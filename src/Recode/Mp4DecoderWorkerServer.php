<?php

namespace Xiaosongshu\Flv2mp4\Recode;

use RuntimeException;
use Xiaosongshu\Flv2mp4\Codec\H264Decoder;
use Xiaosongshu\Flv2mp4\Codec\H264Encoder;
use Xiaosongshu\Flv2mp4\Codec\NalUtil;
use Xiaosongshu\Flv2mp4\Codec\Scaler\VideoScaler;

/**
 * @purpose mp4重编码分布式架构-GOP worker（解码+缩放+编码，每个worker携带1组运动估计子进程）
 */
final class Mp4DecoderWorkerServer
{
    private H264Decoder $decoder;
    private VideoScaler $scaler;
    private H264Encoder $encoder;
    /**
     * 帧级双缓冲中已 startFrame、等待下一帧到来后 finish 的在途编码帧。
     * 运动子进程计算 N+1 与主进程 CAVLC 编码 N 重叠（与中央编码器同一条流水线），
     * 缺少这层重叠时每帧都要"派运动→等结果→CAVLC"串行，GOP 越少损失越明显。
     */
    private ?array $pendingFrame = null;
    /** 在途视频帧之后到达、须等它先发出的直通帧（音频/丢帧/解码失败回退），维持本路序号严格递增。 */
    private string $deferredOutput = '';
    /** 最近一次视频样本的 DTS（GOP 尾部滞留帧的时间轴顺延基准） */
    private int $lastVideoDts = 0;
    /** 源流是否出现过 B 帧（跨 GOP 粘滞）：让每 GOP 起始即按 POC 重排，避免 GOP 头部帧乱序 */
    private bool $sawBFrames = false;
    /** 是否启用 B 帧编码（config.bframes；请求 baseline 时自动升为 main） */
    private bool $bframes = false;
    /**
     * B 帧调度：显示序待编码帧队列 [{yuv,isKey,idx,seq,dtsMs,outW,outH}]。
     * seq 为该帧在全局序号空间的槽位（决定输出封装顺序）；尾部解码器滞留帧 seq=null。
     */
    private array $bQueue = [];
    /** 最近一个已编码锚点的显示序序号（三元组对齐基准，GOP 内） */
    private int $bLastAnchorIdx = -1;
    /** 最近一个已编码 IDR 的显示序序号（POC 基准） */
    private int $bPocBase = 0;
    /** B 模式编码序计数（GOP 内） */
    private int $bEncodeCounter = 0;
    /** B 模式显示序计数（GOP 内） */
    private int $bDisplayCounter = 0;
    /**
     * fastMotion 快速路径：与直播/FLV 流水线同款——
     * 启动/GOP边界回报 READY，主进程只向 READY worker 投递完整 GOP；
     * 输入按 1MB + 已闭合GOP数门控。关闭时保持旧路径行为。
     */
    private bool $fast = false;

    public function __construct(private array $config)
    {
        $this->decoder = new H264Decoder();
        $this->scaler = new VideoScaler();
        // 编码在 GOP worker 内完成：每个 worker 独立编码器 + 独立运动估计子进程
        $this->encoder = new H264Encoder();
        $this->encoder->motionWorkers = max(1, (int)($config['motionWorkers'] ?? 8));
        $profile = strtolower(trim($config['profile'] ?? 'baseline'));
        // 取值校验前置：非 baseline/main/high 一律回退 baseline（与 setProfile 一致）
        if (!in_array($profile, ['baseline', 'main', 'high'], true)) $profile = 'baseline';
        $this->bframes = !empty($config['bframes']);
        // B 片不属于 baseline 语法：请求 B 帧时至少按 main profile 输出
        if ($this->bframes && $profile === 'baseline') $profile = 'main';
        $this->encoder->setProfile($profile);
        if ($this->bframes) $this->encoder->enableBFrames(true);
        if (!empty($config['fastMotion'])) {
            $this->encoder->setFastMotion(true);
            $this->fast = true;
        }
        if (isset($config['intra8x8QpOffset'])) $this->encoder->intra8x8QpOffset = max(-51, min(51, (int)$config['intra8x8QpOffset']));
    }

