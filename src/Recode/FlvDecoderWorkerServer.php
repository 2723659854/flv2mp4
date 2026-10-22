<?php

namespace Xiaosongshu\Flv2mp4\Recode;

use RuntimeException;
use Throwable;
use Xiaosongshu\Flv2mp4\Codec\H264Decoder;
use Xiaosongshu\Flv2mp4\Codec\H264Encoder;
use Xiaosongshu\Flv2mp4\Codec\NalUtil;
use Xiaosongshu\Flv2mp4\Codec\Scaler\VideoScaler;

/**
 * @purpose flv重编码分布式架构-GOP worker（解码+缩放+编码，每个worker携带1组运动估计子进程）
 * @author yanglong
 */
final class FlvDecoderWorkerServer
{
    private H264Decoder $decoder;
    private VideoScaler $scaler;
    private H264Encoder $encoder;
    private string $sps = '';
    private string $pps = '';
    private int $width = 0;
    private int $height = 0;
    /**
     * 帧级双缓冲中已 startFrame、等待下一帧到来后 finish 的在途编码帧。
     * 运动子进程计算 N+1 与主进程 CAVLC 编码 N 重叠（与中央编码器同一条流水线），
     * 缺少这层重叠时每帧都要"派运动→等结果→CAVLC"串行，GOP 越少损失越明显。
     */
    private ?array $pendingFrame = null;
    /**
     * 在途视频帧（pendingFrame）之后到达、但必须等它先发出的直通帧（音频/丢帧/解码失败回退）。
     * 输出进程的反压闸门依赖"每路连接序号严格递增"，不能让后续帧在同路连接上反超在途帧。
     */
    private string $deferredOutput = '';
    /** 最近一次视频 NALU tag 的时间戳（GOP 尾部滞留帧的时间轴顺延基准） */
    private int $lastVideoTs = 0;
    /** 源流是否出现过 B 帧（跨 GOP 粘滞）：让每 GOP 起始即按 POC 重排，避免 GOP 头部帧乱序 */
    private bool $sawBFrames = false;
    /** 是否启用 B 帧编码（config.bframes；请求 baseline 时自动升为 main） */
    private bool $bframes = false;
    /**
     * B 帧调度：显示序待编码帧队列 [{yuv,isKey,idx,seq,timestamp,sourceFps,w,h}]。
     * seq 为该帧在全局序号空间的槽位（决定输出封装顺序）；解码器重排滞留的尾部帧无事件序号（null），
     * 由 gopEnd 预留槽位补发。
     */
    private array $bQueue = [];
    /** 最近一个已编码锚点的显示序序号（三元组对齐基准，GOP 内） */
    private int $bLastAnchorIdx = -1;
    /** 最近一个已编码 IDR 的显示序序号（POC 基准） */
    private int $bPocBase = 0;
    /** B 模式编码序计数（GOP 内）：输出侧按写出序号×帧时长推导全局 DTS，故此处只需编码/显示序的相对差 */
    private int $bEncodeCounter = 0;
    /** B 模式显示序计数（GOP 内） */
    private int $bDisplayCounter = 0;
    /**
     * fastMotion 快速路径：与直播流水线同款——
     * 启动/GOP边界向主进程回报 READY，主进程只向 READY worker 投递完整 GOP；
     * 输入按 1MB + 已闭合GOP数门控（频闪小帧压缩后字节小但解码昂贵）。
     * 关闭时保持旧路径行为（48MB 字节门控、无 READY 回报）。
     */
    private bool $fast = false;

