<?php

namespace Xiaosongshu\Flv2mp4\Aac;

use InvalidArgumentException;

/**
 * @purpose 纯 PHP 实现的 AAC-LC 编码器，适用于 48 kHz 单声道或交错立体声 PCM
 * @author yanglong
 * @time 2026年8月12日17:08:03
 */
final class AacLcEncoder
{
    public const FRAME_SAMPLES = 1024;
    private const SAMPLE_RATE = 48000;
    private const MAX_SFB = 40;
    private const MAX_GLOBAL_SEARCHES = 6;
    private const MAX_LOCAL_ADJUSTMENTS = 96;

    private int $bitrate;
    private int $channels;
    private ?int $previousGlobalOffset = null;

    private int $debugAttemptFrames = 0;
    private int $debugAttempts = 0;

    private array $pending = [];
    private int $pendingOffset = 0;
    private array $overlap = [[], []];
    private int $frameCount = 0;

    public function __construct(int $bitrate = 128000, int $channels = 2)
    {
        if ($channels !== 1 && $channels !== 2) {
            throw new InvalidArgumentException('AAC channel count must be 1 or 2');
        }
        $minimumBitrate = $channels === 1 ? 48000 : 96000;
        if ($bitrate < $minimumBitrate || $bitrate > 192000) {
            throw new InvalidArgumentException("AAC bitrate must be between {$minimumBitrate} and 192000 bit/s");
        }
        $this->bitrate = $bitrate;
        $this->channels = $channels;
        $silence = array_fill(0, self::FRAME_SAMPLES, 0.0);
        $this->overlap = array_fill(0, $channels, $silence);
    }

    public function encodeFloat(array $interleavedPcm): string
    {
        foreach ($interleavedPcm as $sample) {
            $this->pending[] = max(-1.0, min(1.0, (float) $sample));
        }
        return $this->drain(false);
    }

    public function encodeS16le(string $interleavedPcm): string
    {
        $sampleFrameBytes = $this->channels * 2;
        if (strlen($interleavedPcm) % $sampleFrameBytes !== 0) {
            throw new InvalidArgumentException('s16le PCM must contain complete sample frames');
        }
        $samples = unpack('v*', $interleavedPcm);
        $float = [];
        foreach ($samples as $sample) {
            if ($sample >= 0x8000) {
                $sample -= 0x10000;
            }
            $float[] = $sample / 32768.0;
        }
        return $this->encodeFloat($float);
    }

    public function flush(): string
    {
        if (count($this->pending) === $this->pendingOffset) {
            $this->pending = [];
            $this->pendingOffset = 0;
            return '';
        }
        $needed = self::FRAME_SAMPLES * $this->channels - (count($this->pending) - $this->pendingOffset);
        while ($needed-- > 0) {
            $this->pending[] = 0.0;
        }
        return $this->drain(true);
    }

    public function getAudioSpecificConfig(): string
    {
        $config = (2 << 11) | (3 << 7) | ($this->channels << 3);
        return pack('n', $config);
    }

    public function channels(): int
    {
        return $this->channels;
    }

    public function frameCount(): int
    {
        return $this->frameCount;
    }

    private function drain(bool $flush): string
    {
        $output = '';
        $frameSize = self::FRAME_SAMPLES * $this->channels;
        $pendingCount = count($this->pending);
        while ($pendingCount - $this->pendingOffset >= $frameSize) {
            $channels = array_fill(0, $this->channels, []);
            $offset = $this->pendingOffset;
            for ($i = 0; $i < self::FRAME_SAMPLES; ++$i) {
                for ($channel = 0; $channel < $this->channels; ++$channel) {
                    $channels[$channel][$i] = $this->pending[$offset++];
                }
            }
            $this->pendingOffset = $offset;
            $output .= $this->encodeFrame($channels);
        }
        if ($flush || $this->pendingOffset >= $frameSize * 8) {
            $this->pending = $flush
                ? []
                : array_slice($this->pending, $this->pendingOffset, $pendingCount - $this->pendingOffset);
            $this->pendingOffset = 0;
        }
        return $output;
    }

