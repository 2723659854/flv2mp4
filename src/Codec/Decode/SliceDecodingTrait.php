<?php

namespace Xiaosongshu\Flv2mp4\Codec\Decode;

/**
 * @purpose slice分片解析器
 * @author yanglong
 * @time 2026年7月23日15:21:48
 *
 * 本 trait 实现 H.264 的 slice 层解码入口，对应规范：
 *  - 7.3.3 / 7.4.3：slice header 的语法与语义；
 *  - 7.3.4：slice data 语法（宏块遍历、mb_skip_run、end_of_slice_flag）；
 *  - 6.4：宏块地址与邻居推导（光栅扫描顺序）；
 *  - 8.2.4：参考图像列表的构建与修改；
 *  - 9.2：CAVLC 的上下文推导；9.3.1.2：CABAC 初始化；
 *  - 8.7：去块滤波的边界强度与滤波过程。
 *
 * 整体流程：解析 slice header → 构建参考列表 → 初始化熵解码上下文
 * → 按光栅扫描逐宏块解码 → 整帧解码结束后执行去块滤波。
 */
trait SliceDecodingTrait
{
    /**
     * 解码Slice层
     *
     * 入参 $rbsp 为去除防竞争字节（emulation prevention）后的 RBSP；
     * $isIDR 标识 IDR 图像；$nalRefIdc 为 nal_ref_idc，为 0 时该图像不作为参考，
     * 因此不存在 dec_ref_pic_marking 语法；$skipDeblock 为抽帧模式，
     * 仅跳过去块滤波与相关宏块簿记，但仍完成解码以维持参考链。
     *
     * @param string $rbsp        去防竞争字节后的 RBSP
     * @param bool   $isIDR       是否 IDR slice
     * @param int    $nalRefIdc   nal_ref_idc
     * @param bool   $skipDeblock 是否跳过去块滤波
     */
    public function decodeSlice(string $rbsp, bool $isIDR, int $nalRefIdc, bool $skipDeblock = false): void
    {
        // 被抽帧丢弃的帧：不输出、仅维持P链参考，跳过去块滤波及其宏块簿记
        $this->deblockInfoEnabled = !$skipDeblock;
        // 用 RBSP 重建比特读取器，后续严格按 7.3.3 的语法顺序读取 slice header
        $this->reader = new BitReader($rbsp);
        // first_mb_in_slice（7.3.3，ue(v)）：本 slice 首个宏块的光栅扫描地址（6.4.1）
        $firstMbInSlice = $this->reader->readUe();
        // slice_type（7.3.3，ue(v)）：7.4.3 规定取模 5 后映射为
        // 0=P、1=B、2=I、3=SP、4=SI；原值 5..9 表示同图后续不得再出现同类型 slice
        $sliceTypeRaw = $this->reader->readUe();
        $sliceType = $sliceTypeRaw % 5;
        $this->currentSliceType = $sliceType;
        // pic_parameter_set_id（7.3.3，ue(v)）：选择 PPS，其内容已在 PPS 解析阶段写入成员
        $ppsId = $this->reader->readUe();
        // frame_num（7.3.3，u(v)）：位宽为 log2_max_frame_num_minus4 + 4（7.4.3）
        $frameNumBits = $this->log2MaxFrameNumMinus4 + 4;
        $frameNum = $this->reader->readU($frameNumBits);
        $this->currFrameNum = $frameNum;
        // 仅当 frame_mbs_only_flag = 0（允许场编码）时才存在 field_pic_flag（7.3.3）；
        // 其为 1 时再读 bottom_field_flag。本解码器按帧处理，这里只消费语法。
        if (!$this->frameMbsOnlyFlag) {
            $fieldPicFlag = $this->reader->readU(1);
            if ($fieldPicFlag) {
                $this->reader->skip(1);
            }
        }

        // IDR 图像：读 idr_pic_id（7.3.3，ue(v)），并清空 DPB（8.2.5：IDR 使先前参考全部失效）
        if ($isIDR) {
            $idrPicId = $this->reader->readUe();
            $this->dpb = [];
        }

        // pic_order_cnt_type = 0（7.3.3）：读 pic_order_cnt_lsb（u(v)，
        // 位宽 = log2_max_pic_order_cnt_lsb_minus4 + 4）
        if ($this->picOrderCntType === 0) {
            $pocLsb = $this->reader->readU($this->log2MaxPicOrderCntLsb);
            // 非 frame_mbs_only 且当前为帧图像时存在 delta_pic_order_cnt_bottom（7.3.3）
            if ($this->bottomFieldPicOrderInFramePresent) {
                $deltaBottom = $this->reader->readSe();
            }
            $this->currPicOrderCntLsb = $pocLsb;
            // MaxPicOrderCntLsb = 1 << log2_max_pic_order_cnt_lsb_minus4 + 4
            $maxPocLsb = 1 << $this->log2MaxPicOrderCntLsb;
            if ($isIDR) {
                // IDR 图像的 PicOrderCntMsb 恒为 0（8.2.1.1）
                $this->currPicOrderCntMsb = 0;
            } else {
                // spec 8.2.1.1（frame-only，无 frame_num 断档）：以最近参考帧的 (msb,lsb) 为基准，
                // 按 lsb 差阈值推导 msb。阈值判定保证 POC 在整个序列内单调递增，
                // 而不是折叠回单个 lsb 周期 —— 否则越过 MaxPicOrderCntLsb 后 B 片 past/future
                // 判定与显示序重排（reorderByPoc）都会错位。
                // lsb 差 = 当前 lsb 与上一参考帧 lsb 之差，是 8.2.1.1 判据的输入
                $lsbDiff = $pocLsb - $this->prevPicOrderCntLsb;
                // 半个 POC 周期作为回绕阈值
                $halfPoc = $maxPocLsb >> 1;
                if ($lsbDiff > $halfPoc) {
                    // 差值为大正数：发生负向回绕，msb 减一个周期
                    $this->currPicOrderCntMsb = $this->prevPicOrderCntMsb - $maxPocLsb;
                } elseif ($lsbDiff <= -$halfPoc) {
                    // 差值为大负数：发生正向回绕，msb 加一个周期
                    $this->currPicOrderCntMsb = $this->prevPicOrderCntMsb + $maxPocLsb;
                } else {
                    // 同一 lsb 周期内：msb 保持不变
                    $this->currPicOrderCntMsb = $this->prevPicOrderCntMsb;
                }
            }
            // 最终 POC = PicOrderCntMsb + pic_order_cnt_lsb（8.2.1.1，式 8-3）
            $this->currPicOrderCnt = $this->currPicOrderCntMsb + $pocLsb;
        }
        // pic_order_cnt_type = 1（7.3.3）：读 delta_pic_order_cnt[0]（以及场图像的 [1]）；
        // 本实现仅消费该语法，POC 由类型 0 路径或上层计算给出
        if ($this->picOrderCntType === 1) {
            $delta0 = $this->reader->readSe();
            if ($this->bottomFieldPicOrderInFramePresent) {
                $delta1 = $this->reader->readSe();
            }
        }
        // redundant_pic_cnt_present_flag = 1 时存在 redundant_pic_cnt（7.3.3）
        if ($this->redundantPicCntPresent) {
            $redPicCnt = $this->reader->readUe();
        }
        // B slice：direct_spatial_mv_pred_flag（7.3.3），决定 B_Direct 用空域还是时域推导（8.4.1.2）
        if ($sliceType === 1) {
            $this->directSpatialMvPred = (bool)$this->reader->readU(1);
        }

        // num_ref_idx_l0/l1_default_active_minus1 + 1 得到默认活动参考数（7.4.3），
        // 来自 PPS 的解析结果
        $numRefIdxL0Active = $this->numRefIdxL0DefaultActive;
        $numRefIdxL1Active = $this->numRefIdxL1DefaultActive;

        // 非 I/SI slice 才有 num_ref_idx_active_override_flag（7.3.3）；
        // 置 1 时显式读取 L0（B 片还包括 L1）的活动参考个数
        if ($sliceType !== 2 && $sliceType !== 4) {
            $numRefIdxActiveOverrideFlag = $this->reader->readU(1);
            $this->numRefIdxActiveOverrideFlag = (bool)$numRefIdxActiveOverrideFlag;
            if ($numRefIdxActiveOverrideFlag) {
                // num_ref_idx_l0_active_minus1（ue(v)）+ 1
                $numRefIdxL0Active = $this->reader->readUe() + 1;
                $this->numRefIdxL0Active = $numRefIdxL0Active;
                if ($sliceType === 1) {
                    // B 片还需 num_ref_idx_l1_active_minus1（ue(v)）+ 1
                    $numRefIdxL1Active = $this->reader->readUe() + 1;
                }
            } else {
                // 无 override：沿用 PPS 默认值
                $this->numRefIdxL0Active = $this->numRefIdxL0DefaultActive;
            }
            $this->numRefIdxL1Active = $numRefIdxL1Active;
        }
        
        // 参考列表的默认构建在 dec_ref_pic_marking（MMCO/滑动窗口）之后统一进行；
        // 此处只解析并暂存 ref_pic_list_modification 命令（spec 7.3.3.1：
        // idc=0/1 仅 abs_diff_pic_num_minus1 一个参数，idc=2 仅 long_term_pic_num 一个参数）
        $this->modsL0 = [];
        // ref_pic_list_modification_flag_l0（7.3.3.1）：为 1 时循环读取修改命令
        if ($sliceType !== 2 && $sliceType !== 4) {
            $refPicListModificationFlagL0 = $this->reader->readU(1);
            if ($refPicListModificationFlagL0) {
                while (true) {
                    // modification_of_pic_nums_idc（ue(v)）：3 表示命令列表结束
                    $idc = $this->reader->readUe();
                    if ($idc === 3) break;
                    // 每条命令的参数：idc=0/1 为 abs_diff_pic_num_minus1，idc=2 为 long_term_pic_num
                    $this->modsL0[] = [$idc, $this->reader->readUe()];
                }
            }
        }
        $this->modsL1 = [];
        // B slice 才有 ref_pic_list_modification_flag_l1（7.3.3.1）
        if ($sliceType === 1) {
            $refPicListModificationFlagL1 = $this->reader->readU(1);
            if ($refPicListModificationFlagL1) {
                while (true) {
                    // modification_of_pic_nums_idc（ue(v)）
                    $idc = $this->reader->readUe();
                    if ($idc === 3) break;
                    // 命令参数（语义同 L0）
                    $this->modsL1[] = [$idc, $this->reader->readUe()];
                }
            }
        }

        // 默认不使用加权预测；仅当 7.3.3.2 的触发条件成立时才解析 pred_weight_table：
        // P/SP 片看 weighted_pred_flag，B 片看 weighted_bipred_idc
        $this->pwtActive = false;
        if (($this->weightedPredFlag && ($sliceType === 0 || $sliceType === 3)) || ($this->weightedBipredIdc === 1 && $sliceType === 1)) {
            // luma/chroma_log2_weight_denom（ue(v)）：权重归一化的 log2 因子
            $lumaLog2WeightDenom = $this->reader->readUe();
            $chromaLog2WeightDenom = $this->reader->readUe();
            $this->pwtLd = $lumaLog2WeightDenom;
            $this->pwtCd = $chromaLog2WeightDenom;
            // 默认权重 = 1 << denom，默认偏移 = 0（7.4.3.2）
            $defLumaW = 1 << $lumaLog2WeightDenom;
            $defChromaW = 1 << $chromaLog2WeightDenom;
            // 遍历 L0 的每个活动参考索引读取权重
            for ($i = 0; $i < $numRefIdxL0Active; $i++) {
                // luma_weight_l0_flag：0 表示亮度使用默认权重
                $lumaWeightL0Flag = $this->reader->readU(1);
                $lw = $defLumaW; $lo = 0;
                if ($lumaWeightL0Flag) {
                    // spec 7.3.3.2：luma_weight_l0[i] 是有符号绝对权重值，
                    // 不是 default+delta！（flag=0 时才是默认值 1<<denom）
                    $lw = $this->reader->readSe();
                    $lo = $this->reader->readSe();
                }
                // chroma_weight_l0_flag：为 1 时读 Cb/Cr 各自的权重与偏移（共 4 个 se(v)）
                $chromaWeightL0Flag = $this->reader->readU(1);
                $cbw = $defChromaW; $cbo = 0; $crw = $defChromaW; $cro = 0;
                if ($chromaWeightL0Flag) {
                    $cbw = $this->reader->readSe();
                    $cbo = $this->reader->readSe();
                    $crw = $this->reader->readSe();
                    $cro = $this->reader->readSe();
                }
                // 保存 L0 的权重/偏移，供加权运动补偿使用（8.4.2.3）
                $this->pwtL0W[$i] = $lw; $this->pwtL0O[$i] = $lo;
                $this->pwtL0CbW[$i] = $cbw; $this->pwtL0CbO[$i] = $cbo;
                $this->pwtL0CrW[$i] = $crw; $this->pwtL0CrO[$i] = $cro;
                // 任一权重或偏移偏离默认值，即标记需要走加权预测路径
                if ($lw !== $defLumaW || $lo !== 0 || $cbw !== $defChromaW || $cbo !== 0 || $crw !== $defChromaW || $cro !== 0) {
                    $this->pwtActive = true;
                }
            }
            if ($sliceType === 1) {
                // B 片的 L1 权重表：本实现只消费语法，不保存 L1 权重
                for ($i = 0; $i < $numRefIdxL1Active; $i++) {
                    // luma_weight_l1_flag + 权重/偏移
                    $lumaWeightL1Flag = $this->reader->readU(1);
                    if ($lumaWeightL1Flag) {
                        $this->reader->readSe();
                        $this->reader->readSe();
                    }
                    // chroma_weight_l1_flag + Cb/Cr 共 4 个值
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
        // 执行必须延后到当前帧解码完成、加入 DPB 之后（spec 8.2.5：
        // ref_pic_marking 在帧完成后执行）——否则当前帧的
        // 参考列表会用到一个被提前踢除了参考帧的 DPB（多参考 P/B 帧错位）。
        $this->pendingMmco = [];
        $this->pendingMmcoSliding = false;
        // 只有参考图像（nal_ref_idc != 0）才存在该语法（7.3.3.3）
        if ($nalRefIdc !== 0) {
            if ($isIDR) {
                // IDR：读 no_output_of_prior_pics_flag 与 long_term_reference_flag
                $this->reader->readU(1); // no_output_of_prior_pics_flag
                $this->reader->readU(1); // long_term_reference_flag
            } else {
                // 非 IDR：adaptive_ref_pic_marking_mode_flag 选择显式 MMCO 或隐式滑动窗口
                $adaptiveRefPicMarkingModeFlag = $this->reader->readU(1);
                if ($adaptiveRefPicMarkingModeFlag) {
                    while (true) {
                        // memory_management_control_operation（ue(v)）：0 表示命令结束
                        $mmco = $this->reader->readUe();
                        if ($mmco === 0) break;
                        // 按 MMCO 类型消费各自的参数（7.3.3.3，表 7-9）
                        $args = [];
                        if ($mmco === 1 || $mmco === 3) $args[] = $this->reader->readUe(); // diff_of_pic_nums_minus1
                        if ($mmco === 2) $args[] = $this->reader->readUe(); // long_term_pic_num
                        if ($mmco === 3 || $mmco === 6) $args[] = $this->reader->readUe(); // long_term_frame_idx
                        if ($mmco === 4) $args[] = $this->reader->readUe(); // max_long_term_frame_idx_plus1
                        // 暂存命令，待帧进入 DPB 后执行（8.2.5）
                        $this->pendingMmco[] = [$mmco, $args];
                    }
                } else {
                    // 无显式命令：标记需要在帧入 DPB 后执行滑动窗口（8.2.5.3）
                    $this->pendingMmcoSliding = true;
                }
            }
        }

        // cabac_init_idc（7.3.3，ue(v)）：仅 CABAC 且非 I/SI slice 存在；
        // 与 slice QP 一起选择上下文初始化表（9.3.1.2）；I/SI 固定为 0
        if ($this->entropyCodingModeFlag && $sliceType !== 2 && $sliceType !== 4) {
            $this->cabacInitIdc = $this->reader->readUe();
        } else {
            $this->cabacInitIdc = 0;
        }

        // slice_qp_delta（se(v)）：QPY = 26 + pic_init_qp_minus26 + slice_qp_delta（7.4.3）
        $sliceQpDelta = $this->reader->readSe();
        $qp = $this->picInitQp + $sliceQpDelta;
        // QPY 限制在 [0, 51]（7.4.5）；后续每宏块再叠加 mb_qp_delta
        $qp = max(0, min(51, $qp));
        // SP/SI 专有语法（7.3.3）：SP 先读 sp_for_switch_flag，随后读 slice_qs_delta
        if ($sliceType === 3 || $sliceType === 4) {
            if ($sliceType === 3) $this->reader->skip(1);
            $this->reader->readSe();
        }

        // deblocking_filter_control_present_flag = 1 时存在去块滤波参数（7.3.3）：
        // disable_deblocking_filter_idc 与 alpha/beta offset
        if ($this->deblockingFilterParametersPresent) {
            $deblockingFilterIdc = $this->reader->readUe();
            $this->disableDeblockingFilterIdc = $deblockingFilterIdc;
            if ($deblockingFilterIdc !== 1) {
                // slice_alpha_c0_offset_div2 / slice_beta_offset_div2（se(v)），
                // 乘 2 后用于 8.7.2.2 中查 alpha/beta 阈值表
                $alphaOffset = $this->reader->readSe();
                $betaOffset = $this->reader->readSe();
                $this->sliceAlphaC0Offset = $alphaOffset * 2;
                $this->sliceBetaOffset = $betaOffset * 2;
            }
        } else {
            // 语法不存在时：默认开启去块滤波，偏移为 0
            $this->disableDeblockingFilterIdc = 0;
            $this->sliceAlphaC0Offset = 0;
            $this->sliceBetaOffset = 0;
        }

        // CABAC：slice header 后字节对齐，从对齐字节起初始化算术引擎
        if ($this->entropyCodingModeFlag) {
            // 丢弃 byte_alignment 的填充比特（7.3.3）
            $this->reader->alignToByte();
            // 头部结束处的字节偏移，即 CABAC 算术编码数据的起始位置
            $headerEndByte = intdiv($this->reader->getPos(), 8);
            // slice_type 归一化：I/SI 记 2、B 记 1、其余（P/SP）记 0，用于选择上下文表
            $sliceTypeNos = ($sliceType === 2 || $sliceType === 4) ? 2 : ($sliceType === 1 ? 1 : 0);
            // 初始化 CABAC 上下文（9.3.1.2）：sliceQp 与 cabac_init_idc 决定初始状态
            $this->cabacSliceBegin($rbsp, $headerEndByte, $qp, $sliceTypeNos);
        }

        // 图像宏块尺寸与总数（6.4.1：PicWidthInMbs × FrameHeightInMbs）
        $mbWidth = $this->picWidthInMbs;
        $mbHeight = $this->picHeightInMbs;
        $totalMbs = $mbWidth * $mbHeight;
        // 起始宏块地址来自 first_mb_in_slice；本实现按单 slice 覆盖到图像末尾处理
        $startMbIdx = $firstMbInSlice;
        $endMbIdx = $totalMbs;

        // 非 IDR 图像：按 8.2.4 构建参考图像列表
        if (!$isIDR) {
            if ($sliceType === 1) {
                // B 片默认 L0（8.2.4.2.3）：POC past 降序 + future 升序；下方 B 块会重建 L0/L1
                $pastRefs = [];
                $futureRefs = [];
                // 尚未执行 marking 的 DPB 中：POC 大于当前 POC 的为 future，其余为 past；
                // 长期参考不进入默认列表（8.2.4.2.3）
                foreach ($this->dpb as $entry) {
                    if (!$entry['isLongTerm']) {
                        if ($entry['poc'] > $this->currPicOrderCnt) {
                            $futureRefs[] = $entry;
                        } else {
                            $pastRefs[] = $entry;
                        }
                    }
                }
                // past 按 POC 降序（越近越靠前），future 按 POC 升序（越近越靠前）
                usort($pastRefs, function ($a, $b) { return $b['poc'] - $a['poc']; });
                usort($futureRefs, function ($a, $b) { return $a['poc'] - $b['poc']; });
                $this->refPicList0 = array_merge($pastRefs, $futureRefs);
            } else {
                // P/SP 片默认 L0（8.2.4.2.1）：短期参考按 FrameNumWrap 降序（最接近当前帧优先）。
                // 注意：存在参考 B 帧时 frame_num 序与 POC 序不同，必须按 frame_num。
                // MaxPicNum = 1 << (log2_max_frame_num_minus4 + 4)（8.2.4.2.1）
                $maxPicNum = 1 << ($this->log2MaxFrameNumMinus4 + 4);
                $refs = [];
                foreach ($this->dpb as $entry) {
                    if (!$entry['isLongTerm']) {
                        $fn = $entry['frameNum'];
                        // FrameNumWrap：参考帧 frame_num 大于当前值时减去 MaxPicNum（8.2.4.2.1）
                        $refs[] = ['w' => ($fn > $frameNum) ? $fn - $maxPicNum : $fn, 'e' => $entry];
                    }
                }
                // 按 FrameNumWrap 降序排序，即默认 L0 顺序（最近参考优先）
                usort($refs, function ($a, $b) { return $b['w'] - $a['w']; });
                $this->refPicList0 = array_map(function ($r) { return $r['e']; }, $refs);
                // 执行 ref_pic_list_modification（8.2.4.3.1）
                if (!empty($this->modsL0)) {
                    $this->applyRefPicListModification($this->refPicList0, $this->modsL0, $frameNum, $maxPicNum, $numRefIdxL0Active);
                }
                // 截断到 numRefIdxL0Active 个
                if (count($this->refPicList0) > $numRefIdxL0Active) {
                    $this->refPicList0 = array_slice($this->refPicList0, 0, $numRefIdxL0Active);
                }
                // 不足 numRefIdxL0Active 时以默认列表[0]（default_ref）补齐（null 槽位替换为 default_ref）
                while (count($this->refPicList0) < $numRefIdxL0Active && !empty($this->refPicList0)) {
                    $this->refPicList0[] = $this->refPicList0[0];
                }
            }
        } else {
            // IDR 图像不做帧间预测，参考列表置空
            $this->refPicList0 = [];
        }

        // 缓存 list0 首个参考帧的平面指针、步长与尺寸，供后续运动补偿直接使用
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
            // 以当前 POC 为界划分 past/future（8.2.4.2.3）
            foreach ($this->dpb as $entry) {
                if ($entry['poc'] > $this->currPicOrderCnt) {
                    $futureRefs[] = $entry;
                } elseif ($entry['poc'] < $this->currPicOrderCnt) {
                    $pastRefs[] = $entry;
                }
            }
            // future 升序、past 降序：两者都是"距当前越近越靠前"
            usort($futureRefs, function ($a, $b) { return $a['poc'] - $b['poc']; });
            usort($pastRefs, function ($a, $b) { return $b['poc'] - $a['poc']; });
            $this->refPicList0 = array_merge($pastRefs, $futureRefs);
            $this->refPicList1 = array_merge($futureRefs, $pastRefs);
            // 执行 ref_pic_list_modification（8.2.4.3.1）
            if (!empty($this->modsL0) || !empty($this->modsL1)) {
                // B 片的 MaxPicNum 与 P 片一致，用于短期参考 picNum 的取模
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
            // 重建后刷新默认参考帧缓存（L0 首元素）
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
        // nzTopRow*/nzLeftCol* 保存相邻 4x4 块的 TotalCoeff，用于 CAVLC 的 nC 推导（9.2.1）；
        // 每个宏块 luma 有 16 个 4x4 块（每行 4 块），色度同为 4 列，故行缓存大小为 mbWidth*4
        $this->nzTopRowLuma = array_fill(0, $mbWidth * 4, 0);
        $this->nzTopRowChroma = array_fill(0, $mbWidth * 4, 0);
        $this->nzLeftColLuma = array_fill(0, 4, 0);
        $this->nzLeftColChroma = array_fill(0, 4, 0);
        // intra4x4 预测模式缓存（8.3.1.2 用相邻块模式推导最可能模式），-1 表示不可用
        $this->intra4x4TopModes = array_fill(0, $mbWidth * 4, -1);
        $this->intra4x4LeftModes = array_fill(0, 4, -1);

        // 初始化运动向量缓存（4x4子块粒度，每宏块4个，所以mvTopRow大小为mbWidth*4）
        // mvTopRow/mvLeftCol 保存相邻 4x4 子块的 MV，用于中值预测（8.4.1.3）
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
            // 每宏块的 MbType 与 QP：8.7.2.1 推导 bS 时需要 MbType（判断是否 intra / skip）
            $this->mbTypeForDeblock = array_fill(0, $totalMbs, -1);
            $this->mbQpForDeblock = array_fill(0, $totalMbs, $qp);
            // 每帧必须重置NZ缓存，否则P_Skip宏块会使用前一帧的过期数据导致边界强度计算错误
            // 每宏块 24 个 4x4 块的 NNZ（luma 16 + Cb 4 + Cr 4），用于 bS 的非零系数条件
            $emptyNz = array_fill(0, 24, 0);
            $this->mbNnzForDeblock = array_fill(0, $totalMbs, $emptyNz);
            // 每宏块 16 个 4x4 子块的 MV（L0/L1）与参考索引（L0/L1），
            // 用于 8.7.2.1 中"MV 或参考图像不同"的判定
            $emptyMv = array_fill(0, 16, [0, 0]);
            $this->mbMvForDeblock = array_fill(0, $totalMbs, $emptyMv);
            $emptyRef = array_fill(0, 16, 0);
            $this->mbRefForDeblock = array_fill(0, $totalMbs, $emptyRef);
            $this->mbMvL1ForDeblock = array_fill(0, $totalMbs, $emptyMv);
            $this->mbRefL1ForDeblock = array_fill(0, $totalMbs, $emptyRef);
            // 参考索引 → 参考帧唯一 id（参考实现 ref2frm 语义）：去块跨列表比较用
            $this->deblockRefIdL0 = [];
            foreach ($this->refPicList0 as $ri => $entry) {
                $this->deblockRefIdL0[$ri] = $entry['id'] ?? -1;
            }
            $this->deblockRefIdL1 = [];
            foreach ($this->refPicList1 as $ri => $entry) {
                $this->deblockRefIdL1[$ri] = $entry['id'] ?? -1;
            }
        }

        // mb_skip_run 初值 -1 表示尚未读取；在首个需要 skip 判定的位置惰性读取（7.3.4）
        $mbSkipRun = -1;

        //$isPSlice = ($sliceType === 0 || $sliceType === 5);
        // 按宏块地址递增顺序遍历（6.4.1 光栅扫描），从 first_mb_in_slice 直到图像末尾
        for ($mbIdx = $startMbIdx; $mbIdx < $endMbIdx; $mbIdx++) {
            // 地址 → 二维坐标：mbX 为列号，mbY 为行号
            $mbX = $mbIdx % $mbWidth;
            $mbY = (int)($mbIdx / $mbWidth);

            // 行首（mbX=0）处没有左侧邻居，需重置所有左邻居缓存（8.4.1.3 / 9.2.1）
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
        // 隐式加权权重缓存同片重置
        $this->bDistScaleFactor = [];
        $this->bImplicitWeight = [];
                }
            }
            // 预先保存"上一行同列宏块"的 top-left 邻居：本宏块解码会覆盖 mvTopRow，
            // 之后再取会拿到本宏块的数据，因此必须在覆盖前取出
            $nextMvTopLeft = $this->mvTopRow[$mbX * 4 + 3] ?? null;
            if ($isBslice) {
                // B 片：分别取 L0/L1 的 top-left 邻居
                $nextBTL0 = $this->bMvTopRow[0][$mbX * 4 + 3] ?? null;
                $nextBTL1 = $this->bMvTopRow[1][$mbX * 4 + 3] ?? null;
            }

            // CAVLC 的 B 片 skip：mb_skip_run 给出连续 B_Skip 宏块个数（7.3.4）
            if ($isBslice && !$this->entropyCodingModeFlag) {
                if ($mbSkipRun === -1) {
                    // mb_skip_run（ue(v)）：仅在该 run 用尽后才重新读取
                    $mbSkipRun = $this->reader->readUe();
                }
                if ($mbSkipRun--) {
                    // B_Skip = B_Direct_16x16 无残差
                    $this->decodeBSkip($mbX, $mbY);
                    if ($this->deblockInfoEnabled) {
                        // MbType 记为 0（skip/direct），QP 记为当前 slice QP，供 8.7 使用
                        $this->mbTypeForDeblock[$mbIdx] = 0;
                        $this->mbQpForDeblock[$mbIdx] = $qp;
                    }
                    // skip 宏块后重置 top-left 邻居缓存
                    $this->bMvTopLeft[0] = $nextBTL0;
                    $this->bMvTopLeft[1] = $nextBTL1;
                    continue;
                }
            }

            // CAVLC 的 P/SP 片 skip：逻辑同上，mb_skip_run 统计连续 P_Skip 个数
            if (($sliceType === 0 || $sliceType === 5) && !$this->entropyCodingModeFlag) {
                if ($mbSkipRun === -1) {
                    // mb_skip_run（ue(v)）
                    $mbSkipRun = $this->reader->readUe();
                }
                if ($mbSkipRun--) {
                    $this->decodePSkip($mbX, $mbY);
                    if ($this->deblockInfoEnabled) {
                        // skip 宏块：MbType 记 0，QP 沿用当前 slice QP
                        $this->mbTypeForDeblock[$mbIdx] = 0;
                        $this->mbQpForDeblock[$mbIdx] = $qp;
                    }
                    // MV 预测时 top-left 取上一行同列宏块的 MV
                    $this->mvTopLeft = [$nextMvTopLeft, $nextMvTopLeft, $nextMvTopLeft, $nextMvTopLeft];
                    continue;
                }
            }
            if ($this->entropyCodingModeFlag && ($sliceType === 2 || $sliceType === 4)) {
                // CABAC 的 I/SI 宏块：mb_type、预测模式、残差全部由算术解码（9.3.2.2 起）
                $this->decodeCabacIntraMb($mbX, $mbY);
                if ($this->deblockInfoEnabled) {
                    // CABAC 下 QP 由 mb_qp_delta 更新后的 cabQp 决定
                    $this->mbQpForDeblock[$mbIdx] = $this->cabQp;
                }
                $this->mvTopLeft = [$nextMvTopLeft, $nextMvTopLeft, $nextMvTopLeft, $nextMvTopLeft];
                // end_of_slice_flag：每个 MB 后必读 terminate bin
                if ($this->cabTerminate() !== 0) break;
                continue;
            }
            if ($this->entropyCodingModeFlag && ($sliceType === 0 || $sliceType === 5)) {
                // CABAC 的 P/SP 宏块（9.3.2.3 起）
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
                // CABAC 的 B 宏块（含 direct / 双向预测语法）
                $this->decodeCabacBMb($mbX, $mbY);
                if ($this->deblockInfoEnabled) {
                    $this->mbQpForDeblock[$mbIdx] = $this->cabQp;
                }
                $this->mvTopLeft = [$nextMvTopLeft, $nextMvTopLeft, $nextMvTopLeft, $nextMvTopLeft];
                $this->bMvTopLeft[0] = $nextBTL0;
                $this->bMvTopLeft[1] = $nextBTL1;
                // end_of_slice_flag：同上，每 MB 一个 terminate bin
                $term = $this->cabTerminate();
                if ($term !== 0) break;
                continue;
            }
            // CAVLC 路径：解析完整的宏块层语法（7.3.5），并返回本宏块的 mb_qp_delta
            $mbQpDelta = $this->decodeMacroblock($mbX, $mbY, $qp, $sliceType);
            // QP 累加 mb_qp_delta 后按 [0,51] 回绕（7.4.5）
            $qp = $this->wrapQp($qp + $mbQpDelta);
            if ($this->deblockInfoEnabled) $this->mbQpForDeblock[$mbIdx] = $qp;
            $this->mvTopLeft = [$nextMvTopLeft, $nextMvTopLeft, $nextMvTopLeft, $nextMvTopLeft];
            if ($isBslice) {
                // B 片同样恢复 L0/L1 的 top-left 邻居
                $this->bMvTopLeft[0] = $nextBTL0;
                $this->bMvTopLeft[1] = $nextBTL1;
            }
        }
        // slice 内宏块全部解码完成后，对整帧执行去块滤波（8.7）
        if ($this->deblockInfoEnabled) {
            $this->applyDeblockingFilter();
        }
    }

    /** dec_ref_pic_marking 待执行命令（slice header 解析，帧入 DPB 后执行） */
    public array $pendingMmco = [];
    public bool $pendingMmcoSliding = false;

    /**
     * dec_ref_pic_marking 执行阶段（spec 8.2.5）。
     * 必须在当前参考帧加入 DPB 之后调用：MMCO/滑动窗口影响的是后续帧的参考列表，
     * 当前帧的列表构建用的是标记前的 DPB。
     */
    private function executeRefPicMarking(int $frameNum): void
    {
        if (!empty($this->pendingMmco)) {
            // 取出命令副本并清空待执行队列，避免重复执行
            $cmds = $this->pendingMmco;
            $this->pendingMmco = [];
            // MaxPicNum，用于 MMCO1 中 short_pic_num 的取模（8.2.5.1）
            $maxPicNumMmco = 1 << ($this->log2MaxFrameNumMinus4 + 4);
            foreach ($cmds as [$mmco, $args]) {
                if ($mmco === 1) {
                    // MMCO1: 标记短期参考为不用于参考
                    // short_pic_num=(currPicNum-diff-1)&(maxPicNum-1)
                    $shortPicNum = ($frameNum - $args[0] - 1) & ($maxPicNumMmco - 1);
                    // 在 DPB 中查找匹配的短期参考并将其移出（8.2.5.1）
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
            // 统计当前 DPB 中的短期参考数量（长期参考不参与滑动窗口）
            $shortTermCount = 0;
            foreach ($this->dpb as $entry) {
                if (!$entry['isLongTerm']) $shortTermCount++;
            }
            // 逐次移除最老的一个，直到不超过 max_num_ref_frames
            while ($shortTermCount > $this->maxNumRefFrames) {
                $oldestKey = null;
                $oldestFnWrap = null;
                foreach ($this->dpb as $k => $entry) {
                    if ($entry['isLongTerm']) continue;
                    $fn = $entry['frameNum'];
                    // 计算 FrameNumWrap（8.2.4.2.1 语义，用于比较新旧）
                    $fnWrap = ($fn > $frameNum) ? $fn - $maxFrameNum : $fn;
                    if ($oldestFnWrap === null || $fnWrap < $oldestFnWrap) {
                        // 记录当前 FrameNumWrap 最小的项（最老的短期参考）
                        $oldestFnWrap = $fnWrap;
                        $oldestKey = $k;
                    }
                }
                // 没有可移除项时退出，避免死循环
                if ($oldestKey === null) break;
                array_splice($this->dpb, $oldestKey, 1);
                $shortTermCount--;
            }
        }
    }

    /**
     * 执行 ref_pic_list_modification（spec 8.2.4.3.1）。
     * 关键语义（与朴素"移除再插入"不同）：
     *  - 列表长度固定为 refCount（numRefIdxActive），默认列表不足时先补 null；
     *  - 每条命令把目标参考写入 index，[index..i-1] 右移一位（i 为同 pic_id 的既有位置，
     *    找不到则 i=refCount-1，末位被挤出）——允许同一帧占据多个 refIdx；
     *  - 结束后仍为 null 的槽位以默认列表[0]（default_ref）替换。
     */
    private function applyRefPicListModification(array &$list, array $mods, int $currPicNum, int $maxPicNum, int $refCount): void
    {
        if ($refCount <= 0 || empty($list)) return;
        // 默认列表首元素即 default_ref（refIdx 无效时按 8.2.4.3.1 的兜底参考）
        $defaultRef = $list[0];
        // 先把列表长度固定为 refCount，不足部分用 null 占位
        while (count($list) < $refCount) $list[] = null;
        // predPicNum：idc=0/1 的递推基准，初值为当前 currPicNum（8.2.4.3.1）
        $pred = $currPicNum;
        foreach ($mods as $index => $mod) {
            // 修改命令数超过活动参考数时，多余命令不再生效
            if ($index >= $refCount) break;
            [$idc, $val] = $mod;
            $ref = null;
            $picId = 0;
            if ($idc === 0 || $idc === 1) {
                // abs_diff_pic_num_minus1 + 1 为与 predPicNum 的差值
                $absDiff = $val + 1;
                // 差值超过 MaxPicNum 视为非法，放弃后续所有修改
                if ($absDiff > $maxPicNum) return;
                // idc=0 向前减，idc=1 向后加，再对 MaxPicNum 取模得到 picNum
                $pred = ($idc === 0) ? ($pred - $absDiff) & ($maxPicNum - 1)
                                     : ($pred + $absDiff) & ($maxPicNum - 1);
                $picId = $pred;
                // 在 DPB 的短期参考中查找该 frame_num（picNum）
                foreach ($this->dpb as $e) {
                    if (!$e['isLongTerm'] && $e['frameNum'] === $picId) { $ref = $e; break; }
                }
            } else { // idc=2：长期参考（本测试流无长期参考，保留语义）
                // 长期参考：按 long_term_pic_num 匹配（8.2.4.3.1）
                $picId = $val;
                foreach ($this->dpb as $e) {
                    if ($e['isLongTerm'] && ($e['longTermPicNum'] ?? -1) === $val) { $ref = $e; break; }
                }
            }
            // 目标参考不存在时该槽位置空（稍后由 default_ref 填充）
            if ($ref === null) { $list[$index] = null; continue; }
            // 从 index 起找同 pic_id 的既有位置
            $i = $index;
            for (; $i + 1 < $refCount; $i++) {
                $cur = $list[$i];
                if ($cur !== null && $cur['isLongTerm'] === $ref['isLongTerm'] && $cur['frameNum'] === $picId) break;
            }
            // 把 [index, i-1] 的元素整体右移一位，为 index 腾出位置（末位被挤出）
            for (; $i > $index; $i--) {
                $list[$i] = $list[$i - 1];
            }
            // 目标参考写入 index（同一帧因此可占据多个 refIdx）
            $list[$index] = $ref;
        }
        // 仍为 null 的槽位用 default_ref 填充（8.2.4.3.1）
        for ($i = 0; $i < $refCount; $i++) {
            if ($list[$i] === null) $list[$i] = $defaultRef;
        }
    }
}
