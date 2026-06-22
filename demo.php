<?php
// 检查PHP版本是否小于8.1
if (version_compare(PHP_VERSION, '8.1.0', '<')) {
    // 输出错误信息到标准错误（STDERR）
    fwrite(STDERR, "错误：此脚本需要PHP 8.1或更高版本，当前版本为 " . PHP_VERSION . "\n");
    // 退出脚本并返回错误码1（表示一般错误）
    exit(1);
}
require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '512M');

$file = __DIR__ . "/test.flv";

echo "\n === 示例3: 转换flv为hls === \n";
$outputDir1 = __DIR__ . "/hls";
try {
    $res = \Xiaosongshu\Flv2mp4\Client::runFlv2Hls($file, $outputDir1);
    echo "\n hls转换完成 index = {$res['index']} dir = {$res['outputDir']}\n\n";
} catch (\Exception $e) {
    echo "错误: " . $e->getMessage() . "\n\n";
}



