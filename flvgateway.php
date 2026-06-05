<?php

// flv_gateway_http.php
// HTTP-FLV流媒体网关 - 完整修复版

class FlvGateway
{
    private $upstreamBaseUrl = 'http://192.168.96.1:8501';
    private $listenPort = 8080;
    private $clients = [];
    private $pendingClients = [];

    private $streamCache = [];
    private $upstream = null;
    private $buffer = '';
    private $currentStreamPath = '';
    private $chunkedEncoding = false;
    private $chunkBuffer = '';

    public function start()
    {
        $serverSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_set_option($serverSocket, SOL_SOCKET, SO_REUSEADDR, 1);
        socket_bind($serverSocket, '0.0.0.0', $this->listenPort);
        socket_listen($serverSocket, 10);
        socket_set_nonblock($serverSocket);

        echo "HTTP-FLV网关启动，监听端口: {$this->listenPort}\n";
        echo "上游服务器: {$this->upstreamBaseUrl}\n\n";

        while (true) {
            $this->acceptNewClient($serverSocket);
            $this->processPendingClients();
            $this->readFromUpstream();
            $this->cleanupClients();
            usleep(5000);
        }

        socket_close($serverSocket);
    }

    private function acceptNewClient($serverSocket)
    {
        $clientSocket = @socket_accept($serverSocket);
        if (!$clientSocket) return;

        socket_set_nonblock($clientSocket);
        socket_set_option($clientSocket, SOL_SOCKET, TCP_NODELAY, 1);

        // 读取HTTP请求
        $request = '';
        $startTime = time();

        while (time() - $startTime < 3) {
            $chunk = @socket_read($clientSocket, 4096);
            if ($chunk === false) break;
            if ($chunk === '') break;
            $request .= $chunk;
            if (strpos($request, "\r\n\r\n") !== false) break;
        }

        preg_match('#GET\s+/([^\s]+)#', $request, $matches);
        $streamPath = $matches[1] ?? '';
        $streamPath = preg_replace('/\.flv$/', '', $streamPath);

        if (empty($streamPath)) {
            @socket_write($clientSocket, "HTTP/1.1 400 Bad Request\r\nConnection: close\r\n\r\n");
            socket_close($clientSocket);
            return;
        }

        echo "请求流: /{$streamPath}\n";

        // 先发送HTTP响应头
        $httpHeader = "HTTP/1.1 200 OK\r\n"
            . "Content-Type: video/x-flv\r\n"
            . "Connection: keep-alive\r\n"
            . "Cache-Control: no-cache\r\n"
            . "Access-Control-Allow-Origin: *\r\n"
            . "Server: FlvGateway/1.0\r\n"
            . "\r\n";
        @socket_write($clientSocket, $httpHeader);

        // 检查缓存是否就绪
        if (isset($this->streamCache[$streamPath]) && $this->streamCache[$streamPath]['ready']) {
            $this->sendInitData($clientSocket, $streamPath);
            $this->clients[(int)$clientSocket] = [
                'socket' => $clientSocket,
                'stream' => $streamPath,
                'last_active' => time(),
            ];
            echo "+ 客户端就绪（缓存），总数: " . count($this->clients) . "\n";
            return;
        }

        // 切换流
        if ($this->currentStreamPath !== $streamPath) {
            echo "切换流: {$streamPath}\n";
            if ($this->upstream) @socket_close($this->upstream);

            $this->currentStreamPath = $streamPath;
            $this->resetCache($streamPath);
            $this->upstream = $this->connectUpstream($streamPath);

            if (!$this->upstream) {
                @socket_write($clientSocket, "HTTP/1.1 503 Service Unavailable\r\n\r\n");
                socket_close($clientSocket);
                return;
            }
        }

        // 加入等待队列
        $this->pendingClients[] = [
            'socket' => $clientSocket,
            'stream' => $streamPath,
        ];
        echo "+ 等待初始化，队列: " . count($this->pendingClients) . "\n";
    }

