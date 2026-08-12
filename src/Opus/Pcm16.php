<?php

namespace Xiaosongshu\Flv2mp4\Opus;

use InvalidArgumentException;

/**
 * @purpose 16 位 PCM 音频数据工具类
 * @author yanglong
 * @time 2026年8月12日17:26:49
 */
final class Pcm16
{
    public static function clamp(float|int $sample): int
    {
        return max(-32768, min(32767, (int) round($sample)));
    }

    public static function floatToInt(float $sample): int
    {
        if (!is_finite($sample)) {
            return 0;
        }
        return self::clamp($sample * 32768.0);
    }

    public static function floatsToS16le(array $samples, int $channels = 1): string
    {
        self::validateChannels($channels);
        if (count($samples) % $channels !== 0) {
            throw new InvalidArgumentException('Interleaved sample count is not divisible by channels');
        }
        $output = '';
        foreach ($samples as $sample) {
            if (!is_float($sample) && !is_int($sample)) {
                throw new InvalidArgumentException('PCM sample must be numeric');
            }
            $value = self::floatToInt((float) $sample);
            $output .= pack('v', $value & 0xFFFF);
        }
        return $output;
    }

    public static function intsToS16le(array $samples, int $channels = 1): string
    {
        self::validateChannels($channels);
        if (count($samples) % $channels !== 0) {
            throw new InvalidArgumentException('Interleaved sample count is not divisible by channels');
        }
        $output = '';
        foreach ($samples as $sample) {
            if (!is_int($sample) && !is_float($sample)) {
                throw new InvalidArgumentException('PCM sample must be numeric');
            }
            $output .= pack('v', self::clamp($sample) & 0xFFFF);
        }
        return $output;
    }

    public static function s16leToInts(string $pcm, int $channels = 1): array
    {
        self::validateChannels($channels);
        if (strlen($pcm) % (2 * $channels) !== 0) {
            throw new InvalidArgumentException('PCM byte count is not frame-aligned');
        }
        $values = array_values(unpack('v*', $pcm) ?: []);
        foreach ($values as &$value) {
            if ($value >= 0x8000) {
                $value -= 0x10000;
            }
        }
        return $values;
    }

    public static function s16leToFloats(string $pcm, int $channels = 1): array
    {
        return array_map(static fn (int $value): float => $value / 32768.0, self::s16leToInts($pcm, $channels));
    }

    private static function validateChannels(int $channels): void
    {
        if ($channels !== 1 && $channels !== 2) {
            throw new InvalidArgumentException('PCM channels must be mono or stereo');
        }
    }
}
