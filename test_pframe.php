<?php
/**
 * 从FLV提取YUV并使用PHP编码器编码测试
 */

require_once __DIR__ . '/vendor/autoload.php';

use Xiaosongshu\Flv2mp4\Codec\H264Encoder;

/**
 * 从FLV文件中提取YUV帧数据
 */
function extractYUVFromFLV(string $flvFile, int $maxFrames = 0): array
{
    if (!file_exists($flvFile)) {
        throw new Exception("FLV文件不存在: $flvFile");
    }

    // 获取视频信息
    $ffprobeCmd = sprintf(
        'ffprobe -v error -select_streams v:0 -show_entries stream=width,height,r_frame_rate -of csv=p=0 %s',
        escapeshellarg($flvFile)
    );
    $info = shell_exec($ffprobeCmd);
    $parts = explode(',', trim($info));

    if (count($parts) < 3) {
        throw new Exception("无法获取视频信息");
    }

    $width = (int)$parts[0];
    $height = (int)$parts[1];

    // 解析帧率
    $fpsParts = explode('/', $parts[2]);
    $fps = $fpsParts[0] / ($fpsParts[1] ?? 1);

    // 提取YUV数据
    $yuvFile = tempnam(sys_get_temp_dir(), 'yuv_') . '.yuv';
    $duration = $maxFrames > 0 ? (int)ceil($maxFrames / $fps) : 999;

    $cmd = sprintf(
        'ffmpeg -y -i %s -t %d -f rawvideo -pix_fmt yuv420p %s 2>&1',
        escapeshellarg($flvFile),
        $duration,
        escapeshellarg($yuvFile)
    );

    exec($cmd, $output, $returnCode);

    if ($returnCode !== 0 || !file_exists($yuvFile)) {
        throw new Exception("提取YUV失败: " . implode("\n", $output));
    }

    $yuvData = file_get_contents($yuvFile);
    unlink($yuvFile);

    // 分割成单独的帧
    $frameSize = (int)($width * $height * 1.5);
    $totalFrames = (int)(strlen($yuvData) / $frameSize);

    if ($maxFrames > 0 && $totalFrames > $maxFrames) {
        $totalFrames = $maxFrames;
    }

    $frames = [];
    for ($i = 0; $i < $totalFrames; $i++) {
        $frames[] = substr($yuvData, $i * $frameSize, $frameSize);
    }

    return [
        'yuv_frames' => $frames,
        'width' => $width,
        'height' => $height,
        'fps' => $fps,
        'total_frames' => $totalFrames,
        'frame_size' => $frameSize
    ];
}

/**
 * 使用ffmpeg验证H.264文件是否正确
 */
function verifyH264WithFFmpeg(string $h264File, int $expectedFrames = 0): array
{
    $result = [
        'valid' => false,
        'actual_frames' => 0,
        'error' => null,
        'details' => []
    ];

    // 尝试用ffmpeg解码
    $outputFile = tempnam(sys_get_temp_dir(), 'verify_') . '.yuv';
    $cmd = sprintf(
        'ffmpeg -y -i %s -f rawvideo -pix_fmt yuv420p %s 2>&1',
        escapeshellarg($h264File),
        escapeshellarg($outputFile)
    );

    exec($cmd, $output, $returnCode);

    if ($returnCode === 0 && file_exists($outputFile)) {
        $yuvData = file_get_contents($outputFile);
        unlink($outputFile);

        // 尝试获取帧数信息
        $ffprobeCmd = sprintf(
            'ffprobe -v error -count_frames -select_streams v:0 -show_entries stream=nb_read_frames -of csv=p=0 %s 2>&1',
            escapeshellarg($h264File)
        );
        $frameCount = shell_exec($ffprobeCmd);
        $frameCount = trim($frameCount);

        if (is_numeric($frameCount) && $frameCount > 0) {
            $result['actual_frames'] = (int)$frameCount;
        } else {
            // 如果无法获取帧数，通过文件大小估算
            $frameSize = 320 * 240 * 1.5; // 默认分辨率，实际应该从编码器获取
            $result['actual_frames'] = (int)(strlen($yuvData) / $frameSize);
        }

        $result['valid'] = true;
        $result['details'] = [
            'decoded_size' => strlen($yuvData),
            'return_code' => $returnCode
        ];

        if ($expectedFrames > 0) {
            $result['frames_match'] = ($result['actual_frames'] == $expectedFrames);
        }

    } else {
        $result['error'] = implode("\n", array_slice($output, -5, 5));
        if (file_exists($outputFile)) {
            unlink($outputFile);
        }
    }

    return $result;
}

