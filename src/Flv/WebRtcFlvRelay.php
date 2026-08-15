<?php

namespace Xiaosongshu\Flv2mp4\Flv;

use InvalidArgumentException;
use RuntimeException;
use UnexpectedValueException;
use Xiaosongshu\Flv2mp4\Opus\OpusDecodeException;
use Xiaosongshu\Flv2mp4\Opus\OpusPacketParser;
use Xiaosongshu\Flv2mp4\Opus\OpusToAacTranscoder;
use Xiaosongshu\Flv2mp4\Opus\OpusWorkerClient;
use Xiaosongshu\Flv2mp4\Opus\OpusWorkerProtocol;

final class WebRtcFlvRelay
{
    private const MAX_PENDING_OPUS_GAP_SAMPLES = 14400000;
    private int $clientId;
    private string $streamId;
    private FlvSinglePusher $pusher;
    private ?OpusToAacTranscoder $transcoder;
    private ?OpusWorkerClient $workerClient = null;
    private int $opusWorkerPort;
    private bool $closed = false;
    private ?string $lastAudioError = null;
    private int $droppedOpusPackets = 0;
    private int $consecutiveDroppedOpusPackets = 0;
    private int $pendingOpusGapSamples = 0;
    private float $lastOpusDropReportAt = 0.0;
    // #region debug-point relay-audio-aggregate-state
    private float $debugLastAudioAggregateAt = 0.0;
    private float $debugLastRecoveryReportAt = 0.0;
    private float $debugLastGapReportAt = 0.0;
    private float $debugLastWorkerDropReportAt = 0.0;
    private int $debugWorkerDroppedOpus = 0;
    private int $debugReceivedOpus = 0;
    private int $debugPushedOpus = 0;
    private int $debugGeneratedGapPackets = 0;
    private int $debugGeneratedGapSamples = 0;
    // #endregion
    private ?array $opusFormatPending = null;
    private bool $avcSequenceHeaderPending = false;
    private ?int $baseArrivalNs = null;
    private ?int $videoArrivalOffsetMs = null;
    private ?int $audioArrivalOffsetMs = null;
    private ?int $videoBaseRtpTimestamp = null;
    private int $audioFrameIndex = 0;
    private ?int $auTimestamp = null;
    private array $accessUnit = [];
    private ?array $fu = null;
    private ?string $sps = null;
    private ?string $pps = null;
    private ?string $sentAvcConfigHash = null;
    private bool $videoStarted = false;
    private bool $accessUnitCorrupt = false;
    private ?int $lastVideoTimestamp = null;

    public function __construct(
        int $clientId,
        string $streamId,
        string $pushUrl,
        ?FlvSinglePusher $pusher = null,
        ?OpusToAacTranscoder $transcoder = null,
        int $opusWorkerPort = 8330
    ) {
        if ($streamId === '') {
            throw new InvalidArgumentException('streamId must not be empty');
        }
        $this->clientId = $clientId;
        $this->streamId = $streamId;
        $this->pusher = $pusher ?? new FlvSinglePusher($streamId, $pushUrl);
        $this->transcoder = $transcoder;
        $this->opusWorkerPort = $opusWorkerPort;
    }

    public function connect(): void
    {
        try {
            if (!$this->pusher->connect()) {
                throw new RuntimeException("Unable to connect ws-flv destination for stream {$this->streamId}");
            }
            if ($this->transcoder === null) {
                $this->workerClient = new OpusWorkerClient($this->opusWorkerPort);
                $this->workerClient->connect($this->streamId, 64000, 1);
            }
            $this->write("FLV\x01\x05\x00\x00\x00\x09\x00\x00\x00\x00");
            $this->write(self::buildFlvTag(18, 0, self::buildOnMetaData($this->channels())));
            $this->write(self::buildFlvTag(8, 0, $this->audioTagHeader() . "\x00" . $this->audioSpecificConfig()));
        } catch (\Throwable $e) {
            $this->closed = true;
            $this->workerClient?->close();
            $this->pusher->close();
            throw $e;
        }
    }

    public function isHealthy(): bool
    {
        return !$this->closed && !$this->pusher->isClosed();
    }

    public function clientId(): int
    {
        return $this->clientId;
    }

    public function streamId(): string
    {
        return $this->streamId;
    }

