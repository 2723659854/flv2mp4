<?php

require_once __DIR__ . '/vendor/autoload.php';

use Xiaosongshu\Flv2mp4\Codec\H264Encoder;

$yuvData = '';
for ($y = 0; $y < 360; $y++) {
    for ($x = 0; $x < 640; $x++) {
        $yuvData .= chr(128 + ($x % 128));
    }
}
for ($y = 0; $y < 180; $y++) {
    for ($x = 0; $x < 320; $x++) {
        $yuvData .= chr(128);
        $yuvData .= chr(128);
    }
}

echo "Testing with hardcoded SPS/PPS\n";

$sps = "\x27\x42\x40\x1e\x96\x54\x05\x01\x78\x00";
$pps = "\x28\xcb\x40";

$encoder = new H264Encoder();
$encoder->setResolution(640, 360);

$nalUnits = $encoder->encodeFrame($yuvData, true);

$nalUnits[0] = $sps;
$nalUnits[1] = $pps;

echo "Using hardcoded SPS: " . bin2hex($sps) . "\n";
echo "Using hardcoded PPS: " . bin2hex($pps) . "\n";

file_put_contents('test_hardcoded.264', "\x00\x00\x00\x01" . implode("\x00\x00\x00\x01", $nalUnits));
echo "\nSaved to test_hardcoded.264\n";

$cmd = 'ffmpeg -v error -i test_hardcoded.264 -f null - 2>&1';
exec($cmd, $output, $exitCode);
echo "\nFFmpeg test result (exit code $exitCode):\n";
echo implode("\n", $output) . "\n";