/**
 * 使用PHP编码器编码YUV帧
 */
function encodeYUVWithPHP(array $videoInfo, bool $enablePFrame = true): array
{
    $frames = $videoInfo['yuv_frames'];
    $width = $videoInfo['width'];
    $height = $videoInfo['height'];
    $fps = $videoInfo['fps'];
    $frameCount = count($frames);

    echo "开始编码: {$width}x{$height}, {$fps}fps, {$frameCount}帧\n";
    echo "编码模式: " . ($enablePFrame ? "I帧+P帧" : "全部I帧") . "\n";

    // 初始化编码器
    $bitrate = (int)($width * $height * 2.5);
    $encoder = new H264Encoder($width, $height, (int)$fps, $bitrate);
    $encoder->setQp(28);
    $encoder->enableInter = $enablePFrame;

    $nalUnits = [];
    $startTime = microtime(true);

    for ($f = 0; $f < $frameCount; $f++) {
        // 第一帧强制为I帧，后续根据enableInter决定
        $isKeyframe = ($f === 0) || !$enablePFrame;
        $frameNals = $encoder->encodeFrame($frames[$f], $isKeyframe);
        $nalUnits = array_merge($nalUnits, $frameNals);

        if (($f + 1) % 10 === 0 || $f === $frameCount - 1) {
            echo "  进度: " . ($f + 1) . "/$frameCount 帧\n";
        }
    }

    $elapsed = microtime(true) - $startTime;
    $h264Data = implode('', $nalUnits);

    // 保存H.264文件
    $h264File = tempnam(sys_get_temp_dir(), 'h264_') . '.h264';
    file_put_contents($h264File, $h264Data);

    $result = [
        'h264_file' => $h264File,
        'h264_data' => $h264Data,
        'file_size' => strlen($h264Data),
        'frame_count' => $frameCount,
        'encoding_time' => $elapsed,
        'average_fps' => $frameCount / $elapsed
    ];

    echo sprintf("编码完成: %.2fs, 大小: %d字节, 速度: %.1f帧/秒\n",
        $elapsed, $result['file_size'], $result['average_fps']);

    return $result;
}

/**
 * 检查H.264文件并显示详细信息
 */
function analyzeH264File(string $h264File): array
{
    $result = [
        'valid' => false,
        'info' => [],
        'error' => null
    ];

    // 获取流信息
    $cmd = sprintf(
        'ffprobe -v error -show_streams -select_streams v:0 -of default=noprint_wrappers=1 %s 2>&1',
        escapeshellarg($h264File)
    );
    $output = shell_exec($cmd);

    if (empty($output) || strpos($output, 'codec_name') === false) {
        $result['error'] = '无法解析H.264文件';
        return $result;
    }

    $info = [];
    $lines = explode("\n", trim($output));
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $info[trim($key)] = trim($value);
        }
    }

    $result['valid'] = true;
    $result['info'] = $info;

    // 获取帧信息
    $frameCmd = sprintf(
        'ffprobe -v error -show_entries frame=pkt_size,pict_type -of csv=p=0 %s 2>&1',
        escapeshellarg($h264File)
    );
    $frameOutput = shell_exec($frameCmd);
    $frames = [];
    if (!empty(trim($frameOutput))) {
        $lines = explode("\n", trim($frameOutput));
        foreach ($lines as $line) {
            $parts = explode(',', $line);
            if (count($parts) >= 2) {
                $frames[] = [
                    'size' => (int)$parts[0],
                    'type' => $parts[1]
                ];
            }
        }
    }
    $result['frames'] = $frames;

    return $result;
}

// ==================== 主程序 ====================

