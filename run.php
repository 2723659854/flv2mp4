<?php
require_once __DIR__."/vendor/autoload.php";

\Xiaosongshu\Flv2mp4\Client::runFlv2Mp4(__DIR__.'/index.flv', __DIR__ . '/output_webrtc.mp4');
echo "success\r\n";