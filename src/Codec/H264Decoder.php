<?php

namespace Xiaosongshu\Flv2mp4\Codec;

use Xiaosongshu\Flv2mp4\Codec\Decode\BitReader;
use Xiaosongshu\Flv2mp4\Codec\Decode\CabacDecodingTrait;
use Xiaosongshu\Flv2mp4\Codec\Decode\CabacTablesTrait;
use Xiaosongshu\Flv2mp4\Codec\Decode\DeblockingFilterTrait;
use Xiaosongshu\Flv2mp4\Codec\Decode\IntraPredictionTrait;
use Xiaosongshu\Flv2mp4\Codec\Decode\MacroblockDecodingTrait;
use Xiaosongshu\Flv2mp4\Codec\Decode\MotionCompensationTrait;
use Xiaosongshu\Flv2mp4\Codec\Decode\MotionVectorPredictionTrait;
use Xiaosongshu\Flv2mp4\Codec\Decode\ResidualDecodingTrait;
use Xiaosongshu\Flv2mp4\Codec\Decode\SliceDecodingTrait;
use Xiaosongshu\Flv2mp4\Codec\Decode\SpsPpsParsingTrait;
use Xiaosongshu\Flv2mp4\Codec\Decode\TransformTrait;

/**
 * @purpose H.264解码器 - 支持 baseline/main/high profile (I/P/B帧, CAVLC/CABAC)
 * @author yanglong
 * @time 2026年7月23日14:41:47
 */
class H264Decoder
{
    use SpsPpsParsingTrait, SliceDecodingTrait, MacroblockDecodingTrait, IntraPredictionTrait, ResidualDecodingTrait, TransformTrait, DeblockingFilterTrait, MotionCompensationTrait, MotionVectorPredictionTrait, CabacTablesTrait, CabacDecodingTrait;

    const PART_NOT_AVAILABLE = -2;
    private const ALPHA_TABLE = [
        0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
        4, 4, 5, 6, 7, 8, 9, 10, 12, 13, 15, 17, 20, 22,
        25, 28, 32, 36, 40, 45, 50, 56, 63, 71, 80, 90,
        101, 113, 127, 144, 162, 182, 203, 226, 255, 255,
    ];

    private const BETA_TABLE = [
        0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
        2, 2, 2, 3, 3, 3, 3, 4, 4, 4, 6, 6, 7, 7, 8, 8,
        9, 9, 10, 10, 11, 11, 12, 12, 13, 13, 14, 14,
        15, 15, 16, 16, 17, 17, 18, 18,
    ];

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

    public $debugResidual = false;
    public BitReader $reader;

    public int $width = 0;
    public int $height = 0;
    public int $picWidthInMbs = 0;
    public int $picHeightInMbs = 0;

    // SPS参数
    public int $picOrderCntType = 0;
    public int $log2MaxFrameNumMinus4 = 0;
    public int $log2MaxPicOrderCntLsb = 0;
    public bool $frameMbsOnlyFlag = true;
    public int $profileIdc = 0;
    public int $chromaFormatIdc = 1;

    // PPS参数
    public int $picInitQp = 26;
    public int $chromaQpIndexOffset = 0;
    public int $numRefIdxL0DefaultActive = 1;
    public int $numRefIdxL1DefaultActive = 1;
    public bool $weightedPredFlag = false;
    public int $weightedBipredIdc = 0;
    // 显式加权预测表（pred_weight_table，spec 7.3.3.2 / 8.4.2.3）
    public bool $pwtActive = false;
    public int $pwtLd = 0;   // luma_log2_weight_denom
    public int $pwtCd = 0;   // chroma_log2_weight_denom
    public array $pwtL0W = [];
    public array $pwtL0O = [];
    public array $pwtL0CbW = [];
    public array $pwtL0CbO = [];
    public array $pwtL0CrW = [];
    public array $pwtL0CrO = [];
    public bool $entropyCodingModeFlag = false;
    public bool $deblockingFilterParametersPresent = false;
    public bool $redundantPicCntPresent = false;
    public bool $bottomFieldPicOrderInFramePresent = false;

