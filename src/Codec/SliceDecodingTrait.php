<?php

namespace Xiaosongshu\Flv2mp4\Codec;

trait SliceDecodingTrait
{
    /**
     * 解码Slice层
     */
    public function decodeSlice(string $rbsp, bool $isIDR, int $nalRefIdc): void
    {
        $this->reader = new BitReader($rbsp);

        //echo "[SLICE HDR] start bitPos=0\n";
        $firstMbInSlice = $this->reader->readUe();
        //echo "[SLICE HDR] first_mb_in_slice=$firstMbInSlice bitPos=" . $this->reader->getBitPosition() . "\n";

        $sliceTypeRaw = $this->reader->readUe();
        $sliceType = $sliceTypeRaw % 5;
        //echo "[SLICE HDR] slice_type_raw=$sliceTypeRaw slice_type=$sliceType bitPos=" . $this->reader->getBitPosition() . "\n";

        $ppsId = $this->reader->readUe();
        //echo "[SLICE HDR] pps_id=$ppsId bitPos=" . $this->reader->getBitPosition() . "\n";

        $frameNumBits = $this->log2MaxFrameNumMinus4 + 4;
        $frameNum = $this->reader->readU($frameNumBits);
        //echo "[SLICE HDR] frame_num=$frameNum ($frameNumBits bits) bitPos=" . $this->reader->getBitPosition() . "\n";

        if (!$this->frameMbsOnlyFlag) {
            $fieldPicFlag = $this->reader->readU(1);
            //echo "[SLICE HDR] field_pic_flag=$fieldPicFlag bitPos=" . $this->reader->getBitPosition() . "\n";
            if ($fieldPicFlag) {
                $this->reader->skip(1);
                //echo "[SLICE HDR] bottom_field_pic_flag bitPos=" . $this->reader->getBitPosition() . "\n";
            }
        } else {
            //echo "[SLICE HDR] frame_mbs_only_flag=true, skip field coding bitPos=" . $this->reader->getBitPosition() . "\n";
        }

        if ($isIDR) {
            $idrPicId = $this->reader->readUe();
            //echo "[SLICE HDR] idr_pic_id=$idrPicId bitPos=" . $this->reader->getBitPosition() . "\n";
        }

        if ($this->picOrderCntType === 0) {
            $pocLsb = $this->reader->readU($this->log2MaxPicOrderCntLsb);
            //echo "[SLICE HDR] pic_order_cnt_lsb=$pocLsb (" . $this->log2MaxPicOrderCntLsb . " bits) bitPos=" . $this->reader->getBitPosition() . "\n";
            if ($this->bottomFieldPicOrderInFramePresent) {
                $deltaBottom = $this->reader->readSe();
                //echo "[SLICE HDR] delta_pic_order_cnt_bottom=$deltaBottom bitPos=" . $this->reader->getBitPosition() . "\n";
            }
        }
        if ($this->picOrderCntType === 1) {
            $delta0 = $this->reader->readSe();
            //echo "[SLICE HDR] delta_pic_order_cnt[0]=$delta0 bitPos=" . $this->reader->getBitPosition() . "\n";
            if ($this->bottomFieldPicOrderInFramePresent) {
                $delta1 = $this->reader->readSe();
                //echo "[SLICE HDR] delta_pic_order_cnt[1]=$delta1 bitPos=" . $this->reader->getBitPosition() . "\n";
            }
        }

        if ($this->redundantPicCntPresent) {
            $redPicCnt = $this->reader->readUe();
            //echo "[SLICE HDR] redundant_pic_cnt=$redPicCnt bitPos=" . $this->reader->getBitPosition() . "\n";
        }
        if ($sliceType === 1) {
            $this->reader->skip(1);
            //echo "[SLICE HDR] direct_spatial_mv_pred_flag (B slice) bitPos=" . $this->reader->getBitPosition() . "\n";
        }

        $numRefIdxL0Active = $this->numRefIdxL0DefaultActive;
        $numRefIdxL1Active = $this->numRefIdxL1DefaultActive;

        if ($sliceType !== 2 && $sliceType !== 4) {
            $numRefIdxActiveOverrideFlag = $this->reader->readU(1);
            $this->numRefIdxActiveOverrideFlag = (bool)$numRefIdxActiveOverrideFlag;
            //echo "[SLICE HDR] num_ref_idx_active_override_flag=$numRefIdxActiveOverrideFlag bitPos=" . $this->reader->getBitPosition() . "\n";
            if ($numRefIdxActiveOverrideFlag) {
                $numRefIdxL0Active = $this->reader->readUe() + 1;
                $this->numRefIdxL0Active = $numRefIdxL0Active;
                //echo "[SLICE HDR] num_ref_idx_l0_active=$numRefIdxL0Active bitPos=" . $this->reader->getBitPosition() . "\n";
                if ($sliceType === 1) {
                    $numRefIdxL1Active = $this->reader->readUe() + 1;
                    //echo "[SLICE HDR] num_ref_idx_l1_active=$numRefIdxL1Active bitPos=" . $this->reader->getBitPosition() . "\n";
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
                //echo "[SLICE HDR] IDR dec_ref_pic_marking: no_output_of_prior_pics=$noOutputPrior long_term_reference_flag=$longTermRef bitPos=" . $this->reader->getBitPosition() . "\n";
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
            //echo "[SLICE HDR] cabac_init_idc=$cabacInitIdc bitPos=" . $this->reader->getBitPosition() . "\n";
        } else {
            //echo "[SLICE HDR] cabac_init_idc skipped (CABAC=" . ($this->entropyCodingModeFlag ? '1' : '0') . ", sliceType=$sliceType) bitPos=" . $this->reader->getBitPosition() . "\n";
        }

        $sliceQpDelta = $this->reader->readSe();
        //echo "[SLICE HDR] slice_qp_delta=$sliceQpDelta bitPos=" . $this->reader->getBitPosition() . "\n";
        $qp = $this->picInitQp + $sliceQpDelta;
        $qp = max(0, min(51, $qp));
        //echo "[DEBUG SLICE] picInitQp={$this->picInitQp} sliceQpDelta={$sliceQpDelta} qp={$qp}\n";

        if ($sliceType === 3 || $sliceType === 4) {
            if ($sliceType === 3) $this->reader->skip(1);
            $this->reader->readSe();
        }

        if ($this->deblockingFilterParametersPresent) {
            $deblockingFilterIdc = $this->reader->readUe();
            //echo "[SLICE HDR] deblocking_filter_idc=$deblockingFilterIdc bitPos=" . $this->reader->getBitPosition() . "\n";
            $this->disableDeblockingFilterIdc = $deblockingFilterIdc;
            if ($deblockingFilterIdc !== 1) {
                $alphaOffset = $this->reader->readSe();
                $betaOffset = $this->reader->readSe();
                //echo "[SLICE HDR] alpha_offset_div2=$alphaOffset beta_offset_div2=$betaOffset bitPos=" . $this->reader->getBitPosition() . "\n";
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

        //echo "[DECODER]   Decoding {$totalMbs} macroblocks ({$mbWidth}x{$mbHeight}), firstMbInSlice={$firstMbInSlice}, sliceType={$sliceType}..." . PHP_EOL;

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
                $progress = ($mbIdx - $startMbIdx + 1) / ($endMbIdx - $startMbIdx) * 100;
                //echo "[DECODER]     MB progress: {$mbIdx}/{$totalMbs} (row {$mbY}, col {$mbX}) - " . number_format($progress, 1) . "%\r\n";
                flush();
            }

            if (($sliceType === 0 || $sliceType === 5) && !$this->entropyCodingModeFlag) {
                if ($mbSkipRun < 0) {
                    $mbSkipRun = $this->reader->readUe();
                    //echo "[DECODER] MB($mbX,$mbY): mb_skip_run=$mbSkipRun\n";
                    if ($isDebugSlice && $this->debugMbTraceFh) {
                        fwrite($this->debugMbTraceFh, "MB($mbX,$mbY): mb_skip_run=$mbSkipRun\n");
                    }
                }
                if ($mbSkipRun > 0) {
                    $mbSkipRun--;
                    $this->decodePSkip($mbX, $mbY);
                    $this->mbQpForDeblock[$mbIdx] = $qp;
                    if ($isDebugSlice && $this->debugMbTraceFh) {
                        fwrite($this->debugMbTraceFh, "  SKIP, qp=$qp\n");
                    }
                    continue;
                } else {
                    $mbSkipRun = -1;
                }
            }

            $bitBeforeMb = $this->reader->getBitPosition();
            $mbQpDelta = $this->decodeMacroblock($mbX, $mbY, $qp, $sliceType);
            $bitAfterMb = $this->reader->getBitPosition();
            $qp = max(0, min(51, $qp + $mbQpDelta));
            $this->mbQpForDeblock[$mbIdx] = $qp;

            if ($isDebugSlice && $this->debugMbTraceFh) {
                fwrite($this->debugMbTraceFh, "  bitPos=$bitBeforeMb..$bitAfterMb (" . ($bitAfterMb - $bitBeforeMb) . " bits), qp=$qp\n");
            }
        }
        //echo PHP_EOL . "[DECODER]   Macroblocks decoded" . PHP_EOL;

        $this->applyDeblockingFilter();
    }
}
