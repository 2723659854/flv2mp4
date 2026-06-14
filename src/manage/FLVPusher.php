<?php

namespace Xiaosongshu\Flv2mp4\manage;

/**
 * Production-grade FLV Pusher for RTMP Server
 *
 * Features:
 * - 按原始时间戳精确推流（伪直播）
 * - 自动断线重连
 * - 内存优化（流式读取，不加载整个文件）
 * - 实时进度上报
 * - 支持推流倍速（0.5x/1x/2x）
 * - 详细的日志输出
 * - 信号处理（优雅退出）
 *
 * Usage:
 *   php flv_pusher.php /path/to/video.flv [push_url] [speed]
 *   php flv_pusher.php test.flv http://127.0.0.1:8501/live/stream 1.0
 *   php flv_pusher.php test.flv http://127.0.0.1:8501/live/stream 2.0  # 2倍速
 *
 * @author yanglong
 * @version 1.0.1
 */

class FLVPusher {
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

    private $stats = [
        'tags_sent' => 0,
        'bytes_sent' => 0,
        'start_time' => 0,
        'last_report_time' => 0,
        'reconnect_count' => 0,
    ];

    /**
     * 修正版：先扫描所有 Tag 获取真实时长，再推送
     */
    private function pushStream($fileHandle) {
        // 1. 读取 FLV Header
        $flvHeader = fread($fileHandle, 9);
        if (strlen($flvHeader) != 9) {
            throw new \Exception("无效的 FLV 文件：无法读取 Header");
        }
        if (substr($flvHeader, 0, 3) !== 'FLV') {
            throw new \Exception("不是有效的 FLV 文件");
        }

        // 2. 先快速扫描所有 Tag，找到最大时间戳
        $scanHandle = fopen($this->filePath, 'rb');
        fseek($scanHandle, 9); // 跳过 header
        fread($scanHandle, 4); // 跳过 first PreviousTagSize

        $maxTimestamp = 0;
        while (!feof($scanHandle)) {
            $tagHeader = fread($scanHandle, 11);
            if (strlen($tagHeader) < 11) break;

            $dataSize = $this->readUInt24(substr($tagHeader, 1, 3));
            $timestamp = $this->readUInt24(substr($tagHeader, 4, 3));
            $timestampExt = ord($tagHeader[7]);
            $fullTimestamp = ($timestampExt << 24) | $timestamp;

            if ($fullTimestamp > $maxTimestamp) {
                $maxTimestamp = $fullTimestamp;
            }

            // 跳过 tag data + previous tag size
            fseek($scanHandle, $dataSize + 4, SEEK_CUR);
        }
        fclose($scanHandle);

        $realDuration = $maxTimestamp / 1000; // 转换为秒
        $this->log("FLV 真实时长: {$realDuration} 秒 (最大时间戳: {$maxTimestamp}ms)", 'info');

        // 回到文件开头，重新开始推送
        fseek($fileHandle, 9);
        fread($fileHandle, 4); // 跳过 first PreviousTagSize

        // 发送 FLV Header
        if ($this->useChunked) {
            $this->sendChunk($flvHeader);
        } else {
            $this->writeAll($flvHeader);
        }

        // 发送 PreviousTagSize 0
        $this->sendChunkOrWrite(pack('N', 0));

        // 3. 循环读取和发送 Tags（遇到 onMetaData 时修正 duration）
        $startRealTime = microtime(true);
        $tagCount = 0;
        $firstTimestamp = -1;

        while ($this->isRunning && !feof($fileHandle)) {
            $tagHeader = fread($fileHandle, 11);
            if (strlen($tagHeader) < 11) break;

            $tagType = ord($tagHeader[0]);
            $dataSize = $this->readUInt24(substr($tagHeader, 1, 3));
            $timestamp = $this->readUInt24(substr($tagHeader, 4, 3));
            $timestampExt = ord($tagHeader[7]);
            $fullTimestamp = ($timestampExt << 24) | $timestamp;

            if ($dataSize <= 0 || $dataSize > 10 * 1024 * 1024) break;

            $tagData = fread($fileHandle, $dataSize);
            $prevTagSizeBinary = fread($fileHandle, 4);

            // ★ 如果是 Script Tag (onMetaData)，修正 duration
            if ($tagType == 18 && $tagCount == 0) {
                $tagData = $this->fixMetaDataDuration($tagData, $realDuration);
                // 重新计算 dataSize
                $dataSize = strlen($tagData);
                // 重新构建 tagHeader
                $tagHeader = chr($tagType);
                $tagHeader .= chr(($dataSize >> 16) & 0xFF) . chr(($dataSize >> 8) & 0xFF) . chr($dataSize & 0xFF);
                $tagHeader .= chr(($timestamp >> 16) & 0xFF) . chr(($timestamp >> 8) & 0xFF) . chr($timestamp & 0xFF);
                $tagHeader .= chr($timestampExt);
                $tagHeader .= "\x00\x00\x00";
            }

            // 速率控制
            if ($firstTimestamp < 0) $firstTimestamp = $fullTimestamp;
            if ($this->speed > 0 && $fullTimestamp > 0) {
                $adjustedTimestamp = $fullTimestamp - $firstTimestamp;
                $elapsedReal = (microtime(true) - $startRealTime) * 1000;
                $targetTimestamp = $adjustedTimestamp / $this->speed;
                if ($targetTimestamp > $elapsedReal) {
                    $sleepMs = $targetTimestamp - $elapsedReal;
                    if ($sleepMs > 0 && $sleepMs < 5000) {
                        usleep((int)($sleepMs * 1000));
                    }
                }
            }

            // 发送 Tag
            $fullTag = $tagHeader . $tagData;
            if ($this->useChunked) {
                $this->sendChunk($fullTag);
                $this->sendChunk($prevTagSizeBinary);
            } else {
                $this->writeAll($fullTag);
                $this->writeAll($prevTagSizeBinary);
            }

            $this->stats['tags_sent']++;
            $this->stats['bytes_sent'] += strlen($fullTag) + 4;
            $tagCount++;

            if (!$this->isConnected()) {
                throw new \Exception("连接已断开");
            }
        }

        if ($this->useChunked) {
            fwrite($this->socket, "0\r\n\r\n");
        }
        return true;
    }

