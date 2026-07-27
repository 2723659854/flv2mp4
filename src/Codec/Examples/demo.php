<?php
/**
 * H.264 编解码 + 缩放 Demo
 * 
 * 功能：
 *   1. 从 FLV 文件中提取 H.264 裸流 (input.h264)
 *   2. 使用纯 PHP H.264 解码器解码为 YUV420P
 *   3. 使用 VideoScaler 缩放到目标分辨率
 *   4. 使用 H.264 编码器重新编码为 output.h264
 * 
 * 使用方法：
 *   1. 准备 test.flv 文件放在项目根目录
 *   2. 运行: php demo.php
 *   3. 生成的文件:
 *      - input.h264   从 FLV 提取的原始 H.264 流
 *      - output.h264  解码+缩放+编码后的 H.264 流
 *      - input_decoded.yuv  解码后的原始 YUV (可选保存)
 *      - output_decoded.yuv 重新解码后的 YUV (可选保存，用于对比)
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

use Xiaosongshu\Flv2mp4\Codec\H264Decoder;
use Xiaosongshu\Flv2mp4\Codec\H264Encoder;
use Xiaosongshu\Flv2mp4\Codec\Scaler\VideoScaler;
use Xiaosongshu\Flv2mp4\Codec\NalUtil;
use Xiaosongshu\Flv2mp4\Flv\FlvParse;

// ========== 配置 ==========

$inputFlv = __DIR__ . '/../../../test.flv';   // 输入 FLV 文件
$outputDir = __DIR__;                          // 输出目录

// 编码参数
$targetWidth = 426;       // 目标宽度
$targetHeight = 240;      // 目标高度
$targetFps = 24;          // 目标帧率
$targetQp = 30;           // 量化参数 (0-51，越小质量越高)
$keyframeInterval = 30;   // 关键帧间隔

$saveDecodedYuv = true;   // 是否保存解码后的 YUV (用于调试)
$saveScaledYuv = false;   // 是否保存缩放后的 YUV (用于调试)

ini_set('memory_limit', '2048M');
set_time_limit(0);

// ========== Step 1: 从 FLV 提取 H.264 裸流 ==========

echo "========================================\n";
echo "Step 1: 从 FLV 提取 H.264 裸流\n";
echo "========================================\n";

if (!file_exists($inputFlv)) {
    die("错误: 找不到输入文件 {$inputFlv}\n");
}

$inputH264Path = $outputDir . '/input.h264';

$flvData = file_get_contents($inputFlv);
FlvParse::setFlv($flvData);

$h264Data = '';
$videoFrameCount = 0;
$spsData = '';
$ppsData = '';

const AVC_PACKET_TYPE_SEQUENCE_HEADER = 0;
const AVC_PACKET_TYPE_NALU = 1;

foreach (FlvParse::getTags() as $tag) {
    if (!property_exists($tag, 'tagType') || $tag->tagType !== 9) continue;
    
    $body = $tag->body ?? null;
    if ($body === null) continue;
    
    // 读取 VideoFrameHeader (1字节 FrameType + CodecID)
    $videoFrameHeader = ord($body[0]);
    $frameType = ($videoFrameHeader >> 4) & 0x0F;
    $codecId = $videoFrameHeader & 0x0F;
    
    if ($codecId !== 7) continue; // 只处理 AVC (H.264)
    
    // AVC Packet Type (1字节)
    $avcPacketType = ord($body[1]);
    
    // Composition Time (3字节，有符号)
    $cts = (ord($body[2]) << 16) | (ord($body[3]) << 8) | ord($body[4]);
    if ($cts & 0x800000) $cts -= 0x1000000;
    
    $avcData = substr($body, 5);
    
    if ($avcPacketType === AVC_PACKET_TYPE_SEQUENCE_HEADER) {
        // SPS/PPS 序列头
        echo "  找到 AVC Sequence Header\n";
        // 解析 AVCDecoderConfigurationRecord
        $avcc = parseAVCDecoderConfigurationRecord($avcData);
        if ($avcc) {
            $spsData = $avcc['sps'];
            $ppsData = $avcc['pps'];
            echo "  SPS 长度: " . strlen($spsData) . " bytes\n";
            echo "  PPS 长度: " . strlen($ppsData) . " bytes\n";
        }
    } elseif ($avcPacketType === AVC_PACKET_TYPE_NALU) {
        // 视频帧 NALU
        if ($videoFrameCount === 0 && $spsData !== '') {
            // 首帧前写入 SPS/PPS
            $h264Data .= "\x00\x00\x00\x01" . $spsData;
            $h264Data .= "\x00\x00\x00\x01" . $ppsData;
        }
        
        // 从 AVCC 格式 (长度前缀) 转换为 AnnexB 格式 (00 00 00 01 前缀)
        $nalUnits = avccToAnnexB($avcData);
        foreach ($nalUnits as $nal) {
            $h264Data .= $nal;
        }
        $videoFrameCount++;
        
        if ($videoFrameCount % 50 === 0) {
            echo "  已处理 {$videoFrameCount} 帧...\n";
        }
    }
}

file_put_contents($inputH264Path, $h264Data);
echo "  提取完成: 共 {$videoFrameCount} 帧\n";
echo "  输出文件: {$inputH264Path}\n";
echo "  文件大小: " . round(strlen($h264Data) / 1024, 2) . " KB\n\n";

if ($videoFrameCount === 0) {
    die("错误: 未找到视频帧\n");
}

// ========== Step 2: 解码 H.264 ==========

echo "========================================\n";
echo "Step 2: 解码 H.264\n";
echo "========================================\n";

$decoder = new H264Decoder();
$nalUnits = NalUtil::splitNalUnits($h264Data);
echo "  NALU 数量: " . count($nalUnits) . "\n";

$startTime = microtime(true);
$decoded = $decoder->decode($nalUnits);
$decodeTime = microtime(true) - $startTime;

if (!$decoded) {
    die("错误: 解码失败\n");
}

$srcWidth = $decoded['width'];
$srcHeight = $decoded['height'];
$yuvData = $decoded['data'];

$srcFrameSize = $srcWidth * $srcHeight + (int)(($srcWidth * $srcHeight) / 2);
$frameCount = (int)(strlen($yuvData) / $srcFrameSize);

echo "  源分辨率: {$srcWidth}x{$srcHeight}\n";
echo "  解码帧数: {$frameCount}\n";
echo "  解码耗时: " . round($decodeTime, 3) . " 秒\n";
echo "  平均速度: " . round($frameCount / $decodeTime, 2) . " 帧/秒\n";

if ($saveDecodedYuv) {
    $decodedYuvPath = $outputDir . '/input_decoded.yuv';
    file_put_contents($decodedYuvPath, $yuvData);
    echo "  已保存解码 YUV: {$decodedYuvPath}\n";
    echo "  文件大小: " . round(strlen($yuvData) / 1024 / 1024, 2) . " MB\n";
}
echo "\n";

// ========== Step 3: 视频缩放 ==========

echo "========================================\n";
echo "Step 3: 视频缩放 ({$srcWidth}x{$srcHeight} -> {$targetWidth}x{$targetHeight})\n";
echo "========================================\n";

$scaler = new VideoScaler();
$dstFrameSize = $targetWidth * $targetHeight + (int)(($targetWidth * $targetHeight) / 2);
$scaledYuv = '';

$startTime = microtime(true);
for ($i = 0; $i < $frameCount; $i++) {
    $srcFrame = substr($yuvData, $i * $srcFrameSize, $srcFrameSize);
    $scaledFrame = $scaler->scaleYUV420P($srcFrame, $srcWidth, $srcHeight, $targetWidth, $targetHeight);
    $scaledYuv .= $scaledFrame;
    
    if (($i + 1) % 50 === 0) {
        echo "  已缩放 " . ($i + 1) . " / {$frameCount} 帧...\n";
    }
}
$scaleTime = microtime(true) - $startTime;

echo "  缩放完成: {$frameCount} 帧\n";
echo "  缩放耗时: " . round($scaleTime, 3) . " 秒\n";
echo "  平均速度: " . round($frameCount / $scaleTime, 2) . " 帧/秒\n";

if ($saveScaledYuv) {
    $scaledYuvPath = $outputDir . '/scaled.yuv';
    file_put_contents($scaledYuvPath, $scaledYuv);
    echo "  已保存缩放 YUV: {$scaledYuvPath}\n";
    echo "  文件大小: " . round(strlen($scaledYuv) / 1024 / 1024, 2) . " MB\n";
}
echo "\n";

// ========== Step 4: 重新编码 ==========

echo "========================================\n";
echo "Step 4: H.264 编码\n";
echo "========================================\n";

$encoder = new H264Encoder();
$encoder->setResolution($targetWidth, $targetHeight);
$encoder->setFps($targetFps);
$encoder->setQp($targetQp);

$outputH264Path = $outputDir . '/output.h264';
$outputNals = [];

$startTime = microtime(true);
for ($i = 0; $i < $frameCount; $i++) {
    $frameData = substr($scaledYuv, $i * $dstFrameSize, $dstFrameSize);
    $isKeyFrame = ($i % $keyframeInterval === 0);
    
    $nals = $encoder->encodeFrame($frameData, $isKeyFrame);
    
    foreach ($nals as $nal) {
        $outputNals[] = "\x00\x00\x00\x01" . $nal;
    }
    
    if (($i + 1) % 50 === 0) {
        echo "  已编码 " . ($i + 1) . " / {$frameCount} 帧...\n";
    }
}
$encodeTime = microtime(true) - $startTime;

$encodedData = implode('', $outputNals);
file_put_contents($outputH264Path, $encodedData);

echo "  编码完成: {$frameCount} 帧\n";
echo "  编码耗时: " . round($encodeTime, 3) . " 秒\n";
echo "  平均速度: " . round($frameCount / $encodeTime, 2) . " 帧/秒\n";
echo "  输出文件: {$outputH264Path}\n";
echo "  文件大小: " . round(strlen($encodedData) / 1024, 2) . " KB\n";

// 计算压缩率
$rawSize = strlen($scaledYuv);
$compressedSize = strlen($encodedData);
$ratio = $rawSize / $compressedSize;
echo "  压缩率: " . round($ratio, 2) . " : 1\n";
echo "\n";

// ========== Step 5: 验证编码结果 (可选) ==========

echo "========================================\n";
echo "Step 5: 验证编码结果 (重新解码对比)\n";
echo "========================================\n";

$verifyDecoder = new H264Decoder();
$verifyNalUnits = NalUtil::splitNalUnits($encodedData);
$verifyDecoded = $verifyDecoder->decode($verifyNalUnits);

if ($verifyDecoded) {
    echo "  编码后的文件可以正常解码 ✓\n";
    echo "  解码帧数: " . ((int)(strlen($verifyDecoded['data']) / $dstFrameSize)) . "\n";
    
    // 简单质量对比：计算第一帧 PSNR
    if ($frameCount > 0) {
        $origY = substr($scaledYuv, 0, $targetWidth * $targetHeight);
        $reconY = substr($verifyDecoded['data'], 0, $targetWidth * $targetHeight);
        
        $mse = 0;
        $maxDiff = 0;
        for ($j = 0; $j < $targetWidth * $targetHeight; $j++) {
            $diff = ord($origY[$j]) - ord($reconY[$j]);
            $mse += $diff * $diff;
            if (abs($diff) > $maxDiff) $maxDiff = abs($diff);
        }
        $mse /= ($targetWidth * $targetHeight);
        $psnr = ($mse > 0) ? 10 * log10((255 * 255) / $mse) : INF;
        
        echo "  首帧 PSNR: " . ($psnr === INF ? 'INF' : round($psnr, 2)) . " dB\n";
        echo "  首帧最大像素差: {$maxDiff}\n";
    }
} else {
    echo "  警告: 编码后的文件无法解码 ✗\n";
}
echo "\n";

// ========== 总结 ==========

echo "========================================\n";
echo "处理完成！\n";
echo "========================================\n";
echo "输入文件: " . basename($inputFlv) . "\n";
echo "源分辨率: {$srcWidth}x{$srcHeight}\n";
echo "目标分辨率: {$targetWidth}x{$targetHeight}\n";
echo "总帧数: {$frameCount}\n";
echo "总耗时: " . round($decodeTime + $scaleTime + $encodeTime, 3) . " 秒\n";
echo "\n";
echo "生成的文件:\n";
echo "  - input.h264       从 FLV 提取的原始 H.264\n";
echo "  - output.h264      解码+缩放+编码后的 H.264\n";
if ($saveDecodedYuv) echo "  - input_decoded.yuv  解码后的原始 YUV\n";
if ($saveScaledYuv) echo "  - scaled.yuv         缩放后的 YUV\n";
echo "\n";
echo "使用 ffplay 播放:\n";
echo "  ffplay -f rawvideo -pixel_format yuv420p -video_size {$srcWidth}x{$srcHeight} input_decoded.yuv\n";
echo "  ffplay -f rawvideo -pixel_format yuv420p -video_size {$targetWidth}x{$targetHeight} output.h264\n";
echo "\n";

// ========== 辅助函数 ==========

/**
 * 解析 AVCDecoderConfigurationRecord，提取 SPS 和 PPS
 */
