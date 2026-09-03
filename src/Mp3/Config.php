<?php

namespace Xiaosongshu\Flv2mp4\Mp3;

use InvalidArgumentException;

/**
 * @purpose mp3配置
 * @author yanglong
 * @time 2026年9月3日16:24:43
 */
final class Config
{
    public const SAMPLE_RATES = [44100, 48000, 32000];
    public const BITRATES = [32000, 40000, 48000, 56000, 64000, 80000, 96000, 112000, 128000, 160000, 192000, 224000, 256000, 320000];

    public function __construct(
        public readonly int $sampleRate = 44100,
        public readonly int $channels = 1,
        public readonly int $bitrate = 128000
    ) {
        if (!in_array($sampleRate, self::SAMPLE_RATES, true)) {
            throw new InvalidArgumentException('MPEG-1 MP3 sample rate must be 32000, 44100 or 48000 Hz');
        }
        if ($channels !== 1 && $channels !== 2) {
            throw new InvalidArgumentException('MP3 channel count must be 1 or 2');
        }
        if (!in_array($bitrate, self::BITRATES, true)) {
            throw new InvalidArgumentException('Unsupported MPEG-1 Layer III bitrate');
        }
    }
}
