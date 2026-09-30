<?php

require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '2048M');

/**
 * RTMP直播拉流压缩转码启动脚本（独立部署）
 *
 * 从 rtmp:// 直播地址拉流，纯PHP重编码压缩为HLS切片（仅支持 H264 + AAC 源，baseline输出）。
 * 转码链路与 liveCompact.php 完全相同，仅拉流子进程换成独立的RTMP实现。
 *
 * 运行：php liveRtmpCompact.php
 * 停止：Ctrl+C（自动快速收尾并写入ENDLIST）
 */

// ======================== 拉流配置 ========================
$pullUrl = 'rtmp://192.168.110.72:1935/a/b'; // RTMP直播地址（rtmp://host:port/app/流名，与liveCompact.php同一源）

// ======================== 转码压缩配置 ========================
$config = [
    // —— 目标规格（width/height 必须同时给，0=保持源尺寸）——
    'width'        => 640,
    'height'       => 360,
    'bitrate'      => 0,  // 目标视频码率 bps
    'fps'          => 10,       // 目标帧率（串行管道仅传编码器，不抽帧；0=保持）
    'qp'           => 30,      // 量化参数 0-51
    'audioBitrate' => 64000,   // 音频码率 bps

    // —— 并行/输出 ——
    'motionWorkers'   => 12,   // 运动估计子进程数（i5-13400/16线程实测12最优）
    'decodeWorkers'   => 6,    // 解码+缩放worker数（1080p 实测 6 可保 30fps 聚合）
    'segmentDuration' => 3,    // HLS切片时长（秒）
    'fastMotion' => true,      // 快速运动估计：缩小搜索并跳过四分之一像素
    // —— 输出目录与流名（默认 项目根/hls/<URL末段>/）——
    'outputDir'  => __DIR__ . '/hls/live_rtmp/',
    // 'streamName' => 'room1',

    // ======================== 拉流客户端配置 ========================
    'maxRetries'    => 5,      // 断线重连次数（稳定收流60秒后计数清零）
    'retryDelay'    => 3,      // 重连间隔（秒）
    'connectTimeout'=> 10,     // 连接/握手/命令响应超时（秒）
    'idleTimeout'   => 30,     // 连续无数据判定断流（秒）
    'queueMaxBytes' => 8388608,  // 转码落后容忍8MB；超限拉流进程跳IDR追直播（独立进程，绝不反压上游）
    // 'duration'   => 0,      // 限定运行秒数，0=不限
    // 'tlsVerify'  => false,  // https/wss 自签证书时关闭校验
];
(new \Xiaosongshu\Flv2mp4\Manage\Flv2HlsCompact($pullUrl, $config))->run();
