<?php

namespace Xiaosongshu\Flv2mp4\Manage;

use RuntimeException;
use Xiaosongshu\Flv2mp4\Recode\HlsPipelineProtocol;
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

    private ?PurePhpHlsGenerator $generator = null;
    private string $streamDir;

    /**
     * GOP分片解码流水线worker数：
     *   0 = 旧的主进程内串行解码（回退用）；>=1 = 解码+缩放下沉到N个子进程按GOP并行，
     *   输出worker负责编码/TS/HLS，主进程只做拉流转发与端到端反压
     */
    private int $decodeWorkers = 2;
    private array $workerProfile = [];

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
    private int $lastStatsTags = 0;

    // ===== GOP分片解码流水线运行态 =====
    /** @var array<int,resource> worker进程句柄 */
    private array $plProcesses = [];
    /** @var array<int,resource> 主进程→各解码worker的socket */
    private array $plSocks = [];
    /** @var array<int,resource> 主进程→各解码worker的独立控制socket（finish屏障专用，不被媒体积压阻塞） */
    private array $plCtrl = [];
    /** @var resource|null 主进程→输出worker的独立控制socket（finish屏障直达，媒体流中插入会切断半帧） */
    private $plOutCtrl = null;
    /** @var array<int,string> 待写入各解码worker的缓冲 */
    private array $plOut = [];
    /** @var array<int,string> 各解码worker回报缓冲 */
    private array $plIn = [];
    private array $plDead = [];
    private int $plSeq = 0;
    private int $plGopSeq = 0;
    private int $plCurrentWorker = 0;
    private int $plFinished = 0;
    private bool $plEndSent = false;

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
        $this->decodeWorkers = max(0, (int)($config['decodeWorkers'] ?? 2));

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

        if ($this->decodeWorkers > 0) {
            // 流水线模式：profile 随 worker 启动参数下发，切片时长一并透传给 worker
            $profile['segmentDuration'] = $segmentDuration > 0 ? $segmentDuration : 3;
            // 缩放由解码worker并行完成：缩放是纯PHP重操作（768x432→640x360约80-160ms/帧），
            // 放在输出进程会成为串行瓶颈（实测仅~5fps），2个decoder并行缩放下放到~10fps以上
            $this->workerProfile = ['' => $profile];
        } else {
            $this->generator = new PurePhpHlsGenerator($profile, rtrim($this->streamDir, '/'), false);
            $this->generator->setSegmentDuration($segmentDuration > 0 ? $segmentDuration : 3);
        }

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
        $scheme = strtolower(parse_url($this->pullUrl, PHP_URL_SCHEME) ?: 'http');
        $protocolLabel = str_starts_with($scheme, 'rtmp') ? 'RTMP'
            : ($this->isWebSocket ? ($this->isSsl ? 'WSS-FLV' : 'WS-FLV')
            : ($this->isSsl ? 'HTTPS-FLV' : 'HTTP-FLV'));
        $this->log('协议: ' . $protocolLabel);
        $this->log("输出目录: {$this->streamDir}");
        $this->log("拉流进程最大重连: {$this->maxRetries} 次，转码落后容忍: " . round($this->queueMaxBytes / 1048576, 1) . ' MB（超限跳IDR追直播，不反压上游）');
        $this->log('========================================');

        $parallel = $this->decodeWorkers > 0;
        try {
            $port = $this->startIpcServer();
            $this->spawnPuller($port);
            if ($parallel) $this->startPipeline();
            $this->acceptPuller($port);
            if ($parallel) $this->pipelineLoop();
            else $this->transcodeLoop();
        } catch (\Throwable $e) {
            $this->log('客户端异常: ' . $e->getMessage(), 'error');
        } finally {
            if ($parallel) {
                if ($this->stopSignaled || $this->endReceived) {
                    $this->log('正在冲刷末帧、关闭分片并写入ENDLIST...');
                }
                try {
                    $this->shutdownPipeline();
                } catch (\Throwable $e) {
                    $this->log('流水线收尾异常: ' . $e->getMessage(), 'error');
                }
            } else {
                if ($this->stopSignaled) {
                    $this->log('收到停止信号，正在冲刷末帧、关闭分片并写入ENDLIST...');
                } elseif ($this->endReceived) {
                    $this->log('上游已结束，正在冲刷末帧、关闭分片...');
                }
                try {
                    $this->generator?->finishStream();
                } catch (\Throwable $e) {
                    $this->log('收尾异常: ' . $e->getMessage(), 'error');
                }
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
        // rtmp:// 走独立的RTMP拉流子进程；http(s)-flv/ws(s)-flv 走原FLV拉流子进程（两者IPC协议一致）
        $entryFile = str_starts_with(strtolower($this->pullUrl), 'rtmp://')
            ? 'bin/flv2hls-rtmp-puller.php'
            : 'bin/flv2hls-puller.php';
        $entry = dirname(__DIR__, 2) . '/' . $entryFile;
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
            // 注意：Windows PHP 秒级超时(1s)下select可写/可读唤醒会退化到约2次/秒，
            // 必须用毫秒级超时（实测2ms可恢复正常吞吐，idle时每秒500次唤醒开销可忽略）
            if (@stream_select($read, $write, $except, 0, 1) === false) {
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

    private function onIpcReadable(bool $pipeline = false): void
    {
        $chunk = @fread($this->ipc, 65536);
        if ($chunk === false || ($chunk === '' && feof($this->ipc))) {
            // 拉流子进程关闭：重连耗尽时END帧会先到；异常退出时直接收尾
            $this->running = false;
            return;
        }
        if ($chunk === '') return;
        $this->readBuffer .= $chunk;
        $this->drainFrames($pipeline);
    }

    /**
     * 解析IPC帧：u32BE总长 + [type:1][payload]
     * type=1 媒体tag：[tagType:1][timestamp:4BE][body]
     * type=2 上游结束
     */
    private function drainFrames(bool $pipeline = false): void
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
            if ($pipeline) $this->plEnqueueTag($tagType, $body, $timestamp);
            else $this->feedTranscoder($tagType, $body, $timestamp);
        }
        $this->readBuffer = $buf;
        // 本批已全部放行后回报信用（流水线模式下"在途"含各worker出站缓冲，积压由转发反压消化）
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

    // ================= GOP分片解码流水线 =================

    /**
     * 拉起 1个输出worker + N个解码worker（复用文件转码的分布式架构）
     * 拓扑：主进程 → 解码worker(GOP轮询,各自解码+缩放) → 输出worker(编码+TS+HLS)
     */
    private function startPipeline(): void
    {
        $n = $this->decodeWorkers;
        $autoload = dirname((new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName(), 2) . '/autoload.php';
        $entry = dirname(__DIR__, 2) . '/bin/hls-worker.php';
        $profilesOpt = base64_encode(json_encode($this->workerProfile, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        [, $outputPort] = $this->reserveAddress();
        [, $outputCtrlPort] = $this->reserveAddress();
        $decoderPorts = [];
        $controlPorts = [];
        for ($i = 0; $i < $n; $i++) {
            [, $decoderPorts[]] = $this->reserveAddress();
            [, $controlPorts[]] = $this->reserveAddress();
        }

        // 输出worker先启动（内部预热运动估计子进程并等待decoder接入）
        $this->startWorker($entry, [
            '--mode', 'output',
            '--autoload', $autoload,
            '--port', (string)$outputPort,
            '--control-port', (string)$outputCtrlPort,
            '--workers', (string)$n,
            '--profiles', $profilesOpt,
            '--output', rtrim($this->streamDir, '/\\') . '/',
        ]);
        for ($i = 0; $i < $n; $i++) {
            $this->startWorker($entry, [
                '--mode', 'decoder',
                '--autoload', $autoload,
                '--port', (string)$decoderPorts[$i],
                '--control-port', (string)$controlPorts[$i],
                '--output-port', (string)$outputPort,
                '--profiles', $profilesOpt,
            ]);
            $this->plOut[$i] = '';
            $this->plIn[$i] = '';
        }

        // 连接所有解码worker（媒体+控制）及输出worker控制连接（冷启动并发轮询等待就绪）
        $pending = [];
        for ($i = 0; $i < $n; $i++) {
            $pending[] = [0, $decoderPorts[$i], $i];
            $pending[] = [1, $controlPorts[$i], $i];
        }
        $pending[] = [2, $outputCtrlPort, -1];
        $deadline = microtime(true) + 20;
        while ($pending !== []) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('流水线worker连接超时: ' . implode(',', array_map(static fn($p) => ($p[0] === 0 ? 'media' : ($p[0] === 1 ? 'ctrl' : 'outctrl')) . ':' . $p[1], $pending)));
            }
            foreach ($pending as $idx => $item) {
                [$kind, $port, $id] = $item;
                $sock = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 0.1);
                if ($sock === false) continue;
                stream_set_blocking($sock, false);
                if ($kind === 0) $this->plSocks[$id] = $sock;
                elseif ($kind === 1) $this->plCtrl[$id] = $sock;
                else $this->plOutCtrl = $sock;
                unset($pending[$idx]);
            }
            if ($pending !== []) usleep(1);
        }
        ksort($this->plSocks);
        $this->plSocks = array_values($this->plSocks);
        ksort($this->plCtrl);
        $this->plCtrl = array_values($this->plCtrl);
        $this->log("GOP分片流水线就绪：{$n} 个解码worker + 1 个输出worker");
    }

    private function reserveAddress(): array
    {
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) throw new RuntimeException("本地端口分配失败: {$errstr} ({$errno})");
        $name = stream_socket_get_name($server, false);
        fclose($server);
        return ['tcp://' . $name, (int)substr(strrchr($name, ':'), 1)];
    }

    private function startWorker(string $entry, array $args): void
    {
        $descriptors = [fopen('php://stdin', 'r'), fopen('php://stdout', 'a'), fopen('php://stderr', 'a')];
        $options = ['bypass_shell' => true];
        if (PHP_OS_FAMILY === 'Windows') $options['create_process_group'] = true;
        $p = @proc_open(array_merge([PHP_BINARY, $entry], $args), $descriptors, $pipes, dirname(__DIR__, 2), null, $options);
        if (!is_resource($p)) throw new RuntimeException('拉起HLS worker失败: ' . implode(' ', $args));
        $this->plProcesses[] = $p;
    }

    private function pipelineLoop(): void
    {
        while ($this->running) {
            if ($this->duration > 0 && microtime(true) - $this->startMicrotime >= $this->duration) {
                $this->log("已达到运行时长 {$this->duration} 秒，停止");
                $this->running = false;
            }

            $outBytes = 0;
            foreach ($this->plOut as $buf) $outBytes += strlen($buf);
            $canReadPuller = !$this->plEndSent && $outBytes < $this->queueMaxBytes && is_resource($this->ipc);

            $read = [];
            if ($canReadPuller) $read['puller'] = $this->ipc;
            foreach ($this->plSocks as $id => $sock) {
                if (!isset($this->plDead[$id])) $read[$id] = $sock;
            }
            $write = [];
            if ($this->creditBuffer !== '' && is_resource($this->ipc)) $write['puller'] = $this->ipc;
            foreach ($this->plOut as $id => $buf) {
                if ($buf !== '' && !isset($this->plDead[$id])) $write[$id] = $this->plSocks[$id];
            }
            $except = null;
            // 毫秒级超时：Windows PHP 秒级select唤醒退化（见transcodeLoop注释）
            if (@stream_select($read, $write, $except, 0, 1) === false) continue;

            foreach ($write as $key => $sock) {
                if ($key === 'puller') {
                    $this->flushCredit();
                    continue;
                }
                $n = @fwrite($sock, substr($this->plOut[$key], 0, 262144));
                if ($n === false || ($n === 0 && feof($sock))) {
                    if (!$this->plEndSent) throw new RuntimeException("解码worker#{$key} 写入失败");
                    $this->plDead[$key] = true;
                    continue;
                }
                if ($n > 0) $this->plOut[$key] = substr($this->plOut[$key], $n);
            }
            foreach ($read as $key => $sock) {
                if ($key === 'puller') {
                    $this->onIpcReadable(true);
                    continue;
                }
                $this->onPipelineReadable((int)$key);
            }

            // 上游结束/主动停止：立即停拉流，并经独立控制连接广播finish屏障（快速收尾）：
            // 输出进程收齐屏障后丢弃十几秒直播尾部在途帧、只冲刷当前帧并写ENDLIST
            if (!$this->plEndSent && ($this->endReceived || !$this->running)) {
                $this->sendPipelineFinish();
                $this->stopPuller();
            }
            if ($this->plEndSent && $this->plFinished >= $this->decodeWorkers) {
                $this->running = false;
                return;
            }
            $this->maybePrintStats();
        }
    }

    private function onPipelineReadable(int $id): void
    {
        $sock = $this->plSocks[$id];
        $chunk = @fread($sock, 65536);
        if ($chunk === false || ($chunk === '' && feof($sock))) {
            if (!$this->plEndSent) throw new RuntimeException("解码worker#{$id} 意外退出");
            $this->plDead[$id] = true;
            return;
        }
        if ($chunk === '') return;
        $this->plIn[$id] .= $chunk;
        foreach (HlsPipelineProtocol::take($this->plIn[$id], PHP_INT_MAX) as $event) {
            switch ($event['type']) {
                case HlsPipelineProtocol::PROGRESS:
                    break; // GOP进度回报：直播不做窗口gate，忽略
                case HlsPipelineProtocol::FINISHED:
                    $this->plFinished++;
                    break;
                case HlsPipelineProtocol::ERROR:
                    throw new RuntimeException('流水线worker失败: ' . ($event['metadata']['message'] ?? '未知错误'));
            }
        }
    }

    /**
     * 拉流tag按GOP轮询分发到解码worker（与文件流水线同构）：
     * 视频序列头：worker0收EVENT，全体收CONTROL config；
     * IDR：新GOP轮转worker，前一worker补gopEnd；音频随当前GOP worker走。
     */
    private function plEnqueueTag(int $tagType, string $body, int $timestamp): void
    {
        $this->tagsFed++;
        if ($tagType === 9) $this->videoTags++;
        else $this->audioTags++;

        if ($tagType === 9 && strlen($body) >= 2 && (ord($body[0]) & 0x0f) === 7) {
            $packetType = ord($body[1]);
            if ($packetType === 0) {
                $this->plOut[0] .= HlsPipelineProtocol::frame(
                    HlsPipelineProtocol::EVENT, $this->plSeq++,
                    ['tagType' => 9, 'timestamp' => $timestamp], $body
                );
                $control = HlsPipelineProtocol::frame(HlsPipelineProtocol::CONTROL, 0, ['cmd' => 'config'], $body);
                for ($i = 0; $i < $this->decodeWorkers; $i++) $this->plOut[$i] .= $control;
                return;
            }
            if ($packetType === 1) {
                $isKey = ((ord($body[0]) >> 4) === 1) && $this->containsIdrNal($body);
                if ($isKey) {
                    if ($this->plGopSeq > 0) {
                        $prev = $this->plGopSeq - 1;
                        $this->plOut[$prev % $this->decodeWorkers] .= HlsPipelineProtocol::frame(
                            HlsPipelineProtocol::CONTROL, 0, ['cmd' => 'gopEnd', 'gop' => $prev]
                        );
                    }
                    $this->plCurrentWorker = $this->plGopSeq % $this->decodeWorkers;
                    $this->plGopSeq++;
                }
                $this->plOut[$this->plCurrentWorker] .= HlsPipelineProtocol::frame(
                    HlsPipelineProtocol::EVENT, $this->plSeq++,
                    ['tagType' => 9, 'timestamp' => $timestamp], $body
                );
                return;
            }
        }
        $this->plOut[$this->plCurrentWorker] .= HlsPipelineProtocol::frame(
            HlsPipelineProtocol::EVENT, $this->plSeq++,
            ['tagType' => $tagType, 'timestamp' => $timestamp], $body
        );
    }

    /**
     * 扫描AVCC视频包（跳过5字节FLV/AVC头），判断是否包含IDR NAL（type=5）
     */
    private function containsIdrNal(string $body): bool
    {
        $total = strlen($body);
        $off = 5;
        while ($off + 4 <= $total) {
            $length = unpack('N', substr($body, $off, 4))[1];
            $off += 4;
            if ($length <= 0 || $off + $length > $total) break;
            if ((ord($body[$off]) & 0x1f) === 5) return true;
            $off += $length;
        }
        return false;
    }

    /**
     * 发送finish屏障：输出worker经独立控制连接直达（立即丢尾部、冲刷1帧、写ENDLIST）；
     * 各解码worker同样经控制连接停止解码（释放CPU给收尾冲刷）。控制连接无媒体积压，即时到达。
     * 解码worker控制连接缺失/写入失败时退回媒体通道（decoder有长度前缀快扫兜底）
     */
    private function sendPipelineFinish(): void
    {
        $finishFrame = HlsPipelineProtocol::frame(HlsPipelineProtocol::CONTROL, 0, ['cmd' => 'finish']);
        if (is_resource($this->plOutCtrl)) @fwrite($this->plOutCtrl, $finishFrame);
        for ($i = 0; $i < $this->decodeWorkers; $i++) {
            $sent = false;
            if (isset($this->plCtrl[$i]) && is_resource($this->plCtrl[$i])) {
                $n = @fwrite($this->plCtrl[$i], $finishFrame);
                if ($n !== false && $n > 0) $sent = true;
            }
            if (!$sent) $this->plOut[$i] .= $finishFrame; // 媒体通道兜底（decoder快扫截获，仅令其停止解码）
        }
        $this->plEndSent = true;
    }

    private function shutdownPipeline(): void
    {
        $shutdownStart = microtime(true);
        // 兜底广播finish（正常情况下pipelineLoop已发），并限时等待FINISHED
        if (!$this->plEndSent) $this->sendPipelineFinish();
        $this->stopPuller();
        $deadline = microtime(true) + 15;
        while ($this->plFinished < $this->decodeWorkers && microtime(true) < $deadline) {
            $read = [];
            foreach ($this->plSocks as $id => $sock) {
                if (is_resource($sock) && !isset($this->plDead[$id])) $read[$id] = $sock;
            }
            $write = [];
            foreach ($this->plOut as $id => $buf) {
                if ($buf !== '' && !isset($this->plDead[$id]) && is_resource($this->plSocks[$id])) $write[$id] = $this->plSocks[$id];
            }
            if ($read === [] && $write === []) break;
            $except = null;
            if (@stream_select($read, $write, $except, 0, 1) === false) { usleep(1); continue; }
            foreach ($write as $id => $sock) {
                $n = @fwrite($sock, substr($this->plOut[$id], 0, 262144));
                if ($n === false || ($n === 0 && feof($sock))) { $this->plDead[$id] = true; continue; }
                if ($n > 0) $this->plOut[$id] = substr($this->plOut[$id], $n);
            }
            foreach ($read as $id => $sock) {
                try { $this->onPipelineReadable((int)$id); } catch (\Throwable) { $this->plDead[$id] = true; }
            }
        }
        foreach ($this->plSocks as $sock) if (is_resource($sock)) @fclose($sock);
        $this->plSocks = [];
        foreach ($this->plCtrl as $sock) if (is_resource($sock)) @fclose($sock);
        $this->plCtrl = [];
        if (is_resource($this->plOutCtrl)) @fclose($this->plOutCtrl);
        $this->plOutCtrl = null;
        foreach ($this->plProcesses as $p) {
            if (!is_resource($p)) continue;
            $status = @proc_get_status($p);
            if (($status['running'] ?? false)) @proc_terminate($p);
            @proc_close($p);
        }
        $this->plProcesses = [];
        $shutdownElapsed = microtime(true) - $shutdownStart;
        if ($this->plFinished >= $this->decodeWorkers) {
            $this->log(sprintf('收尾完成（%.2fs），ENDLIST 已写入', $shutdownElapsed));
        } else {
            $this->log(sprintf('收尾超时（%.1fs，缺少 %d 个worker确认），已强制关闭', $shutdownElapsed, $this->decodeWorkers - $this->plFinished), 'warning');
        }
    }

    // ================= 日志/统计 =================

    private function maybePrintStats(): void
    {
        $now = microtime(true);
        if ($now - $this->lastStatsMicrotime < 5) return;
        $elapsed = max(0.001, $now - $this->startMicrotime);
        $interval = max(0.001, $now - $this->lastStatsMicrotime);
        $this->log(sprintf(
            '[转码] 已转 %d tags (v%d/a%d) | 平均 %.1f tags/s，近%d秒 %.1f tags/s',
            $this->tagsFed, $this->videoTags, $this->audioTags,
            $this->tagsFed / $elapsed,
            (int)round($interval),
            ($this->tagsFed - $this->lastStatsTags) / $interval
        ), 'progress');
        $this->lastStatsMicrotime = $now;
        $this->lastStatsTags = $this->tagsFed;
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
