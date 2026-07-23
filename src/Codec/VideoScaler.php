<?php

namespace Xiaosongshu\Flv2mp4\Codec;

/**
 * @purpose 视频缩放器
 * @author yanglong
 * @time 2026年7月23日15:28:20
 */
class VideoScaler
{
    /**
     * 使用双立方插值 (Catmull-Rom) 缩放 YUV420P 图像
     * 比双线性插值质量好很多，能更好地保留边缘细节
     */
    public function scaleYUV420P(string $yuvData, int $srcW, int $srcH, int $dstW, int $dstH): string
    {
        // YUV420P 强制宽高为偶数，防止UV平面尺寸错乱花屏
        $srcW = $srcW - ($srcW & 1);
        $srcH = $srcH - ($srcH & 1);
        $dstW = $dstW - ($dstW & 1);
        $dstH = $dstH - ($dstH & 1);

        // 尺寸一致直接返回原始数据，跳过计算
        if ($srcW === $dstW && $srcH === $dstH) {
            return $yuvData;
        }

        $ySize = $srcW * $srcH;
        $uvSize = intdiv($ySize, 4);
        $yPlane = substr($yuvData, 0, $ySize);
        $uPlane = substr($yuvData, $ySize, $uvSize);
        $vPlane = substr($yuvData, $ySize + $uvSize, $uvSize);

        $scaledY = $this->scalePlaneBicubic($yPlane, $srcW, $srcH, $dstW, $dstH);
        $scaledU = $this->scalePlaneBicubic($uPlane, $srcW >> 1, $srcH >> 1, $dstW >> 1, $dstH >> 1);
        $scaledV = $this->scalePlaneBicubic($vPlane, $srcW >> 1, $srcH >> 1, $dstW >> 1, $dstH >> 1);

        return $scaledY . $scaledU . $scaledV;
    }

    /**
     * 双立方插值 (Catmull-Rom spline)
     * 使用周围16个像素点进行加权计算，边缘保留更好
     * 修复：unpack索引偏移BUG、权重短路优化、浮点数除零保护
     */
    private function scalePlaneBicubic(string $data, int $srcW, int $srcH, int $dstW, int $dstH): string
    {
        if ($srcW === $dstW && $srcH === $dstH) {
            return $data;
        }

        $src = array_values(unpack('C*', $data));
        $dst = [];
        $ratioX = $srcW / $dstW;
        $ratioY = $srcH / $dstH;

        // Catmull-Rom 权重计算
        $cubic = function(float $t): float {
            $t = abs($t);
            if ($t <= 1.0) {
                return 1 - 2 * $t * $t + $t * $t * $t;
            }
            if ($t < 2.0) {
                return 4 - 8 * $t + 5 * $t * $t - $t * $t * $t;
            }
            return 0.0;
        };

        for ($y = 0; $y < $dstH; $y++) {
            $srcYf = $y * $ratioY;
            $y0 = (int)floor($srcYf);
            $dy = $srcYf - $y0;

            for ($x = 0; $x < $dstW; $x++) {
                $srcXf = $x * $ratioX;
                $x0 = (int)floor($srcXf);
                $dx = $srcXf - $x0;

                $sum = 0.0;
                $weightSum = 0.0;

                // 4x4邻域采样
                for ($j = -1; $j <= 2; $j++) {
                    $py = min(max($y0 + $j, 0), $srcH - 1);
                    $wy = $cubic($j - $dy);
                    if ($wy < 1e-8) continue;

                    for ($i = -1; $i <= 2; $i++) {
                        $px = min(max($x0 + $i, 0), $srcW - 1);
                        $wx = $cubic($i - $dx);
                        if ($wx < 1e-8) continue;

                        $idx = $py * $srcW + $px;
                        $v = $src[$idx] ?? 128;
                        $w = $wx * $wy;

                        $sum += $v * $w;
                        $weightSum += $w;
                    }
                }

                // 防止除零
                if ($weightSum < 1e-6) {
                    $val = 128;
                } else {
                    $val = (int)round($sum / $weightSum);
                }
                $dst[] = max(0, min(255, $val));
            }
        }

        return pack('C*', ...$dst);
    }

    /**
     * 简单的双线性插值 (保留作为快速降级选项)
     * 修复索引偏移BUG
     */
    private function scalePlaneBilinear(string $data, int $srcW, int $srcH, int $dstW, int $dstH): string
    {
        if ($srcW === $dstW && $srcH === $dstH) {
            return $data;
        }

        $src = array_values(unpack('C*', $data));
        $dst = [];
        $ratioX = $srcW / $dstW;
        $ratioY = $srcH / $dstH;

        for ($y = 0; $y < $dstH; $y++) {
            $srcY = $y * $ratioY;
            $y0 = (int)floor($srcY);
            $y1 = min($y0 + 1, $srcH - 1);
            $dy = $srcY - $y0;

            for ($x = 0; $x < $dstW; $x++) {
                $srcX = $x * $ratioX;
                $x0 = (int)floor($srcX);
                $x1 = min($x0 + 1, $srcW - 1);
                $dx = $srcX - $x0;

                $v00 = $src[$y0 * $srcW + $x0] ?? 128;
                $v01 = $src[$y0 * $srcW + $x1] ?? 128;
                $v10 = $src[$y1 * $srcW + $x0] ?? 128;
                $v11 = $src[$y1 * $srcW + $x1] ?? 128;

                $val = (1 - $dx) * (1 - $dy) * $v00
                    + $dx * (1 - $dy) * $v01
                    + (1 - $dx) * $dy * $v10
                    + $dx * $dy * $v11;
                $dst[] = (int)round(max(0, min(255, $val)));
            }
        }
        return pack('C*', ...$dst);
    }
}