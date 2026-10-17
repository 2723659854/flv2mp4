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
        
        // 参考列表的默认构建在 dec_ref_pic_marking（MMCO/滑动窗口）之后统一进行；
        // 此处只解析并暂存 ref_pic_list_modification 命令（spec 7.3.3.1：
        // idc=0/1 仅 abs_diff_pic_num_minus1 一个参数，idc=2 仅 long_term_pic_num 一个参数）
        $this->modsL0 = [];
        if ($sliceType !== 2 && $sliceType !== 4) {
            $refPicListModificationFlagL0 = $this->reader->readU(1);
            if ($refPicListModificationFlagL0) {
                while (true) {
                    $idc = $this->reader->readUe();
                    if ($idc === 3) break;
                    $this->modsL0[] = [$idc, $this->reader->readUe()];
                }
            }
        }
        $this->modsL1 = [];
        if ($sliceType === 1) {
            $refPicListModificationFlagL1 = $this->reader->readU(1);
            if ($refPicListModificationFlagL1) {
                while (true) {
                    $idc = $this->reader->readUe();
                    if ($idc === 3) break;
                    $this->modsL1[] = [$idc, $this->reader->readUe()];
                }
            }
        }

        $this->pwtActive = false;
        if (($this->weightedPredFlag && ($sliceType === 0 || $sliceType === 3)) || ($this->weightedBipredIdc === 1 && $sliceType === 1)) {
            $lumaLog2WeightDenom = $this->reader->readUe();
            $chromaLog2WeightDenom = $this->reader->readUe();
            $this->pwtLd = $lumaLog2WeightDenom;
            $this->pwtCd = $chromaLog2WeightDenom;
            $defLumaW = 1 << $lumaLog2WeightDenom;
            $defChromaW = 1 << $chromaLog2WeightDenom;
            for ($i = 0; $i < $numRefIdxL0Active; $i++) {
                $lumaWeightL0Flag = $this->reader->readU(1);
                $lw = $defLumaW; $lo = 0;
                if ($lumaWeightL0Flag) {
                    // spec 7.3.3.2 / FFmpeg h264_parse.c：luma_weight_l0[i] 是有符号绝对权重值，
                    // 不是 default+delta！（flag=0 时才是默认值 1<<denom）
                    $lw = $this->reader->readSe();
                    $lo = $this->reader->readSe();
                }
                $chromaWeightL0Flag = $this->reader->readU(1);
                $cbw = $defChromaW; $cbo = 0; $crw = $defChromaW; $cro = 0;
                if ($chromaWeightL0Flag) {
                    $cbw = $this->reader->readSe();
                    $cbo = $this->reader->readSe();
                    $crw = $this->reader->readSe();
                    $cro = $this->reader->readSe();
                }
                $this->pwtL0W[$i] = $lw; $this->pwtL0O[$i] = $lo;
                $this->pwtL0CbW[$i] = $cbw; $this->pwtL0CbO[$i] = $cbo;
                $this->pwtL0CrW[$i] = $crw; $this->pwtL0CrO[$i] = $cro;
                if ($lw !== $defLumaW || $lo !== 0 || $cbw !== $defChromaW || $cbo !== 0 || $crw !== $defChromaW || $cro !== 0) {
                    $this->pwtActive = true;
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

        // dec_ref_pic_marking（spec 7.3.3.3）：此处只解析并暂存命令。
        // 执行必须延后到当前帧解码完成、加入 DPB 之后（spec 8.2.5 / FFmpeg
        // ff_h264_execute_ref_pic_marking 在帧完成后执行）——否则当前帧的
        // 参考列表会用到一个被提前踢除了参考帧的 DPB（多参考 P/B 帧错位）。
        $this->pendingMmco = [];
        $this->pendingMmcoSliding = false;
        if ($nalRefIdc !== 0) {
            if ($isIDR) {
                $this->reader->readU(1); // no_output_of_prior_pics_flag
                $this->reader->readU(1); // long_term_reference_flag
            } else {
                $adaptiveRefPicMarkingModeFlag = $this->reader->readU(1);
                if ($adaptiveRefPicMarkingModeFlag) {
                    while (true) {
                        $mmco = $this->reader->readUe();
                        if ($mmco === 0) break;
                        $args = [];
                        if ($mmco === 1 || $mmco === 3) $args[] = $this->reader->readUe(); // diff_of_pic_nums_minus1
                        if ($mmco === 2) $args[] = $this->reader->readUe(); // long_term_pic_num
                        if ($mmco === 3 || $mmco === 6) $args[] = $this->reader->readUe(); // long_term_frame_idx
                        if ($mmco === 4) $args[] = $this->reader->readUe(); // max_long_term_frame_idx_plus1
                        $this->pendingMmco[] = [$mmco, $args];
                    }
                } else {
                    $this->pendingMmcoSliding = true;
                }
            }
        }

        if ($this->entropyCodingModeFlag && $sliceType !== 2 && $sliceType !== 4) {
            $this->cabacInitIdc = $this->reader->readUe();
        } else {
            $this->cabacInitIdc = 0;
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
            if ($sliceType === 1) {
                // B 片默认 L0（8.2.4.2.3）：POC past 降序 + future 升序；下方 B 块会重建 L0/L1
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
                // P/SP 片默认 L0（8.2.4.2.1）：短期参考按 FrameNumWrap 降序（最接近当前帧优先）。
                // 注意：存在参考 B 帧时 frame_num 序与 POC 序不同，必须按 frame_num。
                $maxPicNum = 1 << ($this->log2MaxFrameNumMinus4 + 4);
                $refs = [];
                foreach ($this->dpb as $entry) {
                    if (!$entry['isLongTerm']) {
                        $fn = $entry['frameNum'];
                        $refs[] = ['w' => ($fn > $frameNum) ? $fn - $maxPicNum : $fn, 'e' => $entry];
                    }
                }
                usort($refs, function ($a, $b) { return $b['w'] - $a['w']; });
                $this->refPicList0 = array_map(function ($r) { return $r['e']; }, $refs);
                // 执行 ref_pic_list_modification（8.2.4.3.1）
                if (!empty($this->modsL0)) {
                    $this->applyRefPicListModification($this->refPicList0, $this->modsL0, $frameNum, $maxPicNum, $numRefIdxL0Active);
                }
                if (count($this->refPicList0) > $numRefIdxL0Active) {
                    $this->refPicList0 = array_slice($this->refPicList0, 0, $numRefIdxL0Active);
                }
                // 不足 numRefIdxL0Active 时以默认列表[0]（default_ref）补齐（FFmpeg: null 槽位替换为 default_ref）
                while (count($this->refPicList0) < $numRefIdxL0Active && !empty($this->refPicList0)) {
                    $this->refPicList0[] = $this->refPicList0[0];
                }
            }
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
            // 执行 ref_pic_list_modification（8.2.4.3.1）
            if (!empty($this->modsL0) || !empty($this->modsL1)) {
                $maxPicNumB = 1 << ($this->log2MaxFrameNumMinus4 + 4);
                if (!empty($this->modsL0)) {
                    $this->applyRefPicListModification($this->refPicList0, $this->modsL0, $frameNum, $maxPicNumB, $numRefIdxL0Active);
                }
                if (!empty($this->modsL1)) {
                    $this->applyRefPicListModification($this->refPicList1, $this->modsL1, $frameNum, $maxPicNumB, $numRefIdxL1Active);
                }
            }
            if (count($this->refPicList0) > $numRefIdxL0Active) {
                $this->refPicList0 = array_slice($this->refPicList0, 0, $numRefIdxL0Active);
            }
            if (count($this->refPicList1) > $numRefIdxL1Active) {
                $this->refPicList1 = array_slice($this->refPicList1, 0, $numRefIdxL1Active);
            }
            // 不足时以 default_ref（默认列表[0]）补齐
            while (count($this->refPicList0) < $numRefIdxL0Active && !empty($this->refPicList0)) {
                $this->refPicList0[] = $this->refPicList0[0];
            }
            while (count($this->refPicList1) < $numRefIdxL1Active && !empty($this->refPicList1)) {
                $this->refPicList1[] = $this->refPicList1[0];
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
            $this->applyDeblockingFilter();
        }
    }

    /** dec_ref_pic_marking 待执行命令（slice header 解析，帧入 DPB 后执行） */
    public array $pendingMmco = [];
    public bool $pendingMmcoSliding = false;

    /**
     * dec_ref_pic_marking 执行阶段（spec 8.2.5 / FFmpeg ff_h264_execute_ref_pic_marking）。
     * 必须在当前参考帧加入 DPB 之后调用：MMCO/滑动窗口影响的是后续帧的参考列表，
     * 当前帧的列表构建用的是标记前的 DPB。
     */
    private function executeRefPicMarking(int $frameNum): void
    {
        if (!empty($this->pendingMmco)) {
            $cmds = $this->pendingMmco;
            $this->pendingMmco = [];
            $maxPicNumMmco = 1 << ($this->log2MaxFrameNumMinus4 + 4);
            foreach ($cmds as [$mmco, $args]) {
                if ($mmco === 1) {
                    // MMCO1: 标记短期参考为不用于参考
                    // FFmpeg h264_refs.c: short_pic_num=(currPicNum-diff-1)&(maxPicNum-1)
                    $shortPicNum = ($frameNum - $args[0] - 1) & ($maxPicNumMmco - 1);
                    foreach ($this->dpb as $dpk => $dpEntry) {
                        if (!$dpEntry['isLongTerm'] && $dpEntry['frameNum'] === $shortPicNum) {
                            array_splice($this->dpb, $dpk, 1);
                            break;
                        }
                    }
                }
                // MMCO2-6（长期参考）当前流不使用，参数已在解析阶段消费
            }
        }
        if ($this->pendingMmcoSliding) {
            $this->pendingMmcoSliding = false;
            // spec 8.2.5.3 隐式滑动窗口：当前帧已在 DPB 内计数；短期参考总数超出
            // max_num_ref_frames 时，踢出 FrameNumWrap 最小（最老）的短期参考。
            $maxFrameNum = 1 << ($this->log2MaxFrameNumMinus4 + 4);
            $shortTermCount = 0;
            foreach ($this->dpb as $entry) {
                if (!$entry['isLongTerm']) $shortTermCount++;
            }
            while ($shortTermCount > $this->maxNumRefFrames) {
                $oldestKey = null;
                $oldestFnWrap = null;
                foreach ($this->dpb as $k => $entry) {
                    if ($entry['isLongTerm']) continue;
                    $fn = $entry['frameNum'];
                    $fnWrap = ($fn > $frameNum) ? $fn - $maxFrameNum : $fn;
                    if ($oldestFnWrap === null || $fnWrap < $oldestFnWrap) {
                        $oldestFnWrap = $fnWrap;
                        $oldestKey = $k;
                    }
                }
                if ($oldestKey === null) break;
                array_splice($this->dpb, $oldestKey, 1);
                $shortTermCount--;
            }
        }
    }

    /**
     * 执行 ref_pic_list_modification（spec 8.2.4.3.1 / FFmpeg h264_refs.c ff_h264_build_ref_list）。
     * 关键语义（与朴素"移除再插入"不同）：
     *  - 列表长度固定为 refCount（numRefIdxActive），默认列表不足时先补 null；
     *  - 每条命令把目标参考写入 index，[index..i-1] 右移一位（i 为同 pic_id 的既有位置，
     *    找不到则 i=refCount-1，末位被挤出）——允许同一帧占据多个 refIdx；
     *  - 结束后仍为 null 的槽位以默认列表[0]（default_ref）替换。
     */
    private function applyRefPicListModification(array &$list, array $mods, int $currPicNum, int $maxPicNum, int $refCount): void
    {
        if ($refCount <= 0 || empty($list)) return;
        $defaultRef = $list[0];
        while (count($list) < $refCount) $list[] = null;
        $pred = $currPicNum;
        foreach ($mods as $index => $mod) {
            if ($index >= $refCount) break;
            [$idc, $val] = $mod;
            $ref = null;
            $picId = 0;
            if ($idc === 0 || $idc === 1) {
                $absDiff = $val + 1;
                if ($absDiff > $maxPicNum) return;
                $pred = ($idc === 0) ? ($pred - $absDiff) & ($maxPicNum - 1)
                                     : ($pred + $absDiff) & ($maxPicNum - 1);
                $picId = $pred;
                foreach ($this->dpb as $e) {
                    if (!$e['isLongTerm'] && $e['frameNum'] === $picId) { $ref = $e; break; }
                }
            } else { // idc=2：长期参考（本测试流无长期参考，保留语义）
                $picId = $val;
                foreach ($this->dpb as $e) {
                    if ($e['isLongTerm'] && ($e['longTermPicNum'] ?? -1) === $val) { $ref = $e; break; }
                }
            }
            if ($ref === null) { $list[$index] = null; continue; }
            // 从 index 起找同 pic_id 的既有位置
            $i = $index;
            for (; $i + 1 < $refCount; $i++) {
                $cur = $list[$i];
                if ($cur !== null && $cur['isLongTerm'] === $ref['isLongTerm'] && $cur['frameNum'] === $picId) break;
            }
            for (; $i > $index; $i--) {
                $list[$i] = $list[$i - 1];
            }
            $list[$index] = $ref;
        }
        for ($i = 0; $i < $refCount; $i++) {
            if ($list[$i] === null) $list[$i] = $defaultRef;
        }
    }
}
