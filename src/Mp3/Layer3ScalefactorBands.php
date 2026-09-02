<?php

namespace Xiaosongshu\Flv2mp4\Mp3;

final class Layer3ScalefactorBands
{
    /** MPEG-1 Layer III 44.1 kHz long-block band boundaries (576 spectral lines). */
    public const LONG_44100 = [
        0, 4, 8, 12, 16, 20, 24, 30, 36, 44, 52, 62, 74,
        90, 110, 134, 162, 196, 238, 288, 342, 418, 576,
    ];

    public const LONG_48000 = [
        0, 4, 8, 12, 16, 20, 24, 30, 36, 42, 50, 60, 72,
        88, 106, 128, 156, 190, 230, 276, 330, 384, 576,
    ];

    public const LONG_32000 = [
        0, 4, 8, 12, 16, 20, 24, 30, 36, 44, 54, 66, 82,
        102, 126, 156, 194, 240, 296, 364, 448, 550, 576,
    ];

    public static function long(int $sampleRate): array
    {
        return match ($sampleRate) {
            32000 => self::LONG_32000,
            44100 => self::LONG_44100,
            48000 => self::LONG_48000,
            default => throw new \InvalidArgumentException('Unsupported MPEG-1 sample rate'),
        };
    }

    public static function long44100(): array
    {
        return self::LONG_44100;
    }

    public static function long44100Widths(): array
    {
        $widths = [];
        for ($i = 0; $i < count(self::LONG_44100) - 1; $i++) {
            $widths[] = self::LONG_44100[$i + 1] - self::LONG_44100[$i];
        }
        return $widths;
    }
}
