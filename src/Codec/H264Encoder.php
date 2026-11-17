<?php

namespace Xiaosongshu\Flv2mp4\Codec;

use Xiaosongshu\Flv2mp4\Codec\Encode\BitstreamTrait;
use Xiaosongshu\Flv2mp4\Codec\Encode\CabacEncodingTrait;
use Xiaosongshu\Flv2mp4\Codec\Encode\CavlcTrait;
use Xiaosongshu\Flv2mp4\Codec\Encode\InterPredTrait;
use Xiaosongshu\Flv2mp4\Codec\Encode\IntraPredTrait;
use Xiaosongshu\Flv2mp4\Codec\Encode\MotionTrait;
use Xiaosongshu\Flv2mp4\Codec\Encode\SliceEncodeTrait;
use Xiaosongshu\Flv2mp4\Codec\Encode\SpsPpsTrait;
use Xiaosongshu\Flv2mp4\Codec\Encode\TransformTrait;

/**
 * @purpose yuv重建码流
 * @author yanglong
 * @time 2026年7月23日14:48:28
 * @note 这个是图像的压缩算法。
 * 运动估计：利用视频的时间冗余（帧间预测/运动估计）或空间冗余（帧内预测），用已经解码的像素去推导当前像素
 * 残差计算：原始像素值与预测像素值相减，得到预测误差（残差）。它是真正需要传输的有效信息
 * DCT 变换：将残差数据从空间域（像素点）转换到频率域（频域系数），实现能量集中（Energy Compaction），且过程完全无损。将数据收拢到左上角，其他剩余数据记录了特征值
 * 关于DCT变换，就是左上角存储的是一个当前块的平均值，然后剩余的值，每一个值代表记录的一个维度的具体值。这个和双塔推荐模型的词元的量化是类似的
 * 量化：将 DCT 系数除以量化步长（QStep）并取整。利用人眼视觉特性（HVS）对高频不敏感的特点，进行不可逆的有损压缩。这个类似于双塔推荐模型的缩放处理-两者都会用一个“缩放因子（Scale）”去除以原始数据。把大数值缩小，把精度降低，保留特征
 * 熵编码：将量化后的语法元素（大量连续的0和非零系数），通过 CAVLC（查表）或 CABAC（算术编码）转换为最终的无损二进制比特流，就是将处理的数据进行压缩打包，比如多个0直接省略一个0+长度的压缩写法，
 * 可以使用查字典cavlc或者概率预测cabac
 * cavlc算法：使用字典密码本，使用压缩后的值编码，解码的时候，使用这个值去字典中查真实的值还原即可。
 * cabac算法：使用数学模型，算下一个值的概率，每一次预测会更新0和1的概率，缩小概率区间，就像线段一样，越来越短，精确度越来越高
 * qp值越大，量化的时候步长越大，很多特征数据就被量化成0了，画面就会很糊。
 */
class H264Encoder
{
    // 组合各功能 trait：
    // BitstreamTrait    位串/字节流与 NAL 封装（ue/se/u、防竞争字节）
    // TransformTrait    整数 DCT 变换与量化/反量化
    // CavlcTrait        CAVLC 熵编码（规范 9.2）
    // CabacEncodingTrait CABAC 熵编码（规范 9.3）
    // IntraPredTrait    帧内预测（规范 8.3）
    // MotionTrait       运动搜索
    // InterPredTrait    帧间预测与亚像素插值（规范 8.4）
    // SpsPpsTrait       参数集生成（规范 7.3.2）
    // SliceEncodeTrait  整帧/片级编码流程（规范 7.3.3、7.3.4）
    use BitstreamTrait, TransformTrait, CavlcTrait, CabacEncodingTrait, IntraPredTrait, MotionTrait, InterPredTrait, SpsPpsTrait, SliceEncodeTrait;

