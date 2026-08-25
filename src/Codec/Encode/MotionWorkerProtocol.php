<?php
namespace Xiaosongshu\Flv2mp4\Codec\Encode;

use InvalidArgumentException;
use UnexpectedValueException;

/**
 * @purpose 运动模块分布式计算-协议
 * @author yanglong
 */
final class MotionWorkerProtocol
{
    public const MAX_BODY_LENGTH = 16777216;
    public const LOAD_REFERENCE = 1;
    public const JOB_BATCH = 2;
    private const REQUEST_MAGIC = 'MWR2';
    private const RESPONSE_MAGIC = 'MWS1';
    private const FRAME_ID_LENGTH = 32;
    private const JOB_LENGTH = 272;
    private const RESULT_LENGTH = 1452;

    public static function frame(string $body): string
    {
        $length = strlen($body);
        if ($length < 1 || $length > self::MAX_BODY_LENGTH) throw new InvalidArgumentException('Invalid motion worker frame');
        return pack('N', $length) . $body;
    }

    public static function takeFrames(string &$buffer, int $limit = 16): array
    {
        $frames = [];
        while (count($frames) < $limit && strlen($buffer) >= 4) {
            $length = unpack('N', substr($buffer, 0, 4))[1];
            if ($length < 1 || $length > self::MAX_BODY_LENGTH) throw new UnexpectedValueException('Invalid motion worker length');
            if (strlen($buffer) < $length + 4) break;
            $frames[] = substr($buffer, 4, $length);
            $buffer = substr($buffer, 4 + $length);
        }
        return $frames;
    }

    public static function referenceId(int $width, int $height, int $alignedWidth, int $alignedHeight, string $refY, string $refU, string $refV): string
    {
        return hash('sha256', pack('N4', $width, $height, $alignedWidth, $alignedHeight) . $refY . $refU . $refV, true);
    }

    public static function loadReference(string $frameId, int $width, int $height, int $alignedWidth, int $alignedHeight, string $refY, string $refU, string $refV): string
    {
        self::validateFrameId($frameId);
        $chromaLength = intdiv($alignedWidth, 2) * intdiv($alignedHeight, 2);
        if (strlen($refY) !== $alignedWidth * $alignedHeight || strlen($refU) !== $chromaLength || strlen($refV) !== $chromaLength) {
            throw new InvalidArgumentException('Invalid motion worker reference planes');
        }
        return self::frame(self::REQUEST_MAGIC . chr(self::LOAD_REFERENCE) . "\0\0\0" . $frameId . pack('N4', $width, $height, $alignedWidth, $alignedHeight) . $refY . $refU . $refV);
    }

    public static function batch(int $id, string $frameId, int $qp, array $blocks): string
    {
        self::validateFrameId($frameId);
        $body = self::REQUEST_MAGIC . chr(self::JOB_BATCH) . "\0\0\0" . $frameId . pack('N3', $id, $qp, count($blocks));
        foreach ($blocks as $index => $job) {
            if (strlen($job[2]) !== 256) throw new InvalidArgumentException('Invalid motion worker luma block');
            $body .= pack('N4', $index, $job[0], $job[1], $job[3]) . $job[2];
        }
        return self::frame($body);
    }

    public static function decodeRequest(string $body): array
    {
        if (strlen($body) < 40 || substr($body, 0, 4) !== self::REQUEST_MAGIC) throw new UnexpectedValueException('Invalid motion worker request');
        $type = ord($body[4]);
        $frameId = substr($body, 8, self::FRAME_ID_LENGTH);
        if ($type === self::LOAD_REFERENCE) {
            if (strlen($body) < 56) throw new UnexpectedValueException('Invalid motion worker reference');
            $header = unpack('Nwidth/Nheight/Naw/Nah', substr($body, 40, 16));
            $chromaLength = intdiv($header['aw'], 2) * intdiv($header['ah'], 2);
            $referenceLength = $header['aw'] * $header['ah'] + 2 * $chromaLength;
            if (strlen($body) !== 56 + $referenceLength) throw new UnexpectedValueException('Invalid motion worker reference length');
            $offset = 56;
            $refY = substr($body, $offset, $header['aw'] * $header['ah']);
            $offset += $header['aw'] * $header['ah'];
            $refU = substr($body, $offset, $chromaLength);
            $refV = substr($body, $offset + $chromaLength, $chromaLength);
            if (!hash_equals($frameId, self::referenceId($header['width'], $header['height'], $header['aw'], $header['ah'], $refY, $refU, $refV))) {
                throw new UnexpectedValueException('Motion worker frame id mismatch');
            }
            return [$type, $frameId, $header['width'], $header['height'], $header['aw'], $header['ah'], $refY, $refU, $refV];
        }
        if ($type !== self::JOB_BATCH || strlen($body) < 52) throw new UnexpectedValueException('Invalid motion worker request type');
        $header = unpack('Nid/Nqp/Ncount', substr($body, 40, 12));
        if (strlen($body) !== 52 + $header['count'] * self::JOB_LENGTH) throw new UnexpectedValueException('Invalid motion worker batch length');
        $blocks = [];
        $offset = 52;
        for ($i = 0; $i < $header['count']; $i++) {
            $job = unpack('Nindex/Nx/Ny/Nrange', substr($body, $offset, 16));
            $blocks[$job['index']] = [$job['x'], $job['y'], substr($body, $offset + 16, 256), $job['range']];
            $offset += self::JOB_LENGTH;
        }
        return [$type, $frameId, $header['id'], $header['qp'], $blocks];
    }

