<?php

namespace Xiaosongshu\Flv2mp4\Opus\Celt;

use LogicException;
use stdClass;
use Xiaosongshu\Flv2mp4\Opus\RangeDecoder;

/**
 * @purpose CELT 频带处理类
 * @author yanglong
 * @time 2026年8月12日17:12:42
 */
final class CeltBands
{
    private const EXP2_TABLE8 = [16384,17866,19483,21247,23170,25267,27554,30048];
    private const BIT_INTERLEAVE = [0,1,1,1,2,3,3,3,2,3,3,3,2,3,3,3];
    private const BIT_DEINTERLEAVE = [0x00,0x03,0x0C,0x0F,0x30,0x33,0x3C,0x3F,0xC0,0xC3,0xCC,0xCF,0xF0,0xF3,0xFC,0xFF];

    public static function decode(
        RangeDecoder $decoder,
        array $allocation,
        int $lm,
        bool $transient,
        int $totalBits,
        int $channels,
        int $seed
    ): array {
        $m = 1 << $lm;
        $size = 120 << $lm;
        $ctx = new stdClass();
        $ctx->decoder = $decoder;
        $ctx->remaining = 0;
        $ctx->band = 0;
        $ctx->intensity = $allocation['intensity'];
        $ctx->spread = $allocation['spread'];
        $ctx->tf = 0;
        $ctx->seed = $seed;

        $x = array_fill(0, $size, 0.0);
        $y = $channels === 1 ? [] : array_fill(0, $size, 0.0);
        $norm = [];
        $norm2 = $channels === 1 ? null : [];
        $collapse = array_fill(0, 42, 0);
        $balance = $allocation['extra'];
        $lowbandOffset = 0;
        $updateLowband = true;
        $dual = (bool) $allocation['dual'];
        $blocks = $transient ? $m : 1;
        $coded = $allocation['coded'];
        $totalFrac = ($totalBits << 3) - $allocation['anti'];

        for ($band = 0; $band < 21; $band++) {
            $tell = $decoder->tellFrac();
            if ($band !== 0) $balance -= $tell;
            $remaining = $totalFrac - $tell - 1;
            $ctx->remaining = $remaining;
            $ctx->band = $band;
            $ctx->tf = $allocation['tf'][$band];
            $n = CeltBitAllocation::BAND_WIDTHS[$band] << $lm;
            $b = $band < $coded
                ? max(0, min(16383, min($remaining + 1, $allocation['pulses'][$band] + self::sdiv($balance, min(3, $coded - $band)))))
                : 0;
            $offset = CeltBitAllocation::BAND_EDGES[$band] << $lm;

            if (($offset - $n >= 0 || $band === 1) && ($updateLowband || $lowbandOffset === 0)) $lowbandOffset = $band;
            $effective = -1;
            $fillX = (1 << $blocks) - 1;
            $fillY = $channels === 1 ? 0 : $fillX;
            if ($lowbandOffset !== 0) {
                $effective = max(0, (CeltBitAllocation::BAND_EDGES[$lowbandOffset] << $lm) - $n);
                $fillX = $fillY = 0;
                for ($j = max(0, $lowbandOffset - 1); $j < $band; $j++) {
                    $fillX |= $collapse[2 * $j];
                    if ($channels !== 1) {
                        $fillY |= $collapse[2 * $j + 1];
                    }
                }
            }
            $lowX = $effective >= 0 ? array_slice($norm, $effective, $n) : null;
            $lowY = $channels !== 1 && $effective >= 0 ? array_slice($norm2, $effective, $n) : null;

            if ($channels !== 1 && $dual && $band === $allocation['intensity']) {
                $dual = false;
                $count = $offset;
                for ($j = 0; $j < $count; $j++) $norm[$j] = (($norm[$j] ?? 0.0) + ($norm2[$j] ?? 0.0)) * 0.5;
            }
            if ($channels === 1) {
                $xb = self::quantBand($ctx, $n, $b, $blocks, $lowX, $lm, 1.0, $fillX);
                $cmX = $xb['mask']; $cmY = 0;
            } elseif ($dual) {
                $xb = self::quantBand($ctx, $n, intdiv($b, 2), $blocks, $lowX, $lm, 1.0, $fillX);
                $yb = self::quantBand($ctx, $n, intdiv($b, 2), $blocks, $lowY, $lm, 1.0, $fillY);
                $cmX = $xb['mask']; $cmY = $yb['mask'];
            } else {
                $st = self::quantStereo($ctx, $n, $b, $blocks, $lowX, $lm, $fillX | $fillY);
                $xb = ['vector' => $st['x']]; $yb = ['vector' => $st['y']];
                $cmX = $cmY = $st['mask'];
            }
            for ($j = 0; $j < $n; $j++) {
                $x[$offset + $j] = $xb['vector'][$j];
            }
            if ($channels !== 1) {
                for ($j = 0; $j < $n; $j++) {
                    $y[$offset + $j] = $yb['vector'][$j];
                }
            }
            if ($band < 20) {
                $scale = sqrt($n);
                for ($j = 0; $j < $n; $j++) {
                    $norm[$offset + $j] = $xb['vector'][$j] * $scale;
                }
                if ($channels !== 1) {
                    for ($j = 0; $j < $n; $j++) {
                        $norm2[$offset + $j] = $yb['vector'][$j] * $scale;
                    }
                }
            }
            $collapse[2 * $band] = $cmX;
            $collapse[2 * $band + 1] = $cmY;
            if ($decoder->hasError()) {
                throw new LogicException(sprintf(
                    'range error at band %d (budget=%d, allocated=%d, remaining=%d, tellFrac=%d/%d)',
                    $band, $b, $allocation['pulses'][$band], $ctx->remaining, $decoder->tellFrac(), $totalFrac
                ));
            }
            $balance += $allocation['pulses'][$band] + $tell;
            $updateLowband = $b > ($n << 3);
        }
        if ($decoder->hasError()) throw new LogicException('CELT range decoder reported an error after PVQ bands');
        return ['x' => $x, 'y' => $y, 'collapse' => $collapse, 'seed' => $ctx->seed];
    }

