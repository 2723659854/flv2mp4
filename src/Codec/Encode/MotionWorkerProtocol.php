<?php
namespace Xiaosongshu\Flv2mp4\Codec\Encode;

use InvalidArgumentException;
use UnexpectedValueException;

/**
 * @purpose 运动模块分布式计算-协议（v7：v5 条带化 + MV 种子地图基础上，JOB_BATCH
 *           增加本帧 Tier1 经验绝对 SAD 限额（u16），响应 flags 增加 Tier1 命中回传位。
 *           v6 的"严格解析死区百分数乘子"因 qp≤15 时基数为 0 已废弃）
 * @author yanglong
 */
final class MotionWorkerProtocol
{
    public const MAX_BODY_LENGTH = 16777216;
    public const LOAD_REFERENCE = 1;
    public const JOB_BATCH = 2;
    private const REQUEST_MAGIC = 'MWR7';
    private const RESPONSE_MAGIC = 'MWS5';
    private const SEQ_LENGTH = 4;
    private const JOB_META_LENGTH = 16;
    private const BATCH_HEADER_LENGTH = 46;
    private const FLAG_HAS_RESIDUAL = 0x01;
    private const FLAG_TIER1_HIT = 0x02;
    /** 单个 4x4 块理论最大 SAD（16 像素 ×255） */
    public const MAX_BLOCK_SAD = 4080;

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
     * 打包前一帧 MV 种子地图（v5）。
     * 每宏块 2 个有符号 16 位（1/4 像素单位），按 mbWidth×mbHeight 光栅连续排列；
     * 无地图（IDR 后首 P 帧、关闭 mvp_seed）时返回空串。
     *
     * @param array $mvs 光栅顺序（键 0..w*h-1）的 [mvX, mvY] 列表（1/4 像素）
     */
    public static function encodeMvMap(int $mbWidth, int $mbHeight, array $mvs): string
    {
        if ($mbWidth <= 0 || $mbHeight <= 0) throw new InvalidArgumentException('Invalid motion worker mv map size');
        if (count($mvs) !== $mbWidth * $mbHeight) throw new InvalidArgumentException('Motion worker mv map must cover every macroblock');
        $flat = [];
        foreach ($mvs as [$mvX, $mvY]) {
            $flat[] = max(-32768, min(32767, (int)$mvX));
            $flat[] = max(-32768, min(32767, (int)$mvY));
        }
        return pack('s*', ...$flat);
    }

