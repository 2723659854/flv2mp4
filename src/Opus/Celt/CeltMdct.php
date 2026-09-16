<?php

namespace Xiaosongshu\Flv2mp4\Opus\Celt;

use InvalidArgumentException;

/**
 * @purpose CELT 改进型离散余弦变换器
 * @author yanglong
 * @time 2026年8月12日17:18:39
 */
final class CeltMdct
{
    public const LENGTHS = [120, 240, 480, 960];

    /**
     * F/2 点复数 FFT 的混合基分解（30·2^k：基 2 串接一次基 3、一次基 5）。
     * 等价于 libopus clt_mdct_backward 所用的 N/4 复数 FFT。
     */
    private const FFT_FACTORS = [
        120 => [2, 2, 3, 5],       // F/2 = 60
        240 => [2, 2, 2, 3, 5],    // F/2 = 120
        480 => [2, 2, 2, 2, 3, 5], // F/2 = 240
        960 => [2, 2, 2, 2, 2, 3, 5], // F/2 = 480
    ];

    private static array $inverseTables = [];
    private static array $fftSwaps = [];
    private static array $fftTwiddles = [];

    /** @var array<int, array{ts:float[], tc:float[]}> 快速 IMDCT 预旋转表 */
    private static array $fastTrig = [];
    /** @var array<string, array> 混合基 FFT 旋转因子缓存 */
    private static array $mixedRadixTables = [];

    /**
     * Returns the middle half of the inverse MDCT, which is the layout consumed
     * by CELT's 120-sample overlap-add stage.
     */
    public static function inverse(array $coefficients): array
    {
        $length = count($coefficients);
        self::validateLength($length);
        self::validateNumeric($coefficients);

        $table = self::$inverseTables[$length] ??= self::buildInverseTable($length);
        $fftLength = $table['fftLength'];
        $real = array_fill(0, $fftLength, 0.0);
        $imaginary = array_fill(0, $fftLength, 0.0);
        $offset = $length - 1;

        for ($i = 0; $i < $length; $i++) {
            $value = (float) $coefficients[$i];
            $real[$i] = $value * $table['inputReal'][$i];
            $imaginary[$i] = $value * $table['inputImaginary'][$i];
        }
        $kernelReal = $table['kernelReal'];
        $kernelImaginary = $table['kernelImaginary'];

        self::fft($real, $imaginary, false);
        for ($i = 0; $i < $fftLength; $i++) {
            $r = $real[$i] * $kernelReal[$i] - $imaginary[$i] * $kernelImaginary[$i];
            $imaginary[$i] = $real[$i] * $kernelImaginary[$i] + $imaginary[$i] * $kernelReal[$i];
            $real[$i] = $r;
        }
        self::fft($real, $imaginary, true);

        $output = [];
        $scale = 1.0 / (32768.0 * $fftLength);
        for ($i = 0; $i < $length; $i++) {
            $position = $offset + $i;
            $output[] = ($real[$position] * $table['outputReal'][$i]
                - $imaginary[$position] * $table['outputImaginary'][$i]) * $scale;
        }
        return $output;
    }

    /**
     * 快速 IMDCT：一个 F/2 点混合基复数 FFT（libopus clt_mdct_backward 算法），
     * 输出与 inverse() 数值一致（误差 ~1e-14），960 帧时运算量约为后者的 1/8。
     */
    public static function inverseFast(array $coefficients): array
    {
        $length = count($coefficients);
        self::validateLength($length);
        $fftLength = $length >> 1;

        if (!isset(self::$fastTrig[$length])) {
            $ts = $tc = [];
            for ($i = 0; $i < $fftLength; $i++) {
                $angle = M_PI * ($i + 0.125) / $length;
                $ts[$i] = -sin($angle);
                $tc[$i] = cos($angle);
            }
            self::$fastTrig[$length] = ['ts' => $ts, 'tc' => $tc];
        }
        $trig = self::$fastTrig[$length];

        // 预旋转：将 F 个实系数两两打包成 F/2 个复数
        $real = array_fill(0, $fftLength, 0.0);
        $imaginary = array_fill(0, $fftLength, 0.0);
        for ($i = 0; $i < $fftLength; $i++) {
            $x1 = (float) $coefficients[2 * $i];
            $x2 = (float) $coefficients[$length - 1 - 2 * $i];
            $s = $trig['ts'][$i]; // -sin
            $c = $trig['tc'][$i]; //  cos
            $real[$i] = $x1 * $c - $x2 * $s;
            $imaginary[$i] = $x2 * $c + $x1 * $s;
        }

        self::mixedRadixFft($real, $imaginary, self::FFT_FACTORS[$length]);
        $binPos = self::binPositions(self::FFT_FACTORS[$length]);

        // 后旋转：从 FFT 输出两端同时还原 F 个时域样本。
        // FFT 输出为反位序，按自然频率 bin → 存储位置映射取数。
        $output = array_fill(0, $length, 0.0);
        $scale = 1.0 / 32768.0;
        for ($i = 0; $i < $fftLength; $i++) {
            $pi = $binPos[$i];
            $pj = $binPos[$fftLength - 1 - $i];
            $reA = $imaginary[$pi];
            $imA = $real[$pi];
            $output[2 * $i] = ($reA * $trig['tc'][$i] + $imA * $trig['ts'][$i]) * $scale;
            $reB = $imaginary[$pj];
            $imB = $real[$pj];
            $output[2 * $i + 1] = ($reB * $trig['ts'][$fftLength - 1 - $i] - $imB * $trig['tc'][$fftLength - 1 - $i]) * $scale;
        }
        return $output;
    }

