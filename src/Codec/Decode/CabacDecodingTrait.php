<?php

namespace Xiaosongshu\Flv2mp4\Codec\Decode;

/**
 * CABAC 算术解码（main/high profile）。
 *
 * 严格对照参考实现：
 *  - 算术解码器初始化（消费 b0/b1/b2）
 *  - 内联解码 / refill / refill2 / bypass / terminate
 *  - ctx 初始化、宏块层语法、残差
 *
 * 适用范围：仅 H.264/AVC main、high profile 的 entropy_coding_mode_flag=1 情形，
 * 4:2:0 8bit 采样（ChromaArrayType=1）。文件内所有语义与语法元素编号均按
 * ITU-T H.264 条款 7.3（语法）、8.3（slice 数据语义）、8.4（宏块层语义）、
 * 9.3（CABAC 熵解码过程）组织。
 *
 * 术语约定（本 trait 内注释统一使用）：
 *  - ctxIdx：CABAC 上下文模型索引（条款 9.3.1.2，由 ctxIdxOffset + ctxIdxInc 得到）；
 *  - ctxIdxOffset / ctxIdxBlockCatOffset：语法元素或残差块类别（ctxBlockCat）的上下文基址；
 *  - ctxIdxInc：上下文增量，由邻居条件（condTermFlagA/B）推导；
 *  - range / offset（代码中对应 $cabacRange / $cabacLow）：算术解码器的区间与偏移寄存器；
 *  - MPS/LPS：最可能/最不可能符号；state 最低位存放 MPS（state 内含 MPS 位）；
 *  - mbPartIdx / subMbPartIdx：宏块分区号 / 子宏块分区号（P/B 片相关）；
 *  - n（scan8 块号）：8x8 分组序的 4x4 块编号，与 raster 块号经 SCAN8/N2R 互转；
 *  - cat：残差块类别（ctxBlockCat，条款 9.3.2.2 表 9-43）。
 *
 * 结构分节（按文件顺序）：
 *  1) 引擎：初始化、refill、解码 1 bit、bypass、terminate（条款 9.3.1.2 / 9.3.1.3）；
 *  2) 上下文初始化：由 SliceQPY 与 init 表推导 1024 个上下文状态（条款 9.3.1.1）；
 *  3) 邻居辅助：左/上 MB、scan8 cache 填充与写回（条款 6.4.3、6.4.11.1）；
 *  4) 宏块层语法原语：mb_type、intra 预测模式、CBP、QP（条款 7.3.5）；
 *  5) 残差：显著/最后/电平三段式（条款 9.3.2.2、9.3.3）；
 *  6) I 片宏块编排（I_PCM 亦在此）；
 *  7) P/B 片宏块：skip、mb_type、sub_mb_type、ref_idx、mvd、运动补偿与残差。
 *
 * 32 位无符号运算统一用 & 0xFFFFFFFF 仿真；PHP >> 为算术下移，掩码后数值恒 < 2^31，
 * 因此 >>31 即逻辑移位。
 *
 * 数值仿真要点：C 参考实现中的 int32 回绕（例如 low -= range<<17 后的符号判断）
 * 在本文件里一律先做 & 0xFFFFFFFF，再用 “>= 0x80000000 表示负” 的方式复用
 * 有符号比较语义；注释中出现 “有符号比较” 即指该惯用法。
 *
 * 状态不变量（调试时优先检查）：
 *  1) 每次解码/重归一化后 cabacRange 应落在 [0x100, 0x1FF]（初始为 0x1FE）；
 *     若出现 < 0x100，说明上一次 renorm 未执行，后续 rangeTabLPS 查表会越界；
 *  2) cabacLow 的低 16 位为 0 时必须立即 refill，否则 offset 窗口错位；
 *  3) cabacPtr 可以超前读取（越界按 0 填充），但不能落后于已解码语法；
 *  4) 每进入一个宏块，cabFillCaches 必须早于任何 cabGet（尤其 CBF/ref/mvd）；
 *  5) mb_type 决定 intra/inter 后需调用 cabFixUnavailNz 把哨兵 -1 转成对应默认值。
 *
 * 线程/实例约定：所有 CAB_* 常量与查表数组为 static（只读），其余状态均为实例属性，
 * 因此一个解码器实例同一时间只能解码一个 slice，跨 slice 由 cabacSliceBegin 重置。
 */
trait CabacDecodingTrait
{
    use CabacTablesTrait;

    // MB 类型标志
    // 这些标志写进 picMbType（每 MB 一项），供后续 MB 的邻居上下文判断使用；
    // 它们并不等同于 H.264 语法中的 mb_type 数值，只是内部位标志。
    private static int $CAB_FLG_I4    = 0x00000001;  // MB_TYPE_INTRA4x4
    private static int $CAB_FLG_I16   = 0x00000002;  // MB_TYPE_INTRA16x16
    private static int $CAB_FLG_PCM   = 0x00000004;  // MB_TYPE_INTRA_PCM
    private static int $CAB_FLG_DCT8  = 0x01000000;  // MB_TYPE_8x8DCT

    // 当前宏块解码期状态
    // cabMbX/cabMbY：当前 MB 在图像内的宏块列/行号（非像素坐标）。
    private int $cabMbX = 0;
    private int $cabMbY = 0;
    // cabMbIdx：raster 序宏块号 = cabMbY * picWidthInMbs + cabMbX（条款 6.4.1）。
    private int $cabMbIdx = 0;
    /** slice 序号（cabacStart 每次 +1；调试仪表用，区分跨 slice 的 cabMbIdx 重置） */
    private int $cabSliceIdx = -1;
    // cabQp：当前 MB 的 QPY（已含 mb_qp_delta 累积，条款 7.4.5）。
    private int $cabQp = 0;
    // cabLastQpDiff：上一 MB 的 mb_qp_delta；作为 mb_qp_delta ctxIdxInc（0/1）的输入。
    private int $cabLastQpDiff = 0;
    /** @var int[] 本 MB 24 个 4x4 块非零计数（raster：0-15 亮度，16-19 Cb，20-23 Cr），MB 结束时由 scan8 cache 汇总，仅供 deblock/legacy */
    private array $cabNz = [];
    // cabCbp：当前 MB 的 coded_block_pattern（低 4 位亮度，高 2 位色度，条款 7.4.5）。
    private int $cabCbp = 0;
    // cabBinSeq：已解码 bin 计数（调试/仪表用途，不参与语法推导）。
    private int $cabBinSeq = 0;
    // cabRawTag：最近一次残差解码的调试标签（形如 "cat2n5"），不参与逻辑。
    private string $cabRawTag = '';
    /** 当前 MB 是否 intra（mb_type 读出后由 cabFixUnavailNz 设置；不可用邻居的 nz/cbp 默认值依赖它） */
    private bool $cabMbIsIntra = true;
    /** 本 MB 渐进 ref 覆盖（ref_cache 语义）：[list][4x4 块号] = ref；未设块按 -1 处理 */
    private array $cabMbRefOv = [[], []];
    /** 本 MB 渐进 direct 标记（direct_cache 语义）：4x4 块号 => 1，B 片 ref ctx 排除用 */
    private array $cabMbDirOv = [];

    // ---- scan8 cache 几何（scan8 / fill/write_back）----
    // 工作 cache 以 scan8 的“8 宽、每行 8 单元”几何排布：
    //   当前 MB 的 4x4 块位于 [12..15][20..23][28..31][36..39]（亮度）与
    //   [52..55][60..63]（Cb）、[92..95][100..103]（Cr）；左侧邻居在列 -1，上方邻居在行 -1。
    /** @var int[] 工作 cache，8 宽共 120 单元，下标 = scan8 位置 */
    private array $cabNzCache = [];
    /** @var int[] 同几何的 intra4x4 预测模式 cache（-1=不可用，2=DC） */
    private array $cabI4Cache = [];
    /** @var int[] 每 MB 持久 nz 存储（48 布局，见 write_back_non_zero_count） */
    private array $cabNzStore = [];
    /** @var int[] 每 MB 持久 i4 模式（16，n 序），仅 I4 MB 有效 */
    private array $cabI4Store = [];

    // I 片不可用邻居默认值（fill_decode_caches）：nz=64，cbp=0x7CF
    // 说明：不可用邻居按 IS_INTRA 取默认值；此处给出 intra 情形的常量，
    // inter 情形的默认值见 cabFixUnavailNz / cabNeighborCbp 中的 0 / 0x00F。
    private static int $CAB_NZ_UNAVAIL = 64;
    private static int $CAB_CBP_INTRA  = 0x7CF;

    // 8x8 残差的显著/最后系数上下文映射（帧模式，cat=5；条款 9.3.2.2 表 9-44 附近）。
    // 下标为扫描位置 last/k（0..63），值为相对基址的 ctxIdxInc 偏移。
    private static array $SIG8_FRAME = [
        0, 1, 2, 3, 4, 5, 5, 4, 4, 3, 3, 4, 4, 4, 5, 5,
        4, 4, 4, 4, 3, 3, 6, 7, 7, 7, 8, 9, 10, 9, 8, 7,
        7, 6, 11, 12, 13, 11, 6, 7, 8, 9, 14, 10, 9, 8, 6, 11,
        12, 13, 11, 6, 9, 14, 10, 9, 11, 12, 13, 11, 14, 10, 12,
    ];
    private static array $LAST8_FRAME = [
        0, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1,
        2, 2, 2, 2, 2, 2, 2, 2, 2, 2, 2, 2, 2, 2, 2, 2,
        3, 3, 3, 3, 3, 3, 3, 3, 4, 4, 4, 4, 4, 4, 4, 4,
        5, 5, 5, 5, 6, 6, 6, 6, 7, 7, 7, 7, 8, 8, 8,
    ];
    // node ctx：level=1 映射 / level>1 映射 / 两种迁移
    // 说明（条款 9.3.3.1.1 的 2bit 状态机近似）：
    //   LV1_CTX[nodeCtx] 用于读“absLevel 是否 >0”的 ctxIdxInc；
    //   GT1_CTX[nodeCtx] 用于读“absLevel 是否 >1”的 ctxIdxInc；
    //   TRANS_L1 / TRANS_GT1 为读完后 nodeCtx 的迁移目标。
    private static array $LV1_CTX  = [1, 2, 3, 4, 0, 0, 0, 0];
    private static array $GT1_CTX  = [5, 5, 5, 5, 6, 7, 8, 9];
    private static array $TRANS_L1 = [1, 2, 3, 3, 4, 5, 6, 7];
    private static array $TRANS_GT1 = [4, 4, 4, 4, 5, 6, 7, 7];
    // 帧模式 cat -> 基址
    // cat 含义：0=I16 亮度 DC，1=I16 亮度 AC，2=亮度 AC（I4/inter），3=色度 DC，
    //           4=色度 AC，5=8x8 亮度，9=PCM 后的“无残差”占位（实际不读系数）。
    // 每个基址对应条款 9.3.2.2 表 9-43 的 ctxIdxBlockCatOffset 组合结果。
    private static array $SIG_BASE  = [0 => 105, 1 => 120, 2 => 134, 3 => 149, 4 => 152, 5 => 402, 9 => 660];
    private static array $LAST_BASE = [0 => 166, 1 => 181, 2 => 195, 3 => 210, 4 => 213, 5 => 417, 9 => 690];
    private static array $LEV_BASE  = [0 => 227, 1 => 237, 2 => 247, 3 => 257, 4 => 266, 5 => 426, 9 => 708];
    private static array $CBF_BASE  = [0 => 85, 1 => 89, 2 => 93, 3 => 97, 4 => 101, 9 => 1016];
    // 8x8 zigzag_direct
    // 8x8 块的 zigzag 扫描（条款 8.5.6 的 zig-zag 顺序），值 = 8x8 系数位置。
    private static array $ZZ8 = [
        0, 1, 8, 16, 9, 2, 3, 10, 17, 24, 32, 25, 18, 11, 4, 5,
        12, 19, 26, 33, 40, 48, 41, 34, 27, 20, 13, 6, 7, 14, 21, 28,
        35, 42, 49, 56, 57, 50, 43, 36, 29, 22, 15, 23, 30, 37, 44, 51,
        58, 59, 52, 45, 38, 31, 39, 46, 53, 60, 61, 54, 47, 55, 62, 63,
    ];

    // scan8[n]：n 是 8x8 分组序块号（非 raster）。420 用到前 36 项
    // 映射关系（条款 6.4.3 / 6.4.11.1 的块地址推导）：
    //   n 0..15 亮度（按 8x8 分组：0..3 左上组，4..7 右上，8..11 左下，12..15 右下）；
    //   n 16..19 Cb 左上/右上/左下/右下（后 4 项 20..23 为 Cb 第二行 8x8 组，420 不用）；
    //   n 32..35 为 Cr 左上组。值 = cache 几何下标。
    private static array $SCAN8 = [
        12, 13, 20, 21,  14, 15, 22, 23,  28, 29, 36, 37,  30, 31, 38, 39, // 0..15 luma
        52, 53, 60, 61,  54, 55, 62, 63,                                    // 16..23 Cb
        68, 69, 76, 77,  70, 71, 78, 79,                                    // 24..31
        92, 93, 100, 101,                                                   // 32..35 Cr
    ];
    /** 亮度 n(8x8分组序) -> raster 4x4 块号 r=by*4+bx */
    // 二者数值相同，但语义方向相反：N2R 用于“解码/扫描序 -> 像素 raster”，
    // R2N 用于“重建 raster 循环 -> 取对应 n 序预测模式/系数块”。
    private static array $N2R = [0, 1, 4, 5, 2, 3, 6, 7, 8, 9, 12, 13, 10, 11, 14, 15];
    /** raster -> n */
    private static array $R2N = [0, 1, 4, 5, 2, 3, 6, 7, 8, 9, 12, 13, 10, 11, 14, 15];

    // ---- 持久 nz 存储（48 布局）与当前 MB / 邻居 cache 单元的对应（write_back/fill_decode_caches）----
    // 48 布局：0..15 亮度（n 序），16..31 Cb（两组 4x4x2），32..47 Cr；
    // 该布局只用于跨 MB 邻居查询，不直接作为语法元素输出。
    /** 当前 MB 矩形单元（luma 16 + Cb 8 + Cr 8），MB 开始清零 */
    private static array $CELL_RESET = [
        12, 13, 14, 15, 20, 21, 22, 23, 28, 29, 30, 31, 36, 37, 38, 39,
        52, 53, 54, 55, 60, 61, 62, 63,
        92, 93, 94, 95, 100, 101, 102, 103,
    ];
    /** nz 存储 s0..15 对应的 luma cache 单元 */
    // 偏移 0..15：n 序亮度块（与 SCAN8[0..15] 同序），写回/读取都按此逐项搬运。
    private static array $CELL_LUMA_ST = [12, 13, 14, 15, 20, 21, 22, 23, 28, 29, 30, 31, 36, 37, 38, 39];
    // 偏移 16..23：Cb 的 8 个 4x4 单元（420 下每 MB 4 个块 + 2 行 8x8 组内填充位）。
    private static array $CELL_CB_ST   = [52, 53, 54, 55, 60, 61, 62, 63];
    // 偏移 32..39：Cr 的 8 个 4x4 单元。
    private static array $CELL_CR_ST   = [92, 93, 94, 95, 100, 101, 102, 103];

    // ============================================================
    // 引擎
    // 覆盖条款 9.3.1.2 / 9.3.1.3：初始化（b0/b1/b2）、refill（常规与带移位）、
    // decodeDecision（rangeTabLPS + transIdx）、bypass、terminate。
    // 状态说明：cabacRange 为 9 位区间长度，cabacLow 为 32 位 offset，
    // cabacPtr 指向下一个待装填字节；三者必须始终保持规范定义的同步关系。
    // ============================================================

    /**
     * 在 slice header 结束（已字节对齐）后启动 CABAC。
     * 始终采用“奇对齐”规范初始化：消费 b0/b1/b2 三字节。
     *
     * 对应对条款 9.3.1.2（初始化过程）：slice 数据起始处读入 b0,b1,b2，
     * 并按起始字节地址的奇偶选择初始化公式；本方法只做入口与计数器维护。
     *
     * @param string $rbsp       整个 NAL 的 RBSP（去掉 emulation prevention 后的数据）
     * @param int    $byteOffset slice_data 的起始字节偏移（相对 RBSP 起点，已字节对齐）
     * @return void
     */
    private function cabacStart(string $rbsp, int $byteOffset): void
    {
        // 每进入一个 slice 递增序号（仅用于调试观察跨 slice 重置）。
        $this->cabSliceIdx++;
        // 保存本 slice 的 RBSP 引用与总长度（越界读取按 0 填充）。
        $this->cabacData = $rbsp;
        $this->cabacEnd = strlen($rbsp);
        // 真正执行算术解码器寄存器初始化。
        $this->cabacInitAt($byteOffset);
    }

    /**
     * 按字节偏移初始化 range/offset（条款 9.3.1.2 的初始化公式）。
     *
     * 此处实现为：若起始偏移为偶数（对应 32 位字内偶字节起始），
     * 只消费 b0/b1，offset 取 (b0<<18)|(b1<<10)|(1<<9)；
     * 若为奇数，则额外消费 b2，offset 末尾带 |2 以及 b2<<2。
     * 两种情况都把 range 置为 0x1FE（510）。
     *
     * 注意：偶数起点与奇数起点的区分来自参考实现的“指针奇偶”惯用法，
     * 本文件用 slice_data 的字节偏移奇偶复现；若偏移错位一个字节，
     * 后续所有 bin 都会错位，因此 cabacSliceBegin 的头部长度必须精确。
     *
     * @param int $byteOffset slice_data 起始字节偏移
     * @return void
     */
    private function cabacInitAt(int $byteOffset): void
    {
        // 读取三个初始化字节（越界按 0）。
        $b0 = $this->cabacAbsByte($byteOffset);
        $b1 = $this->cabacAbsByte($byteOffset + 1);
        $b2 = $this->cabacAbsByte($byteOffset + 2);
        // 解码器初始化：读完 b0/b1 后按指针奇偶选分支。
        // gb.buffer 为偶对齐，故由 slice-data 字节偏移奇偶决定。
        $even = ($byteOffset & 1) === 0;
        if ($even) {
            // 偶起点：指针停在 b2（下次 refill 从 b2 读取）。
            $this->cabacPtr = $byteOffset + 2;
            // 9 位精度装入 b0/b1，附加位 1<<9 为初始化哨兵（条款 9.3.1.2 的固定位）。
            $this->cabacLow = (($b0 << 18) | ($b1 << 10) | (1 << 9)) & 0xFFFFFFFF;
        } else {
            // 奇起点：指针停在 b3（本次已消费 b2）。
            $this->cabacPtr = $byteOffset + 3;
            // 追加 b2<<2 与末位 2（低位哨兵用于随后的 renorm 判定）。
            $this->cabacLow = (($b0 << 18) | ($b1 << 10) | ($b2 << 2) | 2) & 0xFFFFFFFF;
        }
        // 初始区间长度 510（9 位）。
        $this->cabacRange = 0x1FE;
    }

    /**
     * 读取 RBSP 指定绝对偏移的一个字节，越界返回 0。
     *
     * 对应 C 参考实现中在输入缓冲尾部预留填充（AV_INPUT_BUFFER_PADDING）的语义；
     * 解码器在片尾附近可能超前读取 1~2 字节，故必须越界安全。
     *
     * @param int $abs 绝对字节偏移（可为负——调用侧做回退时可能出现）
     * @return int 0..255
     */
    private function cabacAbsByte(int $abs): int
    {
        // 越界统一返回 0（零填充），不抛异常。
        if ($abs < 0 || $abs >= $this->cabacEnd) {
            return 0; // 零填充（AV_INPUT_BUFFER_PADDING）
        }
        // 返回原始字节值。
        return ord($this->cabacData[$abs]);
    }

    /**
     * 常规 refill（条款 9.3.1.3 的 “low 低 16 位为 0 时” 情况）。
     *
     * 读取 ptr 处两字节，按 <<9 / <<1 拼成 16 位增量后加到 low，
     * 再减去 0xFFFF（相当于把 low 的 16 位窗口向前推进），ptr 前进 2。
     *
     * @return void
     */
    private function cabacRefill(): void
    {
        $p = $this->cabacPtr;
        // 两字节装填：(b[p]<<9) + (b[p+1]<<1)，再减 0xFFFF 完成窗口平移。
        $this->cabacLow =
            (($this->cabacLow + ($this->cabacAbsByte($p) << 9) + ($this->cabacAbsByte($p + 1) << 1)) - 0xFFFF)
            & 0xFFFFFFFF;
        // 前移两字节。
        $this->cabacPtr = $p + 2;
    }

    /**
     * “带移位”的 refill（条款 9.3.1.3 的 else 分支：low 低 16 位非 0）。
     *
     * 该过程需要把新读入的两字节增量左移 i 位再加到 low；
     * i 由 low 的最低非零位位置决定：i = ctz(low) - 16。
     * 本实现先用 (low ^ (low-1)) >> 15 的查表法一次算出 i，
     * 表查不到（值 >= 512）时退化为逐位右移求 ctz 的循环。
     *
     * @return void
     */
    private function cabacRefill2(): void
    {
        $low = $this->cabacLow;
        // tmp = low & -low 的低位扩散形式：(low ^ (low-1)) 得到“最低 1 位及其以下全 1”。
        $tmp = ($low ^ ($low - 1)) & 0xFFFFFFFF;
        // 取高位定位：tmp>>15 落在 0..511 时可查 512 项 normShift 表。
        $v = $tmp >> 15;
        if ($v < 512) {
            // i = 7 - normShift[tmp>>15]，等价于 ctz(low) - 16。
            $i = 7 - self::$cabacNormShift[$v];
        } else {
            // 理论不可达（512 项表）；与快速 clz 分支 i=ctz(low)-16 等价
            $u = $low;
            $z = 0;
            // 逐位右移统计末尾零个数（最多 32 次）。
            while (($u & 1) === 0 && $z < 32) {
                $u >>= 1;
                $z++;
            }
            $i = $z - 16;
        }
        $p = $this->cabacPtr;
        // 新两字节增量（已去掉 0xFFFF 偏置），随后按 i 左移。
        $x = -0xFFFF + ($this->cabacAbsByte($p) << 9) + ($this->cabacAbsByte($p + 1) << 1);
        $this->cabacLow = ($low + ($x << $i)) & 0xFFFFFFFF;
        $this->cabacPtr = $p + 2;
    }

