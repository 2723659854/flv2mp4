<?php

namespace Xiaosongshu\Flv2mp4\Manage;

use InvalidArgumentException;
use RuntimeException;

/**
 * @purpose 将 Ogg Opus 音频转码为 MPEG-1 Layer III MP3。
 * 并行模式：Opus 解码子进程（应用 outputGainQ8）与 MP3 编码子进程通过管道同时进行。
 * @author yanglong
 * @time 2026年9月8日17:48:23
 */
final class Opus2Mp3
{
    public function __construct(
        private readonly string $inputFile,
        private readonly string $outputFile,
        private readonly int $bitrate = 128000
    ) {
        if (!is_file($inputFile)) {
            throw new RuntimeException("Opus 文件不存在: {$inputFile}");
        }
        if (!in_array($bitrate, \Xiaosongshu\Flv2mp4\Mp3\Config::BITRATES, true)) {
            throw new InvalidArgumentException('不支持的 MP3 码率');
        }
    }

    public function run(): array
    {
        $result = AudioParallelPipeline::run(
            'opus',
            $this->inputFile,
            'mp3',
            $this->outputFile,
            ['bitrate' => $this->bitrate],
            function (array $meta): void {
                if ($meta['channels'] !== 1 && $meta['channels'] !== 2) {
                    throw new InvalidArgumentException('当前 Opus2Mp3 只支持单声道或双声道 Ogg Opus');
                }
                if ($meta['mappingFamily'] !== 0) {
                    throw new InvalidArgumentException('当前 Opus2Mp3 只支持 mapping-family-0 Ogg Opus');
                }
            },
            null,
            ['gainQ8' => 'auto']
        );
        return [
            'output' => $this->outputFile,
            'sampleRate' => $result['decode']['sampleRate'],
            'channels' => $result['decode']['channels'],
            'bitrate' => $this->bitrate,
            'bytes' => $result['encode']['bytes'],
            'frames' => $result['encode']['frames'],
            'opusPackets' => $result['decode']['packets'],
            'duration' => $result['decode']['seconds'],
        ];
    }
}
