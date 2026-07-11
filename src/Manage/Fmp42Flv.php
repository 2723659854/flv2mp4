<?php

namespace Xiaosongshu\Flv2mp4\Manage;

/**
 * @purpose 将fmp4转码合并为flv文件核心文件
 * @author yanglong
 * @note 仅兼容标准h264,标准aac,其他格式兼容太复杂了，暂时不处理了
 */
class Fmp42Flv
{
    public $_config;
    public $onFlvData = null;
    public $onMediaInfo = null;
    public $onError = null;
    public $onDebug = null;

    public $hasVideo = false;
    public $hasAudio = false;

    public $flvHeaderWritten = false;
    public $videoHeaderWritten = false;
    public $audioHeaderWritten = false;

    public $sps = '';
    public $pps = '';
    public $audioSpecificConfig = '';
    public $audioSampleRate = 44100;
    public $audioChannels = 2;
    public $audioObjectType = 2;
    public $audioFreqIndex = 4;

    public $videoWidth = 0;
    public $videoHeight = 0;

    public $videoTrackId = 0;
    public $audioTrackId = 0;
    public $videoTimescale = 90000;
    public $audioTimescale = 90000;

    public $_pendingSamples = [];

    // 音频采样率映射表
    private $sampleRateTable = [
        0 => 96000,
        1 => 88200,
        2 => 64000,
        3 => 48000,
        4 => 44100,
        5 => 32000,
        6 => 24000,
        7 => 22050,
        8 => 16000,
        9 => 12000,
        10 => 11025,
        11 => 8000,
        12 => 7350,
        13 => 0,
        14 => 0,
        15 => 0
    ];

    public function __construct($config = [])
    {
        $this->_config = array_merge(['_isLive' => false], $config);
        $this->reset();
    }

    public function reset()
    {
        $this->hasVideo = false;
        $this->hasAudio = false;
        $this->flvHeaderWritten = false;
        $this->videoHeaderWritten = false;
        $this->audioHeaderWritten = false;
        $this->sps = '';
        $this->pps = '';
        $this->audioSpecificConfig = '';
        $this->audioSampleRate = 44100;
        $this->audioChannels = 2;
        $this->audioObjectType = 2;
        $this->audioFreqIndex = 4;
        $this->videoWidth = 0;
        $this->videoHeight = 0;
        $this->videoTrackId = 0;
        $this->audioTrackId = 0;
        $this->videoTimescale = 90000;
        $this->audioTimescale = 90000;
        $this->_pendingSamples = [];
    }

    /** 是否开启调试模式 */
    public bool $debug = false;

    private function debugLog($message)
    {
        if ($this->debug) {
            if ($this->onDebug) {
                call_user_func($this->onDebug, $message);
            } else {
                error_log("[Fmp42Flv] " . $message);
            }
        }
    }

    public function setInitSegment($data)
    {
        $this->parseInitSegment($data);
    }

    public function flushInit()
    {
        if (!$this->flvHeaderWritten) {
            if ($this->hasAudio && empty($this->audioSpecificConfig)) {
                $this->debugLog("WARNING: hasAudio=true but audioSpecificConfig is empty!");
            }
            $this->writeFlvHeader();
            $this->flushPendingSamples();
        }
    }

    public function setMediaSegment($data)
    {
        $samples = $this->parseMediaSegment($data);
        foreach ($samples as $sample) {
            if ($this->flvHeaderWritten) {
                $this->writeSample($sample);
            } else {
                $this->_pendingSamples[] = $sample;
            }
        }
    }

    public function collectMediaSegmentSamples($data)
    {
        return $this->parseMediaSegment($data);
    }

    private function parseInitSegment($data)
    {
        $boxes = $this->parseBoxes($data, 0, strlen($data));
        $moov = $this->findBox($boxes, 'moov');
        if (!$moov) {
            if ($this->onError) {
                call_user_func($this->onError, new \Exception('未找到moov盒子'));
            }
            return;
        }

        $traks = $this->findAllBoxes([$moov], 'trak');
        foreach ($traks as $trak) {
            $this->parseTrack($trak);
        }

        if ($this->onMediaInfo) {
            call_user_func($this->onMediaInfo, [
                'width' => $this->videoWidth,
                'height' => $this->videoHeight,
                'hasAudio' => $this->hasAudio,
                'hasVideo' => $this->hasVideo,
                'audioSampleRate' => $this->audioSampleRate,
                'audioChannels' => $this->audioChannels,
                'audioObjectType' => $this->audioObjectType
            ]);
        }
    }

