<?php

class FlvGateway
{
    private $upstreamBaseUrl = 'http://192.168.224.1:8501';
    private $listenPort = 8080;
    
    private $serverSocket = null;
    private $currentStream = '';
    private $upstreamSocket = null;
    private $upstreamReady = false;
    
    private $streamCache = [];
    private $buffer = '';
    private $chunkBuffer = '';
    private $chunkedEncoding = false;
    
    private $clients = [];
    private $pendingClients = [];
    
    public function __construct()
    {
        $this->resetCache();
    }
    
    private function resetCache()
    {
        $this->streamCache = [
            'flvHeader' => '',
            'previousTagSize0' => '',
            'metaDataTag' => '',
            'videoSequenceHeader' => '',
            'audioSequenceHeader' => '',
            'ready' => false,
        ];
        $this->buffer = '';
        $this->chunkBuffer = '';
        $this->chunkedEncoding = false;
        $this->upstreamReady = false;
    }
    
    public function start()
    {
        $this->serverSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if (!$this->serverSocket) {
            die("socket_create 失败: " . socket_strerror(socket_last_error()) . "\n");
        }
        
        socket_set_option($this->serverSocket, SOL_SOCKET, SO_REUSEADDR, 1);
        
        if (!socket_bind($this->serverSocket, '0.0.0.0', $this->listenPort)) {
            die("socket_bind 失败: " . socket_strerror(socket_last_error($this->serverSocket)) . "\n");
        }
        
        if (!socket_listen($this->serverSocket, 50)) {
            die("socket_listen 失败: " . socket_strerror(socket_last_error($this->serverSocket)) . "\n");
        }
        
        socket_set_nonblock($this->serverSocket);
        
        echo "HTTP-FLV网关启动，监听端口: {$this->listenPort}\n";
        echo "上游服务器: {$this->upstreamBaseUrl}\n\n";
        
        $this->eventLoop();
    }
    
    private function eventLoop()
    {
        while (true) {
            $readSockets = [$this->serverSocket];
            
            if ($this->upstreamSocket) {
                $readSockets[] = $this->upstreamSocket;
            }
            
            foreach ($this->clients as $client) {
                $readSockets[] = $client['socket'];
            }
            
            foreach ($this->pendingClients as $client) {
                $readSockets[] = $client['socket'];
            }
            
            $writeSockets = [];
            $exceptSockets = [];
            
            $timeout = ['sec' => 0, 'usec' => 10000];
            $result = socket_select($readSockets, $writeSockets, $exceptSockets, $timeout['sec'], $timeout['usec']);
            
            if ($result === false) {
                echo "socket_select 错误: " . socket_strerror(socket_last_error()) . "\n";
                continue;
            }
            
            if ($result === 0) {
                continue;
            }
            
            foreach ($readSockets as $socket) {
                if ($socket === $this->serverSocket) {
                    $this->acceptClient();
                } elseif ($socket === $this->upstreamSocket) {
                    $this->readFromUpstream();
                } else {
                    $this->checkClientDisconnect($socket);
                }
            }
            
            $this->sendPendingData();
        }
    }
    
