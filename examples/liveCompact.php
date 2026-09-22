<?php

require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '2048M');

/**
 * 直播拉流压缩转码启动脚本（独立部署）
 *
 * 从一路 http-flv / https-flv / ws-flv / wss-flv 直播地址拉流，
 * 纯PHP重编码压缩为HLS切片（仅支持 H264 + AAC 源，baseline输出）。
 *
 * 部署：本进程是CPU密集型服务，请单独部署在空闲机器上，勿与直播推流服务器同机。
 * 运行：php liveCompact.php
 * 停止：Ctrl+C / kill（SIGINT/SIGTERM），会自动冲刷末帧并关闭分片。
 */

// ======================== 拉流配置 ========================
$pullUrl = 'http://127.0.0.1:8501/a/b.flv'; // 直播地址（http/https/ws/wss）

// ======================== 转码压缩配置 ========================
$config = [
    // —— 目标规格（width/height 必须同时给，0=保持源尺寸）——
    'width'        => 360,
    'height'       => 360,
    'bitrate'      => 600000,  // 目标视频码率 bps
    'fps'          => 0,       // 目标帧率（串行管道仅传编码器，不抽帧；0=保持）
    'qp'           => 10,      // 量化参数 0-51
    'audioBitrate' => 64000,   // 音频码率 bps

    // —— 并行/输出 ——
    'motionWorkers'   => 3,    // 运动估计子进程数（180p可降到2，核少的机器建议2-3）
    'segmentDuration' => 3,    // HLS切片时长（秒）
    // 'watermark'       => true,
    // 'watermark_file'  => __DIR__ . '/watermark_80x16.yuv',

    // —— 输出目录与流名（默认 项目根/hls/<URL末段>/）——
     'outputDir'  => __DIR__ . '/hls/live/',
    // 'streamName' => 'room1',

    // ======================== 拉流客户端配置 ========================
    'maxRetries'    => 5,      // 断线重连次数（稳定收流60秒后计数清零）
    'retryDelay'    => 3,      // 重连间隔（秒）
    'connectTimeout'=> 10,     // 连接/握手超时（秒）
    'idleTimeout'   => 30,     // 连续无数据判定断流（秒）
    'queueMaxBytes' => 67108864, // 缓存队列上限64MB（满则TCP反压上游，不丢帧）
    // 'duration'   => 0,      // 限定运行秒数，0=不限
    // 'tlsVerify'  => false,  // https/wss 自签证书时关闭校验
];

(new \Xiaosongshu\Flv2mp4\Manage\Flv2HlsCompact($pullUrl, $config))->run();
