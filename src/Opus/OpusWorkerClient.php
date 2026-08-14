<?php

namespace Xiaosongshu\Flv2mp4\Opus;

use RuntimeException;
use UnexpectedValueException;

/**
 * @purpose opus双worker流水线客户端
 * @author yanglong
 * @time 2026年8月12日17:24:31
 */
final class OpusWorkerClient
{
    private const MAX_OUTPUT_BYTES = 262144;
    private const MAX_INPUT_BYTES = 1048576;
    private const MAX_RELAY_BYTES = 1048576;
    private const MAX_PENDING_PACKETS = 100;

    private static array $processes = [];
    private static bool $shutdownRegistered = false;
    private $decoderSocket = null;
    private $encoderSocket = null;
    private string $decoderInput = '';
    private string $decoderOutput = '';
    private string $encoderInput = '';
    private string $encoderOutput = '';
    private int $pendingPackets = 0;
    private int $nextRequestId = 1;
    // #region debug-point opus-client-pipeline-aggregate
    private int $debugPumpCalls = 0;
    private int $debugPushCount = 0;
    private int $debugDecoderFramesParsed = 0;
    private int $debugEncoderAcksParsed = 0;
    private int $debugDecoderWriteBytes = 0;
    private int $debugDecoderReadBytes = 0;
    private int $debugEncoderWriteBytes = 0;
    private int $debugEncoderReadBytes = 0;
    private array $debugPendingSince = [];
    private float $debugLastCriticalReportAt = 0.0;
    // #endregion
    private float $debugLastReportAt = 0.0;
    private bool $finishSent = false;
    private bool $decoderFinished = false;
    private bool $encoderFinishSent = false;
    private bool $finished = false;

    public function __construct(private readonly int $port = 8330)
    {
        if ($port < 1 || $port > 65534) throw new RuntimeException('Opus decoder worker port must be between 1 and 65534');
    }

    public function connect(string $streamId, int $bitrate = 64000, int $channels = 1): void
    {
        if ($this->decoderSocket !== null || $this->encoderSocket !== null) throw new RuntimeException('Opus worker client is already connected');
        try {
            $this->decoderSocket = $this->connectRole('decoder', $this->port);
            $this->encoderSocket = $this->connectRole('encoder', $this->port + 1);
            stream_set_blocking($this->decoderSocket, false);
            stream_set_blocking($this->encoderSocket, false);
            $this->decoderOutput = OpusWorkerProtocol::decoderOpen($streamId, $channels);
            $this->encoderOutput = OpusWorkerProtocol::encoderOpen($streamId, $bitrate, $channels);
            $deadline = microtime(true) + 0.5;
            while (($this->decoderOutput !== '' || $this->encoderOutput !== '') && microtime(true) < $deadline) {
                $this->pump();
                if ($this->decoderOutput !== '' || $this->encoderOutput !== '') usleep(1000);
            }
            if ($this->decoderOutput !== '' || $this->encoderOutput !== '') throw new RuntimeException('Timed out sending OPEN to Opus workers');
        } catch (\Throwable $e) {
            $this->close();
            throw $e;
        }
    }

    public function push(int $sequence, int $timestamp, string $payload): int
    {
        $this->ensureUsable();
        if ($this->finishSent) throw new RuntimeException('Cannot push Opus after FINISH');
        $requestId = $this->nextRequestId++;
        return $this->enqueue($requestId, OpusWorkerProtocol::push($requestId, $sequence, $timestamp, $payload));
    }

    public function pushGap(int $sampleCount): int
    {
        $this->ensureUsable();
        if ($this->finishSent) throw new RuntimeException('Cannot push Opus GAP after FINISH');
        $requestId = $this->nextRequestId++;
        return $this->enqueue($requestId, OpusWorkerProtocol::gap($requestId, $sampleCount));
    }

    private function enqueue(int $requestId, string $frame): int
    {
        // #region debug-point opus-client-push-critical
        $now = microtime(true); $rejectReason = $this->rejectReason(strlen($frame)); if ($rejectReason !== 0 && $now - $this->debugLastCriticalReportAt >= 5.0) { $this->debugLastCriticalReportAt = $now; $event=json_encode(['sessionId'=>'webrtc-relay-disconnect','runId'=>'pipeline-aggregate','hypothesisId'=>'H2/H4/H5/H6','location'=>'OpusWorkerClient::push','msg'=>'pipeline queue critical aggregate','data'=>['pendingPackets'=>$this->pendingPackets,'requestId'=>$requestId,'rejectReasonBitmask'=>$rejectReason,'oldestPendingAgeMs'=>$this->oldestPendingAgeMs($now)],'ts'=>$now]); if($event!==false&&($debug=@stream_socket_client('tcp://127.0.0.1:7777',$errno,$error,0.001))){@stream_set_timeout($debug,0,1000);@fwrite($debug,"POST /event HTTP/1.1\r\nHost: 127.0.0.1:7777\r\nContent-Type: application/json\r\nContent-Length: ".strlen($event)."\r\nConnection: close\r\n\r\n".$event);@fclose($debug);} }
        // #endregion
        if (!$this->canAcceptPacket() || strlen($this->decoderOutput) + strlen($frame) > self::MAX_OUTPUT_BYTES) throw new RuntimeException('Opus worker input queue limit exceeded');
        $this->decoderOutput .= $frame;
        ++$this->pendingPackets;
        // #region debug-point opus-client-push-accounting
        ++$this->debugPushCount; $this->debugPendingSince[$requestId] = $now;
        // #endregion
        return $requestId;
    }

