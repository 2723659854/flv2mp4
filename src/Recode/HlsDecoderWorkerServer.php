<?php

namespace Xiaosongshu\Flv2mp4\Recode;

use RuntimeException;
use Throwable;
use Xiaosongshu\Flv2mp4\Codec\H264Decoder;
use Xiaosongshu\Flv2mp4\Codec\H264Encoder;
use Xiaosongshu\Flv2mp4\Codec\NalUtil;
use Xiaosongshu\Flv2mp4\Codec\Scaler\VideoScaler;

/**
 * @purpose flv转hls分布式架构-解码服务端
 * @author yanglong
 */
final class HlsDecoderWorkerServer
{
    private H264Decoder $decoder;
    private H264Encoder $encoder;
    private ?VideoScaler $scaler;
    private array $encoders = []; // 保留：run() 里 warmupMotionWorkers 还 foreach
    private string $sps = '';
    private string $pps = '';
    private int $width = 0;
    private int $height = 0;
    /**
     * 帧级双缓冲中已 startFrame（所有 profile）、等待下一帧到来后 finish 的在途编码帧。
     * 运动子进程计算 N+1 与主进程 CAVLC 编码 N 重叠，缺少这层重叠时每帧都要
     * "派运动→等结果→CAVLC" 串行（与 FLV/MP4 worker 同款优化，输出 NAL 不变）。
     */
    private ?array $pendingFrame = null;
    /**
     * 在途视频帧之后到达、但必须等它先发出的直通帧（音频/丢帧/序列头/解码失败回退）。
     * 输出进程依赖每路连接序号严格递增，不能让后续帧在同路连接上反超在途帧。
     */
    private string $deferredOutput = '';
    /** 最近一个已编码保留帧的时间戳（tail 帧时间戳延续基准） */
    private int $lastVideoTs = 0;
    /** 源帧率（主进程随视频 meta 下发），用于推导 tail 帧时间戳步长 */
    private float $sourceFps = 0.0;
    /** B 帧粘滞：源流出现过 B 帧后，后续每个 GOP 起始即按 POC 重排 */
    private bool $sawBFrames = false;
    /** 是否启用 B 帧编码（baseline 自动升为 main） */
    private bool $bframes = false;
    /**
     * B 帧调度：显示序待编码帧队列 [{yuv, isKey, idx, seq}]。
     */
    private array $bQueue = [];
    /** 最近一个已编码锚点的显示序序号；三元组对齐基准 */
    private int $bLastAnchorIdx = -1;
    /** 最近一个已编码 IDR 的显示序序号；POC 基准 */
    private int $bPocBase = 0;
    /** B 模式编码序计数（GOP 内） */
    private int $bEncodeCounter = 0;
    /** B 模式显示序计数（GOP 内） */
    private int $bDisplayCounter = 0;

    public function __construct(private array $profiles)
    {
        $this->decoder = new H264Decoder();
        // 单 profile 架构：HLS 只取第一个 profile
        $profileName = array_key_first($profiles);
        $profile = $profiles[$profileName];
        $encoder = new H264Encoder();
        $encoder->motionWorkers = max(1, (int)($profile['motionWorkers'] ?? 8));
        $profileStr = strtolower(trim($profile['profile'] ?? 'baseline'));
        if (!in_array($profileStr, ['baseline', 'main', 'high'], true)) $profileStr = 'baseline';
        $this->bframes = !empty($profile['bframes']);
        if ($this->bframes && $profileStr === 'baseline') $profileStr = 'main';
        $encoder->setProfile($profileStr);
        if ($this->bframes) $encoder->enableBFrames(true);
        if (!empty($profile['fastMotion'])) $encoder->setFastMotion(true);
        $this->encoder = $encoder;
        $this->encoders = [$profileName => $encoder]; // run() warmupMotionWorkers 循环用
        $this->scaler = new VideoScaler();
    }

