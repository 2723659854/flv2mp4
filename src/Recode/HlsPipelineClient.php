<?php

namespace Xiaosongshu\Flv2mp4\Recode;

use Composer\Autoload\ClassLoader;
use Generator;
use ReflectionClass;
use RuntimeException;
use Throwable;

/**
 * @purpose flv转hls分布式架构-管道客户端（多解码worker按GOP并行）
 * @author yanglong
 */
final class HlsPipelineClient
{
    private array $processes = [];
    /** 每个解码worker待写入主进程缓冲区的软上限 */
    private const PER_WORKER_SOFT_LIMIT = 8388608;

    public function __construct(private array $profiles, private string $outputDir, private ?int $maxFrames, private int $decodeWorkers = 4, private bool $wavefront = false)
    {
    }

    public function process(string $flvFile): void
    {
        $workerCount = max(1, min(8, $this->decodeWorkers));
        $sourceInfo = $this->scanSource($flvFile);
        $gopCount = $sourceInfo['gopCount'];
        // 波前模式可在单个 GOP 内再切区间，不再受源 GOP 数限制
        if (!$this->wavefront && $gopCount > 0) $workerCount = max(1, min($workerCount, $gopCount));
        // 波前区间规划（源 GOP 内按帧均分，后段靠前段检查点接续）
        $wf = $this->wavefront
            ? WavefrontDispatch::begin((int)$sourceInfo['videoFrames'], $sourceInfo['gopStarts'], $workerCount)
            : null;
        // 抽帧目标帧率：各 profile fps>0 的最小值（多 profile 共享一路解码，只能按最低帧率抽一次）；
        // fps=0 表示该 profile 保持源帧率；仅当目标帧率低于源帧率时才抽帧（不升帧）
        $targetFps = 0.0;
        foreach ($this->profiles as $profile) {
            $fps = (int)($profile['fps'] ?? 0);
            if ($fps > 0 && ($targetFps <= 0 || $fps < $targetFps)) $targetFps = $fps;
        }
        $sourceFps = $sourceInfo['fps'];
        $dropFrames = $targetFps > 0 && $sourceFps !== null && $targetFps < $sourceFps - 0.01;
        $autoload = $this->locateAutoload();
        $worker = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'hls-worker.php';
        $decoderAddresses = [];
        for ($i = 0; $i < $workerCount; $i++) $decoderAddresses[] = $this->reserveAddress();
        $sockets = [];
        try {
            if (count($this->profiles) === 1) {
                [, $outputPort] = $this->reserveAddress();
                $this->startWorker([$worker, '--mode', 'output', '--autoload', $autoload, '--port', (string)$outputPort, '--workers', (string)$workerCount, '--profiles', $this->encodeOption($this->profiles), '--output', $this->outputDir]);
                $decoderDownstreamPort = $outputPort;
            } else {
                [, $scalePort] = $this->reserveAddress();
                $profilePorts = [];
                foreach ($this->profiles as $name => $profile) {
                    [, $port] = $this->reserveAddress();
                    $profilePorts[$name] = $port;
                    $singleProfile = [$name => $profile];
                    $this->startWorker([$worker, '--mode', 'output', '--autoload', $autoload, '--port', (string)$port, '--profiles', $this->encodeOption($singleProfile), '--output', $this->outputDir]);
                }
                $this->startWorker([$worker, '--mode', 'scale', '--autoload', $autoload, '--port', (string)$scalePort, '--workers', (string)$workerCount, '--output-ports', $this->encodeOption($profilePorts), '--profiles', $this->encodeOption($this->profiles), '--output', $this->outputDir]);
                $decoderDownstreamPort = $scalePort;
            }
            foreach ($decoderAddresses as [$decoderAddress, $decoderPort]) {
                $this->startWorker([$worker, '--mode', 'decoder', '--autoload', $autoload, '--port', (string)$decoderPort, '--output-port', (string)$decoderDownstreamPort, '--profiles', $this->encodeOption($this->profiles)]);
            }
            foreach ($decoderAddresses as [$decoderAddress]) {
                $socket = $this->connect($decoderAddress);
                stream_set_blocking($socket, false);
                $sockets[] = $socket;
            }

            $sequence = 0;
            $gopSeq = 0;
            $currentWorker = 0;
            $frameCount = 0;
            $videoCount = 0;
            $videoSampleIdx = 0; // 波前用：AVCC 视频帧（packetType=1）序号
            $audioSeq = 0;       // 波前用：音频 tag 轮转 worker
            // 抽帧状态：首个输出 IDR 的源时间戳基准；已保留帧计数（含首 IDR）
            $baseVideoTimestamp = -1;
            $selectedFrames = 0;
            $outbound = array_fill(0, $workerCount, '');
            $inbound = array_fill(0, $workerCount, '');
            $alive = array_fill(0, $workerCount, true);
            $tags = $this->readFlvTags($flvFile);
            $exhausted = false;
            $stopReading = false;
            $endEnqueued = false;
            $wfDeadline = null; // 源读完后等待检查点回传的看门狗
            $finishedCount = 0;
            // GOP 全量并行派发（实测整体最快；旧环境变量窗口调优开关已移除，
            // 全量扇出为默认且唯一调度方式，进程数由 decode_workers 按核数自适应收敛）

            while (true) {
                if (!$stopReading && !$exhausted) {
                    $pendingBytes = $wf !== null ? WavefrontDispatch::pendingBytes($wf) : 0;
                    while ($this->bufferedBytes($outbound) + $pendingBytes < $workerCount * self::PER_WORKER_SOFT_LIMIT) {
                        if (!$tags->valid()) { $exhausted = true; break; }
                        $tag = $tags->current(); $tags->next();
                        $frameCount++;
                        if ($tag['tagType'] === 8) {
                            $audioWorker = $wf !== null ? ($audioSeq++ % $workerCount) : $currentWorker;
                            $outbound[$audioWorker] .= HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $sequence++, ['tagType' => 8, 'timestamp' => $tag['timestamp']], $tag['body']);
                        } elseif ($tag['tagType'] === 9) {
                            $videoCount++;
                            $body = $tag['body'];
                            $packetType = strlen($body) >= 2 ? ord($body[1]) : -1;
                            if ($packetType === 0) {
                                // AVCC 序列头：worker 0 透传给输出进程，全体 worker 各自解析 SPS/PPS
                                $outbound[0] .= HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $sequence++, ['tagType' => 9, 'timestamp' => $tag['timestamp']], $body);
                                $control = HlsPipelineProtocol::frame(HlsPipelineProtocol::CONTROL, 0, ['cmd' => 'config'], $body);
                                for ($i = 0; $i < $workerCount; $i++) $outbound[$i] .= $control;
                            } elseif ($packetType !== 1) {
                                // AVC end-of-sequence（packetType=2）等非 NALU 视频 tag：透传，不计入视频帧序号（扫描也不计）
                                $outbound[0] .= HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $sequence++, ['tagType' => 9, 'timestamp' => $tag['timestamp']], $body);
                            } else {
                                $isKey = (ord($body[0]) >> 4) === 1 && $this->containsIdrNal($body);
                                if ($wf === null) {
                                    // 每个 IDR 开启一个独立 GOP，轮询分配给解码 worker
                                    if ($isKey) {
                                        $newGop = $gopSeq;
                                        if ($newGop > 0) {
                                            // 上一 GOP 所有帧之后插入边界标记：worker 处理到此处即代表该 GOP 已解码完
                                            $prevGop = $newGop - 1;
                                            $outbound[$prevGop % $workerCount] .= HlsPipelineProtocol::frame(HlsPipelineProtocol::CONTROL, 0, ['cmd' => 'gopEnd', 'gop' => $prevGop]);
                                        }
                                        $currentWorker = $newGop % $workerCount;
                                        $gopSeq++;
                                    }
                                }
                                // 抽帧选帧（保持播放时长不变：保留帧时间戳重映射到目标帧率均匀网格，
                                // 音频沿用源时间轴，两轴同源同刻度故仍同步）：
                                // IDR 强制保留（切片边界/解码器刷新点），其余帧按目标帧率时间量化，
                                // 被抽掉的帧仍送解码维持参考链，但标记 drop 不缩放不编码
                                $timestamp = (int)$tag['timestamp'];
                                $videoMeta = ['tagType' => 9, 'timestamp' => $timestamp];
                                if ($dropFrames) {
                                    if ($baseVideoTimestamp < 0) {
                                        if ($isKey) {
                                            $baseVideoTimestamp = $timestamp;
                                            $videoMeta['outTimestamp'] = $timestamp;
                                            $selectedFrames = 1;
                                        } else {
                                            $videoMeta['drop'] = true;
                                        }
                                    } elseif (!$isKey
                                        && ($timestamp - $baseVideoTimestamp) * $targetFps < $selectedFrames * 1000) {
                                        $videoMeta['drop'] = true;
                                    } else {
                                        // 第 $selectedFrames 个保留帧（首 IDR 为 0）-> 均匀网格时间戳
                                        $videoMeta['outTimestamp'] = $baseVideoTimestamp
                                            + (int)round($selectedFrames * 1000 / $targetFps);
                                        $selectedFrames++;
                                    }
                                }
                                $wire = null;
                                if ($wf !== null) {
                                    // 波前：按帧区间路由；后段区间帧缓冲至前段检查点到达
                                    $route = WavefrontDispatch::routeVideo($wf, $videoSampleIdx, $videoMeta);
                                    $wire = HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $sequence++, $videoMeta, $body);
                                    if ($route['hold']) $wf['ranges'][$route['rid']]['buffer'] .= $wire;
                                    else $outbound[$route['w']] .= $wire;
                                    $pendingBytes = WavefrontDispatch::pendingBytes($wf);
                                } else {
                                    $outbound[$currentWorker] .= HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $sequence++, $videoMeta, $body);
                                }
                                $videoSampleIdx++;
                                if ($this->maxFrames !== null && $videoCount >= $this->maxFrames) {
                                    echo "Reached max frames limit ({$this->maxFrames}), stopping...\n";
                                    $stopReading = true;
                                    break;
                                }
                            }
                        }
                        if ($frameCount % 50 === 0) echo "Processed {$frameCount} frames ({$videoCount} video)\n";
                    }
                }
                // END 在本轮 inbound（含末段检查点回传）处理后再入队，见循环后部

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
                        // PROGRESS（GOP 解码边界回报）主进程无需跟踪
                    }
                    if (strlen($inbound[$id]) > HlsPipelineProtocol::MAX_BUFFER_LENGTH) throw new RuntimeException('主进程响应缓冲超限');
                }
                // 源 tag 已读完且本轮 inbound 处理完毕。
                // 自然读完：必须等全部检查点回传（源读取远快于解码，看门狗按"无检查点进展"计时，
                // 每收到一个检查点即重置，60s 无进展才判失败）；maxFrames 截断直接结束；worker 崩溃由 socket EOF 覆盖
                if (!$endEnqueued && ($exhausted || $stopReading)) {
                    $canEnd = $stopReading || $wf === null || WavefrontDispatch::allReleased($wf);
                    if (!$canEnd) {
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
            echo "Done! Processed {$frameCount} frames ({$videoCount} video)\n";
        } catch (Throwable $e) {
            foreach ($sockets as $socket) if (is_resource($socket)) @fclose($socket);
            $this->terminateWorkers();
            throw $e;
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

    /**
     * 预扫描（单次遍历）：统计源帧率、IDR/GOP 数量与 IDR 视频帧序号。
     * IDR 数即可并行 GOP 数（用于收敛 worker 数量避免空转）；帧率用于抽帧判定；
     * gopStarts/videoFrames 供波前区间规划。
     * @return array{fps: ?float, gopCount: int, gopStarts: int[], videoFrames: int}
     */
    private function scanSource(string $flvFile): array
    {
        $first = null; $last = null; $count = 0; $gopCount = 0;
        $gopStarts = [];
        foreach ($this->readFlvTags($flvFile) as $tag) {
            if ($tag['tagType'] !== 9) continue;
            $body = $tag['body'];
            if (strlen($body) < 2 || ord($body[1]) !== 1) continue;
            $first ??= $tag['timestamp']; $last = $tag['timestamp'];
            if ($this->containsIdrNal($body)) { $gopCount++; $gopStarts[] = $count; }
            $count++;
        }
        $fps = $count >= 2 && $last > $first ? ($count - 1) * 1000 / ($last - $first) : null;
        return ['fps' => $fps, 'gopCount' => $gopCount, 'gopStarts' => $gopStarts, 'videoFrames' => $count];
    }

    private function bufferedBytes(array $buffers): int
    {
        $total = 0;
        foreach ($buffers as $buffer) $total += strlen($buffer);
        return $total;
    }

    private function readFlvTags(string $flvFile): Generator
    {
        $handle = @fopen($flvFile, 'rb');
        if ($handle === false) throw new RuntimeException("无法打开 FLV 文件: {$flvFile}");
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
                $tagType = ord($tagHeader[0]);
                $dataSize = unpack('N', "\0" . substr($tagHeader, 1, 3))[1];
                if ($dataSize > HlsPipelineProtocol::MAX_FRAME_LENGTH) throw new RuntimeException("FLV Tag 数据过大: {$dataSize}");
                $timestamp = unpack('N', $tagHeader[7] . substr($tagHeader, 4, 3))[1];
                $body = $this->readExact($handle, $dataSize);
                $this->readExact($handle, 4);
                yield ['tagType' => $tagType, 'timestamp' => $timestamp, 'body' => $body];
            }
        } finally { fclose($handle); }
    }

    private function readExact($handle, int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = fread($handle, $length - strlen($data));
            if ($chunk === false || $chunk === '') throw new RuntimeException('FLV 文件数据不完整');
            $data .= $chunk;
        }
        return $data;
    }

    private function startWorker(array $arguments): void
    {
        $options = ['bypass_shell' => true];
        if (PHP_OS_FAMILY === 'Windows') $options['create_process_group'] = true;
        $pipes = [];
        $process = proc_open(array_merge([PHP_BINARY], $arguments), [fopen('php://stdin', 'r'), fopen('php://stdout', 'a'), fopen('php://stderr', 'a')], $pipes, dirname(__DIR__, 2), null, $options);
        if (!is_resource($process)) throw new RuntimeException('无法启动 HLS worker');
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
            if ($error === null && $timedOut) $error = new RuntimeException('HLS worker 结束超时');
            elseif ($error === null && $exit !== 0 && $exit !== -1) $error = new RuntimeException("HLS worker 异常退出: {$exit}");
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
        $name = stream_socket_get_name($server, false); fclose($server);
        $port = (int)substr(strrchr($name, ':'), 1);
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

    private function encodeOption(array $value): string
    {
        return base64_encode(json_encode($value, JSON_THROW_ON_ERROR));
    }
}
