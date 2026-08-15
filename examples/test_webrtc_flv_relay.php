<?php

use Xiaosongshu\Flv2mp4\Aac\AacLcEncoder;
use Xiaosongshu\Flv2mp4\Flv\FlvSinglePusher;
use Xiaosongshu\Flv2mp4\Flv\WebRtcFlvRelay;
use Xiaosongshu\Flv2mp4\Opus\OpusToAacTranscoder;
use Xiaosongshu\Flv2mp4\Opus\OpusWorkerClient;
use Xiaosongshu\Flv2mp4\Opus\OpusWorkerProtocol;

require_once dirname(__DIR__) . '/vendor/autoload.php';

final class RelayTestPusher extends FlvSinglePusher
{
    public array $writes = [];
    public ?int $failAtWrite = null;
    private bool $testClosed = false;

    public function __construct()
    {
        parent::__construct('test', 'ws://127.0.0.1/test');
    }

    public function connect(): bool
    {
        $this->testClosed = false;
        return true;
    }

    public function write($data): bool
    {
        $this->writes[] = $data;
        if ($this->failAtWrite !== null && count($this->writes) === $this->failAtWrite) {
            $this->testClosed = true;
            return false;
        }
        return true;
    }

    public function flush(): void
    {
    }

    public function close(): void
    {
        $this->testClosed = true;
    }

    public function isClosed(): bool
    {
        return $this->testClosed;
    }
}

final class FailingAudioTranscoder extends OpusToAacTranscoder
{
    public int $pushCount = 0;

    public function __construct()
    {
    }

    public function pushPacket(string $packet, ?int $sampleCount = null): string
    {
        ++$this->pushCount;
        throw new LogicException('unsupported synthetic Opus packet');
    }

    public function finish(): string
    {
        return '';
    }

    public function getAudioSpecificConfig(): string
    {
        return "\x11\x90";
    }

    public function channels(): int
    {
        return 2;
    }
}

function testRtp(int $pt, int $seq, int $timestamp, string $payload, bool $marker = true): string
{
    return "\x80" . chr($pt | ($marker ? 0x80 : 0)) . pack('nNN', $seq, $timestamp, 1) . $payload;
}

