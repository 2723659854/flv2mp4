<?php

namespace Xiaosongshu\Flv2mp4\Codec\Decode;

/**
 * @purpose 去块滤波器
 * @author yanglong
 * @time 2026年7月23日14:39:31
 */
trait DeblockingFilterTrait
{

    public int $sliceAlphaC0Offset = 0;
    public int $sliceBetaOffset = 0;
    public int $disableDeblockingFilterIdc = 0;
    public array $mbTypeForDeblock = [];
    public array $mbQpForDeblock = [];

    private function clip3(int $lo, int $hi, int $x): int
    {
        if ($x < $lo) return $lo;
        if ($x > $hi) return $hi;
        return $x;
    }

    private function clipPixel(int $x): int
    {
        return $this->clip3(0, 255, $x);
    }

    private function getThresholds(int $qp, int $alphaOffset, int $betaOffset): array
    {
        $indexA = $this->clip3(0, 51, $qp + $alphaOffset);
        $indexB = $this->clip3(0, 51, $qp + $betaOffset);
        return [self::ALPHA_TABLE[$indexA], self::BETA_TABLE[$indexB], $indexA];
    }

    private function getTc0(int $qp, int $alphaOffset, int $bs): int
    {
        $indexA = $this->clip3(0, 51, $qp + $alphaOffset);
        return self::TC0_TABLE[$indexA][$bs - 1];
    }

    private function avgQp(int $qpP, int $qpQ): int
    {
        return (int)(($qpP + $qpQ + 1) >> 1);
    }

    private function filterNormalLuma(int $p0, int $p1, int $p2, int $q0, int $q1, int $q2, int $alpha, int $beta, int $tc0): ?array
    {
        if (abs($p0 - $q0) >= $alpha || abs($p1 - $p0) >= $beta || abs($q1 - $q0) >= $beta) {
            return null;
        }

        $tc = $tc0;
        $newP1 = $p1;
        $newQ1 = $q1;

        if (abs($p2 - $p0) < $beta) {
            if ($tc0 !== 0) {
                $newP1 = $p1 + $this->clip3(-$tc0, $tc0, ((($p2 + (($p0 + $q0 + 1) >> 1)) >> 1) - $p1));
            }
            $tc++;
        }

        if (abs($q2 - $q0) < $beta) {
            if ($tc0 !== 0) {
                $newQ1 = $q1 + $this->clip3(-$tc0, $tc0, ((($q2 + (($p0 + $q0 + 1) >> 1)) >> 1) - $q1));
            }
            $tc++;
        }

        $delta = $this->clip3(-$tc, $tc, ((($q0 - $p0) * 4 + ($p1 - $q1) + 4) >> 3));
        $newP0 = $this->clipPixel($p0 + $delta);
        $newQ0 = $this->clipPixel($q0 - $delta);

        return [$newP0, $newP1, $newQ0, $newQ1];
    }

    private function filterStrongLuma(int $p0, int $p1, int $p2, int $p3, int $q0, int $q1, int $q2, int $q3, int $alpha, int $beta): ?array
    {
        if (abs($p0 - $q0) >= $alpha || abs($p1 - $p0) >= $beta || abs($q1 - $q0) >= $beta) {
            return null;
        }

        $ap = abs($p2 - $p0);
        $aq = abs($q2 - $q0);
        $smallGap = abs($p0 - $q0) < (($alpha >> 2) + 2);

        if ($smallGap) {
            if ($ap < $beta) {
                $newP0 = (int)((($p2 + 2 * $p1 + 2 * $p0 + 2 * $q0 + $q1 + 4) >> 3) & 0xFF);
                $newP1 = (int)((($p2 + $p1 + $p0 + $q0 + 2) >> 2) & 0xFF);
                $newP2 = (int)((($p3 * 2 + 3 * $p2 + $p1 + $p0 + $q0 + 4) >> 3) & 0xFF);
            } else {
                $newP0 = (int)((($p1 * 2 + $p0 + $q1 + 2) >> 2) & 0xFF);
                $newP1 = $p1 & 0xFF;
                $newP2 = $p2 & 0xFF;
            }

            if ($aq < $beta) {
                $newQ0 = (int)((($p1 + 2 * $p0 + 2 * $q0 + 2 * $q1 + $q2 + 4) >> 3) & 0xFF);
                $newQ1 = (int)((($p0 + $q0 + $q1 + $q2 + 2) >> 2) & 0xFF);
                $newQ2 = (int)((($q3 * 2 + 3 * $q2 + $q1 + $q0 + $p0 + 4) >> 3) & 0xFF);
            } else {
                $newQ0 = (int)((($q1 * 2 + $q0 + $p1 + 2) >> 2) & 0xFF);
                $newQ1 = $q1 & 0xFF;
                $newQ2 = $q2 & 0xFF;
            }
        } else {
            $newP0 = (int)((($p1 * 2 + $p0 + $q1 + 2) >> 2) & 0xFF);
            $newP1 = $p1 & 0xFF;
            $newP2 = $p2 & 0xFF;
            $newQ0 = (int)((($q1 * 2 + $q0 + $p1 + 2) >> 2) & 0xFF);
            $newQ1 = $q1 & 0xFF;
            $newQ2 = $q2 & 0xFF;
        }

        return [$newP0, $newP1, $newP2, $newQ0, $newQ1, $newQ2];
    }