    public function run(string $listenAddress, string $outputAddress, string $controlAddress = ''): void
    {
        $server = @stream_socket_server($listenAddress, $errno, $error);
        if ($server === false) throw new RuntimeException("解码进程监听失败: {$error} ({$errno})");
        // 独立控制连接：finish 屏障走此通道，不被48MB媒体在途缓冲挡在后面
        $ctrlServer = null;
        if ($controlAddress !== '') {
            $ctrlServer = @stream_socket_server($controlAddress, $errno, $error);
            if ($ctrlServer === false) throw new RuntimeException("解码进程控制端口监听失败: {$error} ({$errno})");
            stream_set_blocking($ctrlServer, false);
        }
        $downstream = $this->connect($outputAddress);
        $upstream = @stream_socket_accept($server, 5);
        fclose($server);
        if ($upstream === false) throw new RuntimeException('解码进程等待主进程连接超时');
        stream_set_blocking($upstream, false);
        stream_set_blocking($downstream, false);
        // 立即拉起各 profile 的运动估计子进程，让 PHP 冷启动与主进程派发/读文件并行
        foreach ($this->encoders as $encoder) $encoder->warmupMotionWorkers();
        $input = '';
        $output = '';
        $upOutput = '';
        $downstreamInput = '';
        $ctrlConn = null;
        $ctrlInput = '';
        $ended = false;
        $finishing = false;
        $endSent = false;
        // 输入中"已闭合但尚未处理完"的GOP数（压缩字节数不能反映频闪帧的解码算力开销，
        // 1MB约200帧≈40s解码，按字节门控仍会在EOF积压上百秒；按GOP门控把每worker在途
        // 工作量限制为 当前GOP+1个完整GOP）。finish后放开，保证收尾能读尽剩余媒体。
        // 注意：口径必须是"当前输入缓冲内未处理的完整GOP数"（缓冲里能扫到几个gopEnd），
        // 不能减去累计已处理数——已处理的gopEnd已随take()离开缓冲，相减会让计数恒为负、
        // 闸门永不闭合。
        $queuedGops = 0;
        // 启动即报告空闲，主进程只向READY worker投递完整GOP。
        $upOutput .= HlsPipelineProtocol::frame(HlsPipelineProtocol::READY, 0, ['worker' => true]);
        $triggerFinish = static function () use (&$finishing): void {
            $finishing = true;
        };
        try {
            while (true) {
                $read = [$downstream];
                // finish 只表示主进程不再追加媒体；仍需继续读取上游socket，
                // 否则最后一个媒体帧可能只收到半帧，input 永远不会变为空，END也无法发送。
                // 输入高水位取 INPUT_HIGH_WATERMARK（1MB）+ 未处理GOP数门控：
                // 频闪帧压缩后很小但解码昂贵，GOP门控把在途算力限制在当前GOP+1个完整GOP；
                // 停读让反压沿 socket→主进程→拉流信用窗传播，避免EOF时内部积压上百秒。
                // finish后必须继续读尽剩余媒体，门控仅在未收尾时生效。
                if (!$ended && !$finishing
                    && strlen($input) < HlsPipelineProtocol::INPUT_HIGH_WATERMARK
                    && $queuedGops < HlsPipelineProtocol::INPUT_MAX_QUEUED_GOPS) $read[] = $upstream;
                if ($ctrlServer !== null) $read[] = $ctrlServer;
                if ($ctrlConn !== null) $read[] = $ctrlConn;
                $write = $output === '' ? [] : [$downstream];
                if ($upOutput !== '') $write[] = $upstream;
                $except = null;
                @stream_select($read, $write, $except, 0, 1);
                if ($ctrlServer !== null && in_array($ctrlServer, $read, true)) {
                    $conn = @stream_socket_accept($ctrlServer, 0);
                    if ($conn !== false) {
                        stream_set_blocking($conn, false);
                        $ctrlConn = $conn;
                        fclose($ctrlServer);
                        $ctrlServer = null;
                    }
                }
                if ($ctrlConn !== null && in_array($ctrlConn, $read, true)) {
                    $chunk = @fread($ctrlConn, 65536);
                    if ($chunk === false || ($chunk === '' && feof($ctrlConn))) {
                        $ctrlConn = null;
                    } else {
                        $ctrlInput .= $chunk;
                        foreach (HlsPipelineProtocol::take($ctrlInput, PHP_INT_MAX) as $ctrlEvent) {
                            if ($ctrlEvent['type'] === HlsPipelineProtocol::CONTROL && ($ctrlEvent['metadata']['cmd'] ?? '') === 'finish') {
                                $triggerFinish();
                            }
                        }
                    }
                }
                if (in_array($upstream, $read, true)) {
                    while (true) {
                        $chunk = @fread($upstream, 65536);
                        if ($chunk === false || ($chunk === '' && feof($upstream))) throw new RuntimeException('主进程媒体连接意外关闭');
                        if ($chunk === '') break;
                        $input .= $chunk;
                        if (strlen($input) > HlsPipelineProtocol::MAX_BUFFER_LENGTH) throw new RuntimeException('解码进程输入缓冲超限');
                        if (strlen($chunk) < 65536) break;
                    }
                }
                // 兼容兜底：finish 若从媒体通道到达（无控制连接的旧调用方），长度前缀快扫定位，
                // 不解析/不搬运媒体负载；同一次遍历顺带统计缓冲内未处理的 gopEnd 数量
                if (!$finishing) {
                    $off = 0;
                    $scanTotal = strlen($input);
                    $foundAt = -1;
                    $bufferedGopEnds = 0;
                    while ($off + 4 <= $scanTotal) {
                        $frameLen = (int)unpack('N', substr($input, $off, 4))[1];
                        if ($frameLen < 9 || $frameLen > HlsPipelineProtocol::MAX_FRAME_LENGTH) break;
                        if ($off + 4 + $frameLen > $scanTotal) break;
                        if (ord($input[$off + 4]) === HlsPipelineProtocol::CONTROL) {
                            $metaLen = (int)unpack('N', substr($input, $off + 9, 4))[1];
                            if ($metaLen <= $frameLen - 9) {
                                $meta = json_decode(substr($input, $off + 13, $metaLen), true);
                                if (is_array($meta) && ($meta['cmd'] ?? '') === 'finish') {
                                    $foundAt = $off;
                                    break;
                                }
                                if (is_array($meta) && ($meta['cmd'] ?? '') === 'gopEnd') $bufferedGopEnds++;
                            }
                        }
                        $off += 4 + $frameLen;
                    }
                    // 缓冲中闭合GOP数即"排队待解码"的完整GOP（gopEnd随take()处理后即离开缓冲）
                    $queuedGops = $bufferedGopEnds;
                    if ($foundAt >= 0) $triggerFinish();
                }
                // 单次 select 唤醒（Windows 下粒度约 10~15ms）最多处理 2 个事件即返回 select 循环：
                // 防止 GOP 边界多帧背靠背同步阻塞（IDR encodeSliceBody 或 P 帧 collect 均可能阻塞 300ms-2s），
                // 期间 upstream/downstream I/O 完全冻结 → 反压级联导致 tags/s 归零。
                // 下游 output 积压到高水位时停止，让反压继续向下游传播，避免长文件下缓冲超限。
                // finish 仅停止接收新数据；已进入 input 的媒体必须全部解码并转发。
                $eventBudget = 2;
                while ($eventBudget-- > 0 && strlen($output) < HlsPipelineProtocol::HIGH_WATERMARK) {
                    $events = HlsPipelineProtocol::take($input, 1);
                    if ($events === []) break;
                    $event = $events[0];
                    if ($event['type'] === HlsPipelineProtocol::CONTROL) {
                        $cmd = $event['metadata']['cmd'] ?? '';
                        if ($cmd === 'config') $this->parseConfiguration(substr($event['payload'], 5));
                        elseif ($cmd === 'gopEnd') {
                            // 先冲刷在途帧与解码器重排队列（B 帧尾帧）再重置：
                            // 保证 GOP 最后一帧正常输出且不跨 GOP 残留流水线状态
                            $gop = (int)($event['metadata']['gop'] ?? -1);
                            $output .= $this->flushGopTail((int)($event['metadata']['tailSeq'] ?? 0), (int)($event['metadata']['tailMax'] ?? 0));
                            $this->resetGopState();
                            $upOutput .= HlsPipelineProtocol::frame(HlsPipelineProtocol::PROGRESS, 0, ['gop' => $gop]);
                            $upOutput .= HlsPipelineProtocol::frame(HlsPipelineProtocol::READY, 0, ['gop' => $gop]);
                        }
                    } elseif ($event['type'] === HlsPipelineProtocol::END) {
                        // 末帧在途：冲刷在途帧与解码器重排队列后再转发 END，
                        // 输出进程必然先收齐全部媒体再看到 END
                        $output .= $this->flushGopTail((int)($event['metadata']['tailSeq'] ?? 0), (int)($event['metadata']['tailMax'] ?? 0));
                        $output .= HlsPipelineProtocol::frame(HlsPipelineProtocol::END, $event['sequence']);
                        $ended = true;
                    } else {
                        $output .= $this->orderFrame($this->transform($event));
                    }
                    if (strlen($output) > HlsPipelineProtocol::MAX_BUFFER_LENGTH) throw new RuntimeException('解码进程下游缓冲超限');
                }
                if ($finishing && !$endSent && $input === '') {
                    $output .= $this->flushEncodedFrame();
                    $output .= HlsPipelineProtocol::frame(HlsPipelineProtocol::END, 0);
                    $endSent = true;
                }
                if (in_array($downstream, $write, true)) {
                    while ($output !== '') {
                        $written = @fwrite($downstream, substr($output, 0, 262144));
                        if ($written === false || ($written === 0 && feof($downstream))) throw new RuntimeException('编码进程媒体连接意外关闭');
                        if ($written === 0) break;
                        $output = substr($output, $written);
                        if ($written < 262144) break;
                    }
                }
                if (in_array($upstream, $write, true) && $upOutput !== '') {
                    while ($upOutput !== '') {
                        $written = @fwrite($upstream, substr($upOutput, 0, 262144));
                        if ($written === false || ($written === 0 && feof($upstream))) break;
                        if ($written === 0) break;
                        $upOutput = substr($upOutput, $written);
                        if ($written < 262144) break;
                    }
                }
                if (in_array($downstream, $read, true)) {
                    $chunk = @fread($downstream, 65536);
                    if ($chunk === false || ($chunk === '' && feof($downstream))) throw new RuntimeException('编码进程响应连接意外关闭');
                    $downstreamInput .= $chunk;
                }
                foreach (HlsPipelineProtocol::take($downstreamInput, 4) as $response) {
                    if ($response['type'] === HlsPipelineProtocol::ERROR) throw new RuntimeException($response['metadata']['message'] ?? '编码进程失败');
                    if ($response['type'] === HlsPipelineProtocol::FINISHED) {
                        $this->writeAll($upstream, HlsPipelineProtocol::frame(HlsPipelineProtocol::FINISHED, $response['sequence']));
                        return;
                    }
                }
            }
        } catch (Throwable $e) {
            if (is_resource($upstream)) {
                try { $this->writeAll($upstream, HlsPipelineProtocol::frame(HlsPipelineProtocol::ERROR, 0, ['message' => $e->getMessage()])); } catch (Throwable) {}
            }
            throw $e;
        } finally {
            if (is_resource($upstream)) @fclose($upstream);
            if (is_resource($downstream)) @fclose($downstream);
            if (is_resource($ctrlConn)) @fclose($ctrlConn);
            if (is_resource($ctrlServer)) @fclose($ctrlServer);
        }
    }