    private function parseTrack($trak)
    {
        $tkhd = $this->findBox([$trak], 'tkhd');
        if (!$tkhd) return;
        $trackId = unpack('N', substr($tkhd['data'], 12, 4))[1];

        $mdia = $this->findBox([$trak], 'mdia');
        if (!$mdia) return;
        $hdlr = $this->findBox([$mdia], 'hdlr');
        if (!$hdlr) return;
        $handlerType = substr($hdlr['data'], 8, 4);

        $this->debugLog("parseTrack: trackId=$trackId, handlerType=$handlerType");

        if ($handlerType === 'vide') {
            $dataLen = strlen($tkhd['data']);
            if ($dataLen >= 8) {
                $widthData = substr($tkhd['data'], $dataLen - 8, 4);
                $heightData = substr($tkhd['data'], $dataLen - 4, 4);
                $widthRaw = unpack('N', $widthData)[1];
                $heightRaw = unpack('N', $heightData)[1];
                $this->videoWidth = (int)($widthRaw / 65536);
                $this->videoHeight = (int)($heightRaw / 65536);
            }
        }

        $mdhd = $this->findBox([$mdia], 'mdhd');
        $timescale = 90000;
        if ($mdhd) {
            $version = ord($mdhd['data'][0]);
            $timescaleOffset = $version == 0 ? 12 : 20;
            $timescale = unpack('N', substr($mdhd['data'], $timescaleOffset, 4))[1];
        }

        $minf = $this->findBox([$mdia], 'minf');
        if (!$minf) return;
        $stbl = $this->findBox([$minf], 'stbl');
        if (!$stbl) return;
        $stsd = $this->findBox([$stbl], 'stsd');
        if (!$stsd) return;

        $stsdData = $stsd['data'];
        $pos = 8;
        while ($pos + 8 <= strlen($stsdData)) {
            $entrySize = unpack('N', substr($stsdData, $pos, 4))[1];
            $entryType = substr($stsdData, $pos + 4, 4);

            if ($handlerType === 'vide' && $entryType === 'avc1') {
                $this->hasVideo = true;
                $this->videoTrackId = $trackId;
                $this->videoTimescale = $timescale;
                $this->debugLog("Video track found: trackId=$trackId, timescale=$timescale");
                $this->parseAvcCFromStsdEntry(substr($stsdData, $pos, $entrySize));
                break;
            } elseif ($handlerType === 'soun' && $entryType === 'mp4a') {
                $this->hasAudio = true;
                $this->audioTrackId = $trackId;
                $this->audioTimescale = $timescale;
                $this->debugLog("Audio track found: trackId=$trackId, timescale=$timescale");
                $this->parseEsdsFromStsdEntry(substr($stsdData, $pos, $entrySize));
                break;
            }
            $pos += $entrySize;
        }
    }

    private function parseAvcCFromStsdEntry($data)
    {
        $pos = strpos($data, 'avcC');
        if ($pos === false || $pos < 4) return;
        $boxSize = unpack('N', substr($data, $pos - 4, 4))[1];
        $avcCData = substr($data, $pos + 4, $boxSize - 8);
        $this->parseAvcC($avcCData);
    }

    private function parseAvcC($data)
    {
        if (strlen($data) < 8) return;

        $numSps = ord($data[5]) & 0x1F;
        $offset = 6;
        for ($i = 0; $i < $numSps; $i++) {
            if ($offset + 2 > strlen($data)) break;
            $spsLength = unpack('n', substr($data, $offset, 2))[1];
            $offset += 2;
            if ($offset + $spsLength > strlen($data)) break;
            $this->sps = substr($data, $offset, $spsLength);
            $offset += $spsLength;
            $this->parseSpsForDimensions($this->sps);
            break;
        }

        $numPps = ord($data[$offset]);
        $offset++;
        for ($i = 0; $i < $numPps; $i++) {
            if ($offset + 2 > strlen($data)) break;
            $ppsLength = unpack('n', substr($data, $offset, 2))[1];
            $offset += 2;
            if ($offset + $ppsLength > strlen($data)) break;
            $this->pps = substr($data, $offset, $ppsLength);
            break;
        }
    }

    private function parseSpsForDimensions($sps)
    {
        if ($this->videoWidth > 0 && $this->videoHeight > 0) return;
        if (strlen($sps) < 10) return;
        $pos = 0;
        if (ord($sps[0]) & 0x80) $pos++;
        $pos += 3;
        $pos++;
        $pos = $this->skipUEG($sps, $pos);
        $picOrderCntType = $this->readUEG($sps, $pos);
        $pos = $this->skipUEG($sps, $pos);
        if ($picOrderCntType == 0) {
            $pos = $this->skipUEG($sps, $pos);
            $pos = $this->skipUEG($sps, $pos);
        } elseif ($picOrderCntType == 1) {
            $pos = $this->skipUEG($sps, $pos);
            $pos = $this->skipUEG($sps, $pos);
            $pos = $this->skipUEG($sps, $pos);
            $pos = $this->skipUEG($sps, $pos);
            $numRefFramesInPicOrderCntCycle = $this->readUEG($sps, $pos);
            $pos = $this->skipUEG($sps, $pos);
            for ($i = 0; $i < $numRefFramesInPicOrderCntCycle; $i++) {
                $pos = $this->skipSEG($sps, $pos);
            }
        }
        $pos = $this->skipUEG($sps, $pos);
        $pos++;
        $picWidthInMbsMinus1 = $this->readUEG($sps, $pos);
        $pos = $this->skipUEG($sps, $pos);
        $picHeightInMapUnitsMinus1 = $this->readUEG($sps, $pos);
        $this->videoWidth = ($picWidthInMbsMinus1 + 1) * 16;
        $this->videoHeight = ($picHeightInMapUnitsMinus1 + 1) * 16;
    }

    private function readUEG($data, &$pos)
    {
        $leadingZeroBits = 0;
        while ($pos < strlen($data) && (ord($data[$pos]) & 0x80) == 0) {
            $leadingZeroBits++;
            $pos++;
        }
        if ($pos >= strlen($data)) return 0;
        $result = ord($data[$pos]) & 0x7F;
        $pos++;
        for ($i = 0; $i < $leadingZeroBits; $i++) {
            if ($pos >= strlen($data)) break;
            $result = ($result << 7) | (ord($data[$pos]) & 0x7F);
            $pos++;
        }
        return $result - 1;
    }

