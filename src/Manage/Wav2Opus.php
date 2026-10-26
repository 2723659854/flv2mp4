<?php

namespace Xiaosongshu\Flv2mp4\Manage;

use InvalidArgumentException;
use RuntimeException;

/**
 * @purpose 将PCM WAV封装为 Ogg Opus。
 * 并行模式：WAV 解码子进程与 Opus 编码子进程（编码侧流式下混）通过管道同时进行。
 * @author yanglong
 * @time 2026年9月15日10:40:36
 */
final class Wav2Opus
{
    public function __construct(
        private readonly string $inputFile,
        private readonly string $outputFile,
        private readonly int $preSkip = 312
    ) {
        if (!is_file($inputFile)) {
            throw new RuntimeException("WAV 文件不存在: {$inputFile}");
        }
        if ($preSkip < 0 || $preSkip > 65535) {
            throw new InvalidArgumentException('Opus pre-skip 无效');
        }
    }

    public function run(): array
    {
        $result = AudioParallelPipeline::run(
            'wav',
            $this->inputFile,
            'opus',
            $this->outputFile,
            ['preSkip' => $this->preSkip],
            function (array $meta): void {
                if ($meta['audioFormat'] !== 1 || $meta['bitsPerSample'] !== 16) {
                    throw new InvalidArgumentException('纯 PHP Opus 编码器只支持 16-bit 未压缩 PCM WAV');
                }
                if ($meta['sampleRate'] !== 48000) {
                    throw new InvalidArgumentException('纯 PHP Opus 编码器只支持 48000 Hz WAV');
                }
                if ($meta['channels'] !== 1 && $meta['channels'] !== 2) {
                    throw new InvalidArgumentException('纯 PHP Opus 编码器只支持单声道或双声道 WAV');
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
        ];
    }
}
