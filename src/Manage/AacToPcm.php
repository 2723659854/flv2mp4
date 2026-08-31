<?php

namespace Xiaosongshu\Flv2mp4\Manage;

/** 将 AAC-LC ADTS 文件流式解码为交错 S16LE PCM。 */
final class AacToPcm
{
    private string $inputFile;
    private string $outputFile;

    public function __construct(string $inputFile, string $outputFile)
    {
        if (!is_file($inputFile)) {
            throw new \RuntimeException("AAC文件不存在: {$inputFile}");
        }
        $this->inputFile = $inputFile;
        $this->outputFile = $outputFile;
        $dir = dirname($outputFile);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException("无法创建输出目录: {$dir}");
        }
    }

    public function run(): array
    {
        $input = fopen($this->inputFile, 'rb');
        if (!$input) {
            throw new \RuntimeException("无法读取 AAC 文件: {$this->inputFile}");
        }
        $part = $this->outputFile . '.part.' . bin2hex(random_bytes(6));
        $output = null;
        $decoder = new \Xiaosongshu\Flv2mp4\Aac\AacLcDecoder();
        $bytes = 0;
        try {
            $output = fopen($part, 'wb');
            if (!$output) {
                throw new \RuntimeException("无法创建 PCM 文件: {$part}");
            }
            while (!feof($input)) {
                $data = fread($input, 1024 * 1024);
                if ($data === false) {
                    throw new \RuntimeException('读取 AAC 文件失败');
                }
                if ($data === '') {
                    continue;
                }
                $pcm = $decoder->push($data);
                $this->write($output, $pcm);
                $bytes += strlen($pcm);
            }
            $pcm = $decoder->flush();
            $this->write($output, $pcm);
            $bytes += strlen($pcm);
            fclose($output);
            $output = null;
            if (!rename($part, $this->outputFile)) {
                throw new \RuntimeException("无法生成 PCM 文件: {$this->outputFile}");
            }
            return [
                'output' => $this->outputFile,
                'sampleRate' => $decoder->sampleRate(),
                'channels' => $decoder->channels(),
                'bytes' => $bytes,
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

    private function write($handle, string $data): void
    {
        $length = strlen($data);
        for ($offset = 0; $offset < $length;) {
            $written = fwrite($handle, substr($data, $offset));
            if ($written === false || $written === 0) {
                throw new \RuntimeException('写入 PCM 文件失败');
            }
            $offset += $written;
        }
    }
}
