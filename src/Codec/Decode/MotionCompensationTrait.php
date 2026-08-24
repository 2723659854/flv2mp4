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
        $maxX = $refWidth - 1;
        $maxY = $refHeight - 1;

        if ($fracX === 0 && $fracY === 0) {
            $xIndex = [];
            for ($i = 0; $i < $blockW; $i++) {
                $sx = $intX + $i;
                $xIndex[$i] = $sx < 0 ? 0 : ($sx > $maxX ? $maxX : $sx);
            }
            for ($j = 0; $j < $blockH; $j++) {
                $sy = $intY + $j;
                $sy = $sy < 0 ? 0 : ($sy > $maxY ? $maxY : $sy);
                $row = $sy * $refStride;
                for ($i = 0; $i < $blockW; $i++) {
                    $pred[$j][$i] = $refPlane[$row + $xIndex[$i]];
                }
            }
            return $pred;
        }

        if ($fracY === 0) {
            $halfH = $this->lumaHorizontalLowpass($refPlane, $refStride, $refWidth, $refHeight, $intX, $intY, $blockW, $blockH);
            $srcOffX = $fracX === 3 ? 1 : 0;
            for ($j = 0; $j < $blockH; $j++) {
                $sy = $intY + $j;
                $sy = $sy < 0 ? 0 : ($sy > $maxY ? $maxY : $sy);
                $row = $sy * $refStride;
                $outBase = $j * $blockW;
                for ($i = 0; $i < $blockW; $i++) {
                    if ($fracX === 2) {
                        $pred[$j][$i] = $halfH[$outBase + $i];
                    } else {
                        $sx = $intX + $i + $srcOffX;
                        $sx = $sx < 0 ? 0 : ($sx > $maxX ? $maxX : $sx);
                        $pred[$j][$i] = ($refPlane[$row + $sx] + $halfH[$outBase + $i] + 1) >> 1;
                    }
                }
            }
        } elseif ($fracX === 0) {
            $halfV = $this->lumaVerticalLowpass($refPlane, $refStride, $refWidth, $refHeight, $intX, $intY - 2, $blockW, $blockH + 4);
            $srcOffY = $fracY === 3 ? 1 : 0;
            for ($j = 0; $j < $blockH; $j++) {
                $sy = $intY + $j + $srcOffY;
                $sy = $sy < 0 ? 0 : ($sy > $maxY ? $maxY : $sy);
                $row = $sy * $refStride;
                $halfBase = ($j + 2) * $blockW;
                for ($i = 0; $i < $blockW; $i++) {
                    if ($fracY === 2) {
                        $pred[$j][$i] = $halfV[$halfBase + $i];
                    } else {
                        $sx = $intX + $i;
                        $sx = $sx < 0 ? 0 : ($sx > $maxX ? $maxX : $sx);
                        $pred[$j][$i] = ($refPlane[$row + $sx] + $halfV[$halfBase + $i] + 1) >> 1;
                    }
                }
            }
        } elseif ($fracX === 2 && $fracY === 2) {
            $halfHV = $this->lumaHvLowpass($refPlane, $refStride, $refWidth, $refHeight, $intX, $intY, $blockW, $blockH);
            for ($j = 0; $j < $blockH; $j++) {
                $base = $j * $blockW;
                for ($i = 0; $i < $blockW; $i++) {
                    $pred[$j][$i] = $halfHV[$base + $i];
                }
            }
        } elseif ($fracX === 2) {
            $halfH = $this->lumaHorizontalLowpass($refPlane, $refStride, $refWidth, $refHeight, $intX, $intY + ($fracY === 1 ? 0 : 1), $blockW, $blockH);
            $halfHV = $this->lumaHvLowpass($refPlane, $refStride, $refWidth, $refHeight, $intX, $intY, $blockW, $blockH);
            for ($j = 0; $j < $blockH; $j++) {
                $base = $j * $blockW;
                for ($i = 0; $i < $blockW; $i++) {
                    $pred[$j][$i] = ($halfH[$base + $i] + $halfHV[$base + $i] + 1) >> 1;
                }
            }
        } elseif ($fracY === 2) {
            $halfV = $this->lumaVerticalLowpass($refPlane, $refStride, $refWidth, $refHeight, $intX + ($fracX === 1 ? 0 : 1), $intY - 2, $blockW, $blockH + 4);
            $halfHV = $this->lumaHvLowpass($refPlane, $refStride, $refWidth, $refHeight, $intX, $intY, $blockW, $blockH);
            for ($j = 0; $j < $blockH; $j++) {
                $base = $j * $blockW;
                $vBase = ($j + 2) * $blockW;
                for ($i = 0; $i < $blockW; $i++) {
                    $pred[$j][$i] = ($halfV[$vBase + $i] + $halfHV[$base + $i] + 1) >> 1;
                }
            }
        } else {
            $halfH = $this->lumaHorizontalLowpass($refPlane, $refStride, $refWidth, $refHeight, $intX, $intY + ($fracY === 3 ? 1 : 0), $blockW, $blockH);
            $halfV = $this->lumaVerticalLowpass($refPlane, $refStride, $refWidth, $refHeight, $intX + ($fracX === 3 ? 1 : 0), $intY - 2, $blockW, $blockH + 4);
            for ($j = 0; $j < $blockH; $j++) {
                $base = $j * $blockW;
                $vBase = ($j + 2) * $blockW;
                for ($i = 0; $i < $blockW; $i++) {
                    $pred[$j][$i] = ($halfH[$base + $i] + $halfV[$vBase + $i] + 1) >> 1;
                }
            }
        }

        return $pred;
    }

    private function lumaHorizontalLowpass(array &$refPlane, int $stride, int $width, int $height, int $srcX, int $srcY, int $w, int $h): array
    {
        $maxX = $width - 1;
        $maxY = $height - 1;
        $taps = array_fill(0, 6, []);
        for ($i = 0; $i < $w; $i++) {
            for ($tap = 0; $tap < 6; $tap++) {
                $sx = $srcX + $i + $tap - 2;
                $taps[$tap][$i] = $sx < 0 ? 0 : ($sx > $maxX ? $maxX : $sx);
            }
        }
        $out = array_fill(0, $w * $h, 0);
        for ($j = 0; $j < $h; $j++) {
            $sy = $srcY + $j;
            $sy = $sy < 0 ? 0 : ($sy > $maxY ? $maxY : $sy);
            $row = $sy * $stride;
            $base = $j * $w;
            for ($i = 0; $i < $w; $i++) {
                $val = $refPlane[$row + $taps[0][$i]] - 5 * $refPlane[$row + $taps[1][$i]]
                    + 20 * $refPlane[$row + $taps[2][$i]] + 20 * $refPlane[$row + $taps[3][$i]]
                    - 5 * $refPlane[$row + $taps[4][$i]] + $refPlane[$row + $taps[5][$i]];
                $val = ($val + 16) >> 5;
                $out[$base + $i] = $val < 0 ? 0 : ($val > 255 ? 255 : $val);
            }
        }
        return $out;
    }

    private function lumaVerticalLowpass(array &$refPlane, int $stride, int $width, int $height, int $srcX, int $srcY, int $w, int $h): array
    {
        $maxX = $width - 1;
        $maxY = $height - 1;
        $xIndex = [];
        for ($i = 0; $i < $w; $i++) {
            $sx = $srcX + $i;
            $xIndex[$i] = $sx < 0 ? 0 : ($sx > $maxX ? $maxX : $sx);
        }
        $out = array_fill(0, $w * $h, 0);
        for ($j = 0; $j < $h; $j++) {
            $rows = [];
            for ($tap = 0; $tap < 6; $tap++) {
                $sy = $srcY + $j + $tap - 2;
                $sy = $sy < 0 ? 0 : ($sy > $maxY ? $maxY : $sy);
                $rows[$tap] = $sy * $stride;
            }
            $base = $j * $w;
            for ($i = 0; $i < $w; $i++) {
                $sx = $xIndex[$i];
                $val = $refPlane[$rows[0] + $sx] - 5 * $refPlane[$rows[1] + $sx]
                    + 20 * $refPlane[$rows[2] + $sx] + 20 * $refPlane[$rows[3] + $sx]
                    - 5 * $refPlane[$rows[4] + $sx] + $refPlane[$rows[5] + $sx];
                $val = ($val + 16) >> 5;
                $out[$base + $i] = $val < 0 ? 0 : ($val > 255 ? 255 : $val);
            }
        }
        return $out;
    }

    private function lumaHvLowpass(array &$refPlane, int $stride, int $width, int $height, int $srcX, int $srcY, int $w, int $h): array
    {
        $maxX = $width - 1;
        $maxY = $height - 1;
        $taps = array_fill(0, 6, []);
        for ($i = 0; $i < $w; $i++) {
            for ($tap = 0; $tap < 6; $tap++) {
                $sx = $srcX + $i + $tap - 2;
                $taps[$tap][$i] = $sx < 0 ? 0 : ($sx > $maxX ? $maxX : $sx);
            }
        }
        $tmpH = $h + 5;
        $tmp = array_fill(0, $tmpH * $w, 0);
        for ($j = 0; $j < $tmpH; $j++) {
            $sy = $srcY - 2 + $j;
            $sy = $sy < 0 ? 0 : ($sy > $maxY ? $maxY : $sy);
            $row = $sy * $stride;
            $base = $j * $w;
            for ($i = 0; $i < $w; $i++) {
                $tmp[$base + $i] = $refPlane[$row + $taps[0][$i]] - 5 * $refPlane[$row + $taps[1][$i]]
                    + 20 * $refPlane[$row + $taps[2][$i]] + 20 * $refPlane[$row + $taps[3][$i]]
                    - 5 * $refPlane[$row + $taps[4][$i]] + $refPlane[$row + $taps[5][$i]];
            }
        }
        $out = array_fill(0, $h * $w, 0);
        for ($j = 0; $j < $h; $j++) {
            $base = $j * $w;
            for ($i = 0; $i < $w; $i++) {
                $val = $tmp[$base + $i] - 5 * $tmp[$base + $w + $i]
                    + 20 * $tmp[$base + 2 * $w + $i] + 20 * $tmp[$base + 3 * $w + $i]
                    - 5 * $tmp[$base + 4 * $w + $i] + $tmp[$base + 5 * $w + $i];
                $val = ($val + 512) >> 10;
                $out[$base + $i] = $val < 0 ? 0 : ($val > 255 ? 255 : $val);
            }
        }
        return $out;
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
        $maxX = $refWidth - 1;
        $maxY = $refHeight - 1;

        $x0 = $x1 = [];
        for ($i = 0; $i < $blockW; $i++) {
            $sx = $intX + $i;
            $x0[$i] = $sx < 0 ? 0 : ($sx > $maxX ? $maxX : $sx);
            $sx++;
            $x1[$i] = $sx < 0 ? 0 : ($sx > $maxX ? $maxX : $sx);
        }

        if ($fracX === 0 && $fracY === 0) {
            for ($j = 0; $j < $blockH; $j++) {
                $sy = $intY + $j;
                $sy = $sy < 0 ? 0 : ($sy > $maxY ? $maxY : $sy);
                $row = $sy * $refStride;
                for ($i = 0; $i < $blockW; $i++) {
                    $pred[$j][$i] = $refPlane[$row + $x0[$i]];
                }
            }
        } else {
            $wx0 = 8 - $fracX;
            $wy0 = 8 - $fracY;
            $w00 = $wx0 * $wy0;
            $w10 = $fracX * $wy0;
            $w01 = $wx0 * $fracY;
            $w11 = $fracX * $fracY;
            for ($j = 0; $j < $blockH; $j++) {
                $sy0 = $intY + $j;
                $sy0 = $sy0 < 0 ? 0 : ($sy0 > $maxY ? $maxY : $sy0);
                $sy1 = $intY + $j + 1;
                $sy1 = $sy1 < 0 ? 0 : ($sy1 > $maxY ? $maxY : $sy1);
                $row0 = $sy0 * $refStride;
                $row1 = $sy1 * $refStride;
                for ($i = 0; $i < $blockW; $i++) {
                    $val = ($w00 * $refPlane[$row0 + $x0[$i]] +
                            $w10 * $refPlane[$row0 + $x1[$i]] +
                            $w01 * $refPlane[$row1 + $x0[$i]] +
                            $w11 * $refPlane[$row1 + $x1[$i]] + 32) >> 6;
                    $pred[$j][$i] = $this->clip255($val);
                }
            }
        }

        return $pred;
    }

    private function mcChromaPair(
        array &$uPlane,
        array &$vPlane,
        int $stride,
        int $width,
        int $height,
        int $x,
        int $y,
        int $blockW,
        int $blockH
    ): array {
        $uPred = array_fill(0, $blockH, array_fill(0, $blockW, 0));
        $vPred = array_fill(0, $blockH, array_fill(0, $blockW, 0));

        $fracX = $x & 7;
        $fracY = $y & 7;
        $intX = $x >> 3;
        $intY = $y >> 3;
        $maxX = $width - 1;
        $maxY = $height - 1;

        $x0 = [];
        $x1 = [];
        for ($i = 0; $i < $blockW; $i++) {
            $sx = $intX + $i;
            $x0[$i] = $sx < 0 ? 0 : ($sx > $maxX ? $maxX : $sx);
            $sx++;
            $x1[$i] = $sx < 0 ? 0 : ($sx > $maxX ? $maxX : $sx);
        }

        $hasFraction = $fracX !== 0 || $fracY !== 0;
        if ($hasFraction) {
            $wx0 = 8 - $fracX;
            $wy0 = 8 - $fracY;
            $w00 = $wx0 * $wy0;
            $w10 = $fracX * $wy0;
            $w01 = $wx0 * $fracY;
            $w11 = $fracX * $fracY;
        }

        for ($j = 0; $j < $blockH; $j++) {
            $sy0 = $intY + $j;
            $sy0 = $sy0 < 0 ? 0 : ($sy0 > $maxY ? $maxY : $sy0);
            $row0 = $sy0 * $stride;
            if ($hasFraction) {
                $sy1 = $intY + $j + 1;
                $sy1 = $sy1 < 0 ? 0 : ($sy1 > $maxY ? $maxY : $sy1);
                $row1 = $sy1 * $stride;
            }
            for ($i = 0; $i < $blockW; $i++) {
                $x0i = $x0[$i];
                $x1i = $x1[$i];
                if (!$hasFraction) {
                    $uPred[$j][$i] = $uPlane[$row0 + $x0i];
                    $vPred[$j][$i] = $vPlane[$row0 + $x0i];
                    continue;
                }

                $uVal = ($w00 * $uPlane[$row0 + $x0i]
                    + $w10 * $uPlane[$row0 + $x1i]
                    + $w01 * $uPlane[$row1 + $x0i]
                    + $w11 * $uPlane[$row1 + $x1i] + 32) >> 6;
                $vVal = ($w00 * $vPlane[$row0 + $x0i]
                    + $w10 * $vPlane[$row0 + $x1i]
                    + $w01 * $vPlane[$row1 + $x0i]
                    + $w11 * $vPlane[$row1 + $x1i] + 32) >> 6;
                $uPred[$j][$i] = $uVal < 0 ? 0 : ($uVal > 255 ? 255 : $uVal);
                $vPred[$j][$i] = $vVal < 0 ? 0 : ($vVal > 255 ? 255 : $vVal);
            }
        }

        return [$uPred, $vPred];
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
