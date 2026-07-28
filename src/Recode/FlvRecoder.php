<?php

namespace Xiaosongshu\Flv2mp4\Recode;

use Xiaosongshu\Flv2mp4\Codec\H264Decoder;
use Xiaosongshu\Flv2mp4\Codec\H264Encoder;
use Xiaosongshu\Flv2mp4\Codec\NalUtil;
use Xiaosongshu\Flv2mp4\Codec\Scaler\VideoScaler;
use Xiaosongshu\Flv2mp4\Flv\FlvParse;
use Xiaosongshu\Flv2mp4\Flv\FlvTag;

/**
 * @purpose flv重编码（改变分辨率/码率）
 * @author yanglong
 * @time 2026年7月28日
 * @note 本转码器目前仅支持baseline profile
 */
class FlvRecoder
{
    const VIDEO_FRAME_TYPE_KEY_FRAME = 1;
    const AVC_PACKET_TYPE_SEQUENCE_HEADER = 0;
    const AVC_PACKET_TYPE_NALU = 1;

    private int $targetWidth = 0;
    private int $targetHeight = 0;
    private int $targetBitrate = 0;
    private int $targetFps = 30;
    private int $targetQp = 26;

    private bool $watermarkEnabled = false;
    private string $watermarkFile = '';
    private string $wmY = '';
    private string $wmU = '';
    private string $wmV = '';
    private int $wmWidth = 0;
    private int $wmHeight = 0;

    private ?H264Decoder $decoder = null;
    private ?H264Encoder $encoder = null;
    private ?VideoScaler $scaler = null;

    private int $srcWidth = 0;
    private int $srcHeight = 0;
    private bool $srcInitialized = false;

    private string $srcSpsData = '';
    private string $srcPpsData = '';

    private string $audioSpecificConfig = '';
    private bool $audioConfigParsed = false;
    private int $audioSampleRate = 44100;
    private int $audioChannels = 2;
    private int $audioObjectType = 2;

    private ?int $baseTimestamp = null;
    private int $videoFrameCount = 0;

    private string $outputBuffer = '';
    private bool $flvHeaderWritten = false;

    private ?int $maxFrames = null;

    private string $encSpsAnnexB = '';
    private string $encPpsAnnexB = '';
    private string $encSpsRbsp = '';
    private string $encPpsRbsp = '';
    private bool $encoderConfigReady = false;

    public function __construct(array $config = [])
    {
        $this->targetWidth = $config['width'] ?? 0;
        $this->targetHeight = $config['height'] ?? 0;
        $this->targetBitrate = $config['bitrate'] ?? 0;
        $this->targetFps = $config['fps'] ?? 30;
        $this->targetQp = $config['qp'] ?? 26;

        if (!empty($config['watermark']) && !empty($config['watermark_file'])) {
            $this->watermarkEnabled = true;
            $this->watermarkFile = $config['watermark_file'];
            $this->loadWatermark();
        }

        $this->decoder = new H264Decoder();
        $this->encoder = new H264Encoder();
        $this->scaler = new VideoScaler();
    }

    private function loadWatermark(): void
    {
        if (!file_exists($this->watermarkFile)) {
            throw new \RuntimeException("水印文件不存在: {$this->watermarkFile}");
        }

        $wmData = file_get_contents($this->watermarkFile);
        if ($wmData === false || $wmData === '') {
            throw new \RuntimeException("无法读取水印文件: {$this->watermarkFile}");
        }

        $wmPath = $this->watermarkFile;
        $basename = basename($wmPath, '.yuv');
        if (preg_match('/_(\d+)x(\d+)$/', $basename, $matches)) {
            $this->wmWidth = (int)$matches[1];
            $this->wmHeight = (int)$matches[2];
        } else {
            $this->wmWidth = 80;
            $this->wmHeight = 16;
        }

        $wmYSize = $this->wmWidth * $this->wmHeight;
        $wmUvSize = $wmYSize >> 2;
        $expectedSize = $wmYSize + $wmUvSize * 2;

        if (strlen($wmData) < $expectedSize) {
            throw new \RuntimeException("水印文件尺寸不匹配: 期望 {$expectedSize} 字节, 实际 " . strlen($wmData) . " 字节");
        }

        $this->wmY = substr($wmData, 0, $wmYSize);
        $this->wmU = substr($wmData, $wmYSize, $wmUvSize);
        $this->wmV = substr($wmData, $wmYSize + $wmUvSize, $wmUvSize);
    }