    public function consumeAudioError(): ?string
    {
        $error = $this->lastAudioError;
        $this->lastAudioError = null;
        return $error;
    }

    public function consumeOpusFormat(): ?array
    {
        $format = $this->opusFormatPending;
        $this->opusFormatPending = null;
        return $format;
    }

    public function consumeAvcSequenceHeaderSent(): bool
    {
        $sent = $this->avcSequenceHeaderPending;
        $this->avcSequenceHeaderPending = false;
        return $sent;
    }

    public function pushRtp(string $plainRtp, string $kind): void
    {
        if ($this->closed) {
            return;
        }
        // #region debug-point relay-pushrtp-slow-stages
        $debugTotalStartNs = hrtime(true);
        $debugPumpBeforeMs = 0.0;
        $debugParseMs = 0.0;
        $debugKindProcessMs = 0.0;
        $debugPumpAfterMs = 0.0;
        try {
            $debugStageStartNs = hrtime(true);
            $this->pumpWorker();
            $debugPumpBeforeMs = (hrtime(true) - $debugStageStartNs) / 1000000;
            $debugStageStartNs = hrtime(true);
            $rtp = self::parseRtp($plainRtp);
            $debugParseMs = (hrtime(true) - $debugStageStartNs) / 1000000;
            $arrivalOffset = $this->arrivalOffsetMs();
            $debugStageStartNs = hrtime(true);
            if ($kind === 'video') {
                $this->pushH264($rtp, $arrivalOffset);
            } elseif ($kind === 'audio') {
                $this->pushOpus($rtp, $arrivalOffset);
                $debugKindProcessMs = (hrtime(true) - $debugStageStartNs) / 1000000;
                $debugStageStartNs = hrtime(true);
                $this->pumpWorker();
                $debugPumpAfterMs = (hrtime(true) - $debugStageStartNs) / 1000000;
            }
            if ($kind !== 'audio') {
                $debugKindProcessMs = (hrtime(true) - $debugStageStartNs) / 1000000;
            }
        } finally {
            $debugTotalMs = (hrtime(true) - $debugTotalStartNs) / 1000000;
            if ($debugPumpBeforeMs >= 100.0 || $debugParseMs >= 100.0 || $debugKindProcessMs >= 100.0 || $debugPumpAfterMs >= 100.0 || $debugTotalMs >= 100.0) { $event = json_encode(['sessionId' => 'webrtc-relay-disconnect', 'runId' => 'block-probe', 'hypothesisId' => 'H6/H7', 'location' => 'WebRtcFlvRelay::pushRtp', 'msg' => 'slow relay RTP processing', 'data' => ['clientId' => $this->clientId, 'kind' => $kind, 'pumpBeforeMs' => round($debugPumpBeforeMs, 3), 'rtpParseMs' => round($debugParseMs, 3), 'kindProcessMs' => round($debugKindProcessMs, 3), 'pumpAfterMs' => round($debugPumpAfterMs, 3), 'totalMs' => round($debugTotalMs, 3), 'workerCanAcceptPacket' => $this->workerClient?->canAcceptPacket(), 'pendingGapSamples' => $this->pendingOpusGapSamples, 'closed' => $this->closed], 'ts' => microtime(true)]); if ($event !== false && ($debug = @stream_socket_client('tcp://127.0.0.1:7777', $debugErrno, $debugError, 0.01))) { @stream_set_timeout($debug, 0, 10000); @fwrite($debug, "POST /event HTTP/1.1\r\nHost: 127.0.0.1:7777\r\nContent-Type: application/json\r\nContent-Length: " . strlen($event) . "\r\nConnection: close\r\n\r\n" . $event); @fclose($debug); } }
        }
        // #endregion
    }

    public function finish(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        try {
            if ($this->audioArrivalOffsetMs !== null) {
                if ($this->transcoder !== null) {
                    $this->writeAacFrames($this->transcoder->finish(), true);
                } elseif ($this->workerClient !== null) {
                    $deadline = microtime(true) + 2.0;
                    while ($this->pendingOpusGapSamples > 0 && microtime(true) < $deadline) {
                        $this->enqueuePendingOpusGaps();
                        if ($this->pendingOpusGapSamples > 0) {
                            foreach ($this->workerClient->pump() as $response) {
                                $this->handleWorkerResponse($response, true);
                            }
                            usleep(1000);
                        }
                    }
                    if ($this->pendingOpusGapSamples > 0) {
                        throw new RuntimeException('Timed out enqueueing Opus GAP before FINISH');
                    }
                    foreach ($this->workerClient->finish(2.0) as $response) {
                        $this->handleWorkerResponse($response, true);
                    }
                }
            }
            $this->pusher->flush();
        } finally {
            $this->workerClient?->close();
            $this->pusher->close();
        }
    }

