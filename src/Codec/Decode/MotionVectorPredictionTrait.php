<?php

namespace Xiaosongshu\Flv2mp4\Codec\Decode;

/**
 * @purpose 宏运动矢量预测
 * @author yanglong
 * @time 2026年7月23日15:15:58
 *
 * 功能概述：
 * 本 trait 负责 H.264/AVC 解码中帧间宏块/子宏块的 MV（运动矢量）预测。
 * 预测只依赖 A（左）、B（上）、C（右上；不可用时回退 D（左上））三个邻块，
 * 按 H.264 8.4.1.3 的规则：先比较邻块 refIdx 与当前 refIdx 是否相同，
 * 再按「匹配个数」决定是取三者中值、取唯一匹配的 MV，还是走特例直接取 A。
 *
 * 约定：
 * - MV 以 1/4 像素为单位，[mvX, mvY, refIdx] 三元组描述一个邻块的运动信息；
 * - self::PART_NOT_AVAILABLE 表示分区不可用（越界或尚未解码）；
 * - refIdx = -1 表示邻块存在，但当前参考列表（如 L0）未被使用（Intra 或只用了 L1）。
 *
 * 注意：本 trait 只做预测（得到 predMV），不负责 MV 的 CABAC/CAVLC 解码、
 * 参考帧索引推导以及最终像素插值，后者分别属于 8.4.1 其他小节与 8.4.2/8.4.3。
 */
trait MotionVectorPredictionTrait
{


    /**
     * P帧16x16宏块运动向量预测 (8.4.1.3节)
     * @param array|null $mvLeft 左邻居 [mvX, mvY, refIdx]，null表示PART_NOT_AVAILABLE（帧边界外），[0,0,-1]表示Intra（LIST_NOT_USED）
     * @param array|null $mvTop 上邻居 [mvX, mvY, refIdx]，null表示PART_NOT_AVAILABLE
     * @param array|null $mvTopRight 右上邻居 [mvX, mvY, refIdx]，null表示PART_NOT_AVAILABLE
     * @param int $currRefIdx 当前参考帧索引
     * @return array [predMvX, predMvY] 预测的运动向量
     *
     * 对应 H.264 8.4.1.3「Derivation process for luma motion vector prediction」：
     * 1) 依次解析 A、B、C 三个邻块的 (refIdx, MV)；
     * 2) 统计 refIdx 与当前 refIdx 相同的邻居个数 matchCount；
     * 3) matchCount > 1 时取三者分量中值；matchCount == 1 时取该唯一匹配者的 MV；
     *    matchCount == 0 时，若 B、C 均不可用而 A 可用，则直接取 A，否则仍取中值。
     */
    public function predictMvP16x16(?array $mvLeft, ?array $mvTop, ?array $mvTopRight, int $currRefIdx): array
    {
        // ① 解析左邻块 A：null 表示越界（PART_NOT_AVAILABLE），
        //    非数组表示该邻块虽存在但不使用当前参考列表（refIdx 记为 -1，MV 归零）。
        if ($mvLeft === null) {
            // A 在图像/片边界外：按不可用处理，MV 取 (0,0) 以参与后续中值计算
            $refA = self::PART_NOT_AVAILABLE;
            $mvA = [0, 0];
        } elseif (!is_array($mvLeft)) {
            // 非法/占位输入：视为 LIST_NOT_USED（Intra），refIdx=-1，MV 归零
            $refA = -1;
            $mvA = [0, 0];
        } else {
            // 正常邻块：取第三元素为 refIdx，前两元素为 MV（缺省时按 -1 与 0 兜底）
            $refA = $mvLeft[2] ?? -1;
            $mvA = [$mvLeft[0] ?? 0, $mvLeft[1] ?? 0];
        }

        // ② 解析上邻块 B，语义与 A 完全相同
        if ($mvTop === null) {
            // B 不可用（帧/片边界外）
            $refB = self::PART_NOT_AVAILABLE;
            $mvB = [0, 0];
        } elseif (!is_array($mvTop)) {
            // B 存在但未使用 L0 列表
            $refB = -1;
            $mvB = [0, 0];
        } else {
            // 正常取出 B 的 refIdx 与 MV
            $refB = $mvTop[2] ?? -1;
            $mvB = [$mvTop[0] ?? 0, $mvTop[1] ?? 0];
        }

        // ③ 解析右上邻块 C（16x16 分区的 C 位于当前宏块右上一格）
        if ($mvTopRight === null) {
            // C 越界（例如图像最右列的宏块）：标记 PART_NOT_AVAILABLE
            $refC = self::PART_NOT_AVAILABLE;
            $mvC = [0, 0];
        } else {
            // C 可用：直接取三元组（此处假定调用方已保证结构完整）
            $refC = $mvTopRight[2];
            $mvC = [$mvTopRight[0], $mvTopRight[1]];
        }

        // ④ 统计 A/B/C 中 refIdx 等于当前 refIdx 的个数（布尔值相加即计数）
        //    这是 8.4.1.3 判定「中值 / 唯一匹配 / 特例」的核心依据
        $matchCount = ($refA === $currRefIdx) + ($refB === $currRefIdx) + ($refC === $currRefIdx);

        // ⑤ 情况一：至少两个邻块使用同一参考帧 → 对 x、y 分量分别取中值
        if ($matchCount > 1) {
            return [
                $this->medianInt($mvA[0], $mvB[0], $mvC[0]),
                $this->medianInt($mvA[1], $mvB[1], $mvC[1]),
            ];
        // ⑥ 情况二：恰有一个邻块匹配 → 直接复制它的 MV（无需中值）
        } elseif ($matchCount === 1) {
            if ($refA === $currRefIdx) {
                // 唯一匹配者是 A（左）
                return $mvA;
            } elseif ($refB === $currRefIdx) {
                // 唯一匹配者是 B（上）
                return $mvB;
            } else {
                // 唯一匹配者是 C（右上）
                return $mvC;
            }
        // ⑦ 情况三：没有任何邻块匹配 → 检查 8.4.1.3 的特例
        } else {
            if ($refB === self::PART_NOT_AVAILABLE && $refC === self::PART_NOT_AVAILABLE && $refA !== self::PART_NOT_AVAILABLE) {
                // 特例：B、C 均不可用而 A 可用 → 直接取 A 的 MV（避免中值被伪 0 拉偏）
                return $mvA;
            } else {
                // 其余情形：仍按三分量中值预测
                return [
                    $this->medianInt($mvA[0], $mvB[0], $mvC[0]),
                    $this->medianInt($mvA[1], $mvB[1], $mvC[1]),
                ];
            }
        }
    }

