<?php

namespace Xiaosongshu\Flv2mp4\Codec\Decode;

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
     * 热路径融合：CAVLC输出的16个zigzag系数 → raster顺序反量化，一步完成。
     * 替代 copy16 + zigzagToRaster + dequantize4x4（每块省2次数组分配与32次循环）。
     * 数值与原三步完全一致：反量化乘数按raster位置查表。
     */
    public function dequantZigzag4x4(array $coeff, int $listIdx, int $qp): array
    {
        $out = array_fill(0, 16, 0);
        $qp = max(0, min(51, $qp));
        $tab = $this->dequant4Table[$listIdx][$qp];
        for ($z = 0; $z < 16; $z++) {
            $c = $coeff[$z];
            if ($c != 0) {
                $r = self::ZIGZAG_SCAN_4X4[$z];
                $out[$r] = ($c * $tab[$r] + 32) >> 6;
            }
        }
        return $out;
    }

    /**
     * 热路径融合（AC专用）：CAVLC输出的15个AC系数（对应zigzag位置1..15）
     * → raster顺序反量化，位置0留空给DC后续填入。
     */
    public function dequantAcZigzag4x4(array $ac15, int $listIdx, int $qp): array
    {
        $out = array_fill(0, 16, 0);
        $qp = max(0, min(51, $qp));
        $tab = $this->dequant4Table[$listIdx][$qp];
        for ($z = 1; $z < 16; $z++) {
            $c = $ac15[$z - 1];
            if ($c != 0) {
                $r = self::ZIGZAG_SCAN_4X4[$z];
                $out[$r] = ($c * $tab[$r] + 32) >> 6;
            }
        }
        return $out;
    }

    /**
     * 4x4 IDCT整数逆变换
     * 先列变换，后行变换，中间>>1不可交换顺序
     */
    public function idct4x4(array $in): array
    {
        $block = array_fill(0, 16, 0);
        for ($y = 0; $y < 4; $y++) for ($x = 0; $x < 4; $x++)
            $block[$y * 4 + $x] = $in[$y][$x];

        $flat = $this->idct4x4Flat($block);
        $out = array_fill(0, 4, array_fill(0, 4, 0));
        for ($y = 0; $y < 4; $y++) for ($x = 0; $x < 4; $x++)
            $out[$y][$x] = $flat[$y * 4 + $x];
        return $out;
    }

    /**
     * 4x4 IDCT整数逆变换（输入输出均为raster顺序的16个系数）
     */
    public function idct4x4Flat(array $in): array
    {
        $block = $in;
        $block[0] += 32;

        // 第一遍：列变换 (i是列索引)
        for ($i = 0; $i < 4; $i++) {
            $z0 = $block[$i + 0] + $block[$i + 8];
            $z1 = $block[$i + 0] - $block[$i + 8];
            $z2 = ($block[$i + 4] >> 1) - $block[$i + 12];
            $z3 = $block[$i + 4] + ($block[$i + 12] >> 1);

            $block[$i + 0] = $z0 + $z3;
            $block[$i + 4] = $z1 + $z2;
            $block[$i + 8] = $z1 - $z2;
            $block[$i + 12] = $z0 - $z3;
        }

        // 第二遍：行变换并>>6，原地写回（每行的4次读取先于该行4次写入，各行互不重叠）
        for ($i = 0; $i < 4; $i++) {
            $row = 4 * $i;
            $z0 = $block[$row + 0] + $block[$row + 2];
            $z1 = $block[$row + 0] - $block[$row + 2];
            $z2 = ($block[$row + 1] >> 1) - $block[$row + 3];
            $z3 = $block[$row + 1] + ($block[$row + 3] >> 1);

            $block[$row + 0] = ($z0 + $z3) >> 6;
            $block[$row + 1] = ($z1 + $z2) >> 6;
            $block[$row + 2] = ($z1 - $z2) >> 6;
            $block[$row + 3] = ($z0 - $z3) >> 6;
        }

        return $block;
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
            $out[0 * 4 + $j] = $s0 >> 8;
            $out[1 * 4 + $j] = $s1 >> 8;
            $out[2 * 4 + $j] = $s2 >> 8;
            $out[3 * 4 + $j] = $s3 >> 8;
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

    // ===== High profile 8x8 变换 =====

    /** dequant8_coeff_init。PHP 8.1 trait 不支持常量，用静态数组 */
    private static array $DEQUANT8_COEFF_INIT = [
        [20, 18, 32, 19, 25, 24],
        [22, 19, 35, 21, 28, 26],
        [26, 23, 42, 24, 33, 31],
        [28, 25, 45, 26, 35, 33],
        [32, 28, 51, 30, 40, 38],
        [36, 32, 58, 34, 46, 43],
    ];
    /** dequant8_coeff_init_scan */
    private static array $DEQUANT8_COEFF_SCAN = [0, 3, 4, 3, 3, 1, 5, 1, 4, 5, 2, 5, 3, 1, 5, 1];

    /**
     * 构造 8x8 块的反量化乘数表（raster 64 项，flat scaling=16）。
     * 对应 pps->dequant8_coeff[cqm][qp][j]，cqm: 帧内Y=0 帧间Y=3。
     */
    public function buildQmul8(int $qp, bool $intraLuma): array
    {
        $qp = max(0, min(51, $qp));
        $shift = intdiv($qp, 6);
        $idx = $qp % 6;
        $init = self::$DEQUANT8_COEFF_INIT[$idx];
        $scan = self::$DEQUANT8_COEFF_SCAN;
        $qmul = array_fill(0, 64, 0);
        for ($j = 0; $j < 64; $j++) {
            // j 为存储位置（转置位置），缩放表索引 x = transpose(j)
            $x = ($j >> 3) | (($j & 7) << 3);
            $scanIdx = (($x >> 1) & 12) | ($x & 3);
            $qmul[$j] = ($init[$scan[$scanIdx]] * 16) << $shift;
        }
        return $qmul;
    }

    /**
     * 8x8 整数反变换并叠加到像素平面（移植 idct8_add）。
     * @param array $block 64 个已反量化系数（raster），会被清零语义忽略
     */
    public function idct8AddToPlane(array &$plane, int $stride, int $x0, int $y0, array $block): void
    {
        $block[0] += 32;

        // 第一遍：列变换
        for ($i = 0; $i < 8; $i++) {
            $a0 = $block[$i + 0 * 8] + $block[$i + 4 * 8];
            $a2 = $block[$i + 0 * 8] - $block[$i + 4 * 8];
            $a4 = ($block[$i + 2 * 8] >> 1) - $block[$i + 6 * 8];
            $a6 = ($block[$i + 6 * 8] >> 1) + $block[$i + 2 * 8];

            $b0 = $a0 + $a6;
            $b2 = $a2 + $a4;
            $b4 = $a2 - $a4;
            $b6 = $a0 - $a6;

            $a1 = -$block[$i + 3 * 8] + $block[$i + 5 * 8] - $block[$i + 7 * 8] - ($block[$i + 7 * 8] >> 1);
            $a3 =  $block[$i + 1 * 8] + $block[$i + 7 * 8] - $block[$i + 3 * 8] - ($block[$i + 3 * 8] >> 1);
            $a5 = -$block[$i + 1 * 8] + $block[$i + 7 * 8] + $block[$i + 5 * 8] + ($block[$i + 5 * 8] >> 1);
            $a7 =  $block[$i + 3 * 8] + $block[$i + 5 * 8] + $block[$i + 1 * 8] + ($block[$i + 1 * 8] >> 1);

            $b1 = ($a7 >> 2) + $a1;
            $b3 = $a3 + ($a5 >> 2);
            $b5 = ($a3 >> 2) - $a5;
            $b7 = $a7 - ($a1 >> 2);

            $block[$i + 0 * 8] = $b0 + $b7;
            $block[$i + 7 * 8] = $b0 - $b7;
            $block[$i + 1 * 8] = $b2 + $b5;
            $block[$i + 6 * 8] = $b2 - $b5;
            $block[$i + 2 * 8] = $b4 + $b3;
            $block[$i + 5 * 8] = $b4 - $b3;
            $block[$i + 3 * 8] = $b6 + $b1;
            $block[$i + 4 * 8] = $b6 - $b1;
        }
        // 第二遍：行变换并 >>6 叠加。
        // 系数块是转置存储布局（scanJ = TRANSPOSE(zigzag)），idct8_add
        // 把存储行 i 的 8 个输出竖写进图像列 i（dst[i + k*stride]），
        // 这样转置布局与两遍蝶形的转置效应正好抵消，输出才是正确方向的像素。
        // 之前横写进行 y0+i 导致整个 8x8 残差块被转置（坏 MB 的 diff 呈反对称）。
        for ($i = 0; $i < 8; $i++) {
            $a0 = $block[0 + $i * 8] + $block[4 + $i * 8];
            $a2 = $block[0 + $i * 8] - $block[4 + $i * 8];
            $a4 = ($block[2 + $i * 8] >> 1) - $block[6 + $i * 8];
            $a6 = ($block[6 + $i * 8] >> 1) + $block[2 + $i * 8];

            $b0 = $a0 + $a6;
            $b2 = $a2 + $a4;
            $b4 = $a2 - $a4;
            $b6 = $a0 - $a6;

            $a1 = -$block[3 + $i * 8] + $block[5 + $i * 8] - $block[7 + $i * 8] - ($block[7 + $i * 8] >> 1);
            $a3 =  $block[1 + $i * 8] + $block[7 + $i * 8] - $block[3 + $i * 8] - ($block[3 + $i * 8] >> 1);
            $a5 = -$block[1 + $i * 8] + $block[7 + $i * 8] + $block[5 + $i * 8] + ($block[5 + $i * 8] >> 1);
            $a7 =  $block[3 + $i * 8] + $block[5 + $i * 8] + $block[1 + $i * 8] + ($block[1 + $i * 8] >> 1);

            $b1 = ($a7 >> 2) + $a1;
            $b3 = $a3 + ($a5 >> 2);
            $b5 = ($a3 >> 2) - $a5;
            $b7 = $a7 - ($a1 >> 2);

            $col = $x0 + $i;
            $v = ($b0 + $b7) >> 6; $base = ($y0 + 0) * $stride + $col; $v = $plane[$base] + $v; $plane[$base] = $v < 0 ? 0 : ($v > 255 ? 255 : $v);
            $v = ($b2 + $b5) >> 6; $base = ($y0 + 1) * $stride + $col; $v = $plane[$base] + $v; $plane[$base] = $v < 0 ? 0 : ($v > 255 ? 255 : $v);
            $v = ($b4 + $b3) >> 6; $base = ($y0 + 2) * $stride + $col; $v = $plane[$base] + $v; $plane[$base] = $v < 0 ? 0 : ($v > 255 ? 255 : $v);
            $v = ($b6 + $b1) >> 6; $base = ($y0 + 3) * $stride + $col; $v = $plane[$base] + $v; $plane[$base] = $v < 0 ? 0 : ($v > 255 ? 255 : $v);
            $v = ($b6 - $b1) >> 6; $base = ($y0 + 4) * $stride + $col; $v = $plane[$base] + $v; $plane[$base] = $v < 0 ? 0 : ($v > 255 ? 255 : $v);
            $v = ($b4 - $b3) >> 6; $base = ($y0 + 5) * $stride + $col; $v = $plane[$base] + $v; $plane[$base] = $v < 0 ? 0 : ($v > 255 ? 255 : $v);
            $v = ($b2 - $b5) >> 6; $base = ($y0 + 6) * $stride + $col; $v = $plane[$base] + $v; $plane[$base] = $v < 0 ? 0 : ($v > 255 ? 255 : $v);
            $v = ($b0 - $b7) >> 6; $base = ($y0 + 7) * $stride + $col; $v = $plane[$base] + $v; $plane[$base] = $v < 0 ? 0 : ($v > 255 ? 255 : $v);
        }
    }

    /**
     * 8x8 仅 DC 系数时叠加（idct8_dc_add），dc 为已反量化的 [0] 系数。
     */
    public function idct8DcAddToPlane(array &$plane, int $stride, int $x0, int $y0, int $dc): void
    {
        $v = ($dc + 32) >> 6;
        for ($j = 0; $j < 8; $j++) {
            $base = ($y0 + $j) * $stride + $x0;
            for ($i = 0; $i < 8; $i++) {
                $p = $plane[$base + $i] + $v;
                $plane[$base + $i] = $p < 0 ? 0 : ($p > 255 ? 255 : $p);
            }
        }
    }
}
