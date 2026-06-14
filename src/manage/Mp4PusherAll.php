<?php

namespace Xiaosongshu\Flv2mp4\manage;

/**
 * @purpose MP4 直接推流器（支持 HTTP-FLV 和 WebSocket-FLV）
 * @author yanglong
 * @time 2026年6月12日15:46:05
 * @version 1.1.0
 */
class Mp4PusherAll
{

    private $inputFile;
    private $mp4Data;
    private $boxTree;
    private $videoTrack = null;
    private $audioTrack = null;

    private $hasWrittenHeader = false;
    private $hasWrittenVideoHeader = false;
    private $hasWrittenAudioHeader = false;

    private $sps = '';
    private $pps = '';
    private $audioSpecificConfig = '';
    private $audioSampleRate = 44100;
    private $audioChannels = 2;
    private $audioProfile = 2;

    private $duration = 0;
    private $videoWidth = 0;
    private $videoHeight = 0;
    private $videoFrameRate = 30;

    // === FlvPusher 属性 ===
    private $pushUrl;
    private $speed = 1.0;
    private $autoReconnect = true;
    private $maxRetries = 5;
    private $retryDelay = 3;
    private $useChunked = true;
    private $verbose = true;
    private $statsEnabled = true;

    private $socket;
    private $isRunning = true;

    // ★ WebSocket 相关
    private $isWebSocket = false;
    private $wsKey = '';
    private $wsPath = '/';

    // 统计信息
    private $stats = [
        'tags_sent' => 0,
        'bytes_sent' => 0,
        'start_time' => 0,
        'last_report_time' => 0,
        'reconnect_count' => 0,
    ];

    /**
     * 初始化推流客户端
     * @param string $inputFile
     * @param string $pushUrl
     * @param float $speed
     * @param bool $autoReconnect
     */
    public function __construct(string $inputFile, string $pushUrl, float $speed = 1.0, bool $autoReconnect = true)
    {
        $this->inputFile = $inputFile;
        $this->pushUrl = $pushUrl;
        $this->speed = max(0.1, min(10.0, $speed));
        $this->autoReconnect = $autoReconnect;

        // ★ 检测协议类型
        $urlParts = parse_url($pushUrl);
        $scheme = strtolower($urlParts['scheme'] ?? 'http');
        $this->isWebSocket = ($scheme === 'ws' || $scheme === 'wss');
        $this->wsPath = $urlParts['path'] ?? '/';
        if (!empty($urlParts['query'])) {
            $this->wsPath .= '?' . $urlParts['query'];
        }

        if (!file_exists($inputFile)) {
            throw new \RuntimeException("MP4 文件不存在：{$inputFile}");
        }
    }

    /**
     * 启动推流
     * @return bool
     */
    public function start(): bool
    {
        $protocolName = $this->isWebSocket ? 'WebSocket-FLV' : 'HTTP-FLV';

        $this->log("========================================", 'info');
        $this->log("MP4 Direct Pusher v1.1.0", 'info');
        $this->log("========================================", 'info');
        $this->log("文件：{$this->inputFile}", 'info');
        $this->log("推流地址：{$this->pushUrl}", 'info');
        $this->log("协议：{$protocolName}", 'info');
        $this->log("推流速度：{$this->speed}x", 'info');
        $this->log("自动重连：" . ($this->autoReconnect ? '是' : '否'), 'info');
        $this->log("========================================", 'info');

        $this->mp4Data = file_get_contents($this->inputFile);
        if (empty($this->mp4Data)) {
            $this->log("无法读取 MP4 文件", 'error');
            return false;
        }

        $fileSize = filesize($this->inputFile);
        $this->log("文件大小：" . $this->formatBytes($fileSize), 'info');

        $this->log("开始解析 MP4...", 'info');
        $this->parseMp4Boxes();
        $this->parseTracks();
        $this->log("MP4 解析完成", 'success');
        $this->log("视频：{$this->videoWidth}x{$this->videoHeight}", 'info');
        $this->log("音频：{$this->audioSampleRate}Hz, {$this->audioChannels} 通道", 'info');
        $this->log("时长：{$this->duration} 秒", 'info');

        $result = $this->doPush();

        if ($this->statsEnabled) {
            $this->printFinalStats();
        }

        return $result;
    }

    /**
     * 向服务端推送数据
     * @return bool
     */
    private function doPush()
    {
        $this->stats['start_time'] = microtime(true);
        $this->stats['last_report_time'] = $this->stats['start_time'];
        $this->stats['tags_sent'] = 0;
        $this->stats['bytes_sent'] = 0;

        $retryCount = 0;

        while ($this->isRunning && $retryCount <= $this->maxRetries) {
            try {
                if (!$this->connect()) {
                    throw new \Exception("连接服务器失败");
                }

                $this->pushStream();
                $this->log("推流完成！", 'success');
                return true;

            } catch (\Exception $e) {
                $this->log("推流错误：" . $e->getMessage(), 'error');
                $this->closeConnection();

                if ($this->autoReconnect && $retryCount < $this->maxRetries) {
                    $retryCount++;
                    $this->stats['reconnect_count']++;
                    $this->log("等待 {$this->retryDelay} 秒后进行第 {$retryCount} 次重连...", 'warning');
                    sleep($this->retryDelay);
                    continue;
                } else {
                    $this->log("达到最大重连次数", 'error');
                    return false;
                }
            }
        }
        return false;
    }

