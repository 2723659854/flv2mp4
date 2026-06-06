<?php

class FlvGateway
{
    private $upstreamBaseUrl = 'http://127.0.0.1:8501';
    private $listenPort = 8082;
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

        echo "HTTP-FLV网关启动，端口: {$this->listenPort}\n";
        echo "上游: {$this->upstreamBaseUrl}\n\n";

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

        $request = '';
        $start = time();
        while (time() - $start < 3) {
            $chunk = @socket_read($clientSocket, 4096);
            if ($chunk === false || $chunk === '') break;
            $request .= $chunk;
            if (strpos($request, "\r\n\r\n") !== false) break;
        }

        preg_match('#GET\s+/([^\s]+)#', $request, $matches);
        $streamPath = preg_replace('/\.flv$/', '', $matches[1] ?? '');

        if (!$streamPath) {
            @socket_write($clientSocket, "HTTP/1.1 400 Bad Request\r\nConnection: close\r\n\r\n");
            socket_close($clientSocket);
            return;
        }

        echo "请求: /{$streamPath}\n";

        $header = "HTTP/1.1 200 OK\r\n"
            . "Content-Type: video/x-flv\r\n"
            . "Connection: keep-alive\r\n"
            . "Cache-Control: no-cache\r\n"
            . "Access-Control-Allow-Origin: *\r\n"
            . "Server: FlvGateway/1.0\r\n"
            . "\r\n";
        @socket_write($clientSocket, $header);

        if (isset($this->streamCache[$streamPath]) && $this->streamCache[$streamPath]['ready']) {
            $this->sendCachedInitData($clientSocket, $streamPath);
            $this->clients[(int)$clientSocket] = [
                'socket' => $clientSocket,
                'stream' => $streamPath,
            ];
            echo "+ 客户端就绪(缓存)，总数: " . count($this->clients) . "\n";
            return;
        }

        if ($this->currentStreamPath !== $streamPath) {
            if ($this->upstream) @socket_close($this->upstream);
            $this->currentStreamPath = $streamPath;
            $this->resetCache($streamPath);
            $this->upstream = $this->connectUpstream($streamPath);
            if (!$this->upstream) {
                @socket_write($clientSocket, "HTTP/1.1 503\r\n\r\n");
                socket_close($clientSocket);
                return;
            }
        }

