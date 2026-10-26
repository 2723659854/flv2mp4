<?php

namespace Xiaosongshu\Flv2mp4\Manage;

use RuntimeException;

/**
 * @purpose MPEG-1 Layer III MP3 解码并封装为 WAV
 * 并行模式：MP3 解码子进程与 WAV 封装子进程通过管道同时进行。
 * @author yanglong
 * @time 2026年9月8日15:09:48
 */
final class Mp32Wav
{
    public function __construct(
        private readonly string $inputFile,
        private readonly string $outputFile
    ) {
        if (!is_file($inputFile)) {
            throw new RuntimeException("MP3 文件不存在: {$inputFile}");
        }
    }

    public function run(): array
    {
        $result = AudioParallelPipeline::run('mp3', $this->inputFile, 'wav', $this->outputFile);
        return [
            'output' => $this->outputFile,
            'sampleRate' => $result['decode']['sampleRate'],
            'channels' => $result['decode']['channels'],
            'bitsPerSample' => $result['encode']['bitsPerSample'],
            'bytes' => $result['encode']['bytes'],
            'duration' => $result['encode']['duration'],
            'source' => $this->inputFile,
            'pcmBytes' => $result['decode']['bytes'],
        ];
    }
}
