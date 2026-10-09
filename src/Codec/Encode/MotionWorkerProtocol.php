<?php
namespace Xiaosongshu\Flv2mp4\Codec\Encode;

use InvalidArgumentException;
use UnexpectedValueException;

/**
 * @purpose 运动模块分布式计算-协议（v6：色度条带恒定携带，移除色度快速档标志）
 * @author yanglong
 */
final class MotionWorkerProtocol
{
    public const MAX_BODY_LENGTH = 16777216;
    public const LOAD_REFERENCE = 1;
    public const JOB_BATCH = 2;
    private const REQUEST_MAGIC = 'MWR6';
    private const RESPONSE_MAGIC = 'MWS3';
    private const SEQ_LENGTH = 4;
    private const JOB_META_LENGTH = 16;
    private const FLAG_HAS_LUMA_RESIDUAL = 0x01;
    private const FLAG_HAS_CHROMA_RESIDUAL = 0x02;

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

    public static function loadReference(int $seq, int $width, int $height, int $alignedWidth, int $alignedHeight, string $refY, string $refU, string $refV): string
    {
        self::validateSeq($seq);
        $chromaLength = intdiv($alignedWidth, 2) * intdiv($alignedHeight, 2);
        if (strlen($refY) !== $alignedWidth * $alignedHeight || strlen($refU) !== $chromaLength || strlen($refV) !== $chromaLength) {
            throw new InvalidArgumentException('Invalid motion worker reference planes');
        }
        return self::frame(self::REQUEST_MAGIC . chr(self::LOAD_REFERENCE) . "\0\0\0" . pack('N', $seq) . pack('N4', $width, $height, $alignedWidth, $alignedHeight) . $refY . $refU . $refV);
    }

    /**
     * BATCH 请求（v6）：
     * 亮度按宏块行发送连续的 16 行条带；色度条带恒定携带，
     * 布局为每宏块行 8 行 U 紧接 8 行 V（各 8*cw 字节，cw=aw/2），总长 stripCount*8*aw。
     *
     * @param array  $jobs         job 索引 => [x, y, range]（y 为全帧绝对宏块行）
     * @param string $strips       亮度条带拼接串（stripCount 个，每条 16*aw 字节）
     * @param int    $stripOffset  首条带对应的绝对宏块行
     * @param int    $stripCount   条带数
     * @param int    $aw           宏块对齐宽度（条带跨距）
     * @param string $chromaStrips 色度条带拼接串（stripCount*8*aw 字节）
     */
    public static function batch(int $id, int $seq, int $qp, array $jobs, string $strips, int $stripOffset, int $stripCount, int $aw, string $chromaStrips): string
    {
        self::validateSeq($seq);
        if ($stripCount < 0 || $stripOffset < 0 || $aw <= 0 || strlen($strips) !== $stripCount * 16 * $aw) {
            throw new InvalidArgumentException('Invalid motion worker strips');
        }
        $chromaStripsLength = $stripCount * 8 * $aw;
        if (strlen($chromaStrips) !== $chromaStripsLength) {
            throw new InvalidArgumentException('Invalid motion worker chroma strips');
        }
        $body = self::REQUEST_MAGIC . chr(self::JOB_BATCH) . "\0\0\0" . pack('N', $seq)
            . pack('N6', $id, $qp, count($jobs), $stripOffset, $stripCount, $aw) . $strips;
        $body .= $chromaStrips;
        // job 元数据批量打包（index,x,y,range），避免每 job 一次 pack 调用
        $flat = [];
        foreach ($jobs as $index => $job) {
            if (!isset($job[0], $job[1], $job[2])) throw new InvalidArgumentException('Invalid motion worker job');
            $flat[] = $index;
            $flat[] = $job[0];
            $flat[] = $job[1];
            $flat[] = $job[2];
        }
        if ($flat !== []) $body .= pack('N*', ...$flat);
        return self::frame($body);
    }

