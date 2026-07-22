<?php

namespace Xiaosongshu\Flv2mp4\Codec;

trait IntraPredictionTrait
{
    /**
     * Intra4x4 帧内预测 - 翻译自wedeo intra_pred.rs predict_4x4
     */
    public function intra4x4Prediction(int $mbX, int $mbY, int $blkX, int $blkY, int $mode): array
    {
        $predicted = array_fill(0, 4, array_fill(0, 4, 128));

        if ($mode < 0 || $mode > 8) {
            $mode = 2;
        }

        $mbPx = $mbX * 16 + $blkX * 4;
        $mbPy = $mbY * 16 + $blkY * 4;

        $topAvail = ($blkY === 0 && $mbY > 0) || $blkY > 0;
        $leftAvail = ($blkX === 0 && $mbX > 0) || $blkX > 0;
        $topRightAvail = false;

        $top = array_fill(0, 8, 128);
        $left = array_fill(0, 4, 128);
        $topLeft = 128;

        // 与wedeo intra_pred.rs一致：不强制转换模式
        // 当top/left不可用时，top/left数组保持为128（默认值）
        // 预测函数直接使用这些值，与wedeo predict_4x4行为一致

        if ($leftAvail) {
            $refX = $mbPx - 1;
            for ($y = 0; $y < 4; $y++) {
                $py = $mbPy + $y;
                if ($py < $this->height && $refX >= 0) {
                    $idx = $py * $this->width + $refX;
                    if ($idx >= 0 && $idx < count($this->yPlane)) {
                        $left[$y] = $this->yPlane[$idx];
                    }
                }
            }
        }

        if ($topAvail) {
            $refY = $mbPy - 1;
            // 与wedeo mb.rs一致：先读取top[0..3]
            for ($x = 0; $x < 4; $x++) {
                $px = $mbPx + $x;
                if ($px < $this->width && $refY >= 0) {
                    $idx = $refY * $this->width + $px;
                    if ($idx >= 0 && $idx < count($this->yPlane)) {
                        $top[$x] = $this->yPlane[$idx];
                    }
                }
            }

            // 判断top-right可用性（参考wedeo mb.rs gather_top_luma）
            if ($blkY > 0) {
                // 宏块内部的行：考虑8x8块边界
                $blockHasTopRight = ($blkX < 3)
                    && !($blkX == 1 && $blkY == 1)
                    && !($blkX == 1 && $blkY == 3)
                    && !($blkX == 3 && $blkY == 1)
                    && !($blkX == 3 && $blkY == 3);
            } else {
                // 宏块第一行（blkY==0）：top-right来自上方宏块
                if ($blkX < 3) {
                    $blockHasTopRight = true;
                } else {
                    // blkX==3: top-right来自右上方的宏块
                    $blockHasTopRight = ($mbX + 1 < $this->picWidthInMbs);
                }
            }

            if ($blockHasTopRight) {
                for ($x = 4; $x < 8; $x++) {
                    $px = $mbPx + $x;
                    if ($px < $this->width && $refY >= 0) {
                        $idx = $refY * $this->width + $px;
                        if ($idx >= 0 && $idx < count($this->yPlane)) {
                            $top[$x] = $this->yPlane[$idx];
                        }
                    }
                }
                $topRightAvail = true;
            } else {
                // top-right不可用：填充为top[3]（与wedeo gather_top_luma一致）
                for ($x = 4; $x < 8; $x++) {
                    $top[$x] = $top[3];
                }
            }
        }
        // 当top不可用时，保持top数组初始值128（与Rust/FFmpeg一致）

        if (($mbX === 1 || $mbX === 2) && $mbY === 0 && $blkX === 0 && $blkY === 0) {
            //echo "[DBG_PRED_FUNC MB($mbX,$mbY) blk(0,0)] top=[" . implode(',', $top) . "] left=[" . implode(',', $left) . "] topAvail=" . ($topAvail ? 1 : 0) . " leftAvail=" . ($leftAvail ? 1 : 0) . " mode=$mode\n";
            if ($mbX === 1) {
                //echo "[DBG_PRED_FUNC MB(1,0) blk(0,0)] blk(0,0) decoded pixels: ";
                for ($y = 0; $y < 4; $y++) {
                    for ($x = 0; $x < 4; $x++) {
                        $idx = $y * $this->width + (16 + $x);
                        echo $this->yPlane[$idx] . ",";
                    }
                }
                //echo "\n";
            }
        }
//        if ($mbX === 2 && $mbY === 0 && $blkX === 2 && $blkY === 0) {
//            echo "[DBG_PRED_FUNC MB(2,0) blk(2,0)] top=[" . implode(',', $top) . "] left=[" . implode(',', $left) . "] topAvail=" . ($topAvail ? 1 : 0) . " leftAvail=" . ($leftAvail ? 1 : 0) . " mode=$mode\n";
//            echo "[DBG_PRED_FUNC MB(2,0) blk(2,0)] blk(1,0) right edge (x=35): ";
//            for ($y = 0; $y < 4; $y++) {
//                $idx = $y * $this->width + 35;
//                echo $this->yPlane[$idx] . ",";
//            }
//            echo "\n";
//        }
//        if ($mbX === 1 && $mbY === 0 && $blkX === 3 && $blkY === 0) {
//            echo "[DBG_PRED_FUNC MB(1,0) blk(3,0)] mode=$mode top=[" . implode(',', $top) . "] left=[" . implode(',', $left) . "] topLeft=$topLeft topAvail=" . ($topAvail ? 1 : 0) . " leftAvail=" . ($leftAvail ? 1 : 0) . " mbPx=$mbPx mbPy=$mbPy\n";
//        }
//        if ($mbX === 6 && $mbY === 0 && $blkX === 0 && $blkY === 0) {
//            echo "[DBG_PRED_FUNC MB(6,0) blk(0,0)] top=[" . implode(',', $top) . "] left=[" . implode(',', $left) . "] topAvail=" . ($topAvail ? 1 : 0) . " leftAvail=" . ($leftAvail ? 1 : 0) . " mode=$mode\n";
//        }

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

        $dst = array_fill(0, 16, 0);
        $stride = 4;

//        if ($mbX === 2 && $mbY === 0) {
//            echo "[DBG_PRED_MODE MB(2,0) blk($blkX,$blkY)] mode=$mode topAvail=" . ($topAvail ? 1 : 0) . " leftAvail=" . ($leftAvail ? 1 : 0) . "\n";
//        }

        switch ($mode) {
            case 0: // Vertical
                for ($y = 0; $y < 4; $y++) {
                    for ($x = 0; $x < 4; $x++) {
                        $predicted[$y][$x] = $top[$x];
                    }
                }
                break;

            case 1: // Horizontal
                for ($y = 0; $y < 4; $y++) {
                    for ($x = 0; $x < 4; $x++) {
                        $predicted[$y][$x] = $left[$y];
                    }
                }
                break;

            case 2: // DC
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
                        $predicted[$y][$x] = $avg;
                    }
                }
//                if ($mbX === 2 && $mbY === 0 && $blkX === 2 && $blkY === 0) {
//                    echo "[DBG_PRED MB(2,0) blk(2,0) mode=2] sum=$sum avg=$avg\n";
//                    echo "[DBG_PRED] predicted=[" . implode(',', $predicted[0]) . "],[" . implode(',', $predicted[1]) . "],[" . implode(',', $predicted[2]) . "],[" . implode(',', $predicted[3]) . "]\n";
//                }
                break;

