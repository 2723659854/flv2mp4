<?php
/**
 * B帧解码测试脚本（CAVLC main profile）
 * 使用ffmpeg testsrc2 编码 I+P+B 帧流，PHP解码后与ffmpeg参考YUV逐帧PSNR对比
 */
require_once 'vendor/autoload.php';
ini_set('memory_limit', '2048M');

use Xiaosongshu\Flv2mp4\Codec\H264Decoder;
use Xiaosongshu\Flv2mp4\Codec\NalUtil;

$h264File = 'test_bframe.h264';
$refYuv = 'test_bframe_ref.yuv';
$decodedYuv = 'test_decoded_bframe.yuv';
$srcFlv = 'test_high.flv';

// 用法：php test_bframe_decode.php [extract]
// extract 子命令：使用 ffmpeg 从 test_high.flv 提取测试素材（H.264 裸流 + 参考 YUV）
if (($argv[1] ?? '') === 'extract') {
    if (!file_exists($srcFlv)) {
        die("ERROR: 缺少源文件 {$srcFlv}\n");
    }
    echo "=== 使用 ffmpeg 从 {$srcFlv} 提取素材 ===\n";
    // 提取视频裸流（Annex B 格式）
    $cmd = 'ffmpeg -y -v error -i ' . escapeshellarg($srcFlv)
        . ' -map 0:v:0 -c copy -bsf:v h264_mp4toannexb -f h264 ' . escapeshellarg($h264File) . ' 2>&1';
    exec($cmd, $out, $ret);
    if ($ret !== 0) {
        die("ERROR: 提取 H.264 裸流失败\n" . implode("\n", $out) . "\n");
    }
    // 解码生成参考 YUV（PHP 解码结果的对照基准）
    $cmd = 'ffmpeg -y -v error -i ' . escapeshellarg($srcFlv)
        . ' -map 0:v:0 -f rawvideo -pix_fmt yuv420p ' . escapeshellarg($refYuv) . ' 2>&1';
    exec($cmd, $out, $ret);
    if ($ret !== 0) {
        die("ERROR: 生成参考 YUV 失败\n" . implode("\n", $out) . "\n");
    }
    echo "素材提取完成: {$h264File}, {$refYuv}\n\n";
}

if (!file_exists($h264File) || !file_exists($refYuv)) {
    die("ERROR: 缺少测试流，请先运行: php test_bframe_decode.php extract\n");
}

$refData = file_get_contents($refYuv);

echo "=== 使用PHP解码器解码 B 帧流 ===\n";
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

echo "=== PSNR对比（显示序） ===\n";
$w = $result['width'];
$h = $result['height'];
$ySz = $w * $h;
$uvSz = (int)($ySz / 4);
$fSz = $ySz + 2 * $uvSz;
$refFrames = (int)(strlen($refData) / $fSz);
$total = min($numFrames, $refFrames);

$sumY = 0.0;
$badFrames = [];
for ($i = 0; $i < $total; $i++) {
    $decFrame = substr($decData, $i * $fSz, $fSz);
    $refFrame = substr($refData, $i * $fSz, $fSz);
    if (strlen($decFrame) < $fSz || strlen($refFrame) < $fSz) break;

    $mseY = 0;
    $mseU = 0;
    $mseV = 0;
    $diffCountY = 0;
    $maxDiffY = 0;

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
    $sumY += $psnrY;

    printf("Frame %2d: Y=%.2fdB (diffs=%d/%d, maxDiff=%d), U=%.2fdB, V=%.2fdB, Avg=%.2fdB\n",
        $i, $psnrY, $diffCountY, $ySz, $maxDiffY, $psnrU, $psnrV, $avgPsnr);

    if ($psnrY < 35.0) {
        $badFrames[] = $i;
    }
}

echo "\n平均 Y PSNR: " . sprintf("%.2f dB", $sumY / max(1, $total)) . "\n";
if (!empty($badFrames)) {
    echo "Y PSNR < 35dB 的帧: " . implode(', ', $badFrames) . "\n";
} else {
    echo "所有帧 Y PSNR >= 35dB\n";
}

echo "\n=== 完成 ===\n";
