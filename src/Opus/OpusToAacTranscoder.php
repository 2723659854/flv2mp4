<?php

namespace Xiaosongshu\Flv2mp4\Opus;

use InvalidArgumentException;
use LogicException;
use Xiaosongshu\Flv2mp4\Aac\AacLcEncoder;

/**
 * @purpose opus转aac核心类
 * @author yanglong
 * @time 2026年8月12日17:23:59
 */
class OpusToAacTranscoder
{
    private const SAMPLE_RATE = 48000;
    private const MAX_SILENCE_SAMPLES = 14400000;
    private const SILENCE_CHUNK_SAMPLES = 1024;

    private int $channels;
    private OpusDecoder $decoder;
    private AacLcEncoder $encoder;
    private bool $finished = false;

    public function __construct(int $bitrate = 128000, int $channels = 2)
    {
        if ($channels !== 1 && $channels !== 2) {
            throw new InvalidArgumentException('AAC channel count must be 1 or 2');
        }
        $this->channels = $channels;
        $this->decoder = new OpusDecoder($channels, self::SAMPLE_RATE);
        $this->encoder = new AacLcEncoder($bitrate, $channels);
    }

    public static function transcodeOgg(string $oggData, int $bitrate = 128000): string
    {
        $reader = new OggOpusReader($oggData);
        $head = $reader->head();
        if (($reader->channels() !== 1 && $reader->channels() !== 2) || $head['mappingFamily'] !== 0) {
            throw new LogicException('Ogg Opus transcoding currently supports mapping-family-0 mono or stereo only');
        }

        $transcoder = new self($bitrate, $reader->channels());
        $output = '';
        foreach ($reader->audioPackets() as $packet) {
            $output .= $transcoder->pushPacketTrimmed(
                $packet['data'],
                $packet['durationSamples'],
                $packet['trimStartSamples'],
                $packet['trimEndSamples'],
                $head['outputGainQ8']
            );
        }
        return $output . $transcoder->finish();
    }

    public function pushPacket(string $packet, ?int $sampleCount = null): string
    {
        return $this->pushPacketTrimmed($packet, $sampleCount, 0, 0, 0);
    }

    public function pushSilence(int $sampleCount): string
    {
        if ($this->finished) {
            throw new LogicException('Cannot push silence after finish');
        }
        if ($sampleCount <= 0 || $sampleCount > self::MAX_SILENCE_SAMPLES) {
            throw new InvalidArgumentException('Silence sample count is outside the supported range');
        }

        $output = '';
        while ($sampleCount > 0) {
            $chunkSamples = min($sampleCount, self::SILENCE_CHUNK_SAMPLES);
            $output .= $this->encoder->encodeFloat(array_fill(0, $chunkSamples * $this->channels, 0.0));
            $sampleCount -= $chunkSamples;
        }
        return $output;
    }

    public function finish(): string
    {
        if ($this->finished) {
            return '';
        }
        $this->finished = true;

        return $this->encoder->flush();
    }

    public function aacFrameCount(): int
    {
        return $this->encoder->frameCount();
    }

    public function getAudioSpecificConfig(): string
    {
        return $this->encoder->getAudioSpecificConfig();
    }

    public function channels(): int
    {
        return $this->channels;
    }

    private function pushPacketTrimmed(
        string $packet,
        ?int $sampleCount,
        int $trimStart,
        int $trimEnd,
        int $gainQ8
    ): string {
        if ($this->finished) {
            throw new LogicException('Cannot push an Opus packet after finish');
        }

        $pcm = $this->decoder->decodeFloat($packet);
        $decodedSamples = $this->decoder->lastSampleCount();
        if ($sampleCount !== null && $sampleCount !== $decodedSamples) {
            throw new InvalidArgumentException("Opus packet sample count is {$decodedSamples}, expected {$sampleCount}");
        }
        if ($trimStart < 0 || $trimEnd < 0 || $trimStart + $trimEnd > $decodedSamples) {
            throw new InvalidArgumentException('Invalid Opus packet trimming');
        }

        $start = $trimStart * $this->channels;
        $length = ($decodedSamples - $trimStart - $trimEnd) * $this->channels;
        if ($start !== 0 || $length !== count($pcm)) {
            $pcm = array_slice($pcm, $start, $length);
        }
        if ($gainQ8 !== 0) {
            $gain = pow(10.0, $gainQ8 / (20.0 * 256.0));
            foreach ($pcm as &$sample) {
                $sample *= $gain;
            }
            unset($sample);
        }
        return $this->encoder->encodeFloat($pcm);
    }
}
