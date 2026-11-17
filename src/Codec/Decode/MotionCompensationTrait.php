<?php

namespace Xiaosongshu\Flv2mp4\Codec\Decode;

/**
 * 运动补偿（Motion Compensation，MC）trait：由参考帧采样 + MV 生成预测块。
 *
 * 覆盖的规范流程：
 *  - 亮度 1/4 像素插值：规范 8.4.2.2.1（6 抽头 FIR 半像素插值 + 相邻半像素平均）；
 *  - 色度 1/8 像素插值：规范 8.4.2.2.2（双线性插值，权重由 1/8 分数决定）；
 *  - 参考样本越界裁剪：规范 8.4.2.2（越界位置取最近边界样本，即 clamp 索引）；
 *  - 显式加权预测：规范 8.4.2.3.1（pred = Clip3(((v*w + rnd) >> log2Wd) + o)）；
 *  - 隐式加权预测的权重由 POC 距离在 slice 层推导，本 trait 只负责按权重套用。
 *
 * 约定：
 *  - 平面（plane）是行优先的一维数组，元素为 8bit 像素值 0..255，行跨度由 stride 给出；
 *  - 亮度 MV 以 1/4 亮度样本为单位：x&3 为水平分数，x>>2 为整数部分；
 *  - 色度 MV 以 1/8 色度样本为单位（因为 4:2:0 下色度平面宽高各为亮度一半）：
 *    x&7 为水平分数，x>>3 为整数部分；
 *  - 所有 clip 都是 8bit：小于 0 取 0，大于 255 取 255（高位深时上界为 (1<<BitDepth)-1）；
 *  - 6 抽头滤波器系数固定为 (1, -5, 20, 20, -5, 1)，增益 32，半像素结果用 +16 >> 5 归一化；
 *  - 本 trait 不做「预测 + 残差」，只产出预测样本；加权预测在 MC 之后原地套用。
 */
/**
 * @purpose 亮度色度运动补偿
 * @author yanglong
 * @time 2026年7月23日15:14:28
 */
trait MotionCompensationTrait
{
    /**
     * 亮度预测块（对外入口）。
     *
     * 输入参考帧亮度平面与目标块在参考帧中的位置（1/4 样本精度），
     * 产出 blockH 行 × blockW 列的二维预测块 $pred[$y][$x]。
     * 真正的插值与边界裁剪委托给 mcLumaTo()，这里只做「一维暂存 → 二维结构」的转换。
     *
     * @param array $refPlane  参考帧亮度平面（行优先一维数组）
     * @param int   $refStride 参考帧亮度行跨度（单位：样本）
     * @param int   $refWidth  参考帧亮度有效宽度
     * @param int   $refHeight 参考帧亮度有效高度
     * @param int   $x         块左上角水平位置，单位 1/4 亮度样本
     * @param int   $y         块左上角垂直位置，单位 1/4 亮度样本
     * @param int   $blockW    块宽（样本数）
     * @param int   $blockH    块高（样本数）
     * @return array 二维预测块
     */
    public function mcLuma(array $refPlane, int $refStride, int $refWidth, int $refHeight, int $x, int $y, int $blockW, int $blockH): array
    {
        // 一维暂存缓冲区：blockW*blockH 个样本，raster 顺序（行优先），初值 0
        $buffer = array_fill(0, $blockW * $blockH, 0);
        // 调用插值核心：目标 stride 取 blockW，目标原点 (0,0)，即紧凑排列
        $this->mcLumaTo($refPlane, $refStride, $refWidth, $refHeight, $x, $y, $blockW, $blockH, $buffer, $blockW, 0, 0);
        // 输出二维数组，方便上层按 [y][x] 访问预测样本
        $pred = [];
        // 逐行搬运：第 j 行在暂存区中的起点为 j*blockW
        for ($j = 0; $j < $blockH; $j++) {
            $row = [];
            $base = $j * $blockW;
            // 逐列拷贝该行，保持 x 方向顺序
            for ($i = 0; $i < $blockW; $i++) {
                $row[$i] = $buffer[$base + $i];
            }
            $pred[$j] = $row;
        }
        return $pred;
    }

    /**
     * 显式加权预测（spec 8.4.2.3.1）：对刚做完 MC 的目标区域应用 weight/offset。
     * pred = clip(((v*w + rnd) >> log2Wd) + o)；log2Wd==0 时 pred = clip(v*w + o)
     *
     * 说明：$w、$o 来自 slice 层的 pred_weight_table（显式加权），
     * log2Wd = luma_log2_weight_denom（亮度）或 chroma_log2_weight_denom（色度）；
     * 颜色分量不同，但公式完全一致，因此亮度与两个色度平面都复用本方法。
     * 结果直接写回平面（原地修改），clip 到 0..255。
     */
    private function applyExplicitWeight(array &$plane, int $stride, int $dstX, int $dstY, int $blockW, int $blockH, int $w, int $o, int $log2Wd): void
    {
        // log2Wd >= 1：需要做带舍入的右移（规范中的 (1 << (log2Wd-1)) 舍入项）
        if ($log2Wd >= 1) {
            // 舍入偏置 rnd = 2^(log2Wd-1)
            $rnd = 1 << ($log2Wd - 1);
            // 逐行处理目标块
            for ($j = 0; $j < $blockH; $j++) {
                // 该行在平面中的起始下标
                $base = ($dstY + $j) * $stride + $dstX;
                // 逐列套用加权公式
                for ($i = 0; $i < $blockW; $i++) {
                    // 取当前预测样本 v
                    $v = $plane[$base + $i];
                    // 加权 + 舍入 + 右移 + offset（>> 对负数等价于规范中对负数取 floor 的语义）
                    $v = (($v * $w + $rnd) >> $log2Wd) + $o;
                    // clip 到 8bit 范围后写回
                    $plane[$base + $i] = $v < 0 ? 0 : ($v > 255 ? 255 : $v);
                }
            }
        } else {
            // log2Wd == 0：无需右移与舍入，直接 v*w + o
            for ($j = 0; $j < $blockH; $j++) {
                // 该行在平面中的起始下标
                $base = ($dstY + $j) * $stride + $dstX;
                // 逐列应用线性缩放 + offset
                for ($i = 0; $i < $blockW; $i++) {
                    $v = $plane[$base + $i] * $w + $o;
                    // clip 到 8bit 范围后写回
                    $plane[$base + $i] = $v < 0 ? 0 : ($v > 255 ? 255 : $v);
                }
            }
        }
    }

