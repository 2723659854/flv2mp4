<?php

namespace Xiaosongshu\Flv2mp4\Codec\Decode;

/**
 * @purpose 亮度色度运动补偿
 * @author yanglong
 * @time 2026年7月23日15:14:28
 */
trait MotionCompensationTrait
{
    /**
     * 亮度运动补偿 - 1/4 像素精度
     * 严格对齐FFmpeg h264qpel_template.c实现
     *
     * @param array $refPlane 参考帧平面 (已clamp到边界)
     * @param int $refStride 参考帧步长
     * @param int $refWidth 参考帧宽度
     * @param int $refHeight 参考帧高度
     * @param int $x 运动向量 x (1/4像素单位，绝对位置)
     * @param int $y 运动向量 y (1/4像素单位，绝对位置)
     * @param int $blockW 块宽度
     * @param int $blockH 块高度
     * @return array 预测块 (二维数组 [$y][$x])
     */
    public function mcLuma(array $refPlane, int $refStride, int $refWidth, int $refHeight, int $x, int $y, int $blockW, int $blockH): array
    {
        $pred = array_fill(0, $blockH, array_fill(0, $blockW, 0));

        $fracX = $x & 3;
        $fracY = $y & 3;
        $intX = $x >> 2;
        $intY = $y >> 2;

        if ($fracX === 0 && $fracY === 0) {
            for ($j = 0; $j < $blockH; $j++) {
                for ($i = 0; $i < $blockW; $i++) {
                    $rx = $this->clamp($intX + $i, 0, $refWidth - 1);
                    $ry = $this->clamp($intY + $j, 0, $refHeight - 1);
                    $pred[$j][$i] = $refPlane[$ry * $refStride + $rx];
                }
            }
            return $pred;
        }

        $avg = function($a, $b): int {
            return (($a + $b + 1) >> 1);
        };

        $hLowpass = function(int $srcIdx, int $srcStride, int $w, int $h) use ($refPlane, $refStride, $refWidth, $refHeight): array {
            $out = array_fill(0, $h, array_fill(0, $w, 0));
            $srcY = $srcIdx >> 16;
            $srcX = $srcIdx & 0xFFFF;
            if ($srcX & 0x8000) $srcX -= 0x10000;
            for ($j = 0; $j < $h; $j++) {
                $ry = $this->clamp($srcY + $j, 0, $refHeight - 1);
                for ($i = 0; $i < $w; $i++) {
                    $rx0 = $this->clamp($srcX + $i - 2, 0, $refWidth - 1);
                    $rx1 = $this->clamp($srcX + $i - 1, 0, $refWidth - 1);
                    $rx2 = $this->clamp($srcX + $i, 0, $refWidth - 1);
                    $rx3 = $this->clamp($srcX + $i + 1, 0, $refWidth - 1);
                    $rx4 = $this->clamp($srcX + $i + 2, 0, $refWidth - 1);
                    $rx5 = $this->clamp($srcX + $i + 3, 0, $refWidth - 1);
                    $p0 = $refPlane[$ry * $refStride + $rx0];
                    $p1 = $refPlane[$ry * $refStride + $rx1];
                    $p2 = $refPlane[$ry * $refStride + $rx2];
                    $p3 = $refPlane[$ry * $refStride + $rx3];
                    $p4 = $refPlane[$ry * $refStride + $rx4];
                    $p5 = $refPlane[$ry * $refStride + $rx5];
                    $val = $p0 - 5*$p1 + 20*$p2 + 20*$p3 - 5*$p4 + $p5;
                    $out[$j][$i] = $this->clip255(($val + 16) >> 5);
                }
            }
            return $out;
        };

        $vLowpass = function(int $srcIdx, int $srcStride, int $w, int $h) use ($refPlane, $refStride, $refWidth, $refHeight): array {
            $out = array_fill(0, $h, array_fill(0, $w, 0));
            $srcY = $srcIdx >> 16;
            $srcX = $srcIdx & 0xFFFF;
            if ($srcX & 0x8000) $srcX -= 0x10000;
            for ($j = 0; $j < $h; $j++) {
                for ($i = 0; $i < $w; $i++) {
                    $rx = $this->clamp($srcX + $i, 0, $refWidth - 1);
                    $ry0 = $this->clamp($srcY + $j - 2, 0, $refHeight - 1);
                    $ry1 = $this->clamp($srcY + $j - 1, 0, $refHeight - 1);
                    $ry2 = $this->clamp($srcY + $j, 0, $refHeight - 1);
                    $ry3 = $this->clamp($srcY + $j + 1, 0, $refHeight - 1);
                    $ry4 = $this->clamp($srcY + $j + 2, 0, $refHeight - 1);
                    $ry5 = $this->clamp($srcY + $j + 3, 0, $refHeight - 1);
                    $p0 = $refPlane[$ry0 * $refStride + $rx];
                    $p1 = $refPlane[$ry1 * $refStride + $rx];
                    $p2 = $refPlane[$ry2 * $refStride + $rx];
                    $p3 = $refPlane[$ry3 * $refStride + $rx];
                    $p4 = $refPlane[$ry4 * $refStride + $rx];
                    $p5 = $refPlane[$ry5 * $refStride + $rx];
                    $val = $p0 - 5*$p1 + 20*$p2 + 20*$p3 - 5*$p4 + $p5;
                    $out[$j][$i] = $this->clip255(($val + 16) >> 5);
                }
            }
            return $out;
        };

        $hvLowpass = function(int $srcIdx, int $w, int $h) use ($refPlane, $refStride, $refWidth, $refHeight): array {
            $srcY = $srcIdx >> 16;
            $srcX = $srcIdx & 0xFFFF;
            if ($srcX & 0x8000) $srcX -= 0x10000;
            $tmpH = $h + 5;
            $tmp = array_fill(0, $tmpH, array_fill(0, $w, 0));
            for ($j = 0; $j < $tmpH; $j++) {
                $ry = $this->clamp($srcY - 2 + $j, 0, $refHeight - 1);
                for ($i = 0; $i < $w; $i++) {
                    $rx0 = $this->clamp($srcX + $i - 2, 0, $refWidth - 1);
                    $rx1 = $this->clamp($srcX + $i - 1, 0, $refWidth - 1);
                    $rx2 = $this->clamp($srcX + $i, 0, $refWidth - 1);
                    $rx3 = $this->clamp($srcX + $i + 1, 0, $refWidth - 1);
                    $rx4 = $this->clamp($srcX + $i + 2, 0, $refWidth - 1);
                    $rx5 = $this->clamp($srcX + $i + 3, 0, $refWidth - 1);
                    $p0 = $refPlane[$ry * $refStride + $rx0];
                    $p1 = $refPlane[$ry * $refStride + $rx1];
                    $p2 = $refPlane[$ry * $refStride + $rx2];
                    $p3 = $refPlane[$ry * $refStride + $rx3];
                    $p4 = $refPlane[$ry * $refStride + $rx4];
                    $p5 = $refPlane[$ry * $refStride + $rx5];
                    $tmp[$j][$i] = $p0 - 5*$p1 + 20*$p2 + 20*$p3 - 5*$p4 + $p5;
                }
            }
            $out = array_fill(0, $h, array_fill(0, $w, 0));
            for ($j = 0; $j < $h; $j++) {
                for ($i = 0; $i < $w; $i++) {
                    $t0 = $tmp[$j][$i];
                    $t1 = $tmp[$j + 1][$i];
                    $t2 = $tmp[$j + 2][$i];
                    $t3 = $tmp[$j + 3][$i];
                    $t4 = $tmp[$j + 4][$i];
                    $t5 = $tmp[$j + 5][$i];
                    $val = $t0 - 5*$t1 + 20*$t2 + 20*$t3 - 5*$t4 + $t5;
                    $out[$j][$i] = $this->clip255(($val + 512) >> 10);
                }
            }
            return $out;
        };

        $srcIdx = ($intY << 16) | $intX;

        if ($fracY === 0) {
            $halfH = $hLowpass($srcIdx, $refStride, $blockW, $blockH);
            if ($fracX === 1) {
                for ($j = 0; $j < $blockH; $j++) {
                    $ry = $this->clamp($intY + $j, 0, $refHeight - 1);
                    for ($i = 0; $i < $blockW; $i++) {
                        $rx = $this->clamp($intX + $i, 0, $refWidth - 1);
                        $pred[$j][$i] = $avg($refPlane[$ry * $refStride + $rx], $halfH[$j][$i]);
                    }
                }
            } elseif ($fracX === 2) {
                for ($j = 0; $j < $blockH; $j++) {
                    for ($i = 0; $i < $blockW; $i++) {
                        $pred[$j][$i] = $halfH[$j][$i];
                    }
                }
            } else {
                for ($j = 0; $j < $blockH; $j++) {
                    $ry = $this->clamp($intY + $j, 0, $refHeight - 1);
                    for ($i = 0; $i < $blockW; $i++) {
                        $rx = $this->clamp($intX + $i + 1, 0, $refWidth - 1);
                        $pred[$j][$i] = $avg($halfH[$j][$i], $refPlane[$ry * $refStride + $rx]);
                    }
                }
            }
        } elseif ($fracX === 0) {
            $halfV = $vLowpass((($intY - 2) << 16) | $intX, $refStride, $blockW, $blockH + 4);
            if ($fracY === 1) {
                for ($j = 0; $j < $blockH; $j++) {
                    $ry = $this->clamp($intY + $j, 0, $refHeight - 1);
                    for ($i = 0; $i < $blockW; $i++) {
                        $rx = $this->clamp($intX + $i, 0, $refWidth - 1);
                        $pred[$j][$i] = $avg($refPlane[$ry * $refStride + $rx], $halfV[$j + 2][$i]);
                    }
                }
            } elseif ($fracY === 2) {
                for ($j = 0; $j < $blockH; $j++) {
                    for ($i = 0; $i < $blockW; $i++) {
                        $pred[$j][$i] = $halfV[$j + 2][$i];
                    }
                }
            } else {
                for ($j = 0; $j < $blockH; $j++) {
                    $ry = $this->clamp($intY + $j + 1, 0, $refHeight - 1);
                    for ($i = 0; $i < $blockW; $i++) {
                        $rx = $this->clamp($intX + $i, 0, $refWidth - 1);
                        $pred[$j][$i] = $avg($halfV[$j + 2][$i], $refPlane[$ry * $refStride + $rx]);
                    }
                }
            }
        } elseif ($fracX === 2 && $fracY === 2) {
            $pred = $hvLowpass($srcIdx, $blockW, $blockH);
        } elseif ($fracX === 2) {
            $halfH = $hLowpass((($intY + ($fracY === 1 ? 0 : 1)) << 16) | $intX, $refStride, $blockW, $blockH);
            $halfHV = $hvLowpass($srcIdx, $blockW, $blockH);
            for ($j = 0; $j < $blockH; $j++) {
                for ($i = 0; $i < $blockW; $i++) {
                    $pred[$j][$i] = $avg($halfH[$j][$i], $halfHV[$j][$i]);
                }
            }
        } elseif ($fracY === 2) {
            $srcOffX = ($fracX === 1) ? 0 : 1;
            $halfV = $vLowpass((($intY - 2) << 16) | ($intX + $srcOffX), $refStride, $blockW, $blockH + 4);
            $halfHV = $hvLowpass($srcIdx, $blockW, $blockH);
            for ($j = 0; $j < $blockH; $j++) {
                for ($i = 0; $i < $blockW; $i++) {
                    $pred[$j][$i] = $avg($halfV[$j + 2][$i], $halfHV[$j][$i]);
                }
            }
        } else {
            $srcOffX = ($fracX === 3) ? 1 : 0;
            $srcOffY = ($fracY === 3) ? 1 : 0;
            $halfH = $hLowpass((($intY + $srcOffY) << 16) | $intX, $refStride, $blockW, $blockH);
            $halfV = $vLowpass((($intY - 2) << 16) | ($intX + $srcOffX), $refStride, $blockW, $blockH + 4);
            for ($j = 0; $j < $blockH; $j++) {
                for ($i = 0; $i < $blockW; $i++) {
                    $pred[$j][$i] = $avg($halfH[$j][$i], $halfV[$j + 2][$i]);
                }
            }
        }

        for ($j = 0; $j < $blockH; $j++) {
            for ($i = 0; $i < $blockW; $i++) {
                $pred[$j][$i] = $this->clip255($pred[$j][$i]);
            }
        }

        return $pred;
    }

