<?php

namespace Xiaosongshu\Flv2mp4\Flv;

/**
 * @purpose php版本flv直播拉流客户端
 * @author yanglong
 */
class FlvPullerClient
{
    protected string $pullUrl;
    protected string $outputFlv;
    protected int $duration;
    protected bool $autoReconnect;
    protected bool $isRunning = true;
    protected $fileHandle = null;
    protected $socket = null;
    protected bool $isWebSocket = false;
    protected int $retryCount = 0;
    protected int $maxRetries = 5;
    protected int $retryDelay = 3;
    protected bool $chunked = false;
    protected string $chunkBuffer = '';
    protected ?int $startTime = null;
    protected ?int $baseTimestamp = null;
    protected int $bytesWritten = 0;
    protected int $lastStatsTime = 0;

    protected array $stats = [
        'tags_received' => 0,
        'audio_tags' => 0,
        'video_tags' => 0,
        'bytes_received' => 0,
        'start_time' => 0,
        'last_report_time' => 0,
        'reconnect_count' => 0,
    ];

    const SCRIPT_TAG = 18;
    const AUDIO_TAG = 8;
    const VIDEO_TAG = 9;

    const VIDEO_FRAME_TYPE_KEY_FRAME = 1;
    const VIDEO_CODEC_ID_AVC = 7;
    const AVC_PACKET_TYPE_SEQUENCE_HEADER = 0;

    const SOUND_FORMAT_AAC = 10;
    const AAC_PACKET_TYPE_SEQUENCE_HEADER = 0;