    public array $quantMatrix = [];
    public array $dequant4Table = [];

    // 宏块对齐的 YUV420p 像素缓冲区（0-based packed 整数数组，0..255；
    // 重建直接写整数数组，参考帧被下一帧直接复用，省掉整帧 unpack/pack 转换）
    public array $yPlane = [];
    public array $uPlane = [];
    public array $vPlane = [];

    // 宏块间非零系数计数（用于nC计算）
    public array $nzTopRowLuma = [];  // 上边行：每列1个，共 picWidthInMbs * 4
    public array $nzTopRowChroma = []; // 上边行：每列1个（Cb+Cr），共 picWidthInMbs * 2 * 2
    public array $nzLeftColLuma = [];  // 左边列：每行1个，共 4
    public array $nzLeftColChroma = []; // 左边列：每行1个（Cb+Cr），共 4
    public bool $enableMbStats = false;
    public array $mbStats = [];

    // 宏块间Intra4x4预测模式缓存（用于跨宏块预测模式计算）
    public array $intra4x4TopModes = [];  // 上边行：每列1个，共 picWidthInMbs * 4
    public array $intra4x4LeftModes = []; // 左边列：每行1个，共 4

    // 去块滤波参数
    public int $disableDeblockingFilterIdc = 0;
    public int $sliceAlphaC0Offset = 0;
    public int $sliceBetaOffset = 0;
    public array $mbTypeForDeblock = [];
    public array $mbQpForDeblock = [];
    public array $mbNnzForDeblock = [];
    public array $mbMvForDeblock = [];
    public array $mbRefForDeblock = [];
    public array $mbMvL1ForDeblock = [];
    public array $mbRefL1ForDeblock = [];

    /**
     * 当前帧是否需要为去块滤波收集宏块簿记（类型/QP/NZ/MV/参考索引）。
     * 被抽帧丢弃的帧仅作P链参考、不输出，调用方可跳过去块滤波及其全部簿记。
     */
    public bool $deblockInfoEnabled = true;
    public int $currentSliceType = 0;
    public bool $forceDisableDeblock = false;

    // P帧参考帧控制
    public bool $numRefIdxActiveOverrideFlag = false;
    public int $numRefIdxL0Active = 1;

    // DPB (Decoded Picture Buffer) - 多参考帧管理
    public array $dpb = [];
    public array $refPicList0 = [];
    // ref_pic_list_modification 命令暂存（[idc, value] 对，slice header 解析后于默认列表上执行）
    public array $modsL0 = [];
    public array $modsL1 = [];
    public int $maxNumRefFrames = 1;
    public int $currFrameNum = 0;

    // 参考帧管理 - 保留兼容，现在作为refPicList0[0]的快捷访问
    public ?array $refFrameY = null;
    public ?array $refFrameU = null;
    public ?array $refFrameV = null;
    public int $refStrideY = 0;
    public int $refStrideUv = 0;
    public int $refWidthY = 0;
    public int $refHeightY = 0;
    public int $refWidthUv = 0;
    public int $refHeightUv = 0;

    // 运动向量缓存（用于P帧预测）- 4x4子块粒度
    public array $mvTopRow = [];      // 上方宏块行的运动向量，每宏块4个（4列4x4块）[colIdx] = [mvX, mvY, refIdx]
    public array $mvTopLeft = [];     // 当前宏块左上方 D 邻居；在覆盖 mvTopRow 前保存
    public array $mvLeftCol = [];     // 左方宏块列的运动向量，4行4x4块

    public int $frameNum = 0;

    // ===== B帧 / POC / CABAC / High profile 扩展 =====

    // SPS high 扩展
    public bool $direct8x8InferenceFlag = true;
    public bool $transformBypass8x8 = false;

    // PPS high 扩展
    public bool $transform8x8ModeFlag = false;
    public int $secondChromaQpIndexOffset = 0;

