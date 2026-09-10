<?php

namespace Xiaosongshu\Flv2mp4\Opus\Encode;

use InvalidArgumentException;
use Xiaosongshu\Flv2mp4\Opus\Celt\CeltWindow;

/**
 * CELT 正变换（48 kHz / 20 ms / LM=3 / 非瞬态）。
 *
 * 严格对照 opus-main/celt/mdct.c 的 clt_mdct_forward_c（浮点路径）：
 *   MDCT 全尺寸 N=1920，N2=960，N4=480，overlap=120。
 *   输入只有 N2+overlap=1080 个样本：前 120 个是上一帧尾部历史，
 *   后 960 个是当前帧。窗折叠后得到 480 个复数，做 480 点复数 FFT，
 *   再旋转出 960 个频谱系数。
 */
final class CeltMdctEncoder
{
    private const N = 1920;
    private const N2 = 960;
    private const N4 = 480;
    private const OVERLAP = 120;

    /** @var float[] */
    private static array $trig = [];
    /** @var array{0:float[],1:float[]} 卷积核的 FFT（Bluestein） */
    private static array $kernelFft = [];

    /**
     * @param array<int, int|float> $samples 长度 1080：120 历史 + 960 当前
     * @return array<int, float> 长度 960 的频谱
     */
    public static function forward(array $samples): array
    {
        if (count($samples) !== self::N2 + self::OVERLAP) {
            throw new InvalidArgumentException('CELT MDCT encoder requires 1080 samples (120 history + 960 current)');
        }

        $window = CeltWindow::coefficients(self::OVERLAP);
        $f = self::fold(array_map('floatval', $samples), $window);

        // 预旋转，等价 mdct.c 的 t0/t1（浮点 scale=1，headroom=0）。
        $trig = self::trigTable();
        $re = array_fill(0, self::N4, 0.0);
        $im = array_fill(0, self::N4, 0.0);
        for ($i = 0; $i < self::N4; $i++) {
            $xre = $f[2 * $i];
            $xim = $f[2 * $i + 1];
            $t0 = $trig[$i];
            $t1 = $trig[self::N4 + $i];
            $re[$i] = $xre * $t0 - $xim * $t1;
            $im[$i] = $xim * $t0 + $xre * $t1;
        }

        // 480 点复数 FFT（非 2 的幂，用 Bluestein 转到 1024 点 radix-2）。
        self::fft480($re, $im);

        // 后旋转，输出 960 个实数频谱；stride=1。
        $out = array_fill(0, self::N2, 0.0);
        for ($i = 0; $i < self::N4; $i++) {
            $t0 = $trig[$i];
            $t1 = $trig[self::N4 + $i];
            $fr = $re[$i];
            $fi = $im[$i];
            $out[2 * $i] = $fi * $t1 - $fr * $t0;
            $out[self::N2 - 1 - 2 * $i] = $fr * $t1 + $fi * $t0;
        }
        return $out;
    }

    /**
     * 窗折叠，对照 mdct.c forward 的 "Window, shuffle, fold" 段。
     * 输入 in[0..1079]，输出 f[0..959]（480 个复数，实/虚交错）。
     *
     * @param float[] $in
     * @param float[] $w 长度 120 的 CELT 窗
     * @return float[]
     */
    private static function fold(array $in, array $w): array
    {
        $f = array_fill(0, self::N2, 0.0);
        $half = self::OVERLAP >> 1;            // 60
        $middle = (self::OVERLAP + 3) >> 2;    // 30

        // 第一段：i = 0..29
        for ($i = 0; $i < $middle; $i++) {
            $a = $in[$half + 2 * $i];                 // xp1
            $b = $in[self::N2 - 1 + $half - 2 * $i];  // xp2
            $c = $in[$half + 2 * $i + self::N2];      // xp1[N2]
            $d = $in[$half - 1 - 2 * $i];             // xp2[-N2]
            $wa = $w[$half + 2 * $i];                  // wp1
            $wb = $w[$half - 1 - 2 * $i];              // wp2
            $f[2 * $i] = $c * $wb + $b * $wa;
            $f[2 * $i + 1] = $a * $wa - $d * $wb;
        }

        // 中段：i = 30..449，不加窗直通。
        for ($i = $middle; $i < self::N4 - $middle; $i++) {
            $f[2 * $i] = $in[self::N2 - 1 + $half - 2 * $i]; // *xp2
            $f[2 * $i + 1] = $in[$half + 2 * $i];             // *xp1
        }

        // 第三段：i = 450..479
        for ($i = self::N4 - $middle; $i < self::N4; $i++) {
            $j = $i - (self::N4 - $middle);
            $a = $in[$half + 2 * $i];                  // xp1
            $b = $in[self::N2 - 1 + $half - 2 * $i];   // xp2
            $am = $in[$half + 2 * $i - self::N2];      // xp1[-N2]
            $bp = $in[self::N2 - 1 + $half - 2 * $i + self::N2]; // xp2[N2]
            $wa = $w[2 * $j];                          // wp1（从 window[0]）
            $wb = $w[self::OVERLAP - 1 - 2 * $j];      // wp2（从 window[119]）
            $f[2 * $i] = -$am * $wa + $b * $wb;
            $f[2 * $i + 1] = $a * $wb + $bp * $wa;
        }

        return $f;
    }

