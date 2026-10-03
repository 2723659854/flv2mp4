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
        $autoload = $this->locateAutoload();
        $worker = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'mp4-recode-worker.php';
        [$streamMetadata, $samples] = (new Mp4Recoder($this->config, false))->preparePipelineInput($inputFile);
        // GOP 数决定可用的解码并行度，先统计再自适应规划（GOP 少时收缩解码 worker、加码帧内运动并行）
        $gopCount = 0;
        foreach ($samples as $sample) if ($sample['type'] === 'video' && !empty($sample['keyframe'])) $gopCount++;
        [$workerCount, $motionPerWorker] = $this->planWorkers($gopCount);
        echo "并行规划: GOP={$gopCount}, 解码worker={$workerCount}, 每worker运动进程={$motionPerWorker}（运动进程共" . ($workerCount * $motionPerWorker) . "）\n";
        [, $outputPort] = $this->reserveAddress();
        $decoderAddresses = [];
        for ($i = 0; $i < $workerCount; $i++) $decoderAddresses[] = $this->reserveAddress();
        $config = $this->config;
        $config['pipeline'] = $streamMetadata;
        // 下发给 worker 的是"每 worker 运动进程数"（自适应结果）
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

            $dropFrames = !empty($streamMetadata['dropFrames']);
            $targetFps = isset($streamMetadata['effectiveTargetFps']) ? (float)$streamMetadata['effectiveTargetFps'] : 0.0;

            $sequence = 0;
            $gopSeq = 0;
            $currentWorker = 0;
            $baseTimestamp = -1;
            $selected = 0;
            $videoCount = 0;
            $index = 0;
            $total = count($samples);
            $outbound = array_fill(0, $workerCount, '');
            $inbound = array_fill(0, $workerCount, '');
            $alive = array_fill(0, $workerCount, true);
            $allEnqueued = false;
            $endEnqueued = false;
            $finishedCount = 0;

            while (true) {
                if (!$allEnqueued) {
                    while ($index < $total && $this->bufferedBytes($outbound) < $workerCount * self::PER_WORKER_SOFT_LIMIT) {
                        $sample = $samples[$index++];
                        if ($sample['type'] === 'video') {
                            $videoCount++;
                            if (!empty($sample['keyframe'])) {
                                // 每个关键帧开启一个独立GOP，轮询分配给解码worker
                                if ($gopSeq > 0) {
                                    // GOP 边界标记：worker 完成上一 GOP 后重置解码参考链与编码器状态
                                    $prevWorker = ($gopSeq - 1) % $workerCount;
                                    $outbound[$prevWorker] .= HlsPipelineProtocol::frame(HlsPipelineProtocol::CONTROL, 0, ['cmd' => 'gopEnd', 'gop' => $gopSeq - 1]);
                                }
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
                            $outbound[$currentWorker] .= HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $sequence++, $meta, $sample['data']);
                            if ($this->maxFrames !== null && $videoCount >= $this->maxFrames) { echo "Reached max frames limit ({$this->maxFrames}), stopping...\n"; $allEnqueued = true; break; }
                            if ($videoCount % 10 === 0) echo "Processed {$videoCount} video frames\n";
                        } else {
                            if ($this->maxFrames !== null && $videoCount >= $this->maxFrames) continue;
                            $outbound[$currentWorker] .= HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $sequence++, [
                                'sampleType' => 'audio', 'dtsMs' => $sample['dtsMs'],
                                'ctsMs' => $sample['ctsMs'], 'keyframe' => $sample['keyframe'],
                            ], $sample['data']);
                        }
                    }
                    if ($index >= $total) $allEnqueued = true;
                    if ($allEnqueued && !$endEnqueued) {
                        // END 广播给每个解码 worker，输出 worker 收齐 workerCount 个 END 才收尾
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
                        if ($event['type'] === HlsPipelineProtocol::ERROR) throw new RuntimeException($event['metadata']['message'] ?? 'MP4 流水线失败');
                        if ($event['type'] === HlsPipelineProtocol::FINISHED) $finishedCount++;
                    }
                    if (strlen($inbound[$id]) > HlsPipelineProtocol::MAX_BUFFER_LENGTH) throw new RuntimeException('主进程响应缓冲超限');
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
     * 按 GOP 数量自适应规划进程布局，返回 [解码worker数, 每worker运动估计进程数]
     *
     * 实测（640x360/fastMotion，PHP 纯实现）：每 worker 的运动进程 M=2 为甜点——
     * 帧内条带并行超过 2 路后，运动计算已不是瓶颈，多出的子进程只增加冷启动/调度开销
     * （90帧片段 2x2=10.4s vs 2x4=12.4s；387帧片段 4x2=22.2s vs 4x4=25.4s）。
     * 因此运动预算 motion_budget 全部用于扩张解码 worker（GOP 并行）：
     *   M = min(2, 配置motionWorkers)
     *   D = min(decode_workers上限, GOP数, floor(budget / M))
     * 例（budget=8）：GOP=1 → 1×2；GOP=2 → 2×2；GOP≥4 → 4×2。
     *
     * @return array{0:int,1:int}
     */
    private function planWorkers(int $gopCount): array
    {
        $decodeMax = max(1, min(8, (int)($this->config['decode_workers'] ?? 4)));
        $configuredMotion = max(1, (int)($this->config['motionWorkers'] ?? 2));
        if ($gopCount <= 0) {
            return [$decodeMax, $configuredMotion];
        }
        $budget = max(1, (int)($this->config['motion_budget'] ?? 8));
        $m = min(2, $configuredMotion);
        $d = min($decodeMax, $gopCount, max(1, intdiv($budget, $m)));
        return [$d, $m];
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
            do { $status = proc_get_status($process); if (!$status['running']) break; usleep(1); } while (microtime(true) < $deadline);
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