    private function acceptClient()
    {
        $clientSocket = @socket_accept($this->serverSocket);
        if (!$clientSocket) {
            return;
        }
        
        socket_set_nonblock($clientSocket);
        socket_set_option($clientSocket, SOL_SOCKET, TCP_NODELAY, 1);
        
        $request = '';
        $maxAttempts = 30;
        $attempt = 0;
        
        while ($attempt++ < $maxAttempts) {
            $chunk = @socket_read($clientSocket, 4096);
            if ($chunk === false) {
                usleep(1000);
                continue;
            }
            if ($chunk === '') {
                break;
            }
            $request .= $chunk;
            if (strpos($request, "\r\n\r\n") !== false) {
                break;
            }
        }
        
        if (empty($request)) {
            @socket_close($clientSocket);
            return;
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
        
        $httpHeader = "HTTP/1.1 200 OK\r\n"
            . "Content-Type: video/x-flv\r\n"
            . "Connection: keep-alive\r\n"
            . "Cache-Control: no-cache\r\n"
            . "Access-Control-Allow-Origin: *\r\n"
            . "\r\n";
        @socket_write($clientSocket, $httpHeader);
        
        if ($this->currentStream !== $streamPath) {
            $this->switchStream($streamPath);
        }
        
        if ($this->streamCache['ready']) {
            $this->sendInitData($clientSocket);
            $this->clients[(int)$clientSocket] = [
                'socket' => $clientSocket,
                'stream' => $streamPath,
                'sendBuffer' => '',
            ];
            echo "+ 客户端就绪（缓存），总数: " . count($this->clients) . "\n";
        } else {
            $this->pendingClients[(int)$clientSocket] = [
                'socket' => $clientSocket,
                'stream' => $streamPath,
            ];
            echo "+ 等待初始化，队列: " . count($this->pendingClients) . "\n";
        }
    }
    
    private function switchStream($streamPath)
    {
        echo "切换流: {$streamPath}\n";
        
        if ($this->upstreamSocket) {
            @socket_close($this->upstreamSocket);
            $this->upstreamSocket = null;
        }
        
        $this->currentStream = $streamPath;
        $this->resetCache();
        
        $this->upstreamSocket = $this->connectUpstream($streamPath);
        if (!$this->upstreamSocket) {
            echo "上游连接失败\n";
            foreach ($this->pendingClients as $key => $client) {
                @socket_write($client['socket'], "HTTP/1.1 503 Service Unavailable\r\n\r\n");
                @socket_close($client['socket']);
                unset($this->pendingClients[$key]);
            }
            $this->currentStream = '';
        }
    }
    
    private function connectUpstream($streamPath)
    {
        $url = "{$this->upstreamBaseUrl}/{$streamPath}.flv";
        $parsed = parse_url($url);
        $host = $parsed['host'];
        $port = $parsed['port'] ?? 80;
        $path = $parsed['path'] . (isset($parsed['query']) ? '?' . $parsed['query'] : '');
        
        echo "连接上游: {$host}:{$port}{$path}\n";
        
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if (!$socket) {
            return false;
        }
        
        socket_set_option($socket, SOL_SOCKET, SO_RCVBUF, 262144);
        
        if (!@socket_connect($socket, $host, $port)) {
            socket_close($socket);
            return false;
        }
        
        $request = "GET {$path} HTTP/1.1\r\n"
            . "Host: {$host}\r\n"
            . "Accept: */*\r\n"
            . "Connection: keep-alive\r\n"
            . "User-Agent: FlvGateway/1.0\r\n"
            . "\r\n";
        
        if (!socket_write($socket, $request)) {
            socket_close($socket);
            return false;
        }
        
        $response = '';
        $maxAttempts = 50;
        $attempt = 0;
        
        while ($attempt++ < $maxAttempts) {
            $chunk = @socket_read($socket, 4096);
            if ($chunk === false) {
                usleep(10000);
                continue;
            }
            if ($chunk === '') {
                socket_close($socket);
                return false;
            }
            
            $response .= $chunk;
            $headerEnd = strpos($response, "\r\n\r\n");
            
            if ($headerEnd !== false) {
                $header = substr($response, 0, $headerEnd);
                $body = substr($response, $headerEnd + 4);
                
                echo "HTTP: " . strtok($header, "\r\n") . "\n";
                
                if (stripos($header, "HTTP/1.1 200") === false) {
                    echo "上游返回错误\n";
                    socket_close($socket);
                    return false;
                }
                
                $this->chunkedEncoding = (stripos($header, "Transfer-Encoding: chunked") !== false);
                socket_set_nonblock($socket);
                
                if ($this->chunkedEncoding) {
                    $this->chunkBuffer = $body;
                } else {
                    $this->buffer = $body;
                }
                
                $this->upstreamReady = true;
                return $socket;
            }
        }
        
        socket_close($socket);
        return false;
    }
    
    private function readFromUpstream()
    {
        if (!$this->upstreamReady) {
            return;
        }
        
        $data = @socket_read($this->upstreamSocket, 65536);
        
        if ($data === false) {
            $error = socket_last_error($this->upstreamSocket);
            if ($error !== SOCKET_EWOULDBLOCK && $error !== 0) {
                echo "上游错误\n";
                $this->handleUpstreamDisconnect();
            }
            return;
        }
        
        if ($data === '') {
            echo "上游断开\n";
            $this->handleUpstreamDisconnect();
            return;
        }
        
        if ($this->chunkedEncoding) {
            $this->chunkBuffer .= $data;
            $decoded = $this->decodeChunked();
            if ($decoded !== null) {
                $this->buffer .= $decoded;
            }
        } else {
            $this->buffer .= $data;
        }
        
        $this->processFlvData();
    }
    
    private function decodeChunked()
    {
        $decoded = '';
        
        while (true) {
            $pos = strpos($this->chunkBuffer, "\r\n");
            if ($pos === false) {
                return $decoded === '' ? null : $decoded;
            }
            
            $sizeLine = trim(substr($this->chunkBuffer, 0, $pos));
            $size = hexdec($sizeLine);
            
            if ($size === 0) {
                $this->chunkBuffer = '';
                return $decoded;
            }
            
            $start = $pos + 2;
            $end = $start + $size + 2;
            
            if (strlen($this->chunkBuffer) < $end) {
                return $decoded === '' ? null : $decoded;
            }
            
            $decoded .= substr($this->chunkBuffer, $start, $size);
            $this->chunkBuffer = substr($this->chunkBuffer, $end);
        }
    }
    
    private function processFlvData()
    {
        $cache = &$this->streamCache;
        $pendingData = '';
        
        if (!$cache['flvHeader'] && strlen($this->buffer) >= 13) {
            if (substr($this->buffer, 0, 3) !== 'FLV') {
                echo "非FLV数据\n";
                return;
            }
            $cache['flvHeader'] = substr($this->buffer, 0, 9);
            $cache['previousTagSize0'] = substr($this->buffer, 9, 4);
            $this->buffer = substr($this->buffer, 13);
            echo "FLV头 ✓\n";
        }
        
        while (!$cache['ready'] && strlen($this->buffer) >= 11) {
            $tagType = ord($this->buffer[0]);
            $dataSize = (ord($this->buffer[1]) << 16) | (ord($this->buffer[2]) << 8) | ord($this->buffer[3]);
            $totalSize = 11 + $dataSize + 4;
            
            if (strlen($this->buffer) < $totalSize) {
                break;
            }
            
            $tag = substr($this->buffer, 0, $totalSize);
            $this->buffer = substr($this->buffer, $totalSize);
            
            if ($tagType === 18 && !$cache['metaDataTag']) {
                $cache['metaDataTag'] = $tag;
                echo "MetaData ✓\n";
            } elseif ($tagType === 9 && !$cache['videoSequenceHeader']) {
                $v = substr($tag, 11, min($dataSize, 2));
                if (strlen($v) >= 2 && (ord($v[0]) >> 4) === 1 && ord($v[1]) === 0) {
                    $cache['videoSequenceHeader'] = $tag;
                    echo "视频序列头 ✓\n";
                } else {
                    $pendingData .= $tag;
                }
            } elseif ($tagType === 8 && !$cache['audioSequenceHeader']) {
                $a = substr($tag, 11, min($dataSize, 2));
                if (strlen($a) >= 2 && (ord($a[0]) >> 4) === 10 && ord($a[1]) === 0) {
                    $cache['audioSequenceHeader'] = $tag;
                    echo "音频序列头 ✓\n";
                } else {
                    $pendingData .= $tag;
                }
            } else {
                $pendingData .= $tag;
            }
            
            if ($cache['flvHeader'] && $cache['videoSequenceHeader'] && $cache['audioSequenceHeader']) {
                $cache['ready'] = true;
                echo ">>> 初始化完毕 <<<\n";
                $this->processPendingClients();
                
                if (strlen($pendingData) > 0) {
                    $this->broadcast($pendingData);
                }
                $pendingData = '';
            }
        }
        
        if ($cache['ready'] && strlen($this->buffer) > 0) {
            $broadcastData = $this->buffer;
            $this->buffer = '';
            $this->broadcast($broadcastData);
        }
        
        if (!$cache['ready'] && strlen($pendingData) > 0) {
            $this->buffer = $pendingData . $this->buffer;
        }
    }
    
    private function processPendingClients()
    {
        foreach ($this->pendingClients as $key => $clientInfo) {
            if ($this->sendInitData($clientInfo['socket'])) {
                $this->clients[$key] = [
                    'socket' => $clientInfo['socket'],
                    'stream' => $clientInfo['stream'],
                    'sendBuffer' => '',
                ];
                unset($this->pendingClients[$key]);
                echo "+ 客户端就绪，总数: " . count($this->clients) . "\n";
            }
        }
    }
    
    private function sendInitData($clientSocket)
    {
        $cache = $this->streamCache;
        $data = $cache['flvHeader']
            . $cache['previousTagSize0']
            . $cache['metaDataTag']
            . $cache['videoSequenceHeader']
            . $cache['audioSequenceHeader'];
        
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
    
    private function broadcast($data)
    {
        if (empty($data) || empty($this->clients)) {
            return;
        }
        
        foreach ($this->clients as $key => &$client) {
            if ($client['stream'] !== $this->currentStream) {
                continue;
            }
            $client['sendBuffer'] .= $data;
        }
    }
    
    private function sendPendingData()
    {
        foreach ($this->clients as $key => &$client) {
            if (empty($client['sendBuffer'])) {
                continue;
            }
            
            $result = @socket_write($client['socket'], $client['sendBuffer']);
            if ($result === false) {
                $err = socket_last_error($client['socket']);
                if ($err !== SOCKET_EWOULDBLOCK && $err !== 0) {
                    @socket_close($client['socket']);
                    unset($this->clients[$key]);
                    echo "- 客户端断开，总数: " . count($this->clients) . "\n";
                }
            } else {
                $client['sendBuffer'] = substr($client['sendBuffer'], $result);
            }
        }
    }
    
    private function checkClientDisconnect($socket)
    {
        $result = @socket_recv($socket, $buf, 1, MSG_PEEK | MSG_DONTWAIT);
        if ($result === 0) {
            $socketId = (int)$socket;
            
            if (isset($this->clients[$socketId])) {
                @socket_close($socket);
                unset($this->clients[$socketId]);
                echo "- 客户端断开，总数: " . count($this->clients) . "\n";
            } elseif (isset($this->pendingClients[$socketId])) {
                @socket_close($socket);
                unset($this->pendingClients[$socketId]);
                echo "- 等待客户端断开\n";
            }
        }
    }
    
    private function handleUpstreamDisconnect()
    {
        if ($this->upstreamSocket) {
            @socket_close($this->upstreamSocket);
            $this->upstreamSocket = null;
        }
        
        echo "关闭所有下游客户端\n";
        
        foreach ($this->clients as $client) {
            @socket_close($client['socket']);
        }
        $this->clients = [];
        
        foreach ($this->pendingClients as $client) {
            @socket_close($client['socket']);
        }
        $this->pendingClients = [];
        
        $this->currentStream = '';
        $this->resetCache();
    }
}

error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(0);
ignore_user_abort(true);

$gateway = new FlvGateway();
$gateway->start();
?>