<?php

namespace Xiaosongshu\Flv2mp4\Manage;

use InvalidArgumentException;
use RuntimeException;

/**
 * 将 MP3 解码并编码为 Ogg Opus。
 */
final class Mp32Opus
{
    public function __construct(
        private readonly string $inputFile,
        private readonly string $outputFile,
        private readonly int $preSkip = 312
    ) {
        if (!is_file($inputFile)) {
            throw new RuntimeException("MP3 文件不存在: {$inputFile}");
        }
        if ($preSkip < 0 || $preSkip > 65535) {
            throw new InvalidArgumentException('Opus pre-skip 无效');
        }
    }

    public function run(): array
    {
        $directory = dirname($this->outputFile);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException("无法创建输出目录: {$directory}");
        }

        $prefix = $directory . DIRECTORY_SEPARATOR . '.mp32opus.' . bin2hex(random_bytes(6));
        $pcmFile = $prefix . '.pcm';
        $monoFile = $prefix . '.mono.pcm';

        try {
            $pcm = (new Mp32Pcm($this->inputFile, $pcmFile))->run();
            if ($pcm['sampleRate'] !== 48000) {
                throw new InvalidArgumentException('纯 PHP Opus 编码器只支持 48000 Hz MP3 输入');
            }
            if ($pcm['channels'] !== 1 && $pcm['channels'] !== 2) {
                throw new InvalidArgumentException('纯 PHP Opus 编码器只支持单声道或双声道 MP3 输入');
            }

            $encodePcmFile = $pcmFile;
            if ($pcm['channels'] === 2) {
                $this->downmixStereoToMono($pcmFile, $monoFile);
                $encodePcmFile = $monoFile;
            }

            return (new Pcm2Opus(
                $encodePcmFile,
                $this->outputFile,
                $pcm['sampleRate'],
                1,
                $this->preSkip
            ))->run() + ['source' => $this->inputFile];
        } finally {
            foreach ([$pcmFile, $monoFile] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
    }

    private function downmixStereoToMono(string $inputFile, string $outputFile): void
    {
        $input = fopen($inputFile, 'rb');
        $output = fopen($outputFile, 'wb');
        if ($input === false || $output === false) {
            if (is_resource($input)) fclose($input);
            if (is_resource($output)) fclose($output);
            throw new RuntimeException('无法创建 MP3 单声道下混文件');
        }

        try {
            $buffer = '';
            while (!feof($input)) {
                $data = fread($input, 1024 * 1024);
                if ($data === false) {
                    throw new RuntimeException('读取立体声 PCM 失败');
                }
                $buffer .= $data;
                $usable = strlen($buffer) - (strlen($buffer) % 4);
                for ($offset = 0; $offset < $usable; $offset += 4) {
                    $left = unpack('v', substr($buffer, $offset))[1];
                    $right = unpack('v', substr($buffer, $offset + 2))[1];
                    if ($left >= 32768) $left -= 65536;
                    if ($right >= 32768) $right -= 65536;
                    $mono = (int) max(-32768, min(32767, round(($left + $right) * 0.5)));
                    $packed = pack('v', $mono < 0 ? $mono + 65536 : $mono);
                    if (fwrite($output, $packed) !== 2) {
                        throw new RuntimeException('写入单声道 PCM 失败');
                    }
                }
                $buffer = substr($buffer, $usable);
            }
            if ($buffer !== '') {
                throw new RuntimeException('立体声 PCM 数据不完整');
            }
        } finally {
            fclose($input);
            fclose($output);
        }
    }
}
