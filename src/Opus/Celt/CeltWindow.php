<?php

namespace Xiaosongshu\Flv2mp4\Opus\Celt;

use InvalidArgumentException;

/*
 * Portions follow RFC 6716 Code Components, Copyright (c) 2012 IETF Trust.
 * Redistribution and use in source and binary forms, with or without modification,
 * are permitted provided that this notice is retained. THIS SOFTWARE IS PROVIDED
 * "AS IS", WITHOUT WARRANTY; contributors are not liable for any damages.
 */
final class CeltWindow
{
    public const OVERLAP = 120;

    private static ?array $opusWindow = null;

    public static function coefficients(int $overlap = self::OVERLAP): array
    {
        if ($overlap < 1 || $overlap > self::OVERLAP) {
            throw new InvalidArgumentException('CELT overlap must be between 1 and 120 samples');
        }
        if ($overlap === self::OVERLAP && self::$opusWindow !== null) {
            return self::$opusWindow;
        }

        $window = [];
        for ($i = 0; $i < $overlap; $i++) {
            $inner = sin(0.5 * M_PI * ($i + 0.5) / $overlap);
            $window[] = sin(0.5 * M_PI * $inner * $inner);
        }
        if ($overlap === self::OVERLAP) {
            self::$opusWindow = $window;
        }
        return $window;
    }

    public static function overlapAdd(array &$buffer, array $block, int $offset, int $overlap = self::OVERLAP): void
    {
        $blockSize = count($block);
        $half = intdiv($overlap, 2);
        if (!in_array($blockSize, CeltMdct::LENGTHS, true) || $overlap > $blockSize || ($overlap & 1) !== 0
            || $offset < 0 || $offset + $blockSize + $overlap > count($buffer)) {
            throw new InvalidArgumentException('Invalid CELT block, overlap, or output offset');
        }

        for ($i = 0; $i < $blockSize; $i++) {
            $buffer[$offset + $half + $i] = (float) $block[$i];
        }
        $window = self::coefficients($overlap);
        for ($i = 0, $j = $half - 1; $i < $half; $i++, $j--) {
            $s0 = (float) $buffer[$offset + $i];
            $s1 = (float) $buffer[$offset + $half + $j];
            $wi = $window[$i];
            $wj = $window[$overlap - 1 - $i];
            $buffer[$offset + $i] = $s0 * $wj - $s1 * $wi;
            $buffer[$offset + $overlap - 1 - $i] = $s0 * $wi + $s1 * $wj;
        }
    }
}