    /** @return float[] trig[i] = cos(2*pi*(i+0.125)/N), i=0..959 */
    private static function trigTable(): array
    {
        if (self::$trig !== []) {
            return self::$trig;
        }
        $trig = [];
        for ($i = 0; $i < self::N2; $i++) {
            $trig[$i] = cos(2.0 * M_PI * ($i + 0.125) / self::N);
        }
        return self::$trig = $trig;
    }

    /**
     * 480 点原地复数 FFT（正向，e^{-j}），用 Bluestein 转到 1024 点。
     *
     * @param float[] $re
     * @param float[] $im
     */
    private static function fft480(array &$re, array &$im): void
    {
        $n = self::N4;        // 480
        $m = 1024;            // >= 2*480-1 的最小 2 的幂
        [$kr, $ki] = self::kernel($m);

        $ar = array_fill(0, $m, 0.0);
        $ai = array_fill(0, $m, 0.0);
        for ($idx = 0; $idx < $n; $idx++) {
            $angle = M_PI * $idx * $idx / $n;
            $wr = cos($angle);
            $wi = -sin($angle); // w_n = e^{-j pi n^2/N}
            $ar[$idx] = $re[$idx] * $wr - $im[$idx] * $wi;
            $ai[$idx] = $re[$idx] * $wi + $im[$idx] * $wr;
        }
        self::radix2($ar, $ai, false);
        for ($idx = 0; $idx < $m; $idx++) {
            $rr = $ar[$idx] * $kr[$idx] - $ai[$idx] * $ki[$idx];
            $ri = $ar[$idx] * $ki[$idx] + $ai[$idx] * $kr[$idx];
            $ar[$idx] = $rr;
            $ai[$idx] = $ri;
        }
        self::radix2($ar, $ai, true); // IFFT（内部除以 M）

        for ($k = 0; $k < $n; $k++) {
            $angle = M_PI * $k * $k / $n;
            $wr = cos($angle);
            $wi = -sin($angle);
            $re[$k] = $ar[$k] * $wr - $ai[$k] * $wi;
            $im[$k] = $ar[$k] * $wi + $ai[$k] * $wr;
        }
    }

    /** @return array{0:float[],1:float[]} */
    private static function kernel(int $m): array
    {
        if (self::$kernelFft !== []) {
            return self::$kernelFft;
        }
        $n = self::N4;
        $br = array_fill(0, $m, 0.0);
        $bi = array_fill(0, $m, 0.0);
        for ($idx = 0; $idx < $n; $idx++) {
            $angle = M_PI * $idx * $idx / $n;
            // b_n = e^{+j pi n^2/N}
            $br[$idx] = cos($angle);
            $bi[$idx] = sin($angle);
        }
        for ($idx = 1; $idx < $n; $idx++) {
            $angle = M_PI * $idx * $idx / $n;
            // 负索引 m=-idx：(-idx)^2 = idx^2，故虚部同样为 +sin。
            $br[$m - $idx] = cos($angle);
            $bi[$m - $idx] = sin($angle);
        }
        self::radix2($br, $bi, false);
        return self::$kernelFft = [$br, $bi];
    }

    /**
     * 迭代 radix-2 复数 FFT，长度必须是 2 的幂。
     * inverse=false 为正向（e^{-j}）；true 为逆向并除以长度。
     *
     * @param float[] $re
     * @param float[] $im
     */
    private static function radix2(array &$re, array &$im, bool $inverse): void
    {
        $length = count($re);
        for ($i = 1, $j = 0; $i < $length; $i++) {
            $bit = $length >> 1;
            while (($j & $bit) !== 0) {
                $j ^= $bit;
                $bit >>= 1;
            }
            $j ^= $bit;
            if ($i < $j) {
                [$re[$i], $re[$j]] = [$re[$j], $re[$i]];
                [$im[$i], $im[$j]] = [$im[$j], $im[$i]];
            }
        }
        $direction = $inverse ? 1.0 : -1.0;
        for ($size = 2; $size <= $length; $size <<= 1) {
            $half = $size >> 1;
            $factor = $direction * 2.0 * M_PI / $size;
            for ($start = 0; $start < $length; $start += $size) {
                for ($i = 0; $i < $half; $i++) {
                    $wr = cos($factor * $i);
                    $wi = sin($factor * $i);
                    $even = $start + $i;
                    $odd = $even + $half;
                    $tr = $wr * $re[$odd] - $wi * $im[$odd];
                    $ti = $wr * $im[$odd] + $wi * $re[$odd];
                    $re[$odd] = $re[$even] - $tr;
                    $im[$odd] = $im[$even] - $ti;
                    $re[$even] += $tr;
                    $im[$even] += $ti;
                }
            }
        }
        if ($inverse) {
            $scale = 1.0 / $length;
            for ($i = 0; $i < $length; $i++) {
                $re[$i] *= $scale;
                $im[$i] *= $scale;
            }
        }
    }
}
