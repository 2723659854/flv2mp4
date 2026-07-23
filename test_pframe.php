<?php
/**
 * P帧编码测试
 */

require_once __DIR__ . '/vendor/autoload.php';

use Xiaosongshu\Flv2mp4\Codec\H264Encoder;

function generateMotionYUV(int $width, int $height, int $frameIdx, int $motionX = 2, int $motionY = 1): string
{
    $yuv = str_repeat("\x80", (int)($width * $height * 1.5));

    if ($frameIdx === 0) {
        // 帧0使用纯灰色（简化I帧编码）
        return $yuv;
    }

    $offsetX = $frameIdx * $motionX;
    $offsetY = $frameIdx * $motionY;

    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
            $idx = $y * $width + $x;
            $val = 128 + (int)(sin(($x + $offsetX) * 0.05) * 40) + (int)(cos(($y + $offsetY) * 0.05) * 40);
            $yuv[$idx] = chr(max(16, min(235, $val)));
        }
    }

    $chromaStart = $width * $height;
    for ($i = $chromaStart; $i < strlen($yuv); $i++) {
        $yuv[$i] = chr(128);
    }

    return $yuv;
}

function testPFrameEncoding(bool $enableInter): array
{
    $width = 320;
    $height = 240;
    $frameCount = 10;

    $encoder = new H264Encoder($width, $height, 25, 1000000);
    $encoder->setQp(28);
    $encoder->enableInter = $enableInter;

    $nalUnits = [];
    $yuvDataAll = '';

    echo "编码" . ($enableInter ? "P帧+I帧" : "全部I帧") . " ($frameCount 帧)...\n";

    for ($f = 0; $f < $frameCount; $f++) {
        $yuvData = generateMotionYUV($width, $height, $f);
        $yuvDataAll .= $yuvData;
        $frameNals = $encoder->encodeFrame($yuvData, $f === 0);
        $nalUnits = array_merge($nalUnits, $frameNals);
        echo "  帧 $f 编码完成\n";
    }

    $h264Data = implode('', $nalUnits);
    $h264File = tempnam(sys_get_temp_dir(), 'ptest_') . '.h264';
    file_put_contents($h264File, $h264Data);

    echo "  文件大小: " . strlen($h264Data) . " 字节\n";

    $ffprobeOutput = shell_exec("ffprobe -v error -show_entries frame=pkt_size -of csv=p=0 $h264File 2>&1");
    $frameSizes = array_filter(explode("\n", trim($ffprobeOutput)));
    echo "  帧大小: " . implode(', ', $frameSizes) . "\n";

    $decodedYuv = tempnam(sys_get_temp_dir(), 'decoded_') . '.yuv';
    exec("ffmpeg -y -i $h264File -f rawvideo -pix_fmt yuv420p $decodedYuv 2>&1", $ffmpegOutput, $returnCode);

    $result = [
        'success' => $returnCode === 0,
        'file_size' => strlen($h264Data),
        'frame_count' => $frameCount,
    ];

    if ($returnCode === 0 && file_exists($decodedYuv)) {
        $decoded = file_get_contents($decodedYuv);
        unlink($decodedYuv);

        $sseY = 0;
        $ySize = $width * $height;
        $frameSize = (int)($ySize * 1.5);

        for ($f = 0; $f < $frameCount; $f++) {
            $offset = $f * $frameSize;
            for ($i = 0; $i < $ySize; $i++) {
                $idx1 = $offset + $i;
                $diff = ord($decoded[$idx1]) - ord($yuvDataAll[$idx1]);
                $sseY += $diff * $diff;
            }
        }

        $mseY = $sseY / ($frameCount * $ySize);
        $psnrY = ($mseY > 0) ? 10 * log10(255 * 255 / $mseY) : 999.0;
        $result['psnr_y'] = round($psnrY, 2);
    } else {
        $result['psnr_y'] = 0;
        $error = '';
        foreach ($ffmpegOutput as $line) {
            if (stripos($line, 'error') !== false) {
                $error .= $line . "\n";
            }
        }
        $result['error'] = substr($error, 0, 200);
    }

    unlink($h264File);
    return $result;
}

echo "=== P帧编码测试 ===\n\n";

// 测试1: 全部I帧
echo "测试1: 全部I帧\n";
$resultI = testPFrameEncoding(false);
if ($resultI['success']) {
    echo sprintf("  成功! PSNR Y=%.2f dB, 文件大小=%d字节\n\n", $resultI['psnr_y'], $resultI['file_size']);
} else {
    echo "  失败: " . ($resultI['error'] ?? '未知错误') . "\n\n";
}

// 测试2: I帧+P帧混合
echo "测试2: I帧+P帧混合（I帧+P帧）\n";
$resultP = testPFrameEncoding(true);
if ($resultP['success']) {
    echo sprintf("  成功! PSNR Y=%.2f dB, 文件大小=%d字节\n", $resultP['psnr_y'], $resultP['file_size']);
    if ($resultI['success']) {
        $ratio = $resultP['file_size'] / $resultI['file_size'];
        echo sprintf("  P帧/I帧大小比: %.2f (%.1f%%)\n", $ratio, $ratio * 100);
    }
} else {
    echo "  失败: " . ($resultP['error'] ?? '未知错误') . "\n";
}

echo "\n测试完成！\n";