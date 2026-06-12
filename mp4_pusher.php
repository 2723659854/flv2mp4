<?php
require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '2048M');

//php mp4_pusher.php test.mp4 http://127.0.0.1:8501/c/d
$mp4File = $argv[1];
$pushUrl = $argv[2] ?? 'http://127.0.0.1:8501/c/d';
$speed = $argv[3] ?? 1.0;
$autoReconnect = !in_array('--no-reconnect', $argv);

echo "\n=== MP4 Pusher 模式 ===\n";
$mp4Pusher = new \Xiaosongshu\Flv2mp4\manage\Mp4Pusher($mp4File, $pushUrl, $speed, $autoReconnect);
$mp4Pusher->start();