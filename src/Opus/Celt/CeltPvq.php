<?php

namespace Xiaosongshu\Flv2mp4\Opus\Celt;

use InvalidArgumentException;
use Xiaosongshu\Flv2mp4\Opus\RangeDecoder;

/**
 * @purpose CELT 感知向量量化器
 * @author yanglong
 * @time 2026年8月12日17:19:29
 */
final class CeltPvq
{
    public const SPREAD_NONE = 0;
    public const SPREAD_LIGHT = 1;
    public const SPREAD_NORMAL = 2;
    public const SPREAD_AGGRESSIVE = 3;

    public static function decodePulses(RangeDecoder $decoder, int $dimensions, int $pulses): array
    {
        $codewords = CeltTables::v($dimensions, $pulses);
        $index = $codewords === 1 ? 0 : $decoder->decodeUint($codewords);
        return self::cwrsi($dimensions, $pulses, $index);
    }

    public static function cwrsi(int $dimensions, int $pulses, int $index): array
    {
        $codewords = CeltTables::v($dimensions, $pulses);
        if ($index < 0 || $index >= $codewords) {
            throw new InvalidArgumentException('CWRS index is outside the PVQ codebook');
        }
        if ($pulses === 0) {
            return ['vector' => array_fill(0, $dimensions, 0), 'normSquared' => 0];
        }
        if ($dimensions === 1) {
            return ['vector' => [$index === 0 ? $pulses : -$pulses], 'normSquared' => $pulses * $pulses];
        }

        $vector = [];
        $n = $dimensions;
        $k = $pulses;
        while ($n > 2) {
            if ($k >= $n) {
                $p = CeltTables::u($n, $k + 1);
                $negative = $index >= $p;
                if ($negative) {
                    $index -= $p;
                }
                $original = $k;
                $q = CeltTables::u($n, $n);
                if ($q > $index) {
                    $k = $n;
                    do {
                        $k--;
                        $p = CeltTables::u($k, $n);
                    } while ($p > $index);
                } else {
                    do {
                        $p = CeltTables::u($n, $k);
                        if ($p <= $index) {
                            break;
                        }
                        $k--;
                    } while (true);
                }
                $index -= $p;
                $magnitude = $original - $k;
                $vector[] = $negative ? -$magnitude : $magnitude;
            } else {
                $p = CeltTables::u($k, $n);
                $q = CeltTables::u($k + 1, $n);
                if ($p <= $index && $index < $q) {
                    $index -= $p;
                    $vector[] = 0;
                } else {
                    $negative = $index >= $q;
                    if ($negative) {
                        $index -= $q;
                    }
                    $original = $k;
                    do {
                        $k--;
                        $p = CeltTables::u($k, $n);
                    } while ($p > $index);
                    $index -= $p;
                    $magnitude = $original - $k;
                    $vector[] = $negative ? -$magnitude : $magnitude;
                }
            }
            $n--;
        }

        $p = 2 * $k + 1;
        $negative = $index >= $p;
        if ($negative) {
            $index -= $p;
        }
        $original = $k;
        $k = intdiv($index + 1, 2);
        if ($k !== 0) {
            $index -= 2 * $k - 1;
        }
        $magnitude = $original - $k;
        $vector[] = $negative ? -$magnitude : $magnitude;
        $vector[] = $index === 0 ? $k : -$k;

        $normSquared = 0;
        foreach ($vector as $value) {
            $normSquared += $value * $value;
        }
        return ['vector' => $vector, 'normSquared' => $normSquared];
    }

    public static function normalizePulses(array $pulses, float $gain = 1.0, ?int $normSquared = null): array
    {
        if (!is_finite($gain)) {
            throw new InvalidArgumentException('PVQ gain must be finite');
        }
        if ($normSquared === null) {
            self::validateNumericVector($pulses, true);
            $normSquared = 0;
            foreach ($pulses as $pulse) {
                $normSquared += $pulse * $pulse;
            }
        }
        if ($normSquared <= 0) {
            throw new InvalidArgumentException('Cannot normalize a zero pulse vector');
        }
        $scale = $gain / sqrt($normSquared);
        $result = [];
        foreach ($pulses as $pulse) {
            $result[] = $pulse * $scale;
        }
        return $result;
    }

