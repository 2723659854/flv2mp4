<?php
/**
 * B 帧编码 round-trip 验证（IBBP，main profile）
 * - 本编码器按解码序 I P B B 输出，B 帧非参考（nal_ref_idc=0）
 * - 校验项：ffprobe profile、ffmpeg 解码显示序 PSNR、项目解码器解码显示序 PSNR
 */
require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '2048M');

use Xiaosongshu\Flv2mp4\Codec\H264Encoder;
use Xiaosongshu\Flv2mp4\Codec\H264Decoder;
use Xiaosongshu\Flv2mp4\Codec\NalUtil;

$prefix = sys_get_temp_dir() . '/btest_' . getmypid();
$h264File = $prefix . '.h264';
$decodedYuv = $prefix . '_ff.yuv';

function generateMotionYUV(int $width, int $height, int $frameIdx, int $motionX = 2, int $motionY = 1): string
{
    $yuv = str_repeat("\x80", (int)($width * $height * 3 / 2));
    $offsetX = $frameIdx * $motionX;
    $offsetY = $frameIdx * $motionY;
    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
            $val = 128 + (int)(sin(($x + $offsetX) * 0.05) * 40) + (int)(cos(($y + $offsetY) * 0.05) * 40);
            $yuv[$y * $width + $x] = chr(max(16, min(235, $val)));
        }
    }
    return $yuv;
}

$width = (int)($argv[1] ?? 320);
$height = (int)($argv[2] ?? 240);
$qp = 30;
$frameCount = (int)($argv[3] ?? 31); // 显示序：0=I, 1..2=B, 3=P, 4..5=B, 6=P, ...（锚点间隔 3）

$frames = [];
$originals = '';
for ($f = 0; $f < $frameCount; $f++) {
    $yuv = generateMotionYUV($width, $height, $f);
    $frames[$f] = $yuv;
    $originals .= $yuv;
}
$frameSize = (int)($width * $height * 3 / 2);

$encoder = new H264Encoder($width, $height, 25, 1000000);
$encoder->setQp($qp);
$encoder->enableInter = true;
$encoder->setProfile('main');
$encoder->enableBFrames(true);
$encoder->setFastMotion(true);

$nals = [];
// I0
$nals = array_merge($nals, $encoder->encodeFrame($frames[0], true));
// 锚点在显示序 3,6,9,...；每个锚点前快照 L0，锚点后编其前两个显示序的 B 帧
for ($anchor = 3; $anchor < $frameCount; $anchor += 3) {
    $encoder->snapshotBRefL0();                 // L0 = 上一锚点（此刻 refYPlane）
    $encoder->poc = 2 * $anchor;                // 驱动层按显示序给锚点 POC
    $nals = array_merge($nals, $encoder->encodeFrame($frames[$anchor], false)); // L1 = 本锚点
    $nals = array_merge($nals, $encoder->encodeBFrame($frames[$anchor - 2], 2 * ($anchor - 2)));
    $nals = array_merge($nals, $encoder->encodeBFrame($frames[$anchor - 1], 2 * ($anchor - 1)));
}

$h264Data = implode('', $nals);
file_put_contents($h264File, $h264Data);
echo '编码完成: ' . count($nals) . " 个 NAL, " . strlen($h264Data) . " 字节\n";

echo "\n=== ffprobe profile ===\n";
$probe = shell_exec('ffprobe -v error -select_streams v:0 -show_entries stream=profile,level,nb_frames -of default=nw=1 ' . escapeshellarg($h264File) . ' 2>&1');
echo $probe;

echo "\n=== ffmpeg 解码（显示序）PSNR ===\n";
$out = [];
$ret = 0;
exec('ffmpeg -y -v error -i ' . escapeshellarg($h264File) . ' -f rawvideo -pix_fmt yuv420p ' . escapeshellarg($decodedYuv) . ' 2>&1', $out, $ret);
if ($ret !== 0) {
    echo "ffmpeg 解码失败:\n" . implode("\n", $out) . "\n";
} else {
    comparePsnr(file_get_contents($decodedYuv), $originals, $width, $height, $frameCount, 'ffmpeg');
}

echo "\n=== 项目解码器解码（显示序）PSNR ===\n";
$nalUnits = NalUtil::splitNalUnits($h264Data);
$decoder = new H264Decoder();
$result = $decoder->decode($nalUnits);
$decData = $result['data'] ?? '';
foreach ($decoder->flushOutput() as $f) $decData .= $f['data'];
echo '  解码输出: ' . strlen($decData) . ' 字节 (' . (int)(strlen($decData) / $frameSize) . " 帧)\n";
comparePsnr($decData, $originals, $width, $height, $frameCount, 'php');

@unlink($h264File);
@unlink($decodedYuv);

function comparePsnr(string $decData, string $refData, int $w, int $h, int $frameCount, string $tag): void
{
    $ySize = $w * $h;
    $uvSize = (int)($ySize / 4);
    $fSz = $ySize + 2 * $uvSize;
    $total = min($frameCount, (int)(strlen($decData) / $fSz));
    $sumY = 0.0;
    $worst = null;
    for ($i = 0; $i < $total; $i++) {
        $dec = substr($decData, $i * $fSz, $fSz);
        $ref = substr($refData, $i * $fSz, $fSz);
        if (strlen($dec) < $fSz || strlen($ref) < $fSz) break;
        $mseY = 0;
        $mseU = 0;
        $mseV = 0;
        for ($j = 0; $j < $ySize; $j++) {
            $d = ord($dec[$j]) - ord($ref[$j]);
            $mseY += $d * $d;
        }
        for ($j = 0; $j < $uvSize; $j++) {
            $d = ord($dec[$ySize + $j]) - ord($ref[$ySize + $j]);
            $mseU += $d * $d;
            $d = ord($dec[$ySize + $uvSize + $j]) - ord($ref[$ySize + $uvSize + $j]);
            $mseV += $d * $d;
        }
        $psnrY = $mseY > 0 ? 10 * log10(255 * 255 * $ySize / $mseY) : INF;
        $psnrU = $mseU > 0 ? 10 * log10(255 * 255 * $uvSize / $mseU) : INF;
        $psnrV = $mseV > 0 ? 10 * log10(255 * 255 * $uvSize / $mseV) : INF;
        $sumY += $psnrY;
        printf("  [%s] 帧 %2d: Y=%6.2f U=%6.2f V=%6.2f\n", $tag, $i, $psnrY, $psnrU, $psnrV);
        if ($worst === null || $psnrY < $worst) $worst = $psnrY;
    }
    if ($total >= $frameCount) {
        printf("  [%s] 平均 Y PSNR = %.2f dB, 最差 Y = %.2f dB\n", $tag, $sumY / max(1, $total), $worst);
    } else {
        printf("  [%s] 帧数不足: %d/%d\n", $tag, $total, $frameCount);
    }
}
