<?php

require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '2048M');
// 使用纯 PHP 多码率 HLS 生成器 ffmpeg -i test.mp4 -c:v libx264 -profile:v baseline -level:v 3.1 -x264-params "no-cabac=1:ref=1" -g 90 -keyint_min 90 -c:a aac -b:a 128k -y test.flv

// 单路转码配置（字段与 recode.php 保持一致）
$config = [
    'width' => 360,        // 目标宽度，0 = 保持原分辨率
    'height' => 360,       // 目标高度，0 = 保持原分辨率
    'bitrate' => 600000,   // 目标码率（bps），0 = 使用 QP 模式
    'fps' => 10,           // 目标帧率（低于源帧率时抽帧，0 = 保持源帧率）
    'qp' => 30,            // QP 质量参数（码率为 0 时生效）
    'audioBitrate' => 64000, // 音频码率（bps，HLS 切片封装使用）
    'watermark'=>false,     // 是否添加水印
    'watermark_file'=> __DIR__."/src/Static/watermark_80x16.yuv",// 水印文件
    'decode_workers'=>6,   // 并行解码进程数（按GOP并行，建议2~7；与运动估计进程合计不超过CPU逻辑核数）
    'motionWorkers'=>6
];

// 生成 HLS（分片与 index.m3u8 直接输出到 hls/output 目录）
$generator = new \Xiaosongshu\Flv2mp4\Recode\PurePhpHlsGenerator(
    $config,
    __DIR__ . '/hls/output',
    true
);
//$generator->setMaxFrames(200);
$startTime = time();
// 测试baseline profile 转码
$generator->processFlv(__DIR__ . '/test.flv');
//$generator->processFlv(__DIR__ . '/test1.flv');
$endTime = time();
$cost = $endTime - $startTime;
echo "HLS 生成完成！\n";
echo "索引地址: hls/output/index.m3u8\n";
echo "cost {$cost}s\n";