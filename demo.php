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


echo "=== 示例1: flv静态文件切片fMP4并合并为mp4文件 ===\n";
$outputDir1 = __DIR__ . "/output_merge";
try{
    $res = \Xiaosongshu\Flv2mp4\Client::runFlv2Fmp4Mixed($file, $outputDir1);
    echo "\n转换完成: " . $res . "\n\n";

    // 验证m3u8文件
    $m3u8File = "$outputDir1/index.m3u8";
    if (file_exists($m3u8File)) {
        echo "m3u8索引文件内容:\n";
        echo "------------------\n";
        echo file_get_contents($m3u8File);
        echo "------------------\n\n";
    }
}catch (\Exception $e){
    echo "错误: " . $e->getMessage() . "\n\n";
}


echo "=== 示例2: 生成分开的音视频切片 ===\n";
$outputDir2 = __DIR__ . "/output_separate";
try{
    $res = \Xiaosongshu\Flv2mp4\Client::runFlv2Fmp4Separate($file, $outputDir2);
    echo "\n转换完成！生成的文件:\n";
    echo "  音频初始化: " . ($res['audioInit'] ?? '无') . "\n";
    echo "  视频初始化: " . ($res['videoInit'] ?? '无') . "\n";
    echo "  音频切片数量: " . count($res['audioSegments']) . "\n";
    echo "  视频切片数量: " . count($res['videoSegments']) . "\n";
    echo "  元数据文件: " . ($res['meta'] ?? '无') . "\n";
    echo "  音频m3u8: " . ($res['audioM3u8'] ?? '无') . "\n";
    echo "  视频m3u8: " . ($res['videoM3u8'] ?? '无') . "\n";
    echo "  主m3u8: " . ($res['masterM3u8'] ?? '无') . "\n";

    // 验证m3u8文件内容
    echo "\n主m3u8索引文件内容:\n";
    echo "------------------\n";
    if (file_exists($res['masterM3u8'])) {
        echo file_get_contents($res['masterM3u8']);
    } else {
        echo "文件不存在\n";
    }
    echo "------------------\n\n";

    if (!empty($res['audioM3u8']) && file_exists($res['audioM3u8'])) {
        echo "音频m3u8索引文件内容:\n";
        echo "------------------\n";
        echo file_get_contents($res['audioM3u8']);
        echo "------------------\n\n";
    }

    if (!empty($res['videoM3u8']) && file_exists($res['videoM3u8'])) {
        echo "视频m3u8索引文件内容:\n";
        echo "------------------\n";
        echo file_get_contents($res['videoM3u8']);
        echo "------------------\n";
    }
}catch (\Exception $e){
    echo "错误: " . $e->getMessage() . "\n";
}


