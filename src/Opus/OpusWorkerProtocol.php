<?php

namespace Xiaosongshu\Flv2mp4\Opus;

use InvalidArgumentException;
use UnexpectedValueException;

/**
 * @purpose opus转码自定义协议
 * @author yanglong
 * @time 2026年8月12日17:25:08
 */
final class OpusWorkerProtocol
{
    public const OPEN = 1;
    public const PUSH = 2;
    public const AAC = 3;
    public const ERROR = 4;
    public const FINISH = 5;
    public const FINISHED = 6;
    public const MAX_BODY_LENGTH = 1048576;

    public static function frame(string $body): string
    {
        $length = strlen($body);
        if ($length === 0 || $length > self::MAX_BODY_LENGTH) {
            throw new InvalidArgumentException('Invalid Opus worker message length');
        }
        return pack('N', $length) . $body;
    }

    public static function takeFrames(string &$buffer, int $limit = 16): array
    {
        $frames = [];
        while (count($frames) < $limit && strlen($buffer) >= 4) {
            $length = unpack('N', substr($buffer, 0, 4))[1];
            if ($length === 0 || $length > self::MAX_BODY_LENGTH) {
                throw new UnexpectedValueException('Invalid Opus worker frame length');
            }
            if (strlen($buffer) < 4 + $length) {
                break;
            }
            $frames[] = substr($buffer, 4, $length);
            $buffer = substr($buffer, 4 + $length);
        }
        return $frames;
    }

    public static function open(string $streamId, int $bitrate, int $channels): string
    {
        $length = strlen($streamId);
        if ($length === 0 || $length > 1024 || $bitrate < 8000 || $bitrate > 512000 || ($channels !== 1 && $channels !== 2)) {
            throw new InvalidArgumentException('Invalid Opus worker OPEN parameters');
        }
        return self::frame(chr(self::OPEN) . pack('nNC', $length, $bitrate, $channels) . $streamId);
    }

    public static function push(int $requestId, int $sequence, int $timestamp, string $payload): string
    {
        if ($payload === '' || strlen($payload) > 65535) {
            throw new InvalidArgumentException('Invalid Opus payload length');
        }
        return self::frame(chr(self::PUSH) . pack('NnN', $requestId, $sequence, $timestamp) . $payload);
    }

    public static function finish(): string
    {
        return self::frame(chr(self::FINISH));
    }

    public static function aac(int $requestId, int $firstFrame, string $adts): string
    {
        return self::frame(chr(self::AAC) . pack('NN', $requestId, $firstFrame) . $adts);
    }

    public static function error(int $requestId, string $message): string
    {
        $message = substr($message, 0, 4096);
        return self::frame(chr(self::ERROR) . pack('N', $requestId) . $message);
    }

    public static function finished(): string
    {
        return self::frame(chr(self::FINISHED));
    }
}
