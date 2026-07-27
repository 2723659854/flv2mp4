<?php

namespace Xiaosongshu\Flv2mp4\Codec\Encode;

/**
 * @purpose 运动矢量缓存与参考帧管理
 * @author yanglong
 */
trait MotionTrait
{

    /**
     * 获取参考帧中指定位置的像素值（带边界处理）
     * 使用mbAlignedWidth/mbAlignedHeight作为stride，与参考帧存储格式一致
     */
    private function getRefPixel(string $refPlane, int $x, int $y): int
    {
        $stride = $this->mbAlignedWidth;
        $x = max(0, min($stride - 1, $x));
        $y = max(0, min($this->mbAlignedHeight - 1, $y));
        return ord($refPlane[$y * $stride + $x]);
    }

    /**
     * 6抽头水平插值（计算半像素b）
     * b[i,j] = (E - 5F + 20G + 20H - 5I + J + 16) >> 5
     */
    private function interpHorizontal(string $refPlane, int $x, int $y): int
    {
        $E = $this->getRefPixel($refPlane, $x - 2, $y);
        $F = $this->getRefPixel($refPlane, $x - 1, $y);
        $G = $this->getRefPixel($refPlane, $x,     $y);
        $H = $this->getRefPixel($refPlane, $x + 1, $y);
        $I = $this->getRefPixel($refPlane, $x + 2, $y);
        $J = $this->getRefPixel($refPlane, $x + 3, $y);
        $val = self::INTERP_TAP0 * $E + self::INTERP_TAP1 * $F + self::INTERP_TAP2 * $G
            + self::INTERP_TAP3 * $H + self::INTERP_TAP4 * $I + self::INTERP_TAP5 * $J;
        return max(0, min(255, (($val + 16) >> 5)));
    }

    /**
     * 6抽头垂直插值（计算半像素h）
     */
    private function interpVertical(string $refPlane, int $x, int $y): int
    {
        $A = $this->getRefPixel($refPlane, $x, $y - 2);
        $B = $this->getRefPixel($refPlane, $x, $y - 1);
        $C = $this->getRefPixel($refPlane, $x, $y);
        $D = $this->getRefPixel($refPlane, $x, $y + 1);
        $E = $this->getRefPixel($refPlane, $x, $y + 2);
        $F = $this->getRefPixel($refPlane, $x, $y + 3);
        $val = self::INTERP_TAP0 * $A + self::INTERP_TAP1 * $B + self::INTERP_TAP2 * $C
            + self::INTERP_TAP3 * $D + self::INTERP_TAP4 * $E + self::INTERP_TAP5 * $F;
        return max(0, min(255, (($val + 16) >> 5)));
    }

    /**
     * 6抽头对角插值（计算半像素j）
     * 先水平后垂直，或先垂直后水平
     */
    private function interpDiagonal(string $refPlane, int $x, int $y): int
    {
        // 先做水平插值，得到中间值aa, bb, ..., ff（不clip）
        $vals = [];
        for ($dy = -2; $dy <= 3; $dy++) {
            $E = $this->getRefPixel($refPlane, $x - 2, $y + $dy);
            $F = $this->getRefPixel($refPlane, $x - 1, $y + $dy);
            $G = $this->getRefPixel($refPlane, $x,     $y + $dy);
            $H = $this->getRefPixel($refPlane, $x + 1, $y + $dy);
            $I = $this->getRefPixel($refPlane, $x + 2, $y + $dy);
            $J = $this->getRefPixel($refPlane, $x + 3, $y + $dy);
            $vals[] = self::INTERP_TAP0 * $E + self::INTERP_TAP1 * $F + self::INTERP_TAP2 * $G
                + self::INTERP_TAP3 * $H + self::INTERP_TAP4 * $I + self::INTERP_TAP5 * $J;
        }
        // 对中间值做垂直插值
        $val = self::INTERP_TAP0 * $vals[0] + self::INTERP_TAP1 * $vals[1] + self::INTERP_TAP2 * $vals[2]
            + self::INTERP_TAP3 * $vals[3] + self::INTERP_TAP4 * $vals[4] + self::INTERP_TAP5 * $vals[5];
        return max(0, min(255, (($val + 512) >> 10)));
    }