    private function skipUEG($data, $pos)
    {
        while ($pos < strlen($data) && (ord($data[$pos]) & 0x80) == 0) $pos++;
        if ($pos >= strlen($data)) return $pos;
        $pos++;
        return $pos;
    }

    private function skipSEG($data, $pos)
    {
        return $this->skipUEG($data, $pos);
    }

    private function parseEsdsFromStsdEntry($data)
    {
        $pos = strpos($data, 'esds');
        if ($pos === false || $pos < 4) {
            $this->debugLog("esds box not found in stsd entry");
            return;
        }

        $boxSize = unpack('N', substr($data, $pos - 4, 4))[1];
        $esdsBoxData = substr($data, $pos - 4, $boxSize);

        $this->debugLog(sprintf(
            "ESDS box found: size=%d, hex=%s",
            $boxSize,
            bin2hex(substr($esdsBoxData, 0, min(64, strlen($esdsBoxData))))
        ));

        // ESDS FullBox 结构:
        // size: 4 bytes
        // type 'esds': 4 bytes
        // version: 1 byte
        // flags: 3 bytes
        // 之后是 ES_Descriptor (tag 0x03)
        // 所以跳过 12 字节

        $descriptors = substr($esdsBoxData, 12);
        $this->parseMP4Descriptors($descriptors);
    }

    /**
     * 解析 MP4 描述符序列
     */
    private function parseMP4Descriptors($data)
    {
        $len = strlen($data);
        $this->debugLog(sprintf(
            "parseMP4Descriptors: dataLen=%d, hex=%s",
            $len,
            bin2hex(substr($data, 0, min(32, $len)))
        ));

        $pos = 0;

        while ($pos + 2 <= $len) {
            $tag = ord($data[$pos]);
            $pos++;

            // 读取可变长度
            $length = 0;
            $lengthStart = $pos;
            while ($pos < $len) {
                $byte = ord($data[$pos]);
                $pos++;
                $length = ($length << 7) | ($byte & 0x7F);
                if (($byte & 0x80) == 0) break;
            }

            $this->debugLog(sprintf(
                "Descriptor: tag=0x%02X, length=%d, pos=%d, remaining=%d",
                $tag, $length, $pos, $len - $pos
            ));

            if ($pos + $length > $len) {
                $this->debugLog(sprintf(
                    "Descriptor tag=0x%02X length=%d exceeds boundary, pos=%d, total=%d",
                    $tag, $length, $pos, $len
                ));
                break;
            }

            $payload = substr($data, $pos, $length);

            switch ($tag) {
                case 0x03: // ES_Descriptor
                    $this->parseESDescriptorPayload($payload);
                    break;

                case 0x04: // DecoderConfigDescriptor
                    $this->parseDecoderConfigDescriptorPayload($payload);
                    break;

                case 0x05: // DecoderSpecificInfo
                    $this->audioSpecificConfig = $payload;
                    $this->parseAudioSpecificConfig($this->audioSpecificConfig);
                    $this->debugLog("AudioSpecificConfig found: " . bin2hex($this->audioSpecificConfig));
                    return;

                case 0x06: // SLConfigDescriptor
                    // 通常不需要解析，直接跳过
                    $this->debugLog("SLConfigDescriptor (skipped)");
                    break;

                default:
                    $this->debugLog(sprintf("Unknown descriptor tag: 0x%02X", $tag));
                    break;
            }

            $pos += $length;

            // 如果已经找到 AudioSpecificConfig，退出
            if (!empty($this->audioSpecificConfig)) {
                return;
            }
        }
    }

    /**
     * 解析 ES_Descriptor payload（不包含 tag 和 length）
     */
    private function parseESDescriptorPayload($data)
    {
        $len = strlen($data);
        $this->debugLog(sprintf(
            "ES_Descriptor payload: len=%d, hex=%s",
            $len,
            bin2hex(substr($data, 0, min(16, $len)))
        ));

        if ($len < 3) return;

        $pos = 0;

        // ES_ID: 2 bytes
        $esId = unpack('n', substr($data, $pos, 2))[1];
        $pos += 2;

        // flags: 1 byte
        $flags = ord($data[$pos]);
        $pos += 1;

        $streamDependenceFlag = ($flags & 0x80) >> 7;
        $urlFlag = ($flags & 0x40) >> 6;
        $ocrStreamFlag = ($flags & 0x20) >> 5;

        $this->debugLog(sprintf(
            "ES_Descriptor: ES_ID=%d, flags=0x%02X (dep=%d, url=%d, ocr=%d)",
            $esId, $flags, $streamDependenceFlag, $urlFlag, $ocrStreamFlag
        ));

        // dependsOn_ES_ID (16 bits)
        if ($streamDependenceFlag) {
            if ($pos + 2 > $len) return;
            $dependsOnEsId = unpack('n', substr($data, $pos, 2))[1];
            $pos += 2;
            $this->debugLog("dependsOn_ES_ID: $dependsOnEsId");
        }

        // URL (variable length string)
        if ($urlFlag) {
            if ($pos >= $len) return;
            $urlLength = ord($data[$pos]);
            $pos += 1;
            if ($pos + $urlLength > $len) return;
            $url = substr($data, $pos, $urlLength);
            $pos += $urlLength;
            $this->debugLog("URL: $url");
        }

        // OCR_ES_ID (16 bits)
        if ($ocrStreamFlag) {
            if ($pos + 2 > $len) return;
            $ocrEsId = unpack('n', substr($data, $pos, 2))[1];
            $pos += 2;
            $this->debugLog("OCR_ES_ID: $ocrEsId");
        }

        // 剩余数据是嵌套的描述符
        if ($pos < $len) {
            $remaining = substr($data, $pos);
            $this->parseMP4Descriptors($remaining);
        }
    }

