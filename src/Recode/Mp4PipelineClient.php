<?php

namespace Xiaosongshu\Flv2mp4\Recode;

use Composer\Autoload\ClassLoader;
use ReflectionClass;
use RuntimeException;
use Throwable;

/**
 * @purpose mp4重编码分布式架构-管道（多解码worker按GOP并行）
 * @author yanglong
 */
final class Mp4PipelineClient
{
    private array $processes = [];
    /** 每个解码worker待写入缓冲区的软上限 */
    private const PER_WORKER_SOFT_LIMIT = 8388608;

    public function __construct(private array $config, private ?int $maxFrames)
    {
    }

    public function process(string $inputFile, string $outputFile): void
    {
        $workerCount = max(1, min(8, (int)($this->config['decode_workers'] ?? 4)));
        $autoload = $this->locateAutoload();
        $worker = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'mp4-recode-worker.php';
        [$streamMetadata, $samples] = (new Mp4Recoder($this->config, false))->preparePipelineInput($inputFile);
        // 波前只在实际需要重编码时才有意义（直通素材 worker 不解码，无检查点可交接）
        $needTranscode = ((int)($this->config['width'] ?? 0) > 0 && (int)$streamMetadata['srcWidth'] !== (int)$streamMetadata['outputWidth'])
            || ((int)($this->config['height'] ?? 0) > 0 && (int)$streamMetadata['srcHeight'] !== (int)$streamMetadata['outputHeight'])
            || (int)($this->config['bitrate'] ?? 0) > 0 || !empty($streamMetadata['dropFrames']) || !empty($this->config['watermark']);
        // Task 6 段池并行（fast 默认开）：波前在三段流水线下不启用，避免已知多 GOP 死锁
        if (!empty($this->config['segment_pool']) && $needTranscode) {
            $this->runSegmentPool($inputFile, $outputFile, $streamMetadata, $samples);
            return;
        }
        $wavefront = !empty($this->config['decode_wavefront']) && $needTranscode;
        // GOP 边界（视频样本序号）供 worker 收敛/波前规划
        $gopCount = 0;
        $gopStarts = [];
        $videoFrames = 0;
        foreach ($samples as $sample) {
            if ($sample['type'] !== 'video') continue;
            if (!empty($sample['keyframe'])) { $gopCount++; $gopStarts[] = $videoFrames; }
            $videoFrames++;
        }
        // 波前模式可在单个 GOP 内再切区间，不再受源 GOP 数限制
        if (!$wavefront && $gopCount > 0) $workerCount = max(1, min($workerCount, $gopCount));
        $wf = $wavefront ? WavefrontDispatch::begin($videoFrames, $gopStarts, $workerCount) : null;
        [, $outputPort] = $this->reserveAddress();
        $decoderAddresses = [];
        for ($i = 0; $i < $workerCount; $i++) $decoderAddresses[] = $this->reserveAddress();
        $config = $this->config;
        $config['pipeline'] = $streamMetadata;
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

            $dropFrames = !empty($streamMetadata['dropFrames']);
            $targetFps = isset($streamMetadata['effectiveTargetFps']) ? (float)$streamMetadata['effectiveTargetFps'] : 0.0;

            $sequence = 0;
            $gopSeq = 0;
            $currentWorker = 0;
            $baseTimestamp = -1;
            $selected = 0;
            $videoCount = 0;
            $videoSampleIdx = 0; // 波前用：视频样本序号
            $audioSeq = 0;       // 波前用：音频 sample 轮转 worker
            $index = 0;
            $total = count($samples);
            $outbound = array_fill(0, $workerCount, '');
            $inbound = array_fill(0, $workerCount, '');
            $alive = array_fill(0, $workerCount, true);
            $allEnqueued = false;
            $endEnqueued = false;
            $wfDeadline = null; // 源读完后等待检查点回传的看门狗
            $finishedCount = 0;

            while (true) {
                if (!$allEnqueued) {
                    $pendingBytes = $wf !== null ? WavefrontDispatch::pendingBytes($wf) : 0;
                    while ($index < $total && $this->bufferedBytes($outbound) + $pendingBytes < $workerCount * self::PER_WORKER_SOFT_LIMIT) {
                        $sample = $samples[$index++];
                        if ($sample['type'] === 'video') {
                            $videoCount++;
                            if ($wf === null && !empty($sample['keyframe'])) {
                                // 每个关键帧开启一个独立GOP，轮询分配给解码worker
                                $currentWorker = $gopSeq % $workerCount;
                                $gopSeq++;
                            }
                            $meta = [
                                'sampleType' => 'video', 'dtsMs' => $sample['dtsMs'],
                                'ctsMs' => $sample['ctsMs'], 'keyframe' => $sample['keyframe'],
                            ];
                            $drop = false;
                            $timestamp = (int)$sample['dtsMs'];
                            if ($baseTimestamp < 0) {
                                if (empty($sample['keyframe'])) $drop = true;
                                else $baseTimestamp = $timestamp;
                            }
                            if (!$drop && $dropFrames && $selected > 0 && ($timestamp - $baseTimestamp) * $targetFps < $selected * 1000) {
                                $drop = true;
                            }
                            if (!$drop) $selected++;
                            if ($drop) $meta['drop'] = true;
                            if ($wf !== null) {
                                // 波前：按帧区间路由；后段区间帧缓冲至前段检查点到达
                                $route = WavefrontDispatch::routeVideo($wf, $videoSampleIdx, $meta);
                                $wire = HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $sequence++, $meta, $sample['data']);
                                if ($route['hold']) $wf['ranges'][$route['rid']]['buffer'] .= $wire;
                                else $outbound[$route['w']] .= $wire;
                                $videoSampleIdx++;
                                $pendingBytes = WavefrontDispatch::pendingBytes($wf);
                            } else {
                                $outbound[$currentWorker] .= HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $sequence++, $meta, $sample['data']);
                            }
                            if ($this->maxFrames !== null && $videoCount >= $this->maxFrames) { echo "Reached max frames limit ({$this->maxFrames}), stopping...\n"; $allEnqueued = true; break; }
                            if ($videoCount % 10 === 0) echo "Processed {$videoCount} video frames\n";
                        } else {
                            if ($this->maxFrames !== null && $videoCount >= $this->maxFrames) continue;
                            $audioWorker = $wf !== null ? ($audioSeq++ % $workerCount) : $currentWorker;
                            $outbound[$audioWorker] .= HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $sequence++, [
                                'sampleType' => 'audio', 'dtsMs' => $sample['dtsMs'],
                                'ctsMs' => $sample['ctsMs'], 'keyframe' => $sample['keyframe'],
                            ], $sample['data']);
                        }
                    }
                    if ($index >= $total) $allEnqueued = true;
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
                        if ($event['type'] === HlsPipelineProtocol::ERROR) throw new RuntimeException($event['metadata']['message'] ?? 'MP4 流水线失败');
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
                // 源样本已读完且本轮 inbound 处理完毕。
                // 自然读完：必须等全部检查点回传（源读取远快于解码，看门狗按"无检查点进展"计时，
                // 每收到一个检查点即重置，60s 无进展才判失败）；maxFrames 截断直接结束；worker 崩溃由 socket EOF 覆盖
                if ($allEnqueued && !$endEnqueued) {
                    $readAll = $index >= $total;
                    $canEnd = !$readAll || $wf === null || WavefrontDispatch::allReleased($wf);
                    if (!$canEnd) {
                        $wfDeadline ??= microtime(true) + 60.0;
                        if (microtime(true) >= $wfDeadline) WavefrontDispatch::assertComplete($wf);
                    } else {
                        if ($readAll && $wf !== null) WavefrontDispatch::assertComplete($wf);
                        $outbound[0] .= HlsPipelineProtocol::frame(HlsPipelineProtocol::END, $sequence++);
                        $endEnqueued = true;
                    }
                }
                if ($finishedCount >= $workerCount) break;
                if ($endEnqueued && !in_array(true, $alive, true)) throw new RuntimeException('解码进程未返回 FINISHED');
            }
            foreach ($sockets as $socket) @fclose($socket);
            $this->waitWorkers();
            echo "Done! Output: {$outputFile}\nOutput size: " . filesize($outputFile) . " bytes\n";
        } catch (Throwable $e) {
            foreach ($sockets as $socket) if (is_resource($socket)) @fclose($socket);
            $this->terminateWorkers();
            throw $e;
        }
    }

    /**
     * Task 6 三段流水线：解码 worker 池（upstream）→ 协调进程攒段 + 段编码池（gopEncoded）
     * → 输出 worker 仅按序号重排 + 打时间戳 + MP4 封装。
     */
    private function runSegmentPool(string $inputFile, string $outputFile, array $streamMetadata, array $samples): void
    {
        $gopCount = 0;
        foreach ($samples as $sample) {
            if ($sample['type'] === 'video' && !empty($sample['keyframe'])) $gopCount++;
        }
        $decodeCount = max(1, min(8, (int)($this->config['decode_workers'] ?? 4)));
        if ($gopCount > 0) $decodeCount = max(1, min($decodeCount, $gopCount));
        $segCount = max(1, min(8, TranscodeOptions::segmentWorkers($this->config)));
        $gopIntervalMs = max(1, (int)($this->config['gop_interval_ms'] ?? 2000));
        $dropFrames = !empty($streamMetadata['dropFrames']);
        $effectiveFps = isset($streamMetadata['effectiveTargetFps']) ? (float)$streamMetadata['effectiveTargetFps'] : 0.0;
        $framesPerSeg = $effectiveFps > 0
            ? max(1, (int)round($effectiveFps * $gopIntervalMs / 1000))
            : 1;

        $autoload = $this->locateAutoload();
        $worker = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'mp4-recode-worker.php';
        $segWorkerBin = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'encode-segment-worker.php';
        [, $outputPort] = $this->reserveAddress();
        $decoderAddresses = [];
        for ($i = 0; $i < $decodeCount; $i++) $decoderAddresses[] = $this->reserveAddress();
        $segmentAddresses = [];
        for ($i = 0; $i < $segCount; $i++) $segmentAddresses[] = $this->reserveAddress();
        $config = $this->config;
        $config['pipeline'] = $streamMetadata;
        $encodedConfig = base64_encode(json_encode($config, JSON_THROW_ON_ERROR));

        $decoderSockets = [];
        $outputSocket = null;
        $pool = null;
        try {
            $this->startWorker([$worker, '--mode', 'output', '--autoload', $autoload, '--port', (string)$outputPort, '--workers', '1', '--config', $encodedConfig, '--output', $outputFile]);
            foreach ($decoderAddresses as [, $decoderPort]) {
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
            $pool = new SegmentEncodePool($segSockets, (int)round($effectiveFps));

            // —— 派发样本到解码池 ——
            $sequence = 0; $gopSeq = 0; $currentWorker = 0;
            $baseTimestamp = -1; $selected = 0;
            $outbound = array_fill(0, $decodeCount, '');
            $inbound = array_fill(0, $decodeCount, '');
            $alive = array_fill(0, $decodeCount, true);
            // 各 decoder 已见最大全局序号：反压时只放行"可能补齐队首缺口"的落后连接（防死锁，同 FLV 端）
            $lastDecSeq = array_fill(0, $decodeCount, -1);
            $index = 0; $total = count($samples);
            $allEnqueued = false; $stopReading = false;
            $sentEventCount = 0; $videoCount = 0;

            // —— 解码结果按序重排 ——
            $pending = [];
            $expected = 0;

            // —— 段池 ——
            $curSeg = null;
            $retainedCount = 0;
            /** @var array<int,array{dtsMs:int}> 全局帧序号 => 段帧发布时间戳（发布后删除） */
            $frameMeta = [];
            /** @var array<int,array{meta:array,payload:string}> 全局帧序号 => 已就绪直通事件 */
            $waitPub = [];
            /** @var array<int,array{key:bool,nals:array}> 全局帧序号 => 段编码完成帧 */
            $readyFrames = [];
            /** @var array<int,true> 全局帧序号 => drop 形成的永久空洞（发布游标必须跨过） */
            $holes = [];
            $pubExpected = 0;
            $outSeq = 0; // 输出进程要求序号从 0 连续：发布时重新编号

            // —— 输出进程 ——
            $outOut = ''; $outIn = '';
            $outAlive = true; $outFinished = false; $outEndSent = false;

            // —— 收尾 ——
            $decEndSent = false; $decFinished = 0;
            $lastSegClosed = false; $poolFinished = false;
            $lastProgress = microtime(true);

            while (true) {
                // 1) 派发样本
                if (!$allEnqueued && !$stopReading) {
                    while ($index < $total && $this->bufferedBytes($outbound) < $decodeCount * self::PER_WORKER_SOFT_LIMIT) {
                        $sample = $samples[$index++];
                        if ($sample['type'] === 'video') {
                            $videoCount++;
                            if (!empty($sample['keyframe'])) {
                                $currentWorker = $gopSeq % $decodeCount;
                                $gopSeq++;
                            }
                            $meta = [
                                'sampleType' => 'video', 'dtsMs' => $sample['dtsMs'],
                                'ctsMs' => $sample['ctsMs'], 'keyframe' => $sample['keyframe'],
                            ];
                            $drop = false;
                            $timestamp = (int)$sample['dtsMs'];
                            if ($baseTimestamp < 0) {
                                if (empty($sample['keyframe'])) $drop = true;
                                else $baseTimestamp = $timestamp;
                            }
                            if (!$drop && $dropFrames && $selected > 0 && ($timestamp - $baseTimestamp) * $effectiveFps < $selected * 1000) {
                                $drop = true;
                            }
                            if (!$drop) $selected++;
                            if ($drop) $meta['drop'] = true;
                            $outbound[$currentWorker] .= HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $sequence++, $meta, $sample['data']);
                            $sentEventCount++;
                            if ($this->maxFrames !== null && $videoCount >= $this->maxFrames) { echo "Reached max frames limit ({$this->maxFrames}), stopping...\n"; $stopReading = true; break; }
                            if ($videoCount % 10 === 0) echo "Processed {$videoCount} video frames\n";
                        } else {
                            $outbound[$currentWorker] .= HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $sequence++, [
                                'sampleType' => 'audio', 'dtsMs' => $sample['dtsMs'],
                                'ctsMs' => $sample['ctsMs'], 'keyframe' => $sample['keyframe'],
                            ], $sample['data']);
                            $sentEventCount++;
                        }
                    }
                    if ($index >= $total) $allEnqueued = true;
                }

                // 2) select
                $read = [];
                $reorderBytes = 0;
                foreach ($pending as $pe) $reorderBytes += strlen($pe['payload']);
                $backpressure = $reorderBytes > HlsPipelineProtocol::HIGH_WATERMARK
                    || strlen($outOut) > HlsPipelineProtocol::HIGH_WATERMARK;
                foreach ($alive as $id => $isAlive) {
                    if (!$isAlive) continue;
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
                    if (array_search($socket, $segSockets, true) !== false) continue;
                    $id = (int)array_search($socket, $decoderSockets, true);
                    $n = @fwrite($socket, substr($outbound[$id], 0, 65536));
                    if ($n === false || ($n === 0 && feof($socket))) {
                        if (!$decEndSent) throw new RuntimeException('解码进程连接意外关闭');
                        $alive[$id] = false;
                    } elseif ($n > 0) { $outbound[$id] = substr($outbound[$id], $n); $lastProgress = microtime(true); }
                }
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
                foreach ($inbound as $id => $buffer) {
                    foreach (HlsPipelineProtocol::take($inbound[$id], PHP_INT_MAX) as $event) {
                        if ($event['type'] === HlsPipelineProtocol::ERROR) throw new RuntimeException($event['metadata']['message'] ?? 'MP4 段池流水线失败');
                        if ($event['type'] === HlsPipelineProtocol::FINISHED) { $decFinished++; continue; }
                        if ($event['type'] === HlsPipelineProtocol::CONTROL) {
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
                    if ($event['type'] === HlsPipelineProtocol::FINISHED) $outFinished = true;
                }

                // 3) 按序消费：直通事件入有序发布队列；保留视频帧攒段。
                //    消费/开段不受发布进度阻塞——后续段必须能提前开启才有段间并行
                while (isset($pending[$expected])) {
                    $event = $pending[$expected];
                    $meta = $event['metadata'];
                    $payload = $event['payload'];
                    $isVideo = ($meta['sampleType'] ?? '') === 'video';
                    $isDrop = !empty($meta['drop']);
                    $isDecoded = !empty($meta['decoded']);
                    $boundary = ($retainedCount % $framesPerSeg === 0);

                    if (!$isVideo) {
                        $waitPub[(int)$event['sequence']] = ['meta' => $meta, 'payload' => $payload];
                        unset($pending[$expected]); $expected++;
                        continue;
                    }
                    if ($isDrop) {
                        $holes[$expected] = true; // drop 帧是发布序列中的永久空洞
                        unset($pending[$expected]); $expected++; continue;
                    }
                    if (!$isDecoded) {
                        // 解码失败回退帧：原样转输出进程本地处理
                        $waitPub[(int)$event['sequence']] = ['meta' => $meta, 'payload' => $payload];
                        $retainedCount++;
                        unset($pending[$expected]); $expected++;
                        continue;
                    }

                    if ($boundary) {
                        if ($curSeg !== null) { $pool->closeSegment($curSeg); $curSeg = null; }
                        if ($pool->freeWorkerCount() === 0) { break; }
                        $curSeg = $pool->openSegment();
                    } elseif ($curSeg === null) {
                        if ($pool->freeWorkerCount() === 0) { break; }
                        $curSeg = $pool->openSegment();
                    }
                    $bodyLength = unpack('N', substr($payload, 0, 4))[1];
                    $avcc = substr($payload, 4, $bodyLength);
                    $yuv = substr($payload, 4 + $bodyLength);
                    $variant = $meta['variants']['default'] ?? null;
                    if (!is_array($variant) || (int)$variant['length'] !== strlen($yuv)) {
                        throw new RuntimeException('解码帧 YUV 长度与 variants 描述不一致');
                    }
                    if ($pool->segmentOutboundBytes($curSeg) + strlen($yuv) > HlsPipelineProtocol::HIGH_WATERMARK) { break; }
                    $dtsMs = $dropFrames
                        ? (int)round($retainedCount * 1000 / $effectiveFps)
                        : (int)$meta['dtsMs'];
                    $frameMeta[(int)$event['sequence']] = ['dtsMs' => $dtsMs];
                    $pool->submitFrame($curSeg, (int)$event['sequence'], $boundary, (int)$variant['width'], (int)$variant['height'], $yuv);
                    $retainedCount++;
                    unset($pending[$expected]); $expected++;
                }

                // 4) 全局有序发布：段结果乱序到达先入 readyFrames，与直通事件一起严格按
                //    全局源顺序、用连续的输出序号发往输出进程（音频不得越过未完成的段帧）
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
                        $outOut .= HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $outSeq, [
                            'sampleType' => 'video',
                            'dtsMs' => $frameMeta[$fseq]['dtsMs'],
                            'ctsMs' => 0,
                            'keyframe' => $readyFrames[$fseq]['key'],
                            'forcedIdr' => $readyFrames[$fseq]['key'],
                            'gopEncoded' => ['profiles' => ['default' => $readyFrames[$fseq]['nals']]],
                        ], '');
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
                    break;
                }

                // 5) 收尾
                if (!$decEndSent && ($allEnqueued || $stopReading)) {
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
            echo "Done! Output: {$outputFile}\nOutput size: " . filesize($outputFile) . " bytes\n";
        } catch (Throwable $e) {
            if ($pool !== null) $pool->closeSockets();
            foreach ($decoderSockets as $socket) if (is_resource($socket)) @fclose($socket);
            if (is_resource($outputSocket)) @fclose($outputSocket);
            $this->terminateWorkers();
            throw $e;
        }
    }

    private function bufferedBytes(array $buffers): int
    {
        $total = 0;
        foreach ($buffers as $buffer) $total += strlen($buffer);
        return $total;
    }

    private function startWorker(array $arguments): void
    {
        $options = ['bypass_shell' => true]; if (PHP_OS_FAMILY === 'Windows') $options['create_process_group'] = true;
        $nul = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'; $pipes = [];
        $process = proc_open(array_merge([PHP_BINARY], $arguments), [['file', $nul, 'r'], ['file', $nul, 'a'], ['file', $nul, 'a']], $pipes, dirname(__DIR__, 2), null, $options);
        if (!is_resource($process)) throw new RuntimeException('无法启动 MP4 recode worker');
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
            if ($error === null && $timedOut) $error = new RuntimeException('MP4 recode worker 结束超时');
            elseif ($error === null && $exit !== 0 && $exit !== -1) $error = new RuntimeException("MP4 recode worker 异常退出: {$exit}");
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
