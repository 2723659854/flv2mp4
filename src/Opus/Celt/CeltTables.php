<?php

namespace Xiaosongshu\Flv2mp4\Opus\Celt;

use InvalidArgumentException;
use OverflowException;

/*
 * Copyright (c) 2007-2009 Xiph.Org Foundation
 * Copyright (c) 2007-2009 Timothy B. Terriberry
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the above copyright notice and
 * this permission notice are retained. THIS SOFTWARE IS PROVIDED BY THE
 * COPYRIGHT HOLDERS AND CONTRIBUTORS ``AS IS'' AND ANY EXPRESS OR IMPLIED
 * WARRANTIES ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR
 * CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL,
 * EXEMPLARY, OR CONSEQUENTIAL DAMAGES.
 */
final class CeltTables
{
    public const MAX_DIMENSIONS = 176;
    public const MAX_PULSES = 128;
    public const UINT32_MAX = 0xFFFFFFFF;

    private const HADAMARD_ORDER = [
        2 => [1, 0],
        4 => [3, 0, 2, 1],
        8 => [7, 0, 4, 3, 6, 1, 5, 2],
        16 => [15, 0, 8, 7, 12, 3, 11, 4, 14, 1, 9, 6, 13, 2, 10, 5],
    ];

    private const CACHE_INDEX = '-1 -1 -1 -1 -1 -1 -1 -1 0 0 0 0 41 41 41 82 82 123 164 200 222 0 0 0 0 0 0 0 0 41 41 41 41 123 123 123 164 164 240 266 283 295 41 41 41 41 41 41 41 41 123 123 123 123 240 240 240 266 266 305 318 328 336 123 123 123 123 123 123 123 123 240 240 240 240 305 305 305 318 318 343 351 358 364 240 240 240 240 240 240 240 240 305 305 305 305 343 343 343 351 351 370 376 382 387';
    private const CACHE_BITS = '40 7 7 7 7 7 7 7 7 7 7 7 7 7 7 7 7 7 7 7 7 7 7 7 7 7 7 7 7 7 7 7 7 7 7 7 7 7 7 7 7 40 15 23 28 31 34 36 38 39 41 42 43 44 45 46 47 47 49 50 51 52 53 54 55 55 57 58 59 60 61 62 63 63 65 66 67 68 69 70 71 71 40 20 33 41 48 53 57 61 64 66 69 71 73 75 76 78 80 82 85 87 89 91 92 94 96 98 101 103 105 107 108 110 112 114 117 119 121 123 124 126 128 40 23 39 51 60 67 73 79 83 87 91 94 97 100 102 105 107 111 115 118 121 124 126 129 131 135 139 142 145 148 150 153 155 159 163 166 169 172 174 177 179 35 28 49 65 78 89 99 107 114 120 126 132 136 141 145 149 153 159 165 171 176 180 185 189 192 199 205 211 216 220 225 229 232 239 245 251 21 33 58 79 97 112 125 137 148 157 166 174 182 189 195 201 207 217 227 235 243 251 17 35 63 86 106 123 139 152 165 177 187 197 206 214 222 230 237 250 25 31 55 75 91 105 117 128 138 146 154 161 168 174 180 185 190 200 208 215 222 229 235 240 245 255 16 36 65 89 110 128 144 159 173 185 196 207 217 226 234 242 250 11 41 74 103 128 151 172 191 209 225 241 255 9 43 79 110 138 163 186 207 227 246 12 39 71 99 123 144 164 182 198 214 228 241 253 9 44 81 113 142 168 192 214 235 255 7 49 90 127 160 191 220 247 6 51 95 134 170 203 234 7 47 87 123 155 184 212 237 6 52 97 137 174 208 240 5 57 106 151 192 231 5 59 111 158 202 243 5 55 103 147 187 224 5 60 113 161 206 248 4 65 122 175 224 4 67 127 182 234';
    private const CACHE_CAPS = '224 224 224 224 224 224 224 224 160 160 160 160 185 185 185 178 178 168 134 61 37 224 224 224 224 224 224 224 224 240 240 240 240 207 207 207 198 198 183 144 66 40 160 160 160 160 160 160 160 160 185 185 185 185 193 193 193 183 183 172 138 64 38 240 240 240 240 240 240 240 240 207 207 207 207 204 204 204 193 193 180 143 66 40 185 185 185 185 185 185 185 185 193 193 193 193 193 193 193 183 183 172 138 65 39 207 207 207 207 207 207 207 207 204 204 204 204 201 201 201 188 188 176 141 66 40 193 193 193 193 193 193 193 193 193 193 193 193 194 194 194 184 184 173 139 65 39 204 204 204 204 204 204 204 204 201 201 201 201 198 198 198 187 187 175 140 66 40';