    /**
     * 解析 DecoderConfigDescriptor payload（不包含 tag 和 length）
     */
    private function parseDecoderConfigDescriptorPayload($data)
    {
        $len = strlen($data);
        $this->debugLog(sprintf(
            "DecoderConfigDescriptor payload: len=%d, hex=%s",
            $len,
            bin2hex(substr($data, 0, min(16, $len)))
        ));

        if ($len < 13) return;

        $pos = 0;

        // objectTypeIndication: 1 byte
        $objectTypeIndication = ord($data[$pos]);
        $pos += 1;

        // streamType (6 bits) + upStream (1 bit) + reserved (1 bit)
        $byteVal = ord($data[$pos]);
        $streamType = ($byteVal >> 2) & 0x3F;
        $upStream = ($byteVal >> 1) & 0x01;
        $pos += 1;

        // bufferSizeDB: 3 bytes
        $bufferSizeDB = (ord($data[$pos]) << 16) | (ord($data[$pos+1]) << 8) | ord($data[$pos+2]);
        $pos += 3;

        // maxBitrate: 4 bytes
        $maxBitrate = unpack('N', substr($data, $pos, 4))[1];
        $pos += 4;

        // avgBitrate: 4 bytes
        $avgBitrate = unpack('N', substr($data, $pos, 4))[1];
        $pos += 4;

        $this->debugLog(sprintf(
            "DecoderConfig: objectType=0x%02X, streamType=%d, bufferSize=%d, maxBitrate=%d, avgBitrate=%d",
            $objectTypeIndication, $streamType, $bufferSizeDB, $maxBitrate, $avgBitrate
        ));

        // 剩余数据是嵌套的描述符
        if ($pos < $len) {
            $remaining = substr($data, $pos);
            $this->parseMP4Descriptors($remaining);
        }
    }

    /**
     * 解析 AudioSpecificConfig
     */
    private function parseAudioSpecificConfig($config)
    {
        $len = strlen($config);
        if ($len < 2) {
            $this->debugLog("AudioSpecificConfig too short: $len bytes");
            return;
        }

        $bytes = unpack('n', substr($config, 0, 2))[1];

        $this->audioObjectType = ($bytes >> 11) & 0x1F;
        $freqIndex = ($bytes >> 7) & 0x0F;
        $channelConfig = ($bytes >> 3) & 0x0F;

        $this->audioFreqIndex = $freqIndex;

        $this->debugLog(sprintf(
            "AudioSpecificConfig: hex=%s, AOT=%d, freqIndex=%d, channelConfig=%d",
            bin2hex(substr($config, 0, min($len, 4))),
            $this->audioObjectType,
            $freqIndex,
            $channelConfig
        ));

        $baseRate = $this->getSampleRateFromIndex($freqIndex, $config);

        switch ($this->audioObjectType) {
            case 2:  // AAC-LC
                $this->audioSampleRate = $baseRate;
                $this->audioChannels = $channelConfig;
                break;

            case 5:  // HE-AAC (SBR)
                $this->audioSampleRate = $baseRate * 2;
                $this->audioChannels = $channelConfig;
                break;

            case 29: // HE-AACv2
                $this->audioSampleRate = $baseRate * 2;
                $this->audioChannels = 2;
                break;

            default:
                $this->audioSampleRate = $baseRate;
                $this->audioChannels = max(1, min($channelConfig, 2));
                break;
        }

        if ($this->audioChannels < 1 || $this->audioChannels > 2) {
            $this->debugLog("Invalid channels: {$this->audioChannels}, defaulting to 2");
            $this->audioChannels = 2;
        }

        if ($this->audioSampleRate < 8000 || $this->audioSampleRate > 192000) {
            $this->debugLog("Invalid sample rate: {$this->audioSampleRate}, defaulting to 44100");
            $this->audioSampleRate = 44100;
        }

        $this->debugLog(sprintf(
            "Final audio config: sampleRate=%d Hz, channels=%d, AOT=%d",
            $this->audioSampleRate,
            $this->audioChannels,
            $this->audioObjectType
        ));
    }

    private function getSampleRateFromIndex($freqIndex, $config)
    {
        if ($freqIndex >= 0 && $freqIndex <= 12) {
            return $this->sampleRateTable[$freqIndex];
        }

        if ($freqIndex == 15) {
            if (strlen($config) >= 5) {
                $extRate = (ord($config[2]) << 16) | (ord($config[3]) << 8) | ord($config[4]);
                return $extRate;
            }
            return 44100;
        }

        return 44100;
    }

