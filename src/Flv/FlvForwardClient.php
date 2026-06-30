<?php

namespace Xiaosongshu\Flv2mp4\Flv;

use Xiaosongshu\Flv2mp4\SabreAMF\RtmpPacket;
use Xiaosongshu\Flv2mp4\SabreAMF\RtmpStream;
use Xiaosongshu\Flv2mp4\SabreAMF\SabreAMF_OutputStream;
use Xiaosongshu\Flv2mp4\SabreAMF\AMF0\SabreAMF_AMF0_Serializer;
use Xiaosongshu\Flv2mp4\SabreAMF\RTMPClient;
use Xiaosongshu\Flv2mp4\SabreAMF\RtmpPushFlvClient;

class FlvForwardClient
{
    protected string $pullUrl;
    protected array $pushUrls;
    protected int $duration;
    protected bool $autoReconnect;
    protected bool $isRunning = true;

    protected $upstreamSocket = null;
    protected string $upstreamProtocol = '';
    protected bool $upstreamConnected = false;
    protected bool $upstreamChunked = false;
    protected string $upstreamBuffer = '';
    protected string $upstreamChunkBuffer = '';
    protected bool $upstreamHttpHeaderParsed = false;
    protected bool $upstreamIsWebSocket = false;

    protected array $downstreams = [];
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

    protected string $flvHeader = '';
    protected string $metaDataTag = '';
    protected string $videoSequenceTag = '';
    protected string $audioSequenceTag = '';
    protected bool $initDataReady = false;
    protected string $initData = '';

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

        $this->lastStatsTime = time();

        while ($this->isRunning) {
            try {
                $this->connectUpstream();
                $this->connectAllDownstreams();
                $this->upstreamConnected = true;
                $this->log("所有连接已建立，开始转发", 'success');

                $this->eventLoop();
            } catch (\Exception $e) {
                $this->log("转发异常: {$e->getMessage()}", 'error');
            } finally {
                $this->disconnectAll();
                $this->upstreamConnected = false;
                $this->resetInitData();
            }

            if ($this->duration > 0 && (time() - $this->stats['start_time']) >= $this->duration) {
                break;
            }

            if ($this->autoReconnect && $this->isRunning) {
                if (!$this->handleReconnect()) {
                    break;
                }
            } else {
                break;
            }
        }

