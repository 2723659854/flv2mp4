<?php

require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '2048M');

/** -----提示：仅支持baseline profile级别的h264 + aac 格式的flv/mp4/hls重编码------ */
$config = [
    'width' => 640,
    'height' => 360,
    'bitrate' => 300000,
    'fps' => 10,
    'audioBitrate' => 48000,
    'qp' => 0,
    'watermark'=>false,
    'watermark_file'=> __DIR__."/watermark_80x16.yuv",
    // GOP分布式架构（与直播 liveRtmpCompact.php 同构）：decode_workers 个GOP worker
    // 按IDR分组并行完成"解码+缩放+编码"，每个GOP worker内部仅携带1个运动估计子进程，
    // 避免按 6×6 过量创建进程；fastMotion 缩小运动搜索并跳过四分之一像素。
    'fastMotion'     => true,
    'motionWorkers'  => 1,
    'decode_workers' => 6,
    'segmentDuration'=> 3,
];

$flvFile = __DIR__ . '/test.flv';
$mp4File = __DIR__ . '/test.mp4';

// 用法：php829 recode.php [flv|mp4|hls|all]，默认 flv（压缩重编码走 GOP 分布式 + 每 worker 1 个运动估计子进程）
$mode = $argv[1] ?? 'flv';

if ($mode === 'hls' || $mode === 'all') {
    /** flv转hls重编码 */
    $generator = new \Xiaosongshu\Flv2mp4\Recode\PurePhpHlsGenerator(
        $config,
        __DIR__ . '/hls/output1',
        true,//是否开启分布式多进程加速
    );

    $startTime = time();
    $generator->processFlv($flvFile);
    $endTime = time();
    $cost = $endTime - $startTime;
    echo "HLS 生成完成！\n";
    echo "索引地址: hls/output1/index.m3u8\n";
    echo "cost {$cost}s\n";
}

if ($mode === 'flv' || $mode === 'all') {
    /** 重编码flv文件（GOP分布式：解码+缩放+编码在worker内完成） */
    $recoder = new \Xiaosongshu\Flv2mp4\Recode\FlvRecoder($config, true);
    $start1 = time();
    $recoder->processFlv($flvFile, __DIR__.'/output1.flv');
    $end1 = time();
    $cost1 = $end1 - $start1;
    echo "flv重编码完成,耗时{$cost1}s\n";
}

if ($mode === 'mp4' || $mode === 'all') {
    /** 重编码mp4文件（GOP分布式：解码+缩放+编码在worker内完成） */
    $recoder = new \Xiaosongshu\Flv2mp4\Recode\Mp4Recoder($config, true);
    $start2 = time();
    $recoder->processMp4($mp4File, __DIR__ . '/output1.mp4');
    $end2 = time();
    $cost2 = $end2 - $start2;
    echo "mp4重编码完成 {$cost2}s\n";
}