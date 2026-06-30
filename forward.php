<?php

require_once __DIR__."/vendor/autoload.php";
use Xiaosongshu\Flv2mp4\Flv\FlvForwardClient;

$pullUrl = 'http://127.0.0.1:8501/a/b.flv';
$pushUrls = [
    'rtmp://127.0.0.1:1935/c/d',
//    'http://127.0.0.1:8502/live/stream2',
//    'ws://127.0.0.1:8503/live/stream3'
];

$forwarder = new FlvForwardClient($pullUrl, $pushUrls, 0, true);
$forwarder->start();