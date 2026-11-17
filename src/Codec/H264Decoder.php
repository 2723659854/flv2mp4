<?php

/**
 * H.264 / MPEG-4 AVC 解码器主类（纯 PHP 实现）。
 *
 * 职责概览：
 *  - NAL 调度：从 NalUtil::splitNalUnits() 的结果中挑出 SPS(7)/PPS(8)/slice(1/5)
 *    （规范 7.4.1 的 nal_unit_type 语义，见表 7-1）；
 *  - 参数集：SPS（7.3.2.1.1）与 PPS（7.3.2.2），具体语法由 SpsPpsParsingTrait 解析；
 *  - 片解码：slice header / slice data（7.3.3、7.3.4），由 SliceDecodingTrait 等各 trait 完成；
 *  - 参考图像：DPB、参考列表构造（8.2.4）、滑动窗口与 MMCO（8.2.5、8.2.5.3）；
 *  - POC 计算（8.2.1）与按显示序重排输出；
 *  - 输出组装：把宏块对齐的 YUV420p 平面裁剪并打包成二进制帧数据。
 */
namespace Xiaosongshu\Flv2mp4\Codec;

// 逐位读取器：按 bit 粒度读取 RBSP 语法元素（规范 7.2 描述的 RBSP 比特流）
use Xiaosongshu\Flv2mp4\Codec\Decode\BitReader;
// CABAC 熵解码引擎（规范 9.3）
use Xiaosongshu\Flv2mp4\Codec\Decode\CabacDecodingTrait;
// CABAC 上下文初始化表 / 上下文模型表
use Xiaosongshu\Flv2mp4\Codec\Decode\CabacTablesTrait;
// 去块滤波（规范 8.7）
use Xiaosongshu\Flv2mp4\Codec\Decode\DeblockingFilterTrait;
// 帧内预测（规范 8.3.1）
use Xiaosongshu\Flv2mp4\Codec\Decode\IntraPredictionTrait;
// 宏块层语法解析（规范 7.3.5、7.4.5）
use Xiaosongshu\Flv2mp4\Codec\Decode\MacroblockDecodingTrait;
// 运动补偿 / 分数像素插值（规范 8.4.2）
use Xiaosongshu\Flv2mp4\Codec\Decode\MotionCompensationTrait;
// MV 预测（规范 8.4.1）
use Xiaosongshu\Flv2mp4\Codec\Decode\MotionVectorPredictionTrait;
// 残差解码：CAVLC(9.2) / CABAC(9.3) 的系数解析与反扫描
use Xiaosongshu\Flv2mp4\Codec\Decode\ResidualDecodingTrait;
// 片层解析与解码主流程（规范 7.3.3、7.3.4）
use Xiaosongshu\Flv2mp4\Codec\Decode\SliceDecodingTrait;
// SPS/PPS 解析（规范 7.3.2.1.1、7.3.2.2）
use Xiaosongshu\Flv2mp4\Codec\Decode\SpsPpsParsingTrait;
// 反量化与整数变换（规范 8.5）
use Xiaosongshu\Flv2mp4\Codec\Decode\TransformTrait;

/**
 * @purpose 视频解码器 - 支持 baseline/main/high profile (I/P/B帧, CAVLC/CABAC)
 * @author yanglong
 * @time 2026年7月23日14:41:47
 *
 * 解码流程（与规范对应）：
 *  1. parseSPS/parsePPS 建立序列与图像参数集；
 *  2. decodeSlice 解析 slice header（7.3.3）并解码 slice data（7.3.4）；
 *  3. 每帧结束后做参考标记（8.2.5）、更新 POC 参考状态（8.2.1.1）；
 *  4. 按 POC 重排后输出 YUV420p。
 * 所有 `use` 进来的 trait 共享 $this 的成员，因此本类属性即为解码器的全局状态。
 */
class H264Decoder
{
    // 各 trait 分别负责参数集、片、宏块、帧内预测、残差、变换、去块、运动补偿、MV 预测、CABAC 表与 CABAC 引擎
    use SpsPpsParsingTrait, SliceDecodingTrait, MacroblockDecodingTrait, IntraPredictionTrait, ResidualDecodingTrait, TransformTrait, DeblockingFilterTrait, MotionCompensationTrait, MotionVectorPredictionTrait, CabacTablesTrait, CabacDecodingTrait;

    // 特殊值：表示相邻块“不可用”（规范 6.4.11 邻域可用性判定；用于 intra 预测与 nC 推导）
    const PART_NOT_AVAILABLE = -2;
    // 去块滤波边界强度阈值 α 表：按 indexA（0..51）查表（规范 8.7.2.2 表 8-16 的 α 值）
    private const ALPHA_TABLE = [
        0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
        4, 4, 5, 6, 7, 8, 9, 10, 12, 13, 15, 17, 20, 22,
        25, 28, 32, 36, 40, 45, 50, 56, 63, 71, 80, 90,
        101, 113, 127, 144, 162, 182, 203, 226, 255, 255,
    ];

    // 去块滤波阈值 β 表：按 indexB（0..51）查表（规范 8.7.2.2 表 8-17 的 β 值）
    private const BETA_TABLE = [
        0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
        2, 2, 2, 3, 3, 3, 3, 4, 4, 4, 6, 6, 7, 7, 8, 8,
        9, 9, 10, 10, 11, 11, 12, 12, 13, 13, 14, 14,
        15, 15, 16, 16, 17, 17, 18, 18,
    ];

    // 去块滤波削波阈值 tc0 表：下标为 indexA（0..51），内层 3 个值按 bS 的“量化”档位选取
    // （规范 8.7.2.2 表 8-18 中 tc0 与 bS 的对应关系：bS=1/2/3 三列）
    private const TC0_TABLE = [
        [0, 0, 0], [0, 0, 0], [0, 0, 0], [0, 0, 0], [0, 0, 0], [0, 0, 0],
        [0, 0, 0], [0, 0, 0], [0, 0, 0], [0, 0, 0], [0, 0, 0], [0, 0, 0],
        [0, 0, 0], [0, 0, 0], [0, 0, 0], [0, 0, 0], [0, 0, 0], [0, 0, 1],
        [0, 0, 1], [0, 0, 1], [0, 0, 1], [0, 1, 1], [0, 1, 1], [1, 1, 1],
        [1, 1, 1], [1, 1, 1], [1, 1, 1], [1, 1, 2], [1, 1, 2], [1, 1, 2],
        [1, 1, 2], [1, 2, 3], [1, 2, 3], [2, 2, 3], [2, 2, 4], [2, 3, 4],
        [2, 3, 4], [3, 3, 5], [3, 4, 6], [3, 4, 6], [4, 5, 7], [4, 5, 8],
        [4, 6, 9], [5, 7, 10], [6, 8, 11], [6, 8, 13], [7, 10, 14], [8, 11, 16],
        [9, 12, 18], [10, 13, 20], [11, 15, 23], [13, 17, 25],
    ];

    // 调试开关：为 true 时打印残差系数相关中间量
    public $debugResidual = false;
    // RBSP 位读取器：每个 NAL 解析前由调用方重置到相应 RBSP 起点
    public BitReader $reader;

    // 当前图像显示宽度（裁剪到真实尺寸，非宏块对齐尺寸）
    public int $width = 0;
    // 当前图像显示高度（同上）
    public int $height = 0;
    // 图像宽度包含的宏块数：PicWidthInMbs = ceil(width / 16)（规范 7.4.2.1.1）
    public int $picWidthInMbs = 0;
    // 图像高度包含的宏块数（帧编码时 pic_height_in_map_units_minus1+1；场编码时再乘 2）
    public int $picHeightInMbs = 0;