    private function filterNormalChroma(int $p0, int $p1, int $q0, int $q1, int $alpha, int $beta, int $tc): ?array
    {
        if (abs($p0 - $q0) >= $alpha || abs($p1 - $p0) >= $beta || abs($q1 - $q0) >= $beta) {
            return null;
        }

        $delta = $this->clip3(-$tc, $tc, ((($q0 - $p0) * 4 + ($p1 - $q1) + 4) >> 3));
        $newP0 = $this->clipPixel($p0 + $delta);
        $newQ0 = $this->clipPixel($q0 - $delta);

        return [$newP0, $newQ0];
    }

    private function filterStrongChroma(int $p0, int $p1, int $q0, int $q1, int $alpha, int $beta): ?array
    {
        if (abs($p0 - $q0) >= $alpha || abs($p1 - $p0) >= $beta || abs($q1 - $q0) >= $beta) {
            return null;
        }

        $newP0 = (int)((($p1 * 2 + $p0 + $q1 + 2) >> 2) & 0xFF);
        $newQ0 = (int)((($q1 * 2 + $q0 + $p1 + 2) >> 2) & 0xFF);

        return [$newP0, $newQ0];
    }

    private function filterMbEdgeLuma(bool $isVertical, int $mbX, int $mbY, int $edge, array $bs, int $qp)
    {
        [$alpha, $beta, $indexA] = $this->getThresholds($qp, $this->sliceAlphaC0Offset, $this->sliceBetaOffset);
        if ($alpha == 0 || $beta == 0) {
            return;
        }

        $stride = $this->width;
        $mbBaseOffset = ($mbY * 16) * $stride + ($mbX * 16);

        $edgePixelOffset = $isVertical ? ($edge * 4) : ($edge * 4 * $stride);
        $base = $mbBaseOffset + $edgePixelOffset;
        $step = $isVertical ? 1 : $stride;

        for ($i = 0; $i < 4; $i++) {
            $curBs = $bs[$i];
            if ($curBs == 0) {
                continue;
            }

            for ($d = 0; $d < 4; $d++) {
                $off = $isVertical ? ($base + ($i * 4 + $d) * $stride) : ($base + $i * 4 + $d);

                if ($off - 4 * $step < 0 || $off + 3 * $step >= count($this->yPlane)) {
                    continue;
                }

                $p0 = $this->yPlane[$off - $step];
                $p1 = $this->yPlane[$off - 2 * $step];
                $p2 = $this->yPlane[$off - 3 * $step];
                $q0 = $this->yPlane[$off];
                $q1 = $this->yPlane[$off + $step];
                $q2 = $this->yPlane[$off + 2 * $step];

                if ($curBs < 4) {
                    $tc0 = $this->getTc0($qp, $this->sliceAlphaC0Offset, $curBs);
                    $result = $this->filterNormalLuma($p0, $p1, $p2, $q0, $q1, $q2, $alpha, $beta, $tc0);
                    if ($result !== null) {
                        [$newP0, $newP1, $newQ0, $newQ1] = $result;
                        $this->yPlane[$off - $step] = $newP0;
                        $this->yPlane[$off - 2 * $step] = $newP1;
                        $this->yPlane[$off] = $newQ0;
                        $this->yPlane[$off + $step] = $newQ1;
                    }
                } else {
                    if ($off - 4 * $step < 0 || $off + 3 * $step >= count($this->yPlane)) {
                        continue;
                    }
                    $p3 = $this->yPlane[$off - 4 * $step];
                    $q3 = $this->yPlane[$off + 3 * $step];
                    $result = $this->filterStrongLuma($p0, $p1, $p2, $p3, $q0, $q1, $q2, $q3, $alpha, $beta);
                    if ($result !== null) {
                        [$newP0, $newP1, $newP2, $newQ0, $newQ1, $newQ2] = $result;
                        $this->yPlane[$off - $step] = $newP0;
                        $this->yPlane[$off - 2 * $step] = $newP1;
                        $this->yPlane[$off - 3 * $step] = $newP2;
                        $this->yPlane[$off] = $newQ0;
                        $this->yPlane[$off + $step] = $newQ1;
                        $this->yPlane[$off + 2 * $step] = $newQ2;
                    }
                }
            }
        }
    }

