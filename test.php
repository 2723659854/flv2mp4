<?php
require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '512M');

//$mp4File = __DIR__ . "/run.mp4";
//$pusher = new \Xiaosongshu\Flv2mp4\Manage\Mp4PusherAll($mp4File, 'ws://127.0.0.1:8501/a/b');
//$pusher->start();


$flvFile = __DIR__ . "/demo_1.flv";
$pusher = new \Xiaosongshu\Flv2mp4\manage\FlvPusherAll($flvFile,'ws://localhost:8501/a/b');
$pusher->start();