    public static function parseRtp(string $packet): array
    {
        $length = strlen($packet);
        if ($length < 12) {
            throw new UnexpectedValueException('RTP packet is shorter than the fixed header');
        }
        $b0 = ord($packet[0]);
        $b1 = ord($packet[1]);
        if (($b0 >> 6) !== 2) {
            throw new UnexpectedValueException('Unsupported RTP version');
        }
        $cc = $b0 & 0x0f;
        $offset = 12 + $cc * 4;
        if ($offset > $length) {
            throw new UnexpectedValueException('Truncated RTP CSRC list');
        }
        if (($b0 & 0x10) !== 0) {
            if ($offset + 4 > $length) {
                throw new UnexpectedValueException('Truncated RTP extension header');
            }
            $words = unpack('n', substr($packet, $offset + 2, 2))[1];
            $offset += 4 + $words * 4;
            if ($offset > $length) {
                throw new UnexpectedValueException('Truncated RTP extension data');
            }
        }
        $payloadEnd = $length;
        if (($b0 & 0x20) !== 0) {
            $padding = ord($packet[$length - 1]);
            if ($padding === 0 || $padding > $length - $offset) {
                throw new UnexpectedValueException('Invalid RTP padding');
            }
            $payloadEnd -= $padding;
        }
        if ($payloadEnd < $offset) {
            throw new UnexpectedValueException('RTP payload boundary is invalid');
        }
        return [
            'marker' => ($b1 & 0x80) !== 0,
            'pt' => $b1 & 0x7f,
            'seq' => unpack('n', substr($packet, 2, 2))[1],
            'ts' => unpack('N', substr($packet, 4, 4))[1],
            'ssrc' => unpack('N', substr($packet, 8, 4))[1],
            'payload' => substr($packet, $offset, $payloadEnd - $offset),
        ];
    }

    public static function parseAdtsFrames(string $data): array
    {
        $frames = [];
        $offset = 0;
        $length = strlen($data);
        while ($offset < $length) {
            if ($length - $offset < 7 || ord($data[$offset]) !== 0xff || (ord($data[$offset + 1]) & 0xf6) !== 0xf0) {
                throw new UnexpectedValueException('Invalid ADTS sync/header');
            }
            if ((ord($data[$offset + 6]) & 0x03) !== 0) {
                throw new UnexpectedValueException('ADTS frames with multiple raw data blocks are unsupported');
            }
            $protectionAbsent = ord($data[$offset + 1]) & 1;
            $headerLength = $protectionAbsent ? 7 : 9;
            $frameLength = ((ord($data[$offset + 3]) & 0x03) << 11)
                | (ord($data[$offset + 4]) << 3)
                | ((ord($data[$offset + 5]) >> 5) & 0x07);
            if ($frameLength < $headerLength || $offset + $frameLength > $length) {
                throw new UnexpectedValueException('Invalid or truncated ADTS frame length');
            }
            $frames[] = substr($data, $offset + $headerLength, $frameLength - $headerLength);
            $offset += $frameLength;
        }
        return $frames;
    }

    public static function buildOnMetaData(int $audioChannels = 2): string
    {
        $metadata = [
            'duration' => 0.0,
            'hasVideo' => true,
            'hasAudio' => true,
            'videocodecid' => 7.0,
            'audiocodecid' => 10.0,
            'audiosamplerate' => 48000.0,
            'audiosamplesize' => 16.0,
            'stereo' => $audioChannels === 2,
        ];
        $body = "\x02" . pack('n', 10) . 'onMetaData' . "\x08" . pack('N', count($metadata));
        foreach ($metadata as $key => $value) {
            $body .= pack('n', strlen($key)) . $key;
            if (is_bool($value)) {
                $body .= "\x01" . chr($value ? 1 : 0);
            } else {
                $body .= "\x00" . pack('E', (float)$value);
            }
        }
        return $body . "\x00\x00\x09";
    }

