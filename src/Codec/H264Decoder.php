<?php

namespace Xiaosongshu\Flv2mp4\Codec;

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
 * @purpose H.264解码器 - 支持 baseline profile (I帧 + P帧)
 * @author yanglong
 * @time 2026年7月23日14:41:47
 */
class H264Decoder
{
    use SpsPpsParsingTrait, SliceDecodingTrait, MacroblockDecodingTrait, IntraPredictionTrait, ResidualDecodingTrait, TransformTrait, DeblockingFilterTrait, MotionCompensationTrait, MotionVectorPredictionTrait;

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
    public bool $entropyCodingModeFlag = false;
    public bool $deblockingFilterParametersPresent = false;
    public bool $redundantPicCntPresent = false;
    public bool $bottomFieldPicOrderInFramePresent = false;

    public array $quantMatrix = [];
    public array $dequant4Table = [];

    // 像素缓冲区：数组替代字符串，解决PHP字符串下标修改卡顿/偏移bug
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
    public int $currentSliceType = 0;
    public bool $forceDisableDeblock = false;

    // P帧参考帧控制
    public bool $numRefIdxActiveOverrideFlag = false;
    public int $numRefIdxL0Active = 1;

    // 参考帧管理
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
    public array $mvLeftCol = [];     // 左方宏块列的运动向量，4行4x4块

    public $debugLastQp = 0;
    public $debugLastDcScan = [];
    public $debugLastDcRaster = [];
    public $debugLastQmul = 0;
    public $debugLastDcResult = [];

    public int $debugSliceIndex = 0;
    public int $debugTargetSlice = 0;
    public $debugMbTraceFh = null;

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

    public function initQuantMatrix(): void
    {
        // H.264标准7.4.2.1节：当SPS/PPS都没有scaling_matrix_present_flag时，
        // scaling_matrix4使用Flat_4x4_16（全16），而不是默认的intra/inter scaling matrix。
        // 默认intra/inter scaling matrix只在显式指定scaling matrix但某个list缺失时作为fallback。
        $flatScaling4 = array_fill(0, 16, 16);

        $scalingMatrices = [
            $flatScaling4, $flatScaling4, $flatScaling4,
            $flatScaling4, $flatScaling4, $flatScaling4,
        ];

        $this->quantMatrix = $scalingMatrices;

        // 构建反量化表
        // table[qp][x] = INIT[qp%6][scale_idx] * scaling_matrix[x] << (qp/6 + 2)
        // scale_idx = (x & 1) + ((x >> 2) & 1) = posClass[x]
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
    public function decode(array $nalUnits, bool $parseOnly = false): ?array
    {
        //echo "[DECODER] Start decoding, " . count($nalUnits) . " NAL units, parseOnly=" . ($parseOnly ? 'true' : 'false') . PHP_EOL;

        // 记录之前的分辨率（用于判断是否需要重新初始化）
        $prevWidth = $this->width;
        $prevHeight = $this->height;

        // 重置像素平面（但保留 SPS/PPS 解析结果）
        $this->yPlane = [];
        $this->uPlane = [];
        $this->vPlane = [];

        // 第一轮：解析SPS/PPS获取分辨率
        //echo "[DECODER] Step 1: Parsing SPS/PPS..." . PHP_EOL;
        foreach ($nalUnits as $nal) {
            $nalType = $nal['type'];
            if ($nalType === 7) {
                //echo "[DECODER]   Parsing SPS (data size=" . strlen($nal['data']) . ")" . PHP_EOL;
                $this->parseSPS($nal['data']);
            } elseif ($nalType === 8) {
                //echo "[DECODER]   Parsing PPS (data size=" . strlen($nal['data']) . ")" . PHP_EOL;
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
        $sliceCount = 0;
        $outputData = '';
        foreach ($nalUnits as $nal) {
            $nalType = $nal['type'];
            if ($nalType === 1 || $nalType === 5) {
                $nalHeader = ord($nal['raw'][0]);
                $nalRefIdc = ($nalHeader >> 5) & 0x03;
                $sliceCount++;
                $this->debugSliceIndex = $sliceCount;
                // 每帧重新初始化像素平面
                $this->yPlane = array_fill(0, $ySize, 128);
                $this->uPlane = array_fill(0, $uvSize, 128);
                $this->vPlane = array_fill(0, $uvSize, 128);

                // 保存实际图像尺寸，临时使用宏块对齐的尺寸进行解码
                $origWidth = $this->width;
                $origHeight = $this->height;
                $this->width = $mbAlignedWidth;
                $this->height = $mbAlignedHeight;

                $this->decodeSlice($nal['data'], $nalType === 5, $nalRefIdc);
                // 更新参考帧（用于P帧运动补偿）- 在恢复尺寸之前进行
                if ($nalRefIdc !== 0 || $nalType === 5) {
                    $this->refFrameY = array_values($this->yPlane);
                    $this->refFrameU = array_values($this->uPlane);
                    $this->refFrameV = array_values($this->vPlane);
                    $this->refStrideY = $mbAlignedWidth;
                    $this->refStrideUv = (int)($mbAlignedWidth / 2);
                    $this->refWidthY = $mbAlignedWidth;
                    $this->refHeightY = $mbAlignedHeight;
                    $this->refWidthUv = (int)($mbAlignedWidth / 2);
                    $this->refHeightUv = (int)($mbAlignedHeight / 2);
                }

                // 恢复实际图像尺寸
                $this->width = $origWidth;
                $this->height = $origHeight;

                // 将本帧转为二进制并追加到输出（裁剪到实际图像尺寸）
                $yBin = '';
                for ($y = 0; $y < $this->height; $y++) {
                    $yBin .= implode('', array_map('chr', array_slice($this->yPlane, $y * $mbAlignedWidth, $this->width)));
                }
                $uvMbAlignedWidth = (int)($mbAlignedWidth / 2);
                $uvWidth = (int)($this->width / 2);
                $uvHeight = (int)($this->height / 2);
                $uBin = '';
                $vBin = '';
                for ($y = 0; $y < $uvHeight; $y++) {
                    $uBin .= implode('', array_map('chr', array_slice($this->uPlane, $y * $uvMbAlignedWidth, $uvWidth)));
                    $vBin .= implode('', array_map('chr', array_slice($this->vPlane, $y * $uvMbAlignedWidth, $uvWidth)));
                }
                $outputData .= $yBin . $uBin . $vBin;

                if ($sliceCount === $this->debugTargetSlice && $this->debugMbTraceFh) {
                    fclose($this->debugMbTraceFh);
                    $this->debugMbTraceFh = null;
                }
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