    /**
     * 解码一个上下文编码的 bin（条款 9.3.1.2 / 9.3.1.3 的 decodeDecision）。
     *
     * 步骤：查 rangeTabLPS → 由 (range<<17) 与 low 的比较选出 MPS/LPS →
     * 更新 low/range → 用 transIdxLPS/MPS 迁移上下文状态 → 重归一化。
     * 返回解出的 bin（0/1）。
     *
     * 实现要点：$s 的低 7 位为 2bit 状态机量化后的 state，最低位携带 MPS；
     * LPS 分支通过 $s ^= -1 得到 -(s+1)，从而用同一张表查“镜像段”完成迁移。
     *
     * @param int $ctxIdx 上下文模型索引
     * @return int 0 或 1
     */
    private function cabGet(int $ctxIdx): int
    {
        // state 低 7 位为概率状态，最低位为 MPS（state 与 MPS 合存）。
        $s = $this->cabacStates[$ctxIdx];
        $range = $this->cabacRange;
        $low = $this->cabacLow;

        // rangeTabLPS 查表：索引 = 2*pStateIdxLPS 的量化区间 (range&0xC0) + state。
        $rangeLps = self::$cabacLpsRange[2 * ($range & 0xC0) + $s];
        // 先假设解出 MPS：range 减去 LPS 区间。
        $range -= $rangeLps;
        // int32 回绕语义：lps_mask = ((range<<17) - low) >> 31，等价于无符号 (range<<17) < low 时取 -1
        $d = (($range << 17) - $low) & 0xFFFFFFFF;
        $mask = $d >= 0x80000000 ? -1 : 0;

        // LPS 分支（mask=-1）时 low 减去 range<<17，range 换为 LPS 区间。
        // 注意：PHP 中 -1 的按位与等价于全 1 掩码，0 掩码则该式为 0。
        $low -= ($range << 17) & $mask;
        $range += ($rangeLps - $range) & $mask;

        // s ^= lps_mask → LPS 时 s 变为 -(s+1)，mlps 索引 128+s 即 127-s（表前 128 项镜像段）
        // 这里用同一张表完成 transIdxMPS（MPS 分支）与 transIdxLPS（LPS 分支）迁移。
        $s ^= $mask;
        $this->cabacStates[$ctxIdx] = self::$cabacMlps[128 + $s];
        // 解出的 bin：MPS 分支下为 state 最低位（MPS），LPS 分支下为取反后的奇偶（即 1-MPS）。
        $bit = $s & 1;

        // 重归一化：查 normShift 表得到左移位数（条款 9.3.1.3 的 renorm）。
        $shift = self::$cabacNormShift[$range];
        $range <<= $shift;
        $low = ($low << $shift) & 0xFFFFFFFF;

        // low 低 16 位为 0 时需要 refill2（带 i 移位的装填）；否则只保存寄存器。
        if (($low & 0xFFFF) === 0) {
            $this->cabacLow = $low;
            $this->cabacRange = $range;
            $this->cabacRefill2();
        } else {
            $this->cabacLow = $low;
            $this->cabacRange = $range;
        }
        return $bit;
    }

    /**
     * 解码一个 bypass bin（bypass 编码，条款 9.3.1.3 的 decodeBypass）。
     *
     * bypass 固定使用“半区间”划分：low/range 各左移 1 位后，
     * 比较 (有符号) low 与 range<<17，小则 0，否则减区间返回 1。
     *
     * bypass 不更新任何上下文状态，因此可用于 mvd 后缀、符号位等高频语法。
     *
     * @return int 0 或 1
     */
    private function cabBypass(): int
    {
        // 区间倍乘：low 左移一位，并保留低 16 位作为 refill 判定。
        $low = ($this->cabacLow << 1) & 0xFFFFFFFF;
        $this->cabacLow = $low;
        if (($low & 0xFFFF) === 0) {
            // 低 16 位耗尽，做常规 refill 并取回更新后的 low。
            $this->cabacRefill();
            $low = $this->cabacLow;
        }
        // bypass 的子区间上界（有符号 32 位意义下）。
        $r = $this->cabacRange << 17;
        // if((int32)low < (int32)range) return 0; 必须按有符号比较
        $d = ($low - $r) & 0xFFFFFFFF;
        if ($d >= 0x80000000) {
            // 有符号 low < r：bit=0，low 保持
            return 0;
        }
        // 否则落在上子区间：low 减去区间，返回 1。
        $this->cabacLow = $d;
        return 1;
    }

    /**
     * bypass 编码的“符号位”解码（decodeBypassSign）。
     *
     * 与 cabBypass 使用同一区间划分，但同时把 LPS 掩码作用到输入值上，
     * 直接返回带符号结果：mask=0 → 返回 $val；mask=-1 → 返回 -$val。
     *
     * @param int $val 待定符号的绝对值（通常为 -abs，调用侧约定）
     * @return int 带符号值
     */
    private function cabBypassSign(int $val): int
    {
        // 与 cabBypass 相同的倍乘与 refill 判定。
        $low = ($this->cabacLow << 1) & 0xFFFFFFFF;
        $this->cabacLow = $low;
        if (($low & 0xFFFF) === 0) {
            $this->cabacRefill();
            $low = $this->cabacLow;
        }
        $r = $this->cabacRange << 17;
        // low-=range; mask=(int32)low>>31; 必须按有符号 32 位
        $d = ($low - $r) & 0xFFFFFFFF;
        $mask = $d >= 0x80000000 ? -1 : 0;
        // mask=0 时 low 不变；mask=-1 时把区间加回（等价于减法后再加回），并保持回绕。
        $this->cabacLow = ($d + ($r & $mask)) & 0xFFFFFFFF;
        // 按 mask 取正/负：mask=0 → val；mask=-1 → -val。
        $ret = ($val ^ $mask) - $mask;
        return $ret;
    }

    /** @return int 0=未结束，非零=已到片尾（调用方一般只判真假） */
    // 解码 terminate bin（end_of_slice_flag 或 I_PCM 判定，条款 9.3.1.4）：
    //   range 先减 2，再按有符号比较 (low < range<<17)；
    //   成立表示“未结束”，做一次条件 renorm（range<0x100 时左移 1 位）后返回 0；
    //   不成立表示“终止”，返回 1（调用方据此结束 slice 或进入 PCM）。
    private function cabTerminate(): int
    {
        // 终止判定使用 range-2 的缩短区间。
        $range = $this->cabacRange - 2;
        // if((int32)low < (int32)(range<<17)) —— 有符号比较
        $d = ($this->cabacLow - ($range << 17)) & 0xFFFFFFFF;
        if ($d >= 0x80000000) {
            // renorm once
            // 仅在 range 仍小于 0x100 时左移一位，使区间回到 9 位精度范围。
            $shift = ($range < 0x100) ? 1 : 0;
            $range <<= $shift;
            $this->cabacLow = ($this->cabacLow << $shift) & 0xFFFFFFFF;
            $this->cabacRange = $range;
            // 低 16 位为 0 时补充字节流。
            if (($this->cabacLow & 0xFFFF) === 0) {
                $this->cabacRefill();
            }
            return 0;
        }
        return 1;
    }

    // ============================================================
    // 上下文初始化
    // 由 SliceQPY 与 init 表（I / PB0 / PB1 / PB2，按 cabac_init_idc 选择）
    // 推导 1024 个上下文的初始状态；状态的最低 bit 即 valMPS。
    // ============================================================

    /**
     * @param int $sliceTypeNos 0=P 1=B 2=I
     */
    // 初始化 1024 个上下文模型的状态（条款 9.3.1.1 的 ctx 初始化过程）。
    // 每个上下文初始状态由 initValue（m,n）与 SliceQPY 计算：
    //   preCtxState = Clip3(1, 126, ((m * SliceQPY) >> 4) + n) 的变体；
    // 本文件沿用参考实现的整数写法：pre = 2*(((m*qp)>>4)+n) - 127，
    // 负数取 -pre-1（等价于取绝对值再减 1，即把 MPS=1 的情形折叠到低位为 1 的状态），
    // 超过 124 则截到 124 | (pre&1)。结果 state 的最低位即 MPS。
    // @param int $qp            SliceQPY（条款 7.4.3 的 slice_qp_delta 累积结果）
    // @param int $sliceTypeNos  0=P 1=B 2=I（本文件内部编号）
    // @return void
    private function cabacInitStates(int $qp, int $sliceTypeNos): void
    {
        // SliceQPY 强制落在 0..51（条款 7.4.3 / 8.2.1）。
        $qp = max(0, min(51, $qp));
        $states = [];
        if ($sliceTypeNos === 2) {
            // I 片使用独立的 init 表。
            $init = self::$cabacCtxInitI;
        } else {
            // P/B 片按 cabac_init_idc（slice header 语法元素）选择三张表之一。
            $init = $this->cabacInitIdc === 0
                ? self::$cabacCtxInitPB0
                : ($this->cabacInitIdc === 1 ? self::$cabacCtxInitPB1 : self::$cabacCtxInitPB2);
        }
        // 1024 个上下文覆盖全语法元素（条款 9.3.1.1 表 9-12 起）。
        for ($i = 0; $i < 1024; $i++) {
            // initValue 拆成斜率 m 与偏移 n 两个分量。
            $m = $init[$i][0];
            $n = $init[$i][1];
            // 严格对照参考实现：pre ^= pre>>31（负数 -> -pre-1；正数保持，不 clamp）
            // 即 2*(((m*qp)>>4)+n)-127，随后按符号折叠。
            $pre = 2 * ((($m * $qp) >> 4) + $n) - 127;
            if ($pre < 0) {
                // 负数折叠为 -pre-1（保留奇偶性，最低位变为 MPS 标志）。
                $pre = -$pre - 1;
            }
            if ($pre > 124) {
                // 上限截断：124 或 125，同样保留最低位。
                $pre = 124 + ($pre & 1);
            }
            $states[$i] = $pre; // state 含 MPS（最低位）
        }
        // 一次性写入本 slice 的上下文状态数组。
        $this->cabacStates = $states;
    }

    /** 片开始 */
    // 启动算术引擎、初始化上下文、重置每片持久 cache（条款 7.3.2.9 / 9.3.1）。
    // @param string $rbsp           RBSP 数据
    // @param int    $headerEndByte  slice header 结束后的字节偏移（已对齐）
    // @param int    $sliceQp        SliceQPY
    // @param int    $sliceTypeNos   0=P 1=B 2=I
    // @return void
    private function cabacSliceBegin(string $rbsp, int $headerEndByte, int $sliceQp, int $sliceTypeNos): void
    {
        // 1) 引擎初始化（消费 b0/b1/b2，置 range=0x1FE）。
        $this->cabacStart($rbsp, $headerEndByte);
        // 2) 1024 个上下文状态初始化。
        $this->cabacInitStates($sliceQp, $sliceTypeNos);
        // 3) 记录当前 QP 与 mb_qp_delta 依赖的“上一差值”。
        $this->cabQp = max(0, min(51, $sliceQp));
        $this->cabLastQpDiff = 0;
        $this->cabBinSeq = 0;

        // 每片重置 scan8 持久存储（write_back_non_zero_count / write_back_intra_pred_mode）
        // 说明：48 项/MB 对应 luma16+Cb16+Cr16 的 nz 布局；i4 mode 每 MB 16 项，默认 2（DC）。
        $mbCount = $this->picWidthInMbs * $this->picHeightInMbs;
        $this->cabNzStore = array_fill(0, $mbCount * 48, 0);
        $this->cabI4Store = array_fill(0, $mbCount * 16, 2);
        $this->cabNzCache = array_fill(0, 120, 0);
        $this->cabI4Cache = array_fill(0, 120, -1);
    }

    // ============================================================
    // 邻居辅助（全帧 pic* 数组）
    // 所有跨 MB 的上下文推导都经过这里；不可用邻居的默认值与写回时机
    // 直接决定 CABAC 是否与编码端同步，故每个入口都需保持“先填后用”。
    // ============================================================

    /**
     * 左邻 MB 的 raster 索引（条款 6.4.3：左邻为 MbAddrA）。
     *
     * @return int 存在返回 cabMbIdx-1；当前 MB 已在最左列时返回 -1（不可用）
     */
    private function cabLeftMbIdx(): int
    {
        // 仅当不在第 0 列时左邻可用。
        return $this->cabMbX > 0 ? $this->cabMbIdx - 1 : -1;
    }

    /**
     * 上邻 MB 的 raster 索引（条款 6.4.3：上邻为 MbAddrB）。
     *
     * @return int 存在返回 cabMbIdx - picWidthInMbs；首行时返回 -1（不可用）
     */
    private function cabTopMbIdx(): int
    {
        // 仅当不在第 0 行时上邻可用。
        return $this->cabMbY > 0 ? $this->cabMbIdx - $this->picWidthInMbs : -1;
    }

    /**
     * 进入一个宏块：更新坐标/索引、重置本 MB 状态并填充邻居 cache。
     *
     * @param int $mbX 宏块列号
     * @param int $mbY 宏块行号
     * @return void
     */
    private function cabMbBegin(int $mbX, int $mbY): void
    {
        // 记录当前 MB 的列/行与 raster 索引（条款 6.4.1 的地址推导）。
        $this->cabMbX = $mbX;
        $this->cabMbY = $mbY;
        $this->cabMbIdx = $mbY * $this->picWidthInMbs + $mbX;
        // 本 MB 渐进 ref/direct 覆盖（ref_cache/direct_cache 在 MB 解码内渐进填写）
        $this->cabMbRefOv = [[], []];
        $this->cabMbDirOv = [];
        // 24 个 4x4 块的 nz 计数与 cbp 初值。
        $this->cabNz = array_fill(0, 24, 0);
        $this->cabCbp = 0;
        // 从持久存储填充本 MB 的邻居 cache（此时尚不知 intra/inter，用 -1 哨兵）。
        $this->cabFillCaches();
    }

    /** 本 MB 覆盖：写入一块区域的渐进 ref（ref 循环 fill_rectangle） */
    // 语义等价于参考实现对 ref_cache 的矩形填充；区域以 4x4 块为单位。
    // @param int $list 参考列表 0/1
    // @param int $bx   起始块列（0..3）
    // @param int $by   起始块行（0..3）
    // @param int $bw   宽（4x4 块数）
    // @param int $bh   高（4x4 块数）
    // @param int $ref  参考帧索引
    // @return void
    private function cabOvRefBox(int $list, int $bx, int $by, int $bw, int $bh, int $ref): void
    {
        // 逐行逐列写入 [list][raster 块号]。
        for ($y = $by; $y < $by + $bh; $y++) {
            $row = $y * 4;
            for ($x = $bx; $x < $bx + $bw; $x++) {
                $this->cabMbRefOv[$list][$row + $x] = $ref;
            }
        }
    }

    /** 4x4 块号 -> 8x8 象限号（0=TL,1=TR,2=BL,3=BR） */
    private static function blkQuad(int $b): int
    {
        // 取块列的最高位作为象限列、块行最高位作为象限行：
        // (b&3)>>1 得到列象限，(b>>2)&2 得到行象限，合并成 0..3。
        return (($b & 3) >> 1) | (($b >> 2) & 2);
    }

    /**
     * 按 fill_decode_caches 从持久存储填充本 MB 的 nz / i4 mode
     * 工作 cache（当前矩形 + 上/左邻居边）。I 片：不可用邻居 nz=64、mode=-1、cbp=0x7CF。
     *
     * 填充顺序固定为：复位当前矩形 → 上边（4..7 等）→ 左边（11/19…）→ i4 模式边。
     * 只有“上/左”两个方向，因为 CABAC 的块上下文仅取 A/B 邻居（条款 9.3.2.3）。
     *
     * @return void
     */
    private function cabFillCaches(): void
    {
        $nz = &$this->cabNzCache;
        $i4 = &$this->cabI4Cache;

        // 当前矩形复位
        // 先把本 MB 覆盖的 32 个单元清零（亮度 16 + 色度各 8），
        // 再把 16 个亮度 i4 模式置为“不可用”(-1)，后续按需写入。
        foreach (self::$CELL_RESET as $cell) {
            $nz[$cell] = 0;
        }
        for ($n = 0; $n < 16; $n++) {
            $i4[self::$SCAN8[$n]] = -1;
        }

        $l = $this->cabLeftMbIdx();
        $t = $this->cabTopMbIdx();
        $nzDef = self::$CAB_NZ_UNAVAIL;

        // ---- nz 上边 ----
        // 上邻：取上 MB 持久 nz 存储的底边 12 个单元（4 亮度 + Cb 4 + Cr 4），
        // 映射到当前 MB cache 的“上一行”单元（4..7 / 44..47 / 84..87）。
        if ($t >= 0) {
            $s = $t * 48;
            $nz[4] = $this->cabNzStore[$s + 12]; $nz[5] = $this->cabNzStore[$s + 13];
            $nz[6] = $this->cabNzStore[$s + 14]; $nz[7] = $this->cabNzStore[$s + 15];
            $nz[44] = $this->cabNzStore[$s + 20]; $nz[45] = $this->cabNzStore[$s + 21];
            $nz[46] = $this->cabNzStore[$s + 22]; $nz[47] = $this->cabNzStore[$s + 23];
            $nz[84] = $this->cabNzStore[$s + 36]; $nz[85] = $this->cabNzStore[$s + 37];
            $nz[86] = $this->cabNzStore[$s + 38]; $nz[87] = $this->cabNzStore[$s + 39];
        } else {
            // 不可用 top：填哨兵 -1，mb_type 读出后经 cabFixUnavailNz 转为
            // fill_decode_caches 默认值（
            // CABAC && !IS_INTRA(mb_type) ? 0 : 64）
            foreach ([4, 5, 6, 7, 44, 45, 46, 47, 84, 85, 86, 87] as $c) {
                $nz[$c] = -1;
            }
        }
        // ---- nz 左边 ----
        // 左邻同理：取左 MB 持久 nz 的右缘 8 个单元（亮度 4 + Cb 2 + Cr 2），
        // 写入 cache 的“左侧一列”单元（11/19/27/35/51/59/91/99）。
        if ($l >= 0) {
            $s = $l * 48;
            $nz[11] = $this->cabNzStore[$s + 3];  $nz[19] = $this->cabNzStore[$s + 7];
            $nz[27] = $this->cabNzStore[$s + 11]; $nz[35] = $this->cabNzStore[$s + 15];
            $nz[51] = $this->cabNzStore[$s + 17]; $nz[59] = $this->cabNzStore[$s + 21];
            $nz[91] = $this->cabNzStore[$s + 33]; $nz[99] = $this->cabNzStore[$s + 37];
        } else {
            // 不可用 left：同上哨兵 -1
            foreach ([11, 19, 27, 35, 51, 59, 91, 99] as $c) {
                $nz[$c] = -1;
            }
        }

        // ---- i4 mode 上边（单元 4..7 ← 上 MB 底边 n10,n11,n14,n15）----
        // 仅当上邻确为 I4 宏块时才拷贝其真实模式；否则按“已存在但非 I4 → 2(DC)”
        // 或“不存在 → -1(不可用)”填默认值（供 cabPredModeN 取 min 使用）。
        if ($t >= 0 && ($this->picMbType[$t] & self::$CAB_FLG_I4)) {
            $b = $t * 16;
            $i4[4] = $this->cabI4Store[$b + 10]; $i4[5] = $this->cabI4Store[$b + 11];
            $i4[6] = $this->cabI4Store[$b + 14]; $i4[7] = $this->cabI4Store[$b + 15];
        } else {
            $m = $t >= 0 ? 2 : -1;
            $i4[4] = $i4[5] = $i4[6] = $i4[7] = $m;
        }
        // ---- i4 mode 左边（11,19,27,35 ← 左 MB 右缘 n5,n7,n13,n15）----
        // 与上边相同：非 I4 邻居给 2(DC)，缺失给 -1。
        if ($l >= 0 && ($this->picMbType[$l] & self::$CAB_FLG_I4)) {
            $b = $l * 16;
            $i4[11] = $this->cabI4Store[$b + 5];  $i4[19] = $this->cabI4Store[$b + 7];
            $i4[27] = $this->cabI4Store[$b + 13]; $i4[35] = $this->cabI4Store[$b + 15];
        } else {
            $m = $l >= 0 ? 2 : -1;
            $i4[11] = $i4[19] = $i4[27] = $i4[35] = $m;
        }
    }

    /**
     * mb_type 读出后调用：把不可用邻居的 nz 哨兵(-1)按当前 MB 类型转为默认值。
     * fill_decode_caches：
     *   CABAC && !IS_INTRA(mb_type) ? 0 : 64
     * cabMbBegin 在 mb_type 读取前执行（fill 时尚不知 intra/inter），故哨兵延迟到此转换。
     * skip/PCM 无 CBF 读取，未调用亦无影响（哨兵在 ctx 计算中表现为 0）。
     *
     * @param bool $isIntra 当前 MB 是否 intra
     * @return void
     */
    private function cabFixUnavailNz(bool $isIntra): void
    {
        $this->cabMbIsIntra = $isIntra;
        // intra -> 64（表示“约等于不可用的高 nz”，使邻居 CBF ctx 趋向 1）；
        // inter -> 0。
        $def = $isIntra ? 64 : 0;
        $nz = &$this->cabNzCache;
        // 只转换上/左两条边上的哨兵单元，当前 MB 自身的块保持 0。
        foreach ([4, 5, 6, 7, 44, 45, 46, 47, 84, 85, 86, 87, 11, 19, 27, 35, 51, 59, 91, 99] as $c) {
            if ($nz[$c] === -1) {
                $nz[$c] = $def;
            }
        }
    }

    /**
     * 左/上 MB 的 cbp 上下文（fill_decode_caches）。
     * 帧 MBAFF（非场）时 left_block={0,1,2,3,...}、leftL==leftBot：
     *   top_cbp  = top 存在 ? cbp_table[top]（原始，不掩码） : (intra?0x7CF:0x00F)
     *   left_cbp = left 存在 ? (c&0x7F0) | (c&0x02) | (c&0x08) : (intra?0x7CF:0x00F)
     *   即保留原 bit1 与 bit3（(c>>(0&~1))&2 与 ((c>>(2&~1))&2)<<2），丢弃 bit0/bit2。
     * 不可用默认值按当前 MB 类型（IS_INTRA ? 0x7CF : 0x00F）：
     * cbp_luma/chroma 的 ctx 两者等价，但 luma-DC(bit8)/chroma-DC(bit6,7) 的 CBF ctx 不等价。
     *
     * 返回值为“修剪后”的 cbp：左邻保留 bit1/bit3，上邻保留原始位，
     * 这样后续 CBP 与 DC 的 CBF ctx 推导可以直接按位取用。
     *
     * @return array{0:int,1:int} [leftCbp,topCbp]
     */
    private function cabNeighborCbp(): array
    {
        $l = $this->cabLeftMbIdx();
        $t = $this->cabTopMbIdx();
        // 不可用邻居的 cbp 默认值随当前 MB 类型变化（条款 9.3.3.2 的邻居推导默认）。
        $unavail = $this->cabMbIsIntra ? self::$CAB_CBP_INTRA : 0x00F;
        if ($t >= 0) {
            $topCbp = $this->picCbp[$t];
        } else {
            $topCbp = $unavail;
        }
        if ($l >= 0) {
            $c = $this->picCbp[$l];
            $leftCbp = ($c & 0x7F0) | ($c & 0x02) | ($c & 0x08);
        } else {
            $leftCbp = $unavail;
        }
        return [$leftCbp, $topCbp];
    }