    /**
     * 连接服务器
     * @return bool
     */
    private function connect()
    {
        $urlParts = parse_url($this->pushUrl);
        $host = $urlParts['host'];
        $port = $urlParts['port'] ?? 8501;

        $protocolName = $this->isWebSocket ? 'WebSocket-FLV' : 'HTTP-FLV';
        $this->log("连接 {$protocolName} 服务器：{$host}:{$port}", 'info');

        $this->socket = @fsockopen($host, $port, $errno, $errstr, 10);
        if (!$this->socket) {
            $this->log("Socket 连接失败：{$errstr} ({$errno})", 'error');
            return false;
        }

        stream_set_timeout($this->socket, 30);
        stream_set_blocking($this->socket, true);

        if ($this->isWebSocket) {
            return $this->webSocketHandshake($host, $port);
        } else {
            return $this->httpConnect($host);
        }
    }

    // ==================== HTTP-FLV 连接 ====================

    /**
     * HTTP 连接
     * @param string $host
     * @return bool
     */
    private function httpConnect(string $host): bool
    {
        $request = $this->buildHTTPRequest($host);
        $this->log("发送 HTTP 请求头", 'debug');
        fwrite($this->socket, $request);

        $response = '';
        $headersEnded = false;
        while (!feof($this->socket)) {
            $line = fgets($this->socket);
            if ($line === false) break;
            $response .= $line;
            if (trim($line) === '') {
                $headersEnded = true;
                break;
            }
        }

        if (!$headersEnded) {
            $this->log("读取服务器响应失败", 'error');
            return false;
        }

        $firstLine = strtok($response, "\r\n");
        $this->log("服务器响应：{$firstLine}", 'debug');

        if (!strpos($firstLine, '200')) {
            $this->log("服务器返回非200状态：{$firstLine}", 'error');
            return false;
        }

        $this->log("HTTP 连接成功", 'success');
        return true;
    }

    /**
     * 构建 HTTP 请求头
     * @param string $host
     * @return string
     */
    private function buildHTTPRequest(string $host): string
    {
        $path = $this->wsPath;
        $request = "POST {$path} HTTP/1.1\r\n";
        $request .= "Host: {$host}\r\n";
        $request .= "Content-Type: video/x-flv\r\n";
        $request .= "Connection: keep-alive\r\n";
        if ($this->useChunked) {
            $request .= "Transfer-Encoding: chunked\r\n";
        }
        $request .= "\r\n";
        return $request;
    }

    // ==================== WebSocket-FLV 连接 ====================

    /**
     * WebSocket 握手
     * @param string $host
     * @param int $port
     * @return bool
     */
    private function webSocketHandshake(string $host, int $port): bool
    {
        $this->wsKey = base64_encode(random_bytes(16));

        $handshake = "GET {$this->wsPath} HTTP/1.1\r\n";
        $handshake .= "Host: {$host}:{$port}\r\n";
        $handshake .= "Connection: Upgrade\r\n";
        $handshake .= "Pragma: no-cache\r\n";
        $handshake .= "Cache-Control: no-cache\r\n";
        $handshake .= "User-Agent: Mp4Pusher/1.1.0\r\n";
        $handshake .= "Upgrade: websocket\r\n";
        $handshake .= "Origin: http://{$host}:{$port}\r\n";
        $handshake .= "Sec-WebSocket-Version: 13\r\n";
        $handshake .= "Accept-Encoding: gzip, deflate, br\r\n";
        $handshake .= "Accept-Language: zh-CN,zh;q=0.9\r\n";
        $handshake .= "Sec-WebSocket-Key: {$this->wsKey}\r\n";
        $handshake .= "Sec-WebSocket-Extensions: permessage-deflate; client_max_window_bits\r\n";
        $handshake .= "\r\n";

        $this->log("发送 WebSocket 握手请求", 'debug');
        $result = fwrite($this->socket, $handshake);
        if ($result === false) {
            $this->log("发送握手请求失败", 'error');
            return false;
        }

        // 读取握手响应
        $response = '';
        $timeout = time() + 10;
        while (time() < $timeout && !feof($this->socket)) {
            $line = fgets($this->socket);
            if ($line === false) break;
            $response .= $line;
            if (trim($line) === '') break;
        }

        $this->log("握手响应: " . strtok($response, "\r\n"), 'debug');

        // 验证握手响应
        if (!preg_match('#Sec-WebSocket-Accept:\s(.*)$#mUi', $response, $matches)) {
            $this->log("握手失败：未找到 Sec-WebSocket-Accept 头", 'error');
            return false;
        }

        $responseKey = trim($matches[1]);
        $expectedKey = base64_encode(sha1($this->wsKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));

        if ($responseKey !== $expectedKey) {
            $this->log("握手失败：Sec-WebSocket-Accept 验证不通过", 'error');
            return false;
        }

        $this->log("WebSocket 握手成功", 'success');
        return true;
    }

    /**
     * 发送 WebSocket 数据帧（Binary 类型）
     * @param string $data
     */
    private function sendWebSocketFrame(string $data): void
    {
        $len = strlen($data);
        $frame = '';

        // 第一个字节: FIN(1) + RSV(3) + Opcode(4) = 0x82 (Binary)
        $frame .= chr(0x82);

        // 第二个字节: MASK(1) + Payload length(7)
        if ($len < 126) {
            $frame .= chr(0x80 | $len);
        } elseif ($len < 65536) {
            $frame .= chr(0x80 | 126);
            $frame .= pack('n', $len);
        } else {
            $frame .= chr(0x80 | 127);
            $frame .= pack('J', $len);
        }

        // 4字节随机掩码
        $mask = random_bytes(4);
        $frame .= $mask;

        // 掩码处理
        for ($i = 0; $i < $len; $i++) {
            $frame .= chr(ord($data[$i]) ^ ord($mask[$i % 4]));
        }

        fwrite($this->socket, $frame);
    }

