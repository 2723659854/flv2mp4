<?php

namespace Xiaosongshu\Flv2mp4\Codec\Encode;

/**
 * @purpose sps/pps 编码器
 * @author yanglong
 *
 * 负责生成 H.264 的两类参数集 NAL：
 * - SPS（nal_unit_type = 7，规范 7.3.2.1），含可选的 VUI（Annex E.1.1）；
 * - PPS（nal_unit_type = 8，规范 7.3.2.2），含 High profile 扩展语法。
 * 语法元素的写出顺序必须与解码端 parseSPS/parsePPS 的读取顺序严格一致，
 * 否则会导致解码端参数解析错位。
 */
trait SpsPpsTrait
{
    /**
     * 生成 SPS NAL 单元（规范 7.3.2.1 seq_parameter_set_rbsp）。
     *
     * @return string 带起始码与 NAL 头的 SPS 字节串
     */
    public function generateSPS(): string
    {
        // 依据配置的 profile 选择 profile_idc 与 constraint_set 标志位
        // 66 = Baseline，77 = Main，100 = High（规范 Annex A.2）
        $profileIdc = 66;
        $constraintFlags = '1100'; // baseline：constraint_set0=1
        if ($this->profile === 'main') {
            // Main profile：profile_idc = 77
            $profileIdc = 77;
            $constraintFlags = '0100'; // main：constraint_set1=1
        } elseif ($this->profile === 'high') {
            // High profile：profile_idc = 100，无 constraint_set 约束
            $profileIdc = 100;
            $constraintFlags = '0000';
        }
        // level_idc = 10，表示 Level 1（level_idc 以 0.1 为单位，规范 A.3）
        $levelIdc = 10;

        // pic_width_in_mbs_minus1 的基数：宽度按 16 像素宏块向上取整
        $picWidthInMbs = (int)ceil($this->width / 16);
        // pic_height_in_map_units_minus1 的基数：高度按 16 像素向上取整
        $picHeightInMapUnits = (int)ceil($this->height / 16);

        // 位串累加器：先拼出完整的 SPS RBSP 位串，最后统一打包成字节
        $bits = '';

        // profile_idc：u(8)（规范 7.3.2.1）
        $bits .= $this->u($profileIdc, 8);

        // constraint_set0..3_flag + reserved_zero_4bits
        // constraint_setN_flags 共 4 位，随后 reserved_zero_4bits 占 4 位（High 时 reserved 位另有含义）
        $bits .= $constraintFlags;
        $bits .= '0000';
        // level_idc：u(8)
        $bits .= $this->u($levelIdc, 8);

        $bits .= $this->ue(0);              // seq_parameter_set_id = 0
        // 补充：seq_parameter_set_id 用 ue(v) 编码，取值 0 表示只使用默认参数集

        // High profile 专属语法（规范 7.3.2.1.1）：与解码端 parseSPS 的读取顺序严格一致
        if ($profileIdc === 100) {
            $bits .= $this->ue(1);          // chroma_format_idc = 1 (4:2:0)
            // chroma_format_idc = 1 即 4:2:0 采样；若为 3（4:4:4）还需额外读写 separate_colour_plane_flag
            $bits .= $this->ue(0);          // bit_depth_luma_minus8
            // 亮度位深 = 8 + 0 = 8bit
            $bits .= $this->ue(0);          // bit_depth_chroma_minus8
            // 色度位深 = 8 + 0 = 8bit
            $bits .= '0';                   // qpprime_y_zero_transform_bypass_flag
            // 该标志为 0 表示不做变换旁路（即正常做变换+量化）
            $bits .= '0';                   // seq_scaling_matrix_present_flag
            // 该标志为 0 表示不发送自定义量化矩阵，使用默认矩阵
        }

        // log2_max_frame_num_minus4 固定为 3，即 frame_num 位宽 7bit（规范 7.4.3）
        $this->log2MaxFrameNumMinus4 = 3;
        $bits .= $this->ue($this->log2MaxFrameNumMinus4);

        // pic_order_cnt_type = 0：使用 POC LSB + MSB 推导方式（规范 8.2.1.1）
        $picOrderCntType = 0;
        $bits .= $this->ue($picOrderCntType);

        // B 帧（IBBP）下锚点间 POC 步进为 6，解码端 msb 推导要求连续参考帧 POC 差
        // ≤ MaxPocLsb/2，故 4bit（16）不够，需扩到 8bit（256）。
        // log2_max_pic_order_cnt_lsb_minus4 = 4 -> POC LSB 位宽 8bit
        $this->log2MaxPicOrderCntLsbMinus4 = $this->bFrames ? 4 : 0;
        $bits .= $this->ue($this->log2MaxPicOrderCntLsbMinus4);

        // max_num_ref_frames：B 帧至少 2 个参考帧，非 B 帧只需 1 个（规范 A.3.1 参考帧数量约束）
        $numRefFrames = $this->bFrames ? max(2, $this->numRefFrames) : 1;
        $bits .= $this->ue($numRefFrames);

        // gaps_in_frame_num_value_allowed_flag = 0：不允许 frame_num 跳号
        $gapsInFrameNumValueAllowedFlag = false;
        $bits .= $gapsInFrameNumValueAllowedFlag ? '1' : '0';

        // pic_width_in_mbs_minus1：单位为 16 像素宏块
        $bits .= $this->ue($picWidthInMbs - 1);
        // pic_height_in_map_units_minus1：单位为 16 像素行（帧编码时一 map unit 即一宏块行）
        $bits .= $this->ue($picHeightInMapUnits - 1);

        // frame_mbs_only_flag = 1：所有宏块均为帧宏块（不支持场编码，规范 7.4.2.1.1）
        $bits .= '1';

        // direct_8x8_inference_flag：high 启用 8x8 变换时按规范写 1；
        // main/baseline 保持 0 不变（输出逐比特零回归）
        $bits .= ($this->profile === 'high') ? '1' : '0';

        // 帧裁剪参数：按 4:2:0 的 CropUnitX/CropUnitY 计算（规范 7.4.2.1.1）
        $cropLeft = 0;
        // 右裁剪量 = 宏块对齐宽度 - 实际宽度
        $cropRight = ($picWidthInMbs * 16 - $this->width);
        $cropTop = 0;
        // 下裁剪量 = 宏块对齐高度 - 实际高度
        $cropBottom = ($picHeightInMapUnits * 16 - $this->height);
        // 任一边存在裁剪量时置 frame_cropping_flag = 1
        $frameCroppingFlag = ($cropLeft > 0 || $cropRight > 0 || $cropTop > 0 || $cropBottom > 0);
        $bits .= $frameCroppingFlag ? '1' : '0';
        // 有裁剪时依次写 4 个偏移量
        if ($frameCroppingFlag) {
            // 4:2:0 帧编码时 CropUnitX = CropUnitY = 2，故偏移量需除以 2（规范 7.4.2.1.1）
            $bits .= $this->ue((int)($cropLeft / 2));
            $bits .= $this->ue((int)($cropRight / 2));
            $bits .= $this->ue((int)($cropTop / 2));
            $bits .= $this->ue((int)($cropBottom / 2));
        }

        // vui_parameters_present_flag = 1：随后写 VUI 参数（规范 E.1.1）
        $bits .= '1';

        // 追加 VUI 位串（含 bitstream_restriction 信息）
        $bits .= $this->vuiParameters();

        // rbsp_stop_one_bit = 1，随后补 0 至字节对齐（规范 7.3.2.11 rbsp_trailing_bits）
        $bits .= '1';
        // 位串长度不是 8 的整数倍时，右侧补 0 完成对齐
        while (strlen($bits) % 8 != 0) $bits .= '0';

        // 位串打包为字节，并封装成 nal_unit_type = 7 的 NAL 单元
        return $this->rbspToNal($this->bitsToBytes($bits), 7);
    }

