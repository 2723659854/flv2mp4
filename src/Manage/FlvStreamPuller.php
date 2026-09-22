<?php

namespace Xiaosongshu\Flv2mp4\Manage;

use RuntimeException;

/**
 * FLV直播拉流进程（由 Flv2HlsCompact 转码主进程拉起的子进程）
 *
 * 唯一职责：以最快速度持续读空上游直播服务器，解析出FLV tag后通过本地TCP(IPC)
 * 交给转码进程。无论转码多慢，本进程都不允许反压上游：
 *
 *  - stream_select 非阻塞读上游（http-flv/https/ws/wss，含chunked与WS帧增量解析）
 *  - 发给转码进程的数据先进本地发送缓冲，非阻塞写，部分写保留续发
 *  - 转码跟不上导致积压超过 maxLagBytes 时，丢弃整个积压并进入跳帧模式：
 *    后续数据一律丢弃直到下一个IDR关键帧，再补发最新视频/音频序列头后恢复，
 *    保证解码器永远能从关键帧重新对齐（直播"追直播点"标准做法，绝不向上游反压）
 *  - 断线自动重连（默认最多5次，稳定收流60秒后计数清零）
 *  - 跨重连与跨跳帧均对时间戳做单调重映射（缺口压缩），保证切片时长/A/V同步不错乱
 *
 * 信号：忽略SIGINT/Ctrl+C（由转码主进程统一收尾后proc_terminate本进程）。
 *
 * @author yanglong
 */
class FlvStreamPuller
{
    const AUDIO_TAG = 8;
    const VIDEO_TAG = 9;

    // IPC帧类型
    const FRAME_TAG = 1;    // 媒体tag：[tagType:1][timestamp:4BE][body]
    const FRAME_END = 2;    // 上游永久不可用（重连耗尽）
    const FRAME_CREDIT = 3; // 转码进程→拉流进程：信用回报，payload=已消费字节u32BE

    // 预授信用窗口：转码进程启动时先放行这么多字节在途（内核+转码缓冲），
    // 之后每消费一批才补一批信用。窗口必须小，否则落后量藏在内核管道里看不见
    const CREDIT_INITIAL_BYTES = 262144;

    private string $pullUrl;
    private int $ipcPort;
    private bool $isWebSocket;
    private bool $isSsl;

    private int $maxRetries = 5;
    private int $retryDelay = 3;
    private int $connectTimeout = 10;
    private int $idleTimeout = 30;
    private int $maxLagBytes = 8388608; // 8MB：转码落后超过此值则跳帧追直播
    private bool $tlsVerify = true;

    /** @var resource|null 上游socket */
    private $socket = null;
    /** @var resource|null 与转码进程的IPC socket */
    private $ipc = null;
    private bool $running = true;

    // ===== 协议解析缓冲 =====
    private string $netBuffer = '';
    private string $flvBuffer = '';
    private string $chunkBuffer = '';
    private bool $chunked = false;
    private bool $flvHeaderParsed = false;
    private int $wsFragmentOp = -1;
    private string $wsFragment = '';

    // ===== IPC发送缓冲（积压量即转码落后量） =====
    private string $ipcBuffer = '';
    // 信用窗口记账：转码进程回报已消费量，拉流进程最多保持 CREDIT_INITIAL+已消费 在途
    private int $sentTotal = 0;     // 已写入IPC的帧字节累计
    private int $consumedTotal = 0; // 转码进程回报已消费字节累计
    private string $ctrlBuffer = ''; // 反向控制帧接收缓冲

    // ===== GOP跳帧 =====
    private bool $skipMode = false;
    private string $videoSeqHeader = '';  // 最新AVC序列头body
    private string $audioSeqHeader = '';  // 最新AAC序列头body

    // ===== 时间轴重映射（跨重连 + 跨跳帧缺口压缩） =====
    private int $timelineOffset = 0; // 重连归零平移
    private int $skippedMs = 0;      // 跳帧累计压缩掉的时长
    private int $lastFedTimestamp = -1;

