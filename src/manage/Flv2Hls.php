<?php

namespace Xiaosongshu\Flv2mp4\manage;

use Xiaosongshu\Flv2mp4\Flv\FlvParse;

/**
 * @purpose flv转hls最终版
 * @author yanglong
 * @time 2026-06-12 00:02:00
 */
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
    private $audioFrameCount = 0;
    private $lastVideoPts = -1;
    private $lastVideoDts = -1;
    private $lastAudioPts = -1;
    private $spsPpsWritten = false;
    private $audioObjectType = 2;
    private $samplingFrequencyIndex = 4;
    private $channelConfiguration = 2;
    private $sbrPresent = false;
    private $extensionSamplingIndex = null;
    private $audioSampleRate = 44100;

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

        $cts = $avc['compositionTime'] ?? 0;
        $dts = (int)(($relativeTime - $cts) * 90);
        $pts = (int)($relativeTime * 90);

        if ($pts < $dts) {
            $dts = $pts;
        }

        if ($dts <= $this->lastVideoDts) {
            $dts = $this->lastVideoDts + 1;
            $pts = $dts + abs($cts) * 90;
            if ($pts < $dts) $pts = $dts;
        }

        $this->lastVideoDts = $dts;
        $this->lastVideoPts = $pts;
        $this->videoFrameCount++;

        $annexb = $this->avccToAnnexB($avc['data']);

        if ($isKeyFrame && $this->spsPpsData !== '') {
            $annexb = $this->spsPpsData . $annexb;
        }

        $pes = $this->createPES(0xE0, $annexb, $pts, ($pts != $dts) ? $dts : null);
        $this->writeTSPackets($this->videoPid, $pes, $isKeyFrame, $dts);
    }

    private function handleAudioFrame(int $timestamp, string $rawData): void
    {
        if (strlen($rawData) < 2) return;

        $soundFormat = (ord($rawData[0]) >> 4) & 0x0F;
        if ($soundFormat != 10) return; // 仅处理 AAC

        $aacPacketType = ord($rawData[1]);

        if ($aacPacketType == 0) {
            // Audio Specific Config
            $asc = substr($rawData, 2);
            if (strlen($asc) >= 2) {
                $this->parseAudioSpecificConfig($asc);
            }
            return;
        }

        if ($aacPacketType != 1 || $this->baseTimestamp === null) return;

        $aacRaw = substr($rawData, 2);
        if (strlen($aacRaw) === 0) return;

        // ★★★ 检测 AAC 数据是否已经包含 ADTS 头 ★★★
        // ADTS 头以 0xFF 0xFx 开头
        $hasADTS = false;
        if (strlen($aacRaw) >= 2) {
            $firstByte = ord($aacRaw[0]);
            $secondByte = ord($aacRaw[1]);
            // ADTS syncword: 12 bits = 0xFFF, so first byte = 0xFF, second byte high nibble = 0xF
            $hasADTS = ($firstByte == 0xFF && ($secondByte & 0xF0) == 0xF0);
        }

        if ($hasADTS) {
            // AAC 数据已包含 ADTS 头，直接使用
            $audioPayload = $aacRaw;

            // 从现有的 ADTS 头中提取音频参数
            if (strlen($aacRaw) >= 7) {
                $this->audioObjectType = ((ord($aacRaw[2]) >> 6) & 0x03) + 1;
                $this->samplingFrequencyIndex = (ord($aacRaw[2]) >> 2) & 0x0F;
                $this->channelConfiguration = ((ord($aacRaw[2]) & 0x01) << 2) | ((ord($aacRaw[3]) >> 6) & 0x03);
            }
        } else {
            // AAC 原始数据，需要添加 ADTS 头
            $adtsHeader = $this->createADTSHeader(strlen($aacRaw));
            $audioPayload = $adtsHeader . $aacRaw;
        }

        // 转换时间戳
        $relativeTime = $timestamp - $this->baseTimestamp;
        $pts = (int)($relativeTime * 90);

        // 确保 PTS 严格递增
        if ($pts <= $this->lastAudioPts) {
            $pts = $this->lastAudioPts + 1;
        }
        $this->lastAudioPts = $pts;
        $this->audioFrameCount++;

        // 创建 PES 包
        $pes = $this->createPES(0xC0, $audioPayload, $pts, null);
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

        // 设置采样率
        $sampleRates = [96000, 88200, 64000, 48000, 44100, 32000, 24000, 22050, 16000, 12000, 11025, 8000, 7350];
        $this->audioSampleRate = $sampleRates[$this->samplingFrequencyIndex] ?? 44100;

        // 处理 HE-AAC / SBR
        if ($this->audioObjectType == 5 || $this->audioObjectType == 29) {
            $this->sbrPresent = true;
            if (strlen($asc) >= 4) {
                $extSamplingIndex = (ord($asc[2]) >> 3) & 0x0F;
                $this->extensionSamplingIndex = $extSamplingIndex;
                if (isset($sampleRates[$extSamplingIndex])) {
                    $this->audioSampleRate = $sampleRates[$extSamplingIndex];
                }
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

        if ($samplingIndex < 0 || $samplingIndex > 11) {
            $samplingIndex = 4; // 默认 44100Hz
        }

        $channelConfig = $this->channelConfiguration;
        if ($channelConfig < 0 || $channelConfig > 7) {
            $channelConfig = 2; // 默认立体声
        }

        $frameLength = $aacLength + 7; // ADTS header is 7 bytes

        if ($frameLength > 0x1FFF) {
            $frameLength = 0x1FFF; // 最大 8191
        }

        // 构建 ADTS 头部 (7 bytes) - 严格按照 ADTS 规范
        return chr(0xFF)  // syncword 前8位
            . chr(0xF1)  // syncword 后4位(0xF) + MPEG version(0=MPEG-2) + layer(0) + protection(1=no CRC)
            . chr((($profile & 0x03) << 6) | (($samplingIndex & 0x0F) << 2) | (($channelConfig >> 2) & 0x01))
            . chr((($channelConfig & 0x03) << 6) | (($frameLength >> 11) & 0x03))
            . chr(($frameLength >> 3) & 0xFF)
            . chr((($frameLength & 0x07) << 5) | 0x1F) // buffer_fullness = 0x7FF (all 1s)
            . chr(0xFC); // buffer_fullness remaining + number_of_raw_data_blocks = 0
    }

    static function videoFrameDataRead($videoData) {
        if (strlen($videoData) < 1) return null;
        $firstByte = ord($videoData[0]);
        return ['frameType' => $firstByte >> 4, 'codecId' => $firstByte & 15, 'data' => substr($videoData, 1)];
    }

    static function avcPacketRead($avcPacket) {
        if (strlen($avcPacket) < 4) return null;
        // CompositionTime 是有符号 24 位整数
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

    private function writePMT2(): void
    {
        $body = "\x00\x01\xC1\x00\x00" . pack('n', 0xE000 | $this->videoPid) . "\xF0\x00";
        $body .= "\x1B" . pack('n', 0xE000 | $this->videoPid) . "\xF0\x00";
        $body .= "\x0F" . pack('n', 0xE000 | $this->audioPid) . "\xF0\x00";
        $secLen = strlen($body) + 4;
        $section = "\x02" . chr(0xB0 | (($secLen >> 8) & 0x0F)) . chr($secLen & 0xFF) . $body;
        $section .= pack('N', $this->crc32mpeg($section));
        $this->writeTSPacketsRaw($this->pmtPid, "\x00" . $section);
    }

    private function writePMT(): void
    {
        // 音频流描述符
        // 0x0F = AAC descriptor tag
        // 需要包含 AudioSpecificConfig
        $audioDesc = '';
        if ($this->audioObjectType !== null) {
            // 构建 AudioSpecificConfig (2 bytes minimum)
            $asc = chr(($this->audioObjectType << 3) | ($this->samplingFrequencyIndex >> 1));
            $asc .= chr((($this->samplingFrequencyIndex & 0x01) << 7) | ($this->channelConfiguration << 3) | 0x00);
            // descriptor_tag(0x0F) + descriptor_length + ASC
            $audioDesc = "\x0F" . chr(strlen($asc)) . $asc;
        }

        $body = "\x00\x01\xC1\x00\x00" . pack('n', 0xE000 | $this->videoPid) . "\xF0\x00";
        $body .= "\x1B" . pack('n', 0xE000 | $this->videoPid) . "\xF0\x00";
        $body .= "\x0F" . pack('n', 0xE000 | $this->audioPid) . "\xF0\x00";

        // 添加音频描述符
        if ($audioDesc !== '') {
            $body .= $audioDesc;
        }

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
        $hasDts = ($dts !== null && $dts != $pts);
        $ptsDtsFlags = $hasDts ? 0xC0 : 0x80;

        $headerData = $this->encodeTimestamp($hasDts ? 0x03 : 0x02, $pts);
        if ($hasDts) {
            $headerData .= $this->encodeTimestamp(0x01, $dts);
        }

        $headerLen = strlen($headerData);
        $packetLen = 3 + $headerLen + strlen($payload);
        if ($packetLen > 0xFFFF) {
            $packetLen = 0;
        }

        return "\x00\x00\x01"           // packet_start_code_prefix
            . chr($streamId)            // stream_id
            . pack('n', $packetLen)     // PES_packet_length
            . "\x80"                    // PES header flags ('10' + 6 flags)
            . chr($ptsDtsFlags)         // PTS_DTS_flags
            . chr($headerLen)           // PES_header_data_length
            . $headerData               // PTS/DTS data
            . $payload;                 // ES data (ADTS + AAC)
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
        $offset = 0;
        $payloadLen = strlen($payload);
        $first = true;

        while ($offset < $payloadLen) {
            $remaining = $payloadLen - $offset;

            // 构建 TS 包头 (4 bytes)
            // sync_byte (0x47) + transport_error_indicator + payload_unit_start_indicator + transport_priority + PID (13 bits)
            $tsHeader = "\x47";
            $tsHeader .= chr((($first ? 1 : 0) << 6) | (($pid >> 8) & 0x1F));
            $tsHeader .= chr($pid & 0xFF);

            // adaptation_field_control 和 continuity_counter
            // adaptation_field_control: 01 = no adaptation field, payload only
            // adaptation_field_control: 11 = adaptation field followed by payload
            $adaptationControl = 1; // 默认：只有 payload
            $adaptationField = '';

            // 如果需要写入 PCR
            if ($writePCR && $first) {
                $adaptationControl = 3; // adaptation field + payload
                // adaptation_field_length = 7, PCR_flag = 0x10, PCR = 6 bytes
                $adaptationField = chr(7) . chr(0x10) . $this->encodePCR($pcr);
            }

            // 计算 payload 可用空间
            $availableSpace = 188 - 4 - strlen($adaptationField);

            // 如果剩余数据不够填满 payload 空间，添加 stuffing
            if ($remaining < $availableSpace) {
                $stuffingSize = $availableSpace - $remaining;

                if ($adaptationField === '') {
                    // 创建新的 adaptation field
                    $adaptationControl = 3;
                    // adaptation_field_length = stuffingSize - 1 (不包含 length 字段本身)
                    $adaptationField = chr($stuffingSize - 1);
                    if ($stuffingSize > 1) {
                        $adaptationField .= str_repeat("\xFF", $stuffingSize - 1);
                    }
                } else {
                    // 扩展现有的 adaptation field
                    $currentLen = ord($adaptationField[0]);
                    $newLen = $currentLen + $stuffingSize;
                    if ($newLen > 255) {
                        $newLen = 255;
                        $stuffingSize = 255 - $currentLen;
                    }
                    $adaptationField[0] = chr($newLen);
                    $adaptationField .= str_repeat("\xFF", $stuffingSize);
                }

                $availableSpace = 188 - 4 - strlen($adaptationField);
            }

            // 确保 availableSpace 不超过 remaining
            $chunkSize = min($remaining, $availableSpace);

            // 写入 TS 包头 + adaptation_field_control + continuity_counter
            $tsHeader .= chr(($adaptationControl << 4) | ($cc & 0x0F));
            $cc = ($cc + 1) & 0x0F;

            // 组合 TS 包
            $tsPacket = $tsHeader . $adaptationField . substr($payload, $offset, $chunkSize);

            // 填充到 188 字节
            if (strlen($tsPacket) < 188) {
                $tsPacket = str_pad($tsPacket, 188, "\xFF");
            }

            fwrite($this->tsHandle, $tsPacket);

            $offset += $chunkSize;
            $first = false;
        }
    }
    private function writeTSPackets2(int $pid, string $payload, bool $writePCR = false, int $pcr = 0): void
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
        if (!$this->tsHandle) {
            throw new \Exception("无法创建 TS 文件: {$file}");
        }

        $this->writePAT();
        $this->writePMT();
    }

    private function closeSegment(int $endTime = 0): void
    {
        if ($this->tsHandle) {
            fclose($this->tsHandle);
            $this->tsHandle = null;
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