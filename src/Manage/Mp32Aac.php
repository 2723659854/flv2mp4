<?php

namespace Xiaosongshu\Flv2mp4\Manage;

use InvalidArgumentException;
use RuntimeException;
use Xiaosongshu\Flv2mp4\Aac\AacLcEncoder;

/**
 * @purpose 将 MP3 解码并转码为 AAC-LC ADTS。
 * @author yanglong
 * @time 2026年9月8日18:08:30
 */
final class Mp32Aac
{
    public function __construct(
        private readonly string $inputFile,
        private readonly string $outputFile,
        private readonly int $bitrate = 128000
    ) {
        if (!is_file($inputFile)) {
            throw new RuntimeException("MP3 文件不存在: {$inputFile}");
        }
        if ($bitrate < 48000 || $bitrate > 192000) {
            throw new InvalidArgumentException('AAC 码率必须在 48000 至 192000 bit/s 之间');
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
        try {
            $pcm = (new Mp32Pcm($this->inputFile, $pcmFile))->run();
            if ($pcm['sampleRate'] !== 48000) {
                throw new InvalidArgumentException('AacLcEncoder 只支持 48000 Hz MP3 输入');
            }
            if ($pcm['channels'] !== 1 && $pcm['channels'] !== 2) {
                throw new InvalidArgumentException('AacLcEncoder 只支持单声道或双声道 MP3 输入');
            }
            $bitrate = $pcm['channels'] === 1 ? min($this->bitrate, 192000) : max($this->bitrate, 96000);
            $encoder = new AacLcEncoder($bitrate, $pcm['channels']);
            $input = fopen($pcmFile, 'rb');
            $output = fopen($part, 'wb');
            if ($input === false || $output === false) {
                if (is_resource($input)) fclose($input);
                if (is_resource($output)) fclose($output);
                throw new RuntimeException('无法打开 MP3 解码 PCM 或 AAC 输出文件');
            }
            try {
                $frameBytes = $pcm['channels'] * 2;
                $pending = '';
                while (!feof($input)) {
                    $data = fread($input, 1024 * 1024);
                    if ($data === false) throw new RuntimeException('读取 PCM 数据失败');
                    if ($data === '') continue;
                    $pending .= $data;
                    $usable = intdiv(strlen($pending), $frameBytes) * $frameBytes;
                    if ($usable === 0) continue;
                    $this->write($output, $encoder->encodeS16le(substr($pending, 0, $usable)));
                    $pending = substr($pending, $usable);
                }
                if ($pending !== '') throw new RuntimeException('PCM 数据不是完整采样帧的整数倍');
                $this->write($output, $encoder->flush());
            } finally {
                fclose($input);
                fclose($output);
            }
            if (!rename($part, $this->outputFile)) {
                throw new RuntimeException("无法生成 AAC 文件: {$this->outputFile}");
            }
            return [
                'output' => $this->outputFile,
                'sampleRate' => $pcm['sampleRate'],
                'channels' => $pcm['channels'],
                'bitrate' => $bitrate,
                'bytes' => filesize($this->outputFile),
                'frames' => $encoder->frameCount(),
                'duration' => $pcm['bytes'] / ($pcm['sampleRate'] * $pcm['channels'] * 2),
            ];
        } finally {
            foreach ([$pcmFile, $part] as $file) {
                if (is_file($file)) @unlink($file);
            }
        }
    }

    private function write($handle, string $data): void
    {
        for ($offset = 0, $length = strlen($data); $offset < $length;) {
            $written = fwrite($handle, substr($data, $offset));
            if ($written === false || $written === 0) throw new RuntimeException('写入 AAC 文件失败');
            $offset += $written;
        }
    }
}