    /**
     * 获取参考块（支持半像素位置）
     * @param int $qpX X位置（1/2像素单位，即qpX=2表示1像素，qpX=3表示1.5像素）
     * @param int $qpY Y位置（1/2像素单位）
     */
    private function getReferenceBlock(string $refPlane, int $qpX, int $qpY): array
    {
        $block = array_fill(0, 16, array_fill(0, 16, 0));
        $intX = $qpX >> 1;
        $intY = $qpY >> 1;
        $halfX = $qpX & 1;
        $halfY = $qpY & 1;

        for ($y = 0; $y < 16; $y++) {
            for ($x = 0; $x < 16; $x++) {
                $px = $intX + $x;
                $py = $intY + $y;
                if ($halfX == 0 && $halfY == 0) {
                    // 整数像素
                    $block[$y][$x] = $this->getRefPixel($refPlane, $px, $py);
                } elseif ($halfX == 1 && $halfY == 0) {
                    // 水平半像素 b
                    $block[$y][$x] = $this->interpHorizontal($refPlane, $px, $py);
                } elseif ($halfX == 0 && $halfY == 1) {
                    // 垂直半像素 h
                    $block[$y][$x] = $this->interpVertical($refPlane, $px, $py);
                } else {
                    // 对角半像素 j
                    $block[$y][$x] = $this->interpDiagonal($refPlane, $px, $py);
                }
            }
        }
        return $block;
    }

    /**
     * 计算两个16x16块的SAD
     */
    private function computeSAD(array $block1, array $block2): int
    {
        $sad = 0;
        for ($y = 0; $y < 16; $y++) {
            for ($x = 0; $x < 16; $x++) {
                $sad += abs($block1[$y][$x] - $block2[$y][$x]);
            }
        }
        return $sad;
    }

