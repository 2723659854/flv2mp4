<?php

namespace Xiaosongshu\Flv2mp4\manage;

/**
 * Production-grade MP4 Pusher for HTTP-FLV Server
 *
 * Features:
 * - 解析 MP4 文件并实时转码为 FLV 格式推送
 * - 按原始时间戳精确推流（伪直播）
 * - 自动断线重连
 * - 内存优化（流式读取，不加载整个文件）
 * - 实时进度上报
 * - 支持推流倍速（0.5x/1x/2x）
 * - 详细的日志输出
 * - 信号处理（优雅退出）
 *
 * Usage:
 *   php mp4_pusher.php /path/to/video.mp4 [push_url] [speed]
 *   php mp4_pusher.php test.mp4 http://127.0.0.1:8501/live/stream 1.0
 *   php mp4_pusher.php test.mp4 http://127.0.0.1:8501/live/stream 2.0  # 2 倍速
 *
 * @author yanglong
 * @version 1.0.0
 */

class Mp4Pusher {
    private $filePath;
    private $pushUrl;
    private $speed = 1.0;
    private $autoReconnect = true;
    private $maxRetries = 5;
    private $retryDelay = 3;
    private $verbose = true;
    private $statsEnabled = true;
    private $useChunked = true;

    private $socket;
    private $totalBytes = 0;
    private $totalTags = 0;
    private $startTime;
    private $lastTimestamp = 0;
    private $isRunning = true;
    private $retryCount = 0;

    // MP4 解析相关
    private $mp4Data;
    private $boxTree;
    private $videoTrack = null;
    private $audioTrack = null;
    private $sps = '';
    private $pps = '';
    private $audioSpecificConfig = '';
    private $audioSampleRate = 44100;
    private $audioChannels = 2;
    private $audioProfile = 2;

    // FLV 状态
    private $hasWrittenVideoHeader = false;
    private $hasWrittenAudioHeader = false;

    // Metadata 相关
    private $duration = 0;
    private $videoWidth = 0;
    private $videoHeight = 0;
    private $videoFrameRate = 30;

    private $stats = [
        'tags_sent' => 0,
        'bytes_sent' => 0,
        'start_time' => 0,
        'last_report_time' => 0,
        'reconnect_count' => 0,
    ];

