<?php
require_once 'vendor/autoload.php';

use Xiaosongshu\Flv2mp4\Codec\H264Encoder;

$width = 320;
$height = 240;
$ySize = $width * $height;
$uvSize = (int)($ySize / 4);

echo "=== 测试H264编码器多种模式 ===\n\n";

$yuvData = '';
for ($y = 0; $y < $height; $y++) {
    for ($x = 0; $x < $width; $x++) {
        $yuvData .= chr((($x + $y) * 2) & 0xFF);
    }
}
for ($y = 0; $y < $height / 2; $y++) {
    for ($x = 0; $x < $width / 2; $x++) {
        $yuvData .= chr(128);
    }
}
for ($y = 0; $y < $height / 2; $y++) {
    for ($x = 0; $x < $width / 2; $x++) {
        $yuvData .= chr(128);
    }
}

echo "=== 测试I_16x16模式 ===\n";
$encoder = new H264Encoder($width, $height);
$encoder->setMbType(H264Encoder::MB_TYPE_I16x16);
$encoder->setQp(28);
$nals16x16 = $encoder->encodeFrame($yuvData, true);
$totalSize16x16 = 0;
foreach ($nals16x16 as $nal) {
    $totalSize16x16 += strlen($nal);
}
echo "I_16x16模式输出大小: $totalSize16x16 bytes (SPS+PPS+IDR)\n";

file_put_contents('test_i16x16.h264', implode('', $nals16x16));
echo "已保存到 test_i16x16.h264\n";

echo "\n=== 测试I_4x4模式 ===\n";
$encoder = new H264Encoder($width, $height);
$encoder->setMbType(H264Encoder::MB_TYPE_I4x4);
$encoder->setQp(28);
$nals4x4 = $encoder->encodeFrame($yuvData, true);

echo "\n=== 检查编码的宏块数据 ===\n";
$sliceNal = $nals4x4[2];
$rbspData = substr($sliceNal, 1);
$rbspClean = '';
$zeroCount = 0;
for ($i = 0; $i < strlen($rbspData); $i++) {
    $byte = ord($rbspData[$i]);
    if ($zeroCount >= 2 && $byte == 0x03) {
        $zeroCount = 0;
        continue;
    }
    $rbspClean .= chr($byte);
    if ($byte == 0) {
        $zeroCount++;
    } else {
        $zeroCount = 0;
    }
}
echo "Slice RBSP大小: " . strlen($rbspClean) . " bytes\n";

$bits = '';
foreach (str_split($rbspClean) as $byte) {
    $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
}
echo "Slice比特数: " . strlen($bits) . "\n";
echo "前100个比特: " . substr($bits, 0, 100) . "\n";
$totalSize4x4 = 0;
foreach ($nals4x4 as $nal) {
    $totalSize4x4 += strlen($nal);
}
echo "I_4x4模式输出大小: $totalSize4x4 bytes (SPS+PPS+IDR)\n";

file_put_contents('test_i4x4.h264', implode('', $nals4x4));
echo "已保存到 test_i4x4.h264\n";

echo "\n=== 验证编码输出 ===\n";

$cmd = 'ffmpeg -v error -i test_i16x16.h264 -f null - 2>&1';
$result = shell_exec($cmd);
if ($result==null || trim($result) === '') {
    echo "I_16x16: ✅ ffmpeg解码成功\n";
} else {
    echo "I_16x16: ❌ ffmpeg解码失败: $result\n";
}

$cmd = 'ffmpeg -v error -i test_i4x4.h264 -f null - 2>&1';
$result = shell_exec($cmd);
if (trim($result) === '') {
    echo "I_4x4: ✅ ffmpeg解码成功\n";
} else {
    echo "I_4x4: ❌ ffmpeg解码失败: $result\n";
}

echo "\n=== 对比两种模式的编码质量 ===\n";
$cmd = 'ffmpeg -i test_i16x16.h264 -frames:v 1 -f rawvideo -pix_fmt yuv420p test_i16x16_decoded.yuv 2>&1';
shell_exec($cmd);
$cmd = 'ffmpeg -i test_i4x4.h264 -frames:v 1 -f rawvideo -pix_fmt yuv420p test_i4x4_decoded.yuv 2>&1';
shell_exec($cmd);

echo "I_16x16解码后YUV大小: " . filesize('test_i16x16_decoded.yuv') . " bytes\n";
echo "I_4x4解码后YUV大小: " . filesize('test_i4x4_decoded.yuv') . " bytes\n";

echo "\n测试完成！\n";
