<?php
require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '512M');

$mp4File = __DIR__ . "/test.mp4";
$flvFromMp4 = __DIR__ . "/test1.flv";
try {
    if (file_exists($mp4File)) {
        $res3 = \Xiaosongshu\Flv2mp4\Client::runMp42Flv($mp4File, $flvFromMp4);
        echo "\n mp4转flv完成: {$res3}\n\n";
    } else {
        echo "跳过: 测试文件不存在 {$mp4File}\n\n";
    }
} catch (\Exception $e) {
    echo "错误: " . $e->getMessage() . "\n\n";
}