        $this->pendingClients[] = ['socket' => $clientSocket, 'stream' => $streamPath];
        echo "+ 等待初始化，队列: " . count($this->pendingClients) . "\n";
    }

    private function connectUpstream($streamPath)
    {
        $url = "{$this->upstreamBaseUrl}/{$streamPath}.flv";
        $p = parse_url($url);
        $host = $p['host'];
        $port = $p['port'] ?? 80;
        $path = $p['path'] . (isset($p['query']) ? '?' . $p['query'] : '');

        echo "连接: {$host}:{$port}\n";

        $sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_set_option($sock, SOL_SOCKET, SO_RCVBUF, 262144);

        if (!@socket_connect($sock, $host, $port)) {
            echo "连接失败\n";
            return false;
        }

        $req = "GET {$path} HTTP/1.1\r\nHost: {$host}\r\nAccept: */*\r\nConnection: keep-alive\r\n\r\n";
        socket_write($sock, $req);

        $resp = '';
        $start = time();
        while (time() - $start < 5) {
            $chunk = @socket_read($sock, 4096);
            if ($chunk === false) { usleep(10000); continue; }
            if ($chunk === '') break;
            $resp .= $chunk;
            $end = strpos($resp, "\r\n\r\n");
            if ($end !== false) {
                $header = substr($resp, 0, $end);
                $body = substr($resp, $end + 4);
                $this->chunkedEncoding = (stripos($header, "chunked") !== false);
                socket_set_nonblock($sock);
                if ($this->chunkedEncoding) {
                    $this->chunkBuffer = $body;
                    $this->buffer = $this->decodeChunked($this->chunkBuffer) ?? '';
                } else {
                    $this->buffer = $body;
                }
                echo "HTTP 200 OK\n";
                return $sock;
            }
        }
        socket_close($sock);
        return false;
    }

    private function sendCachedInitData($clientSocket, $streamPath)
    {
        $cache = $this->streamCache[$streamPath];

        // 严格按照 startPlay() 的顺序发送
        $data = $cache['flvHeader']           // 1. FLV文件头(13字节)
            . $cache['metaDataTag']          // 2. onMetaData
            . $cache['videoSequenceHeader']  // 3. AVC Sequence (SPS/PPS)
            . $cache['audioSequenceHeader']  // 4. AAC Sequence
            . $cache['gopData'];             // 5. GOP关键帧数据

        $totalLen = strlen($data);
        echo "发送缓存: " . $totalLen . " 字节 "
            . "(FLV头=9, Meta=" . strlen($cache['metaDataTag'])
            . ", AVC=" . strlen($cache['videoSequenceHeader'])
            . ", AAC=" . strlen($cache['audioSequenceHeader'])
            . ", GOP=" . strlen($cache['gopData']) . ")\n";

        $sent = 0;
        while ($sent < $totalLen) {
            $r = @socket_write($clientSocket, substr($data, $sent));
            if ($r === false) {
                $err = socket_last_error($clientSocket);
                if ($err !== SOCKET_EWOULDBLOCK && $err !== 0) return false;
                usleep(1000);
                continue;
            }
            $sent += $r;
        }

        echo "发送完成\n";
        return true;
    }

    private function processPendingClients()
    {
        $path = $this->currentStreamPath;
        if (!isset($this->streamCache[$path]) || !$this->streamCache[$path]['ready']) return;

        foreach ($this->pendingClients as $k => $c) {
            if ($this->sendCachedInitData($c['socket'], $path)) {
                $this->clients[(int)$c['socket']] = $c;
                unset($this->pendingClients[$k]);
                echo "+ 客户端就绪，总数: " . count($this->clients) . "\n";
            }
        }
    }

    private function readFromUpstream()
    {
        if (!$this->upstream) return;

        $data = @socket_read($this->upstream, 65536);
        if ($data === false) {
            $err = socket_last_error($this->upstream);
            if ($err !== SOCKET_EWOULDBLOCK && $err !== 0) $this->reconnectUpstream();
            return;
        }
        if ($data === '') return;

        if ($this->chunkedEncoding) {
            $this->chunkBuffer .= $data;
            $decoded = $this->decodeChunked($this->chunkBuffer);
            if ($decoded !== null) $this->buffer .= $decoded;
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
        $path = $this->currentStreamPath;
        $cache = &$this->streamCache[$path];

        // 1. FLV头 (13字节)
        if (!$cache['flvHeader'] && strlen($this->buffer) >= 13) {
            if (substr($this->buffer, 0, 3) !== 'FLV') {
                echo "错误: 非FLV数据\n";
                return;
            }
            $cache['flvHeader'] = substr($this->buffer, 0, 13);  // 9字节头 + 4字节PreviousTagSize
            $this->buffer = substr($this->buffer, 13);
            echo "FLV头 ✓\n";
        }

        // 2. 收集MetaData + 序列头 + GOP
        while (!$cache['ready'] && strlen($this->buffer) >= 11) {
            $tagType = ord($this->buffer[0]);
            $dataSize = (ord($this->buffer[1]) << 16) | (ord($this->buffer[2]) << 8) | ord($this->buffer[3]);
            $total = 11 + $dataSize + 4;
            if (strlen($this->buffer) < $total) break;

            $tag = substr($this->buffer, 0, $total);
            $this->buffer = substr($this->buffer, $total);

            // 检查tag数据部分
            $tagData = substr($tag, 11, $dataSize);

            // --- MetaData (type=18) ---
            if ($tagType === 18 && !$cache['metaDataTag']) {
                $cache['metaDataTag'] = $tag;
                echo "MetaData ✓ (" . $dataSize . "字节)\n";
                continue;
            }

            // --- 视频序列头 (AVC Sequence Header) ---
            if ($tagType === 9 && !$cache['videoSequenceHeader']) {
                if (strlen($tagData) >= 2) {
                    $frameType = (ord($tagData[0]) >> 4) & 0x0F;
                    $avcPacketType = ord($tagData[1]);
                    // frameType=1(关键帧) + avcPacketType=0(序列头)
                    if ($frameType === 1 && $avcPacketType === 0) {
                        $cache['videoSequenceHeader'] = $tag;
                        echo "视频序列头 ✓ (" . $dataSize . "字节)\n";
                        continue;
                    }
                }
            }

            // --- 音频序列头 (AAC Sequence Header) ---
            if ($tagType === 8 && !$cache['audioSequenceHeader']) {
                if (strlen($tagData) >= 2) {
                    $soundFormat = (ord($tagData[0]) >> 4) & 0x0F;
                    $aacPacketType = ord($tagData[1]);
                    // soundFormat=10(AAC) + aacPacketType=0(序列头)
                    if ($soundFormat === 10 && $aacPacketType === 0) {
                        $cache['audioSequenceHeader'] = $tag;
                        echo "音频序列头 ✓ (" . $dataSize . "字节)\n";
                        continue;
                    }
                }
            }

            // --- 序列头收集完毕，开始收集GOP ---
            if ($cache['flvHeader'] && $cache['videoSequenceHeader'] && $cache['audioSequenceHeader']) {
                // 把序列头之后的所有tag都加入GOP缓存
                $cache['gopData'] .= $tag;

                // 如果是视频关键帧，标记ready
                if ($tagType === 9 && strlen($tagData) >= 2) {
                    $frameType = (ord($tagData[0]) >> 4) & 0x0F;
                    $avcPacketType = ord($tagData[1]);
                    if ($frameType === 1 && $avcPacketType === 1) {  // NALU数据
                        $cache['ready'] = true;
                        echo ">>> GOP收集完毕 (" . strlen($cache['gopData']) . " 字节) <<<\n";
                        $this->processPendingClients();
                    }
                }
            }
        }

        // 3. 转发实时数据
        if ($cache['ready'] && strlen($this->buffer) > 0) {
            $this->broadcast($this->buffer);
            $this->buffer = '';
        }
    }

    private function broadcast($data)
    {
        if (empty($data) || empty($this->clients)) return;

        foreach ($this->clients as $k => $c) {
            if ($c['stream'] !== $this->currentStreamPath) continue;
            $r = @socket_write($c['socket'], $data);
            if ($r === false) {
                $err = socket_last_error($c['socket']);
                if ($err !== SOCKET_EWOULDBLOCK && $err !== 0) {
                    @socket_close($c['socket']);
                    unset($this->clients[$k]);
                }
            }
        }
    }

    private function cleanupClients()
    {
        foreach ($this->clients as $k => &$c) {
            $r = @socket_recv($c['socket'], $buf, 0, MSG_PEEK | MSG_DONTWAIT);
            if ($r === 0) {
                @socket_close($c['socket']);
                unset($this->clients[$k]);
                echo "- 断开，总数: " . count($this->clients) . "\n";
            }
        }
        unset($c);
    }

    private function resetCache($path)
    {
        $this->streamCache[$path] = [
            'flvHeader' => '',
            'metaDataTag' => '',
            'videoSequenceHeader' => '',
            'audioSequenceHeader' => '',
            'gopData' => '',
            'ready' => false,
        ];
        $this->buffer = '';
        $this->chunkBuffer = '';
    }

    private function reconnectUpstream()
    {
        if ($this->upstream) @socket_close($this->upstream);
        sleep(2);
        $this->upstream = $this->connectUpstream($this->currentStreamPath);
    }
}

error_reporting(E_ALL);
set_time_limit(0);
$gateway = new FlvGateway();
$gateway->start();