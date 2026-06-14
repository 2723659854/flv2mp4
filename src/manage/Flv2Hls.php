<?php

namespace Xiaosongshu\Flv2mp4\manage;

use Xiaosongshu\Flv2mp4\Flv\FlvParse;

/**
 * FLV转HLS切片
 * @author yanglong
 * @time 2026-06-12 00:02:00
 * @note 当前代码，每一个切片都时长正确，但是hls.js播放会报错
 */
class Flv2Hls
{
    private int $segmentDuration = 4;
    private int $videoPid = 0x100;
    private int $audioPid = 0x101;
    private int $pmtPid   = 0x1000;
    private int $sequenceNumber = 0;
    /** @var resource|null */
    private $tsHandle = null;

    // 时间戳基准
    private ?int $baseTimestamp = null;
    private int $segmentStartTime = 0;
    private array $continuityCounters = [];
    private string $spsPpsData = '';
    private ?string $audioSpecificConfig = null;

    // HE-AAC/SBR 支持
    private int $audioObjectType = 2;
    private int $samplingFrequencyIndex = 4;
    private int $channelConfiguration = 2;
    private bool $sbrPresent = false;
    private ?int $extensionSamplingIndex = null;

    private string $streamDir;
    private array $segmentDurations = [];
    private int $currentSegmentLastTime = 0;

    // ===== 修复：音频缓冲区管理 =====
    private array $audioBuffer = [];
    private ?int $lastAudioTimestamp = null;

    public function __construct(string $streamId, array $config = [])
    {
        $streamId = rtrim($streamId, "/");
        $streamId = ltrim($streamId, "/");
        $this->streamDir = $config['outputDir'] ?? dirname(__DIR__, 2) . "/hls/{$streamId}/";
        if (!is_dir($this->streamDir)) {
            mkdir($this->streamDir, 0777, true);
        }
        if (isset($config['segmentDuration'])) {
            $this->segmentDuration = (int)$config['segmentDuration'];
        }
        $this->ensureInitialPlaylist();
    }

    public function getStreamDir(): string
    {
        return $this->streamDir;
    }

    private function ensureInitialPlaylist(): void
    {
        $m3u8Path = $this->streamDir . 'index.m3u8';
        if (!file_exists($m3u8Path)) {
            $lines = [
                '#EXTM3U',
                '#EXT-X-VERSION:3',
                '#EXT-X-TARGETDURATION:' . $this->segmentDuration,
                '#EXT-X-MEDIA-SEQUENCE:1',
                '#EXT-X-INDEPENDENT-SEGMENTS',
            ];
            file_put_contents($m3u8Path, implode("\n", $lines) . "\n");
        }
    }

    public function processFrame($frame)
    {
        // 兼容不同类型的帧对象
        if (is_object($frame)) {
            $className = get_class($frame);
            // 检查是否是音频帧（通过类名或属性）
            if (strpos($className, 'Audio') !== false || property_exists($frame, 'tagType') && $frame->tagType === 8) {
                $this->handleAudioFrame($frame);
                return;
            }
            // 检查是否是视频帧（通过类名或属性）
            elseif (strpos($className, 'Video') !== false || property_exists($frame, 'tagType') && $frame->tagType === 9) {
                $this->handleVideoFrame($frame);
                return;
            }
        }
    }

    public function run(string $file): void
    {
        if (!file_exists($file)) return;
        $flvData = file_get_contents($file);
        FlvParse::setFlv($flvData);
        foreach (FlvParse::getTags() as $tag) {
            $this->processFrame($tag);
        }
    }