    // SPS参数
    // pic_order_cnt_type：POC 计算方式选择（0/1/2，规范 7.4.2.1.1、8.2.1）
    public int $picOrderCntType = 0;
    // log2_max_frame_num_minus4：frame_num 位宽 = 该值 + 4（规范 7.4.2.1.1）
    public int $log2MaxFrameNumMinus4 = 0;
    // log2_max_pic_order_cnt_lsb_minus4：POC LSB 位宽 = 该值 + 4（POC type 0）
    public int $log2MaxPicOrderCntLsb = 0;
    // frame_mbs_only_flag：1 表示仅帧编码（无场），影响宏块地址推导（规范 7.4.2.1.1）
    public bool $frameMbsOnlyFlag = true;
    // profile_idc：档次标识，66=Baseline，77=Main，88=Extended，100=High 等
    public int $profileIdc = 0;
    // chroma_format_idc：色度采样格式，1 表示 4:2:0（High profile 中出现）
    public int $chromaFormatIdc = 1;

    // PPS参数
    // pic_init_qp_minus26 + 26：亮度初始 QP（规范 7.4.2.2），即 QP'(0) = 26 + pic_init_qp_minus26
    public int $picInitQp = 26;
    // chroma_qp_index_offset：色度 QP 偏移（规范 7.4.2.2、8.4.2.1）
    public int $chromaQpIndexOffset = 0;
    // num_ref_idx_l0_default_active_minus1 + 1：P/SP/B 片 L0 默认活动参考数（规范 7.4.2.2）
    public int $numRefIdxL0DefaultActive = 1;
    // num_ref_idx_l1_default_active_minus1 + 1：B 片 L1 默认活动参考数（规范 7.4.2.2）
    public int $numRefIdxL1DefaultActive = 1;
    // weighted_pred_flag：P/SP 片是否使用显式加权预测（规范 7.4.2.2、8.4.2.3）
    public bool $weightedPredFlag = false;
    // weighted_bipred_idc：B 片加权预测方式，0=默认，1=显式，2=隐式（规范 7.4.2.2）
    public int $weightedBipredIdc = 0;
    // 显式加权预测表（pred_weight_table，spec 7.3.3.2 / 8.4.2.3）
    // pred_weight_table 是否已就绪（当前片使用显式加权时为 true）
    public bool $pwtActive = false;
    // luma_log2_weight_denom：亮度权重分母的 log2（规范 7.3.3.2）
    public int $pwtLd = 0;   // luma_log2_weight_denom
    // chroma_log2_weight_denom：色度权重分母的 log2
    public int $pwtCd = 0;   // chroma_log2_weight_denom
    // L0 各参考索引的亮度权重（-128..127 范围内的整数，实际以 2^denom 为分母）
    public array $pwtL0W = [];
    // L0 各参考索引的亮度偏移
    public array $pwtL0O = [];
    // L0 各参考索引的 Cb 权重
    public array $pwtL0CbW = [];
    // L0 各参考索引的 Cb 偏移
    public array $pwtL0CbO = [];
    // L0 各参考索引的 Cr 权重
    public array $pwtL0CrW = [];
    // L0 各参考索引的 Cr 偏移
    public array $pwtL0CrO = [];
    // entropy_coding_mode_flag：0=CAVLC，1=CABAC（规范 7.4.2.2、9.2、9.3）
    public bool $entropyCodingModeFlag = false;
    // deblocking_filter_control_present_flag：slice header 是否携带去块滤波控制参数（规范 7.4.2.2）
    public bool $deblockingFilterParametersPresent = false;
    // redundant_pic_cnt_present_flag：是否携带 redundant_pic_cnt（规范 7.4.2.2）
    public bool $redundantPicCntPresent = false;
    // bottom_field_pic_order_in_frame_present_flag：场编码时是否为底场携带 POC 增量（规范 7.4.2.2）
    public bool $bottomFieldPicOrderInFramePresent = false;

    // 4x4 缩放矩阵集合：下标 0..2 为 Intra Y/Cb/Cr，3..5 为 Inter Y/Cb/Cr（规范 7.3.2.1.1.1）
    public array $quantMatrix = [];
    // 预先算好的反量化查找表：[矩阵类型][QP 0..51][系数位置] => 乘数（移位已并入）
    public array $dequant4Table = [];

    // 宏块对齐的 YUV420p 像素缓冲区（0-based packed 整数数组，0..255；
    // 重建直接写整数数组，参考帧被下一帧直接复用，省掉整帧 unpack/pack 转换）
    // 亮度平面：尺寸为 mbAlignedWidth * mbAlignedHeight
    public array $yPlane = [];
    // Cb 平面：宽高各为亮度的一半
    public array $uPlane = [];
    // Cr 平面：宽高各为亮度的一半
    public array $vPlane = [];

    // 宏块间非零系数计数（用于nC计算）
    // 上相邻行的亮度 nz：每宏块 4 列 4x4 块，共 picWidthInMbs * 4 个（规范 9.2.1 的 nC 上下文）
    public array $nzTopRowLuma = [];  // 上边行：每列1个，共 picWidthInMbs * 4
    // 上相邻行的色度 nz：每宏块 2 个 4x4 块 * 2 个分量，共 picWidthInMbs * 4
    public array $nzTopRowChroma = []; // 上边行：每列1个（Cb+Cr），共 picWidthInMbs * 2 * 2
    // 左相邻列的亮度 nz：每个 4x4 行块一个，共 4 个
    public array $nzLeftColLuma = [];  // 左边列：每行1个，共 4
    // 左相邻列的色度 nz：Cb/Cr 各 2 个，共 4 个
    public array $nzLeftColChroma = []; // 左边列：每行1个（Cb+Cr），共 4
    // 是否统计宏块级调试信息（仅调试用，不影响解码结果）
    public bool $enableMbStats = false;
    // 宏块统计信息累积容器
    public array $mbStats = [];

    // 宏块间Intra4x4预测模式缓存（用于跨宏块预测模式计算）
    // 上相邻行各 4x4 块的帧内预测模式（规范 8.3.1.1 中 prev_intra4x4_pred_mode 的推导来源）
    public array $intra4x4TopModes = [];  // 上边行：每列1个，共 picWidthInMbs * 4
    // 左相邻列各 4x4 块的帧内预测模式
    public array $intra4x4LeftModes = []; // 左边列：每行1个，共 4

    // 去块滤波参数
    // disable_deblocking_filter_idc：0=全开，1=全关，2=仅关片边界（规范 7.4.3）
    public int $disableDeblockingFilterIdc = 0;
    // slice_alpha_c0_offset_div2 * 2：用于修正 indexA（规范 8.7.2.2）
    public int $sliceAlphaC0Offset = 0;
    // slice_beta_offset_div2 * 2：用于修正 indexB（规范 8.7.2.2）
    public int $sliceBetaOffset = 0;
    // 每个宏块的 mb_type，用于判定边界强度 bS 中的“帧内/帧间”差异（规范 8.7.2.1）
    public array $mbTypeForDeblock = [];
    // 每个宏块的 QP
    public array $mbQpForDeblock = [];
    // 每个宏块边界上的非零系数标志（bS 判定依赖）
    public array $mbNnzForDeblock = [];
    // 每个宏块 L0 的 MV
    public array $mbMvForDeblock = [];
    // 每个宏块 L0 的参考索引
    public array $mbRefForDeblock = [];
    // 每个宏块 L1 的 MV（B 片）
    public array $mbMvL1ForDeblock = [];
    // 每个宏块 L1 的参考索引（B 片）
    public array $mbRefL1ForDeblock = [];

