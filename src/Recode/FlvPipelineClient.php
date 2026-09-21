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
        // Task 6 段池并行（fast 默认开）：解码池 → YUV → 段编码池 → 输出进程只封装
        if (!empty($this->config['segment_pool']) && $this->segmentTranscodeNeeded($sourceFps)) {
            $this->runSegmentPool($flvFile, $outputFile, $sourceFps);
            return;
        }
        $workerCount = max(1, min(8, (int)($this->config['decode_workers'] ?? 4)));
        // 波前模式可在单个 GOP 内再切区间，不再受源 GOP 数限制；否则只能在关键帧边界并行
        $wavefront = !empty($this->config['decode_wavefront']);
        if (!$wavefront && $sourceInfo['gopCount'] > 0) $workerCount = max(1, min($workerCount, $sourceInfo['gopCount']));
        $wf = $wavefront
            ? WavefrontDispatch::begin((int)$sourceInfo['videoFrames'], $sourceInfo['gopStarts'], $workerCount)
            : null;
        [, $outputPort] = $this->reserveAddress();
        $decoderAddresses = [];
        for ($i = 0; $i < $workerCount; $i++) $decoderAddresses[] = $this->reserveAddress();
        $autoload = $this->locateAutoload();
        $worker = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'flv-recode-worker.php';
        $config = $this->config;
        $config['source_fps'] = $sourceFps;
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
            $videoSampleIdx = 0; // 波前用：AVCC 视频帧（packetType=1）序号
            $audioSeq = 0;       // 波前用：音频 tag 轮转 worker
            $outbound = array_fill(0, $workerCount, '');
            $inbound = array_fill(0, $workerCount, '');
            $alive = array_fill(0, $workerCount, true);
            $tags = $this->readFlvTags($flvFile);
            $exhausted = false;
            $stopReading = false;
            $endEnqueued = false;
            $wfDeadline = null; // 源读完后等待检查点回传的看门狗
            $finishedCount = 0;

            while (true) {
                if (!$stopReading) {
                    $pendingBytes = $wf !== null ? WavefrontDispatch::pendingBytes($wf) : 0;
                    while (!$exhausted && $this->bufferedBytes($outbound) + $pendingBytes < $workerCount * self::PER_WORKER_SOFT_LIMIT) {
                        if (!$tags->valid()) { $exhausted = true; break; }
                        $tag = $tags->current(); $tags->next();
                        $frameCount++;
                        if ($tag['tagType'] === 8) {
                            $audioWorker = $wf !== null ? ($audioSeq++ % $workerCount) : $currentWorker;
                            $outbound[$audioWorker] .= HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $sequence++, [
                                'tagType' => $tag['tagType'], 'timestamp' => $tag['timestamp'], 'sourceFps' => $sourceFps,
                            ], $tag['body']);
                        } elseif ($tag['tagType'] === 9) {
                            $videoCount++;
                            $this->dispatchVideoTag($tag, $sequence, $workerCount, $gopSeq, $currentWorker, $configured, $baseTimestamp, $selected, $targetFps, $dropFrames, $sourceFps, $outbound, $wf, $videoSampleIdx);
                            if ($wf !== null) $pendingBytes = WavefrontDispatch::pendingBytes($wf);
                            if ($this->maxFrames !== null && $videoCount >= $this->maxFrames) { echo "Reached max frames limit ({$this->maxFrames}), stopping...\n"; $stopReading = true; break; }
                        }
                        if ($frameCount % 50 === 0) echo "Processed {$frameCount} frames ({$videoCount} video)\n";
                    }
                    // END 在本轮 inbound（含末段检查点回传）处理后再入队，见循环后部
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
                        if ($event['type'] === HlsPipelineProtocol::CONTROL && ($event['metadata']['cmd'] ?? '') === 'checkpoint') {
                            // 波前检查点回传：释放对应后段区间（控制帧 + 缓冲帧序列追加给归属 worker）
                            if ($wf === null) throw new RuntimeException('收到波前检查点但调度未启用波前');
                            $rel = WavefrontDispatch::release($wf, (int)$event['metadata']['range'], $event['payload']);
                            $outbound[$rel['w']] .= $rel['wire'];
                            $wfDeadline = null; // 有检查点进展：重置无进展看门狗
                        }
                    }
                    if (strlen($inbound[$id]) > HlsPipelineProtocol::MAX_BUFFER_LENGTH) throw new RuntimeException('主进程响应缓冲超限');
                }
                // 源 tag 已读完且本轮 inbound 处理完毕。
                // 自然读完：必须等全部检查点回传（源读取远快于解码，看门狗按"无检查点进展"计时，
                // 每收到一个检查点即重置，60s 无进展才判失败）；maxFrames 截断直接结束；worker 崩溃由 socket EOF 覆盖
                if (!$endEnqueued && ($exhausted || $stopReading)) {
                    $canEnd = $stopReading || $wf === null || WavefrontDispatch::allReleased($wf);
                    if (!$canEnd) {
                        // 看门狗按"无检查点进展"计时（源读取远快于解码，不能按墙钟）；worker 崩溃由 socket EOF 独立覆盖
                        $wfDeadline ??= microtime(true) + 60.0;
                        if (microtime(true) >= $wfDeadline) WavefrontDispatch::assertComplete($wf);
                    } else {
                        if ($exhausted && $wf !== null) WavefrontDispatch::assertComplete($wf);
                        $outbound[0] .= HlsPipelineProtocol::frame(HlsPipelineProtocol::END, $sequence++);
                        $endEnqueued = true;
                    }
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

    /** Task 6：FLV 是否存在实际重编码需求（与 FlvRecoder::needTranscode 同义，宽高用目标配置判定） */
    private function segmentTranscodeNeeded(?float $sourceFps): bool
    {
        $targetFps = (int)($this->config['fps'] ?? 0);
        $dropFrames = $targetFps > 0 && $sourceFps !== null && $targetFps < $sourceFps - 0.01;
        return (int)($this->config['width'] ?? 0) > 0
            || (int)($this->config['height'] ?? 0) > 0
            || (int)($this->config['bitrate'] ?? 0) > 0
            || $dropFrames
            || !empty($this->config['watermark']);
    }

    /**
     * Task 6 三段流水线：解码 worker 池（upstream，结果全回协调进程）
     * → 协调进程按输出保留帧攒段、段编码 worker 池出 NAL（gopEncoded）
     * → 输出 worker 仅按全局序号重排 + 打时间戳 + 封装。
     */
    private function runSegmentPool(string $flvFile, string $outputFile, ?float $sourceFps): void
    {
        $decodeCount = max(1, min(8, (int)($this->config['decode_workers'] ?? 4)));
        $segCount = max(1, min(8, TranscodeOptions::segmentWorkers($this->config)));
        $gopIntervalMs = max(1, (int)($this->config['gop_interval_ms'] ?? 2000));
        $targetFps = (int)($this->config['fps'] ?? 0);
        $dropFrames = $targetFps > 0 && $sourceFps !== null && $targetFps < $sourceFps - 0.01;
        $effectiveFps = $dropFrames ? (float)$targetFps : $sourceFps;
        $framesPerSeg = $effectiveFps !== null && $effectiveFps > 0
            ? max(1, (int)round($effectiveFps * $gopIntervalMs / 1000))
            : 1;

        $autoload = $this->locateAutoload();
        $worker = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'flv-recode-worker.php';
        $segWorkerBin = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'encode-segment-worker.php';
        [, $outputPort] = $this->reserveAddress();
        $decoderAddresses = [];
        for ($i = 0; $i < $decodeCount; $i++) $decoderAddresses[] = $this->reserveAddress();
        $segmentAddresses = [];
        for ($i = 0; $i < $segCount; $i++) $segmentAddresses[] = $this->reserveAddress();
        $config = $this->config;
        $config['source_fps'] = $sourceFps;
        $encodedConfig = base64_encode(json_encode($config, JSON_THROW_ON_ERROR));

        $decoderSockets = [];
        $outputSocket = null;
        $pool = null;
        try {
            $this->startWorker([$worker, '--mode', 'output', '--autoload', $autoload, '--port', (string)$outputPort, '--workers', '1', '--config', $encodedConfig, '--output', $outputFile]);
            foreach ($decoderAddresses as [, $decoderPort]) {
                // upstream 模式：不传 --output-port，解码结果直接回协调进程
                $this->startWorker([$worker, '--mode', 'decoder', '--autoload', $autoload, '--port', (string)$decoderPort, '--config', $encodedConfig]);
            }
            foreach ($segmentAddresses as [, $segPort]) {
                $this->startWorker([$segWorkerBin, '--autoload', $autoload, '--port', (string)$segPort, '--config', $encodedConfig]);
            }
            $outputSocket = $this->connect('tcp://127.0.0.1:' . $outputPort);
            stream_set_blocking($outputSocket, false);
            foreach ($decoderAddresses as [$decoderAddress]) {
                $socket = $this->connect($decoderAddress);
                stream_set_blocking($socket, false);
                $decoderSockets[] = $socket;
            }
            $segSockets = [];
            foreach ($segmentAddresses as [$segAddress]) {
                $socket = $this->connect($segAddress);
                stream_set_blocking($socket, false);
                $segSockets[] = $socket;
            }
            $pool = new SegmentEncodePool($segSockets, (int)round($effectiveFps ?? 0));

            // —— 派发源 tag 到解码池（与旧路径同一 dispatch，波前关闭）——
            $sequence = 0; $gopSeq = 0; $currentWorker = 0; $configured = false;
            $baseTimestamp = -1; $selected = 0; $videoSampleIdx = 0; $noWf = null;
            $outbound = array_fill(0, $decodeCount, '');
            $inbound = array_fill(0, $decodeCount, '');
            $alive = array_fill(0, $decodeCount, true);
            // 各 decoder 已见最大全局序号：反压时只放行"可能补齐队首缺口"的落后连接，
            // 避免重排队列超水位后全连接停读、慢连接的队首事件永远进不来的死锁
            $lastDecSeq = array_fill(0, $decodeCount, -1);
            $tags = $this->readFlvTags($flvFile);
            $exhausted = false; $stopReading = false;
            $sentEventCount = 0;
            $frameCount = 0; $videoCount = 0;

            // —— 解码结果全局按序重排 ——
            $pending = [];
            $expected = 0;

            // —— 段池状态 ——
            $curSeg = null;
            $retainedCount = 0;
            /** @var array<int,array{meta:array,payload:string}> 全局帧序号 => 段帧待发布元数据（发布后删除） */
            $frameMeta = [];
            /** @var array<int,array{meta:array,payload:string}> 全局帧序号 => 已就绪的直通事件（音频/序列头/回退帧） */
            $waitPub = [];
            /** @var array<int,array{s:int,k:bool,n:array}> 全局帧序号 => 段编码已完成的帧（乱序到达，按序发布） */
            $readyFrames = [];
            /** @var array<int,true> 全局帧序号 => drop 形成的永久空洞（发布游标必须跨过） */
            $holes = [];
            $pubExpected = 0; // 下一个应按全局序号发布的位置
            $outSeq = 0;      // 输出进程要求序号从 0 连续：发布时重新编号

            // —— 输出进程连接 ——
            $outOut = '';
            $outIn = '';
            $outAlive = true;
            $outFinished = false;
            $outEndSent = false;

            // —— 收尾状态 ——
            $decEndSent = false;
            $decFinished = 0;
            $lastSegClosed = false;
            $poolFinished = false;
            $lastProgress = microtime(true);

            while (true) {
                // 1) 派发源 tag（反压：解码 worker 在途 8MB/个）
                if (!$exhausted && !$stopReading) {
                    while (!$exhausted && $this->bufferedBytes($outbound) < $decodeCount * self::PER_WORKER_SOFT_LIMIT) {
                        if (!$tags->valid()) { $exhausted = true; break; }
                        $tag = $tags->current(); $tags->next();
                        $frameCount++;
                        if ($tag['tagType'] === 8) {
                            $outbound[$currentWorker] .= HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $sequence++, [
                                'tagType' => $tag['tagType'], 'timestamp' => $tag['timestamp'], 'sourceFps' => $sourceFps,
                            ], $tag['body']);
                            $sentEventCount++;
                        } elseif ($tag['tagType'] === 9) {
                            $videoCount++;
                            $this->dispatchVideoTag($tag, $sequence, $decodeCount, $gopSeq, $currentWorker, $configured, $baseTimestamp, $selected, $targetFps, $dropFrames, $sourceFps, $outbound, $noWf, $videoSampleIdx);
                            $sentEventCount++;
                            if ($this->maxFrames !== null && $videoCount >= $this->maxFrames) { echo "Reached max frames limit ({$this->maxFrames}), stopping...\n"; $stopReading = true; break; }
                        }
                        if ($frameCount % 50 === 0) echo "Processed {$frameCount} frames ({$videoCount} video)\n";
                    }
                }

                // 2) select：解码池 + 段编码池 + 输出进程
                $read = [];
                $reorderBytes = 0;
                foreach ($pending as $pe) $reorderBytes += strlen($pe['payload']);
                $backpressure = $reorderBytes > HlsPipelineProtocol::HIGH_WATERMARK
                    || strlen($outOut) > HlsPipelineProtocol::HIGH_WATERMARK;
                foreach ($alive as $id => $isAlive) {
                    if (!$isAlive) continue;
                    // 反压时仅读"落后连接"（最后见到的序号仍小于重排队首 expected）：
                    // 缺口只可能由它们补齐；继续读超前连接只会让积压无限膨胀
                    if (!$backpressure || $lastDecSeq[$id] < $expected) $read[] = $decoderSockets[$id];
                }
                foreach ($pool->readSockets() as $s) $read[] = $s;
                if ($outAlive && strlen($outIn) < HlsPipelineProtocol::HIGH_WATERMARK) $read[] = $outputSocket;
                $write = [];
                foreach ($outbound as $id => $buffer) if ($buffer !== '' && $alive[$id]) $write[] = $decoderSockets[$id];
                foreach ($pool->writeSockets() as $s) $write[] = $s;
                if ($outOut !== '' && $outAlive) $write[] = $outputSocket;
                if ($read === [] && $write === []) {
                    if ($outFinished) break;
                    usleep(5000);
                    if (microtime(true) - $lastProgress > 180) throw new RuntimeException('段池流水线 180s 无进展');
                    continue;
                }
                $except = null;
                if (@stream_select($read, $write, $except, 1) === false) continue;

                foreach ($write as $socket) {
                    if ($socket === $outputSocket) {
                        $n = @fwrite($socket, substr($outOut, 0, 262144));
                        if ($n === false || ($n === 0 && feof($socket))) {
                            if (!$outEndSent) throw new RuntimeException('输出进程连接意外关闭');
                            $outAlive = false;
                        } elseif ($n > 0) { $outOut = substr($outOut, $n); $lastProgress = microtime(true); }
                        continue;
                    }
                    if (($pid = array_search($socket, $segSockets, true)) !== false) {
                        continue; // 段池 socket 在 service() 内统一写
                    }
                    $id = (int)array_search($socket, $decoderSockets, true);
                    $n = @fwrite($socket, substr($outbound[$id], 0, 65536));
                    if ($n === false || ($n === 0 && feof($socket))) {
                        if (!$decEndSent) throw new RuntimeException('解码进程连接意外关闭');
                        $alive[$id] = false;
                    } elseif ($n > 0) { $outbound[$id] = substr($outbound[$id], $n); $lastProgress = microtime(true); }
                }
                // 段池写/读（含 segDone 解析）
                $poolRead = [];
                foreach ($read as $socket) if (array_search($socket, $segSockets, true) !== false) $poolRead[] = $socket;
                $poolWrite = [];
                foreach ($write as $socket) if (array_search($socket, $segSockets, true) !== false) $poolWrite[] = $socket;
                if ($poolRead !== [] || $poolWrite !== []) {
                    $beforeDone = $pool->doneOrderCount();
                    $pool->service($poolRead, $poolWrite);
                    if ($pool->doneOrderCount() > $beforeDone) $lastProgress = microtime(true);
                }
                foreach ($read as $socket) {
                    if (array_search($socket, $segSockets, true) !== false) continue;
                    if ($socket === $outputSocket) {
                        $chunk = @fread($socket, 65536);
                        if ($chunk === false || ($chunk === '' && feof($socket))) {
                            if (!$outEndSent) throw new RuntimeException('输出进程连接意外关闭');
                            $outAlive = false;
                        } elseif ($chunk !== '') $outIn .= $chunk;
                        continue;
                    }
                    $id = (int)array_search($socket, $decoderSockets, true);
                    $chunk = @fread($socket, 65536);
                    if ($chunk === false || ($chunk === '' && feof($socket))) {
                        if (!$decEndSent) throw new RuntimeException('解码进程连接意外关闭');
                        $alive[$id] = false;
                    } elseif ($chunk !== '') { $inbound[$id] .= $chunk; $lastProgress = microtime(true); }
                }
                // 解码池控制帧/结束帧
                foreach ($inbound as $id => $buffer) {
                    foreach (HlsPipelineProtocol::take($inbound[$id], PHP_INT_MAX) as $event) {
                        if ($event['type'] === HlsPipelineProtocol::ERROR) throw new RuntimeException($event['metadata']['message'] ?? '段池流水线失败');
                        if ($event['type'] === HlsPipelineProtocol::FINISHED) { $decFinished++; continue; }
                        if ($event['type'] === HlsPipelineProtocol::CONTROL) {
                            // upstream 非波前模式下不应出现检查点
                            throw new RuntimeException('段池模式收到意外控制帧: ' . ($event['metadata']['cmd'] ?? ''));
                        }
                        $seq = (int)$event['sequence'];
                        if (isset($pending[$seq])) throw new RuntimeException("解码结果序号重复: {$seq}");
                        $pending[$seq] = $event;
                        if ($seq > $lastDecSeq[$id]) $lastDecSeq[$id] = $seq;
                    }
                    if (strlen($inbound[$id]) > HlsPipelineProtocol::MAX_BUFFER_LENGTH) throw new RuntimeException('协调进程解码响应缓冲超限');
                }
                foreach (HlsPipelineProtocol::take($outIn, PHP_INT_MAX) as $event) {
                    if ($event['type'] === HlsPipelineProtocol::ERROR) throw new RuntimeException($event['metadata']['message'] ?? '输出进程失败');
                    if ($event['type'] === HlsPipelineProtocol::FINISHED) { $outFinished = true; }
                }

                // 3) 按全局序号消费解码结果：直通帧入有序发布队列；保留视频帧攒段。
                //    消费/开段不受发布进度阻塞——后续段必须能提前开启才有段间并行
                while (isset($pending[$expected])) {
                    $event = $pending[$expected];
                    $meta = $event['metadata'];
                    $payload = $event['payload'];
                    $isVideo = (int)($meta['tagType'] ?? 0) === 9;
                    $isDrop = !empty($meta['drop']);
                    $isDecoded = !empty($meta['decoded']);
                    // 注意：已解码事件 payload 为 pack('N',avccLen).avcc.YUV，不能再用 payload[1] 的 AVC packetType 分类
                    $packetType = ($isVideo && !$isDecoded && strlen($payload) >= 2) ? ord($payload[1]) : -1;
                    $boundary = ($retainedCount % $framesPerSeg === 0);

                    if (!$isVideo || $isDrop || !$isDecoded) {
                        // 丢弃帧（跳过，不占输出序号）；其余直通事件先入全局有序发布队列，
                        // 与段帧统一按全局源顺序、用连续输出序号发布（输出 worker 要求 sequence 连续）
                        if ($isVideo && $isDrop) {
                            $holes[$expected] = true; // drop 帧是发布序列中的永久空洞
                            unset($pending[$expected]); $expected++; continue;
                        }
                        $waitPub[(int)$event['sequence']] = ['meta' => $meta, 'payload' => $payload];
                        if ($isVideo && $packetType === 1) $retainedCount++;
                        unset($pending[$expected]); $expected++;
                        continue;
                    }

                    // 保留视频帧（已解码 YUV）→ 段编码池
                    if ($boundary) {
                        if ($curSeg !== null) { $pool->closeSegment($curSeg); $curSeg = null; }
                        if ($pool->freeWorkerCount() === 0) { break; }
                        $curSeg = $pool->openSegment();
                    } elseif ($curSeg === null) {
                        if ($pool->freeWorkerCount() === 0) { break; }
                        $curSeg = $pool->openSegment();
                    }
                    $bodyLength = unpack('N', substr($payload, 0, 4))[1];
                    $body = substr($payload, 4, $bodyLength);
                    $yuv = substr($payload, 4 + $bodyLength);
                    $variant = $meta['variants']['default'] ?? null;
                    if (!is_array($variant) || (int)$variant['length'] !== strlen($yuv)) {
                        throw new RuntimeException('解码帧 YUV 长度与 variants 描述不一致');
                    }
                    if ($pool->segmentOutboundBytes($curSeg) + strlen($yuv) > HlsPipelineProtocol::HIGH_WATERMARK) { break; }
                    // 输出侧 keyframe 必须与实际 NAL 一致：段内源关键帧编为 P，首字节 FrameType 按 forcedIdr 重写
                    $pubBody = $body;
                    $pubBody[0] = chr(($boundary ? 0x10 : 0x20) | 0x07);
                    $frameMeta[(int)$event['sequence']] = [
                        'meta' => [
                            'tagType' => 9, 'timestamp' => (int)$meta['timestamp'],
                            'sourceFps' => $sourceFps, 'decoded' => true,
                        ],
                        'payload' => pack('N', strlen($pubBody)) . $pubBody . $yuv,
                    ];
                    $pool->submitFrame($curSeg, (int)$event['sequence'], $boundary, (int)$variant['width'], (int)$variant['height'], $yuv);
                    $retainedCount++;
                    unset($pending[$expected]); $expected++;
                }

                // 4) 全局有序发布：段结果乱序到达先入 readyFrames，再与直通事件一起
                //    严格按全局源顺序、用连续的输出序号发往输出进程
                foreach ($pool->drainDone() as $segSeq => $frames) {
                    foreach ($frames as $frame) {
                        $fseq = (int)$frame['s'];
                        if (isset($readyFrames[$fseq])) throw new RuntimeException("段结果帧 {$fseq} 重复完成");
                        $readyFrames[$fseq] = ['key' => (bool)$frame['k'], 'nals' => $frame['n']];
                    }
                }
                while (true) {
                    if (strlen($outOut) > HlsPipelineProtocol::HIGH_WATERMARK) break;
                    if (isset($waitPub[$pubExpected])) {
                        $item = $waitPub[$pubExpected];
                        $outOut .= HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $outSeq, $item['meta'], $item['payload']);
                        unset($waitPub[$pubExpected]);
                        $pubExpected++; $outSeq++;
                        continue;
                    }
                    if (isset($readyFrames[$pubExpected])) {
                        $fseq = $pubExpected;
                        if (!isset($frameMeta[$fseq])) throw new RuntimeException("段结果帧 {$fseq} 缺少发布元数据");
                        $pub = $frameMeta[$fseq];
                        $pubMeta = $pub['meta'] + [
                            'forcedIdr' => $readyFrames[$fseq]['key'],
                            'gopEncoded' => ['profiles' => ['default' => $readyFrames[$fseq]['nals']]],
                        ];
                        $outOut .= HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $outSeq, $pubMeta, $pub['payload']);
                        unset($frameMeta[$fseq], $readyFrames[$fseq]);
                        $pubExpected++; $outSeq++;
                        continue;
                    }
                    // 下一位置是 drop 永久空洞：跨过
                    if (isset($holes[$pubExpected])) {
                        unset($holes[$pubExpected]);
                        $pubExpected++;
                        continue;
                    }
                    // 下一位置是已提交但未完成的段帧：停止本轮发布等待段结果（不阻塞开新段）
                    break;
                }

                // 5) 收尾：源派发完 → END 全体解码 worker → 收齐 FINISHED 且结果消费完
                //    → 闭合末段 → 等段池发布完 → END 段池 → END 输出进程
                if (!$decEndSent && ($exhausted || $stopReading)) {
                    for ($id = 0; $id < $decodeCount; $id++) {
                        $outbound[$id] .= HlsPipelineProtocol::frame(HlsPipelineProtocol::END, $sentEventCount + $id);
                    }
                    $decEndSent = true;
                }
                if ($decEndSent && $decFinished >= $decodeCount && $expected >= $sentEventCount) {
                    if (!$lastSegClosed) {
                        if ($curSeg !== null) { $pool->closeSegment($curSeg); $curSeg = null; }
                        $lastSegClosed = true;
                    }
                    if ($pool->pendingSegmentCount() === 0 && $frameMeta === [] && $readyFrames === [] && $waitPub === [] && $holes === []) {
                        if (!$poolFinished) { $pool->finishStart(); $poolFinished = true; }
                    }
                }
                if ($poolFinished && $pool->allFinished() && !$outEndSent
                    && $frameMeta === [] && $readyFrames === [] && $waitPub === [] && $holes === []) {
                    $outOut .= HlsPipelineProtocol::frame(HlsPipelineProtocol::END, $outSeq);
                    $outEndSent = true;
                }
                if ($outFinished) break;
            }

            $pool->closeSockets();
            foreach ($decoderSockets as $socket) @fclose($socket);
            if (is_resource($outputSocket)) @fclose($outputSocket);
            $this->waitWorkers();
            echo "Done! Processed {$frameCount} frames ({$videoCount} video), retained {$retainedCount}\nOutput: {$outputFile}\n";
        } catch (Throwable $e) {
            if ($pool !== null) $pool->closeSockets();
            foreach ($decoderSockets as $socket) if (is_resource($socket)) @fclose($socket);
            if (is_resource($outputSocket)) @fclose($outputSocket);
            $this->terminateWorkers();
            if (is_file($outputFile . '.part')) @unlink($outputFile . '.part');
            throw $e;
        }
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
        array &$outbound,
        ?array &$wf = null,
        int &$videoSampleIdx = 0
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
        if ($packetType !== 1) {
            // AVC end-of-sequence（packetType=2）等非 NALU 视频 tag：透传，不计入视频帧序号（扫描也不计）
            $outbound[0] .= HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $sequence++, [
                'tagType' => 9, 'timestamp' => $tag['timestamp'], 'sourceFps' => $sourceFps,
            ], $body);
            return;
        }

        $isKey = (ord($body[0]) >> 4) === 1 && $this->containsIdrNal($body);
        if ($wf === null && $isKey) {
            // 每个IDR开启一个独立GOP，轮询分配给空闲解码worker
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
            if (!$drop && $dropFrames && $selected > 0 && ($timestamp - $baseTimestamp) * $targetFps < $selected * 1000) {
                $drop = true;
            }
            if (!$drop) $selected++;
        }

        $meta = ['tagType' => 9, 'timestamp' => $timestamp, 'sourceFps' => $sourceFps];
        if ($drop) $meta['drop'] = true;
        if ($wf !== null) {
            // 波前：按帧区间路由；后段区间帧缓冲至前段检查点到达
            $route = WavefrontDispatch::routeVideo($wf, $videoSampleIdx, $meta);
            $wire = HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $sequence++, $meta, $body);
            if ($route['hold']) $wf['ranges'][$route['rid']]['buffer'] .= $wire;
            else $outbound[$route['w']] .= $wire;
            $videoSampleIdx++;
        } else {
            $outbound[$currentWorker] .= HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $sequence++, $meta, $body);
        }
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
     * 预扫描（稀疏）：按大块（256KB）顺序读一次文件并在缓冲内解析 tag header，
     * 普通 tag body 不读入；仅对 FrameType=1 的视频包补读完整 body 做精确 IDR NAL 判定
     * （关键帧数/波前区间规划要求与实际 IDR 一致，不能只看 FLV FrameType 位）。
     * @return array{fps: ?float, gopCount: int, gopStarts: int[], videoFrames: int}
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
            $gopStarts = [];
            $buffer = '';
            $pos = 0;
            $eof = false;
            $chunkSize = 262144;
            while ($this->scanEnsure($handle, $buffer, $pos, $eof, 11, $chunkSize)) {
                $dataSize = (ord($buffer[$pos + 1]) << 16) | (ord($buffer[$pos + 2]) << 8) | ord($buffer[$pos + 3]);
                if ($dataSize > HlsPipelineProtocol::MAX_FRAME_LENGTH) break; // 残包/损坏文件，停止扫描
                $tagType = ord($buffer[$pos]);
                $timestamp = unpack('N', $buffer[$pos + 7] . substr($buffer, $pos + 4, 3))[1];

                // 视频包需要 body 前 2 字节（11B header 之后）判 AVCPacketType
                if ($tagType === 9 && $dataSize >= 2 && $this->scanEnsure($handle, $buffer, $pos, $eof, 13, $chunkSize)) {
                    $packetType = ord($buffer[$pos + 12]);
                    if ($packetType === 1) {
                        $first ??= $timestamp; $last = $timestamp;
                        if ((ord($buffer[$pos + 11]) >> 4) === 1
                            && $this->scanEnsure($handle, $buffer, $pos, $eof, 11 + $dataSize, $chunkSize)
                            && $this->containsIdrNal(substr($buffer, $pos + 11, $dataSize))) {
                            $gopCount++;
                            $gopStarts[] = $count;
                        }
                        $count++;
                    }
                }
                $pos += 11 + $dataSize + 4; // tag header + body + PreviousTagSize
            }
            $fps = $count >= 2 && $last > $first ? ($count - 1) * 1000 / ($last - $first) : null;
            return ['fps' => $fps, 'gopCount' => $gopCount, 'gopStarts' => $gopStarts, 'videoFrames' => $count];
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
            do { $status = proc_get_status($process); if (!$status['running']) break; usleep(50000); } while (microtime(true) < $deadline);
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
        do { $socket = @stream_socket_client($address, $errno, $error, 0.2); if ($socket !== false) return $socket; usleep(50000); } while (microtime(true) < $deadline);
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
