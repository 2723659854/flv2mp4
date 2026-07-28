<?php

namespace Xiaosongshu\Flv2mp4\Manage;

use Xiaosongshu\Flv2mp4\Flv\FlvPullerClient;
use Xiaosongshu\Flv2mp4\SabreAMF\RtmpPullerClient;

/**
 * @purpose 直播拉流客户端
 * @author yanglong
 * @time 2026年6月12日14:04:11
 */
class PullerManage
{
    protected $puller = null;

    /**
     * 拉流客户端初始化
     * @param string $pullUrl 拉流地址
     * @param string $outputFlv 输出flv保存路径，必须是本地绝对地址
     * @param int $duration 拉流时长（秒），0表示一直拉流到直播结束
     * @param bool $autoReconnect 是否掉线自动重连
     */
    public function __construct(string $pullUrl, string $outputFlv, int $duration = 0, bool $autoReconnect = true)
    {
        if (empty($pullUrl)) {
            throw new \RuntimeException('Pull URL cannot be empty');
        }

        if (empty($outputFlv)) {
            throw new \RuntimeException('Output FLV path cannot be empty');
        }

        if (file_exists($outputFlv)) {
            @unlink($outputFlv);
        }
        $urlParts = parse_url($pullUrl);
        $scheme = strtolower($urlParts['scheme'] ?? 'http');

        if ($scheme === 'rtmp') {
            $this->puller = new RtmpPullerClient($pullUrl, $outputFlv, $duration, $autoReconnect);
        } else {
            $this->puller = new FlvPullerClient($pullUrl, $outputFlv, $duration, $autoReconnect);
        }
    }

    /**
     * 启动拉流
     * @return void
     */
    public function start(): void
    {
        if ($this->puller) {
            $this->puller->start();
        }
    }
}