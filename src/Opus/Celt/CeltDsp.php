<?php

namespace Xiaosongshu\Flv2mp4\Opus\Celt;

use InvalidArgumentException;

/*
 * Portions follow RFC 6716 Code Components, Copyright (c) 2012 IETF Trust.
 * Redistribution and use in source and binary forms, with or without modification,
 * are permitted provided that this notice is retained. THIS SOFTWARE IS PROVIDED
 * "AS IS", WITHOUT WARRANTY; contributors are not liable for any damages.
 */
final class CeltDsp
{
    public const DEEMPHASIS = 27853.0 / 32768.0;
    public const POSTFILTER_TAPS = [
        [0.306640625, 0.217041015625, 0.129638671875],
        [0.4638671875, 0.26806640625, 0.0],
        [0.7998046875, 0.10009765625, 0.0],
    ];

    private array $outMemory = [];
    private array $postfilterHistory = [];
    private int $postfilterPeriod = 15;
    private float $postfilterGain = 0.0;
    private int $postfilterTapset = 0;
    private float $deemphasisMemory = 0.0;

    /**
     * Synthesizes one CELT frame. For transient packets, pass 2, 4, or 8
     * concatenated short-block spectra whose total size is the frame size.
     */
    public function synthesize(
        array $coefficients,
        int $shortBlocks = 1,
        ?int $postfilterPeriod = null,
        float $postfilterGain = 0.0,
        int $tapset = 0
    ): array {
        $frameSize = count($coefficients);
        if (!in_array($frameSize, CeltMdct::LENGTHS, true)) {
            throw new InvalidArgumentException('CELT frame must contain 120, 240, 480, or 960 coefficients');
        }
        if ($shortBlocks < 1 || $frameSize % $shortBlocks !== 0) {
            throw new InvalidArgumentException('Invalid CELT short-block count');
        }
        $blockSize = intdiv($frameSize, $shortBlocks);
        if (!in_array($blockSize, CeltMdct::LENGTHS, true)) {
            throw new InvalidArgumentException('CELT short-block length is unsupported');
        }

        $overlap = CeltWindow::OVERLAP;
        $buffer = array_fill(0, $frameSize + $overlap, 0.0);
        if ($this->outMemory !== []) {
            for ($i = 0; $i < $overlap; $i++) {
                $buffer[$i] = $this->outMemory[$i];
            }
        }
        for ($block = 0; $block < $shortBlocks; $block++) {
            $spectrum = [];
            for ($i = 0; $i < $blockSize; $i++) {
                $spectrum[] = $coefficients[$block + $i * $shortBlocks];
            }
            CeltWindow::overlapAdd($buffer, CeltMdct::inverse($spectrum), $block * $blockSize);
        }
        $output = array_slice($buffer, 0, $frameSize);
        $this->outMemory = array_slice($buffer, $frameSize, $overlap);

        $output = $this->postfilterTransition($output, $postfilterPeriod, $postfilterGain, $tapset);
        return $this->deemphasis($output);
    }

    public function deemphasis(array $samples): array
    {
        $output = [];
        $memory = $this->deemphasisMemory;
        foreach ($samples as $sample) {
            if (!is_int($sample) && !is_float($sample)) {
                throw new InvalidArgumentException('CELT samples must be numeric');
            }
            $memory = (float) $sample + self::DEEMPHASIS * $memory;
            if (!is_finite($memory)) {
                $memory = 0.0;
            }
            $output[] = $memory;
        }
        $this->deemphasisMemory = $memory;
        return $output;
    }

    public function postfilter(array $samples, int $period, float $gain, int $tapset = 0): array
    {
        if ($period < 15 || $period > 1023) {
            throw new InvalidArgumentException('CELT postfilter period must be between 15 and 1023');
        }
        if (!isset(self::POSTFILTER_TAPS[$tapset])) {
            throw new InvalidArgumentException('Invalid CELT postfilter tapset');
        }
        $taps = self::POSTFILTER_TAPS[$tapset];
        $input = array_merge($this->postfilterHistory, array_map('floatval', $samples));
        $historyLength = count($this->postfilterHistory);
        $output = [];
        foreach ($samples as $i => $sample) {
            $position = $historyLength + $i;
            $filtered = (float) $sample;
            $source = $position - $period;
            if ($source >= 0) {
                $filtered += $gain * $taps[0] * $input[$source];
            }
            for ($tap = 1; $tap <= 2; $tap++) {
                foreach ([-$tap, $tap] as $offset) {
                    $source = $position - $period + $offset;
                    if ($source >= 0) {
                        $filtered += $gain * $taps[$tap] * $input[$source];
                    }
                }
            }
            $output[] = $filtered;
        }
        $this->postfilterHistory = array_slice($input, -1024);
        return $output;
    }

    public function reset(): void
    {
        $this->outMemory = [];
        $this->postfilterHistory = [];
        $this->postfilterPeriod = 15;
        $this->postfilterGain = 0.0;
        $this->postfilterTapset = 0;
        $this->deemphasisMemory = 0.0;
    }

    private function postfilterTransition(array $samples, ?int $period, float $gain, int $tapset): array
    {
        $newPeriod = $period ?? 15;
        $newGain = $period === null ? 0.0 : $gain;
        $history = $this->postfilterHistory;
        $input = array_merge($history, array_map('floatval', $samples));
        $historyLength = count($history);
        $output = [];
        $window = CeltWindow::coefficients();
        foreach ($samples as $i => $sample) {
            $mix = $i < CeltWindow::OVERLAP ? $window[$i] ** 2 : 1.0;
            $old = $this->postfilterContribution(
                $input, $historyLength + $i, $this->postfilterPeriod, $this->postfilterGain, $this->postfilterTapset
            );
            $new = $this->postfilterContribution($input, $historyLength + $i, $newPeriod, $newGain, $tapset);
            $output[] = (float) $sample + (1.0 - $mix) * $old + $mix * $new;
        }
        $this->postfilterHistory = array_slice($input, -1024);
        $this->postfilterPeriod = $newPeriod;
        $this->postfilterGain = $newGain;
        $this->postfilterTapset = $tapset;
        return $output;
    }

    private function postfilterContribution(array $input, int $position, int $period, float $gain, int $tapset): float
    {
        if ($gain === 0.0) {
            return 0.0;
        }
        $taps = self::POSTFILTER_TAPS[$tapset];
        $sum = 0.0;
        for ($tap = -2; $tap <= 2; $tap++) {
            $source = $position - $period + $tap;
            if ($source >= 0) {
                $sum += $gain * $taps[abs($tap)] * $input[$source];
            }
        }
        return $sum;
    }

}