    /**
     * 修正 onMetaData 中的 duration
     */
    private function fixMetaDataDuration(string $tagData, float $realDuration): string
    {
        // 解析 AMF0: 第一个是 "onMetaData" 字符串
        // 格式: 0x02 + length(2) + "onMetaData" + ECMA Array(0x08) + ...

        $pos = 0;
        // 跳过 "onMetaData" 字符串
        if (ord($tagData[0]) == 0x02) {
            $strLen = unpack('n', substr($tagData, 1, 2))[1];
            $pos = 3 + $strLen;
        }

        if ($pos >= strlen($tagData)) return $tagData;

        // 应该是 ECMA Array (0x08)
        if (ord($tagData[$pos]) == 0x08) {
            $pos += 5; // 跳过 0x08 + arrayLength(4)

            // 遍历 key-value，找到 "duration"
            while ($pos + 3 < strlen($tagData)) {
                $keyLen = unpack('n', substr($tagData, $pos, 2))[1];
                $pos += 2;
                if ($pos + $keyLen > strlen($tagData)) break;

                $key = substr($tagData, $pos, $keyLen);
                $pos += $keyLen;

                if ($key === 'duration') {
                    // duration 是 Number 类型 (0x00) + 8 字节 double
                    if ($pos + 9 <= strlen($tagData) && ord($tagData[$pos]) == 0x00) {
                        $pos++; // 跳过 0x00
                        $newDuration = strrev(pack('d', $realDuration));
                        $tagData = substr_replace($tagData, $newDuration, $pos, 8);
                        $this->log("已修正 duration: {$realDuration} 秒", 'info');
                        return $tagData;
                    }
                    break;
                }

                // 跳过值
                $pos = $this->skipAmf0Value($tagData, $pos);
            }
        }

        return $tagData;
    }

    private function skipAmf0Value(string $data, int $pos): int
    {
        if ($pos >= strlen($data)) return $pos;

        $type = ord($data[$pos]);
        $pos++;

        switch ($type) {
            case 0x00: // Number
                $pos += 8;
                break;
            case 0x01: // Boolean
                $pos += 1;
                break;
            case 0x02: // String
                $len = unpack('n', substr($data, $pos, 2))[1];
                $pos += 2 + $len;
                break;
            case 0x03: // Object
                while ($pos + 3 <= strlen($data)) {
                    $keyLen = unpack('n', substr($data, $pos, 2))[1];
                    $pos += 2;
                    if ($keyLen == 0) break;
                    $pos += $keyLen;
                    $pos = $this->skipAmf0Value($data, $pos);
                }
                $pos += 1; // 0x09 结束标记
                break;
            case 0x08: // ECMA Array
                $pos += 4; // arrayLength
                while ($pos + 3 <= strlen($data)) {
                    $keyLen = unpack('n', substr($data, $pos, 2))[1];
                    $pos += 2;
                    if ($keyLen == 0) break;
                    $pos += $keyLen;
                    $pos = $this->skipAmf0Value($data, $pos);
                }
                $pos += 1;
                break;
            default:
                break;
        }
        return $pos;
    }

