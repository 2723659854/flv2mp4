<?php

namespace Xiaosongshu\Flv2mp4\Opus\Celt;

use InvalidArgumentException;

/*
 * Portions follow RFC 6716 Code Components, Copyright (c) 2012 IETF Trust.
 * Redistribution and use in source and binary forms, with or without modification,
 * are permitted provided that this notice is retained. THIS SOFTWARE IS PROVIDED
 * "AS IS", WITHOUT WARRANTY; contributors are not liable for any damages.
 */
final class CeltMdct
{
    public const LENGTHS = [120, 240, 480, 960];

    /**
     * Returns the middle half of the inverse MDCT, which is the layout consumed
     * by CELT's 120-sample overlap-add stage.
     */
    public static function inverse(array $coefficients): array
    {
        $length = count($coefficients);
        self::validateLength($length);
        self::validateNumeric($coefficients);

        $fftLength = 1;
        while ($fftLength < 3 * $length - 2) {
            $fftLength <<= 1;
        }
        $real = array_fill(0, $fftLength, 0.0);
        $imaginary = array_fill(0, $fftLength, 0.0);
        $kernelReal = array_fill(0, $fftLength, 0.0);
        $kernelImaginary = array_fill(0, $fftLength, 0.0);
        $factor = M_PI / $length;
        $offset = $length - 1;

        for ($i = 0; $i < $length; $i++) {
            $angle = $factor * (($length + 0.5) * $i + 0.5 * $i * $i);
            $value = (float) $coefficients[$i];
            $real[$i] = $value * cos($angle);
            $imaginary[$i] = $value * sin($angle);
        }
        for ($i = -$length + 1; $i < $length; $i++) {
            $angle = -0.5 * $factor * $i * $i;
            $kernelReal[$offset + $i] = cos($angle);
            $kernelImaginary[$offset + $i] = sin($angle);
        }

        self::fft($real, $imaginary, false);
        self::fft($kernelReal, $kernelImaginary, false);
        for ($i = 0; $i < $fftLength; $i++) {
            $r = $real[$i] * $kernelReal[$i] - $imaginary[$i] * $kernelImaginary[$i];
            $imaginary[$i] = $real[$i] * $kernelImaginary[$i] + $imaginary[$i] * $kernelReal[$i];
            $real[$i] = $r;
        }
        self::fft($real, $imaginary, true);

        $output = [];
        $scale = 1.0 / 32768.0;
        for ($i = 0; $i < $length; $i++) {
            $angle = $factor * (0.5 * ($i + $length + 0.5) + 0.5 * $i * $i);
            $position = $offset + $i;
            $output[] = ($real[$position] * cos($angle) - $imaginary[$position] * sin($angle)) * $scale;
        }
        return $output;
    }

    /**
     * Analysis counterpart used for transform validation and future encoder use.
     */
    public static function forward(array $samples): array
    {
        $count = count($samples);
        if (($count & 1) !== 0) {
            throw new InvalidArgumentException('MDCT input must contain 2N samples');
        }
        $length = intdiv($count, 2);
        self::validateLength($length);
        self::validateNumeric($samples);

        $output = array_fill(0, $length, 0.0);
        $factor = M_PI / $length;
        for ($bin = 0; $bin < $length; $bin++) {
            $sum = 0.0;
            for ($sample = 0; $sample < 2 * $length; $sample++) {
                $sum += (float) $samples[$sample]
                    * cos($factor * ($sample + 0.5 + $length / 2.0) * ($bin + 0.5));
            }
            $output[$bin] = $sum;
        }
        return $output;
    }

    private static function fft(array &$real, array &$imaginary, bool $inverse): void
    {
        $length = count($real);
        for ($i = 1, $j = 0; $i < $length; $i++) {
            $bit = $length >> 1;
            while (($j & $bit) !== 0) {
                $j ^= $bit;
                $bit >>= 1;
            }
            $j ^= $bit;
            if ($i < $j) {
                [$real[$i], $real[$j]] = [$real[$j], $real[$i]];
                [$imaginary[$i], $imaginary[$j]] = [$imaginary[$j], $imaginary[$i]];
            }
        }

        for ($size = 2; $size <= $length; $size <<= 1) {
            $angle = ($inverse ? 2.0 : -2.0) * M_PI / $size;
            $stepReal = cos($angle);
            $stepImaginary = sin($angle);
            $half = $size >> 1;
            for ($start = 0; $start < $length; $start += $size) {
                $wr = 1.0;
                $wi = 0.0;
                for ($i = 0; $i < $half; $i++) {
                    $even = $start + $i;
                    $odd = $even + $half;
                    $tr = $wr * $real[$odd] - $wi * $imaginary[$odd];
                    $ti = $wr * $imaginary[$odd] + $wi * $real[$odd];
                    $real[$odd] = $real[$even] - $tr;
                    $imaginary[$odd] = $imaginary[$even] - $ti;
                    $real[$even] += $tr;
                    $imaginary[$even] += $ti;
                    $nextWr = $wr * $stepReal - $wi * $stepImaginary;
                    $wi = $wr * $stepImaginary + $wi * $stepReal;
                    $wr = $nextWr;
                }
            }
        }
        if ($inverse) {
            for ($i = 0; $i < $length; $i++) {
                $real[$i] /= $length;
                $imaginary[$i] /= $length;
            }
        }
    }

    private static function validateLength(int $length): void
    {
        if (!in_array($length, self::LENGTHS, true)) {
            throw new InvalidArgumentException('CELT MDCT length must be 120, 240, 480, or 960');
        }
    }

    private static function validateNumeric(array $values): void
    {
        foreach ($values as $value) {
            if (!is_int($value) && !is_float($value)) {
                throw new InvalidArgumentException('MDCT values must be numeric');
            }
        }
    }
}