    /**
     * 运动估计：整数像素搜索，菱形搜索
     * @return array [mvX, mvY, sad] 运动向量和SAD值（mvX/mvY为1/4像素单位）
     */
    public function motionEstimate16x16(array $currentBlock, string $refPlane, int $mbX, int $mbY, int $searchRange = 16): array
    {
        if (!isset($this->refInts) || $this->refInts === null) {
            $this->refInts = unpack('C*', $refPlane);
        }

        $curFlat = [];
        foreach ($currentBlock as $row) {
            foreach ($row as $val) {
                $curFlat[] = $val;
            }
        }

        $origX = $mbX * 16;
        $origY = $mbY * 16;
        $blockW = min(16, $this->width - $origX);
        $blockH = min(16, $this->height - $origY);
        $refStride = $this->mbAlignedWidth;

        $minDx = max(-$searchRange, -$origX);
        $maxDx = min($searchRange, $this->mbAlignedWidth - $origX - $blockW);
        $minDy = max(-$searchRange, -$origY);
        $maxDy = min($searchRange, $this->mbAlignedHeight - $origY - $blockH);

        $ldspPattern = [
            [-2, 0], [2, 0], [0, -2], [0, 2],
            [-1, -1], [1, -1], [-1, 1], [1, 1],
        ];
        $sdspPattern = [
            [-1, 0], [1, 0], [0, -1], [0, 1],
        ];

        $bestDX = 0;
        $bestDY = 0;
        $bestSAD = $this->computeSADFast($curFlat, $origX, $origY, 0, 0, $blockW, $blockH, $refStride);

        for ($iter = 0; $iter < 10; $iter++) {
            $foundBetter = false;
            foreach ($ldspPattern as [$px, $py]) {
                $dx = $bestDX + $px;
                $dy = $bestDY + $py;
                if ($dx < $minDx || $dx > $maxDx || $dy < $minDy || $dy > $maxDy) {
                    continue;
                }
                $sad = $this->computeSADFast($curFlat, $origX, $origY, $dx, $dy, $blockW, $blockH, $refStride);
                if ($sad < $bestSAD) {
                    $bestSAD = $sad;
                    $bestDX = $dx;
                    $bestDY = $dy;
                    $foundBetter = true;
                }
            }
            if (!$foundBetter) break;
        }

        for ($iter = 0; $iter < 3; $iter++) {
            $foundBetter = false;
            foreach ($sdspPattern as [$px, $py]) {
                $dx = $bestDX + $px;
                $dy = $bestDY + $py;
                if ($dx < $minDx || $dx > $maxDx || $dy < $minDy || $dy > $maxDy) {
                    continue;
                }
                $sad = $this->computeSADFast($curFlat, $origX, $origY, $dx, $dy, $blockW, $blockH, $refStride);
                if ($sad < $bestSAD) {
                    $bestSAD = $sad;
                    $bestDX = $dx;
                    $bestDY = $dy;
                    $foundBetter = true;
                }
            }
            if (!$foundBetter) break;
        }

        // === 半像素/1/4像素精搜索 ===
        // 使用mcLumaBlock计算子像素位置的SAD
        $bestMVx = $bestDX * 4;
        $bestMVy = $bestDY * 4;
        $bestSAD = $this->computeSADSubpel($curFlat, $refPlane, $mbX, $mbY, $bestMVx, $bestMVy, $blockW, $blockH);

        // 先搜索半像素位置 (±2, ±2) 在1/4像素单位
        $halfPelPattern = [
            [-2, -2], [-2, 0], [-2, 2],
            [ 0, -2],          [ 0, 2],
            [ 2, -2], [ 2, 0], [ 2, 2],
        ];
        foreach ($halfPelPattern as [$ox, $oy]) {
            $mvx = $bestMVx + $ox;
            $mvy = $bestMVy + $oy;
            // 边界检查：整数像素位置不能越界
            $intDx = ($mvx >> 2) - $bestDX;
            $intDy = ($mvy >> 2) - $bestDY;
            if ($bestDX + $intDx < $minDx || $bestDX + $intDx > $maxDx ||
                $bestDY + $intDy < $minDy || $bestDY + $intDy > $maxDy) {
                continue;
            }
            $sad = $this->computeSADSubpel($curFlat, $refPlane, $mbX, $mbY, $mvx, $mvy, $blockW, $blockH);
            if ($sad < $bestSAD) {
                $bestSAD = $sad;
                $bestMVx = $mvx;
                $bestMVy = $mvy;
            }
        }

        // 再搜索1/4像素位置 (±1, 0), (0, ±1)
        $quarterPelPattern = [
            [-1, 0], [1, 0], [0, -1], [0, 1],
        ];
        foreach ($quarterPelPattern as [$ox, $oy]) {
            $mvx = $bestMVx + $ox;
            $mvy = $bestMVy + $oy;
            $intDx = ($mvx >> 2) - $bestDX;
            $intDy = ($mvy >> 2) - $bestDY;
            if ($bestDX + $intDx < $minDx || $bestDX + $intDx > $maxDx ||
                $bestDY + $intDy < $minDy || $bestDY + $intDy > $maxDy) {
                continue;
            }
            $sad = $this->computeSADSubpel($curFlat, $refPlane, $mbX, $mbY, $mvx, $mvy, $blockW, $blockH);
            if ($sad < $bestSAD) {
                $bestSAD = $sad;
                $bestMVx = $mvx;
                $bestMVy = $mvy;
            }
        }

        return [$bestMVx, $bestMVy, $bestSAD];
    }

    /**
     * 计算子像素位置的SAD（使用mcLumaBlock获取1/4像素精度预测块）
     */
    private function computeSADSubpel(array $curFlat, string $refPlane, int $mbX, int $mbY, int $mvx, int $mvy, int $blockW, int $blockH): int
    {
        $refX = $mbX * 64 + $mvx;
        $refY = $mbY * 64 + $mvy;
        $predBlock = $this->mcLumaBlock($refPlane, $refX, $refY, $this->mbAlignedWidth, $this->mbAlignedHeight);
        $sad = 0;
        $pos = 0;
        for ($y = 0; $y < $blockH; $y++) {
            for ($x = 0; $x < $blockW; $x++) {
                $diff = $curFlat[$pos] - $predBlock[$y][$x];
                if ($diff < 0) $diff = -$diff;
                $sad += $diff;
                $pos++;
            }
        }
        return $sad;
    }

