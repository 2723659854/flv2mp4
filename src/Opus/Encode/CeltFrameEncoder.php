<?php

namespace Xiaosongshu\Flv2mp4\Opus\Encode;

use InvalidArgumentException;
use Xiaosongshu\Flv2mp4\Opus\Celt\CeltBitAllocation;
use Xiaosongshu\Flv2mp4\Opus\Celt\CeltMdct;
use Xiaosongshu\Flv2mp4\Opus\Celt\CeltTables;
use Xiaosongshu\Flv2mp4\Opus\RangeDecoder;
use Xiaosongshu\Flv2mp4\Opus\Celt\CeltEnergy;
use Xiaosongshu\Flv2mp4\Opus\Encode\CeltPvqEncoder;

/**
 * CELT 帧编码器入口（当前仅建立受限格式的安全边界）。
 */
final class CeltFrameEncoder
{
    public const SAMPLE_RATE = 48000;
    public const FRAME_SAMPLES = 960;
    public const MAX_FRAME_BYTES = 1275;

    private const MEAN_ENERGY = [
        6.4375, 6.25, 5.75, 5.3125, 5.0625, 4.8125, 4.5, 4.375, 4.875, 4.6875, 4.5625,
        4.4375, 4.875, 4.625, 4.3125, 4.5, 4.375, 4.625, 4.75, 4.4375, 3.75,
    ];

    private int $channels;
    private ?array $debugEnergies = null;

    private const ENERGY_MODEL_LM3_INTRA = [
        [22,178],[63,114],[74,82],[84,83],[92,82],[103,62],[96,72],[96,67],[101,73],[107,72],[113,55],
        [118,52],[125,52],[118,52],[117,55],[135,49],[137,39],[157,32],[145,29],[97,33],[77,40],
    ];

    private const INTRA_BETA = 1 - 4915 / 32768;

    public function __construct(int $channels = 2)
    {
        if ($channels !== 1 && $channels !== 2) {
            throw new InvalidArgumentException('CELT encoder supports only mono or stereo');
        }
        $this->channels = $channels;
    }

    public function channels(): int
    {
        return $this->channels;
    }

    public function sampleRate(): int
    {
        return self::SAMPLE_RATE;
    }

    /**
     * Encode one interleaved 20 ms PCM float frame into the CELT elementary payload.
     *
     * @param float[] $pcm Samples in [-1.0, 1.0], interleaved when stereo.
     */
    public function encodeFrame(array $pcm): string
    {
        $expected = self::FRAME_SAMPLES * $this->channels;
        if (count($pcm) !== $expected) {
            throw new InvalidArgumentException(sprintf(
                'CELT frame must contain %d interleaved float samples, got %d',
                $expected,
                count($pcm)
            ));
        }
        foreach ($pcm as $sample) {
            if (!is_float($sample) && !is_int($sample)) {
                throw new InvalidArgumentException('CELT PCM samples must be finite numbers');
            }
            if (!is_finite((float) $sample) || $sample < -1.0 || $sample > 1.0) {
                throw new InvalidArgumentException('CELT PCM samples must be finite and within [-1, 1]');
            }
        }

        if ($this->isSilent($pcm)) {
            $silent = new RangeEncoder();
            $silent->encodeBitLogp(1, 15);
            return $silent->finish();
        }

        $analysis = array_merge(array_fill(0, self::FRAME_SAMPLES, 0.0), $pcm);
        $spectrum = CeltMdct::forward($analysis);
        $energies = [];
        for ($band = 0; $band < 21; $band++) {
            $start = CeltBitAllocation::BAND_EDGES[$band] << 3;
            $length = CeltBitAllocation::BAND_WIDTHS[$band] << 3;
            $sum = 1.0e-12;
            for ($i = 0; $i < $length; $i++) $sum += $spectrum[$start + $i] ** 2;
            $energies[$band] = (int) max(-28, min(28, round(log(sqrt($sum), 2) - self::MEAN_ENERGY[$band] + 4.4)));
        }
        $this->debugEnergies = $energies;

        $encoder = new RangeEncoder();
        $this->encodeHeader($encoder, $energies);
        // Keep the profile deliberately fixed. The allocation decoder is the
        // normative source of the per-band pulse/fine-bit decisions.
        // 128 kb/s at 48 kHz with a 20 ms frame is 320 bytes. Using the
        // maximum 1275-byte CELT payload over-allocates PVQ pulses and can
        // make the PHP encoder spend an excessive amount of time quantizing.
        $targetBytes = 320;
        // 固定参数验证路径：暂不调用 allocationForBudget()，避免编码前重复构造
        // RangeDecoder 探测。先只编码前 8 个频带，每个频带使用极少量 PVQ
        // 比特；该路径用于验证单帧位流顺序，不代表最终码率分配。
        $allocation = $this->allocationForBudget($energies, $targetBytes * 8);
        // 原始位从帧尾按解码消费顺序读取：fine、n=1 符号、anti-collapse、final。
        $n1Signs = [];
        for ($band = 0; $band < $allocation['coded']; $band++) {
            $n = CeltBitAllocation::BAND_WIDTHS[$band] << 3;
            $bits = $allocation['pulses'][$band];
            $q = CeltTables::bitsToPulses($band, 3, $bits);
            $k = CeltTables::pulseCount($q);
            if ($n === 1) {
                if ($bits >= 8) $n1Signs[] = $spectrum[CeltBitAllocation::BAND_EDGES[$band] << 3] < 0 ? 1 : 0;
                continue;
            }
            if ($k > 0) CeltPvqEncoder::encode($encoder, $this->quantizeBand($spectrum, $band, $n, $k), $k);
        }
        // 原始位从帧尾按解码消费顺序读取：fine、n=1 符号、anti-collapse、final。
        for ($band = 0; $band < 21; $band++) {
            $bits = $allocation['fine'][$band];
            if ($bits > 0) $encoder->encodeBits(1 << ($bits - 1), $bits);
        }
        foreach ($n1Signs as $sign) $encoder->encodeBits($sign, 1);
        if ($allocation['anti'] !== 0) $encoder->encodeBits(0, 1);
        for ($pass = 0; $pass < 2; $pass++) {
            for ($band = 0; $band < 21; $band++) {
                if ($allocation['priority'][$band] === $pass && $allocation['fine'][$band] < 8) $encoder->encodeBits(0, 1);
            }
        }
        $frame = $encoder->finish($targetBytes);
        return $frame;
    }

