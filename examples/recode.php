<?php

require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '2048M');

/** -----提示：仅支持baseline profile级别的h264 + aac 格式的flv/mp4/hls重编码------ */
$config = [
    'width' => 480,
    'height' => 270,
    'bitrate' => 0,
    'fps' => 15,
    'audioBitrate' => 48000,
    'qp' => 30,
    'watermark'      => false,
    'watermarkFile'  => __DIR__."/watermark_80x16.yuv",
    'fastMotion'     => true,
    'motionWorkers'  => 1,
    'decodeWorkers' => 12,
    'segmentDuration'=> 3,
    // 输出 profile 等级：baseline / main / high；取值不匹配时默认 baseline
    // baseline 不允许 B 帧；若同时开启 bframes，则 baseline 自动提升为 main
    'profile'=>'baseline',
    // 是否开启 B 帧（IBBP）。开启后需 main/high profile；关闭则保持原有 I/P 路径
    'bframes'=> false,
];
# test.flv,base.flv,fast.flv,main_test.flv,real_high.flv,test_high.flv
$flvFile = __DIR__ . '/base.flv';
$mp4File = __DIR__ . '/base.mp4';

// 用法：php829 recode.php [flv|mp4|hls|all] [baseline|main|high]，默认 flv + baseline（压缩重编码走 GOP 分布式 + 每 worker 1 个运动估计子进程）
// ffmpeg -i base.flv -t 3 -c copy -avoid_negative_ts make_zero base3.flv
$mode = $argv[1] ?? 'flv';
// main/high 自动开启 B 帧（IBBP），同时覆盖 B 片 CABAC 路径
$profile = $argv[2] ?? 'baseline';
$config['profile'] = $profile;
$config['bframes'] = ($profile === 'main' || $profile === 'high');
$outFlv = __DIR__ . '/output_' . $profile . '.flv';

if ($mode === 'hls' || $mode === 'all') {
    /** flv转hls重编码 */
    $generator = new \Xiaosongshu\Flv2mp4\Recode\PurePhpHlsGenerator(
        $config,
        __DIR__ . '/hls/output',
        true,//是否开启分布式多进程加速
    );

    $startTime = time();
    $generator->processFlv($flvFile);
    $endTime = time();
    $cost = $endTime - $startTime;
    echo "HLS 生成完成！\n";
    echo "索引地址: hls/output/index.m3u8\n";
    echo "cost {$cost}s\n";
}

if ($mode === 'flv' || $mode === 'all') {
    /** 重编码flv文件（纯PHP解码+缩放+编码） */
    $recoder = new \Xiaosongshu\Flv2mp4\Recode\FlvRecoder($config, true);
    $start1 = time();
    $recoder->processFlv($flvFile, $outFlv);
    $end1 = time();
    $cost1 = $end1 - $start1;
    echo "flv重编码完成,耗时{$cost1}s\n";
}

if ($mode === 'mp4' || $mode === 'all') {
    /** 重编码mp4文件（GOP分布式：解码+缩放+编码在worker内完成） */
    $recoder = new \Xiaosongshu\Flv2mp4\Recode\Mp4Recoder($config, true);
    $start2 = time();
    $recoder->processMp4($mp4File, __DIR__ . '/output_main1.mp4');
    $end2 = time();
    $cost2 = $end2 - $start2;
    echo "mp4重编码完成 {$cost2}s\n";
}