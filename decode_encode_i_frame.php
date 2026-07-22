<?php

require_once 'vendor/autoload.php';

use Xiaosongshu\Flv2mp4\Codec\H264Decoder;
use Xiaosongshu\Flv2mp4\Codec\H264Encoder;
use Xiaosongshu\Flv2mp4\Codec\NalUtil;
use Xiaosongshu\Flv2mp4\Codec\VideoScaler;

$inputH264 = 'test_extracted.h264';
$decodedYuv = 'decoded_i_frame.yuv';
$reencodedH264 = 'reencoded_i_frame.h264';

echo "=== Step 1: 准备输入H264文件 ===\n";
if (!file_exists($inputH264)) {
    echo "生成测试用H264 (全I帧)...\n";
    $cmd = sprintf(
        'ffmpeg -y -i test.mp4 -t 1 -c:v libx264 -profile:v baseline '
        . '-x264-params bframes=0:keyint=1:min-keyint=1:no-scenecut=1 '
        . '-preset veryfast -an -f h264 %s 2>&1',
        escapeshellarg($inputH264)
    );
    shell_exec($cmd);
}

if (!file_exists($inputH264) || filesize($inputH264) == 0) {
    die("ERROR: 输入H264文件不存在\n");
}
echo "输入文件: $inputH264 (" . filesize($inputH264) . " bytes)\n\n";

echo "=== Step 2: 拆分NAL单元，提取第一个IDR帧 ===\n";
$h264Data = file_get_contents($inputH264);
$nalUnits = NalUtil::splitNalUnits($h264Data);
echo "总NAL单元数量: " . count($nalUnits) . "\n";

$firstIDRNals = [];
$sps = null;
$pps = null;
$gotIDR = false;
foreach ($nalUnits as $nal) {
    if ($nal['type'] == 7 && $sps === null) {
        $sps = $nal;
        $firstIDRNals[] = $nal;
    } elseif ($nal['type'] == 8 && $pps === null) {
        $pps = $nal;
        $firstIDRNals[] = $nal;
    } elseif ($nal['type'] == 5 && !$gotIDR) {
        $firstIDRNals[] = $nal;
        $gotIDR = true;
        break;
    }
}

echo "第一个IDR帧NAL数: " . count($firstIDRNals) . "\n";
foreach ($firstIDRNals as $nal) {
    $names = [7=>'SPS', 8=>'PPS', 5=>'IDR'];
    $name = isset($names[$nal['type']]) ? $names[$nal['type']] : "type{$nal['type']}";
    echo "  $name: " . strlen($nal['raw']) . " bytes\n";
}
echo "\n";

echo "=== Step 3: 使用PHP解码器解码IDR帧 ===\n";
$decoder = new H264Decoder();
$result = $decoder->decode($firstIDRNals);

if (!$result) {
    die("ERROR: 解码失败\n");
}

echo "解码结果:\n";
echo "  宽度: " . $result['width'] . "\n";
echo "  高度: " . $result['height'] . "\n";
echo "  像素格式: " . $result['pix_fmt'] . "\n";
echo "  数据大小: " . strlen($result['data']) . " bytes\n";

$expectedSize = (int)($result['width'] * $result['height'] * 3 / 2);
echo "  预期大小(YUV420p一帧): $expectedSize bytes\n";

if (strlen($result['data']) >= $expectedSize) {
    $firstFrame = substr($result['data'], 0, $expectedSize);
    file_put_contents($decodedYuv, $firstFrame);
    echo "  已保存解码YUV到 $decodedYuv\n\n";
} else {
    die("ERROR: 解码数据大小不足\n");
}

echo "=== Step 4: 使用VideoScaler缩放YUV到640x360 ===\n";
$scaler = new VideoScaler();
$scaledYuv = $scaler->scaleYUV420P($firstFrame, $result['width'], $result['height'], 640, 360);
echo "  缩放前: {$result['width']}x{$result['height']} ({$expectedSize} bytes)\n";
echo "  缩放后: 640x360 (" . strlen($scaledYuv) . " bytes)\n\n";

echo "=== Step 5: 使用PHP编码器重新编码I帧 ===\n";
$width = 640;
$height = 360;
$yuvData = $scaledYuv;

$encoder = new H264Encoder($width, $height, 30);
$encoder->setQp(30);

$nalUnits = $encoder->encodeFrame($yuvData, true);
$h264Encoded = implode('', $nalUnits);
file_put_contents($reencodedH264, $h264Encoded);

echo "编码完成: " . strlen($h264Encoded) . " 字节\n";
echo "  NAL单元数: " . count($nalUnits) . "\n";
foreach ($nalUnits as $i => $nal) {
    $nalHeader = ord($nal[0]);
    $nalType = $nalHeader & 0x1F;
    $names = [7=>'SPS', 8=>'PPS', 5=>'IDR'];
    $name = isset($names[$nalType]) ? $names[$nalType] : "type{$nalType}";
    echo "    NAL[$i]: $name (" . strlen($nal) . " bytes)\n";
}
echo "\n";

echo "=== Step 6: 验证重新编码的H264 ===\n";
$output = shell_exec("ffmpeg -v error -i $reencodedH264 -f null - 2>&1");
if (empty($output)) {
    echo "RESULT: ✅ ffmpeg验证通过！\n";
} else {
    echo "RESULT: ❌ ffmpeg验证失败:\n$output\n";
    exit(1);
}

echo "\n生成的文件:\n";
echo "  1. $inputH264 - 原始H264输入\n";
echo "  2. $decodedYuv - PHP解码器输出的YUV\n";
echo "  3. $reencodedH264 - PHP编码器重新编码的H264\n";

echo "\n=== 生成可播放的MP4 ===\n";
shell_exec("ffmpeg -y -f h264 -i $reencodedH264 -c:v copy reencoded_i_frame.mp4 2>&1");
echo "生成: reencoded_i_frame.mp4\n";

echo "\n✅ 完成：PHP解码 -> VideoScaler缩放 -> PHP编码 (I帧) 流程测试成功！\n";