    private function encodeFrame(array $channels): string
    {
        $spectra = [];
        for ($ch = 0; $ch < $this->channels; ++$ch) {
            $input = array_merge($this->overlap[$ch], $channels[$ch]);
            $this->overlap[$ch] = $channels[$ch];
            $mdct = $this->mdct($input);
            $pow34 = [];
            $energy = array_fill(0, self::MAX_SFB, 0.0);
            $maxPow34 = array_fill(0, self::MAX_SFB, 0.0);
            $band = 0;
            for ($i = 0; $i < 1024; ++$i) {
                $value = $mdct[$i];
                $magnitude = pow(abs($value), 0.75);
                $pow34[$i] = $value < 0.0 ? -$magnitude : $magnitude;
                if ($band < self::MAX_SFB) {
                    while ($band + 1 < self::MAX_SFB && $i >= AacTables::SWB_48K[$band + 1]) {
                        ++$band;
                    }
                    if ($i < AacTables::SWB_48K[self::MAX_SFB]) {
                        $energy[$band] += $value * $value;
                        $maxPow34[$band] = max($maxPow34[$band], $magnitude);
                    }
                }
            }
            $spectra[$ch] = [
                'mdct' => $mdct,
                'pow34' => $pow34,
                'energy' => $energy,
                'maxPow34' => $maxPow34,
                'totalEnergy' => array_sum($energy),
                'peakPow34' => max($maxPow34),
            ];
        }

        $targetBytes = max(1, intdiv((int) floor($this->bitrate * 1024 / self::SAMPLE_RATE) - 56, 8));
        $attempts = 0;
        $plans = null;
        if ($this->previousGlobalOffset !== null) {
            $candidate = [];
            foreach ($spectra as $spectrum) {
                $candidate[] = $this->analyzeChannel($spectrum, $this->previousGlobalOffset);
            }
            ++$attempts;
            $candidateBytes = $this->estimateRawBytes($candidate);
            if ($candidateBytes <= $targetBytes && $candidateBytes >= (int) floor($targetBytes * 0.85)) {
                $plans = $candidate;
            }
        }
        if ($plans === null) {
            $low = 0;
            $high = 63;
            $bestPlans = null;
            $bestOffset = 64;
            while ($low <= $high && $attempts < self::MAX_GLOBAL_SEARCHES) {
                $offset = ($low + $high) >> 1;
                $candidate = [];
                foreach ($spectra as $spectrum) {
                    $candidate[] = $this->analyzeChannel($spectrum, $offset);
                }
                ++$attempts;
                if ($this->estimateRawBytes($candidate) <= $targetBytes) {
                    $bestPlans = $candidate;
                    $bestOffset = $offset;
                    $high = $offset - 1;
                } else {
                    $low = $offset + 1;
                }
            }
            if ($bestPlans === null) {
                $bestPlans = [];
                foreach ($spectra as $spectrum) {
                    $bestPlans[] = $this->analyzeChannel($spectrum, 64);
                }
                ++$attempts;
            }
            $this->previousGlobalOffset = $bestOffset;
            $plans = $this->adjustToBudget($spectra, $bestPlans, $targetBytes, $attempts);
        }
        $raw = $this->rawDataBlock($plans);

        ++$this->frameCount;
        return $this->adtsHeader(strlen($raw) + 7) . $raw;
    }

    private function analyzeChannel(array $spectrum, int $offset): array
    {
        $scaleFactors = [];
        for ($band = 0; $band < self::MAX_SFB; ++$band) {
            $maximum = $spectrum['maxPow34'][$band];
            if ($maximum === 0.0) {
                $scaleFactors[$band] = 255;
                continue;
            }
            $width = AacTables::SWB_48K[$band + 1] - AacTables::SWB_48K[$band];
            $target = $width <= 8 ? 7.0 : 4.0;
            $scaleFactors[$band] = max(0, min(255, (int) ceil(104.0 + (16.0 / 3.0) * log($maximum / ($target - 0.4054), 2) + $offset)));
        }
        $scaleFactors = $this->constrainScaleFactors($scaleFactors, $spectrum);
        $bands = [];
        for ($band = 0; $band < self::MAX_SFB; ++$band) {
            $bands[$band] = $this->quantizeBand($spectrum, $band, $scaleFactors[$band]);
        }
        return $this->finishPlan($bands);
    }

