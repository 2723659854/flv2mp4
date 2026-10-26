<?php

namespace Xiaosongshu\Flv2mp4\Manage;

use InvalidArgumentException;
use RuntimeException;

/**
 * @purpose 将 MP3 解码并编码为 Ogg Opus。
 * 并行模式：MP3 解码子进程与 Opus 编码子进程（编码侧流式下混）通过管道同时进行。
 * @author yanglong
 * @time 2026年9月15日16:20:08
 */
final class Mp32Opus
{
    public function __construct(
        private readonly string $inputFile,
        private readonly string $outputFile,
        private readonly int $preSkip = 312
    ) {
        if (!is_file($inputFile)) {
            throw new RuntimeException("MP3 文件不存在: {$inputFile}");
        }
        if ($preSkip < 0 || $preSkip > 65535) {
            throw new InvalidArgumentException('Opus pre-skip 无效');
        }
    }

    public function run(): array
    {
        $result = AudioParallelPipeline::run(
            'mp3',
            $this->inputFile,
            'opus',
            $this->outputFile,
            ['preSkip' => $this->preSkip],
            function (array $meta): void {
                if ($meta['sampleRate'] !== 48000) {
                    throw new InvalidArgumentException('纯 PHP Opus 编码器只支持 48000 Hz MP3 输入');
                }
                if ($meta['channels'] !== 1 && $meta['channels'] !== 2) {
                    throw new InvalidArgumentException('纯 PHP Opus 编码器只支持单声道或双声道 MP3 输入');
                }
            }
        );
        return [
            'output' => $this->outputFile,
            'sampleRate' => $result['encode']['sampleRate'],
            'channels' => $result['encode']['channels'],
            'preSkip' => $result['encode']['preSkip'],
            'bytes' => $result['encode']['bytes'],
            'packets' => $result['encode']['packets'],
            'samples' => $result['encode']['samples'],
            'duration' => $result['encode']['duration'],
            'source' => $this->inputFile,
        ];
    }
}