    /**
     * 生成 VUI 参数位串（规范 E.1.1 vui_parameters）。
     * 本实现只开启 bitstream_restriction_flag，其余可选信息全部关闭，
     * 目的是向解码/解复用端如实声明重排延迟与 DPB 大小。
     *
     * @return string VUI 部分位串
     */
    public function vuiParameters(): string
    {
        // bitstream_restriction 必须如实声明重排延迟：B 帧流（IBBP）锚点前插 2 个 B，
        // 重排延迟为 2；声明为 0 会让解复用/解码端把 B 帧 pts<dts 判为非法时间戳并打乱输出节拍
        // max_num_reorder_frames：B 帧时为 2，否则为 0
        $reorderFrames = $this->bFrames ? 2 : 0;
        // max_dec_frame_buffering：解码端 DPB 至少需要的帧数，B 帧时与参考帧数一致
        $dpbFrames = $this->bFrames ? max(2, $this->numRefFrames) : 1;

        // VUI 位串累加器
        $bits = '';
        // 标准 VUI（Annex E.1.1）在 bitstream_restriction_flag 前共有 8 个 present flag，
        // timing/nal_hrd/vcl_hrd/pic_struct）。漏写任何一个都会让后续位整体左移，
        // aspect_ratio_info_present_flag = 0
        // 以下 8 个标志位顺序严格遵循规范 E.1.1，全部置 0 表示不携带对应信息
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
        // max_bytes_per_pic_denom = 0（不做每图像字节数限制）
        $bits .= $this->ue(0);
        // max_bits_per_mb_denom = 0（不做每宏块位数限制）
        $bits .= $this->ue(0);
        // log2_max_mv_length_horizontal = 16
        $bits .= $this->ue(16);
        // log2_max_mv_length_vertical = 16
        $bits .= $this->ue(16);
        // max_num_reorder_frames：解码端可重排的最大帧数
        $bits .= $this->ue($reorderFrames);
        // max_dec_frame_buffering：解码端 DPB 所需帧缓冲数
        $bits .= $this->ue($dpbFrames);
        return $bits;
    }