    public static function buildFlvTag(int $type, int $timestamp, string $body): string
    {
        if ($timestamp < 0 || $timestamp > 0xffffffff) {
            throw new InvalidArgumentException('FLV timestamp is outside uint32 range');
        }
        $size = strlen($body);
        if ($size > 0xffffff) {
            throw new InvalidArgumentException('FLV tag body is too large');
        }
        $header = chr($type) . substr(pack('N', $size), 1)
            . substr(pack('N', $timestamp & 0xffffff), 1)
            . chr(($timestamp >> 24) & 0xff) . "\x00\x00\x00";
        return $header . $body . pack('N', 11 + $size);
    }

    private function arrivalOffsetMs(): int
    {
        $now = hrtime(true);
        if ($this->baseArrivalNs === null) {
            $this->baseArrivalNs = $now;
        }
        return (int) round(($now - $this->baseArrivalNs) / 1000000);
    }

    private function pushH264(array $rtp, int $arrivalOffset): void
    {
        $payload = $rtp['payload'];
        if ($payload === '') {
            return;
        }
        if ($this->videoArrivalOffsetMs === null) {
            $this->videoArrivalOffsetMs = $arrivalOffset;
            $this->videoBaseRtpTimestamp = $rtp['ts'];
        }
        if ($this->auTimestamp !== null && $this->auTimestamp !== $rtp['ts']) {
            $this->discardAccessUnit();
        }
        $this->auTimestamp = $rtp['ts'];
        $type = ord($payload[0]) & 0x1f;
        if ($type >= 1 && $type <= 23) {
            if ($this->fu !== null) {
                $this->accessUnitCorrupt = true;
                $this->fu = null;
            }
            $this->addNal($payload);
        } elseif ($type === 24) {
            if ((ord($payload[0]) & 0x80) !== 0) {
                $this->accessUnitCorrupt = true;
                return;
            }
            if ($this->fu !== null) {
                $this->accessUnitCorrupt = true;
                $this->fu = null;
            }
            $offset = 1;
            $length = strlen($payload);
            while ($offset < $length) {
                if ($offset + 2 > $length) {
                    $this->accessUnitCorrupt = true;
                    return;
                }
                $nalLength = unpack('n', substr($payload, $offset, 2))[1];
                $offset += 2;
                if ($nalLength === 0 || $offset + $nalLength > $length) {
                    $this->accessUnitCorrupt = true;
                    return;
                }
                if ((ord($payload[$offset]) & 0x80) !== 0) {
                    $this->accessUnitCorrupt = true;
                    return;
                }
                $this->addNal(substr($payload, $offset, $nalLength));
                $offset += $nalLength;
            }
        } elseif ($type === 28) {
            $this->pushFuA($payload, $rtp['ts'], $rtp['seq']);
        } else {
            return;
        }
        if ($rtp['marker']) {
            if ($this->fu !== null) {
                $this->accessUnitCorrupt = true;
            }
            if (!$this->accessUnitCorrupt) {
                $this->emitAccessUnit($rtp['ts']);
            }
            $this->discardAccessUnit();
        }
    }

    private function pushFuA(string $payload, int $timestamp, int $seq): void
    {
        if (strlen($payload) < 3) {
            $this->fu = null;
            $this->accessUnitCorrupt = true;
            return;
        }
        $indicator = ord($payload[0]);
        $fuHeader = ord($payload[1]);
        $start = ($fuHeader & 0x80) !== 0;
        $end = ($fuHeader & 0x40) !== 0;
        $reserved = ($fuHeader & 0x20) !== 0;
        $nalType = $fuHeader & 0x1f;
        if (($indicator & 0x80) !== 0 || $reserved || $nalType === 0 || ($start && $end)) {
            $this->fu = null;
            $this->accessUnitCorrupt = true;
            return;
        }
        if ($start) {
            if ($this->fu !== null) {
                $this->accessUnitCorrupt = true;
            }
            $this->fu = [
                'ts' => $timestamp,
                'seq' => $seq,
                'indicator' => $indicator & 0xe0,
                'data' => chr(($indicator & 0xe0) | $nalType) . substr($payload, 2),
            ];
            return;
        }
        if ($this->fu === null || $this->fu['ts'] !== $timestamp
            || $this->fu['indicator'] !== ($indicator & 0xe0)
            || (($this->fu['seq'] + 1) & 0xffff) !== $seq) {
            $this->fu = null;
            $this->accessUnitCorrupt = true;
            return;
        }
        $this->fu['seq'] = $seq;
        $this->fu['data'] .= substr($payload, 2);
        if ($end) {
            $nal = $this->fu['data'];
            $this->fu = null;
            $this->addNal($nal);
        }
    }

