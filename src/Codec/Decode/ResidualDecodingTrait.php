<?php

namespace Xiaosongshu\Flv2mp4\Codec\Decode;

/**
 * @purpose 亮度色度残差解码
 * @author yanglong
 * @time 2026年7月23日15:18:35
 */
trait ResidualDecodingTrait
{
    /**
     * CAVLC读取DC系数
     * @param int $nC 对于亮度DC使用专用表，对于色度DC使用nC=-1
     * @param int $maxCoeff 16（亮度）或 4（色度）
     */
    public function readCoeffsCAVLC(int $nC, int $maxCoeff): array
    {
        $coeffs = array_fill(0, $maxCoeff, 0);

        $totalCoeff = 0;
        $trailingOnes = 0;
        $this->readCoeffToken($totalCoeff, $trailingOnes, $nC);

        $lastNz = $totalCoeff - 1;

        for ($i = 0; $i < $trailingOnes; $i++) {
            $sign = $this->reader->readU(1);
            $coeffs[$lastNz - $i] = $sign ? -1 : 1;
        }

        $suffixLen = ($totalCoeff > 10 && $trailingOnes < 3) ? 1 : 0;
        $rem = $totalCoeff - $trailingOnes;
        $revStart = $lastNz - $trailingOnes;
        // H.264 suffix_length 更新阈值表
        $suffixLimit = [0, 3, 6, 12, 24, 48, PHP_INT_MAX];

        for ($i = $revStart; $i >= 0 && $rem > 0; $i--) {
            $isFirst = ($rem === $totalCoeff - $trailingOnes);
            $pre = 0;
            // 防止死循环：最多读取32位前缀 (level_prefix = 连续0的个数)
            while ($pre < 32 && $this->reader->readU(1) === 0) $pre++;
            // 计算 level_code
            //$levelCode = 0;
            if ($isFirst && $suffixLen === 0) {
                // 第一个系数且 suffix_length==0 的特殊VLC结构
                if ($pre < 14) {
                    $levelCode = $pre;
                } elseif ($pre === 14) {
                    $suffix = $this->reader->readU(4);
                    $levelCode = 14 + $suffix;
                } else {
                    $lc = 30;
                    if ($pre >= 16) {
                        $lc += (1 << ($pre - 3)) - 4096;
                    }
                    $suffix = $this->reader->readU($pre - 3);
                    $levelCode = $lc + $suffix;
                }
            } else {
                // 后续系数 或 第一个且 suffix_length==1
                if ($pre < 15) {
                    $suffix = ($suffixLen > 0) ? $this->reader->readU($suffixLen) : 0;
                    $levelCode = $pre * (1 << $suffixLen) + $suffix;
                } else {
                    $lc = 15 * (1 << $suffixLen);
                    if ($pre >= 16) {
                        $lc += (1 << ($pre - 3)) - 4096;
                    }
                    $suffix = $this->reader->readU($pre - 3);
                    $levelCode = $lc + $suffix;
                }
            }
            // 对第一个 level 应用 trailing_ones<3 的 +2 偏移
            $adjustedCode = $levelCode;
            if ($isFirst && $trailingOnes < 3) $adjustedCode += 2;
            // 将 level_code 转换为带符号 level
            $mask = -($adjustedCode & 1);
            $level = (($adjustedCode + 2) >> 1) ^ $mask;
            $level = $level - $mask;
            $coeffs[$i] = $level;
            // 更新 suffix_length (参考 FFmpeg SUFFIX_LIMIT 表)
            $absLvl = abs($level);
            if ($isFirst) {
                // 第一个 level 之后: suffixLen = 1 + (|level| > 3)
                $suffixLen = ($absLvl > 3) ? 2 : 1;
            } else {
                if ($suffixLen < 6 && $absLvl > $suffixLimit[$suffixLen]) {
                    $suffixLen++;
                }
            }
            $rem--;
        }

        if ($totalCoeff < $maxCoeff) {
            $totalZeros = $this->readTotalZeros($totalCoeff, $maxCoeff);
            $zl = $totalZeros;
            $coeffIdx = $totalCoeff + $totalZeros - 1;

            $tmpCoeffs = array_fill(0, $maxCoeff, 0);
            $nzIdx = $lastNz;
            for ($i = 0; $i < $totalCoeff; $i++) {
                $isLast = ($i == $totalCoeff - 1);
                //$rb = 0;
                if (!$isLast && $zl > 0) {
                    $rb = $this->readRunBefore($zl);
                } elseif (!$isLast) {
                    $rb = 0;
                } else {
                    $rb = $zl;
                }

                $tmpCoeffs[$coeffIdx] = $coeffs[$nzIdx];

                $zl -= $rb;

                if (!$isLast) {
                    $coeffIdx -= ($rb + 1);
                    $nzIdx--;
                }
            }

            $coeffs = $tmpCoeffs;
        }

        return $coeffs;
    }

