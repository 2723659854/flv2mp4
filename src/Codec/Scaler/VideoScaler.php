<?php

namespace Xiaosongshu\Flv2mp4\Codec\Scaler;

/**
 * @purpose 视频缩放器
 * @author yanglong
 * @time 2026年7月23日15:28:20
 */
class VideoScaler
{
    /**
     * 列映射缓存（同组分辨率每帧重复使用）：
     * key => [x0[], x1[], dx[]]，定点 16.16
     * @var array<string,array{array<int>,array<int>,array<int>}>
     */
    private static array $xMapCache = [];

    /**
     * 使用双线性插值缩放 YUV420P 图像
     * 比双立方插值快3-4倍，低分辨率下质量损失可忽略
     * 列映射按分辨率缓存，行级 pack 输出
     */
    public function scaleYUV420P(string $yuvData, int $srcW, int $srcH, int $dstW, int $dstH): string
    {
        $srcW = $srcW - ($srcW & 1);
        $srcH = $srcH - ($srcH & 1);
        $dstW = $dstW - ($dstW & 1);
        $dstH = $dstH - ($dstH & 1);

        if ($srcW === $dstW && $srcH === $dstH) {
            return $yuvData;
        }

        $ySize = $srcW * $srcH;
        $uvSize = intdiv($ySize, 4);
        $yPlane = substr($yuvData, 0, $ySize);
        $uPlane = substr($yuvData, $ySize, $uvSize);
        $vPlane = substr($yuvData, $ySize + $uvSize, $uvSize);

        $scaledY = $this->scalePlaneBilinear($yPlane, $srcW, $srcH, $dstW, $dstH);
        $scaledU = $this->scalePlaneBilinear($uPlane, $srcW >> 1, $srcH >> 1, $dstW >> 1, $dstH >> 1);
        $scaledV = $this->scalePlaneBilinear($vPlane, $srcW >> 1, $srcH >> 1, $dstW >> 1, $dstH >> 1);

        return $scaledY . $scaledU . $scaledV;
    }

    /**
     * 双线性插值（列映射缓存 + 行级 pack，避免逐像素 chr 写串）
     */
    private function scalePlaneBilinear(string $data, int $srcW, int $srcH, int $dstW, int $dstH): string
    {
        if ($srcW === $dstW && $srcH === $dstH) {
            return $data;
        }

        $key = "{$srcW}_{$dstW}";
        if (!isset(self::$xMapCache[$key])) {
            $fx = (int)($srcW * 65536 / $dstW);
            $x0Map = [];
            $x1Map = [];
            $dxMap = [];
            for ($x = 0; $x < $dstW; $x++) {
                $srcXFixed = $x * $fx;
                $x0 = (int)($srcXFixed >> 16);
                $x0Map[] = $x0;
                $x1Map[] = min($x0 + 1, $srcW - 1);
                $dxMap[] = $srcXFixed & 0xFFFF;
            }
            self::$xMapCache[$key] = [$x0Map, $x1Map, $dxMap];
        }
        [$x0Map, $x1Map, $dxMap] = self::$xMapCache[$key];

        $fy = (int)($srcH * 65536 / $dstH);
        $rows = [];
        for ($y = 0; $y < $dstH; $y++) {
            $srcYFixed = $y * $fy;
            $y0 = (int)($srcYFixed >> 16);
            $y1 = min($y0 + 1, $srcH - 1);
            $dy = $srcYFixed & 0xFFFF;
            $yd = 65536 - $dy;
            $row0 = $y0 * $srcW;
            $row1 = $y1 * $srcW;

            $vals = [];
            for ($x = 0; $x < $dstW; $x++) {
                $dx = $dxMap[$x];
                $a = $x0Map[$x];
                $b = $x1Map[$x];
                $v00 = ord($data[$row0 + $a]);
                $v01 = ord($data[$row0 + $b]);
                $v10 = ord($data[$row1 + $a]);
                $v11 = ord($data[$row1 + $b]);

                $val = ((65536 - $dx) * $yd * $v00 + $dx * $yd * $v01
                     + (65536 - $dx) * $dy * $v10 + $dx * $dy * $v11) >> 32;
                $vals[] = $val > 255 ? 255 : ($val < 0 ? 0 : $val);
            }
            $rows[] = pack('C*', ...$vals);
        }
        return implode('', $rows);
    }
}