    public function pump(int $readBudget = 65536, int $writeBudget = 65536, int $responseBudget = 16): array
    {
        $this->ensureConnected();
        try {
            ++$this->debugPumpCalls;
            $responses = [];
            for ($round = 0; $round < 4; $round++) {
                $progress = 0;
                $written = $this->writeSocket($this->decoderSocket, $this->decoderOutput, $writeBudget, 'decoder');
                $read = $this->readSocket($this->decoderSocket, $this->decoderInput, $readBudget, 'decoder');
                $this->debugDecoderWriteBytes += $written;
                $this->debugDecoderReadBytes += $read;
                $decoderFrames = OpusWorkerProtocol::takeFrames($this->decoderInput, $responseBudget);
                foreach ($decoderFrames as $body) { ++$this->debugDecoderFramesParsed; $this->handleDecoderResponse($body); }
                $progress += $written + $read + count($decoderFrames);

                $written = $this->writeSocket($this->encoderSocket, $this->encoderOutput, $writeBudget, 'encoder');
                $read = $this->readSocket($this->encoderSocket, $this->encoderInput, $readBudget, 'encoder');
                $this->debugEncoderWriteBytes += $written;
                $this->debugEncoderReadBytes += $read;
                $encoderFrames = OpusWorkerProtocol::takeFrames($this->encoderInput, $responseBudget);
                foreach ($encoderFrames as $body) $responses[] = $this->decodeEncoderResponse($body);
                $progress += $written + $read + count($encoderFrames);

                if ($this->decoderFinished && $this->encoderOutput === '' && !$this->encoderFinishSent) {
                    $this->encoderOutput = OpusWorkerProtocol::finish();
                    $this->encoderFinishSent = true;
                    ++$progress;
                }
                if ($progress === 0) break;
            }
            // #region debug-point opus-client-pump-report
            $now=microtime(true); if($now-$this->debugLastReportAt>=1.0){$this->debugLastReportAt=$now;$read=[$this->decoderSocket,$this->encoderSocket];$write=null;$except=null;$ready=@stream_select($read,$write,$except,0,0);$readableMask=$ready===false?0:(in_array($this->decoderSocket,$read,true)?1:0)|(in_array($this->encoderSocket,$read,true)?2:0);$event=json_encode(['sessionId'=>'webrtc-relay-disconnect','runId'=>'pipeline-aggregate','hypothesisId'=>'H2/H3/H4/H5/H6','location'=>'OpusWorkerClient::pump','msg'=>'pipeline aggregate','data'=>['pumpCalls'=>$this->debugPumpCalls,'pushCount'=>$this->debugPushCount,'decoderFramesParsed'=>$this->debugDecoderFramesParsed,'encoderAcksParsed'=>$this->debugEncoderAcksParsed,'pending'=>$this->pendingPackets,'oldestPendingAgeMs'=>$this->oldestPendingAgeMs($now),'decoderSocketReadable'=>$readableMask&1,'encoderSocketReadable'=>($readableMask&2)>>1,'decoderReadBytes'=>$this->debugDecoderReadBytes,'decoderWriteBytes'=>$this->debugDecoderWriteBytes,'encoderReadBytes'=>$this->debugEncoderReadBytes,'encoderWriteBytes'=>$this->debugEncoderWriteBytes,'decoderInputBytes'=>strlen($this->decoderInput),'decoderOutputBytes'=>strlen($this->decoderOutput),'encoderInputBytes'=>strlen($this->encoderInput),'pcmRelayBytes'=>strlen($this->encoderOutput),'rejectReasonBitmask'=>$this->rejectReason(0)],'ts'=>$now]);if($event!==false&&($debug=@stream_socket_client('tcp://127.0.0.1:7777',$errno,$error,0.001))){@stream_set_timeout($debug,0,1000);@fwrite($debug,"POST /event HTTP/1.1\r\nHost: 127.0.0.1:7777\r\nContent-Type: application/json\r\nContent-Length: ".strlen($event)."\r\nConnection: close\r\n\r\n".$event);@fclose($debug);}}
            // #endregion
            return $responses;
        } catch (\Throwable $e) {
            $this->close();
            throw $e;
        }
    }

