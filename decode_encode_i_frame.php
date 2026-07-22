<?php

require_once 'vendor/autoload.php';

use Xiaosongshu\Flv2mp4\Codec\H264Decoder;
use Xiaosongshu\Flv2mp4\Codec\H264Encoder;
use Xiaosongshu\Flv2mp4\Codec\NalUtil;
use Xiaosongshu\Flv2mp4\Codec\VideoScaler;

$inputH264 = 'test_extracted.h264';
$decodedYuv = 'decoded_frames.yuv';
$reencodedH264 = 'reencoded_frames.h264';
$targetWidth = 640;
$targetHeight = 360;

echo "=== Step 1: 准备输入H264文件 (多帧全I帧) ===\n";
if (!file_exists($inputH264)) {
    echo "生成测试用H264 (5秒全I帧)...\n";
    $cmd = sprintf(
        'ffmpeg -y -i test.mp4 -t 5 -c:v libx264 -profile:v baseline '
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

echo "=== Step 2: 拆分NAL单元，按帧分组 ===\n";
$h264Data = file_get_contents($inputH264);
$nalUnits = NalUtil::splitNalUnits($h264Data);
echo "总NAL单元数量: " . count($nalUnits) . "\n";

$frames = [];
$currentFrameNals = [];
$sps = null;
$pps = null;

foreach ($nalUnits as $nal) {
    if ($nal['type'] == 7) {
        $sps = $nal;
    } elseif ($nal['type'] == 8) {
        $pps = $nal;
    } elseif ($nal['type'] == 5) {
        if (!empty($currentFrameNals)) {
            $frames[] = $currentFrameNals;
        }
        $currentFrameNals = [];
        if ($sps !== null) {
            $currentFrameNals[] = $sps;
        }
        if ($pps !== null) {
            $currentFrameNals[] = $pps;
        }
        $currentFrameNals[] = $nal;
    }
}

if (!empty($currentFrameNals)) {
    $frames[] = $currentFrameNals;
}

echo "总帧数: " . count($frames) . "\n";
echo "\n";

echo "=== Step 3: 初始化解码器、缩放器、编码器 ===\n";
$decoder = new H264Decoder();
$scaler = new VideoScaler();
$encoder = new H264Encoder($targetWidth, $targetHeight, 30);
$encoder->setQp(30);

$allEncodedNals = [];
$decodedYuvData = '';
$firstFrame = true;
$srcWidth = 0;
$srcHeight = 0;

echo "=== Step 4: 循环解码 -> 缩放 -> 编码 ===\n";
foreach ($frames as $frameIdx => $frameNals) {
    echo "\n--- 处理第 " . ($frameIdx + 1) . " 帧 ---\n";
    
    echo "  解码帧...\n";
    $result = $decoder->decode($frameNals);
    
    if (!$result) {
        echo "  ERROR: 解码失败\n";
        continue;
    }
    
    if ($firstFrame) {
        $srcWidth = $result['width'];
        $srcHeight = $result['height'];
        echo "  源尺寸: {$srcWidth}x{$srcHeight}\n";
        $firstFrame = false;
    }
    
    $expectedSize = (int)($result['width'] * $result['height'] * 3 / 2);
    $frameYuv = substr($result['data'], 0, $expectedSize);
    $decodedYuvData .= $frameYuv;
    
    echo "  缩放帧 ({$result['width']}x{$result['height']} -> {$targetWidth}x{$targetHeight})...\n";
    $scaledYuv = $scaler->scaleYUV420P($frameYuv, $result['width'], $result['height'], $targetWidth, $targetHeight);
    
    echo "  编码帧 (关键帧)...\n";
    $encodedNals = $encoder->encodeFrame($scaledYuv, true);
    $allEncodedNals = array_merge($allEncodedNals, $encodedNals);
    
    $frameSize = strlen(implode('', $encodedNals));
    echo "  帧编码大小: {$frameSize} bytes, NAL数: " . count($encodedNals) . "\n";
}

file_put_contents($decodedYuv, $decodedYuvData);
$h264Encoded = implode('', $allEncodedNals);
file_put_contents($reencodedH264, $h264Encoded);

echo "\n=== Step 5: 验证重新编码的H264 ===\n";
echo "总编码大小: " . strlen($h264Encoded) . " bytes\n";
echo "总NAL单元数: " . count($allEncodedNals) . "\n";

$output = shell_exec("ffmpeg -v error -i $reencodedH264 -f null - 2>&1");
if (empty($output)) {
    echo "RESULT: ✅ ffmpeg验证通过！\n";
} else {
    echo "RESULT: ❌ ffmpeg验证失败:\n$output\n";
    exit(1);
}

echo "\n生成的文件:\n";
echo "  1. $inputH264 - 原始H264输入\n";
echo "  2. $decodedYuv - PHP解码器输出的YUV ({$srcWidth}x{$srcHeight})\n";
echo "  3. $reencodedH264 - PHP编码器重新编码的H264 ({$targetWidth}x{$targetHeight})\n";

echo "\n=== 生成可播放的MP4 ===\n";
shell_exec("ffmpeg -y -f h264 -i $reencodedH264 -c:v copy reencoded_frames.mp4 2>&1");
echo "生成: reencoded_frames.mp4\n";

echo "\n✅ 完成：PHP多帧解码 -> VideoScaler缩放 -> PHP编码 流程测试成功！\n";
echo "   处理帧数: " . count($frames) . "\n";
echo "   源尺寸: {$srcWidth}x{$srcHeight}\n";
echo "   目标尺寸: {$targetWidth}x{$targetHeight}\n";
