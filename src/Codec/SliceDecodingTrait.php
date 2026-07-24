<?php

namespace Xiaosongshu\Flv2mp4\Codec;

/**
 * @purpose slice分片解析器
 * @author yanglong
 * @time 2026年7月23日15:21:48
 */
trait SliceDecodingTrait
{
    /**
     * 解码Slice层
     */
    public function decodeSlice(string $rbsp, bool $isIDR, int $nalRefIdc): void
    {
        $this->reader = new BitReader($rbsp);
        $firstMbInSlice = $this->reader->readUe();
        $sliceTypeRaw = $this->reader->readUe();
        $sliceType = $sliceTypeRaw % 5;
        $this->currentSliceType = $sliceType;
        $ppsId = $this->reader->readUe();
        $frameNumBits = $this->log2MaxFrameNumMinus4 + 4;
        $frameNum = $this->reader->readU($frameNumBits);
        if (!$this->frameMbsOnlyFlag) {
            $fieldPicFlag = $this->reader->readU(1);
            if ($fieldPicFlag) {
                $this->reader->skip(1);
            }
        }

        if ($isIDR) {
            $idrPicId = $this->reader->readUe();
        }

        if ($this->picOrderCntType === 0) {
            $pocLsb = $this->reader->readU($this->log2MaxPicOrderCntLsb);
            if ($this->bottomFieldPicOrderInFramePresent) {
                $deltaBottom = $this->reader->readSe();
            }
        }
        if ($this->picOrderCntType === 1) {
            $delta0 = $this->reader->readSe();
            if ($this->bottomFieldPicOrderInFramePresent) {
                $delta1 = $this->reader->readSe();
            }
        }

        if ($this->redundantPicCntPresent) {
            $redPicCnt = $this->reader->readUe();
        }
        if ($sliceType === 1) {
            $this->reader->skip(1);
        }

        $numRefIdxL0Active = $this->numRefIdxL0DefaultActive;
        $numRefIdxL1Active = $this->numRefIdxL1DefaultActive;

        if ($sliceType !== 2 && $sliceType !== 4) {
            $numRefIdxActiveOverrideFlag = $this->reader->readU(1);
            $this->numRefIdxActiveOverrideFlag = (bool)$numRefIdxActiveOverrideFlag;
            if ($numRefIdxActiveOverrideFlag) {
                $numRefIdxL0Active = $this->reader->readUe() + 1;
                $this->numRefIdxL0Active = $numRefIdxL0Active;
                if ($sliceType === 1) {
                    $numRefIdxL1Active = $this->reader->readUe() + 1;
                }
            } else {
                $this->numRefIdxL0Active = $this->numRefIdxL0DefaultActive;
            }
        }

        if ($sliceType !== 2 && $sliceType !== 4) {
            $refPicListModificationFlagL0 = $this->reader->readU(1);
            if ($refPicListModificationFlagL0) {
                while (true) {
                    $idc = $this->reader->readUe();
                    if ($idc === 3) break;
                    $this->reader->readUe();
                }
            }
        }
        if ($sliceType === 1) {
            $refPicListModificationFlagL1 = $this->reader->readU(1);
            if ($refPicListModificationFlagL1) {
                while (true) {
                    $idc = $this->reader->readUe();
                    if ($idc === 3) break;
                    $this->reader->readUe();
                }
            }
        }

        if (($this->weightedPredFlag && ($sliceType === 0 || $sliceType === 3)) || ($this->weightedBipredIdc === 1 && $sliceType === 1)) {
            $lumaLog2WeightDenom = $this->reader->readUe();
            $chromaLog2WeightDenom = $this->reader->readUe();
            for ($i = 0; $i < $numRefIdxL0Active; $i++) {
                $lumaWeightL0Flag = $this->reader->readU(1);
                if ($lumaWeightL0Flag) {
                    $this->reader->readSe();
                    $this->reader->readSe();
                }
                $chromaWeightL0Flag = $this->reader->readU(1);
                if ($chromaWeightL0Flag) {
                    $this->reader->readSe();
                    $this->reader->readSe();
                    $this->reader->readSe();
                    $this->reader->readSe();
                }
            }
            if ($sliceType === 1) {
                for ($i = 0; $i < $numRefIdxL1Active; $i++) {
                    $lumaWeightL1Flag = $this->reader->readU(1);
                    if ($lumaWeightL1Flag) {
                        $this->reader->readSe();
                        $this->reader->readSe();
                    }
                    $chromaWeightL1Flag = $this->reader->readU(1);
                    if ($chromaWeightL1Flag) {
                        $this->reader->readSe();
                        $this->reader->readSe();
                        $this->reader->readSe();
                        $this->reader->readSe();
                    }
                }
            }
        }

        if ($nalRefIdc !== 0) {
            if ($isIDR) {
                $noOutputPrior = $this->reader->readU(1);
                $longTermRef = $this->reader->readU(1);
            } else {
                $adaptiveRefPicMarkingModeFlag = $this->reader->readU(1);
                if ($adaptiveRefPicMarkingModeFlag) {
                    while (true) {
                        $mmco = $this->reader->readUe();
                        if ($mmco === 0) break;
                        if ($mmco === 1 || $mmco === 3) $this->reader->readUe();
                        if ($mmco === 2) $this->reader->readUe();
                        if ($mmco === 3 || $mmco === 6) $this->reader->readUe();
                        if ($mmco === 4) $this->reader->readUe();
                    }
                }
            }
        }

        if ($this->entropyCodingModeFlag && $sliceType !== 2 && $sliceType !== 4) {
            $cabacInitIdc = $this->reader->readUe();
        }

        $sliceQpDelta = $this->reader->readSe();
        $qp = $this->picInitQp + $sliceQpDelta;
        $qp = max(0, min(51, $qp));
        if ($sliceType === 3 || $sliceType === 4) {
            if ($sliceType === 3) $this->reader->skip(1);
            $this->reader->readSe();
        }

        if ($this->deblockingFilterParametersPresent) {
            $deblockingFilterIdc = $this->reader->readUe();
            $this->disableDeblockingFilterIdc = $deblockingFilterIdc;
            if ($deblockingFilterIdc !== 1) {
                $alphaOffset = $this->reader->readSe();
                $betaOffset = $this->reader->readSe();
                $this->sliceAlphaC0Offset = $alphaOffset * 2;
                $this->sliceBetaOffset = $betaOffset * 2;
            }
        } else {
            $this->disableDeblockingFilterIdc = 0;
            $this->sliceAlphaC0Offset = 0;
            $this->sliceBetaOffset = 0;
        }

        $mbWidth = $this->picWidthInMbs;
        $mbHeight = $this->picHeightInMbs;
        $totalMbs = $mbWidth * $mbHeight;
        $startMbIdx = $firstMbInSlice;
        $endMbIdx = $totalMbs;

        // 初始化宏块间非零系数计数
        $this->nzTopRowLuma = array_fill(0, $mbWidth * 4, 0);
        $this->nzTopRowChroma = array_fill(0, $mbWidth * 4, 0);
        $this->nzLeftColLuma = array_fill(0, 4, 0);
        $this->nzLeftColChroma = array_fill(0, 4, 0);
        $this->intra4x4TopModes = array_fill(0, $mbWidth * 4, -1);
        $this->intra4x4LeftModes = array_fill(0, 4, -1);

        // 初始化运动向量缓存（4x4子块粒度，每宏块4个，所以mvTopRow大小为mbWidth*4）
        $this->mvTopRow = array_fill(0, $mbWidth * 4, null);
        $this->mvLeftCol = array_fill(0, 4, null);

        // 初始化去块滤波所需的宏块信息
        $this->mbTypeForDeblock = array_fill(0, $totalMbs, -1);
        $this->mbQpForDeblock = array_fill(0, $totalMbs, $qp);
        // 每帧必须重置NZ缓存，否则P_Skip宏块会使用前一帧的过期数据导致边界强度计算错误
        $emptyNz = array_fill(0, 24, 0);
        $this->mbNnzForDeblock = array_fill(0, $totalMbs, $emptyNz);

        $mbSkipRun = -1;

        $isDebugSlice = ($this->debugTargetSlice > 0 && $this->debugSliceIndex === $this->debugTargetSlice);

        for ($mbIdx = $startMbIdx; $mbIdx < $endMbIdx; $mbIdx++) {
            $mbX = $mbIdx % $mbWidth;
            $mbY = (int)($mbIdx / $mbWidth);

            if ($mbX === 0) {
                $this->nzLeftColLuma = array_fill(0, 4, 0);
                $this->nzLeftColChroma = array_fill(0, 4, 0);
                $this->intra4x4LeftModes = array_fill(0, 4, -1);
                $this->mvLeftCol = array_fill(0, 4, null);
            }

            if ($mbIdx % 2 === 0 || $mbIdx === $endMbIdx - 1) {
                flush();
            }

            if (($sliceType === 0 || $sliceType === 5) && !$this->entropyCodingModeFlag) {
                if ($mbSkipRun < 0) {
                    $mbSkipRun = $this->reader->readUe();
                }
                if ($mbSkipRun > 0) {
                    $mbSkipRun--;
                    $this->decodePSkip($mbX, $mbY);
                    $this->mbTypeForDeblock[$mbIdx] = 0;
                    $this->mbQpForDeblock[$mbIdx] = $qp;
                    continue;
                } else {
                    $mbSkipRun = -1;
                }
            }
            $mbQpDelta = $this->decodeMacroblock($mbX, $mbY, $qp, $sliceType);
            $qp = max(0, min(51, $qp + $mbQpDelta));
            $this->mbQpForDeblock[$mbIdx] = $qp;
        }

        $this->applyDeblockingFilter();
    }
}
