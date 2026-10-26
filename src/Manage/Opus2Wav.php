<?php

namespace Xiaosongshu\Flv2mp4\Manage;

use InvalidArgumentException;
use RuntimeException;

/**
 * @purpose 将 Ogg Opus 音频解码并封装为 WAV。
 * 并行模式：Opus 解码子进程与 WAV 封装子进程通过管道同时进行。
 * @author yanglong
 * @time 2026年9月8日11:11:07
 */
final class Opus2Wav
{
    public function __construct(
        private readonly string $inputFile,
        private readonly string $outputFile
    ) {
        if (!is_file($inputFile)) {
            throw new RuntimeException("Opus 文件不存在: {$inputFile}");
        }
    }

    public function run(): array
    {
        $result = AudioParallelPipeline::run('opus', $this->inputFile, 'wav', $this->outputFile, [], function (array $meta): void {
            if ($meta['channels'] !== 1 && $meta['channels'] !== 2) {
                throw new InvalidArgumentException('当前 Opus2Wav 只支持单声道或双声道 Ogg Opus');
            }
            if ($meta['mappingFamily'] !== 0) {
                throw new InvalidArgumentException('当前 Opus2Wav 只支持 mapping-family-0 Ogg Opus');
            }
        });
        return [
            'output' => $this->outputFile,
            'sampleRate' => $result['decode']['sampleRate'],
            'channels' => $result['decode']['channels'],
            'bitsPerSample' => $result['encode']['bitsPerSample'],
            'bytes' => $result['encode']['bytes'],
            'duration' => $result['encode']['duration'],
        ];
    }
}
