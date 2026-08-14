<?php

namespace Xiaosongshu\Flv2mp4\Opus;

use LogicException;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

final class OpusDecoderWorkerServer
{
    private const MAX_BUFFER = 2097152;
    private $server;
    private array $connections = [];

    public function run(string $address = 'tcp://127.0.0.1:8330', ?float $exitWhenIdleSeconds = null): void
    {
        $this->server = @stream_socket_server($address, $errno, $error);
        if ($this->server === false) throw new RuntimeException("Unable to listen on {$address}: {$error} ({$errno})");
        stream_set_blocking($this->server, false);
        $accepted = false;
        $idleSince = microtime(true);
        while (true) {
            if ($this->connections !== []) $idleSince = microtime(true);
            if ($accepted && $exitWhenIdleSeconds !== null && $this->connections === [] && microtime(true) - $idleSince >= $exitWhenIdleSeconds) {
                fclose($this->server);
                return;
            }
            $read = [$this->server];
            $write = [];
            foreach ($this->connections as $connection) {
                $read[] = $connection['socket'];
                if ($connection['output'] !== '') $write[] = $connection['socket'];
            }
            $except = null;
            if (@stream_select($read, $write, $except, 0, 1000) === false) continue;
            if (in_array($this->server, $read, true)) {
                while (($socket = @stream_socket_accept($this->server, 0)) !== false) {
                    $accepted = true;
                    stream_set_blocking($socket, false);
                    $this->connections[(int)$socket] = ['socket' => $socket, 'input' => '', 'output' => '', 'decoder' => null, 'channels' => 0, 'finished' => false, 'count' => 0, 'capabilityFallbackCount' => 0, 'totalMs' => 0.0, 'maxMs' => 0.0, 'stageMs' => [], 'reportedAt' => 0.0];
                }
            }
            foreach ($read as $socket) if ($socket !== $this->server) $this->read((int)$socket);
            foreach (array_keys($this->connections) as $id) $this->process($id);
            foreach (array_keys($this->connections) as $id) if ($this->connections[$id]['output'] !== '') $this->write($id);
        }
    }

    private function read(int $id): void
    {
        if (!isset($this->connections[$id])) return;
        $data = @fread($this->connections[$id]['socket'], 65536);
        if ($data === false || ($data === '' && feof($this->connections[$id]['socket']))) { $this->close($id); return; }
        $this->connections[$id]['input'] .= $data;
        if (strlen($this->connections[$id]['input']) > self::MAX_BUFFER) $this->close($id);
    }

    private function process(int $id): void
    {
        if (!isset($this->connections[$id])) return;
        try {
            foreach (OpusWorkerProtocol::takeFrames($this->connections[$id]['input'], 16) as $body) $this->handle($id, $body);
        } catch (Throwable $e) {
            $this->queue($id, OpusWorkerProtocol::error(0, $e->getMessage()));
            if (isset($this->connections[$id])) $this->connections[$id]['finished'] = true;
        }
    }

    private function handle(int $id, string $body): void
    {
        $type = ord($body[0]);
        if ($type === OpusWorkerProtocol::OPEN) {
            if (strlen($body) < 5 || $this->connections[$id]['decoder'] !== null) throw new UnexpectedValueException('Invalid or duplicate decoder OPEN');
            $values = unpack('nstreamLength/Cchannels', substr($body, 1, 3));
            if (strlen($body) !== 4 + $values['streamLength'] || ($values['channels'] !== 1 && $values['channels'] !== 2)) throw new UnexpectedValueException('Invalid decoder OPEN');
            $this->connections[$id]['decoder'] = new OpusDecoder($values['channels'], 48000);
            $this->connections[$id]['channels'] = $values['channels'];
            return;
        }
        if ($type === OpusWorkerProtocol::PUSH) {
            if (strlen($body) < 12 || $this->connections[$id]['decoder'] === null || $this->connections[$id]['finished']) throw new UnexpectedValueException('PUSH before OPEN or after FINISH');
            $values = unpack('NrequestId/nsequence/Ntimestamp', substr($body, 1, 10));
            if ($values['requestId'] === 0) throw new UnexpectedValueException('Invalid PUSH request id');
            $packet = substr($body, 11);
            $started = hrtime(true);
            try {
                $pcm = $this->connections[$id]['decoder']->decodeFloat($packet);
                $sampleCount = $this->connections[$id]['decoder']->lastSampleCount();
                $payload = $this->packFloat32Le($pcm);
            } catch (LogicException $e) {
                if (!$this->isUnsupported($e)) throw $e;
                $description = OpusPacketParser::parse($packet);
                $sampleCount = $description['frameDurationSamples'] * $description['frameCount'];
                $payload = str_repeat("\0", $sampleCount * $this->connections[$id]['channels'] * 4);
                ++$this->connections[$id]['capabilityFallbackCount'];
                $this->connections[$id]['decoder']->reset();
            }
            // #region debug-point celt-stage-aggregate
            foreach ($this->connections[$id]['decoder']->debugCeltStageMs() as $stage => $stageMs) {
                $this->connections[$id]['stageMs'][$stage] = ($this->connections[$id]['stageMs'][$stage] ?? 0.0) + $stageMs;
            }
            // #endregion
            $this->recordTiming($id, (hrtime(true) - $started) / 1000000);
            $this->queue($id, OpusWorkerProtocol::pcm($values['requestId'], $values['sequence'], $values['timestamp'], $sampleCount, $this->connections[$id]['channels'], $payload));
            return;
        }
        if ($type === OpusWorkerProtocol::GAP) {
            if (strlen($body) !== 9 || $this->connections[$id]['decoder'] === null || $this->connections[$id]['finished']) throw new UnexpectedValueException('GAP before OPEN or after FINISH');
            $values = unpack('NrequestId/NsampleCount', substr($body, 1, 8));
            if ($values['requestId'] === 0 || $values['sampleCount'] === 0 || $values['sampleCount'] > OpusWorkerProtocol::MAX_GAP_SAMPLES) throw new UnexpectedValueException('Invalid GAP');
            $this->connections[$id]['decoder']->reset();
            $payload = str_repeat("\0", $values['sampleCount'] * $this->connections[$id]['channels'] * 4);
            $this->queue($id, OpusWorkerProtocol::pcm($values['requestId'], 0, 0, $values['sampleCount'], $this->connections[$id]['channels'], $payload));
            return;
        }
        if ($type === OpusWorkerProtocol::FINISH) {
            if (strlen($body) !== 1 || $this->connections[$id]['decoder'] === null || $this->connections[$id]['finished']) throw new UnexpectedValueException('Invalid decoder FINISH');
            $this->queue($id, OpusWorkerProtocol::finished());
            $this->connections[$id]['finished'] = true;
            return;
        }
        throw new UnexpectedValueException('Unknown decoder worker message type');
    }