    /**
     * 发送 WebSocket Close Frame
     */
    private function sendCloseFrame(): void
    {
        $frame = chr(0x88);  // FIN + Close
        $frame .= chr(0x80); // MASK + length=0
        $mask = random_bytes(4);
        $frame .= $mask;
        fwrite($this->socket, $frame);
    }

    // ==================== 推流核心 ====================

    /**
     * 推送数据流
     * @return void
     */
    private function pushStream()
    {
        // 发送 FLV Header
        $this->sendFLVData($this->generateFlvHeader());
        $this->sendFLVData(pack('N', 0));

        // 写入 onMetaData 标签
        $this->writeMetaData();

        // 提取并推送媒体数据
        $this->extractAndPushMediaData();

        // ★ 发送结束标记
        if ($this->isWebSocket) {
            $this->sendCloseFrame();
        }
    }

    /**
     * 推送 FLV 数据（根据协议自动选择发送方式）
     * @param string $data
     */
    private function sendFLVData(string $data): void
    {
        if ($this->isWebSocket) {
            $this->sendWebSocketFrame($data);
        } elseif ($this->useChunked) {
            fwrite($this->socket, dechex(strlen($data)) . "\r\n");
            fwrite($this->socket, $data);
            fwrite($this->socket, "\r\n");
        } else {
            fwrite($this->socket, $data);
        }
    }

    /**
     * 生成 FLV Header
     * @return string
     */
    private function generateFlvHeader(): string
    {
        $flags = 0;
        if ($this->videoTrack) $flags |= 0x01;
        if ($this->audioTrack) $flags |= 0x04;
        return "FLV\x01" . chr($flags) . "\x00\x00\x00\x09";
    }

    // ==================== MP4 解析（保持不变） ====================

    private function parseMp4Boxes(): void
    {
        $this->boxTree = $this->parseBox($this->mp4Data, 0, strlen($this->mp4Data));
    }

    private function parseBox(string $data, int $offset, int $end): array
    {
        $boxes = [];
        while ($offset + 8 <= $end) {
            $size = unpack('N', substr($data, $offset, 4))[1];
            $type = substr($data, $offset + 4, 4);
            if ($size == 1) {
                if ($offset + 16 <= $end) {
                    $size = unpack('J', substr($data, $offset + 8, 8))[1];
                    $headerSize = 16;
                } else break;
            } elseif ($size == 0) {
                $size = $end - $offset;
                $headerSize = 8;
            } else {
                $headerSize = 8;
            }
            $boxEnd = $offset + $size;
            if ($boxEnd > $end) break;
            $boxData = substr($data, $offset + $headerSize, $size - $headerSize);
            $box = ['type' => $type, 'size' => $size, 'offset' => $offset, 'data' => $boxData, 'children' => []];
            if ($size > $headerSize) {
                $box['children'] = $this->parseBox($data, $offset + $headerSize, $boxEnd);
            }
            $boxes[] = $box;
            $offset = $boxEnd;
        }
        return $boxes;
    }

    private function findBox(array $boxes, string $type): ?array
    {
        foreach ($boxes as $box) {
            if ($box['type'] === $type) return $box;
            if (!empty($box['children'])) {
                $found = $this->findBox($box['children'], $type);
                if ($found !== null) return $found;
            }
        }
        return null;
    }

    private function findAllBoxes(array $boxes, string $type): array
    {
        $result = [];
        foreach ($boxes as $box) {
            if ($box['type'] === $type) $result[] = $box;
            if (!empty($box['children'])) {
                $result = array_merge($result, $this->findAllBoxes($box['children'], $type));
            }
        }
        return $result;
    }

    private function parseTracks(): void
    {
        $moov = $this->findBox($this->boxTree, 'moov');
        if (!$moov) throw new \RuntimeException("未找到 moov 盒子");

        $mvhd = $this->findBox([$moov], 'mvhd');
        if ($mvhd) {
            $mvhdData = $mvhd['data'];
            $version = ord($mvhdData[0]);
            if ($version == 0) {
                $timescale = unpack('N', substr($mvhdData, 12, 4))[1];
                $duration = unpack('N', substr($mvhdData, 16, 4))[1];
            } else {
                $timescale = unpack('N', substr($mvhdData, 20, 4))[1];
                $duration = unpack('J', substr($mvhdData, 24, 8))[1];
            }
            $this->duration = round($duration * 1000 / $timescale) / 1000;
        }

        $traks = $this->findAllBoxes([$moov], 'trak');
        foreach ($traks as $trak) {
            $this->parseTrack($trak);
        }
    }