    /**
     * P_Skip 宏块运动向量预测 (8.4.1.1节)
     * - 如果A（左邻居）完全不存在（帧边界外，null）→ 返回(0,0)
     * - 如果B（上邻居）完全不存在（帧边界外，null）→ 返回(0,0)
     * - 如果A是Inter宏块且ref=0、MV=(0,0) → 返回(0,0)
     * - 如果B是Inter宏块且ref=0、MV=(0,0) → 返回(0,0)
     * - 否则使用与P_16x16相同的中值预测逻辑
     * @param array|null $mvLeft 左邻居 [mvX, mvY, refIdx]，null表示完全不存在，[0,0,-1]表示存在但不使用L0（Intra）
     * @param array|null $mvTop 上邻居 [mvX, mvY, refIdx]，null表示完全不存在，[0,0,-1]表示存在但不使用L0（Intra）
     * @param array|null $mvTopRight 右上邻居 [mvX, mvY, refIdx]
     * @return array [predMvX, predMvY] 预测的运动向量
     *
     * 对应 H.264 8.4.1.1 中 P_Skip 的 MV 推导：
     * 若 A 或 B 不可用、其 refIdx 不为 0、或其 MV 不为 (0,0)，则 predMV 取 (0,0)；
     * 否则（A、B 都是 ref=0 且 MV=(0,0) 的帧间块）退化为 8.4.1.3 的中值预测。
     * 注意 P_Skip 隐含使用参考索引 0，因此这里固定以 currRefIdx = 0 调用 16x16 预测。
     */
    public function predictMvPSkip(?array $mvLeft, ?array $mvTop, ?array $mvTopRight): array
    {
        // 提取 A、B 的参考索引：null（越界）统一映射为 PART_NOT_AVAILABLE
        $refA = $mvLeft === null ? self::PART_NOT_AVAILABLE : ($mvLeft[2] ?? -1);
        $refB = $mvTop === null ? self::PART_NOT_AVAILABLE : ($mvTop[2] ?? -1);

        // 条件一：A 不可用（越界）→ P_Skip 的 MV 直接为 (0,0)
        if ($refA === self::PART_NOT_AVAILABLE) {
            return [0, 0];
        }
        // 条件二：A 使用参考索引 0 且 MV 恰为 (0,0) → 同样取 (0,0)
        if ($refA === 0 && $mvLeft[0] === 0 && $mvLeft[1] === 0) {
            return [0, 0];
        }

        // 条件三：B 不可用（越界）→ 取 (0,0)
        if ($refB === self::PART_NOT_AVAILABLE) {
            return [0, 0];
        }
        // 条件四：B 使用参考索引 0 且 MV 恰为 (0,0) → 取 (0,0)
        if ($refB === 0 && $mvTop[0] === 0 && $mvTop[1] === 0) {
            return [0, 0];
        }

        // 其余情况：按 8.4.1.3 的 16x16 中值预测推导，P_Skip 固定 refIdx = 0
        return $this->predictMvP16x16($mvLeft, $mvTop, $mvTopRight, 0);
    }

