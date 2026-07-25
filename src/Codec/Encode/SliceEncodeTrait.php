<?php

namespace Xiaosongshu\Flv2mp4\Codec\Encode;

trait SliceEncodeTrait
{
    public function encodeSlice(string $yuvData, bool $isKeyframe): string
    {
        $isIDR = $isKeyframe;
        // P_SLICE=0, I_SLICE=2 (slice_type 0和5都表示P帧)
        $sliceType = 0; // P_SLICE

        // 如果没有参考帧或禁用P帧，强制使用I帧
        $usePFrame = $this->enableInter && !$isIDR && $this->refYPlane !== null;
        if (!$usePFrame) {
            $sliceType = 2; // I_SLICE
        }

        $bits = '';

        $bits .= $this->ue(0);           // first_mb_in_slice
        $bits .= $this->ue($sliceType);  // slice_type
        $bits .= $this->ue(0);           // pic_parameter_set_id

        $log2MaxFrameNum = $this->log2MaxFrameNumMinus4 + 4;
        $frameNumBits = $log2MaxFrameNum;
        $frameNumValue = $this->frameNum & ((1 << $frameNumBits) - 1);
        $frameNumBitsStr = $this->u($frameNumValue, $frameNumBits);
        $bits .= $frameNumBitsStr;

        if ($isIDR) $bits .= $this->ue($this->idrPicId);

        // pic_order_cnt_lsb is present when pic_order_cnt_type == 0, regardless of IDR or not
        $log2MaxPicOrderCntLsb = $this->log2MaxPicOrderCntLsbMinus4 + 4;
        $pocLsb = $this->poc & ((1 << $log2MaxPicOrderCntLsb) - 1);
        $bits .= $this->u($pocLsb, $log2MaxPicOrderCntLsb);

        // dec_ref_pic_marking() for IDR frames (nal_ref_idc != 0)
        if ($isIDR) {
            $bits .= '0'; // no_output_of_prior_pics_flag
            $bits .= '0'; // long_term_reference_flag
        }

        // P帧需要编码num_ref_idx_active_override_flag和ref_pic_list_modification
        if ($sliceType === 0) {
            $bits .= '0'; // num_ref_idx_active_override_flag
            // ref_pic_list_modification(): ref_pic_list_modification_flag_l0 = 0
            $bits .= '0';
        }

        // dec_ref_pic_marking() for non-IDR frames
        if (!$isIDR) {
            // adaptive_ref_pic_marking_mode_flag = 0 (滑窗模式)
            $bits .= '0';
        }

        $bits .= $this->se(0); // slice_qp_delta

        // 禁用deblocking filter（编码器未实现去块滤波，需与解码器保持一致）
        $bits .= $this->ue(1); // disable_deblocking_filter_idc = 1 (禁用)
        // disable_deblocking_filter_idc=1时，不需要alpha_c0_offset_div2和beta_offset_div2

        $mbWidth = (int)ceil($this->width / 16);
        $mbHeight = (int)ceil($this->height / 16);
        $this->picWidthInMbs = $mbWidth;
        // 使用宏块对齐尺寸（与解码器一致），避免边界宏块参考帧失配
        $this->mbAlignedWidth = $mbWidth * 16;
        $this->mbAlignedHeight = $mbHeight * 16;
        $ySize = $this->width * $this->height;
        $uvSize = intdiv($ySize, 4);
        $yPlane = substr($yuvData, 0, $ySize);
        $uPlane = substr($yuvData, $ySize, $uvSize);
        $vPlane = substr($yuvData, $ySize + $uvSize, $uvSize);
        $topNzLuma = array_fill(0, $mbWidth * 4, 0);
        $topNzCb = array_fill(0, $mbWidth * 2, 0);
        $topNzCr = array_fill(0, $mbWidth * 2, 0);
        $leftNz = [0, 0, 0, 0, 0, 0, 0, 0];
        $leftIntra4x4Mode = [-1, -1, -1, -1];
        $topIntra4x4Mode = array_fill(0, $mbWidth * 4, -1);

        // 重置MV缓存（mvTopRow保留上行的MV，mvLeftCol每行重置）
        $this->mvTopRow = [];
        $this->mvLeftCol = [null, null, null, null];

        // 初始化本地解码重建帧（使用宏块对齐尺寸，与解码器一致）
        // 解码器初始化为128，填充区域会被实际解码值覆盖
        $reconYSize = $this->mbAlignedWidth * $this->mbAlignedHeight;
        $reconUvW = intdiv($this->mbAlignedWidth, 2);
        $reconUvH = intdiv($this->mbAlignedHeight, 2);
        $reconUvSize = $reconUvW * $reconUvH;
        $this->reconYPlane = str_repeat("\x80", $reconYSize);
        $this->reconUPlane = str_repeat("\x80", $reconUvSize);
        $this->reconVPlane = str_repeat("\x80", $reconUvSize);

        // P帧使用mb_skip_run来编码P_Skip宏块（CAVLC模式）
        $mbSkipRun = 0;
        $isPSlice = ($sliceType === 0 && $this->refYPlane !== null);

        for ($mbY = 0; $mbY < $mbHeight; $mbY++) {
            $leftAvailable = false;
            $leftNz = [0, 0, 0, 0, 0, 0, 0, 0];
            $leftIntra4x4Mode = [-1, -1, -1, -1];
            // 每行重置mvLeftCol（左邻居不可用于行首宏块）
            // 不清空mvTopRow：保留上一行的MV作为top预测参考
            $this->mvLeftCol = [null, null, null, null];
            for ($mbX = 0; $mbX < $mbWidth; $mbX++) {
                // DEBUG: 提前终止宏块编码用于二分定位
                if ($this->debugStopMbY >= 0 && $this->debugStopMbX >= 0) {
                    if ($mbY > $this->debugStopMbY || ($mbY == $this->debugStopMbY && $mbX > $this->debugStopMbX)) {
                        break 2;
                    }
                }
                if ($isPSlice) {
                    // P帧编码
                    $mbBits = $this->encodePMacroblock(
                        $mbX, $mbY, $yPlane, $uPlane, $vPlane,
                        $leftAvailable, $leftNz, $mbY > 0,
                        $topNzLuma, $topNzCb, $topNzCr,
                        $leftIntra4x4Mode, $topIntra4x4Mode,
                        $this->refYPlane
                    );
                    if ($this->lastMbWasSkip) {
                        // P_Skip: 只增加跳过计数，不写mb_type
                        $mbSkipRun++;
                    } else {
                        // 非Skip宏块: 先写mb_skip_run，再写宏块层
                        $bits .= $this->ue($mbSkipRun);
                        $bits .= $mbBits;
                        $mbSkipRun = 0;
                    }
                } else {
                    // I帧编码
                    $mbBits = $this->encodeMacroblock(
                        $mbX, $mbY, $yPlane, $uPlane, $vPlane,
                        $leftAvailable, $leftNz, $mbY > 0,
                        $topNzLuma, $topNzCb, $topNzCr,
                        $leftIntra4x4Mode, $topIntra4x4Mode
                    );
                    if ($mbY == 5 && $mbX >= 10 && $mbX <= 15) {
                        echo "ENCODE SLICE MB({$mbX},{$mbY}): before=" . strlen($bits) . ", mbBits=" . strlen($mbBits) . ", after=" . (strlen($bits) + strlen($mbBits)) . "\n";
                    }
                    $bits .= $mbBits;
                }
                $leftAvailable = true;
            }
        }
        // P帧结尾: 如果还有未写入的skip宏块，写入最终的mb_skip_run
        if ($isPSlice && $mbSkipRun > 0) {
            $bits .= $this->ue($mbSkipRun);
        }
        $bits .= '1';
        while (strlen($bits) % 8 != 0) $bits .= '0';

        $rbsp = $this->bitsToBytes($bits);
        $nalType = 1; // 非IDR片
        if ($isIDR) $nalType = 5; // IDR片
        $nal = $this->rbspToNal($rbsp, $nalType);

        // 保存重建后的帧作为下一帧的参考帧（避免编解码器失配）
        $this->refYPlane = $this->reconYPlane;
        $this->refUPlane = $this->reconUPlane;
        $this->refVPlane = $this->reconVPlane;
        $this->refInts = null;

        // 更新帧序号
        $this->frameNum++;
        $this->poc += 2;

        return $nal;
    }
}
