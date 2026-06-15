<?php
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
$mp4Pusher = new \Xiaosongshu\Flv2mp4\manage\Mp4PusherAll($mp4File, $pushUrl, $speed, $autoReconnect);
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
$mp4Pusher = new \Xiaosongshu\Flv2mp4\manage\FlvPusherAll($flvFile, $pushUrl, $speed, $autoReconnect);
$mp4Pusher->start();