    /** CABAC 熵编码开关（main/high profile 置 true；baseline 保持 CAVLC） */
    public bool $useCabac = false;
    /** PPS transform_8x8_mode_flag（high=true，镜像解码端 parsePPS，8x8 变换语法开关） */
    public bool $transform8x8ModeFlag = false;
    /** SPS direct_8x8_inference_flag（high=true，镜像解码端 parseSPS，B_Direct 8x8 推理） */
    public bool $direct8x8InferenceFlag = true;
    /** I 片 8x8 变换开关（high=true；供对比实验/后续决策关闭用） */
    public bool $intra8x8Enabled = true;
    /** I 片 8x8 QP 补偿偏移（8x8 同 QP 步长约为 4x4 的 2 倍，降低 I 片 QP 保参考质量） */
    public int $intra8x8QpOffset = -4;
    /** high 档 IDR 的 I16/I8 逐 GOP 决策开关（IDR 双路试编取片 NAL 更小者） */
    public bool $intraGopDecisionEnabled = true;
    /** P/B 16x16 inter 8x8DCT 开关（T5，high=true）：prepare 端 4x4/8x8 双路量化按 Σ|level| 取优 */
    public bool $inter8x8Enabled = false;
    /** 逐 GOP 决策内部标记：当前片强制走 I16 路径（I16 试编遍置位） */
    public bool $forceIntra16Slice = false;
    /** 逐 GOP 决策统计：I16 胜出 / I8x8 胜出的 GOP 数（供 qp 补偿调参观察） */
    public int $gopI16Wins = 0;
    public int $gopI8Wins = 0;
    // 反量化 4x4 的基础系数表（规范表 8-15 levelScale4x4 的 6 档，3 个位置类别）
    // 行 = QP%6，列 = 位置类别（0/1/2），构建 dequant4Table 时再叠加 flat scaling matrix 与移位
    public const DEQUANT4_COEFF_INIT = [
        [10, 13, 16],
        [11, 14, 18],
        [13, 16, 20],
        [14, 18, 23],
        [16, 20, 25],
        [18, 23, 29],
    ];

    /**
     * 色度QP映射表（编码标准）
     * 编码器和解码器必须使用相同的映射
     */
    // 表项含义：QPc = ChromaQpTable[Clip3(0, 51, QP + chroma_qp_index_offset)]（规范表 8-15 的色度 QP 映射）
    // 当 QP 较大时多个亮度 QP 会映射到同一色度 QP，从而限制色度量化步长
    public const CHROMA_QP_TABLE = [
        0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15,
        16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 28, 29,
        29, 30, 31, 32, 32, 33, 34, 34, 35, 35, 36, 36, 37, 37,
        37, 38, 38, 38, 39, 39, 39, 39,
    ];

    /**
     * 量化乘法因子表
     * 索引: QP (0-51), j = position & 7
     */
    // 正向量化乘法因子（规范 8.5.8.1）：量化 level = (|W| * MF + f) >> qbits
    // MF 由 levelScale4x4 放大得到；每行 8 个值按「位置类别 + 行列交错」排列，与参考实现一致
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
    // 量化舍入偏置因子 f 的查表（规范 8.5.8：intra 取 f = 2^qbits/3，inter 取 2^qbits/6）
    // 本表按 inter 组织，intra 通过索引 QP+6 复用同一张表（与参考实现保持一致）
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

    // 4x4 量化系数的之字形扫描顺序（规范 8.5.6）：数组下标为扫描序号，值为 4x4 块内的光栅位置
    // 熵编码与反量化都按该顺序访问系数，使非零系数集中在前面（能量集中）
    public const ZIGZAG_SCAN_4X4 = [0, 1, 4, 8, 5, 2, 3, 6, 9, 12, 13, 10, 7, 11, 14, 15];

    // coeff_token 码表的行号辅助表：以 TotalCoeff 为下标，返回对应 nC 档位的表内行索引
    public const CT_INDEX = [0, 0, 1, 1, 2, 2, 2, 2, 3, 3, 3, 3, 3, 3, 3, 3, 3];

    // nC 档位映射（规范 9.2.1）：nC 0..16 → coeff_token 表号 0..3，nC=-1（色度 DC）→ 表号 4
    public const ENC_NC_MAP_TABLE = [0, 0, 1, 1, 2, 2, 2, 2, 3, 3, 3, 3, 3, 3, 3, 3, 3, 4];

    // coeff_token 码表（规范表 9-5）：[表号][TotalCoeff][TrailingOnes] = [码字值, 码字位长]
    // 表号 0: 0<=nC<2；1: 2<=nC<4；2: 4<=nC<8；3: nC>=8；4: 色度 DC（nC=-1）
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

    // total_zeros 码表（规范表 9-7/9-8）：[TotalCoeff][total_zeros] = [码字值, 码字位长]
    // 下标 0 行（TotalCoeff=0）全为 0，因为全零块不写 total_zeros
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

