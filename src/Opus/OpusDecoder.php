<?php

namespace Xiaosongshu\Flv2mp4\Opus;

use InvalidArgumentException;
use LogicException;
use Xiaosongshu\Flv2mp4\Opus\Celt\CeltFrameDecoder;

/*
 * Portions follow RFC 6716 Code Components, Copyright (c) 2012 IETF Trust.
 * Redistribution and use in source and binary forms, with or without modification,
 * are permitted provided that this notice is retained. THIS SOFTWARE IS PROVIDED
 * "AS IS", WITHOUT WARRANTY; contributors are not liable for any damages.
 */
final class OpusDecoder
{
    private int $channels;
    private int $sampleRate;
    private ?array $lastPacket = null;
    private int $lastSampleCount = 0;
    private CeltFrameDecoder $celtDecoder;

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
        $this->celtDecoder->reset();
    }

    public function decodeFloat(string $packet): array
    {
        $this->lastPacket = OpusPacketParser::parse($packet);
        $this->lastSampleCount = 0;
        if ($this->lastPacket['mode'] !== 'CELT') {
            throw new LogicException($this->lastPacket['mode'] . ' audio decoding is not implemented; refusing to fabricate PCM');
        }
        if ($this->sampleRate !== 48000 || $this->lastPacket['bandwidth'] !== 'FB'
            || $this->lastPacket['frameDurationSamples'] !== 960 || $this->lastPacket['frameCount'] !== 1
            || ($this->channels !== 1 && $this->channels !== 2)) {
            throw new LogicException(sprintf(
                'Unsupported CELT packet: sampleRate=%d bandwidth=%s codedChannels=%d frameSamples=%d frameCount=%d',
                $this->sampleRate,
                $this->lastPacket['bandwidth'],
                $this->lastPacket['stereo'] ? 2 : 1,
                $this->lastPacket['frameDurationSamples'],
                $this->lastPacket['frameCount']
            ));
        }
        $codedChannels = $this->lastPacket['stereo'] ? 2 : 1;
        $decoded = $this->celtDecoder->decode($this->lastPacket['frames'][0], 960, $codedChannels);
        if ($codedChannels === 1 && $this->channels === 2) {
            $pcm = [];
            for ($i = 0; $i < 960; $i++) {
                $pcm[] = $decoded[$i];
                $pcm[] = $decoded[$i];
            }
        } else {
            $pcm = $decoded;
        }
        $this->lastSampleCount = intdiv(count($pcm), $this->channels);
        return $pcm;
    }

    public function decodeS16le(string $packet): string
    {
        return Pcm16::floatsToS16le($this->decodeFloat($packet), $this->channels);
    }
}
