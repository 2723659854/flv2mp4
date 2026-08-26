<?php

namespace Xiaosongshu\Flv2mp4\Codec\Decode;

/**
 * @purpose 亮度色度运动补偿
 * @author yanglong
 * @time 2026年7月23日15:14:28
 */
trait MotionCompensationTrait
{
    public function mcLuma(string $refPlane, int $refStride, int $refWidth, int $refHeight, int $x, int $y, int $blockW, int $blockH): array
    {
        $buffer = str_repeat("\0", $blockW * $blockH);
        $this->mcLumaTo($refPlane, $refStride, $refWidth, $refHeight, $x, $y, $blockW, $blockH, $buffer, $blockW, 0, 0);
        $pred = [];
        for ($j = 0; $j < $blockH; $j++) {
            $row = [];
            $base = $j * $blockW;
            for ($i = 0; $i < $blockW; $i++) {
                $row[$i] = ord($buffer[$base + $i]);
            }
            $pred[$j] = $row;
        }
        return $pred;
    }

    private function mcLumaTo(
        string $refPlane,
        int $refStride,
        int $refWidth,
        int $refHeight,
        int $x,
        int $y,
        int $blockW,
        int $blockH,
        string &$dstPlane,
        int $dstStride,
        int $dstX,
        int $dstY,
        ?array $refBytes = null
    ): void {
        $fracX = $x & 3;
        $fracY = $y & 3;
        $intX = $x >> 2;
        $intY = $y >> 2;
        $maxX = $refWidth - 1;
        $maxY = $refHeight - 1;

        if ($fracX === 0 && $fracY === 0) {
            if ($intX >= 0 && $intY >= 0 && $intX + $blockW <= $refWidth && $intY + $blockH <= $refHeight) {
                for ($j = 0; $j < $blockH; $j++) {
                    $srcBase = ($intY + $j) * $refStride + $intX;
                    $dstBase = ($dstY + $j) * $dstStride + $dstX;
                    for ($i = 0; $i < $blockW; $i++) {
                        $dstPlane[$dstBase + $i] = $refPlane[$srcBase + $i];
                    }
                }
                return;
            }

            $xIndex = [];
            for ($i = 0; $i < $blockW; $i++) {
                $sx = $intX + $i;
                $xIndex[$i] = $sx < 0 ? 0 : ($sx > $maxX ? $maxX : $sx);
            }
            for ($j = 0; $j < $blockH; $j++) {
                $sy = $intY + $j;
                $sy = $sy < 0 ? 0 : ($sy > $maxY ? $maxY : $sy);
                $srcBase = $sy * $refStride;
                $dstBase = ($dstY + $j) * $dstStride + $dstX;
                for ($i = 0; $i < $blockW; $i++) {
                    $dstPlane[$dstBase + $i] = $refPlane[$srcBase + $xIndex[$i]];
                }
            }
            return;
        }

        if ($refBytes === null) {
            $refBytes = array_values(unpack('C*', $refPlane));
        }

        if ($fracY === 0) {
            $srcOffX = $fracX === 3 ? 1 : 0;
            $interiorX = $intX >= 2 && $intX + $blockW + 2 <= $maxX;
            for ($j = 0; $j < $blockH; $j++) {
                $sy = $intY + $j;
                $sy = $sy < 0 ? 0 : ($sy > $maxY ? $maxY : $sy);
                $srcBase = $sy * $refStride;
                $dstBase = ($dstY + $j) * $dstStride + $dstX;
                for ($i = 0; $i < $blockW; $i++) {
                    $sx = $intX + $i;
                    if ($interiorX) {
                        $val = $refBytes[$srcBase + $sx - 2] - 5 * $refBytes[$srcBase + $sx - 1]
                            + 20 * $refBytes[$srcBase + $sx] + 20 * $refBytes[$srcBase + $sx + 1]
                            - 5 * $refBytes[$srcBase + $sx + 2] + $refBytes[$srcBase + $sx + 3];
                    } else {
                        $x0 = $sx - 2 < 0 ? 0 : ($sx - 2 > $maxX ? $maxX : $sx - 2);
                        $x1 = $sx - 1 < 0 ? 0 : ($sx - 1 > $maxX ? $maxX : $sx - 1);
                        $x2 = $sx < 0 ? 0 : ($sx > $maxX ? $maxX : $sx);
                        $x3 = $sx + 1 < 0 ? 0 : ($sx + 1 > $maxX ? $maxX : $sx + 1);
                        $x4 = $sx + 2 < 0 ? 0 : ($sx + 2 > $maxX ? $maxX : $sx + 2);
                        $x5 = $sx + 3 < 0 ? 0 : ($sx + 3 > $maxX ? $maxX : $sx + 3);
                        $val = $refBytes[$srcBase + $x0] - 5 * $refBytes[$srcBase + $x1]
                            + 20 * $refBytes[$srcBase + $x2] + 20 * $refBytes[$srcBase + $x3]
                            - 5 * $refBytes[$srcBase + $x4] + $refBytes[$srcBase + $x5];
                    }
                    $val = ($val + 16) >> 5;
                    $val = $val < 0 ? 0 : ($val > 255 ? 255 : $val);
                    if ($fracX !== 2) {
                        $sx += $srcOffX;
                        if (!$interiorX) {
                            $sx = $sx < 0 ? 0 : ($sx > $maxX ? $maxX : $sx);
                        }
                        $val = ($refBytes[$srcBase + $sx] + $val + 1) >> 1;
                    }
                    $dstPlane[$dstBase + $i] = chr($val);
                }
            }
            return;
        }

        if ($fracX === 0) {
            $srcOffY = $fracY === 3 ? 1 : 0;
            $interiorX = $intX >= 0 && $intX + $blockW <= $refWidth;
            for ($j = 0; $j < $blockH; $j++) {
                $sy = $intY + $j;
                $y0 = $sy - 2 < 0 ? 0 : ($sy - 2 > $maxY ? $maxY : $sy - 2);
                $y1 = $sy - 1 < 0 ? 0 : ($sy - 1 > $maxY ? $maxY : $sy - 1);
                $y2 = $sy < 0 ? 0 : ($sy > $maxY ? $maxY : $sy);
                $y3 = $sy + 1 < 0 ? 0 : ($sy + 1 > $maxY ? $maxY : $sy + 1);
                $y4 = $sy + 2 < 0 ? 0 : ($sy + 2 > $maxY ? $maxY : $sy + 2);
                $y5 = $sy + 3 < 0 ? 0 : ($sy + 3 > $maxY ? $maxY : $sy + 3);
                $row0 = $y0 * $refStride;
                $row1 = $y1 * $refStride;
                $row2 = $y2 * $refStride;
                $row3 = $y3 * $refStride;
                $row4 = $y4 * $refStride;
                $row5 = $y5 * $refStride;
                $srcY = $sy + $srcOffY;
                $srcY = $srcY < 0 ? 0 : ($srcY > $maxY ? $maxY : $srcY);
                $srcBase = $srcY * $refStride;
                $dstBase = ($dstY + $j) * $dstStride + $dstX;
                for ($i = 0; $i < $blockW; $i++) {
                    $sx = $intX + $i;
                    if (!$interiorX) {
                        $sx = $sx < 0 ? 0 : ($sx > $maxX ? $maxX : $sx);
                    }
                    $val = $refBytes[$row0 + $sx] - 5 * $refBytes[$row1 + $sx]
                        + 20 * $refBytes[$row2 + $sx] + 20 * $refBytes[$row3 + $sx]
                        - 5 * $refBytes[$row4 + $sx] + $refBytes[$row5 + $sx];
                    $val = ($val + 16) >> 5;
                    $val = $val < 0 ? 0 : ($val > 255 ? 255 : $val);
                    if ($fracY !== 2) {
                        $val = ($refBytes[$srcBase + $sx] + $val + 1) >> 1;
                    }
                    $dstPlane[$dstBase + $i] = chr($val);
                }
            }
            return;
        }

        $firstOffset = 0;
        if ($fracX === 2 && $fracY === 2) {
            $first = $this->lumaHvLowpass($refBytes, $refStride, $refWidth, $refHeight, $intX, $intY, $blockW, $blockH);
            $second = null;
            $secondOffset = 0;
        } elseif ($fracX === 2) {
            $first = $this->lumaHorizontalLowpass($refBytes, $refStride, $refWidth, $refHeight, $intX, $intY + ($fracY === 1 ? 0 : 1), $blockW, $blockH);
            $second = $this->lumaHvLowpass($refBytes, $refStride, $refWidth, $refHeight, $intX, $intY, $blockW, $blockH);
            $secondOffset = 0;
        } elseif ($fracY === 2) {
            $first = $this->lumaVerticalLowpass($refBytes, $refStride, $refWidth, $refHeight, $intX + ($fracX === 1 ? 0 : 1), $intY - 2, $blockW, $blockH + 4);
            $firstOffset = 2 * $blockW;
            $second = $this->lumaHvLowpass($refBytes, $refStride, $refWidth, $refHeight, $intX, $intY, $blockW, $blockH);
            $secondOffset = 0;
        } else {
            $horizontalY = $intY + ($fracY === 3 ? 1 : 0);
            $verticalX = $intX + ($fracX === 3 ? 1 : 0);
            $interiorHorizontalX = $intX >= 2 && $intX + $blockW + 2 <= $maxX;
            $interiorVerticalX = $verticalX >= 0 && $verticalX + $blockW <= $refWidth;
            for ($j = 0; $j < $blockH; $j++) {
                $horizontalSrcY = $horizontalY + $j;
                $horizontalSrcY = $horizontalSrcY < 0 ? 0 : ($horizontalSrcY > $maxY ? $maxY : $horizontalSrcY);
                $horizontalRow = $horizontalSrcY * $refStride;

                $verticalSrcY = $intY + $j;
                $y0 = $verticalSrcY - 2 < 0 ? 0 : ($verticalSrcY - 2 > $maxY ? $maxY : $verticalSrcY - 2);
                $y1 = $verticalSrcY - 1 < 0 ? 0 : ($verticalSrcY - 1 > $maxY ? $maxY : $verticalSrcY - 1);
                $y2 = $verticalSrcY < 0 ? 0 : ($verticalSrcY > $maxY ? $maxY : $verticalSrcY);
                $y3 = $verticalSrcY + 1 < 0 ? 0 : ($verticalSrcY + 1 > $maxY ? $maxY : $verticalSrcY + 1);
                $y4 = $verticalSrcY + 2 < 0 ? 0 : ($verticalSrcY + 2 > $maxY ? $maxY : $verticalSrcY + 2);
                $y5 = $verticalSrcY + 3 < 0 ? 0 : ($verticalSrcY + 3 > $maxY ? $maxY : $verticalSrcY + 3);
                $row0 = $y0 * $refStride;
                $row1 = $y1 * $refStride;
                $row2 = $y2 * $refStride;
                $row3 = $y3 * $refStride;
                $row4 = $y4 * $refStride;
                $row5 = $y5 * $refStride;
                $dstBase = ($dstY + $j) * $dstStride + $dstX;

                for ($i = 0; $i < $blockW; $i++) {
                    $horizontalSrcX = $intX + $i;
                    if ($interiorHorizontalX) {
                        $horizontal = $refBytes[$horizontalRow + $horizontalSrcX - 2] - 5 * $refBytes[$horizontalRow + $horizontalSrcX - 1]
                            + 20 * $refBytes[$horizontalRow + $horizontalSrcX] + 20 * $refBytes[$horizontalRow + $horizontalSrcX + 1]
                            - 5 * $refBytes[$horizontalRow + $horizontalSrcX + 2] + $refBytes[$horizontalRow + $horizontalSrcX + 3];
                    } else {
                        $x0 = $horizontalSrcX - 2 < 0 ? 0 : ($horizontalSrcX - 2 > $maxX ? $maxX : $horizontalSrcX - 2);
                        $x1 = $horizontalSrcX - 1 < 0 ? 0 : ($horizontalSrcX - 1 > $maxX ? $maxX : $horizontalSrcX - 1);
                        $x2 = $horizontalSrcX < 0 ? 0 : ($horizontalSrcX > $maxX ? $maxX : $horizontalSrcX);
                        $x3 = $horizontalSrcX + 1 < 0 ? 0 : ($horizontalSrcX + 1 > $maxX ? $maxX : $horizontalSrcX + 1);
                        $x4 = $horizontalSrcX + 2 < 0 ? 0 : ($horizontalSrcX + 2 > $maxX ? $maxX : $horizontalSrcX + 2);
                        $x5 = $horizontalSrcX + 3 < 0 ? 0 : ($horizontalSrcX + 3 > $maxX ? $maxX : $horizontalSrcX + 3);
                        $horizontal = $refBytes[$horizontalRow + $x0] - 5 * $refBytes[$horizontalRow + $x1]
                            + 20 * $refBytes[$horizontalRow + $x2] + 20 * $refBytes[$horizontalRow + $x3]
                            - 5 * $refBytes[$horizontalRow + $x4] + $refBytes[$horizontalRow + $x5];
                    }
                    $horizontal = ($horizontal + 16) >> 5;
                    $horizontal = $horizontal < 0 ? 0 : ($horizontal > 255 ? 255 : $horizontal);

                    $verticalSrcX = $verticalX + $i;
                    if (!$interiorVerticalX) {
                        $verticalSrcX = $verticalSrcX < 0 ? 0 : ($verticalSrcX > $maxX ? $maxX : $verticalSrcX);
                    }
                    $vertical = $refBytes[$row0 + $verticalSrcX] - 5 * $refBytes[$row1 + $verticalSrcX]
                        + 20 * $refBytes[$row2 + $verticalSrcX] + 20 * $refBytes[$row3 + $verticalSrcX]
                        - 5 * $refBytes[$row4 + $verticalSrcX] + $refBytes[$row5 + $verticalSrcX];
                    $vertical = ($vertical + 16) >> 5;
                    $vertical = $vertical < 0 ? 0 : ($vertical > 255 ? 255 : $vertical);

                    $dstPlane[$dstBase + $i] = chr(($horizontal + $vertical + 1) >> 1);
                }
            }
            return;
        }

        for ($j = 0; $j < $blockH; $j++) {
            $base = $j * $blockW;
            $dstBase = ($dstY + $j) * $dstStride + $dstX;
            for ($i = 0; $i < $blockW; $i++) {
                $val = $first[$base + $firstOffset + $i];
                if ($second !== null) {
                    $val = ($val + $second[$base + $secondOffset + $i] + 1) >> 1;
                }
                $dstPlane[$dstBase + $i] = chr($val);
            }
        }
    }

