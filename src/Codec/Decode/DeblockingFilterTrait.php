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

    /** @var int[] 运动向量缺失时复用的零值，避免在热循环中重复创建数组。 */
    private array $deblockZeroMv = [0, 0];

    private int $deblockYStride = 0;
    private int $deblockUvStride = 0;
    private array $deblockThresholdCache = [];
    private array $deblockTc0Cache = [];
    private array $deblockChromaQpCache = [];

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

    private function getThresholds(int $qp, int $alphaOffset, int $betaOffset, int &$alpha, int &$beta): void
    {
        if (isset($this->deblockThresholdCache[$qp])) {
            [$alpha, $beta] = $this->deblockThresholdCache[$qp];
            return;
        }

        $indexA = $this->clip3(0, 51, $qp + $alphaOffset);
        $indexB = $this->clip3(0, 51, $qp + $betaOffset);
        $alpha = self::ALPHA_TABLE[$indexA];
        $beta = self::BETA_TABLE[$indexB];
        $this->deblockThresholdCache[$qp] = [$alpha, $beta];
    }

    private function getTc0(int $qp, int $alphaOffset, int $bs): int
    {
        $key = ($qp << 2) | $bs;
        if (isset($this->deblockTc0Cache[$key])) {
            return $this->deblockTc0Cache[$key];
        }

        $indexA = $this->clip3(0, 51, $qp + $alphaOffset);
        $tc0 = self::TC0_TABLE[$indexA][$bs - 1];
        $this->deblockTc0Cache[$key] = $tc0;
        return $tc0;
    }

    private function getChromaQp(int $qp): int
    {
        if (isset($this->deblockChromaQpCache[$qp])) {
            return $this->deblockChromaQpCache[$qp];
        }

        $chromaQpIndex = $this->clip3(
            0,
            51,
            $qp + $this->chromaQpIndexOffset
        );
        $chromaQp = self::CHROMA_QP_TABLE[$chromaQpIndex];
        $this->deblockChromaQpCache[$qp] = $chromaQp;
        return $chromaQp;
    }

    private function avgQp(int $qpP, int $qpQ): int
    {
        return (int)(($qpP + $qpQ + 1) >> 1);
    }

    private function filterStrongLuma(int $p0, int $p1, int $p2, int $p3, int $q0, int $q1, int $q2, int $q3, int $alpha, int $beta,
                                      &$newP0, &$newP1, &$newP2, &$newQ0, &$newQ1, &$newQ2): bool
    {
        if (abs($p0 - $q0) >= $alpha || abs($p1 - $p0) >= $beta || abs($q1 - $q0) >= $beta) {
            return false;
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

        return true;
    }

    private function filterStrongChroma(int $p0, int $p1, int $q0, int $q1, int $alpha, int $beta,
                                        &$newP0, &$newQ0): bool
    {
        if (abs($p0 - $q0) >= $alpha || abs($p1 - $p0) >= $beta || abs($q1 - $q0) >= $beta) {
            return false;
        }

        $newP0 = (int)((($p1 * 2 + $p0 + $q1 + 2) >> 2) & 0xFF);
        $newQ0 = (int)((($q1 * 2 + $q0 + $p1 + 2) >> 2) & 0xFF);

        return true;
    }

    private function filterVerticalLuma(int $mbX, int $mbY, int $edge, array $bs, int $qp): void
    {
        $alpha = $beta = 0;
        $this->getThresholds($qp, $this->sliceAlphaC0Offset, $this->sliceBetaOffset, $alpha, $beta);
        if ($alpha == 0 || $beta == 0) {
            return;
        }

        $stride = $this->deblockYStride;
        $plane = &$this->yPlane;
        $base = ($mbY * 16) * $stride + ($mbX * 16) + $edge * 4;

        for ($i = 0; $i < 4; $i++) {
            $curBs = $bs[$i];
            if ($curBs == 0) {
                continue;
            }
            $tc0 = $curBs < 4 ? $this->getTc0($qp, $this->sliceAlphaC0Offset, $curBs) : 0;
            $off = $base + $i * 4 * $stride;

            for ($d = 0; $d < 4; $d++, $off += $stride) {
                $p0 = ord($plane[$off - 1]);
                $p1 = ord($plane[$off - 2]);
                $p2 = ord($plane[$off - 3]);
                $q0 = ord($plane[$off]);
                $q1 = ord($plane[$off + 1]);
                $q2 = ord($plane[$off + 2]);

                if ($curBs < 4) {
                    if (abs($p0 - $q0) < $alpha && abs($p1 - $p0) < $beta && abs($q1 - $q0) < $beta) {
                        $tc = $tc0;
                        $newP1 = $p1;
                        $newQ1 = $q1;
                        $p0q0 = ($p0 + $q0 + 1) >> 1;

                        if (abs($p2 - $p0) < $beta) {
                            if ($tc0 !== 0) {
                                $newP1 = $p1 + $this->clip3(-$tc0, $tc0, ((($p2 + $p0q0) >> 1) - $p1));
                            }
                            $tc++;
                        }

                        if (abs($q2 - $q0) < $beta) {
                            if ($tc0 !== 0) {
                                $newQ1 = $q1 + $this->clip3(-$tc0, $tc0, ((($q2 + $p0q0) >> 1) - $q1));
                            }
                            $tc++;
                        }

                        $delta = $this->clip3(-$tc, $tc, ((($q0 - $p0) * 4 + ($p1 - $q1) + 4) >> 3));
                        $plane[$off - 1] = chr($this->clipPixel($p0 + $delta));
                        $plane[$off - 2] = chr($newP1);
                        $plane[$off] = chr($this->clipPixel($q0 - $delta));
                        $plane[$off + 1] = chr($newQ1);
                    }
                } else {
                    $p3 = ord($plane[$off - 4]);
                    $q3 = ord($plane[$off + 3]);
                    if ($this->filterStrongLuma(
                        $p0, $p1, $p2, $p3, $q0, $q1, $q2, $q3, $alpha, $beta,
                        $newP0, $newP1, $newP2, $newQ0, $newQ1, $newQ2
                    )) {
                        $plane[$off - 1] = chr($newP0);
                        $plane[$off - 2] = chr($newP1);
                        $plane[$off - 3] = chr($newP2);
                        $plane[$off] = chr($newQ0);
                        $plane[$off + 1] = chr($newQ1);
                        $plane[$off + 2] = chr($newQ2);
                    }
                }
            }
        }
    }

    private function filterHorizontalLuma(int $mbX, int $mbY, int $edge, array $bs, int $qp): void
    {
        $alpha = $beta = 0;
        $this->getThresholds($qp, $this->sliceAlphaC0Offset, $this->sliceBetaOffset, $alpha, $beta);
        if ($alpha == 0 || $beta == 0) {
            return;
        }

        $stride = $this->deblockYStride;
        $plane = &$this->yPlane;
        $base = ($mbY * 16 + $edge * 4) * $stride + ($mbX * 16);

        for ($i = 0; $i < 4; $i++) {
            $curBs = $bs[$i];
            if ($curBs == 0) {
                continue;
            }
            $tc0 = $curBs < 4 ? $this->getTc0($qp, $this->sliceAlphaC0Offset, $curBs) : 0;
            $off = $base + $i * 4;

            for ($d = 0; $d < 4; $d++, $off++) {
                $p0 = ord($plane[$off - $stride]);
                $p1 = ord($plane[$off - 2 * $stride]);
                $p2 = ord($plane[$off - 3 * $stride]);
                $q0 = ord($plane[$off]);
                $q1 = ord($plane[$off + $stride]);
                $q2 = ord($plane[$off + 2 * $stride]);

                if ($curBs < 4) {
                    if (abs($p0 - $q0) < $alpha && abs($p1 - $p0) < $beta && abs($q1 - $q0) < $beta) {
                        $tc = $tc0;
                        $newP1 = $p1;
                        $newQ1 = $q1;
                        $p0q0 = ($p0 + $q0 + 1) >> 1;

                        if (abs($p2 - $p0) < $beta) {
                            if ($tc0 !== 0) {
                                $newP1 = $p1 + $this->clip3(-$tc0, $tc0, ((($p2 + $p0q0) >> 1) - $p1));
                            }
                            $tc++;
                        }

                        if (abs($q2 - $q0) < $beta) {
                            if ($tc0 !== 0) {
                                $newQ1 = $q1 + $this->clip3(-$tc0, $tc0, ((($q2 + $p0q0) >> 1) - $q1));
                            }
                            $tc++;
                        }

                        $delta = $this->clip3(-$tc, $tc, ((($q0 - $p0) * 4 + ($p1 - $q1) + 4) >> 3));
                        $plane[$off - $stride] = chr($this->clipPixel($p0 + $delta));
                        $plane[$off - 2 * $stride] = chr($newP1);
                        $plane[$off] = chr($this->clipPixel($q0 - $delta));
                        $plane[$off + $stride] = chr($newQ1);
                    }
                } else {
                    $p3 = ord($plane[$off - 4 * $stride]);
                    $q3 = ord($plane[$off + 3 * $stride]);
                    if ($this->filterStrongLuma(
                        $p0, $p1, $p2, $p3, $q0, $q1, $q2, $q3, $alpha, $beta,
                        $newP0, $newP1, $newP2, $newQ0, $newQ1, $newQ2
                    )) {
                        $plane[$off - $stride] = chr($newP0);
                        $plane[$off - 2 * $stride] = chr($newP1);
                        $plane[$off - 3 * $stride] = chr($newP2);
                        $plane[$off] = chr($newQ0);
                        $plane[$off + $stride] = chr($newQ1);
                        $plane[$off + 2 * $stride] = chr($newQ2);
                    }
                }
            }
        }
    }

    private function filterVerticalChroma(int $mbX, int $mbY, int $edge, array $bs, int $qp): void
    {
        $alpha = $beta = 0;
        $this->getThresholds($qp, $this->sliceAlphaC0Offset, $this->sliceBetaOffset, $alpha, $beta);
        if ($alpha == 0 || $beta == 0) {
            return;
        }

        $stride = $this->deblockUvStride;
        $base = ($mbY * 8) * $stride + ($mbX * 8) + $edge * 4;

        foreach (['uPlane', 'vPlane'] as $planeName) {
            $plane = &$this->$planeName;
            for ($i = 0; $i < 4; $i++) {
                $curBs = $bs[$i];
                if ($curBs == 0) {
                    continue;
                }
                $tc = $curBs < 4 ? $this->getTc0($qp, $this->sliceAlphaC0Offset, $curBs) + 1 : 0;
                $off = $base + $i * 2 * $stride;

                for ($d = 0; $d < 2; $d++, $off += $stride) {
                    $p0 = ord($plane[$off - 1]);
                    $p1 = ord($plane[$off - 2]);
                    $q0 = ord($plane[$off]);
                    $q1 = ord($plane[$off + 1]);

                    if ($curBs < 4) {
                        if (abs($p0 - $q0) < $alpha && abs($p1 - $p0) < $beta && abs($q1 - $q0) < $beta) {
                            $delta = $this->clip3(-$tc, $tc, ((($q0 - $p0) * 4 + ($p1 - $q1) + 4) >> 3));
                            $plane[$off - 1] = chr($this->clipPixel($p0 + $delta));
                            $plane[$off] = chr($this->clipPixel($q0 - $delta));
                        }
                    } elseif ($this->filterStrongChroma(
                        $p0, $p1, $q0, $q1, $alpha, $beta, $newP0, $newQ0
                    )) {
                        $plane[$off - 1] = chr($newP0);
                        $plane[$off] = chr($newQ0);
                    }
                }
            }
            unset($plane);
        }
    }

    private function filterHorizontalChroma(int $mbX, int $mbY, int $edge, array $bs, int $qp): void
    {
        $alpha = $beta = 0;
        $this->getThresholds($qp, $this->sliceAlphaC0Offset, $this->sliceBetaOffset, $alpha, $beta);
        if ($alpha == 0 || $beta == 0) {
            return;
        }

        $stride = $this->deblockUvStride;
        $base = ($mbY * 8 + $edge * 4) * $stride + ($mbX * 8);

        foreach (['uPlane', 'vPlane'] as $planeName) {
            $plane = &$this->$planeName;
            for ($i = 0; $i < 4; $i++) {
                $curBs = $bs[$i];
                if ($curBs == 0) {
                    continue;
                }
                $tc = $curBs < 4 ? $this->getTc0($qp, $this->sliceAlphaC0Offset, $curBs) + 1 : 0;
                $off = $base + $i * 2;

                for ($d = 0; $d < 2; $d++, $off++) {
                    $p0 = ord($plane[$off - $stride]);
                    $p1 = ord($plane[$off - 2 * $stride]);
                    $q0 = ord($plane[$off]);
                    $q1 = ord($plane[$off + $stride]);

                    if ($curBs < 4) {
                        if (abs($p0 - $q0) < $alpha && abs($p1 - $p0) < $beta && abs($q1 - $q0) < $beta) {
                            $delta = $this->clip3(-$tc, $tc, ((($q0 - $p0) * 4 + ($p1 - $q1) + 4) >> 3));
                            $plane[$off - $stride] = chr($this->clipPixel($p0 + $delta));
                            $plane[$off] = chr($this->clipPixel($q0 - $delta));
                        }
                    } elseif ($this->filterStrongChroma(
                        $p0, $p1, $q0, $q1, $alpha, $beta, $newP0, $newQ0
                    )) {
                        $plane[$off - $stride] = chr($newP0);
                        $plane[$off] = chr($newQ0);
                    }
                }
            }
            unset($plane);
        }
    }

    private function computeBoundaryStrengths(int $mbX, int $mbY, array &$bsVertical, array &$bsHorizontal): void
    {
        $mbWidth = $this->picWidthInMbs;
        $mbIdx = $mbY * $mbWidth + $mbX;
        $curType = $this->mbTypeForDeblock[$mbIdx] ?? -1;
        $curNnz = $this->mbNnzForDeblock[$mbIdx] ?? [];
        $curMv = $this->mbMvForDeblock[$mbIdx] ?? [];
        $curRef = $this->mbRefForDeblock[$mbIdx] ?? [];
        $sliceType = $this->currentSliceType;
        $isIslice = ($sliceType === 2 || $sliceType === 4);
        $isPslice = ($sliceType === 0 || $sliceType === 5);
        $curIntra = $isIslice
            ? ($curType >= 0 && $curType <= 24)
            : ($isPslice && $curType >= 5 && $curType <= 29);
        $curIpcm = ($isIslice && $curType === 25) || ($isPslice && $curType === 30);

        $leftIntra = $curIntra;
        $leftIpcm = $curIpcm;
        $leftNnz = $leftMv = $leftRef = [];
        if ($mbX > 0) {
            $leftIdx = $mbIdx - 1;
            $leftType = $this->mbTypeForDeblock[$leftIdx] ?? -1;
            $leftNnz = $this->mbNnzForDeblock[$leftIdx] ?? [];
            $leftMv = $this->mbMvForDeblock[$leftIdx] ?? [];
            $leftRef = $this->mbRefForDeblock[$leftIdx] ?? [];
            $leftIntra = $isIslice
                ? ($leftType >= 0 && $leftType <= 24)
                : ($isPslice && $leftType >= 5 && $leftType <= 29);
            $leftIpcm = ($isIslice && $leftType === 25) || ($isPslice && $leftType === 30);
        }

        $topIntra = $curIntra;
        $topIpcm = $curIpcm;
        $topNnz = $topMv = $topRef = [];
        if ($mbY > 0) {
            $topIdx = $mbIdx - $mbWidth;
            $topType = $this->mbTypeForDeblock[$topIdx] ?? -1;
            $topNnz = $this->mbNnzForDeblock[$topIdx] ?? [];
            $topMv = $this->mbMvForDeblock[$topIdx] ?? [];
            $topRef = $this->mbRefForDeblock[$topIdx] ?? [];
            $topIntra = $isIslice
                ? ($topType >= 0 && $topType <= 24)
                : ($isPslice && $topType >= 5 && $topType <= 29);
            $topIpcm = ($isIslice && $topType === 25) || ($isPslice && $topType === 30);
        }

        for ($edge = 0; $edge < 4; $edge++) {
            $vertical = $horizontal = [0, 0, 0, 0];
            for ($pair = 0; $pair < 4; $pair++) {
                $qIdx = ($pair << 2) + $edge;
                $pIdx = $edge === 0 ? (($pair << 2) + 3) : $qIdx - 1;
                $pIntra = $edge === 0 ? $leftIntra : $curIntra;
                $pIpcm = $edge === 0 ? $leftIpcm : $curIpcm;
                $pNnz = $edge === 0 ? ($leftNnz[$pIdx] ?? 0) : ($curNnz[$pIdx] ?? 0);

                if (!$pIpcm && !$curIpcm) {
                    if ($pIntra || $curIntra) {
                        $vertical[$pair] = $edge === 0 ? 4 : 3;
                    } elseif ($pNnz != 0 || ($curNnz[$qIdx] ?? 0) != 0) {
                        $vertical[$pair] = 2;
                    } else {
                        $pMv = $edge === 0 ? ($leftMv[$pIdx] ?? $this->deblockZeroMv) : ($curMv[$pIdx] ?? $this->deblockZeroMv);
                        $qMv = $curMv[$qIdx] ?? $this->deblockZeroMv;
                        $pRef = $edge === 0 ? ($leftRef[$pIdx] ?? 0) : ($curRef[$pIdx] ?? 0);
                        if ($pRef != ($curRef[$qIdx] ?? 0)
                            || abs($pMv[0] - $qMv[0]) >= 4
                            || abs($pMv[1] - $qMv[1]) >= 4) {
                            $vertical[$pair] = 1;
                        }
                    }
                }

                $qIdx = ($edge << 2) + $pair;
                $pIdx = $edge === 0 ? 12 + $pair : $qIdx - 4;
                $pIntra = $edge === 0 ? $topIntra : $curIntra;
                $pIpcm = $edge === 0 ? $topIpcm : $curIpcm;
                $pNnz = $edge === 0 ? ($topNnz[$pIdx] ?? 0) : ($curNnz[$pIdx] ?? 0);

                if (!$pIpcm && !$curIpcm) {
                    if ($pIntra || $curIntra) {
                        $horizontal[$pair] = $edge === 0 ? 4 : 3;
                    } elseif ($pNnz != 0 || ($curNnz[$qIdx] ?? 0) != 0) {
                        $horizontal[$pair] = 2;
                    } else {
                        $pMv = $edge === 0 ? ($topMv[$pIdx] ?? $this->deblockZeroMv) : ($curMv[$pIdx] ?? $this->deblockZeroMv);
                        $qMv = $curMv[$qIdx] ?? $this->deblockZeroMv;
                        $pRef = $edge === 0 ? ($topRef[$pIdx] ?? 0) : ($curRef[$pIdx] ?? 0);
                        if ($pRef != ($curRef[$qIdx] ?? 0)
                            || abs($pMv[0] - $qMv[0]) >= 4
                            || abs($pMv[1] - $qMv[1]) >= 4) {
                            $horizontal[$pair] = 1;
                        }
                    }
                }
            }
            $bsVertical[$edge] = $vertical;
            $bsHorizontal[$edge] = $horizontal;
        }
    }

    public function applyDeblockingFilter(): void
    {
        if ($this->disableDeblockingFilterIdc == 1 || ($this->forceDisableDeblock ?? false)) {
            return;
        }

        $this->deblockYStride = $this->width;
        $this->deblockUvStride = (int)($this->width / 2);
        $this->deblockThresholdCache = [];
        $this->deblockTc0Cache = [];
        $this->deblockChromaQpCache = [];

        $mbWidth = $this->picWidthInMbs;
        $mbHeight = $this->picHeightInMbs;
        $emptyStrengths = [[0, 0, 0, 0], [0, 0, 0, 0], [0, 0, 0, 0], [0, 0, 0, 0]];
        $bsVertical = $emptyStrengths;
        $bsHorizontal = $emptyStrengths;

        for ($mbY = 0; $mbY < $mbHeight; $mbY++) {
            for ($mbX = 0; $mbX < $mbWidth; $mbX++) {
                $mbIdx = $mbY * $mbWidth + $mbX;
                $curType = $this->mbTypeForDeblock[$mbIdx] ?? 0;
                $curQp = $this->mbQpForDeblock[$mbIdx] ?? 26;

                if ($curType < 0) {
                    continue;
                }

                $this->computeBoundaryStrengths($mbX, $mbY, $bsVertical, $bsHorizontal);

                for ($edge = 0; $edge < 4; $edge++) {
                    $isMbEdge = ($edge == 0);

                    if ($isMbEdge && $mbX == 0) {
                        continue;
                    }

                    $strengths = $bsVertical[$edge];
                    if (($strengths[0] | $strengths[1] | $strengths[2] | $strengths[3]) === 0) {
                        continue;
                    }

                    $qp = $isMbEdge && $mbX > 0 ? $this->avgQp($curQp, $this->mbQpForDeblock[($mbY * $mbWidth + $mbX - 1)] ?? $curQp) : $curQp;
                    $this->filterVerticalLuma($mbX, $mbY, $edge, $strengths, $qp);

                    $chromaQp = $this->getChromaQp($qp);
                    if ($edge == 0 || $edge == 2) {
                        $chromaEdge = (int)($edge / 2);
                        $this->filterVerticalChroma($mbX, $mbY, $chromaEdge, $strengths, $chromaQp);
                    }
                }

                for ($edge = 0; $edge < 4; $edge++) {
                    $isMbEdge = ($edge == 0);

                    if ($isMbEdge && $mbY == 0) {
                        continue;
                    }

                    $strengths = $bsHorizontal[$edge];
                    if (($strengths[0] | $strengths[1] | $strengths[2] | $strengths[3]) === 0) {
                        continue;
                    }

                    $qp = $isMbEdge && $mbY > 0 ? $this->avgQp($curQp, $this->mbQpForDeblock[(($mbY - 1) * $mbWidth + $mbX)] ?? $curQp) : $curQp;
                    $this->filterHorizontalLuma($mbX, $mbY, $edge, $strengths, $qp);

                    $chromaQp = $this->getChromaQp($qp);
                    if ($edge == 0 || $edge == 2) {
                        $chromaEdge = (int)($edge / 2);
                        $this->filterHorizontalChroma($mbX, $mbY, $chromaEdge, $strengths, $chromaQp);
                    }
                }
            }
        }
    }
}