    // POC (H.264 8.2.1, type0)
    public int $prevPicOrderCntMsb = 0;
    public int $prevPicOrderCntLsb = 0;
    public int $currPicOrderCntLsb = 0;
    public int $currPicOrderCntMsb = 0;
    public int $currPicOrderCnt = 0;
    public int $prevFrameNumForPoc = 0;
    public int $prevFrameNumOffset = 0;
    public int $frameNumOffset = 0;

    // B 片参考表与片级状态
    public array $refPicList1 = [];
    public int $numRefIdxL1Active = 1;
    public bool $directSpatialMvPred = true;
    /** B 帧 MV 预测邻居缓存：[list] → topRow(mbWidth*4)/leftCol(4)/topLeft */
    public array $bMvTopRow = [];
    public array $bMvLeftCol = [];
    public array $bMvTopLeft = [];
    public int $cabacInitIdc = 0;
    public int $lastQpDelta = 0;

    // 显式加权预测 pred_weight_table
    // [lumaShift, chromaShift, l0 => [ref=>[w,o,cw[3],co[3]]...], l1=>[...]]
    public array $predWeightTable = [];

    // direct 缩放/隐式权重（片级预计算）
    // distScaleFactor[list][refIdx]；implicitW[l0][l1] = [wY,wC]
    public array $distScaleFactor = [[], []];
    public array $implicitWeight = [];

    // ===== CABAC 引擎状态 =====
    public string $cabacData = '';
    public int $cabacPtr = 0;
    public int $cabacEnd = 0;
    public int $cabacLow = 0;
    public int $cabacRange = 0;
    public array $cabacStates = [];

    // ===== 当前图像的全帧 4x4 块级状态（CABAC/B 帧邻域 + 同位帧存储）=====
    // 每个宏块 16 个 4x4 亮度块（raster），色度 nz 块每 MB 8 个（16-19 Cb,20-23 Cr）
    public int $mbCount = 0;
    public array $picMv0 = [];       // mbCount*16 [x,y]
    public array $picMv1 = [];       // mbCount*16 [x,y]
    public array $picRef0 = [];      // mbCount*16 int, -1=未使用
    public array $picRef1 = [];
    public array $picMvd0 = [];      // mbCount*16 [x,y]
    public array $picMvd1 = [];
    public array $picIsSkip = [];    // mbCount 0/1（CABAC mb_skip_flag 上下文）
    public array $picIsDirectMb = []; // mbCount 0/1（CABAC B mb_type/ref 上下文，仅 B_Direct_16x16/Skip）
    public array $picDirectQ = [];   // mbCount*4 逐 8x8 象限 direct 标记（FFmpeg direct_table，B 片 ref ctx 排除用）
    public array $picMbType = [];    // mbCount flags
    public array $picSubType = [];   // mbCount*4 8x8 子类型 flags
    public array $picCbp = [];       // mbCount
    public array $picNz = [];        // mbCount*24
    public array $picChromaPred = [];// mbCount
    public array $picIntra4Mode = [];// mbCount*16
    public array $picIs8x8Dct = [];  // mbCount
    public array $picIsPcm = [];     // mbCount
    // 同位帧 LongTerm 标记（DPB 条目内同步保存一份）
    public bool $currentIsLongTerm = false;

    // 显示序重排输出队列
    // 是否按 POC 重排输出（baseline profile 直接解码序输出，零回归）
    public bool $reorderByPoc = false;
    public array $outputQueue = [];  // [seq,poc,epoch,isIdr,width,height,data]
    public int $outputEpoch = 0;
    public int $outputSeq = 0;

    // DC系数映射表：DC数组索引 -> 块索引
    public static array $dcCoeffIndex = [0, 1, 4, 5, 2, 3, 6, 7, 8, 9, 12, 13, 10, 11, 14, 15];

    // 色度QP映射表
    // 用于将luma QP映射到chroma QP
    public const CHROMA_QP_TABLE = [
        0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15,
        16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 28, 29,
        29, 30, 31, 32, 32, 33, 34, 34, 35, 35, 36, 36, 37, 37,
        37, 38, 38, 38, 39, 39, 39, 39,
    ];

