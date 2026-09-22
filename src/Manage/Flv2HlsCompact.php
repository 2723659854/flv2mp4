<?php

namespace Xiaosongshu\Flv2mp4\Manage;

use RuntimeException;
use Xiaosongshu\Flv2mp4\Recode\PurePhpHlsGenerator;

/**
 * FLV直播拉流转码压缩客户端（独立部署）
 *
 * 输入一路直播地址（http-flv / ws-flv / https / wss），自动拉流、缓冲、
 * 纯PHP重编码压缩（解码->缩放->H264 baseline重编码）为HLS切片输出。
 *
 * 模型：
 *  - 单进程 stream_select 事件循环：非阻塞读网络 -> 增量解析协议 -> tag入缓存队列
 *  - 缓存队列解耦网络抖动与CPU密集转码；队列满时停止读socket，由TCP反压上游
 *  - 每轮事件循环消费一个tag送转码器（转码内部的运动估计仍由motion worker子进程并行）
 *  - 断线自动重连，单轮连续失败最多5次；连接稳定收流60秒后重置重试计数
 *  - 重连后源时间戳可能归零，按单调不减原则平移时间轴，分片时间轴跨重连连续
 *
 * 仅支持 H264 + AAC 源；转码走串行管道（不抽帧，fps仅传编码器）。
 * 属CPU密集型服务，应与直播服务器分机部署，避免争抢核导致推流卡顿。
 *
 * @author yanglong
 */
class Flv2HlsCompact
{
    /** 拉流地址 */
    private string $pullUrl;
    private bool $isWebSocket;
    private bool $isSsl;

    private PurePhpHlsGenerator $generator;
    private string $streamDir;

    // ===== 拉流参数 =====
    private int $maxRetries = 5;
    private int $retryDelay = 3;
    private int $connectTimeout = 10;
    private int $idleTimeout = 30;
    private int $queueMaxBytes = 67108864; // 64MB，约可缓存1分钟@8Mbps以内的压缩源流
    private int $duration = 0;             // 0=不限时
    private bool $tlsVerify = true;

    /** @var resource|null */
    private $socket = null;
    private bool $running = true;
    private bool $stopSignaled = false; // Ctrl+C/SIGTERM请求的优雅停止

    // ===== 协议解析缓冲 =====
    private string $netBuffer = '';       // 已读未消费的原始字节（WS帧 / HTTP响应 / chunked）
    private string $flvBuffer = '';       // 已解出的FLV字节流（去WS帧/chunked之后）
    private string $chunkBuffer = '';     // chunked 未解完部分
    private bool $chunked = false;
    private bool $flvHeaderParsed = false;
    private int $wsFragmentOp = -1;
    private string $wsFragment = '';

    // ===== tag 缓存队列 =====
    /** @var array<int,array{0:int,1:string,2:int}> [tagType, body, fedTimestamp] */
    private array $queue = [];
    private int $queueBytes = 0;

    // ===== 时间轴连续化（跨重连） =====
    private int $timelineOffset = 0;
    private int $lastFedTimestamp = -1;
    private bool $connectionReady = false; // 本连接已见到FLV头

    // ===== 统计 =====
    private int $retryCount = 0;
    private int $connectCount = 0;
    private int $tagsReceived = 0;
    private int $videoTags = 0;
    private int $audioTags = 0;
    private int $tagsFed = 0;
    private int $bytesReceived = 0;
    private float $startMicrotime;
    private float $lastDataMicrotime = 0.0;
    private float $connectedAt = 0.0;
    private float $lastStatsMicrotime = 0.0;
    private bool $backpressureWarned = false;

    const AUDIO_TAG = 8;
    const VIDEO_TAG = 9;

