<?php

namespace Xiaosongshu\Flv2mp4\Opus\Encode;

use stdClass;
use Xiaosongshu\Flv2mp4\Opus\Celt\CeltBitAllocation;
use Xiaosongshu\Flv2mp4\Opus\Celt\CeltPvq;
use Xiaosongshu\Flv2mp4\Opus\Celt\CeltTables;

/** Encodes the mono CELT band stream for the fixed LM=3 profile. */
final class CeltBandsEncoder
{
    private const EXP2_TABLE8 = [16384,17866,19483,21247,23170,25267,27554,30048];

    public static function encode(RangeEncoder $encoder, array $spectrum, array $allocation, int $lm = 3, bool $transient = false): array
    {
        if ($lm !== 3 || $transient) throw new \InvalidArgumentException('Only mono LM=3 non-transient CELT bands are supported');
        $ctx = new stdClass(); $ctx->encoder = $encoder; $ctx->remaining = 0; $ctx->band = 0;
        $ctx->tf = 0; $ctx->spread = $allocation['spread']; $ctx->intensity = $allocation['intensity'];
        $balance = $allocation['extra']; $totalFrac = ($allocation['_totalBits'] << 3) - $allocation['anti'];
        $collapse = array_fill(0, 42, 0); $rawSigns = [];
        for ($band = 0; $band < 21; $band++) {
            $tell = $encoder->tellFrac(); if ($band !== 0) $balance -= $tell;
            $remaining = $totalFrac - $tell - 1; $ctx->remaining = $remaining; $ctx->band = $band; $ctx->tf = $allocation['tf'][$band];
            $n = CeltBitAllocation::BAND_WIDTHS[$band] << $lm;
            $b = $band < $allocation['coded'] ? max(0, min(16383, min($remaining + 1, $allocation['pulses'][$band] + self::sdiv($balance, min(3, $allocation['coded'] - $band))))) : 0;
            $offset = CeltBitAllocation::BAND_EDGES[$band] << $lm;
            $values = array_slice($spectrum, $offset, $n);
            $result = self::quantBand($ctx, $values, $n, $b, 1, null, $lm, 1.0, (1 << 1) - 1, $rawSigns);
            $collapse[2 * $band] = $result['mask'];
            if ($band < 20) $ctx->norm = array_merge($ctx->norm ?? [], array_map(static fn(float $v): float => $v * sqrt($n), $result['vector']));
            $balance += $allocation['pulses'][$band] + $tell;
        }
        return ['collapse' => $collapse, 'n1Signs' => $rawSigns];
    }

    private static function quantBand(stdClass $ctx, array $target, int $n, int $b, int $blocks, ?array $lowband, int $lm, float $gain, int $fill, array &$rawSigns): array
    {
        if ($n === 1) { $rawSigns[] = (($target[0] ?? 0.0) < 0.0) ? 1 : 0; return ['vector' => [1.0], 'mask' => 1]; }
        $n0 = $n; $nb = intdiv($n, $blocks); $originalBlocks = $blocks; $recombine = max(0, $ctx->tf);
        for ($k = 0; $k < $recombine; $k++) $fill = self::bitInterleave($fill);
        $blocks >>= $recombine; $nb <<= $recombine; $divide = 0; $tf = $ctx->tf;
        while (($nb & 1) === 0 && $tf < 0) { $fill |= $fill << $blocks; $blocks <<= 1; $nb >>= 1; $divide++; $tf++; }
        $b0 = $blocks; $nb0 = $nb;
        if ($b0 > 1) $target = CeltPvq::deinterleaveHadamard($target, $b0, $originalBlocks === 1);
        if ($divide > 0) for ($k = 0; $k < $divide; $k++) $target = self::haar($target, $nb << ($divide - $k - 1), $blocks >> ($k + 1));
        $result = self::partition($ctx, $target, $n, $b, $blocks, $lm, $gain, $fill, $rawSigns);
        $vector = $result['vector'];
        if ($b0 > 1) $vector = CeltPvq::interleaveHadamard($vector, $b0, $originalBlocks === 1);
        $nb = $nb0; $blocks = $b0;
        for ($k = 0; $k < $divide; $k++) { $blocks >>= 1; $nb <<= 1; $vector = self::haar($vector, $nb, $blocks); }
        for ($k = 0; $k < $recombine; $k++) { $vector = self::haar($vector, $n0 >> $k, 1 << $k); }
        return ['vector' => $vector, 'mask' => $result['mask']];
    }

