<?php
/**
 * P帧解码测试脚本 - 修复版
 * 使用PHP生成YUV测试图案，再用ffmpeg编码为Baseline H.264
 */
require_once 'vendor/autoload.php';

use Xiaosongshu\Flv2mp4\Codec\H264Decoder;
use Xiaosongshu\Flv2mp4\Codec\NalUtil;

$width = 320;
$height = 240;
$fps = 30;
$numTestFrames = 30;
$rawYuv = 'test_raw.yuv';
$h264File = 'test_pframe.h264';
$decodedYuv = 'test_decoded_pframe.yuv';
$refYuv = 'test_pframe_ref.yuv';

$ySize = $width * $height;
$uvSize = (int)($ySize / 4);
$frameSize = $ySize + 2 * $uvSize;

echo "=== Step 1: PHP生成 {$numTestFrames} 帧 YUV420p 测试图案 ===\n";
$yuvData = '';
for ($f = 0; $f < $numTestFrames; $f++) {
    $yPlane = str_repeat("\x00", $ySize);
    $uPlane = str_repeat("\x80", $uvSize);
    $vPlane = str_repeat("\x80", $uvSize);
    
    // 生成移动的方块（模拟运动，让P帧有残差）
    $boxSize = 64;
    $boxX = ($f * 20) % ($width - $boxSize);
    $boxY = ($f * 10) % ($height - $boxSize);
    for ($y = 0; $y < $boxSize; $y++) {
        for ($x = 0; $x < $boxSize; $x++) {
            $px = $boxX + $x;
            $py = $boxY + $y;
            if ($px < $width && $py < $height) {
                $val = 128 + (int)(127 * sin(($x + $y + $f * 5) * M_PI / 32));
                $yPlane[$py * $width + $px] = chr(max(0, min(255, $val)));
            }
        }
    }
    
    // 背景渐变
    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
            if ($yPlane[$y * $width + $x] === "\x00") {
                $val = (int)(($x * 255 + $y * 255) / ($width + $height));
                $yPlane[$y * $width + $x] = chr($val);
            }
        }
    }
    
    $yuvData .= $yPlane . $uPlane . $vPlane;
}
file_put_contents($rawYuv, $yuvData);
echo "生成原始YUV: " . strlen($yuvData) . " bytes (" . (int)(strlen($yuvData) / $frameSize) . " frames)\n\n";

$ffmpeg = 'D:\ffmpeg\bin\ffmpeg.exe';

echo "=== Step 2: ffmpeg 编码为 Baseline H.264 (I+P帧，无B帧) ===\n";
$cmd = sprintf(
    '"%s" -y -f rawvideo -pix_fmt yuv420p -s %dx%d -r %d -i %s '
    . '-c:v libx264 -profile:v baseline -level 3.0 '
    . '-x264-params bframes=0:keyint=30:min-keyint=30:scenecut=0:qp=20 '
    . '-preset veryfast -an -f h264 %s 2>&1',
    $ffmpeg,
    $width, $height, $fps,
    escapeshellarg($rawYuv),
    escapeshellarg($h264File)
);
$result = shell_exec($cmd);
echo $result;

if (!file_exists($h264File) || filesize($h264File) == 0) {
    die("ERROR: 编码H264失败\n");
}
echo "编码成功，大小: " . filesize($h264File) . " bytes\n\n";

echo "=== Step 3: ffmpeg 解码为参考YUV ===\n";
$cmd = sprintf(
    '"%s" -y -i %s -frames:v %d -f rawvideo -pix_fmt yuv420p %s 2>&1',
    $ffmpeg,
    escapeshellarg($h264File),
    $numTestFrames,
    escapeshellarg($refYuv)
);
$result = shell_exec($cmd);
echo $result;

if (!file_exists($refYuv) || filesize($refYuv) < $frameSize) {
    die("ERROR: 生成参考YUV失败\n");
}
$refData = file_get_contents($refYuv);
echo "参考YUV生成成功，大小: " . strlen($refData) . " bytes (" . (int)(strlen($refData) / $frameSize) . " frames)\n\n";

echo "=== Step 4: 使用PHP解码器解码 ===\n";
$h264Data = file_get_contents($h264File);
$nalUnits = NalUtil::splitNalUnits($h264Data);
echo "总NAL单元数量: " . count($nalUnits) . "\n";

$decoder = new H264Decoder();
$result = $decoder->decode($nalUnits);

if (!$result) {
    die("ERROR: PHP解码失败\n");
}

echo "解码结果:\n";
echo "  宽度: " . $result['width'] . "\n";
echo "  高度: " . $result['height'] . "\n";
echo "  像素格式: " . $result['pix_fmt'] . "\n";

$decData = $result['data'];
$actualFrameSize = (int)($result['width'] * $result['height'] * 3 / 2);
$numFrames = (int)(strlen($decData) / $actualFrameSize);
echo "  数据大小: " . strlen($decData) . " bytes\n";
echo "  解码帧数: $numFrames\n\n";

file_put_contents($decodedYuv, $decData);
echo "已保存到 $decodedYuv\n\n";

echo "=== Step 5: PSNR对比 ===\n";
$w = $result['width'];
$h = $result['height'];
$ySz = $w * $h;
$uvSz = (int)($ySz / 4);
$fSz = $ySz + 2 * $uvSz;

for ($i = 0; $i < min($numFrames, $numTestFrames); $i++) {
    $decFrame = substr($decData, $i * $fSz, $fSz);
    $refFrame = substr($refData, $i * $fSz, $fSz);

    if (strlen($decFrame) < $fSz || strlen($refFrame) < $fSz) break;

    $mseY = 0;
    $mseU = 0;
    $mseV = 0;
    $maxDiffY = 0;
    $diffCountY = 0;
    
    for ($j = 0; $j < $ySz; $j++) {
        $diff = ord($decFrame[$j]) - ord($refFrame[$j]);
        $absDiff = abs($diff);
        if ($absDiff > 0) $diffCountY++;
        if ($absDiff > $maxDiffY) $maxDiffY = $absDiff;
        $mseY += $diff * $diff;
    }
    for ($j = 0; $j < $uvSz; $j++) {
        $diff = ord($decFrame[$ySz + $j]) - ord($refFrame[$ySz + $j]);
        $mseU += $diff * $diff;
    }
    for ($j = 0; $j < $uvSz; $j++) {
        $diff = ord($decFrame[$ySz + $uvSz + $j]) - ord($refFrame[$ySz + $uvSz + $j]);
        $mseV += $diff * $diff;
    }
    
    $mseY /= $ySz;
    $mseU /= $uvSz;
    $mseV /= $uvSz;
    $psnrY = $mseY > 0 ? 10 * log10(255 * 255 / $mseY) : INF;
    $psnrU = $mseU > 0 ? 10 * log10(255 * 255 / $mseU) : INF;
    $psnrV = $mseV > 0 ? 10 * log10(255 * 255 / $mseV) : INF;
    $avgPsnr = ($psnrY + $psnrU + $psnrV) / 3;
    
    printf("Frame %d: Y=%.2fdB (diffs=%d/%d, maxDiff=%d), U=%.2fdB, V=%.2fdB, Avg=%.2fdB\n",
        $i, $psnrY, $diffCountY, $ySz, $maxDiffY, $psnrU, $psnrV, $avgPsnr);
}

echo "\n=== 完成 ===\n";
