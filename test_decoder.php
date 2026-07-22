<?php
/**
 * 解码器测试脚本
 * 思路：
 * 1. 用ffmpeg从test.mp4提取一小段h264裸流
 * 2. 取第一个IDR帧(SPS+PPS+IDR)
 * 3. 用H264Decoder解码为YUV
 * 4. 用ffmpeg将YUV重新编码为h264
 * 5. 验证新的h264能否正常播放
 */
require_once 'vendor/autoload.php';

use Xiaosongshu\Flv2mp4\Codec\H264Decoder;
use Xiaosongshu\Flv2mp4\Codec\NalUtil;

//$mp4File = 'test.mp4';
//$extractedH264 = 'test_extracted.h264';
//$decodedYuv = 'test_decoded.yuv';
//$reencodedH264 = 'test_reencoded.h264';
//
//echo "=== Step 1: 用ffmpeg从MP4提取H264裸流(前3秒) ===\n";
//$cmd = sprintf(
//    'ffmpeg -y -i %s -c:v copy -bsf:v h264_mp4toannexb -an -t 3 %s 2>&1',
//    escapeshellarg($mp4File),
//    escapeshellarg($extractedH264)
//);
//$result = shell_exec($cmd);

$mp4File = 'test.mp4';
$extractedH264 = 'test_extracted.h264';
$decodedYuv = 'test_decoded.yuv';
$reencodedH264 = 'test_reencoded.h264';

echo "=== Step 1: MP4转码 Baseline 全I帧裸H264(前3秒) ===\n";
$cmd = sprintf(
    'ffmpeg -y -i %s -t 3 -c:v libx264 -profile:v baseline '
    . '-x264-params bframes=0:keyint=1:min-keyint=1:no-scenecut=1 '
    . '-preset veryfast -an -f h264 %s 2>&1',
    escapeshellarg($mp4File),
    escapeshellarg($extractedH264)
);
$result = shell_exec($cmd);
echo $result;


if (!file_exists($extractedH264) || filesize($extractedH264) == 0) {
    die("ERROR: 提取H264失败\n");
}
echo "提取成功，大小: " . filesize($extractedH264) . " bytes\n\n";

echo "=== Step 2: 拆分NAL单元，取第一个IDR帧 ===\n";
$h264Data = file_get_contents($extractedH264);
$nalUnits = NalUtil::splitNalUnits($h264Data);
echo "总NAL单元数量: " . count($nalUnits) . "\n";

// 取第一个IDR帧（SPS + PPS + 第一个IDR slice）
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

echo "=== Step 3: 使用PHP解码器解码第一个IDR帧 ===\n";
$decoder = new H264Decoder();

// 不抑制调试输出，查看详细解码信息
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
    // 只取第一帧
    $firstFrame = substr($result['data'], 0, $expectedSize);
    file_put_contents($decodedYuv, $firstFrame);
    echo "  已保存第一帧到 $decodedYuv\n\n";
} else {
    die("ERROR: 解码数据大小不足\n");
}

//ffplay -f rawvideo -pix_fmt yuv420p -video_size 720x742 test_decoded.yuv

echo "=== Step 4: 用ffmpeg将YUV重新编码为H264 ===\n";
$cmd = sprintf(
    'ffmpeg -y -f rawvideo -pix_fmt %s -s %dx%d -i %s -c:v libx264 -profile baseline -preset ultrafast -qp 24 %s 2>&1',
    $result['pix_fmt'],
    $result['width'],
    $result['height'],
    escapeshellarg($decodedYuv),
    escapeshellarg($reencodedH264)
);
$result = shell_exec($cmd);

if (!file_exists($reencodedH264) || filesize($reencodedH264) == 0) {
    die("ERROR: 重新编码失败\n");
}
echo "重新编码成功，大小: " . filesize($reencodedH264) . " bytes\n\n";

echo "=== Step 5: 验证新H264能否正常解码 ===\n";
$cmd = sprintf('ffmpeg -v error -i %s -f null - 2>&1', escapeshellarg($reencodedH264));
$result = shell_exec($cmd);
$result = $result ?? '';

if (trim($result) === '') {
    echo "RESULT: ✅ 解码成功，无错误！解码器工作正常。\n\n";
} else {
    echo "RESULT: ❌ 解码有错误:\n";
    echo $result . "\n\n";
}

echo "=== Step 6: 用ffmpeg解码原始H264生成参考YUV ===\n";
$refYuv = 'ffmpeg_ref.yuv';
$cmd = sprintf(
    'ffmpeg -y -i %s -frames:v 1 -f rawvideo -pix_fmt yuv420p %s 2>&1',
    escapeshellarg($extractedH264),
    escapeshellarg($refYuv)
);
$result = shell_exec($cmd);

if (!file_exists($refYuv) || filesize($refYuv) == 0) {
    die("ERROR: 生成参考YUV失败\n");
}
echo "参考YUV生成成功，大小: " . filesize($refYuv) . " bytes\n\n";

echo "生成的文件:\n";
echo "  1. $extractedH264 - 从MP4提取的原始H264\n";
echo "  2. $decodedYuv - PHP解码器输出的YUV(第一帧)\n";
echo "  3. $reencodedH264 - 用ffmpeg重新编码的H264(验证用)\n";
echo "  4. $refYuv - ffmpeg解码的参考YUV(第一帧)\n";
