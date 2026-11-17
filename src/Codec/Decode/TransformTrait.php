<?php

namespace Xiaosongshu\Flv2mp4\Codec\Decode;

/**
 * 逆变换 / 反量化 trait（H.264 解码侧：系数域 → 残差域）。
 *
 * 覆盖的规范流程：
 *  - 4x4 残差系数的反量化（scaling）：规范 8.5.9；
 *  - 4x4 整数 IDCT：规范 8.5.12；
 *  - 4x4 亮度 DC 组（16 个）的逆哈达玛 + 反量化：规范 8.5.10；
 *  - 2x2 色度 DC 组的逆哈达玛 + 反量化：规范 8.5.11；
 *  - High profile 的 8x8 残差系数反量化与 8x8 整数 IDCT：规范 8.5.9 / 8.5.13。
 *
 * 约定：
 *  - 除 *AddToPlane 系列方法外，本 trait 只产出残差，不做 pred + residual 与 clip；
 *  - 整数实现中的 >> 是算术右移，等价于规范中对负数取 floor 的语义；
 *  - dequant4Table / dequant8 乘数表在解码器初始化阶段预先算好，
 *    dequant4Table 的下标依次为 [list][QP][raster 位置]，
 *    list 的取值：0/3 为亮度（帧内/帧间），1/4 为 Cb（帧内/帧间），2/5 为 Cr（帧内/帧间）。
 */
/**
 * @purpose 逆离散余弦变换
 * @author yanglong
 * @time 2026年7月23日15:27:12
 */
trait TransformTrait
{
    /**
     * Z字形转光栅顺序
     *
     * 解码器从 CAVLC / CABAC 残差块解码得到的是扫描顺序（zigzag）的 16 个系数，
     * 而反量化、IDCT 都按光栅（raster）顺序处理，因此需要做一次重排。
     * ZIGZAG_SCAN_4X4 的含义是「扫描序号 k → raster 下标」的映射表，
     * 对应规范 8.5.9 中根据 scan 顺序取系数的过程。
     *
     * @param array $block 扫描顺序的 16 个系数
     * @return array raster 顺序的 16 个系数
     */
    public function zigzagToRaster(array $block): array
    {
        // 先建一个全 0 的 16 项输出块，未赋值位置保持 0（等价于系数为 0）
        $out = array_fill(0, 16, 0);
        // 遍历全部 16 个扫描位置（4x4 块共有 16 个系数）
        for ($i = 0; $i < 16; $i++) {
            // 查表得到该扫描位置对应的 raster 位置（行优先下标 y*4+x）
            $pos = self::ZIGZAG_SCAN_4X4[$i];
            // 把扫描顺序的系数搬到 raster 位置的槽位上
            $out[$pos] = $block[$i];
        }
        return $out;
    }

    /**
     * 4x4反量化
     * 公式: (level * table[qp][pos] + 32) >> 6
     * table 已包含 INIT * scaling_matrix << (qp/6 + 2)
     *
     * 对应规范 8.5.9（4x4 残差块的 scaling 过程）：
     *   d[x][y] = Clip3(-32768, 32767, ( ( c[x][y] * LevelScale(qP%6, x, y) +
     *              (1 << (qP/6 + 3)) ) >> (qP/6 + 4) ) )
     * 本实现把 LevelScale 与 (qP/6 + 4) 中的部分移位提前合并进表的 <<(qp/6+2)，
     * 于是代码里只剩固定的 +32（2^5）与 >>6，两者相加正好等于规范中的移位量。
     *
     * @param array $coeff 16个系数（raster顺序）
     * @param int $type 0亮度intra 1色度intra
     * @param int $qp
     * @return array
     */
    public function dequantize4x4(array $coeff, int $type, int $qp): array
    {
        // 输出同样是 raster 顺序的 16 个反量化系数
        $out = array_fill(0, 16, 0);
        // QP 越界保护：规范允许的 QP 范围是 0..51（先夹紧，避免查表越界）
        $qp = max(0, min(51, $qp));
        // type 0=intra Y (list 0), type 1=Cb (list 1), type 2=Cr (list 2)
        // list 序号直接决定使用哪一张量化矩阵（帧内/帧间、亮度/色度的表不同）
        $listIdx = $type;
        // 逐个 raster 位置做反量化
        for ($i = 0; $i < 16; $i++) {
            // 系数为 0 时结果必然为 0（含舍入偏置后仍为 0），直接跳过以省一次乘法
            if ($coeff[$i] == 0) continue;
            // intdiv是向0取整，对负数结果错误
            // 所以这里用 >> 6 而不是 intdiv(...)：算术右移对负数是 floor，符合规范
            // (level * 乘数 + 32) >> 6：+32 是 2^5 的舍入偏置，>>6 完成 1/64 归一化
            $out[$i] = ($coeff[$i] * $this->dequant4Table[$listIdx][$qp][$i] + 32) >> 6;
        }
        return $out;
    }