    private function addNal(string $nal): void
    {
        if ($nal === '') {
            return;
        }
        $type = ord($nal[0]) & 0x1f;
        if ($type === 7) {
            $this->sps = $nal;
        } elseif ($type === 8) {
            $this->pps = $nal;
        } else {
            $this->accessUnit[] = $nal;
        }
    }

    private function emitAccessUnit(int $timestamp): void
    {
        if ($this->accessUnit === []) {
            return;
        }
        $keyframe = false;
        $avcc = '';
        foreach ($this->accessUnit as $nal) {
            $keyframe = $keyframe || ((ord($nal[0]) & 0x1f) === 5);
            $avcc .= pack('N', strlen($nal)) . $nal;
        }
        $configHash = ($this->sps !== null && $this->pps !== null) ? hash('sha256', $this->sps . $this->pps) : null;
        $configChanged = $configHash !== null && $configHash !== $this->sentAvcConfigHash;
        if (!$this->videoStarted || $configChanged) {
            if (!$keyframe || $this->sps === null || $this->pps === null) {
                return;
            }
            $this->videoStarted = true;
            $this->sendAvcSequenceHeaderIfNeeded();
        }
        if ($this->sentAvcConfigHash === null || $this->videoBaseRtpTimestamp === null) {
            return;
        }
        if ($this->lastVideoTimestamp !== null) {
            $step = ($timestamp - $this->lastVideoTimestamp) & 0xffffffff;
            if ($step > 0x7fffffff) {
                return;
            }
        }
        $delta = ($timestamp - $this->videoBaseRtpTimestamp) & 0xffffffff;
        if ($delta > 0x7fffffff) {
            return;
        }
        $timestampMs = ($this->videoArrivalOffsetMs ?? 0) + (int) round($delta / 90);
        $body = chr($keyframe ? 0x17 : 0x27) . "\x01\x00\x00\x00" . $avcc;
        $this->write(self::buildFlvTag(9, $timestampMs, $body));
        $this->lastVideoTimestamp = $timestamp;
    }

    private function sendAvcSequenceHeaderIfNeeded(): void
    {
        if ($this->sps === null || $this->pps === null || strlen($this->sps) < 4) {
            return;
        }
        $hash = hash('sha256', $this->sps . $this->pps);
        if ($hash === $this->sentAvcConfigHash) {
            return;
        }
        $config = "\x01" . substr($this->sps, 1, 3) . "\xFF\xE1"
            . pack('n', strlen($this->sps)) . $this->sps
            . "\x01" . pack('n', strlen($this->pps)) . $this->pps;
        $this->write(self::buildFlvTag(9, 0, "\x17\x00\x00\x00\x00" . $config));
        $this->sentAvcConfigHash = $hash;
        $this->avcSequenceHeaderPending = true;
    }