    public function readLumaDcCoeffs(): array
    {
        $coeffs = array_fill(0, 16, 0);

        $totalCoeff = 0;
        $trailingOnes = 0;

        $bits = 0;
        for ($i = 0; $i < 16; $i++) {
            $bits = ($bits << 1) | $this->reader->readU(1);

            if (($bits & 0xFFFE) === 0x0002) {
                $totalCoeff = 1;
                $trailingOnes = 1;
                break;
            } elseif (($bits & 0xFFF8) === 0x0008) {
                $totalCoeff = 2;
                $trailingOnes = 2;
                break;
            } elseif (($bits & 0xFFF0) === 0x0020) {
                $totalCoeff = 2;
                $trailingOnes = 1;
                break;
            } elseif (($bits & 0xFFE0) === 0x0080) {
                $totalCoeff = 2;
                $trailingOnes = 0;
                break;
            } elseif (($bits & 0xFFC0) === 0x0200) {
                $totalCoeff = 3;
                $trailingOnes = 3;
                break;
            } elseif (($bits & 0xFF80) === 0x0800) {
                $totalCoeff = 3;
                $trailingOnes = 2;
                break;
            } elseif (($bits & 0xFF00) === 0x2000) {
                $totalCoeff = 3;
                $trailingOnes = 1;
                break;
            } elseif (($bits & 0xFE00) === 0x8000) {
                $totalCoeff = 3;
                $trailingOnes = 0;
                break;
            } elseif (($bits & 0xFC00) === 0x4000) {
                $totalCoeff = 4;
                $trailingOnes = 3;
                break;
            } elseif (($bits & 0xF800) === 0x1000) {
                $totalCoeff = 4;
                $trailingOnes = 2;
                break;
            } elseif (($bits & 0xF000) === 0x0800) {
                $totalCoeff = 4;
                $trailingOnes = 1;
                break;
            } elseif (($bits & 0xE000) === 0x0400) {
                $totalCoeff = 4;
                $trailingOnes = 0;
                break;
            } elseif (($bits & 0xC000) === 0x0200) {
                $totalCoeff = 5;
                $trailingOnes = 3;
                break;
            } elseif (($bits & 0x8000) === 0x0100) {
                $totalCoeff = 5;
                $trailingOnes = 2;
                break;
            } elseif (($bits & 0x0000) === 0x0001) {
                $totalCoeff = 5;
                $trailingOnes = 1;
                break;
            } elseif (($bits & 0x0000) === 0x0004) {
                $totalCoeff = 5;
                $trailingOnes = 0;
                break;
            } elseif (($bits & 0x0000) === 0x0002) {
                $totalCoeff = 6;
                $trailingOnes = 0;
                break;
            } elseif (($bits & 0x0000) === 0x0008) {
                $totalCoeff = 7;
                $trailingOnes = 0;
                break;
            } elseif (($bits & 0x0000) === 0x0020) {
                $totalCoeff = 8;
                $trailingOnes = 0;
                break;
            } elseif (($bits & 0x0000) === 0x0080) {
                $totalCoeff = 9;
                $trailingOnes = 0;
                break;
            } elseif (($bits & 0x0000) === 0x0200) {
                $totalCoeff = 10;
                $trailingOnes = 0;
                break;
            } elseif (($bits & 0x0000) === 0x0800) {
                $totalCoeff = 11;
                $trailingOnes = 0;
                break;
            } elseif (($bits & 0x0000) === 0x2000) {
                $totalCoeff = 12;
                $trailingOnes = 0;
                break;
            } elseif (($bits & 0x0000) === 0x8000) {
                $totalCoeff = 13;
                $trailingOnes = 0;
                break;
            } elseif (($bits & 0x0001) === 0x0000) {
                $totalCoeff = 14;
                $trailingOnes = 0;
                break;
            } elseif (($bits & 0x0004) === 0x0000) {
                $totalCoeff = 15;
                $trailingOnes = 0;
                break;
            } elseif (($bits & 0x0010) === 0x0000) {
                $totalCoeff = 16;
                $trailingOnes = 0;
                break;
            } elseif (($bits & 0xFFFC) === 0x0004) {
                $totalCoeff = 1;
                $trailingOnes = 0;
                break;
            } elseif (($bits & 0xFFF8) === 0x0000) {
                $totalCoeff = 0;
                $trailingOnes = 0;
                break;
            }
        }

        if ($totalCoeff === 0) return $coeffs;

        $lastNz = $totalCoeff - 1;

        for ($i = 0; $i < $trailingOnes; $i++) {
            $idx = $lastNz - $i;
            $sign = $this->reader->readU(1);
            $coeffs[$idx] = $sign ? -1 : 1;
        }

        $suffixLen = ($totalCoeff > 10 && $trailingOnes < 3) ? 1 : 0;
        $rem = $totalCoeff - $trailingOnes;
        $revStart = $lastNz - $trailingOnes;

        for ($i = $revStart; $i >= 0 && $rem > 0; $i--) {
            $pre = 0;
            while ($pre < 32 && $this->reader->readU(1) === 0) $pre++;

            $isFirst = ($rem === $totalCoeff - $trailingOnes);

            //$levelCode = 0;
            if ($isFirst && $suffixLen === 0) {
                if ($pre < 14) {
                    $levelCode = $pre;
                } elseif ($pre === 14) {
                    $suffix = $this->reader->readU(4);
                    $levelCode = 14 + $suffix;
                } else {
                    $suffixBits = $pre - 3;
                    $suffix = $this->reader->readU($suffixBits);
                    $levelCode = 30 + $suffix;
                }
            } else {
                $levelCode = (1 << $pre);
                if ($suffixLen > 0) {
                    $levelCode += $this->reader->readU($suffixLen);
                }
            }

            $adjustedCode = $levelCode;
            if ($isFirst && $trailingOnes < 3) $adjustedCode += 2;

            $mask = -($adjustedCode & 1);
            $level = (($adjustedCode + 2) >> 1) ^ $mask;
            $level = $level - $mask;
            $coeffs[$i] = $level;

            $absLvl = abs($level);

            $rem--;

            if ($suffixLen === 0) $suffixLen = 1;
            while ($suffixLen < 6 && $absLvl >= (1 << ($suffixLen + 1))) $suffixLen++;
        }

        if ($totalCoeff < 16) {
            $totalZeros = $this->readTotalZeros($totalCoeff, 16);
            $zl = $totalZeros;
            $lastPos = $lastNz;

            $tmpCoeffs = array_fill(0, 16, 0);
            $nzIdx = $lastNz;

            for ($i = 0; $i < $totalCoeff; $i++) {
                if ($zl > 0) {
                    $rb = $this->readRunBefore($zl);
                    $lastPos -= ($rb + 1);
                    $zl -= $rb;
                }

                $tmpCoeffs[$lastPos] = $coeffs[$nzIdx];
                $nzIdx--;
                if ($lastPos > 0) $lastPos--;
            }

            $coeffs = $tmpCoeffs;
        }

        return $coeffs;
    }

