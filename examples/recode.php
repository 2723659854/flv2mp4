<?php

require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '2048M');

/** -----提示：仅支持baseline/main/high profile级别的h264 + aac/mp3/opus/wav/pcm 格式的flv/mp4/hls重编码------ */
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
    // profile 与 bframes 有库默认值：不传 = baseline + 无 B 帧；
    // 本入口由命令行第 2 参数指定 profile（main/high 自动开启 B 帧）
];
#
$flvFile = __DIR__ . '/output_high_mp3.mp4';
$mp4File = __DIR__ . '/output_high_mp3.mp4';

// 用法：php843 recode.php [flv|mp4|hls|all] [baseline|main|high] [aac|mp3|none] [输入文件]
// 第 3 参数：音频目标；缺省 / none = 不配置 audio（默认直通，零回归）
// 第 4 参数：可选输入文件覆盖（便于 mp3 源用例）
$mode = $argv[1] ?? 'flv';
$profile = $argv[2] ?? 'baseline';
$audioArg = strtolower(trim($argv[3] ?? ''));
if (in_array($audioArg, ['aac', 'mp3'], true)) {
    $config['audio'] = $audioArg;
}
$inputOverride = $argv[4] ?? null;
if ($inputOverride !== null) {
    $flvFile = $inputOverride;
    $mp4File = $inputOverride;
}
$audioSuffix = isset($config['audio']) ? '_' . $config['audio'] : '';
$config['profile'] = $profile;
$config['bframes'] = ($profile === 'main' || $profile === 'high');
$outFlv = __DIR__ . '/output_' . $profile . $audioSuffix . '.flv';

if ($mode === 'hls' || $mode === 'all') {
    /** flv转hls重编码 */
    $generator = new \Xiaosongshu\Flv2mp4\Recode\PurePhpHlsGenerator(
        $config,
        __DIR__ . '/hls/'.$profile.$audioSuffix,
        true,//是否开启分布式多进程加速
    );

    $startTime = time();
    $generator->processFlv($flvFile);
    $endTime = time();
    $cost = $endTime - $startTime;
    echo "HLS 生成完成！\n";
    echo "索引地址: hls/{$profile}{$audioSuffix}/index.m3u8\n";
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
    $recoder->processMp4($mp4File, __DIR__ . "/output_{$profile}{$audioSuffix}.mp4");
    $end2 = time();
    $cost2 = $end2 - $start2;
    echo "mp4重编码完成 {$cost2}s\n";
}