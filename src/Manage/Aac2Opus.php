<?php

namespace Xiaosongshu\Flv2mp4\Manage;

use RuntimeException;

/**
 * @purpose 将 AAC-LC 音频解码并编码为 Ogg Opus。
 * 并行模式：AAC 解码子进程与 Opus 编码子进程（编码侧流式下混）通过管道同时进行。
 * @author yanglong
 * @time 2026年9月15日16:14:09
 */
final class Aac2Opus
{
    public function __construct(
        private readonly string $inputFile,
        private readonly string $outputFile,
        private readonly int $preSkip = 312,
        /** 进度回调 fn(string $message)，各阶段与 Opus 编码进度经此输出 */
        private readonly ?\Closure $progress = null
    ) {
        if (!is_file($inputFile)) {
            throw new RuntimeException("输入文件不存在: {$inputFile}");
        }
    }

    public function run(): array
    {
        $progress = $this->progress;
        $rate = 0;
        $result = AudioParallelPipeline::run(
            'aac',
            $this->inputFile,
            'opus',
            $this->outputFile,
            ['preSkip' => $this->preSkip],
            function (array $meta) use (&$rate): void {
                $rate = $meta['sampleRate'];
                if ($meta['sampleRate'] !== 48000) {
                    throw new RuntimeException(
                        '当前纯 PHP Opus 编码器只支持 48000 Hz AAC，实际为 '
                        . $meta['sampleRate'] . ' Hz'
                    );
                }
                if ($meta['channels'] !== 1 && $meta['channels'] !== 2) {
                    throw new RuntimeException('当前纯 PHP Opus 编码器只支持单声道或双声道 AAC');
                }
            },
            function (string $stage, array $event) use ($progress, &$rate): void {
                if ($progress === null) {
                    return;
                }
                if ($stage === 'decode') {
                    match ($event['ev'] ?? '') {
                        'meta' => $progress("AAC 提取完成：{$event['frames']} 帧"),
                        'progress' => $progress(sprintf('PCM 解码进度：%.1f 秒（%d Hz）', $event['seconds'], $rate)),
                        'done' => $progress(sprintf('PCM 解码完成：%.1f 秒（%d Hz）', $event['seconds'], $rate)),
                        default => null,
                    };
                } elseif (($event['ev'] ?? '') === 'progress') {
                    $progress(sprintf('Opus 编码进度：%d 包 / %.1f 秒音频', $event['packets'], $event['seconds']));
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
            'frames' => $result['decode']['frames'],
        ];
    }
}