    private function transform(array $event): string
    {
        if ($event['type'] !== HlsPipelineProtocol::EVENT) throw new RuntimeException('解码进程收到未知事件');
        $meta = $event['metadata'];
        if (($meta['tagType'] ?? 0) !== 9) return HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $event['sequence'], $meta, $event['payload']);
        $body = $event['payload'];
        if (strlen($body) < 5 || (ord($body[0]) & 0x0f) !== 7) return HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $event['sequence'], $meta, $body);
        $packetType = ord($body[1]);
        if ($packetType === 0) {
            $this->parseConfiguration(substr($body, 5));
            $meta['sourceWidth'] = $this->width;
            $meta['sourceHeight'] = $this->height;
            return HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $event['sequence'], $meta, $body);
        }
        if ($packetType !== 1 || $this->width === 0) return HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $event['sequence'], $meta, $body);
        $nals = $this->extractNals(substr($body, 5));
        if ($this->sps !== '') array_unshift($nals, ['type' => 7, 'data' => $this->sps]);
        if ($this->pps !== '') array_unshift($nals, ['type' => 8, 'data' => $this->pps]);
        $dropFrame = !empty($meta['drop']);
        if (isset($meta['sourceFps'])) $this->sourceFps = (float)$meta['sourceFps'];
        // 抽帧帧仍需解码以维持P链，但不需要生成YUV输出，也不需要执行去块滤波。
        // IDR在解码器内部仍会强制保留去块，保证关键帧参考链正确。
        $frame = $this->decoder->decode($nals, false, !$dropFrame, $dropFrame);
        // 抽帧丢弃：解码已完成（维持 GOP 内后续帧的参考链），不缩放/不附 YUV，
        // meta.drop 原样透传，输出端直接跳过编码
        if ($dropFrame) {
            return HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $event['sequence'], $meta, $body);
        }
        // 重排延迟：本帧已在解码器 reorder 队列中等待 POC 排序，内容随后续帧产出。
        // 不能透传未解码的原始 NAL（输出端拿到 High NAL 会产生马赛克），标记 drop 跳过输出。
        if (!$frame || empty($frame['data'])) {
            $meta['drop'] = true;
            return HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $event['sequence'], $meta, $body);
        }
        $meta['decoded'] = true;
        $meta['sourceWidth'] = $this->width;
        $meta['sourceHeight'] = $this->height;
        // 重排下 IDR 内容滞后一帧，tag 的 frameType 标记与实际编码内容错位，
        // 必须用解码帧的 isIdr 决定编码关键帧，否则切片 IDR 缺失导致首段无画面
        $isKeyFrame = !empty($frame['isIdr']);
        $this->lastVideoTs = (int)($meta['outTimestamp'] ?? $meta['timestamp'] ?? 0);
        // 单 profile：取第一个（去除多 profile 重编码）
        $profileName = array_key_first($this->profiles);
        $profile = $this->profiles[$profileName];
        $targetWidth = ($profile['width'] ?? 0) > 0 ? (int)$profile['width'] : $this->width;
        $targetHeight = ($profile['height'] ?? 0) > 0 ? (int)$profile['height'] : $this->height;
        $encoder = $this->encoders[$profileName];
        $yuv = ($targetWidth !== $this->width || $targetHeight !== $this->height)
            ? $this->scaler->scaleYUV420P($frame['data'], $this->width, $this->height, $targetWidth, $targetHeight) : $frame['data'];
        if (!empty($profile['watermark']) && !empty($profile['watermarkFile'])) {
            $yuv = $this->applyWatermark($yuv, $targetWidth, $targetHeight, $profile['watermarkFile']);
        }
        $encoder->setResolution($targetWidth, $targetHeight);
        $encoder->setBitrate((int)($profile['bitrate'] ?? 500000));
        $encoder->setFps((int)($profile['fps'] ?? 25));
        $encoder->setQp((int)($profile['qp'] ?? 26));
        // B 模式：显示序帧入 3 帧重排缓冲，凑齐三元组后按编码序落盘
        if ($this->bframes) return $this->feedBFrame($event, $yuv, $targetWidth, $targetHeight, $isKeyFrame);
        // P 模式：startFrame 派发运动估计，在途帧 finish 在 feedEncoder 内完成
        $encoder->startFrame($yuv, $isKeyFrame);
        return $this->feedEncoder($event, $meta, $body, $isKeyFrame, [$targetWidth, $targetHeight]);
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
     * 帧级双缓冲喂帧：调用前各 profile 均已 startFrame（当前帧运动估计在途）。
     * 首帧只登记不产出；后续帧 finishFrame() 取上一帧各 profile 的 NAL，
     * 返回上一帧协议帧（连同被延后的直通帧）；首帧返回空串。
     *
     * @param array<int|string,array{0:int,1:int}> $variantDims 各 profile 输出尺寸
     */
    private function feedEncoder(array $event, array $meta, string $body, bool $isKeyFrame, array $variantDims): string
    {
        if ($this->pendingFrame === null) {
            $this->pendingFrame = ['sequence' => $event['sequence'], 'meta' => $meta, 'body' => $body, 'isKey' => $isKeyFrame, 'dims' => $variantDims];
            return '';
        }
        $frame = $this->buildPendingFrame();
        $this->pendingFrame = ['sequence' => $event['sequence'], 'meta' => $meta, 'body' => $body, 'isKey' => $isKeyFrame, 'dims' => $variantDims];
        if ($this->deferredOutput !== '') { $frame .= $this->deferredOutput; $this->deferredOutput = ''; }
        return $frame;
    }

    /** 冲刷在途的最后一帧（GOP 边界或 END），并带出其后缓存的直通帧。 */
    private function flushEncodedFrame(): string
    {
        if ($this->pendingFrame === null) return '';
        $frame = $this->buildPendingFrame();
        $this->pendingFrame = null;
        if ($this->deferredOutput !== '') { $frame .= $this->deferredOutput; $this->deferredOutput = ''; }
        return $frame;
    }

    /**
     * GOP 尾部冲刷：排出解码器重排队列中滞留的尾帧，经缩放/编码后占用 dispatcher 预留的
     * 连续序号发出；不足 tailMax 部分以 drop 占位事件补齐，保全局序号连续。
     */
    private function flushGopTail(int $tailSeq, int $tailMax): string
    {
        if ($this->bframes) return $this->flushGopTailB($tailSeq, $tailMax);
        $out = '';
        $emitted = 0;
        if ($tailMax > 0) {
            $frames = $this->width > 0 ? $this->decoder->flushOutput() : [];
            $frameDurMs = $this->sourceFps > 0.0 ? 1000 / $this->sourceFps : 40;
            $profileName = array_key_first($this->profiles);
            $profile = $this->profiles[$profileName];
            $targetWidth = ($profile['width'] ?? 0) > 0 ? (int)$profile['width'] : $this->width;
            $targetHeight = ($profile['height'] ?? 0) > 0 ? (int)$profile['height'] : $this->height;
            foreach ($frames as $f) {
                if ($emitted >= $tailMax || empty($f['data'])) break;
                $yuv = ($targetWidth !== $this->width || $targetHeight !== $this->height)
                    ? $this->scaler->scaleYUV420P($f['data'], $this->width, $this->height, $targetWidth, $targetHeight) : $f['data'];
                if (!empty($profile['watermark']) && !empty($profile['watermarkFile'])) {
                    $yuv = $this->applyWatermark($yuv, $targetWidth, $targetHeight, $profile['watermarkFile']);
                }
                $this->encoder->setResolution($targetWidth, $targetHeight);
                $this->encoder->setBitrate((int)($profile['bitrate'] ?? 500000));
                $this->encoder->setFps((int)($profile['fps'] ?? 25));
                $this->encoder->setQp((int)($profile['qp'] ?? 26));
                $this->encoder->startFrame($yuv, !empty($f['isIdr']));
                if ($this->pendingFrame !== null) {
                    $out .= $this->buildPendingFrame();
                    $this->pendingFrame = null;
                }
                $ts = (int)round($this->lastVideoTs + ($emitted + 1) * $frameDurMs);
                $body = chr(((!empty($f['isIdr']) ? 1 : 2) << 4) | 7) . chr(1) . "\0\0\0";
                $this->pendingFrame = ['sequence' => $tailSeq + $emitted,
                    'meta' => ['tagType' => 9, 'timestamp' => $ts],
                    'body' => $body, 'isKey' => !empty($f['isIdr']), 'dims' => [$targetWidth, $targetHeight]];
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

    private function buildPendingFrame(): string
    {
        $p = $this->pendingFrame;
        $annexb = '';
        foreach ($this->encoder->finishFrame() as $nal) {
            // TS 容器要求真正的 AnnexB：每个 NAL 前加 00 00 00 01 start code
            $annexb .= "\x00\x00\x00\x01" . $nal;
        }
        [$w, $h] = $p['dims'];
        $meta = $p['meta'];
        $body = $p['body'];
        $profileName = array_key_first($this->profiles);
        // PurePhpHlsGenerator 的 pipelineEncodedPayload 是拆分后的纯 AnnexB（不含 4 字节前缀 + body），
        // 所以 offset 必须为 0
        $meta['encoded'] = true;
        $meta['keyFrame'] = $p['isKey'];
        $meta['encodedVariants'] = [$profileName => ['offset' => 0, 'length' => strlen($annexb), 'width' => $w, 'height' => $h]];
        $meta['timestamp'] = $meta['timestamp'] ?? 0;
        $meta['pts'] = (int)(($meta['outTimestamp'] ?? $meta['timestamp']) * 90);
        $meta['dts'] = (int)($meta['timestamp'] * 90);
        return HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $p['sequence'], $meta, pack('N', strlen($body)) . $body . $annexb);
    }

    /** 显示序帧入队（B 模式，单 profile 版）——$yuv 已缩放/水印完毕。 */
    private function feedBFrame(array $event, string $yuv, int $outW, int $outH, bool $isKeyFrame): string
    {
        $this->bQueue[] = [
            'yuv' => $yuv,
            'isKey' => $isKeyFrame,
            'idx' => $this->bDisplayCounter++,
            'seq' => $event['sequence'],
            'outW' => $outW,
            'outH' => $outH,
        ];
        $tail = 0;
        return $this->drainBQueue(false, 0, $tail);
    }

    /**
     * 显示序缓冲 → 编码序落盘（单 profile 版，DTS 走编码序均匀网格）。
     * IBBP 编码序：锚点(P/IDR) → B1 → B2；DTS 严格按此序列递增。
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

            // 2) 前 3 帧内出现 IDR：先把 IDR 之前的残留帧按 P 编码，下轮再处理 IDR
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
                // 槽位按升序重排后配给编码序：锚点取最小、其次 B1、B2
                $slots = [$b1['seq'], $b2['seq'], $anchor['seq']];
                sort($slots);
                // L0 快照 + 锚点 POC 设置
                $this->encoder->snapshotBRefL0();
                $this->encoder->poc = 2 * ($anchor['idx'] - $this->bPocBase);
                $anchorNals = $this->encoder->encodeFrame($anchor['yuv'], false);
                $out .= $this->wrapEncodedBFrame($anchorNals, $slots[0], false, $anchor);
                $b1Nals = $this->encoder->encodeBFrame($b1['yuv'], 2 * ($b1['idx'] - $this->bPocBase));
                $out .= $this->wrapEncodedBFrame($b1Nals, $slots[1], false, $b1);
                $b2Nals = $this->encoder->encodeBFrame($b2['yuv'], 2 * ($b2['idx'] - $this->bPocBase));
                $out .= $this->wrapEncodedBFrame($b2Nals, $slots[2], false, $b2);
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

    /** 单 profile 锚点 P 编码 */
    private function emitBAnchorP(array $e, int $tailSeq, int &$tailUsed): string
    {
        $this->encoder->snapshotBRefL0();
        $this->encoder->poc = 2 * ($e['idx'] - $this->bPocBase);
        $nals = $this->encoder->encodeFrame($e['yuv'], false);
        $out = $this->emitEncodedB($nals, $e, false, $tailSeq, $tailUsed);
        $this->bLastAnchorIdx = $e['idx'];
        return $out;
    }

    /** 单帧（锚点 IDR / 尾部 P）编码落盘 */
    private function emitEncodedB(array $nals, array $e, bool $isKey, int $tailSeq, int &$tailUsed): string
    {
        $seq = $e['seq'] ?? ($tailSeq + $tailUsed++);
        return $this->wrapEncodedBFrame($nals, $seq, $isKey, $e);
    }

    /** B 模式帧时长（毫秒）——按抽帧后实际编码帧率（target 与 source 取小），与 B 队列帧间隔一致 */
    private function bFrameDurMs(): float
    {
        $profileName = array_key_first($this->profiles);
        $targetFps = (float)($this->profiles[$profileName]['fps'] ?? 0);
        if ($targetFps > 0 && $this->sourceFps > 0 && $targetFps < $this->sourceFps - 0.01) {
            return 1000.0 / $targetFps;
        }
        if ($this->sourceFps > 0.0) return 1000.0 / $this->sourceFps;
        return 40.0;
    }

    /**
     * B 帧封装（单 profile）——DTS 按编码序均匀网格，CTS = 显示序 − 编码序 之差。
     * DTS 不走源流 timestamp（编码序输出时源流 timestamp 非单调），避免 TS discontinuity。
     * PTS = DTS + CTS。
     */
    private function wrapEncodedBFrame(array $nals, int $seq, bool $isKey, array $e): string
    {
        // 先算编码序 DTS（推进 counter 前），再算 CTS，最后推进 counter
        $frameDurMs = $this->bFrameDurMs();
        $dtsMs = (int)round($this->bEncodeCounter * $frameDurMs);
        // CTS 用 90kHz tick 表达：帧时长×90 对常见帧率（15/25/30fps）均为整数，
        // 避免毫秒取整（66.67ms→67ms）给 PTS 网格引入 ±30 tick 的系统性偏差
        $ctsTicks = (int)round(($e['idx'] - $this->bEncodeCounter) * $frameDurMs * 90);
        $this->bEncodeCounter++;

        $annexb = '';
        foreach ($nals as $nal) $annexb .= "\x00\x00\x00\x01" . $nal;
        // TS 封装需要最小 AVCC NALU 包头（frameType/codecId/packetType）
        $body = chr((($isKey ? 1 : 2) << 4) | 7) . chr(1) . "\0\0\0";
        // PurePhpHlsGenerator 的 pipelineEncodedPayload 是拆分后的纯 AnnexB，offset 必须为 0
        $profileName = array_key_first($this->profiles);
        $meta = [
            'tagType' => 9,
            'encoded' => true,
            'keyFrame' => $isKey,
            'ctsTicks' => $ctsTicks,
            'dtsMs' => $dtsMs,
            'frameDurMs' => $frameDurMs,
            'timestamp' => $dtsMs,
            'encodedVariant' => ['offset' => 0, 'length' => strlen($annexb),
                'width' => (int)($e['outW'] ?? 0), 'height' => (int)($e['outH'] ?? 0)],
            'encodedVariants' => [$profileName => ['offset' => 0, 'length' => strlen($annexb),
                'width' => (int)($e['outW'] ?? 0), 'height' => (int)($e['outH'] ?? 0)]],
            'pts' => (int)($dtsMs * 90) + $ctsTicks,
            'dts' => (int)($dtsMs * 90),
        ];
        return HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $seq, $meta, pack('N', strlen($body)) . $body . $annexb);
    }

    /**
     * B 模式 GOP 尾部冲刷：把解码器重排队列滞留的显示序尾帧补入 B 缓冲，
     * 再按显示序以 P 帧补齐残留帧，最后不足 tailMax 部分以 drop 占位补齐。
     */
    private function flushGopTailB(int $tailSeq, int $tailMax): string
    {
        $tailUsed = 0;
        if ($tailMax > 0 && $this->width > 0) {
            $profileName = array_key_first($this->profiles);
            $profile = $this->profiles[$profileName];
            $targetWidth = ($profile['width'] ?? 0) > 0 ? (int)$profile['width'] : $this->width;
            $targetHeight = ($profile['height'] ?? 0) > 0 ? (int)$profile['height'] : $this->height;
            $encoder = $this->encoders[$profileName];
            $encoder->setResolution($targetWidth, $targetHeight);
            $encoder->setBitrate((int)($profile['bitrate'] ?? 500000));
            $encoder->setFps((int)($profile['fps'] ?? 25));
            $encoder->setQp((int)($profile['qp'] ?? 26));
            foreach ($this->decoder->flushOutput() as $f) {
                if (empty($f['data'])) continue;
                $yuv = ($targetWidth !== $this->width || $targetHeight !== $this->height)
                    ? $this->scaler->scaleYUV420P($f['data'], $this->width, $this->height, $targetWidth, $targetHeight) : $f['data'];
                if (!empty($profile['watermark']) && !empty($profile['watermarkFile'])) {
                    $yuv = $this->applyWatermark($yuv, $targetWidth, $targetHeight, $profile['watermarkFile']);
                }
                $this->bQueue[] = ['yuv' => $yuv, 'isKey' => !empty($f['isIdr']),
                    'idx' => $this->bDisplayCounter++, 'seq' => null,
                    'outW' => $targetWidth, 'outH' => $targetHeight];
            }
        }
        $out = $this->drainBQueue(true, $tailSeq, $tailUsed);
        while ($tailUsed < $tailMax) {
            $out .= HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $tailSeq + $tailUsed, ['tagType' => 9, 'drop' => true], '');
            $tailUsed++;
        }
        return $out;
    }

    private function resetGopState(): void
    {
        // 解码器内部仍包含较多SPS/PPS、参考帧和帧计数耦合状态，先保留原有安全做法：
        // GOP边界重建解码器；编码器则使用轻量重置并复用已连接的运动估计worker。
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
        foreach ($this->encoders as $encoder) $encoder->resetGopState();
        // 每个 GOP 边界主动收一次 cycle GC：把 GC 开销从随机热时刻挪到已知空闲点。
        // PHP 默认 GC root buffer = 10K roots，帧内宏块数组/变换结果堆到 ~60s 触发 full collection。
        // 12 个 decoder worker 同步启动时间戳接近，显式 GC 主动清空能打散下一次 full collection 的同步停顿。
        // 实测对比：显式 GC → 归零 10s、累计 8666 tags；无显式 GC → 归零 15s、累计 7051 tags
        gc_collect_cycles();
    }

    private function resetEncoders(): void
    {
        foreach ($this->encoders as $encoder) $encoder->resetGopState();
    }

    private function parseConfiguration(string $data): void
    {
        if (strlen($data) < 7) return;
        $offset = 5;
        $count = ord($data[$offset++]) & 0x1f;
        for ($i = 0; $i < $count; $i++) {
            $length = unpack('n', substr($data, $offset, 2))[1]; $offset += 2;
            $raw = substr($data, $offset, $length); $offset += $length;
            $this->sps = substr(NalUtil::removeEmulationPrevention($raw), 1);
            $this->decoder->decode([['type' => 7, 'data' => $this->sps]], true);
            $this->width = $this->decoder->getWidth(); $this->height = $this->decoder->getHeight();
        }
        if ($offset >= strlen($data)) return;
        $count = ord($data[$offset++]);
        for ($i = 0; $i < $count; $i++) {
            $length = unpack('n', substr($data, $offset, 2))[1]; $offset += 2;
            $raw = substr($data, $offset, $length); $offset += $length;
            $this->pps = substr(NalUtil::removeEmulationPrevention($raw), 1);
        }
    }

    private function extractNals(string $data): array
    {
        $result = []; $offset = 0; $total = strlen($data);
        while ($offset + 4 <= $total) {
            $length = unpack('N', substr($data, $offset, 4))[1]; $offset += 4;
            if ($offset + $length > $total) break;
            $clean = NalUtil::removeEmulationPrevention(substr($data, $offset, $length)); $offset += $length;
            $result[] = ['type' => ord($clean[0]) & 0x1f, 'data' => substr($clean, 1), 'raw' => $clean];
        }
        return $result;
    }

    private function applyWatermark(string $yuv, int $w, int $h, string $file): string
    {
        $data = file_get_contents($file);
        if ($data === false) throw new RuntimeException("无法读取水印文件: {$file}");
        $base = basename($file, '.yuv');
        if (!preg_match('/_(\d+)x(\d+)$/', $base, $m)) { $ww = 80; $wh = 16; } else { $ww = (int)$m[1]; $wh = (int)$m[2]; }
        if ($ww > $w || $wh > $h || strlen($data) < $ww * $wh * 3 / 2) return $yuv;
        $ySize = $w * $h; $uvSize = ($w >> 1) * ($h >> 1); $wySize = $ww * $wh; $wuvSize = $wySize >> 2;
        for ($row = 0; $row < $wh; $row++) for ($col = 0; $col < $ww; $col++) $yuv[$row * $w + $col] = $data[$row * $ww + $col];
        for ($row = 0; $row < ($wh >> 1); $row++) for ($col = 0; $col < ($ww >> 1); $col++) {
            $dst = $row * ($w >> 1) + $col; $src = $row * ($ww >> 1) + $col;
            $yuv[$ySize + $dst] = $data[$wySize + $src]; $yuv[$ySize + $uvSize + $dst] = $data[$wySize + $wuvSize + $src];
        }
        return $yuv;
    }

    private function connect(string $address)
    {
        $deadline = microtime(true) + 15;
        do { $socket = @stream_socket_client($address, $errno, $error, 0.2); if ($socket !== false) return $socket; usleep(1); } while (microtime(true) < $deadline);
        throw new RuntimeException("无法连接编码进程: {$error} ({$errno})");
    }

    private function writeAll($socket, string $data): void
    {
        stream_set_blocking($socket, true); $offset = 0;
        while ($offset < strlen($data)) { $n = fwrite($socket, substr($data, $offset)); if ($n === false || $n === 0) throw new RuntimeException('无法发送完成响应'); $offset += $n; }
    }
}