    // Inter宏块CBP映射表（golomb code -> CBP）
    // H.264标准Table 9-4
    public const GOLOMB_TO_INTER_CBP = [
        0, 16, 1, 2, 4, 8, 32, 3, 5, 10, 12, 15, 47, 7, 11, 13, 14, 6, 9, 31, 35, 37, 42, 44, 33, 34,
        36, 40, 39, 43, 45, 46, 17, 18, 20, 24, 19, 21, 26, 28, 23, 27, 29, 30, 22, 25, 38, 41,
    ];

    // FFmpeg scan8数组，用于将块索引映射到缓存位置
    // 用于pred_non_zero_count计算
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

    public function __construct()
    {
        $this->initQuantMatrix();
    }

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

    public const DEFAULT_SCALING4 = [
        [6, 13, 20, 28, 13, 20, 28, 32, 20, 28, 32, 37, 28, 32, 37, 42],
        [10, 14, 20, 24, 14, 20, 24, 27, 20, 24, 27, 30, 24, 27, 30, 34],
    ];

    // ZIGZAG_SCAN_4X4
    public const ZIGZAG_SCAN_4X4 = [0, 1, 4, 8, 5, 2, 3, 6, 9, 12, 13, 10, 7, 11, 14, 15];

    /** 清理当前GOP的参考帧和帧级缓存，保留SPS/PPS、量化表和已解析分辨率。 */
    public function resetGopState(): void
    {
        $this->yPlane = [];
        $this->uPlane = [];
        $this->vPlane = [];
        $this->dpb = [];
        $this->refPicList0 = [];
        $this->refFrameY = null;
        $this->refFrameU = null;
        $this->refFrameV = null;
        $this->refStrideY = 0;
        $this->refStrideUv = 0;
        $this->refWidthY = 0;
        $this->refHeightY = 0;
        $this->refWidthUv = 0;
        $this->refHeightUv = 0;
        $this->frameNum = 0;
        $this->currFrameNum = 0;
        $this->mvTopRow = [];
        $this->mvTopLeft = [];
        $this->mvLeftCol = [];
        $this->nzTopRowLuma = [];
        $this->nzTopRowChroma = [];
        $this->nzLeftColLuma = [];
        $this->nzLeftColChroma = [];
        $this->intra4x4TopModes = [];
        $this->intra4x4LeftModes = [];
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
        $this->outputQueue = [];
        $this->outputEpoch = 0;
        $this->outputSeq = 0;
        $this->resetPictureState();
    }

    /**
     * 每帧解码前重置全帧 4x4 块级状态（CABAC 邻域与同位帧存储用）。
     */
    public function resetPictureState(): void
    {
        $n = $this->picWidthInMbs * $this->picHeightInMbs;
        $this->mbCount = $n;
        $this->picMv0 = array_fill(0, $n * 16, [0, 0]);
        $this->picMv1 = array_fill(0, $n * 16, [0, 0]);
        $this->picRef0 = array_fill(0, $n * 16, -1);
        $this->picRef1 = array_fill(0, $n * 16, -1);
        $this->picMvd0 = array_fill(0, $n * 16, [0, 0]);
        $this->picMvd1 = array_fill(0, $n * 16, [0, 0]);
        $this->picIsSkip = array_fill(0, $n, 0);
        $this->picIsDirectMb = array_fill(0, $n, 0);
        $this->picDirectQ = array_fill(0, $n * 4, 0);
        $this->picMbType = array_fill(0, $n, 0);
        $this->picSubType = array_fill(0, $n * 4, 0);
        $this->picCbp = array_fill(0, $n, 0);
        $this->picNz = array_fill(0, $n * 24, 0);
        $this->picChromaPred = array_fill(0, $n, 0);
        $this->picIntra4Mode = array_fill(0, $n * 16, 2);
        $this->picIs8x8Dct = array_fill(0, $n, 0);
        $this->picIsPcm = array_fill(0, $n, 0);
        $this->currentIsLongTerm = false;
        $this->dbgBStat = ['n' => 0, 'skip' => 0, 'direct' => 0, 'intra' => 0, 'inter' => 0, 'other' => 0];
    }

