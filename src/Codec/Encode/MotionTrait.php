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
        $bestSAD = $this->computeSADFast($curFlat, $origX, $origY, 0, 0, $blockW, $blockH, $refStride, PHP_INT_MAX);
        $candidateSads = ['0,0' => $bestSAD];

        for ($iter = 0; $iter < 10; $iter++) {
            $foundBetter = false;
            foreach ($ldspPattern as [$px, $py]) {
                $dx = $bestDX + $px;
                $dy = $bestDY + $py;
                if ($dx < $minDx || $dx > $maxDx || $dy < $minDy || $dy > $maxDy) {
                    continue;
                }
                $candidateKey = $dx . ',' . $dy;
                if (isset($candidateSads[$candidateKey])) {
                    $sad = $candidateSads[$candidateKey];
                } else {
                    $sad = $this->computeSADFast($curFlat, $origX, $origY, $dx, $dy, $blockW, $blockH, $refStride, $bestSAD);
                    $candidateSads[$candidateKey] = $sad;
                }
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
                $candidateKey = $dx . ',' . $dy;
                if (isset($candidateSads[$candidateKey])) {
                    $sad = $candidateSads[$candidateKey];
                } else {
                    $sad = $this->computeSADFast($curFlat, $origX, $origY, $dx, $dy, $blockW, $blockH, $refStride, $bestSAD);
                    $candidateSads[$candidateKey] = $sad;
                }
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
        // 整数 MV 的 MC 像素与整数 SAD 候选完全等价，复用已计算结果。
        $bestSAD = $candidateSads[$bestDX . ',' . $bestDY];

        [$bestMVx, $bestMVy, $bestSAD] = $this->refineSubpelShared(
            $curFlat, $origX, $origY, $bestDX, $bestDY, $bestSAD, $blockW, $blockH,
            $minDx, $maxDx, $minDy, $maxDy
        );

        return [$bestMVx, $bestMVy, $bestSAD];
    }

    private function refineSubpelShared(
        array $curFlat, int $origX, int $origY, int $bestDX, int $bestDY, int $bestSAD,
        int $blockW, int $blockH, int $minDx, int $maxDx, int $minDy, int $maxDy
    ): array {
        $stride = $this->mbAlignedWidth;
        $height = $this->mbAlignedHeight;
        $ref = $this->refInts;
        $baseX = $origX + $bestDX;
        $baseY = $origY + $bestDY;
        $bufW = $blockW + 12;

        $xs = [];
        for ($x = -8; $x <= $blockW + 8; $x++) {
            $rx = $baseX + $x;
            $xs[$x + 8] = $rx < 0 ? 0 : ($rx >= $stride ? $stride - 1 : $rx);
        }
        $ys = [];
        for ($y = -8; $y <= $blockH + 8; $y++) {
            $ry = $baseY + $y;
            $ys[$y + 8] = $ry < 0 ? 0 : ($ry >= $height ? $height - 1 : $ry);
        }

        $hFull = [];
        $h = [];
        for ($y = -8; $y <= $blockH + 8; $y++) {
            $row = $ys[$y + 8] * $stride + 1;
            for ($x = -6; $x <= $blockW + 5; $x++) {
                $xi = $x + 8;
                $full = $ref[$row + $xs[$xi - 2]] - 5 * $ref[$row + $xs[$xi - 1]]
                    + 20 * $ref[$row + $xs[$xi]] + 20 * $ref[$row + $xs[$xi + 1]]
                    - 5 * $ref[$row + $xs[$xi + 2]] + $ref[$row + $xs[$xi + 3]];
                $hFull[($y + 8) * $bufW + $x + 6] = $full;
                if ($y >= -6 && $y <= $blockH + 5) {
                    $half = ($full + 16) >> 5;
                    $h[($y + 6) * $bufW + $x + 6] = $half < 0 ? 0 : ($half > 255 ? 255 : $half);
                }
            }
        }

        $v = [];
        $c = [];
        for ($y = -6; $y <= $blockH + 5; $y++) {
            $yi = $y + 8;
            $r0 = $ys[$yi - 2] * $stride + 1;
            $r1 = $ys[$yi - 1] * $stride + 1;
            $r2 = $ys[$yi] * $stride + 1;
            $r3 = $ys[$yi + 1] * $stride + 1;
            $r4 = $ys[$yi + 2] * $stride + 1;
            $r5 = $ys[$yi + 3] * $stride + 1;
            for ($x = -6; $x <= $blockW + 5; $x++) {
                $idx = ($y + 6) * $bufW + $x + 6;
                $rx = $xs[$x + 8];
                $full = $ref[$r0 + $rx] - 5 * $ref[$r1 + $rx]
                    + 20 * $ref[$r2 + $rx] + 20 * $ref[$r3 + $rx]
                    - 5 * $ref[$r4 + $rx] + $ref[$r5 + $rx];
                $half = ($full + 16) >> 5;
                $v[$idx] = $half < 0 ? 0 : ($half > 255 ? 255 : $half);

                $hf = ($y + 6) * $bufW + $x + 6;
                $full = $hFull[$hf] - 5 * $hFull[$hf + $bufW]
                    + 20 * $hFull[$hf + 2 * $bufW] + 20 * $hFull[$hf + 3 * $bufW]
                    - 5 * $hFull[$hf + 4 * $bufW] + $hFull[$hf + 5 * $bufW];
                $center = ($full + 512) >> 10;
                $c[$idx] = $center < 0 ? 0 : ($center > 255 ? 255 : $center);
            }
        }

        $bestMVx = $bestDX * 4;
        $bestMVy = $bestDY * 4;
        $halfPattern = [
            [-2, -2], [-2, 0], [-2, 2],
            [ 0, -2],          [ 0, 2],
            [ 2, -2], [ 2, 0], [ 2, 2],
        ];
        foreach ($halfPattern as [$ox, $oy]) {
            $mvx = $bestMVx + $ox;
            $mvy = $bestMVy + $oy;
            $dx = $mvx >> 2;
            $dy = $mvy >> 2;
            if ($dx < $minDx || $dx > $maxDx || $dy < $minDy || $dy > $maxDy) {
                continue;
            }
            $fracX = $mvx & 3;
            $fracY = $mvy & 3;
            $offX = $dx - $bestDX;
            $offY = $dy - $bestDY;
            $sad = 0;
            for ($y = 0; $y < $blockH; $y++) {
                $bi = ($y + $offY + 6) * $bufW + $offX + 6;
                $cur = $y * 16;
                for ($x = 0; $x < $blockW; $x++, $bi++, $cur++) {
                    if ($fracX === 0 && $fracY === 0) {
                        $pred = $ref[$ys[$y + $offY + 8] * $stride + $xs[$x + $offX + 8] + 1];
                    } elseif ($fracY === 0) {
                        $pred = $h[$bi];
                    } elseif ($fracX === 0) {
                        $pred = $v[$bi];
                    } else {
                        $pred = $c[$bi];
                    }
                    $diff = $curFlat[$cur] - $pred;
                    $sad += $diff < 0 ? -$diff : $diff;
                    if ($sad >= $bestSAD) break 2;
                }
            }
            if ($sad < $bestSAD) {
                $bestSAD = $sad;
                $bestMVx = $mvx;
                $bestMVy = $mvy;
            }
        }

        $quarterPattern = [[-1, 0], [1, 0], [0, -1], [0, 1]];
        foreach ($quarterPattern as [$ox, $oy]) {
            $mvx = $bestMVx + $ox;
            $mvy = $bestMVy + $oy;
            $dx = $mvx >> 2;
            $dy = $mvy >> 2;
            if ($dx < $minDx || $dx > $maxDx || $dy < $minDy || $dy > $maxDy) {
                continue;
            }
            $fracX = $mvx & 3;
            $fracY = $mvy & 3;
            $offX = $dx - $bestDX;
            $offY = $dy - $bestDY;
            $sad = 0;
            for ($y = 0; $y < $blockH; $y++) {
                $bi = ($y + $offY + 6) * $bufW + $offX + 6;
                $cur = $y * 16;
                for ($x = 0; $x < $blockW; $x++, $bi++, $cur++) {
                    $integer = $ref[$ys[$y + $offY + 8] * $stride + $xs[$x + $offX + 8] + 1];
                    if ($fracX === 0 && $fracY === 0) {
                        $pred = $integer;
                    } elseif ($fracY === 0) {
                        $half = $h[$bi];
                        $pred = $fracX === 1 ? (($integer + $half + 1) >> 1)
                            : ($fracX === 2 ? $half : (($half + $ref[$ys[$y + $offY + 8] * $stride + $xs[$x + $offX + 9] + 1] + 1) >> 1));
                    } elseif ($fracX === 0) {
                        $half = $v[$bi];
                        $pred = $fracY === 1 ? (($integer + $half + 1) >> 1)
                            : ($fracY === 2 ? $half : (($half + $ref[$ys[$y + $offY + 9] * $stride + $xs[$x + $offX + 8] + 1] + 1) >> 1));
                    } elseif ($fracX === 2) {
                        $center = $c[$bi];
                        $pred = $fracY === 2 ? $center
                            : (($h[$bi + ($fracY === 1 ? 0 : $bufW)] + $center + 1) >> 1);
                    } elseif ($fracY === 2) {
                        $pred = ($c[$bi] + $v[$bi + ($fracX === 3 ? 1 : 0)] + 1) >> 1;
                    } else {
                        $pred = ($h[$bi + ($fracY === 1 ? 0 : $bufW)]
                            + $v[$bi + ($fracX === 3 ? 1 : 0)] + 1) >> 1;
                    }
                    $diff = $curFlat[$cur] - $pred;
                    $sad += $diff < 0 ? -$diff : $diff;
                    if ($sad >= $bestSAD) break 2;
                }
            }
            if ($sad < $bestSAD) {
                $bestSAD = $sad;
                $bestMVx = $mvx;
                $bestMVy = $mvy;
            }
        }

        return [$bestMVx, $bestMVy, $bestSAD];
    }

    /**
     * 计算子像素位置的SAD，融合运动补偿与像素比较，避免构造完整预测块。
     */
    private function computeSADSubpel(array $curFlat, string $refPlane, int $mbX, int $mbY, int $mvx, int $mvy, int $blockW, int $blockH, int $cutoff): int
    {
        $refX = $mbX * 64 + $mvx;
        $refY = $mbY * 64 + $mvy;
        $fracX = $refX & 3;
        $fracY = $refY & 3;
        $intX = $refX >> 2;
        $intY = $refY >> 2;
        $stride = $this->mbAlignedWidth;
        $height = $this->mbAlignedHeight;
        $ref = $this->refInts;
        $sad = 0;

        $xs = [];
        for ($x = -2; $x < $blockW + 3; $x++) {
            $xs[$x + 2] = max(0, min($stride - 1, $intX + $x));
        }
        $ys = [];
        for ($y = -2; $y < $blockH + 3; $y++) {
            $ys[$y + 2] = max(0, min($height - 1, $intY + $y));
        }

        $h = [];
        $hFull = [];
        $needC = ($fracX === 2 && $fracY !== 0) || ($fracY === 2 && $fracX !== 0);
        $needH = $fracX !== 0 && ($fracY === 0 || $fracX === 2 || ($fracX !== 2 && $fracY !== 2));
        if ($needH || $needC) {
            $startY = ($fracY === 0) ? 0 : -2;
            $rows = ($fracY === 0) ? $blockH : $blockH + 5;
            for ($y = 0; $y < $rows; $y++) {
                $base = $ys[$startY + $y + 2] * $stride + 1;
                for ($x = 0; $x < $blockW; $x++) {
                    $full = $ref[$base + $xs[$x]] - 5 * $ref[$base + $xs[$x + 1]]
                        + 20 * $ref[$base + $xs[$x + 2]] + 20 * $ref[$base + $xs[$x + 3]]
                        - 5 * $ref[$base + $xs[$x + 4]] + $ref[$base + $xs[$x + 5]];
                    $idx = $y * $blockW + $x;
                    if ($needC) $hFull[$idx] = $full;
                    if ($needH) {
                        $half = ($full + 16) >> 5;
                        $h[$idx] = $half < 0 ? 0 : ($half > 255 ? 255 : $half);
                    }
                }
            }
        }

        $v = [];
        $needV = $fracY !== 0 && ($fracX === 0 || $fracY === 2 || ($fracX !== 2 && $fracY !== 2));
        $vCols = $blockW + (($fracX !== 0 && $needV) ? 1 : 0);
        if ($needV) {
            for ($y = 0; $y < $blockH; $y++) {
                for ($x = 0; $x < $vCols; $x++) {
                    $rx = $xs[$x + 2];
                    $full = $ref[$ys[$y] * $stride + $rx + 1]
                        - 5 * $ref[$ys[$y + 1] * $stride + $rx + 1]
                        + 20 * $ref[$ys[$y + 2] * $stride + $rx + 1]
                        + 20 * $ref[$ys[$y + 3] * $stride + $rx + 1]
                        - 5 * $ref[$ys[$y + 4] * $stride + $rx + 1]
                        + $ref[$ys[$y + 5] * $stride + $rx + 1];
                    $half = ($full + 16) >> 5;
                    $v[$y * $vCols + $x] = $half < 0 ? 0 : ($half > 255 ? 255 : $half);
                }
            }
        }

        for ($y = 0; $y < $blockH; $y++) {
            for ($x = 0; $x < $blockW; $x++) {
                if ($fracX === 0 && $fracY === 0) {
                    $pred = $ref[$ys[$y + 2] * $stride + $xs[$x + 2] + 1];
                } elseif ($fracY === 0) {
                    $half = $h[$y * $blockW + $x];
                    if ($fracX === 1) {
                        $pred = ($ref[$ys[$y + 2] * $stride + $xs[$x + 2] + 1] + $half + 1) >> 1;
                    } elseif ($fracX === 2) {
                        $pred = $half;
                    } else {
                        $pred = ($half + $ref[$ys[$y + 2] * $stride + $xs[$x + 3] + 1] + 1) >> 1;
                    }
                } elseif ($fracX === 0) {
                    $half = $v[$y * $vCols + $x];
                    if ($fracY === 1) {
                        $pred = ($ref[$ys[$y + 2] * $stride + $xs[$x + 2] + 1] + $half + 1) >> 1;
                    } elseif ($fracY === 2) {
                        $pred = $half;
                    } else {
                        $pred = ($half + $ref[$ys[$y + 3] * $stride + $xs[$x + 2] + 1] + 1) >> 1;
                    }
                } elseif ($needC) {
                    $idx = $y * $blockW + $x;
                    $full = $hFull[$idx] - 5 * $hFull[$idx + $blockW]
                        + 20 * $hFull[$idx + 2 * $blockW] + 20 * $hFull[$idx + 3 * $blockW]
                        - 5 * $hFull[$idx + 4 * $blockW] + $hFull[$idx + 5 * $blockW];
                    $center = ($full + 512) >> 10;
                    $center = $center < 0 ? 0 : ($center > 255 ? 255 : $center);
                    if ($fracX === 2) {
                        $pred = $fracY === 2 ? $center : (($h[($y + ($fracY === 1 ? 2 : 3)) * $blockW + $x] + $center + 1) >> 1);
                    } else {
                        $pred = ($center + $v[$y * $vCols + $x + ($fracX === 3 ? 1 : 0)] + 1) >> 1;
                    }
                } else {
                    $hIdx = $y + ($fracY === 1 ? 2 : 3);
                    $vIdx = $x + ($fracX === 3 ? 1 : 0);
                    $pred = ($h[$hIdx * $blockW + $x] + $v[$y * $vCols + $vIdx] + 1) >> 1;
                }

                $diff = $curFlat[$y * 16 + $x] - $pred;
                if ($diff < 0) $diff = -$diff;
                $sad += $diff;
                if ($sad >= $cutoff) {
                    return $sad;
                }
            }
        }
        return $sad;
    }

    private function computeSADFast(array $curFlat, int $origX, int $origY, int $dx, int $dy, int $blockW, int $blockH, int $refStride, int $cutoff): int
    {
        $rx = $origX + $dx;
        $ry = $origY + $dy;
        $refStart = $ry * $refStride + $rx + 1;
        $sad = 0;
        $refInts = $this->refInts;

        for ($y = 0; $y < $blockH; $y++) {
            $rowOffset = $refStart + $y * $refStride;
            for ($x = 0; $x < $blockW; $x++) {
                $diff = $curFlat[$y * 16 + $x] - $refInts[$rowOffset + $x];
                if ($diff < 0) $diff = -$diff;
                $sad += $diff;
                if ($sad >= $cutoff) {
                    return $sad;
                }
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
