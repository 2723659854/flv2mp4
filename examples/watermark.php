<?php

require_once __DIR__ . '/vendor/autoload.php';

use Xiaosongshu\Flv2mp4\Codec\WatermarkUtil;

echo "=== 测试 WatermarkUtil ===\n\n";

// 测试1：生成文字水印
echo "1. 生成文字水印 (xiaosongshu, 80x16)...\n";
$outputFile1 = __DIR__ . '/test_wm_copy_80x16.yuv';
$start = microtime(true);
$result = WatermarkUtil::generateFromImage(
    __DIR__."/watermark_80x16.png",
    $outputFile1,
    80,
    16,
);
$cost = round(microtime(true) - $start, 3);
if ($result && file_exists($outputFile1)) {
    $size = filesize($outputFile1);
    $expectedSize = 80 * 16 + (80 * 16 >> 1);
    echo "   成功! 文件大小: {$size} 字节 (期望: {$expectedSize}) - 耗时: {$cost}s\n";
    if ($size === $expectedSize) {
        echo "   ✅ 文件尺寸正确\n";
    } else {
        echo "   ❌ 文件尺寸不匹配\n";
    }
} else {
    echo "   ❌ 生成失败\n";
}

// 用 FFmpeg 验证生成的 YUV
echo "\n2. FFmpeg 验证生成的 YUV...\n";
$pngFile = __DIR__ . '/test_wm_copy_80x16.png';
$cmd = sprintf(
    'ffmpeg -y -f rawvideo -pix_fmt yuv420p -s 80x16 -i %s -frames:v 1 %s 2>&1',
    escapeshellarg($outputFile1),
    escapeshellarg($pngFile)
);
shell_exec($cmd);
if (file_exists($pngFile) && filesize($pngFile) > 0) {
    echo "   ✅ FFmpeg 解码成功: $pngFile\n";
} else {
    echo "   ❌ FFmpeg 解码失败\n";
}