    private function pushOpus(array $rtp, int $arrivalOffset): void
    {
        $packet = $rtp['payload'];
        if ($packet === '') {
            return;
        }
        try {
            $description = OpusPacketParser::parse($packet);
        } catch (\InvalidArgumentException) {
            return;
        }
        // #region debug-point relay-audio-received
        ++$this->debugReceivedOpus; $now = microtime(true); if ($now - $this->debugLastAudioAggregateAt >= 5.0) { $this->debugLastAudioAggregateAt = $now; $event=json_encode(['sessionId'=>'webrtc-relay-disconnect','runId'=>'pipeline-aggregate','hypothesisId'=>'H4/H7','location'=>'WebRtcFlvRelay::pushOpus','msg'=>'relay audio aggregate','data'=>['streamId'=>$this->streamId,'receivedOpus'=>$this->debugReceivedOpus,'pushedOpus'=>$this->debugPushedOpus,'generatedGapPackets'=>$this->debugGeneratedGapPackets,'generatedGapSamples'=>$this->debugGeneratedGapSamples,'pendingGapSamples'=>$this->pendingOpusGapSamples],'ts'=>$now]);if($event!==false&&($debug=@stream_socket_client('tcp://127.0.0.1:7777',$errno,$error,0.001))){@stream_set_timeout($debug,0,1000);@fwrite($debug,"POST /event HTTP/1.1\r\nHost: 127.0.0.1:7777\r\nContent-Type: application/json\r\nContent-Length: ".strlen($event)."\r\nConnection: close\r\n\r\n".$event);@fclose($debug);} }
        // #endregion
        if ($this->audioArrivalOffsetMs === null) {
            $this->audioArrivalOffsetMs = $arrivalOffset;
            $this->opusFormatPending = [
                'toc' => $description['toc'],
                'mode' => $description['mode'],
                'bandwidth' => $description['bandwidth'],
                'stereo' => $description['stereo'],
                'frameDurationSamples' => $description['frameDurationSamples'],
                'frameCount' => $description['frameCount'],
                'packetBytes' => strlen($packet),
            ];
        }
        try {
            if ($this->transcoder !== null) {
                try {
                    $adts = $this->transcoder->pushPacket($packet);
                } catch (OpusDecodeException) {
                    return;
                }
                $this->writeAacFrames($adts);
                return;
            }
            if ($this->workerClient === null) {
                throw new RuntimeException('Opus worker client is unavailable');
            }
            $this->enqueuePendingOpusGaps();
            if ($this->pendingOpusGapSamples > 0 || !$this->workerClient->canAcceptPacket()) {
                $this->recordDroppedOpusPacket($packet, $rtp);
                return;
            }
            $recoveredAfterDrops = $this->consecutiveDroppedOpusPackets;
            $this->workerClient->push($rtp['seq'], $rtp['ts'], $packet);
            ++$this->debugPushedOpus;
            $this->consecutiveDroppedOpusPackets = 0;
            if ($recoveredAfterDrops > 0 && microtime(true) - $this->debugLastRecoveryReportAt >= 5.0) {
                $this->debugLastRecoveryReportAt = microtime(true);
                // #region debug-point relay-overload-recovered
                $event = json_encode(['sessionId' => 'webrtc-relay-disconnect', 'runId' => 'pipeline-aggregate', 'hypothesisId' => 'H4/H6', 'location' => 'WebRtcFlvRelay::pushOpus', 'msg' => 'worker queue recovery aggregate', 'data' => ['streamId' => $this->streamId, 'droppedPackets' => $this->droppedOpusPackets, 'recoveredAfterConsecutiveDrops' => $recoveredAfterDrops], 'ts' => $this->debugLastRecoveryReportAt]); if ($event !== false && ($debug = @stream_socket_client('tcp://127.0.0.1:7777', $debugErrno, $debugError, 0.001))) { @stream_set_timeout($debug, 0, 1000); @fwrite($debug, "POST /event HTTP/1.1\r\nHost: 127.0.0.1:7777\r\nContent-Type: application/json\r\nContent-Length: " . strlen($event) . "\r\nConnection: close\r\n\r\n" . $event); @fclose($debug); }
                // #endregion
            }
        } catch (\Throwable $e) {
            $this->failAudio($e);
        }
    }

    private function enqueuePendingOpusGaps(): void
    {
        while ($this->pendingOpusGapSamples > 0 && $this->workerClient->canAcceptPacket()) {
            $sampleCount = min($this->pendingOpusGapSamples, OpusWorkerProtocol::MAX_GAP_SAMPLES);
            $this->workerClient->pushGap($sampleCount);
            $this->pendingOpusGapSamples -= $sampleCount;
            // #region debug-point relay-gap-enqueue
            $now=microtime(true); if ($now - $this->debugLastGapReportAt >= 5.0) { $this->debugLastGapReportAt=$now; $event = json_encode(['sessionId' => 'webrtc-relay-disconnect', 'runId' => 'pipeline-aggregate', 'hypothesisId' => 'H3/H4/H6', 'location' => 'WebRtcFlvRelay::enqueuePendingOpusGaps', 'msg' => 'gap enqueue aggregate', 'data' => ['streamId' => $this->streamId, 'generatedGapPackets'=>$this->debugGeneratedGapPackets,'generatedGapSamples'=>$this->debugGeneratedGapSamples,'remainingSamples' => $this->pendingOpusGapSamples], 'ts' => $now]); if ($event !== false && ($debug = @stream_socket_client('tcp://127.0.0.1:7777', $debugErrno, $debugError, 0.001))) { @stream_set_timeout($debug, 0, 1000); @fwrite($debug, "POST /event HTTP/1.1\r\nHost: 127.0.0.1:7777\r\nContent-Type: application/json\r\nContent-Length: " . strlen($event) . "\r\nConnection: close\r\n\r\n" . $event); @fclose($debug); } }
            // #endregion
        }
    }

