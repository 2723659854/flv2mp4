<?php

namespace Xiaosongshu\Flv2mp4\Codec\Decode;

/**
 * @purpose 去块滤波器
 * @author yanglong
 * @time 2026年7月23日14:39:31
 */
/**
 * 去块滤波（Deblocking Filter）实现，对应 H.264 规范 8.7 节。
 *
 * 整体流程：
 * 1) 8.7.2 推导每条 4x4 块边缘（含宏块边界与宏块内部的 4x4 边界）的边界强度 bS；
 * 2) 8.7.2.3 / 8.7.2.4 由 QP 与片级偏移查出阈值 alpha、beta 与 tc0；
 * 3) 8.7.1 按 bS 与阈值判定该边是否需要滤波，并分别对亮度（bs<4 常规滤波、
 *    bs==4 强滤波）与色度（仅 bs<4 的常规滤波、bs==4 的强滤波）修改 p0/p1/p2、q0/q1/q2。
 *
 * 本 trait 只做「像素域」处理，不做 MV 预测、不做熵解码；它依赖解码阶段写好的
 * 簿记数组（mbTypeForDeblock / mbNnzForDeblock / mbMvForDeblock / mbRefForDeblock 等）。
 */
trait DeblockingFilterTrait
{

    // 片头 slice_alpha_c0_offset_div2 换算出的 alpha/tc0 偏移，规范中记为 FilterOffsetA，
    // 计算方式为 slice_alpha_c0_offset_div2 << 1（调用方已完成乘 2）。
    public int $sliceAlphaC0Offset = 0;
    // 片头 slice_beta_offset_div2 换算出的 beta 偏移（FilterOffsetB，同样为 << 1 后的值）。
    public int $sliceBetaOffset = 0;
    // 片头 disable_deblocking_filter_idc：1 表示整片关闭去块滤波（规范 7.4.3 / 8.7.1）。
    public int $disableDeblockingFilterIdc = 0;
    // 每个宏块的 mb_type（原始值），供 8.7.2 判定 intra / I_PCM / 非 intra。
    public array $mbTypeForDeblock = [];
    // 每个宏块的 QP（亮度 QP），供 8.7.2.3 的 QPav 与 8.7.2.4 的 QP 使用。
    public array $mbQpForDeblock = [];
    /**
     * 去块簿记的参考索引 → 参考帧唯一 id（L0/L1），片头参考列表构建后填充。
     * 参考实现 check_mv 比较的是 ref2frm 映射后的帧号（同一参考帧跨列表同号），
     * 直接比较原始索引会把 B 片 L0[0] / L1[0]（通常是不同帧）误判为相同。
     */
    public array $deblockRefIdL0 = [];
    public array $deblockRefIdL1 = [];
    /** CABAC 内嵌 intra 语法 mb_type 的片类型编号偏移：I=0 / P=5 / B=23 */
    private int $cabDeblockTypeOffset = 0;

    /** @var int[] 运动向量缺失时复用的零值，避免在热循环中重复创建数组。 */
    private array $deblockZeroMv = [0, 0];

    // 亮度平面行跨度（字节/像素个数），applyDeblockingFilter 中按帧宽赋值，避免方法内反复读取属性。
    private int $deblockYStride = 0;
    // 色度平面行跨度（宽度的一半），供色度滤波使用。
    private int $deblockUvStride = 0;
    // alpha/beta 查表缓存：key = qp，value = [alpha, beta]，见 8.7.2.3。
    private array $deblockThresholdCache = [];
    // tc0 查表缓存：key = (qp << 2) | bs，见 8.7.2.4。
    private array $deblockTc0Cache = [];
    // 亮度 QP → 色度 QP 的映射缓存（Table 8-15，见 getChromaQp）。
    private array $deblockChromaQpCache = [];
    /**
     * 按 qp 预聚合的滤波参数元组（热路径每帧上万次边调用，避免重复查表与小array字面量分配）：
     * 亮度 [alpha, beta, tc0(bs1), tc0(bs2), tc0(bs3)]
     * @var array<int,array{0:int,1:int,2:int,3:int,4:int}>
     */
    private array $deblockLumaQpTuple = [];
    /** 色度 [alpha, beta, tc(bs1), tc(bs2), tc(bs3)]（tc 已含 +1） */
    private array $deblockChromaQpTuple = [];

    /**
     * 取当前帧亮度 qp 对应的参数元组 [alpha, beta, tc0(bs=1), tc0(bs=2), tc0(bs=3)]。
     * 结果只依赖 qp 与片级 alpha/beta 偏移，因此可跨宏块、跨帧复用（缓存 key 由三者复合而成）。
     * 对应 8.7.2.3（alpha/beta）与 8.7.2.4（tc0）。
     */
    /** 当前帧亮度 qp 元组（alpha/beta/tc0，仅依赖 qp 与 slice offset） */
    private function lumaQpTuple(int $qp): array
    {
        // 复合 key：高 16 位放 qp，中间 8 位放 alpha 偏移（+12 使其非负），低 8 位放 beta 偏移。
        $key = ($qp << 16) | ((($this->sliceAlphaC0Offset + 12) & 0xFF) << 8) | ($this->sliceBetaOffset + 12);
        // 未命中缓存时才真正查表，命中直接返回已有元组。
        if (!isset($this->deblockLumaQpTuple[$key])) {
            $alpha = $beta = 0;
            // 8.7.2.3：由 qp + FilterOffsetA / FilterOffsetB 分别索引 ALPHA_TABLE / BETA_TABLE。
            $this->getThresholds($qp, $this->sliceAlphaC0Offset, $this->sliceBetaOffset, $alpha, $beta);
            // 一次性把 alpha、beta 与 bs=1/2/3 三档 tc0 打包成数组，后续按索引直接取用。
            $this->deblockLumaQpTuple[$key] = [
                $alpha,
                $beta,
                $this->getTc0($qp, $this->sliceAlphaC0Offset, 1),
                $this->getTc0($qp, $this->sliceAlphaC0Offset, 2),
                $this->getTc0($qp, $this->sliceAlphaC0Offset, 3),
            ];
        }
        return $this->deblockLumaQpTuple[$key];
    }

    /**
     * 色度滤波参数元组，key 为【已换算的色度 QP】（调用方负责 getChromaQp）：
     * [alpha, beta, tc(bs1), tc(bs2), tc(bs3)]（tc 已按规范 +1）
     */
    /**
     * 色度参数元组：色度滤波使用色度 QP（经 Table 8-15 映射），且 8.7.2.4 规定
     * 色度的 tc 在 tc0 基础上加 1（tc = tc0 + 1），此处已内联完成。
     */
    private function chromaQpTuple(int $chromaQp): array
    {
        // 复合 key：低 8 位放色度 qp，中间/高位分别放 alpha、beta 偏移，避免不同片参数互相污染。
        $key = $chromaQp | (($this->sliceAlphaC0Offset + 12) << 8) | (($this->sliceBetaOffset + 12) << 16);
        // 缓存未命中才查表。
        if (!isset($this->deblockChromaQpTuple[$key])) {
            $alpha = $beta = 0;
            // 8.7.2.3：色度同样用 alpha/beta 表，只是索引的 QP 换成色度 QP。
            $this->getThresholds($chromaQp, $this->sliceAlphaC0Offset, $this->sliceBetaOffset, $alpha, $beta);
            // 三档 tc 均取 tc0 + 1（规范中色度 tc 的固定增量）。
            $this->deblockChromaQpTuple[$key] = [
                $alpha,
                $beta,
                $this->getTc0($chromaQp, $this->sliceAlphaC0Offset, 1) + 1,
                $this->getTc0($chromaQp, $this->sliceAlphaC0Offset, 2) + 1,
                $this->getTc0($chromaQp, $this->sliceAlphaC0Offset, 3) + 1,
            ];
        }
        return $this->deblockChromaQpTuple[$key];
    }

    /**
     * 规范中的 Clip3(lo, hi, x)：把 x 限制在 [lo, hi] 闭区间内。
     * 用于 QP/索引运算以及像素值裁剪（替代多次 if 判断，语义与 8.7.1 的 Clip3 一致）。
     */
    private function clip3(int $lo, int $hi, int $x): int
    {
        // 低于下界直接取 lo。
        if ($x < $lo) return $lo;
        // 高于上界直接取 hi。
        if ($x > $hi) return $hi;
        // 在区间内原样返回。
        return $x;
    }

    /**
     * 像素值裁剪到 8bit 无符号范围 [0, 255]（4:2:0、8bit 采样）。
     */
    private function clipPixel(int $x): int
    {
        // 等价于 Clip3(0, 255, x)。
        return $this->clip3(0, 255, $x);
    }

    /**
     * 求 alpha、beta 阈值，对应 8.7.2.3：
     *   indexA = Clip3(0, 51, qP + FilterOffsetA)
     *   indexB = Clip3(0, 51, qP + FilterOffsetB)
     *   alpha = alpha_table[indexA]，beta = beta_table[indexB]
     * 结果按 qp 缓存；注意缓存 key 只含 qp，因此同一帧内片级偏移必须保持一致（调用方约定）。
     */
    private function getThresholds(int $qp, int $alphaOffset, int $betaOffset, int &$alpha, int &$beta): void
    {
        // 命中缓存：直接解构出 [alpha, beta] 并返回。
        if (isset($this->deblockThresholdCache[$qp])) {
            [$alpha, $beta] = $this->deblockThresholdCache[$qp];
            return;
        }

        // alpha 的查表索引：QP 加片级 alpha 偏移后夹到 0..51（8.7.2.3）。
        $indexA = $this->clip3(0, 51, $qp + $alphaOffset);
        // beta 的查表索引：QP 加片级 beta 偏移后夹到 0..51（8.7.2.3）。
        $indexB = $this->clip3(0, 51, $qp + $betaOffset);
        // 查表得到 alpha（表列于规范表 8-16）。
        $alpha = self::ALPHA_TABLE[$indexA];
        // 查表得到 beta（表列于规范表 8-17）。
        $beta = self::BETA_TABLE[$indexB];
        // 回填缓存，后续同 qp 直接复用。
        $this->deblockThresholdCache[$qp] = [$alpha, $beta];
    }

