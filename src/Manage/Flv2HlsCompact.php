<?php

namespace Xiaosongshu\Flv2mp4\Manage;

use Xiaosongshu\Flv2mp4\Recode\PurePhpHlsGenerator;

/**
 * FLV直播流转码压缩为HLS切片（逐帧喂入入口）
 *
 * 与 Flv2Hls 同生命周期（构造传 streamId/config，逐帧 processFrame，结束 close），
 * 区别在于本入口不是直接转封装，而是纯PHP重编码压缩：
 *   源H264解码 -> 缩放降分辨率 -> H264 baseline重编码(降码率/降帧率参数) -> TS切片
 *
 * 约束：
 *  - 仅支持 H264(baseline) + AAC 的源；只支持降配（width/height/fps=0 表示保持源规格）
 *  - 逐帧喂入走串行转码管道（多进程快速管道以整文件为输入，无法逐帧驱动），
 *    串行路径不做抽帧，fps 仅传给编码器；所有帧都会被解码并重编码
 *  - 输出 index.m3u8 + segment_N.ts，兼容 hls.js / vlc / ffplay
 *
 * config 关键字段（其余透传给重编码器）：
 *   width/height   目标分辨率（偶数，0=保持）
 *   bitrate        目标视频码率
 *   fps            目标帧率（仅写入编码器，不抽帧）
 *   qp             量化参数
 *   audioBitrate   音频码率
 *   motionWorkers  运动估计子进程数
 *   watermark/watermark_file 水印
 *   outputDir      切片输出目录（默认 项目根/hls/{streamId}/）
 *   segmentDuration 切片时长秒（默认3秒）
 *
 * @author yanglong
 */
class Flv2HlsCompact
{
    private PurePhpHlsGenerator $generator;
    private string $streamDir;
    private bool $closed = false;

    public function __construct(string $streamId, array $config = [])
    {
        $streamId = trim($streamId, "/");
        $this->streamDir = $config['outputDir'] ?? dirname(__DIR__, 2) . "/hls/{$streamId}/";
        if (!is_dir($this->streamDir)) {
            mkdir($this->streamDir, 0777, true);
        }

        $segmentDuration = isset($config['segmentDuration']) ? (int)$config['segmentDuration'] : null;
        // 容器层键不透传给转码 profile
        unset($config['outputDir'], $config['segmentDuration'], $config['multi']);

        // 流式喂入仅支持串行管道（multi 管道以完整 FLV 文件为输入）
        $this->generator = new PurePhpHlsGenerator($config, rtrim($this->streamDir, '/'), false);
        if ($segmentDuration !== null && $segmentDuration > 0) {
            $this->generator->setSegmentDuration($segmentDuration);
        }
    }

    public function getStreamDir(): string
    {
        return $this->streamDir;
    }

    public function getIndex(): string
    {
        return $this->streamDir . 'index.m3u8';
    }

    /**
     * 喂入一个FLV数据包（音频 tagType=8 / 视频 tagType=9）
     * 兼容 FlvTag、FlvParse 产出的 tag，以及含 body/getData()、getTime()/getTimestamp() 的同类对象
     * @param object $frame
     */
    public function processFrame($frame): void
    {
        if ($this->closed || !is_object($frame)) return;

        $tag = $this->normalizeFrame($frame);
        if ($tag === null) return;

        $this->generator->processTag($tag);
    }

    /**
     * 归一化各种 tag 形态为转码器所需的 {tagType, body, getTime()} 对象
     */
    private function normalizeFrame(object $frame): ?object
    {
        if (!property_exists($frame, 'tagType')) return null;
        $tagType = (int)$frame->tagType;
        if ($tagType !== 8 && $tagType !== 9) return null;

        // 取 tag body：与 Flv2Hls 相同的四级兼容
        $body = null;
        if (property_exists($frame, 'body')) {
            $body = $frame->body;
        } elseif (method_exists($frame, 'getData')) {
            $body = $frame->getData();
        } elseif (method_exists($frame, '__toString')) {
            $body = (string)$frame;
        } elseif (method_exists($frame, 'dump')) {
            $body = $frame->dump();
        }
        if (!is_string($body) || $body === '') return null;

        // 取时间戳（毫秒）：整型 timestamp 属性优先，其次 getTime()/getTimestamp()
        $timestamp = 0;
        if (property_exists($frame, 'timestamp') && is_int($frame->timestamp)) {
            $timestamp = $frame->timestamp;
        } elseif (method_exists($frame, 'getTime')) {
            $timestamp = (int)$frame->getTime();
        } elseif (method_exists($frame, 'getTimestamp')) {
            $timestamp = (int)$frame->getTimestamp();
        }
        $timestamp = max(0, $timestamp);

        return new class($tagType, $body, $timestamp) {
            public int $tagType;
            public string $body;
            private int $timestamp;

            public function __construct(int $tagType, string $body, int $timestamp)
            {
                $this->tagType = $tagType;
                $this->body = $body;
                $this->timestamp = $timestamp;
            }

            public function getTime(): int
            {
                return $this->timestamp;
            }
        };
    }

    public function close(): void
    {
        if ($this->closed) return;
        $this->closed = true;
        $this->generator->finishStream();
    }

    public function __destruct()
    {
        $this->close();
    }
}