    /**
     * 热路径融合：CAVLC输出的16个zigzag系数 → raster顺序反量化，一步完成。
     * 替代 copy16 + zigzagToRaster + dequantize4x4（每块省2次数组分配与32次循环）。
     * 数值与原三步完全一致：反量化乘数按raster位置查表。
     *
     * 语义仍是规范 8.5.9 的 4x4 反量化，只是把「扫描顺序 → raster 顺序」的重排
     * 与反量化合并进同一次循环。
     */
    public function dequantZigzag4x4(array $coeff, int $listIdx, int $qp): array
    {
        // 输出：raster 顺序的 16 个反量化系数
        $out = array_fill(0, 16, 0);
        // QP 夹紧到合法区间 0..51
        $qp = max(0, min(51, $qp));
        // 先取出该 list、该 QP 下的一整行反量化乘数（16 项，按 raster 位置排列）
        $tab = $this->dequant4Table[$listIdx][$qp];
        // z 为扫描（zigzag）序号，从 0 到 15
        for ($z = 0; $z < 16; $z++) {
            // 取扫描顺序下的第 z 个量化电平 level
            $c = $coeff[$z];
            // level 为 0 时该位置反量化结果就是 0，跳过
            if ($c != 0) {
                // 把扫描序号映射为 raster 下标
                $r = self::ZIGZAG_SCAN_4X4[$z];
                // 用 raster 位置对应的乘数做反量化：(level * 乘数 + 32) >> 6
                $out[$r] = ($c * $tab[$r] + 32) >> 6;
            }
        }
        return $out;
    }

    /**
     * 热路径融合（AC专用）：CAVLC输出的15个AC系数（对应zigzag位置1..15）
     * → raster顺序反量化，位置0留空给DC后续填入。
     *
     * 规范 8.5.9 对「Intra16x16 的 AC 块」只传 15 个系数（DC 已在 4x4 亮度 DC 组中单独处理），
     * 因此循环从扫描序号 1 开始，raster 位置 0（即 DC 位置）保持 0，之后由 DC 反量化结果填入。
     */
    public function dequantAcZigzag4x4(array $ac15, int $listIdx, int $qp): array
    {
        // 输出：raster 顺序的 16 个系数，其中 DC（下标 0）暂留 0
        $out = array_fill(0, 16, 0);
        // QP 夹紧到 0..51
        $qp = max(0, min(51, $qp));
        // 取出该 list、该 QP 下按 raster 位置排列的 16 项反量化乘数
        $tab = $this->dequant4Table[$listIdx][$qp];
        // z 从 1 开始：扫描序号 0 是 DC，不在本函数的输入里
        for ($z = 1; $z < 16; $z++) {
            // 输入数组 ac15 的长度为 15，扫描序号 z 对应其下标 z-1
            $c = $ac15[$z - 1];
            // AC level 为 0 则跳过（该 raster 位置保持 0）
            if ($c != 0) {
                // 扫描序号 → raster 下标
                $r = self::ZIGZAG_SCAN_4X4[$z];
                // 反量化：+32 为舍入偏置，>>6 完成归一化
                $out[$r] = ($c * $tab[$r] + 32) >> 6;
            }
        }
        return $out;
    }

