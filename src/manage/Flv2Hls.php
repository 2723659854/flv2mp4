<?php

namespace Xiaosongshu\Flv2mp4\manage;

use Xiaosongshu\Flv2mp4\Flv\FlvParse;

/**
 * @purpose flv转hls协议工具（最终修复版）
 * @author yanglong
 * @time 2026年6月3日15:46:52
 */
class Flv2Hls
{
    private $segmentDuration = 4;
    private $streamId;
    private $streamDir;
    private $videoPid = 0x100;
    private $audioPid = 0x101;
    private $pmtPid = 0x1000;
    private $sequenceNumber = 0;
    private $tsHandle = null;

    private $baseTimestamp = null;
    private $segmentStartTime = 0;
    private $continuityCounters = [];
    private $spsPpsData = '';
    private $audioSpecificConfig = null;

    private $segmentDurations = [];
    private $currentSegmentLastTime = 0;

    // 时间戳跟踪
    private $lastVideoDts = 0;
    private $lastVideoPts = 0;
    private $lastAudioPts = 0;

    // 帧缓冲
    private $videoFrameBuffer = [];
    private $maxBufferSize = 500;

    // 切片内SPS/PPS标记
    private $spsPpsWrittenInSegment = false;

    public function __construct(string $streamId, array $config = [])
    {
        $streamId = rtrim($streamId, "/");
        $streamId = ltrim($streamId, "/");
        $this->streamId = $streamId;
        if (isset($config['outputDir'])) {
            $this->streamDir = $config['outputDir'];
        } else {
            $this->streamDir = dirname(__DIR__, 2) . "/hls/{$streamId}/";
        }
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
        switch ($frame->FRAME_TYPE) {
            case 1: return $this->sendVideoFrame($frame);
            case 2: return $this->sendAudioFrame($frame);
            case 0: return $this->sendMetaDataFrame($frame);
        }
    }

    static function createFlvTag($tag)
    {
        $preTagLen = 11 + $tag->dataSize;
        return pack(
            "Ca3a3Ca3a{$tag->dataSize}N",
            $tag->type,
            pack("N", $tag->dataSize << 8),
            pack("N", $tag->timestamp << 8),
            $tag->timestamp >> 24,
            pack("N", $tag->streamId << 8),
            $tag->data,
            $preTagLen
        );
    }

    public function sendMetaDataFrame($metaDataFrame)
    {
        $tag = new \stdClass();
        $tag->streamId = 0;
        $tag->type = 18;
        $tag->timestamp = 0;
        $tag->data = (string)$metaDataFrame;
        $tag->dataSize = strlen($tag->data);
        $this->write(self::createFlvTag($tag));
    }

    public function sendAudioFrame($audioFrame)
    {
        $tag = new \stdClass();
        $tag->streamId = 0;
        $tag->type = 8;
        $tag->timestamp = $audioFrame->timestamp;
        $tag->data = (string)$audioFrame;
        $tag->dataSize = strlen($tag->data);
        $this->write(self::createFlvTag($tag));
    }

    public function sendVideoFrame($videoFrame)
    {
        $tag = new \stdClass();
        $tag->streamId = 0;
        $tag->type = 9;
        $tag->timestamp = $videoFrame->timestamp;
        $tag->data = (string)$videoFrame;
        $tag->dataSize = strlen($tag->data);
        $this->write(self::createFlvTag($tag));
    }

    public function run(string $file)
    {
        if (!file_exists($file)) return;

        $flvData = file_get_contents($file);
        FlvParse::setFlv($flvData);
        $tags = FlvParse::getTags();

        foreach ($tags as $tag) {
            $flvTagData = chr($tag->tagType)
                . chr(($tag->dataSize >> 16) & 0xFF)
                . chr(($tag->dataSize >> 8) & 0xFF)
                . chr($tag->dataSize & 0xFF)
                . substr($tag->Timestamp, 0, 3)
                . substr($tag->Timestamp, 3, 1)
                . chr(($tag->StreamID >> 16) & 0xFF)
                . chr(($tag->StreamID >> 8) & 0xFF)
                . chr($tag->StreamID & 0xFF)
                . $tag->body
                . pack('N', $tag->size);
            $this->write($flvTagData);
        }
        $this->close();
    }

