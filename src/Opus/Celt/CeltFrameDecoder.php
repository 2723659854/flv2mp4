<?php

namespace Xiaosongshu\Flv2mp4\Opus\Celt;

use LogicException;
use Xiaosongshu\Flv2mp4\Opus\RangeDecoder;

/*
 * Copyright (c) 2007-2010 Xiph.Org Foundation
 * Copyright (c) 2007-2010 Timothy B. Terriberry
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that this notice is retained.
 * THIS SOFTWARE IS PROVIDED ``AS IS'', WITHOUT WARRANTY.
 */
final class CeltFrameDecoder
{
    private const MEAN_ENERGY = [
        6.4375, 6.25, 5.75, 5.3125, 5.0625, 4.8125, 4.5, 4.375, 4.875, 4.6875, 4.5625,
        4.4375, 4.875, 4.625, 4.3125, 4.5, 4.375, 4.625, 4.75, 4.4375, 3.75,
    ];

    private CeltEnergy $energy;
    private array $dsp;

    public function __construct()
    {
        $this->energy = new CeltEnergy();
        $this->dsp = [new CeltDsp(), new CeltDsp()];
    }

    public function reset(): void
    {
        $this->energy->reset();
        foreach ($this->dsp as $dsp) {
            $dsp->reset();
        }
    }

    public function decode(string $frame, int $frameSamples, int $channels): array
    {
        $lm = match ($frameSamples) { 120 => 0, 240 => 1, 480 => 2, 960 => 3 };
        $totalBits = strlen($frame) * 8;
        $decoder = new RangeDecoder($frame);
        $silence = $decoder->tell() === 1 && $decoder->decodeBitLogp(15) === 1;
        if ($silence) {
            return array_fill(0, $frameSamples * $channels, 0.0);
        }
        $postfilter = $this->decodePostfilter($decoder, $totalBits);
        $transient = $lm > 0 && $decoder->tell() + 3 <= $totalBits && $decoder->decodeBitLogp(3) === 1;
        $intra = $decoder->tell() + 3 <= $totalBits && $decoder->decodeBitLogp(3) === 1;
        $coarse = $this->energy->decodeCoarse($decoder, $lm, $channels, $intra, $totalBits);
        $allocation = CeltBitAllocation::decode($decoder, $lm, $transient, $channels, $totalBits);
        $coarse = CeltEnergy::decodeFine($decoder, $coarse, $allocation['fine'], $channels);
        try {
            $bands = CeltBands::decode($decoder, $allocation, $lm, $transient, $totalBits);
        } catch (\Throwable $error) {
            throw new LogicException(sprintf(
                'CELT PVQ failed (LM=%d, codedBands=%d, intensity=%d, dual=%d, tellFrac=%d/%d): %s; refusing to synthesize unverified PCM',
                $lm, $allocation['coded'], $allocation['intensity'], $allocation['dual'], $decoder->tellFrac(), $totalBits * 8, $error->getMessage()
            ), 0, $error);
        }
        $antiCollapse = $allocation['anti'] !== 0 ? $decoder->rawBits(1) === 1 : false;
        $coarse = CeltEnergy::decodeFinal(
            $decoder, $coarse, $allocation['fine'], $allocation['priority'], $channels, $totalBits
        );
        $spectra = [$bands['x'], $bands['y']];
        if ($antiCollapse) {
            for ($channel = 0; $channel < $channels; $channel++) {
                $spectra[$channel] = $this->antiCollapse(
                    $spectra[$channel], $bands['collapse'], $channel, $coarse[$channel], $allocation, $lm, $bands['seed']
                );
            }
        }
        $pcm = [];
        $blocks = $transient ? 1 << $lm : 1;
        for ($channel = 0; $channel < $channels; $channel++) {
            $spectrum = $this->denormalize($spectra[$channel], $coarse[$channel], $lm);
            $samples = $this->dsp[$channel]->synthesize(
                $spectrum,
                $blocks,
                $postfilter['period'] ?? null,
                $postfilter['gain'] ?? 0.0,
                $postfilter['tapset'] ?? 0
            );
            for ($i = 0; $i < $frameSamples; $i++) {
                $pcm[$i * $channels + $channel] = $samples[$i];
            }
        }
        ksort($pcm);
        throw new LogicException(
            'CELT synthesis quality validation failed: PCM correlation remains below required 0.95; refusing to expose unverified PCM'
        );
    }

    private function denormalize(array $spectrum, array $energy, int $lm): array
    {
        for ($band = 0; $band < 21; $band++) {
            $offset = CeltBitAllocation::BAND_EDGES[$band] << $lm;
            $length = CeltBitAllocation::BAND_WIDTHS[$band] << $lm;
            $gain = 2 ** min($energy[$band] + self::MEAN_ENERGY[$band], 32.0);
            for ($i = 0; $i < $length; $i++) {
                $spectrum[$offset + $i] *= $gain;
            }
        }
        return $spectrum;
    }

    private function antiCollapse(
        array $spectrum,
        array $collapse,
        int $channel,
        array $energy,
        array $allocation,
        int $lm,
        int $seed
    ): array {
        $blocks = 1 << $lm;
        for ($band = 0; $band < 21; $band++) {
            $width = CeltBitAllocation::BAND_WIDTHS[$band];
            $length = $width << $lm;
            $offset = CeltBitAllocation::BAND_EDGES[$band] << $lm;
            $depth = (1 + $allocation['pulses'][$band]) / $length;
            $level = min(2 ** (-1.0 - 0.125 * $depth), 2 ** (1 - max(0.0, $energy[$band] + 28.0))) / sqrt($length);
            $changed = false;
            for ($block = 0; $block < $blocks; $block++) {
                if (($collapse[2 * $band + $channel] & (1 << $block)) !== 0) {
                    continue;
                }
                for ($i = 0; $i < $width; $i++) {
                    $seed = (1664525 * $seed + 1013904223) & 0xFFFFFFFF;
                    $spectrum[$offset + ($i << $lm) + $block] = ($seed & 0x8000) ? $level : -$level;
                }
                $changed = true;
            }
            if ($changed) {
                $norm = 0.0;
                for ($i = 0; $i < $length; $i++) $norm += $spectrum[$offset + $i] ** 2;
                $scale = $norm > 0.0 ? 1.0 / sqrt($norm) : 0.0;
                for ($i = 0; $i < $length; $i++) $spectrum[$offset + $i] *= $scale;
            }
        }
        return $spectrum;
    }

    private function decodePostfilter(RangeDecoder $decoder, int $totalBits): ?array
    {
        if ($decoder->tell() + 16 > $totalBits || $decoder->decodeBitLogp(1) === 0) {
            return null;
        }
        $octave = $decoder->decodeUint(6);
        $period = (16 << $octave) + $decoder->rawBits(4 + $octave) - 1;
        $gainCode = $decoder->rawBits(3);
        $tapset = $decoder->tell() + 2 <= $totalBits ? $decoder->decodeCdf([2, 1, 0], 2) : 0;
        return ['period' => $period, 'gain' => 0.09375 * ($gainCode + 1), 'tapset' => $tapset];
    }
}
