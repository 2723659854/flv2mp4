<?php

namespace Xiaosongshu\Flv2mp4\Manage;

/**
 * @purpose 标准flv文件转码mp4文件操作类
 * @author yanglong
 * @time 2026年7月9日12:41:59
 */
class FlvToMp4
{
    private $inputFile;
    private $outputFile;
    private $flvData;

    private $hasVideo = false;
    private $hasAudio = false;

    private $videoWidth = 0;
    private $videoHeight = 0;
    private $videoFrameRate = 30;
    private $sps = '';
    private $pps = '';
    private $avccHeader = '';

    private $audioSampleRate = 44100;
    private $audioChannels = 2;
    private $audioObjectType = 2;
    private $audioSpecificConfig = '';

    private $videoSamples = [];
    private $audioSamples = [];

    private $duration = 0;
    private $videoTimescale = 90000;
    private $audioTimescale = 44100;
    private $videoBaseTimestamp = 0;
    private $audioBaseTimestamp = 0;
    private $videoSampleIndex = 0;
    private $audioSampleIndex = 0;

    public function __construct(string $inputFile, string $outputFile)
    {
        $this->inputFile = $inputFile;
        $this->outputFile = $outputFile;
        if (!file_exists($inputFile)) {
            throw new \RuntimeException("FLV文件不存在: {$inputFile}");
        }
        $outputDir = dirname($outputFile);
        if (!$outputDir || !is_dir($outputDir)) {
            mkdir($outputDir, 0777, true);
        }
    }

    public function run(): bool
    {
        $this->flvData = file_get_contents($this->inputFile);
        if (empty($this->flvData)) {
            throw new \RuntimeException("无法读取FLV文件");
        }

        $this->parseFlv();

        if (!$this->hasVideo && !$this->hasAudio) {
            throw new \RuntimeException("未找到有效的视频或音频轨道");
        }

        usort($this->videoSamples, function($a, $b) {
            if ($a['timestamp'] != $b['timestamp']) {
                return $a['timestamp'] - $b['timestamp'];
            }
            return $a['index'] - $b['index'];
        });

        $prevTimestamp = -1;
        foreach ($this->videoSamples as &$sample) {
            if ($sample['timestamp'] <= $prevTimestamp) {
                $sample['timestamp'] = $prevTimestamp + 1;
            }
            $prevTimestamp = $sample['timestamp'];
        }
        unset($sample);

        usort($this->audioSamples, function($a, $b) {
            if ($a['timestamp'] != $b['timestamp']) {
                return $a['timestamp'] - $b['timestamp'];
            }
            return $a['index'] - $b['index'];
        });

        $this->buildMp4();

        return true;
    }

    private function parseFlv(): void
    {
        $offset = 0;

        if (strlen($this->flvData) < 9) {
            throw new \RuntimeException("无效的FLV文件");
        }

        $signature = substr($this->flvData, 0, 3);
        if ($signature !== 'FLV') {
            throw new \RuntimeException("不是有效的FLV文件");
        }

        $version = ord($this->flvData[3]);
        $flags = ord($this->flvData[4]);
        $this->hasAudio = ($flags & 0x04) !== 0;
        $this->hasVideo = ($flags & 0x01) !== 0;

        $headerSize = unpack('N', substr($this->flvData, 5, 4))[1];
        $offset = $headerSize;

        while ($offset + 11 <= strlen($this->flvData)) {
            $prevTagSize = unpack('N', substr($this->flvData, $offset, 4))[1];
            $offset += 4;

            if ($offset + 11 > strlen($this->flvData)) break;

            $tagType = ord($this->flvData[$offset]);
            $dataSize = unpack('N', "\x00" . substr($this->flvData, $offset + 1, 3))[1];
            $timestamp = unpack('N', $this->flvData[$offset + 7] . substr($this->flvData, $offset + 4, 3))[1];
            $streamId = unpack('N', "\x00" . substr($this->flvData, $offset + 8, 3))[1];

            $offset += 11;

            if ($offset + $dataSize > strlen($this->flvData)) break;

            $tagData = substr($this->flvData, $offset, $dataSize);
            $offset += $dataSize;

            switch ($tagType) {
                case 8:
                    $this->parseAudioTag($tagData, $timestamp);
                    break;
                case 9:
                    $this->parseVideoTag($tagData, $timestamp);
                    break;
                case 18:
                    $this->parseMetaDataTag($tagData);
                    break;
            }
        }
    }

