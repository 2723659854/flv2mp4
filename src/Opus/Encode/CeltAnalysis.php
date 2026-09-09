<?php

namespace Xiaosongshu\Flv2mp4\Opus\Encode;

use InvalidArgumentException;
use Xiaosongshu\Flv2mp4\Opus\Celt\CeltBitAllocation;
use Xiaosongshu\Flv2mp4\Opus\Celt\CeltMdct;

/** CELT analysis transform and per-band normalized spectra. */
final class CeltAnalysis
{
    public const SAMPLE_RATE = 48000;
    public const FRAME_SAMPLES = 960;
    public const LM = 3;

    /**
     * @param array<int, int|float> $pcm One frame (960) or a complete MDCT window (1920).
     * @return array{coefficients: array<int,float>, bands: array<int,array{offset:int,length:int,energy:float,norm:array<int,float>}>}
     */
    public static function analyze(array $pcm, int $lm = self::LM): array
    {
        if (!in_array($lm, [0, 1, 2, 3], true)) {
            throw new InvalidArgumentException('CELT LM must be between 0 and 3');
        }
        if (count($pcm) === self::FRAME_SAMPLES) {
            $pcm = array_merge(array_fill(0, self::FRAME_SAMPLES, 0.0), $pcm);
        } elseif (count($pcm) !== self::FRAME_SAMPLES * 2) {
            throw new InvalidArgumentException('CELT analysis requires 960 or 1920 PCM samples');
        }
        foreach ($pcm as $sample) {
            if ((!is_int($sample) && !is_float($sample)) || !is_finite((float) $sample)) {
                throw new InvalidArgumentException('PCM samples must be finite numbers');
            }
        }
        $coefficients = CeltMdct::forward($pcm);
        $bands = [];
        for ($band = 0; $band < count(CeltBitAllocation::BAND_WIDTHS); $band++) {
            $offset = CeltBitAllocation::BAND_EDGES[$band] << $lm;
            $length = CeltBitAllocation::BAND_WIDTHS[$band] << $lm;
            $values = array_slice($coefficients, $offset, $length);
            $sum = 0.0;
            foreach ($values as $value) $sum += $value * $value;
            $energy = sqrt($sum);
            $scale = $energy > 0.0 ? 1.0 / $energy : 0.0;
            $norm = array_map(static fn(float|int $value): float => (float) $value * $scale, $values);
            $bands[] = ['offset' => $offset, 'length' => $length, 'energy' => $energy, 'norm' => $norm];
        }
        return ['coefficients' => $coefficients, 'bands' => $bands];
    }

    public static function mdct(array $pcm): array
    {
        return self::analyze($pcm)['coefficients'];
    }
}
