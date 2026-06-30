<?php

namespace Xiaosongshu\Flv2mp4\Flv;

use Xiaosongshu\Flv2mp4\SabreAMF\RtmpPullerClient;
use Xiaosongshu\Flv2mp4\SabreAMF\RtmpPushFlvClient;

/**
 * @purpose 直播转发工具
 * @author yanglong
 * @time 2026年6月30日18:26:10
 * @note 支持rtm/ws-flv/http-flv拉流，rtmp推流
 * @comment ws-flv推流暂时没有实现
 */
class FlvForwardClient
{
    protected string $pullUrl;
    protected array $pushUrls;
    protected int $duration;
    protected bool $autoReconnect;
    protected bool $isRunning = true;

    protected $upstreamClient = null;
    protected string $upstreamProtocol = '';
    protected bool $upstreamConnected = false;
    protected bool $upstreamChunked = false;
    protected string $upstreamBuffer = '';
    protected string $upstreamChunkBuffer = '';

    protected array $downstreamClients = [];
    protected array $downstreamStats = [];

    protected int $retryCount = 0;
    protected int $maxRetries = 5;
    protected int $retryDelay = 3;

    protected array $stats = [
        'tags_received' => 0,
        'tags_sent' => 0,
        'audio_tags' => 0,
        'video_tags' => 0,
        'bytes_received' => 0,
        'bytes_sent' => 0,
        'start_time' => 0,
        'last_report_time' => 0,
        'reconnect_count' => 0,
    ];

    protected int $lastStatsTime = 0;
    protected int $statsInterval = 5;

    protected string $metaDataTag = '';
    protected string $videoSequenceTag = '';
    protected string $audioSequenceTag = '';
    protected bool $initDataReady = false;

    const SCRIPT_TAG = 18;
    const AUDIO_TAG = 8;
    const VIDEO_TAG = 9;

    const VIDEO_FRAME_TYPE_KEY_FRAME = 1;
    const VIDEO_CODEC_ID_AVC = 7;
    const AVC_PACKET_TYPE_SEQUENCE_HEADER = 0;

    const SOUND_FORMAT_AAC = 10;
    const AAC_PACKET_TYPE_SEQUENCE_HEADER = 0;

