<?php

namespace Xiaosongshu\Flv2mp4\Codec\Decode;

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
    public function decodeSlice(string $rbsp, bool $isIDR, int $nalRefIdc, bool $skipDeblock = false): void
    {
        // 被抽帧丢弃的帧：不输出、仅维持P链参考，跳过去块滤波及其宏块簿记
        $this->deblockInfoEnabled = !$skipDeblock;
        $this->reader = new BitReader($rbsp);
        $firstMbInSlice = $this->reader->readUe();
        $sliceTypeRaw = $this->reader->readUe();
        $sliceType = $sliceTypeRaw % 5;
        $this->currentSliceType = $sliceType;
        $ppsId = $this->reader->readUe();
        $frameNumBits = $this->log2MaxFrameNumMinus4 + 4;
        $frameNum = $this->reader->readU($frameNumBits);
        $this->currFrameNum = $frameNum;
        if (!$this->frameMbsOnlyFlag) {
            $fieldPicFlag = $this->reader->readU(1);
            if ($fieldPicFlag) {
                $this->reader->skip(1);
            }
        }

        if ($isIDR) {
            $idrPicId = $this->reader->readUe();
            $this->dpb = [];
        }

        if ($this->picOrderCntType === 0) {
            $pocLsb = $this->reader->readU($this->log2MaxPicOrderCntLsb);
            if ($this->bottomFieldPicOrderInFramePresent) {
                $deltaBottom = $this->reader->readSe();
            }
            $this->currPicOrderCntLsb = $pocLsb;
            $maxPocLsb = 1 << $this->log2MaxPicOrderCntLsb;
            if ($isIDR) {
                $this->currPicOrderCntMsb = 0;
            } else {
                // spec 8.2.1.1（frame-only，无 frame_num 断档）
                $lsbDiff = $pocLsb - $this->prevPicOrderCntLsb;
                if ($lsbDiff > ($maxPocLsb >> 1)) {
                    $lsbDiff -= $maxPocLsb;
                } elseif ($lsbDiff <= -($maxPocLsb >> 1)) {
                    $lsbDiff += $maxPocLsb;
                }
                $msb = $this->prevPicOrderCntMsb;
                $sum = $msb + $this->prevPicOrderCntLsb + $lsbDiff;
                if ($sum < 0 || $sum >= $maxPocLsb) {
                    $msb += $lsbDiff < 0 ? $maxPocLsb : -$maxPocLsb;
                }
                $this->currPicOrderCntMsb = $msb;
            }
            $this->currPicOrderCnt = $this->currPicOrderCntMsb + $pocLsb;
        }
        if ($this->picOrderCntType === 1) {
            $delta0 = $this->reader->readSe();
            if ($this->bottomFieldPicOrderInFramePresent) {
                $delta1 = $this->reader->readSe();
            }
        }
        if ($this->picOrderCntType === 2) {
            // H.264 spec 8.2.1.2：pocType=2 无 pocLsb 字段，POC 直接由 frame_num 推导
            // 参考帧 POC = 2*frame_num，非参考帧 POC = 2*frame_num - 1
            $this->currPicOrderCnt = $isIDR ? 0 : ($nalRefIdc !== 0 ? 2 * $frameNum : 2 * $frameNum - 1);
        }

        if ($this->redundantPicCntPresent) {
            $redPicCnt = $this->reader->readUe();
        }
        if ($sliceType === 1) {
            $this->directSpatialMvPred = (bool)$this->reader->readU(1);
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
            $this->numRefIdxL1Active = $numRefIdxL1Active;
        }
        
        if (!$isIDR) {
            // L0 = 过去帧 POC 降序（最近优先）+ 未来帧 POC 升序（spec 8.2.4.2.3 / FFmpeg add_sorted）
            // 注意：不能按 frame_num 排序——存在参考 B 帧时 frame_num 与 POC 顺序不一致
            $pastRefs = [];
            $futureRefs = [];
            foreach ($this->dpb as $entry) {
                if (!$entry['isLongTerm']) {
                    if ($entry['poc'] > $this->currPicOrderCnt) {
                        $futureRefs[] = $entry;
                    } else {
                        $pastRefs[] = $entry;
                    }
                }
            }
            usort($pastRefs, function ($a, $b) { return $b['poc'] - $a['poc']; });
            usort($futureRefs, function ($a, $b) { return $a['poc'] - $b['poc']; });
            $this->refPicList0 = array_merge($pastRefs, $futureRefs);
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
            $this->cabacInitIdc = $this->reader->readUe();
        } else {
            $this->cabacInitIdc = 0;
        }

        // 调试插桩（勿提交）：qp_delta 前位位置
        if (getenv('SH_DBG') !== false) {
            file_put_contents('sh_debug.log', sprintf("pos@qpdelta=%d\n", $this->reader->getPos()), FILE_APPEND);
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
            // 调试插桩（勿提交）：slice header 关键字段位位置
            if (getenv('SH_DBG') !== false) {
                file_put_contents('sh_debug.log', sprintf("pos@deblock=%d idc=%d\n", $this->reader->getPos(), $deblockingFilterIdc), FILE_APPEND);
            }
        } else {
            $this->disableDeblockingFilterIdc = 0;
            $this->sliceAlphaC0Offset = 0;
            $this->sliceBetaOffset = 0;
        }

        // CABAC：slice header 后字节对齐，从对齐字节起初始化算术引擎（FFmpeg h264_slice.c）
        if ($this->entropyCodingModeFlag) {
            $this->reader->alignToByte();
            $headerEndByte = intdiv($this->reader->getPos(), 8);
            $sliceTypeNos = ($sliceType === 2 || $sliceType === 4) ? 2 : ($sliceType === 1 ? 1 : 0);
            $this->cabacSliceBegin($rbsp, $headerEndByte, $qp, $sliceTypeNos);
        }

        $mbWidth = $this->picWidthInMbs;
        $mbHeight = $this->picHeightInMbs;
        $totalMbs = $mbWidth * $mbHeight;
        $startMbIdx = $firstMbInSlice;
        $endMbIdx = $totalMbs;

        if (!$isIDR) {
            // L0 = 过去帧 POC 降序（最近优先）+ 未来帧 POC 升序（spec 8.2.4.2.3 / FFmpeg add_sorted）
            // 注意：不能按 frame_num 排序——存在参考 B 帧时 frame_num 与 POC 顺序不一致
            $pastRefs = [];
            $futureRefs = [];
            foreach ($this->dpb as $entry) {
                if (!$entry['isLongTerm']) {
                    if ($entry['poc'] > $this->currPicOrderCnt) {
                        $futureRefs[] = $entry;
                    } else {
                        $pastRefs[] = $entry;
                    }
                }
            }
            usort($pastRefs, function ($a, $b) { return $b['poc'] - $a['poc']; });
            usort($futureRefs, function ($a, $b) { return $a['poc'] - $b['poc']; });
            $this->refPicList0 = array_merge($pastRefs, $futureRefs);
        } else {
            $this->refPicList0 = [];
        }

        if (!empty($this->refPicList0)) {
            $ref0 = $this->refPicList0[0];
            $this->refFrameY = $ref0['y'];
            $this->refFrameU = $ref0['u'];
            $this->refFrameV = $ref0['v'];
            $this->refStrideY = $ref0['strideY'];
            $this->refStrideUv = $ref0['strideUv'];
            $this->refWidthY = $ref0['widthY'];
            $this->refHeightY = $ref0['heightY'];
            $this->refWidthUv = $ref0['widthUv'];
            $this->refHeightUv = $ref0['heightUv'];
        }

        if ($sliceType === 1) {
            // B 片：按 POC 重建双参考列表
            // L0 = 过去帧 POC 降序 + 未来帧 POC 升序；L1 = 未来帧升序 + 过去帧降序
            $futureRefs = [];
            $pastRefs = [];
            foreach ($this->dpb as $entry) {
                if ($entry['poc'] > $this->currPicOrderCnt) {
                    $futureRefs[] = $entry;
                } elseif ($entry['poc'] < $this->currPicOrderCnt) {
                    $pastRefs[] = $entry;
                }
            }
            usort($futureRefs, function ($a, $b) { return $a['poc'] - $b['poc']; });
            usort($pastRefs, function ($a, $b) { return $b['poc'] - $a['poc']; });
            $this->refPicList0 = array_merge($pastRefs, $futureRefs);
            $this->refPicList1 = array_merge($futureRefs, $pastRefs);
            if (count($this->refPicList0) > $numRefIdxL0Active) {
                $this->refPicList0 = array_slice($this->refPicList0, 0, $numRefIdxL0Active);
            }
            if (count($this->refPicList1) > $numRefIdxL1Active) {
                $this->refPicList1 = array_slice($this->refPicList1, 0, $numRefIdxL1Active);
            }
            if (!empty($this->refPicList0)) {
                $ref0 = $this->refPicList0[0];
                $this->refFrameY = $ref0['y'];
                $this->refFrameU = $ref0['u'];
                $this->refFrameV = $ref0['v'];
                $this->refStrideY = $ref0['strideY'];
                $this->refStrideUv = $ref0['strideUv'];
                $this->refWidthY = $ref0['widthY'];
                $this->refHeightY = $ref0['heightY'];
                $this->refWidthUv = $ref0['widthUv'];
                $this->refHeightUv = $ref0['heightUv'];
            }
        }

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

        $isBslice = ($sliceType === 1);
        if ($isBslice) {
            // B 片双 list 邻居缓存：每 list 独立的 topRow/leftCol/topLeft
            $this->bMvTopRow = [array_fill(0, $mbWidth * 4, null), array_fill(0, $mbWidth * 4, null)];
            $this->bMvLeftCol = [array_fill(0, 4, null), array_fill(0, 4, null)];
            $this->bMvTopLeft = [null, null];
        // temporal direct 的 dist_scale_factor 缓存按片重置（参考列表随片变化）
        // 隐式加权权重缓存同片重置
        $this->bDistScaleFactor = [];
        $this->bImplicitWeight = [];
        }
        if ($this->deblockInfoEnabled) {
            // 初始化去块滤波所需的宏块信息
            $this->mbTypeForDeblock = array_fill(0, $totalMbs, -1);
            $this->mbQpForDeblock = array_fill(0, $totalMbs, $qp);
            // 每帧必须重置NZ缓存，否则P_Skip宏块会使用前一帧的过期数据导致边界强度计算错误
            $emptyNz = array_fill(0, 24, 0);
            $this->mbNnzForDeblock = array_fill(0, $totalMbs, $emptyNz);
            $emptyMv = array_fill(0, 16, [0, 0]);
            $this->mbMvForDeblock = array_fill(0, $totalMbs, $emptyMv);
            $emptyRef = array_fill(0, 16, 0);
            $this->mbRefForDeblock = array_fill(0, $totalMbs, $emptyRef);
            $this->mbMvL1ForDeblock = array_fill(0, $totalMbs, $emptyMv);
            $this->mbRefL1ForDeblock = array_fill(0, $totalMbs, $emptyRef);
        }

        $mbSkipRun = -1;

        //$isPSlice = ($sliceType === 0 || $sliceType === 5);
        for ($mbIdx = $startMbIdx; $mbIdx < $endMbIdx; $mbIdx++) {
            $mbX = $mbIdx % $mbWidth;
            $mbY = (int)($mbIdx / $mbWidth);

            if ($mbX === 0) {
                $this->nzLeftColLuma = array_fill(0, 4, 0);
                $this->nzLeftColChroma = array_fill(0, 4, 0);
                $this->intra4x4LeftModes = array_fill(0, 4, -1);
                $this->mvLeftCol = array_fill(0, 4, null);
                $this->mvTopLeft = array_fill(0, 4, null);
                if ($isBslice) {
                    $this->bMvLeftCol = [array_fill(0, 4, null), array_fill(0, 4, null)];
                    $this->bMvTopLeft = [null, null];
        // temporal direct 的 dist_scale_factor 缓存按片重置（参考列表随片变化）
        $this->bDistScaleFactor = [];
        $this->bImplicitWeight = [];
                }
            }
            $nextMvTopLeft = $this->mvTopRow[$mbX * 4 + 3] ?? null;
            if ($isBslice) {
                $nextBTL0 = $this->bMvTopRow[0][$mbX * 4 + 3] ?? null;
                $nextBTL1 = $this->bMvTopRow[1][$mbX * 4 + 3] ?? null;
            }

            if ($isBslice && !$this->entropyCodingModeFlag) {
                if ($mbSkipRun === -1) {
                    $mbSkipRun = $this->reader->readUe();
                }
                if ($mbSkipRun--) {
                    // B_Skip = B_Direct_16x16 无残差
                    $this->decodeBSkip($mbX, $mbY);
                    if ($this->deblockInfoEnabled) {
                        $this->mbTypeForDeblock[$mbIdx] = 0;
                        $this->mbQpForDeblock[$mbIdx] = $qp;
                    }
                    $this->bMvTopLeft[0] = $nextBTL0;
                    $this->bMvTopLeft[1] = $nextBTL1;
                    continue;
                }
            }

            if (($sliceType === 0 || $sliceType === 5) && !$this->entropyCodingModeFlag) {
                if ($mbSkipRun === -1) {
                    $mbSkipRun = $this->reader->readUe();
                }
                if ($mbSkipRun--) {
                    $this->decodePSkip($mbX, $mbY);
                    if ($this->deblockInfoEnabled) {
                        $this->mbTypeForDeblock[$mbIdx] = 0;
                        $this->mbQpForDeblock[$mbIdx] = $qp;
                    }
                    $this->mvTopLeft = [$nextMvTopLeft, $nextMvTopLeft, $nextMvTopLeft, $nextMvTopLeft];
                    continue;
                }
            }
            if ($this->entropyCodingModeFlag && ($sliceType === 2 || $sliceType === 4)) {
                $this->decodeCabacIntraMb($mbX, $mbY);
                if ($this->deblockInfoEnabled) {
                    $this->mbQpForDeblock[$mbIdx] = $this->cabQp;
                }
                $this->mvTopLeft = [$nextMvTopLeft, $nextMvTopLeft, $nextMvTopLeft, $nextMvTopLeft];
                // end_of_slice_flag：FFmpeg h264_slice.c:2644 每个 MB 后必读 terminate bin
                if ($this->cabTerminate() !== 0) break;
                continue;
            }
            if ($this->entropyCodingModeFlag && ($sliceType === 0 || $sliceType === 5)) {
                $this->decodeCabacPMb($mbX, $mbY);
                if ($this->deblockInfoEnabled) {
                    $this->mbQpForDeblock[$mbIdx] = $this->cabQp;
                }
                $this->mvTopLeft = [$nextMvTopLeft, $nextMvTopLeft, $nextMvTopLeft, $nextMvTopLeft];
                // end_of_slice_flag：同上，每 MB 一个 terminate bin
                if ($this->cabTerminate() !== 0) break;
                continue;
            }
            if ($this->entropyCodingModeFlag && $isBslice) {
                $this->decodeCabacBMb($mbX, $mbY);
                if ($this->deblockInfoEnabled) {
                    $this->mbQpForDeblock[$mbIdx] = $this->cabQp;
                }
                $this->mvTopLeft = [$nextMvTopLeft, $nextMvTopLeft, $nextMvTopLeft, $nextMvTopLeft];
                $this->bMvTopLeft[0] = $nextBTL0;
                $this->bMvTopLeft[1] = $nextBTL1;
                // end_of_slice_flag：同上，每 MB 一个 terminate bin
                if ($this->cabTerminate() !== 0) break;
                continue;
            }
            $mbQpDelta = $this->decodeMacroblock($mbX, $mbY, $qp, $sliceType);
            $qp = max(0, min(51, $qp + $mbQpDelta));
            if ($this->deblockInfoEnabled) $this->mbQpForDeblock[$mbIdx] = $qp;
            $this->mvTopLeft = [$nextMvTopLeft, $nextMvTopLeft, $nextMvTopLeft, $nextMvTopLeft];
            if ($isBslice) {
                $this->bMvTopLeft[0] = $nextBTL0;
                $this->bMvTopLeft[1] = $nextBTL1;
            }
        }
        if ($this->deblockInfoEnabled) {
            // 调试插桩：B_NO_DEBLOCK 环境变量跳过 B 帧去块（勿提交）
            if (getenv('B_NO_DEBLOCK') !== false && $this->currentSliceType === 1) {
                return;
            }
            $this->applyDeblockingFilter();
        }
    }
}
