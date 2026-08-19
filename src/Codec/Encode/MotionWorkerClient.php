<?php
namespace Xiaosongshu\Flv2mp4\Codec\Encode;

use RuntimeException;

final class MotionWorkerClient
{
    private array $sockets = [];
    private array $inputs = [];
    private array $outputs = [];
    private array $processes = [];
    private array $references = [];
    private array $workerPorts = [];
    private int $id = 1;

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
        $frameId = MotionWorkerProtocol::referenceId($width, $height, $aw, $ah, $refY, $refU, $refV);
        $chunks = array_fill(0, $this->workers, []);
        foreach ($blocks as $key => $block) $chunks[$key % $this->workers][$key] = $block;
        $ids = [];
        foreach ($chunks as $worker => $chunk) {
            if ($chunk === []) continue;
            $id = $this->id++;
            $ids[$worker] = $id;
            $frameKey = bin2hex($frameId);
            if (($this->references[$worker] ?? null) !== $frameKey) {
                $this->outputs[$worker] .= MotionWorkerProtocol::loadReference($frameId, $width, $height, $aw, $ah, $refY, $refU, $refV);
                $this->references[$worker] = $frameKey;
            }
            $this->outputs[$worker] .= MotionWorkerProtocol::batch($id, $frameId, $qp, $chunk);
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
            $seconds = (int)$remaining;
            $microseconds = (int)(($remaining - $seconds) * 1000000);
            $ready = @stream_select($read, $write, $except, $seconds, $microseconds);
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

    private function connectAll(): void
    {
        for ($worker = 0; $worker < $this->workers; $worker++) {
            if (isset($this->sockets[$worker]) && is_resource($this->sockets[$worker])) continue;
            $lock = null;
            if (!isset($this->workerPorts[$worker])) {
                $lock = fopen(sys_get_temp_dir() . '/flv2mp4-motion-worker-port.lock', 'c');
                if ($lock === false || !flock($lock, LOCK_EX)) throw new RuntimeException('Unable to lock motion worker port allocation');
                $this->workerPorts[$worker] = $this->allocatePort();
            }
            $port = $this->workerPorts[$worker];
            $socket = $this->port === 0 ? false : @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $error, 0.1);
            if ($socket === false) {
                $entry = dirname(__DIR__, 3) . '/bin/motion-worker.php';
                $autoload = dirname((new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName(), 2) . '/autoload.php';
                $descriptors = [fopen('php://stdin', 'r'), fopen('php://stdout', 'a'), fopen('php://stderr', 'a')];
                $process = @proc_open([PHP_BINARY, $entry, '--owned', "--port={$port}", "--autoload={$autoload}"], $descriptors, $pipes, null, null, ['bypass_shell' => true]);
                if (!is_resource($process)) throw new RuntimeException('Unable to start motion worker');
                $this->processes[] = $process;
                $end = microtime(true) + 2;
                do {
                    usleep(20000);
                    $socket = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $error, 0.1);
                } while ($socket === false && microtime(true) < $end);
            }
            if ($socket === false) {
                if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
                throw new RuntimeException("Unable to connect motion worker {$port}: {$error} ({$errno})");
            }
            if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
            stream_set_blocking($socket, false);
            $this->sockets[$worker] = $socket;
            $this->inputs[$worker] = '';
            $this->outputs[$worker] = '';
            unset($this->references[$worker]);
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