    // ===== 统计 =====
    private int $retryCount = 0;
    private int $connectCount = 0;
    private int $tagsReceived = 0;
    private int $videoTags = 0;
    private int $audioTags = 0;
    private int $tagsDropped = 0;
    private int $skipCount = 0;
    private int $bytesReceived = 0;
    private float $startMicrotime;
    private float $lastDataMicrotime = 0.0;
    private float $connectedAt = 0.0;
    private float $lastStatsMicrotime = 0.0;

    /**
     * @param array $opts url/port/maxRetries/retryDelay/connectTimeout/idleTimeout/maxLagBytes/tlsVerify
     */
    public function __construct(array $opts)
    {
        $this->pullUrl = (string)$opts['url'];
        $this->ipcPort = (int)$opts['port'];
        $parts = parse_url($this->pullUrl);
        $scheme = strtolower($parts['scheme'] ?? 'http');
        $this->isWebSocket = ($scheme === 'ws' || $scheme === 'wss');
        $this->isSsl = ($scheme === 'https' || $scheme === 'wss');

        $this->maxRetries = (int)($opts['maxRetries'] ?? 5);
        $this->retryDelay = (int)($opts['retryDelay'] ?? 3);
        $this->connectTimeout = (int)($opts['connectTimeout'] ?? 10);
        $this->idleTimeout = (int)($opts['idleTimeout'] ?? 30);
        $this->maxLagBytes = (int)($opts['maxLagBytes'] ?? 8388608);
        $this->tlsVerify = (bool)($opts['tlsVerify'] ?? true);
    }

