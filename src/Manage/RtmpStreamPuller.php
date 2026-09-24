<?php

namespace Xiaosongshu\Flv2mp4\Manage;

use RuntimeException;

/**
 * RTMP直播拉流进程（由 Flv2HlsCompact 转码主进程拉起的子进程）
 *
 * 以最快速度持续读空上游RTMP服务器，把音视频消息还原成FLV tag后
 * 通过本地TCP(IPC)交给转码进程。无论转码多慢都不反压上游，复用同一套：
 *  - IPC长度前缀帧协议（FRAME_TAG/FRAME_END/FRAME_CREDIT）与预授信用窗口
 *  - 转码落后超限时丢弃积压、跳到下一个IDR关键帧并补发序列头追直播
 *  - 跨重连/跨跳帧时间戳单调重映射、断线重连、统计日志
 *
 * 上游层为RTMP：simple handshake + connect/createStream/play(AMF0)，
 * 非阻塞增量RTMP chunk重组解析（type 8音频/9视频，payload即FLV tag body）。
 *
 * 信号：忽略SIGINT/Ctrl+C（由转码主进程统一收尾后proc_terminate本进程）。
 *
 * @author yanglong
 * @time 2026年9月24日11:33:58
 */
class RtmpStreamPuller
{
    const AUDIO_TAG = 8;
    const VIDEO_TAG = 9;

    // IPC帧类型（与FlvStreamPuller完全一致，转码主进程无感知协议差异）
    const FRAME_TAG = 1;    // 媒体tag：[tagType:1][timestamp:4BE][body]
    const FRAME_END = 2;    // 上游永久不可用（重连耗尽）
    const FRAME_CREDIT = 3; // 转码进程→拉流进程：信用回报，payload=已消费字节u32BE

    const CREDIT_INITIAL_BYTES = 262144;
    const RTMP_SIG_SIZE = 1536;

    private string $pullUrl;
    private int $ipcPort;

    private int $maxRetries = 5;
    private int $retryDelay = 3;
    private int $connectTimeout = 10;
    private int $idleTimeout = 30;
    private int $maxLagBytes = 8388608; // 8MB：转码落后超过此值则跳帧追直播

    private string $rtmpHost = '127.0.0.1';
    private int $rtmpPort = 1935;
    private string $rtmpApp = 'live';
    private string $rtmpStreamKey = 'stream';

    /** @var resource|null 上游RTMP socket */
    private $socket = null;
    /** @var resource|null 与转码进程的IPC socket */
    private $ipc = null;
    private bool $running = true;

    // ===== RTMP协议状态 =====
    private string $netBuffer = '';
    private int $chunkSizeR = 128;
    private int $chunkSizeW = 4096;
    private int $streamId = 0;
    private int $windowAckSize = 2500000;
    private int $netBytesReceived = 0;
    private int $netBytesSinceAck = 0;
    /** @var array<int,array{ts:int,len:int,type:int,sid:int,ext:bool}> 各csid上一条完整消息头 */
    private array $rtmpLast = [];
    /** @var array<int,array{ts:int,len:int,type:int,sid:int,data:string,read:int,ext:bool,chunkRemain:int}> 各csid重组中的消息 */
    private array $rtmpCur = [];
    /** @var int|null 全局唯一"chunk payload接收中途"的csid（TCP截断点，线上接下来是裸payload而非块头） */
    private ?int $rtmpMidCsid = null;
    private string $ctrlOutBuffer = ''; // 待写回上游的控制消息（ACK/Pong）

    // ===== IPC发送缓冲（积压量即转码落后量） =====
    private string $ipcBuffer = '';
    private int $sentTotal = 0;
    private int $consumedTotal = 0;
    private string $ctrlBuffer = '';

    // ===== GOP跳帧 =====
    private bool $skipMode = false;
    private string $videoSeqHeader = '';
    private string $audioSeqHeader = '';