    /**
     * 亮度运动补偿核心（规范 8.4.2.2.1）。
     *
     * 根据 x、y 的分数部分（1/4 样本精度）分三类路径：
     *  1) fracX==0 且 fracY==0：整数 MV，直接拷贝（可整块无裁剪时走展开热路径）；
     *  2) 仅一个方向有分数：6 抽头 FIR 求半像素，再用相邻整像素/半像素求 1/4 像素；
     *  3) 两个方向都有分数：两个方向各做一次 6 抽头（可分离），再平均。
     * 所有越界参考样本都按 8.4.2.2 的语义取最近边界样本。
     *
     * @param array      $refPlane  参考帧平面
     * @param int        $refStride 参考帧行跨度
     * @param int        $refWidth  参考帧有效宽度
     * @param int        $refHeight 参考帧有效高度
     * @param int        $x         目标块水平位置（1/4 亮度样本）
     * @param int        $y         目标块垂直位置（1/4 亮度样本）
     * @param int        $blockW    块宽
     * @param int        $blockH    块高
     * @param array      $dstPlane  目标平面（引用，原地写）
     * @param int        $dstStride 目标平面行跨度
     * @param int        $dstX      目标块左上角 x
     * @param int        $dstY      目标块左上角 y
     * @param array|null $refBytes  可选的参考样本数组（默认与 $refPlane 相同）
     */
    private function mcLumaTo(
        array $refPlane,
        int $refStride,
        int $refWidth,
        int $refHeight,
        int $x,
        int $y,
        int $blockW,
        int $blockH,
        array &$dstPlane,
        int $dstStride,
        int $dstX,
        int $dstY,
        ?array $refBytes = null
    ): void {
        // 分数部分：MV 以 1/4 样本为单位，故低 2 位是分数（0..3）
        $fracX = $x & 3;
        $fracY = $y & 3;
        // 整数部分：算术右移 2 位即 floor(x/4)，对应规范中的 xIntL / yIntL
        $intX = $x >> 2;
        $intY = $y >> 2;
        // 有效坐标上界（用于越界裁剪）
        $maxX = $refWidth - 1;
        $maxY = $refHeight - 1;

        // 分支一：整数 MV（fracX==0 && fracY==0），无需插值
        if ($fracX === 0 && $fracY === 0) {
            // 参考块完整落在图像内部：可直接逐行拷贝，不必逐像素裁剪
            if ($intX >= 0 && $intY >= 0 && $intX + $blockW <= $refWidth && $intY + $blockH <= $refHeight) {
                // 热路径手工展开：16x16 与 16x8/16x? 等16宽块（PSkip/P_16x16 每帧大量命中）
                if ($blockW === 16) {
                    // 每行连续拷贝 16 个样本；s/d 分别是源/目标行首下标
                    for ($j = 0; $j < $blockH; $j++) {
                        $s = ($intY + $j) * $refStride + $intX;
                        $d = ($dstY + $j) * $dstStride + $dstX;
                        // 展开循环，避免内层循环与下标加法开销（语义与逐列拷贝等价）
                        $dstPlane[$d]      = $refPlane[$s];
                        $dstPlane[$d + 1]  = $refPlane[$s + 1];
                        $dstPlane[$d + 2]  = $refPlane[$s + 2];
                        $dstPlane[$d + 3]  = $refPlane[$s + 3];
                        $dstPlane[$d + 4]  = $refPlane[$s + 4];
                        $dstPlane[$d + 5]  = $refPlane[$s + 5];
                        $dstPlane[$d + 6]  = $refPlane[$s + 6];
                        $dstPlane[$d + 7]  = $refPlane[$s + 7];
                        $dstPlane[$d + 8]  = $refPlane[$s + 8];
                        $dstPlane[$d + 9]  = $refPlane[$s + 9];
                        $dstPlane[$d + 10] = $refPlane[$s + 10];
                        $dstPlane[$d + 11] = $refPlane[$s + 11];
                        $dstPlane[$d + 12] = $refPlane[$s + 12];
                        $dstPlane[$d + 13] = $refPlane[$s + 13];
                        $dstPlane[$d + 14] = $refPlane[$s + 14];
                        $dstPlane[$d + 15] = $refPlane[$s + 15];
                    }
                    return;
                }
                // B_Direct/B_Skip 主体是 8 宽块（4 象限 8x8）：整数 MV 高频，同样展开
                if ($blockW === 8) {
                    // 每行连续拷贝 8 个样本
                    for ($j = 0; $j < $blockH; $j++) {
                        $s = ($intY + $j) * $refStride + $intX;
                        $d = ($dstY + $j) * $dstStride + $dstX;
                        // 展开 8 列拷贝
                        $dstPlane[$d]     = $refPlane[$s];
                        $dstPlane[$d + 1] = $refPlane[$s + 1];
                        $dstPlane[$d + 2] = $refPlane[$s + 2];
                        $dstPlane[$d + 3] = $refPlane[$s + 3];
                        $dstPlane[$d + 4] = $refPlane[$s + 4];
                        $dstPlane[$d + 5] = $refPlane[$s + 5];
                        $dstPlane[$d + 6] = $refPlane[$s + 6];
                        $dstPlane[$d + 7] = $refPlane[$s + 7];
                    }
                    return;
                }
                // 其它宽度（4、8x16 等）：通用双循环拷贝
                for ($j = 0; $j < $blockH; $j++) {
                    $srcBase = ($intY + $j) * $refStride + $intX;
                    $dstBase = ($dstY + $j) * $dstStride + $dstX;
                    // 逐列拷贝该行
                    for ($i = 0; $i < $blockW; $i++) {
                        $dstPlane[$dstBase + $i] = $refPlane[$srcBase + $i];
                    }
                }
                return;
            }

            // 参考块部分越界：整数 MV 的裁剪等价于对每个坐标单独 clamp 到边界
            // x 方向：先按块内列偏移预计算 clamp 后的绝对列下标，避免内层重复判断
            $xIndex = [];
            for ($i = 0; $i < $blockW; $i++) {
                $sx = $intX + $i;
                // 越界取最近边界样本（规范 8.4.2.2 参考样本裁剪语义）
                $xIndex[$i] = $sx < 0 ? 0 : ($sx > $maxX ? $maxX : $sx);
            }
            // y 方向：逐行 clamp 行号，再按预计算的列下标取值
            for ($j = 0; $j < $blockH; $j++) {
                $sy = $intY + $j;
                $sy = $sy < 0 ? 0 : ($sy > $maxY ? $maxY : $sy);
                $srcBase = $sy * $refStride;
                $dstBase = ($dstY + $j) * $dstStride + $dstX;
                // 逐列写目标
                for ($i = 0; $i < $blockW; $i++) {
                    $dstPlane[$dstBase + $i] = $refPlane[$srcBase + $xIndex[$i]];
                }
            }
            return;
        }

        // 有分数时统一使用 $refBytes 访问参考样本；未传入则直接引用参考平面
        if ($refBytes === null) {
            $refBytes = $refPlane;
        }

        // 分支二：仅水平方向有分数（fracY==0），即 a/b/c 三个位置
        if ($fracY === 0) {
            // fracX==3 对应 c 点：需与右侧整像素 H（intX+1）平均；fracX==1 对应 a 点：与 G（intX）平均
            $srcOffX = $fracX === 3 ? 1 : 0;
            // interior 判定：保证 6 抽头窗口 intX-2 .. intX+blockW+2 全部在图像内
            $interiorX = $intX >= 2 && $intX + $blockW + 2 <= $maxX;
            if ($interiorX) {
                // interior：6 抽头滑动窗口递推（与直接卷积整数严格等价）：
                // S' = S + x_new - x_drop + 6*(t1 - t5) + 25*(t4 - t2)
                // 逐行处理（每行水平方向独立）
                for ($j = 0; $j < $blockH; $j++) {
                    // 行号仍需裁剪（y 方向可能越界）
                    $sy = $intY + $j;
                    $sy = $sy < 0 ? 0 : ($sy > $maxY ? $maxY : $sy);
                    // 该行窗口起点的采样下标（对应 intX 位置）
                    $srcBase = $sy * $refStride + $intX;
                    // 目标行首下标
                    $dstBase = ($dstY + $j) * $dstStride + $dstX;

                    // 初始化 6 个抽头：t0..t5 对应 x-2、x-1、x、x+1、x+2、x+3
                    $t0 = $refBytes[$srcBase - 2];
                    $t1 = $refBytes[$srcBase - 1];
                    $t2 = $refBytes[$srcBase];
                    $t3 = $refBytes[$srcBase + 1];
                    $t4 = $refBytes[$srcBase + 2];
                    $t5 = $refBytes[$srcBase + 3];
                    // 未归一化的卷积和：(t0+t5) + 20*(t2+t3) - 5*(t1+t4)，增益为 32
                    $sum = ($t0 + $t5) + 20 * ($t2 + $t3) - 5 * ($t1 + $t4);

                    // 逐列滑窗：每列输出一个 b 点，并按 fracX 合成 1/4 像素
                    for ($i = 0; $i < $blockW; $i++) {
                        // 半像素 b：+16 >> 5 做四舍五入并归一化（增益 32）
                        $val = ($sum + 16) >> 5;
                        // clip 到 8bit（规范中半像素中间结果也要 Clip3）
                        $val = $val < 0 ? 0 : ($val > 255 ? 255 : $val);
                        // fracX==1 时 a=(G+b+1)>>1；fracX==3 时 c=(H+b+1)>>1
                        if ($fracX !== 2) {
                            // 与相邻整像素平均，+1 为四舍五入（规范 8.4.2.2.1 的 1/4 像素合成）
                            $val = ($refBytes[$srcBase + $i + $srcOffX] + $val + 1) >> 1;
                        }
                        // 写入目标像素
                        $dstPlane[$dstBase + $i] = $val;

                        // 窗口右移一列：读入新抽头并递推更新卷积和
                        if ($i + 1 < $blockW) {
                            // 新进入窗口的样本 x+4
                            $tn = $refBytes[$srcBase + $i + 4];
                            // 递推式与逐点卷积等价，避免内层重新做 6 次乘加
                            $sum = $sum + $tn - $t0 + 6 * ($t1 - $t5) + 25 * ($t4 - $t2);
                            // 抽头寄存器整体左移，tn 成为新的 t5
                            $t0 = $t1;
                            $t1 = $t2;
                            $t2 = $t3;
                            $t3 = $t4;
                            $t4 = $t5;
                            $t5 = $tn;
                        }
                    }
                }
                return;
            }

            // 边界路径：逐像素做 6 抽头，并对每个抽头单独 clamp（允许窗口越出图像）
            for ($j = 0; $j < $blockH; $j++) {
                // 行号 clamp
                $sy = $intY + $j;
                $sy = $sy < 0 ? 0 : ($sy > $maxY ? $maxY : $sy);
                $srcBase = $sy * $refStride;
                $dstBase = ($dstY + $j) * $dstStride + $dstX;
                for ($i = 0; $i < $blockW; $i++) {
                    // 当前待插值位置（整数部分 + 块内列偏移）
                    $sx = $intX + $i;
                    // 6 个抽头下标 x-2..x+3，各自 clamp 到 [0, maxX]
                    $x0 = $sx - 2 < 0 ? 0 : ($sx - 2 > $maxX ? $maxX : $sx - 2);
                    $x1 = $sx - 1 < 0 ? 0 : ($sx - 1 > $maxX ? $maxX : $sx - 1);
                    $x2 = $sx < 0 ? 0 : ($sx > $maxX ? $maxX : $sx);
                    $x3 = $sx + 1 < 0 ? 0 : ($sx + 1 > $maxX ? $maxX : $sx + 1);
                    $x4 = $sx + 2 < 0 ? 0 : ($sx + 2 > $maxX ? $maxX : $sx + 2);
                    $x5 = $sx + 3 < 0 ? 0 : ($sx + 3 > $maxX ? $maxX : $sx + 3);
                    // 直接卷积：(1,-5,20,20,-5,1) 点乘
                    $val = ($refBytes[$srcBase + $x0] + $refBytes[$srcBase + $x5])
                        + 20 * ($refBytes[$srcBase + $x2] + $refBytes[$srcBase + $x3])
                        - 5 * ($refBytes[$srcBase + $x1] + $refBytes[$srcBase + $x4]);
                    // 归一化 + 四舍五入（>>5，增益 32）
                    $val = ($val + 16) >> 5;
                    // clip 到 8bit
                    $val = $val < 0 ? 0 : ($val > 255 ? 255 : $val);
                    // 1/4 像素合成：与 G 或 H 平均
                    if ($fracX !== 2) {
                        // 相邻整像素位置同样需要 clamp（H 可能在图像右边界外）
                        $sx += $srcOffX;
                        $sx = $sx < 0 ? 0 : ($sx > $maxX ? $maxX : $sx);
                        $val = ($refBytes[$srcBase + $sx] + $val + 1) >> 1;
                    }
                    // 写入目标像素
                    $dstPlane[$dstBase + $i] = $val;
                }
            }
            return;
        }

        // 分支三：仅垂直方向有分数（fracX==0），即 d/h/n 三个位置
        if ($fracX === 0) {
            // fracY==3 对应 n 点：与下方整像素 M（intY+1）平均；fracY==1 对应 d 点：与 G（intY）平均
            $srcOffY = $fracY === 3 ? 1 : 0;
            // 列方向是否整块落在图像内（内层因此可省去逐点 clamp）
            $interiorX = $intX >= 0 && $intX + $blockW <= $refWidth;
            // interior：x 不夹边、y 窗口（sy-2..sy+3）全程不夹边时，按列滑动递推，
            // 每像素只需读 1 个新 tap 点（原路径 6 个），与直接卷积整数严格等价
            $interiorY = $intY >= 2 && $intY + $blockH + 2 <= $maxY;
            if ($interiorX && $interiorY) {
                // 外层按列遍历（垂直方向插值，逐列独立）
                for ($i = 0; $i < $blockW; $i++) {
                    // 当前列绝对下标
                    $sx = $intX + $i;
                    // 窗口顶部（intY-2 行）的采样下标
                    $off = ($intY - 2) * $refStride + $sx;
                    // 初始化 6 个垂直抽头：y-2..y+3
                    $t0 = $refBytes[$off];
                    $t1 = $refBytes[$off + $refStride];
                    $t2 = $refBytes[$off + 2 * $refStride];
                    $t3 = $refBytes[$off + 3 * $refStride];
                    $t4 = $refBytes[$off + 4 * $refStride];
                    $t5 = $refBytes[$off + 5 * $refStride];
                    // 未归一化卷积和（增益 32）
                    $s = ($t0 + $t5) + 20 * ($t2 + $t3) - 5 * ($t1 + $t4);
                    // 目标列在目标平面中的首下标（第 0 行）
                    $dstOff = $dstY * $dstStride + $dstX + $i;
                    // 用于 1/4 合成时取相邻整像素（d 用 intY 行，n 用 intY+1 行）
                    $srcRow = ($intY + $srcOffY) * $refStride + $sx;
                    // 沿列向下滑窗
                    for ($j = 0; $j < $blockH; $j++) {
                        // 半像素 h：归一化 + 四舍五入
                        $val = ($s + 16) >> 5;
                        // clip 到 8bit
                        $val = $val < 0 ? 0 : ($val > 255 ? 255 : $val);
                        // fracY==1 时 d=(G+h+1)>>1；fracY==3 时 n=(M+h+1)>>1
                        if ($fracY !== 2) {
                            // 与相邻整像素平均（+1 四舍五入）
                            $val = ($refBytes[$srcRow] + $val + 1) >> 1;
                        }
                        // 写入目标像素
                        $dstPlane[$dstOff] = $val;
                        // 窗口下移一行：读入新抽头并递推
                        if ($j + 1 < $blockH) {
                            // 新进入窗口的样本 y+4
                            $tn = $refBytes[$off + 6 * $refStride];
                            // 递推式与逐点卷积等价
                            $s = $s + $tn - $t0 + 6 * ($t1 - $t5) + 25 * ($t4 - $t2);
                            // 抽头寄存器整体上移
                            $t0 = $t1; $t1 = $t2; $t2 = $t3; $t3 = $t4; $t4 = $t5; $t5 = $tn;
                        }
                        // 推进到下一行的窗口起点、目标位置与整像素参考位置
                        $off += $refStride;
                        $dstOff += $dstStride;
                        $srcRow += $refStride;
                    }
                }
                return;
            }
            // 边界路径：逐像素做垂直 6 抽头，并对每个抽头行号 clamp
            for ($j = 0; $j < $blockH; $j++) {
                // 目标行对应的插值位置
                $sy = $intY + $j;
                // 6 个抽头行号 y-2..y+3，各自 clamp 到 [0, maxY]
                $y0 = $sy - 2 < 0 ? 0 : ($sy - 2 > $maxY ? $maxY : $sy - 2);
                $y1 = $sy - 1 < 0 ? 0 : ($sy - 1 > $maxY ? $maxY : $sy - 1);
                $y2 = $sy < 0 ? 0 : ($sy > $maxY ? $maxY : $sy);
                $y3 = $sy + 1 < 0 ? 0 : ($sy + 1 > $maxY ? $maxY : $sy + 1);
                $y4 = $sy + 2 < 0 ? 0 : ($sy + 2 > $maxY ? $maxY : $sy + 2);
                $y5 = $sy + 3 < 0 ? 0 : ($sy + 3 > $maxY ? $maxY : $sy + 3);
                // 预计算 6 行的行首偏移，内层只需加列下标
                $row0 = $y0 * $refStride;
                $row1 = $y1 * $refStride;
                $row2 = $y2 * $refStride;
                $row3 = $y3 * $refStride;
                $row4 = $y4 * $refStride;
                $row5 = $y5 * $refStride;
                // 1/4 合成用的相邻整像素行（d 或 n），行号需 clamp
                $srcY = $sy + $srcOffY;
                $srcY = $srcY < 0 ? 0 : ($srcY > $maxY ? $maxY : $srcY);
                $srcBase = $srcY * $refStride;
                // 目标行首下标
                $dstBase = ($dstY + $j) * $dstStride + $dstX;
                // 逐列处理
                for ($i = 0; $i < $blockW; $i++) {
                    // 当前列
                    $sx = $intX + $i;
                    // 列方向越界时 clamp（interiorX 为真时整行都在图像内，无需判断）
                    if (!$interiorX) {
                        $sx = $sx < 0 ? 0 : ($sx > $maxX ? $maxX : $sx);
                    }
                    // 垂直 6 抽头卷积（系数 1,-5,20,20,-5,1）
                    $val = ($refBytes[$row0 + $sx] + $refBytes[$row5 + $sx])
                        + 20 * ($refBytes[$row2 + $sx] + $refBytes[$row3 + $sx])
                        - 5 * ($refBytes[$row1 + $sx] + $refBytes[$row4 + $sx]);
                    // 归一化 + 四舍五入
                    $val = ($val + 16) >> 5;
                    // clip 到 8bit
                    $val = $val < 0 ? 0 : ($val > 255 ? 255 : $val);
                    // 1/4 像素合成：h 与整像素 G/M 平均
                    if ($fracY !== 2) {
                        $val = ($refBytes[$srcBase + $sx] + $val + 1) >> 1;
                    }
                    // 写入目标像素
                    $dstPlane[$dstBase + $i] = $val;
                }
            }
            return;
        }

        // 分支四：两个方向都有分数（fracX!=0 且 fracY!=0）
        // firstOffset 用于在「垂直低通结果」中跳过顶部多算的 2 行
        $firstOffset = 0;
        // 4.1 位置 j（双半像素）：直接由可分离两步滤波（水平 6 抽头 → 垂直 6 抽头）得到
        if ($fracX === 2 && $fracY === 2) {
            $first = $this->lumaHvLowpass($refBytes, $refStride, $refWidth, $refHeight, $intX, $intY, $blockW, $blockH);
            // 无第二个结果，最终循环不做平均
            $second = null;
            $secondOffset = 0;
        // 4.2 fracX==2（b 列）：水平半像素在「当前行或其下一行」 + j，再平均得到 f 或 q
        } elseif ($fracX === 2) {
            // fracY==1：取 intY 行的水平半像素 b；fracY==3：取 intY+1 行的水平半像素 s
            $first = $this->lumaHorizontalLowpass($refBytes, $refStride, $refWidth, $refHeight, $intX, $intY + ($fracY === 1 ? 0 : 1), $blockW, $blockH);
            // 第二个结果：整数原点的 j（水平+垂直各一次 6 抽头）
            $second = $this->lumaHvLowpass($refBytes, $refStride, $refWidth, $refHeight, $intX, $intY, $blockW, $blockH);
            $secondOffset = 0;
        // 4.3 fracY==2（h 行）：垂直半像素在「当前列或其右一列」 + j，再平均得到 i 或 p
        } elseif ($fracY === 2) {
            // 垂直低通需要从 intY-2 行开始，高度多算 4 行（覆盖 j 的垂直窗口）
            $first = $this->lumaVerticalLowpass($refBytes, $refStride, $refWidth, $refHeight, $intX + ($fracX === 1 ? 0 : 1), $intY - 2, $blockW, $blockH + 4);
            // 跳过顶部 2 行，使第 0 行对应 intY 处的垂直半像素
            $firstOffset = 2 * $blockW;
            $second = $this->lumaHvLowpass($refBytes, $refStride, $refWidth, $refHeight, $intX, $intY, $blockW, $blockH);
            $secondOffset = 0;
        } else {
            // 4.4 两个方向都是 1/4 奇数位置（e/g/p/r）：分别求水平半像素与垂直半像素再平均
            // 水平半像素所在行：fracY==1 用 intY 行，fracY==3 用 intY+1 行
            $horizontalY = $intY + ($fracY === 3 ? 1 : 0);
            // 垂直半像素所在列：fracX==1 用 intX 列，fracX==3 用 intX+1 列
            $verticalX = $intX + ($fracX === 3 ? 1 : 0);
            // 水平 6 抽头窗口是否完全在图像内
            $interiorHorizontalX = $intX >= 2 && $intX + $blockW + 2 <= $maxX;
            // 垂直半像素的列范围是否完全在图像内
            $interiorVerticalX = $verticalX >= 0 && $verticalX + $blockW <= $refWidth;

            if ($interiorHorizontalX) {
                // 行内水平半像素可用滑动窗口递推；垂直半像素仍逐点计算
                for ($j = 0; $j < $blockH; $j++) {
                    // 水平半像素所在行的行号（需 clamp）
                    $horizontalSrcY = $horizontalY + $j;
                    $horizontalSrcY = $horizontalSrcY < 0 ? 0 : ($horizontalSrcY > $maxY ? $maxY : $horizontalSrcY);
                    // 该行窗口起点（对应 intX 位置）
                    $hr = $horizontalSrcY * $refStride + $intX;

                    // 垂直半像素对应的插值行位置
                    $verticalSrcY = $intY + $j;
                    // 垂直 6 抽头行号 y-2..y+3，各自 clamp
                    $y0 = $verticalSrcY - 2 < 0 ? 0 : ($verticalSrcY - 2 > $maxY ? $maxY : $verticalSrcY - 2);
                    $y1 = $verticalSrcY - 1 < 0 ? 0 : ($verticalSrcY - 1 > $maxY ? $maxY : $verticalSrcY - 1);
                    $y2 = $verticalSrcY < 0 ? 0 : ($verticalSrcY > $maxY ? $maxY : $verticalSrcY);
                    $y3 = $verticalSrcY + 1 < 0 ? 0 : ($verticalSrcY + 1 > $maxY ? $maxY : $verticalSrcY + 1);
                    $y4 = $verticalSrcY + 2 < 0 ? 0 : ($verticalSrcY + 2 > $maxY ? $maxY : $verticalSrcY + 2);
                    $y5 = $verticalSrcY + 3 < 0 ? 0 : ($verticalSrcY + 3 > $maxY ? $maxY : $verticalSrcY + 3);
                    // 预计算 6 行行首偏移
                    $row0 = $y0 * $refStride;
                    $row1 = $y1 * $refStride;
                    $row2 = $y2 * $refStride;
                    $row3 = $y3 * $refStride;
                    $row4 = $y4 * $refStride;
                    $row5 = $y5 * $refStride;
                    // 目标行首下标
                    $dstBase = ($dstY + $j) * $dstStride + $dstX;

                    // 初始化水平 6 抽头
                    $t0 = $refBytes[$hr - 2];
                    $t1 = $refBytes[$hr - 1];
                    $t2 = $refBytes[$hr];
                    $t3 = $refBytes[$hr + 1];
                    $t4 = $refBytes[$hr + 2];
                    $t5 = $refBytes[$hr + 3];
                    // 水平方向未归一化卷积和
                    $hsum = ($t0 + $t5) + 20 * ($t2 + $t3) - 5 * ($t1 + $t4);

                    // 逐列：先水平半像素，再垂直半像素，最后平均
                    for ($i = 0; $i < $blockW; $i++) {
                        // 水平半像素：归一化 + 四舍五入 + clip
                        $horizontal = ($hsum + 16) >> 5;
                        $horizontal = $horizontal < 0 ? 0 : ($horizontal > 255 ? 255 : $horizontal);

                        // 垂直半像素所在列
                        $verticalSrcX = $verticalX + $i;
                        // 列越界时 clamp（列范围在图像内则跳过）
                        if (!$interiorVerticalX) {
                            $verticalSrcX = $verticalSrcX < 0 ? 0 : ($verticalSrcX > $maxX ? $maxX : $verticalSrcX);
                        }
                        // 垂直 6 抽头卷积
                        $vertical = ($refBytes[$row0 + $verticalSrcX] + $refBytes[$row5 + $verticalSrcX])
                            + 20 * ($refBytes[$row2 + $verticalSrcX] + $refBytes[$row3 + $verticalSrcX])
                            - 5 * ($refBytes[$row1 + $verticalSrcX] + $refBytes[$row4 + $verticalSrcX]);
                        // 垂直半像素：归一化 + 四舍五入 + clip
                        $vertical = ($vertical + 16) >> 5;
                        $vertical = $vertical < 0 ? 0 : ($vertical > 255 ? 255 : $vertical);

                        // 两个半像素平均得到 1/4 像素（+1 四舍五入）
                        $dstPlane[$dstBase + $i] = ($horizontal + $vertical + 1) >> 1;

                        // 水平窗口右移一列：读入新抽头并递推
                        if ($i + 1 < $blockW) {
                            $tn = $refBytes[$hr + $i + 4];
                            $hsum = $hsum + $tn - $t0 + 6 * ($t1 - $t5) + 25 * ($t4 - $t2);
                            $t0 = $t1;
                            $t1 = $t2;
                            $t2 = $t3;
                            $t3 = $t4;
                            $t4 = $t5;
                            $t5 = $tn;
                        }
                    }
                }
                return;
            }

            // 边界路径：两个方向都逐点做 6 抽头，所有抽头下标各自 clamp
            for ($j = 0; $j < $blockH; $j++) {
                // 水平半像素所在行（clamp）
                $horizontalSrcY = $horizontalY + $j;
                $horizontalSrcY = $horizontalSrcY < 0 ? 0 : ($horizontalSrcY > $maxY ? $maxY : $horizontalSrcY);
                $horizontalRow = $horizontalSrcY * $refStride;

                // 垂直半像素对应的插值行位置
                $verticalSrcY = $intY + $j;
                // 垂直 6 抽头行号 clamp
                $y0 = $verticalSrcY - 2 < 0 ? 0 : ($verticalSrcY - 2 > $maxY ? $maxY : $verticalSrcY - 2);
                $y1 = $verticalSrcY - 1 < 0 ? 0 : ($verticalSrcY - 1 > $maxY ? $maxY : $verticalSrcY - 1);
                $y2 = $verticalSrcY < 0 ? 0 : ($verticalSrcY > $maxY ? $maxY : $verticalSrcY);
                $y3 = $verticalSrcY + 1 < 0 ? 0 : ($verticalSrcY + 1 > $maxY ? $maxY : $verticalSrcY + 1);
                $y4 = $verticalSrcY + 2 < 0 ? 0 : ($verticalSrcY + 2 > $maxY ? $maxY : $verticalSrcY + 2);
                $y5 = $verticalSrcY + 3 < 0 ? 0 : ($verticalSrcY + 3 > $maxY ? $maxY : $verticalSrcY + 3);
                // 预计算 6 行行首偏移
                $row0 = $y0 * $refStride;
                $row1 = $y1 * $refStride;
                $row2 = $y2 * $refStride;
                $row3 = $y3 * $refStride;
                $row4 = $y4 * $refStride;
                $row5 = $y5 * $refStride;
                // 目标行首下标
                $dstBase = ($dstY + $j) * $dstStride + $dstX;

                for ($i = 0; $i < $blockW; $i++) {
                    // 水平方向 6 个抽头下标 x-2..x+3，各自 clamp
                    $horizontalSrcX = $intX + $i;
                    $x0 = $horizontalSrcX - 2 < 0 ? 0 : ($horizontalSrcX - 2 > $maxX ? $maxX : $horizontalSrcX - 2);
                    $x1 = $horizontalSrcX - 1 < 0 ? 0 : ($horizontalSrcX - 1 > $maxX ? $maxX : $horizontalSrcX - 1);
                    $x2 = $horizontalSrcX < 0 ? 0 : ($horizontalSrcX > $maxX ? $maxX : $horizontalSrcX);
                    $x3 = $horizontalSrcX + 1 < 0 ? 0 : ($horizontalSrcX + 1 > $maxX ? $maxX : $horizontalSrcX + 1);
                    $x4 = $horizontalSrcX + 2 < 0 ? 0 : ($horizontalSrcX + 2 > $maxX ? $maxX : $horizontalSrcX + 2);
                    $x5 = $horizontalSrcX + 3 < 0 ? 0 : ($horizontalSrcX + 3 > $maxX ? $maxX : $horizontalSrcX + 3);
                    // 水平半像素直接卷积
                    $horizontal = ($refBytes[$horizontalRow + $x0] + $refBytes[$horizontalRow + $x5])
                        + 20 * ($refBytes[$horizontalRow + $x2] + $refBytes[$horizontalRow + $x3])
                        - 5 * ($refBytes[$horizontalRow + $x1] + $refBytes[$horizontalRow + $x4]);
                    // 归一化 + 四舍五入 + clip
                    $horizontal = ($horizontal + 16) >> 5;
                    $horizontal = $horizontal < 0 ? 0 : ($horizontal > 255 ? 255 : $horizontal);

                    // 垂直半像素所在列（越界时 clamp）
                    $verticalSrcX = $verticalX + $i;
                    if (!$interiorVerticalX) {
                        $verticalSrcX = $verticalSrcX < 0 ? 0 : ($verticalSrcX > $maxX ? $maxX : $verticalSrcX);
                    }
                    // 垂直半像素直接卷积
                    $vertical = ($refBytes[$row0 + $verticalSrcX] + $refBytes[$row5 + $verticalSrcX])
                        + 20 * ($refBytes[$row2 + $verticalSrcX] + $refBytes[$row3 + $verticalSrcX])
                        - 5 * ($refBytes[$row1 + $verticalSrcX] + $refBytes[$row4 + $verticalSrcX]);
                    // 归一化 + 四舍五入 + clip
                    $vertical = ($vertical + 16) >> 5;
                    $vertical = $vertical < 0 ? 0 : ($vertical > 255 ? 255 : $vertical);

                    // 两个半像素平均得到 1/4 像素
                    $dstPlane[$dstBase + $i] = ($horizontal + $vertical + 1) >> 1;
                }
            }
            return;
        }

        // 4.1~4.3 的统一收尾：把 first（必要时与 second）合并写入目标块
        for ($j = 0; $j < $blockH; $j++) {
            // first/second 都是紧凑的 blockW 宽中间结果
            $base = $j * $blockW;
            $dstBase = ($dstY + $j) * $dstStride + $dstX;
            for ($i = 0; $i < $blockW; $i++) {
                // 取第一路中间结果（可能带顶部偏移）
                $val = $first[$base + $firstOffset + $i];
                // 若存在第二路（j 位置之外的情况），做 (a+b+1)>>1 平均得到 1/4 像素
                if ($second !== null) {
                    $val = ($val + $second[$base + $secondOffset + $i] + 1) >> 1;
                }
                // 写入目标像素
                $dstPlane[$dstBase + $i] = $val;
            }
        }
    }

