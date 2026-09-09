<?php

namespace Xiaosongshu\Flv2mp4\Opus\Encode;

use InvalidArgumentException;
use Xiaosongshu\Flv2mp4\Opus\Celt\CeltTables;

/** Encodes one CELT PVQ pulse vector using the reference CWRS ordering. */
final class CeltPvqEncoder
{
    public static function index(array $vector, ?int $pulses = null): int
    {
        if ($vector === [] || !array_is_list($vector)) {
            throw new InvalidArgumentException('PVQ vector must be a non-empty list');
        }
        $n = count($vector);
        if ($n < 2 || $n > CeltTables::MAX_DIMENSIONS) {
            throw new InvalidArgumentException('PVQ dimensions must be 2..176');
        }
        $sum = 0;
        foreach ($vector as $value) {
            if (!is_int($value)) {
                throw new InvalidArgumentException('PVQ vector values must be integers');
            }
            $sum += abs($value);
        }
        if ($pulses === null) $pulses = $sum;
        if ($pulses < 1 || $pulses > CeltTables::MAX_PULSES || $sum !== $pulses) {
            throw new InvalidArgumentException('PVQ pulse count does not match the vector');
        }
        $index = $vector[$n - 1] < 0 ? 1 : 0;
        $k = abs($vector[$n - 1]);
        for ($j = $n - 2; $j >= 0; $j--) {
            $index += CeltTables::u($n - $j, $k);
            $k += abs($vector[$j]);
            if ($vector[$j] < 0) $index += CeltTables::u($n - $j, $k + 1);
        }
        return $index;
    }

    public static function encode(RangeEncoder $encoder, array $vector, ?int $pulses = null): int
    {
        $pulses ??= array_sum(array_map(static fn(int $v): int => abs($v), $vector));
        $index = self::index($vector, $pulses);
        $encoder->encodeUint($index, CeltTables::v(count($vector), $pulses));
        return $index;
    }

    public static function encodePulses(RangeEncoder $encoder, array $vector, ?int $pulses = null): int
    {
        return self::encode($encoder, $vector, $pulses);
    }
}
