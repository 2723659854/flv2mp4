<?php
require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '512M');

$mp4File = __DIR__ . "/test.mp4";
$pusher = new \Xiaosongshu\Flv2mp4\Manage\Mp4Pusher($mp4File, 'http://localhost:8501/a/b');
$pusher->start();


//$flvFile = __DIR__ . "/test.flv";
//// 创建推流器
//$pusher = new \Xiaosongshu\Flv2mp4\manage\FLVPusher($flvFile,'http://localhost:8501/live/d');
//
//// 启动推流
//$pusher->start();