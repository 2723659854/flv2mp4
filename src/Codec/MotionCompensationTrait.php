<?php

namespace Xiaosongshu\Flv2mp4\Codec;

trait MotionCompensationTrait
{
    /**
     * 亮度运动补偿 - 1/4 像素精度
     * @param array $refPlane 参考帧平面
     * @param int $refStride 参考帧步长
     * @param int $refWidth 参考帧宽度
     * @param int $refHeight 参考帧高度
     * @param int $x 运动向量 x (1/4像素单位)
     * @param int $y 运动向量 y (1/4像素单位)
     * @param int $blockW 块宽度
     * @param int $blockH 块高度
     * @return array 预测块 (二维数组 [$y][$x])
     */
    public function mcLuma(array &$refPlane, int $refStride, int $refWidth, int $refHeight, int $x, int $y, int $blockW, int $blockH): array
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

        $tmp = array_fill(0, $blockH + 5, array_fill(0, $blockW, 0));

        if ($fracX !== 0) {
            $half = array_fill(0, $blockH + 5, array_fill(0, $blockW, 0));

            for ($j = -2; $j < $blockH + 3; $j++) {
                for ($i = 0; $i < $blockW; $i++) {
                    $rx = $this->clamp($intX + $i - 2, 0, $refWidth - 1);
                    $ry = $this->clamp($intY + $j, 0, $refHeight - 1);
                    $a = $refPlane[$ry * $refStride + $rx];
                    $rx = $this->clamp($intX + $i - 1, 0, $refWidth - 1);
                    $b = $refPlane[$ry * $refStride + $rx];
                    $rx = $this->clamp($intX + $i, 0, $refWidth - 1);
                    $c = $refPlane[$ry * $refStride + $rx];
                    $rx = $this->clamp($intX + $i + 1, 0, $refWidth - 1);
                    $d = $refPlane[$ry * $refStride + $rx];
                    $rx = $this->clamp($intX + $i + 2, 0, $refWidth - 1);
                    $e = $refPlane[$ry * $refStride + $rx];
                    $rx = $this->clamp($intX + $i + 3, 0, $refWidth - 1);
                    $f = $refPlane[$ry * $refStride + $rx];

                    $h = ($a - 5 * $b + 20 * $c + 20 * $d - 5 * $e + $f + 16) >> 5;
                    $h = $this->clip255($h);
                    $half[$j + 2][$i] = $h;

                    if ($fracX === 1) {
                        $tmp[$j + 2][$i] = ($c + $h + 1) >> 1;
                    } elseif ($fracX === 2) {
                        $tmp[$j + 2][$i] = $h;
                    } else {
                        $tmp[$j + 2][$i] = ($h + $d + 1) >> 1;
                    }
                    $tmp[$j + 2][$i] = $this->clip255($tmp[$j + 2][$i]);
                }
            }
        } else {
            for ($j = -2; $j < $blockH + 3; $j++) {
                for ($i = 0; $i < $blockW; $i++) {
                    $rx = $this->clamp($intX + $i, 0, $refWidth - 1);
                    $ry = $this->clamp($intY + $j, 0, $refHeight - 1);
                    $tmp[$j + 2][$i] = $refPlane[$ry * $refStride + $rx];
                }
            }
        }

        if ($fracY !== 0) {
            for ($j = 0; $j < $blockH; $j++) {
                for ($i = 0; $i < $blockW; $i++) {
                    $a = $tmp[$j][$i];
                    $b = $tmp[$j + 1][$i];
                    $c = $tmp[$j + 2][$i];
                    $d = $tmp[$j + 3][$i];
                    $e = $tmp[$j + 4][$i];
                    $f = $tmp[$j + 5][$i];

                    $h = ($a - 5 * $b + 20 * $c + 20 * $d - 5 * $e + $f + 16) >> 5;
                    $h = $this->clip255($h);

                    if ($fracY === 1) {
                        $val = ($c + $h + 1) >> 1;
                    } elseif ($fracY === 2) {
                        $val = $h;
                    } else {
                        $val = ($h + $d + 1) >> 1;
                    }
                    $pred[$j][$i] = $this->clip255($val);
                }
            }
        } else {
            for ($j = 0; $j < $blockH; $j++) {
                for ($i = 0; $i < $blockW; $i++) {
                    $pred[$j][$i] = $tmp[$j + 2][$i];
                }
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
