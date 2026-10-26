<?php

namespace Xiaosongshu\Flv2mp4\Manage;

use InvalidArgumentException;
use RuntimeException;

/**
 * @purpose 将 MP3 解码并转码为 AAC-LC ADTS。
 * 并行模式：MP3 解码子进程与 AAC 编码子进程通过管道同时进行。
 * @author yanglong
 * @time 2026年9月8日18:08:30
 */
final class Mp32Aac
{
    public function __construct(
        private readonly string $inputFile,
        private readonly string $outputFile,
        private readonly int $bitrate = 128000
    ) {
        if (!is_file($inputFile)) {
            throw new RuntimeException("MP3 文件不存在: {$inputFile}");
        }
        if ($bitrate < 48000 || $bitrate > 192000) {
            throw new InvalidArgumentException('AAC 码率必须在 48000 至 192000 bit/s 之间');
        }
    }

    public function run(): array
    {
        $bitrate = $this->bitrate;
        $result = AudioParallelPipeline::run(
            'mp3',
            $this->inputFile,
            'aac',
            $this->outputFile,
            fn (array $meta): array => ['bitrate' => $meta['channels'] === 1 ? min($bitrate, 192000) : max($bitrate, 96000)],
            function (array $meta): void {
                if ($meta['sampleRate'] !== 48000) {
                    throw new InvalidArgumentException('AacLcEncoder 只支持 48000 Hz MP3 输入');
                }
                if ($meta['channels'] !== 1 && $meta['channels'] !== 2) {
                    throw new InvalidArgumentException('AacLcEncoder 只支持单声道或双声道 MP3 输入');
                }
            }
        );
        return [
            'output' => $this->outputFile,
            'sampleRate' => $result['decode']['sampleRate'],
            'channels' => $result['decode']['channels'],
            'bitrate' => $result['encode']['bitrate'],
            'bytes' => $result['encode']['bytes'],
            'frames' => $result['encode']['frames'],
            'duration' => $result['decode']['seconds'],
        ];
    }
}
