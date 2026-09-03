<?php

if (version_compare(PHP_VERSION, '8.1.0', '<')) {
    fwrite(STDERR, '此脚本需要 PHP 8.1 或更高版本' . PHP_EOL);
    exit(1);
}
ini_set('memory_limit', '2048M');
ini_set('display_errors', '1');
error_reporting(E_ALL);

echo "AAC2MP3 测试开始" . PHP_EOL;

$autoload = __DIR__ . '/vendor/autoload.php';
if (!is_file($autoload)) {
    throw new RuntimeException("Composer 自动加载文件不存在: {$autoload}");
}
require_once $autoload;

$inputFile = $argv[1] ?? __DIR__ . '/test_demo.mp4';
$format = strtolower($argv[2] ?? 'mp3');
$outputFile = $argv[3] ?? (__DIR__ . '/aac2mp3_test.' . $format);

try {
    $converter = new \Xiaosongshu\Flv2mp4\Manage\AAC2MP3();
    $result = $converter->process($inputFile, $outputFile, $format);

    echo '转换成功' . PHP_EOL;
    echo '输入: ' . $inputFile . PHP_EOL;
    echo '输出: ' . $result['output'] . PHP_EOL;
    echo '格式: ' . $result['format'] . PHP_EOL;
    echo '采样率: ' . $result['sampleRate'] . ' Hz' . PHP_EOL;
    echo '声道: ' . $result['channels'] . PHP_EOL;
    echo 'AAC帧数: ' . $result['frames'] . PHP_EOL;
    echo '文件大小: ' . $result['bytes'] . ' bytes' . PHP_EOL;
} catch (\Exception $e) {
    var_dump($e->getMessage(),$e->getFile(),$e->getLine());

    exit(1);
}
