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
    
    // MP4解析相关
    private $mp4Data;
    private $boxTree;
    private $videoTrack = null;
    private $audioTrack = null;
    
    // FLV写入相关
    private $hasWrittenHeader = false;
    private $hasWrittenVideoHeader = false;
    private $hasWrittenAudioHeader = false;
    private $lastVideoTimestamp = 0;
    private $lastAudioTimestamp = 0;
    
    // 视频相关
    private $sps = '';
    private $pps = '';
    private $avcConfig = '';
    
    // 音频相关
    private $audioSpecificConfig = '';
    private $audioSampleRate = 44100;
    private $audioChannels = 2;
    private $audioProfile = 2; // AAC LC

    /**
     * 构造函数
     * @param string $inputFile 输入的MP4文件路径
     * @param string $outputFile 输出的FLV文件路径
     */
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

    /**
     * 运行转换
     * @return bool
     */
    public function run(): bool
    {
        // 读取MP4文件
        $this->mp4Data = file_get_contents($this->inputFile);
        if (empty($this->mp4Data)) {
            throw new \RuntimeException("无法读取MP4文件");
        }
        
        echo "[DEBUG] MP4文件大小: " . strlen($this->mp4Data) . " bytes\n";
        
        // 解析MP4盒子结构
        $this->parseMp4Boxes();
        
        // 打开输出文件
        $this->flvHandle = fopen($this->outputFile, 'wb');
        if (!$this->flvHandle) {
            throw new \RuntimeException("无法创建输出文件: {$this->outputFile}");
        }
        
        try {
            // 解析轨道信息
            $this->parseTracks();
            
            echo "[DEBUG] 视频轨道: " . ($this->videoTrack ? "存在 (ID: {$this->videoTrack['id']})" : "不存在") . "\n";
            echo "[DEBUG] 音频轨道: " . ($this->audioTrack ? "存在 (ID: {$this->audioTrack['id']})" : "不存在") . "\n";
            echo "[DEBUG] SPS: " . (empty($this->sps) ? "空" : "存在 (" . strlen($this->sps) . " bytes)") . "\n";
            echo "[DEBUG] PPS: " . (empty($this->pps) ? "空" : "存在 (" . strlen($this->pps) . " bytes)") . "\n";
            echo "[DEBUG] AudioConfig: " . (empty($this->audioSpecificConfig) ? "空" : "存在 (" . strlen($this->audioSpecificConfig) . " bytes)") . "\n";
            
            // 写入FLV头部
            $this->writeFLVHeader();
            
            // 提取并写入媒体数据
            $this->extractAndWriteMediaData();
            
            return true;
        } finally {
            fclose($this->flvHandle);
        }
    }

    /**
     * 解析MP4盒子结构
     */
    private function parseMp4Boxes(): void
    {
        $this->boxTree = $this->parseBox($this->mp4Data, 0, strlen($this->mp4Data));
    }

    /**
     * 递归解析盒子
     * @param string $data
     * @param int $offset
     * @param int $end
     * @return array
     */
    private function parseBox(string $data, int $offset, int $end): array
    {
        $boxes = [];
        
        while ($offset + 8 <= $end) {
            $size = unpack('N', substr($data, $offset, 4))[1];
            $type = substr($data, $offset + 4, 4);
            
            // 处理扩展大小
            if ($size == 1) {
                if ($offset + 16 <= $end) {
                    $size = unpack('J', substr($data, $offset + 8, 8))[1];
                    $headerSize = 16;
                } else {
                    break;
                }
            } elseif ($size == 0) {
                $size = $end - $offset;
                $headerSize = 8;
            } else {
                $headerSize = 8;
            }
            
            $boxEnd = $offset + $size;
            if ($boxEnd > $end) {
                break;
            }
            
            $boxData = substr($data, $offset + $headerSize, $size - $headerSize);
            $box = [
                'type' => $type,
                'size' => $size,
                'offset' => $offset,
                'data' => $boxData,
                'children' => []
            ];
            
            // 递归解析子盒子
            if ($size > $headerSize) {
                $box['children'] = $this->parseBox($data, $offset + $headerSize, $boxEnd);
            }
            
            $boxes[] = $box;
            $offset = $boxEnd;
        }
        
        return $boxes;
    }

    /**
     * 查找指定类型的盒子
     * @param array $boxes
     * @param string $type
     * @return array|null
     */
    private function findBox(array $boxes, string $type): ?array
    {
        foreach ($boxes as $box) {
            if ($box['type'] === $type) {
                return $box;
            }
            if (!empty($box['children'])) {
                $found = $this->findBox($box['children'], $type);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
    }

    /**
     * 查找所有指定类型的盒子
     * @param array $boxes
     * @param string $type
     * @return array
     */
    private function findAllBoxes(array $boxes, string $type): array
    {
        $result = [];
        foreach ($boxes as $box) {
            if ($box['type'] === $type) {
                $result[] = $box;
            }
            if (!empty($box['children'])) {
                $result = array_merge($result, $this->findAllBoxes($box['children'], $type));
            }
        }
        return $result;
    }

    /**
     * 解析轨道信息
     */
    private function parseTracks(): void
    {
        // 查找moov盒子
        $moov = $this->findBox($this->boxTree, 'moov');
        if (!$moov) {
            throw new \RuntimeException("未找到moov盒子");
        }
        
        // 查找所有trak盒子
        $traks = $this->findAllBoxes([$moov], 'trak');
        
        foreach ($traks as $trak) {
            $this->parseTrack($trak);
        }
        
        if (!$this->videoTrack && !$this->audioTrack) {
            throw new \RuntimeException("未找到有效的视频或音频轨道");
        }
    }

    /**
     * 解析单个轨道
     * @param array $trak
     */
    private function parseTrack(array $trak): void
    {
        // 查找tkhd获取轨道信息
        $tkhd = $this->findBox([$trak], 'tkhd');
        if (!$tkhd) return;
        
        $tkhdData = $tkhd['data'];
        $trackId = unpack('N', substr($tkhdData, 12, 4))[1];
        
        // 查找mdia盒子
        $mdia = $this->findBox([$trak], 'mdia');
        if (!$mdia) return;
        
        // 查找hdlr获取轨道类型
        $hdlr = $this->findBox([$mdia], 'hdlr');
        if (!$hdlr) return;
        
        $hdlrData = $hdlr['data'];
        $handlerType = substr($hdlrData, 8, 4);
        
        // 查找stsd获取编解码信息
        $stbl = $this->findBox([$mdia], 'stbl');
        if (!$stbl) return;
        
        $stsd = $this->findBox([$stbl], 'stsd');
        if (!$stsd) return;
        
        $stsdData = $stsd['data'];
        $sampleEntrySize = unpack('N', substr($stsdData, 8, 4))[1];
        $sampleEntryType = substr($stsdData, 12, 4);
        
        // 获取mdhd中的timescale
        $mdhd = $this->findBox([$mdia], 'mdhd');
        $timescale = 90000; // 默认90kHz
        if ($mdhd) {
            $mdhdData = $mdhd['data'];
            $timescale = unpack('N', substr($mdhdData, 12, 4))[1];
        }
        
        if ($handlerType === 'vide' && $sampleEntryType === 'avc1') {
            // 视频轨道
            $this->videoTrack = [
                'id' => $trackId,
                'type' => 'video',
                'codec' => 'avc1',
                'timescale' => $timescale
            ];
            
            // 解析avcC盒子获取SPS/PPS
            echo "[DEBUG] stsdData长度: " . strlen($stsdData) . "\n";
            echo "[DEBUG] sampleEntrySize: " . $sampleEntrySize . "\n";
            
            // 在stsdData中查找avcC盒子
            // stsdData是stsd盒子的数据部分，结构: 4字节版本/标志 + 4字节entry count + 条目数据
            $offset = 8; // 跳过版本/标志(4字节)和entry count(4字节)
            while ($offset + 8 <= strlen($stsdData)) {
                $boxSize = unpack('N', substr($stsdData, $offset, 4))[1];
                $boxType = substr($stsdData, $offset + 4, 4);
                echo "[DEBUG] 找到盒子: {$boxType}, 大小: {$boxSize}, 偏移: {$offset}\n";
                
                if ($boxType === 'avc1') {
                    // 在avc1盒子内部搜索avcC盒子
                    $avc1Start = $offset;
                    $avc1End = $offset + $boxSize;
                    
                    // 从不同的偏移开始搜索
                    for ($searchOffset = $avc1Start + 8; $searchOffset + 8 <= $avc1End; $searchOffset++) {
                        $testType = substr($stsdData, $searchOffset + 4, 4);
                        if ($testType === 'avcC') {
                            $avcCSize = unpack('N', substr($stsdData, $searchOffset, 4))[1];
                            echo "[DEBUG] 在偏移 {$searchOffset} 找到avcC, 大小: {$avcCSize}\n";
                            
                            if ($searchOffset + $avcCSize <= $avc1End) {
                                $avcCData = substr($stsdData, $searchOffset + 8, $avcCSize - 8);
                                echo "[DEBUG] avcC数据长度: " . strlen($avcCData) . "\n";
                                $this->parseAvcC($avcCData);
                            }
                            break;
                        }
                    }
                    break;
                }
                
                $offset += $boxSize;
            }
            
        } elseif ($handlerType === 'soun' && $sampleEntryType === 'mp4a') {
            // 音频轨道
            $this->audioTrack = [
                'id' => $trackId,
                'type' => 'audio',
                'codec' => 'mp4a',
                'timescale' => $timescale
            ];
            
            // 解析esds盒子获取音频配置
            // 在stsdData中查找mp4a盒子然后搜索esds
            echo "[DEBUG] 查找esds...\n";
            $mp4aOffset = 8; // 跳过版本/标志和entry count
            while ($mp4aOffset + 8 <= strlen($stsdData)) {
                $mp4aSize = unpack('N', substr($stsdData, $mp4aOffset, 4))[1];
                $mp4aType = substr($stsdData, $mp4aOffset + 4, 4);
                
                if ($mp4aType === 'mp4a') {
                    // 在mp4a内部搜索esds
                    $mp4aEnd = $mp4aOffset + $mp4aSize;
                    for ($searchOffset = $mp4aOffset + 8; $searchOffset + 8 <= $mp4aEnd; $searchOffset++) {
                        $testType = substr($stsdData, $searchOffset + 4, 4);
                        if ($testType === 'esds') {
                            $esdsSize = unpack('N', substr($stsdData, $searchOffset, 4))[1];
                            echo "[DEBUG] 在偏移 {$searchOffset} 找到esds, 大小: {$esdsSize}\n";
                            
                            if ($searchOffset + $esdsSize <= $mp4aEnd) {
                                $esdsData = substr($stsdData, $searchOffset + 8, $esdsSize - 8);
                                echo "[DEBUG] esds数据长度: " . strlen($esdsData) . "\n";
                                $this->parseEsds($esdsData);
                            }
                            break 2; // 退出两层循环
                        }
                    }
                }
                
                $mp4aOffset += $mp4aSize;
            }
        }
    }

    /**
     * 解析avcC盒子
     * @param string $data
     */
    private function parseAvcC(string $data): void
    {
        if (strlen($data) < 8) return;
        
        $configurationVersion = ord($data[0]);
        $avcProfile = ord($data[1]);
        $profileCompatibility = ord($data[2]);
        $avcLevel = ord($data[3]);
        $lengthSizeMinusOne = ord($data[4]) & 0x03;
        
        $numOfSequenceParameterSets = ord($data[5]) & 0x1F;
        $offset = 6;
        
        // 提取SPS
        for ($i = 0; $i < $numOfSequenceParameterSets; $i++) {
            if ($offset + 2 > strlen($data)) break;
            $spsLength = unpack('n', substr($data, $offset, 2))[1];
            $offset += 2;
            
            if ($offset + $spsLength > strlen($data)) break;
            $this->sps = substr($data, $offset, $spsLength);
            $offset += $spsLength;
        }
        
        // 提取PPS
        if ($offset + 1 > strlen($data)) return;
        $numOfPictureParameterSets = ord($data[$offset]);
        $offset += 1;
        
        for ($i = 0; $i < $numOfPictureParameterSets; $i++) {
            if ($offset + 2 > strlen($data)) break;
            $ppsLength = unpack('n', substr($data, $offset, 2))[1];
            $offset += 2;
            
            if ($offset + $ppsLength > strlen($data)) break;
            $this->pps = substr($data, $offset, $ppsLength);
            $offset += $ppsLength;
        }
        
        // 保存AVC配置
        $this->avcConfig = $data;
    }

    /**
     * 解析esds盒子
     * @param string $data
     */
    private function parseEsds(string $data): void
    {
        // esds盒子结构比较复杂，这里简化处理
        // 跳过前面的固定头，查找AudioSpecificConfig
        if (strlen($data) < 20) return;
        
        // 调试：输出esds数据的十六进制
        echo "[DEBUG] esds原始数据(完整): ";
        for ($i = 0; $i < strlen($data); $i++) {
            echo sprintf("%02X ", ord($data[$i]));
        }
        echo "\n";
        
        // 查找05标记的位置
        $pos = strpos($data, "\x05");
        echo "[DEBUG] 05标记位置: " . ($pos !== false ? $pos : "未找到") . "\n";
        if ($pos !== false) {
            echo "[DEBUG] 位置pos前后的字节: ";
            for ($i = max(0, $pos - 2); $i <= min(strlen($data)-1, $pos + 5); $i++) {
                echo sprintf("%02X ", ord($data[$i]));
            }
            echo "\n";
        }
        
        // 查找05标记（AudioSpecificConfig）
        $pos = strpos($data, "\x05");
        if ($pos !== false && $pos + 2 < strlen($data)) {
            // esds使用变长编码，跳过0x80字节找到实际长度
            $lengthOffset = $pos + 1;
            while ($lengthOffset < strlen($data) && ord($data[$lengthOffset]) == 0x80) {
                $lengthOffset++;
            }
            
            if ($lengthOffset < strlen($data)) {
                $configLength = ord($data[$lengthOffset]);
                $configStart = $lengthOffset + 1;
                
                if ($configStart + $configLength <= strlen($data)) {
                    $this->audioSpecificConfig = substr($data, $configStart, $configLength);
                    echo "[DEBUG] AudioSpecificConfig长度: " . strlen($this->audioSpecificConfig) . ", 数据: ";
                    for ($i = 0; $i < strlen($this->audioSpecificConfig); $i++) {
                        echo sprintf("%02X ", ord($this->audioSpecificConfig[$i]));
                    }
                    echo "\n";
                    
                    // 解析音频参数
                    if (strlen($this->audioSpecificConfig) >= 2) {
                    $config = unpack('n', $this->audioSpecificConfig)[1];
                    $this->audioProfile = ($config >> 11) & 0x1F;
                    $freqIndex = ($config >> 7) & 0x0F;
                    $channelConfig = ($config >> 3) & 0x0F;
                    
                    // 频率索引映射
                    $freqMap = [
                        0 => 96000, 1 => 88200, 2 => 64000, 3 => 48000,
                        4 => 44100, 5 => 32000, 6 => 24000, 7 => 22050,
                        8 => 16000, 9 => 12000, 10 => 11025, 11 => 8000,
                        12 => 7350, 13 => 0, 14 => 0, 15 => 0
                    ];
                    
                    if (isset($freqMap[$freqIndex])) {
                        $this->audioSampleRate = $freqMap[$freqIndex];
                    }
                    
                        $this->audioChannels = $channelConfig;
                    }
                }
            }
        }
    }

    /**
     * 提取并写入媒体数据
     */
    private function extractAndWriteMediaData(): void
    {
        // 查找mdat盒子
        $mdat = $this->findBox($this->boxTree, 'mdat');
        if (!$mdat) {
            throw new \RuntimeException("未找到mdat盒子");
        }
        
        $mdatData = $mdat['data'];
        echo "[DEBUG] mdat数据长度: " . strlen($mdatData) . "\n";
        
        // 检查视频和音频轨道是否存在
        echo "[DEBUG] 视频轨道: " . (isset($this->videoTrack['id']) ? "存在 (ID: {$this->videoTrack['id']})" : "不存在") . "\n";
        echo "[DEBUG] 音频轨道: " . (isset($this->audioTrack['id']) ? "存在 (ID: {$this->audioTrack['id']})" : "不存在") . "\n";
        
        // 查找moof盒子（如果有）
        $moofs = $this->findAllBoxes($this->boxTree, 'moof');
        
        if (!empty($moofs)) {
            // 处理fMP4格式
            $this->processFragmentedMP4($moofs, $mdatData);
        } else {
            // 处理标准MP4格式
            $this->processStandardMP4($mdatData);
        }
    }

    /**
     * 处理标准MP4格式
     * @param string $mdatData
     */
    private function processStandardMP4(string $mdatData): void
    {
        // 查找moov盒子中的样本表
        $moov = $this->findBox($this->boxTree, 'moov');
        if (!$moov) return;
        
        $traks = $this->findAllBoxes([$moov], 'trak');
        
        foreach ($traks as $trak) {
            $mdia = $this->findBox([$trak], 'mdia');
            if (!$mdia) continue;
            
            $hdlr = $this->findBox([$mdia], 'hdlr');
            if (!$hdlr) continue;
            
            $hdlrData = $hdlr['data'];
            $handlerType = substr($hdlrData, 8, 4);
            
            $stbl = $this->findBox([$mdia], 'stbl');
            if (!$stbl) continue;
            
            // 获取样本信息
            $stsz = $this->findBox([$stbl], 'stsz');
            $stco = $this->findBox([$stbl], 'stco');
            $stts = $this->findBox([$stbl], 'stts');
            
            if (!$stsz || !$stco) continue;
            
            $this->processTrackSamples($handlerType, $stsz, $stco, $stts, $mdatData);
        }
    }

    /**
     * 处理轨道样本
     * @param string $handlerType
     * @param array $stsz
     * @param array $stco
     * @param array|null $stts
     * @param string $mdatData
     */
    private function processTrackSamples(string $handlerType, array $stsz, array $stco, ?array $stts, string $mdatData): void
    {
        $stszData = $stsz['data'];
        $stcoData = $stco['data'];
        
        echo "[DEBUG] processTrackSamples - handlerType: {$handlerType}\n";
        
        // 解析样本大小
        $sampleSizeFieldSize = unpack('N', substr($stszData, 0, 4))[1];
        $sampleCount = unpack('N', substr($stszData, 4, 4))[1];
        
        echo "[DEBUG] sampleSizeFieldSize: {$sampleSizeFieldSize}, sampleCount: {$sampleCount}\n";
        
        $sampleSizes = [];
        if ($sampleSizeFieldSize == 0) {
            // 每个样本有独立的大小
            for ($i = 0; $i < $sampleCount; $i++) {
                $offset = 12 + $i * 4;
                if ($offset + 4 <= strlen($stszData)) {
                    $sampleSizes[] = unpack('N', substr($stszData, $offset, 4))[1];
                }
            }
        } else {
            // 所有样本大小相同
            $sampleSizes = array_fill(0, $sampleCount, $sampleSizeFieldSize);
        }
        
        // 解析样本偏移
        $entryCount = unpack('N', substr($stcoData, 4, 4))[1];
        $chunkOffsets = [];
        for ($i = 0; $i < $entryCount; $i++) {
            $offset = 8 + $i * 4;
            if ($offset + 4 <= strlen($stcoData)) {
                $chunkOffsets[] = unpack('N', substr($stcoData, $offset, 4))[1];
            }
        }
        
        // 计算mdat起始偏移
        $mdat = $this->findBox($this->boxTree, 'mdat');
        $mdatOffset = $mdat['offset'] + 8; // 跳过盒子头
        
        // 解析stts获取时间增量
        $timeDeltas = [];
        if ($stts) {
            $sttsData = $stts['data'];
            $entryCount = unpack('N', substr($sttsData, 4, 4))[1];
            $offset = 8;
            
            for ($i = 0; $i < $entryCount; $i++) {
                if ($offset + 8 > strlen($sttsData)) break;
                $sampleCount = unpack('N', substr($sttsData, $offset, 4))[1];
                $sampleDelta = unpack('N', substr($sttsData, $offset + 4, 4))[1];
                $offset += 8;
                
                for ($j = 0; $j < $sampleCount; $j++) {
                    $timeDeltas[] = $sampleDelta;
                }
            }
        }
        
        // 写入样本数据
        $timestamp = 0;
        $sampleIndex = 0;
        
        foreach ($chunkOffsets as $chunkOffset) {
            $dataOffset = $chunkOffset - $mdatOffset;
            
            while ($sampleIndex < $sampleCount) {
                if (!isset($sampleSizes[$sampleIndex])) break;
                
                $sampleSize = $sampleSizes[$sampleIndex];
                if ($dataOffset + $sampleSize > strlen($mdatData)) break;
                
                $sampleData = substr($mdatData, $dataOffset, $sampleSize);
                
                if ($handlerType === 'vide') {
                    $this->writeVideoSample($sampleData, $timestamp);
                } elseif ($handlerType === 'soun') {
                    $this->writeAudioSample($sampleData, $timestamp);
                }
                
                $dataOffset += $sampleSize;
                
                // 使用stts中的时间增量
                if (isset($timeDeltas[$sampleIndex])) {
                    $timestamp += $timeDeltas[$sampleIndex];
                } else {
                    $timestamp += 40; // 默认40ms
                }
                
                $sampleIndex++;
            }
        }
    }

    /**
     * 处理fMP4格式
     * @param array $moofs
     * @param string $mdatData
     */
    private function processFragmentedMP4(array $moofs, string $mdatData): void
    {
        foreach ($moofs as $moof) {
            $traf = $this->findBox([$moof], 'traf');
            if (!$traf) continue;
            
            $tfhd = $this->findBox([$traf], 'tfhd');
            $trun = $this->findBox([$traf], 'trun');
            $tfdt = $this->findBox([$traf], 'tfdt');
            
            if (!$tfhd || !$trun) continue;
            
            $this->processFragment($tfhd, $trun, $tfdt, $mdatData);
        }
    }

    /**
     * 处理单个片段
     * @param array $tfhd
     * @param array $trun
     * @param array|null $tfdt
     * @param string $mdatData
     */
    private function processFragment(array $tfhd, array $trun, ?array $tfdt, string $mdatData): void
    {
        $tfhdData = $tfhd['data'];
        $trackId = unpack('N', substr($tfhdData, 4, 4))[1];
        
        $trunData = $trun['data'];
        $flags = unpack('N', substr($trunData, 0, 4))[1];
        $sampleCount = unpack('N', substr($trunData, 4, 4))[1];
        
        $dataOffset = 0;
        if ($flags & 0x01) {
            $dataOffset = unpack('N', substr($trunData, 8, 4))[1];
        }
        
        // 获取基准时间
        $baseTime = 0;
        if ($tfdt) {
            $tfdtData = $tfdt['data'];
            $baseTime = unpack('N', substr($tfdtData, 4, 4))[1];
        }
        
        // 解析样本
        $offset = 8;
        if ($flags & 0x01) $offset += 4;
        if ($flags & 0x04) $offset += 4;
        if ($flags & 0x08) $offset += 4;
        
        $timestamp = $baseTime;
        
        for ($i = 0; $i < $sampleCount; $i++) {
            $sampleDuration = 0;
            $sampleSize = 0;
            $sampleFlags = 0;
            $sampleCts = 0;
            
            if ($flags & 0x100) {
                $sampleDuration = unpack('N', substr($trunData, $offset, 4))[1];
                $offset += 4;
            }
            
            if ($flags & 0x200) {
                $sampleSize = unpack('N', substr($trunData, $offset, 4))[1];
                $offset += 4;
            }
            
            if ($flags & 0x400) {
                $sampleFlags = unpack('N', substr($trunData, $offset, 4))[1];
                $offset += 4;
            }
            
            if ($flags & 0x800) {
                $sampleCts = unpack('N', substr($trunData, $offset, 4))[1];
                $offset += 4;
            }
            
            if ($dataOffset + $sampleSize > strlen($mdatData)) break;
            
            $sampleData = substr($mdatData, $dataOffset, $sampleSize);
            
            // 判断轨道类型
            $isVideo = ($this->videoTrack && $this->videoTrack['id'] == $trackId);
            $isAudio = ($this->audioTrack && $this->audioTrack['id'] == $trackId);
            
            if ($isVideo) {
                $isKeyFrame = (($sampleFlags >> 16) & 0x01) == 0;
                $this->writeVideoSample($sampleData, $timestamp, $sampleCts, $isKeyFrame);
            } elseif ($isAudio) {
                $this->writeAudioSample($sampleData, $timestamp);
            }
            
            $dataOffset += $sampleSize;
            $timestamp += $sampleDuration;
        }
    }

    /**
     * 写入视频样本
     * @param string $sampleData
     * @param int $timestamp
     * @param int $cts
     * @param bool $isKeyFrame
     */
    private function writeVideoSample(string $sampleData, int $timestamp, int $cts = 0, bool $isKeyFrame = false): void
    {
        // 写入AVC序列头
        if (!$this->hasWrittenVideoHeader && !empty($this->sps) && !empty($this->pps)) {
            $this->writeAVCSequenceHeader();
        }
        
        // 获取视频轨道的timescale
        $timescale = $this->videoTrack['timescale'] ?? 90000;
        
        // 转换时间戳（MP4使用timescale，FLV使用毫秒）
        $flvTimestamp = (int)($timestamp * 1000 / $timescale);
        $flvCts = (int)($cts * 1000 / $timescale);
        
        // 确保时间戳不递减
        $flvTimestamp = max($flvTimestamp, $this->lastVideoTimestamp);
        $this->lastVideoTimestamp = $flvTimestamp;
        
        // 将AVCC格式转换为AnnexB格式
        $nalus = $this->avccToAnnexb($sampleData);
        
        // 检查是否为关键帧
        if (!$isKeyFrame) {
            foreach ($nalus as $nalu) {
                if (strlen($nalu) > 0 && (ord($nalu[0]) & 0x1F) === 5) {
                    $isKeyFrame = true;
                    break;
                }
            }
        }
        
        // 转换回AVCC格式用于FLV
        $avccData = $this->annexbToAvcc($nalus);
        
        $codecId = 7; // AVC
        $frameType = $isKeyFrame ? 1 : 2;
        $frameType = ($frameType << 4) | $codecId;
        
        $ctsBytes = chr(($flvCts >> 16) & 0xFF) . chr(($flvCts >> 8) & 0xFF) . chr($flvCts & 0xFF);
        $videoData = chr($frameType) . "\x01" . $ctsBytes . $avccData;
        
        $this->writeFLVTag(9, $videoData, $flvTimestamp);
    }

    /**
     * 写入音频样本
     * @param string $sampleData
     * @param int $timestamp
     */
    private function writeAudioSample(string $sampleData, int $timestamp): void
    {
        // 写入AAC序列头
        if (!$this->hasWrittenAudioHeader && !empty($this->audioSpecificConfig)) {
            $this->writeAACSequenceHeader();
        }
        
        // 获取音频轨道的timescale
        $timescale = $this->audioTrack['timescale'] ?? 90000;
        
        // 转换时间戳（MP4使用timescale，FLV使用毫秒）
        $flvTimestamp = (int)($timestamp * 1000 / $timescale);
        
        // 确保时间戳不递减
        $flvTimestamp = max($flvTimestamp, $this->lastAudioTimestamp);
        $this->lastAudioTimestamp = $flvTimestamp;
        
        $soundFormat = 10; // AAC
        $soundRate = $this->getSoundRate();
        $soundSize = 1; // 16-bit
        $soundType = $this->audioChannels == 2 ? 1 : 0;
        
        $audioHeader = (($soundFormat << 4) | ($soundRate << 2) | ($soundSize << 1) | $soundType);
        $audioData = chr($audioHeader) . "\x01" . $sampleData;
        
        $this->writeFLVTag(8, $audioData, $flvTimestamp);
    }

    /**
     * 获取音频采样率对应的FLV编码
     * @return int
     */
    private function getSoundRate(): int
    {
        switch ($this->audioSampleRate) {
            case 5500: return 0;
            case 11025: return 1;
            case 22050: return 2;
            case 44100: return 3;
            case 48000: return 4;
            default: return 3; // 默认44100
        }
    }

    /**
     * AVCC格式转AnnexB格式
     * @param string $avccData
     * @return array
     */
    private function avccToAnnexb(string $avccData): array
    {
        $nalus = [];
        $offset = 0;
        $len = strlen($avccData);
        
        while ($offset + 4 <= $len) {
            $naluLength = unpack('N', substr($avccData, $offset, 4))[1];
            $offset += 4;
            
            if ($offset + $naluLength > $len) break;
            
            $nalus[] = substr($avccData, $offset, $naluLength);
            $offset += $naluLength;
        }
        
        return $nalus;
    }

    /**
     * AnnexB格式转AVCC格式
     * @param array $nalus
     * @return string
     */
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

    /**
     * 写入FLV头部
     */
    private function writeFLVHeader(): void
    {
        if ($this->hasWrittenHeader) return;
        
        $flags = 0;
        if ($this->videoTrack) $flags |= 0x01;
        if ($this->audioTrack) $flags |= 0x04;
        
        $header = "FLV\x01" . chr($flags) . "\x00\x00\x00\x09";
        fwrite($this->flvHandle, $header);
        fwrite($this->flvHandle, "\x00\x00\x00\x00");
        
        $this->hasWrittenHeader = true;
    }

    /**
     * 写入AVC序列头
     */
    private function writeAVCSequenceHeader(): void
    {
        if (empty($this->sps) || empty($this->pps) || $this->hasWrittenVideoHeader) return;
        
        // 构建FLV格式的AVC配置数据
        $avcConfig = "\x01";
        $avcConfig .= substr($this->sps, 1, 3);
        $avcConfig .= "\xFF\xE1";
        $avcConfig .= pack('n', strlen($this->sps));
        $avcConfig .= $this->sps;
        $avcConfig .= "\x01";
        $avcConfig .= pack('n', strlen($this->pps));
        $avcConfig .= $this->pps;
        
        $videoData = "\x17\x00\x00\x00\x00" . $avcConfig;
        $this->writeFLVTag(9, $videoData, 0);
        $this->hasWrittenVideoHeader = true;
    }

    /**
     * 写入AAC序列头
     */
    private function writeAACSequenceHeader(): void
    {
        if (empty($this->audioSpecificConfig) || $this->hasWrittenAudioHeader) return;
        
        $audioData = "\xAF\x00" . $this->audioSpecificConfig;
        $this->writeFLVTag(8, $audioData, 0);
        $this->hasWrittenAudioHeader = true;
    }

    /**
     * 写入FLV标签
     * @param int $tagType
     * @param string $data
     * @param int $timestamp
     */
    private function writeFLVTag(int $tagType, string $data, int $timestamp): void
    {
        $dataSize = strlen($data);
        
        // 确保时间戳在有效范围内
        $timestamp = $timestamp & 0xFFFFFF;
        $timestampExtended = ($timestamp >> 24) & 0xFF;
        $timestampLower = $timestamp & 0xFFFFFF;
        
        $tagHeader = chr($tagType);
        $tagHeader .= chr(($dataSize >> 16) & 0xFF);
        $tagHeader .= chr(($dataSize >> 8) & 0xFF);
        $tagHeader .= chr($dataSize & 0xFF);
        $tagHeader .= chr(($timestampLower >> 16) & 0xFF);
        $tagHeader .= chr(($timestampLower >> 8) & 0xFF);
        $tagHeader .= chr($timestampLower & 0xFF);
        $tagHeader .= chr($timestampExtended);
        $tagHeader .= "\x00\x00\x00";
        
        fwrite($this->flvHandle, $tagHeader);
        fwrite($this->flvHandle, $data);
        fwrite($this->flvHandle, pack('N', 11 + $dataSize));
    }
}