    private function handleVideoFrame($tag): void
    {
        // 获取帧数据 - 兼容不同的帧对象格式
        $body = null;
        if (property_exists($tag, 'body')) {
            $body = $tag->body;
        } elseif (method_exists($tag, 'getData')) {
            $body = $tag->getData();
        } elseif (method_exists($tag, '__toString')) {
            $body = (string)$tag;
        } elseif (method_exists($tag, 'dump')) {
            $body = $tag->dump();
        }

        if ($body === null) return;

        $videoData = $this->videoFrameDataRead($body);
        if (!$videoData) return;

        $avc = $this->avcPacketRead($videoData['data']);
        if (!$avc) return;

        if ($avc['avcPacketType'] === self::AVC_PACKET_TYPE_SEQUENCE_HEADER) {
            $this->parseAVCDecoderConfigurationRecord($avc['data']);
            return;
        }

        if ($avc['avcPacketType'] !== self::AVC_PACKET_TYPE_NALU) return;

        $isKeyFrame = ($videoData['frameType'] === self::VIDEO_FRAME_TYPE_KEY_FRAME);

        // 获取时间戳 - 兼容不同的帧对象格式
        $timestamp = 0;
        if (property_exists($tag, 'timestamp')) {
            $timestamp = $tag->timestamp;
        } elseif (method_exists($tag, 'getTime')) {
            $timestamp = $tag->getTime();
        } elseif (method_exists($tag, 'getTimestamp')) {
            $timestamp = $tag->getTimestamp();
        }

        // 首次收到关键帧，设置时间基准
        if ($this->baseTimestamp === null) {
            if (!$isKeyFrame) return;
            $this->baseTimestamp = $timestamp;
            $this->segmentStartTime = 0;
            $this->startSegment();
        }

        // 全局相对时间（毫秒）
        $relativeTime = $timestamp - $this->baseTimestamp;

        // ===== 修复：切片切换时处理音频缓冲 =====
        if ($isKeyFrame && ($relativeTime - $this->segmentStartTime) >= ($this->segmentDuration * 1000)) {
            // 先写入缓冲的音频数据到旧切片
            $this->flushAudioBuffer();
            // 关闭旧切片
            $this->closeSegment($relativeTime);
            // 清空音频缓冲，避免音频帧跨越切片边界
            $this->audioBuffer = [];
            $this->lastAudioTimestamp = null;
            // 开始新切片
            $this->segmentStartTime = $relativeTime;
            $this->startSegment();
        }

        $this->currentSegmentLastTime = $relativeTime;

        // 计算 CTS/DTS/PTS
        $cts = $avc['compositionTime'] ?? 0;
        // CTS 是 24 位有符号整数，需要符号扩展
        if ($cts & 0x800000) {
            $cts -= 0x1000000;
        }
        $dts = (int)($relativeTime * 90);
        $pts = (int)(($relativeTime + $cts) * 90);
        if ($pts < $dts) {
            $pts = $dts;
        }

        // 构建 AnnexB 并写入 TS
        $annexb = $this->avccToAnnexB($avc['data']);

        if ($isKeyFrame && $this->spsPpsData !== '') {
            $annexb = $this->spsPpsData . $annexb;
        }

        $pes = $this->createPES(0xE0, $annexb, $pts, ($pts != $dts) ? $dts : null);
        $this->writeTSPackets($this->videoPid, $pes, true, $dts);
    }

