<?php

namespace Xiaosongshu\Flv2mp4\Manage;

use Xiaosongshu\Flv2mp4\Flv\FlvParse;
use Xiaosongshu\Flv2mp4\Codec\H264Decoder;
use Xiaosongshu\Flv2mp4\Codec\H264Encoder;
use Xiaosongshu\Flv2mp4\Codec\VideoScaler;

class PurePhpHlsGenerator
{
    private int $segmentDuration = 4;
    private int $videoPid = 0x100;
    private int $audioPid = 0x101;
    private int $pmtPid = 0x1000;

    private array $profiles;
    private string $outputDir;
    private array $segmentWriters = [];
    private array $segmentDurations = [];
    private array $spsPpsData = [];
    private array $continuityCounters = [];

    private ?int $baseTimestamp = null;
    private array $segmentStartTimes = [];
    private array $currentSegmentLastTimes = [];

    private string $audioSpecificConfig = '';
    private int $audioObjectType = 2;
    private int $samplingFrequencyIndex = 4;
    private int $channelConfiguration = 2;
    private bool $sbrPresent = false;
    private ?int $extensionSamplingIndex = null;

    private array $audioFrameCounts = [];
    private array $audioBasePts = [];

    private H264Decoder $decoder;
    private H264Encoder $encoder;
    private VideoScaler $scaler;
    private ?string $lastDecodedFrame = null;

    private int $srcWidth = 0;
    private int $srcHeight = 0;
    private bool $srcInitialized = false;
    private array $generatedSpsPps = [];
    private array $videoFrameCounts = [];
    private array $lastDts = [];

    const VIDEO_FRAME_TYPE_KEY_FRAME = 1;
    const AVC_PACKET_TYPE_SEQUENCE_HEADER = 0;
    const AVC_PACKET_TYPE_NALU = 1;