    private function lumaHorizontalLowpass(array $refBytes, int $stride, int $width, int $height, int $srcX, int $srcY, int $w, int $h): array
    {
        $maxX = $width - 1;
        $maxY = $height - 1;
        $out = [];
        if ($srcX >= 2 && $srcX + $w + 2 <= $maxX) {
            for ($j = 0; $j < $h; $j++) {
                $sy = $srcY + $j;
                $sy = $sy < 0 ? 0 : ($sy > $maxY ? $maxY : $sy);
                $row = $sy * $stride + $srcX;
                for ($i = 0; $i < $w; $i++) {
                    $val = $refBytes[$row + $i - 2] - 5 * $refBytes[$row + $i - 1]
                        + 20 * $refBytes[$row + $i] + 20 * $refBytes[$row + $i + 1]
                        - 5 * $refBytes[$row + $i + 2] + $refBytes[$row + $i + 3];
                    $val = ($val + 16) >> 5;
                    $out[] = $val < 0 ? 0 : ($val > 255 ? 255 : $val);
                }
            }
            return $out;
        }

        for ($j = 0; $j < $h; $j++) {
            $sy = $srcY + $j;
            $sy = $sy < 0 ? 0 : ($sy > $maxY ? $maxY : $sy);
            $row = $sy * $stride;
            for ($i = 0; $i < $w; $i++) {
                $sx = $srcX + $i;
                $x0 = $sx - 2 < 0 ? 0 : ($sx - 2 > $maxX ? $maxX : $sx - 2);
                $x1 = $sx - 1 < 0 ? 0 : ($sx - 1 > $maxX ? $maxX : $sx - 1);
                $x2 = $sx < 0 ? 0 : ($sx > $maxX ? $maxX : $sx);
                $x3 = $sx + 1 < 0 ? 0 : ($sx + 1 > $maxX ? $maxX : $sx + 1);
                $x4 = $sx + 2 < 0 ? 0 : ($sx + 2 > $maxX ? $maxX : $sx + 2);
                $x5 = $sx + 3 < 0 ? 0 : ($sx + 3 > $maxX ? $maxX : $sx + 3);
                $val = $refBytes[$row + $x0] - 5 * $refBytes[$row + $x1]
                    + 20 * $refBytes[$row + $x2] + 20 * $refBytes[$row + $x3]
                    - 5 * $refBytes[$row + $x4] + $refBytes[$row + $x5];
                $val = ($val + 16) >> 5;
                $out[] = $val < 0 ? 0 : ($val > 255 ? 255 : $val);
            }
        }
        return $out;
    }