            case 3: // Diagonal Down-Left
                $t0 = $top[0];
                $t1 = $top[1];
                $t2 = $top[2];
                $t3 = $top[3];
                $t4 = $top[4];
                $t5 = $top[5];
                $t6 = $top[6];
                $t7 = $top[7];
                $predicted[0][0] = (int)(($t0 + 2 * $t1 + $t2 + 2) >> 2);
                $predicted[0][1] = (int)(($t1 + 2 * $t2 + $t3 + 2) >> 2);
                $predicted[1][0] = $predicted[0][1];
                $predicted[0][2] = (int)(($t2 + 2 * $t3 + $t4 + 2) >> 2);
                $predicted[1][1] = $predicted[0][2];
                $predicted[2][0] = $predicted[0][2];
                $predicted[0][3] = (int)(($t3 + 2 * $t4 + $t5 + 2) >> 2);
                $predicted[1][2] = $predicted[0][3];
                $predicted[2][1] = $predicted[0][3];
                $predicted[3][0] = $predicted[0][3];
                $predicted[1][3] = (int)(($t4 + 2 * $t5 + $t6 + 2) >> 2);
                $predicted[2][2] = $predicted[1][3];
                $predicted[3][1] = $predicted[1][3];
                $predicted[2][3] = (int)(($t5 + 2 * $t6 + $t7 + 2) >> 2);
                $predicted[3][2] = $predicted[2][3];
                $predicted[3][3] = (int)(($t6 + 3 * $t7 + 2) >> 2);
                break;

