<?php

namespace Xiaosongshu\Flv2mp4\Manage;

use InvalidArgumentException;
use RuntimeException;
use Xiaosongshu\Flv2mp4\Aac\AacLcEncoder;

/**
 * @purpose 将 WAV 音频转换为 AAC-LC ADTS 音频。
 * @author yanglong
 * @time 2026年9月8日10:31:10
 */
final class Wav2Aac
{
    private string $inputFile;
    private string $outputFile;
    private int $bitrate;

    public function __construct(string $inputFile, string $outputFile, int $bitrate = 128000)
    {
        if (!is_file($inputFile)) {
            throw new RuntimeException("WAV 文件不存在: {$inputFile}");
        }
        if ($bitrate < 96000 || $bitrate > 192000) {
            throw new InvalidArgumentException('AAC-LC 双声道码率必须在 96000 至 192000 bit/s 之间');
        }
        $this->inputFile = $inputFile;
        $this->outputFile = $outputFile;
        $this->bitrate = $bitrate;
    }

    public function run(): array
    {
        $dir = dirname($this->outputFile);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException("无法创建输出目录: {$dir}");
        }
        $prefix = $dir . DIRECTORY_SEPARATOR . '.wav2aac.' . bin2hex(random_bytes(6));
        $pcmFile = $prefix . '.pcm';
        $part = $this->outputFile . '.part.' . bin2hex(random_bytes(6));
        $output = null;
        try {
            $wav = (new Wav2Pcm($this->inputFile, $pcmFile))->run();
            if ($wav['audioFormat'] !== 1 || $wav['bitsPerSample'] !== 16) {
                throw new InvalidArgumentException('AacLcEncoder 只支持 16-bit 未压缩 PCM WAV');
            }
            if ($wav['sampleRate'] !== 48000) {
                throw new InvalidArgumentException('AacLcEncoder 只支持 48000 Hz WAV');
            }
            if ($wav['channels'] !== 1 && $wav['channels'] !== 2) {
                throw new InvalidArgumentException('AacLcEncoder 只支持单声道或双声道 WAV');
            }
            $bitrate = $wav['channels'] === 1 ? intdiv($this->bitrate, 2) : $this->bitrate;
            $encoder = new AacLcEncoder($bitrate, $wav['channels']);
            $input = fopen($pcmFile, 'rb');
            if ($input === false) {
                throw new RuntimeException("无法读取 PCM 文件: {$pcmFile}");
            }
            $output = fopen($part, 'wb');
            if ($output === false) {
                fclose($input);
                throw new RuntimeException("无法创建 AAC 文件: {$part}");
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
            fclose($output);
            $output = null;
            if (!rename($part, $this->outputFile)) {
                throw new RuntimeException("无法生成 AAC 文件: {$this->outputFile}");
            }
            return [
                'output' => $this->outputFile,
                'sampleRate' => $wav['sampleRate'],
                'channels' => $wav['channels'],
                'bitrate' => $bitrate,
                'bytes' => filesize($this->outputFile),
                'frames' => $encoder->frameCount(),
                'duration' => $wav['duration'],
            ];
        } finally {
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
                throw new RuntimeException('写入 AAC 文件失败');
            }
            $offset += $written;
        }
    }
}
