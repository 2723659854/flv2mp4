<?php
require_once __DIR__ . '/vendor/autoload.php';


$file = __DIR__."/test.flv";
$outputDir = __DIR__."/output";
$res = \Xiaosongshu\Flv2mp4\Client::run($file, $outputDir);
echo $res;