    public function readChromaDcCoeffs(): array
    {
        $coeffs = array_fill(0, 4, 0);

        // 色度DC coeff_token VLC表
        // 索引方式: [totalCoeff][trailingOnes] -> [bits_value, length]
        static $chromaDcTokenMap = [
            '01'       => [0, 0],  // TotalCoeff=0, TrailingOnes=0 (len=2)
            '000111'   => [1, 0],  // TotalCoeff=1, TrailingOnes=0 (len=6)
            '1'        => [1, 1],  // TotalCoeff=1, TrailingOnes=1 (len=1)
            '000100'   => [2, 0],  // TotalCoeff=2, TrailingOnes=0 (len=6)
            '000110'   => [2, 1],  // TotalCoeff=2, TrailingOnes=1 (len=6)
            '001'      => [2, 2],  // TotalCoeff=2, TrailingOnes=2 (len=3)
            '000011'   => [3, 0],  // TotalCoeff=3, TrailingOnes=0 (len=6)
            '0000011'  => [3, 1],  // TotalCoeff=3, TrailingOnes=1 (len=7)
            '0000010'  => [3, 2],  // TotalCoeff=3, TrailingOnes=2 (len=7)
            '000101'   => [3, 3],  // TotalCoeff=3, TrailingOnes=3 (len=6)
            '000010'   => [4, 0],  // TotalCoeff=4, TrailingOnes=0 (len=6)
            '00000011' => [4, 1],  // TotalCoeff=4, TrailingOnes=1 (len=8)
            '00000010' => [4, 2],  // TotalCoeff=4, TrailingOnes=2 (len=8)
            '0000000'  => [4, 3],  // TotalCoeff=4, TrailingOnes=3 (len=7)
        ];

        $bits = '';
        $totalCoeff = 0;
        $trailingOnes = 0;

        // 最长码字为8位 ("00000011" / "00000010")
        for ($i = 0; $i < 8; $i++) {
            $bits .= $this->reader->readU(1);
            if (isset($chromaDcTokenMap[$bits])) {
                list($totalCoeff, $trailingOnes) = $chromaDcTokenMap[$bits];
                break;
            }
        }

        if ($totalCoeff === 0) return $coeffs;

        $lastNz = $totalCoeff - 1;

        // trailing_ones: 从高频端开始读取符号位
        for ($i = 0; $i < $trailingOnes; $i++) {
            $idx = $lastNz - $i;
            $sign = $this->reader->readU(1);
            $coeffs[$idx] = $sign ? -1 : 1;
        }

        // level解码: 与readCoeffsCAVLC使用完全相同的逻辑
        $suffixLen = ($totalCoeff > 10 && $trailingOnes < 3) ? 1 : 0;
        $rem = $totalCoeff - $trailingOnes;
        $revStart = $lastNz - $trailingOnes;
        $suffixLimit = [0, 3, 6, 12, 24, 48, PHP_INT_MAX];

        for ($i = $revStart; $i >= 0 && $rem > 0; $i--) {
            $isFirst = ($rem === $totalCoeff - $trailingOnes);
            $pre = 0;
            while ($pre < 32 && $this->reader->readU(1) === 0) $pre++;

            //$levelCode = 0;
            if ($isFirst && $suffixLen === 0) {
                // 第一个level且suffix_length==0的特殊VLC
                if ($pre < 14) {
                    $levelCode = $pre;
                } elseif ($pre === 14) {
                    $suffix = $this->reader->readU(4);
                    $levelCode = 14 + $suffix;
                } else {
                    $lc = 30;
                    if ($pre >= 16) {
                        $lc += (1 << ($pre - 3)) - 4096;
                    }
                    $suffix = $this->reader->readU($pre - 3);
                    $levelCode = $lc + $suffix;
                }
            } else {
                // 后续level 或 第一个且suffix_length>0
                if ($pre < 15) {
                    $suffix = ($suffixLen > 0) ? $this->reader->readU($suffixLen) : 0;
                    $levelCode = $pre * (1 << $suffixLen) + $suffix;
                } else {
                    $lc = 15 * (1 << $suffixLen);
                    if ($pre >= 16) {
                        $lc += (1 << ($pre - 3)) - 4096;
                    }
                    $suffix = $this->reader->readU($pre - 3);
                    $levelCode = $lc + $suffix;
                }
            }

            $adjustedCode = $levelCode;
            if ($isFirst && $trailingOnes < 3) $adjustedCode += 2;

            $mask = -($adjustedCode & 1);
            $level = (($adjustedCode + 2) >> 1) ^ $mask;
            $level = $level - $mask;
            $coeffs[$i] = $level;

            // 更新suffix_length (与readCoeffsCAVLC一致)
            $absLvl = abs($level);
            if ($isFirst) {
                $suffixLen = ($absLvl > 3) ? 2 : 1;
            } else {
                if ($suffixLen < 6 && $absLvl > $suffixLimit[$suffixLen]) {
                    $suffixLen++;
                }
            }
            $rem--;
        }

        // total_zeros + run_before (与readCoeffsCAVLC完全相同的逻辑)
        if ($totalCoeff < 4) {
            $totalZeros = $this->readTotalZeros($totalCoeff, 4);
            $zl = $totalZeros;
            $coeffIdx = $totalCoeff + $totalZeros - 1;

            $tmpCoeffs = array_fill(0, 4, 0);
            $nzIdx = $lastNz;

            for ($i = 0; $i < $totalCoeff; $i++) {
                $isLast = ($i == $totalCoeff - 1);
                //$rb = 0;
                if (!$isLast && $zl > 0) {
                    $rb = $this->readRunBefore($zl);
                } elseif (!$isLast) {
                    $rb = 0;
                } else {
                    // 最后一个系数: 消费所有剩余零, 不读取run_before
                    $rb = $zl;
                }

                $tmpCoeffs[$coeffIdx] = $coeffs[$nzIdx];

                $zl -= $rb;

                if (!$isLast) {
                    $coeffIdx -= ($rb + 1);
                    $nzIdx--;
                }
            }

            $coeffs = $tmpCoeffs;
        }

        return $coeffs;
    }

