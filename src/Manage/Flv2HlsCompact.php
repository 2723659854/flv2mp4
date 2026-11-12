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
    /**
     * 主进程派往解码worker但尚未写入socket的出站缓冲总上限（3MB）。
     * worker各自另有1MB输入高水位（HlsPipelineProtocol::INPUT_HIGH_WATERMARK），
     * 此门限保证端到端在途最多约"每worker 1个GOP"，频闪慢帧也不会积压直播延迟。
     */
    private const PIPELINE_OUTPUT_MAX_BYTES = 3145728;

    /**
     * 收尾等待基准（秒）的默认值，可由配置项 finishTimeout 覆盖。
     * 它是唯一的主控值：断流/输入结束后的"无进展判定""finish确认""中断排空"
     * "最终冲刷"全部由它按固定倍率派生，避免出现"配置调小但内部仍等45秒"的矛盾。
     */
    private const FINISH_WAIT_DEFAULT = 30;
    /** 配置钳制下限（秒）：再小会截断正常尾部GOP编码 */
    private const FINISH_WAIT_MIN = 5;
    /** 配置钳制上限（秒）：再大则兜底失去意义 */
    private const FINISH_WAIT_MAX = 600;

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
    private array $workerConfig = [];

    // ===== 拉流子进程参数 =====
    private int $maxRetries = 5;
    private int $retryDelay = 3;
    private int $connectTimeout = 10;
    private int $idleTimeout = 30;
    private int $queueMaxBytes = 8388608; // 转发积压上限8MB，超过拉流端跳帧追直播
    private int $duration = 0;            // 0=不限时

    // ===== 收尾等待（全部由 finishTimeout 基准派生，见构造函数） =====
    /** 基准值（秒），配置项 finishTimeout，默认30，钳制到 [5,600] */
    private int $finishWait = self::FINISH_WAIT_DEFAULT;
    /** 输入结束后"无任何进展"判定：ceil(1.5×基准)，默认45s */
    private int $drainStallTimeout = 45;
    /** finish 屏障后等待 worker 确认：ceil(2/3×基准)，默认20s */
    private int $finishStallTimeout = 20;
    /** 收到 Ctrl+C/SIGINT 后的排空硬预算：1×基准，默认30s */
    private int $signalDrainBudget = 30;
    /** 强制收尾后最后一次冲刷等待：ceil(1/3×基准)，默认10s */
    private int $forceFlushWait = 10;
    /** 非强制收尾（异常路径）的兜底等待：20×基准，默认600s */
    private int $shutdownMaxWait = 600;
    /** 基准值的来源说明（用于启动日志，体现钳制结果） */
    private string $finishWaitNote = '未配置，使用默认值';
    /**
     * 收尾排空：duration 到点后不再立刻杀掉拉流进程，先按内容窗口把在途积压吃完
     * （拉流端 ipcBuffer + 内核缓冲 + 主进程 readBuffer/plHold + 各 worker 在途 GOP），
     * 然后再走 ENDLIST 收尾，避免"最后一截已拉到的内容被丢弃"。
     */
    private const DRAIN_GRACE_SECONDS = 15;
    private bool $draining = false;
    private float $drainDeadline = 0.0;
    private int $drainContentLimit = 0;
    private int $targetFps = 0;           // 0=不抽帧；大于0时按目标帧率跳过多余视频帧
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
    /** 拉流IPC已到EOF，readBuffer尾部残帧已不可能再补齐 */
    private bool $pullerIpcEnded = false;

    // ===== 信用回报（拉流端按消费节拍发数据，防止落后量堆积在内核管道看不见） =====
    private string $creditBuffer = '';
    private int $consumedBytes = 0;

    // ===== 统计 =====
    private int $tagsFed = 0;
    private int $videoTags = 0;
    private int $audioTags = 0;
    private ?int $firstMediaTimestamp = null;
    private ?int $lastMediaTimestamp = null;
    private ?int $firstVideoTimestamp = null;
    private ?int $lastVideoTimestamp = null;
    /** @var array<int,int> 各媒体类型上一时间戳（同类型跳变告警用） */
    private array $lastTsByType = [8 => -1, 9 => -1];
    /** 同类型时间戳前跳 >500ms 的告警次数 */
    private int $tsJumpWarnings = 0;
    private float $processStartMicrotime;
    private float $startMicrotime;
    private float $pullStartMicrotime;
    private float $pullEndMicrotime = 0.0;
    private int $pullStartUnix = 0;
    private int $pullEndUnix = 0;
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
    /** @var array<int,int> 各worker在途加权负载（保留帧权重2/抽掉帧权重1） */
    private array $plLoad = [];
    /** @var array<int,int> GOP序号→派发worker */
    private array $plGopWorker = [];
    /** @var array<int,int> GOP序号→权重（READY时从该worker负载中扣减） */
    private array $plGopWeight = [];
    /** @var array<int,float> GOP序号→派发时刻（hrtime），用于实测各worker单权重处理耗时 */
    private array $plGopDispatchAt = [];
    /**
     * @var array<int,float> 各worker"单位权重实测处理耗时(ms)"指数移动平均。
     * 固定权重(保留2/丢弃1)无法反映：频闪段帧成本数倍上涨、混合架构大小核调度差异。
     * 派发分数 = 在途权重 × EMA，慢worker自然少分GOP，无数据时退化为最小权重选择。
     */
    private array $plEmaMsPerWeight = [];
    /** 已派发但未收到READY的GOP数量 */
    private int $plInflightGops = 0;
    /**
     * @var array<int,bool> 各worker是否空闲（已READY、无在途GOP）。
     * GOP只投递给空闲worker；全部繁忙时主进程停止读拉流IPC，反压沿信用窗传导到上游，
     * 端到端在途被限制为"每worker约1个GOP"（频闪帧压缩字节小但解码昂贵，按字节门控
     * 仍会积压数个GOP导致EOF收尾上百秒）。
     */
    private array $plReady = [];
    /**
     * 等待空闲worker的GOP帧暂存（新IDR已到但所有worker都在忙）。
     * 关键：gopEnd由下一个IDR触发生成，若直接停止读IPC，IDR永远进不来、在途GOP永远
     * 收不到边界、worker永远不READY，形成死锁。所以主进程读流不停，仅把帧缓存在此处，
     * 任一worker READY后立即派给它；hold非空期间关闭拉流IPC读取，反压继续向上游传导。
     */
    private string $plHold = '';
    /** 收尾时最后一个GOP是否已补gopEnd */
    private bool $plGopClosed = false;
    private string $plConfigFrame = '';
    private int $plSeq = 0;
    /** 下一个GOP序号（0表示首个视频GOP尚未开始） */
    private int $plGopSeq = 0;
    /** 当前GOP派发的worker（首个IDR之前的音频走worker0） */
    private int $plCurrentWorker = 0;
    private bool $plFinishing = false;
    private int $plFinished = 0;
    private bool $plEndSent = false;
    /** 卡死兜底已触发（强制收尾），shutdownPipeline 据此使用短超时 */
    private bool $plForceStopped = false;
    /** 管线进度水位：签名及其最后一次变化时刻，用于卡死看门狗 */
    private float $plProgressAt = 0.0;
    private float $plProgressCheckAt = 0.0;
    private string $plProgressSig = '';
    /** 拉流子进程存活探测（每0.5s一次） */
    private float $pullerStatusAt = 0.0;
    private bool $pullerProcGone = false;
    /** 收到第二次 Ctrl+C/SIGINT：立即退出，不再等待收尾 */
    private bool $forceExitRequested = false;
    /** 用户中断信号计数（与上游EOF置位的stopSignaled区分开） */
    private int $signalCount = 0;
    /** 收到中断后的排空硬上限时刻（0=未启动） */
    private float $stopDeadline = 0.0;
    /** 抽帧网格基准：首个保留 IDR 的源时间戳（-1=未初始化，与静态管线一致） */
    private int $plBaseVideoTs = -1;
    /** 已保留帧计数：第 n 个保留帧重映射到 base + n×1000/targetFps 均匀网格 */
    private int $plSelectedFrames = 0;

    /**
     * @param string $pullUrl 直播地址 http(s)-flv / ws(s)-flv
     * @param array $config 转码配置：width/height(0=保持)/bitrate/fps(仅编码器)/qp/audioBitrate/
     *                      motionWorkers/watermark/watermark_file/segmentDuration；
     *                      部署配置：outputDir/streamName/maxRetries/retryDelay/connectTimeout/
     *                      idleTimeout/queueMaxBytes(转码落后容忍字节,默认8MB)/duration/fps/tlsVerify/
     *                      finishTimeout(尾部落空与worker确认的基准等待秒数,默认30,范围5~600)
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
        if (isset($config['fps'])) $this->targetFps = max(0, (int)$config['fps']);
        if (isset($config['tlsVerify'])) $this->tlsVerify = (bool)$config['tlsVerify'];
        $this->decodeWorkers = max(0, (int)($config['decodeWorkers'] ?? 2));

        // 收尾等待基准及其派生阈值（单调：基准越小，整条收尾链路越快）
        $this->finishWait = $this->normalizeFinishWait($config['finishTimeout'] ?? null);
        $this->drainStallTimeout = max(3, (int)ceil($this->finishWait * 1.5));
        $this->finishStallTimeout = max(2, (int)ceil($this->finishWait * 2 / 3));
        $this->signalDrainBudget = $this->finishWait;
        $this->forceFlushWait = max(1, (int)ceil($this->finishWait / 3));
        $this->shutdownMaxWait = max(30, $this->finishWait * 20);

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
            $profile['tlsVerify'], $profile['multi'], $profile['finishTimeout']
        );

        if ($this->decodeWorkers > 0) {
            // 流水线模式：profile 随 worker 启动参数下发，切片时长一并透传给 worker
            $profile['segmentDuration'] = $segmentDuration > 0 ? $segmentDuration : 3;
            // 缩放由解码worker并行完成：缩放是纯PHP重操作（768x432→640x360约80-160ms/帧），
            // 放在输出进程会成为串行瓶颈（实测仅~5fps），2个decoder并行缩放下放到~10fps以上
            $this->workerConfig = $profile;
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
        $this->signalCount++;
        if ($this->signalCount > 1) {
            $this->forceExitRequested = true;
            $this->log("再次收到信号 {$signal}，立即退出（不再等待收尾）", 'warning');
            return;
        }
        $this->stopSignaled = true;
        $this->log("收到信号 {$signal}，准备停止并排空已接收数据...（再次发送信号可立即退出）");
    }

    public function handleWindowsCtrl(int $event): bool
    {
        // 回调运行在独立线程：只置标量标志，不做日志/IO。返回true表示已接管该事件，
        // 否则PHP会走默认处理直接杀进程，丢掉ENDLIST收尾机会。
        $this->signalCount++;
        if ($this->signalCount > 1) {
            $this->forceExitRequested = true;
        } else {
            $this->stopSignaled = true;
        }
        return true;
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
     * 归一化配置项 finishTimeout（收尾等待基准，秒）。
     * 未配置/非数字 → 默认30；≤0 → 视为未配置；<5 钳到5；>600 钳到600。
     * 同时记录来源说明，启动日志据此展示实际生效值。
     */
    private function normalizeFinishWait(mixed $value): int
    {
        if ($value === null || $value === '') {
            $this->finishWaitNote = '未配置，使用默认值';
            return self::FINISH_WAIT_DEFAULT;
        }
        if (!is_numeric($value)) {
            $this->finishWaitNote = '配置值非数字，使用默认值';
            return self::FINISH_WAIT_DEFAULT;
        }
        $raw = (float)$value;
        if ($raw <= 0) {
            $this->finishWaitNote = sprintf('配置 %s 非法(≤0)，使用默认值', is_scalar($value) ? (string)$value : '?');
            return self::FINISH_WAIT_DEFAULT;
        }
        $v = (int)round($raw);
        if ($v < self::FINISH_WAIT_MIN) {
            $this->finishWaitNote = sprintf('配置 %s 过小，钳制到下限 %ds', (string)$value, self::FINISH_WAIT_MIN);
            return self::FINISH_WAIT_MIN;
        }
        if ($v > self::FINISH_WAIT_MAX) {
            $this->finishWaitNote = sprintf('配置 %s 过大，钳制到上限 %ds', (string)$value, self::FINISH_WAIT_MAX);
            return self::FINISH_WAIT_MAX;
        }
        $this->finishWaitNote = sprintf('配置 %ds', $v);
        return $v;
    }

    /**
     * 启动转码客户端（阻塞运行：拉起拉流子进程 → 收帧转码 → 优雅收尾）
     */
    public function run(): void
    {
        $this->processStartMicrotime = microtime(true);
        $this->startMicrotime = $this->processStartMicrotime;
        $this->pullStartMicrotime = $this->processStartMicrotime;
        $this->pullStartUnix = time();
        $this->lastStatsMicrotime = $this->processStartMicrotime;
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
        $this->log(sprintf(
            '收尾等待基准: %ds（%s，可配置 finishTimeout，范围 %d~%d）| 无进展判定 %ds / finish确认 %ds / 中断排空 %ds / 最终冲刷 %ds',
            $this->finishWait, $this->finishWaitNote, self::FINISH_WAIT_MIN, self::FINISH_WAIT_MAX,
            $this->drainStallTimeout, $this->finishStallTimeout, $this->signalDrainBudget, $this->forceFlushWait
        ));
        $this->log('========================================');

        $parallel = $this->decodeWorkers > 0;
        try {
            $port = $this->startIpcServer();
            if ($parallel) {
                // 先完成全部转码worker及其内部连接，再启动拉流，避免流水线冷启动期间积压直播数据。
                $this->startPipeline();
                $this->spawnPuller($port);
            } else {
                $this->spawnPuller($port);
            }
            $this->acceptPuller($port);
            // duration 从拉流IPC建立后开始计算，不包含跨平台差异较大的worker冷启动耗时。
            $this->startMicrotime = microtime(true);
            $this->pullStartMicrotime = $this->startMicrotime;
            $this->pullStartUnix = time();
            $this->lastStatsMicrotime = $this->startMicrotime;
            if ($parallel) $this->pipelineLoop();
            else $this->transcodeLoop();
        } catch (\Throwable $e) {
            $this->log('客户端异常: ' . $e->getMessage(), 'error');
        } finally {
            if ($parallel) {
                if ($this->stopSignaled || $this->endReceived || $this->pullerIpcEnded) {
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
        if ($this->pullEndMicrotime <= 0.0) {
            $this->pullEndMicrotime = microtime(true);
            $this->pullEndUnix = time();
        }
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
            if ($this->forceExitRequested) {
                $this->log('用户要求立即退出，跳过收尾', 'warning');
                $this->running = false;
                return;
            }
            if ($this->stopSignaled) {
                // Ctrl+C/SIGINT：停止读取，交由 finally 冲刷末帧并写ENDLIST
                $this->running = false;
                return;
            }
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
            // 拉流子进程关闭：先消费所有完整帧，再丢弃无法补齐的尾部残帧。
            // 主动停止时若保留残帧，流水线会一直认为仍有待处理输入而无法发送finish。
            $this->drainFrames($pipeline);
            $this->pullerIpcEnded = true;
            $this->stopSignaled = true;
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
            // 内容时间窗只按媒体实体帧推进：sequence header（时间戳通常为 0）、
            // end-of-sequence 与脚本元数据若算进来，firstMediaTimestamp 会被钉在 0，
            // 排空终点 drainContentLimit 立刻被末个时间戳越过，收尾排空吃不到在途数据
            if ($this->isMediaEntityTag($tagType, $body)) {
                // 同类型时间戳前跳告警：拉流端时间轴被棘轮放大时，这里是最先出现异常的地方
                $prevTs = $this->lastTsByType[$tagType] ?? -1;
                if ($prevTs >= 0 && $timestamp - $prevTs > 500) {
                    $this->tsJumpWarnings++;
                    $this->log(sprintf(
                        '时间戳跳变：%s 前进 %dms（%d → %d，当前偏移 %dms），累计 %d 次',
                        $tagType === 9 ? '视频' : '音频',
                        $timestamp - $prevTs, $prevTs, $timestamp,
                        $timestamp - ($this->firstMediaTimestamp ?? $timestamp),
                        $this->tsJumpWarnings
                    ), 'warning');
                }
                $this->lastTsByType[$tagType] = $timestamp;
                if ($this->firstMediaTimestamp === null) $this->firstMediaTimestamp = $timestamp;
                $this->lastMediaTimestamp = $timestamp;
                if ($tagType === 9) {
                    if ($this->firstVideoTimestamp === null) $this->firstVideoTimestamp = $timestamp;
                    $this->lastVideoTimestamp = $timestamp;
                }
            }
            if ($pipeline) {
                $this->plEnqueueTag($tagType, $body, $timestamp);
                // 新GOP无空闲worker时帧进入plHold：必须立即停止解析本批剩余字节。
                // 否则同一批里若再含下一个IDR（跳帧追直播时resync包极小，64KB可容纳
                // 多个IDR），plStartGop会把gopEnd写进plOut[-1]丢失，两个GOP被合并派给
                // 同一worker且在途计数永久泄漏，累计6次后所有worker卡死、拉流永久停滞。
                // 剩余字节保留在readBuffer，待plDispatchHold()派发后续排（见pipelineLoop）。
                if ($this->plHold !== '') break;
            } else {
                $this->feedTranscoder($tagType, $body, $timestamp);
            }
        }
        $this->readBuffer = $buf;
        // 本批已全部放行后回报信用（流水线模式下"在途"含各worker出站缓冲，积压由转发反压消化）
        if ($newlyConsumed > 0) {
            $this->consumedBytes += $newlyConsumed;
            $this->grantCredit();
        }
    }

    /**
     * 是否为媒体实体帧（可推进内容时间窗），排除 sequence header 等非内容 tag：
     *  - 视频：AVC 的 AVCPacketType=0（sequence header）/2（end of sequence）不是帧；
     *          帧类型 5（video info/command）也不是图像
     *  - 音频：AAC 的 AACPacketType=0（sequence header）不是音频帧
     *  - 其余（脚本元数据 tagType=18 等）一律不算
     */
    private function isMediaEntityTag(int $tagType, string $body): bool
    {
        if (strlen($body) < 2) return false;
        if ($tagType === 9) {
            $frameType = (ord($body[0]) >> 4) & 0x0F;
            if ($frameType !== 1 && $frameType !== 2) return false;
            if ((ord($body[0]) & 0x0F) === 7 && ord($body[1]) !== 1) return false;
            return true;
        }
        if ($tagType === 8) {
            if ((ord($body[0]) >> 4) === 10 && ord($body[1]) !== 1) return false;
            return true;
        }
        return false;
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
        $configOpt = base64_encode(json_encode($this->workerConfig, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        // 媒体端口由各 worker 自行 bind tcp://127.0.0.1:0 后经 rendezvous 上报，
        // 主进程不再预留（预留需 bind+close 监听 socket，Windows 上 close 有约 510ms 固定延迟）。
        // 控制端口仍由主进程预留后经 --control-port 传入，worker 负责监听。
        $rendezvous = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($rendezvous === false) throw new RuntimeException("无法建立 rendezvous 监听: {$error}");
        $rvPort = (int)substr(strrchr(stream_socket_get_name($rendezvous, false), ':'), 1);
        $rendezvousAddress = "tcp://127.0.0.1:{$rvPort}";
        [, $outputCtrlPort] = $this->reserveAddress();
        $controlPorts = [];
        for ($i = 0; $i < $n; $i++) [, $controlPorts[]] = $this->reserveAddress();

        try {
            // 输出worker先启动（单profile下视频帧已在解码worker预编码完成，输出worker只做TS封装；
            // --preencoded 跳过编码器运动子进程预热，避免白冷启动 motionWorkers 个永不使用的PHP进程）
            $this->startWorker($entry, [
                '--mode', 'output',
                '--autoload', $autoload,
                '--rendezvous', $rendezvousAddress,
                '--control-port', (string)$outputCtrlPort,
                '--workers', (string)$n,
                '--config', $configOpt,
                '--output', rtrim($this->streamDir, '/\\') . '/',
                '--preencoded',
            ]);
            [$outputPort] = $this->collectWorkerPorts($rendezvous, 0);
            for ($i = 0; $i < $n; $i++) {
                $this->startWorker($entry, [
                    '--mode', 'decoder',
                    '--autoload', $autoload,
                    '--rendezvous', $rendezvousAddress,
                    '--control-port', (string)$controlPorts[$i],
                    '--output-port', (string)$outputPort,
                    '--config', $configOpt,
                ]);
                $this->plOut[$i] = '';
                $this->plIn[$i] = '';
                $this->plLoad[$i] = 0;
                $this->plReady[$i] = false;
                $this->plEmaMsPerWeight[$i] = 0.0;
            }
            [, $decoderPorts] = $this->collectWorkerPorts($rendezvous, $n, false);
        } finally {
            @fclose($rendezvous);
        }
        $this->plHold = '';

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

    /**
     * 从 rendezvous 收集 worker 自报的媒体监听端口（每个连接一行 "mode port"）。
     * 输出worker的端口是解码worker --output-port 的下游参数，必须先收集完成。
     * $needOutput=false 时只等待解码worker上报。
     */
    private function collectWorkerPorts($rendezvous, int $decoders, bool $needOutput = true): array
    {
        $outputPort = 0;
        $decoderPorts = [];
        $deadline = microtime(true) + 30;
        while (microtime(true) < $deadline
            && (($needOutput && $outputPort === 0) || count($decoderPorts) < $decoders)) {
            $conn = @stream_socket_accept($rendezvous, 2);
            if ($conn === false) continue;
            $line = fgets($conn);
            @fclose($conn);
            if ($line === false) continue;
            $parts = explode(' ', trim($line));
            if (count($parts) !== 2) continue;
            [$mode, $port] = $parts;
            $port = (int)$port;
            if ($port <= 0) continue;
            if ($mode === 'output') $outputPort = $port;
            elseif ($mode === 'decoder') $decoderPorts[] = $port;
        }
        if (($needOutput && $outputPort === 0) || count($decoderPorts) < $decoders) {
            throw new RuntimeException('worker 端口上报不完整（rendezvous 超时）');
        }
        return [$outputPort, $decoderPorts];
    }

    private function startWorker(string $entry, array $args): void
    {
        $descriptors = [fopen('php://stdin', 'r'), fopen('php://stdout', 'a'), fopen('php://stderr', 'a')];
        $options = ['bypass_shell' => true];
        if (PHP_OS_FAMILY === 'Windows') $options['create_process_group'] = true;
        $command = [PHP_BINARY, '-d', 'memory_limit=2048M', $entry];
        $p = @proc_open(array_merge($command, $args), $descriptors, $pipes, dirname(__DIR__, 2), null, $options);
        if (!is_resource($p)) throw new RuntimeException('拉起HLS worker失败: ' . implode(' ', $args));
        $this->plProcesses[] = $p;
    }

    private function pipelineLoop(): void
    {
        while (true) {
            if ($this->forceExitRequested) {
                $this->log('用户要求立即退出，跳过收尾', 'warning');
                $this->running = false;
                return;
            }
            if ($this->plProgressAt <= 0.0) $this->plProgressAt = microtime(true);
            // 用户中断：给出有上界的排空预算，避免"永不停机"；二次中断已在上面直接退出
            if ($this->stopSignaled && $this->signalCount > 0 && $this->stopDeadline <= 0.0) {
                $this->stopDeadline = microtime(true) + $this->signalDrainBudget;
                $this->log(sprintf('已停止拉流，最多再等 %d 秒排空已接收数据（再次按 Ctrl+C 立即退出）', $this->signalDrainBudget));
            }
            if ($this->running && !$this->draining && $this->duration > 0
                && microtime(true) - $this->startMicrotime >= $this->duration) {
                // 到点后先进排空：保持读拉流IPC，把已拉到但未消化的内容吃完再收尾
                $this->draining = true;
                $this->drainDeadline = microtime(true) + self::DRAIN_GRACE_SECONDS;
                $this->drainContentLimit = ($this->firstMediaTimestamp ?? 0) + $this->duration * 1000;
                $this->log("已达到运行时长 {$this->duration} 秒，进入收尾排空（内容窗口至 {$this->drainContentLimit}ms）");
            }
            if ($this->draining) {
                // 排空终点：内容窗口已到 / 拉流侧结束 / 宽限期到
                $contentReached = $this->lastMediaTimestamp !== null
                    && $this->lastMediaTimestamp > $this->drainContentLimit;
                if ($this->endReceived || $this->pullerIpcEnded || $contentReached
                    || microtime(true) >= $this->drainDeadline) {
                    $this->draining = false;
                    $this->stopSignaled = true;
                    $this->running = false;
                    $this->log('收尾排空完成（末个媒体时间戳 '
                        . ($this->lastMediaTimestamp ?? 0) . 'ms），冲刷在途后写 ENDLIST');
                }
            }

            $outBytes = 0;
            foreach ($this->plOut as $buf) $outBytes += strlen($buf);
            // 拉流子进程存活看门狗：子进程退出后 END/EOF 必须能读到，不能因为反压
            // （hold/出站积压）关闭了拉流读取而永远看不到"上游已永久结束"。
            if (!$this->plEndSent && !$this->pullerIpcEnded && !$this->endReceived
                && microtime(true) - $this->pullerStatusAt >= 0.5) {
                $this->pullerStatusAt = microtime(true);
                if (is_resource($this->process)) {
                    $ps = @proc_get_status($this->process);
                    if ($ps && empty($ps['running'])) $this->pullerProcGone = true;
                }
                if ($this->pullerProcGone) $this->forceDrainPuller();
            }
            // 进度水位：tags/派发序号/READY回执/FINISHED/出站积压任一变化即视为有进展
            $now = microtime(true);
            if ($now - $this->plProgressCheckAt >= 0.2) {
                $this->plProgressCheckAt = $now;
                $sig = $this->tagsFed . ':' . $this->plSeq . ':' . $this->plFinished
                    . ':' . $this->plInflightGops . ':' . $outBytes;
                if ($sig !== $this->plProgressSig) {
                    $this->plProgressSig = $sig;
                    $this->plProgressAt = $now;
                }
            }
            // 出站（派往worker但未写入socket）积压门限：worker各自还有1MB输入高水位，
            // 此处3MB封顶。读取不要求worker READY——gopEnd必须靠读入下一个IDR才能生成，
            // 而worker处理完GOP帧后要等gopEnd才回报READY，若"无READY不读IPC"会死锁。
            // 正确链条：照常读入IDR→为上一GOP补发gopEnd→无空闲worker时新GOP帧进入plHold
            // 并停止继续读IPC（反压沿信用窗传导上游）→worker收尾GOP回READY→hold派发。
            // 端到端在途≈每worker 1个GOP+IDR前残留（≤约2s内容）。
            // 主动停止后仍继续读取拉流IPC，直至被终止的拉流进程关闭连接。
            // 否则readBuffer可能停在半个IPC帧上，永远无法满足收尾条件。
            $canReadPuller = !$this->plEndSent && !$this->pullerIpcEnded && $outBytes < self::PIPELINE_OUTPUT_MAX_BYTES
                && $this->plHold === '' && is_resource($this->ipc);

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
            // hold可能在本次select中已被worker READY派发：续排上次因hold中断而残留在
            // readBuffer中的字节，直到再次形成hold或排空（极小resync包时一批可含多个IDR），
            // 保证END/后续IDR不积压。注意必须以"缓冲长度是否缩短"为终止条件：末尾残留的
            // 半个帧要等下次fread补全，否则会无限重排同一残帧导致主进程100%空转。
            while ($this->plHold === '' && $this->readBuffer !== '') {
                $beforeLen = strlen($this->readBuffer);
                $this->drainFrames(true);
                if (strlen($this->readBuffer) >= $beforeLen) break;
            }
            if ($this->pullerIpcEnded && $this->plHold === '' && $this->readBuffer !== '') {
                $this->readBuffer = '';
            }

            if ($this->stopSignaled && is_resource($this->process)) $this->stopPuller();
            // 主动停止时先排空主进程已收到的媒体，再通知 decoder 优雅结束；
            // 不提前通知 output，否则它会丢弃尚未编码的重排队列。
            $pendingInput = $this->readBuffer !== '' || $this->plHold !== '';
            foreach ($this->plOut as $buf) if ($buf !== '') { $pendingInput = true; break; }
            // 卡死兜底：输入已结束/收到停止信号后，若管线长时间无任何进展，说明有worker
            // 不回READY（plInflightGops永远≥1，finish屏障永远发不出去），必须强制收尾，
            // 否则主进程无限空转、Ctrl+C也只能置标志而无法退出。
            $inputEnded = $this->endReceived || $this->pullerIpcEnded;
            if (!$this->plEndSent && ($inputEnded || $this->stopSignaled)) {
                $stalled = $now - $this->plProgressAt >= $this->drainStallTimeout;
                $overBudget = $this->stopDeadline > 0.0 && $now >= $this->stopDeadline;
                if ($stalled) {
                    $this->forceFinishPipeline(sprintf('管线无任何进展 %.0fs', $now - $this->plProgressAt));
                } elseif ($overBudget) {
                    $this->forceFinishPipeline(sprintf(
                        '中断后已等待 %.0fs 排空', $now - ($this->stopDeadline - $this->signalDrainBudget)
                    ));
                }
            }
            // 输入已结束：hold必须先派发再发finish，否则被hold的帧已占用的序号
            // 会成为输出端重排缓冲的永久空洞（output worker永远等不到，收尾卡死）。
            if (!$this->plEndSent && $this->plHold === ''
                && ($this->endReceived || (($this->pullerIpcEnded || $this->stopSignaled) && !$pendingInput))) {
                // GOP帧已随拉流实时派发；先为最后一个GOP补边界，所有GOP均READY后再finish
                if (!$this->plGopClosed) {
                    $this->plCloseCurrentGop();
                    $this->plGopClosed = true;
                }
                if ($this->plInflightGops === 0) $this->sendPipelineFinish();
            }
            // finish屏障发出后：等全部worker确认FINISHED；长时间无任何进展（worker卡死）
            // 也必须退出，不能无限空转（否则Ctrl+C也只能置标志）。
            if ($this->plEndSent && ($this->plFinished >= $this->decodeWorkers
                || microtime(true) - $this->plProgressAt >= $this->finishStallTimeout)) {
                if ($this->plFinished < $this->decodeWorkers) {
                    $this->plForceStopped = true;
                    $this->log(sprintf(
                        '强制收尾超时：仍有 %d/%d 个worker未确认，直接退出（末个分片可能不完整）',
                        $this->decodeWorkers - $this->plFinished, $this->decodeWorkers
                    ), 'warning');
                }
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
                    break;
                case HlsPipelineProtocol::READY:
                    // worker启动时发一个无gop的READY，表示空闲可接GOP
                    $g = $event['metadata']['gop'] ?? null;
                    if ($g === null) {
                        $this->plReady[$id] = true;
                    } elseif (isset($this->plGopWorker[$g])) {
                        $w = $this->plGopWorker[$g];
                        $this->plReady[$w] = true;
                        $this->plLoad[$w] -= $this->plGopWeight[$g];
                        // 实测该GOP"派发→排空"耗时（含排队），更新单位权重耗时EMA
                        $gw = $this->plGopWeight[$g];
                        if ($gw > 0 && isset($this->plGopDispatchAt[$g])) {
                            $perWeight = (hrtime(true) - $this->plGopDispatchAt[$g]) / 1e6 / $gw;
                            $ema = $this->plEmaMsPerWeight[$w];
                            $this->plEmaMsPerWeight[$w] = $ema <= 0.0 ? $perWeight : $ema * 0.5 + $perWeight * 0.5;
                            unset($this->plGopDispatchAt[$g]);
                        }
                        unset($this->plGopWorker[$g], $this->plGopWeight[$g]);
                        $this->plInflightGops--;
                    }
                    // 有等待空闲worker的GOP时立即派发（恢复拉流IPC读取）
                    $this->plDispatchHold();
                    break;
                case HlsPipelineProtocol::FINISHED:
                    $this->plFinished++;
                    break;
                case HlsPipelineProtocol::ERROR:
                    throw new RuntimeException('流水线worker失败: ' . ($event['metadata']['message'] ?? '未知错误'));
            }
        }
    }

    /**
     * 拉流tag实时派发到解码worker（帧到达即下发，不等GOP结束，消除整GOP约2秒的派发延迟）：
     * 视频序列头：worker0收EVENT，全体收CONTROL config；
     * IDR：新GOP立即按在途加权负载选定worker，前一GOP向其worker补gopEnd；
     * 音频随当前GOP worker立即下发。
     */
    private function plEnqueueTag(int $tagType, string $body, int $timestamp): void
    {
        $this->tagsFed++;
        if ($tagType === 9) $this->videoTags++; else $this->audioTags++;
        if ($tagType === 9 && strlen($body) >= 2 && (ord($body[0]) & 0x0f) === 7) {
            $packetType = ord($body[1]);
            if ($packetType === 0) {
                $this->plRoute(HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $this->plSeq++, ['tagType' => 9, 'timestamp' => $timestamp], $body));
                $this->plConfigFrame = HlsPipelineProtocol::frame(HlsPipelineProtocol::CONTROL, 0, ['cmd' => 'config'], $body);
                for ($i = 0; $i < $this->decodeWorkers; $i++) $this->plOut[$i] .= $this->plConfigFrame;
                return;
            }
            if ($packetType === 1) {
                $isKey = ((ord($body[0]) >> 4) === 1) && $this->containsIdrNal($body);
                if ($isKey) $this->plStartGop();
                $metadata = ['tagType' => 9, 'timestamp' => $timestamp];
                $drop = false;
                if ($this->targetFps > 0) {
                    // 尾帧冲刷用：保留帧时间戳在目标帧率均匀网格上，尾帧步长对齐输出节奏
                    $metadata['sourceFps'] = $this->targetFps;
                    // 选帧网格按源 PTS 归一：保留帧直接沿用源 PTS（音频/视频两轴同刻度），
                    // 输出时长只跟源时间轴走；不再用"保留帧数 × 1000/targetFps"反推输出时间
                    // ——那样输出时长由帧数决定，上游时间戳一旦被拉长就成倍膨胀。
                    // 保留帧数仍用于选帧：被抽掉的帧送解码维持参考链但标记 drop 不写输出。
                    if ($this->plBaseVideoTs < 0) {
                        if ($isKey) {
                            $this->plBaseVideoTs = $timestamp;
                            $metadata['outTimestamp'] = $timestamp;
                            $this->plSelectedFrames = 1;
                        } else {
                            $drop = true;
                        }
                    } elseif (!$isKey
                        && ($timestamp - $this->plBaseVideoTs) * $this->targetFps < $this->plSelectedFrames * 1000) {
                        $drop = true;
                    } else {
                        $metadata['outTimestamp'] = $timestamp;
                        $this->plSelectedFrames++;
                    }
                }
                if ($drop) $metadata['drop'] = true;
                $this->plRoute(HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $this->plSeq++, $metadata, $body));
                // 在途加权负载：抽掉帧只解码维持参考链（权重1），保留帧全链路（权重2）。
                // GOP等待派发期间只累计GOP权重，账在plAssignGop补记到worker。
                $weight = $drop ? 1 : 2;
                if ($this->plCurrentWorker >= 0) $this->plLoad[$this->plCurrentWorker] += $weight;
                $this->plGopWeight[$this->plGopSeq - 1] += $weight;
                return;
            }
        }
        // 音频等非视频tag：随当前GOP的worker立即下发（首个IDR之前走worker0）
        $this->plRoute(HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $this->plSeq++, ['tagType' => $tagType, 'timestamp' => $timestamp], $body));
    }

    /** 帧路由：GOP尚无空闲worker时进plHold等待，否则写入目标worker发送缓冲。 */
    private function plRoute(string $frame): void
    {
        if ($this->plCurrentWorker < 0) $this->plHold .= $frame;
        else $this->plOut[$this->plCurrentWorker] .= $frame;
    }

    /**
     * IDR到达：先为上一GOP补边界标记（其全部帧已实时写入对应worker），
     * 再把新GOP派给空闲（READY）worker中EMA分数最优者；首个GOP固定worker0（序列头只投递给他）。
     * 无空闲worker时不选目标（plCurrentWorker=-1），本GOP帧暂存plHold，
     * 待任一worker READY后由 plDispatchHold() 派发。
     */
    private function plStartGop(): void
    {
        if ($this->plGopSeq > 0) {
            $prev = $this->plGopSeq - 1;
            $prevWorker = $this->plGopWorker[$prev];
            if ($prevWorker < 0) {
                // 上一GOP仍在hold未派发就收到新IDR：drainFrames的hold中断应已杜绝此情形
                throw new RuntimeException("GOP{$prev}尚未派发即收到下一个IDR（hold中断失效）");
            }
            // main/high 源按 POC 重排，GOP 末尾帧滞留解码器重排队列，gopEnd 触发尾帧冲刷。
            // 尾帧占用连续预留序号（不足以 drop 占位补齐），保证输出端全局序号严格连续。
            $this->plOut[$prevWorker] .= HlsPipelineProtocol::frame(HlsPipelineProtocol::CONTROL, 0, [
                'cmd' => 'gopEnd', 'gop' => $prev,
                'tailSeq' => $this->plSeq, 'tailMax' => HlsPipelineProtocol::TAIL_SEQ_RESERVE,
            ]);
            $this->plSeq += HlsPipelineProtocol::TAIL_SEQ_RESERVE;
        }
        $gop = $this->plGopSeq;
        $worker = ($gop === 0) ? 0 : $this->plPickReadyWorker();
        $this->plGopWorker[$gop] = $worker; // -1：等待空闲worker（帧进plHold）
        $this->plGopWeight[$gop] = 0;
        $this->plCurrentWorker = $worker;
        $this->plGopSeq++;
        if ($worker >= 0) {
            $this->plAssignGop($gop, $worker);
        }
    }

    /** 选择空闲worker中"在途权重×EMA"最小者；无空闲返回-1。 */
    private function plPickReadyWorker(): int
    {
        $worker = -1;
        $bestScore = null;
        for ($i = 0; $i < $this->decodeWorkers; $i++) {
            if (empty($this->plReady[$i])) continue;
            $ema = $this->plEmaMsPerWeight[$i];
            $score = $this->plLoad[$i] * ($ema > 0.0 ? $ema : 1.0);
            if ($bestScore === null || $score < $bestScore) {
                $bestScore = $score;
                $worker = $i;
            }
        }
        return $worker;
    }

    /** 把GOP正式登记到worker（立即派发或hold冲刷时调用）。 */
    private function plAssignGop(int $gop, int $worker): void
    {
        $this->plGopWorker[$gop] = $worker;
        $this->plReady[$worker] = false;
        $this->plCurrentWorker = $worker;
        $this->plGopDispatchAt[$gop] = hrtime(true);
        // hold期间累计的权重补记到该worker负载（READY时按GOP总权重扣减，保持账平）
        $this->plLoad[$worker] += $this->plGopWeight[$gop];
        $this->plInflightGops++;
    }

    /** worker READY后若有等待派发的GOP，立即派给最优空闲worker。 */
    private function plDispatchHold(): void
    {
        if ($this->plHold === '') return;
        $gop = $this->plGopSeq - 1;
        if ($gop < 0 || ($this->plGopWorker[$gop] ?? -1) !== -1) return;
        $worker = $this->plPickReadyWorker();
        if ($worker < 0) return;
        $this->plAssignGop($gop, $worker);
        $this->plOut[$worker] .= $this->plHold;
        $this->plHold = '';
    }

    /** 收尾：为最后一个GOP补边界标记（含尾帧序号预留），worker冲刷末帧后回READY */
    private function plCloseCurrentGop(): void
    {
        if ($this->plGopSeq > 0) {
            $gop = $this->plGopSeq - 1;
            if (isset($this->plGopWorker[$gop]) && $this->plGopWorker[$gop] >= 0) {
                $this->plOut[$this->plGopWorker[$gop]] .= HlsPipelineProtocol::frame(HlsPipelineProtocol::CONTROL, 0, [
                    'cmd' => 'gopEnd', 'gop' => $gop,
                    'tailSeq' => $this->plSeq, 'tailMax' => HlsPipelineProtocol::TAIL_SEQ_RESERVE,
                ]);
                $this->plSeq += HlsPipelineProtocol::TAIL_SEQ_RESERVE;
            }
        }
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
     * 发送finish屏障：输出worker经独立控制连接直达，仅解除其媒体反压；
     * 解码worker的finish必须追加到媒体通道末尾，不能走独立控制连接。
     * 否则控制帧可能先于尚在decoder内核接收缓冲中的媒体到达，decoder会提前停止读媒体并丢帧。
     */
    private function sendPipelineFinish(): void
    {
        $finishFrame = HlsPipelineProtocol::frame(HlsPipelineProtocol::CONTROL, 0, ['cmd' => 'finish']);
        if (is_resource($this->plOutCtrl)) @fwrite($this->plOutCtrl, $finishFrame);
        for ($i = 0; $i < $this->decodeWorkers; $i++) {
            $this->plOut[$i] .= $finishFrame;
        }
        $this->plEndSent = true;
        // 重置进度水位：finish 后的等待从这里开始计时
        $this->plProgressAt = microtime(true);
    }

    /**
     * 拉流子进程已退出但还没读到 END/EOF：无视管线反压把 IPC 读尽。
     * 子进程退出后剩余数据只在其本地内存（已随进程丢失）+内核缓冲（有界），
     * 不会造成无限读取；这样做保证三条拉流协议（HTTP/WS/RTMP）的结束都能被发现。
     */
    private function forceDrainPuller(): void
    {
        if (!is_resource($this->ipc)) {
            $this->pullerIpcEnded = true;
            return;
        }
        for ($i = 0; $i < 128 && !$this->endReceived; $i++) {
            $chunk = @fread($this->ipc, 262144);
            if ($chunk === false || $chunk === '') break;
            $this->readBuffer .= $chunk;
            $this->drainFrames(true);
            if (strlen($chunk) < 262144) break;
        }
        if ($this->endReceived) {
            $this->pullerIpcEnded = true;
            return;
        }
        // 进程已退出：再读一次，空即代表不会再有数据（不必等FIN的半个处理周期）
        $tail = @fread($this->ipc, 262144);
        if ($tail === false || $tail === '') {
            if ($tail !== false && $this->readBuffer !== '') $this->drainFrames(true);
            $this->pullerIpcEnded = true;
            $this->stopSignaled = true;
        } else {
            $this->readBuffer .= $tail;
            $this->drainFrames(true);
        }
    }

    /**
     * 收尾卡死兜底：强制派发 hold、补最后一个GOP边界并发送finish屏障。
     * 触发条件：输入已结束/收到停止信号后，管线长时间无任何进展（有worker不回READY）。
     * 派发到"任意存活worker"是安全的：帧按worker内顺序追加，输出端序号仍连续。
     */
    private function forceFinishPipeline(string $reason = '管线无任何进展'): void
    {
        if ($this->plEndSent) return;
        $unready = [];
        for ($i = 0; $i < $this->decodeWorkers; $i++) {
            if (empty($this->plReady[$i]) && !isset($this->plDead[$i])) {
                $unready[] = '#' . $i . '(load=' . ($this->plLoad[$i] ?? 0) . ')';
            }
        }
        $this->log(sprintf(
            '收尾等待超时（%s）：%d 个worker未回报READY [%s]，强制派发在途GOP并发送finish屏障',
            $reason, count($unready), implode(' ', $unready)
        ), 'warning');
        if ($this->plHold !== '') {
            $worker = $this->plPickReadyWorker();
            if ($worker < 0) $worker = $this->plPickLiveWorker();
            $gop = $this->plGopSeq - 1;
            if ($worker >= 0 && $gop >= 0 && ($this->plGopWorker[$gop] ?? -1) === -1) {
                $this->plAssignGop($gop, $worker);
                $this->plOut[$worker] .= $this->plHold;
            } else {
                $this->log(sprintf('强制收尾：无可用worker，丢弃 %d 字节未派发GOP', strlen($this->plHold)), 'warning');
            }
            $this->plHold = '';
        }
        if (!$this->plGopClosed) {
            $this->plCloseCurrentGop();
            $this->plGopClosed = true;
        }
        $this->sendPipelineFinish();
    }

    /** 选一个存活worker（不看READY，按负载最小），强制派发hold用；全部不可用返回-1。 */
    private function plPickLiveWorker(): int
    {
        $worker = -1;
        $best = null;
        for ($i = 0; $i < $this->decodeWorkers; $i++) {
            if (isset($this->plDead[$i]) || !is_resource($this->plSocks[$i] ?? null)) continue;
            $load = $this->plLoad[$i] ?? 0;
            if ($best === null || $load < $best) {
                $best = $load;
                $worker = $i;
            }
        }
        return $worker;
    }

    /** 强制收尾后播放列表兜底：worker已无法正常完成时补写ENDLIST，避免留下永不结束的直播列表。 */
    private function forceWriteEndlist(): void
    {
        $index = rtrim($this->streamDir, '/\\') . '/index.m3u8';
        if (!is_file($index)) return;
        $content = @file_get_contents($index);
        if ($content === false || strpos($content, '#EXT-X-ENDLIST') !== false) return;
        if (@file_put_contents($index, rtrim($content, "\r\n") . "\n#EXT-X-ENDLIST\n", LOCK_EX) !== false) {
            $this->log('已为 index.m3u8 补写 ENDLIST（强制收尾）', 'warning');
        }
    }

    private function shutdownPipeline(): void
    {
        $shutdownStart = microtime(true);
        // 兜底广播finish（正常情况下pipelineLoop已发），并限时等待FINISHED
        if (!$this->plEndSent) $this->sendPipelineFinish();
        $this->stopPuller();
        // 编码速度低于实时速度时，尾部可能积累数百 MB；正常收尾必须等待完整排空。
        // 非强制收尾用 20×基准（默认600s）作为异常worker兜底；已判定卡死或用户已中断时
        // 只用 1/3×基准（默认10s）做最后冲刷，否则Ctrl+C仍表现为"按了没用"。
        $waitLimit = ($this->plForceStopped || $this->signalCount > 0)
            ? $this->forceFlushWait : $this->shutdownMaxWait;
        $deadline = microtime(true) + $waitLimit;
        while ($this->plFinished < $this->decodeWorkers && microtime(true) < $deadline) {
            if ($this->forceExitRequested) break;
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
        // 卡死/强制收回：worker 已无法正常完成 ENDLIST 收尾，主进程兜底补写
        if ($this->plForceStopped) $this->forceWriteEndlist();
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
        $endMicrotime = microtime(true);
        $elapsed = $endMicrotime - $this->processStartMicrotime;
        $pullEndMicrotime = $this->pullEndMicrotime > 0.0 ? $this->pullEndMicrotime : $endMicrotime;
        $initElapsed = max(0.0, $this->pullStartMicrotime - $this->processStartMicrotime);
        $pullElapsed = max(0.0, $pullEndMicrotime - $this->pullStartMicrotime);
        $tailElapsed = max(0.0, $endMicrotime - $pullEndMicrotime);
        $pullStart = $this->pullStartUnix > 0 ? date('Y-m-d H:i:s', $this->pullStartUnix) : '-';
        $pullEnd = $this->pullEndUnix > 0 ? date('Y-m-d H:i:s', $this->pullEndUnix) : '-';

        $this->log('========================================');
        $this->log('转码结束统计');
        $this->log("开始拉流: {$pullStart}，结束拉流: {$pullEnd}");
        $this->log(sprintf('初始化耗时: %.1fs，拉流时长: %.1fs，收尾转码耗时: %.1fs', $initElapsed, $pullElapsed, $tailElapsed));
        $this->log('总耗时: ' . round($elapsed, 1) . "s，送转码tag: {$this->tagsFed} (视频{$this->videoTags}/音频{$this->audioTags})");
        // 源时间轴跨度 / 拉流墙钟：正常应接近 1.0；>1.1 说明时间轴被放大（拉流重映射/源时间戳异常）
        if ($pullElapsed > 1.0 && $this->firstMediaTimestamp !== null && $this->lastMediaTimestamp !== null) {
            $mediaSpan = max(0, $this->lastMediaTimestamp - $this->firstMediaTimestamp) / 1000;
            $ratio = $mediaSpan / $pullElapsed;
            $abnormal = $ratio > 1.1;
            $this->log(sprintf(
                '源时间轴跨度: %.1fs，拉流时长: %.1fs，比值 %.2f%s（时间戳跳变告警 %d 次）',
                $mediaSpan, $pullElapsed, $ratio,
                $abnormal ? ' —— 超过1.1，时间轴被放大，请检查拉流端重映射与源时间戳' : '',
                $this->tsJumpWarnings
            ), $abnormal ? 'warning' : 'info');
        }
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
