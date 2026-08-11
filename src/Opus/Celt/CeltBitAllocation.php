<?php

namespace Xiaosongshu\Flv2mp4\Opus\Celt;

use Xiaosongshu\Flv2mp4\Opus\RangeDecoder;

final class CeltBitAllocation
{
    public const BAND_EDGES = [0,1,2,3,4,5,6,7,8,10,12,14,16,20,24,28,34,40,48,60,78,100];
    public const BAND_WIDTHS = [1,1,1,1,1,1,1,1,2,2,2,2,4,4,4,6,6,8,12,18,22];
    public const LOG_WIDTHS = [0,0,0,0,0,0,0,0,8,8,8,8,16,16,16,21,21,24,29,34,36];
    private const LOG2_FRAC = [0,8,13,16,19,21,23,24,26,27,28,29,30,31,32,32,33,34,34,35,36,36];
    private const TF_SELECT = [
        [0,-1,0,-1,0,-1,0,-1], [0,-1,0,-2,1,0,1,-1],
        [0,-2,0,-3,2,0,1,-1], [0,-2,0,-3,3,0,1,-1],
    ];
    private const STATIC_ALLOC = [
        [0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0],
        [90,80,75,69,63,56,49,40,34,29,20,18,10,0,0,0,0,0,0,0,0],
        [110,100,90,84,78,71,65,58,51,45,39,32,26,20,12,0,0,0,0,0,0],
        [118,110,103,93,86,80,75,70,65,59,53,47,40,31,23,15,4,0,0,0,0],
        [126,119,112,104,95,89,83,78,72,66,60,54,47,39,32,25,17,12,1,0,0],
        [134,127,120,114,103,97,91,85,78,72,66,60,54,47,41,35,29,23,16,10,1],
        [144,137,130,124,113,107,101,95,88,82,76,70,64,57,51,45,39,33,26,15,1],
        [152,145,138,132,123,117,111,105,98,92,86,80,74,67,61,55,49,43,36,20,1],
        [162,155,148,142,133,127,121,115,108,102,96,90,84,77,71,65,59,53,46,30,1],
        [172,165,158,152,143,137,131,125,118,112,106,100,94,87,81,75,69,63,56,45,20],
        [200,200,200,200,200,200,200,200,198,193,188,183,178,173,168,163,158,153,148,129,104],
    ];
    private const SPREAD_ICDF = [25,23,2,0];
    private const TRIM_ICDF = [126,124,119,109,87,41,19,9,4,2,0];