    private function applyWatermark(string $yuvData, int $frameW, int $frameH): string
    {
        if ($this->wmWidth > $frameW || $this->wmHeight > $frameH) {
            return $yuvData;
        }

        $ySize = $frameW * $frameH;
        $uvW = $frameW >> 1;
        $uvH = $frameH >> 1;
        $uvSize = $uvW * $uvH;

        $dstX = 0;
        $dstY = 0;

        for ($row = 0; $row < $this->wmHeight; $row++) {
            $srcOffset = $row * $this->wmWidth;
            $dstOffset = ($dstY + $row) * $frameW + $dstX;
            for ($col = 0; $col < $this->wmWidth; $col++) {
                $yuvData[$dstOffset + $col] = $this->wmY[$srcOffset + $col];
            }
        }

        $wmUvW = $this->wmWidth >> 1;
        $wmUvH = $this->wmHeight >> 1;
        $dstUvX = $dstX >> 1;
        $dstUvY = $dstY >> 1;

        $uOffset = $ySize;
        $vOffset = $ySize + $uvSize;

        for ($row = 0; $row < $wmUvH; $row++) {
            $srcOffset = $row * $wmUvW;
            $dstOffset = ($dstUvY + $row) * $uvW + $dstUvX;
            for ($col = 0; $col < $wmUvW; $col++) {
                $yuvData[$uOffset + $dstOffset + $col] = $this->wmU[$srcOffset + $col];
                $yuvData[$vOffset + $dstOffset + $col] = $this->wmV[$srcOffset + $col];
            }
        }

        return $yuvData;
    }

    public function setMaxFrames(int $maxFrames): void
    {
        $this->maxFrames = $maxFrames;
    }

    public function processFlv(string $inputFile, string $outputFile): void
    {
        if (!file_exists($inputFile)) {
            throw new \Exception("FLV file not found: {$inputFile}");
        }

        $flvData = file_get_contents($inputFile);
        FlvParse::setFlv($flvData);

        $this->outputBuffer = '';
        $this->flvHeaderWritten = false;

        $frameCount = 0;
        $videoCount = 0;
        $hasAudio = false;
        $hasVideo = false;

        foreach (FlvParse::getTags() as $tag) {
            if (!property_exists($tag, 'tagType')) continue;

            if ($tag->tagType === 9) {
                $hasVideo = true;
                $this->handleVideoFrame($tag);
                $videoCount++;
            } elseif ($tag->tagType === 8) {
                $hasAudio = true;
                $this->handleAudioFrame($tag);
            }

            $frameCount++;
            if ($frameCount % 50 === 0) {
                echo "Processed {$frameCount} frames ({$videoCount} video)\n";
            }
            if ($this->maxFrames !== null && $videoCount >= $this->maxFrames) {
                echo "Reached max frames limit ({$this->maxFrames}), stopping...\n";
                break;
            }
        }

        file_put_contents($outputFile, $this->outputBuffer);
        echo "Done! Processed {$frameCount} frames ({$videoCount} video)\n";
        echo "Output: {$outputFile}\n";
    }

