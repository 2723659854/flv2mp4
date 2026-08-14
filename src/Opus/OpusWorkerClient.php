<?php

namespace Xiaosongshu\Flv2mp4\Opus;

use RuntimeException;
use UnexpectedValueException;

/**
 * @purpose opus子进程客户端
 * @author yanglong
 * @time 2026年8月12日17:24:31
 */
final class OpusWorkerClient
{
    private const MAX_OUTPUT_BYTES = 262144;
    private const MAX_INPUT_BYTES = 1048576;
    private const MAX_PENDING_PACKETS = 100;

    private static array $processes = [];
    private static bool $shutdownRegistered = false;
    private $socket = null;
    private string $input = '';
    private string $output = '';
    private int $pendingPackets = 0;
    private int $nextRequestId = 1;
    // #region debug-point opus-client-pump-counter
    private int $debugPumpCalls = 0;
    // #endregion
    private bool $finishSent = false;
    private bool $finished = false;

    public function __construct(private readonly int $port = 8330)
    {
        if ($port < 1 || $port > 65535) {
            throw new RuntimeException('Opus worker port must be between 1 and 65535');
        }
    }

    public function connect(string $streamId, int $bitrate = 64000, int $channels = 1): void
    {
        if ($this->socket !== null) {
            throw new RuntimeException('Opus worker client is already connected');
        }
        $socket = $this->openSocket(0.1);
        if ($socket === false) {
            self::startWorker($this->port);
            $deadline = microtime(true) + 2.0;
            do {
                usleep(20000);
                $socket = $this->openSocket(0.1);
            } while ($socket === false && microtime(true) < $deadline);
        }
        if ($socket === false) {
            throw new RuntimeException("Unable to connect to Opus worker on 127.0.0.1:{$this->port}");
        }
        $this->socket = $socket;
        stream_set_blocking($this->socket, false);
        $this->output = OpusWorkerProtocol::open($streamId, $bitrate, $channels);
        $deadline = microtime(true) + 0.5;
        while ($this->output !== '' && microtime(true) < $deadline) {
            $this->pump();
            if ($this->output !== '') {
                usleep(1000);
            }
        }
        if ($this->output !== '') {
            $this->close();
            throw new RuntimeException('Timed out sending OPEN to Opus worker');
        }
    }

    public function push(int $sequence, int $timestamp, string $payload): int
    {
        $this->ensureUsable();
        if ($this->finishSent) {
            throw new RuntimeException('Cannot push Opus after FINISH');
        }
        $requestId = $this->nextRequestId++;
        $frame = OpusWorkerProtocol::push($requestId, $sequence, $timestamp, $payload);
        // #region debug-point opus-client-push-critical
        if ($this->pendingPackets === 90 || $this->pendingPackets === 99 || $this->pendingPackets >= self::MAX_PENDING_PACKETS || strlen($this->output) + strlen($frame) > self::MAX_OUTPUT_BYTES) { $event = json_encode(['sessionId' => 'webrtc-relay-disconnect', 'runId' => 'post-fix', 'hypothesisId' => 'H2/H4', 'location' => 'OpusWorkerClient::push', 'msg' => $this->pendingPackets >= self::MAX_PENDING_PACKETS || strlen($this->output) + strlen($frame) > self::MAX_OUTPUT_BYTES ? 'queue rejection' : 'queue critical', 'data' => ['pendingPackets' => $this->pendingPackets, 'outputBytes' => strlen($this->output), 'frameBytes' => strlen($frame), 'requestId' => $requestId], 'ts' => microtime(true)]); if ($event !== false && ($debug = @stream_socket_client('tcp://127.0.0.1:7777', $debugErrno, $debugError, 0.05))) { @stream_set_timeout($debug, 0, 50000); @fwrite($debug, "POST /event HTTP/1.1\r\nHost: 127.0.0.1:7777\r\nContent-Type: application/json\r\nContent-Length: " . strlen($event) . "\r\nConnection: close\r\n\r\n" . $event); @fclose($debug); } }
        // #endregion
        if ($this->pendingPackets >= self::MAX_PENDING_PACKETS || strlen($this->output) + strlen($frame) > self::MAX_OUTPUT_BYTES) {
            throw new RuntimeException('Opus worker input queue limit exceeded');
        }
        $this->output .= $frame;
        ++$this->pendingPackets;
        return $requestId;
    }