    /**
     * 当前帧是否需要为去块滤波收集宏块簿记（类型/QP/NZ/MV/参考索引）。
     * 被抽帧丢弃的帧仅作P链参考、不输出，调用方可跳过去块滤波及其全部簿记。
     */
    public bool $deblockInfoEnabled = true;
    // 当前片的 slice_type（0=P,1=B,2=I,3=SP,4=SI；取 0..4 归一化后的值）
    public int $currentSliceType = 0;
    // 强制关闭去块滤波（例如性能优先的快速路径）
    public bool $forceDisableDeblock = false;

    // P帧参考帧控制
    // num_ref_idx_active_override_flag：slice header 是否覆盖 PPS 的默认活动参考数（规范 7.3.3）
    public bool $numRefIdxActiveOverrideFlag = false;
    // num_ref_idx_l0_active_minus1 + 1：本片实际使用的 L0 活动参考数
    public int $numRefIdxL0Active = 1;

    // DPB (Decoded Picture Buffer) - 多参考帧管理
    // 已解码参考帧条目列表：每项含平面数据、frame_num、POC、同位帧 MV 等
    public array $dpb = [];
    /** DPB 条目自增 id：去块滤波跨列表参考比较需要“同一参考帧”的唯一标识（等价参考实现 ref2frm） */
    public int $dpbIdSeq = 0;
    // L0 参考图像列表（按规范 8.2.4 构造，元素为 DPB 条目）
    public array $refPicList0 = [];
    // ref_pic_list_modification 命令暂存（[idc, value] 对，slice header 解析后于默认列表上执行）
    // L0 的修改命令（规范 7.3.3.1、8.2.4.3）
    public array $modsL0 = [];
    // L1 的修改命令
    public array $modsL1 = [];
    // max_num_ref_frames：DPB 中参考帧数量上限（规范 7.4.2.1.1、8.2.5.3）
    public int $maxNumRefFrames = 1;
    // 当前图像的 frame_num（规范 7.4.3）
    public int $currFrameNum = 0;

    // 参考帧管理 - 保留兼容，现在作为refPicList0[0]的快捷访问
    // 最近参考帧的 Y 平面（等价 refPicList0[0]['y']）
    public ?array $refFrameY = null;
    // 最近参考帧的 U 平面
    public ?array $refFrameU = null;
    // 最近参考帧的 V 平面
    public ?array $refFrameV = null;
    // 亮度行跨度（宏块对齐宽度）
    public int $refStrideY = 0;
    // 色度行跨度（亮度一半）
    public int $refStrideUv = 0;
    // 亮度平面宽度
    public int $refWidthY = 0;
    // 亮度平面高度
    public int $refHeightY = 0;
    // 色度平面宽度
    public int $refWidthUv = 0;
    // 色度平面高度
    public int $refHeightUv = 0;

    // 运动向量缓存（用于P帧预测）- 4x4子块粒度
    // 上相邻宏块行的 MV，每宏块 4 个（4 列 4x4 块），元素为 [mvX, mvY, refIdx]
    public array $mvTopRow = [];      // 上方宏块行的运动向量，每宏块4个（4列4x4块）[colIdx] = [mvX, mvY, refIdx]
    // 当前宏块左上方 D 邻居的 MV（在覆盖 mvTopRow 前保存，规范 8.4.1.3 的 D 邻居）
    public array $mvTopLeft = [];     // 当前宏块左上方 D 邻居；在覆盖 mvTopRow 前保存
    // 左相邻宏块列的 MV，4 行 4x4 块
    public array $mvLeftCol = [];     // 左方宏块列的运动向量，4行4x4块

    // 解码顺序帧计数（每遇到一个 slice NAL 自增，仅本类内部使用）
    public int $frameNum = 0;

    // ===== B帧 / POC / CABAC / High profile 扩展 =====

    // SPS high 扩展
    // direct_8x8_inference_flag：direct 模式下 8x8 划分是否可推断（规范 7.4.2.1.1、8.4.1.2）
    public bool $direct8x8InferenceFlag = true;
    // transform_bypass_8x8? 实际用于 High profile 变换旁路相关标记
    public bool $transformBypass8x8 = false;

    // PPS high 扩展
    // transform_8x8_mode_flag：是否允许 8x8 整数变换（规范 7.4.2.2、8.5）
    public bool $transform8x8ModeFlag = false;
    // second_chroma_qp_index_offset：Cr 的额外色度 QP 偏移（规范 7.4.2.2）
    public int $secondChromaQpIndexOffset = 0;

    // POC (8.2.1, type0)
    // 前一参考图像的 PicOrderCntMsb（规范 8.2.1.1）
    public int $prevPicOrderCntMsb = 0;
    // 前一参考图像的 PicOrderCntLsb
    public int $prevPicOrderCntLsb = 0;
    // 当前图像的 pic_order_cnt_lsb（从 slice header 读取）
    public int $currPicOrderCntLsb = 0;
    // 当前图像的 PicOrderCntMsb（由 8.2.1.1 的 msb 推导规则得到）
    public int $currPicOrderCntMsb = 0;
    // 当前图像的最终 POC = Msb + Lsb（规范 8.2.1）
    public int $currPicOrderCnt = 0;
    // 前一参考图像用于 POC 的 frame_num
    public int $prevFrameNumForPoc = 0;
    // 前一图像的 FrameNumOffset（用于 POC type 1/2 的环绕处理）
    public int $prevFrameNumOffset = 0;
    // 当前图像的 FrameNumOffset
    public int $frameNumOffset = 0;

    // B 片参考表与片级状态
    // L1 参考图像列表（规范 8.2.4）
    public array $refPicList1 = [];
    // L1 活动参考数
    public int $numRefIdxL1Active = 1;
    // direct_spatial_mv_pred_flag：B 片 direct 模式用空间还是时间预测（规范 7.3.3、8.4.1.2）
    public bool $directSpatialMvPred = true;
    /** B 帧 MV 预测邻居缓存：[list] → topRow(mbWidth*4)/leftCol(4)/topLeft */
    // L0/L1 各自的上行 MV 缓存
    public array $bMvTopRow = [];
    // L0/L1 各自的左列 MV 缓存
    public array $bMvLeftCol = [];
    // L0/L1 各自的左上邻居 MV 缓存
    public array $bMvTopLeft = [];
    // cabac_init_idc：CABAC 上下文初始化选择（规范 7.3.3、9.3.1.2）
    public int $cabacInitIdc = 0;
    // 上一宏块的 QP 增量（mb_qp_delta 的恢复起点）
    public int $lastQpDelta = 0;

    // 显式加权预测 pred_weight_table
    // [lumaShift, chromaShift, l0 => [ref=>[w,o,cw[3],co[3]]...], l1=>[...]]
    public array $predWeightTable = [];

    // direct 缩放/隐式权重（片级预计算）
    // distScaleFactor[list][refIdx]；implicitW[l0][l1] = [wY,wC]
    // direct 模式时间预测中的距离缩放因子（规范 8.4.1.2.3 式 8-184）
    public array $distScaleFactor = [[], []];
    // 隐式加权预测权重（规范 8.4.2.3.2 式 8-244/8-245）
    public array $implicitWeight = [];