    public function run(string $listenAddress, string $outputAddress): void
    {
        $server = @stream_socket_server($listenAddress, $errno, $error);
        if ($server === false) throw new RuntimeException("解码进程监听失败: {$error} ({$errno})");
        $downstream = $this->connect($outputAddress);
        $upstream = @stream_socket_accept($server, 15); fclose($server);
        if ($upstream === false) throw new RuntimeException('解码进程等待主进程连接超时');
        stream_set_blocking($upstream, false); stream_set_blocking($downstream, false);
        // 立即拉起运动估计子进程，让冷启动与主进程派发其它 worker/读取文件并行（与中央编码器同策略）
        $this->encoder->warmupMotionWorkers();
        $input = ''; $output = ''; $response = ''; $upOutput = ''; $ended = false;
        // 快速路径：输入中"已闭合但尚未处理完"的GOP数（口径同 Hls/Flv worker）
        $queuedGops = 0;
        if ($this->fast) {
            // 启动即报告空闲，主进程只向 READY worker 投递完整 GOP
            $upOutput .= HlsPipelineProtocol::frame(HlsPipelineProtocol::READY, 0, ['worker' => true]);
        }
        try {
            while (true) {
                $read = [$downstream];
                if (!$ended) {
                    if ($this->fast) {
                        // 1MB + GOP 双门控：把每 worker 在途算力限制为当前GOP+1个完整GOP
                        if (strlen($input) < HlsPipelineProtocol::INPUT_HIGH_WATERMARK
                            && $queuedGops < HlsPipelineProtocol::INPUT_MAX_QUEUED_GOPS) $read[] = $upstream;
                    } elseif (strlen($input) < HlsPipelineProtocol::HIGH_WATERMARK && strlen($output) < HlsPipelineProtocol::HIGH_WATERMARK) {
                        $read[] = $upstream;
                    }
                }
                $write = $output === '' ? [] : [$downstream];
                if ($upOutput !== '') $write[] = $upstream;
                $except = null; @stream_select($read, $write, $except, 0, 1);
                if (in_array($upstream, $read, true)) {
                    while (true) {
                        $chunk = @fread($upstream, 65536);
                        if ($chunk === false || ($chunk === '' && feof($upstream))) throw new RuntimeException('主进程媒体连接意外关闭');
                        if ($chunk === '') break;
                        $input .= $chunk; if (strlen($input) > HlsPipelineProtocol::MAX_BUFFER_LENGTH) throw new RuntimeException('解码进程输入缓冲超限');
                        if (strlen($chunk) < 65536) break;
                    }
                }
                if ($this->fast) {
                    // 长度前缀快扫统计缓冲内未处理 gopEnd（不解析/不搬运媒体负载）
                    $off = 0; $scanTotal = strlen($input); $bufferedGopEnds = 0;
                    while ($off + 4 <= $scanTotal) {
                        $frameLen = (int)unpack('N', substr($input, $off, 4))[1];
                        if ($frameLen < 9 || $frameLen > HlsPipelineProtocol::MAX_FRAME_LENGTH) break;
                        if ($off + 4 + $frameLen > $scanTotal) break;
                        if (ord($input[$off + 4]) === HlsPipelineProtocol::CONTROL) {
                            $metaLen = (int)unpack('N', substr($input, $off + 9, 4))[1];
                            if ($metaLen <= $frameLen - 9) {
                                $meta = json_decode(substr($input, $off + 13, $metaLen), true);
                                if (is_array($meta) && ($meta['cmd'] ?? '') === 'gopEnd') $bufferedGopEnds++;
                            }
                        }
                        $off += 4 + $frameLen;
                    }
                    $queuedGops = $bufferedGopEnds;
                }
                // 单次 select 唤醒最多处理 2 个事件即返回 select 循环（与 HlsDecoderWorkerServer 同策略）：
                // 防止 GOP 边界多帧背靠背同步阻塞（IDR encodeSliceBody 或 P 帧 collect 均可能阻塞数百ms），
                // 期间 upstream/downstream I/O 冻结 → 反压级联导致 tags/s 归零。
                $eventBudget = 2;
                while ($eventBudget-- > 0 && strlen($output) < HlsPipelineProtocol::HIGH_WATERMARK) {
                    $events = HlsPipelineProtocol::take($input, 1);
                    if ($events === []) break;
                    $event = $events[0];
                    if ($event['type'] === HlsPipelineProtocol::CONTROL) {
                        // GOP 边界标记在 worker 内消费，不转发给输出进程；先冲刷在途帧再重置
                        if (($event['metadata']['cmd'] ?? '') === 'gopEnd') {
                            $gop = (int)($event['metadata']['gop'] ?? -1);
                            $output .= $this->flushGopTail((int)($event['metadata']['tailSeq'] ?? 0), (int)($event['metadata']['tailMax'] ?? 0));
                            $this->resetGopState();
                            if ($this->fast) {
                                // 快速路径：回报该 GOP 完成并重新声明空闲，主进程据此派发下一个完整 GOP
                                $upOutput .= HlsPipelineProtocol::frame(HlsPipelineProtocol::PROGRESS, 0, ['gop' => $gop]);
                                $upOutput .= HlsPipelineProtocol::frame(HlsPipelineProtocol::READY, 0, ['gop' => $gop]);
                            }
                        }
                    } elseif ($event['type'] === HlsPipelineProtocol::END) {
                        // 末帧在途：收尾后再转发 END
                        $output .= $this->flushGopTail((int)($event['metadata']['tailSeq'] ?? 0), (int)($event['metadata']['tailMax'] ?? 0));
                        $output .= HlsPipelineProtocol::frame(HlsPipelineProtocol::END, $event['sequence']);
                        $ended = true;
                    }
                    else $output .= $this->orderFrame($this->transform($event));
                    if (strlen($output) > HlsPipelineProtocol::MAX_BUFFER_LENGTH) throw new RuntimeException('解码进程下游缓冲超限');
                }
                if (in_array($downstream, $write, true)) {
                    while ($output !== '') {
                        $n = @fwrite($downstream, substr($output, 0, 262144));
                        if ($n === false || ($n === 0 && feof($downstream))) throw new RuntimeException('输出进程媒体连接意外关闭');
                        if ($n === 0) break;
                        $output = substr($output, $n);
                        if ($n < 262144) break;
                    }
                }
                // 快速路径：READY/PROGRESS 经媒体上行连接回报主进程（FINISHED 亦在此通道，顺序天然一致）
                if (in_array($upstream, $write, true) && $upOutput !== '') {
                    while ($upOutput !== '') {
                        $n = @fwrite($upstream, substr($upOutput, 0, 262144));
                        if ($n === false || ($n === 0 && feof($upstream))) break;
                        if ($n === 0) break;
                        $upOutput = substr($upOutput, $n);
                        if ($n < 262144) break;
                    }
                }
                if (in_array($downstream, $read, true)) {
                    $chunk = @fread($downstream, 65536);
                    if ($chunk === false || ($chunk === '' && feof($downstream))) throw new RuntimeException('输出进程响应连接意外关闭');
                    $response .= $chunk;
                }
                foreach (HlsPipelineProtocol::take($response, 4) as $event) {
                    if ($event['type'] === HlsPipelineProtocol::ERROR) throw new RuntimeException($event['metadata']['message'] ?? '输出进程失败');
                    if ($event['type'] === HlsPipelineProtocol::FINISHED) {
                        // 必须排在尚未发完的 READY/PROGRESS 之后，避免同一连接上帧序颠倒
                        if ($this->fast && $upOutput !== '') {
                            $this->writeAll($upstream, $upOutput);
                            $upOutput = '';
                        }
                        $this->writeAll($upstream, HlsPipelineProtocol::frame(HlsPipelineProtocol::FINISHED, $event['sequence']));
                        return;
                    }
                }
            }
        } catch (\Throwable $e) {
            @stream_set_blocking($upstream, true);
            @fwrite($upstream, HlsPipelineProtocol::frame(HlsPipelineProtocol::ERROR, 0, ['message' => $e->getMessage()]));
            throw $e;
        } finally { if (is_resource($upstream)) @fclose($upstream); if (is_resource($downstream)) @fclose($downstream); }
    }