        $this->printFinalStats();
    }

    protected function eventLoop(): void
    {
        while ($this->isRunning) {
            $read = [];
            if ($this->isStreamValid($this->upstreamSocket)) {
                $read[] = $this->upstreamSocket;
            }

            foreach ($this->downstreams as $idx => $downstream) {
                if ($downstream['protocol'] === 'rtmp' || $downstream['protocol'] === 'rtmps') {
                    if ($downstream['rtmpClient'] && $downstream['connected']) {
                        $socket = $this->getRtmpSocket($downstream['rtmpClient']);
                        if ($socket) {
                            $read[] = $socket;
                        }
                    }
                } elseif ($this->isStreamValid($downstream['socket'])) {
                    $read[] = $downstream['socket'];
                }
            }

            $write = [];
            foreach ($this->downstreams as $idx => $downstream) {
                if (!$downstream['connected']) {
                    continue;
                }

                if (($downstream['protocol'] === 'rtmp' || $downstream['protocol'] === 'rtmps')) {
                    continue;
                }

                if ($this->isStreamValid($downstream['socket']) && !empty($downstream['sendBuffer'])) {
                    $write[] = $downstream['socket'];
                }
            }

            $except = null;
            $n = @stream_select($read, $write, $except, 1, 0);

            if ($n === false) {
                usleep(10000);
                continue;
            }

            if ($n > 0) {
                foreach ($read as $sock) {
                    if ($sock === $this->upstreamSocket) {
                        $this->readUpstream();
                    } else {
                        $this->readDownstream($sock);
                    }
                }

                foreach ($write as $sock) {
                    $this->writeDownstream($sock);
                }
            }

            $this->checkDownstreamHealth();

            if ($this->statsInterval > 0 && time() - $this->lastStatsTime >= $this->statsInterval) {
                $this->printStats();
                $this->lastStatsTime = time();
            }

            if ($this->duration > 0 && (time() - $this->stats['start_time']) >= $this->duration) {
                $this->log("已达到指定时长 {$this->duration} 秒，停止转发");
                break;
            }
        }
    }

    protected function getRtmpSocket($rtmpClient)
    {
        $reflection = new \ReflectionClass('\Xiaosongshu\Flv2mp4\SabreAMF\RTMPClient');
        $socketProperty = $reflection->getProperty('socket');
        $socketProperty->setAccessible(true);
        $rtmpSocket = $socketProperty->getValue($rtmpClient);

        if ($rtmpSocket) {
            $reflection2 = new \ReflectionClass('\Xiaosongshu\Flv2mp4\SabreAMF\RtmpSocket');
            $socketProp = $reflection2->getProperty('socket');
            $socketProp->setAccessible(true);
            return $socketProp->getValue($rtmpSocket);
        }
        return null;
    }

    protected function connectUpstream(): void
    {
        $urlParts = parse_url($this->pullUrl);
        $scheme = strtolower($urlParts['scheme'] ?? 'http');
        $host = $urlParts['host'] ?? '127.0.0.1';
        $port = $urlParts['port'] ?? ($scheme === 'https' ? 443 : ($scheme === 'wss' ? 443 : 8501));
        $path = $urlParts['path'] ?? '/';
        if (!empty($urlParts['query'])) {
            $path .= '?' . $urlParts['query'];
        }

        $this->log("连接上游 {$scheme}://{$host}:{$port}{$path}...");

        switch ($scheme) {
            case 'rtmp':
            case 'rtmps':
                $this->upstreamProtocol = 'rtmp';
                $this->connectRtmpUpstream($host, $port, $path);
                break;
            case 'ws':
            case 'wss':
                $this->upstreamProtocol = 'ws';
                $this->upstreamIsWebSocket = true;
                $this->connectWebSocketUpstream($host, $port, $path, $scheme === 'wss');
                break;
            default:
                $this->upstreamProtocol = 'http';
                $this->connectHttpUpstream($host, $port, $path);
                break;
        }

        $this->log("上游连接成功，协议: {$this->upstreamProtocol}", 'success');
    }

    protected function connectHttpUpstream(string $host, int $port, string $path): void
    {
        $this->upstreamSocket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 10);
        if (!$this->upstreamSocket) {
            throw new \RuntimeException("无法连接到 {$host}:{$port} - {$errstr}");
        }

        stream_set_timeout($this->upstreamSocket, 10);

        $request = "GET {$path} HTTP/1.1\r\n";
        $request .= "Host: {$host}\r\n";
        $request .= "Accept: */*\r\n";
        $request .= "Connection: keep-alive\r\n";
        $request .= "\r\n";

        fwrite($this->upstreamSocket, $request);

        $header = '';
        $timeout = time() + 10;

        while (time() < $timeout) {
            $chunk = @fread($this->upstreamSocket, 4096);
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
                    $this->safeCloseStream($this->upstreamSocket);
                    $this->upstreamSocket = null;
                    throw new \RuntimeException("上游返回非200状态码");
                }

                $this->upstreamChunked = (stripos($headerStr, "Transfer-Encoding: chunked") !== false);
                $this->upstreamHttpHeaderParsed = true;

                if (strlen($bodyData) > 0) {
                    if ($this->upstreamChunked) {
                        $this->upstreamChunkBuffer = $bodyData;
                    } else {
                        $this->upstreamBuffer = $bodyData;
                    }
                }

                stream_set_blocking($this->upstreamSocket, false);
                return;
            }
        }

        $this->safeCloseStream($this->upstreamSocket);
        $this->upstreamSocket = null;
        throw new \RuntimeException("上游响应超时");
    }

    protected function connectWebSocketUpstream(string $host, int $port, string $path, bool $ssl = false): void
    {
        $proto = $ssl ? 'ssl' : 'tcp';
        $this->upstreamSocket = @stream_socket_client("{$proto}://{$host}:{$port}", $errno, $errstr, 10);
        if (!$this->upstreamSocket) {
            throw new \RuntimeException("无法连接到 {$host}:{$port} - {$errstr}");
        }

        stream_set_timeout($this->upstreamSocket, 10);

        $key = base64_encode(random_bytes(16));
        $handshake = "GET {$path} HTTP/1.1\r\n";
        $handshake .= "Host: {$host}:{$port}\r\n";
        $handshake .= "Upgrade: websocket\r\n";
        $handshake .= "Connection: Upgrade\r\n";
        $handshake .= "Sec-WebSocket-Key: {$key}\r\n";
        $handshake .= "Sec-WebSocket-Version: 13\r\n";
        $handshake .= "\r\n";

        fwrite($this->upstreamSocket, $handshake);

        $response = fread($this->upstreamSocket, 1024);
        if (!str_contains($response, '101 Switching Protocols')) {
            $this->safeCloseStream($this->upstreamSocket);
            $this->upstreamSocket = null;
            throw new \RuntimeException("WebSocket握手失败");
        }

        stream_set_blocking($this->upstreamSocket, false);
    }

    protected function connectRtmpUpstream(string $host, int $port, string $path): void
    {
        $pathParts = explode('/', trim($path, '/'));
        $app = $pathParts[0] ?? 'live';
        $streamKey = $pathParts[1] ?? 'stream';

        $this->upstreamSocket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 10);
        if (!$this->upstreamSocket) {
            throw new \RuntimeException("无法连接到 {$host}:{$port} - {$errstr}");
        }

        stream_set_timeout($this->upstreamSocket, 10);

        $this->rtmpHandshake();
        $this->rtmpConnect($app);
        $this->rtmpCreateStream();
        $this->rtmpPlay($streamKey);

        stream_set_blocking($this->upstreamSocket, false);
    }

    protected $rtmpStreamId = 0;
    protected $rtmpChunkSizeR = 128;
    protected $rtmpPrevReadingPacket = [];
    protected $rtmpReadingOperations = [];

    protected function rtmpHandshake(): void
    {
        $stream = new RtmpStream();
        $stream->writeByte(0x03);
        $ctime = time();
        $stream->writeInt32($ctime);
        $stream->write("\x80\x00\x03\x02");

        $crandom = '';
        for ($i = 0; $i < 1528; $i++) {
            $crandom .= chr(rand(0, 255));
        }
        $stream->write($crandom);
        fwrite($this->upstreamSocket, $stream->flush());

        $s0 = @fread($this->upstreamSocket, 1);
        $s1 = @fread($this->upstreamSocket, 1536);
        $s2 = @fread($this->upstreamSocket, 1536);

        if (strlen($s0) < 1 || strlen($s1) < 1536 || strlen($s2) < 1536) {
            $this->safeCloseStream($this->upstreamSocket);
            $this->upstreamSocket = null;
            throw new \RuntimeException("RTMP握手失败");
        }

        $s1Stream = new RtmpStream($s1);
        $s1Time = $s1Stream->readInt32();
        $s1Stream->readInt32();
        $s1Raw = $s1Stream->readRaw();

        $c2 = new RtmpStream();
        $c2->writeInt32($s1Time);
        $c2->writeInt32($ctime);
        $c2->write($s1Raw);
        fwrite($this->upstreamSocket, $c2->flush());
    }

    protected function rtmpConnect(string $app): void
    {
        $connectObj = (object)[
            'app' => $app,
            'flashVer' => 'LNX 10,0,32,18',
            'swfUrl' => null,
            'tcUrl' => 'rtmp://' . parse_url($this->pullUrl)['host'] . '/' . $app,
            'fpad' => false,
            'capabilities' => 0.0,
            'audioCodecs' => 0x01,
            'videoCodecs' => 0xFF,
            'videoFunction' => 0,
            'pageUrl' => null,
            'objectEncoding' => 0x03
        ];

        $message = new \Xiaosongshu\Flv2mp4\SabreAMF\RtmpMessage('connect', $connectObj);
        $message->encode();
        $packet = $message->getPacket();
        $this->rtmpSendPacket($packet);

        $timeout = time() + 5;
        while (time() < $timeout) {
            $p = $this->rtmpReadPacketUpstream();
            if ($p && $p['type'] == 0x14) {
                $response = $this->rtmpDecodeInvoke($p['payload']);
                if (is_array($response) && isset($response['cmd']) && $response['cmd'] === '_result') {
                    break;
                }
            }
            usleep(10000);
        }
    }

    protected function rtmpCreateStream(): void
    {
        $message = new \Xiaosongshu\Flv2mp4\SabreAMF\RtmpMessage('createStream', null);
        $message->encode();
        $packet = $message->getPacket();
        $packet->streamId = 0;
        $this->rtmpSendPacket($packet);

        $timeout = time() + 5;
        while (time() < $timeout) {
            $p = $this->rtmpReadPacketUpstream();
            if ($p && $p['type'] == 0x14) {
                $response = $this->rtmpDecodeInvoke($p['payload']);
                if (is_array($response) && isset($response['cmd']) && $response['cmd'] === '_result') {
                    if (isset($response['data'][0])) {
                        $this->rtmpStreamId = (int)$response['data'][0];
                    }
                    break;
                }
            }
            usleep(10000);
        }
    }

    protected function rtmpPlay(string $streamKey): void
    {
        $message = new \Xiaosongshu\Flv2mp4\SabreAMF\RtmpMessage('play', null, [$streamKey]);
        $message->encode();
        $packet = $message->getPacket();
        $packet->streamId = $this->rtmpStreamId;
        $this->rtmpSendPacket($packet);
    }

    protected function rtmpSendPacket(RtmpPacket $packet): void
    {
        if (!$packet->length) {
            $packet->length = strlen($packet->payload);
        }

        $header = new RtmpStream();
        $header->writeByte($packet->chunkType << 6 | $packet->chunkStreamId);

        if ($packet->chunkType <= RtmpPacket::CHUNK_TYPE_1) {
            $header->writeInt24(time());
        }

        if ($packet->chunkType <= RtmpPacket::CHUNK_TYPE_1) {
            $header->writeInt24($packet->length);
            $header->writeByte($packet->type);
        }

        if ($packet->chunkType == RtmpPacket::CHUNK_TYPE_0) {
            $header->writeInt32LE($packet->streamId);
        }

        fwrite($this->upstreamSocket, $header->flush());

        $buffer = $packet->payload;
        $bufferLen = strlen($buffer);
        $offset = 0;

        while ($offset < $bufferLen) {
            $chunkSize = min(128, $bufferLen - $offset);
            fwrite($this->upstreamSocket, substr($buffer, $offset, $chunkSize));
            $offset += $chunkSize;

            if ($offset < $bufferLen) {
                fwrite($this->upstreamSocket, chr(0xC0 | $packet->chunkStreamId));
            }
        }
    }

    protected function connectAllDownstreams(): void
    {
        foreach ($this->pushUrls as $idx => $url) {
            $this->connectDownstream($idx, $url);
        }
    }

    protected function connectDownstream(int $idx, string $url): void
    {
        $urlParts = parse_url($url);
        $scheme = strtolower($urlParts['scheme'] ?? 'http');
        $host = $urlParts['host'] ?? '127.0.0.1';
        $port = $urlParts['port'] ?? 8501;
        $path = $urlParts['path'] ?? '/';
        if (!empty($urlParts['query'])) {
            $path .= '?' . $urlParts['query'];
        }

        $connected = false;
        $socket = null;
        $rtmpClient = null;
        $rtmpStreamId = 0;

        switch ($scheme) {
            case 'rtmp':
            case 'rtmps':
                try {
                    $pathParts = explode('/', trim($path, '/'));
                    $app = $pathParts[0] ?? 'live';
                    $streamKey = $pathParts[1] ?? 'stream';

                    $rtmpClient = new RtmpPushFlvClient('', $url);

                    $reflection = new \ReflectionClass($rtmpClient);
                    $connectMethod = $reflection->getMethod('connect');
                    $connectMethod->setAccessible(true);
                    $connectMethod->invoke($rtmpClient, $host, $app, $port);

                    $fcPublishMethod = $reflection->getMethod('fcPublish');
                    $fcPublishMethod->setAccessible(true);
                    $fcPublishMethod->invoke($rtmpClient, $streamKey);

                    $publishMethod = $reflection->getMethod('publish');
                    $publishMethod->setAccessible(true);
                    $publishMethod->invoke($rtmpClient, $streamKey, 'live');

                    $streamIdProp = $reflection->getProperty('streamId');
                    $streamIdProp->setAccessible(true);
                    $rtmpStreamId = $streamIdProp->getValue($rtmpClient);

                    $connected = true;
                    $this->log("下游RTMP连接成功: {$url}", 'success');
                } catch (\Exception $e) {
                    $this->log("下游RTMP连接失败: {$url} - {$e->getMessage()}", 'error');
                }
                break;

            case 'ws':
            case 'wss':
                $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 10);
                if ($socket) {
                    stream_set_timeout($socket, 10);
                    $connected = $this->handshakeWsDownstream($socket, $host, $port, $path, $scheme === 'wss');
                    if ($connected) {
                        stream_set_blocking($socket, false);
                    } else {
                        $this->safeCloseStream($socket);
                        $socket = null;
                    }
                } else {
                    $this->log("无法连接下游 {$url}: {$errstr}", 'error');
                }
                break;

            default:
                $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 10);
                if ($socket) {
                    stream_set_timeout($socket, 10);
                    $connected = $this->handshakeHttpDownstream($socket, $host, $path);
                    if ($connected) {
                        stream_set_blocking($socket, false);
                    } else {
                        $this->safeCloseStream($socket);
                        $socket = null;
                    }
                } else {
                    $this->log("无法连接下游 {$url}: {$errstr}", 'error');
                }
                break;
        }

        $this->downstreams[$idx] = [
            'url' => $url,
            'socket' => $socket,
            'protocol' => $scheme,
            'connected' => $connected,
            'sendBuffer' => '',
            'isWebSocket' => ($scheme === 'ws' || $scheme === 'wss'),
            'rtmpClient' => $rtmpClient,
            'rtmpStreamId' => $rtmpStreamId,
            'rtmpAudioChunkId' => 4,
            'rtmpVideoChunkId' => 5,
            'rtmpMetaChunkId' => 3,
            'rtmpLastAudioTs' => -1,
            'rtmpLastVideoTs' => -1,
        ];

        $this->downstreamStats[$idx] = [
            'tags_sent' => 0,
            'bytes_sent' => 0,
            'connected' => $connected,
            'reconnect_count' => 0,
        ];
    }

    protected function handshakeHttpDownstream($socket, string $host, string $path): bool
    {
        $request = "POST {$path} HTTP/1.1\r\n";
        $request .= "Host: {$host}\r\n";
        $request .= "Content-Type: video/x-flv\r\n";
        $request .= "Connection: keep-alive\r\n";
        $request .= "Transfer-Encoding: chunked\r\n";
        $request .= "\r\n";

        fwrite($socket, $request);

        $response = '';
        $timeout = time() + 10;
        while (time() < $timeout) {
            $line = @fgets($socket);
            if ($line === false) {
                usleep(10000);
                continue;
            }
            $response .= $line;
            if (trim($line) === '') {
                break;
            }
        }

        if (!preg_match('#^HTTP/\d\.\d 200#', $response)) {
            return false;
        }

        return true;
    }

    protected function handshakeWsDownstream($socket, string $host, int $port, string $path, bool $ssl = false): bool
    {
        if ($ssl) {
            $context = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
            $socket = @stream_socket_client("ssl://{$host}:{$port}", $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $context);
            if (!$socket) {
                return false;
            }
        }

        $key = base64_encode(random_bytes(16));
        $handshake = "GET {$path} HTTP/1.1\r\n";
        $handshake .= "Host: {$host}:{$port}\r\n";
        $handshake .= "Upgrade: websocket\r\n";
        $handshake .= "Connection: Upgrade\r\n";
        $handshake .= "Sec-WebSocket-Key: {$key}\r\n";
        $handshake .= "Sec-WebSocket-Version: 13\r\n";
        $handshake .= "\r\n";

        fwrite($socket, $handshake);

        $response = '';
        $timeout = time() + 10;
        while (time() < $timeout) {
            $line = @fgets($socket);
            if ($line === false) {
                usleep(10000);
                continue;
            }
            $response .= $line;
            if (trim($line) === '') {
                break;
            }
        }

        if (!str_contains($response, '101 Switching Protocols')) {
            return false;
        }

        if (!preg_match('#Sec-WebSocket-Accept:\s(.*)$#mUi', $response, $matches)) {
            return false;
        }

        $expectedKey = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        if (trim($matches[1]) !== $expectedKey) {
            return false;
        }

        return true;
    }

    protected function rtmpDecodeInvoke(string $payload)
    {
        $stream = new \Xiaosongshu\Flv2mp4\SabreAMF\SabreAMF_InputStream($payload);
        $deserializer = new \Xiaosongshu\Flv2mp4\SabreAMF\AMF0\SabreAMF_AMF0_Deserializer($stream);

        $command = $deserializer->readAMFData();
        $transId = $deserializer->readAMFData();
        $cmdObj = $deserializer->readAMFData();

        $args = [];
        try {
            $args = $deserializer->readAMFData();
        } catch (\Exception $e) {
            $args = null;
        }

        return is_array($args) ? $args : [];
    }

    protected function readUpstream(): void
    {
        if (!$this->isStreamValid($this->upstreamSocket)) {
            throw new \RuntimeException("上游连接已断开");
        }

        switch ($this->upstreamProtocol) {
            case 'rtmp':
                $data = $this->readRtmpUpstream();
                break;
            case 'ws':
                $data = $this->readWebSocketUpstream();
                break;
            default:
                $data = $this->readHttpUpstream();
                break;
        }

        if ($data === null || $data === '') {
            return;
        }

        $this->stats['bytes_received'] += strlen($data);
        $this->processUpstreamData($data);
    }

    protected function readHttpUpstream(): ?string
    {
        $data = @fread($this->upstreamSocket, 65536);
        if ($data === false) {
            $info = stream_get_meta_data($this->upstreamSocket);
            if (!empty($info['eof'])) {
                throw new \RuntimeException("HTTP上游连接已关闭");
            }
            return null;
        }

        if ($data === '') {
            $info = stream_get_meta_data($this->upstreamSocket);
            if (!empty($info['eof'])) {
                throw new \RuntimeException("HTTP上游连接已关闭");
            }
            return null;
        }

        return $data;
    }

    protected function readWebSocketUpstream(): ?string
    {
        $frame = @fread($this->upstreamSocket, 2);
        if (!$frame || strlen($frame) < 2) {
            $info = stream_get_meta_data($this->upstreamSocket);
            if (!empty($info['eof'])) {
                throw new \RuntimeException("WebSocket上游连接已关闭");
            }
            return null;
        }

        $firstByte = ord($frame[0]);
        $secondByte = ord($frame[1]);

        $opcode = $firstByte & 0x0F;
        $payloadLen = $secondByte & 0x7F;

        if ($opcode === 0x08) {
            throw new \RuntimeException("WebSocket上游连接关闭帧");
        }

        if ($opcode !== 0x01 && $opcode !== 0x02) {
            return null;
        }

        if ($payloadLen === 126) {
            $ext = @fread($this->upstreamSocket, 2);
            if ($ext === false || strlen($ext) < 2) return null;
            $payloadLen = unpack('n', $ext)[1];
        } elseif ($payloadLen === 127) {
            $ext = @fread($this->upstreamSocket, 8);
            if ($ext === false || strlen($ext) < 8) return null;
            $payloadLen = unpack('J', $ext)[1];
        }

        $data = '';
        while (strlen($data) < $payloadLen) {
            $chunk = @fread($this->upstreamSocket, $payloadLen - strlen($data));
            if ($chunk === false) {
                $info = stream_get_meta_data($this->upstreamSocket);
                if (!empty($info['eof'])) {
                    throw new \RuntimeException("WebSocket上游连接已关闭");
                }
                break;
            }
            $data .= $chunk;
        }

        return $data;
    }

    protected function readRtmpUpstream(): ?string
    {
        while (true) {
            $p = $this->rtmpReadPacketUpstream();
            if (!$p) return null;

            switch ($p['type']) {
                case 0x08:
                case 0x09:
                case 0x12:
                    return $this->rtmpToFlvTag($p['type'], $p['timestamp'], $p['payload']);
                case 0x01:
                    $s = new RtmpStream($p['payload']);
                    $this->rtmpChunkSizeR = $s->readInt32();
                    break;
                case 0x04:
                    $this->rtmpHandlePing($p);
                    break;
                case 0x14:
                    $response = $this->rtmpDecodeInvoke($p['payload']);
                    if (is_array($response) && isset($response['cmd']) && $response['cmd'] === '_result') {
                        if (isset($response['data'][0])) {
                            $this->rtmpStreamId = (int)$response['data'][0];
                        }
                    }
                    break;
                default:
                    break;
            }
        }
    }

    protected function rtmpReadPacketUpstream()
    {
        if (!$this->isStreamValid($this->upstreamSocket)) return null;

        $header = @fread($this->upstreamSocket, 1);
        if ($header === false || strlen($header) < 1) {
            return null;
        }

        $firstByte = ord($header[0]);
        $chunkType = (($firstByte & 0xc0) >> 6);
        $chunkStreamId = $firstByte & 0x3f;

        switch ($chunkStreamId) {
            case 0:
                $secondByte = @fread($this->upstreamSocket, 1);
                if ($secondByte === false || strlen($secondByte) < 1) return null;
                $chunkStreamId = 64 + ord($secondByte[0]);
                break;
            case 1:
                $secondByte = @fread($this->upstreamSocket, 1);
                $thirdByte = @fread($this->upstreamSocket, 1);
                if ($secondByte === false || strlen($secondByte) < 1 || $thirdByte === false || strlen($thirdByte) < 1) return null;
                $chunkStreamId = 64 + ord($secondByte[0]) + ord($thirdByte[0]) * 256;
                break;
        }

        if (!isset($this->rtmpReadingOperations[$chunkStreamId])) {
            $this->rtmpReadingOperations[$chunkStreamId] = [
                'bytesRead' => 0,
                'length' => 0,
                'type' => 0,
                'streamId' => 0,
                'timestamp' => 0,
                'payload' => ''
            ];
        }

        $op = &$this->rtmpReadingOperations[$chunkStreamId];

        $isContinuation = ($chunkType == RtmpPacket::CHUNK_TYPE_3 &&
            $op['bytesRead'] > 0 && $op['length'] > 0 && $op['bytesRead'] < $op['length']);

        if ($isContinuation) {
            $timestamp = $op['timestamp'];
            $length = $op['length'];
            $type = $op['type'];
            $streamId = $op['streamId'];
            $bytesRead = $op['bytesRead'];
            $payload = $op['payload'];
        } else {
            $timestamp = 0;
            $length = 0;
            $type = 0;
            $streamId = 0;

            if (isset($this->rtmpPrevReadingPacket[$chunkStreamId])) {
                $prev = $this->rtmpPrevReadingPacket[$chunkStreamId];
                switch ($chunkType) {
                    case RtmpPacket::CHUNK_TYPE_3:
                        $timestamp = $prev['timestamp'];
                        $length = $prev['length'];
                        $type = $prev['type'];
                        $streamId = $prev['streamId'];
                        break;
                    case RtmpPacket::CHUNK_TYPE_2:
                        $length = $prev['length'];
                        $type = $prev['type'];
                        $streamId = $prev['streamId'];
                        break;
                    case RtmpPacket::CHUNK_TYPE_1:
                        $streamId = $prev['streamId'];
                        break;
                }
            }

            $headerSize = RtmpPacket::$SIZES[$chunkType] - 1;
            $headerData = '';
            if ($headerSize > 0) {
                $headerData = @fread($this->upstreamSocket, $headerSize);
                if ($headerData === false || strlen($headerData) < $headerSize) {
                    return null;
                }

                $offset = 0;
                if ($headerSize >= 3) {
                    $timestamp = (ord($headerData[$offset++]) << 16) |
                        (ord($headerData[$offset++]) << 8) |
                        ord($headerData[$offset++]);
                    if ($timestamp === 0xFFFFFF) {
                        if ($offset + 4 <= $headerSize) {
                            $extTimestamp = (ord($headerData[$offset++]) << 24) |
                                (ord($headerData[$offset++]) << 16) |
                                (ord($headerData[$offset++]) << 8) |
                                ord($headerData[$offset++]);
                            $timestamp = $extTimestamp;
                        }
                    }
                }
                if ($headerSize >= 6) {
                    $length = (ord($headerData[$offset++]) << 16) |
                        (ord($headerData[$offset++]) << 8) |
                        ord($headerData[$offset++]);
                }
                if ($headerSize > 6) {
                    $type = ord($headerData[$offset++]);
                }
                if ($headerSize == 11) {
                    $streamId = ord($headerData[$offset]) |
                        (ord($headerData[$offset+1]) << 8) |
                        (ord($headerData[$offset+2]) << 16) |
                        (ord($headerData[$offset+3]) << 24);
                }
            }

            if ($chunkType == RtmpPacket::CHUNK_TYPE_0) {
                $this->rtmpPrevReadingPacket[$chunkStreamId] = [
                    'timestamp' => $timestamp,
                    'length' => $length,
                    'type' => $type,
                    'streamId' => $streamId
                ];
            }

            $op['length'] = $length;
            $op['type'] = $type;
            $op['streamId'] = $streamId;
            $op['timestamp'] = $timestamp;
            $op['payload'] = '';
            $op['bytesRead'] = 0;

            $bytesRead = 0;
            $payload = '';
        }

        $nToRead = $length - $bytesRead;
        $nChunk = $this->rtmpChunkSizeR;
        if ($nToRead < $nChunk) {
            $nChunk = $nToRead;
        }

        if ($nChunk > 0) {
            $chunk = '';
            $remaining = $nChunk;
            while (strlen($chunk) < $nChunk) {
                $data = @fread($this->upstreamSocket, $remaining);
                if ($data === false) {
                    return null;
                }
                if ($data === '') {
                    usleep(1000);
                    continue;
                }
                $chunk .= $data;
                $remaining -= strlen($data);
            }

            $payload .= $chunk;
            $bytesRead += strlen($chunk);
            $op['payload'] = $payload;
            $op['bytesRead'] = $bytesRead;
        }

        if ($bytesRead >= $length && $length > 0) {
            $this->rtmpPrevReadingPacket[$chunkStreamId] = [
                'timestamp' => $timestamp,
                'length' => $length,
                'type' => $type,
                'streamId' => $streamId
            ];

            $op['bytesRead'] = 0;
            $op['payload'] = '';

            return [
                'chunkStreamId' => $chunkStreamId,
                'type' => $type,
                'streamId' => $streamId,
                'timestamp' => $timestamp,
                'payload' => $payload
            ];
        }

        return null;
    }

    protected function rtmpHandlePing(array $p): void
    {
        $pingPayload = $p['payload'];
        if (strlen($pingPayload) >= 8) {
            $eventType = ord($pingPayload[0]) | (ord($pingPayload[1]) << 8);
            if ($eventType === 0x0006) {
                $timestamp = substr($pingPayload, 2, 4);
                $response = chr(0x00) . chr(0x07) . $timestamp;

                $packet = new RtmpPacket();
                $packet->chunkStreamId = 2;
                $packet->streamId = $this->rtmpStreamId;
                $packet->type = 0x04;
                $packet->length = strlen($response);
                $packet->payload = $response;
                $packet->chunkType = RtmpPacket::CHUNK_TYPE_0;
                $this->rtmpSendPacket($packet);
            }
        }
    }

    protected function rtmpToFlvTag(int $tagType, int $timestamp, string $payload): string
    {
        $dataSize = strlen($payload);

        $tsLow = $timestamp & 0xFFFFFF;
        $tsHigh = ($timestamp >> 24) & 0xFF;

        $tag = chr($tagType);
        $tag .= chr(($dataSize >> 16) & 0xFF);
        $tag .= chr(($dataSize >> 8) & 0xFF);
        $tag .= chr($dataSize & 0xFF);
        $tag .= chr(($tsLow >> 16) & 0xFF);
        $tag .= chr(($tsLow >> 8) & 0xFF);
        $tag .= chr($tsLow & 0xFF);
        $tag .= chr($tsHigh);
        $tag .= "\x00\x00\x00";
        $tag .= $payload;

        $prevTagSize = 11 + $dataSize;
        $tag .= chr(($prevTagSize >> 24) & 0xFF);
        $tag .= chr(($prevTagSize >> 16) & 0xFF);
        $tag .= chr(($prevTagSize >> 8) & 0xFF);
        $tag .= chr($prevTagSize & 0xFF);

        return $tag;
    }

    protected function readDownstream($sock): void
    {
        foreach ($this->downstreams as $idx => $downstream) {
            if (($downstream['socket'] === $sock) || 
                ($downstream['protocol'] === 'rtmp' && $downstream['rtmpClient'])) {
                
                $socket = $downstream['socket'];
                if ($downstream['protocol'] === 'rtmp' || $downstream['protocol'] === 'rtmps') {
                    $socket = $this->getRtmpSocket($downstream['rtmpClient']);
                }
                
                if ($socket === $sock) {
                    $data = @fread($sock, 1);
                    if ($data === '' || $data === false) {
                        $this->removeDownstream($idx);
                    }
                    break;
                }
            }
        }
    }

    protected function writeDownstream($sock): void
    {
        foreach ($this->downstreams as $idx => &$downstream) {
            if ($downstream['protocol'] === 'rtmp' || $downstream['protocol'] === 'rtmps') {
                continue;
            }

            $socket = $downstream['socket'];
            if ($socket === $sock) {
                if (empty($downstream['sendBuffer'])) {
                    continue;
                }

                $data = $downstream['sendBuffer'];
                $written = 0;

                if ($downstream['isWebSocket']) {
                    $frame = $this->encodeWebSocketFrame($data);
                    $written = @fwrite($socket, $frame);
                } else {
                    $chunk = dechex(strlen($data)) . "\r\n" . $data . "\r\n";
                    $written = @fwrite($socket, $chunk);
                }

                if ($written !== false && $written > 0) {
                    $downstream['sendBuffer'] = '';
                    $this->downstreamStats[$idx]['bytes_sent'] += strlen($data);
                    $this->stats['bytes_sent'] += strlen($data);
                } else {
                    $downstream['connected'] = false;
                }

                break;
            }
        }
    }

    protected function writeRtmpDownstream($rtmpClient, string $data, array &$downstream): int
    {
        if (strlen($data) < 15) {
            return 0;
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
            default:
                return 0;
        }

        return strlen($data);
    }

    protected function encodeWebSocketFrame(string $data): string
    {
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

        return $frame;
    }

    protected function processUpstreamData(string $data): void
    {
        if ($this->upstreamProtocol === 'http' && $this->upstreamChunked) {
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

    protected function processFlvBuffer(): void
    {
        if (!$this->flvHeader && strlen($this->upstreamBuffer) >= 13) {
            $this->flvHeader = substr($this->upstreamBuffer, 0, 13);
            $this->upstreamBuffer = substr($this->upstreamBuffer, 13);
            $this->log("收到FLV头", 'debug');
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

                if ($this->flvHeader && $this->videoSequenceTag && $this->audioSequenceTag) {
                    $this->initData = $this->flvHeader . $this->metaDataTag . $this->videoSequenceTag . $this->audioSequenceTag;
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

    protected function sendToAllDownstreams(string $data): void
    {
        foreach ($this->downstreams as $idx => &$downstream) {
            if ($downstream['connected']) {
                if ($downstream['protocol'] === 'rtmp' || $downstream['protocol'] === 'rtmps') {
                    $this->sendRtmpTag($downstream['rtmpClient'], $data);
                } else {
                    $downstream['sendBuffer'] .= $data;
                }
                $this->downstreamStats[$idx]['tags_sent']++;
                $this->stats['tags_sent']++;
            }
        }
    }

    protected function sendRtmpTag($rtmpClient, string $data): void
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

    protected function checkDownstreamHealth(): void
    {
        foreach ($this->downstreams as $idx => &$downstream) {
            if (!$downstream['connected']) continue;

            $isValid = false;
            if ($downstream['protocol'] === 'rtmp' || $downstream['protocol'] === 'rtmps') {
                $socket = $this->getRtmpSocket($downstream['rtmpClient']);
                $isValid = $socket !== null && is_resource($socket) && get_resource_type($socket) === 'stream';
            } else {
                $isValid = $this->isStreamValid($downstream['socket']);
            }

            if (!$isValid) {
                $this->log("下游 {$downstream['url']} 连接断开", 'error');
                $downstream['connected'] = false;

                if ($this->autoReconnect) {
                    $this->downstreamStats[$idx]['reconnect_count']++;
                    $this->log("尝试重连下游 {$downstream['url']}...", 'warning');
                    $this->connectDownstream($idx, $downstream['url']);
                }
            }
        }
    }

    protected function removeDownstream(int $idx): void
    {
        $downstream = $this->downstreams[$idx];
        $this->log("下游 {$downstream['url']} 已移除", 'warning');

        if ($downstream['protocol'] === 'rtmp' || $downstream['protocol'] === 'rtmps') {
            if ($downstream['rtmpClient']) {
                $closeMethod = new \ReflectionMethod($downstream['rtmpClient'], 'close');
                $closeMethod->setAccessible(true);
                $closeMethod->invoke($downstream['rtmpClient']);
            }
        } else {
            $this->safeCloseStream($downstream['socket']);
        }

        $this->downstreams[$idx]['socket'] = null;
        $this->downstreams[$idx]['connected'] = false;
        $this->downstreams[$idx]['rtmpClient'] = null;
    }

    protected function disconnectAll(): void
    {
        $this->safeCloseStream($this->upstreamSocket);
        $this->upstreamSocket = null;

        foreach ($this->downstreams as &$downstream) {
            if ($downstream['protocol'] === 'rtmp' || $downstream['protocol'] === 'rtmps') {
                if ($downstream['rtmpClient']) {
                    try {
                        $closeMethod = new \ReflectionMethod($downstream['rtmpClient'], 'close');
                        $closeMethod->setAccessible(true);
                        $closeMethod->invoke($downstream['rtmpClient']);
                    } catch (\Exception $e) {
                    }
                }
            } else {
                $this->safeCloseStream($downstream['socket']);
            }
            $downstream['socket'] = null;
            $downstream['connected'] = false;
            $downstream['rtmpClient'] = null;
        }
    }

    protected function resetInitData(): void
    {
        $this->flvHeader = '';
        $this->metaDataTag = '';
        $this->videoSequenceTag = '';
        $this->audioSequenceTag = '';
        $this->initDataReady = false;
        $this->initData = '';
        $this->upstreamBuffer = '';
        $this->upstreamChunkBuffer = '';
        $this->upstreamHttpHeaderParsed = false;
        $this->rtmpReadingOperations = [];
        $this->rtmpPrevReadingPacket = [];
        $this->rtmpStreamId = 0;
    }

    protected function handleReconnect(): bool
    {
        if (!$this->autoReconnect) {
            return false;
        }

        $this->retryCount++;
        if ($this->retryCount > $this->maxRetries) {
            $this->log("已达到最大重试次数 {$this->maxRetries}", 'error');
            return false;
        }

        $this->stats['reconnect_count']++;
        $this->log("{$this->retryDelay} 秒后进行第 {$this->retryCount} 次重试...", 'warning');
        sleep($this->retryDelay);
        return true;
    }

    protected function isStreamValid($stream): bool
    {
        if ($stream === null) return false;
        return is_resource($stream) && get_resource_type($stream) === 'stream';
    }

    protected function safeCloseStream(&$stream): void
    {
        if ($stream === null) return;
        if ($this->isStreamValid($stream)) {
            @stream_socket_shutdown($stream, STREAM_SHUT_RDWR);
            @fclose($stream);
        }
        $stream = null;
    }

    protected function printStats(): void
    {
        $elapsed = microtime(true) - $this->stats['start_time'];
        $speed = $elapsed > 0 ? $this->stats['tags_received'] / $elapsed : 0;
        $bitrate = $elapsed > 0 ? ($this->stats['bytes_received'] * 8 / $elapsed) / 1000 : 0;

        $this->log("========================================");
        $this->log("转发统计");
        $this->log("========================================");
        $this->log("运行时间: " . $this->formatTime($elapsed * 1000));
        $this->log("接收 Tag 数: " . number_format($this->stats['tags_received']));
        $this->log("  - 音频: " . number_format($this->stats['audio_tags']));
        $this->log("  - 视频: " . number_format($this->stats['video_tags']));
        $this->log("发送 Tag 数: " . number_format($this->stats['tags_sent']));
        $this->log("接收字节: " . $this->formatBytes($this->stats['bytes_received']));
        $this->log("发送字节: " . $this->formatBytes($this->stats['bytes_sent']));
        $this->log("速率: " . number_format($speed, 1) . " tags/s");
        $this->log("码率: " . number_format($bitrate, 1) . " kbps");
        $this->log("重连次数: " . $this->stats['reconnect_count']);
        $this->log("上游状态: " . ($this->upstreamConnected ? '✓ 连接' : '✗ 断开'));

        $this->log("下游状态:");
        foreach ($this->downstreams as $idx => $downstream) {
            $status = $downstream['connected'] ? '✓ 连接' : '✗ 断开';
            $this->log("  {$downstream['url']}: {$status} | Tags: " . number_format($this->downstreamStats[$idx]['tags_sent'] ?? 0));
        }

        $this->log("========================================");
    }

    protected function printFinalStats(): void
    {
        $this->printStats();
        $this->log("转发已停止");
    }

    protected function formatBytes($bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }

    protected function formatTime($ms): string
    {
        $seconds = floor($ms / 1000);
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;

        if ($hours > 0) {
            return sprintf("%02d:%02d:%02d", $hours, $minutes, $secs);
        } else {
            return sprintf("%02d:%02d", $minutes, $secs);
        }
    }

    protected function log(string $message, string $level = 'info'): void
    {
        if ($level === 'debug') {
            return;
        }

        $time = date('Y-m-d H:i:s');
        $prefix = '';

        switch ($level) {
            case 'error':
                $prefix = "\033[31m[ERROR]\033[0m";
                break;
            case 'warning':
                $prefix = "\033[33m[WARN]\033[0m";
                break;
            case 'success':
                $prefix = "\033[32m[SUCCESS]\033[0m";
                break;
            case 'progress':
                $prefix = "\033[94m[PROGRESS]\033[0m";
                break;
            default:
                $prefix = "[INFO]";
        }

        echo "[{$time}] {$prefix} {$message}\n";
    }
}