    /**
     * @param string $pullUrl 直播地址 http(s)-flv / ws(s)-flv
     * @param array $config 转码配置：width/height(0=保持)/bitrate/fps(仅编码器)/qp/audioBitrate/
     *                      motionWorkers/watermark/watermark_file/segmentDuration；
     *                      部署配置：outputDir/streamName/maxRetries/retryDelay/connectTimeout/
     *                      idleTimeout/queueMaxBytes/duration/tlsVerify
     */
    public function __construct(string $pullUrl, array $config = [])
    {
        $this->pullUrl = $pullUrl;
        $parts = parse_url($pullUrl);
        $scheme = strtolower($parts['scheme'] ?? 'http');
        $this->isWebSocket = ($scheme === 'ws' || $scheme === 'wss');
        $this->isSsl = ($scheme === 'https' || $scheme === 'wss');

        $this->maxRetries = (int)($config['maxRetries'] ?? 5);
        $this->retryDelay = (int)($config['retryDelay'] ?? 3);
        $this->connectTimeout = (int)($config['connectTimeout'] ?? 10);
        $this->idleTimeout = (int)($config['idleTimeout'] ?? 30);
        if (isset($config['queueMaxBytes'])) $this->queueMaxBytes = (int)$config['queueMaxBytes'];
        if (isset($config['duration'])) $this->duration = (int)$config['duration'];
        if (isset($config['tlsVerify'])) $this->tlsVerify = (bool)$config['tlsVerify'];

        $streamName = $config['streamName'] ?? $this->deriveStreamName($parts);
        $this->streamDir = $config['outputDir'] ?? dirname(__DIR__, 2) . "/hls/{$streamName}/";
        if (!is_dir($this->streamDir)) mkdir($this->streamDir, 0777, true);

        $segmentDuration = isset($config['segmentDuration']) ? (int)$config['segmentDuration'] : 3;
        // 转码profile默认值；width/height默认0=保持源尺寸，缩放时务必同时给宽高避免拉伸
        $profile = $config + [
            'width' => 0,
            'height' => 0,
            'bitrate' => 800000,
            'fps' => 0,
            'audioBitrate' => 64000,
            'qp' => 10,
            'motionWorkers' => 6,
        ];
        unset(
            $profile['outputDir'], $profile['streamName'], $profile['segmentDuration'],
            $profile['maxRetries'], $profile['retryDelay'], $profile['connectTimeout'],
            $profile['idleTimeout'], $profile['queueMaxBytes'], $profile['duration'],
            $profile['tlsVerify'], $profile['multi']
        );

        $this->generator = new PurePhpHlsGenerator($profile, rtrim($this->streamDir, '/'), false);
        $this->generator->setSegmentDuration($segmentDuration > 0 ? $segmentDuration : 3);

        if (function_exists('pcntl_async_signals')) {
            // Linux/macOS：SIGINT(Ctrl+C)/SIGTERM 在主循环间隙触发，可安全写日志
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, [$this, 'handleSignal']);
            pcntl_signal(SIGTERM, [$this, 'handleSignal']);
        }
        if (function_exists('sapi_windows_set_ctrl_handler')) {
            // Windows：pcntl不存在，Ctrl+C默认直接杀进程（finally不执行、不写ENDLIST）。
            // 回调在独立线程触发，只做标量赋值，日志留给主循环收尾时输出。
            sapi_windows_set_ctrl_handler([$this, 'handleWindowsCtrl']);
        }
    }

    public function handleSignal(int $signal): void
    {
        $this->stopSignaled = true;
        $this->log("收到信号 {$signal}，准备停止...");
        $this->running = false;
    }

    public function handleWindowsCtrl(int $event): void
    {
        // 注册了处理器后PHP不会终止进程（Ctrl+C/Ctrl+Break均拦截）；
        // 回调在独立线程触发，只做标量赋值，日志留给主循环收尾时输出。
        $this->stopSignaled = true;
        $this->running = false;
    }

    public function getStreamDir(): string
    {
        return $this->streamDir;
    }

    public function getIndex(): string
    {
        return $this->streamDir . 'index.m3u8';
    }

    private function deriveStreamName(array $parts): string
    {
        $path = trim($parts['path'] ?? '/', '/');
        if ($path === '') return 'live';
        $base = basename($path);
        $base = preg_replace('/\.(flv|m3u8)$/i', '', $base);
        $base = preg_replace('/[^A-Za-z0-9_\-]/', '_', $base);
        return $base === '' ? 'live' : $base;
    }

    /**
     * 启动拉流转码客户端（阻塞运行，直到流结束、达到时长、5次重连失败或收到停止信号）
     */
    public function run(): void
    {
        $this->startMicrotime = microtime(true);
        $this->lastStatsMicrotime = $this->startMicrotime;
        $this->log('========================================');
        $this->log('FLV拉流转码HLS客户端 v1.0');
        $this->log("拉流地址: {$this->pullUrl}");
        $this->log('协议: ' . ($this->isWebSocket ? ($this->isSsl ? 'WSS-FLV' : 'WS-FLV') : ($this->isSsl ? 'HTTPS-FLV' : 'HTTP-FLV')));
        $this->log("输出目录: {$this->streamDir}");
        $this->log("最大重连: {$this->maxRetries} 次，队列上限: " . round($this->queueMaxBytes / 1048576, 1) . ' MB');
        $this->log('========================================');

        try {
            $this->loop();
        } finally {
            // 优雅收尾：冲刷末帧、关闭分片、追加ENDLIST
            try {
                $this->generator->finishStream();
            } catch (\Throwable $e) {
                $this->log('收尾异常: ' . $e->getMessage(), 'error');
            }
            $this->safeClose();
            $this->printStats();
        }
    }

    /**
     * 外层：连接/重连生命周期；内层：select事件循环
     */
    private function loop(): void
    {
        while ($this->running) {
            try {
                $this->connect();
            } catch (\Throwable $e) {
                $this->log('连接失败: ' . $e->getMessage(), 'error');
                $this->safeClose();
                if (!$this->reconnect()) return;
                continue;
            }

            try {
                $this->streamLoop();
            } catch (\Throwable $e) {
                $this->log('拉流中断: ' . $e->getMessage(), 'warning');
            } finally {
                $this->safeClose();
            }

            if (!$this->running) {
                if ($this->stopSignaled) {
                    $this->log('收到停止信号，正在冲刷末帧、关闭分片并写入ENDLIST...');
                }
                return;
            }
            if ($this->duration > 0 && (time() - (int)$this->startMicrotime) >= $this->duration) return;
            if (!$this->reconnect()) return;
        }
    }

    private function streamLoop(): void
    {
        while ($this->running) {
            if ($this->duration > 0 && microtime(true) - $this->startMicrotime >= $this->duration) {
                $this->log("已达到运行时长 {$this->duration} 秒，停止");
                $this->running = false;
                return;
            }

            // 队列满：进入反压，不读网络只转码，靠TCP窗口逼上游降速
            $paused = $this->queueBytes >= $this->queueMaxBytes;
            if ($paused) {
                if (!$this->backpressureWarned) {
                    $this->log('缓存队列达到上限，对上游施加TCP反压', 'warning');
                    $this->backpressureWarned = true;
                }
            } else {
                $this->backpressureWarned = false;
            }

            $read = $paused ? [] : [$this->socket];
            $write = [];
            $except = null;
            // 队列有积压时select短超时尽快消费；无积压时长等待节省CPU
            $sec = $this->queue === [] ? 1 : 0;
            $usec = $this->queue === [] ? 0 : 20000;
            if (@stream_select($read, $write, $except, $sec, $usec) === false) {
                throw new RuntimeException('stream_select 失败');
            }
            foreach ($read as $socket) {
                $this->onSocketReadable();
            }

            // 空闲超时判定（连接在但长期无数据）
            if ($this->lastDataMicrotime > 0 && microtime(true) - $this->lastDataMicrotime > $this->idleTimeout) {
                throw new RuntimeException("连续 {$this->idleTimeout} 秒无数据");
            }

            // 每轮消费一个tag（视频tag是重CPU操作，网络由队列缓冲）
            if ($this->queue !== []) {
                $this->consumeOneTag();
            }
            $this->maybePrintStats();

            // 稳定收流超过60秒，重置连续失败计数（允许长跑中偶发多次断流）
            if ($this->retryCount > 0 && $this->connectedAt > 0
                && microtime(true) - $this->connectedAt > 60) {
                $this->retryCount = 0;
            }
        }
    }

    private function onSocketReadable(): void
    {
        $chunk = @fread($this->socket, 65536);
        if ($chunk === false || ($chunk === '' && feof($this->socket))) {
            throw new RuntimeException('上游连接已关闭');
        }
        if ($chunk === '') return;

        $this->bytesReceived += strlen($chunk);
        $this->lastDataMicrotime = microtime(true);
        $this->netBuffer .= $chunk;

        if ($this->isWebSocket) {
            $this->drainWebSocketFrames();
        } else {
            $this->drainHttpBody();
        }
        $this->parseFlv();
    }

    // ================= 连接握手 =================

    private function connect(): void
    {
        // 每连接解析状态必须在握手前重置：握手读响应头时可能已收到首块FLV数据，
        // 若在握手后清空netBuffer会丢掉FLV头导致chunked错位
        $this->netBuffer = '';
        $this->flvBuffer = '';
        $this->chunkBuffer = '';
        $this->chunked = false;
        $this->flvHeaderParsed = false;
        $this->wsFragmentOp = -1;
        $this->wsFragment = '';
        $this->connectionReady = false;

        $parts = parse_url($this->pullUrl);
        $host = $parts['host'] ?? '127.0.0.1';
        $defaultPort = $this->isSsl ? 443 : 80;
        $port = (int)($parts['port'] ?? $defaultPort);
        $path = ($parts['path'] ?? '/') ?: '/';
        if (!empty($parts['query'])) $path .= '?' . $parts['query'];

        $this->log("连接 {$host}:{$port}{$path} ...");
        $remote = ($this->isSsl ? 'ssl' : 'tcp') . "://{$host}:{$port}";
        $context = null;
        if ($this->isSsl && isset($this->tlsVerify) && $this->tlsVerify === false) {
            $context = stream_context_create(['ssl' => [
                'verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true,
            ]]);
        }
        $socket = @stream_socket_client($remote, $errno, $errstr, $this->connectTimeout, STREAM_CLIENT_CONNECT, $context);
        if (!$socket) throw new RuntimeException("TCP连接失败: {$errstr} ({$errno})");
        stream_set_timeout($socket, $this->connectTimeout);
        $this->socket = $socket;

        if ($this->isWebSocket) {
            $this->handshakeWebSocket($host, $port, $path);
        } else {
            $this->handshakeHttp($host, $port, $path);
        }

        stream_set_blocking($this->socket, false);
        $this->connectCount++;
        $this->connectedAt = microtime(true);
        $this->lastDataMicrotime = $this->connectedAt;
        // 注意：解析状态已在建连开始时重置，此处不可再清空netBuffer，
        // 否则会丢掉握手响应头后面附带的首块FLV数据
        $this->connectionReady = true;
        $this->log('上游连接成功', 'success');
    }

    private function handshakeHttp(string $host, int $port, string $path): void
    {
        $request = "GET {$path} HTTP/1.1\r\n"
            . "Host: {$host}" . ($this->isSsl || in_array($port, [80, 443], true) ? '' : ":{$port}") . "\r\n"
            . "User-Agent: Xiaosongshu-Flv2HlsCompact/1.0\r\n"
            . "Accept: */*\r\n"
            . "Connection: close\r\n\r\n";
        fwrite($this->socket, $request);

        $response = '';
        $deadline = microtime(true) + $this->connectTimeout;
        while (microtime(true) < $deadline) {
            $piece = @fread($this->socket, 8192);
            if ($piece === false) throw new RuntimeException('读取HTTP响应失败');
            if ($piece !== '') {
                $response .= $piece;
                if (($pos = strpos($response, "\r\n\r\n")) !== false) {
                    $header = substr($response, 0, $pos);
                    if (!preg_match('#^HTTP/\d\.\d\s+200#', $header)) {
                        throw new RuntimeException('上游返回非200: ' . trim(strtok($header, "\r\n")));
                    }
                    $this->chunked = (stripos($header, 'Transfer-Encoding: chunked') !== false);
                    $remainder = substr($response, $pos + 4);
                    if ($remainder !== '') $this->netBuffer .= $remainder;
                    $this->log('HTTP 200，chunked: ' . ($this->chunked ? '是' : '否'));
                    return;
                }
            } else {
                usleep(50000);
            }
        }
        throw new RuntimeException('HTTP响应超时');
    }

    private function handshakeWebSocket(string $host, int $port, string $path): void
    {
        $key = base64_encode(random_bytes(16));
        $handshake = "GET {$path} HTTP/1.1\r\n"
            . "Host: {$host}:{$port}\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: {$key}\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . "User-Agent: Xiaosongshu-Flv2HlsCompact/1.0\r\n\r\n";
        fwrite($this->socket, $handshake);

        $response = '';
        $deadline = microtime(true) + $this->connectTimeout;
        while (microtime(true) < $deadline) {
            $piece = @fread($this->socket, 8192);
            if ($piece === false) throw new RuntimeException('读取WS握手响应失败');
            if ($piece !== '') {
                $response .= $piece;
                if (($pos = strpos($response, "\r\n\r\n")) !== false) {
                    $header = substr($response, 0, $pos);
                    if (stripos($header, '101 Switching Protocols') === false) {
                        throw new RuntimeException('WebSocket握手失败: ' . trim(strtok($header, "\r\n")));
                    }
                    $remainder = substr($response, $pos + 4);
                    if ($remainder !== '') $this->netBuffer .= $remainder;
                    $this->log('WebSocket 101 握手成功');
                    return;
                }
            } else {
                usleep(50000);
            }
        }
        throw new RuntimeException('WS握手超时');
    }

    private function reconnect(): bool
    {
        if ($this->retryCount >= $this->maxRetries) {
            $this->log("已达最大重连次数 {$this->maxRetries}，放弃", 'error');
            return false;
        }
        $this->retryCount++;
        $this->log("{$this->retryDelay} 秒后第 {$this->retryCount}/{$this->maxRetries} 次重连...");
        sleep($this->retryDelay);
        return true;
    }

    private function safeClose(): void
    {
        if (is_resource($this->socket)) {
            @stream_socket_shutdown($this->socket, STREAM_SHUT_RDWR);
            @fclose($this->socket);
        }
        $this->socket = null;
    }

    // ================= WebSocket 增量帧解析 =================

    /**
     * 从netBuffer解析所有完整WS帧：binary入FLV缓冲，ping回pong，close判断线
     */
    private function drainWebSocketFrames(): void
    {
        $buf = $this->netBuffer;
        $len = strlen($buf);
        $offset = 0;

        while ($offset + 2 <= $len) {
            $b0 = ord($buf[$offset]);
            $b1 = ord($buf[$offset + 1]);
            $fin = ($b0 & 0x80) !== 0;
            $opcode = $b0 & 0x0F;
            $masked = ($b1 & 0x80) !== 0;
            $payloadLen = $b1 & 0x7F;
            $headerLen = 2;

            if ($payloadLen === 126) {
                if ($offset + 4 > $len) break;
                $payloadLen = unpack('n', substr($buf, $offset + 2, 2))[1];
                $headerLen = 4;
            } elseif ($payloadLen === 127) {
                if ($offset + 10 > $len) break;
                $quad = unpack('J', substr($buf, $offset + 2, 8))[1];
                if ($quad > 0x7FFFFFFF) throw new RuntimeException('WS帧过大');
                $payloadLen = (int)$quad;
                $headerLen = 10;
            }
            if ($masked) {
                if ($offset + $headerLen + 4 > $len) break;
                $mask = substr($buf, $offset + $headerLen, 4);
                $headerLen += 4;
            }
            if ($offset + $headerLen + $payloadLen > $len) break;

            $payload = $payloadLen > 0 ? substr($buf, $offset + $headerLen, $payloadLen) : '';
            if ($masked) {
                for ($i = 0; $i < $payloadLen; $i++) $payload[$i] = chr(ord($payload[$i]) ^ ord($mask[$i % 4]));
            }
            $offset += $headerLen + $payloadLen;

            // 控制帧（close/ping/pong）不允许分片，随时可处理
            if ($opcode === 0x08) {
                throw new RuntimeException('收到WebSocket关闭帧');
            }
            if ($opcode === 0x09) {
                $this->sendWsPong($payload);
                continue;
            }
            if ($opcode === 0x0A) continue;

            // 数据帧：0=分片续帧，1=文本（忽略），2=binary
            if ($opcode !== 0x00) {
                if (!$fin) {
                    $this->wsFragmentOp = $opcode;
                    $this->wsFragment = $payload;
                    continue;
                }
                if ($opcode === 0x02) $this->flvBuffer .= $payload;
                continue;
            }
            if ($this->wsFragmentOp === 0x02) {
                $this->wsFragment .= $payload;
                if ($fin) {
                    $this->flvBuffer .= $this->wsFragment;
                    $this->wsFragment = '';
                    $this->wsFragmentOp = -1;
                }
            }
        }

        $this->netBuffer = $offset > 0 ? substr($buf, $offset) : $buf;
    }

    private function sendWsPong(string $payload): void
    {
        if (!is_resource($this->socket)) return;
        $len = strlen($payload);
        $mask = random_bytes(4);
        for ($i = 0; $i < $len; $i++) $payload[$i] = chr(ord($payload[$i]) ^ ord($mask[$i % 4]));
        if ($len < 126) {
            $frame = chr(0x8A) . chr(0x80 | $len) . $mask . $payload;
        } elseif ($len <= 0xFFFF) {
            $frame = chr(0x8A) . chr(0xFE) . pack('n', $len) . $mask . $payload;
        } else {
            $frame = chr(0x8A) . chr(0xFF) . pack('J', $len) . $mask . $payload;
        }
        @fwrite($this->socket, $frame);
    }

    // ================= HTTP body / chunked 增量解码 =================

    private function drainHttpBody(): void
    {
        if ($this->netBuffer === '') return;
        if (!$this->chunked) {
            $this->flvBuffer .= $this->netBuffer;
            $this->netBuffer = '';
            return;
        }
        $this->chunkBuffer .= $this->netBuffer;
        $this->netBuffer = '';
        $decoded = $this->decodeChunked($this->chunkBuffer);
        if ($decoded !== '') $this->flvBuffer .= $decoded;
    }

    /**
     * 增量chunked解码；$buf 引用传递，解出的数据返回，剩余不完整部分留在$buf
     */
    private function decodeChunked(string &$buf): string
    {
        $decoded = '';
        while (true) {
            $pos = strpos($buf, "\r\n");
            if ($pos === false) break;
            $sizeLine = trim(substr($buf, 0, $pos));
            if ($sizeLine === '') { // 容忍空行/分块扩展
                $buf = substr($buf, $pos + 2);
                continue;
            }
            $size = hexdec(strtok($sizeLine, ';'));
            if ($size === 0) {
                // 流结束标记（可能带trailer），直播中视为上游正常关闭
                $buf = '';
                if ($decoded !== '') break;
                throw new RuntimeException('chunked流结束');
            }
            $start = $pos + 2;
            if (strlen($buf) < $start + $size + 2) break;
            $decoded .= substr($buf, $start, $size);
            $buf = substr($buf, $start + $size + 2);
        }
        return $decoded;
    }

    // ================= FLV 增量tag解析 =================

    private function parseFlv(): void
    {
        $buf = $this->flvBuffer;
        $len = strlen($buf);
        $offset = 0;

        if (!$this->flvHeaderParsed) {
            // 同步字兜底：协议错位/杂散字节时在缓冲前部扫描'FLV'标记并丢弃前缀
            if (substr($buf, 0, 3) !== 'FLV') {
                $sync = strpos($buf, 'FLV');
                if ($sync !== false && $sync > 0) {
                    $buf = substr($buf, $sync);
                    $len = strlen($buf);
                } elseif ($sync === false) {
                    if ($len < 65536) { $this->flvBuffer = $buf; return; }
                    throw new RuntimeException('不是有效的FLV流（缺少FLV头）');
                }
            }
            if ($len < 9) { $this->flvBuffer = $buf; return; }
            $headerSize = unpack('N', substr($buf, 5, 4))[1]; // FLV头长度，通常9
            if ($headerSize < 9) throw new RuntimeException('FLV头长度无效');
            if ($len < $headerSize + 4) { $this->flvBuffer = $buf; return; }
            $offset = $headerSize + 4; // 跳过FLV头 + PreviousTagSize0
            $this->flvHeaderParsed = true;
            $this->connectionReady = true;
        }

        while ($offset + 11 <= $len) {
            $tagType = ord($buf[$offset]);
            $dataSize = (ord($buf[$offset + 1]) << 16) | (ord($buf[$offset + 2]) << 8) | ord($buf[$offset + 3]);
            $tsLow = (ord($buf[$offset + 4]) << 16) | (ord($buf[$offset + 5]) << 8) | ord($buf[$offset + 6]);
            $tsHigh = ord($buf[$offset + 7]);
            $timestamp = ($tsHigh << 24) | $tsLow;
            $total = 11 + $dataSize + 4;
            if ($offset + $total > $len) break;

            $body = substr($buf, $offset + 11, $dataSize);
            $offset += $total;

            if ($tagType === self::VIDEO_TAG || $tagType === self::AUDIO_TAG) {
                $this->enqueueTag($tagType, $body, $timestamp);
            }
            // script tag(18) 忽略
        }

        $this->flvBuffer = $offset > 0 ? substr($buf, $offset) : $buf;
    }

    // ================= 缓存队列 =================

    private function enqueueTag(int $tagType, string $body, int $srcTimestamp): void
    {
        // 重连后时间戳可能归零：按单调不减平移，保证切片时间轴跨连接连续
        if ($this->lastFedTimestamp >= 0) {
            $candidate = $srcTimestamp + $this->timelineOffset;
            if ($candidate < $this->lastFedTimestamp) {
                $this->timelineOffset = $this->lastFedTimestamp + 1 - $srcTimestamp;
            }
        }
        $fedTimestamp = max(0, $srcTimestamp + $this->timelineOffset);
        if ($fedTimestamp < $this->lastFedTimestamp) $fedTimestamp = $this->lastFedTimestamp;

        $this->queue[] = [$tagType, $body, $fedTimestamp];
        $this->queueBytes += strlen($body);
        $this->tagsReceived++;
        if ($tagType === self::VIDEO_TAG) $this->videoTags++; else $this->audioTags++;
        $this->lastFedTimestamp = $fedTimestamp;
    }

    private function consumeOneTag(): void
    {
        $entry = array_shift($this->queue);
        if ($entry === null) return;
        [$tagType, $body, $timestamp] = $entry;
        $this->queueBytes -= strlen($body);
        $this->tagsFed++;

        $tag = new class($tagType, $body, $timestamp) {
            public int $tagType;
            public string $body;
            private int $timestamp;
            public function __construct(int $tagType, string $body, int $timestamp)
            {
                $this->tagType = $tagType;
                $this->body = $body;
                $this->timestamp = $timestamp;
            }
            public function getTime(): int
            {
                return $this->timestamp;
            }
        };
        $this->generator->processTag($tag);
    }

    // ================= 日志/统计 =================

    private function maybePrintStats(): void
    {
        $now = microtime(true);
        if ($now - $this->lastStatsMicrotime < 5) return;
        $this->lastStatsMicrotime = $now;
        $elapsed = max(0.001, $now - $this->startMicrotime);
        $queueMB = round($this->queueBytes / 1048576, 2);
        $kbps = round($this->bytesReceived * 8 / $elapsed / 1000);
        $this->log(sprintf(
            '连接#%d | 已收 %d tags (v%d/a%d) 已转 %d | 队列 %.2fMB/%d | %.1f tags/s | 入流 %d kbps',
            $this->connectCount,
            $this->tagsReceived, $this->videoTags, $this->audioTags, $this->tagsFed,
            $queueMB, (int)round($this->queueMaxBytes / 1048576),
            $this->tagsReceived / $elapsed, $kbps
        ), 'progress');
    }

    private function printStats(): void
    {
        $elapsed = microtime(true) - $this->startMicrotime;
        $this->log('========================================');
        $this->log('拉流转码结束统计');
        $this->log('总耗时: ' . round($elapsed, 1) . 's');
        $this->log("连接次数: {$this->connectCount}，残留队列tag: " . count($this->queue));
        $this->log("接收tag: {$this->tagsReceived} (视频{$this->videoTags}/音频{$this->audioTags})，送转码: {$this->tagsFed}");
        $this->log('入流数据: ' . round($this->bytesReceived / 1048576, 2) . ' MB');
        $this->log("播放列表: {$this->streamDir}index.m3u8");
        $this->log('========================================');
    }

    private function log(string $message, string $level = 'info'): void
    {
        $prefix = match ($level) {
            'error' => "\033[31m[ERROR]\033[0m",
            'warning' => "\033[33m[WARN]\033[0m",
            'success' => "\033[32m[OK]\033[0m",
            'progress' => "\033[94m[STAT]\033[0m",
            default => '[INFO]',
        };
        echo '[' . date('Y-m-d H:i:s') . "] {$prefix} {$message}\n";
    }
}