    private function handleAudioFrame($tag): void
    {
        // 获取帧数据 - 兼容不同的帧对象格式
        $raw = null;
        if (property_exists($tag, 'body')) {
            $raw = $tag->body;
        } elseif (method_exists($tag, 'getData')) {
            $raw = $tag->getData();
        } elseif (method_exists($tag, '__toString')) {
            $raw = (string)$tag;
        } elseif (method_exists($tag, 'dump')) {
            $raw = $tag->dump();
        }

        if ($raw === null || strlen($raw) < 2) return;

        $soundFormat = (ord($raw[0]) >> 4) & 0x0F;
        if ($soundFormat != 10) return; // 只处理 AAC

        $aacPacketType = ord($raw[1]);

        // Audio Specific Config
        if ($aacPacketType == 0) {
            $asc = substr($raw, 2);
            if (strlen($asc) >= 2) {
                $this->audioSpecificConfig = substr($asc, 0, 2);
                // 解析 AudioSpecificConfig，处理 HE-AAC/SBR
                $this->parseAudioSpecificConfig($asc);
            }
            return;
        }

        if ($aacPacketType != 1) return;
        if ($this->baseTimestamp === null || $this->audioSpecificConfig === null) return;

        $aacRaw = substr($raw, 2);
        if ($aacRaw === '') return;

        // ★★★ 检测并移除原始 ADTS 头，然后重新生成 ★★★
        // 原始 FLV 中的 ADTS 头可能有错误的参数，必须重新生成
        if (strlen($aacRaw) >= 7) {
            $firstByte = ord($aacRaw[0]);
            $secondByte = ord($aacRaw[1]);
            // ADTS syncword: 12 bits = 0xFFF
            if ($firstByte == 0xFF && ($secondByte & 0xF0) == 0xF0) {
                // 移除原始 ADTS 头（7 字节）
                $aacRaw = substr($aacRaw, 7);
            }
        }

        // 始终重新生成 ADTS 头，确保参数正确
        $adts = $this->createADTSHeader(strlen($aacRaw));
        $payload = $adts . $aacRaw;

        // 获取时间戳 - 兼容不同的帧对象格式
        $timestamp = 0;
        if (property_exists($tag, 'timestamp')) {
            $timestamp = $tag->timestamp;
        } elseif (method_exists($tag, 'getTime')) {
            $timestamp = $tag->getTime();
        } elseif (method_exists($tag, 'getTimestamp')) {
            $timestamp = $tag->getTimestamp();
        }

        // 全局相对时间
        $relativeTime = $timestamp - $this->baseTimestamp;

        // 直接写入当前切片的音频数据
        $pts = (int)($relativeTime * 90);

        $pes = $this->createPES(0xC0, $payload, $pts, null);

        // 如果有打开的 TS 文件就直接写入
        if ($this->tsHandle) {
            $this->writeTSPackets($this->audioPid, $pes, false, 0);
        }

        // 同时缓存音频帧信息，用于切片切换
        $this->audioBuffer[] = [
            'timestamp' => $relativeTime,
            'pts' => $pts,
            'payload' => $payload
        ];
    }

    // ===== 新增：刷新音频缓冲区 =====
    private function flushAudioBuffer(): void
    {
        // 音频数据已经在 handleAudioFrame 中实时写入了
        // 这个方法主要用于清理缓冲区，确保不跨越切片边界
        $this->audioBuffer = [];
    }

    private function parseAVCDecoderConfigurationRecord(string $data): void
    {
        $offset = 5;
        $numSps = ord($data[$offset]) & 0x1F;
        $offset++;
        $result = '';

        for ($i = 0; $i < $numSps; $i++) {
            $len = unpack('n', substr($data, $offset, 2))[1];
            $offset += 2;
            $result .= "\x00\x00\x00\x01";
            $result .= substr($data, $offset, $len);
            $offset += $len;
        }

        $numPps = ord($data[$offset]);
        $offset++;

        for ($i = 0; $i < $numPps; $i++) {
            $len = unpack('n', substr($data, $offset, 2))[1];
            $offset += 2;
            $result .= "\x00\x00\x00\x01";
            $result .= substr($data, $offset, $len);
            $offset += $len;
        }

        $this->spsPpsData = $result;
    }

    private function avccToAnnexB(string $data): string
    {
        $offset = 0;
        $result = '';
        $len = strlen($data);

        while ($offset + 4 <= $len) {
            $nalSize = unpack('N', substr($data, $offset, 4))[1];
            $offset += 4;
            if ($offset + $nalSize > $len) break;
            $result .= "\x00\x00\x00\x01";
            $result .= substr($data, $offset, $nalSize);
            $offset += $nalSize;
        }

        return $result;
    }

    private function parseAudioSpecificConfig(string $asc): void
    {
        if (strlen($asc) < 2) return;
        $b1 = ord($asc[0]);
        $b2 = ord($asc[1]);
        $this->audioObjectType = ($b1 >> 3) & 0x1F;
        $this->samplingFrequencyIndex = (($b1 & 0x07) << 1) | (($b2 >> 7) & 0x01);
        $this->channelConfiguration = ($b2 >> 3) & 0x0F;
        $this->sbrPresent = false;
        $this->extensionSamplingIndex = null;

        // 处理 HE-AAC / SBR (audioObjectType == 5 或 29)
        if ($this->audioObjectType == 5 || $this->audioObjectType == 29) {
            $this->sbrPresent = true;
            if (strlen($asc) >= 4) {
                $extSamplingIndex = (ord($asc[2]) >> 3) & 0x0F;
                $this->extensionSamplingIndex = $extSamplingIndex;
            }
        }
    }

