<?php

namespace Xiaosongshu\Flv2mp4\Codec\Encoder;

trait CavlcEncodingTrait
{
    public function computeNC($blockIdx, $mbX, $bx, $by, $leftAvailable, $leftNz, $topAvailable, $topNz, $nzCache)
        {
            if ($blockIdx === -1) {
                $predNz = 0;
                $count = 0;
                if ($leftAvailable) {
                    $predNz += $leftNz[0];
                    $count++;
                }
                if ($topAvailable) {
                    $ax = $mbX * 4;
                    if ($ax < count($topNz)) {
                        $predNz += $topNz[$ax];
                        $count++;
                    }
                }
                if ($count === 0) return 0;
                $avgNz = intdiv($predNz + intdiv($count, 2), $count);
                return min($avgNz, 16);
            }
    
            $predNz = 0;
            $count = 0;
    
            if ($blockIdx < 16) {
                if ($bx > 0) {
                    $predNz += $nzCache[$blockIdx - 1];
                    $count++;
                } elseif ($leftAvailable) {
                    $predNz += $leftNz[$by];
                    $count++;
                }
                if ($by > 0) {
                    $predNz += $nzCache[$blockIdx - 4];
                    $count++;
                } elseif ($topAvailable) {
                    $ax = $mbX * 4 + $bx;
                    if ($ax < count($topNz)) {
                        $predNz += $topNz[$ax];
                        $count++;
                    }
                }
            } else {
                $lnOff = $blockIdx < 20 ? 4 : 6;
                if ($bx > 0) {
                    $predNz += $nzCache[$blockIdx - 1];
                    $count++;
                } elseif ($leftAvailable) {
                    $predNz += $leftNz[$lnOff + $by];
                    $count++;
                }
                if ($by > 0) {
                    $predNz += $nzCache[$blockIdx - 2];
                    $count++;
                } elseif ($topAvailable) {
                    $ax = $mbX * 2 + $bx;
                    if ($ax < count($topNz)) {
                        $predNz += $topNz[$ax];
                        $count++;
                    }
                }
            }
    
            $avgNz = $count > 0 ? intdiv($predNz + intdiv($count, 2), $count) : 0;
            return min($avgNz, 16);
        }