    private function parseTrack(array $trak): void
    {
        $tkhd = $this->findBox([$trak], 'tkhd');
        if (!$tkhd) return;

        $tkhdData = $tkhd['data'];
        $trackId = unpack('N', substr($tkhdData, 12, 4))[1];

        $version = ord($tkhdData[0]);
        if ($version == 0) {
            $widthOffset  = 76;
            $heightOffset = 80;
        } else {
            $widthOffset  = 88;
            $heightOffset = 92;
        }

        $tkhdWidth  = 0;
        $tkhdHeight = 0;
        if (strlen($tkhdData) >= $heightOffset + 4) {
            $tkhdWidth  = unpack('N', substr($tkhdData, $widthOffset, 4))[1] / 65536;
            $tkhdHeight = unpack('N', substr($tkhdData, $heightOffset, 4))[1] / 65536;
        }

        if ($tkhdWidth > 0 && $tkhdHeight > 0) {
            $this->videoWidth  = (int)round($tkhdWidth);
            $this->videoHeight = (int)round($tkhdHeight);
        }

        $mdia = $this->findBox([$trak], 'mdia');
        if (!$mdia) return;
        $hdlr = $this->findBox([$mdia], 'hdlr');
        if (!$hdlr) return;
        $handlerType = substr($hdlr['data'], 8, 4);

        $minf = $this->findBox([$mdia], 'minf');
        if (!$minf) return;
        $stbl = $this->findBox([$minf], 'stbl');
        if (!$stbl) return;
        $stsd = $this->findBox([$stbl], 'stsd');
        if (!$stsd) return;
        $mdhd = $this->findBox([$mdia], 'mdhd');
        $timescale = 90000;
        if ($mdhd) {
            $timescale = unpack('N', substr($mdhd['data'], 12, 4))[1];
        }

        $stsdData = $stsd['data'];
        $pos = 8;
        while ($pos + 8 <= strlen($stsdData)) {
            $entrySize = unpack('N', substr($stsdData, $pos, 4))[1];
            $entryType = substr($stsdData, $pos + 4, 4);

            if ($handlerType === 'vide' && $entryType === 'avc1') {
                $this->videoTrack = [
                    'id'        => $trackId,
                    'type'      => 'video',
                    'codec'     => 'avc1',
                    'timescale' => $timescale
                ];
                $this->parseAvcCFromBox(substr($stsdData, $pos, $entrySize));
                break;
            } elseif ($handlerType === 'soun' && $entryType === 'mp4a') {
                $this->audioTrack = [
                    'id'        => $trackId,
                    'type'      => 'audio',
                    'codec'     => 'mp4a',
                    'timescale' => $timescale
                ];
                $this->parseEsdsFromBox(substr($stsdData, $pos, $entrySize));
                break;
            }
            $pos += $entrySize;
        }
    }

    private function parseAvcCFromBox(string $data): void
    {
        $pos = strpos($data, 'avcC');
        if ($pos === false) return;
        if ($pos < 4) return;

        $boxSize = unpack('N', substr($data, $pos - 4, 4))[1];
        $avcCData = substr($data, $pos + 4, $boxSize - 8);

        $this->parseAvcC($avcCData);
    }

    private function parseAvcC(string $data): void
    {
        if (strlen($data) < 8) return;
        $numSps = ord($data[5]) & 0x1F;
        $offset = 6;
        for ($i = 0; $i < $numSps; $i++) {
            if ($offset + 2 > strlen($data)) break;
            $spsLength = unpack('n', substr($data, $offset, 2))[1];
            $offset += 2;
            if ($offset + $spsLength > strlen($data)) break;
            $this->sps = substr($data, $offset, $spsLength);
            $offset += $spsLength;
            $this->parseSpsForDimensions($this->sps);
            break;
        }
        $numPps = ord($data[$offset]); $offset++;
        for ($i = 0; $i < $numPps; $i++) {
            if ($offset + 2 > strlen($data)) break;
            $ppsLength = unpack('n', substr($data, $offset, 2))[1];
            $offset += 2;
            if ($offset + $ppsLength > strlen($data)) break;
            $this->pps = substr($data, $offset, $ppsLength);
            break;
        }
    }

    private function parseSpsForDimensions(string $sps): void
    {
        if (strlen($sps) < 10) return;

        $pos = 0;
        if (ord($sps[0]) & 0x80) {
            $pos++;
        }

        $pos += 3;
        $pos++;
        $pos = $this->skipUEG($sps, $pos);

        $picOrderCntType = $this->readUEG($sps, $pos);
        $pos = $this->skipUEG($sps, $pos);

        if ($picOrderCntType == 0) {
            $pos = $this->skipUEG($sps, $pos);
            $pos = $this->skipUEG($sps, $pos);
        } elseif ($picOrderCntType == 1) {
            $pos = $this->skipUEG($sps, $pos);
            $pos = $this->skipUEG($sps, $pos);
            $pos = $this->skipUEG($sps, $pos);
            $pos = $this->skipUEG($sps, $pos);
            $numRefFramesInPicOrderCntCycle = $this->readUEG($sps, $pos);
            $pos = $this->skipUEG($sps, $pos);
            for ($i = 0; $i < $numRefFramesInPicOrderCntCycle; $i++) {
                $pos = $this->skipSEG($sps, $pos);
            }
        }

        $pos = $this->skipUEG($sps, $pos);
        $pos++;

        $picWidthInMbsMinus1 = $this->readUEG($sps, $pos);
        $pos = $this->skipUEG($sps, $pos);

        $picHeightInMapUnitsMinus1 = $this->readUEG($sps, $pos);
        $pos = $this->skipUEG($sps, $pos);
    }

    private function readUEG(string $data, int &$pos): int
    {
        $result = 0;
        $leadingZeroBits = 0;

        while ($pos < strlen($data) && (ord($data[$pos]) & 0x80) == 0) {
            $leadingZeroBits++;
            $pos++;
        }

        if ($pos >= strlen($data)) return 0;

        $result = ord($data[$pos]) & 0x7F;
        $pos++;

        for ($i = 0; $i < $leadingZeroBits; $i++) {
            if ($pos >= strlen($data)) break;
            $result = ($result << 7) | (ord($data[$pos]) & 0x7F);
            $pos++;
        }

        return $result - 1;
    }

