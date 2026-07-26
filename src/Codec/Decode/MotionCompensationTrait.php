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

        $hRows = 0;
        $hStart = 0;
        if ($fracX !== 0) {
            if ($fracY === 0) {
                $hRows = $blockH;
                $hStart = 0;
            } else {
                $hRows = $blockH + 5;
                $hStart = -2;
            }
        }

        $vCols = 0;
        if ($fracY !== 0) {
            if ($fracX === 0) {
                $vCols = $blockW;
            } else {
                $vCols = $blockW + 1;
            }
        }

        $H = null;
        $Hfull = null;
        if ($fracX !== 0) {
            if ($fracY === 0) {
                $H = array_fill(0, $hRows, array_fill(0, $blockW, 0));
            } else {
                $Hfull = array_fill(0, $hRows, array_fill(0, $blockW, 0));
                $H = array_fill(0, $hRows, array_fill(0, $blockW, 0));
            }
            for ($j = $hStart; $j < $hStart + $hRows; $j++) {
                $ry = $this->clamp($intY + $j, 0, $refHeight - 1);
                for ($i = 0; $i < $blockW; $i++) {
                    $px0 = $refPlane[$ry * $refStride + $this->clamp($intX + $i - 2, 0, $refWidth - 1)];
                    $px1 = $refPlane[$ry * $refStride + $this->clamp($intX + $i - 1, 0, $refWidth - 1)];
                    $px2 = $refPlane[$ry * $refStride + $this->clamp($intX + $i, 0, $refWidth - 1)];
                    $px3 = $refPlane[$ry * $refStride + $this->clamp($intX + $i + 1, 0, $refWidth - 1)];
                    $px4 = $refPlane[$ry * $refStride + $this->clamp($intX + $i + 2, 0, $refWidth - 1)];
                    $px5 = $refPlane[$ry * $refStride + $this->clamp($intX + $i + 3, 0, $refWidth - 1)];
                    $fullVal = $px0 - 5 * $px1 + 20 * $px2 + 20 * $px3 - 5 * $px4 + $px5;
                    if ($fracY === 0) {
                        $h = ($fullVal + 16) >> 5;
                        $H[$j - $hStart][$i] = $this->clip255($h);
                    } else {
                        $Hfull[$j - $hStart][$i] = $fullVal;
                        $h = ($fullVal + 16) >> 5;
                        $H[$j - $hStart][$i] = $this->clip255($h);
                    }
                }
            }
        }

        $V = null;
        if ($fracY !== 0) {
            $V = array_fill(0, $blockH, array_fill(0, $vCols, 0));
            for ($j = 0; $j < $blockH; $j++) {
                for ($i = 0; $i < $vCols; $i++) {
                    $rx = $this->clamp($intX + $i, 0, $refWidth - 1);
                    $px0 = $refPlane[$this->clamp($intY + $j - 2, 0, $refHeight - 1) * $refStride + $rx];
                    $px1 = $refPlane[$this->clamp($intY + $j - 1, 0, $refHeight - 1) * $refStride + $rx];
                    $px2 = $refPlane[$this->clamp($intY + $j, 0, $refHeight - 1) * $refStride + $rx];
                    $px3 = $refPlane[$this->clamp($intY + $j + 1, 0, $refHeight - 1) * $refStride + $rx];
                    $px4 = $refPlane[$this->clamp($intY + $j + 2, 0, $refHeight - 1) * $refStride + $rx];
                    $px5 = $refPlane[$this->clamp($intY + $j + 3, 0, $refHeight - 1) * $refStride + $rx];
                    $h = ($px0 - 5 * $px1 + 20 * $px2 + 20 * $px3 - 5 * $px4 + $px5 + 16) >> 5;
                    $V[$j][$i] = $this->clip255($h);
                }
            }
        }

        $C = null;
        if ($fracX !== 0 && $fracY !== 0) {
            $C = array_fill(0, $blockH, array_fill(0, $blockW, 0));
            for ($j = 0; $j < $blockH; $j++) {
                for ($i = 0; $i < $blockW; $i++) {
                    $px0 = $Hfull[$j][$i];
                    $px1 = $Hfull[$j + 1][$i];
                    $px2 = $Hfull[$j + 2][$i];
                    $px3 = $Hfull[$j + 3][$i];
                    $px4 = $Hfull[$j + 4][$i];
                    $px5 = $Hfull[$j + 5][$i];
                    $fullVal = $px0 - 5 * $px1 + 20 * $px2 + 20 * $px3 - 5 * $px4 + $px5;
                    $h = ($fullVal + 512) >> 10;
                    $C[$j][$i] = $this->clip255($h);
                }
            }
        }

        $avg = function($a, $b) {
            return ($a + $b + 1) >> 1;
        };

        if ($fracY === 0) {
            for ($j = 0; $j < $blockH; $j++) {
                $ry = $this->clamp($intY + $j, 0, $refHeight - 1);
                for ($i = 0; $i < $blockW; $i++) {
                    if ($fracX === 1) {
                        $I = $refPlane[$ry * $refStride + $this->clamp($intX + $i, 0, $refWidth - 1)];
                        $pred[$j][$i] = $avg($I, $H[$j][$i]);
                    } elseif ($fracX === 2) {
                        $pred[$j][$i] = $H[$j][$i];
                    } else {
                        $I1 = $refPlane[$ry * $refStride + $this->clamp($intX + $i + 1, 0, $refWidth - 1)];
                        $pred[$j][$i] = $avg($H[$j][$i], $I1);
                    }
                }
            }
        } elseif ($fracX === 0) {
            for ($j = 0; $j < $blockH; $j++) {
                for ($i = 0; $i < $blockW; $i++) {
                    $rx = $this->clamp($intX + $i, 0, $refWidth - 1);
                    if ($fracY === 1) {
                        $I = $refPlane[$this->clamp($intY + $j, 0, $refHeight - 1) * $refStride + $rx];
                        $pred[$j][$i] = $avg($I, $V[$j][$i]);
                    } elseif ($fracY === 2) {
                        $pred[$j][$i] = $V[$j][$i];
                    } else {
                        $I_1 = $refPlane[$this->clamp($intY + $j + 1, 0, $refHeight - 1) * $refStride + $rx];
                        $pred[$j][$i] = $avg($V[$j][$i], $I_1);
                    }
                }
            }
        } elseif ($fracX === 2) {
            for ($j = 0; $j < $blockH; $j++) {
                for ($i = 0; $i < $blockW; $i++) {
                    if ($fracY === 1) {
                        $pred[$j][$i] = $avg($H[$j + 2][$i], $C[$j][$i]);
                    } elseif ($fracY === 2) {
                        $pred[$j][$i] = $C[$j][$i];
                    } else {
                        $pred[$j][$i] = $avg($C[$j][$i], $H[$j + 3][$i]);
                    }
                }
            }
        } elseif ($fracY === 2) {
            for ($j = 0; $j < $blockH; $j++) {
                for ($i = 0; $i < $blockW; $i++) {
                    if ($fracX === 1) {
                        $pred[$j][$i] = $avg($V[$j][$i], $C[$j][$i]);
                    } else {
                        $pred[$j][$i] = $avg($C[$j][$i], $V[$j][$i + 1]);
                    }
                }
            }
        } else {
            $hIdx = ($fracY === 1) ? 2 : 3;
            $vIdx = ($fracX === 3) ? 1 : 0;
            for ($j = 0; $j < $blockH; $j++) {
                for ($i = 0; $i < $blockW; $i++) {
                    $pred[$j][$i] = $avg($H[$j + $hIdx][$i], $V[$j][$i + $vIdx]);
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