    /**
     * 水平 6 抽头低通（半像素水平插值的中间结果）。
     *
     * 对 h 行 × w 列区域逐点计算 b 类型半像素值：
     *   b = (p[x-2] - 5*p[x-1] + 20*p[x] + 20*p[x+1] - 5*p[x+2] + p[x+3] + 16) >> 5
     * 随后 clip 到 0..255（规范 8.4.2.2.1 中半像素中间结果）。
     * 输出为一维紧凑数组（raster 顺序，行宽 w），不含顶部/左侧偏移。
     */
    private function lumaHorizontalLowpass(array $refBytes, int $stride, int $width, int $height, int $srcX, int $srcY, int $w, int $h): array
    {
        // 有效坐标上界
        $maxX = $width - 1;
        $maxY = $height - 1;
        // 输出缓冲：按行优先顺序追加
        $out = [];
        // interior 判定：保证窗口 srcX-2 .. srcX+w+2 全在图像内（右边 2 个为补偿窗口的 +3）
        if ($srcX >= 2 && $srcX + $w + 2 <= $maxX) {
            // 逐行处理
            for ($j = 0; $j < $h; $j++) {
                // 行号 clamp（垂直方向可能越界）
                $sy = $srcY + $j;
                $sy = $sy < 0 ? 0 : ($sy > $maxY ? $maxY : $sy);
                // 该行窗口起点（对应 srcX）
                $row = $sy * $stride + $srcX;

                // 初始化 6 个抽头 x-2..x+3
                $t0 = $refBytes[$row - 2];
                $t1 = $refBytes[$row - 1];
                $t2 = $refBytes[$row];
                $t3 = $refBytes[$row + 1];
                $t4 = $refBytes[$row + 2];
                $t5 = $refBytes[$row + 3];
                // 未归一化卷积和（增益 32）
                $sum = ($t0 + $t5) + 20 * ($t2 + $t3) - 5 * ($t1 + $t4);

                // 逐列输出
                for ($i = 0; $i < $w; $i++) {
                    // 归一化 + 四舍五入
                    $val = ($sum + 16) >> 5;
                    // clip 到 8bit 后写入输出
                    $out[] = $val < 0 ? 0 : ($val > 255 ? 255 : $val);
                    // 滑窗右移：读入新抽头并递推
                    if ($i + 1 < $w) {
                        $tn = $refBytes[$row + $i + 4];
                        // 递推更新未归一化和（与逐点卷积等价）
                        $sum = $sum + $tn - $t0 + 6 * ($t1 - $t5) + 25 * ($t4 - $t2);
                        // 抽头寄存器左移
                        $t0 = $t1;
                        $t1 = $t2;
                        $t2 = $t3;
                        $t3 = $t4;
                        $t4 = $t5;
                        $t5 = $tn;
                    }
                }
            }
            return $out;
        }

        // 边界路径：逐像素 clamp 6 个抽头下标后再卷积
        for ($j = 0; $j < $h; $j++) {
            // 行号 clamp
            $sy = $srcY + $j;
            $sy = $sy < 0 ? 0 : ($sy > $maxY ? $maxY : $sy);
            // 该行行首偏移（不含列偏移）
            $row = $sy * $stride;
            // 逐列处理
            for ($i = 0; $i < $w; $i++) {
                // 当前列
                $sx = $srcX + $i;
                // 6 个抽头下标各自 clamp 到 [0, maxX]
                $x0 = $sx - 2 < 0 ? 0 : ($sx - 2 > $maxX ? $maxX : $sx - 2);
                $x1 = $sx - 1 < 0 ? 0 : ($sx - 1 > $maxX ? $maxX : $sx - 1);
                $x2 = $sx < 0 ? 0 : ($sx > $maxX ? $maxX : $sx);
                $x3 = $sx + 1 < 0 ? 0 : ($sx + 1 > $maxX ? $maxX : $sx + 1);
                $x4 = $sx + 2 < 0 ? 0 : ($sx + 2 > $maxX ? $maxX : $sx + 2);
                $x5 = $sx + 3 < 0 ? 0 : ($sx + 3 > $maxX ? $maxX : $sx + 3);
                // 直接卷积（系数 1,-5,20,20,-5,1）
                $val = ($refBytes[$row + $x0] + $refBytes[$row + $x5])
                    + 20 * ($refBytes[$row + $x2] + $refBytes[$row + $x3])
                    - 5 * ($refBytes[$row + $x1] + $refBytes[$row + $x4]);
                // 归一化 + 四舍五入
                $val = ($val + 16) >> 5;
                // clip 到 8bit 后写入输出
                $out[] = $val < 0 ? 0 : ($val > 255 ? 255 : $val);
            }
        }
        return $out;
    }