    private function createADTSHeader(int $aacLength): string
    {
        $profile = $this->audioObjectType - 1;
        if ($profile < 0) $profile = 1;

        // 对于 HE-AAC/SBR，使用 extension sampling frequency index
        $freqIndex = $this->samplingFrequencyIndex;
        if ($this->sbrPresent && $this->extensionSamplingIndex !== null) {
            $freqIndex = $this->extensionSamplingIndex;
        }

        // 确保采样率索引在有效范围内 (0-11)
        if ($freqIndex < 0 || $freqIndex > 11) {
            $freqIndex = 4; // 默认 44100Hz
        }

        $channelConfig = $this->channelConfiguration;
        if ($channelConfig < 0 || $channelConfig > 7) {
            $channelConfig = 2; // 默认立体声
        }

        $frameLength = $aacLength + 7;

        // 构建 ADTS 头部 (7 bytes)
        return pack('CCCCCCC',
            0xFF, 0xF1,
            (($profile & 0x03) << 6) | (($freqIndex & 0x0F) << 2) | (($channelConfig >> 2) & 0x01),
            (($channelConfig & 0x03) << 6) | (($frameLength >> 11) & 0x03),
            ($frameLength >> 3) & 0xFF,
            (($frameLength & 0x07) << 5) | 0x1F,
            0xFC
        );
    }

    private function createPES(int $streamId, string $payload, int $pts, ?int $dts): string
    {
        $ptsDtsFlags = ($dts !== null && $dts != $pts) ? 0xC0 : 0x80;

        $headerData = $this->encodeTimestamp(($dts !== null && $dts != $pts) ? 0x03 : 0x02, $pts);
        if ($dts !== null && $dts != $pts) {
            $headerData .= $this->encodeTimestamp(0x01, $dts);
        }

        $headerLength = strlen($headerData);
        $packetLength = strlen($payload) + 3 + $headerLength;

        if ($packetLength > 0xFFFF) {
            $packetLength = 0;
        }

        return "\x00\x00\x01"
            . chr($streamId)
            . pack('n', $packetLength)
            . "\x80"
            . chr($ptsDtsFlags)
            . chr($headerLength)
            . $headerData
            . $payload;
    }

    private function encodeTimestamp(int $type, int $ts): string
    {
        $ts &= 0x1FFFFFFFF;
        return pack('CCCCC',
            (($type << 4) & 0xF0) | ((($ts >> 30) & 0x07) << 1) | 1,
            ($ts >> 22) & 0xFF,
            ((($ts >> 15) & 0x7F) << 1) | 1,
            ($ts >> 7) & 0xFF,
            (($ts & 0x7F) << 1) | 1
        );
    }

    private function encodePCR(int $pcr): string
    {
        return pack('CCCCCC',
            ($pcr >> 25) & 0xFF,
            ($pcr >> 17) & 0xFF,
            ($pcr >> 9)  & 0xFF,
            ($pcr >> 1)  & 0xFF,
            (($pcr & 1) << 7) | 0x7E,
            0x00
        );
    }

    private function writePAT(): void
    {
        $section = "\x00\xB0\x0D"
            . "\x00\x01"
            . "\xC1\x00\x00"
            . "\x00\x01"
            . pack('n', 0xE000 | $this->pmtPid);

        $section .= pack('N', $this->crc32mpeg($section));

        $payload = "\x00" . $section;
        $this->writeTSPacketsRaw(0x0000, $payload);
    }