    private function parseMediaSegment($data)
    {
        $samples = [];
        $offset = 0;
        $len = strlen($data);

        while ($offset + 8 <= $len) {
            $size = unpack('N', substr($data, $offset, 4))[1];
            $type = substr($data, $offset + 4, 4);

            if ($type === 'moof') {
                $moofData = substr($data, $offset + 8, $size - 8);
                $moofBoxes = $this->parseBoxes($moofData, 0, strlen($moofData));
                $moofSamples = $this->parseMoof($moofBoxes);

                $nextOffset = $offset + $size;
                if ($nextOffset + 8 <= $len) {
                    $nextSize = unpack('N', substr($data, $nextOffset, 4))[1];
                    $nextType = substr($data, $nextOffset + 4, 4);
                    if ($nextType === 'mdat') {
                        $mdatData = substr($data, $nextOffset + 8, $nextSize - 8);
                        foreach ($moofSamples as &$sample) {
                            if (isset($sample['mdatOffset']) && $sample['mdatOffset'] + $sample['size'] <= strlen($mdatData)) {
                                $sample['data'] = substr($mdatData, $sample['mdatOffset'], $sample['size']);
                            }
                        }
                        unset($sample);
                        $offset = $nextOffset + $nextSize;
                        $samples = array_merge($samples, $moofSamples);
                        continue;
                    }
                }
                $samples = array_merge($samples, $moofSamples);
            }

            $offset += $size;
        }

        return $samples;
    }

    private function parseMoof($boxes)
    {
        $samples = [];
        $tfhdData = null;
        $tfdtData = null;
        $trunData = null;
        $trackId = 0;

        foreach ($boxes as $box) {
            if ($box['type'] === 'traf') {
                foreach ($box['children'] as $child) {
                    if ($child['type'] === 'tfhd') {
                        $tfhdData = $child['data'];
                        $trackId = unpack('N', substr($tfhdData, 4, 4))[1];
                    } elseif ($child['type'] === 'tfdt') {
                        $tfdtData = $child['data'];
                    } elseif ($child['type'] === 'trun') {
                        $trunData = $child['data'];
                    }
                }
            }
        }

        if (!$trunData || !$tfdtData) return $samples;

        $baseMediaDecodeTime = 0;
        $version = ord($tfdtData[0]);
        if ($version == 0) {
            $baseMediaDecodeTime = unpack('N', substr($tfdtData, 4, 4))[1];
        } else {
            $baseMediaDecodeTime = unpack('J', substr($tfdtData, 4, 8))[1];
        }

        $trunVersion = ord($trunData[0]);
        $flags = (ord($trunData[1]) << 16) | (ord($trunData[2]) << 8) | ord($trunData[3]);
        $sampleCount = unpack('N', substr($trunData, 4, 4))[1];

        $offset = 8;

        $dataOffset = 0;
        if ($flags & 0x000001) {
            $dataOffset = unpack('N', substr($trunData, $offset, 4))[1];
            $offset += 4;
        }

        $currentMdatOffset = 0;

        $isVideo = ($trackId == $this->videoTrackId);
        $timescale = $isVideo ? $this->videoTimescale : $this->audioTimescale;

        $hasDuration = ($flags & 0x000100) != 0;
        $hasSize = ($flags & 0x000200) != 0;
        $hasFlags = ($flags & 0x000400) != 0;
        $hasCts = ($flags & 0x000800) != 0;

        for ($i = 0; $i < $sampleCount; $i++) {
            $duration = 0;
            if ($hasDuration) {
                $duration = unpack('N', substr($trunData, $offset, 4))[1];
                $offset += 4;
            }

            $size = 0;
            if ($hasSize) {
                $size = unpack('N', substr($trunData, $offset, 4))[1];
                $offset += 4;
            }

            $sampleFlags = 0;
            if ($hasFlags) {
                $sampleFlags = unpack('N', substr($trunData, $offset, 4))[1];
                $offset += 4;
            }

            $cts = 0;
            if ($hasCts) {
                $cts = unpack('N', substr($trunData, $offset, 4))[1];
                if ($cts & 0x80000000) {
                    $cts -= 0x100000000;
                }
                $offset += 4;
            }

            $isLeading = ($sampleFlags >> 24) & 0x03;
            $dependsOn = ($sampleFlags >> 22) & 0x03;
            $isNonSync = ($sampleFlags >> 1) & 0x01;
            $isKeyframe = ($dependsOn == 2) && ($isNonSync == 0);

            $dts = $baseMediaDecodeTime;
            $pts = $dts + $cts;

            $samples[] = [
                'type' => $isVideo ? 'video' : 'audio',
                'trackId' => $trackId,
                'dts' => $dts,
                'pts' => $pts,
                'cts' => $cts,
                'duration' => $duration,
                'size' => $size,
                'isKeyframe' => $isKeyframe,
                'mdatOffset' => $currentMdatOffset
            ];

            $currentMdatOffset += $size;
            $baseMediaDecodeTime += $duration;
        }

        return $samples;
    }

    private function parseBoxes($data, $offset, $end)
    {
        $boxes = [];
        while ($offset + 8 <= $end) {
            $size = unpack('N', substr($data, $offset, 4))[1];
            $type = substr($data, $offset + 4, 4);

            if ($size == 1) {
                if ($offset + 16 <= $end) {
                    $size = unpack('J', substr($data, $offset + 8, 8))[1];
                    $headerSize = 16;
                } else break;
            } elseif ($size == 0) {
                $size = $end - $offset;
                $headerSize = 8;
            } else {
                $headerSize = 8;
            }

            $boxEnd = $offset + $size;
            if ($boxEnd > $end) break;

            $boxData = substr($data, $offset + $headerSize, $size - $headerSize);
            $box = ['type' => $type, 'size' => $size, 'offset' => $offset, 'data' => $boxData, 'children' => []];

            if ($size > $headerSize) {
                $box['children'] = $this->parseBoxes($data, $offset + $headerSize, $boxEnd);
            }

            $boxes[] = $box;
            $offset = $boxEnd;
        }
        return $boxes;
    }

