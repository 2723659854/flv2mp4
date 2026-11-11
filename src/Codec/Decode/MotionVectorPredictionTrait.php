<?php

namespace Xiaosongshu\Flv2mp4\Codec\Decode;

/**
 * @purpose 宏运动矢量预测
 * @author yanglong
 * @time 2026年7月23日15:15:58
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
     */
    public function predictMvP16x16(?array $mvLeft, ?array $mvTop, ?array $mvTopRight, int $currRefIdx): array
    {
        if ($mvLeft === null) {
            $refA = self::PART_NOT_AVAILABLE;
            $mvA = [0, 0];
        } elseif (!is_array($mvLeft)) {
            $refA = -1;
            $mvA = [0, 0];
        } else {
            $refA = $mvLeft[2] ?? -1;
            $mvA = [$mvLeft[0] ?? 0, $mvLeft[1] ?? 0];
        }

        if ($mvTop === null) {
            $refB = self::PART_NOT_AVAILABLE;
            $mvB = [0, 0];
        } elseif (!is_array($mvTop)) {
            $refB = -1;
            $mvB = [0, 0];
        } else {
            $refB = $mvTop[2] ?? -1;
            $mvB = [$mvTop[0] ?? 0, $mvTop[1] ?? 0];
        }

        if ($mvTopRight === null) {
            $refC = self::PART_NOT_AVAILABLE;
            $mvC = [0, 0];
        } else {
            $refC = $mvTopRight[2];
            $mvC = [$mvTopRight[0], $mvTopRight[1]];
        }

        $matchCount = ($refA === $currRefIdx) + ($refB === $currRefIdx) + ($refC === $currRefIdx);

        if ($matchCount > 1) {
            return [
                $this->medianInt($mvA[0], $mvB[0], $mvC[0]),
                $this->medianInt($mvA[1], $mvB[1], $mvC[1]),
            ];
        } elseif ($matchCount === 1) {
            if ($refA === $currRefIdx) {
                return $mvA;
            } elseif ($refB === $currRefIdx) {
                return $mvB;
            } else {
                return $mvC;
            }
        } else {
            if ($refB === self::PART_NOT_AVAILABLE && $refC === self::PART_NOT_AVAILABLE && $refA !== self::PART_NOT_AVAILABLE) {
                return $mvA;
            } else {
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
     */
    public function predictMvPSkip(?array $mvLeft, ?array $mvTop, ?array $mvTopRight): array
    {
        $refA = $mvLeft === null ? self::PART_NOT_AVAILABLE : ($mvLeft[2] ?? -1);
        $refB = $mvTop === null ? self::PART_NOT_AVAILABLE : ($mvTop[2] ?? -1);

        if ($refA === self::PART_NOT_AVAILABLE) {
            return [0, 0];
        }
        if ($refA === 0 && $mvLeft[0] === 0 && $mvLeft[1] === 0) {
            return [0, 0];
        }

        if ($refB === self::PART_NOT_AVAILABLE) {
            return [0, 0];
        }
        if ($refB === 0 && $mvTop[0] === 0 && $mvTop[1] === 0) {
            return [0, 0];
        }

        return $this->predictMvP16x16($mvLeft, $mvTop, $mvTopRight, 0);
    }

    /**
     * 三个整数取中值
     */
    private function medianInt(int $a, int $b, int $c): int
    {
        $min = min($a, $b, $c);
        $max = max($a, $b, $c);
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
        $strict = ($curSubIdx >= 0);

        $mvA = null;
        $mvB = null;
        $mvC = null;
        $aAvail = false;
        $bAvail = false;
        $cAvail = false;

        // Neighbor A (left)
        if ($blkX > 0) {
            $cell = $mbMvs[$blkY][$blkX - 1] ?? null;
            if ($cell !== null) {
                $aAvail = true;
                $mvA = $cell;
            } elseif ($strict) {
                // 同 MB 左侧 4x4 一定排在当前块之前解码，缺少运动信息即该 list 未使用
                $aAvail = true;
                $mvA = [0, 0, -1];
            }
        } elseif ($mbX > 0) {
            if (isset($leftColMvs[$blkY])) {
                $aAvail = true;
                $mvA = $leftColMvs[$blkY];
            }
        }

        // Neighbor B (top)
        if ($blkY > 0) {
            $cell = $mbMvs[$blkY - 1][$blkX] ?? null;
            if ($cell !== null) {
                $bAvail = true;
                $mvB = $cell;
            } elseif ($strict) {
                $bAvail = true;
                $mvB = [0, 0, -1];
            }
        } elseif ($mbY > 0) {
            $colIdx = $mbX * 4 + $blkX;
            if (isset($topRowMvs[$colIdx])) {
                $bAvail = true;
                $mvB = $topRowMvs[$colIdx];
            }
        }

        // Neighbor C (top-right), falling back to D (top-left)
        $crX = $blkX + $partWidth;
        $crY = $blkY - 1;

        $absCrX = $mbX * 4 + $crX;
        $absCrY = $mbY * 4 + $crY;

        $cIsPast = false;
        if ($absCrX >= 0 && $absCrY >= 0 && $absCrX < $mbW * 4) {
            $cMbX = (int)($absCrX / 4);
            $cMbY = (int)($absCrY / 4);
            $cIsPast = ($cMbY < $mbY) || ($cMbY == $mbY && $cMbX <= $mbX);
        }

        if ($cIsPast && $absCrX >= 0 && $absCrY >= 0 && $absCrX < $mbW * 4) {
            $cMbX = (int)($absCrX / 4);
            $cBlkX = $absCrX % 4;
            $cMbY = (int)($absCrY / 4);
            $cBlkY = $absCrY % 4;

            $isSameMb = ($cMbX == $mbX && $cMbY == $mbY);
            if ($isSameMb) {
                $cell = $mbMvs[$cBlkY][$cBlkX] ?? null;
                if ($strict) {
                    // 同 MB：仅排在当前子块之前的 4x4 视为已解码；
                    // 已解码但该 list 未使用 -> ref=-1、MV=(0,0)，仍参与中值，不触发 D 回退
                    $siC = (($cBlkY >> 1) << 1) + ($cBlkX >> 1);
                    $decoded = ($siC <= $curSubIdx);
                    if ($decoded) {
                        $cAvail = true;
                        $mvC = $cell ?? [0, 0, -1];
                    }
                } elseif ($cell !== null) {
                    $cAvail = true;
                    $mvC = $cell;
                }
            } else {
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
        if (!$cAvail && $blkX > 0 && $blkY > 0) {
            $cell = $mbMvs[$blkY - 1][$blkX - 1] ?? null;
            if ($cell !== null) {
                $cAvail = true;
                $mvC = $cell;
            } elseif ($strict) {
                $cAvail = true;
                $mvC = [0, 0, -1];
            }
        }
        if (!$cAvail && $blkX == 0 && $blkY > 0 && $mbX > 0) {
            if (isset($leftColMvs[$blkY - 1])) {
                $cAvail = true;
                $mvC = $leftColMvs[$blkY - 1];
            }
        }
        if (!$cAvail && $blkY == 0 && $blkX > 0 && $mbY > 0) {
            $colIdx = $mbX * 4 + $blkX - 1;
            if (isset($topRowMvs[$colIdx])) {
                $cAvail = true;
                $mvC = $topRowMvs[$colIdx];
            }
        }
        if (!$cAvail && $blkX == 0 && $blkY == 0 && $mbX > 0 && $mbY > 0) {
            $colIdx = ($mbX - 1) * 4 + 3;
            if (isset($topRowMvs[$colIdx])) {
                $cAvail = true;
                $mvC = $topRowMvs[$colIdx];
            }
        }

        if ($aAvail) {
            $refA = $mvA[2] ?? -1;
            $mvAv = [$mvA[0] ?? 0, $mvA[1] ?? 0];
        } else {
            $refA = self::PART_NOT_AVAILABLE;
            $mvAv = [0, 0];
        }

        if ($bAvail) {
            $refB = $mvB[2] ?? -1;
            $mvBv = [$mvB[0] ?? 0, $mvB[1] ?? 0];
        } else {
            $refB = self::PART_NOT_AVAILABLE;
            $mvBv = [0, 0];
        }

        if ($cAvail) {
            $refC = $mvC[2] ?? -1;
            $mvCv = [$mvC[0] ?? 0, $mvC[1] ?? 0];
        } else {
            $refC = self::PART_NOT_AVAILABLE;
            $mvCv = [0, 0];
        }

        $matchCount = ($refA === $currRefIdx) + ($refB === $currRefIdx) + ($refC === $currRefIdx);

        if ($matchCount > 1) {
            return [
                $this->medianInt($mvAv[0], $mvBv[0], $mvCv[0]),
                $this->medianInt($mvAv[1], $mvBv[1], $mvCv[1]),
            ];
        } elseif ($matchCount === 1) {
            if ($refA === $currRefIdx) {
                return $mvAv;
            } elseif ($refB === $currRefIdx) {
                return $mvBv;
            } else {
                return $mvCv;
            }
        } else {
            if ($refB === self::PART_NOT_AVAILABLE && $refC === self::PART_NOT_AVAILABLE && $refA !== self::PART_NOT_AVAILABLE) {
                return $mvAv;
            } else {
                return [
                    $this->medianInt($mvAv[0], $mvBv[0], $mvCv[0]),
                    $this->medianInt($mvAv[1], $mvBv[1], $mvCv[1]),
                ];
            }
        }
    }
}
