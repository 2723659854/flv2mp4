<?php
/**
 * 解码器验证脚本（轻量版）
 * 提取 test1.flv 前3秒，PHP解码后由FFmpeg重新编码为H264，用ffplay播放
 *
 * 用法： php test_decoder_play.php
 */

require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '2048M');
use Xiaosongshu\Flv2mp4\Codec\H264Decoder;
use Xiaosongshu\Flv2mp4\Codec\NalUtil;

// ========== 配置 ==========
$flvFile = __DIR__ . '/test1.flv';
$width = 720;
$height = 742;
$frameCount = 90;   // 3秒 (30fps)
$h264File = __DIR__ . '/test1_3s.h264';
$phpYuv = __DIR__ . '/php_decoded_3s.yuv';
$outputMp4 = __DIR__ . '/php_decoded_3s.mp4';

// ========== 1. 提取前3秒H.264裸流 ==========
echo "=== 1. 提取前3秒H.264裸流 ===\n";
$cmd = sprintf(
    'ffmpeg -y -i %s -c:v copy -an -frames:v %d %s 2>&1',
    escapeshellarg($flvFile),
    $frameCount,
    escapeshellarg($h264File)
);
exec($cmd, $output, $ret);
if ($ret !== 0 || !file_exists($h264File) || filesize($h264File) === 0) {
    die("❌ 提取H.264失败。\n");
}
echo "✅ 提取成功: " . filesize($h264File) . " 字节\n\n";

// ========== 2. PHP解码器解码 ==========
echo "=== 2. PHP解码器解码 ===\n";
$h264Data = file_get_contents($h264File);
$nalUnits = NalUtil::splitNalUnits($h264Data);
echo "NAL单元数: " . count($nalUnits) . "\n";

$decoder = new H264Decoder();
$result = $decoder->decode($nalUnits);
if (!$result) {
    die("❌ PHP解码失败\n");
}
$decData = $result['data'];
file_put_contents($phpYuv, $decData);
$ySize = $width * $height;
$uvSize = (int)($ySize / 4);
$frameSize = $ySize + 2 * $uvSize;
$decFrames = (int)(strlen($decData) / $frameSize);
echo "PHP解码帧数: $decFrames\n";
echo "✅ YUV保存到: $phpYuv\n\n";

// ========== 3. FFmpeg编码为H264（用于播放验证） ==========
echo "=== 3. FFmpeg重新编码为H264 ===\n";
$cmd = sprintf(
    'ffmpeg -y -f rawvideo -pix_fmt yuv420p -s %dx%d -r 30 -i %s -c:v libx264 -qp 30 -an %s 2>&1',
    $width,
    $height,
    escapeshellarg($phpYuv),
    escapeshellarg($outputMp4)
);
exec($cmd, $output, $ret);
if ($ret !== 0 || !file_exists($outputMp4)) {
    die("❌ 重新编码失败\n");
}
echo "✅ MP4生成: $outputMp4\n\n";

// ========== 4. 播放 ==========
echo "=== 4. 播放（ffplay） ===\n";
echo "正在播放... 按ESC或Q退出\n";
echo "如果画面出现马赛克，说明PHP解码器有问题；如果清晰，则解码器正常。\n";
$cmd = sprintf('ffplay -i %s', escapeshellarg($outputMp4));
passthru($cmd);