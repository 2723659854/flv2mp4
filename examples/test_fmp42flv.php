<?php
if (version_compare(PHP_VERSION, '8.1.0', '<')) {
    fwrite(STDERR, "错误：此脚本需要PHP 8.1或更高版本，当前版本为 " . PHP_VERSION . "\n");
    exit(1);
}
require_once __DIR__ . '/../vendor/autoload.php';
ini_set('memory_limit', '512M');

$flvFile = __DIR__ . "/../test.flv";

if (!file_exists($flvFile)) {
    echo "错误: 测试文件不存在 {$flvFile}\n";
    exit(1);
}

echo "=== 步骤1: 将FLV转换为fMP4切片 ===\n";
$outputDir = __DIR__ . "/output_fmp42flv_test";
if (!is_dir($outputDir)) mkdir($outputDir, 0777, true);
foreach (glob("$outputDir/*") as $file) {
    if (is_file($file)) unlink($file);
}

try {
    $res = \Xiaosongshu\Flv2mp4\Client::runFlv2Fmp4Mixed($flvFile, $outputDir);
    echo "FLV转fMP4完成\n";
} catch (\Exception $e) {
    echo "错误: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n=== 步骤2: 收集fMP4切片文件 ===\n";
$m3u8File = "$outputDir/index.m3u8";
$initFile = "$outputDir/init.mp4";
$segmentFiles = glob("$outputDir/segment_*.m4s");
sort($segmentFiles);

echo "  m3u8索引: " . (file_exists($m3u8File) ? "存在" : "不存在") . "\n";
echo "  init.mp4: " . (file_exists($initFile) ? "存在 (大小: " . filesize($initFile) . " bytes)" : "不存在") . "\n";
echo "  切片数量: " . count($segmentFiles) . "\n";
foreach ($segmentFiles as $seg) {
    echo "    - " . basename($seg) . " (大小: " . filesize($seg) . " bytes)\n";
}

echo "\n=== 步骤3: 将fMP4切片转换为FLV（使用m3u8索引）===\n";
$outputFlv = __DIR__ . "/output_from_fmp4.flv";
try {
    $res = \Xiaosongshu\Flv2mp4\Client::runFmp42Flv($m3u8File, $outputFlv);
    echo "fMP4转FLV完成: {$res}\n";
    echo "输出文件大小: " . filesize($outputFlv) . " bytes\n";

    $flvHeader = file_get_contents($outputFlv, false, null, 0, 13);
    $magic = substr($flvHeader, 0, 3);
    $version = ord($flvHeader[3]);
    $flags = ord($flvHeader[4]);
    $hasAudio = ($flags & 0x04) != 0;
    $hasVideo = ($flags & 0x01) != 0;
    echo "FLV头验证: magic=$magic, version=$version, hasAudio=$hasAudio, hasVideo=$hasVideo\n";

} catch (\Exception $e) {
    echo "错误: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n=== 步骤4: 验证输出FLV文件 ===\n";
if (file_exists($outputFlv)) {
    echo "输出文件: {$outputFlv}\n";
    echo "文件大小: " . filesize($outputFlv) . " bytes\n";
    echo "文件已成功生成！\n";
} else {
    echo "错误: 输出文件不存在\n";
    exit(1);
}

echo "\n测试完成！\n";