    private function writePMT(): void
    {
        $body = "\x00\x01"
            . "\xC1\x00\x00"
            . pack('n', 0xE000 | $this->videoPid)
            . "\xF0\x00";

        // 视频流: H.264
        $body .= "\x1B"
            . pack('n', 0xE000 | $this->videoPid)
            . "\xF0\x00";

        // 音频流: AAC
        $body .= "\x0F"
            . pack('n', 0xE000 | $this->audioPid)
            . "\xF0\x00";

        $sectionLength = strlen($body) + 4;
        $section = "\x02"
            . chr(0xB0 | (($sectionLength >> 8) & 0x0F))
            . chr($sectionLength & 0xFF)
            . $body;

        $section .= pack('N', $this->crc32mpeg($section));

        $payload = "\x00" . $section;
        $this->writeTSPacketsRaw($this->pmtPid, $payload);
    }

    private function writeTSPacketsRaw(int $pid, string $payload): void
    {
        $cc = &$this->continuityCounters[$pid];
        if (!isset($cc)) $cc = 0;

        $offset = 0;
        $payloadLen = strlen($payload);
        $first = true;

        while ($offset < $payloadLen) {
            $remaining = $payloadLen - $offset;

            $packet = "\x47";
            $packet .= chr((($first ? 1 : 0) << 6) | (($pid >> 8) & 0x1F));
            $packet .= chr($pid & 0xFF);
            $packet .= chr(0x10 | ($cc & 0x0F));
            $cc = ($cc + 1) & 0x0F;

            $chunkSize = min($remaining, 184);
            $packet .= substr($payload, $offset, $chunkSize);

            if (strlen($packet) < 188) {
                $packet = str_pad($packet, 188, "\xFF");
            }

            fwrite($this->tsHandle, $packet);
            $offset += $chunkSize;
            $first = false;
        }
    }

    private function writeTSPackets(int $pid, string $payload, bool $writePCR = false, int $pcr = 0): void
    {
        $cc = &$this->continuityCounters[$pid];
        if (!isset($cc)) $cc = 0;

        $offset = 0;
        $payloadLen = strlen($payload);
        $first = true;

        while ($offset < $payloadLen) {
            $remaining = $payloadLen - $offset;

            $packet = "\x47";
            $packet .= chr((($first ? 1 : 0) << 6) | (($pid >> 8) & 0x1F));
            $packet .= chr($pid & 0xFF);

            $adaptationField = '';
            $adaptationControl = 1;

            if ($writePCR && $first) {
                $adaptationControl = 3;
                $adaptationField = chr(7) . chr(0x10) . $this->encodePCR($pcr);
            }

            $payloadSpace = 188 - 4 - strlen($adaptationField);

            if ($remaining < $payloadSpace) {
                $adaptationControl = 3;
                $stuffing = $payloadSpace - $remaining;

                if ($adaptationField === '') {
                    $adaptationField = chr($stuffing - 1) . chr(0x00);
                    if ($stuffing > 2) {
                        $adaptationField .= str_repeat("\xFF", $stuffing - 2);
                    }
                } else {
                    $newLen = min(255, ord($adaptationField[0]) + $stuffing);
                    $adaptationField[0] = chr($newLen);
                    $adaptationField .= str_repeat("\xFF", $stuffing);
                }
                $payloadSpace = 188 - 4 - strlen($adaptationField);
            }

            $packet .= chr(($adaptationControl << 4) | ($cc & 0x0F));
            $cc = ($cc + 1) & 0x0F;
            $packet .= $adaptationField;
            $packet .= substr($payload, $offset, $payloadSpace);
            $packet = str_pad($packet, 188, "\xFF");

            fwrite($this->tsHandle, $packet);
            $offset += $payloadSpace;
            $first = false;
        }
    }

    private function startSegment(): void
    {
        $this->sequenceNumber++;
        $this->continuityCounters = [];
        $file = $this->streamDir . "segment_{$this->sequenceNumber}.ts";
        $this->tsHandle = fopen($file, 'wb');

        $this->writePAT();
        $this->writePMT();

        // 写入 SPS/PPS
        if ($this->spsPpsData !== '') {
            $startPcr = (int)($this->segmentStartTime * 90);
            $spsPpsPes = $this->createPES(0xE0, $this->spsPpsData, $startPcr, $startPcr);
            $this->writeTSPackets($this->videoPid, $spsPpsPes, true, $startPcr);
        }
    }