    public static function decode(RangeDecoder $decoder, int $lm, bool $transient, int $channels, int $frameBits): array
    {
        $consumed = $decoder->tell();
        $logp = $transient ? 2 : 4;
        $selectBit = $lm !== 0 && $consumed + $logp + 1 <= $frameBits;
        $diff = 0; $changed = 0; $tf = array_fill(0, 21, 0);
        for ($i = 0; $i < 21; $i++) {
            if ($consumed + $logp + ($selectBit ? 1 : 0) <= $frameBits) {
                $diff ^= $decoder->decodeBitLogp($logp);
                $consumed = $decoder->tell();
                $changed |= $diff;
            }
            $tf[$i] = $diff;
            $logp = $transient ? 4 : 5;
        }
        $base = $transient ? 4 : 0; $select = 0; $table = self::TF_SELECT[$lm];
        if ($selectBit && $table[$base + $changed] !== $table[$base + 2 + $changed]) $select = $decoder->decodeBitLogp(1);
        for ($i = 0; $i < 21; $i++) $tf[$i] = $table[$base + 2 * $select + $tf[$i]];

        $spread = $decoder->tell() + 4 <= $frameBits ? $decoder->decodeCdf(self::SPREAD_ICDF, 5) : 2;
        $caps = $boost = array_fill(0, 21, 0);
        $tbits = $frameBits << 3; $dynalloc = 6;
        for ($i = 0; $i < 21; $i++) {
            $width = self::BAND_WIDTHS[$i] << $lm;
            $caps[$i] = CeltTables::cap($i, $lm, $channels, $width);
            $quanta = min(($width << ($channels - 1)) << 3, max(48, $width << ($channels - 1)));
            $log = $dynalloc;
            while ($decoder->tellFrac() + ($log << 3) < $tbits && $boost[$i] < $caps[$i]) {
                if ($decoder->decodeBitLogp($log) === 0) break;
                $boost[$i] += $quanta; $tbits -= $quanta; $log = 1;
            }
            if ($boost[$i]) $dynalloc = max($dynalloc - 1, 2);
        }
        $trim = 5;
        if ($decoder->tellFrac() + 48 <= $tbits) $trim = $decoder->decodeCdf(self::TRIM_ICDF, 7);

        $tbits = ($frameBits << 3) - $decoder->tellFrac() - 1;
        $anti = $transient && $lm >= 2 && $tbits >= (($lm + 2) << 3) ? 8 : 0; $tbits -= $anti;
        $skipBit = $tbits >= 8 ? 8 : 0; $tbits -= $skipBit;
        $intensityBits = self::LOG2_FRAC[21]; $dualBits = 0;
        if ($intensityBits <= $tbits) { $tbits -= $intensityBits; if ($tbits >= 8) { $dualBits = 8; $tbits -= 8; } }
        else $intensityBits = 0;

        $threshold = $trimOffset = array_fill(0, 21, 0); $skipStart = 0;
        for ($i = 0; $i < 21; $i++) {
            $trimValue = $trim - 5 - $lm;
            $threshold[$i] = max((3 * self::BAND_WIDTHS[$i] << ($lm + 3)) >> 4, $channels << 3);
            $trimOffset[$i] = ($trimValue * (self::BAND_WIDTHS[$i] * (20 - $i) << ($lm + 3 + $channels - 1))) >> 6;
            if ((self::BAND_WIDTHS[$i] << $lm) === 1) $trimOffset[$i] -= $channels << 3;
        }
        $norm = static fn(int $v): int => (($v << ($channels - 1)) << $lm) >> 2;
        $low = 1; $high = 10;
        while ($low <= $high) {
            $center = ($low + $high) >> 1; $total = 0; $done = false;
            for ($i = 20; $i >= 0; $i--) {
                $b = $norm(self::BAND_WIDTHS[$i] * self::STATIC_ALLOC[$center][$i]);
                if ($b) $b = max($b + $trimOffset[$i], 0); $b += $boost[$i];
                if ($b >= $threshold[$i] || $done) { $done = true; $total += min($b, $caps[$i]); }
                elseif ($b >= ($channels << 3)) $total += $channels << 3;
            }
            if ($total > $tbits) $high = $center - 1; else $low = $center + 1;
        }
        $high = $low; $low--;
        $bits1 = $bits2 = array_fill(0, 21, 0);
        for ($i = 0; $i < 21; $i++) {
            $bits1[$i] = $norm(self::BAND_WIDTHS[$i] * self::STATIC_ALLOC[$low][$i]);
            $b2 = $high >= 11 ? $caps[$i] : $norm(self::BAND_WIDTHS[$i] * self::STATIC_ALLOC[$high][$i]);
            if ($bits1[$i]) $bits1[$i] = max($bits1[$i] + $trimOffset[$i], 0);
            if ($b2) $b2 = max($b2 + $trimOffset[$i], 0);
            if ($low) $bits1[$i] += $boost[$i]; $b2 += $boost[$i];
            if ($boost[$i]) $skipStart = $i; $bits2[$i] = max($b2 - $bits1[$i], 0);
        }
        $lowStep = 0; $highStep = 64;
        for ($step = 0; $step < 6; $step++) {
            $center = ($lowStep + $highStep) >> 1; $total = 0; $done = false;
            for ($j = 20; $j >= 0; $j--) {
                $b = $bits1[$j] + (($center * $bits2[$j]) >> 6);
                if ($b >= $threshold[$j] || $done) { $done = true; $total += min($b, $caps[$j]); }
                elseif ($b >= ($channels << 3)) $total += $channels << 3;
            }
            if ($total > $tbits) $highStep = $center; else $lowStep = $center;
        }
        $pulses = array_fill(0, 21, 0); $total = 0; $done = false;
        for ($i = 20; $i >= 0; $i--) {
            $b = $bits1[$i] + (($lowStep * $bits2[$i]) >> 6);
            if ($b >= $threshold[$i] || $done) $done = true; else $b = $b >= ($channels << 3) ? $channels << 3 : 0;
            $pulses[$i] = min($b, $caps[$i]); $total += $pulses[$i];
        }
        for ($coded = 21; ; $coded--) {
            $j = $coded - 1;
            if ($j === $skipStart) { $tbits += $skipBit; break; }
            $span = self::BAND_EDGES[$j + 1]; $remaining = $tbits - $total;
            $bandbits = intdiv($remaining, $span); $remaining -= $bandbits * $span;
            $allocation = $pulses[$j] + $bandbits * self::BAND_WIDTHS[$j] + max($remaining - self::BAND_EDGES[$j], 0);
            if ($allocation >= max($threshold[$j], ($channels + 1) << 3)) {
                if ($decoder->decodeBitLogp(1)) break;
                $total += 8; $allocation -= 8;
            }
            $total -= $pulses[$j];
            if ($intensityBits) { $total -= $intensityBits; $intensityBits = self::LOG2_FRAC[$j]; $total += $intensityBits; }
            $pulses[$j] = $allocation >= ($channels << 3) ? $channels << 3 : 0; $total += $pulses[$j];
        }
        $intensity = $intensityBits ? $decoder->decodeUint($coded + 1) : 0;
        $dual = 0;
        if ($intensity <= 0) $tbits += $dualBits; elseif ($dualBits) $dual = $decoder->decodeBitLogp(1);
        $remaining = $tbits - $total; $span = self::BAND_EDGES[$coded];
        $bandbits = intdiv($remaining, $span); $remaining -= $bandbits * $span;
        for ($i = 0; $i < $coded; $i++) { $bits = min($remaining, self::BAND_WIDTHS[$i]); $pulses[$i] += $bits + $bandbits * self::BAND_WIDTHS[$i]; $remaining -= $bits; }
        $fine = $priority = array_fill(0, 21, 0); $extra = 0;
        for ($i = 0; $i < $coded; $i++) {
            $n = self::BAND_WIDTHS[$i] << $lm; $prevExtra = $extra; $pulses[$i] += $extra;
            if ($n > 1) {
                $extra = max($pulses[$i] - $caps[$i], 0); $pulses[$i] -= $extra;
                $dof = $n * $channels + ($channels === 2 && $n > 2 && !$dual && $i < $intensity ? 1 : 0);
                $temp = $dof * (self::LOG_WIDTHS[$i] + ($lm << 3)); $offset = ($temp >> 1) - $dof * 21;
                if ($n === 2) $offset += $dof << 1;
                if ($pulses[$i] + $offset < 2 * ($dof << 3)) $offset += $temp >> 2;
                elseif ($pulses[$i] + $offset < 3 * ($dof << 3)) $offset += $temp >> 3;
                $fine[$i] = max(0, min(intdiv($pulses[$i] + $offset + ($dof << 2), $dof << 3), min(($pulses[$i] >> 3) >> ($channels - 1), 8)));
                $priority[$i] = ($fine[$i] * ($dof << 3) >= $pulses[$i] + $offset) ? 1 : 0;
                $pulses[$i] -= $fine[$i] << ($channels - 1) << 3;
            } else { $extra = max($pulses[$i] - ($channels << 3), 0); $pulses[$i] -= $extra; $priority[$i] = 1; }
            if ($extra > 0) { $fineExtra = min($extra >> ($channels + 2), 8 - $fine[$i]); $fine[$i] += $fineExtra; $fineExtra <<= $channels + 2; $priority[$i] = $fineExtra >= $extra - $prevExtra ? 1 : 0; $extra -= $fineExtra; }
        }
        for ($i = $coded; $i < 21; $i++) { $fine[$i] = ($pulses[$i] >> ($channels - 1)) >> 3; $pulses[$i] = 0; $priority[$i] = $fine[$i] < 1 ? 1 : 0; }
        return compact('tf','spread','caps','pulses','fine','priority','coded','intensity','dual','anti','extra');
    }
}