    public function pump(int $readBudget = 65536, int $writeBudget = 65536, int $responseBudget = 16): array
    {
        $this->ensureConnected();
        // #region debug-point opus-client-pump-snapshot
        $debugPendingBefore = $this->pendingPackets; $debugWrittenBytes = 0; $debugReadBytes = 0; ++$this->debugPumpCalls;
        // #endregion
        if ($this->output !== '' && $writeBudget > 0) {
            $written = @fwrite($this->socket, substr($this->output, 0, $writeBudget));
            if ($written === false) {
                throw new RuntimeException('Failed writing to Opus worker');
            }
            if ($written > 0) {
                $this->output = substr($this->output, $written);
                // #region debug-point opus-client-pump-written
                $debugWrittenBytes = $written;
                // #endregion
            }
        }
        $remaining = $readBudget;
        while ($remaining > 0) {
            $data = @fread($this->socket, min(65536, $remaining));
            if ($data === false) {
                throw new RuntimeException('Failed reading from Opus worker');
            }
            if ($data === '') {
                break;
            }
            $this->input .= $data;
            $remaining -= strlen($data);
            // #region debug-point opus-client-pump-read
            $debugReadBytes += strlen($data);
            // #endregion
            if (strlen($this->input) > self::MAX_INPUT_BYTES) {
                throw new RuntimeException('Opus worker output buffer limit exceeded');
            }
        }
        if (feof($this->socket) && !$this->finished) {
            throw new RuntimeException('Opus worker connection closed unexpectedly');
        }
        $responses = [];
        foreach (OpusWorkerProtocol::takeFrames($this->input, $responseBudget) as $body) {
            $responses[] = $this->decodeResponse($body);
        }
        // #region debug-point opus-client-pump-report
        if ($this->debugPumpCalls % 25 === 0 || $debugPendingBefore >= 90 || $this->pendingPackets >= 90) { $event = json_encode(['sessionId' => 'webrtc-relay-disconnect', 'runId' => 'post-fix', 'hypothesisId' => 'H2/H3', 'location' => 'OpusWorkerClient::pump', 'msg' => 'pump snapshot', 'data' => ['pumpCall' => $this->debugPumpCalls, 'writeBytes' => $debugWrittenBytes, 'readBytes' => $debugReadBytes, 'responsesParsed' => count($responses), 'pendingBefore' => $debugPendingBefore, 'pendingAfter' => $this->pendingPackets, 'inputBytes' => strlen($this->input), 'outputBytes' => strlen($this->output), 'feof' => feof($this->socket)], 'ts' => microtime(true)]); if ($event !== false && ($debug = @stream_socket_client('tcp://127.0.0.1:7777', $debugErrno, $debugError, 0.05))) { @stream_set_timeout($debug, 0, 50000); @fwrite($debug, "POST /event HTTP/1.1\r\nHost: 127.0.0.1:7777\r\nContent-Type: application/json\r\nContent-Length: " . strlen($event) . "\r\nConnection: close\r\n\r\n" . $event); @fclose($debug); } }
        // #endregion
        return $responses;
    }

    public function beginFinish(): void
    {
        $this->ensureUsable();
        if (!$this->finishSent) {
            $frame = OpusWorkerProtocol::finish();
            if (strlen($this->output) + strlen($frame) > self::MAX_OUTPUT_BYTES) {
                throw new RuntimeException('Opus worker input queue limit exceeded at FINISH');
            }
            $this->output .= $frame;
            $this->finishSent = true;
        }
    }

    public function finish(float $timeoutSeconds = 2.0): array
    {
        $this->beginFinish();
        $responses = [];
        $deadline = microtime(true) + $timeoutSeconds;
        while (!$this->finished && microtime(true) < $deadline) {
            $responses = array_merge($responses, $this->pump());
            if (!$this->finished) {
                usleep(1000);
            }
        }
        if (!$this->finished) {
            throw new RuntimeException('Timed out draining Opus worker');
        }
        return $responses;
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->socket = null;
    }