    // ===== CABAC 引擎状态 =====
    // 当前片 CABAC 数据缓冲（已剥离防竞争字节的 RBSP 片段）
    public string $cabacData = '';
    // CABAC 字节读取指针
    public int $cabacPtr = 0;
    // CABAC 有效数据末尾位置
    public int $cabacEnd = 0;
    // 算术解码区间下界低 32 位（规范 9.3.1.2）
    public int $cabacLow = 0;
    // 算术解码区间长度 range（9.3.1.2）
    public int $cabacRange = 0;
    // 上下文模型状态 [pStateIdx, valMPS] 数组（9.3.1.1）
    public array $cabacStates = [];

    // ===== 当前图像的全帧 4x4 块级状态（CABAC/B 帧邻域 + 同位帧存储）=====
    // 每个宏块 16 个 4x4 亮度块（raster），色度 nz 块每 MB 8 个（16-19 Cb,20-23 Cr）
    // 当前图像的宏块总数 = picWidthInMbs * picHeightInMbs
    public int $mbCount = 0;
    // 全帧每 4x4 块的 L0 MV（mbCount*16 项，每项 [x,y]）
    public array $picMv0 = [];       // mbCount*16 [x,y]
    // 全帧每 4x4 块的 L1 MV
    public array $picMv1 = [];       // mbCount*16 [x,y]
    // 全帧每 4x4 块的 L0 参考索引（-1 表示未使用）
    public array $picRef0 = [];      // mbCount*16 int, -1=未使用
    // 全帧每 4x4 块的 L1 参考索引
    public array $picRef1 = [];
    // 全帧每 4x4 块的 L0 MVD（用于 CABAC mvd 上下文与拷贝）
    public array $picMvd0 = [];      // mbCount*16 [x,y]
    // 全帧每 4x4 块的 L1 MVD
    public array $picMvd1 = [];
    // 每宏块是否为 skip（CABAC mb_skip_flag 上下文推导）
    public array $picIsSkip = [];    // mbCount 0/1（CABAC mb_skip_flag 上下文）
    // 每宏块是否为 B_Direct_16x16/skip（CABAC B 片 mb_type/ref 上下文）
    public array $picIsDirectMb = []; // mbCount 0/1（CABAC B mb_type/ref 上下文，仅 B_Direct_16x16/Skip）
    // 每宏块 4 个 8x8 象限的 direct 标记（direct_table 用）
    public array $picDirectQ = [];   // mbCount*4 逐 8x8 象限 direct 标记（direct_table，B 片 ref ctx 排除用）
    // 每宏块的 mb_type（压缩编码后的类型标志）
    public array $picMbType = [];    // mbCount flags
    // 每宏块 4 个 8x8 子块的划分类型
    public array $picSubType = [];   // mbCount*4 8x8 子类型 flags
    // 每宏块的 coded_block_pattern（规范 7.4.5、表 9-4）
    public array $picCbp = [];       // mbCount
    // 每宏块 24 个 nz 值（16 亮度 + 4 Cb + 4 Cr 的 4x4 块非零系数计数）
    public array $picNz = [];        // mbCount*24
    // 每宏块的帧内色度预测模式（规范 8.3.1.2）
    public array $picChromaPred = [];// mbCount
    // 每宏块 16 个 4x4 块的帧内预测模式
    public array $picIntra4Mode = [];// mbCount*16
    // 每宏块是否使用 8x8 变换（transform_8x8_mode）
    public array $picIs8x8Dct = [];  // mbCount
    // 每宏块是否使用 PCM 模式（I_PCM，规范 7.3.5）
    public array $picIsPcm = [];     // mbCount
    // 同位帧 LongTerm 标记（DPB 条目内同步保存一份）
    // 当前图像是否被标记为长期参考（MMCO 5/6，规范 8.2.5.4）
    public bool $currentIsLongTerm = false;

    // 显示序重排输出队列
    // 是否按 POC 重排输出（baseline profile 直接解码序输出，零回归）
    public bool $reorderByPoc = false;
    // 待输出帧队列：[seq,poc,epoch,isIdr,width,height,data]
    public array $outputQueue = [];  // [seq,poc,epoch,isIdr,width,height,data]
    // 输出“时代”编号：遇到 IDR 时自增，强制先排空旧时代帧
    public int $outputEpoch = 0;
    // 入队序号：同 POC 时保证稳定排序
    public int $outputSeq = 0;

    // DC系数映射表：DC数组索引 -> 块索引
    // 4x4 块按 zig-zag 扫描顺序存放 16 个系数，此表把 DC 位置映射回 raster 块序（规范 8.5.6）
    public static array $dcCoeffIndex = [0, 1, 4, 5, 2, 3, 6, 7, 8, 9, 12, 13, 10, 11, 14, 15];

    // 色度QP映射表
    // 用于将luma QP映射到chroma QP
    // 下标 QPc（0..51），值即 QPc 经截断后的结果（规范表 8-15、8.4.2.1）
    public const CHROMA_QP_TABLE = [
        0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15,
        16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 28, 29,
        29, 30, 31, 32, 32, 33, 34, 34, 35, 35, 36, 36, 37, 37,
        37, 38, 38, 38, 39, 39, 39, 39,
    ];

    // Inter宏块CBP映射表（golomb code -> CBP）
    // 编码标准Table 9-4
    // coded_block_pattern 的码字映射：下标为解析出的码字，值为 6 位 CBP（规范表 9-4）
    public const GOLOMB_TO_INTER_CBP = [
        0, 16, 1, 2, 4, 8, 32, 3, 5, 10, 12, 15, 47, 7, 11, 13, 14, 6, 9, 31, 35, 37, 42, 44, 33, 34,
        36, 40, 39, 43, 45, 46, 17, 18, 20, 24, 19, 21, 26, 28, 23, 27, 29, 30, 22, 25, 38, 41,
    ];

    // scan8数组，用于将块索引映射到缓存位置
    // 用于pred_non_zero_count计算
    // 把 4x4/8x8 块序号换算成“nz 缓存槽位”的查表（含 Cb/Cr 与 8x8 合并槽），
    // 供 CAVLC 的 nC 上下文推导使用（规范 9.2.1）
    public const SCAN8 = [
        12, 13, 20, 21,
        14, 15, 22, 23,
        28, 29, 36, 37,
        30, 31, 38, 39,
        52, 53, 60, 61,
        54, 55, 62, 63,
        68, 69, 76, 77,
        70, 71, 78, 79,
        92, 93, 100, 101,
        94, 95, 102, 103,
        108, 109, 116, 117,
        110, 111, 118, 119,
        0, 40, 80,
    ];

    /**
     * 构造解码器：初始化 4x4 反量化查找表（依赖默认 flat 缩放矩阵）。
     */
    public function __construct()
    {
        $this->initQuantMatrix();
    }