    /**
     * 非 DC 块 CBF 上下文（get_cabac_cbf_ctx），n 为 scan8 块号：
     * 亮度 AC cat2 n=0..15；I16-AC cat1 n=0..15；色度-AC cat4 n=16+16c+i。
     */
    private function cabCbfAcCtx(int $cat, int $n): int
    {
        $cell = self::$SCAN8[$n];
        // cache 几何中“左邻”为 cell-1，“上邻”为 cell-8。
        $nza = $this->cabNzCache[$cell - 1];
        $nzb = $this->cabNzCache[$cell - 8];
        // ctxIdxInc = condTermFlagA + 2*condTermFlagB，基址由 cat 决定（条款 9.3.3.2）。
        return self::$CBF_BASE[$cat] + ($nza > 0 ? 1 : 0) + 2 * ($nzb > 0 ? 1 : 0);
    }

    /** intra4x4 预测模式（pred_intra_mode：min(左,上)，不可用(<0)->DC=2），n 为 scan8 块号 */
    // 对应条款 8.3.1.1 的推导：两个邻居任一不可用即视作 -1，
    // min 后仍为负时取 DC_PRED(2)；否则取较小者作为当前块的预测模式。
    // @param int $n scan8 块号（0..15）
    // @return int 0..8 的预测模式
    private function cabPredModeN(int $n): int
    {
        $cell = self::$SCAN8[$n];
        $a = $this->cabI4Cache[$cell - 1];
        $b = $this->cabI4Cache[$cell - 8];
        $m = min($a, $b);
        return $m < 0 ? 2 : $m;
    }

    /** MB 结束：把当前矩形写回持久 nz(48)/i4(n序) 存储（write_back_non_zero_count / write_back_intra_pred_mode） */
    // @param bool  $isI4   当前 MB 是否 I4（决定是否写 i4 模式存储）
    // @param array $modesN 16 项 i4 预测模式（n 序），仅 $isI4 为真时使用
    // @return void
    private function cabStoreWriteBack(bool $isI4, array $modesN): void
    {
        $mbIdx = $this->cabMbIdx;
        $nz = &$this->cabNzCache;
        // 本 MB 的 48 项持久区偏移。
        $s = $mbIdx * 48;
        // 亮度 16 项（n 序）→ 偏移 0..15。
        foreach (self::$CELL_LUMA_ST as $k => $cell) {
            $this->cabNzStore[$s + $k] = $nz[$cell];
        }
        foreach (self::$CELL_CB_ST as $k => $cell) {
            // Cb 的 8 个单元写入偏移 16..23（其中 420 只用到前 4 个）。
            $this->cabNzStore[$s + 16 + $k] = $nz[$cell];
        }
        foreach (self::$CELL_CR_ST as $k => $cell) {
            // Cr 的 8 个单元写入偏移 32..39。
            $this->cabNzStore[$s + 32 + $k] = $nz[$cell];
        }
        if ($isI4) {
            // i4 预测模式按 n 序持久化（16 项/MB），供跨 MB 邻居读取。
            $b = $mbIdx * 16;
            for ($n = 0; $n < 16; $n++) {
                $this->cabI4Store[$b + $n] = $modesN[$n];
            }
        }
    }

    // ============================================================
    // 宏块层语法原语
    // 对应条款 7.3.5 的语法元素与 9.3.3 的上下文推导：
    // mb_type / intra 预测模式 / CBP / mb_qp_delta / chroma QP。
    // 每个方法只读语法，不触碰像素重建（重建在 I 片/P/B 编排层完成）。
    // ============================================================

    /**
     * intra mb_type（decode_cabac_intra_mb_type）。
     * 返回 ['k'=>'i4'|'i16'|'pcm', 'mode'=>int, 'luma15'=>bool, 'chromaCbp'=>int]
     *
     * 语法分两支（条款 9.3.3.3）：
     *  - intra_slice=1（I 片）：ctxIdxInc 由左/上邻居是否 I16/PCM 决定，基址 $state=ctxBase+2；
     *  - intra_slice=0（P/B 片内嵌）：固定用 ctxBase，$state=ctxBase。
     * 之后：terminate → I_PCM；否则读 luma15、chromaCbp（分两级）与 Intra16x16 预测模式。
     *
     * @param int  $ctxBase    该片类型的 intra mb_type ctxIdxOffset（I=3, P=17, B=32）
     * @param bool $intraSlice 是否处于 I 片
     * @return array{k:string,mode?:int,luma15?:bool,chromaCbp?:int}
     */
    private function cabReadIntraMbType(int $ctxBase, bool $intraSlice): array
    {
        if ($intraSlice) {
            // I 片：邻居条件累积 ctxIdxInc（0..2）。
            $ctx = 0;
            $l = $this->cabLeftMbIdx();
            $t = $this->cabTopMbIdx();
            if ($l >= 0 && ($this->picMbType[$l] & (self::$CAB_FLG_I16 | self::$CAB_FLG_PCM))) {
                $ctx++;
            }
            if ($t >= 0 && ($this->picMbType[$t] & (self::$CAB_FLG_I16 | self::$CAB_FLG_PCM))) {
                $ctx++;
            }
            // bin=0 → Intra4x4（I_NxN）。
            if ($this->cabGet($ctxBase + $ctx) === 0) {
                return ['k' => 'i4'];
            }
            // 后续上下文从 ctxBase+2 开始（ctxBase 与 +1 已被占用）。
            $state = $ctxBase + 2;
        } else {
            // P/B 片内嵌 intra：只有单一上下文。
            if ($this->cabGet($ctxBase) === 0) {
                return ['k' => 'i4'];
            }
            // decode_cabac_intra_mb_type：state+=2 仅在 intra_slice 分支内，
            // 非 intra 片保持 state=ctx_base（后续读 state[1..3+2*is] 即 ctx_base+1..3）
            $state = $ctxBase;
        }

        // terminate=1 → I_PCM（此 MB 的样值随后直接按字节拷贝）。
        if ($this->cabTerminate()) {
            return ['k' => 'pcm'];
        }

        // $is 用于在 “I 片” 与 “非 I 片” 两套相邻 ctx 之间切换。
        $is = $intraSlice ? 1 : 0;
        // luma15：CodedBlockPatternLuma 是否全 1（15）。
        $luma15 = (bool)$this->cabGet($state + 1);
        $chromaCbp = 0;
        // 先读色度 CBP 是否非零，非零再读一位区分 1/2。
        if ($this->cabGet($state + 2)) {
            $chromaCbp = 1 + $this->cabGet($state + 2 + $is);
        }
        // Intra16x16 预测模式：2 个 bin 组成 0..3。
        $p1 = $this->cabGet($state + 3 + $is);
        $p0 = $this->cabGet($state + 3 + 2 * $is);
        $mode = 2 * $p1 + $p0;

        // 返回内部结构（k 区分三类 intra）。
        return ['k' => 'i16', 'mode' => $mode, 'luma15' => $luma15, 'chromaCbp' => $chromaCbp];
    }

    /**
     * intra4x4 预测模式（pred_intra_mode：min(左,上)，不可用(<0)->DC=2），n 为 scan8 块号
     *
     * 对应条款 8.3.1.1 的 Intra4x4PredMode 邻域推导：
     * 左邻块模式与上邻块模式取较小者；若邻居不可用（-1），返回 DC_PRED=2。
     * 读出 bin=1 时直接返回 pred（prev_intra4x4_pred_mode_flag=1）；
     * 否则把 3 个 bin 组成 rem 值，并按“跳过 pred 编号”的规则映射。
     *
     * @param int $pred 邻居推导出的预测模式（cabPredModeN 的返回值）
     * @return int 预测模式 0..8
     */
    private function cabReadIntra4Mode(int $pred): int
    {
        // prev_intra4x4_pred_mode_flag：1 表示沿用邻居推导值 pred，无需再读 3 个位。
        if ($this->cabGet(68)) {
            return $pred;
        }
        // rem_intra4x4_pred_mode 的 3 个 bin 按 LSB-first 组合成 0..7。
        $mode = $this->cabGet(69) + 2 * $this->cabGet(69) + 4 * $this->cabGet(69);
        // 条款 8.3.1.1：rem >= pred 时模式加 1（跳过 pred 所占的编号）。
        return $mode + ($mode >= $pred ? 1 : 0);
    }

    /**
     * 读取色度帧内预测模式 chroma_pred_mode（条款 7.3.5.3 / 9.3.3.4）。
     *
     * ctxIdxInc 由左/上邻居的 chroma_pred_mode 是否为 0（DC）决定；
     * 两个 bin 依次区分 DC / V / H / Plane 四种模式。
     *
     * @return int 0=DC, 1=H(水平), 2=V(垂直), 3=Plane
     */
    private function cabReadChromaPredMode(): int
    {
        $l = $this->cabLeftMbIdx();
        $t = $this->cabTopMbIdx();
        $ctx = 0;
        // 左邻非 DC 时 ctx++。
        if ($l >= 0 && $this->picChromaPred[$l] !== 0) {
            $ctx++;
        }
        // decode_cabac_mb_chroma_pre_mode：左/上邻居各 ctx++（非 +2）
        // 上邻非 DC 时 ctx 再 ++（因此 ctx ∈ 0..2）。
        if ($t >= 0 && $this->picChromaPred[$t] !== 0) {
            $ctx++;
        }
        // 第一个 bin=0 → DC_PRED。
        if ($this->cabGet(64 + $ctx) === 0) {
            return 0;
        }
        // 第二个 bin=0 → 水平模式。
        if ($this->cabGet(67) === 0) {
            return 1;
        }
        // 第三个 bin=0 → 垂直模式；否则 Plane。
        if ($this->cabGet(67) === 0) {
            return 2;
        }
        return 3;
    }

    /**
     * 读取亮度 coded_block_pattern（条款 9.3.3.2 的 coded_block_flag 组合）。
     *
     * 4 个 bit 依次为 luma 的 4 个 8x8 分组；每个 bit 的 ctxIdxInc 由
     * 左/上邻居 cbp 的对应位以及已读出的相邻 bit 共同决定。
     * 返回值为 4 bit 亮度 CBP（bit3..0 分别对应 4 个 8x8 组）。
     *
     * @return int 0..15 的亮度 CBP
     */
    private function cabReadCbpLuma(): int
    {
        // [leftCbp, topCbp]，已按不可用默认值处理。
        [$cbpA, $cbpB] = $this->cabNeighborCbp();

        $cbp = 0;
        // bit0：左邻 bit1 为 0 → +1；上邻 bit2 为 0 → +2。
        $ctx = (($cbpA & 0x02) === 0 ? 1 : 0) + 2 * (($cbpB & 0x04) === 0 ? 1 : 0);
        $cbp += $this->cabGet(73 + $ctx);
        // bit1：自身 bit0 为 0 → +1；上邻 bit3 为 0 → +2。
        $ctx = (($cbp & 0x01) === 0 ? 1 : 0) + 2 * (($cbpB & 0x08) === 0 ? 1 : 0);
        $cbp += $this->cabGet(73 + $ctx) << 1;
        // bit2：左邻 bit3 为 0 → +1；自身 bit0 为 0 → +2。
        $ctx = (($cbpA & 0x08) === 0 ? 1 : 0) + 2 * (($cbp & 0x01) === 0 ? 1 : 0);
        $cbp += $this->cabGet(73 + $ctx) << 2;
        // bit3：自身 bit2 为 0 → +1；自身 bit1 为 0 → +2。
        $ctx = (($cbp & 0x04) === 0 ? 1 : 0) + 2 * (($cbp & 0x02) === 0 ? 1 : 0);
        $cbp += $this->cabGet(73 + $ctx) << 3;
        return $cbp;
    }

    /**
     * 读取色度 coded_block_pattern（条款 9.3.3.2 / 7.4.5）。
     *
     * 先读 1 个 bin 判断是否为 0；非零时再读 1 个 bin 区分 1 与 2。
     * ctxIdxInc 由左/上邻居的色度 cbp（各 2 bit，值 0/1/2）决定。
     * 返回 0/1/2，调用方再左移 4 位与亮度 CBP 合并。
     *
     * @return int 0,1,2 的色度 CBP
     */
    private function cabReadCbpChroma(): int
    {
        [$lCbp, $tCbp] = $this->cabNeighborCbp();
        // 取左/上邻居的色度 2 bit 字段（bit4..5）。
        $cbpA = ($lCbp >> 4) & 0x03;
        $cbpB = ($tCbp >> 4) & 0x03;

        // 第一 bin：两邻居均非零的个数构成 ctx（0..3）。
        $ctx = ($cbpA > 0 ? 1 : 0) + ($cbpB > 0 ? 2 : 0);
        if ($this->cabGet(77 + $ctx) === 0) {
            return 0;
        }
        // 第二 bin：邻居是否恰为 2 决定 ctx 的 4/5/6。
        $ctx = 4 + ($cbpA === 2 ? 1 : 0) + ($cbpB === 2 ? 2 : 0);
        return 1 + $this->cabGet(77 + $ctx);
    }

    /** 读取 mb_qp_delta，更新 cabQp / cabLastQpDiff（decode_cabac_mb_dqp） */
    // 条款 9.3.3.6 / 7.4.5：mb_qp_delta 采用一元前缀 + 符号映射，
    // ctxIdxInc 依赖上一 MB 的 delta 是否为 0；累加后需在 0..51 上环绕。
    // 注意：cabLastQpDiff 保存“带符号的 delta”，下一个 MB 的 ctx 只判断它是否为 0。
    private function cabReadQpDelta(): void
    {
        // 第一个 bin=0 表示 delta=0（ctx 由上一 delta 是否为 0 选择 60/61）。
        if ($this->cabGet(60 + ($this->cabLastQpDiff !== 0 ? 1 : 0)) === 0) {
            $this->cabLastQpDiff = 0;
            return;
        }
        // 非零：abs 从 1 开始做一元累积（最多 102 次，防越界）。
        $val = 1;
        $ctx = 2;
        while ($this->cabGet(60 + $ctx)) {
            // 前缀后续 bin 改用 ctx=3（条件 “|delta| > 1”）。
            $ctx = 3;
            $val++;
            if ($val > 102) {
                break;
            }
        }
        // 奇偶映射：1→1, 2→-1, 3→2, 4→-2 ...（条款 7.4.5 的映射表）。
        if (($val & 1) !== 0) {
            $val = ($val + 1) >> 1;
        } else {
            $val = -(($val + 1) >> 1);
        }
        $this->cabLastQpDiff = $val;
        // QPY 累加并按 0..51 环绕（条款 7.4.5）。
        $qp = $this->cabQp + $val;
        if ($qp < 0) {
            $qp += 52;
        } elseif ($qp > 51) {
            $qp -= 52;
        }
        $this->cabQp = $qp;
    }

    /**
     * 由 QPY 推导色度 QP（条款 8.5.8 / 表 8-15）。
     *
     * 索引 QPY + chroma_qp_index_offset 先 clip 到 0..51，再查 CHROMA_QP_TABLE。
     * 此处实现为：只用当前 PPS/slice 的单一 chroma_qp_index_offset（4:2:0 共用）。
     *
     * @return int QPC（色度量化参数）
     */
    private function cabChromaQp(): int
    {
        // 先求色度 QP 索引，再映射为实际 QPc 值。
        $idx = max(0, min(51, $this->cabQp + $this->chromaQpIndexOffset));
        return self::CHROMA_QP_TABLE[$idx];
    }

    // ============================================================
    // 残差（decode_cabac_residual_internal）
    // 残差语法分三段：significant_coeff_flag / last_significant_coeff_flag、
    // coeff_abs_level_minus1 与符号位。本段按 ctxBlockCat 复用同一核心，
    // 仅由基址表（SIG/LAST/LEV/CBF_BASE）与扫描表参数化。
    // ============================================================

    /**
     * 残差核心。
     *
     * @param int      $cat      cat（决定 sig/last/level 基址）
     * @param array    $scanJ    长度 maxCoeff，扫描位 k -> 输出存储位 j
     * @param int      $maxCoeff 16/15/4/64
     * @param array|null $qmul   非 DC：按存储位 j 的反量化乘数；DC 传 null（输出原始 level）
     * @param bool     $is8x8
     * @return array{0:array,1:int} [j=>level, coeffCount]
     *
     * 说明：$scanJ 决定“扫描序号 -> 输出存储位”的映射，4x4/AC 用 zigzag，
     * 8x8 用转置后的 zigzag；返回的 map 键空间与 $scanJ 的值空间一致。
     * 本方法不修改 nz cache，nz 由调用方（cabDecodeAcBlock 等）写入。
     */
    private function cabResidualCore(
        int $cat,
        array $scanJ,
        int $maxCoeff,
        ?array $qmul,
        bool $is8x8
    ): array {
        $sigBase = self::$SIG_BASE[$cat];
        $lastBase = self::$LAST_BASE[$cat];
        $levBase = self::$LEV_BASE[$cat];

        // ---- 第一阶段：significant_coeff_flag / last_significant_coeff_flag ----
        // 按扫描序逐个位置判定“是否非零”，直到遇到 last=1 的位置为止；
        // $index 记录非零位置的扫描序号 k（升序追加），供第二阶段逆序读电平。
        $index = [];
        $coeffCount = 0;
        if ($is8x8) {
            // 8x8 块（cat=5）：扫描位置 0..62，最后一位置 63 特殊处理。
            $terminated = false;
            for ($last = 0; $last < 63; $last++) {
                if ($this->cabGet($sigBase + self::$SIG8_FRAME[$last])) {
                    // 该位置非零：记录扫描序号。
                    $index[$coeffCount++] = $last;
                    // 再读 last_significant_coeff_flag：1 表示此位置为最后一个非零系数。
                    if ($this->cabGet($lastBase + self::$LAST8_FRAME[$last])) {
                        $last = 64;
                        $terminated = true;
                        break;
                    }
                }
            }
            // 循环正常退出（last==63）时最后一位必显著；break（last=64）不补
            if (!$terminated) {
                $index[$coeffCount++] = 63;
            }
        } else {
            // 4x4 / DC 块：扫描位置 0..maxCoeff-2，最后一位置单独处理。
            $terminated = false;
            for ($last = 0; $last < $maxCoeff - 1; $last++) {
                if ($this->cabGet($sigBase + $last)) {
                    $index[$coeffCount++] = $last;
                    if ($this->cabGet($lastBase + $last)) {
                        $last = $maxCoeff;
                        $terminated = true;
                        break;
                    }
                }
            }
            // 循环语义：break（last 置 max）不补；正常退出（last==max-1）补最后一位
            if (!$terminated) {
                $index[$coeffCount++] = $maxCoeff - 1;
            }
        }

        // ---- 第二阶段：coeff_abs_level_minus1 与符号位（逆扫描序） ----
        // 逆序解码是因为电平的上下文依赖“已解码的高频系数”。
        $out = [];
        $nodeCtx = 0;
        while ($coeffCount > 0) {
            // 取下一个待解码位置（从最高频往低频走）。
            $k = $index[--$coeffCount];
            // 映射到输出存储位（4x4/8x8 的 zigzag 或转置布局）。
            $j = $scanJ[$k];

            // 先读“absLevel 是否 > 0”——注意第一阶段已判非零，这里实际读的是
            // coeff_abs_level_greater1_flag 的前导位（|level|==1 与 >1 的分叉）。
            $ctx = $levBase + self::$LV1_CTX[$nodeCtx];
            if ($this->cabGet($ctx) === 0) {
                // |level|=1：状态机按 TRANS_L1 迁移，符号走 bypass 解码。
                $nodeCtx = self::$TRANS_L1[$nodeCtx];
                $sv = $this->cabBypassSign(-1);
                if ($qmul === null) {
                    // DC 路径：输出原始电平（反量化由调用方的 DC 专用 IDCT 完成）。
                    $out[$j] = $sv;
                } else {
                    // 非 DC：按乘数反量化并带 +32 舍入（条款 8.5.12 的缩放）。
                    $out[$j] = (($sv * $qmul[$j]) + 32) >> 6;
                }
            } else {
                // |level|>=2：继续读 greater1 前缀。
                $ctx = $levBase + self::$GT1_CTX[$nodeCtx];
                $nodeCtx = self::$TRANS_GT1[$nodeCtx];
                $coeffAbs = 2;
                // greater1 前缀：最多累加到 14（ctx 固定，条款 9.3.3.1.2）。
                while ($coeffAbs < 15 && $this->cabGet($ctx)) {
                    $coeffAbs++;
                }
                if ($coeffAbs >= 15) {
                    // 达到转义值 15：改用 Golomb-Rice（bypass）读后缀。
                    $e = 0;
                    // 一元前缀（bypass）：累计后缀长度（上限 23，防越界）。
                    while ($this->cabBypass() && $e < 23) {
                        $e++;
                    }
                    // 反转读取后缀位，逐位拼出绝对值：abs = 1; abs = 2*abs + bit。
                    $coeffAbs = 1;
                    while ($e-- > 0) {
                        $coeffAbs += $coeffAbs + $this->cabBypass();
                    }
                    // 加上转义基值 14（大于 14 的部分）。
                    $coeffAbs += 14;
                }
                // 符号位：bypass 解码并按 mask 取正负。
                $sv = $this->cabBypassSign(-$coeffAbs);
                if ($qmul === null) {
                    $out[$j] = $sv;
                } else {
                    $out[$j] = (($sv * $qmul[$j]) + 32) >> 6;
                }
            }
        }
        // 返回：存储位 -> 系数值 的映射，以及非零系数个数（供 nz 缓存）。
        return [$out, count($out)];
    }

