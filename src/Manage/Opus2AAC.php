<?php

namespace Xiaosongshu\Flv2mp4\Manage;

use InvalidArgumentException;
use RuntimeException;

/**
 * @purpose 将 Ogg Opus 音频转码为 AAC-LC ADTS。
 * 并行模式：Opus 解码子进程（应用 outputGainQ8）与 AAC 编码子进程通过管道同时进行。
 * 解码输出以 f64 浮点传输，避免 16-bit 量化改变既有转码结果。
 * @author yanglong
 * @time 2026年9月8日17:38:38
 */
final class Opus2AAC
{
    public function __construct(
        private readonly string $inputFile,
        private readonly string $outputFile,
        private readonly int $bitrate = 128000
    ) {
        if (!is_file($inputFile)) {
            throw new RuntimeException("Opus 文件不存在: {$inputFile}");
        }
        if ($bitrate < 48000 || $bitrate > 192000) {
            throw new InvalidArgumentException('AAC 码率必须在 48000 至 192000 bit/s 之间');
        }
    }

    public function run(): array
    {
        $result = AudioParallelPipeline::run(
            'opus',
            $this->inputFile,
            'aac',
            $this->outputFile,
            // 与原进程内 OpusToAacTranscoder 一致：码率直接使用传入值，不按声道折算
            ['bitrate' => $this->bitrate, 'pcm' => 'f64'],
            function (array $meta): void {
                if ($meta['channels'] !== 1 && $meta['channels'] !== 2) {
                    throw new InvalidArgumentException('当前 Opus2AAC 只支持单声道或双声道 Ogg Opus');
                }
                if ($meta['mappingFamily'] !== 0) {
                    throw new InvalidArgumentException('当前 Opus2AAC 只支持 mapping-family-0 Ogg Opus');
                }
            },
            null,
            // 原 transcodeOgg 始终应用 OpusHead 的 outputGainQ8；
            // worker 解码选项名为 --gain-q8（与 audio-worker.php decodeOpus 参数一致）
            ['gain-q8' => 'auto', 'pcm' => 'f64']
        );
        return [
            'output' => $this->outputFile,
            'sampleRate' => $result['decode']['sampleRate'],
            'channels' => $result['decode']['channels'],
            'bitrate' => $result['encode']['bitrate'],
            'bytes' => $result['encode']['bytes'],
            'opusPackets' => $result['decode']['packets'],
            'duration' => $result['decode']['seconds'],
        ];
    }
}