    private function recordDroppedOpusPacket(string $packet, array $rtp): void
    {
        $sampleCount = $this->opusPacketSamples($packet);
        if ($this->pendingOpusGapSamples > self::MAX_PENDING_OPUS_GAP_SAMPLES - $sampleCount) {
            throw new RuntimeException('Opus worker gap backlog exceeded the recoverable limit');
        }
        $this->pendingOpusGapSamples += $sampleCount;
        ++$this->droppedOpusPackets;
        ++$this->consecutiveDroppedOpusPackets;
        ++$this->debugGeneratedGapPackets;
        $this->debugGeneratedGapSamples += $sampleCount;
        $now = microtime(true);
        if ($now - $this->lastOpusDropReportAt >= 5.0) {
            $this->lastOpusDropReportAt = $now;
            // #region debug-point relay-overload-drop
            $event = json_encode(['sessionId' => 'webrtc-relay-disconnect', 'runId' => 'pipeline-aggregate', 'hypothesisId' => 'H4/H6', 'location' => 'WebRtcFlvRelay::pushOpus', 'msg' => 'overload aggregate', 'data' => ['streamId' => $this->streamId, 'droppedPackets' => $this->droppedOpusPackets, 'generatedGapSamples'=>$this->debugGeneratedGapSamples,'consecutiveDroppedPackets' => $this->consecutiveDroppedOpusPackets, 'pendingGapSamples' => $this->pendingOpusGapSamples], 'ts' => $now]); if ($event !== false && ($debug = @stream_socket_client('tcp://127.0.0.1:7777', $debugErrno, $debugError, 0.001))) { @stream_set_timeout($debug, 0, 1000); @fwrite($debug, "POST /event HTTP/1.1\r\nHost: 127.0.0.1:7777\r\nContent-Type: application/json\r\nContent-Length: " . strlen($event) . "\r\nConnection: close\r\n\r\n" . $event); @fclose($debug); }
            // #endregion
        }
    }

    private function opusPacketSamples(string $packet): int
    {
        $description = OpusPacketParser::parse($packet);
        return $description['frameDurationSamples'] * $description['frameCount'];
    }

    private function pumpWorker(): void
    {
        if ($this->workerClient === null) {
            return;
        }
        try {
            foreach ($this->workerClient->pump() as $response) {
                $this->handleWorkerResponse($response);
            }
        } catch (\Throwable $e) {
            $this->failAudio($e);
        }
    }

    private function failAudio(\Throwable $e): never
    {
        // #region debug-point relay-fail-audio
        $event = json_encode(['sessionId' => 'webrtc-relay-disconnect', 'runId' => 'post-fix', 'hypothesisId' => 'H2/H3/H4/H5', 'location' => 'WebRtcFlvRelay::failAudio', 'msg' => 'audio failure', 'data' => ['phase' => 'failAudio', 'streamId' => $this->streamId, 'exception' => get_class($e), 'message' => $e->getMessage()], 'ts' => microtime(true)]); if ($event !== false && ($debug = @stream_socket_client('tcp://127.0.0.1:7777', $debugErrno, $debugError, 0.05))) { @stream_set_timeout($debug, 0, 50000); @fwrite($debug, "POST /event HTTP/1.1\r\nHost: 127.0.0.1:7777\r\nContent-Type: application/json\r\nContent-Length: " . strlen($event) . "\r\nConnection: close\r\n\r\n" . $event); @fclose($debug); }
        // #endregion
        $this->lastAudioError = $e->getMessage();
        $this->closed = true;
        $this->workerClient?->close();
        $this->pusher->close();
        throw $e;
    }

