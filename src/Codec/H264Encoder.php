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

    // 反量化表（用于本地解码重建参考帧）
    public $dequant4Table = [];

    // P帧参考帧管理
    public $refYPlane = null;      // 参考帧Y平面（重建后的）
    public $refUPlane = null;      // 参考帧U平面
    public $refVPlane = null;      // 参考帧V平面
    public $enableInter = false;   // 是否启用P帧
    public $numRefFrames = 1;      // 参考帧数量

    // 本地解码重建帧（用于正确更新参考帧）
    public $reconYPlane = '';

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

        // 构建反量化表（与解码器一致，使用flat scaling matrix=16）
        $posClass = [0, 1, 0, 1, 1, 2, 1, 2, 0, 1, 0, 1, 1, 2, 1, 2];
        $flatScaling4 = array_fill(0, 16, 16);
        $this->dequant4Table = array_fill(0, 6, array_fill(0, 52, array_fill(0, 16, 0)));
        for ($i = 0; $i < 6; $i++) {
            for ($q = 0; $q < 52; $q++) {
                $shift = intdiv($q, 6) + 2;
                $idx = $q % 6;
                for ($x = 0; $x < 16; $x++) {
                    $scaleIdx = $posClass[$x];
                    $this->dequant4Table[$i][$q][$x] =
                        (self::DEQUANT4_COEFF_INIT[$idx][$scaleIdx] * $flatScaling4[$x]) << $shift;
                }
            }
        }
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

        // 重置MV缓存
        $this->mvLeftCol = [];
        $this->mvTopRow = [];

        // 初始化本地解码重建帧（用于正确更新参考帧，避免编解码器失配）
        $this->reconYPlane = str_repeat("\x00", $ySize);

        // P帧使用mb_skip_run来编码P_Skip宏块（CAVLC模式）
        $mbSkipRun = 0;
        $isPSlice = ($sliceType === 0 && $this->refYPlane !== null);

        for ($mbY = 0; $mbY < $mbHeight; $mbY++) {
            $leftAvailable = false;
            $leftNz = [0, 0, 0, 0, 0, 0, 0, 0];
            $leftIntra4x4Mode = [-1, -1, -1, -1];
            // 注意: 不清空mvTopRow，保留上一行的MV作为top预测参考
            // mvTopRow[$mbX]会在处理当前宏块时被覆写为当前行的MV
            for ($mbX = 0; $mbX < $mbWidth; $mbX++) {
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
                    $bits .= $mbBits;
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

        // === 本地解码重建（用于正确更新参考帧）===
        // 1. 反量化DC Hadamard系数
        $dcFlatRecon = [];
        for ($y = 0; $y < 4; $y++) for ($x = 0; $x < 4; $x++) $dcFlatRecon[] = $dcQuant[$y][$x];
        $lumaQmul = $this->dequant4Table[0][$this->qp][0];
        $dcResultRecon = $this->lumaDcDequantIdct($dcFlatRecon, $lumaQmul);

        // 2. 逐4x4块重建像素
        for ($by = 0; $by < 4; $by++) {
            for ($bx = 0; $bx < 4; $bx++) {
                $rasterIdx = $by * 4 + $bx;
                $dcResidual = $dcResultRecon[$rasterIdx];

                if ($cbpLuma > 0) {
                    // AC存在: 反量化AC, 将DC放入[0], 一起做IDCT
                    $acDequant = $this->dequantize4x4($quant4x4Luma[$rasterIdx], 0, $this->qp);
                    $acDequant[0] = $dcResidual;
                    $acBlock = array_fill(0, 4, array_fill(0, 4, 0));
                    for ($y = 0; $y < 4; $y++) for ($x = 0; $x < 4; $x++) $acBlock[$y][$x] = $acDequant[$y * 4 + $x];
                    $idctResult = $this->idct4x4($acBlock);

                    for ($y = 0; $y < 4; $y++) {
                        for ($x = 0; $x < 4; $x++) {
                            $py = $mbY * 16 + $by * 4 + $y;
                            $px = $mbX * 16 + $bx * 4 + $x;
                            if ($py < $this->height && $px < $this->width) {
                                $val = $predPixels[$by * 4 + $y][$bx * 4 + $x] + $idctResult[$y][$x];
                                $val = max(0, min(255, $val));
                                $this->reconYPlane[$py * $this->width + $px] = chr($val);
                            }
                        }
                    }
                } else {
                    // 仅DC: (DC + 32) >> 6
                    $dcAdd = ($dcResidual + 32) >> 6;
                    for ($y = 0; $y < 4; $y++) {
                        for ($x = 0; $x < 4; $x++) {
                            $py = $mbY * 16 + $by * 4 + $y;
                            $px = $mbX * 16 + $bx * 4 + $x;
                            if ($py < $this->height && $px < $this->width) {
                                $val = $predPixels[$by * 4 + $y][$bx * 4 + $x] + $dcAdd;
                                $val = max(0, min(255, $val));
                                $this->reconYPlane[$py * $this->width + $px] = chr($val);
                            }
                        }
                    }
                }
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
        // I_4x4的CBP映射表 (codeNum -> cbp)，与解码器golombToIntraCbp完全一致
        $intra4x4CbpMap = [
            47, 31, 15, 0, 23, 27, 29, 30, 7, 11, 13, 14, 39, 43, 45, 46,
            16, 3, 5, 10, 12, 19, 21, 26, 28, 35, 37, 42, 44, 1, 2, 4, 8,
            17, 18, 20, 24, 6, 9, 22, 25, 32, 33, 34, 36, 40, 38, 41,
        ];
        $cbpValue = ($cbpChroma << 4) | $cbpLuma;
        $cbpCode = array_search($cbpValue, $intra4x4CbpMap);
        if ($cbpCode === false) $cbpCode = 0;
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
     * Intra FF索引 = QP + 6, Inter FF索引 = QP
     */
    public function quantize(array $block, int $isChroma, bool $isInter = false): array
    {
        $qp = $this->qp;
        $mf = self::QUANT_MF[$qp];
        $ffIdx = $isInter ? $qp : $qp + 6;
        $ff = self::QUANT_INTER_FF[$ffIdx];
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

    /**
     * 4x4反量化（用于本地解码重建参考帧）
     * 公式: (level * table[qp][pos] + 32) >> 6
     */
    public function dequantize4x4(array $coeff, int $type, int $qp): array
    {
        $out = array_fill(0, 16, 0);
        $qp = max(0, min(51, $qp));
        $listIdx = $type;
        for ($i = 0; $i < 16; $i++) {
            if ($coeff[$i] == 0) continue;
            $out[$i] = ($coeff[$i] * $this->dequant4Table[$listIdx][$qp][$i] + 32) >> 6;
        }
        return $out;
    }

    /**
     * 4x4 IDCT整数逆变换（与解码器一致）
     */
    public function idct4x4(array $in): array
    {
        $coeffs = array_fill(0, 16, 0);
        for ($y = 0; $y < 4; $y++) for ($x = 0; $x < 4; $x++) $coeffs[$y * 4 + $x] = $in[$y][$x];

        $coeffs[0] = $coeffs[0] + 32;

        for ($i = 0; $i < 4; $i++) {
            $row = 4 * $i;
            $z0 = $coeffs[$row] + $coeffs[$row + 2];
            $z1 = $coeffs[$row] - $coeffs[$row + 2];
            $z2 = ($coeffs[$row + 1] >> 1) - $coeffs[$row + 3];
            $z3 = $coeffs[$row + 1] + ($coeffs[$row + 3] >> 1);

            $coeffs[$row] = $z0 + $z3;
            $coeffs[$row + 1] = $z1 + $z2;
            $coeffs[$row + 2] = $z1 - $z2;
            $coeffs[$row + 3] = $z0 - $z3;
        }

        $d = array_fill(0, 16, 0);
        for ($i = 0; $i < 4; $i++) {
            $z0 = $coeffs[$i] + $coeffs[$i + 8];
            $z1 = $coeffs[$i] - $coeffs[$i + 8];
            $z2 = ($coeffs[$i + 4] >> 1) - $coeffs[$i + 12];
            $z3 = $coeffs[$i + 4] + ($coeffs[$i + 12] >> 1);

            $d[$i] = ($z0 + $z3) >> 6;
            $d[$i + 4] = ($z1 + $z2) >> 6;
            $d[$i + 8] = ($z1 - $z2) >> 6;
            $d[$i + 12] = ($z0 - $z3) >> 6;
        }

        $out = array_fill(0, 4, array_fill(0, 4, 0));
        for ($y = 0; $y < 4; $y++) for ($x = 0; $x < 4; $x++) $out[$y][$x] = $d[$y * 4 + $x];
        return $out;
    }

    /**
     * 亮度DC 4x4逆哈达玛+反量化
     * 输入: raster顺序的16个DC系数
     * 输出: raster顺序的16个反量化DC值
     */
    public function lumaDcDequantIdct(array $dc4x4, int $qmul): array
    {
        $temp = array_fill(0, 16, 0);

        for ($i = 0; $i < 4; $i++) {
            $base = 4 * $i;
            $z0 = $dc4x4[$base] + $dc4x4[$base + 1];
            $z1 = $dc4x4[$base] - $dc4x4[$base + 1];
            $z2 = $dc4x4[$base + 2] - $dc4x4[$base + 3];
            $z3 = $dc4x4[$base + 2] + $dc4x4[$base + 3];

            $temp[$base] = $z0 + $z3;
            $temp[$base + 1] = $z0 - $z3;
            $temp[$base + 2] = $z1 - $z2;
            $temp[$base + 3] = $z1 + $z2;
        }

        $out = array_fill(0, 16, 0);
        for ($j = 0; $j < 4; $j++) {
            $z0 = $temp[$j] + $temp[8 + $j];
            $z1 = $temp[$j] - $temp[8 + $j];
            $z2 = $temp[4 + $j] - $temp[12 + $j];
            $z3 = $temp[4 + $j] + $temp[12 + $j];

            $s0 = ($z0 + $z3) * $qmul + 128;
            $s1 = ($z1 + $z2) * $qmul + 128;
            $s2 = ($z1 - $z2) * $qmul + 128;
            $s3 = ($z0 - $z3) * $qmul + 128;
            $out[0 * 4 + $j] = ($s0 >= 0) ? ($s0 >> 8) : -((abs($s0)) >> 8);
            $out[1 * 4 + $j] = ($s1 >= 0) ? ($s1 >> 8) : -((abs($s1)) >> 8);
            $out[2 * 4 + $j] = ($s2 >= 0) ? ($s2 >> 8) : -((abs($s2)) >> 8);
            $out[3 * 4 + $j] = ($s3 >= 0) ? ($s3 >> 8) : -((abs($s3)) >> 8);
        }
        return $out;
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
     * 6抽头插值滤波器系数 (1/32, -5/32, 20/32, 20/32, -5/32, 1/32)
     * H.264标准规定的半像素插值
     */
    private const INTERP_TAP0 = 1;
    private const INTERP_TAP1 = -5;
    private const INTERP_TAP2 = 20;
    private const INTERP_TAP3 = 20;
    private const INTERP_TAP4 = -5;
    private const INTERP_TAP5 = 1;

    /**
     * 获取参考帧中指定位置的像素值（带边界处理）
     */
    private function getRefPixel(string $refPlane, int $x, int $y): int
    {
        $x = max(0, min($this->width - 1, $x));
        $y = max(0, min($this->height - 1, $y));
        return ord($refPlane[$y * $this->width + $x]);
    }

    /**
     * 6抽头水平插值（计算半像素b）
     * b[i,j] = (E - 5F + 20G + 20H - 5I + J + 16) >> 5
     */
    private function interpHorizontal(string $refPlane, int $x, int $y): int
    {
        $E = $this->getRefPixel($refPlane, $x - 2, $y);
        $F = $this->getRefPixel($refPlane, $x - 1, $y);
        $G = $this->getRefPixel($refPlane, $x,     $y);
        $H = $this->getRefPixel($refPlane, $x + 1, $y);
        $I = $this->getRefPixel($refPlane, $x + 2, $y);
        $J = $this->getRefPixel($refPlane, $x + 3, $y);
        $val = self::INTERP_TAP0 * $E + self::INTERP_TAP1 * $F + self::INTERP_TAP2 * $G
             + self::INTERP_TAP3 * $H + self::INTERP_TAP4 * $I + self::INTERP_TAP5 * $J;
        return max(0, min(255, (($val + 16) >> 5)));
    }

    /**
     * 6抽头垂直插值（计算半像素h）
     */
    private function interpVertical(string $refPlane, int $x, int $y): int
    {
        $A = $this->getRefPixel($refPlane, $x, $y - 2);
        $B = $this->getRefPixel($refPlane, $x, $y - 1);
        $C = $this->getRefPixel($refPlane, $x, $y);
        $D = $this->getRefPixel($refPlane, $x, $y + 1);
        $E = $this->getRefPixel($refPlane, $x, $y + 2);
        $F = $this->getRefPixel($refPlane, $x, $y + 3);
        $val = self::INTERP_TAP0 * $A + self::INTERP_TAP1 * $B + self::INTERP_TAP2 * $C
             + self::INTERP_TAP3 * $D + self::INTERP_TAP4 * $E + self::INTERP_TAP5 * $F;
        return max(0, min(255, (($val + 16) >> 5)));
    }

    /**
     * 6抽头对角插值（计算半像素j）
     * 先水平后垂直，或先垂直后水平
     */
    private function interpDiagonal(string $refPlane, int $x, int $y): int
    {
        // 先做水平插值，得到中间值aa, bb, ..., ff（不clip）
        $vals = [];
        for ($dy = -2; $dy <= 3; $dy++) {
            $E = $this->getRefPixel($refPlane, $x - 2, $y + $dy);
            $F = $this->getRefPixel($refPlane, $x - 1, $y + $dy);
            $G = $this->getRefPixel($refPlane, $x,     $y + $dy);
            $H = $this->getRefPixel($refPlane, $x + 1, $y + $dy);
            $I = $this->getRefPixel($refPlane, $x + 2, $y + $dy);
            $J = $this->getRefPixel($refPlane, $x + 3, $y + $dy);
            $vals[] = self::INTERP_TAP0 * $E + self::INTERP_TAP1 * $F + self::INTERP_TAP2 * $G
                    + self::INTERP_TAP3 * $H + self::INTERP_TAP4 * $I + self::INTERP_TAP5 * $J;
        }
        // 对中间值做垂直插值
        $val = self::INTERP_TAP0 * $vals[0] + self::INTERP_TAP1 * $vals[1] + self::INTERP_TAP2 * $vals[2]
             + self::INTERP_TAP3 * $vals[3] + self::INTERP_TAP4 * $vals[4] + self::INTERP_TAP5 * $vals[5];
        return max(0, min(255, (($val + 512) >> 10)));
    }

    /**
     * 获取参考块（支持半像素位置）
     * @param int $qpX X位置（1/2像素单位，即qpX=2表示1像素，qpX=3表示1.5像素）
     * @param int $qpY Y位置（1/2像素单位）
     */
    private function getReferenceBlock(string $refPlane, int $qpX, int $qpY): array
    {
        $block = array_fill(0, 16, array_fill(0, 16, 0));
        $intX = $qpX >> 1;
        $intY = $qpY >> 1;
        $halfX = $qpX & 1;
        $halfY = $qpY & 1;

        for ($y = 0; $y < 16; $y++) {
            for ($x = 0; $x < 16; $x++) {
                $px = $intX + $x;
                $py = $intY + $y;
                if ($halfX == 0 && $halfY == 0) {
                    // 整数像素
                    $block[$y][$x] = $this->getRefPixel($refPlane, $px, $py);
                } elseif ($halfX == 1 && $halfY == 0) {
                    // 水平半像素 b
                    $block[$y][$x] = $this->interpHorizontal($refPlane, $px, $py);
                } elseif ($halfX == 0 && $halfY == 1) {
                    // 垂直半像素 h
                    $block[$y][$x] = $this->interpVertical($refPlane, $px, $py);
                } else {
                    // 对角半像素 j
                    $block[$y][$x] = $this->interpDiagonal($refPlane, $px, $py);
                }
            }
        }
        return $block;
    }

    /**
     * 计算两个16x16块的SAD
     */
    private function computeSAD(array $block1, array $block2): int
    {
        $sad = 0;
        for ($y = 0; $y < 16; $y++) {
            for ($x = 0; $x < 16; $x++) {
                $sad += abs($block1[$y][$x] - $block2[$y][$x]);
            }
        }
        return $sad;
    }

    /**
     * 运动估计：整数像素搜索
     * @return array [mvX, mvY, sad] 运动向量和SAD值（mvX/mvY为1/4像素单位）
     */
    public function motionEstimate16x16(array $currentBlock, string $refPlane, int $mbX, int $mbY, int $searchRange = 16): array
    {
        $bestMV = [0, 0];
        $bestSAD = PHP_INT_MAX;

        $origX = $mbX * 16;
        $origY = $mbY * 16;

        $blockW = min(16, $this->width - $origX);
        $blockH = min(16, $this->height - $origY);

        // 先检查(0,0)位置
        $sad00 = 0;
        for ($y = 0; $y < $blockH; $y++) {
            for ($x = 0; $x < $blockW; $x++) {
                $refIdx = ($origY + $y) * $this->width + ($origX + $x);
                $sad00 += abs($currentBlock[$y][$x] - ord($refPlane[$refIdx]));
            }
        }
        $bestSAD = $sad00;
        $bestDX = 0;
        $bestDY = 0;

        // 大步长粗搜索（步长=4）
        $coarseStep = 4;
        for ($dy = -$searchRange; $dy <= $searchRange; $dy += $coarseStep) {
            for ($dx = -$searchRange; $dx <= $searchRange; $dx += $coarseStep) {
                if ($dx == 0 && $dy == 0) continue;
                $rx = $origX + $dx;
                $ry = $origY + $dy;

                if ($rx < 0 || $rx + $blockW > $this->width || $ry < 0 || $ry + $blockH > $this->height) {
                    continue;
                }

                $sad = 0;
                for ($y = 0; $y < $blockH; $y++) {
                    for ($x = 0; $x < $blockW; $x++) {
                        $refIdx = ($ry + $y) * $this->width + ($rx + $x);
                        $sad += abs($currentBlock[$y][$x] - ord($refPlane[$refIdx]));
                    }
                }

                if ($sad < $bestSAD) {
                    $bestSAD = $sad;
                    $bestDX = $dx;
                    $bestDY = $dy;
                }
            }
        }

        // 小步长精搜索（在粗搜索最佳点周围±3，步长=1）
        $refineRange = 3;
        for ($dy = $bestDY - $refineRange; $dy <= $bestDY + $refineRange; $dy++) {
            for ($dx = $bestDX - $refineRange; $dx <= $bestDX + $refineRange; $dx++) {
                if ($dx == 0 && $dy == 0 && $bestDX == 0 && $bestDY == 0) continue;
                if (abs($dx) > $searchRange || abs($dy) > $searchRange) continue;
                $rx = $origX + $dx;
                $ry = $origY + $dy;

                if ($rx < 0 || $rx + $blockW > $this->width || $ry < 0 || $ry + $blockH > $this->height) {
                    continue;
                }

                $sad = 0;
                for ($y = 0; $y < $blockH; $y++) {
                    for ($x = 0; $x < $blockW; $x++) {
                        $refIdx = ($ry + $y) * $this->width + ($rx + $x);
                        $sad += abs($currentBlock[$y][$x] - ord($refPlane[$refIdx]));
                    }
                }

                if ($sad < $bestSAD) {
                    $bestSAD = $sad;
                    $bestDX = $dx;
                    $bestDY = $dy;
                }
            }
        }

        $bestMV = [$bestDX * 4, $bestDY * 4];
        return [$bestMV[0], $bestMV[1], $bestSAD];
    }

    // P帧运动向量缓存（用于MVP预测）
    public $mvLeftCol = [];      // 左边宏块列的MV
    public $mvTopRow = [];       // 上边宏块行的MV
    public $lastMbWasSkip = false; // 上一个宏块是否为P_Skip

    /**
     * 编码P帧宏块（P_16x16模式）
     * 包含运动估计、MVP预测、MVD编码、残差编码
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
        $bits = '';

        // 提取当前宏块像素
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

        // 运动估计（返回1/4像素单位的MV）
        list($mvX, $mvY, $sad) = $this->motionEstimate16x16($lumaPixels, $refYPlane, $mbX, $mbY);

        // 先计算残差和CBP
        $predBlock = array_fill(0, 16, array_fill(0, 16, 128));
        $intMvX = $mvX / 4; // 1/4像素 -> 整数像素
        $intMvY = $mvY / 4;
        $refX = $mbX * 16 + $intMvX;
        $refY = $mbY * 16 + $intMvY;

        for ($y = 0; $y < 16; $y++) {
            for ($x = 0; $x < 16; $x++) {
                $ry = $refY + $y;
                $rx = $refX + $x;
                if ($ry >= 0 && $ry < $this->height && $rx >= 0 && $rx < $this->width) {
                    $idx = $ry * $this->width + $rx;
                    $predBlock[$y][$x] = ord($refYPlane[$idx]);
                }
            }
        }

        // 计算残差
        $residual = array_fill(0, 16, array_fill(0, 16, 0));
        for ($y = 0; $y < 16; $y++) {
            for ($x = 0; $x < 16; $x++) {
                $residual[$y][$x] = $lumaPixels[$y][$x] - $predBlock[$y][$x];
            }
        }

        // DCT和量化
        $nzCache = array_fill(0, 24, 0);
        $cbpLuma = 0;
        $quantResidual = [];

        for ($by = 0; $by < 4; $by++) {
            for ($bx = 0; $bx < 4; $bx++) {
                $blkIdx = $by * 4 + $bx;
                $blk4x4 = array_fill(0, 4, array_fill(0, 4, 0));

                for ($y = 0; $y < 4; $y++) {
                    for ($x = 0; $x < 4; $x++) {
                        $blk4x4[$y][$x] = $residual[$by * 4 + $y][$bx * 4 + $x];
                    }
                }

                $dctBlock = $this->dct($blk4x4);
                $quantBlock = $this->quantize($dctBlock, 0, true);

                $nz = 0;
                $quantResidual[$blkIdx] = array_fill(0, 16, 0);
                for ($y = 0; $y < 4; $y++) {
                    for ($x = 0; $x < 4; $x++) {
                        $quantResidual[$blkIdx][$y * 4 + $x] = $quantBlock[$y][$x];
                        if ($quantBlock[$y][$x] != 0) $nz++;
                    }
                }

                $nzCache[$blkIdx] = min(15, $nz);
                // cbpLuma: 4位，每个位对应一个8x8块
                $subY = intdiv($blkIdx, 4);
                $subX = $blkIdx % 4;
                $block8x8Idx = intdiv($subY, 2) * 2 + intdiv($subX, 2);
                if ($nz > 0) $cbpLuma |= (1 << $block8x8Idx);
            }
        }

        // 对于未编码的8x8块，nzCache必须置0（与解码器一致）
        for ($blkIdx = 0; $blkIdx < 16; $blkIdx++) {
            $subY = intdiv($blkIdx, 4);
            $subX = $blkIdx % 4;
            $block8x8Idx = intdiv($subY, 2) * 2 + intdiv($subX, 2);
            if (!($cbpLuma & (1 << $block8x8Idx))) {
                $nzCache[$blkIdx] = 0;
            }
        }

        // 如果cbpLuma=0且MV=(0,0)，使用P_Skip
        // CAVLC模式下P_Skip通过mb_skip_run编码，不写mb_type
        if ($cbpLuma == 0 && $mvX == 0 && $mvY == 0) {
            $this->lastMbWasSkip = true;

            // 更新邻居缓存
            for ($by = 0; $by < 4; $by++) {
                $leftNz[$by] = 0;
            }
            for ($bx = 0; $bx < 4; $bx++) {
                $topBlkX = $mbX * 4 + $bx;
                if ($topBlkX < count($topNzLuma)) {
                    $topNzLuma[$topBlkX] = 0;
                }
            }

            // 保存当前MV供后续宏块预测（1/4像素单位）
            $this->mvLeftCol[$mbY] = [$mvX, $mvY];
            $this->mvTopRow[$mbX] = [$mvX, $mvY];

            // P_Skip重建: 直接使用MC预测（MV=0, 无残差）
            for ($y = 0; $y < 16; $y++) {
                for ($x = 0; $x < 16; $x++) {
                    $py = $mbY * 16 + $y;
                    $px = $mbX * 16 + $x;
                    if ($py < $this->height && $px < $this->width) {
                        $val = max(0, min(255, $predBlock[$y][$x]));
                        $this->reconYPlane[$py * $this->width + $px] = chr($val);
                    }
                }
            }

            return '';
        }

        // 非Skip宏块
        $this->lastMbWasSkip = false;

        // P_L0_16x16模式: mb_type = 0 in P slice (1 partition, 1 MV)
        $bits .= $this->ue(0); // mb_type = 0 for P_L0_16x16

        // 运动向量预测(MVP)
        $leftMV = [0, 0];
        if ($leftAvailable && isset($this->mvLeftCol[$mbY])) {
            $leftMV = $this->mvLeftCol[$mbY];
        }
        $topMV = [0, 0];
        if ($topAvailable && isset($this->mvTopRow[$mbX])) {
            $topMV = $this->mvTopRow[$mbX];
        }
        $topRightMV = [0, 0];
        if ($topAvailable && isset($this->mvTopRow[$mbX + 1])) {
            $topRightMV = $this->mvTopRow[$mbX + 1];
        }
        $mvpX = $this->median3($leftMV[0], $topMV[0], $topRightMV[0]);
        $mvpY = $this->median3($leftMV[1], $topMV[1], $topRightMV[1]);

        // MVD = MV - MVP (1/4像素单位)
        $mvdX = $mvX - $mvpX;
        $mvdY = $mvY - $mvpY;
        $bits .= $this->se($mvdX);
        $bits .= $this->se($mvdY);

        // CBP编码（P帧使用Inter映射表）
        // Inter模式CBP映射表 (codeNum -> cbp)，必须与解码器GOLOMB_TO_INTER_CBP完全一致
        $interCbpMap = [
            0, 16, 1, 2, 4, 8, 32, 3, 5, 10, 12, 15, 47, 7, 11, 13,
            14, 6, 9, 31, 35, 37, 42, 44, 33, 34, 36, 40, 39, 43, 45, 46,
            17, 18, 20, 24, 19, 21, 26, 28, 23, 27, 29, 30, 22, 25, 38, 41,
        ];
        // 查找cbp对应的codeNum
        $cbpFull = $cbpLuma;
        $cbpCode = array_search($cbpFull, $interCbpMap);
        if ($cbpCode === false) $cbpCode = 0;
        $bits .= $this->ue($cbpCode);

        // mb_qp_delta
        if ($cbpLuma > 0) {
            $bits .= $this->se(0);
        }

        // 编码残差（按8x8块分组，仅编码cbpLuma对应位为1的块）
        if ($cbpLuma > 0) {
            // 每个8x8块包含4个4x4子块（按scan4顺序排列）
            $blockGroups = [
                [0, 1, 4, 5],    // 8x8 block 0 (top-left)
                [2, 3, 6, 7],    // 8x8 block 1 (top-right)
                [8, 9, 12, 13],  // 8x8 block 2 (bottom-left)
                [10, 11, 14, 15],// 8x8 block 3 (bottom-right)
            ];
            for ($blk8 = 0; $blk8 < 4; $blk8++) {
                if (!($cbpLuma & (1 << $blk8))) {
                    continue;
                }
                foreach ($blockGroups[$blk8] as $rasterIdx) {
                    $by = (int)($rasterIdx / 4);
                    $bx = $rasterIdx % 4;

                    $ac = $this->scan4x4DcAc($quantResidual[$rasterIdx]);
                    $acNc = $this->computeNC($rasterIdx, $mbX, $bx, $by, $leftAvailable, $leftNz, $topAvailable, $topNzLuma, $nzCache);
                    $bits .= $this->writeBlockResidualCavlc($ac, 15, false, $acNc);
                }
            }
        }

        // 更新邻居缓存
        for ($by = 0; $by < 4; $by++) {
            $leftNz[$by] = $nzCache[$by * 4 + 3];
        }
        for ($bx = 0; $bx < 4; $bx++) {
            $topBlkX = $mbX * 4 + $bx;
            if ($topBlkX < count($topNzLuma)) {
                $topNzLuma[$topBlkX] = $nzCache[$bx + 12];
            }
        }

        // 保存当前MV供后续宏块预测（1/4像素单位）
        $this->mvLeftCol[$mbY] = [$mvX, $mvY];
        $this->mvTopRow[$mbX] = [$mvX, $mvY];

        // === P帧本地解码重建 ===
        for ($by = 0; $by < 4; $by++) {
            for ($bx = 0; $bx < 4; $bx++) {
                $blkIdx = $by * 4 + $bx;
                $block8x8Idx = intdiv($by, 2) * 2 + intdiv($bx, 2);

                if ($cbpLuma & (1 << $block8x8Idx)) {
                    // 有残差: 反量化 + IDCT + 加到MC预测
                    $acDequant = $this->dequantize4x4($quantResidual[$blkIdx], 0, $this->qp);
                    $acBlock = array_fill(0, 4, array_fill(0, 4, 0));
                    for ($y = 0; $y < 4; $y++) for ($x = 0; $x < 4; $x++) $acBlock[$y][$x] = $acDequant[$y * 4 + $x];
                    $idctResult = $this->idct4x4($acBlock);

                    for ($y = 0; $y < 4; $y++) {
                        for ($x = 0; $x < 4; $x++) {
                            $py = $mbY * 16 + $by * 4 + $y;
                            $px = $mbX * 16 + $bx * 4 + $x;
                            if ($py < $this->height && $px < $this->width) {
                                $val = $predBlock[$by * 4 + $y][$bx * 4 + $x] + $idctResult[$y][$x];
                                $val = max(0, min(255, $val));
                                $this->reconYPlane[$py * $this->width + $px] = chr($val);
                            }
                        }
                    }
                } else {
                    // 无残差: 直接使用MC预测
                    for ($y = 0; $y < 4; $y++) {
                        for ($x = 0; $x < 4; $x++) {
                            $py = $mbY * 16 + $by * 4 + $y;
                            $px = $mbX * 16 + $bx * 4 + $x;
                            if ($py < $this->height && $px < $this->width) {
                                $val = max(0, min(255, $predBlock[$by * 4 + $y][$bx * 4 + $x]));
                                $this->reconYPlane[$py * $this->width + $px] = chr($val);
                            }
                        }
                    }
                }
            }
        }

        return $bits;
    }

    /**
     * 中值滤波（用于MVP计算）
     */
    private function median3(int $a, int $b, int $c): int
    {
        $arr = [$a, $b, $c];
        sort($arr);
        return $arr[1];
    }
}