    private function constrainScaleFactors(array $scaleFactors, array $spectrum): array
    {
        $active = [];
        for ($band = 0; $band < self::MAX_SFB; ++$band) {
            if ($spectrum['maxPow34'][$band] > 0.0) {
                $active[] = $band;
            }
        }
        $count = count($active);
        for ($i = 0; $i < $count; ++$i) {
            for ($j = 0; $j < $count; ++$j) {
                $scaleFactors[$active[$i]] = max(
                    $scaleFactors[$active[$i]],
                    $scaleFactors[$active[$j]] - 60 * abs($i - $j)
                );
            }
            $scaleFactors[$active[$i]] = min(255, $scaleFactors[$active[$i]]);
        }
        return $scaleFactors;
    }

    private function quantizeBand(array $spectrum, int $band, int $scaleFactor): array
    {
        $start = AacTables::SWB_48K[$band];
        $end = AacTables::SWB_48K[$band + 1];
        do {
            $quantizer = pow(2.0, (104 - $scaleFactor) * 3.0 / 16.0);
            $q = [];
            $qMax = 0;
            $distortion = 0.0;
            for ($i = $start; $i < $end; ++$i) {
                $value = (int) floor(abs($spectrum['pow34'][$i]) * $quantizer + 0.4054);
                $qMax = max($qMax, $value);
                $q[] = $spectrum['pow34'][$i] < 0.0 ? -$value : $value;
                $reconstructed = $value === 0 ? 0.0 : pow($value / $quantizer, 4.0 / 3.0);
                if ($spectrum['mdct'][$i] < 0.0) {
                    $reconstructed = -$reconstructed;
                }
                $error = $spectrum['mdct'][$i] - $reconstructed;
                $distortion += $error * $error;
            }
            if ($qMax <= 7 || $scaleFactor === 255) {
                break;
            }
            ++$scaleFactor;
        } while (true);

        $significant = $spectrum['totalEnergy'] > 0.0
            && $spectrum['energy'][$band] >= $spectrum['totalEnergy'] * 1.0e-8
            && $spectrum['maxPow34'][$band] >= $spectrum['peakPow34'] * 1.0e-6;
        while ($qMax === 0 && $significant && $scaleFactor > 0) {
            --$scaleFactor;
            $quantizer = pow(2.0, (104 - $scaleFactor) * 3.0 / 16.0);
            $q = [];
            $qMax = 0;
            $distortion = 0.0;
            for ($i = $start; $i < $end; ++$i) {
                $value = (int) floor(abs($spectrum['pow34'][$i]) * $quantizer + 0.4054);
                $qMax = max($qMax, $value);
                $q[] = $spectrum['pow34'][$i] < 0.0 ? -$value : $value;
                $reconstructed = $value === 0 ? 0.0 : pow($value / $quantizer, 4.0 / 3.0);
                if ($spectrum['mdct'][$i] < 0.0) {
                    $reconstructed = -$reconstructed;
                }
                $error = $spectrum['mdct'][$i] - $reconstructed;
                $distortion += $error * $error;
            }
        }

        $codebook = 0;
        $spectralBits = 0;
        if ($qMax > 0) {
            if ($qMax <= 4) {
                $bits5 = $this->spectralBits($q, 5);
                $bits7 = $this->spectralBits($q, 7);
                if ($bits5 <= $bits7) {
                    $codebook = 5;
                    $spectralBits = $bits5;
                } else {
                    $codebook = 7;
                    $spectralBits = $bits7;
                }
            } else {
                $codebook = 7;
                $spectralBits = $this->spectralBits($q, 7);
            }
        }
        return [
            'sf' => $scaleFactor,
            'codebook' => $codebook,
            'q' => $q,
            'qMax' => $qMax,
            'spectralBits' => $spectralBits,
            'distortion' => $distortion,
        ];
    }

    private function spectralBits(array $q, int $codebook): int
    {
        $bits = 0;
        $count = count($q);
        for ($i = 0; $i < $count; $i += 2) {
            $a = $q[$i];
            $b = $q[$i + 1];
            if ($codebook === 5) {
                $bits += AacTables::BITS5[($a + 4) * 9 + ($b + 4)];
            } else {
                $bits += AacTables::BITS7[abs($a) * 8 + abs($b)];
                $bits += ($a !== 0 ? 1 : 0) + ($b !== 0 ? 1 : 0);
            }
        }
        return $bits;
    }

