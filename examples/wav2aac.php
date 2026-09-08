<?php

if (version_compare(PHP_VERSION, '8.1.0', '<')) {
    fwrite(STDERR, '错误：此脚本需要 PHP 8.1 或更高版本，当前版本为 ' . PHP_VERSION . PHP_EOL);
    exit(1);
}

require_once __DIR__ . '/vendor/autoload.php';

$inputFile = $argv[1] ?? __DIR__ . '/pcm2wav.wav';
$outputFile = $argv[2] ?? __DIR__ . '/wav2aac.aac';
$bitrate = (int) ($argv[3] ?? 128000);

if (in_array($inputFile, ['-h', '--help'], true)) {
    echo '用法：php843 .\\wav2aac.php [输入WAV] [输出AAC] [码率]' . PHP_EOL;
    echo '示例：php843 .\\wav2aac.php .\\pcm2wav.wav .\\wav2aac.aac 128000' . PHP_EOL;
    exit(0);
}

if (!is_file($inputFile)) {
    fwrite(STDERR, "输入 WAV 不存在: {$inputFile}" . PHP_EOL);
    exit(1);
}

try {
    $start = microtime(true);
    echo "使用纯 PHP 将 WAV 编码为 AAC-LC ADTS..." . PHP_EOL;
    $result = \Xiaosongshu\Flv2mp4\Client::runWav2Aac($inputFile, $outputFile, $bitrate);
    echo "AAC: {$result['output']} (" . filesize($result['output']) . " bytes)" . PHP_EOL;
    echo "参数: {$result['sampleRate']} Hz, {$result['channels']} 声道, {$result['bitrate']} bit/s" . PHP_EOL;
    echo "AAC 帧数: {$result['frames']}" . PHP_EOL;
    echo sprintf("时长: %.3f 秒，耗时: %.3f 秒。%s", $result['duration'], microtime(true) - $start, PHP_EOL);
} catch (Throwable $e) {
    fwrite(STDERR, '转换失败: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