    private function computeSADFast(array $curFlat, int $origX, int $origY, int $dx, int $dy, int $blockW, int $blockH, int $refStride): int
    {
        $rx = $origX + $dx;
        $ry = $origY + $dy;
        $refStart = $ry * $refStride + $rx + 1;
        $sad = 0;
        $pos = 0;
        $refInts = $this->refInts;

        for ($y = 0; $y < $blockH; $y++) {
            $rowOffset = $refStart + $y * $refStride;
            for ($x = 0; $x < $blockW; $x++) {
                $diff = $curFlat[$pos] - $refInts[$rowOffset + $x];
                if ($diff < 0) $diff = -$diff;
                $sad += $diff;
                $pos++;
            }
        }
        return $sad;
    }

    /**
     * 运动估计：二级搜索（粗搜+精搜）备份实现
     * 速度较慢但搜索更充分，可用于质量敏感场景
     * @return array [mvX, mvY, sad] 运动向量和SAD值（mvX/mvY为1/4像素单位）
     */
    public function motionEstimate16x16TwoLevel(array $currentBlock, string $refPlane, int $mbX, int $mbY, int $searchRange = 16): array
    {
        $refStride = $this->mbAlignedWidth;
        $refW = $this->mbAlignedWidth;
        $refH = $this->mbAlignedHeight;

        $bestMV = [0, 0];
        $bestSAD = PHP_INT_MAX;

        $origX = $mbX * 16;
        $origY = $mbY * 16;

        $blockW = min(16, $this->width - $origX);
        $blockH = min(16, $this->height - $origY);

        // 先检查(0,0)位置
        $sad00 = 0;
        for ($y = 0; $y < $blockH; $y++) {
            for ($x = 0; $x < $blockW; $x++) {
                $refIdx = ($origY + $y) * $refStride + ($origX + $x);
                $sad00 += abs($currentBlock[$y][$x] - ord($refPlane[$refIdx]));
            }
        }
        $bestSAD = $sad00;
        $bestDX = 0;
        $bestDY = 0;

        // 大步长粗搜索（步长=4）
        $coarseStep = 4;
        for ($dy = -$searchRange; $dy <= $searchRange; $dy += $coarseStep) {
            for ($dx = -$searchRange; $dx <= $searchRange; $dx += $coarseStep) {
                if ($dx == 0 && $dy == 0) continue;
                $rx = $origX + $dx;
                $ry = $origY + $dy;

                if ($rx < 0 || $rx + $blockW > $refW || $ry < 0 || $ry + $blockH > $refH) {
                    continue;
                }

                $sad = 0;
                for ($y = 0; $y < $blockH; $y++) {
                    for ($x = 0; $x < $blockW; $x++) {
                        $refIdx = ($ry + $y) * $refStride + ($rx + $x);
                        $sad += abs($currentBlock[$y][$x] - ord($refPlane[$refIdx]));
                    }
                }

                if ($sad < $bestSAD) {
                    $bestSAD = $sad;
                    $bestDX = $dx;
                    $bestDY = $dy;
                }
            }
        }

        // 小步长精搜索（在粗搜索最佳点周围±3，步长=1）
        $refineRange = 3;
        for ($dy = $bestDY - $refineRange; $dy <= $bestDY + $refineRange; $dy++) {
            for ($dx = $bestDX - $refineRange; $dx <= $bestDX + $refineRange; $dx++) {
                if ($dx == 0 && $dy == 0 && $bestDX == 0 && $bestDY == 0) continue;
                if (abs($dx) > $searchRange || abs($dy) > $searchRange) continue;
                $rx = $origX + $dx;
                $ry = $origY + $dy;

                if ($rx < 0 || $rx + $blockW > $refW || $ry < 0 || $ry + $blockH > $refH) {
                    continue;
                }

                $sad = 0;
                for ($y = 0; $y < $blockH; $y++) {
                    for ($x = 0; $x < $blockW; $x++) {
                        $refIdx = ($ry + $y) * $refStride + ($rx + $x);
                        $sad += abs($currentBlock[$y][$x] - ord($refPlane[$refIdx]));
                    }
                }

                if ($sad < $bestSAD) {
                    $bestSAD = $sad;
                    $bestDX = $dx;
                    $bestDY = $dy;
                }
            }
        }

        $bestMV = [$bestDX * 4, $bestDY * 4];
        return [$bestMV[0], $bestMV[1], $bestSAD];
    }
}