    private function packFloat32Le(array $samples): string
    {
        $output = '';
        foreach (array_chunk($samples, 2048) as $chunk) $output .= pack('g*', ...$chunk);
        return $output;
    }

    private function isUnsupported(LogicException $e): bool
    {
        return str_contains($e->getMessage(), ' decoding is not implemented;') || str_starts_with($e->getMessage(), 'Unsupported CELT packet:');
    }

    private function recordTiming(int $id, float $ms): void
    {
        ++$this->connections[$id]['count'];
        $this->connections[$id]['totalMs'] += $ms;
        $this->connections[$id]['maxMs'] = max($this->connections[$id]['maxMs'], $ms);
        $count = $this->connections[$id]['count'];
        $now = microtime(true);
        if ($now - $this->connections[$id]['reportedAt'] < 5.0) return;
        $this->connections[$id]['reportedAt'] = $now;
        // #region debug-point split-decoder-timing
        $event = json_encode(['sessionId'=>'webrtc-relay-disconnect','runId'=>'pipeline-aggregate','hypothesisId'=>'H1/H7','location'=>'OpusDecoderWorkerServer','msg'=>'decoder aggregate','data'=>['processedCount'=>$count,'capabilityFallbackCount'=>$this->connections[$id]['capabilityFallbackCount'],'totalDecodeMs'=>$this->connections[$id]['totalMs'],'averageDecodeMs'=>$this->connections[$id]['totalMs']/$count,'maxDecodeMs'=>$this->connections[$id]['maxMs'],'celtStageTotalMs'=>$this->connections[$id]['stageMs'],'inputBytes'=>strlen($this->connections[$id]['input']),'outputBytes'=>strlen($this->connections[$id]['output'])],'ts'=>$now]); if ($event !== false && ($debug = @stream_socket_client('tcp://127.0.0.1:7777', $errno, $error, 0.001))) { @stream_set_timeout($debug,0,1000); @fwrite($debug, "POST /event HTTP/1.1\r\nHost: 127.0.0.1:7777\r\nContent-Type: application/json\r\nContent-Length: ".strlen($event)."\r\nConnection: close\r\n\r\n".$event); @fclose($debug); }
        // #endregion
    }

    private function queue(int $id, string $data): void { if (!isset($this->connections[$id])) return; $this->connections[$id]['output'] .= $data; if (strlen($this->connections[$id]['output']) > self::MAX_BUFFER) $this->close($id); }
    private function write(int $id): void { if (!isset($this->connections[$id])) return; $written = @fwrite($this->connections[$id]['socket'], substr($this->connections[$id]['output'], 0, 65536)); if ($written === false || ($written === 0 && feof($this->connections[$id]['socket']))) { $this->close($id); return; } if ($written > 0) $this->connections[$id]['output'] = substr($this->connections[$id]['output'], $written); }
    private function close(int $id): void { if (isset($this->connections[$id])) { @fclose($this->connections[$id]['socket']); unset($this->connections[$id]); } }
}