    private function connectUpstream($streamPath)
    {
        $url = "{$this->upstreamBaseUrl}/{$streamPath}.flv";
        $parsed = parse_url($url);
        $host = $parsed['host'];
        $port = $parsed['port'] ?? 80;
        $path = $parsed['path'] . (isset($parsed['query']) ? '?' . $parsed['query'] : '');

        echo "连接: {$host}:{$port}{$path}\n";

        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_set_option($socket, SOL_SOCKET, SO_RCVBUF, 262144);

        if (!@socket_connect($socket, $host, $port)) {
            echo "连接失败\n";
            return false;
        }

        $request = "GET {$path} HTTP/1.1\r\n"
            . "Host: {$host}\r\n"
            . "Accept: */*\r\n"
            . "Connection: keep-alive\r\n"
            . "User-Agent: FlvGateway/1.0\r\n"
            . "\r\n";
        socket_write($socket, $request);

        $response = '';
        $startTime = time();

        while (time() - $startTime < 5) {
            $chunk = @socket_read($socket, 4096);
            if ($chunk === false) { usleep(10000); continue; }
            if ($chunk === '') break;

            $response .= $chunk;
            $headerEnd = strpos($response, "\r\n\r\n");

            if ($headerEnd !== false) {
                $header = substr($response, 0, $headerEnd);
                $body = substr($response, $headerEnd + 4);

                echo "HTTP: " . strtok($header, "\r\n") . "\n";

                $this->chunkedEncoding = (stripos($header, "chunked") !== false);

                socket_set_nonblock($socket);

                if ($this->chunkedEncoding) {
                    $this->chunkBuffer = $body;
                    $this->buffer = $this->decodeChunked($this->chunkBuffer) ?? '';
                } else {
                    $this->buffer = $body;
                }
                return $socket;
            }
        }

        socket_close($socket);
        return false;
    }

    private function sendInitData($clientSocket, $streamPath)
    {
        $cache = $this->streamCache[$streamPath];
        $data = $cache['flvHeader']
            . $cache['previousTagSize0']
            . $cache['metaDataTag']
            . $cache['videoSequenceHeader']
            . $cache['audioSequenceHeader'];

        echo "发送初始化数据: " . strlen($data) . " 字节\n";

        $sent = 0;
        $dataLen = strlen($data);

        while ($sent < $dataLen) {
            $result = @socket_write($clientSocket, substr($data, $sent));
            if ($result === false) {
                $err = socket_last_error($clientSocket);
                if ($err !== SOCKET_EWOULDBLOCK && $err !== 0) {
                    return false;
                }
                usleep(1000);
                continue;
            }
            $sent += $result;
        }

        return true;
    }

    private function processPendingClients()
    {
        $streamPath = $this->currentStreamPath;

        if (!isset($this->streamCache[$streamPath]) || !$this->streamCache[$streamPath]['ready']) {
            return;
        }

        foreach ($this->pendingClients as $key => $clientInfo) {
            if ($this->sendInitData($clientInfo['socket'], $streamPath)) {
                $this->clients[(int)$clientInfo['socket']] = [
                    'socket' => $clientInfo['socket'],
                    'stream' => $streamPath,
                    'last_active' => time(),
                ];
                unset($this->pendingClients[$key]);
                echo "+ 客户端就绪，总数: " . count($this->clients) . "\n";
            }
        }
    }

    private function readFromUpstream()
    {
        if (!$this->upstream) return;

        $data = @socket_read($this->upstream, 65536);

        if ($data === false) {
            $error = socket_last_error($this->upstream);
            if ($error !== SOCKET_EWOULDBLOCK && $error !== 0) {
                echo "[调试] readFromUpstream: 上游错误 $error\n";
                $this->reconnectUpstream();
            }
            return;
        }

        if ($data === '') return;

        echo "[调试] readFromUpstream: 收到 " . strlen($data) . " 字节\n";

        if ($this->chunkedEncoding) {
            $this->chunkBuffer .= $data;
            $decoded = $this->decodeChunked($this->chunkBuffer);
            if ($decoded !== null) {
                $this->buffer .= $decoded;
                echo "[调试] readFromUpstream: 解码chunked后 " . strlen($decoded) . " 字节\n";
            }
        } else {
            $this->buffer .= $data;
        }

        $this->processFlvData();
    }

    private function decodeChunked(&$buf)
    {
        $decoded = '';

        while (true) {
            $pos = strpos($buf, "\r\n");
            if ($pos === false) break;

            $size = hexdec(trim(substr($buf, 0, $pos)));
            if ($size === 0) { $buf = ''; return $decoded; }

            $start = $pos + 2;
            $end = $start + $size + 2;

            if (strlen($buf) < $end) break;

            $decoded .= substr($buf, $start, $size);
            $buf = substr($buf, $end);
        }

        return $decoded;
    }

