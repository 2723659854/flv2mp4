<?php

namespace Xiaosongshu\Flv2mp4\Opus;

use RuntimeException;
use Throwable;
use UnexpectedValueException;

/**
 * @purpose opus转码子进程服务端
 * @author yanglong
 * @time 2026年8月12日17:25:59
 */
final class OpusWorkerServer
{
    private const MAX_CONNECTION_BUFFER = 2097152;
    private const MAX_OUTPUT_BUFFER = 2097152;

    private $server;
    private array $connections = [];

    public function run(string $address = 'tcp://127.0.0.1:8330', ?float $exitWhenIdleSeconds = null): void
    {
        $this->server = @stream_socket_server($address, $errno, $error);
        if ($this->server === false) {
            throw new RuntimeException("Unable to listen on {$address}: {$error} ({$errno})");
        }
        stream_set_blocking($this->server, false);
        $acceptedConnection = false;
        $idleSince = microtime(true);
        while (true) {
            if ($this->connections !== []) {
                $idleSince = microtime(true);
            }
            if ($acceptedConnection && $exitWhenIdleSeconds !== null && $this->connections === []
                && microtime(true) - $idleSince >= $exitWhenIdleSeconds) {
                fclose($this->server);
                return;
            }
            $read = [$this->server];
            $write = [];
            $bufferedFrameReady = false;
            foreach ($this->connections as $connection) {
                $read[] = $connection['socket'];
                if ($connection['output'] !== '') {
                    $write[] = $connection['socket'];
                }
                $inputLength = strlen($connection['input']);
                if ($inputLength >= 4) {
                    $bodyLength = unpack('N', substr($connection['input'], 0, 4))[1];
                    if ($inputLength >= 4 + $bodyLength) {
                        $bufferedFrameReady = true;
                    }
                }
            }
            $except = null;
            if (@stream_select($read, $write, $except, 0, $bufferedFrameReady ? 0 : 200000) === false) {
                continue;
            }
            if (in_array($this->server, $read, true)) {
                while (($socket = @stream_socket_accept($this->server, 0)) !== false) {
                    $acceptedConnection = true;
                    stream_set_blocking($socket, false);
                    $this->connections[(int)$socket] = [
                        'socket' => $socket,
                        'input' => '',
                        'output' => '',
                        'transcoder' => null,
                        'frameIndex' => 0,
                        'finished' => false,
                    ];
                }
            }
            foreach ($read as $socket) {
                if ($socket === $this->server) {
                    continue;
                }
                $this->readConnection((int)$socket);
            }
            foreach (array_keys($this->connections) as $id) {
                $this->processConnection($id);
            }
            foreach ($write as $socket) {
                $this->writeConnection((int)$socket);
            }
        }
    }

    private function readConnection(int $id): void
    {
        if (!isset($this->connections[$id])) {
            return;
        }
        $socket = $this->connections[$id]['socket'];
        $data = @fread($socket, 65536);
        if ($data === false || ($data === '' && feof($socket))) {
            $this->closeConnection($id);
            return;
        }
        if ($data === '') {
            return;
        }
        $this->connections[$id]['input'] .= $data;
        if (strlen($this->connections[$id]['input']) > self::MAX_CONNECTION_BUFFER) {
            $this->closeConnection($id);
            return;
        }
    }

    private function processConnection(int $id): void
    {
        if (!isset($this->connections[$id])) {
            return;
        }
        try {
            foreach (OpusWorkerProtocol::takeFrames($this->connections[$id]['input'], 1) as $body) {
                $this->handleMessage($id, $body);
            }
        } catch (Throwable $e) {
            $this->queue($id, OpusWorkerProtocol::error(0, $e->getMessage()));
            if (isset($this->connections[$id])) {
                $this->connections[$id]['finished'] = true;
            }
        }
    }