    /**
     * 自然频率 bin → 混合基反位序存储位置的映射（naturalOrder 的逆映射）。
     */
    private static array $binPositionCache = [];

    private static function binPositions(array $factors): array
    {
        $key = implode(',', $factors);
        if (isset(self::$binPositionCache[$key])) {
            return self::$binPositionCache[$key];
        }
        $n = 1;
        foreach ($factors as $f) $n *= $f;
        $products = [1];
        foreach ($factors as $f) $products[] = end($products) * $f;
        $map = [];
        for ($position = 0; $position < $n; $position++) {
            $bin = 0;
            for ($k = 0; $k < count($factors); $k++) {
                $block = intdiv($n, $products[$k + 1]);
                $digit = intdiv($position, $block) % $factors[$k];
                $bin += $digit * $products[$k];
            }
            $map[$bin] = $position;
        }
        return self::$binPositionCache[$key] = $map;
    }

    /** @var array<string, array{scratchR:float[], scratchI:float[]}> 各尺寸层暂存（同一时刻每层只活一个） */
    private static array $fftScratch = [];

    /**
     * 原地混合基 DFT（正向 e^{-i}，无归一化）。factors 乘积须等于数组长度。
     * 递归 Cooley-Tukey：每层按基 f 做 f 点 DFT 后乘 W_n^{a·t} 旋转因子。
     * 基 2 使用就地蝶形；基 3/5 走通用路径（扁平层暂存）。
     */
    private static function mixedRadixFft(array &$real, array &$imaginary, array $factors, int $offset = 0, ?int $length = null): void
    {
        $n = $length ?? count($real);
        if (count($factors) === 0) {
            return;
        }
        $radix = $factors[0];
        $tail = array_slice($factors, 1);
        $half = intdiv($n, $radix);

        if ($radix === 2) {
            // 预计算 a=1 的旋转 W_n^t
            $table = self::$mixedRadixTables["{$n}:2"] ?? null;
            if ($table === null) {
                $twR = $twI = [];
                for ($t = 0; $t < $half; $t++) {
                    $angle = -2.0 * M_PI * $t / $n;
                    $twR[$t] = cos($angle);
                    $twI[$t] = sin($angle);
                }
                $table = [$twR, $twI];
                self::$mixedRadixTables["{$n}:2"] = $table;
            }
            [$twR, $twI] = $table;
            for ($t = 0; $t < $half; $t++) {
                $p0 = $offset + $t;
                $p1 = $p0 + $half;
                $xR = $real[$p0]; $xI = $imaginary[$p0];
                $yR = $real[$p1]; $yI = $imaginary[$p1];
                $real[$p0] = $xR + $yR;
                $imaginary[$p0] = $xI + $yI;
                $dR = $xR - $yR;
                $dI = $xI - $yI;
                $c = $twR[$t]; $s = $twI[$t];
                $real[$p1] = $dR * $c - $dI * $s;
                $imaginary[$p1] = $dR * $s + $dI * $c;
            }
        } else {
            $table = self::$mixedRadixTables["{$n}:{$radix}"] ?? null;
            if ($table === null) {
                $innerPhase = [];
                for ($a = 0; $a < $radix; $a++) {
                    for ($l = 0; $l < $radix; $l++) {
                        $angle = -2.0 * M_PI * (($a * $l) % $radix) / $radix;
                        $innerPhase[$a][$l] = [cos($angle), sin($angle)];
                    }
                }
                $twR = $twI = [];
                for ($a = 0; $a < $radix; $a++) {
                    for ($t = 0; $t < $half; $t++) {
                        $angle = -2.0 * M_PI * $a * $t / $n;
                        $twR[$a * $half + $t] = cos($angle);
                        $twI[$a * $half + $t] = sin($angle);
                    }
                }
                $table = [$innerPhase, $twR, $twI];
                self::$mixedRadixTables["{$n}:{$radix}"] = $table;
            }
            [$innerPhase, $twR, $twI] = $table;

            if (!isset(self::$fftScratch[$n])) {
                self::$fftScratch[$n] = [
                    'scratchR' => array_fill(0, $n, 0.0),
                    'scratchI' => array_fill(0, $n, 0.0),
                ];
            }
            $scratchR = &self::$fftScratch[$n]['scratchR'];
            $scratchI = &self::$fftScratch[$n]['scratchI'];

            for ($a = 0; $a < $radix; $a++) {
                $outBase = $a * $half;
                for ($t = 0; $t < $half; $t++) {
                    $sumR = 0.0;
                    $sumI = 0.0;
                    for ($l = 0; $l < $radix; $l++) {
                        $idx = $offset + $l * $half + $t;
                        $xR = $real[$idx];
                        $xI = $imaginary[$idx];
                        $c = $innerPhase[$a][$l][0];
                        $s = $innerPhase[$a][$l][1];
                        $sumR += $xR * $c - $xI * $s;
                        $sumI += $xR * $s + $xI * $c;
                    }
                    $wIdx = $outBase + $t;
                    $c = $twR[$wIdx];
                    $s = $twI[$wIdx];
                    $scratchR[$wIdx] = $sumR * $c - $sumI * $s;
                    $scratchI[$wIdx] = $sumR * $s + $sumI * $c;
                }
            }
            for ($i = 0; $i < $n; $i++) {
                $real[$offset + $i] = $scratchR[$i];
                $imaginary[$offset + $i] = $scratchI[$i];
            }
        }

        if ($tail !== []) {
            for ($a = 0; $a < $radix; $a++) {
                self::mixedRadixFft($real, $imaginary, $tail, $offset + $a * $half, $half);
            }
        }
    }