    /**
     * 4x4 非 DC 残差块（亮度 AC cat2 / I16-AC cat1 / 色度-AC cat4）。
     * $n 为 scan8 块号（亮度 0..15；色度 16+16c+i），CBF 邻居与 nz 写回均走 nzCache[scan8[n]]。
     * 输出 16 位 raster 系数。
     * @return array{0:int[],1:int} [16 raster 系数, nz]
     */
    private function cabDecodeAcBlock(int $cat, int $n, int $list, int $qp, bool $zz15): array
    {
        $cell = self::$SCAN8[$n];
        // 先读 coded_block_flag（CBF）：0 表示整块无残差，直接清零 nz 并返回全 0。
        if ($this->cabGet($this->cabCbfAcCtx($cat, $n)) === 0) {
            $this->cabNzCache[$cell] = 0;
            return [array_fill(0, 16, 0), 0];
        }
        // 反量化乘数表：$list 选择 intra/inter 与 luma/chroma 的量化表（条款 8.5.12）。
        $qmul = $this->dequant4Table[$list][$qp];
        if ($zz15) {
            // I16-AC 与色度 AC：扫描从位置 1 开始（DC 单独传输），共 15 个系数。
            $scanJ = [];
            for ($k = 0; $k < 15; $k++) {
                $scanJ[$k] = self::ZIGZAG_SCAN_4X4[$k + 1];
            }
            $maxCoeff = 15;
        } else {
            // 普通 4x4 AC：16 个系数全扫描。
            $scanJ = self::ZIGZAG_SCAN_4X4;
            $maxCoeff = 16;
        }
        // 调试标签（不参与逻辑）。
        $this->cabRawTag = "cat{$cat}n{$n}";
        // 核心解码：返回 存储位->电平 的映射与 nz。
        [$map, $nz] = $this->cabResidualCore($cat, $scanJ, $maxCoeff, $qmul, false);
        $block = array_fill(0, 16, 0);
        foreach ($map as $j => $v) {
            $block[$j] = $v;
        }
        $this->cabNzCache[$cell] = $nz;
        return [$block, $nz];
    }

    /**
     * I16x16 亮度 DC（16 个原始 level，scan 序）。含 CBF。
     * @return int[] 16 个原始 level，键为扫描位 k（0..15）
     */
    private function cabResidualLumaDc(): array
    {
        $mbIdx = $this->cabMbIdx;
        // DC CBF ctx：cat0 idx0，nza/nzb 取左/上 cbp bit8（不可用 I 邻居 0x7CF 含 bit8）
        // 说明：亮度 DC 的 coded_block_flag 使用 cbp 的 bit8 作为邻居条件，
        // 而不是 scan8 nz cache（条款 9.3.3.2 中 cat=0 的 ctxIdxInc 推导）。
        [$lCbp, $tCbp] = $this->cabNeighborCbp();
        // 邻居 bit8 = 该邻居亮度 DC 是否有非零系数。
        $nza = ($lCbp & 0x100) ? 1 : 0;
        $nzb = ($tCbp & 0x100) ? 1 : 0;
        // 读 DC 的 coded_block_flag（cat0 基址 + condTermFlagA + 2*condTermFlagB）。
        if ($this->cabGet(self::$CBF_BASE[0] + $nza + 2 * $nzb) === 0) {
            $this->cabNzCache[self::$SCAN8[0]] = 0;
            return array_fill(0, 16, 0);
        }
        // DC 电平不做 AC 反量化（qmul=null），由调用方做 DC 专用反量化/IDCT。
        [$map] = $this->cabResidualCore(0, self::ZIGZAG_SCAN_4X4, 16, null, false);
        // map 的 key 已是 raster 位（cabResidualCore 内部 $j = scanJ[$k]），此处仅展开为定长数组
        $out = array_fill(0, 16, 0);
        foreach ($map as $k => $v) {
            $out[$k] = $v;
        }
        // 标记本 MB 的 cbp bit8：亮度 DC 存在非零系数（供右/下邻居的 ctx 使用）。
        $this->picCbp[$mbIdx] |= 0x100;
        return $out;
    }

    /** 色度 DC（4 个原始 level）。$c 0=Cb 1=Cr。含 CBF。 */
    // 残差块类别为 cat3（4 个 DC 系数）。
    // @param int $c 色度分量（0=Cb, 1=Cr）
    // @return int[] 4 个电平（扫描序 0..3）
    private function cabResidualChromaDc(int $c): array
    {
        $mbIdx = $this->cabMbIdx;
        // cbp 中 Cb/Cr 的存在位分别为 bit6/bit7。
        $bit = 6 + $c;
        // 色度 DC 的 CBF 邻居条件取 cbp 的 bit6（Cb）/ bit7（Cr）。
        [$lCbp, $tCbp] = $this->cabNeighborCbp();
        $nza = ($lCbp & (1 << $bit)) ? 1 : 0;
        $nzb = ($tCbp & (1 << $bit)) ? 1 : 0;
        if ($this->cabGet(self::$CBF_BASE[3] + $nza + 2 * $nzb) === 0) {
            // decode_cabac_residual_dc：CBF=0 时置 nzCache[scan8[n]]=0（n=16+c）
            $this->cabNzCache[self::$SCAN8[16 + $c]] = 0;
            return [0, 0, 0, 0];
        }
        [$map] = $this->cabResidualCore(3, [0, 1, 2, 3], 4, null, false);
        // 展开为固定 4 项数组（扫描序保持 0..3）。
        $out = [0, 0, 0, 0];
        foreach ($map as $k => $v) {
            $out[$k] = $v;
        }
        // 标记 cbp 的 bit6/bit7：本 MB 的该色度分量存在非零系数。
        $this->picCbp[$mbIdx] |= (1 << $bit);
        return $out;
    }

    /**
     * 8x8 亮度残差（cat5，420 无逐块 CBF，由 cbp 位决定）。
     * 输出 64 系数列主序（与 buildQmul8/idct8AddToPlane 一致）。
     * @return int[]|null 64 系数；无残差返回 null
     */
    private function cabResidualLuma8x8(int $p8, bool $intra): ?array
    {
        // scanJ[k] = TRANSPOSE8(zigzag_direct[k])（存储布局）
        // 说明：zigzag 序号 z 的行/列互换（(z>>3) | ((z&7)<<3)），
        // 因为本解码器的 8x8 系数按列主序存储，而 H.264 扫描按行主序定义。
        $scanJ = [];
        for ($k = 0; $k < 64; $k++) {
            $z = self::$ZZ8[$k];
            $scanJ[$k] = ($z >> 3) | (($z & 7) << 3);
        }
        $qmul = $this->buildQmul8($this->cabQp, $intra);
        // 8x8 残差一律按“含全部 64 个位置”解码（末位特殊处理在核心内完成）。
        [$map] = $this->cabResidualCore(5, $scanJ, 64, $qmul, true);
        $block = array_fill(0, 64, 0);
        foreach ($map as $j => $v) {
            $block[$j] = $v;
        }
        // nz：四个 4x4 块都记 coeffCount（fill_rectangle(&nz_cache[scan8[4*i8x8]],2,2,8)）
        $nz = count($map);
        $baseCell = self::$SCAN8[4 * $p8];
        $this->cabNzCache[$baseCell] = $nz;
        $this->cabNzCache[$baseCell + 1] = $nz;
        $this->cabNzCache[$baseCell + 8] = $nz;
        $this->cabNzCache[$baseCell + 9] = $nz;
        // legacy 24-raster（deblock）
        $origin = [0 => 0, 1 => 2, 2 => 8, 3 => 10][$p8];
        foreach ([[0, 0], [1, 0], [0, 1], [1, 1]] as [$dx, $dy]) {
            $this->cabNz[$origin + $dx + 4 * $dy] = $nz;
        }
        return $block;
    }

    // ============================================================
    // I 片宏块编排
    // I 片只含 I_NxN（I4 或 8x8DCT 形态）、I16x16 与 I_PCM 三类，
    // 由 intra mb_type 的解析结果分派到 cabDecodeI4x4 / cabDecodeI16x16 / cabDecodePcm。
    // ============================================================

    /**
     * 解码一个 I 片宏块（CABAC）。
     * 调用前必须已 cabacSliceBegin 且 cabMbBegin。
     *
     * @param int        $mbX     宏块列号
     * @param int        $mbY     宏块行号
     * @param array|null $preType 已由 P/B 片 mb_type 分支解出的 intra 类型；null 表示自行读取
     * @return void
     */
    private function decodeCabacIntraMb(int $mbX, int $mbY, ?array $preType = null): void
    {
        // 进入 MB：重置本 MB 状态并填充邻居 cache。
        $this->cabMbBegin($mbX, $mbY);
        $mbIdx = $this->cabMbIdx;
        // 同位帧 intra 标记（B 帧 spatial direct 的 colZero 判定依赖）
        $this->picMbType[$mbY * $this->picWidthInMbs + $mbX] |= 1;

        // I 片的 intra mb_type 使用 ctxIdxOffset=3（条款 9.3.3.3，表 9-39）。
        // $preType 非空时表示由 P/B 片的 mb_type 分支直接给出，无需再读。
        $t = $preType ?? $this->cabReadIntraMbType(3, true);
        // 内嵌 intra 的语法 mb_type 按 I 片编号（I4=0 / I16=1..24 / PCM=25）解码，
        // 去块簿记需要片类型编号：P 片整体 +5（I4=5..PCM=30），B 片整体 +23（I4=23..PCM=48）
        $this->cabDeblockTypeOffset = $this->currentSliceType === 1
            ? 23
            : (($this->currentSliceType === 0 || $this->currentSliceType === 5) ? 5 : 0);
        // intra 恒定：不可用邻居 nz 哨兵转 64（IS_INTRA -> 0x40）
        $this->cabFixUnavailNz(true);

        if ($t['k'] === 'pcm') {
            $this->cabDecodePcm();
            return;
        }

        if ($t['k'] === 'i4') {
            $this->cabDecodeI4x4($mbX, $mbY);
        } else {
            $this->cabDecodeI16x16($mbX, $mbY, $t['mode'], $t['luma15'], $t['chromaCbp']);
        }
    }

    /**
     * 邻居宏块是否使用 8x8 变换（ctxIdxInc 输入，条款 9.3.3.5）。
     *
     * 左/上邻居的 transform_size_8x8_flag 各贡献 1，返回 0..2。
     *
     * @return int ctxIdxInc 值（0/1/2）
     */
    private function cabNeighborTransformSize(): int
    {
        $n = 0;
        $l = $this->cabLeftMbIdx();
        $t = $this->cabTopMbIdx();
        // 左邻为 8x8DCT MB 时 +1。
        if ($l >= 0 && ($this->picMbType[$l] & self::$CAB_FLG_DCT8)) {
            $n++;
        }
        // 上邻为 8x8DCT MB 时再 +1。
        if ($t >= 0 && ($this->picMbType[$t] & self::$CAB_FLG_DCT8)) {
            $n++;
        }
        return $n;
    }

    /**
     * 解码 I_NxN 宏块（Intra4x4 或 8x8DCT 的 I4 形态）。
     *
     * 语法顺序（条款 7.3.5）：
     *   transform_size_8x8_flag（可选）→ 16 个 prev_intra4x4_pred_mode_flag /
     *   rem_intra4x4_pred_mode → intra_chroma_pred_mode → coded_block_pattern →
     *   mb_qp_delta → 残差 → 亮度/色度重建。
     *
     * @param int $mbX 宏块列号
     * @param int $mbY 宏块行号
     * @return void
     */
    private function cabDecodeI4x4(int $mbX, int $mbY): void
    {
        $mbIdx = $this->cabMbIdx;
        // 模式数组按 n（8x8 分组序块号）存放，与 i4Cache 写回一致
        $modes = array_fill(0, 16, 2);
        $dct8 = false;

        // transform_8x8 标志在所有 pred 模式之前读
        // ctxIdxInc 由左右/上邻居的 DCT8 状态推导（条款 9.3.3.5，ctxIdxOffset=399）。
        if ($this->transform8x8ModeFlag && $this->cabGet(399 + $this->cabNeighborTransformSize())) {
            $dct8 = true;
            // 只在每个 8x8 分组左上块 n=0,4,8,12 读模式，2x2 填充（fill_rectangle）
            foreach ([0, 4, 8, 12] as $n0) {
                // 预测模式仍按“左/上取小”推导，再读 flag/rem。
                $m = $this->cabReadIntra4Mode($this->cabPredModeN($n0));
                $cell = self::$SCAN8[$n0];
                // 8x8DCT 时一个模式覆盖该 8x8 内的 4 个 4x4 块。
                $this->cabI4Cache[$cell] = $m;
                $this->cabI4Cache[$cell + 1] = $m;
                $this->cabI4Cache[$cell + 8] = $m;
                $this->cabI4Cache[$cell + 9] = $m;
                $modes[$n0] = $m;
                $modes[$n0 + 1] = $m;
                $modes[$n0 + 2] = $m;
                $modes[$n0 + 3] = $m;
            }
        } else {
            for ($n = 0; $n < 16; $n++) {
                $m = $this->cabReadIntra4Mode($this->cabPredModeN($n));
                $modes[$n] = $m;
                $this->cabI4Cache[self::$SCAN8[$n]] = $m;
            }
        }

        // 语法顺序：intra_chroma_pred_mode → coded_block_pattern → mb_qp_delta（条款 7.3.5）。
        $chromaPredMode = $this->cabReadChromaPredMode();
        $lumaCbp = $this->cabReadCbpLuma();
        $chromaCbp = $this->cabReadCbpChroma();
        // 内部 cbp 组织：低 4 位亮度，bit4..5 色度（条款 7.4.5 的 CodedBlockPatternLuma/Chroma）。
        $cbp = $lumaCbp | ($chromaCbp << 4);
        $this->cabCbp = $cbp;
        $this->picCbp[$mbIdx] = $cbp;
        $this->picChromaPred[$mbIdx] = $chromaPredMode;

        // I4x4 仅 cbp!=0 时读 mb_qp_delta
        if ($cbp !== 0) {
            $this->cabReadQpDelta();
        } else {
            // cbp=0 时不读 delta，且“上一 delta”复位为 0（供下一 MB 的 ctx 使用）。
            $this->cabLastQpDiff = 0;
        }

        // 本 MB 使用的 QPY（后续 AC 反量化与去块簿记都用它）。
        $qp = $this->cabQp;

        // ---------- 亮度残差（n 序；cbp 位 = n>>2）----------
        if ($dct8) {
            // hl_decode_mb 的 8x8DCT 分支：逐 8x8 块 预测→叠残差→重建完成，
            // 后续块的预测使用已重建邻居样值（不能先写全部预测再统一叠残差）
            // 因此这里按 p8=0..3（左上/右上/左下/右下）顺序串行处理。
            for ($p8 = 0; $p8 < 4; $p8++) {
                // p8 的象限坐标：bx=p8&1（列），by=p8>>1（行）。
                $bx = $p8 & 1;
                $by = $p8 >> 1;
                // 该 8x8 块在亮度平面中的像素左上角。
                $x0 = $mbX * 16 + $bx * 8;
                $y0 = $mbY * 16 + $by * 8;
                // 按该象限左上块（4*p8）的模式生成 8x8 预测。
                $pred = $this->intra8x8Prediction($mbX, $mbY, $bx, $by, $modes[4 * $p8]);
                for ($yy = 0; $yy < 8; $yy++) {
                    $py = $y0 + $yy;
                    if ($py >= $this->height) {
                        break;
                    }
                    $rowOff = $py * $this->width;
                    for ($xx = 0; $xx < 8; $xx++) {
                        $px = $x0 + $xx;
                        if ($px < $this->width) {
                            $this->yPlane[$rowOff + $px] = $pred[$yy][$xx];
                        }
                    }
                }
                if (($lumaCbp & (1 << $p8)) !== 0) {
                    $block = $this->cabResidualLuma8x8($p8, true);
                    $this->idct8AddToPlane($this->yPlane, $this->width, $x0, $y0, $block);
                } else {
                    $cell = self::$SCAN8[4 * $p8];
                    $this->cabNzCache[$cell] = 0;
                    $this->cabNzCache[$cell + 1] = 0;
                    $this->cabNzCache[$cell + 8] = 0;
                    $this->cabNzCache[$cell + 9] = 0;
                }
            }
        } else {
            // 阶段1：按 n 序（8x8 分组序）解码全部残差系数块
            // 必须先读完 16 块再重建：CABAC 的 CBF/上下文依赖 n 序邻居，
            // 而像素重建必须按 raster 序（预测依赖已重建邻居）。
            $blocksN = array_fill(0, 16, null);
            for ($n = 0; $n < 16; $n++) {
                if (($lumaCbp & (1 << ($n >> 2))) !== 0) {
                    [$blocksN[$n]] = $this->cabDecodeAcBlock(2, $n, 0, $qp, false);
                } else {
                    $blocksN[$n] = array_fill(0, 16, 0);
                    $this->cabNzCache[self::$SCAN8[$n]] = 0;
                }
            }
            // 阶段2：按 raster 序重建像素（intra4x4 预测依赖已重建的上/右上邻居）
            // r 为 raster 块号，经 R2N 取回对应的 n 序模式与系数块。
            for ($r = 0; $r < 16; $r++) {
                $n = self::$R2N[$r];
                $this->cabWriteI4PredictionWithResidual($mbX, $mbY, $r, $modes[$n], $blocksN[$n]);
            }
        }

        // ---------- 色度 ----------
        $chromaQp = $this->cabChromaQp();
        $this->cabWriteChroma($mbX, $mbY, $chromaPredMode, $chromaCbp, $chromaQp, true);

        // ---------- 邻居缓存写回 ----------
        $this->cabFinishI4Bookkeeping($mbX, $mbY, $modes, $dct8 ? self::$CAB_FLG_DCT8 : 0);
    }

