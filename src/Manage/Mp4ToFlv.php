<?php

namespace Xiaosongshu\Flv2mp4\Manage;

/**
 * @purpose 将mp4转码为flv
 * @author yanglong
 * @time 2026年6月15日11:43:17
 * @note 本工具可以准确的将mp4转码为flv
 */
class Mp4ToFlv
{
    private $inputFile;
    private $outputFile;
    private $flvHandle;
    private $inputHandle;
    private $inputSize = 0;
    private $temporaryOutputFile;

    private $boxTree;
    private $videoTrack = null;
    private $audioTrack = null;

    private $hasWrittenHeader = false;
    private $hasWrittenVideoHeader = false;
    private $hasWrittenAudioHeader = false;

    private $sps = '';
    private $pps = '';
    private $audioSpecificConfig = '';
    private $audioSampleRate = 44100;
    private $audioChannels = 2;
    private $audioObjectType = 2;
    private $isHeAac = false;

    private $duration = 0;
    private $maxVideoDtsMs = 0;
    private $maxAudioDtsMs = 0;
    private $videoWidth = 0;
    private $videoHeight = 0;
    private $videoFrameRate = 30;

    public function __construct(string $inputFile, string $outputFile)
    {
        $this->inputFile = $inputFile;
        $this->outputFile = $outputFile;
        if (!file_exists($inputFile)) {
            throw new \RuntimeException("MP4文件不存在: {$inputFile}");
        }
        $outputDir = dirname($outputFile);
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0777, true);
        }
    }

    public function run(): bool
    {
        $this->inputHandle = fopen($this->inputFile, 'rb');
        if (!$this->inputHandle) {
            throw new \RuntimeException("无法读取MP4文件: {$this->inputFile}");
        }
        $stat = fstat($this->inputHandle);
        $this->inputSize = (int)($stat['size'] ?? 0);
        if ($this->inputSize < 8) {
            fclose($this->inputHandle);
            $this->inputHandle = null;
            throw new \RuntimeException("MP4文件为空或不完整");
        }

        $this->temporaryOutputFile = $this->outputFile . '.part.' . bin2hex(random_bytes(6));
        try {
            $this->parseMp4Boxes();
            $this->parseTracks();

            $this->flvHandle = fopen($this->temporaryOutputFile, 'w+b');
            if (!$this->flvHandle) {
                throw new \RuntimeException("无法创建临时输出文件: {$this->temporaryOutputFile}");
            }

            $this->writeFLVHeader();
            $this->extractAndWriteMediaData();
            if (!fflush($this->flvHandle)) {
                throw new \RuntimeException("无法刷新FLV临时文件");
            }
            fclose($this->flvHandle);
            $this->flvHandle = null;

            if (!@rename($this->temporaryOutputFile, $this->outputFile)) {
                throw new \RuntimeException("无法原子输出FLV文件: {$this->outputFile}");
            }
            $this->temporaryOutputFile = null;
            return true;
        } finally {
            if (is_resource($this->flvHandle)) fclose($this->flvHandle);
            if (is_resource($this->inputHandle)) fclose($this->inputHandle);
            $this->flvHandle = null;
            $this->inputHandle = null;
            if ($this->temporaryOutputFile && is_file($this->temporaryOutputFile)) {
                @unlink($this->temporaryOutputFile);
            }
        }
    }

    private function getAudioTypeName(): string
    {
        switch ($this->audioObjectType) {
            case 2:  return 'AAC-LC';
            case 5:  return 'HE-AAC (SBR)';
            case 29: return 'HE-AACv2 (SBR+PS)';
            default: return 'Unknown(' . $this->audioObjectType . ')';
        }
    }

    /* ========== Box 解析 ========== */
    private function parseMp4Boxes(): void
    {
        $this->boxTree = $this->parseBox(0, $this->inputSize);
    }

    private function parseBox(int $offset, int $end): array
    {
        $boxes = [];
        $containerTypes = ['moov', 'trak', 'mdia', 'minf', 'stbl', 'edts', 'dinf', 'udta', 'meta'];
        $metadataTypes = ['mvhd', 'tkhd', 'hdlr', 'mdhd', 'stsd', 'stsz', 'stco', 'co64', 'stsc', 'stts', 'ctts', 'stss'];
        while ($offset + 8 <= $end) {
            $header = $this->readAt($offset, 8);
            $size = unpack('N', substr($header, 0, 4))[1];
            $type = substr($header, 4, 4);
            $headerSize = 8;
            if ($size === 1) {
                if ($offset + 16 > $end) break;
                $parts = unpack('Nhigh/Nlow', $this->readAt($offset + 8, 8));
                $size = $parts['high'] * 4294967296 + $parts['low'];
                $headerSize = 16;
            } elseif ($size === 0) {
                $size = $end - $offset;
            }
            if ($size < $headerSize || $size > PHP_INT_MAX - $offset) break;
            $boxEnd = $offset + (int)$size;
            if ($boxEnd > $end) break;

            $payloadOffset = $offset + $headerSize;
            $payloadSize = (int)$size - $headerSize;
            $box = [
                'type' => $type,
                'size' => (int)$size,
                'offset' => $offset,
                'dataOffset' => $payloadOffset,
                'data' => '',
                'children' => []
            ];
            if (in_array($type, $containerTypes, true)) {
                $childrenOffset = $payloadOffset + ($type === 'meta' ? 4 : 0);
                $box['children'] = $this->parseBox($childrenOffset, $boxEnd);
            } elseif (in_array($type, $metadataTypes, true) && $payloadSize > 0) {
                $box['data'] = $this->readAt($payloadOffset, $payloadSize);
            }
            $boxes[] = $box;
            $offset = $boxEnd;
        }
        return $boxes;
    }

    private function readAt(int $offset, int $length): string
    {
        if ($offset < 0 || $length < 0 || $offset > $this->inputSize - $length) {
            throw new \RuntimeException("MP4读取范围无效: offset={$offset}, length={$length}");
        }
        if (fseek($this->inputHandle, $offset, SEEK_SET) !== 0) {
            throw new \RuntimeException("无法定位MP4偏移: {$offset}");
        }
        $data = '';
        while (strlen($data) < $length) {
            $chunk = fread($this->inputHandle, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                throw new \RuntimeException("MP4数据读取不完整: offset={$offset}, length={$length}");
            }
            $data .= $chunk;
        }
        return $data;
    }

    private function findBox(array $boxes, string $type): ?array
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

    private function findAllBoxes(array $boxes, string $type): array
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

    /* ========== 轨道解析 ========== */
    private function parseTracks(): void
    {
        $moov = $this->findBox($this->boxTree, 'moov');
        if (!$moov) throw new \RuntimeException("未找到moov盒子");

        $mvhd = $this->findBox([$moov], 'mvhd');
        if ($mvhd) {
            $mvhdData = $mvhd['data'];
            $version = ord($mvhdData[0]);
            if ($version == 0) {
                $timescale = unpack('N', substr($mvhdData, 12, 4))[1];
                $duration = unpack('N', substr($mvhdData, 16, 4))[1];
            } else {
                $timescale = unpack('N', substr($mvhdData, 20, 4))[1];
                $durationParts = unpack('Nhigh/Nlow', substr($mvhdData, 24, 8));
                $duration = $durationParts['high'] * 4294967296 + $durationParts['low'];
            }
            if ($timescale > 0) {
                $this->duration = round($duration * 1000 / $timescale) / 1000;
            }
        }

        $traks = $this->findAllBoxes([$moov], 'trak');
        foreach ($traks as $trak) {
            $this->parseTrack($trak);
        }
        if (!$this->videoTrack && !$this->audioTrack) {
            throw new \RuntimeException("未找到有效的视频或音频轨道");
        }
//        if ($this->audioTrack) {
//            echo "audioTrack 存在\n";
//            echo "audioSpecificConfig: '" . bin2hex($this->audioSpecificConfig) . "' (" . strlen($this->audioSpecificConfig) . " bytes)\n";
//            echo "audioObjectType: {$this->audioObjectType}\n";
//            echo "audioSampleRate: {$this->audioSampleRate}\n";
//        } else {
//            echo "audioTrack 不存在!\n";
//        }
    }

    private function parseTrack(array $trak): void
    {
        $tkhd = $this->findBox([$trak], 'tkhd');
        if (!$tkhd) return;
        $trackId = unpack('N', substr($tkhd['data'], 12, 4))[1];
        $mdia = $this->findBox([$trak], 'mdia');
        if (!$mdia) return;
        $hdlr = $this->findBox([$mdia], 'hdlr');
        if (!$hdlr) return;
        $handlerType = substr($hdlr['data'], 8, 4);

        $minf = $this->findBox([$mdia], 'minf');
        if (!$minf) return;

        $stbl = $this->findBox([$minf], 'stbl');
        if (!$stbl) return;
        $stsd = $this->findBox([$stbl], 'stsd');
        if (!$stsd) return;
        $mdhd = $this->findBox([$mdia], 'mdhd');
        $timescale = 90000;
        if ($mdhd) {
            $timescale = unpack('N', substr($mdhd['data'], 12, 4))[1];
        }
        $stsdData = $stsd['data'];
        $pos = 8;
        while ($pos + 8 <= strlen($stsdData)) {
            $entrySize = unpack('N', substr($stsdData, $pos, 4))[1];
            $entryType = substr($stsdData, $pos + 4, 4);
            if ($handlerType === 'vide' && $entryType === 'avc1') {
                $this->videoTrack = ['id' => $trackId, 'type' => 'video', 'codec' => 'avc1', 'timescale' => $timescale];
                $this->parseAvcCFromBox(substr($stsdData, $pos, $entrySize));
                break;
            } elseif ($handlerType === 'soun' && $entryType === 'mp4a') {
                $this->audioTrack = ['id' => $trackId, 'type' => 'audio', 'codec' => 'mp4a', 'timescale' => $timescale];
                $this->parseEsdsFromBox(substr($stsdData, $pos, $entrySize));
                break;
            }
            $pos += $entrySize;
        }
    }

    private function parseAvcCFromBox(string $data): void
    {
        $pos = strpos($data, 'avcC');
        if ($pos === false || $pos < 4) return;
        $boxSize = unpack('N', substr($data, $pos - 4, 4))[1];
        $avcCData = substr($data, $pos + 4, $boxSize - 8);
        $this->parseAvcC($avcCData);
    }

    private function parseAvcC(string $data): void
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
        $numPps = ord($data[$offset]); $offset++;
        for ($i = 0; $i < $numPps; $i++) {
            if ($offset + 2 > strlen($data)) break;
            $ppsLength = unpack('n', substr($data, $offset, 2))[1];
            $offset += 2;
            if ($offset + $ppsLength > strlen($data)) break;
            $this->pps = substr($data, $offset, $ppsLength);
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

    private function readUEG(string $data, int &$pos): int
    {
        $leadingZeroBits = 0;
        while ($pos < strlen($data) && (ord($data[$pos]) & 0x80) == 0) {
            $leadingZeroBits++; $pos++;
        }
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

    /* ========== ESDS 解析（★ 完全修复版） ========== */

    private function parseEsdsFromBox(string $data): void
    {
        $pos = strpos($data, 'esds');
        if ($pos === false || $pos < 4) return;
        $boxSize = unpack('N', substr($data, $pos - 4, 4))[1];
        $esdsData = substr($data, $pos + 4, $boxSize - 8);
        $this->parseEsds($esdsData);
    }

    private function parseEsds(string $data, bool $hasFullBoxHeader = true): void
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
                // ES_Descriptor: 跳过 ES_ID(2) + flags(1) = 3 bytes
                $skipBytes = 3;
                if ($length > $skipBytes) {
                    $this->parseEsds(substr($data, $pos + $skipBytes, $length - $skipBytes), false);
                }
            } elseif ($tag == 0x04) {
                // DecoderConfigDescriptor: 跳过 objectTypeIndication(1) + streamType(1)
                // + bufferSizeDB(3) + maxBitrate(4) + avgBitrate(4) = 13 bytes
                $skipBytes = 13;
                if ($length > $skipBytes) {
                    $this->parseEsds(substr($data, $pos + $skipBytes, $length - $skipBytes), false);
                }
            }

            $pos += $length;
        }
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
            case 2: // AAC-LC
                $this->audioSampleRate = $baseRate;
                $this->audioChannels = $channelConfig;
                $this->isHeAac = false;
                break;

            case 5: // HE-AAC (SBR)
                // freqIndex 指向核心采样率，输出 = 核心 × 2
                $this->audioSampleRate = $baseRate * 2;
                $this->audioChannels = $channelConfig;
                $this->isHeAac = true;
                break;

            case 29: // HE-AACv2 (SBR + PS)
                // freqIndex 指向核心采样率的一半，输出 = 核心 × 2
                // 核心 = baseRate，输出 = baseRate × 2
                $this->audioSampleRate = $baseRate * 2;
                $this->audioChannels = 2;
                $this->isHeAac = true;
                break;

            default:
                $this->audioSampleRate = $baseRate;
                $this->audioChannels = $channelConfig;
                $this->isHeAac = false;
                break;
        }
    }

    /* ========== 媒体数据提取 ========== */
    private function extractAndWriteMediaData(): void
    {
        if (!$this->findBox($this->boxTree, 'mdat')) {
            throw new \RuntimeException("未找到mdat盒子");
        }
        $moov = $this->findBox($this->boxTree, 'moov');
        if (!$moov) return;

        $videoSamples = [];
        $audioSamples = [];
        foreach ($this->findAllBoxes([$moov], 'trak') as $trak) {
            $mdia = $this->findBox([$trak], 'mdia');
            $hdlr = $mdia ? $this->findBox([$mdia], 'hdlr') : null;
            $stbl = $mdia ? $this->findBox([$mdia], 'stbl') : null;
            if (!$hdlr || !$stbl) continue;
            $handlerType = substr($hdlr['data'], 8, 4);
            if ($handlerType === 'vide') {
                $videoSamples = $this->extractSamplesFromStbl($stbl, $handlerType);
            } elseif ($handlerType === 'soun') {
                $audioSamples = $this->extractSamplesFromStbl($stbl, $handlerType);
            }
        }

        $videoIndex = 0;
        $audioIndex = 0;
        while ($videoIndex < count($videoSamples) || $audioIndex < count($audioSamples)) {
            $writeVideo = $videoIndex < count($videoSamples)
                && ($audioIndex >= count($audioSamples)
                    || $videoSamples[$videoIndex]['dtsMs'] <= $audioSamples[$audioIndex]['dtsMs']);
            $sample = $writeVideo ? $videoSamples[$videoIndex++] : $audioSamples[$audioIndex++];
            $data = $this->readAt($sample['offset'], $sample['size']);
            if ($writeVideo) {
                $this->writeVideoSample($data, $sample['dtsMs'], $sample['ctsMs'], $sample['keyframe']);
            } else {
                $this->writeAudioSample($data, $sample['dtsMs']);
            }
        }
    }

    private function extractSamplesFromStbl(array $stbl, string $handlerType): array
    {
        $stsz = $this->findBox([$stbl], 'stsz');
        $stco = $this->findBox([$stbl], 'stco');
        $co64 = $this->findBox([$stbl], 'co64');
        $stsc = $this->findBox([$stbl], 'stsc');
        $stts = $this->findBox([$stbl], 'stts');
        $ctts = $this->findBox([$stbl], 'ctts');
        $stss = $this->findBox([$stbl], 'stss');

        if (!$stsz || (!$stco && !$co64) || !$stsc || !$stts) return [];

        $timescale = ($handlerType === 'vide')
            ? ($this->videoTrack['timescale'] ?? 90000)
            : ($this->audioTrack['timescale'] ?? 90000);

        // 解析 STSZ
        $stszData = $stsz['data'];
        $sampleSize = unpack('N', substr($stszData, 4, 4))[1];
        $sampleCount = unpack('N', substr($stszData, 8, 4))[1];
        $sampleSizes = [];
        if ($sampleSize == 0) {
            for ($i = 0; $i < $sampleCount; $i++) {
                $sampleSizes[] = unpack('N', substr($stszData, 12 + $i * 4, 4))[1];
            }
        } else {
            $sampleSizes = array_fill(0, $sampleCount, $sampleSize);
        }

        // 解析 STSC
        $stscData = $stsc['data'];
        $stscEntries = unpack('N', substr($stscData, 4, 4))[1];
        $chunkMap = [];
        for ($i = 0; $i < $stscEntries; $i++) {
            $firstChunk = unpack('N', substr($stscData, 8 + $i * 12, 4))[1];
            $samplesPerChunk = unpack('N', substr($stscData, 12 + $i * 12, 4))[1];
            $chunkMap[$firstChunk] = $samplesPerChunk;
        }

        // 解析 STCO / CO64
        $chunkOffsetBox = $stco ?: $co64;
        $chunkOffsetData = $chunkOffsetBox['data'];
        $chunkCount = unpack('N', substr($chunkOffsetData, 4, 4))[1];
        $chunkOffsets = [];
        for ($i = 0; $i < $chunkCount; $i++) {
            if ($stco) {
                $chunkOffsets[] = unpack('N', substr($chunkOffsetData, 8 + $i * 4, 4))[1];
            } else {
                $parts = unpack('Nhigh/Nlow', substr($chunkOffsetData, 8 + $i * 8, 8));
                $chunkOffsets[] = (int)($parts['high'] * 4294967296 + $parts['low']);
            }
        }

        // 每个 chunk 的样本数
        $chunkSamples = [];
        for ($chunk = 0; $chunk < $chunkCount; $chunk++) {
            $chunkNum = $chunk + 1;
            $samples = 1;
            foreach ($chunkMap as $firstChunk => $spc) {
                if ($chunkNum >= $firstChunk) $samples = $spc;
            }
            $chunkSamples[] = $samples;
        }

        // 样本偏移
        $sampleOffsets = [];
        $sampleIndex = 0;
        foreach ($chunkOffsets as $chunkIdx => $chunkOffset) {
            $count = $chunkSamples[$chunkIdx];
            $runningOffset = $chunkOffset;
            for ($j = 0; $j < $count && $sampleIndex < count($sampleSizes); $j++) {
                $sampleOffsets[$sampleIndex] = $runningOffset;
                $runningOffset += $sampleSizes[$sampleIndex];
                $sampleIndex++;
            }
        }

        // 解析 STTS
        $sttsData = $stts['data'];
        $sttsEntries = unpack('N', substr($sttsData, 4, 4))[1];
        $timeDeltas = [];
        $pos = 8;
        for ($i = 0; $i < $sttsEntries; $i++) {
            $count = unpack('N', substr($sttsData, $pos, 4))[1];
            $delta = unpack('N', substr($sttsData, $pos + 4, 4))[1];
            $pos += 8;
            for ($j = 0; $j < $count; $j++) {
                $timeDeltas[] = $delta;
            }
        }

        // 解析 CTTS
        $ctOffsets = [];
        if ($ctts && $handlerType === 'vide') {
            $cttsData = $ctts['data'];
            $cttsVersion = ord($cttsData[0]);
            $cttsEntries = unpack('N', substr($cttsData, 4, 4))[1];
            $pos = 8;
            for ($i = 0; $i < $cttsEntries; $i++) {
                $count = unpack('N', substr($cttsData, $pos, 4))[1];
                $offset = unpack('N', substr($cttsData, $pos + 4, 4))[1];
                if ($cttsVersion === 1 && ($offset & 0x80000000)) {
                    $offset -= 0x100000000;
                }
                $pos += 8;
                for ($j = 0; $j < $count; $j++) {
                    $ctOffsets[] = $offset;
                }
            }
        }

        // 关键帧
        $keyframeSet = [];
        if ($stss && $handlerType === 'vide') {
            $stssData = $stss['data'];
            $entries = unpack('N', substr($stssData, 4, 4))[1];
            for ($i = 0; $i < $entries; $i++) {
                $keyframeSet[unpack('N', substr($stssData, 8 + $i * 4, 4))[1] - 1] = true;
            }
        }

        // 构建仅包含索引信息的样本；payload 在归并写入时按 offset 读取。
        $samples = [];
        $dtsTicks = 0;
        for ($i = 0; $i < count($sampleSizes) && $i < count($sampleOffsets); $i++) {
            $offset = $sampleOffsets[$i];
            $size = $sampleSizes[$i];
            if ($offset < 0 || $size < 0 || $offset > $this->inputSize - $size) {
                throw new \RuntimeException("MP4 sample范围无效: offset={$offset}, size={$size}");
            }

            $ctsTicks = $ctOffsets[$i] ?? 0;
            $samples[] = [
                'offset' => $offset,
                'size' => $size,
                'dtsMs' => (int)round($dtsTicks * 1000 / $timescale),
                'ctsMs' => (int)round($ctsTicks * 1000 / $timescale),
                'keyframe' => $handlerType !== 'vide' || !$stss || isset($keyframeSet[$i])
            ];

            $dtsTicks += $timeDeltas[$i] ?? 0;
        }
        return $samples;
    }

    /* ========== FLV 写入 ========== */
    private function writeVideoSample(string $data, int $dtsMs, int $ctsMs, bool $isKeyFrame): void
    {
        if ($dtsMs > $this->maxVideoDtsMs) $this->maxVideoDtsMs = $dtsMs;
        if (!$this->hasWrittenVideoHeader && !empty($this->sps)) {
            $this->writeAVCSequenceHeader();
        }

        $codecId = 7;
        $frameType = $isKeyFrame ? 1 : 2;
        $videoData = chr(($frameType << 4) | $codecId) . "\x01" .
            chr(($ctsMs >> 16) & 0xFF) . chr(($ctsMs >> 8) & 0xFF) . chr($ctsMs & 0xFF) .
            $data;

        $this->writeFLVTag(9, $videoData, $dtsMs);
    }

    private function writeAudioSample(string $data, int $dtsMs): void
    {
        if ($dtsMs > $this->maxAudioDtsMs) $this->maxAudioDtsMs = $dtsMs;
        if (!$this->hasWrittenAudioHeader && !empty($this->audioSpecificConfig)) {
            $this->writeAACSequenceHeader();
        }

        $soundFormat = 10;
        $soundRate = $this->getSoundRate();
        $soundSize = 1;
        $soundType = ($this->audioChannels == 2) ? 1 : 0;
        $audioHeader = ($soundFormat << 4) | ($soundRate << 2) | ($soundSize << 1) | $soundType;
        $audioData = chr($audioHeader) . "\x01" . $data;

        $this->writeFLVTag(8, $audioData, $dtsMs);
    }

    private function writeAVCSequenceHeader(): void
    {
        $record = "\x01" .
            ($this->sps[1] ?? "\x42") .
            ($this->sps[2] ?? "\x00") .
            ($this->sps[3] ?? "\x1F") .
            "\xFF" .
            "\xE1" . pack('n', strlen($this->sps)) . $this->sps .
            "\x01" . pack('n', strlen($this->pps)) . $this->pps;

        $videoData = "\x17\x00\x00\x00\x00" . $record;
        $this->writeFLVTag(9, $videoData, 0);
        $this->hasWrittenVideoHeader = true;
    }

    private function writeAACSequenceHeader(): void
    {
        $soundFormat = 10;
        $soundRate = $this->getSoundRate();
        $soundSize = 1;
        $soundType = ($this->audioChannels == 2) ? 1 : 0;
        $audioHeader = ($soundFormat << 4) | ($soundRate << 2) | ($soundSize << 1) | $soundType;

        // ★ 使用完整的 AudioSpecificConfig ★
        $audioData = chr($audioHeader) . "\x00" . $this->audioSpecificConfig;
        $this->writeFLVTag(8, $audioData, 0);
        $this->hasWrittenAudioHeader = true;
    }

    private function getSoundRate(): int
    {
        // ★ 始终使用输出采样率 ★
        switch ($this->audioSampleRate) {
            case 5512:  return 0;
            case 11025: return 1;
            case 22050: return 2;
            case 44100: return 3;
            case 48000: return 4;
            default:    return 3;
        }
    }

    private function writeFLVHeader(): void
    {
        if ($this->hasWrittenHeader) return;
        $flags = 0;
        if ($this->videoTrack) $flags |= 0x01;
        if ($this->audioTrack) $flags |= 0x04;
        fwrite($this->flvHandle, "FLV\x01" . chr($flags) . "\x00\x00\x00\x09");
        fwrite($this->flvHandle, "\x00\x00\x00\x00");
        $this->hasWrittenHeader = true;
        $this->writeMetaData();
    }

    private function writeMetaData(): void
    {
        $metaData = [
            'duration' => (float)$this->duration,
            'width' => (float)($this->videoWidth ?: 720),
            'height' => (float)($this->videoHeight ?: 480),
            'videocodecid' => 'avc1',
            'audiocodecid' => 'mp4a',
            'audiosamplerate' => (float)($this->audioSampleRate ?: 44100),
            'audiochannels' => (float)($this->audioChannels ?: 2),
            'framerate' => (float)($this->videoFrameRate ?: 30.0),
        ];

        $data = $this->serializeAmf0($metaData);
        $onMetaData = $this->serializeAmf0('onMetaData') . $data;
        $this->writeFLVTag(18, $onMetaData, 0);
    }

    private function serializeAmf0($value): string
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

    private function packDoubleBE(float $value): string
    {
        return strrev(pack('d', $value));
    }

    private function writeFLVTag(int $tagType, string $data, int $timestamp): void
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

        fwrite($this->flvHandle, $tag);
        fwrite($this->flvHandle, $data);
        fwrite($this->flvHandle, pack('N', 11 + $dataSize));
    }
}