    // 4x4 反量化基表 levelScale4x4：行=QP%6，列=位置类别（规范表 8-13）
    public const DEQUANT_COEFF = [
        [10, 13, 10, 13, 13, 16, 13, 16],
        [11, 14, 11, 14, 14, 18, 14, 18],
        [13, 16, 13, 16, 16, 20, 16, 20],
        [14, 18, 14, 18, 18, 23, 18, 23],
        [16, 20, 16, 20, 20, 25, 20, 25],
        [18, 23, 18, 23, 23, 29, 23, 29],
        [20, 26, 20, 26, 26, 32, 26, 32],
        [22, 28, 22, 28, 28, 36, 28, 36],
        [26, 32, 26, 32, 32, 40, 32, 40],
        [28, 36, 28, 36, 36, 46, 36, 46],
        [32, 40, 32, 40, 40, 50, 40, 50],
        [36, 46, 36, 46, 46, 58, 46, 58],
        [40, 52, 40, 52, 52, 64, 52, 64],
        [44, 56, 44, 56, 56, 72, 56, 72],
        [52, 64, 52, 64, 64, 80, 64, 80],
        [56, 72, 56, 72, 72, 92, 72, 92],
        [64, 80, 64, 80, 80, 100, 80, 100],
        [72, 92, 72, 92, 92, 116, 92, 116],
        [80, 104, 80, 104, 104, 128, 104, 128],
        [88, 112, 88, 112, 112, 144, 112, 144],
        [104, 128, 104, 128, 128, 160, 128, 160],
        [112, 144, 112, 144, 144, 184, 144, 184],
        [128, 160, 128, 160, 160, 200, 160, 200],
        [144, 184, 144, 184, 184, 232, 184, 232],
        [160, 208, 160, 208, 208, 256, 208, 256],
        [176, 224, 176, 224, 224, 288, 224, 288],
        [208, 256, 208, 256, 256, 320, 256, 320],
        [224, 288, 224, 288, 288, 368, 288, 368],
        [256, 320, 256, 320, 320, 400, 320, 400],
        [288, 368, 288, 368, 368, 464, 368, 464],
        [320, 416, 320, 416, 416, 512, 416, 512],
        [352, 448, 352, 448, 448, 576, 448, 576],
        [416, 512, 416, 512, 512, 640, 512, 640],
        [448, 576, 448, 576, 576, 736, 576, 736],
        [512, 640, 512, 640, 640, 800, 640, 800],
        [576, 736, 576, 736, 736, 928, 736, 928],
        [640, 832, 640, 832, 832, 1024, 832, 1024],
        [704, 896, 704, 896, 896, 1152, 896, 1152],
        [832, 1024, 832, 1024, 1024, 1280, 1024, 1280],
        [896, 1152, 896, 1152, 1152, 1472, 1152, 1472],
        [1024, 1280, 1024, 1280, 1280, 1600, 1280, 1600],
        [1152, 1472, 1152, 1472, 1472, 1856, 1472, 1856],
        [1280, 1664, 1280, 1664, 1664, 2048, 1664, 2048],
        [1408, 1792, 1408, 1792, 1792, 2304, 1792, 2304],
        [1664, 2048, 1664, 2048, 2048, 2560, 2048, 2560],
        [1792, 2304, 1792, 2304, 2304, 2944, 2304, 2944],
        [2048, 2560, 2048, 2560, 2560, 3200, 2560, 3200],
        [2304, 2944, 2304, 2944, 2944, 3712, 2944, 3712],
        [2560, 3328, 2560, 3328, 3328, 4096, 3328, 4096],
        [2816, 3584, 2816, 3584, 3584, 4608, 3584, 4608],
        [3328, 4096, 3328, 4096, 4096, 5120, 4096, 5120],
        [3584, 4608, 3584, 4608, 4608, 5888, 4608, 5888],
    ];

    // 默认 4x4 缩放矩阵：下标 0 为 Intra（表 7-3 default_scaling4[0]），
    // 下标 1 为 Inter（表 7-3 default_scaling4[1]）
    public const DEFAULT_SCALING4 = [
        [6, 13, 20, 28, 13, 20, 28, 32, 20, 28, 32, 37, 28, 32, 37, 42],
        [10, 14, 20, 24, 14, 20, 24, 27, 20, 24, 27, 30, 24, 27, 30, 34],
    ];

    // ZIGZAG_SCAN_4X4
    // 4x4 块 zig-zag 扫描顺序（规范 8.5.6 图 8-11）：值为 raster 位置，下标为扫描序号
    public const ZIGZAG_SCAN_4X4 = [0, 1, 4, 8, 5, 2, 3, 6, 9, 12, 13, 10, 7, 11, 14, 15];

    /** 清理当前GOP的参考帧和帧级缓存，保留SPS/PPS、量化表和已解析分辨率。 */
    public function resetGopState(): void
    {
        // 丢弃像素平面，下一帧按新尺寸重新分配
        $this->yPlane = [];
        $this->uPlane = [];
        $this->vPlane = [];
        // 清空 DPB 与参考列表（新序列起点，规范 8.2.5.5 的 IDR 语义）
        $this->dpb = [];
        $this->refPicList0 = [];
        // 清空旧式单参考帧快捷引用
        $this->refFrameY = null;
        $this->refFrameU = null;
        $this->refFrameV = null;
        $this->refStrideY = 0;
        $this->refStrideUv = 0;
        $this->refWidthY = 0;
        $this->refHeightY = 0;
        $this->refWidthUv = 0;
        $this->refHeightUv = 0;
        // 重置 frame_num 与其计数
        $this->frameNum = 0;
        $this->currFrameNum = 0;
        // 清空 MV 邻居缓存（跨帧不再有效）
        $this->mvTopRow = [];
        $this->mvTopLeft = [];
        $this->mvLeftCol = [];
        // 清空 nz 上下文缓存
        $this->nzTopRowLuma = [];
        $this->nzTopRowChroma = [];
        $this->nzLeftColLuma = [];
        $this->nzLeftColChroma = [];
        // 清空 intra4x4 预测模式缓存
        $this->intra4x4TopModes = [];
        $this->intra4x4LeftModes = [];
        // 清空去块滤波簿记
        $this->mbTypeForDeblock = [];
        $this->mbQpForDeblock = [];
        $this->mbNnzForDeblock = [];
        $this->mbMvForDeblock = [];
        $this->mbRefForDeblock = [];
        $this->mbMvL1ForDeblock = [];
        $this->mbRefL1ForDeblock = [];
        // B帧/POC/CABAC 扩展状态
        $this->refPicList1 = [];
        $this->prevPicOrderCntMsb = 0;
        $this->prevPicOrderCntLsb = 0;
        $this->prevFrameNumForPoc = 0;
        $this->prevFrameNumOffset = 0;
        $this->frameNumOffset = 0;
        $this->currPicOrderCnt = 0;
        // 输出队列一并清空（避免旧 GOP 帧污染显示序）
        $this->outputQueue = [];
        $this->outputEpoch = 0;
        $this->outputSeq = 0;
        // 重置全帧 4x4 块级状态
        $this->resetPictureState();
    }