    private static function quantStereo(stdClass $ctx, int $n, int $b, int $blocks, ?array $lowband, int $lm, int $fill): array
    {
        if ($n === 1) {
            $x = self::bandN1($ctx, true);
            return ['x' => [$x[0]], 'y' => [$x[1]], 'mask' => 1];
        }
        $originalFill = $fill;
        $theta = self::theta($ctx, $n, $b, $blocks, $blocks, $lm, true, $fill);
        $b = $theta['bits'];
        $mid = $theta['mid']; $side = $theta['side'];
        if ($n === 2) {
            $sideBits = $theta['itheta'] !== 0 && $theta['itheta'] !== 16384 ? 8 : 0;
            $ctx->remaining -= $theta['qalloc'] + $sideBits;
            $sign = $sideBits ? 1 - 2 * $ctx->decoder->rawBits(1) : 1;
            $main = self::quantBand($ctx, 2, $b - $sideBits, $blocks, $lowband, $lm, 1.0, $originalFill);
            $a = $main['vector'];
            $other = [-$sign * $a[1], $sign * $a[0]];
            if ($theta['itheta'] > 8192) [$a, $other] = [$other, $a];
            return ['x' => [$mid * $a[0] - $side * $other[0], $mid * $a[1] - $side * $other[1]],
                'y' => [$mid * $a[0] + $side * $other[0], $mid * $a[1] + $side * $other[1]], 'mask' => $main['mask']];
        }
        $mbits = max(0, min($b, self::sdiv($b - $theta['delta'], 2)));
        $sbits = $b - $mbits;
        $ctx->remaining -= $theta['qalloc'];
        $before = $ctx->remaining;
        if ($mbits >= $sbits) {
            $mx = self::quantBand($ctx, $n, $mbits, $blocks, $lowband, $lm, 1.0, $fill);
            $rebalance = $mbits - ($before - $ctx->remaining);
            if ($rebalance > 24 && $theta['itheta'] !== 0) $sbits += $rebalance - 24;
            $sy = self::quantBand($ctx, $n, $sbits, $blocks, null, $lm, $side, $fill >> $blocks);
        } else {
            $sy = self::quantBand($ctx, $n, $sbits, $blocks, null, $lm, $side, $fill >> $blocks);
            $rebalance = $sbits - ($before - $ctx->remaining);
            if ($rebalance > 24 && $theta['itheta'] !== 16384) $mbits += $rebalance - 24;
            $mx = self::quantBand($ctx, $n, $mbits, $blocks, $lowband, $lm, 1.0, $fill);
        }
        [$left, $right] = self::stereoMerge($mx['vector'], $sy['vector'], $mid);
        if ($theta['inv']) $right = array_map(static fn(float $v): float => -$v, $right);
        return ['x' => $left, 'y' => $right, 'mask' => $mx['mask'] | $sy['mask']];
    }