    public function __construct(array $profiles, string $outputDir)
    {
        $this->profiles = $profiles;
        $this->outputDir = rtrim($outputDir, '/');

        $this->decoder = new H264Decoder();
        $this->encoder = new H264Encoder();
        $this->scaler = new VideoScaler();

        foreach ($this->profiles as $name => $profile) {
            $dir = "{$this->outputDir}/{$name}/";
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }

            $this->segmentWriters[$name] = [
                'sequence' => 0,
                'handle' => null,
                'startTime' => 0,
                'endTime' => 0,
            ];

            $this->segmentDurations[$name] = [];
            $this->spsPpsData[$name] = '';
            $this->continuityCounters[$name] = [];
            $this->segmentStartTimes[$name] = 0;
            $this->currentSegmentLastTimes[$name] = 0;
            $this->audioFrameCounts[$name] = 0;
            $this->audioBasePts[$name] = null;
            $this->videoFrameCounts[$name] = 0;
            $this->lastDts[$name] = -1;

            $this->ensureInitialPlaylist($name);
        }
    }

    public function processFlv(string $flvFile): void
    {
        if (!file_exists($flvFile)) {
            throw new \Exception("FLV file not found: {$flvFile}");
        }

        $flvData = file_get_contents($flvFile);
        FlvParse::setFlv($flvData);

        $frameCount = 0;
        foreach (FlvParse::getTags() as $tag) {
            if (property_exists($tag, 'tagType')) {
                if ($tag->tagType === 9) {
                    $this->handleVideoFrame($tag);
                } elseif ($tag->tagType === 8) {
                    $this->handleAudioFrame($tag);
                }
            }

            $frameCount++;

            if ($frameCount % 30 == 0) {
                echo "Processed {$frameCount} frames\n";
            }
        }

        $this->closeAllSegments();
        $this->generateMasterPlaylist();

        echo "Done! Processed {$frameCount} frames\n";
    }

    private function handleVideoFrame($tag): void
    {
        $body = null;
        if (property_exists($tag, 'body')) {
            $body = $tag->body;
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

        $timestamp = 0;
        if (method_exists($tag, 'getTime')) {
            $timestamp = $tag->getTime();
        }

        if ($this->baseTimestamp === null) {
            if (!$isKeyFrame) return;
            $this->baseTimestamp = $timestamp;
            foreach ($this->profiles as $name => $profile) {
                $this->segmentStartTimes[$name] = 0;
                $this->startSegment($name);
            }
        }

        $relativeTime = $timestamp - $this->baseTimestamp;

        foreach ($this->profiles as $name => $profile) {
            if ($isKeyFrame && ($relativeTime - $this->segmentStartTimes[$name]) >= ($this->segmentDuration * 1000)) {
                $this->closeSegment($name, $relativeTime);
                $this->audioFrameCounts[$name] = 0;
                $this->audioBasePts[$name] = (int)($relativeTime * 90);
                $this->lastDts[$name] = -1;
                $this->segmentStartTimes[$name] = $relativeTime;
                $this->startSegment($name);
            }
            $this->currentSegmentLastTimes[$name] = $relativeTime;
            $writer = &$this->segmentWriters[$name];
            $writer['endTime'] = $relativeTime;
        }

        $cts = $avc['compositionTime'] ?? 0;
        if ($cts & 0x800000) {
            $cts -= 0x1000000;
        }

        $avcData = $avc['data'];

        foreach ($this->profiles as $name => $profile) {
            $writer = &$this->segmentWriters[$name];
            if (!is_resource($writer['handle'])) continue;

            $dts = (int)($relativeTime * 90);
            $pts = (int)(($relativeTime + $cts) * 90);
            if ($pts < $dts) {
                $pts = $dts;
            }

            $outputData = $avcData;
            $outputSpsPps = $this->spsPpsData[$name];

            if ($this->srcInitialized && ($profile['width'] != $this->srcWidth || $profile['height'] != $this->srcHeight)) {
                static $debugCount = 0;
                $debugCount++;
                
                if ($debugCount <= 5) {
                    echo "Re-encoding triggered: src={$this->srcWidth}x{$this->srcHeight}, target={$profile['width']}x{$profile['height']}, isKeyFrame=$isKeyFrame\n";
                }
                
                if ($isKeyFrame) {
                    $nalUnits = $this->extractNalUnitsFromAVCC($avcData);
                    if (!empty($nalUnits)) {
                        $decoded = $this->decoder->decode($nalUnits);
                        if ($decoded && isset($decoded['data'])) {
                            $this->lastDecodedFrame = $decoded['data'];
                            if ($debugCount <= 5) {
                                echo "Decoded frame: " . strlen($decoded['data']) . " bytes\n";
                            }
                        } else {
                            if ($debugCount <= 5) {
                                echo "Decode failed\n";
                            }
                        }
                    } else {
                        if ($debugCount <= 5) {
                            echo "No NAL units extracted\n";
                        }
                    }
                }
                
                if (isset($this->lastDecodedFrame)) {
                    $scaledYuv = $this->scaler->scaleYUV420P(
                        $this->lastDecodedFrame,
                        $this->srcWidth,
                        $this->srcHeight,
                        $profile['width'],
                        $profile['height']
                    );

                    $this->encoder->setResolution($profile['width'], $profile['height']);
                    $this->encoder->setBitrate($profile['bitrate']);
                    $this->encoder->setFps($profile['fps']);

                    $encoded = $this->encoder->encodeFrame($scaledYuv, $isKeyFrame);

                    $outputData = '';
                    $outputSpsPps = '';
                    foreach ($encoded as $nal) {
                        $nalType = ord($nal[0]) & 0x1F;
                        if ($nalType === 7 || $nalType === 8) {
                            $outputSpsPps .= "\x00\x00\x00\x01" . $this->escapeNAL($nal);
                        } else {
                            $nalSize = strlen($nal);
                            $outputData .= pack('N', $nalSize) . $nal;
                        }
                    }
                    
                    if ($debugCount <= 5) {
                        echo "Encoded: " . count($encoded) . " NAL units, SPS/PPS=" . strlen($outputSpsPps) . " bytes, data=" . strlen($outputData) . " bytes\n";
                        foreach ($encoded as $nal) {
                            $nalType = ord($nal[0]) & 0x1F;
                            $types = [7 => 'SPS', 8 => 'PPS', 1 => 'SLICE', 2 => 'SLICE'];
                            echo "  NAL type: " . ($types[$nalType] ?? $nalType) . ", length: " . strlen($nal) . ", hex: " . bin2hex(substr($nal, 0, 20)) . "\n";
                        }
                    }
                } else {
                    if ($debugCount <= 5) {
                        echo "No decoded frame available\n";
                    }
                }
            }

            $annexb = $this->avccToAnnexB($outputData);

            if ($isKeyFrame && $outputSpsPps !== '') {
                $annexb = $outputSpsPps . $annexb;
            }

            $pes = $this->createPES(0xE0, $annexb, $pts, ($pts != $dts) ? $dts : null);
            $this->writeTSPackets($name, $this->videoPid, $pes, true, $dts);
        }
    }

    private function handleAudioFrame($tag): void
    {
        $raw = null;
        if (property_exists($tag, 'body')) {
            $raw = $tag->body;
        }
        if ($raw === null || strlen($raw) < 2) return;

        $soundFormat = (ord($raw[0]) >> 4) & 0x0F;
        if ($soundFormat != 10) return;

        $aacPacketType = ord($raw[1]);

        if ($aacPacketType == 0) {
            $asc = substr($raw, 2);
            if (strlen($asc) >= 2) {
                $this->audioSpecificConfig = $asc;
                $this->parseAudioSpecificConfig($asc);
            }
            return;
        }

        if ($aacPacketType != 1) return;
        if ($this->baseTimestamp === null || $this->audioSpecificConfig === '') return;

        $aacRaw = substr($raw, 2);
        if ($aacRaw === '') return;

        if (strlen($aacRaw) >= 2) {
            $firstByte = ord($aacRaw[0]);
            $secondByte = ord($aacRaw[1]);
            if ($firstByte == 0xFF && ($secondByte & 0xF0) == 0xF0) {
                $crcPresent = (ord($aacRaw[1]) & 0x01) == 0;
                $adtsLen = $crcPresent ? 9 : 7;
                if (strlen($aacRaw) > $adtsLen) {
                    $aacRaw = substr($aacRaw, $adtsLen);
                }
            }
        }

        $adts = $this->createADTSHeader(strlen($aacRaw));
        $payload = $adts . $aacRaw;

        $timestamp = 0;
        if (method_exists($tag, 'getTime')) {
            $timestamp = $tag->getTime();
        }

        $relativeTime = $timestamp - $this->baseTimestamp;

        $sampleRates = [96000, 88200, 64000, 48000, 44100, 32000, 24000, 22050, 16000, 12000, 11025, 8000, 7350];
        $sampleRate = $sampleRates[$this->samplingFrequencyIndex] ?? 44100;
        $frameDuration = (int)((1024 * 90000) / $sampleRate);

        foreach ($this->profiles as $name => $profile) {
            $writer = &$this->segmentWriters[$name];
            if (!is_resource($writer['handle'])) continue;

            if ($this->audioBasePts[$name] === null) {
                $this->audioBasePts[$name] = (int)($relativeTime * 90);
            }
            $pts = $this->audioBasePts[$name] + ($this->audioFrameCounts[$name] * $frameDuration);
            $this->audioFrameCounts[$name]++;

            $pes = $this->createPES(0xC0, $payload, $pts, null);
            $this->writeTSPackets($name, $this->audioPid, $pes, false, 0);
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
            $result .= "\x00\x00\x00\x01";
            $spsData = substr($data, $offset, $len);
            $result .= $spsData;
            $offset += $len;

            if (!$this->srcInitialized) {
                $this->decoder->decode([['type' => 7, 'data' => $spsData]]);
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
            $result .= "\x00\x00\x00\x01";
            $ppsData = substr($data, $offset, $len);
            $result .= $ppsData;
            $offset += $len;

            if (!$this->srcInitialized) {
                $this->decoder->decode([['type' => 8, 'data' => $ppsData]]);
            }
        }

        foreach ($this->profiles as $name => $profile) {
            $this->spsPpsData[$name] = $result;
        }
    }

    private function escapeNAL(string $nalData): string
    {
        if (strlen($nalData) <= 1) return $nalData;
        
        $escaped = $nalData[0];
        
        $rbsp = substr($nalData, 1);
        $len = strlen($rbsp);
        $i = 0;
        while ($i < $len) {
            if ($i + 2 < $len && $rbsp[$i] === "\x00" && $rbsp[$i + 1] === "\x00" &&
                ($rbsp[$i + 2] === "\x00" || $rbsp[$i + 2] === "\x01" || $rbsp[$i + 2] === "\x02" || $rbsp[$i + 2] === "\x03")
            ) {
                $escaped .= "\x00\x00\x03" . $rbsp[$i + 2];
                $i += 3;
            } else {
                $escaped .= $rbsp[$i];
                $i++;
            }
        }
        return $escaped;
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
            $nalData = substr($data, $offset, $nalSize);
            $offset += $nalSize;
            $result .= "\x00\x00\x00\x01";
            $result .= $this->escapeNAL($nalData);
        }

        return $result;
    }

    private function extractNalUnitsFromAVCC(string $data): array
    {
        $nalUnits = [];
        $offset = 0;
        $len = strlen($data);

        while ($offset + 4 <= $len) {
            $nalSize = unpack('N', substr($data, $offset, 4))[1];
            $offset += 4;

            if ($offset + $nalSize > $len) {
                break;
            }

            $nalData = substr($data, $offset, $nalSize);
            $offset += $nalSize;

            if (strlen($nalData) > 0) {
                $nalType = ord($nalData[0]) & 0x1F;
                $nalUnits[] = [
                    'type' => $nalType,
                    'data' => $nalData,
                ];
            }
        }

        return $nalUnits;
    }

    private function parseAudioSpecificConfig(string $asc): void
    {
        if (strlen($asc) < 2) return;

        $bits = '';
        for ($i = 0; $i < strlen($asc); $i++) {
            $byte = ord($asc[$i]);
            for ($j = 7; $j >= 0; $j--) {
                $bits .= (($byte >> $j) & 0x01) ? '1' : '0';
            }
        }

        $pos = 0;
        $totalBits = strlen($bits);

        $audioObjectType = (int)bindec(substr($bits, $pos, 5));
        $pos += 5;

        $this->sbrPresent = false;
        $this->extensionSamplingIndex = null;

        if ($audioObjectType == 5 || $audioObjectType == 29) {
            $this->sbrPresent = true;

            if ($pos + 4 > $totalBits) return;
            $this->extensionSamplingIndex = (int)bindec(substr($bits, $pos, 4));
            $pos += 4;

            if ($this->extensionSamplingIndex == 0x0F) {
                $pos += 24;
                if ($pos > $totalBits) return;
            }

            if ($pos + 5 > $totalBits) return;
            $this->audioObjectType = (int)bindec(substr($bits, $pos, 5));
            $pos += 5;
        } else {
            $this->audioObjectType = $audioObjectType;
        }

        if ($pos + 4 > $totalBits) return;
        $this->samplingFrequencyIndex = (int)bindec(substr($bits, $pos, 4));
        $pos += 4;

        if ($this->samplingFrequencyIndex == 0x0F) {
            $pos += 24;
            if ($pos > $totalBits) return;
        }

        if ($pos + 4 > $totalBits) return;
        $this->channelConfiguration = (int)bindec(substr($bits, $pos, 4));
    }

    private function createADTSHeader(int $aacLength): string
    {
        $profile = 1;
        $freqIndex = $this->samplingFrequencyIndex;
        if ($freqIndex < 0 || $freqIndex > 11) {
            $freqIndex = 4;
        }

        $channelConfig = $this->channelConfiguration;
        if ($channelConfig < 0 || $channelConfig > 7) {
            $channelConfig = 2;
        }

        $frameLength = $aacLength + 7;

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

    private function writePAT(string $profile): void
    {
        $section = "\x00\xB0\x0D"
            . "\x00\x01"
            . "\xC1\x00\x00"
            . "\x00\x01"
            . pack('n', 0xE000 | $this->pmtPid);

        $section .= pack('N', $this->crc32mpeg($section));

        $payload = "\x00" . $section;
        $this->writeTSPacketsRaw($profile, 0x0000, $payload);
    }

    private function writePMT(string $profile): void
    {
        $body = "\x00\x01"
            . "\xC1\x00\x00"
            . pack('n', 0xE000 | $this->videoPid)
            . "\xF0\x00";

        $body .= "\x1B"
            . pack('n', 0xE000 | $this->videoPid)
            . "\xF0\x00";

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
        $this->writeTSPacketsRaw($profile, $this->pmtPid, $payload);
    }

    private function writeTSPacketsRaw(string $profile, int $pid, string $payload): void
    {
        $cc = &$this->continuityCounters[$profile][$pid];
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

            fwrite($this->segmentWriters[$profile]['handle'], $packet);
            $offset += $chunkSize;
            $first = false;
        }
    }

    private function writeTSPackets(string $profile, int $pid, string $payload, bool $writePCR = false, int $pcr = 0): void
    {
        $cc = &$this->continuityCounters[$profile][$pid];
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
                        $adaptationField = chr($stuffing - 1) . chr(0x00);
                        $adaptationField .= str_repeat("\xFF", $stuffing - 2);
                    } else {
                        $adaptationField = chr(0);
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

            fwrite($this->segmentWriters[$profile]['handle'], $packet);
            $offset += $payloadSpace;
            $first = false;
        }
    }

    private function startSegment(string $profile): void
    {
        $writer = &$this->segmentWriters[$profile];
        $writer['sequence']++;
        $this->continuityCounters[$profile] = [];

        $file = "{$this->outputDir}/{$profile}/segment_{$writer['sequence']}.ts";
        $writer['handle'] = fopen($file, 'wb');

        $this->writePAT($profile);
        $this->writePMT($profile);

        $this->audioFrameCounts[$profile] = 0;
    }

    private function closeSegment(string $profile, int $endTime = 0): void
    {
        $writer = &$this->segmentWriters[$profile];

        if (is_resource($writer['handle'])) {
            fflush($writer['handle']);
            fclose($writer['handle']);
            $writer['handle'] = null;

            $end = $endTime;
            if ($end <= 0) {
                $end = $this->currentSegmentLastTimes[$profile];
            }
            $actualDuration = ($end - $this->segmentStartTimes[$profile]) / 1000.0;
            $actualDuration = max(0.001, round($actualDuration, 3));
            $this->segmentDurations[$profile][$writer['sequence']] = $actualDuration;

            $this->updatePlaylist($profile);
        }
    }

    private function closeAllSegments(): void
    {
        foreach ($this->profiles as $name => $profile) {
            $writer = $this->segmentWriters[$name];
            if ($writer['handle']) {
                $this->closeSegment($name, $writer['endTime']);
            }

            $this->addEndList($name);
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

    private function ensureInitialPlaylist(string $profile): void
    {
        $m3u8Path = "{$this->outputDir}/{$profile}/index.m3u8";
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

    private function updatePlaylist(string $profile): void
    {
        $lines = [];
        $lines[] = "#EXTM3U";
        $lines[] = "#EXT-X-VERSION:3";

        $maxDuration = $this->segmentDuration;
        foreach ($this->segmentDurations[$profile] as $duration) {
            $maxDuration = max($maxDuration, ceil($duration));
        }
        $lines[] = "#EXT-X-TARGETDURATION:" . (int)$maxDuration;
        $lines[] = "#EXT-X-MEDIA-SEQUENCE:1";
        $lines[] = '#EXT-X-INDEPENDENT-SEGMENTS';

        $writer = $this->segmentWriters[$profile];
        for ($i = 1; $i <= $writer['sequence']; $i++) {
            $duration = $this->segmentDurations[$profile][$i] ?? $this->segmentDuration;
            $lines[] = "#EXTINF:" . number_format($duration, 3, '.', '') . ",";
            $lines[] = "segment_{$i}.ts";
        }

        $m3u8Content = implode("\n", $lines) . "\n";

        $m3u8Path = "{$this->outputDir}/{$profile}/index.m3u8";
        $tmpPath = $m3u8Path . '.tmp';
        file_put_contents($tmpPath, $m3u8Content);
        rename($tmpPath, $m3u8Path);
    }

    private function addEndList(string $profile): void
    {
        $m3u8Path = "{$this->outputDir}/{$profile}/index.m3u8";
        if (file_exists($m3u8Path)) {
            $m3u8 = rtrim(file_get_contents($m3u8Path)) . "\n";
            if (strpos($m3u8, '#EXT-X-ENDLIST') === false) {
                $m3u8 .= "#EXT-X-ENDLIST\n";
            }
            file_put_contents($m3u8Path, $m3u8);
        }
    }

    private function generateMasterPlaylist(): void
    {
        $lines = [];
        $lines[] = "#EXTM3U";
        $lines[] = "#EXT-X-VERSION:6";

        $sortedProfiles = $this->profiles;
        uasort($sortedProfiles, function($a, $b) {
            return $b['bitrate'] <=> $a['bitrate'];
        });

        foreach ($sortedProfiles as $name => $profile) {
            $bandwidth = $profile['bitrate'] + ($profile['audioBitrate'] ?? 128000);
            $resolution = "{$profile['width']}x{$profile['height']}";

            $lines[] = sprintf(
                '#EXT-X-STREAM-INF:BANDWIDTH=%d,RESOLUTION=%s,CODECS="avc1.64001f,mp4a.40.2"',
                $bandwidth,
                $resolution
            );
            $lines[] = "{$name}/index.m3u8";
        }

        $masterPath = $this->outputDir . '/master.m3u8';
        file_put_contents($masterPath, implode("\n", $lines) . "\n");
    }

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