<?php

namespace Xiaosongshu\Flv2mp4\Codec\Encode;

/**
 * @purpose 变换与量化
 * @author yanglong
 */
trait TransformTrait
{
    /**
     * 4x4系数扫描
     * 将raster顺序的16个系数转换为zigzag扫描顺序
     * 用于I16x16 DC系数和I4x4全部系数
     */
    public function scan4x4DcAc(array $raster): array
    {
        $out = array_fill(0, 16, 0);
        for ($i = 0; $i < 16; $i++) {
            $out[$i] = $raster[self::ZIGZAG_SCAN_4X4[$i]];
        }
        return $out;
    }

    /**
     * 4x4 AC系数扫描
     * 跳过DC(位置0),将raster顺序的AC系数(位置1-15)转换为zigzag扫描顺序
     * 输出15个AC系数,用于I16x16 AC和chroma AC
     */
    public function scan4x4Ac(array $raster): array
    {
        $out = array_fill(0, 15, 0);
        for ($i = 0; $i < 15; $i++) {
            $out[$i] = $raster[self::ZIGZAG_SCAN_4X4[$i + 1]];
        }
        return $out;
    }

    public function dct(array $block): array
    {
        $pDct = array_fill(0, 16, 0);
        for ($y = 0; $y < 4; $y++) for ($x = 0; $x < 4; $x++) $pDct[$y * 4 + $x] = $block[$y][$x];

        for ($i = 0; $i < 16; $i += 4) {
            $kiI1 = 1 + $i;
            $kiI2 = 2 + $i;
            $kiI3 = 3 + $i;

            $s03 = $pDct[$i] + $pDct[$kiI3];
            $s12 = $pDct[$kiI1] + $pDct[$kiI2];
            $d03 = $pDct[$i] - $pDct[$kiI3];
            $d12 = $pDct[$kiI1] - $pDct[$kiI2];

            $pDct[$i] = $s03 + $s12;
            $pDct[$kiI2] = $s03 - $s12;
            $pDct[$kiI1] = 2 * $d03 + $d12;
            $pDct[$kiI3] = $d03 - 2 * $d12;
        }

        for ($i = 0; $i < 4; $i++) {
            $kiI4 = 4 + $i;
            $kiI8 = 8 + $i;
            $kiI12 = 12 + $i;

            $s03 = $pDct[$i] + $pDct[$kiI12];
            $s12 = $pDct[$kiI4] + $pDct[$kiI8];
            $d03 = $pDct[$i] - $pDct[$kiI12];
            $d12 = $pDct[$kiI4] - $pDct[$kiI8];

            $pDct[$i] = $s03 + $s12;
            $pDct[$kiI8] = $s03 - $s12;
            $pDct[$kiI4] = 2 * $d03 + $d12;
            $pDct[$kiI12] = $d03 - 2 * $d12;
        }

        $result = array_fill(0, 4, array_fill(0, 4, 0));
        for ($y = 0; $y < 4; $y++) for ($x = 0; $x < 4; $x++) $result[$y][$x] = $pDct[$y * 4 + $x];
        return $result;
    }

    public function hadamardTransformDC(array $b): array
    {
        $d = array_fill(0, 16, 0);
        for ($y = 0; $y < 4; $y++) for ($x = 0; $x < 4; $x++) $d[$y * 4 + $x] = $b[$y][$x];

        $tmp = array_fill(0, 16, 0);
        for ($i = 0; $i < 4; $i++) {
            $s01 = $d[$i * 4 + 0] + $d[$i * 4 + 1];
            $d01 = $d[$i * 4 + 0] - $d[$i * 4 + 1];
            $s23 = $d[$i * 4 + 2] + $d[$i * 4 + 3];
            $d23 = $d[$i * 4 + 2] - $d[$i * 4 + 3];
            $tmp[$i * 4 + 0] = $s01 + $s23;
            $tmp[$i * 4 + 1] = $s01 - $s23;
            $tmp[$i * 4 + 2] = $d01 - $d23;
            $tmp[$i * 4 + 3] = $d01 + $d23;
        }

        $res = array_fill(0, 4, array_fill(0, 4, 0));
        for ($j = 0; $j < 4; $j++) {
            $s01 = $tmp[0 * 4 + $j] + $tmp[1 * 4 + $j];
            $d01 = $tmp[0 * 4 + $j] - $tmp[1 * 4 + $j];
            $s23 = $tmp[2 * 4 + $j] + $tmp[3 * 4 + $j];
            $d23 = $tmp[2 * 4 + $j] - $tmp[3 * 4 + $j];
            $res[0][$j] = (int)(($s01 + $s23 + 1) >> 1);
            $res[1][$j] = (int)(($s01 - $s23 + 1) >> 1);
            $res[2][$j] = (int)(($d01 - $d23 + 1) >> 1);
            $res[3][$j] = (int)(($d01 + $d23 + 1) >> 1);
        }
        return $res;
    }