    public function run(): int
    {
        // 拉流进程不响应Ctrl+C/SIGINT：统一由转码主进程收尾后终止本进程，
        // 避免收尾期间拉流进程先死或日志错乱
        if (function_exists('sapi_windows_set_ctrl_handler')) {
            sapi_windows_set_ctrl_handler(function (int $e): void {});
        }
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, function (): void {}); // SIGTERM保持默认，供父进程快速终止
        }

        $this->startMicrotime = microtime(true);
        $this->lastStatsMicrotime = $this->startMicrotime;

        try {
            $this->connectIpc();
        } catch (\Throwable $e) {
            $this->log('无法连接转码进程: ' . $e->getMessage(), 'error');
            return 1;
        }
        $this->log('拉流子进程已启动，IPC端口 ' . $this->ipcPort);

        try {
            $this->loop();
        } catch (\Throwable $e) {
            $this->log('拉流进程退出: ' . $e->getMessage(), 'warning');
        }

        // 重连耗尽：通知转码进程上游已永久结束，再退出（IPC已断则静默）
        try {
            $this->sendFrame(self::FRAME_END, '');
        } catch (\Throwable $e) {
        }
        $this->safeCloseIpc();
        $this->printStats();
        return 0;
    }

    // ================= IPC =================

    /**
     * 压缩IPC内核socket缓冲（默认可能数MB，会掩盖转码落后导致跳帧不及时）
     */
    private function shrinkSocketBuffer($sock, string $which): void
    {
        if (!function_exists('socket_import_stream')) return;
        $s = @socket_import_stream($sock);
        if ($s === false) return;
        if ($which === 'sndbuf') {
            @socket_set_option($s, SOL_SOCKET, SO_SNDBUF, 65536);
        } else {
            @socket_set_option($s, SOL_SOCKET, SO_RCVBUF, 65536);
        }
    }

    private function connectIpc(): void
    {
        $deadline = microtime(true) + 15;
        $lastErr = '';
        while (microtime(true) < $deadline) {
            $ipc = @stream_socket_client("tcp://127.0.0.1:{$this->ipcPort}", $errno, $errstr, 2);
            if ($ipc) {
                stream_set_blocking($ipc, false);
                $this->shrinkSocketBuffer($ipc, 'sndbuf');
                $this->ipc = $ipc;
                return;
            }
            $lastErr = "{$errstr} ({$errno})";
            usleep(100000);
        }
        throw new RuntimeException($lastErr);
    }

    private function sendFrame(int $type, string $payload): void
    {
        if (!is_resource($this->ipc)) return;
        $this->ipcBuffer .= pack('N', strlen($payload) + 1) . chr($type) . $payload;
        $this->flushIpc(true);
    }

    /**
     * 转码进程尚未消费的在途字节（内核管道 + 转码侧缓冲，上限=预授窗口）
     */
    private function inFlightBytes(): int
    {
        return max(0, $this->sentTotal - $this->consumedTotal);
    }

    /**
     * 转码落后总量 = 在途 + 本地待发（GOP跳帧的判定依据）
     */
    private function lagBytes(): int
    {
        return $this->inFlightBytes() + strlen($this->ipcBuffer);
    }

    /**
     * 接收转码进程的信用回报等反向控制帧（同一TCP连接反向通道）
     */
    private function drainControl(): void
    {
        $data = @fread($this->ipc, 65536);
        if ($data === false || $data === '') {
            if ($data === '' && feof($this->ipc)) throw new RuntimeException('转码进程已关闭IPC');
            return;
        }
        $this->ctrlBuffer .= $data;
        while (strlen($this->ctrlBuffer) >= 4) {
            $frameLen = unpack('N', substr($this->ctrlBuffer, 0, 4))[1];
            if ($frameLen < 1 || strlen($this->ctrlBuffer) < 4 + $frameLen) break;
            $frame = substr($this->ctrlBuffer, 4, $frameLen);
            $this->ctrlBuffer = substr($this->ctrlBuffer, 4 + $frameLen);
            if (ord($frame[0]) === self::FRAME_CREDIT && strlen($frame) >= 5) {
                $v = unpack('N', substr($frame, 1, 4))[1];
                // u32累计值回绕处理（7×24长流约每2-3小时回绕一次）
                $base = intdiv($this->consumedTotal, 4294967296) * 4294967296;
                $prevMod = $this->consumedTotal - $base;
                if ($v < $prevMod) $base += 4294967296;
                $this->consumedTotal = $base + $v;
            }
        }
    }

    /**
     * 非阻塞写IPC，受转码进程信用窗口约束：没有信用就停（数据留在本地缓冲，
     * 由GOP跳帧逻辑丢弃），绝不阻塞读上游。
     * $allowBlock=true时（FRAME_END控制帧）边收信用边退避，最多等5秒
     */
    private function flushIpc(bool $allowBlock = false): void
    {
        if ($this->ipcBuffer === '' || !is_resource($this->ipc)) return;
        $waited = 0;
        while ($this->ipcBuffer !== '') {
            $avail = self::CREDIT_INITIAL_BYTES + $this->consumedTotal - $this->sentTotal;
            if ($avail <= 0) {
                if (!$allowBlock || $waited >= 50) return;
                $this->drainControl();
                usleep(100000);
                $waited++;
                continue;
            }
            $piece = $avail >= strlen($this->ipcBuffer)
                ? $this->ipcBuffer
                : substr($this->ipcBuffer, 0, $avail);
            $written = @fwrite($this->ipc, $piece);
            if ($written === false || ($written === 0 && feof($this->ipc))) {
                throw new RuntimeException('转码进程已关闭IPC');
            }
            if ($written > 0) {
                $this->sentTotal += $written;
                $this->ipcBuffer = substr($this->ipcBuffer, $written);
                continue;
            }
            if (!$allowBlock || $waited >= 50) return;
            $this->drainControl();
            usleep(100000);
            $waited++;
        }
    }

    // ================= 连接生命周期 =================

    private function loop(): void
    {
        while ($this->running) {
            try {
                $this->connect();
            } catch (\Throwable $e) {
                $this->log('连接失败: ' . $e->getMessage(), 'error');
                $this->safeCloseUpstream();
                $this->flushIpc();
                if (!$this->reconnect()) return;
                continue;
            }

            try {
                $this->streamLoop();
            } catch (\Throwable $e) {
                $this->log('拉流中断: ' . $e->getMessage(), 'warning');
            } finally {
                $this->safeCloseUpstream();
            }

            if (!$this->running) return;
            $this->flushIpc();
            if (!$this->reconnect()) return;
        }
    }

    private function streamLoop(): void
    {
        while ($this->running) {
            $read = [$this->socket, $this->ipc];
            $write = $this->ipcBuffer !== '' ? [$this->ipc] : [];
            $except = null;
            // 有积压时短轮询尽快排空本地缓冲，无数据时1秒醒一次省CPU
            if (@stream_select($read, $write, $except, 1) === false) {
                throw new RuntimeException('stream_select 失败');
            }
            // 先收信用回报再写，避免错过窗口更新
            if (in_array($this->ipc, $read, true)) $this->drainControl();
            if (in_array($this->socket, $read, true)) $this->onUpstreamReadable();
            if (in_array($this->ipc, $write, true) || $this->ipcBuffer !== '') $this->flushIpc();

            if ($this->lastDataMicrotime > 0 && microtime(true) - $this->lastDataMicrotime > $this->idleTimeout) {
                throw new RuntimeException("连续 {$this->idleTimeout} 秒无数据");
            }
            $this->maybePrintStats();

            // 稳定收流超过60秒，重置连续失败计数
            if ($this->retryCount > 0 && $this->connectedAt > 0
                && microtime(true) - $this->connectedAt > 60) {
                $this->retryCount = 0;
            }
        }
    }

    private function onUpstreamReadable(): void
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

    // ================= 上游握手 =================

    private function connect(): void
    {
        // 每连接解析状态必须在握手前重置（握手响应可能附带首块FLV数据）
        $this->netBuffer = '';
        $this->flvBuffer = '';
        $this->chunkBuffer = '';
        $this->chunked = false;
        $this->flvHeaderParsed = false;
        $this->wsFragmentOp = -1;
        $this->wsFragment = '';

        $parts = parse_url($this->pullUrl);
        $host = $parts['host'] ?? '127.0.0.1';
        $defaultPort = $this->isSsl ? 443 : 80;
        $port = (int)($parts['port'] ?? $defaultPort);
        $path = ($parts['path'] ?? '/') ?: '/';
        if (!empty($parts['query'])) $path .= '?' . $parts['query'];

        $this->log("连接 {$host}:{$port}{$path} ...");
        $remote = ($this->isSsl ? 'ssl' : 'tcp') . "://{$host}:{$port}";
        $context = null;
        if ($this->isSsl && $this->tlsVerify === false) {
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

    private function safeCloseUpstream(): void
    {
        if (is_resource($this->socket)) {
            @stream_socket_shutdown($this->socket, STREAM_SHUT_RDWR);
            @fclose($this->socket);
        }
        $this->socket = null;
    }

    private function safeCloseIpc(): void
    {
        if (is_resource($this->ipc)) {
            @stream_socket_shutdown($this->ipc, STREAM_SHUT_RDWR);
            @fclose($this->ipc);
        }
        $this->ipc = null;
    }

    // ================= WebSocket 增量帧解析 =================

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

            if ($opcode === 0x08) throw new RuntimeException('收到WebSocket关闭帧');
            if ($opcode === 0x09) {
                $this->sendWsPong($payload);
                continue;
            }
            if ($opcode === 0x0A) continue;

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

    private function decodeChunked(string &$buf): string
    {
        $decoded = '';
        while (true) {
            $pos = strpos($buf, "\r\n");
            if ($pos === false) break;
            $sizeLine = trim(substr($buf, 0, $pos));
            if ($sizeLine === '') {
                $buf = substr($buf, $pos + 2);
                continue;
            }
            $size = hexdec(strtok($sizeLine, ';'));
            if ($size === 0) {
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

    // ================= FLV 增量解析 → IPC =================

    private function parseFlv(): void
    {
        $buf = $this->flvBuffer;
        $len = strlen($buf);
        $offset = 0;

        if (!$this->flvHeaderParsed) {
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
            $headerSize = unpack('N', substr($buf, 5, 4))[1];
            if ($headerSize < 9) throw new RuntimeException('FLV头长度无效');
            if ($len < $headerSize + 4) { $this->flvBuffer = $buf; return; }
            $offset = $headerSize + 4;
            $this->flvHeaderParsed = true;
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
                $this->handleTag($tagType, $body, $timestamp);
            }
            // script tag(18) 忽略
        }

        $this->flvBuffer = $offset > 0 ? substr($buf, $offset) : $buf;
    }

    // ================= tag分发：序列头缓存 / GOP跳帧 / IPC帧化 =================

    private function isAvcSequenceHeader(string $body): bool
    {
        return strlen($body) >= 2 && (ord($body[0]) & 0x0F) === 7 && ord($body[1]) === 0x00;
    }

    private function isIdrNalu(string $body): bool
    {
        // frameType=1(key) codecId=7(AVC) AVCPacketType=1(NALU)
        return strlen($body) >= 2 && ord($body[0]) === 0x17 && ord($body[1]) === 0x01;
    }

    private function isAacSequenceHeader(string $body): bool
    {
        return strlen($body) >= 2 && (ord($body[0]) >> 4) === 10 && ord($body[1]) === 0x00;
    }

    private function handleTag(int $tagType, string $body, int $srcTimestamp): void
    {
        $this->tagsReceived++;
        if ($tagType === self::VIDEO_TAG) $this->videoTags++; else $this->audioTags++;

        // 始终更新最新序列头缓存（重连/编码器参数变更后用于追帧补发）
        if ($tagType === self::VIDEO_TAG && $this->isAvcSequenceHeader($body)) {
            $this->videoSeqHeader = $body;
        } elseif ($tagType === self::AUDIO_TAG && $this->isAacSequenceHeader($body)) {
            $this->audioSeqHeader = $body;
        }

        if ($this->skipMode) {
            $this->tagsDropped++;
            // 只在IDR关键帧恢复，先补发序列头再发关键帧，解码器才能完整对齐
            if ($tagType === self::VIDEO_TAG && $this->isIdrNalu($body)) {
                $this->resyncAtIdr($body, $srcTimestamp);
            }
            return;
        }

        $isSeq = $this->isSequenceTag($tagType, $body);
        // 媒体帧到来时发现本地积压已超限：立即丢弃全部积压进入跳帧模式，
        // 当前帧按跳帧规则处理（IDR则当场对齐，非IDR丢弃），保证读上游不被阻塞
        if (!$isSeq && $this->lagBytes() >= $this->maxLagBytes) {
            $this->enterSkipMode();
            if ($tagType === self::VIDEO_TAG && $this->isIdrNalu($body)) {
                $this->resyncAtIdr($body, $srcTimestamp);
            } else {
                $this->tagsDropped++;
            }
            return;
        }

        $this->emitTag($tagType, $body, $srcTimestamp, $isSeq);
    }

    private function isSequenceTag(int $tagType, string $body): bool
    {
        return ($tagType === self::VIDEO_TAG && $this->isAvcSequenceHeader($body))
            || ($tagType === self::AUDIO_TAG && $this->isAacSequenceHeader($body));
    }

    private function enterSkipMode(): void
    {
        if ($this->skipMode) return;
        $this->skipMode = true;
        $this->skipCount++;
        $lagKb = round($this->lagBytes() / 1024);
        $this->log("转码落后 {$lagKb}KB，丢弃积压并跳到下一个IDR追直播（第{$this->skipCount}次）", 'warning');
        $this->ipcBuffer = ''; // 积压整体丢弃
    }

    /**
     * 在IDR处重新对齐：补发序列头 + IDR，并把跳帧造成的时间缺口压缩掉
     */
    private function resyncAtIdr(string $idrBody, int $srcTimestamp): void
    {
        $fed = $this->remapTimestamp($srcTimestamp);
        if ($this->lastFedTimestamp >= 0) {
            $gap = $fed - $this->lastFedTimestamp;
            if ($gap > 1) $this->skippedMs += $gap - 1;
        }
        $fed = $this->remapTimestamp($srcTimestamp);
        if ($fed < $this->lastFedTimestamp) $fed = max(0, $this->lastFedTimestamp);

        $anchorTs = max(0, $this->lastFedTimestamp >= 0 ? $this->lastFedTimestamp : $fed);
        if ($this->videoSeqHeader !== '') {
            $this->frameToBuffer(self::VIDEO_TAG, $this->videoSeqHeader, $anchorTs);
        }
        if ($this->audioSeqHeader !== '') {
            $this->frameToBuffer(self::AUDIO_TAG, $this->audioSeqHeader, $anchorTs);
        }
        $this->frameToBuffer(self::VIDEO_TAG, $idrBody, $fed);
        $this->lastFedTimestamp = $fed;
        $this->skipMode = false;
        $this->log("已在IDR对齐直播点，丢弃 {$this->tagsDropped} 个tag", 'success');
        $this->tagsDropped = 0;
    }

    private function emitTag(int $tagType, string $body, int $srcTimestamp, bool $isSeq): void
    {
        $fed = $this->remapTimestamp($srcTimestamp);
        // 序列头时间戳不参与推进（生成器忽略其时间戳），避免压缩正常媒体时间轴
        if (!$isSeq && $fed < $this->lastFedTimestamp) $fed = max(0, $this->lastFedTimestamp);
        $this->frameToBuffer($tagType, $body, $fed);
        if (!$isSeq) $this->lastFedTimestamp = $fed;
    }

    /**
     * 时间戳重映射：先处理重连归零平移，再减去跳帧压缩量，保证单调不减
     */
    private function remapTimestamp(int $srcTimestamp): int
    {
        if ($this->lastFedTimestamp >= 0) {
            $candidate = $srcTimestamp + $this->timelineOffset;
            if ($candidate < $this->lastFedTimestamp + $this->skippedMs) {
                $this->timelineOffset = $this->lastFedTimestamp + $this->skippedMs + 1 - $srcTimestamp;
            }
        }
        return max(0, $srcTimestamp + $this->timelineOffset - $this->skippedMs);
    }

    private function frameToBuffer(int $tagType, string $body, int $timestamp): void
    {
        $payload = chr(self::FRAME_TAG) . chr($tagType) . pack('N', $timestamp) . $body;
        $this->ipcBuffer .= pack('N', strlen($payload)) . $payload;
    }

    // ================= 日志/统计 =================

    private function maybePrintStats(): void
    {
        $now = microtime(true);
        if ($now - $this->lastStatsMicrotime < 5) return;
        $this->lastStatsMicrotime = $now;
        $elapsed = max(0.001, $now - $this->startMicrotime);
        $lagMb = round($this->lagBytes() / 1048576, 2);
        $kbps = round($this->bytesReceived * 8 / $elapsed / 1000);
        $this->log(sprintf(
            '[拉流] 连接#%d | 已收 %d tags (v%d/a%d) | 待转积压 %.2fMB | 跳帧%d次 | 入流 %d kbps',
            $this->connectCount,
            $this->tagsReceived, $this->videoTags, $this->audioTags,
            $lagMb, $this->skipCount, $kbps
        ), 'progress');
    }

    private function printStats(): void
    {
        $elapsed = microtime(true) - $this->startMicrotime;
        $this->log('========================================');
        $this->log('[拉流] 拉流子进程结束统计');
        $this->log('[拉流] 总耗时: ' . round($elapsed, 1) . "s，连接次数: {$this->connectCount}，跳帧: {$this->skipCount} 次");
        $this->log("[拉流] 接收tag: {$this->tagsReceived} (视频{$this->videoTags}/音频{$this->audioTags})，入流 " . round($this->bytesReceived / 1048576, 2) . ' MB');
        $this->log('========================================');
    }

    private function log(string $message, string $level = 'info'): void
    {
        $prefix = match ($level) {
            'error' => "\033[31m[拉流-ERROR]\033[0m",
            'warning' => "\033[33m[拉流-WARN]\033[0m",
            'success' => "\033[32m[拉流-OK]\033[0m",
            'progress' => "\033[94m[拉流-STAT]\033[0m",
            default => '[拉流-INFO]',
        };
        echo '[' . date('Y-m-d H:i:s') . "] {$prefix} " . preg_replace('/^\[拉流\]\s*/', '', $message) . "\n";
    }
}