    /**
     * 垂直 6 抽头低通（半像素垂直插值的中间结果）。
     *
     *   h = (p[y-2] - 5*p[y-1] + 20*p[y] + 20*p[y+1] - 5*p[y+2] + p[y+3] + 16) >> 5
     * 逐列在垂直方向取 6 个抽头，行号越界时 clamp（规范 8.4.2.2 参考样本裁剪），
     * 结果 clip 到 0..255，按 raster 顺序输出到紧凑数组（行宽 w）。
     * 允许 $srcY 为负（调用方需要从 intY-2 起算），因此 $h 可能大于目标块高。
     */
    private function lumaVerticalLowpass(array $refBytes, int $stride, int $width, int $height, int $srcX, int $srcY, int $w, int $h): array
    {
        // 有效坐标上界
        $maxX = $width - 1;
        $maxY = $height - 1;
        // 列方向是否整段在图像内（是则内层无需逐点 clamp）
        $interiorX = $srcX >= 0 && $srcX + $w <= $width;
        // 输出缓冲
        $out = [];
        // 逐行（每个输出行都要在垂直方向取 6 个抽头）
        for ($j = 0; $j < $h; $j++) {
            // 当前输出行对应的插值位置
            $sy = $srcY + $j;
            // 6 个抽头行号 y-2..y+3，各自 clamp 到 [0, maxY]
            $y0 = $sy - 2 < 0 ? 0 : ($sy - 2 > $maxY ? $maxY : $sy - 2);
            $y1 = $sy - 1 < 0 ? 0 : ($sy - 1 > $maxY ? $maxY : $sy - 1);
            $y2 = $sy < 0 ? 0 : ($sy > $maxY ? $maxY : $sy);
            $y3 = $sy + 1 < 0 ? 0 : ($sy + 1 > $maxY ? $maxY : $sy + 1);
            $y4 = $sy + 2 < 0 ? 0 : ($sy + 2 > $maxY ? $maxY : $sy + 2);
            $y5 = $sy + 3 < 0 ? 0 : ($sy + 3 > $maxY ? $maxY : $sy + 3);
            // 预计算 6 行行首偏移
            $row0 = $y0 * $stride;
            $row1 = $y1 * $stride;
            $row2 = $y2 * $stride;
            $row3 = $y3 * $stride;
            $row4 = $y4 * $stride;
            $row5 = $y5 * $stride;
            // 逐列输出
            for ($i = 0; $i < $w; $i++) {
                // 当前列
                $sx = $srcX + $i;
                // 列越界时 clamp（整段在图像内则跳过）
                if (!$interiorX) {
                    $sx = $sx < 0 ? 0 : ($sx > $maxX ? $maxX : $sx);
                }
                // 垂直 6 抽头卷积
                $val = ($refBytes[$row0 + $sx] + $refBytes[$row5 + $sx])
                    + 20 * ($refBytes[$row2 + $sx] + $refBytes[$row3 + $sx])
                    - 5 * ($refBytes[$row1 + $sx] + $refBytes[$row4 + $sx]);
                // 归一化 + 四舍五入
                $val = ($val + 16) >> 5;
                // clip 到 8bit 后写入输出
                $out[] = $val < 0 ? 0 : ($val > 255 ? 255 : $val);
            }
        }
        return $out;
    }