    private function findBox($boxes, $type)
    {
        foreach ($boxes as $box) {
            if ($box['type'] === $type) return $box;
            if (!empty($box['children'])) {
                $found = $this->findBox($box['children'], $type);
                if ($found !== null) return $found;
            }
        }
        return null;
    }

    private function findAllBoxes($boxes, $type)
    {
        $result = [];
        foreach ($boxes as $box) {
            if ($box['type'] === $type) $result[] = $box;
            if (!empty($box['children'])) {
                $result = array_merge($result, $this->findAllBoxes($box['children'], $type));
            }
        }
        return $result;
    }

    private function writeFlvHeader()
    {
        if ($this->flvHeaderWritten) return;

        $flags = 0;
        if ($this->hasVideo) $flags |= 0x01;
        if ($this->hasAudio) $flags |= 0x04;

        $header = "FLV\x01" . chr($flags) . "\x00\x00\x00\x09";
        $this->emitFlvData($header);

        $prevTagSize = "\x00\x00\x00\x00";
        $this->emitFlvData($prevTagSize);

        $this->flvHeaderWritten = true;

        $this->writeMetaData();
    }

    private function writeMetaData()
    {
        $metaData = [
            'duration' => 0,
            'width' => (float)($this->videoWidth ?: 0),
            'height' => (float)($this->videoHeight ?: 0),
            'videocodecid' => $this->hasVideo ? 'avc1' : '',
            'audiocodecid' => $this->hasAudio ? 'mp4a' : '',
            'audiosamplerate' => (float)($this->audioSampleRate ?: 44100),
            'audiochannels' => (float)($this->audioChannels ?: 2),
            'framerate' => 30.0,
            'hasAudio' => $this->hasAudio,
            'hasVideo' => $this->hasVideo
        ];

        $data = $this->serializeAmf0($metaData);
        $onMetaData = $this->serializeAmf0('onMetaData') . $data;
        $this->writeFlvTag(18, $onMetaData, 0);
    }

    private function serializeAmf0($value)
    {
        if (is_string($value)) {
            return "\x02" . pack('n', strlen($value)) . $value;
        } elseif (is_float($value) || is_numeric($value)) {
            return "\x00" . $this->packDoubleBE((float)$value);
        } elseif (is_bool($value)) {
            return $value ? "\x01\x01" : "\x01\x00";
        } elseif (is_array($value)) {
            $result = "\x03";
            foreach ($value as $key => $val) {
                if (!is_string($key)) continue;
                $result .= pack('n', strlen($key)) . $key;
                $result .= $this->serializeAmf0($val);
            }
            $result .= "\x00\x00\x09";
            return $result;
        } elseif ($value === null) {
            return "\x05";
        }
        return '';
    }

    private function packDoubleBE($value)
    {
        return strrev(pack('d', $value));
    }

    private function flushPendingSamples()
    {
        foreach ($this->_pendingSamples as $sample) {
            $this->writeSample($sample);
        }
        $this->_pendingSamples = [];
    }

    public function writeSample($sample)
    {
        if ($sample['type'] === 'video') {
            $this->writeVideoSample($sample);
        } else {
            $this->writeAudioSample($sample);
        }
    }

    private function writeVideoSample($sample)
    {
        if (!$this->videoHeaderWritten && !empty($this->sps)) {
            $this->writeAVCSequenceHeader();
        }

        $dtsMs = (int)round($sample['dts'] * 1000 / $this->videoTimescale);
        $ctsMs = (int)round($sample['cts'] * 1000 / $this->videoTimescale);

        $codecId = 7;
        $frameType = $sample['isKeyframe'] ? 1 : 2;

        $videoData = chr(($frameType << 4) | $codecId) . "\x01" .
            chr(($ctsMs >> 16) & 0xFF) . chr(($ctsMs >> 8) & 0xFF) . chr($ctsMs & 0xFF) .
            $sample['data'];

        $this->writeFlvTag(9, $videoData, $dtsMs);
    }

    private function writeAudioSample($sample)
    {
        if (!$this->audioHeaderWritten && !empty($this->audioSpecificConfig)) {
            $this->writeAACSequenceHeader();
        }

        $dtsMs = (int)round($sample['dts'] * 1000 / $this->audioTimescale);

        $soundFormat = 10; // AAC
        $soundRate = $this->getSoundRate();
        $soundSize = 1;    // 16-bit
        $soundType = ($this->audioChannels == 2) ? 1 : 0;
        $audioHeader = ($soundFormat << 4) | ($soundRate << 2) | ($soundSize << 1) | $soundType;

        $audioData = chr($audioHeader) . "\x01" . $sample['data'];

        $this->writeFlvTag(8, $audioData, $dtsMs);
    }

    private function writeAVCSequenceHeader()
    {
        $record = "\x01" .
            ($this->sps[1] ?? "\x42") .
            ($this->sps[2] ?? "\x00") .
            ($this->sps[3] ?? "\x1F") .
            "\xFF" .
            "\xE1" . pack('n', strlen($this->sps)) . $this->sps .
            "\x01" . pack('n', strlen($this->pps)) . $this->pps;

        $videoData = "\x17\x00\x00\x00\x00" . $record;
        $this->writeFlvTag(9, $videoData, 0);
        $this->videoHeaderWritten = true;
        $this->debugLog("AVC sequence header written");
    }