    private function skipUEG(string $data, int $pos): int
    {
        while ($pos < strlen($data) && (ord($data[$pos]) & 0x80) == 0) {
            $pos++;
        }
        if ($pos >= strlen($data)) return $pos;
        $pos++;
        return $pos;
    }

    private function skipSEG(string $data, int $pos): int
    {
        while ($pos < strlen($data) && (ord($data[$pos]) & 0x80) == 0) {
            $pos++;
        }
        if ($pos >= strlen($data)) return $pos;
        $pos++;
        return $pos;
    }

    private function parseEsdsFromBox(string $data): void
    {
        $pos = strpos($data, 'esds');
        if ($pos === false) return;
        if ($pos < 4) return;

        $boxSize = unpack('N', substr($data, $pos - 4, 4))[1];
        $esdsData = substr($data, $pos + 4, $boxSize - 8);

        $this->parseEsds($esdsData);
    }

    private function parseEsds(string $data): void
    {
        if (strlen($data) < 20) return;
        $pos = 4;

        while ($pos < strlen($data)) {
            if ($pos + 1 > strlen($data)) break;
            $tag = ord($data[$pos]);
            $pos++;

            if ($pos >= strlen($data)) break;
            $length = 0;
            while ($pos < strlen($data)) {
                $byte = ord($data[$pos]);
                $length = ($length << 7) | ($byte & 0x7F);
                $pos++;
                if (($byte & 0x80) == 0) break;
            }

            if ($pos + $length > strlen($data)) break;

            $contentStart = $pos;

            switch ($tag) {
                case 0x03:
                    $pos += 3;
                    $remainingLength = $length - 3;
                    if ($remainingLength > 0 && $pos + $remainingLength <= strlen($data)) {
                        $subData = substr($data, $pos, $remainingLength);
                        $this->parseEsdsNested($subData);
                    }
                    break;

                case 0x04:
                    $pos += 13;
                    $remainingLength = $length - 13;
                    if ($remainingLength > 0 && $pos + $remainingLength <= strlen($data)) {
                        $subData = substr($data, $pos, $remainingLength);
                        $this->parseEsdsNested($subData);
                    }
                    break;

                case 0x05:
                    $this->audioSpecificConfig = substr($data, $contentStart, $length);
                    if ($length >= 2) {
                        $config = unpack('n', $this->audioSpecificConfig)[1];
                        $this->audioProfile = ($config >> 11) & 0x1F;
                        $freqIndex = ($config >> 7) & 0x0F;
                        $this->audioChannels = ($config >> 3) & 0x0F;
                        $rates = [96000,88200,64000,48000,44100,32000,24000,22050,16000,12000,11025,8000,7350];
                        $this->audioSampleRate = $rates[$freqIndex] ?? 44100;
                    }
                    return;

                default:
                    break;
            }

            $pos = $contentStart + $length;
        }
    }

    private function parseEsdsNested(string $data): void
    {
        $pos = 0;
        while ($pos < strlen($data)) {
            if ($pos + 1 > strlen($data)) break;
            $tag = ord($data[$pos]);
            $pos++;

            if ($pos >= strlen($data)) break;
            $length = 0;
            while ($pos < strlen($data)) {
                $byte = ord($data[$pos]);
                $length = ($length << 7) | ($byte & 0x7F);
                $pos++;
                if (($byte & 0x80) == 0) break;
            }

            if ($pos + $length > strlen($data)) break;

            $contentStart = $pos;

            switch ($tag) {
                case 0x03:
                    $pos += 3;
                    $remainingLength = $length - 3;
                    if ($remainingLength > 0 && $pos + $remainingLength <= strlen($data)) {
                        $subData = substr($data, $pos, $remainingLength);
                        $this->parseEsdsNested($subData);
                    }
                    break;

                case 0x04:
                    $pos += 13;
                    $remainingLength = $length - 13;
                    if ($remainingLength > 0 && $pos + $remainingLength <= strlen($data)) {
                        $subData = substr($data, $pos, $remainingLength);
                        $this->parseEsdsNested($subData);
                    }
                    break;

                case 0x05:
                    $this->audioSpecificConfig = substr($data, $contentStart, $length);
                    if ($length >= 2) {
                        $config = unpack('n', $this->audioSpecificConfig)[1];
                        $this->audioProfile = ($config >> 11) & 0x1F;
                        $freqIndex = ($config >> 7) & 0x0F;
                        $this->audioChannels = ($config >> 3) & 0x0F;
                        $rates = [96000,88200,64000,48000,44100,32000,24000,22050,16000,12000,11025,8000,7350];
                        $this->audioSampleRate = $rates[$freqIndex] ?? 44100;
                    }
                    return;

                default:
                    break;
            }

            $pos = $contentStart + $length;
        }
    }

    // ==================== 媒体数据提取与推送 ====================

