<?php

require_once __DIR__ . "/vendor/autoload.php";
use Xiaosongshu\Flv2mp4\Flv\FlvForwardClient;
ini_set('memory_limit', '2048M');
// RTMP 拉流 -> RTMP 推流
$pullUrl = 'ws://127.0.0.1:8501/a/b.flv';
//$pullUrl = 'http://127.0.0.1:8501/a/b.flv';
//$pullUrl = 'rtmp://127.0.0.1:1935/a/b';
$pushUrls = [
//    'rtmp://127.0.0.1:1935/c/d',
    'ws://127.0.0.1:8501/c/d',
    'http://127.0.0.1:8501/c/e',
];

$forwarder = new FlvForwardClient($pullUrl, $pushUrls, 0, true);
$forwarder->start();