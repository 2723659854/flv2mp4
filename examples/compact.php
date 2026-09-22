<?php
require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '2048M');
echo "\n === flv直播转码压缩测试 === \n";

$hls = new \Xiaosongshu\Flv2mp4\Manage\Flv2HlsCompact('stream1', [
    'width' => 360,
    'height' => 360,
    'bitrate' => 300000,
    'fps' => 10,
    'audioBitrate' => 48000,
    'qp' => 10,
    'motionWorkers' => 6,
    'segmentDuration' => 3,            // 可选，默认3秒
    'outputDir' => '/path/to/out/',    // 可选，默认 项目根/hls/{streamId}/
]);
// 直播循环中：
$hls->processFrame($flvTag = "");   // 逐帧喂入，与 Flv2Hls 相同的 tag 对象形态
// 结束：
$hls->close();                  // 冲刷末帧、关闭分片、追加 ENDLIST