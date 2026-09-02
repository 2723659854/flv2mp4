<?php

namespace Xiaosongshu\Flv2mp4\Mp3;

final class Quantizer
{
    public function quantize(array $spectrum, float $step = 1.0): array
    {
        $result = [];
        foreach ($spectrum as $value) {
            $result[] = (int) max(-8191, min(8191, round($value / $step)));
        }
        return $result;
    }
}