    /**
     * 求 tc0，对应 8.7.2.4：
     *   indexA = Clip3(0, 51, qP + FilterOffsetA)
     *   tc0 = tc0_table[indexA][bS - 1]（bS 取 1..3）
     * 这里把 bS 也编码进缓存 key（低 2 位）。
     */
    private function getTc0(int $qp, int $alphaOffset, int $bs): int
    {
        // key = qp 左移 2 位后或上 bS，保证不同 bS 档位互不覆盖。
        $key = ($qp << 2) | $bs;
        // 命中缓存直接返回。
        if (isset($this->deblockTc0Cache[$key])) {
            return $this->deblockTc0Cache[$key];
        }

        // tc0 的索引与 alpha 共用同一条公式（8.7.2.4 引用 8.7.2.3 的 indexA）。
        $indexA = $this->clip3(0, 51, $qp + $alphaOffset);
        // 表项按 bS 分三档（bS=1/2/3），数组下标为 bS-1。
        $tc0 = self::TC0_TABLE[$indexA][$bs - 1];
        // 回填缓存。
        $this->deblockTc0Cache[$key] = $tc0;
        return $tc0;
    }

    /**
     * 亮度 QP 映射为色度 QP：index = Clip3(0, 51, QP + chroma_qp_index_offset)，
     * 再经 Table 8-15（CHROMA_QP_TABLE）映射，8.7.2 中色度 bS 与阈值推导需要色度 QP。
     */
    private function getChromaQp(int $qp): int
    {
        // 命中缓存直接返回映射结果。
        if (isset($this->deblockChromaQpCache[$qp])) {
            return $this->deblockChromaQpCache[$qp];
        }

        // 先加色度偏移并夹到 0..51 得到表索引。
        $chromaQpIndex = $this->clip3(
            0,
            51,
            $qp + $this->chromaQpIndexOffset
        );
        // 查 Table 8-15 得到实际色度 QP。
        $chromaQp = self::CHROMA_QP_TABLE[$chromaQpIndex];
        // 回填缓存。
        $this->deblockChromaQpCache[$qp] = $chromaQp;
        return $chromaQp;
    }

    /**
     * 求宏块边界的平均 QP：QPav = (QPp + QPq + 1) >> 1（规范 8.7.2.3 中 QPav 的定义）。
     * 宏块边界两侧 QP 可能不同，需要用两侧平均；宏块内部边只用当前 MB 的 QP。
     */
    private function avgQp(int $qpP, int $qpQ): int
    {
        // 加 1 后右移实现四舍五入。
        return (int)(($qpP + $qpQ + 1) >> 1);
    }

    /**
     * 亮度强滤波（bS == 4），对应 8.7.2.5.1 / 8.7.2.5.2 / 8.7.2.5.3 的强滤波分支：
     * 常规滤波只改 p0/q0（bS<4 时还改 p1/q1），而强滤波会按规范同时改写 p0、p1、p2 与 q0、q1、q2。
     * 返回值表示本次是否满足强滤波的三个门限条件（不满足则整条边不滤波）。
     *
     * 注意：规范要求只有当 |p0-q0| < alpha、|p1-p0| < beta、|q1-q0| < beta 同时成立才进入强滤波，
     * 否则跳过（返回 false）。
     */
    private function filterStrongLuma(int $p0, int $p1, int $p2, int $p3, int $q0, int $q1, int $q2, int $q3, int $alpha, int $beta,
                                      &$newP0, &$newP1, &$newP2, &$newQ0, &$newQ1, &$newQ2): bool
    {
        // 8.7.1 的滤波判决：三个差值门限任一不满足则不滤波。
        if (abs($p0 - $q0) >= $alpha || abs($p1 - $p0) >= $beta || abs($q1 - $q0) >= $beta) {
            return false;
        }

        // |p2-p0|、|q2-q0| 用于决定强滤波是否延伸到 p1/q1（规范中条件为 < beta）。
        $ap = abs($p2 - $p0);
        $aq = abs($q2 - $q0);
        // 「小块间隙」条件 |p0-q0| < (alpha/4 + 2)：决定强滤波中是否使用包含 p2/q2 的 4 抽头组合。
        $smallGap = abs($p0 - $q0) < (($alpha >> 2) + 2);

        // 小块间隙时：两侧各用 3 个新样点（p2/p1/p0 与 q2/q1/q0）。
        if ($smallGap) {
            // p 侧：|p2-p0| < beta 才能使用 4 抽头（含 p3）更新 p2；否则退化为较弱组合。
            if ($ap < $beta) {
                // p0' = (p2 + 2*p1 + 2*p0 + 2*q0 + q1 + 4) >> 3（8.7.2.5.1 强滤波公式）。
                $newP0 = (int)((($p2 + 2 * $p1 + 2 * $p0 + 2 * $q0 + $q1 + 4) >> 3) & 0xFF);
                // p1' = (p2 + p1 + p0 + q0 + 2) >> 2（8.7.2.5.2 强滤波公式）。
                $newP1 = (int)((($p2 + $p1 + $p0 + $q0 + 2) >> 2) & 0xFF);
                // p2' = (2*p3 + 3*p2 + p1 + p0 + q0 + 4) >> 3（8.7.2.5.3 强滤波公式）。
                $newP2 = (int)((($p3 * 2 + 3 * $p2 + $p1 + $p0 + $q0 + 4) >> 3) & 0xFF);
            } else {
                // p2 与 p0 相差较大：只对 p0 做较弱更新，p1/p2 保持原值。
                $newP0 = (int)((($p1 * 2 + $p0 + $q1 + 2) >> 2) & 0xFF);
                // p1 原样输出（& 0xFF 只是统一裁剪写法）。
                $newP1 = $p1 & 0xFF;
                // p2 原样输出。
                $newP2 = $p2 & 0xFF;
            }

            // q 侧：|q2-q0| < beta 才能使用 4 抽头（含 q3）更新 q2。
            if ($aq < $beta) {
                // q0' = (p1 + 2*p0 + 2*q0 + 2*q1 + q2 + 4) >> 3。
                $newQ0 = (int)((($p1 + 2 * $p0 + 2 * $q0 + 2 * $q1 + $q2 + 4) >> 3) & 0xFF);
                // q1' = (p0 + q0 + q1 + q2 + 2) >> 2。
                $newQ1 = (int)((($p0 + $q0 + $q1 + $q2 + 2) >> 2) & 0xFF);
                // q2' = (2*q3 + 3*q2 + q1 + q0 + p0 + 4) >> 3。
                $newQ2 = (int)((($q3 * 2 + 3 * $q2 + $q1 + $q0 + $p0 + 4) >> 3) & 0xFF);
            } else {
                // q2 与 q0 相差较大：只更新 q0，q1/q2 保持原值。
                $newQ0 = (int)((($q1 * 2 + $q0 + $p1 + 2) >> 2) & 0xFF);
                // q1 原样输出。
                $newQ1 = $q1 & 0xFF;
                // q2 原样输出。
                $newQ2 = $q2 & 0xFF;
            }
        } else {
            // 非小块间隙：强滤波退化为常规滤波的 p0/q0 更新公式，p1/p2、q1/q2 不变。
            $newP0 = (int)((($p1 * 2 + $p0 + $q1 + 2) >> 2) & 0xFF);
            $newP1 = $p1 & 0xFF;
            $newP2 = $p2 & 0xFF;
            $newQ0 = (int)((($q1 * 2 + $q0 + $p1 + 2) >> 2) & 0xFF);
            $newQ1 = $q1 & 0xFF;
            $newQ2 = $q2 & 0xFF;
        }

        // 返回 true 表示调用方可以把这些新样点写回图像平面。
        return true;
    }

    /**
     * 色度强滤波（bS == 4）：色度只更新 p0/q0（规范 8.7.2.5.1 的色度强滤波公式），
     * 门限判决与亮度一致：|p0-q0| < alpha、|p1-p0| < beta、|q1-q0| < beta。
     */
    private function filterStrongChroma(int $p0, int $p1, int $q0, int $q1, int $alpha, int $beta,
                                        &$newP0, &$newQ0): bool
    {
        // 三个门限任一不满足则不做滤波。
        if (abs($p0 - $q0) >= $alpha || abs($p1 - $p0) >= $beta || abs($q1 - $q0) >= $beta) {
            return false;
        }

        // p0' = (2*p1 + p0 + q1 + 2) >> 2（8.7.2.5.1 色度强滤波）。
        $newP0 = (int)((($p1 * 2 + $p0 + $q1 + 2) >> 2) & 0xFF);
        // q0' = (2*q1 + q0 + p1 + 2) >> 2。
        $newQ0 = (int)((($q1 * 2 + $q0 + $p1 + 2) >> 2) & 0xFF);

        // 表示本次滤波生效。
        return true;
    }