    public function write(string $flvTagData)
    {
        $offset = 0;
        $dataLen = strlen($flvTagData);
        if ($dataLen < 15) return;

        $type = ord($flvTagData[$offset++]);
        $dataSize = (ord($flvTagData[$offset]) << 16) | (ord($flvTagData[$offset+1]) << 8) | ord($flvTagData[$offset+2]);
        $offset += 3;
        $timestampLow = (ord($flvTagData[$offset]) << 16) | (ord($flvTagData[$offset+1]) << 8) | ord($flvTagData[$offset+2]);
        $offset += 3;
        $timestampExt = ord($flvTagData[$offset++]);
        $timestamp = ($timestampExt << 24) | $timestampLow;
        $offset += 3;

        if ($offset + $dataSize > $dataLen) return;
        $payload = substr($flvTagData, $offset, $dataSize);

        if ($type === 9) {
            $this->handleVideoFrame($timestamp, $payload);
        } elseif ($type === 8) {
            $this->handleAudioFrame($timestamp, $payload);
        }
    }

    private function handleVideoFrame(int $timestamp, string $rawData): void
    {
        $videoData = self::videoFrameDataRead($rawData);
        if (!$videoData) return;

        $avc = self::avcPacketRead($videoData['data']);
        if (!$avc) return;

        if ($avc['avcPacketType'] == self::AVC_PACKET_TYPE_SEQUENCE_HEADER) {
            $this->parseAVCDecoderConfigurationRecord($avc['data']);
            return;
        }

        if ($avc['avcPacketType'] != self::AVC_PACKET_TYPE_NALU) return;

        $isKeyFrame = ($videoData['frameType'] == self::VIDEO_FRAME_TYPE_KEY_FRAME);

        // 初始化基准时间戳
        if ($this->baseTimestamp === null) {
            if (!$isKeyFrame) return;
            $this->baseTimestamp = $timestamp;
            $this->segmentStartTime = 0;
            $this->spsPpsWrittenInSegment = false;
            $this->startSegment();
        }

        // 相对时间（毫秒）
        $relativeTime = $timestamp - $this->baseTimestamp;

        // CTS偏移
        $cts = $avc['compositionTime'] ?? 0;
        if ($cts & 0x800000) $cts -= 0x1000000;

        // 计算90kHz时间戳
        $dts = (int)($relativeTime * 90);
        $pts = (int)(($relativeTime + $cts) * 90);
        if ($pts < $dts) $pts = $dts;

        // 确保时间戳单调递增
        if ($dts <= $this->lastVideoDts) $dts = $this->lastVideoDts + 1;
        if ($pts <= $this->lastVideoPts) $pts = $this->lastVideoPts + 1;
        $this->lastVideoDts = $dts;
        $this->lastVideoPts = $pts;

        // AnnexB转换
        $annexb = $this->avccToAnnexB($avc['data']);

        // 添加到缓冲队列
        $this->videoFrameBuffer[] = [
            'dts' => $dts,
            'pts' => $pts,
            'isKeyFrame' => $isKeyFrame,
            'annexb' => $annexb,
            'relativeTime' => $relativeTime,
        ];

        // 关键帧或缓冲满时输出
        if ($isKeyFrame || count($this->videoFrameBuffer) >= $this->maxBufferSize) {
            $this->flushFrameBuffer();
        }
    }

    private function flushFrameBuffer(): void
    {
        if (empty($this->videoFrameBuffer)) return;

        // 按DTS排序
        usort($this->videoFrameBuffer, function($a, $b) {
            return $a['dts'] - $b['dts'];
        });

        // 检查是否需要切新片
        $firstKeyFrameTime = null;
        foreach ($this->videoFrameBuffer as $frame) {
            if ($frame['isKeyFrame']) {
                $firstKeyFrameTime = $frame['relativeTime'];
                break;
            }
        }

        if ($firstKeyFrameTime !== null && $this->segmentStartTime > 0 &&
            ($firstKeyFrameTime - $this->segmentStartTime) >= ($this->segmentDuration * 1000)) {
            $this->closeSegment($this->currentSegmentLastTime);
            $this->segmentStartTime = $firstKeyFrameTime;
            $this->spsPpsWrittenInSegment = false;
            $this->startSegment();
        }

        // 输出帧
        foreach ($this->videoFrameBuffer as $frame) {
            $this->currentSegmentLastTime = $frame['relativeTime'];
            $annexbData = $frame['annexb'];
            $writePCR = false;

            if ($frame['isKeyFrame']) {
                // 关键帧前附加SPS/PPS（如果还没写过）
                if (!$this->spsPpsWrittenInSegment && $this->spsPpsData !== '') {
                    $annexbData = $this->spsPpsData . $annexbData;
                    $this->spsPpsWrittenInSegment = true;
                }
                $writePCR = true;
            }

            $pes = $this->createPES(0xE0, $annexbData, $frame['pts'],
                ($frame['pts'] != $frame['dts']) ? $frame['dts'] : null);
            $this->writeTSPackets($this->videoPid, $pes, $writePCR, $frame['dts']);
        }

        $this->videoFrameBuffer = [];
    }

