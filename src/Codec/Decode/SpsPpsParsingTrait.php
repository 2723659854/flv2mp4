<?php

// 命名空间：H.264 解码器内部解析相关代码
namespace Xiaosongshu\Flv2mp4\Codec\Decode;

/**
 * @purpose spspps解析器
 * @author yanglong
 * @time 2026年7月23日15:25:17
 *
 * SPS（序列参数集）与 PPS（图像参数集）的语法解析实现。
 * 对应规范：
 *  - SPS 语法：7.3.2.1（seq_parameter_set_data，见 7.3.2.1.1）
 *  - PPS 语法：7.3.2.2（pic_parameter_set_rbsp）
 * 解析结果写入解码器对象的属性，供后续 slice 头解析、宏块解码、去块滤波等步骤使用。
 */
trait SpsPpsParsingTrait
{
    /**
     * 解析SPS获取画面宽高、色度格式等
     *
     * 入参为去掉 NAL 头与防竞争字节后的 SPS RBSP（见规范 7.3.1、7.4.1）。
     * 解析顺序严格按 7.3.2.1.1 的语法表：
     * profile_idc → constraint_setN_flag/reserved → level_idc → seq_parameter_set_id
     * →（High profile 扩展）→ 帧号/POC/参考帧相关字段 → 尺寸裁剪字段。
     *
     * @param string $rbsp SPS 的 RBSP 数据
     */
    public function parseSPS(string $rbsp): void
    {
        // 为本次 SPS 新建位读取器，读指针从 RBSP 起始位置开始
        $this->reader = new BitReader($rbsp);

        // profile_idc（u(8)）：标识档次（66=Baseline，77=Main，100=High 等），见 7.4.2.1.1
        $this->profileIdc = $this->reader->readU(8);
        // constraint_setN_flag 与保留位（u(8)）：此处仅整字节消费，不做具体约束检查
        $constraintFlags = $this->reader->readU(8);
        // level_idc（u(8)）：标识级别（码率/分辨率上限），此处只读取不使用
        $levelIdc = $this->reader->readU(8);

        // seq_parameter_set_id（ue(v)）：SPS 编号，当前解码器只维护单个 SPS，故直接丢弃
        $this->reader->readUe(); // seq_parameter_set_id

        // 判断是否为 High 系列 profile：这些 profile 在 SPS 中额外携带色度格式等扩展字段
        // （见 7.3.2.1.1 中 if(profile_idc == 100 || ...) 分支）
        $isHighProfile = in_array($this->profileIdc, [100, 110, 122, 244, 44, 83, 86, 118, 128, 138, 139, 134, 135, 144]);

        // High profile 扩展字段解析
        if ($isHighProfile) {
            // chroma_format_idc（ue(v)）：色度采样格式，0=单色，1=4:2:0，2=4:2:2，3=4:4:4
            $this->chromaFormatIdc = $this->reader->readUe();
            // 仅支持 0~3，超出范围视为非法码流，直接放弃后续解析
            if ($this->chromaFormatIdc > 3) return;
            // chroma_format_idc=3 时额外读取 separate_colour_plane_flag（1 bit，见 7.3.2.1.1）
            if ($this->chromaFormatIdc === 3) $this->reader->skip(1); // separate_colour_plane_flag
            // bit_depth_luma_minus8（ue(v)）：亮度位深减 8，此处只消费不保存（假定 8bit）
            $this->reader->readUe(); // bit_depth_luma_minus8
            // bit_depth_chroma_minus8（ue(v)）：色度位深减 8，同样只消费
            $this->reader->readUe(); // bit_depth_chroma_minus8
            // qpprime_y_zero_transform_bypass_flag（u(1)）：QP'=0 时是否旁路变换，记下供残余解码使用
            $this->transformBypass8x8 = (bool)$this->reader->readU(1); // qpprime_y_zero_transform_bypass_flag

            // seq_scaling_matrix_present_flag (必须先读取这个 flag)
            // 该 flag 决定是否存在自定义量化缩放矩阵（见 7.3.2.1.1）
            $seqScalingMatrixPresentFlag = $this->reader->readU(1);
            // 存在缩放矩阵时，逐个列表读取 scaling_list()
            if ($seqScalingMatrixPresentFlag) {
                // 列表数量：4:4:4(含分离色彩平面) 为 12 个，否则为 8 个（6 个 4x4 + 2 个 8x8）
                $numScalingLists = ($this->chromaFormatIdc !== 3) ? 8 : 12;
                // 遍历每个缩放列表
                for ($i = 0; $i < $numScalingLists; $i++) {
                    // seq_scaling_list_present_flag[i]（u(1)）：该列表是否存在
                    $useScalingList = $this->reader->readU(1);
                    // 存在则按 scaling_list() 语法（7.3.2.1.1.1）解析
                    if ($useScalingList) {
                        // 前 6 个为 4x4（16 个系数），其余为 8x8（64 个系数）
                        $sizeOfScalingList = ($i < 6) ? 16 : 64;
                        // lastScale 初值 8（规范中默认缩放值）
                        $lastScale = 8;
                        // nextScale 初值 8
                        $nextScale = 8;
                        // 逐系数读取 delta_scale（se(v)）并递推 nextScale
                        for ($j = 0; $j < $sizeOfScalingList; $j++) {
                            // nextScale 为 0 时后续系数保持 lastScale，不再读取 delta
                            if ($nextScale !== 0) {
                                // delta_scale（se(v)，见 7.3.2.1.1.1）
                                $deltaScale = $this->reader->readSe();
                                // 递推公式：nextScale = (lastScale + deltaScale + 256) % 256
                                $nextScale = ($lastScale + $deltaScale + 256) % 256;
                            }
                            // nextScale 非 0 时更新 lastScale；为 0 则沿用旧值
                            $lastScale = $nextScale === 0 ? $lastScale : $nextScale;
                        }
                    }
                }
            }
        } else {
            // 非 High profile：按 4:2:0 处理（chroma_format_idc 隐含为 1）
            $this->chromaFormatIdc = 1;
            // 非 High profile 不支持变换旁路
            $this->transformBypass8x8 = false;
        }

        // baseline(66) 不含 B 帧，直接解码序输出；其它 profile 按 POC 重排
        $this->reorderByPoc = ($this->profileIdc !== 66);

        // log2_max_frame_num_minus4（ue(v)）：frame_num 位宽减 4，用于推定位宽
        $this->log2MaxFrameNumMinus4 = $this->reader->readUe();
        // pic_order_cnt_type（ue(v)）：POC 计算方式（0/1/2），见 8.2.1
        $this->picOrderCntType = $this->reader->readUe();

        // pic_order_cnt_type=0：读取 log2_max_pic_order_cnt_lsb_minus4 并加 4 得到位宽
        if ($this->picOrderCntType === 0) {
            $this->log2MaxPicOrderCntLsb = $this->reader->readUe() + 4;
        } elseif ($this->picOrderCntType === 1) {
            // pic_order_cnt_type=1 的扩展字段（见 7.3.2.1.1）
            // delta_pic_order_always_zero_flag（u(1)）
            $this->reader->skip(1);
            // offset_for_non_ref_pic（se(v)）
            $this->reader->readSe();
            // offset_for_top_to_bottom_field（se(v)）
            $this->reader->readSe();
            // num_ref_frames_in_pic_order_cnt_cycle（ue(v)）
            $numRefFramesInPicOrderCntCycle = $this->reader->readUe();
            // 逐个读取 offset_for_ref_frame[i]（se(v)）
            for ($j = 0; $j < $numRefFramesInPicOrderCntCycle; $j++) $this->reader->readSe();
        }

        // max_num_ref_frames（ue(v)）：DPB 中参考帧的最大数量
        $this->maxNumRefFrames = $this->reader->readUe(); // max_num_ref_frames（规范 7.3.2.1，无 +1）
        // gaps_in_frame_num_value_allowed_flag（u(1)）：是否允许 frame_num 不连续
        $this->reader->skip(1);
        // pic_width_in_mbs_minus1（ue(v)）：以宏块为单位的宽度减 1
        $picWidthInMbsMinus1 = $this->reader->readUe();
        // pic_height_in_map_units_minus1（ue(v)）：以宏块（map unit）为单位的高度减 1
        $picHeightInMapUnitsMinus1 = $this->reader->readUe();
        // frame_mbs_only_flag（u(1)）：1 表示只有帧编码，0 表示允许场编码
        $this->frameMbsOnlyFlag = (bool)$this->reader->readU(1);

        // frame_mbs_only_flag=0 时还存在 mb_adaptive_frame_field_flag（u(1)）
        if (!$this->frameMbsOnlyFlag) $this->reader->skip(1); // mb_adaptive_frame_field_flag
        // direct_8x8_inference_flag（u(1)）：B 帧直接模式 8x8 运动推导标志，见 8.4.1.2
        $this->direct8x8InferenceFlag = (bool)$this->reader->readU(1); // direct_8x8_inference_flag

        // frame_cropping_flag（u(1)）：是否存在画面裁剪参数
        $frameCroppingFlag = $this->reader->readU(1);
        // 裁剪偏移初值全部为 0
        $cropLeft = 0; $cropRight = 0; $cropTop = 0; $cropBottom = 0;
        // 存在裁剪参数时依次读取四个偏移（均为 ue(v)，见 7.3.2.1.1）
        if ($frameCroppingFlag) {
            $cropLeft = $this->reader->readUe();
            $cropRight = $this->reader->readUe();
            $cropTop = $this->reader->readUe();
            $cropBottom = $this->reader->readUe();
        }

        // 宏块列数 = pic_width_in_mbs_minus1 + 1
        $this->picWidthInMbs = $picWidthInMbsMinus1 + 1;
        // 宏块行数（map unit 数）= pic_height_in_map_units_minus1 + 1
        $this->picHeightInMbs = $picHeightInMapUnitsMinus1 + 1;
        // 允许场编码时（frame_mbs_only_flag=0），帧由两个场组成，行数翻倍
        if (!$this->frameMbsOnlyFlag) $this->picHeightInMbs *= 2;

        // 色度垂直采样因子指数：4:2:0 为 1，其余为 0（见 7.4.2.1.1 中 SubWidthC/SubHeightC 的定义）
        $vsub = (int)($this->chromaFormatIdc === 1);
        // 色度水平采样因子指数：4:2:0 与 4:2:2 为 1，4:4:4 为 0
        $hsub = (int)($this->chromaFormatIdc === 1 || $this->chromaFormatIdc === 2);
        // 裁剪单位在水平方向的像素步长 = 2^hsub
        $stepX = 1 << $hsub;
        // 裁剪单位在垂直方向的像素步长：帧编码为 2，场编码为 1，再乘 2^vsub
        // （对应规范 7.4.2.1.1 中 CropUnitY 的定义）
        $stepY = (2 - (int)$this->frameMbsOnlyFlag) << $vsub;

        // 未裁剪的亮度宽度 = 宏块列数 × 16
        $width = $this->picWidthInMbs * 16;
        // 未裁剪的亮度高度 = 宏块行数 × 16
        $height = $this->picHeightInMbs * 16;

        // 四个裁剪偏移换算成亮度像素单位
        $cropLeftScaled = $cropLeft * $stepX;
        $cropRightScaled = $cropRight * $stepX;
        $cropTopScaled = $cropTop * $stepY;
        $cropBottomScaled = $cropBottom * $stepY;

        // 只有裁剪量小于原始尺寸时才采用裁剪结果，否则视为非法参数，回退到未裁剪尺寸
        if (($cropLeftScaled + $cropRightScaled) < $width && ($cropTopScaled + $cropBottomScaled) < $height) {
            // 有效显示宽度 = 原始宽度减去左右裁剪
            $this->width = $width - $cropLeftScaled - $cropRightScaled;
            // 有效显示高度 = 原始高度减去上下裁剪
            $this->height = $height - $cropTopScaled - $cropBottomScaled;
        } else {
            // 裁剪参数不合理：使用未裁剪的宏块对齐尺寸
            $this->width = $width;
            $this->height = $height;
        }
    }

