<?php

namespace Xiaosongshu\Flv2mp4\Manage;

use Xiaosongshu\Flv2mp4\SabreAMF\RtmpPullerClient;
use Xiaosongshu\Flv2mp4\Flv\FlvPullerClient;

/**
 * @purpose php版本的拉流客户端，保存流媒体为flv静态文件
 * @author yanglong
 * @note 支持http-flv,ws-flv,rtmp协议
 * @command php puller.php http://127.0.0.1:8501/live/stream.flv output.flv 0 --no-reconnect
 * @command php puller.php ws://127.0.0.1:8501/live/stream.flv output.flv 0 --no-reconnect
 * @command php puller.php rtmp://127.0.0.1:1935/live/stream output.flv 0 --no-reconnect
 */
class PullerManager
{
    protected $puller = null;

    public function __construct(string $pullUrl, string $outputFlv, int $duration = 0, bool $autoReconnect = true)
    {
        if (empty($pullUrl)) {
            throw new \RuntimeException('Pull URL cannot be empty');
        }

        if (empty($outputFlv)) {
            throw new \RuntimeException('Output FLV path cannot be empty');
        }

        $urlParts = parse_url($pullUrl);
        $scheme = strtolower($urlParts['scheme'] ?? 'http');

        if ($scheme === 'rtmp') {
            $this->puller = new RtmpPullerClient($pullUrl, $outputFlv, $duration, $autoReconnect);
        } else {
            $this->puller = new FlvPullerClient($pullUrl, $outputFlv, $duration, $autoReconnect);
        }
    }

    public function start(): void
    {
        if ($this->puller) {
            $this->puller->start();
        }
    }
}