    /**
     * 三个整数取中值
     *
     * 利用「总和 - 最小值 - 最大值 = 中间值」恒等式，避免排序，
     * 供 8.4.1.3 / 8.4.1.5 中对 MV 的 x、y 分量分别求中值使用。
     */
    private function medianInt(int $a, int $b, int $c): int
    {
        // 先求三者中的最小与最大
        $min = min($a, $b, $c);
        $max = max($a, $b, $c);
        // 三数之和减去最小、最大，剩下的即为中值
        return $a + $b + $c - $min - $max;
    }

    /**
     * P帧8x8块运动向量预测 (8.4.1.5节)
     * 4x4子块粒度版本，支持不同part_width
     * @param int $mbX 宏块x
     * @param int $mbY 宏块y
     * @param int $blkX 块在宏块内的x索引(0~3，4x4子块坐标)
     * @param int $blkY 块在宏块内的y索引(0~3，4x4子块坐标)
     * @param int $partWidth 分区宽度（以4x4子块为单位：1=4宽, 2=8宽）
     * @param array $mbMvs 宏块内已解码块的运动向量 [blkY][blkX] = [mvX, mvY, refIdx] (4x4)
     * @param array $topRowMvs 上方宏块行的运动向量 [colIdx] = [mvX, mvY, refIdx] (每宏块4个)
     * @param array $leftColMvs 左方宏块列的运动向量 [rowIdx] = [mvX, mvY, refIdx] (4个)
     * @param int $currRefIdx 当前参考帧索引
     * @return array [predMvX, predMvY]
     *
     * 对应 H.264 8.4.1.5「Derivation process for luma motion vector prediction
     * for a sub-macroblock partition」：
     * 以 4x4 子块为单位，对每个待解码子块取 A（左）、B（上）、C（右上）三个邻块，
     * 当 C 越界或尚未解码时回退到 D（左上），再按 8.4.1.3 的同样规则做中值预测。
     * 宏块内部按 z 扫描顺序解码 4 个 8x8 子宏块，$curSubIdx 用于刻画「是否已解码」。
     */
    public function predictMvP8x8(
        int $mbX, int $mbY, int $blkX, int $blkY, int $partWidth,
        array $mbMvs, array $topRowMvs, array $leftColMvs,
        int $currRefIdx, int $curSubIdx = -1
    ): array {
        $mbW = $this->picWidthInMbs;
        // $curSubIdx >= 0 时启用严格可用性语义：
        // 同 MB 内“已解码但该 list 未使用”的邻居按 ref=-1、MV=(0,0) 参与中值，且不触发 C→D 回退；
        // 只有“尚未解码”的邻居才是 PART_NOT_AVAILABLE。$curSubIdx < 0 保留旧行为（供 P 片调用方）。
        // 补充：$curSubIdx 为当前 8x8 子宏块在宏块内的 z 扫描序号（0~3），
        // 判定标准参见 8.4.1.5 中「邻块是否在解码顺序上位于当前子块之前」。
        $strict = ($curSubIdx >= 0);

        // 三个邻块 A/B/C 的运动信息缓存，以及各自的可用性标志
        $mvA = null;
        $mvB = null;
        $mvC = null;
        $aAvail = false;
        $bAvail = false;
        $cAvail = false;

        // Neighbor A (left)
        // 邻块 A：与当前 4x4 子块左边相邻（8.4.1.5 的 A 为当前子块左侧 4x4）
        if ($blkX > 0) {
            // 同宏块内：取同行前一列的 4x4 单元
            $cell = $mbMvs[$blkY][$blkX - 1] ?? null;
            if ($cell !== null) {
                // 该单元已写入运动信息，A 可用
                $aAvail = true;
                $mvA = $cell;
            } elseif ($strict) {
                // 同 MB 左侧 4x4 一定排在当前块之前解码，缺少运动信息即该 list 未使用
                // → 仍视为可用，但 refIdx=-1、MV=(0,0)，参与中值而不触发回退
                $aAvail = true;
                $mvA = [0, 0, -1];
            }
        } elseif ($mbX > 0) {
            // 位于宏块最左列：A 在左邻宏块的同一行
            if (isset($leftColMvs[$blkY])) {
                $aAvail = true;
                $mvA = $leftColMvs[$blkY];
            }
        }

        // Neighbor B (top)
        // 邻块 B：与当前 4x4 子块上边相邻（即上一行的同一列 4x4）
        if ($blkY > 0) {
            // 同宏块内：取上一行同一列的 4x4 单元
            $cell = $mbMvs[$blkY - 1][$blkX] ?? null;
            if ($cell !== null) {
                $bAvail = true;
                $mvB = $cell;
            } elseif ($strict) {
                // 同 MB 上方 4x4 已按解码顺序完成，缺失即表示该 list 未被使用
                $bAvail = true;
                $mvB = [0, 0, -1];
            }
        } elseif ($mbY > 0) {
            // 位于宏块最上行：B 在上邻宏块的第 blkX 个 4x4 列
            $colIdx = $mbX * 4 + $blkX;
            if (isset($topRowMvs[$colIdx])) {
                $bAvail = true;
                $mvB = $topRowMvs[$colIdx];
            }
        }

        // Neighbor C (top-right), falling back to D (top-left)
        // 邻块 C：位于当前分区右上方，其列偏移需加上分区宽度 partWidth
        $crX = $blkX + $partWidth;
        $crY = $blkY - 1;

        // 将 C 的宏块内坐标换算为整帧的 4x4 绝对坐标
        $absCrX = $mbX * 4 + $crX;
        $absCrY = $mbY * 4 + $crY;

        // 判定 C 是否落在「解码顺序上已完成」的位置：
        // 同一宏块内要求其所在 4x4 列不晚于当前 mbX；跨行时要求位于上方宏块行。
        $cIsPast = false;
        if ($absCrX >= 0 && $absCrY >= 0 && $absCrX < $mbW * 4) {
            // 计算 C 所在宏块坐标（向右/向下取整）
            $cMbX = (int)($absCrX / 4);
            $cMbY = (int)($absCrY / 4);
            $cIsPast = ($cMbY < $mbY) || ($cMbY == $mbY && $cMbX <= $mbX);
        }

        // 只有在 C 位于已解码区域且在图像水平范围内时才尝试取 C
        if ($cIsPast && $absCrX >= 0 && $absCrY >= 0 && $absCrX < $mbW * 4) {
            // 拆出 C 所在宏块坐标与宏块内的 4x4 子块坐标
            $cMbX = (int)($absCrX / 4);
            $cBlkX = $absCrX % 4;
            $cMbY = (int)($absCrY / 4);
            $cBlkY = $absCrY % 4;

            $isSameMb = ($cMbX == $mbX && $cMbY == $mbY);
            if ($isSameMb) {
                // C 与当前子块同在一个宏块内：直接查本宏块已解码的 4x4 表
                $cell = $mbMvs[$cBlkY][$cBlkX] ?? null;
                if ($strict) {
                    // 同 MB：仅排在当前子块之前的 4x4 视为已解码；
                    // 已解码但该 list 未使用 -> ref=-1、MV=(0,0)，仍参与中值，不触发 D 回退
                    // 子宏块序号：每 2x2 个 4x4 构成一个 8x8 子宏块，按 z 扫描编号
                    $siC = (($cBlkY >> 1) << 1) + ($cBlkX >> 1);
                    $decoded = ($siC <= $curSubIdx);
                    if ($decoded) {
                        // 已解码：单元格缺失时按「未使用该 list」处理
                        $cAvail = true;
                        $mvC = $cell ?? [0, 0, -1];
                    }
                } elseif ($cell !== null) {
                    // 非严格模式：仅当单元格存在时才认为 C 可用
                    $cAvail = true;
                    $mvC = $cell;
                }
            } else {
                // C 位于上方宏块行：从上一行宏块行缓存中按列取
                if ($cMbY < $mbY) {
                    $colIdx = $cMbX * 4 + $cBlkX;
                    if (isset($topRowMvs[$colIdx])) {
                        $cAvail = true;
                        $mvC = $topRowMvs[$colIdx];
                    }
                }
            }
        }

        // Fall back to D (top-left) only when C is genuinely unavailable (out of picture / not decoded)
        // 回退方案 D：C 不可用时改用左上邻块（8.4.1.5 规定的替代邻块）
        if (!$cAvail && $blkX > 0 && $blkY > 0) {
            // D 与当前子块同宏块：取左上方 4x4 单元
            $cell = $mbMvs[$blkY - 1][$blkX - 1] ?? null;
            if ($cell !== null) {
                $cAvail = true;
                $mvC = $cell;
            } elseif ($strict) {
                // 严格模式下同 MB 的 D 必定已解码，缺失表示该 list 未使用
                $cAvail = true;
                $mvC = [0, 0, -1];
            }
        }
        if (!$cAvail && $blkX == 0 && $blkY > 0 && $mbX > 0) {
            // 当前子块在宏块最左列但不在最上行：D 落在左邻宏块的上一行
            if (isset($leftColMvs[$blkY - 1])) {
                $cAvail = true;
                $mvC = $leftColMvs[$blkY - 1];
            }
        }
        if (!$cAvail && $blkY == 0 && $blkX > 0 && $mbY > 0) {
            // 当前子块在宏块最上行但不在最左列：D 落在上邻宏块的前一列
            $colIdx = $mbX * 4 + $blkX - 1;
            if (isset($topRowMvs[$colIdx])) {
                $cAvail = true;
                $mvC = $topRowMvs[$colIdx];
            }
        }
        if (!$cAvail && $blkX == 0 && $blkY == 0 && $mbX > 0 && $mbY > 0) {
            // 当前子块位于宏块左上角：D 取左上宏块的最后一列（每宏块 4 个，索引 3）
            $colIdx = ($mbX - 1) * 4 + 3;
            if (isset($topRowMvs[$colIdx])) {
                $cAvail = true;
                $mvC = $topRowMvs[$colIdx];
            }
        }

        // 统一整理 A 的 (refIdx, MV)；不可用则 refIdx=PART_NOT_AVAILABLE、MV=(0,0)
        if ($aAvail) {
            $refA = $mvA[2] ?? -1;
            $mvAv = [$mvA[0] ?? 0, $mvA[1] ?? 0];
        } else {
            $refA = self::PART_NOT_AVAILABLE;
            $mvAv = [0, 0];
        }

        // 统一整理 B 的 (refIdx, MV)
        if ($bAvail) {
            $refB = $mvB[2] ?? -1;
            $mvBv = [$mvB[0] ?? 0, $mvB[1] ?? 0];
        } else {
            $refB = self::PART_NOT_AVAILABLE;
            $mvBv = [0, 0];
        }

        // 统一整理 C（或其回退 D）的 (refIdx, MV)
        if ($cAvail) {
            $refC = $mvC[2] ?? -1;
            $mvCv = [$mvC[0] ?? 0, $mvC[1] ?? 0];
        } else {
            $refC = self::PART_NOT_AVAILABLE;
            $mvCv = [0, 0];
        }

        // 统计与当前 refIdx 匹配的邻块数（同 8.4.1.3 的判定方式）
        $matchCount = ($refA === $currRefIdx) + ($refB === $currRefIdx) + ($refC === $currRefIdx);

        // 情况一：两个及以上匹配 → x、y 分量分别取中值
        if ($matchCount > 1) {
            return [
                $this->medianInt($mvAv[0], $mvBv[0], $mvCv[0]),
                $this->medianInt($mvAv[1], $mvBv[1], $mvCv[1]),
            ];
        // 情况二：仅一个匹配 → 直接采用该邻块的 MV
        } elseif ($matchCount === 1) {
            if ($refA === $currRefIdx) {
                // 匹配者是 A
                return $mvAv;
            } elseif ($refB === $currRefIdx) {
                // 匹配者是 B
                return $mvBv;
            } else {
                // 匹配者是 C（或回退的 D）
                return $mvCv;
            }
        // 情况三：无匹配 → 先判断「B、C 不可用且 A 可用」的特例
        } else {
            if ($refB === self::PART_NOT_AVAILABLE && $refC === self::PART_NOT_AVAILABLE && $refA !== self::PART_NOT_AVAILABLE) {
                // 特例成立时直接取 A 的 MV
                return $mvAv;
            } else {
                // 其余情形仍取三分量中值
                return [
                    $this->medianInt($mvAv[0], $mvBv[0], $mvCv[0]),
                    $this->medianInt($mvAv[1], $mvBv[1], $mvCv[1]),
                ];
            }
        }
    }
}
