<?php

namespace Xiaosongshu\Flv2mp4\Manage;

use InvalidArgumentException;
use RuntimeException;
use Xiaosongshu\Flv2mp4\Opus\Encode\CeltFrameEncoder;
use Xiaosongshu\Flv2mp4\Opus\Encode\OggOpusWriter;

/**
 * 将 S16LE PCM 封装并编码为 Ogg Opus 音频文件。
 *
 * 当前编码器限制为 48 kHz、20 ms、单声道 CELT。
 */
final class Pcm2Opus
{
    private const SAMPLE_RATE = 48000;
    private const FRAME_SAMPLES = 960;
    private const BYTES_PER_SAMPLE = 2;

    public function __construct(
        private readonly string $inputFile,
        private readonly string $outputFile,
        private readonly int $sampleRate = self::SAMPLE_RATE,
        private readonly int $channels = 1,
        private readonly int $preSkip = 312
    ) {
        if (!is_file($inputFile)) {
            throw new RuntimeException("PCM 文件不存在: {$inputFile}");
        }
        if ($sampleRate !== self::SAMPLE_RATE) {
            throw new InvalidArgumentException('当前纯 PHP CELT 编码器只支持 48000 Hz');
        }
        if ($channels !== 1) {
            throw new InvalidArgumentException('当前纯 PHP CELT 编码器只支持单声道');
        }
        if ($preSkip < 0 || $preSkip > 65535) {
            throw new InvalidArgumentException('Opus pre-skip 无效');
        }
    }

    public function run(): array
    {
        $input = fopen($this->inputFile, 'rb');
        if ($input === false) {
            throw new RuntimeException("无法读取 PCM 文件: {$this->inputFile}");
        }

        $directory = dirname($this->outputFile);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            fclose($input);
            throw new RuntimeException("无法创建输出目录: {$directory}");
        }

        $part = $this->outputFile . '.part.' . bin2hex(random_bytes(6));
        $frameEncoder = new CeltFrameEncoder(1);
        $writer = new OggOpusWriter(1, $this->sampleRate, $this->preSkip);
        $buffer = '';
        $inputSamples = 0;
        $packets = 0;
        $granule = $this->preSkip;

        try {
            while (!feof($input)) {
                $chunk = fread($input, 1024 * 1024);
                if ($chunk === false) {
                    throw new RuntimeException('读取 PCM 文件失败');
                }
                $buffer .= $chunk;

                while (strlen($buffer) >= self::FRAME_SAMPLES * self::BYTES_PER_SAMPLE) {
                    $frame = substr($buffer, 0, self::FRAME_SAMPLES * self::BYTES_PER_SAMPLE);
                    $buffer = substr($buffer, self::FRAME_SAMPLES * self::BYTES_PER_SAMPLE);
                    $writer->writePacket(
                        $frameEncoder->encodePacket($this->decodeS16le($frame)),
                        $granule += self::FRAME_SAMPLES
                    );
                    $inputSamples += self::FRAME_SAMPLES;
                    ++$packets;
                }
            }

            if (strlen($buffer) % self::BYTES_PER_SAMPLE !== 0) {
                throw new RuntimeException('PCM 数据长度不是完整采样的整数倍');
            }
            if ($buffer !== '' || $packets === 0) {
                $samples = $this->decodeS16le($buffer);
                $length = count($samples);
                $samples = array_pad($samples, self::FRAME_SAMPLES, 0.0);
                $writer->writePacket(
                    $frameEncoder->encodePacket($samples),
                    $granule += self::FRAME_SAMPLES
                );
                $inputSamples += $length;
                ++$packets;
            }

            $data = $writer->finish($this->preSkip + $inputSamples);
            if (file_put_contents($part, $data) !== strlen($data)) {
                throw new RuntimeException("无法写入 Opus 文件: {$part}");
            }
            if (!rename($part, $this->outputFile)) {
                throw new RuntimeException("无法生成 Opus 文件: {$this->outputFile}");
            }

            return [
                'output' => $this->outputFile,
                'sampleRate' => $this->sampleRate,
                'channels' => $this->channels,
                'preSkip' => $this->preSkip,
                'bytes' => strlen($data),
                'packets' => $packets,
                'samples' => $inputSamples,
                'duration' => $inputSamples / $this->sampleRate,
            ];
        } finally {
            fclose($input);
            if (is_file($part)) {
                @unlink($part);
            }
        }
    }

    /** @return float[] */
    private function decodeS16le(string $data): array
    {
        $samples = [];
        for ($offset = 0, $length = strlen($data); $offset < $length; $offset += 2) {
            $value = unpack('v', substr($data, $offset))[1];
            if ($value >= 32768) {
                $value -= 65536;
            }
            $samples[] = $value / 32768.0;
        }
        return $samples;
    }
}