    public function __construct(string $pullUrl, array $pushUrls, int $duration = 0, bool $autoReconnect = true)
    {
        $this->pullUrl = $pullUrl;
        $this->pushUrls = $pushUrls;
        $this->duration = $duration;
        $this->autoReconnect = $autoReconnect;

        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, [$this, 'handleSignal']);
            pcntl_signal(SIGTERM, [$this, 'handleSignal']);
        }
    }

    public function handleSignal(int $signal): void
    {
        $this->log("收到信号 {$signal}，正在停止转发...", 'warning');
        $this->isRunning = false;
    }

    public function start(): void
    {
        $this->stats['start_time'] = microtime(true);
        $this->stats['last_report_time'] = $this->stats['start_time'];

        $this->log("========================================");
        $this->log("FLV Forwarder v1.0.0");
        $this->log("========================================");
        $this->log("拉流地址: {$this->pullUrl}");
        $this->log("推流地址: " . implode(', ', $this->pushUrls));
        $this->log("转发时长: " . ($this->duration > 0 ? "{$this->duration} 秒" : "不限"));
        $this->log("自动重连: " . ($this->autoReconnect ? '是' : '否'));
        $this->log("========================================");

        while ($this->isRunning) {
            try {
                $this->connectUpstream();
                $this->connectAllDownstreams();

                if ($this->upstreamConnected && !empty($this->downstreamClients)) {
                    $this->log("所有连接已建立，开始转发", 'success');
                    $this->forwardData();
                }

                $this->disconnect();
            } catch (\Exception $e) {
                $this->log("转发异常: {$e->getMessage()}", 'error');
                $this->disconnect();
            }

            if (!$this->isRunning) break;

            if ($this->autoReconnect && $this->retryCount < $this->maxRetries) {
                $this->retryCount++;
                $this->stats['reconnect_count']++;
                $this->log("{$this->retryDelay} 秒后进行第 {$this->retryCount} 次重试...", 'warning');
                sleep($this->retryDelay);
            } else {
                $this->log("转发结束", 'info');
                break;
            }
        }

        $this->printFinalStats();
    }

    protected function connectUpstream(): void
    {
        $urlParts = parse_url($this->pullUrl);
        $scheme = strtolower($urlParts['scheme'] ?? 'http');

        $this->log("连接上游 {$scheme}://{$urlParts['host']}...");

        switch ($scheme) {
            case 'rtmp':
            case 'rtmps':
                $this->upstreamProtocol = 'rtmp';
                $this->connectRtmpUpstream();
                break;
            case 'ws':
            case 'wss':
                $this->upstreamProtocol = 'ws';
                $this->connectWsUpstream($urlParts);
                break;
            default:
                $this->upstreamProtocol = 'http';
                $this->connectHttpUpstream($urlParts);
                break;
        }

        $this->log("上游连接成功，协议: {$this->upstreamProtocol}", 'success');
        $this->upstreamConnected = true;
    }

    protected function connectRtmpUpstream(): void
    {
        $this->upstreamClient = new RtmpPullerClient($this->pullUrl, '', 0, true);
        
        $reflection = new \ReflectionClass($this->upstreamClient);
        $connectMethod = $reflection->getMethod('connect');
        $connectMethod->setAccessible(true);
        $connectMethod->invoke($this->upstreamClient);
    }

    protected function connectWsUpstream(array $urlParts): void
    {
        $host = $urlParts['host'] ?? '127.0.0.1';
        $port = $urlParts['port'] ?? 8501;
        $path = $urlParts['path'] ?? '/';
        $ssl = ($urlParts['scheme'] === 'wss');

        $proto = $ssl ? 'ssl' : 'tcp';
        $this->upstreamClient = @stream_socket_client("{$proto}://{$host}:{$port}", $errno, $errstr, 10);
        if (!$this->upstreamClient) {
            throw new \RuntimeException("无法连接到 {$host}:{$port} - {$errstr}");
        }

        stream_set_timeout($this->upstreamClient, 10);

        $key = base64_encode(random_bytes(16));
        $handshake = "GET {$path} HTTP/1.1\r\n";
        $handshake .= "Host: {$host}:{$port}\r\n";
        $handshake .= "Upgrade: websocket\r\n";
        $handshake .= "Connection: Upgrade\r\n";
        $handshake .= "Sec-WebSocket-Key: {$key}\r\n";
        $handshake .= "Sec-WebSocket-Version: 13\r\n";
        $handshake .= "\r\n";

        fwrite($this->upstreamClient, $handshake);

        $response = fread($this->upstreamClient, 1024);
        if (!str_contains($response, '101 Switching Protocols')) {
            $this->safeCloseStream($this->upstreamClient);
            $this->upstreamClient = null;
            throw new \RuntimeException("WebSocket握手失败");
        }

        stream_set_blocking($this->upstreamClient, false);
    }

    protected function connectHttpUpstream(array $urlParts): void
    {
        $host = $urlParts['host'] ?? '127.0.0.1';
        $port = $urlParts['port'] ?? 8501;
        $path = $urlParts['path'] ?? '/';
        if (!empty($urlParts['query'])) {
            $path .= '?' . $urlParts['query'];
        }

        $this->upstreamClient = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 10);
        if (!$this->upstreamClient) {
            throw new \RuntimeException("无法连接到 {$host}:{$port} - {$errstr}");
        }

        stream_set_timeout($this->upstreamClient, 10);

        $request = "GET {$path} HTTP/1.1\r\n";
        $request .= "Host: {$host}\r\n";
        $request .= "Accept: */*\r\n";
        $request .= "Connection: keep-alive\r\n";
        $request .= "\r\n";

        fwrite($this->upstreamClient, $request);

        $header = '';
        $timeout = time() + 10;

        while (time() < $timeout) {
            $chunk = @fread($this->upstreamClient, 4096);
            if ($chunk === false) {
                throw new \RuntimeException("读取响应失败");
            }
            if ($chunk === '') {
                usleep(10000);
                continue;
            }

            $header .= $chunk;
            if (($pos = strpos($header, "\r\n\r\n")) !== false) {
                $headerStr = substr($header, 0, $pos);
                $bodyData = substr($header, $pos + 4);

                if (!preg_match('#^HTTP/\d\.\d 200#', $headerStr)) {
                    $this->safeCloseStream($this->upstreamClient);
                    $this->upstreamClient = null;
                    throw new \RuntimeException("上游返回非200状态码");
                }

                $this->upstreamChunked = (stripos($headerStr, "Transfer-Encoding: chunked") !== false);

                if (strlen($bodyData) > 0) {
                    if ($this->upstreamChunked) {
                        $this->upstreamChunkBuffer = $bodyData;
                    } else {
                        $this->upstreamBuffer = $bodyData;
                    }
                }

                stream_set_blocking($this->upstreamClient, false);
                return;
            }
        }

        $this->safeCloseStream($this->upstreamClient);
        $this->upstreamClient = null;
        throw new \RuntimeException("上游响应超时");
    }

    protected function connectAllDownstreams(): void
    {
        $this->downstreamClients = [];

        foreach ($this->pushUrls as $idx => $url) {
            $this->connectDownstream($idx, $url);
        }
    }

    protected function connectDownstream(int $idx, string $url): void
    {
        $urlParts = parse_url($url);
        $scheme = strtolower($urlParts['scheme'] ?? 'http');

        if ($scheme !== 'rtmp' && $scheme !== 'rtmps') {
            $this->log("下游协议不支持: {$scheme}", 'error');
            return;
        }

        try {
            $rtmpClient = new RtmpPushFlvClient('', $url);

            $reflection = new \ReflectionClass($rtmpClient);
            $connectMethod = $reflection->getMethod('connect');
            $connectMethod->setAccessible(true);

            $host = $reflection->getProperty('host');
            $host->setAccessible(true);
            $app = $reflection->getProperty('app');
            $app->setAccessible(true);
            $port = $reflection->getProperty('port');
            $port->setAccessible(true);

            $connectMethod->invoke($rtmpClient, $host->getValue($rtmpClient), $app->getValue($rtmpClient), $port->getValue($rtmpClient));

            $fcPublishMethod = $reflection->getMethod('fcPublish');
            $fcPublishMethod->setAccessible(true);
            $streamKey = $reflection->getProperty('streamKey');
            $streamKey->setAccessible(true);
            $fcPublishMethod->invoke($rtmpClient, $streamKey->getValue($rtmpClient));

            $publishMethod = $reflection->getMethod('publish');
            $publishMethod->setAccessible(true);
            $publishMethod->invoke($rtmpClient, $streamKey->getValue($rtmpClient), 'live');

            $this->downstreamClients[$idx] = [
                'url' => $url,
                'client' => $rtmpClient,
                'connected' => true,
            ];

            $this->downstreamStats[$idx] = [
                'tags_sent' => 0,
                'bytes_sent' => 0,
            ];

            $this->log("下游RTMP连接成功: {$url}", 'success');
        } catch (\Exception $e) {
            $this->log("下游RTMP连接失败: {$url} - {$e->getMessage()}", 'error');
        }
    }

    protected function forwardData(): void
    {
        $this->metaDataTag = '';
        $this->videoSequenceTag = '';
        $this->audioSequenceTag = '';
        $this->initDataReady = false;
        $this->upstreamBuffer = '';
        $this->upstreamChunkBuffer = '';

        while ($this->isRunning) {
            $data = $this->readUpstreamData();
            if ($data === null) {
                if ($this->upstreamProtocol === 'rtmp') {
                    break;
                }
                usleep(10000);
                continue;
            }

            $this->stats['bytes_received'] += strlen($data);

            if ($this->upstreamProtocol === 'http') {
                $this->processUpstreamData($data);
            } else {
                if (strlen($data) >= 15) {
                    $tagType = ord($data[0]);
                    $dataSize = (ord($data[1]) << 16) | (ord($data[2]) << 8) | ord($data[3]);
                    $timestamp = (ord($data[4]) << 16) | (ord($data[5]) << 8) | ord($data[6]);
                    $timestampExt = ord($data[7]);
                    $fullTimestamp = ($timestampExt << 24) | $timestamp;
                    $payload = substr($data, 11, $dataSize);

                    $this->stats['tags_received']++;

                    switch ($tagType) {
                        case self::AUDIO_TAG:
                            $this->stats['audio_tags']++;
                            if (!$this->initDataReady) {
                                $soundFormat = (ord($payload[0]) >> 4) & 0x0F;
                                if ($soundFormat === self::SOUND_FORMAT_AAC) {
                                    $aacPacketType = ord($payload[1]);
                                    if ($aacPacketType === self::AAC_PACKET_TYPE_SEQUENCE_HEADER) {
                                        $this->audioSequenceTag = $data;
                                        $this->log("收到音频序列头", 'debug');
                                    }
                                } else {
                                    $this->audioSequenceTag = $data;
                                }
                            }
                            break;
                        case self::VIDEO_TAG:
                            $this->stats['video_tags']++;
                            if (!$this->initDataReady) {
                                $frameType = (ord($payload[0]) >> 4) & 0x0F;
                                $codecId = ord($payload[0]) & 0x0F;
                                if ($codecId === self::VIDEO_CODEC_ID_AVC && $frameType === self::VIDEO_FRAME_TYPE_KEY_FRAME) {
                                    $avcPacketType = ord($payload[1]);
                                    if ($avcPacketType === self::AVC_PACKET_TYPE_SEQUENCE_HEADER) {
                                        $this->videoSequenceTag = $data;
                                        $this->log("收到视频序列头", 'debug');
                                    }
                                }
                            }
                            break;
                        case self::SCRIPT_TAG:
                            if (!$this->initDataReady) {
                                $this->metaDataTag = $data;
                                $this->log("收到元数据", 'debug');
                            }
                            break;
                    }

                    if (!$this->initDataReady && $this->videoSequenceTag && $this->audioSequenceTag) {
                        $this->initDataReady = true;
                        $this->log("初始化数据就绪，准备转发", 'success');

                        foreach ($this->downstreamClients as $idx => &$downstream) {
                            if ($downstream['connected']) {
                                if ($this->metaDataTag) {
                                    $this->sendRtmpData($downstream['client'], $this->metaDataTag);
                                }
                                $this->sendRtmpData($downstream['client'], $this->videoSequenceTag);
                                $this->sendRtmpData($downstream['client'], $this->audioSequenceTag);
                            }
                        }
                    }

                    $this->sendToAllDownstreams($data);
                }
            }

            $this->checkStats();

            if ($this->duration > 0) {
                $elapsed = microtime(true) - $this->stats['start_time'];
                if ($elapsed >= $this->duration) {
                    $this->log("转发时长已到", 'info');
                    break;
                }
            }
        }
    }

    protected function processUpstreamData(string $data): void
    {
        if ($this->upstreamChunked) {
            $this->upstreamChunkBuffer .= $data;
            $decoded = $this->decodeChunked($this->upstreamChunkBuffer);
            if ($decoded !== null) {
                $this->upstreamBuffer .= $decoded;
            }
        } else {
            $this->upstreamBuffer .= $data;
        }

        $this->processFlvBuffer();
    }

    protected function processFlvBuffer(): void
    {
        while (strlen($this->upstreamBuffer) >= 15) {
            $tagType = ord($this->upstreamBuffer[0]);
            $dataSize = (ord($this->upstreamBuffer[1]) << 16) | (ord($this->upstreamBuffer[2]) << 8) | ord($this->upstreamBuffer[3]);
            $totalSize = 11 + $dataSize + 4;

            if (strlen($this->upstreamBuffer) < $totalSize) {
                break;
            }

            $tag = substr($this->upstreamBuffer, 0, $totalSize);
            $this->upstreamBuffer = substr($this->upstreamBuffer, $totalSize);

            $this->stats['tags_received']++;
            if ($tagType === self::AUDIO_TAG) {
                $this->stats['audio_tags']++;
            } elseif ($tagType === self::VIDEO_TAG) {
                $this->stats['video_tags']++;
            }

            if (!$this->initDataReady) {
                if ($tagType === self::SCRIPT_TAG && !$this->metaDataTag) {
                    $this->metaDataTag = $tag;
                    $this->log("收到MetaData", 'debug');
                } elseif ($tagType === self::VIDEO_TAG && !$this->videoSequenceTag) {
                    $payload = substr($tag, 11, $dataSize);
                    if (strlen($payload) >= 2) {
                        $firstByte = ord($payload[0]);
                        $frameType = ($firstByte >> 4) & 0x0F;
                        $codecId = $firstByte & 0x0F;
                        if ($codecId === self::VIDEO_CODEC_ID_AVC && strlen($payload) >= 5) {
                            $avcPacketType = ord($payload[1]);
                            if ($avcPacketType === self::AVC_PACKET_TYPE_SEQUENCE_HEADER) {
                                $this->videoSequenceTag = $tag;
                                $this->log("收到视频序列头", 'debug');
                            }
                        }
                    }
                } elseif ($tagType === self::AUDIO_TAG && !$this->audioSequenceTag) {
                    $payload = substr($tag, 11, $dataSize);
                    if (strlen($payload) >= 2) {
                        $firstByte = ord($payload[0]);
                        $soundFormat = ($firstByte >> 4) & 0x0F;
                        if ($soundFormat === self::SOUND_FORMAT_AAC) {
                            $aacPacketType = ord($payload[1]);
                            if ($aacPacketType === self::AAC_PACKET_TYPE_SEQUENCE_HEADER) {
                                $this->audioSequenceTag = $tag;
                                $this->log("收到音频序列头", 'debug');
                            }
                        }
                    }
                }

                if ($this->videoSequenceTag && $this->audioSequenceTag) {
                    $this->initDataReady = true;
                    $this->log("初始化数据就绪，准备转发", 'success');
                    if ($this->metaDataTag) {
                        $this->sendToAllDownstreams($this->metaDataTag);
                    }
                    $this->sendToAllDownstreams($this->videoSequenceTag);
                    $this->sendToAllDownstreams($this->audioSequenceTag);
                }
            }

            $this->sendToAllDownstreams($tag);
        }
    }

    protected function readUpstreamData(): ?string
    {
        if ($this->upstreamProtocol === 'rtmp') {
            $reflection = new \ReflectionClass($this->upstreamClient);
            $receiveMethod = $reflection->getMethod('receiveData');
            $receiveMethod->setAccessible(true);
            return $receiveMethod->invoke($this->upstreamClient);
        }

        if ($this->upstreamProtocol === 'ws') {
            return $this->readWsData();
        }

        return $this->readHttpData();
    }

    protected function readWsData(): ?string
    {
        if (!$this->isStreamValid($this->upstreamClient)) return null;

        $header = @fread($this->upstreamClient, 2);
        if ($header === false || strlen($header) < 2) return null;

        $fin = (ord($header[0]) >> 7) & 0x01;
        $opcode = ord($header[0]) & 0x0F;
        $masked = (ord($header[1]) >> 7) & 0x01;
        $payloadLen = ord($header[1]) & 0x7F;

        if ($payloadLen === 126) {
            $ext = @fread($this->upstreamClient, 2);
            if ($ext === false || strlen($ext) < 2) return null;
            $payloadLen = unpack('n', $ext)[1];
        } elseif ($payloadLen === 127) {
            $ext = @fread($this->upstreamClient, 8);
            if ($ext === false || strlen($ext) < 8) return null;
            $payloadLen = unpack('J', $ext)[1];
        }

        $mask = '';
        if ($masked) {
            $mask = @fread($this->upstreamClient, 4);
            if ($mask === false || strlen($mask) < 4) return null;
        }

        $data = '';
        while (strlen($data) < $payloadLen) {
            $chunk = @fread($this->upstreamClient, $payloadLen - strlen($data));
            if ($chunk === false) {
                return null;
            }
            $data .= $chunk;
        }

        if ($masked) {
            for ($i = 0; $i < strlen($data); $i++) {
                $data[$i] = chr(ord($data[$i]) ^ ord($mask[$i % 4]));
            }
        }

        return $data;
    }

    protected function readHttpData(): ?string
    {
        if (!$this->isStreamValid($this->upstreamClient)) return null;

        $data = @fread($this->upstreamClient, 65536);
        if ($data === false) {
            $info = stream_get_meta_data($this->upstreamClient);
            if (!empty($info['eof'])) {
                throw new \RuntimeException("HTTP上游连接已关闭");
            }
            return null;
        }

        if ($data === '') {
            $info = stream_get_meta_data($this->upstreamClient);
            if (!empty($info['eof'])) {
                throw new \RuntimeException("HTTP上游连接已关闭");
            }
            return null;
        }

        return $data;
    }

    protected function decodeChunked(string &$buf): ?string
    {
        $decoded = '';
        while (true) {
            $pos = strpos($buf, "\r\n");
            if ($pos === false) break;

            $sizeHex = trim(substr($buf, 0, $pos));
            if ($sizeHex === '') {
                $buf = substr($buf, $pos + 2);
                continue;
            }

            if (!ctype_xdigit($sizeHex)) {
                $buf = substr($buf, $pos + 2);
                continue;
            }

            $size = hexdec($sizeHex);
            if ($size === 0) {
                $buf = '';
                return $decoded;
            }

            $start = $pos + 2;
            $end = $start + $size + 2;

            if (strlen($buf) < $end) {
                break;
            }

            $decoded .= substr($buf, $start, $size);
            $buf = substr($buf, $end);
        }

        return $decoded === '' ? null : $decoded;
    }

    protected function sendToAllDownstreams(string $data): void
    {
        foreach ($this->downstreamClients as $idx => &$downstream) {
            if ($downstream['connected']) {
                $this->sendRtmpData($downstream['client'], $data);
                $this->downstreamStats[$idx]['tags_sent']++;
                $this->stats['tags_sent']++;
            }
        }
    }

    protected function sendRtmpData($rtmpClient, string $data): void
    {
        if (strlen($data) < 15) {
            return;
        }

        $tagType = ord($data[0]);
        $dataSize = (ord($data[1]) << 16) | (ord($data[2]) << 8) | ord($data[3]);
        $timestamp = (ord($data[4]) << 16) | (ord($data[5]) << 8) | ord($data[6]);
        $timestampExt = ord($data[7]);
        $fullTimestamp = ($timestampExt << 24) | $timestamp;
        $payload = substr($data, 11, $dataSize);

        $reflection = new \ReflectionClass($rtmpClient);

        switch ($tagType) {
            case self::AUDIO_TAG:
                $sendAudioMethod = $reflection->getMethod('sendAudioData');
                $sendAudioMethod->setAccessible(true);
                $sendAudioMethod->invoke($rtmpClient, $payload, $fullTimestamp);
                break;
            case self::VIDEO_TAG:
                $sendVideoMethod = $reflection->getMethod('sendVideoData');
                $sendVideoMethod->setAccessible(true);
                $sendVideoMethod->invoke($rtmpClient, $payload, $fullTimestamp);
                break;
            case self::SCRIPT_TAG:
                $sendMetaMethod = $reflection->getMethod('sendMetaData');
                $sendMetaMethod->setAccessible(true);
                $sendMetaMethod->invoke($rtmpClient, $payload, $fullTimestamp);
                break;
        }
    }

    protected function checkStats(): void
    {
        $now = microtime(true);
        if ($now - $this->stats['last_report_time'] >= $this->statsInterval) {
            $this->printStats();
            $this->stats['last_report_time'] = $now;
        }
    }

    protected function disconnect(): void
    {
        if ($this->upstreamProtocol === 'rtmp' && $this->upstreamClient) {
            $reflection = new \ReflectionClass($this->upstreamClient);
            $disconnectMethod = $reflection->getMethod('disconnect');
            $disconnectMethod->setAccessible(true);
            $disconnectMethod->invoke($this->upstreamClient);
        } else {
            $this->safeCloseStream($this->upstreamClient);
        }

        foreach ($this->downstreamClients as $downstream) {
            if ($downstream['connected']) {
                $reflection = new \ReflectionClass($downstream['client']);
                $closeMethod = $reflection->getMethod('close');
                $closeMethod->setAccessible(true);
                $closeMethod->invoke($downstream['client']);
            }
        }

        $this->upstreamClient = null;
        $this->downstreamClients = [];
        $this->upstreamConnected = false;
    }

    protected function isStreamValid($stream): bool
    {
        return $stream !== null && is_resource($stream) && get_resource_type($stream) === 'stream';
    }

    protected function safeCloseStream($stream): void
    {
        if ($this->isStreamValid($stream)) {
            @fclose($stream);
        }
    }

    protected function log(string $message, string $level = 'info'): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $levelUpper = strtoupper($level);
        $prefix = '[' . $timestamp . '] [' . $levelUpper . '] ';
        echo $prefix . $message . "\n";
    }

    protected function printStats(): void
    {
        $now = microtime(true);
        $elapsed = $now - $this->stats['start_time'];
        $elapsedFormatted = gmdate('H:i:s', (int)$elapsed);

        $tagsPerSec = $this->stats['tags_received'] / max(1, $elapsed);
        $kbps = ($this->stats['bytes_received'] * 8) / max(1, $elapsed) / 1000;

        echo "\n";
        $this->log("========================================");
        $this->log("转发统计");
        $this->log("========================================");
        $this->log("运行时间: {$elapsedFormatted}");
        $this->log("接收 Tag 数: {$this->stats['tags_received']}");
        $this->log("  - 音频: {$this->stats['audio_tags']}");
        $this->log("  - 视频: {$this->stats['video_tags']}");
        $this->log("发送 Tag 数: {$this->stats['tags_sent']}");
        $this->log("接收字节: " . $this->formatBytes($this->stats['bytes_received']));
        $this->log("发送字节: " . $this->formatBytes($this->stats['bytes_sent']));
        $this->log("速率: " . sprintf("%.1f", $tagsPerSec) . " tags/s");
        $this->log("码率: " . sprintf("%.1f", $kbps) . " kbps");
        $this->log("重连次数: {$this->stats['reconnect_count']}");
        $this->log("上游状态: " . ($this->upstreamConnected ? '✓ 连接' : '✗ 断开'));
        $this->log("下游状态:");
        foreach ($this->downstreamClients as $idx => $downstream) {
            $status = $downstream['connected'] ? '✓ 连接' : '✗ 断开';
            $this->log("  {$downstream['url']}: {$status} | Tags: {$this->downstreamStats[$idx]['tags_sent']}");
        }
        $this->log("========================================");
    }

    protected function printFinalStats(): void
    {
        $elapsed = microtime(true) - $this->stats['start_time'];
        $elapsedFormatted = gmdate('H:i:s', (int)$elapsed);

        $this->log("========================================");
        $this->log("转发统计");
        $this->log("========================================");
        $this->log("运行时间: {$elapsedFormatted}");
        $this->log("接收 Tag 数: {$this->stats['tags_received']}");
        $this->log("  - 音频: {$this->stats['audio_tags']}");
        $this->log("  - 视频: {$this->stats['video_tags']}");
        $this->log("发送 Tag 数: {$this->stats['tags_sent']}");
        $this->log("接收字节: " . $this->formatBytes($this->stats['bytes_received']));
        $this->log("发送字节: " . $this->formatBytes($this->stats['bytes_sent']));
        $this->log("重连次数: {$this->stats['reconnect_count']}");
        $this->log("========================================");
    }

    protected function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024 * 1024) {
            return sprintf("%.2f GB", $bytes / (1024 * 1024 * 1024));
        } elseif ($bytes >= 1024 * 1024) {
            return sprintf("%.2f MB", $bytes / (1024 * 1024));
        } elseif ($bytes >= 1024) {
            return sprintf("%.2f KB", $bytes / 1024);
        } else {
            return "{$bytes} B";
        }
    }
}
