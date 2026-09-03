<?php

namespace Xiaosongshu\Flv2mp4\Mp3;

/**
 * @purpose 音频帧头
 * @author yanglong
 * @time 2026年9月3日16:26:26
 */
final class FrameHeader
{
    public static function encode(Config $config, bool $padding): string
    {
        $bitrateIndex = array_search($config->bitrate, Config::BITRATES, true) + 1;
        $sampleIndex = array_search($config->sampleRate, Config::SAMPLE_RATES, true);
        $mode = $config->channels === 1 ? 3 : 0;
        $value = (0x7ff << 21) | (3 << 19) | (1 << 17) | (1 << 16) | ($bitrateIndex << 12)
            | ($sampleIndex << 10) | (($padding ? 1 : 0) << 9) | ($mode << 6);
        return pack('N', $value);
    }

    public static function frameLength(Config $config, bool $padding): int
    {
        return intdiv(144 * $config->bitrate, $config->sampleRate) + ($padding ? 1 : 0);
    }
}