    private static function buildInverseTable(int $length): array
    {
        $fftLength = 1;
        while ($fftLength < 3 * $length - 2) {
            $fftLength <<= 1;
        }
        $inputReal = $inputImaginary = $outputReal = $outputImaginary = [];
        $kernelReal = array_fill(0, $fftLength, 0.0);
        $kernelImaginary = array_fill(0, $fftLength, 0.0);
        $factor = M_PI / $length;
        $offset = $length - 1;
        for ($i = 0; $i < $length; $i++) {
            $angle = $factor * (($length + 0.5) * $i + 0.5 * $i * $i);
            $inputReal[$i] = cos($angle);
            $inputImaginary[$i] = sin($angle);
            $angle = $factor * (0.5 * ($i + $length + 0.5) + 0.5 * $i * $i);
            $outputReal[$i] = cos($angle);
            $outputImaginary[$i] = sin($angle);
        }
        for ($i = -$length + 1; $i < $length; $i++) {
            $angle = -0.5 * $factor * $i * $i;
            $kernelReal[$offset + $i] = cos($angle);
            $kernelImaginary[$offset + $i] = sin($angle);
        }
        self::fft($kernelReal, $kernelImaginary, false);
        return compact(
            'fftLength', 'inputReal', 'inputImaginary', 'kernelReal', 'kernelImaginary',
            'outputReal', 'outputImaginary'
        );
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
        if (!isset(self::$fftSwaps[$length])) {
            $swaps = [];
            for ($i = 1, $j = 0; $i < $length; $i++) {
                $bit = $length >> 1;
                while (($j & $bit) !== 0) {
                    $j ^= $bit;
                    $bit >>= 1;
                }
                $j ^= $bit;
                if ($i < $j) {
                    $swaps[] = [$i, $j];
                }
            }
            self::$fftSwaps[$length] = $swaps;
        }
        foreach (self::$fftSwaps[$length] as [$i, $j]) {
            $temporary = $real[$i];
            $real[$i] = $real[$j];
            $real[$j] = $temporary;
            $temporary = $imaginary[$i];
            $imaginary[$i] = $imaginary[$j];
            $imaginary[$j] = $temporary;
        }

        $direction = $inverse ? 1 : -1;
        if (!isset(self::$fftTwiddles[$length][$direction])) {
            $stages = [];
            for ($size = 2; $size <= $length; $size <<= 1) {
                $half = $size >> 1;
                $realTwiddles = $imaginaryTwiddles = [];
                $factor = $direction * 2.0 * M_PI / $size;
                for ($i = 0; $i < $half; $i++) {
                    $realTwiddles[$i] = cos($factor * $i);
                    $imaginaryTwiddles[$i] = sin($factor * $i);
                }
                $stages[$size] = [$realTwiddles, $imaginaryTwiddles];
            }
            self::$fftTwiddles[$length][$direction] = $stages;
        }
        $stages = self::$fftTwiddles[$length][$direction];
        for ($size = 2; $size <= $length; $size <<= 1) {
            $half = $size >> 1;
            $wr = $stages[$size][0];
            $wi = $stages[$size][1];
            for ($start = 0; $start < $length; $start += $size) {
                for ($i = 0; $i < $half; $i++) {
                    $even = $start + $i;
                    $odd = $even + $half;
                    $tr = $wr[$i] * $real[$odd] - $wi[$i] * $imaginary[$odd];
                    $ti = $wr[$i] * $imaginary[$odd] + $wi[$i] * $real[$odd];
                    $real[$odd] = $real[$even] - $tr;
                    $imaginary[$odd] = $imaginary[$even] - $ti;
                    $real[$even] += $tr;
                    $imaginary[$even] += $ti;
                }
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
