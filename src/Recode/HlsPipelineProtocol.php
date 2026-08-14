<?php

namespace Xiaosongshu\Flv2mp4\Recode;

use InvalidArgumentException;
use UnexpectedValueException;

final class HlsPipelineProtocol
{
    public const EVENT = 1;
    public const END = 2;
    public const FINISHED = 3;
    public const ERROR = 4;
    public const MAX_FRAME_LENGTH = 67108864;
    public const HIGH_WATERMARK = 50331648;
    public const MAX_BUFFER_LENGTH = 67108868;

    public static function frame(int $type, int $sequence, array $metadata = [], string $payload = ''): string
    {
        $json = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $body = chr($type) . pack('NN', $sequence, strlen($json)) . $json . $payload;
        if (strlen($body) > self::MAX_FRAME_LENGTH) {
            throw new InvalidArgumentException('HLS pipeline frame is too large');
        }
        return pack('N', strlen($body)) . $body;
    }

    public static function take(string &$buffer, int $limit = 1): array
    {
        $frames = [];
        while (count($frames) < $limit && strlen($buffer) >= 4) {
            $length = unpack('N', substr($buffer, 0, 4))[1];
            if ($length < 9 || $length > self::MAX_FRAME_LENGTH) {
                throw new UnexpectedValueException('Invalid HLS pipeline frame length');
            }
            if (strlen($buffer) < $length + 4) {
                break;
            }
            $body = substr($buffer, 4, $length);
            $header = unpack('Ctype/Nsequence/NmetadataLength', substr($body, 0, 9));
            if ($header['metadataLength'] > $length - 9) {
                throw new UnexpectedValueException('Invalid HLS pipeline metadata length');
            }
            $json = substr($body, 9, $header['metadataLength']);
            $metadata = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($metadata)) {
                throw new UnexpectedValueException('Invalid HLS pipeline metadata');
            }
            $frames[] = [
                'type' => $header['type'],
                'sequence' => $header['sequence'],
                'metadata' => $metadata,
                'payload' => substr($body, 9 + $header['metadataLength']),
            ];
            $buffer = substr($buffer, $length + 4);
        }
        return $frames;
    }
}
