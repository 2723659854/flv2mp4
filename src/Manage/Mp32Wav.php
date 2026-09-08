<?php

namespace Xiaosongshu\Flv2mp4\Manage;

use RuntimeException;

/**
 * @purpose MPEG-1 Layer III MP3 解码并封装为 WAV
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
        $pcmFile = $this->outputFile . '.pcm.' . bin2hex(random_bytes(6));
        try {
            $pcm = (new Mp32Pcm($this->inputFile, $pcmFile))->run();
            $wav = (new Pcm2Wav(
                $pcmFile,
                $this->outputFile,
                $pcm['sampleRate'],
                $pcm['channels']
            ))->run();
            $wav['source'] = $this->inputFile;
            $wav['pcmBytes'] = $pcm['bytes'];
            return $wav;
        } finally {
            if (is_file($pcmFile)) {
                @unlink($pcmFile);
            }
        }
    }
}
