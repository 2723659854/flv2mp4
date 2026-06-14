<?php

namespace Xiaosongshu\Flv2mp4\Manage;

/**
 * @purpose MP4转FLV工具
 * @author yanglong
 * @time 2026年6月4日
 */
class Mp4ToFlv
{
    private $inputFile;
    private $outputFile;
    private $flvHandle;

    private $mp4Data;
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
    private $audioProfile = 2;

    private $duration = 0;
    private $maxVideoDtsMs = 0;
    private $maxAudioDtsMs = 0;
    private $videoWidth = 0;
    private $videoHeight = 0;
    private $videoFrameRate = 30;
    private $videoCodecId = 'avc1';
    private $audioCodecId = 'mp4a';

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
        $this->mp4Data = file_get_contents($this->inputFile);
        if (empty($this->mp4Data)) {
            throw new \RuntimeException("无法读取MP4文件");
        }

        $this->parseMp4Boxes();

        $this->flvHandle = fopen($this->outputFile, 'wb');
        if (!$this->flvHandle) {
            throw new \RuntimeException("无法创建输出文件: {$this->outputFile}");
        }

        try {
            $this->parseTracks();
            $this->writeFLVHeader();
            $this->extractAndWriteMediaData();
            return true;
        } finally {
            fclose($this->flvHandle);
        }
    }

    /* ========== Box 解析 (不变) ========== */
    private function parseMp4Boxes(): void
    {
        $this->boxTree = $this->parseBox($this->mp4Data, 0, strlen($this->mp4Data));
    }

    private function parseBox(string $data, int $offset, int $end): array
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
                $box['children'] = $this->parseBox($data, $offset + $headerSize, $boxEnd);
            }
            $boxes[] = $box;
            $offset = $boxEnd;
        }
        return $boxes;
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
        
        // 读取时长
        $mvhd = $this->findBox([$moov], 'mvhd');
        if ($mvhd) {
            $mvhdData = $mvhd['data'];
            $version = ord($mvhdData[0]);
            if ($version == 0) {
                $timescale = unpack('N', substr($mvhdData, 12, 4))[1];
                $duration = unpack('N', substr($mvhdData, 16, 4))[1];
            } else {
                $timescale = unpack('N', substr($mvhdData, 20, 4))[1];
                $duration = unpack('J', substr($mvhdData, 24, 8))[1];
            }
            $this->duration = round($duration * 1000 / $timescale) / 1000;
        }
        
        $traks = $this->findAllBoxes([$moov], 'trak');
        foreach ($traks as $trak) {
            $this->parseTrack($trak);
        }
        if (!$this->videoTrack && !$this->audioTrack) {
            throw new \RuntimeException("未找到有效的视频或音频轨道");
        }
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
        
        // stbl 在 minf 里面，而不是直接在 mdia 里面
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
        // 直接在数据中查找 avcC box
        $pos = strpos($data, 'avcC');
        if ($pos === false) {
            return;
        }
        
        // avcC box 的结构：4字节大小 + 4字节类型 + 内容
        // pos 是 'avcC' 的位置，所以大小在 pos - 4
        if ($pos < 4) {
            return;
        }
        
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
            break; // 只取第一个SPS
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
        // 跳过 NALU 头
        if (ord($sps[0]) & 0x80) {
            $pos++;
        }
        
        // 跳过 profile_idc, constraint_set_flags, level_idc
        $pos += 3;
        
        // 跳过 seq_parameter_set_id
        $pos++;
        
        // 读取 log2_max_frame_num_minus4
        $pos = $this->skipUEG($sps, $pos);
        
        // 读取 pic_order_cnt_type
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
        
        // 读取 num_ref_frames
        $pos = $this->skipUEG($sps, $pos);
        
        // 读取 gaps_in_frame_num_value_allowed_flag
        $pos++;
        
        // 读取 pic_width_in_mbs_minus1
        $picWidthInMbsMinus1 = $this->readUEG($sps, $pos);
        $pos = $this->skipUEG($sps, $pos);
        
        // 读取 pic_height_in_map_units_minus1
        $picHeightInMapUnitsMinus1 = $this->readUEG($sps, $pos);
        $pos = $this->skipUEG($sps, $pos);
        
        $this->videoWidth = ($picWidthInMbsMinus1 + 1) * 16;
        $this->videoHeight = ($picHeightInMapUnitsMinus1 + 1) * 16;
    }

    private function readUEG(string $data, int &$pos): int
    {
        $result = 0;
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

    private function skipUEG(string $data, int $pos): int
    {
        $result = 0;
        $leadingZeroBits = 0;
        
        while ($pos < strlen($data) && (ord($data[$pos]) & 0x80) == 0) {
            $leadingZeroBits++;
            $pos++;
        }
        
        if ($pos >= strlen($data)) return $pos;
        
        $result = ord($data[$pos]) & 0x7F;
        $pos++;
        
        for ($i = 0; $i < $leadingZeroBits; $i++) {
            if ($pos >= strlen($data)) break;
            $result = ($result << 7) | (ord($data[$pos]) & 0x7F);
            $pos++;
        }
        
        return $pos;
    }

    private function skipSEG(string $data, int $pos): int
    {
        $uegResult = 0;
        $leadingZeroBits = 0;
        
        while ($pos < strlen($data) && (ord($data[$pos]) & 0x80) == 0) {
            $leadingZeroBits++;
            $pos++;
        }
        
        if ($pos >= strlen($data)) return $pos;
        
        $uegResult = ord($data[$pos]) & 0x7F;
        $pos++;
        
        for ($i = 0; $i < $leadingZeroBits; $i++) {
            if ($pos >= strlen($data)) break;
            $uegResult = ($uegResult << 7) | (ord($data[$pos]) & 0x7F);
            $pos++;
        }
        
        return $pos;
    }

    private function parseEsdsFromBox(string $data): void
    {
        // 直接在数据中查找 esds box
        $pos = strpos($data, 'esds');
        if ($pos === false) {
            return;
        }
        
        // esds box 的结构：4字节大小 + 4字节类型 + 内容
        // pos 是 'esds' 的位置，所以大小在 pos - 4
        if ($pos < 4) {
            return;
        }
        
        $boxSize = unpack('N', substr($data, $pos - 4, 4))[1];
        $esdsData = substr($data, $pos + 4, $boxSize - 8);
        
        $this->parseEsds($esdsData);
    }

    private function parseEsds(string $data): void
    {
        if (strlen($data) < 20) return;
        $pos = 4; // 跳过 version+flags
        
        while ($pos < strlen($data)) {
            if ($pos + 1 > strlen($data)) break;
            $tag = ord($data[$pos]);
            $pos++;
            
            // 解析长度（可变长度编码）
            if ($pos >= strlen($data)) break;
            $length = 0;
            while ($pos < strlen($data)) {
                $byte = ord($data[$pos]);
                $length = ($length << 7) | ($byte & 0x7F);
                $pos++;
                if (($byte & 0x80) == 0) break;
            }
            
            if ($pos + $length > strlen($data)) {
                break;
            }
            
            $contentStart = $pos;
            
            switch ($tag) {
                case 0x03: // ES Descriptor
                    // ES_ID (2 bytes) + streamPriority (1 byte)
                    $pos += 3;
                    // 剩余部分包含子描述符
                    $remainingLength = $length - 3;
                    if ($remainingLength > 0 && $pos + $remainingLength <= strlen($data)) {
                        $subData = substr($data, $pos, $remainingLength);
                        $this->parseEsdsNested($subData);
                    }
                    break;
                    
                case 0x04: // Decoder Config Descriptor
                    // objectTypeIndication (1) + streamType (1) + bufferSizeDB (3) + maxBitrate (4) + avgBitrate (4)
                    $pos += 13;
                    // 剩余部分包含子描述符（如 Decoder Specific Info）
                    $remainingLength = $length - 13;
                    if ($remainingLength > 0 && $pos + $remainingLength <= strlen($data)) {
                        $subData = substr($data, $pos, $remainingLength);
                        $this->parseEsdsNested($subData);
                    }
                    break;
                    
                case 0x05: // Decoder Specific Info
                    $this->audioSpecificConfig = substr($data, $contentStart, $length);
                    if ($length >= 2) {
                        $config = unpack('n', $this->audioSpecificConfig)[1];
                        $this->audioProfile = ($config >> 11) & 0x1F;
                        $freqIndex = ($config >> 7) & 0x0F;
                        $this->audioChannels = ($config >> 3) & 0x0F;
                        $rates = [96000,88200,64000,48000,44100,32000,24000,22050,16000,12000,11025,8000,7350];
                        $this->audioSampleRate = $rates[$freqIndex] ?? 44100;
                    }
                    return; // 找到目标，退出
                    
                default:
                    // 未知描述符，跳过
                    break;
            }
            
            $pos = $contentStart + $length;
        }
    }
    
    private function parseEsdsNested(string $data): void
    {
        $pos = 0;
        while ($pos < strlen($data)) {
            if ($pos + 1 > strlen($data)) break;
            $tag = ord($data[$pos]);
            $pos++;
            
            // 解析长度（可变长度编码）
            if ($pos >= strlen($data)) break;
            $length = 0;
            while ($pos < strlen($data)) {
                $byte = ord($data[$pos]);
                $length = ($length << 7) | ($byte & 0x7F);
                $pos++;
                if (($byte & 0x80) == 0) break;
            }
            
            if ($pos + $length > strlen($data)) {
                break;
            }
            
            $contentStart = $pos;
            
            switch ($tag) {
                case 0x03: // ES Descriptor
                    $pos += 3; // ES_ID + streamPriority
                    $remainingLength = $length - 3;
                    if ($remainingLength > 0 && $pos + $remainingLength <= strlen($data)) {
                        $subData = substr($data, $pos, $remainingLength);
                        $this->parseEsdsNested($subData);
                    }
                    break;
                    
                case 0x04: // Decoder Config Descriptor
                    $pos += 13; // objectTypeIndication + streamType + bufferSizeDB + maxBitrate + avgBitrate
                    $remainingLength = $length - 13;
                    if ($remainingLength > 0 && $pos + $remainingLength <= strlen($data)) {
                        $subData = substr($data, $pos, $remainingLength);
                        $this->parseEsdsNested($subData);
                    }
                    break;
                    
                case 0x05: // Decoder Specific Info
                    $this->audioSpecificConfig = substr($data, $contentStart, $length);
                    if ($length >= 2) {
                        $config = unpack('n', $this->audioSpecificConfig)[1];
                        $this->audioProfile = ($config >> 11) & 0x1F;
                        $freqIndex = ($config >> 7) & 0x0F;
                        $this->audioChannels = ($config >> 3) & 0x0F;
                        $rates = [96000,88200,64000,48000,44100,32000,24000,22050,16000,12000,11025,8000,7350];
                        $this->audioSampleRate = $rates[$freqIndex] ?? 44100;
                    }
                    return;
                    
                default:
                    break;
            }
            
            $pos = $contentStart + $length;
        }
    }

    /* ========== 媒体数据提取 (关键修复) ========== */
    private function extractAndWriteMediaData(): void
    {
        $mdat = $this->findBox($this->boxTree, 'mdat');
        if (!$mdat) throw new \RuntimeException("未找到mdat盒子");
        $moov = $this->findBox($this->boxTree, 'moov');
        if (!$moov) return;

        $allSamples = []; // 统一按毫秒DTS排序

        $traks = $this->findAllBoxes([$moov], 'trak');
        foreach ($traks as $trak) {
            $mdia = $this->findBox([$trak], 'mdia');
            if (!$mdia) continue;
            $hdlr = $this->findBox([$mdia], 'hdlr');
            if (!$hdlr) continue;
            $handlerType = substr($hdlr['data'], 8, 4);
            $stbl = $this->findBox([$mdia], 'stbl');
            if (!$stbl) continue;

            $samples = $this->extractSamplesFromStbl($stbl, $mdat['data'], $mdat['offset'], $handlerType);
            foreach ($samples as &$s) {
                $s['type'] = ($handlerType === 'vide') ? 'video' : 'audio';
            }
            $allSamples = array_merge($allSamples, $samples);
        }

        // 按毫秒DTS排序
        usort($allSamples, function($a, $b) {
            return $a['dtsMs'] - $b['dtsMs'];
        });

        // 交错写入
        foreach ($allSamples as $sample) {
            if ($sample['type'] === 'video') {
                $this->writeVideoSample($sample['data'], $sample['dtsMs'], $sample['ctsMs'] ?? 0, $sample['keyframe']);
            } else {
                $this->writeAudioSample($sample['data'], $sample['dtsMs']);
            }
        }
    }

    /**
     * 从stbl盒子提取样本数组，返回的DTS/CTS已转换为毫秒
     */
    private function extractSamplesFromStbl(array $stbl, string $mdatData, int $mdatOffset, string $handlerType): array
    {
        $stsz = $this->findBox([$stbl], 'stsz');
        $stco = $this->findBox([$stbl], 'stco');
        $stsc = $this->findBox([$stbl], 'stsc');
        $stts = $this->findBox([$stbl], 'stts');
        $ctts = $this->findBox([$stbl], 'ctts');
        $stss = $this->findBox([$stbl], 'stss');

        if (!$stsz || !$stco || !$stsc || !$stts) return [];

        // 获取轨道 timescale
        $timescale = ($handlerType === 'vide') ? ($this->videoTrack['timescale'] ?? 90000) : ($this->audioTrack['timescale'] ?? 90000);

        // 解析 STSZ
        $stszData = $stsz['data'];
        // stsz 内容结构: version(1) + flags(3) + sampleSize(4) + sampleCount(4) + entries...
        // stsz 是 FullBox，总是包含 version/flags（4 字节）
        $stszOffset = 4; // 跳过 version/flags
        
        $sampleSize = unpack('N', substr($stszData, $stszOffset, 4))[1];
        $sampleCount = unpack('N', substr($stszData, $stszOffset + 4, 4))[1];
        $sampleSizes = [];
        if ($sampleSize == 0) {
            for ($i = 0; $i < $sampleCount; $i++) {
                $sampleSizes[] = unpack('N', substr($stszData, $stszOffset + 8 + $i * 4, 4))[1];
            }
        } else {
            $sampleSizes = array_fill(0, $sampleCount, $sampleSize);
        }

        // 解析 STSC (chunk -> samples per chunk)
        $stscData = $stsc['data'];
        $stscOffset = 0;
        
        // 检查是否有 version/flags
        if (strlen($stscData) >= 8 && unpack('N', substr($stscData, 0, 4))[1] == 0) {
            $stscOffset = 4;
        }
        
        $stscEntries = unpack('N', substr($stscData, $stscOffset, 4))[1];
        $chunkMap = []; // chunkNum => ['samples' => int, 'desc' => int]
        $pos = $stscOffset + 4;
        for ($i = 0; $i < $stscEntries; $i++) {
            $firstChunk = unpack('N', substr($stscData, $pos, 4))[1];
            $samplesPerChunk = unpack('N', substr($stscData, $pos+4, 4))[1];
            $descIndex = unpack('N', substr($stscData, $pos+8, 4))[1];
            $pos += 12;
            $chunkMap[$firstChunk] = ['samples' => $samplesPerChunk, 'desc' => $descIndex];
        }

        // 解析 STCO
        $stcoData = $stco['data'];
        $stcoOffset = 0;
        
        // 检查是否有 version/flags
        if (strlen($stcoData) >= 8 && unpack('N', substr($stcoData, 0, 4))[1] == 0) {
            $stcoOffset = 4;
        }
        
        $chunkCount = unpack('N', substr($stcoData, $stcoOffset, 4))[1];
        $chunkOffsets = [];
        for ($i = 0; $i < $chunkCount; $i++) {
            $chunkOffsets[] = unpack('N', substr($stcoData, $stcoOffset + 4 + $i * 4, 4))[1];
        }

        // 构建每个chunk的samples per chunk列表
        $chunkSamples = [];
        for ($chunk = 0; $chunk < $chunkCount; $chunk++) {
            $chunkNum = $chunk + 1;
            $samples = 0;
            foreach ($chunkMap as $firstChunk => $map) {
                if ($chunkNum >= $firstChunk) {
                    $samples = $map['samples'];
                }
            }
            $chunkSamples[] = $samples;
        }

        // 计算每个样本在mdat中的偏移
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

        // 解析 STTS (时间增量)
        $sttsData = $stts['data'];
        $sttsEntries = unpack('N', substr($sttsData, 4, 4))[1];
        $timeDeltas = [];
        $pos = 8;
        for ($i = 0; $i < $sttsEntries; $i++) {
            $count = unpack('N', substr($sttsData, $pos, 4))[1];
            $delta = unpack('N', substr($sttsData, $pos+4, 4))[1];
            $pos += 8;
            for ($j = 0; $j < $count; $j++) {
                $timeDeltas[] = $delta;
            }
        }

        // 解析 CTTS (视频)
        $ctOffsets = [];
        if ($ctts && $handlerType === 'vide') {
            $cttsData = $ctts['data'];
            $cttsEntries = unpack('N', substr($cttsData, 4, 4))[1];
            $pos = 8;
            for ($i = 0; $i < $cttsEntries; $i++) {
                $count = unpack('N', substr($cttsData, $pos, 4))[1];
                $offset = unpack('N', substr($cttsData, $pos+4, 4))[1];
                $pos += 8;
                for ($j = 0; $j < $count; $j++) {
                    $ctOffsets[] = $offset;
                }
            }
        }

        // 关键帧样本 (stss)
        $keyframeSet = [];
        if ($stss && $handlerType === 'vide') {
            $stssData = $stss['data'];
            $entries = unpack('N', substr($stssData, 4, 4))[1];
            for ($i = 0; $i < $entries; $i++) {
                $keyframeSet[unpack('N', substr($stssData, 8 + $i*4, 4))[1] - 1] = true; // 0-based
            }
        }

        // 构建样本数组，DTS/CTS 转换为毫秒
        $samples = [];
        $dtsTicks = 0;
        for ($i = 0; $i < count($sampleSizes) && $i < count($sampleOffsets); $i++) {
            // 使用文件绝对偏移读取样本数据
            $offset = $sampleOffsets[$i];
            if ($offset < 0 || $offset + $sampleSizes[$i] > strlen($this->mp4Data)) continue;
            $rawData = substr($this->mp4Data, $offset, $sampleSizes[$i]);

            $ctsTicks = $ctOffsets[$i] ?? 0;
            // DTS 是当前累计的解码时间戳
            $dtsMs = round($dtsTicks * 1000 / $timescale);
            // CTS 是 PTS - DTS，需要转换为毫秒
            $ctsMs = round($ctsTicks * 1000 / $timescale);
            $isKeyframe = isset($keyframeSet[$i]);

            $samples[] = [
                'data' => $rawData,
                'dtsMs' => $dtsMs,
                'ctsMs' => $ctsMs,
                'keyframe' => $isKeyframe
            ];

            $dtsTicks += $timeDeltas[$i] ?? 0;
        }
        return $samples;
    }

    /* ========== FLV 写入 ========== */
    private function writeVideoSample(string $data, int $dtsMs, int $ctsMs, bool $isKeyFrame): void
    {
        // 跟踪最大视频时间戳
        if ($dtsMs > $this->maxVideoDtsMs) {
            $this->maxVideoDtsMs = $dtsMs;
        }

        // 确保 AVC Sequence Header 在第一个视频帧之前被写入
        if (!$this->hasWrittenVideoHeader && !empty($this->sps)) {
            $this->writeAVCSequenceHeader();
        }

        $codecId = 7;
        $frameType = $isKeyFrame ? 1 : 2;
        
        // 构建 FLV 视频数据
        // 字节1: frameType(高4位) | codecId(低4位)
        // 字节2: AVCPacketType = 1 (NALU)
        // 字节3-5: Composition Time (CTS)
        // 后续: MP4 中的原始 AVCC 数据（包含长度前缀的多个 NAL 单元）
        $videoData = chr(($frameType << 4) | $codecId) . "\x01" .
            chr(($ctsMs >> 16) & 0xFF) . chr(($ctsMs >> 8) & 0xFF) . chr($ctsMs & 0xFF) .
            $data;

        $this->writeFLVTag(9, $videoData, $dtsMs);
    }
    
    /**
     * 解析 AVCC 格式的数据，拆分成单独的 NAL 单元
     * MP4 中的样本数据包含多个 NAL 单元，每个单元前面有 4 字节长度前缀
     */
    private function parseAvccNalUnits(string $data): array
    {
        $nalUnits = [];
        $offset = 0;
        $length = strlen($data);
        
        while ($offset + 4 <= $length) {
            // 读取 4 字节长度前缀
            $nalLength = unpack('N', substr($data, $offset, 4))[1];
            $offset += 4;
            
            if ($offset + $nalLength <= $length) {
                $nalUnit = substr($data, $offset, $nalLength);
                $nalUnits[] = $nalUnit;
                $offset += $nalLength;
            } else {
                break;
            }
        }
        
        return $nalUnits;
    }

    private function writeAudioSample(string $data, int $dtsMs): void
    {
        // 跟踪最大音频时间戳
        if ($dtsMs > $this->maxAudioDtsMs) {
            $this->maxAudioDtsMs = $dtsMs;
        }

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
        // 构建 AVCDecoderConfigurationRecord
        $configVersion = "\x01";
        $profile = $this->sps[1] ?? "\x42";
        $compat  = $this->sps[2] ?? "\x00";
        $level   = $this->sps[3] ?? "\x1F";
        $lengthMinusOne = "\xFF"; // lengthSize = 4
        $spsNum = "\xE1";         // 1 SPS
        $spsLen = pack('n', strlen($this->sps));
        $ppsNum = "\x01";
        $ppsLen = pack('n', strlen($this->pps));
        $record = $configVersion . $profile . $compat . $level . $lengthMinusOne . $spsNum . $spsLen . $this->sps . $ppsNum . $ppsLen . $this->pps;

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
        $audioData = chr($audioHeader) . "\x00" . $this->audioSpecificConfig;
        $this->writeFLVTag(8, $audioData, 0);
        $this->hasWrittenAudioHeader = true;
    }

    private function getSoundRate(): int
    {
        switch ($this->audioSampleRate) {
            case 5500:  return 0;
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
        
        // 写入 onMetaData 标签
        $this->writeMetaData();
    }

    private function writeMetaData(): void
    {
        // 使用实际最大时间戳计算时长
        $duration = max($this->maxVideoDtsMs, $this->maxAudioDtsMs) / 1000;
        $metaData = [
            'duration' => $duration,
            'width' => $this->videoWidth ?: 720,
            'height' => $this->videoHeight ?: 480,
            'videocodecid' => 'avc1',
            'audiocodecid' => 'mp4a',
            'audiosamplerate' => $this->audioSampleRate ?: 44100,
            'audiochannels' => $this->audioChannels ?: 2,
            'framerate' => $this->videoFrameRate ?: 30.0,
        ];
        
        $data = $this->serializeAmf0($metaData);
        $onMetaData = $this->serializeAmf0('onMetaData') . $data;
        
        $this->writeFLVTag(18, $onMetaData, 0);
    }

    private function serializeAmf0($value): string
    {
        if (is_string($value)) {
            return "\x02" . pack('n', strlen($value)) . $value;
        } elseif (is_int($value)) {
            // 对于整数，先转换为float再按大端序写入
            return "\x00" . $this->packDoubleBE((float)$value);
        } elseif (is_float($value) || is_numeric($value)) {
            return "\x00" . $this->packDoubleBE((float)$value);
        } elseif (is_bool($value)) {
            return $value ? "\x01\x01" : "\x01\x00";
        } elseif (is_array($value)) {
            $result = "\x03"; // Object type
            foreach ($value as $key => $val) {
                if (!is_string($key)) continue;
                $result .= pack('n', strlen($key)) . $key;
                $result .= $this->serializeAmf0($val);
            }
            $result .= "\x00\x00\x09"; // End of object marker
            return $result;
        } elseif ($value === null) {
            return "\x05";
        }
        return '';
    }

    private function packDoubleBE(float $value): string
    {
        // AMF0要求大端序的IEEE 754双精度浮点数
        $packed = pack('d', $value);
        // PHP的pack('d')是小端序，需要转换为大端序
        return strrev($packed);
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
        $tag .= "\x00\x00\x00"; // StreamID

        fwrite($this->flvHandle, $tag);
        fwrite($this->flvHandle, $data);
        fwrite($this->flvHandle, pack('N', 11 + $dataSize));
    }
}