    private function handleVideoFrame(FlvTag $tag): void
    {
        $body = $tag->body ?? null;
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
        $timestamp = $tag->getTime();

        $relativeTime = 0;
        if ($this->baseTimestamp !== null) {
            $relativeTime = $timestamp - $this->baseTimestamp;
        }

        $cts = $avc['compositionTime'] ?? 0;
        if ($cts & 0x800000) $cts -= 0x1000000;
        $avcData = $avc['data'];

        $needTranscode = $this->srcInitialized && (
            $this->targetWidth > 0 && $this->srcWidth !== $this->targetWidth ||
            $this->targetHeight > 0 && $this->srcHeight !== $this->targetHeight ||
            $this->targetBitrate > 0
        );

        if ($needTranscode) {
            $yuvData = $this->decodeNaluToYuv($avcData);
            if ($yuvData === null) return;

            $targetW = $this->targetWidth > 0 ? $this->targetWidth : $this->srcWidth;
            $targetH = $this->targetHeight > 0 ? $this->targetHeight : $this->srcHeight;

            if ($targetW !== $this->srcWidth || $targetH !== $this->srcHeight) {
                $yuvData = $this->scaler->scaleYUV420P(
                    $yuvData,
                    $this->srcWidth, $this->srcHeight,
                    $targetW, $targetH
                );
            }

            if ($this->watermarkEnabled) {
                $yuvData = $this->applyWatermark($yuvData, $targetW, $targetH);
            }

            $this->encoder->setResolution($targetW, $targetH);
            if ($this->targetBitrate > 0) {
                $this->encoder->setBitrate($this->targetBitrate);
            } else {
                $this->encoder->setQp($this->targetQp);
            }
            $this->encoder->setFps($this->targetFps);

            $encodedNals = $this->encoder->encodeFrame($yuvData, $isKeyFrame);
            $this->videoFrameCount++;

            if ($this->baseTimestamp === null) {
                if (!$isKeyFrame) return;
                $this->baseTimestamp = $timestamp;
                $relativeTime = 0;

                $this->extractSpsPpsFromNals($encodedNals);

                $this->writeFlvHeader(true, true);
                $this->writeSequenceHeaderTag();
            }

            $this->writeEncodedVideoFrame($encodedNals, $isKeyFrame, $relativeTime, $cts);
        } else {
            if ($this->baseTimestamp === null) {
                if (!$isKeyFrame) return;
                $this->baseTimestamp = $timestamp;
                $relativeTime = 0;
                $this->writeFlvHeader(true, true);
                $this->writeSequenceHeaderTag();
            }
            $this->writeVideoTag($avcData, $isKeyFrame, $relativeTime, $cts);
        }
    }

    private function extractSpsPpsFromNals(array $nals): void
    {
        foreach ($nals as $nal) {
            $nalHeaderPos = $this->findNalHeaderPos($nal);
            if ($nalHeaderPos < 0) continue;

            $nalRaw = substr($nal, $nalHeaderPos);
            $nalType = ord($nalRaw[0]) & 0x1F;

            if ($nalType === 7) {
                $this->encSpsAnnexB = $nal;
                $nalClean = NalUtil::removeEmulationPrevention($nalRaw);
                $this->encSpsRbsp = substr($nalClean, 1);
            } elseif ($nalType === 8) {
                $this->encPpsAnnexB = $nal;
                $nalClean = NalUtil::removeEmulationPrevention($nalRaw);
                $this->encPpsRbsp = substr($nalClean, 1);
            }
        }
        $this->encoderConfigReady = true;
    }

    private function writeEncodedVideoFrame(array $nals, bool $isKeyFrame, int $timestamp, int $cts): void
    {
        $videoNalsAnnexB = '';
        foreach ($nals as $nal) {
            $nalHeaderPos = $this->findNalHeaderPos($nal);
            if ($nalHeaderPos < 0) continue;
            $nalType = ord($nal[$nalHeaderPos]) & 0x1F;
            if ($nalType === 7 || $nalType === 8) continue;
            $videoNalsAnnexB .= $nal;
        }
        if ($videoNalsAnnexB === '') return;

        $avccData = $this->annexBToAvcc($videoNalsAnnexB);
        $this->writeVideoTag($avccData, $isKeyFrame, $timestamp, $cts);
    }