    /**
     * 2x2 chroma DC Hadamard变换
     * 不做缩放,输出原始Hadamard系数
     */
    public function forwardChromaHadamard2x2(array $c): array
    {
        $a = $c[0];
        $b = $c[1];
        $cc = $c[2];
        $d = $c[3];
        $e = $a - $b;
        $a = $a + $b;
        $b = $cc - $d;
        $cc = $cc + $d;
        return [$a + $cc, $e + $b, $a - $cc, $e - $b];
    }

    /**
     * 4x4 AC系数量化
     * 公式: level = sign(coeff) * abs(((FF[j] + |coeff|) * MF[j]) >> 16)
     * Intra FF索引 = QP + 6, Inter FF索引 = QP
     */
    public function quantize(array $block, int $isChroma, bool $isInter = false): array
    {
        $qp = $this->qp;
        $mf = self::QUANT_MF[$qp];
        $ffIdx = $isInter ? $qp : $qp + 6;
        $ff = self::QUANT_INTER_FF[$ffIdx];
        $out = array_fill(0, 4, array_fill(0, 4, 0));
        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                $i = $y * 4 + $x;
                $j = $i & 7;
                $val = $block[$y][$x];
                $absVal = abs($val);
                $absQuant = (($ff[$j] + $absVal) * $mf[$j]) >> 16;
                $out[$y][$x] = $val >= 0 ? $absQuant : -$absQuant;
            }
        }
        return $out;
    }

    /**
     * 4x4 chroma AC系数量化
     */
    public function quantizeChroma(array $block, int $qp): array
    {
        $mf = self::QUANT_MF[$qp];
        $ff = self::QUANT_INTER_FF[$qp + 6];
        $out = array_fill(0, 4, array_fill(0, 4, 0));
        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                $i = $y * 4 + $x;
                $j = $i & 7;
                $val = $block[$y][$x];
                $absVal = abs($val);
                $absQuant = (($ff[$j] + $absVal) * $mf[$j]) >> 16;
                $out[$y][$x] = $val >= 0 ? $absQuant : -$absQuant;
            }
        }
        return $out;
    }

    /**
     * 4x4 luma DC量化
     * DC使用: iFF = pFF[0] << 1, iMF = pMF[0] >> 1
     * 公式: level = sign(coeff) * abs(((iFF + |coeff|) * iMF) >> 16)
     */
    public function quantizeDCMatrix(array $b, int $qp): array
    {
        $mf0 = self::QUANT_MF[$qp][0];
        $ff0 = self::QUANT_INTER_FF[$qp + 6][0];
        $iFF = $ff0 << 1;
        $iMF = $mf0 >> 1;
        $out = array_fill(0, 4, array_fill(0, 4, 0));
        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                $val = $b[$y][$x];
                $absVal = abs($val);
                $absQuant = (($iFF + $absVal) * $iMF) >> 16;
                $out[$y][$x] = $val >= 0 ? $absQuant : -$absQuant;
            }
        }
        return $out;
    }

    /**
     * 2x2 chroma DC量化
     * DC使用: iFF = pFF[0] << 1, iMF = pMF[0] >> 1
     */
    public function quantizeChromaDC(array $coeffs, int $chromaQp): array
    {
        $mf0 = self::QUANT_MF[$chromaQp][0];
        $ff0 = self::QUANT_INTER_FF[$chromaQp + 6][0];
        $iFF = $ff0 << 1;
        $iMF = $mf0 >> 1;
        $output = [];
        foreach ($coeffs as $val) {
            $absVal = abs($val);
            $absQuant = (($iFF + $absVal) * $iMF) >> 16;
            $output[] = $val >= 0 ? $absQuant : -$absQuant;
        }
        return $output;
    }

    /**
     * 4x4反量化（用于本地解码重建参考帧）
     * 公式: (level * table[qp][pos] + 32) >> 6
     */
    public function dequantize4x4(array $coeff, int $type, int $qp): array
    {
        $out = array_fill(0, 16, 0);
        $qp = max(0, min(51, $qp));
        $listIdx = $type;
        for ($i = 0; $i < 16; $i++) {
            if ($coeff[$i] == 0) continue;
            $out[$i] = ($coeff[$i] * $this->dequant4Table[$listIdx][$qp][$i] + 32) >> 6;
        }
        return $out;
    }

    /**
     * 4x4 IDCT整数逆变换（与解码器一致）
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
     */
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

        $out = array_fill(0, 4, 0);
        $out[0] = (($a + $c) * $qmul) >> 7;
        $out[1] = (($e + $b) * $qmul) >> 7;
        $out[2] = (($a - $c) * $qmul) >> 7;
        $out[3] = (($e - $b) * $qmul) >> 7;
        return $out;
    }
}