    private function lumaVerticalLowpass(array $refBytes, int $stride, int $width, int $height, int $srcX, int $srcY, int $w, int $h): array
    {
        $maxX = $width - 1;
        $maxY = $height - 1;
        $interiorX = $srcX >= 0 && $srcX + $w <= $width;
        $out = [];
        for ($j = 0; $j < $h; $j++) {
            $sy = $srcY + $j;
            $y0 = $sy - 2 < 0 ? 0 : ($sy - 2 > $maxY ? $maxY : $sy - 2);
            $y1 = $sy - 1 < 0 ? 0 : ($sy - 1 > $maxY ? $maxY : $sy - 1);
            $y2 = $sy < 0 ? 0 : ($sy > $maxY ? $maxY : $sy);
            $y3 = $sy + 1 < 0 ? 0 : ($sy + 1 > $maxY ? $maxY : $sy + 1);
            $y4 = $sy + 2 < 0 ? 0 : ($sy + 2 > $maxY ? $maxY : $sy + 2);
            $y5 = $sy + 3 < 0 ? 0 : ($sy + 3 > $maxY ? $maxY : $sy + 3);
            $row0 = $y0 * $stride;
            $row1 = $y1 * $stride;
            $row2 = $y2 * $stride;
            $row3 = $y3 * $stride;
            $row4 = $y4 * $stride;
            $row5 = $y5 * $stride;
            for ($i = 0; $i < $w; $i++) {
                $sx = $srcX + $i;
                if (!$interiorX) {
                    $sx = $sx < 0 ? 0 : ($sx > $maxX ? $maxX : $sx);
                }
                $val = $refBytes[$row0 + $sx] - 5 * $refBytes[$row1 + $sx]
                    + 20 * $refBytes[$row2 + $sx] + 20 * $refBytes[$row3 + $sx]
                    - 5 * $refBytes[$row4 + $sx] + $refBytes[$row5 + $sx];
                $val = ($val + 16) >> 5;
                $out[] = $val < 0 ? 0 : ($val > 255 ? 255 : $val);
            }
        }
        return $out;
    }

