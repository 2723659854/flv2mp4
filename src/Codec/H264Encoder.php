<?php

namespace Xiaosongshu\Flv2mp4\Codec;

/**
 * @purpose yuv重建h264
 * @author yanglong
 * @time 2026年7月23日14:48:28
 */
class H264Encoder
{
    public const DEQUANT4_COEFF_INIT = [
        [10, 13, 16],
        [11, 14, 18],
        [13, 16, 20],
        [14, 18, 23],
        [16, 20, 25],
        [18, 23, 29],
    ];

    /**
     * 量化乘法因子表
     * 索引: QP (0-51), j = position & 7
     */
    public const QUANT_MF = [
        [26214, 16132, 26214, 16132, 16132, 10486, 16132, 10486],
        [23832, 14980, 23832, 14980, 14980,  9320, 14980,  9320],
        [20164, 13108, 20164, 13108, 13108,  8388, 13108,  8388],
        [18724, 11650, 18724, 11650, 11650,  7294, 11650,  7294],
        [16384, 10486, 16384, 10486, 10486,  6710, 10486,  6710],
        [14564,  9118, 14564,  9118,  9118,  5786,  9118,  5786],
        [13107,  8066, 13107,  8066,  8066,  5243,  8066,  5243],
        [11916,  7490, 11916,  7490,  7490,  4660,  7490,  4660],
        [10082,  6554, 10082,  6554,  6554,  4194,  6554,  4194],
        [ 9362,  5825,  9362,  5825,  5825,  3647,  5825,  3647],
        [ 8192,  5243,  8192,  5243,  5243,  3355,  5243,  3355],
        [ 7282,  4559,  7282,  4559,  4559,  2893,  4559,  2893],
        [ 6554,  4033,  6554,  4033,  4033,  2622,  4033,  2622],
        [ 5958,  3745,  5958,  3745,  3745,  2330,  3745,  2330],
        [ 5041,  3277,  5041,  3277,  3277,  2097,  3277,  2097],
        [ 4681,  2913,  4681,  2913,  2913,  1824,  2913,  1824],
        [ 4096,  2622,  4096,  2622,  2622,  1678,  2622,  1678],
        [ 3641,  2280,  3641,  2280,  2280,  1447,  2280,  1447],
        [ 3277,  2017,  3277,  2017,  2017,  1311,  2017,  1311],
        [ 2979,  1873,  2979,  1873,  1873,  1165,  1873,  1165],
        [ 2521,  1639,  2521,  1639,  1639,  1049,  1639,  1049],
        [ 2341,  1456,  2341,  1456,  1456,   912,  1456,   912],
        [ 2048,  1311,  2048,  1311,  1311,   839,  1311,   839],
        [ 1821,  1140,  1821,  1140,  1140,   723,  1140,   723],
        [ 1638,  1008,  1638,  1008,  1008,   655,  1008,   655],
        [ 1490,   936,  1490,   936,   936,   583,   936,   583],
        [ 1260,   819,  1260,   819,   819,   524,   819,   524],
        [ 1170,   728,  1170,   728,   728,   456,   728,   456],
        [ 1024,   655,  1024,   655,   655,   419,   655,   419],
        [  910,   570,   910,   570,   570,   362,   570,   362],
        [  819,   504,   819,   504,   504,   328,   504,   328],
        [  745,   468,   745,   468,   468,   291,   468,   291],
        [  630,   410,   630,   410,   410,   262,   410,   262],
        [  585,   364,   585,   364,   364,   228,   364,   228],
        [  512,   328,   512,   328,   328,   210,   328,   210],
        [  455,   285,   455,   285,   285,   181,   285,   181],
        [  410,   252,   410,   252,   252,   164,   252,   164],
        [  372,   234,   372,   234,   234,   146,   234,   146],
        [  315,   205,   315,   205,   205,   131,   205,   131],
        [  293,   182,   293,   182,   182,   114,   182,   114],
        [  256,   164,   256,   164,   164,   105,   164,   105],
        [  228,   142,   228,   142,   142,    90,   142,    90],
        [  205,   126,   205,   126,   126,    82,   126,    82],
        [  186,   117,   186,   117,   117,    73,   117,    73],
        [  158,   102,   158,   102,   102,    66,   102,    66],
        [  146,    91,   146,    91,    91,    57,    91,    57],
        [  128,    82,   128,    82,    82,    52,    82,    52],
        [  114,    71,   114,    71,    71,    45,    71,    45],
        [  102,    63,   102,    63,    63,    41,    63,    41],
        [   93,    59,    93,    59,    59,    36,    59,    36],
        [   79,    51,    79,    51,    51,    33,    51,    33],
        [   73,    46,    73,    46,    46,    28,    46,    28],
    ];

    /**
     * g_kiQuantInterFF[58][8] - 量化偏移因子表
     * Inter: 索引 = QP (0-51)
     * Intra: 索引 = QP + 6 (g_iQuantIntraFF = g_kiQuantInterFF + 6)
     */
    public const QUANT_INTER_FF = [
        [  0,   1,   0,   1,   1,   1,   1,   1],
        [  0,   1,   0,   1,   1,   1,   1,   1],
        [  1,   1,   1,   1,   1,   1,   1,   1],
        [  1,   1,   1,   1,   1,   1,   1,   1],
        [  1,   1,   1,   1,   1,   2,   1,   2],
        [  1,   1,   1,   1,   1,   2,   1,   2],
        [  1,   1,   1,   1,   1,   2,   1,   2],
        [  1,   1,   1,   1,   1,   2,   1,   2],
        [  1,   2,   1,   2,   2,   3,   2,   3],
        [  1,   2,   1,   2,   2,   3,   2,   3],
        [  1,   2,   1,   2,   2,   3,   2,   3],
        [  1,   2,   1,   2,   2,   4,   2,   4],
        [  2,   3,   2,   3,   3,   4,   3,   4],
        [  2,   3,   2,   3,   3,   5,   3,   5],
        [  2,   3,   2,   3,   3,   5,   3,   5],
        [  2,   4,   2,   4,   4,   6,   4,   6],
        [  3,   4,   3,   4,   4,   7,   4,   7],
        [  3,   5,   3,   5,   5,   8,   5,   8],
        [  3,   5,   3,   5,   5,   8,   5,   8],
        [  4,   6,   4,   6,   6,   9,   6,   9],
        [  4,   7,   4,   7,   7,  10,   7,  10],
        [  5,   8,   5,   8,   8,  12,   8,  12],
        [  5,   8,   5,   8,   8,  13,   8,  13],
        [  6,  10,   6,  10,  10,  15,  10,  15],
        [  7,  11,   7,  11,  11,  17,  11,  17],
        [  7,  12,   7,  12,  12,  19,  12,  19],
        [  9,  13,   9,  13,  13,  21,  13,  21],
        [  9,  15,   9,  15,  15,  24,  15,  24],
        [ 11,  17,  11,  17,  17,  26,  17,  26],
        [ 12,  19,  12,  19,  19,  30,  19,  30],
        [ 13,  22,  13,  22,  22,  33,  22,  33],
        [ 15,  23,  15,  23,  23,  38,  23,  38],
        [ 17,  27,  17,  27,  27,  42,  27,  42],
        [ 19,  30,  19,  30,  30,  48,  30,  48],
        [ 21,  33,  21,  33,  33,  52,  33,  52],
        [ 24,  38,  24,  38,  38,  60,  38,  60],
        [ 27,  43,  27,  43,  43,  67,  43,  67],
        [ 29,  47,  29,  47,  47,  75,  47,  75],
        [ 35,  53,  35,  53,  53,  83,  53,  83],
        [ 37,  60,  37,  60,  60,  96,  60,  96],
        [ 43,  67,  43,  67,  67, 104,  67, 104],
        [ 48,  77,  48,  77,  77, 121,  77, 121],
        [ 53,  87,  53,  87,  87, 133,  87, 133],
        [ 59,  93,  59,  93,  93, 150,  93, 150],
        [ 69, 107,  69, 107, 107, 167, 107, 167],
        [ 75, 120,  75, 120, 120, 192, 120, 192],
        [ 85, 133,  85, 133, 133, 208, 133, 208],
        [ 96, 153,  96, 153, 153, 242, 153, 242],
        [107, 173, 107, 173, 173, 267, 173, 267],
        [117, 187, 117, 187, 187, 300, 187, 300],
        [139, 213, 139, 213, 213, 333, 213, 333],
        [149, 240, 149, 240, 240, 383, 240, 383],
        [171, 267, 171, 267, 267, 417, 267, 417],
        [192, 307, 192, 307, 307, 483, 307, 483],
        [213, 347, 213, 347, 347, 533, 347, 533],
        [235, 373, 235, 373, 373, 600, 373, 600],
        [277, 427, 277, 427, 427, 667, 427, 667],
        [299, 480, 299, 480, 480, 767, 480, 767],
    ];

    public const ZIGZAG_SCAN_4X4 = [0, 1, 4, 8, 5, 2, 3, 6, 9, 12, 13, 10, 7, 11, 14, 15];

    public const CT_INDEX = [0, 0, 1, 1, 2, 2, 2, 2, 3, 3, 3, 3, 3, 3, 3, 3, 3];

    public const ENC_NC_MAP_TABLE = [0, 0, 1, 1, 2, 2, 2, 2, 3, 3, 3, 3, 3, 3, 3, 3, 3, 4];

    public const VLC_COEFF_TOKEN = [
        [
            //0<=nc<2
            [[1,1],[0,0],[0,0],[0,0]],
            [[5,6],[1,2],[0,0],[0,0]],
            [[7,8],[4,6],[1,3],[0,0]],
            [[7,9],[6,8],[5,7],[3,5]],
            [[7,10],[6,9],[5,8],[3,6]],
            [[7,11],[6,10],[5,9],[4,7]],
            [[15,13],[6,11],[5,10],[4,8]],
            [[11,13],[14,13],[5,11],[4,9]],
            [[8,13],[10,13],[13,13],[4,10]],
            [[15,14],[14,14],[9,13],[4,11]],
            [[11,14],[10,14],[13,14],[12,13]],
            [[15,15],[14,15],[9,14],[12,14]],
            [[11,15],[10,15],[13,15],[8,14]],
            [[15,16],[1,15],[9,15],[12,15]],
            [[11,16],[14,16],[13,16],[8,15]],
            [[7,16],[10,16],[9,16],[12,16]],
            [[4,16],[6,16],[5,16],[8,16]],
        ],
        [
            //2<=nc<4
            [[3,2],[0,0],[0,0],[0,0]],
            [[11,6],[2,2],[0,0],[0,0]],
            [[7,6],[7,5],[3,3],[0,0]],
            [[7,7],[10,6],[9,6],[5,4]],
            [[7,8],[6,6],[5,6],[4,4]],
            [[4,8],[6,7],[5,7],[6,5]],
            [[7,9],[6,8],[5,8],[8,6]],
            [[15,11],[6,9],[5,9],[4,6]],
            [[11,11],[14,11],[13,11],[4,7]],
            [[15,12],[10,11],[9,11],[4,9]],
            [[11,12],[14,12],[13,12],[12,11]],
            [[8,12],[10,12],[9,12],[8,11]],
            [[15,13],[14,13],[13,13],[12,12]],
            [[11,13],[10,13],[9,13],[12,13]],
            [[7,13],[11,14],[6,13],[8,13]],
            [[9,14],[8,14],[10,14],[1,13]],
            [[7,14],[6,14],[5,14],[4,14]],
        ],
        [
            //4<=nc<8
            [[15,4],[0,0],[0,0],[0,0]],
            [[15,6],[14,4],[0,0],[0,0]],
            [[11,6],[15,5],[13,4],[0,0]],
            [[8,6],[12,5],[14,5],[12,4]],
            [[15,7],[10,5],[11,5],[11,4]],
            [[11,7],[8,5],[9,5],[10,4]],
            [[9,7],[14,6],[13,6],[9,4]],
            [[8,7],[10,6],[9,6],[8,4]],
            [[15,8],[14,7],[13,7],[13,5]],
            [[11,8],[14,8],[10,7],[12,6]],
            [[15,9],[10,8],[13,8],[12,7]],
            [[11,9],[14,9],[9,8],[12,8]],
            [[8,9],[10,9],[13,9],[8,8]],
            [[13,10],[7,9],[9,9],[12,9]],
            [[9,10],[12,10],[11,10],[10,10]],
            [[5,10],[8,10],[7,10],[6,10]],
            [[1,10],[4,10],[3,10],[2,10]],
        ],
        [
            //8<=nc
            [[3,6],[0,0],[0,0],[0,0]],
            [[0,6],[1,6],[0,0],[0,0]],
            [[4,6],[5,6],[6,6],[0,0]],
            [[8,6],[9,6],[10,6],[11,6]],
            [[12,6],[13,6],[14,6],[15,6]],
            [[16,6],[17,6],[18,6],[19,6]],
            [[20,6],[21,6],[22,6],[23,6]],
            [[24,6],[25,6],[26,6],[27,6]],
            [[28,6],[29,6],[30,6],[31,6]],
            [[32,6],[33,6],[34,6],[35,6]],
            [[36,6],[37,6],[38,6],[39,6]],
            [[40,6],[41,6],[42,6],[43,6]],
            [[44,6],[45,6],[46,6],[47,6]],
            [[48,6],[49,6],[50,6],[51,6]],
            [[52,6],[53,6],[54,6],[55,6]],
            [[56,6],[57,6],[58,6],[59,6]],
            [[60,6],[61,6],[62,6],[63,6]],
        ],
        [
            //nc == -1 (chroma DC)
            [[1,2],[0,0],[0,0],[0,0]],
            [[7,6],[1,1],[0,0],[0,0]],
            [[4,6],[6,6],[1,3],[0,0]],
            [[3,6],[3,7],[2,7],[5,6]],
            [[2,6],[3,8],[2,8],[0,7]],
            [[0,0],[0,0],[0,0],[0,0]],
            [[0,0],[0,0],[0,0],[0,0]],
            [[0,0],[0,0],[0,0],[0,0]],
            [[0,0],[0,0],[0,0],[0,0]],
            [[0,0],[0,0],[0,0],[0,0]],
            [[0,0],[0,0],[0,0],[0,0]],
            [[0,0],[0,0],[0,0],[0,0]],
            [[0,0],[0,0],[0,0],[0,0]],
            [[0,0],[0,0],[0,0],[0,0]],
            [[0,0],[0,0],[0,0],[0,0]],
            [[0,0],[0,0],[0,0],[0,0]],
            [[0,0],[0,0],[0,0],[0,0]],
        ],
    ];