    /**
     * 可分离二维 6 抽头低通（水平 → 垂直），用于半像素「双半」位置 j。
     *
     * 规范 8.4.2.2.1 中 j 的计算等价于对整数样本先做水平 6 抽头、再做垂直 6 抽头，
     * 两级增益各为 32，合计 1024，因此最终一次 (sum + 512) >> 10 归一化并四舍五入。
     * 第一阶段刻意不取整、不 clip（保留未归一化的卷积和），
     * 这样第二阶段的线性组合与规范中「先算中间值再合并」的结果严格一致。
     */
    private function lumaHvLowpass(array $refBytes, int $stride, int $width, int $height, int $srcX, int $srcY, int $w, int $h): array
    {
        // 有效坐标上界
        $maxX = $width - 1;
        $maxY = $height - 1;
        // 垂直滤波需要 srcY-2 .. srcY+h+2 共 h+5 行中间结果
        $tmpH = $h + 5;
        // 第一阶段输出：h+5 行 × w 列的未归一化水平卷积和
        $tmp = [];
        // 水平窗口是否完全在图像内（决定第一阶段是否可递推）
        $interiorX = $srcX >= 2 && $srcX + $w + 2 <= $maxX;
        if ($interiorX) {
            // 第一阶段（interior）：逐行做水平 6 抽头滑动递推
            for ($j = 0; $j < $tmpH; $j++) {
                // 中间结果覆盖的行号：从 srcY-2 开始（为垂直滤波预留上方 2 行）
                $sy = $srcY - 2 + $j;
                $sy = $sy < 0 ? 0 : ($sy > $maxY ? $maxY : $sy);
                // 该行窗口起点
                $hr = $sy * $stride + $srcX;

                // 初始化 6 个水平抽头
                $t0 = $refBytes[$hr - 2];
                $t1 = $refBytes[$hr - 1];
                $t2 = $refBytes[$hr];
                $t3 = $refBytes[$hr + 1];
                $t4 = $refBytes[$hr + 2];
                $t5 = $refBytes[$hr + 3];
                // 中间值保存未取整的卷积和（供第二阶段合并归一化）
                $sum = ($t0 + $t5) + 20 * ($t2 + $t3) - 5 * ($t1 + $t4);

                // 逐列写入中间结果（注意：这里不 >>5、不 clip）
                for ($i = 0; $i < $w; $i++) {
                    $tmp[] = $sum;
                    // 滑窗右移：读入新抽头并递推
                    if ($i + 1 < $w) {
                        $tn = $refBytes[$hr + $i + 4];
                        $sum = $sum + $tn - $t0 + 6 * ($t1 - $t5) + 25 * ($t4 - $t2);
                        $t0 = $t1;
                        $t1 = $t2;
                        $t2 = $t3;
                        $t3 = $t4;
                        $t4 = $t5;
                        $t5 = $tn;
                    }
                }
            }
        } else {
            // 第一阶段（边界）：逐点 clamp 水平抽头下标后做卷积
            for ($j = 0; $j < $tmpH; $j++) {
                // 中间结果覆盖的行号（可能越界，需 clamp）
                $sy = $srcY - 2 + $j;
                $sy = $sy < 0 ? 0 : ($sy > $maxY ? $maxY : $sy);
                // 该行行首偏移
                $row = $sy * $stride;
                for ($i = 0; $i < $w; $i++) {
                    // 当前列
                    $sx = $srcX + $i;
                    // 6 个抽头下标各自 clamp
                    $x0 = $sx - 2 < 0 ? 0 : ($sx - 2 > $maxX ? $maxX : $sx - 2);
                    $x1 = $sx - 1 < 0 ? 0 : ($sx - 1 > $maxX ? $maxX : $sx - 1);
                    $x2 = $sx < 0 ? 0 : ($sx > $maxX ? $maxX : $sx);
                    $x3 = $sx + 1 < 0 ? 0 : ($sx + 1 > $maxX ? $maxX : $sx + 1);
                    $x4 = $sx + 2 < 0 ? 0 : ($sx + 2 > $maxX ? $maxX : $sx + 2);
                    $x5 = $sx + 3 < 0 ? 0 : ($sx + 3 > $maxX ? $maxX : $sx + 3);
                    // 未归一化的水平卷积和（与 interior 路径一致，保留原值）
                    $val = ($refBytes[$row + $x0] + $refBytes[$row + $x5])
                        + 20 * ($refBytes[$row + $x2] + $refBytes[$row + $x3])
                        - 5 * ($refBytes[$row + $x1] + $refBytes[$row + $x4]);
                    $tmp[] = $val;
                }
            }
        }
        // 第二阶段：对中间结果做垂直 6 抽头，并一次性归一化到 8bit
        $out = [];
        for ($j = 0; $j < $h; $j++) {
            // 第 j 行中间结果的起始下标
            $base = $j * $w;
            for ($i = 0; $i < $w; $i++) {
                // 在 tmp 上按行偏移取 6 个垂直抽头（行距 w），做同样的系数卷积
                $val = ($tmp[$base + $i] + $tmp[$base + 5 * $w + $i])
                    + 20 * ($tmp[$base + 2 * $w + $i] + $tmp[$base + 3 * $w + $i])
                    - 5 * ($tmp[$base + $w + $i] + $tmp[$base + 4 * $w + $i]);
                // 两级增益 32*32=1024，故 +512 >> 10 完成归一化与四舍五入
                $val = ($val + 512) >> 10;
                // clip 到 8bit 后写入输出
                $out[] = $val < 0 ? 0 : ($val > 255 ? 255 : $val);
            }
        }
        return $out;
    }