    private function filterMbEdgeChroma(bool $isVertical, int $mbX, int $mbY, int $edge, array $bs, int $qp)
    {
        [$alpha, $beta, $indexA] = $this->getThresholds($qp, $this->sliceAlphaC0Offset, $this->sliceBetaOffset);
        if ($alpha == 0 || $beta == 0) {
            return;
        }

        $stride = (int)($this->width / 2);
        $mbBaseOffset = ($mbY * 8) * $stride + ($mbX * 8);

        $edgePixelOffset = $isVertical ? ($edge * 4) : ($edge * 4 * $stride);
        $base = $mbBaseOffset + $edgePixelOffset;
        $step = $isVertical ? 1 : $stride;

        foreach (['uPlane', 'vPlane'] as $planeName) {
            $plane = &$this->$planeName;

            for ($i = 0; $i < 4; $i++) {
                $curBs = $bs[$i];
                if ($curBs == 0) {
                    continue;
                }

                for ($d = 0; $d < 2; $d++) {
                    $off = $isVertical ? ($base + ($i * 2 + $d) * $stride) : ($base + $i * 2 + $d);

                    if ($off - 2 * $step < 0 || $off + $step >= count($plane)) {
                        continue;
                    }

                    $p0 = $plane[$off - $step];
                    $p1 = $plane[$off - 2 * $step];
                    $q0 = $plane[$off];
                    $q1 = $plane[$off + $step];

                    if ($curBs < 4) {
                        $tc = $this->getTc0($qp, $this->sliceAlphaC0Offset, $curBs) + 1;
                        $result = $this->filterNormalChroma($p0, $p1, $q0, $q1, $alpha, $beta, $tc);
                        if ($result !== null) {
                            [$newP0, $newQ0] = $result;
                            $plane[$off - $step] = $newP0;
                            $plane[$off] = $newQ0;
                        }
                    } else {
                        $result = $this->filterStrongChroma($p0, $p1, $q0, $q1, $alpha, $beta);
                        if ($result !== null) {
                            [$newP0, $newQ0] = $result;
                            $plane[$off - $step] = $newP0;
                            $plane[$off] = $newQ0;
                        }
                    }
                }
            }
        }
    }

