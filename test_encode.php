<?php
require_once 'src/Codec/H264Encoder.php';

$encoder = new \Xiaosongshu\Flv2mp4\Codec\H264Encoder();
$encoder->setResolution(480, 360);

$yuvSize = 480 * 360 + 2 * (240 * 180);
$yuv = str_repeat(chr(128), $yuvSize);

$nal = $encoder->encodeFrame($yuv, true);

echo '=== SPS Analysis ===' . PHP_EOL;
$sps = $nal[0];
echo 'SPS raw bytes: ' . bin2hex($sps) . PHP_EOL;
echo 'First byte: 0x' . dechex(ord($sps[0])) . ' (' . decbin(ord($sps[0])) . ')' . PHP_EOL;
echo 'NAL type (correct: & 0x1F): ' . (ord($sps[0]) & 0x1F) . PHP_EOL;

echo PHP_EOL . '=== PPS Analysis ===' . PHP_EOL;
$pps = $nal[1];
echo 'PPS raw bytes: ' . bin2hex($pps) . PHP_EOL;
echo 'First byte: 0x' . dechex(ord($pps[0])) . ' (' . decbin(ord($pps[0])) . ')' . PHP_EOL;
echo 'NAL type (correct: & 0x1F): ' . (ord($pps[0]) & 0x1F) . PHP_EOL;

echo PHP_EOL . '=== IDR Analysis ===' . PHP_EOL;
$idr = $nal[2];
echo 'IDR raw bytes: ' . substr(bin2hex($idr), 0, 32) . '...' . PHP_EOL;
echo 'First byte: 0x' . dechex(ord($idr[0])) . ' (' . decbin(ord($idr[0])) . ')' . PHP_EOL;
echo 'NAL type (correct: & 0x1F): ' . (ord($idr[0]) & 0x1F) . PHP_EOL;
