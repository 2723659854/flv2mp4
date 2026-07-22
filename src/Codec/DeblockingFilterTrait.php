<?php

namespace Xiaosongshu\Flv2mp4\Codec;

trait DeblockingFilterTrait
{
    private const ALPHA_TABLE = [
        0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
        4, 4, 5, 6, 7, 8, 9, 10, 12, 13, 15, 17, 20, 22,
        25, 28, 32, 36, 40, 45, 50, 56, 63, 71, 80, 90,
        101, 113, 127, 144, 162, 182, 203, 226, 255, 255,
    ];

    private const BETA_TABLE = [
        0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
        2, 2, 2, 3, 3, 3, 3, 4, 4, 4, 6, 6, 7, 7, 8, 8,
        9, 9, 10, 10, 11, 11, 12, 12, 13, 13, 14, 14,
        15, 15, 16, 16, 17, 17, 18, 18,
    ];

    private const TC0_TABLE = [
        [0, 0, 0], [0, 0, 0], [0, 0, 0], [0, 0, 0], [0, 0, 0], [0, 0, 0],
        [0, 0, 0], [0, 0, 0], [0, 0, 0], [0, 0, 0], [0, 0, 0], [0, 0, 0],
        [0, 0, 0], [0, 0, 0], [0, 0, 0], [0, 0, 0], [0, 0, 0], [0, 0, 1],
        [0, 0, 1], [0, 0, 1], [0, 0, 1], [0, 1, 1], [0, 1, 1], [1, 1, 1],
        [1, 1, 1], [1, 1, 1], [1, 1, 1], [1, 1, 2], [1, 1, 2], [1, 1, 2],
        [1, 1, 2], [1, 2, 3], [1, 2, 3], [2, 2, 3], [2, 2, 4], [2, 3, 4],
        [2, 3, 4], [3, 3, 5], [3, 4, 6], [3, 4, 6], [4, 5, 7], [4, 5, 8],
        [4, 6, 9], [5, 7, 10], [6, 8, 11], [6, 8, 13], [7, 10, 14], [8, 11, 16],
        [9, 12, 18], [10, 13, 20], [11, 15, 23], [13, 17, 25],
    ];

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

        for ($edge = 0; $edge < 4; $edge++) {
            $isMbEdge = ($edge == 0);

            for ($pair = 0; $pair < 4; $pair++) {
                $pIntra = $curIntra;
                $qIntra = $curIntra;
                $pIpcm = $curIpcm;
                $qIpcm = $curIpcm;

                if ($isMbEdge && $edge == 0 && $mbX > 0) {
                    $leftIdx = ($mbY * $mbWidth + $mbX - 1);
                    $leftType = $this->mbTypeForDeblock[$leftIdx] ?? -1;
                    if ($leftType >= 0) {
                        if ($isIslice) {
                            $pIntra = ($leftType >= 0 && $leftType <= 24);
                            $pIpcm = ($leftType == 25);
                        } elseif ($isPslice) {
                            $pIntra = ($leftType >= 5 && $leftType <= 29);
                            $pIpcm = ($leftType == 30);
                        }
                    }
                }

                if ($pIpcm || $qIpcm) {
                    $bsVertical[$edge][$pair] = 0;
                } elseif ($pIntra || $qIntra) {
                    $bsVertical[$edge][$pair] = $isMbEdge ? 4 : 3;
                } else {
                    $bsVertical[$edge][$pair] = 2;
                }
            }
        }

        for ($edge = 0; $edge < 4; $edge++) {
            $isMbEdge = ($edge == 0);

            for ($pair = 0; $pair < 4; $pair++) {
                $pIntra = $curIntra;
                $qIntra = $curIntra;
                $pIpcm = $curIpcm;
                $qIpcm = $curIpcm;

                if ($isMbEdge && $edge == 0 && $mbY > 0) {
                    $topIdx = (($mbY - 1) * $mbWidth + $mbX);
                    $topType = $this->mbTypeForDeblock[$topIdx] ?? -1;
                    if ($topType >= 0) {
                        if ($isIslice) {
                            $pIntra = ($topType >= 0 && $topType <= 24);
                            $pIpcm = ($topType == 25);
                        } elseif ($isPslice) {
                            $pIntra = ($topType >= 5 && $topType <= 29);
                            $pIpcm = ($topType == 30);
                        }
                    }
                }

                if ($pIpcm || $qIpcm) {
                    $bsHorizontal[$edge][$pair] = 0;
                } elseif ($pIntra || $qIntra) {
                    $bsHorizontal[$edge][$pair] = $isMbEdge ? 4 : 3;
                } else {
                    $bsHorizontal[$edge][$pair] = 2;
                }
            }
        }

        return [$bsVertical, $bsHorizontal];
    }

    public function applyDeblockingFilter(): void
    {
        if ($this->disableDeblockingFilterIdc == 1 || ($this->forceDisableDeblock ?? false)) {
            return;
        }

        //echo "[DEBLOCK] Applying deblocking filter..." . PHP_EOL;
        //$startTime = microtime(true);

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

        //$endTime = microtime(true);
        //echo "[DEBLOCK] Deblocking filter completed in " . number_format($endTime - $startTime, 2) . "s" . PHP_EOL;
    }
}