    private function finishPlan(array $bands): array
    {
        $globalGain = 0;
        foreach ($bands as $band) {
            if ($band['codebook'] !== 0) {
                $globalGain = $band['sf'];
                break;
            }
        }
        return ['globalGain' => $globalGain, 'bands' => $bands];
    }

    private function adjustToBudget(array $spectra, array $plans, int $targetBytes, int &$attempts): array
    {
        for ($iteration = 0; $iteration < self::MAX_LOCAL_ADJUSTMENTS; ++$iteration) {
            $currentBytes = $this->estimateRawBytes($plans);
            $overBudget = $currentBytes > $targetBytes;
            $best = null;
            foreach ($plans as $channel => $plan) {
                for ($band = 0; $band < self::MAX_SFB; ++$band) {
                    $old = $plan['bands'][$band];
                    if ($old['codebook'] === 0 || ($overBudget && $old['sf'] >= 255) || (!$overBudget && $old['sf'] <= 0)) {
                        continue;
                    }
                    $newSf = $old['sf'] + ($overBudget ? 1 : -1);
                    if (!$this->isScaleFactorLegal($plan['bands'], $band, $newSf)) {
                        continue;
                    }
                    $newBand = $this->quantizeBand($spectra[$channel], $band, $newSf);
                    if ($newBand['qMax'] > 7 || (!$overBudget && $newBand['sf'] !== $newSf)) {
                        continue;
                    }
                    $candidatePlans = $plans;
                    $candidatePlans[$channel]['bands'][$band] = $newBand;
                    $candidatePlans[$channel] = $this->finishPlan($candidatePlans[$channel]['bands']);
                    $candidateBytes = $this->estimateRawBytes($candidatePlans);
                    if ($overBudget) {
                        $saved = $currentBytes - $candidateBytes;
                        if ($saved <= 0) {
                            continue;
                        }
                        $score = max(0.0, $newBand['distortion'] - $old['distortion']) / $saved;
                        if ($best === null || $score < $best['score']) {
                            $best = ['score' => $score, 'plans' => $candidatePlans];
                        }
                    } else {
                        $added = $candidateBytes - $currentBytes;
                        if ($added <= 0 || $candidateBytes > $targetBytes) {
                            continue;
                        }
                        $improvement = max(0.0, $old['distortion'] - $newBand['distortion']);
                        $score = $improvement / $added;
                        if ($score > 0.0 && ($best === null || $score > $best['score'])) {
                            $best = ['score' => $score, 'plans' => $candidatePlans];
                        }
                    }
                }
            }
            if ($best === null) {
                break;
            }
            $plans = $best['plans'];
            ++$attempts;
        }
        return $plans;
    }

    private function isScaleFactorLegal(array $bands, int $changedBand, int $newSf): bool
    {
        $previous = null;
        $next = null;
        for ($band = $changedBand - 1; $band >= 0; --$band) {
            if ($bands[$band]['codebook'] !== 0) {
                $previous = $bands[$band]['sf'];
                break;
            }
        }
        for ($band = $changedBand + 1; $band < self::MAX_SFB; ++$band) {
            if ($bands[$band]['codebook'] !== 0) {
                $next = $bands[$band]['sf'];
                break;
            }
        }
        return ($previous === null || abs($newSf - $previous) <= 60)
            && ($next === null || abs($next - $newSf) <= 60);
    }

    private function estimateRawBytes(array $plans): int
    {
        if ($this->channels === 1) {
            $bits = 3 + 4 + 8 + 11 + $this->channelPayloadBits($plans[0]);
        } else {
            $bits = 3 + 4 + 1 + 11 + 2;
            foreach ($plans as $plan) {
                $bits += 8 + $this->channelPayloadBits($plan);
            }
        }
        $bits += 3;
        return intdiv($bits + 7, 8);
    }

