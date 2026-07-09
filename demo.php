<?php
// 检查PHP版本是否小于8.1
if (version_compare(PHP_VERSION, '8.1.0', '<')) {
    // 输出错误信息到标准错误（STDERR）
    fwrite(STDERR, "错误：此脚本需要PHP 8.1或更高版本，当前版本为 " . PHP_VERSION . "\n");
    // 退出脚本并返回错误码1（表示一般错误）
    exit(1);
}
require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '512M');


//echo "\n === 示例5: 转换mp4为flv === \n";
//$mp4File = __DIR__ . "/sea.mp4";
//$flvFromMp4 = __DIR__ . "/sea.flv";
//try {
//    if (file_exists($mp4File)) {
//        $res3 = \Xiaosongshu\Flv2mp4\Client::runMp42Flv($mp4File, $flvFromMp4);
//        echo "\n mp4转flv完成: {$res3}\n\n";
//    } else {
//        echo "跳过: 测试文件不存在 {$mp4File}\n\n";
//    }
//} catch (\Exception $e) {
//    echo "错误: " . $e->getMessage() . "\n\n";
//}

echo "\n === 示例6: 转换flv为mp4 === \n";
$flvFile = __DIR__ . "/test.flv";
$mp4FromFlv = __DIR__ . "/123456.mp4";
try {
    if (file_exists($flvFile)) {
        $res4 = \Xiaosongshu\Flv2mp4\Client::runFlvFile2Mp4($flvFile, $mp4FromFlv);
        echo "\n flv转mp4完成: {$res4}\n\n";
    } else {
        echo "跳过: 测试文件不存在 {$flvFile}\n\n";
    }
} catch (\Exception $e) {
    echo "错误: " . $e->getMessage() . "\n\n";
}


