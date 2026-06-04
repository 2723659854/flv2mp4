<?php
require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '512M');

// 需要转换的flv媒体文件
$file = __DIR__."/test.flv";

// 示例1: 使用原有方法合并转换为单个MP4
echo "=== 示例1: 合并转换为单个MP4 ===\n";
$outputDir1 = __DIR__."/output_merge";
try{
    $res = \Xiaosongshu\Flv2mp4\Client::runFlv2mp4($file, $outputDir1);
    echo "\n转换完成: " . $res . "\n\n";
}catch (\Exception $e){
    echo "错误: " . $e->getMessage() . "\n\n";
}

// 示例2: 生成分开的音视频切片（用于浏览器播放）
echo "=== 示例2: 生成分开的音视频切片 ===\n";
$outputDir2 = __DIR__."/output_separate";
try{
    $res = \Xiaosongshu\Flv2mp4\Client::runFlv2mp4Separate($file, $outputDir2);
    echo "\n转换完成！生成的文件:\n";
    echo "  音频初始化: " . ($res['audioInit'] ?? '无') . "\n";
    echo "  视频初始化: " . ($res['videoInit'] ?? '无') . "\n";
    echo "  音频切片数量: " . count($res['audioSegments']) . "\n";
    echo "  视频切片数量: " . count($res['videoSegments']) . "\n";
    echo "  元数据文件: " . ($res['meta'] ?? '无') . "\n";
}catch (\Exception $e){
    echo "错误: " . $e->getMessage() . "\n";
}

// mp4转码生成测试的flv文件 ffmpeg -i test.mp4 -c:v libx264 -c:a aac -f flv test.flv
// 检查切片是否错误的命令 ffmpeg -v trace -i hls\a\b\segment_1.ts -f null -


echo "\n === 示例3: 转换flv为hls === \n";
$outputDir1 = __DIR__ . "/hls";
try {
    $res = \Xiaosongshu\Flv2mp4\Client::runFlv2Hls($file, $outputDir1);
    echo "\n hls转换完成 index = {$res['index']} dir = {$res['outputDir']}\n\n";

    echo "\n === 示例4: 转换hls回flv === \n";
    $outputFlv = __DIR__ . "/output_from_hls.flv";
    try {
        $res2 = \Xiaosongshu\Flv2mp4\Client::runHls2Flv($res['index'], $outputFlv);
        echo "\n hls转flv完成: {$res2}\n\n";
    } catch (\Exception $e) {
        echo "错误: " . $e->getMessage() . "\n\n";
    }
} catch (\Exception $e) {
    echo "错误: " . $e->getMessage() . "\n\n";
}