    public static function response(int $id, array $results): string
    {
        $body = self::RESPONSE_MAGIC . pack('NCx3N', $id, 1, count($results));
        foreach ($results as $index => $result) {
            [$mvX, $mvY, $sad, $cbpLuma, $nzCache, $quantResidual, $reconY, $reconU, $reconV] = $result;
            if (count($nzCache) !== 24 || strlen($reconY) !== 256 || strlen($reconU) !== 64 || strlen($reconV) !== 64) throw new InvalidArgumentException('Invalid motion worker result');
            $body .= pack('N5', $index, $mvX, $mvY, $sad, $cbpLuma);
            foreach ($nzCache as $value) $body .= chr($value);
            for ($block = 0; $block < 16; $block++) {
                if (!isset($quantResidual[$block]) || count($quantResidual[$block]) !== 16) throw new InvalidArgumentException('Invalid motion worker residual');
                foreach ($quantResidual[$block] as $value) $body .= pack('N', $value);
            }
            $body .= $reconY . $reconU . $reconV;
        }
        return self::frame($body);
    }

    public static function error(int $id, string $message): string
    {
        return self::frame(self::RESPONSE_MAGIC . pack('NCx3N', $id, 0, strlen($message)) . $message);
    }

    public static function decodeResponse(string $body): array
    {
        if (strlen($body) < 16 || substr($body, 0, 4) !== self::RESPONSE_MAGIC) throw new UnexpectedValueException('Invalid motion worker response');
        $header = unpack('Nid/Cok/x3/Ncount', substr($body, 4, 12));
        if (!$header['ok']) {
            if (strlen($body) !== 16 + $header['count']) throw new UnexpectedValueException('Invalid motion worker error length');
            return [$header['id'], false, substr($body, 16)];
        }
        if (strlen($body) !== 16 + $header['count'] * self::RESULT_LENGTH) throw new UnexpectedValueException('Invalid motion worker response length');
        $results = [];
        $offset = 16;
        for ($i = 0; $i < $header['count']; $i++) {
            $values = unpack('Nindex/NmvX/NmvY/Nsad/Ncbp', substr($body, $offset, 20));
            $offset += 20;
            $nzCache = array_values(unpack('C24', substr($body, $offset, 24)));
            $offset += 24;
            $quantResidual = [];
            for ($block = 0; $block < 16; $block++) {
                $quantResidual[$block] = [];
                foreach (unpack('N16', substr($body, $offset, 64)) as $value) $quantResidual[$block][] = self::signed($value);
                $offset += 64;
            }
            $reconY = substr($body, $offset, 256);
            $reconU = substr($body, $offset + 256, 64);
            $reconV = substr($body, $offset + 320, 64);
            $offset += 384;
            $results[$values['index']] = [self::signed($values['mvX']), self::signed($values['mvY']), self::signed($values['sad']), self::signed($values['cbp']), $nzCache, $quantResidual, $reconY, $reconU, $reconV];
        }
        return [$header['id'], true, $results];
    }

    private static function validateFrameId(string $frameId): void
    {
        if (strlen($frameId) !== self::FRAME_ID_LENGTH) throw new InvalidArgumentException('Invalid motion worker frame id');
    }

    private static function signed(int $value): int
    {
        return $value >= 0x80000000 ? $value - 0x100000000 : $value;
    }
}