    private function handleMessage(int $id, string $body): void
    {
        $type = ord($body[0]);
        if ($type === OpusWorkerProtocol::OPEN) {
            if (strlen($body) < 8 || $this->connections[$id]['transcoder'] !== null) {
                throw new UnexpectedValueException('Invalid or duplicate OPEN');
            }
            $values = unpack('nstreamLength/Nbitrate/Cchannels', substr($body, 1, 7));
            if (strlen($body) !== 8 + $values['streamLength']) {
                throw new UnexpectedValueException('Invalid OPEN stream id length');
            }
            $this->connections[$id]['transcoder'] = new OpusToAacTranscoder($values['bitrate'], $values['channels']);
            return;
        }
        if ($type === OpusWorkerProtocol::PUSH) {
            if (strlen($body) < 12 || $this->connections[$id]['transcoder'] === null || $this->connections[$id]['finished']) {
                throw new UnexpectedValueException('PUSH before OPEN or after FINISH');
            }
            $values = unpack('NrequestId/nsequence/Ntimestamp', substr($body, 1, 10));
            try {
                $transcoder = $this->connections[$id]['transcoder'];
                $adts = $transcoder->pushPacket(substr($body, 11));
                $firstFrame = $this->connections[$id]['frameIndex'];
                $frameCount = $this->countAdtsFrames($adts);
                $this->connections[$id]['frameIndex'] += $frameCount;
                $this->queue($id, OpusWorkerProtocol::aac($values['requestId'], $firstFrame, $adts));
            } catch (Throwable $e) {
                $this->queue($id, OpusWorkerProtocol::error($values['requestId'], $e->getMessage()));
                $this->connections[$id]['finished'] = true;
            }
            return;
        }
        if ($type === OpusWorkerProtocol::FINISH) {
            if (strlen($body) !== 1 || $this->connections[$id]['transcoder'] === null || $this->connections[$id]['finished']) {
                throw new UnexpectedValueException('Invalid FINISH');
            }
            $transcoder = $this->connections[$id]['transcoder'];
            $adts = $transcoder->finish();
            $firstFrame = $this->connections[$id]['frameIndex'];
            $this->queue($id, OpusWorkerProtocol::aac(0, $firstFrame, $adts));
            $this->queue($id, OpusWorkerProtocol::finished());
            $this->connections[$id]['finished'] = true;
            return;
        }
        throw new UnexpectedValueException('Unknown Opus worker message type');
    }

    private function countAdtsFrames(string $adts): int
    {
        $count = 0;
        $offset = 0;
        while ($offset < strlen($adts)) {
            if (strlen($adts) - $offset < 7) {
                throw new UnexpectedValueException('Truncated ADTS produced by encoder');
            }
            $length = ((ord($adts[$offset + 3]) & 3) << 11) | (ord($adts[$offset + 4]) << 3) | (ord($adts[$offset + 5]) >> 5);
            if ($length < 7 || $offset + $length > strlen($adts)) {
                throw new UnexpectedValueException('Invalid ADTS produced by encoder');
            }
            ++$count;
            $offset += $length;
        }
        return $count;
    }

    private function queue(int $id, string $data): void
    {
        if (!isset($this->connections[$id])) {
            return;
        }
        $this->connections[$id]['output'] .= $data;
        if (strlen($this->connections[$id]['output']) > self::MAX_OUTPUT_BUFFER) {
            $this->closeConnection($id);
        }
    }

    private function writeConnection(int $id): void
    {
        if (!isset($this->connections[$id])) {
            return;
        }
        $written = @fwrite($this->connections[$id]['socket'], substr($this->connections[$id]['output'], 0, 65536));
        if ($written === false || ($written === 0 && feof($this->connections[$id]['socket']))) {
            $this->closeConnection($id);
            return;
        }
        if ($written > 0) {
            $this->connections[$id]['output'] = substr($this->connections[$id]['output'], $written);
        }
    }

    private function closeConnection(int $id): void
    {
        if (isset($this->connections[$id])) {
            @fclose($this->connections[$id]['socket']);
            unset($this->connections[$id]);
        }
    }
}