    /**
     * 对一条「垂直边」做亮度滤波，对应 8.7.1（先垂直边后水平边、亮度先于色度）。
     * 一条垂直边由 4 个 4x4 块高度组成（$i = 0..3），每个位置再沿 y 方向处理 4 行（$d = 0..3）。
     *
     * @param int   $mbX  当前宏块列号（以宏块为单位）
     * @param int   $mbY  当前宏块行号
     * @param int   $edge 0 表示宏块左边界，1..3 表示宏块内部第 1..3 条 4x4 垂直边
     * @param array $bs   该边的 4 个边界强度（垂直方向 4 个 4x4 块各自一个 bS）
     * @param int   $qp   该边使用的 QP（宏块边界为两侧平均 QP，内部边为当前 MB QP）
     */
    private function filterVerticalLuma(int $mbX, int $mbY, int $edge, array $bs, int $qp): void
    {
        // 取该 QP 对应的 [alpha, beta, tc0(bs1..3)] 元组（8.7.2.3/8.7.2.4）。
        $qt = $this->lumaQpTuple($qp);
        // alpha 或 beta 为 0 时（极低 QP）规范判定不会有任何滤波，直接整条边跳过。
        if ($qt[0] == 0 || $qt[1] == 0) {
            return;
        }
        // alpha 门限。
        $alpha = $qt[0];
        // beta 门限。
        $beta = $qt[1];

        // 亮度平面行跨度。
        $stride = $this->deblockYStride;
        // 以引用方式取得亮度平面，避免每次写入都做数组拷贝。
        $plane = &$this->yPlane;
        // 边起点：宏块左上像素 + 边在 x 方向的偏移（edge*4 像素）；p 侧在 off-1，q 侧在 off。
        $base = ($mbY * 16) * $stride + ($mbX * 16) + $edge * 4;

        // 沿边方向遍历 4 个 4x4 块（每个块对应一个 bS）。
        for ($i = 0; $i < 4; $i++) {
            // 当前 4x4 块的边界强度。
            $curBs = $bs[$i];
            // bS == 0 表示该段不滤波（8.7.2 边界强度推导结果为 0）。
            if ($curBs == 0) {
                continue;
            }
            // 按 bS 选 tc0：bS=1/2/3 对应元组下标 2/3/4；bS=4（强滤波）不需要 tc，置 0。
            $tc0 = $curBs === 1 ? $qt[2] : ($curBs === 2 ? $qt[3] : ($curBs === 3 ? $qt[4] : 0));
            // 该 4x4 块的首个像素偏移：沿 y 方向下移 i*4 行。
            $off = $base + $i * 4 * $stride;

            // 沿 y 方向逐行处理 4 个像素对（p0/q0 分别在 off-1、off）。
            for ($d = 0; $d < 4; $d++, $off += $stride) {
                // 惰性读取：高运动场景多数像素第一关即失败，只需 2 次读取而非 6 次
                // p0 为边左侧相邻像素，q0 为边右侧像素（8.7.1 的 p0/q0 定义）。
                $p0 = $plane[$off - 1];
                // q0 取边右侧第一个像素。
                $q0 = $plane[$off];

                // bS < 4：常规滤波（8.7.2.5.1 / 8.7.2.5.2），最多改到 p1/q1。
                if ($curBs < 4) {
                    // 第一道门限：|p0-q0| < alpha，不满足则整条边该行不滤波。
                    if (abs($p0 - $q0) < $alpha) {
                        // 通过首道门限后才读取 p1/q1（惰性求值，减少数组访问）。
                        $p1 = $plane[$off - 2];
                        // q1 位于 off+1。
                        $q1 = $plane[$off + 1];
                        // 第二道门限：|p1-p0| < beta 且 |q1-q0| < beta。
                        if (abs($p1 - $p0) < $beta && abs($q1 - $q0) < $beta) {
                            // tc 初值取 tc0（bS<4 时 tc = tc0，后续每多修改一侧 p1/q1 就加 1，见 8.7.2.5）。
                            $tc = $tc0;
                            // 先假定 p1/q1 不变，最后按是否变化决定是否写回。
                            $newP1 = $p1;
                            // 同理假定 q1 不变。
                            $newQ1 = $q1;
                            // p0/q0 的平均值，供 p1/q1 更新公式使用：((p0 + q0 + 1) >> 1)。
                            $p0q0 = ($p0 + $q0 + 1) >> 1;

                            // 读取 p2 以判断是否修改 p1（8.7.2.5.2 的条件 |p2-p0| < beta）。
                            $p2 = $plane[$off - 3];
                            // 满足 |p2-p0| < beta 才更新 p1。
                            if (abs($p2 - $p0) < $beta) {
                                // tc0==0 时 clamp 到 [-0,0] 恒为 0，newP1 不变，无需 guard
                                // 计算差值 (p2 + p0q0)/2 - p1，再夹到 [-tc0, tc0]。
                                $__v = (($p2 + $p0q0) >> 1) - $p1;
                                // p1' = p1 + Clip3(-tc0, tc0, 差值)，对应 8.7.2.5.2。
                                $newP1 = $p1 + ($__v < -$tc0 ? -$tc0 : ($__v > $tc0 ? $tc0 : $__v));
                                // p 侧发生修改，tc 自增（规范：每修改一侧 p1 或 q1，tc 加 1）。
                                $tc++;
                            }

                            // 读取 q2，用于同样的 q1 更新判定。
                            $q2 = $plane[$off + 2];
                            // |q2-q0| < beta 时更新 q1。
                            if (abs($q2 - $q0) < $beta) {
                                // 差值 (q2 + p0q0)/2 - q1。
                                $__v = (($q2 + $p0q0) >> 1) - $q1;
                                // q1' = q1 + Clip3(-tc0, tc0, 差值)。
                                $newQ1 = $q1 + ($__v < -$tc0 ? -$tc0 : ($__v > $tc0 ? $tc0 : $__v));
                                // q 侧发生修改，tc 自增。
                                $tc++;
                            }

                            // p0/q0 的基础差值：((q0-p0)*4 + (p1-q1) + 4) >> 3（8.7.2.5.1）。
                            $__v = (($q0 - $p0) * 4 + ($p1 - $q1) + 4) >> 3;
                            // delta = Clip3(-tc, tc, 基础差值)。
                            $delta = $__v < -$tc ? -$tc : ($__v > $tc ? $tc : $__v);
                            // delta==0 时 p0/q0 不变（约三成像素），省两次字符串写
                            // 仅在 delta 非零时才写回，减少热循环中的写入次数。
                            if ($delta !== 0) {
                                // p0' = p0 + delta。
                                $__v = $p0 + $delta;
                                // 写回 p0，并裁剪到 [0,255]（内联的 Clip3 写法）。
                                $plane[$off - 1] = $__v < 0 ? 0 : ($__v > 255 ? 255 : $__v);
                                // q0' = q0 - delta（对称修正）。
                                $__v = $q0 - $delta;
                                // 写回 q0 并裁剪。
                                $plane[$off] = $__v < 0 ? 0 : ($__v > 255 ? 255 : $__v);
                            }
                            // p1 确实发生变化时才写回（节省一次数组赋值）。
                            if ($newP1 !== $p1) {
                                $plane[$off - 2] = $newP1;
                            }
                            // q1 确实发生变化时才写回。
                            if ($newQ1 !== $q1) {
                                $plane[$off + 1] = $newQ1;
                            }
                        }
                    }
                } else {
                    // bS == 4：强滤波（8.7.2.5.1~8.7.2.5.3），需要读取两侧各 4 个样点 p3..p0 / q0..q3。
                    $p1 = $plane[$off - 2];
                    $q1 = $plane[$off + 1];
                    $p2 = $plane[$off - 3];
                    $q2 = $plane[$off + 2];
                    // p3 只在强滤波的 p2' 公式中出现（|p2-p0| < beta 时）。
                    $p3 = $plane[$off - 4];
                    // q3 只在强滤波的 q2' 公式中出现。
                    $q3 = $plane[$off + 3];
                    // 调用强滤波函数，内部先做门限判决；返回 true 表示新样点有效。
                    if ($this->filterStrongLuma(
                        $p0, $p1, $p2, $p3, $q0, $q1, $q2, $q3, $alpha, $beta,
                        $newP0, $newP1, $newP2, $newQ0, $newQ1, $newQ2
                    )) {
                        // 写回 p0'、p1'、p2'（8.7.2.5.1/8.7.2.5.2/8.7.2.5.3）。
                        $plane[$off - 1] = $newP0;
                        $plane[$off - 2] = $newP1;
                        $plane[$off - 3] = $newP2;
                        // 写回 q0'、q1'、q2'。
                        $plane[$off] = $newQ0;
                        $plane[$off + 1] = $newQ1;
                        $plane[$off + 2] = $newQ2;
                    }
                }
            }
        }
    }