    private function closeSegment(int $endTime = 0): void
    {
        if ($this->tsHandle) {
            fflush($this->tsHandle);
            fclose($this->tsHandle);
            $this->tsHandle = null;

            $end = $endTime > 0 ? $endTime : $this->currentSegmentLastTime;
            $actualDuration = ($end - $this->segmentStartTime) / 1000.0;
            $actualDuration = max(0.001, round($actualDuration, 3));
            $this->segmentDurations[$this->sequenceNumber] = $actualDuration;

            $this->updatePlaylist();
        }
    }

    private function crc32mpeg(string $data): int
    {
        $crc = 0xFFFFFFFF;
        $len = strlen($data);

        for ($i = 0; $i < $len; $i++) {
            $crc ^= ord($data[$i]) << 24;
            for ($j = 0; $j < 8; $j++) {
                if ($crc & 0x80000000) {
                    $crc = (($crc << 1) ^ 0x04C11DB7) & 0xFFFFFFFF;
                } else {
                    $crc = ($crc << 1) & 0xFFFFFFFF;
                }
            }
        }

        return $crc;
    }

    private function updatePlaylist(): void
    {
        $lines = [];
        $lines[] = "#EXTM3U";
        $lines[] = "#EXT-X-VERSION:3";

        $maxDuration = $this->segmentDuration;
        foreach ($this->segmentDurations as $duration) {
            $maxDuration = max($maxDuration, ceil($duration));
        }
        $lines[] = "#EXT-X-TARGETDURATION:" . (int)$maxDuration;
        $lines[] = "#EXT-X-MEDIA-SEQUENCE:1";
        $lines[] = '#EXT-X-INDEPENDENT-SEGMENTS';

        for ($i = 1; $i <= $this->sequenceNumber; $i++) {
            $duration = $this->segmentDurations[$i] ?? $this->segmentDuration;
            // ===== 修复：移除不必要的 EXT-X-DISCONTINUITY =====
            $lines[] = "#EXTINF:" . number_format($duration, 3, '.', '') . ",";
            $lines[] = "segment_{$i}.ts";
        }

        $m3u8Content = implode("\n", $lines) . "\n";

        $m3u8Path = $this->streamDir . "index.m3u8";
        $tmpPath = $m3u8Path . '.tmp';
        file_put_contents($tmpPath, $m3u8Content);
        rename($tmpPath, $m3u8Path);
    }

    public function getIndex(): string
    {
        return $this->streamDir . "index.m3u8";
    }

    public function close(): void
    {
        // ===== 修复：关闭前刷新音频缓冲 =====
        $this->flushAudioBuffer();
        $this->closeSegment($this->currentSegmentLastTime);

        $m3u8Path = $this->streamDir . 'index.m3u8';
        if (file_exists($m3u8Path)) {
            $m3u8 = rtrim(file_get_contents($m3u8Path)) . "\n";
            if (strpos($m3u8, '#EXT-X-ENDLIST') === false) {
                $m3u8 .= "#EXT-X-ENDLIST\n";
            }
            file_put_contents($m3u8Path, $m3u8);
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    // ============ 辅助方法 ============

    const VIDEO_FRAME_TYPE_KEY_FRAME = 1;
    const AVC_PACKET_TYPE_SEQUENCE_HEADER = 0;
    const AVC_PACKET_TYPE_NALU = 1;

    private function videoFrameDataRead(string $videoData): ?array
    {
        if (strlen($videoData) < 1) return null;
        $firstByte = ord($videoData[0]);
        return [
            'frameType' => $firstByte >> 4,
            'codecId' => $firstByte & 15,
            'data' => substr($videoData, 1)
        ];
    }

    private function avcPacketRead(string $avcPacket): ?array
    {
        if (strlen($avcPacket) < 4) return null;
        $cts = (ord($avcPacket[1]) << 16) | (ord($avcPacket[2]) << 8) | ord($avcPacket[3]);
        if ($cts & 0x800000) {
            $cts -= 0x1000000;
        }
        return [
            'avcPacketType' => ord($avcPacket[0]),
            'compositionTime' => $cts,
            'data' => substr($avcPacket, 4)
        ];
    }
}