    private function writeAACSequenceHeader()
    {
        $soundFormat = 10; // AAC
        $soundRate = $this->getSoundRate();
        $soundSize = 1;    // 16-bit
        $soundType = ($this->audioChannels == 2) ? 1 : 0;
        $audioHeader = ($soundFormat << 4) | ($soundRate << 2) | ($soundSize << 1) | $soundType;

        $audioData = chr($audioHeader) . "\x00" . $this->audioSpecificConfig;

        $this->debugLog(sprintf(
            "AAC sequence header: sampleRate=%d, channels=%d, AOT=%d, config=%s",
            $this->audioSampleRate,
            $this->audioChannels,
            $this->audioObjectType,
            bin2hex($this->audioSpecificConfig)
        ));

        $this->writeFlvTag(8, $audioData, 0);
        $this->audioHeaderWritten = true;
    }

    private function getSoundRate()
    {
        $rateMap = [
            5512 => 0,
            11025 => 1,
            22050 => 2,
            44100 => 3,
            48000 => 3,
        ];

        if (isset($rateMap[$this->audioSampleRate])) {
            return $rateMap[$this->audioSampleRate];
        }

        $standardRates = [5512, 11025, 22050, 44100, 48000];
        $nearest = 44100;
        $minDiff = PHP_INT_MAX;

        foreach ($standardRates as $rate) {
            $diff = abs($this->audioSampleRate - $rate);
            if ($diff < $minDiff) {
                $minDiff = $diff;
                $nearest = $rate;
            }
        }

        return $rateMap[$nearest];
    }

    private function writeFlvTag($tagType, $data, $timestamp)
    {
        $dataSize = strlen($data);
        $timestamp &= 0xFFFFFFFF;
        $tsLow = $timestamp & 0xFFFFFF;
        $tsHigh = ($timestamp >> 24) & 0xFF;

        $tag = chr($tagType);
        $tag .= chr(($dataSize >> 16) & 0xFF) . chr(($dataSize >> 8) & 0xFF) . chr($dataSize & 0xFF);
        $tag .= chr(($tsLow >> 16) & 0xFF) . chr(($tsLow >> 8) & 0xFF) . chr($tsLow & 0xFF);
        $tag .= chr($tsHigh);
        $tag .= "\x00\x00\x00";

        $this->emitFlvData($tag);
        $this->emitFlvData($data);
        $this->emitFlvData(pack('N', 11 + $dataSize));
    }

    private function emitFlvData($data)
    {
        if ($this->onFlvData) {
            call_user_func($this->onFlvData, $data);
        }
    }