    /**
     * 对一条「水平边」做亮度滤波，逻辑与 filterVerticalLuma 完全对称，
     * 区别仅在于相邻样点沿行方向偏移（用 $stride 步进），8.7.1 规定先处理垂直边再处理水平边。
     *
     * @param int   $edge 0 表示宏块上边界，1..3 表示宏块内部第 1..3 条 4x4 水平边
     * @param array $bs   该边 4 个 4x4 块各自的 bS
     * @param int   $qp   该边使用的 QP
     */
    private function filterHorizontalLuma(int $mbX, int $mbY, int $edge, array $bs, int $qp): void
    {
        // 取参数元组，alpha/beta 为 0 时整条边跳过。
        $qt = $this->lumaQpTuple($qp);
        if ($qt[0] == 0 || $qt[1] == 0) {
            return;
        }
        $alpha = $qt[0];
        $beta = $qt[1];

        $stride = $this->deblockYStride;
        $plane = &$this->yPlane;
        // 水平边起点：沿 y 下移 edge*4 行，x 方向从宏块左边界开始；p 侧在 off-stride，q 侧在 off。
        $base = ($mbY * 16 + $edge * 4) * $stride + ($mbX * 16);

        // 遍历边上 4 个 4x4 块（x 方向）。
        for ($i = 0; $i < 4; $i++) {
            $curBs = $bs[$i];
            // bS 为 0 的段不滤波。
            if ($curBs == 0) {
                continue;
            }
            // 按 bS 取 tc0（bS=4 强滤波不用 tc）。
            $tc0 = $curBs === 1 ? $qt[2] : ($curBs === 2 ? $qt[3] : ($curBs === 3 ? $qt[4] : 0));
            // 该 4x4 块首像素：沿 x 右移 i*4 个像素。
            $off = $base + $i * 4;

            // 沿 x 方向逐列处理 4 个像素对。
            for ($d = 0; $d < 4; $d++, $off++) {
                // 惰性读取：高运动场景多数像素第一关即失败
                // p0 位于上一行（off - stride），q0 位于当前行。
                $p0 = $plane[$off - $stride];
                $q0 = $plane[$off];

                // 常规滤波分支（bS < 4）。
                if ($curBs < 4) {
                    // 门限 1：|p0-q0| < alpha。
                    if (abs($p0 - $q0) < $alpha) {
                        // p1 在上两行，q1 在下一行。
                        $p1 = $plane[$off - 2 * $stride];
                        $q1 = $plane[$off + $stride];
                        // 门限 2：|p1-p0| < beta 且 |q1-q0| < beta。
                        if (abs($p1 - $p0) < $beta && abs($q1 - $q0) < $beta) {
                            // tc 初值为 tc0。
                            $tc = $tc0;
                            // 默认 p1/q1 不变。
                            $newP1 = $p1;
                            $newQ1 = $q1;
                            // p0/q0 平均，用于 p1/q1 更新。
                            $p0q0 = ($p0 + $q0 + 1) >> 1;

                            // p2 位于上三行，|p2-p0| < beta 时才更新 p1。
                            $p2 = $plane[$off - 3 * $stride];
                            if (abs($p2 - $p0) < $beta) {
                                // 差值 (p2 + p0q0)/2 - p1。
                                $__v = (($p2 + $p0q0) >> 1) - $p1;
                                // p1' = p1 + Clip3(-tc0, tc0, 差值)。
                                $newP1 = $p1 + ($__v < -$tc0 ? -$tc0 : ($__v > $tc0 ? $tc0 : $__v));
                                // tc 自增。
                                $tc++;
                            }

                            // q2 位于下两行，|q2-q0| < beta 时才更新 q1。
                            $q2 = $plane[$off + 2 * $stride];
                            if (abs($q2 - $q0) < $beta) {
                                // 差值 (q2 + p0q0)/2 - q1。
                                $__v = (($q2 + $p0q0) >> 1) - $q1;
                                // q1' = q1 + Clip3(-tc0, tc0, 差值)。
                                $newQ1 = $q1 + ($__v < -$tc0 ? -$tc0 : ($__v > $tc0 ? $tc0 : $__v));
                                // tc 自增。
                                $tc++;
                            }

                            // p0/q0 的基础差值公式（8.7.2.5.1，水平方向同理）。
                            $__v = (($q0 - $p0) * 4 + ($p1 - $q1) + 4) >> 3;
                            // Clip3(-tc, tc, ...)。
                            $delta = $__v < -$tc ? -$tc : ($__v > $tc ? $tc : $__v);
                            // delta 非零才写回。
                            if ($delta !== 0) {
                                // p0' = p0 + delta。
                                $__v = $p0 + $delta;
                                // 写回 p0（上邻行）并裁剪。
                                $plane[$off - $stride] = $__v < 0 ? 0 : ($__v > 255 ? 255 : $__v);
                                // q0' = q0 - delta。
                                $__v = $q0 - $delta;
                                // 写回 q0 并裁剪。
                                $plane[$off] = $__v < 0 ? 0 : ($__v > 255 ? 255 : $__v);
                            }
                            // p1 有变化才写回。
                            if ($newP1 !== $p1) {
                                $plane[$off - 2 * $stride] = $newP1;
                            }
                            // q1 有变化才写回。
                            if ($newQ1 !== $q1) {
                                $plane[$off + $stride] = $newQ1;
                            }
                        }
                    }
                } else {
                    // bS == 4 强滤波：读取两侧 p3..p0 与 q0..q3。
                    $p1 = $plane[$off - 2 * $stride];
                    $q1 = $plane[$off + $stride];
                    $p2 = $plane[$off - 3 * $stride];
                    $q2 = $plane[$off + 2 * $stride];
                    $p3 = $plane[$off - 4 * $stride];
                    $q3 = $plane[$off + 3 * $stride];
                    // 门限满足则得到 p0'..p2'、q0'..q2'。
                    if ($this->filterStrongLuma(
                        $p0, $p1, $p2, $p3, $q0, $q1, $q2, $q3, $alpha, $beta,
                        $newP0, $newP1, $newP2, $newQ0, $newQ1, $newQ2
                    )) {
                        // 写回 p0'、p1'、p2'。
                        $plane[$off - $stride] = $newP0;
                        $plane[$off - 2 * $stride] = $newP1;
                        $plane[$off - 3 * $stride] = $newP2;
                        // 写回 q0'、q1'、q2'。
                        $plane[$off] = $newQ0;
                        $plane[$off + $stride] = $newQ1;
                        $plane[$off + 2 * $stride] = $newQ2;
                    }
                }
            }
        }
    }

    /**
     * 垂直边的色度滤波（8.7.1 中色度滤波在亮度之后进行）。
     * 4:2:0 下色度分辨率是亮度的一半：每个 4x4 亮度边对应 2 行色度样点（$d = 0..1），
     * 相邻色度样点间距为 1 像素。U、V 两个平面用同一组 bS 与同一色度 QP 分别处理。
     *
     * @param int   $edge 0 表示宏块左边界（对应色度 x 偏移 0），1 表示内部第 2 条 4x4 边（即色度 edge 1）
     * @param array $bs   亮度 4x4 边上的 4 个 bS
     * @param int   $qp   已换算好的色度 QP
     */
    private function filterVerticalChroma(int $mbX, int $mbY, int $edge, array $bs, int $qp): void
    {
        // 色度参数元组（tc 已含 +1）。
        $qt = $this->chromaQpTuple($qp);
        // alpha/beta 为 0 时无需滤波。
        if ($qt[0] == 0 || $qt[1] == 0) {
            return;
        }
        $alpha = $qt[0];
        $beta = $qt[1];

        // 色度平面行跨度。
        $stride = $this->deblockUvStride;
        // 色度宏块起点：8x8 色度块 + edge*4 的 x 偏移。
        $base = ($mbY * 8) * $stride + ($mbX * 8) + $edge * 4;

        // U、V 两个平面名称；static 避免在热路径中反复构造数组。
        static $planeNames = ['uPlane', 'vPlane'];
        // 依次处理 U、V 平面。
        foreach ($planeNames as $planeName) {
            // 引用取得当前色度平面。
            $plane = &$this->$planeName;
            // 沿边方向 4 个 4x4 块（各自 bS）。
            for ($i = 0; $i < 4; $i++) {
                $curBs = $bs[$i];
                // bS 为 0 不滤波。
                if ($curBs == 0) {
                    continue;
                }
                // 色度 tc 直接取元组中的值（已 +1）；bS=4 不用 tc。
                $tc = $curBs === 1 ? $qt[2] : ($curBs === 2 ? $qt[3] : ($curBs === 3 ? $qt[4] : 0));
                // 该 4x4 块起始：色度每个 4x4 块只有 2 行，故步进 i*2*stride。
                $off = $base + $i * 2 * $stride;

                // 每个 4x4 边只处理 2 个色度样点（4:2:0 的垂直方向抽样）。
                for ($d = 0; $d < 2; $d++, $off += $stride) {
                    // p0 在边左侧，q0 在边右侧。
                    $p0 = $plane[$off - 1];
                    $q0 = $plane[$off];

                    // bS < 4：色度常规滤波只更新 p0/q0（8.7.2.5.1）。
                    if ($curBs < 4) {
                        // 门限 1：|p0-q0| < alpha。
                        if (abs($p0 - $q0) < $alpha) {
                            // 读取 p1/q1。
                            $p1 = $plane[$off - 2];
                            $q1 = $plane[$off + 1];
                            // 门限 2：|p1-p0| < beta 且 |q1-q0| < beta。
                            if (abs($p1 - $p0) < $beta && abs($q1 - $q0) < $beta) {
                                // 基础差值公式，与亮度相同。
                                $__v = (($q0 - $p0) * 4 + ($p1 - $q1) + 4) >> 3;
                                // Clip3(-tc, tc, 差值)。
                                $delta = $__v < -$tc ? -$tc : ($__v > $tc ? $tc : $__v);
                                // delta==0 时不必写回。
                                if ($delta !== 0) {
                                    // p0' = p0 + delta。
                                    $__v = $p0 + $delta;
                                    // 写回并裁剪。
                                    $plane[$off - 1] = $__v < 0 ? 0 : ($__v > 255 ? 255 : $__v);
                                    // q0' = q0 - delta。
                                    $__v = $q0 - $delta;
                                    // 写回并裁剪。
                                    $plane[$off] = $__v < 0 ? 0 : ($__v > 255 ? 255 : $__v);
                                }
                            }
                        }
                    } else {
                        // bS == 4：色度强滤波，只更新 p0/q0。
                        $p1 = $plane[$off - 2];
                        $q1 = $plane[$off + 1];
                        // 门限判决 + 计算新样点。
                        if ($this->filterStrongChroma(
                            $p0, $p1, $q0, $q1, $alpha, $beta, $newP0, $newQ0
                        )) {
                            // 写回 p0'、q0'。
                            $plane[$off - 1] = $newP0;
                            $plane[$off] = $newQ0;
                        }
                    }
                }
            }
            // 解除引用，避免后续循环误改当前平面变量。
            unset($plane);
        }
    }