    private function parseAudioTag(string $data, int $timestamp): void
    {
        if (strlen($data) < 2) return;

        $soundFormat = (ord($data[0]) >> 4) & 0x0F;

        if ($soundFormat == 10) {
            $aacPacketType = ord($data[1]);

            if ($aacPacketType == 0) {
                $this->audioSpecificConfig = substr($data, 2);
                $this->parseAudioSpecificConfig($this->audioSpecificConfig);
                $this->audioTimescale = $this->audioSampleRate;
            } else {
                $audioData = substr($data, 2);
                $this->audioSamples[] = [
                    'data' => $audioData,
                    'timestamp' => $timestamp,
                    'index' => $this->audioSampleIndex++
                ];
            }
        }
    }

    private function parseVideoTag(string $data, int $timestamp): void
    {
        if (strlen($data) < 5) return;

        $frameType = (ord($data[0]) >> 4) & 0x0F;
        $codecId = ord($data[0]) & 0x0F;

        if ($codecId == 7) {
            $avcPacketType = ord($data[1]);
            $cts = unpack('N', "\x00" . substr($data, 2, 3))[1];

            if ($avcPacketType == 0) {
                $sequenceData = substr($data, 5);
                $this->parseAVCSequenceHeader($sequenceData);
            } else {
                $videoData = substr($data, 5);
                $isKeyframe = ($frameType == 1);
                $this->videoSamples[] = [
                    'data' => $videoData,
                    'timestamp' => $timestamp,
                    'cts' => $cts,
                    'keyframe' => $isKeyframe,
                    'index' => $this->videoSampleIndex++
                ];
            }
        }
    }

    private function parseMetaDataTag(string $data): void
    {
        $pos = 0;
        while ($pos < strlen($data)) {
            $type = ord($data[$pos]);
            $pos++;

            if ($type == 0x02) {
                $nameLen = unpack('n', substr($data, $pos, 2))[1];
                $pos += 2;
                $name = substr($data, $pos, $nameLen);
                $pos += $nameLen;

                $valType = ord($data[$pos]);
                $pos++;

                if ($valType == 0x00) {
                    $value = unpack('d', substr($data, $pos, 8))[1];
                    $pos += 8;

                    switch ($name) {
                        case 'width':
                            $this->videoWidth = (int)$value;
                            break;
                        case 'height':
                            $this->videoHeight = (int)$value;
                            break;
                        case 'framerate':
                            $this->videoFrameRate = (float)$value;
                            break;
                    }
                }
            } elseif ($type == 0x09) {
                break;
            } else {
                $pos++;
            }
        }
    }

    private function parseAudioSpecificConfig(string $config): void
    {
        if (strlen($config) < 2) return;

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

    private function parseAVCSequenceHeader(string $data): void
    {
        if (strlen($data) < 5) return;

        $this->avccHeader = $data;

        $offset = 5;
        $numSps = ord($data[$offset]) & 0x1F;
        $offset++;

        for ($i = 0; $i < $numSps; $i++) {
            $spsLen = unpack('n', substr($data, $offset, 2))[1];
            $offset += 2;
            $this->sps = substr($data, $offset, $spsLen);
            $offset += $spsLen;
            $this->parseSpsForDimensions($this->sps);
            break;
        }

        $numPps = ord($data[$offset]);
        $offset++;

        for ($i = 0; $i < $numPps; $i++) {
            $ppsLen = unpack('n', substr($data, $offset, 2))[1];
            $offset += 2;
            $this->pps = substr($data, $offset, $ppsLen);
            $offset += $ppsLen;
            break;
        }
    }

    private function parseSpsForDimensions(string $sps): void
    {
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
            $numRefFrames = $this->readUEG($sps, $pos);
            $pos = $this->skipUEG($sps, $pos);
            for ($i = 0; $i < $numRefFrames; $i++) {
                $pos = $this->skipSEG($sps, $pos);
            }
        }

        $pos = $this->skipUEG($sps, $pos);
        $pos++;
    }

