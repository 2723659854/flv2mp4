<?php

namespace Xiaosongshu\Flv2mp4\Opus;

use InvalidArgumentException;
use LogicException;
use Xiaosongshu\Flv2mp4\Opus\Celt\CeltFrameDecoder;

/**
 * @purpose Opus 解码器
 * @author yanglong
 * @time 2026年8月12日17:22:23
 */
final class OpusDecoder
{
    private int $channels;
    private int $sampleRate;
    private ?array $lastPacket = null;
    private int $lastSampleCount = 0;
    private CeltFrameDecoder $celtDecoder;
    private int $skipRemaining = 0;
    private float $outputGain = 1.0;

    /**
     * CELT 带宽 → 编码带上限（end band）。取值逐项对应 FFmpeg libavcodec/opus/tab.c
     * 的 ff_celt_band_end[] = {13, 17, 17, 19, 21}（NB/MB/WB/SWB/FB）。
     */
    private const BAND_END = ['NB' => 13, 'WB' => 17, 'SWB' => 19, 'FB' => 21];

    public function __construct(int $channels = 2, int $sampleRate = 48000)
    {
        if (($channels !== 1 && $channels !== 2) || !in_array($sampleRate, [8000, 12000, 16000, 24000, 48000], true)) {
            throw new InvalidArgumentException('Unsupported Opus output format');
        }
        $this->channels = $channels;
        $this->sampleRate = $sampleRate;
        $this->celtDecoder = new CeltFrameDecoder();
    }

    public function channels(): int
    {
        return $this->channels;
    }

    public function sampleRate(): int
    {
        return $this->sampleRate;
    }

    public function lastPacket(): ?array
    {
        return $this->lastPacket;
    }

    public function lastSampleCount(): int
    {
        return $this->lastSampleCount;
    }

    public function debugCeltFrame(): ?array
    {
        return $this->celtDecoder->debugFrame();
    }

    public function reset(): void
    {
        $this->lastPacket = null;
        $this->lastSampleCount = 0;
        $this->skipRemaining = 0;
        $this->celtDecoder->reset();
    }

    /** 设置 dOps/OpusHead 的 pre-skip（48 kHz 样本数），从解码输出开头丢弃 */
    public function setPreSkip(int $samples): void
    {
        $this->skipRemaining = max(0, $samples);
    }

    /** 设置 dOps/OpusHead 的 output gain（Q7.7，1/256 dB） */
    public function setOutputGain(int $q7_7): void
    {
        $this->outputGain = (float)(10 ** ($q7_7 / (256.0 * 20.0)));
    }

    public function decodeFloat(string $packet): array
    {
        $this->lastPacket = OpusPacketParser::parse($packet);
        $this->lastSampleCount = 0;
        if ($this->lastPacket['mode'] !== 'CELT') {
            throw new LogicException($this->lastPacket['mode'] . ' audio decoding is not implemented; refusing to fabricate PCM');
        }
        if ($this->sampleRate !== 48000) {
            throw new LogicException(sprintf('Unsupported Opus output rate: %d (only 48000)', $this->sampleRate));
        }
        $frameSamples = $this->lastPacket['frameDurationSamples'];
        if (!in_array($frameSamples, [120, 240, 480, 960], true)) {
            throw new LogicException('Unsupported CELT frame size: ' . $frameSamples);
        }
        $endBand = self::BAND_END[$this->lastPacket['bandwidth']] ?? 21;
        $codedChannels = $this->lastPacket['stereo'] ? 2 : 1;
        $frames = $this->lastPacket['frames'];
        if (count($frames) !== $this->lastPacket['frameCount']) {
            throw new LogicException('Opus packet frame count mismatch');
        }

        $pcm = [];
        foreach ($frames as $frame) {
            $decoded = $this->celtDecoder->decode($frame, $frameSamples, $codedChannels, $endBand);
            if ($codedChannels === 1 && $this->channels === 2) {
                for ($i = 0; $i < $frameSamples; $i++) {
                    $pcm[] = $decoded[$i];
                    $pcm[] = $decoded[$i];
                }
            } elseif ($codedChannels === 2 && $this->channels === 1) {
                for ($i = 0; $i < $frameSamples; $i++) {
                    $pcm[] = ($decoded[$i * 2] + $decoded[$i * 2 + 1]) * 0.5;
                }
            } else {
                foreach ($decoded as $sample) {
                    $pcm[] = $sample;
                }
            }
        }

        if ($this->outputGain !== 1.0) {
            foreach ($pcm as $i => $sample) {
                $pcm[$i] = $sample * $this->outputGain;
            }
        }
        if ($this->skipRemaining > 0) {
            $drop = min($this->skipRemaining, intdiv(count($pcm), $this->channels));
            if ($drop > 0) {
                $pcm = array_slice($pcm, $drop * $this->channels);
                $this->skipRemaining -= $drop;
            }
        }
        $this->lastSampleCount = intdiv(count($pcm), $this->channels);
        return $pcm;
    }

    public function decodeS16le(string $packet): string
    {
        return Pcm16::floatsToS16le($this->decodeFloat($packet), $this->channels);
    }
}
