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
     * 4x4反量化 (H.264 标准 8.5.10.2)
     * |level_dec| = (|level| × m[qp%6][scale] × sm[pos] + f) >> (qp/6 + 2)
     * f = 1 << (qp/6 + 1)
     * dequant4Table 只存 m × sm（不含 shift），在此函数里正确做 shift
     */
    public function dequantize4x4(array $coeff, int $type, int $qp): array
    {
        $out = array_fill(0, 16, 0);
        $qp = max(0, min(51, $qp));
        $listIdx = $type;
        $shift = intdiv($qp, 6) + 2;
        $f = 1 << ($shift - 1);
        $tab = $this->dequant4Table[$listIdx][$qp];
        for ($i = 0; $i < 16; $i++) {
            $c = $coeff[$i];
            if ($c == 0) continue;
            $mul = $tab[$i];
            $absDec = (abs($c) * $mul + $f) >> $shift;
            $out[$i] = $c < 0 ? -$absDec : $absDec;
        }
        return $out;
    }

    /**
     * 热路径融合：CAVLC输出的16个zigzag系数 → raster顺序反量化，一步完成。
     * 按 H.264 标准反量化公式做 shift。
     */
    public function dequantZigzag4x4(array $coeff, int $listIdx, int $qp): array
    {
        $out = array_fill(0, 16, 0);
        $qp = max(0, min(51, $qp));
        $shift = intdiv($qp, 6) + 2;
        $f = 1 << ($shift - 1);
        $tab = $this->dequant4Table[$listIdx][$qp];
        for ($z = 0; $z < 16; $z++) {
            $c = $coeff[$z];
            if ($c != 0) {
                $r = self::ZIGZAG_SCAN_4X4[$z];
                $mul = $tab[$r];
                $absDec = (abs($c) * $mul + $f) >> $shift;
                $out[$r] = $c < 0 ? -$absDec : $absDec;
            }
        }
        return $out;
    }

    /**
     * 热路径融合（AC专用）：CAVLC输出的15个AC系数（对应zigzag位置1..15）
     * → raster顺序反量化，位置0留空给DC后续填入。
     * 按 H.264 标准反量化公式做 shift。
     */
    public function dequantAcZigzag4x4(array $ac15, int $listIdx, int $qp): array
    {
        $out = array_fill(0, 16, 0);
        $qp = max(0, min(51, $qp));
        $shift = intdiv($qp, 6) + 2;
        $f = 1 << ($shift - 1);
        $tab = $this->dequant4Table[$listIdx][$qp];
        for ($z = 1; $z < 16; $z++) {
            $c = $ac15[$z - 1];
            if ($c != 0) {
                $r = self::ZIGZAG_SCAN_4X4[$z];
                $mul = $tab[$r];
                $absDec = (abs($c) * $mul + $f) >> $shift;
                $out[$r] = $c < 0 ? -$absDec : $absDec;
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
     * 4x4 IDCT整数逆变换 (H.264 标准)
     * 先列变换，后行变换（与 H.264 标准一致）
     * 自动做 rounding + shift: residual = (纯IDCT输出 + offset) >> shift_inv
     * shift_inv = max(0, 8 - qp/6), offset = 1 << (shift_inv - 1)
     */
    public function idct4x4Flat(array $in, int $qp = 24): array
    {
        $block = $in;

        // 第一遍：列变换 (i 是列索引)
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

        // 第二遍：行变换
        for ($i = 0; $i < 4; $i++) {
            $row = 4 * $i;
            $z0 = $block[$row + 0] + $block[$row + 2];
            $z1 = $block[$row + 0] - $block[$row + 2];
            $z2 = ($block[$row + 1] >> 1) - $block[$row + 3];
            $z3 = $block[$row + 1] + ($block[$row + 3] >> 1);

            $block[$row + 0] = $z0 + $z3;
            $block[$row + 1] = $z1 + $z2;
            $block[$row + 2] = $z1 - $z2;
            $block[$row + 3] = $z0 - $z3;
        }

        // H.264 标准 IDCT 后 shift: shift_inv = max(0, 8 - qp/6)
        $shiftInv = max(0, 8 - intdiv($qp, 6));
        if ($shiftInv > 0) {
            $offset = 1 << ($shiftInv - 1);
            for ($i = 0; $i < 16; $i++) {
                $block[$i] = ($block[$i] + $offset) >> $shiftInv;
            }
        }

        return $block;
    }

    /**
     * 亮度DC 4x4逆哈达玛+反量化 (H.264 标准 I16x16 luma DC)
     * 先做 4×4 逆哈达玛，再按 H.264 标准做反量化
     * 输出是反量化后的 level_dec 值（raster 顺序的 16 个 DC 值）
     * 后续跟 AC 系数一起做 idct4x4Flat（包含 shift_inv）
     *
     * @param array $dc4x4 raster 顺序的 16 个 DC level 值
     * @param int $qp 当前 QP
     * @return array 反量化后的 16 个 DC level_dec 值
     */
    public function lumaDcDequantIdct(array $dc4x4, int $qp): array
    {
        // 1. 4×4 逆哈达玛 (H^T = H，所以也是正 Hadamard)
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

        $hadamardOut = array_fill(0, 16, 0);
        for ($j = 0; $j < 4; $j++) {
            $z0 = $temp[$j] + $temp[8 + $j];
            $z1 = $temp[$j] - $temp[8 + $j];
            $z2 = $temp[4 + $j] - $temp[12 + $j];
            $z3 = $temp[4 + $j] + $temp[12 + $j];

            $hadamardOut[0 * 4 + $j] = $z0 + $z3;
            $hadamardOut[1 * 4 + $j] = $z1 + $z2;
            $hadamardOut[2 * 4 + $j] = $z1 - $z2;
            $hadamardOut[3 * 4 + $j] = $z0 - $z3;
        }

        // 2. 标准反量化 (跟普通 luma AC 一样)
        $qp = max(0, min(51, $qp));
        $shift = intdiv($qp, 6) + 2;
        $f = 1 << ($shift - 1);
        $tab = $this->dequant4Table[0][$qp]; // listIdx=0 = luma
        $out = array_fill(0, 16, 0);
        for ($i = 0; $i < 16; $i++) {
            $h = $hadamardOut[$i];
            if ($h == 0) continue;
            $mul = $tab[$i];
            $absDec = (abs($h) * $mul + $f) >> $shift;
            $out[$i] = $h < 0 ? -$absDec : $absDec;
        }
        return $out;
    }

    /**
     * 色度DC 2x2逆哈达玛+反量化 (H.264 标准 chroma DC)
     * 先做 2×2 逆哈达玛，再按 H.264 标准做反量化
     * 输出是反量化后的 level_dec 值（4 个 DC 值）
     * 后续可能跟 AC 一起做 idct4x4Flat，或单独加到像素上
     *
     * @param array $dc2x2 4 个 chroma DC level 值
     * @param int $qp 当前 QP（chromaQp）
     * @param int $listIdx 1=Cb, 2=Cr
     * @return array 反量化后的 4 个 DC level_dec 值
     */
    public function chromaDcDequantIdct(array $dc2x2, int $qp, int $listIdx): array
    {
        // 1. 2×2 逆哈达玛
        $a = $dc2x2[0];
        $b = $dc2x2[1];
        $c = $dc2x2[2];
        $d = $dc2x2[3];

        $e = $a - $b;
        $a = $a + $b;
        $b = $c - $d;
        $c = $c + $d;

        $hadamardOut = [$a + $c, $e + $b, $a - $c, $e - $b];

        // 2. 标准反量化 (chroma DC 位置 scaleIdx=0)
        $qp = max(0, min(51, $qp));
        $shift = intdiv($qp, 6) + 2;
        $f = 1 << ($shift - 1);
        $tab = $this->dequant4Table[$listIdx][$qp];
        $out = array_fill(0, 4, 0);
        for ($i = 0; $i < 4; $i++) {
            $h = $hadamardOut[$i];
            if ($h == 0) continue;
            $mul = $tab[$i]; // chroma DC 在 2×2 块里，对应 4×4 的位置
            $absDec = (abs($h) * $mul + $f) >> $shift;
            $out[$i] = $h < 0 ? -$absDec : $absDec;
        }
        return $out;
    }
}
