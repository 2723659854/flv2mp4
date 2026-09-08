<?php

namespace Xiaosongshu\Flv2mp4\Manage;

use InvalidArgumentException;
use RuntimeException;
use Xiaosongshu\Flv2mp4\Opus\OggOpusReader;
use Xiaosongshu\Flv2mp4\Opus\OpusToAacTranscoder;

/**
 * @purpose 将 Ogg Opus 音频转码为 AAC-LC ADTS。
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
        $ogg = @file_get_contents($this->inputFile);
        if ($ogg === false) {
            throw new RuntimeException("无法读取 Opus 文件: {$this->inputFile}");
        }
        $reader = new OggOpusReader($ogg);
        if ($reader->channels() !== 1 && $reader->channels() !== 2) {
            throw new InvalidArgumentException('当前 Opus2AAC 只支持单声道或双声道 Ogg Opus');
        }
        if (($reader->head()['mappingFamily'] ?? null) !== 0) {
            throw new InvalidArgumentException('当前 Opus2AAC 只支持 mapping-family-0 Ogg Opus');
        }
        $aac = OpusToAacTranscoder::transcodeOgg($ogg, $this->bitrate);
        if ($aac === '') {
            throw new RuntimeException('Opus 未生成 AAC 数据');
        }
        $dir = dirname($this->outputFile);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException("无法创建输出目录: {$dir}");
        }
        $part = $this->outputFile . '.part.' . bin2hex(random_bytes(6));
        try {
            if (file_put_contents($part, $aac) === false || !rename($part, $this->outputFile)) {
                throw new RuntimeException("无法生成 AAC 文件: {$this->outputFile}");
            }
            return [
                'output' => $this->outputFile,
                'sampleRate' => $reader->sampleRate(),
                'channels' => $reader->channels(),
                'bitrate' => $this->bitrate,
                'bytes' => strlen($aac),
                'opusPackets' => count($reader->audioPackets()),
                'duration' => $this->duration($reader),
            ];
        } finally {
            if (is_file($part)) {
                @unlink($part);
            }
        }
    }

    private function duration(OggOpusReader $reader): float
    {
        $samples = 0;
        foreach ($reader->audioPackets() as $packet) {
            $samples += $packet['durationSamples'] - $packet['trimStartSamples'] - $packet['trimEndSamples'];
        }
        return $samples / $reader->sampleRate();
    }
}
