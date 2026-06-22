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
echo 'First byte: 0x' . dechex(ord($sps[0])) . PHP_EOL;
echo 'NAL type from first byte: ' . ((ord($sps[0]) >> 1) & 0x3F) . PHP_EOL;

echo PHP_EOL . '=== PPS Analysis ===' . PHP_EOL;
$pps = $nal[1];
echo 'PPS raw bytes: ' . bin2hex($pps) . PHP_EOL;
echo 'First byte: 0x' . dechex(ord($pps[0])) . PHP_EOL;
echo 'NAL type from first byte: ' . ((ord($pps[0]) >> 1) & 0x3F) . PHP_EOL;