    private function handleDecoderResponse(string $body): void
    {
        $type = ord($body[0]);
        if ($type === OpusWorkerProtocol::PCM) {
            if (strlen($body) < 17) throw new UnexpectedValueException('Truncated PCM response');
            $values = unpack('NrequestId/nsequence/Ntimestamp/NsampleCount/Cchannels', substr($body, 1, 15));
            $payload = substr($body, 16);
            if ($values['requestId'] === 0 || $values['sampleCount'] === 0 || $values['sampleCount'] > OpusWorkerProtocol::MAX_GAP_SAMPLES || ($values['channels'] !== 1 && $values['channels'] !== 2) || strlen($payload) !== $values['sampleCount'] * $values['channels'] * 4) throw new UnexpectedValueException('Invalid decoder PCM response');
            $frame = OpusWorkerProtocol::pcm($values['requestId'], $values['sequence'], $values['timestamp'], $values['sampleCount'], $values['channels'], $payload);
            if (strlen($this->encoderOutput) + strlen($frame) > self::MAX_RELAY_BYTES) throw new RuntimeException('Opus PCM relay buffer limit exceeded');
            $this->encoderOutput .= $frame;
            return;
        }
        if ($type === OpusWorkerProtocol::ERROR) $this->throwWorkerError('decoder', $body);
        if ($type === OpusWorkerProtocol::FINISHED && strlen($body) === 1) { $this->decoderFinished = true; return; }
        throw new UnexpectedValueException('Unknown decoder worker response');
    }

    private function decodeEncoderResponse(string $body): array
    {
        $type = ord($body[0]);
        if ($type === OpusWorkerProtocol::AAC) {
            if (strlen($body) < 9) throw new UnexpectedValueException('Truncated AAC response');
            $values = unpack('NrequestId/NfirstFrame', substr($body, 1, 8));
            if ($values['requestId'] !== 0) {
                if ($this->pendingPackets <= 0) throw new UnexpectedValueException('Unexpected AAC acknowledgement');
                --$this->pendingPackets;
                // #region debug-point opus-client-ack-accounting
                ++$this->debugEncoderAcksParsed; unset($this->debugPendingSince[$values['requestId']]);
                // #endregion
            }
            return ['type'=>'aac','requestId'=>$values['requestId'],'firstFrame'=>$values['firstFrame'],'adts'=>substr($body,9)];
        }
        if ($type === OpusWorkerProtocol::ERROR) $this->throwWorkerError('encoder', $body);
        if ($type === OpusWorkerProtocol::FINISHED && strlen($body) === 1) { $this->finished = true; return ['type'=>'finished']; }
        throw new UnexpectedValueException('Unknown encoder worker response');
    }

    private function throwWorkerError(string $role, string $body): never
    {
        if (strlen($body) < 5) throw new UnexpectedValueException("Truncated {$role} ERROR response");
        $requestId = unpack('N', substr($body,1,4))[1];
        throw new RuntimeException("Opus {$role} worker error".($requestId?" for request {$requestId}":'').': '.substr($body,5));
    }

    public function beginFinish(): void
    {
        $this->ensureUsable();
        if (!$this->finishSent) {
            $frame = OpusWorkerProtocol::finish();
            if (strlen($this->decoderOutput)+strlen($frame)>self::MAX_OUTPUT_BYTES) throw new RuntimeException('Opus worker input queue limit exceeded at FINISH');
            $this->decoderOutput .= $frame;
            $this->finishSent = true;
        }
    }

    public function finish(float $timeoutSeconds = 2.0): array
    {
        $this->beginFinish(); $responses=[]; $deadline=microtime(true)+$timeoutSeconds;
        while(!$this->finished&&microtime(true)<$deadline){$responses=array_merge($responses,$this->pump());if(!$this->finished)usleep(1000);}
        if(!$this->finished)throw new RuntimeException('Timed out draining Opus worker pipeline');
        return $responses;
    }

    public function close(): void
    {
        foreach (['decoderSocket','encoderSocket'] as $property) { if (is_resource($this->{$property})) @fclose($this->{$property}); $this->{$property}=null; }
    }

    public function isFinished(): bool { return $this->finished; }

    public function canAcceptPacket(): bool
    {
        return is_resource($this->decoderSocket) && is_resource($this->encoderSocket) && !$this->finishSent
            && $this->pendingPackets < self::MAX_PENDING_PACKETS
            && strlen($this->decoderOutput) < self::MAX_OUTPUT_BYTES
            && strlen($this->decoderInput) < self::MAX_INPUT_BYTES
            && strlen($this->encoderOutput) < self::MAX_RELAY_BYTES
            && strlen($this->encoderInput) < self::MAX_INPUT_BYTES;
    }