    /**
     * 色度运动补偿 - 1/8 像素精度
     * @param array $refPlane 参考帧平面
     * @param int $refStride 参考帧步长
     * @param int $refWidth 参考帧宽度
     * @param int $refHeight 参考帧高度
     * @param int $x 运动向量 x (1/8像素单位)
     * @param int $y 运动向量 y (1/8像素单位)
     * @param int $blockW 块宽度
     * @param int $blockH 块高度
     * @return array 预测块 (二维数组 [$y][$x])
     */
    public function mcChroma(array &$refPlane, int $refStride, int $refWidth, int $refHeight, int $x, int $y, int $blockW, int $blockH): array
    {
        $pred = array_fill(0, $blockH, array_fill(0, $blockW, 0));

        $fracX = $x & 7;
        $fracY = $y & 7;
        $intX = $x >> 3;
        $intY = $y >> 3;

        for ($j = 0; $j < $blockH; $j++) {
            for ($i = 0; $i < $blockW; $i++) {
                $a00 = $this->getRefPixel($refPlane, $refStride, $refWidth, $refHeight, $intX + $i, $intY + $j);
                $a10 = $this->getRefPixel($refPlane, $refStride, $refWidth, $refHeight, $intX + $i + 1, $intY + $j);
                $a01 = $this->getRefPixel($refPlane, $refStride, $refWidth, $refHeight, $intX + $i, $intY + $j + 1);
                $a11 = $this->getRefPixel($refPlane, $refStride, $refWidth, $refHeight, $intX + $i + 1, $intY + $j + 1);

                $val = ((8 - $fracX) * (8 - $fracY) * $a00 +
                         $fracX * (8 - $fracY) * $a10 +
                         (8 - $fracX) * $fracY * $a01 +
                         $fracX * $fracY * $a11 + 32) >> 6;
                $pred[$j][$i] = $this->clip255($val);
            }
        }

        return $pred;
    }

    private function getRefPixel(array &$refPlane, int $stride, int $w, int $h, int $x, int $y): int
    {
        $x = $this->clamp($x, 0, $w - 1);
        $y = $this->clamp($y, 0, $h - 1);
        return $refPlane[$y * $stride + $x];
    }

    private function clamp(int $val, int $min, int $max): int
    {
        return max($min, min($max, $val));
    }

    private function clip255(int $val): int
    {
        return max(0, min(255, $val));
    }
}
