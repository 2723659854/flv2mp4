<?php

namespace Xiaosongshu\Flv2mp4\Codec\Decode;

use Xiaosongshu\Flv2mp4\Codec\H264Decoder;

/**
 * @purpose 宏块解码器
 * @author yanglong
 * @time 2026年7月23日14:57:14
 */
/*
 * ============================================================================
 * MacroblockDecodingTrait —— 宏块（Macroblock）级解码
 * ============================================================================
 * 本 trait 由 H264Decoder 组合使用，负责 H.264 码流中「宏块层」的全部解析与重建工作，
 * 对应 H.264/AVC 规范（ITU-T Rec. H.264）第 7.3.5 节（宏块层语法）与第 8.3~8.5 节
 * （帧内预测、帧间预测、残差解码与重建）。
 *
 * 职责范围：
 *   1. 读取并解释 mb_type（7.3.5.1 / Table 7-11、7-13、7-14）；
 *   2. 帧内宏块：I_4x4（8.3.1.1 预测模式推导 + 8.3.2 预测）、I_16x16（8.3.3）、
 *      I_PCM（7.3.5，直接搬运 256+64+64 字节原始像素）；
 *   3. 帧间宏块：P 切片（16x16/16x8/8x16/8x8 及 P_Skip）、B 切片
 *      （Direct/BiPred/List0/List1 及 B_Skip），含 MV 预测（8.4.1.3）、
 *      参考索引解析（7.4.5.1）、亚像素插值与加权预测；
 *   4. 残差：CBP 解释（7.4.5 / 9.1.2）、nC 推导（9.2.1）、CABAC/CAVLC 系数读取、
 *      反量化（8.5.12）、逆变换 IDCT（8.5.10 / 8.5.11）、DC 逆 Hadamard；
 *   5. QP 递推：mb_qp_delta 的模 52 回绕（7.4.5.1、8.5.8），色度 QP 映射（Table 8-15）；
 *   6. 去块滤波所需簿记（8.7）：mb_type、非零系数计数 nz、MV 与参考索引的逐 4x4 存储。
 *
 * 数据布局约定（全 trait 通用）：
 *   - 像素平面 yPlane/uPlane/vPlane 均为一维数组，索引 = py * 行宽 + px；
 *   - 宏块内 4x4 块用「raster 索引」0..15（行优先），码流中的「扫描索引」需经
 *     scanToRaster / blockIndexToRaster 映射；
 *   - 色度 4x4 块编号沿用规范：16~19 = Cb（16 17 上行 / 18 19 下行），
 *     20~23 = Cr（20 21 上行 / 22 23 下行）；
 *   - nz 计数缓存 nzCache 长度 24：0~15 亮度，16~19 Cb，20~23 Cr。
 *
 * 位流读取统一通过 $this->reader（指数哥伦布 ue/se、定长 u、字节对齐与字节读取），
 * 残差系数读取内部再按 PPS 的 entropy_coding_mode_flag 区分 CABAC 与 CAVLC。
 * ============================================================================
 */
trait MacroblockDecodingTrait
{
    /**
     * 宏块级 QP 递推（H.264 7.4.5）：
     * QPY = (QPY_PREV + mb_qp_delta + 52) % 52（8bit，QpBdOffsetY=0）
     * 注意是模 52 回绕，不是截断到 [0,51]；用 clamp 会让负向 delta 卡在 0，
     * 之后整条 QP 链偏低，反量化系数被系统性缩小（横带区域重建失败即由此而来）。
     *
     * @param int $q 上一宏块 QPY 与本宏块 mb_qp_delta 之和（可能为负，也可能 >= 52）
     * @return int 回绕到 [0, 51] 的 QPY
     */
    private function wrapQp(int $q): int
    {
        // PHP 的 % 对负数保留符号，故先 % 52 再 +52 再 % 52，等价于数学意义上的非负取模；
        // 这两步不能合并成一次取模，否则负 delta 会得到负 QP。
        return (($q % 52) + 52) % 52;
    }

    /**
     * 读取 List0 的参考索引 ref_idx_l0（H.264 7.3.5.1 / 7.4.5.1 的 ref_idx_l0 语法元素）。
     *
     * 语法元素是否出现取决于 num_ref_idx_l0_active_minus1（7.4.5.1）：
     *   - 活动参考帧只有 1 个时，码流中不出现该语法元素，隐含取 0；
     *   - 恰好 2 个时，规范用 1 bit 定长编码，且语义取反（1 → 0，0 → 1），
     *     因此此处用 readU(1) ^ 1 还原；
     *   - 更多参考帧时使用无符号指数哥伦布 ue(v) 编码（9.1）。
     *
     * @return int 解码得到的 List0 参考索引，范围 [0, numRefIdxL0Active-1]
     */
    private function readRefIdxL0(): int
    {
        $numRef = $this->numRefIdxL0Active;
        // 仅 1 个活动参考帧：语法元素缺省，参考索引恒为 0
        if ($numRef <= 1) {
            return 0;
        }
        // 恰好 2 个活动参考帧：1 bit 定长，且按规范取反后使用
        if ($numRef === 2) {
            $val = $this->reader->readU(1) ^ 1;
            return $val;
        }
        // 3 个及以上：无符号指数哥伦布编码（ue(v)）
        return $this->reader->readUe();
    }

    /**
     * 解码单个宏块
     * @return int mb_qp_delta
     *
     * 流程：读取 mb_type → 记录去块簿记用的 mb_type → 按 slice_type 分派到
     * 帧内 / P / B 解码路径 → 把本宏块的 mb_qp_delta 交回调用方用于 QP 递推。
     *
     * slice_type 的取值来自 slice header（7.3.3 / Table 7-6）：
     *   0 = P、1 = B、2 = I、4 = SI，5~9 是 0~4 的「所有切片均为此类型」变体。
     * 此处实现为：2/4 走帧内，0/5 走 P，1 走 B，其余类型用灰色填充兜底。
     *
     * @param int $mbX 宏块在图像中的列号（以 16x16 亮度宏块为单位，从 0 开始）
     * @param int $mbY 宏块在图像中的行号
     * @param int $sliceQp 当前切片的 SliceQPY（7.4.5 中的 slice_qp_delta 结算结果）
     * @param int $sliceType 当前切片的 slice_type（见上）
     * @return int 本宏块的 mb_qp_delta（帧内为 delta，帧间为 0），供上层做 QPY 递推
     */
    public function decodeMacroblock(int $mbX, int $mbY, int $sliceQp, int $sliceType): int
    {
        // mb_type 是宏块层第一个语法元素，编码方式为 ue(v)（7.3.5.1）
        $mbType = $this->reader->readUe();
        // 图像宽度（宏块数），用于把二维坐标摊平为一维宏块索引
        $mbWidth = $this->picWidthInMbs;
        $mbIdx = $mbY * $mbWidth + $mbX;

        // 去块滤波（8.7）需要逐宏块的 mb_type：滤波强度推导要区分帧内/帧间与划分方式
        if ($this->deblockInfoEnabled) {
            $this->mbTypeForDeblock[$mbIdx] = $mbType;
            // 去块簿记的零值槽位在帧开始时已用共享零数组预填：
            // - nz：有残差的 MB 会在 decodeResidualAndAdd() 末尾整体替换为 nzCache；
            // - mv/ref：16x16/16x8/skip 路径整体替换；8x16/8x8ref0 原地改写路径各自先换私有数组。
            // 逐 MB 预分配（每帧 2000+ MB × 33 个数组）在频闪高 intra 流里是纯浪费。
        }

        // 帧间宏块不携带 mb_qp_delta，缺省返回 0（QP 保持不变）
        $mbQpDelta = 0;

        // 帧内切片：I（2）与 SI（4）
        if ($sliceType === 2 || $sliceType === 4) {
            $mbQpDelta = $this->decodeIntraMacroblock($mbX, $mbY, $mbType, $sliceQp);
        // P 切片：P（0）与「所有切片均为 P」的变体（5）
        } elseif ($sliceType === 0 || $sliceType === 5) {
            $mbQpDelta = $this->decodePInterMacroblock($mbX, $mbY, $mbType, $sliceQp);
        // B 切片
        } elseif ($sliceType === 1) {
            $mbQpDelta = $this->decodeBInterMacroblock($mbX, $mbY, $mbType, $sliceQp);
        } else {
            // SP/SI 等未实现类型：用中性灰填充，保证图像尺寸与后续宏块位置不错位
            $this->fillMacroblockGray($mbX, $mbY);
        }
        return $mbQpDelta;
    }

    /**
     * I帧宏块解码
     *
     * 按 I 切片的 mb_type 取值分派（7.3.5.1 / Table 7-11，I 切片中 mb_type 直接就是
     * 完整的宏块类型，不含 P 切片的 5 偏移）：
     *   - 0        → I_NxN（可能是 I_4x4 或 I_8x8，由 transform_size_8x8_flag 决定，
     *                此处实现按 I_4x4 处理）；
     *   - 1~24     → I_16x16（预测模式与 CBP 由 Table 7-11 的组合编码）；
     *   - 25       → I_PCM，逐字节搬运原始像素；
     *   - 其他     → 非法/越界，灰色填充兜底。
     *
     * 返回值为 mb_qp_delta，供上层完成 QPY 递推（7.4.5）。
     *
     * @param int $mbX 宏块列号
     * @param int $mbY 宏块行号
     * @param int $mbType 已解析出的 I 切片 mb_type
     * @param int $sliceQp 当前切片的 SliceQPY
     * @return int 本宏块的 mb_qp_delta
     */
    private function decodeIntraMacroblock(int $mbX, int $mbY, int $mbType, int $sliceQp): int
    {
        // 同位帧存储：intra 标记（B 帧 direct 的 colZero 判定依赖）
        // 8.4.1.2.1 中 colZeroFlag 要求同位参考块「不是帧内编码」且 MV 为零，故这里置最低位。
        $this->picMbType[$mbY * $this->picWidthInMbs + $mbX] |= 1;

        // I_PCM：原始采样值按字节对齐后直接写入（7.3.5 的 pcm_alignment_zero_bit 与 pcm_sample_luma/chroma）
        if ($mbType === 25) {
            // 7.3.5：pcm_alignment_zero_bit 使读指针对齐到字节边界，随后读取原始采样
            $this->reader->alignToByte();
            // 亮度 16x16 = 256 字节，按 raster 顺序逐像素搬运（8.5.13 规定 I_PCM 不做预测与变换）
            for ($y = 0; $y < 16; $y++) {
                for ($x = 0; $x < 16; $x++) {
                    $pixel = $this->reader->readByte();
                    $this->writeLumaPixel($mbX, $mbY, $x, $y, $pixel);
                }
            }
            // 色度 Cb 8x8 = 64 字节（4:2:0 下每个色度分量尺寸为亮度的 1/4）
            for ($y = 0; $y < 8; $y++) {
                for ($x = 0; $x < 8; $x++) {
                    $pixel = $this->reader->readByte();
                    $this->writeChromaPixel($mbX, $mbY, $x, $y, $pixel, 0);
                }
            }
            // 色度 Cr 8x8 = 64 字节
            for ($y = 0; $y < 8; $y++) {
                for ($x = 0; $x < 8; $x++) {
                    $pixel = $this->reader->readByte();
                    $this->writeChromaPixel($mbX, $mbY, $x, $y, $pixel, 1);
                }
            }
            // I_PCM宏块传递DC_PRED(2)给相邻宏块
            // 依据 8.3.1.1：非 Intra_4x4 的相邻宏块，其 4x4 预测模式按 DC_PRED(2) 推断
            $this->intra4x4LeftModes = array_fill(0, 4, 2);
            // 上边缓存按绝对 4x4 列号索引：本宏块占 baseLuma .. baseLuma+3 四列
            $baseLuma = $mbX * 4;
            $this->intra4x4TopModes[$baseLuma + 0] = 2;
            $this->intra4x4TopModes[$baseLuma + 1] = 2;
            $this->intra4x4TopModes[$baseLuma + 2] = 2;
            $this->intra4x4TopModes[$baseLuma + 3] = 2;
            // I_PCM 不携带 mb_qp_delta，QP 保持不变
            return 0;
        }

        // Intra4x4 / Intra16x16
        if ($mbType === 0) {
            // I_NxN（此处按 I_4x4 处理，共 16 个 4x4 亮度块）
            return $this->decodeIntra4x4($mbX, $mbY, $sliceQp);
        } elseif ($mbType >= 1 && $mbType <= 24) {
            // I_16x16：mb_type 同时编码 4 种预测模式与 6 种 CBP 组合（Table 7-11）
            return $this->decodeIntra16x16($mbX, $mbY, $mbType, $sliceQp);
        } else {
            // 超出 Table 7-11 范围：灰色填充，避免像素平面留下未初始化数据
            $this->fillMacroblockGray($mbX, $mbY);
            return 0;
        }
    }