    /**
     * 4x4 IDCT整数逆变换
     * 先列变换，后行变换，中间>>1不可交换顺序
     *
     * 这里做的是二维数组（[y][x]）与一维 flat 数组之间的转换包装，
     * 真正的蝶形运算在 idct4x4Flat 中完成（对应规范 8.5.12）。
     *
     * @param array $in 4x4 二维残差系数（已反量化）
     * @return array 4x4 二维残差样点
     */
    public function idct4x4(array $in): array
    {
        // 先把二维 [y][x] 摊平为 raster 顺序的 16 项一维数组
        $block = array_fill(0, 16, 0);
        // 双重循环遍历 4 行 4 列（行优先展平：下标 = y*4 + x）
        for ($y = 0; $y < 4; $y++) for ($x = 0; $x < 4; $x++)
            $block[$y * 4 + $x] = $in[$y][$x];

        // 调用 flat 版做两遍整数蝶形逆变换
        $flat = $this->idct4x4Flat($block);
        // 再把一维残差还原为 4x4 二维结构返回
        $out = array_fill(0, 4, array_fill(0, 4, 0));
        // 双重循环按 raster 顺序回填
        for ($y = 0; $y < 4; $y++) for ($x = 0; $x < 4; $x++)
            $out[$y][$x] = $flat[$y * 4 + $x];
        return $out;
    }

    /**
     * 4x4 IDCT整数逆变换（输入输出均为raster顺序的16个系数）
     * 顺序与参考实现 ff_h264_idct_add 一致：先行变换（含>>1），再列变换（含>>1与>>6）。
     * 两遍蝶形在实数域可交换，但 >>1 是 floor，顺序互换会在 .5 边界产生 ±1 漂移。
     *
     * 对应规范 8.5.12：对已反量化的 4x4 系数做整数逆变换，
     * 蝶形中出现的 (x >> 1) 近似规范里乘 1/2 的系数，最后 >>6 完成 1/64 归一化。
     */
    public function idct4x4Flat(array $in): array
    {
        // 就地复用输入数组作为工作区（避免额外分配）
        $block = $in;
        // 舍入偏置：规范 8.5.12 的第一步是对 DC 项加 32（即 2^5），配合末尾的 >>6
        $block[0] += 32;

        // 第一遍：行变换
        // i 为行号，每行独立做一维 4 点蝶形（行内元素互不影响其他行）
        for ($i = 0; $i < 4; $i++) {
            // 该行首元素在 flat 数组中的下标
            $row = 4 * $i;
            // 偶数项部分：z0 为和、z1 为差（对应规范中的 e0/e1 一类中间量）
            $z0 = $block[$row + 0] + $block[$row + 2];
            $z1 = $block[$row + 0] - $block[$row + 2];
            // 奇数项部分：用 >>1 近似乘 1/2 的旋转因子，z2/z3 为两个旋转后的中间量
            $z2 = ($block[$row + 1] >> 1) - $block[$row + 3];
            $z3 = $block[$row + 1] + ($block[$row + 3] >> 1);

            // 四个输出（行内 raster 顺序写回）：偶数/奇数部分交叉相加减
            $block[$row + 0] = $z0 + $z3;
            $block[$row + 1] = $z1 + $z2;
            $block[$row + 2] = $z1 - $z2;
            $block[$row + 3] = $z0 - $z3;
        }

        // 第二遍：列变换并>>6（每列的4次读取先于该列4次写入，各列互不重叠）
        // i 为列号；同一列的元素在 flat 数组中间隔 4（下标 i、i+4、i+8、i+12）
        for ($i = 0; $i < 4; $i++) {
            // 列内偶数行（第 0、2 行）的和与差
            $z0 = $block[$i + 0] + $block[$i + 8];
            $z1 = $block[$i + 0] - $block[$i + 8];
            // 列内奇数行（第 1、3 行）经 >>1 旋转后的两个中间量
            $z2 = ($block[$i + 4] >> 1) - $block[$i + 12];
            $z3 = $block[$i + 4] + ($block[$i + 12] >> 1);

            // 四个输出各自 >>6：完成 1/64 归一化（与首行的 +32 构成四舍五入）
            $block[$i + 0] = ($z0 + $z3) >> 6;
            $block[$i + 4] = ($z1 + $z2) >> 6;
            $block[$i + 8] = ($z1 - $z2) >> 6;
            $block[$i + 12] = ($z0 - $z3) >> 6;
        }

        // 返回 raster 顺序的 16 个残差样点（尚未叠加预测值、未 clip）
        return $block;
    }

