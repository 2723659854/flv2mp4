<?php

namespace Xiaosongshu\Flv2mp4\Manage;

use InvalidArgumentException;
use RuntimeException;
use Xiaosongshu\Flv2mp4\Opus\OggOpusReader;
use Xiaosongshu\Flv2mp4\Opus\OpusDecoder;
use Xiaosongshu\Flv2mp4\Opus\Pcm16;

/**
 * @purpose 将 Ogg Opus 音频解码并封装为 WAV。
 * @author yanglong
 * @time 2026年9月8日11:11:07
 */
final class Opus2Wav
{
    private string $inputFile;
    private string $outputFile;

    public function __construct(string $inputFile, string $outputFile)
    {
        if (!is_file($inputFile)) {
            throw new RuntimeException("Opus 文件不存在: {$inputFile}");
        }
        $this->inputFile = $inputFile;
        $this->outputFile = $outputFile;
    }

    public function run(): array
    {
        $reader = OggOpusReader::fromFile($this->inputFile);
        $channels = $reader->channels();
        if ($channels !== 1 && $channels !== 2) {
            throw new InvalidArgumentException('当前 Opus2Wav 只支持单声道或双声道 Ogg Opus');
        }
        if (($reader->head()['mappingFamily'] ?? null) !== 0) {
            throw new InvalidArgumentException('当前 Opus2Wav 只支持 mapping-family-0 Ogg Opus');
        }
        $decoder = new OpusDecoder($channels, $reader->sampleRate());
        $pcm = '';
        foreach ($reader->audioPackets() as $packet) {
            $samples = $decoder->decodeFloat($packet['data']);
            $trimStart = $packet['trimStartSamples'];
            $trimEnd = $packet['trimEndSamples'];
            if ($trimStart !== 0 || $trimEnd !== 0) {
                $samples = array_slice($samples, $trimStart * $channels, ($decoder->lastSampleCount() - $trimStart - $trimEnd) * $channels);
            }
            $pcm .= Pcm16::floatsToS16le($samples, $channels);
        }
        $tempPcm = dirname($this->outputFile) . DIRECTORY_SEPARATOR . '.opus2wav.' . bin2hex(random_bytes(6)) . '.pcm';
        try {
            if (file_put_contents($tempPcm, $pcm) === false) {
                throw new RuntimeException("无法创建临时 PCM 文件: {$tempPcm}");
            }
            return (new Pcm2Wav($tempPcm, $this->outputFile, $reader->sampleRate(), $channels))->run();
        } finally {
            if (is_file($tempPcm)) {
                @unlink($tempPcm);
            }
        }
    }
}
