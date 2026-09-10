<?php

namespace Xiaosongshu\Flv2mp4\Opus\Encode;

use InvalidArgumentException;

/** Strict cosine MDCT used by the CELT encoder. */
final class CeltMdctEncoder
{
    private const INPUT_LENGTH = 1920;
    private const OUTPUT_LENGTH = 960;

    /**
     * @param array<int, int|float> $samples
     * @return array<int, float>
     */
    public static function forward(array $samples): array
    {
        if (count($samples) !== self::INPUT_LENGTH) {
            throw new InvalidArgumentException('CELT MDCT encoder requires 1920 samples');
        }
        foreach ($samples as $sample) {
            if ((!is_int($sample) && !is_float($sample)) || !is_finite((float) $sample)) {
                throw new InvalidArgumentException('MDCT samples must be finite numbers');
            }
        }

        $output = array_fill(0, self::OUTPUT_LENGTH, 0.0);
        $factor = M_PI / self::OUTPUT_LENGTH;
        $normalization = 1.0;
        for ($bin = 0; $bin < self::OUTPUT_LENGTH; $bin++) {
            $sum = 0.0;
            $binOffset = $bin + 0.5;
            for ($sample = 0; $sample < self::INPUT_LENGTH; $sample++) {
                $sum += (float) $samples[$sample]
                    * cos($factor * ($sample + 0.5 + self::OUTPUT_LENGTH / 2.0) * $binOffset);
            }
            $output[$bin] = $sum * $normalization;
        }
        return $output;
    }
}
