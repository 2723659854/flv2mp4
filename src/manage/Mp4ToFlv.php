<?php

namespace Xiaosongshu\Flv2mp4\Manage;

/**
 * @purpose MP4转FLV工具
 * @author yanglong
 * @time 2026年6月4日
 * 修复版：正确处理样本表、时间戳、交错写入
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

    // 视频相关
    private $sps = '';
    private $pps = '';
    private $avcConfig = '';

    // 音频相关
    private $audioSpecificConfig = '';
    private $audioSampleRate = 44100;
    private $audioChannels = 2;
    private $audioProfile = 2; // AAC LC

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

        echo "[DEBUG] MP4文件大小: " . strlen($this->mp4Data) . " bytes\n";

        $this->parseMp4Boxes();

        $this->flvHandle = fopen($this->outputFile, 'wb');
        if (!$this->flvHandle) {
            throw new \RuntimeException("无法创建输出文件: {$this->outputFile}");
        }

        try {
            $this->parseTracks();

            echo "[DEBUG] 视频轨道: " . ($this->videoTrack ? "存在 (ID: {$this->videoTrack['id']})" : "不存在") . "\n";
            echo "[DEBUG] 音频轨道: " . ($this->audioTrack ? "存在 (ID: {$this->audioTrack['id']})" : "不存在") . "\n";
            echo "[DEBUG] SPS: " . (empty($this->sps) ? "空" : "存在 (" . strlen($this->sps) . " bytes)") . "\n";
            echo "[DEBUG] PPS: " . (empty($this->pps) ? "空" : "存在 (" . strlen($this->pps) . " bytes)") . "\n";
            echo "[DEBUG] AudioConfig: " . (empty($this->audioSpecificConfig) ? "空" : "存在 (" . strlen($this->audioSpecificConfig) . " bytes)") . "\n";

            $this->writeFLVHeader();
            $this->extractAndWriteMediaData();

            return true;
        } finally {
            fclose($this->flvHandle);
        }
    }

    /* ========== Box 解析 ========== */
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
            $box = [
                'type' => $type,
                'size' => $size,
                'offset' => $offset,
                'data' => $boxData,
                'children' => []
            ];

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

        $stbl = $this->findBox([$mdia], 'stbl');
        if (!$stbl) return;

        $stsd = $this->findBox([$stbl], 'stsd');
        if (!$stsd) return;

        // timescale
        $mdhd = $this->findBox([$mdia], 'mdhd');
        $timescale = 90000;
        if ($mdhd) {
            $timescale = unpack('N', substr($mdhd['data'], 12, 4))[1];
        }

        $stsdData = $stsd['data'];
        // 跳过 version(4) + entry_count(4) = 8 bytes
        $pos = 8;
        while ($pos + 8 <= strlen($stsdData)) {
            $entrySize = unpack('N', substr($stsdData, $pos, 4))[1];
            $entryType = substr($stsdData, $pos + 4, 4);

            if ($handlerType === 'vide' && $entryType === 'avc1') {
                $this->videoTrack = [
                    'id' => $trackId,
                    'type' => 'video',
                    'codec' => 'avc1',
                    'timescale' => $timescale
                ];
                // 在avc1盒子内部搜索avcC
                $avc1Data = substr($stsdData, $pos, $entrySize);
                $this->parseAvcCFromBox($avc1Data);
                break;
            }
            elseif ($handlerType === 'soun' && $entryType === 'mp4a') {
                $this->audioTrack = [
                    'id' => $trackId,
                    'type' => 'audio',
                    'codec' => 'mp4a',
                    'timescale' => $timescale
                ];
                $mp4aData = substr($stsdData, $pos, $entrySize);
                $this->parseEsdsFromBox($mp4aData);
                break;
            }
            $pos += $entrySize;
        }
    }

    private function parseAvcCFromBox(string $data): void
    {
        // 搜索avcC盒子
        $offset = 0;
        $len = strlen($data);
        while ($offset + 8 <= $len) {
            $boxSize = unpack('N', substr($data, $offset, 4))[1];
            $boxType = substr($data, $offset + 4, 4);
            if ($boxType === 'avcC') {
                $avcC = substr($data, $offset + 8, $boxSize - 8);
                $this->parseAvcC($avcC);
                break;
            }
            $offset += $boxSize;
        }
    }

    private function parseAvcC(string $data): void
    {
        if (strlen($data) < 8) return;
        $this->avcConfig = $data;

        $numSps = ord($data[5]) & 0x1F;
        $offset = 6;
        $allSps = '';
        for ($i = 0; $i < $numSps; $i++) {
            if ($offset + 2 > strlen($data)) break;
            $spsLength = unpack('n', substr($data, $offset, 2))[1];
            $offset += 2;
            if ($offset + $spsLength > strlen($data)) break;
            $sps = substr($data, $offset, $spsLength);
            $offset += $spsLength;
            $allSps .= $sps;
            if ($i == 0) $this->sps = $sps; // 仅保存第一个SPS
        }
        $numPps = ord($data[$offset]); $offset++;
        $allPps = '';
        for ($i = 0; $i < $numPps; $i++) {
            if ($offset + 2 > strlen($data)) break;
            $ppsLength = unpack('n', substr($data, $offset, 2))[1];
            $offset += 2;
            if ($offset + $ppsLength > strlen($data)) break;
            $pps = substr($data, $offset, $ppsLength);
            $offset += $ppsLength;
            $allPps .= $pps;
            if ($i == 0) $this->pps = $pps;
        }
    }

    private function parseEsdsFromBox(string $data): void
    {
        $offset = 0;
        $len = strlen($data);
        while ($offset + 8 <= $len) {
            $boxSize = unpack('N', substr($data, $offset, 4))[1];
            $boxType = substr($data, $offset + 4, 4);
            if ($boxType === 'esds') {
                $esdsData = substr($data, $offset + 8, $boxSize - 8);
                $this->parseEsds($esdsData);
                break;
            }
            $offset += $boxSize;
        }
    }

    private function parseEsds(string $data): void
    {
        // ESDS 结构复杂，这里简易解析：跳过 4 字节 version/flags，寻找 0x05 描述符
        if (strlen($data) < 20) return;
        $pos = 4;
        // 搜索 0x05 (DecSpecificInfoTag) 并读取长度
        while ($pos < strlen($data)) {
            $tag = ord($data[$pos]);
            if ($tag == 0x05) {
                $pos++;
                // 读取变长长度
                $length = 0;
                while ($pos < strlen($data)) {
                    $byte = ord($data[$pos]);
                    $length = ($length << 7) | ($byte & 0x7F);
                    $pos++;
                    if (($byte & 0x80) == 0) break;
                }
                if ($pos + $length <= strlen($data)) {
                    $this->audioSpecificConfig = substr($data, $pos, $length);
                    // 解析参数
                    if ($length >= 2) {
                        $config = unpack('n', $this->audioSpecificConfig)[1];
                        $this->audioProfile = ($config >> 11) & 0x1F;
                        $freqIndex = ($config >> 7) & 0x0F;
                        $this->audioChannels = ($config >> 3) & 0x0F;
                        $freqMap = [96000,88200,64000,48000,44100,32000,24000,22050,16000,12000,11025,8000,7350];
                        $this->audioSampleRate = $freqMap[$freqIndex] ?? 44100;
                    }
                }
                break;
            } else {
                // 跳过其他描述符
                $pos++;
                if ($pos >= strlen($data)) break;
                $length = 0;
                while ($pos < strlen($data)) {
                    $byte = ord($data[$pos]);
                    $length = ($length << 7) | ($byte & 0x7F);
                    $pos++;
                    if (($byte & 0x80) == 0) break;
                }
                $pos += $length;
            }
        }
    }

    /* ========== 媒体数据提取与写入 ========== */
    private function extractAndWriteMediaData(): void
    {
        $mdat = $this->findBox($this->boxTree, 'mdat');
        if (!$mdat) throw new \RuntimeException("未找到mdat盒子");

        $moov = $this->findBox($this->boxTree, 'moov');
        if (!$moov) return;

        $videoSamples = [];
        $audioSamples = [];

        // 分别处理视频和音频轨道
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
            if ($handlerType === 'vide') {
                $videoSamples = $samples;
            } elseif ($handlerType === 'soun') {
                $audioSamples = $samples;
            }
        }

        // 按时间戳交错写入
        $allSamples = array_merge($videoSamples, $audioSamples);
        usort($allSamples, function($a, $b) {
            return $a['dts'] - $b['dts'];
        });

        foreach ($allSamples as $sample) {
            if ($sample['type'] === 'video') {
                $this->writeVideoSample($sample['data'], $sample['dts'], $sample['cts'] ?? 0, $sample['keyframe']);
            } else {
                $this->writeAudioSample($sample['data'], $sample['dts']);
            }
        }
    }

    private function extractSamplesFromStbl(array $stbl, string $mdatData, int $mdatOffset, string $handlerType): array
    {
        $stsz = $this->findBox([$stbl], 'stsz');
        $stco = $this->findBox([$stbl], 'stco');
        $stsc = $this->findBox([$stbl], 'stsc');
        $stts = $this->findBox([$stbl], 'stts');
        $ctts = $this->findBox([$stbl], 'ctts');

        if (!$stsz || !$stco || !$stsc || !$stts) return [];

        // 解析 STSZ (样本大小)
        $stszData = $stsz['data'];
        $version = unpack('N', substr($stszData, 0, 4))[1];       // flags+version 打包
        $sampleSize = unpack('N', substr($stszData, 4, 4))[1];    // 全0则每个样本单独指定
        $sampleCount = unpack('N', substr($stszData, 8, 4))[1];
        $sampleSizes = [];
        if ($sampleSize == 0) {
            for ($i = 0; $i < $sampleCount; $i++) {
                $sampleSizes[] = unpack('N', substr($stszData, 12 + $i * 4, 4))[1];
            }
        } else {
            $sampleSizes = array_fill(0, $sampleCount, $sampleSize);
        }

        // 解析 STSC (chunk 到 sample 映射)
        $stscData = $stsc['data'];
        $stscEntries = unpack('N', substr($stscData, 4, 4))[1];
        $chunkMap = []; // chunk编号 -> samples_per_chunk
        $descIdx = [];
        $pos = 8;
        for ($i = 0; $i < $stscEntries; $i++) {
            $firstChunk = unpack('N', substr($stscData, $pos, 4))[1];
            $samplesPerChunk = unpack('N', substr($stscData, $pos+4, 4))[1];
            $sampleDescIndex = unpack('N', substr($stscData, $pos+8, 4))[1];
            $pos += 12;
            $chunkMap[$firstChunk] = ['samples' => $samplesPerChunk, 'desc' => $sampleDescIndex];
        }

        // 解析 STCO (chunk offsets)
        $stcoData = $stco['data'];
        $chunkCount = unpack('N', substr($stcoData, 4, 4))[1];
        $chunkOffsets = [];
        for ($i = 0; $i < $chunkCount; $i++) {
            $chunkOffsets[] = unpack('N', substr($stcoData, 8 + $i * 4, 4))[1];
        }

        // 构建 sample 偏移序列
        $sampleOffsets = [];
        $currentSample = 0;
        $currentChunkSamples = 0;
        $currentChunkSamplesPer = 0;
        $lastChunkIdx = 0;
        foreach ($chunkOffsets as $chunkIdx => $chunkOffset) {
            $chunkNum = $chunkIdx + 1;
            // 查找该 chunk 的 samples_per_chunk
            $samplesPerChunk = 0;
            foreach ($chunkMap as $firstChunk => $map) {
                if ($chunkNum >= $firstChunk) {
                    $samplesPerChunk = $map['samples'];
                }
            }
            for ($j = 0; $j < $samplesPerChunk; $j++) {
                if ($currentSample >= count($sampleSizes)) break;
                $sampleOffsets[$currentSample] = $chunkOffset + array_sum(array_slice($sampleSizes, $currentSample - $j, $j));
                $currentSample++;
            }
        }
        // 修正偏移计算方式
        $sampleOffsets = [];
        $sampleIdx = 0;
        foreach ($chunkOffsets as $chunkIdx => $chunkOffset) {
            $chunkNum = $chunkIdx + 1;
            $samplesPerChunk = 0;
            foreach ($chunkMap as $firstChunk => $map) {
                if ($chunkNum >= $firstChunk) {
                    $samplesPerChunk = $map['samples'];
                }
            }
            for ($j = 0; $j < $samplesPerChunk && $sampleIdx < count($sampleSizes); $j++) {
                $offset = $chunkOffset;
                for ($k = 0; $k < $j; $k++) {
                    $offset += $sampleSizes[$sampleIdx - $j + $k];
                }
                $sampleOffsets[$sampleIdx] = $offset;
                $sampleIdx++;
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

        // 解析 CTTS (composition offset) - 视频所需
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

        // 判断是否关键帧 (stss)
        $stss = $this->findBox([$stbl], 'stss');
        $keyframeSamples = [];
        if ($stss) {
            $stssData = $stss['data'];
            $stssEntries = unpack('N', substr($stssData, 4, 4))[1];
            for ($i = 0; $i < $stssEntries; $i++) {
                $keyframeSamples[] = unpack('N', substr($stssData, 8 + $i * 4, 4))[1] - 1; // 转为0-based
            }
        }
        $keyFrameSet = array_flip($keyframeSamples);

        // 构建样本数组
        $samples = [];
        $dts = 0;
        for ($i = 0; $i < count($sampleSizes) && $i < count($sampleOffsets); $i++) {
            $offset = $sampleOffsets[$i] - ($mdatOffset + 8); // mdat数据偏移
            if ($offset < 0 || $offset + $sampleSizes[$i] > strlen($mdatData)) continue;
            $rawData = substr($mdatData, $offset, $sampleSizes[$i]);

            $cts = $ctOffsets[$i] ?? 0;
            $pts = $dts + $cts;
            $isKeyframe = isset($keyFrameSet[$i]);

            $samples[] = [
                'type' => $handlerType === 'vide' ? 'video' : 'audio',
                'data' => $rawData,
                'dts' => $dts,
                'cts' => $cts,
                'pts' => $pts,
                'keyframe' => $isKeyframe,
                'size' => $sampleSizes[$i]
            ];
            $dts += $timeDeltas[$i] ?? 0;
        }
        return $samples;
    }

    /* ========== FLV 写入 ========== */
    private function writeVideoSample(string $data, int $dts, int $cts = 0, bool $isKeyFrame = false): void
    {
        if (!$this->hasWrittenVideoHeader && !empty($this->sps)) {
            $this->writeAVCSequenceHeader();
        }

        $timescale = $this->videoTrack['timescale'] ?? 90000;
        // 将 timescale 时间戳转换为毫秒
        $flvTimestamp = (int)round($dts * 1000 / $timescale);
        $flvCts = (int)round($cts * 1000 / $timescale);

        // 保证时间戳不减
        static $lastVideoTs = -1;
        if ($flvTimestamp <= $lastVideoTs) $flvTimestamp = $lastVideoTs + 1;
        $lastVideoTs = $flvTimestamp;

        // 数据不变，直接写入
        $nalus = $this->avccToAnnexb($data);
        $avccData = $this->annexbToAvcc($nalus);

        $codecId = 7;
        $frameType = $isKeyFrame ? 1 : 2;
        $videoTag = chr(($frameType << 4) | $codecId) . "\x01" . pack('N', $flvCts);
        $videoTag = chr(($frameType << 4) | $codecId) . "\x01" . chr(($flvCts>>16)&0xFF) . chr(($flvCts>>8)&0xFF) . chr($flvCts&0xFF) . $avccData;
        $this->writeFLVTag(9, $videoTag, $flvTimestamp);
    }

    private function writeAudioSample(string $data, int $dts): void
    {
        if (!$this->hasWrittenAudioHeader && !empty($this->audioSpecificConfig)) {
            $this->writeAACSequenceHeader();
        }

        $timescale = $this->audioTrack['timescale'] ?? 90000;
        $flvTimestamp = (int)round($dts * 1000 / $timescale);

        static $lastAudioTs = -1;
        if ($flvTimestamp <= $lastAudioTs) $flvTimestamp = $lastAudioTs + 1;
        $lastAudioTs = $flvTimestamp;

        $soundFormat = 10; // AAC
        $soundRate = $this->getSoundRate();
        $soundSize = 1; // 16-bit
        $soundType = ($this->audioChannels == 2) ? 1 : 0;
        $audioHeader = ($soundFormat << 4) | ($soundRate << 2) | ($soundSize << 1) | $soundType;
        $audioData = chr($audioHeader) . "\x01" . $data; // AACPacketType=1 (raw)

        $this->writeFLVTag(8, $audioData, $flvTimestamp);
    }

    private function writeAVCSequenceHeader(): void
    {
        // 构建完整的AVCDecoderConfigurationRecord (参考FLV规范)
        $record = "\x01"; // configurationVersion
        $record .= $this->sps[1] ?? "\x42"; // AVCProfileIndication
        $record .= $this->sps[2] ?? "\x00"; // profile_compatibility
        $record .= $this->sps[3] ?? "\x1F"; // AVCLevelIndication
        $record .= "\xFF"; // lengthSizeMinusOne (4字节) + 0xE1 (numOfSequenceParameterSets)
        $record .= "\xE1"; // SPS count = 1 (为了简单，只写第一个SPS)
        $record .= pack('n', strlen($this->sps));
        $record .= $this->sps;
        $record .= "\x01"; // numOfPictureParameterSets
        $record .= pack('n', strlen($this->pps));
        $record .= $this->pps;

        $videoData = "\x17\x00\x00\x00\x00" . $record;
        $this->writeFLVTag(9, $videoData, 0);
        $this->hasWrittenVideoHeader = true;
    }

    private function writeAACSequenceHeader(): void
    {
        $soundFormat = 10; // AAC
        $soundRate = $this->getSoundRate();
        $soundSize = 1;
        $soundType = ($this->audioChannels == 2) ? 1 : 0;
        $audioHeader = ($soundFormat << 4) | ($soundRate << 2) | ($soundSize << 1) | $soundType;
        $audioData = chr($audioHeader) . "\x00" . $this->audioSpecificConfig; // AACPacketType=0
        $this->writeFLVTag(8, $audioData, 0);
        $this->hasWrittenAudioHeader = true;
    }

    private function getSoundRate(): int
    {
        $rates = [5500=>0, 11025=>1, 22050=>2, 44100=>3, 48000=>4];
        foreach ($rates as $rate => $code) {
            if ($this->audioSampleRate == $rate) return $code;
        }
        return 3; // 默认44100
    }

    private function avccToAnnexb(string $data): array
    {
        $nalus = [];
        $offset = 0; $len = strlen($data);
        while ($offset + 4 <= $len) {
            $naluLength = unpack('N', substr($data, $offset, 4))[1];
            $offset += 4;
            if ($offset + $naluLength > $len) break;
            $nalus[] = substr($data, $offset, $naluLength);
            $offset += $naluLength;
        }
        return $nalus;
    }

    private function annexbToAvcc(array $nalus): string
    {
        $result = '';
        foreach ($nalus as $nalu) {
            if (strlen($nalu) > 0) {
                $result .= pack('N', strlen($nalu)) . $nalu;
            }
        }
        return $result;
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
    }

    private function writeFLVTag(int $tagType, string $data, int $timestamp): void
    {
        $dataSize = strlen($data);
        $timestamp &= 0xFFFFFF;
        $tsExt = 0;
        $tsLow = $timestamp & 0x00FFFFFF;
        $tsHigh = ($timestamp >> 24) & 0xFF;
        // 实际FLV时间戳只有24位低部分，扩展位放在ExtendedTimestamp字段（这里是单字节）
        // 标准做法：将完整32位时间戳拆分为低24位和高8位，高8位放入timestampExtended。
        $tsLow = $timestamp & 0xFFFFFF;
        $tsHigh = ($timestamp >> 24) & 0xFF;

        $tag = chr($tagType);
        $tag .= chr(($dataSize >> 16) & 0xFF) . chr(($dataSize >> 8) & 0xFF) . chr($dataSize & 0xFF);
        $tag .= chr(($tsLow >> 16) & 0xFF) . chr(($tsLow >> 8) & 0xFF) . chr($tsLow & 0xFF);
        $tag .= chr($tsHigh);
        $tag .= "\x00\x00\x00"; // StreamID (always 0)

        fwrite($this->flvHandle, $tag);
        fwrite($this->flvHandle, $data);
        fwrite($this->flvHandle, pack('N', 11 + $dataSize));
    }
}