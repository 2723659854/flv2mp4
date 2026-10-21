<?php

namespace Xiaosongshu\Flv2mp4\Codec\Encode;

/**
 * @purpose sps/pps 编码器
 * @author yanglong
 */
trait SpsPpsTrait
{
    public function generateSPS(): string
    {
        // 依据配置的 profile 选择 profile_idc 与 constraint_set 标志位
        $profileIdc = 66;
        $constraintFlags = '1100'; // baseline：constraint_set0=1
        if ($this->profile === 'main') {
            $profileIdc = 77;
            $constraintFlags = '0100'; // main：constraint_set1=1
        } elseif ($this->profile === 'high') {
            $profileIdc = 100;
            $constraintFlags = '0000';
        }
        $levelIdc = 10;

        $picWidthInMbs = (int)ceil($this->width / 16);
        $picHeightInMapUnits = (int)ceil($this->height / 16);

        $bits = '';

        $bits .= $this->u($profileIdc, 8);

        // constraint_set0..3_flag + reserved_zero_4bits
        $bits .= $constraintFlags;
        $bits .= '0000';
        $bits .= $this->u($levelIdc, 8);

        $bits .= $this->ue(0);              // seq_parameter_set_id = 0

        // High profile 专属语法（规范 7.3.2.1.1）：与解码端 parseSPS 的读取顺序严格一致
        if ($profileIdc === 100) {
            $bits .= $this->ue(1);          // chroma_format_idc = 1 (4:2:0)
            $bits .= $this->ue(0);          // bit_depth_luma_minus8
            $bits .= $this->ue(0);          // bit_depth_chroma_minus8
            $bits .= '0';                   // qpprime_y_zero_transform_bypass_flag
            $bits .= '0';                   // seq_scaling_matrix_present_flag
        }

        $this->log2MaxFrameNumMinus4 = 3;
        $bits .= $this->ue($this->log2MaxFrameNumMinus4);

        $picOrderCntType = 0;
        $bits .= $this->ue($picOrderCntType);

        // B 帧（IBBP）下锚点间 POC 步进为 6，解码端 msb 推导要求连续参考帧 POC 差
        // ≤ MaxPocLsb/2，故 4bit（16）不够，需扩到 8bit（256）。
        $this->log2MaxPicOrderCntLsbMinus4 = $this->bFrames ? 4 : 0;
        $bits .= $this->ue($this->log2MaxPicOrderCntLsbMinus4);

        $numRefFrames = $this->bFrames ? max(2, $this->numRefFrames) : 1;
        $bits .= $this->ue($numRefFrames);

        $gapsInFrameNumValueAllowedFlag = false;
        $bits .= $gapsInFrameNumValueAllowedFlag ? '1' : '0';

        $bits .= $this->ue($picWidthInMbs - 1);
        $bits .= $this->ue($picHeightInMapUnits - 1);

        $bits .= '1';

        $bits .= '0';

        $cropLeft = 0;
        $cropRight = ($picWidthInMbs * 16 - $this->width);
        $cropTop = 0;
        $cropBottom = ($picHeightInMapUnits * 16 - $this->height);
        $frameCroppingFlag = ($cropLeft > 0 || $cropRight > 0 || $cropTop > 0 || $cropBottom > 0);
        $bits .= $frameCroppingFlag ? '1' : '0';
        if ($frameCroppingFlag) {
            $bits .= $this->ue((int)($cropLeft / 2));
            $bits .= $this->ue((int)($cropRight / 2));
            $bits .= $this->ue((int)($cropTop / 2));
            $bits .= $this->ue((int)($cropBottom / 2));
        }

        $bits .= '1';

        $bits .= $this->vuiParameters();

        $bits .= '1';
        while (strlen($bits) % 8 != 0) $bits .= '0';

        return $this->rbspToNal($this->bitsToBytes($bits), 7);
    }

    public function vuiParameters(): string
    {
        // bitstream_restriction 必须如实声明重排延迟：B 帧流（IBBP）锚点前插 2 个 B，
        // 重排延迟为 2；声明为 0 会让解复用/解码端把 B 帧 pts<dts 判为非法时间戳并打乱输出节拍
        $reorderFrames = $this->bFrames ? 2 : 0;
        $dpbFrames = $this->bFrames ? max(2, $this->numRefFrames) : 1;

        $bits = '';
        // 标准 VUI（Annex E.1.1）在 bitstream_restriction_flag 前共有 8 个 present flag，
        // timing/nal_hrd/vcl_hrd/pic_struct）。漏写任何一个都会让后续位整体左移，
        // aspect_ratio_info_present_flag = 0
        $bits .= '0';
        // overscan_info_present_flag = 0
        $bits .= '0';
        // video_signal_type_present_flag = 0
        $bits .= '0';
        // chroma_loc_info_present_flag = 0
        $bits .= '0';
        // timing_info_present_flag = 0
        $bits .= '0';
        // nal_hrd_parameters_present_flag = 0
        $bits .= '0';
        // vcl_hrd_parameters_present_flag = 0
        $bits .= '0';
        // pic_struct_present_flag = 0
        $bits .= '0';
        // bitstream_restriction_flag = 1
        $bits .= '1';
        // motion_vectors_over_pic_boundaries_flag = 1
        $bits .= '1';
        $bits .= $this->ue(0);
        $bits .= $this->ue(0);
        $bits .= $this->ue(16);
        $bits .= $this->ue(16);
        $bits .= $this->ue($reorderFrames);
        $bits .= $this->ue($dpbFrames);
        return $bits;
    }

    public function generatePPS()
    {
        $bits = '';

        $bits .= $this->ue(0);              // pic_parameter_set_id = 0
        $bits .= $this->ue(0);              // seq_parameter_set_id = 0
        // entropy_coding_mode_flag：main/high 用 CABAC，baseline 用 CAVLC
        $bits .= ($this->profile === 'baseline') ? '0' : '1';
        $bits .= '0';                       // bottom_field_pic_order_in_frame_present_flag = 0
        $bits .= $this->ue(0);              // num_slice_groups_minus1 = 0
        $bits .= $this->ue(0);              // num_ref_idx_l0_default_active_minus1 = 0
        $bits .= $this->ue(0);              // num_ref_idx_l1_default_active_minus1 = 0
        $bits .= '0';                       // weighted_pred_flag = 0
        $bits .= '00';                      // weighted_bipred_idc = 0
        $bits .= $this->se($this->qp - 26); // pic_init_qp_minus26
        $bits .= $this->se(0);              // pic_init_qs_minus26
        $bits .= $this->se($this->chromaQpIndexOffset); // chroma_qp_index_offset
        $bits .= '1';                       // deblocking_filter_control_present_flag = 1
        $bits .= '0';                       // constrained_intra_pred_flag = 0
        $bits .= '0';                       // redundant_pic_cnt_present_flag = 0

        // High profile PPS 扩展（规范 7.3.2.2）：与解码端 parsePPS 的读取顺序严格一致
        if ($this->profile === 'high') {
            $bits .= '0';                   // transform_8x8_mode_flag = 0（不使用 8x8 变换）
            $bits .= '0';                   // pic_scaling_matrix_present_flag = 0
            $bits .= $this->se(0);          // second_chroma_qp_index_offset = 0
        }

        $bits .= '1';                       // rbsp_stop_one_bit
        while (strlen($bits) % 8 != 0) $bits .= '0';
        return $this->rbspToNal($this->bitsToBytes($bits), 8);
    }
}
