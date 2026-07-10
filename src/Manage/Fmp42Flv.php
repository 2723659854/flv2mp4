<?php

namespace Xiaosongshu\Flv2mp4\Manage;

use Xiaosongshu\Flv2mp4\MP4\MP4;

class Fmp42Flv
{
    public $_config;
    public $onFlvData = null;
    public $onMediaInfo = null;
    public $error = null;

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

    public $videoWidth = 0;
    public $videoHeight = 0;

    public $videoTrackId = 0;
    public $audioTrackId = 0;
    public $videoTimescale = 90000;
    public $audioTimescale = 90000;

    public $_pendingSamples = [];

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
        $this->videoWidth = 0;
        $this->videoHeight = 0;
        $this->videoTrackId = 0;
        $this->audioTrackId = 0;
        $this->videoTimescale = 90000;
        $this->audioTimescale = 90000;
        $this->_pendingSamples = [];
    }

    public function setInitSegment($data)
    {
        $this->parseInitSegment($data);
    }

    public function flushInit()
    {
        if (!$this->flvHeaderWritten) {
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

    private function parseInitSegment($data)
    {
        $boxes = $this->parseBoxes($data, 0, strlen($data));
        $moov = $this->findBox($boxes, 'moov');
        if (!$moov) {
            if ($this->error) {
                call_user_func($this->error, new \Exception('未找到moov盒子'));
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
                'audioChannels' => $this->audioChannels
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
            $timescale = unpack('N', substr($mdhd['data'], 12, 4))[1];
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
                $this->parseAvcCFromStsdEntry(substr($stsdData, $pos, $entrySize));
                break;
            } elseif ($handlerType === 'soun' && $entryType === 'mp4a') {
                $this->hasAudio = true;
                $this->audioTrackId = $trackId;
                $this->audioTimescale = $timescale;
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
        if ($pos === false || $pos < 4) return;
        $boxSize = unpack('N', substr($data, $pos - 4, 4))[1];
        $esdsData = substr($data, $pos + 4, $boxSize - 8);
        $this->parseEsds($esdsData);
    }

    private function parseEsds($data, $hasFullBoxHeader = true)
    {
        $len = strlen($data);
        if ($len < 2) return;

        $pos = $hasFullBoxHeader ? 4 : 0;

        while ($pos + 2 <= $len) {
            $tag = ord($data[$pos]);
            $pos++;

            if ($pos >= $len) break;

            $length = 0;
            while ($pos < $len) {
                $byte = ord($data[$pos]);
                $pos++;
                $length = ($length << 7) | ($byte & 0x7F);
                if (($byte & 0x80) == 0) break;
            }

            if ($pos + $length > $len) break;

            if ($tag == 0x05) {
                $this->audioSpecificConfig = substr($data, $pos, $length);
                $this->parseAudioSpecificConfig($this->audioSpecificConfig);
                return;
            }

            if ($tag == 0x03) {
                $skipBytes = 3;
                if ($length > $skipBytes) {
                    $this->parseEsds(substr($data, $pos + $skipBytes, $length - $skipBytes), false);
                }
            } elseif ($tag == 0x04) {
                $skipBytes = 13;
                if ($length > $skipBytes) {
                    $this->parseEsds(substr($data, $pos + $skipBytes, $length - $skipBytes), false);
                }
            }

            $pos += $length;
        }
    }

    private function parseAudioSpecificConfig($config)
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
                $this->audioSampleRate = $baseRate * 2;
                $this->audioChannels = $channelConfig;
                break;
            case 29:
                $this->audioSampleRate = $baseRate * 2;
                $this->audioChannels = 2;
                break;
            default:
                $this->audioSampleRate = $baseRate;
                $this->audioChannels = $channelConfig;
                break;
        }
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

    private function writeSample($sample)
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

        $soundFormat = 10;
        $soundRate = $this->getSoundRate();
        $soundSize = 1;
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
    }

    private function writeAACSequenceHeader()
    {
        $soundFormat = 10;
        $soundRate = $this->getSoundRate();
        $soundSize = 1;
        $soundType = ($this->audioChannels == 2) ? 1 : 0;
        $audioHeader = ($soundFormat << 4) | ($soundRate << 2) | ($soundSize << 1) | $soundType;

        $audioData = chr($audioHeader) . "\x00" . $this->audioSpecificConfig;
        $this->writeFlvTag(8, $audioData, 0);
        $this->audioHeaderWritten = true;
    }

    private function getSoundRate()
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
}