    private static function partition(stdClass $ctx, array $target, int $n, int $b, int $blocks, int $lm, float $gain, int $fill, array &$rawSigns): array
    {
        $cache = CeltTables::pulseCache($ctx->band, $lm);
        if ($lm !== -1 && $b > $cache[$cache[0]] + 12 && $n > 2) {
            $b0 = $blocks; $half = intdiv($n, 2); $lm--; if ($blocks === 1) $fill = ($fill & 1) | ($fill << 1); $blocks = intdiv($blocks + 1, 2);
            $theta = self::theta($ctx, $target, $half, $b, $blocks, $b0, $lm, false, $fill); $b = $theta['bits']; $delta = $theta['delta'];
            if ($b0 > 1 && ($theta['itheta'] & 0x3fff)) $delta = $theta['itheta'] > 8192 ? $delta - ($delta >> (4 - $lm)) : min(0, $delta + (($half << 3) >> (5 - $lm)));
            $mbits = max(0, min($b, self::sdiv($b - $delta, 2))); $sbits = $b - $mbits; $ctx->remaining -= $theta['qalloc']; $before = $ctx->remaining;
            $a = self::partition($ctx, array_slice($target, 0, $half), $half, $mbits, $blocks, $lm, $gain * $theta['mid'], $fill, $rawSigns);
            $rebalance = $mbits - ($before - $ctx->remaining); if ($rebalance > 24 && $theta['itheta'] !== 0) $sbits += $rebalance - 24;
            $z = self::partition($ctx, array_slice($target, $half), $half, $sbits, $blocks, $lm, $gain * $theta['side'], $fill >> $blocks, $rawSigns);
            return ['vector' => array_merge($a['vector'], $z['vector']), 'mask' => $a['mask'] | ($z['mask'] << intdiv($b0, 2))];
        }
        $q = CeltTables::bitsToPulses($ctx->band, $lm, $b); $cost = CeltTables::pulsesToBits($ctx->band, $lm, $q); $ctx->remaining -= $cost;
        while ($ctx->remaining < 0 && $q > 0) { $ctx->remaining += $cost; $q--; $cost = CeltTables::pulsesToBits($ctx->band, $lm, $q); $ctx->remaining -= $cost; }
        if ($q <= 0) return ['vector' => array_fill(0, $n, 0.0), 'mask' => 0];
        $k = CeltTables::pulseCount($q); $rotated = CeltPvq::expRotation($target, $blocks, $k, $ctx->spread, true, true); $vector = self::search($rotated, $k); $actual = array_sum(array_map(static fn(int $v): int => abs($v), $vector)); if ($actual !== $k) $vector[0] += $vector[0] < 0 ? -($k - $actual) : ($k - $actual); CeltPvqEncoder::encode($ctx->encoder, $vector, $k);
        $norm = sqrt(max(1, array_sum(array_map(static fn(int $v): int => $v * $v, $vector))));
        return ['vector' => array_map(static fn(int $v): float => $gain * $v / $norm, $vector), 'mask' => 1];
    }

    private static function theta(stdClass $ctx, array $target, int $n, int $b, int $blocks, int $b0, int $lm, bool $stereo, int &$fill): array
    {
        $pulseCap = CeltBitAllocation::LOG_WIDTHS[$ctx->band] + ($lm << 3); $offset = ($pulseCap >> 1) - ($stereo && $n === 2 ? 16 : 4); $n2 = 2 * $n - 1 - (($stereo && $n === 2) ? 1 : 0);
        $qb = intdiv($b + $n2 * $offset, $n2); $qb = min($b - $pulseCap - 32, $qb, 64); $qn = $qb < 4 ? 1 : ((self::EXP2_TABLE8[$qb & 7] >> (14 - ($qb >> 3))) + 1) >> 1 << 1;
        $itheta = 0; $qalloc = 0; $tell = $ctx->encoder->tellFrac();
        if ($qn !== 1) { $energyL = 0.0; $energyR = 0.0; for ($i = 0; $i < $n; $i++) { $energyL += ($target[$i] ?? 0.0) ** 2; $energyR += ($target[$i + $n] ?? 0.0) ** 2; } $itheta = (int) round($qn * 0.5 * (1.0 + atan2(sqrt($energyR), sqrt($energyL)) / (M_PI / 2))); $itheta = max(0, min($qn, $itheta)); if ($b0 > 1) $ctx->encoder->encodeUint($itheta, $qn + 1); else $ctx->encoder->encodeTriangular($itheta, $qn); $itheta = intdiv($itheta * 16384, $qn); }
        $qalloc = $ctx->encoder->tellFrac() - $tell; $b -= $qalloc;
        if ($itheta === 0) { $mid = 32767 / 32768; $side = 0.0; $fill &= (1 << $blocks) - 1; $delta = -16384; }
        elseif ($itheta === 16384) { $mid = 0.0; $side = 32767 / 32768; $fill &= ((1 << $blocks) - 1) << $blocks; $delta = 16384; }
        else { $imid = self::bitexactCos($itheta); $iside = self::bitexactCos(16384 - $itheta); $mid = $imid / 32768; $side = $iside / 32768; $delta = self::fracMul(($n - 1) << 7, self::log2Tan($iside, $imid)); }
        return compact('mid','side','delta','itheta','qalloc') + ['bits' => $b];
    }