    // ===== POC 计算 (H.264 8.2.1.1) =====

    /**
     * 参考帧解码完成后更新 prev POC 状态。
     */
    public function updatePrevPocState(int $frameNum, int $maxFrameNum, bool $isIdr): void
    {
        if ($isIdr) {
            $this->prevPicOrderCntMsb = 0;
            $this->prevPicOrderCntLsb = 0;
        } else {
            $this->prevPicOrderCntMsb = $this->currPicOrderCntMsb;
            $this->prevPicOrderCntLsb = $this->currPicOrderCntLsb;
        }
        $this->prevFrameNumForPoc = $frameNum;
        $this->prevFrameNumOffset = 0;
    }

    // ===== 显示序重排输出 =====

    /**
     * 将解码完成的一帧入队，并按 POC 排出一帧（无可排出返回 null）。
     */
    public function enqueueAndDrainFrame(string $data, int $width, int $height, int $poc, bool $isIdr): ?array
    {
        if ($isIdr && !empty($this->outputQueue)) {
            // 新 IDR 时代：旧帧必须先排出
            $this->outputEpoch++;
        }
        $this->outputQueue[] = [
            'seq' => $this->outputSeq++,
            'poc' => $poc,
            'epoch' => $this->outputEpoch,
            'width' => $width,
            'height' => $height,
            'data' => $data,
        ];
        return $this->drainOneFrame($poc);
    }

    /**
     * 排出规则：
     *  1. 若存在旧 epoch 帧，按 (poc) 取最旧一帧；
     *  2. 否则取 POC 严格小于当前已解码 POC 的最小 POC 帧。
     */
    private function drainOneFrame(int $curPoc): ?array
    {
        if (empty($this->outputQueue)) return null;
        $curEpoch = $this->outputEpoch;
        $pickKey = null;
        $pickPoc = null;
        $oldEpochExists = false;
        foreach ($this->outputQueue as $k => $f) {
            if ($f['epoch'] < $curEpoch) { $oldEpochExists = true; break; }
        }
        foreach ($this->outputQueue as $k => $f) {
            if ($oldEpochExists) {
                if ($f['epoch'] === $curEpoch) continue;
            } else {
                if ($f['poc'] >= $curPoc) continue;
            }
            if ($pickPoc === null || $f['poc'] < $pickPoc) {
                $pickPoc = $f['poc'];
                $pickKey = $k;
            }
        }
        if ($pickKey === null) return null;
        $f = $this->outputQueue[$pickKey];
        array_splice($this->outputQueue, $pickKey, 1);
        return ['data' => $f['data'], 'width' => $f['width'], 'height' => $f['height'], 'pix_fmt' => 'yuv420p'];
    }

    /**
     * 流结束时冲洗输出队列（按 epoch、POC 升序）。
     * @return array[] 帧结果数组
     */
    public function flushReorderQueue(): array
    {
        $frames = $this->outputQueue;
        usort($frames, function ($a, $b) {
            if ($a['epoch'] !== $b['epoch']) return $a['epoch'] <=> $b['epoch'];
            if ($a['poc'] !== $b['poc']) return $a['poc'] <=> $b['poc'];
            return $a['seq'] <=> $b['seq'];
        });
        $this->outputQueue = [];
        $out = [];
        foreach ($frames as $f) {
            $out[] = ['data' => $f['data'], 'width' => $f['width'], 'height' => $f['height'], 'pix_fmt' => 'yuv420p'];
        }
        return $out;
    }

