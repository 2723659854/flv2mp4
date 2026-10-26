<?php

namespace Xiaosongshu\Flv2mp4\Aac;

/**
 * @purpose 改进型离散余弦变换器
 * @author yanglong
 * @time 2026年8月12日17:10:32
 */
final class FastMdct
{
    private const SIZE = 2048;
    private const COEFFICIENTS = 1024;

    private static ?array $bitReverse = null;
    private static ?array $inputReal = null;
    private static ?array $inputImaginary = null;
    private static ?array $twiddleReal = null;
    private static ?array $twiddleImaginary = null;
    private static ?array $outputReal = null;
    private static ?array $outputImaginary = null;

    public static function transform(array $input): array
    {
        self::initialize();
        $inputReal = self::$inputReal;
        $inputImaginary = self::$inputImaginary;
        $real = [];
        $imaginary = [];
        for ($i = 0; $i < self::SIZE; ++$i) {
            $sample = $input[$i];
            $real[$i] = $sample * $inputReal[$i];
            $imaginary[$i] = $sample * $inputImaginary[$i];
        }

        self::fft($real, $imaginary);

        $outputReal = self::$outputReal;
        $outputImaginary = self::$outputImaginary;
        $output = [];
        for ($k = 0; $k < self::COEFFICIENTS; ++$k) {
            $output[$k] = ($real[$k] * $outputReal[$k]
                - $imaginary[$k] * $outputImaginary[$k]) * 32768.0;
        }
        return $output;
    }

    private static function initialize(): void
    {
        if (self::$bitReverse !== null) {
            return;
        }

        self::$bitReverse = [];
        self::$inputReal = [];
        self::$inputImaginary = [];
        self::$twiddleReal = [];
        self::$twiddleImaginary = [];
        self::$outputReal = [];
        self::$outputImaginary = [];

        for ($i = 0; $i < self::SIZE; ++$i) {
            $reversed = 0;
            $value = $i;
            for ($bit = 0; $bit < 11; ++$bit) {
                $reversed = ($reversed << 1) | ($value & 1);
                $value >>= 1;
            }
            self::$bitReverse[$i] = $reversed;

            $window = sin(M_PI / self::SIZE * ($i + 0.5));
            $phase = M_PI * $i / self::SIZE;
            self::$inputReal[$i] = $window * cos($phase);
            self::$inputImaginary[$i] = $window * sin($phase);
            self::$twiddleReal[$i] = cos(2.0 * M_PI * $i / self::SIZE);
            self::$twiddleImaginary[$i] = sin(2.0 * M_PI * $i / self::SIZE);
        }

        for ($k = 0; $k < self::COEFFICIENTS; ++$k) {
            $phase = M_PI / self::COEFFICIENTS * 512.5 * ($k + 0.5);
            self::$outputReal[$k] = cos($phase);
            self::$outputImaginary[$k] = sin($phase);
        }
    }

    private static function fft(array &$real, array &$imaginary): void
    {
        $bitReverse = self::$bitReverse;
        $twiddleReal = self::$twiddleReal;
        $twiddleImaginary = self::$twiddleImaginary;
        for ($i = 0; $i < self::SIZE; ++$i) {
            $j = $bitReverse[$i];
            if ($j <= $i) {
                continue;
            }
            $temporary = $real[$i];
            $real[$i] = $real[$j];
            $real[$j] = $temporary;
            $temporary = $imaginary[$i];
            $imaginary[$i] = $imaginary[$j];
            $imaginary[$j] = $temporary;
        }

        for ($length = 2; $length <= self::SIZE; $length <<= 1) {
            $half = $length >> 1;
            $stride = intdiv(self::SIZE, $length);
            for ($start = 0; $start < self::SIZE; $start += $length) {
                for ($j = 0, $twiddle = 0; $j < $half; ++$j, $twiddle += $stride) {
                    $even = $start + $j;
                    $odd = $even + $half;
                    $cos = $twiddleReal[$twiddle];
                    $sin = $twiddleImaginary[$twiddle];
                    $oddReal = $real[$odd] * $cos - $imaginary[$odd] * $sin;
                    $oddImaginary = $real[$odd] * $sin + $imaginary[$odd] * $cos;
                    $real[$odd] = $real[$even] - $oddReal;
                    $imaginary[$odd] = $imaginary[$even] - $oddImaginary;
                    $real[$even] += $oddReal;
                    $imaginary[$even] += $oddImaginary;
                }
            }
        }
    }
}