    private static array $uCache = [];
    private static ?array $cacheIndex = null;
    private static ?array $cacheBits = null;
    private static ?array $cacheCaps = null;

    public static function pulseCache(int $band, int $lm): array
    {
        $indexes = self::$cacheIndex ??= self::numbers(self::CACHE_INDEX);
        $bits = self::$cacheBits ??= self::numbers(self::CACHE_BITS);
        $offset = $indexes[($lm + 1) * 21 + $band];
        $length = $bits[$offset];
        return array_slice($bits, $offset, $length + 1);
    }

    public static function cap(int $band, int $lm, int $channels, int $width): int
    {
        $caps = self::$cacheCaps ??= self::numbers(self::CACHE_CAPS);
        return intdiv(($caps[21 * (2 * $lm + $channels - 1) + $band] + 64) * $channels * $width, 4);
    }

    public static function bitsToPulses(int $band, int $lm, int $bits): int
    {
        $cache = self::pulseCache($band, $lm);
        $lo = 0;
        $hi = $cache[0];
        $bits--;
        for ($i = 0; $i < 6; $i++) {
            $mid = intdiv($lo + $hi + 1, 2);
            if ($cache[$mid] >= $bits) {
                $hi = $mid;
            } else {
                $lo = $mid;
            }
        }
        return $bits - ($lo === 0 ? -1 : $cache[$lo]) <= $cache[$hi] - $bits ? $lo : $hi;
    }

    public static function pulsesToBits(int $band, int $lm, int $pulses): int
    {
        return $pulses === 0 ? 0 : self::pulseCache($band, $lm)[$pulses] + 1;
    }

    public static function pulseCount(int $index): int
    {
        return $index < 8 ? $index : (8 + ($index & 7)) << (($index >> 3) - 1);
    }

    public static function u(int $n, int $k): int
    {
        self::validateCoordinates($n, $k);
        if ($n === 0) {
            return $k === 0 ? 1 : 0;
        }
        if ($k === 0) {
            return 0;
        }
        if ($n === 1 || $k === 1) {
            return 1;
        }

        $small = min($n, $k);
        $large = max($n, $k);
        $key = $small . ':' . $large;
        if (isset(self::$uCache[$key])) {
            return self::$uCache[$key];
        }

        $previous = array_fill(0, $large + 1, 0);
        $previous[0] = 1;
        for ($row = 1; $row <= $small; $row++) {
            $current = array_fill(0, $large + 1, 0);
            for ($column = 1; $column <= $large; $column++) {
                $a = $previous[$column];
                $b = $current[$column - 1];
                $c = $previous[$column - 1];
                if ($a > self::UINT32_MAX - $b || $a + $b > self::UINT32_MAX - $c) {
                    throw new OverflowException("U($n,$k) exceeds the CELT 32-bit codebook domain");
                }
                $current[$column] = $a + $b + $c;
            }
            $previous = $current;
        }
        return self::$uCache[$key] = $previous[$large];
    }

    public static function v(int $n, int $k): int
    {
        if ($n < 1 || $n > self::MAX_DIMENSIONS || $k < 0 || $k > self::MAX_PULSES) {
            throw new InvalidArgumentException('PVQ dimensions must be 1..176 and pulses 0..128');
        }
        $a = self::u($n, $k);
        $b = self::u($n, $k + 1);
        if ($a > self::UINT32_MAX - $b) {
            throw new OverflowException("V($n,$k) exceeds the CELT 32-bit codebook domain");
        }
        return $a + $b;
    }

    public static function hadamardOrder(int $stride, bool $hadamard = true): array
    {
        if (!in_array($stride, [1, 2, 4, 8, 16], true)) {
            throw new InvalidArgumentException('Hadamard stride must be 1, 2, 4, 8, or 16');
        }
        if (!$hadamard || $stride === 1) {
            return range(0, $stride - 1);
        }
        return self::HADAMARD_ORDER[$stride];
    }

    private static function numbers(string $values): array
    {
        return array_map('intval', explode(' ', $values));
    }

    private static function validateCoordinates(int $n, int $k): void
    {
        if ($n < 0 || $n > self::MAX_DIMENSIONS || $k < 0 || $k > self::MAX_PULSES + 1) {
            throw new InvalidArgumentException('U coordinates exceed the standard Opus CELT domain');
        }
    }
}