    private function extractAndPushMediaData(): void
    {
        $mdat = $this->findBox($this->boxTree, 'mdat');
        if (!$mdat) throw new \RuntimeException("未找到 mdat 盒子");
        $moov = $this->findBox($this->boxTree, 'moov');
        if (!$moov) return;

        $allSamples = [];

        $traks = $this->findAllBoxes([$moov], 'trak');
        foreach ($traks as $trak) {
            $mdia = $this->findBox([$trak], 'mdia');
            if (!$mdia) continue;
            $hdlr = $this->findBox([$mdia], 'hdlr');
            if (!$hdlr) continue;
            $handlerType = substr($hdlr['data'], 8, 4);
            $stbl = $this->findBox([$mdia], 'stbl');
            if (!$stbl) continue;

            $samples = $this->extractSamplesFromStbl($stbl, $mdat['data'], $mdat['offset'], $handlerType);
            foreach ($samples as &$s) {
                $s['type'] = ($handlerType === 'vide') ? 'video' : 'audio';
            }
            $allSamples = array_merge($allSamples, $samples);
        }

        usort($allSamples, function($a, $b) {
            return $a['dtsMs'] - $b['dtsMs'];
        });

        $this->log("共提取 " . count($allSamples) . " 个样本", 'info');

        $startRealTime = microtime(true);
        $firstTimestamp = -1;
        $sampleCount = 0;
        $lastProgressTime = 0;

        foreach ($allSamples as $sample) {
            $dtsMs = $sample['dtsMs'];

            if ($firstTimestamp < 0) {
                $firstTimestamp = $dtsMs;
            }

            if ($this->speed > 0 && $dtsMs > 0) {
                $adjustedTimestamp = $dtsMs - $firstTimestamp;
                $elapsedReal = (microtime(true) - $startRealTime) * 1000;
                $targetTimestamp = $adjustedTimestamp / $this->speed;

                if ($targetTimestamp > $elapsedReal) {
                    $sleepMs = $targetTimestamp - $elapsedReal;
                    if ($sleepMs > 0 && $sleepMs < 5000) {
                        usleep((int)($sleepMs * 1000));
                    }
                }
            }

            if ($sample['type'] === 'video') {
                $this->writeVideoSample($sample['data'], $sample['dtsMs'], $sample['ctsMs'] ?? 0, $sample['keyframe']);
            } else {
                $this->writeAudioSample($sample['data'], $sample['dtsMs']);
            }

            $sampleCount++;

            $currentTime = microtime(true);
            if ($this->statsEnabled && $sampleCount % 100 == 0 && ($currentTime - $lastProgressTime) >= 1) {
                $this->printProgress($dtsMs);
                $lastProgressTime = $currentTime;
            }
        }

        $this->log("共处理 {$sampleCount} 个样本", 'info');
    }

    private function extractSamplesFromStbl(array $stbl, string $mdatData, int $mdatOffset, string $handlerType): array
    {
        $stsz = $this->findBox([$stbl], 'stsz');
        $stco = $this->findBox([$stbl], 'stco');
        $stsc = $this->findBox([$stbl], 'stsc');
        $stts = $this->findBox([$stbl], 'stts');
        $ctts = $this->findBox([$stbl], 'ctts');
        $stss = $this->findBox([$stbl], 'stss');

        if (!$stsz || !$stco || !$stsc || !$stts) return [];

        $timescale = ($handlerType === 'vide') ? ($this->videoTrack['timescale'] ?? 90000) : ($this->audioTrack['timescale'] ?? 90000);

        $stszData = $stsz['data'];
        $stszOffset = 4;

        $sampleSize = unpack('N', substr($stszData, $stszOffset, 4))[1];
        $sampleCount = unpack('N', substr($stszData, $stszOffset + 4, 4))[1];
        $sampleSizes = [];
        if ($sampleSize == 0) {
            for ($i = 0; $i < $sampleCount; $i++) {
                $sampleSizes[] = unpack('N', substr($stszData, $stszOffset + 8 + $i * 4, 4))[1];
            }
        } else {
            $sampleSizes = array_fill(0, $sampleCount, $sampleSize);
        }

        $stscData = $stsc['data'];
        $stscOffset = 0;

        if (strlen($stscData) >= 8 && unpack('N', substr($stscData, 0, 4))[1] == 0) {
            $stscOffset = 4;
        }

        $stscEntries = unpack('N', substr($stscData, $stscOffset, 4))[1];
        $chunkMap = [];
        $pos = $stscOffset + 4;
        for ($i = 0; $i < $stscEntries; $i++) {
            $firstChunk = unpack('N', substr($stscData, $pos, 4))[1];
            $samplesPerChunk = unpack('N', substr($stscData, $pos+4, 4))[1];
            $descIndex = unpack('N', substr($stscData, $pos+8, 4))[1];
            $pos += 12;
            $chunkMap[$firstChunk] = ['samples' => $samplesPerChunk, 'desc' => $descIndex];
        }

        $stcoData = $stco['data'];
        $stcoOffset = 0;

        if (strlen($stcoData) >= 8 && unpack('N', substr($stcoData, 0, 4))[1] == 0) {
            $stcoOffset = 4;
        }

        $chunkCount = unpack('N', substr($stcoData, $stcoOffset, 4))[1];
        $chunkOffsets = [];
        for ($i = 0; $i < $chunkCount; $i++) {
            $chunkOffsets[] = unpack('N', substr($stcoData, $stcoOffset + 4 + $i * 4, 4))[1];
        }

        $chunkSamples = [];
        for ($chunk = 0; $chunk < $chunkCount; $chunk++) {
            $chunkNum = $chunk + 1;
            $samples = 0;
            foreach ($chunkMap as $firstChunk => $map) {
                if ($chunkNum >= $firstChunk) {
                    $samples = $map['samples'];
                }
            }
            $chunkSamples[] = $samples;
        }

        $sampleOffsets = [];
        $sampleIndex = 0;
        foreach ($chunkOffsets as $chunkIdx => $chunkOffset) {
            $count = $chunkSamples[$chunkIdx];
            $runningOffset = $chunkOffset;
            for ($j = 0; $j < $count && $sampleIndex < count($sampleSizes); $j++) {
                $sampleOffsets[$sampleIndex] = $runningOffset;
                $runningOffset += $sampleSizes[$sampleIndex];
                $sampleIndex++;
            }
        }

        $sttsData = $stts['data'];
        $sttsEntries = unpack('N', substr($sttsData, 4, 4))[1];
        $timeDeltas = [];
        $pos = 8;
        for ($i = 0; $i < $sttsEntries; $i++) {
            $count = unpack('N', substr($sttsData, $pos, 4))[1];
            $delta = unpack('N', substr($sttsData, $pos+4, 4))[1];
            $pos += 8;
            for ($j = 0; $j < $count; $j++) {
                $timeDeltas[] = $delta;
            }
        }

        $ctOffsets = [];
        if ($ctts && $handlerType === 'vide') {
            $cttsData = $ctts['data'];
            $cttsEntries = unpack('N', substr($cttsData, 4, 4))[1];
            $pos = 8;
            for ($i = 0; $i < $cttsEntries; $i++) {
                $count = unpack('N', substr($cttsData, $pos, 4))[1];
                $offset = unpack('N', substr($cttsData, $pos+4, 4))[1];
                $pos += 8;
                for ($j = 0; $j < $count; $j++) {
                    $ctOffsets[] = $offset;
                }
            }
        }

        $keyframeSet = [];
        if ($stss && $handlerType === 'vide') {
            $stssData = $stss['data'];
            $entries = unpack('N', substr($stssData, 4, 4))[1];
            for ($i = 0; $i < $entries; $i++) {
                $keyframeSet[unpack('N', substr($stssData, 8 + $i*4, 4))[1] - 1] = true;
            }
        }

        $samples = [];
        $dtsTicks = 0;
        for ($i = 0; $i < count($sampleSizes) && $i < count($sampleOffsets); $i++) {
            $offset = $sampleOffsets[$i];
            if ($offset < 0 || $offset + $sampleSizes[$i] > strlen($this->mp4Data)) continue;
            $rawData = substr($this->mp4Data, $offset, $sampleSizes[$i]);

            $ctsTicks = $ctOffsets[$i] ?? 0;
            $dtsMs = round($dtsTicks * 1000 / $timescale);
            $ctsMs = round($ctsTicks * 1000 / $timescale);
            $isKeyframe = isset($keyframeSet[$i]);

            $samples[] = [
                'data' => $rawData,
                'dtsMs' => $dtsMs,
                'ctsMs' => $ctsMs,
                'keyframe' => $isKeyframe
            ];

            $dtsTicks += $timeDeltas[$i] ?? 0;
        }
        return $samples;
    }