    /**
     * 水平边的色度滤波，与 filterVerticalChroma 对称：
     * 相邻样点沿行方向以 $stride 步进，每条边处理 2 行色度（$d = 0..1）。
     */
    private function filterHorizontalChroma(int $mbX, int $mbY, int $edge, array $bs, int $qp): void
    {
        // 取色度参数元组。
        $qt = $this->chromaQpTuple($qp);
        // alpha/beta 为 0 直接返回。
        if ($qt[0] == 0 || $qt[1] == 0) {
            return;
        }
        $alpha = $qt[0];
        $beta = $qt[1];

        $stride = $this->deblockUvStride;
        // 水平边起点：色度 y 方向偏移 edge*4 行。
        $base = ($mbY * 8 + $edge * 4) * $stride + ($mbX * 8);

        // U、V 两个平面。
        static $planeNamesH = ['uPlane', 'vPlane'];
        foreach ($planeNamesH as $planeName) {
            $plane = &$this->$planeName;
            // 边上 4 个 4x4 块。
            for ($i = 0; $i < 4; $i++) {
                $curBs = $bs[$i];
                // bS 为 0 的段跳过。
                if ($curBs == 0) {
                    continue;
                }
                // 取色度 tc（已含 +1）。
                $tc = $curBs === 1 ? $qt[2] : ($curBs === 2 ? $qt[3] : ($curBs === 3 ? $qt[4] : 0));
                // 色度块宽 2 个样点，故 x 方向步进 i*2。
                $off = $base + $i * 2;

                // 每条边 2 个色度样点。
                for ($d = 0; $d < 2; $d++, $off++) {
                    // p0 在上一行，q0 在当前行。
                    $p0 = $plane[$off - $stride];
                    $q0 = $plane[$off];

                    // 常规滤波（bS < 4）。
                    if ($curBs < 4) {
                        // 门限 1：|p0-q0| < alpha。
                        if (abs($p0 - $q0) < $alpha) {
                            // p1 在上两行，q1 在下一行。
                            $p1 = $plane[$off - 2 * $stride];
                            $q1 = $plane[$off + $stride];
                            // 门限 2：|p1-p0| < beta 且 |q1-q0| < beta。
                            if (abs($p1 - $p0) < $beta && abs($q1 - $q0) < $beta) {
                                // 基础差值。
                                $__v = (($q0 - $p0) * 4 + ($p1 - $q1) + 4) >> 3;
                                // 夹到 [-tc, tc]。
                                $delta = $__v < -$tc ? -$tc : ($__v > $tc ? $tc : $__v);
                                // 非零才写回。
                                if ($delta !== 0) {
                                    // p0' = p0 + delta。
                                    $__v = $p0 + $delta;
                                    // 写回 p0 并裁剪。
                                    $plane[$off - $stride] = $__v < 0 ? 0 : ($__v > 255 ? 255 : $__v);
                                    // q0' = q0 - delta。
                                    $__v = $q0 - $delta;
                                    // 写回 q0 并裁剪。
                                    $plane[$off] = $__v < 0 ? 0 : ($__v > 255 ? 255 : $__v);
                                }
                            }
                        }
                    } else {
                        // bS == 4 色度强滤波。
                        $p1 = $plane[$off - 2 * $stride];
                        $q1 = $plane[$off + $stride];
                        if ($this->filterStrongChroma(
                            $p0, $p1, $q0, $q1, $alpha, $beta, $newP0, $newQ0
                        )) {
                            // 写回 p0'、q0'。
                            $plane[$off - $stride] = $newP0;
                            $plane[$off] = $newQ0;
                        }
                    }
                }
            }
            unset($plane);
        }
    }

    /**
     * B 片边界强度的运动判定，语义与参考实现 check_mv 一致：
     * 先比 list0 参考索引；仅当索引相同且不为 -1（表示该 list 未使用）时才比 list0 MV；
     * list0 未判出差异时再比 list1；一旦判出差异转入跨列表判定——跨列表参考索引
     * 不一致即判差异，否则只按跨列表 MV 极差判定。
     */
    /**
     * 上述判定的规范依据：8.7.2 边界强度推导中「两 4x4 块的运动向量或参考索引不同」即 bS=1，
     * B 片需要同时考虑 L0/L1 两个预测列表以及跨列表的比较。
     */
    private function bMotionBoundaryDiffers(
        int $pRef0, array $pMv0, int $qRef0, array $qMv0,
        int $pRef1, array $pMv1, int $qRef1, array $qMv1
    ): bool {
        // 先比较 L0 参考索引是否不同。
        $diff = $pRef0 !== $qRef0;
        // 索引相同且不是 -1（-1 表示该块没有使用该列表）时才比较 L0 的 MV 分量。
        if (!$diff && $pRef0 !== -1) {
            // MV 任一分量差 >= 4（1/4 像素精度下即 1 个整像素）视为不同，规范用 >= 4 判定。
            $diff = abs($pMv0[0] - $qMv0[0]) >= 4 || abs($pMv0[1] - $qMv0[1]) >= 4;
        }
        // L0 未判出差异，再用 L1 的参考索引与 MV 判定。
        if (!$diff) {
            $diff = $pRef1 !== $qRef1
                || abs($pMv1[0] - $qMv1[0]) >= 4
                || abs($pMv1[1] - $qMv1[1]) >= 4;
        }
        // 两个列表都一致，则运动信息相同，bS 不因运动而置 1。
        if (!$diff) {
            return false;
        }
        // 已判出差异，再确认是否属于「跨列表同参考帧但 MV 不同」的情况：
        // 跨列表参考索引不一致即认定运动信息不同。
        if ($pRef0 !== $qRef1 || $pRef1 !== $qRef0) {
            return true;
        }
        // 跨列表参考一致时，只比较跨列表的 MV 分量差（任一 >= 4 即不同）。
        return abs($pMv0[0] - $qMv1[0]) >= 4
            || abs($pMv0[1] - $qMv1[1]) >= 4
            || abs($pMv1[0] - $qMv0[0]) >= 4
            || abs($pMv1[1] - $qMv0[1]) >= 4;
    }

