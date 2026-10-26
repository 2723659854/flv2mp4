<?php

namespace Xiaosongshu\Flv2mp4\Manage;

use InvalidArgumentException;
use RuntimeException;

/**
 * @purpose 将 WAV 音频转换为 AAC-LC ADTS 音频。
 * 并行模式：WAV 解码子进程与 AAC 编码子进程通过管道同时进行。
 * @author yanglong
 * @time 2026年9月8日10:31:10
 */
final class Wav2Aac
{
    public function __construct(
        private readonly string $inputFile,
        private readonly string $outputFile,
        private readonly int $bitrate = 128000
    ) {
        if (!is_file($inputFile)) {
            throw new RuntimeException("WAV 文件不存在: {$inputFile}");
        }
        if ($bitrate < 96000 || $bitrate > 192000) {
            throw new InvalidArgumentException('AAC-LC 双声道码率必须在 96000 至 192000 bit/s 之间');
        }
    }

    public function run(): array
    {
        $bitrate = $this->bitrate;
        $result = AudioParallelPipeline::run(
            'wav',
            $this->inputFile,
            'aac',
            $this->outputFile,
            fn (array $meta): array => ['bitrate' => $meta['channels'] === 1 ? intdiv($bitrate, 2) : $bitrate],
            function (array $meta): void {
                if ($meta['audioFormat'] !== 1 || $meta['bitsPerSample'] !== 16) {
                    throw new InvalidArgumentException('AacLcEncoder 只支持 16-bit 未压缩 PCM WAV');
                }
                if ($meta['sampleRate'] !== 48000) {
                    throw new InvalidArgumentException('AacLcEncoder 只支持 48000 Hz WAV');
                }
                if ($meta['channels'] !== 1 && $meta['channels'] !== 2) {
                    throw new InvalidArgumentException('AacLcEncoder 只支持单声道或双声道 WAV');
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