    public function initQuantMatrix(): void
    {
        // 当scaling_matrix_present_flag=0时，所有矩阵初始化为16（flat matrix）
        // default_scaling4[0/1]仅在decode_scaling_list内部作为fallback使用，
        // 不覆盖整个矩阵为非flat值
        $flatScaling4 = array_fill(0, 16, 16);

        $scalingMatrices = [
            $flatScaling4, $flatScaling4, $flatScaling4,
            $flatScaling4, $flatScaling4, $flatScaling4,
        ];

        $this->quantMatrix = $scalingMatrices;

        $posClass = [0, 1, 0, 1, 1, 2, 1, 2, 0, 1, 0, 1, 1, 2, 1, 2];

        $this->dequant4Table = array_fill(0, 6, array_fill(0, 52, array_fill(0, 16, 0)));
        for ($i = 0; $i < 6; $i++) {
            $sm = $scalingMatrices[$i];
            for ($q = 0; $q < 52; $q++) {
                $shift = intdiv($q, 6) + 2;
                $idx = $q % 6;
                for ($x = 0; $x < 16; $x++) {
                    $scaleIdx = $posClass[$x];
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

        // 第一轮：解析SPS/PPS获取分辨率
        foreach ($nalUnits as $nal) {
            $nalType = $nal['type'];
            if ($nalType === 7) {
                $this->parseSPS($nal['data']);
            } elseif ($nalType === 8) {
                $this->parsePPS($nal['data']);
            }
        }

        // 如果之前已经有有效的分辨率，且当前 NAL 单元中没有 SPS/PPS，则跳过分辨率检查
        if (($this->width <= 0 || $this->height <= 0) && $prevWidth > 0 && $prevHeight > 0) {
            // 恢复之前的分辨率
            $this->width = $prevWidth;
            $this->height = $prevHeight;
        } elseif ($this->width <= 0 || $this->height <= 0) {
            return null;
        }

        if ($parseOnly) {
            return [
                'width' => $this->width,
                'height' => $this->height,
                'pix_fmt' => 'yuv420p'
            ];
        }

        // 初始化像素数组，默认中性灰128
        // 使用宏块对齐的尺寸，确保边界宏块有完整的存储空间
        $mbAlignedWidth = $this->picWidthInMbs * 16;
        $mbAlignedHeight = $this->picHeightInMbs * 16;
        $ySize = $mbAlignedWidth * $mbAlignedHeight;
        $uvSize = (int)($ySize / 4);

        // 第二轮：解码图像Slice — 每个Slice是一帧，各自初始化并输出
        //$sliceCount = 0;
        $outputData = '';
        $this->frameNum = -1;
        foreach ($nalUnits as $nal) {
            $nalType = $nal['type'];
            if ($nalType === 1 || $nalType === 5) {
                $nalHeader = ord($nal['raw'][0]);
                $nalRefIdc = ($nalHeader >> 5) & 0x03;
                //$sliceCount++;
                $this->frameNum++;
                // 每帧重新初始化像素平面（128 = H.264 规定的帧内预测默认值）
                $this->yPlane = array_fill(0, $ySize, 128);
                $this->uPlane = array_fill(0, $uvSize, 128);
                $this->vPlane = array_fill(0, $uvSize, 128);
                // 重置全帧 4x4 块级状态（CABAC 邻域 / B帧同位 MV）
                $this->resetPictureState();

                // 保存实际图像尺寸，临时使用宏块对齐的尺寸进行解码
                $origWidth = $this->width;
                $origHeight = $this->height;
                $this->width = $mbAlignedWidth;
                $this->height = $mbAlignedHeight;

                $isIdrNal = ($nalType === 5);
                // IDR关键帧永不跳滤波（参考链重置点，且通常都保留输出）
                $this->decodeSlice($nal['data'], $isIdrNal, $nalRefIdc, $skipDeblock && $nalType !== 5);
                $framePoc = $this->currPicOrderCnt;
                // B 片的解码序≠显示序，启用按 POC 重排输出
                if ($this->currentSliceType === 1) {
                    $this->reorderByPoc = true;
                }
                // 更新参考帧（用于P/B帧运动补偿）- 在恢复尺寸之前进行
                if ($nalRefIdc !== 0 || $nalType === 5) {
                    $dpbEntry = [
                        'frameNum' => $this->currFrameNum,
                        'isLongTerm' => $this->currentIsLongTerm,
                        'poc' => $framePoc,
                        // 同位帧 4x4 运动信息（temporal direct / 邻域调试用）
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
                    $this->dpb[] = $dpbEntry;
                    // 滑动窗口淘汰已在 decodeSlice() 的 slice header 阶段（参考列表构建前）完成

                    $this->refFrameY = $dpbEntry['y'];
                    $this->refFrameU = $dpbEntry['u'];
                    $this->refFrameV = $dpbEntry['v'];
                    $this->refStrideY = $dpbEntry['strideY'];
                    $this->refStrideUv = $dpbEntry['strideUv'];
                    $this->refWidthY = $dpbEntry['widthY'];
                    $this->refHeightY = $dpbEntry['heightY'];
                    $this->refWidthUv = $dpbEntry['widthUv'];
                    $this->refHeightUv = $dpbEntry['heightUv'];

                    // POC 参考状态仅在参考帧后更新（H.264 8.2.1.1）
                    $this->updatePrevPocState($this->currFrameNum, 1 << ($this->log2MaxFrameNumMinus4 + 4), $isIdrNal);
                }

                // 恢复实际图像尺寸
                $this->width = $origWidth;
                $this->height = $origHeight;

                // 将本帧转为二进制（裁剪到实际图像尺寸）
                if ($buildOutput) {
                    $yBin = '';
                    for ($yy = 0; $yy < $this->height; $yy++) {
                        $yBin .= pack('C*', ...array_slice($this->yPlane, $yy * $mbAlignedWidth, $this->width));
                    }
                    $uvMbAlignedWidth = (int)($mbAlignedWidth / 2);
                    $uvWidth = (int)($this->width / 2);
                    $uvHeight = (int)($this->height / 2);
                    $uBin = '';
                    $vBin = '';
                    for ($yy = 0; $yy < $uvHeight; $yy++) {
                        $uBin .= pack('C*', ...array_slice($this->uPlane, $yy * $uvMbAlignedWidth, $uvWidth));
                        $vBin .= pack('C*', ...array_slice($this->vPlane, $yy * $uvMbAlignedWidth, $uvWidth));
                    }
                    $frameBin = $yBin . $uBin . $vBin;
                    if ($this->reorderByPoc) {
                        // 按显示序(POC)延迟排出；流结束需调用 flushReorderQueue()
                        $drained = $this->enqueueAndDrainFrame($frameBin, $this->width, $this->height, $framePoc, $isIdrNal);
                        if ($drained !== null) {
                            $outputData .= $drained['data'];
                        }
                    } else {
                        $outputData .= $frameBin;
                    }
                }
            }
        }

        // POC 重排：流尾冲洗残留在输出队列中的 B 帧（最多 bframes 帧）
        if ($buildOutput && $this->reorderByPoc) {
            foreach ($this->flushReorderQueue() as $f) {
                $outputData .= $f['data'];
            }
        }

        return [
            'data' => $outputData,
            'width' => $this->width,
            'height' => $this->height,
            'pix_fmt' => 'yuv420p'
        ];
    }

    /**
     * 默认缩放矩阵 (intra Y 和 intra C)
     * 来自 H.264 标准 Table 7-3, FFmpeg default_scaling4
     */
    private const DEFAULT_SCALING4_INTRA = [
        6, 13, 20, 28, 13, 20, 28, 32, 20, 28, 32, 37, 28, 32, 37, 42
    ];
    private const DEFAULT_SCALING4_INTER = [
        10, 14, 20, 24, 14, 20, 24, 27, 20, 24, 27, 30, 24, 27, 30, 34
    ];

    /**
     * Dequant coefficient init table (H.264 标准)
     */
    private const DEQUANT4_COEFF_INIT = [
        [10, 13, 16],
        [11, 14, 18],
        [13, 16, 20],
        [14, 18, 23],
        [16, 20, 25],
        [18, 23, 29],
    ];

    public function getWidth()
    {

        return $this->width;
    }

    public function getHeight(){
        return $this->height;
    }
}
