<?php

namespace Xiaosongshu\Flv2mp4\Opus;

use InvalidArgumentException;

/*
 * Portions follow RFC 6716 Code Components, Copyright (c) 2012 IETF Trust.
 * Redistribution and use in source and binary forms, with or without modification,
 * are permitted provided that this notice is retained. THIS SOFTWARE IS PROVIDED
 * "AS IS", WITHOUT WARRANTY; contributors are not liable for any damages.
 */
final class OpusPacketParser
{
    public const MAX_FRAME_BYTES = 1275;
    public const MAX_FRAMES = 48;
    public const MAX_PACKET_SAMPLES = 5760;

    public static function parse(string $packet): array
    {
        $length = strlen($packet);
        if ($length < 1) {
            throw new InvalidArgumentException('Opus packet is empty');
        }

        $toc = ord($packet[0]);
        $config = $toc >> 3;
        $code = $toc & 3;
        [$mode, $bandwidth, $samples] = self::configuration($config);
        $offset = 1;
        $sizes = [];
        $vbr = false;
        $padding = 0;

        if ($code === 0) {
            $sizes[] = $length - 1;
        } elseif ($code === 1) {
            $payload = $length - 1;
            if (($payload & 1) !== 0) {
                throw new InvalidArgumentException('Code 1 packet has an odd payload length');
            }
            $sizes = [$payload >> 1, $payload >> 1];
        } elseif ($code === 2) {
            $first = self::readFrameLength($packet, $offset, $length);
            $remaining = $length - $offset;
            if ($first > $remaining) {
                throw new InvalidArgumentException('Code 2 first frame exceeds packet');
            }
            $sizes = [$first, $remaining - $first];
            $vbr = true;
        } else {
            if ($offset >= $length) {
                throw new InvalidArgumentException('Code 3 packet lacks frame count');
            }
            $control = ord($packet[$offset++]);
            $count = $control & 0x3F;
            $vbr = ($control & 0x80) !== 0;
            if ($count < 1 || $count > self::MAX_FRAMES) {
                throw new InvalidArgumentException('Invalid Opus frame count');
            }
            if (($control & 0x40) !== 0) {
                do {
                    if ($offset >= $length) {
                        throw new InvalidArgumentException('Truncated Opus padding');
                    }
                    $value = ord($packet[$offset++]);
                    $padding += $value === 255 ? 254 : $value;
                } while ($value === 255);
                if ($padding > $length - $offset) {
                    throw new InvalidArgumentException('Opus padding exceeds packet');
                }
            }
            $payloadEnd = $length - $padding;
            if ($vbr) {
                $sum = 0;
                for ($i = 0; $i < $count - 1; $i++) {
                    $size = self::readFrameLength($packet, $offset, $payloadEnd);
                    $sizes[] = $size;
                    $sum += $size;
                }
                $last = $payloadEnd - $offset - $sum;
                if ($last < 0) {
                    throw new InvalidArgumentException('VBR frame lengths exceed packet');
                }
                $sizes[] = $last;
            } else {
                $payload = $payloadEnd - $offset;
                if ($payload < 0 || $payload % $count !== 0) {
                    throw new InvalidArgumentException('CBR payload is not divisible by frame count');
                }
                $sizes = array_fill(0, $count, intdiv($payload, $count));
            }
        }

        if (count($sizes) * $samples > self::MAX_PACKET_SAMPLES) {
            throw new InvalidArgumentException('Opus packet duration exceeds 120 ms');
        }
        foreach ($sizes as $size) {
            if ($size > self::MAX_FRAME_BYTES) {
                throw new InvalidArgumentException('Opus frame exceeds 1275 bytes');
            }
        }

        $frames = [];
        foreach ($sizes as $size) {
            if ($offset + $size > $length - $padding) {
                throw new InvalidArgumentException('Truncated Opus frame');
            }
            $frames[] = substr($packet, $offset, $size);
            $offset += $size;
        }
        if ($offset !== $length - $padding) {
            throw new InvalidArgumentException('Opus framing leaves unused payload bytes');
        }

        return [
            'toc' => $toc,
            'config' => $config,
            'code' => $code,
            'mode' => $mode,
            'bandwidth' => $bandwidth,
            'stereo' => ($toc & 4) !== 0,
            'frameDurationSamples' => $samples,
            'frameCount' => count($frames),
            'vbr' => $vbr,
            'padding' => $padding,
            'frames' => $frames,
        ];
    }

    private static function readFrameLength(string $packet, int &$offset, int $end): int
    {
        if ($offset >= $end) {
            throw new InvalidArgumentException('Missing Opus frame length');
        }
        $first = ord($packet[$offset++]);
        if ($first < 252) {
            return $first;
        }
        if ($offset >= $end) {
            throw new InvalidArgumentException('Truncated two-byte Opus frame length');
        }
        return $first + (ord($packet[$offset++]) << 2);
    }

    private static function configuration(int $config): array
    {
        if ($config < 12) {
            return ['SILK', ['NB', 'MB', 'WB'][intdiv($config, 4)], [480, 960, 1920, 2880][$config & 3]];
        }
        if ($config < 16) {
            return ['HYBRID', $config < 14 ? 'SWB' : 'FB', ($config & 1) === 0 ? 480 : 960];
        }
        return ['CELT', ['NB', 'WB', 'SWB', 'FB'][intdiv($config - 16, 4)], [120, 240, 480, 960][$config & 3]];
    }
}