    private function computeBoundaryStrengths(int $mbX, int $mbY): array
    {
        $mbWidth = $this->picWidthInMbs;
        $mbHeight = $this->picHeightInMbs;
        $mbIdx = $mbY * $mbWidth + $mbX;
        $curType = $this->mbTypeForDeblock[$mbIdx] ?? -1;
        $curNnz = $this->mbNnzForDeblock[$mbIdx] ?? array_fill(0, 24, 0);
        $curMv = $this->mbMvForDeblock[$mbIdx] ?? array_fill(0, 16, [0, 0]);
        $curRef = $this->mbRefForDeblock[$mbIdx] ?? array_fill(0, 16, 0);
        $sliceType = $this->currentSliceType;
        $isIslice = ($sliceType === 2 || $sliceType === 4);
        $isPslice = ($sliceType === 0 || $sliceType === 5);

        $curIntra = false;
        $curIpcm = false;
        if ($curType >= 0) {
            if ($isIslice) {
                $curIntra = ($curType >= 0 && $curType <= 24);
                $curIpcm = ($curType == 25);
            } elseif ($isPslice) {
                $curIntra = ($curType >= 5 && $curType <= 29);
                $curIpcm = ($curType == 30);
            }
        }

        $bsVertical = [[0, 0, 0, 0], [0, 0, 0, 0], [0, 0, 0, 0], [0, 0, 0, 0]];
        $bsHorizontal = [[0, 0, 0, 0], [0, 0, 0, 0], [0, 0, 0, 0], [0, 0, 0, 0]];

        // 垂直边界: Q块在(col=edge, row=pair), P块在(col=edge-1, row=pair)
        for ($edge = 0; $edge < 4; $edge++) {
            $isMbEdge = ($edge == 0);

            for ($pair = 0; $pair < 4; $pair++) {
                $qBx = $edge;
                $qBy = $pair;
                $qIdx = $qBy * 4 + $qBx;
                $qIntra = $curIntra;
                $qIpcm = $curIpcm;
                $qNnz = $curNnz[$qIdx] ?? 0;
                $qMv = $curMv[$qIdx] ?? [0, 0];
                $qRef = $curRef[$qIdx] ?? 0;

                $pIntra = $curIntra;
                $pIpcm = $curIpcm;
                $pNnz = 0;
                $pMv = [0, 0];
                $pRef = 0;

                if ($isMbEdge && $mbX > 0) {
                    $leftIdx = $mbY * $mbWidth + $mbX - 1;
                    $leftType = $this->mbTypeForDeblock[$leftIdx] ?? -1;
                    $leftNnz = $this->mbNnzForDeblock[$leftIdx] ?? array_fill(0, 24, 0);
                    $leftMv = $this->mbMvForDeblock[$leftIdx] ?? array_fill(0, 16, [0, 0]);
                    $leftRef = $this->mbRefForDeblock[$leftIdx] ?? array_fill(0, 16, 0);
                    $pBx = 3;
                    $pBy = $qBy;
                    $pIdx = $pBy * 4 + $pBx;
                    $pNnz = $leftNnz[$pIdx] ?? 0;
                    $pMv = $leftMv[$pIdx] ?? [0, 0];
                    $pRef = $leftRef[$pIdx] ?? 0;
                    if ($leftType >= 0) {
                        if ($isIslice) {
                            $pIntra = ($leftType >= 0 && $leftType <= 24);
                            $pIpcm = ($leftType == 25);
                        } elseif ($isPslice) {
                            $pIntra = ($leftType >= 5 && $leftType <= 29);
                            $pIpcm = ($leftType == 30);
                        }
                    }
                } elseif (!$isMbEdge) {
                    $pBx = $qBx - 1;
                    $pBy = $qBy;
                    $pIdx = $pBy * 4 + $pBx;
                    $pNnz = $curNnz[$pIdx] ?? 0;
                    $pMv = $curMv[$pIdx] ?? [0, 0];
                    $pRef = $curRef[$pIdx] ?? 0;
                }

                $bsVertical[$edge][$pair] = $this->computeBsSingle(
                    $isMbEdge, $pIntra, $qIntra, $pIpcm, $qIpcm,
                    $pNnz, $qNnz, $pMv, $qMv, $pRef, $qRef
                );
            }
        }

        // 水平边界: Q块在(col=pair, row=edge), P块在(col=pair, row=edge-1)
        for ($edge = 0; $edge < 4; $edge++) {
            $isMbEdge = ($edge == 0);

            for ($pair = 0; $pair < 4; $pair++) {
                $qBx = $pair;
                $qBy = $edge;
                $qIdx = $qBy * 4 + $qBx;
                $qIntra = $curIntra;
                $qIpcm = $curIpcm;
                $qNnz = $curNnz[$qIdx] ?? 0;
                $qMv = $curMv[$qIdx] ?? [0, 0];
                $qRef = $curRef[$qIdx] ?? 0;

                $pIntra = $curIntra;
                $pIpcm = $curIpcm;
                $pNnz = 0;
                $pMv = [0, 0];
                $pRef = 0;

                if ($isMbEdge && $mbY > 0) {
                    $topIdx = ($mbY - 1) * $mbWidth + $mbX;
                    $topType = $this->mbTypeForDeblock[$topIdx] ?? -1;
                    $topNnz = $this->mbNnzForDeblock[$topIdx] ?? array_fill(0, 24, 0);
                    $topMv = $this->mbMvForDeblock[$topIdx] ?? array_fill(0, 16, [0, 0]);
                    $topRef = $this->mbRefForDeblock[$topIdx] ?? array_fill(0, 16, 0);
                    $pBx = $qBx;
                    $pBy = 3;
                    $pIdx = $pBy * 4 + $pBx;
                    $pNnz = $topNnz[$pIdx] ?? 0;
                    $pMv = $topMv[$pIdx] ?? [0, 0];
                    $pRef = $topRef[$pIdx] ?? 0;
                    if ($topType >= 0) {
                        if ($isIslice) {
                            $pIntra = ($topType >= 0 && $topType <= 24);
                            $pIpcm = ($topType == 25);
                        } elseif ($isPslice) {
                            $pIntra = ($topType >= 5 && $topType <= 29);
                            $pIpcm = ($topType == 30);
                        }
                    }
                } elseif (!$isMbEdge) {
                    $pBx = $qBx;
                    $pBy = $qBy - 1;
                    $pIdx = $pBy * 4 + $pBx;
                    $pNnz = $curNnz[$pIdx] ?? 0;
                    $pMv = $curMv[$pIdx] ?? [0, 0];
                    $pRef = $curRef[$pIdx] ?? 0;
                }

                $bsHorizontal[$edge][$pair] = $this->computeBsSingle(
                    $isMbEdge, $pIntra, $qIntra, $pIpcm, $qIpcm,
                    $pNnz, $qNnz, $pMv, $qMv, $pRef, $qRef
                );
            }
        }

        return [$bsVertical, $bsHorizontal];
    }