            case 4: // Diagonal Down-Right
                $lt = $topLeft;
                $t0 = $top[0];
                $t1 = $top[1];
                $t2 = $top[2];
                $t3 = $top[3];
                $l0 = $left[0];
                $l1 = $left[1];
                $l2 = $left[2];
                $l3 = $left[3];
                $avg3 = function($a, $b, $c) {
                    return (int)(($a + 2 * $b + $c + 2) >> 2);
                };
                $v03 = $avg3($l3, $l2, $l1);
                $v02 = $avg3($l2, $l1, $l0);
                $v01 = $avg3($l1, $l0, $lt);
                $v00 = $avg3($l0, $lt, $t0);
                $v10 = $avg3($lt, $t0, $t1);
                $v20 = $avg3($t0, $t1, $t2);
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
                $lt = $topLeft;
                $t0 = $top[0];
                $t1 = $top[1];
                $t2 = $top[2];
                $t3 = $top[3];
                $l0 = $left[0];
                $l1 = $left[1];
                $l2 = $left[2];
                $avg2 = function($a, $b) {
                    return (int)(($a + $b + 1) >> 1);
                };
                $avg3 = function($a, $b, $c) {
                    return (int)(($a + 2 * $b + $c + 2) >> 2);
                };
                $predicted[0][0] = $avg2($lt, $t0);
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
                $predicted[2][0] = $avg3($lt, $l0, $l1);
                $predicted[3][0] = $avg3($l0, $l1, $l2);
                break;