    /**
     * 亮度DC 4x4逆哈达玛+反量化
     * 输入: raster顺序的16个DC系数
     * 输出: raster顺序的16个反量化DC值
     * 变换: 先Hadamard，再乘qmul，最后 >> 8
     *
     * 说明：方法名保留历史命名（descan），实际语义是「逆扫描」：
     * Intra16x16 的 16 个 4x4 子块各自只保留一个 DC，这 16 个 DC 组成一个 4x4 组，
     * 按扫描顺序编码，需要先还原成 raster 顺序才能做逆哈达玛（规范 8.5.10、8.5.9）。
     *
     * @param array $scanOrder 扫描顺序的 DC 系数
     * @param int $maxCoef 有效 DC 系数个数（TotalCoeff，可能小于 16）
     * @return array raster 顺序的 16 个 DC 系数
     */
    public function descan4x4(array $scanOrder, int $maxCoef): array
    {
        // raster 顺序的 4x4 DC 组，未赋值位置为 0
        $raster = array_fill(0, 16, 0);
        // 只遍历实际存在的 maxCoef 个系数（CAVLC 尾部零不显式传输）
        for ($scanPos = 0; $scanPos < $maxCoef; $scanPos++) {
            // 扫描序号 → raster 下标，把 DC 系数放回其子块应在的位置
            $raster[self::ZIGZAG_SCAN_4X4[$scanPos]] = $scanOrder[$scanPos];
        }
        return $raster;
    }

    /**
     * 4x4 亮度 DC 组的逆哈达玛变换 + 反量化（规范 8.5.10）。
     *
     * 两步：
     *  1) 对 4x4 DC 组做二维哈达玛（只有加减，没有 >>1 旋转因子）；
     *  2) 每个结果乘以 qmul 反量化，+128 后 >>8（+128 即 2^7，是 >>8 的舍入偏置）。
     * 输出 16 个值将分别作为 16 个 4x4 子块的 DC 系数（raster 顺序）。
     *
     * @param array $dc4x4 raster 顺序的 16 个 DC 系数
     * @param int $qmul 该 list/QP 下的 DC 反量化乘数（dequant4Table[list][qp][0]）
     * @return array raster 顺序的 16 个反量化 DC 值
     */
    public function lumaDcDequantIdct(array $dc4x4, int $qmul): array
    {
        // 第一遍（行方向逆哈达玛）的中间结果
        $temp = array_fill(0, 16, 0);

        // 逐行做 4 点哈达玛：每行 4 个输入 → 4 个输出
        for ($i = 0; $i < 4; $i++) {
            // 该行首元素下标（行优先）
            $base = 4 * $i;
            // 行内相邻两项的和与差（哈达玛的第一级）
            $z0 = $dc4x4[$base] + $dc4x4[$base + 1];
            $z1 = $dc4x4[$base] - $dc4x4[$base + 1];
            $z2 = $dc4x4[$base + 2] - $dc4x4[$base + 3];
            $z3 = $dc4x4[$base + 2] + $dc4x4[$base + 3];

            // 第二级：交叉相加减得到该行 4 个哈达玛输出
            $temp[$base] = $z0 + $z3;
            $temp[$base + 1] = $z0 - $z3;
            $temp[$base + 2] = $z1 - $z2;
            $temp[$base + 3] = $z1 + $z2;
        }

        // 第二遍（列方向逆哈达玛）+ 反量化，结果直接写入输出块
        $out = array_fill(0, 16, 0);
        // j 为列号；同列元素在 flat 数组中间隔 4
        for ($j = 0; $j < 4; $j++) {
            // 列内第 0、2 行的和与差
            $z0 = $temp[$j] + $temp[8 + $j];
            $z1 = $temp[$j] - $temp[8 + $j];
            // 列内第 1、3 行的差与和
            $z2 = $temp[4 + $j] - $temp[12 + $j];
            $z3 = $temp[4 + $j] + $temp[12 + $j];

            // 乘 qmul 完成反量化，并加 128（2^7）作为 >>8 的舍入偏置
            $s0 = ($z0 + $z3) * $qmul + 128;
            $s1 = ($z1 + $z2) * $qmul + 128;
            $s2 = ($z1 - $z2) * $qmul + 128;
            $s3 = ($z0 - $z3) * $qmul + 128;
            // >>8 完成 1/256 归一化后，按 raster 位置（行*4+列）写回
            $out[0 * 4 + $j] = $s0 >> 8;
            $out[1 * 4 + $j] = $s1 >> 8;
            $out[2 * 4 + $j] = $s2 >> 8;
            $out[3 * 4 + $j] = $s3 >> 8;
        }
        return $out;
    }

