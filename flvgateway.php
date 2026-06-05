<?php

// flv_gateway_http.php
// HTTP-FLV流媒体网关 - 最终修复版

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

        // 解析请求路径
        preg_match('#GET\s+/([^\s]+)#', $request, $matches);
        $streamPath = $matches[1] ?? '';
        $streamPath = preg_replace('/\.flv$/', '', $streamPath);

        if (empty($streamPath)) {
            @socket_write($clientSocket, "HTTP/1.1 400 Bad Request\r\nConnection: close\r\n\r\n");
            socket_close($clientSocket);
            return;
        }

        echo "客户端请求流: /{$streamPath}\n";

        // 发送HTTP响应头
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
            echo "+ 客户端（缓存命中），总数: " . count($this->clients) . "\n";
            return;
        }

        // 切换流
        if ($this->currentStreamPath !== $streamPath) {
            echo "切换流: {$streamPath}\n";
            if ($this->upstream) @socket_close($this->upstream);

            $this->currentStreamPath = $streamPath;
            $this->resetCache($streamPath);
            $this->upstream = $this->connectUpstream($streamPath);
        }

        // 加入等待队列
        $this->pendingClients[] = [
            'socket' => $clientSocket,
            'stream' => $streamPath,
            'last_active' => time(),
        ];
        echo "+ 客户端等待数据，队列: " . count($this->pendingClients) . "\n";
    }

    private function connectUpstream($streamPath)
    {
        $url = "{$this->upstreamBaseUrl}/{$streamPath}.flv";
        $parsed = parse_url($url);
        $host = $parsed['host'];
        $port = $parsed['port'] ?? 80;
        $path = $parsed['path'];

        echo "连接上游: {$host}:{$port}{$path}\n";

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

        // 读取HTTP响应
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

                $firstLine = strtok($header, "\r\n");
                echo "HTTP: {$firstLine}\n";

                $this->chunkedEncoding = (stripos($header, "chunked") !== false);

                if (strpos($firstLine, '200') !== false) {
                    socket_set_nonblock($socket);
                    if ($this->chunkedEncoding) {
                        $this->chunkBuffer = $body;
                        $this->buffer = $this->decodeChunked($this->chunkBuffer) ?? '';
                    } else {
                        $this->buffer = $body;
                    }
                    return $socket;
                }
                break;
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

        // 尝试发送，忽略EWOULDBLOCK错误
        $sent = 0;
        $dataLen = strlen($data);

        while ($sent < $dataLen) {
            $result = @socket_write($clientSocket, substr($data, $sent));
            if ($result === false) {
                $err = socket_last_error($clientSocket);
                if ($err !== SOCKET_EWOULDBLOCK && $err !== 0) {
                    echo "发送初始化数据失败: " . socket_strerror($err) . "\n";
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
                $this->clients[(int)$clientInfo['socket']] = $clientInfo;
                unset($this->pendingClients[$key]);
                echo "+ 客户端已就绪，总数: " . count($this->clients) . "\n";
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
                echo "上游错误: " . socket_strerror($error) . "\n";
                $this->reconnectUpstream();
            }
            return;
        }

        if ($data === '') return;  // 非阻塞模式无数据

        if ($this->chunkedEncoding) {
            $this->chunkBuffer .= $data;
            $decoded = $this->decodeChunked($this->chunkBuffer);
            if ($decoded !== null) {
                $this->buffer .= $decoded;
            }
        } else {
            $this->buffer .= $data;
        }

        $this->processFlvData();
    }

    private function decodeChunked(&$chunkBuffer)
    {
        $decoded = '';
        $bufferLen = strlen($chunkBuffer);

        while (true) {
            $sizeEnd = strpos($chunkBuffer, "\r\n");
            if ($sizeEnd === false) break;

            $sizeLine = substr($chunkBuffer, 0, $sizeEnd);
            $chunkSize = hexdec(trim($sizeLine));

            if ($chunkSize === 0) {
                $chunkBuffer = '';
                return $decoded;
            }

            $chunkDataStart = $sizeEnd + 2;
            $chunkFullLen = $chunkDataStart + $chunkSize + 2;

            if ($bufferLen < $chunkFullLen) break;

            $decoded .= substr($chunkBuffer, $chunkDataStart, $chunkSize);
            $chunkBuffer = substr($chunkBuffer, $chunkFullLen);
            $bufferLen = strlen($chunkBuffer);
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
                echo "[错误] 非FLV数据: " . bin2hex(substr($this->buffer, 0, min(16, strlen($this->buffer)))) . "\n";
                return;
            }
            $cache['flvHeader'] = substr($this->buffer, 0, 9);
            $cache['previousTagSize0'] = substr($this->buffer, 9, 4);
            $this->buffer = substr($this->buffer, 13);
            echo "[{$streamPath}] FLV头\n";
        }

        // 序列数据
        while (!$cache['ready'] && strlen($this->buffer) >= 11) {
            $tagType = ord($this->buffer[0]);
            $dataSize = (ord($this->buffer[1]) << 16) | (ord($this->buffer[2]) << 8) | ord($this->buffer[3]);
            $totalTagSize = 11 + $dataSize + 4;

            if (strlen($this->buffer) < $totalTagSize) break;

            $tagData = substr($this->buffer, 0, $totalTagSize);
            $this->buffer = substr($this->buffer, $totalTagSize);

            if ($tagType === 18 && !$cache['metaDataTag']) {
                $cache['metaDataTag'] = $tagData;
                echo "[{$streamPath}] MetaData\n";
            } elseif ($tagType === 9 && !$cache['videoSequenceHeader']) {
                $vData = substr($tagData, 11, $dataSize);
                if (strlen($vData) >= 2 && (ord($vData[0]) >> 4) === 1 && ord($vData[1]) === 0) {
                    $cache['videoSequenceHeader'] = $tagData;
                    echo "[{$streamPath}] 视频序列头\n";
                }
            } elseif ($tagType === 8 && !$cache['audioSequenceHeader']) {
                $aData = substr($tagData, 11, $dataSize);
                if (strlen($aData) >= 2 && (ord($aData[0]) >> 4) === 10 && ord($aData[1]) === 0) {
                    $cache['audioSequenceHeader'] = $tagData;
                    echo "[{$streamPath}] 音频序列头\n";
                }
            }

            if ($cache['flvHeader'] && ($cache['videoSequenceHeader'] || $cache['audioSequenceHeader'])) {
                $cache['ready'] = true;
                echo "[{$streamPath}] 初始化完毕\n";
                $this->processPendingClients();
            }
        }

        // 转发数据
        if ($cache['ready'] && strlen($this->buffer) > 0) {
            $this->broadcast($this->buffer);
            $this->buffer = '';
        }
    }

    private function broadcast($data)
    {
        if (empty($data) || empty($this->clients)) return;

        foreach ($this->clients as $key => $clientInfo) {
            if ($clientInfo['stream'] !== $this->currentStreamPath) continue;

            $result = @socket_write($clientInfo['socket'], $data);
            if ($result === false) {
                $err = socket_last_error($clientInfo['socket']);
                if ($err !== SOCKET_EWOULDBLOCK && $err !== 0) {
                    echo "广播失败: " . socket_strerror($err) . "\n";
                    @socket_close($clientInfo['socket']);
                    unset($this->clients[$key]);
                }
            }
        }
    }

    private function cleanupClients()
    {
        // ====== 修复：不使用MSG_PEEK检测，改用timeout机制 ======
        $now = time();

        foreach ($this->clients as $key => $clientInfo) {
            // 超过60秒无活动的客户端才断开
            if ($now - $clientInfo['last_active'] > 60) {
                @socket_close($clientInfo['socket']);
                unset($this->clients[$key]);
                echo "- 超时断开，总数: " . count($this->clients) . "\n";
            }
        }

        // 更新最后活动时间
        foreach ($this->clients as $key => &$clientInfo) {
            $result = @socket_recv($clientInfo['socket'], $buf, 1, MSG_PEEK | MSG_DONTWAIT);
            if ($result === 0) {
                // 连接正常关闭
                @socket_close($clientInfo['socket']);
                unset($this->clients[$key]);
                echo "- 客户端断开，总数: " . count($this->clients) . "\n";
            } elseif ($result > 0) {
                // 有数据可读（可能是关闭信号），更新活动时间
                $clientInfo['last_active'] = time();
            }
            // result === false + EWOULDBLOCK = 正常，忽略
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
        echo "2秒后重连...\n";
        sleep(2);
        $this->upstream = $this->connectUpstream($this->currentStreamPath);
    }
}

error_reporting(E_ALL);
set_time_limit(0);

$gateway = new FlvGateway();
$gateway->start();