    public function __construct(private array $config)
    {
        ini_set('memory_limit', (string)($config['memory_limit'] ?? '512M'));
        $this->decoder = new H264Decoder();
        $this->scaler = new VideoScaler();
        // 编码在 GOP worker 内完成：每个 worker 独立编码器 + 独立运动估计子进程（GOP边界只重置状态不重建）
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
        // 快速路径：输入中"已闭合但尚未处理完"的GOP数（口径同 HlsDecoderWorkerServer）
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
                        $cmd = $event['metadata']['cmd'] ?? '';
                        if ($cmd === 'config') $this->parseConfiguration(substr($event['payload'], 5));
                        elseif ($cmd === 'gopEnd') {
                            // 先冲刷在途帧再重置：保证 GOP 最后一帧正常输出且不跨 GOP 残留流水线状态
                            $gop = (int)($event['metadata']['gop'] ?? -1);
                            $tailOut = $this->flushGopTail((int)($event['metadata']['tailSeq'] ?? 0), (int)($event['metadata']['tailMax'] ?? 0));
                            $output .= $tailOut;
                            $this->resetGopState();
                            if ($this->fast) {
                                // 快速路径：回报该 GOP 完成并重新声明空闲，主进程据此派发下一个完整 GOP
                                $upOutput .= HlsPipelineProtocol::frame(HlsPipelineProtocol::PROGRESS, 0, ['gop' => $gop]);
                                $upOutput .= HlsPipelineProtocol::frame(HlsPipelineProtocol::READY, 0, ['gop' => $gop]);
                            }
                        }
                    } elseif ($event['type'] === HlsPipelineProtocol::END) {
                        // 末帧在途：收尾后再转发 END，输出进程必然先收齐全部媒体再看到 END
                        $tailOut = $this->flushGopTail((int)($event['metadata']['tailSeq'] ?? 0), (int)($event['metadata']['tailMax'] ?? 0));
                        $output .= $tailOut;
                        $output .= HlsPipelineProtocol::frame(HlsPipelineProtocol::END, $event['sequence']);
                        $ended = true;
                    } else {
                        $output .= $this->orderFrame($this->transform($event));
                    }
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
        } catch (Throwable $e) {
            if (is_resource($upstream)) {
                try { $this->writeAll($upstream, HlsPipelineProtocol::frame(HlsPipelineProtocol::ERROR, 0, ['message' => $e->getMessage()])); } catch (Throwable) {}
            }
            throw $e;
        } finally { if (is_resource($upstream)) @fclose($upstream); if (is_resource($downstream)) @fclose($downstream); }
    }

    private function transform(array $event): string
    {
        if ($event['type'] !== HlsPipelineProtocol::EVENT) throw new RuntimeException('解码进程收到未知事件');
        $meta = $event['metadata']; $body = $event['payload'];
        if (($meta['tagType'] ?? 0) !== 9 || strlen($body) < 5 || (ord($body[0]) & 0x0f) !== 7) return HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $event['sequence'], $meta, $body);
        $packetType = ord($body[1]);
        if ($packetType === 0) { $this->parseConfiguration(substr($body, 5)); return HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $event['sequence'], $meta, $body); }
        if ($packetType !== 1 || $this->width === 0) return HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $event['sequence'], $meta, $body);
        $nals = $this->extractNals(substr($body, 5));
        if ($this->sps !== '') array_unshift($nals, ['type' => 7, 'data' => $this->sps]);
        if ($this->pps !== '') array_unshift($nals, ['type' => 8, 'data' => $this->pps]);
        $dropFrame = !empty($meta['drop']);
        $this->lastVideoTs = (int)($meta['timestamp'] ?? 0);
        $sourceFps = isset($meta['sourceFps']) ? (float)$meta['sourceFps'] : (float)($this->config['source_fps'] ?? 0);
        if ($sourceFps <= 0.0) $sourceFps = 0.0;
        $targetFps = (int)($this->config['fps'] ?? 0);
        $dropFrames = $targetFps > 0 && $sourceFps > 0.0 && $targetFps < $sourceFps - 0.01;
        $w = ($this->config['width'] ?? 0) > 0 ? (int)$this->config['width'] : $this->width;
        $h = ($this->config['height'] ?? 0) > 0 ? (int)$this->config['height'] : $this->height;
        $needTranscode = ($w !== $this->width || $h !== $this->height)
            || (int)($this->config['bitrate'] ?? 0) > 0 || $dropFrames
            || $this->bframes
            || (!empty($this->config['watermark']) && !empty($this->config['watermarkFile']));
        // 无需转码（纯直通）时原封不动转发，输出侧同样判定为直通
        if (!$needTranscode) return HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $event['sequence'], $meta, $body);
        // 抽帧丢弃的帧仍需解码以维持 GOP 内 P 链参考，否则后续保留帧会参考错误画面出现马赛克
        // （与 HlsDecoderWorkerServer 同策略）：不生成 YUV、跳过去块滤波，省去大部分开销；
        // IDR 在解码器内部仍强制执行去块。原 tag 回传占用序号，输出侧按 meta.drop 丢弃。
        if ($dropFrame) {
            $this->decoder->decode($nals, false, false, true);
            return HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $event['sequence'], $meta, $body);
        }
        $frame = $this->decoder->decode($nals, false, true, false);
        if (!$frame || empty($frame['data'])) { $meta['drop'] = true; return HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $event['sequence'], $meta, $body); }
        $yuv = ($w === $this->width && $h === $this->height) ? $frame['data'] : $this->scaler->scaleYUV420P($frame['data'], $this->width, $this->height, $w, $h);
        if (!empty($this->config['watermark']) && !empty($this->config['watermarkFile'])) $yuv = $this->applyWatermark($yuv, $w, $h, $this->config['watermarkFile']);
        // GOP worker 内直接完成编码（运动估计走本 worker 的子进程），输出侧只做封装
        $this->configureEncoder($w, $h, $dropFrames, $sourceFps, $targetFps);
        // B 模式：显示序帧入 3 帧重排缓冲，凑齐三元组后按编码序（锚点→B→B）落盘
        if ($this->bframes) return $this->feedBFrame($event, $yuv, $w, $h, !empty($frame['isIdr']));
        return $this->feedEncoder($event, $meta, $body, $yuv, $w, $h, !empty($frame['isIdr']));
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
    private function feedEncoder(array $event, array $meta, string $body, string $yuv, int $w, int $h, bool $isKeyFrame): string
    {
        $this->encoder->startFrame($yuv, $isKeyFrame);
        if ($this->pendingFrame === null) {
            $this->pendingFrame = ['sequence' => $event['sequence'], 'meta' => $meta, 'body' => $body, 'w' => $w, 'h' => $h, 'isKey' => $isKeyFrame];
            return '';
        }
        $frame = $this->buildPendingFrame($this->encoder->finishFrame());
        $this->pendingFrame = ['sequence' => $event['sequence'], 'meta' => $meta, 'body' => $body, 'w' => $w, 'h' => $h, 'isKey' => $isKeyFrame];
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
        $meta['keyFrame'] = $p['isKey'];
        $meta['encodedVariant'] = ['offset' => 4 + strlen($p['body']), 'length' => strlen($annexb), 'width' => $p['w'], 'height' => $p['h']];
        return HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $p['sequence'], $meta, pack('N', strlen($p['body'])) . $p['body'] . $annexb);
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
            $frames = $this->width > 0 ? $this->decoder->flushOutput() : [];
            $sourceFps = isset($this->config['source_fps']) ? (float)$this->config['source_fps'] : 0.0;
            if ($sourceFps <= 0.0) $sourceFps = 0.0;
            $targetFps = (int)($this->config['fps'] ?? 0);
            $dropFrames = $targetFps > 0 && $sourceFps > 0.0 && $targetFps < $sourceFps - 0.01;
            $w = ($this->config['width'] ?? 0) > 0 ? (int)$this->config['width'] : $this->width;
            $h = ($this->config['height'] ?? 0) > 0 ? (int)$this->config['height'] : $this->height;
            $frameDurMs = $sourceFps > 0.0 ? 1000 / $sourceFps : 40;
            foreach ($frames as $f) {
                if ($emitted >= $tailMax || empty($f['data'])) break;
                $yuv = ($w === $this->width && $h === $this->height) ? $f['data'] : $this->scaler->scaleYUV420P($f['data'], $this->width, $this->height, $w, $h);
                if (!empty($this->config['watermark']) && !empty($this->config['watermarkFile'])) $yuv = $this->applyWatermark($yuv, $w, $h, $this->config['watermarkFile']);
                $this->configureEncoder($w, $h, $dropFrames, $sourceFps, $targetFps);
                $this->encoder->startFrame($yuv, !empty($f['isIdr']));
                if ($this->pendingFrame !== null) {
                    $out .= $this->buildPendingFrame($this->encoder->finishFrame());
                    $this->pendingFrame = null;
                }
                $ts = (int)round($this->lastVideoTs + ($emitted + 1) * $frameDurMs);
                $this->pendingFrame = ['sequence' => $tailSeq + $emitted,
                    'meta' => ['tagType' => 9, 'timestamp' => $ts, 'sourceFps' => $sourceFps],
                    'body' => '', 'w' => $w, 'h' => $h, 'isKey' => !empty($f['isIdr'])];
                $emitted++;
            }
        }
        $out .= $this->flushEncodedFrame();
        while ($emitted < $tailMax) {
            $out .= HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $tailSeq + $emitted, ['tagType' => 9, 'drop' => true], '');
            $emitted++;
        }
        return $out;
    }

    /** 显示序帧入队（B 模式），并尝试排出已凑齐的编码三元组。 */
    private function feedBFrame(array $event, string $yuv, int $w, int $h, bool $isKeyFrame): string
    {
        $meta = $event['metadata'];
        $this->lastVideoTs = (int)($meta['timestamp'] ?? 0);
        $this->bQueue[] = [
            'yuv' => $yuv,
            'isKey' => $isKeyFrame,
            'idx' => $this->bDisplayCounter++,
            'seq' => $event['sequence'],
            'timestamp' => (int)($meta['timestamp'] ?? 0),
            'sourceFps' => $meta['sourceFps'] ?? null,
            'w' => $w,
            'h' => $h,
        ];
        $tail = 0;
        return $this->drainBQueue(false, 0, $tail);
    }

    /**
     * 显示序缓冲 → 编码序落盘（与单进程 FlvRecoder::drainBQueue 同构）。
     * 固定 3 帧延迟的 IBBP：锚点在显示序 1、4、7…（IDR 为首帧），三元组编码序 = 锚点 → 前 2 个 B。
     * 关键差异：输出按序号升序封装，故三元组内锚点必须占用【最小】序号，才能保证封装顺序=解码序。
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

            // 3) 标准三元组（三帧均携带事件序号；尾部解码器滞留帧不参与）
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

            // 4) 冲刷时把残留帧按显示序 P 编码（GOP 尾部不足一个三元组；其真实 L1 属下一 GOP 不可见）
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
            break; // 等待后续帧凑齐三元组
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

    /** B 模式帧时长（毫秒）：抽帧取目标帧率，否则取源帧率；与输出侧推导 DTS 的时基保持一致。 */
    private function bFrameDurMs(): float
    {
        $sourceFps = (float)($this->config['source_fps'] ?? 0);
        if ($sourceFps <= 0.0) $sourceFps = 25.0;
        $targetFps = (int)($this->config['fps'] ?? 0);
        $fps = ($targetFps > 0 && $targetFps < $sourceFps - 0.01) ? (float)$targetFps : $sourceFps;
        return $fps > 0 ? 1000.0 / $fps : 40.0;
    }

    /**
     * B 模式帧封装：把编码 NAL 与 cts 附在 meta 上（DTS 由输出侧按写出序号×帧时长推导）。
     * 负载 = 4 字节 body 长度前缀(0) + AnnexB NAL，encodedVariant 指向 NAL 起点。
     */
    private function wrapEncodedBFrame(array $nals, int $seq, bool $isKey, int $cts, array $e): string
    {
        $annexb = '';
        foreach ($nals as $nal) $annexb .= $nal;
        $meta = [
            'tagType' => 9,
            'timestamp' => (int)($e['timestamp'] ?? 0),
            'encoded' => true,
            'keyFrame' => $isKey,
            'cts' => $cts,
            'encodedVariant' => ['offset' => 4, 'length' => strlen($annexb), 'width' => (int)($e['w'] ?? 0), 'height' => (int)($e['h'] ?? 0)],
        ];
        if (isset($e['sourceFps'])) $meta['sourceFps'] = $e['sourceFps'];
        return HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $seq, $meta, pack('N', 0) . $annexb);
    }

    /**
     * B 模式 GOP 尾部冲刷：先把解码器重排队列滞留的显示序尾帧补入 B 缓冲（无事件序号），
     * 再按显示序以 P 帧补齐残留帧，最后不足 tailMax 部分以 drop 占位补齐。
     */
    private function flushGopTailB(int $tailSeq, int $tailMax): string
    {
        $tailUsed = 0;
        if ($tailMax > 0 && $this->width > 0) {
            $sourceFps = isset($this->config['source_fps']) ? (float)$this->config['source_fps'] : 0.0;
            if ($sourceFps <= 0.0) $sourceFps = 0.0;
            $w = ($this->config['width'] ?? 0) > 0 ? (int)$this->config['width'] : $this->width;
            $h = ($this->config['height'] ?? 0) > 0 ? (int)$this->config['height'] : $this->height;
            foreach ($this->decoder->flushOutput() as $f) {
                if (empty($f['data'])) continue;
                $yuv = ($w === $this->width && $h === $this->height) ? $f['data'] : $this->scaler->scaleYUV420P($f['data'], $this->width, $this->height, $w, $h);
                if (!empty($this->config['watermark']) && !empty($this->config['watermarkFile'])) $yuv = $this->applyWatermark($yuv, $w, $h, $this->config['watermarkFile']);
                $this->bQueue[] = ['yuv' => $yuv, 'isKey' => !empty($f['isIdr']), 'idx' => $this->bDisplayCounter++,
                    'seq' => null, 'timestamp' => $this->lastVideoTs,
                    'sourceFps' => $sourceFps > 0.0 ? $sourceFps : null, 'w' => $w, 'h' => $h];
            }
        }
        $out = $this->drainBQueue(true, $tailSeq, $tailUsed);
        while ($tailUsed < $tailMax) {
            $out .= HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $tailSeq + $tailUsed, ['tagType' => 9, 'drop' => true], '');
            $tailUsed++;
        }
        return $out;
    }

    private function configureEncoder(int $w, int $h, bool $dropFrames, float $sourceFps, int $targetFps): void
    {
        $this->encoder->setResolution($w, $h);
        if ((int)($this->config['bitrate'] ?? 0) > 0) $this->encoder->setBitrate((int)$this->config['bitrate']);
        else $this->encoder->setQp((int)($this->config['qp'] ?? 26));
        $encoderFps = $dropFrames ? (float)$targetFps : ($sourceFps > 0.0 ? $sourceFps : null);
        if ($encoderFps !== null && $encoderFps > 0) $this->encoder->setFps(max(1, (int)round($encoderFps)));
    }

    private function resetGopState(): void
    {
        // GOP 边界：解码器重建（参考链/帧计数耦合较多），编码器轻量重置并复用已连接的运动估计子进程
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
        if ($this->sps !== '') {
            $this->decoder->decode([['type' => 7, 'data' => $this->sps]], true);
            $this->width = $this->decoder->getWidth();
            $this->height = $this->decoder->getHeight();
        }
        $this->encoder->resetGopState();
        // 每个 GOP 边界主动收一次 cycle GC（与 HlsDecoderWorkerServer 同策略）：
        // 12 个 GOP worker 近似同步启动，显式 GC 打散下一次 full collection 的同步停顿。
        gc_collect_cycles();
    }

    private function parseConfiguration(string $data): void
    {
        if (strlen($data) < 7) return; $offset = 5; $count = ord($data[$offset++]) & 0x1f;
        for ($i = 0; $i < $count; $i++) { $length = unpack('n', substr($data, $offset, 2))[1]; $offset += 2; $raw = substr($data, $offset, $length); $offset += $length; $this->sps = substr(NalUtil::removeEmulationPrevention($raw), 1); $this->decoder->decode([['type' => 7, 'data' => $this->sps]], true); $this->width = $this->decoder->getWidth(); $this->height = $this->decoder->getHeight(); }
        if ($offset >= strlen($data)) return; $count = ord($data[$offset++]);
        for ($i = 0; $i < $count; $i++) { $length = unpack('n', substr($data, $offset, 2))[1]; $offset += 2; $raw = substr($data, $offset, $length); $offset += $length; $this->pps = substr(NalUtil::removeEmulationPrevention($raw), 1); }
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
