<?php

namespace Xiaosongshu\Flv2mp4\Recode;

use Composer\Autoload\ClassLoader;
use ReflectionClass;
use RuntimeException;
use Throwable;

/**
 * @purpose mp4重编码分布式架构-管道
 * @author yanglong
 */
final class Mp4PipelineClient
{
    private array $processes = [];

    public function __construct(private array $config, private ?int $maxFrames)
    {
    }

    public function process(string $inputFile, string $outputFile): void
    {
        [$decoderAddress, $decoderPort] = $this->reserveAddress();
        [, $outputPort] = $this->reserveAddress();
        $autoload = $this->locateAutoload();
        $worker = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'mp4-recode-worker.php';
        [$streamMetadata, $samples] = (new Mp4Recoder($this->config, false))->preparePipelineInput($inputFile);
        $config = $this->config;
        $config['pipeline'] = $streamMetadata;
        $encodedConfig = base64_encode(json_encode($config, JSON_THROW_ON_ERROR));
        try {
            $this->startWorker([$worker, '--mode', 'output', '--autoload', $autoload, '--port', (string)$outputPort, '--config', $encodedConfig, '--output', $outputFile]);
            $this->startWorker([$worker, '--mode', 'decoder', '--autoload', $autoload, '--port', (string)$decoderPort, '--output-port', (string)$outputPort, '--config', $encodedConfig]);
            $socket = $this->connect($decoderAddress);
            stream_set_blocking($socket, false);
            $sequence = 0; $videoCount = 0; $buffer = ''; $response = ''; $finished = false;
            foreach ($samples as $sample) {
                if ($sample['type'] === 'video') $videoCount++;
                if ($this->maxFrames !== null && $sample['type'] === 'audio' && $videoCount >= $this->maxFrames) continue;
                $buffer .= HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $sequence++, [
                    'sampleType' => $sample['type'], 'dtsMs' => $sample['dtsMs'],
                    'ctsMs' => $sample['ctsMs'], 'keyframe' => $sample['keyframe'],
                ], $sample['data']);
                $this->drainUntilLow($socket, $buffer, $response);
                if ($this->maxFrames !== null && $videoCount >= $this->maxFrames) break;
                if ($videoCount > 0 && $videoCount % 10 === 0 && $sample['type'] === 'video') echo "Processed {$videoCount} video frames\n";
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
            $this->waitWorkers();
            echo "Done! Output: {$outputFile}\nOutput size: " . filesize($outputFile) . " bytes\n";
        } catch (Throwable $e) {
            if (isset($socket) && is_resource($socket)) @fclose($socket);
            $this->terminateWorkers();
            throw $e;
        }
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
        if ($chunk === false || ($chunk === '' && feof($socket))) throw new RuntimeException('解码进程响应连接意外关闭');
        $buffer .= $chunk;
        foreach (HlsPipelineProtocol::take($buffer, 4) as $event) {
            if ($event['type'] === HlsPipelineProtocol::ERROR) throw new RuntimeException($event['metadata']['message'] ?? 'MP4 流水线失败');
            if ($event['type'] === HlsPipelineProtocol::FINISHED) $finished = true;
        }
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
            $deadline = microtime(true) + 15;
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
