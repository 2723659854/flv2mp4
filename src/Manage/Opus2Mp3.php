<?php

namespace Xiaosongshu\Flv2mp4\Manage;

use InvalidArgumentException;
use RuntimeException;
use Xiaosongshu\Flv2mp4\Mp3\Config;
use Xiaosongshu\Flv2mp4\Mp3\Encoder;
use Xiaosongshu\Flv2mp4\Opus\OggOpusReader;
use Xiaosongshu\Flv2mp4\Opus\OpusDecoder;
use Xiaosongshu\Flv2mp4\Opus\Pcm16;

/**
 * @purpose 将 Ogg Opus 音频转码为 MPEG-1 Layer III MP3。
 * @author yanglong
 * @time 2026年9月8日17:48:23
 */
final class Opus2Mp3
{
    public function __construct(
        private readonly string $inputFile,
        private readonly string $outputFile,
        private readonly int $bitrate = 128000
    ) {
        if (!is_file($inputFile)) {
            throw new RuntimeException("Opus 文件不存在: {$inputFile}");
        }
        if (!in_array($bitrate, Config::BITRATES, true)) {
            throw new InvalidArgumentException('不支持的 MP3 码率');
        }
    }

    public function run(): array
    {
        $reader = OggOpusReader::fromFile($this->inputFile);
        $channels = $reader->channels();
        if ($channels !== 1 && $channels !== 2) {
            throw new InvalidArgumentException('当前 Opus2Mp3 只支持单声道或双声道 Ogg Opus');
        }
        if (($reader->head()['mappingFamily'] ?? null) !== 0) {
            throw new InvalidArgumentException('当前 Opus2Mp3 只支持 mapping-family-0 Ogg Opus');
        }
        $encoder = new Encoder(new Config($reader->sampleRate(), $channels, $this->bitrate));
        $decoder = new OpusDecoder($channels, $reader->sampleRate());
        $output = '';
        foreach ($reader->audioPackets() as $packet) {
            $samples = $decoder->decodeFloat($packet['data']);
            $trimStart = $packet['trimStartSamples'];
            $trimEnd = $packet['trimEndSamples'];
            if ($trimStart !== 0 || $trimEnd !== 0) {
                $samples = array_slice(
                    $samples,
                    $trimStart * $channels,
                    ($decoder->lastSampleCount() - $trimStart - $trimEnd) * $channels
                );
            }
            $gainQ8 = $reader->head()['outputGainQ8'] ?? 0;
            if ($gainQ8 !== 0) {
                $gain = pow(10.0, $gainQ8 / (20.0 * 256.0));
                foreach ($samples as &$sample) {
                    $sample *= $gain;
                }
                unset($sample);
            }
            $output .= $encoder->encodeS16le(Pcm16::floatsToS16le($samples, $channels));
        }
        $output .= $encoder->flush();
        if ($output === '') {
            throw new RuntimeException('Opus 未生成 MP3 数据');
        }
        $dir = dirname($this->outputFile);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException("无法创建输出目录: {$dir}");
        }
        $part = $this->outputFile . '.part.' . bin2hex(random_bytes(6));
        try {
            if (file_put_contents($part, $output) === false || !rename($part, $this->outputFile)) {
                throw new RuntimeException("无法生成 MP3 文件: {$this->outputFile}");
            }
            $samples = 0;
            foreach ($reader->audioPackets() as $packet) {
                $samples += $packet['durationSamples'] - $packet['trimStartSamples'] - $packet['trimEndSamples'];
            }
            return [
                'output' => $this->outputFile,
                'sampleRate' => $reader->sampleRate(),
                'channels' => $channels,
                'bitrate' => $this->bitrate,
                'bytes' => strlen($output),
                'frames' => $encoder->frameCount(),
                'opusPackets' => count($reader->audioPackets()),
                'duration' => $samples / $reader->sampleRate(),
            ];
        } finally {
            if (is_file($part)) {
                @unlink($part);
            }
        }
    }
}
