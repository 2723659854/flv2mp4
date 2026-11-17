<?php

namespace Xiaosongshu\Flv2mp4\Codec\Decode;

/**
 * @purpose 亮度色度残差解码
 * @author yanglong
 * @time 2026年7月23日15:18:35
 *
 * 本 trait 实现 H.264 残差块（residual block）的 CAVLC 熵解码与系数回填：
 *   - 语法结构：7.3.5.3 residual_block() / residual_block_cavlc()
 *   - 9.2.1 coeff_token 解析（TotalCoeff / TrailingOnes，需要 nC）
 *   - 9.2.2 level 解析（level_prefix / level_suffix，suffix_length 自适应）
 *   - 9.2.3 total_zeros 解析（表 9-7 用于 4x4，表 9-8 用于色度 DC）
 *   - 9.2.4 run_before 解析（把 level 按扫描顺序放回块内位置）
 * 所有方法返回的数组都按扫描顺序（下标 0 为最低频位置）保存系数，
 * 之后由变换/逆扫描流程（如 8.5 系列）继续处理。
 * 说明：CABAC 残差语法（9.3.3.1、9.3.3.2）不在此 trait 中实现，
 *       这里的码流读取统一通过 $this->reader 完成。
 */
trait ResidualDecodingTrait
{
    /**
     * CAVLC读取DC系数
     * @param int $nC 对于亮度DC使用专用表，对于色度DC使用nC=-1
     * @param int $maxCoeff 16（亮度）或 4（色度）
     *
     * 完整执行一次 7.3.5.3 的 residual_block_cavlc() 语法：
     *   coeff_token -> trailing_ones_sign_flag -> level_prefix/level_suffix
     *   -> total_zeros -> run_before
     * 返回长度 $maxCoeff 的系数数组（下标 0 为最低频/DC 位置）。
     */
    public function readCoeffsCAVLC(int $nC, int $maxCoeff): array
    {
        // 系数容器：初始化为 0，长度即本块系数总数（4x4 为 16，色度 DC 为 4）
        $coeffs = array_fill(0, $maxCoeff, 0);

        // TotalCoeff（非零系数个数）与 TrailingOnes（尾部 ±1 的个数）
        $totalCoeff = 0;
        $trailingOnes = 0;
        // 9.2.1：按 nC 选择码表并解出 TotalCoeff / TrailingOnes
        $this->readCoeffToken($totalCoeff, $trailingOnes, $nC);

        // 扫描顺序中最后一个非零系数的下标（TotalCoeff-1），level 从此处向前填充
        $lastNz = $totalCoeff - 1;

        // 7.3.5.3.1：trailing_ones_sign_flag，每个占 1 bit，1 表示负、0 表示正
        for ($i = 0; $i < $trailingOnes; $i++) {
            // 读取 1 bit 符号位
            $sign = $this->reader->readU(1);
            // 从最高频端开始放置 ±1
            $coeffs[$lastNz - $i] = $sign ? -1 : 1;
        }

        // 9.2.2：suffix_length 初值，TotalCoeff>10 且 TrailingOnes<3 时为 1，否则为 0
        $suffixLen = ($totalCoeff > 10 && $trailingOnes < 3) ? 1 : 0;
        // 还需要用 level_prefix/level_suffix 解码的 level 个数
        $rem = $totalCoeff - $trailingOnes;
        // 这些 level 的逆序填充起点（跳过已填好的 TrailingOnes 个 ±1）
        $revStart = $lastNz - $trailingOnes;
        // suffix_length 更新阈值表
        // 9.2.2 步骤 3 的判定阈值：|level| 超过阈值则 suffix_length 递增，最大到 6
        $suffixLimit = [0, 3, 6, 12, 24, 48, PHP_INT_MAX];

        // 从高频向低频逐个解码 level，直到 $rem 归零
        for ($i = $revStart; $i >= 0 && $rem > 0; $i--) {
            // 是否是第一个待解码 level（第一个 level 的码表规则与后续不同）
            $isFirst = ($rem === $totalCoeff - $trailingOnes);
            // level_prefix 用一元码表示：连续 0 的个数
            $pre = 0;
            // 防止死循环：最多读取32位前缀 (level_prefix = 连续0的个数)
            while ($pre < 32 && $this->reader->readU(1) === 0) $pre++;
            // 计算 level_code
            //$levelCode = 0;
            if ($isFirst && $suffixLen === 0) {
                // 第一个系数且 suffix_length==0 的特殊VLC结构
                // prefix<14：没有 suffix，level_code 直接等于 prefix
                if ($pre < 14) {
                    $levelCode = $pre;
                // prefix==14：额外读 4 bit suffix，level_code = 14 + suffix
                } elseif ($pre === 14) {
                    $suffix = $this->reader->readU(4);
                    $levelCode = 14 + $suffix;
                } else {
                    // prefix>=15：进入 escape 区间，基准值为 30
                    $lc = 30;
                    // prefix>=16 时用 2^(prefix-3) 扩展，并减去 4096 的固定偏置
                    if ($pre >= 16) {
                        $lc += (1 << ($pre - 3)) - 4096;
                    }
                    // 读取 (prefix-3) bit 的 suffix
                    $suffix = $this->reader->readU($pre - 3);
                    $levelCode = $lc + $suffix;
                }
            } else {
                // 后续系数 或 第一个且 suffix_length==1
                // prefix<15：常规码，level_code = prefix * 2^suffixLength + suffix
                if ($pre < 15) {
                    // suffix_length 为 0 时没有 suffix 位
                    $suffix = ($suffixLen > 0) ? $this->reader->readU($suffixLen) : 0;
                    $levelCode = $pre * (1 << $suffixLen) + $suffix;
                } else {
                    // prefix>=15：基准 15*2^suffixLength，再按 escape 规则扩展
                    $lc = 15 * (1 << $suffixLen);
                    if ($pre >= 16) {
                        $lc += (1 << ($pre - 3)) - 4096;
                    }
                    $suffix = $this->reader->readU($pre - 3);
                    $levelCode = $lc + $suffix;
                }
            }
            // 对第一个 level 应用 trailing_ones<3 的 +2 偏移
            // 先保留原始码值
            $adjustedCode = $levelCode;
            // 9.2.2 步骤 2：TrailingOnes<3 时第一个 level 的码值整体 +2
            if ($isFirst && $trailingOnes < 3) $adjustedCode += 2;
            // 将 level_code 转换为带符号 level
            // 最低位即符号位，mask 用于把正负两种情形统一到同一个位运算公式
            $mask = -($adjustedCode & 1);
            // 等价于规范公式 level = (偶 ? code+2 : -code-1) / 2 的位运算写法
            $level = (($adjustedCode + 2) >> 1) ^ $mask;
            $level = $level - $mask;
            // 写入对应的扫描位置
            $coeffs[$i] = $level;
            // 更新 suffix_length (参考 SUFFIX_LIMIT 表)
            $absLvl = abs($level);
            if ($isFirst) {
                // 第一个 level 之后: suffixLen = 1 + (|level| > 3)
                $suffixLen = ($absLvl > 3) ? 2 : 1;
            } else {
                // 后续 level：|level| 超过当前 suffix_length 的阈值则加 1，上限 6
                if ($suffixLen < 6 && $absLvl > $suffixLimit[$suffixLen]) {
                    $suffixLen++;
                }
            }
            // 已解出一个 level
            $rem--;
        }

        // 9.2.3 / 9.2.4：只有 TotalCoeff<maxCoeff 时才存在 total_zeros 与 run_before
        if ($totalCoeff < $maxCoeff) {
            // 解码块内剩余零的总数
            $totalZeros = $this->readTotalZeros($totalCoeff, $maxCoeff);
            // 尚未分配的剩余零数，run_before 会逐步扣减
            $zl = $totalZeros;
            // 最高频非零系数在扫描序列中的绝对位置
            $coeffIdx = $totalCoeff + $totalZeros - 1;

            // 用临时数组承接按扫描位置重排后的系数
            $tmpCoeffs = array_fill(0, $maxCoeff, 0);
            // 从已解出的系数数组最高频端开始取
            $nzIdx = $lastNz;
            // 从最高频非零系数开始，逐个向前摆放
            for ($i = 0; $i < $totalCoeff; $i++) {
                $isLast = ($i == $totalCoeff - 1);
                //$rb = 0;
                if (!$isLast && $zl > 0) {
                    // 仍有零可分配，读取 run_before 码字（9.2.4）
                    $rb = $this->readRunBefore($zl);
                } elseif (!$isLast) {
                    // 剩余零已为 0，run 必为 0，无需读码字
                    $rb = 0;
                } else {
                    // 最后一个系数：run 就是剩余零数，不读 run_before
                    $rb = $zl;
                }

                // 把该 level 放到对应扫描位置
                $tmpCoeffs[$coeffIdx] = $coeffs[$nzIdx];

                // 扣减本次消费掉的零
                $zl -= $rb;

                if (!$isLast) {
                    // 位置前移 (run+1)：跨过 run 个零和当前系数
                    $coeffIdx -= ($rb + 1);
                    $nzIdx--;
                }
            }

            // 用重排结果替换原数组
            $coeffs = $tmpCoeffs;
        }

        // 返回扫描顺序（低频在前）的系数数组
        return $coeffs;
    }