    private function transform(array $event): string
    {
        if ($event['type'] !== HlsPipelineProtocol::EVENT) throw new RuntimeException('解码进程收到未知事件');
        $meta = $event['metadata']; $payload = $event['payload'];
        if (($meta['sampleType'] ?? '') !== 'video') return HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $event['sequence'], $meta, $payload);
        $pipeline = $this->config['pipeline'];
        $needTranscode = ((int)($this->config['width'] ?? 0) > 0 && (int)$pipeline['srcWidth'] !== (int)$pipeline['outputWidth'])
            || ((int)($this->config['height'] ?? 0) > 0 && (int)$pipeline['srcHeight'] !== (int)$pipeline['outputHeight'])
            || (int)($this->config['bitrate'] ?? 0) > 0 || !empty($pipeline['dropFrames']) || !empty($this->config['watermark'])
            || $this->bframes;
        if (!$needTranscode) return HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $event['sequence'], $meta, $payload);
        $nals = $this->extractNals($payload);
        $sps = base64_decode($pipeline['srcSps'], true) ?: '';
        $pps = base64_decode($pipeline['srcPps'], true) ?: '';
        if ($sps !== '') array_unshift($nals, ['type' => 7, 'data' => $sps]);
        if ($pps !== '') array_unshift($nals, ['type' => 8, 'data' => $pps]);
        $dropFrame = !empty($meta['drop']);
        $this->lastVideoDts = (int)($meta['dtsMs'] ?? 0);
        // 与 FLV/HLS worker 同策略：抽帧丢弃的帧仍解码 slice 以更新 DPB/P 链参考
        // （不解参考后续保留帧会出现马赛克），但不组装 YUV、跳过去块滤波；
        // IDR 在解码器内部仍强制去块。省下抽帧场景（约 2/3 帧）的大头开销。
        $frame = $this->decoder->decode($nals, false, !$dropFrame, $dropFrame);
        if ($dropFrame) return HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $event['sequence'], $meta, $payload);
        if (!$frame || empty($frame['data'])) { $meta['drop'] = true; return HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $event['sequence'], $meta, $payload); }
        $srcW = (int)$pipeline['srcWidth']; $srcH = (int)$pipeline['srcHeight'];
        $outW = (int)$pipeline['outputWidth']; $outH = (int)$pipeline['outputHeight'];
        $yuv = ($srcW === $outW && $srcH === $outH) ? $frame['data'] : $this->scaler->scaleYUV420P($frame['data'], $srcW, $srcH, $outW, $outH);
        if (!empty($this->config['watermark']) && !empty($this->config['watermarkFile'])) $yuv = $this->applyWatermark($yuv, $outW, $outH, $this->config['watermarkFile']);
        // GOP worker 内直接完成编码（运动估计走本 worker 的子进程），输出侧只做封装
        $this->configureEncoder($outW, $outH, $pipeline);
        // B 模式：显示序帧入 3 帧重排缓冲，凑齐三元组后按编码序（锚点→B→B）落盘
        if ($this->bframes) return $this->feedBFrame($event, $yuv, $outW, $outH, !empty($frame['isIdr']));
        return $this->feedEncoder($event, $meta, $payload, $yuv, $outW, $outH, !empty($frame['isIdr']));
    }

    /**
     * 维持本路连接序号严格递增：在途帧未 finish 期间，后续直通帧（序号更大）先缓存，
     * 待在途帧产出时按"在途帧 + 缓存帧"顺序一并吐出。
     */
    private function orderFrame(string $frame): string
    {
        if ($frame === '') return '';
        if ($this->pendingFrame === null) return $frame;
        $this->deferredOutput .= $frame;
        return '';
    }

    /**
     * 帧级双缓冲喂帧：首帧只 startFrame（异步派运动估计）不产出；
     * 后续帧先 startFrame(N+1) 再 finishFrame() 取 N 的 NAL，
     * 主进程 CAVLC(N) 与运动子进程计算(N+1) 在时间上重叠。
     * 返回上一帧（已 finish）的协议帧（连同被延后的直通帧）；首帧返回空串。
     */
    private function feedEncoder(array $event, array $meta, string $payload, string $yuv, int $outW, int $outH, bool $isKey): string
    {
        $this->encoder->startFrame($yuv, $isKey);
        if ($this->pendingFrame === null) {
            $this->pendingFrame = ['sequence' => $event['sequence'], 'meta' => $meta, 'payload' => $payload, 'w' => $outW, 'h' => $outH, 'isKey' => $isKey];
            return '';
        }
        $frame = $this->buildPendingFrame($this->encoder->finishFrame());
        $this->pendingFrame = ['sequence' => $event['sequence'], 'meta' => $meta, 'payload' => $payload, 'w' => $outW, 'h' => $outH, 'isKey' => $isKey];
        if ($this->deferredOutput !== '') { $frame .= $this->deferredOutput; $this->deferredOutput = ''; }
        return $frame;
    }

    /** 冲刷在途的最后一帧（GOP 边界或 END），并带出其后缓存的直通帧。 */
    private function flushEncodedFrame(): string
    {
        if ($this->pendingFrame === null) return '';
        $frame = $this->buildPendingFrame($this->encoder->finishFrame());
        $this->pendingFrame = null;
        if ($this->deferredOutput !== '') { $frame .= $this->deferredOutput; $this->deferredOutput = ''; }
        return $frame;
    }

    private function buildPendingFrame(array $encodedNals): string
    {
        $p = $this->pendingFrame;
        $annexb = '';
        foreach ($encodedNals as $nal) $annexb .= $nal;
        $meta = $p['meta'];
        $meta['encoded'] = true;
        $meta['keyframe'] = $p['isKey'];
        $meta['encodedVariant'] = ['offset' => 4 + strlen($p['payload']), 'length' => strlen($annexb), 'width' => $p['w'], 'height' => $p['h']];
        return HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $p['sequence'], $meta, pack('N', strlen($p['payload'])) . $p['payload'] . $annexb);
    }

    /**
     * GOP 尾部冲刷：排出解码器重排队列中滞留的 B 帧尾帧，经缩放/编码后占用 dispatcher 预留的连续序号发出；
     * 不足 tailMax 部分以 drop 占位事件补齐，保全局序号连续。
     */
    private function flushGopTail(int $tailSeq, int $tailMax): string
    {
        if ($this->bframes) return $this->flushGopTailB($tailSeq, $tailMax);
        $out = '';
        $emitted = 0;
        if ($tailMax > 0) {
            $frames = $this->decoder->flushOutput();
            $pipeline = $this->config['pipeline'];
            $srcW = (int)$pipeline['srcWidth']; $srcH = (int)$pipeline['srcHeight'];
            $outW = (int)$pipeline['outputWidth']; $outH = (int)$pipeline['outputHeight'];
            $sourceFps = (float)($pipeline['sourceFps'] ?? 0);
            $frameDurMs = $sourceFps > 0.0 ? 1000 / $sourceFps : 40;
            foreach ($frames as $f) {
                if ($emitted >= $tailMax || empty($f['data'])) break;
                $yuv = ($srcW === $outW && $srcH === $outH) ? $f['data'] : $this->scaler->scaleYUV420P($f['data'], $srcW, $srcH, $outW, $outH);
                if (!empty($this->config['watermark']) && !empty($this->config['watermarkFile'])) $yuv = $this->applyWatermark($yuv, $outW, $outH, $this->config['watermarkFile']);
                $this->configureEncoder($outW, $outH, $pipeline);
                $this->encoder->startFrame($yuv, !empty($f['isIdr']));
                if ($this->pendingFrame !== null) {
                    $out .= $this->buildPendingFrame($this->encoder->finishFrame());
                    $this->pendingFrame = null;
                }
                $ts = (int)round($this->lastVideoDts + ($emitted + 1) * $frameDurMs);
                $this->pendingFrame = ['sequence' => $tailSeq + $emitted,
                    'meta' => ['sampleType' => 'video', 'dtsMs' => $ts, 'ctsMs' => 0],
                    'payload' => '', 'w' => $outW, 'h' => $outH, 'isKey' => !empty($f['isIdr'])];
                $emitted++;
            }
        }
        $out .= $this->flushEncodedFrame();
        while ($emitted < $tailMax) {
            $out .= HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $tailSeq + $emitted, ['sampleType' => 'video', 'drop' => true, 'dtsMs' => 0, 'ctsMs' => 0], '');
            $emitted++;
        }
        return $out;
    }

    private function configureEncoder(int $outW, int $outH, array $pipeline): void
    {
        $this->encoder->setResolution($outW, $outH);
        if ((int)($this->config['bitrate'] ?? 0) > 0) $this->encoder->setBitrate((int)$this->config['bitrate']);
        else $this->encoder->setQp((int)($this->config['qp'] ?? 26));
        $encoderFps = isset($pipeline['effectiveTargetFps']) ? (float)$pipeline['effectiveTargetFps'] : (float)($pipeline['sourceFps'] ?? 0);
        if ($encoderFps <= 0.0) $encoderFps = (float)($pipeline['sourceFps'] ?? 0);
        if ($encoderFps > 0) $this->encoder->setFps(max(1, (int)round($encoderFps)));
    }

    /** 显示序帧入队（B 模式），并尝试排出已凑齐的编码三元组。 */
    private function feedBFrame(array $event, string $yuv, int $outW, int $outH, bool $isKeyFrame): string
    {
        $meta = $event['metadata'];
        $this->lastVideoDts = (int)($meta['dtsMs'] ?? 0);
        $this->bQueue[] = [
            'yuv' => $yuv,
            'isKey' => $isKeyFrame,
            'idx' => $this->bDisplayCounter++,
            'seq' => $event['sequence'],
            'dtsMs' => (int)($meta['dtsMs'] ?? 0),
            'outW' => $outW,
            'outH' => $outH,
        ];
        $tail = 0;
        return $this->drainBQueue(false, 0, $tail);
    }

    /**
     * 显示序缓冲 → 编码序落盘（与 FLV worker 同构）。
     * 固定 3 帧延迟的 IBBP：锚点编码序最小、B1 中间、B2 最大。
     */
    private function drainBQueue(bool $flush, int $tailSeq, int &$tailUsed): string
    {
        $out = '';
        while (true) {
            $n = count($this->bQueue);
            if ($n === 0) break;
            $head = $this->bQueue[0];

            // 1) 队首是 IDR：直接作为锚点编码，并把 POC 基准移到本帧
            if ($head['isKey']) {
                $nals = $this->encoder->encodeFrame($head['yuv'], true);
                $out .= $this->emitEncodedB($nals, $head, true, $tailSeq, $tailUsed);
                $this->bLastAnchorIdx = $head['idx'];
                $this->bPocBase = $head['idx'];
                array_shift($this->bQueue);
                continue;
            }

            // 2) 前 3 帧内出现 IDR：先把 IDR 之前的残留帧按 P 编码（下轮再处理 IDR）
            $idrPos = null;
            for ($i = 1; $i < min(3, $n); $i++) if ($this->bQueue[$i]['isKey']) { $idrPos = $i; break; }
            if ($idrPos !== null) {
                for ($i = 0; $i < $idrPos; $i++) $out .= $this->emitBAnchorP(array_shift($this->bQueue), $tailSeq, $tailUsed);
                continue;
            }

            // 3) 标准三元组（三帧均携带事件序号）
            if ($n >= 3 && $this->bLastAnchorIdx >= 0
                && $head['idx'] === $this->bLastAnchorIdx + 1
                && $this->bQueue[1]['idx'] === $this->bLastAnchorIdx + 2
                && $this->bQueue[2]['idx'] === $this->bLastAnchorIdx + 3
                && $head['seq'] !== null && $this->bQueue[1]['seq'] !== null && $this->bQueue[2]['seq'] !== null) {
                $b1 = $this->bQueue[0];
                $b2 = $this->bQueue[1];
                $anchor = $this->bQueue[2];
                // 槽位按升序重排后配给编码序：锚点取最小、其次 B1、B2（输出按序号升序写出 = 解码序）
                $slots = [$b1['seq'], $b2['seq'], $anchor['seq']];
                sort($slots);
                $this->encoder->snapshotBRefL0();
                $this->encoder->poc = 2 * ($anchor['idx'] - $this->bPocBase);
                $anchorNals = $this->encoder->encodeFrame($anchor['yuv'], false);
                $out .= $this->wrapEncodedBFrame($anchorNals, $slots[0], false, $this->nextCts($anchor), $anchor);
                $b1Nals = $this->encoder->encodeBFrame($b1['yuv'], 2 * ($b1['idx'] - $this->bPocBase));
                $out .= $this->wrapEncodedBFrame($b1Nals, $slots[1], false, $this->nextCts($b1), $b1);
                $b2Nals = $this->encoder->encodeBFrame($b2['yuv'], 2 * ($b2['idx'] - $this->bPocBase));
                $out .= $this->wrapEncodedBFrame($b2Nals, $slots[2], false, $this->nextCts($b2), $b2);
                array_splice($this->bQueue, 0, 3);
                $this->bLastAnchorIdx = $anchor['idx'];
                continue;
            }

            // 4) 冲刷时把残留帧按显示序 P 编码
            if ($flush) {
                $e = array_shift($this->bQueue);
                if ($this->bLastAnchorIdx < 0) {
                    $nals = $this->encoder->encodeFrame($e['yuv'], true);
                    $out .= $this->emitEncodedB($nals, $e, true, $tailSeq, $tailUsed);
                    $this->bPocBase = $e['idx'];
                } else {
                    $out .= $this->emitBAnchorP($e, $tailSeq, $tailUsed);
                }
                $this->bLastAnchorIdx = $e['idx'];
                continue;
            }
            break;
        }
        return $out;
    }

    /** 把一帧按锚点 P 编码（参考上一锚点重建帧），推进锚点基准。 */
    private function emitBAnchorP(array $e, int $tailSeq, int &$tailUsed): string
    {
        $this->encoder->snapshotBRefL0();
        $this->encoder->poc = 2 * ($e['idx'] - $this->bPocBase);
        $nals = $this->encoder->encodeFrame($e['yuv'], false);
        $out = $this->emitEncodedB($nals, $e, false, $tailSeq, $tailUsed);
        $this->bLastAnchorIdx = $e['idx'];
        return $out;
    }

    /** 单帧（锚点 IDR / 尾部 P）编码落盘：有事件序号用其槽位，否则占用 gopEnd 预留槽位。 */
    private function emitEncodedB(array $nals, array $e, bool $isKey, int $tailSeq, int &$tailUsed): string
    {
        $seq = $e['seq'] ?? ($tailSeq + $tailUsed++);
        return $this->wrapEncodedBFrame($nals, $seq, $isKey, $this->nextCts($e), $e);
    }

    /** 当前编码序下的 CTS = PTS − DTS =（显示序 − 编码序）×帧时长，并推进编码序计数。 */
    private function nextCts(array $e): int
    {
        $cts = (int)round(($e['idx'] - $this->bEncodeCounter) * $this->bFrameDurMs());
        $this->bEncodeCounter++;
        return $cts;
    }

    /** B 模式帧时长（毫秒）：抽帧取目标帧率，否则取源帧率。 */
    private function bFrameDurMs(): float
    {
        $pipeline = $this->config['pipeline'];
        $sourceFps = (float)($pipeline['sourceFps'] ?? 0);
        if ($sourceFps <= 0.0) $sourceFps = 25.0;
        $targetFps = (int)($this->config['fps'] ?? 0);
        $fps = ($targetFps > 0 && $targetFps < $sourceFps - 0.01) ? (float)$targetFps : $sourceFps;
        return $fps > 0 ? 1000.0 / $fps : 40.0;
    }

    /**
     * B 模式帧封装：MP4 用 sampleType='video' + dtsMs/ctsMs metadata（容器原生支持负 CTS，
     * 不需要 FLV 的 ctsShift 偏移）。负载 = 4 字节空前缀 + AnnexB NAL，encodedVariant 指向 NAL 起点。
     */
    private function wrapEncodedBFrame(array $nals, int $seq, bool $isKey, int $ctsMs, array $e): string
    {
        $annexb = '';
        foreach ($nals as $nal) $annexb .= $nal;
        $meta = [
            'sampleType' => 'video',
            'dtsMs' => (int)($e['dtsMs'] ?? 0),
            'ctsMs' => $ctsMs,
            'encoded' => true,
            'keyframe' => $isKey,
            'encodedVariant' => ['offset' => 4, 'length' => strlen($annexb), 'width' => (int)($e['outW'] ?? 0), 'height' => (int)($e['outH'] ?? 0)],
        ];
        return HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $seq, $meta, pack('N', 0) . $annexb);
    }

    /**
     * B 模式 GOP 尾部冲刷：先把解码器重排队列滞留的显示序尾帧补入 B 缓冲（无事件序号），
     * 再按显示序以 P 帧补齐残留帧，最后不足 tailMax 部分以 drop 占位补齐。
     */
    private function flushGopTailB(int $tailSeq, int $tailMax): string
    {
        $tailUsed = 0;
        if ($tailMax > 0) {
            $pipeline = $this->config['pipeline'];
            $outW = (int)$pipeline['outputWidth']; $outH = (int)$pipeline['outputHeight'];
            foreach ($this->decoder->flushOutput() as $f) {
                if (empty($f['data'])) continue;
                $srcW = (int)$pipeline['srcWidth']; $srcH = (int)$pipeline['srcHeight'];
                $yuv = ($srcW === $outW && $srcH === $outH) ? $f['data'] : $this->scaler->scaleYUV420P($f['data'], $srcW, $srcH, $outW, $outH);
                if (!empty($this->config['watermark']) && !empty($this->config['watermarkFile'])) $yuv = $this->applyWatermark($yuv, $outW, $outH, $this->config['watermarkFile']);
                $this->bQueue[] = ['yuv' => $yuv, 'isKey' => !empty($f['isIdr']), 'idx' => $this->bDisplayCounter++,
                    'seq' => null, 'dtsMs' => $this->lastVideoDts, 'outW' => $outW, 'outH' => $outH];
            }
        }
        $out = $this->drainBQueue(true, $tailSeq, $tailUsed);
        while ($tailUsed < $tailMax) {
            $out .= HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $tailSeq + $tailUsed, ['sampleType' => 'video', 'drop' => true, 'dtsMs' => 0, 'ctsMs' => 0], '');
            $tailUsed++;
        }
        return $out;
    }

    private function resetGopState(): void
    {
        // GOP 边界：解码器重建（SPS/PPS 每帧随样本注入），编码器轻量重置并复用运动估计子进程
        // B 帧粘滞：源流出现过 B 帧后，每个 GOP 起始即按 POC 重排，避免 GOP 头部帧乱序
        $this->sawBFrames = $this->sawBFrames || $this->decoder->reorderByPoc;
        $this->pendingFrame = null;
        $this->deferredOutput = '';
        $this->bQueue = [];
        $this->bLastAnchorIdx = -1;
        $this->bPocBase = 0;
        $this->bEncodeCounter = 0;
        $this->bDisplayCounter = 0;
        $this->decoder = new H264Decoder();
        $this->decoder->reorderByPoc = $this->sawBFrames;
        $this->encoder->resetGopState();
        // 每个 GOP 边界主动收一次 cycle GC（与 HlsDecoderWorkerServer 同策略）
        gc_collect_cycles();
    }

    private function extractNals(string $data): array
    {
        $result = []; $offset = 0; $total = strlen($data);
        while ($offset + 4 <= $total) { $length = unpack('N', substr($data, $offset, 4))[1]; $offset += 4; if ($offset + $length > $total) break; $clean = NalUtil::removeEmulationPrevention(substr($data, $offset, $length)); $offset += $length; $result[] = ['type' => ord($clean[0]) & 0x1f, 'data' => substr($clean, 1), 'raw' => $clean]; }
        return $result;
    }

    private function applyWatermark(string $yuv, int $w, int $h, string $file): string
    {
        $data = file_get_contents($file); if ($data === false) throw new RuntimeException("无法读取水印文件: {$file}");
        if (!preg_match('/_(\d+)x(\d+)$/', basename($file, '.yuv'), $m)) { $ww = 80; $wh = 16; } else { $ww = (int)$m[1]; $wh = (int)$m[2]; }
        if ($ww > $w || $wh > $h || strlen($data) < $ww * $wh * 3 / 2) throw new RuntimeException('水印文件尺寸不匹配');
        $ySize = $w * $h; $uvSize = ($w >> 1) * ($h >> 1); $wySize = $ww * $wh; $wuvSize = $wySize >> 2;
        for ($row = 0; $row < $wh; $row++) for ($col = 0; $col < $ww; $col++) $yuv[$row * $w + $col] = $data[$row * $ww + $col];
        for ($row = 0; $row < ($wh >> 1); $row++) for ($col = 0; $col < ($ww >> 1); $col++) { $dst = $row * ($w >> 1) + $col; $src = $row * ($ww >> 1) + $col; $yuv[$ySize + $dst] = $data[$wySize + $src]; $yuv[$ySize + $uvSize + $dst] = $data[$wySize + $wuvSize + $src]; }
        return $yuv;
    }

    private function connect(string $address)
    {
        $deadline = microtime(true) + 15; do { $socket = @stream_socket_client($address, $errno, $error, 0.2); if ($socket !== false) return $socket; usleep(1); } while (microtime(true) < $deadline);
        throw new RuntimeException("无法连接输出进程: {$error} ({$errno})");
    }

    private function writeAll($socket, string $data): void
    {
        stream_set_blocking($socket, true); $offset = 0; while ($offset < strlen($data)) { $n = fwrite($socket, substr($data, $offset)); if ($n === false || $n === 0) throw new RuntimeException('无法发送完成响应'); $offset += $n; }
    }
}