    private function readUEG(string $data, int &$pos): int
    {
        $leadingZeroBits = 0;
        while ($pos < strlen($data) && (ord($data[$pos]) & 0x80) == 0) { $leadingZeroBits++; $pos++; }
        if ($pos >= strlen($data)) return 0;
        $result = ord($data[$pos]) & 0x7F; $pos++;
        for ($i = 0; $i < $leadingZeroBits; $i++) {
            if ($pos >= strlen($data)) break;
            $result = ($result << 7) | (ord($data[$pos]) & 0x7F); $pos++;
        }
        return $result - 1;
    }

    private function skipUEG(string $data, int $pos): int
    {
        while ($pos < strlen($data) && (ord($data[$pos]) & 0x80) == 0) $pos++;
        if ($pos >= strlen($data)) return $pos;
        $pos++;
        return $pos;
    }

    private function skipSEG(string $data, int $pos): int
    {
        return $this->skipUEG($data, $pos);
    }

    private function annexbToAvcc(string $data): string
    {
        $result = '';
        $pos = 0;
        $len = strlen($data);

        while ($pos < $len) {
            while ($pos < $len && ord($data[$pos]) == 0) {
                $pos++;
            }
            if ($pos >= $len) break;

            if (ord($data[$pos]) == 1) {
                $pos++;
            } else {
                if ($pos > 0) break;
                $pos = 0;
            }

            $nalStart = $pos;
            $nextStart = -1;

            for ($i = $pos; $i < $len - 3; $i++) {
                if (ord($data[$i]) == 0 && ord($data[$i+1]) == 0 && ord($data[$i+2]) == 1) {
                    $nextStart = $i;
                    break;
                }
                if ($i < $len - 4 && ord($data[$i]) == 0 && ord($data[$i+1]) == 0 && ord($data[$i+2]) == 0 && ord($data[$i+3]) == 1) {
                    $nextStart = $i;
                    break;
                }
            }

            if ($nextStart == -1) {
                $nalSize = $len - $nalStart;
                if ($nalSize > 0) {
                    $result .= pack('N', $nalSize) . substr($data, $nalStart, $nalSize);
                }
                break;
            }

            $nalSize = $nextStart - $nalStart;
            if ($nalSize > 0) {
                $result .= pack('N', $nalSize) . substr($data, $nalStart, $nalSize);
            }
            $pos = $nextStart;
        }

        return $result;
    }

    private function buildEsds(): string
    {
        $config = $this->audioSpecificConfig;
        $configSize = strlen($config);

        $tag5 = "\x05" . $this->writeDescriptorLength($configSize) . $config;

        $tag4Content = "\x40" . chr($this->audioObjectType) . "\x00\x00\x00" .
            "\x00\x00\x00\x00\x00\x00\x00\x00" . $tag5;
        $tag4 = "\x04" . $this->writeDescriptorLength(strlen($tag4Content)) . $tag4Content;

        $tag3Content = "\x00\x01\x00" . $tag4;
        $tag3 = "\x03" . $this->writeDescriptorLength(strlen($tag3Content)) . $tag3Content;

        return pack('N', 0) . $tag3;
    }