    public static function decodeRequest(string $body): array
    {
        if (strlen($body) < 12 || substr($body, 0, 4) !== self::REQUEST_MAGIC) throw new UnexpectedValueException('Invalid motion worker request');
        $type = ord($body[4]);
        $seq = unpack('N', substr($body, 8, self::SEQ_LENGTH))[1];
        if ($type === self::LOAD_REFERENCE) {
            if (strlen($body) < 28) throw new UnexpectedValueException('Invalid motion worker reference');
            $header = unpack('Nwidth/Nheight/Naw/Nah', substr($body, 12, 16));
            $chromaLength = intdiv($header['aw'], 2) * intdiv($header['ah'], 2);
            $referenceLength = $header['aw'] * $header['ah'] + 2 * $chromaLength;
            if (strlen($body) !== 28 + $referenceLength) throw new UnexpectedValueException('Invalid motion worker reference length');
            $offset = 28;
            $refY = substr($body, $offset, $header['aw'] * $header['ah']);
            $offset += $header['aw'] * $header['ah'];
            $refU = substr($body, $offset, $chromaLength);
            $refV = substr($body, $offset + $chromaLength, $chromaLength);
            return [$type, $seq, $header['width'], $header['height'], $header['aw'], $header['ah'], $refY, $refU, $refV];
        }
        if ($type !== self::JOB_BATCH || strlen($body) < 36) throw new UnexpectedValueException('Invalid motion worker request type');
        $header = unpack('Nid/Nqp/Ncount/NstripOffset/NstripCount/Naw', substr($body, 12, 24));
        $count = $header['count'];
        $stripOffset = $header['stripOffset'];
        $stripCount = $header['stripCount'];
        $aw = $header['aw'];
        if ($count < 0 || $stripCount < 0 || $stripOffset < 0 || $aw <= 0) throw new UnexpectedValueException('Invalid motion worker batch header');
        $cw = intdiv($aw, 2);
        $stripsLength = $stripCount * 16 * $aw;
        $chromaStripsLength = $stripCount * 16 * $cw; // 每宏块行 8行U+8行V
        if (strlen($body) !== 36 + $stripsLength + $chromaStripsLength + $count * self::JOB_META_LENGTH) {
            throw new UnexpectedValueException('Invalid motion worker batch length');
        }
        // 条带只保留引用（零拷贝），块提取按 job 所在宏块行惰性切片
        $strips = $stripCount > 0 ? substr($body, 36, $stripsLength) : '';
        $chromaStrips = substr($body, 36 + $stripsLength, $chromaStripsLength);
        $blocks = [];
        $offset = 36 + $stripsLength + $chromaStripsLength;
        $stripRows = $stripCount > 0 ? [] : null;
        $cStripRows = $stripCount > 0 ? [] : null;
        for ($i = 0; $i < $count; $i++) {
            $job = unpack('Nindex/Nx/Ny/Nrange', substr($body, $offset, 16));
            $stripIndex = $job['y'] - $stripOffset;
            if ($stripIndex < 0 || $stripIndex >= $stripCount) throw new UnexpectedValueException('Motion worker job outside strips');
            $strip = $stripRows[$stripIndex] ??= substr($strips, $stripIndex * 16 * $aw, 16 * $aw);
            // 16x16 亮度块提取下沉到 worker 端（各 worker 并行）
            $luma = '';
            $base = $job['x'] * 16;
            for ($row = 0; $row < 16; $row++) $luma .= substr($strip, $row * $aw + $base, 16);
            $cu = '';
            $cv = '';
            // 色度条带：前 8*cw 为 U 条，后 8*cw 为 V 条
            $cstrip = $cStripRows[$stripIndex] ??= substr($chromaStrips, $stripIndex * 16 * $cw, 16 * $cw);
            $cbase = $job['x'] * 8;
            for ($row = 0; $row < 8; $row++) {
                $cu .= substr($cstrip, $row * $cw + $cbase, 8);
                $cv .= substr($cstrip, 8 * $cw + $row * $cw + $cbase, 8);
            }
            $blocks[$job['index']] = [$job['x'], $job['y'], $luma, $job['range'], $cu, $cv];
            $offset += self::JOB_META_LENGTH;
        }
        return [$type, $seq, $header['id'], $header['qp'], $blocks, true];
    }