    private function writeVideoSample(string $data, int $dtsMs, int $ctsMs, bool $isKeyFrame): void
    {
        if (!$this->hasWrittenVideoHeader && !empty($this->sps)) {
            $this->writeAVCSequenceHeader();
        }

        $codecId = 7;
        $frameType = $isKeyFrame ? 1 : 2;

        $videoData = chr(($frameType << 4) | $codecId) . "\x01" .
            chr(($ctsMs >> 16) & 0xFF) . chr(($ctsMs >> 8) & 0xFF) . chr($ctsMs & 0xFF) .
            $data;

        $this->writeFLVTag(9, $videoData, $dtsMs);
    }

    private function writeAudioSample(string $data, int $dtsMs): void
    {
        if (!$this->hasWrittenAudioHeader && !empty($this->audioSpecificConfig)) {
            $this->writeAACSequenceHeader();
        }

        $soundFormat = 10;
        $soundRate = $this->getSoundRate();
        $soundSize = 1;
        $soundType = ($this->audioChannels == 2) ? 1 : 0;
        $audioHeader = ($soundFormat << 4) | ($soundRate << 2) | ($soundSize << 1) | $soundType;
        $audioData = chr($audioHeader) . "\x01" . $data;

        $this->writeFLVTag(8, $audioData, $dtsMs);
    }

    private function writeAVCSequenceHeader(): void
    {
        $configVersion = "\x01";
        $profile = $this->sps[1] ?? "\x42";
        $compat  = $this->sps[2] ?? "\x00";
        $level   = $this->sps[3] ?? "\x1F";
        $lengthMinusOne = "\xFF";
        $spsNum = "\xE1";
        $spsLen = pack('n', strlen($this->sps));
        $ppsNum = "\x01";
        $ppsLen = pack('n', strlen($this->pps));
        $record = $configVersion . $profile . $compat . $level . $lengthMinusOne . $spsNum . $spsLen . $this->sps . $ppsNum . $ppsLen . $this->pps;

        $videoData = "\x17\x00\x00\x00\x00" . $record;
        $this->writeFLVTag(9, $videoData, 0);
        $this->hasWrittenVideoHeader = true;
    }

    private function writeAACSequenceHeader(): void
    {
        $soundFormat = 10;
        $soundRate = $this->getSoundRate();
        $soundSize = 1;
        $soundType = ($this->audioChannels == 2) ? 1 : 0;
        $audioHeader = ($soundFormat << 4) | ($soundRate << 2) | ($soundSize << 1) | $soundType;
        $audioData = chr($audioHeader) . "\x00" . $this->audioSpecificConfig;
        $this->writeFLVTag(8, $audioData, 0);
        $this->hasWrittenAudioHeader = true;
    }