    public const VLC_TOTAL_ZEROS = [
        [[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0]],
        [[1,1],[3,3],[2,3],[3,4],[2,4],[3,5],[2,5],[3,6],[2,6],[3,7],[2,7],[3,8],[2,8],[3,9],[2,9],[1,9]],
        [[7,3],[6,3],[5,3],[4,3],[3,3],[5,4],[4,4],[3,4],[2,4],[3,5],[2,5],[3,6],[2,6],[1,6],[0,6],[0,0]],
        [[5,4],[7,3],[6,3],[5,3],[4,4],[3,4],[4,3],[3,3],[2,4],[3,5],[2,5],[1,6],[1,5],[0,6],[0,0],[0,0]],
        [[3,5],[7,3],[5,4],[4,4],[6,3],[5,3],[4,3],[3,4],[3,3],[2,4],[2,5],[1,5],[0,5],[0,0],[0,0],[0,0]],
        [[5,4],[4,4],[3,4],[7,3],[6,3],[5,3],[4,3],[3,3],[2,4],[1,5],[1,4],[0,5],[0,0],[0,0],[0,0],[0,0]],
        [[1,6],[1,5],[7,3],[6,3],[5,3],[4,3],[3,3],[2,3],[1,4],[1,3],[0,6],[0,0],[0,0],[0,0],[0,0],[0,0]],
        [[1,6],[1,5],[5,3],[4,3],[3,3],[3,2],[2,3],[1,4],[1,3],[0,6],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0]],
        [[1,6],[1,4],[1,5],[3,3],[3,2],[2,2],[2,3],[1,3],[0,6],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0]],
        [[1,6],[0,6],[1,4],[3,2],[2,2],[1,3],[1,2],[1,5],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0]],
        [[1,5],[0,5],[1,3],[3,2],[2,2],[1,2],[1,4],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0]],
        [[0,4],[1,4],[1,3],[2,3],[1,1],[3,3],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0]],
        [[0,4],[1,4],[1,2],[1,1],[1,3],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0]],
        [[0,3],[1,3],[1,1],[1,2],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0]],
        [[0,2],[1,2],[1,1],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0]],
        [[0,1],[1,1],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0]],
    ];

    public const VLC_TOTAL_ZEROS_CHROMA_DC = [
        [[0,0],[0,0],[0,0],[0,0]],
        [[1,1],[1,2],[1,3],[0,3]],
        [[1,1],[1,2],[0,2],[0,0]],
        [[1,1],[0,1],[0,0],[0,0]],
    ];

    public const VLC_RUN_BEFORE = [
        [[1,1],[0,1],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0]],
        [[1,1],[1,2],[0,2],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0]],
        [[3,2],[2,2],[1,2],[0,2],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0]],
        [[3,2],[2,2],[1,2],[1,3],[0,3],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0]],
        [[3,2],[2,2],[3,3],[2,3],[1,3],[0,3],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0]],
        [[3,2],[0,3],[1,3],[3,3],[2,3],[5,3],[4,3],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0]],
        [[7,3],[6,3],[5,3],[4,3],[3,3],[2,3],[1,3],[1,4],[1,5],[1,6],[1,7],[1,8],[1,9],[1,10],[1,11]],
    ];

    public const ZERO_LEFT_MAP = [0, 0, 1, 2, 3, 4, 5, 6, 6, 6, 6, 6, 6, 6, 6, 6];

    public const MB_TYPE_I16x16 = 0;
    public const MB_TYPE_I4x4 = 1;
    public const MB_TYPE_P_16x16 = 2;
    public const MB_TYPE_P_16x8 = 3;
    public const MB_TYPE_P_8x16 = 4;
    public const MB_TYPE_P_8x8 = 5;

    public $width = 640;
    public $height = 360;
    public $fps = 30;
    public $bitrate = 500000;
    public $qp = 22;
    public $chromaQpIndexOffset = 0;
    public $mbType = self::MB_TYPE_I16x16;

    public $frameNum = 0;
    public $idrPicId = 0;
    public $poc = 0;

    public $log2MaxFrameNumMinus4 = 0;
    public $log2MaxPicOrderCntLsbMinus4 = 0;

    public $quantMatrix = [];

    // P帧参考帧管理
    public $refYPlane = null;      // 参考帧Y平面
    public $refUPlane = null;      // 参考帧U平面
    public $refVPlane = null;      // 参考帧V平面
    public $enableInter = false;   // 是否启用P帧
    public $numRefFrames = 1;      // 参考帧数量

    public function __construct(int $width = 0, int $height = 0, int $fps = 25, int $bitrate = 1000000)
    {
        $this->width = $width;
        $this->height = $height;
        $this->fps = $fps;
        $this->bitrate = $bitrate;
        $this->initQuantMatrix();
    }

    public function initQuantMatrix(): void
    {
        $this->quantMatrix[0] = [6, 13, 20, 28, 13, 20, 28, 32, 20, 28, 32, 37, 28, 32, 37, 42];
        $this->quantMatrix[1] = [6, 13, 20, 28, 13, 20, 28, 32, 20, 28, 32, 37, 28, 32, 37, 42];
    }

    public function setResolution(int $width, int $height): void
    {
        $this->width = $width;
        $this->height = $height;
    }

    public function setQp(int $qp): void
    {
        if ($qp < 0) $qp = 0;
        if ($qp > 51) $qp = 51;
        $this->qp = $qp;
    }

    public function setMbType(int $type): void
    {
        $this->mbType = $type;
    }

    public function setFps(int $fps): void
    {
        $this->fps = $fps;
    }

    public function setBitrate(int $bitrate): void
    {
        $this->bitrate = $bitrate;
        $logBitrate = log(max(100000, $bitrate));
        $logRef = log(100000);
        $logMax = log(10000000);
        $qpRange = 38 - 18;
        $ratio = ($logBitrate - $logRef) / ($logMax - $logRef);
        $this->qp = (int)round(38 - $ratio * $qpRange);
        $this->qp = max(18, min(38, $this->qp));
    }

    public function encodeFrame(string $yuvData, bool $isKeyframe = false): array
    {
        $nalUnits = [];
        if ($isKeyframe) {
            // I帧：重置参考帧和计数器
            $this->refYPlane = null;
            $this->refUPlane = null;
            $this->refVPlane = null;
            $this->frameNum = 0;
            $this->idrPicId++;
            $this->poc = 0;

            $nalUnits[] = $this->generateSPS();
            $nalUnits[] = $this->generatePPS();
        }
        $sliceData = $this->encodeSlice($yuvData, $isKeyframe);
        $nalUnits[] = $sliceData;
        return $nalUnits;
    }

    public function generateSPS(): string
    {
        $profileIdc = 66;
        $levelIdc = 10;

        $picWidthInMbs = (int)ceil($this->width / 16);
        $picHeightInMapUnits = (int)ceil($this->height / 16);

        $bits = '';

        $bits .= $this->u($profileIdc, 8);

        $bits .= '1';
        $bits .= '1';
        $bits .= '0';
        $bits .= '0';
        $bits .= '0000';
        $bits .= $this->u($levelIdc, 8);

        $bits .= $this->ue(0);

        $this->log2MaxFrameNumMinus4 = 3;
        $bits .= $this->ue($this->log2MaxFrameNumMinus4);

        $picOrderCntType = 0;
        $bits .= $this->ue($picOrderCntType);

        $this->log2MaxPicOrderCntLsbMinus4 = 0;
        $bits .= $this->ue($this->log2MaxPicOrderCntLsbMinus4);

        $numRefFrames = 1;
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
        $bits = '';
        $bits .= '0';
        $bits .= '0';
        $bits .= '0';
        $bits .= '0';
        $bits .= '0';
        $bits .= '0';
        $bits .= '0';
        $bits .= '0';
        $bits .= '1';

        $bits .= '1';
        $bits .= $this->ue(0);
        $bits .= $this->ue(0);
        $bits .= $this->ue(16);
        $bits .= $this->ue(16);
        $bits .= $this->ue(0);
        $bits .= $this->ue(1);
        return $bits;
    }

    public function generatePPS()
    {
        $bits = '';

        $bits .= $this->ue(0);              // pic_parameter_set_id = 0
        $bits .= $this->ue(0);              // seq_parameter_set_id = 0
        $bits .= '0';                       // entropy_coding_mode_flag = 0 (CAVLC)
        $bits .= '0';                       // bottom_field_pic_order_in_frame_present_flag = 0
        $bits .= $this->ue(0);              // num_slice_groups_minus1 = 0
        $bits .= $this->ue(0);              // num_ref_idx_l0_default_active_minus1 = 0
        $bits .= $this->ue(0);              // num_ref_idx_l1_default_active_minus1 = 0
        $bits .= '0';                       // weighted_pred_flag = 0
        $bits .= '00';                      // weighted_bipred_idc = 0
        $bits .= $this->se($this->qp - 26); // pic_init_qp_minus26
        $bits .= $this->se(0);              // pic_init_qs_minus26
        $bits .= $this->se($this->chromaQpIndexOffset); // chroma_qp_index_offset
        $bits .= '0';                       // deblocking_filter_control_present_flag = 0
        $bits .= '0';                       // constrained_intra_pred_flag = 0
        $bits .= '0';                       // redundant_pic_cnt_present_flag = 0

        $bits .= '1';                       // rbsp_stop_one_bit
        while (strlen($bits) % 8 != 0) $bits .= '0';
        return $this->rbspToNal($this->bitsToBytes($bits), 8);
    }

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

        $mbWidth = (int)ceil($this->width / 16);
        $mbHeight = (int)ceil($this->height / 16);
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

        for ($mbY = 0; $mbY < $mbHeight; $mbY++) {
            $leftAvailable = false;
            $leftNz = [0, 0, 0, 0, 0, 0, 0, 0];
            $leftIntra4x4Mode = [-1, -1, -1, -1];
            for ($mbX = 0; $mbX < $mbWidth; $mbX++) {
                if ($sliceType === 0 && $this->refYPlane !== null) {
                    // P帧编码
                    $mbBits = $this->encodePMacroblock(
                        $mbX, $mbY, $yPlane, $uPlane, $vPlane,
                        $leftAvailable, $leftNz, $mbY > 0,
                        $topNzLuma, $topNzCb, $topNzCr,
                        $leftIntra4x4Mode, $topIntra4x4Mode,
                        $this->refYPlane
                    );
                } else {
                    // I帧编码
                    $mbBits = $this->encodeMacroblock(
                        $mbX, $mbY, $yPlane, $uPlane, $vPlane,
                        $leftAvailable, $leftNz, $mbY > 0,
                        $topNzLuma, $topNzCb, $topNzCr,
                        $leftIntra4x4Mode, $topIntra4x4Mode
                    );
                }
                $bits .= $mbBits;
                $leftAvailable = true;
            }
        }
        $bits .= '1';
        while (strlen($bits) % 8 != 0) $bits .= '0';

        $rbsp = $this->bitsToBytes($bits);
        $nalType = 1; // 非IDR片
        if ($isIDR) $nalType = 5; // IDR片
        $nal = $this->rbspToNal($rbsp, $nalType);

        // 保存当前帧作为下一帧的参考帧
        $this->refYPlane = $yPlane;
        $this->refUPlane = $uPlane;
        $this->refVPlane = $vPlane;

        // 更新帧序号
        $this->frameNum++;
        $this->poc += 2;

        return $nal;
    }

    public function encodeMacroblock(
        $mbX, $mbY, $yPlane, $uPlane, $vPlane,
        $leftAvailable, &$leftNz, $topAvailable,
        &$topNzLuma, &$topNzCb, &$topNzCr,
        &$leftIntra4x4Mode = null, &$topIntra4x4Mode = null
    )
    {
        if ($this->mbType === self::MB_TYPE_I16x16) {
            return $this->encodeMacroblockI16x16($mbX, $mbY, $yPlane, $uPlane, $vPlane, $leftAvailable, $leftNz, $topAvailable, $topNzLuma, $topNzCb, $topNzCr);
        } else {
            return $this->encodeMacroblockI4x4($mbX, $mbY, $yPlane, $uPlane, $vPlane, $leftAvailable, $leftNz, $topAvailable, $topNzLuma, $topNzCb, $topNzCr, $leftIntra4x4Mode, $topIntra4x4Mode);
        }
    }

    public function encodeMacroblockI16x16(
        $mbX, $mbY, $yPlane, $uPlane, $vPlane,
        $leftAvailable, &$leftNz, $topAvailable,
        &$topNzLuma, &$topNzCb, &$topNzCr
    )
    {
        $bits = '';
        $chromaWidth = (int)($this->width / 2);
        $lumaPixels = array_fill(0, 16, array_fill(0, 16, 128));
        for ($y = 0; $y < 16; $y++) {
            $py = $mbY * 16 + $y;
            if ($py >= $this->height) break;
            for ($x = 0; $x < 16; $x++) {
                $px = $mbX * 16 + $x;
                if ($px >= $this->width) break;
                $idx = $py * $this->width + $px;
                $lumaPixels[$y][$x] = ord($yPlane[$idx]);
            }
        }

        $leftPixels = array_fill(0, 16, 128);
        $topPixels = array_fill(0, 16, 128);
        $leftSum = 0;
        $topSum = 0;
        $cntL = 0;
        $cntT = 0;
        if ($leftAvailable) {
            $refX = $mbX * 16 - 1;
            for ($y = 0; $y < 16; $y++) {
                $py = $mbY * 16 + $y;
                $idx = $py * $this->width + $refX;
                if ($idx < strlen($yPlane)) {
                    $leftPixels[$y] = ord($yPlane[$idx]);
                    $leftSum += $leftPixels[$y];
                    $cntL++;
                }
            }
        }
        if ($topAvailable) {
            $refY = $mbY * 16 - 1;
            for ($x = 0; $x < 16; $x++) {
                $px = $mbX * 16 + $x;
                $idx = $refY * $this->width + $px;
                if ($idx < strlen($yPlane)) {
                    $topPixels[$x] = ord($yPlane[$idx]);
                    $topSum += $topPixels[$x];
                    $cntT++;
                }
            }
        }

        $lumaPredMode = 2;

        $predPixels = array_fill(0, 16, array_fill(0, 16, 128));
        for ($y = 0; $y < 16; $y++) {
            for ($x = 0; $x < 16; $x++) {
                switch ($lumaPredMode) {
                    case 0:
                        $predPixels[$y][$x] = $topPixels[$x];
                        break;
                    case 1:
                        $predPixels[$y][$x] = $leftPixels[$y];
                        break;
                    case 2:
                        if ($cntL && $cntT) $predPixels[$y][$x] = ($topSum + $leftSum + 16) >> 5;
                        elseif ($cntL) $predPixels[$y][$x] = ($leftSum + 8) >> 4;
                        elseif ($cntT) $predPixels[$y][$x] = ($topSum + 8) >> 4;
                        else $predPixels[$y][$x] = 128;
                        break;
                    case 3:
                        $a = $topPixels[$x];
                        $b = $leftPixels[$y];
                        $c = ($x > 0) ? $topPixels[$x - 1] : 128;
                        $d = ($y > 0) ? $leftPixels[$y - 1] : 128;
                        $predPixels[$y][$x] = (int)(($a + $b - $c - $d + $x * ($d - $c) + $y * ($c - $a) + 16) >> 5);
                        break;
                }
            }
        }

        $dc4x4Raw = array_fill(0, 4, array_fill(0, 4, 0));
        $quant4x4Luma = array_fill(0, 16, array_fill(0, 16, 0));
        $nzCache = array_fill(0, 24, 0);
        $cbpLuma = 0;
        for ($by = 0; $by < 4; $by++) {
            for ($bx = 0; $bx < 4; $bx++) {
                $blkIdx = $by * 4 + $bx;
                $blk4x4 = array_fill(0, 4, array_fill(0, 4, 0));
                for ($y = 0; $y < 4; $y++) for ($x = 0; $x < 4; $x++) {
                    $py = $by * 4 + $y;
                    $px = $bx * 4 + $x;
                    $pv = $lumaPixels[$py][$px];
                    $pred = $predPixels[$py][$px];
                    $blk4x4[$y][$x] = $pv - $pred;
                }
                $dctBlock = $this->dct($blk4x4);
                $dc4x4Raw[$by][$bx] = $dctBlock[0][0];
                $quantBlock = $this->quantize($dctBlock, 0);
                for ($yy = 0; $yy < 4; $yy++) for ($xx = 0; $xx < 4; $xx++) {
                    $quant4x4Luma[$blkIdx][$yy * 4 + $xx] = $quantBlock[$yy][$xx];
                }
                $nz = 0;
                for ($i = 1; $i < 16; $i++) {
                    if ($quant4x4Luma[$blkIdx][$i] !== 0) $nz++;
                }
                $nzCache[$blkIdx] = min(15, $nz);
                if ($nz > 0) {
                    $cbpLuma |= 1 << (int)($blkIdx / 4);
                }
            }
        }

        $dcHadamard = $this->hadamardTransformDC($dc4x4Raw);
        $dcQuant = $this->quantizeDCMatrix($dcHadamard, $this->qp);
        $dcFlat = [];
        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                $dcFlat[] = $dcQuant[$y][$x];
            }
        }
        $dcZigzag = [];
        for ($i = 0; $i < 16; $i++) {
            $dcZigzag[$i] = $dcFlat[self::ZIGZAG_SCAN_4X4[$i]];
        }

        $hasLumaDc = false;
        for ($i = 0; $i < 16; $i++) {
            if ($dcZigzag[$i] !== 0) {
                $hasLumaDc = true;
                break;
            }
        }
        if ($hasLumaDc && $cbpLuma === 0) {
            $cbpLuma = 15;
        }

        $u8x8 = array_fill(0, 8, array_fill(0, 8, 128));
        $v8x8 = array_fill(0, 8, array_fill(0, 8, 128));
        $chromaQp = max(0, min(51, $this->qp + $this->chromaQpIndexOffset));
        $hasChromaAc = false;
        $quantCb4x4 = array_fill(0, 4, array_fill(0, 16, 0));
        $quantCr4x4 = array_fill(0, 4, array_fill(0, 16, 0));
        $dcCb2x2 = [0, 0, 0, 0];
        $dcCr2x2 = [0, 0, 0, 0];

        $chromaHeight = (int)($this->height / 2);
        for ($y = 0; $y < 8; $y++) {
            $py = $mbY * 8 + $y;
            if ($py >= $chromaHeight) break;
            for ($x = 0; $x < 8; $x++) {
                $px = $mbX * 8 + $x;
                if ($px >= $chromaWidth) break;
                $idx = $py * $chromaWidth + $px;
                $u8x8[$y][$x] = ord($uPlane[$idx]);
                $v8x8[$y][$x] = ord($vPlane[$idx]);
            }
        }

        $chromaLeftU = array_fill(0, 8, 128);
        $chromaTopU = array_fill(0, 8, 128);
        $chromaLeftV = array_fill(0, 8, 128);
        $chromaTopV = array_fill(0, 8, 128);
        $chromaLeftSumU = 0;
        $chromaTopSumU = 0;
        $chromaLeftSumV = 0;
        $chromaTopSumV = 0;
        $chromaCntL = 0;
        $chromaCntT = 0;
        if ($leftAvailable) {
            $refX = $mbX * 8 - 1;
            for ($y = 0; $y < 8; $y++) {
                $py = $mbY * 8 + $y;
                $idx = $py * $chromaWidth + $refX;
                if ($idx < strlen($uPlane)) {
                    $chromaLeftU[$y] = ord($uPlane[$idx]);
                    $chromaLeftV[$y] = ord($vPlane[$idx]);
                    $chromaLeftSumU += $chromaLeftU[$y];
                    $chromaLeftSumV += $chromaLeftV[$y];
                    $chromaCntL++;
                }
            }
        }
        if ($topAvailable) {
            $refY = $mbY * 8 - 1;
            for ($x = 0; $x < 8; $x++) {
                $px = $mbX * 8 + $x;
                $idx = $refY * $chromaWidth + $px;
                if ($idx < strlen($uPlane)) {
                    $chromaTopU[$x] = ord($uPlane[$idx]);
                    $chromaTopV[$x] = ord($vPlane[$idx]);
                    $chromaTopSumU += $chromaTopU[$x];
                    $chromaTopSumV += $chromaTopV[$x];
                    $chromaCntT++;
                }
            }
        }

        $chromaPredMode = 0;

        $chromaPredU = array_fill(0, 8, array_fill(0, 8, 128));
        $chromaPredV = array_fill(0, 8, array_fill(0, 8, 128));
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                switch ($chromaPredMode) {
                    case 0:
                        if ($chromaCntL && $chromaCntT) {
                            $chromaPredU[$y][$x] = ($chromaTopSumU + $chromaLeftSumU + 8) >> 4;
                            $chromaPredV[$y][$x] = ($chromaTopSumV + $chromaLeftSumV + 8) >> 4;
                        } elseif ($chromaCntL) {
                            $chromaPredU[$y][$x] = ($chromaLeftSumU + 4) >> 3;
                            $chromaPredV[$y][$x] = ($chromaLeftSumV + 4) >> 3;
                        } elseif ($chromaCntT) {
                            $chromaPredU[$y][$x] = ($chromaTopSumU + 4) >> 3;
                            $chromaPredV[$y][$x] = ($chromaTopSumV + 4) >> 3;
                        } else {
                            $chromaPredU[$y][$x] = 128;
                            $chromaPredV[$y][$x] = 128;
                        }
                        break;
                    case 1:
                        $chromaPredU[$y][$x] = $chromaLeftU[$y];
                        $chromaPredV[$y][$x] = $chromaLeftV[$y];
                        break;
                    case 2:
                        $chromaPredU[$y][$x] = $chromaTopU[$x];
                        $chromaPredV[$y][$x] = $chromaTopV[$x];
                        break;
                    default:
                        $chromaPredU[$y][$x] = 128;
                        $chromaPredV[$y][$x] = 128;
                        break;
                }
            }
        }

        for ($by = 0; $by < 2; $by++) {
            for ($bx = 0; $bx < 2; $bx++) {
                $blkIdx = $by * 2 + $bx;
                $blkU = array_fill(0, 4, array_fill(0, 4, 0));
                $blkV = array_fill(0, 4, array_fill(0, 4, 0));
                for ($y = 0; $y < 4; $y++) for ($x = 0; $x < 4; $x++) {
                    $py = $by * 4 + $y;
                    $px = $bx * 4 + $x;
                    $blkU[$y][$x] = $u8x8[$py][$px] - $chromaPredU[$py][$px];
                    $blkV[$y][$x] = $v8x8[$py][$px] - $chromaPredV[$py][$px];
                }
                $dctU = $this->dct($blkU);
                $dctV = $this->dct($blkV);
                $dcCb2x2[$blkIdx] = $dctU[0][0];
                $dcCr2x2[$blkIdx] = $dctV[0][0];
                $qU = $this->quantizeChroma($dctU, $chromaQp);
                $qV = $this->quantizeChroma($dctV, $chromaQp);
                for ($yy = 0; $yy < 4; $yy++) for ($xx = 0; $xx < 4; $xx++) {
                    $quantCb4x4[$blkIdx][$yy * 4 + $xx] = $qU[$yy][$xx];
                    $quantCr4x4[$blkIdx][$yy * 4 + $xx] = $qV[$yy][$xx];
                }
                $nzU = 0;
                $nzV = 0;
                for ($i = 1; $i < 16; $i++) {
                    if ($quantCb4x4[$blkIdx][$i] !== 0) $nzU++;
                    if ($quantCr4x4[$blkIdx][$i] !== 0) $nzV++;
                }
                $nzCache[16 + $blkIdx] = min(15, $nzU);
                $nzCache[20 + $blkIdx] = min(15, $nzV);
                if ($nzU > 0 || $nzV > 0) $hasChromaAc = true;
            }
        }

        $hadCb = $this->forwardChromaHadamard2x2($dcCb2x2);
        $hadCr = $this->forwardChromaHadamard2x2($dcCr2x2);
        $qCbDc = $this->quantizeChromaDC($hadCb, $chromaQp);
        $qCrDc = $this->quantizeChromaDC($hadCr, $chromaQp);

        $hasChromaDc = false;
        for ($i = 0; $i < 4; $i++) {
            if ($qCbDc[$i] !== 0 || $qCrDc[$i] !== 0) {
                $hasChromaDc = true;
                break;
            }
        }

        $cbpChroma = 0;
        if ($hasChromaDc) {
            $cbpChroma = $hasChromaAc ? 2 : 1;
        }

        $mapModeI16x16 = [0, 1, 2, 3, 2, 2, 2];
        $mapModeChroma = [0, 1, 2, 3, 0, 0, 0];
        $i16Mode = $mapModeI16x16[$lumaPredMode];
        $chromaMode = $mapModeChroma[$chromaPredMode];

        $mbTypeValue = 1 + $i16Mode + ($cbpChroma << 2) + ($cbpLuma == 0 ? 0 : 12);
        $bits .= $this->ue($mbTypeValue);

        $bits .= $this->ue($chromaMode);

        $bits .= $this->se(0);

        $dcNc = $this->computeNC(-1, $mbX, 0, 0, $leftAvailable, $leftNz, $topAvailable, $topNzLuma, $nzCache);
        $bits .= $this->writeBlockResidualCavlc($dcZigzag, 15, false, $dcNc);

        $lumaAcScanOrder = [0, 1, 4, 5, 2, 3, 6, 7, 8, 9, 12, 13, 10, 11, 14, 15];
        $nzCacheNew = array_fill(0, 24, 0);
        if ($cbpLuma > 0) {
            foreach ($lumaAcScanOrder as $rasterIdx) {
                $by = (int)($rasterIdx / 4);
                $bx = $rasterIdx % 4;
                $acNc = $this->computeNC($rasterIdx, $mbX, $bx, $by, $leftAvailable, $leftNz, $topAvailable, $topNzLuma, $nzCacheNew);
                $ac = $this->scan4x4Ac($quant4x4Luma[$rasterIdx]);
                $bits .= $this->writeBlockResidualCavlc($ac, 14, false, $acNc);
                $nzCacheNew[$rasterIdx] = $nzCache[$rasterIdx];
            }
        }

        for ($by = 0; $by < 4; $by++) $leftNz[$by] = $nzCache[$by * 4 + 3];
        for ($bx = 0; $bx < 4; $bx++) {
            $topBlkX = $mbX * 4 + $bx;
            if ($topBlkX < count($topNzLuma)) $topNzLuma[$topBlkX] = $nzCache[$bx + 12];
        }

        if ($cbpChroma > 0) {
            $bits .= $this->writeBlockResidualCavlc($qCbDc, 3, true, -1);
            $bits .= $this->writeBlockResidualCavlc($qCrDc, 3, true, -1);

            if ($cbpChroma === 2) {
                $cbScanOrder = [16, 17, 18, 19];
                foreach ($cbScanOrder as $blockIdx) {
                    $blk = $blockIdx - 16;
                    $by = (int)($blk / 2);
                    $bx = $blk % 2;
                    $acNc = $this->computeNC($blockIdx, $mbX, $bx, $by, $leftAvailable, $leftNz, $topAvailable, $topNzCb, $nzCacheNew);
                    $acCb = $this->scan4x4Ac($quantCb4x4[$blk]);
                    $bits .= $this->writeBlockResidualCavlc($acCb, 14, false, $acNc);
                    $nzCacheNew[$blockIdx] = $nzCache[$blockIdx];
                }
                $crScanOrder = [20, 21, 22, 23];
                foreach ($crScanOrder as $blockIdx) {
                    $blk = $blockIdx - 20;
                    $by = (int)($blk / 2);
                    $bx = $blk % 2;
                    $acNc = $this->computeNC($blockIdx, $mbX, $bx, $by, $leftAvailable, $leftNz, $topAvailable, $topNzCr, $nzCacheNew);
                    $acCr = $this->scan4x4Ac($quantCr4x4[$blk]);
                    $bits .= $this->writeBlockResidualCavlc($acCr, 14, false, $acNc);
                    $nzCacheNew[$blockIdx] = $nzCache[$blockIdx];
                }
            }

            for ($by = 0; $by < 2; $by++) {
                $cbBlk = $by * 2 + 1;
                $leftNz[4 + $by] = $nzCache[16 + $cbBlk];
                $leftNz[6 + $by] = $nzCache[20 + $cbBlk];
            }
            $topCbx0 = $mbX * 2 + 0;
            $topCbx1 = $mbX * 2 + 1;
            if ($topCbx1 < count($topNzCb)) {
                $topNzCb[$topCbx0] = $nzCache[18];
                $topNzCb[$topCbx1] = $nzCache[19];
                $topNzCr[$topCbx0] = $nzCache[22];
                $topNzCr[$topCbx1] = $nzCache[23];
            }
        }
        return $bits;
    }

    public function encodeMacroblockI4x4(
        $mbX, $mbY, $yPlane, $uPlane, $vPlane,
        $leftAvailable, &$leftNz, $topAvailable,
        &$topNzLuma, &$topNzCb, &$topNzCr,
        &$leftIntra4x4Mode = null, &$topIntra4x4Mode = null
    )
    {
        $bits = '';
        $chromaWidth = (int)($this->width / 2);
        $chromaHeight = (int)($this->height / 2);

        $lumaPixels = array_fill(0, 16, array_fill(0, 16, 128));
        for ($y = 0; $y < 16; $y++) {
            $py = $mbY * 16 + $y;
            if ($py >= $this->height) break;
            for ($x = 0; $x < 16; $x++) {
                $px = $mbX * 16 + $x;
                if ($px >= $this->width) break;
                $idx = $py * $this->width + $px;
                $lumaPixels[$y][$x] = ord($yPlane[$idx]);
            }
        }

        $leftPixels4x4 = array_fill(0, 8, array_fill(0, 4, 128));
        $topPixels4x4 = array_fill(0, 4, array_fill(0, 8, 128));
        if ($leftAvailable) {
            $refX = $mbX * 16 - 1;
            for ($by = 0; $by < 4; $by++) {
                for ($y = 0; $y < 4; $y++) {
                    $py = $mbY * 16 + $by * 4 + $y;
                    $idx = $py * $this->width + $refX;
                    if ($idx < strlen($yPlane)) {
                        $leftPixels4x4[$by][$y] = ord($yPlane[$idx]);
                    }
                }
            }
        }
        if ($topAvailable) {
            $refY = $mbY * 16 - 1;
            for ($bx = 0; $bx < 4; $bx++) {
                for ($x = 0; $x < 4; $x++) {
                    $px = $mbX * 16 + $bx * 4 + $x;
                    $idx = $refY * $this->width + $px;
                    if ($idx < strlen($yPlane)) {
                        $topPixels4x4[$bx][$x] = ord($yPlane[$idx]);
                    }
                }
            }
            for ($bx = 0; $bx < 4; $bx++) {
                for ($x = 4; $x < 8; $x++) {
                    $px = $mbX * 16 + $bx * 4 + $x;
                    if ($px >= $this->width) {
                        $topPixels4x4[$bx][$x] = $topPixels4x4[$bx][3];
                    } else {
                        $idx = $refY * $this->width + $px;
                        if ($idx < strlen($yPlane)) {
                            $topPixels4x4[$bx][$x] = ord($yPlane[$idx]);
                        }
                    }
                }
            }
        }

        $lumaAcScanOrder = [0, 1, 4, 5, 2, 3, 6, 7, 8, 9, 12, 13, 10, 11, 14, 15];
        $intra4x4PredModes = array_fill(0, 16, 2);
        for ($by = 0; $by < 4; $by++) {
            for ($bx = 0; $bx < 4; $bx++) {
                $blkIdx = $by * 4 + $bx;
                $leftAvail = ($bx > 0) || $leftAvailable;
                $topAvail = $topAvailable;
                $topRightAvail = false;
                if ($topAvail && $bx < 3) {
                    $topRightAvail = true;
                }

                $top = array_fill(0, 8, 128);
                $left = array_fill(0, 4, 128);
                $topLeft = 128;

                if ($leftAvail) {
                    if ($bx > 0) {
                        for ($y = 0; $y < 4; $y++) {
                            $left[$y] = $lumaPixels[$by * 4 + $y][$bx * 4 - 1];
                        }
                    } else {
                        for ($y = 0; $y < 4; $y++) {
                            $left[$y] = $leftPixels4x4[$by][$y];
                        }
                    }
                }

                if ($topAvail) {
                    for ($x = 0; $x < 4; $x++) {
                        $top[$x] = $topPixels4x4[$bx][$x];
                    }
                    if ($topRightAvail) {
                        for ($x = 4; $x < 8; $x++) {
                            $top[$x] = $topPixels4x4[$bx][$x];
                        }
                    } else {
                        for ($x = 4; $x < 8; $x++) {
                            $top[$x] = $top[3];
                        }
                    }
                }

                if ($topAvail && $leftAvail) {
                    if ($bx > 0 && $by > 0) {
                        $topLeft = $lumaPixels[$by * 4 - 1][$bx * 4 - 1];
                    } elseif ($bx > 0) {
                        $topLeft = $top[0];
                    } elseif ($by > 0) {
                        $topLeft = $left[0];
                    }
                } elseif ($topAvail) {
                    $topLeft = $top[0];
                } elseif ($leftAvail) {
                    $topLeft = $left[0];
                }

                $bestMode = 2;
                $bestCost = PHP_INT_MAX;
                for ($mode = 0; $mode <= 8; $mode++) {
                    if (!$topAvail && ($mode === 0 || $mode === 3 || $mode === 4 || $mode === 5 || $mode === 7 || $mode === 8)) {
                        continue;
                    }
                    if (!$leftAvail && ($mode === 1 || $mode === 4 || $mode === 6)) {
                        continue;
                    }
                    $pred = array_fill(0, 4, array_fill(0, 4, 128));
                    switch ($mode) {
                        case 0:
                            for ($y = 0; $y < 4; $y++) {
                                for ($x = 0; $x < 4; $x++) {
                                    $pred[$y][$x] = $top[$x];
                                }
                            }
                            break;
                        case 1:
                            for ($y = 0; $y < 4; $y++) {
                                for ($x = 0; $x < 4; $x++) {
                                    $pred[$y][$x] = $left[$y];
                                }
                            }
                            break;
                        case 2:
                            if ($topAvail && $leftAvail) {
                                $sum = $top[0] + $top[1] + $top[2] + $top[3] +
                                    $left[0] + $left[1] + $left[2] + $left[3];
                                $avg = ($sum + 4) >> 3;
                            } elseif ($topAvail) {
                                $sum = $top[0] + $top[1] + $top[2] + $top[3];
                                $avg = ($sum + 2) >> 2;
                            } elseif ($leftAvail) {
                                $sum = $left[0] + $left[1] + $left[2] + $left[3];
                                $avg = ($sum + 2) >> 2;
                            } else {
                                $avg = 128;
                            }
                            for ($y = 0; $y < 4; $y++) {
                                for ($x = 0; $x < 4; $x++) {
                                    $pred[$y][$x] = $avg;
                                }
                            }
                            break;
                        case 3:
                            $t0 = $top[0]; $t1 = $top[1]; $t2 = $top[2]; $t3 = $top[3];
                            $t4 = $top[4]; $t5 = $top[5]; $t6 = $top[6]; $t7 = $top[7];
                            $pred[0][0] = (int)(($t0 + 2 * $t1 + $t2 + 2) >> 2);
                            $pred[0][1] = (int)(($t1 + 2 * $t2 + $t3 + 2) >> 2);
                            $pred[1][0] = $pred[0][1];
                            $pred[0][2] = (int)(($t2 + 2 * $t3 + $t4 + 2) >> 2);
                            $pred[1][1] = $pred[0][2];
                            $pred[2][0] = $pred[0][2];
                            $pred[0][3] = (int)(($t3 + 2 * $t4 + $t5 + 2) >> 2);
                            $pred[1][2] = $pred[0][3];
                            $pred[2][1] = $pred[0][3];
                            $pred[3][0] = $pred[0][3];
                            $pred[1][3] = (int)(($t4 + 2 * $t5 + $t6 + 2) >> 2);
                            $pred[2][2] = $pred[1][3];
                            $pred[3][1] = $pred[1][3];
                            $pred[2][3] = (int)(($t5 + 2 * $t6 + $t7 + 2) >> 2);
                            $pred[3][2] = $pred[2][3];
                            $pred[3][3] = (int)(($t6 + 3 * $t7 + 2) >> 2);
                            break;
                        case 4:
                            $lt = $topLeft;
                            $t0 = $top[0]; $t1 = $top[1]; $t2 = $top[2]; $t3 = $top[3];
                            $l0 = $left[0]; $l1 = $left[1]; $l2 = $left[2]; $l3 = $left[3];
                            $avg3 = function($a, $b, $c) { return (int)(($a + 2 * $b + $c + 2) >> 2); };
                            $v03 = $avg3($l3, $l2, $l1);
                            $v02 = $avg3($l2, $l1, $l0);
                            $v01 = $avg3($l1, $l0, $lt);
                            $v00 = $avg3($l0, $lt, $t0);
                            $v10 = $avg3($lt, $t0, $t1);
                            $v20 = $avg3($t0, $t1, $t2);
                            $v30 = $avg3($t1, $t2, $t3);
                            $pred[3][0] = $v03; $pred[2][0] = $v02; $pred[3][1] = $v02;
                            $pred[1][0] = $v01; $pred[2][1] = $v01; $pred[3][2] = $v01;
                            $pred[0][0] = $v00; $pred[1][1] = $v00; $pred[2][2] = $v00; $pred[3][3] = $v00;
                            $pred[0][1] = $v10; $pred[1][2] = $v10; $pred[2][3] = $v10;
                            $pred[0][2] = $v20; $pred[1][3] = $v20;
                            $pred[0][3] = $v30;
                            break;
                        case 5:
                            $lt = $topLeft;
                            $t0 = $top[0]; $t1 = $top[1]; $t2 = $top[2]; $t3 = $top[3];
                            $l0 = $left[0]; $l1 = $left[1]; $l2 = $left[2];
                            $avg2 = function($a, $b) { return (int)(($a + $b + 1) >> 1); };
                            $avg3 = function($a, $b, $c) { return (int)(($a + 2 * $b + $c + 2) >> 2); };
                            $pred[0][0] = $avg2($lt, $t0); $pred[2][1] = $pred[0][0];
                            $pred[0][1] = $avg2($t0, $t1); $pred[2][2] = $pred[0][1];
                            $pred[0][2] = $avg2($t1, $t2); $pred[2][3] = $pred[0][2];
                            $pred[0][3] = $avg2($t2, $t3);
                            $pred[1][0] = $avg3($l0, $lt, $t0); $pred[3][1] = $pred[1][0];
                            $pred[1][1] = $avg3($lt, $t0, $t1); $pred[3][2] = $pred[1][1];
                            $pred[1][2] = $avg3($t0, $t1, $t2); $pred[3][3] = $pred[1][2];
                            $pred[1][3] = $avg3($t1, $t2, $t3);
                            $pred[2][0] = $avg3($lt, $l0, $l1);
                            $pred[3][0] = $avg3($l0, $l1, $l2);
                            break;
                        case 6:
                            $lt = $topLeft;
                            $t0 = $top[0]; $t1 = $top[1]; $t2 = $top[2];
                            $l0 = $left[0]; $l1 = $left[1]; $l2 = $left[2]; $l3 = $left[3];
                            $avg2 = function($a, $b) { return (int)(($a + $b + 1) >> 1); };
                            $avg3 = function($a, $b, $c) { return (int)(($a + 2 * $b + $c + 2) >> 2); };
                            $pred[0][0] = $avg2($lt, $l0); $pred[1][2] = $pred[0][0];
                            $pred[0][1] = $avg3($l0, $lt, $t0); $pred[1][3] = $pred[0][1];
                            $pred[0][2] = $avg3($lt, $t0, $t1);
                            $pred[0][3] = $avg3($t0, $t1, $t2);
                            $pred[1][0] = $avg2($l0, $l1); $pred[2][2] = $pred[1][0];
                            $pred[1][1] = $avg3($lt, $l0, $l1); $pred[2][3] = $pred[1][1];
                            $pred[2][0] = $avg2($l1, $l2); $pred[3][2] = $pred[2][0];
                            $pred[2][1] = $avg3($l0, $l1, $l2); $pred[3][3] = $pred[2][1];
                            $pred[3][0] = $avg2($l2, $l3);
                            $pred[3][1] = $avg3($l1, $l2, $l3);
                            break;
                        case 7:
                            $t0 = $top[0]; $t1 = $top[1]; $t2 = $top[2]; $t3 = $top[3];
                            $t4 = $top[4]; $t5 = $top[5]; $t6 = $top[6];
                            $avg2 = function($a, $b) { return (int)(($a + $b + 1) >> 1); };
                            $avg3 = function($a, $b, $c) { return (int)(($a + 2 * $b + $c + 2) >> 2); };
                            $pred[0][0] = $avg2($t0, $t1);
                            $pred[0][1] = $avg2($t1, $t2); $pred[2][0] = $pred[0][1];
                            $pred[0][2] = $avg2($t2, $t3); $pred[2][1] = $pred[0][2];
                            $pred[0][3] = $avg2($t3, $t4); $pred[2][2] = $pred[0][3];
                            $pred[2][3] = $avg2($t4, $t5);
                            $pred[1][0] = $avg3($t0, $t1, $t2);
                            $pred[1][1] = $avg3($t1, $t2, $t3); $pred[3][0] = $pred[1][1];
                            $pred[1][2] = $avg3($t2, $t3, $t4); $pred[3][1] = $pred[1][2];
                            $pred[1][3] = $avg3($t3, $t4, $t5); $pred[3][2] = $pred[1][3];
                            $pred[3][3] = $avg3($t4, $t5, $t6);
                            break;
                        case 8:
                            $l0 = $left[0]; $l1 = $left[1]; $l2 = $left[2]; $l3 = $left[3];
                            $avg2 = function($a, $b) { return (int)(($a + $b + 1) >> 1); };
                            $avg3 = function($a, $b, $c) { return (int)(($a + 2 * $b + $c + 2) >> 2); };
                            $pred[0][0] = $avg2($l0, $l1);
                            $pred[0][1] = $avg3($l0, $l1, $l2);
                            $pred[0][2] = $avg2($l1, $l2); $pred[1][0] = $pred[0][2];
                            $pred[0][3] = $avg3($l1, $l2, $l3); $pred[1][1] = $pred[0][3];
                            $pred[1][2] = $avg2($l2, $l3); $pred[2][0] = $pred[1][2];
                            $pred[1][3] = $avg3($l2, $l3, $l3); $pred[2][1] = $pred[1][3];
                            $pred[2][3] = $l3; $pred[3][1] = $l3; $pred[3][0] = $l3;
                            $pred[2][2] = $l3; $pred[3][2] = $l3; $pred[3][3] = $l3;
                            break;
                    }

                    $cost = 0;
                    for ($y = 0; $y < 4; $y++) {
                        for ($x = 0; $x < 4; $x++) {
                            $diff = $lumaPixels[$by * 4 + $y][$bx * 4 + $x] - $pred[$y][$x];
                            $cost += $diff * $diff;
                        }
                    }
                    if ($cost < $bestCost) {
                        $bestCost = $cost;
                        $bestMode = $mode;
                    }
                }
                $intra4x4PredModes[$blkIdx] = $bestMode;
            }
        }
        $quant4x4Luma = array_fill(0, 16, array_fill(0, 16, 0));
        $nzCache = array_fill(0, 24, 0);
        $cbpLuma = 0;
        for ($by = 0; $by < 4; $by++) {
            for ($bx = 0; $bx < 4; $bx++) {
                $blkIdx = $by * 4 + $bx;
                $blk4x4 = array_fill(0, 4, array_fill(0, 4, 0));
                for ($y = 0; $y < 4; $y++) {
                    for ($x = 0; $x < 4; $x++) {
                        $py = $by * 4 + $y;
                        $px = $bx * 4 + $x;
                        $blk4x4[$y][$x] = $lumaPixels[$py][$px];
                    }
                }

                $leftAvail = ($bx > 0) || $leftAvailable;
                $topAvail = ($by > 0) || $topAvailable;
                $topRightAvail = false;
                if ($topAvail && $bx < 3) {
                    $topRightAvail = true;
                }

                $top = array_fill(0, 8, 128);
                $left = array_fill(0, 4, 128);
                $topLeft = 128;

                if ($leftAvail) {
                    if ($bx > 0) {
                        for ($y = 0; $y < 4; $y++) {
                            $left[$y] = $lumaPixels[$by * 4 + $y][$bx * 4 - 1];
                        }
                    } else {
                        for ($y = 0; $y < 4; $y++) {
                            $left[$y] = $leftPixels4x4[$by][$y];
                        }
                    }
                }

                if ($topAvail) {
                    if ($by > 0) {
                        for ($x = 0; $x < 4; $x++) {
                            $top[$x] = $lumaPixels[$by * 4 - 1][$bx * 4 + $x];
                        }
                        for ($x = 4; $x < 8; $x++) {
                            $px = $bx * 4 + $x;
                            if ($px < 16) {
                                $top[$x] = $lumaPixels[$by * 4 - 1][$px];
                            } else {
                                $top[$x] = $top[3];
                            }
                        }
                    } else {
                        for ($x = 0; $x < 4; $x++) {
                            $top[$x] = $topPixels4x4[$bx][$x];
                        }
                        if ($topRightAvail) {
                            for ($x = 4; $x < 8; $x++) {
                                $top[$x] = $topPixels4x4[$bx][$x];
                            }
                        } else {
                            for ($x = 4; $x < 8; $x++) {
                                $top[$x] = $top[3];
                            }
                        }
                    }
                }

                if ($topAvail && $leftAvail) {
                    if ($bx > 0 && $by > 0) {
                        $topLeft = $lumaPixels[$by * 4 - 1][$bx * 4 - 1];
                    } elseif ($bx > 0) {
                        $topLeft = $top[0];
                    } elseif ($by > 0) {
                        $topLeft = $left[0];
                    }
                } elseif ($topAvail) {
                    $topLeft = $top[0];
                } elseif ($leftAvail) {
                    $topLeft = $left[0];
                }

                $mode = $intra4x4PredModes[$blkIdx];
                if (!$topAvail && ($mode === 0 || $mode === 3 || $mode === 4 || $mode === 5 || $mode === 7)) {
                    $mode = 2;
                }
                if (!$leftAvail && ($mode === 1 || $mode === 4 || $mode === 6 || $mode === 8)) {
                    $mode = 2;
                }
                $predPixels = array_fill(0, 4, array_fill(0, 4, 128));
                switch ($mode) {
                    case 0:
                        for ($y = 0; $y < 4; $y++) {
                            for ($x = 0; $x < 4; $x++) {
                                $predPixels[$y][$x] = $top[$x];
                            }
                        }
                        break;
                    case 1:
                        for ($y = 0; $y < 4; $y++) {
                            for ($x = 0; $x < 4; $x++) {
                                $predPixels[$y][$x] = $left[$y];
                            }
                        }
                        break;
                    case 2:
                        if ($topAvail && $leftAvail) {
                            $sum = $top[0] + $top[1] + $top[2] + $top[3] +
                                $left[0] + $left[1] + $left[2] + $left[3];
                            $avg = ($sum + 4) >> 3;
                        } elseif ($topAvail) {
                            $sum = $top[0] + $top[1] + $top[2] + $top[3];
                            $avg = ($sum + 2) >> 2;
                        } elseif ($leftAvail) {
                            $sum = $left[0] + $left[1] + $left[2] + $left[3];
                            $avg = ($sum + 2) >> 2;
                        } else {
                            $avg = 128;
                        }
                        for ($y = 0; $y < 4; $y++) {
                            for ($x = 0; $x < 4; $x++) {
                                $predPixels[$y][$x] = $avg;
                            }
                        }
                        break;
                    case 3:
                        $t0 = $top[0]; $t1 = $top[1]; $t2 = $top[2]; $t3 = $top[3];
                        $t4 = $top[4]; $t5 = $top[5]; $t6 = $top[6]; $t7 = $top[7];
                        $predPixels[0][0] = (int)(($t0 + 2 * $t1 + $t2 + 2) >> 2);
                        $predPixels[0][1] = (int)(($t1 + 2 * $t2 + $t3 + 2) >> 2);
                        $predPixels[1][0] = $predPixels[0][1];
                        $predPixels[0][2] = (int)(($t2 + 2 * $t3 + $t4 + 2) >> 2);
                        $predPixels[1][1] = $predPixels[0][2];
                        $predPixels[2][0] = $predPixels[0][2];
                        $predPixels[0][3] = (int)(($t3 + 2 * $t4 + $t5 + 2) >> 2);
                        $predPixels[1][2] = $predPixels[0][3];
                        $predPixels[2][1] = $predPixels[0][3];
                        $predPixels[3][0] = $predPixels[0][3];
                        $predPixels[1][3] = (int)(($t4 + 2 * $t5 + $t6 + 2) >> 2);
                        $predPixels[2][2] = $predPixels[1][3];
                        $predPixels[3][1] = $predPixels[1][3];
                        $predPixels[2][3] = (int)(($t5 + 2 * $t6 + $t7 + 2) >> 2);
                        $predPixels[3][2] = $predPixels[2][3];
                        $predPixels[3][3] = (int)(($t6 + 3 * $t7 + 2) >> 2);
                        break;
                    case 4:
                        $lt = $topLeft;
                        $t0 = $top[0]; $t1 = $top[1]; $t2 = $top[2]; $t3 = $top[3];
                        $l0 = $left[0]; $l1 = $left[1]; $l2 = $left[2]; $l3 = $left[3];
                        $avg3 = function($a, $b, $c) { return (int)(($a + 2 * $b + $c + 2) >> 2); };
                        $v03 = $avg3($l3, $l2, $l1);
                        $v02 = $avg3($l2, $l1, $l0);
                        $v01 = $avg3($l1, $l0, $lt);
                        $v00 = $avg3($l0, $lt, $t0);
                        $v10 = $avg3($lt, $t0, $t1);
                        $v20 = $avg3($t0, $t1, $t2);
                        $v30 = $avg3($t1, $t2, $t3);
                        $predPixels[3][0] = $v03; $predPixels[2][0] = $v02; $predPixels[3][1] = $v02;
                        $predPixels[1][0] = $v01; $predPixels[2][1] = $v01; $predPixels[3][2] = $v01;
                        $predPixels[0][0] = $v00; $predPixels[1][1] = $v00; $predPixels[2][2] = $v00; $predPixels[3][3] = $v00;
                        $predPixels[0][1] = $v10; $predPixels[1][2] = $v10; $predPixels[2][3] = $v10;
                        $predPixels[0][2] = $v20; $predPixels[1][3] = $v20;
                        $predPixels[0][3] = $v30;
                        break;
                    case 5:
                        $lt = $topLeft;
                        $t0 = $top[0]; $t1 = $top[1]; $t2 = $top[2]; $t3 = $top[3];
                        $l0 = $left[0]; $l1 = $left[1]; $l2 = $left[2];
                        $avg2 = function($a, $b) { return (int)(($a + $b + 1) >> 1); };
                        $avg3 = function($a, $b, $c) { return (int)(($a + 2 * $b + $c + 2) >> 2); };
                        $predPixels[0][0] = $avg2($lt, $t0); $predPixels[2][1] = $predPixels[0][0];
                        $predPixels[0][1] = $avg2($t0, $t1); $predPixels[2][2] = $predPixels[0][1];
                        $predPixels[0][2] = $avg2($t1, $t2); $predPixels[2][3] = $predPixels[0][2];
                        $predPixels[0][3] = $avg2($t2, $t3);
                        $predPixels[1][0] = $avg3($l0, $lt, $t0); $predPixels[3][1] = $predPixels[1][0];
                        $predPixels[1][1] = $avg3($lt, $t0, $t1); $predPixels[3][2] = $predPixels[1][1];
                        $predPixels[1][2] = $avg3($t0, $t1, $t2); $predPixels[3][3] = $predPixels[1][2];
                        $predPixels[1][3] = $avg3($t1, $t2, $t3);
                        $predPixels[2][0] = $avg3($lt, $l0, $l1);
                        $predPixels[3][0] = $avg3($l0, $l1, $l2);
                        break;
                    case 6:
                        $lt = $topLeft;
                        $t0 = $top[0]; $t1 = $top[1]; $t2 = $top[2];
                        $l0 = $left[0]; $l1 = $left[1]; $l2 = $left[2]; $l3 = $left[3];
                        $avg2 = function($a, $b) { return (int)(($a + $b + 1) >> 1); };
                        $avg3 = function($a, $b, $c) { return (int)(($a + 2 * $b + $c + 2) >> 2); };
                        $predPixels[0][0] = $avg2($lt, $l0); $predPixels[1][2] = $predPixels[0][0];
                        $predPixels[0][1] = $avg3($l0, $lt, $t0); $predPixels[1][3] = $predPixels[0][1];
                        $predPixels[0][2] = $avg3($lt, $t0, $t1);
                        $predPixels[0][3] = $avg3($t0, $t1, $t2);
                        $predPixels[1][0] = $avg2($l0, $l1); $predPixels[2][2] = $predPixels[1][0];
                        $predPixels[1][1] = $avg3($lt, $l0, $l1); $predPixels[2][3] = $predPixels[1][1];
                        $predPixels[2][0] = $avg2($l1, $l2); $predPixels[3][2] = $predPixels[2][0];
                        $predPixels[2][1] = $avg3($l0, $l1, $l2); $predPixels[3][3] = $predPixels[2][1];
                        $predPixels[3][0] = $avg2($l2, $l3);
                        $predPixels[3][1] = $avg3($l1, $l2, $l3);
                        break;
                    case 7:
                        $t0 = $top[0]; $t1 = $top[1]; $t2 = $top[2]; $t3 = $top[3];
                        $t4 = $top[4]; $t5 = $top[5]; $t6 = $top[6];
                        $avg2 = function($a, $b) { return (int)(($a + $b + 1) >> 1); };
                        $avg3 = function($a, $b, $c) { return (int)(($a + 2 * $b + $c + 2) >> 2); };
                        $predPixels[0][0] = $avg2($t0, $t1);
                        $predPixels[0][1] = $avg2($t1, $t2); $predPixels[2][0] = $predPixels[0][1];
                        $predPixels[0][2] = $avg2($t2, $t3); $predPixels[2][1] = $predPixels[0][2];
                        $predPixels[0][3] = $avg2($t3, $t4); $predPixels[2][2] = $predPixels[0][3];
                        $predPixels[2][3] = $avg2($t4, $t5);
                        $predPixels[1][0] = $avg3($t0, $t1, $t2);
                        $predPixels[1][1] = $avg3($t1, $t2, $t3); $predPixels[3][0] = $predPixels[1][1];
                        $predPixels[1][2] = $avg3($t2, $t3, $t4); $predPixels[3][1] = $predPixels[1][2];
                        $predPixels[1][3] = $avg3($t3, $t4, $t5); $predPixels[3][2] = $predPixels[1][3];
                        $predPixels[3][3] = $avg3($t4, $t5, $t6);
                        break;
                    case 8:
                        $l0 = $left[0]; $l1 = $left[1]; $l2 = $left[2]; $l3 = $left[3];
                        $avg2 = function($a, $b) { return (int)(($a + $b + 1) >> 1); };
                        $avg3 = function($a, $b, $c) { return (int)(($a + 2 * $b + $c + 2) >> 2); };
                        $predPixels[0][0] = $avg2($l0, $l1);
                        $predPixels[0][1] = $avg3($l0, $l1, $l2);
                        $predPixels[0][2] = $avg2($l1, $l2); $predPixels[1][0] = $predPixels[0][2];
                        $predPixels[0][3] = $avg3($l1, $l2, $l3); $predPixels[1][1] = $predPixels[0][3];
                        $predPixels[1][2] = $avg2($l2, $l3); $predPixels[2][0] = $predPixels[1][2];
                        $predPixels[1][3] = $avg3($l2, $l3, $l3); $predPixels[2][1] = $predPixels[1][3];
                        $predPixels[2][3] = $l3; $predPixels[3][1] = $l3; $predPixels[3][0] = $l3;
                        $predPixels[2][2] = $l3; $predPixels[3][2] = $l3; $predPixels[3][3] = $l3;
                        break;
                }

                for ($y = 0; $y < 4; $y++) {
                    for ($x = 0; $x < 4; $x++) {
                        $blk4x4[$y][$x] -= $predPixels[$y][$x];
                    }
                }

                $dctBlock = $this->dct($blk4x4);
                $quantBlock = $this->quantize($dctBlock, 0);
                for ($yy = 0; $yy < 4; $yy++) {
                    for ($xx = 0; $xx < 4; $xx++) {
                        $quant4x4Luma[$blkIdx][$yy * 4 + $xx] = $quantBlock[$yy][$xx];
                    }
                }
                $nz = 0;
                for ($i = 1; $i < 16; $i++) {
                    if ($quant4x4Luma[$blkIdx][$i] !== 0) $nz++;
                }
                $nzCache[$blkIdx] = min(15, $nz);
                if ($nz > 0) {
                    $cbpLuma |= 1 << (int)($blkIdx / 4);
                }
            }
        }

        $u8x8 = array_fill(0, 8, array_fill(0, 8, 128));
        $v8x8 = array_fill(0, 8, array_fill(0, 8, 128));
        $chromaQp = max(0, min(51, $this->qp + $this->chromaQpIndexOffset));
        $hasChromaAc = false;
        $quantCb4x4 = array_fill(0, 4, array_fill(0, 16, 0));
        $quantCr4x4 = array_fill(0, 4, array_fill(0, 16, 0));
        $dcCb2x2 = [0, 0, 0, 0];
        $dcCr2x2 = [0, 0, 0, 0];

        for ($y = 0; $y < 8; $y++) {
            $py = $mbY * 8 + $y;
            if ($py >= $chromaHeight) break;
            for ($x = 0; $x < 8; $x++) {
                $px = $mbX * 8 + $x;
                if ($px >= $chromaWidth) break;
                $idx = $py * $chromaWidth + $px;
                $u8x8[$y][$x] = ord($uPlane[$idx]);
                $v8x8[$y][$x] = ord($vPlane[$idx]);
            }
        }

        $chromaLeftU = array_fill(0, 8, 128);
        $chromaTopU = array_fill(0, 8, 128);
        $chromaLeftV = array_fill(0, 8, 128);
        $chromaTopV = array_fill(0, 8, 128);
        $chromaLeftSumU = 0;
        $chromaTopSumU = 0;
        $chromaLeftSumV = 0;
        $chromaTopSumV = 0;
        $chromaCntL = 0;
        $chromaCntT = 0;
        if ($leftAvailable) {
            $refX = $mbX * 8 - 1;
            for ($y = 0; $y < 8; $y++) {
                $py = $mbY * 8 + $y;
                $idx = $py * $chromaWidth + $refX;
                if ($idx < strlen($uPlane)) {
                    $chromaLeftU[$y] = ord($uPlane[$idx]);
                    $chromaLeftV[$y] = ord($vPlane[$idx]);
                    $chromaLeftSumU += $chromaLeftU[$y];
                    $chromaLeftSumV += $chromaLeftV[$y];
                    $chromaCntL++;
                }
            }
        }
        if ($topAvailable) {
            $refY = $mbY * 8 - 1;
            for ($x = 0; $x < 8; $x++) {
                $px = $mbX * 8 + $x;
                $idx = $refY * $chromaWidth + $px;
                if ($idx < strlen($uPlane)) {
                    $chromaTopU[$x] = ord($uPlane[$idx]);
                    $chromaTopV[$x] = ord($vPlane[$idx]);
                    $chromaTopSumU += $chromaTopU[$x];
                    $chromaTopSumV += $chromaTopV[$x];
                    $chromaCntT++;
                }
            }
        }

        $chromaPredMode = 0;
        $chromaPredU = array_fill(0, 8, array_fill(0, 8, 128));
        $chromaPredV = array_fill(0, 8, array_fill(0, 8, 128));
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                switch ($chromaPredMode) {
                    case 0:
                        if ($chromaCntL && $chromaCntT) {
                            $chromaPredU[$y][$x] = ($chromaTopSumU + $chromaLeftSumU + 8) >> 4;
                            $chromaPredV[$y][$x] = ($chromaTopSumV + $chromaLeftSumV + 8) >> 4;
                        } elseif ($chromaCntL) {
                            $chromaPredU[$y][$x] = ($chromaLeftSumU + 4) >> 3;
                            $chromaPredV[$y][$x] = ($chromaLeftSumV + 4) >> 3;
                        } elseif ($chromaCntT) {
                            $chromaPredU[$y][$x] = ($chromaTopSumU + 4) >> 3;
                            $chromaPredV[$y][$x] = ($chromaTopSumV + 4) >> 3;
                        } else {
                            $chromaPredU[$y][$x] = 128;
                            $chromaPredV[$y][$x] = 128;
                        }
                        break;
                    case 1:
                        $chromaPredU[$y][$x] = $chromaLeftU[$y];
                        $chromaPredV[$y][$x] = $chromaLeftV[$y];
                        break;
                    case 2:
                        $chromaPredU[$y][$x] = $chromaTopU[$x];
                        $chromaPredV[$y][$x] = $chromaTopV[$x];
                        break;
                    default:
                        $chromaPredU[$y][$x] = 128;
                        $chromaPredV[$y][$x] = 128;
                        break;
                }
            }
        }

        for ($by = 0; $by < 2; $by++) {
            for ($bx = 0; $bx < 2; $bx++) {
                $blkIdx = $by * 2 + $bx;
                $blkU = array_fill(0, 4, array_fill(0, 4, 0));
                $blkV = array_fill(0, 4, array_fill(0, 4, 0));
                for ($y = 0; $y < 4; $y++) {
                    for ($x = 0; $x < 4; $x++) {
                        $py = $by * 4 + $y;
                        $px = $bx * 4 + $x;
                        $blkU[$y][$x] = $u8x8[$py][$px] - $chromaPredU[$py][$px];
                        $blkV[$y][$x] = $v8x8[$py][$px] - $chromaPredV[$py][$px];
                    }
                }
                $dctU = $this->dct($blkU);
                $dctV = $this->dct($blkV);
                $dcCb2x2[$blkIdx] = $dctU[0][0];
                $dcCr2x2[$blkIdx] = $dctV[0][0];
                $qU = $this->quantizeChroma($dctU, $chromaQp);
                $qV = $this->quantizeChroma($dctV, $chromaQp);
                for ($yy = 0; $yy < 4; $yy++) {
                    for ($xx = 0; $xx < 4; $xx++) {
                        $quantCb4x4[$blkIdx][$yy * 4 + $xx] = $qU[$yy][$xx];
                        $quantCr4x4[$blkIdx][$yy * 4 + $xx] = $qV[$yy][$xx];
                    }
                }
                $nzU = 0;
                $nzV = 0;
                for ($i = 1; $i < 16; $i++) {
                    if ($quantCb4x4[$blkIdx][$i] !== 0) $nzU++;
                    if ($quantCr4x4[$blkIdx][$i] !== 0) $nzV++;
                }
                $nzCache[16 + $blkIdx] = min(15, $nzU);
                $nzCache[20 + $blkIdx] = min(15, $nzV);
                if ($nzU > 0 || $nzV > 0) $hasChromaAc = true;
            }
        }

        $hadCb = $this->forwardChromaHadamard2x2($dcCb2x2);
        $hadCr = $this->forwardChromaHadamard2x2($dcCr2x2);
        $qCbDc = $this->quantizeChromaDC($hadCb, $chromaQp);
        $qCrDc = $this->quantizeChromaDC($hadCr, $chromaQp);

        $hasChromaDc = false;
        for ($i = 0; $i < 4; $i++) {
            if ($qCbDc[$i] !== 0 || $qCrDc[$i] !== 0) {
                $hasChromaDc = true;
                break;
            }
        }

        $cbpChroma = 0;
        if ($hasChromaDc) {
            $cbpChroma = $hasChromaAc ? 2 : 1;
        }

        // I_4x4编码顺序：mb_type -> intra4x4_pred_mode -> intra_chroma_pred_mode -> CBP -> mb_qp_delta -> residual

        // 1. mb_type = 0 for I_NxN
        $bits .= $this->ue(0);

        // 2. 编码intra4x4_pred_mode (16个块)
        $lumaAcScanOrder = [0, 1, 4, 5, 2, 3, 6, 7, 8, 9, 12, 13, 10, 11, 14, 15];
        $modeCache = array_fill(0, 16, -1);
        for ($scanIdx = 0; $scanIdx < 16; $scanIdx++) {
            $rasterIdx = $lumaAcScanOrder[$scanIdx];
            $bx = $rasterIdx % 4;
            $by = (int)($rasterIdx / 4);

            // 获取leftMode
            if ($bx > 0) {
                $leftMode = $modeCache[$rasterIdx - 1];
            } elseif ($leftAvailable && $leftIntra4x4Mode !== null) {
                $leftMode = $leftIntra4x4Mode[$by];
            } else {
                $leftMode = -1;
            }

            // 获取topMode
            if ($by > 0) {
                $topMode = $modeCache[$rasterIdx - 4];
            } elseif ($topAvailable && $topIntra4x4Mode !== null) {
                $absBlkX = $mbX * 4 + $bx;
                $topMode = ($absBlkX < count($topIntra4x4Mode)) ? $topIntra4x4Mode[$absBlkX] : -1;
            } else {
                $topMode = -1;
            }

            // 计算MPM (Most Probable Mode) - 参考openh264 PredIntra4x4Mode
            $predicted = ($leftMode < 0 || $topMode < 0) ? 2 : min($leftMode, $topMode);
            $mode = $intra4x4PredModes[$rasterIdx];

            // 编码模式
            if ($mode === $predicted) {
                $bits .= '1';  // prev_intra4x4_pred_mode_flag = 1
            } else {
                $bits .= '0';  // prev_intra4x4_pred_mode_flag = 0
                $remMode = ($mode > $predicted) ? $mode - 1 : $mode;
                $bits .= $this->u($remMode, 3);
            }

            $modeCache[$rasterIdx] = $mode;
        }

        // 更新邻居模式缓存
        if ($leftIntra4x4Mode !== null) {
            for ($by = 0; $by < 4; $by++) {
                $leftIntra4x4Mode[$by] = $modeCache[3 + $by * 4];
            }
        }
        if ($topIntra4x4Mode !== null) {
            for ($bx = 0; $bx < 4; $bx++) {
                $absBlkX = $mbX * 4 + $bx;
                if ($absBlkX < count($topIntra4x4Mode)) {
                    $topIntra4x4Mode[$absBlkX] = $modeCache[12 + $bx];
                }
            }
        }

        // 3. 编码intra_chroma_pred_mode
        $bits .= $this->ue($chromaPredMode);

        // 4. 编码CBP (coded_block_pattern)
        // I_4x4的CBP映射表
        $intra4x4CbpMap = [
            47, 31, 15, 0, 23, 27, 29, 30, 7, 11, 13, 14, 39, 43, 45, 46,
            16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 30, 31,
            32, 33, 34, 35, 36, 37, 38, 39, 40, 41, 42, 43, 44, 45, 46, 47
        ];
        $cbpValue = ($cbpChroma << 4) | $cbpLuma;
        $cbpCode = $intra4x4CbpMap[$cbpValue] ?? 0;
        $bits .= $this->ue($cbpCode);

        // 5. 编码mb_qp_delta (如果CBP > 0)
        if ($cbpValue > 0) {
            $bits .= $this->se(0);
        }
        
        for ($by = 0; $by < 4; $by++) {
            $leftNz[$by] = $nzCache[$by * 4 + 3];
        }
        for ($bx = 0; $bx < 4; $bx++) {
            $topBlkX = $mbX * 4 + $bx;
            if ($topBlkX < count($topNzLuma)) {
                $topNzLuma[$topBlkX] = $nzCache[$bx + 12];
            }
        }

        $nzCacheNew = array_fill(0, 24, 0);
        if ($cbpLuma > 0) {
            foreach ($lumaAcScanOrder as $rasterIdx) {
                $by = (int)($rasterIdx / 4);
                $bx = $rasterIdx % 4;
                $acNc = $this->computeNC($rasterIdx, $mbX, $bx, $by, $leftAvailable, $leftNz, $topAvailable, $topNzLuma, $nzCacheNew);
                $ac = $this->scan4x4DcAc($quant4x4Luma[$rasterIdx]);
                $bits .= $this->writeBlockResidualCavlc($ac, 15, false, $acNc);
                $nzCacheNew[$rasterIdx] = $nzCache[$rasterIdx];
            }
        }

        if ($cbpChroma > 0) {
            $bits .= $this->writeBlockResidualCavlc($qCbDc, 3, true, -1);
            $bits .= $this->writeBlockResidualCavlc($qCrDc, 3, true, -1);

            if ($cbpChroma === 2) {
                $cbScanOrder = [16, 17, 18, 19];
                foreach ($cbScanOrder as $blockIdx) {
                    $blk = $blockIdx - 16;
                    $by = (int)($blk / 2);
                    $bx = $blk % 2;
                    $acNc = $this->computeNC($blockIdx, $mbX, $bx, $by, $leftAvailable, $leftNz, $topAvailable, $topNzCb, $nzCacheNew);
                    $acCb = $this->scan4x4Ac($quantCb4x4[$blk]);
                    $bits .= $this->writeBlockResidualCavlc($acCb, 14, false, $acNc);
                    $nzCacheNew[$blockIdx] = $nzCache[$blockIdx];
                }
                $crScanOrder = [20, 21, 22, 23];
                foreach ($crScanOrder as $blockIdx) {
                    $blk = $blockIdx - 20;
                    $by = (int)($blk / 2);
                    $bx = $blk % 2;
                    $acNc = $this->computeNC($blockIdx, $mbX, $bx, $by, $leftAvailable, $leftNz, $topAvailable, $topNzCr, $nzCacheNew);
                    $acCr = $this->scan4x4Ac($quantCr4x4[$blk]);
                    $bits .= $this->writeBlockResidualCavlc($acCr, 14, false, $acNc);
                    $nzCacheNew[$blockIdx] = $nzCache[$blockIdx];
                }
            }

            for ($by = 0; $by < 2; $by++) {
                $cbBlk = $by * 2 + 1;
                $leftNz[4 + $by] = $nzCache[16 + $cbBlk];
                $leftNz[6 + $by] = $nzCache[20 + $cbBlk];
            }
            $topCbx0 = $mbX * 2 + 0;
            $topCbx1 = $mbX * 2 + 1;
            if ($topCbx1 < count($topNzCb)) {
                $topNzCb[$topCbx0] = $nzCache[18];
                $topNzCb[$topCbx1] = $nzCache[19];
                $topNzCr[$topCbx0] = $nzCache[22];
                $topNzCr[$topCbx1] = $nzCache[23];
            }
        }

        return $bits;
    }

    public function computeNC($blockIdx, $mbX, $bx, $by, $leftAvailable, $leftNz, $topAvailable, $topNz, $nzCache)
    {
        if ($blockIdx === -1) {
            $predNz = 0;
            $count = 0;
            if ($leftAvailable) {
                $predNz += $leftNz[0];
                $count++;
            }
            if ($topAvailable) {
                $ax = $mbX * 4;
                if ($ax < count($topNz)) {
                    $predNz += $topNz[$ax];
                    $count++;
                }
            }
            if ($count === 0) return 0;
            $avgNz = intdiv($predNz + intdiv($count, 2), $count);
            return min($avgNz, 16);
        }

        $predNz = 0;
        $count = 0;

        if ($blockIdx < 16) {
            if ($bx > 0) {
                $predNz += $nzCache[$blockIdx - 1];
                $count++;
            } elseif ($leftAvailable) {
                $predNz += $leftNz[$by];
                $count++;
            }
            if ($by > 0) {
                $predNz += $nzCache[$blockIdx - 4];
                $count++;
            } elseif ($topAvailable) {
                $ax = $mbX * 4 + $bx;
                if ($ax < count($topNz)) {
                    $predNz += $topNz[$ax];
                    $count++;
                }
            }
        } else {
            $lnOff = $blockIdx < 20 ? 4 : 6;
            if ($bx > 0) {
                $predNz += $nzCache[$blockIdx - 1];
                $count++;
            } elseif ($leftAvailable) {
                $predNz += $leftNz[$lnOff + $by];
                $count++;
            }
            if ($by > 0) {
                $predNz += $nzCache[$blockIdx - 2];
                $count++;
            } elseif ($topAvailable) {
                $ax = $mbX * 2 + $bx;
                if ($ax < count($topNz)) {
                    $predNz += $topNz[$ax];
                    $count++;
                }
            }
        }

        $avgNz = $count > 0 ? intdiv($predNz + intdiv($count, 2), $count) : 0;
        return min($avgNz, 16);
    }

    public function writeBlockResidualCavlc(array $coeffs, int $endIdx, bool $isChromaDc, int $iNC): string
    {

        $bits = '';
        
        $iLastIndex = $endIdx;
        while ($iLastIndex >= 0 && $coeffs[$iLastIndex] == 0) {
            $iLastIndex--;
        }

        if ($iLastIndex < 0) {
            if ($isChromaDc) {
                $ncIdx = 4;
            } else {
                $ncIdx = self::ENC_NC_MAP_TABLE[$iNC];
            }
            $coeffToken = self::VLC_COEFF_TOKEN[$ncIdx][0][0];
            $value = $coeffToken[0];
            $n = $coeffToken[1];
            $bits .= $this->u($value, $n);
            return $bits;
        }

        $totalZeros = 0;
        $totalCoeffs = 0;
        
        $level = [];
        $run = [];
        
        $iLastIndex = $endIdx;
        while ($iLastIndex >= 0 && $coeffs[$iLastIndex] == 0) {
            $iLastIndex--;
        }
        
        while ($iLastIndex >= 0) {
            $countZero = 0;
            $level[$totalCoeffs] = $coeffs[$iLastIndex--];
            
            while ($iLastIndex >= 0 && $coeffs[$iLastIndex] == 0) {
                $countZero++;
                $iLastIndex--;
            }
            $totalZeros += $countZero;
            $run[$totalCoeffs++] = $countZero;
        }
        
        $trailingOnes = 0;
        $sign = 0;
        $count = ($totalCoeffs > 3) ? 3 : $totalCoeffs;
        for ($i = 0; $i < $count; $i++) {
            if (abs($level[$i]) == 1) {
                $trailingOnes++;
                $sign <<= 1;
                if ($level[$i] < 0) {
                    $sign |= 1;
                }
            } else {
                break;
            }
        }

        if ($isChromaDc) {
            $ncIdx = 4;
        } else {
            $ncIdx = self::ENC_NC_MAP_TABLE[$iNC];
        }

        $coeffToken = self::VLC_COEFF_TOKEN[$ncIdx][$totalCoeffs][$trailingOnes];
        $value = $coeffToken[0];
        $n = $coeffToken[1];
        $n += $trailingOnes;
        $value = ($value << $trailingOnes) | $sign;
        $bits .= $this->u($value, $n);

        $suffixLength = ($totalCoeffs > 10 && $trailingOnes < 3) ? 1 : 0;

        $suffixLimit = [0, 3, 6, 12, 24, 48, PHP_INT_MAX];

        for ($i = $trailingOnes; $i < $totalCoeffs; $i++) {
            $val = $level[$i];
            $absVal = abs($val);
            $isFirst = ($i == $trailingOnes);

            if ($val > 0) {
                $levelCode = 2 * ($val - 1);
            } else {
                $levelCode = 2 * (-$val) - 1;
            }
            if ($isFirst && ($trailingOnes < 3) && ($absVal > 1)) {
                $levelCode -= 2;
            }
            if ($levelCode < 0) {
                $levelCode = 0;
            }

            $levelPrefix = $levelCode >> $suffixLength;
            $levelSuffixSize = $suffixLength;
            $levelSuffix = $levelCode - ($levelPrefix << $suffixLength);

            if ($levelPrefix >= 14 && $levelPrefix < 30 && $suffixLength == 0) {
                $levelPrefix = 14;
                $levelSuffix = $levelCode - $levelPrefix;
                $levelSuffixSize = 4;
            } elseif ($levelPrefix >= 15) {
                $levelPrefix = 15;
                $levelSuffix = $levelCode - ($levelPrefix << $suffixLength);
                if ($suffixLength == 0) {
                    $levelSuffix -= 15;
                }
                $levelSuffixSize = 12;
            }

            $n = $levelPrefix + 1 + $levelSuffixSize;
            $value = ((1 << $levelSuffixSize) | $levelSuffix);
            $bits .= $this->u($value, $n);

            if ($isFirst) {
                $suffixLength = ($absVal > 3) ? 2 : 1;
            } else {
                if ($suffixLength < 6 && $absVal > $suffixLimit[$suffixLength]) {
                    $suffixLength++;
                }
            }
        }

        if ($totalCoeffs < $endIdx + 1) {
            if (!$isChromaDc) {
                $totalZerosEntry = self::VLC_TOTAL_ZEROS[$totalCoeffs][$totalZeros];
                $n = $totalZerosEntry[1];
                $value = $totalZerosEntry[0];
                $bits .= $this->u($value, $n);
            } else {
                if ($totalCoeffs < 4) {
                    $totalZerosEntry = self::VLC_TOTAL_ZEROS_CHROMA_DC[$totalCoeffs][$totalZeros];
                    $n = $totalZerosEntry[1];
                    $value = $totalZerosEntry[0];
                    $bits .= $this->u($value, $n);
                } else {
                    $bits .= $this->ue($totalZeros);
                }
            }
        }

        $zerosLeft = $totalZeros;
        for ($i = 0; $i + 1 < $totalCoeffs && $zerosLeft > 0; $i++) {
            $uirun = $run[$i];
            $zeroLeft = self::ZERO_LEFT_MAP[$zerosLeft];
            $runBeforeEntry = self::VLC_RUN_BEFORE[$zeroLeft][$uirun];
            $n = $runBeforeEntry[1];
            $value = $runBeforeEntry[0];
            $bits .= $this->u($value, $n);
            $zerosLeft -= $uirun;
        }

        return $bits;
    }

    /**
     * 4x4系数扫描
     * 将raster顺序的16个系数转换为zigzag扫描顺序
     * 用于I16x16 DC系数和I4x4全部系数
     */
    public function scan4x4DcAc(array $raster): array
    {
        $out = array_fill(0, 16, 0);
        for ($i = 0; $i < 16; $i++) {
            $out[$i] = $raster[self::ZIGZAG_SCAN_4X4[$i]];
        }
        return $out;
    }

    /**
     * 4x4 AC系数扫描
     * 跳过DC(位置0),将raster顺序的AC系数(位置1-15)转换为zigzag扫描顺序
     * 输出15个AC系数,用于I16x16 AC和chroma AC
     */
    public function scan4x4Ac(array $raster): array
    {
        $out = array_fill(0, 15, 0);
        for ($i = 0; $i < 15; $i++) {
            $out[$i] = $raster[self::ZIGZAG_SCAN_4X4[$i + 1]];
        }
        return $out;
    }

    public function dct(array $block): array
    {
        $pDct = array_fill(0, 16, 0);
        for ($y = 0; $y < 4; $y++) for ($x = 0; $x < 4; $x++) $pDct[$y * 4 + $x] = $block[$y][$x];

        for ($i = 0; $i < 16; $i += 4) {
            $kiI1 = 1 + $i;
            $kiI2 = 2 + $i;
            $kiI3 = 3 + $i;

            $s03 = $pDct[$i] + $pDct[$kiI3];
            $s12 = $pDct[$kiI1] + $pDct[$kiI2];
            $d03 = $pDct[$i] - $pDct[$kiI3];
            $d12 = $pDct[$kiI1] - $pDct[$kiI2];

            $pDct[$i] = $s03 + $s12;
            $pDct[$kiI2] = $s03 - $s12;
            $pDct[$kiI1] = 2 * $d03 + $d12;
            $pDct[$kiI3] = $d03 - 2 * $d12;
        }

        for ($i = 0; $i < 4; $i++) {
            $kiI4 = 4 + $i;
            $kiI8 = 8 + $i;
            $kiI12 = 12 + $i;

            $s03 = $pDct[$i] + $pDct[$kiI12];
            $s12 = $pDct[$kiI4] + $pDct[$kiI8];
            $d03 = $pDct[$i] - $pDct[$kiI12];
            $d12 = $pDct[$kiI4] - $pDct[$kiI8];

            $pDct[$i] = $s03 + $s12;
            $pDct[$kiI8] = $s03 - $s12;
            $pDct[$kiI4] = 2 * $d03 + $d12;
            $pDct[$kiI12] = $d03 - 2 * $d12;
        }

        $result = array_fill(0, 4, array_fill(0, 4, 0));
        for ($y = 0; $y < 4; $y++) for ($x = 0; $x < 4; $x++) $result[$y][$x] = $pDct[$y * 4 + $x];
        return $result;
    }

    public function hadamardTransformDC(array $b): array
    {
        $d = array_fill(0, 16, 0);
        for ($y = 0; $y < 4; $y++) for ($x = 0; $x < 4; $x++) $d[$y * 4 + $x] = $b[$y][$x];

        $tmp = array_fill(0, 16, 0);
        for ($i = 0; $i < 4; $i++) {
            $s01 = $d[$i * 4 + 0] + $d[$i * 4 + 1];
            $d01 = $d[$i * 4 + 0] - $d[$i * 4 + 1];
            $s23 = $d[$i * 4 + 2] + $d[$i * 4 + 3];
            $d23 = $d[$i * 4 + 2] - $d[$i * 4 + 3];
            $tmp[$i * 4 + 0] = $s01 + $s23;
            $tmp[$i * 4 + 1] = $s01 - $s23;
            $tmp[$i * 4 + 2] = $d01 - $d23;
            $tmp[$i * 4 + 3] = $d01 + $d23;
        }

        $res = array_fill(0, 4, array_fill(0, 4, 0));
        for ($j = 0; $j < 4; $j++) {
            $s01 = $tmp[0 * 4 + $j] + $tmp[1 * 4 + $j];
            $d01 = $tmp[0 * 4 + $j] - $tmp[1 * 4 + $j];
            $s23 = $tmp[2 * 4 + $j] + $tmp[3 * 4 + $j];
            $d23 = $tmp[2 * 4 + $j] - $tmp[3 * 4 + $j];
            $res[0][$j] = (int)(($s01 + $s23 + 1) >> 1);
            $res[1][$j] = (int)(($s01 - $s23 + 1) >> 1);
            $res[2][$j] = (int)(($d01 - $d23 + 1) >> 1);
            $res[3][$j] = (int)(($d01 + $d23 + 1) >> 1);
        }
        return $res;
    }

    /**
     * 2x2 chroma DC Hadamard变换
     * 不做缩放,输出原始Hadamard系数
     */
    public function forwardChromaHadamard2x2(array $c): array
    {
        $a = $c[0];
        $b = $c[1];
        $cc = $c[2];
        $d = $c[3];
        $e = $a - $b;
        $a = $a + $b;
        $b = $cc - $d;
        $cc = $cc + $d;
        return [$a + $cc, $e + $b, $a - $cc, $e - $b];
    }

    /**
     * 4x4 AC系数量化
     * 公式: level = sign(coeff) * abs(((FF[j] + |coeff|) * MF[j]) >> 16)
     * Intra FF索引 = QP + 6
     */
    public function quantize(array $block, int $isChroma): array
    {
        $qp = $this->qp;
        $mf = self::QUANT_MF[$qp];
        $ff = self::QUANT_INTER_FF[$qp + 6];
        $out = array_fill(0, 4, array_fill(0, 4, 0));
        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                $i = $y * 4 + $x;
                $j = $i & 7;
                $val = $block[$y][$x];
                $absVal = abs($val);
                $absQuant = (($ff[$j] + $absVal) * $mf[$j]) >> 16;
                $out[$y][$x] = $val >= 0 ? $absQuant : -$absQuant;
            }
        }
        return $out;
    }

    /**
     * 4x4 chroma AC系数量化
     */
    public function quantizeChroma(array $block, int $qp): array
    {
        $mf = self::QUANT_MF[$qp];
        $ff = self::QUANT_INTER_FF[$qp + 6];
        $out = array_fill(0, 4, array_fill(0, 4, 0));
        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                $i = $y * 4 + $x;
                $j = $i & 7;
                $val = $block[$y][$x];
                $absVal = abs($val);
                $absQuant = (($ff[$j] + $absVal) * $mf[$j]) >> 16;
                $out[$y][$x] = $val >= 0 ? $absQuant : -$absQuant;
            }
        }
        return $out;
    }

    /**
     * 4x4 luma DC量化
     * DC使用: iFF = pFF[0] << 1, iMF = pMF[0] >> 1
     * 公式: level = sign(coeff) * abs(((iFF + |coeff|) * iMF) >> 16)
     */
    public function quantizeDCMatrix(array $b, int $qp): array
    {
        $mf0 = self::QUANT_MF[$qp][0];
        $ff0 = self::QUANT_INTER_FF[$qp + 6][0];
        $iFF = $ff0 << 1;
        $iMF = $mf0 >> 1;
        $out = array_fill(0, 4, array_fill(0, 4, 0));
        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                $val = $b[$y][$x];
                $absVal = abs($val);
                $absQuant = (($iFF + $absVal) * $iMF) >> 16;
                $out[$y][$x] = $val >= 0 ? $absQuant : -$absQuant;
            }
        }
        return $out;
    }

    /**
     * 2x2 chroma DC量化
     * DC使用: iFF = pFF[0] << 1, iMF = pMF[0] >> 1
     */
    public function quantizeChromaDC(array $coeffs, int $chromaQp): array
    {
        $mf0 = self::QUANT_MF[$chromaQp][0];
        $ff0 = self::QUANT_INTER_FF[$chromaQp + 6][0];
        $iFF = $ff0 << 1;
        $iMF = $mf0 >> 1;
        $output = [];
        foreach ($coeffs as $val) {
            $absVal = abs($val);
            $absQuant = (($iFF + $absVal) * $iMF) >> 16;
            $output[] = $val >= 0 ? $absQuant : -$absQuant;
        }
        return $output;
    }

    public function ue(int $v): string
    {
        $bin = decbin($v + 1);
        $zeros = strlen($bin) - 1;
        return str_repeat('0', $zeros) . $bin;
    }

    public function se(int $v): string
    {
        if ($v <= 0) {
            return $this->ue(-$v * 2);
        } else {
            return $this->ue($v * 2 - 1);
        }
    }

    public function u(int $v, int $n): string
    {
        return str_pad(decbin($v), $n, '0', STR_PAD_LEFT);
    }

    public function bitsToBytes(string $bits): string
    {
        $bytes = '';
        $len = strlen($bits);
        for ($i = 0; $i < $len; $i += 8) {
            $chunk = substr($bits, $i, 8);
            if (strlen($chunk) < 8) {
                $chunk = str_pad($chunk, 8, '0', STR_PAD_RIGHT);
            }
            $bytes .= chr(bindec($chunk));
        }
        return $bytes;
    }

    public function rbspToNal(string $rbsp, int $type): string
    {
        $ref = match (true) {
            $type === 5 => 3,
            $type === 7 || $type === 8 => 3,
            default => 2
        };
        $header = chr(($ref << 5) | $type);

        $output = '';
        $zeroCount = 0;
        for ($i = 0; $i < strlen($rbsp); $i++) {
            $byte = ord($rbsp[$i]);
            if ($zeroCount >= 2 && $byte <= 3) {
                $output .= chr(0x03);
                $zeroCount = 0;
            }
            $output .= chr($byte);
            if ($byte == 0) {
                $zeroCount++;
            } else {
                $zeroCount = 0;
            }
        }
        return "\x00\x00\x00\x01" . $header . $output;
    }

    /**
     * 运动估计：在参考帧中搜索最佳匹配块
     * @param array $currentBlock 当前16x16块
     * @param string $refPlane 参考帧Y平面
     * @param int $mbX 宏块X坐标
     * @param int $mbY 宏块Y坐标
     * @param int $searchRange 搜索范围（默认16像素）
     * @return array [mvX, mvY, sad] 运动向量和SAD值
     */
    public function motionEstimate16x16(array $currentBlock, string $refPlane, int $mbX, int $mbY, int $searchRange = 16): array
    {
        $bestMV = [0, 0];
        $bestSAD = PHP_INT_MAX;

        $origX = $mbX * 16;
        $origY = $mbY * 16;

        // 简化版搜索：只在整数像素位置搜索
        for ($dy = -$searchRange; $dy <= $searchRange; $dy++) {
            for ($dx = -$searchRange; $dx <= $searchRange; $dx++) {
                $rx = $origX + $dx;
                $ry = $origY + $dy;

                // 边界检查
                if ($rx < 0 || $rx + 16 > $this->width || $ry < 0 || $ry + 16 > $this->height) {
                    continue;
                }

                $sad = 0;
                for ($y = 0; $y < 16; $y++) {
                    for ($x = 0; $x < 16; $x++) {
                        $refIdx = ($ry + $y) * $this->width + ($rx + $x);
                        $ref = ord($refPlane[$refIdx]);
                        $cur = $currentBlock[$y][$x];
                        $sad += abs($cur - $ref);
                    }
                }

                if ($sad < $bestSAD) {
                    $bestSAD = $sad;
                    $bestMV = [$dx, $dy];
                }
            }
        }

        return [$bestMV[0], $bestMV[1], $bestSAD];
    }

    /**
     * 编码P帧宏块（使用P_Skip模式）
     * P_Skip = mb_type=0 for P slice
     * 含义：使用预测运动向量(0,0)，复制参考帧对应位置，无残差
     */
    public function encodePMacroblock(
        int $mbX,
        int $mbY,
        string $yPlane,
        string $uPlane,
        string $vPlane,
        bool $leftAvailable,
        array &$leftNz,
        bool $topAvailable,
        array &$topNzLuma,
        array &$topNzCb,
        array &$topNzCr,
        array &$leftIntra4x4Mode,
        array &$topIntra4x4Mode,
        string $refYPlane
    ): string {
        // P_Skip模式: mb_type = 0 (无残差，使用预测MV=(0,0))
        $bits = '';
        $bits .= $this->ue(0); // mb_type = 0 for P_Skip

        // 更新邻居nz缓存（所有块都是0）
        for ($by = 0; $by < 4; $by++) {
            $leftNz[$by] = 0;
        }
        for ($bx = 0; $bx < 4; $bx++) {
            $topBlkX = $mbX * 4 + $bx;
            if ($topBlkX < count($topNzLuma)) {
                $topNzLuma[$topBlkX] = 0;
            }
        }
        $leftNz[4] = 0;
        $leftNz[5] = 0;
        // 同步U/V的nz
        for ($bx = 0; $bx < 2; $bx++) {
            $topBlkX = $mbX * 2 + $bx;
            if ($topBlkX < count($topNzCb)) {
                $topNzCb[$topBlkX] = 0;
            }
            if ($topBlkX < count($topNzCr)) {
                $topNzCr[$topBlkX] = 0;
            }
        }

        return $bits;
    }
}