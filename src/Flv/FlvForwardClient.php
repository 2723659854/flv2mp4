<?php

namespace Xiaosongshu\Flv2mp4\Flv;

use Xiaosongshu\Flv2mp4\SabreAMF\RtmpPullerClient;
use Xiaosongshu\Flv2mp4\SabreAMF\RtmpPushFlvClient;

/**
 * @purpose 直播转发工具
 * @author yanglong
 * @time 2026年6月30日18:26:10
 * @note 支持rtmp/ws-flv/http-flv拉流，rtmp/ws-flv/http-flv推流
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
    protected bool $flvHeaderSkipped = false;

    protected bool $initDataSent = false;

    protected ?bool $metaHasVideo = null;
    protected ?bool $metaHasAudio = null;
    protected array $metaData = [];

    protected array $preInitMediaTags = [];

    protected int $baseTimestamp = 0;
    protected bool $baseTimestampSet = false;

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

        try {
            switch ($scheme) {
                case 'rtmp':
                case 'rtmps':
                    $this->connectRtmpDownstream($idx, $url);
                    break;
                case 'http':
                case 'https':
                    $this->connectHttpFlvDownstream($idx, $url, $urlParts);
                    break;
                case 'ws':
                case 'wss':
                    $this->connectWsFlvDownstream($idx, $url, $urlParts);
                    break;
                default:
                    $this->log("下游协议不支持: {$scheme}", 'error');
                    return;
            }
        } catch (\Exception $e) {
            $this->log("下游连接失败: {$url} - {$e->getMessage()}", 'error');
        }
    }

    protected function connectRtmpDownstream(int $idx, string $url): void
    {
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
            'protocol' => 'rtmp',
            'connected' => true,
        ];

        $this->downstreamStats[$idx] = [
            'tags_sent' => 0,
            'bytes_sent' => 0,
        ];

        $this->log("下游RTMP连接成功: {$url}", 'success');
    }

    protected function connectHttpFlvDownstream(int $idx, string $url, array $urlParts): void
    {
        $host = $urlParts['host'] ?? '127.0.0.1';
        $port = $urlParts['port'] ?? ($urlParts['scheme'] === 'https' ? 443 : 8501);
        $path = $urlParts['path'] ?? '/';
        if (!empty($urlParts['query'])) {
            $path .= '?' . $urlParts['query'];
        }

        $ssl = ($urlParts['scheme'] === 'https');
        $proto = $ssl ? 'ssl' : 'tcp';
        $socket = @stream_socket_client("{$proto}://{$host}:{$port}", $errno, $errstr, 10);
        if (!$socket) {
            throw new \RuntimeException("无法连接到 {$host}:{$port} - {$errstr}");
        }

        stream_set_timeout($socket, 30);
        stream_set_blocking($socket, true);

        $request = "POST {$path} HTTP/1.1\r\n";
        $request .= "Host: {$host}\r\n";
        $request .= "Content-Type: video/x-flv\r\n";
        $request .= "Connection: keep-alive\r\n";
        $request .= "Transfer-Encoding: chunked\r\n";
        $request .= "\r\n";

        fwrite($socket, $request);

        $response = '';
        $headersEnded = false;
        while (!feof($socket)) {
            $line = fgets($socket);
            if ($line === false) break;
            $response .= $line;
            if (trim($line) === '') {
                $headersEnded = true;
                break;
            }
        }

        if (!$headersEnded) {
            $this->safeCloseStream($socket);
            throw new \RuntimeException("读取服务器响应失败");
        }

        $firstLine = strtok($response, "\r\n");
        if (strpos($firstLine, '200') === false) {
            $this->safeCloseStream($socket);
            throw new \RuntimeException("服务器返回非200状态: {$firstLine}");
        }

        $this->downstreamClients[$idx] = [
            'url' => $url,
            'client' => $socket,
            'protocol' => 'http',
            'connected' => true,
            'useChunked' => true,
        ];

        $this->downstreamStats[$idx] = [
            'tags_sent' => 0,
            'bytes_sent' => 0,
        ];

        $this->log("下游HTTP-FLV连接成功: {$url}", 'success');
    }

    protected function connectWsFlvDownstream(int $idx, string $url, array $urlParts): void
    {
        $host = $urlParts['host'] ?? '127.0.0.1';
        $port = $urlParts['port'] ?? ($urlParts['scheme'] === 'wss' ? 443 : 8501);
        $path = $urlParts['path'] ?? '/';
        if (!empty($urlParts['query'])) {
            $path .= '?' . $urlParts['query'];
        }

        $ssl = ($urlParts['scheme'] === 'wss');
        $proto = $ssl ? 'ssl' : 'tcp';
        $socket = @stream_socket_client("{$proto}://{$host}:{$port}", $errno, $errstr, 10);
        if (!$socket) {
            throw new \RuntimeException("无法连接到 {$host}:{$port} - {$errstr}");
        }

        stream_set_timeout($socket, 30);
        stream_set_blocking($socket, true);

        $wsKey = base64_encode(random_bytes(16));
        $handshake = "GET {$path} HTTP/1.1\r\n";
        $handshake .= "Host: {$host}:{$port}\r\n";
        $handshake .= "Connection: Upgrade\r\n";
        $handshake .= "Pragma: no-cache\r\n";
        $handshake .= "Cache-Control: no-cache\r\n";
        $handshake .= "Upgrade: websocket\r\n";
        $handshake .= "Origin: http://{$host}:{$port}\r\n";
        $handshake .= "Sec-WebSocket-Version: 13\r\n";
        $handshake .= "Sec-WebSocket-Key: {$wsKey}\r\n";
        $handshake .= "\r\n";

        fwrite($socket, $handshake);

        $response = '';
        $timeout = time() + 10;
        while (time() < $timeout && !feof($socket)) {
            $line = fgets($socket);
            if ($line === false) break;
            $response .= $line;
            if (trim($line) === '') break;
        }

        if (!preg_match('#Sec-WebSocket-Accept:\s(.*)$#mUi', $response, $matches)) {
            $this->safeCloseStream($socket);
            throw new \RuntimeException("WebSocket握手失败：未找到 Sec-WebSocket-Accept 头");
        }

        $responseKey = trim($matches[1]);
        $expectedKey = base64_encode(sha1($wsKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));

        if ($responseKey !== $expectedKey) {
            $this->safeCloseStream($socket);
            throw new \RuntimeException("WebSocket握手失败：Sec-WebSocket-Accept 验证不通过");
        }

        $this->downstreamClients[$idx] = [
            'url' => $url,
            'client' => $socket,
            'protocol' => 'ws',
            'connected' => true,
            'wsKey' => $wsKey,
        ];

        $this->downstreamStats[$idx] = [
            'tags_sent' => 0,
            'bytes_sent' => 0,
        ];

        $this->log("下游WebSocket-FLV连接成功: {$url}", 'success');
    }

    protected function forwardData(): void
    {
        $this->metaDataTag = '';
        $this->videoSequenceTag = '';
        $this->audioSequenceTag = '';
        $this->initDataReady = false;
        $this->flvHeaderSkipped = false;
        $this->initDataSent = false;
        $this->upstreamBuffer = '';
        $this->upstreamChunkBuffer = '';
        $this->metaHasVideo = null;
        $this->metaHasAudio = null;
        $this->metaData = [];
        $this->preInitMediaTags = [];
        $this->baseTimestamp = 0;
        $this->baseTimestampSet = false;

        if ($this->upstreamProtocol === 'rtmp') {
            $this->forwardRtmpStream();
        } else {
            $this->forwardFlvStream();
        }
    }

    protected function forwardRtmpStream(): void
    {
        while ($this->isRunning && !$this->durationReached()) {
            $result = $this->upstreamClient->readMediaTag();
            if ($result['status'] === RtmpPullerClient::READ_TIMEOUT) {
                $this->checkStats();
                continue;
            }
            if ($result['status'] !== RtmpPullerClient::MEDIA_TAG) {
                break;
            }
            $this->stats['bytes_received'] += strlen($result['tag']);
            $this->handleIncomingFlvTag($result['tag']);
            $this->checkStats();
        }
    }

    protected function forwardFlvStream(): void
    {
        while ($this->isRunning && !$this->durationReached()) {
            $data = $this->upstreamProtocol === 'ws' ? $this->readWsData() : $this->readHttpData();
            if ($data === null) {
                usleep(10000);
                $this->checkStats();
                continue;
            }
            $this->stats['bytes_received'] += strlen($data);
            $this->processUpstreamData($data);
            $this->checkStats();
        }
    }

    protected function durationReached(): bool
    {
        if ($this->duration <= 0 || microtime(true) - $this->stats['start_time'] < $this->duration) return false;
        $this->log("转发时长已到", 'info');
        $this->isRunning = false;
        return true;
    }

    protected function handleIncomingFlvTag(string $tag): void
    {
        $this->stats['tags_received']++;
        $receivedFields = $this->getTagFields($tag);
        if ($receivedFields['type'] === self::AUDIO_TAG) {
            $this->stats['audio_tags']++;
        } elseif ($receivedFields['type'] === self::VIDEO_TAG) {
            $this->stats['video_tags']++;
        }
        if (!$this->handleTagBeforeInitialization($tag, $receivedFields)) {
            $this->sendToAllDownstreams($this->rewriteTimestamp($tag));
        }
    }

    protected function getTagFields(string $tag): array
    {
        $type = strlen($tag) > 0 ? ord($tag[0]) : -1;
        $dataSize = strlen($tag) >= 4 ? ((ord($tag[1]) << 16) | (ord($tag[2]) << 8) | ord($tag[3])) : 0;
        $payload = strlen($tag) >= 11 ? substr($tag, 11, $dataSize) : '';
        $frameType = $type === self::VIDEO_TAG && $payload !== '' ? (ord($payload[0]) >> 4) & 0x0f : null;
        $avcPacketType = $type === self::VIDEO_TAG && strlen($payload) >= 2 && (ord($payload[0]) & 0x0f) === self::VIDEO_CODEC_ID_AVC ? ord($payload[1]) : null;
        $sequenceHeader = ($type === self::VIDEO_TAG && $avcPacketType === self::AVC_PACKET_TYPE_SEQUENCE_HEADER)
            || ($type === self::AUDIO_TAG && strlen($payload) >= 2 && ((ord($payload[0]) >> 4) & 0x0f) === self::SOUND_FORMAT_AAC && ord($payload[1]) === self::AAC_PACKET_TYPE_SEQUENCE_HEADER);
        return [
            'type' => $type,
            'timestamp' => $this->getTagTimestamp($tag),
            'sequence_header' => $sequenceHeader,
            'video_keyframe' => $frameType === self::VIDEO_FRAME_TYPE_KEY_FRAME && $avcPacketType === 1,
        ];
    }

    protected function getTagTimestamp(string $tag): int
    {
        if (strlen($tag) < 11) return 0;
        $timestamp = (ord($tag[4]) << 16) | (ord($tag[5]) << 8) | ord($tag[6]);
        $timestampExt = ord($tag[7]);
        return ($timestampExt << 24) | $timestamp;
    }

    protected function setTagTimestamp(string $tag, int $newTimestamp): string
    {
        if (strlen($tag) < 11) return $tag;
        if ($newTimestamp < 0) $newTimestamp = 0;
        $tag[4] = chr(($newTimestamp >> 16) & 0xFF);
        $tag[5] = chr(($newTimestamp >> 8) & 0xFF);
        $tag[6] = chr($newTimestamp & 0xFF);
        $tag[7] = chr(($newTimestamp >> 24) & 0xFF);
        return $tag;
    }

    protected function rewriteTimestamp(string $tag): string
    {
        if (!$this->baseTimestampSet) {
            return $this->setTagTimestamp($tag, 0);
        }
        $ts = $this->getTagTimestamp($tag);
        if ($ts >= $this->baseTimestamp) {
            $newTs = $ts - $this->baseTimestamp;
        } elseif ($this->baseTimestamp - $ts > 0x80000000) {
            $newTs = (0x100000000 - $this->baseTimestamp) + $ts;
        } else {
            $newTs = 0;
        }
        return $this->setTagTimestamp($tag, $newTs);
    }

    protected function parseMetaDataFromTag(string $tag): void
    {
        if (strlen($tag) < 11) return;

        $dataSize = (ord($tag[1]) << 16) | (ord($tag[2]) << 8) | ord($tag[3]);
        $payload = substr($tag, 11, $dataSize);

        try {
            $parsed = FlvDemux::parseMetadata($payload);
            $meta = $parsed['onMetaData'] ?? reset($parsed);
            if (is_array($meta)) {
                $this->metaData = $meta;
                $this->metaHasVideo = isset($meta['videocodecid']) && $meta['videocodecid'] > 0;
                $this->metaHasAudio = isset($meta['audiocodecid']) && $meta['audiocodecid'] > 0;
                $this->log("MetaData解析完成: 视频=" . ($this->metaHasVideo ? '✓' : '✗') . " 音频=" . ($this->metaHasAudio ? '✓' : '✗'), 'debug');
            }
        } catch (\Exception $e) {
            $this->log("MetaData解析失败: " . $e->getMessage(), 'warning');
        }
    }

    protected function handleTagBeforeInitialization(string $tag, array $receivedFields): bool
    {
        if ($this->initDataReady) return false;

        $tagType = $receivedFields['type'];
        if ($tagType === self::SCRIPT_TAG) {
            if ($this->metaDataTag === '') {
                $this->metaDataTag = $tag;
                $this->log("收到MetaData", 'debug');
                $this->parseMetaDataFromTag($tag);
            }
        } elseif ($receivedFields['sequence_header']) {
            if ($tagType === self::VIDEO_TAG) {
                $this->videoSequenceTag = $tag;
                $this->log("收到视频序列头", 'debug');
            } else {
                $this->audioSequenceTag = $tag;
                $this->log("收到音频序列头", 'debug');
            }
        } elseif ($tagType === self::VIDEO_TAG || $tagType === self::AUDIO_TAG) {
            $this->preInitMediaTags[] = $tag;
        }

        $this->trySendInitData();
        return true;
    }

    protected function trySendInitData(): void
    {
        if ($this->initDataSent) return;

        $needVideo = ($this->metaHasVideo === null) ? true : $this->metaHasVideo;
        $needAudio = ($this->metaHasAudio === null) ? true : $this->metaHasAudio;

        $hasVideoSeq = $this->videoSequenceTag !== '';
        $hasAudioSeq = $this->audioSequenceTag !== '';

        if ($this->metaDataTag === '') {
            $needVideo = $hasVideoSeq;
            $needAudio = $hasAudioSeq;
            foreach ($this->preInitMediaTags as $cachedTag) {
                $cachedType = ord($cachedTag[0]);
                if ($cachedType === self::VIDEO_TAG) $needVideo = true;
                if ($cachedType === self::AUDIO_TAG) $needAudio = true;
            }
        }

        $videoReady = !$needVideo || $hasVideoSeq;
        $audioReady = !$needAudio || $hasAudioSeq;

        if (!$videoReady || !$audioReady || empty($this->preInitMediaTags)) return;

        if ($needVideo) {
            $keyframeTimestamp = null;
            foreach ($this->preInitMediaTags as $cachedTag) {
                $fields = $this->getTagFields($cachedTag);
                if ($fields['video_keyframe']) {
                    $keyframeTimestamp = $fields['timestamp'];
                    break;
                }
            }
            if ($keyframeTimestamp === null) {
                if (count($this->preInitMediaTags) > 1000) {
                    $this->preInitMediaTags = array_slice($this->preInitMediaTags, -1000);
                }
                return;
            }
            $audioStartTimestamp = max(0, $keyframeTimestamp - 500);
            $this->preInitMediaTags = array_values(array_filter(
                $this->preInitMediaTags,
                function (string $cachedTag) use ($keyframeTimestamp, $audioStartTimestamp): bool {
                    $fields = $this->getTagFields($cachedTag);
                    if ($fields['type'] === self::VIDEO_TAG) {
                        return $fields['timestamp'] >= $keyframeTimestamp;
                    }
                    return $fields['type'] !== self::AUDIO_TAG || $fields['timestamp'] >= $audioStartTimestamp;
                }
            ));
        }

        if (!$this->baseTimestampSet) {
            $this->baseTimestamp = $this->getTagTimestamp($this->preInitMediaTags[0]);
            $this->baseTimestampSet = true;
            $this->log("基准时间戳确定: {$this->baseTimestamp}ms", 'debug');
        }

        $metaTag = ($this->metaDataTag !== '') ? $this->setTagTimestamp($this->metaDataTag, 0) : '';
        $videoSeqTag = ($hasVideoSeq) ? $this->setTagTimestamp($this->videoSequenceTag, 0) : '';
        $audioSeqTag = ($hasAudioSeq) ? $this->setTagTimestamp($this->audioSequenceTag, 0) : '';

        $typeFlags = 0;
        if ($hasVideoSeq) $typeFlags |= 0x01;
        if ($hasAudioSeq) $typeFlags |= 0x04;
        $flvHeader = 'FLV' . chr(1) . chr($typeFlags) . pack('N', 9);
        $prevTagSize0 = pack('N', 0);

        foreach ($this->downstreamClients as $idx => &$downstream) {
            if (!$downstream['connected']) continue;
            $proto = $downstream['protocol'];

            if ($proto === 'http') {
                $this->sendHttpFlvData($downstream['client'], $flvHeader, $downstream['useChunked']);
                $this->sendHttpFlvData($downstream['client'], $prevTagSize0, $downstream['useChunked']);
                if ($metaTag !== '') {
                    $this->sendHttpFlvData($downstream['client'], $metaTag, $downstream['useChunked']);
                }
                if ($videoSeqTag !== '') {
                    $this->sendHttpFlvData($downstream['client'], $videoSeqTag, $downstream['useChunked']);
                }
                if ($audioSeqTag !== '') {
                    $this->sendHttpFlvData($downstream['client'], $audioSeqTag, $downstream['useChunked']);
                }
            } elseif ($proto === 'ws') {
                $this->sendWsFlvData($downstream['client'], $flvHeader);
                $this->sendWsFlvData($downstream['client'], $prevTagSize0);
                if ($metaTag !== '') {
                    $this->sendWsFlvData($downstream['client'], $metaTag);
                }
                if ($videoSeqTag !== '') {
                    $this->sendWsFlvData($downstream['client'], $videoSeqTag);
                }
                if ($audioSeqTag !== '') {
                    $this->sendWsFlvData($downstream['client'], $audioSeqTag);
                }
            } elseif ($proto === 'rtmp') {
                if ($metaTag !== '') {
                    $this->sendRtmpData($downstream['client'], $metaTag);
                }
                if ($videoSeqTag !== '') {
                    $this->sendRtmpData($downstream['client'], $videoSeqTag);
                }
                if ($audioSeqTag !== '') {
                    $this->sendRtmpData($downstream['client'], $audioSeqTag);
                }
            }
        }

        $this->initDataSent = true;
        $this->initDataReady = true;
        $this->log("初始化数据已发送（视频:" . ($hasVideoSeq ? '✓' : '✗') . " 音频:" . ($hasAudioSeq ? '✓' : '✗') . "），开始转发普通帧", 'success');

        if (!empty($this->preInitMediaTags)) {
            $this->log("补发初始化期间缓存的帧: " . count($this->preInitMediaTags), 'debug');
            foreach ($this->preInitMediaTags as $cachedTag) {
                $rewritten = $this->rewriteTimestamp($cachedTag);
                $this->sendToAllDownstreams($rewritten);
            }
            $this->preInitMediaTags = [];
        }
    }

    protected function checkInitDataReady(): void
    {
        if ($this->initDataReady) return;
        $this->trySendInitData();
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
        if (!$this->flvHeaderSkipped && strlen($this->upstreamBuffer) >= 13) {
            if (substr($this->upstreamBuffer, 0, 3) === 'FLV') {
                $this->upstreamBuffer = substr($this->upstreamBuffer, 13);
                $this->flvHeaderSkipped = true;
                $this->log("已跳过FLV Header", 'debug');
            }
        }

        while (strlen($this->upstreamBuffer) >= 15) {
            $tagType = ord($this->upstreamBuffer[0]);
            $dataSize = (ord($this->upstreamBuffer[1]) << 16) | (ord($this->upstreamBuffer[2]) << 8) | ord($this->upstreamBuffer[3]);
            $totalSize = 11 + $dataSize + 4;

            if (strlen($this->upstreamBuffer) < $totalSize) {
                break;
            }

            $tag = substr($this->upstreamBuffer, 0, $totalSize);
            $this->upstreamBuffer = substr($this->upstreamBuffer, $totalSize);

            $this->handleIncomingFlvTag($tag);
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
                try {
                    switch ($downstream['protocol']) {
                        case 'rtmp':
                            $this->sendRtmpData($downstream['client'], $data);
                            break;
                        case 'http':
                            if (!$this->sendHttpFlvData($downstream['client'], $data, $downstream['useChunked'])) {
                                throw new \RuntimeException("HTTP-FLV写入失败");
                            }
                            break;
                        case 'ws':
                            if (!$this->sendWsFlvData($downstream['client'], $data)) {
                                throw new \RuntimeException("WebSocket-FLV写入失败");
                            }
                            break;
                    }
                    $this->downstreamStats[$idx]['tags_sent']++;
                    $this->downstreamStats[$idx]['bytes_sent'] += strlen($data);
                    $this->stats['tags_sent']++;
                    $this->stats['bytes_sent'] += strlen($data);
                } catch (\Exception $e) {
                    $this->log("下游发送失败: {$downstream['url']} - {$e->getMessage()}", 'error');
                    $downstream['connected'] = false;
                    $this->safeCloseStream($downstream['client']);
                }
            }
        }
    }

    protected function sendHttpFlvData($socket, string $data, bool $useChunked): bool
    {
        if (!$this->isStreamValid($socket)) {
            return false;
        }

        if ($useChunked) {
            $chunkSize = dechex(strlen($data));
            $chunk = $chunkSize . "\r\n" . $data . "\r\n";
            return $this->writeAll($socket, $chunk);
        } else {
            return $this->writeAll($socket, $data);
        }
    }

    protected function sendWsFlvData($socket, string $data): bool
    {
        if (!$this->isStreamValid($socket)) {
            return false;
        }

        $len = strlen($data);
        $frame = '';

        $frame .= chr(0x82);

        if ($len < 126) {
            $frame .= chr(0x80 | $len);
        } elseif ($len < 65536) {
            $frame .= chr(0x80 | 126);
            $frame .= pack('n', $len);
        } else {
            $frame .= chr(0x80 | 127);
            $frame .= pack('J', $len);
        }

        $mask = random_bytes(4);
        $frame .= $mask;

        for ($i = 0; $i < $len; $i++) {
            $frame .= chr(ord($data[$i]) ^ ord($mask[$i % 4]));
        }

        return $this->writeAll($socket, $frame);
    }

    protected function writeAll($socket, string $data): bool
    {
        $len = strlen($data);
        $written = 0;

        while ($written < $len) {
            $result = @fwrite($socket, substr($data, $written));
            if ($result === false) {
                return false;
            }
            $written += $result;
        }
        return true;
    }

    protected function sendWsCloseFrame($socket): void
    {
        if (!$this->isStreamValid($socket)) {
            return;
        }
        $frame = chr(0x88);
        $frame .= chr(0x80);
        $mask = random_bytes(4);
        $frame .= $mask;
        @fwrite($socket, $frame);
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
                if ($downstream['protocol'] === 'rtmp') {
                    $reflection = new \ReflectionClass($downstream['client']);
                    $closeMethod = $reflection->getMethod('close');
                    $closeMethod->setAccessible(true);
                    $closeMethod->invoke($downstream['client']);
                } else {
                    if ($downstream['protocol'] === 'ws') {
                        $this->sendWsCloseFrame($downstream['client']);
                    }
                    $this->safeCloseStream($downstream['client']);
                }
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