    /**
     * CAVLC 解码亮度 DC 块（16 个系数，如 Intra16x16 的亮度 DC 块）
     * 采用逐位累积前缀匹配 coeff_token 码字的方式（等价于 9.2.1 查表），
     * 随后按 9.2.2 / 9.2.3 / 9.2.4 解码 level、total_zeros 与 run_before。
     * @return array 长度 16 的扫描顺序系数数组
     */
    public function readLumaDcCoeffs(): array
    {
        // 亮度 DC 块固定 16 个系数
        $coeffs = array_fill(0, 16, 0);

        // coeff_token 的两个输出：非零系数个数与尾部 ±1 个数
        $totalCoeff = 0;
        $trailingOnes = 0;

        // 逐位累积已读入的比特，用于前缀匹配
        $bits = 0;
        // 码字最长 16 bit，边读边判
        for ($i = 0; $i < 16; $i++) {
            // 左移一位，把新读的 bit 放到最低位
            $bits = ($bits << 1) | $this->reader->readU(1);

            // 以下每个分支用掩码截取最近读入的若干位并与码字比较；
            // 命中即确定 (TotalCoeff, TrailingOnes) 并结束匹配
            if (($bits & 0xFFFE) === 0x0002) { // TotalCoeff=1, TrailingOnes=1
                $totalCoeff = 1;
                $trailingOnes = 1;
                break;
            } elseif (($bits & 0xFFF8) === 0x0008) { // TotalCoeff=2, TrailingOnes=2
                $totalCoeff = 2;
                $trailingOnes = 2;
                break;
            } elseif (($bits & 0xFFF0) === 0x0020) { // TotalCoeff=2, TrailingOnes=1
                $totalCoeff = 2;
                $trailingOnes = 1;
                break;
            } elseif (($bits & 0xFFE0) === 0x0080) { // TotalCoeff=2, TrailingOnes=0
                $totalCoeff = 2;
                $trailingOnes = 0;
                break;
            } elseif (($bits & 0xFFC0) === 0x0200) { // TotalCoeff=3, TrailingOnes=3
                $totalCoeff = 3;
                $trailingOnes = 3;
                break;
            } elseif (($bits & 0xFF80) === 0x0800) { // TotalCoeff=3, TrailingOnes=2
                $totalCoeff = 3;
                $trailingOnes = 2;
                break;
            } elseif (($bits & 0xFF00) === 0x2000) { // TotalCoeff=3, TrailingOnes=1
                $totalCoeff = 3;
                $trailingOnes = 1;
                break;
            } elseif (($bits & 0xFE00) === 0x8000) { // TotalCoeff=3, TrailingOnes=0
                $totalCoeff = 3;
                $trailingOnes = 0;
                break;
            } elseif (($bits & 0xFC00) === 0x4000) { // TotalCoeff=4, TrailingOnes=3
                $totalCoeff = 4;
                $trailingOnes = 3;
                break;
            } elseif (($bits & 0xF800) === 0x1000) { // TotalCoeff=4, TrailingOnes=2
                $totalCoeff = 4;
                $trailingOnes = 2;
                break;
            } elseif (($bits & 0xF000) === 0x0800) { // TotalCoeff=4, TrailingOnes=1
                $totalCoeff = 4;
                $trailingOnes = 1;
                break;
            } elseif (($bits & 0xE000) === 0x0400) { // TotalCoeff=4, TrailingOnes=0
                $totalCoeff = 4;
                $trailingOnes = 0;
                break;
            } elseif (($bits & 0xC000) === 0x0200) { // TotalCoeff=5, TrailingOnes=3
                $totalCoeff = 5;
                $trailingOnes = 3;
                break;
            } elseif (($bits & 0x8000) === 0x0100) { // TotalCoeff=5, TrailingOnes=2
                $totalCoeff = 5;
                $trailingOnes = 2;
                break;
            } elseif (($bits & 0x0000) === 0x0001) { // TotalCoeff=5, TrailingOnes=1
                $totalCoeff = 5;
                $trailingOnes = 1;
                break;
            } elseif (($bits & 0x0000) === 0x0004) { // TotalCoeff=5, TrailingOnes=0
                $totalCoeff = 5;
                $trailingOnes = 0;
                break;
            } elseif (($bits & 0x0000) === 0x0002) { // TotalCoeff=6, TrailingOnes=0
                $totalCoeff = 6;
                $trailingOnes = 0;
                break;
            } elseif (($bits & 0x0000) === 0x0008) { // TotalCoeff=7, TrailingOnes=0
                $totalCoeff = 7;
                $trailingOnes = 0;
                break;
            } elseif (($bits & 0x0000) === 0x0020) { // TotalCoeff=8, TrailingOnes=0
                $totalCoeff = 8;
                $trailingOnes = 0;
                break;
            } elseif (($bits & 0x0000) === 0x0080) { // TotalCoeff=9, TrailingOnes=0
                $totalCoeff = 9;
                $trailingOnes = 0;
                break;
            } elseif (($bits & 0x0000) === 0x0200) { // TotalCoeff=10, TrailingOnes=0
                $totalCoeff = 10;
                $trailingOnes = 0;
                break;
            } elseif (($bits & 0x0000) === 0x0800) { // TotalCoeff=11, TrailingOnes=0
                $totalCoeff = 11;
                $trailingOnes = 0;
                break;
            } elseif (($bits & 0x0000) === 0x2000) { // TotalCoeff=12, TrailingOnes=0
                $totalCoeff = 12;
                $trailingOnes = 0;
                break;
            } elseif (($bits & 0x0000) === 0x8000) { // TotalCoeff=13, TrailingOnes=0
                $totalCoeff = 13;
                $trailingOnes = 0;
                break;
            } elseif (($bits & 0x0001) === 0x0000) { // TotalCoeff=14, TrailingOnes=0
                $totalCoeff = 14;
                $trailingOnes = 0;
                break;
            } elseif (($bits & 0x0004) === 0x0000) { // TotalCoeff=15, TrailingOnes=0
                $totalCoeff = 15;
                $trailingOnes = 0;
                break;
            } elseif (($bits & 0x0010) === 0x0000) { // TotalCoeff=16, TrailingOnes=0
                $totalCoeff = 16;
                $trailingOnes = 0;
                break;
            } elseif (($bits & 0xFFFC) === 0x0004) { // TotalCoeff=1, TrailingOnes=0
                $totalCoeff = 1;
                $trailingOnes = 0;
                break;
            } elseif (($bits & 0xFFF8) === 0x0000) { // TotalCoeff=0, TrailingOnes=0
                $totalCoeff = 0;
                $trailingOnes = 0;
                break;
            }
        }

        // TotalCoeff==0：整块为零，直接返回零块
        if ($totalCoeff === 0) return $coeffs;

        // 最后一个非零系数的扫描位置
        $lastNz = $totalCoeff - 1;

        // 7.3.5.3.1：读取 TrailingOnes 个符号位，1 表示负
        for ($i = 0; $i < $trailingOnes; $i++) {
            $idx = $lastNz - $i;
            $sign = $this->reader->readU(1);
            $coeffs[$idx] = $sign ? -1 : 1;
        }

        // 9.2.2：suffix_length 初值规则
        $suffixLen = ($totalCoeff > 10 && $trailingOnes < 3) ? 1 : 0;
        // 剩余待解码的 level 数量
        $rem = $totalCoeff - $trailingOnes;
        // 逆序填充的起点
        $revStart = $lastNz - $trailingOnes;

        // 从高频向低频逐个解码 level
        for ($i = $revStart; $i >= 0 && $rem > 0; $i--) {
            // level_prefix：连续 0 的个数
            $pre = 0;
            while ($pre < 32 && $this->reader->readU(1) === 0) $pre++;

            // 第一个 level 的判定
            $isFirst = ($rem === $totalCoeff - $trailingOnes);

            //$levelCode = 0;
            if ($isFirst && $suffixLen === 0) {
                // 第一个 level 且 suffix_length==0 的特殊 VLC
                if ($pre < 14) {
                    $levelCode = $pre;
                } elseif ($pre === 14) {
                    // 读 4 bit suffix
                    $suffix = $this->reader->readU(4);
                    $levelCode = 14 + $suffix;
                } else {
                    // escape：读取 (prefix-3) bit suffix 并加到基准 30 上
                    $suffixBits = $pre - 3;
                    $suffix = $this->reader->readU($suffixBits);
                    $levelCode = 30 + $suffix;
                }
            } else {
                // 常规码：level_code = 2^level_prefix + suffix
                $levelCode = (1 << $pre);
                if ($suffixLen > 0) {
                    $levelCode += $this->reader->readU($suffixLen);
                }
            }

            // 第一个 level 的 +2 偏移（TrailingOnes<3 时）
            $adjustedCode = $levelCode;
            if ($isFirst && $trailingOnes < 3) $adjustedCode += 2;

            // 位运算形式的有符号 level 还原（最低位为符号位）
            $mask = -($adjustedCode & 1);
            $level = (($adjustedCode + 2) >> 1) ^ $mask;
            $level = $level - $mask;
            $coeffs[$i] = $level;

            // 更新 suffix_length 时需要 |level|
            $absLvl = abs($level);

            // 已解出一个 level
            $rem--;

            // 第一个 level 之后 suffix_length 从 1 起（9.2.2）
            if ($suffixLen === 0) $suffixLen = 1;
            // 后续 level：|level| >= 2^(suffixLen+1) 时加 1，上限 6
            while ($suffixLen < 6 && $absLvl >= (1 << ($suffixLen + 1))) $suffixLen++;
        }

        // 9.2.3 / 9.2.4：TotalCoeff<16 时还需读取 total_zeros 与 run_before
        if ($totalCoeff < 16) {
            $totalZeros = $this->readTotalZeros($totalCoeff, 16);
            // 剩余待分配零数
            $zl = $totalZeros;
            // 当前摆放位置，从最高频非零位置开始
            $lastPos = $lastNz;

            // 重排结果容器
            $tmpCoeffs = array_fill(0, 16, 0);
            // 已解出系数的高频端下标
            $nzIdx = $lastNz;

            for ($i = 0; $i < $totalCoeff; $i++) {
                if ($zl > 0) {
                    // 读取 run_before 并扣减剩余零
                    $rb = $this->readRunBefore($zl);
                    $lastPos -= ($rb + 1);
                    $zl -= $rb;
                }

                // 摆放系数
                $tmpCoeffs[$lastPos] = $coeffs[$nzIdx];
                $nzIdx--;
                // 下一个非零位置前移一格（不能越界）
                if ($lastPos > 0) $lastPos--;
            }

            // 替换为重排后的结果
            $coeffs = $tmpCoeffs;
        }

        // 返回扫描顺序的系数数组
        return $coeffs;
    }

