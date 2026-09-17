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
        $workerCount = max(1, min(8, (int)($this->config['decode_workers'] ?? 4)));
        // FLV 只能在关键帧边界切分并行，worker 超过 GOP 数纯属空转（白白承担进程启动与等待开销）
        if ($sourceInfo['gopCount'] > 0) $workerCount = max(1, min($workerCount, $sourceInfo['gopCount']));
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
            $outbound = array_fill(0, $workerCount, '');
            $inbound = array_fill(0, $workerCount, '');
            $alive = array_fill(0, $workerCount, true);
            $tags = $this->readFlvTags($flvFile);
            $exhausted = false;
            $stopReading = false;
            $endEnqueued = false;
            $finishedCount = 0;

            while (true) {
                if (!$stopReading) {
                    while (!$exhausted && $this->bufferedBytes($outbound) < $workerCount * self::PER_WORKER_SOFT_LIMIT) {
                        if (!$tags->valid()) { $exhausted = true; break; }
                        $tag = $tags->current(); $tags->next();
                        $frameCount++;
                        if ($tag['tagType'] === 8) {
                            $outbound[$currentWorker] .= HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $sequence++, [
                                'tagType' => $tag['tagType'], 'timestamp' => $tag['timestamp'], 'sourceFps' => $sourceFps,
                            ], $tag['body']);
                        } elseif ($tag['tagType'] === 9) {
                            $videoCount++;
                            $this->dispatchVideoTag($tag, $sequence, $workerCount, $gopSeq, $currentWorker, $configured, $baseTimestamp, $selected, $targetFps, $dropFrames, $sourceFps, $outbound);
                            if ($this->maxFrames !== null && $videoCount >= $this->maxFrames) { echo "Reached max frames limit ({$this->maxFrames}), stopping...\n"; $stopReading = true; break; }
                        }
                        if ($frameCount % 50 === 0) echo "Processed {$frameCount} frames ({$videoCount} video)\n";
                    }
                    if (($exhausted || $stopReading) && !$endEnqueued) {
                        $outbound[0] .= HlsPipelineProtocol::frame(HlsPipelineProtocol::END, $sequence++);
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
                        if ($event['type'] === HlsPipelineProtocol::FINISHED) $finishedCount++;
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
        $outbound[$currentWorker] .= HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $sequence++, $meta, $body);
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
     * 预扫描（复用同一次全文件遍历）：统计源帧率与 IDR/GOP 数量。
     * @return array{fps: ?float, gopCount: int}
     */
    private function scanSource(string $file): array
    {
        $first = null; $last = null; $count = 0; $gopCount = 0;
        foreach ($this->readFlvTags($file) as $tag) {
            if ($tag['tagType'] !== 9 || strlen($tag['body']) < 2 || ord($tag['body'][1]) !== 1) continue;
            $first ??= $tag['timestamp']; $last = $tag['timestamp']; $count++;
            if ((ord($tag['body'][0]) >> 4) === 1 && $this->containsIdrNal($tag['body'])) $gopCount++;
        }
        $fps = $count >= 2 && $last > $first ? ($count - 1) * 1000 / ($last - $first) : null;
        return ['fps' => $fps, 'gopCount' => $gopCount];
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