    public static function expRotation(
        array $vector,
        int $blocks,
        int $pulses,
        int $spread,
        bool $encode = false,
        bool $trusted = false
    ): array {
        if (!$trusted) {
            self::validateNumericVector($vector, false);
        }
        $length = count($vector);
        if ($blocks < 1 || $blocks > 16 || $length % $blocks !== 0) {
            throw new InvalidArgumentException('Rotation blocks must divide the vector length and be 1..16');
        }
        if ($pulses < 0 || $pulses > CeltTables::MAX_PULSES || $spread < 0 || $spread > 3) {
            throw new InvalidArgumentException('Invalid CELT pulse count or spread mode');
        }
        $result = array_map(static fn (int|float $value): float => (float) $value, $vector);
        if (2 * $pulses >= $length || $spread === self::SPREAD_NONE) {
            return $result;
        }

        $gain = $length / ($length + (20 - 5 * $spread) * $pulses);
        $theta = M_PI * $gain * $gain / 4;
        $cosine = cos($theta);
        $sine = sin($theta);
        $secondStride = 0;
        if ($length >= 8 * $blocks) {
            $secondStride = 1;
            while (($secondStride * $secondStride + $secondStride) * $blocks + intdiv($blocks, 4) < $length) {
                $secondStride++;
            }
        }

        $blockLength = intdiv($length, $blocks);
        for ($block = 0; $block < $blocks; $block++) {
            $offset = $block * $blockLength;
            if ($encode) {
                self::rotateBlock($result, $offset, $blockLength, 1, $cosine, -$sine);
                if ($secondStride !== 0) {
                    self::rotateBlock($result, $offset, $blockLength, $secondStride, $sine, -$cosine);
                }
            } else {
                if ($secondStride !== 0) {
                    self::rotateBlock($result, $offset, $blockLength, $secondStride, $sine, $cosine);
                }
                self::rotateBlock($result, $offset, $blockLength, 1, $cosine, $sine);
            }
        }
        return $result;
    }

    public static function collapseMask(array $pulses, int $blocks, bool $trusted = false): int
    {
        if (!$trusted) {
            self::validateNumericVector($pulses, true);
        }
        $length = count($pulses);
        if ($blocks < 1 || $blocks > 16 || $length % $blocks !== 0) {
            throw new InvalidArgumentException('Collapse-mask blocks must divide the vector length and be 1..16');
        }
        if ($blocks === 1) {
            return 1;
        }
        $blockLength = intdiv($length, $blocks);
        $mask = 0;
        for ($block = 0; $block < $blocks; $block++) {
            for ($i = 0; $i < $blockLength; $i++) {
                if ($pulses[$block * $blockLength + $i] !== 0) {
                    $mask |= 1 << $block;
                    break;
                }
            }
        }
        return $mask;
    }

    public static function interleaveHadamard(array $vector, int $stride, bool $hadamard = true): array
    {
        return self::reorderHadamard($vector, $stride, $hadamard, false);
    }

    public static function deinterleaveHadamard(array $vector, int $stride, bool $hadamard = true): array
    {
        return self::reorderHadamard($vector, $stride, $hadamard, true);
    }

    private static function reorderHadamard(array $vector, int $stride, bool $hadamard, bool $inverse): array
    {
        self::validateNumericVector($vector, false);
        $length = count($vector);
        if ($length % $stride !== 0) {
            throw new InvalidArgumentException('Hadamard stride must divide the vector length');
        }
        $order = CeltTables::hadamardOrder($stride, $hadamard);
        $n0 = intdiv($length, $stride);
        $result = array_fill(0, $length, 0.0);
        for ($i = 0; $i < $stride; $i++) {
            for ($j = 0; $j < $n0; $j++) {
                if ($inverse) {
                    $result[$order[$i] * $n0 + $j] = $vector[$j * $stride + $i];
                } else {
                    $result[$j * $stride + $i] = $vector[$order[$i] * $n0 + $j];
                }
            }
        }
        return $result;
    }

    private static function rotateBlock(
        array &$vector,
        int $offset,
        int $length,
        int $stride,
        float $cosine,
        float $sine
    ): void {
        for ($i = 0; $i < $length - $stride; $i++) {
            $first = $offset + $i;
            $second = $first + $stride;
            $x1 = $vector[$first];
            $x2 = $vector[$second];
            $vector[$second] = $cosine * $x2 + $sine * $x1;
            $vector[$first] = $cosine * $x1 - $sine * $x2;
        }
        for ($i = $length - 2 * $stride - 1; $i >= 0; $i--) {
            $first = $offset + $i;
            $second = $first + $stride;
            $x1 = $vector[$first];
            $x2 = $vector[$second];
            $vector[$second] = $cosine * $x2 + $sine * $x1;
            $vector[$first] = $cosine * $x1 - $sine * $x2;
        }
    }

    private static function validateNumericVector(array $vector, bool $integersOnly): void
    {
        if ($vector === [] || !array_is_list($vector) || count($vector) > CeltTables::MAX_DIMENSIONS) {
            throw new InvalidArgumentException('CELT vector must be a non-empty list of at most 176 values');
        }
        foreach ($vector as $value) {
            if ($integersOnly ? !is_int($value) : (!is_int($value) && !is_float($value))) {
                throw new InvalidArgumentException($integersOnly ? 'Pulse vectors require integers' : 'CELT vectors require numeric values');
            }
            if (!$integersOnly && !is_finite((float) $value)) {
                throw new InvalidArgumentException('CELT vectors require finite values');
            }
        }
    }
}
