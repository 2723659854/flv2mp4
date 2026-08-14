<?php

namespace Xiaosongshu\Flv2mp4\Recode;

use Composer\Autoload\ClassLoader;
use ReflectionClass;
use RuntimeException;
use Throwable;

final class HlsPipelineClient
{
    private array $processes = [];
    private array $pipes = [];

    public function __construct(private array $profiles, private string $outputDir, private ?int $maxFrames)
    {
    }

    public function process(string $flvFile): void
    {
        [$decoderAddress, $decoderPort] = $this->reserveAddress();
        [$outputAddress, $outputPort] = $this->reserveAddress();
        $autoload = $this->locateAutoload();
        $worker = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'hls-worker.php';
        try {
            $this->startWorker([$worker, '--mode', 'output', '--autoload', $autoload, '--port', (string)$outputPort, '--profiles', base64_encode(json_encode($this->profiles, JSON_THROW_ON_ERROR)), '--output', $this->outputDir]);
            $this->startWorker([$worker, '--mode', 'decoder', '--autoload', $autoload, '--port', (string)$decoderPort, '--output-port', (string)$outputPort, '--profiles', base64_encode(json_encode($this->profiles, JSON_THROW_ON_ERROR))]);
            $socket = $this->connect($decoderAddress);
            stream_set_blocking($socket, false);
            $sequence = 0; $frameCount = 0; $videoCount = 0; $buffer = ''; $response = ''; $finished = false;
            foreach ($this->readFlvTags($flvFile) as $tag) {
                if ($tag['tagType'] === 8 || $tag['tagType'] === 9) {
                    $buffer .= HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $sequence++, [
                        'tagType' => $tag['tagType'],
                        'timestamp' => $tag['timestamp'],
                    ], $tag['body']);
                    if ($tag['tagType'] === 9) $videoCount++;
                    $this->drainUntilLow($socket, $buffer, $response);
                }
                $frameCount++;
                if ($frameCount % 10 === 0) echo "Processed {$frameCount} frames ({$videoCount} video)\n";
                if ($this->maxFrames !== null && $videoCount >= $this->maxFrames) { echo "Reached max frames limit ({$this->maxFrames}), stopping...\n"; break; }
            }
            $buffer .= HlsPipelineProtocol::frame(HlsPipelineProtocol::END, $sequence++);
            while (!$finished) {
                $read = [$socket]; $write = $buffer === '' ? [] : [$socket]; $except = null;
                if (@stream_select($read, $write, $except, 1) === false) continue;
                if ($write !== []) $this->writeSome($socket, $buffer);
                if ($read !== []) $this->readResponses($socket, $response, $finished);
                if ($buffer === '' && feof($socket) && !$finished) throw new RuntimeException('解码进程未返回 FINISHED');
            }
            fclose($socket);
            echo "Done! Processed {$frameCount} frames\n";
            $this->waitWorkers();
        } catch (Throwable $e) {
            if (isset($socket) && is_resource($socket)) @fclose($socket);
            $this->terminateWorkers();
            throw $e;
        }
    }

    private function readFlvTags(string $flvFile): \Generator
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
        } finally {
            fclose($handle);
        }
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

    private function drainUntilLow($socket, string &$buffer, string &$response): void
    {
        while (strlen($buffer) >= HlsPipelineProtocol::HIGH_WATERMARK) {
            $read = [$socket]; $write = [$socket]; $except = null;
            if (@stream_select($read, $write, $except, 1) === false) continue;
            if ($write !== []) $this->writeSome($socket, $buffer);
            $ignored = false; if ($read !== []) $this->readResponses($socket, $response, $ignored);
        }
        if ($buffer !== '') $this->writeSome($socket, $buffer);
        if (strlen($buffer) > HlsPipelineProtocol::MAX_BUFFER_LENGTH) throw new RuntimeException('主进程发送缓冲超限');
    }

    private function writeSome($socket, string &$buffer): void
    {
        $n = @fwrite($socket, substr($buffer, 0, 65536));
        if ($n === false || ($n === 0 && feof($socket))) throw new RuntimeException('解码进程媒体连接意外关闭');
        if ($n > 0) $buffer = substr($buffer, $n);
    }

    private function readResponses($socket, string &$buffer, bool &$finished): void
    {
        $chunk = @fread($socket, 65536);
        if ($chunk === false || ($chunk === '' && feof($socket))) {
            throw new RuntimeException('解码进程响应连接意外关闭');
        }
        $buffer .= $chunk;
        foreach (HlsPipelineProtocol::take($buffer, 4) as $event) {
            if ($event['type'] === HlsPipelineProtocol::ERROR) throw new RuntimeException($event['metadata']['message'] ?? '流水线失败');
            if ($event['type'] === HlsPipelineProtocol::FINISHED) $finished = true;
        }
    }

    private function startWorker(array $arguments): void
    {
        $command = array_merge([PHP_BINARY], $arguments);
        $options = ['bypass_shell' => true];
        if (PHP_OS_FAMILY === 'Windows') $options['create_process_group'] = true;
        $nul = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $pipes = [];
        $process = proc_open($command, [['file', $nul, 'r'], ['file', $nul, 'a'], ['file', $nul, 'a']], $pipes, dirname(__DIR__, 2), null, $options);
        if (!is_resource($process)) throw new RuntimeException('无法启动 HLS worker');
        $this->processes[] = $process; $this->pipes[] = $pipes;
    }

    private function waitWorkers(): void
    {
        $error = null;
        foreach ($this->processes as $key => $process) {
            if (!is_resource($process)) { unset($this->processes[$key]); continue; }
            $deadline = microtime(true) + 15;
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
}
