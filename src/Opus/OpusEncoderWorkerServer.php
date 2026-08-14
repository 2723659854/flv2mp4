<?php

namespace Xiaosongshu\Flv2mp4\Opus;

use RuntimeException;
use Throwable;
use UnexpectedValueException;
use Xiaosongshu\Flv2mp4\Aac\AacLcEncoder;

final class OpusEncoderWorkerServer
{
    private const MAX_BUFFER = 2097152;
    private $server;
    private array $connections = [];

    public function run(string $address = 'tcp://127.0.0.1:8331', ?float $exitWhenIdleSeconds = null): void
    {
        $this->server = @stream_socket_server($address, $errno, $error);
        if ($this->server === false) throw new RuntimeException("Unable to listen on {$address}: {$error} ({$errno})");
        stream_set_blocking($this->server, false);
        $accepted = false;
        $idleSince = microtime(true);
        while (true) {
            if ($this->connections !== []) $idleSince = microtime(true);
            if ($accepted && $exitWhenIdleSeconds !== null && $this->connections === [] && microtime(true) - $idleSince >= $exitWhenIdleSeconds) { fclose($this->server); return; }
            $read = [$this->server]; $write = [];
            foreach ($this->connections as $connection) { $read[] = $connection['socket']; if ($connection['output'] !== '') $write[] = $connection['socket']; }
            $except = null;
            if (@stream_select($read, $write, $except, 0, 1000) === false) continue;
            if (in_array($this->server, $read, true)) while (($socket = @stream_socket_accept($this->server, 0)) !== false) {
                $accepted = true; stream_set_blocking($socket, false);
                $this->connections[(int)$socket] = ['socket'=>$socket,'input'=>'','output'=>'','encoder'=>null,'channels'=>0,'frameIndex'=>0,'finished'=>false,'count'=>0,'totalMs'=>0.0,'maxMs'=>0.0,'encodedFrameCount'=>0,'emptyEncodeRequestCount'=>0,'multiFrameRequestCount'=>0,'emptyRequestMs'=>0.0,'frameProducingRequestMs'=>0.0,'reportedAt'=>0.0];
            }
            foreach ($read as $socket) if ($socket !== $this->server) $this->read((int)$socket);
            foreach (array_keys($this->connections) as $id) $this->process($id);
            foreach ($write as $socket) $this->write((int)$socket);
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
        try { foreach (OpusWorkerProtocol::takeFrames($this->connections[$id]['input'], 16) as $body) $this->handle($id, $body); }
        catch (Throwable $e) { $this->queue($id, OpusWorkerProtocol::error(0, $e->getMessage())); if (isset($this->connections[$id])) $this->connections[$id]['finished'] = true; }
    }

    private function handle(int $id, string $body): void
    {
        $type = ord($body[0]);
        if ($type === OpusWorkerProtocol::OPEN) {
            if (strlen($body) < 8 || $this->connections[$id]['encoder'] !== null) throw new UnexpectedValueException('Invalid or duplicate encoder OPEN');
            $values = unpack('nstreamLength/Nbitrate/Cchannels', substr($body, 1, 7));
            if (strlen($body) !== 8 + $values['streamLength']) throw new UnexpectedValueException('Invalid encoder OPEN');
            $this->connections[$id]['encoder'] = new AacLcEncoder($values['bitrate'], $values['channels']);
            $this->connections[$id]['channels'] = $values['channels'];
            return;
        }
        if ($type === OpusWorkerProtocol::PCM) {
            if (strlen($body) < 17 || $this->connections[$id]['encoder'] === null || $this->connections[$id]['finished']) throw new UnexpectedValueException('PCM before OPEN or after FINISH');
            $values = unpack('NrequestId/nsequence/Ntimestamp/NsampleCount/Cchannels', substr($body, 1, 15));
            $payload = substr($body, 16);
            if ($values['requestId'] === 0 || $values['sampleCount'] === 0 || $values['sampleCount'] > OpusWorkerProtocol::MAX_GAP_SAMPLES || $values['channels'] !== $this->connections[$id]['channels'] || strlen($payload) !== $values['sampleCount'] * $values['channels'] * 4) throw new UnexpectedValueException('Invalid PCM payload length or metadata');
            $pcm = array_values(unpack('g*', $payload) ?: []);
            if (count($pcm) !== $values['sampleCount'] * $values['channels']) throw new UnexpectedValueException('Unable to unpack PCM payload');
            $started = hrtime(true);
            $adts = $this->connections[$id]['encoder']->encodeFloat($pcm);
            $ms = (hrtime(true) - $started) / 1000000;
            $firstFrame = $this->connections[$id]['frameIndex'];
            $encodedFrames = $this->countAdts($adts);
            $this->connections[$id]['frameIndex'] += $encodedFrames;
            // #region debug-point aac-request-semantics
            $this->connections[$id]['encodedFrameCount'] += $encodedFrames;
            if ($encodedFrames === 0) {
                ++$this->connections[$id]['emptyEncodeRequestCount'];
                $this->connections[$id]['emptyRequestMs'] += $ms;
            } else {
                if ($encodedFrames > 1) ++$this->connections[$id]['multiFrameRequestCount'];
                $this->connections[$id]['frameProducingRequestMs'] += $ms;
            }
            // #endregion
            $this->recordTiming($id, $ms);
            $this->queue($id, OpusWorkerProtocol::aac($values['requestId'], $firstFrame, $adts));
            return;
        }
        if ($type === OpusWorkerProtocol::FINISH) {
            if (strlen($body) !== 1 || $this->connections[$id]['encoder'] === null || $this->connections[$id]['finished']) throw new UnexpectedValueException('Invalid encoder FINISH');
            $adts = $this->connections[$id]['encoder']->flush();
            $firstFrame = $this->connections[$id]['frameIndex'];
            $this->connections[$id]['frameIndex'] += $this->countAdts($adts);
            $this->queue($id, OpusWorkerProtocol::aac(0, $firstFrame, $adts));
            $this->queue($id, OpusWorkerProtocol::finished());
            $this->connections[$id]['finished'] = true;
            return;
        }
        throw new UnexpectedValueException('Unknown encoder worker message type');
    }

    private function countAdts(string $adts): int
    {
        $count = 0; $offset = 0; $length = strlen($adts);
        while ($offset < $length) { if ($length - $offset < 7) throw new UnexpectedValueException('Truncated ADTS produced by encoder'); $frameLength = ((ord($adts[$offset+3])&3)<<11)|(ord($adts[$offset+4])<<3)|(ord($adts[$offset+5])>>5); if ($frameLength < 7 || $offset+$frameLength>$length) throw new UnexpectedValueException('Invalid ADTS produced by encoder'); ++$count; $offset += $frameLength; }
        return $count;
    }

    private function recordTiming(int $id, float $ms): void
    {
        ++$this->connections[$id]['count']; $this->connections[$id]['totalMs'] += $ms; $this->connections[$id]['maxMs'] = max($this->connections[$id]['maxMs'], $ms);
        $count = $this->connections[$id]['count']; $now = microtime(true);
        if ($now - $this->connections[$id]['reportedAt'] < 5.0) return;
        $this->connections[$id]['reportedAt'] = $now;
        // #region debug-point split-encoder-timing
        $event=json_encode(['sessionId'=>'webrtc-relay-disconnect','runId'=>'pipeline-aggregate','hypothesisId'=>'H1/H3','location'=>'OpusEncoderWorkerServer','msg'=>'encoder aggregate','data'=>['processedCount'=>$count,'totalEncodeMs'=>$this->connections[$id]['totalMs'],'averageEncodeMs'=>$this->connections[$id]['totalMs']/$count,'maxEncodeMs'=>$this->connections[$id]['maxMs'],'encodedFrameCount'=>$this->connections[$id]['encodedFrameCount'],'emptyEncodeRequestCount'=>$this->connections[$id]['emptyEncodeRequestCount'],'multiFrameRequestCount'=>$this->connections[$id]['multiFrameRequestCount'],'emptyRequestMs'=>$this->connections[$id]['emptyRequestMs'],'frameProducingRequestMs'=>$this->connections[$id]['frameProducingRequestMs'],'inputBytes'=>strlen($this->connections[$id]['input']),'outputBytes'=>strlen($this->connections[$id]['output'])],'ts'=>$now]); if($event!==false&&($debug=@stream_socket_client('tcp://127.0.0.1:7777',$errno,$error,0.001))){@stream_set_timeout($debug,0,1000);@fwrite($debug,"POST /event HTTP/1.1\r\nHost: 127.0.0.1:7777\r\nContent-Type: application/json\r\nContent-Length: ".strlen($event)."\r\nConnection: close\r\n\r\n".$event);@fclose($debug);}
        // #endregion
    }

    private function queue(int $id,string $data):void{if(!isset($this->connections[$id]))return;$this->connections[$id]['output'].=$data;if(strlen($this->connections[$id]['output'])>self::MAX_BUFFER)$this->close($id);}
    private function write(int $id):void{if(!isset($this->connections[$id]))return;$written=@fwrite($this->connections[$id]['socket'],substr($this->connections[$id]['output'],0,65536));if($written===false||($written===0&&feof($this->connections[$id]['socket']))){$this->close($id);return;}if($written>0)$this->connections[$id]['output']=substr($this->connections[$id]['output'],$written);}
    private function close(int $id):void{if(isset($this->connections[$id])){@fclose($this->connections[$id]['socket']);unset($this->connections[$id]);}}
}