    private function channelPayloadBits(array $plan): int
    {
        $bits = 3;
        $band = 0;
        while ($band < self::MAX_SFB) {
            $codebook = $plan['bands'][$band]['codebook'];
            $run = 1;
            while ($band + $run < self::MAX_SFB && $plan['bands'][$band + $run]['codebook'] === $codebook) {
                ++$run;
            }
            $bits += 4 + 5 * (intdiv($run, 31) + 1);
            $band += $run;
        }
        $previous = $plan['globalGain'];
        foreach ($plan['bands'] as $band) {
            if ($band['codebook'] === 0) {
                continue;
            }
            $difference = $band['sf'] - $previous;
            $bits += AacTables::SCALEFACTOR_BITS[$difference + 60];
            $previous = $band['sf'];
            $bits += $band['spectralBits'];
        }
        return $bits;
    }

    private function rawDataBlock(array $plans): string
    {
        $writer = new BitWriter();
        if ($this->channels === 1) {
            $writer->write(0, 3);
            $writer->write(0, 4);
            $writer->write($plans[0]['globalGain'], 8);
            $this->writeIcsInfo($writer);
            $this->writeChannelPayload($writer, $plans[0]);
        } else {
            $writer->write(1, 3);
            $writer->write(0, 4);
            $writer->write(1, 1);
            $this->writeIcsInfo($writer);
            $writer->write(0, 2);
            foreach ($plans as $plan) {
                $writer->write($plan['globalGain'], 8);
                $this->writeChannelPayload($writer, $plan);
            }
        }
        $writer->write(7, 3);
        return $writer->finish();
    }

    private function writeIcsInfo(BitWriter $writer): void
    {
        $writer->write(0, 1);
        $writer->write(0, 2);
        $writer->write(0, 1);
        $writer->write(self::MAX_SFB, 6);
        $writer->write(0, 1);
    }

    private function writeChannelPayload(BitWriter $writer, array $plan): void
    {
        $band = 0;
        while ($band < self::MAX_SFB) {
            $codebook = $plan['bands'][$band]['codebook'];
            $run = 1;
            while ($band + $run < self::MAX_SFB && $plan['bands'][$band + $run]['codebook'] === $codebook) {
                ++$run;
            }
            $writer->write($codebook, 4);
            $remaining = $run;
            while ($remaining >= 31) {
                $writer->write(31, 5);
                $remaining -= 31;
            }
            $writer->write($remaining, 5);
            $band += $run;
        }

        $previous = $plan['globalGain'];
        foreach ($plan['bands'] as $band) {
            if ($band['codebook'] === 0) {
                continue;
            }
            $difference = $band['sf'] - $previous;
            $writer->write(AacTables::SCALEFACTOR_CODES[$difference + 60], AacTables::SCALEFACTOR_BITS[$difference + 60]);
            $previous = $band['sf'];
        }
        $writer->write(0, 1);
        $writer->write(0, 1);
        $writer->write(0, 1);

        foreach ($plan['bands'] as $band) {
            if ($band['codebook'] === 0) {
                continue;
            }
            $q = $band['q'];
            $count = count($q);
            for ($i = 0; $i < $count; $i += 2) {
                $a = $q[$i];
                $b = $q[$i + 1];
                if ($band['codebook'] === 5) {
                    $index = ($a + 4) * 9 + ($b + 4);
                    $writer->write(AacTables::CODES5[$index], AacTables::BITS5[$index]);
                } else {
                    $index = abs($a) * 8 + abs($b);
                    $writer->write(AacTables::CODES7[$index], AacTables::BITS7[$index]);
                    if ($a !== 0) {
                        $writer->write($a < 0 ? 1 : 0, 1);
                    }
                    if ($b !== 0) {
                        $writer->write($b < 0 ? 1 : 0, 1);
                    }
                }
            }
        }
    }

    private function mdct(array $input): array
    {
        return FastMdct::transform($input);
    }

    private function adtsHeader(int $frameLength): string
    {
        $profile = 1;
        $frequencyIndex = 3;
        $channelConfig = $this->channels;
        return pack('C7',
            0xff,
            0xf1,
            ($profile << 6) | ($frequencyIndex << 2) | ($channelConfig >> 2),
            (($channelConfig & 3) << 6) | (($frameLength >> 11) & 3),
            ($frameLength >> 3) & 0xff,
            (($frameLength & 7) << 5) | 0x1f,
            0xfc
        );
    }
}