    public function writeBlockResidualCavlc(array $coeffs, int $endIdx, bool $isChromaDc, int $iNC): string
        {
    
            $bits = '';
            
            $iLastIndex = $endIdx;
            while ($iLastIndex >= 0 && $coeffs[$iLastIndex] == 0) {
                $iLastIndex--;
            }
    
            if ($iLastIndex < 0) {
                if ($isChromaDc) {
                    $ncIdx = 4;
                } else {
                    $ncIdx = self::ENC_NC_MAP_TABLE[$iNC];
                }
                $coeffToken = self::VLC_COEFF_TOKEN[$ncIdx][0][0];
                $value = $coeffToken[0];
                $n = $coeffToken[1];
                $bits .= $this->u($value, $n);
                return $bits;
            }
    
            $totalZeros = 0;
            $totalCoeffs = 0;
            
            $level = [];
            $run = [];
            
            $iLastIndex = $endIdx;
            while ($iLastIndex >= 0 && $coeffs[$iLastIndex] == 0) {
                $iLastIndex--;
            }
            
            while ($iLastIndex >= 0) {
                $countZero = 0;
                $level[$totalCoeffs] = $coeffs[$iLastIndex--];
                
                while ($iLastIndex >= 0 && $coeffs[$iLastIndex] == 0) {
                    $countZero++;
                    $iLastIndex--;
                }
                $totalZeros += $countZero;
                $run[$totalCoeffs++] = $countZero;
            }
            
            $trailingOnes = 0;
            $sign = 0;
            $count = ($totalCoeffs > 3) ? 3 : $totalCoeffs;
            for ($i = 0; $i < $count; $i++) {
                if (abs($level[$i]) == 1) {
                    $trailingOnes++;
                    $sign <<= 1;
                    if ($level[$i] < 0) {
                        $sign |= 1;
                    }
                } else {
                    break;
                }
            }
    
            if ($isChromaDc) {
                $ncIdx = 4;
            } else {
                $ncIdx = self::ENC_NC_MAP_TABLE[$iNC];
            }
    
            $coeffToken = self::VLC_COEFF_TOKEN[$ncIdx][$totalCoeffs][$trailingOnes];
            $value = $coeffToken[0];
            $n = $coeffToken[1];
            $n += $trailingOnes;
            $value = ($value << $trailingOnes) | $sign;
            $bits .= $this->u($value, $n);
    
            $suffixLength = ($totalCoeffs > 10 && $trailingOnes < 3) ? 1 : 0;
    
            $suffixLimit = [0, 3, 6, 12, 24, 48, PHP_INT_MAX];
    
            for ($i = $trailingOnes; $i < $totalCoeffs; $i++) {
                $val = $level[$i];
                $absVal = abs($val);
                $isFirst = ($i == $trailingOnes);
    
                if ($val > 0) {
                    $levelCode = 2 * ($val - 1);
                } else {
                    $levelCode = 2 * (-$val) - 1;
                }
                if ($isFirst && ($trailingOnes < 3) && ($absVal > 1)) {
                    $levelCode -= 2;
                }
                if ($levelCode < 0) {
                    $levelCode = 0;
                }
    
                if ($isFirst && $suffixLength === 0) {
                    if ($levelCode < 14) {
                        $levelPrefix = $levelCode;
                        $levelSuffix = 0;
                        $levelSuffixSize = 0;
                    } elseif ($levelCode < 30) {
                        $levelPrefix = 14;
                        $levelSuffix = $levelCode - 14;
                        $levelSuffixSize = 4;
                    } else {
                        $remaining = $levelCode - 30;
                        $pre = 15;
                        while (true) {
                            $suffixBits = $pre - 3;
                            $maxSuffixVal = (1 << $suffixBits) - 1;
                            if ($remaining <= $maxSuffixVal) {
                                break;
                            }
                            $remaining -= ($maxSuffixVal + 1);
                            $pre++;
                            if ($pre > 32) break;
                        }
                        $levelPrefix = $pre;
                        $levelSuffix = $remaining;
                        $levelSuffixSize = $pre - 3;
                    }
                } else {
                    $levelPrefix = $levelCode >> $suffixLength;
                    $levelSuffixSize = $suffixLength;
                    $levelSuffix = $levelCode - ($levelPrefix << $suffixLength);
    
                    if ($levelPrefix >= 15) {
                        $baseCode = 15 * (1 << $suffixLength);
                        $remaining = $levelCode - $baseCode;
                        $pre = 15;
                        while (true) {
                            $suffixBits = $pre - 3;
                            $maxSuffixVal = (1 << $suffixBits) - 1;
                            if ($remaining <= $maxSuffixVal) {
                                break;
                            }
                            $remaining -= ($maxSuffixVal + 1);
                            $pre++;
                            if ($pre > 32) break;
                        }
                        $levelPrefix = $pre;
                        $levelSuffix = $remaining;
                        $levelSuffixSize = $pre - 3;
                    }
                }
    
                $n = $levelPrefix + 1 + $levelSuffixSize;
                $value = ((1 << $levelSuffixSize) | $levelSuffix);
                $bits .= $this->u($value, $n);
    
                if ($isFirst) {
                    $suffixLength = ($absVal > 3) ? 2 : 1;
                } else {
                    if ($suffixLength < 6 && $absVal > $suffixLimit[$suffixLength]) {
                        $suffixLength++;
                    }
                }
            }
    
            if ($totalCoeffs < $endIdx + 1) {
                if (!$isChromaDc) {
                    $totalZerosEntry = self::VLC_TOTAL_ZEROS[$totalCoeffs][$totalZeros];
                    $n = $totalZerosEntry[1];
                    $value = $totalZerosEntry[0];
                    $bits .= $this->u($value, $n);
                } else {
                    if ($totalCoeffs < 4) {
                        $totalZerosEntry = self::VLC_TOTAL_ZEROS_CHROMA_DC[$totalCoeffs][$totalZeros];
                        $n = $totalZerosEntry[1];
                        $value = $totalZerosEntry[0];
                        $bits .= $this->u($value, $n);
                    } else {
                        $bits .= $this->ue($totalZeros);
                    }
                }
            }
    
            $zerosLeft = $totalZeros;
            for ($i = 0; $i + 1 < $totalCoeffs && $zerosLeft > 0; $i++) {
                $uirun = $run[$i];
                $zeroLeft = self::ZERO_LEFT_MAP[$zerosLeft];
                $runBeforeEntry = self::VLC_RUN_BEFORE[$zeroLeft][$uirun];
                $n = $runBeforeEntry[1];
                $value = $runBeforeEntry[0];
                $bits .= $this->u($value, $n);
                $zerosLeft -= $uirun;
            }
    
            return $bits;
        }

    public function scan4x4DcAc(array $raster): array
        {
            $out = array_fill(0, 16, 0);
            for ($i = 0; $i < 16; $i++) {
                $out[$i] = $raster[self::ZIGZAG_SCAN_4X4[$i]];
            }
            return $out;
        }

    public function scan4x4Ac(array $raster): array
        {
            $out = array_fill(0, 15, 0);
            for ($i = 0; $i < 15; $i++) {
                $out[$i] = $raster[self::ZIGZAG_SCAN_4X4[$i + 1]];
            }
            return $out;
        }
}
