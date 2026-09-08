<?php

namespace Xiaosongshu\Flv2mp4\Manage;

use InvalidArgumentException;
use RuntimeException;
use Xiaosongshu\Flv2mp4\Mp3\Config;
use Xiaosongshu\Flv2mp4\Mp3\Encoder;

/**
 * @purpose 将 WAV 音频编码为 MPEG-1 Layer III MP3。
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
        $dir = dirname($this->outputFile);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException("无法创建输出目录: {$dir}");
        }
        $pcmFile = $this->outputFile . '.pcm.' . bin2hex(random_bytes(6));
        $part = $this->outputFile . '.part.' . bin2hex(random_bytes(6));
        $input = null;
        $output = null;
        try {
            $wav = (new Wav2Pcm($this->inputFile, $pcmFile))->run();
            if ($wav['audioFormat'] !== 1 || $wav['bitsPerSample'] !== 16) {
                throw new InvalidArgumentException('MP3 Encoder 只支持 16-bit 未压缩 PCM WAV');
            }
            if (!in_array($wav['sampleRate'], Config::SAMPLE_RATES, true)) {
                throw new InvalidArgumentException('MP3 Encoder 只支持 32000、44100 或 48000 Hz WAV');
            }
            if ($wav['channels'] !== 1 && $wav['channels'] !== 2) {
                throw new InvalidArgumentException('MP3 Encoder 只支持单声道或双声道 WAV');
            }

            $encoder = new Encoder(new Config($wav['sampleRate'], $wav['channels'], $this->bitrate));
            $input = fopen($pcmFile, 'rb');
            if ($input === false) {
                throw new RuntimeException("无法读取 PCM 文件: {$pcmFile}");
            }
            $output = fopen($part, 'wb');
            if ($output === false) {
                throw new RuntimeException("无法创建 MP3 文件: {$part}");
            }
            $frameBytes = $wav['channels'] * 2;
            $pending = '';
            $bytes = 0;
            while (!feof($input)) {
                $data = fread($input, 1024 * 1024);
                if ($data === false) {
                    throw new RuntimeException('读取 PCM 数据失败');
                }
                if ($data === '') {
                    continue;
                }
                $pending .= $data;
                $usable = intdiv(strlen($pending), $frameBytes) * $frameBytes;
                if ($usable === 0) {
                    continue;
                }
                $encoded = $encoder->encodeS16le(substr($pending, 0, $usable));
                $pending = substr($pending, $usable);
                $this->write($output, $encoded);
                $bytes += strlen($encoded);
            }
            if ($pending !== '') {
                throw new RuntimeException('PCM 数据不是完整采样帧的整数倍');
            }
            $tail = $encoder->flush();
            $this->write($output, $tail);
            $bytes += strlen($tail);
            fclose($input);
            $input = null;
            fclose($output);
            $output = null;
            if (!rename($part, $this->outputFile)) {
                throw new RuntimeException("无法生成 MP3 文件: {$this->outputFile}");
            }
            return [
                'output' => $this->outputFile,
                'sampleRate' => $wav['sampleRate'],
                'channels' => $wav['channels'],
                'bitrate' => $this->bitrate,
                'bytes' => $bytes,
                'frames' => $encoder->frameCount(),
                'duration' => $wav['duration'],
            ];
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            foreach ([$pcmFile, $part] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
    }

    private function write($handle, string $data): void
    {
        for ($offset = 0, $length = strlen($data); $offset < $length;) {
            $written = fwrite($handle, substr($data, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('写入 MP3 文件失败');
            }
            $offset += $written;
        }
    }
}
