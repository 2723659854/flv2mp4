<?php

namespace Xiaosongshu\Flv2mp4\Codec\Encode;

/**
 * @purpose CAVLC编码特征/模块 熵编码模块
 * @author yanglong
 *
 * CAVLC（Context-Adaptive Variable Length Coding）熵编码实现，对应规范 9.2 节：
 * - 9.2.1  coeff_token：由 nC 选择码表，编码 (TotalCoeff, TrailingOnes)；
 * - 9.2.2  level：level_prefix / level_suffix，含 suffixLength 自适应与两种转义；
 * - 9.2.3  total_zeros：非零系数前的总零数；
 * - 9.2.4  run_before：每个非零系数前的零游程。
 * 码表（VLC_COEFF_TOKEN / VLC_TOTAL_ZEROS / VLC_RUN_BEFORE 等）定义在 H264Encoder 中。
 */
trait CavlcTrait
{
    /**
     * 计算当前 4x4 块的 nC 预测值（规范 9.2.1 中 nC 的推导）。
     * nC 由左、上相邻块的非零系数个数 nA、nB 推得：
     *   nC = (nA + nB + 1) >> 1
     * 若某一侧不可用则不参与平均；两侧都不可用时 nC = 0。
     *
     * 块索引约定：-1 = 亮度 16x16 的 DC 块；0..15 = 亮度 4x4 块；
     * 其余 = 色度 DC/AC 块（其中 blockIdx<20 与 >=20 对应不同色度分量，左邻偏移不同）。
     *
     * @param int   $blockIdx       当前块索引（见上述约定）
     * @param int   $mbX            当前宏块列号
     * @param int   $bx             块在宏块内的列号
     * @param int   $by             块在宏块内的行号
     * @param bool  $leftAvailable  左邻块是否可用（是否在同一 slice / 图像内）
     * @param array $leftNz         左邻宏块各块的 nz 缓存
     * @param bool  $topAvailable   上邻块是否可用
     * @param array $topNz          上邻行的 nz 缓存（按 4x4 块列索引）
     * @param array $nzCache        当前宏块内已编码块的 nz 值（用于优先取块内邻居）
     * @return int nC（取值 0..16）
     */
    public function computeNC($blockIdx, $mbX, $bx, $by, $leftAvailable, $leftNz, $topAvailable, $topNz, $nzCache)
    {
        // 亮度 16x16 的 DC 块：其邻居是整个相邻宏块的 DC 块
        if ($blockIdx === -1) {
            // 参与平均的 nz 累加值
            $predNz = 0;
            // 可用的邻居个数
            $count = 0;
            // 左侧宏块可用：取其 DC 块（索引 0）的 nz
            if ($leftAvailable) {
                $predNz += $leftNz[0];
                $count++;
            }
            // 上侧宏块可用：取其 DC 块对应位置的 nz
            if ($topAvailable) {
                // 上邻数组按 4x4 块列索引，mbX*4 定位该宏块第一个 4x4 块列
                $ax = $mbX * 4;
                if ($ax < count($topNz)) {
                    $predNz += $topNz[$ax];
                    $count++;
                }
            }
            // 两侧都不可用：nC 取 0（规范 9.2.1）
            if ($count === 0) return 0;
            // nC = (nA + nB + 1) >> 1，用整数除法 + 四舍五入实现
            $avgNz = intdiv($predNz + intdiv($count, 2), $count);
            // nC 上限为 16
            return min($avgNz, 16);
        }

        // 普通 4x4 块：分别累加左、上邻居的 nz
        $predNz = 0;
        // 可用的邻居个数
        $count = 0;

        // 亮度 4x4 块（0..15，按光栅顺序排列）
        if ($blockIdx < 16) {
            // 块左侧在同一宏块内：直接取块内左边块的 nz
            if ($bx > 0) {
                $predNz += $nzCache[$blockIdx - 1];
                $count++;
            } elseif ($leftAvailable) {
                // 块位于宏块最左列：取左邻宏块中同一行块的 nz
                $predNz += $leftNz[$by];
                $count++;
            }
            // 块上方在同一宏块内：取块内上一行块的 nz（光栅顺序差 4）
            if ($by > 0) {
                $predNz += $nzCache[$blockIdx - 4];
                $count++;
            } elseif ($topAvailable) {
                // 块位于宏块最上行：取上邻行同一列的 nz
                $ax = $mbX * 4 + $bx;
                if ($ax < count($topNz)) {
                    $predNz += $topNz[$ax];
                    $count++;
                }
            }
        } else {
            // 色度块：blockIdx<20 用偏移 4，>=20 用偏移 6（两个色度分量在 leftNz 中的起始位置不同）
            $lnOff = $blockIdx < 20 ? 4 : 6;
            // 块左侧在同一宏块内
            if ($bx > 0) {
                $predNz += $nzCache[$blockIdx - 1];
                $count++;
            } elseif ($leftAvailable) {
                // 左邻宏块同一行块
                $predNz += $leftNz[$lnOff + $by];
                $count++;
            }
            // 块上方在同一宏块内：色度 4x4 块在宏块内按 2 列排布，故上邻索引差 2
            if ($by > 0) {
                $predNz += $nzCache[$blockIdx - 2];
                $count++;
            } elseif ($topAvailable) {
                // 上邻行同一列（色度按 2 块/宏块宽计）
                $ax = $mbX * 2 + $bx;
                if ($ax < count($topNz)) {
                    $predNz += $topNz[$ax];
                    $count++;
                }
            }
        }

        // nC = (nA + nB + 1) >> 1；无可用邻居时为 0
        $avgNz = $count > 0 ? intdiv($predNz + intdiv($count, 2), $count) : 0;
        // nC 上限仍为 16
        return  min($avgNz, 16);
    }