    /**
     * 计算CAVLC的nC（neighboring coefficient count）
     * @param int $blockIdx 块索引（raster order，0-15亮度，16-19色度Cb，20-23色度Cr）
     * @param int $mbX 当前宏块X坐标
     * @param int $mbY 当前宏块Y坐标
     * @param array $nzCache 当前宏块内已解码块的非零系数计数（raster order）
     * @param array $leftNz 左邻宏块的非零系数计数（亮度4个，色度2个）
     * @param array $topNz 上邻宏块的非零系数计数（亮度按列存储）
     * @param bool $leftAvailable 左邻宏块是否可用
     * @param bool $topAvailable 上邻宏块是否可用
     * @return int nC值
     */
    public function computeNc(int $rasterIdx, int $mbX, int $mbY, array $nzCache, array $leftNz, array $topNz, bool $leftAvailable, bool $topAvailable): int
    {
        if ($rasterIdx < 16) {
            $blkX = $rasterIdx % 4;
            $blkY = (int)($rasterIdx / 4);

            $left = null;
            if ($blkX > 0) {
                $left = $nzCache[$rasterIdx - 1];
            } elseif ($leftAvailable) {
                $left = $leftNz[$blkY];
            }

            $top = null;
            if ($blkY > 0) {
                $top = $nzCache[$rasterIdx - 4];
            } elseif ($topAvailable) {
                $absBlkX = $mbX * 4 + $blkX;
                $top = $topNz[$absBlkX];
            }
        } elseif ($rasterIdx < 20) {
            $cbIdx = $rasterIdx - 16;
            $blkX = $cbIdx % 2;
            $blkY = (int)($cbIdx / 2);

            $left = null;
            if ($blkX > 0) {
                $left = $nzCache[$rasterIdx - 1];
            } elseif ($leftAvailable) {
                $left = $leftNz[4 + $blkY];
            }

            $top = null;
            if ($blkY > 0) {
                $top = $nzCache[$rasterIdx - 2];
            } elseif ($topAvailable) {
                $absBlkX = $mbX * 2 + $blkX;
                $top = $topNz[$this->picWidthInMbs * 4 + $absBlkX];
            }
        } else {
            $crIdx = $rasterIdx - 20;
            $blkX = $crIdx % 2;
            $blkY = (int)($crIdx / 2);

            $left = null;
            if ($blkX > 0) {
                $left = $nzCache[$rasterIdx - 1];
            } elseif ($leftAvailable) {
                $left = $leftNz[6 + $blkY];
            }

            $top = null;
            if ($blkY > 0) {
                $top = $nzCache[$rasterIdx - 2];
            } elseif ($topAvailable) {
                $absBlkX = $mbX * 2 + $blkX;
                $top = $topNz[$this->picWidthInMbs * 4 + $this->picWidthInMbs * 2 + $absBlkX];
            }
        }

        if ($left !== null && $top !== null) {
            return (int)(($left + $top + 1) >> 1);
        } elseif ($left !== null) {
            return (int)$left;
        } elseif ($top !== null) {
            return (int)$top;
        } else {
            return 0;
        }
    }