            case 6: // Horizontal-Down
                $lt = $topLeft;
                $t0 = $top[0];
                $t1 = $top[1];
                $t2 = $top[2];
                $l0 = $left[0];
                $l1 = $left[1];
                $l2 = $left[2];
                $l3 = $left[3];
                $avg2 = function($a, $b) {
                    return (int)(($a + $b + 1) >> 1);
                };
                $avg3 = function($a, $b, $c) {
                    return (int)(($a + 2 * $b + $c + 2) >> 2);
                };
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
                $t0 = $top[0];
                $t1 = $top[1];
                $t2 = $top[2];
                $t3 = $top[3];
                $t4 = $top[4];
                $t5 = $top[5];
                $t6 = $top[6];
                $avg2 = function($a, $b) {
                    return (int)(($a + $b + 1) >> 1);
                };
                $avg3 = function($a, $b, $c) {
                    return (int)(($a + 2 * $b + $c + 2) >> 2);
                };
                $predicted[0][0] = $avg2($t0, $t1);
                $predicted[0][1] = $avg2($t1, $t2);
                $predicted[2][0] = $predicted[0][1];
                $predicted[0][2] = $avg2($t2, $t3);
                $predicted[2][1] = $predicted[0][2];
                $predicted[0][3] = $avg2($t3, $t4);
                $predicted[2][2] = $predicted[0][3];
                $predicted[2][3] = $avg2($t4, $t5);
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
                // 参考 FFmpeg h264pred_template.c pred4x4_horizontal_up:
                //   src[x + y*stride] 对应 predicted[y][x]
                $l0 = $left[0];
                $l1 = $left[1];
                $l2 = $left[2];
                $l3 = $left[3];
                $avg2 = function($a, $b) {
                    return (int)(($a + $b + 1) >> 1);
                };
                $avg3 = function($a, $b, $c) {
                    return (int)(($a + 2 * $b + $c + 2) >> 2);
                };
                $predicted[0][0] = $avg2($l0, $l1);
                $predicted[0][1] = $avg3($l0, $l1, $l2);
                $predicted[0][2] = $avg2($l1, $l2);
                $predicted[1][0] = $predicted[0][2];
                $predicted[0][3] = $avg3($l1, $l2, $l3);
                $predicted[1][1] = $predicted[0][3];
                $predicted[1][2] = $avg2($l2, $l3);
                $predicted[2][0] = $predicted[1][2];
                $predicted[1][3] = $avg3($l2, $l3, $l3);
                $predicted[2][1] = $predicted[1][3];
                $predicted[2][3] = $l3;
                $predicted[3][1] = $l3;
                $predicted[3][0] = $l3;
                $predicted[2][2] = $l3;
                $predicted[3][2] = $l3;
                $predicted[3][3] = $l3;
                break;
        }

        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                if ($predicted[$y][$x] < 0) $predicted[$y][$x] = 0;
                if ($predicted[$y][$x] > 255) $predicted[$y][$x] = 255;
            }
        }

        return $predicted;
    }

    /**
     * Intra16x16 亮度预测
     */
    public function intra16x16Prediction(int $mbX, int $mbY, int $mode): array
    {
        $pred = array_fill(0, 16, array_fill(0, 16, 128));
        $px0 = $mbX * 16;
        $py0 = $mbY * 16;
        $topAvail = $mbY > 0;
        $leftAvail = $mbX > 0;
        $topLine = array_fill(0, 18, 128);
        $leftLine = array_fill(0, 18, 128);

        if ($topAvail) {
            $ry = $py0 - 1;
            for ($x = 0; $x < 16; $x++) {
                $idx = $ry * $this->width + ($px0 + $x);
                if ($idx >= 0 && $idx < count($this->yPlane)) $topLine[$x + 1] = $this->yPlane[$idx];
            }
        }
        if ($leftAvail) {
            $rx = $px0 - 1;
            for ($y = 0; $y < 16; $y++) {
                $idx = ($py0 + $y) * $this->width + $rx;
                if ($idx >= 0 && $idx < count($this->yPlane)) $leftLine[$y + 1] = $this->yPlane[$idx];
            }
        }
        // 角落参考像素
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

        // 参考 Rust 实现：当参考像素不可用时，回退到 DC 模式
        // 垂直模式需要顶部参考，水平模式需要左侧参考
        if ($mode === 0 && !$topAvail) {
            $mode = 2; // 回退到 DC
        }
        if ($mode === 1 && !$leftAvail) {
            $mode = 2; // 回退到 DC
        }

        switch ($mode) {
            case 0: // 垂直预测
                for ($y = 0; $y < 16; $y++) {
                    for ($x = 0; $x < 16; $x++) {
                        $pred[$y][$x] = $topLine[$x + 1];
                    }
                }
                break;
            case 1: // 水平预测
                for ($y = 0; $y < 16; $y++) {
                    for ($x = 0; $x < 16; $x++) {
                        $pred[$y][$x] = $leftLine[$y + 1];
                    }
                }
                break;
            case 2: // DC均值预测（参考 Rust 的 compute_dc_value 实现）
                $sum = 0;
                $cnt = 0;
                if ($topAvail) {
                    for ($i = 1; $i <= 16; $i++) {
                        $sum += $topLine[$i];
                        $cnt++;
                    }
                }
                if ($leftAvail) {
                    for ($i = 1; $i <= 16; $i++) {
                        $sum += $leftLine[$i];
                        $cnt++;
                    }
                }
                if ($topAvail && $leftAvail) {
                    $dcVal = ($sum + 16) >> 5; // (sum + N) >> (log2(N) + 1), N=16
                } elseif ($topAvail) {
                    $dcVal = ($sum + 8) >> 4; // (sum + N/2) >> log2(N), N=16
                } elseif ($leftAvail) {
                    $dcVal = ($sum + 8) >> 4; // (sum + N/2) >> log2(N), N=16
                } else {
                    $dcVal = 128; // DC_128 模式，无参考像素时使用固定值
                }
                for ($y = 0; $y < 16; $y++) {
                    for ($x = 0; $x < 16; $x++) {
                        $pred[$y][$x] = $dcVal;
                    }
                }
                break;
            case 3: // 平面预测 (H.264 8.3.3.1.4, FFmpeg pred16x16_plane_compat)
                // topLine[0]=lt, topLine[1..16]=top[0..15]
                // leftLine[0]=lt, leftLine[1..16]=left[0..15]
                // H = sum_{i=0..7} (i+1)*(top[8+i] - top[6-i]), 当 i=7 时 top[6-7]=top[-1]=lt
                // V = sum_{i=0..7} (i+1)*(left[8+i] - left[6-i])
                $H = 0;
                $V = 0;
                for ($i = 0; $i < 8; $i++) {
                    $k = $i + 1;
                    $H += $k * ($topLine[9 + $i] - $topLine[7 - $i]);
                    $V += $k * ($leftLine[9 + $i] - $leftLine[7 - $i]);
                }
                $b = (5 * $H + 32) >> 6;
                $c = (5 * $V + 32) >> 6;
                // a = 16*(top[15]+left[15]+1) - 7*(b+c), 等价于 H.264 标准 a=16*(top[15]+left[15]), pred=(a+b*(x-7)+c*(y-7)+16)>>5
                $a = 16 * ($topLine[16] + $leftLine[16] + 1) - 7 * ($b + $c);
                for ($y = 0; $y < 16; $y++) {
                    for ($x = 0; $x < 16; $x++) {
                        $val = ($a + $b * $x + $c * $y) >> 5;
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
     */
    public function intraChromaPrediction(int $mbX, int $mbY, int $mode, int $uvIdx): array
    {
        // 严格参考 wedeo intra_pred.rs predict_chroma_8x8
        // H.264 色度预测模式: 0=DC, 1=Horizontal, 2=Vertical, 3=Plane
        $pred = array_fill(0, 8, array_fill(0, 8, 128));
        $cw = (int)($this->width / 2);
        $px0 = $mbX * 8;
        $py0 = $mbY * 8;
        $hasTop = $mbY > 0;
        $hasLeft = $mbX > 0;
        $planeBuf = $uvIdx === 0 ? $this->uPlane : $this->vPlane;

        // 与 wedeo mb.rs gather_top/gather_left/gather_top_left 一致:
        // 不可用时填充 128
        $top = array_fill(0, 8, 128);
        $left = array_fill(0, 8, 128);
        $topLeft = 128;

        if ($hasTop) {
            $ry = $py0 - 1;
            for ($x = 0; $x < 8; $x++) {
                $idx = $ry * $cw + ($px0 + $x);
                if ($idx >= 0 && $idx < count($planeBuf)) $top[$x] = $planeBuf[$idx];
            }
        }
        if ($hasLeft) {
            $rx = $px0 - 1;
            for ($y = 0; $y < 8; $y++) {
                $idx = ($py0 + $y) * $cw + $rx;
                if ($idx >= 0 && $idx < count($planeBuf)) $left[$y] = $planeBuf[$idx];
            }
        }
        if ($hasTop && $hasLeft) {
            $cornerIdx = ($py0 - 1) * $cw + ($px0 - 1);
            if ($cornerIdx >= 0 && $cornerIdx < count($planeBuf)) {
                $topLeft = $planeBuf[$cornerIdx];
            }
        }

        // 与 wedeo intra_pred.rs predict_chroma_8x8 match 一致:
        // 不做模式回退, 每个预测函数自己处理 hasTop/hasLeft
        switch ($mode) {
            case 0: // DC - 与 wedeo pred_chroma_8x8_dc 一致 (4 象限)
                if ($hasTop && $hasLeft) {
                    $dc0 = 0; $dc1 = 0; $dc2 = 0;
                    for ($i = 0; $i < 4; $i++) {
                        $dc0 += $top[$i] + $left[$i];
                        $dc1 += $top[4 + $i];
                        $dc2 += $left[4 + $i];
                    }
                    $dc0Val = ($dc0 + 4) >> 3;
                    $dc1Val = ($dc1 + 2) >> 2;
                    $dc2Val = ($dc2 + 2) >> 2;
                    $dc3Val = ($dc1 + $dc2 + 4) >> 3;
                } elseif (!$hasTop && $hasLeft) {
                    $dc0 = 0; $dc2 = 0;
                    for ($i = 0; $i < 4; $i++) {
                        $dc0 += $left[$i];
                        $dc2 += $left[4 + $i];
                    }
                    $dc0Val = ($dc0 + 2) >> 2;
                    $dc1Val = $dc0Val;
                    $dc2Val = ($dc2 + 2) >> 2;
                    $dc3Val = $dc2Val;
                } elseif ($hasTop && !$hasLeft) {
                    $dc0 = 0; $dc1 = 0;
                    for ($i = 0; $i < 4; $i++) {
                        $dc0 += $top[$i];
                        $dc1 += $top[4 + $i];
                    }
                    $dc0Val = ($dc0 + 2) >> 2;
                    $dc1Val = ($dc1 + 2) >> 2;
                    $dc2Val = $dc0Val;
                    $dc3Val = $dc1Val;
                } else {
                    $dc0Val = $dc1Val = $dc2Val = $dc3Val = 128;
                }
                // 填充 4 个 4x4 象限
                for ($y = 0; $y < 4; $y++) {
                    for ($x = 0; $x < 4; $x++) $pred[$y][$x] = $dc0Val;
                    for ($x = 4; $x < 8; $x++) $pred[$y][$x] = $dc1Val;
                }
                for ($y = 4; $y < 8; $y++) {
                    for ($x = 0; $x < 4; $x++) $pred[$y][$x] = $dc2Val;
                    for ($x = 4; $x < 8; $x++) $pred[$y][$x] = $dc3Val;
                }
                break;
            case 1: // Horizontal - 与 wedeo pred_chroma_8x8_horizontal 一致
                for ($y = 0; $y < 8; $y++) for ($x = 0; $x < 8; $x++) $pred[$y][$x] = $left[$y];
                break;
            case 2: // Vertical - 与 wedeo pred_chroma_8x8_vertical 一致
                for ($y = 0; $y < 8; $y++) for ($x = 0; $x < 8; $x++) $pred[$y][$x] = $top[$x];
                break;
            case 3: // Plane - 与 wedeo pred_chroma_8x8_plane / plane_pred<8,17,16,5> 一致
                // pivot = 3, extra_mult = 4, last = 7
                $hVal = 0;
                for ($x = 1; $x <= 3; $x++) {
                    $hVal += $x * ($top[3 + $x] - $top[3 - $x]);
                }
                $hVal += 4 * ($top[7] - $topLeft);

                $vVal = 0;
                for ($y = 1; $y <= 3; $y++) {
                    $vVal += $y * ($left[3 + $y] - $left[3 - $y]);
                }
                $vVal += 4 * ($left[7] - $topLeft);

                $b = (17 * $hVal + 16) >> 5;
                $c = (17 * $vVal + 16) >> 5;
                $a = 16 * ($left[7] + $top[7] + 1) - 3 * ($b + $c);

                for ($y = 0; $y < 8; $y++) {
                    $acc = $a + $c * $y;
                    for ($x = 0; $x < 8; $x++) {
                        $val = $acc >> 5;
                        $pred[$y][$x] = max(0, min(255, $val));
                        $acc += $b;
                    }
                }
                break;
        }
        return $pred;
    }
}
