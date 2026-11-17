<?php

namespace Xiaosongshu\Flv2mp4\Codec\Encode;

/**
 * @purpose 运动模块进程管理辅助函数
 * @author yanglong
 * @time 2026年9月18日11:00:03
 *
 * 说明：本类运行在「运动搜索子进程」中，仅承担两件事：
 * 1) 复用与主进程一致的常量/量化表（避免子进程重复计算、保证两端数值一致）；
 * 2) 通过 prepare() 把主进程下发的任务数组转换成子进程可执行的宏块预测参数。
 * 真正的宏块预测/变换逻辑来自以下三个 trait（与主进程共用同一份实现）。
 */
final class MotionWorkerHelper
{
    // 亮度 6 抽头插值滤波器系数（规范 8.4.2.2.1 的 1/4 像素亮度插值，系数为 1,-5,20,20,-5,1）
    private const INTERP_TAP0 = 1;
    // 第 2 个滤波器系数
    private const INTERP_TAP1 = -5;
    // 第 3 个滤波器系数
    private const INTERP_TAP2 = 20;
    // 第 4 个滤波器系数
    private const INTERP_TAP3 = 20;
    // 第 5 个滤波器系数
    private const INTERP_TAP4 = -5;
    // 第 6 个滤波器系数（滤波器关于中心对称）
    private const INTERP_TAP5 = 1;
    // 引入运动矢量预测、整数变换、帧间预测三个 trait，使子进程与主进程具备相同的预测实现
    use MotionTrait, TransformTrait, InterPredTrait;
    // 反量化系数初始表：按 qp%6 取行、按 4x4 位置类别取列（规范表 8-15 levelScale4x4 的两档系数 10/13/16 等）
    private const DEQUANT4_COEFF_INIT = [[10,13,16],[11,14,18],[13,16,20],[14,18,23],[16,20,25],[18,23,29]];
    // 色度 QP 映射表（规范表 8-15 的 QPc 推导，直接复用 H264Encoder 的定义保证一致）
    private const CHROMA_QP_TABLE = \Xiaosongshu\Flv2mp4\Codec\H264Encoder::CHROMA_QP_TABLE;
    // 量化乘法因子表（规范 8.5.8 正向量化的 MF，复用主进程定义）
    private const QUANT_MF = \Xiaosongshu\Flv2mp4\Codec\H264Encoder::QUANT_MF;
    // 帧间亮度 DC 量化相关的修正因子表（复用主进程定义）
    private const QUANT_INTER_FF = \Xiaosongshu\Flv2mp4\Codec\H264Encoder::QUANT_INTER_FF;
    // 4x4 之字形扫描顺序（规范 8.5.6 的 up-right 对角线扫描，用于残差系数重排）
    private const ZIGZAG_SCAN_4X4 = \Xiaosongshu\Flv2mp4\Codec\H264Encoder::ZIGZAG_SCAN_4X4;
    // 当前处理帧的实际宽度（像素）
    public int $width;
    // 当前处理帧的实际高度（像素）
    public int $height;
    // 按 16x16 宏块对齐后的宽度（像素）
    public int $mbAlignedWidth;
    // 按 16x16 宏块对齐后的高度（像素）
    public int $mbAlignedHeight;
    // 量化参数 QP（亮度）
    public int $qp;
    /** chroma_qp_index_offset：与 H264Encoder 保持同名同默认值，InterPredTrait 读取 */
    public int $chromaQpIndexOffset = 0;
    // 反量化 4x4 表，结构为 [色度分量 0..5][QP 0..51][位置 0..15]
    public array $dequant4Table = [];
    // 参考帧像素缓存（由子进程按分片任务填充/共享），null 表示尚未就绪
    public $refInts = null;
    /** early-skip 开关：静止宏块提前跳过精搜（量化死区内残差归零，不影响正确性） */
    public bool $earlySkip = true;
    /** T5：inter 8x8DCT 双路决策开关（batch 头标志注入，镜像主进程 encoder->inter8x8Enabled） */
    public bool $inter8x8Enabled = false;
    // 进程内共享的反量化表缓存（static 以便同一子进程的多个实例复用，避免重复构建 6*52*16 个元素）
    private static ?array $sharedDequantTable = null;

    /**
     * 构造函数：保存几何/量化参数，并惰性构建反量化表。
     *
     * @param int    $width  帧宽（像素）
     * @param int    $height 帧高（像素）
     * @param int    $aw     宏块对齐宽度（像素，16 的倍数）
     * @param int    $ah     宏块对齐高度（像素，16 的倍数）
     * @param int    $qp     亮度 QP
     * @param string $refY   参考帧 Y 分量像素数据
     * @param string $refU   参考帧 U 分量像素数据
     * @param string $refV   参考帧 V 分量像素数据
     */
    public function __construct(int $width, int $height, int $aw, int $ah, int $qp, private string $refY, private string $refU, private string $refV)
    {
        // 记录实际宽高
        $this->width = $width;
        // 记录实际高度
        $this->height = $height;
        // 记录宏块对齐宽度
        $this->mbAlignedWidth = $aw;
        // 记录宏块对齐高度
        $this->mbAlignedHeight = $ah;
        // 记录亮度 QP
        $this->qp = $qp;
        // 仅首次实例化时构建反量化表，后续实例直接共享
        if (self::$sharedDequantTable === null) {
            // 4x4 变换系数位置类别：0/1/2 分别对应规范表 8-15 的不同量化步长档位
            $positionClass = [0,1,0,1,1,2,1,2,0,1,0,1,1,2,1,2];
            // 初始化 [6][52][16] 的三维表：6 个 QP 余数档、52 个 QP、16 个系数位置
            $table = array_fill(0, 6, array_fill(0, 52, array_fill(0, 16, 0)));
            // 外层遍历 QP 余数档 i（0..5，对应 levelScale4x4 的 6 列）
            for ($i = 0; $i < 6; $i++) for ($q = 0; $q < 52; $q++) {
                // 规范 8.5.12 反量化中的右移量：levelShift = qp/6 + 2（此处按整数 QP 直接展开到查表）
                $shift = intdiv($q, 6) + 2;
                // QP 对 6 取余决定使用哪一列 levelScale 系数
                $index = $q % 6;
                // 遍历 16 个 4x4 系数位置，按位置类别选取系数后放大 16 倍并左移
                for ($x = 0; $x < 16; $x++) $table[$i][$q][$x] = (self::DEQUANT4_COEFF_INIT[$index][$positionClass[$x]] * 16) << $shift;
            }
            // 存入静态缓存，供后续实例复用
            self::$sharedDequantTable = $table;
        }
        // 引用共享表（PHP 数组写时复制，仅读不会产生额外内存开销）
        $this->dequant4Table = self::$sharedDequantTable;
    }

    /**
     * 将主进程下发的一条运动搜索任务转换为宏块预测所需的参数并执行。
     * 任务数组下标含义：0=mbX、1=mbY、2=预测模式/参考索引、3=运动矢量候选、4/5=可选附加信息。
     *
     * @param array $job 主进程通过管道/MotionWorkerProtocol 下发的任务
     * @return array 预测结果（由 preparePMacroblock 返回，交回主进程）
     */
    public function prepare(array $job): array
    {
        // 直接转调 trait 中的 preparePMacroblock，参考帧数据取自构造时保存的三分量
        return $this->preparePMacroblock($job[0], $job[1], $job[2], $job[4] ?? '', $job[5] ?? '', $this->refY, $this->refU, $this->refV, $job[3]);
    }
}