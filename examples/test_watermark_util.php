<?php
require_once __DIR__ . '/vendor/autoload.php';

use Xiaosongshu\Flv2mp4\Codec\WatermarkUtil;

echo "=== 测试 WatermarkUtil ===\n\n";

// 测试1：生成文字水印
echo "1. 生成文字水印 (xiaosongshu, 80x16)...\n";
$outputFile1 = __DIR__ . '/test_wm_text.yuv';
$start = microtime(true);
$result = WatermarkUtil::generateFromText(
    'xiaosongshu',
    $outputFile1,
    80,
    16,

    [
        'fontSize' => 5, // 内置字体大小 1-5
        'fontColor' => [255, 255, 255],
        'bgColor' => [0, 0, 0],
    ]
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
$pngFile = __DIR__ . '/test_wm_text.png';
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

// 检查白色像素数量
$wmData = file_get_contents($outputFile1);
$whiteCount = 0;
for ($i = 0; $i < 80 * 16; $i++) {
    if (ord($wmData[$i]) > 200) $whiteCount++;
}
echo "   白色像素(Y>200)数量: {$whiteCount}\n";

// 和已有的水印对比
$existingWm = __DIR__ . '/watermark_80x16.yuv';
if (file_exists($existingWm)) {
    echo "\n3. 与现有水印文件对比...\n";
    $existingData = file_get_contents($existingWm);
    $ySize = 80 * 16;
    $yMatch = 0;
    for ($i = 0; $i < $ySize; $i++) {
        if (ord($wmData[$i]) === ord($existingData[$i])) $yMatch++;
    }
    echo "   Y平面匹配: {$yMatch} / {$ySize} (" . round($yMatch / $ySize * 100, 1) . "%)\n";
    echo "   (注：字体不同导致差异是正常的，ffmpeg用的是系统字体)\n";
}

echo "\n=== 测试完成 ===\n";