    public function readCoeffToken(int &$totalCoeff, int &$trailingOnes, int $nC): void
    {
        static $COEFF_TOKEN_LEN_0 = [
            1, 0, 0, 0, 6, 2, 0, 0, 8, 6, 3, 0, 9, 8, 7, 5, 10, 9, 8, 6, 11, 10, 9, 7, 13, 11, 10, 8, 13,
            13, 11, 9, 13, 13, 13, 10, 14, 14, 13, 11, 14, 14, 14, 13, 15, 15, 14, 14, 15, 15, 15, 14, 16,
            15, 15, 15, 16, 16, 16, 15, 16, 16, 16, 16, 16, 16, 16, 16,
        ];
        static $COEFF_TOKEN_BITS_0 = [
            1, 0, 0, 0, 5, 1, 0, 0, 7, 4, 1, 0, 7, 6, 5, 3, 7, 6, 5, 3, 7, 6, 5, 4, 15, 6, 5, 4, 11, 14, 5,
            4, 8, 10, 13, 4, 15, 14, 9, 4, 11, 10, 13, 12, 15, 14, 9, 12, 11, 10, 13, 8, 15, 1, 9, 12, 11,
            14, 13, 8, 7, 10, 9, 12, 4, 6, 5, 8,
        ];

        static $COEFF_TOKEN_LEN_1 = [
            2, 0, 0, 0, 6, 2, 0, 0, 6, 5, 3, 0, 7, 6, 6, 4, 8, 6, 6, 4, 8, 7, 7, 5, 9, 8, 8, 6, 11, 9, 9,
            6, 11, 11, 11, 7, 12, 11, 11, 9, 12, 12, 12, 11, 12, 12, 12, 11, 13, 13, 13, 12, 13, 13, 13,
            13, 13, 14, 13, 13, 14, 14, 14, 13, 14, 14, 14, 14,
        ];
        static $COEFF_TOKEN_BITS_1 = [
            3, 0, 0, 0, 11, 2, 0, 0, 7, 7, 3, 0, 7, 10, 9, 5, 7, 6, 5, 4, 4, 6, 5, 6, 7, 6, 5, 8, 15, 6, 5,
            4, 11, 14, 13, 4, 15, 10, 9, 4, 11, 14, 13, 12, 8, 10, 9, 8, 15, 14, 13, 12, 11, 10, 9, 12, 7,
            11, 6, 8, 9, 8, 10, 1, 7, 6, 5, 4,
        ];

        static $COEFF_TOKEN_LEN_2 = [
            4, 0, 0, 0, 6, 4, 0, 0, 6, 5, 4, 0, 6, 5, 5, 4, 7, 5, 5, 4, 7, 5, 5, 4, 7, 6, 6, 4, 7, 6, 6, 4,
            8, 7, 7, 5, 8, 8, 7, 6, 9, 8, 8, 7, 9, 9, 8, 8, 9, 9, 9, 8, 10, 9, 9, 9, 10, 10, 10, 10, 10,
            10, 10, 10, 10, 10, 10, 10,
        ];
        static $COEFF_TOKEN_BITS_2 = [
            15, 0, 0, 0, 15, 14, 0, 0, 11, 15, 13, 0, 8, 12, 14, 12, 15, 10, 11, 11, 11, 8, 9, 10, 9, 14,
            13, 9, 8, 10, 9, 8, 15, 14, 13, 13, 11, 14, 10, 12, 15, 10, 13, 12, 11, 14, 9, 12, 8, 10, 13,
            8, 13, 7, 9, 12, 9, 12, 11, 10, 5, 8, 7, 6, 1, 4, 3, 2,
        ];

        static $COEFF_TOKEN_LEN_3 = [
            6, 0, 0, 0, 6, 6, 0, 0, 6, 6, 6, 0, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6,
            6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6,
            6, 6, 6, 6,
        ];
        static $COEFF_TOKEN_BITS_3 = [
            3, 0, 0, 0, 0, 1, 0, 0, 4, 5, 6, 0, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22,
            23, 24, 25, 26, 27, 28, 29, 30, 31, 32, 33, 34, 35, 36, 37, 38, 39, 40, 41, 42, 43, 44, 45, 46,
            47, 48, 49, 50, 51, 52, 53, 54, 55, 56, 57, 58, 59, 60, 61, 62, 63,
        ];

        static $CHROMA_DC_COEFF_TOKEN_LEN = [2, 0, 0, 0, 6, 1, 0, 0, 6, 6, 3, 0, 6, 7, 7, 6, 6, 8, 8, 7];
        static $CHROMA_DC_COEFF_TOKEN_BITS = [1, 0, 0, 0, 7, 1, 0, 0, 4, 6, 1, 0, 3, 3, 2, 5, 2, 3, 2, 0];

        static $COEFF_TOKEN_TABLE_INDEX = [0, 0, 1, 1, 2, 2, 2, 2, 3, 3, 3, 3, 3, 3, 3, 3, 3];

        $totalCoeff = 0;
        $trailingOnes = 0;

        if ($nC == -1) {
            $lens = $CHROMA_DC_COEFF_TOKEN_LEN;
            $bitsTab = $CHROMA_DC_COEFF_TOKEN_BITS;
            $count = 20;
            $maxBits = 8;
        } else {
            $ncClamped = min($nC, 16);
            $tableIdx = $COEFF_TOKEN_TABLE_INDEX[$ncClamped];

            switch ($tableIdx) {
                case 0:
                    $lens = $COEFF_TOKEN_LEN_0;
                    $bitsTab = $COEFF_TOKEN_BITS_0;
                    $count = 68;
                    $maxBits = 16;
                    break;
                case 1:
                    $lens = $COEFF_TOKEN_LEN_1;
                    $bitsTab = $COEFF_TOKEN_BITS_1;
                    $count = 68;
                    $maxBits = 14;
                    break;
                case 2:
                    $lens = $COEFF_TOKEN_LEN_2;
                    $bitsTab = $COEFF_TOKEN_BITS_2;
                    $count = 68;
                    $maxBits = 10;
                    break;
                case 3:
                    $lens = $COEFF_TOKEN_LEN_3;
                    $bitsTab = $COEFF_TOKEN_BITS_3;
                    $count = 68;
                    $maxBits = 6;
                    break;
                default:
                    return;
            }
        }

        $peeked = $this->reader->peek($maxBits);

        static $lookupCache = [];
        $lookupKey = $nC == -1 ? -1 : $tableIdx;
        if (!isset($lookupCache[$lookupKey])) {
            $lookup = [];
            for ($i = 0; $i < $count; $i++) {
                $len = $lens[$i];
                if ($len !== 0) {
                    $lookup[$len][$bitsTab[$i]] = $i;
                }
            }
            $lookupCache[$lookupKey] = $lookup;
        }
        $lookup = $lookupCache[$lookupKey];

        for ($len = $maxBits; $len > 0; $len--) {
            if (!isset($lookup[$len])) continue;
            $code = $peeked >> ($maxBits - $len);
            if (isset($lookup[$len][$code])) {
                $index = $lookup[$len][$code];
                $this->reader->skip($len);
                $totalCoeff = intdiv($index, 4);
                $trailingOnes = $index & 3;
                return;
            }
        }

        $totalCoeff = 0;
        $trailingOnes = 0;
    }

