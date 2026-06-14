<?php
require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '512M');
// mp4转码生成测试的flv文件 ffmpeg -i test.mp4 -c:v libx264 -c:a aac -f flv test.flv
// 检查切片是否错误的命令 ffmpeg -v trace -i hls\a\b\segment_1.ts -f null -
// 需要转换的flv媒体文件


$file = __DIR__."/test.flv";


//echo "=== 示例1: flv静态文件切片fMP4并合并为mp4文件 ===\n";
//$outputDir1 = __DIR__."/output_merge";
//try{
//    $res = \Xiaosongshu\Flv2mp4\Client::runFlv2mp4($file, $outputDir1);
//    echo "\n转换完成: " . $res . "\n\n";
//}catch (\Exception $e){
//    echo "错误: " . $e->getMessage() . "\n\n";
//}
//
//
//echo "=== 示例2: 生成分开的音视频切片 ===\n";
//$outputDir2 = __DIR__."/output_separate";
//try{
//    $res = \Xiaosongshu\Flv2mp4\Client::runFlv2mp4Separate($file, $outputDir2);
//    echo "\n转换完成！生成的文件:\n";
//    echo "  音频初始化: " . ($res['audioInit'] ?? '无') . "\n";
//    echo "  视频初始化: " . ($res['videoInit'] ?? '无') . "\n";
//    echo "  音频切片数量: " . count($res['audioSegments']) . "\n";
//    echo "  视频切片数量: " . count($res['videoSegments']) . "\n";
//    echo "  元数据文件: " . ($res['meta'] ?? '无') . "\n";
//}catch (\Exception $e){
//    echo "错误: " . $e->getMessage() . "\n";
//}

//echo "\n === 示例3: 转换flv为hls === \n";
//$outputDir1 = __DIR__ . "/hls";
//try {
//    $res = \Xiaosongshu\Flv2mp4\Client::runFlv2Hls($file, $outputDir1);
//    echo "\n hls转换完成 index = {$res['index']} dir = {$res['outputDir']}\n\n";
//
//    echo "\n === 示例4: 转换hls回flv === \n";
//    $outputFlv = __DIR__ . "/output_from_hls.flv";
//    try {
//        $res2 = \Xiaosongshu\Flv2mp4\Client::runHls2Flv($res['index'], $outputFlv);
//        echo "\n hls转flv完成: {$res2}\n\n";
//    } catch (\Exception $e) {
//        echo "错误: " . $e->getMessage() . "\n\n";
//    }
//} catch (\Exception $e) {
//    echo "错误: " . $e->getMessage() . "\n\n";
//}


echo "\n === 示例5: 转换mp4为flv === \n";
$mp4File = __DIR__ . "/test_fixed.mp4";
$flvFromMp4 = __DIR__ . "/test_1.flv";
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
//
//require_once __DIR__ . '/vendor/autoload.php';
//ini_set('memory_limit', '2048M');
//// ============ 命令行入口 ============
//
//
//
//if (PHP_SAPI !== 'cli') {
//    die("This script can only be run from command line.\n");
//}
//
//// 解析命令行参数
//if ($argc < 2) {
//    echo "Usage: php " . basename($argv[0]) . " <flv_file> [push_url] [speed] [--no-reconnect]\n";
//    echo "\n";
//    echo "Examples:\n";
//    echo "  php flv_pusher.php test.flv\n";
//    echo "  php flv_pusher.php test.flv http://127.0.0.1:8501/live/stream\n";
//    echo "  php flv_pusher.php test.flv http://127.0.0.1:8501/live/stream 2.0\n";
//    echo "  php flv_pusher.php test.flv http://127.0.0.1:8501/live/stream 1.0 --no-reconnect\n";
//    echo "\n";
//    echo "Options:\n";
//    echo "  speed        推流速度倍数 (0.1-10.0, default: 1.0)\n";
//    echo "  --no-reconnect  禁用自动重连\n";
//    exit(1);
//}
//
//$flvFile = $argv[1];
//$pushUrl = $argv[2] ?? 'http://127.0.0.1:8501/live/stream';
//$speed = $argv[3] ?? 1.0;
//$autoReconnect = !in_array('--no-reconnect', $argv);
//
//// 创建推流器
//$pusher = new \Xiaosongshu\Flv2mp4\manage\FLVPusher($flvFile, $pushUrl, $speed, $autoReconnect);
//
//// 启动推流
//$pusher->start();
//
//// ============ MP4 Pusher 示例 ============
//// 如果需要推流 MP4 文件，可以使用 Mp4Pusher
//// 用法：php demo.php test.mp4 http://127.0.0.1:8501/live/stream 1.0
//
//if (isset($argv[1]) && preg_match('/\.mp4$/i', $argv[1])) {
//    $mp4File = $argv[1];
//    $pushUrl = $argv[2] ?? 'http://127.0.0.1:8501/live/stream';
//    $speed = $argv[3] ?? 1.0;
//    $autoReconnect = !in_array('--no-reconnect', $argv);
//
//    echo "\n=== MP4 Pusher 模式 ===\n";
//    $mp4Pusher = new \Xiaosongshu\Flv2mp4\manage\Mp4Pusher($mp4File, $pushUrl, $speed, $autoReconnect);
//    $mp4Pusher->start();
//}