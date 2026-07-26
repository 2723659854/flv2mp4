<?php

namespace Xiaosongshu\Flv2mp4\Flv;

/**
 * @purpose 推流客户端
 * @author yanglong
 * @note 微型推流客户端，支持ws-flv/http-flv协议
 */
class FlvSinglePusher
{
    protected $playPath;

    protected $pushUrl;

    protected $socket;

    protected $isWebSocket = false;

    protected $wsKey = '';

    protected $wsPath = '/';

    protected $closed = false;

    protected $sendBuffer = '';

    protected $sendBufferSize = 0;

    protected $maxBufferSize = 10485760;

    protected $lastFlushTime = 0;

    public function __construct(string $playPath, string $pushUrl)
    {
        $this->playPath = $playPath;
        $this->pushUrl = $pushUrl;

        $urlParts = parse_url($pushUrl);
        $scheme = strtolower($urlParts['scheme'] ?? 'http');
        $this->isWebSocket = ($scheme === 'ws' || $scheme === 'wss');
        $this->wsPath = $urlParts['path'] ?? '/';
        if (!empty($urlParts['query'])) {
            $this->wsPath .= '?' . $urlParts['query'];
        }
    }

    public function connect()
    {
        $urlParts = parse_url($this->pushUrl);
        $host = $urlParts['host'];
        $port = $urlParts['port'] ?? ($this->isWebSocket ? 8501 : 8501);

        $this->socket = @fsockopen($host, $port, $errno, $errstr, 10);
        if (!$this->socket) {
            return false;
        }

        // 握手阶段设置合理的超时（3秒），确保能读取服务器响应
        stream_set_timeout($this->socket, 3);

        if ($this->isWebSocket) {
            $result = $this->webSocketHandshake($host, $port);
        } else {
            $result = $this->httpConnect($host);
        }

        // 保持阻塞模式，确保数据能完整发送
        if ($result) {
            stream_set_blocking($this->socket, true);
        }

        return $result;
    }

    protected function httpConnect($host)
    {
        $path = $this->wsPath;
        $request = "POST {$path} HTTP/1.1\r\n";
        $request .= "Host: {$host}\r\n";
        $request .= "Content-Type: video/x-flv\r\n";
        $request .= "Connection: keep-alive\r\n";
        $request .= "Transfer-Encoding: chunked\r\n";
        $request .= "\r\n";

        $result = fwrite($this->socket, $request);
        if ($result === false) {
            return false;
        }

        $response = '';
        $headersEnded = false;
        $timeout = time() + 1;
        while (time() < $timeout && !feof($this->socket)) {
            $line = fgets($this->socket);
            if ($line === false) break;
            $response .= $line;
            if (trim($line) === '') {
                $headersEnded = true;
                break;
            }
        }

        if (!$headersEnded) {
            return false;
        }

        $firstLine = strtok($response, "\r\n");

        if (strpos($firstLine, '200') === false) {
            return false;
        }

        return true;
    }

    protected function webSocketHandshake($host, $port)
    {
        $this->wsKey = base64_encode(random_bytes(16));

        $handshake = "GET {$this->wsPath} HTTP/1.1\r\n";
        $handshake .= "Host: {$host}:{$port}\r\n";
        $handshake .= "Connection: Upgrade\r\n";
        $handshake .= "Pragma: no-cache\r\n";
        $handshake .= "Cache-Control: no-cache\r\n";
        $handshake .= "User-Agent: FlvPusher/1.0\r\n";
        $handshake .= "Upgrade: websocket\r\n";
        $handshake .= "Origin: http://{$host}:{$port}\r\n";
        $handshake .= "Sec-WebSocket-Version: 13\r\n";
        $handshake .= "Accept-Encoding: gzip, deflate, br\r\n";
        $handshake .= "Accept-Language: zh-CN,zh;q=0.9\r\n";
        $handshake .= "Sec-WebSocket-Key: {$this->wsKey}\r\n";
        $handshake .= "Sec-WebSocket-Extensions: permessage-deflate; client_max_window_bits\r\n";
        $handshake .= "\r\n";

        $result = fwrite($this->socket, $handshake);
        if ($result === false) {
            return false;
        }

        $response = '';
        $timeout = time() + 3;
        while (time() < $timeout && !feof($this->socket)) {
            $line = fgets($this->socket);
            if ($line === false) break;
            $response .= $line;
            if (trim($line) === '') break;
        }

        if (!\preg_match("/Sec-WebSocket-Accept: *(.*?)\r\n/i", $response, $matches)) {
            return false;
        }

        $responseKey = trim($matches[1]);
        $expectedKey = \base64_encode(\sha1($this->wsKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));

        if ($responseKey !== $expectedKey) {
            return false;
        }

        return true;
    }

    public function write($data)
    {
        if (!$this->socket || $this->closed) {
            return;
        }

        try {
            if ($this->isWebSocket) {
                $frame = $this->buildWebSocketFrame($data);
            } else {
                $frame = $this->buildChunkedFrame($data);
            }

            $frameSize = strlen($frame);

            if ($this->sendBufferSize + $frameSize > $this->maxBufferSize) {
                $this->flush();
                if ($this->sendBufferSize + $frameSize > $this->maxBufferSize) {
                    $this->close();
                    return;
                }
            }

            $this->sendBuffer .= $frame;
            $this->sendBufferSize += $frameSize;

            if ($this->sendBufferSize > 4396 || (microtime(true) - $this->lastFlushTime) > 0.005) {
                $this->flush();
            }
        } catch (\Exception $e) {
            $this->close();
            throw $e;
        }
    }

    protected function buildChunkedFrame($data)
    {
        $chunkSize = dechex(strlen($data));
        return $chunkSize . "\r\n" . $data . "\r\n";
    }

    protected function buildWebSocketFrame($data)
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

        $maskedData = '';
        for ($i = 0; $i < $len; $i++) {
            $maskedData .= chr(ord($data[$i]) ^ ord($mask[$i % 4]));
        }
        $frame .= $maskedData;

        return $frame;
    }

    public function flush()
    {
        if (!$this->socket || $this->closed || $this->sendBufferSize === 0) {
            return;
        }

        try {
            $totalWritten = 0;
            $bufferLen = $this->sendBufferSize;
            
            while ($totalWritten < $bufferLen) {
                $written = fwrite($this->socket, substr($this->sendBuffer, $totalWritten));
                if ($written === false || $written === 0) {
                    break;
                }
                $totalWritten += $written;
            }
            
            if ($totalWritten > 0) {
                $this->sendBuffer = substr($this->sendBuffer, $totalWritten);
                $this->sendBufferSize -= $totalWritten;
            }
            $this->lastFlushTime = microtime(true);
        } catch (\Exception $e) {
            $this->close();
        }
    }

    protected function sendCloseFrame()
    {
        if (!$this->socket) return;
        $frame = chr(0x88);
        $frame .= chr(0x80);
        $mask = random_bytes(4);
        $frame .= $mask;
        @fwrite($this->socket, $frame);
    }

    public function close()
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        if ($this->socket) {
            try {
                if ($this->isWebSocket) {
                    $this->sendCloseFrame();
                } else {
                    if ($this->sendBufferSize > 0) {
                        $this->flush();
                    }
                    @fwrite($this->socket, "0\r\n\r\n");
                }
            } catch (\Exception $e) {}

            @fclose($this->socket);
            $this->socket = null;
        }

        $this->sendBuffer = '';
        $this->sendBufferSize = 0;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function __destruct()
    {
        $this->close();
    }
}