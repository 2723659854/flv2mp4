<?php

namespace Xiaosongshu\Flv2mp4\Manage;

use RuntimeException;

/**
 * @purpose 从 AAC、MP4 或 FLV 文件中提取 AAC-LC 音频并转码为 WAV。
 * 并行模式：解码子进程与 WAV 封装子进程通过管道同时进行。
 * @author yanglong
 * @time 2026年9月7日17:47:26
 */
final class Aac2Wav
{
    public function __construct(
        private readonly string $inputFile,
        private readonly string $outputFile
    ) {
        if (!is_file($inputFile)) {
            throw new RuntimeException("输入文件不存在: {$inputFile}");
        }
    }

    public function run(): array
    {
        $result = AudioParallelPipeline::run('aac', $this->inputFile, 'wav', $this->outputFile);
        return [
            'output' => $this->outputFile,
            'sampleRate' => $result['decode']['sampleRate'],
            'channels' => $result['decode']['channels'],
            'bitsPerSample' => $result['encode']['bitsPerSample'],
            'bytes' => $result['encode']['bytes'],
            'duration' => $result['encode']['duration'],
            'frames' => $result['decode']['frames'],
        ];
    }
}