    private function rejectReason(int $nextFrameBytes): int
    {
        $reason = 0;
        if (!is_resource($this->decoderSocket) || !is_resource($this->encoderSocket) || $this->finishSent) $reason |= 1;
        if ($this->pendingPackets >= self::MAX_PENDING_PACKETS) $reason |= 2;
        if (strlen($this->decoderOutput) + $nextFrameBytes >= self::MAX_OUTPUT_BYTES) $reason |= 4;
        if (strlen($this->decoderInput) >= self::MAX_INPUT_BYTES) $reason |= 8;
        if (strlen($this->encoderOutput) >= self::MAX_RELAY_BYTES) $reason |= 16;
        if (strlen($this->encoderInput) >= self::MAX_INPUT_BYTES) $reason |= 32;
        return $reason;
    }

    private function oldestPendingAgeMs(float $now): float
    {
        if ($this->debugPendingSince === []) return 0.0;
        return round(($now - reset($this->debugPendingSince)) * 1000, 3);
    }

    private function writeSocket($socket, string &$buffer, int $budget, string $role): int
    {
        if ($buffer===''||$budget<=0)return 0;
        $written=@fwrite($socket,substr($buffer,0,$budget));
        if($written===false)throw new RuntimeException("Failed writing to Opus {$role} worker");
        if($written>0)$buffer=substr($buffer,$written);
        return $written;
    }

    private function readSocket($socket, string &$buffer, int $budget, string $role): int
    {
        $remaining=$budget;
        while($remaining>0){$data=@fread($socket,min(65536,$remaining));if($data===false)throw new RuntimeException("Failed reading from Opus {$role} worker");if($data==='')break;$buffer.=$data;$remaining-=strlen($data);if(strlen($buffer)>self::MAX_INPUT_BYTES)throw new RuntimeException("Opus {$role} worker output buffer limit exceeded");}
        $expectedFinish=$role==='decoder'?$this->decoderFinished:$this->finished;
        if(feof($socket)&&!$expectedFinish)throw new RuntimeException("Opus {$role} worker connection closed unexpectedly");
        return $budget - $remaining;
    }

    private function ensureConnected(): void { if(!is_resource($this->decoderSocket)||!is_resource($this->encoderSocket))throw new RuntimeException('Opus worker client is not connected'); }
    private function ensureUsable(): void { $this->ensureConnected(); if($this->finished||feof($this->decoderSocket)||feof($this->encoderSocket))throw new RuntimeException('Opus worker connection is no longer usable'); }

    private function connectRole(string $role,int $port)
    {
        $socket=$this->openSocket($port,0.1);
        if($socket===false){self::startWorker($role,$port);$deadline=microtime(true)+2.0;do{usleep(20000);$socket=$this->openSocket($port,0.1);}while($socket===false&&microtime(true)<$deadline);}
        if($socket===false)throw new RuntimeException("Unable to connect to Opus {$role} worker on 127.0.0.1:{$port}");
        return $socket;
    }

    private function openSocket(int $port,float $timeout){return @stream_socket_client("tcp://127.0.0.1:{$port}",$errno,$error,$timeout,STREAM_CLIENT_CONNECT);}

    public static function shutdownOwnedWorkers(): void
    {
        foreach(self::$processes as $process){if(!is_resource($process))continue;$status=@proc_get_status($process);if(($status['running']??false)===true)@proc_terminate($process);@proc_close($process);}self::$processes=[];
    }

    private static function startWorker(string $role,int $port): void
    {
        $entry=dirname(__DIR__,2).DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'opus-worker.php';$null=PHP_OS_FAMILY==='Windows'?'NUL':'/dev/null';
        $descriptor=[0=>['file',$null,'r'],1=>['file',$null,'a'],2=>['file',$null,'a']];$options=['bypass_shell'=>true];if(PHP_OS_FAMILY==='Windows')$options['create_process_group']=true;
        $composerClassLoader=(new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName();$autoload=dirname($composerClassLoader,2).DIRECTORY_SEPARATOR.'autoload.php';
        $process=@proc_open([PHP_BINARY,$entry,'--owned',"--role={$role}","--port={$port}","--autoload={$autoload}"],$descriptor,$pipes,dirname($entry),null,$options);
        if(!is_resource($process))throw new RuntimeException("Unable to start Opus {$role} worker process");
        self::$processes[]=$process;if(!self::$shutdownRegistered){self::$shutdownRegistered=true;register_shutdown_function([self::class,'shutdownOwnedWorkers']);}
    }
}