    /**
     * 每帧解码前重置全帧 4x4 块级状态（CABAC 邻域与同位帧存储用）。
     */
    public function resetPictureState(): void
    {
        // 宏块总数决定各块级数组长度（每 MB 16 个亮度 4x4 块 + 8 个色度 nz 槽）
        $n = $this->picWidthInMbs * $this->picHeightInMbs;
        $this->mbCount = $n;
        // L0/L1 的 MV 与 MVD 逐 4x4 块清零
        $this->picMv0 = array_fill(0, $n * 16, [0, 0]);
        $this->picMv1 = array_fill(0, $n * 16, [0, 0]);
        // 参考索引 -1 表示该块未使用对应列表（规范 8.4.1 中“参考索引无效”的表示）
        $this->picRef0 = array_fill(0, $n * 16, -1);
        $this->picRef1 = array_fill(0, $n * 16, -1);
        $this->picMvd0 = array_fill(0, $n * 16, [0, 0]);
        $this->picMvd1 = array_fill(0, $n * 16, [0, 0]);
        // 每宏块标志位清零
        $this->picIsSkip = array_fill(0, $n, 0);
        $this->picIsDirectMb = array_fill(0, $n, 0);
        $this->picDirectQ = array_fill(0, $n * 4, 0);
        $this->picMbType = array_fill(0, $n, 0);
        $this->picSubType = array_fill(0, $n * 4, 0);
        $this->picCbp = array_fill(0, $n, 0);
        $this->picNz = array_fill(0, $n * 24, 0);
        $this->picChromaPred = array_fill(0, $n, 0);
        // 4x4 帧内模式默认填 2（DC 模式），表示“尚未解码/无倾向”
        $this->picIntra4Mode = array_fill(0, $n * 16, 2);
        $this->picIs8x8Dct = array_fill(0, $n, 0);
        $this->picIsPcm = array_fill(0, $n, 0);
        // 新图像默认不是长期参考（MMCO 可能在后续重新标记）
        $this->currentIsLongTerm = false;
    }

    // ===== POC 计算 (8.2.1.1) =====

    /**
     * 参考帧解码完成后更新 prev POC 状态。
     */
    public function updatePrevPocState(int $frameNum, int $maxFrameNum, bool $isIdr): void
    {
        // IDR 图像会把 POC 状态复位（规范 8.2.1.1：IDR 时 prevPicOrderCntMsb/Lsb 置 0）
        if ($isIdr) {
            $this->prevPicOrderCntMsb = 0;
            $this->prevPicOrderCntLsb = 0;
        } else {
            // 非 IDR：把当前图像的 Msb/Lsb 保存为“前一张参考图像”的值
            $this->prevPicOrderCntMsb = $this->currPicOrderCntMsb;
            $this->prevPicOrderCntLsb = $this->currPicOrderCntLsb;
        }
        // 记录前一参考图像的 frame_num，供后续 POC 环绕判断
        $this->prevFrameNumForPoc = $frameNum;
        $this->prevFrameNumOffset = 0;
    }

    // ===== 显示序重排输出 =====

    /**
     * 将解码完成的一帧入队，并按 POC 排出一帧（无可排出返回 null）。
     */
    public function enqueueAndDrainFrame(string $data, int $width, int $height, int $poc, bool $isIdr): ?array
    {
        // 新 IDR 到来且队列非空：提升 epoch，保证旧时代（旧 GOP）帧先被排出
        if ($isIdr && !empty($this->outputQueue)) {
            // 新 IDR 时代：旧帧必须先排出
            $this->outputEpoch++;
        }
        // 入队：记录序号、POC、时代、尺寸与像素数据（规范 8.2.1 的显示序依据）
        $this->outputQueue[] = [
            'seq' => $this->outputSeq++,
            'poc' => $poc,
            'epoch' => $this->outputEpoch,
            'width' => $width,
            'height' => $height,
            'data' => $data,
            'isIdr' => $isIdr,
        ];
        // 尝试按显示序排出恰好一帧（B 帧重排的“bumping”思路，规范 8.2.5 的输出顺序）
        return $this->drainOneFrame($poc);
    }

    /**
     * 排出规则：
     *  1. 若存在旧 epoch 帧，按 (poc) 取最旧一帧；
     *  2. 否则取 POC 严格小于当前已解码 POC 的最小 POC 帧。
     */
    private function drainOneFrame(int $curPoc): ?array
    {
        // 队列为空则无可输出
        if (empty($this->outputQueue)) return null;
        $curEpoch = $this->outputEpoch;
        $pickKey = null;
        $pickPoc = null;
        // 先判定队列中是否存在“旧时代”的帧（旧 GOP 必须先清空）
        $oldEpochExists = false;
        foreach ($this->outputQueue as $k => $f) {
            if ($f['epoch'] < $curEpoch) { $oldEpochExists = true; break; }
        }
        // 在候选集合中挑选 POC 最小者
        foreach ($this->outputQueue as $k => $f) {
            if ($oldEpochExists) {
                // 有旧帧时，只从“非当前时代”的帧中挑选
                if ($f['epoch'] === $curEpoch) continue;
            } else {
                // 无旧帧时，仅 POC 小于当前解码 POC 的帧才可安全输出（显示序已确定）
                if ($f['poc'] >= $curPoc) continue;
            }
            // 更新最小 POC 的候选
            if ($pickPoc === null || $f['poc'] < $pickPoc) {
                $pickPoc = $f['poc'];
                $pickKey = $k;
            }
        }
        // 没有满足条件的帧，等后续帧到来再排
        if ($pickKey === null) return null;
        $f = $this->outputQueue[$pickKey];
        // 从队列中移除被选中的帧
        array_splice($this->outputQueue, $pickKey, 1);
        // 返回统一的帧结果结构（YUV420p）
        return ['data' => $f['data'], 'width' => $f['width'], 'height' => $f['height'], 'pix_fmt' => 'yuv420p', 'poc' => $f['poc'], 'isIdr' => $f['isIdr']];
    }

    /**
     * 流结束时冲洗输出队列（按 epoch、POC 升序）。
     * @return array[] 帧结果数组
     */
    public function flushReorderQueue(): array
    {
        // 拷贝一份待排序帧，避免排序过程中直接改动队列
        $frames = $this->outputQueue;
        // 排序键：先 epoch（旧 GOP 优先），再 POC（显示序），最后入队序号（稳定）
        usort($frames, function ($a, $b) {
            if ($a['epoch'] !== $b['epoch']) return $a['epoch'] <=> $b['epoch'];
            if ($a['poc'] !== $b['poc']) return $a['poc'] <=> $b['poc'];
            return $a['seq'] <=> $b['seq'];
        });
        // 队列已全部取出
        $this->outputQueue = [];
        $out = [];
        // 统一转换为对外帧结构
        foreach ($frames as $f) {
            $out[] = ['data' => $f['data'], 'width' => $f['width'], 'height' => $f['height'], 'pix_fmt' => 'yuv420p', 'poc' => $f['poc'], 'isIdr' => $f['isIdr']];
        }
        return $out;
    }

    /**
     * 流结束时冲洗残留的重排队列帧（B 帧最多延迟 bframes 帧）。
     * @return array[] 帧结果数组
     */
    public function flushOutput(): array
    {
        // 语义化别名：流结束时把剩余帧全部按显示序输出
        return $this->flushReorderQueue();
    }