    /**
     * 色度DC 2x2逆哈达玛+反量化
     * output[i] = (hadamard_result * qmul) >> 7
     *
     * 规范 8.5.11：4:2:0 的每个色度分量，一个 8x8 色度块由 4 个 4x4 子块组成，
     * 4 个子块的 DC 构成 2x2 组，先做 2 点哈达玛（行、列各一次），再反量化。
     * 与亮度 DC 不同，这里的移位是 >>7 且不带 +2^6 的舍入偏置。
     *
     * @param array $dc2x2 4 个 DC 系数（raster 顺序）
     * @param int $qmul 该分量/QP 下的 DC 反量化乘数
     * @return array 4 个反量化后的 DC 值（raster 顺序）
     */
    public function chromaDcDequantIdct(array $dc2x2, int $qmul): array
    {
        // 取出 2x2 组的 4 个输入（a 左上、b 右上、c 左下、d 右下）
        $a = $dc2x2[0];
        $b = $dc2x2[1];
        $c = $dc2x2[2];
        $d = $dc2x2[3];

        // 行方向哈达玛：a/b 一组、c/d 一组，得到和与差
        $e = $a - $b;
        $a = $a + $b;
        $b = $c - $d;
        $c = $c + $d;

        // 没有像亮度DC那样的+128舍入偏置
        // 使用intdiv确保负数算术右移
        // 输出 4 个 DC 值：列方向哈达玛 + 反量化 + >>7 归一化
        $out = array_fill(0, 4, 0);
        $out[0] = (($a + $c) * $qmul) >> 7;
        $out[1] = (($e + $b) * $qmul) >> 7;
        $out[2] = (($a - $c) * $qmul) >> 7;
        $out[3] = (($e - $b) * $qmul) >> 7;
        return $out;
    }

    // ===== High profile 8x8 变换 =====
    // 以下为 High profile 才启用的 8x8 变换路径（transform_size_8x8_flag = 1 时使用），
    // 4x4 路径（CAVLC/CABAC + transform_size_8x8_flag = 0）仍然走上文的 4x4 函数。

    /** dequant8_coeff_init。PHP 8.1 trait 不支持常量，用静态数组 */
    // 8x8 反量化初始系数表：6 行对应 QP%6 = 0..5，每行 6 项对应规范中不同位置的
    // LevelScale8 基值（规范 8.5.9 的 8x8 分支 / 8x8 反量化表）。
    private static array $DEQUANT8_COEFF_INIT = [
        [20, 18, 32, 19, 25, 24],
        [22, 19, 35, 21, 28, 26],
        [26, 23, 42, 24, 33, 31],
        [28, 25, 45, 26, 35, 33],
        [32, 28, 51, 30, 40, 38],
        [36, 32, 58, 34, 46, 43],
    ];
    /** dequant8_coeff_init_scan */
    // 位置到「初始系数索引」的映射表：把 raster 位置按量化矩阵的位置类别
    // 归类到 0..5 之一（8x8 时按 4x4 子块位置决定类别）。
    private static array $DEQUANT8_COEFF_SCAN = [0, 3, 4, 3, 3, 1, 5, 1, 4, 5, 2, 5, 3, 1, 5, 1];

