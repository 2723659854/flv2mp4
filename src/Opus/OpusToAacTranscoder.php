<?php

namespace Xiaosongshu\Flv2mp4\Opus;

use InvalidArgumentException;
use LogicException;
use Xiaosongshu\Flv2mp4\Aac\AacLcEncoder;

final class OpusToAacTranscoder
{
    private const CHANNELS = 2;
    private const SAMPLE_RATE = 48000;

    private OpusDecoder $decoder;
    private AacLcEncoder $encoder;
    private array $pcmFifo = [];
    private bool $finished = false;

    public function __construct(int $bitrate = 128000)
    {
        $this->decoder = new OpusDecoder(self::CHANNELS, self::SAMPLE_RATE);
        $this->encoder = new AacLcEncoder($bitrate);
    }

    public static function transcodeOgg(string $oggData, int $bitrate = 128000): string
    {
        $reader = new OggOpusReader($oggData);
        $head = $reader->head();
        if ($reader->channels() !== self::CHANNELS || $head['mappingFamily'] !== 0) {
            throw new LogicException('Ogg Opus transcoding currently supports mapping-family-0 stereo only');
        }

        $transcoder = new self($bitrate);
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

    public function finish(): string
    {
        if ($this->finished) {
            return '';
        }
        $this->finished = true;

        $output = '';
        if ($this->pcmFifo !== []) {
            $output .= $this->encoder->encodeFloat($this->pcmFifo);
            $this->pcmFifo = [];
        }
        return $output . $this->encoder->flush();
    }

    public function aacFrameCount(): int
    {
        return $this->encoder->frameCount();
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

        $start = $trimStart * self::CHANNELS;
        $length = ($decodedSamples - $trimStart - $trimEnd) * self::CHANNELS;
        $pcm = array_slice($pcm, $start, $length);
        if ($gainQ8 !== 0) {
            $gain = pow(10.0, $gainQ8 / (20.0 * 256.0));
            foreach ($pcm as &$sample) {
                $sample *= $gain;
            }
            unset($sample);
        }
        array_push($this->pcmFifo, ...$pcm);

        $output = '';
        $frameSize = AacLcEncoder::FRAME_SAMPLES * self::CHANNELS;
        while (count($this->pcmFifo) >= $frameSize) {
            $output .= $this->encoder->encodeFloat(array_splice($this->pcmFifo, 0, $frameSize));
        }
        return $output;
    }
}