    private function computeBsSingle($isMbEdge, $pIntra, $qIntra, $pIpcm, $qIpcm,
                                     $pNnz, $qNnz, $pMv, $qMv, $pRef, $qRef): int
    {
        if ($pIpcm || $qIpcm) {
            return 0;
        }
        if ($pIntra || $qIntra) {
            return $isMbEdge ? 4 : 3;
        }
        if ($pNnz != 0 || $qNnz != 0) {
            return 2;
        }
        // 检查MV和参考帧是否不同
        $mvDiffX = abs($pMv[0] - $qMv[0]);
        $mvDiffY = abs($pMv[1] - $qMv[1]);
        if ($pRef != $qRef || $mvDiffX >= 4 || $mvDiffY >= 4) {
            return 1;
        }
        return 0;
    }

    public function applyDeblockingFilter(): void
    {
        if ($this->disableDeblockingFilterIdc == 1 || ($this->forceDisableDeblock ?? false)) {
            return;
        }

        $mbWidth = $this->picWidthInMbs;
        $mbHeight = $this->picHeightInMbs;

        for ($mbY = 0; $mbY < $mbHeight; $mbY++) {
            for ($mbX = 0; $mbX < $mbWidth; $mbX++) {
                $mbIdx = $mbY * $mbWidth + $mbX;
                $curType = $this->mbTypeForDeblock[$mbIdx] ?? 0;
                $curQp = $this->mbQpForDeblock[$mbIdx] ?? 26;

                if ($curType < 0) {
                    continue;
                }

                [$bsVertical, $bsHorizontal] = $this->computeBoundaryStrengths($mbX, $mbY);

                for ($edge = 0; $edge < 4; $edge++) {
                    $isMbEdge = ($edge == 0);

                    if ($isMbEdge && $mbX == 0) {
                        continue;
                    }

                    $qp = $isMbEdge && $mbX > 0 ? $this->avgQp($curQp, $this->mbQpForDeblock[($mbY * $mbWidth + $mbX - 1)] ?? $curQp) : $curQp;
                    $this->filterMbEdgeLuma(true, $mbX, $mbY, $edge, $bsVertical[$edge], $qp);

                    $chromaQpIndex = max(0, min(51, $qp + $this->chromaQpIndexOffset));
                    $chromaQp = self::CHROMA_QP_TABLE[$chromaQpIndex];
                    if ($edge == 0 || $edge == 2) {
                        $chromaEdge = (int)($edge / 2);
                        $this->filterMbEdgeChroma(true, $mbX, $mbY, $chromaEdge, $bsVertical[$edge], $chromaQp);
                    }
                }

                for ($edge = 0; $edge < 4; $edge++) {
                    $isMbEdge = ($edge == 0);

                    if ($isMbEdge && $mbY == 0) {
                        continue;
                    }

                    $qp = $isMbEdge && $mbY > 0 ? $this->avgQp($curQp, $this->mbQpForDeblock[(($mbY - 1) * $mbWidth + $mbX)] ?? $curQp) : $curQp;
                    $this->filterMbEdgeLuma(false, $mbX, $mbY, $edge, $bsHorizontal[$edge], $qp);

                    $chromaQpIndex = max(0, min(51, $qp + $this->chromaQpIndexOffset));
                    $chromaQp = self::CHROMA_QP_TABLE[$chromaQpIndex];
                    if ($edge == 0 || $edge == 2) {
                        $chromaEdge = (int)($edge / 2);
                        $this->filterMbEdgeChroma(false, $mbX, $mbY, $chromaEdge, $bsHorizontal[$edge], $chromaQp);
                    }
                }
            }
        }
    }
}