    /**
     * BATCH 请求（v5 条带化 + 可选 MV 种子地图）：
     * 当前帧亮度不再逐宏块切 256B 追加，而是按宏块行发送连续的 16 行条带。
     * 主进程按宏块行连续分片（每 worker 负责连续若干整行），故每 worker 只收自己
     * 行区间的条带：整帧条带在所有 worker 间恰好发送一次（总字节数与旧协议相同），
     * 而主进程每帧 8464 次 substr 降为每帧 ~23 次，块提取下沉到各 worker 并行。
     *
     * @param array  $jobs        job 索引 => [x, y, range]（不再含 256B 块，y 为全帧绝对宏块行）
     * @param string $strips      条带拼接串（stripCount 个，每条 16*aw 字节）
     * @param int    $stripOffset 首条带对应的绝对宏块行
     * @param int    $stripCount  条带数
     * @param int    $aw          宏块对齐宽度（条带跨距）
     * @param string $seedMap     前一帧 MV 地图（encodeMvMap 产物），空串=无种子（行为同 v4）
     * @param int    $seedW       地图宏块宽（0=无地图）
     * @param int    $seedH       地图宏块高（0=无地图）
     * @param int    $tier1BlockSad v7：本帧 Tier1 经验绝对 SAD 限额（每个 4x4 块）；
     *                              0=不放宽（仅用严格解析死区），否则取 1..4080，
     *                              worker 实际阈值 = max(严格死区, 本限额)
     */
    public static function batch(int $id, int $seq, int $qp, array $jobs, string $strips, int $stripOffset, int $stripCount, int $aw, string $seedMap = '', int $seedW = 0, int $seedH = 0, int $tier1BlockSad = 0): string
    {
        self::validateSeq($seq);
        if ($stripCount < 0 || $stripOffset < 0 || $aw <= 0 || strlen($strips) !== $stripCount * 16 * $aw) {
            throw new InvalidArgumentException('Invalid motion worker strips');
        }
        $hasSeed = $seedW > 0 && $seedH > 0;
        if ($hasSeed !== ($seedMap !== '') || ($hasSeed && strlen($seedMap) !== $seedW * $seedH * 4)) {
            throw new InvalidArgumentException('Invalid motion worker mv seed map');
        }
        if ($tier1BlockSad < 0 || $tier1BlockSad > self::MAX_BLOCK_SAD) {
            throw new InvalidArgumentException('Invalid motion worker tier1 block sad');
        }
        $body = self::REQUEST_MAGIC . chr(self::JOB_BATCH) . "\0\0\0" . pack('N', $seq)
            . pack('N8', $id, $qp, count($jobs), $stripOffset, $stripCount, $aw, $seedW, $seedH)
            . pack('n', $tier1BlockSad)
            . $strips . $seedMap;
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
        if ($type !== self::JOB_BATCH || strlen($body) < self::BATCH_HEADER_LENGTH) throw new UnexpectedValueException('Invalid motion worker request type');
        $header = unpack('Nid/Nqp/Ncount/NstripOffset/NstripCount/Naw/NseedW/NseedH', substr($body, 12, 32));
        $count = $header['count'];
        $stripOffset = $header['stripOffset'];
        $stripCount = $header['stripCount'];
        $aw = $header['aw'];
        $seedW = $header['seedW'];
        $seedH = $header['seedH'];
        // v7：头末尾 u16 Tier1 经验绝对 SAD 限额（0=不放宽，1..4080）
        $tier1BlockSad = unpack('n', substr($body, 44, 2))[1];
        if ($count < 0 || $stripCount < 0 || $stripOffset < 0 || $aw <= 0 || $seedW < 0 || $seedH < 0
            || $tier1BlockSad > self::MAX_BLOCK_SAD) {
            throw new UnexpectedValueException('Invalid motion worker batch header');
        }
        $stripsLength = $stripCount * 16 * $aw;
        $seedLength = $seedW * $seedH * 4;
        if (($seedW > 0) !== ($seedH > 0) || strlen($body) !== self::BATCH_HEADER_LENGTH + $stripsLength + $seedLength + $count * self::JOB_META_LENGTH) {
            throw new UnexpectedValueException('Invalid motion worker batch length');
        }
        $payloadOffset = self::BATCH_HEADER_LENGTH;
        // 条带只保留引用（零拷贝），块提取按 job 所在宏块行惰性切片
        $strips = $stripCount > 0 ? substr($body, $payloadOffset, $stripsLength) : '';
        // v5：前一帧 MV 种子地图（无地图时 null，运动估计路径与 v4 完全一致）
        $seedMap = null;
        if ($seedLength > 0) {
            $raw = unpack('s*', substr($body, $payloadOffset + $stripsLength, $seedLength));
            $mvs = [];
            for ($i = 1, $n = $seedW * $seedH; $i <= $n; $i++) {
                $mvs[] = [$raw[2 * $i - 1], $raw[2 * $i]];
            }
            $seedMap = ['w' => $seedW, 'h' => $seedH, 'mvs' => $mvs];
        }
        $blocks = [];
        $offset = $payloadOffset + $stripsLength + $seedLength;
        $stripRows = $stripCount > 0 ? [] : null;
        for ($i = 0; $i < $count; $i++) {
            $job = unpack('Nindex/Nx/Ny/Nrange', substr($body, $offset, 16));
            $stripIndex = $job['y'] - $stripOffset;
            if ($stripIndex < 0 || $stripIndex >= $stripCount) throw new UnexpectedValueException('Motion worker job outside strips');
            $strip = $stripRows[$stripIndex] ??= substr($strips, $stripIndex * 16 * $aw, 16 * $aw);
            // 16x16 块提取下沉到 worker 端（各 worker 并行）
            $luma = '';
            $base = $job['x'] * 16;
            for ($row = 0; $row < 16; $row++) $luma .= substr($strip, $row * $aw + $base, 16);
            $blocks[$job['index']] = [$job['x'], $job['y'], $luma, $job['range']];
            $offset += self::JOB_META_LENGTH;
        }
        return [$type, $seq, $header['id'], $header['qp'], $blocks, $seedMap, $tier1BlockSad];
    }