    public function readTotalZeros(int $totalCoeff, int $maxCoeff): int
    {
        $maxZeros = $maxCoeff - $totalCoeff;
        if ($maxZeros === 0) return 0;

        static $TOTAL_ZEROS_LEN = [
            [1, 3, 3, 4, 4, 5, 5, 6, 6, 7, 7, 8, 8, 9, 9, 9],
            [3, 3, 3, 3, 3, 4, 4, 4, 4, 5, 5, 6, 6, 6, 6, 0],
            [4, 3, 3, 3, 4, 4, 3, 3, 4, 5, 5, 6, 5, 6, 0, 0],
            [5, 3, 4, 4, 3, 3, 3, 4, 3, 4, 5, 5, 5, 0, 0, 0],
            [4, 4, 4, 3, 3, 3, 3, 3, 4, 5, 4, 5, 0, 0, 0, 0],
            [6, 5, 3, 3, 3, 3, 3, 3, 4, 3, 6, 0, 0, 0, 0, 0],
            [6, 5, 3, 3, 3, 2, 3, 4, 3, 6, 0, 0, 0, 0, 0, 0],
            [6, 4, 5, 3, 2, 2, 3, 3, 6, 0, 0, 0, 0, 0, 0, 0],
            [6, 6, 4, 2, 2, 3, 2, 5, 0, 0, 0, 0, 0, 0, 0, 0],
            [5, 5, 3, 2, 2, 2, 4, 0, 0, 0, 0, 0, 0, 0, 0, 0],
            [4, 4, 3, 3, 1, 3, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
            [4, 4, 2, 1, 3, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
            [3, 3, 1, 2, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
            [2, 2, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
            [1, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
        ];

        static $TOTAL_ZEROS_BITS = [
            [1, 3, 2, 3, 2, 3, 2, 3, 2, 3, 2, 3, 2, 3, 2, 1],
            [7, 6, 5, 4, 3, 5, 4, 3, 2, 3, 2, 3, 2, 1, 0, 0],
            [5, 7, 6, 5, 4, 3, 4, 3, 2, 3, 2, 1, 1, 0, 0, 0],
            [3, 7, 5, 4, 6, 5, 4, 3, 3, 2, 2, 1, 0, 0, 0, 0],
            [5, 4, 3, 7, 6, 5, 4, 3, 2, 1, 1, 0, 0, 0, 0, 0],
            [1, 1, 7, 6, 5, 4, 3, 2, 1, 1, 0, 0, 0, 0, 0, 0],
            [1, 1, 5, 4, 3, 3, 2, 1, 1, 0, 0, 0, 0, 0, 0, 0],
            [1, 1, 1, 3, 3, 2, 2, 1, 0, 0, 0, 0, 0, 0, 0, 0],
            [1, 0, 1, 3, 2, 1, 1, 1, 0, 0, 0, 0, 0, 0, 0, 0],
            [1, 0, 1, 3, 2, 1, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0],
            [0, 1, 1, 2, 1, 3, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
            [0, 1, 1, 1, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
            [0, 1, 1, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
            [0, 1, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
            [0, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
        ];

        static $CHROMA_DC_TOTAL_ZEROS_LEN = [[1, 2, 3, 3], [1, 2, 2, 0], [1, 1, 0, 0]];
        static $CHROMA_DC_TOTAL_ZEROS_BITS = [[1, 1, 1, 0], [1, 1, 0, 0], [1, 0, 0, 0]];

        if ($maxCoeff == 4) {
            $tableIdx = $totalCoeff - 1;
            if ($tableIdx < 0 || $tableIdx > 2) return 0;
            $lens = $CHROMA_DC_TOTAL_ZEROS_LEN[$tableIdx];
            $bitsTab = $CHROMA_DC_TOTAL_ZEROS_BITS[$tableIdx];
            $count = 4;
            $maxBits = 3;
        } else {
            $tableIdx = $totalCoeff - 1;
            if ($tableIdx < 0 || $tableIdx > 14) return 0;
            $lens = $TOTAL_ZEROS_LEN[$tableIdx];
            $bitsTab = $TOTAL_ZEROS_BITS[$tableIdx];
            $count = 16;
            $maxBits = 9;
        }

        $peeked = $this->reader->peek($maxBits);

        for ($i = 0; $i < $count; $i++) {
            $len = $lens[$i];
            if ($len == 0) continue;
            $code = $bitsTab[$i];
            $shift = $maxBits - $len;
            if (($peeked >> $shift) == $code) {
                $this->reader->skip($len);
                return $i;
            }
        }

        return 0;
    }

    public function readRunBefore(int $leftZeros): int
    {
        if ($leftZeros <= 0) return 0;

        static $RUN_BEFORE_LEN = [
            [1, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
            [1, 2, 2, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
            [2, 2, 2, 2, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
            [2, 2, 2, 3, 3, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
            [2, 2, 3, 3, 3, 3, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
            [2, 3, 3, 3, 3, 3, 3, 0, 0, 0, 0, 0, 0, 0, 0, 0],
            [3, 3, 3, 3, 3, 3, 3, 4, 5, 6, 7, 8, 9, 10, 11, 0],
        ];

        static $RUN_BEFORE_BITS = [
            [1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
            [1, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
            [3, 2, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
            [3, 2, 1, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
            [3, 2, 3, 2, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
            [3, 0, 1, 3, 2, 5, 4, 0, 0, 0, 0, 0, 0, 0, 0, 0],
            [7, 6, 5, 4, 3, 2, 1, 1, 1, 1, 1, 1, 1, 1, 1, 0],
        ];

        static $RUN_BEFORE_COUNT = [2, 3, 4, 5, 6, 7, 15];

        $tableIdx = min($leftZeros, 7) - 1;
        if ($tableIdx < 0 || $tableIdx > 6) return 0;

        $lens = $RUN_BEFORE_LEN[$tableIdx];
        $bitsTab = $RUN_BEFORE_BITS[$tableIdx];
        $count = $RUN_BEFORE_COUNT[$tableIdx];

        $maxBits = ($leftZeros >= 7) ? 11 : 3;

        $peeked = $this->reader->peek($maxBits);

        for ($i = 0; $i < $count; $i++) {
            $len = $lens[$i];
            if ($len == 0) continue;
            $code = $bitsTab[$i];
            $shift = $maxBits - $len;
            if (($peeked >> $shift) == $code) {
                $this->reader->skip($len);
                return $i;
            }
        }

        return 0;
    }

    /**
     * 解码AC残差块CAVLC（15个AC）
     * 注意：对于 AC 系数，nC 通常根据上邻和左邻宏块的非零系数数量计算
     * 对于 DC 系数（luma DC 16/4 或 chroma DC 4），nC = -1
     */
    public function decodeResidualBlock(int $maxCoef, int $nC = -1): array
    {
        // Direct translation of Rust decode_residual (cavlc.rs:344-542)
        //$dbg = $this->debugResidual;
        $coeffs = array_fill(0, $maxCoef, 0);

        // 1. Read coeff_token
        $totalCoeff = 0;
        $trailingOnes = 0;
        $this->readCoeffToken($totalCoeff, $trailingOnes, $nC);

        // 根据H.264标准，如果totalCoeff超过maxCoef，限制为maxCoef
        $totalCoeff = min($totalCoeff, $maxCoef);
        // trailingOnes不能超过totalCoeff
        $trailingOnes = min($trailingOnes, $totalCoeff);

        if ($totalCoeff === 0) {
            return $coeffs;
        }

        // 2. Read levels
        $levels = array_fill(0, $totalCoeff, 0);

        // 2a. Trailing ones: read sign bits (1 bit each)
        for ($i = 0; $i < $trailingOnes; $i++) {
            $sign = $this->reader->readU(1);
            $levels[$i] = $sign ? -1 : 1;
        }

        // 2b. Read remaining levels (totalCoeff - trailingOnes)
        $remaining = $totalCoeff - $trailingOnes;
        if ($remaining > 0) {
            $suffixLength = ($totalCoeff > 10 && $trailingOnes < 3) ? 1 : 0;

            for ($i = 0; $i < $remaining; $i++) {
                $levelIdx = $trailingOnes + $i;
                $isFirst = ($i === 0);

                // Read level_prefix: count consecutive zero bits before a '1'
                $prefix = 0;
                while ($prefix < 32 && $this->reader->readU(1) === 0) {
                    $prefix++;
                }

                // Compute level_code from prefix and suffix
                //$levelCode = 0;

                if ($isFirst && $suffixLength === 0) {
                    // First coefficient with suffix_length == 0: special VLC
                    if ($prefix < 14) {
                        $levelCode = $prefix;
                    } elseif ($prefix === 14) {
                        $suffix = $this->reader->readU(4);
                        $levelCode = 14 + $suffix;
                    } else {
                        $lc = 30;
                        if ($prefix >= 16) {
                            $lc += (1 << ($prefix - 3)) - 4096;
                        }
                        $suffix = $this->reader->readU($prefix - 3);
                        $levelCode = $lc + $suffix;
                    }
                } else {
                    // Subsequent coefficients or first with suffix_length == 1
                    if ($prefix < 15) {
                        $suffix = ($suffixLength > 0) ? $this->reader->readU($suffixLength) : 0;
                        $levelCode = $prefix * (1 << $suffixLength) + $suffix;
                    } else {
                        $lc = 15 * (1 << $suffixLength);
                        if ($prefix >= 16) {
                            $lc += (1 << ($prefix - 3)) - 4096;
                        }
                        $suffix = $this->reader->readU($prefix - 3);
                        $levelCode = $lc + $suffix;
                    }
                }

                // 从 levelCode 中提取符号和绝对值（H.264 标准：最低位是符号位，0=正，1=负）
                $sign = $levelCode & 1;
                $absLevelMinus1 = $levelCode >> 1;
                if ($isFirst && $trailingOnes < 3) {
                    $absLevelMinus1 += 1;
                }
                $absLevel = $absLevelMinus1 + 1;
                $levels[$levelIdx] = $sign ? -$absLevel : $absLevel;

                // Update suffix_length
                $absLevel = abs($levels[$levelIdx]);
                if ($isFirst) {
                    $suffixLength = ($absLevel > 3) ? 2 : 1;
                } else {
                    $suffixLimit = [0, 3, 6, 12, 24, 48, PHP_INT_MAX];
                    if ($suffixLength < 6 && $absLevel > $suffixLimit[$suffixLength]) {
                        $suffixLength++;
                    }
                }
            }
        }

        // 3. Read total_zeros
        if ($totalCoeff < $maxCoef) {
            $totalZeros = $this->readTotalZeros($totalCoeff, $maxCoef);
        } else {
            $totalZeros = 0;
        }

        // 4. Read run_before and place coefficients
        $zerosLeft = $totalZeros;
        $coeffIdx = $totalCoeff + $totalZeros - 1;

        for ($i = 0; $i < $totalCoeff; $i++) {
            $isLast = ($i === $totalCoeff - 1);

            if (!$isLast && $zerosLeft > 0) {
                $run = $this->readRunBefore($zerosLeft);
            } elseif (!$isLast) {
                $run = 0;
            } else {
                $run = $zerosLeft;
            }

            if ($coeffIdx >= 0 && $coeffIdx < $maxCoef) {
                $coeffs[$coeffIdx] = $levels[$i];
            }

            $zerosLeft -= $run;

            if (!$isLast) {
                $coeffIdx -= (1 + $run);
            }
        }
        return $coeffs;
    }
}
