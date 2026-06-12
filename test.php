<?php
require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '512M');

$mp4File = __DIR__ . "/test.mp4";
$pusher = new \Xiaosongshu\Flv2mp4\Manage\Mp4DirectPusher($mp4File, 'http://localhost:8501/live/stream');
$pusher->run();


