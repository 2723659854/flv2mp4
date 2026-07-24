<?php

namespace Xiaosongshu\Flv2mp4\Codec\Encoder;

trait TransformEncodingTrait
{
    public function initQuantMatrix(): void
        {
            $this->quantMatrix[0] = [6, 13, 20, 28, 13, 20, 28, 32, 20, 28, 32, 37, 28, 32, 37, 42];
            $this->quantMatrix[1] = [6, 13, 20, 28, 13, 20, 28, 32, 20, 28, 32, 37, 28, 32, 37, 42];
    
            // 构建反量化表（与解码器一致，使用flat scaling matrix=16）
            $posClass = [0, 1, 0, 1, 1, 2, 1, 2, 0, 1, 0, 1, 1, 2, 1, 2];
            $flatScaling4 = array_fill(0, 16, 16);
            $this->dequant4Table = array_fill(0, 6, array_fill(0, 52, array_fill(0, 16, 0)));
            for ($i = 0; $i < 6; $i++) {
                for ($q = 0; $q < 52; $q++) {
                    $shift = intdiv($q, 6) + 2;
                    $idx = $q % 6;
                    for ($x = 0; $x < 16; $x++) {
                        $scaleIdx = $posClass[$x];
                        $this->dequant4Table[$i][$q][$x] =
                            (self::DEQUANT4_COEFF_INIT[$idx][$scaleIdx] * $flatScaling4[$x]) << $shift;
                    }
                }
            }
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
