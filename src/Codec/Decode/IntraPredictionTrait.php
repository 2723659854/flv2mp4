<?php

namespace Xiaosongshu\Flv2mp4\Codec\Decode;

/**
 * @purpose 帧内预测核心模块
 * @author yanglong
 * @time 2026年7月23日14:53:12
 */
trait IntraPredictionTrait
{
    /*
     * 本 trait 实现 H.264/AVC 解码端的帧内预测（Intra prediction）。
     * 解码流程中，每个宏块/子块在完成熵解码（CAVLC/CABAC）、反量化与 IDCT 后重建出像素，
     * 随后按本 trait 提供的算法，用已重建的相邻样本生成预测块，再与残差相加得到重建值。
     *
     * 对应规范条款：
     *   8.3.1.1  亮度 4x4 帧内预测（Intra_4x4）
     *   8.3.1.2  亮度 8x8 帧内预测（Intra_8x8），8.3.1.2.1 为其参考样本滤波
     *   8.3.2    亮度 16x16 帧内预测（Intra_16x16，含 Vertical/Horizontal/DC/Plane）
     *   8.3.3    色度 8x8 帧内预测（Intra_chroma）
     *   8.3.4    帧内预测模式推导（由相邻块的 Intra 模式推导当前块的候选模式）
     *
     * 统一约定：
     *   - 像素平面 yPlane/uPlane/vPlane 均为一维数组，索引 = y * width + x；
     *   - 预测输出为 [y][x] 二维数组，左上角为 [0][0]；
     *   - 相邻参考样本不可用时，按规范以 128 填充（8bit 位深下 (1<<(BitDepth-1)) = 128）；
     *   - 预测公式中的 +2、+1、+4 等为四舍五入偏置，>> 为向下取整的整数除法。
     */
    /**
     * Intra4x4 帧内预测
     * 规范条款：8.3.1.1（Intra_4x4 prediction process）。
     * 本实现直接使用已重建的相邻样本（不做 [1 2 1]/4 滤波），与参考实现的 4x4 路径一致。
     *
     * @param int $mbX   宏块在图像中的列号（以 16x16 宏块为单位）
     * @param int $mbY   宏块在图像中的行号（以 16x16 宏块为单位）
     * @param int $blkX  4x4 块在宏块内的列号 0..3
     * @param int $blkY  4x4 块在宏块内的行号 0..3
     * @param int $mode  Intra_4x4 预测模式 0..8（见表 8-2）
     * @return array     4x4 预测样值，$predicted[y][x]
     */
    public function intra4x4Prediction(int $mbX, int $mbY, int $blkX, int $blkY, int $mode): array
    {
        // 预测结果缓冲，先全部填 128（中性灰）；随后按模式逐像素覆盖。
        $predicted = array_fill(0, 4, array_fill(0, 4, 128));

        // 模式越界保护：遇到非法模式统一回退到 DC（模式 2），避免落入无定义的 switch 分支。
        if ($mode < 0 || $mode > 8) {
            $mode = 2;
        }

        // 换算当前 4x4 块左上角在亮度平面中的像素坐标：宏块坐标 * 16 + 块内偏移 * 4。
        $mbPx = $mbX * 16 + $blkX * 4;
        $mbPy = $mbY * 16 + $blkY * 4;

        // 邻块可用性判定（规范 8.3.1.1 的预测输入约束）：
        // 顶部可用：块不在宏块首行（blkY>0，上一行 4x4 已重建），或宏块不在图像首行。
        $topAvail = ($blkY === 0 && $mbY > 0) || $blkY > 0;
        // 左侧可用：块不在宏块首列（blkX>0，左一列 4x4 已重建），或宏块不在图像首列。
        $leftAvail = ($blkX === 0 && $mbX > 0) || $blkX > 0;
        //$topRightAvail = false;
        // 注：右上方块可用性在下方按块位置单独判定，此处保留原占位注释。

        // 参考样本缓存：
        //   top[0..3]  = p[0..3,-1]（顶部行），top[4..7] = p[4..7,-1]（右上方扩展）；
        //   left[0..3] = p[-1,0..3]（左侧列）；topLeft = p[-1,-1]。
        // 初值 128 表示"样本不可用"时的填充值。
        $top = array_fill(0, 8, 128);
        $left = array_fill(0, 4, 128);
        $topLeft = 128;

        if ($leftAvail) {
            // 读取左侧参考列 p[-1,y]（y=0..3）：列索引为当前块左边界减 1。
            $refX = $mbPx - 1;
            for ($y = 0; $y < 4; $y++) {
                $py = $mbPy + $y;
                // 边界检查：行不得越出图像高度，列索引不得为负（图像左边界外）。
                if ($py < $this->height && $refX >= 0) {
                    // 一维平面索引 = y * width + x。
                    $idx = $py * $this->width + $refX;
                    // 再校验索引落在 yPlane 有效范围内，避免越界读取。
                    if ($idx >= 0 && $idx < count($this->yPlane)) {
                        $left[$y] = $this->yPlane[$idx];
                    }
                }
            }
        }

        if ($topAvail) {
            // 读取顶部参考行 p[x,-1] 的前 4 个样本（x=0..3）。
            $refY = $mbPy - 1;
            for ($x = 0; $x < 4; $x++) {
                $px = $mbPx + $x;
                // 边界检查：列不得越出图像宽度，行索引不得为负（图像上边界外）。
                if ($px < $this->width && $refY >= 0) {
                    $idx = $refY * $this->width + $px;
                    if ($idx >= 0 && $idx < count($this->yPlane)) {
                        $top[$x] = $this->yPlane[$idx];
                    }
                }
            }

            // 判定右上样本 p[4..7,-1] 是否可用（规范 8.3.1.1 只在该区域已重建时才可取用）。
            // blkY>0：右上方块需已按光栅扫描顺序解码，此处用块坐标排除若干跨行/跨列边界的情况。
            if ($blkY > 0) {
                $blockHasTopRight = ($blkX < 3)
                    && !($blkX == 1 && $blkY == 1)
                    && !($blkX == 1 && $blkY == 3)
                    && !($blkX == 3 && $blkY == 1)
                    && !($blkX == 3 && $blkY == 3);
            } else {
                if ($blkX < 3) {
                    // 宏块首行且非最右块：右上即同一宏块内右侧块的上方，已重建。
                    $blockHasTopRight = true;
                } else {
                    // 宏块首行最右块：需要右侧相邻宏块存在，其左下 4x4 已解码。
                    $blockHasTopRight = ($mbX + 1 < $this->picWidthInMbs);
                }
            }

            if ($blockHasTopRight) {
                // 右上可用：继续读取 p[4..7,-1]，与顶部样本处于同一行。
                for ($x = 4; $x < 8; $x++) {
                    $px = $mbPx + $x;
                    if ($px < $this->width && $refY >= 0) {
                        $idx = $refY * $this->width + $px;
                        if ($idx >= 0 && $idx < count($this->yPlane)) {
                            $top[$x] = $this->yPlane[$idx];
                        }
                    }
                }
                //$topRightAvail = true;
            } else {
                // 右上不可用：按规范 8.3.1.1 用 p[3,-1] 复制填充 p[4..7,-1]。
                for ($x = 4; $x < 8; $x++) {
                    $top[$x] = $top[3];
                }
            }
        }

        // 左上角样本 p[-1,-1] 的取值（规范 8.3.1.1）：
        // 顶/左都可用时取实际重建值；仅一侧可用时用该侧首个样本复制；都不可用时保持 128。
        if ($topAvail && $leftAvail) {
            $cornerIdx = ($mbPy - 1) * $this->width + ($mbPx - 1);
            if ($mbPy - 1 >= 0 && $mbPx - 1 >= 0 && $cornerIdx >= 0 && $cornerIdx < count($this->yPlane)) {
                $topLeft = $this->yPlane[$cornerIdx];
            }
        } elseif ($topAvail) {
            $topLeft = $top[0];
        } elseif ($leftAvail) {
            $topLeft = $left[0];
        }

        // 按 Intra_4x4 模式生成预测（规范 8.3.1.1，模式定义见表 8-2）：
        //   0=Vertical、1=Horizontal、2=DC、3=Diagonal Down-Left、4=Diagonal Down-Right、
        //   5=Vertical-Right、6=Horizontal-Down、7=Vertical-Left、8=Horizontal-Up。
        switch ($mode) {
            case 0: // Vertical
                // pred[x,y] = p[x,-1]：每一列复制对应的顶部样本。
                for ($y = 0; $y < 4; $y++) {
                    for ($x = 0; $x < 4; $x++) {
                        $predicted[$y][$x] = $top[$x];
                    }
                }
                break;

            case 1: // Horizontal
                // pred[x,y] = p[-1,y]：每一行复制对应的左侧样本。
                for ($y = 0; $y < 4; $y++) {
                    for ($x = 0; $x < 4; $x++) {
                        $predicted[$y][$x] = $left[$y];
                    }
                }
                break;

            case 2: // DC
                // DC 预测按可用参考样本分四种情况，取平均后填满整块（规范 8.3.1.1）。
                if ($topAvail && $leftAvail) {
                    // 两侧均可用：8 个样本求和，(Sum + 4) >> 3 实现四舍五入除法。
                    $sum = $top[0] + $top[1] + $top[2] + $top[3] +
                        $left[0] + $left[1] + $left[2] + $left[3];
                    $avg = ($sum + 4) >> 3;
                } elseif ($topAvail) {
                    // 仅顶部可用：4 个顶部样本求和，(Sum + 2) >> 2。
                    $sum = $top[0] + $top[1] + $top[2] + $top[3];
                    $avg = ($sum + 2) >> 2;
                } elseif ($leftAvail) {
                    // 仅左侧可用：4 个左侧样本求和，(Sum + 2) >> 2。
                    $sum = $left[0] + $left[1] + $left[2] + $left[3];
                    $avg = ($sum + 2) >> 2;
                } else {
                    // 两侧均不可用：DC_128，固定取 128。
                    $avg = 128;
                }
                // 将 DC 值填入 4x4 全部像素。
                for ($y = 0; $y < 4; $y++) {
                    for ($x = 0; $x < 4; $x++) {
                        $predicted[$y][$x] = $avg;
                    }
                }

                break;

            case 3: // Diagonal Down-Left
                // 沿 "右下到左下" 的对角线方向外推：每个预测值为
                //   (p[a] + 2*p[b] + p[c] + 2) >> 2，即 [1 2 1]/4 的 3-tap 插值。
                // 对角线上 x-y 相同的像素结果一致，因此下面用赋值复用减少重复计算。
                $t0 = $top[0];
                $t1 = $top[1];
                $t2 = $top[2];
                $t3 = $top[3];
                $t4 = $top[4];
                $t5 = $top[5];
                $t6 = $top[6];
                $t7 = $top[7];
                // 主对角线方向：d = x - y 越大越靠右/上。
                $predicted[0][0] = (int)(($t0 + 2 * $t1 + $t2 + 2) >> 2);
                $predicted[0][1] = (int)(($t1 + 2 * $t2 + $t3 + 2) >> 2);
                // d = 1 的像素 (1,0) 与 (0,1) 共用同一插值结果。
                $predicted[1][0] = $predicted[0][1];
                $predicted[0][2] = (int)(($t2 + 2 * $t3 + $t4 + 2) >> 2);
                $predicted[1][1] = $predicted[0][2];
                $predicted[2][0] = $predicted[0][2];
                $predicted[0][3] = (int)(($t3 + 2 * $t4 + $t5 + 2) >> 2);
                $predicted[1][2] = $predicted[0][3];
                $predicted[2][1] = $predicted[0][3];
                $predicted[3][0] = $predicted[0][3];
                // 靠近下边界时依旧使用 top 行更右侧的样本（p[4..7,-1]）。
                $predicted[1][3] = (int)(($t4 + 2 * $t5 + $t6 + 2) >> 2);
                $predicted[2][2] = $predicted[1][3];
                $predicted[3][1] = $predicted[1][3];
                $predicted[2][3] = (int)(($t5 + 2 * $t6 + $t7 + 2) >> 2);
                $predicted[3][2] = $predicted[2][3];
                // 右下角为边界情况，权重变为 (p[6]+3*p[7]+2)>>2。
                $predicted[3][3] = (int)(($t6 + 3 * $t7 + 2) >> 2);
                break;

            case 4: // Diagonal Down-Right
                // 沿 d = x - y 的对角线方向（左上到右下）预测；
                // 每条对角线内部共享同一个 [1 2 1]/4 插值结果。
                $lt = $topLeft;
                $t0 = $top[0];
                $t1 = $top[1];
                $t2 = $top[2];
                $t3 = $top[3];
                $l0 = $left[0];
                $l1 = $left[1];
                $l2 = $left[2];
                $l3 = $left[3];
                // 3-tap 插值闭包：(a + 2b + c + 2) >> 2，对应 [1 2 1]/4 滤波。
                $avg3 = function($a, $b, $c) {
                    return (int)(($a + 2 * $b + $c + 2) >> 2);
                };
                // 变量命名约定：vXY 中的数字表示对角线 d = x - y（负数用 0X 表示）。
                // d = -3：仅 (0,3)，用左侧样本 l3、l2、l1。
                $v03 = $avg3($l3, $l2, $l1);
                // d = -2：(0,2) 与 (1,3)。
                $v02 = $avg3($l2, $l1, $l0);
                // d = -1：(0,1)、(1,2)、(2,3)。
                $v01 = $avg3($l1, $l0, $lt);
                // d = 0：主对角线，同时用到左、左上、上三个样本。
                $v00 = $avg3($l0, $lt, $t0);
                // d = 1：(1,0)、(2,1)、(3,2)，跨过 topLeft 进入 top 行。
                $v10 = $avg3($lt, $t0, $t1);
                // d = 2：(2,0)、(3,1)。
                $v20 = $avg3($t0, $t1, $t2);
                // d = 3：(3,0)。
                $v30 = $avg3($t1, $t2, $t3);
                $predicted[3][0] = $v03;
                $predicted[2][0] = $v02;
                $predicted[3][1] = $v02;
                $predicted[1][0] = $v01;
                $predicted[2][1] = $v01;
                $predicted[3][2] = $v01;
                $predicted[0][0] = $v00;
                $predicted[1][1] = $v00;
                $predicted[2][2] = $v00;
                $predicted[3][3] = $v00;
                $predicted[0][1] = $v10;
                $predicted[1][2] = $v10;
                $predicted[2][3] = $v10;
                $predicted[0][2] = $v20;
                $predicted[1][3] = $v20;
                $predicted[0][3] = $v30;
                break;

            case 5: // Vertical-Right
                // 预测方向偏向垂直并向右下倾斜；由 [1 2 1]/4 与 [1 1]/2 两类插值组合而成。
                $lt = $topLeft;
                $t0 = $top[0];
                $t1 = $top[1];
                $t2 = $top[2];
                $t3 = $top[3];
                $l0 = $left[0];
                $l1 = $left[1];
                $l2 = $left[2];
                // 2-tap 平均闭包：(a + b + 1) >> 1。
                $avg2 = function($a, $b) {
                    return (int)(($a + $b + 1) >> 1);
                };
                // 3-tap 插值闭包：(a + 2b + c + 2) >> 2。
                $avg3 = function($a, $b, $c) {
                    return (int)(($a + 2 * $b + $c + 2) >> 2);
                };
                // 沿各条等值线 zVR = 2x - y 计算预测；以下按规范表 8-2 的公式逐点赋值。
                $predicted[0][0] = $avg2($lt, $t0);
                // (1,2) 与 (0,0) 位于同一条等值线，共享结果。
                $predicted[2][1] = $predicted[0][0];
                $predicted[0][1] = $avg2($t0, $t1);
                $predicted[2][2] = $predicted[0][1];
                $predicted[0][2] = $avg2($t1, $t2);
                $predicted[2][3] = $predicted[0][2];
                $predicted[0][3] = $avg2($t2, $t3);
                $predicted[1][0] = $avg3($l0, $lt, $t0);
                $predicted[3][1] = $predicted[1][0];
                $predicted[1][1] = $avg3($lt, $t0, $t1);
                $predicted[3][2] = $predicted[1][1];
                $predicted[1][2] = $avg3($t0, $t1, $t2);
                $predicted[3][3] = $predicted[1][2];
                $predicted[1][3] = $avg3($t1, $t2, $t3);
                // 左下角两行只依赖左侧样本 l0..l2。
                $predicted[2][0] = $avg3($lt, $l0, $l1);
                $predicted[3][0] = $avg3($l0, $l1, $l2);
                break;

            case 6: // Horizontal-Down
                // 预测方向偏向水平并向左下倾斜；同样由 avg2/avg3 两类插值组成。
                $lt = $topLeft;
                $t0 = $top[0];
                $t1 = $top[1];
                $t2 = $top[2];
                $l0 = $left[0];
                $l1 = $left[1];
                $l2 = $left[2];
                $l3 = $left[3];
                // 2-tap 平均闭包：(a + b + 1) >> 1。
                $avg2 = function($a, $b) {
                    return (int)(($a + $b + 1) >> 1);
                };
                // 3-tap 插值闭包：(a + 2b + c + 2) >> 2。
                $avg3 = function($a, $b, $c) {
                    return (int)(($a + 2 * $b + $c + 2) >> 2);
                };
                // 沿等值线 zHD = 2y - x 逐点计算，同一条等值线上的像素共享结果。
                $predicted[0][0] = $avg2($lt, $l0);
                $predicted[1][2] = $predicted[0][0];
                $predicted[0][1] = $avg3($l0, $lt, $t0);
                $predicted[1][3] = $predicted[0][1];
                $predicted[0][2] = $avg3($lt, $t0, $t1);
                $predicted[0][3] = $avg3($t0, $t1, $t2);
                $predicted[1][0] = $avg2($l0, $l1);
                $predicted[2][2] = $predicted[1][0];
                $predicted[1][1] = $avg3($lt, $l0, $l1);
                $predicted[2][3] = $predicted[1][1];
                $predicted[2][0] = $avg2($l1, $l2);
                $predicted[3][2] = $predicted[2][0];
                $predicted[2][1] = $avg3($l0, $l1, $l2);
                $predicted[3][3] = $predicted[2][1];
                $predicted[3][0] = $avg2($l2, $l3);
                $predicted[3][1] = $avg3($l1, $l2, $l3);
                break;

            case 7: // Vertical-Left
                // 预测方向偏向垂直并向右上倾斜；只用 top 行样本（含右上扩展 p[4..6,-1]）。
                $t0 = $top[0];
                $t1 = $top[1];
                $t2 = $top[2];
                $t3 = $top[3];
                $t4 = $top[4];
                $t5 = $top[5];
                $t6 = $top[6];
                // 2-tap 平均闭包：(a + b + 1) >> 1。
                $avg2 = function($a, $b) {
                    return (int)(($a + $b + 1) >> 1);
                };
                // 3-tap 插值闭包：(a + 2b + c + 2) >> 2。
                $avg3 = function($a, $b, $c) {
                    return (int)(($a + 2 * $b + $c + 2) >> 2);
                };
                // 第一行用相邻 top 样本的 2-tap 平均。
                $predicted[0][0] = $avg2($t0, $t1);
                $predicted[0][1] = $avg2($t1, $t2);
                $predicted[2][0] = $predicted[0][1];
                $predicted[0][2] = $avg2($t2, $t3);
                $predicted[2][1] = $predicted[0][2];
                $predicted[0][3] = $avg2($t3, $t4);
                $predicted[2][2] = $predicted[0][3];
                $predicted[2][3] = $avg2($t4, $t5);
                // 第二、四行用 3-tap 插值；t7 不参与模式 7（表中最高只到 t6）。
                $predicted[1][0] = $avg3($t0, $t1, $t2);
                $predicted[1][1] = $avg3($t1, $t2, $t3);
                $predicted[3][0] = $predicted[1][1];
                $predicted[1][2] = $avg3($t2, $t3, $t4);
                $predicted[3][1] = $predicted[1][2];
                $predicted[1][3] = $avg3($t3, $t4, $t5);
                $predicted[3][2] = $predicted[1][3];
                $predicted[3][3] = $avg3($t4, $t5, $t6);
                break;

            case 8: // Horizontal-Up
                //   src[x + y*stride] 对应 predicted[y][x]
                // 预测方向偏向水平并向右上倾斜；仅使用 left 列样本 l0..l3。
                $l0 = $left[0];
                $l1 = $left[1];
                $l2 = $left[2];
                $l3 = $left[3];
                // 2-tap 平均闭包：(a + b + 1) >> 1。
                $avg2 = function($a, $b) {
                    return (int)(($a + $b + 1) >> 1);
                };
                // 3-tap 插值闭包：(a + 2b + c + 2) >> 2。
                $avg3 = function($a, $b, $c) {
                    return (int)(($a + 2 * $b + $c + 2) >> 2);
                };
                // 依次按等值线 zHU = x + 2y 计算，同线像素共享结果。
                $predicted[0][0] = $avg2($l0, $l1);
                $predicted[0][1] = $avg3($l0, $l1, $l2);
                $predicted[0][2] = $avg2($l1, $l2);
                $predicted[1][0] = $predicted[0][2];
                $predicted[0][3] = $avg3($l1, $l2, $l3);
                $predicted[1][1] = $predicted[0][3];
                $predicted[1][2] = $avg2($l2, $l3);
                $predicted[2][0] = $predicted[1][2];
                // 样本不足时重复使用 l3，等价于边界扩展。
                $predicted[1][3] = $avg3($l2, $l3, $l3);
                $predicted[2][1] = $predicted[1][3];
                // 下半部分超出参考样本范围，统一取 l3。
                $predicted[2][3] = $l3;
                $predicted[3][1] = $l3;
                $predicted[3][0] = $l3;
                $predicted[2][2] = $l3;
                $predicted[3][2] = $l3;
                $predicted[3][3] = $l3;
                break;
        }

        // 结果裁剪到 8bit 像素范围 [0,255]，防止插值/越界样本造成溢出。
        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                if ($predicted[$y][$x] < 0) $predicted[$y][$x] = 0;
                if ($predicted[$y][$x] > 255) $predicted[$y][$x] = 255;
            }
        }

        return $predicted;
    }

    /**
     * Intra8x8 帧内预测（transform_8x8_flag=1 的 I8x8 宏块）
     * 严格对照参考实现的 pred8x8l_* 系列：
     * 邻居样值先经 [1 2 1]/4 滤波（l0..l7/t0..t15/lt），再按模式生成 8x8 预测。
     * blkX/blkY ∈ {0,1}：8x8 块在宏块内的位置。
     *
     * 规范条款：8.3.1.2（Intra_8x8 prediction process）与
     * 8.3.1.2.1（Reference sample filtering process for Intra_8x8 luma prediction）。
     *
     * @param int $mbX   宏块列号
     * @param int $mbY   宏块行号
     * @param int $blkX  8x8 块在宏块内的列号 0..1
     * @param int $blkY  8x8 块在宏块内的行号 0..1
     * @param int $mode  Intra_8x8 预测模式 0..8
     * @return array     8x8 预测样值 $p[y][x]
     */
    public function intra8x8Prediction(int $mbX, int $mbY, int $blkX, int $blkY, int $mode): array
    {
        // 模式越界保护：非法模式统一回退到 DC（模式 2）。
        if ($mode < 0 || $mode > 8) {
            $mode = 2;
        }
        // 当前 8x8 块左上角的亮度像素坐标：宏块坐标 * 16 + 块内偏移 * 8。
        $x0 = $mbX * 16 + $blkX * 8;
        $y0 = $mbY * 16 + $blkY * 8;
        // 缓存平面几何参数，避免循环内反复取属性。
        $w = $this->width;
        $h = $this->height;

        // 顶部/左侧可用性（规范 8.3.1.2 的预测输入）：
        // blkY>0 时上一行 8x8 已重建；否则需宏块上方存在。
        $topAvail = ($blkY > 0) || ($mbY > 0);
        // blkX>0 时左侧 8x8 已重建；否则需宏块左侧存在。
        $leftAvail = ($blkX > 0) || ($mbX > 0);
        // topRight：blkX=0 时与 top 同行（上邻底行右半或本 MB 块1底行，均已重建）；
        // blkX=1 且 blkY=0 时为右上邻 MB 底行（帧内需存在）；blkX=1 且 blkY=1 时为右邻（未解码）
        if ($blkX === 0) {
            // 块 0：右上方与顶部同属一个宏块（或上邻宏块）的已重建行。
            $topRightAvail = $topAvail;
        } else {
            // 块 1：只有在宏块首行且右侧宏块存在时，右上样本才已重建可用。
            $topRightAvail = ($blkY === 0) && $mbY > 0 && ($mbX + 1 < $this->picWidthInMbs);
        }
        // 左上角样本需要顶与左同时可用。
        $topLeftAvail = $topAvail && $leftAvail;

        // 原始邻居样值（不可用时 128）
        $st = array_fill(0, 16, 128); // p[x,-1], x=0..15
        $sl = array_fill(0, 8, 128);  // p[-1,y], y=0..7
        $slt = 128;                   // p[-1,-1]
        if ($topAvail) {
            // 顶部行采样：右上不可用时只需读 8 个，否则读满 16 个。
            $ry = $y0 - 1;
            $lim = $topRightAvail ? 16 : 8;
            for ($x = 0; $x < $lim; $x++) {
                $px = $x0 + $x;
                // 边界检查：列不越界，行索引落在图像内。
                if ($px < $w && $ry < $h) {
                    // 一维平面索引 = y*width + x。
                    $st[$x] = $this->yPlane[$ry * $w + $px];
                }
            }
            if (!$topRightAvail) {
                // 右上不可用：用 p[7,-1] 复制填充 p[8..15,-1]（规范 8.3.1.2.1）。
                for ($x = 8; $x < 16; $x++) {
                    $st[$x] = $st[7];
                }
            }
        }
        if ($leftAvail) {
            // 左侧列采样 p[-1,y]（y=0..7）。
            $rx = $x0 - 1;
            for ($y = 0; $y < 8; $y++) {
                $py = $y0 + $y;
                if ($py < $h && $rx < $w) {
                    $sl[$y] = $this->yPlane[$py * $w + $rx];
                }
            }
        }
        if ($topLeftAvail) {
            // 左上角 p[-1,-1]：位于当前块左上角左上方一格。
            $slt = $this->yPlane[($y0 - 1) * $w + ($x0 - 1)];
        }

        // [1 2 1]/4 滤波（PREDICT_8x8_LOAD_*）
        // 规范 8.3.1.2.1：对参考样本做 (p[a] + 2*p[b] + p[c] + 2) >> 2 的低通滤波，
        // 以减弱块边界不连续；得到滤波后的 l[0..7]、t[0..15] 与 lt。
        $l = [];
        // 首样本：左侧可用但左上不可用时用 p[-1,0] 代替 p[-1,-1]。
        $l[0] = (($topLeftAvail ? $slt : $sl[0]) + 2 * $sl[0] + $sl[1] + 2) >> 2;
        // 中间样本：标准 3-tap 滤波。
        for ($y = 1; $y <= 6; $y++) {
            $l[$y] = ($sl[$y - 1] + 2 * $sl[$y] + $sl[$y + 1] + 2) >> 2;
        }
        // 末样本只有两个邻居，权重变为 p[6] + 3*p[7]。
        $l[7] = ($sl[6] + 3 * $sl[7] + 2) >> 2;

        $t = [];
        // 顶部首样本：左上不可用时用 p[0,-1] 代替 p[-1,-1]。
        $t[0] = (($topLeftAvail ? $slt : $st[0]) + 2 * $st[0] + $st[1] + 2) >> 2;
        for ($x = 1; $x <= 6; $x++) {
            $t[$x] = ($st[$x - 1] + 2 * $st[$x] + $st[$x + 1] + 2) >> 2;
        }
        // 顶部第 8 个样本：右上不可用时按 st[7] 复制处理。
        $t[7] = (($topRightAvail ? $st[8] : $st[7]) + 2 * $st[7] + $st[6] + 2) >> 2;
        if ($topRightAvail) {
            // 右上可用：继续滤波 p[8..14,-1]，末样本用 p[14]+3*p[15]。
            for ($x = 8; $x <= 14; $x++) {
                $t[$x] = ($st[$x - 1] + 2 * $st[$x] + $st[$x + 1] + 2) >> 2;
            }
            $t[15] = ($st[14] + 3 * $st[15] + 2) >> 2;
        } else {
            // 右上不可用：t[8..15] 直接等于 p[7,-1]。
            for ($x = 8; $x <= 15; $x++) {
                $t[$x] = $st[7];
            }
        }
        // 左上角滤波值 lt = (p[-1,0] + 2*p[-1,-1] + p[0,-1] + 2) >> 2。
        $lt = ($sl[0] + 2 * $slt + $st[0] + 2) >> 2;

        // 两个插值辅助闭包：2-tap 平均与 3-tap [1 2 1]/4 插值，供各预测模式复用。
        $avg2 = fn($a, $b) => ($a + $b + 1) >> 1;
        $avg3 = fn($a, $b, $c) => ($a + 2 * $b + $c + 2) >> 2;

        // 预测结果缓冲（8x8），默认 128。
        $p = array_fill(0, 8, array_fill(0, 8, 128));
        switch ($mode) {
            case 0: // Vertical
                // pred[x,y] = t[x]：整列复制滤波后的顶部样本。
                for ($y = 0; $y < 8; $y++) {
                    for ($x = 0; $x < 8; $x++) {
                        $p[$y][$x] = $t[$x];
                    }
                }
                break;
            case 1: // Horizontal
                // pred[x,y] = l[y]：整行复制滤波后的左侧样本。
                for ($y = 0; $y < 8; $y++) {
                    for ($x = 0; $x < 8; $x++) {
                        $p[$y][$x] = $l[$y];
                    }
                }
                break;
            case 2: // DC（四变体）
                // DC 预测按顶部/左侧可用性分四种情况（规范 8.3.1.2）。
                if ($topAvail && $leftAvail) {
                    // 两侧均可用：16 个样本均值，先加 8 再 >>4 实现四舍五入。
                    $s = 8;
                    for ($i = 0; $i < 8; $i++) {
                        $s += $l[$i] + $t[$i];
                    }
                    $dc = $s >> 4;
                } elseif ($leftAvail) {
                    // 仅左侧可用：8 个样本均值，(Sum + 4) >> 3。
                    $dc = ($l[0] + $l[1] + $l[2] + $l[3] + $l[4] + $l[5] + $l[6] + $l[7] + 4) >> 3;
                } elseif ($topAvail) {
                    // 仅顶部可用：8 个样本均值，(Sum + 4) >> 3。
                    $dc = ($t[0] + $t[1] + $t[2] + $t[3] + $t[4] + $t[5] + $t[6] + $t[7] + 4) >> 3;
                } else {
                    // 两侧都不可用：DC_128。
                    $dc = 128;
                }
                // 填满 8x8。
                for ($y = 0; $y < 8; $y++) {
                    for ($x = 0; $x < 8; $x++) {
                        $p[$y][$x] = $dc;
                    }
                }
                break;
            case 3: // Diagonal Down-Left
                // 等值线 k = x + y；k<14 时用 t[k..k+2] 的 3-tap 插值，
                // k>=14 时参考样本不足，退化为 avg3(t14,t15,t15)。
                for ($y = 0; $y < 8; $y++) {
                    for ($x = 0; $x < 8; $x++) {
                        $k = $x + $y;
                        if ($k < 14) {
                            $p[$y][$x] = $avg3($t[$k], $t[$k + 1], $t[$k + 2]);
                        } else {
                            $p[$y][$x] = $avg3($t[14], $t[15], $t[15]);
                        }
                    }
                }
                break;
            case 4: // Diagonal Down-Right（等值线 d=x-y）
                // 按 d 的符号选择参考样本：d>0 用 top 行，d<0 用 left 列，d=0 用 lt/l0/t0。
                for ($y = 0; $y < 8; $y++) {
                    for ($x = 0; $x < 8; $x++) {
                        $d = $x - $y;
                        if ($d > 0) {
                            // 靠近左上角的 d=1 需要跨过 lt。
                            $p[$y][$x] = $d === 1
                                ? $avg3($lt, $t[0], $t[1])
                                : $avg3($t[$d - 2], $t[$d - 1], $t[$d]);
                        } elseif ($d < 0) {
                            // k = |d|，d<0 时用左侧样本；k=1 需跨过 lt。
                            $k = -$d;
                            $p[$y][$x] = $k === 1
                                ? $avg3($l[1], $l[0], $lt)
                                : $avg3($l[$k], $l[$k - 1], $l[$k - 2]);
                        } else {
                            // 主对角线。
                            $p[$y][$x] = $avg3($l[0], $lt, $t[0]);
                        }
                    }
                }
                break;
            case 5: // Vertical-Right（等值线 zVR=2x-y，对照 pred8x8l_vertical_right）
                // 按 zVR 的取值分三支：非负时用顶部样本，-1 时跨 lt，更小则用左侧样本。
                for ($y = 0; $y < 8; $y++) {
                    for ($x = 0; $x < 8; $x++) {
                        $zVR = 2 * $x - $y;
                        if ($zVR >= 0) {
                            if (($zVR & 1) === 0) {
                                // 偶数：2-tap 平均。
                                $k = $zVR >> 1;
                                $p[$y][$x] = $k === 0 ? $avg2($lt, $t[0]) : $avg2($t[$k - 1], $t[$k]);
                            } else {
                                // 奇数：3-tap 插值。
                                $k = $zVR >> 1;
                                $p[$y][$x] = $k === 0
                                    ? $avg3($lt, $t[0], $t[1])
                                    : $avg3($t[$k - 1], $t[$k], $t[$k + 1]);
                            }
                        } elseif ($zVR === -1) {
                            // 等值线 -1：左、左上、上三个样本插值。
                            $p[$y][$x] = $avg3($l[0], $lt, $t[0]);
                        } else {
                            // 更小的 zVR：使用左侧样本。
                            $j = -$zVR - 2;
                            $p[$y][$x] = $j === 0
                                ? $avg3($l[1], $l[0], $lt)
                                : $avg3($l[$j + 1], $l[$j], $l[$j - 1]);
                        }
                    }
                }
                break;
            case 6: // Horizontal-Down（等值线 zHD=2y-x，对照 pred8x8l_horizontal_down）
                // 与模式 5 镜像：非负时主要用左侧样本，-1 时跨 lt，更小则用顶部样本。
                for ($y = 0; $y < 8; $y++) {
                    for ($x = 0; $x < 8; $x++) {
                        $zHD = 2 * $y - $x;
                        if ($zHD >= 0) {
                            if (($zHD & 1) === 0) {
                                // 偶数：2-tap 平均。
                                $k = $zHD >> 1;
                                $p[$y][$x] = $k === 0 ? $avg2($lt, $l[0]) : $avg2($l[$k - 1], $l[$k]);
                            } else {
                                // 奇数：3-tap 插值。
                                $k = $zHD >> 1;
                                $p[$y][$x] = $k === 0
                                    ? $avg3($lt, $l[0], $l[1])
                                    : $avg3($l[$k - 1], $l[$k], $l[$k + 1]);
                            }
                        } elseif ($zHD === -1) {
                            // 等值线 -1：左、左上、上三个样本插值。
                            $p[$y][$x] = $avg3($l[0], $lt, $t[0]);
                        } else {
                            // 更小的 zHD：使用顶部样本。
                            $j = -$zHD - 2;
                            $p[$y][$x] = $j === 0
                                ? $avg3($lt, $t[0], $t[1])
                                : $avg3($t[$j + 1], $t[$j], $t[$j - 1]);
                        }
                    }
                }
                break;
            case 7: // Vertical-Left（等值线 zVL=2x+y，对照 pred8x8l_vertical_left）
                // zVL 的奇偶决定使用 2-tap 还是 3-tap 插值，参考样本全部来自 top 行。
                for ($y = 0; $y < 8; $y++) {
                    for ($x = 0; $x < 8; $x++) {
                        $zVL = 2 * $x + $y;
                        $k = $zVL >> 1;
                        $p[$y][$x] = ($zVL & 1) === 0
                            ? $avg2($t[$k], $t[$k + 1])
                            : $avg3($t[$k], $t[$k + 1], $t[$k + 2]);
                    }
                }
                break;
            case 8: // Horizontal-Up（等值线 zHU=x+2y，对照 pred8x8l_horizontal_up）
                // zHU<13 时用 left 样本插值；=13 为边界公式；>13 直接复制 l7。
                for ($y = 0; $y < 8; $y++) {
                    for ($x = 0; $x < 8; $x++) {
                        $zHU = $x + 2 * $y;
                        if ($zHU < 13) {
                            $k = $zHU >> 1;
                            $p[$y][$x] = ($zHU & 1) === 0
                                ? $avg2($l[$k], $l[$k + 1])
                                : $avg3($l[$k], $l[$k + 1], $l[$k + 2]);
                        } elseif ($zHU === 13) {
                            // 最后一个有效等值线：只有两个邻居，权重为 1:3。
                            $p[$y][$x] = ($l[6] + 3 * $l[7] + 2) >> 2;
                        } else {
                            // 参考样本用尽：边界扩展为 l7。
                            $p[$y][$x] = $l[7];
                        }
                    }
                }
                break;
        }
        return $p;
    }

    /**
     * Intra16x16 亮度预测
     * 规范条款：8.3.2（Intra_16x16 prediction process for luma samples）。
     * 模式：0=Vertical、1=Horizontal、2=DC、3=Plane。
     * 参考样本仅需上邻 16 个、左邻 16 个与左上角 1 个，不做 [1 2 1]/4 滤波。
     *
     * @param int $mbX  宏块列号
     * @param int $mbY  宏块行号
     * @param int $mode 16x16 预测模式 0..3
     * @return array    16x16 预测样值 $pred[y][x]
     */
    public function intra16x16Prediction(int $mbX, int $mbY, int $mode): array
    {
        // 预测缓冲，默认 128。
        $pred = array_fill(0, 16, array_fill(0, 16, 128));
        // 宏块左上角像素坐标。
        $px0 = $mbX * 16;
        $py0 = $mbY * 16;
        // 上邻/左邻可用性：宏块不在图像首行/首列。
        $topAvail = $mbY > 0;
        $leftAvail = $mbX > 0;
        // 参考样本数组：长度 18，索引 0 保留给左上角 p[-1,-1]，
        // 索引 1..16 对应 p[0..15,-1] / p[-1,0..15]，末尾多余 1 个元素不使用。
        $topLine = array_fill(0, 18, 128);
        $leftLine = array_fill(0, 18, 128);

        if ($topAvail) {
            // 读取顶部参考行 p[x,-1]（x=0..15），存入 topLine[1..16]。
            $ry = $py0 - 1;
            for ($x = 0; $x < 16; $x++) {
                $idx = $ry * $this->width + ($px0 + $x);
                // 索引边界检查后读取亮度平面。
                if ($idx >= 0 && $idx < count($this->yPlane)) $topLine[$x + 1] = $this->yPlane[$idx];
            }
        }
        if ($leftAvail) {
            // 读取左侧参考列 p[-1,y]（y=0..15），存入 leftLine[1..16]。
            $rx = $px0 - 1;
            for ($y = 0; $y < 16; $y++) {
                $idx = ($py0 + $y) * $this->width + $rx;
                if ($idx >= 0 && $idx < count($this->yPlane)) $leftLine[$y + 1] = $this->yPlane[$idx];
            }
        }
        // 角落参考像素
        // 左上角 p[-1,-1] 的处理（规范 8.3.2 的参考样本推导）：
        // 两侧都可用取真实值；仅一侧可用则复制该侧首样本；都不可用取 128。
        if ($topAvail && $leftAvail) {
            $cornerIdx = ($py0 - 1) * $this->width + ($px0 - 1);
            if ($cornerIdx >= 0 && $cornerIdx < count($this->yPlane)) {
                $topLine[0] = $leftLine[0] = $this->yPlane[$cornerIdx];
            } else {
                $topLine[0] = $leftLine[0] = 128;
            }
        } elseif ($topAvail) {
            $topLine[0] = $topLine[1];
            $leftLine[0] = 128;
        } elseif ($leftAvail) {
            $leftLine[0] = $leftLine[1];
            $topLine[0] = 128;
        } else {
            $topLine[0] = $leftLine[0] = 128;
        }

        // 当参考像素不可用时，回退到 DC 模式
        // 垂直模式需要顶部参考，水平模式需要左侧参考
        if ($mode === 0 && !$topAvail) {
            $mode = 2; // 回退到 DC
        }
        if ($mode === 1 && !$leftAvail) {
            $mode = 2; // 回退到 DC
        }

        switch ($mode) {
            case 0: // 垂直预测
                // pred[x,y] = p[x,-1]：整列复制顶部样本。
                for ($y = 0; $y < 16; $y++) {
                    for ($x = 0; $x < 16; $x++) {
                        $pred[$y][$x] = $topLine[$x + 1];
                    }
                }
                break;
            case 1: // 水平预测
                // pred[x,y] = p[-1,y]：整行复制左侧样本。
                for ($y = 0; $y < 16; $y++) {
                    for ($x = 0; $x < 16; $x++) {
                        $pred[$y][$x] = $leftLine[$y + 1];
                    }
                }
                break;
            case 2: // DC均值预测
                // DC 预测：按可用样本求和，再按样本个数做带舍入的除法（规范 8.3.2）。
                $sum = 0;
                // cnt 记录参与求和的样本个数（本实现最终按可用性直接选用固定除数）。
                $cnt = 0;
                if ($topAvail) {
                    // 累加 16 个顶部样本。
                    for ($i = 1; $i <= 16; $i++) {
                        $sum += $topLine[$i];
                        $cnt++;
                    }
                }
                if ($leftAvail) {
                    // 累加 16 个左侧样本。
                    for ($i = 1; $i <= 16; $i++) {
                        $sum += $leftLine[$i];
                        $cnt++;
                    }
                }
                if ($topAvail && $leftAvail) {
                    // 两侧共 32 个样本：(Sum + 16) >> 5。
                    $dcVal = ($sum + 16) >> 5; // (sum + N) >> (log2(N) + 1), N=16
                } elseif ($topAvail) {
                    // 仅顶部 16 个样本：(Sum + 8) >> 4。
                    $dcVal = ($sum + 8) >> 4; // (sum + N/2) >> log2(N), N=16
                } elseif ($leftAvail) {
                    // 仅左侧 16 个样本：(Sum + 8) >> 4。
                    $dcVal = ($sum + 8) >> 4; // (sum + N/2) >> log2(N), N=16
                } else {
                    // 无可用参考：DC_128。
                    $dcVal = 128; // DC_128 模式，无参考像素时使用固定值
                }
                // 用统一 DC 值填满 16x16。
                for ($y = 0; $y < 16; $y++) {
                    for ($x = 0; $x < 16; $x++) {
                        $pred[$y][$x] = $dcVal;
                    }
                }
                break;
            case 3: // 平面预测 (8.3.3.1.4, pred16x16_plane_compat)
                // 16x16 平面模式（规范 8.3.2 中 Intra_16x16 的 Plane 预测，模式 3）：
                // 由顶部/左侧样本的线性梯度拟合出一个斜面，逐像素用平面方程求值。
                // topLine[0]=lt, topLine[1..16]=top[0..15]
                // leftLine[0]=lt, leftLine[1..16]=left[0..15]
                // H = sum_{i=0..7} (i+1)*(top[8+i] - top[6-i]), 当 i=7 时 top[6-7]=top[-1]=lt
                // V = sum_{i=0..7} (i+1)*(left[8+i] - left[6-i])
                $H = 0;
                $V = 0;
                for ($i = 0; $i < 8; $i++) {
                    // 权重 k=i+1，越靠近底/右侧权重越大。
                    $k = $i + 1;
                    // 索引 +1 的偏移即来自 topLine/leftLine 的第 0 位是左上角样本。
                    $H += $k * ($topLine[9 + $i] - $topLine[7 - $i]);
                    $V += $k * ($leftLine[9 + $i] - $leftLine[7 - $i]);
                }
                // 水平/垂直梯度系数 b、c：(5*H + 32) >> 6 等为规范中的缩放形式。
                $b = (5 * $H + 32) >> 6;
                $c = (5 * $V + 32) >> 6;
                // a = 16*(top[15]+left[15]+1) - 7*(b+c), 等价于编码标准 a=16*(top[15]+left[15]), pred=(a+b*(x-7)+c*(y-7)+16)>>5
                // 这里的 +1 与 -7*(b+c) 把"以 (7,7) 为中心"的偏移合并进常数项 a。
                $a = 16 * ($topLine[16] + $leftLine[16] + 1) - 7 * ($b + $c);
                for ($y = 0; $y < 16; $y++) {
                    for ($x = 0; $x < 16; $x++) {
                        // 平面方程求值：(a + b*x + c*y) >> 5。
                        $val = ($a + $b * $x + $c * $y) >> 5;
                        // 裁剪到 8bit 范围。
                        $val = max(0, min(255, $val));
                        $pred[$y][$x] = $val;
                    }
                }
                break;
        }
        return $pred;
    }

    /**
     * Intra色度预测（8x8）
     * 规范条款：8.3.3（Intra prediction process for chroma samples）。
     * 4:2:0 下色度块为 8x8，每个宏块各有 U、V 一个块。
     * 模式：0=DC、1=Horizontal、2=Vertical、3=Plane。
     *
     * @param int $mbX    宏块列号
     * @param int $mbY    宏块行号
     * @param int $mode   色度预测模式 0..3
     * @param int $uvIdx  0 表示 U 平面，1 表示 V 平面
     * @return array      8x8 预测样值 $pred[y][x]
     */
    public function intraChromaPrediction(int $mbX, int $mbY, int $mode, int $uvIdx): array
    {
        // intra_pred.rs predict_chroma_8x8
        // 色度预测模式: 0=DC, 1=Horizontal, 2=Vertical, 3=Plane
        // 预测缓冲（8x8），默认 128。
        $pred = array_fill(0, 8, array_fill(0, 8, 128));
        // 色度平面宽度为亮度的一半（4:2:0）。
        $cw = (int)($this->width / 2);
        // 色度块左上角坐标：每个宏块 8x8 色度样本。
        $px0 = $mbX * 8;
        $py0 = $mbY * 8;
        // 上邻/左邻可用性：宏块不在图像首行/首列。
        $hasTop = $mbY > 0;
        $hasLeft = $mbX > 0;
        // 按 uvIdx 选择 U 或 V 平面。
        $planeBuf = $uvIdx === 0 ? $this->uPlane : $this->vPlane;

        // 不可用时填充 128
        // 参考样本缓存：top[0..7]=p[0..7,-1]，left[0..7]=p[-1,0..7]，topLeft=p[-1,-1]。
        $top = array_fill(0, 8, 128);
        $left = array_fill(0, 8, 128);
        $topLeft = 128;

        if ($hasTop) {
            // 读取顶部参考行（色度 stride 为 cw）。
            $ry = $py0 - 1;
            for ($x = 0; $x < 8; $x++) {
                $idx = $ry * $cw + ($px0 + $x);
                if ($idx >= 0 && $idx < count($planeBuf)) $top[$x] = $planeBuf[$idx];
            }
        }
        if ($hasLeft) {
            // 读取左侧参考列（色度 stride 为 cw）。
            $rx = $px0 - 1;
            for ($y = 0; $y < 8; $y++) {
                $idx = ($py0 + $y) * $cw + $rx;
                if ($idx >= 0 && $idx < count($planeBuf)) $left[$y] = $planeBuf[$idx];
            }
        }
        if ($hasTop && $hasLeft) {
            // 左上角样本 p[-1,-1] 仅在两侧都可用时读取。
            $cornerIdx = ($py0 - 1) * $cw + ($px0 - 1);
            if ($cornerIdx >= 0 && $cornerIdx < count($planeBuf)) {
                $topLeft = $planeBuf[$cornerIdx];
            }
        }

        // 不做模式回退, 每个预测函数自己处理 hasTop/hasLeft
        switch ($mode) {
            case 0: // DC -  pred_chroma_8x8_dc  (4 象限)
                // 色度 DC 把 8x8 拆成 4 个 4x4 象限，各象限使用不同的 DC 值（规范 8.3.3）。
                if ($hasTop && $hasLeft) {
                    // 两侧都可用：左上象限用 top[0..3]+left[0..3]；
                    // 右上象限用 top[4..7]；左下象限用 left[4..7]；右下由二者均值合成。
                    $dc0 = 0; $dc1 = 0; $dc2 = 0;
                    for ($i = 0; $i < 4; $i++) {
                        $dc0 += $top[$i] + $left[$i];
                        $dc1 += $top[4 + $i];
                        $dc2 += $left[4 + $i];
                    }
                    // 8 个样本求和：(Sum + 4) >> 3。
                    $dc0Val = ($dc0 + 4) >> 3;
                    // 4 个样本求和：(Sum + 2) >> 2。
                    $dc1Val = ($dc1 + 2) >> 2;
                    $dc2Val = ($dc2 + 2) >> 2;
                    // 右下象限 = 右上与左下 DC 的均值。
                    $dc3Val = ($dc1 + $dc2 + 4) >> 3;
                } elseif (!$hasTop && $hasLeft) {
                    // 仅左侧可用：上、下两个 4x4 象限分别用 left[0..3]、left[4..7] 的均值。
                    $dc0 = 0; $dc2 = 0;
                    for ($i = 0; $i < 4; $i++) {
                        $dc0 += $left[$i];
                        $dc2 += $left[4 + $i];
                    }
                    $dc0Val = ($dc0 + 2) >> 2;
                    // 右侧象限沿用同高度的左侧 DC。
                    $dc1Val = $dc0Val;
                    $dc2Val = ($dc2 + 2) >> 2;
                    $dc3Val = $dc2Val;
                } elseif ($hasTop && !$hasLeft) {
                    // 仅顶部可用：左、右两个 4x4 象限分别用 top[0..3]、top[4..7] 的均值。
                    $dc0 = 0; $dc1 = 0;
                    for ($i = 0; $i < 4; $i++) {
                        $dc0 += $top[$i];
                        $dc1 += $top[4 + $i];
                    }
                    $dc0Val = ($dc0 + 2) >> 2;
                    $dc1Val = ($dc1 + 2) >> 2;
                    // 下侧象限沿用同列的顶部 DC。
                    $dc2Val = $dc0Val;
                    $dc3Val = $dc1Val;
                } else {
                    // 两侧都不可用：四个象限全取 DC_128。
                    $dc0Val = $dc1Val = $dc2Val = $dc3Val = 128;
                }
                // 填充 4 个 4x4 象限
                // 上半部分：左象限 dc0，右象限 dc1。
                for ($y = 0; $y < 4; $y++) {
                    for ($x = 0; $x < 4; $x++) $pred[$y][$x] = $dc0Val;
                    for ($x = 4; $x < 8; $x++) $pred[$y][$x] = $dc1Val;
                }
                // 下半部分：左象限 dc2，右象限 dc3。
                for ($y = 4; $y < 8; $y++) {
                    for ($x = 0; $x < 4; $x++) $pred[$y][$x] = $dc2Val;
                    for ($x = 4; $x < 8; $x++) $pred[$y][$x] = $dc3Val;
                }
                break;
            case 1: // Horizontal
                // pred[x,y] = p[-1,y]：整行复制左侧样本。
                for ($y = 0; $y < 8; $y++) for ($x = 0; $x < 8; $x++) $pred[$y][$x] = $left[$y];
                break;
            case 2: // Vertical
                // pred[x,y] = p[x,-1]：整列复制顶部样本。
                for ($y = 0; $y < 8; $y++) for ($x = 0; $x < 8; $x++) $pred[$y][$x] = $top[$x];
                break;
            case 3: // Plane
                // 色度平面预测（规范 8.3.3 的 Plane 模式）：
                // 以样本 3 为 pivot 计算梯度，最后 4 个样本权重为 4。
                // pivot = 3, extra_mult = 4, last = 7
                $hVal = 0;
                // 水平梯度：x=1..3 时取 top[3+x] - top[3-x]，权重为 x。
                for ($x = 1; $x <= 3; $x++) {
                    $hVal += $x * ($top[3 + $x] - $top[3 - $x]);
                }
                // 最右侧样本 p[7,-1] 相对左上角样本的贡献，权重 4。
                $hVal += 4 * ($top[7] - $topLeft);

                $vVal = 0;
                // 垂直梯度：y=1..3 时取 left[3+y] - left[3-y]，权重为 y。
                for ($y = 1; $y <= 3; $y++) {
                    $vVal += $y * ($left[3 + $y] - $left[3 - $y]);
                }
                // 最下方样本 p[-1,7] 相对左上角样本的贡献，权重 4。
                $vVal += 4 * ($left[7] - $topLeft);

                // 梯度系数 b、c，按 (17*val + 16) >> 5 缩放（17/32 的近似）。
                $b = (17 * $hVal + 16) >> 5;
                $c = (17 * $vVal + 16) >> 5;
                // 常数项 a：以样本 (7,7) 为基准合并偏移，-3*(b+c) 对应 pivot=3 的修正。
                $a = 16 * ($left[7] + $top[7] + 1) - 3 * ($b + $c);

                for ($y = 0; $y < 8; $y++) {
                    // 每行起点累加值 a + c*y，行内逐像素加 b（增量式，避免乘法）。
                    $acc = $a + $c * $y;
                    for ($x = 0; $x < 8; $x++) {
                        // 右移 5 位完成定点缩放。
                        $val = $acc >> 5;
                        // 裁剪到 8bit 范围。
                        $pred[$y][$x] = max(0, min(255, $val));
                        // 水平方向累加梯度 b。
                        $acc += $b;
                    }
                }
                break;
        }
        return $pred;
    }
}