    private static function quantBand(stdClass $ctx, int $n, int $b, int $blocks, ?array $lowband, int $lm, float $gain, int $fill): array
    {
        if ($n === 1) return ['vector' => [self::bandN1($ctx, false)[0] * $gain], 'mask' => 1];
        $n0 = $n; $nb = intdiv($n, $blocks); $originalBlocks = $blocks;
        $recombine = max(0, $ctx->tf);
        for ($k = 0; $k < $recombine; $k++) $fill = self::BIT_INTERLEAVE[$fill & 15] | (self::BIT_INTERLEAVE[$fill >> 4] << 2);
        $blocks >>= $recombine; $nb <<= $recombine;
        $divide = 0; $tf = $ctx->tf;
        while (($nb & 1) === 0 && $tf < 0) { $fill |= $fill << $blocks; $blocks <<= 1; $nb >>= 1; $divide++; $tf++; }
        $b0 = $blocks; $nb0 = $nb;
        if ($b0 > 1 && $lowband !== null) $lowband = CeltPvq::deinterleaveHadamard($lowband, $b0, $originalBlocks === 1);
        $result = self::partition($ctx, $n, $b, $blocks, $lowband, $lm, $gain, $fill);
        $vector = $result['vector']; $mask = $result['mask'];
        if ($b0 > 1) $vector = CeltPvq::interleaveHadamard($vector, $b0, $originalBlocks === 1);
        $nb = $nb0; $blocks = $b0;
        for ($k = 0; $k < $divide; $k++) { $blocks >>= 1; $nb <<= 1; $mask |= $mask >> $blocks; $vector = self::haar($vector, $nb, $blocks); }
        for ($k = 0; $k < $recombine; $k++) { $mask = self::BIT_DEINTERLEAVE[$mask & 255]; $vector = self::haar($vector, $n0 >> $k, 1 << $k); }
        return ['vector' => $vector, 'mask' => $mask & ((1 << ($blocks << $recombine)) - 1)];
    }