    private function sendChunkOrWrite($data) {
        if ($this->useChunked) {
            $this->sendChunk($data);
        } else {
            $this->writeAll($data);
        }
    }

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
        $this->log("FLV Pusher v1.0.1", 'info');
        $this->log("========================================", 'info');
        $this->log("文件: {$this->filePath}", 'info');
        $this->log("推流地址: {$this->pushUrl}", 'info');
        $this->log("推流速度: {$this->speed}x", 'info');
        $this->log("自动重连: " . ($this->autoReconnect ? '是' : '否'), 'info');
        $this->log("========================================", 'info');

        if (!file_exists($this->filePath)) {
            $this->log("文件不存在: {$this->filePath}", 'error');
            return false;
        }

        $fileSize = filesize($this->filePath);
        $this->log("文件大小: " . $this->formatBytes($fileSize), 'info');

        $result = $this->doPush();

        if ($this->statsEnabled) {
            $this->printFinalStats();
        }

        return $result;
    }

    private function doPush() {
        $this->stats['start_time'] = microtime(true);
        $this->stats['last_report_time'] = $this->stats['start_time'];

        $fileHandle = null;
        $retryCount = 0;

        while ($this->isRunning && $retryCount <= $this->maxRetries) {
            try {
                $fileHandle = fopen($this->filePath, 'rb');
                if (!$fileHandle) {
                    throw new \Exception("无法打开文件: {$this->filePath}");
                }

                if (!$this->connect()) {
                    throw new \Exception("连接服务器失败");
                }

                $result = $this->pushStream($fileHandle);
                fclose($fileHandle);

                if ($result === true) {
                    $this->log("推流完成！", 'success');
                    return true;
                }

            } catch (\Exception $e) {
                $this->log("推流错误: " . $e->getMessage(), 'error');
                if ($fileHandle) fclose($fileHandle);
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

    private function connect() {
        $urlParts = parse_url($this->pushUrl);
        $host = $urlParts['host'];
        $port = $urlParts['port'] ?? 8501;
        $path = $urlParts['path'] ?? '/';

        $this->log("连接 HTTP-FLV 服务器: {$host}:{$port}", 'info');

        $this->socket = @fsockopen($host, $port, $errno, $errstr, 10);
        if (!$this->socket) {
            $this->log("Socket 连接失败: {$errstr} ({$errno})", 'error');
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

            // 检测响应头结束
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
        $this->log("服务器响应: " . $firstLine, 'debug');

        if (strpos($firstLine, '200') === false) {
            $this->log("服务器返回非200状态: " . $firstLine, 'error');
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

    private function getTagTypeName($type) {
        switch ($type) {
            case 8: return '音频(Audio)';
            case 9: return '视频(Video)';
            case 18: return '脚本(Script)';
            default: return "未知({$type})";
        }
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

    private function readUInt24($bytes) {
        if (strlen($bytes) < 3) return 0;
        return (ord($bytes[0]) << 16) | (ord($bytes[1]) << 8) | ord($bytes[2]);
    }

    private function readInt32($bytes) {
        if (strlen($bytes) < 4) return 0;
        return (ord($bytes[0]) << 24) | (ord($bytes[1]) << 16) | (ord($bytes[2]) << 8) | ord($bytes[3]);
    }

    private function printProgress($currentTimestamp) {
        $elapsed = microtime(true) - $this->stats['start_time'];
        $speed = $elapsed > 0 ? $this->stats['tags_sent'] / $elapsed : 0;
        $bitrate = $elapsed > 0 ? ($this->stats['bytes_sent'] * 8 / $elapsed) / 1000 : 0;
        $progress = $this->totalBytes > 0 ?
            round(($this->stats['tags_sent'] / max(1, $this->stats['tags_sent'] + 100)) * 100, 1) : 0;

        $this->log(sprintf(
            "[进度] 已发送: %d tags | 时间戳: %s | 速率: %.1f tags/s | 码率: %.1f kbps",
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
        $this->log("总耗时: " . $this->formatTime($elapsed * 1000), 'info');
        $this->log("发送 Tag 数: " . number_format($this->stats['tags_sent']), 'info');
        $this->log("发送字节数: " . $this->formatBytes($this->stats['bytes_sent']), 'info');
        $this->log("平均速率: " . number_format($avgSpeed, 1) . " tags/s", 'info');
        $this->log("平均码率: " . number_format($totalBitrate, 1) . " kbps", 'info');
        $this->log("重连次数: " . $this->stats['reconnect_count'], 'info');
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