    /**
     * 生成 PPS NAL 单元（规范 7.3.2.2 pic_parameter_set_rbsp）。
     *
     * @return string 带起始码与 NAL 头的 PPS 字节串
     */
    public function generatePPS()
    {
        // PPS 位串累加器
        $bits = '';

        $bits .= $this->ue(0);              // pic_parameter_set_id = 0
        $bits .= $this->ue(0);              // seq_parameter_set_id = 0
        // entropy_coding_mode_flag：main/high 用 CABAC，baseline 用 CAVLC
        $bits .= ($this->profile === 'baseline') ? '0' : '1';
        $bits .= '0';                       // bottom_field_pic_order_in_frame_present_flag = 0
        $bits .= $this->ue(0);              // num_slice_groups_minus1 = 0
        // num_slice_groups_minus1 = 0 表示整帧只有一个 slice group，无需切片组映射表
        $bits .= $this->ue(0);              // num_ref_idx_l0_default_active_minus1 = 0
        $bits .= $this->ue(0);              // num_ref_idx_l1_default_active_minus1 = 0
        $bits .= '0';                       // weighted_pred_flag = 0
        $bits .= '00';                      // weighted_bipred_idc = 0
        $bits .= $this->se($this->qp - 26); // pic_init_qp_minus26
        // 补充：pic_init_qp_minus26 用 se(v) 编码，最终初始 QP = 26 + 该值
        $bits .= $this->se(0);              // pic_init_qs_minus26
        $bits .= $this->se($this->chromaQpIndexOffset); // chroma_qp_index_offset
        // 补充：chroma_qp_index_offset 决定色度 QP 与亮度 QP 的映射偏移（规范 8.5.8）
        $bits .= '1';                       // deblocking_filter_control_present_flag = 1
        $bits .= '0';                       // constrained_intra_pred_flag = 0
        $bits .= '0';                       // redundant_pic_cnt_present_flag = 0

        // High profile PPS 扩展（规范 7.3.2.2）：与解码端 parsePPS 的读取顺序严格一致
        if ($this->profile === 'high') {
            $bits .= '1';                   // transform_8x8_mode_flag = 1（high 启用 8x8 变换）
            $bits .= '0';                   // pic_scaling_matrix_present_flag = 0
            $bits .= $this->se(0);          // second_chroma_qp_index_offset = 0
        }

        $bits .= '1';                       // rbsp_stop_one_bit
        // 补 0 至字节对齐（规范 7.3.2.11 rbsp_trailing_bits）
        while (strlen($bits) % 8 != 0) $bits .= '0';
        // 打包为字节并封装成 nal_unit_type = 8 的 NAL 单元
        return $this->rbspToNal($this->bitsToBytes($bits), 8);
    }
}