    private static function partition(stdClass $ctx, int $n, int $b, int $blocks, ?array $lowband, int $lm, float $gain, int $fill): array
    {
        $cache = CeltTables::pulseCache($ctx->band, $lm);
        if ($lm !== -1 && $b > $cache[$cache[0]] + 12 && $n > 2) {
            $b0 = $blocks; $half = intdiv($n, 2); $lm--; if ($blocks === 1) $fill = ($fill & 1) | ($fill << 1); $blocks = intdiv($blocks + 1, 2);
            $theta = self::theta($ctx, $half, $b, $blocks, $b0, $lm, false, $fill); $b = $theta['bits'];
            $delta = $theta['delta'];
            if ($b0 > 1 && ($theta['itheta'] & 0x3fff)) $delta = $theta['itheta'] > 8192 ? $delta - ($delta >> (4 - $lm)) : min(0, $delta + (($half << 3) >> (5 - $lm)));
            $mbits = max(0, min($b, self::sdiv($b - $delta, 2))); $sbits = $b - $mbits; $ctx->remaining -= $theta['qalloc']; $before = $ctx->remaining;
            $low1 = $lowband === null ? null : array_slice($lowband, 0, $half); $low2 = $lowband === null ? null : array_slice($lowband, $half);
            if ($mbits >= $sbits) {
                $a = self::partition($ctx, $half, $mbits, $blocks, $low1, $lm, $gain * $theta['mid'], $fill);
                $rebalance = $mbits - ($before - $ctx->remaining); if ($rebalance > 24 && $theta['itheta'] !== 0) $sbits += $rebalance - 24;
                $z = self::partition($ctx, $half, $sbits, $blocks, $low2, $lm, $gain * $theta['side'], $fill >> $blocks);
            } else {
                $z = self::partition($ctx, $half, $sbits, $blocks, $low2, $lm, $gain * $theta['side'], $fill >> $blocks);
                $rebalance = $sbits - ($before - $ctx->remaining); if ($rebalance > 24 && $theta['itheta'] !== 16384) $mbits += $rebalance - 24;
                $a = self::partition($ctx, $half, $mbits, $blocks, $low1, $lm, $gain * $theta['mid'], $fill);
            }
            return ['vector' => array_merge($a['vector'], $z['vector']), 'mask' => $a['mask'] | ($z['mask'] << intdiv($b0, 2))];
        }
        $q = CeltTables::bitsToPulses($ctx->band, $lm, $b); $cost = CeltTables::pulsesToBits($ctx->band, $lm, $q); $ctx->remaining -= $cost;
        while ($ctx->remaining < 0 && $q > 0) { $ctx->remaining += $cost; $q--; $cost = CeltTables::pulsesToBits($ctx->band, $lm, $q); $ctx->remaining -= $cost; }
        if ($q > 0) {
            $k = CeltTables::pulseCount($q);
            $decoded = CeltPvq::decodePulses($ctx->decoder, $n, $k);
            $vector = CeltPvq::normalizePulses($decoded['vector'], $gain, $decoded['normSquared']);
            $vector = CeltPvq::expRotation($vector, $blocks, $k, $ctx->spread, false, true);
            return ['vector' => $vector, 'mask' => CeltPvq::collapseMask($decoded['vector'], $blocks, true)];
        }
        $mask = (1 << $blocks) - 1; $fill &= $mask;
        if ($fill === 0) return ['vector' => array_fill(0, $n, 0.0), 'mask' => 0];
        $vector = [];
        for ($j = 0; $j < $n; $j++) { $ctx->seed = self::lcg($ctx->seed); $vector[] = $lowband === null ? (($ctx->seed & 0x80000000) ? 1.0 : -1.0) : (($lowband[$j] ?? 0.0) + (($ctx->seed & 0x8000) ? 1 / 256 : -1 / 256)); }
        return ['vector' => self::normalize($vector, $gain), 'mask' => $lowband === null ? $mask : $fill];
    }

    private static function theta(stdClass $ctx, int $n, int $b, int $blocks, int $b0, int $lm, bool $stereo, int &$fill): array
    {
        $pulseCap = CeltBitAllocation::LOG_WIDTHS[$ctx->band] + ($lm << 3);
        $offset = ($pulseCap >> 1) - ($stereo && $n === 2 ? 16 : 4);
        $n2 = 2 * $n - 1 - (($stereo && $n === 2) ? 1 : 0);
        $qb = intdiv($b + $n2 * $offset, $n2); $qb = min($b - $pulseCap - 32, $qb, 64);
        $qn = $qb < 4 ? 1 : ((self::EXP2_TABLE8[$qb & 7] >> (14 - ($qb >> 3))) + 1) >> 1 << 1;
        if ($stereo && $ctx->band >= $ctx->intensity) $qn = 1;
        $tell = $ctx->decoder->tellFrac(); $inv = 0; $itheta = 0;
        if ($qn !== 1) {
            if ($stereo && $n > 2) {
                $x0 = intdiv($qn, 2); $total = 3 * ($x0 + 1) + $x0; $symbol = $ctx->decoder->decode($total);
                $x = $symbol < 3 * ($x0 + 1) ? intdiv($symbol, 3) : $x0 + 1 + $symbol - 3 * ($x0 + 1);
                $low = $x <= $x0 ? 3 * $x : 3 * ($x0 + 1) + $x - $x0 - 1; $high = $x <= $x0 ? 3 * ($x + 1) : 3 * ($x0 + 1) + $x - $x0;
                $ctx->decoder->update($low, $high, $total); $itheta = $x;
            } elseif ($b0 > 1 || $stereo) $itheta = $ctx->decoder->decodeUint($qn + 1);
            else $itheta = $ctx->decoder->decodeTriangular($qn);
            $itheta = intdiv($itheta * 16384, $qn);
        } elseif ($stereo && $b > 16 && $ctx->remaining > 16) $inv = $ctx->decoder->decodeBitLogp(2);
        $qalloc = $ctx->decoder->tellFrac() - $tell; $b -= $qalloc;
        if ($itheta === 0) { $mid = 32767 / 32768; $side = 0.0; $fill &= (1 << $blocks) - 1; $delta = -16384; }
        elseif ($itheta === 16384) { $mid = 0.0; $side = 32767 / 32768; $fill &= ((1 << $blocks) - 1) << $blocks; $delta = 16384; }
        else { $imid = self::bitexactCos($itheta); $iside = self::bitexactCos(16384 - $itheta); $mid = $imid / 32768; $side = $iside / 32768; $delta = self::fracMul(($n - 1) << 7, self::log2Tan($iside, $imid)); }
        return compact('mid', 'side', 'delta', 'itheta', 'qalloc', 'inv') + ['bits' => $b];
    }

