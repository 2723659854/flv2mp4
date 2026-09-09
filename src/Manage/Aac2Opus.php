<?php

namespace Xiaosongshu\Flv2mp4\Manage;

use RuntimeException;

/**
 * 将 AAC-LC 音频解码并编码为 Ogg Opus。
 *
 * 当前 Opus 编码器限制为 48000 Hz 单声道 CELT。
 */
final class Aac2Opus
{
    public function __construct(
        private readonly string $inputFile,
        private readonly string $outputFile,
        private readonly int $preSkip = 312
    ) {
        if (!is_file($inputFile)) {
            throw new RuntimeException("输入文件不存在: {$inputFile}");
        }
    }

    public function run(): array
    {
        $directory = dirname($this->outputFile);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException("无法创建输出目录: {$directory}");
        }

        $prefix = $directory . DIRECTORY_SEPARATOR . '.aac2opus.' . bin2hex(random_bytes(6));
        $aacFile = $prefix . '.aac';
        $pcmFile = $prefix . '.pcm';

        try {
            $aac = (new AAC2MP3())->process($this->inputFile, $aacFile, 'aac');
            $pcm = (new AacToPcm($aacFile, $pcmFile))->run();
            if ($pcm['sampleRate'] !== 48000) {
                throw new RuntimeException(
                    '当前纯 PHP Opus 编码器只支持 48000 Hz AAC，实际为 '
                    . $pcm['sampleRate'] . ' Hz'
                );
            }

            $encodePcmFile = $pcmFile;
            if ($pcm['channels'] === 2) {
                $encodePcmFile = $prefix . '.mono.pcm';
                $this->downmixStereoToMono($pcmFile, $encodePcmFile);
            } elseif ($pcm['channels'] !== 1) {
                throw new RuntimeException('当前纯 PHP Opus 编码器只支持单声道或双声道 AAC');
            }

            $opus = (new Pcm2Opus(
                $encodePcmFile,
                $this->outputFile,
                $pcm['sampleRate'],
                1,
                $this->preSkip
            ))->run();

            return [
                'output' => $opus['output'],
                'sampleRate' => $opus['sampleRate'],
                'channels' => $opus['channels'],
                'preSkip' => $opus['preSkip'],
                'bytes' => $opus['bytes'],
                'packets' => $opus['packets'],
                'samples' => $opus['samples'],
                'duration' => $opus['duration'],
                'frames' => $aac['frames'],
            ];
        } finally {
            foreach ([$aacFile, $pcmFile, $prefix . '.mono.pcm'] as $file) {
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
            throw new RuntimeException('无法创建 AAC 单声道下混文件');
        }
        try {
            $buffer = '';
            while (!feof($input)) {
                $data = fread($input, 1024 * 1024);
                if ($data === false) throw new RuntimeException('读取立体声 PCM 失败');
                $buffer .= $data;
                $usable = strlen($buffer) - (strlen($buffer) % 4);
                for ($offset = 0; $offset < $usable; $offset += 4) {
                    $left = unpack('v', substr($buffer, $offset))[1];
                    $right = unpack('v', substr($buffer, $offset + 2))[1];
                    if ($left >= 32768) $left -= 65536;
                    if ($right >= 32768) $right -= 65536;
                    $mono = (int) max(-32768, min(32767, round(($left + $right) * 0.5)));
                    if (fwrite($output, pack('v', $mono < 0 ? $mono + 65536 : $mono)) !== 2) {
                        throw new RuntimeException('写入单声道 PCM 失败');
                    }
                }
                $buffer = substr($buffer, $usable);
            }
            if ($buffer !== '') throw new RuntimeException('立体声 PCM 数据不完整');
        } finally {
            fclose($input);
            fclose($output);
        }
    }
}