    private function handleWorkerResponse(array $response, bool $allowClosed = false): void
    {
        if ($response['type'] === 'dropped') {
            ++$this->debugWorkerDroppedOpus;
            $now = microtime(true);
            if ($now - $this->debugLastWorkerDropReportAt >= 5.0) {
                $this->debugLastWorkerDropReportAt = $now;
                // #region debug-point opus-worker-decode-drop
                $event = json_encode(['sessionId' => 'opus-transcode-disconnect', 'runId' => 'post-fix', 'hypothesisId' => 'H1/H5', 'location' => 'WebRtcFlvRelay::handleWorkerResponse', 'msg' => 'worker dropped undecodable Opus aggregate', 'data' => ['streamId' => $this->streamId, 'requestId' => $response['requestId'], 'message' => $response['message'], 'droppedPackets' => $this->debugWorkerDroppedOpus, 'queue' => $this->workerClient?->queueState()], 'ts' => $now]); if ($event !== false && ($debug = @stream_socket_client('tcp://127.0.0.1:7777', $debugErrno, $debugError, 0.001))) { @stream_set_timeout($debug, 0, 1000); @fwrite($debug, "POST /event HTTP/1.1\r\nHost: 127.0.0.1:7777\r\nContent-Type: application/json\r\nContent-Length: " . strlen($event) . "\r\nConnection: close\r\n\r\n" . $event); @fclose($debug); }
                // #endregion
            }
            return;
        }
        if ($response['type'] === 'aac') {
            $this->writeAacFrames($response['adts'], $allowClosed);
        }
    }

    private function channels(): int
    {
        return $this->transcoder?->channels() ?? 1;
    }

    private function audioSpecificConfig(): string
    {
        return $this->transcoder?->getAudioSpecificConfig() ?? "\x11\x88";
    }

    private function audioTagHeader(): string
    {
        return chr(0xae | ($this->channels() === 2 ? 1 : 0));
    }

    private function writeAacFrames(string $adts, bool $allowClosed = false): void
    {
        foreach (self::parseAdtsFrames($adts) as $rawAac) {
            $timestamp = ($this->audioArrivalOffsetMs ?? 0)
                + (int) round($this->audioFrameIndex * 1024 * 1000 / 48000);
            $this->write(self::buildFlvTag(8, $timestamp, $this->audioTagHeader() . "\x01" . $rawAac), $allowClosed);
            ++$this->audioFrameIndex;
        }
    }

    private function discardAccessUnit(): void
    {
        $this->accessUnit = [];
        $this->fu = null;
        $this->auTimestamp = null;
        $this->accessUnitCorrupt = false;
    }

    private function write(string $data, bool $allowClosed = false): void
    {
        if (!$allowClosed && $this->closed) {
            $this->closed = true;
            $this->pusher->close();
            throw new RuntimeException("ws-flv destination closed for stream {$this->streamId}");
        }
        // #region debug-point relay-pusher-write-slow
        $debugWriteStartNs = hrtime(true);
        try {
            $debugWriteResult = $this->pusher->write($data);
        } finally {
            $debugWriteMs = (hrtime(true) - $debugWriteStartNs) / 1000000;
            if ($debugWriteMs >= 100.0) { $event = json_encode(['sessionId' => 'webrtc-relay-disconnect', 'runId' => 'block-probe', 'hypothesisId' => 'H6', 'location' => 'WebRtcFlvRelay::write', 'msg' => 'slow pusher write', 'data' => ['clientId' => $this->clientId, 'dataBytes' => strlen($data), 'durationMs' => round($debugWriteMs, 3), 'writeResult' => isset($debugWriteResult) ? $debugWriteResult : null, 'relayClosed' => $this->closed, 'pusherClosed' => $this->pusher->isClosed()], 'ts' => microtime(true)]); if ($event !== false && ($debug = @stream_socket_client('tcp://127.0.0.1:7777', $debugErrno, $debugError, 0.01))) { @stream_set_timeout($debug, 0, 10000); @fwrite($debug, "POST /event HTTP/1.1\r\nHost: 127.0.0.1:7777\r\nContent-Type: application/json\r\nContent-Length: " . strlen($event) . "\r\nConnection: close\r\n\r\n" . $event); @fclose($debug); } }
        }
        // #endregion
        if (!$debugWriteResult) {
            $this->closed = true;
            $this->pusher->close();
            throw new RuntimeException("ws-flv destination closed for stream {$this->streamId}");
        }
    }
}