function assertTest(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$gapBuffer = OpusWorkerProtocol::gap(7, 960);
$gapBodies = OpusWorkerProtocol::takeFrames($gapBuffer);
assertTest($gapBuffer === '' && count($gapBodies) === 1, 'GAP protocol framing did not round-trip');
assertTest(ord($gapBodies[0][0]) === OpusWorkerProtocol::GAP, 'GAP protocol type is invalid');
$gapValues = unpack('NrequestId/NsampleCount', substr($gapBodies[0], 1));
assertTest($gapValues['requestId'] === 7 && $gapValues['sampleCount'] === 960, 'GAP protocol values are invalid');

$clientReflection = new ReflectionClass(OpusWorkerClient::class);
$client = $clientReflection->newInstanceWithoutConstructor();
$pendingProperty = $clientReflection->getProperty('pendingPackets');
$pendingProperty->setValue($client, 1);
$decodeResponse = $clientReflection->getMethod('decodeResponse');
$droppedFrame = OpusWorkerProtocol::dropped(9, 'unsupported Hybrid frame');
$droppedBodies = OpusWorkerProtocol::takeFrames($droppedFrame);
$dropped = $decodeResponse->invoke($client, $droppedBodies[0]);
assertTest($dropped['type'] === 'dropped' && $dropped['requestId'] === 9, 'DROPPED response was not decoded');
assertTest($pendingProperty->getValue($client) === 0 && !$client->isFinished(), 'DROPPED response did not release pending state');
$outputProperty = $clientReflection->getProperty('output');
$outputProperty->setValue($client, str_repeat('x', 4194304 - 65550 + 1));
assertTest(!$client->canAcceptPacket(), 'client accepted a packet without sufficient output byte headroom');
$outputProperty->setValue($client, '');
$pendingProperty->setValue($client, 1000);
assertTest(!$client->canAcceptPacket(), 'client accepted a packet at the pending packet limit');

$workerPort = random_int(20000, 40000);
$worker = new OpusWorkerClient($workerPort);
$worker->connect('worker-regression', 64000, 1);
$hybridRequest = $worker->push(1, 0, "\x60");
$workerResponses = [];
$deadline = microtime(true) + 2.0;
do {
    $workerResponses = array_merge($workerResponses, $worker->pump());
    if ($workerResponses === []) {
        usleep(1000);
    }
} while ($workerResponses === [] && microtime(true) < $deadline);
assertTest(($workerResponses[0]['type'] ?? null) === 'dropped' && $workerResponses[0]['requestId'] === $hybridRequest, 'Hybrid frame did not produce DROPPED');
assertTest(!$worker->isFinished(), 'Hybrid frame terminated the Worker connection');
$gapRequest = $worker->pushGap(960);
$gapResponses = [];
$deadline = microtime(true) + 2.0;
do {
    $gapResponses = array_merge($gapResponses, $worker->pump());
    if ($gapResponses === []) {
        usleep(1000);
    }
} while ($gapResponses === [] && microtime(true) < $deadline);
assertTest(($gapResponses[0]['type'] ?? null) === 'aac' && $gapResponses[0]['requestId'] === $gapRequest, 'Worker GAP did not return AAC');
$worker->finish();
$worker->close();
OpusWorkerClient::shutdownOwnedWorkers();

$monoEncoder = new AacLcEncoder(64000, 1);
assertTest($monoEncoder->getAudioSpecificConfig() === "\x11\x88", 'mono AAC ASC is invalid');
$monoAdts = $monoEncoder->encodeFloat(array_fill(0, AacLcEncoder::FRAME_SAMPLES, 0.0));
assertTest(strlen($monoAdts) > 7, 'mono AAC frame was not encoded');
assertTest((ord($monoAdts[2]) & 1) === 0 && (ord($monoAdts[3]) >> 6) === 1, 'mono ADTS channel configuration is invalid');

$sps = "\x67\x42\x00\x1e\x95\xa8\x14\x01\x6e\x9b\x80";
$pps = "\x68\xce\x3c\x80";
$idr = "\x65\x88\x84";
$stapA = "\x78" . pack('n', strlen($sps)) . $sps
    . pack('n', strlen($pps)) . $pps
    . pack('n', strlen($idr)) . $idr;

$audioFailurePusher = new RelayTestPusher();
$transcoder = new FailingAudioTranscoder();
$audioFailureRelay = new WebRtcFlvRelay(1, 'test-audio-failure', 'ws://127.0.0.1/live/test', $audioFailurePusher, $transcoder);
$audioFailureRelay->connect();
$audioFailed = false;
try {
    $audioFailureRelay->pushRtp(testRtp(111, 1, 48000, "\x00"), 'audio');
} catch (LogicException $e) {
    $audioFailed = true;
}
assertTest($audioFailed, 'audio failure was not propagated');
assertTest($audioFailureRelay->consumeAudioError() === 'unsupported synthetic Opus packet', 'audio failure was not exposed');
assertTest(!$audioFailureRelay->isHealthy(), 'audio failure did not close the relay');
assertTest($transcoder->pushCount === 1, 'failing transcoder push count is invalid');

$pusher = new RelayTestPusher();
$relay = new WebRtcFlvRelay(2, 'test', 'ws://127.0.0.1/live/test', $pusher, new FailingAudioTranscoder());
$relay->connect();
$relay->pushRtp(testRtp(96, 3, 90000, $stapA), 'video');
assertTest($relay->isHealthy(), 'video-only regression relay is unhealthy');
assertTest($relay->consumeAvcSequenceHeaderSent(), 'AVC sequence-header state was not exposed');
assertTest(isset($pusher->writes[1]) && ord($pusher->writes[1][0]) === 18, 'metadata script tag missing');
assertTest(strpos($pusher->writes[1], 'onMetaData') !== false, 'onMetaData payload missing');
assertTest(isset($pusher->writes[2]) && strpos($pusher->writes[2], "\xAF\x00") !== false, 'AAC sequence header missing');
assertTest(isset($pusher->writes[3]) && strpos($pusher->writes[3], "\x17\x00\x00\x00\x00") !== false, 'AVC sequence header missing');
assertTest(isset($pusher->writes[4]) && strpos($pusher->writes[4], "\x17\x01\x00\x00\x00") !== false, 'IDR tag missing');

$failingPusher = new RelayTestPusher();
$failingPusher->failAtWrite = 4;
$failingRelay = new WebRtcFlvRelay(2, 'test-failure', 'ws://127.0.0.1/live/test-failure', $failingPusher, new FailingAudioTranscoder());
$failingRelay->connect();
$videoWriteFailed = false;
try {
    $failingRelay->pushRtp(testRtp(96, 1, 90000, $stapA), 'video');
} catch (RuntimeException $e) {
    $videoWriteFailed = true;
}
assertTest($videoWriteFailed && !$failingRelay->isHealthy(), 'video write failure did not fail the relay');

$start = file_get_contents(dirname(__DIR__) . '/start.php');
assertTest(strpos($start, "'ws://127.0.0.1:8501/live/{streamId}'") !== false, 'default ws-flv URL missing');
assertTest(strpos($start, 'live/{streamId}.flv') === false, 'default ws-flv URL still contains .flv');

fwrite(STDOUT, "WebRTC FLV relay regression passed\n");
