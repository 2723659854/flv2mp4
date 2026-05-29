<?php
require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '512M');
# 需要转换的flv媒体文件
$file = __DIR__."/test.flv";
# 转换后mp4保存目录
$outputDir = __DIR__."/output";
# 开始转换
try{
    # 转弯完成后返回mp4路径
    $res = \Xiaosongshu\Flv2mp4\Client::run($file, $outputDir);
    echo $res;
}catch (\Exception $e){
    # 抛出异常
    echo $e->getMessage();
}

