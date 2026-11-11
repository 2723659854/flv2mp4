<?php

namespace Xiaosongshu\Flv2mp4\Opus\Celt;

use InvalidArgumentException;

/**
 * @purpose CELT 窗函数模块
 * @author yanglong
 * @time 2026年8月12日17:20:46
 */
final class CeltWindow
{
    public const OVERLAP = 120;

    /**
     * 参考实现 libavcodec/opus/tab.c 的 ff_celt_window2[120]，用于后置滤波器过渡与
     * 冗余帧淡入淡出（dec.c opus_fade）。与 IMDCT 用的窗表不同，必须逐值对齐。
     */
    private const WINDOW2 = [
        4.5275357e-09, 3.66647e-07, 2.82777e-06, 1.08557e-05, 2.96371e-05, 6.60594e-05,
        0.000128686, 0.000227727, 0.000374999, 0.000583881, 0.000869266, 0.0012475,
        0.0017363, 0.00235471, 0.00312299, 0.00406253, 0.00519576, 0.00654601,
        0.00813743, 0.00999482, 0.0121435, 0.0146093, 0.017418, 0.0205957, 0.0241684,
        0.0281615, 0.0326003, 0.0375092, 0.0429118, 0.0488308, 0.0552873, 0.0623012,
        0.0698908, 0.0780723, 0.0868601, 0.0962664, 0.106301, 0.11697, 0.12828,
        0.140231, 0.152822, 0.166049, 0.179905, 0.194379, 0.209457, 0.225123, 0.241356,
        0.258133, 0.275428, 0.293212, 0.311453, 0.330116, 0.349163, 0.368556, 0.388253,
        0.40821, 0.428382, 0.448723, 0.469185, 0.48972, 0.51028, 0.530815, 0.551277,
        0.571618, 0.59179, 0.611747, 0.631444, 0.650837, 0.669884, 0.688547, 0.706788,
        0.724572, 0.741867, 0.758644, 0.774877, 0.790543, 0.805621, 0.820095, 0.833951,
        0.847178, 0.859769, 0.87172, 0.88303, 0.893699, 0.903734, 0.91314, 0.921928,
        0.930109, 0.937699, 0.944713, 0.951169, 0.957088, 0.962491, 0.9674, 0.971838,
        0.975832, 0.979404, 0.982582, 0.985391, 0.987857, 0.990005, 0.991863, 0.993454,
        0.994804, 0.995937, 0.996877, 0.997645, 0.998264, 0.998753, 0.999131, 0.999416,
        0.999625, 0.999772, 0.999871, 0.999934, 0.99997, 0.999989, 0.999997, 0.99999964, 1.0,
    ];

    private static ?array $opusWindow = null;

    public static function window2(): array
    {
        return self::WINDOW2;
    }

    public static function coefficients(int $overlap = self::OVERLAP): array
    {
        if ($overlap < 1 || $overlap > self::OVERLAP) {
            throw new InvalidArgumentException('CELT overlap must be between 1 and 120 samples');
        }
        if ($overlap === self::OVERLAP && self::$opusWindow !== null) {
            return self::$opusWindow;
        }

        $window = [];
        for ($i = 0; $i < $overlap; $i++) {
            $inner = sin(0.5 * M_PI * ($i + 0.5) / $overlap);
            $window[] = sin(0.5 * M_PI * $inner * $inner);
        }
        if ($overlap === self::OVERLAP) {
            self::$opusWindow = $window;
        }
        return $window;
    }

    public static function overlapAdd(array &$buffer, array $block, int $offset, int $overlap = self::OVERLAP): void
    {
        $blockSize = count($block);
        $half = intdiv($overlap, 2);
        if (!in_array($blockSize, CeltMdct::LENGTHS, true) || $overlap > $blockSize || ($overlap & 1) !== 0
            || $offset < 0 || $offset + $blockSize + $overlap > count($buffer)) {
            throw new InvalidArgumentException('Invalid CELT block, overlap, or output offset');
        }

        for ($i = 0; $i < $blockSize; $i++) {
            $buffer[$offset + $half + $i] = (float) $block[$i];
        }
        $window = self::coefficients($overlap);
        for ($i = 0, $j = $half - 1; $i < $half; $i++, $j--) {
            $s0 = (float) $buffer[$offset + $i];
            $s1 = (float) $buffer[$offset + $half + $j];
            $wi = $window[$i];
            $wj = $window[$overlap - 1 - $i];
            $buffer[$offset + $i] = $s0 * $wj - $s1 * $wi;
            $buffer[$offset + $overlap - 1 - $i] = $s0 * $wi + $s1 * $wj;
        }
    }
}