    /**
     * 推导当前宏块 4 条垂直边、4 条水平边上每个 4x4 块的边界强度 bS，
     * 对应规范 8.7.2（亮度 bS 推导；色度沿用同一组 bS）。
     *
     * 规范规则（每一对相邻 4x4 块 p、q）：
     * - 若任一侧块所在 MB 为 intra 编码：宏块边界 bS = 4，宏块内部边 bS = 3；
     * - 否则若任一侧块存在非零变换系数（nnz != 0）：bS = 2；
     * - 否则若两侧参考索引不同、或 MV 分量差 >= 4：bS = 1；
     * - 其余情况 bS = 0（不滤波）。
     *
     * I_PCM 块（intra 的真子集但不产生残差）按规范不参与滤波，代码用 !ipcm 直接跳过。
     *
     * 输出形状：$bsVertical[$edge][$pair]、$bsHorizontal[$edge][$pair]，
     * edge=0 为宏块边界，edge=1..3 为宏块内部边；pair 为沿边方向第几个 4x4 块。
     */
    private function computeBoundaryStrengths(int $mbX, int $mbY, array &$bsVertical, array &$bsHorizontal): void
    {
        // 图像宏块列数，用于由 mbX/mbY 计算一维宏块下标。
        $mbWidth = $this->picWidthInMbs;
        // 当前宏块的一维下标（光栅顺序）。
        $mbIdx = $mbY * $mbWidth + $mbX;
        // 当前宏块类型；缺失时用 -1 表示不可用（后续 $curType < 0 会跳过整个 MB）。
        $curType = $this->mbTypeForDeblock[$mbIdx] ?? -1;
        // 当前宏块 16 个 4x4 块的 nnz 数组（亮度，按 4x4 光栅顺序）。
        $curNnz = $this->mbNnzForDeblock[$mbIdx] ?? [];
        // 当前宏块 16 个 4x4 块的 L0 MV。
        $curMv = $this->mbMvForDeblock[$mbIdx] ?? [];
        // 当前宏块 16 个 4x4 块的 L0 参考索引（已由 mapDeblockRefIds 映射为帧 id）。
        $curRef = $this->mbRefForDeblock[$mbIdx] ?? [];
        // 当前宏块 16 个 4x4 块的 L1 MV（仅 B 片使用）。
        $curMv1 = $this->mbMvL1ForDeblock[$mbIdx] ?? [];
        // 当前宏块 16 个 4x4 块的 L1 参考索引（仅 B 片使用）。
        $curRef1 = $this->mbRefL1ForDeblock[$mbIdx] ?? [];
        // 当前片的 slice_type 编码（0=P,1=B,2=I,5=P(all),4=I(all) 等编码方式）。
        $sliceType = $this->currentSliceType;
        // 是否为 I 片（2 = I，4 = I 且所有宏块同类型）。
        $isIslice = ($sliceType === 2 || $sliceType === 4);
        // 是否为 P 片（0 = P，5 = P 且所有宏块同类型）。
        $isPslice = ($sliceType === 0 || $sliceType === 5);
        // 是否为 B 片。
        $isBslice = ($sliceType === 1);
        // 当前宏块是否为 intra 编码：按片类型对应的 mb_type 编号区间判定
        // （I 片 0..24、P 片 5..29、B 片 23..48，编号 25/30/48 为 I_PCM，另由 $curIpcm 区分）。
        $curIntra = $isIslice
            ? ($curType >= 0 && $curType <= 24)
            : ($isPslice ? ($curType >= 5 && $curType <= 29)
                : ($isBslice && $curType >= 23 && $curType <= 48));
        // 当前宏块是否为 I_PCM（I 片 25、P 片 30、B 片 48）；I_PCM 不参与滤波。
        $curIpcm = ($isIslice && $curType === 25) || ($isPslice && $curType === 30) || ($isBslice && $curType === 48);

        // 左邻宏块的默认值：无左邻时视为与当前 MB 相同（该边界不会被使用）。
        $leftIntra = $curIntra;
        $leftIpcm = $curIpcm;
        $leftNnz = $leftMv = $leftRef = [];
        // 存在左邻宏块（mbX > 0）时才读取其簿记信息。
        if ($mbX > 0) {
            // 左邻一维下标。
            $leftIdx = $mbIdx - 1;
            // 左邻宏块类型。
            $leftType = $this->mbTypeForDeblock[$leftIdx] ?? -1;
            // 左邻 nnz。
            $leftNnz = $this->mbNnzForDeblock[$leftIdx] ?? [];
            // 左邻 L0 MV。
            $leftMv = $this->mbMvForDeblock[$leftIdx] ?? [];
            // 左邻 L0 参考索引。
            $leftRef = $this->mbRefForDeblock[$leftIdx] ?? [];
            // 左邻 L1 MV。
            $leftMv1 = $this->mbMvL1ForDeblock[$leftIdx] ?? [];
            // 左邻 L1 参考索引。
            $leftRef1 = $this->mbRefL1ForDeblock[$leftIdx] ?? [];
            // 左邻是否 intra（区间判定与前文一致）。
            $leftIntra = $isIslice
                ? ($leftType >= 0 && $leftType <= 24)
                : ($isPslice ? ($leftType >= 5 && $leftType <= 29)
                    : ($isBslice && $leftType >= 23 && $leftType <= 48));
            // 左邻是否 I_PCM。
            $leftIpcm = ($isIslice && $leftType === 25) || ($isPslice && $leftType === 30) || ($isBslice && $leftType === 48);
        }

        // 上邻宏块的默认值：无上邻时视为与当前 MB 相同。
        $topIntra = $curIntra;
        $topIpcm = $curIpcm;
        $topNnz = $topMv = $topRef = [];
        // 存在上邻宏块（mbY > 0）时才读取。
        if ($mbY > 0) {
            // 上邻一维下标：同一列、上一行。
            $topIdx = $mbIdx - $mbWidth;
            // 上邻宏块类型。
            $topType = $this->mbTypeForDeblock[$topIdx] ?? -1;
            // 上邻 nnz。
            $topNnz = $this->mbNnzForDeblock[$topIdx] ?? [];
            // 上邻 L0 MV。
            $topMv = $this->mbMvForDeblock[$topIdx] ?? [];
            // 上邻 L0 参考索引。
            $topRef = $this->mbRefForDeblock[$topIdx] ?? [];
            // 上邻 L1 MV。
            $topMv1 = $this->mbMvL1ForDeblock[$topIdx] ?? [];
            // 上邻 L1 参考索引。
            $topRef1 = $this->mbRefL1ForDeblock[$topIdx] ?? [];
            // 上邻是否 intra。
            $topIntra = $isIslice
                ? ($topType >= 0 && $topType <= 24)
                : ($isPslice ? ($topType >= 5 && $topType <= 29)
                    : ($isBslice && $topType >= 23 && $topType <= 48));
            // 上邻是否 I_PCM。
            $topIpcm = ($isIslice && $topType === 25) || ($isPslice && $topType === 30) || ($isBslice && $topType === 48);
        }

        // 快速路径（与规范判定逐位等价的充分条件）：当前 MB 及左/上邻块均为
        // 非 intra / 非 I_PCM，参与 BS 判定的 luma 4x4 块 nnz 全为 0，且当前 MB
        // 全部 16 块与左/上边界块的 MV 全等、参考索引相同 → 32 个边界强度均为 0。
        // B 片扩展：额外要求 L1 参考与 L0 统一（ref0==ref1，保证跨列表比较
        // pRef0==qRef1 / pRef1==qRef0 恒真）且 L1 MV 并入同一极差统计（极差<=3
        // 保证 bMotionBoundaryDiffers 的全部跨列表分量差 < 4）→ 全部 bS=0。
        // 命中条件不满足时落回原逐对判定，结果完全一致。
        // 该快速路径对应 8.7.2 中 bS 必定为 0 的情形（无 intra、无残差、运动一致）。
        if (!$curIntra && !$curIpcm
            && ($mbX === 0 || (!$leftIntra && !$leftIpcm))
            && ($mbY === 0 || (!$topIntra && !$topIpcm))
        ) {
            // 先假设所有块 nnz 均为 0（bS=2 的必要条件不成立）。
            $allZero = true;
            // 检查当前宏块 16 个 4x4 块的 nnz。
            for ($k = 0; $k < 16; $k++) {
                if (($curNnz[$k] ?? 0) != 0) {
                    // 出现非零系数，bS 可能为 2，快速路径失效。
                    $allZero = false;
                    break;
                }
            }
            // 检查左邻参与垂直跨宏块边的 4 个块（3,7,11,15 为左邻最右列）。
            if ($allZero && $mbX > 0) {
                foreach ([3, 7, 11, 15] as $k) {
                    if (($leftNnz[$k] ?? 0) != 0) {
                        $allZero = false;
                        break;
                    }
                }
            }
            // 检查上邻参与水平跨宏块边的 4 个块（12..15 为上邻最下一行）。
            if ($allZero && $mbY > 0) {
                foreach ([12, 13, 14, 15] as $k) {
                    if (($topNnz[$k] ?? 0) != 0) {
                        $allZero = false;
                        break;
                    }
                }
            }

            // nnz 全部为 0 后，继续验证运动信息是否完全一致。
            if ($allZero) {
                // 以块 0 的 MV 作为极差统计的初值（缺失时用零向量）。
                $zeroMv = $curMv[0] ?? $this->deblockZeroMv;
                // x 分量极差区间。
                $minX = $maxX = $zeroMv[0];
                // y 分量极差区间。
                $minY = $maxY = $zeroMv[1];
                // 以块 0 的参考索引作为统一参考（缺失按 0 处理）。
                $zeroRef = $curRef[0] ?? 0;
                // 假设运动信息一致。
                $uniform = true;
                // B 片还要把块 0 的 L1 MV 纳入极差统计。
                if ($isBslice) {
                    // 块 0 的 L1 MV 同样参与极差统计（内部边界配对会涉及块 0）
                    $m1 = $curMv1[0] ?? $this->deblockZeroMv;
                    $x = $m1[0];
                    $y = $m1[1];
                    // 更新 x 分量极差下界。
                    if ($x < $minX) {
                        $minX = $x;
                    } elseif ($x > $maxX) {
                        // 更新 x 分量极差上界。
                        $maxX = $x;
                    }
                    // 更新 y 分量极差下界。
                    if ($y < $minY) {
                        $minY = $y;
                    } elseif ($y > $maxY) {
                        // 更新 y 分量极差上界。
                        $maxY = $y;
                    }
                }

                // cur 内部 24 对相邻边：16 个块参考索引全等、且 MV 分量极差 <= 3
                // （任意相邻块分量差 <= 全局极差 < 4，与规范 bs=0 的 |Δmv| < 4 等价）。
                for ($k = 1; $k < 16; $k++) {
                    // 任意块参考索引与块 0 不同 → 运动信息不一致（bS 可能为 1）。
                    if (($curRef[$k] ?? 0) != $zeroRef) {
                        $uniform = false;
                        break;
                    }
                    // 取该块 MV，参与全局极差统计。
                    $m = $curMv[$k] ?? $this->deblockZeroMv;
                    $x = $m[0];
                    $y = $m[1];
                    // 更新 x 下界。
                    if ($x < $minX) {
                        $minX = $x;
                    } elseif ($x > $maxX) {
                        // 更新 x 上界。
                        $maxX = $x;
                    }
                    // 更新 y 下界。
                    if ($y < $minY) {
                        $minY = $y;
                    } elseif ($y > $maxY) {
                        // 更新 y 上界。
                        $maxY = $y;
                    }
                    // B 片额外验证 L1。
                    if ($isBslice) {
                        // L1 参考必须与 L0 同值（ref0==ref1==R），否则跨列表参考比较失败；
                        // L1 MV 并入同一极差，保证跨列表分量差也 < 4。
                        if (($curRef1[$k] ?? 0) != $zeroRef) {
                            $uniform = false;
                            break;
                        }
                        // 取 L1 MV 参与同一极差统计。
                        $m1 = $curMv1[$k] ?? $this->deblockZeroMv;
                        $x = $m1[0];
                        $y = $m1[1];
                        if ($x < $minX) {
                            $minX = $x;
                        } elseif ($x > $maxX) {
                            $maxX = $x;
                        }
                        if ($y < $minY) {
                            $minY = $y;
                        } elseif ($y > $maxY) {
                            $maxY = $y;
                        }
                    }
                }
                // 极差 > 3 时无法保证所有相邻对分量差 < 4，快速路径失效。
                if ($uniform && ($maxX - $minX > 3 || $maxY - $minY > 3)) {
                    $uniform = false;
                }
                // 垂直跨 MB 边：left(3,7,11,15) 配对 cur(0,4,8,12)
                // 即左邻最右列的 4 个块与当前 MB 最左列的 4 个块（宏块边界，bS 可能为 1）。
                if ($uniform && $mbX > 0) {
                    foreach ([3, 7, 11, 15] as $i => $k) {
                        // 当前 MB 最左列第 i 个块的下标：0,4,8,12。
                        $qk = $i << 2;
                        // p 侧（左邻）MV。
                        $pM = $leftMv[$k] ?? $this->deblockZeroMv;
                        // q 侧（当前 MB）MV。
                        $qM = $curMv[$qk] ?? $this->deblockZeroMv;
                        // 参考索引不同或 MV 分量差 >= 4 → 运动信息不一致。
                        if (($leftRef[$k] ?? 0) != ($curRef[$qk] ?? 0)
                            || abs($pM[0] - $qM[0]) >= 4
                            || abs($pM[1] - $qM[1]) >= 4) {
                            $uniform = false;
                            break;
                        }
                        // B 片还需检查 L1 与跨列表组合。
                        if ($isBslice) {
                            // L0 检查已保证 pRef0 == qRef0 == R == qRef1（cur 参考统一），
                            // 下方 L1 检查保证 pRef1 == qRef1 == R == qRef0，
                            // 故跨列表参考比较恒真，只需再查跨列表 MV 分量差。
                            $pM1 = $leftMv1[$k] ?? $this->deblockZeroMv;
                            $qM1 = $curMv1[$qk] ?? $this->deblockZeroMv;
                            if (($leftRef1[$k] ?? 0) != ($curRef1[$qk] ?? 0)
                                || abs($pM1[0] - $qM1[0]) >= 4
                                || abs($pM1[1] - $qM1[1]) >= 4
                                || abs($pM[0] - $qM1[0]) >= 4
                                || abs($pM[1] - $qM1[1]) >= 4
                                || abs($pM1[0] - $qM[0]) >= 4
                                || abs($pM1[1] - $qM[1]) >= 4) {
                                $uniform = false;
                                break;
                            }
                        }
                    }
                }
                // 水平跨 MB 边：top(12..15) 配对 cur(0..3)
                // 即上邻最下一行的 4 个块与当前 MB 最上一行的 4 个块。
                if ($uniform && $mbY > 0) {
                    foreach ([12, 13, 14, 15] as $i => $k) {
                        // p 侧（上邻）MV。
                        $pM = $topMv[$k] ?? $this->deblockZeroMv;
                        // q 侧（当前 MB 最上行）MV。
                        $qM = $curMv[$i] ?? $this->deblockZeroMv;
                        // 参考/运动不一致则失效。
                        if (($topRef[$k] ?? 0) != ($curRef[$i] ?? 0)
                            || abs($pM[0] - $qM[0]) >= 4
                            || abs($pM[1] - $qM[1]) >= 4) {
                            $uniform = false;
                            break;
                        }
                        // B 片检查 L1 与跨列表组合。
                        if ($isBslice) {
                            $pM1 = $topMv1[$k] ?? $this->deblockZeroMv;
                            $qM1 = $curMv1[$i] ?? $this->deblockZeroMv;
                            if (($topRef1[$k] ?? 0) != ($curRef1[$i] ?? 0)
                                || abs($pM1[0] - $qM1[0]) >= 4
                                || abs($pM1[1] - $qM1[1]) >= 4
                                || abs($pM[0] - $qM1[0]) >= 4
                                || abs($pM[1] - $qM1[1]) >= 4
                                || abs($pM1[0] - $qM[0]) >= 4
                                || abs($pM1[1] - $qM[1]) >= 4) {
                                $uniform = false;
                                break;
                            }
                        }
                    }
                }

                // 全部条件满足：32 条 4x4 边的 bS 均为 0，直接输出零矩阵并返回。
                if ($uniform) {
                    $bsVertical = [[0, 0, 0, 0], [0, 0, 0, 0], [0, 0, 0, 0], [0, 0, 0, 0]];
                    $bsHorizontal = [[0, 0, 0, 0], [0, 0, 0, 0], [0, 0, 0, 0], [0, 0, 0, 0]];
                    return;
                }
            }
        }

        // 通用路径：逐条边、逐对 4x4 块按 8.7.2 规则推导 bS。
        // $edge = 0..3：0 为宏块边界，1..3 为宏块内部边。
        for ($edge = 0; $edge < 4; $edge++) {
            // 本条垂直边 / 水平边的 4 个 bS，初值为 0。
            $vertical = $horizontal = [0, 0, 0, 0];
            // 沿边方向 4 对相邻 4x4 块。
            for ($pair = 0; $pair < 4; $pair++) {
                // 垂直边 q 侧块下标：列 = edge，行 = pair，故为 pair*4 + edge。
                $qIdx = ($pair << 2) + $edge;
                // p 侧块下标：宏块边界时取左邻最右列（pair*4 + 3），内部边取左侧相邻块 qIdx-1。
                $pIdx = $edge === 0 ? (($pair << 2) + 3) : $qIdx - 1;
                // p 侧是否 intra：宏块边界看左邻 MB，内部边看当前 MB。
                $pIntra = $edge === 0 ? $leftIntra : $curIntra;
                // p 侧是否 I_PCM。
                $pIpcm = $edge === 0 ? $leftIpcm : $curIpcm;
                // p 侧块的 nnz。
                $pNnz = $edge === 0 ? ($leftNnz[$pIdx] ?? 0) : ($curNnz[$pIdx] ?? 0);

                // I_PCM 块不参与滤波，两侧任一为 I_PCM 时 bS 保持 0。
                if (!$pIpcm && !$curIpcm) {
                    // 规则 1：任一侧 intra → 宏块边界 bS=4，内部边 bS=3。
                    if ($pIntra || $curIntra) {
                        $vertical[$pair] = $edge === 0 ? 4 : 3;
                    } elseif ($pNnz != 0 || ($curNnz[$qIdx] ?? 0) != 0) {
                        // 规则 2：任一侧存在非零变换系数 → bS=2。
                        $vertical[$pair] = 2;
                    } else {
                        // 规则 3：比较参考索引与 MV 判定是否 bS=1。
                        $pMv = $edge === 0 ? ($leftMv[$pIdx] ?? $this->deblockZeroMv) : ($curMv[$pIdx] ?? $this->deblockZeroMv);
                        // q 侧 MV。
                        $qMv = $curMv[$qIdx] ?? $this->deblockZeroMv;
                        // p 侧 L0 参考索引。
                        $pRef = $edge === 0 ? ($leftRef[$pIdx] ?? 0) : ($curRef[$pIdx] ?? 0);
                        // B 片走带跨列表判定的比较过程。
                        if ($isBslice) {
                            // p 侧 L1 MV。
                            $pMv1 = $edge === 0 ? ($leftMv1[$pIdx] ?? $this->deblockZeroMv) : ($curMv1[$pIdx] ?? $this->deblockZeroMv);
                            // q 侧 L1 MV。
                            $qMv1 = $curMv1[$qIdx] ?? $this->deblockZeroMv;
                            // p 侧 L1 参考索引。
                            $pRef1 = $edge === 0 ? ($leftRef1[$pIdx] ?? 0) : ($curRef1[$pIdx] ?? 0);
                            // q 侧 L1 参考索引。
                            $qRef1 = $curRef1[$qIdx] ?? 0;
                            // 运动信息不同 → bS=1。
                            if ($this->bMotionBoundaryDiffers($pRef, $pMv, $curRef[$qIdx] ?? 0, $qMv, $pRef1, $pMv1, $qRef1, $qMv1)) {
                                $vertical[$pair] = 1;
                            }
                        } elseif ($pRef != ($curRef[$qIdx] ?? 0)
                            || abs($pMv[0] - $qMv[0]) >= 4
                            || abs($pMv[1] - $qMv[1]) >= 4) {
                            // P/I 片：参考索引不同或 MV 分量差 >= 4 → bS=1。
                            $vertical[$pair] = 1;
                        }
                    }
                }

                // 水平边：q 侧块下标为 edge*4 + pair（行 = edge，列 = pair）。
                $qIdx = ($edge << 2) + $pair;
                // p 侧块下标：宏块边界时取上邻最下行（12 + pair），内部边取上一行块 qIdx-4。
                $pIdx = $edge === 0 ? 12 + $pair : $qIdx - 4;
                // p 侧是否 intra。
                $pIntra = $edge === 0 ? $topIntra : $curIntra;
                // p 侧是否 I_PCM。
                $pIpcm = $edge === 0 ? $topIpcm : $curIpcm;
                // p 侧 nnz。
                $pNnz = $edge === 0 ? ($topNnz[$pIdx] ?? 0) : ($curNnz[$pIdx] ?? 0);

                // I_PCM 两侧均不是才推导 bS。
                if (!$pIpcm && !$curIpcm) {
                    // 规则 1：intra → 宏块边界 4 / 内部边 3。
                    if ($pIntra || $curIntra) {
                        $horizontal[$pair] = $edge === 0 ? 4 : 3;
                    } elseif ($pNnz != 0 || ($curNnz[$qIdx] ?? 0) != 0) {
                        // 规则 2：非零系数 → 2。
                        $horizontal[$pair] = 2;
                    } else {
                        // 规则 3：运动信息比较。
                        $pMv = $edge === 0 ? ($topMv[$pIdx] ?? $this->deblockZeroMv) : ($curMv[$pIdx] ?? $this->deblockZeroMv);
                        $qMv = $curMv[$qIdx] ?? $this->deblockZeroMv;
                        $pRef = $edge === 0 ? ($topRef[$pIdx] ?? 0) : ($curRef[$pIdx] ?? 0);
                        // B 片：含跨列表判定。
                        if ($isBslice) {
                            $pMv1 = $edge === 0 ? ($topMv1[$pIdx] ?? $this->deblockZeroMv) : ($curMv1[$pIdx] ?? $this->deblockZeroMv);
                            $qMv1 = $curMv1[$qIdx] ?? $this->deblockZeroMv;
                            $pRef1 = $edge === 0 ? ($topRef1[$pIdx] ?? 0) : ($curRef1[$pIdx] ?? 0);
                            $qRef1 = $curRef1[$qIdx] ?? 0;
                            if ($this->bMotionBoundaryDiffers($pRef, $pMv, $curRef[$qIdx] ?? 0, $qMv, $pRef1, $pMv1, $qRef1, $qMv1)) {
                                $horizontal[$pair] = 1;
                            }
                        } elseif ($pRef != ($curRef[$qIdx] ?? 0)
                            || abs($pMv[0] - $qMv[0]) >= 4
                            || abs($pMv[1] - $qMv[1]) >= 4) {
                            // 单列表：参考索引或 MV 不同 → 1。
                            $horizontal[$pair] = 1;
                        }
                    }
                }
            }
            // 记录当前 edge 的 4 个 bS 到输出数组（按引用回传给调用方）。
            $bsVertical[$edge] = $vertical;
            $bsHorizontal[$edge] = $horizontal;
        }
    }