    /**
     * Intra4x4 完整解码
     * @return int mb_qp_delta
     *
     * 实现 I_NxN（此处仅 4x4 变换）宏块的完整重建流程，覆盖：
     *   1. 16 个亮度 4x4 块的帧内预测模式解析（7.3.5.1 的 prev_intra4x4_pred_mode_flag /
     *      rem_intra4x4_pred_mode，配合 8.3.1.1 的候选模式推导）；
     *   2. 跨宏块预测模式缓存更新（供右侧与下方宏块推导候选模式）；
     *   3. chroma_pred_mode（Table 8-4 的 4 种色度模式）与 coded_block_pattern（9.1.2 映射表）；
     *   4. mb_qp_delta 读取与 QP 递推（7.4.5，仅当 CBP > 0 时出现）；
     *   5. 亮度 16 个 4x4 系数、色度 DC(4x4) + AC(4x4) 系数的读取、反量化与 IDCT；
     *   6. 预测值叠加残差并写回像素平面，最后更新 nz 计数缓存。
     *
     * @param int $mbX 宏块列号
     * @param int $mbY 宏块行号
     * @param int $sliceQp 当前切片的 SliceQPY
     * @return int 本宏块的 mb_qp_delta（CBP 为 0 时为 0）
     */
    public function decodeIntra4x4(int $mbX, int $mbY, int $sliceQp): int
    {
        // 亮度块预测模式（raster 索引 0..15）
        $modes = array_fill(0, 16, 0);
        // 本宏块内已解出的模式缓存，-1 表示尚未解码（此时需回退到跨宏块缓存或 DC_PRED）
        $modeCache = array_fill(0, 16, -1);
        // 扫描顺序 → raster 顺序映射：I_4x4 的 4x4 块按 8x8 分组的扫描序编码（6.4.3）
        $scanToRaster = [0, 1, 4, 5, 2, 3, 6, 7, 8, 9, 12, 13, 10, 11, 14, 15];

        // 逐块解码 16 个 4x4 亮度预测模式
        for ($scanIdx = 0; $scanIdx < 16; $scanIdx++) {
            // 换算到 raster 索引，再拆成 4x4 网格坐标 (blkX, blkY)
            $rasterIdx = $scanToRaster[$scanIdx];
            $blkX = $rasterIdx % 4;
            $blkY = (int)($rasterIdx / 4);

            // 获取左边块的预测模式
            $leftMode = -1;
            if ($blkX > 0) {
                $leftMode = $modeCache[$rasterIdx - 1];
            } elseif ($mbX > 0) {
                $leftMode = $this->intra4x4LeftModes[$blkY];
            }

            // 获取上面块的预测模式
            $topMode = -1;
            if ($blkY > 0) {
                $topMode = $modeCache[$rasterIdx - 4];
            } elseif ($mbY > 0) {
                $absBlkX = $mbX * 4 + $blkX;
                $topMode = $this->intra4x4TopModes[$absBlkX];
            }

            // 编码标准8.3.1.1节：如果任一邻居不可用(mode<0)，predicted=DC(2)
            // 否则 predicted=min(leftMode, topMode)
            $minMode = min($leftMode, $topMode);
            $predicted = ($minMode < 0) ? 2 : $minMode;
            // prev_intra4x4_pred_mode_flag：1 表示直接采用候选模式，0 表示还需读 rem 值
            $prevFlag = $this->reader->readU(1);
            if ($prevFlag) {
                $mode = $predicted;
            } else {
                // rem_intra4x4_pred_mode 为 3 bit 定长码；按 8.3.1.1 做「跳过候选值」的还原
                $remMode = $this->reader->readU(3);
                // 若 rem 大于等于候选模式，则实际模式为 rem+1（把候选模式从序号空间中剔除）
                $mode = $remMode >= $predicted ? $remMode + 1 : $remMode;
            }
            $modeCache[$rasterIdx] = $mode;
            $modes[$rasterIdx] = $mode;
        }

        // 更新跨宏块预测模式缓存
        // 左边列：右列块的模式 (rasterIdx 3,7,11,15)
        $this->intra4x4LeftModes[0] = $modes[3];
        $this->intra4x4LeftModes[1] = $modes[7];
        $this->intra4x4LeftModes[2] = $modes[11];
        $this->intra4x4LeftModes[3] = $modes[15];
        // 上边行：顶行块的模式 (rasterIdx 12,13,14,15)
        $baseLuma = $mbX * 4;
        $this->intra4x4TopModes[$baseLuma + 0] = $modes[12];
        $this->intra4x4TopModes[$baseLuma + 1] = $modes[13];
        $this->intra4x4TopModes[$baseLuma + 2] = $modes[14];
        $this->intra4x4TopModes[$baseLuma + 3] = $modes[15];

        // 色度预测模式：Table 8-4 的 0=DC、1=H、2=V、3=Plane（7.3.5.1 中为 ue(v)）
        $chromaPredMode = $this->reader->readUe();
        // coded_block_pattern：先读 ue(v) 码字，再经 9.1.2 的码字→CBP 映射表还原
        $cbpCode = $this->reader->readUe();
        $cbp = $this->golombToIntraCbp($cbpCode);
        // mb_qp_delta 仅在 CBP > 0 时读取（编码标准 7.4.5）
        $mbQpDelta = 0;
        if ($cbp > 0) {
            // se(v)：带符号指数哥伦布，可直接为负（QP 回绕依赖此点）
            $mbQpDelta = $this->reader->readSe();
        }
        // QPY 递推：上一宏块 QP + delta 后再按 52 回绕（8.5.8）
        $qp = $this->wrapQp($sliceQp + $mbQpDelta);
        // 亮度 4x4 系数（raster 顺序，每块 16 个），已含 zigzag 重排与反量化结果
        $yCoeffs = array_fill(0, 16, array_fill(0, 16, 0));
        // 色度系数：每分量 4 个 4x4 块，DC 与 AC 分两趟处理
        $cbCoeffs = array_fill(0, 4, array_fill(0, 16, 0));
        $crCoeffs = array_fill(0, 4, array_fill(0, 16, 0));

        // 亮度4x4残差 - I_4x4每个块有16个系数（DC+AC）
        // 按zigzag扫描顺序解码，每个块计算nC
        // nzCache：本宏块 24 个 4x4 块的「非零系数个数」，用于 nC 推导（9.2.1）
        $nzCache = array_fill(0, 24, 0);
        // leftNz：左邻宏块供本宏块使用的 nz 计数（亮度 4 个 + 色度 4 个）
        $leftNz = array_fill(0, 8, 0);
        // topNz：上邻宏块一行（亮度 4*mbWidth 个 + 色度两分量各 2*mbWidth 个）的 nz 计数
        $topNz = array_fill(0, $this->picWidthInMbs * 4 + $this->picWidthInMbs * 4, 0);
        // 图像边界处邻居不可用：此时不读取对应缓存，nC 只由可用一侧决定
        $leftAvailable = ($mbX > 0);
        $topAvailable = ($mbY > 0);

        // 从成员变量加载左边和上边的非零系数数
        if ($leftAvailable) {
            for ($y = 0; $y < 4; $y++) $leftNz[$y] = $this->nzLeftColLuma[$y];
            // leftNz布局：4=Cb上行右列(19), 5=Cb下行右列(17), 6=Cr上行右列(23), 7=Cr下行右列(21)
            $leftNz[4] = $this->nzLeftColChroma[0];  // Cb上行
            $leftNz[5] = $this->nzLeftColChroma[1];  // Cb下行
            $leftNz[6] = $this->nzLeftColChroma[2];  // Cr上行
            $leftNz[7] = $this->nzLeftColChroma[3];  // Cr下行
        }
        if ($topAvailable) {
            $baseLuma = $mbX * 4;
            for ($x = 0; $x < 4; $x++) $topNz[$baseLuma + $x] = $this->nzTopRowLuma[$baseLuma + $x];
            // 色度Cb：topNz索引16+mbX*2+x，nzTopRowChroma索引mbX*2+x
            // 块18需要上宏块的块16（左列），块19需要上宏块的块17（右列）
            for ($x = 0; $x < 2; $x++) $topNz[$this->picWidthInMbs * 4 + $mbX * 2 + $x] = $this->nzTopRowChroma[$mbX * 2 + $x];
            // 色度Cr：topNz索引24+mbX*2+x，nzTopRowChroma索引picWidthInMbs*2+mbX*2+x
            for ($x = 0; $x < 2; $x++) $topNz[$this->picWidthInMbs * 4 + $this->picWidthInMbs * 2 + $mbX * 2 + $x] = $this->nzTopRowChroma[$this->picWidthInMbs * 2 + $mbX * 2 + $x];
        }

        // SCAN_TO_RASTER映射：扫描顺序 -> 光栅顺序
        $scanToRaster = [0, 1, 4, 5, 2, 3, 6, 7, 8, 9, 12, 13, 10, 11, 14, 15];
        $lumaCbp = $cbp & 0x0F;

        // 按扫描顺序解码
        // i8x8: 8x8块索引(0-3), i4x4: 8x8块内的4x4子块索引(0-3)
        // lumaCbp 的 bit i8x8 = 1 表示该 8x8 区域含亮度系数（9.1.2）
        for ($i8x8 = 0; $i8x8 < 4; $i8x8++) {
            if (($lumaCbp & (1 << $i8x8)) !== 0) {
                for ($i4x4 = 0; $i4x4 < 4; $i4x4++) {
                    // 组合出扫描序号，再映射到 raster 索引
                    $scanIdx = $i8x8 * 4 + $i4x4;
                    $rasterIdx = $scanToRaster[$scanIdx];
                    // nC = 左右邻块与上邻块 nz 的加权平均（9.2.1）
                    $nc = $this->computeNc($rasterIdx, $mbX, $mbY, $nzCache, $leftNz, $topNz, $leftAvailable, $topAvailable);
                    // 亮度 4x4 全系数（DC+AC 共 16 个），CAVLC/CABAC 由内部按 PPS 选择
                    $coeffs = $this->decodeResidualBlock(16, $nc);
                    // 统计非零系数个数，写入 nzCache 供后续块与相邻宏块使用
                    $nzCount = 0;
                    for ($i = 0; $i < 16; $i++) if ($coeffs[$i] != 0) $nzCount++;
                    $nzCache[$rasterIdx] = $nzCount;
                    // zigzag重排+反量化融合为一步
                    $yCoeffs[$rasterIdx] = $this->dequantZigzag4x4($coeffs, 0, $qp);
                }
            } else {
                // 该 8x8 区域无系数：4 个子块 nz 全部置 0（供后续 nC 推导使用）
                for ($i4x4 = 0; $i4x4 < 4; $i4x4++) {
                    $scanIdx = $i8x8 * 4 + $i4x4;
                    $rasterIdx = $scanToRaster[$scanIdx];
                    $nzCache[$rasterIdx] = 0;
                }
            }
        }

        // 色度 QP 由 Table 8-15（QPC 映射表）给出：索引 QPY + chroma_qp_index_offset（7.4.5）
        $chromaQpIndex = max(0, min(51, $qp + $this->chromaQpIndexOffset));
        $chromaQp = self::CHROMA_QP_TABLE[$chromaQpIndex];
        // 色度 DC 系数缓冲（Cb/Cr 各 4 个 4x4 块，此处只存 DC 块）
        $cbDc = array_fill(0, 4, 0);
        $crDc = array_fill(0, 4, 0);

        // 色度CBP: 高2位 (0=无, 1=仅DC, 2=DC+AC)
        $chromaCbp = ($cbp >> 4) & 0x03;

        // 色度DC残差 - 使用nC=-1，对应coeffTokenMinus1 VLC表
        if ($chromaCbp > 0) {
            $cbDc = $this->decodeResidualBlock(4, -1);
            $crDc = $this->decodeResidualBlock(4, -1);
            // 注意：不初始化nzCache[16-23]为DC计数，totalCoeff[16-23]只存AC计数
        }

        // 色度AC残差 - 条件是chromaCbp >= 2
        // DC残差不进入IDCT，直接加到像素上；AC残差单独处理（coeffs[0]保持为0）
        if ($chromaCbp >= 2) {
            // Cb块空间布局：16 17 (上行), 18 19 (下行)
            // 按递增顺序解码：16,17,18,19
            $cbScanOrder = [16, 17, 18, 19];
            foreach ($cbScanOrder as $blockIdx) {
                $blk = $blockIdx - 16;
                $nc = $this->computeNc($blockIdx, $mbX, $mbY, $nzCache, $leftNz, $topNz, $leftAvailable, $topAvailable);
                $ac = $this->decodeResidualBlock(15, $nc);
                $nzCount = 0;
                for ($i = 0; $i < 15; $i++) if ($ac[$i] != 0) $nzCount++;
                $nzCache[$blockIdx] = $nzCount;
                // zigzag重排+反量化融合（coeffs[0]留空给DC）
                $cbCoeffs[$blk] = $this->dequantAcZigzag4x4($ac, 1, $chromaQp);
            }
            // Cr块空间布局：20 21 (上行), 22 23 (下行)
            // 按递增顺序解码：20,21,22,23
            $crScanOrder = [20, 21, 22, 23];
            foreach ($crScanOrder as $blockIdx) {
                $blk = $blockIdx - 20;
                $nc = $this->computeNc($blockIdx, $mbX, $mbY, $nzCache, $leftNz, $topNz, $leftAvailable, $topAvailable);
                $ac = $this->decodeResidualBlock(15, $nc);
                $nzCount = 0;
                for ($i = 0; $i < 15; $i++) if ($ac[$i] != 0) $nzCount++;
                $nzCache[$blockIdx] = $nzCount;
                // zigzag重排+反量化融合（coeffs[0]留空给DC）
                $crCoeffs[$blk] = $this->dequantAcZigzag4x4($ac, 2, $chromaQp);
            }
        }

        // IDCT4x4 逆变换亮度
        // 逐个 4x4 块调用逆整数变换（8.5.10），结果放入宏块级 16x16 像素缓冲
        $yPixels = array_fill(0, 16, array_fill(0, 16, 0));
        for ($blkY = 0; $blkY < 4; $blkY++) {
            for ($blkX = 0; $blkX < 4; $blkX++) {
                $blk = $blkY * 4 + $blkX;
                // idct4x4Flat 已完成「+32 偏置、>>6 归一化」并返回一维 4x4 残差
                $idct = $this->idct4x4Flat($yCoeffs[$blk]);
                // 把一维结果按 raster 顺序铺回 16x16 缓冲的对应位置
                for ($y = 0; $y < 4; $y++) for ($x = 0; $x < 4; $x++) $yPixels[$blkY * 4 + $y][$blkX * 4 + $x] = $idct[$y * 4 + $x];
            }
        }

        // 亮度预测+残差写入像素
        for ($blkY = 0; $blkY < 4; $blkY++) {
            for ($blkX = 0; $blkX < 4; $blkX++) {
                $blk = $blkY * 4 + $blkX;
                // 按 8.3.2 用本块已解出的预测模式生成 4x4 预测值
                $predicted = $this->intra4x4Prediction($mbX, $mbY, $blkX, $blkY, $modes[$blk]);

                for ($y = 0; $y < 4; $y++) {
                    for ($x = 0; $x < 4; $x++) {
                        // 换算到整帧亮度平面坐标（宏块 16x16 + 块内 4x4 偏移）
                        $py = $mbY * 16 + $blkY * 4 + $y;
                        $px = $mbX * 16 + $blkX * 4 + $x;
                        // 裁剪到可见区域：图像宽高不是 16 的整数倍时，右/下边缘宏块会越界
                        if ($py < $this->height && $px < $this->width) {
                            // 重建值 = 预测值 + 残差（8.5.12 之后的重建公式）
                            $val = $predicted[$y][$x] + $yPixels[$blkY * 4 + $y][$blkX * 4 + $x];
                            // 限幅到 8bit 像素范围 [0, 255]
                            $val = max(0, min(255, $val));
                            // 一维平面索引 = 行号 * 行宽 + 列号
                            $idx = $py * $this->width + $px;
                            $this->yPlane[$idx] = $val;
                        }
                    }
                }
            }
        }

        // 色度DC逆哈达玛 + AC IDCT
        //   - AC存在 (chromaCbp>=2): DC放入coeffs[0], 一次IDCT (单一+32偏置, >>6)
        //   - DC-only (chromaCbp==1): dc_add = (dc + 32) >> 6, 加到所有像素
        // uPixels/vPixels：8x8 色度残差缓冲（4:2:0 下单分量尺寸为亮度的 1/4）
        $uPixels = array_fill(0, 8, array_fill(0, 8, 0));
        $vPixels = array_fill(0, 8, array_fill(0, 8, 0));

        // 色度DC: 逆Hadamard + 反量化
        // qmul = dequant4Table[list_idx=1+plane_idx][chromaQp][0]
        // 输入为原始DC系数（不预反量化）
        $cbQmul = $this->dequant4Table[1][$chromaQp][0];
        $crQmul = $this->dequant4Table[2][$chromaQp][0];
        $cbDcResult = $this->chromaDcDequantIdct($cbDc, $cbQmul);
        $crDcResult = $this->chromaDcDequantIdct($crDc, $crQmul);

        for ($blkY = 0; $blkY < 2; $blkY++) {
            for ($blkX = 0; $blkX < 2; $blkX++) {
                // 色度块在宏块内的 raster 序号：blkY*2 + blkX，与 4x4 网格一一对应
                $blk = $blkY * 2 + $blkX;
                // DC残差（已完成反量化+逆哈达玛，但还需要+32>>6归一化）
                $dcResidualCb = $cbDcResult[$blk];
                $dcResidualCr = $crDcResult[$blk];

                if ($chromaCbp >= 2) {
                    // AC存在: 将DC放入coeffs[0], 与AC一起做IDCT
                    $blockCb = $cbCoeffs[$blk];
                    $blockCr = $crCoeffs[$blk];
                    // 将DC残差放入位置0
                    $blockCb[0] = $dcResidualCb;
                    $blockCr[0] = $dcResidualCr;
                    $acIdctCb = $this->idct4x4Flat($blockCb);
                    $acIdctCr = $this->idct4x4Flat($blockCr);

                    for ($y = 0; $y < 4; $y++) for ($x = 0; $x < 4; $x++) {
                        $uPixels[$blkY * 4 + $y][$blkX * 4 + $x] = $acIdctCb[$y * 4 + $x];
                        $vPixels[$blkY * 4 + $y][$blkX * 4 + $x] = $acIdctCr[$y * 4 + $x];
                    }
                } else {
                    // DC-only: 应用+32>>6归一化，加到所有像素
                    $dcAddCb = ($dcResidualCb + 32) >> 6;
                    $dcAddCr = ($dcResidualCr + 32) >> 6;
                    for ($y = 0; $y < 4; $y++) for ($x = 0; $x < 4; $x++) {
                        $uPixels[$blkY * 4 + $y][$blkX * 4 + $x] = $dcAddCb;
                        $vPixels[$blkY * 4 + $y][$blkX * 4 + $x] = $dcAddCr;
                    }
                }
            }
        }

        // 色度预测+残差写入
        // 按 8.3.4 用 chroma_pred_mode 生成 8x8 的 Cb/Cr 预测（两分量使用同一模式）
        $cbPredicted = $this->intraChromaPrediction($mbX, $mbY, $chromaPredMode, 0);
        $crPredicted = $this->intraChromaPrediction($mbX, $mbY, $chromaPredMode, 1);
        // 色度平面尺寸为亮度的一半（4:2:0）
        $chromaWidth = (int)($this->width / 2);
        $chromaHeight = (int)($this->height / 2);
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                // 色度坐标：宏块 8x8 + 块内偏移
                $py = $mbY * 8 + $y;
                $px = $mbX * 8 + $x;
                // 边界裁剪，避免右下边缘宏块写越界
                if ($py < $chromaHeight && $px < $chromaWidth) {
                    // 重建 = 预测 + 残差，随后限幅到 [0,255]
                    $valU = $cbPredicted[$y][$x] + $uPixels[$y][$x];
                    $valV = $crPredicted[$y][$x] + $vPixels[$y][$x];
                    $valU = max(0, min(255, $valU));
                    $valV = max(0, min(255, $valV));
                    $idx = $py * $chromaWidth + $px;
                    $this->uPlane[$idx] = $valU;
                    $this->vPlane[$idx] = $valV;
                }
            }
        }

        // 保存非零系数数供相邻宏块使用
        // 亮度raster布局：
        //  0  1  2  3
        //  4  5  6  7
        //  8  9 10 11
        // 12 13 14 15
        // 右列（供左宏块使用）：raster 3,7,11,15
        $this->nzLeftColLuma[0] = $nzCache[3];
        $this->nzLeftColLuma[1] = $nzCache[7];
        $this->nzLeftColLuma[2] = $nzCache[11];
        $this->nzLeftColLuma[3] = $nzCache[15];
        // 底行（供上宏块使用）：raster 12,13,14,15
        $baseLuma = $mbX * 4;
        $this->nzTopRowLuma[$baseLuma + 0] = $nzCache[12];
        $this->nzTopRowLuma[$baseLuma + 1] = $nzCache[13];
        $this->nzTopRowLuma[$baseLuma + 2] = $nzCache[14];
        $this->nzTopRowLuma[$baseLuma + 3] = $nzCache[15];

        // 色度Cb raster布局：
        // 16 17 (上行)
        // 18 19 (下行)
        // 右列：17,19 供左宏块使用
        $this->nzLeftColChroma[0] = $nzCache[17];  // Cb上行右列
        $this->nzLeftColChroma[1] = $nzCache[19];  // Cb下行右列
        // 底行：18,19 供上宏块使用
        $this->nzTopRowChroma[$mbX * 2 + 0] = $nzCache[18];
        $this->nzTopRowChroma[$mbX * 2 + 1] = $nzCache[19];

        // 色度Cr raster布局：
        // 20 21 (上行)
        // 22 23 (下行)
        $this->nzLeftColChroma[2] = $nzCache[21];  // Cr上行右列
        $this->nzLeftColChroma[3] = $nzCache[23];  // Cr下行右列
        $this->nzTopRowChroma[$this->picWidthInMbs * 2 + $mbX * 2 + 0] = $nzCache[22];
        $this->nzTopRowChroma[$this->picWidthInMbs * 2 + $mbX * 2 + 1] = $nzCache[23];
        return $mbQpDelta;
    }

    /**
     * Intra16x16 解码
     * @return int mb_qp_delta
     *
     * 处理 I_16x16 宏块（mb_type 1..24）。mb_type 同时承载三个信息（Table 7-11）：
     *   - Intra16x16PredMode = (mb_type-1) % 4，0=Intra_16x16_Vertical、
     *     1=Horizontal、2=DC、3=Plane（8.3.3 的四种预测）；
     *   - CodedBlockPatternChroma = intdiv(mb_type-1-base, 4)，取值 0/1/2；
     *   - CodedBlockPatternLuma：mb_type<=12 时为 0，否则为 15（16 个 4x4 块全含 AC）。
     *
     * 残差解码顺序遵循 7.3.5.2：亮度 DC(4x4 的 16 个 DC) → 亮度 AC → 色度 DC → 色度 AC；
     * I_16x16 无论 CBP 是否为 0 都必须读取 mb_qp_delta（7.4.5.2）。
     *
     * @param int $mbX 宏块列号
     * @param int $mbY 宏块行号
     * @param int $mbType I 切片中的 mb_type，取值 1..24
     * @param int $qp 当前切片的 SliceQPY（参数名为 $qp，语义上是切片级 QP）
     * @return int 本宏块的 mb_qp_delta
     */
    public function decodeIntra16x16(int $mbX, int $mbY, int $mbType, int $qp): int
    {
        // 编码标准Table 7-11 (I帧) / Table 7-10 (P帧Intra mb_type-5):
        // mbType 1-24: Intra16x16
        // predMode顺序: 0(V),1(H),2(DC),3(Plane) 每4个一循环
        // cbp分组: 每4个一组
        //   组0 (1-4):  Luma=0,  Chroma=0
        //   组1 (5-8):  Luma=0,  Chroma=1
        //   组2 (9-12): Luma=0,  Chroma=2
        //   组3 (13-16):Luma=15, Chroma=0
        //   组4 (17-20):Luma=15, Chroma=1
        //   组5 (21-24):Luma=15, Chroma=2

        // 由 mb_type 还原预测模式与亮度/色度 CBP（Table 7-11 的逆映射）
        $predMode = ($mbType - 1) % 4;
        // mb_type >= 13 时亮度 CBP=15（36 个 AC 系数），否则亮度只传 DC
        $hasLumaAc = ($mbType - 1) >= 12;
        $base = $hasLumaAc ? 12 : 0;
        // 色度 CBP：以 4 为步长递增，取值 0/1/2
        $cbpChroma = intdiv(($mbType - 1 - $base), 4);
        $cbpLuma = $hasLumaAc ? 15 : 0;


        // I_16x16宏块总是编码chroma_pred_mode（参考C语言编码器cavlc_mb_header_i）
        // chroma = CHROMA_FORMAT == CHROMA_420 || CHROMA_FORMAT == CHROMA_422，对于YUV420总是true
        // 编码顺序: mb_type -> chroma_pred_mode -> mb_qp_delta -> 亮度DC系数
        $chromaPredMode = $this->reader->readUe();
        if ($chromaPredMode > 3) {
            // 越界值（4..）不符合 Table 8-4，此处实现回退到 DC 模式，保证解码继续
            $chromaPredMode = 0; // 回退到DC模式
        }

        // mb_qp_delta - I_16x16无论cbp如何都要读取（编码标准 7.4.5.2）
        $mbQpDelta = $this->reader->readSe();
        // QPY 递推：切片 QP + delta 后按 52 回绕
        $qp = $this->wrapQp($qp + $mbQpDelta);

        // 亮度DC系数
        // 编码顺序：亮度 DC → 亮度 AC → 色度 DC → 色度 AC
        $nzCache = array_fill(0, 24, 0);
        $leftNz = array_fill(0, 8, 0);
        $topNz = array_fill(0, $this->picWidthInMbs * 4 + $this->picWidthInMbs * 4, 0);
        $leftAvailable = ($mbX > 0);
        $topAvailable = ($mbY > 0);

        // 从成员变量加载左边和上边的非零系数数
        if ($leftAvailable) {
            for ($y = 0; $y < 4; $y++) $leftNz[$y] = $this->nzLeftColLuma[$y];
            // leftNz布局：4=Cb上行右列(19), 5=Cb下行右列(17), 6=Cr上行右列(23), 7=Cr下行右列(21)
            $leftNz[4] = $this->nzLeftColChroma[0];  // Cb上行
            $leftNz[5] = $this->nzLeftColChroma[1];  // Cb下行
            $leftNz[6] = $this->nzLeftColChroma[2];  // Cr上行
            $leftNz[7] = $this->nzLeftColChroma[3];  // Cr下行
        }
        if ($topAvailable) {
            $baseLuma = $mbX * 4;
            for ($x = 0; $x < 4; $x++) $topNz[$baseLuma + $x] = $this->nzTopRowLuma[$baseLuma + $x];
            // 色度Cb：topNz索引16+mbX*2+x，nzTopRowChroma索引mbX*2+x
            // 块18需要上宏块的块16（左列），块19需要上宏块的块17（右列）
            for ($x = 0; $x < 2; $x++) $topNz[$this->picWidthInMbs * 4 + $mbX * 2 + $x] = $this->nzTopRowChroma[$mbX * 2 + $x];
            // 色度Cr：topNz索引24+mbX*2+x，nzTopRowChroma索引picWidthInMbs*2+mbX*2+x
            for ($x = 0; $x < 2; $x++) $topNz[$this->picWidthInMbs * 4 + $this->picWidthInMbs * 2 + $mbX * 2 + $x] = $this->nzTopRowChroma[$this->picWidthInMbs * 2 + $mbX * 2 + $x];
        }

        // Intra16x16总是解码亮度DC
        // 不论cbpLuma是否为0，DC系数总是存在
        $predNz = 0;
        $count = 0;
        // 亮度 DC 的 nC（9.2.1）：取左邻块 0 与上邻块（本宏块首列对应的 4x4 块）的 nz 平均
        if ($leftAvailable) {
            $predNz += $leftNz[0];
            $count++;
        }
        if ($topAvailable) {
            // 上邻宏块中正对本宏块首列的 4x4 块：绝对列号 mbX*4
            $ax = $mbX * 4;
            if ($ax < count($topNz)) {
                $predNz += $topNz[$ax];
                $count++;
            }
        }
        if ($count === 0) {
            // 左右上均不可用（图像左上角）：nC 取 0
            $yDcNc = 0;
        } else {
            // 四舍五入取平均：intdiv(sum + count/2, count)
            $avgNz = intdiv($predNz + intdiv($count, 2), $count);
            // 亮度 DC 的 nC 上限为 16（最大 DC 个数）
            $yDcNc = min($avgNz, 16);
        }
        // 下列两行仅在特定宏块位置打开残差调试开关（调试辅助，不影响重建结果）
        if ($mbX === 0 && $mbY === 0) $this->debugResidual = true;
        if ($mbX === 3 && $mbY === 0) $this->debugResidual = true;
        // 读取 16 个亮度 DC 系数（只有 DC，不带 AC）
        $yDcZigzag = $this->decodeResidualBlock(16, $yDcNc);
        $this->debugResidual = false;
        // decodeResidualBlock返回zig-zag顺序，需先转为raster顺序
        // raster顺序在宏块DC语境下对应4x4矩阵: row=block_row, col=block_col
        // 这是Hadamard变换所需的输入顺序
        $yDcRaster = array_fill(0, 16, 0);
        for ($i = 0; $i < 16; $i++) {
            // 用 4x4 zigzag 表做逆映射：raster 位置 = ZIGZAG_SCAN_4X4[扫描序号]
            $yDcRaster[self::ZIGZAG_SCAN_4X4[$i]] = $yDcZigzag[$i];
        }
        // 反量化表仅覆盖 0..51；QP 已被 wrapQp 归一化，此处理论上不会越界，保留夹取以防万一
        $qpClamped = max(0, min(51, $qp));

        // 亮度DC: 逆Hadamard + 反量化
        // qmul = dequant4Table[list_idx=0][qp][0]
        // 输入为raster顺序的原始DC系数（不预反量化），输出为raster顺序的反量化值
        // 8.5.10.2：16 个 DC 先做 4x4 逆 Hadamard，再统一乘反量化因子
        $lumaQmul = $this->dequant4Table[0][$qpClamped][0];
        $yDcResultBlockOrder = $this->lumaDcDequantIdct($yDcRaster, $lumaQmul);

        // 亮度 AC 系数缓冲（16 个 4x4 块 × 16 个位置，位置 0 预留给 DC）
        $yAcCoeffs = array_fill(0, 16, array_fill(0, 16, 0));
        // 码流块序号（扫描序）→ raster 块索引
        $blockIndexToRaster = [0, 1, 4, 5, 2, 3, 6, 7, 8, 9, 12, 13, 10, 11, 14, 15];

        // 亮度AC残差 - 按blockIndex递增顺序解码，每个块计算nC
        // AC系数单独处理，DC残差不进入IDCT，直接加到像素上
        if ($cbpLuma !== 0) {
            for ($blkIdx = 0; $blkIdx < 16; $blkIdx++) {
                $rasterIdx = $blockIndexToRaster[$blkIdx];
                $nc = $this->computeNc($rasterIdx, $mbX, $mbY, $nzCache, $leftNz, $topNz, $leftAvailable, $topAvailable);
                $ac = $this->decodeResidualBlock(15, $nc);
                // AC-only count（totalCoeff[blockIndex]只存AC计数，不包括DC）
                $nzCount = 0;
                for ($i = 0; $i < 15; $i++) if ($ac[$i] != 0) $nzCount++;
                $nzCache[$rasterIdx] = $nzCount;
                // zigzag重排+反量化融合（coeffs[0]留空给DC）
                $yAcCoeffs[$rasterIdx] = $this->dequantAcZigzag4x4($ac, 0, $qp);
            }
        }

        // 色度 QP 映射：QPC = Table8-15[min(51, QPY + chroma_qp_index_offset)]
        $chromaQpIndex = max(0, min(51, $qp + $this->chromaQpIndexOffset));
        $chromaQp = self::CHROMA_QP_TABLE[$chromaQpIndex];

        // 色度 DC 系数（每分量 4 个块的 DC）
        $cbDc = array_fill(0, 4, 0);
        $crDc = array_fill(0, 4, 0);

        // 色度 DC 只要 CBP(chroma) != 0 就存在（0=无、1=仅DC、2=DC+AC）
        if ($cbpChroma != 0) {
            // 色度 DC 使用 nC=-1（由 decodeResidualBlock 内部对应到 4:2:0 DC 的固定 VLC 表）
            $cbDc = $this->decodeResidualBlock(4, -1);
            $crDc = $this->decodeResidualBlock(4, -1);
        }

        // 色度DC: 逆Hadamard + 反量化
        // qmul = dequant4Table[list_idx=1+plane_idx][chromaQp][0]
        // 输入为原始DC系数（不预反量化），输出为raster顺序的反量化值
        // Cb用list_idx=1, Cr用list_idx=2 (都是intra scaling matrix)
        $cbQmul = $this->dequant4Table[1][$chromaQp][0];
        $crQmul = $this->dequant4Table[2][$chromaQp][0];
        $cbDcResult = $this->chromaDcDequantIdct($cbDc, $cbQmul);
        $crDcResult = $this->chromaDcDequantIdct($crDc, $crQmul);

        $cbAcCoeffs = array_fill(0, 4, array_fill(0, 16, 0));
        $crAcCoeffs = array_fill(0, 4, array_fill(0, 16, 0));

        // 色度AC残差 - 条件是cbpChroma >= 2
        // DC残差不进入IDCT，直接加到像素上；AC残差单独处理（coeffs[0]保持为0）
        if ($cbpChroma >= 2) {
            // Cb块空间布局
            //   16 17  (上行)
            //   18 19  (下行)
            // 按递增顺序解码：16,17,18,19
            $cbScanOrder = [16, 17, 18, 19];
            foreach ($cbScanOrder as $blockIdx) {
                $blk = $blockIdx - 16;
                $nc = $this->computeNc($blockIdx, $mbX, $mbY, $nzCache, $leftNz, $topNz, $leftAvailable, $topAvailable);
                $ac = $this->decodeResidualBlock(15, $nc);
                $nzCnt = 0; for ($i = 0; $i < 15; $i++) if ($ac[$i] != 0) $nzCnt++;
                $nzCache[$blockIdx] = $nzCnt;
                // zigzag重排+反量化融合（coeffs[0]留空给DC）
                $cbAcCoeffs[$blk] = $this->dequantAcZigzag4x4($ac, 1, $chromaQp);
            }
            // Cr块空间布局（与Cb相同）：
            //   20 21  (上行)
            //   22 23  (下行)
            // 按递增顺序解码：20,21,22,23
            $crScanOrder = [20, 21, 22, 23];
            foreach ($crScanOrder as $blockIdx) {
                $blk = $blockIdx - 20;
                $nc = $this->computeNc($blockIdx, $mbX, $mbY, $nzCache, $leftNz, $topNz, $leftAvailable, $topAvailable);
                $ac = $this->decodeResidualBlock(15, $nc);
                $nzCnt = 0; for ($i = 0; $i < 15; $i++) if ($ac[$i] != 0) $nzCnt++;
                $nzCache[$blockIdx] = $nzCnt;
                // zigzag重排+反量化融合（coeffs[0]留空给DC）
                $crAcCoeffs[$blk] = $this->dequantAcZigzag4x4($ac, 2, $chromaQp);
            }
        }

        // 亮度预测 + 残差
        // decode_intra16x16:
        //   - AC存在: DC放入coeffs[0], 一次IDCT (单一+32偏置, >>6)
        //   - DC-only: dc_add = (dc + 32) >> 6, 加到所有像素
        $lumaPred = $this->intra16x16Prediction($mbX, $mbY, $predMode);
        // 逐 4x4 块叠加：有 AC 时 DC 与 AC 合并做一次 IDCT；仅 DC 时直接加 (dc+32)>>6
        for ($blkY = 0; $blkY < 4; $blkY++) {
            for ($blkX = 0; $blkX < 4; $blkX++) {
                $blk = $blkY * 4 + $blkX;

                // DC残差（已完成反量化+逆哈达玛，但还需要+32>>6归一化）
                $dcResidual = $yDcResultBlockOrder[$blk];

                if ($cbpLuma !== 0) {
                    // AC存在: 将DC放入coeffs[0], 与AC一起做IDCT
                    // (匹配Rust: 单一+32偏置, 避免DC和AC分别IDCT导致的双重偏置)
                    $acBlock = $yAcCoeffs[$blk];
                    // 将DC残差放入位置0 (idct4x4Flat会对位置0加+32偏置)
                    $acBlock[0] = $dcResidual;
                    $acIdct = $this->idct4x4Flat($acBlock);

                    for ($y = 0; $y < 4; $y++) {
                        for ($x = 0; $x < 4; $x++) {
                            $py = $mbY * 16 + $blkY * 4 + $y;
                            $px = $mbX * 16 + $blkX * 4 + $x;
                            if ($py < $this->height && $px < $this->width) {
                                $val = $lumaPred[$blkY * 4 + $y][$blkX * 4 + $x] + $acIdct[$y * 4 + $x];
                                $val = max(0, min(255, $val));
                                $idx = $py * $this->width + $px;
                                $this->yPlane[$idx] = $val;
                            }
                        }
                    }
                } else {
                    // DC-only: 应用+32>>6归一化，加到所有像素
                    $dcAdd = ($dcResidual + 32) >> 6;
                    for ($y = 0; $y < 4; $y++) {
                        for ($x = 0; $x < 4; $x++) {
                            $py = $mbY * 16 + $blkY * 4 + $y;
                            $px = $mbX * 16 + $blkX * 4 + $x;
                            if ($py < $this->height && $px < $this->width) {
                                $val = $lumaPred[$blkY * 4 + $y][$blkX * 4 + $x] + $dcAdd;
                                $val = max(0, min(255, $val));
                                $idx = $py * $this->width + $px;
                                $this->yPlane[$idx] = $val;
                            }
                        }
                    }
                }
            }
        }

        // 色度预测+残差
        // decode_chroma:
        //   - AC存在 (cbpChroma>=2): DC放入coeffs[0], 一次IDCT (单一+32偏置, >>6)
        //   - DC-only (cbpChroma==1): dc_add = (dc + 32) >> 6, 加到所有像素
        $cbPred = $this->intraChromaPrediction($mbX, $mbY, $chromaPredMode, 0);
        $crPred = $this->intraChromaPrediction($mbX, $mbY, $chromaPredMode, 1);
        // 色度平面宽高（4:2:0 下为亮度的一半）
        $cw = (int)($this->width / 2);
        $ch = (int)($this->height / 2);
        for ($blkY = 0; $blkY < 2; $blkY++) {
            for ($blkX = 0; $blkX < 2; $blkX++) {
                $blk = $blkY * 2 + $blkX;

                // DC残差（已完成反量化+逆哈达玛，但还需要+32>>6归一化）
                $cbDcResidual = $cbDcResult[$blk];
                $crDcResidual = $crDcResult[$blk];

                if ($cbpChroma >= 2) {
                    // AC存在: 将DC放入coeffs[0], 与AC一起做IDCT
                    $cbAcBlock = $cbAcCoeffs[$blk];
                    $crAcBlock = $crAcCoeffs[$blk];
                    // 将DC残差放入位置0
                    $cbAcBlock[0] = $cbDcResidual;
                    $crAcBlock[0] = $crDcResidual;
                    $acIdctCb = $this->idct4x4Flat($cbAcBlock);
                    $acIdctCr = $this->idct4x4Flat($crAcBlock);

                    for ($y = 0; $y < 4; $y++) {
                        for ($x = 0; $x < 4; $x++) {
                            $py = $mbY * 8 + $blkY * 4 + $y;
                            $px = $mbX * 8 + $blkX * 4 + $x;
                            if ($py < $ch && $px < $cw) {
                                $vu = $cbPred[$blkY * 4 + $y][$blkX * 4 + $x] + $acIdctCb[$y * 4 + $x];
                                $vv = $crPred[$blkY * 4 + $y][$blkX * 4 + $x] + $acIdctCr[$y * 4 + $x];
                                $idx = $py * $cw + $px;
                                $this->uPlane[$idx] = max(0, min(255, $vu));
                                $this->vPlane[$idx] = max(0, min(255, $vv));
                            }
                        }
                    }
                } elseif ($cbpChroma == 1) {
                    // DC-only: 应用+32>>6归一化，加到所有像素
                    $cbDcAdd = ($cbDcResidual + 32) >> 6;
                    $crDcAdd = ($crDcResidual + 32) >> 6;
                    for ($y = 0; $y < 4; $y++) {
                        for ($x = 0; $x < 4; $x++) {
                            $py = $mbY * 8 + $blkY * 4 + $y;
                            $px = $mbX * 8 + $blkX * 4 + $x;
                            if ($py < $ch && $px < $cw) {
                                $vu = $cbPred[$blkY * 4 + $y][$blkX * 4 + $x] + $cbDcAdd;
                                $vv = $crPred[$blkY * 4 + $y][$blkX * 4 + $x] + $crDcAdd;
                                $idx = $py * $cw + $px;
                                $this->uPlane[$idx] = max(0, min(255, $vu));
                                $this->vPlane[$idx] = max(0, min(255, $vv));
                            }
                        }
                    }
                } else {
                    // cbpChroma == 0: 没有残差，直接写入预测值
                    for ($y = 0; $y < 4; $y++) {
                        for ($x = 0; $x < 4; $x++) {
                            $py = $mbY * 8 + $blkY * 4 + $y;
                            $px = $mbX * 8 + $blkX * 4 + $x;
                            if ($py < $ch && $px < $cw) {
                                $idx = $py * $cw + $px;
                                $this->uPlane[$idx] = $cbPred[$blkY * 4 + $y][$blkX * 4 + $x];
                                $this->vPlane[$idx] = $crPred[$blkY * 4 + $y][$blkX * 4 + $x];
                            }
                        }
                    }
                }
            }
        }

        // 保存非零系数数供相邻宏块使用
        // 亮度raster布局：
        //  0  1  2  3
        //  4  5  6  7
        //  8  9 10 11
        // 12 13 14 15
        // 右列（供左宏块使用）：raster 3,7,11,15
        $this->nzLeftColLuma[0] = $nzCache[3];
        $this->nzLeftColLuma[1] = $nzCache[7];
        $this->nzLeftColLuma[2] = $nzCache[11];
        $this->nzLeftColLuma[3] = $nzCache[15];
        // 底行（供上宏块使用）：raster 12,13,14,15
        $baseLuma = $mbX * 4;
        $this->nzTopRowLuma[$baseLuma + 0] = $nzCache[12];
        $this->nzTopRowLuma[$baseLuma + 1] = $nzCache[13];
        $this->nzTopRowLuma[$baseLuma + 2] = $nzCache[14];
        $this->nzTopRowLuma[$baseLuma + 3] = $nzCache[15];

        // 色度Cb raster布局：
        // 16 17 (上行)
        // 18 19 (下行)
        // 右列：17,19 供左宏块使用
        $this->nzLeftColChroma[0] = $nzCache[17];  // Cb上行右列
        $this->nzLeftColChroma[1] = $nzCache[19];  // Cb下行右列
        // 底行：18,19 供上宏块使用
        $this->nzTopRowChroma[$mbX * 2 + 0] = $nzCache[18];
        $this->nzTopRowChroma[$mbX * 2 + 1] = $nzCache[19];

        // 色度Cr raster布局：
        // 20 21 (上行)
        // 22 23 (下行)
        $this->nzLeftColChroma[2] = $nzCache[21];  // Cr上行右列
        $this->nzLeftColChroma[3] = $nzCache[23];  // Cr下行右列
        $this->nzTopRowChroma[$this->picWidthInMbs * 2 + $mbX * 2 + 0] = $nzCache[22];
        $this->nzTopRowChroma[$this->picWidthInMbs * 2 + $mbX * 2 + 1] = $nzCache[23];

        // Intra16x16宏块传递DC_PRED(2)给相邻宏块
        // spec 8.3.1.1: 当邻居不是Intra_4x4时，其预测模式推断为DC_PRED(2)
        // 存储2而不是-1的原因：如果存储-1，当左边是-1但上边是有效模式(如1)时，
        // min(-1, 1) = -1会导致predicted=DC_PRED(2)，但正确行为应该是min(2, 1) = 1
        $this->intra4x4LeftModes = array_fill(0, 4, 2);
        $baseLuma = $mbX * 4;
        $this->intra4x4TopModes[$baseLuma + 0] = 2;
        $this->intra4x4TopModes[$baseLuma + 1] = 2;
        $this->intra4x4TopModes[$baseLuma + 2] = 2;
        $this->intra4x4TopModes[$baseLuma + 3] = 2;

        return $mbQpDelta;
    }

    // ---------------------- 工具函数 ----------------------
    /**
     * CBP 映射表
     *
     * 把码流中 coded_block_pattern 的 ue(v) 码字（code）映射为实际的 CBP 值
     * （H.264 Table 9-4 的码字→coded_block_pattern 关系）。
     * CBP 低 4 位对应亮度 4 个 8x8 区域，高 2 位对应色度的 DC/AC 存在性（9.1.2）。
     *
     * @param int $code coded_block_pattern 的码字（ue(v) 解出的值）
     * @return int 对应的 CBP；超出表长时此处实现返回 0
     */
    public function golombToIntraCbp(int $code): int
    {
        // Table 9-4 的映射表，下标为码字
        $table = [47,31,15,0,23,27,29,30,7,11,13,14,39,43,45,46,16,3,5,10,12,19,21,26,28,35,
            37,42,44,1,2,4,8,17,18,20,24,6,9,22,25,32,33,34,36,40,38,41];
        // 码字越界（不大于表长 48）属于非规范流，此处实现按 0 处理
        return $code < count($table) ? $table[$code] : 0;
    }

    /**
     * 填充宏块为中性灰（P/B帧无运动补偿）
     *
     * 用于无法正常重建的宏块（不支持的 slice_type、错误的 mb_type 等）：
     * 亮度与两个色度分量全部填 128（8bit 的中性灰），保证输出图像尺寸正确、
     * 后续宏块的预测读取不会读到未初始化数据。
     * 同时把该宏块视为「非 Intra_4x4」，向相邻宏块传播 DC_PRED(2)（8.3.1.1）。
     *
     * @param int $mbX 宏块列号
     * @param int $mbY 宏块行号
     * @return void
     */
    public function fillMacroblockGray(int $mbX, int $mbY): void
    {
        // 亮度16x16
        // 宏块左上角在亮度平面中的坐标
        $py0 = $mbY * 16;
        $px0 = $mbX * 16;
        for ($y = 0; $y < 16; $y++) {
            $py = $py0 + $y;
            // 行越界即停止（图像高度不是 16 的整数倍时的底部边缘）
            if ($py >= $this->height) break;
            // 行起始索引，避免在内层循环里重复乘法
            $baseIdx = $py * $this->width;
            for ($x = 0; $x < 16; $x++) {
                $px = $px0 + $x;
                // 列越界即停止本行（右边缘宏块）
                if ($px >= $this->width) break;
                $this->yPlane[$baseIdx + $px] = 128;
            }
        }
        // 色度8x8
        // 4:2:0 下色度平面宽高各为亮度的一半
        $cw = (int)($this->width / 2);
        $ch = (int)($this->height / 2);
        $cy0 = $mbY * 8;
        $cx0 = $mbX * 8;
        for ($y = 0; $y < 8; $y++) {
            $cy = $cy0 + $y;
            if ($cy >= $ch) break;
            $baseIdx = $cy * $cw;
            for ($x = 0; $x < 8; $x++) {
                $cx = $cx0 + $x;
                if ($cx >= $cw) break;
                // Cb 与 Cr 同时填充
                $this->uPlane[$baseIdx + $cx] = 128;
                $this->vPlane[$baseIdx + $cx] = 128;
            }
        }
        // 非I帧宏块传递DC_PRED(2)给相邻宏块
        // 与 I_PCM/I_16x16 相同的规则：非 Intra_4x4 邻居的模式按 DC_PRED(2) 推断
        $this->intra4x4LeftModes = array_fill(0, 4, 2);
        $baseLuma = $mbX * 4;
        $this->intra4x4TopModes[$baseLuma + 0] = 2;
        $this->intra4x4TopModes[$baseLuma + 1] = 2;
        $this->intra4x4TopModes[$baseLuma + 2] = 2;
        $this->intra4x4TopModes[$baseLuma + 3] = 2;
    }

    /**
     * 写入亮度像素
     *
     * 以「宏块坐标 + 宏块内相对坐标」写入亮度平面，自动做边界裁剪与 [0,255] 限幅。
     * 下标换算：像素绝对坐标 = 宏块坐标*16 + 相对坐标；平面索引 = py*width + px。
     *
     * @param int $mbX 宏块列号
     * @param int $mbY 宏块行号
     * @param int $x 宏块内水平偏移（0..15）
     * @param int $y 宏块内垂直偏移（0..15）
     * @param int $val 待写入的像素值，会限幅到 [0,255]
     * @return void
     */
    public function writeLumaPixel(int $mbX, int $mbY, int $x, int $y, int $val): void
    {
        // 宏块内偏移换算为平面绝对坐标
        $px = $mbX * 16 + $x;
        $py = $mbY * 16 + $y;
        // 越界直接丢弃（I_PCM 读到的边缘多余像素）
        if ($px >= $this->width || $py >= $this->height) return;
        $idx = $py * $this->width + $px;
        // 8bit 限幅
        $this->yPlane[$idx] = max(0, min(255, $val));
    }

    /**
     * 写入色度像素
     *
     * 与 writeLumaPixel 类似，但作用于 4:2:0 的色度平面：宏块级步长为 8x8，
     * 平面宽高为亮度的 1/2。$uv 选择 Cb(0) 或 Cr(1)。
     *
     * @param int $mbX 宏块列号
     * @param int $mbY 宏块行号
     * @param int $x 宏块内水平偏移（0..7）
     * @param int $y 宏块内垂直偏移（0..7）
     * @param int $val 待写入的像素值
     * @param int $uv 0=U 1=V
     * @param int $uv 0=U(Cb) 1=V(Cr)
     * @return void
     */
    public function writeChromaPixel(int $mbX, int $mbY, int $x, int $y, int $val, int $uv): void
    {
        // 色度平面尺寸
        $cw = (int)($this->width / 2);
        $ch = (int)($this->height / 2);
        // 色度宏块步长为 8
        $px = $mbX * 8 + $x;
        $py = $mbY * 8 + $y;
        // 边界裁剪
        if ($px >= $cw || $py >= $ch) return;
        $idx = $py * $cw + $px;
        $val = max(0, min(255, $val));
        // 按分量选择目标平面
        if ($uv === 0) {
            $this->uPlane[$idx] = $val;
        } else {
            $this->vPlane[$idx] = $val;
        }
    }

    // ====================== P帧宏块解码 ======================
    // P 切片的帧间宏块只使用 List0；本段负责按 mb_type 分派到 16x16 / 16x8 / 8x16 /
    // 8x8 / 8x8ref0 五条路径，以及 P 切片中夹带的 Intra 宏块（mb_type 5..30、31）。
    // 所有路径最终都要：做运动补偿 → 保存 MV/参考索引供 MV 预测（8.4.1.3）→
    // 更新 nz 计数与 intra 模式缓存 → 需要时写去块簿记（8.7）。

    /**
     * P帧宏块解码
     * P-slice mb_type (CAVLC, Table 7-11):
     *   0 = P_L0_16x16
     *   1 = P_L0_L0_16x8
     *   2 = P_L0_L0_8x16
     *   3 = P_8x8
     *   4 = P_8x8ref0
     *   5..30 = Intra (intraMbType = mb_type - 5; I-slice mb_type 0=I4x4, 1-24=I16x16)
     *   31 = I_PCM
     * P_Skip宏块由mb_skip_run计数处理，不在macroblock_layer()中出现。
     *
     * @param int $mbX 宏块列号
     * @param int $mbY 宏块行号
     * @param int $mbType P 切片的 mb_type（Table 7-13）
     * @param int $sliceQp 当前切片的 SliceQPY
     * @return int mb_qp_delta（帧间分区为 0，仅夹带 Intra 宏块时非 0）
     */
    private function decodePInterMacroblock(int $mbX, int $mbY, int $mbType, int $sliceQp): int
    {
        // P_L0_16x16：整宏块一个 16x16 分区、一个 MV
        if ($mbType === 0) {
            return $this->decodePL0_16x16($mbX, $mbY, $sliceQp);
        }

        // P_L0_L0_16x8：上下两个 16x8 分区，各自携带 MV 与 ref_idx
        if ($mbType === 1) {
            return $this->decodePL0_16x8($mbX, $mbY, $sliceQp);
        }

        // P_L0_L0_8x16：左右两个 8x16 分区
        if ($mbType === 2) {
            return $this->decodePL0_8x16($mbX, $mbY, $sliceQp);
        }

        // P_8x8：4 个 8x8 子宏块，每个再细分（sub_mb_type）
        if ($mbType === 3) {
            return $this->decodeP_8x8($mbX, $mbY, $sliceQp);
        }

        // P_8x8ref0：4 个 8x8 子宏块且所有参考索引隐含为 0（不读 ref_idx 语法）
        if ($mbType === 4) {
            return $this->decodeP_8x8ref0($mbX, $mbY, $sliceQp);
        }

        // P 切片中的 Intra 宏块：mb_type-5 才是 I 切片的 mb_type（Table 7-13）
        if ($mbType >= 5 && $mbType <= 30) {
            $intraMbType = $mbType - 5;
            $mbQpDelta = $this->decodeIntraMacroblock($mbX, $mbY, $intraMbType, $sliceQp);
            // 帧内宏块不参与帧间 MV 预测：MV 清零、参考索引置 -1 表示「不可用」
            $intraMv = [0, 0, -1];
            // 左边/上边 4x4 的 MV 缓存同样写成不可用，避免邻居把 intra 块当帧间块取 MV
            $this->mvLeftCol = [$intraMv, $intraMv, $intraMv, $intraMv];
            $this->mvTopRow[$mbX * 4 + 0] = $intraMv;
            $this->mvTopRow[$mbX * 4 + 1] = $intraMv;
            $this->mvTopRow[$mbX * 4 + 2] = $intraMv;
            $this->mvTopRow[$mbX * 4 + 3] = $intraMv;
            return $mbQpDelta;
        }

        // 31 = I_PCM：此处实现不解析 PCM 采样，用灰色填充兜底
        if ($mbType === 31) {
            $this->fillMacroblockGray($mbX, $mbY);
            return 0;
        }

        // 越界 mb_type：灰色填充，保证后续宏块位置对齐
        $this->fillMacroblockGray($mbX, $mbY);
        return 0;
    }

    /**
     * P_Skip 宏块解码
     * 运动向量为预测值，无残差
     *
     * 依据 8.4.1.1，P_Skip 宏块等价于 P_L0_16x16 且 ref_idx_l0 = 0、MV 等于预测 MV、
     * CBP 为 0（无残差、无 mb_qp_delta）。P_Skip 本身不在 macroblock_layer() 中出现，
     * 而是由 mb_skip_run 计数后由外部调用本方法。
     *
     * @param int $mbX 宏块列号
     * @param int $mbY 宏块行号
     * @return int 恒为 0（无 mb_qp_delta）
     */
    private function decodePSkip(int $mbX, int $mbY): int
    {
        // P_Skip 固定使用 List0 的第 0 个参考帧
        $refIdx = 0;
        // 取 P_Skip 专用 MV 预测（8.4.1.1：左/上/右上邻居 MV 的中值，并处理不可用邻居）
        list($predMvX, $predMvY) = $this->getPSkipMvPrediction($mbX, $mbY);

        $mvX = $predMvX;
        $mvY = $predMvY;

        // 16x16 运动补偿（含亚像素插值）+ 直接写像素
        $this->performMotionCompensation16x16($mbX, $mbY, $mvX, $mvY, $refIdx);

        // 把该 MV 写入左列/上行缓存，供后续宏块做 MV 预测
        $this->saveMvForPrediction($mbX, $mbY, $mvX, $mvY, $refIdx);

        // 无残差：nz 计数全部清零（影响后续 nC 推导与去块滤波 bS 判定）
        $this->updateNzCountZero($mbX, $mbY);

        // 非 Intra_4x4 邻居的预测模式传播 DC_PRED(2)
        $this->updateInterMbIntraModes($mbX);

        $mbWidth = $this->picWidthInMbs;
        $mbIdx = $mbY * $mbWidth + $mbX;
        // 16 个 4x4 块 MV/参考索引全同：array_fill 一次构建（子数组共享同一 COW 引用，
        // 去块滤波端只读），避免逐块小数组分配
        if ($this->deblockInfoEnabled) {
            $this->mbMvForDeblock[$mbIdx] = array_fill(0, 16, [$mvX, $mvY]);
            $this->mbRefForDeblock[$mbIdx] = array_fill(0, 16, $refIdx);
        }

        return 0;
    }

    /**
     * 把本宏块相关的 nz 计数缓存全部清零
     *
     * 用于无残差的帧间宏块（P_Skip、B_Skip 等）：这些宏块不携带系数，
     * 其 nz 必须为 0，否则后续宏块计算 nC 时会误判有系数（9.2.1），
     * 去块滤波的 bS 判定也会受影响。
     *
     * 需要清零的位置：亮度上行 4 个（按 4x4 绝对列索引）、亮度左列 4 个、
     * 色度 Cb/Cr 各 2 个上行 + 2 个左列。
     *
     * @param int $mbX 宏块列号
     * @param int $mbY 宏块行号
     * @return void
     */
    private function updateNzCountZero(int $mbX, int $mbY): void
    {
        $mbWidth = $this->picWidthInMbs;

        // 亮度上行（供下方宏块使用；索引为绝对 4x4 列号 mbX*4 + 0..3）
        $baseLuma = $mbX * 4;
        $this->nzTopRowLuma[$baseLuma + 0] = 0;
        $this->nzTopRowLuma[$baseLuma + 1] = 0;
        $this->nzTopRowLuma[$baseLuma + 2] = 0;
        $this->nzTopRowLuma[$baseLuma + 3] = 0;

        // 亮度左列（供右侧宏块使用）
        $this->nzLeftColLuma[0] = 0;
        $this->nzLeftColLuma[1] = 0;
        $this->nzLeftColLuma[2] = 0;
        $this->nzLeftColLuma[3] = 0;

        // 色度上行：Cb 占前 mbWidth*2 段，Cr 占后 mbWidth*2 段
        $this->nzTopRowChroma[$mbX * 2 + 0] = 0;
        $this->nzTopRowChroma[$mbX * 2 + 1] = 0;
        $this->nzTopRowChroma[$mbWidth * 2 + $mbX * 2 + 0] = 0;
        $this->nzTopRowChroma[$mbWidth * 2 + $mbX * 2 + 1] = 0;

        // 色度左列：0/1 = Cb 上/下，2/3 = Cr 上/下
        $this->nzLeftColChroma[0] = 0;
        $this->nzLeftColChroma[1] = 0;
        $this->nzLeftColChroma[2] = 0;
        $this->nzLeftColChroma[3] = 0;
    }

    /**
     * Inter宏块解码后更新intra4x4预测模式缓存
     * 编码标准: 非Intra_4x4宏块的邻居预测模式视为DC_PRED(2)
     *
     * @param int $mbX 宏块列号（用于定位上行缓存中属于本宏块起始的 4 个位置）
     * @return void
     */
    private function updateInterMbIntraModes(int $mbX): void
    {
        // 非 Intra_4x4 宏块邻居预测模式固定为 DC_PRED(2)；常量数组 COW 共享，避免逐 MB 分配
        $this->intra4x4LeftModes = self::$interDcModes;
        $baseLuma = $mbX * 4;
        $this->intra4x4TopModes[$baseLuma + 0] = 2;
        $this->intra4x4TopModes[$baseLuma + 1] = 2;
        $this->intra4x4TopModes[$baseLuma + 2] = 2;
        $this->intra4x4TopModes[$baseLuma + 3] = 2;
    }

    // 注意：本包适配 PHP 8.1，trait 常量需 PHP 8.2+，故用 private static 属性（数组 COW 共享）
    /**
     * 非 Intra_4x4 宏块向邻居传播的预测模式数组（全为 DC_PRED = 2，8.3.1.1）
     * @var int[]
     */
    private static array $interDcModes = [2, 2, 2, 2];

    /**
     * 8x16 分区中属于「左半」的 4x4 块 raster 索引（共 8 个）
     *
     * 8x16 分区覆盖宏块左/右各 8 像素宽，每个分区包含 4 个 8x8 亮度 4x4 对，
     * 用这组索引可把某个 MV/参考索引原地写入对应的一半块。
     * @var int[]
     */
    private static array $left8x16Blocks = [0, 1, 4, 5, 8, 9, 12, 13];
    /**
     * 8x16 分区中属于「右半」的 4x4 块 raster 索引（共 8 个）
     * @var int[]
     */
    private static array $right8x16Blocks = [2, 3, 6, 7, 10, 11, 14, 15];

    // ====================== B 片宏块解码（CAVLC，main profile）======================
    // B 切片每个宏块最多使用两个参考列表（L0/L1），分区方向由 mb_type / sub_mb_type 决定：
    // 0 = 仅 List0、1 = 仅 List1、2 = 双向（BiPred，需要加权平均）。
    // B_Direct（含 B_Skip）需要根据同位帧（colocated）信息推导 MV/参考索引，见 8.4.1.2。

    // B 片 mb_type 4-21 双分区规格：[layout(0=16x8,1=8x16), dir0, dir1]，dir: 0=L0/1=L1/2=Bi
    // （b_mb_type_info）
    // 例如 [0,0,1] 表示上下两个 16x8 分区，上分区用 L0、下分区用 L1。
    /**
     * B 切片双分区 mb_type（4..21）的布局与预测方向表
     * layout：0 = 16x8（上下），1 = 8x16（左右）；dir：0 = L0，1 = L1，2 = 双向
     * @var array<int,int[]>
     */
    private static array $bPartSpecs = [
        4 => [0, 0, 0], 5 => [1, 0, 0], 6 => [0, 1, 1], 7 => [1, 1, 1],
        8 => [0, 0, 1], 9 => [1, 0, 1], 10 => [0, 1, 0], 11 => [1, 1, 0],
        12 => [0, 0, 2], 13 => [1, 0, 2], 14 => [0, 1, 2], 15 => [1, 1, 2],
        16 => [0, 2, 0], 17 => [1, 2, 0], 18 => [0, 2, 1], 19 => [1, 2, 1],
        20 => [0, 2, 2], 21 => [1, 2, 2],
    ];
    // B sub_mb_type 0-12 的分区数（b_sub_mb_type_info）
    /**
     * B 切片 sub_mb_type（0..12）对应的分区个数：1 = 8x8、2 = 8x4/4x8、4 = 4x4
     * @var int[]
     */
    private static array $bSubPartCount = [1, 1, 1, 1, 2, 2, 2, 2, 2, 2, 4, 4, 4];
    // B sub_mb_type 是否使用 L0/L1
    /**
     * B 切片各 sub_mb_type 是否使用 List0（1 = 使用）
     * @var int[]
     */
    private static array $bSubUsesL0 = [0, 1, 0, 1, 1, 1, 0, 0, 1, 1, 1, 0, 1];
    /**
     * B 切片各 sub_mb_type 是否使用 List1（1 = 使用）
     * @var int[]
     */
    private static array $bSubUsesL1 = [0, 0, 1, 1, 0, 0, 1, 1, 1, 1, 0, 1, 1];

    /**
     * 读取 B 切片的参考索引（ref_idx_l0 或 ref_idx_l1）
     *
     * 规则与 readRefIdxL0 相同（7.4.5.1）：只有 1 个活动参考帧时不出现语法元素，
     * 恰好 2 个时用 1 bit 定长且语义取反，更多时用 ue(v)。
     *
     * @param int $list 0 = List0，其他值按 List1 处理
     * @return int 解码得到的参考索引
     */
    private function readRefIdxB(int $list): int
    {
        // 根据列表选择对应的活动参考帧数量（num_ref_idx_l0_active_minus1 / l1）
        $numRef = $list === 0 ? $this->numRefIdxL0Active : $this->numRefIdxL1Active;
        if ($numRef <= 1) {
            // 单参考帧：语法元素缺省，隐含为 0
            return 0;
        }
        if ($numRef === 2) {
            // 双参考帧：1 bit 定长，按规范取反
            return $this->reader->readU(1) ^ 1;
        }
        // 多参考帧：ue(v)
        return $this->reader->readUe();
    }

    /**
     * 取出指定列表、指定参考索引所对应的参考像素平面信息
     *
     * 优先返回 refPicList0/refPicList1 中对应条目（含 y/u/v 平面、字节串、stride 与尺寸），
     * 若该索引不存在则回退到当前参考帧（refFrameY/U/V 等成员），保证运动补偿不至于空指针。
     *
     * @param int $list 0 = List0，其他值按 List1 处理
     * @param int $refIdx 参考帧索引
     * @return array 包含 y/u/v 平面、*Bytes、strideY/strideUv、widthY/heightY/widthUv/heightUv 的关联数组
     */
    private function getBRefPlanes(int $list, int $refIdx): array
    {
        // 选择参考列表
        $listArr = $list === 0 ? $this->refPicList0 : $this->refPicList1;
        if (isset($listArr[$refIdx])) {
            // 命中列表条目：只挑出运动补偿需要的字段
            $ref = $listArr[$refIdx];
            return [
                'y' => $ref['y'], 'u' => $ref['u'], 'v' => $ref['v'],
                'yBytes' => $ref['yBytes'], 'uBytes' => $ref['uBytes'], 'vBytes' => $ref['vBytes'],
                'strideY' => $ref['strideY'], 'strideUv' => $ref['strideUv'],
                'widthY' => $ref['widthY'], 'heightY' => $ref['heightY'],
                'widthUv' => $ref['widthUv'], 'heightUv' => $ref['heightUv'],
            ];
        }
        // 回退：直接使用当前参考帧平面（列表缺项时的兜底路径）
        return [
            'y' => $this->refFrameY, 'u' => $this->refFrameU, 'v' => $this->refFrameV,
            'yBytes' => $this->refFrameY, 'uBytes' => $this->refFrameU, 'vBytes' => $this->refFrameV,
            'strideY' => $this->refStrideY, 'strideUv' => $this->refStrideUv,
            'widthY' => $this->refWidthY, 'heightY' => $this->refHeightY,
            'widthUv' => $this->refWidthUv, 'heightUv' => $this->refHeightUv,
        ];
    }

    /**
     * 按需把参考帧的像素平面「实体化」为字节串（*Bytes）
     *
     * 亚像素插值需要按字节直接访问参考平面；某些 DPB 条目在构造时只保留了平面数组，
     * 字节串可能为 null。本方法只在真正需要（$needLuma/$needChroma）时补齐，
     * 并同步回 DPB 与 refPicList0，避免重复转换。
     *
     * 此处实现为：L1 条目直接跳过（构造时已共享 *Bytes），只处理 L0 条目。
     *
     * @param int $list 参考列表（仅 List0 会被处理）
     * @param int $refIdx List0 中的参考索引
     * @param bool $needLuma 是否确实需要亮度字节串
     * @param bool $needChroma 是否确实需要色度字节串
     * @return void
     */
    private function ensureBRefBytes(int $list, int $refIdx, bool $needLuma, bool $needChroma): void
    {
        // L1 条目直接来自 DPB（构造时已共享 *Bytes），仅需处理 L0
        if ($list !== 0 || !isset($this->refPicList0[$refIdx])) {
            return;
        }
        // 取列表条目，检查所需平面的字节串是否已就绪
        $listEntry = $this->refPicList0[$refIdx];
        $lumaOk = !$needLuma || $listEntry['yBytes'] !== null;
        $chromaOk = !$needChroma || ($listEntry['uBytes'] !== null && $listEntry['vBytes'] !== null);
        if ($lumaOk && $chromaOk) {
            // 已经齐全，无需处理
            return;
        }
        // 在 DPB 中找到与列表条目同一帧（frameNum 与 long-term 标记均相同）的条目
        foreach ($this->dpb as $k => $entry) {
            if ($entry['frameNum'] !== $listEntry['frameNum'] || $entry['isLongTerm'] !== $listEntry['isLongTerm']) {
                continue;
            }
            // 只补齐缺失的平面字节串
            if ($needLuma && $entry['yBytes'] === null) $entry['yBytes'] = $entry['y'];
            if ($needChroma && $entry['uBytes'] === null) $entry['uBytes'] = $entry['u'];
            if ($needChroma && $entry['vBytes'] === null) $entry['vBytes'] = $entry['v'];
            // 写回 DPB 与 refPicList0，保持两处一致
            $this->dpb[$k] = $entry;
            $this->refPicList0[$refIdx] = $entry;
            return;
        }
        // DPB 中未找到同一帧：直接在列表条目上就地补齐（引用写法避免复制整个条目）
        $entry = &$this->refPicList0[$refIdx];
        if ($needLuma && $entry['yBytes'] === null) $entry['yBytes'] = $entry['y'];
        if ($needChroma) {
            if ($entry['uBytes'] === null) $entry['uBytes'] = $entry['u'];
            if ($entry['vBytes'] === null) $entry['vBytes'] = $entry['v'];
        }
        unset($entry);
    }

    /**
     * 以 4x4 为单位对 B 切片的一个分区做单向运动补偿并写入当前帧
     *
     * $dstX/$dstY 与 $blockW/$blockH 采用「4x4 单位」坐标（故换算到亮度像素时要乘 4）。
     * 先判断是否需要亚像素插值（亮度 1/4 像素、色度 1/8 像素），按需补齐参考字节串，
     * 再分别做亮度与色度（Cb/Cr 成对）的运动补偿。
     *
     * @param int $dstX 目标块在宏块内/帧内的 4x4 列坐标
     * @param int $dstY 目标块在宏块内/帧内的 4x4 行坐标
     * @param int $blockW 块宽（4x4 单位）
     * @param int $blockH 块高（4x4 单位）
     * @param int $mvX 亮度 MV 水平分量（1/4 像素单位）
     * @param int $mvY 亮度 MV 垂直分量（1/4 像素单位）
     * @param int $list 参考列表
     * @param int $refIdx 参考索引
     * @return void
     */
    private function mcBBlockTo(int $dstX, int $dstY, int $blockW, int $blockH, int $mvX, int $mvY, int $list, int $refIdx): void
    {
        // 没有任何参考帧可用：直接放弃（保持目标区域原值）
        if ($this->refFrameY === null) {
            return;
        }
        // 参考坐标：4x4 单位 → 亮度像素，再叠加 MV（1/4 像素精度）
        $lumaRefX = $dstX * 4 + $mvX;
        $lumaRefY = $dstY * 4 + $mvY;
        // 低 2 位非 0 说明亮度落在 1/4 像素位置，需要插值（否则可整像素拷贝）
        $needLuma = ($lumaRefX & 3) !== 0 || ($lumaRefY & 3) !== 0;
        // 色度 MV 为亮度的一半精度（1/8 像素），故用低 3 位判断
        $needChroma = ($lumaRefX & 7) !== 0 || ($lumaRefY & 7) !== 0;
        $this->ensureBRefBytes($list, $refIdx, $needLuma, $needChroma);
        $ref = $this->getBRefPlanes($list, $refIdx);
        // 亮度运动补偿（内部处理 1/4 像素 6 抽头插值）
        $this->mcLumaTo(
            $ref['y'], $ref['strideY'], $ref['widthY'], $ref['heightY'],
            $lumaRefX, $lumaRefY, $blockW, $blockH,
            $this->yPlane, $this->width, $dstX, $dstY, $ref['yBytes']
        );
        // 色度运动补偿：块尺寸与目标坐标都折半（4:2:0）
        $chromaStride = intdiv($this->width, 2);
        $this->mcChromaPairTo(
            $ref['u'], $ref['v'], $ref['strideUv'], $ref['widthUv'], $ref['heightUv'],
            $lumaRefX, $lumaRefY, intdiv($blockW, 2), intdiv($blockH, 2),
            $this->uPlane, $this->vPlane, $chromaStride, intdiv($dstX, 2), intdiv($dstY, 2),
            $ref['uBytes'], $ref['vBytes']
        );
    }

    /**
     * 双向预测（BiPred）分区的重建：L0 与 L1 两个预测块按权重合并
     *
     * 依据 8.4.3：双向预测的分区需要把 List0 与 List1 各做一次运动补偿，
     * 再按默认等权或隐式权重（weighted_bipred_idc==2）合并：
     *   - 等权：pred = (p0 + p1 + 1) >> 1；
     *   - 隐式：pred = (w0*p0 + w1*p1 + 32) >> 6，w0 由 POC 距离推得（8.4.3.3）。
     * 两个参考都落在整像素位置时走 biPredFusedInt 融合快路径（单趟写出）。
     *
     * @param int $dstX 目标块 4x4 列坐标
     * @param int $dstY 目标块 4x4 行坐标
     * @param int $blockW 块宽（4x4 单位）
     * @param int $blockH 块高（4x4 单位）
     * @param int $mv0X List0 MV 水平分量（1/4 像素）
     * @param int $mv0Y List0 MV 垂直分量（1/4 像素）
     * @param int $refIdx0 List0 参考索引
     * @param int $mv1X List1 MV 水平分量（1/4 像素）
     * @param int $mv1Y List1 MV 垂直分量（1/4 像素）
     * @param int $refIdx1 List1 参考索引
     * @return void
     */
    private function performBiPredBlock(int $dstX, int $dstY, int $blockW, int $blockH,
                                        int $mv0X, int $mv0Y, int $refIdx0,
                                        int $mv1X, int $mv1Y, int $refIdx1): void
    {
        // 无参考帧时直接返回（保持目标区域不变）
        if ($this->refFrameY === null) {
            return;
        }
        // 两个列表各自的亮度参考坐标（4x4 单位 → 像素 + MV）
        $lumaRefX0 = $dstX * 4 + $mv0X;
        $lumaRefY0 = $dstY * 4 + $mv0Y;
        $lumaRefX1 = $dstX * 4 + $mv1X;
        $lumaRefY1 = $dstY * 4 + $mv1Y;
        // 双参考均全整数（luma &3==0 且 chroma &7==0，后者蕴含前者）时，
        // 原路径 = L0 拷入当前面 + L1 拷入临时面 + 合并趟（共 3 趟 + 临时面分配）。
        // 融合为直读双参考面的单趟写出，像素级 bit 等价（B_Direct 零 MV 主体全走此路径）。
        if ((($lumaRefX0 | $lumaRefY0 | $lumaRefX1 | $lumaRefY1) & 7) === 0) {
            $this->ensureBRefBytes(0, $refIdx0, false, false);
            $this->ensureBRefBytes(1, $refIdx1, false, false);
            $r0 = $this->getBRefPlanes(0, $refIdx0);
            $r1 = $this->getBRefPlanes(1, $refIdx1);
            $w0 = 32;
            if ($this->weightedBipredIdc === 2) {
                $w0 = $this->getBImplicitWeight0($refIdx0, $refIdx1);
            }
            $this->biPredFusedInt($r0, $r1, $lumaRefX0, $lumaRefY0, $lumaRefX1, $lumaRefY1,
                $dstX, $dstY, $blockW, $blockH, $w0);
            return;
        }
        // L0 直接写入当前平面；L1 写临时平面后按隐式权重合并（见下方合并循环）
        $this->mcBBlockTo($dstX, $dstY, $blockW, $blockH, $mv0X, $mv0Y, 0, $refIdx0);
        $tmpY = array_fill(0, $blockH * $blockW, 0);
        $cw = intdiv($blockW, 2);
        $ch = intdiv($blockH, 2);
        $tmpU = array_fill(0, $ch * $cw, 0);
        $tmpV = array_fill(0, $ch * $cw, 0);
        $lumaRefX = $lumaRefX1;
        $lumaRefY = $lumaRefY1;
        $needLuma = ($lumaRefX & 3) !== 0 || ($lumaRefY & 3) !== 0;
        $needChroma = ($lumaRefX & 7) !== 0 || ($lumaRefY & 7) !== 0;
        $this->ensureBRefBytes(1, $refIdx1, $needLuma, $needChroma);
        $ref = $this->getBRefPlanes(1, $refIdx1);
        $this->mcLumaTo(
            $ref['y'], $ref['strideY'], $ref['widthY'], $ref['heightY'],
            $lumaRefX, $lumaRefY, $blockW, $blockH,
            $tmpY, $blockW, 0, 0, $ref['yBytes']
        );
        $this->mcChromaPairTo(
            $ref['u'], $ref['v'], $ref['strideUv'], $ref['widthUv'], $ref['heightUv'],
            $lumaRefX, $lumaRefY, $cw, $ch,
            $tmpU, $tmpV, $cw, 0, 0,
            $ref['uBytes'], $ref['vBytes']
        );
        // 隐式加权双预测（weightedBipredIdc==2，spec 8.4.3）：pred = (w0*p0 + w1*p1 + 32) >> 6
        // w0==32 时公式退化为 (p0+p1+1)>>1，走原等权快路径
        $w0 = 32;
        if ($this->weightedBipredIdc === 2) {
            $w0 = $this->getBImplicitWeight0($refIdx0, $refIdx1);
        }
        $dstStride = $this->width;
        $chromaStride = intdiv($this->width, 2);
        $dstCx = intdiv($dstX, 2);
        $dstCy = intdiv($dstY, 2);
        if ($w0 === 32) {
            for ($y = 0; $y < $blockH; $y++) {
                $row = ($y + $dstY) * $dstStride + $dstX;
                $trow = $y * $blockW;
                for ($x = 0; $x < $blockW; $x++) {
                    $avg = ($this->yPlane[$row + $x] + $tmpY[$trow + $x] + 1) >> 1;
                    $this->yPlane[$row + $x] = $avg > 255 ? 255 : $avg;
                }
            }
            for ($y = 0; $y < $ch; $y++) {
                $urow = ($y + $dstCy) * $chromaStride + $dstCx;
                $trow = $y * $cw;
                for ($x = 0; $x < $cw; $x++) {
                    $avg = ($this->uPlane[$urow + $x] + $tmpU[$trow + $x] + 1) >> 1;
                    $this->uPlane[$urow + $x] = $avg > 255 ? 255 : $avg;
                    $avg = ($this->vPlane[$urow + $x] + $tmpV[$trow + $x] + 1) >> 1;
                    $this->vPlane[$urow + $x] = $avg > 255 ? 255 : $avg;
                }
            }
        } else {
            $w1 = 64 - $w0;
            for ($y = 0; $y < $blockH; $y++) {
                $row = ($y + $dstY) * $dstStride + $dstX;
                $trow = $y * $blockW;
                for ($x = 0; $x < $blockW; $x++) {
                    $avg = ($w0 * $this->yPlane[$row + $x] + $w1 * $tmpY[$trow + $x] + 32) >> 6;
                    $this->yPlane[$row + $x] = $avg < 0 ? 0 : ($avg > 255 ? 255 : $avg);
                }
            }
            for ($y = 0; $y < $ch; $y++) {
                $urow = ($y + $dstCy) * $chromaStride + $dstCx;
                $trow = $y * $cw;
                for ($x = 0; $x < $cw; $x++) {
                    $avg = ($w0 * $this->uPlane[$urow + $x] + $w1 * $tmpU[$trow + $x] + 32) >> 6;
                    $this->uPlane[$urow + $x] = $avg < 0 ? 0 : ($avg > 255 ? 255 : $avg);
                    $avg = ($w0 * $this->vPlane[$urow + $x] + $w1 * $tmpV[$trow + $x] + 32) >> 6;
                    $this->vPlane[$urow + $x] = $avg < 0 ? 0 : ($avg > 255 ? 255 : $avg);
                }
            }
        }
    }

    /**
     * 双参考全整数 bi-pred 融合：直读 L0/L1 参考面单趟写出，等价于
     * 「L0 整数拷入 dst + L1 整数拷入 tmp + (p0+p1+1)>>1 合并」三趟的 bit 级结果。
     * 输入像素均在 [0,255]（解码残差加法已裁剪），等权 (p0+p1+1)>>1 ≤ 255、
     * 加权 (w0*p0+w1*p1+32)>>6 ≤ 255（w0+w1=64），故合并结果无需再裁剪。
     *
     * @param array $r0 List0 参考平面描述（含 y/u/v、stride、尺寸）
     * @param array $r1 List1 参考平面描述
     * @param int $lx0 List0 亮度参考 x（1/4 像素单位）
     * @param int $ly0 List0 亮度参考 y（1/4 像素单位）
     * @param int $lx1 List1 亮度参考 x（1/4 像素单位）
     * @param int $ly1 List1 亮度参考 y（1/4 像素单位）
     * @param int $dstX 目标块 4x4 列坐标
     * @param int $dstY 目标块 4x4 行坐标
     * @param int $blockW 块宽（4x4 单位）
     * @param int $blockH 块高（4x4 单位）
     * @param int $w0 List0 权重（6bit 定点，32 表示等权）
     * @return void
     */
    private function biPredFusedInt(
        array $r0, array $r1,
        int $lx0, int $ly0, int $lx1, int $ly1,
        int $dstX, int $dstY, int $blockW, int $blockH, int $w0
    ): void {
        // 亮度：luma 1/4pel >> 2 即整数位（此路径已保证 &7==0，即 &3==0）
        $this->biPredMergePlane(
            $r0['y'], $r0['strideY'], $lx0 >> 2, $ly0 >> 2, $r0['widthY'], $r0['heightY'],
            $r1['y'], $r1['strideY'], $lx1 >> 2, $ly1 >> 2, $r1['widthY'], $r1['heightY'],
            $this->yPlane, $this->width, $dstX, $dstY, $blockW, $blockH, $w0
        );
        // 色度：chroma 坐标（chroma 像素）= luma 1/4pel / 8（&7==0 已保证整数）
        $cw = $blockW >> 1;
        $ch = $blockH >> 1;
        $u0 = $r0['u']; $v0 = $r0['v']; $u1 = $r1['u']; $v1 = $r1['v'];
        $s0 = $r0['strideUv']; $s1 = $r1['strideUv'];
        $ax = $lx0 >> 3; $ay = $ly0 >> 3;
        $bx = $lx1 >> 3; $by = $ly1 >> 3;
        $in0 = $ax >= 0 && $ay >= 0 && $ax + $cw <= $r0['widthUv'] && $ay + $ch <= $r0['heightUv'];
        $in1 = $bx >= 0 && $by >= 0 && $bx + $cw <= $r1['widthUv'] && $by + $ch <= $r1['heightUv'];
        $cs = intdiv($this->width, 2);
        $dcx = $dstX >> 1;
        $dcy = $dstY >> 1;
        if ($in0 && $in1) {
            for ($j = 0; $j < $ch; $j++) {
                $ra = ($ay + $j) * $s0 + $ax;
                $rb = ($by + $j) * $s1 + $bx;
                $d = ($dcy + $j) * $cs + $dcx;
                if ($w0 === 32) {
                    for ($i = 0; $i < $cw; $i++) {
                        $this->uPlane[$d + $i] = ($u0[$ra + $i] + $u1[$rb + $i] + 1) >> 1;
                        $this->vPlane[$d + $i] = ($v0[$ra + $i] + $v1[$rb + $i] + 1) >> 1;
                    }
                } else {
                    $w1 = 64 - $w0;
                    for ($i = 0; $i < $cw; $i++) {
                        $this->uPlane[$d + $i] = ($w0 * $u0[$ra + $i] + $w1 * $u1[$rb + $i] + 32) >> 6;
                        $this->vPlane[$d + $i] = ($w0 * $v0[$ra + $i] + $w1 * $v1[$rb + $i] + 32) >> 6;
                    }
                }
            }
            return;
        }
        // 越界回退：逐像素 clamp（与 mcChromaPairToCore 越界路径同口径）
        $mw0 = $r0['widthUv'] - 1; $mh0 = $r0['heightUv'] - 1;
        $mw1 = $r1['widthUv'] - 1; $mh1 = $r1['heightUv'] - 1;
        if ($w0 === 32) {
            for ($j = 0; $j < $ch; $j++) {
                $ayj = $ay + $j; $ayj = $ayj < 0 ? 0 : ($ayj > $mh0 ? $mh0 : $ayj);
                $byj = $by + $j; $byj = $byj < 0 ? 0 : ($byj > $mh1 ? $mh1 : $byj);
                $ra = $ayj * $s0;
                $rb = $byj * $s1;
                $d = ($dcy + $j) * $cs + $dcx;
                for ($i = 0; $i < $cw; $i++) {
                    $axi = $ax + $i; $axi = $axi < 0 ? 0 : ($axi > $mw0 ? $mw0 : $axi);
                    $bxi = $bx + $i; $bxi = $bxi < 0 ? 0 : ($bxi > $mw1 ? $mw1 : $bxi);
                    $this->uPlane[$d + $i] = ($u0[$ra + $axi] + $u1[$rb + $bxi] + 1) >> 1;
                    $this->vPlane[$d + $i] = ($v0[$ra + $axi] + $v1[$rb + $bxi] + 1) >> 1;
                }
            }
            return;
        }
        $w1 = 64 - $w0;
        for ($j = 0; $j < $ch; $j++) {
            $ayj = $ay + $j; $ayj = $ayj < 0 ? 0 : ($ayj > $mh0 ? $mh0 : $ayj);
            $byj = $by + $j; $byj = $byj < 0 ? 0 : ($byj > $mh1 ? $mh1 : $byj);
            $ra = $ayj * $s0;
            $rb = $byj * $s1;
            $d = ($dcy + $j) * $cs + $dcx;
            for ($i = 0; $i < $cw; $i++) {
                $axi = $ax + $i; $axi = $axi < 0 ? 0 : ($axi > $mw0 ? $mw0 : $axi);
                $bxi = $bx + $i; $bxi = $bxi < 0 ? 0 : ($bxi > $mw1 ? $mw1 : $bxi);
                $this->uPlane[$d + $i] = ($w0 * $u0[$ra + $axi] + $w1 * $u1[$rb + $bxi] + 32) >> 6;
                $this->vPlane[$d + $i] = ($w0 * $v0[$ra + $axi] + $w1 * $v1[$rb + $bxi] + 32) >> 6;
            }
        }
    }

    /**
     * 双参考全整数平面的加权合并（亮度）：等价于
     * 「L0 拷入 dst + L1 拷入 tmp + (w0*p0+w1*p1+32)>>6 合并」的 bit 级结果。
     *
     * 按块宽 16/8/其他做三档展开：16 与 8 像素宽使用完全展开的赋值语句（减少循环开销），
     * 其余宽度走通用循环。两个参考块都在边界内时走快路径，否则逐像素 clamp（8.4.3.3 的边缘像素填充规则）。
     *
     * @param array $p0 List0 平面
     * @param int $s0 List0 stride
     * @param int $x0 List0 起始 x（像素）
     * @param int $y0 List0 起始 y（像素）
     * @param int $wd0 List0 平面宽
     * @param int $hd0 List0 平面高
     * @param array $p1 List1 平面
     * @param int $s1 List1 stride
     * @param int $x1 List1 起始 x（像素）
     * @param int $y1 List1 起始 y（像素）
     * @param int $wd1 List1 平面宽
     * @param int $hd1 List1 平面高
     * @param array $dst 目标平面（按引用传入）
     * @param int $dStride 目标 stride
     * @param int $dX 目标起始 x
     * @param int $dY 目标起始 y
     * @param int $blockW 块宽（像素）
     * @param int $blockH 块高（像素）
     * @param int $w0 List0 权重（32 = 等权）
     * @return void
     */
    private function biPredMergePlane(
        array $p0, int $s0, int $x0, int $y0, int $wd0, int $hd0,
        array $p1, int $s1, int $x1, int $y1, int $wd1, int $hd1,
        array &$dst, int $dStride, int $dX, int $dY,
        int $blockW, int $blockH, int $w0
    ): void {
        $in0 = $x0 >= 0 && $y0 >= 0 && $x0 + $blockW <= $wd0 && $y0 + $blockH <= $hd0;
        $in1 = $x1 >= 0 && $y1 >= 0 && $x1 + $blockW <= $wd1 && $y1 + $blockH <= $hd1;
        if ($in0 && $in1) {
            if ($blockW === 16) {
                for ($j = 0; $j < $blockH; $j++) {
                    $a = ($y0 + $j) * $s0 + $x0;
                    $b = ($y1 + $j) * $s1 + $x1;
                    $d = ($dY + $j) * $dStride + $dX;
                    if ($w0 === 32) {
                        $dst[$d]      = ($p0[$a]      + $p1[$b]      + 1) >> 1;
                        $dst[$d + 1]  = ($p0[$a + 1]  + $p1[$b + 1]  + 1) >> 1;
                        $dst[$d + 2]  = ($p0[$a + 2]  + $p1[$b + 2]  + 1) >> 1;
                        $dst[$d + 3]  = ($p0[$a + 3]  + $p1[$b + 3]  + 1) >> 1;
                        $dst[$d + 4]  = ($p0[$a + 4]  + $p1[$b + 4]  + 1) >> 1;
                        $dst[$d + 5]  = ($p0[$a + 5]  + $p1[$b + 5]  + 1) >> 1;
                        $dst[$d + 6]  = ($p0[$a + 6]  + $p1[$b + 6]  + 1) >> 1;
                        $dst[$d + 7]  = ($p0[$a + 7]  + $p1[$b + 7]  + 1) >> 1;
                        $dst[$d + 8]  = ($p0[$a + 8]  + $p1[$b + 8]  + 1) >> 1;
                        $dst[$d + 9]  = ($p0[$a + 9]  + $p1[$b + 9]  + 1) >> 1;
                        $dst[$d + 10] = ($p0[$a + 10] + $p1[$b + 10] + 1) >> 1;
                        $dst[$d + 11] = ($p0[$a + 11] + $p1[$b + 11] + 1) >> 1;
                        $dst[$d + 12] = ($p0[$a + 12] + $p1[$b + 12] + 1) >> 1;
                        $dst[$d + 13] = ($p0[$a + 13] + $p1[$b + 13] + 1) >> 1;
                        $dst[$d + 14] = ($p0[$a + 14] + $p1[$b + 14] + 1) >> 1;
                        $dst[$d + 15] = ($p0[$a + 15] + $p1[$b + 15] + 1) >> 1;
                    } else {
                        $w1 = 64 - $w0;
                        $dst[$d]      = ($w0 * $p0[$a]      + $w1 * $p1[$b]      + 32) >> 6;
                        $dst[$d + 1]  = ($w0 * $p0[$a + 1]  + $w1 * $p1[$b + 1]  + 32) >> 6;
                        $dst[$d + 2]  = ($w0 * $p0[$a + 2]  + $w1 * $p1[$b + 2]  + 32) >> 6;
                        $dst[$d + 3]  = ($w0 * $p0[$a + 3]  + $w1 * $p1[$b + 3]  + 32) >> 6;
                        $dst[$d + 4]  = ($w0 * $p0[$a + 4]  + $w1 * $p1[$b + 4]  + 32) >> 6;
                        $dst[$d + 5]  = ($w0 * $p0[$a + 5]  + $w1 * $p1[$b + 5]  + 32) >> 6;
                        $dst[$d + 6]  = ($w0 * $p0[$a + 6]  + $w1 * $p1[$b + 6]  + 32) >> 6;
                        $dst[$d + 7]  = ($w0 * $p0[$a + 7]  + $w1 * $p1[$b + 7]  + 32) >> 6;
                        $dst[$d + 8]  = ($w0 * $p0[$a + 8]  + $w1 * $p1[$b + 8]  + 32) >> 6;
                        $dst[$d + 9]  = ($w0 * $p0[$a + 9]  + $w1 * $p1[$b + 9]  + 32) >> 6;
                        $dst[$d + 10] = ($w0 * $p0[$a + 10] + $w1 * $p1[$b + 10] + 32) >> 6;
                        $dst[$d + 11] = ($w0 * $p0[$a + 11] + $w1 * $p1[$b + 11] + 32) >> 6;
                        $dst[$d + 12] = ($w0 * $p0[$a + 12] + $w1 * $p1[$b + 12] + 32) >> 6;
                        $dst[$d + 13] = ($w0 * $p0[$a + 13] + $w1 * $p1[$b + 13] + 32) >> 6;
                        $dst[$d + 14] = ($w0 * $p0[$a + 14] + $w1 * $p1[$b + 14] + 32) >> 6;
                        $dst[$d + 15] = ($w0 * $p0[$a + 15] + $w1 * $p1[$b + 15] + 32) >> 6;
                    }
                }
                return;
            }
            if ($blockW === 8) {
                for ($j = 0; $j < $blockH; $j++) {
                    $a = ($y0 + $j) * $s0 + $x0;
                    $b = ($y1 + $j) * $s1 + $x1;
                    $d = ($dY + $j) * $dStride + $dX;
                    if ($w0 === 32) {
                        $dst[$d]     = ($p0[$a]     + $p1[$b]     + 1) >> 1;
                        $dst[$d + 1] = ($p0[$a + 1] + $p1[$b + 1] + 1) >> 1;
                        $dst[$d + 2] = ($p0[$a + 2] + $p1[$b + 2] + 1) >> 1;
                        $dst[$d + 3] = ($p0[$a + 3] + $p1[$b + 3] + 1) >> 1;
                        $dst[$d + 4] = ($p0[$a + 4] + $p1[$b + 4] + 1) >> 1;
                        $dst[$d + 5] = ($p0[$a + 5] + $p1[$b + 5] + 1) >> 1;
                        $dst[$d + 6] = ($p0[$a + 6] + $p1[$b + 6] + 1) >> 1;
                        $dst[$d + 7] = ($p0[$a + 7] + $p1[$b + 7] + 1) >> 1;
                    } else {
                        $w1 = 64 - $w0;
                        $dst[$d]     = ($w0 * $p0[$a]     + $w1 * $p1[$b]     + 32) >> 6;
                        $dst[$d + 1] = ($w0 * $p0[$a + 1] + $w1 * $p1[$b + 1] + 32) >> 6;
                        $dst[$d + 2] = ($w0 * $p0[$a + 2] + $w1 * $p1[$b + 2] + 32) >> 6;
                        $dst[$d + 3] = ($w0 * $p0[$a + 3] + $w1 * $p1[$b + 3] + 32) >> 6;
                        $dst[$d + 4] = ($w0 * $p0[$a + 4] + $w1 * $p1[$b + 4] + 32) >> 6;
                        $dst[$d + 5] = ($w0 * $p0[$a + 5] + $w1 * $p1[$b + 5] + 32) >> 6;
                        $dst[$d + 6] = ($w0 * $p0[$a + 6] + $w1 * $p1[$b + 6] + 32) >> 6;
                        $dst[$d + 7] = ($w0 * $p0[$a + 7] + $w1 * $p1[$b + 7] + 32) >> 6;
                    }
                }
                return;
            }
            for ($j = 0; $j < $blockH; $j++) {
                $a = ($y0 + $j) * $s0 + $x0;
                $b = ($y1 + $j) * $s1 + $x1;
                $d = ($dY + $j) * $dStride + $dX;
                if ($w0 === 32) {
                    for ($i = 0; $i < $blockW; $i++) {
                        $dst[$d + $i] = ($p0[$a + $i] + $p1[$b + $i] + 1) >> 1;
                    }
                } else {
                    $w1 = 64 - $w0;
                    for ($i = 0; $i < $blockW; $i++) {
                        $dst[$d + $i] = ($w0 * $p0[$a + $i] + $w1 * $p1[$b + $i] + 32) >> 6;
                    }
                }
            }
            return;
        }
        // 越界回退：逐像素 clamp（与 mcLumaTo 整数越界路径同口径）
        $mw0 = $wd0 - 1; $mh0 = $hd0 - 1;
        $mw1 = $wd1 - 1; $mh1 = $hd1 - 1;
        if ($w0 === 32) {
            for ($j = 0; $j < $blockH; $j++) {
                $y0j = $y0 + $j; $y0j = $y0j < 0 ? 0 : ($y0j > $mh0 ? $mh0 : $y0j);
                $y1j = $y1 + $j; $y1j = $y1j < 0 ? 0 : ($y1j > $mh1 ? $mh1 : $y1j);
                $ra = $y0j * $s0;
                $rb = $y1j * $s1;
                $d = ($dY + $j) * $dStride + $dX;
                for ($i = 0; $i < $blockW; $i++) {
                    $x0i = $x0 + $i; $x0i = $x0i < 0 ? 0 : ($x0i > $mw0 ? $mw0 : $x0i);
                    $x1i = $x1 + $i; $x1i = $x1i < 0 ? 0 : ($x1i > $mw1 ? $mw1 : $x1i);
                    $dst[$d + $i] = ($p0[$ra + $x0i] + $p1[$rb + $x1i] + 1) >> 1;
                }
            }
            return;
        }
        $w1 = 64 - $w0;
        for ($j = 0; $j < $blockH; $j++) {
            $y0j = $y0 + $j; $y0j = $y0j < 0 ? 0 : ($y0j > $mh0 ? $mh0 : $y0j);
            $y1j = $y1 + $j; $y1j = $y1j < 0 ? 0 : ($y1j > $mh1 ? $mh1 : $y1j);
            $ra = $y0j * $s0;
            $rb = $y1j * $s1;
            $d = ($dY + $j) * $dStride + $dX;
            for ($i = 0; $i < $blockW; $i++) {
                $x0i = $x0 + $i; $x0i = $x0i < 0 ? 0 : ($x0i > $mw0 ? $mw0 : $x0i);
                $x1i = $x1 + $i; $x1i = $x1i < 0 ? 0 : ($x1i > $mw1 ? $mw1 : $x1i);
                $dst[$d + $i] = ($w0 * $p0[$ra + $x0i] + $w1 * $p1[$rb + $x1i] + 32) >> 6;
            }
        }
    }

    /**
     * B 切片 16x16 分区的 MV 预测
     *
     * 按 8.4.1.3 取 A（左邻宏块右侧 4x4 的 MV）、B（上邻宏块正下方 4x4）、
     * C（上邻宏块右上角 4x4，不可用时回退到左上角 D）三个邻居 MV，
     * 再交给 predictMvP16x16 做中值预测并处理不可用/不同参考索引的情形。
     *
     * @param int $mbX 宏块列号
     * @param int $mbY 宏块行号
     * @param int $list 参考列表（0 = L0，其他 = L1）
     * @param int $refIdx 当前分区的参考索引
     * @return array [mvX, mvY]（1/4 像素单位）
     */
    private function getB16x16MvPrediction(int $mbX, int $mbY, int $list, int $refIdx): array
    {
        $mbWidth = $this->picWidthInMbs;
        $mvLeft = null;
        $mvTop = null;
        $mvC = null;
        if ($mbX > 0) {
            // pred_motion：A = mv_cache[scan8[0]-1] = 左 MB 块 3（索引 0）
            if (isset($this->bMvLeftCol[$list][0])) {
                $mvLeft = $this->bMvLeftCol[$list][0];
            }
        }
        if ($mbY > 0) {
            // B = 上邻宏块中与本宏块首列对齐的 4x4 块
            $mvTop = $this->bMvTopRow[$list][$mbX * 4] ?? null;
            if ($mbX + 1 < $mbWidth) {
                // C = 上邻宏块右上角（宏块级别的「右上」邻居）
                $mvC = $this->bMvTopRow[$list][($mbX + 1) * 4] ?? null;
            }
            if ($mvC === null && $mbX > 0) {
                // C 不可用（右边界）时回退到左上角 D
                $mvC = $this->bMvTopLeft[$list] ?? null;
            }
        }
        // 中值预测（8.4.1.3.1）：三个邻居齐全时取中值，缺失时按规范回退
        return $this->predictMvP16x16($mvLeft, $mvTop, $mvC, $refIdx);
    }

    /**
     * B 切片 16x8 分区的 MV 预测
     *
     * 16x8 分区的 pred_part 使用 8.4.1.3 规定的邻居集合：
     *   - partIdx=0（上分区）：A 取左邻第 0 行块、B/C 取上邻、右上邻；
     *   - partIdx=1（下分区）：B 直接复用上分区已算出的 MV（$mvPart0），C 取左邻第 1 行块。
     * 若 B（part0）或 A（part1）的参考索引与当前一致，可直接采用其 MV（规范中的快速路径）。
     *
     * @param int $mbX 宏块列号
     * @param int $mbY 宏块行号
     * @param int $partIdx 分区序号（0 = 上，1 = 下）
     * @param int $list 参考列表
     * @param int $refIdx 当前分区参考索引
     * @param array|null $mvPart0 上分区已算出的 MV（含 ref 的 [x,y,ref]），partIdx=1 时使用
     * @return array [mvX, mvY]
     */
    private function getB16x8MvPrediction(int $mbX, int $mbY, int $partIdx, int $list, int $refIdx, ?array $mvPart0 = null): array
    {
        $mbWidth = $this->picWidthInMbs;

        $mvLeft = null;
        $mvTop = null;
        $mvC = null;

        if ($mbX > 0) {
            // 左邻宏块中与本分区行对齐的 4x4 块：每个 16x8 分区跨 2 行 4x4
            $rowIdx = $partIdx * 2;
            if (isset($this->bMvLeftCol[$list][$rowIdx])) {
                $mvLeft = $this->bMvLeftCol[$list][$rowIdx];
            }
        }

        if ($partIdx === 0) {
            if ($mbY > 0) {
                // 上分区：B = 上邻首列块
                $mvTop = $this->bMvTopRow[$list][$mbX * 4] ?? null;
            }
            if ($mbX + 1 < $mbWidth && $mbY > 0) {
                // C = 右上邻
                $mvC = $this->bMvTopRow[$list][($mbX + 1) * 4] ?? null;
            }
            if ($mvC === null && $mbX > 0 && $mbY > 0) {
                // 右上不可用 → 左上角 D
                $mvC = $this->bMvTopLeft[$list] ?? null;
            }
        } else {
            // 下分区：B 复用上分区 MV
            $mvTop = $mvPart0;
            if ($mbX > 0 && isset($this->bMvLeftCol[$list][1])) {
                // C 取左邻宏块第 1 行块
                $mvC = $this->bMvLeftCol[$list][1];
            }
        }

        // 快速路径：part0 的 B 与当前 ref 相同 → 直接采用其 MV
        if ($partIdx === 0) {
            if ($mvTop !== null && $mvTop[2] >= 0 && $mvTop[2] === $refIdx) {
                return [$mvTop[0], $mvTop[1]];
            }
        } else {
            // part1 的 A 与当前 ref 相同 → 直接采用其 MV
            if ($mvLeft !== null && $mvLeft[2] >= 0 && $mvLeft[2] === $refIdx) {
                return [$mvLeft[0], $mvLeft[1]];
            }
        }

        // 其余情况走通用中值预测
        $result = $this->predictMvP16x16($mvLeft, $mvTop, $mvC, $refIdx);
        return $result;
    }

    /**
     * B 切片 8x16 分区的 MV 预测
     *
     * 与 16x8 对称：partIdx=0 为左分区、1 为右分区（8.4.1.3 的 pred_part 规则）。
     * 左分区的 C 取上邻宏块中 blk(2,-1)；右分区的 B 取上邻 blk(2,0)，
     * C 越界时先回退到上邻内部块，再回退到左上角 D。
     *
     * @param int $mbX 宏块列号
     * @param int $mbY 宏块行号
     * @param int $partIdx 分区序号（0 = 左，1 = 右）
     * @param int $list 参考列表
     * @param int $refIdx 当前分区参考索引
     * @param array|null $mvPart0 左分区已算出的 MV（[x,y,ref]），partIdx=1 时使用
     * @return array [mvX, mvY]
     */
    private function getB8x16MvPrediction(int $mbX, int $mbY, int $partIdx, int $list, int $refIdx, ?array $mvPart0 = null): array
    {
        $mbWidth = $this->picWidthInMbs;

        $mvLeft = null;
        $mvTop = null;
        $mvC = null;

        if ($partIdx === 0) {
            if ($mbX > 0) {
                // pred_8x16 n=0：A = mv_cache[scan8[0]-1] = 左 MB 块 3（索引 0）
                if (isset($this->bMvLeftCol[$list][0])) {
                    $mvLeft = $this->bMvLeftCol[$list][0];
                }
            }
            if ($mbY > 0) {
                // B = top of blk(0,0) → blk(0,3) = mbX*4+0
                $mvTop = $this->bMvTopRow[$list][$mbX * 4 + 0] ?? null;
            }
            // C = top-right of part0: blk(0+2,-1) = blk(2,-1) → mbX*4+2
            if ($mbY > 0) {
                $mvC = $this->bMvTopRow[$list][$mbX * 4 + 2] ?? null;
            }
            // D fallback: 当前宏块左上方 4x4 块
            if ($mvC === null && $mbX > 0 && $mbY > 0) {
                $mvC = $this->bMvTopLeft[$list] ?? null;
            }
        } else {
            $mvLeft = $mvPart0;
            if ($mbY > 0) {
                // B = top of blk(2,0) → blk(2,3) = mbX*4+2
                $mvTop = $this->bMvTopRow[$list][$mbX * 4 + 2] ?? null;
            }
            // C = 当前右分区右上角；画面右边界不可用时回退到左上角 D。
            if ($mbX + 1 < $mbWidth && $mbY > 0) {
                $mvC = $this->bMvTopRow[$list][($mbX + 1) * 4] ?? null;
            }
            if ($mvC === null && $mbY > 0) {
                $mvC = $this->bMvTopRow[$list][$mbX * 4 + 1] ?? null;
            }
        }

        if ($partIdx === 0) {
            if ($mvLeft !== null && $mvLeft[2] === $refIdx) {
                return [$mvLeft[0], $mvLeft[1]];
            }
        } else {
            if ($mvC !== null && $mvC[2] === $refIdx) {
                return [$mvC[0], $mvC[1]];
            }
        }

        $pred = $this->predictMvP16x16($mvLeft, $mvTop, $mvC, $refIdx);
        return $pred;
    }

    /**
     * spatial direct 的 MB 级邻居预测（pred_spatial_direct_motion）
     * 每 list 独立：取邻居有效 ref 最小值 + 匹配中值 MV；无有效邻居 → ref=-1（该 list 丢弃）
     * @return array [list => [ref, mvX, mvY]]
     *
     * 依次为 L0、L1 各做一次：邻居集合为 A（左）、B（上）、C（右上/左上），
     * 只有 ref >= 0 的帧间邻居参与「最小参考索引」选择（8.4.1.2.2）。
     *
     * @param int $mbX 宏块列号
     * @param int $mbY 宏块行号
     * @return array [list => [ref, mvX, mvY]]，ref=-1 表示该列表无可用预测
     */
    private function getBDirectNeighborPred(int $mbX, int $mbY): array
    {
        $mbWidth = $this->picWidthInMbs;
        $result = [];
        for ($list = 0; $list < 2; $list++) {
            $left = null;
            $top = null;
            $c = null;
            if ($mbX > 0) {
                // pred_spatial_direct_motion：left_ref = mv_cache[scan8[0]-1] = 左 MB 块 3（索引 0）
                if (isset($this->bMvLeftCol[$list][0])) {
                    $left = $this->bMvLeftCol[$list][0];
                }
            }
            if ($mbY > 0) {
                $top = $this->bMvTopRow[$list][$mbX * 4] ?? null;
                if ($mbX + 1 < $mbWidth) {
                    $c = $this->bMvTopRow[$list][($mbX + 1) * 4] ?? null;
                }
                if ($c === null && $mbX > 0) {
                    $c = $this->bMvTopLeft[$list] ?? null;
                }
            }
            // 有效 ref 最小值（null/intra/不可用邻居忽略）
            $minRef = -1;
            foreach ([$left, $top, $c] as $n) {
                if ($n !== null && $n[2] >= 0 && ($minRef < 0 || $n[2] < $minRef)) {
                    $minRef = $n[2];
                }
            }
            if ($minRef < 0) {
                $result[$list] = [-1, 0, 0];
            } else {
                list($pmx, $pmy) = $this->predictMvP16x16($left, $top, $c, $minRef);
                $result[$list] = [$minRef, $pmx, $pmy];
            }
        }
        return $result;
    }

    /**
     * 同位块（refPicList1[0] 同位置 8x8 子块）的运动信息
     * mv0/mv1 = 8x8 顶左 4x4；mv0c/mv1c = 8x8 远角 4x4（x8*3 + y8*3*b4_stride）
     * ——同位 16x8/8x16 时角点才落在自身分区
     * @return array|null [isIntra, isLongTerm, mbFlags, ref0, mv0, ref1, mv1, mv0c, mv1c]
     *
     * 索引换算：同位宏块内第 i8 个 8x8 子块（0..3），其在 4x4 网格中的左上角为
     *   row = i8>>1、col = i8&1，于是 4x4 序号 = row*8 + col*2；
     * 远角为 8x8 内的第 3 行第 3 列，故为 row*3*4 + col*3（跳过整行，跨 4 个宏块行）。
     *
     * @param int $mbX 当前宏块列号（同位帧使用相同坐标）
     * @param int $mbY 当前宏块行号
     * @param int $i8 8x8 子块序号（0..3）
     * @return array|null 同位信息数组；无 List1 第 0 帧时返回 null
     */
    private function getColocatedBlockInfo(int $mbX, int $mbY, int $i8): ?array
    {
        // 同位帧固定取 refPicList1[0]（8.4.1.2.1）
        $col = $this->refPicList1[0] ?? null;
        if ($col === null) {
            return null;
        }
        // 同位宏块索引（raster）
        $colMbIdx = $mbY * $this->picWidthInMbs + $mbX;
        // 顶左 4x4：每个宏块 16 个 4x4；(i8>>1) 为 8x8 行，(i8&1)*2 为该行内 4x4 列偏移
        $i4 = $colMbIdx * 16 + (($i8 >> 1) * 8 + ($i8 & 1) * 2);
        // 远角 4x4：8x8 内第 3 行第 3 列
        $ic = $colMbIdx * 16 + (($i8 >> 1) * 3) * 4 + ($i8 & 1) * 3;
        return [
            // intra 标志：CABAC I4=0x01/I16=0x02/PCM=0x04；非 CABAC intra 一律 |=1。&7 覆盖两者
            'isIntra' => (bool)($col['mbType'][$colMbIdx] & 7),
            'isLongTerm' => (bool)$col['isLongTerm'],
            'mbFlags' => $col['mbType'][$colMbIdx],
            'ref0' => $col['ref0'][$i4],
            'mv0' => $col['mv0'][$i4],
            'ref1' => $col['ref1'][$i4],
            'mv1' => $col['mv1'][$i4],
            'mv0c' => $col['mv0'][$ic],
            'mv1c' => $col['mv1'][$ic],
        ];
    }

    /**
     * colZero 判定：同位非 intra 且 MV 近零 → 置零 ref==0 方向的 MV
     * （MV 幅度取远角 4x4，
     * colocated 16x16/intra 时远角==顶左，16x8/8x16 时仅远角属于自身分区）
     *
     * 判定条件（8.4.1.2.1）：同位块不 intra、非 long-term，
     * 且某方向的 ref 为 0 而该方向 MV 的两个分量绝对值都不超过 1（1/4 像素）。
     * 满足条件且当前方向的参考索引为 0 时，把 MV 置零。
     *
     * @param array $col 同位块信息（getColocatedBlockInfo 的返回值）
     * @param int $ref 当前方向的参考索引（按引用读取）
     * @param int $mvX MV 水平分量（按引用写回）
     * @param int $mvY MV 垂直分量（按引用写回）
     * @return void
     */
    private function applyColZero(array $col, int &$ref, int &$mvX, int &$mvY): void
    {
        // 同位块 intra 或 long-term 参考：不做 colZero 处理
        if ($col['isIntra'] || $col['isLongTerm']) {
            return;
        }
        // 两种满足条件的情形：L0 的 ref==0，或 L0 未用而 L1 的 ref==0；MV 均需接近零
        $colZero = ($col['ref0'] === 0 && abs($col['mv0c'][0]) <= 1 && abs($col['mv0c'][1]) <= 1)
            || ($col['ref0'] < 0 && $col['ref1'] === 0 && abs($col['mv1c'][0]) <= 1 && abs($col['mv1c'][1]) <= 1);
        // 当前方向参考索引为 0 时，把 MV 强制置零
        if ($colZero && $ref === 0) {
            $mvX = 0;
            $mvY = 0;
        }
    }

    /** temporal direct 的 dist_scale_factor 缓存（每片重置，key=L0 索引） */
    private array $bDistScaleFactor = [];

    /** 隐式加权双预测的 L0 权重缓存（1/64 单位，key=ref0*32+ref1，每片重置） */
    private array $bImplicitWeight = [];

    /**
     * dist_scale_factor[L0[i]]（get_scale_factor）
     * td = clip8(L1[0].poc − L0[i].poc)；td==0 或长参考 → 256
     * 否则 tb=clip8(curPoc−L0[i].poc)，tx=(16384+|td|/2)/td，结果 clip((tb*tx+32)>>6, -512, 511)
     *
     * 用于 temporal direct 的 MV 缩放（8.4.1.2.3）：把同位块的 MV 按时间距离比例
     * 缩放到当前帧到 L0/L1 参考帧的方向。结果按索引缓存，避免同一帧内重复计算。
     *
     * @param int $i List0 中的参考索引
     * @return int dist_scale_factor（若 td==0 或参考为 long-term 则返回 256，即不缩放）
     */
    private function getbDistScaleFactor(int $i): int
    {
        // 命中缓存直接返回
        if (isset($this->bDistScaleFactor[$i])) {
            return $this->bDistScaleFactor[$i];
        }
        // 缺参考帧时按「不缩放」处理
        $l0 = $this->refPicList0[$i] ?? null;
        $l1 = $this->refPicList1[0] ?? null;
        if ($l0 === null || $l1 === null) {
            return 256;
        }
        $poc0 = $l0['poc'];
        // td = L1[0] 与 L0[i] 的 POC 差，按 8bit 有符号范围裁剪
        $td = $l1['poc'] - $poc0;
        if ($td > 127) $td = 127;
        if ($td < -128) $td = -128;
        if ($td === 0 || $l0['isLongTerm']) {
            $s = 256;
        } else {
            $tb = $this->currPicOrderCnt - $poc0;
            if ($tb > 127) $tb = 127;
            if ($tb < -128) $tb = -128;
            $tx = intdiv(16384 + (intdiv(abs($td), 2)), $td);
            $s = ($tb * $tx + 32) >> 6;
            if ($s > 511) $s = 511;
            if ($s < -512) $s = -512;
        }
        $this->bDistScaleFactor[$i] = $s;
        return $s;
    }

    /**
     * 隐式模式双预测的 L0 权重（implicit_weight_table）
     * td = clip_int8(poc1−poc0)；tb = clip_int8(curPoc−poc0)
     * tx = (16384+|td|/2)/td；dsf = (tb*tx+32)>>8；w0 = 64 − dsf（范围 [-64,128] 才生效）
     * 长参考或 td==0 → 32；色度用同一权重
     *
     * 依据 8.4.3.3 的隐式加权预测：w0 越大表示越偏向 List0。
     *
     * @param int $refIdx0 List0 参考索引
     * @param int $refIdx1 List1 参考索引
     * @return int L0 权重 w0（6bit 定点，32 表示等权）
     */
    private function getBImplicitWeight0(int $refIdx0, int $refIdx1): int
    {
        // 缓存键：参考索引组合（假设每个列表的活动参考帧数不超过 32）
        $key = $refIdx0 * 32 + $refIdx1;
        if (isset($this->bImplicitWeight[$key])) {
            return $this->bImplicitWeight[$key];
        }
        $l0 = $this->refPicList0[$refIdx0] ?? null;
        $l1 = $this->refPicList1[$refIdx1] ?? null;
        $w = 32;
        if ($l0 !== null && $l1 !== null && empty($l0['isLongTerm']) && empty($l1['isLongTerm'])) {
            $td = $l1['poc'] - $l0['poc'];
            if ($td > 127) $td = 127;
            if ($td < -128) $td = -128;
            if ($td !== 0) {
                $tb = $this->currPicOrderCnt - $l0['poc'];
                if ($tb > 127) $tb = 127;
                if ($tb < -128) $tb = -128;
                $tx = intdiv(16384 + (abs($td) >> 1), $td);
                $dsf = ($tb * $tx + 32) >> 8;
                if ($dsf >= -64 && $dsf <= 128) {
                    $w = 64 - $dsf;
                }
            }
        }
        $this->bImplicitWeight[$key] = $w;
        return $w;
    }

    /**
     * fill_colmap：把同位帧参考列表的
     * 第 refIdxCol 帧按 frame_num 匹配到当前 L0 的索引；未找到 → 0
     *
     * 用于 temporal direct：同位块的参考索引属于同位帧的参考列表，
     * 必须映射回当前帧的 List0 索引，才能取到对应的 dist_scale_factor。
     *
     * @param int $list 同位块使用的列表（0 = 其 L0，其他 = 其 L1）
     * @param int $refIdxCol 同位块在该列表中的参考索引
     * @return int 当前 List0 中的匹配索引；无匹配或输入非法时返回 0
     */
    private function mapColToList0(int $list, int $refIdxCol): int
    {
        // 同位块未使用该列表：按 0 处理
        if ($refIdxCol < 0) {
            return 0;
        }
        $col = $this->refPicList1[0] ?? null;
        if ($col === null) {
            return 0;
        }
        // 取同位帧对应列表的 frame_num 表（refFns0/refFns1）
        $fns = $list === 0 ? ($col['refFns0'] ?? []) : ($col['refFns1'] ?? []);
        $fn = $fns[$refIdxCol] ?? null;
        if ($fn === null) {
            return 0;
        }
        // 在当前 List0 中按 frame_num 查找同一帧
        foreach ($this->refPicList0 as $j => $e) {
            if ($e['frameNum'] === $fn) {
                return $j;
            }
        }
        return 0;
    }

    /**
     * 单个 8x8 temporal direct 子块（pred_temp_direct_motion）
     * refL1 恒为 0；mvL0 = (scale*mvCol+128)>>8，mvL1 = mvL0 − mvCol
     *
     * 按 direct_8x8_inference_flag（PPS）区分：
     *   - flag=1（B_SUB_8x8）：整个 8x8 使用同位 8x8 顶左 4x4 的 MV；
     *   - flag=0（B_SUB_4x4）：逐 4x4 取同位 MV 分别缩放。
     * 同位块为 intra 或无同位帧时，两个列表都写零 MV、ref=0。
     *
     * @param int $mbX 宏块列号
     * @param int $mbY 宏块行号
     * @param int $i8 8x8 子块序号（0..3）
     * @param array $gridL0 当前宏块的 L0 [MV,ref] 网格（4x4，按引用写回）
     * @param array $gridL1 当前宏块的 L1 [MV,ref] 网格
     * @return void
     */
    private function applyBDirect8x8Temporal(int $mbX, int $mbY, int $i8, array &$gridL0, array &$gridL1): void
    {
        // 子块在宏块 4x4 网格中的左上角：每 8x8 = 2x2 个 4x4
        $baseX = ($i8 & 1) * 2;
        $baseY = ($i8 >> 1) * 2;
        $col = $this->getColocatedBlockInfo($mbX, $mbY, $i8);
        if ($col === null || $col['isIntra']) {
            // 同位块 intra 或缺失：按零 MV、ref=0 填充本 8x8 的 4 个 4x4
            for ($j = 0; $j < 4; $j++) {
                $bx = $baseX + ($j & 1);
                $by = $baseY + ($j >> 1);
                $gridL0[$by][$bx] = [0, 0, 0];
                $gridL1[$by][$bx] = [0, 0, 0];
            }
            return;
        }
        // 同位块的参考索引与 MV（优先 L0，L0 未用时改用 L1）
        $refIdxCol = $col['ref0'];
        // temporal 缩放输入用 8x8 远角 4x4 的 MV（x8*3+y8*3*b4_stride；
        // colocated 16x16/intra 时远角==顶左，单 MV 路径 i8=0 不受影响）
        $mvCol = $col['mv0c'];
        $colList = 0;
        if ($refIdxCol < 0) {
            // 同位块未用 L0 → 用其 L1 信息
            $refIdxCol = $col['ref1'];
            $mvCol = $col['mv1c'];
            $colList = 1;
        }
        if ($refIdxCol < 0) {
            // 同位块两列表均未用（异常）→ 双零
            for ($j = 0; $j < 4; $j++) {
                $bx = $baseX + ($j & 1);
                $by = $baseY + ($j >> 1);
                $gridL0[$by][$bx] = [0, 0, 0];
                $gridL1[$by][$bx] = [0, 0, 0];
            }
            return;
        }
        $ref0 = $this->mapColToList0($colList, $refIdxCol);
        $scale = $this->getbDistScaleFactor($ref0);

        if ($this->direct8x8InferenceFlag) {
            // B_SUB_8x8：用同位 8x8 顶左 4x4 的 MV 填满整个 8x8（getColocatedBlockInfo 即顶左块）
            $mvL0x = ($scale * $mvCol[0] + 128) >> 8;
            $mvL0y = ($scale * $mvCol[1] + 128) >> 8;
            $mvL1x = $mvL0x - $mvCol[0];
            $mvL1y = $mvL0y - $mvCol[1];
            for ($j = 0; $j < 4; $j++) {
                $bx = $baseX + ($j & 1);
                $by = $baseY + ($j >> 1);
                $gridL0[$by][$bx] = [$mvL0x, $mvL0y, $ref0];
                $gridL1[$by][$bx] = [$mvL1x, $mvL1y, 0];
            }
        } else {
            // B_SUB_4x4：同位 4x4 逐块缩放
            $colMbIdx = $mbY * $this->picWidthInMbs + $mbX;
            $colBase = $colMbIdx * 16 + $baseY * 4 + $baseX;
            for ($j = 0; $j < 4; $j++) {
                $bx = $baseX + ($j & 1);
                $by = $baseY + ($j >> 1);
                $i4 = $colBase + ($j >> 1) * 4 + ($j & 1);
                $mv4 = $colList === 0 ? $this->picMv0 : $this->picMv1;
                $c4 = $mv4[$i4] ?? [0, 0];
                $mvL0x = ($scale * $c4[0] + 128) >> 8;
                $mvL0y = ($scale * $c4[1] + 128) >> 8;
                $gridL0[$by][$bx] = [$mvL0x, $mvL0y, $ref0];
                $gridL1[$by][$bx] = [$mvL0x - $c4[0], $mvL0y - $c4[1], 0];
            }
        }
    }

    /**
     * 单个 8x8 直接子块：MB 级邻居预测 + 每子块 colZero
     *
     * spatial direct（8.4.1.2.2）的核心：先用宏块级邻居预测得到 L0/L1 的
     * ref 与 MV，再把 colZeroFlag 为真的方向清零，最后铺满该 8x8 的 4 个 4x4。
     *
     * @param int $mbX 宏块列号
     * @param int $mbY 宏块行号
     * @param int $i8 8x8 子块序号（0..3）
     * @param array $neighborPred getBDirectNeighborPred 的结果 [list => [ref,mvX,mvY]]
     * @param array $gridL0 L0 网格（按引用写回）
     * @param array $gridL1 L1 网格（按引用写回）
     * @return void
     */
    private function applyBDirect8x8(int $mbX, int $mbY, int $i8, array $neighborPred, array &$gridL0, array &$gridL1): void
    {
        // 两个列表各自的参考索引与 MV
        $ref0 = $neighborPred[0][0];
        $mv0x = $neighborPred[0][1];
        $mv0y = $neighborPred[0][2];
        $ref1 = $neighborPred[1][0];
        $mv1x = $neighborPred[1][1];
        $mv1y = $neighborPred[1][2];
        // 两列表都没有可用邻居：退化为零 MV、ref=0（规范中的默认分支）
        if ($ref0 < 0 && $ref1 < 0) {
            $ref0 = $ref1 = 0;
            $mv0x = $mv0y = $mv1x = $mv1y = 0;
        }
        // 逐子块 colZero 判定
        $col = $this->getColocatedBlockInfo($mbX, $mbY, $i8);
        if ($col !== null) {
            $this->applyColZero($col, $ref0, $mv0x, $mv0y);
            $this->applyColZero($col, $ref1, $mv1x, $mv1y);
        }
        // 填充该 8x8 覆盖的 2x2 个 4x4；ref<0 的列表写成 [0,0,-1] 表示不可用
        $baseX = ($i8 & 1) * 2;
        $baseY = ($i8 >> 1) * 2;
        for ($j = 0; $j < 4; $j++) {
            $bx = $baseX + ($j & 1);
            $by = $baseY + ($j >> 1);
            $gridL0[$by][$bx] = $ref0 >= 0 ? [$mv0x, $mv0y, $ref0] : [0, 0, -1];
            $gridL1[$by][$bx] = $ref1 >= 0 ? [$mv1x, $mv1y, $ref1] : [0, 0, -1];
        }
    }

    /**
     * 把 B 宏块 4x4 网格中的边界块写入 MV 预测缓存
     *
     * 供右侧与下方宏块做 MV 预测使用：
     *   - 左列缓存 bMvLeftCol 取网格第 3 列（宏块最右侧 4x4）；
     *   - 上行缓存 bMvTopRow 取网格第 3 行（宏块最下方 4x4），按绝对 4x4 列索引存放。
     * 两个参考列表分别保存。
     *
     * @param int $mbX 宏块列号
     * @param array $gridL0 L0 网格
     * @param array $gridL1 L1 网格
     * @return void
     */
    private function saveBListMvs(int $mbX, array $gridL0, array $gridL1): void
    {
        // 左列：每行取最右侧的 4x4（列 3）
        for ($y = 0; $y < 4; $y++) {
            $this->bMvLeftCol[0][$y] = $gridL0[$y][3];
            $this->bMvLeftCol[1][$y] = $gridL1[$y][3];
        }
        // 上行：取最下方一行（行 3）的 4 个 4x4
        for ($x = 0; $x < 4; $x++) {
            $this->bMvTopRow[0][$mbX * 4 + $x] = $gridL0[3][$x];
            $this->bMvTopRow[1][$mbX * 4 + $x] = $gridL1[3][$x];
        }
    }

    /**
     * 把 B 宏块的逐 4x4 MV/参考索引写入图像级存储（供后续帧做同位块参考）
     *
     * 存储内容：picMv0/picRef0、picMv1/picRef1（每宏块 16 个 4x4），
     * 以及 picMbType 的附加标志位 $flags（用于标识 direct 等类型）。
     * 若四个角的 [MV,ref] 完全一致，说明整个宏块同质，用一次循环共享同一数组，
     * 减少小数组分配（PHP 数组为 COW，只读共享安全）。
     *
     * @param int $mbX 宏块列号
     * @param int $mbY 宏块行号
     * @param array $gridL0 L0 网格
     * @param array $gridL1 L1 网格
     * @param int $flags 追加写入 picMbType 的标志位
     * @return void
     */
    private function storeBPicBlocks(int $mbX, int $mbY, array $gridL0, array $gridL1, int $flags): void
    {
        $mbIdx = $mbY * $this->picWidthInMbs + $mbX;
        // 按位或追加类型标志
        $this->picMbType[$mbIdx] |= $flags;
        // 本宏块 16 个 4x4 的起始索引
        $base = $mbIdx * 16;
        // 每个 8x8 象限内部一致（构造保证），四象限角点相等 ⇒ 全 16 块一致：
        // 全部 4x4 块共享同一 mv 数组 zval（COW），免去每 MB 新建 32 个小数组
        $c00 = $gridL0[0][0];
        $c10 = $gridL1[0][0];
        if ($c00 === $gridL0[0][2] && $c00 === $gridL0[2][0] && $c00 === $gridL0[2][2]
            && $c10 === $gridL1[0][2] && $c10 === $gridL1[2][0] && $c10 === $gridL1[2][2]) {
            $mv0 = [$c00[0], $c00[1]];
            $mv1 = [$c10[0], $c10[1]];
            $r0 = $c00[2];
            $r1 = $c10[2];
            for ($i4 = $base, $end = $base + 16; $i4 < $end; $i4++) {
                $this->picMv0[$i4] = $mv0;
                $this->picRef0[$i4] = $r0;
                $this->picMv1[$i4] = $mv1;
                $this->picRef1[$i4] = $r1;
            }
            return;
        }
        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                $i4 = $base + $y * 4 + $x;
                $l0 = $gridL0[$y][$x];
                $l1 = $gridL1[$y][$x];
                $this->picMv0[$i4] = [$l0[0], $l0[1]];
                $this->picRef0[$i4] = $l0[2];
                $this->picMv1[$i4] = [$l1[0], $l1[1]];
                $this->picRef1[$i4] = $l1[2];
            }
        }
    }

    /**
     * 把 B 宏块的逐 4x4 MV/参考索引写入去块滤波簿记
     *
     * 去块滤波（8.7）需要两侧 4x4 的 MV 与参考索引来判断边界强度 bS：
     * 参考帧不同、MV 差较大或无残差等情形都会影响 bS。此处写入 L0/L1 两套数据。
     * 与 storeBPicBlocks 同样利用四角一致性做共享数组优化。
     *
     * @param int $mbX 宏块列号
     * @param int $mbY 宏块行号
     * @param array $gridL0 L0 网格
     * @param array|null $gridL1 L1 网格（null 表示只有 L0）
     * @return void
     */
    private function storeBDeblockInfo(int $mbX, int $mbY, array $gridL0, ?array $gridL1 = null): void
    {
        // 未开启去块簿记则跳过
        if (!$this->deblockInfoEnabled) {
            return;
        }
        $mbIdx = $mbY * $this->picWidthInMbs + $mbX;
        // 与 storeBPicBlocks 同思路：四象限一致时用 array_fill 共享同一 mv zval，
        // 免去每 MB 新建 4 个 16 项数组 + 32 个 mv 子数组的分配
        $c00 = $gridL0[0][0];
        $c10 = $gridL1[0][0] ?? [0, 0, -1];
        if ($c00 === ($gridL0[0][2] ?? null) && $c00 === ($gridL0[2][0] ?? null) && $c00 === ($gridL0[2][2] ?? null)
            && $c10 === ($gridL1[0][2] ?? null) && $c10 === ($gridL1[2][0] ?? null) && $c10 === ($gridL1[2][2] ?? null)) {
            $this->mbMvForDeblock[$mbIdx] = array_fill(0, 16, [$c00[0], $c00[1]]);
            $this->mbRefForDeblock[$mbIdx] = array_fill(0, 16, $c00[2]);
            $this->mbMvL1ForDeblock[$mbIdx] = array_fill(0, 16, [$c10[0], $c10[1]]);
            $this->mbRefL1ForDeblock[$mbIdx] = array_fill(0, 16, $c10[2]);
            return;
        }
        $mvArr0 = [];
        $refArr0 = [];
        $mvArr1 = [];
        $refArr1 = [];
        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                $g0 = $gridL0[$y][$x];
                $g1 = $gridL1[$y][$x] ?? [0, 0, -1];
                $i = $y * 4 + $x;
                $mvArr0[$i] = [$g0[0], $g0[1]];
                $refArr0[$i] = $g0[2];
                $mvArr1[$i] = [$g1[0], $g1[1]];
                $refArr1[$i] = $g1[2];
            }
        }
        $this->mbMvForDeblock[$mbIdx] = $mvArr0;
        $this->mbRefForDeblock[$mbIdx] = $refArr0;
        $this->mbMvL1ForDeblock[$mbIdx] = $mvArr1;
        $this->mbRefL1ForDeblock[$mbIdx] = $refArr1;
    }

    /**
     * B_Direct_16x16（含 B_Skip）宏块重建
     *
     * 不读任何 MV 语法元素，完全由「直接预测」推导（8.4.1.2）：
     *   - direct_spatial_mv_pred_flag = 0 → temporal direct：按同位帧 POC 距离缩放同位 MV；
     *   - = 1 → spatial direct：用 A/B/C 邻居的最小参考索引 + 中值 MV，再做 colZero 判定。
     * 推导结果写入 4x4 MV 网格，随后按象限做 MC（四象限同质时合并为一次 16x16 MC），
     * 最后写 MV 缓存、图像级 MV 存储与去块簿记。
     *
     * @param int $mbX 宏块列号
     * @param int $mbY 宏块行号
     * @return void
     */
    private function decodeBDirect16x16(int $mbX, int $mbY): void
    {
        // 4x4 网格：每格为 [mvX, mvY, ref]，初值 ref=-1 表示不可用
        $gridL0 = array_fill(0, 4, array_fill(0, 4, [0, 0, -1]));
        $gridL1 = array_fill(0, 4, array_fill(0, 4, [0, 0, -1]));

        // 取同位宏块（i8=0 即 16x16 视角）的信息
        $col = $this->getColocatedBlockInfo($mbX, $mbY, 0);
        if (!$this->directSpatialMvPred) {
            // temporal direct（pred_temp_direct_motion）
            // colocated 16x16_OR_INTRA（flags: 0x08|intra 0x07）→ 整 MB 单一 MV
            if ($col === null || ($col['mbFlags'] & 0x0F) !== 0) {
                $tmpL0 = [];
                $tmpL1 = [];
                $this->applyBDirect8x8Temporal($mbX, $mbY, 0, $tmpL0, $tmpL1);
                // 整 MB 单一 MV：嵌套 array_fill 共享 zval（值与逐格写完全一致）
                $gridL0 = array_fill(0, 4, array_fill(0, 4, $tmpL0[0][0]));
                $gridL1 = array_fill(0, 4, array_fill(0, 4, $tmpL1[0][0]));
            } else {
                for ($i8 = 0; $i8 < 4; $i8++) {
                    $this->applyBDirect8x8Temporal($mbX, $mbY, $i8, $gridL0, $gridL1);
                }
            }
        } else {
            $neighborPred = $this->getBDirectNeighborPred($mbX, $mbY);
            $ref0 = $neighborPred[0][0];
            $mv0x = $neighborPred[0][1];
            $mv0y = $neighborPred[0][2];
            $ref1 = $neighborPred[1][0];
            $mv1x = $neighborPred[1][1];
            $mv1y = $neighborPred[1][2];
            if ($ref0 < 0 && $ref1 < 0) {
                // 双 list 均无有效邻居 → ref=0, mv=0 双向
                $ref0 = $ref1 = 0;
                $mv0x = $mv0y = $mv1x = $mv1y = 0;
            }

            // colZero 粒度跟随同位块分区（pred_spatial_direct_motion）：
            // colocated 16x16_OR_INTRA → 当前保持 B_16x16，MB 级判定（L404-427，
            // 顶左 4x4 == 角点(0,0)）；colocated 16x8/8x16/8x8 → 当前升级 per-8x8，
            // 均匀填充后逐 8x8 独立判定（L428-487，远角 4x4 的 mv）
            if ($col !== null && ($col['mbFlags'] & 0x0F) !== 0) {
                $this->applyColZero($col, $ref0, $mv0x, $mv0y);
                $this->applyColZero($col, $ref1, $mv1x, $mv1y);
            }
            // 全 MB 网格基值唯一（[mv,ref] 三元组）：嵌套 array_fill 让 16 格共享同一
            // 数组 zval（COW），替代逐格数组字面量的 32 次小数组分配；值语义完全一致
            $m0 = $ref0 >= 0 ? [$mv0x, $mv0y, $ref0] : [0, 0, -1];
            $m1 = $ref1 >= 0 ? [$mv1x, $mv1y, $ref1] : [0, 0, -1];
            $gridL0 = array_fill(0, 4, array_fill(0, 4, $m0));
            $gridL1 = array_fill(0, 4, array_fill(0, 4, $m1));
            if ($col !== null && ($col['mbFlags'] & 0x0F) === 0) {
                for ($i8 = 0; $i8 < 4; $i8++) {
                    $c8 = $this->getColocatedBlockInfo($mbX, $mbY, $i8);
                    if ($c8 === null) {
                        break;
                    }
                    $r0 = $ref0;
                    $m0x = $mv0x;
                    $m0y = $mv0y;
                    $r1 = $ref1;
                    $m1x = $mv1x;
                    $m1y = $mv1y;
                    $this->applyColZero($c8, $r0, $m0x, $m0y);
                    $this->applyColZero($c8, $r1, $m1x, $m1y);
                    if ($m0x !== $mv0x || $m0y !== $mv0y || $m1x !== $mv1x || $m1y !== $mv1y) {
                        $bx8 = ($i8 & 1) * 2;
                        $by8 = ($i8 >> 1) * 2;
                        for ($y = $by8; $y < $by8 + 2; $y++) {
                            for ($x = $bx8; $x < $bx8 + 2; $x++) {
                                $gridL0[$y][$x] = [$m0x, $m0y, $r0];
                                $gridL1[$y][$x] = [$m1x, $m1y, $r1];
                            }
                        }
                    }
                }
            }
        }

        // 运动补偿（网格在每个 8x8 内一致）
        // 四象限 (mv,ref) 完全一致时合并为一次 16x16 MC。等价性：luma 相位
        // (dstX*4+mv)&3 与 chroma 相位 (dstX*4+mv)&7 中 dstX 为 8/16 对齐贡献 0，
        // 相位仅由 mv 低位决定，与象限内位置无关；interior/非 interior 两条路径
        // 本身对合并前后逐像素严格等价（clamp 为逐像素独立判定）→ bit 级一致。
        $a0 = $gridL0[0][0];
        $a1 = $gridL1[0][0];
        $merged = $a0 === $gridL0[0][2] && $a0 === $gridL0[2][0] && $a0 === $gridL0[2][2]
            && $a1 === $gridL1[0][2] && $a1 === $gridL1[2][0] && $a1 === $gridL1[2][2];
        if ($merged) {
            $dstX = $mbX * 16;
            $dstY = $mbY * 16;
            if ($a0[2] >= 0 && $a1[2] >= 0) {
                $this->performBiPredBlock($dstX, $dstY, 16, 16, $a0[0], $a0[1], $a0[2], $a1[0], $a1[1], $a1[2]);
            } elseif ($a0[2] >= 0) {
                $this->mcBBlockTo($dstX, $dstY, 16, 16, $a0[0], $a0[1], 0, $a0[2]);
            } elseif ($a1[2] >= 0) {
                $this->mcBBlockTo($dstX, $dstY, 16, 16, $a1[0], $a1[1], 1, $a1[2]);
            }
        } else {
            for ($i8 = 0; $i8 < 4; $i8++) {
                $bx = ($i8 & 1) * 2;
                $by = ($i8 >> 1) * 2;
                $l0 = $gridL0[$by][$bx];
                $l1 = $gridL1[$by][$bx];
                $dstX = $mbX * 16 + $bx * 4;
                $dstY = $mbY * 16 + $by * 4;
                if ($l0[2] >= 0 && $l1[2] >= 0) {
                    $this->performBiPredBlock($dstX, $dstY, 8, 8, $l0[0], $l0[1], $l0[2], $l1[0], $l1[1], $l1[2]);
                } elseif ($l0[2] >= 0) {
                    $this->mcBBlockTo($dstX, $dstY, 8, 8, $l0[0], $l0[1], 0, $l0[2]);
                } elseif ($l1[2] >= 0) {
                    $this->mcBBlockTo($dstX, $dstY, 8, 8, $l1[0], $l1[1], 1, $l1[2]);
                }
            }
        }

        $this->saveBListMvs($mbX, $gridL0, $gridL1);
        $this->storeBPicBlocks($mbX, $mbY, $gridL0, $gridL1, 0x08);
        $this->storeBDeblockInfo($mbX, $mbY, $gridL0, $gridL1);
    }

    /**
     * B 切片帧间宏块解码（mb_type 0..48）
     *
     * 分派规则（Table 7-14，B 切片）：
     *   0        → B_Direct_16x16（direct 预测 + 残差）
     *   1 / 2    → B_L0_16x16 / B_L1_16x16
     *   3        → B_Bi_16x16（双向加权）
     *   4..21    → B_L0/L1/Bi 的 16x8、8x16 双分区（查 bPartSpecs）
     *   22       → B_8x8（4 个 8x8 子宏块）
     *   23..48   → 内嵌 Intra 宏块，mb_type-23 为 I 切片语义
     *   其余     → 灰色填充兜底
     *
     * @param int $mbX 宏块列号
     * @param int $mbY 宏块行号
     * @param int $mbType B 切片 mb_type
     * @param int $sliceQp 当前切片的 SliceQPY
     * @return int mb_qp_delta（帧间为 0，内嵌 intra 时非 0）
     */
    private function decodeBInterMacroblock(int $mbX, int $mbY, int $mbType, int $sliceQp): int
    {
        if ($mbType >= 23 && $mbType <= 48) {
            // B 片内嵌 Intra 宏块：mb_type-23 → I 片语义
            $mbQpDelta = $this->decodeIntraMacroblock($mbX, $mbY, $mbType - 23, $sliceQp);
            // intra 邻居的 MV 标记为未使用（双 list 一致，镜像 P 片 intra 路径）
            $intraMv = [0, 0, -1];
            $this->bMvLeftCol[0] = [$intraMv, $intraMv, $intraMv, $intraMv];
            $this->bMvLeftCol[1] = [$intraMv, $intraMv, $intraMv, $intraMv];
            for ($i = 0; $i < 4; $i++) {
                $this->bMvTopRow[0][$mbX * 4 + $i] = $intraMv;
                $this->bMvTopRow[1][$mbX * 4 + $i] = $intraMv;
            }
            return $mbQpDelta;
        }
        // 注意：B 切片没有 P_Skip 那样的 mb_skip_run 语法，B_Skip 等价于 mb_type=0 且 CBP=0
        switch ($mbType) {
            case 0:
                // B_Direct_16x16：先 direct 推导并做 MC，再读残差
                $this->decodeBDirect16x16($mbX, $mbY);
                return $this->decodeBResidual($mbX, $mbY, $sliceQp);
            case 1:
                return $this->decodeBSingle16x16($mbX, $mbY, $sliceQp, 0);
            case 2:
                return $this->decodeBSingle16x16($mbX, $mbY, $sliceQp, 1);
            case 3:
                return $this->decodeBBi16x16($mbX, $mbY, $sliceQp);
            case 22:
                return $this->decodeB8x8($mbX, $mbY, $sliceQp);
        }
        // 4..21：双分区（16x8 / 8x16）
        if (isset(self::$bPartSpecs[$mbType])) {
            return $this->decodeBBipart($mbX, $mbY, $mbType, $sliceQp);
        }
        // 越界类型：灰色填充
        $this->fillMacroblockGray($mbX, $mbY);
        return 0;
    }

    /**
     * B 切片单向 16x16 宏块（B_L0_16x16 / B_L1_16x16）
     *
     * 语法：ref_idx（按 $list）→ mvd_l0/l1（x、y 各 se(v)）→ 残差。
     * MV = 预测 MV + MVD（8.4.1.3）。
     *
     * @param int $mbX 宏块列号
     * @param int $mbY 宏块行号
     * @param int $sliceQp 当前切片的 SliceQPY
     * @param int $list 使用哪个参考列表（0 = L0，1 = L1）
     * @return int mb_qp_delta（来自残差解码）
     */
    private function decodeBSingle16x16(int $mbX, int $mbY, int $sliceQp, int $list): int
    {
        // 参考索引（7.4.5.1），随后读取 MVD 的两个分量
        $refIdx = $this->readRefIdxB($list);
        $mvdX = $this->reader->readSe();
        $mvdY = $this->reader->readSe();
        // MV 预测 + 残差 MV
        list($predX, $predY) = $this->getB16x16MvPrediction($mbX, $mbY, $list, $refIdx);
        $mvX = $predX + $mvdX;
        $mvY = $predY + $mvdY;
        // 16x16 运动补偿
        $this->mcBBlockTo($mbX * 16, $mbY * 16, 16, 16, $mvX, $mvY, $list, $refIdx);

        // 整个宏块的 16 个 4x4 共享同一 MV/ref：用 array_fill 填充对应列表
        $mv = [$mvX, $mvY, $refIdx];
        $gridL0 = array_fill(0, 4, array_fill(0, 4, [0, 0, -1]));
        $gridL1 = array_fill(0, 4, array_fill(0, 4, [0, 0, -1]));
        if ($list === 0) {
            for ($y = 0; $y < 4; $y++) {
                for ($x = 0; $x < 4; $x++) $gridL0[$y][$x] = $mv;
            }
        } else {
            for ($y = 0; $y < 4; $y++) {
                for ($x = 0; $x < 4; $x++) $gridL1[$y][$x] = $mv;
            }
        }
        // MVP 缓存 / 图像级 MV 存储 / 去块簿记
        $this->saveBListMvs($mbX, $gridL0, $gridL1);
        $this->storeBPicBlocks($mbX, $mbY, $gridL0, $gridL1, 0x08);
        $this->storeBDeblockInfo($mbX, $mbY, $gridL0, $gridL1);
        // 最后读取残差并叠加
        return $this->decodeBResidual($mbX, $mbY, $sliceQp);
    }

    /**
     * B 切片双向 16x16 宏块（B_Bi_16x16）
     *
     * 语法顺序按 list 主序：ref_l0 → ref_l1 → mvd_l0 → mvd_l1（7.3.5.1）。
     * 两个列表各自做 MV 预测后，用 performBiPredBlock 做加权双向预测。
     *
     * @param int $mbX 宏块列号
     * @param int $mbY 宏块行号
     * @param int $sliceQp 当前切片的 SliceQPY
     * @return int mb_qp_delta（来自残差解码）
     */
    private function decodeBBi16x16(int $mbX, int $mbY, int $sliceQp): int
    {
        // 语法顺序：ref L0 → ref L1 → mvd L0 → mvd L1（list 主序）
        $refIdx0 = $this->readRefIdxB(0);
        $refIdx1 = $this->readRefIdxB(1);
        list($predX0, $predY0) = $this->getB16x16MvPrediction($mbX, $mbY, 0, $refIdx0);
        $mvd0X = $this->reader->readSe();
        $mvd0Y = $this->reader->readSe();
        $mv0X = $predX0 + $mvd0X;
        $mv0Y = $predY0 + $mvd0Y;
        list($predX1, $predY1) = $this->getB16x16MvPrediction($mbX, $mbY, 1, $refIdx1);
        $mvd1X = $this->reader->readSe();
        $mvd1Y = $this->reader->readSe();
        $mv1X = $predX1 + $mvd1X;
        $mv1Y = $predY1 + $mvd1Y;

        $this->performBiPredBlock($mbX * 16, $mbY * 16, 16, 16, $mv0X, $mv0Y, $refIdx0, $mv1X, $mv1Y, $refIdx1);

        $gridL0 = array_fill(0, 4, array_fill(0, 4, [$mv0X, $mv0Y, $refIdx0]));
        $gridL1 = array_fill(0, 4, array_fill(0, 4, [$mv1X, $mv1Y, $refIdx1]));
        $this->saveBListMvs($mbX, $gridL0, $gridL1);
        $this->storeBPicBlocks($mbX, $mbY, $gridL0, $gridL1, 0x08);
        $this->storeBDeblockInfo($mbX, $mbY, $gridL0, $gridL1);
        return $this->decodeBResidual($mbX, $mbY, $sliceQp);
    }

    /**
     * B 切片双分区宏块（mb_type 4..21，16x8 或 8x16）
     *
     * 每个宏块由两个分区组成，各分区的预测方向（L0/L1/双向）由 bPartSpecs 给出。
     * 语法读取顺序为 list 主序（先读所有 L0 分区的 ref，再读所有 L0 分区的 mvd，
     * 然后 L1），而不是分区主序——这是 H.264 的 7.3.5.1 规定。
     *
     * @param int $mbX 宏块列号
     * @param int $mbY 宏块行号
     * @param int $mbType mb_type（4..21）
     * @param int $sliceQp 当前切片的 SliceQPY
     * @return int mb_qp_delta（来自残差解码）
     */
    private function decodeBBipart(int $mbX, int $mbY, int $mbType, int $sliceQp): int
    {
        // 规格三元组：layout 决定分区方向（0 = 上下两个 16x8，1 = 左右两个 8x16），
        // dir0/dir1 为两个分区的预测方向（0 = 仅 L0，1 = 仅 L1，2 = 双向预测）
        // 取该 mb_type 的规格：[layout, dir0, dir1]
        $spec = self::$bPartSpecs[$mbType];
        $layout = $spec[0]; // 0=16x8, 1=8x16
        // ref 读取：list 主序，未使用该 list 的分区填 -1；Bi(2) 分区两个 list 都读
        // （IS_DIR 对 Bi 分区双向为真）
        $refs = [[-1, -1], [-1, -1]];
        for ($list = 0; $list < 2; $list++) {
            for ($p = 0; $p < 2; $p++) {
                $dir = $spec[1 + $p];
                if ($dir === $list || $dir === 2) {
                    $refs[$list][$p] = $this->readRefIdxB($list);
                }
            }
        }
        // mvd 读取：list 主序，逐分区 pred + mvd
        $mvs = [[null, null], [null, null]];
        for ($list = 0; $list < 2; $list++) {
            for ($p = 0; $p < 2; $p++) {
                if ($refs[$list][$p] < 0) {
                    continue;
                }
                if ($layout === 0) {
                    list($px, $py) = $this->getB16x8MvPrediction($mbX, $mbY, $p, $list, $refs[$list][$p], $mvs[$list][0]);
                } else {
                    list($px, $py) = $this->getB8x16MvPrediction($mbX, $mbY, $p, $list, $refs[$list][$p], $mvs[$list][0]);
                }
                $mvdX = $this->reader->readSe();
                $mvdY = $this->reader->readSe();
                $mvs[$list][$p] = [$px + $mvdX, $py + $mvdY, $refs[$list][$p]];
            }
        }
        // MC + 网格
        // 每个分区在 4x4 网格中的位置：16x8 → 整宽 4 列 × 2 行；8x16 → 2 列 × 4 行
        $gridL0 = array_fill(0, 4, array_fill(0, 4, [0, 0, -1]));
        $gridL1 = array_fill(0, 4, array_fill(0, 4, [0, 0, -1]));
        for ($p = 0; $p < 2; $p++) {
            $dir = $spec[1 + $p];
            if ($layout === 0) {
                // 上下分区：从第 0 列开始，行偏移 = p*2
                $bx0 = 0;
                $by0 = $p * 2;
                $cols = 4;
                $rows = 2;
            } else {
                // 左右分区：列偏移 = p*2，从第 0 行开始
                $bx0 = $p * 2;
                $by0 = 0;
                $cols = 2;
                $rows = 4;
            }
            // 按方向取出该分区实际使用的 MV（双向时两者都有）
            $mv0 = ($dir === 0 || $dir === 2) ? $mvs[0][$p] : null;
            $mv1 = ($dir === 1 || $dir === 2) ? $mvs[1][$p] : null;
            // 目标像素坐标与块尺寸（4x4 单位 ×4）
            $dstX = $mbX * 16 + $bx0 * 4;
            $dstY = $mbY * 16 + $by0 * 4;
            $w = $cols * 4;
            $h = $rows * 4;
            if ($mv0 !== null && $mv1 !== null) {
                // 双向：两次 MC 后按权重合并
                $this->performBiPredBlock($dstX, $dstY, $w, $h, $mv0[0], $mv0[1], $refs[0][$p], $mv1[0], $mv1[1], $refs[1][$p]);
            } elseif ($mv0 !== null) {
                // 仅 L0
                $this->mcBBlockTo($dstX, $dstY, $w, $h, $mv0[0], $mv0[1], 0, $refs[0][$p]);
            } elseif ($mv1 !== null) {
                // 仅 L1
                $this->mcBBlockTo($dstX, $dstY, $w, $h, $mv1[0], $mv1[1], 1, $refs[1][$p]);
            }
            // 回填该分区覆盖的网格单元
            for ($y = $by0; $y < $by0 + $rows; $y++) {
                for ($x = $bx0; $x < $bx0 + $cols; $x++) {
                    $gridL0[$y][$x] = $mv0 !== null ? [$mv0[0], $mv0[1], $refs[0][$p]] : [0, 0, -1];
                    $gridL1[$y][$x] = $mv1 !== null ? [$mv1[0], $mv1[1], $refs[1][$p]] : [0, 0, -1];
                }
            }
        }
        // MV 预测缓存 + 同位帧存储（按布局写 0x10/0x20 类型位）+ 去块簿记
        $this->saveBListMvs($mbX, $gridL0, $gridL1);
        $this->storeBPicBlocks($mbX, $mbY, $gridL0, $gridL1, $layout === 0 ? 0x10 : 0x20);
        $this->storeBDeblockInfo($mbX, $mbY, $gridL0, $gridL1);
        return $this->decodeBResidual($mbX, $mbY, $sliceQp);
    }

    /**
     * B_8x8 宏块解码（sub_mb_type 0..12）
     *
     * 解析顺序（7.3.5.1）：
     *   1. 4 个 sub_mb_type；
     *   2. 按 list 主序读参考索引：先 L0 的 4 个（跳过 direct 与不使用 L0 的子块），再 L1；
     *   3. 按 list 主序读 MVD，每个子宏块再按分区顺序；
     *   4. 若含 B_Direct_8x8 子块（sub_mb_type=0）：按 direct_spatial_mv_pred_flag
     *      选择 temporal / spatial direct 推导（8.4.1.2），并把结果先写入 MV 缓存，
     *      供同一宏块后面的帧间子块做 MV 预测；
     *   5. 全部 MV 就绪后逐子块做 MC（双向子块调用 performBiPredBlock）；
     *   6. 残差、nz、MV/去块簿记。
     *
     * @param int $mbX 宏块列号
     * @param int $mbY 宏块行号
     * @param int $sliceQp 当前切片的 SliceQPY
     * @return int mb_qp_delta
     */
    private function decodeB8x8(int $mbX, int $mbY, int $sliceQp): int
    {
        // sub_mb_type ×4 → ref（list 主序）→ mvd（list 主序）
        $subTypes = [];
        // 是否含 direct 子块：决定是否需要先做 direct 推导
        $hasDirect = false;
        for ($i = 0; $i < 4; $i++) {
            $subTypes[$i] = $this->reader->readUe();
            if ($subTypes[$i] === 0) {
                $hasDirect = true;
            }
        }

        // 两个 list 的 4x4 MV 网格：null 表示该子块未使用此列表或尚未确定
        $mbMvsL0 = array_fill(0, 4, array_fill(0, 4, null));
        $mbMvsL1 = array_fill(0, 4, array_fill(0, 4, null));
        // 最终网格：ref=-1 表示该列表不可用
        $gridL0 = array_fill(0, 4, array_fill(0, 4, [0, 0, -1]));
        $gridL1 = array_fill(0, 4, array_fill(0, 4, [0, 0, -1]));

        if ($hasDirect) {
            if (!$this->directSpatialMvPred) {
                for ($i = 0; $i < 4; $i++) {
                    if ($subTypes[$i] === 0) {
                        $this->applyBDirect8x8Temporal($mbX, $mbY, $i, $gridL0, $gridL1);
                    }
                }
            } else {
                $neighborPred = $this->getBDirectNeighborPred($mbX, $mbY);
                for ($i = 0; $i < 4; $i++) {
                    if ($subTypes[$i] === 0) {
                        $this->applyBDirect8x8($mbX, $mbY, $i, $neighborPred, $gridL0, $gridL1);
                    }
                }
            }
            // direct 子块 MV 同步进 mbMvs（pred_direct_motion 先行写入 mv_cache），
            // 供同 MB 后续 inter 子块的 MV 预测作邻居
            for ($i = 0; $i < 4; $i++) {
                if ($subTypes[$i] !== 0) {
                    continue;
                }
                $partX = ($i & 1) * 2;
                $partY = ($i >> 1) * 2;
                for ($y = $partY; $y < $partY + 2; $y++) {
                    for ($x = $partX; $x < $partX + 2; $x++) {
                        if ($gridL0[$y][$x][2] >= 0) {
                            $mbMvsL0[$y][$x] = $gridL0[$y][$x];
                        }
                        if ($gridL1[$y][$x][2] >= 0) {
                            $mbMvsL1[$y][$x] = $gridL1[$y][$x];
                        }
                    }
                }
            }
        }

        // ref 读取：list 主序，跳过 direct；不用该 list 的子块填 -1
        $refs = [[-1, -1, -1, -1], [-1, -1, -1, -1]];
        for ($list = 0; $list < 2; $list++) {
            for ($i = 0; $i < 4; $i++) {
                $st = $subTypes[$i];
                if ($st === 0) {
                    continue;
                }
                if ($st >= 0 && $st < 13 && (($list === 0 && self::$bSubUsesL0[$st]) || ($list === 1 && self::$bSubUsesL1[$st]))) {
                    $refs[$list][$i] = $this->readRefIdxB($list);
                }
            }
        }

        // mvd 读取：list 主序，逐子块逐分区 pred + mvd
        for ($list = 0; $list < 2; $list++) {
            for ($i = 0; $i < 4; $i++) {
                $st = $subTypes[$i];
                if ($st === 0 || $refs[$list][$i] < 0) {
                    continue;
                }
                $partCount = self::$bSubPartCount[$st];
                $refIdx = $refs[$list][$i];
                for ($j = 0; $j < $partCount; $j++) {
                    list($bx, $by, $cols, $rows) = $this->bSubPartGeometry($st, $i, $j);
                    list($px, $py) = $this->predictMvP8x8(
                        $mbX, $mbY, $bx, $by, $cols,
                        $list === 0 ? $mbMvsL0 : $mbMvsL1,
                        $this->bMvTopRow[$list], $this->bMvLeftCol[$list], $refIdx, $i
                    );
                    $mvdX = $this->reader->readSe();
                    $mvdY = $this->reader->readSe();
                    $mv = [$px + $mvdX, $py + $mvdY, $refIdx];
                    if ($list === 0) {
                        for ($yy = 0; $yy < $rows; $yy++) {
                            for ($xx = 0; $xx < $cols; $xx++) $mbMvsL0[$by + $yy][$bx + $xx] = $mv;
                        }
                    } else {
                        for ($yy = 0; $yy < $rows; $yy++) {
                            for ($xx = 0; $xx < $cols; $xx++) $mbMvsL1[$by + $yy][$bx + $xx] = $mv;
                        }
                    }
                }
            }
        }

        // 网格回填（direct 子块已在 grid 中，非 direct 从 mbMvs 回填）
        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                if ($mbMvsL0[$y][$x] !== null) $gridL0[$y][$x] = $mbMvsL0[$y][$x];
                if ($mbMvsL1[$y][$x] !== null) $gridL1[$y][$x] = $mbMvsL1[$y][$x];
            }
        }

        // 运动补偿（此时双 list MV 均已就绪）
        for ($i = 0; $i < 4; $i++) {
            $st = $subTypes[$i];
            if ($st < 0 || $st >= 13) {
                continue;
            }
            $partCount = self::$bSubPartCount[$st];
            for ($j = 0; $j < $partCount; $j++) {
                list($bx, $by, $cols, $rows) = $this->bSubPartGeometry($st, $i, $j);
                $l0 = $mbMvsL0[$by][$bx] ?? $gridL0[$by][$bx];
                $l1 = $mbMvsL1[$by][$bx] ?? $gridL1[$by][$bx];
                $dstX = $mbX * 16 + $bx * 4;
                $dstY = $mbY * 16 + $by * 4;
                $w = $cols * 4;
                $h = $rows * 4;
                if ($l0[2] >= 0 && $l1[2] >= 0) {
                    $this->performBiPredBlock($dstX, $dstY, $w, $h, $l0[0], $l0[1], $l0[2], $l1[0], $l1[1], $l1[2]);
                } elseif ($l0[2] >= 0) {
                    $this->mcBBlockTo($dstX, $dstY, $w, $h, $l0[0], $l0[1], 0, $l0[2]);
                } elseif ($l1[2] >= 0) {
                    $this->mcBBlockTo($dstX, $dstY, $w, $h, $l1[0], $l1[1], 1, $l1[2]);
                }
            }
        }

        $this->saveBListMvs($mbX, $gridL0, $gridL1);
        $this->storeBPicBlocks($mbX, $mbY, $gridL0, $gridL1, 0x40);
        $this->storeBDeblockInfo($mbX, $mbY, $gridL0, $gridL1);
        return $this->decodeBResidual($mbX, $mbY, $sliceQp);
    }

    /**
     * B_8x8 子分区几何：[blkX, blkY, cols, rows]（4x4 子块单位；cols 同时是 predictMvP8x8 的 partWidth）
     *
     * $st 为 sub_mb_type（0..12）：
     *   0..3   → 8x8（整个子宏块一个分区）
     *   4/6/8  → 8x4（上下两个分区，按 $j 取行偏移）
     *   5/7/9  → 4x8（左右两个分区，按 $j 取列偏移）
     *   10..12 → 4x4（四个分区，按 $j 的位取行列偏移）
     *
     * @param int $st sub_mb_type
     * @param int $i8 8x8 子块序号（0..3）
     * @param int $j 分区序号
     * @return array [blkX, blkY, cols, rows]，均为宏块内 4x4 网格坐标/尺寸
     */
    private function bSubPartGeometry(int $st, int $i8, int $j): array
    {
        // 子宏块在宏块 4x4 网格中的左上角
        $partX = ($i8 & 1) * 2;
        $partY = ($i8 >> 1) * 2;
        if ($st <= 3) {
            return [$partX, $partY, 2, 2];                                 // 8x8
        }
        if ($st === 4 || $st === 6 || $st === 8) {
            return [$partX, $partY + $j, 2, 1];                            // 8x4
        }
        if ($st === 5 || $st === 7 || $st === 9) {
            return [$partX + $j, $partY, 1, 2];                            // 4x8
        }
        return [$partX + ($j & 1), $partY + ($j >> 1), 1, 1];              // 4x4（st 10-12）
    }

    /**
     * B 切片的残差解码入口
     *
     * 帧间宏块的残差语法（7.3.5.2）：coded_block_pattern → （若 CBP != 0）
     * mb_qp_delta → 各分量的系数。CBP 为 0 时没有 mb_qp_delta，nz 必须清零。
     * CBP 为非帧内查表（Table 9-4 的 inter 版本 GOLOMB_TO_INTER_CBP）。
     *
     * @param int $mbX 宏块列号
     * @param int $mbY 宏块行号
     * @param int $sliceQp 当前切片的 SliceQPY
     * @return int mb_qp_delta（CBP 为 0 时为 0）
     */
    private function decodeBResidual(int $mbX, int $mbY, int $sliceQp): int
    {
        // coded_block_pattern：ue(v) 码字 → CBP（inter 映射表）
        $cbpCode = $this->reader->readUe();
        $codedBlockPattern = self::GOLOMB_TO_INTER_CBP[$cbpCode] ?? 0;
        $mbQpDelta = 0;
        if ($codedBlockPattern !== 0) {
            // 有残差：读 mb_qp_delta 并递推 QP，然后解码并叠加残差
            $mbQpDelta = $this->reader->readSe();
            $qp = $this->wrapQp($sliceQp + $mbQpDelta);
            $this->decodeResidualAndAdd($mbX, $mbY, $codedBlockPattern, $qp, 0);
        } else {
            // 无残差：nz 全部清零
            $this->updateNzCountZero($mbX, $mbY);
        }
        // 传播非 Intra_4x4 的 DC_PRED(2) 邻居模式
        $this->updateInterMbIntraModes($mbX);
        return $mbQpDelta;
    }

    /**
     * B_Skip 宏块
     *
     * B_Skip 语义上等于 B_Direct_16x16 且 CBP=0（无残差、无 mb_qp_delta），
     * 由 mb_skip_run 计数后调用（8.4.1.2）。
     *
     * @param int $mbX 宏块列号
     * @param int $mbY 宏块行号
     * @return void
     */
    private function decodeBSkip(int $mbX, int $mbY): void
    {
        // B_Skip = B_Direct_16x16 无残差
        $this->decodeBDirect16x16($mbX, $mbY);
        $this->updateNzCountZero($mbX, $mbY);
        $this->updateInterMbIntraModes($mbX);
    }

    /**
     * P_L0_16x16 宏块解码
     *
     * 语法（7.3.5.1，P 切片）：ref_idx_l0（可能省略）→ mvd_l0（x、y 各 se(v)）
     * → 16x16 运动补偿 → coded_block_pattern → mb_qp_delta（若 CBP != 0）→ 残差。
     * 最后把 MV/参考索引写入预测缓存与去块簿记。
     *
     * @param int $mbX 宏块列号
     * @param int $mbY 宏块行号
     * @param int $sliceQp 当前切片的 SliceQPY
     * @return int mb_qp_delta
     */
    private function decodePL0_16x16(int $mbX, int $mbY, int $sliceQp): int
    {
        // 参考索引（活动参考帧仅 1 个时不占码流）
        $refIdx = $this->readRefIdxL0();

        // MVD 两个分量（se(v)，带符号）
        $mvdL0X = $this->reader->readSe();
        $mvdL0Y = $this->reader->readSe();

        // MV = 预测 MV + MVD（8.4.1.3）
        list($predMvX, $predMvY) = $this->getP16x16MvPrediction($mbX, $mbY, $refIdx);
        $mvX = $predMvX + $mvdL0X;
        $mvY = $predMvY + $mvdL0Y;

        // 先做 MC，再读 CBP（码流顺序如此）
        $this->performMotionCompensation16x16($mbX, $mbY, $mvX, $mvY, $refIdx);
        $cbpCode = $this->reader->readUe();
        $codedBlockPattern = self::GOLOMB_TO_INTER_CBP[$cbpCode] ?? 0;
        $mbQpDelta = 0;
        if ($codedBlockPattern !== 0) {
            $mbQpDelta = $this->reader->readSe();
            $qp = $this->wrapQp($sliceQp + $mbQpDelta);
            $this->decodeResidualAndAdd($mbX, $mbY, $codedBlockPattern, $qp, 0);
        }
        if ($codedBlockPattern === 0) {
            // 无残差：nz 清零
            $this->updateNzCountZero($mbX, $mbY);
        }
        // 保存 MV 供后续宏块预测
        $this->saveMvForPrediction($mbX, $mbY, $mvX, $mvY, $refIdx);
        $this->updateInterMbIntraModes($mbX);
        $mbWidth = $this->picWidthInMbs;
        $mbIdx = $mbY * $mbWidth + $mbX;
        if ($this->deblockInfoEnabled) {
            $this->mbMvForDeblock[$mbIdx] = array_fill(0, 16, [$mvX, $mvY]);
            $this->mbRefForDeblock[$mbIdx] = array_fill(0, 16, $refIdx);
        }
        return $mbQpDelta;
    }

    /**
     * P_L0_L0_16x8 宏块解码
     *
     * 两个 16x8 分区共享同一参考列表 L0，但各自有 ref_idx 与 MVD。
     * 语法顺序为「分区主序」：先 ref0 → ref1，再 mvd0 → mvd1（7.3.5.1）。
     * 分区 1 的 MV 预测可复用分区 0 的结果（见 getP16x8MvPrediction）。
     *
     * @param int $mbX 宏块列号
     * @param int $mbY 宏块行号
     * @param int $sliceQp 当前切片的 SliceQPY
     * @return int mb_qp_delta
     */
    private function decodePL0_16x8(int $mbX, int $mbY, int $sliceQp): int
    {
        // 两个分区的参考索引
        $refIdx0 = $this->readRefIdxL0();
        $refIdx1 = $this->readRefIdxL0();
        // 两个分区的 MVD
        $mvd0X = $this->reader->readSe();
        $mvd0Y = $this->reader->readSe();
        $mvd1X = $this->reader->readSe();
        $mvd1Y = $this->reader->readSe();

        // 上分区（partIdx=0）的 MV 预测
        list($predMv0X, $predMv0Y) = $this->getP16x8MvPrediction($mbX, $mbY, 0, $refIdx0);
        $mv0X = $predMv0X + $mvd0X;
        $mv0Y = $predMv0Y + $mvd0Y;

        // 下分区（partIdx=1）的 MV 预测可参考上分区结果
        $mv0 = [$mv0X, $mv0Y, $refIdx0];
        list($predMv1X, $predMv1Y) = $this->getP16x8MvPrediction($mbX, $mbY, 1, $refIdx1, $mv0);
        $mv1X = $predMv1X + $mvd1X;
        $mv1Y = $predMv1Y + $mvd1Y;

        // 两个 16x8 分区依次做运动补偿
        $this->performMotionCompensation16x8($mbX, $mbY, 0, $mv0X, $mv0Y, $refIdx0);
        $this->performMotionCompensation16x8($mbX, $mbY, 1, $mv1X, $mv1Y, $refIdx1);

        // 残差相关语法
        $cbpCode = $this->reader->readUe();
        $codedBlockPattern = self::GOLOMB_TO_INTER_CBP[$cbpCode] ?? 0;

        $mbQpDelta = 0;
        if ($codedBlockPattern !== 0) {
            $mbQpDelta = $this->reader->readSe();
            $qp = $this->wrapQp($sliceQp + $mbQpDelta);
            $this->decodeResidualAndAdd($mbX, $mbY, $codedBlockPattern, $qp, 0);
        }

        if ($codedBlockPattern === 0) {
            $this->updateNzCountZero($mbX, $mbY);
        }

        $this->saveMvForPrediction16x8($mbX, $mbY, $mv0X, $mv0Y, $refIdx0, $mv1X, $mv1Y, $refIdx1);

        $this->updateInterMbIntraModes($mbX);

        $mbWidth = $this->picWidthInMbs;
        $mbIdx = $mbY * $mbWidth + $mbX;
        if ($this->deblockInfoEnabled) {
            $this->mbMvForDeblock[$mbIdx] = array_merge(
                array_fill(0, 8, [$mv0X, $mv0Y]),
                array_fill(0, 8, [$mv1X, $mv1Y])
            );
            $this->mbRefForDeblock[$mbIdx] = array_merge(
                array_fill(0, 8, $refIdx0),
                array_fill(0, 8, $refIdx1)
            );
        }

        return $mbQpDelta;
    }

    /**
     * P_L0_L0_8x16 宏块解码
     *
     * 左右两个 8x16 分区，各自 ref_idx 与 MVD。与 16x8 对称，
     * 右分区（partIdx=1）的 MV 预测可复用左分区结果。
     *
     * @param int $mbX 宏块列号
     * @param int $mbY 宏块行号
     * @param int $sliceQp 当前切片的 SliceQPY
     * @return int mb_qp_delta
     */
    private function decodePL0_8x16(int $mbX, int $mbY, int $sliceQp): int
    {

        // 两个分区的参考索引（左、右）
        $refIdx0 = $this->readRefIdxL0();
        $refIdx1 = $this->readRefIdxL0();
        // 两个分区的 MVD
        $mvd0X = $this->reader->readSe();
        $mvd0Y = $this->reader->readSe();
        $mvd1X = $this->reader->readSe();
        $mvd1Y = $this->reader->readSe();

        // 左分区（partIdx=0）MV 预测
        list($predMv0X, $predMv0Y) = $this->getP8x16MvPrediction($mbX, $mbY, 0, $refIdx0);
        $mv0X = $predMv0X + $mvd0X;
        $mv0Y = $predMv0Y + $mvd0Y;

        // 右分区（partIdx=1）MV 预测，可参考左分区
        $mv0 = [$mv0X, $mv0Y, $refIdx0];
        list($predMv1X, $predMv1Y) = $this->getP8x16MvPrediction($mbX, $mbY, 1, $refIdx1, $mv0);
        $mv1X = $predMv1X + $mvd1X;
        $mv1Y = $predMv1Y + $mvd1Y;

        // 左右两个 8x16 分区分别做 MC
        $this->performMotionCompensation8x16($mbX, $mbY, 0, $mv0X, $mv0Y, $refIdx0);
        $this->performMotionCompensation8x16($mbX, $mbY, 1, $mv1X, $mv1Y, $refIdx1);

        // 残差语法与其它帧间宏块一致
        $cbpCode = $this->reader->readUe();
        $codedBlockPattern = self::GOLOMB_TO_INTER_CBP[$cbpCode] ?? 0;
        $mbQpDelta = 0;
        if ($codedBlockPattern !== 0) {
            $mbQpDelta = $this->reader->readSe();
            $qp = $this->wrapQp($sliceQp + $mbQpDelta);
            $this->decodeResidualAndAdd($mbX, $mbY, $codedBlockPattern, $qp, 0);
        }

        if ($codedBlockPattern === 0) {
            $this->updateNzCountZero($mbX, $mbY);
        }

        // 左/右两半分别写入 MV 预测缓存
        $this->saveMvForPrediction8x16($mbX, $mbY, $mv0X, $mv0Y, $refIdx0, $mv1X, $mv1Y, $refIdx1);

        $this->updateInterMbIntraModes($mbX);

        $mbWidth = $this->picWidthInMbs;
        $mbIdx = $mbY * $mbWidth + $mbX;
        if ($this->deblockInfoEnabled) {
            // 下方按子块原地改写，先换成本 MB 私有数组，避免污染帧级共享零数组
            $this->mbMvForDeblock[$mbIdx] = array_fill(0, 16, [0, 0]);
            $this->mbRefForDeblock[$mbIdx] = array_fill(0, 16, 0);
            // 左半 8 个 4x4 用分区 0 的 MV/ref
            foreach (self::$left8x16Blocks as $i) {
                $this->mbMvForDeblock[$mbIdx][$i] = [$mv0X, $mv0Y];
                $this->mbRefForDeblock[$mbIdx][$i] = $refIdx0;
            }
            // 右半 8 个 4x4 用分区 1 的 MV/ref
            foreach (self::$right8x16Blocks as $i) {
                $this->mbMvForDeblock[$mbIdx][$i] = [$mv1X, $mv1Y];
                $this->mbRefForDeblock[$mbIdx][$i] = $refIdx1;
            }
        }

        return $mbQpDelta;
    }

    /**
     * P_8x8 宏块解码
     * 码流顺序: 先所有4个sub_mb_type, 再所有4个ref_idx, 最后所有sub-partition的mvd
     * @return int mb_qp_delta
     *
     * sub_mb_type（Table 7-17）含义：0 = P_L0_8x8、1 = P_L0_8x4、2 = P_L0_4x8、3 = P_L0_4x4，
     * 分别对应 1/2/2/4 个分区。所有 8x8 子块的 MV 都先算完，MC 任务收集到
     * $mcTasks 后由 executeMergedMcTasks 统一执行（合并相邻任务以减少插值开销）。
     *
     * @param int $mbX 宏块列号
     * @param int $mbY 宏块行号
     * @param int $sliceQp 当前切片的 SliceQPY
     * @return int mb_qp_delta
     */
    private function decodeP_8x8(int $mbX, int $mbY, int $sliceQp): int
    {
        // 4x4 MV 网格：每格 [mvX, mvY, ref]，null 表示尚未确定
        $mbMvs = array_fill(0, 4, array_fill(0, 4, null));
        // 待执行的 MC 任务队列：[dstX, dstY, w, h, mvX, mvY, refIdx]
        $mcTasks = [];

        // 8x8 子块 scan 序 → 宏块内 8x8 坐标（0/1）
        $subMbScan = [[0, 0], [1, 0], [0, 1], [1, 1]];

        // 快照左列/上行邻居 MV，避免在循环中反复访问成员
        $leftColMvs = $this->mvLeftCol;
        $topRowMvs = $this->mvTopRow;

        $subMbTypes = [];
        $refIdxs = [];
        $mvds = [];

        // sub_mb_type → 分区个数
        $subPartCount = [1, 2, 2, 4];

        // 1) 先读完 4 个 sub_mb_type
        for ($i = 0; $i < 4; $i++) {
            $subMbTypes[$i] = $this->reader->readUe();
        }

        // 2) 再读完 4 个 ref_idx（P_8x8 每个子宏块一个，且都可能出现在码流中）
        for ($i = 0; $i < 4; $i++) {
            $refIdxs[$i] = $this->readRefIdxL0();
        }

        // 3) 最后按子宏块、分区顺序读 MVD（x、y 各 se(v)）
        for ($i = 0; $i < 4; $i++) {
            $subType = $subMbTypes[$i];
            $partCount = $subPartCount[$subType] ?? 1;
            $mvds[$i] = [];
            for ($j = 0; $j < $partCount; $j++) {
                $mvdX = $this->reader->readSe();
                $mvdY = $this->reader->readSe();
                $mvds[$i][$j] = [$mvdX, $mvdY];
            }
        }

        // 4) 按扫描序处理 4 个 8x8 子宏块，逐分区做 MV 预测并登记 MC 任务
        for ($mbPartIdx = 0; $mbPartIdx < 4; $mbPartIdx++) {
            // 子块所在的 8x8 坐标（0/1），换算到 4x4 网格坐标需乘 2
            $blkX8 = $subMbScan[$mbPartIdx][0];
            $blkY8 = $subMbScan[$mbPartIdx][1];
            $subMbType = $subMbTypes[$mbPartIdx];
            $refIdx = $refIdxs[$mbPartIdx];

            // 4x4 网格中的左上角
            $partX = $blkX8 * 2;
            $partY = $blkY8 * 2;

            // 8x8：一个分区覆盖 2x2 个 4x4
            if ($subMbType === 0) {
                $mvdX = $mvds[$mbPartIdx][0][0];
                $mvdY = $mvds[$mbPartIdx][0][1];

                list($predMvX, $predMvY) = $this->predictMvP8x8(
                    $mbX, $mbY, $partX, $partY, 2,
                    $mbMvs, $topRowMvs, $leftColMvs,
                    $refIdx
                );
                $mvX = $predMvX + $mvdX;
                $mvY = $predMvY + $mvdY;

                $mv = [$mvX, $mvY, $refIdx];
                $mbMvs[$partY][$partX] = $mv;
                $mbMvs[$partY][$partX + 1] = $mv;
                $mbMvs[$partY + 1][$partX] = $mv;
                $mbMvs[$partY + 1][$partX + 1] = $mv;

                $mcTasks[] = [$partX * 4, $partY * 4, 8, 8, $mvX, $mvY, $refIdx];

            } elseif ($subMbType === 1) {
                // 8x4：上下两个分区，各覆盖 2x1 个 4x4
                for ($sub = 0; $sub < 2; $sub++) {
                    $subY = $partY + $sub;
                    $mvdX = $mvds[$mbPartIdx][$sub][0];
                    $mvdY = $mvds[$mbPartIdx][$sub][1];

                    list($predMvX, $predMvY) = $this->predictMvP8x8(
                        $mbX, $mbY, $partX, $subY, 2,
                        $mbMvs, $topRowMvs, $leftColMvs,
                        $refIdx
                    );
                    $mvX = $predMvX + $mvdX;
                    $mvY = $predMvY + $mvdY;

                    $mv = [$mvX, $mvY, $refIdx];
                    $mbMvs[$subY][$partX] = $mv;
                    $mbMvs[$subY][$partX + 1] = $mv;

                    $mcTasks[] = [$partX * 4, $subY * 4, 8, 4, $mvX, $mvY, $refIdx];
                }

            } elseif ($subMbType === 2) {
                // 4x8：左右两个分区，各覆盖 1x2 个 4x4
                for ($sub = 0; $sub < 2; $sub++) {
                    $subX = $partX + $sub;
                    $mvdX = $mvds[$mbPartIdx][$sub][0];
                    $mvdY = $mvds[$mbPartIdx][$sub][1];

                    list($predMvX, $predMvY) = $this->predictMvP8x8(
                        $mbX, $mbY, $subX, $partY, 1,
                        $mbMvs, $topRowMvs, $leftColMvs,
                        $refIdx
                    );
                    $mvX = $predMvX + $mvdX;
                    $mvY = $predMvY + $mvdY;

                    $mv = [$mvX, $mvY, $refIdx];
                    $mbMvs[$partY][$subX] = $mv;
                    $mbMvs[$partY + 1][$subX] = $mv;

                    $mcTasks[] = [$subX * 4, $partY * 4, 4, 8, $mvX, $mvY, $refIdx];
                }

            } elseif ($subMbType === 3) {
                // 4x4：四个分区，按 $sub 的奇偶拆行列
                for ($sub = 0; $sub < 4; $sub++) {
                    $subX = $partX + ($sub % 2);
                    $subY = $partY + (int)($sub / 2);
                    $mvdX = $mvds[$mbPartIdx][$sub][0];
                    $mvdY = $mvds[$mbPartIdx][$sub][1];

                    list($predMvX, $predMvY) = $this->predictMvP8x8(
                        $mbX, $mbY, $subX, $subY, 1,
                        $mbMvs, $topRowMvs, $leftColMvs,
                        $refIdx
                    );
                    $mvX = $predMvX + $mvdX;
                    $mvY = $predMvY + $mvdY;

                    $mbMvs[$subY][$subX] = [$mvX, $mvY, $refIdx];

                    $mcTasks[] = [$subX * 4, $subY * 4, 4, 4, $mvX, $mvY, $refIdx];
                }

            } else {
                break;
            }
        }

        $this->executeMergedMcTasks($mbX, $mbY, $mcTasks);

        $mbQpDelta = 0;
        $cbpCode = $this->reader->readUe();
        $codedBlockPattern = self::GOLOMB_TO_INTER_CBP[$cbpCode] ?? 0;
        if ($codedBlockPattern !== 0) {
            $mbQpDelta = $this->reader->readSe();
            $qp = $this->wrapQp($sliceQp + $mbQpDelta);
            $this->decodeResidualAndAdd($mbX, $mbY, $codedBlockPattern, $qp, 0);
        }

        if ($codedBlockPattern === 0) {
            $this->updateNzCountZero($mbX, $mbY);
        }

        $this->saveMvForPrediction8x8($mbX, $mbY, $mbMvs);

        $this->storeDeblockInfo8x8($mbX, $mbY, $mbMvs);

        $this->updateInterMbIntraModes($mbX);

        return $mbQpDelta;
    }

    /**
     * P_8x8ref0 宏块解码（ref_idx固定为0）
     * 码流顺序: 先所有4个sub_mb_type, 再所有sub-partition的mvd (ref_idx固定为0)
     * 4x4子块粒度，每个子划分独立预测MV
     *
     * 与 decodeP_8x8 的唯一区别是码流中不出现 4 个 ref_idx_l0（7.3.5.1 规定
     * P_8x8ref0 的所有子宏块参考索引恒为 0），其余处理完全相同。
     *
     * @param int $mbX 宏块列号
     * @param int $mbY 宏块行号
     * @param int $sliceQp 当前切片的 SliceQPY
     * @return int mb_qp_delta
     */
    private function decodeP_8x8ref0(int $mbX, int $mbY, int $sliceQp): int
    {
        // 参考索引固定为 0，无需从码流读取
        $refIdx = 0;

        // 4x4 MV 网格与待执行 MC 任务队列
        $mbMvs = array_fill(0, 4, array_fill(0, 4, null));
        $mcTasks = [];

        $subMbScan = [[0, 0], [1, 0], [0, 1], [1, 1]];

        $leftColMvs = $this->mvLeftCol;
        $topRowMvs = $this->mvTopRow;

        $subMbTypes = [];
        $mvds = [];

        $subPartCount = [1, 2, 2, 4];

        // 1) 读取 4 个 sub_mb_type
        for ($i = 0; $i < 4; $i++) {
            $subMbTypes[$i] = $this->reader->readUe();
        }

        // 2) 直接读 MVD（跳过 ref_idx）
        for ($i = 0; $i < 4; $i++) {
            $subType = $subMbTypes[$i];
            $partCount = $subPartCount[$subType] ?? 1;
            $mvds[$i] = [];
            for ($j = 0; $j < $partCount; $j++) {
                $mvdX = $this->reader->readSe();
                $mvdY = $this->reader->readSe();
                $mvds[$i][$j] = [$mvdX, $mvdY];
            }
        }

        for ($mbPartIdx = 0; $mbPartIdx < 4; $mbPartIdx++) {
            $blkX8 = $subMbScan[$mbPartIdx][0];
            $blkY8 = $subMbScan[$mbPartIdx][1];
            $subMbType = $subMbTypes[$mbPartIdx];

            $partX = $blkX8 * 2;
            $partY = $blkY8 * 2;

            if ($subMbType === 0) {
                $mvdX = $mvds[$mbPartIdx][0][0];
                $mvdY = $mvds[$mbPartIdx][0][1];

                list($predMvX, $predMvY) = $this->predictMvP8x8(
                    $mbX, $mbY, $partX, $partY, 2,
                    $mbMvs, $topRowMvs, $leftColMvs,
                    $refIdx
                );
                $mvX = $predMvX + $mvdX;
                $mvY = $predMvY + $mvdY;

                $mv = [$mvX, $mvY, $refIdx];
                $mbMvs[$partY][$partX] = $mv;
                $mbMvs[$partY][$partX + 1] = $mv;
                $mbMvs[$partY + 1][$partX] = $mv;
                $mbMvs[$partY + 1][$partX + 1] = $mv;

                $mcTasks[] = [$partX * 4, $partY * 4, 8, 8, $mvX, $mvY, $refIdx];

            } elseif ($subMbType === 1) {
                // 8x4：上下两个分区，各覆盖 2x1 个 4x4
                for ($sub = 0; $sub < 2; $sub++) {
                    $subY = $partY + $sub;
                    $mvdX = $mvds[$mbPartIdx][$sub][0];
                    $mvdY = $mvds[$mbPartIdx][$sub][1];

                    list($predMvX, $predMvY) = $this->predictMvP8x8(
                        $mbX, $mbY, $partX, $subY, 2,
                        $mbMvs, $topRowMvs, $leftColMvs,
                        $refIdx
                    );
                    $mvX = $predMvX + $mvdX;
                    $mvY = $predMvY + $mvdY;

                    $mv = [$mvX, $mvY, $refIdx];
                    $mbMvs[$subY][$partX] = $mv;
                    $mbMvs[$subY][$partX + 1] = $mv;

                    $mcTasks[] = [$partX * 4, $subY * 4, 8, 4, $mvX, $mvY, $refIdx];
                }

            } elseif ($subMbType === 2) {
                // 4x8：左右两个分区，各覆盖 1x2 个 4x4
                for ($sub = 0; $sub < 2; $sub++) {
                    $subX = $partX + $sub;
                    $mvdX = $mvds[$mbPartIdx][$sub][0];
                    $mvdY = $mvds[$mbPartIdx][$sub][1];

                    list($predMvX, $predMvY) = $this->predictMvP8x8(
                        $mbX, $mbY, $subX, $partY, 1,
                        $mbMvs, $topRowMvs, $leftColMvs,
                        $refIdx
                    );
                    $mvX = $predMvX + $mvdX;
                    $mvY = $predMvY + $mvdY;

                    $mv = [$mvX, $mvY, $refIdx];
                    $mbMvs[$partY][$subX] = $mv;
                    $mbMvs[$partY + 1][$subX] = $mv;

                    $mcTasks[] = [$subX * 4, $partY * 4, 4, 8, $mvX, $mvY, $refIdx];
                }

            } elseif ($subMbType === 3) {
                // 4x4：四个分区，按 $sub 的奇偶拆行列
                for ($sub = 0; $sub < 4; $sub++) {
                    $subX = $partX + ($sub % 2);
                    $subY = $partY + (int)($sub / 2);
                    $mvdX = $mvds[$mbPartIdx][$sub][0];
                    $mvdY = $mvds[$mbPartIdx][$sub][1];

                    list($predMvX, $predMvY) = $this->predictMvP8x8(
                        $mbX, $mbY, $subX, $subY, 1,
                        $mbMvs, $topRowMvs, $leftColMvs,
                        $refIdx
                    );
                    $mvX = $predMvX + $mvdX;
                    $mvY = $predMvY + $mvdY;

                    $mbMvs[$subY][$subX] = [$mvX, $mvY, $refIdx];

                    $mcTasks[] = [$subX * 4, $subY * 4, 4, 4, $mvX, $mvY, $refIdx];
                }

            } else {
                break;
            }
        }

        $this->executeMergedMcTasks($mbX, $mbY, $mcTasks);

        $cbpCode = $this->reader->readUe();
        $codedBlockPattern = self::GOLOMB_TO_INTER_CBP[$cbpCode] ?? 0;
        $mbQpDelta = 0;
        if ($codedBlockPattern !== 0) {
            $mbQpDelta = $this->reader->readSe();
            $qp = $this->wrapQp($sliceQp + $mbQpDelta);
            $this->decodeResidualAndAdd($mbX, $mbY, $codedBlockPattern, $qp, 0);
        }

        if ($codedBlockPattern === 0) {
            $this->updateNzCountZero($mbX, $mbY);
        }

        $this->saveMvForPrediction8x8($mbX, $mbY, $mbMvs);

        $this->storeDeblockInfo8x8($mbX, $mbY, $mbMvs);

        $this->updateInterMbIntraModes($mbX);

        return $mbQpDelta;
    }

    /**
     * 取 List0 指定参考索引对应的像素平面信息
     *
     * 与 getBRefPlanes 的 List0 部分等价：命中 refPicList0 时返回该条目的平面/stride/尺寸，
     * 否则回退到当前参考帧成员（refFrameY/U/V 等），保证 MC 始终有可读平面。
     *
     * @param int $refIdx List0 参考索引
     * @return array 平面与尺寸信息（键名同 getBRefPlanes）
     */
    private function getRefPlanes(int $refIdx): array
    {
        if (isset($this->refPicList0[$refIdx])) {
            $ref = $this->refPicList0[$refIdx];
            return [
                'y' => $ref['y'], 'u' => $ref['u'], 'v' => $ref['v'],
                'yBytes' => $ref['yBytes'], 'uBytes' => $ref['uBytes'], 'vBytes' => $ref['vBytes'],
                'strideY' => $ref['strideY'], 'strideUv' => $ref['strideUv'],
                'widthY' => $ref['widthY'], 'heightY' => $ref['heightY'],
                'widthUv' => $ref['widthUv'], 'heightUv' => $ref['heightUv'],
            ];
        }
        // 列表缺项：退回当前参考帧
        return [
            'y' => $this->refFrameY, 'u' => $this->refFrameU, 'v' => $this->refFrameV,
            'yBytes' => $this->refFrameY, 'uBytes' => $this->refFrameU, 'vBytes' => $this->refFrameV,
            'strideY' => $this->refStrideY, 'strideUv' => $this->refStrideUv,
            'widthY' => $this->refWidthY, 'heightY' => $this->refHeightY,
            'widthUv' => $this->refWidthUv, 'heightUv' => $this->refHeightUv,
        ];
    }

    /**
     * 子像素运动补偿前按需把参考平面展开为字节数组（每参考帧每片最多一次），
     * 避免整像素场景下每帧 3 次整帧 unpack 的纯浪费。写回 refPicList0 供同片后续宏块复用。
     *
     * @param int $refIdx List0 参考索引
     * @param bool $needLuma 是否需要亮度字节串
     * @param bool $needChroma 是否需要色度字节串
     * @return void
     */
    private function ensureRefBytes(int $refIdx, bool $needLuma, bool $needChroma): void
    {
        // 列表无此参考帧：交给 getRefPlanes 的回退路径处理
        if (!isset($this->refPicList0[$refIdx])) return;
        // 本片列表已缓存（同片后续宏块），直接复用
        $listEntry = $this->refPicList0[$refIdx];
        if ((!$needLuma || $listEntry['yBytes'] !== null) && (!$needChroma || ($listEntry['uBytes'] !== null && $listEntry['vBytes'] !== null))) return;
        // 写回 dpb 才能跨 slice 复用（refPicList0 每片重建）；maxNumRefFrames=1，dpb 通常仅 1 条
        foreach ($this->dpb as $k => $entry) {
            if ($entry['frameNum'] !== $listEntry['frameNum'] || $entry['isLongTerm'] !== $listEntry['isLongTerm']) continue;
            // 平面本身就是整数数组，正常构造的 entry 中 *Bytes 已共享同一数组；
            // 此处仅兼容异常流下 *Bytes 缺失的情况（直接别名，无需任何转换）
            if ($needLuma && $entry['yBytes'] === null) {
                $entry['yBytes'] = $entry['y'];
            }
            if ($needChroma && $entry['uBytes'] === null) {
                $entry['uBytes'] = $entry['u'];
            }
            if ($needChroma && $entry['vBytes'] === null) {
                $entry['vBytes'] = $entry['v'];
            }
            $this->dpb[$k] = $entry;
            $this->refPicList0[$refIdx] = $entry;
            return;
        }
        // 兜底：参考帧不在 dpb（异常流）时直接填充本片列表副本
        $entry = &$this->refPicList0[$refIdx];
        if ($needLuma && $entry['yBytes'] === null) $entry['yBytes'] = $entry['y'];
        if ($needChroma) {
            if ($entry['uBytes'] === null) $entry['uBytes'] = $entry['u'];
            if ($entry['vBytes'] === null) $entry['vBytes'] = $entry['v'];
        }
        unset($entry);
    }

    /**
     * 执行 8x8 子宏块的 MC 任务（先按相同 MV/ref 合并矩形，再逐块做运动补偿）
     *
     * 三个步骤：
     *   1. 遍历任务，把每个任务覆盖的 4x4 网格填上 [mvX, mvY, refIdx]，
     *      同时统计每个参考帧是否需要亮度/色度字节串（决定是否要补齐 *Bytes）；
     *   2. 贪心合并：从第一个未使用的网格出发，向右扩展同 MV 的连续块，再逐行向下扩展，
     *      得到最大同质矩形，避免相邻同 MV 分区重复做插值；
     *   3. 对每个合并后的矩形调用 performMotionCompensationBlock，
     *      每个参考索引只取一次平面描述（refCache 缓存）。
     *
     * @param int $mbX 宏块列号
     * @param int $mbY 宏块行号
     * @param array $tasks MC 任务列表，每项为 [dstX, dstY, w, h, mvX, mvY, refIdx]
     *                      （dstX/dstY 为宏块内像素偏移，w/h 为像素尺寸）
     * @return void
     */
    private function executeMergedMcTasks(int $mbX, int $mbY, array $tasks): void
    {
        // 每个参考索引的平面描述缓存
        $refCache = [];
        // 每个参考索引是否需要 [亮度, 色度] 字节串
        $needBytes = [];
        // 4x4 网格上的 MV 归属
        $grid = array_fill(0, 4, array_fill(0, 4, null));
        foreach ($tasks as $task) {
            [$x, $y, $w, $h, $mvX, $mvY, $refIdx] = $task;
            // 换算成 1/4 像素的绝对参考坐标（4x4 单位 ×4）
            $refX4 = ($mbX * 16 + $x) * 4 + $mvX;
            $refY4 = ($mbY * 16 + $y) * 4 + $mvY;
            if (!isset($needBytes[$refIdx])) $needBytes[$refIdx] = [false, false];
            // 低 2 位非 0 → 亮度需要 1/4 像素插值
            if (($refX4 & 3) !== 0 || ($refY4 & 3) !== 0) $needBytes[$refIdx][0] = true;
            // 低 3 位非 0 → 色度（1/8 像素）需要插值
            if (($refX4 & 7) !== 0 || ($refY4 & 7) !== 0) $needBytes[$refIdx][1] = true;
            // 任务覆盖的 4x4 网格范围：像素 / 4
            for ($blockY = intdiv($y, 4); $blockY < intdiv($y + $h, 4); $blockY++) {
                for ($blockX = intdiv($x, 4); $blockX < intdiv($x + $w, 4); $blockX++) {
                    $grid[$blockY][$blockX] = [$mvX, $mvY, $refIdx];
                }
            }
        }

        // 合并阶段的「已被某个矩形覆盖」标记
        $used = array_fill(0, 4, array_fill(0, 4, false));
        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                if ($used[$y][$x] || $grid[$y][$x] === null) continue;

                $mv = $grid[$y][$x];
                // 先向右扩展：只要同行右侧未使用且 MV/ref 完全相同
                $width = 1;
                while ($x + $width < 4 && !$used[$y][$x + $width] && $grid[$y][$x + $width] === $mv) {
                    $width++;
                }

                // 再逐行向下扩展：整行宽度内都必须同 MV 且未使用
                $height = 1;
                while ($y + $height < 4) {
                    for ($i = 0; $i < $width; $i++) {
                        if ($used[$y + $height][$x + $i] || $grid[$y + $height][$x + $i] !== $mv) {
                            break 2;
                        }
                    }
                    $height++;
                }

                // 标记该矩形已覆盖
                for ($j = 0; $j < $height; $j++) {
                    for ($i = 0; $i < $width; $i++) {
                        $used[$y + $j][$x + $i] = true;
                    }
                }

                // 该参考帧首次出现：补齐字节串并缓存平面描述
                if (!isset($refCache[$mv[2]])) {
                    $this->ensureRefBytes($mv[2], $needBytes[$mv[2]][0], $needBytes[$mv[2]][1]);
                    $refCache[$mv[2]] = $this->getRefPlanes($mv[2]);
                }
                $this->performMotionCompensationBlock(
                    $mbX * 16 + $x * 4, $mbY * 16 + $y * 4,
                    $width * 4, $height * 4, $mv[0], $mv[1], $refCache[$mv[2]], $mv[2]
                );
            }
        }
    }

    /**
     * 通用运动补偿：对任意矩形做亮度 + 色度（Cb/Cr 成对）的预测并写入当前帧
     *
     * $ref 既可以是 List0 参考索引（int），也可以是已解析好的参考平面数组，
     * 由 executeMergedMcTasks 的合并路径传入以减少重复的列表查找。
     * 亮度与色度分别判断是否需要亚像素插值（1/4 与 1/8 像素）并按需补齐参考字节串。
     * 若启用了显式加权预测（pred_weight_table，8.4.2.3.1），最后对目标矩形做加权/偏移。
     *
     * @param int $dstX 目标矩形左上角 x（像素，4 的倍数单位语义见调用方）
     * @param int $dstY 目标矩形左上角 y
     * @param int $blockW 块宽（像素）
     * @param int $blockH 块高（像素）
     * @param int $mvX MV 水平分量（1/4 像素）
     * @param int $mvY MV 垂直分量（1/4 像素）
     * @param int|array $ref 参考索引或参考平面描述数组
     * @param int|null $refIdxArg 参考平面数组路径下显式携带的真实 refIdx
     * @return void
     */
    private function performMotionCompensationBlock(int $dstX, int $dstY, int $blockW, int $blockH, int $mvX, int $mvY, int|array $ref, ?int $refIdxArg = null): void
    {
        // 无参考帧直接返回
        if ($this->refFrameY === null) return;
        // 合并任务路径传入的是参考平面数组，真实 refIdx 由 $refIdxArg 携带（显式加权预测按 refIdx 查权重）
        $refIdx = $refIdxArg ?? (is_int($ref) ? $ref : 0);
        // 1/4 像素精度的参考坐标
        $lumaRefX = $dstX * 4 + $mvX;
        $lumaRefY = $dstY * 4 + $mvY;
        // 亮度低 2 位 / 色度低 3 位判断是否需要插值
        $needLuma = ($lumaRefX & 3) !== 0 || ($lumaRefY & 3) !== 0;
        $needChroma = ($lumaRefX & 7) !== 0 || ($lumaRefY & 7) !== 0;
        if (is_int($ref)) {
            // 传索引时：按需补齐字节串并解析平面描述
            $this->ensureRefBytes($ref, $needLuma, $needChroma);
            $ref = $this->getRefPlanes($ref);
        }
        // 亮度 MC（6 抽头插值在 mcLumaTo 内部完成）
        $this->mcLumaTo(
            $ref['y'], $ref['strideY'], $ref['widthY'], $ref['heightY'],
            $lumaRefX, $lumaRefY, $blockW, $blockH,
            $this->yPlane, $this->width, $dstX, $dstY, $ref['yBytes']
        );
        // 色度平面行宽为亮度的一半，块尺寸与目标坐标同样折半
        $chromaStride = intdiv($this->width, 2);
        $this->mcChromaPairTo(
            $ref['u'], $ref['v'], $ref['strideUv'], $ref['widthUv'], $ref['heightUv'],
            $lumaRefX, $lumaRefY, intdiv($blockW, 2), intdiv($blockH, 2),
            $this->uPlane, $this->vPlane, $chromaStride, intdiv($dstX, 2), intdiv($dstY, 2),
            $ref['uBytes'], $ref['vBytes']
        );
        // 显式加权预测（spec 8.4.2.3.1）：P/SP slice 且 pred_weight_table 非默认时应用
        if ($this->pwtActive && isset($this->pwtL0W[$refIdx])) {
            // 默认权重为 1<<pwtLd（亮度）与 1<<pwtCd（色度），默认偏移 0
            $defLumaW = 1 << $this->pwtLd;
            $defChromaW = 1 << $this->pwtCd;
            $lw = $this->pwtL0W[$refIdx]; $lo = $this->pwtL0O[$refIdx];
            // 仅当权重/偏移非默认时才做逐像素加权，省掉恒等变换
            if ($lw !== $defLumaW || $lo !== 0) {
                $this->applyExplicitWeight($this->yPlane, $this->width, $dstX, $dstY, $blockW, $blockH, $lw, $lo, $this->pwtLd);
            }
            // 色度 Cb/Cr 各自独立的权重与偏移
            $cbw = $this->pwtL0CbW[$refIdx]; $cbo = $this->pwtL0CbO[$refIdx];
            $crw = $this->pwtL0CrW[$refIdx]; $cro = $this->pwtL0CrO[$refIdx];
            if ($cbw !== $defChromaW || $cbo !== 0) {
                $this->applyExplicitWeight($this->uPlane, $chromaStride, intdiv($dstX, 2), intdiv($dstY, 2), intdiv($blockW, 2), intdiv($blockH, 2), $cbw, $cbo, $this->pwtCd);
            }
            if ($crw !== $defChromaW || $cro !== 0) {
                $this->applyExplicitWeight($this->vPlane, $chromaStride, intdiv($dstX, 2), intdiv($dstY, 2), intdiv($blockW, 2), intdiv($blockH, 2), $crw, $cro, $this->pwtCd);
            }
        }
    }

    /**
     * 8x8 子块运动补偿：目标坐标 = 宏块基准 + 8x8 网格偏移
     *
     * @param int $mbX 宏块列号
     * @param int $mbY 宏块行号
     * @param int $blkX 8x8 网格列号（0/1）
     * @param int $blkY 8x8 网格行号（0/1）
     * @param int $mvX MV 水平分量（1/4 像素）
     * @param int $mvY MV 垂直分量（1/4 像素）
     * @param int $refIdx List0 参考索引
     * @return void
     */
    private function performMotionCompensation8x8(int $mbX, int $mbY, int $blkX, int $blkY, int $mvX, int $mvY, int $refIdx): void
    {
        $this->performMotionCompensationBlock($mbX * 16 + $blkX * 8, $mbY * 16 + $blkY * 8, 8, 8, $mvX, $mvY, $refIdx);
    }

    /**
     * 8x4 子分区运动补偿：在 8x8 块内按 $subPartIdx 取上下两半
     *
     * @param int $mbX 宏块列号
     * @param int $mbY 宏块行号
     * @param int $blkX 8x8 网格列号
     * @param int $blkY 8x8 网格行号
     * @param int $subPartIdx 子分区序号（0 = 上，1 = 下）
     * @param int $mvX MV 水平分量
     * @param int $mvY MV 垂直分量
     * @param int $refIdx List0 参考索引
     * @return void
     */
    private function performMotionCompensation8x4(int $mbX, int $mbY, int $blkX, int $blkY, int $subPartIdx, int $mvX, int $mvY, int $refIdx): void
    {
        $this->performMotionCompensationBlock($mbX * 16 + $blkX * 8, $mbY * 16 + $blkY * 8 + $subPartIdx * 4, 8, 4, $mvX, $mvY, $refIdx);
    }

    /**
     * 4x8 子分区运动补偿：在 8x8 块内按 $subPartIdx 取左右两半
     *
     * @param int $mbX 宏块列号
     * @param int $mbY 宏块行号
     * @param int $blkX 8x8 网格列号
     * @param int $blkY 8x8 网格行号
     * @param int $subPartIdx 子分区序号（0 = 左，1 = 右）
     * @param int $mvX MV 水平分量
     * @param int $mvY MV 垂直分量
     * @param int $refIdx List0 参考索引
     * @return void
     */
    private function performMotionCompensation4x8(int $mbX, int $mbY, int $blkX, int $blkY, int $subPartIdx, int $mvX, int $mvY, int $refIdx): void
    {
        $this->performMotionCompensationBlock($mbX * 16 + $blkX * 8 + $subPartIdx * 4, $mbY * 16 + $blkY * 8, 4, 8, $mvX, $mvY, $refIdx);
    }

    /**
     * 4x4 子分区运动补偿：$subPartIdx 的 bit0 为列、bit1 为行
     *
     * @param int $mbX 宏块列号
     * @param int $mbY 宏块行号
     * @param int $blkX 8x8 网格列号
     * @param int $blkY 8x8 网格行号
     * @param int $subPartIdx 子分区序号（0..3）
     * @param int $mvX MV 水平分量
     * @param int $mvY MV 垂直分量
     * @param int $refIdx List0 参考索引
     * @return void
     */
    private function performMotionCompensation4x4(int $mbX, int $mbY, int $blkX, int $blkY, int $subPartIdx, int $mvX, int $mvY, int $refIdx): void
    {
        // 2x2 子块网格中的行列
        $subX = $subPartIdx % 2;
        $subY = intdiv($subPartIdx, 2);
        $this->performMotionCompensationBlock($mbX * 16 + $blkX * 8 + $subX * 4, $mbY * 16 + $blkY * 8 + $subY * 4, 4, 4, $mvX, $mvY, $refIdx);
    }

    // ====================== 运动向量预测辅助函数 ======================

    /**
     * 获取P帧16x16宏块的运动向量预测值
     * C邻居（top-right）不可用时，回退到top-left（D邻居）
     *
     * 邻居定义（8.4.1.3）：A = 左邻宏块最右列 4x4 的 MV；B = 上邻宏块最下行 4x4；
     * C = 上邻宏块右下 4x4；D = 左下 4x4（此处 C 不可用时用 mvTopLeft 顶替）。
     *
     * @param int $mbX 宏块列号
     * @param int $mbY 宏块行号
     * @param int $refIdx 当前分区的参考索引（用于中值预测时的同参考判断）
     * @return array [mvX, mvY]（1/4 像素单位）
     */
    private function getP16x16MvPrediction(int $mbX, int $mbY, int $refIdx): array
    {
        // 图像宽度（宏块数），用于判断上邻宏块的最右列是否越界（C 邻居）
        $mbWidth = $this->picWidthInMbs;

        // A/B/C 三个邻居的 MV，null 表示该邻居不可用
        // 每个元素形如 [mvX, mvY, refIdx]，后半段的 [2] 用于同参考判断
        $mvLeft = null;
        $mvTop = null;
        $mvC = null;

        // A 邻居（8.4.1.3）：左邻宏块最右一列 4x4 块。
        // mvLeftCol 按 4x4 行号保存左邻宏块 4 个块的 MV；16x16 分区取第 1 行代表整列，
        // 缺失时退回第 0 行，仍缺失则保持 null（视作不可用）。
        if ($mbX > 0) {
            if (isset($this->mvLeftCol[1])) {
                $mvLeft = $this->mvLeftCol[1];
            } elseif (isset($this->mvLeftCol[0])) {
                $mvLeft = $this->mvLeftCol[0];
            }
        }

        // B 邻居（8.4.1.3）：上邻宏块最底行左侧的 4x4 块，
        // 用绝对 4x4 列号 mbX*4 索引 mvTopRow（每宏块 4 列）
        if ($mbY > 0) {
            $mvTop = $this->mvTopRow[$mbX * 4] ?? null;
        }

        // C邻居：优先top-right，不可用时回退到top-left (D)
        // C = 上邻宏块右下角的 4x4 块；位于图像最右列时越界，改用 D（当前宏块左上 4x4）
        if ($mbY > 0) {
            if ($mbX + 1 < $mbWidth) {
                $mvC = $this->mvTopRow[($mbX + 1) * 4] ?? null;
            }
            if ($mvC === null && $mbX > 0) {
                $mvC = $this->mvTopLeft[0] ?? null;
            }
        }

        // 统一走中值预测：三个邻居都可用取中值；缺一个时用另两个的均值；全缺取 (0,0)
        return $this->predictMvP16x16($mvLeft, $mvTop, $mvC, $refIdx);
    }

    /**
     * 获取P帧Skip宏块的运动向量预测值
     * P_Skip有特殊快速路径：A或B不可用时直接返回(0,0)；A或B为ref=0且mv=0时直接返回(0,0)
     *
     * 对应 8.4.1.1 的推导：Skip 的 MV 预测与普通 16x16 相同（中值），
     * 但多两条快捷规则以减少无意义的中值计算。
     *
     * @param int $mbX 宏块列号
     * @param int $mbY 宏块行号
     * @return array [mvX, mvY]（1/4 像素单位）
     */
    private function getPSkipMvPrediction(int $mbX, int $mbY): array
    {
        // 图像宽度（宏块数），用于 C（上邻右列）邻居的越界判断
        $mbWidth = $this->picWidthInMbs;

        // 与 16x16 相同：先收集 A/B/C 三个邻居的 MV，null = 不可用
        $mvLeft = null;
        $mvTop = null;
        $mvC = null;

        // A 邻居：左邻宏块最右一列 4x4；P_Skip 覆盖整个宏块，取第 1 行（或退回第 0 行）
        if ($mbX > 0) {
            if (isset($this->mvLeftCol[1])) {
                $mvLeft = $this->mvLeftCol[1];
            } elseif (isset($this->mvLeftCol[0])) {
                $mvLeft = $this->mvLeftCol[0];
            }
        }

        // B 邻居：上邻宏块最底行左侧 4x4，绝对列号 mbX*4
        if ($mbY > 0) {
            $mvTop = $this->mvTopRow[$mbX * 4] ?? null;
        }

        // C 邻居：上邻宏块右下 4x4（列号 (mbX+1)*4），越界时回退 D
        if ($mbY > 0) {
            if ($mbX + 1 < $mbWidth) {
                $mvC = $this->mvTopRow[($mbX + 1) * 4] ?? null;
            }
            if ($mvC === null && $mbX > 0) {
                $mvC = $this->mvTopLeft[0] ?? null;
            }
        }

        // P_Skip 专用预测：A/B 不可用或 A/B 均为 ref=0 且 MV=0 时直接 (0,0)，
        // 否则仍按 8.4.1.3 做中值预测
        return $this->predictMvPSkip($mvLeft, $mvTop, $mvC);
    }

    /**
     * 获取P帧16x8宏块的运动向量预测值
     *
     * partIdx=0（上）的 C 与 16x16 相同；partIdx=1（下）的 B 直接取上分区 MV，
     * C 取左邻宏块第 1 行块（见 8.4.1.3 的 pred_part 规则）。
     *
     * @param int $mbX 宏块列号
     * @param int $mbY 宏块行号
     * @param int $partIdx 0=上半部分, 1=下半部分
     * @param int $refIdx 当前分区参考索引
     * @param array|null $mvPart0 上半部分的MV（仅partIdx=1时使用）
     * @return array [mvX, mvY]
     */
    private function getP16x8MvPrediction(int $mbX, int $mbY, int $partIdx, int $refIdx, ?array $mvPart0 = null): array
    {
        // 图像宽度（宏块数），C 邻居越界判断用
        $mbWidth = $this->picWidthInMbs;

        // A/B/C 邻居 MV，null = 不可用；元素为 [mvX, mvY, refIdx]
        $mvLeft = null;
        $mvTop = null;
        $mvC = null;

        // A 邻居：16x8 的两个分区各占 2 行 4x4，
        // 故上分区取左邻 4x4 第 0 行、下分区取第 2 行（partIdx*2）
        if ($mbX > 0) {
            $rowIdx = $partIdx * 2;
            if (isset($this->mvLeftCol[$rowIdx])) {
                $mvLeft = $this->mvLeftCol[$rowIdx];
            }
        }

        // 上分区（partIdx=0）：B/C 取上邻宏块最底行（8.4.1.3）
        if ($partIdx === 0) {
            if ($mbY > 0) {
                $mvTop = $this->mvTopRow[$mbX * 4] ?? null;
            }
            if ($mbX + 1 < $mbWidth && $mbY > 0) {
                $mvC = $this->mvTopRow[($mbX + 1) * 4] ?? null;
            }
            if ($mvC === null && $mbX > 0 && $mbY > 0) {
                $mvC = $this->mvTopLeft[0] ?? null;
            }
        } else {
            // 下分区（partIdx=1）：B 直接用上分区的 MV（分区内部的「上方邻居」），
            // C 取左邻宏块第 1 行 4x4 的 MV
            $mvTop = $mvPart0;
            if ($mbX > 0 && isset($this->mvLeftCol[1])) {
                $mvC = $this->mvLeftCol[1];
            }
        }

        // 快捷规则（8.4.1.3）：若指定邻居与当前分区参考索引相同，直接采用其 MV，跳过中值
        if ($partIdx === 0) {
            if ($mvTop !== null && $mvTop[2] >= 0 && $mvTop[2] === $refIdx) {
                return [$mvTop[0], $mvTop[1]];
            }
        } else {
            if ($mvLeft !== null && $mvLeft[2] >= 0 && $mvLeft[2] === $refIdx) {
                return [$mvLeft[0], $mvLeft[1]];
            }
        }

        // 其余情况统一做 A/B/C 中值预测
        $result = $this->predictMvP16x16($mvLeft, $mvTop, $mvC, $refIdx);
        return $result;
    }

    /**
     * 获取P帧8x16宏块的运动向量预测值
     *
     * partIdx=0（左）的 B/C 取上邻宏块；partIdx=1（右）的 A 直接取左分区 MV，
     * B 取上邻宏块第 2 列的块（见 8.4.1.3）。
     *
     * @param int $mbX 宏块列号
     * @param int $mbY 宏块行号
     * @param int $partIdx 0=左半部分, 1=右半部分
     * @param int $refIdx 当前分区参考索引
     * @param array|null $mvPart0 左半部分的MV（仅partIdx=1时使用）
     * @return array [mvX, mvY]
     */
    private function getP8x16MvPrediction(int $mbX, int $mbY, int $partIdx, int $refIdx, ?array $mvPart0 = null): array
    {
        // 图像宽度（宏块数），用于 C 邻居越界判断
        $mbWidth = $this->picWidthInMbs;

        // A/B/C 邻居 MV，null = 不可用
        $mvLeft = null;
        $mvTop = null;
        $mvC = null;

        // 左分区（partIdx=0）：完整使用 A/B/C 三个外部邻居
        if ($partIdx === 0) {
            if ($mbX > 0) {
                if (isset($this->mvLeftCol[1])) {
                    $mvLeft = $this->mvLeftCol[1];
                } elseif (isset($this->mvLeftCol[0])) {
                    $mvLeft = $this->mvLeftCol[0];
                }
            }
            if ($mbY > 0) {
                // B = top of blk(0,0) → blk(0,3) = mbX*4+0
                $mvTop = $this->mvTopRow[$mbX * 4 + 0] ?? null;
            }
            // C = top-right of part0: blk(0+2,-1) = blk(2,-1) → mbX*4+2
            if ($mbY > 0) {
                $mvC = $this->mvTopRow[$mbX * 4 + 2] ?? null;
            }
            // D fallback: 当前宏块左上方 4x4 块
            if ($mvC === null && $mbX > 0 && $mbY > 0) {
                $mvC = $this->mvTopLeft[0] ?? null;
            }
        } else {
            // 右分区（partIdx=1）：A 直接取左分区 MV；B 取上邻宏块第 2 列 4x4
            $mvLeft = $mvPart0;
            if ($mbY > 0) {
                // B = top of blk(2,0) → blk(2,3) = mbX*4+2
                $mvTop = $this->mvTopRow[$mbX * 4 + 2] ?? null;
            }
            // C = 当前右分区右上角；画面右边界不可用时回退到左上角 D。
            // 此处实现为：先越界回退到上邻宏块第 1 列（mbX*4+1）的 4x4
            if ($mbX + 1 < $mbWidth && $mbY > 0) {
                $mvC = $this->mvTopRow[($mbX + 1) * 4] ?? null;
            }
            if ($mvC === null && $mbY > 0) {
                $mvC = $this->mvTopRow[$mbX * 4 + 1] ?? null;
            }
        }

        // 快捷规则：与当前分区参考索引相同的邻居直接采用，省去中值计算
        if ($partIdx === 0) {
            if ($mvLeft !== null && $mvLeft[2] === $refIdx) {
                return [$mvLeft[0], $mvLeft[1]];
            }
        } else {
            if ($mvC !== null && $mvC[2] === $refIdx) {
                return [$mvC[0], $mvC[1]];
            }
        }

        // 其余情况统一做 A/B/C 中值预测
        $pred = $this->predictMvP16x16($mvLeft, $mvTop, $mvC, $refIdx);
        return $pred;
    }

    /**
     * 保存运动向量用于后续预测（16x16宏块）
     * 每个8x8子块的MV都相同
     */
    private function saveMvForPrediction(int $mbX, int $mbY, int $mvX, int $mvY, int $refIdx): void
    {
        $mv = [$mvX, $mvY, $refIdx];
        $this->mvLeftCol = [$mv, $mv, $mv, $mv];
        $this->mvTopRow[$mbX * 4 + 0] = $mv;
        $this->mvTopRow[$mbX * 4 + 1] = $mv;
        $this->mvTopRow[$mbX * 4 + 2] = $mv;
        $this->mvTopRow[$mbX * 4 + 3] = $mv;
        // 同位帧存储（B 帧 direct 判定）：16x16 → MB_TYPE_16x16
        $mbIdx = $mbY * $this->picWidthInMbs + $mbX;
        $this->picMbType[$mbIdx] |= 0x08;
        $base = $mbIdx * 16;
        $mvPair = [$mvX, $mvY];
        for ($i = 0; $i < 16; $i++) {
            $this->picMv0[$base + $i] = $mvPair;
            $this->picRef0[$base + $i] = $refIdx;
        }
    }

    /**
     * 保存运动向量（16x8宏块）
     * 上下分割：上半部分mv0，下半部分mv1
     *
     * 左列缓存按 4 行 4x4 分别写：上两行 = 上分区 MV，下两行 = 下分区 MV；
     * 上行缓存全部取下分区（宏块最下一行）的 MV。
     * 同时写同位帧存储 picMv0/picRef0，并标记 MB_TYPE_16x8(0x10)。
     *
     * @param int $mbX 宏块列号
     * @param int $mbY 宏块行号
     * @param int $mv0X 上分区 MV 水平分量
     * @param int $mv0Y 上分区 MV 垂直分量
     * @param int $refIdx0 上分区参考索引
     * @param int $mv1X 下分区 MV 水平分量
     * @param int $mv1Y 下分区 MV 垂直分量
     * @param int $refIdx1 下分区参考索引
     * @return void
     */
    private function saveMvForPrediction16x8(int $mbX, int $mbY, int $mv0X, int $mv0Y, int $refIdx0, int $mv1X, int $mv1Y, int $refIdx1): void
    {
        $mvTop = [$mv0X, $mv0Y, $refIdx0];
        $mvBottom = [$mv1X, $mv1Y, $refIdx1];
        $this->mvLeftCol = [$mvTop, $mvTop, $mvBottom, $mvBottom];
        $this->mvTopRow[$mbX * 4 + 0] = $mvBottom;
        $this->mvTopRow[$mbX * 4 + 1] = $mvBottom;
        $this->mvTopRow[$mbX * 4 + 2] = $mvBottom;
        $this->mvTopRow[$mbX * 4 + 3] = $mvBottom;
        // 同位帧存储：16x8 → MB_TYPE_16x8，上 2 行=mv0，下 2 行=mv1
        $mbIdx = $mbY * $this->picWidthInMbs + $mbX;
        $this->picMbType[$mbIdx] |= 0x10;
        $base = $mbIdx * 16;
        $pair0 = [$mv0X, $mv0Y];
        $pair1 = [$mv1X, $mv1Y];
        for ($y = 0; $y < 4; $y++) {
            $pair = $y < 2 ? $pair0 : $pair1;
            $ref = $y < 2 ? $refIdx0 : $refIdx1;
            for ($x = 0; $x < 4; $x++) {
                $this->picMv0[$base + $y * 4 + $x] = $pair;
                $this->picRef0[$base + $y * 4 + $x] = $ref;
            }
        }
    }

    /**
     * 保存运动向量（8x16宏块）
     * 左右分割：左半部分mv0，右半部分mv1
     *
     * 左列缓存全部取右分区（宏块最右列）的 MV；上行缓存按列拆分：
     * 前 2 列 = 左分区，后 2 列 = 右分区。
     * 同时写同位帧存储并标记 MB_TYPE_8x16(0x20)。
     *
     * @param int $mbX 宏块列号
     * @param int $mbY 宏块行号
     * @param int $mv0X 左分区 MV 水平分量
     * @param int $mv0Y 左分区 MV 垂直分量
     * @param int $refIdx0 左分区参考索引
     * @param int $mv1X 右分区 MV 水平分量
     * @param int $mv1Y 右分区 MV 垂直分量
     * @param int $refIdx1 右分区参考索引
     * @return void
     */
    private function saveMvForPrediction8x16(int $mbX, int $mbY, int $mv0X, int $mv0Y, int $refIdx0, int $mv1X, int $mv1Y, int $refIdx1): void
    {
        $mvLeft = [$mv0X, $mv0Y, $refIdx0];
        $mvRight = [$mv1X, $mv1Y, $refIdx1];
        $this->mvLeftCol = [$mvRight, $mvRight, $mvRight, $mvRight];
        $this->mvTopRow[$mbX * 4 + 0] = $mvLeft;
        $this->mvTopRow[$mbX * 4 + 1] = $mvLeft;
        $this->mvTopRow[$mbX * 4 + 2] = $mvRight;
        $this->mvTopRow[$mbX * 4 + 3] = $mvRight;
        // 同位帧存储：8x16 → MB_TYPE_8x16，左 2 列=mv0，右 2 列=mv1
        $mbIdx = $mbY * $this->picWidthInMbs + $mbX;
        $this->picMbType[$mbIdx] |= 0x20;
        $base = $mbIdx * 16;
        $pair0 = [$mv0X, $mv0Y];
        $pair1 = [$mv1X, $mv1Y];
        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                $i4 = $base + $y * 4 + $x;
                $this->picMv0[$i4] = $x < 2 ? $pair0 : $pair1;
                $this->picRef0[$i4] = $x < 2 ? $refIdx0 : $refIdx1;
            }
        }
    }

    /**
     * 保存运动向量（8x8宏块的4x4子块）
     * mbMvs[blkY][blkX] = [mvX, mvY, refIdx] (4x4=16个子块)
     *
     * 左列缓存取每行最右的 4x4（列 3）；上行缓存取最下一行（行 3）的 4 个 4x4。
     * 同位帧存储逐 4x4 写入，并标记 MB_TYPE_8x8(0x40)；未赋值的子块按 [0,0,-1]（不可用）写入。
     *
     * @param int $mbX 宏块列号
     * @param int $mbY 宏块行号
     * @param array $mbMvs 4x4 MV 网格（4 行 × 4 列）
     * @return void
     */
    private function saveMvForPrediction8x8(int $mbX, int $mbY, array $mbMvs): void
    {
        $this->mvLeftCol[0] = $mbMvs[0][3];
        $this->mvLeftCol[1] = $mbMvs[1][3];
        $this->mvLeftCol[2] = $mbMvs[2][3];
        $this->mvLeftCol[3] = $mbMvs[3][3];
        $this->mvTopRow[$mbX * 4 + 0] = $mbMvs[3][0];
        $this->mvTopRow[$mbX * 4 + 1] = $mbMvs[3][1];
        $this->mvTopRow[$mbX * 4 + 2] = $mbMvs[3][2];
        $this->mvTopRow[$mbX * 4 + 3] = $mbMvs[3][3];
        // 同位帧存储：8x8 → MB_TYPE_8x8，逐 4x4 块
        $mbIdx = $mbY * $this->picWidthInMbs + $mbX;
        $this->picMbType[$mbIdx] |= 0x40;
        $base = $mbIdx * 16;
        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                $m = $mbMvs[$y][$x] ?? [0, 0, -1];
                $this->picMv0[$base + $y * 4 + $x] = [$m[0], $m[1]];
                $this->picRef0[$base + $y * 4 + $x] = $m[2];
            }
        }
    }

    /**
     * 保存去块滤波所需的 4x4 运动信息（P_8x8 / P_8x8ref0 共用）。
     * 边界强度按 4x4 子块比较 MV，逐子块写入；未赋值的子块用 (0,0)/ref 0 占位。
     *
     * @param int $mbX 宏块列号
     * @param int $mbY 宏块行号
     * @param array $mbMvs 4x4 MV 网格（元素为 [mvX, mvY, refIdx] 或 null）
     * @return void
     */
    private function storeDeblockInfo8x8(int $mbX, int $mbY, array $mbMvs): void
    {
        if (!$this->deblockInfoEnabled) {
            return;
        }
        $mbIdx = $mbY * $this->picWidthInMbs + $mbX;
        // 先换成本 MB 私有数组，避免污染帧级共享零数组
        // 缺省 [0,0]/0：未被 8x8 子分区覆盖的位置按零 MV、ref 0 参与滤波强度推导
        $this->mbMvForDeblock[$mbIdx] = array_fill(0, 16, [0, 0]);
        $this->mbRefForDeblock[$mbIdx] = array_fill(0, 16, 0);
        // 把 4x4 网格 (y,x) 摊平为 0..15 的块索引写入去块簿记
        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                $mv = $mbMvs[$y][$x];
                if ($mv !== null) {
                    $idx = $y * 4 + $x;
                    // 只取 MV 与 refIdx，丢弃其他附加字段
                    $this->mbMvForDeblock[$mbIdx][$idx] = [$mv[0], $mv[1]];
                    $this->mbRefForDeblock[$mbIdx][$idx] = $mv[2];
                }
            }
        }
    }

    // ====================== 运动补偿辅助函数 ======================

    /**
     * 16x16 分区运动补偿
     *
     * 参考帧缺失（refFrameY 为 null）时不写真实像素，改用灰色填充，
     * 保证帧缓冲完整、后续宏块的预测读取不越界。
     *
     * @param int $mbX 宏块列号
     * @param int $mbY 宏块行号
     * @param int $mvX MV 水平分量（1/4 像素）
     * @param int $mvY MV 垂直分量（1/4 像素）
     * @param int $refIdx List0 参考索引
     * @return void
     */
    private function performMotionCompensation16x16(int $mbX, int $mbY, int $mvX, int $mvY, int $refIdx): void
    {
        // 参考帧尚未建立（首个 P/B 帧等）：无法取像素，整宏块灰色填充占位
        if ($this->refFrameY === null) {
            $this->fillMacroblockGray($mbX, $mbY);
            return;
        }
        // 16x16 覆盖整个宏块：目标原点 = 宏块左上角，尺寸 16x16
        $this->performMotionCompensationBlock($mbX * 16, $mbY * 16, 16, 16, $mvX, $mvY, $refIdx);
    }

    /**
     * 16x8 分区运动补偿（上下两半）
     *
     * @param int $mbX 宏块列号
     * @param int $mbY 宏块行号
     * @param int $partIdx 0 = 上，1 = 下
     * @param int $mvX MV 水平分量
     * @param int $mvY MV 垂直分量
     * @param int $refIdx List0 参考索引
     * @return void
     */
    private function performMotionCompensation16x8(int $mbX, int $mbY, int $partIdx, int $mvX, int $mvY, int $refIdx): void
    {
        // 无参考帧时同样用灰色填充兜底
        if ($this->refFrameY === null) {
            $this->fillMacroblockGray($mbX, $mbY);
            return;
        }
        // 上下分区：行偏移 = partIdx*8，每块尺寸 16x8
        $this->performMotionCompensationBlock($mbX * 16, $mbY * 16 + $partIdx * 8, 16, 8, $mvX, $mvY, $refIdx);
    }

    /**
     * 8x16 分区运动补偿（左右两半）
     *
     * @param int $mbX 宏块列号
     * @param int $mbY 宏块行号
     * @param int $partIdx 0 = 左，1 = 右
     * @param int $mvX MV 水平分量
     * @param int $mvY MV 垂直分量
     * @param int $refIdx List0 参考索引
     * @return void
     */
    private function performMotionCompensation8x16(int $mbX, int $mbY, int $partIdx, int $mvX, int $mvY, int $refIdx): void
    {
        // 无参考帧时灰色填充，保证后续宏块可安全读取该区域
        if ($this->refFrameY === null) {
            $this->fillMacroblockGray($mbX, $mbY);
            return;
        }
        // 左右分区：列偏移 = partIdx*8，每块尺寸 8x16
        $this->performMotionCompensationBlock($mbX * 16 + $partIdx * 8, $mbY * 16, 8, 16, $mvX, $mvY, $refIdx);
    }

    /**
     * 解码残差并加到预测值上（Inter帧方式，与Intra4x4类似）
     *
     * 帧间宏块的残差解码（7.3.5.2 / 8.5）：
     *   1. 从邻居缓存装载 nz（亮度 4 + 色度 4），构造 nC 推导所需的 leftNz/topNz；
     *   2. 亮度：按 CBP 的 4 个 8x8 位逐块读系数（每个 4x4 含 DC+AC 16 个系数）；
     *   3. 色度：先 DC（4 个系数，nC=-1），CBP==2 时再读 AC；
     *   4. 反量化 + IDCT（亮度直接 4x4；色度 DC 走 2x2 逆 Hadamard）；
     *   5. 与已写入的预测值相加并限幅写回，最后更新 nz 缓存供邻居使用。
     *
     * 注意：与 Intra 不同，Inter 宏块没有「亮度 DC 单独一趟」的语法，DC 与 AC 一起编码。
     *
     * @param int $mbX 宏块列号
     * @param int $mbY 宏块行号
     * @param int $codedBlockPattern CBP（低 4 位亮度，高 2 位色度）
     * @param int $qp 已递推的 QPY
     * @param int $mbType 宏块类型（此实现中未使用，保留以对齐调用签名）
     * @return void
     */
    private function decodeResidualAndAdd(int $mbX, int $mbY, int $codedBlockPattern, int $qp, int $mbType): void
    {
        // 静态扫描表：避免每次调用重建同一个 16 元素数组
        static $scanToRasterL = [0, 1, 4, 5, 2, 3, 6, 7, 8, 9, 12, 13, 10, 11, 14, 15];
        $cbp = $codedBlockPattern;
        // 亮度 CBP：bit i 表示第 i 个 8x8 区域有系数
        $lumaCbp = $cbp & 0x0F;
        // 色度 CBP：0 = 无、1 = 仅 DC、2 = DC+AC
        $chromaCbp = ($cbp >> 4) & 0x03;

        $mbWidth = $this->picWidthInMbs;
        // 邻居可用性（图像边界）
        $leftAvailable = ($mbX > 0);
        $topAvailable = ($mbY > 0);

        // nz 计数：本宏块 24 个 4x4 块
        $nzCache = array_fill(0, 24, 0);
        // 左邻 nz：0..3 亮度、4..7 色度
        $leftNz = array_fill(0, 8, 0);
        // 上邻 nz：前 mbWidth*4 为亮度，后 mbWidth*4 为色度（Cb 与 Cr 各占一半）
        $topNz = array_fill(0, $mbWidth * 4 + $mbWidth * 4, 0);

        // 装载左邻 nz
        if ($leftAvailable) {
            for ($y = 0; $y < 4; $y++) $leftNz[$y] = $this->nzLeftColLuma[$y];
            $leftNz[4] = $this->nzLeftColChroma[0];
            $leftNz[5] = $this->nzLeftColChroma[1];
            $leftNz[6] = $this->nzLeftColChroma[2];
            $leftNz[7] = $this->nzLeftColChroma[3];
        }
        // 装载上邻 nz（按绝对 4x4 索引；色度两分量分别偏移）
        if ($topAvailable) {
            $baseLuma = $mbX * 4;
            for ($x = 0; $x < 4; $x++) $topNz[$baseLuma + $x] = $this->nzTopRowLuma[$baseLuma + $x];
            for ($x = 0; $x < 2; $x++) $topNz[$mbWidth * 4 + $mbX * 2 + $x] = $this->nzTopRowChroma[$mbX * 2 + $x];
            for ($x = 0; $x < 2; $x++) $topNz[$mbWidth * 4 + $mbWidth * 2 + $mbX * 2 + $x] = $this->nzTopRowChroma[$mbWidth * 2 + $mbX * 2 + $x];
        }

        // 亮度4x4残差 - lumaCbp==0 时全部 16 块均无残差：nzCache 已全 0，无需填充/扫描
        $yCoeffs = [];
        if ($lumaCbp !== 0) {
            $yCoeffs = array_fill(0, 16, array_fill(0, 16, 0));
            $scanToRaster = $scanToRasterL;

            // 亮度4x4残差 - Inter帧每个块有16个系数（DC+AC一起）
            // 按zigzag扫描顺序解码，每个块计算nC（与Intra4x4相同）
            for ($i8x8 = 0; $i8x8 < 4; $i8x8++) {
                if (($lumaCbp & (1 << $i8x8)) !== 0) {
                    for ($i4x4 = 0; $i4x4 < 4; $i4x4++) {
                        $scanIdx = $i8x8 * 4 + $i4x4;
                        $rasterIdx = $scanToRaster[$scanIdx];
                        $nc = $this->computeNc($rasterIdx, $mbX, $mbY, $nzCache, $leftNz, $topNz, $leftAvailable, $topAvailable);
                        $coeffs = $this->decodeResidualBlock(16, $nc);
                        $nzCount = 0;
                        for ($i = 0; $i < 16; $i++) if ($coeffs[$i] != 0) $nzCount++;
                        $nzCache[$rasterIdx] = $nzCount;
                        // zigzag重排+反量化融合为一步
                        $yCoeffs[$rasterIdx] = $this->dequantZigzag4x4($coeffs, 3, $qp);
                    }
                }
            }
        }

        // 色度（chromaCbp==0 表示色度无任何残差，DC/AC 解码与像素加回全部跳过：
        // nzCache 色度项保持 0，与尾部非零缓存更新语义一致）
        $cbDcResult = [0, 0, 0, 0];
        $crDcResult = [0, 0, 0, 0];
        $cbAcCoeffs = [];
        $crAcCoeffs = [];
        if ($chromaCbp !== 0) {
            $chromaQpIndex = max(0, min(51, $qp + $this->chromaQpIndexOffset));
            $chromaQp = self::CHROMA_QP_TABLE[$chromaQpIndex];

            //$cbDc = array_fill(0, 4, 0);
            //$crDc = array_fill(0, 4, 0);
            $cbAcCoeffs = array_fill(0, 4, array_fill(0, 16, 0));
            $crAcCoeffs = array_fill(0, 4, array_fill(0, 16, 0));

            // 色度 DC 系数（4 个，nC=-1 选择 4:2:0 DC 的固定 VLC 表）
            $cbDc = $this->decodeResidualBlock(4, -1);
            $crDc = $this->decodeResidualBlock(4, -1);

            // 色度 DC 反量化：表 5 = Cb DC、表 4 = Cr DC（表序号沿用 8.5.12 的 list 编号），
            // 均取列表内的第 0 个位置（DC 系数）
            $cbQmul = $this->dequant4Table[5][$chromaQp][0];
            $crQmul = $this->dequant4Table[4][$chromaQp][0];
            // 2x2 逆 Hadamard（8.5.11）+ 反量化，得到 4 个 4x4 块的 DC 值
            $cbDcResult = $this->chromaDcDequantIdct($cbDc, $cbQmul);
            $crDcResult = $this->chromaDcDequantIdct($crDc, $crQmul);

            if ($chromaCbp === 2 || $chromaCbp === 3) {
                $cbScanOrder = [16, 17, 18, 19];
                foreach ($cbScanOrder as $blockIdx) {
                    $blk = $blockIdx - 16;
                    $nc = $this->computeNc($blockIdx, $mbX, $mbY, $nzCache, $leftNz, $topNz, $leftAvailable, $topAvailable);
                    $ac = $this->decodeResidualBlock(15, $nc);
                    $nzCnt = 0; for ($i = 0; $i < 15; $i++) if ($ac[$i] != 0) $nzCnt++;
                    $nzCache[$blockIdx] = $nzCnt;
                    // zigzag重排+反量化融合（coeffs[0]留空给DC）
                    $cbAcCoeffs[$blk] = $this->dequantAcZigzag4x4($ac, 5, $chromaQp);
                }
                $crScanOrder = [20, 21, 22, 23];
                foreach ($crScanOrder as $blockIdx) {
                    $blk = $blockIdx - 20;
                    $nc = $this->computeNc($blockIdx, $mbX, $mbY, $nzCache, $leftNz, $topNz, $leftAvailable, $topAvailable);
                    $ac = $this->decodeResidualBlock(15, $nc);

                    $nzCnt = 0; for ($i = 0; $i < 15; $i++) if ($ac[$i] != 0) $nzCnt++;
                    $nzCache[$blockIdx] = $nzCnt;
                    // zigzag重排+反量化融合（coeffs[0]留空给DC）
                    $crAcCoeffs[$blk] = $this->dequantAcZigzag4x4($ac, 4, $chromaQp);
                }
            }
        }

        // 亮度残差+IDCT 并加到像素上（Inter帧：每个4x4块完整16系数）
        // 整个 16x16 MB 在画面内时，16 个 4x4 块均无需逐像素边界检查
        $lumaFull = (($mbX + 1) * 16 <= $this->width) && (($mbY + 1) * 16 <= $this->height);
        $lumaW = $this->width;
        // 快路径：整宏块在画面内，直接按行展平写入，省去每像素的坐标判断
        if ($lumaFull) {
            for ($blkY = 0; $blkY < 4; $blkY++) {
                for ($blkX = 0; $blkX < 4; $blkX++) {
                    $blk = $blkY * 4 + $blkX;
                    $i8x8 = (int)($blkY / 2) * 2 + (int)($blkX / 2);
                    if (($lumaCbp & (1 << $i8x8)) !== 0) {
                        $idct = $this->idct4x4Flat($yCoeffs[$blk]);
                        $idx = ($mbY * 16 + $blkY * 4) * $lumaW + $mbX * 16 + $blkX * 4;
                        for ($y = 0; $y < 4; $y++) {
                            $row = $y * 4;
                            $this->yPlane[$idx]     = max(0, min(255, $this->yPlane[$idx] + $idct[$row]));
                            $this->yPlane[$idx + 1] = max(0, min(255, $this->yPlane[$idx + 1] + $idct[$row + 1]));
                            $this->yPlane[$idx + 2] = max(0, min(255, $this->yPlane[$idx + 2] + $idct[$row + 2]));
                            $this->yPlane[$idx + 3] = max(0, min(255, $this->yPlane[$idx + 3] + $idct[$row + 3]));
                            $idx += $lumaW;
                        }
                    }
                }
            }
        } else {
            for ($blkY = 0; $blkY < 4; $blkY++) {
                for ($blkX = 0; $blkX < 4; $blkX++) {
                    $blk = $blkY * 4 + $blkX;
                    $i8x8 = (int)($blkY / 2) * 2 + (int)($blkX / 2);
                    if (($lumaCbp & (1 << $i8x8)) !== 0) {
                        $idct = $this->idct4x4Flat($yCoeffs[$blk]);

                        for ($y = 0; $y < 4; $y++) {
                            for ($x = 0; $x < 4; $x++) {
                                $py = $mbY * 16 + $blkY * 4 + $y;
                                $px = $mbX * 16 + $blkX * 4 + $x;
                                if ($py < $this->height && $px < $this->width) {
                                    $idx = $py * $this->width + $px;
                                    $val = $this->yPlane[$idx] + $idct[$y * 4 + $x];
                                    $this->yPlane[$idx] = max(0, min(255, $val));
                                }
                            }
                        }
                    }
                }
            }
        }

        // 色度残差+IDCT 并加到像素上（chromaCbp==0 无色度残差，整段跳过）
        if ($chromaCbp !== 0) {
            $cw = (int)($this->width / 2);
            $ch = (int)($this->height / 2);
            // 整个 8x8 色度 MB 在画面内时，4 个 4x4 块均无需逐像素边界检查
            $chrFull = (($mbX + 1) * 8 <= $cw) && (($mbY + 1) * 8 <= $ch);
            for ($blkY = 0; $blkY < 2; $blkY++) {
                for ($blkX = 0; $blkX < 2; $blkX++) {
                    $blk = $blkY * 2 + $blkX;
                    $dcCb = $cbDcResult[$blk];
                    $dcCr = $crDcResult[$blk];
                    $baseX = $mbX * 8 + $blkX * 4;
                    $baseY = $mbY * 8 + $blkY * 4;

                    // Cb 处理
                    if ($chromaCbp >= 2) {
                        $acBlockCb = $cbAcCoeffs[$blk];
                        $acBlockCb[0] = $dcCb;
                        $acIdctCb = $this->idct4x4Flat($acBlockCb);
                        if ($chrFull) {
                            $idx = $baseY * $cw + $baseX;
                            for ($y = 0; $y < 4; $y++) {
                                $row = $y * 4;
                                $this->uPlane[$idx]     = max(0, min(255, $this->uPlane[$idx] + $acIdctCb[$row]));
                                $this->uPlane[$idx + 1] = max(0, min(255, $this->uPlane[$idx + 1] + $acIdctCb[$row + 1]));
                                $this->uPlane[$idx + 2] = max(0, min(255, $this->uPlane[$idx + 2] + $acIdctCb[$row + 2]));
                                $this->uPlane[$idx + 3] = max(0, min(255, $this->uPlane[$idx + 3] + $acIdctCb[$row + 3]));
                                $idx += $cw;
                            }
                        } else {
                            for ($y = 0; $y < 4; $y++) {
                                for ($x = 0; $x < 4; $x++) {
                                    $py = $baseY + $y;
                                    $px = $baseX + $x;
                                    if ($py < $ch && $px < $cw) {
                                        $idx = $py * $cw + $px;
                                        $val = $this->uPlane[$idx] + $acIdctCb[$y * 4 + $x];
                                        $this->uPlane[$idx] = max(0, min(255, $val));
                                    }
                                }
                            }
                        }
                    } else {
                        // 仅 DC 的模式：DC 已完成逆 Hadamard，再按 (dc + 32) >> 6 归一化，
                        // 然后作为常数加到 4x4 块的所有像素
                        $dcAddCb = ($dcCb + 32) >> 6;
                        if ($chrFull) {
                            $idx = $baseY * $cw + $baseX;
                            for ($y = 0; $y < 4; $y++) {
                                $v0 = max(0, min(255, $this->uPlane[$idx] + $dcAddCb));
                                $v1 = max(0, min(255, $this->uPlane[$idx + 1] + $dcAddCb));
                                $v2 = max(0, min(255, $this->uPlane[$idx + 2] + $dcAddCb));
                                $v3 = max(0, min(255, $this->uPlane[$idx + 3] + $dcAddCb));
                                $this->uPlane[$idx] = $v0;
                                $this->uPlane[$idx + 1] = $v1;
                                $this->uPlane[$idx + 2] = $v2;
                                $this->uPlane[$idx + 3] = $v3;
                                $idx += $cw;
                            }
                        } else {
                            for ($y = 0; $y < 4; $y++) {
                                for ($x = 0; $x < 4; $x++) {
                                    $py = $baseY + $y;
                                    $px = $baseX + $x;
                                    if ($py < $ch && $px < $cw) {
                                        $idx = $py * $cw + $px;
                                        $val = $this->uPlane[$idx] + $dcAddCb;
                                        $this->uPlane[$idx] = max(0, min(255, $val));
                                    }
                                }
                            }
                        }
                    }

                    // Cr 处理
                    if ($chromaCbp >= 2) {
                        $acBlockCr = $crAcCoeffs[$blk];
                        $acBlockCr[0] = $dcCr;
                        $acIdctCr = $this->idct4x4Flat($acBlockCr);
                        if ($chrFull) {
                            $idx = $baseY * $cw + $baseX;
                            for ($y = 0; $y < 4; $y++) {
                                $row = $y * 4;
                                $this->vPlane[$idx]     = max(0, min(255, $this->vPlane[$idx] + $acIdctCr[$row]));
                                $this->vPlane[$idx + 1] = max(0, min(255, $this->vPlane[$idx + 1] + $acIdctCr[$row + 1]));
                                $this->vPlane[$idx + 2] = max(0, min(255, $this->vPlane[$idx + 2] + $acIdctCr[$row + 2]));
                                $this->vPlane[$idx + 3] = max(0, min(255, $this->vPlane[$idx + 3] + $acIdctCr[$row + 3]));
                                $idx += $cw;
                            }
                        } else {
                            for ($y = 0; $y < 4; $y++) {
                                for ($x = 0; $x < 4; $x++) {
                                    $py = $baseY + $y;
                                    $px = $baseX + $x;
                                    if ($py < $ch && $px < $cw) {
                                        $idx = $py * $cw + $px;
                                        $val = $this->vPlane[$idx] + $acIdctCr[$y * 4 + $x];
                                        $this->vPlane[$idx] = max(0, min(255, $val));
                                    }
                                }
                            }
                        }
                    } else {
                        // 仅 DC 的模式（Cr）：归一化后整块相加
                        $dcAddCr = ($dcCr + 32) >> 6;
                        if ($chrFull) {
                            $idx = $baseY * $cw + $baseX;
                            for ($y = 0; $y < 4; $y++) {
                                $v0 = max(0, min(255, $this->vPlane[$idx] + $dcAddCr));
                                $v1 = max(0, min(255, $this->vPlane[$idx + 1] + $dcAddCr));
                                $v2 = max(0, min(255, $this->vPlane[$idx + 2] + $dcAddCr));
                                $v3 = max(0, min(255, $this->vPlane[$idx + 3] + $dcAddCr));
                                $this->vPlane[$idx] = $v0;
                                $this->vPlane[$idx + 1] = $v1;
                                $this->vPlane[$idx + 2] = $v2;
                                $this->vPlane[$idx + 3] = $v3;
                                $idx += $cw;
                            }
                        } else {
                            for ($y = 0; $y < 4; $y++) {
                                for ($x = 0; $x < 4; $x++) {
                                    $py = $baseY + $y;
                                    $px = $baseX + $x;
                                    if ($py < $ch && $px < $cw) {
                                        $idx = $py * $cw + $px;
                                        $val = $this->vPlane[$idx] + $dcAddCr;
                                        $this->vPlane[$idx] = max(0, min(255, $val));
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        // 更新非零系数缓存
        // 亮度上行取宏块最下一行 4 个 4x4（raster 12..15），左列取每行最右（3/7/11/15）
        $baseLuma = $mbX * 4;
        for ($x = 0; $x < 4; $x++) $this->nzTopRowLuma[$baseLuma + $x] = $nzCache[12 + $x];
        $this->nzLeftColLuma[0] = $nzCache[3];
        $this->nzLeftColLuma[1] = $nzCache[7];
        $this->nzLeftColLuma[2] = $nzCache[11];
        $this->nzLeftColLuma[3] = $nzCache[15];

        // 色度上行：Cb 取 18/19（下排），Cr 取 22/23；左列：Cb 17/19、Cr 21/23（各取右列）
        $this->nzTopRowChroma[$mbX * 2 + 0] = $nzCache[18];
        $this->nzTopRowChroma[$mbX * 2 + 1] = $nzCache[19];
        $this->nzTopRowChroma[$mbWidth * 2 + $mbX * 2 + 0] = $nzCache[22];
        $this->nzTopRowChroma[$mbWidth * 2 + $mbX * 2 + 1] = $nzCache[23];
        $this->nzLeftColChroma[0] = $nzCache[17];
        $this->nzLeftColChroma[1] = $nzCache[19];
        $this->nzLeftColChroma[2] = $nzCache[21];
        $this->nzLeftColChroma[3] = $nzCache[23];

        // 去块滤波簿记：保存本宏块 24 个 4x4 的 nz 计数（8.7 的 bS 需要）
        $mbIdx = $mbY * $this->picWidthInMbs + $mbX;
        if ($this->deblockInfoEnabled) $this->mbNnzForDeblock[$mbIdx] = $nzCache;
    }
}