    public function debugEnergies(): ?array
    {
        return $this->debugEnergies;
    }

    private function encodeHeader(RangeEncoder $encoder, array $energies): void
    {
        // The first bit distinguishes the CELT silence packet.
        $encoder->encodeBitLogp(0, 15);
        // Then mirror CeltFrameDecoder::decodePostfilter(), transient, intra.
        $encoder->encodeBitLogp(0, 1);
        $encoder->encodeBitLogp(0, 3);
        $encoder->encodeBitLogp(1, 3);
        $prediction = 0.0;
        foreach ($energies as $band => $energy) {
            [$probability, $decay] = self::ENERGY_MODEL_LM3_INTRA[$band];
            $delta = (int) round($energy - $prediction);
            $encoder->encodeLaplace($delta, $probability << 7, $decay << 6);
            $prediction += self::INTRA_BETA * $delta;
        }
        for ($band = 0; $band < 21; $band++) {
            $encoder->encodeBitLogp(0, $band === 0 ? 4 : 5);
        }
        $encoder->encodeCdf([25, 23, 2, 0], 2, 5);
        for ($band = 0; $band < 21; $band++) $encoder->encodeBitLogp(0, 6);
        $encoder->encodeCdf([126, 124, 119, 109, 87, 41, 19, 9, 4, 2, 0], 5, 7);
        for ($band = 20; $band >= 1; $band--) $encoder->encodeBitLogp(0, 1);
    }

    private function allocationForBudget(array $energies, int $budget): array
    {
        $probe = new RangeEncoder();
        $this->encodeHeader($probe, $energies);
        $data = $probe->finish();
        $probeBytes = intdiv($budget + 7, 8);
        // Keep the complete range stream.  Truncating it changes the probe
        // state, and "\\0" would add two literal bytes instead of NULs.
        if (strlen($data) < $probeBytes) $data = str_pad($data, $probeBytes, "\0");
        $decoder = new RangeDecoder($data);
        $decoder->decodeBitLogp(15);
        $decoder->decodeBitLogp(1);
        $decoder->decodeBitLogp(3);
        $decoder->decodeBitLogp(3);
        $energy = new CeltEnergy();
        $energy->decodeCoarse($decoder, 3, 1, true, strlen($data) * 8);
        return CeltBitAllocation::decode($decoder, 3, false, 1, strlen($data) * 8);
    }

    private function quantizeBand(array $spectrum, int $band, int $dimensions, int $pulses): array
    {
        $offset = CeltBitAllocation::BAND_EDGES[$band] << 3;
        $values = array_slice($spectrum, $offset, $dimensions);
        $norm = sqrt(max(1.0e-20, array_sum(array_map(static fn(float $v): float => $v * $v, $values))));
        $vector = array_fill(0, $dimensions, 0);
        for ($pulse = 0; $pulse < $pulses; $pulse++) {
            $best = 0; $score = -1.0;
            foreach ($values as $i => $value) {
                $candidate = abs($value) / $norm - abs($vector[$i]) / max(1, $pulses);
                if ($candidate > $score) { $score = $candidate; $best = $i; }
            }
            $vector[$best] += ($values[$best] < 0.0 ? -1 : 1);
        }
        return $vector;
    }

    private function isSilent(array $pcm): bool
    {
        foreach ($pcm as $sample) {
            if ((float) $sample !== 0.0) {
                return false;
            }
        }
        return true;
    }

    /**
     * Wrap an elementary CELT payload in a single-frame Opus packet header.
     */
    public function makeOpusPacket(string $celtFrame): string
    {
        $length = strlen($celtFrame);
        if ($length < 1 || $length > self::MAX_FRAME_BYTES) {
            throw new InvalidArgumentException('CELT payload must contain 1..1275 bytes');
        }

        $config = 31;
        $toc = ($config << 3) | ($this->channels === 2 ? 4 : 0);
        return chr($toc) . $celtFrame;
    }

    /**
     * Convenience method for callers that need a complete one-frame Opus packet.
     *
     * @param float[] $pcm
     */
    public function encodePacket(array $pcm): string
    {
        return $this->makeOpusPacket($this->encodeFrame($pcm));
    }
}