    private function getSoundRate(): int
    {
        switch ($this->audioSampleRate) {
            case 5500:  return 0;
            case 11025: return 1;
            case 22050: return 2;
            case 44100: return 3;
            case 48000: return 4;
            default:    return 3;
        }
    }

    private function writeFLVTag(int $tagType, string $data, int $timestamp): void
    {
        $dataSize = strlen($data);
        $timestamp &= 0xFFFFFFFF;
        $tsLow = $timestamp & 0xFFFFFF;
        $tsHigh = ($timestamp >> 24) & 0xFF;

        $tag = chr($tagType);
        $tag .= chr(($dataSize >> 16) & 0xFF) . chr(($dataSize >> 8) & 0xFF) . chr($dataSize & 0xFF);
        $tag .= chr(($tsLow >> 16) & 0xFF) . chr(($tsLow >> 8) & 0xFF) . chr($tsLow & 0xFF);
        $tag .= chr($tsHigh);
        $tag .= "\x00\x00\x00";

        $fullTag = $tag . $data . pack('N', 11 + $dataSize);
        $this->sendFLVData($fullTag);

        $this->stats['tags_sent']++;
        $this->stats['bytes_sent'] += strlen($fullTag);
    }

    private function writeMetaData(): void
    {
        $metaData = [
            'duration' => $this->duration,
            'width' => $this->videoWidth,
            'height' => $this->videoHeight,
            'videocodecid' => 'avc1',
            'audiocodecid' => 'mp4a',
            'audiosamplerate' => $this->audioSampleRate,
            'audiochannels' => $this->audioChannels,
            'framerate' => $this->videoFrameRate,
        ];

        $data = $this->serializeAmf0($metaData);
        $onMetaData = $this->serializeAmf0('onMetaData') . $data;

        $this->writeFLVTag(18, $onMetaData, 0);
    }

    private function serializeAmf0($value): string
    {
        if (is_string($value)) {
            return "\x02" . pack('n', strlen($value)) . $value;
        } elseif (is_int($value)) {
            return "\x00" . $this->packDoubleBE((float)$value);
        } elseif (is_float($value) || is_numeric($value)) {
            return "\x00" . $this->packDoubleBE((float)$value);
        } elseif (is_bool($value)) {
            return $value ? "\x01\x01" : "\x01\x00";
        } elseif (is_array($value)) {
            $result = "\x03";
            foreach ($value as $key => $val) {
                if (!is_string($key)) continue;
                $result .= pack('n', strlen($key)) . $key;
                $result .= $this->serializeAmf0($val);
            }
            $result .= "\x00\x00\x09";
            return $result;
        } elseif ($value === null) {
            return "\x05";
        }
        return '';
    }

    private function packDoubleBE(float $value): string
    {
        return strrev(pack('d', $value));
    }

    // ==================== 辅助方法 ====================

    private function closeConnection()
    {
        if ($this->socket) {
            @fclose($this->socket);
            $this->socket = null;
        }
    }

    private function log(string $message, string $level = 'info')
    {
        if (!$this->verbose && $level == 'debug') return;

        $timestamp = date('Y-m-d H:i:s');
        $prefix = match($level) {
            'error'   => "\033[31m[ERROR]\033[0m",
            'warning' => "\033[33m[WARN]\033[0m",
            'success' => "\033[32m[SUCCESS]\033[0m",
            'debug'   => "\033[90m[DEBUG]\033[0m",
            default   => "[INFO]",
        };

        echo "[{$timestamp}] {$prefix} {$message}\n";
    }

    private function printProgress($currentTimestamp) {
        $elapsed = microtime(true) - $this->stats['start_time'];
        $speed = $elapsed > 0 ? $this->stats['tags_sent'] / $elapsed : 0;
        $bitrate = $elapsed > 0 ? ($this->stats['bytes_sent'] * 8 / $elapsed) / 1000 : 0;

        $this->log(sprintf(
            "[进度] 已发送: %d tags | 时间戳: %s | 速率: %.1f tags/s | 码率: %.1f kbps",
            $this->stats['tags_sent'],
            $this->formatTime($currentTimestamp),
            $speed,
            $bitrate
        ), 'debug');
    }

    private function printFinalStats() {
        $elapsed = microtime(true) - $this->stats['start_time'];
        $avgSpeed = $elapsed > 0 ? $this->stats['tags_sent'] / $elapsed : 0;
        $totalBitrate = $elapsed > 0 ? ($this->stats['bytes_sent'] * 8 / $elapsed) / 1000 : 0;

        $this->log("========================================", 'info');
        $this->log("推流统计", 'info');
        $this->log("========================================", 'info');
        $this->log("总耗时: " . $this->formatTime($elapsed * 1000), 'info');
        $this->log("发送 Tag 数: " . number_format($this->stats['tags_sent']), 'info');
        $this->log("发送字节数: " . $this->formatBytes($this->stats['bytes_sent']), 'info');
        $this->log("平均速率: " . number_format($avgSpeed, 1) . " tags/s", 'info');
        $this->log("平均码率: " . number_format($totalBitrate, 1) . " kbps", 'info');
        $this->log("重连次数: " . $this->stats['reconnect_count'], 'info');
        $this->log("========================================", 'info');
    }

    private function formatTime($ms) {
        $seconds = floor($ms / 1000);
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;
        return $hours > 0
            ? sprintf("%02d:%02d:%02d", $hours, $minutes, $secs)
            : sprintf("%02d:%02d", $minutes, $secs);
    }

    private function formatBytes($bytes) {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}