    private function processFlvData()
    {
        $streamPath = $this->currentStreamPath;
        $cache = &$this->streamCache[$streamPath];

        // FLV头
        if (!$cache['flvHeader'] && strlen($this->buffer) >= 13) {
            if (substr($this->buffer, 0, 3) !== 'FLV') {
                echo "非FLV数据: " . bin2hex(substr($this->buffer, 0, 3)) . "\n";
                return;
            }
            $cache['flvHeader'] = substr($this->buffer, 0, 9);
            $cache['previousTagSize0'] = substr($this->buffer, 9, 4);
            $this->buffer = substr($this->buffer, 13);
            echo "FLV头 ✓\n";
        }

        // ====== 收集序列数据 ======
        while (!$cache['ready'] && strlen($this->buffer) >= 11) {
            $tagType = ord($this->buffer[0]);
            $dataSize = (ord($this->buffer[1]) << 16) | (ord($this->buffer[2]) << 8) | ord($this->buffer[3]);
            $totalSize = 11 + $dataSize + 4;

            if (strlen($this->buffer) < $totalSize) break;

            $tag = substr($this->buffer, 0, $totalSize);
            $this->buffer = substr($this->buffer, $totalSize);

            // 保存到缓存（不广播）
            if ($tagType === 18 && !$cache['metaDataTag']) {
                $cache['metaDataTag'] = $tag;
                echo "MetaData ✓\n";
            } elseif ($tagType === 9 && !$cache['videoSequenceHeader']) {
                $v = substr($tag, 11, min($dataSize, 2));
                if (strlen($v) >= 2 && (ord($v[0]) >> 4) === 1 && ord($v[1]) === 0) {
                    $cache['videoSequenceHeader'] = $tag;
                    echo "视频序列头 ✓\n";
                }
            } elseif ($tagType === 8 && !$cache['audioSequenceHeader']) {
                $a = substr($tag, 11, min($dataSize, 2));
                if (strlen($a) >= 2 && (ord($a[0]) >> 4) === 10 && ord($a[1]) === 0) {
                    $cache['audioSequenceHeader'] = $tag;
                    echo "音频序列头 ✓\n";
                }
            }

            // ====== 关键：等待所有序列数据齐备 ======
            if ($cache['flvHeader'] && $cache['videoSequenceHeader'] && $cache['audioSequenceHeader']) {
                $cache['ready'] = true;
                echo ">>> 初始化完毕 <<<\n";
                $this->processPendingClients();

                // ====== 将缓存的序列数据也加入待转发buffer ======
                // 这样后续客户端也能拿到完整数据
            }
        }

        // ====== 初始化完成后才转发数据 ======
        if ($cache['ready'] && strlen($this->buffer) > 0) {
            echo "[调试] processFlvData: 调用broadcast，buffer长度=" . strlen($this->buffer) . "\n";
            $this->broadcast($this->buffer);
            $this->buffer = '';
        } else if ($cache['ready'] && strlen($this->buffer) === 0) {
            echo "[调试] processFlvData: buffer为空，跳过broadcast\n";
        }
    }

    private function broadcast($data)
    {
        if (empty($data)) {
            echo "[调试] broadcast: 无数据，跳过\n";
            return;
        }

        if (empty($this->clients)) {
            echo "[调试] broadcast: 无客户端，跳过\n";
            return;
        }

        echo "[调试] broadcast: 准备发送给 " . count($this->clients) . " 个客户端，数据长度: " . strlen($data) . "\n";

        foreach ($this->clients as $key => $clientInfo) {
            if ($clientInfo['stream'] !== $this->currentStreamPath) {
                echo "[调试] broadcast: 客户端 {$key} 流不匹配\n";
                continue;
            }

            $result = @socket_write($clientInfo['socket'], $data);
            if ($result === false) {
                $err = socket_last_error($clientInfo['socket']);
                if ($err !== SOCKET_EWOULDBLOCK && $err !== 0) {
                    echo "[调试] broadcast: 客户端 {$key} 发送失败: " . socket_strerror($err) . "\n";
                    @socket_close($clientInfo['socket']);
                    unset($this->clients[$key]);
                }
            }
        }
    }

    private function cleanupClients()
    {
        $now = time();

        foreach ($this->clients as $key => $clientInfo) {
            if ($now - $clientInfo['last_active'] > 60) {
                @socket_close($clientInfo['socket']);
                unset($this->clients[$key]);
                echo "- 超时断开，总数: " . count($this->clients) . "\n";
            }
        }

        foreach ($this->clients as $key => &$clientInfo) {
            $result = @socket_recv($clientInfo['socket'], $buf, 1, MSG_PEEK | MSG_DONTWAIT);
            if ($result === 0) {
                @socket_close($clientInfo['socket']);
                unset($this->clients[$key]);
                echo "- 断开，总数: " . count($this->clients) . "\n";
            } elseif ($result > 0) {
                $clientInfo['last_active'] = time();
            }
        }
        unset($clientInfo);
    }

    private function resetCache($streamPath)
    {
        $this->streamCache[$streamPath] = [
            'flvHeader' => '',
            'previousTagSize0' => '',
            'metaDataTag' => '',
            'videoSequenceHeader' => '',
            'audioSequenceHeader' => '',
            'ready' => false,
        ];
        $this->buffer = '';
        $this->chunkBuffer = '';
    }

    private function reconnectUpstream()
    {
        if ($this->upstream) @socket_close($this->upstream);
        echo "重连中...\n";
        sleep(2);
        $this->upstream = $this->connectUpstream($this->currentStreamPath);
    }
}

error_reporting(E_ALL);
set_time_limit(0);

$gateway = new FlvGateway();
$gateway->start();