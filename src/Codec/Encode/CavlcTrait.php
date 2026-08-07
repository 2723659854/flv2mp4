<?php

namespace Xiaosongshu\Flv2mp4\Codec\Encode;

/**
 * @purpose CAVLC编码特征/模块 熵编码模块
 */
trait CavlcTrait
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
        $result = min($avgNz, 16);
        if (isset($GLOBALS['debugNc']) && $GLOBALS['debugNc'] && $blockIdx >= 16) {
            echo "    ENCODER computeNC(blockIdx={$blockIdx}, mbX={$mbX}, bx={$bx}, by={$by}): predNz={$predNz}, count={$count}, result={$result}\n";
        }
        return $result;
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

        for ($i = $trailingOnes; $i < $totalCoeffs; $i++) {
            $val = $level[$i];

            $levelCode = ($val - 1) * 2;
            $sign = $levelCode >> 31;
            $levelCode = ($levelCode ^ $sign) + ($sign << 1);
            $levelCode -= (($i == $trailingOnes) && ($trailingOnes < 3)) << 1;

            $levelPrefix = $levelCode >> $suffixLength;
            $levelSuffixSize = $suffixLength;
            $levelSuffix = $levelCode - ($levelPrefix << $suffixLength);

            if ($levelPrefix >= 14 && $levelPrefix < 30 && $suffixLength == 0) {
                $levelPrefix = 14;
                $levelSuffix = $levelCode - $levelPrefix;
                $levelSuffixSize = 4;
            } else if ($levelPrefix >= 15) {
                $levelPrefix = 15;
                $levelSuffix = $levelCode - ($levelPrefix << $suffixLength);
                if ($suffixLength == 0) {
                    $levelSuffix -= 15;
                }
                $levelSuffixSize = 12;
            }

            $n = $levelPrefix + 1 + $levelSuffixSize;
            $value = ((1 << $levelSuffixSize) | $levelSuffix);
            $bits .= $this->u($value, $n);

            $suffixLength += ($suffixLength == 0) ? 1 : 0;
            $threshold = 3 << ($suffixLength - 1);
            if (($val > $threshold || $val < -$threshold) && $suffixLength < 6) {
                $suffixLength++;
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
}