    private function writeDescriptorLength(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }
        $result = '';
        while ($length > 0) {
            $byte = $length & 0x7F;
            $length >>= 7;
            if ($result !== '') {
                $byte |= 0x80;
            }
            $result = chr($byte) . $result;
        }
        return $result;
    }

    private function buildMp4(): void
    {
        $this->calculateDuration();

        $ftyp = $this->buildFtyp();
        $mdat = $this->buildMdat();

        $ftypSize = strlen($ftyp);
        $moovSize = 0;

        $videoTotalSize = 0;
        foreach ($this->getSortedVideoSamples() as $sample) {
            $videoTotalSize += strlen($sample['data']);
        }

        $stcoVideoBase = $ftypSize + 10000;
        $stcoAudioBase = $stcoVideoBase + $videoTotalSize;

        $moov = $this->buildMoov($stcoVideoBase, $stcoAudioBase);

        $moovSize = strlen($moov);
        $mdatHeaderSize = 8;
        $actualStcoVideoBase = $ftypSize + $moovSize + $mdatHeaderSize;
        $actualStcoAudioBase = $actualStcoVideoBase + $videoTotalSize;

        if ($stcoVideoBase != $actualStcoVideoBase) {
            $moov = $this->buildMoov($actualStcoVideoBase, $actualStcoAudioBase);
        }

        file_put_contents($this->outputFile, $ftyp . $moov . $mdat);
    }

    private function calculateDuration(): void
    {
        $maxVideoDts = 0;
        $maxAudioDts = 0;

        foreach ($this->getSortedVideoSamples() as $sample) {
            if ($sample['timestamp'] > $maxVideoDts) $maxVideoDts = $sample['timestamp'];
        }

        foreach ($this->audioSamples as $sample) {
            if ($sample['timestamp'] > $maxAudioDts) $maxAudioDts = $sample['timestamp'];
        }

        $maxDtsMs = max($maxVideoDts, $maxAudioDts);

        $this->duration = (int)($maxDtsMs * $this->videoTimescale / 1000);
    }

    private function buildFtyp(): string
    {
        $data = pack('C*',
            0x69, 0x73, 0x6F, 0x6D, 0x00, 0x00, 0x00, 0x01,
            0x69, 0x73, 0x6F, 0x6D, 0x61, 0x76, 0x63, 0x31,
            0x6D, 0x70, 0x34, 0x32, 0x00, 0x00, 0x00, 0x01,
            0x6D, 0x70, 0x34, 0x31
        );
        return $this->box('ftyp', $data);
    }

    private function buildMoov(int $stcoVideoBase = 0, int $stcoAudioBase = 0): string
    {
        $mvhd = $this->buildMvhd($this->videoTimescale, $this->duration);
        $tracks = [];

        if ($this->hasVideo) {
            $tracks[] = $this->buildVideoTrak($stcoVideoBase);
        }
        if ($this->hasAudio) {
            $tracks[] = $this->buildAudioTrak($stcoAudioBase);
        }

        return $this->box('moov', $mvhd, ...$tracks);
    }

    private function buildMvhd(int $timescale, int $duration): string
    {
        $data = pack('C*',
            0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,
            ($timescale>>24)&0xFF, ($timescale>>16)&0xFF, ($timescale>>8)&0xFF, $timescale&0xFF,
            ($duration>>24)&0xFF, ($duration>>16)&0xFF, ($duration>>8)&0xFF, $duration&0xFF,
            0x00,0x01,0x00,0x00,0x01,0x00,0x00,0x00,0x00,0x00,0x00,0x00,
            0x00,0x00,0x00,0x00,0x00,0x01,0x00,0x00,0x00,0x00,0x00,0x00,
            0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x01,0x00,0x00,
            0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,
            0x40,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,
            0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,
            0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0xFF,0xFF,0xFF,0xFF
        );
        return $this->box('mvhd', $data);
    }

    private function buildElst(int $mediaTime, int $duration): string
    {
        $data = pack('N', 0);
        $data .= pack('N', 1);
        $data .= pack('N', $duration);
        $data .= pack('N', $mediaTime);
        $data .= pack('N', 0x00010000);
        return $this->box('elst', $data);
    }

    private function buildEdts(int $mediaTime, int $duration): string
    {
        $elst = $this->buildElst($mediaTime, $duration);
        return $this->box('edts', $elst);
    }

    private function buildVideoTrak(int $stcoBase = 0): string
    {
        $trackId = 1;
        $numSamples = count($this->videoSamples);
        $duration = ($numSamples > 0) ? $this->duration : 0;

        $tkhd = $this->buildTkhd($trackId, $duration, $this->videoWidth, $this->videoHeight);
        $mdhd = $this->buildMdhd($this->videoTimescale, $duration);
        $hdlr = $this->buildHdlr('vide');
        $minf = $this->buildMinf('video', $stcoBase);

        $mdia = $this->box('mdia', $mdhd, $hdlr, $minf);

        $mediaTime = 0;
        if ($numSamples > 0) {
            $samples = $this->getSortedVideoSamples();
            $firstSample = $samples[0];
            $mediaTime = (int)($firstSample['timestamp'] * $this->videoTimescale / 1000);
        }

        $edts = $this->buildEdts($mediaTime, $duration);

        return $this->box('trak', $tkhd, $edts, $mdia);
    }

    private function buildAudioTrak(int $stcoBase = 0): string
    {
        $trackId = 2;
        $numSamples = count($this->audioSamples);
        $duration = ($numSamples > 0) ? (int)($this->duration * $this->audioTimescale / $this->videoTimescale) : 0;

        $tkhd = $this->buildTkhd($trackId, $duration, 0, 0);
        $mdhd = $this->buildMdhd($this->audioTimescale, $duration);
        $hdlr = $this->buildHdlr('soun');
        $minf = $this->buildMinf('audio', $stcoBase);

        $mdia = $this->box('mdia', $mdhd, $hdlr, $minf);

        return $this->box('trak', $tkhd, $mdia);
    }

    private function buildTkhd(int $trackId, int $duration, int $width, int $height): string
    {
        $fixedWidth = $width << 16;
        $fixedHeight = $height << 16;

        $data = pack('C*',
            0x00,0x00,0x00,0x07,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,
            ($trackId>>24)&0xFF, ($trackId>>16)&0xFF, ($trackId>>8)&0xFF, $trackId&0xFF,
            0x00,0x00,0x00,0x00,
            ($duration>>24)&0xFF, ($duration>>16)&0xFF, ($duration>>8)&0xFF, $duration&0xFF,
            0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,
            0x00,0x00,0x00,0x00,0x00,0x01,0x00,0x00,0x00,0x00,0x00,0x00,
            0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x01,0x00,0x00,
            0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,
            0x40,0x00,0x00,0x00,
            ($fixedWidth>>24)&0xFF, ($fixedWidth>>16)&0xFF, ($fixedWidth>>8)&0xFF, $fixedWidth&0xFF,
            ($fixedHeight>>24)&0xFF, ($fixedHeight>>16)&0xFF, ($fixedHeight>>8)&0xFF, $fixedHeight&0xFF
        );
        return $this->box('tkhd', $data);
    }

    private function buildMdhd(int $timescale, int $duration): string
    {
        $data = pack('C*',
            0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,
            ($timescale>>24)&0xFF, ($timescale>>16)&0xFF, ($timescale>>8)&0xFF, $timescale&0xFF,
            ($duration>>24)&0xFF, ($duration>>16)&0xFF, ($duration>>8)&0xFF, $duration&0xFF,
            0x55,0xC4,0x00,0x00
        );
        return $this->box('mdhd', $data);
    }

    private function buildHdlr(string $type): string
    {
        if ($type === 'vide') {
            $data = pack('C*',
                0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,
                0x76,0x69,0x64,0x65,0x00,0x00,0x00,0x00,
                0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,
                0x56,0x69,0x64,0x65,0x6F,0x48,0x61,0x6E,
                0x64,0x6C,0x65,0x72,0x00
            );
        } else {
            $data = pack('C*',
                0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,
                0x73,0x6F,0x75,0x6E,0x00,0x00,0x00,0x00,
                0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,
                0x53,0x6F,0x75,0x6E,0x64,0x48,0x61,0x6E,
                0x64,0x6C,0x65,0x72,0x00
            );
        }
        return $this->box('hdlr', $data);
    }

    private function buildMinf(string $type, int $stcoBase = 0): string
    {
        if ($type === 'video') {
            $vmhd = pack('C*', 0x00,0x00,0x00,0x01,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00);
            $xmhd = $this->box('vmhd', $vmhd);
        } else {
            $smhd = pack('C*', 0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00);
            $xmhd = $this->box('smhd', $smhd);
        }

        $drefData = pack('N*', 0, 1) . pack('N', 12) . "url " . pack('N', 1);
        $dref = $this->box('dref', $drefData);
        $dinf = $this->box('dinf', $dref);

        $stbl = $this->buildStbl($type, $stcoBase);

        return $this->box('minf', $xmhd, $dinf, $stbl);
    }

    private function buildStbl(string $type, int $stcoBase = 0): string
    {
        if ($type === 'video') {
            $samples = $this->getSortedVideoSamples();
            $stsd = $this->buildVideoStsd();
            $stts = $this->buildVideoStts();
            $ctts = $this->buildCtts();
            $stsc = $this->buildStsc($samples);
            $stsz = $this->buildStsz($samples);
            $stco = $this->buildVideoStco($stcoBase);
            return $this->box('stbl', $stsd, $stts, $ctts, $stsc, $stsz, $stco);
        } else {
            $samples = $this->getSortedAudioSamples();
            $stsd = $this->buildAudioStsd();
            $stts = $this->buildAudioStts();
            $stsc = $this->buildStsc($samples);
            $stsz = $this->buildStsz($samples);
            $stco = $this->buildAudioStco($stcoBase);
            return $this->box('stbl', $stsd, $stts, $stsc, $stsz, $stco);
        }
    }

    private function buildVideoStsd(): string
    {
        $avcc = $this->avccHeader;
        $avcCBox = $this->box('avcC', $avcc);

        $width = $this->videoWidth ?: 1;
        $height = $this->videoHeight ?: 1;

        $avc1Data = str_repeat("\x00", 6) .
            pack('n', 1) .
            pack('n', 0) . pack('n', 0) . pack('N', 0) . pack('N', 0) . pack('N', 0) .
            pack('n', $width) . pack('n', $height) .
            pack('N', 0x00480000) . pack('N', 0x00480000) . pack('N', 0) .
            pack('n', 1) .
            "\x00" . str_repeat("\x00", 31) .
            pack('n', 0x0018) . pack('n', 0xFFFF);

        $avc1Box = $this->box('avc1', $avc1Data, $avcCBox);

        $stsdPrefix = pack('C*', 0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x01);

        return $this->box('stsd', $stsdPrefix, $avc1Box);
    }

    private function buildAudioStsd(): string
    {
        $channelCount = $this->audioChannels;
        $sampleRate = $this->audioSampleRate;

        $mp4aData = pack('C*',
            0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x01,
            0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,
            0x00,$channelCount,0x00,0x10,0x00,0x00,0x00,0x00,
            ($sampleRate>>8)&0xFF, $sampleRate&0xFF, 0x00,0x00
        );

        $esds = $this->buildEsds();
        $esdsBox = $this->box('esds', $esds);
        $mp4aBox = $this->box('mp4a', $mp4aData, $esdsBox);

        $stsdPrefix = pack('C*', 0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x01);

        return $this->box('stsd', $stsdPrefix, $mp4aBox);
    }

    private function getSortedVideoSamples(): array
    {
        return $this->videoSamples;
    }

    private function getSortedAudioSamples(): array
    {
        return $this->audioSamples;
    }

    private function buildVideoStts(): string
    {
        $samples = $this->getSortedVideoSamples();
        $count = count($samples);

        if ($count === 0) {
            return $this->box('stts', pack('N*', 0, 0));
        }

        $data = pack('N', 0);

        $baseTimestamp = PHP_INT_MAX;
        foreach ($samples as $sample) {
            if ($sample['timestamp'] < $baseTimestamp) {
                $baseTimestamp = $sample['timestamp'];
            }
        }

        $entries = [];
        $currentDts = 0;

        foreach ($samples as $sample) {
            $targetDts = (int)(($sample['timestamp'] - $baseTimestamp) * $this->videoTimescale / 1000);
            $delta = $targetDts - $currentDts;
            if ($delta <= 0) $delta = 1;
            $entries[] = ['count' => 1, 'delta' => $delta];
            $currentDts += $delta;
        }

        $data .= pack('N', count($entries));

        foreach ($entries as $entry) {
            $data .= pack('N', $entry['count']);
            $data .= pack('N', $entry['delta']);
        }

        return $this->box('stts', $data);
    }

    private function buildAudioStts(): string
    {
        $samples = $this->getSortedAudioSamples();
        $count = count($samples);

        if ($count === 0) {
            return $this->box('stts', pack('N*', 0, 0));
        }

        $data = pack('N', 0);

        $baseTimestamp = PHP_INT_MAX;
        foreach ($samples as $sample) {
            if ($sample['timestamp'] < $baseTimestamp) {
                $baseTimestamp = $sample['timestamp'];
            }
        }

        $this->audioBaseTimestamp = $baseTimestamp;

        $entries = [];
        $currentDts = 0;

        foreach ($samples as $sample) {
            $targetDts = (int)(($sample['timestamp'] - $baseTimestamp) * $this->audioTimescale / 1000);
            $delta = $targetDts - $currentDts;
            if ($delta <= 0) $delta = 1;
            $entries[] = ['count' => 1, 'delta' => $delta];
            $currentDts += $delta;
        }

        $data .= pack('N', count($entries));

        foreach ($entries as $entry) {
            $data .= pack('N', $entry['count']);
            $data .= pack('N', $entry['delta']);
        }

        return $this->box('stts', $data);
    }

    private function buildCtts(): string
    {
        $samples = $this->getSortedVideoSamples();
        $count = count($samples);

        if ($count === 0) {
            return $this->box('ctts', pack('N*', 0, 0));
        }

        $data = pack('N', 0);
        $data .= pack('N', $count);

        foreach ($samples as $sample) {
            $cts = (int)($sample['cts'] * $this->videoTimescale / 1000);
            $data .= pack('N', 1);
            $data .= pack('N', $cts);
        }

        return $this->box('ctts', $data);
    }

    private function buildStsc(array $samples): string
    {
        $count = count($samples);

        if ($count === 0) {
            return $this->box('stsc', pack('N*', 0, 0));
        }

        $data = pack('N', 0);
        $data .= pack('N', $count);

        for ($i = 0; $i < $count; $i++) {
            $data .= pack('N', $i + 1);
            $data .= pack('N', 1);
            $data .= pack('N', 1);
        }

        return $this->box('stsc', $data);
    }

    private function buildStsz(array $samples): string
    {
        $count = count($samples);

        $data = pack('N', 0);
        $data .= pack('N', 0);
        $data .= pack('N', $count);

        foreach ($samples as $sample) {
            $data .= pack('N', strlen($sample['data']));
        }

        return $this->box('stsz', $data);
    }

    private function buildVideoStco(int $baseOffset = 0): string
    {
        $samples = $this->getSortedVideoSamples();
        $count = count($samples);

        $data = pack('N', 0);
        $data .= pack('N', $count);

        $offset = $baseOffset;
        foreach ($samples as $sample) {
            $data .= pack('N', $offset);
            $offset += strlen($sample['data']);
        }

        return $this->box('stco', $data);
    }

    private function buildAudioStco(int $baseOffset = 0): string
    {
        $samples = $this->getSortedAudioSamples();
        $count = count($samples);

        $data = pack('N', 0);
        $data .= pack('N', $count);

        $offset = $baseOffset;
        foreach ($samples as $sample) {
            $data .= pack('N', $offset);
            $offset += strlen($sample['data']);
        }

        return $this->box('stco', $data);
    }

    private function buildMdat(): string
    {
        $mdatData = '';

        foreach ($this->getSortedVideoSamples() as $sample) {
            $mdatData .= $sample['data'];
        }

        foreach ($this->getSortedAudioSamples() as $sample) {
            $mdatData .= $sample['data'];
        }

        return $this->box('mdat', $mdatData);
    }

    private function box(string $type, ...$datas): string
    {
        $size = 8;
        foreach ($datas as $data) $size += strlen($data);
        $result = pack('N', $size) . $type;
        foreach ($datas as $data) $result .= $data;
        return $result;
    }
}