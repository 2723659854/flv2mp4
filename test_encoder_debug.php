<?php

require_once __DIR__ . '/vendor/autoload.php';

use Xiaosongshu\Flv2mp4\Codec\H264Encoder;

$encoder = new H264Encoder();
$encoder->setResolution(640, 360);

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

echo "Testing encoder with " . strlen($yuvData) . " bytes YUV data\n";

$nalUnits = $encoder->encodeFrame($yuvData, true);
echo "Generated " . count($nalUnits) . " NAL units\n";

foreach ($nalUnits as $i => $nal) {
    $nalType = ord($nal[0]) & 0x1F;
    $refIdc = (ord($nal[0]) >> 5) & 0x03;
    $types = [1 => 'P-Slice', 5 => 'IDR', 7 => 'SPS', 8 => 'PPS'];
    $typeName = isset($types[$nalType]) ? $types[$nalType] : "Unknown($nalType)";
    
    echo "NAL $i: type=$typeName ($nalType), refIdc=$refIdc, size=" . strlen($nal) . " bytes\n";
    
    if ($nalType == 7 || $nalType == 8) {
        $hex = bin2hex(substr($nal, 0, 20));
        echo "  First 20 bytes: $hex\n";
    }
    
    if ($nalType == 5) {
        $hex = bin2hex(substr($nal, 0, 30));
        echo "  First 30 bytes: $hex\n";
        $sliceData = substr($nal, 1);
        $firstByte = ord($sliceData[0]);
        echo "  Slice first byte: 0x" . dechex($firstByte) . "\n";
    }
}

file_put_contents('test_encoder_output.264', "\x00\x00\x00\x01" . implode("\x00\x00\x00\x01", $nalUnits));
echo "\nSaved to test_encoder_output.264\n";

$cmd = 'ffmpeg -v error -i test_encoder_output.264 -f null - 2>&1';
exec($cmd, $output, $exitCode);
echo "\nFFmpeg test result (exit code $exitCode):\n";
echo implode("\n", $output) . "\n";
