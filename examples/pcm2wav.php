<?php

if (version_compare(PHP_VERSION, '8.1.0', '<')) {
    fwrite(STDERR, '错误：此脚本需要 PHP 8.1 或更高版本，当前版本为 ' . PHP_VERSION . PHP_EOL);
    exit(1);
}

require_once __DIR__ . '/vendor/autoload.php';

$inputMp4 = $argv[1] ?? __DIR__ . '/test_demo.mp4';
$pcmFile = $argv[2] ?? __DIR__ . '/pcm2wav.pcm';
$wavFile = $argv[3] ?? __DIR__ . '/pcm2wav.wav';
$sampleRate = 48000;
$channels = 2;

if (in_array($inputMp4, ['-h', '--help'], true)) {
    echo '用法：php843 .\\pcm2wav.php [输入MP4] [临时PCM] [输出WAV]' . PHP_EOL;
    echo '示例：php843 .\\pcm2wav.php .\\test_demo.mp4 .\\pcm2wav.pcm .\\pcm2wav.wav' . PHP_EOL;
    exit(0);
}

if (!is_file($inputMp4)) {
    fwrite(STDERR, "输入 MP4 不存在: {$inputMp4}" . PHP_EOL);
    exit(1);
}

try {
    $start = microtime(true);
    echo "步骤1：使用 FFmpeg 提取 S16LE PCM..." . PHP_EOL;
    extractPcm($inputMp4, $pcmFile, $sampleRate, $channels);
    echo "PCM: {$pcmFile} (" . filesize($pcmFile) . " bytes)" . PHP_EOL;
    echo "参数: {$sampleRate} Hz, {$channels} 声道, S16LE" . PHP_EOL;

    echo "步骤2：使用纯 PHP 将 PCM 封装为 WAV..." . PHP_EOL;
    $wav = (new \Xiaosongshu\Flv2mp4\Manage\Pcm2Wav($pcmFile, $wavFile, $sampleRate, $channels))->run();
    echo "WAV: {$wav['output']} (" . filesize($wav['output']) . " bytes)" . PHP_EOL;
    echo sprintf("测试完成，时长 %.3f 秒，耗时 %.3f 秒。%s", $wav['duration'], microtime(true) - $start, PHP_EOL);
} catch (Throwable $e) {
    fwrite(STDERR, '转换失败: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

function extractPcm(string $input, string $output, int $sampleRate, int $channels): void
{
    $command = 'ffmpeg -y -i ' . escapeshellarg($input)
        . ' -vn -ar ' . $sampleRate . ' -ac ' . $channels
        . ' -f s16le ' . escapeshellarg($output) . ' 2>&1';
    exec($command, $lines, $code);
    if ($code !== 0 || !is_file($output) || filesize($output) === 0) {
        throw new RuntimeException("FFmpeg 提取 PCM 失败:\n" . implode(PHP_EOL, $lines));
    }
}
