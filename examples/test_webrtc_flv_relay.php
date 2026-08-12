<?php

use Xiaosongshu\Flv2mp4\Aac\AacLcEncoder;
use Xiaosongshu\Flv2mp4\Flv\FlvSinglePusher;
use Xiaosongshu\Flv2mp4\Flv\WebRtcFlvRelay;
use Xiaosongshu\Flv2mp4\Opus\OpusToAacTranscoder;

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

$pusher = new RelayTestPusher();
$transcoder = new FailingAudioTranscoder();
$relay = new WebRtcFlvRelay(1, 'test', 'ws://127.0.0.1/live/test', $pusher, $transcoder);
$relay->connect();
$relay->pushRtp(testRtp(111, 1, 48000, "\x00"), 'audio');
assertTest($relay->consumeAudioError() === 'unsupported synthetic Opus packet', 'audio failure was not exposed');
$relay->pushRtp(testRtp(111, 2, 48960, "\x00"), 'audio');
assertTest($transcoder->pushCount === 1, 'disabled audio was transcoded again');
$relay->pushRtp(testRtp(96, 3, 90000, $stapA), 'video');
assertTest($relay->isHealthy(), 'audio failure closed the relay');
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
