<?php
require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '2048M');

$config = [
    'width' => 320,        // 目标宽度，0 = 保持原分辨率
    'height' => 180,       // 目标高度，0 = 保持原分辨率
    'bitrate' => 150000,   // 目标码率（bps），0 = 使用 QP 模式
    'fps' => 15,           // 目标帧率
    'qp' => 30,            // QP 质量参数（码率为 0 时生效）
];

$recoder = new \Xiaosongshu\Flv2mp4\Recode\FlvRecoder($config);
$recoder->setMaxFrames(100);  // 可选：限制处理帧数
$recoder->processFlv(__DIR__ . '/test.flv', __DIR__.'/output.flv');
echo "flv转码完成\r\n";