    /**
     * 构造 8x8 块的反量化乘数表（raster 64 项，flat scaling=16）。
     * 对应 pps->dequant8_coeff[cqm][qp][j]，cqm: 帧内Y=0 帧间Y=3。
     *
     * 与 4x4 一致地把移位量提前并入乘数：乘数 = 初始系数 * 16 << (QP/6)，
     * 因此后续 8x8 变换末尾只需固定 >>6（见 idct8AddToPlane）。
     *
     * @param int $qp 量化参数
     * @param bool $intraLuma 是否为帧内亮度（保留形参以对齐调用约定 / 量化矩阵选择）
     * @return array 64 项反量化乘数，按转置后的存储顺序排列
     */
    public function buildQmul8(int $qp, bool $intraLuma): array
    {
        // QP 夹紧到合法范围 0..51
        $qp = max(0, min(51, $qp));
        // 移位量：QP/6（整数部分），即每上升 6 个 QP，量化步长翻倍
        $shift = intdiv($qp, 6);
        // 表内行号：QP%6，决定基础的相对步长
        $idx = $qp % 6;
        // 取出该 QP%6 对应的 6 个初始系数
        $init = self::$DEQUANT8_COEFF_INIT[$idx];
        // 取出位置类别映射表
        $scan = self::$DEQUANT8_COEFF_SCAN;
        // 输出 64 个乘数（8x8 = 64 个系数）
        $qmul = array_fill(0, 64, 0);
        // j 遍历存储位置（转置布局），逐个计算乘数
        for ($j = 0; $j < 64; $j++) {
            // j 为存储位置（转置位置），缩放表索引 x = transpose(j)
            // 转置公式：行号与列号互换（8x8 时每行 8 项，故低 3 位为列、高位为行）
            $x = ($j >> 3) | (($j & 7) << 3);
            // 把 raster 位置 x 映射到 0..5 的位置类别：
            // ((x>>1) & 12) 取出行方向的 4x4 子块类别，($x & 3) 取列方向子块类别
            $scanIdx = (($x >> 1) & 12) | ($x & 3);
            // 乘数 = 初始系数 * 16（flat scaling matrix 的值）<< (QP/6)
            $qmul[$j] = ($init[$scan[$scanIdx]] * 16) << $shift;
        }
        return $qmul;
    }