    /**
     * 初始化/重建 4x4 反量化查找表。
     *
     * 规范 8.5.9：反量化系数 d[x][y] = (c[x][y] * levelScale(QP%6, x, y) * scalingMatrix) << (QP/6)，
     * 其中 QP>=24 时还需额外右移；本方法把 levelScale、缩放矩阵与移位预先合并成查表加速。
     */
    public function initQuantMatrix(): void
    {
        // 当scaling_matrix_present_flag=0时，所有矩阵初始化为16（flat matrix）
        // default_scaling4[0/1]仅在decode_scaling_list内部作为fallback使用，
        // 不覆盖整个矩阵为非flat值
        // flat 矩阵：16 个 4x4 位置全部为 16（即缩放系数为 1）
        $flatScaling4 = array_fill(0, 16, 16);

        // 6 个矩阵依次为 Intra Y/Cb/Cr 与 Inter Y/Cb/Cr（规范 7.3.2.1.1.1 的顺序）
        $scalingMatrices = [
            $flatScaling4, $flatScaling4, $flatScaling4,
            $flatScaling4, $flatScaling4, $flatScaling4,
        ];

        // 保存当前缩放矩阵，供后续（如 adaptive scaling matrix）复用
        $this->quantMatrix = $scalingMatrices;

        // 4x4 位置类别（0..2）映射：与 levelScale4x4 每行的列分组对应（规范表 8-13）
        $posClass = [0, 1, 0, 1, 1, 2, 1, 2, 0, 1, 0, 1, 1, 2, 1, 2];

        // 预分配 [矩阵类型][QP][位置] 三维表
        $this->dequant4Table = array_fill(0, 6, array_fill(0, 52, array_fill(0, 16, 0)));
        // 遍历 6 种矩阵
        for ($i = 0; $i < 6; $i++) {
            $sm = $scalingMatrices[$i];
            // 遍历 52 个 QP 取值
            for ($q = 0; $q < 52; $q++) {
                // 左移位数 = QP/6 + 2（规范 8.5.9 的移位量，含固定 2 位精度补偿）
                $shift = intdiv($q, 6) + 2;
                // levelScale 的行号 = QP % 6
                $idx = $q % 6;
                // 遍历 4x4 的 16 个位置
                for ($x = 0; $x < 16; $x++) {
                    // 取该位置的类别，用于选择 levelScale 的列
                    $scaleIdx = $posClass[$x];
                    // 合成查表值：levelScale * scalingMatrix，并按 QP 左移
                    $this->dequant4Table[$i][$q][$x] =
                        (self::DEQUANT4_COEFF_INIT[$idx][$scaleIdx] * $sm[$x]) << $shift;
                }
            }
        }
    }

