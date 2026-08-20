<?php

namespace Xiaosongshu\Flv2mp4\Manage;

use Xiaosongshu\Flv2mp4\Flv\SPSParser;

/**
 * @purpose 标准flv文件转码mp4文件操作类
 * @author yanglong
 * @time 2026年7月9日12:41:59
 */
class FlvToMp4
{
    private $inputFile;
    private $outputFile;
    private $mdatFile;
    private $mdatPath = '';
    private $mdatSize = 0;

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
        $this->mdatPath = tempnam(dirname($this->outputFile), '.flv2mp4-mdat-');
        if ($this->mdatPath === false) {
            throw new \RuntimeException("无法创建临时媒体文件");
        }
        $this->mdatFile = fopen($this->mdatPath, 'w+b');
        if ($this->mdatFile === false) {
            @unlink($this->mdatPath);
            throw new \RuntimeException("无法打开临时媒体文件");
        }

        try {
            $this->parseFlv();

            if (empty($this->videoSamples) && empty($this->audioSamples)) {
                throw new \RuntimeException("未找到有效的视频或音频轨道");
            }
            $this->hasVideo = !empty($this->videoSamples);
            $this->hasAudio = !empty($this->audioSamples);

            fflush($this->mdatFile);
            $this->buildMp4();
            return true;
        } finally {
            if (is_resource($this->mdatFile)) {
                fclose($this->mdatFile);
            }
            $this->mdatFile = null;
            if ($this->mdatPath !== '') {
                @unlink($this->mdatPath);
                $this->mdatPath = '';
            }
        }
    }

    private function parseFlv(): void
    {
        $input = fopen($this->inputFile, 'rb');
        if ($input === false) {
            throw new \RuntimeException("无法读取FLV文件");
        }

        try {
            $header = $this->readExact($input, 9);
            if ($header === null || substr($header, 0, 3) !== 'FLV') {
                throw new \RuntimeException("不是有效的FLV文件");
            }

            $flags = ord($header[4]);
            $this->hasAudio = ($flags & 0x04) !== 0;
            $this->hasVideo = ($flags & 0x01) !== 0;
            $headerSize = unpack('N', substr($header, 5, 4))[1];
            if ($headerSize < 9 || ($headerSize > 9 && fseek($input, $headerSize - 9, SEEK_CUR) !== 0)) {
                throw new \RuntimeException("无效的FLV文件头");
            }

            while (true) {
                $previousTagSize = $this->readExact($input, 4);
                if ($previousTagSize === null) {
                    break;
                }
                if (feof($input) || ftell($input) === filesize($this->inputFile)) {
                    break;
                }
                $tagHeader = $this->readExact($input, 11);
                if ($tagHeader === null) {
                    throw new \RuntimeException("FLV标签头不完整");
                }

                $tagType = ord($tagHeader[0]);
                $dataSize = unpack('N', "\x00" . substr($tagHeader, 1, 3))[1];
                $timestamp = unpack('N', $tagHeader[7] . substr($tagHeader, 4, 3))[1];
                $tagData = $this->readExact($input, $dataSize);
                if ($tagData === null) {
                    throw new \RuntimeException("FLV标签数据不完整");
                }

                if ($tagType === 8) {
                    $this->parseAudioTag($tagData, $timestamp);
                } elseif ($tagType === 9) {
                    $this->parseVideoTag($tagData, $timestamp);
                } elseif ($tagType === 18) {
                    $this->parseMetaDataTag($tagData);
                }
            }
        } finally {
            fclose($input);
        }
    }

    private function readExact($stream, int $length): ?string
    {
        $data = '';
        while (strlen($data) < $length && !feof($stream)) {
            $chunk = fread($stream, $length - strlen($data));
            if ($chunk === false) {
                throw new \RuntimeException("读取FLV文件失败");
            }
            $data .= $chunk;
        }
        return strlen($data) === $length ? $data : null;
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
            } elseif ($aacPacketType === 1) {
                $audioData = substr($data, 2);
                $this->audioSamples[] = $this->writeSample($audioData, [
                    'timestamp' => $timestamp,
                    'index' => $this->audioSampleIndex++
                ]);
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
            if (($cts & 0x800000) !== 0) {
                $cts -= 0x1000000;
            }

            if ($avcPacketType === 0) {
                $sequenceData = substr($data, 5);
                $this->parseAVCSequenceHeader($sequenceData);
            } elseif ($avcPacketType === 1) {
                $videoData = substr($data, 5);
                $this->videoSamples[] = $this->writeSample($videoData, [
                    'timestamp' => $timestamp,
                    'cts' => $cts,
                    'keyframe' => $frameType === 1,
                    'index' => $this->videoSampleIndex++
                ]);
            }
        }
    }

    private function writeSample(string $data, array $sample): array
    {
        $length = strlen($data);
        $written = 0;
        while ($written < $length) {
            $count = fwrite($this->mdatFile, substr($data, $written));
            if ($count === false || $count === 0) {
                throw new \RuntimeException("写入临时媒体文件失败");
            }
            $written += $count;
        }
        $sample['offset'] = $this->mdatSize;
        $sample['size'] = $length;
        $this->mdatSize += $length;
        return $sample;
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
                    if ($pos + 8 > strlen($data)) break;
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
        if (strlen($sps) < 4) return;

        try {
            $config = SPSParser::parseSPS($sps);
            $this->videoWidth = (int)($config['present_size']['width'] ?? $config['codec_size']['width'] ?? 0);
            $this->videoHeight = (int)($config['present_size']['height'] ?? $config['codec_size']['height'] ?? 0);
            $fps = (float)($config['frame_rate']['fps'] ?? 0);
            if ($fps > 0 && $fps <= 120) {
                $this->videoFrameRate = $fps;
            }
        } catch (\Throwable $e) {
            return;
        }
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
        $mdatHeaderSize = $this->mdatSize + 8 <= 0xFFFFFFFF ? 8 : 16;
        $mediaBase = strlen($ftyp) + $mdatHeaderSize;
        $moov = '';

        for ($i = 0; $i < 3; $i++) {
            $mediaBase = strlen($ftyp) + strlen($moov) + $mdatHeaderSize;
            $nextMoov = $this->buildMoov($mediaBase, $mediaBase);
            if (strlen($nextMoov) === strlen($moov)) {
                $moov = $nextMoov;
                break;
            }
            $moov = $nextMoov;
        }

        $outputPath = tempnam(dirname($this->outputFile), '.flv2mp4-output-');
        if ($outputPath === false) {
            throw new \RuntimeException("无法创建临时输出文件");
        }
        $output = fopen($outputPath, 'w+b');
        if ($output === false) {
            @unlink($outputPath);
            throw new \RuntimeException("无法打开临时输出文件");
        }

        try {
            $this->writeAll($output, $ftyp . $moov . $this->buildMdatHeader());
            rewind($this->mdatFile);
            if (stream_copy_to_stream($this->mdatFile, $output) !== $this->mdatSize) {
                throw new \RuntimeException("复制媒体数据失败");
            }
            fflush($output);
            fclose($output);
            $output = null;
            if (!rename($outputPath, $this->outputFile)) {
                throw new \RuntimeException("无法原子替换输出文件");
            }
        } finally {
            if (is_resource($output)) {
                fclose($output);
            }
            if (file_exists($outputPath)) {
                @unlink($outputPath);
            }
        }
    }

    private function writeAll($stream, string $data): void
    {
        $written = 0;
        $length = strlen($data);
        while ($written < $length) {
            $count = fwrite($stream, substr($data, $written));
            if ($count === false || $count === 0) {
                throw new \RuntimeException("写入MP4文件失败");
            }
            $written += $count;
        }
    }

    private function buildMdatHeader(): string
    {
        $size = $this->mdatSize + 8;
        if ($size <= 0xFFFFFFFF) {
            return pack('N', $size) . 'mdat';
        }
        return pack('N', 1) . 'mdat' . $this->packUint64($this->mdatSize + 16);
    }

    private function packUint64(int $value): string
    {
        return pack('N2', intdiv($value, 0x100000000), $value % 0x100000000);
    }

    private function calculateDuration(): void
    {
        $videoFallback = $this->videoFrameRate > 0 ? (int)round(1000 / $this->videoFrameRate) : 33;
        $audioFallback = $this->audioSampleRate > 0 ? (int)round(1024 * 1000 / $this->audioSampleRate) : 23;
        $durationMs = max(
            $this->trackDurationMs($this->videoSamples, $videoFallback),
            $this->trackDurationMs($this->audioSamples, $audioFallback)
        );
        $this->duration = (int)round($durationMs * $this->videoTimescale / 1000);
    }

    private function trackDurationMs(array $samples, int $fallback): int
    {
        $count = count($samples);
        if ($count === 0) {
            return 0;
        }
        $lastDuration = $count > 1
            ? max(0, $samples[$count - 1]['timestamp'] - $samples[$count - 2]['timestamp'])
            : $fallback;
        return $samples[$count - 1]['timestamp'] - $samples[0]['timestamp'] + $lastDuration;
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

        return $this->box('trak', $tkhd, $mdia);
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
            $stss = $this->buildStss();
            return $this->box('stbl', $stsd, $stts, $ctts, $stsc, $stsz, $stco, $stss);
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

    private function calculateVideoFrameRate(): float
    {
        $samples = $this->getSortedVideoSamples();
        $count = count($samples);

        if ($count < 2) {
            return $this->videoFrameRate > 0 && $this->videoFrameRate <= 120 ? $this->videoFrameRate : 30;
        }

        $totalInterval = 0;
        $intervals = 0;

        for ($i = 1; $i < $count; $i++) {
            $interval = $samples[$i]['timestamp'] - $samples[$i - 1]['timestamp'];
            if ($interval > 0) {
                $totalInterval += $interval;
                $intervals++;
            }
        }

        if ($intervals == 0) {
            return $this->videoFrameRate > 0 && $this->videoFrameRate <= 120 ? $this->videoFrameRate : 30;
        }

        $avgIntervalMs = $totalInterval / $intervals;
        $fps = 1000 / $avgIntervalMs;

        if ($fps <= 0 || $fps > 120) {
            return $this->videoFrameRate > 0 && $this->videoFrameRate <= 120 ? $this->videoFrameRate : 30;
        }

        if ($this->videoFrameRate > 0 && $this->videoFrameRate <= 120) {
            $diff = abs($fps - $this->videoFrameRate) / $this->videoFrameRate;
            if ($diff < 0.1) {
                return $this->videoFrameRate;
            }
        }

        $roundedFps = round($fps);
        if ($roundedFps >= 1 && $roundedFps <= 120) {
            return $roundedFps;
        }

        return $fps;
    }

    private function buildVideoStts(): string
    {
        $samples = $this->getSortedVideoSamples();
        $count = count($samples);

        if ($count === 0) {
            return $this->box('stts', pack('N*', 0, 0));
        }

        return $this->buildTimestampTable(
            $samples,
            $this->videoTimescale,
            (int)round($this->videoTimescale / $this->calculateVideoFrameRate())
        );
    }

    private function buildAudioStts(): string
    {
        $samples = $this->getSortedAudioSamples();
        $count = count($samples);

        if ($count === 0) {
            return $this->box('stts', pack('N*', 0, 0));
        }

        $fallback = 1024;
        return $this->buildTimestampTable($samples, $this->audioTimescale, $fallback);
    }

    private function buildTimestampTable(array $samples, int $timescale, int $fallback): string
    {
        $deltas = [];
        $count = count($samples);
        for ($i = 0; $i < $count - 1; $i++) {
            $delta = (int)round(($samples[$i + 1]['timestamp'] - $samples[$i]['timestamp']) * $timescale / 1000);
            $deltas[] = max(0, $delta);
        }
        $deltas[] = $count > 1 ? $deltas[$count - 2] : max(1, $fallback);

        $entries = [];
        foreach ($deltas as $delta) {
            $last = count($entries) - 1;
            if ($last >= 0 && $entries[$last]['delta'] === $delta) {
                $entries[$last]['count']++;
            } else {
                $entries[] = ['count' => 1, 'delta' => $delta];
            }
        }

        $data = pack('N2', 0, count($entries));
        foreach ($entries as $entry) {
            $data .= pack('N2', $entry['count'], $entry['delta']);
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

        $hasNegative = false;
        $offsets = [];
        foreach ($samples as $sample) {
            $offset = (int)round($sample['cts'] * $this->videoTimescale / 1000);
            $offsets[] = $offset;
            $hasNegative = $hasNegative || $offset < 0;
        }

        $entries = [];
        foreach ($offsets as $offset) {
            $last = count($entries) - 1;
            if ($last >= 0 && $entries[$last]['offset'] === $offset) {
                $entries[$last]['count']++;
            } else {
                $entries[] = ['count' => 1, 'offset' => $offset];
            }
        }

        $data = pack('N2', $hasNegative ? 0x01000000 : 0, count($entries));
        foreach ($entries as $entry) {
            $data .= pack('N2', $entry['count'], $entry['offset'] & 0xFFFFFFFF);
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

    private function buildStss(): string
    {
        $samples = $this->getSortedVideoSamples();
        $keyframes = [];
        foreach ($samples as $index => $sample) {
            if (!empty($sample['keyframe'])) {
                $keyframes[] = $index + 1;
            }
        }

        $data = pack('N', 0) . pack('N', count($keyframes));
        foreach ($keyframes as $sampleNumber) {
            $data .= pack('N', $sampleNumber);
        }

        return $this->box('stss', $data);
    }

    private function buildStsz(array $samples): string
    {
        $count = count($samples);

        $data = pack('N', 0);
        $data .= pack('N', 0);
        $data .= pack('N', $count);

        foreach ($samples as $sample) {
            $data .= pack('N', $sample['size']);
        }

        return $this->box('stsz', $data);
    }

    private function buildVideoStco(int $baseOffset = 0): string
    {
        $samples = $this->getSortedVideoSamples();
        $count = count($samples);

        $data = pack('N', 0);
        $data .= pack('N', $count);

        foreach ($samples as $sample) {
            $data .= pack('N', $baseOffset + $sample['offset']);
        }

        return $this->box('stco', $data);
    }

    private function buildAudioStco(int $baseOffset = 0): string
    {
        $samples = $this->getSortedAudioSamples();
        $count = count($samples);

        $data = pack('N', 0);
        $data .= pack('N', $count);

        foreach ($samples as $sample) {
            $data .= pack('N', $baseOffset + $sample['offset']);
        }

        return $this->box('stco', $data);
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