    private static function bandN1(stdClass $ctx, bool $stereo): array
    {
        $out = [];
        for ($c = 0; $c < ($stereo ? 2 : 1); $c++) { $sign = 0; if ($ctx->remaining >= 8) { $sign = $ctx->decoder->rawBits(1); $ctx->remaining -= 8; } $out[] = $sign ? -1.0 : 1.0; }
        return $out;
    }

    private static function stereoMerge(array $x, array $y, float $mid): array
    {
        $cross = $sideEnergy = 0.0; foreach ($x as $i => $v) { $cross += $y[$i] * $v; $sideEnergy += $y[$i] ** 2; }
        $cross *= $mid; $el = $mid * $mid + $sideEnergy - 2 * $cross; $er = $mid * $mid + $sideEnergy + 2 * $cross;
        if ($el < 6e-4 || $er < 6e-4) return [$x, $x];
        $left = $right = []; foreach ($x as $i => $v) { $left[] = ($mid * $v - $y[$i]) / sqrt($el); $right[] = ($mid * $v + $y[$i]) / sqrt($er); }
        return [$left, $right];
    }

    private static function haar(array $x, int $n0, int $stride): array
    {
        $n0 >>= 1; $s = M_SQRT1_2;
        for ($i = 0; $i < $stride; $i++) for ($j = 0; $j < $n0; $j++) { $a = $stride * 2 * $j + $i; $z = $stride * (2 * $j + 1) + $i; $u = $x[$a]; $v = $x[$z]; $x[$a] = ($u + $v) * $s; $x[$z] = ($u - $v) * $s; }
        return $x;
    }

    private static function normalize(array $x, float $gain): array { $e = 0.0; foreach ($x as $v) $e += $v * $v; if ($e <= 0) return array_fill(0, count($x), 0.0); $g = $gain / sqrt($e); return array_map(static fn(float $v): float => $v * $g, $x); }
    private static function bitexactCos(int $x): int { $x2 = (4096 + $x * $x) >> 13; $x2 = (32767 - $x2) + self::fracMul($x2, -7651 + self::fracMul($x2, 8277 + self::fracMul(-626, $x2))); return 1 + $x2; }
    private static function log2Tan(int $sin, int $cos): int { $lc = self::ilog($cos); $ls = self::ilog($sin); $cos <<= 15 - $lc; $sin <<= 15 - $ls; return ($ls - $lc) * 2048 + self::fracMul($sin, self::fracMul($sin, -2597) + 7932) - self::fracMul($cos, self::fracMul($cos, -2597) + 7932); }
    private static function fracMul(int $a, int $b): int { return ($a * $b + 16384) >> 15; }
    private static function ilog(int $x): int { $n = 0; while ($x > 0) { $n++; $x >>= 1; } return $n; }
    private static function sdiv(int $a, int $b): int { return $a < 0 ? -intdiv(-$a, $b) : intdiv($a, $b); }
    private static function lcg(int $seed): int { return (1664525 * $seed + 1013904223) & 0xFFFFFFFF; }
}