    /**
     * 把去块簿记里的原始参考索引统一映射为参考帧唯一 id。
     * 参考实现的 check_mv 比较的是 ref2frm 映射后的帧号：B 片的 L0 索引 0 与 L1 索引 0
     * 通常指向不同参考帧，直接比索引数值会把“跨列表指向不同帧”误判为相同。
     */
    private function mapDeblockRefIds(): void
    {
        // 只有 B 片会做跨列表比较；P 片单列表下原始索引与帧 id 单调同构，无需映射
        // 两个映射表任一为空（非 B 片或参考列表未构建）就直接返回。
        if (empty($this->deblockRefIdL0) || empty($this->deblockRefIdL1)) {
            return;
        }
        // 取 L0 映射表局部变量，减少循环内属性访问。
        $l0 = $this->deblockRefIdL0;
        // 取 L1 映射表。
        $l1 = $this->deblockRefIdL1;
        // 遍历所有宏块的 L0 参考索引并替换为帧 id。
        foreach ($this->mbRefForDeblock as $mbIdx => $refs) {
            foreach ($refs as $k => $r) {
                // 索引 -1 表示该块不使用该列表，保持原值，避免误映射。
                if ($r >= 0) {
                    // 映射失败时回退为 -1（视为未知参考，参与比较时判为不同）。
                    $this->mbRefForDeblock[$mbIdx][$k] = $l0[$r] ?? -1;
                }
            }
        }
        // 同样处理 L1 参考索引。
        foreach ($this->mbRefL1ForDeblock as $mbIdx => $refs) {
            foreach ($refs as $k => $r) {
                if ($r >= 0) {
                    $this->mbRefL1ForDeblock[$mbIdx][$k] = $l1[$r] ?? -1;
                }
            }
        }
    }