    /**
     * 对一个 4x4 残差块做 CAVLC 编码（规范 9.2）。
     *
     * 流程：扫描得到非零系数序列（level/run）→ 统计 TotalCoeff/TrailingOnes →
     * 写 coeff_token → 逆序写各 level（含 suffixLength 自适应）→ 写 total_zeros → 写 run_before。
     *
     * @param array $coeffs     按扫描顺序排列的量化后系数（长度 16，色度 DC 为 4）
     * @param int   $endIdx     最后一个可能非零的下标
     * @param bool  $isChromaDc 是否为色度 DC 块（使用独立的 coeff_token/total_zeros 码表）
     * @param int   $iNC        已量化的 nC 档位（用于 ENC_NC_MAP_TABLE 选表）
     * @return string 位串
     */
    public function writeBlockResidualCavlc(array $coeffs, int $endIdx, bool $isChromaDc, int $iNC): string
    {
        // 位串累加器
        $bits = '';

        // 反向扫描，定位最后一个非零系数（CAVLC 按逆扫描顺序编码）
        $iLastIndex = $endIdx;
        while ($iLastIndex >= 0 && $coeffs[$iLastIndex] == 0) {
            $iLastIndex--;
        }

        // 全零块：TotalCoeff = 0、TrailingOnes = 0，只需写一个 coeff_token
        if ($iLastIndex < 0) {
            // 色度 DC 固定使用 nC 表 4（规范 9.2.1 中色度 DC 的 nC 规则）
            if ($isChromaDc) {
                $ncIdx = 4;
            } else {
                // 亮度按 nC 分档选表
                $ncIdx = self::ENC_NC_MAP_TABLE[$iNC];
            }
            // 取 (TotalCoeff=0, TrailingOnes=0) 的码字（下标 [0][0]）
            $coeffToken = self::VLC_COEFF_TOKEN[$ncIdx][0][0];
            // 码字数值
            $value = $coeffToken[0];
            // 码字位长
            $n = $coeffToken[1];
            // 写入定长位串
            $bits .= $this->u($value, $n);
            return $bits;
        }

        // 非零系数之前（扫描顺序上）的零总数
        $totalZeros = 0;
        // 非零系数总个数 = TotalCoeff
        $totalCoeffs = 0;

        // 逆序收集的 level 序列
        $level = [];
        // 每个 level 之前紧邻的零游程
        $run = [];

        // 再次定位最后一个非零系数（后面主循环从该位置开始反向遍历）
        $iLastIndex = $endIdx;
        while ($iLastIndex >= 0 && $coeffs[$iLastIndex] == 0) {
            $iLastIndex--;
        }

        // 从最后一个非零系数开始逆序扫描，拆分出 level 与 run
        while ($iLastIndex >= 0) {
            // 当前 level 之前紧邻的零游程长度
            $countZero = 0;
            // 取出一个非零系数作为下一个 level，指针左移
            $level[$totalCoeffs] = $coeffs[$iLastIndex--];

            // 继续左移，统计紧邻的零系数个数
            while ($iLastIndex >= 0 && $coeffs[$iLastIndex] == 0) {
                $countZero++;
                $iLastIndex--;
            }
            // 累加总零数
            $totalZeros += $countZero;
            // 记录该 level 的 run，并递增 TotalCoeff
            $run[$totalCoeffs++] = $countZero;
        }

        // TrailingOnes：逆序序列开头连续出现、幅值为 1 的系数个数（最多 3，规范 9.2.1）
        $trailingOnes = 0;
        // TrailingOnes 的符号位打包（1 表示负）
        $sign = 0;
        // 最多检查 3 个系数
        $count = ($totalCoeffs > 3) ? 3 : $totalCoeffs;
        for ($i = 0; $i < $count; $i++) {
            // 幅值为 1 才算 trailing one
            if (abs($level[$i]) == 1) {
                $trailingOnes++;
                // 符号位左移腾出最低位
                $sign <<= 1;
                // 负系数置最低位为 1
                if ($level[$i] < 0) {
                    $sign |= 1;
                }
            } else {
                // 遇到幅值非 1 的系数即停止统计
                break;
            }
        }

        // 选表：色度 DC 固定表 4
        if ($isChromaDc) {
            $ncIdx = 4;
        } else {
            // 亮度按 nC 档位选表
            $ncIdx = self::ENC_NC_MAP_TABLE[$iNC];
        }

        // 查 (TotalCoeff, TrailingOnes) 对应的 coeff_token 码字
        $coeffToken = self::VLC_COEFF_TOKEN[$ncIdx][$totalCoeffs][$trailingOnes];
        // 码字数值（不含 trailing ones 的符号位）
        $value = $coeffToken[0];
        // 码字位长
        $n = $coeffToken[1];
        // 位长增加 trailingOnes 个符号位
        $n += $trailingOnes;
        // 把符号位拼到码字最低若干位
        $value = ($value << $trailingOnes) | $sign;
        // 写出完整的 coeff_token（码字 + 符号位）
        $bits .= $this->u($value, $n);

        // suffixLength 初值：TotalCoeff>10 且 TrailingOnes<3 时为 1，否则为 0（规范 9.2.2.1）
        $suffixLength = ($totalCoeffs > 10 && $trailingOnes < 3) ? 1 : 0;

        // 依次编码 TrailingOnes 之后的每个 level（按逆扫描顺序）
        for ($i = $trailingOnes; $i < $totalCoeffs; $i++) {
            // 当前 level 的实际值（有符号）
            $val = $level[$i];

            // 规范 9.2.2：把有符号 level 映射为无符号 levelCode（levelCode = 2*|level| - (level>0?0:1)）
            $levelCode = ($val - 1) * 2;
            // 取符号（负数时 sign = -1，正数时 0），用于取绝对值
            $sign = $levelCode >> 31;
            // (x ^ sign) - sign 即取绝对值，再乘 2 后按符号加 1，得到 levelCode
            $levelCode = ($levelCode ^ $sign) + ($sign << 1);
            // 首个被编码的 level 且 TrailingOnes<3 时，levelCode 额外减 2（规范 9.2.2 的特例）
            $levelCode -= (($i == $trailingOnes) && ($trailingOnes < 3)) << 1;

            // level_prefix = levelCode >> suffixLength
            $levelPrefix = $levelCode >> $suffixLength;
            // 后缀位宽默认等于 suffixLength
            $levelSuffixSize = $suffixLength;
            // level_suffix = levelCode 的低 suffixLength 位
            $levelSuffix = $levelCode - ($levelPrefix << $suffixLength);

            // 规范 9.2.2 表 9-6 的第一类转义判断
            if ($suffixLength == 0 && $levelPrefix >= 14 && $levelPrefix < 30) {
                // sl==0 且 code∈[14,29]：prefix=14，4 位后缀
                $levelPrefix = 14;
                $levelSuffix = $levelCode - 14;
                $levelSuffixSize = 4;
            } else if ($suffixLength == 0 && $levelPrefix >= 30) {
                // sl==0 转义：基准 code=30，prefix=15 时 12 位后缀，溢出则递增 prefix
                $levelPrefix = 15;
                $levelSuffix = $levelCode - 30;
                $levelSuffixSize = 12;
                // 后缀超出 12 位可表示范围时，每溢出一次 prefix+1、后缀位宽+1（规范 9.2.2 的转义机制）
                while ($levelSuffix >= (1 << $levelSuffixSize)) {
                    $levelSuffix -= (1 << $levelSuffixSize);
                    $levelPrefix++;
                    $levelSuffixSize++;
                }
            } else if ($levelPrefix >= 15) {
                // sl>0 转义：基准 code=(15<<sl)，prefix=15 时 12 位后缀，
                // 超出 4096 需递增 prefix（16→13 位、17→14 位…），不能钳制
                $levelPrefix = 15;
                $levelSuffix = $levelCode - (15 << $suffixLength);
                $levelSuffixSize = 12;
                // 同样按溢出次数递增 prefix 与后缀位宽
                while ($levelSuffix >= (1 << $levelSuffixSize)) {
                    $levelSuffix -= (1 << $levelSuffixSize);
                    $levelPrefix++;
                    $levelSuffixSize++;
                }
            }

            // level_prefix 由 levelPrefix 个 0 加一个 1 构成，再加 suffix 位，总位长 = prefix+1+suffixSize
            $n = $levelPrefix + 1 + $levelSuffixSize;
            // 最高位固定为 1（prefix 的终止位），其后是 level_suffix
            $value = ((1 << $levelSuffixSize) | $levelSuffix);
            // 写出该 level
            $bits .= $this->u($value, $n);

            // suffixLength 自适应：先由 0 升到 1（规范 9.2.2 步骤）
            $suffixLength += ($suffixLength == 0) ? 1 : 0;
            // 阈值 = 3 << (suffixLength-1)，用于判断是否继续增大 suffixLength
            $threshold = 3 << ($suffixLength - 1);
            // |level| 超过阈值且 suffixLength<6 时递增（上限 6）
            if (($val > $threshold || $val < -$threshold) && $suffixLength < 6) {
                $suffixLength++;
            }
        }

        // 只有当块内存在零系数（TotalCoeff 小于总位置数）时才需要写 total_zeros（规范 9.2.3）
        if ($totalCoeffs < $endIdx + 1) {
            // 亮度块使用标准 total_zeros 码表
            if (!$isChromaDc) {
                $totalZerosEntry = self::VLC_TOTAL_ZEROS[$totalCoeffs][$totalZeros];
                // 码字位长
                $n = $totalZerosEntry[1];
                // 码字数值
                $value = $totalZerosEntry[0];
                // 写出 total_zeros
                $bits .= $this->u($value, $n);
            } else {
                // 色度 DC：TotalCoeff<4 时查专用码表
                if ($totalCoeffs < 4) {
                    $totalZerosEntry = self::VLC_TOTAL_ZEROS_CHROMA_DC[$totalCoeffs][$totalZeros];
                    // 码字位长
                    $n = $totalZerosEntry[1];
                    // 码字数值
                    $value = $totalZerosEntry[0];
                    // 写出 total_zeros
                    $bits .= $this->u($value, $n);
                } else {
                    // 色度 DC 且 TotalCoeff>=4 时，total_zeros 直接用 ue(v) 编码（规范 9.2.3）
                    $bits .= $this->ue($totalZeros);
                }
            }
        }

        // 剩余待编码的零个数，随 run_before 递减
        $zerosLeft = $totalZeros;
        // run_before：除最后一个 level 外，逐个写其前面的零游程；zerosLeft 为 0 时提前结束
        for ($i = 0; $i + 1 < $totalCoeffs && $zerosLeft > 0; $i++) {
            // 该 level 前紧邻的零游程
            $uirun = $run[$i];
            // 按 zerosLeft 选择码表行（规范表 9-10 的映射）
            $zeroLeft = self::ZERO_LEFT_MAP[$zerosLeft];
            // 查 run_before 码字
            $runBeforeEntry = self::VLC_RUN_BEFORE[$zeroLeft][$uirun];
            // 码字位长
            $n = $runBeforeEntry[1];
            // 码字数值
            $value = $runBeforeEntry[0];
            // 写出 run_before
            $bits .= $this->u($value, $n);
            // 更新剩余零个数
            $zerosLeft -= $uirun;
        }

        return $bits;
    }
}