    /** 仅写 I4x4 亮度预测（8x8 DCT 路径用，残差由 idct8 统一叠加） */
    // 说明：8x8DCT 时 4 个 4x4 块共享同一 8x8 残差，因此不能逐块叠加残差，
    // 此方法只负责把预测样值写进亮度平面。
    // @param int $mbX    宏块列号
    // @param int $mbY    宏块行号
    // @param int $raster raster 4x4 块号（0..15）
    // @param int $mode   预测模式（0..8）
    // @return void
    private function cabWriteI4Prediction(int $mbX, int $mbY, int $raster, int $mode): void
    {
        $blkX = $raster & 3;
        $blkY = $raster >> 2;
        $pred = $this->intra4x4Prediction($mbX, $mbY, $blkX, $blkY, $mode);
        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                $py = $mbY * 16 + $blkY * 4 + $y;
                $px = $mbX * 16 + $blkX * 4 + $x;
                if ($py < $this->height && $px < $this->width) {
                    $this->yPlane[$py * $this->width + $px] = max(0, min(255, $pred[$y][$x]));
                }
            }
        }
    }

    /** 写 I4x4 亮度预测 + 4x4 残差（一条 idct4x4Flat，内含 +32 偏置） */
    // @param int        $mbX    宏块列号
    // @param int        $mbY    宏块行号
    // @param int        $raster raster 4x4 块号（0..15）
    // @param int        $mode   预测模式（0..8）
    // @param array|null $coeff  系数块（存储位 -> 电平），null 视为全 0
    // @return void
    private function cabWriteI4PredictionWithResidual(
        int $mbX,
        int $mbY,
        int $raster,
        int $mode,
        array $block
    ): void {
        $blkX = $raster & 3;
        $blkY = $raster >> 2;
        $pred = $this->intra4x4Prediction($mbX, $mbY, $blkX, $blkY, $mode);
        $idct = $this->idct4x4Flat($block);
        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                $py = $mbY * 16 + $blkY * 4 + $y;
                $px = $mbX * 16 + $blkX * 4 + $x;
                if ($py < $this->height && $px < $this->width) {
                    $v = $pred[$y][$x] + $idct[$y * 4 + $x];
                    $this->yPlane[$py * $this->width + $px] = max(0, min(255, $v));
                }
            }
        }
    }

    /** 把 nzCache 当前矩形汇总为 24-raster（0..15 亮度，16..19 Cb，20..23 Cr）到 $cabNz */
    // 用途：去掉 scan8 分组序，统一成去块滤波与 legacy 路径使用的 raster 序。
    // @return void
    private function cabBuildNz24(): void
    {
        $nz = &$this->cabNzCache;
        // 亮度：n 序经 N2R 转 raster 后存放。
        for ($n = 0; $n < 16; $n++) {
            $this->cabNz[self::$N2R[$n]] = $nz[self::$SCAN8[$n]];
        }
        // 色度：每色度分量取 4 个块（左上组 4 项），420 下每 MB 4 个 4x4。
        for ($b = 0; $b < 4; $b++) {
            $this->cabNz[16 + $b] = $nz[self::$SCAN8[16 + $b]]; // 52,53,60,61
            $this->cabNz[20 + $b] = $nz[self::$SCAN8[32 + $b]]; // 92,93,100,101
        }
    }

    /**
     * I4x4 宏块收尾：写 picMbType/picIntra4Mode、持久 cache、跨 MB 预测模式缓存与去块信息。
     *
     * @param int   $mbX        宏块列号
     * @param int   $mbY        宏块行号
     * @param array $modesN     16 个 4x4 块预测模式（n 序）
     * @param int   $extraFlags 额外 MB 类型标志（如 CAB_FLG_DCT8）
     * @return void
     */
    private function cabFinishI4Bookkeeping(int $mbX, int $mbY, array $modesN, int $extraFlags): void
    {
        $mbIdx = $this->cabMbIdx;
        $this->picMbType[$mbIdx] = self::$CAB_FLG_I4 | $extraFlags;
        $this->picIs8x8Dct[$mbIdx] = $extraFlags !== 0 ? 1 : 0;
        for ($n = 0; $n < 16; $n++) {
            $this->picIntra4Mode[$mbIdx * 16 + self::$N2R[$n]] = $modesN[$n];
        }
        $this->cabStoreWriteBack(true, $modesN);

        // 跨 MB 预测模式缓存（供 intra4x4Prediction 等既有路径使用），按几何右缘/底边
        $this->intra4x4LeftModes = [$modesN[5], $modesN[7], $modesN[13], $modesN[15]];
        $base = $mbX * 4;
        $this->intra4x4TopModes[$base] = $modesN[10];
        $this->intra4x4TopModes[$base + 1] = $modesN[11];
        $this->intra4x4TopModes[$base + 2] = $modesN[14];
        $this->intra4x4TopModes[$base + 3] = $modesN[15];

        $this->cabBuildNz24();
        $this->cabWriteBackNz($mbX);
        $this->cabWriteDeblock($this->cabDeblockTypeOffset);
    }

    /** I16x16 宏块 */
    // Intra16x16 宏块，含 DC/AC 分层残差与 4 个色度块。
    // 语法顺序（条款 7.3.5）：mb_type 已由调用侧读出并给出 predMode/luma15/chromaCbp，
    // 本方法继续读 intra_chroma_pred_mode → mb_qp_delta → 亮度 DC/AC → 色度 DC/AC → 重建。
    // @param int  $mbX        宏块列号
    // @param int  $mbY        宏块行号
    // @param int  $predMode   Intra16x16 预测模式（0=垂直,1=水平,2=DC,3=Plane）
    // @param bool $luma15     CodedBlockPatternLuma 是否为 15（亮度 AC 全有）
    // @param int  $chromaCbp  色度 CBP（0/1/2）
    // @return void
    private function cabDecodeI16x16(
        int $mbX,
        int $mbY,
        int $predMode,
        bool $luma15,
        int $chromaCbp
    ): void {
        $mbIdx = $this->cabMbIdx;
        // I16 的亮度 CBP 由 mb_type 中的 luma15 决定：15 表示 16 个 AC 块全有。
        $lumaCbp = $luma15 ? 15 : 0;
        // 组合 cbp：低 4 位亮度、bit4..5 色度。
        $cbp = $lumaCbp | ($chromaCbp << 4);
        $this->cabCbp = $cbp;
        $this->picCbp[$mbIdx] = $cbp;

        // 先读色度预测模式，再读 qp/残差（条款 7.3.5 的语法顺序）。
        $chromaPredMode = $this->cabReadChromaPredMode();
        $this->picChromaPred[$mbIdx] = $chromaPredMode;

        // I16 无论 cbp 如何都读 mb_qp_delta
        // 与 I4 不同：I16 的 cbp 为 0 时仍存在 mb_qp_delta（语法顺序固定）。
        $this->cabReadQpDelta();
        $qp = $this->cabQp;

        // 亮度 DC：cabResidualCore 内部已按 $j = scanJ[$k] 存到 raster 位（j=ZIGZAG_SCAN_4X4[k]），
        // 因此 cabResidualLumaDc 返回的即是 raster 序，不能再用 ZIGZAG_SCAN_4X4 二次散开
        // 亮度 DC 使用 cat0（16 个系数），反量化乘数取 dequant4Table[0][qp][0]。
        $yDcRaster = $this->cabResidualLumaDc();
        $lumaQmul = $this->dequant4Table[0][$qp][0];
        $yDcResult = $this->lumaDcDequantIdct($yDcRaster, $lumaQmul);

        // 亮度 AC（n 序，cat1，15 系数 zz15）
        // 注意 luma15=0 时不存在 AC 语法，不得读取任何 CBF。
        $yAcN = array_fill(0, 16, null);
        if ($lumaCbp !== 0) {
            for ($n = 0; $n < 16; $n++) {
                [$yAcN[$n]] = $this->cabDecodeAcBlock(1, $n, 0, $qp, true);
            }
        }

        // 色度
        // 色度同样先解 DC 再解 AC；QPC 由当前 QPY 推导（条款 8.5.8）。
        $chromaQp = $this->cabChromaQp();
        $cbDc = [0, 0, 0, 0];
        $crDc = [0, 0, 0, 0];
        // 色度 DC 仅在 chromaCbp>0 时存在（值 1 或 2 都含 DC）。
        if ($chromaCbp > 0) {
            $cbDc = $this->cabResidualChromaDc(0);
            $crDc = $this->cabResidualChromaDc(1);
        }
        $cbDcResult = $this->chromaDcDequantIdct($cbDc, $this->dequant4Table[1][$chromaQp][0]);
        $crDcResult = $this->chromaDcDequantIdct($crDc, $this->dequant4Table[2][$chromaQp][0]);

        $cbAc = array_fill(0, 4, array_fill(0, 16, 0));
        $crAc = array_fill(0, 4, array_fill(0, 16, 0));
        // chromaCbp==2 才存在色度 AC（cat4，zz15）；块号 16..19 为 Cb、32..35 为 Cr。
        if ($chromaCbp >= 2) {
            for ($b = 0; $b < 4; $b++) {
                [$cbAc[$b]] = $this->cabDecodeAcBlock(4, 16 + $b, 1, $chromaQp, true);
            }
            for ($b = 0; $b < 4; $b++) {
                [$crAc[$b]] = $this->cabDecodeAcBlock(4, 32 + $b, 2, $chromaQp, true);
            }
        }

        // ---------- 亮度预测 + 残差 ----------
        // I16 预测一次性生成整 MB 的 16x16 预测块，再按 4x4 块逐个叠加
        // （AC 存在时先 idct4x4Flat；AC 不存在时只叠加 DC 常量 (dc+32)>>6）。
        $lumaPred = $this->intra16x16Prediction($mbX, $mbY, $predMode);
        for ($by = 0; $by < 4; $by++) {
            for ($bx = 0; $bx < 4; $bx++) {
                $blk = $by * 4 + $bx;
                $dcRes = $yDcResult[$blk];
                if ($lumaCbp !== 0) {
                    $acBlock = $yAcN[self::$R2N[$blk]];
                    $acBlock[0] = $dcRes;
                    $idct = $this->idct4x4Flat($acBlock);
                    for ($y = 0; $y < 4; $y++) {
                        for ($x = 0; $x < 4; $x++) {
                            $py = $mbY * 16 + $by * 4 + $y;
                            $px = $mbX * 16 + $bx * 4 + $x;
                            if ($py < $this->height && $px < $this->width) {
                                $v = $lumaPred[$by * 4 + $y][$bx * 4 + $x] + $idct[$y * 4 + $x];
                                $this->yPlane[$py * $this->width + $px] = max(0, min(255, $v));
                            }
                        }
                    }
                } else {
                    $dcAdd = ($dcRes + 32) >> 6;
                    for ($y = 0; $y < 4; $y++) {
                        for ($x = 0; $x < 4; $x++) {
                            $py = $mbY * 16 + $by * 4 + $y;
                            $px = $mbX * 16 + $bx * 4 + $x;
                            if ($py < $this->height && $px < $this->width) {
                                $v = $lumaPred[$by * 4 + $y][$bx * 4 + $x] + $dcAdd;
                                $this->yPlane[$py * $this->width + $px] = max(0, min(255, $v));
                            }
                        }
                    }
                }
            }
        }

        // 色度重建：DC/AC 结果在此统一叠加到 U/V 平面。
        $this->cabWriteChromaBlocks($mbX, $mbY, $chromaPredMode, $chromaCbp, $cbDcResult, $crDcResult, $cbAc, $crAc);

        // ---------- 邻居缓存 ----------
        // I16 的类型位不使用 I4 标志；i4 预测模式对邻居按 DC(2) 推断。
        $this->picMbType[$mbIdx] = self::$CAB_FLG_I16;
        $this->picIs8x8Dct[$mbIdx] = 0;
        $this->cabStoreWriteBack(false, []);
        // I16 对 I4 邻居而言推断为 DC_PRED(2)
        $this->intra4x4LeftModes = [2, 2, 2, 2];
        $base = $mbX * 4;
        $this->intra4x4TopModes[$base] = 2;
        $this->intra4x4TopModes[$base + 1] = 2;
        $this->intra4x4TopModes[$base + 2] = 2;
        $this->intra4x4TopModes[$base + 3] = 2;
        $this->cabBuildNz24();
        $this->cabWriteBackNz($mbX);
        $chrType = $chromaCbp === 2 ? 8 : ($chromaCbp === 1 ? 4 : 0);
        $this->cabWriteDeblock($this->cabDeblockTypeOffset + 1 + ($luma15 ? 12 : 0) + $chrType + $predMode);
    }

    /**
     * 色度预测 + DC/AC 叠加（I16 与 I4 共用）。
     * 若未提供 $cbAc/$crAc（I4 路径），内部按 chromaCbp 自行解码。
     *
     * 说明：DC/AC 的“是否读取”由 chromaCbp 决定（0/1/2），
     * 该值来自 mb_type（I16）或 coded_block_pattern（I4/inter），
     * 因此这里不再重复读 CBF，只做重建。
     *
     * @param int   $mbX            宏块列号
     * @param int   $mbY            宏块行号
     * @param int   $chromaPredMode 色度预测模式（0..3）
     * @param int   $chromaCbp      色度 CBP（0/1/2）
     * @param int   $chromaQp       色度 QP（QPC）
     * @param bool  $intra          是否 intra（决定反量化表选择）
     * @param array|null $cbAc      Cb AC 系数块（可选）
     * @param array|null $crAc      Cr AC 系数块（可选）
     * @return void
     */
    private function cabWriteChroma(
        int $mbX,
        int $mbY,
        int $chromaPredMode,
        int $chromaCbp,
        int $chromaQp,
        bool $intra
    ): void {
        $cbDc = [0, 0, 0, 0];
        $crDc = [0, 0, 0, 0];
        if ($chromaCbp > 0) {
            $cbDc = $this->cabResidualChromaDc(0);
            $crDc = $this->cabResidualChromaDc(1);
        }
        $cbDcResult = $this->chromaDcDequantIdct($cbDc, $this->dequant4Table[$intra ? 1 : 4][$chromaQp][0]);
        $crDcResult = $this->chromaDcDequantIdct($crDc, $this->dequant4Table[$intra ? 2 : 5][$chromaQp][0]);

        $cbAc = array_fill(0, 4, array_fill(0, 16, 0));
        $crAc = array_fill(0, 4, array_fill(0, 16, 0));
        // chromaCbp==2 才存在色度 AC；==1 时只有 DC。
        if ($chromaCbp >= 2) {
            $cbList = $intra ? 1 : 4;
            $crList = $intra ? 2 : 5;
            // Cb 的 4 个 4x4 块，n 序 16..19，cat4，zz15。
            for ($b = 0; $b < 4; $b++) {
                [$cbAc[$b]] = $this->cabDecodeAcBlock(4, 16 + $b, $cbList, $chromaQp, true);
            }
            // Cr 的 4 个 4x4 块，n 序 32..35。
            for ($b = 0; $b < 4; $b++) {
                [$crAc[$b]] = $this->cabDecodeAcBlock(4, 32 + $b, $crList, $chromaQp, true);
            }
        } else {
            // cbp&0x20==0 时 fill_rectangle 清零色度 AC nz，
            // 否则陈旧值会经 48-store 污染后续 MB 的 CBF 邻居
            foreach (self::$CELL_CB_ST as $cell) {
                $this->cabNzCache[$cell] = 0;
            }
            foreach (self::$CELL_CR_ST as $cell) {
                $this->cabNzCache[$cell] = 0;
            }
        }
        // 统一走色度重建（DC/AC 叠加与裁剪）。
        $this->cabWriteChromaBlocks($mbX, $mbY, $chromaPredMode, $chromaCbp, $cbDcResult, $crDcResult, $cbAc, $crAc);
    }

    /**
     * 把已解码的色度 DC/AC 系数经 IDCT 叠加到 U/V 平面（I16 与 I4 共用）。
     *
     * 三种情形（条款 8.5.10）：
     *  - chromaCbp>=2：DC+AC 都做 4x4 IDCT；
     *  - chromaCbp==1：仅 DC，按 (dc+32)>>6 作为常量叠加；
     *  - chromaCbp==0：只写预测值。
     *
     * @param int   $mbX            宏块列号
     * @param int   $mbY            宏块行号
     * @param int   $chromaPredMode 色度预测模式（0..3）
     * @param int   $chromaCbp      色度 CBP
     * @param array $cbDcResult     Cb DC 的 4 个已反量化/IDCT 结果
     * @param array $crDcResult     Cr DC 的 4 个已反量化/IDCT 结果
     * @param array $cbAc           Cb 的 4 个 AC 系数块
     * @param array $crAc           Cr 的 4 个 AC 系数块
     * @return void
     *
     * 注意：本方法只负责“写像素”，不读任何语法；nz 的写回由调用方完成。
     */
    private function cabWriteChromaBlocks(
        int $mbX,
        int $mbY,
        int $chromaPredMode,
        int $chromaCbp,
        array $cbDcResult,
        array $crDcResult,
        array $cbAc,
        array $crAc
    ): void {
        // 色度预测（条款 8.3.4），Cb/Cr 分别生成 8x8 预测块。
        $cbPred = $this->intraChromaPrediction($mbX, $mbY, $chromaPredMode, 0);
        $crPred = $this->intraChromaPrediction($mbX, $mbY, $chromaPredMode, 1);
        // 色度平面尺寸为亮度的一半（420）。
        $cw = intdiv($this->width, 2);
        $ch = intdiv($this->height, 2);

        // 逐 8x8 象限（by/bx 各 0..1，共 4 个 4x4 块）重建色度。
        for ($by = 0; $by < 2; $by++) {
            for ($bx = 0; $bx < 2; $bx++) {
                $blk = $by * 2 + $bx;
                if ($chromaCbp >= 2) {
                    // DC+AC：把 DC 结果填回 AC 块位置 0，再整体做 4x4 IDCT。
                    $cbBlock = $cbAc[$blk];
                    $crBlock = $crAc[$blk];
                    $cbBlock[0] = $cbDcResult[$blk];
                    $crBlock[0] = $crDcResult[$blk];
                    $idctCb = $this->idct4x4Flat($cbBlock);
                    $idctCr = $this->idct4x4Flat($crBlock);
                    for ($y = 0; $y < 4; $y++) {
                        for ($x = 0; $x < 4; $x++) {
                            $py = $mbY * 8 + $by * 4 + $y;
                            $px = $mbX * 8 + $bx * 4 + $x;
                            if ($py < $ch && $px < $cw) {
                                $u = $cbPred[$by * 4 + $y][$bx * 4 + $x] + $idctCb[$y * 4 + $x];
                                $v = $crPred[$by * 4 + $y][$bx * 4 + $x] + $idctCr[$y * 4 + $x];
                                $idx = $py * $cw + $px;
                                $this->uPlane[$idx] = max(0, min(255, $u));
                                $this->vPlane[$idx] = max(0, min(255, $v));
                            }
                        }
                    }
                } elseif ($chromaCbp === 1) {
                    $cbAdd = ($cbDcResult[$blk] + 32) >> 6;
                    $crAdd = ($crDcResult[$blk] + 32) >> 6;
                    for ($y = 0; $y < 4; $y++) {
                        for ($x = 0; $x < 4; $x++) {
                            $py = $mbY * 8 + $by * 4 + $y;
                            $px = $mbX * 8 + $bx * 4 + $x;
                            if ($py < $ch && $px < $cw) {
                                $idx = $py * $cw + $px;
                                $this->uPlane[$idx] = max(0, min(255, $cbPred[$by * 4 + $y][$bx * 4 + $x] + $cbAdd));
                                $this->vPlane[$idx] = max(0, min(255, $crPred[$by * 4 + $y][$bx * 4 + $x] + $crAdd));
                            }
                        }
                    }
                } else {
                    for ($y = 0; $y < 4; $y++) {
                        for ($x = 0; $x < 4; $x++) {
                            $py = $mbY * 8 + $by * 4 + $y;
                            $px = $mbX * 8 + $bx * 4 + $x;
                            if ($py < $ch && $px < $cw) {
                                $idx = $py * $cw + $px;
                                $this->uPlane[$idx] = max(0, min(255, $cbPred[$by * 4 + $y][$bx * 4 + $x]));
                                $this->vPlane[$idx] = max(0, min(255, $crPred[$by * 4 + $y][$bx * 4 + $x]));
                            }
                        }
                    }
                }
            }
        }
    }

    /** 把本 MB nz 写回去块滤波等使用的行列缓存 */
    // 条款 8.7 的 bS 计算输入。
    // 亮度：左列为 n3/n7/n11/n15，上排为本 MB 的 n12..n15（按 MB 列偏置）。
    // 色度：左列取 Cb/Cr 右缘 4 个值，上排按半分辨率行宽 w2 定位。
    // @param int $mbX 宏块列号（用于上排的水平偏置）
    // @return void
    private function cabWriteBackNz(int $mbX): void
    {
        // 本 MB 的 24-raster nz（由 cabBuildNz24 预先汇总）。
        $nz = $this->cabNz;
        // 亮度左列：每行最后一个 4x4 块。
        $this->nzLeftColLuma[0] = $nz[3];
        $this->nzLeftColLuma[1] = $nz[7];
        $this->nzLeftColLuma[2] = $nz[11];
        $this->nzLeftColLuma[3] = $nz[15];
        // 亮度上排：本 MB 最底行的 4 个块，按列偏置写入。
        $base = $mbX * 4;
        $this->nzTopRowLuma[$base] = $nz[12];
        $this->nzTopRowLuma[$base + 1] = $nz[13];
        $this->nzTopRowLuma[$base + 2] = $nz[14];
        $this->nzTopRowLuma[$base + 3] = $nz[15];

        // 色度左列：取 Cb/Cr 在 MB 右缘的 4 个块（nz 存储在 16..23 / 32..39 段）。
        $this->nzLeftColChroma[0] = $nz[17];
        $this->nzLeftColChroma[1] = $nz[19];
        $this->nzLeftColChroma[2] = $nz[21];
        $this->nzLeftColChroma[3] = $nz[23];
        // 色度上排：按半分辨率行宽 w2 分成 Cb/Cr 两段存放。
        $this->nzTopRowChroma[$mbX * 2] = $nz[18];
        $this->nzTopRowChroma[$mbX * 2 + 1] = $nz[19];
        $w2 = $this->picWidthInMbs * 2;
        $this->nzTopRowChroma[$w2 + $mbX * 2] = $nz[22];
        $this->nzTopRowChroma[$w2 + $mbX * 2 + 1] = $nz[23];
    }

    /** 去块滤波簿记（语法 mb_type：I4=0；I16=1..24；PCM=25；P/B 片内嵌 intra 由调用侧加片类型偏移） */
    // @param int $syntaxType 供去块滤波使用的 mb_type 语法编号
    // @return void
    private function cabWriteDeblock(int $syntaxType): void
    {
        // 未启用去块信息收集时直接返回（不读也不写）。
        if (!$this->deblockInfoEnabled) {
            return;
        }
        $mbIdx = $this->cabMbIdx;
        // 记录类型、24 项 nz、QP，供后续 bS（边界强度）推导。
        $this->mbTypeForDeblock[$mbIdx] = $syntaxType;
        $this->mbNnzForDeblock[$mbIdx] = $this->cabNz;
        $this->mbQpForDeblock[$mbIdx] = $this->cabQp;
    }

    // ============================================================
    // I_PCM
    // I_PCM 绕过变换与量化：样值以原始字节存放在 CABAC 数据之后，
    // 解码完 384 字节后必须以 ptr+384 处重新初始化算术引擎（条款 9.3.1.2）。
    // ============================================================

    /**
     * I_PCM 宏块（条款 7.3.5 的 pcm_alignment_zero_bit + pcm_sample_luma/chroma）。
     *
     * 流程：
     *  1) 由 low 的低位回退 0~2 字节定位 PCM 起始；
     *  2) 直接拷贝 256 亮度 + 128 Cb + 128 Cr 原始样值；
     *  3) 以 ptr+384 重新初始化算术解码器；
     *  4) 写入 PCM 相关的 MB 簿记（nz=16、cbp=0xf7ef 等）。
     *
     * @return void
     */
    private function cabDecodePcm(): void
    {
        // 定位 PCM 起始：当前 ptr 按 low 回退 0~2 字节
        // 依据条款 9.3.1.2 的初始化/renorm 状态，PCM 样值起点可能比 ptr 早 1~2 字节。
        $ptr = $this->cabacPtr;
        if (($this->cabacLow & 0x1) !== 0) {
            $ptr--;
        }
        if (($this->cabacLow & 0x1FF) !== 0) {
            $ptr--;
        }

        $mbX = $this->cabMbX;
        $mbY = $this->cabMbY;
        $cw = intdiv($this->width, 2);
        // 256 Y
        // 亮度样值：16x16 逐行拷贝；越出图像边界时只推进源偏移（保持样值同步）。
        $off = $ptr;
        for ($yy = 0; $yy < 16; $yy++) {
            $py = $mbY * 16 + $yy;
            for ($xx = 0; $xx < 16; $xx++) {
                $px = $mbX * 16 + $xx;
                if ($py < $this->height && $px < $this->width) {
                    $this->yPlane[$py * $this->width + $px] = $this->cabacAbsByte($off++);
                } else {
                    $off++;
                }
            }
        }
        // 128 Cb + 128 Cr（420）
        // PCM 的色度固定 8x8/每分量，按平面指针逐个写入；越界只推进源偏移。
        foreach ([0 => $this->uPlane, 1 => $this->vPlane] as $uv => $plane) {
            for ($yy = 0; $yy < 8; $yy++) {
                $py = $mbY * 8 + $yy;
                for ($xx = 0; $xx < 8; $xx++) {
                    $px = $mbX * 8 + $xx;
                    $b = $this->cabacAbsByte($off++);
                    if ($py < intdiv($this->height, 2) && $px < $cw) {
                        if ($uv === 0) {
                            $this->uPlane[$py * $cw + $px] = $b;
                        } else {
                            $this->vPlane[$py * $cw + $px] = $b;
                        }
                    }
                }
            }
        }

        // PCM 之后按 ptr+384 重初始化引擎
        // 384 = 256(Y) + 64(Cb) + 64(Cr) 个原始样值字节（420 8bit）。
        $this->cabacInitAt($ptr + 384);

        $mbIdx = $this->cabMbIdx;
        // PCM 的 MB 类型与各邻居表默认值（条款 8.4.1 的 I_PCM 处理）。
        $this->picMbType[$mbIdx] = self::$CAB_FLG_PCM;
        $this->picIsPcm[$mbIdx] = 1;
        $this->picIs8x8Dct[$mbIdx] = 0;
        $this->picChromaPred[$mbIdx] = 0;
        // cbp=0xf7ef：亮度 0xF + 色度非零位的组合（PCM 后按“全非零”处理）。
        $this->picCbp[$mbIdx] = 0xf7ef;
        // PCM：当前矩形所有 nz=16 并持久化（write_back_non_zero_count 语义）
        foreach (self::$CELL_RESET as $cell) {
            $this->cabNzCache[$cell] = 16;
        }
        $this->cabStoreWriteBack(false, []);
        // 24-raster nz 全置 16，供去块滤波与邻居查询。
        for ($r = 0; $r < 24; $r++) {
            $this->cabNz[$r] = 16;
        }
        // PCM 对 I4 邻居按 DC(2) 推断预测模式。
        $this->intra4x4LeftModes = [2, 2, 2, 2];
        $base = $mbX * 4;
        $this->intra4x4TopModes[$base] = 2;
        $this->intra4x4TopModes[$base + 1] = 2;
        $this->intra4x4TopModes[$base + 2] = 2;
        $this->intra4x4TopModes[$base + 3] = 2;
        // PCM 不携带 mb_qp_delta，上一差值复位。
        $this->cabLastQpDiff = 0;
        $this->cabWriteBackNz($mbX);
        $this->cabWriteDeblock($this->cabDeblockTypeOffset + 25);
    }

    // ================================================================
    // CABAC P/B 宏块
    // P/B 片共享 skip、CBP/QP、残差与去块簿记流程，
    // 差异集中在 mb_type 语义（P 四种分区 vs B 的 direct/L0/L1/Bi）与双 list 语法。
    // ================================================================

    /**
     * 当前 MB 某分区（首块 n）的左/上邻居块定位。
     * @return int[] [lMb, lBlk, tMb, tBlk]，Mb 为 -1 表示不可用
     */
    private function cabNbBlock(int $n): array
    {
        $mbIdx = $this->cabMbIdx;
        $w = $this->picWidthInMbs;
        // n 为 scan8 块号；低 2 位为块列 bx，高 2 位为块行 by（0..3）。
        $bx = $n & 3;
        $by = $n >> 2;
        // 初始为不可用；下面按“同 MB 内优先，其次跨 MB”的顺序赋值。
        $lMb = -1; $lBlk = 0;
        $tMb = -1; $tBlk = 0;
        if ($bx > 0) {
            $lMb = $mbIdx; $lBlk = $n - 1;
        } elseif ($this->cabMbX > 0) {
            $lMb = $mbIdx - 1; $lBlk = $by * 4 + 3;
        }
        if ($by > 0) {
            $tMb = $mbIdx; $tBlk = $n - 4;
        } elseif ($this->cabMbY > 0) {
            $tMb = $mbIdx - $w; $tBlk = ($n & 3) + 12;
        }
        return [$lMb, $lBlk, $tMb, $tBlk];
    }

    /** mb_skip_flag（decode_cabac_mb_skip） */
    // 条款 9.3.3.1。
    // ctxIdxOffset：P 片为 11，B 片为 24（本文件用 +13 的偏移复用 P 的基址写法）；
    // ctxIdxInc 由左/上邻居是否“非 skip”决定（各 +1）。
    // @param bool $isB 是否 B 片
    // @return bool true 表示本 MB 为 skip
    private function cabReadMbSkip(bool $isB): bool
    {
        $mbIdx = $this->cabMbIdx;
        $w = $this->picWidthInMbs;
        $ctx = 0;
        // 左邻存在且不是 skip → ctxIdxInc +1。
        if ($this->cabMbX > 0 && empty($this->picIsSkip[$mbIdx - 1])) {
            $ctx++;
        }
        // 上邻存在且不是 skip → ctxIdxInc +1。
        if ($this->cabMbY > 0 && empty($this->picIsSkip[$mbIdx - $w])) {
            $ctx++;
        }
        // B 片的 skip 上下文整体后移 13（B 片 ctxIdxOffset 为 24，即 11+13）。
        if ($isB) {
            $ctx += 13;
        }
        return $this->cabGet(11 + $ctx) !== 0;
    }

    /** P mb_type（ctx 14..17）：>=0 为 inter（0..3），-1 为 intra */
    // 条款 9.3.3.3 的宏块类型解码。
    // 二叉树结构：14 → (15 → (16) | 17)，解出 0..3 四种 inter 分区尺寸。
    // @return int >=0 为 inter（0=16x16,1=16x8,2=8x16,3=8x8）；-1 为 intra
    private function cabReadPMbType(): int
    {
        // 第一个 bin=0：进入 inter 子树。
        if ($this->cabGet(14) === 0) {
            // 第二个 bin=0：16x16 或 16x8 方向；由第三 bin 决定。
            if ($this->cabGet(15) === 0) {
                return $this->cabGet(16) !== 0 ? 3 : 0;
            }
            // 第二个 bin=1：8x16 或 8x8。
            return $this->cabGet(17) !== 0 ? 1 : 2;
        }
        // 第一个 bin=1：intra（具体类型由调用方再读 intra mb_type）。
        return -1;
    }

    /**
     * B mb_type（ctx 27..32）。
     * k: 'direct' | 'inter'（mbType 0..22）| 'i4'/'i16'/'pcm'（B 片内嵌 Intra）
     */
    // 条款 9.3.3.3 的宏块类型解码。
    // ctxIdxInc 由左/上邻居是否为 direct 决定（各 +1），其余部分为固定 ctx。
    // @return array{k:string,mbType?:int}
    private function cabReadBMbType(): array
    {
        $mbIdx = $this->cabMbIdx;
        $w = $this->picWidthInMbs;
        $ctx = 0;
        // 左/上邻居非 direct 时 ctxIdxInc 各 +1（条款 9.3.3.3）。
        if ($this->cabMbX > 0 && empty($this->picIsDirectMb[$mbIdx - 1])) {
            $ctx++;
        }
        if ($this->cabMbY > 0 && empty($this->picIsDirectMb[$mbIdx - $w])) {
            $ctx++;
        }
        // 第一个 bin=0 → B_Direct_16x16。
        if ($this->cabGet(27 + $ctx) === 0) {
            return ['k' => 'direct', 'mbType' => 0];
        }
        // 第二 bin=0 → B_L0/L1_16x16。
        if ($this->cabGet(30) === 0) {
            return ['k' => 'inter', 'mbType' => 1 + ($this->cabGet(32) !== 0 ? 1 : 0)];
        }
        // 之后按 4 个 bin 组成 bits（高位先读），直接映射到 mbType 区间。
        $bits = 0;
        if ($this->cabGet(31) !== 0) $bits |= 8;
        if ($this->cabGet(32) !== 0) $bits |= 4;
        if ($this->cabGet(32) !== 0) $bits |= 2;
        if ($this->cabGet(32) !== 0) $bits |= 1;
        if ($bits < 8) {
            // bits<8 时 mbType = bits + 3（此处实现为直接偏移映射）。
            return ['k' => 'inter', 'mbType' => $bits + 3];
        }
        if ($bits === 13) {
            // B 片内嵌 Intra（decode_cabac_intra_mb_type ctx 32, intra_slice=0）
            return $this->cabReadIntraMbType(32, false);
        }
        if ($bits === 14) {
            return ['k' => 'inter', 'mbType' => 11];
        }
        if ($bits === 15) {
            return ['k' => 'inter', 'mbType' => 22];
        }
        // 剩余情况：追加 1 个 bin 后再映射（bits 8..12 的偏移修正）。
        $bits = ($bits << 1) + ($this->cabGet(32) !== 0 ? 1 : 0);
        return ['k' => 'inter', 'mbType' => $bits - 4];
    }

    /** P sub_mb_type（ctx 21..23） */
    // 条款 9.3.3.3。
    // @return int 0=8x8,1=8x4,2=4x8,3=4x4
    private function cabReadPSubMbType(): int
    {
        // 第一个 bin=1 → 8x8。
        if ($this->cabGet(21) !== 0) {
            return 0;
        }
        // 第二个 bin=0 → 8x4。
        if ($this->cabGet(22) === 0) {
            return 1;
        }
        // 第三个 bin=1 → 4x4，否则 4x8。
        if ($this->cabGet(23) !== 0) {
            return 2;
        }
        return 3;
    }

    /** B sub_mb_type（ctx 36..39） */
    // 条款 9.3.3.3。
    // @return int 0=Direct, 1..12 为 L0/L1/Bi 的 8x8/8x4/4x8/4x4 组合
    private function cabReadBSubMbType(): int
    {
        // 第一个 bin=0 → B_Direct_8x8。
        if ($this->cabGet(36) === 0) {
            return 0;
        }
        if ($this->cabGet(37) === 0) {
            return 1 + ($this->cabGet(39) !== 0 ? 1 : 0);
        }
        $type = 3;
        if ($this->cabGet(38) !== 0) {
            if ($this->cabGet(39) !== 0) {
                return 11 + ($this->cabGet(39) !== 0 ? 1 : 0);
            }
            $type += 4;
        }
        $type += 2 * ($this->cabGet(39) !== 0 ? 1 : 0);
        $type += $this->cabGet(39) !== 0 ? 1 : 0;
        return $type;
    }

    /** ref_idx（decode_cabac_mb_ref）：rc<=1 直接返回 0 */
    // 条款 9.3.3.7。
    // 若活动参考帧数 <= 1，语法上不存在 ref_idx，直接返回 0。
    // 否则 ctxIdxInc 由左/上邻块（同一分区首块 n）的 ref 是否为 0 且非 direct 决定；
    // 之后按一元前缀继续累加，ctx 进入 “ref>=1” 后的 4/5 段。
    // @param int  $list 0=L0 1=L1
    // @param int  $n    该分区首块的 scan8 块号
    // @param bool $isB  是否 B 片（B 片需排除 direct 邻块）
    // @return int 参考索引
    private function cabReadRefIdx(int $list, int $n, bool $isB): int
    {
        // 活动参考数：P 片 L1 不使用，但此处仅按 list 取对应计数值。
        $rc = $list === 0 ? $this->numRefIdxL0Active : $this->numRefIdxL1Active;
        if ($rc <= 1) {
            return 0;
        }
        // 左/上邻块定位（同一 MB 内或跨 MB 都可能）。
        [$lMb, $lBlk, $tMb, $tBlk] = $this->cabNbBlock($n);
        $store = $list === 0 ? $this->picRef0 : $this->picRef1;
        $ctx = 0;
        // 本 MB 内的邻居：读渐进覆盖（ref_cache 在 MB 解码过程中渐进填写，
        // ref 循环里后解码象限的 ctx 能看到先解码象限的 ref）
        if ($lMb >= 0) {
            if ($lMb === $this->cabMbIdx) {
                // 同 MB：从渐进覆盖数组取（尚未写入则为 -1）。
                $r = $this->cabMbRefOv[$list][$lBlk] ?? -1;
                $isDir = !empty($this->cabMbDirOv[$lBlk]);
            } else {
                // 跨 MB：从全帧持久存储取；B 片再查 direct 象限表。
                $r = $store[$lMb * 16 + $lBlk] ?? -1;
                $isDir = $isB && !empty($this->picDirectQ[$lMb * 4 + self::blkQuad($lBlk)]);
            }
            // 条件：邻块 ref 非 0 且不是 direct → ctxIdxInc 加 1。
            if ($r > 0 && !$isDir) {
                $ctx++;
            }
        }
        if ($tMb >= 0) {
            if ($tMb === $this->cabMbIdx) {
                $r = $this->cabMbRefOv[$list][$tBlk] ?? -1;
                $isDir = !empty($this->cabMbDirOv[$tBlk]);
            } else {
                $r = $store[$tMb * 16 + $tBlk] ?? -1;
                $isDir = $isB && !empty($this->picDirectQ[$tMb * 4 + self::blkQuad($tBlk)]);
            }
            // 上邻贡献 +2（与左邻的 +1 组成 0..3 的 ctxIdxInc）。
            if ($r > 0 && !$isDir) {
                $ctx += 2;
            }
        }
        $ref = 0;
        // 一元前缀：每读到一个 1 就把 ref 加 1；ctx 前缀读完后固定为 4（+ (ctx>>2) 保持段内）。
        while ($this->cabGet(54 + $ctx) !== 0) {
            $ref++;
            $ctx = ($ctx >> 2) + 4;
            // 上限保护（参考帧索引的合理上限）。
            if ($ref > 31) {
                break;
            }
        }
        return $ref;
    }

    /** mvd（DECODE_CABAC_MB_MVD），amvd 取自 picMvd0/picMvd1 的左/上邻块 */
    // 条款 9.3.3.8。
    // 上下文由左/上邻块的 |mvd| 之和 amvd 决定（三档），随后是一元前缀；
    // 前缀达到 9 后改用 EGk（指数 Golomb，bypass）读后缀，最后 bypass 读符号。
    // @param int $list 0=L0 1=L1
    // @param int $comp 0=水平 1=垂直
    // @param int $n    分区首块的 scan8 块号
    // @return int mvd 分量（带符号）
    private function cabReadMvd(int $list, int $comp, int $n): int
    {
        [$lMb, $lBlk, $tMb, $tBlk] = $this->cabNbBlock($n);
        $store = $list === 0 ? $this->picMvd0 : $this->picMvd1;
        // mvd_cache 存的是 abs(mvd) 截 70（decode_cabac_mb_mvd 传出 *mvda），
        // amvd = 左/上邻块 abs 值之和
        $amvd = 0;
        if ($lMb >= 0) {
            $m = $store[$lMb * 16 + $lBlk] ?? [0, 0];
            // 单个邻居贡献截到 70（条款 9.3.3.8 的 Min(70, ...)）。
            $amvd += min(70, abs($m[$comp]));
        }
        if ($tMb >= 0) {
            $m = $store[$tMb * 16 + $tBlk] ?? [0, 0];
            $amvd += min(70, abs($m[$comp]));
        }
        // 分量基址：水平 40，垂直 47；各占 3 个上下文。
        $ctxBase = $comp === 0 ? 40 : 47;
        // ctx = ctxbase + ((amvd-3)>>31) + ((amvd-33)>>31) + 2
        // 即 amvd<3 -> ctxbase+0，[3,33) -> ctxbase+1，>=33 -> ctxbase+2
        $ctx = $ctxBase + 2 - ($amvd < 3 ? 1 : 0) - ($amvd < 33 ? 1 : 0);
        // 第一个 bin=0：mvd 为 0。
        if ($this->cabGet($ctx) === 0) {
            return 0;
        }
        $mvd = 1;
        // 一元前缀继续使用 ctxbase+3 起的上下文；前 3 个 bin 逐级 +1。
        $ctxBase += 3;
        while ($mvd < 9 && $this->cabGet($ctxBase) !== 0) {
            if ($mvd < 4) {
                $ctxBase++;
            }
            $mvd++;
        }
        // 前缀到 9：读 EGk 后缀（k 从 3 开始）。
        if ($mvd >= 9) {
            $k = 3;
            // 第一段：连续 1 的个数 e（每段步长 2^k）。
            while ($this->cabBypass() !== 0) {
                $mvd += 1 << $k;
                $k++;
                if ($k > 20) {
                    break;
                }
            }
            // 第二段：按剩余 k 位读二进制后缀（高位先读）。
            while ($k-- > 0) {
                if ($this->cabBypass() !== 0) {
                    $mvd += 1 << $k;
                }
            }
        }
        // 最后 bypass 读符号位。
        return $this->cabBypassSign(-$mvd);
    }

    /** 把一个分区的 MVD 写入块级存储（分区内全部 4x4 块同值） */
    // picMvd0/picMvd1 按 MB*16 + raster 块号索引，每项 [dx, dy]；
    // 邻居的 |mvd| 用于 mvd 上下文推导（条款 9.3.3.8）。
    // @param int $list 0=L0 1=L1
    // @param int $mbIdx 宏块 raster 索引
    // @param int $bx   起始块列
    // @param int $by   起始块行
    // @param int $bw   宽（4x4 块数）
    // @param int $bh   高（4x4 块数）
    // @param int $dx   水平 mvd
    // @param int $dy   垂直 mvd
    // @return void
    private function cabStoreMvd(int $list, int $mbIdx, int $bx, int $by, int $bw, int $bh, int $dx, int $dy): void
    {
        // 选择 list 对应的持久存储。
        if ($list === 0) {
            $store = &$this->picMvd0;
        } else {
            $store = &$this->picMvd1;
        }
        // 本 MB 的 16 个 4x4 块起始偏移。
        $base = $mbIdx * 16;
        for ($y = $by; $y < $by + $bh; $y++) {
            $row = $base + $y * 4;
            for ($x = $bx; $x < $bx + $bw; $x++) {
                $store[$row + $x] = [$dx, $dy];
            }
        }
    }

    /** 子分区几何：返回 [块x, 块y, 宽(4x4), 高(4x4)]；兼容 P(0..3) 与 B(0..12) 子类型。
     *  注意 P/B 编号不同：B 的 1/2/3 是 L0/L1/Bi_8x8（整 8x8），P 的 1/2/3 是 8x4/4x8/4x4。
     */
    // 说明：$i8 为子宏块象限号（0..3），$j 为象限内分区号（0/1），
    // 返回值以 4x4 块为单位定位该分区在 MB 内的起点与尺寸。
    // @param int  $st  子宏块类型（P:0..3；B:0..12）
    // @param int  $i8  8x8 象限号（0=左上,1=右上,2=左下,3=右下）
    // @param int  $j   象限内分区号
    // @param bool $isB 是否 B 片子类型编号体系
    // @return int[] [bx, by, bw, bh]
    private function cabSubPartGeometry(int $st, int $i8, int $j, bool $isB = false): array
    {
        // 象限左上角（4x4 块坐标）。
        $px = ($i8 & 1) * 2;
        $py = ($i8 >> 1) * 2;
        // B 的 1/2/3（L0/L1/Bi_8x8）都是整 8x8。
        if ($isB && $st >= 1 && $st <= 3) {
            return [$px, $py, 2, 2];
        }
        if ($st === 0) {
            return [$px, $py, 2, 2];
        }
        if ($st === 1 || $st === 4 || $st === 6 || $st === 8) {
            return [$px, $py + $j, 2, 1];
        }
        if ($st === 2 || $st === 5 || $st === 7 || $st === 9) {
            return [$px + $j, $py, 1, 2];
        }
        return [$px + ($j & 1), $py + ($j >> 1), 1, 1];
    }

    /** inter 宏块残差：叠加到运动补偿后的像素上（inter 残差语义） */
    // 与 intra 不同，inter 的亮度/色度预测值已由 MC 写入平面，
    // 此处只做“解码系数 -> 反量化 -> IDCT -> 加回平面”的流程（条款 8.5）。
    // @param int  $mbX       宏块列号
    // @param int  $mbY       宏块行号
    // @param int  $cbpLuma   亮度 CBP（低 4 位）
    // @param int  $cbpChroma 色度 CBP（0/1/2）
    // @param bool $dct8      是否 8x8DCT
    // @return void
    private function cabDecodeInterResidual(int $mbX, int $mbY, int $cbpLuma, int $cbpChroma, bool $dct8): void
    {
        $qp = $this->cabQp;
        $w = $this->width;
        $h = $this->height;

        // ---------- 亮度 ----------
        // inter 的残差直接叠加在运动补偿结果上（预测像素已在 yPlane 中）。
        // dct8=true 走 4 个 8x8 块（cat5），否则走 16 个 4x4 块（cat2）。
        if ($dct8) {
            // 8x8 残差：p8 为 8x8 象限号，cbp 位同为 p8。
            for ($p8 = 0; $p8 < 4; $p8++) {
                if (($cbpLuma & (1 << $p8)) !== 0) {
                    $block = $this->cabResidualLuma8x8($p8, false);
                    if ($block !== null) {
                        $x0 = $mbX * 16 + ($p8 & 1) * 8;
                        $y0 = $mbY * 16 + ($p8 >> 1) * 8;
                        $this->idct8AddToPlane($this->yPlane, $w, $x0, $y0, $block);
                    }
                } else {
                    $cell = self::$SCAN8[4 * $p8];
                    $this->cabNzCache[$cell] = 0;
                    $this->cabNzCache[$cell + 1] = 0;
                    $this->cabNzCache[$cell + 8] = 0;
                    $this->cabNzCache[$cell + 9] = 0;
                }
            }
        } else {
            // 4x4 亮度残差：n 序读取 16 个 AC 块；cbp 位为 n>>2（每 4 个 n 共享一个 8x8 位）。
            for ($n = 0; $n < 16; $n++) {
                if (($cbpLuma & (1 << ($n >> 2))) !== 0) {
                    [$block] = $this->cabDecodeAcBlock(2, $n, 3, $qp, false);
                    $idct = $this->idct4x4Flat($block);
                    // block_offset[i] 由 scan8[i]-scan8[0] 派生：
                    // 残差块号 n 为 8x8 分组序，像素位置须经 N2R 映射到 raster
                    $r = self::$N2R[$n];
                    $px0 = $mbX * 16 + ($r & 3) * 4;
                    $py0 = $mbY * 16 + ($r >> 2) * 4;
                    for ($y = 0; $y < 4; $y++) {
                        $py = $py0 + $y;
                        if ($py >= $h) {
                            break;
                        }
                        $row = $py * $w + $px0;
                        $iy = $y * 4;
                        for ($x = 0; $x < 4; $x++) {
                            if ($px0 + $x >= $w) {
                                break;
                            }
                            $v = $this->yPlane[$row + $x] + $idct[$iy + $x];
                            $this->yPlane[$row + $x] = $v < 0 ? 0 : ($v > 255 ? 255 : $v);
                        }
                    }
                } else {
                    $this->cabNzCache[self::$SCAN8[$n]] = 0;
                }
            }
        }

        // ---------- 色度（叠加残差，不做帧内预测）----------
        // 色度 QP 由当前 QPY 经 chroma_qp_index_offset 推导（条款 8.5.8）。
        $chromaQp = $this->cabChromaQp();
        $cw = intdiv($w, 2);
        $ch = intdiv($h, 2);

        $cbDc = [0, 0, 0, 0];
        $crDc = [0, 0, 0, 0];
        if ($cbpChroma > 0) {
            $cbDc = $this->cabResidualChromaDc(0);
            $crDc = $this->cabResidualChromaDc(1);
        }
        $cbDcResult = $this->chromaDcDequantIdct($cbDc, $this->dequant4Table[4][$chromaQp][0]);
        $crDcResult = $this->chromaDcDequantIdct($crDc, $this->dequant4Table[5][$chromaQp][0]);

        $cbAc = array_fill(0, 4, array_fill(0, 16, 0));
        $crAc = array_fill(0, 4, array_fill(0, 16, 0));
        if ($cbpChroma >= 2) {
            for ($b = 0; $b < 4; $b++) {
                [$cbAc[$b]] = $this->cabDecodeAcBlock(4, 16 + $b, 4, $chromaQp, true);
            }
            for ($b = 0; $b < 4; $b++) {
                [$crAc[$b]] = $this->cabDecodeAcBlock(4, 32 + $b, 5, $chromaQp, true);
            }
        }

        // 逐 4x4 块叠加：blk=0..3 对应 Cb/Cr 的左上/右上/左下/右下。
        for ($by = 0; $by < 2; $by++) {
            for ($bx = 0; $bx < 2; $bx++) {
                $blk = $by * 2 + $bx;
                if ($cbpChroma >= 2) {
                    $cbBlock = $cbAc[$blk];
                    $crBlock = $crAc[$blk];
                    $cbBlock[0] = $cbDcResult[$blk];
                    $crBlock[0] = $crDcResult[$blk];
                    $idctCb = $this->idct4x4Flat($cbBlock);
                    $idctCr = $this->idct4x4Flat($crBlock);
                    for ($y = 0; $y < 4; $y++) {
                        for ($x = 0; $x < 4; $x++) {
                            $py = $mbY * 8 + $by * 4 + $y;
                            $px = $mbX * 8 + $bx * 4 + $x;
                            if ($py < $ch && $px < $cw) {
                                $idx = $py * $cw + $px;
                                $u = $this->uPlane[$idx] + $idctCb[$y * 4 + $x];
                                $v = $this->vPlane[$idx] + $idctCr[$y * 4 + $x];
                                $this->uPlane[$idx] = $u < 0 ? 0 : ($u > 255 ? 255 : $u);
                                $this->vPlane[$idx] = $v < 0 ? 0 : ($v > 255 ? 255 : $v);
                            }
                        }
                    }
                } elseif ($cbpChroma === 1) {
                    $cbAdd = ($cbDcResult[$blk] + 32) >> 6;
                    $crAdd = ($crDcResult[$blk] + 32) >> 6;
                    for ($y = 0; $y < 4; $y++) {
                        for ($x = 0; $x < 4; $x++) {
                            $py = $mbY * 8 + $by * 4 + $y;
                            $px = $mbX * 8 + $bx * 4 + $x;
                            if ($py < $ch && $px < $cw) {
                                $idx = $py * $cw + $px;
                                $u = $this->uPlane[$idx] + $cbAdd;
                                $v = $this->vPlane[$idx] + $crAdd;
                                $this->uPlane[$idx] = $u < 0 ? 0 : ($u > 255 ? 255 : $u);
                                $this->vPlane[$idx] = $v < 0 ? 0 : ($v > 255 ? 255 : $v);
                            }
                        }
                    }
                }
            }
        }
    }

    /** inter 宏块收尾簿记：cbp/i4/nz/去块 */
    // 与 intra 收尾的差异：inter 不写 i4 模式（按 DC=2 推断），
    // 且去块类型使用 inter 的语法编号（由 $deblockType 传入）。
    // @param int  $mbX         宏块列号
    // @param int  $mbY         宏块行号
    // @param int  $cbp         本 MB 的 cbp
    // @param bool $dct8        是否 8x8DCT
    // @param int  $deblockType 去块用 mb_type 编号
    // @return void
    private function cabFinishInterMb(int $mbX, int $mbY, int $cbp, bool $dct8, int $deblockType): void
    {
        $mbIdx = $this->cabMbIdx;
        $this->cabCbp = $cbp;
        // picCbp 已在残差解码前赋值（含残差期 OR 上的 DC 标志位），此处不得覆盖
        $this->picIs8x8Dct[$mbIdx] = $dct8 ? 1 : 0;
        if ($dct8) {
            $this->picMbType[$mbIdx] |= self::$CAB_FLG_DCT8;
        }
        $this->cabStoreWriteBack(false, []);
        $this->intra4x4LeftModes = [2, 2, 2, 2];
        $base = $mbX * 4;
        $this->intra4x4TopModes[$base] = 2;
        $this->intra4x4TopModes[$base + 1] = 2;
        $this->intra4x4TopModes[$base + 2] = 2;
        $this->intra4x4TopModes[$base + 3] = 2;
        $this->cabBuildNz24();
        $this->cabWriteBackNz($mbX);
        $this->cabWriteDeblock($deblockType);
    }

    /** CABAC P 片宏块 */
    // skip → mb_type → inter/intra → CBP/QP → 残差 → 簿记。
    // 语法顺序严格按条款 7.3.5：mb_skip_flag 之后才可能出现 mb_type；
    // intra 由 mb_type=-1 分支转 decodeCabacIntraMb；inter 分四种分区
    // （16x16 / 16x8 / 8x16 / 8x8），最后统一读 CBP、transform_size_8x8_flag 与 QP。
    // @param int $mbX 宏块列号
    // @param int $mbY 宏块行号
    // @return void
    private function decodeCabacPMb(int $mbX, int $mbY): void
    {
        // 进入 MB：重置本 MB 状态并填充邻居 cache。
        $this->cabMbBegin($mbX, $mbY);
        $mbIdx = $this->cabMbIdx;
        $this->picChromaPred[$mbIdx] = 0;
        $this->picIsSkip[$mbIdx] = 0;

        // ---------- skip（decode_cabac_mb_skip）----------
        // P_Skip（条款 8.4.1 / 9.3.3.1）：无条件 16x16、参考索引 0、无残差。
        // 读出的 skip 标志为 1 时不需要任何后续语法，直接做运动补偿并写簿记。
        if ($this->cabReadMbSkip(false)) {
            $this->picIsSkip[$mbIdx] = 1;
            $this->picCbp[$mbIdx] = 0;   // 跳过宏块：cbp_table 置 0
            $this->cabLastQpDiff = 0;    // last_qscale_diff 置 0
            $this->decodePSkip($mbX, $mbY);
            $this->cabWriteDeblock(0);
            return;
        }

        // mb_type：返回值 -1 表示 intra，0..3 表示 P 的四种 inter 分区尺寸。
        $mbType = $this->cabReadPMbType();
        if ($mbType === -1) {
            // P 片内嵌 Intra（ctx 17, intra_slice=0）
            $this->picIsSkip[$mbIdx] = 0;
            $intraType = $this->cabReadIntraMbType(17, false);
            $this->decodeCabacIntraMb($mbX, $mbY, $intraType);
            // intra 邻居：P 缓存 MV/ref 标记未使用
            $intraMv = [0, 0, -1];
            $this->mvLeftCol = [$intraMv, $intraMv, $intraMv, $intraMv];
            $base = $mbX * 4;
            for ($i = 0; $i < 4; $i++) {
                $this->mvTopRow[$base + $i] = $intraMv;
            }
            return;
        }

        // ---------- inter 语法 ----------
        // inter MB：不可用邻居 nz 哨兵转 0（!IS_INTRA -> 0）
        // 说明：mb_type 已确定为 inter，此时才把 cabFillCaches 留下的 -1 转成 0；
        // 这一步必须早于任何 CBF 读取，否则上下文值错误会导致算术引擎失步。
        $this->cabFixUnavailNz(false);
        $dct8Allowed = $this->transform8x8ModeFlag;
        // mcTasks：P_8x8 下合并执行的 MC 任务；mbMvs：8x8 子块的 MV 网格。
        $mcTasks = [];
        $mbMvs = [];

        if ($mbType === 0) {
            // P_L0_16x16
            // 语法：ref_idx_l0（num_ref_idx_l0_active>1 时）→ mvd_l0[0]/[1]（条款 7.3.5.1）。
            $refIdx = $this->cabReadRefIdx(0, 0, false);
            // 读后立即填 ref_cache（整 MB 4x4）。
            $this->cabOvRefBox(0, 0, 0, 4, 4, $refIdx);
            // MV 预测（条款 8.4.1.3.1），随后两分量 mvd 相加。
            [$px, $py] = $this->getP16x16MvPrediction($mbX, $mbY, $refIdx);
            $mvdX = $this->cabReadMvd(0, 0, 0);
            $mvdY = $this->cabReadMvd(0, 1, 0);
            $mvX = $px + $mvdX;
            $mvY = $py + $mvdY;
            $this->performMotionCompensation16x16($mbX, $mbY, $mvX, $mvY, $refIdx);
            $this->saveMvForPrediction($mbX, $mbY, $mvX, $mvY, $refIdx);
            $this->cabStoreMvd(0, $mbIdx, 0, 0, 4, 4, $mvdX, $mvdY);
            if ($this->deblockInfoEnabled) {
                $this->mbMvForDeblock[$mbIdx] = array_fill(0, 16, [$mvX, $mvY]);
                $this->mbRefForDeblock[$mbIdx] = array_fill(0, 16, $refIdx);
            }
        } elseif ($mbType === 1 || $mbType === 2) {
            // P_L0_16x8 / P_L0_8x16
            // anchor 用 raster 块号（p1 首块：16x8=(0,2)→8，8x16=(2,0)→2；
            // per-quadrant 8/4 经 scan8 落在同位置，cabNbBlock 按空间取邻居）
            // 语法顺序：part0 ref → part1 ref → part0 mvd → part1 mvd（条款 7.3.5.1）。
            $ns = $mbType === 1 ? [0, 8] : [0, 2];
            $ref0 = $this->cabReadRefIdx(0, $ns[0], false);
            // 16x8/8x16：每分区 ref 读后立即 fill ref_cache（part1 的 ref ctx 可见 part0）
            if ($mbType === 1) {
                $this->cabOvRefBox(0, 0, 0, 4, 2, $ref0);
            } else {
                $this->cabOvRefBox(0, 0, 0, 2, 4, $ref0);
            }
            $ref1 = $this->cabReadRefIdx(0, $ns[1], false);
            if ($mbType === 1) {
                $this->cabOvRefBox(0, 0, 2, 4, 2, $ref1);
            } else {
                $this->cabOvRefBox(0, 2, 0, 2, 4, $ref1);
            }
            $mv0X = 0; $mv0Y = 0; $mv1X = 0; $mv1Y = 0;
            $mvd0X = 0; $mvd0Y = 0; $mvd1X = 0; $mvd1Y = 0;
            if ($mbType === 1) {
                [$px, $py] = $this->getP16x8MvPrediction($mbX, $mbY, 0, $ref0);
                $mvd0X = $this->cabReadMvd(0, 0, 0);
                $mvd0Y = $this->cabReadMvd(0, 1, 0);
                $mv0X = $px + $mvd0X; $mv0Y = $py + $mvd0Y;
                // 每分区读后立即写回 mvd_cache（part1 的 amvd 依赖 part0 的值）
                $this->cabStoreMvd(0, $mbIdx, 0, 0, 4, 2, $mvd0X, $mvd0Y);
                // part1 的 pred 经 mv_cache（part0 的 ref 已回填），mvPart0 须带 ref0
                [$px, $py] = $this->getP16x8MvPrediction($mbX, $mbY, 1, $ref1, [$mv0X, $mv0Y, $ref0]);
                $mvd1X = $this->cabReadMvd(0, 0, 8);
                $mvd1Y = $this->cabReadMvd(0, 1, 8);
                $mv1X = $px + $mvd1X; $mv1Y = $py + $mvd1Y;
                $this->cabStoreMvd(0, $mbIdx, 0, 2, 4, 2, $mvd1X, $mvd1Y);
            } else {
                [$px, $py] = $this->getP8x16MvPrediction($mbX, $mbY, 0, $ref0);
                $mvd0X = $this->cabReadMvd(0, 0, 0);
                $mvd0Y = $this->cabReadMvd(0, 1, 0);
                $mv0X = $px + $mvd0X; $mv0Y = $py + $mvd0Y;
                $this->cabStoreMvd(0, $mbIdx, 0, 0, 2, 4, $mvd0X, $mvd0Y);
                // 同 16x8：mvPart0 带上 ref0（mv_cache[scan8[4]-1] 的 ref = part0）
                [$px, $py] = $this->getP8x16MvPrediction($mbX, $mbY, 1, $ref1, [$mv0X, $mv0Y, $ref0]);
                $mvd1X = $this->cabReadMvd(0, 0, 2);
                $mvd1Y = $this->cabReadMvd(0, 1, 2);
                $mv1X = $px + $mvd1X; $mv1Y = $py + $mvd1Y;
                $this->cabStoreMvd(0, $mbIdx, 2, 0, 2, 4, $mvd1X, $mvd1Y);
            }
            if ($mbType === 1) {
                $this->performMotionCompensation16x8($mbX, $mbY, 0, $mv0X, $mv0Y, $ref0);
                $this->performMotionCompensation16x8($mbX, $mbY, 1, $mv1X, $mv1Y, $ref1);
                $this->saveMvForPrediction16x8($mbX, $mbY, $mv0X, $mv0Y, $ref0, $mv1X, $mv1Y, $ref1);
            } else {
                $this->performMotionCompensation8x16($mbX, $mbY, 0, $mv0X, $mv0Y, $ref0);
                $this->performMotionCompensation8x16($mbX, $mbY, 1, $mv1X, $mv1Y, $ref1);
                $this->saveMvForPrediction8x16($mbX, $mbY, $mv0X, $mv0Y, $ref0, $mv1X, $mv1Y, $ref1);
            }
            if ($this->deblockInfoEnabled) {
                $mvArr = [];
                $refArr = [];
                for ($y = 0; $y < 4; $y++) {
                    for ($x = 0; $x < 4; $x++) {
                        if ($mbType === 1) {
                            $use0 = $y < 2;
                        } else {
                            $use0 = $x < 2;
                        }
                        $mvArr[$y * 4 + $x] = $use0 ? [$mv0X, $mv0Y] : [$mv1X, $mv1Y];
                        $refArr[$y * 4 + $x] = $use0 ? $ref0 : $ref1;
                    }
                }
                $this->mbMvForDeblock[$mbIdx] = $mvArr;
                $this->mbRefForDeblock[$mbIdx] = $refArr;
            }
        } else {
            // P_8x8（CABAC mb_type 3，无 P_8x8ref0）
            // 4 个 sub_mb_type 先全部读出（ctx 21..23），之后按象限顺序读 ref/mvd。
            $subTypes = [
                $this->cabReadPSubMbType(),
                $this->cabReadPSubMbType(),
                $this->cabReadPSubMbType(),
                $this->cabReadPSubMbType(),
            ];
            // 8x8 子块 MV 网格：每项 [mvX, mvY, ref]。
            $mbMvs = array_fill(0, 4, array_fill(0, 4, [0, 0, 0]));
            $dct8Allowed = $this->transform8x8ModeFlag
                && $subTypes[0] === 0 && $subTypes[1] === 0
                && $subTypes[2] === 0 && $subTypes[3] === 0;

            // refs（list 序）；anchor = 各 8x8 象限首块的 raster 块号 [0,2,8,10]
            // 说明：ref_idx 的 ctx 需要按空间邻居取块，因此传 raster 块号而非象限号。
            $refs = [0, 0, 0, 0];
            $p8RefN = [0, 2, 8, 10];
            for ($i = 0; $i < 4; $i++) {
                $refs[$i] = $this->cabReadRefIdx(0, $p8RefN[$i], false);
                // 每象限 ref 读后立即 fill ref_cache 2x2（后续象限 ctx 可见）
                $this->cabOvRefBox(0, ($i & 1) * 2, ($i >> 1) * 2, 2, 2, $refs[$i]);
            }
            // mvds + 预测（list 序、子块序、分区序）
            for ($i = 0; $i < 4; $i++) {
                $st = $subTypes[$i];
                $partCount = $st === 0 ? 1 : ($st === 3 ? 4 : 2);
                for ($j = 0; $j < $partCount; $j++) {
                    // 分区的 4x4 几何（bx/by 为 MB 内块坐标，cols/rows 为块尺寸）。
                    [$bx, $by, $cols, $rows] = $this->cabSubPartGeometry($st, $i, $j);
                    [$mvX, $mvY] = $this->predictMvP8x8(
                        $mbX, $mbY, $bx, $by, $cols, $mbMvs, $this->mvTopRow, $this->mvLeftCol, $refs[$i]
                    );
                    $n = $by * 4 + $bx;
                    $mvdX = $this->cabReadMvd(0, 0, $n);
                    $mvdY = $this->cabReadMvd(0, 1, $n);
                    $mvX += $mvdX;
                    $mvY += $mvdY;
                    for ($y = $by; $y < $by + $rows; $y++) {
                        for ($x = $bx; $x < $bx + $cols; $x++) {
                            $mbMvs[$y][$x] = [$mvX, $mvY, $refs[$i]];
                        }
                    }
                    $mcTasks[] = [$bx * 4, $by * 4, $cols * 4, $rows * 4, $mvX, $mvY, $refs[$i]];
                    $this->cabStoreMvd(0, $mbIdx, $bx, $by, $cols, $rows, $mvdX, $mvdY);
                }
            }
            // 合并执行全部 MC 任务（每个分区一项：x, y, w, h, mvX, mvY, ref）。
            $this->executeMergedMcTasks($mbX, $mbY, $mcTasks);
            // 保存逐 4x4 的 MV 网格供后续 MB 的 MV 预测使用。
            $this->saveMvForPrediction8x8($mbX, $mbY, $mbMvs);
            if ($this->deblockInfoEnabled) {
                $mvArr = [];
                $refArr = [];
                for ($y = 0; $y < 4; $y++) {
                    for ($x = 0; $x < 4; $x++) {
                        $m = $mbMvs[$y][$x];
                        $mvArr[$y * 4 + $x] = [$m[0], $m[1]];
                        $refArr[$y * 4 + $x] = $m[2];
                    }
                }
                $this->mbMvForDeblock[$mbIdx] = $mvArr;
                $this->mbRefForDeblock[$mbIdx] = $refArr;
            }
        }

        // ---------- CBP / dct8 / qp ----------
        // coded_block_pattern 与 transform_size_8x8_flag（仅当亮度 CBP 非零时才可能为 8x8）。
        $cbpLuma = $this->cabReadCbpLuma();
        $cbpChroma = $this->cabReadCbpChroma();
        $dct8 = false;
        if ($dct8Allowed && ($cbpLuma & 15) !== 0 && $this->cabGet(399 + $this->cabNeighborTransformSize()) !== 0) {
            $dct8 = true;
        }
        $cbp = $cbpLuma | ($cbpChroma << 4);
        // cbp_table 在残差解码前赋值（色度 DC/亮度 DC 的 CBF 标志位在其上 OR）
        $this->picCbp[$mbIdx] = $cbp;
        if ($cbp !== 0) {
            $this->cabReadQpDelta();
        } else {
            $this->cabLastQpDiff = 0;
        }
        $this->cabDecodeInterResidual($mbX, $mbY, $cbpLuma, $cbpChroma, $dct8);
        $this->cabFinishInterMb($mbX, $mbY, $cbp, $dct8, $mbType);
    }

    /** CABAC B 片宏块 */
    // skip → mb_type → direct/inter/intra → 双 list 语法 → CBP/QP → 残差。
    // B 片特有：mb_type 可返回 direct（B_Direct_16x16），inter 分区带
    // L0/L1/Bi 方向；16x8/8x16 的 ref/mvd 按 list 主序读取（条款 7.3.5.1）。
    // @param int $mbX 宏块列号
    // @param int $mbY 宏块行号
    // @return void
    private function decodeCabacBMb(int $mbX, int $mbY): void
    {
        // 进入 MB：重置本 MB 状态并填充邻居 cache。
        $this->cabMbBegin($mbX, $mbY);
        $mbIdx = $this->cabMbIdx;
        $this->picChromaPred[$mbIdx] = 0;
        $this->picIsSkip[$mbIdx] = 0;
        $this->picIsDirectMb[$mbIdx] = 0;

        // ---------- skip（B_Skip = B_Direct_16x16 无残差）----------
        // B_Skip 等价于 B_Direct_16x16 且 cbp=0（条款 8.4.1.1 / 9.3.3.1）。
        // 注意 B 片的 skip ctxIdxOffset 与 P 片不同（由 cabReadMbSkip 内部 +13 处理）。
        if ($this->cabReadMbSkip(true)) {
            $this->picIsSkip[$mbIdx] = 1;
            $this->picIsDirectMb[$mbIdx] = 1;
            // B_Skip = B_Direct_16x16：direct_cache 全 MB direct（邻居 ref ctx 排除）
            $q0 = $mbIdx * 4;
            $this->picDirectQ[$q0] = $this->picDirectQ[$q0 + 1] = $this->picDirectQ[$q0 + 2] = $this->picDirectQ[$q0 + 3] = 1;
            $this->picCbp[$mbIdx] = 0;   // 跳过宏块：cbp_table 置 0
            $this->cabLastQpDiff = 0;    // last_qscale_diff 置 0
            $this->decodeBSkip($mbX, $mbY);
            $this->cabWriteDeblock(0);
            return;
        }

        $t = $this->cabReadBMbType();

        if ($t['k'] === 'i4' || $t['k'] === 'i16' || $t['k'] === 'pcm') {
            // B 片内嵌 Intra
            $this->decodeCabacIntraMb($mbX, $mbY, $t);
            // intra 邻居：B 列表 MV/ref 缓存标记未使用
            $intraMv = [0, 0, -1];
            $this->bMvLeftCol[0] = [$intraMv, $intraMv, $intraMv, $intraMv];
            $this->bMvLeftCol[1] = [$intraMv, $intraMv, $intraMv, $intraMv];
            $base = $mbX * 4;
            for ($i = 0; $i < 4; $i++) {
                $this->bMvTopRow[0][$base + $i] = $intraMv;
                $this->bMvTopRow[1][$base + $i] = $intraMv;
            }
            return;
        }

        if ($t['k'] === 'direct') {
            // B_Direct_16x16（含残差）；direct 同属 inter（!IS_INTRA -> 0）
            // direct 模式本身不读 ref_idx/mvd，由 BDIRECT 推导（条款 8.4.1.2.2/8.4.1.2.3）。
            $this->cabFixUnavailNz(false);
            $this->picIsDirectMb[$mbIdx] = 1;
            // B_Direct_16x16：direct_cache 全 MB direct（邻居 ref ctx 排除）
            $q0 = $mbIdx * 4;
            $this->picDirectQ[$q0] = $this->picDirectQ[$q0 + 1] = $this->picDirectQ[$q0 + 2] = $this->picDirectQ[$q0 + 3] = 1;
            $this->decodeBDirect16x16($mbX, $mbY);
            $dct8Allowed = $this->transform8x8ModeFlag && $this->direct8x8InferenceFlag;
            $cbpLuma = $this->cabReadCbpLuma();
            $cbpChroma = $this->cabReadCbpChroma();
            $dct8 = false;
            if ($dct8Allowed && ($cbpLuma & 15) !== 0 && $this->cabGet(399 + $this->cabNeighborTransformSize()) !== 0) {
                $dct8 = true;
            }
            $cbp = $cbpLuma | ($cbpChroma << 4);
            // cbp_table 在残差解码前赋值（色度 DC/亮度 DC 的 CBF 标志位在其上 OR）
            $this->picCbp[$mbIdx] = $cbp;
            if ($cbp !== 0) {
                $this->cabReadQpDelta();
            } else {
                $this->cabLastQpDiff = 0;
            }
            $this->cabDecodeInterResidual($mbX, $mbY, $cbpLuma, $cbpChroma, $dct8);
            $this->cabFinishInterMb($mbX, $mbY, $cbp, $dct8, 0);
            return;
        }

        $mbType = $t['mbType'];
        // 显式 inter 分区（16x16/16x8/8x16/8x8）：不可用邻居 nz 哨兵转 0
        // 注意：direct 分支已在上面单独处理并返回，这里只处理显式分区。
        $this->cabFixUnavailNz(false);
        $dct8Allowed = $this->transform8x8ModeFlag;

        if ($mbType <= 2) {
            // B_L0/L1_16x16
            // mbType 1 → 仅 L0，mbType 2 → 仅 L1；另一 list 的网格保持 ref=-1。
            $list = $mbType === 1 ? 0 : 1;
            $refIdx = $this->cabReadRefIdx($list, 0, true);
            // ref 读后立即 fill ref_cache 4x4（后续 MB 边界与本 MB mvd ctx 可见）
            $this->cabOvRefBox($list, 0, 0, 4, 4, $refIdx);
            [$px, $py] = $this->getB16x16MvPrediction($mbX, $mbY, $list, $refIdx);
            $mvdX = $this->cabReadMvd($list, 0, 0);
            $mvdY = $this->cabReadMvd($list, 1, 0);
            $mvX = $px + $mvdX;
            $mvY = $py + $mvdY;
            // 整 MB 单一 (mv,ref)：嵌套 array_fill 共享同一数组 zval（值与逐格写一致）
            $mvCell = [$mvX, $mvY, $refIdx];
            $idleCell = [0, 0, -1];
            if ($list === 0) {
                $gridL0 = array_fill(0, 4, array_fill(0, 4, $mvCell));
                $gridL1 = array_fill(0, 4, array_fill(0, 4, $idleCell));
            } else {
                $gridL0 = array_fill(0, 4, array_fill(0, 4, $idleCell));
                $gridL1 = array_fill(0, 4, array_fill(0, 4, $mvCell));
            }
            $this->mcBBlockTo($mbX * 16, $mbY * 16, 16, 16, $mvX, $mvY, $list, $refIdx);
            // 保存双 list 网格（未用 list 填 [0,0,-1]）与 B 专用块缓存；
            // 0x08 为 16x16 分区标记（供后续 MV 预测/去块区分分区尺寸）。
            $this->saveBListMvs($mbX, $gridL0, $gridL1);
            $this->storeBPicBlocks($mbX, $mbY, $gridL0, $gridL1, 0x08);
            $this->storeBDeblockInfo($mbX, $mbY, $gridL0, $gridL1);
            $this->cabStoreMvd($list, $mbIdx, 0, 0, 4, 4, $mvdX, $mvdY);
        } elseif ($mbType === 3) {
            // B_Bi_16x16
            // 双向：ref_l0 → ref_l1，随后 l0 的 mvd(2 分量) 与 l1 的 mvd(2 分量)。
            $ref0 = $this->cabReadRefIdx(0, 0, true);
            $ref1 = $this->cabReadRefIdx(1, 0, true);
            // ref 读后立即 fill ref_cache 4x4
            $this->cabOvRefBox(0, 0, 0, 4, 4, $ref0);
            $this->cabOvRefBox(1, 0, 0, 4, 4, $ref1);
            [$px0, $py0] = $this->getB16x16MvPrediction($mbX, $mbY, 0, $ref0);
            [$px1, $py1] = $this->getB16x16MvPrediction($mbX, $mbY, 1, $ref1);
            $mvd0X = $this->cabReadMvd(0, 0, 0);
            $mvd0Y = $this->cabReadMvd(0, 1, 0);
            $mvd1X = $this->cabReadMvd(1, 0, 0);
            $mvd1Y = $this->cabReadMvd(1, 1, 0);
            // 两个 list 的 MV 各自等于“预测值 + mvd”。
            $mv0X = $px0 + $mvd0X; $mv0Y = $py0 + $mvd0Y;
            $mv1X = $px1 + $mvd1X; $mv1Y = $py1 + $mvd1Y;
            $gridL0 = array_fill(0, 4, array_fill(0, 4, [$mv0X, $mv0Y, $ref0]));
            $gridL1 = array_fill(0, 4, array_fill(0, 4, [$mv1X, $mv1Y, $ref1]));
            $this->performBiPredBlock($mbX * 16, $mbY * 16, 16, 16, $mv0X, $mv0Y, $ref0, $mv1X, $mv1Y, $ref1);
            $this->saveBListMvs($mbX, $gridL0, $gridL1);
            $this->storeBPicBlocks($mbX, $mbY, $gridL0, $gridL1, 0x08);
            $this->storeBDeblockInfo($mbX, $mbY, $gridL0, $gridL1);
            $this->cabStoreMvd(0, $mbIdx, 0, 0, 4, 4, $mvd0X, $mvd0Y);
            $this->cabStoreMvd(1, $mbIdx, 0, 0, 4, 4, $mvd1X, $mvd1Y);
        } elseif ($mbType <= 21) {
            // 16x8 / 8x16 双分区（mbType 4..21，镜像 decodeBBipart + CABAC 语法）
            // bPartSpecs[mbType] = [layout, dir0, dir1]，dir: 0=L0,1=L1,2=Bi。
            $spec = self::$bPartSpecs[$mbType];
            $layout = $spec[0]; // 0=16x8, 1=8x16
            // 每个分区的 raster 锚点块：16x8 为左上/左下，8x16 为左上/右上。
            $ns = $layout === 0 ? [0, 8] : [0, 2]; // raster anchor（8x16 p1=(2,0)）

            // ref：list 主序，Bi(2) 分区双向都读
            // $spec = [layout, dir(part0), dir(part1)]；dir 取 0=L0、1=L1、2=Bi。
            // refs[list][part] = -1 表示该 list 不用于该分区（后续跳过 mvd/MC）。
            $refs = [[-1, -1], [-1, -1]];
            for ($list = 0; $list < 2; $list++) {
                for ($p = 0; $p < 2; $p++) {
                    $dir = $spec[1 + $p];
                    if ($dir === $list || $dir === 2) {
                        $refs[$list][$p] = $this->cabReadRefIdx($list, $ns[$p], true);
                        // 每分区 ref 读后立即 fill ref_cache（part1 的 ref ctx 可见 part0）
                        if ($layout === 0) {
                            $this->cabOvRefBox($list, 0, $p * 2, 4, 2, $refs[$list][$p]);
                        } else {
                            $this->cabOvRefBox($list, $p * 2, 0, 2, 4, $refs[$list][$p]);
                        }
                    }
                }
            }
            // mvd：list 主序，逐分区 pred + mvd
            $mvs = [[null, null], [null, null]];
            for ($list = 0; $list < 2; $list++) {
                for ($p = 0; $p < 2; $p++) {
                    if ($refs[$list][$p] < 0) {
                        continue;
                    }
                    if ($layout === 0) {
                        [$px, $py] = $this->getB16x8MvPrediction($mbX, $mbY, $p, $list, $refs[$list][$p], $mvs[$list][0]);
                    } else {
                        [$px, $py] = $this->getB8x16MvPrediction($mbX, $mbY, $p, $list, $refs[$list][$p], $mvs[$list][0]);
                    }
                    $mvdX = $this->cabReadMvd($list, 0, $ns[$p]);
                    $mvdY = $this->cabReadMvd($list, 1, $ns[$p]);
                    // fill_rectangle 回填 mv_cache 时 ref 一并写入，part1 的
                    // B/A 邻居（mv_cache[20]/[13]）能读到 part0 的 ref；须带 ref 分量
                    $mvs[$list][$p] = [$px + $mvdX, $py + $mvdY, $refs[$list][$p]];
                    $bx0 = ($layout === 0 ? 0 : $p * 2) + ($ns[$p] & 3);
                    $by0 = ($layout === 0 ? $p * 2 : 0) + ($ns[$p] >> 2);
                    $bw = $layout === 0 ? 4 : 2;
                    $bh = $layout === 0 ? 2 : 4;
                    $storeBx = $layout === 0 ? 0 : $p * 2;
                    $storeBy = $layout === 0 ? $p * 2 : 0;
                    $this->cabStoreMvd($list, $mbIdx, $storeBx, $storeBy, $bw, $bh, $mvdX, $mvdY);
                }
            }
            // MC + 网格
            $gridL0 = array_fill(0, 4, array_fill(0, 4, [0, 0, -1]));
            $gridL1 = array_fill(0, 4, array_fill(0, 4, [0, 0, -1]));
            for ($p = 0; $p < 2; $p++) {
                $dir = $spec[1 + $p];
                if ($layout === 0) {
                    $bx0 = 0; $by0 = $p * 2; $cols = 4; $rows = 2;
                } else {
                    $bx0 = $p * 2; $by0 = 0; $cols = 2; $rows = 4;
                }
                $mv0 = ($dir === 0 || $dir === 2) ? $mvs[0][$p] : null;
                $mv1 = ($dir === 1 || $dir === 2) ? $mvs[1][$p] : null;
                $dstX = $mbX * 16 + $bx0 * 4;
                $dstY = $mbY * 16 + $by0 * 4;
                $w = $cols * 4;
                $h = $rows * 4;
                if ($mv0 !== null && $mv1 !== null) {
                    $this->performBiPredBlock($dstX, $dstY, $w, $h, $mv0[0], $mv0[1], $refs[0][$p], $mv1[0], $mv1[1], $refs[1][$p]);
                } elseif ($mv0 !== null) {
                    $this->mcBBlockTo($dstX, $dstY, $w, $h, $mv0[0], $mv0[1], 0, $refs[0][$p]);
                } elseif ($mv1 !== null) {
                    $this->mcBBlockTo($dstX, $dstY, $w, $h, $mv1[0], $mv1[1], 1, $refs[1][$p]);
                }
                for ($y = $by0; $y < $by0 + $rows; $y++) {
                    for ($x = $bx0; $x < $bx0 + $cols; $x++) {
                        $gridL0[$y][$x] = $mv0 !== null ? [$mv0[0], $mv0[1], $refs[0][$p]] : [0, 0, -1];
                        $gridL1[$y][$x] = $mv1 !== null ? [$mv1[0], $mv1[1], $refs[1][$p]] : [0, 0, -1];
                    }
                }
            }
            $this->saveBListMvs($mbX, $gridL0, $gridL1);
            $this->storeBPicBlocks($mbX, $mbY, $gridL0, $gridL1, $layout === 0 ? 0x10 : 0x20);
            $this->storeBDeblockInfo($mbX, $mbY, $gridL0, $gridL1);
        } else {
            // B_8x8（mbType 22）
            // 子宏块语法（sub_mb_type/direct/ref/mvd）必须无条件读完，否则引擎失步。
            // 禁止写成 $dct8Allowed && $this->decodeCabacB8x8(...)：transform8x8ModeFlag=false
            // 时 && 短路会跳过整个子宏块语法读取（sub_mb_type/ref/mvd），CABAC 引擎立即失步。
            $b8x8Dct8Ok = $this->decodeCabacB8x8($mbX, $mbY, $mbIdx);
            $dct8Allowed = $dct8Allowed && $b8x8Dct8Ok;
            $cbpLuma = $this->cabReadCbpLuma();
            $cbpChroma = $this->cabReadCbpChroma();
            $dct8 = false;
            if ($dct8Allowed && ($cbpLuma & 15) !== 0 && $this->cabGet(399 + $this->cabNeighborTransformSize()) !== 0) {
                $dct8 = true;
            }
            $cbp = $cbpLuma | ($cbpChroma << 4);
            // cbp_table 在残差解码前赋值（色度 DC/亮度 DC 的 CBF 标志位在其上 OR）
            $this->picCbp[$mbIdx] = $cbp;
            if ($cbp !== 0) {
                $this->cabReadQpDelta();
            } else {
                $this->cabLastQpDiff = 0;
            }
            $this->cabDecodeInterResidual($mbX, $mbY, $cbpLuma, $cbpChroma, $dct8);
            $this->cabFinishInterMb($mbX, $mbY, $cbp, $dct8, $mbType);
            return;
        }

        // ---------- 16x16/16x8/8x16 类型的 CBP/qp/残差/簿记 ----------
        // 注意：B_8x8 分支已在上方自行读取同一组语法并 return，
        // 因此这里只处理 16x16 / 16x8 / 8x16 三种情况。
        $cbpLuma = $this->cabReadCbpLuma();
        $cbpChroma = $this->cabReadCbpChroma();
        $dct8 = false;
        if ($dct8Allowed && ($cbpLuma & 15) !== 0 && $this->cabGet(399 + $this->cabNeighborTransformSize()) !== 0) {
            $dct8 = true;
        }
        $cbp = $cbpLuma | ($cbpChroma << 4);
        // cbp_table 在残差解码前赋值（色度 DC/亮度 DC 的 CBF 标志位在其上 OR）
        $this->picCbp[$mbIdx] = $cbp;
        if ($cbp !== 0) {
            $this->cabReadQpDelta();
        } else {
            $this->cabLastQpDiff = 0;
        }
        $this->cabDecodeInterResidual($mbX, $mbY, $cbpLuma, $cbpChroma, $dct8);
        $this->cabFinishInterMb($mbX, $mbY, $cbp, $dct8, $mbType);
    }

    /**
     * B mb_type 是否使用某 list（CABAC 可达类型 4..21）
     * 4,5 L0_L0；6,7 L1_L1；8..21 双 list
     *
     * 用途：16x8/8x16 分区读取 ref/mvd 时，仅对实际使用的 list 读语法，
     * 从而保证与编码端的语法顺序一致。
     *
     * @param int $mbType B 的 mb_type（4..21）
     * @param int $list   0=L0 1=L1
     * @return bool 该 list 是否参与本 MB
     */
    private function bMbUsesList(int $mbType, int $list): bool
    {
        if ($mbType <= 5) {
            return $list === 0;
        }
        if ($mbType <= 7) {
            return $list === 1;
        }
        return true;
    }

    /**
     * B_8x8 宏块（CABAC，镜像 CAVLC decodeB8x8：sub types → direct 预测 → refs → mvd → 统一 MC）。
     *
     * 语法读取顺序必须与编码端一致：4×sub_mb_type → direct 推导（无语法）→
     * 按 list 主序读各象限 ref_idx → 按象限/分区读 mvd → 统一按分区执行 MC。
     *
     * @param int $mbX   宏块列号
     * @param int $mbY   宏块行号
     * @param int $mbIdx 宏块 raster 索引
     * @return bool 8x8DCT 是否允许
     */
    private function decodeCabacB8x8(int $mbX, int $mbY, int $mbIdx): bool
    {
        // 4 个 sub_mb_type（条款 7.3.5.2），按 8x8 象限顺序读取。
        $subTypes = [
            $this->cabReadBSubMbType(),
            $this->cabReadBSubMbType(),
            $this->cabReadBSubMbType(),
            $this->cabReadBSubMbType(),
        ];
        // 是否存在 direct 子块（sub_mb_type=0 即 B_Direct_8x8）。
        $hasDirect = $subTypes[0] === 0 || $subTypes[1] === 0
            || $subTypes[2] === 0 || $subTypes[3] === 0;

        // 8x8DCT：仅当所有子分区为 8x8 级（≤3），且 direct 子块需要 direct_8x8_inference
        $dct8Allowed = $this->transform8x8ModeFlag
            && (!$hasDirect || $this->direct8x8InferenceFlag)
            && $subTypes[0] <= 3 && $subTypes[1] <= 3
            && $subTypes[2] <= 3 && $subTypes[3] <= 3;

        // mbMvsL0/L1：逐 4x4 的 [mvX, mvY, ref]，null 表示尚未确定；
        // gridL0/L1：最终回填给运动补偿与邻居缓存的网格（ref=-1 表示该 list 未用）。
        $mbMvsL0 = array_fill(0, 4, array_fill(0, 4, null));
        $mbMvsL1 = array_fill(0, 4, array_fill(0, 4, null));
        $gridL0 = array_fill(0, 4, array_fill(0, 4, [0, 0, -1]));
        $gridL1 = array_fill(0, 4, array_fill(0, 4, [0, 0, -1]));

        // direct_cache 按 8x8 象限 fill（direct 象限标记，B 片 ref ctx 排除 +
        // 写回 direct_table 供后续 MB 边界 ctx 使用）
        for ($i = 0; $i < 4; $i++) {
            $this->picDirectQ[$mbIdx * 4 + $i] = ($subTypes[$i] === 0) ? 1 : 0;
        }

        // direct 子块：空间/时间 direct 预测（无语法），并同步进 mbMvs 供后续预测
        // directSpatialMvPred=0 时走时间 direct（以同位帧 MV 缩放），否则用空间邻居推导。
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
            for ($i = 0; $i < 4; $i++) {
                if ($subTypes[$i] !== 0) {
                    continue;
                }
                $partX = ($i & 1) * 2;
                $partY = ($i >> 1) * 2;
                // pred_direct_motion：direct 象限 mv_cache 双 list 无条件 2x2 fill
                //（后续非 direct 象限 pred_motion 的 A/B 邻居可读到 direct MV/ref）
                for ($y = $partY; $y < $partY + 2; $y++) {
                    for ($x = $partX; $x < $partX + 2; $x++) {
                        $mbMvsL0[$y][$x] = $gridL0[$y][$x];
                        $mbMvsL1[$y][$x] = $gridL1[$y][$x];
                        $this->cabMbDirOv[$y * 4 + $x] = 1;
                    }
                }
            }
        }

        // ref：list 主序，跳过 direct，未使用该 list 的子块填 -1
        // bSubUsesL0/L1 给出各子类型实际参与的方向，避免多读/漏读 ref_idx。
        $refs = [[-1, -1, -1, -1], [-1, -1, -1, -1]];
        $b8RefN = [0, 2, 8, 10]; // 各 8x8 象限首块 raster 块号
        for ($list = 0; $list < 2; $list++) {
            for ($i = 0; $i < 4; $i++) {
                $st = $subTypes[$i];
                if ($st === 0 || $st < 0 || $st >= 13) {
                    continue;
                }
                if (($list === 0 && self::$bSubUsesL0[$st]) || ($list === 1 && self::$bSubUsesL1[$st])) {
                    $refs[$list][$i] = $this->cabReadRefIdx($list, $b8RefN[$i], true);
                    // 每象限 ref 读后立即 fill ref_cache 2x2（后续象限 ctx 可见）
                    $this->cabOvRefBox($list, ($i & 1) * 2, ($i >> 1) * 2, 2, 2, $refs[$list][$i]);
                }
            }
        }

        // mvd：list 主序，逐子块逐分区 pred + mvd
        // 说明：list 外层循环保证同一子块的 L0、L1 语法按 list 分开读取；
        // 每个分区的 pred 使用已写入 mbMvs 的前序分区作为邻居（条款 8.4.1.3）。
        // 与 P 片不同，这里的分区方向由 bSubUsesL0/L1 决定：不参与的 list 不读语法。
        for ($list = 0; $list < 2; $list++) {
            // 注意：必须引用绑定（PHP 数组值语义/COW，值拷贝写入不会传回 $mbMvsL0/L1，
            // 导致跨象限 MV 预测邻居与网格回填全部丢失非 direct MV）
            if ($list === 0) {
                $mbMvs = &$mbMvsL0;
            } else {
                $mbMvs = &$mbMvsL1;
            }
            for ($i = 0; $i < 4; $i++) {
                $st = $subTypes[$i];
                if ($st === 0 || $st < 0 || $st >= 13) {
                    continue;   // direct 象限已由 direct fill 无条件写入
                }
                $partX = ($i & 1) * 2;
                $partY = ($i >> 1) * 2;
                if ($refs[$list][$i] < 0) {
                    // 未用该 list 的非 direct 象限 mv_cache 填 0（ref=-1）
                    //——后续象限 pred_motion 将其视为"可用但不匹配"而非不可用
                    for ($y = $partY; $y < $partY + 2; $y++) {
                        for ($x = $partX; $x < $partX + 2; $x++) {
                            $mbMvs[$y][$x] = [0, 0, -1];
                        }
                    }
                    continue;
                }
                $partCount = self::$bSubPartCount[$st];
                $refIdx = $refs[$list][$i];
                for ($j = 0; $j < $partCount; $j++) {
                    [$bx, $by, $cols, $rows] = $this->cabSubPartGeometry($st, $i, $j, true);
                    [$px, $py] = $this->predictMvP8x8(
                        $mbX, $mbY, $bx, $by, $cols,
                        $mbMvs, $this->bMvTopRow[$list], $this->bMvLeftCol[$list], $refIdx, $i
                    );
                    $n = $by * 4 + $bx;
                    $mvdX = $this->cabReadMvd($list, 0, $n);
                    $mvdY = $this->cabReadMvd($list, 1, $n);
                    $mv = [$px + $mvdX, $py + $mvdY, $refIdx];
                    for ($yy = 0; $yy < $rows; $yy++) {
                        for ($xx = 0; $xx < $cols; $xx++) {
                            $mbMvs[$by + $yy][$bx + $xx] = $mv;
                        }
                    }
                    $this->cabStoreMvd($list, $mbIdx, $bx, $by, $cols, $rows, $mvdX, $mvdY);
                }
            }
        }
        unset($mbMvs);

        // 网格回填（direct 子块已在 grid 中，非 direct 从 mbMvs 回填）
        // 逐 4x4 判断：只有非 null 的项才覆盖 grid，避免把 direct 推导结果冲掉。
        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                if ($mbMvsL0[$y][$x] !== null) {
                    $gridL0[$y][$x] = $mbMvsL0[$y][$x];
                }
                if ($mbMvsL1[$y][$x] !== null) {
                    $gridL1[$y][$x] = $mbMvsL1[$y][$x];
                }
            }
        }

        // 运动补偿（双 list MV 均已就绪，按分区统一处理）
        for ($i = 0; $i < 4; $i++) {
            $st = $subTypes[$i];
            if ($st < 0 || $st >= 13) {
                continue;
            }
            $partCount = self::$bSubPartCount[$st];
            for ($j = 0; $j < $partCount; $j++) {
                [$bx, $by, $cols, $rows] = $this->cabSubPartGeometry($st, $i, $j, true);
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

        // 保存双 list 的逐 4x4 MV/ref（供后续 MB 的 MV 预测），
        // 并写入 B 片专用块缓存；0x40 为 B_8x8 的类型标记。
        $this->saveBListMvs($mbX, $gridL0, $gridL1);
        $this->storeBPicBlocks($mbX, $mbY, $gridL0, $gridL1, 0x40);
        $this->storeBDeblockInfo($mbX, $mbY, $gridL0, $gridL1);
        return $dct8Allowed;
    }
}
