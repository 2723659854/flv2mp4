<?php
/**
 * RTMP直播拉流子进程入口（由 Flv2HlsCompact 转码主进程在 rtmp:// 地址时自动拉起，无需手工运行）
 *
 * 用法: php bin/flv2hls-rtmp-puller.php --url=<rtmp直播地址> --port=<本地IPC端口>
 *        [--retries=N] [--retry-delay=N] [--connect-timeout=N]
 *        [--idle-timeout=N] [--lag-bytes=N] [--autoload=路径]
 *
 * 进程职责：持续读空上游RTMP服务器（永不反压），RTMP chunk重组还原FLV tag，
 * 经本地TCP把tag帧交给转码主进程；转码落后超限时在IDR关键帧边界跳帧追直播。
 * IPC帧协议与 flv2hls-puller.php 完全一致。信号由主进程统一处理，本进程忽略Ctrl+C。
 */

ini_set('memory_limit', '512M');

$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if (!str_starts_with($arg, '--') || !str_contains($arg, '=')) continue;
    [$key, $value] = explode('=', substr($arg, 2), 2);
    $opts[$key] = $value;
}

$autoload = $opts['autoload'] ?? dirname(__DIR__) . '/vendor/autoload.php';
require_once $autoload;

if (empty($opts['url']) || empty($opts['port'])) {
    fwrite(STDERR, "用法: php flv2hls-rtmp-puller.php --url=<rtmp直播地址> --port=<IPC端口>\n");
    exit(2);
}

$config = [
    'url' => $opts['url'],
    'port' => (int)$opts['port'],
    'maxRetries' => isset($opts['retries']) ? (int)$opts['retries'] : 5,
    'retryDelay' => isset($opts['retry-delay']) ? (int)$opts['retry-delay'] : 3,
    'connectTimeout' => isset($opts['connect-timeout']) ? (int)$opts['connect-timeout'] : 10,
    'idleTimeout' => isset($opts['idle-timeout']) ? (int)$opts['idle-timeout'] : 30,
    'maxLagBytes' => isset($opts['lag-bytes']) ? (int)$opts['lag-bytes'] : 8388608,
];

exit((new \Xiaosongshu\Flv2mp4\Manage\RtmpStreamPuller($config))->run());