    /**
     * 输入 NalUtil::splitNalUnits 处理后的NAL数组，返回 YUV420p 原始二进制
     * @param array $nalUnits
     * @param bool $parseOnly 是否只解析SPS/PPS获取宽高，不解码帧
     * @return array|null ['data' => 二进制流, 'width', 'height', 'pix_fmt']
     */
    public function decode(array $nalUnits, bool $parseOnly = false, bool $buildOutput = true, bool $skipDeblock = false): ?array
    {
        // 记录之前的分辨率（用于判断是否需要重初始化）
        $prevWidth = $this->width;
        $prevHeight = $this->height;

        // 重置像素平面（但保留 SPS/PPS 解析结果）
        $this->yPlane = [];
        $this->uPlane = [];
        $this->vPlane = [];

        // 第一轮：解析SPS/PPS获取分辨率。
        // SPS 必须先于 PPS 解析：parsePPS 的 High profile 扩展
        // （transform_8x8_mode_flag / 缩放矩阵 / second_chroma_qp_index_offset）
        // 依赖 SPS 先解析出的 profileIdc；调用方若以 [PPS,SPS,...] 顺序注入
        // （如 Mp4DecoderWorkerServer 的 unshift），原序解析会把 High 流
        // 误判为非 High，transform8x8ModeFlag 丢失导致 CABAC 解码错位。
        // 第一遍：只处理 NAL type 7（SPS），先建立序列级参数（规范 7.3.2.1.1）
        foreach ($nalUnits as $nal) {
            if ($nal['type'] === 7) {
                $this->parseSPS($nal['data']);
            }
        }
        // 第二遍：只处理 NAL type 8（PPS），此时 profileIdc 已就绪（规范 7.3.2.2）
        foreach ($nalUnits as $nal) {
            if ($nal['type'] === 8) {
                $this->parsePPS($nal['data']);
            }
        }

        // 如果之前已经有有效的分辨率，且当前 NAL 单元中没有 SPS/PPS，则跳过分辨率检查
        if (($this->width <= 0 || $this->height <= 0) && $prevWidth > 0 && $prevHeight > 0) {
            // 恢复之前的分辨率
            $this->width = $prevWidth;
            $this->height = $prevHeight;
        } elseif ($this->width <= 0 || $this->height <= 0) {
            // 既无新 SPS 也无历史分辨率：无法解码，直接返回
            return null;
        }

        // 仅解析模式：只回报尺寸信息，不做帧解码
        if ($parseOnly) {
            return [
                'width' => $this->width,
                'height' => $this->height,
                'pix_fmt' => 'yuv420p'
            ];
        }

        // 初始化像素数组，默认中性灰128
        /** 使用宏块对齐的尺寸，确保边界宏块有完整的存储空间 */
        // 宏块对齐宽度：PicWidthInMbs * 16（规范 7.4.2.1.1 的图像尺寸推导）
        $mbAlignedWidth = $this->picWidthInMbs * 16;
        // 宏块对齐高度：PicHeightInMapUnits * 16（帧编码）
        $mbAlignedHeight = $this->picHeightInMbs * 16;
        // 亮度样本数
        $ySize = $mbAlignedWidth * $mbAlignedHeight;
        // 每个色度平面的样本数（4:2:0 为亮度的 1/4）
        $uvSize = (int)($ySize / 4);

        // 第二轮：解码图像Slice — 每个Slice是一帧，各自初始化并输出
        //$sliceCount = 0;
        // 累积输出的原始 YUV 数据
        $outputData = '';
        // 最近一次输出帧的 POC（供上层做时间戳/重排判断）
        $outputPoc = null;
        // 最近一次输出帧是否为 IDR
        $outputIsIdr = false;
        // 解码序帧计数从 -1 开始，每个 slice 先自增（相当于从 0 计起）
        $this->frameNum = -1;
        // 遍历所有 NAL 单元，逐个解码片
        foreach ($nalUnits as $nal) {
            $nalType = $nal['type'];
            // 仅 1（非 IDR 片）与 5（IDR 片）承载图像数据（规范表 7-1）
            if ($nalType === 1 || $nalType === 5) {
                // 取 NAL 头首字节，右移 5 位并掩码得到 nal_ref_idc（规范 7.3.1）
                $nalHeader = ord($nal['raw'][0]);
                $nalRefIdc = ($nalHeader >> 5) & 0x03;
                //$sliceCount++;
                // 解码序帧号自增
                $this->frameNum++;
                // 每帧重新初始化像素平面（128 = 标准规定的帧内预测默认值）
                // 预先全部填 128，保证未重建区域（如未覆盖的边界）有合法值
                $this->yPlane = array_fill(0, $ySize, 128);
                $this->uPlane = array_fill(0, $uvSize, 128);
                $this->vPlane = array_fill(0, $uvSize, 128);
                // 重置全帧 4x4 块级状态（CABAC 邻域 / B帧同位 MV）
                $this->resetPictureState();

                // 保存实际图像尺寸，临时使用宏块对齐的尺寸进行解码
                $origWidth = $this->width;
                $origHeight = $this->height;
                // 解码期间按宏块对齐尺寸寻址，避免边界宏块越界
                $this->width = $mbAlignedWidth;
                $this->height = $mbAlignedHeight;

                // NAL type 5 = IDR 图像（规范 7.4.1、8.2.5.5）
                $isIdrNal = ($nalType === 5);
                // IDR关键帧永不跳滤波（参考链重置点，且通常都保留输出）
                // 非 IDR 且调用方要求跳过滤波时，才允许跳过去块滤波（性能优化）
                $this->decodeSlice($nal['data'], $isIdrNal, $nalRefIdc, $skipDeblock && $nalType !== 5);
                // 取本帧 POC（在 decodeSlice 内按规范 8.2.1 计算完成）
                $framePoc = $this->currPicOrderCnt;
                // B 片的解码序≠显示序，启用按 POC 重排输出
                if ($this->currentSliceType === 1) {
                    $this->reorderByPoc = true;
                }
                // 更新参考帧（用于P/B帧运动补偿）- 在恢复尺寸之前进行
                // nal_ref_idc != 0 或 IDR 时才写入 DPB（非参考帧不参与后续预测）
                if ($nalRefIdc !== 0 || $nalType === 5) {
                    // 组装 DPB 条目：包含像素平面、同位帧 4x4 运动信息与参考快照
                    $dpbEntry = [
                        // 自增唯一 id，供去块滤波判定“是否同一参考帧”
                        'id' => ++$this->dpbIdSeq,
                        'frameNum' => $this->currFrameNum,
                        'isLongTerm' => $this->currentIsLongTerm,
                        'poc' => $framePoc,
                        // 同位帧 4x4 运动信息（temporal direct / 邻域调试用）
                        // 供后续 B 片的 temporal direct 模式（规范 8.4.1.2.3）读取
                        'mv0' => $this->picMv0,
                        'mv1' => $this->picMv1,
                        'ref0' => $this->picRef0,
                        'ref1' => $this->picRef1,
                        'mbType' => $this->picMbType,
                        'subType' => $this->picSubType,
                        // 本帧参考列表的 frameNum 快照（temporal direct 的 fill_colmap 匹配用）
                        'refFns0' => array_map(function ($e) { return $e['frameNum']; }, $this->refPicList0),
                        'refFns1' => array_map(function ($e) { return $e['frameNum']; }, $this->refPicList1),
                        // 平面本身即 0-based 整数数组，*Bytes 与平面共享同一 COW 副本
                        // （参考帧只读不写，不会发生写时分离），ensureRefBytes 因此变为空操作
                        'y' => $this->yPlane,
                        'u' => $this->uPlane,
                        'v' => $this->vPlane,
                        'yBytes' => $this->yPlane,
                        'uBytes' => $this->uPlane,
                        'vBytes' => $this->vPlane,
                        'strideY' => $mbAlignedWidth,
                        'strideUv' => (int)($mbAlignedWidth / 2),
                        'widthY' => $mbAlignedWidth,
                        'heightY' => $mbAlignedHeight,
                        'widthUv' => (int)($mbAlignedWidth / 2),
                        'heightUv' => (int)($mbAlignedHeight / 2),
                    ];
                    // 入 DPB
                    $this->dpb[] = $dpbEntry;
                    // dec_ref_pic_marking（MMCO/滑动窗口）在当前帧入 DPB 后执行（spec 8.2.5 时序）
                    $this->executeRefPicMarking($this->currFrameNum);

                    // 维护“最近参考帧”快捷引用（即 refPicList0[0] 的等价视图）
                    $this->refFrameY = $dpbEntry['y'];
                    $this->refFrameU = $dpbEntry['u'];
                    $this->refFrameV = $dpbEntry['v'];
                    $this->refStrideY = $dpbEntry['strideY'];
                    $this->refStrideUv = $dpbEntry['strideUv'];
                    $this->refWidthY = $dpbEntry['widthY'];
                    $this->refHeightY = $dpbEntry['heightY'];
                    $this->refWidthUv = $dpbEntry['widthUv'];
                    $this->refHeightUv = $dpbEntry['heightUv'];

                    // POC 参考状态仅在参考帧后更新（8.2.1.1）
                    // MaxFrameNum = 1 << (log2_max_frame_num_minus4 + 4)
                    $this->updatePrevPocState($this->currFrameNum, 1 << ($this->log2MaxFrameNumMinus4 + 4), $isIdrNal);
                }

                // 恢复实际图像尺寸
                $this->width = $origWidth;
                $this->height = $origHeight;

                // 将本帧转为二进制（裁剪到实际图像尺寸）
                if ($buildOutput) {
                    // 亮度：逐行从宏块对齐平面中取前 width 个样本打包成字节串
                    $yBin = '';
                    for ($yy = 0; $yy < $this->height; $yy++) {
                        $yBin .= pack('C*', ...array_slice($this->yPlane, $yy * $mbAlignedWidth, $this->width));
                    }
                    // 色度平面跨度与裁剪宽度按 4:2:0 取亮度一半
                    $uvMbAlignedWidth = (int)($mbAlignedWidth / 2);
                    $uvWidth = (int)($this->width / 2);
                    $uvHeight = (int)($this->height / 2);
                    $uBin = '';
                    $vBin = '';
                    // 逐行裁剪并打包 Cb/Cr
                    for ($yy = 0; $yy < $uvHeight; $yy++) {
                        $uBin .= pack('C*', ...array_slice($this->uPlane, $yy * $uvMbAlignedWidth, $uvWidth));
                        $vBin .= pack('C*', ...array_slice($this->vPlane, $yy * $uvMbAlignedWidth, $uvWidth));
                    }
                    // YUV420p 平面顺序：Y 全帧，再 U 全帧，再 V 全帧
                    $frameBin = $yBin . $uBin . $vBin;
                    if ($this->reorderByPoc) {
                        // 按显示序(POC)延迟排出；流结束需调用 flushOutput()
                        $drained = $this->enqueueAndDrainFrame($frameBin, $this->width, $this->height, $framePoc, $isIdrNal);
                        if ($drained !== null) {
                            $outputData .= $drained['data'];
                            $outputPoc = $drained['poc'];
                            $outputIsIdr = $drained['isIdr'];
                        }
                    } else {
                        // 无 B 帧（POC 单调）时直接按解码序输出
                        $outputData .= $frameBin;
                        $outputPoc = $framePoc;
                        $outputIsIdr = $isIdrNal;
                    }
                }
            }
        }

        // 返回累积数据与帧描述信息
        return [
            'data' => $outputData,
            'width' => $this->width,
            'height' => $this->height,
            'pix_fmt' => 'yuv420p',
            'poc' => $outputPoc,
            'isIdr' => $outputIsIdr,
        ];
    }

    /**
     * 默认缩放矩阵 (intra Y 和 intra C)
     * 来自编码标准 Table 7-3, default_scaling4
     */
    // Intra 4x4 默认缩放矩阵扁平形式（表 7-3 default_scaling4[0]，按 raster 顺序）
    private const DEFAULT_SCALING4_INTRA = [
        6, 13, 20, 28, 13, 20, 28, 32, 20, 28, 32, 37, 28, 32, 37, 42
    ];
    // Inter 4x4 默认缩放矩阵扁平形式（表 7-3 default_scaling4[1]）
    private const DEFAULT_SCALING4_INTER = [
        10, 14, 20, 24, 14, 20, 24, 27, 20, 24, 27, 30, 24, 27, 30, 34
    ];

    /**
     * Dequant coefficient init table (编码标准)
     */
    // levelScale4x4 的 6 行（QP%6 = 0..5），每行 3 个位置类别（规范表 8-13）
    private const DEQUANT4_COEFF_INIT = [
        [10, 13, 16],
        [11, 14, 18],
        [13, 16, 20],
        [14, 18, 23],
        [16, 20, 25],
        [18, 23, 29],
    ];

    /**
     * 返回当前图像显示宽度。
     */
    public function getWidth()
    {

        // 返回已解析/恢复的实际显示宽度
        return $this->width;
    }

    /**
     * 返回当前图像显示高度。
     */
    public function getHeight(){
        // 返回已解析/恢复的实际显示高度
        return $this->height;
    }
}
