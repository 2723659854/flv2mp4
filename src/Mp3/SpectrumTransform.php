<?php

namespace Xiaosongshu\Flv2mp4\Mp3;

final class SpectrumTransform
{
    public function transform(array $samples): array
    {
        $n = 576;
        $out = [];
        for ($k = 0; $k < $n; ++$k) {
            $sum = 0.0;
            for ($i = 0; $i < $n; ++$i) {
                $sample = $samples[$i] ?? 0.0;
                $window = sin(M_PI * ($i + 0.5) / $n);
                $sum += $sample * $window * cos(M_PI / $n * ($i + 0.5 + $n / 2) * ($k + 0.5));
            }
            $out[$k] = $sum;
        }
        return $out;
    }
}