function parseAVCDecoderConfigurationRecord(string $data): ?array
{
    if (strlen($data) < 7) return null;
    
    $offset = 0;
    $configurationVersion = ord($data[$offset++]);  // 1字节
    $avcProfileIndication = ord($data[$offset++]);   // 1字节
    $profileCompatibility = ord($data[$offset++]);   // 1字节
    $avcLevelIndication = ord($data[$offset++]);     // 1字节
    $lengthSizeMinusOne = ord($data[$offset++]) & 0x03; // 1字节 (低2位)
    
    $numOfSequenceParameterSets = ord($data[$offset++]) & 0x1F; // 低5位
    
    $spsData = '';
    for ($i = 0; $i < $numOfSequenceParameterSets; $i++) {
        if ($offset + 2 > strlen($data)) break;
        $spsLength = (ord($data[$offset]) << 8) | ord($data[$offset + 1]);
        $offset += 2;
        if ($offset + $spsLength > strlen($data)) break;
        $spsData .= substr($data, $offset, $spsLength);
        $offset += $spsLength;
    }
    
    if ($offset >= strlen($data)) return null;
    $numOfPictureParameterSets = ord($data[$offset++]) & 0x1F;
    
    $ppsData = '';
    for ($i = 0; $i < $numOfPictureParameterSets; $i++) {
        if ($offset + 2 > strlen($data)) break;
        $ppsLength = (ord($data[$offset]) << 8) | ord($data[$offset + 1]);
        $offset += 2;
        if ($offset + $ppsLength > strlen($data)) break;
        $ppsData .= substr($data, $offset, $ppsLength);
        $offset += $ppsLength;
    }
    
    if ($spsData === '' || $ppsData === '') return null;
    
    return [
        'sps' => $spsData,
        'pps' => $ppsData,
        'profile' => $avcProfileIndication,
        'level' => $avcLevelIndication,
    ];
}

/**
 * 将 AVCC 格式 (长度前缀) 转换为 AnnexB 格式 (00 00 00 01 前缀)
 */
function avccToAnnexB(string $data): array
{
    $nalUnits = [];
    $offset = 0;
    $len = strlen($data);
    
    while ($offset + 4 <= $len) {
        $nalLength = (ord($data[$offset]) << 24)
                   | (ord($data[$offset + 1]) << 16)
                   | (ord($data[$offset + 2]) << 8)
                   | ord($data[$offset + 3]);
        $offset += 4;
        
        if ($nalLength <= 0 || $offset + $nalLength > $len) break;
        
        $nalData = substr($data, $offset, $nalLength);
        $nalUnits[] = "\x00\x00\x00\x01" . $nalData;
        $offset += $nalLength;
    }
    
    return $nalUnits;
}