    /**
     * 对整帧执行去块滤波，对应 8.7.1 的整体流程：
     * 按光栅顺序遍历宏块，每个宏块依次处理垂直边（左边界 + 内部 3 条）与水平边（上边界 + 内部 3 条）。
     *
     * 关键点：
     * - 每条边先做亮度（Y），再做色度（U/V）；
     * - 色度只在 edge 0（宏块边界）与 edge 2（宏块内部第 2 条 4x4 边）上存在，
     *   因为 4:2:0 下每个 8x8 色度块对应 2 个 4x4 亮度块；
     * - 宏块边界的 bS 由两侧宏块信息共同决定，QP 取两侧平均值（8.7.2.3 的 QPav）；
     * - 内部边使用当前宏块的 QP。
     */
    public function applyDeblockingFilter(): void
    {
        // disable_deblocking_filter_idc == 1 表示整片禁用；forceDisableDeblock 为上层强制开关。
        if ($this->disableDeblockingFilterIdc == 1 || ($this->forceDisableDeblock ?? false)) {
            return;
        }

        // 亮度平面行跨度 = 图像宽度。
        $this->deblockYStride = $this->width;
        // 色度平面行跨度 = 宽度的一半（4:2:0）。
        $this->deblockUvStride = (int)($this->width / 2);
        // 先把参考索引映射为参考帧唯一 id，保证 B 片跨列表比较语义正确。
        $this->mapDeblockRefIds();
        // qp→阈值/tc 映射与帧内容无关，缓存跨帧保留（首次出现的 qp 才计算）

        // 图像宏块列数。
        $mbWidth = $this->picWidthInMbs;
        // 图像宏块行数。
        $mbHeight = $this->picHeightInMbs;
        // bS 输出缓冲的初值（4 条边 × 4 个块）。
        $emptyStrengths = [[0, 0, 0, 0], [0, 0, 0, 0], [0, 0, 0, 0], [0, 0, 0, 0]];
        // 垂直边 bS 缓冲。
        $bsVertical = $emptyStrengths;
        // 水平边 bS 缓冲。
        $bsHorizontal = $emptyStrengths;

        // 逐行遍历宏块。
        for ($mbY = 0; $mbY < $mbHeight; $mbY++) {
            // 逐列遍历宏块。
            for ($mbX = 0; $mbX < $mbWidth; $mbX++) {
                // 当前宏块一维下标。
                $mbIdx = $mbY * $mbWidth + $mbX;
                // 当前宏块类型（缺失按 0 处理，随后 <0 判断不会跳过）。
                $curType = $this->mbTypeForDeblock[$mbIdx] ?? 0;
                // 当前宏块 QP（缺失时用 26，即默认中值 QP）。
                $curQp = $this->mbQpForDeblock[$mbIdx] ?? 26;

                // 类型 <0 表示该宏块无簿记信息（例如未解码/被跳过），不做滤波。
                if ($curType < 0) {
                    continue;
                }

                // 推导本宏块全部垂直边与水平边的 bS（8.7.2）。
                $this->computeBoundaryStrengths($mbX, $mbY, $bsVertical, $bsHorizontal);

                // 内部边 qp 恒为当前 MB qp，色度 QP 每 MB 只查一次
                $chromaQpCur = $this->getChromaQp($curQp);
                // 处理 4 条垂直边：edge 0 为宏块左边界，1..3 为内部边。
                for ($edge = 0; $edge < 4; $edge++) {
                    // edge 0 是宏块边界（跨宏块），其余为宏块内部边。
                    $isMbEdge = ($edge == 0);

                    // 图像最左列宏块没有左邻，跳过左边界。
                    if ($isMbEdge && $mbX == 0) {
                        continue;
                    }

                    // 取该边的 4 个 bS。
                    $strengths = $bsVertical[$edge];
                    // 4 个 bS 全为 0 时整条边无需滤波（按位或一次判断，避免逐个比较）。
                    if (($strengths[0] | $strengths[1] | $strengths[2] | $strengths[3]) === 0) {
                        continue;
                    }

                    // 宏块边界：QP 取当前 MB 与左邻 MB 的平均（8.7.2.3 的 QPav）。
                    if ($isMbEdge) {
                        $qp = $this->avgQp($curQp, $this->mbQpForDeblock[($mbY * $mbWidth + $mbX - 1)] ?? $curQp);
                    } else {
                        // 内部边：直接使用当前 MB 的 QP。
                        $qp = $curQp;
                    }
                    // 亮度垂直边滤波。
                    $this->filterVerticalLuma($mbX, $mbY, $edge, $strengths, $qp);

                    // 色度只有 2 个 4x4 块宽，只在 edge 0 与 edge 2 处存在对应边界。
                    if ($edge == 0 || $edge == 2) {
                        // 亮度 edge 0/2 映射为色度 edge 0/1。
                        $chromaEdge = (int)($edge / 2);
                        // 色度边界用两侧平均 QP 换算的色度 QP；内部边沿用已算好的 $chromaQpCur。
                        $this->filterVerticalChroma($mbX, $mbY, $chromaEdge, $strengths, $isMbEdge ? $this->getChromaQp($qp) : $chromaQpCur);
                    }
                }

                // 处理 4 条水平边（上边界 + 内部 3 条）。
                for ($edge = 0; $edge < 4; $edge++) {
                    // edge 0 为宏块上边界。
                    $isMbEdge = ($edge == 0);

                    // 图像最顶行宏块没有上邻，跳过上边界。
                    if ($isMbEdge && $mbY == 0) {
                        continue;
                    }

                    // 取该边 4 个 bS。
                    $strengths = $bsHorizontal[$edge];
                    // 全 0 跳过。
                    if (($strengths[0] | $strengths[1] | $strengths[2] | $strengths[3]) === 0) {
                        continue;
                    }

                    // 宏块边界：QP 取当前 MB 与上邻 MB 的平均。
                    if ($isMbEdge) {
                        $qp = $this->avgQp($curQp, $this->mbQpForDeblock[(($mbY - 1) * $mbWidth + $mbX)] ?? $curQp);
                    } else {
                        // 内部边使用当前 MB 的 QP。
                        $qp = $curQp;
                    }
                    // 亮度水平边滤波。
                    $this->filterHorizontalLuma($mbX, $mbY, $edge, $strengths, $qp);

                    // 色度同样只在 edge 0/2 处处理。
                    if ($edge == 0 || $edge == 2) {
                        // 映射为色度 edge 0/1。
                        $chromaEdge = (int)($edge / 2);
                        // 色度水平边滤波。
                        $this->filterHorizontalChroma($mbX, $mbY, $chromaEdge, $strengths, $isMbEdge ? $this->getChromaQp($qp) : $chromaQpCur);
                    }
                }
            }
        }
    }
}