    // ===== 时间轴重映射（跨重连 + 跨跳帧缺口压缩） =====
    private int $timelineOffset = 0;
    private int $skippedMs = 0;
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
     * @param array $opts url/port/maxRetries/retryDelay/connectTimeout/idleTimeout/maxLagBytes
     */
    public function __construct(array $opts)
    {
        $this->pullUrl = (string)$opts['url'];
        $this->ipcPort = (int)$opts['port'];

        $this->maxRetries = (int)($opts['maxRetries'] ?? 5);
        $this->retryDelay = (int)($opts['retryDelay'] ?? 3);
        $this->connectTimeout = (int)($opts['connectTimeout'] ?? 10);
        $this->idleTimeout = (int)($opts['idleTimeout'] ?? 30);
        $this->maxLagBytes = (int)($opts['maxLagBytes'] ?? 8388608);
    }

    public function run(): int
    {
        if (function_exists('sapi_windows_set_ctrl_handler')) {
            sapi_windows_set_ctrl_handler(function (int $e): void {});
        }
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, function (): void {});
        }

        $this->startMicrotime = microtime(true);
        $this->lastStatsMicrotime = $this->startMicrotime;

        try {
            $this->connectIpc();
        } catch (\Throwable $e) {
            $this->log('无法连接转码进程: ' . $e->getMessage(), 'error');
            return 1;
        }
        $this->log('RTMP拉流子进程已启动，IPC端口 ' . $this->ipcPort);

        try {
            $this->loop();
        } catch (\Throwable $e) {
            $this->log('拉流进程退出: ' . $e->getMessage(), 'warning');
        }

        try {
            $this->sendFrame(self::FRAME_END, '');
        } catch (\Throwable $e) {
        }
        $this->safeCloseIpc();
        $this->printStats();
        return 0;
    }

    // ================= IPC（与FlvStreamPuller同协议） =================

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

    private function inFlightBytes(): int
    {
        return max(0, $this->sentTotal - $this->consumedTotal);
    }

    private function lagBytes(): int
    {
        return $this->inFlightBytes() + strlen($this->ipcBuffer);
    }

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
                $base = intdiv($this->consumedTotal, 4294967296) * 4294967296;
                $prevMod = $this->consumedTotal - $base;
                if ($v < $prevMod) $base += 4294967296;
                $this->consumedTotal = $base + $v;
            }
        }
    }

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
            $write = [];
            if ($this->ipcBuffer !== '') $write[] = $this->ipc;
            if ($this->ctrlOutBuffer !== '') $write[] = $this->socket;
            $except = null;
            if (@stream_select($read, $write, $except, 0, 2000) === false) {
                throw new RuntimeException('stream_select 失败');
            }
            if (in_array($this->ipc, $read, true)) $this->drainControl();
            if (in_array($this->socket, $read, true)) $this->onUpstreamReadable();
            if (in_array($this->socket, $write, true)) $this->flushUpstreamWrites();
            if (in_array($this->ipc, $write, true) || $this->ipcBuffer !== '') $this->flushIpc();

            if ($this->lastDataMicrotime > 0 && microtime(true) - $this->lastDataMicrotime > $this->idleTimeout) {
                throw new RuntimeException("连续 {$this->idleTimeout} 秒无数据");
            }
            $this->maybePrintStats();

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
        $this->netBytesReceived += strlen($chunk);
        $this->netBytesSinceAck += strlen($chunk);
        $this->lastDataMicrotime = microtime(true);
        $this->netBuffer .= $chunk;
        $this->parseRtmpMessages();

        // 按服务器窗口确认大小回ACK（控制消息很小，非阻塞追加后本轮立即写回）
        if ($this->netBytesSinceAck >= $this->windowAckSize) {
            $this->netBytesSinceAck = 0;
            $this->queueProtocolControl(0x03, self::u32BE($this->netBytesReceived));
        }
        $this->flushUpstreamWrites();
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

    // ================= RTMP 握手与命令 =================

    private function connect(): void
    {
        $parts = parse_url($this->pullUrl);
        $this->rtmpHost = $parts['host'] ?? '127.0.0.1';
        $this->rtmpPort = (int)($parts['port'] ?? 1935);
        $path = trim($parts['path'] ?? '/live/stream', '/');
        $segments = $path === '' ? ['live', 'stream'] : explode('/', $path);
        $this->rtmpApp = array_shift($segments) ?: 'live';
        $this->rtmpStreamKey = implode('/', $segments) ?: 'stream';

        // 每连接重置RTMP解析状态
        $this->netBuffer = '';
        $this->ctrlOutBuffer = '';
        $this->rtmpLast = [];
        $this->rtmpCur = [];
        $this->rtmpMidCsid = null;
        $this->chunkSizeR = 128;
        $this->streamId = 0;
        $this->netBytesReceived = 0;
        $this->netBytesSinceAck = 0;
        $this->windowAckSize = 2500000;

        $this->log("RTMP连接 {$this->rtmpHost}:{$this->rtmpPort}/{$this->rtmpApp}/{$this->rtmpStreamKey} ...");
        $socket = @stream_socket_client(
            "tcp://{$this->rtmpHost}:{$this->rtmpPort}",
            $errno, $errstr, $this->connectTimeout
        );
        if (!$socket) throw new RuntimeException("TCP连接失败: {$errstr} ({$errno})");
        stream_set_timeout($socket, $this->connectTimeout);
        $this->socket = $socket;

        $this->handshake();

        // 通告窗口确认大小与本端发送块大小（命令阶段socket阻塞，直接写尽）
        $this->queueProtocolControl(0x05, self::u32BE($this->windowAckSize));
        $this->queueProtocolControl(0x01, self::u32BE($this->chunkSizeW));
        $this->flushUpstreamWrites(true);

        // connect（事务1）
        $connectObj = [
            'app' => $this->rtmpApp,
            'flashVer' => 'LNX 10,0,32,18',
            'tcUrl' => "rtmp://{$this->rtmpHost}:{$this->rtmpPort}/{$this->rtmpApp}",
            'fpad' => false,
            'capabilities' => 0.0,
            'audioCodecs' => 0x01,
            'videoCodecs' => 0xFF,
            'videoFunction' => 0,
            'objectEncoding' => 0x03,
        ];
        $this->queueInvoke('connect', 1, $connectObj, []);
        $this->flushUpstreamWrites(true);
        $this->waitInvokeResult(1, 'connect');

        // createStream（事务2），_result第1个参数为message stream id
        $this->queueInvoke('createStream', 2, null, []);
        $this->flushUpstreamWrites(true);
        $this->streamId = $this->waitInvokeResult(2, 'createStream');

        // play（事务0），流名用app之后的完整路径
        $this->queueInvoke('play', 0, null, [$this->rtmpStreamKey]);
        $this->flushUpstreamWrites(true);
        $this->log("RTMP播放命令已发送: {$this->rtmpStreamKey}");

        // 等待媒体真正到来（或收到onStatus error），最长10秒；超时则进入主循环由idleTimeout兜底
        $deadline = microtime(true) + 10;
        $hadMedia = $this->tagsReceived > 0;
        while (!$hadMedia && microtime(true) < $deadline) {
            $read = [$this->socket];
            $write = $this->ctrlOutBuffer !== '' ? [$this->socket] : [];
            $except = null;
            if (@stream_select($read, $write, $except, 0, 2000) === false) {
                usleep(20000);
                continue;
            }
            if (in_array($this->socket, $write, true)) $this->flushUpstreamWrites(true);
            if (in_array($this->socket, $read, true)) {
                $before = $this->tagsReceived;
                $this->readBlockingChunk();
                $this->parseRtmpMessages();
                if ($this->tagsReceived > $before) $hadMedia = true;
            }
        }

        stream_set_blocking($this->socket, false);
        $this->connectCount++;
        $this->connectedAt = microtime(true);
        $this->lastDataMicrotime = $this->connectedAt;
        $this->log('上游RTMP连接成功', 'success');
    }

    private function handshake(): void
    {
        $ctime = time();
        $c1 = pack('NN', $ctime, 0);
        $c1 .= str_repeat("\x00", self::RTMP_SIG_SIZE - 8); // simple handshake，零随机即可
        // C0(0x03) + C1
        $this->writeAll(chr(0x03) . $c1);

        // S0(1) + S1(1536) + S2(1536)
        $srv = $this->readAll(1 + self::RTMP_SIG_SIZE * 2, $this->connectTimeout);
        if (ord($srv[0]) !== 0x03) throw new RuntimeException('RTMP握手失败：S0版本号非3');
        $s1 = substr($srv, 1, self::RTMP_SIG_SIZE);

        // C2：回显S1（simple handshake下服务端不校验digest）
        $this->writeAll($s1);
        $this->log('RTMP握手完成');
    }

    /**
     * 阻塞读足指定字节（握手阶段使用）
     */
    private function readAll(int $length, int $timeout): string
    {
        $data = '';
        $deadline = microtime(true) + $timeout;
        while (strlen($data) < $length && microtime(true) < $deadline) {
            $piece = @fread($this->socket, $length - strlen($data));
            if ($piece === false) throw new RuntimeException('RTMP读取失败');
            if ($piece !== '') {
                $data .= $piece;
                continue;
            }
            $read = [$this->socket];
            $write = null;
            $except = null;
            if (@stream_select($read, $write, $except, 0, 2000) === false) usleep(10000);
        }
        if (strlen($data) < $length) throw new RuntimeException('RTMP读取超时');
        return $data;
    }

    private function writeAll(string $data): void
    {
        $total = strlen($data);
        $written = 0;
        while ($written < $total) {
            $n = @fwrite($this->socket, substr($data, $written));
            if ($n === false || $n === 0) throw new RuntimeException('RTMP写入失败');
            $written += $n;
        }
    }

    private function readBlockingChunk(): void
    {
        $chunk = @fread($this->socket, 65536);
        if ($chunk === false || ($chunk === '' && feof($this->socket))) {
            throw new RuntimeException('上游连接已关闭');
        }
        if ($chunk !== '') {
            $this->netBuffer .= $chunk;
            $this->bytesReceived += strlen($chunk);
            $this->netBytesReceived += strlen($chunk);
            $this->netBytesSinceAck += strlen($chunk);
        }
    }

    /**
     * 阻塞等待指定事务的_result，返回其首个参数（createStream时为streamId），无参数返回0
     */
    private function waitInvokeResult(int $transId, string $what): int
    {
        $deadline = microtime(true) + $this->connectTimeout;
        while (microtime(true) < $deadline) {
            foreach ($this->parseRtmpMessages() as $invoke) {
                if (($invoke['name'] ?? '') === '_result' && (int)($invoke['trans'] ?? -1) === $transId) {
                    $arg = $invoke['args'][0] ?? 0;
                    return is_numeric($arg) ? (int)$arg : 0;
                }
            }
            $read = [$this->socket];
            $write = $this->ctrlOutBuffer !== '' ? [$this->socket] : [];
            $except = null;
            if (@stream_select($read, $write, $except, 0, 2000) === false) {
                usleep(20000);
                continue;
            }
            if (in_array($this->socket, $write, true)) $this->flushUpstreamWrites(true);
            if (in_array($this->socket, $read, true)) $this->readBlockingChunk();
        }
        throw new RuntimeException("RTMP {$what} 响应超时");
    }

    // ================= RTMP 出站消息 =================

    private function flushUpstreamWrites(bool $blocking = false): void
    {
        if ($this->ctrlOutBuffer === '' || !is_resource($this->socket)) return;
        if ($blocking) {
            $this->writeAll($this->ctrlOutBuffer);
            $this->ctrlOutBuffer = '';
            return;
        }
        while ($this->ctrlOutBuffer !== '') {
            $n = @fwrite($this->socket, $this->ctrlOutBuffer);
            if ($n === false || $n === 0) return;
            $this->ctrlOutBuffer = substr($this->ctrlOutBuffer, $n);
        }
    }

    /**
     * 协议控制消息（chunk stream id=2，message stream id=0）
     */
    private function queueProtocolControl(int $type, string $payload): void
    {
        $this->queueRtmpMessage(2, $type, 0, $payload, 0);
    }

    /**
     * 组装一条RTMP消息（fmt0首块 + fmt3续块，按发送块大小分片）
     */
    private function queueRtmpMessage(int $csid, int $type, int $msgStreamId, string $payload, int $timestamp): void
    {
        $length = strlen($payload);
        $header = chr($csid & 0x3F)
            . chr(($timestamp >> 16) & 0xFF) . chr(($timestamp >> 8) & 0xFF) . chr($timestamp & 0xFF)
            . chr(($length >> 16) & 0xFF) . chr(($length >> 8) & 0xFF) . chr($length & 0xFF)
            . chr($type)
            . chr($msgStreamId & 0xFF) . chr(($msgStreamId >> 8) & 0xFF)
            . chr(($msgStreamId >> 16) & 0xFF) . chr(($msgStreamId >> 24) & 0xFF);

        $frame = $header;
        $offset = 0;
        $first = true;
        while ($offset < $length) {
            if (!$first) $frame .= chr(0xC0 | ($csid & 0x3F)); // fmt3续块头
            $take = min($this->chunkSizeW, $length - $offset);
            $frame .= substr($payload, $offset, $take);
            $offset += $take;
            $first = false;
        }
        $this->ctrlOutBuffer .= $frame;
    }

    private function queueInvoke(string $command, int $transId, $commandObject, array $arguments): void
    {
        $payload = self::amfString($command) . self::amfNumber($transId);
        $payload .= $commandObject === null ? self::amfNull() : self::amfObject($commandObject);
        foreach ($arguments as $arg) $payload .= $this->amfValue($arg);
        $this->queueRtmpMessage(3, 0x14, 0, $payload, 0);
    }

    private function amfValue($value): string
    {
        if (is_int($value) || is_float($value)) return self::amfNumber((float)$value);
        if (is_bool($value)) return chr(0x01) . chr($value ? 1 : 0);
        if (is_null($value)) return self::amfNull();
        return self::amfString((string)$value);
    }

    private static function amfNumber(float $n): string
    {
        return chr(0x00) . pack('J', unpack('Q', pack('d', $n))[1]);
    }

    private static function amfString(string $s): string
    {
        return chr(0x02) . pack('n', strlen($s)) . $s;
    }

    private static function amfNull(): string
    {
        return chr(0x05);
    }

    /**
     * @param array<string,mixed> $obj
     */
    private static function amfObject(array $obj): string
    {
        $out = chr(0x03);
        foreach ($obj as $k => $v) {
            $out .= pack('n', strlen((string)$k)) . $k;
            if (is_int($v) || is_float($v)) {
                $out .= self::amfNumber((float)$v);
            } elseif (is_bool($v)) {
                $out .= chr(0x01) . chr($v ? 1 : 0);
            } elseif (is_null($v)) {
                $out .= self::amfNull();
            } else {
                $out .= self::amfString((string)$v);
            }
        }
        return $out . "\x00\x00\x09"; // object end
    }

    private static function u32BE(int $v): string
    {
        return chr(($v >> 24) & 0xFF) . chr(($v >> 16) & 0xFF)
            . chr(($v >> 8) & 0xFF) . chr($v & 0xFF);
    }

    // ================= RTMP chunk 增量重组 =================

    /**
     * 从netBuffer增量解析完整RTMP消息；返回本轮解析到的invoke信息列表
     * （connect命令阶段用），媒体/控制消息在内部直接处理。
     *
     * @return array<int,array{name:?string,trans:mixed,args:array}>
     */
    private function parseRtmpMessages(): array
    {
        $invokes = [];
        $buf = $this->netBuffer;
        $len = strlen($buf);
        $off = 0;

        while ($off < $len) {
            // —— 情况A：上一轮TCP读截断在某个chunk的payload中间，线上紧接着是裸payload（无块头）——
            if ($this->rtmpMidCsid !== null) {
                $csid = $this->rtmpMidCsid;
                if (!isset($this->rtmpCur[$csid])) { $this->rtmpMidCsid = null; continue; }
                $cur = $this->rtmpCur[$csid];
                $take = min($cur['chunkRemain'], $len - $off);
                if ($take > 0) {
                    $cur['data'] .= substr($buf, $off, $take);
                    $cur['read'] += $take;
                    $cur['chunkRemain'] -= $take;
                    $off += $take;
                }
                if ($cur['read'] >= $cur['len']) {
                    $this->finishRtmpMessage($csid, $cur, $invokes);
                    $this->rtmpMidCsid = null;
                } elseif ($cur['chunkRemain'] === 0) {
                    // 当前chunk恰好收满，后续线上是fmt3续块头
                    $this->rtmpCur[$csid] = $cur;
                    $this->rtmpMidCsid = null;
                } else {
                    $this->rtmpCur[$csid] = $cur;
                }
                continue;
            }

            $start = $off;

            // 基本头：fmt(2bit) + chunk stream id(6bit)，csid 0/1 为扩展形式
            if ($off + 1 > $len) break;
            $firstByte = ord($buf[$off++]);
            $fmt = $firstByte >> 6;
            $csid = $firstByte & 0x3F;
            if ($csid === 0) {
                if ($off + 1 > $len) { $off = $start; break; }
                $csid = 64 + ord($buf[$off++]);
            } elseif ($csid === 1) {
                if ($off + 2 > $len) { $off = $start; break; }
                $csid = 64 + ord($buf[$off]) + ord($buf[$off + 1]) * 256;
                $off += 2;
            }

            $cur = $this->rtmpCur[$csid] ?? null;

            if ($cur !== null && $cur['read'] < $cur['len']) {
                // 该csid有未收完的消息：此处只允许fmt3续块头
                if ($fmt !== 3) {
                    // 协议宽容：服务端在未完成消息上开新消息（理论上违规），丢弃旧重组按新消息解析
                    unset($this->rtmpCur[$csid]);
                    $cur = null;
                } else {
                    // 续块：若该消息使用扩展时间戳，每个续块头后仍带4字节扩展时间戳，忽略其值
                    if ($cur['ext']) {
                        if ($off + 4 > $len) { $off = $start; break; }
                        $off += 4;
                    }
                    $cur['chunkRemain'] = min($this->chunkSizeR, $cur['len'] - $cur['read']);
                }
            }

            if ($cur === null) {
                // 新消息：按fmt读消息头，未继承字段沿用上一条消息
                $headerLen = [11, 7, 3, 0][$fmt];
                if ($off + $headerLen > $len) { $off = $start; break; }
                $last = $this->rtmpLast[$csid] ?? null;
                if ($fmt !== 0 && $last === null) {
                    throw new RuntimeException("RTMP块头缺少继承信息: fmt={$fmt} csid={$csid}");
                }
                $ts = 0; $msgLen = 0; $msgType = 0; $msgSid = 0; $ext = false;
                if ($fmt === 0) {
                    $ts24 = (ord($buf[$off]) << 16) | (ord($buf[$off + 1]) << 8) | ord($buf[$off + 2]);
                    $msgLen = (ord($buf[$off + 3]) << 16) | (ord($buf[$off + 4]) << 8) | ord($buf[$off + 5]);
                    $msgType = ord($buf[$off + 6]);
                    $msgSid = ord($buf[$off + 7]) | (ord($buf[$off + 8]) << 8)
                        | (ord($buf[$off + 9]) << 16) | (ord($buf[$off + 10]) << 24);
                    $ext = $ts24 === 0xFFFFFF;
                    if ($ext) {
                        if ($off + 15 > $len) { $off = $start; break; }
                        $ts = (ord($buf[$off + 11]) << 24) | (ord($buf[$off + 12]) << 16)
                            | (ord($buf[$off + 13]) << 8) | ord($buf[$off + 14]);
                    } else {
                        $ts = $ts24;
                    }
                } elseif ($fmt === 1) {
                    $delta24 = (ord($buf[$off]) << 16) | (ord($buf[$off + 1]) << 8) | ord($buf[$off + 2]);
                    $msgLen = (ord($buf[$off + 3]) << 16) | (ord($buf[$off + 4]) << 8) | ord($buf[$off + 5]);
                    $msgType = ord($buf[$off + 6]);
                    $msgSid = $last['sid'];
                    $ext = $delta24 === 0xFFFFFF;
                    if ($ext) {
                        if ($off + 11 > $len) { $off = $start; break; }
                        $delta = (ord($buf[$off + 7]) << 24) | (ord($buf[$off + 8]) << 16)
                            | (ord($buf[$off + 9]) << 8) | ord($buf[$off + 10]);
                    } else {
                        $delta = $delta24;
                    }
                    $ts = $last['ts'] + $delta;
                } elseif ($fmt === 2) {
                    $delta24 = (ord($buf[$off]) << 16) | (ord($buf[$off + 1]) << 8) | ord($buf[$off + 2]);
                    $msgLen = $last['len'];
                    $msgType = $last['type'];
                    $msgSid = $last['sid'];
                    $ext = $delta24 === 0xFFFFFF;
                    if ($ext) {
                        if ($off + 7 > $len) { $off = $start; break; }
                        $delta = (ord($buf[$off + 3]) << 24) | (ord($buf[$off + 4]) << 16)
                            | (ord($buf[$off + 5]) << 8) | ord($buf[$off + 6]);
                    } else {
                        $delta = $delta24;
                    }
                    $ts = $last['ts'] + $delta;
                } else {
                    // fmt3新消息：完整继承上一条消息头
                    $ts = $last['ts'];
                    $msgLen = $last['len'];
                    $msgType = $last['type'];
                    $msgSid = $last['sid'];
                }
                $off += $headerLen + ($ext ? 4 : 0);
                $cur = [
                    'ts' => $ts, 'len' => $msgLen, 'type' => $msgType, 'sid' => $msgSid,
                    'data' => '', 'read' => 0, 'ext' => $ext,
                    'chunkRemain' => min($this->chunkSizeR, $msgLen),
                ];
                // 关键：块头已从线上消费，立即持久化重组态；即使本轮没有任何payload字节也不丢
                $this->rtmpCur[$csid] = $cur;
            }

            // 块payload（可能被TCP边界截断：取本轮实际可用字节，状态留到下一轮裸续传）
            $need = min($cur['chunkRemain'], $cur['len'] - $cur['read']);
            if ($need <= 0) {
                // 零长度消息（规范中不存在，宽容处理，直接完成以免断流）
                $this->finishRtmpMessage($csid, $cur, $invokes);
                continue;
            }
            $take = min($need, $len - $off);
            if ($take > 0) {
                $cur['data'] .= substr($buf, $off, $take);
                $cur['read'] += $take;
                $cur['chunkRemain'] -= $take;
                $off += $take;
            }

            if ($cur['read'] >= $cur['len']) {
                // 消息完整：更新继承头并分发
                $this->finishRtmpMessage($csid, $cur, $invokes);
            } elseif ($take < $need) {
                // TCP截断在chunk payload中间：下一轮线上直接是剩余payload
                $this->rtmpCur[$csid] = $cur;
                $this->rtmpMidCsid = $csid;
                break;
            } else {
                // 刚好收满一个chunk但消息未完：下一轮线上是fmt3续块头
                $this->rtmpCur[$csid] = $cur;
            }
        }

        $this->netBuffer = $off > 0 ? substr($buf, $off) : $buf;
        return $invokes;
    }

    /**
     * 一条RTMP消息重组完成：更新继承头、清理重组态并分发
     *
     * @param array{ts:int,len:int,type:int,sid:int,data:string,read:int,ext:bool,chunkRemain:int} $cur
     * @param array<int,array{name:?string,trans:mixed,args:array}> $invokes
     */
    private function finishRtmpMessage(int $csid, array $cur, array &$invokes): void
    {
        $this->rtmpLast[$csid] = [
            'ts' => $cur['ts'], 'len' => $cur['len'], 'type' => $cur['type'],
            'sid' => $cur['sid'], 'ext' => $cur['ext'],
        ];
        unset($this->rtmpCur[$csid]);
        if ($this->rtmpMidCsid === $csid) $this->rtmpMidCsid = null;
        $invoke = $this->dispatchRtmpMessage($cur['type'], $cur['ts'], $cur['data']);
        if ($invoke !== null) $invokes[] = $invoke;
    }

    /**
     * 分发一条完整RTMP消息：协议控制就地应答；音视频进GOP跳帧/IPC通道；
     * invoke(AMF0/AMF3)解析后返回信息供命令阶段等待结果
     *
     * @return array{name:?string,trans:mixed,args:array}|null
     */
    private function dispatchRtmpMessage(int $type, int $timestamp, string $payload): ?array
    {
        switch ($type) {
            case 0x01: // Set Chunk Size
                $size = (self::readU32($payload, 0) & 0x7FFFFFFF);
                if ($size > 0 && $size <= 0xFFFFFF) $this->chunkSizeR = $size;
                return null;

            case 0x02: // Abort Message
            case 0x03: // Acknowledgement
                return null;

            case 0x04: // User Control Message
                if (strlen($payload) >= 6) {
                    $event = (ord($payload[0]) << 8) | ord($payload[1]);
                    if ($event === 6) {
                        // Ping Request → Ping Response(事件7)，原样回4字节服务端时间
                        $this->queueProtocolControl(0x04, chr(0x00) . chr(0x07) . substr($payload, 2, 4));
                    }
                }
                return null;

            case 0x05: // Window Acknowledgement Size
                if (strlen($payload) >= 4) $this->windowAckSize = self::readU32($payload, 0);
                return null;

            case 0x06: // Set Peer Bandwidth
                return null;

            case self::AUDIO_TAG:
                $this->handleTag(self::AUDIO_TAG, $payload, $timestamp);
                return null;

            case self::VIDEO_TAG:
                $this->handleTag(self::VIDEO_TAG, $payload, $timestamp);
                return null;

            case 0x12: // Data AMF0（onMetaData等），转码不需要
                return null;

            case 0x0F: // Data AMF3（部分服务器元数据走此类型，首字节AMF3标记）
            case 0x16: // Aggregate，直播不使用
                return null;

            case 0x14: // AMF0 invoke
                return $this->decodeInvoke($payload);

            case 0x11: // AMF3 invoke：首字节0x00后按AMF0解析
                if ($payload !== '' && ord($payload[0]) === 0x00) $payload = substr($payload, 1);
                return $this->decodeInvoke($payload);

            default:
                return null;
        }
    }

    /**
     * @return array{name:?string,trans:mixed,args:array}|null
     */
    private function decodeInvoke(string $payload): ?array
    {
        $decoder = new RtmpAmf0Reader($payload);
        $name = $decoder->read();
        if (!is_string($name) || $name === '') return null;
        $trans = $decoder->read();
        $decoder->read(); // command object（通常null/object，忽略）
        $args = [];
        while (true) {
            $v = $decoder->read();
            if ($v === null && $decoder->eof()) break;
            $args[] = $v;
            if ($decoder->eof()) break;
        }
        // 服务器→客户端的onStatus错误（如流不存在）：直接抛出触发重连
        if ($name === 'onStatus' && isset($args[0]) && is_array($args[0])
            && strcasecmp((string)($args[0]['level'] ?? ''), 'error') === 0) {
            throw new RuntimeException('RTMP onStatus错误: ' . ($args[0]['code'] ?? '')
                . ' ' . ($args[0]['description'] ?? ''));
        }
        return ['name' => $name, 'trans' => $trans, 'args' => $args];
    }

    private static function readU32(string $b, int $o): int
    {
        return (ord($b[$o]) << 24) | (ord($b[$o + 1]) << 16) | (ord($b[$o + 2]) << 8) | ord($b[$o + 3]);
    }

    // ================= tag分发：序列头缓存 / GOP跳帧 / IPC帧化 =================

    private function isAvcSequenceHeader(string $body): bool
    {
        return strlen($body) >= 2 && (ord($body[0]) & 0x0F) === 7 && ord($body[1]) === 0x00;
    }

    private function isIdrNalu(string $body): bool
    {
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

        if ($tagType === self::VIDEO_TAG && $this->isAvcSequenceHeader($body)) {
            $this->videoSeqHeader = $body;
        } elseif ($tagType === self::AUDIO_TAG && $this->isAacSequenceHeader($body)) {
            $this->audioSeqHeader = $body;
        }

        if ($this->skipMode) {
            $this->tagsDropped++;
            if ($tagType === self::VIDEO_TAG && $this->isIdrNalu($body)) {
                $this->resyncAtIdr($body, $srcTimestamp);
            }
            return;
        }

        $isSeq = $this->isSequenceTag($tagType, $body);
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
        $this->ipcBuffer = '';
    }

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
        if (!$isSeq && $fed < $this->lastFedTimestamp) $fed = max(0, $this->lastFedTimestamp);
        $this->frameToBuffer($tagType, $body, $fed);
        if (!$isSeq) $this->lastFedTimestamp = $fed;
    }

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
        $this->log('[拉流] RTMP拉流子进程结束统计');
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
