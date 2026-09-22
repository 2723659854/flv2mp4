<?php

namespace Xiaosongshu\Flv2mp4\Manage;

use RuntimeException;
use Xiaosongshu\Flv2mp4\Recode\PurePhpHlsGenerator;

/**
 * FLV直播拉流转码压缩客户端（独立部署）—— 转码主进程
 *
 * 双进程模型，确保CPU密集的转码绝不阻塞上游直播服务器：
 *
 *   上游直播服务器 ──HTTP/WS-FLV──▶ [拉流子进程 FlvStreamPuller]
 *                                        │ 持续读空上游（永不反压）
 *                                        │ 本地TCP帧（tag/时间戳）
 *                                        ▼ 积压超限则IDR边界跳帧追直播
 *                                  [转码主进程（本类）]
 *                                        │ 纯PHP解码->缩放->H264重编码
 *                                        ▼
 *                                     HLS切片
 *
 *  - 本进程启动时随机监听 127.0.0.1 端口，proc_open 拉起 bin/flv2hls-puller.php
 *  - 主进程只做转码：收帧 -> PurePhpHlsGenerator，慢一点没关系，拉流进程会跳帧
 *  - 拉流进程默认重连5次，耗尽后发END帧，主进程冲刷末帧、写ENDLIST后退出
 *  - Ctrl+C/SIGTERM 由主进程统一优雅收尾，拉流进程忽略信号、收尾后被终止
 *
 * 仅支持 H264 + AAC 源。属CPU密集型服务，应与直播服务器分机部署。
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

    // ===== 拉流子进程参数 =====
    private int $maxRetries = 5;
    private int $retryDelay = 3;
    private int $connectTimeout = 10;
    private int $idleTimeout = 30;
    private int $queueMaxBytes = 8388608; // 转发积压上限8MB，超过拉流端跳帧追直播
    private int $duration = 0;            // 0=不限时
    private bool $tlsVerify = true;

    private bool $running = true;
    private bool $stopSignaled = false;

    /** @var resource|null IPC监听socket */
    private $server = null;
    /** @var resource|null 拉流子进程IPC连接 */
    private $ipc = null;
    /** @var resource|null proc_open进程句柄 */
    private $process = null;
    /** @var array proc_open管道 */
    private array $procPipes = [];

    // ===== IPC帧读取缓冲 =====
    private string $readBuffer = '';
    private bool $endReceived = false;

    // ===== 信用回报（拉流端按消费节拍发数据，防止落后量堆积在内核管道看不见） =====
    private string $creditBuffer = '';
    private int $consumedBytes = 0;

    // ===== 统计 =====
    private int $tagsFed = 0;
    private int $videoTags = 0;
    private int $audioTags = 0;
    private float $startMicrotime;
    private float $lastStatsMicrotime = 0.0;

    /**
     * @param string $pullUrl 直播地址 http(s)-flv / ws(s)-flv
     * @param array $config 转码配置：width/height(0=保持)/bitrate/fps(仅编码器)/qp/audioBitrate/
     *                      motionWorkers/watermark/watermark_file/segmentDuration；
     *                      部署配置：outputDir/streamName/maxRetries/retryDelay/connectTimeout/
     *                      idleTimeout/queueMaxBytes(转码落后容忍字节,默认8MB)/duration/tlsVerify
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
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, [$this, 'handleSignal']);
            pcntl_signal(SIGTERM, [$this, 'handleSignal']);
        }
        if (function_exists('sapi_windows_set_ctrl_handler')) {
            // Windows无pcntl：注册处理器拦截Ctrl+C/Break，回调在独立线程，只置标量标志
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
     * 启动转码客户端（阻塞运行：拉起拉流子进程 → 收帧转码 → 优雅收尾）
     */
    public function run(): void
    {
        $this->startMicrotime = microtime(true);
        $this->lastStatsMicrotime = $this->startMicrotime;
        $this->log('========================================');
        $this->log('FLV拉流转码HLS客户端 v2.0（拉流/转码双进程）');
        $this->log("拉流地址: {$this->pullUrl}");
        $this->log('协议: ' . ($this->isWebSocket ? ($this->isSsl ? 'WSS-FLV' : 'WS-FLV') : ($this->isSsl ? 'HTTPS-FLV' : 'HTTP-FLV')));
        $this->log("输出目录: {$this->streamDir}");
        $this->log("拉流进程最大重连: {$this->maxRetries} 次，转码落后容忍: " . round($this->queueMaxBytes / 1048576, 1) . ' MB（超限跳IDR追直播，不反压上游）');
        $this->log('========================================');

        try {
            $port = $this->startIpcServer();
            $this->spawnPuller($port);
            $this->acceptPuller($port);
            $this->transcodeLoop();
        } catch (\Throwable $e) {
            $this->log('客户端异常: ' . $e->getMessage(), 'error');
        } finally {
            if ($this->stopSignaled) {
                $this->log('收到停止信号，正在冲刷末帧、关闭分片并写入ENDLIST...');
            } elseif ($this->endReceived) {
                $this->log('上游已结束，正在冲刷末帧、关闭分片...');
            }
            try {
                $this->generator->finishStream();
            } catch (\Throwable $e) {
                $this->log('收尾异常: ' . $e->getMessage(), 'error');
            }
            $this->stopPuller();
            $this->closeIpc();
            $this->printStats();
        }
    }

    // ================= 双进程装配 =================

    private function startIpcServer(): int
    {
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) throw new RuntimeException("本地IPC监听失败: {$errstr} ({$errno})");
        $this->server = $server;
        return (int)substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
    }

    private function spawnPuller(int $port): void
    {
        $entry = dirname(__DIR__, 2) . '/bin/flv2hls-puller.php';
        $autoload = dirname((new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName(), 2) . '/autoload.php';
        $args = [
            PHP_BINARY, $entry,
            '--url=' . $this->pullUrl,
            '--port=' . $port,
            '--retries=' . $this->maxRetries,
            '--retry-delay=' . $this->retryDelay,
            '--connect-timeout=' . $this->connectTimeout,
            '--idle-timeout=' . $this->idleTimeout,
            '--lag-bytes=' . $this->queueMaxBytes,
            '--autoload=' . $autoload,
        ];
        if (!$this->tlsVerify) $args[] = '--insecure';

        $descriptors = [fopen('php://stdin', 'r'), fopen('php://stdout', 'a'), fopen('php://stderr', 'a')];
        $this->process = @proc_open($args, $descriptors, $this->procPipes, dirname(__DIR__, 2), null, ['bypass_shell' => true]);
        if (!is_resource($this->process)) throw new RuntimeException('拉起拉流子进程失败');
    }

    /**
     * 压缩IPC内核socket接收缓冲（Windows循环回连默认接收窗可达数MB，
     * 会掩盖转码落后、使拉流端迟迟不触发IDR跳帧）
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

    private function acceptPuller(int $port): void
    {
        stream_set_timeout($this->server, 15);
        $conn = @stream_socket_accept($this->server, 15);
        if ($conn === false) throw new RuntimeException('等待拉流子进程连接超时');
        stream_set_blocking($conn, false);
        $this->shrinkSocketBuffer($conn, 'rcvbuf');
        $this->ipc = $conn;
        // 只接受一个拉流连接，监听socket关闭
        @fclose($this->server);
        $this->server = null;
        // 发放初始窗口信用，拉流进程收到后才开始放行数据
        $this->grantCredit();
    }

    /**
     * 把已消费字节数作为信用回报给拉流进程（FRAME_CREDIT，u32BE累计值，回绕由对端处理）
     */
    private function grantCredit(): void
    {
        if (!is_resource($this->ipc)) return;
        $this->creditBuffer .= pack('N', 5)
            . chr(FlvStreamPuller::FRAME_CREDIT)
            . pack('N', $this->consumedBytes & 0xFFFFFFFF);
        $this->flushCredit();
    }

    private function flushCredit(): void
    {
        while ($this->creditBuffer !== '' && is_resource($this->ipc)) {
            $n = @fwrite($this->ipc, $this->creditBuffer);
            if ($n === false || $n === 0) return; // 反向通道暂不可写，下个select周期续发
            $this->creditBuffer = substr($this->creditBuffer, $n);
        }
    }

    private function stopPuller(): void
    {
        if (!is_resource($this->process)) return;
        $status = proc_get_status($this->process);
        if ($status['running']) {
            // 拉流进程忽略SIGINT/Ctrl+C，这里统一终止（Windows为TerminateProcess）
            @proc_terminate($this->process);
        }
        @proc_close($this->process);
        $this->process = null;
    }

    private function closeIpc(): void
    {
        if (is_resource($this->ipc)) {
            @stream_socket_shutdown($this->ipc, STREAM_SHUT_RDWR);
            @fclose($this->ipc);
        }
        $this->ipc = null;
        if (is_resource($this->server)) {
            @fclose($this->server);
            $this->server = null;
        }
    }

    // ================= 转码主循环 =================

    private function transcodeLoop(): void
    {
        while ($this->running) {
            if ($this->duration > 0 && microtime(true) - $this->startMicrotime >= $this->duration) {
                $this->log("已达到运行时长 {$this->duration} 秒，停止");
                $this->running = false;
                return;
            }

            $read = [$this->ipc];
            $write = $this->creditBuffer !== '' ? [$this->ipc] : [];
            $except = null;
            // 转码期间不拉select空转；无数据时1秒醒一次以响应信号/时长
            if (@stream_select($read, $write, $except, 1) === false) {
                if (!is_resource($this->ipc) || feof($this->ipc)) {
                    $this->running = false;
                    return;
                }
                continue;
            }
            if (in_array($this->ipc, $read, true)) $this->onIpcReadable();
            if (in_array($this->ipc, $write, true)) $this->flushCredit();
            if ($this->endReceived) {
                $this->running = false;
                return;
            }
            $this->maybePrintStats();
        }
    }

    private function onIpcReadable(): void
    {
        $chunk = @fread($this->ipc, 65536);
        if ($chunk === false || ($chunk === '' && feof($this->ipc))) {
            // 拉流子进程关闭：重连耗尽时END帧会先到；异常退出时直接收尾
            $this->running = false;
            return;
        }
        if ($chunk === '') return;
        $this->readBuffer .= $chunk;
        $this->drainFrames();
    }

    /**
     * 解析IPC帧：u32BE总长 + [type:1][payload]
     * type=1 媒体tag：[tagType:1][timestamp:4BE][body]
     * type=2 上游结束
     */
    private function drainFrames(): void
    {
        $buf = $this->readBuffer;
        $newlyConsumed = 0;
        while (strlen($buf) >= 4) {
            $frameLen = unpack('N', substr($buf, 0, 4))[1];
            if ($frameLen < 1 || strlen($buf) < 4 + $frameLen) break;
            $frame = substr($buf, 4, $frameLen);
            $buf = substr($buf, 4 + $frameLen);
            $newlyConsumed += 4 + $frameLen;

            $type = ord($frame[0]);
            $payload = substr($frame, 1);
            if ($type === FlvStreamPuller::FRAME_END) {
                $this->endReceived = true;
                continue;
            }
            if ($type !== FlvStreamPuller::FRAME_TAG || strlen($payload) < 5) continue;

            $tagType = ord($payload[0]);
            $timestamp = unpack('N', substr($payload, 1, 4))[1];
            $body = substr($payload, 5);
            $this->feedTranscoder($tagType, $body, $timestamp);
        }
        $this->readBuffer = $buf;
        // 本批已全部喂入编码器后回报信用（在途的定义止于送编码器，编码耗时本身即落后量）
        if ($newlyConsumed > 0) {
            $this->consumedBytes += $newlyConsumed;
            $this->grantCredit();
        }
    }

    private function feedTranscoder(int $tagType, string $body, int $timestamp): void
    {
        $this->tagsFed++;
        if ($tagType === 9) $this->videoTags++; else $this->audioTags++;

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
        $this->log(sprintf(
            '[转码] 已转 %d tags (v%d/a%d) | %.1f tags/s',
            $this->tagsFed, $this->videoTags, $this->audioTags,
            $this->tagsFed / $elapsed
        ), 'progress');
    }

    private function printStats(): void
    {
        $elapsed = microtime(true) - $this->startMicrotime;
        $this->log('========================================');
        $this->log('转码结束统计');
        $this->log('总耗时: ' . round($elapsed, 1) . "s，送转码tag: {$this->tagsFed} (视频{$this->videoTags}/音频{$this->audioTags})");
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
