<?php

namespace Xiaosongshu\Flv2mp4\manage;

use Xiaosongshu\Flv2mp4\Flv\FlvParse;

class Flv2Hls
{
    private $segmentDuration = 4;
    private $streamId;
    private $streamDir;
    private $videoPid = 0x100;
    private $audioPid = 0x101;
    private $pmtPid   = 0x1000;
    private $sequenceNumber = 0;
    private $tsHandle = null;

    private $baseTimestamp = null;
    private $segmentStartTime = 0;
    private $continuityCounters = [];
    private $spsPpsData = '';
    private $audioSpecificConfig = null;
    private $segmentDurations = [];
    private $currentSegmentLastTime = 0;

    private $videoFrameCount = 0;
    private $frameInterval = 3000; // 假设 30fps，90kHz 时钟下的帧间隔
    private $audioFrameCount = 0;
    private $audioInterval = 1024; // AAC 帧间隔（基于采样率）
    private $lastVideoPts = -1;
    private $lastVideoDts = -1;
    private $lastAudioPts = -1;
    private $spsPpsWritten = false;
    private $audioObjectType = 2;
    private $samplingFrequencyIndex = 4;
    private $channelConfiguration = 2;
    private $sbrPresent = false;
    private $extensionSamplingIndex = null;

    public function __construct(string $streamId, array $config = [])
    {
        $streamId = rtrim($streamId, "/");
        $streamId = ltrim($streamId, "/");
        $this->streamId = $streamId;
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
        switch ($frame->FRAME_TYPE) {
            case 1: return $this->sendVideoFrame($frame);
            case 2: return $this->sendAudioFrame($frame);
            case 0: return $this->sendMetaDataFrame($frame);
        }
    }