    public static function runFmp42Flv(string $m3u8File, string $outputFile, bool $debug = false)
    {
        if (!file_exists($m3u8File)) {
            throw new \RuntimeException("m3u8 file not exist: $m3u8File");
        }

        $m3u8Dir = dirname($m3u8File);
        $parsed = self::parseFmp4M3U8($m3u8File);

        $outputDir = dirname($outputFile);
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0777, true);
        }

        $fmp42flv = new Fmp42Flv();
        $flvData = '';
        $debugLogs = [];

        $fmp42flv->onFlvData = function ($data) use (&$flvData) {
            $flvData .= $data;
        };

        if ($debug) {
            $fmp42flv->onDebug = function ($msg) use (&$debugLogs) {
                $debugLogs[] = date('[Y-m-d H:i:s] ') . $msg;
                echo $msg . "\n";
            };
        }

        $fmp42flv->onMediaInfo = function ($info) use ($debug) {
            if ($debug) {
                echo "\n[媒体信息]\n";
                echo "  宽度: " . ($info['width'] ?? 'N/A') . "\n";
                echo "  高度: " . ($info['height'] ?? 'N/A') . "\n";
                echo "  音频: " . ($info['hasAudio'] ? '是' : '否') . "\n";
                echo "  视频: " . ($info['hasVideo'] ? '是' : '否') . "\n";
                echo "  音频采样率: " . ($info['audioSampleRate'] ?? 'N/A') . " Hz\n";
                echo "  音频声道: " . ($info['audioChannels'] ?? 'N/A') . "\n";
                echo "  音频类型: " . ($info['audioObjectType'] ?? 'N/A') . "\n";
            }
        };

        try {
            if (!empty($parsed['initFile'])) {
                $initPath = $m3u8Dir . DIRECTORY_SEPARATOR . $parsed['initFile'];
                if (file_exists($initPath)) {
                    $initData = file_get_contents($initPath);
                    $fmp42flv->setInitSegment($initData);
                }
            }

            if (!empty($parsed['audioInitFile']) && !$fmp42flv->hasAudio) {
                $audioInitPath = $m3u8Dir . DIRECTORY_SEPARATOR . $parsed['audioInitFile'];
                if (file_exists($audioInitPath)) {
                    $audioInitData = file_get_contents($audioInitPath);
                    $fmp42flv->setInitSegment($audioInitData);
                }
            }

            if (!empty($parsed['videoInitFile']) && !$fmp42flv->hasVideo) {
                $videoInitPath = $m3u8Dir . DIRECTORY_SEPARATOR . $parsed['videoInitFile'];
                if (file_exists($videoInitPath)) {
                    $videoInitData = file_get_contents($videoInitPath);
                    $fmp42flv->setInitSegment($videoInitData);
                }
            }

            $fmp42flv->flushInit();

            $allSamples = [];

            if (!empty($parsed['audioSegments'])) {
                foreach ($parsed['audioSegments'] as $seg) {
                    $segmentPath = $m3u8Dir . DIRECTORY_SEPARATOR . $seg;
                    if (!file_exists($segmentPath)) {
                        throw new \RuntimeException("segment file not exist: $segmentPath");
                    }
                    $segmentData = file_get_contents($segmentPath);
                    $samples = $fmp42flv->collectMediaSegmentSamples($segmentData);
                    $allSamples = array_merge($allSamples, $samples);
                }
            }

            if (!empty($parsed['videoSegments'])) {
                foreach ($parsed['videoSegments'] as $seg) {
                    $segmentPath = $m3u8Dir . DIRECTORY_SEPARATOR . $seg;
                    if (!file_exists($segmentPath)) {
                        throw new \RuntimeException("segment file not exist: $segmentPath");
                    }
                    $segmentData = file_get_contents($segmentPath);
                    $samples = $fmp42flv->collectMediaSegmentSamples($segmentData);
                    $allSamples = array_merge($allSamples, $samples);
                }
            }

            if (!empty($parsed['segmentFiles'])) {
                foreach ($parsed['segmentFiles'] as $seg) {
                    $segmentPath = $m3u8Dir . DIRECTORY_SEPARATOR . $seg;
                    if (!file_exists($segmentPath)) {
                        throw new \RuntimeException("segment file not exist: $segmentPath");
                    }
                    $segmentData = file_get_contents($segmentPath);
                    $samples = $fmp42flv->collectMediaSegmentSamples($segmentData);
                    $allSamples = array_merge($allSamples, $samples);
                }
            }

            usort($allSamples, function ($a, $b) {
                return $a['dts'] - $b['dts'];
            });

            $totalSamples = count($allSamples);
            if ($debug) {
                echo "\n[处理] 共收集 $totalSamples 个样本\n";
            }

            foreach ($allSamples as $index => $sample) {
                if ($debug && $index % 100 == 0) {
                    echo "  处理样本: $index/$totalSamples\n";
                }
                $fmp42flv->writeSample($sample);
            }

            if ($debug) {
                echo "\n[完成] 写入文件: $outputFile\n";
                echo "  视频: " . ($fmp42flv->hasVideo ? '是' : '否') . "\n";
                echo "  音频: " . ($fmp42flv->hasAudio ? '是' : '否') . "\n";
                echo "  音频采样率: {$fmp42flv->audioSampleRate} Hz\n";
                echo "  音频声道: {$fmp42flv->audioChannels}\n";
            }

            file_put_contents($outputFile, $flvData);
            return $outputFile;

        } catch (\Exception $e) {
            throw new \RuntimeException("转码失败: " . $e->getMessage());
        }
    }

    protected static function parseFmp4M3U8(string $m3u8File): array
    {
        $content = file_get_contents($m3u8File);
        $lines = explode("\n", $content);

        $m3u8Dir = dirname($m3u8File);

        $initFile = null;
        $segmentFiles = [];
        $audioInitFile = null;
        $audioSegments = [];
        $videoInitFile = null;
        $videoSegments = [];

        $audioM3u8File = null;
        $videoM3u8File = null;

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            if (strpos($line, '#EXT-X-MAP:') === 0) {
                preg_match('/URI="([^"]+)"/', $line, $matches);
                if (isset($matches[1])) {
                    $initFile = $matches[1];
                }
            } elseif (strpos($line, '#EXT-X-MEDIA:TYPE=AUDIO') === 0) {
                preg_match('/URI="([^"]+)"/', $line, $matches);
                if (isset($matches[1])) {
                    $audioM3u8File = $matches[1];
                }
            } elseif (strpos($line, '#EXT-X-STREAM-INF:') === 0) {
                continue;
            } elseif (strpos($line, '#') !== 0) {
                $ext = pathinfo($line, PATHINFO_EXTENSION);
                if ($ext === 'm4s') {
                    $segmentFiles[] = $line;
                } elseif ($ext === 'm3u8') {
                    $videoM3u8File = $line;
                }
            }
        }

        if ($audioM3u8File) {
            $audioM3u8Path = $m3u8Dir . DIRECTORY_SEPARATOR . $audioM3u8File;
            if (file_exists($audioM3u8Path)) {
                $audioParsed = self::parseFmp4M3U8($audioM3u8Path);
                $audioInitFile = $audioParsed['initFile'];
                $audioSegments = $audioParsed['segmentFiles'];
            }
        }

        if ($videoM3u8File) {
            $videoM3u8Path = $m3u8Dir . DIRECTORY_SEPARATOR . $videoM3u8File;
            if (file_exists($videoM3u8Path)) {
                $videoParsed = self::parseFmp4M3U8($videoM3u8Path);
                $videoInitFile = $videoParsed['initFile'];
                $videoSegments = $videoParsed['segmentFiles'];
            }
        }

        return [
            'initFile' => $initFile,
            'segmentFiles' => $segmentFiles,
            'audioInitFile' => $audioInitFile,
            'audioSegments' => $audioSegments,
            'videoInitFile' => $videoInitFile,
            'videoSegments' => $videoSegments
        ];
    }
}