echo "=== FLV YUV提取 + PHP H.264编码测试 ===\n";
echo str_repeat('=', 50) . "\n\n";

$flvFile = __DIR__ . '/test.flv';

if (!file_exists($flvFile)) {
    die("错误: test.flv 不存在于当前目录!\n");
}

echo "输入文件: $flvFile\n";

try {
    // 1. 提取YUV
    echo "\n[1] 提取YUV数据...\n";
    $videoInfo = extractYUVFromFLV($flvFile, 50); // 提取50帧进行测试
    echo sprintf("  分辨率: %dx%d, 帧率: %.2f fps, 帧数: %d\n",
        $videoInfo['width'], $videoInfo['height'],
        $videoInfo['fps'], $videoInfo['total_frames']);

    // 2. 使用PHP编码器编码（I帧+P帧模式）
    echo "\n[2] PHP编码器编码...\n";
    $encodeResult = encodeYUVWithPHP($videoInfo, true);

    // 3. 验证H.264文件
    echo "\n[3] 验证H.264文件...\n";

    // 3.1 使用ffprobe分析
    $analysis = analyzeH264File($encodeResult['h264_file']);
    if ($analysis['valid']) {
        echo "  ✅ H.264文件有效\n";
        echo sprintf("  编码器: %s\n", $analysis['info']['codec_long_name'] ?? '未知');
        echo sprintf("  编码格式: %s\n", $analysis['info']['codec_tag_string'] ?? '未知');
        echo sprintf("  分辨率: %sx%s\n",
            $analysis['info']['width'] ?? '?',
            $analysis['info']['height'] ?? '?');
        echo sprintf("  帧数: %s\n", $analysis['info']['nb_frames'] ?? '未知');
        echo sprintf("  比特率: %s bps\n", $analysis['info']['bit_rate'] ?? '未知');

        // 显示帧类型分布
        if (!empty($analysis['frames'])) {
            $iFrames = 0;
            $pFrames = 0;
            $bFrames = 0;
            foreach ($analysis['frames'] as $frame) {
                switch ($frame['type']) {
                    case 'I': $iFrames++; break;
                    case 'P': $pFrames++; break;
                    case 'B': $bFrames++; break;
                }
            }
            echo sprintf("  帧类型分布: I=%d, P=%d, B=%d\n", $iFrames, $pFrames, $bFrames);

            // 显示前10帧的信息
            echo "  前10帧: ";
            $displayFrames = array_slice($analysis['frames'], 0, 10);
            $frameInfo = [];
            foreach ($displayFrames as $idx => $frame) {
                $frameInfo[] = sprintf("%d:%s", $idx, $frame['type']);
            }
            echo implode(', ', $frameInfo) . "\n";
        }
    } else {
        echo "  ❌ H.264文件验证失败: " . ($analysis['error'] ?? '未知错误') . "\n";
    }

    // 3.2 使用ffmpeg解码测试
    echo "\n[4] 解码测试...\n";
    $verifyResult = verifyH264WithFFmpeg($encodeResult['h264_file'], $encodeResult['frame_count']);

    if ($verifyResult['valid']) {
        echo "  ✅ 解码成功\n";
        echo sprintf("  实际帧数: %d\n", $verifyResult['actual_frames']);
        if (isset($verifyResult['frames_match'])) {
            echo sprintf("  帧数匹配: %s\n", $verifyResult['frames_match'] ? '✅ 是' : '❌ 否');
        }
        if (!empty($verifyResult['details'])) {
            echo sprintf("  解码数据大小: %d 字节\n", $verifyResult['details']['decoded_size']);
        }
    } else {
        echo "  ❌ 解码失败\n";
        if ($verifyResult['error']) {
            echo "  错误信息: " . $verifyResult['error'] . "\n";
        }
    }

    // 清理临时文件
    if (file_exists($encodeResult['h264_file'])) {
        unlink($encodeResult['h264_file']);
        echo "\n✅ 临时文件已清理\n";
    }

} catch (Exception $e) {
    echo "\n❌ 错误: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n" . str_repeat('=', 50) . "\n";
echo "测试完成！\n";