    // 色度 DC 专用 total_zeros 码表（规范表 9-9a）：仅 TotalCoeff<4 时使用
    // [TotalCoeff][total_zeros] = [码字值, 码字位长]
    public const VLC_TOTAL_ZEROS_CHROMA_DC = [
        [[0,0],[0,0],[0,0],[0,0]],
        [[1,1],[1,2],[1,3],[0,3]],
        [[1,1],[1,2],[0,2],[0,0]],
        [[1,1],[0,1],[0,0],[0,0]],
    ];

    // run_before 码表（规范表 9-10）：[行号][run_before] = [码字值, 码字位长]
    // 行号由 ZERO_LEFT_MAP[zerosLeft] 给出；最后一行（zerosLeft>=7）的后缀为定长 3 位起递增
    public const VLC_RUN_BEFORE = [
        [[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0]],
        [[1,1],[0,1],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0]],
        [[1,1],[1,2],[0,2],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0]],
        [[3,2],[2,2],[1,2],[0,2],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0]],
        [[3,2],[2,2],[1,2],[1,3],[0,3],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0]],
        [[3,2],[2,2],[3,3],[2,3],[1,3],[0,3],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0]],
        [[3,2],[0,3],[1,3],[3,3],[2,3],[5,3],[4,3],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0],[0,0]],
        [[7,3],[6,3],[5,3],[4,3],[3,3],[2,3],[1,3],[1,4],[1,5],[1,6],[1,7],[1,8],[1,9],[1,10],[1,11]],
    ];

    // zerosLeft → VLC_RUN_BEFORE 行号映射；zerosLeft>=7 统一使用最后一行（规范表 9-10 的行划分）
    public const ZERO_LEFT_MAP = [0, 1, 2, 3, 4, 5, 6, 7, 7, 7, 7, 7, 7, 7, 7, 7];

    // 宏块类型常量（本编码器内部编号，最终写码流时映射为规范 7.4.5 的 mb_type）
    // I16x16 = 整宏块 16x16 帧内预测（规范表 7-11，mb_type 1..24 的 I_16x16 系列）
    public const MB_TYPE_I16x16 = 0;
    // I4x4 = 16 个 4x4 帧内预测块（I_NxN，规范表 7-11 mb_type=0）
    public const MB_TYPE_I4x4 = 1;
    // P_16x16 = 16x16 帧间预测（规范表 7-13 mb_type=0 的 P_L0_16x16）
    public const MB_TYPE_P_16x16 = 2;
    // P_16x8 = 两个 16x8 分区（规范表 7-13 的 P_L0_L0_16x8 系列）
    public const MB_TYPE_P_16x8 = 3;
    // P_8x16 = 两个 8x16 分区（规范表 7-13 的 P_L0_L0_8x16 系列）
    public const MB_TYPE_P_8x16 = 4;
    // P_8x8 = 四个 8x8 分区，可再细分到 4x4（规范表 7-13 的 P_8x8 / P_8x8ref0）
    public const MB_TYPE_P_8x8 = 5;

    // 场景切换检测：单宏块平均 SAD 超过该阈值视为「高差异」
    private const SCENE_SAD_THRESHOLD = 40;
    // 高差异宏块占比超过该比例才认为发生了场景切换
    private const SCENE_RATIO_THRESHOLD = 0.5;
    // 区域检测：差异区域面积占全帧的比例阈值
    private const SCENE_REGION_MB_RATIO = 0.20;
    // 区域检测：区域平均 SAD 阈值
    private const SCENE_REGION_MEAN = 55;
    /** 持续高 SAD 超过该帧数后再允许一次 IDR（兜底"运动中硬切"）；15fps 下约 6 秒 */
    private const SCENE_SUSTAIN_MAX = 90;
    /** 抽样点带符号差均值/绝对差均值 ≥ 此值 → 同向全局亮度变化（灯光频闪/曝光跳变），非硬切，不强制 IDR */
    private const SCENE_FLASH_SIGNED_RATIO = 0.62;
    /** 两次强制 IDR 的最小间隔（输出帧，15fps 下约 1 秒）：频闪连闪期间只允许一次，其余等源 IDR */
    private const SCENE_FORCE_COOLDOWN = 15;

    // 输出帧宽度（像素）
    public $width = 640;
    // 输出帧高度（像素）
    public $height = 360;
    // 帧率（用于码率/QP 估算与 VUI 相关参数，本实现 SPS 未写 timing_info）
    public $fps = 30;
    // 目标码率（bit/s），仅在 setBitrate 中参与 QP 估算
    public $bitrate = 500000;
    // 亮度量化参数 QP（0..51，越大压缩越强、画质越差）
    public $qp = 22;
    // 色度 QP 偏移（PPS chroma_qp_index_offset，规范 7.4.2.2）
    public $chromaQpIndexOffset = 0;
    // 当前帧使用的宏块类型（取 MB_TYPE_* 常量）
    public $mbType = self::MB_TYPE_I16x16;

    /**
     * 输出 H.264 profile：baseline | main | high（非法/缺失值回落 baseline）。
     * 仅影响 SPS/PPS 的 profile_idc 与高等级扩展语法字段，不改变宏块编码路径。
     */
    public $profile = 'baseline';

    /**
     * P帧QP偏移量（相对于I帧QP）
     * 默认 +2，即 P 帧 QP = I 帧 QP + 2
     */
    public $pFrameQpDelta = 0;

    // 宏块对齐尺寸（与解码器一致，用于重建帧和参考帧）
    // 解码器使用mbAlignedWidth/Height存储参考帧，编码器必须匹配
    public int $mbAlignedWidth = 0;
    public int $mbAlignedHeight = 0;

    // 帧号 frame_num（规范 7.4.3，位宽由 log2_max_frame_num_minus4 决定）
    public $frameNum = 0;
    // IDR 图像序号 idr_pic_id（规范 7.4.3，每个 IDR 递增）
    public $idrPicId = 0;
    // 图像顺序计数 POC（pic_order_cnt，规范 8.2.1）
    public $poc = 0;

    // SPS 中 log2_max_frame_num_minus4（frame_num 位宽 - 4）
    public $log2MaxFrameNumMinus4 = 0;
    // SPS 中 log2_max_pic_order_cnt_lsb_minus4（POC LSB 位宽 - 4）
    public $log2MaxPicOrderCntLsbMinus4 = 0;

    // 量化矩阵（默认使用 flat 矩阵，值 6/13/20/28... 为示例缩放系数）
    public $quantMatrix = [];

    // 反量化表（用于本地解码重建参考帧）
    // 结构 [6][52][16]：QP%6、QP、4x4 位置，构建方式见 initQuantMatrix()
    public $dequant4Table = [];

    // P帧参考帧管理
    public $refYPlane = null;      // 参考帧Y平面（重建后的）
    public $refUPlane = null;      // 参考帧U平面
    public $refVPlane = null;      // 参考帧V平面
    public $refInts = null;        // 参考帧Y平面整数数组缓存（优化运动估计速度）
    public $enableInter = true;   // 是否启用P帧
    public $numRefFrames = 1;      // 参考帧数量

    /**
     * 是否启用 B 帧（IBBP）编码。关闭时完全保持原 I/P 编码行为。
     */
    public bool $bFrames = false;

    /** B 帧 L0（过去锚点）参考平面：编码锚点前由驱动层快照 */
    public $bRefL0YPlane = null;
    public $bRefL0UPlane = null;
    public $bRefL0VPlane = null;

    /** B 片 MVP 邻居缓存（双 list，与解码器 bMvLeftCol/bMvTopRow/bMvTopLeft 一一对应） */
    public $bMvLeftCol = [];
    public $bMvTopRow = [];
    public $bMvTopLeft = [null, null];

    /** B 片两个参考帧（L0=过去锚点 / L1=未来锚点）的 Y 平面整数缓存，每片仅 unpack 一次 */
    public $bRefIntsL0 = null;
    public $bRefIntsL1 = null;

    /** B 帧当前宏块的 curFlat 缓存：prepareBMacroblock 产出，forcedMv 路径的 preparePMacroblock 复用，免去二次 unpack */
    public $bCurFlat = null;

    // 本地解码重建帧（用于正确更新参考帧，避免编解码器失配）
    public $reconYPlane = '';
    public $reconUPlane = '';
    public $reconVPlane = '';

    /**
     * 构造函数：记录几何/码率参数，并预先构建量化矩阵与反量化表。
     *
     * @param int $width   输出宽度（像素）
     * @param int $height  输出高度（像素）
     * @param int $fps     帧率
     * @param int $bitrate 目标码率（bit/s）
     */
    public function __construct(int $width = 0, int $height = 0, int $fps = 25, int $bitrate = 1000000)
    {
        // 保存输出宽度
        $this->width = $width;
        // 保存输出高度
        $this->height = $height;
        // 保存帧率
        $this->fps = $fps;
        // 保存目标码率
        $this->bitrate = $bitrate;
        // 参考帧整数缓存初始为空（首帧没有参考帧）
        $this->refInts = null;
        // 构建量化/反量化表
        $this->initQuantMatrix();
    }

    /**
     * 初始化量化矩阵与反量化表。
     * 反量化表在本地解码重建时使用，必须与解码端算法完全一致，否则参考帧会漂移。
     */
    public function initQuantMatrix(): void
    {
        // 4x4 帧内默认缩放矩阵（flat 矩阵的示例值，实际未启用自定义矩阵）
        $this->quantMatrix[0] = [6, 13, 20, 28, 13, 20, 28, 32, 20, 28, 32, 37, 28, 32, 37, 42];
        // 4x4 帧间默认缩放矩阵
        $this->quantMatrix[1] = [6, 13, 20, 28, 13, 20, 28, 32, 20, 28, 32, 37, 28, 32, 37, 42];

        // 构建反量化表（与解码器一致，使用flat scaling matrix=16）
        // 位置类别：0/1/2 对应规范表 8-15 的三档 levelScale
        $posClass = [0, 1, 0, 1, 1, 2, 1, 2, 0, 1, 0, 1, 1, 2, 1, 2];
        // flat scaling matrix：16 个位置全部取 16（规范 8.5.12 的 flat 情形）
        $flatScaling4 = array_fill(0, 16, 16);
        // 反量化表 [6][52][16] 初始化
        $this->dequant4Table = array_fill(0, 6, array_fill(0, 52, array_fill(0, 16, 0)));
        // 外层：6 个 QP 余数档
        for ($i = 0; $i < 6; $i++) {
            // 中层：52 个 QP
            for ($q = 0; $q < 52; $q++) {
                // 反量化右移量 = qp/6 + 2（规范 8.5.12 的 levelShift）
                $shift = intdiv($q, 6) + 2;
                // 当前 QP 对应的 levelScale 列
                $idx = $q % 6;
                // 内层：16 个 4x4 系数位置
                for ($x = 0; $x < 16; $x++) {
                    // 位置类别
                    $scaleIdx = $posClass[$x];
                    // 表项 = levelScale * scalingMatrix << levelShift
                    $this->dequant4Table[$i][$q][$x] =
                        (self::DEQUANT4_COEFF_INIT[$idx][$scaleIdx] * $flatScaling4[$x]) << $shift;
                }
            }
        }
    }

    /**
     * 设置分辨率（不重建量化表，量化表只与 QP 有关）。
     *
     * @param int $width  宽度
     * @param int $height 高度
     */
    public function setResolution(int $width, int $height): void
    {
        // 更新宽度
        $this->width = $width;
        // 更新高度
        $this->height = $height;
    }

    /** 清理单个GOP的参考帧和帧级流水线状态，保留已预热的运动worker。 */
    public function resetGopState(): void
    {
        // 重置 frame_num（新 GOP 从 0 开始）
        $this->frameNum = 0;
        // 重置 IDR 图像序号
        $this->idrPicId = 0;
        // 重置 POC
        $this->poc = 0;
        // 清空参考帧三分量（避免引用上一 GOP 的画面）
        $this->refYPlane = null;
        $this->refUPlane = null;
        $this->refVPlane = null;
        // 清空参考帧整数缓存
        $this->refInts = null;
        // 清空本地重建平面
        $this->reconYPlane = '';
        $this->reconUPlane = '';
        $this->reconVPlane = '';
        // 清空 P 片 MVP 邻居缓存
        $this->mvLeftCol = [];
        $this->mvTopRow = [];
        $this->mvTopLeft = null;
        // 重置 P_Skip 状态
        $this->lastMbWasSkip = false;
        // 清空运动搜索结果与流水线在途状态
        $this->motionWorkerResults = [];
        $this->pipeFlight = null;
        $this->pipePending = null;
        // 重置重建预处理标志
        $this->reconPreassembled = false;
        $this->reconPrefetched = false;
        // 重置场景切换检测状态
        $this->scenePrevY = null;
        $this->scenePrevAw = 0;
        $this->scenePrevAh = 0;
        $this->sceneHighRun = 0;
        // 清空 B 帧 L0 参考平面
        $this->bRefL0YPlane = null;
        $this->bRefL0UPlane = null;
        $this->bRefL0VPlane = null;
        // 清空 B 片 MVP 邻居缓存
        $this->bMvLeftCol = [];
        $this->bMvTopRow = [];
        $this->bMvTopLeft = [null, null];
        // 清空 B 片参考帧整数缓存
        $this->bRefIntsL0 = null;
        $this->bRefIntsL1 = null;
    }

    /**
     * 设置 QP，并钳制到规范允许的 0..51。
     *
     * @param int $qp 亮度 QP
     */
    public function setQp(int $qp): void
    {
        // 下界钳制
        if ($qp < 0) $qp = 0;
        // 上界钳制
        if ($qp > 51) $qp = 51;
        $this->qp = $qp;
    }

    /**
     * 设置宏块类型（取 MB_TYPE_* 常量）。
     *
     * @param int $type 宏块类型
     */
    public function setMbType(int $type): void
    {
        $this->mbType = $type;
    }

    /**
     * 设置输出 profile：baseline | main | high；其它值一律回落 baseline。
     */
    public function setProfile(string $profile): void
    {
        // 归一化输入（去空白、转小写）
        $profile = strtolower(trim($profile));
        // 非法取值回落 baseline
        if (!in_array($profile, ['baseline', 'main', 'high'], true)) {
            $profile = 'baseline';
        }
        $this->profile = $profile;
        // CABAC 开关与 PPS entropy_coding_mode_flag 保持一致：main/high 用 CABAC
        $this->useCabac = ($profile === 'main' || $profile === 'high');
        // 8x8 变换开关镜像解码端 parseSPS/parsePPS 语义：high 开、main/baseline 关
        $this->transform8x8ModeFlag = ($profile === 'high');
        // direct_8x8_inference_flag 仅在 8x8 变换可用时置位（规范 7.4.2.1.1）
        $this->direct8x8InferenceFlag = ($profile === 'high');
        // T5：inter 8x8DCT 决策随 high 同步启停（batch 头标志一并透传给 motion worker）
        $this->inter8x8Enabled = ($profile === 'high');
    }

    /**
     * 开启/关闭 B 帧编码。开启后 SPS 的 num_ref_frames≥2、log2_max_poc_lsb 扩展为 256，
     * 由驱动层按 IBBP 解码序调用 encodeBFrame()。
     */
    public function enableBFrames(bool $enabled = true): void
    {
        // 记录开关
        $this->bFrames = $enabled;
        // B 帧至少需要 2 个参考帧（L0 过去、L1 未来），否则解码端无法重建
        if ($enabled && $this->numRefFrames < 2) {
            $this->numRefFrames = 2;
        }
    }

    /** 编码锚点帧（I/P）前，把当前参考帧快照为 B 的 L0（过去）参考平面。 */
    public function snapshotBRefL0(): void
    {
        // PHP 字符串/数组赋值即复制，快照后即使参考帧后续被替换也不受影响
        $this->bRefL0YPlane = $this->refYPlane;
        $this->bRefL0UPlane = $this->refUPlane;
        $this->bRefL0VPlane = $this->refVPlane;
    }

    /**
     * 设置帧率（仅保存，不直接参与码流语法）。
     *
     * @param int $fps 帧率
     */
    public function setFps(int $fps): void
    {
        $this->fps = $fps;
    }

    /**
     * 根据目标码率估算 QP：在对数码率轴上 100kbps~10Mbps 线性映射到 QP 38~18。
     * 码率越高 QP 越小（画质越好），最后钳制到 18..38，避免极端取值。
     *
     * @param int $bitrate 目标码率（bit/s）
     */
    public function setBitrate(int $bitrate): void
    {
        // 保存码率
        $this->bitrate = $bitrate;
        // 取对数（下限 100kbps，避免 log(0)）
        $logBitrate = log(max(100000, $bitrate));
        // 映射区间下界
        $logRef = log(100000);
        // 映射区间上界
        $logMax = log(10000000);
        // QP 映射区间宽度（38 - 18）
        $qpRange = 38 - 18;
        // 在 [0,1] 上的归一化位置
        $ratio = ($logBitrate - $logRef) / ($logMax - $logRef);
        // 线性映射并四舍五入：码率越高 ratio 越大，QP 越小
        $this->qp = (int)round(38 - $ratio * $qpRange);
        // 钳制到 18..38
        $this->qp = max(18, min(38, $this->qp));
    }

    // 帧编码入口（encodeFrame / startFrame / finishFrame）见 SliceEncodeTrait

    // ue(v) 码字缓存：小值的指数哥伦布码字在编码中反复出现，缓存可避免重复 decbin/str_repeat
    private static $ueCache = [];


    /**
     * 6抽头插值滤波器系数 (1/32, -5/32, 20/32, 20/32, -5/32, 1/32)
     * 编码标准规定的半像素插值
     */
    // 补充：规范 8.4.2.2.1 的 1/4 像素亮度插值滤波器为 (1, -5, 20, 20, -5, 1)/32，
    // 系数和为 32 保证直流增益为 1；TAP0..TAP5 即该滤波器的 6 个抽头
    private const INTERP_TAP0 = 1;
    private const INTERP_TAP1 = -5;
    private const INTERP_TAP2 = 20;
    private const INTERP_TAP3 = 20;
    private const INTERP_TAP4 = -5;
    private const INTERP_TAP5 = 1;


    // P帧运动向量缓存（与解码器一致的4x4子块粒度存储）
    // mvLeftCol[0..3]: 左邻居宏块右列4个4x4子块的MV，每个元素=[mvX, mvY, refIdx]或null
    // mvTopRow[mbX*4+0..3]: 上邻居宏块底行4个4x4子块的MV，每个元素=[mvX, mvY, refIdx]或null
    // 补充：上述缓存用于规范 8.4.1.3 的 MVP（中值预测）推导，按 4x4 子块粒度保存
    public $mvLeftCol = [];
    // 上邻行各 4x4 子块的 MV 缓存
    public $mvTopRow = [];
    // 左上邻块的 MV 缓存（null 表示不可用）
    public $mvTopLeft = null;
    // 当前帧按宏块计的宽度（pic_width_in_mbs）
    public $picWidthInMbs = 0;
    public $lastMbWasSkip = false; // 上一个宏块是否为P_Skip
    // 运动搜索子进程客户端（懒加载，见 warmupMotionWorkers）
    public $motionWorkerClient = null;
    // 并行 worker 数量
    public int $motionWorkers = 4;
    // 运动搜索半径（像素）
    public int $motionRange = 32;
    // 快速编码开关（同时收紧运动范围并启用帧内剪枝）
    public bool $fastMotion = false;
    /** 静止宏块提前跳过精搜（量化死区内残差归零，不影响正确性）；B 帧在主进程内联跑 ME 时同样生效 */
    public bool $earlySkip = true;
    /** I4x4 帧内模式剪枝：只评估常用 4 种 (DC/Vertical/Horizontal/DiagonalDownLeft)，跳过 5-8 角模式 */
    public bool $fastIntraPrune = false;
    // 运动搜索子进程返回的结果（宏块下标 => 预测结果），由流水线装配阶段消费
    public array $motionWorkerResults = [];

    /**
     * 预热运动估计子进程：在首批 P 帧到来之前完成 PHP 冷启动与建链，
     * 避免把启动开销计入首帧等待（仅多进程 worker 入口调用）。
     */
    public function setFastMotion(bool $enabled = true): void
    {
        // 记录快速编码开关
        $this->fastMotion = $enabled;
        if ($enabled) {
            // 收紧运动搜索半径到 8 像素（在速度与命中率间折中）
            $this->motionRange = min($this->motionRange, 8);
            // 快速编码 = fastMotion + fastIntraPrune 联合生效：composer 包对外只暴露一个开关，
            // 内部同时收缩运动范围到 8px + 帧内跳过 5-8 角模式，保证速度-画质平衡的甜点。
            $this->fastIntraPrune = true;
        }
    }

    /**
     * 预热运动估计子进程：首次调用时创建 MotionWorkerClient 并建立全部连接。
     */
    public function warmupMotionWorkers(): void
    {
        // 仅在启用帧间预测时才有运动搜索的意义
        if ($this->enableInter) {
            // ??= 懒加载客户端；connectAll() 会拉起/连接全部 worker 子进程
            ($this->motionWorkerClient ??= new \Xiaosongshu\Flv2mp4\Codec\Encode\MotionWorkerClient(workers: $this->motionWorkers))->connectAll();
        }
    }

}