    public static function response(int $id, array $results): string
    {
        $body = self::RESPONSE_MAGIC . pack('NCx3N', $id, 1, count($results));
        foreach ($results as $index => $result) {
            [$mvX, $mvY, $sad, $cbpLuma, $nzCache, $quantResidual, $reconY, $reconU, $reconV, $tier1Hit] = $result;
            if (count($nzCache) !== 24 || strlen($reconY) !== 256 || strlen($reconU) !== 64 || strlen($reconV) !== 64) throw new InvalidArgumentException('Invalid motion worker result');
            $flags = $tier1Hit ? self::FLAG_TIER1_HIT : 0;
            if ($cbpLuma > 0) {
                // 非零宏块：flags(bit0=残差/bit1=Tier1命中) + nz(24B) + 量化残差(256*4B)
                $flags |= self::FLAG_HAS_RESIDUAL;
            }
            // cbp=0：残差必全 0 且主进程不会读取，省掉 1048 字节/宏块的打包、传输与解包
            $body .= pack('N5', $index, $mvX, $mvY, $sad, $cbpLuma);
            $body .= pack('Cx3', $flags);
            if ($cbpLuma > 0) {
                $body .= pack('C24', ...array_values($nzCache));
                $flat = [];
                for ($block = 0; $block < 16; $block++) {
                    if (!isset($quantResidual[$block]) || count($quantResidual[$block]) !== 16) throw new InvalidArgumentException('Invalid motion worker residual');
                    foreach ($quantResidual[$block] as $value) $flat[] = $value;
                }
                $body .= pack('N256', ...$flat);
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
            $nzCache = array_fill(0, 24, 0);
            $quantResidual = [];
            if (($packed['flags'] & self::FLAG_HAS_RESIDUAL) !== 0) {
                if (strlen($body) < $offset + 24 + 1024) throw new UnexpectedValueException('Invalid motion worker residual payload');
                $extra = unpack('C24z/N256r', substr($body, $offset, 24 + 1024));
                for ($k = 1; $k <= 24; $k++) $nzCache[$k - 1] = $extra['z' . $k];
                for ($k = 0; $k < 256; $k++) {
                    $value = $extra['r' . ($k + 1)];
                    if ($value >= 0x80000000) $value -= 0x100000000;
                    $quantResidual[$k >> 4][$k & 15] = $value;
                }
                $offset += 1048;
            }
            if (strlen($body) < $offset + 384) throw new UnexpectedValueException('Invalid motion worker recon payload');
            $reconY = substr($body, $offset, 256);
            $reconU = substr($body, $offset + 256, 64);
            $reconV = substr($body, $offset + 320, 64);
            $offset += 384;
            $mvX = $packed['h2']; if ($mvX >= 0x80000000) $mvX -= 0x100000000;
            $mvY = $packed['h3']; if ($mvY >= 0x80000000) $mvY -= 0x100000000;
            $sad = $packed['h4']; if ($sad >= 0x80000000) $sad -= 0x100000000;
            $cbp = $packed['h5']; if ($cbp >= 0x80000000) $cbp -= 0x100000000;
            // v6：flags bit1 回传该 MB 是否走了 Tier1（含放宽阈值）命中
            $tier1Hit = ($packed['flags'] & self::FLAG_TIER1_HIT) !== 0 ? 1 : 0;
            $results[$index] = [$mvX, $mvY, $sad, $cbp, $nzCache, $quantResidual, $reconY, $reconU, $reconV, $tier1Hit];
        }
        if ($offset !== strlen($body)) throw new UnexpectedValueException('Invalid motion worker response trailer');
        return [$header['id'], true, $results];
    }

    private static function validateSeq(int $seq): void
    {
        if ($seq < 1 || $seq > 0xFFFFFFFF) throw new InvalidArgumentException('Invalid motion worker reference seq');
    }
}