    public function __construct(string $pullUrl, string $outputFlv, int $duration = 0, bool $autoReconnect = true)
    {
        $this->pullUrl = $pullUrl;
        $this->outputFlv = $outputFlv;
        $this->duration = $duration;
        $this->autoReconnect = $autoReconnect;

        $urlParts = parse_url($pullUrl);
        $scheme = strtolower($urlParts['scheme'] ?? 'http');
        $this->isWebSocket = ($scheme === 'ws' || $scheme === 'wss');

        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, [$this, 'handleSignal']);
            pcntl_signal(SIGTERM, [$this, 'handleSignal']);
        }
    }

    public function handleSignal(int $signal): void
    {
        $this->log("收到信号 {$signal}，正在停止拉流...");
        $this->isRunning = false;
    }

    public function start(): void
    {
        $this->stats['start_time'] = microtime(true);
        $this->stats['last_report_time'] = $this->stats['start_time'];

        $this->log("========================================");
        $this->log("FLV Puller v1.0.0");
        $this->log("========================================");
        $this->log("拉流地址: {$this->pullUrl}");
        $this->log("输出文件: {$this->outputFlv}");

        $urlParts = parse_url($this->pullUrl);
        $scheme = strtolower($urlParts['scheme'] ?? 'http');
        $protocolName = '';
        switch ($scheme) {
            case 'http':
            case 'https':
                $protocolName = 'HTTP-FLV';
                break;
            case 'ws':
            case 'wss':
                $protocolName = 'WebSocket-FLV';
                break;
            case 'rtmp':
            case 'rtmps':
                $protocolName = 'RTMP';
                break;
            default:
                $protocolName = $scheme;
        }
        $this->log("协议: {$protocolName}");
        $this->log("录制时长: " . ($this->duration > 0 ? "{$this->duration} 秒" : "不限"));
        $this->log("自动重连: " . ($this->autoReconnect ? '是' : '否'));
        $this->log("========================================");

        $dir = dirname($this->outputFlv);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $this->fileHandle = fopen($this->outputFlv, 'wb');
        if (!$this->fileHandle) {
            throw new \RuntimeException("无法创建输出文件: {$this->outputFlv}");
        }

        $this->lastStatsTime = time();

        while ($this->isRunning) {
            try {
                $this->connect();
            } catch (\Exception $e) {
                $this->log("连接失败: {$e->getMessage()}", 'error');
                if (!$this->autoReconnect || !$this->handleReconnect()) {
                    break;
                }
                continue;
            }

            try {
                while ($this->isRunning) {
                    if ($this->duration > 0 && $this->startTime !== null && (time() - $this->startTime) >= $this->duration) {
                        $this->log("已达到指定时长 {$this->duration} 秒，停止拉流");
                        break;
                    }

                    $data = $this->receiveData();
                    if ($data === null || $data === '') {
                        usleep(100000);
                        continue;
                    }

                    $this->processData($data);
                }
            } catch (\Exception $e) {
                $this->log("接收数据异常: {$e->getMessage()}", 'error');
            } finally {
                $this->disconnect();
            }

            if ($this->duration > 0 && $this->startTime !== null && (time() - $this->startTime) >= $this->duration) {
                break;
            }

            if ($this->autoReconnect && $this->isRunning) {
                if (!$this->handleReconnect()) {
                    break;
                }
            } else {
                break;
            }
        }

        if ($this->fileHandle) {
            fclose($this->fileHandle);
            $this->fileHandle = null;
        }

        $this->printFinalStats();
    }

    protected function processData(string $data): void
    {
        if ($this->chunked) {
            $this->chunkBuffer .= $data;
            $decoded = $this->decodeChunked($this->chunkBuffer);
            if ($decoded !== null) {
                $this->writeFlvData($decoded);
            }
        } else {
            $this->writeFlvData($data);
        }
    }

    protected function writeFlvData(string $data): void
    {
        $adjustedData = $this->adjustFlvTimestamps($data);
        fwrite($this->fileHandle, $adjustedData);
        $this->bytesWritten += strlen($adjustedData);
        $this->stats['bytes_received'] += strlen($adjustedData);

        $now = microtime(true);
        if ($now - $this->stats['last_report_time'] >= 1) {
            $this->printProgress();
            $this->stats['last_report_time'] = $now;
        }
    }

    protected function adjustFlvTimestamps(string $data): string
    {
        $result = '';
        $offset = 0;
        $dataLen = strlen($data);

        if ($offset < $dataLen && substr($data, $offset, 3) === 'FLV') {
            $headerLen = 13;
            if ($dataLen >= $headerLen) {
                $result .= substr($data, $offset, $headerLen);
                $offset += $headerLen;
                if ($this->startTime === null) {
                    $this->startTime = time();
                    $this->log("开始计时，将录制 {$this->duration} 秒");
                }
            }
        }

        while ($offset + 11 < $dataLen) {
            $tagType = ord($data[$offset]);
            $dataSize = ((ord($data[$offset + 1]) << 16) | (ord($data[$offset + 2]) << 8) | ord($data[$offset + 3]));
            $timestamp = ((ord($data[$offset + 4]) << 16) | (ord($data[$offset + 5]) << 8) | ord($data[$offset + 6]));
            $timestampExt = ord($data[$offset + 7]);
            $fullTimestamp = ($timestampExt << 24) | $timestamp;

            $tagTotalSize = 11 + $dataSize + 4;

            if ($offset + $tagTotalSize > $dataLen) {
                break;
            }

            $tagHeader = substr($data, $offset, 11);
            $tagPayload = substr($data, $offset + 11, $dataSize);
            $prevTagSize = substr($data, $offset + 11 + $dataSize, 4);

            $adjustedTimestamp = $this->calculateAdjustedTimestamp($tagType, $fullTimestamp, $tagPayload);

            $newTimestampLow = $adjustedTimestamp & 0xFFFFFF;
            $newTimestampHigh = ($adjustedTimestamp >> 24) & 0xFF;

            $newTagHeader = $tagHeader;
            $newTagHeader[4] = chr(($newTimestampLow >> 16) & 0xFF);
            $newTagHeader[5] = chr(($newTimestampLow >> 8) & 0xFF);
            $newTagHeader[6] = chr($newTimestampLow & 0xFF);
            $newTagHeader[7] = chr($newTimestampHigh);

            $result .= $newTagHeader . $tagPayload . $prevTagSize;
            $offset += $tagTotalSize;

            $this->stats['tags_received']++;
            if ($tagType === self::AUDIO_TAG) {
                $this->stats['audio_tags']++;
            } elseif ($tagType === self::VIDEO_TAG) {
                $this->stats['video_tags']++;
            }
        }

        if ($offset < $dataLen) {
            $result .= substr($data, $offset);
        }

        return $result;
    }

    protected function calculateAdjustedTimestamp(int $tagType, int $originalTimestamp, string $payload): int
    {
        if ($tagType === self::SCRIPT_TAG) {
            return 0;
        }

        if ($tagType === self::VIDEO_TAG && strlen($payload) >= 1) {
            $firstByte = ord($payload[0]);
            $codecId = $firstByte & 0x0F;
            if ($codecId === self::VIDEO_CODEC_ID_AVC && strlen($payload) >= 5) {
                $avcPacketType = ord($payload[1]);
                if ($avcPacketType === self::AVC_PACKET_TYPE_SEQUENCE_HEADER) {
                    return 0;
                }
            }
        }

        if ($tagType === self::AUDIO_TAG && strlen($payload) >= 1) {
            $firstByte = ord($payload[0]);
            $soundFormat = ($firstByte >> 4) & 0x0F;
            if ($soundFormat === self::SOUND_FORMAT_AAC && strlen($payload) >= 2) {
                $aacPacketType = ord($payload[1]);
                if ($aacPacketType === self::AAC_PACKET_TYPE_SEQUENCE_HEADER) {
                    return 0;
                }
            }
        }

        if ($tagType === self::VIDEO_TAG || $tagType === self::AUDIO_TAG) {
            if ($this->baseTimestamp === null) {
                $this->baseTimestamp = $originalTimestamp;
            }
            return max(0, $originalTimestamp - $this->baseTimestamp);
        }

        return $originalTimestamp;
    }

    protected function connect(): void
    {
        $urlParts = parse_url($this->pullUrl);
        $scheme = strtolower($urlParts['scheme'] ?? 'http');
        $host = $urlParts['host'] ?? '127.0.0.1';
        $port = $urlParts['port'] ?? ($scheme === 'https' ? 443 : ($scheme === 'wss' ? 443 : 8501));
        $path = $urlParts['path'] ?? '/';
        if (!empty($urlParts['query'])) {
            $path .= '?' . $urlParts['query'];
        }

        $this->log("连接到 {$host}:{$port}{$path}...");

        if ($this->isWebSocket) {
            $this->connectWebSocket($host, $port, $path, $scheme === 'wss');
        } else {
            $this->connectHttp($host, $port, $path);
        }
    }

    protected function connectHttp(string $host, int $port, string $path): void
    {
        $this->socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 10);
        if (!$this->socket) {
            throw new \RuntimeException("无法连接到 {$host}:{$port} - {$errstr}");
        }
        stream_set_timeout($this->socket, 10);

        $request = "GET {$path} HTTP/1.1\r\n";
        $request .= "Host: {$host}\r\n";
        $request .= "Accept: */*\r\n";
        $request .= "Connection: keep-alive\r\n";
        $request .= "\r\n";

        fwrite($this->socket, $request);

        $header = '';
        $timeout = time() + 10;

        while (time() < $timeout) {
            $chunk = @fread($this->socket, 4096);
            if ($chunk === false) {
                throw new \RuntimeException("读取响应失败");
            }
            if ($chunk === '') {
                usleep(10000);
                continue;
            }

            $header .= $chunk;
            if (($pos = strpos($header, "\r\n\r\n")) !== false) {
                $headerStr = substr($header, 0, $pos);
                $bodyData = substr($header, $pos + 4);

                if (!preg_match('#^HTTP/\d\.\d 200#', $headerStr)) {
                    $this->safeCloseStream($this->socket);
                    $this->socket = null;
                    throw new \RuntimeException("服务器返回非200状态码");
                }

                $this->chunked = (stripos($headerStr, "Transfer-Encoding: chunked") !== false);
                $this->chunkBuffer = '';

                if (strlen($bodyData) > 0) {
                    if ($this->chunked) {
                        $this->chunkBuffer = $bodyData;
                        $decoded = $this->decodeChunked($this->chunkBuffer);
                        if ($decoded !== null) {
                            $this->writeFlvData($decoded);
                        }
                    } else {
                        $this->writeFlvData($bodyData);
                    }
                }

                stream_set_blocking($this->socket, false);
                $this->log("HTTP响应状态: 200 OK");
                $this->log("上游连接成功，分块编码: " . ($this->chunked ? '是' : '否'));
                return;
            }
        }

        $this->safeCloseStream($this->socket);
        $this->socket = null;
        throw new \RuntimeException("上游响应超时");
    }

    protected function connectWebSocket(string $host, int $port, string $path, bool $ssl = false): void
    {
        $proto = $ssl ? 'ssl' : 'tcp';
        $this->socket = @stream_socket_client("{$proto}://{$host}:{$port}", $errno, $errstr, 10);
        if (!$this->socket) {
            throw new \RuntimeException("无法连接到 {$host}:{$port} - {$errstr}");
        }

        stream_set_timeout($this->socket, 10);

        $key = base64_encode(random_bytes(16));
        $handshake = "GET {$path} HTTP/1.1\r\n";
        $handshake .= "Host: {$host}:{$port}\r\n";
        $handshake .= "Upgrade: websocket\r\n";
        $handshake .= "Connection: Upgrade\r\n";
        $handshake .= "Sec-WebSocket-Key: {$key}\r\n";
        $handshake .= "Sec-WebSocket-Version: 13\r\n";
        $handshake .= "\r\n";

        fwrite($this->socket, $handshake);

        $response = fread($this->socket, 1024);
        if (!str_contains($response, '101 Switching Protocols')) {
            $this->safeCloseStream($this->socket);
            $this->socket = null;
            throw new \RuntimeException("WebSocket握手失败");
        }

        $this->log("WebSocket握手成功");
    }

    protected function receiveData(): ?string
    {
        if (!$this->socket) {
            return null;
        }

        if ($this->isWebSocket) {
            return $this->receiveWebSocketData();
        } else {
            return $this->receiveHttpData();
        }
    }

    protected function receiveWebSocketData(): ?string
    {
        $frame = @fread($this->socket, 2);
        if (!$frame || strlen($frame) < 2) {
            $info = stream_get_meta_data($this->socket);
            if (!empty($info['eof'])) {
                throw new \RuntimeException("WebSocket连接已关闭");
            }
            return null;
        }

        $firstByte = ord($frame[0]);
        $secondByte = ord($frame[1]);

        $opcode = $firstByte & 0x0F;
        $payloadLen = $secondByte & 0x7F;

        if ($opcode === 0x08) {
            throw new \RuntimeException("WebSocket连接关闭帧");
        }

        if ($opcode !== 0x01 && $opcode !== 0x02) {
            return null;
        }

        if ($payloadLen === 126) {
            $ext = @fread($this->socket, 2);
            if ($ext === false || strlen($ext) < 2) return null;
            $payloadLen = unpack('n', $ext)[1];
        } elseif ($payloadLen === 127) {
            $ext = @fread($this->socket, 8);
            if ($ext === false || strlen($ext) < 8) return null;
            $payloadLen = unpack('J', $ext)[1];
        }

        $data = '';
        while (strlen($data) < $payloadLen) {
            $chunk = @fread($this->socket, $payloadLen - strlen($data));
            if ($chunk === false) {
                $info = stream_get_meta_data($this->socket);
                if (!empty($info['eof'])) {
                    throw new \RuntimeException("WebSocket连接已关闭");
                }
                break;
            }
            $data .= $chunk;
        }

        return $data;
    }

    protected function receiveHttpData(): ?string
    {
        $data = @fread($this->socket, 65536);
        if ($data === false) {
            $info = stream_get_meta_data($this->socket);
            if (!empty($info['eof'])) {
                throw new \RuntimeException("HTTP连接已关闭");
            }
            return null;
        }

        if ($data === '') {
            $info = stream_get_meta_data($this->socket);
            if (!empty($info['eof'])) {
                throw new \RuntimeException("HTTP连接已关闭");
            }
            return null;
        }

        return $data;
    }

    protected function decodeChunked(string &$buf): ?string
    {
        $decoded = '';
        while (true) {
            $pos = strpos($buf, "\r\n");
            if ($pos === false) break;

            $sizeHex = trim(substr($buf, 0, $pos));
            if ($sizeHex === '') {
                $buf = substr($buf, $pos + 2);
                continue;
            }

            if (!ctype_xdigit($sizeHex)) {
                $buf = substr($buf, $pos + 2);
                continue;
            }

            $size = hexdec($sizeHex);
            if ($size === 0) {
                $buf = '';
                return $decoded;
            }

            $start = $pos + 2;
            $end = $start + $size + 2;

            if (strlen($buf) < $end) {
                break;
            }

            $decoded .= substr($buf, $start, $size);
            $buf = substr($buf, $end);
        }

        return $decoded === '' ? null : $decoded;
    }

    protected function disconnect(): void
    {
        $this->safeCloseStream($this->socket);
        $this->socket = null;
        $this->chunked = false;
        $this->chunkBuffer = '';
        $this->baseTimestamp = null;
    }

    protected function safeCloseStream(&$stream): void
    {
        if ($stream === null) return;
        if (is_resource($stream) && get_resource_type($stream) === 'stream') {
            @stream_socket_shutdown($stream, STREAM_SHUT_RDWR);
            @fclose($stream);
        }
        $stream = null;
    }

    protected function handleReconnect(): bool
    {
        if (!$this->autoReconnect) {
            return false;
        }

        $this->retryCount++;
        if ($this->retryCount > $this->maxRetries) {
            $this->log("已达到最大重试次数 {$this->maxRetries}", 'error');
            return false;
        }

        $this->log("{$this->retryDelay} 秒后进行第 {$this->retryCount} 次重试...");
        sleep($this->retryDelay);
        return true;
    }

    protected function printProgress(): void
    {
        $elapsed = microtime(true) - $this->stats['start_time'];
        $speed = $elapsed > 0 ? $this->stats['tags_received'] / $elapsed : 0;
        $bitrate = $elapsed > 0 ? ($this->stats['bytes_received'] * 8 / $elapsed) / 1000 : 0;
        $sizeMB = round($this->stats['bytes_received'] / 1024 / 1024, 2);

        $this->log(sprintf(
            "[进度] 已接收: %d tags | 音频: %d | 视频: %d | 文件大小: %.2f MB | 速率: %.1f tags/s | 码率: %.1f kbps",
            $this->stats['tags_received'],
            $this->stats['audio_tags'],
            $this->stats['video_tags'],
            $sizeMB,
            $speed,
            $bitrate
        ), 'progress');
    }

    protected function printFinalStats(): void
    {
        $elapsed = microtime(true) - $this->stats['start_time'];
        $avgSpeed = $elapsed > 0 ? $this->stats['tags_received'] / $elapsed : 0;
        $totalBitrate = $elapsed > 0 ? ($this->stats['bytes_received'] * 8 / $elapsed) / 1000 : 0;

        $this->log("========================================");
        $this->log("拉流统计");
        $this->log("========================================");
        $this->log("总耗时: " . $this->formatTime($elapsed * 1000));
        $this->log("接收 Tag 数: " . number_format($this->stats['tags_received']));
        $this->log("  - 音频: " . number_format($this->stats['audio_tags']));
        $this->log("  - 视频: " . number_format($this->stats['video_tags']));
        $this->log("文件大小: " . $this->formatBytes($this->stats['bytes_received']));
        $this->log("平均速率: " . number_format($avgSpeed, 1) . " tags/s");
        $this->log("平均码率: " . number_format($totalBitrate, 1) . " kbps");
        $this->log("重连次数: " . $this->stats['reconnect_count']);
        $this->log("输出文件: {$this->outputFlv}");
        $this->log("========================================");
    }

    protected function formatTime($ms): string
    {
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

    protected function formatBytes($bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }

    protected function log(string $message, string $level = 'info'): void
    {
        if ($level === 'debug') {
            return;
        }

        $time = date('Y-m-d H:i:s');
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
            case 'progress':
                $prefix = "\033[94m[PROGRESS]\033[0m";
                break;
            default:
                $prefix = "[INFO]";
        }

        echo "[{$time}] {$prefix} {$message}\n";
    }
}