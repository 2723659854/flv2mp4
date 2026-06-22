<?php

namespace Xiaosongshu\Flv2mp4\Codec;

/**
 * 视频缩放器 - 纯 PHP 实现
 */
class VideoScaler
{
    private int $srcWidth;
    private int $srcHeight;
    private int $dstWidth;
    private int $dstHeight;

    /**
     * 缩放 YUV420P 格式视频
     */
    public function scaleYUV420P(string $yuvData, int $srcW, int $srcH, int $dstW, int $dstH): string
    {
        $this->srcWidth = $srcW;
        $this->srcHeight = $srcH;
        $this->dstWidth = $dstW;
        $this->dstHeight = $dstH;

        // YUV420P 数据布局: Y 平面 + U 平面 + V 平面
        $ySize = $srcW * $srcH;
        $uvSize = $ySize / 4;

        $yPlane = substr($yuvData, 0, $ySize);
        $uPlane = substr($yuvData, $ySize, $uvSize);
        $vPlane = substr($yuvData, $ySize + $uvSize, $uvSize);

        // 缩放各平面
        $scaledY = $this->scalePlane($yPlane, $srcW, $srcH, $dstW, $dstH);
        $scaledU = $this->scalePlane($uPlane, $srcW/2, $srcH/2, $dstW/2, $dstH/2);
        $scaledV = $this->scalePlane($vPlane, $srcW/2, $srcH/2, $dstW/2, $dstH/2);

        return $scaledY . $scaledU . $scaledV;
    }

    /**
     * 缩放单个平面（使用双线性插值）
     */
    private function scalePlane(string $data, float $srcW, float $srcH, float $dstW, float $dstH): string
    {
        $src = unpack('C*', $data);
        $dst = [];

        $srcW = (int)$srcW;
        $srcH = (int)$srcH;
        $dstW = (int)$dstW;
        $dstH = (int)$dstH;

        // 如果尺寸相同，直接返回
        if ($srcW == $dstW && $srcH == $dstH) {
            return $data;
        }

        // 双线性插值
        for ($y = 0; $y < $dstH; $y++) {
            for ($x = 0; $x < $dstW; $x++) {
                // 计算源图像坐标
                $srcX = ($x + 0.5) * $srcW / $dstW - 0.5;
                $srcY = ($y + 0.5) * $srcH / $dstH - 0.5;

                $x0 = (int)floor($srcX);
                $y0 = (int)floor($srcY);
                $x1 = min($x0 + 1, $srcW - 1);
                $y1 = min($y0 + 1, $srcH - 1);

                $fx = $srcX - $x0;
                $fy = $srcY - $y0;

                // 获取四个邻近像素
                $v00 = $src[$y0 * $srcW + $x0 + 1] ?? 0;
                $v01 = $src[$y0 * $srcW + $x1 + 1] ?? 0;
                $v10 = $src[$y1 * $srcW + $x0 + 1] ?? 0;
                $v11 = $src[$y1 * $srcW + $x1 + 1] ?? 0;

                // 双线性插值
                $value = (1-$fx)*(1-$fy)*$v00 + $fx*(1-$fy)*$v01 + (1-$fx)*$fy*$v10 + $fx*$fy*$v11;
                $dst[] = (int)round($value);
            }
        }

        return pack('C*', ...$dst);
    }

    /**
     * 缩放单个平面（使用最近邻插值 - 更快但质量稍差）
     */
    private function scalePlaneNearest(string $data, float $srcW, float $srcH, float $dstW, float $dstH): string
    {
        $src = unpack('C*', $data);
        $dst = [];

        $srcW = (int)$srcW;
        $srcH = (int)$srcH;
        $dstW = (int)$dstW;
        $dstH = (int)$dstH;

        if ($srcW == $dstW && $srcH == $dstH) {
            return $data;
        }

        $ratioX = $srcW / $dstW;
        $ratioY = $srcH / $dstH;

        for ($y = 0; $y < $dstH; $y++) {
            for ($x = 0; $x < $dstW; $x++) {
                $srcX = (int)($x * $ratioX);
                $srcY = (int)($y * $ratioY);
                $value = $src[$srcY * $srcW + $srcX + 1] ?? 0;
                $dst[] = $value;
            }
        }

        return pack('C*', ...$dst);
    }
}