    /**
     * CAVLC 解码色度 DC 块（2x2，共 4 个系数，nC = -1，对应表 9-9）
     * 先按固定码表匹配 coeff_token，再依次解码 level、total_zeros、run_before。
     * @return array 长度 4 的扫描顺序系数数组
     */
    public function readChromaDcCoeffs(): array
    {
        // 色度 DC 块固定 4 个系数
        $coeffs = array_fill(0, 4, 0);

        // 色度DC coeff_token VLC表
        // 索引方式: [totalCoeff][trailingOnes] -> [bits_value, length]
        // 9.2.1：色度 DC 使用表 9-9，键为码字的二进制字符串，值为 [TotalCoeff, TrailingOnes]
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

        // 已读比特串（用于与上表逐前缀比较）
        $bits = '';
        // coeff_token 的两个输出
        $totalCoeff = 0;
        $trailingOnes = 0;

        // 最长码字为8位 ("00000011" / "00000010")
        for ($i = 0; $i < 8; $i++) {
            // 追加 1 bit 并立即查表（前缀码特性保证一旦命中即为最终结果）
            $bits .= $this->reader->readU(1);
            if (isset($chromaDcTokenMap[$bits])) {
                list($totalCoeff, $trailingOnes) = $chromaDcTokenMap[$bits];
                break;
            }
        }

        // TotalCoeff==0：整块为零
        if ($totalCoeff === 0) return $coeffs;

        // 最后一个非零系数的扫描位置
        $lastNz = $totalCoeff - 1;

        // trailing_ones: 从高频端开始读取符号位
        for ($i = 0; $i < $trailingOnes; $i++) {
            $idx = $lastNz - $i;
            $sign = $this->reader->readU(1);
            $coeffs[$idx] = $sign ? -1 : 1;
        }

        // level解码: 与readCoeffsCAVLC使用完全相同的逻辑
        // 9.2.2：suffix_length 初值（色度 DC 最多 4 个系数，条件实际上不会成立）
        $suffixLen = ($totalCoeff > 10 && $trailingOnes < 3) ? 1 : 0;
        // 剩余待解码 level 数
        $rem = $totalCoeff - $trailingOnes;
        // 逆序填充起点
        $revStart = $lastNz - $trailingOnes;
        // suffix_length 更新阈值表
        $suffixLimit = [0, 3, 6, 12, 24, 48, PHP_INT_MAX];

        // 从高频向低频逐个解码 level
        for ($i = $revStart; $i >= 0 && $rem > 0; $i--) {
            // 第一个 level 判定
            $isFirst = ($rem === $totalCoeff - $trailingOnes);
            // level_prefix：连续 0 的个数
            $pre = 0;
            while ($pre < 32 && $this->reader->readU(1) === 0) $pre++;

            //$levelCode = 0;
            if ($isFirst && $suffixLen === 0) {
                // 第一个level且suffix_length==0的特殊VLC
                if ($pre < 14) {
                    $levelCode = $pre;
                } elseif ($pre === 14) {
                    // 额外 4 bit suffix
                    $suffix = $this->reader->readU(4);
                    $levelCode = 14 + $suffix;
                } else {
                    // escape 区间：基准 30 + (prefix>=16 时的扩展) + suffix
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
                    // 常规码：prefix * 2^suffixLength + suffix
                    $suffix = ($suffixLen > 0) ? $this->reader->readU($suffixLen) : 0;
                    $levelCode = $pre * (1 << $suffixLen) + $suffix;
                } else {
                    // escape 区间
                    $lc = 15 * (1 << $suffixLen);
                    if ($pre >= 16) {
                        $lc += (1 << ($pre - 3)) - 4096;
                    }
                    $suffix = $this->reader->readU($pre - 3);
                    $levelCode = $lc + $suffix;
                }
            }

            // 第一个 level 的 +2 偏移
            $adjustedCode = $levelCode;
            if ($isFirst && $trailingOnes < 3) $adjustedCode += 2;

            // 位运算还原带符号 level（最低位为符号位）
            $mask = -($adjustedCode & 1);
            $level = (($adjustedCode + 2) >> 1) ^ $mask;
            $level = $level - $mask;
            $coeffs[$i] = $level;

            // 更新suffix_length (与readCoeffsCAVLC一致)
            $absLvl = abs($level);
            if ($isFirst) {
                // 第一个 level 之后固定取 1 或 2
                $suffixLen = ($absLvl > 3) ? 2 : 1;
            } else {
                // 超过阈值则递增，上限 6
                if ($suffixLen < 6 && $absLvl > $suffixLimit[$suffixLen]) {
                    $suffixLen++;
                }
            }
            $rem--;
        }

        // total_zeros + run_before (与readCoeffsCAVLC完全相同的逻辑)
        // 9.2.3 / 9.2.4：TotalCoeff<4 时才有零需要分配
        if ($totalCoeff < 4) {
            // 色度 DC 的 total_zeros 查表 9-8
            $totalZeros = $this->readTotalZeros($totalCoeff, 4);
            // 剩余待分配零数
            $zl = $totalZeros;
            // 最高频非零系数的扫描位置
            $coeffIdx = $totalCoeff + $totalZeros - 1;

            // 重排结果容器
            $tmpCoeffs = array_fill(0, 4, 0);
            // 已解出系数的高频端下标
            $nzIdx = $lastNz;

            for ($i = 0; $i < $totalCoeff; $i++) {
                $isLast = ($i == $totalCoeff - 1);
                //$rb = 0;
                if (!$isLast && $zl > 0) {
                    // 仍有零可分配，读 run_before
                    $rb = $this->readRunBefore($zl);
                } elseif (!$isLast) {
                    // 无剩余零，run 为 0
                    $rb = 0;
                } else {
                    // 最后一个系数: 消费所有剩余零, 不读取run_before
                    $rb = $zl;
                }

                // 按扫描位置摆放
                $tmpCoeffs[$coeffIdx] = $coeffs[$nzIdx];

                // 扣减已消费零
                $zl -= $rb;

                if (!$isLast) {
                    // 前移 (run+1)
                    $coeffIdx -= ($rb + 1);
                    $nzIdx--;
                }
            }

            // 替换为重排结果
            $coeffs = $tmpCoeffs;
        }

        // 返回扫描顺序的系数数组
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
     *
     * 9.2.1：nC 由左邻块的非零系数数 nA 与上邻块的非零系数数 nB 推导：
     *   两者都可用 -> (nA + nB + 1) >> 1；仅一方可用 -> 取该方；都不可用 -> 0。
     * 4x4 亮度按光栅序（0..15）索引，色度 Cb/Cr 各 2x2 块（16..19 / 20..23）。
     */
    public function computeNc(int $rasterIdx, int $mbX, int $mbY, array $nzCache, array $leftNz, array $topNz, bool $leftAvailable, bool $topAvailable): int
    {
        // 亮度 4x4 块（0..15）
        if ($rasterIdx < 16) {
            // 块在宏块内的列、行（4x4 光栅序）
            $blkX = $rasterIdx % 4;
            $blkY = (int)($rasterIdx / 4);

            // 左邻：优先取本宏块内已解码块，边界处取左邻宏块
            $left = null;
            if ($blkX > 0) {
                // 同一宏块内左侧块
                $left = $nzCache[$rasterIdx - 1];
            } elseif ($leftAvailable) {
                // 宏块最左列：取左邻宏块对应行
                $left = $leftNz[$blkY];
            }

            // 上邻：优先取本宏块内已解码块，边界处取上邻宏块
            $top = null;
            if ($blkY > 0) {
                // 同一宏块内上方块（光栅序相差 4）
                $top = $nzCache[$rasterIdx - 4];
            } elseif ($topAvailable) {
                // 宏块最上行：换算成整帧亮度的绝对列号再查上邻缓存
                $absBlkX = $mbX * 4 + $blkX;
                $top = $topNz[$absBlkX];
            }
        // 色度 Cb 块（16..19，2x2）
        } elseif ($rasterIdx < 20) {
            $cbIdx = $rasterIdx - 16;
            // 2x2 块内的列、行
            $blkX = $cbIdx % 2;
            $blkY = (int)($cbIdx / 2);

            // 左邻
            $left = null;
            if ($blkX > 0) {
                // 同一宏块内左侧 Cb 块
                $left = $nzCache[$rasterIdx - 1];
            } elseif ($leftAvailable) {
                // 左邻宏块的 Cb 块（leftNz 中 Cb 从下标 4 开始）
                $left = $leftNz[4 + $blkY];
            }

            // 上邻
            $top = null;
            if ($blkY > 0) {
                // 同一宏块内上方 Cb 块（2x2，光栅序相差 2）
                $top = $nzCache[$rasterIdx - 2];
            } elseif ($topAvailable) {
                // 上邻宏块：Cb 区域位于亮度 16 行之后
                $absBlkX = $mbX * 2 + $blkX;
                $top = $topNz[$this->picWidthInMbs * 4 + $absBlkX];
            }
        } else {
            // 色度 Cr 块（20..23，2x2）
            $crIdx = $rasterIdx - 20;
            $blkX = $crIdx % 2;
            $blkY = (int)($crIdx / 2);

            // 左邻
            $left = null;
            if ($blkX > 0) {
                // 同一宏块内左侧 Cr 块
                $left = $nzCache[$rasterIdx - 1];
            } elseif ($leftAvailable) {
                // 左邻宏块的 Cr 块（leftNz 中 Cr 从下标 6 开始）
                $left = $leftNz[6 + $blkY];
            }

            // 上邻
            $top = null;
            if ($blkY > 0) {
                // 同一宏块内上方 Cr 块
                $top = $nzCache[$rasterIdx - 2];
            } elseif ($topAvailable) {
                // 上邻宏块：Cr 区域位于亮度 16 行 + Cb 8 行之后
                $absBlkX = $mbX * 2 + $blkX;
                $top = $topNz[$this->picWidthInMbs * 4 + $this->picWidthInMbs * 2 + $absBlkX];
            }
        }

        // 9.2.1：nC 的推导规则
        if ($left !== null && $top !== null) {
            // 两侧都可用：(nA + nB + 1) >> 1，等价于向上取整的平均
            return (int)(($left + $top + 1) >> 1);
        } elseif ($left !== null) {
            // 仅左邻可用
            return (int)$left;
        } elseif ($top !== null) {
            // 仅上邻可用
            return (int)$top;
        } else {
            // 都不可用：nC 取 0
            return 0;
        }
    }

    /**
     * CAVLC 解码 coeff_token（9.2.1）
     * nC == -1 时使用色度 DC 表（表 9-9）；否则按 nC 区间选取 4x4 码表，
     * nC 大于 16 时按 16 处理（即使用定长 6 bit 的 FLC 表）。
     * @param int $totalCoeff 输出：非零系数个数 TotalCoeff
     * @param int $trailingOnes 输出：尾部 ±1 的个数 TrailingOnes
     * @param int $nC 邻块预测得到的非零系数均值
     */
    public function readCoeffToken(int &$totalCoeff, int &$trailingOnes, int $nC): void
    {
        // coeff_token 码表（nC∈[0,2)）：按 4*TotalCoeff+TrailingOnes 排列的码长
        static $COEFF_TOKEN_LEN_0 = [
            1, 0, 0, 0, 6, 2, 0, 0, 8, 6, 3, 0, 9, 8, 7, 5, 10, 9, 8, 6, 11, 10, 9, 7, 13, 11, 10, 8, 13,
            13, 11, 9, 13, 13, 13, 10, 14, 14, 13, 11, 14, 14, 14, 13, 15, 15, 14, 14, 15, 15, 15, 14, 16,
            15, 15, 15, 16, 16, 16, 15, 16, 16, 16, 16, 16, 16, 16, 16,
        ];
        // 同一码表的码字（右对齐的 bit 值，0 表示该条目不存在）
        static $COEFF_TOKEN_BITS_0 = [
            1, 0, 0, 0, 5, 1, 0, 0, 7, 4, 1, 0, 7, 6, 5, 3, 7, 6, 5, 3, 7, 6, 5, 4, 15, 6, 5, 4, 11, 14, 5,
            4, 8, 10, 13, 4, 15, 14, 9, 4, 11, 10, 13, 12, 15, 14, 9, 12, 11, 10, 13, 8, 15, 1, 9, 12, 11,
            14, 13, 8, 7, 10, 9, 12, 4, 6, 5, 8,
        ];

        // coeff_token 码表（nC∈[2,4)）码长
        static $COEFF_TOKEN_LEN_1 = [
            2, 0, 0, 0, 6, 2, 0, 0, 6, 5, 3, 0, 7, 6, 6, 4, 8, 6, 6, 4, 8, 7, 7, 5, 9, 8, 8, 6, 11, 9, 9,
            6, 11, 11, 11, 7, 12, 11, 11, 9, 12, 12, 12, 11, 12, 12, 12, 11, 13, 13, 13, 12, 13, 13, 13,
            13, 13, 14, 13, 13, 14, 14, 14, 13, 14, 14, 14, 14,
        ];
        // 对应码字
        static $COEFF_TOKEN_BITS_1 = [
            3, 0, 0, 0, 11, 2, 0, 0, 7, 7, 3, 0, 7, 10, 9, 5, 7, 6, 5, 4, 4, 6, 5, 6, 7, 6, 5, 8, 15, 6, 5,
            4, 11, 14, 13, 4, 15, 10, 9, 4, 11, 14, 13, 12, 8, 10, 9, 8, 15, 14, 13, 12, 11, 10, 9, 12, 7,
            11, 6, 8, 9, 8, 10, 1, 7, 6, 5, 4,
        ];

        // coeff_token 码表（nC∈[4,8)）码长
        static $COEFF_TOKEN_LEN_2 = [
            4, 0, 0, 0, 6, 4, 0, 0, 6, 5, 4, 0, 6, 5, 5, 4, 7, 5, 5, 4, 7, 5, 5, 4, 7, 6, 6, 4, 7, 6, 6, 4,
            8, 7, 7, 5, 8, 8, 7, 6, 9, 8, 8, 7, 9, 9, 8, 8, 9, 9, 9, 8, 10, 9, 9, 9, 10, 10, 10, 10, 10,
            10, 10, 10, 10, 10, 10, 10,
        ];
        // 对应码字
        static $COEFF_TOKEN_BITS_2 = [
            15, 0, 0, 0, 15, 14, 0, 0, 11, 15, 13, 0, 8, 12, 14, 12, 15, 10, 11, 11, 11, 8, 9, 10, 9, 14,
            13, 9, 8, 10, 9, 8, 15, 14, 13, 13, 11, 14, 10, 12, 15, 10, 13, 12, 11, 14, 9, 12, 8, 10, 13,
            8, 13, 7, 9, 12, 9, 12, 11, 10, 5, 8, 7, 6, 1, 4, 3, 2,
        ];

        // coeff_token 码表（nC>=8）：6 bit 定长 FLC，码长恒为 6
        static $COEFF_TOKEN_LEN_3 = [
            6, 0, 0, 0, 6, 6, 0, 0, 6, 6, 6, 0, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6,
            6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6,
            6, 6, 6, 6,
        ];
        // FLC 的码字即条目下标本身
        static $COEFF_TOKEN_BITS_3 = [
            3, 0, 0, 0, 0, 1, 0, 0, 4, 5, 6, 0, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22,
            23, 24, 25, 26, 27, 28, 29, 30, 31, 32, 33, 34, 35, 36, 37, 38, 39, 40, 41, 42, 43, 44, 45, 46,
            47, 48, 49, 50, 51, 52, 53, 54, 55, 56, 57, 58, 59, 60, 61, 62, 63,
        ];

        // 色度 DC（nC=-1）码表（表 9-9）：20 个条目，按 4*TotalCoeff+TrailingOnes 排列
        static $CHROMA_DC_COEFF_TOKEN_LEN = [2, 0, 0, 0, 6, 1, 0, 0, 6, 6, 3, 0, 6, 7, 7, 6, 6, 8, 8, 7];
        static $CHROMA_DC_COEFF_TOKEN_BITS = [1, 0, 0, 0, 7, 1, 0, 0, 4, 6, 1, 0, 3, 3, 2, 5, 2, 3, 2, 0];

        // nC（已 clamp 到 0..16）到码表下标的映射：0-1->0, 2-3->1, 4-7->2, 8 及以上->3
        static $COEFF_TOKEN_TABLE_INDEX = [0, 0, 1, 1, 2, 2, 2, 2, 3, 3, 3, 3, 3, 3, 3, 3, 3];

        // 先清零输出参数，任何失败路径都保持 0/0（等价于 TotalCoeff=0 的零块）
        $totalCoeff = 0;
        $trailingOnes = 0;

        if ($nC == -1) {
            // nC == -1：色度 DC 块使用固定表 9-9
            $lens = $CHROMA_DC_COEFF_TOKEN_LEN;
            $bitsTab = $CHROMA_DC_COEFF_TOKEN_BITS;
            // 20 个条目 = 5 个 TotalCoeff 值 × 最多 4 个 TrailingOnes
            $count = 20;
            // 该表最长码字 8 bit
            $maxBits = 8;
        } else {
            // 其它情况：nC 截断到 16 后按区间选表
            $ncClamped = min($nC, 16);
            $tableIdx = $COEFF_TOKEN_TABLE_INDEX[$ncClamped];

            switch ($tableIdx) {
                case 0:
                    // nC∈[0,2)：最长码字 16 bit
                    $lens = $COEFF_TOKEN_LEN_0;
                    $bitsTab = $COEFF_TOKEN_BITS_0;
                    $count = 68;
                    $maxBits = 16;
                    break;
                case 1:
                    // nC∈[2,4)：最长码字 14 bit
                    $lens = $COEFF_TOKEN_LEN_1;
                    $bitsTab = $COEFF_TOKEN_BITS_1;
                    $count = 68;
                    $maxBits = 14;
                    break;
                case 2:
                    // nC∈[4,8)：最长码字 10 bit
                    $lens = $COEFF_TOKEN_LEN_2;
                    $bitsTab = $COEFF_TOKEN_BITS_2;
                    $count = 68;
                    $maxBits = 10;
                    break;
                case 3:
                    // nC>=8：6 bit 定长 FLC
                    $lens = $COEFF_TOKEN_LEN_3;
                    $bitsTab = $COEFF_TOKEN_BITS_3;
                    $count = 68;
                    $maxBits = 6;
                    break;
                default:
                    // 不可达分支：保持 TotalCoeff/TrailingOnes 为 0
                    return;
            }
        }

        // 一次性预取最长码字，避免逐 bit 读取
        $peeked = $this->reader->peek($maxBits);

        // 每进程缓存：码长 -> 码字 -> 条目下标
        static $lookupCache = [];
        // 缓存键：色度 DC 用 -1，其余用码表下标
        $lookupKey = $nC == -1 ? -1 : $tableIdx;
        if (!isset($lookupCache[$lookupKey])) {
            // 首次使用时把两张并行表合并成「码长 -> 码字 -> 下标」结构
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

        // 9.2.1 查表：从最长码长向短尝试（前缀码唯一性保证正确匹配）
        for ($len = $maxBits; $len > 0; $len--) {
            if (!isset($lookup[$len])) continue;
            // 取预取数据的高 len 位作为候选码字
            $code = $peeked >> ($maxBits - $len);
            if (isset($lookup[$len][$code])) {
                $index = $lookup[$len][$code];
                // 消费该码字对应的比特数
                $this->reader->skip($len);
                // 条目下标 = 4*TotalCoeff + TrailingOnes
                $totalCoeff = intdiv($index, 4);
                $trailingOnes = $index & 3;
                return;
            }
        }

        // 未命中任何码字（异常码流）：退化为零块
        $totalCoeff = 0;
        $trailingOnes = 0;
    }

    /**
     * CAVLC 解码 total_zeros（9.2.3）
     * 4x4 块查表 9-7，色度 DC（maxCoeff=4）查表 9-8；
     * 用预生成的定宽查找表把「peek 出的比特模式」直接映射为 [zero 个数, 码长]。
     * @param int $totalCoeff 已解码的非零系数个数
     * @param int $maxCoeff 本块系数总数（16 或 4）
     * @return int 块内剩余零的总数
     */
    public function readTotalZeros(int $totalCoeff, int $maxCoeff): int
    {
        // 块内最多可能的零个数
        $maxZeros = $maxCoeff - $totalCoeff;
        // 系数已占满，没有 zero 需要解码
        if ($maxZeros === 0) return 0;

        // 快速路径：预生成的定宽查找表（索引=peek出的比特值，值=[zero数, 码长]）
        static $LUT = null;
        if ($LUT === null) {
            $LUT = self::buildTotalZerosLut();
        }
        if ($maxCoeff == 4) {
            // 色度 DC：表 9-8 按 TotalCoeff-1 选行，最长码字 3 bit
            $tableIdx = $totalCoeff - 1;
            if ($tableIdx < 0 || $tableIdx > 2) return 0;
            // 预取 3 bit
            $peeked = $this->reader->peek(3);
            // 查表得到 [zero 个数, 码长]
            $e = $LUT['c' . $tableIdx][$peeked];
            if ($e[1] !== 0) {
                // 码长非 0 表示命中合法码字，消费对应 bit 数
                $this->reader->skip($e[1]);
                return $e[0];
            }
            return 0;
        }
        // 4x4 块：表 9-7 按 TotalCoeff-1 选行（TotalCoeff 取值 1..15）
        $tableIdx = $totalCoeff - 1;
        if ($tableIdx < 0 || $tableIdx > 14) return 0;
        // 表 9-7 最长码字 9 bit
        $peeked = $this->reader->peek(9);
        $e = $LUT['l' . $tableIdx][$peeked];
        if ($e[1] !== 0) {
            // 命中后跳过码长
            $this->reader->skip($e[1]);
            return $e[0];
        }
        return 0;
    }

    /** 预生成 total_zeros 定宽查找表（每进程一次） */
    private function buildTotalZerosLut(): array
    {
        // 表 9-7 各行码长（行下标 = TotalCoeff-1，列下标 = total_zeros 取值）
        $LENS = [
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

        // 表 9-7 各行码字（与上面的码长一一对应）
        $BITS = [
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

        // 表 9-8（色度 DC）：行下标 = TotalCoeff-1，列下标 = total_zeros
        $CHROMA_LEN = [[1, 2, 3, 3], [1, 2, 2, 0], [1, 1, 0, 0]];
        $CHROMA_BITS = [[1, 1, 1, 0], [1, 1, 0, 0], [1, 0, 0, 0]];

        $lut = [];
        // 亮度15张表，定宽9位
        for ($t = 0; $t < 15; $t++) {
            // 每条表展开成 2^9 个槽位，未命中处保持 [0, 0]
            $row = array_fill(0, 512, [0, 0]);
            for ($i = 0; $i < 16; $i++) {
                $len = $LENS[$t][$i];
                if ($len == 0) continue;
                $code = $BITS[$t][$i];
                // 该码字需要左移的位数（补齐到 9 bit）
                $shift = 9 - $len;
                // 填充所有前缀匹配的9位模式
                $base = $code << $shift;
                $cnt = 1 << $shift;
                for ($p = 0; $p < $cnt; $p++) {
                    $row[$base + $p] = [$i, $len];
                }
            }
            $lut['l' . $t] = $row;
        }
        // 色度DC 3张表，定宽3位
        for ($t = 0; $t < 3; $t++) {
            // 每条表展开成 2^3 个槽位
            $row = array_fill(0, 8, [0, 0]);
            for ($i = 0; $i < 4; $i++) {
                $len = $CHROMA_LEN[$t][$i];
                if ($len == 0) continue;
                $code = $CHROMA_BITS[$t][$i];
                $shift = 3 - $len;
                $base = $code << $shift;
                $cnt = 1 << $shift;
                for ($p = 0; $p < $cnt; $p++) {
                    $row[$base + $p] = [$i, $len];
                }
            }
            $lut['c' . $t] = $row;
        }
        return $lut;
    }

    /**
     * CAVLC 解码 run_before（9.2.4）
     * 表 9-10 按 zerosLeft 选行：1..6 各一行（定宽 3 bit），
     * zerosLeft>6 时用最后一行（最长码字 11 bit）。
     * @param int $leftZeros 当前剩余的零个数
     * @return int 当前非零系数之前跳过的零个数
     */
    public function readRunBefore(int $leftZeros): int
    {
        // 没有剩余零时 run 必为 0
        if ($leftZeros <= 0) return 0;

        // 快速路径：预生成定宽查找表（表0-5宽3位，表6宽11位）
        static $LUT = null;
        if ($LUT === null) {
            $LUT = $this->buildRunBeforeLut();
        }

        // zerosLeft 1..6 对应表 0..5，>6 统一用表 6
        $tableIdx = min($leftZeros, 7) - 1;
        if ($tableIdx < 0 || $tableIdx > 6) return 0;

        if ($tableIdx === 6) {
            // 表 9-10 最后一行为 11 bit 上限
            $peeked = $this->reader->peek(11);
            $e = $LUT[6][$peeked];
        } else {
            // 其余各表最长 3 bit
            $peeked = $this->reader->peek(3);
            $e = $LUT[$tableIdx][$peeked];
        }
        if ($e[1] !== 0) {
            // 命中合法码字：消费码长
            $this->reader->skip($e[1]);
        }
        // $e[0] 即 run_before 的值
        return $e[0];
    }

    /** 预生成 run_before 定宽查找表（每进程一次） */
    private function buildRunBeforeLut(): array
    {
        // 表 9-10 各行码长（行下标 = zerosLeft-1，列下标 = run_before 取值）
        $RUN_BEFORE_LEN = [
            [1, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
            [1, 2, 2, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
            [2, 2, 2, 2, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
            [2, 2, 2, 3, 3, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
            [2, 2, 3, 3, 3, 3, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
            [2, 3, 3, 3, 3, 3, 3, 0, 0, 0, 0, 0, 0, 0, 0, 0],
            [3, 3, 3, 3, 3, 3, 3, 4, 5, 6, 7, 8, 9, 10, 11, 0],
        ];

        // 表 9-10 各行码字
        $RUN_BEFORE_BITS = [
            [1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
            [1, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
            [3, 2, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
            [3, 2, 1, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
            [3, 2, 3, 2, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
            [3, 0, 1, 3, 2, 5, 4, 0, 0, 0, 0, 0, 0, 0, 0, 0],
            [7, 6, 5, 4, 3, 2, 1, 1, 1, 1, 1, 1, 1, 1, 1, 0],
        ];

        // 每行有效码字个数（最后一行为 15 个）
        $RUN_BEFORE_COUNT = [2, 3, 4, 5, 6, 7, 15];

        $all = [];
        for ($t = 0; $t < 7; $t++) {
            // 前 6 行定宽 3 bit，最后一行定宽 11 bit
            $maxBits = $t === 6 ? 11 : 3;
            $size = 1 << $maxBits;
            $row = array_fill(0, $size, [0, 0]);
            for ($i = 0; $i < $RUN_BEFORE_COUNT[$t]; $i++) {
                $len = $RUN_BEFORE_LEN[$t][$i];
                if ($len == 0) continue;
                $code = $RUN_BEFORE_BITS[$t][$i];
                // 补齐到定宽所需的左移位数
                $shift = $maxBits - $len;
                $base = $code << $shift;
                $cnt = 1 << $shift;
                // 把该码字覆盖的所有定宽模式都填上同一条目
                for ($p = 0; $p < $cnt; $p++) {
                    $row[$base + $p] = [$i, $len];
                }
            }
            $all[$t] = $row;
        }
        return $all;
    }

    /**
     * 解码AC残差块CAVLC（15个AC）
     * 注意：对于 AC 系数，nC 通常根据上邻和左邻宏块的非零系数数量计算
     * 对于 DC 系数（luma DC 16/4 或 chroma DC 4），nC = -1
     *
     * 本方法是通用入口，按 7.3.5.3 顺序完成一次 residual_block_cavlc() 解码。
     * @param int $maxCoef 本块系数总数（4x4 AC 为 15，4x4 DC 为 16，色度 DC 为 4）
     * @param int $nC 邻块预测值；DC 块传 -1
     * @return array 扫描顺序的长度 $maxCoef 系数数组
     */
    public function decodeResidualBlock(int $maxCoef, int $nC = -1): array
    {
        // Direct translation of Rust decode_residual (cavlc.rs:344-542)
        //$dbg = $this->debugResidual;
        // 输出系数数组，长度为本块系数容量
        $coeffs = array_fill(0, $maxCoef, 0);

        // 1. Read coeff_token
        // 9.2.1：由 nC 选表，解出 TotalCoeff 与 TrailingOnes
        $totalCoeff = 0;
        $trailingOnes = 0;
        $this->readCoeffToken($totalCoeff, $trailingOnes, $nC);

        // 根据编码标准，如果totalCoeff超过maxCoef，限制为maxCoef
        $totalCoeff = min($totalCoeff, $maxCoef);
        // trailingOnes不能超过totalCoeff
        $trailingOnes = min($trailingOnes, $totalCoeff);

        if ($totalCoeff === 0) {
            // TotalCoeff==0：整个残差块为零，直接返回零块
            return $coeffs;
        }

        // 2. Read levels（顺序追加，避免预填充数组；高运动帧约 50 万系数/帧）
        $levels = [];

        // 2a. Trailing ones: read sign bits (1 bit each)
        // 7.3.5.3.1：每个 trailing one 只带 1 bit 符号（1=负）
        for ($i = 0; $i < $trailingOnes; $i++) {
            $sign = $this->reader->readU(1);
            $levels[] = $sign ? -1 : 1;
        }

        // 2b. Read remaining levels (totalCoeff - trailingOnes)
        // 9.2.2：其余 level 用 level_prefix + level_suffix 解码
        $remaining = $totalCoeff - $trailingOnes;
        if ($remaining > 0) {
            // suffix_length 自适应阈值（9.2.2 步骤 3）
            static $SUFFIX_LIMIT = [0, 3, 6, 12, 24, 48, PHP_INT_MAX];
            // suffix_length 初值：TotalCoeff>10 且 TrailingOnes<3 时为 1
            $suffixLength = ($totalCoeff > 10 && $trailingOnes < 3) ? 1 : 0;

            for ($i = 0; $i < $remaining; $i++) {
                // 该 level 在 levels 数组中的下标
                $levelIdx = $trailingOnes + $i;
                // 是否为第一个非 trailing 的 level
                $isFirst = ($i === 0);

                // Read level_prefix: 连续 0 的个数 + 终止位 1
                $prefix = $this->reader->readLevelPrefix();

                // Compute level_code from prefix and suffix
                //$levelCode = 0;

                if ($isFirst && $suffixLength === 0) {
                    // First coefficient with suffix_length == 0: special VLC
                    if ($prefix < 14) {
                        // 无 suffix，level_code 就是 prefix
                        $levelCode = $prefix;
                    } elseif ($prefix === 14) {
                        // 额外 4 bit suffix
                        $suffix = $this->reader->readU(4);
                        $levelCode = 14 + $suffix;
                    } else {
                        // escape 区间：基准 30 + 扩展项 + suffix
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
                        // 常规码：prefix * 2^suffixLength + suffix
                        $suffix = ($suffixLength > 0) ? $this->reader->readU($suffixLength) : 0;
                        $levelCode = $prefix * (1 << $suffixLength) + $suffix;
                    } else {
                        // escape 区间
                        $lc = 15 * (1 << $suffixLength);
                        if ($prefix >= 16) {
                            $lc += (1 << ($prefix - 3)) - 4096;
                        }
                        $suffix = $this->reader->readU($prefix - 3);
                        $levelCode = $lc + $suffix;
                    }
                }

                // 从 levelCode 中提取符号和绝对值（编码标准：最低位是符号位，0=正，1=负）
                $sign = $levelCode & 1;
                // 右移一位得到 |level|-1
                $absLevelMinus1 = $levelCode >> 1;
                if ($isFirst && $trailingOnes < 3) {
                    // TrailingOnes<3 时第一个 level 的码值带 +2 偏移，这里补回 +1
                    $absLevelMinus1 += 1;
                }
                $absLevel = $absLevelMinus1 + 1;
                $levels[$levelIdx] = $sign ? -$absLevel : $absLevel;

                // Update suffix_length
                $absLevel = abs($levels[$levelIdx]);
                if ($isFirst) {
                    // 第一个 level 之后固定取 1 或 2（|level|>3 时取 2）
                    $suffixLength = ($absLevel > 3) ? 2 : 1;
                } elseif ($suffixLength < 6 && $absLevel > $SUFFIX_LIMIT[$suffixLength]) {
                    // 后续 level：超过阈值则加 1，上限 6
                    $suffixLength++;
                }
            }
        }

        // 3. Read total_zeros
        // 9.2.3：仅当 TotalCoeff<maxCoef 时块内还有零
        if ($totalCoeff < $maxCoef) {
            $totalZeros = $this->readTotalZeros($totalCoeff, $maxCoef);
        } else {
            // 系数已占满所有位置，没有零
            $totalZeros = 0;
        }

        // 4. Read run_before and place coefficients
        // 9.2.4：按扫描逆序摆放，run_before 表示相邻非零系数之间的零个数
        // 剩余未分配的零
        $zerosLeft = $totalZeros;
        // 最高频非零系数的扫描位置
        $coeffIdx = $totalCoeff + $totalZeros - 1;

        for ($i = 0; $i < $totalCoeff; $i++) {
            $isLast = ($i === $totalCoeff - 1);

            if (!$isLast && $zerosLeft > 0) {
                // 还有零可分配，读取 run_before
                $run = $this->readRunBefore($zerosLeft);
            } elseif (!$isLast) {
                // 剩余零为 0，run 必为 0
                $run = 0;
            } else {
                // 最后一个系数消费全部剩余零，不读码字
                $run = $zerosLeft;
            }

            if ($coeffIdx >= 0 && $coeffIdx < $maxCoef) {
                // 越界保护：异常码流下避免写坏数组
                $coeffs[$coeffIdx] = $levels[$i];
            }

            // 扣减已消费的零
            $zerosLeft -= $run;

            if (!$isLast) {
                // 前移 (run+1)：跨过 run 个零与当前系数
                $coeffIdx -= (1 + $run);
            }
        }
        // 返回扫描顺序的系数数组
        return $coeffs;
    }
}