    public static function response(int $id, array $results): string
    {
        $body = self::RESPONSE_MAGIC . pack('NCx3N', $id, 1, count($results));
        foreach ($results as $index => $result) {
            [$mvX, $mvY, $sad, $cbpLuma, $nzCache, $quantResidual, $reconY, $reconU, $reconV] = $result;
            $cbpChroma = $result[9] ?? 0;
            $chromaDc = $result[10] ?? [];
            $chromaAc = $result[11] ?? [];
            if (count($nzCache) !== 24 || strlen($reconY) !== 256 || strlen($reconU) !== 64 || strlen($reconV) !== 64) throw new InvalidArgumentException('Invalid motion worker result');
            $hasLuma = $cbpLuma > 0;
            $hasChroma = $cbpChroma > 0;
            // 第5个N为完整CBP：低4位luma，高2位chroma
            $cbpFull = ($cbpLuma & 0x0F) | (($cbpChroma & 0x03) << 4);
            $body .= pack('N5', $index, $mvX, $mvY, $sad, $cbpFull);
            $flags = ($hasLuma ? self::FLAG_HAS_LUMA_RESIDUAL : 0) | ($hasChroma ? self::FLAG_HAS_CHROMA_RESIDUAL : 0);
            if (!$hasLuma && !$hasChroma) {
                // 全零宏块：主进程不读取 nz/残差，省掉打包传输（与旧瘦身一致）
                $body .= "\0\0\0\0";
            } else {
                // flags + nz(24B)；再按 flag 追加亮度/色度残差
                $body .= pack('Cx3', $flags);
                $body .= pack('C24', ...array_values($nzCache));
                if ($hasLuma) {
                    $flat = [];
                    for ($block = 0; $block < 16; $block++) {
                        if (!isset($quantResidual[$block]) || count($quantResidual[$block]) !== 16) throw new InvalidArgumentException('Invalid motion worker residual');
                        foreach ($quantResidual[$block] as $value) $flat[] = $value;
                    }
                    $body .= pack('N256', ...$flat);
                }
                if ($hasChroma) {
                    // 色度 DC：Cb 4 + Cr 4（8 个）；色度 AC：Cb 4块 + Cr 4块（8*16 个）
                    if (count($chromaDc) !== 8) throw new InvalidArgumentException('Invalid chroma DC payload');
                    $body .= pack('N8', ...$chromaDc);
                    $flatAc = [];
                    for ($block = 0; $block < 8; $block++) {
                        if (!isset($chromaAc[$block]) || count($chromaAc[$block]) !== 16) throw new InvalidArgumentException('Invalid chroma AC payload');
                        foreach ($chromaAc[$block] as $value) $flatAc[] = $value;
                    }
                    $body .= pack('N128', ...$flatAc);
                }
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
        $count = $header['count'];
        $results = [];
        $offset = 16;
        for ($i = 0; $i < $count; $i++) {
            if (strlen($body) < $offset + 24) throw new UnexpectedValueException('Invalid motion worker response header');
            $packed = unpack('N5h/Cflags/x3', substr($body, $offset, 24));
            $index = $packed['h1'];
            $offset += 24;
            $cbpFull = $packed['h5']; if ($cbpFull >= 0x80000000) $cbpFull -= 0x100000000;
            $cbpLuma = $cbpFull & 0x0F;
            $cbpChroma = ($cbpFull >> 4) & 0x03;
            $hasLuma = ($packed['flags'] & self::FLAG_HAS_LUMA_RESIDUAL) !== 0;
            $hasChroma = ($packed['flags'] & self::FLAG_HAS_CHROMA_RESIDUAL) !== 0;
            $nzCache = array_fill(0, 24, 0);
            $quantResidual = [];
            $chromaDc = [];
            $chromaAc = [];
            if ($hasLuma || $hasChroma) {
                if (strlen($body) < $offset + 24) throw new UnexpectedValueException('Invalid motion worker nz payload');
                $extra = unpack('C24z', substr($body, $offset, 24));
                for ($k = 1; $k <= 24; $k++) $nzCache[$k - 1] = $extra['z' . $k];
                $offset += 24;
            }
            if ($hasLuma) {
                if (strlen($body) < $offset + 1024) throw new UnexpectedValueException('Invalid motion worker luma residual payload');
                $extra = unpack('N256r', substr($body, $offset, 1024));
                for ($k = 0; $k < 256; $k++) {
                    $value = $extra['r' . ($k + 1)];
                    if ($value >= 0x80000000) $value -= 0x100000000;
                    $quantResidual[$k >> 4][$k & 15] = $value;
                }
                $offset += 1024;
            }
            if ($hasChroma) {
                if (strlen($body) < $offset + 32 + 512) throw new UnexpectedValueException('Invalid motion worker chroma residual payload');
                $dcPacked = unpack('N8d', substr($body, $offset, 32));
                for ($k = 1; $k <= 8; $k++) {
                    $value = $dcPacked['d' . $k];
                    if ($value >= 0x80000000) $value -= 0x100000000;
                    $chromaDc[$k - 1] = $value;
                }
                $offset += 32;
                $acPacked = unpack('N128a', substr($body, $offset, 512));
                for ($k = 0; $k < 128; $k++) {
                    $value = $acPacked['a' . ($k + 1)];
                    if ($value >= 0x80000000) $value -= 0x100000000;
                    $chromaAc[$k >> 4][$k & 15] = $value;
                }
                $offset += 512;
            }
            if (strlen($body) < $offset + 384) throw new UnexpectedValueException('Invalid motion worker recon payload');
            $reconY = substr($body, $offset, 256);
            $reconU = substr($body, $offset + 256, 64);
            $reconV = substr($body, $offset + 320, 64);
            $offset += 384;
            $mvX = $packed['h2']; if ($mvX >= 0x80000000) $mvX -= 0x100000000;
            $mvY = $packed['h3']; if ($mvY >= 0x80000000) $mvY -= 0x100000000;
            $sad = $packed['h4']; if ($sad >= 0x80000000) $sad -= 0x100000000;
            $results[$index] = [$mvX, $mvY, $sad, $cbpLuma, $nzCache, $quantResidual, $reconY, $reconU, $reconV, $cbpChroma, $chromaDc, $chromaAc];
        }
        if ($offset !== strlen($body)) throw new UnexpectedValueException('Invalid motion worker response trailer');
        return [$header['id'], true, $results];
    }

    private static function validateSeq(int $seq): void
    {
        if ($seq < 1 || $seq > 0xFFFFFFFF) throw new InvalidArgumentException('Invalid motion worker reference seq');
    }
}
