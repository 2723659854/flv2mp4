<?php

if (version_compare(PHP_VERSION, '8.1.0', '<')) {
    fwrite(STDERR, '错误：此脚本需要 PHP 8.1 或更高版本，当前版本为 ' . PHP_VERSION . PHP_EOL);
    exit(1);
}

require_once __DIR__ . '/vendor/autoload.php';

$inputFile = $argv[1] ?? __DIR__ . '/test_demo.mp4';
$inputFile = $argv[1] ?? __DIR__ . '/aac_recode_source.aac';
$outputFile = $argv[2] ?? __DIR__ . '/aac2mp3.mp3';
$format = 'mp3';

if (in_array($inputFile, ['-h', '--help'], true)) {
    echo '用法：php843 .\\aac2mp3.php [输入AAC/MP4/FLV] [输出MP3]' . PHP_EOL;
    echo '示例：php843 .\\aac2mp3.php .\\input.aac .\\aac2mp3.mp3' . PHP_EOL;
    exit(0);
}

if (!is_file($inputFile)) {
    fwrite(STDERR, "输入文件不存在: {$inputFile}" . PHP_EOL);
    exit(1);
}

try {
    $start = microtime(true);
    echo "使用纯 PHP 将 AAC-LC 音频编码为 MP3..." . PHP_EOL;
    $result = \Xiaosongshu\Flv2mp4\Client::runAac2Mp3(
        $inputFile,
        $outputFile,
        $format
    );
    echo "MP3: {$result['output']} (" . filesize($result['output']) . " bytes)" . PHP_EOL;
    echo "参数: {$result['sampleRate']} Hz, {$result['channels']} 声道" . PHP_EOL;
    echo "AAC 帧数: {$result['frames']}" . PHP_EOL;
    echo sprintf("耗时: %.3f 秒。%s", microtime(true) - $start, PHP_EOL);
} catch (Throwable $e) {
    fwrite(STDERR, '转换失败: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