    private function handleAudioFrame(FlvTag $tag): void
    {
        $raw = $tag->body ?? null;
        if ($raw === null || strlen($raw) < 2) return;

        $soundFormat = (ord($raw[0]) >> 4) & 0x0F;
        if ($soundFormat !== 10) return;

        $aacPacketType = ord($raw[1]);
        if ($aacPacketType === 0) {
            $this->audioSpecificConfig = substr($raw, 2);
            $this->audioConfigParsed = true;
            $this->parseAudioSpecificConfig($this->audioSpecificConfig);
            return;
        }
        if ($aacPacketType !== 1) return;
        if ($this->baseTimestamp === null || !$this->audioConfigParsed) return;

        $aacRaw = substr($raw, 2);
        if ($aacRaw === '') return;

        $timestamp = $tag->getTime();
        $relativeTime = $timestamp - $this->baseTimestamp;

        $this->writeAudioTag($aacRaw, $relativeTime);
    }

    private function parseAudioSpecificConfig(string $config): void
    {
        $len = strlen($config);
        if ($len < 2) return;

        $bytes = unpack('n', substr($config, 0, 2))[1];
        $this->audioObjectType = ($bytes >> 11) & 0x1F;
        $freqIndex = ($bytes >> 7) & 0x0F;
        $channelConfig = ($bytes >> 3) & 0x0F;

        $rates = [96000, 88200, 64000, 48000, 44100, 32000, 24000, 22050, 16000, 12000, 11025, 8000, 7350];
        $baseRate = $rates[$freqIndex] ?? 44100;

        switch ($this->audioObjectType) {
            case 2:
                $this->audioSampleRate = $baseRate;
                $this->audioChannels = $channelConfig;
                break;
            case 5:
            case 29:
                $this->audioSampleRate = $baseRate * 2;
                $this->audioChannels = ($this->audioObjectType == 29) ? 2 : $channelConfig;
                break;
            default:
                $this->audioSampleRate = $baseRate;
                $this->audioChannels = $channelConfig;
                break;
        }
    }

    private function decodeNaluToYuv(string $avcData): ?string
    {
        $nalUnits = $this->extractNalUnitsFromAVCC($avcData);

        if ($this->srcSpsData !== '') {
            array_unshift($nalUnits, ['type' => 7, 'data' => $this->srcSpsData]);
        }
        if ($this->srcPpsData !== '') {
            array_unshift($nalUnits, ['type' => 8, 'data' => $this->srcPpsData]);
        }

        $frame = $this->decoder->decode($nalUnits);
        if ($frame && !empty($frame['data'])) {
            return $frame['data'];
        }
        return null;
    }

    private function parseAVCDecoderConfigurationRecord(string $data): void
    {
        $offset = 5;
        $numSps = ord($data[$offset]) & 0x1F;
        $offset++;

        for ($i = 0; $i < $numSps; $i++) {
            $len = unpack('n', substr($data, $offset, 2))[1];
            $offset += 2;
            $spsData = substr($data, $offset, $len);
            $offset += $len;

            $spsClean = NalUtil::removeEmulationPrevention($spsData);
            $rbspSps = substr($spsClean, 1);

            if (!$this->srcInitialized) {
                $this->srcSpsData = $rbspSps;
                $this->decoder->decode([['type' => 7, 'data' => $rbspSps]], true);
                $this->srcWidth = $this->decoder->getWidth();
                $this->srcHeight = $this->decoder->getHeight();
                $this->srcInitialized = true;
            }
        }

        $numPps = ord($data[$offset]);
        $offset++;
        for ($i = 0; $i < $numPps; $i++) {
            $len = unpack('n', substr($data, $offset, 2))[1];
            $offset += 2;
            $ppsData = substr($data, $offset, $len);
            $offset += $len;

            $ppsClean = NalUtil::removeEmulationPrevention($ppsData);
            $rbspPps = substr($ppsClean, 1);
            $this->srcPpsData = $rbspPps;

            if (!$this->srcInitialized) {
                $this->decoder->decode([['type' => 8, 'data' => $rbspPps]]);
            }
        }
    }

