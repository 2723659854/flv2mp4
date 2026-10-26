<?php

namespace Xiaosongshu\Flv2mp4\Manage;

use InvalidArgumentException;
use RuntimeException;
use Xiaosongshu\Flv2mp4\Mp3\Config;

/**
 * @purpose 将 WAV 音频编码为 MPEG-1 Layer III MP3。
 * 并行模式：WAV 解码子进程与 MP3 编码子进程通过管道同时进行。
 * @author yanglong
 * @time 2026年9月8日16:50:07
 */
final class Wav2Mp3
{
    public function __construct(
        private readonly string $inputFile,
        private readonly string $outputFile,
        private readonly int $bitrate = 128000
    ) {
        if (!is_file($inputFile)) {
            throw new RuntimeException("WAV 文件不存在: {$inputFile}");
        }
        if (!in_array($bitrate, Config::BITRATES, true)) {
            throw new InvalidArgumentException('不支持的 MP3 码率');
        }
    }

    public function run(): array
    {
        $result = AudioParallelPipeline::run(
            'wav',
            $this->inputFile,
            'mp3',
            $this->outputFile,
            ['bitrate' => $this->bitrate],
            function (array $meta): void {
                if ($meta['audioFormat'] !== 1 || $meta['bitsPerSample'] !== 16) {
                    throw new InvalidArgumentException('MP3 Encoder 只支持 16-bit 未压缩 PCM WAV');
                }
                if (!in_array($meta['sampleRate'], Config::SAMPLE_RATES, true)) {
                    throw new InvalidArgumentException('MP3 Encoder 只支持 32000、44100 或 48000 Hz WAV');
                }
                if ($meta['channels'] !== 1 && $meta['channels'] !== 2) {
                    throw new InvalidArgumentException('MP3 Encoder 只支持单声道或双声道 WAV');
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
