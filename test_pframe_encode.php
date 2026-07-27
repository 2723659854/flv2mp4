<?php

/**
 * P帧编码测试 - 三平面质量 (QP=30)
 */

require_once __DIR__ . '/vendor/autoload.php';

use Xiaosongshu\Flv2mp4\Codec\H264Encoder;

function generateMotionYUV(int $width, int $height, int $frameIdx, int $motionX = 2, int $motionY = 1): string
{
    $yuv = str_repeat("\x80", $width * $height * 3 / 2);

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

function testPFrameEncoding(bool $enableInter, int $qp = 30): array
{
    $width = 320;
    $height = 240;
    $frameCount = 10;

    $encoder = new H264Encoder($width, $height, 25, 1000000);
    $encoder->setQp($qp);
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
        'per_frame_psnr' => [],
        'avg_psnr' => ['y' => 0, 'u' => 0, 'v' => 0],
    ];

    if ($returnCode === 0 && file_exists($decodedYuv)) {
        $decoded = file_get_contents($decodedYuv);
        unlink($decodedYuv);

        $ySize = $width * $height;
        $uvSize = (int)($ySize / 4);
        $frameSize = (int)($ySize * 1.5);

        $sumY = 0;
        $sumU = 0;
        $sumV = 0;

        for ($f = 0; $f < $frameCount; $f++) {
            $offset = (int)($f * $frameSize);
            $decY = substr($decoded, $offset, $ySize);
            $decU = substr($decoded, $offset + $ySize, $uvSize);
            $decV = substr($decoded, $offset + $ySize + $uvSize, $uvSize);
            $refY = substr($yuvDataAll, $offset, $ySize);
            $refU = substr($yuvDataAll, $offset + $ySize, $uvSize);
            $refV = substr($yuvDataAll, $offset + $ySize + $uvSize, $uvSize);

            $sseY = 0;
            for ($i = 0; $i < $ySize; $i++) {
                $diff = ord($decY[$i]) - ord($refY[$i]);
                $sseY += $diff * $diff;
            }
            $mseY = $sseY / $ySize;
            $psnrY = $mseY > 0 ? 10 * log10(255 * 255 / $mseY) : INF;

            $sseU = 0;
            for ($i = 0; $i < $uvSize; $i++) {
                $diff = ord($decU[$i]) - ord($refU[$i]);
                $sseU += $diff * $diff;
            }
            $mseU = $sseU / $uvSize;
            $psnrU = $mseU > 0 ? 10 * log10(255 * 255 / $mseU) : INF;

            $sseV = 0;
            for ($i = 0; $i < $uvSize; $i++) {
                $diff = ord($decV[$i]) - ord($refV[$i]);
                $sseV += $diff * $diff;
            }
            $mseV = $sseV / $uvSize;
            $psnrV = $mseV > 0 ? 10 * log10(255 * 255 / $mseV) : INF;

            $result['per_frame_psnr'][] = [
                'y' => round($psnrY, 2),
                'u' => round($psnrU, 2),
                'v' => round($psnrV, 2),
            ];
            $sumY += $psnrY;
            $sumU += $psnrU;
            $sumV += $psnrV;
        }

        $result['avg_psnr']['y'] = round($sumY / $frameCount, 2);
        $result['avg_psnr']['u'] = round($sumU / $frameCount, 2);
        $result['avg_psnr']['v'] = round($sumV / $frameCount, 2);
    } else {
        $result['success'] = false;
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

echo "=== P帧编码测试 (QP=30) ===\n\n";

$qp = 30;

// 测试1: 全部I帧
echo "测试1: 全部I帧\n";
$resultI = testPFrameEncoding(false, $qp);
if ($resultI['success']) {
    echo "  成功!\n";
    echo "  文件大小: {$resultI['file_size']} 字节\n";
    echo "  平均 PSNR: Y={$resultI['avg_psnr']['y']} dB, U={$resultI['avg_psnr']['u']} dB, V={$resultI['avg_psnr']['v']} dB\n";
    echo "  每帧 PSNR:\n";
    foreach ($resultI['per_frame_psnr'] as $i => $psnr) {
        echo "    帧 {$i}: Y={$psnr['y']} dB, U={$psnr['u']} dB, V={$psnr['v']} dB\n";
    }
} else {
    echo "  失败: " . ($resultI['error'] ?? '未知错误') . "\n";
}
echo "\n";

// 测试2: I帧+P帧混合
echo "测试2: I帧+P帧混合（I帧+P帧）\n";
$resultP = testPFrameEncoding(true, $qp);
if ($resultP['success']) {
    echo "  成功!\n";
    echo "  文件大小: {$resultP['file_size']} 字节\n";
    echo "  平均 PSNR: Y={$resultP['avg_psnr']['y']} dB, U={$resultP['avg_psnr']['u']} dB, V={$resultP['avg_psnr']['v']} dB\n";
    echo "  每帧 PSNR:\n";
    foreach ($resultP['per_frame_psnr'] as $i => $psnr) {
        echo "    帧 {$i}: Y={$psnr['y']} dB, U={$psnr['u']} dB, V={$psnr['v']} dB\n";
    }
    if ($resultI['success']) {
        $ratio = $resultP['file_size'] / $resultI['file_size'];
        echo sprintf("  P帧/I帧大小比: %.2f (%.1f%%)\n", $ratio, $ratio * 100);
    }
} else {
    echo "  失败: " . ($resultP['error'] ?? '未知错误') . "\n";
}

echo "\n测试完成！\n";