    private function handleAudioFrame(int $timestamp, string $rawData): void
    {
        if (strlen($rawData) < 2) return;

        $soundFormat = (ord($rawData[0]) >> 4) & 0x0F;
        if ($soundFormat != 10) return; // 只支持AAC

        $aacPacketType = ord($rawData[1]);

        if ($aacPacketType == 0) {
            // AudioSpecificConfig
            $asc = substr($rawData, 2);
            if (strlen($asc) >= 2) {
                $this->audioSpecificConfig = substr($asc, 0, 2);
            }
            return;
        }

        if ($aacPacketType != 1) return;
        if ($this->baseTimestamp === null || $this->audioSpecificConfig === null) return;

        $aacRaw = substr($rawData, 2);
        if ($aacRaw === '') return;

        // 相对时间
        $relativeTime = $timestamp - $this->baseTimestamp;

        // PTS（90kHz）
        $pts = (int)($relativeTime * 90);
        if ($pts <= $this->lastAudioPts) $pts = $this->lastAudioPts + 1;
        $this->lastAudioPts = $pts;

        // 创建ADTS头
        $adts = $this->createADTSHeader(strlen($aacRaw));
        $payload = $adts . $aacRaw;

        // 写入TS包
        $pes = $this->createPES(0xC0, $payload, $pts, null);
        $this->writeTSPackets($this->audioPid, $pes, false, $pts);
    }

    static function videoFrameDataRead($videoData)
    {
        if (strlen($videoData) < 1) return null;
        $firstByte = ord($videoData[0]);
        return [
            'frameType' => $firstByte >> 4,
            'codecId' => $firstByte & 15,
            'data' => substr($videoData, 1),
        ];
    }

    static function avcPacketRead($avcPacket)
    {
        if (strlen($avcPacket) < 4) return null;
        return [
            'avcPacketType' => ord($avcPacket[0]),
            'compositionTime' => (ord($avcPacket[1]) << 16) | (ord($avcPacket[2]) << 8) | ord($avcPacket[3]),
            'data' => substr($avcPacket, 4)
        ];
    }

    const VIDEO_FRAME_TYPE_KEY_FRAME = 1;
    const AVC_PACKET_TYPE_SEQUENCE_HEADER = 0;
    const AVC_PACKET_TYPE_NALU = 1;

    private function writePAT(): void
    {
        $section = "\x00\xB0\x0D\x00\x01\xC1\x00\x00\x00\x01" . pack('n', 0xE000 | $this->pmtPid);
        $section .= pack('N', $this->crc32mpeg($section));
        $this->writeTSPacketsRaw(0x0000, "\x00" . $section);
    }

