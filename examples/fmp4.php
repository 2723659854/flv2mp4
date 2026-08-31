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

$file = __DIR__ . "/17.flv";
$outputDir1 = __DIR__ . "/output_merge";
$res = \Xiaosongshu\Flv2mp4\Client::runFlv2Fmp4Mixed($file, $outputDir1);

$outputDir2 = __DIR__ . "/output_separate";
$res = \Xiaosongshu\Flv2mp4\Client::runFlv2Fmp4Separate($file, $outputDir2);

echo "\n=== 步骤3: 将fMP4切片转换为FLV（使用m3u8索引）===\n";

try {
    $res = \Xiaosongshu\Flv2mp4\Client::runFmp42Flv(__DIR__."/output_merge/index.m3u8", __DIR__ . "/003.flv");
    $res = \Xiaosongshu\Flv2mp4\Client::runFmp42Flv(__DIR__."/output_separate/index.m3u8", __DIR__ . "/004.flv");
    echo "fMP4转FLV完成: {$res}\n";
} catch (\Exception $e) {
    echo "错误: " . $e->getMessage() . "\n";
    exit(1);
}


$file = __DIR__ . "/demo.mp4";
$outputDir1 = __DIR__ . "/mp4_fmp4";
$res = \Xiaosongshu\Flv2mp4\Client::runMp42Fmp4($file, $outputDir1);
var_dump("mp4生成fmp4的hls切片",$res);


$res = \Xiaosongshu\Flv2mp4\Client::runFmp42Mp4(__DIR__."/mp4_fmp4/index.m3u8", __DIR__ . "/005.MP4");
echo "fMP4转MP4完成: {$res}\n";