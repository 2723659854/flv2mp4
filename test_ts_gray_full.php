<?php

/**
 * 完整检测TS文件中灰色方块是否来自色度平面（U/V=128）
 * 用法: php test_ts_gray_full.php
 */

$tsFile = __DIR__ . '/hls/output/180p/segment_1.ts';  // 修改为你的TS路径
$targetW = 320;
$targetH = 180;

// 用FFmpeg解码TS为YUV420P
$ffYuvFile = __DIR__ . '/test_ts_gray_ff.yuv';
$cmd = "ffmpeg -y -i \"$tsFile\" -pix_fmt yuv420p -f rawvideo \"$ffYuvFile\" 2>&1";
exec($cmd, $ffOutput, $ret);
echo "FFmpeg解码输出:\n" . implode("\n", $ffOutput) . "\n\n";

$ffYuv = file_get_contents($ffYuvFile);
if ($ffYuv === false || strlen($ffYuv) === 0) {
    die("无法读取FFmpeg解码的YUV文件\n");
}

$ySize = $targetW * $targetH;
$uvSize = intdiv($ySize, 4);
$frameSize = $ySize + $uvSize * 2;
$ffFrameCount = (int)(strlen($ffYuv) / $frameSize);
echo "FFmpeg解码帧数: $ffFrameCount\n\n";

$mbW = (int)ceil($targetW / 16);
$mbH = (int)ceil($targetH / 16);
echo "宏块尺寸: {$mbW}x{$mbH} = " . ($mbW * $mbH) . " 个宏块\n\n";

/**
 * 分析一个帧的YUV数据，统计每个宏块内Y、U、V等于128的像素比例
 */
function analyzeFrame($frameData, $w, $h, $mbW, $mbH)
{
    $ySize = $w * $h;
    $uvSize = $ySize >> 2;
    $yPlane = substr($frameData, 0, $ySize);
    $uPlane = substr($frameData, $ySize, $uvSize);
    $vPlane = substr($frameData, $ySize + $uvSize, $uvSize);

    $result = [];
    $totalMb = $mbW * $mbH;
    $result['mb'] = [];
    $result['grayY'] = 0;
    $result['grayU'] = 0;
    $result['grayV'] = 0;

    for ($mbY = 0; $mbY < $mbH; $mbY++) {
        for ($mbX = 0; $mbX < $mbW; $mbX++) {
            // 统计Y
            $countY128 = 0;
            $totalY = 0;
            for ($y = 0; $y < 16; $y++) {
                $py = $mbY * 16 + $y;
                if ($py >= $h) break;
                for ($x = 0; $x < 16; $x++) {
                    $px = $mbX * 16 + $x;
                    if ($px >= $w) break;
                    $idx = $py * $w + $px;
                    if (ord($yPlane[$idx]) === 128) $countY128++;
                    $totalY++;
                }
            }
            $isGrayY = ($totalY > 0 && $countY128 / $totalY > 0.8);

            // 统计U（每个宏块对应8x8色度块）
            $countU128 = 0;
            $totalU = 0;
            for ($y = 0; $y < 8; $y++) {
                $py = $mbY * 8 + $y;
                if ($py >= $h / 2) break;
                for ($x = 0; $x < 8; $x++) {
                    $px = $mbX * 8 + $x;
                    if ($px >= $w / 2) break;
                    $idx = $py * ($w / 2) + $px;
                    if (ord($uPlane[$idx]) === 128) $countU128++;
                    $totalU++;
                }
            }
            $isGrayU = ($totalU > 0 && $countU128 / $totalU > 0.8);

            // 统计V
            $countV128 = 0;
            $totalV = 0;
            for ($y = 0; $y < 8; $y++) {
                $py = $mbY * 8 + $y;
                if ($py >= $h / 2) break;
                for ($x = 0; $x < 8; $x++) {
                    $px = $mbX * 8 + $x;
                    if ($px >= $w / 2) break;
                    $idx = $py * ($w / 2) + $px;
                    if (ord($vPlane[$idx]) === 128) $countV128++;
                    $totalV++;
                }
            }
            $isGrayV = ($totalV > 0 && $countV128 / $totalV > 0.8);

            $result['mb'][] = [
                'x' => $mbX, 'y' => $mbY,
                'grayY' => $isGrayY,
                'grayU' => $isGrayU,
                'grayV' => $isGrayV
            ];
            if ($isGrayY) $result['grayY']++;
            if ($isGrayU) $result['grayU']++;
            if ($isGrayV) $result['grayV']++;
        }
    }
    return $result;
}

echo "=== 灰色宏块检测（阈值: 宏块内>80%%像素=128）===\n";
echo "符号说明: Y=亮度灰块, U=色度U灰块, V=色度V灰块\n\n";

$grayFrames = [];

for ($fi = 0; $fi < $ffFrameCount; $fi++) {
    $frame = substr($ffYuv, $fi * $frameSize, $frameSize);
    $stats = analyzeFrame($frame, $targetW, $targetH, $mbW, $mbH);
    $totalMb = $mbW * $mbH;

    printf("帧%3d: Y灰块 %3d/%d (%.1f%%) | U灰块 %3d/%d (%.1f%%) | V灰块 %3d/%d (%.1f%%)",
        $fi,
        $stats['grayY'], $totalMb, $stats['grayY'] * 100 / $totalMb,
        $stats['grayU'], $totalMb, $stats['grayU'] * 100 / $totalMb,
        $stats['grayV'], $totalMb, $stats['grayV'] * 100 / $totalMb
    );

    if ($stats['grayU'] > 0 || $stats['grayV'] > 0 || $stats['grayY'] > 0) {
        $grayFrames[$fi] = $stats;
        echo " ⚠️ 发现灰色";
    }
    echo "\n";
}

// 打印前几个有灰色块的帧的分布图
echo "\n=== 灰色宏块分布图（仅显示U/V灰色）===\n";
echo "'.'=正常, 'U'=U灰块, 'V'=V灰块, 'B'=两者皆灰\n\n";

$printed = 0;
foreach ($grayFrames as $fi => $stats) {
    if ($printed >= 5) break;
    echo "--- 帧{$fi} (U灰={$stats['grayU']}, V灰={$stats['grayV']}) ---\n";
    $grid = array_fill(0, $mbH, str_repeat('.', $mbW));
    foreach ($stats['mb'] as $mb) {
        $x = $mb['x'];
        $y = $mb['y'];
        $ch = '.';
        if ($mb['grayU'] && $mb['grayV']) $ch = 'B';
        else if ($mb['grayU']) $ch = 'U';
        else if ($mb['grayV']) $ch = 'V';
        $grid[$y][$x] = $ch;
    }
    foreach ($grid as $row) echo "  $row\n";
    echo "\n";
    $printed++;
}

// 清理
@unlink($ffYuvFile);
echo "\n[INFO] 完成\n";