    private function writeFlvHeader(bool $hasVideo, bool $hasAudio): void
    {
        if ($this->flvHeaderWritten) return;

        $flags = 0;
        if ($hasVideo) $flags |= 0x01;
        if ($hasAudio) $flags |= 0x04;

        $header = 'FLV';
        $header .= chr(1);
        $header .= chr($flags);
        $header .= pack('N', 9);
        $header .= pack('N', 0);

        $this->outputBuffer .= $header;
        $this->flvHeaderWritten = true;
    }

    private function writeSequenceHeaderTag(): void
    {
        $this->writeVideoSequenceHeader();
        if ($this->audioConfigParsed && $this->audioSpecificConfig !== '') {
            $this->writeAudioSequenceHeader();
        }
    }

    private function writeVideoSequenceHeader(): void
    {
        $avcConfig = $this->buildAVCDecoderConfigurationRecord();
        $videoTagBody = "\x17\x00\x00\x00\x00" . $avcConfig;
        $this->writeTag(9, $videoTagBody, 0);
    }

    private function writeAudioSequenceHeader(): void
    {
        $audioHeader = $this->getAudioHeaderByte();
        $audioTagBody = chr($audioHeader) . chr(0) . $this->audioSpecificConfig;
        $this->writeTag(8, $audioTagBody, 0);
    }

    private function buildAVCDecoderConfigurationRecord(): string
    {
        $spsAnnexB = $this->encSpsAnnexB;
        $ppsAnnexB = $this->encPpsAnnexB;

        $spsPos = $this->findNalHeaderPos($spsAnnexB);
        $ppsPos = $this->findNalHeaderPos($ppsAnnexB);

        if ($spsPos < 0 || $ppsPos < 0) {
            $spsClean = NalUtil::removeEmulationPrevention("\x67" . $this->srcSpsData);
            $ppsClean = NalUtil::removeEmulationPrevention("\x68" . $this->srcPpsData);
            $spsRaw = $spsClean;
            $ppsRaw = $ppsClean;
        } else {
            $spsRaw = substr($spsAnnexB, $spsPos);
            $ppsRaw = substr($ppsAnnexB, $ppsPos);
        }

        $spsLen = strlen($spsRaw);
        $ppsLen = strlen($ppsRaw);

        $config = '';
        $config .= chr(1);
        $config .= $spsLen > 1 ? $spsRaw[1] : chr(0);
        $config .= $spsLen > 2 ? $spsRaw[2] : chr(0);
        $config .= $spsLen > 3 ? $spsRaw[3] : chr(0);
        $config .= chr(0xFF);
        $config .= chr(0xE0 | 1);
        $config .= pack('n', $spsLen);
        $config .= $spsRaw;
        $config .= chr(1);
        $config .= pack('n', $ppsLen);
        $config .= $ppsRaw;

        return $config;
    }

    private function updateSequenceHeader(): void
    {
    }

    private function writeVideoTag(string $avccData, bool $isKeyFrame, int $timestamp, int $cts): void
    {
        $frameType = $isKeyFrame ? 0x10 : 0x20;
        $codecId = 0x07;
        $avcPacketType = 1;

        $ctsBytes = '';
        $ctsVal = $cts & 0xFFFFFF;
        $ctsBytes .= chr(($ctsVal >> 16) & 0xFF);
        $ctsBytes .= chr(($ctsVal >> 8) & 0xFF);
        $ctsBytes .= chr($ctsVal & 0xFF);

        $body = chr($frameType | $codecId);
        $body .= chr($avcPacketType);
        $body .= $ctsBytes;
        $body .= $avccData;

        $this->writeTag(9, $body, $timestamp);
    }

    private function writeAudioTag(string $aacRaw, int $timestamp): void
    {
        $audioHeader = $this->getAudioHeaderByte();
        $body = chr($audioHeader) . chr(1) . $aacRaw;

        $this->writeTag(8, $body, $timestamp);
    }

    private function getSoundRateValue(): int
    {
        switch ($this->audioSampleRate) {
            case 5512:  return 0;
            case 11025: return 1;
            case 22050: return 2;
            case 44100: return 3;
            case 48000: return 4;
            default:    return 3;
        }
    }

