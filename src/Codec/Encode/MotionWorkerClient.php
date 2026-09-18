<?php
namespace Xiaosongshu\Flv2mp4\Codec\Encode;

use RuntimeException;

/**
 * @purpose 运动模块分布式计算-客户端
 * @author yanglong
 */
final class MotionWorkerClient
{
    private array $sockets = [];
    private array $inputs = [];
    private array $outputs = [];
    private array $processes = [];
    private array $lastReference = [];
    private array $workerSeq = [];
    private array $workerPorts = [];
    private int $id = 1;
    private int $refSeq = 0;

    public function __construct(private int $port = 0, private int $workers = 0)
    {
        $this->workers = $workers > 0 ? $workers : max(1, min(4, (int)(getenv('NUMBER_OF_PROCESSORS') ?: 2)));
        if ($this->port !== 0) {
            for ($worker = 0; $worker < $this->workers; $worker++) $this->workerPorts[$worker] = $this->port + $worker;
        }
    }

    public function batch(int $width, int $height, int $aw, int $ah, int $qp, string $refY, string $refU, string $refV, array $blocks): array
    {
        $this->connectAll();
        // 参考帧按内容分配单调序号：内容不变（如纯静态画面）则复用同一序号，
        // worker 端已持有该参考帧时跳过整帧重传；字符串 === 先比长度再 memcmp，
        // 不同内容通常首字节即返回，比每帧 SHA256 便宜且无碰撞风险。
        if ($this->lastReference !== []
            && $this->lastReference[0] === $refY
            && $this->lastReference[1] === $refU
            && $this->lastReference[2] === $refV) {
            $seq = $this->refSeq;
        } else {
            $seq = ++$this->refSeq;
            $this->lastReference = [$refY, $refU, $refV];
        }
        $chunks = array_fill(0, $this->workers, []);
        foreach ($blocks as $key => $block) $chunks[$key % $this->workers][$key] = $block;
        $ids = [];
        $referenceFrame = null;
        foreach ($chunks as $worker => $chunk) {
            if ($chunk === []) continue;
            $id = $this->id++;
            $ids[$worker] = $id;
            if (($this->workerSeq[$worker] ?? null) !== $seq) {
                $referenceFrame ??= MotionWorkerProtocol::loadReference($seq, $width, $height, $aw, $ah, $refY, $refU, $refV);
                $this->outputs[$worker] .= $referenceFrame;
                $this->workerSeq[$worker] = $seq;
            }
            $this->outputs[$worker] .= MotionWorkerProtocol::batch($id, $seq, $qp, $chunk);
        }

        $result = [];
        $deadline = microtime(true) + 30;
        while ($ids !== []) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) throw new RuntimeException('Timed out motion worker batch');
            $read = [];
            $write = [];
            foreach (array_keys($ids) as $worker) {
                $read[] = $this->sockets[$worker];
                if ($this->outputs[$worker] !== '') $write[] = $this->sockets[$worker];
            }
            $except = null;
            $ready = @stream_select($read, $write, $except, 0, 1);
            if ($ready === false) throw new RuntimeException('Failed waiting for motion worker');
            foreach ($write as $socket) $this->writeSocket($this->workerFor($socket));
            foreach ($read as $socket) {
                $worker = $this->workerFor($socket);
                $this->readSocket($worker);
                foreach (MotionWorkerProtocol::takeFrames($this->inputs[$worker], 16) as $body) {
                    [$responseId, $ok, $part] = MotionWorkerProtocol::decodeResponse($body);
                    if ($responseId !== $ids[$worker]) throw new RuntimeException("Unexpected motion worker response {$responseId}");
                    if (!$ok) throw new RuntimeException('Motion worker failed: ' . $part);
                    foreach ($part as $key => $value) $result[$key] = $value;
                    unset($ids[$worker]);
                }
            }
        }
        if (count($result) !== count($blocks)) throw new RuntimeException('Incomplete motion worker batch');
        ksort($result);
        return $result;
    }

    private function allocatePort(): int
    {
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($server === false) throw new RuntimeException("Unable to reserve motion worker port: {$error} ({$errno})");
        $name = stream_socket_get_name($server, false);
        fclose($server);
        return (int)substr(strrchr($name, ':'), 1);
    }

    public function connectAll(): void
    {
        // 第一阶段：一次性拉起全部子进程（PHP 冷启动并发进行，避免逐个等待）
        $pending = [];
        $entry = dirname(__DIR__, 3) . '/bin/motion-worker.php';
        $autoload = dirname((new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName(), 2) . '/autoload.php';
        $descriptors = [fopen('php://stdin', 'r'), fopen('php://stdout', 'a'), fopen('php://stderr', 'a')];
        for ($worker = 0; $worker < $this->workers; $worker++) {
            if (isset($this->sockets[$worker]) && is_resource($this->sockets[$worker])) continue;
            $lock = null;
            if (!isset($this->workerPorts[$worker])) {
                $lock = fopen(sys_get_temp_dir() . '/flv2mp4-motion-worker-port.lock', 'c');
                if ($lock === false || !flock($lock, LOCK_EX)) throw new RuntimeException('Unable to lock motion worker port allocation');
                $this->workerPorts[$worker] = $this->allocatePort();
                flock($lock, LOCK_UN);
                fclose($lock);
            }
            $port = $this->workerPorts[$worker];
            $socket = $this->port === 0 ? false : @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $error, 0.1);
            if ($socket === false) {
                // 多进程转码时多个 PHP 冷启动并发，2 秒窗口会偶发连接超时；放宽到 15 秒
                $process = @proc_open([PHP_BINARY, $entry, '--owned', "--port={$port}", "--autoload={$autoload}"], $descriptors, $pipes, null, null, ['bypass_shell' => true]);
                if (!is_resource($process)) throw new RuntimeException('Unable to start motion worker');
                $this->processes[] = $process;
                $pending[$worker] = $port;
            } else {
                stream_set_blocking($socket, false);
                $this->sockets[$worker] = $socket;
                $this->inputs[$worker] = '';
                $this->outputs[$worker] = '';
                unset($this->workerSeq[$worker]);
            }
        }

        // 第二阶段：并发轮询，等待所有子进程监听就绪
        $deadline = microtime(true) + 15;
        while ($pending !== []) {
            if (microtime(true) >= $deadline) throw new RuntimeException('Unable to connect motion workers (timeout): ' . implode(',', $pending));
            foreach ($pending as $worker => $port) {
                $socket = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $error, 0.1);
                if ($socket === false) continue;
                stream_set_blocking($socket, false);
                $this->sockets[$worker] = $socket;
                $this->inputs[$worker] = '';
                $this->outputs[$worker] = '';
                unset($this->workerSeq[$worker], $pending[$worker]);
            }
            if ($pending !== []) usleep(50000);
        }
    }

    private function workerFor($socket): int
    {
        foreach ($this->sockets as $worker => $candidate) if ($candidate === $socket) return $worker;
        throw new RuntimeException('Unknown motion worker socket');
    }

    private function writeSocket(int $worker): void
    {
        $written = @fwrite($this->sockets[$worker], $this->outputs[$worker]);
        if ($written === false || ($written === 0 && feof($this->sockets[$worker]))) throw new RuntimeException('Failed writing motion worker');
        if ($written > 0) $this->outputs[$worker] = substr($this->outputs[$worker], $written);
    }

    private function readSocket(int $worker): void
    {
        $data = @fread($this->sockets[$worker], 65536);
        if ($data === false || ($data === '' && feof($this->sockets[$worker]))) throw new RuntimeException('Motion worker closed');
        $this->inputs[$worker] .= $data;
    }

    public function __destruct()
    {
        foreach ($this->sockets as $socket) if (is_resource($socket)) @fclose($socket);
        foreach ($this->processes as $process) {
            if (!is_resource($process)) continue;
            if ((proc_get_status($process)['running'] ?? false)) @proc_terminate($process);
            @proc_close($process);
        }
    }
}