    private static function search(array $target, int $k): array
    {
        $count = count($target);
        $absolute = array_map('abs', $target);
        $sum = array_sum($absolute);
        if ($sum <= 1.0e-20) {
            $out = array_fill(0, $count, 0);
            $out[0] = $k;
            return $out;
        }

        $out = array_fill(0, $count, 0);
        $double = array_fill(0, $count, 0.0);
        $xy = 0.0;
        $yy = 0.0;
        $remaining = $k;
        if ($k > intdiv($count, 2)) {
            $scale = ($k + 0.8) / $sum;
            for ($i = 0; $i < $count; $i++) {
                $value = (int) floor($absolute[$i] * $scale);
                $out[$i] = $value;
                $double[$i] = 2.0 * $value;
                $remaining -= $value;
                $xy += $absolute[$i] * $value;
                $yy += $value * $value;
            }
        }

        for ($pulse = 0; $pulse < $remaining; $pulse++) {
            $best = 0;
            $bestNumerator = -INF;
            $bestDenominator = 1.0;
            foreach ($absolute as $i => $value) {
                $numerator = ($xy + $value) ** 2;
                $denominator = $yy + $double[$i] + 1.0;
                if ($numerator * $bestDenominator > $bestNumerator * $denominator) {
                    $bestNumerator = $numerator;
                    $bestDenominator = $denominator;
                    $best = $i;
                }
            }
            $xy += $absolute[$best];
            $yy += $double[$best] + 1.0;
            $double[$best] += 2.0;
            $out[$best]++;
        }

        foreach ($out as $i => $value) {
            if ($value !== 0 && $target[$i] < 0.0) $out[$i] = -$value;
        }
        return $out;
    }
    private static function bitInterleave(int $x): int { $r = 0; for ($i = 0; $i < 4; $i++) { $r |= (($x >> $i) & 1) << (2 * $i); } return $r; }
    private static function haar(array $x, int $n0, int $stride): array { $n0 >>= 1; for ($i = 0; $i < $stride; $i++) for ($j = 0; $j < $n0; $j++) { $a = $stride * 2 * $j + $i; $z = $stride * (2 * $j + 1) + $i; $u = $x[$a]; $v = $x[$z]; $x[$a] = ($u + $v) * M_SQRT1_2; $x[$z] = ($u - $v) * M_SQRT1_2; } return $x; }
    private static function bitexactCos(int $x): int { $x2 = (4096 + $x * $x) >> 13; $x2 = (32767 - $x2) + self::fracMul($x2, -7651 + self::fracMul($x2, 8277 + self::fracMul(-626, $x2))); return 1 + $x2; }
    private static function log2Tan(int $sin, int $cos): int { $lc = self::ilog($cos); $ls = self::ilog($sin); $cos <<= 15 - $lc; $sin <<= 15 - $ls; return ($ls - $lc) * 2048 + self::fracMul($sin, self::fracMul($sin, -2597) + 7932) - self::fracMul($cos, self::fracMul($cos, -2597) + 7932); }
    private static function fracMul(int $a, int $b): int { return ($a * $b + 16384) >> 15; }
    private static function ilog(int $x): int { $n = 0; while ($x > 0) { $n++; $x >>= 1; } return $n; }
    private static function sdiv(int $a, int $b): int { return $a < 0 ? -intdiv(-$a, $b) : intdiv($a, $b); }
}
