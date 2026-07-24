<?php
/**
 * 从TS文件中提取H.264并解码，分析灰色方块分布
 */

$tsFile = __DIR__ . '/hls/output/180p/segment_1.ts';
$targetW = 320;
$targetH = 180;

// 用FFmpeg从TS中提取YUV
$ffYuvFile = __DIR__ . '/test_ts_gray_ff.yuv';
$cmd = "ffmpeg -y -i \"$tsFile\" -pix_fmt yuv420p -f rawvideo \"$ffYuvFile\" 2>&1";
$ffOutput = shell_exec($cmd);
echo "FFmpeg解码输出:\n$ffOutput\n";

$ffYuv = file_get_contents($ffYuvFile);
$ySize = $targetW * $targetH;
$uvSize = intdiv($ySize, 4);
$frameSize = $ySize + $uvSize * 2;
$ffFrameCount = (int)(strlen($ffYuv) / $frameSize);

echo "FFmpeg解码帧数: $ffFrameCount\n\n";

$mbW = (int)ceil($targetW / 16);
$mbH = (int)ceil($targetH / 16);
echo "宏块尺寸: {$mbW}x{$mbH} = " . ($mbW * $mbH) . " 个宏块\n\n";

// 逐帧分析灰色宏块
echo "=== 灰色宏块分析（FFmpeg解码输出中值=128的像素比例）===\n";
echo "阈值: 宏块内>80%像素=128视为灰色宏块\n\n";

$framesWithGray = [];
$grayMbPatterns = [];

for ($fi = 0; $fi < $ffFrameCount; $fi++) {
    $ffFrame = substr($ffYuv, $fi * $frameSize, $ySize);
    
    $grayMbCount = 0;
    $grayMbList = [];
    $pattern = '';
    
    for ($mbY = 0; $mbY < $mbH; $mbY++) {
        for ($mbX = 0; $mbX < $mbW; $mbX++) {
            $grayCount = 0;
            $totalCount = 0;
            
            for ($y = 0; $y < 16; $y++) {
                $py = $mbY * 16 + $y;
                if ($py >= $targetH) break;
                for ($x = 0; $x < 16; $x++) {
                    $px = $mbX * 16 + $x;
                    if ($px >= $targetW) break;
                    $idx = $py * $targetW + $px;
                    if (ord($ffFrame[$idx]) === 128) $grayCount++;
                    $totalCount++;
                }
            }
            
            $isGray = ($totalCount > 0 && $grayCount / $totalCount > 0.8);
            if ($isGray) {
                $grayMbCount++;
                $grayMbList[] = "($mbX,$mbY)";
            }
        }
    }
    
    $totalMb = $mbW * $mbH;
    $pct = $grayMbCount * 100.0 / $totalMb;
    printf("帧%2d: 灰色宏块 %3d/%d (%5.1f%%)", $fi, $grayMbCount, $totalMb, $pct);
    
    if ($grayMbCount > 0) {
        $framesWithGray[] = $fi;
        echo " -> " . implode(' ', array_slice($grayMbList, 0, 15));
        if ($grayMbCount > 15) echo " ... (共{$grayMbCount}个)";
    }
    echo "\n";
}

// 打印前5帧和有灰色宏块的帧的分布图
echo "\n=== 灰色宏块分布图 ===\n";
echo "每个字符代表一个宏块: .=正常 #=灰色(>80%%像素=128)\n\n";

$printed = 0;
for ($fi = 0; $fi < $ffFrameCount; $fi++) {
    $ffFrame = substr($ffYuv, $fi * $frameSize, $ySize);
    
    // 判断是否有灰色宏块
    $hasGray = false;
    for ($mbY = 0; $mbY < $mbH && !$hasGray; $mbY++) {
        for ($mbX = 0; $mbX < $mbW && !$hasGray; $mbX++) {
            $grayCount = 0;
            $totalCount = 0;
            for ($y = 0; $y < 16; $y++) {
                $py = $mbY * 16 + $y;
                if ($py >= $targetH) break;
                for ($x = 0; $x < 16; $x++) {
                    $px = $mbX * 16 + $x;
                    if ($px >= $targetW) break;
                    $idx = $py * $targetW + $px;
                    if (ord($ffFrame[$idx]) === 128) $grayCount++;
                    $totalCount++;
                }
            }
            if ($totalCount > 0 && $grayCount / $totalCount > 0.8) $hasGray = true;
        }
    }
    
    // 只打印前3帧和有灰色的帧
    if ($fi < 3 || $hasGray) {
        echo "--- 帧{$fi} ---\n";
        for ($mbY = 0; $mbY < $mbH; $mbY++) {
            $row = '';
            for ($mbX = 0; $mbX < $mbW; $mbX++) {
                $grayCount = 0;
                $totalCount = 0;
                for ($y = 0; $y < 16; $y++) {
                    $py = $mbY * 16 + $y;
                    if ($py >= $targetH) break;
                    for ($x = 0; $x < 16; $x++) {
                        $px = $mbX * 16 + $x;
                        if ($px >= $targetW) break;
                        $idx = $py * $targetW + $px;
                        if (ord($ffFrame[$idx]) === 128) $grayCount++;
                        $totalCount++;
                    }
                }
                if ($totalCount > 0 && $grayCount / $totalCount > 0.8) {
                    $row .= '#';
                } else {
                    $row .= '.';
                }
            }
            printf("%2d|%s|\n", $mbY, $row);
        }
        echo "\n";
        $printed++;
    }
}

// 周期性分析
echo "=== 周期性分析 ===\n";
echo "有灰色宏块的帧: " . (empty($framesWithGray) ? '无' : implode(', ', $framesWithGray)) . "\n";

if (count($framesWithGray) > 1) {
    $intervals = [];
    for ($i = 1; $i < count($framesWithGray); $i++) {
        $intervals[] = $framesWithGray[$i] - $framesWithGray[$i-1];
    }
    echo "帧间隔: " . implode(', ', $intervals) . "\n";
}

// 也计算一下PSNR看看质量趋势
echo "\n=== Y-PSNR 趋势（与前一帧对比）===\n";
$prevFrame = null;
for ($fi = 0; $fi < min($ffFrameCount, 20); $fi++) {
    $frame = substr($ffYuv, $fi * $frameSize, $ySize);
    if ($prevFrame !== null) {
        $mse = 0;
        for ($j = 0; $j < $ySize; $j++) {
            $d = ord($frame[$j]) - ord($prevFrame[$j]);
            $mse += $d * $d;
        }
        $mse /= $ySize;
        $psnr = $mse > 0 ? 10 * log10(255*255/$mse) : 99;
        printf("帧%2d vs 帧%2d: Y-PSNR = %.2f dB\n", $fi, $fi-1, $psnr);
    }
    $prevFrame = $frame;
}

// 清理
@unlink($ffYuvFile);

echo "\n[INFO] 完成\n";
