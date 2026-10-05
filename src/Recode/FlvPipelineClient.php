<?php

namespace Xiaosongshu\Flv2mp4\Recode;

use Composer\Autoload\ClassLoader;
use Generator;
use ReflectionClass;
use RuntimeException;
use Throwable;

/**
 * @purpose flv重编码分布式架构-管道客户端（多解码worker按GOP并行）
 * @author yanglong
 */
final class FlvPipelineClient
{
    private array $processes = [];
    /** 每个解码worker待写入主进程缓冲区的软上限 */
    private const PER_WORKER_SOFT_LIMIT = 8388608;

    public function __construct(private array $config, private ?int $maxFrames)
    {
    }

    public function process(string $flvFile, string $outputFile): void
    {
        $sourceInfo = $this->scanSource($flvFile);
        $sourceFps = $sourceInfo['fps'];
        // 自适应并行：GOP 少时收缩解码 worker（多余的只会空转），把运动估计进程预算让给帧内条带并行
        [$workerCount, $motionPerWorker] = $this->planWorkers($sourceInfo['gopCount']);
        echo "并行规划: GOP={$sourceInfo['gopCount']}, 解码worker={$workerCount}, 每worker运动进程={$motionPerWorker}（运动进程共" . ($workerCount * $motionPerWorker) . "）\n";
        [, $outputPort] = $this->reserveAddress();
        $decoderAddresses = [];
        for ($i = 0; $i < $workerCount; $i++) $decoderAddresses[] = $this->reserveAddress();
        $autoload = $this->locateAutoload();
        $worker = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'flv-recode-worker.php';
        $config = $this->config;
        $config['source_fps'] = $sourceFps;
        // 下发给 worker 的是"每 worker 运动进程数"（自适应结果），不改变主配置中 motionWorkers 的原有语义
        $config['motionWorkers'] = $motionPerWorker;
        $encodedConfig = base64_encode(json_encode($config, JSON_THROW_ON_ERROR));
        $sockets = [];
        try {
            $this->startWorker([$worker, '--mode', 'output', '--autoload', $autoload, '--port', (string)$outputPort, '--workers', (string)$workerCount, '--config', $encodedConfig, '--output', $outputFile]);
            foreach ($decoderAddresses as [$decoderAddress, $decoderPort]) {
                $this->startWorker([$worker, '--mode', 'decoder', '--autoload', $autoload, '--port', (string)$decoderPort, '--output-port', (string)$outputPort, '--config', $encodedConfig]);
            }
            foreach ($decoderAddresses as [$decoderAddress]) {
                $socket = $this->connect($decoderAddress);
                stream_set_blocking($socket, false);
                $sockets[] = $socket;
            }

            $targetFps = (int)($config['fps'] ?? 0);
            $dropFrames = $targetFps > 0 && $sourceFps !== null && $targetFps < $sourceFps - 0.01;

            $sequence = 0;
            $gopSeq = 0;
            $currentWorker = 0;
            $configured = false;
            $baseTimestamp = -1;
            $selected = 0;
            $frameCount = 0;
            $videoCount = 0;
            $outbound = array_fill(0, $workerCount, '');
            $inbound = array_fill(0, $workerCount, '');
            $alive = array_fill(0, $workerCount, true);
            // fastMotion 快速路径：READY 门控 + hold 缓冲 + EMA 加权派发（与直播主流水线一致）
            $fast = !empty($config['fastMotion']);
            $hold = '';
            $ready = array_fill(0, $workerCount, false);
            $load = array_fill(0, $workerCount, 0);
            $ema = array_fill(0, $workerCount, 0.0);
            /** @var array<int,int> GOP序号 => worker（-1=等待空闲worker） */
            $gopWorkerMap = [];
            /** @var array<int,int> GOP序号 => 加权负载 */
            $gopWeightMap = [];
            /** @var array<int,int> GOP序号 => 派发时刻 hrtime */
            $gopDispatchAt = [];
            $lastGopClosed = false;
            $tags = $this->readFlvTags($flvFile);
            $exhausted = false;
            $stopReading = false;
            $endEnqueued = false;
            $finishedCount = 0;

            while (true) {
                if (!$stopReading) {
                    // 快速路径下 hold 非空（新GOP暂无空闲worker）时必须停止取 tag，
                    // 否则同一批后续 IDR 会让未派发 GOP 的边界标记写错通道
                    while (!$exhausted && $hold === '' && $this->bufferedBytes($outbound) < $workerCount * self::PER_WORKER_SOFT_LIMIT) {
                        if (!$tags->valid()) { $exhausted = true; break; }
                        $tag = $tags->current(); $tags->next();
                        $frameCount++;
                        if ($tag['tagType'] === 8) {
                            $audioFrame = HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $sequence++, [
                                'tagType' => $tag['tagType'], 'timestamp' => $tag['timestamp'], 'sourceFps' => $sourceFps,
                            ], $tag['body']);
                            // 音频随当前GOP worker；快速路径下当前GOP等待派发时进 hold 保序
                            if ($fast && $currentWorker < 0) $hold .= $audioFrame;
                            else $outbound[$currentWorker] .= $audioFrame;
                        } elseif ($tag['tagType'] === 9) {
                            $videoCount++;
                            if ($fast) {
                                $this->dispatchVideoTagFast($tag, $sequence, $gopSeq, $currentWorker, $configured, $baseTimestamp, $selected, $targetFps, $dropFrames, $sourceFps, $workerCount, $ready, $load, $ema, $gopWorkerMap, $gopWeightMap, $gopDispatchAt, $hold, $outbound);
                            } else {
                                $this->dispatchVideoTag($tag, $sequence, $workerCount, $gopSeq, $currentWorker, $configured, $baseTimestamp, $selected, $targetFps, $dropFrames, $sourceFps, $outbound);
                            }
                            if ($this->maxFrames !== null && $videoCount >= $this->maxFrames) { echo "Reached max frames limit ({$this->maxFrames}), stopping...\n"; $stopReading = true; break; }
                        }
                        if ($frameCount % 50 === 0) echo "Processed {$frameCount} frames ({$videoCount} video)\n";
                    }
                    if ($fast) {
                        // 快速路径收尾：hold 先排空，再为最后一个GOP补边界，最后广播 END。
                        // gopEnd/END 顺序追加在各 worker 媒体末尾，worker 必然先收完媒体再收尾。
                        if (($exhausted || $stopReading) && $hold === '' && !$lastGopClosed) {
                            if ($gopSeq > 0 && isset($gopWorkerMap[$gopSeq - 1])) {
                                $lastGop = $gopSeq - 1;
                                $outbound[$gopWorkerMap[$lastGop]] .= HlsPipelineProtocol::frame(HlsPipelineProtocol::CONTROL, 0, ['cmd' => 'gopEnd', 'gop' => $lastGop]);
                            }
                            $lastGopClosed = true;
                        }
                        if (($exhausted || $stopReading) && $lastGopClosed && !$endEnqueued) {
                            for ($i = 0; $i < $workerCount; $i++) {
                                $outbound[$i] .= HlsPipelineProtocol::frame(HlsPipelineProtocol::END, $sequence++);
                            }
                            $endEnqueued = true;
                        }
                    } elseif (($exhausted || $stopReading) && !$endEnqueued) {
                        // END 必须广播给每个解码 worker：各 worker 排空自己通道内的媒体后各自转发 END，
                        // 输出 worker 需收齐 workerCount 个 END 才收尾并回 FINISHED（与 HLS 流水线一致）。
                        // END 追加在各 worker 媒体末尾，天然保证"先收完媒体再收尾"的顺序。
                        for ($i = 0; $i < $workerCount; $i++) {
                            $outbound[$i] .= HlsPipelineProtocol::frame(HlsPipelineProtocol::END, $sequence++);
                        }
                        $endEnqueued = true;
                    }
                }

                $read = [];
                foreach ($alive as $id => $isAlive) if ($isAlive) $read[] = $sockets[$id];
                $write = [];
                foreach ($outbound as $id => $buffer) if ($buffer !== '' && $alive[$id]) $write[] = $sockets[$id];
                if ($read === [] && $write === []) {
                    if ($finishedCount >= $workerCount) break;
                    throw new RuntimeException('解码进程媒体连接意外关闭');
                }
                $except = null;
                if (@stream_select($read, $write, $except, 1) === false) {
                    if ($finishedCount >= $workerCount) break;
                    continue;
                }
                foreach ($write as $socket) {
                    $id = (int)array_search($socket, $sockets, true);
                    $n = @fwrite($socket, substr($outbound[$id], 0, 65536));
                    if ($n === false || ($n === 0 && feof($socket))) {
                        if (!$endEnqueued) throw new RuntimeException('解码进程媒体连接意外关闭');
                        $alive[$id] = false;
                    }
                    if ($n > 0) $outbound[$id] = substr($outbound[$id], $n);
                }
                foreach ($read as $socket) {
                    $id = (int)array_search($socket, $sockets, true);
                    $chunk = @fread($socket, 65536);
                    if ($chunk === false || ($chunk === '' && feof($socket))) {
                        // END 发送前关闭一定是 worker 崩溃；END 后关闭可能是转发 FINISHED 后正常退出
                        if (!$endEnqueued) throw new RuntimeException('解码进程媒体连接意外关闭');
                        $alive[$id] = false;
                    } elseif ($chunk !== '') $inbound[$id] .= $chunk;
                }
                foreach ($inbound as $id => $buffer) {
                    foreach (HlsPipelineProtocol::take($inbound[$id], PHP_INT_MAX) as $event) {
                        if ($event['type'] === HlsPipelineProtocol::ERROR) throw new RuntimeException($event['metadata']['message'] ?? '流水线失败');
                        if ($event['type'] === HlsPipelineProtocol::FINISHED) { $finishedCount++; continue; }
                        if ($fast && $event['type'] === HlsPipelineProtocol::READY) {
                            $g = $event['metadata']['gop'] ?? null;
                            if ($g === null) {
                                $ready[$id] = true; // worker 启动空闲回报
                            } elseif (isset($gopWorkerMap[$g])) {
                                $w = $gopWorkerMap[$g];
                                $ready[$w] = true;
                                if (isset($gopWeightMap[$g])) $load[$w] -= $gopWeightMap[$g];
                                $gw = $gopWeightMap[$g] ?? 0;
                                if ($gw > 0 && isset($gopDispatchAt[$g])) {
                                    $perWeight = (hrtime(true) - $gopDispatchAt[$g]) / 1e6 / $gw;
                                    $ema[$w] = $ema[$w] <= 0.0 ? $perWeight : $ema[$w] * 0.5 + $perWeight * 0.5;
                                }
                                unset($gopWorkerMap[$g], $gopWeightMap[$g], $gopDispatchAt[$g]);
                            }
                            $this->dispatchFastHold($gopSeq, $currentWorker, $ready, $load, $ema, $gopWorkerMap, $gopWeightMap, $gopDispatchAt, $hold, $outbound);
                        }
                        // PROGRESS 在快速路径仅作进度信号，记账以随后的 READY 为准
                    }
                    if (strlen($inbound[$id]) > HlsPipelineProtocol::MAX_BUFFER_LENGTH) throw new RuntimeException('主进程响应缓冲超限');
                }
                if ($finishedCount >= $workerCount) break;
                if ($endEnqueued && !in_array(true, $alive, true)) throw new RuntimeException('解码进程未返回 FINISHED');
            }
            foreach ($sockets as $socket) @fclose($socket);
            $this->waitWorkers();
            echo "Done! Processed {$frameCount} frames ({$videoCount} video)\nOutput: {$outputFile}\n";
        } catch (Throwable $e) {
            foreach ($sockets as $socket) if (is_resource($socket)) @fclose($socket);
            $this->terminateWorkers();
            if (is_file($outputFile . '.part')) @unlink($outputFile . '.part');
            throw $e;
        }
    }

    /**
     * 按 GOP 数量自适应规划进程布局，返回 [解码worker数, 每worker运动估计进程数]
     *
     * fastMotion 快速路径（与直播流水线同款布局，1080p 源实测较旧 4×2 布局提速约 1/3）：
     *   每 GOP worker 仅 1 个运动进程（fastMotion 已缩小搜索，第 2 个运动进程只增争用），
     *   解码 worker 扩张到 decode_workers（按 GOP 数收敛，多余 worker 只会空转）。
     *
     * 旧路径（fastMotion 关闭）：每 worker 的运动进程 M=2 为甜点——
     * 帧内条带并行超过 2 路后，运动计算已不是瓶颈，多出的子进程只增加冷启动/调度开销
     * （90帧片段 2x2=10.4s vs 2x4=12.4s；387帧片段 4x2=22.2s vs 4x4=25.4s）。
     * 因此运动预算 motion_budget 全部用于扩张解码 worker（GOP 并行）：
     *   M = min(2, 配置motionWorkers)
     *   D = min(decode_workers上限, GOP数, floor(budget / M))
     * 例（budget=8）：GOP=1 → 1×2；GOP=2 → 2×2；GOP≥4 → 4×2。
     * 无法统计 GOP（gopCount<=0）时退回配置原值。
     *
     * @return array{0:int,1:int}
     */
    private function planWorkers(int $gopCount): array
    {
        $decodeMax = max(1, min(8, (int)($this->config['decode_workers'] ?? 4)));
        if (!empty($this->config['fastMotion'])) {
            return [$gopCount > 0 ? min($decodeMax, $gopCount) : $decodeMax, 1];
        }
        $configuredMotion = max(1, (int)($this->config['motionWorkers'] ?? 2));
        if ($gopCount <= 0) {
            return [$decodeMax, $configuredMotion];
        }
        $budget = max(1, (int)($this->config['motion_budget'] ?? 8));
        $m = min(2, $configuredMotion);
        $d = min($decodeMax, $gopCount, max(1, intdiv($budget, $m)));
        return [$d, $m];
    }

    private function dispatchVideoTag(
        array $tag,
        int &$sequence,
        int $workerCount,
        int &$gopSeq,
        int &$currentWorker,
        bool &$configured,
        int &$baseTimestamp,
        int &$selected,
        int $targetFps,
        bool $dropFrames,
        ?float $sourceFps,
        array &$outbound
    ): void {
        $body = $tag['body'];
        $packetType = strlen($body) >= 2 ? ord($body[1]) : -1;
        if ($packetType === 0) {
            // AVCC 序列头：worker 0 负责透传给输出进程，全体 worker 各自解析 SPS/PPS
            $outbound[0] .= HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $sequence++, [
                'tagType' => 9, 'timestamp' => $tag['timestamp'], 'sourceFps' => $sourceFps,
            ], $body);
            $control = HlsPipelineProtocol::frame(HlsPipelineProtocol::CONTROL, 0, ['cmd' => 'config'], $body);
            for ($i = 0; $i < $workerCount; $i++) $outbound[$i] .= $control;
            $configured = true;
            return;
        }

        $isKey = (ord($body[0]) >> 4) === 1 && $this->containsIdrNal($body);
        if ($isKey) {
            // 每个IDR开启一个独立GOP，轮询分配给空闲解码worker
            if ($gopSeq > 0) {
                // 上一GOP所有帧之后插入边界标记：worker 处理到此处即代表该 GOP 已完成，
                // 重置解码参考链与编码器 GOP 状态（保留已预热的运动估计子进程）
                $prevWorker = ($gopSeq - 1) % $workerCount;
                $outbound[$prevWorker] .= HlsPipelineProtocol::frame(HlsPipelineProtocol::CONTROL, 0, ['cmd' => 'gopEnd', 'gop' => $gopSeq - 1]);
            }
            $currentWorker = $gopSeq % $workerCount;
            $gopSeq++;
        }

        $drop = false;
        $timestamp = (int)$tag['timestamp'];
        if ($configured) {
            if ($baseTimestamp < 0) {
                if (!$isKey) $drop = true;
                else $baseTimestamp = $timestamp;
            }
            // 关键帧永不参与抽帧丢弃：GOP worker 在 gopEnd 已重置编码器参考与 frameNum，
            // 若边界 IDR 被丢，该 GOP 首帧会以非 IDR I-slice 输出，破坏 frame_num/参考标记语义
            if (!$drop && !$isKey && $dropFrames && $selected > 0 && ($timestamp - $baseTimestamp) * $targetFps < $selected * 1000) {
                $drop = true;
            }
            if (!$drop) $selected++;
        }

        $meta = ['tagType' => 9, 'timestamp' => $timestamp, 'sourceFps' => $sourceFps];
        if ($drop) $meta['drop'] = true;
        $outbound[$currentWorker] .= HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $sequence++, $meta, $body);
    }

    /**
     * 快速路径视频派发（fastMotion，与直播主流水线 plStartGop/plRoute 同构）：
     * IDR 到达时为上一GOP补 gopEnd，并把新GOP派给 READY worker 中 EMA×在途权重最小者；
     * 无空闲 worker 时 currentWorker=-1，本GOP全部帧（含其间音频）进 hold 保序暂存，
     * 待 READY 回报后由 dispatchFastHold() 整体放行。
     */
    private function dispatchVideoTagFast(
        array $tag,
        int &$sequence,
        int &$gopSeq,
        int &$currentWorker,
        bool &$configured,
        int &$baseTimestamp,
        int &$selected,
        int $targetFps,
        bool $dropFrames,
        ?float $sourceFps,
        int $workerCount,
        array &$ready,
        array &$load,
        array &$ema,
        array &$gopWorkerMap,
        array &$gopWeightMap,
        array &$gopDispatchAt,
        string &$hold,
        array &$outbound
    ): void {
        $body = $tag['body'];
        $packetType = strlen($body) >= 2 ? ord($body[1]) : -1;
        if ($packetType === 0) {
            // AVCC 序列头：worker 0 透传给输出进程，全体 worker 各自解析 SPS/PPS
            $outbound[0] .= HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $sequence++, [
                'tagType' => 9, 'timestamp' => $tag['timestamp'], 'sourceFps' => $sourceFps,
            ], $body);
            $control = HlsPipelineProtocol::frame(HlsPipelineProtocol::CONTROL, 0, ['cmd' => 'config'], $body);
            for ($i = 0; $i < $workerCount; $i++) $outbound[$i] .= $control;
            $configured = true;
            return;
        }

        $timestamp = (int)$tag['timestamp'];
        $isKey = (ord($body[0]) >> 4) === 1 && $this->containsIdrNal($body);
        if ($isKey) {
            if ($gopSeq > 0 && isset($gopWorkerMap[$gopSeq - 1])) {
                $prev = $gopSeq - 1;
                $outbound[$gopWorkerMap[$prev]] .= HlsPipelineProtocol::frame(HlsPipelineProtocol::CONTROL, 0, ['cmd' => 'gopEnd', 'gop' => $prev]);
            }
            $gop = $gopSeq;
            $worker = $gop === 0
                ? ($ready[0] ? 0 : -1)
                : $this->pickFastReadyWorker($ready, $load, $ema, $workerCount);
            $gopWorkerMap[$gop] = $worker;
            $gopWeightMap[$gop] = 0;
            $currentWorker = $worker;
            if ($worker >= 0) {
                $ready[$worker] = false;
                $gopDispatchAt[$gop] = hrtime(true);
            }
            $gopSeq++;
        }

        // 抽帧选帧（口径与旧路径 dispatchVideoTag 一致），但关键帧永不丢弃
        $drop = false;
        if ($configured) {
            if ($baseTimestamp < 0) {
                if (!$isKey) $drop = true;
                else $baseTimestamp = $timestamp;
            }
            if (!$drop && !$isKey && $dropFrames && $selected > 0 && ($timestamp - $baseTimestamp) * $targetFps < $selected * 1000) {
                $drop = true;
            }
            if (!$drop) $selected++;
        }

        $meta = ['tagType' => 9, 'timestamp' => $timestamp, 'sourceFps' => $sourceFps];
        if ($drop) $meta['drop'] = true;
        $frame = HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $sequence++, $meta, $body);
        // 首 IDR 之前的丢弃视频帧直通 worker0，不属于任何 GOP，不参与负载记账
        if ($gopSeq === 0) {
            $outbound[0] .= $frame;
            return;
        }
        $weight = $drop ? 1 : 2;
        if ($currentWorker < 0) {
            $hold .= $frame;
        } else {
            $outbound[$currentWorker] .= $frame;
            $load[$currentWorker] += $weight;
        }
        $gopWeightMap[$gopSeq - 1] = ($gopWeightMap[$gopSeq - 1] ?? 0) + $weight;
    }

    /** READY 后把 hold 中等待的GOP整体派给最优空闲 worker（首GOP固定 worker0）。 */
    private function dispatchFastHold(
        int &$gopSeq,
        int &$currentWorker,
        array &$ready,
        array &$load,
        array &$ema,
        array &$gopWorkerMap,
        array &$gopWeightMap,
        array &$gopDispatchAt,
        string &$hold,
        array &$outbound
    ): void {
        if ($hold === '') return;
        $gop = $gopSeq - 1;
        if ($gop < 0 || ($gopWorkerMap[$gop] ?? -1) !== -1) return;
        $worker = $gop === 0
            ? ($ready[0] ? 0 : -1)
            : $this->pickFastReadyWorker($ready, $load, $ema, count($outbound));
        if ($worker < 0) return;
        $gopWorkerMap[$gop] = $worker;
        $ready[$worker] = false;
        $currentWorker = $worker;
        $gopDispatchAt[$gop] = hrtime(true);
        $load[$worker] += $gopWeightMap[$gop] ?? 0;
        $outbound[$worker] .= $hold;
        $hold = '';
    }

    /** 选择 READY worker 中"在途权重×单位权重耗时EMA"最小者；无空闲返回 -1。 */
    private function pickFastReadyWorker(array $ready, array $load, array $ema, int $workerCount): int
    {
        $worker = -1;
        $best = null;
        for ($i = 0; $i < $workerCount; $i++) {
            if (empty($ready[$i])) continue;
            $e = $ema[$i] ?? 0.0;
            $score = $load[$i] * ($e > 0.0 ? $e : 1.0);
            if ($best === null || $score < $best) {
                $best = $score;
                $worker = $i;
            }
        }
        return $worker;
    }

    /**
     * 扫描AVCC视频包（跳过5字节FLV/AVC头），判断是否包含IDR NAL（type=5）。
     * x264常在IDR前带SEI/SPS/PPS，不能只看首个NAL。
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

    private function bufferedBytes(array $buffers): int
    {
        $total = 0;
        foreach ($buffers as $buffer) $total += strlen($buffer);
        return $total;
    }

    /**
     * 预扫描（稀疏）：仅按大块（256KB）顺序读一次文件并在缓冲内解析 tag header，
     * 不把 tag body 读入内存、不做 NAL 遍历——统计帧率只需 header 内的时间戳，
     * 统计关键帧只需 body 前 2 字节（FLV FrameType 高4位 + AVCPacketType）。
     * 关键帧按 FrameType=1 粗判（标准 AVC FLV 中与 IDR 一致），该值仅用于 worker 数上限。
     * @return array{fps: ?float, gopCount: int}
     */
    private function scanSource(string $file): array
    {
        $handle = @fopen($file, 'rb');
        if ($handle === false) throw new RuntimeException("无法打开 FLV 文件: {$file}");
        try {
            $header = fread($handle, 9);
            if ($header === false || strlen($header) < 9 || substr($header, 0, 3) !== 'FLV') {
                throw new RuntimeException('不是有效的 FLV 文件');
            }
            $dataOffset = unpack('N', substr($header, 5, 4))[1];
            if ($dataOffset < 9) throw new RuntimeException('FLV Header 长度无效');
            $skip = ($dataOffset - 9) + 4; // 扩展头 + PreviousTagSize0
            while ($skip > 0) {
                $chunk = fread($handle, $skip);
                if ($chunk === false || $chunk === '') throw new RuntimeException('FLV 文件数据不完整');
                $skip -= strlen($chunk);
            }

            $first = null; $last = null; $count = 0; $gopCount = 0;
            $buffer = '';
            $pos = 0;
            $eof = false;
            $chunkSize = 262144;
            while ($this->scanEnsure($handle, $buffer, $pos, $eof, 11, $chunkSize)) {
                $dataSize = (ord($buffer[$pos + 1]) << 16) | (ord($buffer[$pos + 2]) << 8) | ord($buffer[$pos + 3]);
                if ($dataSize > HlsPipelineProtocol::MAX_FRAME_LENGTH) break; // 残包/损坏文件，停止扫描
                $tagType = ord($buffer[$pos]);
                $timestamp = unpack('N', $buffer[$pos + 7] . substr($buffer, $pos + 4, 3))[1];

                // 视频包额外需要 body 前 2 字节（11B header 之后），其余 body 一律按长度跳过
                if ($tagType === 9 && $dataSize >= 2 && $this->scanEnsure($handle, $buffer, $pos, $eof, 13, $chunkSize)) {
                    $packetType = ord($buffer[$pos + 12]);
                    if ($packetType === 1) {
                        $first ??= $timestamp; $last = $timestamp; $count++;
                        if ((ord($buffer[$pos + 11]) >> 4) === 1) $gopCount++;
                    }
                }
                $pos += 11 + $dataSize + 4; // tag header + body + PreviousTagSize
            }
            $fps = $count >= 2 && $last > $first ? ($count - 1) * 1000 / ($last - $first) : null;
            return ['fps' => $fps, 'gopCount' => $gopCount];
        } finally {
            fclose($handle);
        }
    }

    /**
     * 保证 $buffer 从 $pos 起至少有 $need 个未消费字节；不足则丢弃已消费部分并补读一块。
     * 整个扫描只有这一处发生大块读取，系统调用次数为 O(文件大小/块大小)。
     */
    private function scanEnsure($handle, string &$buffer, int &$pos, bool &$eof, int $need, int $chunkSize): bool
    {
        while (!$eof && strlen($buffer) - $pos < $need) {
            if ($pos >= strlen($buffer)) {
                // 逻辑跳过点越过当前块尾（大 tag 跨块）：句柄必须 fseek 越过差额，
                // 否则下一块会从块尾续读、漏掉块尾到目标位置间的字节，造成后续 tag 全部错位
                $over = $pos - strlen($buffer);
                if ($over > 0 && fseek($handle, $over, SEEK_CUR) !== 0) { $eof = true; break; }
                $buffer = '';
                $pos = 0;
            } elseif ($pos > 0) {
                $buffer = substr($buffer, $pos);
                $pos = 0;
            }
            $chunk = fread($handle, $chunkSize);
            if ($chunk === false || $chunk === '') { $eof = true; break; }
            $buffer .= $chunk;
        }
        return strlen($buffer) - $pos >= $need;
    }

    private function readFlvTags(string $file): Generator
    {
        $handle = @fopen($file, 'rb');
        if ($handle === false) throw new RuntimeException("无法打开 FLV 文件: {$file}");
        try {
            $header = $this->readExact($handle, 9);
            if (substr($header, 0, 3) !== 'FLV') throw new RuntimeException('不是有效的 FLV 文件');
            $headerSize = unpack('N', substr($header, 5, 4))[1];
            if ($headerSize < 9) throw new RuntimeException('FLV Header 长度无效');
            if ($headerSize > 9) $this->readExact($handle, $headerSize - 9);
            $this->readExact($handle, 4);
            while (!feof($handle)) {
                $tagHeader = fread($handle, 11);
                if ($tagHeader === false) throw new RuntimeException('读取 FLV Tag Header 失败');
                if ($tagHeader === '') break;
                if (strlen($tagHeader) !== 11) throw new RuntimeException('FLV Tag Header 不完整');
                $size = unpack('N', "\0" . substr($tagHeader, 1, 3))[1];
                if ($size > HlsPipelineProtocol::MAX_FRAME_LENGTH) throw new RuntimeException("FLV Tag 数据过大: {$size}");
                $timestamp = unpack('N', $tagHeader[7] . substr($tagHeader, 4, 3))[1];
                $body = $this->readExact($handle, $size); $this->readExact($handle, 4);
                yield ['tagType' => ord($tagHeader[0]), 'timestamp' => $timestamp, 'body' => $body];
            }
        } finally { fclose($handle); }
    }

    private function readExact($handle, int $length): string
    {
        $data = '';
        while (strlen($data) < $length) { $chunk = fread($handle, $length - strlen($data)); if ($chunk === false || $chunk === '') throw new RuntimeException('FLV 文件数据不完整'); $data .= $chunk; }
        return $data;
    }

    private function startWorker(array $arguments): void
    {
        $options = ['bypass_shell' => true]; if (PHP_OS_FAMILY === 'Windows') $options['create_process_group'] = true;
        $pipes = [];
        $process = proc_open(array_merge([PHP_BINARY], $arguments), [fopen('php://stdin', 'r'), fopen('php://stdout', 'a'), fopen('php://stderr', 'a')], $pipes, dirname(__DIR__, 2), null, $options);
        if (!is_resource($process)) throw new RuntimeException('无法启动 FLV recode worker');
        $this->processes[] = $process;
    }

    private function waitWorkers(): void
    {
        $error = null;
        foreach ($this->processes as $key => $process) {
            if (!is_resource($process)) { unset($this->processes[$key]); continue; }
            $deadline = microtime(true) + 30;
            do { $status = proc_get_status($process); if (!$status['running']) break; usleep(1); } while (microtime(true) < $deadline);
            $timedOut = $status['running'];
            if ($timedOut) @proc_terminate($process);
            $exit = proc_close($process); unset($this->processes[$key]);
            if ($error === null && $timedOut) $error = new RuntimeException('FLV recode worker 结束超时');
            elseif ($error === null && $exit !== 0 && $exit !== -1) $error = new RuntimeException("FLV recode worker 异常退出: {$exit}");
        }
        if ($error !== null) throw $error;
    }

    private function terminateWorkers(): void
    {
        foreach ($this->processes as $key => $process) {
            if (!is_resource($process)) { unset($this->processes[$key]); continue; }
            $status = @proc_get_status($process);
            if ($status !== false && $status['running']) @proc_terminate($process);
            @proc_close($process); unset($this->processes[$key]);
        }
    }

    private function reserveAddress(): array
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($server === false) throw new RuntimeException("无法分配 loopback 端口: {$error}");
        $name = stream_socket_get_name($server, false); fclose($server); $port = (int)substr(strrchr($name, ':'), 1);
        return ["tcp://127.0.0.1:{$port}", $port];
    }

    private function connect(string $address)
    {
        $deadline = microtime(true) + 15;
        do { $socket = @stream_socket_client($address, $errno, $error, 0.2); if ($socket !== false) return $socket; usleep(1); } while (microtime(true) < $deadline);
        throw new RuntimeException("无法连接解码进程: {$error} ({$errno})");
    }

    private function locateAutoload(): string
    {
        $reflection = new ReflectionClass(ClassLoader::class);
        $path = dirname($reflection->getFileName(), 2) . DIRECTORY_SEPARATOR . 'autoload.php';
        if (!is_file($path)) throw new RuntimeException('无法定位宿主 Composer autoload.php');
        return $path;
    }
}
