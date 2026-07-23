<?php
/**
 * P帧解码测试脚本
 * 测试 baseline profile P 帧解码
 */
require_once 'vendor/autoload.php';

use Xiaosongshu\Flv2mp4\Codec\H264Decoder;
use Xiaosongshu\Flv2mp4\Codec\NalUtil;

$mp4File = 'test.mp4';
$extractedH264 = 'test_pframe.h264';
$decodedYuv = 'test_decoded_pframe.yuv';
$refYuv = 'test_pframe_ref.yuv';

// 用 ffmpeg 生成 baseline profile 的测试序列（I+P帧，无B帧）
echo "=== Step 1: 生成 Baseline profile 测试序列 (前5帧) ===\n";
$cmd = sprintf(
    'ffmpeg -y -f lavfi -i testsrc=duration=1:size=320x240:rate=30 -pix_fmt yuv420p '
    . '-c:v libx264 -profile:v baseline '
    . '-x264-params bframes=0:keyint=30:min-keyint=30 '
    . '-preset veryfast -an -f h264 %s 2>&1',
    escapeshellarg($extractedH264)
);
$result = shell_exec($cmd);
echo $result . "\n";

if (!file_exists($extractedH264) || filesize($extractedH264) == 0) {
    die("ERROR: 生成H264失败\n");
}
echo "生成成功，大小: " . filesize($extractedH264) . " bytes\n\n";

// 生成参考YUV
echo "=== Step 2: 生成FFmpeg参考YUV ===\n";
$cmd = sprintf(
    'ffmpeg -y -i %s -frames:v 5 -f rawvideo -pix_fmt yuv420p %s 2>&1',
    escapeshellarg($extractedH264),
    escapeshellarg($refYuv)
);
$result = shell_exec($cmd);
echo $result . "\n";

if (!file_exists($refYuv) || filesize($refYuv) == 0) {
    die("ERROR: 生成参考YUV失败\n");
}
echo "参考YUV生成成功，大小: " . filesize($refYuv) . " bytes\n\n";

// 使用PHP解码器解码
echo "=== Step 3: 使用PHP解码器解码 ===\n";
$h264Data = file_get_contents($extractedH264);
$nalUnits = NalUtil::splitNalUnits($h264Data);
echo "总NAL单元数量: " . count($nalUnits) . "\n";

$decoder = new H264Decoder();
// 添加宏块统计
$decoder->enableMbStats = true;
$result = $decoder->decode($nalUnits);

if (!$result) {
    die("ERROR: 解码失败\n");
}

echo "解码结果:\n";
echo "  宽度: " . $result['width'] . "\n";
echo "  高度: " . $result['height'] . "\n";
echo "  像素格式: " . $result['pix_fmt'] . "\n";
echo "  数据大小: " . strlen($result['data']) . " bytes\n";

$frameSize = (int)($result['width'] * $result['height'] * 3 / 2);
$numFrames = (int)(strlen($result['data']) / $frameSize);
echo "  解码帧数: $numFrames\n\n";

// 保存解码结果
file_put_contents($decodedYuv, $result['data']);
echo "已保存到 $decodedYuv\n\n";

// 计算每帧的PSNR
echo "=== Step 4: PSNR对比 ===\n";
$width = $result['width'];
$height = $result['height'];
$ySize = $width * $height;
$uvSize = (int)($ySize / 4);
$frameSize = $ySize + 2 * $uvSize;

$decData = file_get_contents($decodedYuv);
$refData = file_get_contents($refYuv);

for ($i = 0; $i < min($numFrames, 5); $i++) {
    $decFrame = substr($decData, $i * $frameSize, $frameSize);
    $refFrame = substr($refData, $i * $frameSize, $frameSize);

    if (strlen($decFrame) < $frameSize || strlen($refFrame) < $frameSize) break;

    // Y PSNR
    $mseY = 0;
    for ($j = 0; $j < $ySize; $j++) {
        $diff = ord($decFrame[$j]) - ord($refFrame[$j]);
        $mseY += $diff * $diff;
    }
    $mseY /= $ySize;
    $psnrY = $mseY > 0 ? 10 * log10(255 * 255 / $mseY) : INF;

    // U PSNR
    $mseU = 0;
    for ($j = 0; $j < $uvSize; $j++) {
        $diff = ord($decFrame[$ySize + $j]) - ord($refFrame[$ySize + $j]);
        $mseU += $diff * $diff;
    }
    $mseU /= $uvSize;
    $psnrU = $mseU > 0 ? 10 * log10(255 * 255 / $mseU) : INF;

    // V PSNR
    $mseV = 0;
    for ($j = 0; $j < $uvSize; $j++) {
        $diff = ord($decFrame[$ySize + $uvSize + $j]) - ord($refFrame[$ySize + $uvSize + $j]);
        $mseV += $diff * $diff;
    }
    $mseV /= $uvSize;
    $psnrV = $mseV > 0 ? 10 * log10(255 * 255 / $mseV) : INF;

    $avgPsnr = ($psnrY + $psnrU + $psnrV) / 3;
    printf("Frame %d: Y=%.2fdB, U=%.2fdB, V=%.2fdB, Avg=%.2fdB\n", $i, $psnrY, $psnrU, $psnrV, $avgPsnr);
}


echo "\n=== Step 5: 将解码YUV重新编码为H.264 ===\n";

$reencodedH264 = 'test_decoded_pframe_reencoded.h264';
$width  = $result['width'];
$height = $result['height'];
// 假设帧率为 25 fps（可根据实际源视频调整，或从 ffprobe 获取）
$fps = 30;

$cmd = sprintf(
    'ffmpeg -y -f rawvideo -pix_fmt yuv420p -s %dx%d -r %d -i %s -c:v libx264 -preset veryfast %s 2>&1',
    $width,
    $height,
    $fps,
    escapeshellarg($decodedYuv),
    escapeshellarg($reencodedH264)
);
$reencodeResult = shell_exec($cmd);
//echo $reencodeResult . "\n";

if (file_exists($reencodedH264) && filesize($reencodedH264) > 0) {
    echo "重新编码成功，文件: $reencodedH264 (" . filesize($reencodedH264) . " bytes)\n";
    echo "可以使用 ffplay -i $reencodedH264 播放\n";
} else {
    echo "重新编码失败\n";
}