    static function createFlvTag($tag)
    {
        $preTagLen = 11 + $tag->dataSize;
        return pack("Ca3a3Ca3a{$tag->dataSize}N",
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
        $tag->streamId = 0; $tag->type = 18; $tag->timestamp = 0;
        $tag->data = (string)$metaDataFrame; $tag->dataSize = strlen($tag->data);
        $this->write(self::createFlvTag($tag));
    }

    public function sendAudioFrame($audioFrame)
    {
        $tag = new \stdClass();
        $tag->streamId = 0; $tag->type = 8; $tag->timestamp = $audioFrame->timestamp;
        $tag->data = (string)$audioFrame; $tag->dataSize = strlen($tag->data);
        $this->write(self::createFlvTag($tag));
    }

    public function sendVideoFrame($videoFrame)
    {
        $tag = new \stdClass();
        $tag->streamId = 0; $tag->type = 9; $tag->timestamp = $videoFrame->timestamp;
        $tag->data = (string)$videoFrame; $tag->dataSize = strlen($tag->data);
        $this->write(self::createFlvTag($tag));
    }

    public function run(string $file)
    {
        if (!file_exists($file)) return;
        $flvData = file_get_contents($file);
        FlvParse::setFlv($flvData);
        foreach (FlvParse::getTags() as $tag) {
            $flvTagData = chr($tag->tagType)
                . chr(($tag->dataSize >> 16) & 0xFF)
                . chr(($tag->dataSize >> 8) & 0xFF)
                . chr($tag->dataSize & 0xFF)
                . substr($tag->Timestamp, 0, 3)
                . substr($tag->Timestamp, 3, 1)
                . chr(($tag->StreamID >> 16) & 0xFF)
                . chr(($tag->StreamID >> 8) & 0xFF)
                . chr($tag->StreamID & 0xFF)
                . $tag->body . pack('N', $tag->size);
            $this->write($flvTagData);
        }
        $this->close();
    }

    public function write(string $flvTagData)
    {
        $offset = 0; $dataLen = strlen($flvTagData);
        if ($dataLen < 15) return;
        $type = ord($flvTagData[$offset]); $offset += 1;
        $dataSize = (ord($flvTagData[$offset]) << 16) | (ord($flvTagData[$offset+1]) << 8) | ord($flvTagData[$offset+2]);
        $offset += 3;
        $timestampLow = (ord($flvTagData[$offset]) << 16) | (ord($flvTagData[$offset+1]) << 8) | ord($flvTagData[$offset+2]);
        $offset += 3;
        $timestampExt = ord($flvTagData[$offset]); $offset += 1;
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
        if ($this->baseTimestamp === null) {
            if (!$isKeyFrame) return;
            $this->baseTimestamp = $timestamp;
            $this->segmentStartTime = 0;
            $this->startSegment();
        }

        $relativeTime = $timestamp - $this->baseTimestamp;
        if ($isKeyFrame && ($relativeTime - $this->segmentStartTime) >= ($this->segmentDuration * 1000)) {
            $this->closeSegment($relativeTime);
            $this->segmentStartTime = $relativeTime;
            $this->startSegment();
        }

        $this->currentSegmentLastTime = $relativeTime;

        // CTS 已在 avcPacketRead 中正确解析为有符号整数
        $cts = $avc['compositionTime'] ?? 0;

        // 使用单调递增的帧计数器生成 DTS（确保严格递增）
        // DTS = 帧计数 * 帧间隔（90kHz）
        $dts = $this->videoFrameCount * $this->frameInterval;
        
        // PTS = DTS + CTS * 90
        $pts = $dts + $cts * 90;

        // 确保 PTS >= DTS
        if ($pts < $dts) {
            $pts = $dts;
        }

        // 更新帧计数器
        $this->videoFrameCount++;

        // 保存当前时间戳用于参考
        $this->lastVideoDts = $dts;
        $this->lastVideoPts = $pts;

        // 构建 AnnexB 格式的 NAL 单元
        $annexb = $this->avccToAnnexB($avc['data']);

        // 如果是关键帧，并且有 SPS/PPS 数据，将其拼接在关键帧前面
        // 这确保解码器可以正确初始化
        if ($isKeyFrame && $this->spsPpsData !== '') {
            $annexb = $this->spsPpsData . $annexb;
        }

        $pes = $this->createPES(0xE0, $annexb, $pts, ($pts != $dts) ? $dts : null);
        // 仅关键帧写入 PCR
        $this->writeTSPackets($this->videoPid, $pes, $isKeyFrame, $dts);
    }

    private function handleAudioFrame(int $timestamp, string $rawData): void
    {
        $raw = $rawData;
        if (strlen($raw) < 2) return;
        if ((ord($raw[0]) >> 4) != 10) return; // 仅 AAC

        $aacPacketType = ord($raw[1]);
        if ($aacPacketType == 0) {
            $asc = substr($raw, 2);
            if (strlen($asc) >= 2) $this->parseAudioSpecificConfig($asc);
            return;
        }
        if ($aacPacketType != 1 || $this->baseTimestamp === null || $this->audioObjectType === null) return;

        $aacRaw = substr($raw, 2);
        if ($aacRaw === '') return;
        
        // 使用单调递增的计数器生成音频 PTS
        $pts = $this->audioFrameCount * $this->audioInterval;
        $this->audioFrameCount++;

        $adts = $this->createADTSHeader(strlen($aacRaw));
        $pes = $this->createPES(0xC0, $adts . $aacRaw, $pts, null);
        $this->writeTSPackets($this->audioPid, $pes, false, 0);
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

        // 处理 HE-AAC / SBR
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
        $samplingIndex = $this->samplingFrequencyIndex;
        if ($this->sbrPresent && $this->extensionSamplingIndex !== null) {
            $samplingIndex = $this->extensionSamplingIndex;
        }
        $channelConfig = $this->channelConfiguration;
        $frameLength = $aacLength + 7;

        return pack('CCCCCCC',
            0xFF, 0xF1,
            (($profile & 0x03) << 6) | (($samplingIndex & 0x0F) << 2) | (($channelConfig >> 2) & 0x01),
            (($channelConfig & 0x03) << 6) | (($frameLength >> 11) & 0x03),
            ($frameLength >> 3) & 0xFF,
            (($frameLength & 0x07) << 5) | 0x1F,
            0xFC
        );
    }

    static function videoFrameDataRead($videoData) {
        $firstByte = ord($videoData[0]);
        return ['frameType' => $firstByte >> 4, 'codecId' => $firstByte & 15, 'data' => substr($videoData, 1)];
    }

    static function avcPacketRead($avcPacket) {
        // CompositionTime 是有符号 24 位整数 (SI24)，不是无符号 (UI24)
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
        $secLen = strlen($body) + 4;
        $section = "\x02" . chr(0xB0 | (($secLen >> 8) & 0x0F)) . chr($secLen & 0xFF) . $body;
        $section .= pack('N', $this->crc32mpeg($section));
        $this->writeTSPacketsRaw($this->pmtPid, "\x00" . $section);
    }

    private function writeTSPacketsRaw(int $pid, string $payload): void
    {
        $cc = &$this->continuityCounters[$pid];
        if (!isset($cc)) $cc = 0;
        $offset = 0; $payloadLen = strlen($payload); $first = true;
        while ($offset < $payloadLen) {
            $chunkSize = min($payloadLen - $offset, 184);
            $packet = "\x47" . chr((($first ? 1 : 0) << 6) | (($pid >> 8) & 0x1F)) . chr($pid & 0xFF);
            $packet .= chr(0x10 | ($cc & 0x0F));
            $cc = ($cc + 1) & 0x0F;
            $packet .= substr($payload, $offset, $chunkSize);
            if (strlen($packet) < 188) $packet = str_pad($packet, 188, "\xFF");
            fwrite($this->tsHandle, $packet);
            $offset += $chunkSize; $first = false;
        }
    }

    private function parseAVCDecoderConfigurationRecord(string $data): void
    {
        $offset = 5; $numSps = ord($data[$offset]) & 0x1F; $offset++;
        $result = '';
        for ($i = 0; $i < $numSps; $i++) {
            $len = unpack('n', substr($data, $offset, 2))[1]; $offset += 2;
            $result .= "\x00\x00\x00\x01" . substr($data, $offset, $len);
            $offset += $len;
        }
        $numPps = ord($data[$offset]); $offset++;
        for ($i = 0; $i < $numPps; $i++) {
            $len = unpack('n', substr($data, $offset, 2))[1]; $offset += 2;
            $result .= "\x00\x00\x00\x01" . substr($data, $offset, $len);
            $offset += $len;
        }
        $this->spsPpsData = $result;
    }

    private function avccToAnnexB(string $data): string
    {
        $offset = 0; $result = ''; $len = strlen($data);
        while ($offset + 4 <= $len) {
            $nalSize = unpack('N', substr($data, $offset, 4))[1]; $offset += 4;
            if ($offset + $nalSize > $len) break;
            $result .= "\x00\x00\x00\x01" . substr($data, $offset, $nalSize);
            $offset += $nalSize;
        }
        return $result;
    }

    private function createPES(int $streamId, string $payload, int $pts, ?int $dts): string
    {
        $ptsDtsFlags = ($dts !== null && $dts != $pts) ? 0xC0 : 0x80;
        $headerData = $this->encodeTimestamp(($dts !== null && $dts != $pts) ? 0x03 : 0x02, $pts);
        if ($dts !== null && $dts != $pts) $headerData .= $this->encodeTimestamp(0x01, $dts);
        $headerLen = strlen($headerData);
        $packetLen = strlen($payload) + 3 + $headerLen;
        if ($packetLen > 0xFFFF) $packetLen = 0;
        return "\x00\x00\x01" . chr($streamId) . pack('n', $packetLen) . "\x80" . chr($ptsDtsFlags) . chr($headerLen) . $headerData . $payload;
    }

    private function encodeTimestamp(int $type, int $ts): string
    {
        $ts &= 0x1FFFFFFFF;
        return pack('CCCCC',
            (($type << 4) & 0xF0) | ((($ts >> 30) & 0x07) << 1) | 1,
            ($ts >> 22) & 0xFF,
            ((($ts >> 15) & 0x7F) << 1) | 1,
            ($ts >> 7) & 0xFF,
            (($ts & 0x7F) << 1) | 1);
    }

    private function encodePCR(int $pcr): string
    {
        return pack('CCCCCC',
            ($pcr >> 25) & 0xFF, ($pcr >> 17) & 0xFF, ($pcr >> 9) & 0xFF,
            ($pcr >> 1) & 0xFF, (($pcr & 1) << 7) | 0x7E, 0x00);
    }

    private function writeTSPackets(int $pid, string $payload, bool $writePCR = false, int $pcr = 0): void
    {
        $cc = &$this->continuityCounters[$pid];
        if (!isset($cc)) $cc = 0;
        $offset = 0; $payloadLen = strlen($payload); $first = true;
        while ($offset < $payloadLen) {
            $remaining = $payloadLen - $offset;
            $packet = "\x47" . chr((($first ? 1 : 0) << 6) | (($pid >> 8) & 0x1F)) . chr($pid & 0xFF);
            $adaptationField = ''; $adaptationControl = 1;
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
                    if ($stuffing > 2) $adaptationField .= str_repeat("\xFF", $stuffing - 2);
                } else {
                    $newLen = min(255, ord($adaptationField[0]) + $stuffing);
                    $adaptationField[0] = chr($newLen);
                    $adaptationField .= str_repeat("\xFF", $stuffing);
                }
                $payloadSpace = 188 - 4 - strlen($adaptationField);
            }
            $packet .= chr(($adaptationControl << 4) | ($cc & 0x0F));
            $cc = ($cc + 1) & 0x0F;
            $packet .= $adaptationField . substr($payload, $offset, $payloadSpace);
            $packet = str_pad($packet, 188, "\xFF");
            fwrite($this->tsHandle, $packet);
            $offset += $payloadSpace; $first = false;
        }
    }

    private function startSegment(): void
    {
        $this->sequenceNumber++;
        $this->continuityCounters = [];
        $this->lastVideoPts = -1;
        $this->lastVideoDts = -1;
        $this->lastAudioPts = -1;
        $this->spsPpsWritten = false;

        $file = $this->streamDir . "segment_{$this->sequenceNumber}.ts";
        $this->tsHandle = fopen($file, 'wb');
        $this->writePAT();
        $this->writePMT();

        // ★ 独立发送 SPS/PPS，不影响视频帧 DTS 序列
        if ($this->spsPpsData !== '') {
            $startPcr = (int)($this->segmentStartTime * 90);
            $spsPpsPes = $this->createPES(0xE0, $this->spsPpsData, $startPcr, null);
            $this->writeTSPackets($this->videoPid, $spsPpsPes, false, $startPcr);
        }
    }

    private function closeSegment(int $endTime = 0): void
    {
        if ($this->tsHandle) {
            fclose($this->tsHandle); $this->tsHandle = null;
            $end = $endTime > 0 ? $endTime : $this->currentSegmentLastTime;
            $actual = max(0.001, round(($end - $this->segmentStartTime) / 1000.0, 3));
            $this->segmentDurations[$this->sequenceNumber] = $actual;
            $this->updatePlaylist();
        }
    }

    private function crc32mpeg(string $data): int
    {
        $crc = 0xFFFFFFFF;
        for ($i = 0; $i < strlen($data); $i++) {
            $crc ^= ord($data[$i]) << 24;
            for ($j = 0; $j < 8; $j++) {
                $crc = ($crc & 0x80000000) ? (($crc << 1) ^ 0x04C11DB7) & 0xFFFFFFFF : ($crc << 1) & 0xFFFFFFFF;
            }
        }
        return $crc;
    }

    private function updatePlaylist(): void
    {
        $lines = ["#EXTM3U", "#EXT-X-VERSION:3"];
        $maxDur = $this->segmentDuration;
        foreach ($this->segmentDurations as $d) $maxDur = max($maxDur, ceil($d));
        $lines[] = "#EXT-X-TARGETDURATION:" . (int)$maxDur;
        $lines[] = "#EXT-X-MEDIA-SEQUENCE:1";
        $lines[] = '#EXT-X-INDEPENDENT-SEGMENTS';
        for ($i = 1; $i <= $this->sequenceNumber; $i++) {
            $dur = $this->segmentDurations[$i] ?? $this->segmentDuration;
            $lines[] = "#EXTINF:" . number_format($dur, 3, '.', '') . ",";
            $lines[] = "segment_{$i}.ts";
        }
        $content = implode("\n", $lines) . "\n";
        $path = $this->streamDir . "index.m3u8";
        file_put_contents($path . '.tmp', $content);
        rename($path . '.tmp', $path);
    }

    public function getIndex() { return $this->streamDir . "index.m3u8"; }

    public function close(): void
    {
        $this->closeSegment($this->currentSegmentLastTime);
        $m3u8Path = $this->streamDir . 'index.m3u8';
        if (file_exists($m3u8Path)) {
            $m3u8 = rtrim(file_get_contents($m3u8Path)) . "\n";
            if (strpos($m3u8, '#EXT-X-ENDLIST') === false) $m3u8 .= "#EXT-X-ENDLIST\n";
            file_put_contents($m3u8Path, $m3u8);
        }
    }

    public function __destruct() { $this->close(); }
}