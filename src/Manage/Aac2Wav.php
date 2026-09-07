<?php

namespace Xiaosongshu\Flv2mp4\Manage;

use RuntimeException;

/**
 * @purpose 从mp4/flv文件中提取aac-lc音频转码为wav
 * @author yanglong
 * @time 2026年9月7日17:47:26
 */
final class Aac2Wav
{
    private string $inputFile;
    private string $outputFile;

    /**
     * 初始化
     * @param string $inputFile 原始音视频文件
     * @param string $outputFile 输出的wav文件
     */
    public function __construct(string $inputFile, string $outputFile)
    {
        if (!is_file($inputFile)) {
            throw new RuntimeException("输入文件不存在: {$inputFile}");
        }
        $this->inputFile = $inputFile;
        $this->outputFile = $outputFile;
    }

    /**
     * 启动函数
     * @return array
     */
    public function run(): array
    {
        $tempDir = dirname($this->outputFile);
        if (!is_dir($tempDir) && !mkdir($tempDir, 0777, true) && !is_dir($tempDir)) {
            throw new RuntimeException("无法创建输出目录: {$tempDir}");
        }
        $prefix = $tempDir . DIRECTORY_SEPARATOR . '.aac2wav.' . bin2hex(random_bytes(6));
        $aacFile = $prefix . '.aac';
        $pcmFile = $prefix . '.pcm';
        try {
            $aac = (new AAC2MP3())->process($this->inputFile, $aacFile, 'aac');
            $pcm = (new AacToPcm($aacFile, $pcmFile))->run();
            $wav = (new Pcm2Wav($pcmFile, $this->outputFile, $pcm['sampleRate'], $pcm['channels']))->run();
            return [
                'output' => $wav['output'],
                'sampleRate' => $wav['sampleRate'],
                'channels' => $wav['channels'],
                'bitsPerSample' => $wav['bitsPerSample'],
                'bytes' => $wav['bytes'],
                'duration' => $wav['duration'],
                'frames' => $aac['frames'],
            ];
        } finally {
            foreach ([$aacFile, $pcmFile] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
    }
}