    public function isFinished(): bool
    {
        return $this->finished;
    }

    public function canAcceptPacket(): bool
    {
        return $this->pendingPackets < self::MAX_PENDING_PACKETS;
    }

    private function decodeResponse(string $body): array
    {
        $type = ord($body[0]);
        if ($type === OpusWorkerProtocol::AAC) {
            if (strlen($body) < 9) {
                throw new UnexpectedValueException('Truncated AAC response');
            }
            $values = unpack('NrequestId/NfirstFrame', substr($body, 1, 8));
            $adtsOffset = 9;
            if (strlen($body) >= 11) {
                $statsLength = unpack('n', substr($body, 9, 2))[1];
                $legacyOffset = 11 + $statsLength;
                if ($statsLength > 0 && $legacyOffset <= strlen($body)) {
                    $stats = json_decode(substr($body, 11, $statsLength), true);
                    $legacyAdts = substr($body, $legacyOffset);
                    if (is_array($stats) && ($legacyAdts === '' || str_starts_with($legacyAdts, "\xff\xf1"))) {
                        $adtsOffset = $legacyOffset;
                    }
                }
            }
            if ($values['requestId'] !== 0) {
                --$this->pendingPackets;
            }
            return [
                'type' => 'aac',
                'requestId' => $values['requestId'],
                'firstFrame' => $values['firstFrame'],
                'adts' => substr($body, $adtsOffset),
            ];
        }
        if ($type === OpusWorkerProtocol::ERROR) {
            if (strlen($body) < 5) {
                throw new UnexpectedValueException('Truncated ERROR response');
            }
            $requestId = unpack('N', substr($body, 1, 4))[1];
            throw new RuntimeException('Opus worker error' . ($requestId ? " for request {$requestId}" : '') . ': ' . substr($body, 5));
        }
        if ($type === OpusWorkerProtocol::FINISHED && strlen($body) === 1) {
            $this->finished = true;
            return ['type' => 'finished'];
        }
        throw new UnexpectedValueException('Unknown Opus worker response');
    }

    private function ensureConnected(): void
    {
        if (!is_resource($this->socket)) {
            throw new RuntimeException('Opus worker client is not connected');
        }
    }

    private function ensureUsable(): void
    {
        $this->ensureConnected();
        if ($this->finished || feof($this->socket)) {
            throw new RuntimeException('Opus worker connection is no longer usable');
        }
    }

    private function openSocket(float $timeout)
    {
        return @stream_socket_client("tcp://127.0.0.1:{$this->port}", $errno, $error, $timeout, STREAM_CLIENT_CONNECT);
    }

    public static function shutdownOwnedWorkers(): void
    {
        foreach (self::$processes as $process) {
            if (!is_resource($process)) {
                continue;
            }
            $status = @proc_get_status($process);
            if (($status['running'] ?? false) === true) {
                @proc_terminate($process);
            }
            @proc_close($process);
        }
        self::$processes = [];
    }

    private static function startWorker(int $port): void
    {
        $entry = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'opus-worker.php';
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $descriptor = [
            0 => ['file', $null, 'r'],
            1 => ['file', $null, 'a'],
            2 => ['file', $null, 'a'],
        ];
        $options = ['bypass_shell' => true];
        if (PHP_OS_FAMILY === 'Windows') {
            $options['create_process_group'] = true;
        }
        $composerClassLoader = (new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName();
        $autoload = dirname($composerClassLoader, 2) . DIRECTORY_SEPARATOR . 'autoload.php';
        $process = @proc_open(
            [PHP_BINARY, $entry, '--owned', "--port={$port}", "--autoload={$autoload}"],
            $descriptor,
            $pipes,
            dirname($entry),
            null,
            $options
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start Opus worker process');
        }
        self::$processes[] = $process;
        if (!self::$shutdownRegistered) {
            self::$shutdownRegistered = true;
            register_shutdown_function([self::class, 'shutdownOwnedWorkers']);
        }
    }
}