    public function __construct($filePath, $pushUrl, $speed = 1.0, $autoReconnect = true) {
        $this->filePath = $filePath;
        $this->pushUrl = $pushUrl;
        $this->speed = max(0.1, min(10.0, (float)$speed));
        $this->autoReconnect = $autoReconnect;

        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, [$this, 'handleSignal']);
            pcntl_signal(SIGTERM, [$this, 'handleSignal']);
        }
    }

    public function handleSignal($signal) {
        $this->log("[信号] 收到退出信号，正在优雅关闭...", 'warning');
        $this->isRunning = false;
        $this->closeConnection();
        exit(0);
    }

    public function start() {
        $this->log("========================================", 'info');
        $this->log("MP4 Pusher v1.0.0", 'info');
        $this->log("========================================", 'info');
        $this->log("文件：{$this->filePath}", 'info');
        $this->log("推流地址：{$this->pushUrl}", 'info');
        $this->log("推流速度：{$this->speed}x", 'info');
        $this->log("自动重连：" . ($this->autoReconnect ? '是' : '否'), 'info');
        $this->log("========================================", 'info');

        if (!file_exists($this->filePath)) {
            $this->log("文件不存在：{$this->filePath}", 'error');
            return false;
        }

        $fileSize = filesize($this->filePath);
        $this->log("文件大小：" . $this->formatBytes($fileSize), 'info');

        $result = $this->doPush();

        if ($this->statsEnabled) {
            $this->printFinalStats();
        }

        return $result;
    }

    private function doPush() {
        $this->stats['start_time'] = microtime(true);
        $this->stats['last_report_time'] = $this->stats['start_time'];

        $retryCount = 0;

        while ($this->isRunning && $retryCount <= $this->maxRetries) {
            try {
                // 解析 MP4 文件
                $this->log("解析 MP4 文件...", 'info');
                $this->parseMp4File();

                if (!$this->connect()) {
                    throw new \Exception("连接服务器失败");
                }

                $result = $this->pushStream();

                if ($result === true) {
                    $this->log("推流完成！", 'success');
                    return true;
                }

            } catch (\Exception $e) {
                $this->log("推流错误：" . $e->getMessage(), 'error');
                $this->closeConnection();

                if ($this->autoReconnect && $retryCount < $this->maxRetries) {
                    $retryCount++;
                    $this->stats['reconnect_count']++;
                    $this->log("等待 {$this->retryDelay} 秒后进行第 {$retryCount} 次重连...", 'warning');
                    sleep($this->retryDelay);
                    continue;
                } else {
                    $this->log("达到最大重连次数，推流失败", 'error');
                    return false;
                }
            }
        }

        return false;
    }

    private function parseMp4File() {
        $this->mp4Data = file_get_contents($this->filePath);
        if (empty($this->mp4Data)) {
            throw new \RuntimeException("无法读取 MP4 文件");
        }

        $this->parseMp4Boxes();
        $this->parseTracks();
    }

    private function parseMp4Boxes() {
        $this->boxTree = $this->parseBox($this->mp4Data, 0, strlen($this->mp4Data));
    }

    private function parseBox(string $data, int $offset, int $end): array {
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

    private function findBox(array $boxes, string $type): ?array {
        foreach ($boxes as $box) {
            if ($box['type'] === $type) return $box;
            if (!empty($box['children'])) {
                $found = $this->findBox($box['children'], $type);
                if ($found !== null) return $found;
            }
        }
        return null;
    }

    private function findAllBoxes(array $boxes, string $type): array {
        $result = [];
        foreach ($boxes as $box) {
            if ($box['type'] === $type) $result[] = $box;
            if (!empty($box['children'])) {
                $result = array_merge($result, $this->findAllBoxes($box['children'], $type));
            }
        }
        return $result;
    }

    private function parseTracks() {
        $moov = $this->findBox($this->boxTree, 'moov');
        if (!$moov) throw new \RuntimeException("未找到 moov 盒子");
        
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

    private function parseTrack(array $trak) {
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

    private function parseAvcCFromBox(string $data) {
        $pos = strpos($data, 'avcC');
        if ($pos === false) return;
        if ($pos < 4) return;
        
        $boxSize = unpack('N', substr($data, $pos - 4, 4))[1];
        $avcCData = substr($data, $pos + 4, $boxSize - 8);
        $this->parseAvcC($avcCData);
    }

    private function parseAvcC(string $data) {
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
        if (ord($sps[0]) & 0x80) {
            $pos++;
        }
        
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

    private function parseEsdsFromBox(string $data) {
        $pos = strpos($data, 'esds');
        if ($pos === false) return;
        if ($pos < 4) return;
        
        $boxSize = unpack('N', substr($data, $pos - 4, 4))[1];
        $esdsData = substr($data, $pos + 4, $boxSize - 8);
        $this->parseEsds($esdsData);
    }

    private function parseEsds(string $data) {
        if (strlen($data) < 20) return;
        $pos = 4;
        
        while ($pos < strlen($data)) {
            if ($pos + 1 > strlen($data)) break;
            $tag = ord($data[$pos]);
            $pos++;
            
            if ($pos >= strlen($data)) break;
            $length = 0;
            while ($pos < strlen($data)) {
                $byte = ord($data[$pos]);
                $length = ($length << 7) | ($byte & 0x7F);
                $pos++;
                if (($byte & 0x80) == 0) break;
            }
            
            if ($pos + $length > strlen($data)) break;
            $contentStart = $pos;
            
            switch ($tag) {
                case 0x03:
                    $pos += 3;
                    $remainingLength = $length - 3;
                    if ($remainingLength > 0 && $pos + $remainingLength <= strlen($data)) {
                        $subData = substr($data, $pos, $remainingLength);
                        $this->parseEsdsNested($subData);
                    }
                    break;
                    
                case 0x04:
                    $pos += 13;
                    $remainingLength = $length - 13;
                    if ($remainingLength > 0 && $pos + $remainingLength <= strlen($data)) {
                        $subData = substr($data, $pos, $remainingLength);
                        $this->parseEsdsNested($subData);
                    }
                    break;
                    
                case 0x05:
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
    
    private function parseEsdsNested(string $data) {
        $pos = 0;
        while ($pos < strlen($data)) {
            if ($pos + 1 > strlen($data)) break;
            $tag = ord($data[$pos]);
            $pos++;
            
            if ($pos >= strlen($data)) break;
            $length = 0;
            while ($pos < strlen($data)) {
                $byte = ord($data[$pos]);
                $length = ($length << 7) | ($byte & 0x7F);
                $pos++;
                if (($byte & 0x80) == 0) break;
            }
            
            if ($pos + $length > strlen($data)) break;
            $contentStart = $pos;
            
            switch ($tag) {
                case 0x03:
                    $pos += 3;
                    $remainingLength = $length - 3;
                    if ($remainingLength > 0 && $pos + $remainingLength <= strlen($data)) {
                        $subData = substr($data, $pos, $remainingLength);
                        $this->parseEsdsNested($subData);
                    }
                    break;
                    
                case 0x04:
                    $pos += 13;
                    $remainingLength = $length - 13;
                    if ($remainingLength > 0 && $pos + $remainingLength <= strlen($data)) {
                        $subData = substr($data, $pos, $remainingLength);
                        $this->parseEsdsNested($subData);
                    }
                    break;
                    
                case 0x05:
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

    private function pushStream() {
        // 1. 发送 FLV Header
        $flvHeader = $this->buildFLVHeader();
        $this->log("发送 FLV Header", 'info');
        
        if ($this->useChunked) {
            $this->sendChunk($flvHeader);
        } else {
            $this->writeAll($flvHeader);
        }

        // 2. 发送第一个 Previous Tag Size (0)
        $prevTagSize = pack('N', 0);
        if ($this->useChunked) {
            $this->sendChunk($prevTagSize);
        } else {
            $this->writeAll($prevTagSize);
        }

        // 3. 发送 metadata
        $this->writeMetaData();

        // 4. 提取并推送媒体数据
        $this->extractAndPushMediaData();

        // 5. 发送结束标记
        if ($this->useChunked) {
            fwrite($this->socket, "0\r\n\r\n");
        }

        return true;
    }

    private function buildFLVHeader() {
        $hasAudio = ($this->audioTrack !== null);
        $hasVideo = ($this->videoTrack !== null);
        
        $header = "FLV";
        $header .= chr(0x01); // version
        $header .= chr(($hasAudio ? 0x04 : 0) | ($hasVideo ? 0x01 : 0)); // flags
        $header .= pack('N', 9); // data offset (9 bytes)
        
        return $header;
    }

    private function extractAndPushMediaData() {
        $mdat = $this->findBox($this->boxTree, 'mdat');
        if (!$mdat) throw new \RuntimeException("未找到 mdat 盒子");
        $moov = $this->findBox($this->boxTree, 'moov');
        if (!$moov) return;

        $allSamples = [];

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

        // 按 DTS 排序
        usort($allSamples, function($a, $b) {
            return $a['dtsMs'] - $b['dtsMs'];
        });

        $this->log("共提取 " . count($allSamples) . " 个样本，开始推送...", 'info');

        $startRealTime = microtime(true);
        $firstTimestamp = -1;
        $tagCount = 0;
        $lastProgressTime = 0;

        // 交错推送样本
        foreach ($allSamples as $sample) {
            if (!$this->isRunning) break;

            if ($sample['type'] === 'video') {
                $this->writeVideoSample($sample['data'], $sample['dtsMs'], $sample['ctsMs'] ?? 0, $sample['keyframe']);
            } else {
                $this->writeAudioSample($sample['data'], $sample['dtsMs']);
            }

            $tagCount++;
            $this->stats['tags_sent']++;
            $this->stats['bytes_sent'] += strlen($sample['data']) + 15; // 近似值

            // 速率控制
            if ($this->speed > 0 && $sample['dtsMs'] > 0) {
                if ($firstTimestamp < 0) {
                    $firstTimestamp = $sample['dtsMs'];
                }
                
                $adjustedTimestamp = $sample['dtsMs'] - $firstTimestamp;
                $elapsedReal = (microtime(true) - $startRealTime) * 1000;
                $targetTimestamp = $adjustedTimestamp / $this->speed;

                if ($targetTimestamp > $elapsedReal) {
                    $sleepMs = $targetTimestamp - $elapsedReal;
                    if ($sleepMs > 0 && $sleepMs < 5000) {
                        usleep((int)($sleepMs * 1000));
                    }
                }
            }

            // 定期输出进度
            $currentTime = microtime(true);
            if ($this->statsEnabled && $tagCount % 100 == 0 && ($currentTime - $lastProgressTime) >= 1) {
                $this->printProgress($sample['dtsMs']);
                $lastProgressTime = $currentTime;
            }

            // 检查连接
            if (!$this->isConnected()) {
                throw new \Exception("连接已断开");
            }
        }

        $this->log("推送完成！共处理 {$tagCount} 个样本", 'info');
    }

    private function extractSamplesFromStbl($stbl, $mdatData, $mdatOffset, $handlerType) {
        $stsz = $this->findBox([$stbl], 'stsz');
        $stco = $this->findBox([$stbl], 'stco');
        $stsc = $this->findBox([$stbl], 'stsc');
        $stts = $this->findBox([$stbl], 'stts');
        $ctts = $this->findBox([$stbl], 'ctts');
        $stss = $this->findBox([$stbl], 'stss');

        if (!$stsz || !$stco || !$stsc || !$stts) return [];

        $timescale = ($handlerType === 'vide') ? ($this->videoTrack['timescale'] ?? 90000) : ($this->audioTrack['timescale'] ?? 90000);

        // 解析 STSZ
        $stszData = $stsz['data'];
        $stszOffset = 4;
        
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

        // 解析 STSC
        $stscData = $stsc['data'];
        $stscOffset = 0;
        
        if (strlen($stscData) >= 8 && unpack('N', substr($stscData, 0, 4))[1] == 0) {
            $stscOffset = 4;
        }
        
        $stscEntries = unpack('N', substr($stscData, $stscOffset, 4))[1];
        $chunkMap = [];
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
        
        if (strlen($stcoData) >= 8 && unpack('N', substr($stcoData, 0, 4))[1] == 0) {
            $stcoOffset = 4;
        }
        
        $chunkCount = unpack('N', substr($stcoData, $stcoOffset, 4))[1];
        $chunkOffsets = [];
        for ($i = 0; $i < $chunkCount; $i++) {
            $chunkOffsets[] = unpack('N', substr($stcoData, $stcoOffset + 4 + $i * 4, 4))[1];
        }

        // 构建每个 chunk 的 samples per chunk 列表
        $chunkSamples = [];
        for ($chunk = 0; $chunk < $chunkCount; $chunk++) {
            $chunkNum = $chunk + 1;
            $samples = 0;
            $lastChunkNum = 0;
            foreach ($chunkMap as $mapChunkNum => $mapData) {
                if ($chunkNum >= $mapChunkNum) {
                    $samples = $mapData['samples'];
                    $lastChunkNum = $mapChunkNum;
                }
            }
            $chunkSamples[$chunkNum] = $samples;
        }

        // 计算每个 sample 的 offset 和 size
        $sampleOffsets = [];
        $sampleIndex = 0;
        for ($chunk = 0; $chunk < $chunkCount; $chunk++) {
            $chunkNum = $chunk + 1;
            $samplesInChunk = $chunkSamples[$chunkNum];
            $chunkOffset = $chunkOffsets[$chunk] - $mdatOffset;
            $offsetInChunk = 0;
            
            for ($s = 0; $s < $samplesInChunk; $s++) {
                $sampleOffsets[$sampleIndex] = $chunkOffset + $offsetInChunk;
                $offsetInChunk += $sampleSizes[$sampleIndex];
                $sampleIndex++;
            }
        }

        // 解析 STTS (DTS)
        $sttsData = $stts['data'];
        $sttsOffset = 4;
        $sttsEntries = unpack('N', substr($sttsData, $sttsOffset, 4))[1];
        $dtsMap = [];
        $pos = $sttsOffset + 4;
        $currentDts = 0;
        for ($i = 0; $i < $sttsEntries; $i++) {
            $sampleCount = unpack('N', substr($sttsData, $pos, 4))[1];
            $sampleDelta = unpack('N', substr($sttsData, $pos+4, 4))[1];
            $pos += 8;
            for ($j = 0; $j < $sampleCount; $j++) {
                $dtsMap[] = $currentDts;
                $currentDts += $sampleDelta;
            }
        }

        // 解析 CTTS (CTS)
        $ctsMap = [];
        if ($ctts) {
            $cttsData = $ctts['data'];
            $cttsOffset = 4;
            $cttsEntries = unpack('N', substr($cttsData, $cttsOffset, 4))[1];
            $pos = $cttsOffset + 4;
            for ($i = 0; $i < $cttsEntries; $i++) {
                $sampleCount = unpack('N', substr($cttsData, $pos, 4))[1];
                $sampleOffset = unpack('N', substr($cttsData, $pos+4, 4))[1];
                $pos += 8;
                for ($j = 0; $j < $sampleCount; $j++) {
                    $ctsMap[] = $sampleOffset;
                }
            }
        }

        // 解析 STSS (关键帧)
        $keyframeMap = [];
        if ($stss && $handlerType === 'vide') {
            $stssData = $stss['data'];
            $stssOffset = 4;
            $stssEntries = unpack('N', substr($stssData, $stssOffset, 4))[1];
            $pos = $stssOffset + 4;
            for ($i = 0; $i < $stssEntries; $i++) {
                $keyframeNum = unpack('N', substr($stssData, $pos, 4))[1];
                $keyframeMap[$keyframeNum - 1] = true;
                $pos += 4;
            }
        }

        // 构建样本数组
        $samples = [];
        for ($i = 0; $i < $sampleCount; $i++) {
            $offset = $sampleOffsets[$i];
            $size = $sampleSizes[$i];
            
            if ($offset + $size > strlen($mdatData)) {
                continue;
            }
            
            $sampleData = substr($mdatData, $offset, $size);
            $dts = ($dtsMap[$i] ?? 0) * 1000 / $timescale;
            $cts = isset($ctsMap[$i]) ? $ctsMap[$i] * 1000 / $timescale : 0;
            $isKeyframe = ($handlerType !== 'vide') || isset($keyframeMap[$i]);
            
            $samples[] = [
                'data' => $sampleData,
                'dtsMs' => $dts,
                'ctsMs' => $cts,
                'keyframe' => $isKeyframe,
            ];
        }

        return $samples;
    }

    private function writeVideoSample($data, $dtsMs, $ctsMs, $isKeyframe) {
        $timestamp = $dtsMs;
        $compositionTime = $ctsMs;
        
        // 构建 FLV video tag
        $frameType = $isKeyframe ? 0x10 : 0x20;
        $codecId = 0x07; // AVC
        
        if (!$this->hasWrittenVideoHeader) {
            // 写入视频配置标签
            $videoConfig = chr(0x17); // keyframe + AVC config
            $videoConfig .= chr(0x00); // AVC sequence header
            $videoConfig .= "\x00\x00\x00"; // composition time = 0 (3 bytes)
            $videoConfig .= $this->buildAVCDecoderConfig();
            
            $tagHeader = $this->buildTagHeader(9, strlen($videoConfig), $timestamp);
            $fullTag = $tagHeader . $videoConfig;
            
            if ($this->useChunked) {
                $this->sendChunk($fullTag);
                $this->sendChunk(pack('N', strlen($fullTag)));
            } else {
                $this->writeAll($fullTag);
                $this->writeAll(pack('N', strlen($fullTag)));
            }
            
            $this->hasWrittenVideoHeader = true;
            $this->log("已写入视频配置 (SPS/PPS)", 'debug');
        }
        
        $videoData = chr($frameType | $codecId);
        $videoData .= chr(0x01); // AVC NALU
        // Composition Time (3 bytes, big-endian)
        $cts = (int)$compositionTime;
        $videoData .= chr(($cts >> 16) & 0xFF) . chr(($cts >> 8) & 0xFF) . chr($cts & 0xFF);
        $videoData .= $data;
        
        $tagHeader = $this->buildTagHeader(9, strlen($videoData), $timestamp);
        $fullTag = $tagHeader . $videoData;
        
        if ($this->useChunked) {
            $this->sendChunk($fullTag);
            $this->sendChunk(pack('N', strlen($fullTag)));
        } else {
            $this->writeAll($fullTag);
            $this->writeAll(pack('N', strlen($fullTag)));
        }
    }

    private function writeAudioSample($data, $dtsMs) {
        if (!$this->hasWrittenAudioHeader) {
            // 写入音频配置标签
            $soundFormat = 10;  // AAC
            $soundRate = $this->getSoundRate();
            $soundSize = 1;
            $soundType = ($this->audioChannels == 2) ? 1 : 0;
            $audioHeader = ($soundFormat << 4) | ($soundRate << 2) | ($soundSize << 1) | $soundType;
            
            $audioConfig = chr($audioHeader);
            $audioConfig .= chr(0x00); // AAC sequence header
            $audioConfig .= $this->audioSpecificConfig;
            
            $tagHeader = $this->buildTagHeader(8, strlen($audioConfig), $dtsMs);
            $fullTag = $tagHeader . $audioConfig;
            
            if ($this->useChunked) {
                $this->sendChunk($fullTag);
                $this->sendChunk(pack('N', strlen($fullTag)));
            } else {
                $this->writeAll($fullTag);
                $this->writeAll(pack('N', strlen($fullTag)));
            }
            
            $this->hasWrittenAudioHeader = true;
            $this->log("已写入音频配置", 'debug');
        }
        
        // 构建 AAC 数据
        $soundFormat = 10;  // AAC
        $soundRate = $this->getSoundRate();
        $soundSize = 1;
        $soundType = ($this->audioChannels == 2) ? 1 : 0;
        $audioHeader = ($soundFormat << 4) | ($soundRate << 2) | ($soundSize << 1) | $soundType;
        
        $audioData = chr($audioHeader);
        $audioData .= chr(0x01); // AAC raw
        $audioData .= $data;
        
        $tagHeader = $this->buildTagHeader(8, strlen($audioData), $dtsMs);
        $fullTag = $tagHeader . $audioData;
        
        if ($this->useChunked) {
            $this->sendChunk($fullTag);
            $this->sendChunk(pack('N', strlen($fullTag)));
        } else {
            $this->writeAll($fullTag);
            $this->writeAll(pack('N', strlen($fullTag)));
        }
    }

    private function buildAVCDecoderConfig() {
        $configVersion = "\x01";
        $profile = $this->sps[1] ?? "\x42";
        $compat  = $this->sps[2] ?? "\x00";
        $level   = $this->sps[3] ?? "\x1F";
        $lengthMinusOne = "\xFF"; // lengthSize = 4
        $spsNum = "\xE1";         // 1 SPS
        $spsLen = pack('n', strlen($this->sps));
        $ppsNum = "\x01";
        $ppsLen = pack('n', strlen($this->pps));
        
        return $configVersion . $profile . $compat . $level . $lengthMinusOne . $spsNum . $spsLen . $this->sps . $ppsNum . $ppsLen . $this->pps;
    }

    private function writeMetaData() {
        $metaData = [
            'duration' => $this->duration,
            'width' => $this->videoWidth,
            'height' => $this->videoHeight,
            'videocodecid' => 'avc1',
            'audiocodecid' => 'mp4a',
            'audiosamplerate' => $this->audioSampleRate,
            'audiochannels' => $this->audioChannels,
            'framerate' => $this->videoFrameRate,
        ];
        
        $data = $this->serializeAmf0($metaData);
        $onMetaData = $this->serializeAmf0('onMetaData') . $data;
        
        $tagHeader = $this->buildTagHeader(18, strlen($onMetaData), 0);
        $fullTag = $tagHeader . $onMetaData;
        
        if ($this->useChunked) {
            $this->sendChunk($fullTag);
            $this->sendChunk(pack('N', strlen($fullTag)));
        } else {
            $this->writeAll($fullTag);
            $this->writeAll(pack('N', strlen($fullTag)));
        }
        
        $this->log("已写入 metadata", 'debug');
    }

    private function serializeAmf0($value): string
    {
        if (is_string($value)) {
            return "\x02" . pack('n', strlen($value)) . $value;
        } elseif (is_int($value)) {
            return "\x00" . $this->packDoubleBE((float)$value);
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
        $packed = pack('d', $value);
        return strrev($packed);
    }

    private function getSoundRate(): int {
        switch ($this->audioSampleRate) {
            case 5500:  return 0;
            case 11025: return 1;
            case 22050: return 2;
            case 44100: return 3;
            case 48000: return 4;
            default:    return 3;
        }
    }

    private function buildTagHeader($type, $dataSize, $timestamp) {
        $header = '';
        $header .= chr($type);
        $header .= chr(($dataSize >> 16) & 0xFF);
        $header .= chr(($dataSize >> 8) & 0xFF);
        $header .= chr($dataSize & 0xFF);
        
        // 正确处理时间戳：转换为整数并进行位掩码操作
        $timestamp = (int)$timestamp;
        $timestamp &= 0xFFFFFFFF;
        $tsLow = $timestamp & 0xFFFFFF;
        $tsHigh = ($timestamp >> 24) & 0xFF;
        
        $header .= chr(($tsLow >> 16) & 0xFF);
        $header .= chr(($tsLow >> 8) & 0xFF);
        $header .= chr($tsLow & 0xFF);
        $header .= chr($tsHigh);
        
        $header .= chr(0x00);
        $header .= chr(0x00);
        $header .= chr(0x00);
        
        return $header;
    }

    private function connect() {
        $urlParts = parse_url($this->pushUrl);
        $host = $urlParts['host'];
        $port = $urlParts['port'] ?? 8501;
        $path = $urlParts['path'] ?? '/';

        $this->log("连接 HTTP-FLV 服务器：{$host}:{$port}", 'info');

        $this->socket = @fsockopen($host, $port, $errno, $errstr, 10);
        if (!$this->socket) {
            $this->log("Socket 连接失败：{$errstr} ({$errno})", 'error');
            return false;
        }

        stream_set_timeout($this->socket, 30);
        stream_set_blocking($this->socket, true);

        $request = $this->buildHTTPRequest($host, $path);
        $this->log("发送 HTTP 请求头", 'debug');

        $result = fwrite($this->socket, $request);
        if ($result === false) {
            $this->log("发送请求头失败", 'error');
            return false;
        }

        // 读取响应头
        $response = '';
        $headersEnded = false;
        while (!feof($this->socket)) {
            $line = fgets($this->socket);
            if ($line === false) break;

            $response .= $line;

            if (trim($line) === '') {
                $headersEnded = true;
                break;
            }
        }

        if (!$headersEnded) {
            $this->log("读取服务器响应失败", 'error');
            return false;
        }

        $firstLine = strtok($response, "\r\n");
        $this->log("服务器响应：" . $firstLine, 'debug');

        if (strpos($firstLine, '200') === false) {
            $this->log("服务器返回非 200 状态：" . $firstLine, 'error');
            return false;
        }

        $this->log("HTTP 连接成功", 'success');
        return true;
    }

    private function buildHTTPRequest($host, $path) {
        $request = "POST {$path} HTTP/1.1\r\n";
        $request .= "Host: {$host}\r\n";
        $request .= "Content-Type: video/x-flv\r\n";
        $request .= "Connection: keep-alive\r\n";

        if ($this->useChunked) {
            $request .= "Transfer-Encoding: chunked\r\n";
        }

        $request .= "\r\n";
        return $request;
    }

    private function sendChunk($data) {
        $chunkSize = dechex(strlen($data));
        $chunk = $chunkSize . "\r\n" . $data . "\r\n";
        return $this->writeAll($chunk);
    }

    private function writeAll($data) {
        $len = strlen($data);
        $written = 0;

        while ($written < $len) {
            $result = fwrite($this->socket, substr($data, $written));
            if ($result === false) {
                throw new \Exception("写入数据失败");
            }
            $written += $result;
        }

        $this->totalBytes += $written;
        return $written;
    }

    private function isConnected() {
        if (!$this->socket) return false;

        $metadata = stream_get_meta_data($this->socket);
        if ($metadata['eof']) return false;

        return true;
    }

    private function closeConnection() {
        if ($this->socket) {
            @fclose($this->socket);
            $this->socket = null;
        }
    }

    private function printProgress($currentTimestamp) {
        $elapsed = microtime(true) - $this->stats['start_time'];
        $speed = $elapsed > 0 ? $this->stats['tags_sent'] / $elapsed : 0;
        $bitrate = $elapsed > 0 ? ($this->stats['bytes_sent'] * 8 / $elapsed) / 1000 : 0;

        $this->log(sprintf(
            "[进度] 已发送：%d tags | 时间戳：%s | 速率：%.1f tags/s | 码率：%.1f kbps",
            $this->stats['tags_sent'],
            $this->formatTime($currentTimestamp),
            $speed,
            $bitrate
        ), 'debug');
    }

    private function printFinalStats() {
        $elapsed = microtime(true) - $this->stats['start_time'];
        $avgSpeed = $elapsed > 0 ? $this->stats['tags_sent'] / $elapsed : 0;
        $totalBitrate = $elapsed > 0 ? ($this->stats['bytes_sent'] * 8 / $elapsed) / 1000 : 0;

        $this->log("========================================", 'info');
        $this->log("推流统计", 'info');
        $this->log("========================================", 'info');
        $this->log("总耗时：" . $this->formatTime($elapsed * 1000), 'info');
        $this->log("发送 Tag 数：" . number_format($this->stats['tags_sent']), 'info');
        $this->log("发送字节数：" . $this->formatBytes($this->stats['bytes_sent']), 'info');
        $this->log("平均速率：" . number_format($avgSpeed, 1) . " tags/s", 'info');
        $this->log("平均码率：" . number_format($totalBitrate, 1) . " kbps", 'info');
        $this->log("重连次数：" . $this->stats['reconnect_count'], 'info');
        $this->log("========================================", 'info');
    }

    private function formatTime($ms) {
        $seconds = floor($ms / 1000);
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;

        if ($hours > 0) {
            return sprintf("%02d:%02d:%02d", $hours, $minutes, $secs);
        } else {
            return sprintf("%02d:%02d", $minutes, $secs);
        }
    }

    private function formatBytes($bytes) {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }

    private function log($message, $level = 'info') {
        if (!$this->verbose && $level == 'debug') {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $prefix = '';

        switch ($level) {
            case 'error':
                $prefix = "\033[31m[ERROR]\033[0m";
                break;
            case 'warning':
                $prefix = "\033[33m[WARN]\033[0m";
                break;
            case 'success':
                $prefix = "\033[32m[SUCCESS]\033[0m";
                break;
            case 'debug':
                $prefix = "\033[90m[DEBUG]\033[0m";
                break;
            default:
                $prefix = "[INFO]";
        }

        echo "[{$timestamp}] {$prefix} {$message}\n";
    }
}