    /**
     * 色度成对运动补偿核心（规范 8.4.2.2.2）：Cb/Cr 两个平面同步插值。
     *
     * 色度 MV 以 1/8 色度样本为单位：fracX/fracY = 0..7，intX/intY 为整数样本。
     * 1/8 像素位置用双线性插值：
     *   pred = ((8-fracX)*(8-fracY)*A + fracX*(8-fracY)*B
     *          + (8-fracX)*fracY*C + fracX*fracY*D + 32) >> 6
     * 其中 A/B/C/D 是左上/右上/左下/右下四个整像素样本；
     * interior 路径中结果是四邻域的凸组合，天然在 0..255 内，故省略 clip；
     * 边界路径因参考样本被 clamp（出现重复取值），仍显式 clip 一次。
     *
     * @param array      $uPlane   Cb 参考平面
     * @param array      $vPlane   Cr 参考平面
     * @param int        $stride   色度平面行跨度
     * @param int        $width    色度平面有效宽度
     * @param int        $height   色度平面有效高度
     * @param int        $x        块水平位置（1/8 色度样本）
     * @param int        $y        块垂直位置（1/8 色度样本）
     * @param int        $blockW   块宽（色度样本）
     * @param int        $blockH   块高（色度样本）
     * @param array      $dstU     Cb 目标平面（引用）
     * @param array      $dstV     Cr 目标平面（引用）
     * @param int        $dstStride 目标平面行跨度
     * @param int        $dstX     目标块左上角 x
     * @param int        $dstY     目标块左上角 y
     * @param array|null $uBytes   可选的 Cb 参考样本数组
     * @param array|null $vBytes   可选的 Cr 参考样本数组
     */
    private function mcChromaPairTo(
        array $uPlane,
        array $vPlane,
        int $stride,
        int $width,
        int $height,
        int $x,
        int $y,
        int $blockW,
        int $blockH,
        array &$dstU,
        array &$dstV,
        int $dstStride,
        int $dstX,
        int $dstY,
        ?array $uBytes = null,
        ?array $vBytes = null
    ): void {
        // 分数部分：色度 MV 以 1/8 样本为单位，低 3 位为分数（0..7）
        $fracX = $x & 7;
        $fracY = $y & 7;
        // 整数部分：右移 3 位即 floor(x/8)，对应规范中色度的 xIntC / yIntC
        $intX = $x >> 3;
        $intY = $y >> 3;
        // 有效坐标上界（用于越界裁剪）
        $maxX = $width - 1;
        $maxY = $height - 1;

        // 快速路径：整数色度 MV 且整块在图像内 → 直接拷贝 Cb/Cr，无需插值
        if ($fracX === 0 && $fracY === 0 && $intX >= 0 && $intY >= 0 && $intX + $blockW <= $width && $intY + $blockH <= $height) {
            // 4:2:0 下色度块常见的 8 宽情形：手工展开，成对写 Cb/Cr
            if ($blockW === 8) {
                // 逐行拷贝 8 个色度样本
                for ($j = 0; $j < $blockH; $j++) {
                    $s = ($intY + $j) * $stride + $intX;
                    $d = ($dstY + $j) * $dstStride + $dstX;
                    // 同一列位置交替写 Cb 与 Cr，两个平面共享相同的整数坐标
                    $dstU[$d]     = $uPlane[$s];
                    $dstV[$d]     = $vPlane[$s];
                    $dstU[$d + 1] = $uPlane[$s + 1];
                    $dstV[$d + 1] = $vPlane[$s + 1];
                    $dstU[$d + 2] = $uPlane[$s + 2];
                    $dstV[$d + 2] = $vPlane[$s + 2];
                    $dstU[$d + 3] = $uPlane[$s + 3];
                    $dstV[$d + 3] = $vPlane[$s + 3];
                    $dstU[$d + 4] = $uPlane[$s + 4];
                    $dstV[$d + 4] = $vPlane[$s + 4];
                    $dstU[$d + 5] = $uPlane[$s + 5];
                    $dstV[$d + 5] = $vPlane[$s + 5];
                    $dstU[$d + 6] = $uPlane[$s + 6];
                    $dstV[$d + 6] = $vPlane[$s + 6];
                    $dstU[$d + 7] = $uPlane[$s + 7];
                    $dstV[$d + 7] = $vPlane[$s + 7];
                }
                return;
            }
            // 其它色度块尺寸：通用双循环，Cb/Cr 同坐标成对拷贝
            for ($j = 0; $j < $blockH; $j++) {
                $srcBase = ($intY + $j) * $stride + $intX;
                $dstBase = ($dstY + $j) * $dstStride + $dstX;
                for ($i = 0; $i < $blockW; $i++) {
                    $dstU[$dstBase + $i] = $uPlane[$srcBase + $i];
                    $dstV[$dstBase + $i] = $vPlane[$srcBase + $i];
                }
            }
            return;
        }

        // 是否存在分数分量（决定是否需要双线性插值）
        $hasFraction = $fracX !== 0 || $fracY !== 0;
        if ($hasFraction) {
            // 需要插值时统一使用 $uBytes/$vBytes 访问样本；未传入则引用原平面
            if ($uBytes === null) {
                $uBytes = $uPlane;
                $vBytes = $vPlane;
            }
            // 双线性权重：左侧/上方整像素权重为 8-frac（分母 8）
            $wx0 = 8 - $fracX;
            $wy0 = 8 - $fracY;

            // interior 快速路径：保证右/下邻像素所需的 +1 也在图像内
            // （fracX!=0 时需要右邻，fracY!=0 时需要下邻）
            if ($intX >= 0 && $intY >= 0
                && $intX + $blockW + ($fracX !== 0 ? 1 : 0) <= $width
                && $intY + $blockH + ($fracY !== 0 ? 1 : 0) <= $height
            ) {
                // 情形 A：仅水平分数（fracY==0），双线性退化为两像素加权
                if ($fracY === 0) {
                    // 纯水平双线性：相邻像素共享 b 点，寄存器轮转每像素每平面只读 1 个新点
                    for ($j = 0; $j < $blockH; $j++) {
                        // 该行插值起点
                        $row0 = ($intY + $j) * $stride + $intX;
                        $dstBase = ($dstY + $j) * $dstStride + $dstX;
                        // 左端整像素 a（Cb/Cr 各一份）
                        $ua = $uBytes[$row0];
                        $va = $vBytes[$row0];
                        for ($i = 0; $i < $blockW; $i++) {
                            // 右邻整像素 b
                            $ub = $uBytes[$row0 + $i + 1];
                            $vb = $vBytes[$row0 + $i + 1];
                            // 水平加权：((8-fracX)*a + fracX*b + 4) >> 3
                            $uVal = ($wx0 * $ua + $fracX * $ub + 4) >> 3;
                            $vVal = ($wx0 * $va + $fracX * $vb + 4) >> 3;
                            // 写 Cb/Cr 目标
                            $dstU[$dstBase + $i] = $uVal;
                            $dstV[$dstBase + $i] = $vVal;
                            // 轮转：本像素的右邻成为下一像素的左邻
                            $ua = $ub;
                            $va = $vb;
                        }
                    }
                // 情形 B：仅垂直分数（fracX==0），两行加权
                } elseif ($fracX === 0) {
                    // 纯垂直双线性：列间无共享，保持逐点
                    for ($j = 0; $j < $blockH; $j++) {
                        // 上方行与下方行的行首偏移
                        $row0 = ($intY + $j) * $stride + $intX;
                        $row1 = $row0 + $stride;
                        $dstBase = ($dstY + $j) * $dstStride + $dstX;
                        for ($i = 0; $i < $blockW; $i++) {
                            // 垂直加权：((8-fracY)*top + fracY*bottom + 4) >> 3
                            $uVal = ($wy0 * $uBytes[$row0 + $i] + $fracY * $uBytes[$row1 + $i] + 4) >> 3;
                            $vVal = ($wy0 * $vBytes[$row0 + $i] + $fracY * $vBytes[$row1 + $i] + 4) >> 3;
                            // 写 Cb/Cr 目标
                            $dstU[$dstBase + $i] = $uVal;
                            $dstV[$dstBase + $i] = $vVal;
                        }
                    }
                } else {
                    // 情形 C：两个方向都有分数 → 四邻域双线性
                    // 预乘权重：w00=左上、w10=右上、w01=左下、w11=右下（分母 8*8=64）
                    $w00 = $wx0 * $wy0;
                    $w10 = $fracX * $wy0;
                    $w01 = $wx0 * $fracY;
                    $w11 = $fracX * $fracY;
                    // 双向双线性：相邻像素共享右列 b/d，寄存器轮转每像素每平面只读 2 个新点
                    for ($j = 0; $j < $blockH; $j++) {
                        // 上/下两行的行首偏移
                        $row0 = ($intY + $j) * $stride + $intX;
                        $row1 = $row0 + $stride;
                        $dstBase = ($dstY + $j) * $dstStride + $dstX;
                        // 当前像素四邻域：a=左上、b=右上、c=左下、d=右下
                        $ua = $uBytes[$row0];
                        $ub = $uBytes[$row0 + 1];
                        $uc = $uBytes[$row1];
                        $ud = $uBytes[$row1 + 1];
                        $va = $vBytes[$row0];
                        $vb = $vBytes[$row0 + 1];
                        $vc = $vBytes[$row1];
                        $vd = $vBytes[$row1 + 1];
                        for ($i = 0; $i < $blockW; $i++) {
                            // 双线性组合 +32 四舍五入 + >>6 归一化（64 个权重单位）
                            $uVal = ($w00 * $ua + $w10 * $ub + $w01 * $uc + $w11 * $ud + 32) >> 6;
                            $vVal = ($w00 * $va + $w10 * $vb + $w01 * $vc + $w11 * $vd + 32) >> 6;
                            // 写 Cb/Cr 目标（凸组合必在 0..255，无需 clip）
                            $dstU[$dstBase + $i] = $uVal;
                            $dstV[$dstBase + $i] = $vVal;
                            // 向右滑动一列：右列成为下一像素的左列，再读入新的右列
                            if ($i + 1 < $blockW) {
                                $ua = $ub;
                                $uc = $ud;
                                $va = $vb;
                                $vc = $vd;
                                $ub = $uBytes[$row0 + $i + 2];
                                $ud = $uBytes[$row1 + $i + 2];
                                $vb = $vBytes[$row0 + $i + 2];
                                $vd = $vBytes[$row1 + $i + 2];
                            }
                        }
                    }
                }
                return;
            }

            // 非 interior：同样需要四个预乘权重，后面逐像素取样本时使用
            $w00 = $wx0 * $wy0;
            $w10 = $fracX * $wy0;
            $w01 = $wx0 * $fracY;
            $w11 = $fracX * $fracY;
        }

        // 边界路径：预计算两列下标（当前列 x、右邻列 x+1）的 clamp 结果
        $x0 = [];
        $x1 = [];
        for ($i = 0; $i < $blockW; $i++) {
            // 当前列
            $sx = $intX + $i;
            // 左列 clamp 到 [0, maxX]
            $x0[$i] = $sx < 0 ? 0 : ($sx > $maxX ? $maxX : $sx);
            // 右邻列（+1）
            $sx++;
            $x1[$i] = $sx < 0 ? 0 : ($sx > $maxX ? $maxX : $sx);
        }

        // 逐行、逐列输出（边界路径统一处理，含无分数时的纯拷贝）
        for ($j = 0; $j < $blockH; $j++) {
            // 当前行行号 clamp
            $sy0 = $intY + $j;
            $sy0 = $sy0 < 0 ? 0 : ($sy0 > $maxY ? $maxY : $sy0);
            $row0 = $sy0 * $stride;
            // 有分数时需要下一行的四个邻域样本（行号同样 clamp）
            if ($hasFraction) {
                $sy1 = $intY + $j + 1;
                $sy1 = $sy1 < 0 ? 0 : ($sy1 > $maxY ? $maxY : $sy1);
                $row1 = $sy1 * $stride;
            }
            // 目标行首下标
            $dstBase = ($dstY + $j) * $dstStride + $dstX;
            for ($i = 0; $i < $blockW; $i++) {
                // 无分数：直接按 clamp 后的列下标拷贝（此时只用到 row0/x0）
                if (!$hasFraction) {
                    $dstU[$dstBase + $i] = $uPlane[$row0 + $x0[$i]];
                    $dstV[$dstBase + $i] = $vPlane[$row0 + $x0[$i]];
                    continue;
                }
                // 四邻域双线性（Cb）
                $uVal = ($w00 * $uBytes[$row0 + $x0[$i]]
                    + $w10 * $uBytes[$row0 + $x1[$i]]
                    + $w01 * $uBytes[$row1 + $x0[$i]]
                    + $w11 * $uBytes[$row1 + $x1[$i]] + 32) >> 6;
                // 四邻域双线性（Cr）
                $vVal = ($w00 * $vBytes[$row0 + $x0[$i]]
                    + $w10 * $vBytes[$row0 + $x1[$i]]
                    + $w01 * $vBytes[$row1 + $x0[$i]]
                    + $w11 * $vBytes[$row1 + $x1[$i]] + 32) >> 6;
                // 边界处样本被 clamp 重复使用，仍按 8bit 做一次 clip 保证安全
                $dstU[$dstBase + $i] = $uVal < 0 ? 0 : ($uVal > 255 ? 255 : $uVal);
                $dstV[$dstBase + $i] = $vVal < 0 ? 0 : ($vVal > 255 ? 255 : $vVal);
            }
        }
    }
}