    /**
     * 8x8 整数反变换并叠加到像素平面（移植 idct8_add）。
     * @param array $block 64 个已反量化系数（raster），会被清零语义忽略
     *
     * 对应规范 8.5.13：8x8 逆变换采用两遍一维 8 点蝶形（水平 + 垂直），
     * 中间结果直接用整数表示，末尾 >>6 完成归一化，再与预测像素相加并 clip 到 0..255。
     *
     * @param array $plane 目标像素平面（按引用修改）
     * @param int $stride 平面行跨度
     * @param int $x0 8x8 块左上角列坐标
     * @param int $y0 8x8 块左上角行坐标
     */
    public function idct8AddToPlane(array &$plane, int $stride, int $x0, int $y0, array $block): void
    {
        // DC 项的舍入偏置（2^5），配合末尾 >>6 实现四舍五入
        $block[0] += 32;

        // 第一遍：列变换
        // i 为列号，遍历 8 列；每列取间隔 8 的 8 个系数做一维 8 点蝶形
        for ($i = 0; $i < 8; $i++) {
            // 偶数项第一级：第 0/4 行的和与差
            $a0 = $block[$i + 0 * 8] + $block[$i + 4 * 8];
            $a2 = $block[$i + 0 * 8] - $block[$i + 4 * 8];
            // 偶数项第二级：第 2/6 行经 >>1 旋转（近似乘 1/2 的系数）
            $a4 = ($block[$i + 2 * 8] >> 1) - $block[$i + 6 * 8];
            $a6 = ($block[$i + 6 * 8] >> 1) + $block[$i + 2 * 8];

            // 组合出偶数部分的 4 个中间量 b0/b2/b4/b6
            $b0 = $a0 + $a6;
            $b2 = $a2 + $a4;
            $b4 = $a2 - $a4;
            $b6 = $a0 - $a6;

            // 奇数部分：用 >>1 与加减组合近似规范的奇数列旋转因子
            $a1 = -$block[$i + 3 * 8] + $block[$i + 5 * 8] - $block[$i + 7 * 8] - ($block[$i + 7 * 8] >> 1);
            $a3 =  $block[$i + 1 * 8] + $block[$i + 7 * 8] - $block[$i + 3 * 8] - ($block[$i + 3 * 8] >> 1);
            $a5 = -$block[$i + 1 * 8] + $block[$i + 7 * 8] + $block[$i + 5 * 8] + ($block[$i + 5 * 8] >> 1);
            $a7 =  $block[$i + 3 * 8] + $block[$i + 5 * 8] + $block[$i + 1 * 8] + ($block[$i + 1 * 8] >> 1);

            // 再叠加 >>2（近似乘 1/4）的旋转项，得到奇数部分中间量 b1/b3/b5/b7
            $b1 = ($a7 >> 2) + $a1;
            $b3 = $a3 + ($a5 >> 2);
            $b5 = ($a3 >> 2) - $a5;
            $b7 = $a7 - ($a1 >> 2);

            // 蝶形输出，按行号顺序写回该列的 8 个位置（第一遍不归一化）
            $block[$i + 0 * 8] = $b0 + $b7;
            $block[$i + 7 * 8] = $b0 - $b7;
            $block[$i + 1 * 8] = $b2 + $b5;
            $block[$i + 6 * 8] = $b2 - $b5;
            $block[$i + 2 * 8] = $b4 + $b3;
            $block[$i + 5 * 8] = $b4 - $b3;
            $block[$i + 3 * 8] = $b6 + $b1;
            $block[$i + 4 * 8] = $b6 - $b1;
        }
        // 第二遍：行变换并 >>6 叠加。
        // 系数块是转置存储布局（scanJ = TRANSPOSE(zigzag)），idct8_add
        // 把存储行 i 的 8 个输出竖写进图像列 i（dst[i + k*stride]），
        // 这样转置布局与两遍蝶形的转置效应正好抵消，输出才是正确方向的像素。
        // 之前横写进行 y0+i 导致整个 8x8 残差块被转置（坏 MB 的 diff 呈反对称）。
        // i 为存储行号（在转置布局下等价于图像列号）
        for ($i = 0; $i < 8; $i++) {
            // 偶数项第一级：第 0/4 列（在存储行内偏移 0 与 4）
            $a0 = $block[0 + $i * 8] + $block[4 + $i * 8];
            $a2 = $block[0 + $i * 8] - $block[4 + $i * 8];
            // 偶数项第二级：第 2/6 列经 >>1 旋转
            $a4 = ($block[2 + $i * 8] >> 1) - $block[6 + $i * 8];
            $a6 = ($block[6 + $i * 8] >> 1) + $block[2 + $i * 8];

            // 偶数部分的 4 个中间量
            $b0 = $a0 + $a6;
            $b2 = $a2 + $a4;
            $b4 = $a2 - $a4;
            $b6 = $a0 - $a6;

            // 奇数部分的旋转中间量（与第一遍同构，只是改取列）
            $a1 = -$block[3 + $i * 8] + $block[5 + $i * 8] - $block[7 + $i * 8] - ($block[7 + $i * 8] >> 1);
            $a3 =  $block[1 + $i * 8] + $block[7 + $i * 8] - $block[3 + $i * 8] - ($block[3 + $i * 8] >> 1);
            $a5 = -$block[1 + $i * 8] + $block[7 + $i * 8] + $block[5 + $i * 8] + ($block[5 + $i * 8] >> 1);
            $a7 =  $block[3 + $i * 8] + $block[5 + $i * 8] + $block[1 + $i * 8] + ($block[1 + $i * 8] >> 1);

            // 奇数部分最终中间量
            $b1 = ($a7 >> 2) + $a1;
            $b3 = $a3 + ($a5 >> 2);
            $b5 = ($a3 >> 2) - $a5;
            $b7 = $a7 - ($a1 >> 2);

            // 目标像素列：第 i 列（转置布局下存储行 i 对应图像列 i）
            $col = $x0 + $i;
            // 以下 8 行依次处理图像第 y0+0 .. y0+7 行：
            // 每个输出先 >>6 完成归一化，再加到预测像素上，最后 clip 到 [0, 255]
            // 第 y0+0 行：对应蝶形输出 (b0 + b7)
            $v = ($b0 + $b7) >> 6; $base = ($y0 + 0) * $stride + $col; $v = $plane[$base] + $v; $plane[$base] = $v < 0 ? 0 : ($v > 255 ? 255 : $v);
            // 第 y0+1 行：对应蝶形输出 (b2 + b5)
            $v = ($b2 + $b5) >> 6; $base = ($y0 + 1) * $stride + $col; $v = $plane[$base] + $v; $plane[$base] = $v < 0 ? 0 : ($v > 255 ? 255 : $v);
            // 第 y0+2 行：对应蝶形输出 (b4 + b3)
            $v = ($b4 + $b3) >> 6; $base = ($y0 + 2) * $stride + $col; $v = $plane[$base] + $v; $plane[$base] = $v < 0 ? 0 : ($v > 255 ? 255 : $v);
            // 第 y0+3 行：对应蝶形输出 (b6 + b1)
            $v = ($b6 + $b1) >> 6; $base = ($y0 + 3) * $stride + $col; $v = $plane[$base] + $v; $plane[$base] = $v < 0 ? 0 : ($v > 255 ? 255 : $v);
            // 第 y0+4 行：对应蝶形输出 (b6 - b1)
            $v = ($b6 - $b1) >> 6; $base = ($y0 + 4) * $stride + $col; $v = $plane[$base] + $v; $plane[$base] = $v < 0 ? 0 : ($v > 255 ? 255 : $v);
            // 第 y0+5 行：对应蝶形输出 (b4 - b3)
            $v = ($b4 - $b3) >> 6; $base = ($y0 + 5) * $stride + $col; $v = $plane[$base] + $v; $plane[$base] = $v < 0 ? 0 : ($v > 255 ? 255 : $v);
            // 第 y0+6 行：对应蝶形输出 (b2 - b5)
            $v = ($b2 - $b5) >> 6; $base = ($y0 + 6) * $stride + $col; $v = $plane[$base] + $v; $plane[$base] = $v < 0 ? 0 : ($v > 255 ? 255 : $v);
            // 第 y0+7 行：对应蝶形输出 (b0 - b7)
            $v = ($b0 - $b7) >> 6; $base = ($y0 + 7) * $stride + $col; $v = $plane[$base] + $v; $plane[$base] = $v < 0 ? 0 : ($v > 255 ? 255 : $v);
        }
    }

    /**
     * 8x8 仅 DC 系数时叠加（idct8_dc_add），dc 为已反量化的 [0] 系数。
     * 空块优化：8x8 块只有 DC 非零时，逆变换结果在整块内为常数 (dc + 32) >> 6，
     * 无需跑完整的两遍蝶形，直接把该常数加到 8x8 像素上即可（对应规范 8.5.13 的退化情形）。
     */
    public function idct8DcAddToPlane(array &$plane, int $stride, int $x0, int $y0, int $dc): void
    {
        // 只用 DC 反量化值算出全块统一的残差值：(dc + 32) >> 6（+32 为舍入偏置）
        $v = ($dc + 32) >> 6;
        // 逐行铺满 8x8 区域
        for ($j = 0; $j < 8; $j++) {
            // 该行起始像素下标（列方向从 x0 开始）
            $base = ($y0 + $j) * $stride + $x0;
            // 行内逐列叠加残差
            for ($i = 0; $i < 8; $i++) {
                // 预测像素 + 残差
                $p = $plane[$base + $i] + $v;
                // clip 到 8bit 像素范围 [0, 255]
                $plane[$base + $i] = $p < 0 ? 0 : ($p > 255 ? 255 : $p);
            }
        }
    }
}