    private function writePMT(): void
    {
        $body = "\x00\x01\xC1\x00\x00" . pack('n', 0xE000 | $this->videoPid) . "\xF0\x00";
        $body .= "\x1B" . pack('n', 0xE000 | $this->videoPid) . "\xF0\x00";
        $body .= "\x0F" . pack('n', 0xE000 | $this->audioPid) . "\xF0\x00";

        $sectionLength = strlen($body) + 4;
        $section = "\x02" . chr(0xB0 | (($sectionLength >> 8) & 0x0F)) . chr($sectionLength & 0xFF) . $body;
        $section .= pack('N', $this->crc32mpeg($section));
        $this->writeTSPacketsRaw($this->pmtPid, "\x00" . $section);
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
            $packet = str_pad($packet, 188, "\xFF");

            fwrite($this->tsHandle, $packet);
            $offset += $chunkSize;
            $first = false;
        }
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
            $result .= "\x00\x00\x00\x01" . substr($data, $offset, $len);
            $offset += $len;
        }

        $numPps = ord($data[$offset]);
        $offset++;

        for ($i = 0; $i < $numPps; $i++) {
            $len = unpack('n', substr($data, $offset, 2))[1];
            $offset += 2;
            $result .= "\x00\x00\x00\x01" . substr($data, $offset, $len);
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
            $result .= "\x00\x00\x00\x01" . substr($data, $offset, $nalSize);
            $offset += $nalSize;
        }

        return $result;
    }

    private function createADTSHeader(int $aacLength): string
    {
        if ($this->audioSpecificConfig === null || strlen($this->audioSpecificConfig) < 2) {
            return str_repeat("\x00", 7); // 返回空头，避免错误
        }

        $asc = $this->audioSpecificConfig;
        $b1 = ord($asc[0]);
        $b2 = ord($asc[1]);

        $profile = ($b1 >> 3) & 0x1F;
        $freqIndex = (($b1 & 0x07) << 1) | (($b2 >> 7) & 0x01);
        $channelConfig = ($b2 >> 3) & 0x0F;

        // 使用AAC-LC
        $profile = 1;
        $frameLength = $aacLength + 7;

        return pack('CCCCCCC',
            0xFF, 0xF1,
            (($profile & 0x03) << 6) | (($freqIndex & 0x0F) << 2) | (($channelConfig >> 2) & 0x01),
            (($channelConfig & 0x03) << 6) | (($frameLength >> 11) & 0x07),
            ($frameLength >> 3) & 0xFF,
            (($frameLength & 0x07) << 5) | 0x1F,
            0xFC
        );
    }

    private function createPES(int $streamId, string $payload, int $pts, ?int $dts): string
    {
        // 判断是否需要DTS
        $hasDts = ($dts !== null && $dts != $pts);
        $ptsDtsFlags = $hasDts ? 0xC0 : 0x80;

        // 编码PTS
        $headerData = $this->encodeTimestamp($hasDts ? 0x03 : 0x02, $pts);

        // 编码DTS
        if ($hasDts) {
            $headerData .= $this->encodeTimestamp(0x01, $dts);
        }

        $headerLength = strlen($headerData);

        // PES包长度 = 标志位(2) + 头部长度(1) + 头部数据 + 负载
        $pesPayloadLength = 3 + $headerLength + strlen($payload);
        if ($pesPayloadLength > 0xFFFF) {
            $pesPayloadLength = 0; // 视频流无限制
        }

        return "\x00\x00\x01"           // 起始码
            . chr($streamId)            // 流ID
            . pack('n', $pesPayloadLength)  // PES包长度
            . "\x80"                    // 标志位
            . chr($ptsDtsFlags)         // PTS/DTS标志
            . chr($headerLength)        // 头部长度
            . $headerData               // PTS/DTS数据
            . $payload;                 // 负载
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
            ($pcr >> 9) & 0xFF,
            ($pcr >> 1) & 0xFF,
            (($pcr & 1) << 7) | 0x7E,
            0x00
        );
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
                    if ($stuffing >= 2) {
                        $adaptationField = chr($stuffing - 1) . chr(0x00) . str_repeat("\xFF", $stuffing - 2);
                    } else {
                        $adaptationField = chr(1) . "\xFF";
                        $stuffing = 2;
                    }
                } else {
                    $newLen = min(255, ord($adaptationField[0]) + $stuffing);
                    $adaptationField[0] = chr($newLen);
                    $adaptationField .= str_repeat("\xFF", $newLen - ord($adaptationField[0]));
                }

                $payloadSpace = 188 - 4 - strlen($adaptationField);
            }

            $payloadSpace = min($payloadSpace, $remaining);

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

        if (!$this->tsHandle) {
            throw new \RuntimeException("无法创建TS文件: {$file}");
        }

        $this->writePAT();
        $this->writePMT();

        // 不单独写SPS/PPS，将在第一个关键帧中附加
    }

    private function closeSegment(int $endTime = 0): void
    {
        if (!$this->tsHandle) return;

        // 输出缓冲帧
        $this->flushFrameBuffer();

        fclose($this->tsHandle);
        $this->tsHandle = null;

        $end = ($endTime > 0) ? $endTime : $this->currentSegmentLastTime;
        $duration = ($end - $this->segmentStartTime) / 1000.0;
        $this->segmentDurations[$this->sequenceNumber] = max(0.001, round($duration, 3));

        $this->updatePlaylist();
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
        $lines = ["#EXTM3U", "#EXT-X-VERSION:3"];

        $maxDuration = $this->segmentDuration;
        foreach ($this->segmentDurations as $d) {
            $maxDuration = max($maxDuration, ceil($d));
        }

        $lines[] = "#EXT-X-TARGETDURATION:" . (int)$maxDuration;
        $lines[] = "#EXT-X-MEDIA-SEQUENCE:1";

        for ($i = 1; $i <= $this->sequenceNumber; $i++) {
            $duration = $this->segmentDurations[$i] ?? $this->segmentDuration;
            $lines[] = "#EXTINF:" . number_format($duration, 3, '.', '') . ",";
            $lines[] = "segment_{$i}.ts";
        }

        $m3u8Content = implode("\n", $lines) . "\n";
        $m3u8Path = $this->streamDir . "index.m3u8";
        $tmpPath = $m3u8Path . '.tmp';

        file_put_contents($tmpPath, $m3u8Content);
        rename($tmpPath, $m3u8Path);
    }

    public function getIndex()
    {
        return $this->streamDir . "index.m3u8";
    }

    public function close(): void
    {
        $this->closeSegment($this->currentSegmentLastTime);

        $m3u8Path = $this->streamDir . 'index.m3u8';
        if (file_exists($m3u8Path)) {
            $content = rtrim(file_get_contents($m3u8Path)) . "\n";
            if (strpos($content, '#EXT-X-ENDLIST') === false) {
                $content .= "#EXT-X-ENDLIST\n";
            }
            file_put_contents($m3u8Path, $content);
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}