    private function getAudioHeaderByte(): int
    {
        $soundFormat = 10;
        $soundRate = $this->getSoundRateValue();
        $soundSize = 1;
        $soundType = ($this->audioChannels == 2) ? 1 : 0;
        return ($soundFormat << 4) | ($soundRate << 2) | ($soundSize << 1) | $soundType;
    }

    private function writeTag(int $tagType, string $data, int $timestamp): void
    {
        $dataSize = strlen($data);

        $tagHeader = '';
        $tagHeader .= chr($tagType);
        $tagHeader .= chr(($dataSize >> 16) & 0xFF);
        $tagHeader .= chr(($dataSize >> 8) & 0xFF);
        $tagHeader .= chr($dataSize & 0xFF);

        $tsLow = $timestamp & 0xFFFFFF;
        $tsExt = ($timestamp >> 24) & 0xFF;
        $tagHeader .= chr(($tsLow >> 16) & 0xFF);
        $tagHeader .= chr(($tsLow >> 8) & 0xFF);
        $tagHeader .= chr($tsLow & 0xFF);
        $tagHeader .= chr($tsExt);

        $tagHeader .= chr(0);
        $tagHeader .= chr(0);
        $tagHeader .= chr(0);

        $prevTagSize = 11 + $dataSize;

        $this->outputBuffer .= $tagHeader;
        $this->outputBuffer .= $data;
        $this->outputBuffer .= pack('N', $prevTagSize);
    }

    private function videoFrameDataRead(string $data): ?array
    {
        if (strlen($data) < 1) return null;
        $b0 = ord($data[0]);
        return [
            'frameType' => $b0 >> 4,
            'codecId' => $b0 & 0x0F,
            'data' => substr($data, 1)
        ];
    }

    private function avcPacketRead(string $packet): ?array
    {
        if (strlen($packet) < 1) return null;
        $avcPacketType = ord($packet[0]);

        if ($avcPacketType === 0) {
            return [
                'avcPacketType' => $avcPacketType,
                'compositionTime' => 0,
                'data' => substr($packet, 4)
            ];
        }

        if (strlen($packet) < 4) return null;
        $cts = (ord($packet[1]) << 16) | (ord($packet[2]) << 8) | ord($packet[3]);
        if ($cts & 0x800000) $cts -= 0x1000000;

        return [
            'avcPacketType' => $avcPacketType,
            'compositionTime' => $cts,
            'data' => substr($packet, 4)
        ];
    }

    private function extractNalUnitsFromAVCC(string $data): array
    {
        $list = [];
        $offset = 0;
        $totalLen = strlen($data);
        while ($offset + 4 <= $totalLen) {
            $nalSize = unpack('N', substr($data, $offset, 4))[1];
            $offset += 4;
            if ($offset + $nalSize > $totalLen) break;
            $nalRaw = substr($data, $offset, $nalSize);
            $offset += $nalSize;

            $nalClean = NalUtil::removeEmulationPrevention($nalRaw);
            $type = ord($nalClean[0]) & 0x1F;
            $rbspData = substr($nalClean, 1);
            $list[] = ['type' => $type, 'data' => $rbspData, 'raw' => $nalClean];
        }
        return $list;
    }

    private function annexBToAvcc(string $annexBuf): string
    {
        $nals = NalUtil::splitNalUnits($annexBuf);
        $result = '';
        foreach ($nals as $nal) {
            $nalRaw = $nal['raw'];
            $nalLen = strlen($nalRaw);
            $result .= pack('N', $nalLen);
            $result .= $nalRaw;
        }
        return $result;
    }

    private function findNalHeaderPos(string $nal): int
    {
        $len = strlen($nal);
        if ($len < 4) return -1;
        if (substr($nal, 0, 4) === "\x00\x00\x00\x01") return 4;
        if ($len >= 3 && substr($nal, 0, 3) === "\x00\x00\x01") return 3;
        return -1;
    }
}
