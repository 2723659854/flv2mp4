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

        // Slice header 语法顺序符合H.264标准（与FFmpeg cbs_h264_syntax_template.c一致）：
        // num_ref_idx_active_override_flag → ref_pic_list_modification() → pred_weight_table → dec_ref_pic_marking()
        
        if ($sliceType === 0) {
            // P帧：num_ref_idx_active_override_flag
            $bits .= '0'; // num_ref_idx_active_override_flag = 0 (使用默认值)
            // ref_pic_list_modification(): ref_pic_list_modification_flag_l0 = 0 (无修改)
            $bits .= '0';
        }
        
        // pred_weight_table() 不需要，因为PPS中weighted_pred_flag=0, weighted_bipred_idc=0
        
        // dec_ref_pic_marking() - 所有nal_ref_idc != 0的帧都需要（在ref_pic_list_modification之后）
        if ($isIDR) {
            $bits .= '0'; // no_output_of_prior_pics_flag
            $bits .= '0'; // long_term_reference_flag
        } else {
            // 非IDR参考帧：adaptive_ref_pic_marking_mode_flag = 0 (滑窗模式，FIFO)
            $bits .= '0';
        }
        
        // cabac_init_idc 不需要，因为PPS中entropy_coding_mode_flag=0 (CAVLC)

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

        // 将输入图像扩展到宏块对齐尺寸（边缘复制填充）
        // 确保边界宏块的参考像素与FFmpeg一致，避免P帧误差累积
        if ($this->mbAlignedWidth !== $this->width || $this->mbAlignedHeight !== $this->height) {
            // 亮度平面扩展
            $expandedY = '';
            $padRight = $this->mbAlignedWidth - $this->width;
            for ($y = 0; $y < $this->height; $y++) {
                $row = substr($yPlane, $y * $this->width, $this->width);
                $lastPixel = $row[$this->width - 1];
                $expandedY .= $row . str_repeat($lastPixel, $padRight);
            }
            $padBottom = $this->mbAlignedHeight - $this->height;
            $lastRow = substr($expandedY, ($this->height - 1) * $this->mbAlignedWidth, $this->mbAlignedWidth);
            for ($y = 0; $y < $padBottom; $y++) {
                $expandedY .= $lastRow;
            }
            $yPlane = $expandedY;

            // 色度平面扩展
            $uvW = (int)($this->width / 2);
            $uvH = (int)($this->height / 2);
            $uvAlignedW = (int)($this->mbAlignedWidth / 2);
            $uvAlignedH = (int)($this->mbAlignedHeight / 2);
            $padRightUv = $uvAlignedW - $uvW;
            $padBottomUv = $uvAlignedH - $uvH;

            foreach (['uPlane', 'vPlane'] as $planeName) {
                $expandedUV = '';
                for ($y = 0; $y < $uvH; $y++) {
                    $row = substr($$planeName, $y * $uvW, $uvW);
                    $lastPixel = $row[$uvW - 1];
                    $expandedUV .= $row . str_repeat($lastPixel, $padRightUv);
                }
                $lastRowUv = substr($expandedUV, ($uvH - 1) * $uvAlignedW, $uvAlignedW);
                for ($y = 0; $y < $padBottomUv; $y++) {
                    $expandedUV .= $lastRowUv;
                }
                $$planeName = $expandedUV;
            }
        }
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

                    // I帧宏块没有运动向量，设置为[0,0,-1]表示存在但不使用L0（与解码器一致）
                    $intraMv = [0, 0, -1];
                    $this->mvLeftCol = [$intraMv, $intraMv, $intraMv, $intraMv];
                    $this->mvTopRow[$mbX * 4 + 0] = $intraMv;
                    $this->mvTopRow[$mbX * 4 + 1] = $intraMv;
                    $this->mvTopRow[$mbX * 4 + 2] = $intraMv;
                    $this->mvTopRow[$mbX * 4 + 3] = $intraMv;
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
