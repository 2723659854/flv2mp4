<?php

if (version_compare(PHP_VERSION, '8.1.0', '<')) {
    fwrite(STDERR, '错误：此脚本需要 PHP 8.1 或更高版本，当前版本为 ' . PHP_VERSION . PHP_EOL);
    exit(1);
}

require_once __DIR__ . '/vendor/autoload.php';

$inputFile = $argv[1] ?? __DIR__ . '/test_demo.mp4';
$outputFile = $argv[2] ?? __DIR__ . '/aac2wav.wav';

if (in_array($inputFile, ['-h', '--help'], true)) {
    echo '用法：php843 .\\aac2wav.php [输入MP4或FLV] [输出WAV]' . PHP_EOL;
    echo '示例：php843 .\\aac2wav.php .\\test_demo.mp4 .\\aac2wav.wav' . PHP_EOL;
    exit(0);
}

if (!is_file($inputFile)) {
    fwrite(STDERR, "输入文件不存在: {$inputFile}" . PHP_EOL);
    exit(1);
}

try {
    $start = microtime(true);
    echo "使用纯 PHP 从 MP4/FLV 提取 AAC-LC 并封装为 WAV..." . PHP_EOL;
    $result = \Xiaosongshu\Flv2mp4\Client::aac2wav($inputFile, $outputFile);
    echo "WAV: {$result['output']} (" . filesize($result['output']) . " bytes)" . PHP_EOL;
    echo "参数: {$result['sampleRate']} Hz, {$result['channels']} 声道, {$result['bitsPerSample']} bit" . PHP_EOL;
    echo "AAC 帧数: {$result['frames']}" . PHP_EOL;
    echo sprintf("时长: %.3f 秒，耗时: %.3f 秒。%s", $result['duration'], microtime(true) - $start, PHP_EOL);
} catch (Throwable $e) {
    fwrite(STDERR, '转换失败: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
