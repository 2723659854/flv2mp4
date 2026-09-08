<?php

namespace Xiaosongshu\Flv2mp4\Manage;

use InvalidArgumentException;
use RuntimeException;

/**
 * @purpose 将 PCM WAV 音频还原为不含 WAV 头的原始 PCM 数据。
 * @author yanglong
 * @time 2026年9月8日10:31:33
 */
final class Wav2Pcm
{
    private string $inputFile;
    private string $outputFile;

    public function __construct(string $inputFile, string $outputFile)
    {
        if (!is_file($inputFile)) {
            throw new RuntimeException("WAV 文件不存在: {$inputFile}");
        }
        $this->inputFile = $inputFile;
        $this->outputFile = $outputFile;
    }

    public function run(): array
    {
        $input = fopen($this->inputFile, 'rb');
        if ($input === false) {
            throw new RuntimeException("无法读取 WAV 文件: {$this->inputFile}");
        }
        $part = $this->outputFile . '.part.' . bin2hex(random_bytes(6));
        $output = null;
        try {
            $format = $this->readFormat($input);
            $dir = dirname($this->outputFile);
            if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
                throw new RuntimeException("无法创建输出目录: {$dir}");
            }
            $output = fopen($part, 'wb');
            if ($output === false) {
                throw new RuntimeException("无法创建 PCM 文件: {$part}");
            }
            $bytes = 0;
            $remaining = $format['dataSize'];
            while ($remaining > 0) {
                $data = fread($input, min(1024 * 1024, $remaining));
                if ($data === false || $data === '') {
                    throw new RuntimeException('读取 WAV PCM 数据失败');
                }
                $this->write($output, $data);
                $length = strlen($data);
                $bytes += $length;
                $remaining -= $length;
            }
            fclose($output);
            $output = null;
            if (!rename($part, $this->outputFile)) {
                throw new RuntimeException("无法生成 PCM 文件: {$this->outputFile}");
            }
            return [
                'output' => $this->outputFile,
                'sampleRate' => $format['sampleRate'],
                'channels' => $format['channels'],
                'bitsPerSample' => $format['bitsPerSample'],
                'audioFormat' => $format['audioFormat'],
                'bytes' => $bytes,
                'duration' => $bytes / $format['blockAlign'] / $format['sampleRate'],
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

    private function readFormat($input): array
    {
        $riff = $this->readExactly($input, 12);
        if (substr($riff, 0, 4) !== 'RIFF' || substr($riff, 8, 4) !== 'WAVE') {
            throw new InvalidArgumentException('无效的 WAV 文件');
        }
        $fmt = null;
        $dataOffset = null;
        $dataSize = null;
        while (!feof($input)) {
            $chunk = $this->readExactly($input, 8);
            if ($chunk === '') {
                break;
            }
            $size = unpack('V', substr($chunk, 4, 4))[1];
            if ($size < 0) {
                throw new InvalidArgumentException('WAV 数据块长度无效');
            }
            if (substr($chunk, 0, 4) === 'fmt ') {
                $body = $this->readExactly($input, $size);
                if (strlen($body) < 16) {
                    throw new InvalidArgumentException('WAV fmt 数据块不完整');
                }
                $values = unpack('vaudioFormat/vchannels/VsampleRate/VbyteRate/vblockAlign/vbitsPerSample', substr($body, 0, 16));
                $fmt = $values;
            } elseif (substr($chunk, 0, 4) === 'data') {
                $dataOffset = ftell($input);
                $dataSize = $size;
                break;
            } else {
                $this->skip($input, $size);
            }
            if ($size % 2 !== 0) {
                $this->skip($input, 1);
            }
        }
        if ($fmt === null || $dataOffset === null || $dataSize === null) {
            throw new InvalidArgumentException('WAV 缺少 fmt 或 data 数据块');
        }
        if ($fmt['audioFormat'] !== 1) {
            throw new InvalidArgumentException('只支持未压缩 PCM WAV');
        }
        if ($fmt['channels'] < 1 || $fmt['sampleRate'] < 1 || $fmt['bitsPerSample'] < 1 || $fmt['blockAlign'] < 1) {
            throw new InvalidArgumentException('WAV 音频参数无效');
        }
        if ($dataSize % $fmt['blockAlign'] !== 0) {
            throw new InvalidArgumentException('WAV PCM 数据不是完整采样帧的整数倍');
        }
        return [
            'audioFormat' => $fmt['audioFormat'],
            'channels' => $fmt['channels'],
            'sampleRate' => $fmt['sampleRate'],
            'blockAlign' => $fmt['blockAlign'],
            'bitsPerSample' => $fmt['bitsPerSample'],
            'dataSize' => $dataSize,
        ];
    }

    private function readExactly($input, int $length): string
    {
        $data = '';
        while (strlen($data) < $length && !feof($input)) {
            $part = fread($input, $length - strlen($data));
            if ($part === false || $part === '') {
                break;
            }
            $data .= $part;
        }
        if ($data !== '' && strlen($data) !== $length) {
            throw new InvalidArgumentException('WAV 文件已截断');
        }
        return $data;
    }

    private function skip($input, int $length): void
    {
        if ($length > 0 && fseek($input, $length, SEEK_CUR) !== 0) {
            throw new InvalidArgumentException('WAV 数据块已截断');
        }
    }

    private function write($handle, string $data): void
    {
        for ($offset = 0, $length = strlen($data); $offset < $length;) {
            $written = fwrite($handle, substr($data, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('写入 PCM 文件失败');
            }
            $offset += $written;
        }
    }
}