    /**
     * 解析PPS
     *
     * 入参为去掉 NAL 头与防竞争字节后的 PPS RBSP，
     * 解析顺序按规范 7.3.2.2 的 pic_parameter_set_rbsp 语法表。
     * 解析结果影响熵编码模式（CABAC/CAVLC）、默认参考索引、QP 初值、
     * 加权预测、去块滤波参数以及 8x8 变换开关等。
     *
     * @param string $rbsp PPS 的 RBSP 数据
     */
    public function parsePPS(string $rbsp): void
    {
        // 为本次 PPS 新建位读取器
        $this->reader = new BitReader($rbsp);
        // pic_parameter_set_id（ue(v)）：PPS 编号，当前只维护单个 PPS，直接丢弃
        $this->reader->readUe();
        // seq_parameter_set_id（ue(v)）：引用的 SPS 编号
        $spsId = $this->reader->readUe();
        // entropy_coding_mode_flag（u(1)）：1=CABAC，0=CAVLC
        $this->entropyCodingModeFlag = (bool)$this->reader->readU(1);
        // bottom_field_pic_order_in_frame_present_flag（u(1)）：场编码相关 POC 标志
        $this->bottomFieldPicOrderInFramePresent = (bool)$this->reader->readU(1);
        // num_slice_groups_minus1（ue(v)）：slice group 数量减 1，此处未使用（假定为 0）
        $this->reader->readUe();
        // num_ref_idx_l0_default_active_minus1 + 1：L0 默认参考索引数量
        $this->numRefIdxL0DefaultActive = $this->reader->readUe() + 1;
        // num_ref_idx_l1_default_active_minus1 + 1：L1 默认参考索引数量
        $this->numRefIdxL1DefaultActive = $this->reader->readUe() + 1;
        // weighted_pred_flag（u(1)）：P/SP 片是否使用加权预测
        $this->weightedPredFlag = (bool)$this->reader->readU(1);
        // weighted_bipred_idc（u(2)）：B 片加权预测模式（0=默认，1=显式，2=隐式）
        $this->weightedBipredIdc = $this->reader->readU(2);

        // pic_init_qp_minus26（se(v)）+ 26：亮度 QP 初值
        $this->picInitQp = $this->reader->readSe() + 26;
        // pic_init_qs_minus26（se(v)）：SP/SI 片 QS 初值，当前实现未使用
        $this->reader->readSe();
        // chroma_qp_index_offset（se(v)）：色度 QP 相对亮度 QP 的偏移
        $this->chromaQpIndexOffset = $this->reader->readSe();

        // 修复色度QP偏移范围 -12 ~ 12
        // 规范 7.4.2.2 规定该值应在 [-12, 12]；越界时按常见默认值 5 容错处理
        if ($this->chromaQpIndexOffset < -12 || $this->chromaQpIndexOffset > 12) {
            $this->chromaQpIndexOffset = 5;
        }

        // deblocking_filter_control_present_flag（u(1)）：slice 头是否携带去块滤波控制参数
        $this->deblockingFilterParametersPresent = (bool)$this->reader->readU(1);
        // constrained_intra_pred_flag（u(1)）：受限帧内预测标志，当前实现未启用
        $this->reader->skip(1); // constrained_intra_pred_flag
        // redundant_pic_cnt_present_flag（u(1)）：是否携带 redundant_pic_cnt
        $this->redundantPicCntPresent = (bool)$this->reader->readU(1);

        // High profile PPS 扩展（7.3.2.2）
        // 与 SPS 相同的高档次判断：High 系列才有后续扩展字段
        $isHighProfile = in_array($this->profileIdc, [100, 110, 122, 244, 44, 83, 86, 118, 128, 138, 139, 134, 135, 144]);
        if ($isHighProfile) {
            // transform_8x8_mode_flag（u(1)）：是否允许 8x8 整数变换（见 8.5.13）
            $this->transform8x8ModeFlag = (bool)$this->reader->readU(1);
            // pic_scaling_matrix_present_flag（u(1)）：是否存在 PPS 级缩放矩阵
            $picScalingMatrixPresent = $this->reader->readU(1);
            if ($picScalingMatrixPresent) {
                // 8 个缩放列表：6 个 4x4 + 2 个 8x8（此处仅消费语法，解码器假定 flat=16）
                for ($i = 0; $i < 8; $i++) {
                    // pic_scaling_list_present_flag[i]（u(1)）
                    $useScalingList = $this->reader->readU(1);
                    if ($useScalingList) {
                        // 前 6 个列表各 16 个系数（4x4），后 2 个各 64 个系数（8x8）
                        $size = ($i < 6) ? 16 : 64;
                        // 缩放值初值 8
                        $lastScale = 8;
                        $nextScale = 8;
                        // 逐系数读取 delta_scale 并递推（语法同 7.3.2.1.1.1 的 scaling_list()）
                        for ($j = 0; $j < $size; $j++) {
                            // nextScale 为 0 时不再读取，后续系数继承 lastScale
                            if ($nextScale !== 0) {
                                $nextScale = ($lastScale + $this->reader->readSe() + 256) % 256;
                            }
                            // nextScale 非 0 则更新 lastScale
                            $lastScale = $nextScale === 0 ? $lastScale : $nextScale;
                        }
                    }
                }
            }
            // second_chroma_qp_index_offset（se(v)）：Cb/Cr 第二个色度 QP 偏移
            $this->secondChromaQpIndexOffset = $this->reader->readSe();
        } else {
            // 非 High profile：不支持 8x8 变换，第二个色度 QP 偏移为 0
            $this->transform8x8ModeFlag = false;
            $this->secondChromaQpIndexOffset = 0;
        }
    }
}
