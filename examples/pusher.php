<?php
// 检查PHP版本是否小于8.1
if (version_compare(PHP_VERSION, '8.1.0', '<')) {
    // 输出错误信息到标准错误（STDERR）
    fwrite(STDERR, "错误：此脚本需要PHP 8.1或更高版本，当前版本为 " . PHP_VERSION . "\n");
    // 退出脚本并返回错误码1（表示一般错误）
    exit(1);
}
require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '2048M');

// 测试mp4推流
$mp4File = __DIR__ . '/sea.mp4';
// 测试http-flv协议
$pushUrl = 'http://127.0.0.1:8501/a/b';
// 测试ws-flv协议
$pushUrl = 'ws://127.0.0.1:8501/a/b';
$speed = 1.0;
$autoReconnect = true;

echo "\n=== MP4 Pusher 模式 ===\n";
$mp4Pusher = new \Xiaosongshu\Flv2mp4\Manage\Mp4PusherAll($mp4File, $pushUrl, $speed, $autoReconnect);
$mp4Pusher->start();


// 测试mp4推流
$flvFile = __DIR__ . '/sea.flv';
// 测试http-flv协议
$pushUrl = 'http://127.0.0.1:8501/a/b';
// 测试ws-flv协议
$pushUrl = 'ws://127.0.0.1:8501/a/b';
$speed = 1.0;
$autoReconnect = true;

echo "\n=== MP4 Pusher 模式 ===\n";
$mp4Pusher = new \Xiaosongshu\Flv2mp4\Manage\FlvPusherAll($flvFile, $pushUrl, $speed, $autoReconnect);
$mp4Pusher->start();