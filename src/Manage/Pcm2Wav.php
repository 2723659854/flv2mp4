<?php

namespace Xiaosongshu\Flv2mp4\Manage;

use InvalidArgumentException;
use RuntimeException;

/** 将交错 S16LE PCM 封装为 WAV 音频文件。 */
final class Pcm2Wav
{
    private string $inputFile;
    private string $outputFile;
    private int $sampleRate;
    private int $channels;

    public function __construct(string $inputFile, string $outputFile, int $sampleRate = 48000, int $channels = 2)
    {
        if (!is_file($inputFile)) {
            throw new RuntimeException("PCM 文件不存在: {$inputFile}");
        }
        if ($sampleRate < 1 || $sampleRate > 384000) {
            throw new InvalidArgumentException('PCM 采样率无效');
        }
        if ($channels < 1 || $channels > 32) {
            throw new InvalidArgumentException('PCM 声道数无效');
        }
        $this->inputFile = $inputFile;
        $this->outputFile = $outputFile;
        $this->sampleRate = $sampleRate;
        $this->channels = $channels;
    }

    public function run(): array
    {
        $input = fopen($this->inputFile, 'rb');
        if ($input === false) {
            throw new RuntimeException("无法读取 PCM 文件: {$this->inputFile}");
        }
        $dir = dirname($this->outputFile);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            fclose($input);
            throw new RuntimeException("无法创建输出目录: {$dir}");
        }
        $part = $this->outputFile . '.part.' . bin2hex(random_bytes(6));
        $output = null;
        $dataBytes = 0;
        $blockAlign = $this->channels * 2;
        try {
            $output = fopen($part, 'wb');
            if ($output === false) {
                throw new RuntimeException("无法创建 WAV 文件: {$part}");
            }
            $this->write($output, $this->header(0, $blockAlign));
            while (!feof($input)) {
                $data = fread($input, 1024 * 1024);
                if ($data === false) {
                    throw new RuntimeException('读取 PCM 文件失败');
                }
                if ($data === '') {
                    continue;
                }
                if (strlen($data) % $blockAlign !== 0) {
                    throw new RuntimeException('PCM 数据长度不是完整采样帧的整数倍');
                }
                $this->write($output, $data);
                $dataBytes += strlen($data);
            }
            if (fseek($output, 0, SEEK_SET) !== 0) {
                throw new RuntimeException('无法回写 WAV 文件头');
            }
            $this->write($output, $this->header($dataBytes, $blockAlign));
            fclose($output);
            $output = null;
            if (!rename($part, $this->outputFile)) {
                throw new RuntimeException("无法生成 WAV 文件: {$this->outputFile}");
            }
            return [
                'output' => $this->outputFile,
                'sampleRate' => $this->sampleRate,
                'channels' => $this->channels,
                'bitsPerSample' => 16,
                'bytes' => $dataBytes,
                'duration' => $dataBytes / $blockAlign / $this->sampleRate,
            ];
        } finally {
            if (is_resource($output)) {
                fclose($output);
            }
            fclose($input);
            if (is_file($part)) {
                @unlink($part);
            }
        }
    }

    private function header(int $dataBytes, int $blockAlign): string
    {
        $byteRate = $this->sampleRate * $blockAlign;
        return 'RIFF'
            . pack('V', 36 + $dataBytes)
            . 'WAVE'
            . 'fmt '
            . pack('VvvVVvv', 16, 1, $this->channels, $this->sampleRate, $byteRate, $blockAlign, 16)
            . 'data'
            . pack('V', $dataBytes);
    }

    private function write($handle, string $data): void
    {
        for ($offset = 0, $length = strlen($data); $offset < $length;) {
            $written = fwrite($handle, substr($data, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('写入 WAV 文件失败');
            }
            $offset += $written;
        }
    }
}