    private function lumaHvLowpass(array $refBytes, int $stride, int $width, int $height, int $srcX, int $srcY, int $w, int $h): array
    {
        $maxX = $width - 1;
        $maxY = $height - 1;
        $tmpH = $h + 5;
        $tmp = [];
        $interiorX = $srcX >= 2 && $srcX + $w + 2 <= $maxX;
        for ($j = 0; $j < $tmpH; $j++) {
            $sy = $srcY - 2 + $j;
            $sy = $sy < 0 ? 0 : ($sy > $maxY ? $maxY : $sy);
            $row = $sy * $stride;
            for ($i = 0; $i < $w; $i++) {
                $sx = $srcX + $i;
                if ($interiorX) {
                    $val = $refBytes[$row + $sx - 2] - 5 * $refBytes[$row + $sx - 1]
                        + 20 * $refBytes[$row + $sx] + 20 * $refBytes[$row + $sx + 1]
                        - 5 * $refBytes[$row + $sx + 2] + $refBytes[$row + $sx + 3];
                } else {
                    $x0 = $sx - 2 < 0 ? 0 : ($sx - 2 > $maxX ? $maxX : $sx - 2);
                    $x1 = $sx - 1 < 0 ? 0 : ($sx - 1 > $maxX ? $maxX : $sx - 1);
                    $x2 = $sx < 0 ? 0 : ($sx > $maxX ? $maxX : $sx);
                    $x3 = $sx + 1 < 0 ? 0 : ($sx + 1 > $maxX ? $maxX : $sx + 1);
                    $x4 = $sx + 2 < 0 ? 0 : ($sx + 2 > $maxX ? $maxX : $sx + 2);
                    $x5 = $sx + 3 < 0 ? 0 : ($sx + 3 > $maxX ? $maxX : $sx + 3);
                    $val = $refBytes[$row + $x0] - 5 * $refBytes[$row + $x1]
                        + 20 * $refBytes[$row + $x2] + 20 * $refBytes[$row + $x3]
                        - 5 * $refBytes[$row + $x4] + $refBytes[$row + $x5];
                }
                $tmp[] = $val;
            }
        }
        $out = [];
        for ($j = 0; $j < $h; $j++) {
            $base = $j * $w;
            for ($i = 0; $i < $w; $i++) {
                $val = $tmp[$base + $i] - 5 * $tmp[$base + $w + $i]
                    + 20 * $tmp[$base + 2 * $w + $i] + 20 * $tmp[$base + 3 * $w + $i]
                    - 5 * $tmp[$base + 4 * $w + $i] + $tmp[$base + 5 * $w + $i];
                $val = ($val + 512) >> 10;
                $out[] = $val < 0 ? 0 : ($val > 255 ? 255 : $val);
            }
        }
        return $out;
    }

