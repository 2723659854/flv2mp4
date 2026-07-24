<?php

namespace Xiaosongshu\Flv2mp4\Codec\Decoder;

/**
 * @purpose 逆离散余弦变换
 * @author yanglong
 * @time 2026年7月23日15:27:12
 */
trait TransformTrait
{
    /**
     * Z字形转光栅顺序
     */
    public function zigzagToRaster(array $block): array
    {
        $out = array_fill(0, 16, 0);
        for ($i = 0; $i < 16; $i++) {
            $pos = self::ZIGZAG_SCAN_4X4[$i];
            $out[$pos] = $block[$i];
        }
        return $out;
    }

    /**
     * 4x4反量化
     * 公式: (level * table[qp][pos] + 32) >> 6
     * table 已包含 INIT * scaling_matrix << (qp/6 + 2)
     * @param array $coeff 16个系数（raster顺序）
     * @param int $type 0亮度intra 1色度intra
     * @param int $qp
     * @return array
     */
    public function dequantize4x4(array $coeff, int $type, int $qp): array
    {
        $out = array_fill(0, 16, 0);
        $qp = max(0, min(51, $qp));
        // type 0=intra Y (list 0), type 1=Cb (list 1), type 2=Cr (list 2)
        $listIdx = $type;
        for ($i = 0; $i < 16; $i++) {
            if ($coeff[$i] == 0) continue;
            // intdiv是向0取整，对负数结果错误
            $out[$i] = ($coeff[$i] * $this->dequant4Table[$listIdx][$qp][$i] + 32) >> 6;
        }
        return $out;
    }

    /**
     * 4x4 IDCT整数逆变换
     * 变换顺序: 先行变换(混合列), 后列变换(混合行) + >>6
     * >>1截断不可交换, 顺序必须与Rust/FFmpeg一致
     */
    public function idct4x4(array $in): array
    {
        $coeffs = array_fill(0, 16, 0);
        for ($y = 0; $y < 4; $y++) for ($x = 0; $x < 4; $x++) $coeffs[$y * 4 + $x] = $in[$y][$x];

        $coeffs[0] = $coeffs[0] + 32;

        for ($i = 0; $i < 4; $i++) {
            $row = 4 * $i;
            $z0 = $coeffs[$row] + $coeffs[$row + 2];
            $z1 = $coeffs[$row] - $coeffs[$row + 2];
            $z2 = ($coeffs[$row + 1] >> 1) - $coeffs[$row + 3];
            $z3 = $coeffs[$row + 1] + ($coeffs[$row + 3] >> 1);

            $coeffs[$row] = $z0 + $z3;
            $coeffs[$row + 1] = $z1 + $z2;
            $coeffs[$row + 2] = $z1 - $z2;
            $coeffs[$row + 3] = $z0 - $z3;
        }

        $d = array_fill(0, 16, 0);
        for ($i = 0; $i < 4; $i++) {
            $z0 = $coeffs[$i] + $coeffs[$i + 8];
            $z1 = $coeffs[$i] - $coeffs[$i + 8];
            $z2 = ($coeffs[$i + 4] >> 1) - $coeffs[$i + 12];
            $z3 = $coeffs[$i + 4] + ($coeffs[$i + 12] >> 1);

            $d[$i] = ($z0 + $z3) >> 6;
            $d[$i + 4] = ($z1 + $z2) >> 6;
            $d[$i + 8] = ($z1 - $z2) >> 6;
            $d[$i + 12] = ($z0 - $z3) >> 6;
        }

        $out = array_fill(0, 4, array_fill(0, 4, 0));
        for ($y = 0; $y < 4; $y++) for ($x = 0; $x < 4; $x++) $out[$y][$x] = $d[$y * 4 + $x];
        return $out;
    }

    /**
     * 亮度DC 4x4逆哈达玛+反量化
     * 输入: raster顺序的16个DC系数
     * 输出: raster顺序的16个反量化DC值
     * 变换: 先Hadamard，再乘qmul，最后 >> 8
     */
    public function descan4x4(array $scanOrder, int $maxCoef): array
    {
        $raster = array_fill(0, 16, 0);
        for ($scanPos = 0; $scanPos < $maxCoef; $scanPos++) {
            $raster[self::ZIGZAG_SCAN_4X4[$scanPos]] = $scanOrder[$scanPos];
        }
        return $raster;
    }

    public function lumaDcDequantIdct(array $dc4x4, int $qmul): array
    {
        $temp = array_fill(0, 16, 0);

        for ($i = 0; $i < 4; $i++) {
            $base = 4 * $i;
            $z0 = $dc4x4[$base] + $dc4x4[$base + 1];
            $z1 = $dc4x4[$base] - $dc4x4[$base + 1];
            $z2 = $dc4x4[$base + 2] - $dc4x4[$base + 3];
            $z3 = $dc4x4[$base + 2] + $dc4x4[$base + 3];

            $temp[$base] = $z0 + $z3;
            $temp[$base + 1] = $z0 - $z3;
            $temp[$base + 2] = $z1 - $z2;
            $temp[$base + 3] = $z1 + $z2;
        }

        $out = array_fill(0, 16, 0);
        for ($j = 0; $j < 4; $j++) {
            $z0 = $temp[$j] + $temp[8 + $j];
            $z1 = $temp[$j] - $temp[8 + $j];
            $z2 = $temp[4 + $j] - $temp[12 + $j];
            $z3 = $temp[4 + $j] + $temp[12 + $j];

            $s0 = ($z0 + $z3) * $qmul + 128;
            $s1 = ($z1 + $z2) * $qmul + 128;
            $s2 = ($z1 - $z2) * $qmul + 128;
            $s3 = ($z0 - $z3) * $qmul + 128;
            $out[0 * 4 + $j] = ($s0 >= 0) ? ($s0 >> 8) : -((abs($s0)) >> 8);
            $out[1 * 4 + $j] = ($s1 >= 0) ? ($s1 >> 8) : -((abs($s1)) >> 8);
            $out[2 * 4 + $j] = ($s2 >= 0) ? ($s2 >> 8) : -((abs($s2)) >> 8);
            $out[3 * 4 + $j] = ($s3 >= 0) ? ($s3 >> 8) : -((abs($s3)) >> 8);
        }
        return $out;
    }

    /**
     * 色度DC 2x2逆哈达玛+反量化
     * output[i] = (hadamard_result * qmul) >> 7
     */
    public function chromaDcDequantIdct(array $dc2x2, int $qmul): array
    {
        $a = $dc2x2[0];
        $b = $dc2x2[1];
        $c = $dc2x2[2];
        $d = $dc2x2[3];

        $e = $a - $b;
        $a = $a + $b;
        $b = $c - $d;
        $c = $c + $d;

        // 没有像亮度DC那样的+128舍入偏置
        // 使用intdiv确保负数算术右移
        $out = array_fill(0, 4, 0);
        $out[0] = (($a + $c) * $qmul) >> 7;
        $out[1] = (($e + $b) * $qmul) >> 7;
        $out[2] = (($a - $c) * $qmul) >> 7;
        $out[3] = (($e - $b) * $qmul) >> 7;
        return $out;
    }
}