    private function mcChromaPairTo(
        string $uPlane,
        string $vPlane,
        int $stride,
        int $width,
        int $height,
        int $x,
        int $y,
        int $blockW,
        int $blockH,
        string &$dstU,
        string &$dstV,
        int $dstStride,
        int $dstX,
        int $dstY,
        ?array $uBytes = null,
        ?array $vBytes = null
    ): void {
        $fracX = $x & 7;
        $fracY = $y & 7;
        $intX = $x >> 3;
        $intY = $y >> 3;
        $maxX = $width - 1;
        $maxY = $height - 1;

        if ($fracX === 0 && $fracY === 0 && $intX >= 0 && $intY >= 0 && $intX + $blockW <= $width && $intY + $blockH <= $height) {
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

        $hasFraction = $fracX !== 0 || $fracY !== 0;
        if ($hasFraction) {
            if ($uBytes === null) {
                $uBytes = array_values(unpack('C*', $uPlane));
                $vBytes = array_values(unpack('C*', $vPlane));
            }
            $wx0 = 8 - $fracX;
            $wy0 = 8 - $fracY;

            if ($intX >= 0 && $intY >= 0
                && $intX + $blockW + ($fracX !== 0 ? 1 : 0) <= $width
                && $intY + $blockH + ($fracY !== 0 ? 1 : 0) <= $height
            ) {
                if ($fracX !== 0 && $fracY !== 0) {
                    $w00 = $wx0 * $wy0;
                    $w10 = $fracX * $wy0;
                    $w01 = $wx0 * $fracY;
                    $w11 = $fracX * $fracY;
                }
                for ($j = 0; $j < $blockH; $j++) {
                    $row0 = ($intY + $j) * $stride + $intX;
                    $row1 = $row0 + $stride;
                    $dstBase = ($dstY + $j) * $dstStride + $dstX;
                    for ($i = 0; $i < $blockW; $i++) {
                        $src = $row0 + $i;
                        if ($fracY === 0) {
                            $uVal = ($wx0 * $uBytes[$src] + $fracX * $uBytes[$src + 1] + 4) >> 3;
                            $vVal = ($wx0 * $vBytes[$src] + $fracX * $vBytes[$src + 1] + 4) >> 3;
                        } elseif ($fracX === 0) {
                            $uVal = ($wy0 * $uBytes[$src] + $fracY * $uBytes[$row1 + $i] + 4) >> 3;
                            $vVal = ($wy0 * $vBytes[$src] + $fracY * $vBytes[$row1 + $i] + 4) >> 3;
                        } else {
                            $uVal = ($w00 * $uBytes[$src] + $w10 * $uBytes[$src + 1]
                                + $w01 * $uBytes[$row1 + $i] + $w11 * $uBytes[$row1 + $i + 1] + 32) >> 6;
                            $vVal = ($w00 * $vBytes[$src] + $w10 * $vBytes[$src + 1]
                                + $w01 * $vBytes[$row1 + $i] + $w11 * $vBytes[$row1 + $i + 1] + 32) >> 6;
                        }
                        $dstU[$dstBase + $i] = chr($uVal);
                        $dstV[$dstBase + $i] = chr($vVal);
                    }
                }
                return;
            }

            $w00 = $wx0 * $wy0;
            $w10 = $fracX * $wy0;
            $w01 = $wx0 * $fracY;
            $w11 = $fracX * $fracY;
        }

        $x0 = [];
        $x1 = [];
        for ($i = 0; $i < $blockW; $i++) {
            $sx = $intX + $i;
            $x0[$i] = $sx < 0 ? 0 : ($sx > $maxX ? $maxX : $sx);
            $sx++;
            $x1[$i] = $sx < 0 ? 0 : ($sx > $maxX ? $maxX : $sx);
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
            $dstBase = ($dstY + $j) * $dstStride + $dstX;
            for ($i = 0; $i < $blockW; $i++) {
                if (!$hasFraction) {
                    $dstU[$dstBase + $i] = $uPlane[$row0 + $x0[$i]];
                    $dstV[$dstBase + $i] = $vPlane[$row0 + $x0[$i]];
                    continue;
                }
                $uVal = ($w00 * $uBytes[$row0 + $x0[$i]]
                    + $w10 * $uBytes[$row0 + $x1[$i]]
                    + $w01 * $uBytes[$row1 + $x0[$i]]
                    + $w11 * $uBytes[$row1 + $x1[$i]] + 32) >> 6;
                $vVal = ($w00 * $vBytes[$row0 + $x0[$i]]
                    + $w10 * $vBytes[$row0 + $x1[$i]]
                    + $w01 * $vBytes[$row1 + $x0[$i]]
                    + $w11 * $vBytes[$row1 + $x1[$i]] + 32) >> 6;
                $dstU[$dstBase + $i] = chr($uVal < 0 ? 0 : ($uVal > 255 ? 255 : $uVal));
                $dstV[$dstBase + $i] = chr($vVal < 0 ? 0 : ($vVal > 255 ? 255 : $vVal));
            }
        }
    }
}
