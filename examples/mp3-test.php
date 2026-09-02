<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use Xiaosongshu\Flv2mp4\Mp3\Config;
use Xiaosongshu\Flv2mp4\Mp3\Encoder;

$path = $argv[1] ?? dirname(__DIR__) . '/mp3-test.mp3';
$seconds = isset($argv[2]) ? (float) $argv[2] : 1.0;
$rate = isset($argv[3]) ? (int) $argv[3] : 44100;
$channels = isset($argv[4]) ? (int) $argv[4] : 2;
$bitrate = isset($argv[5]) ? (int) $argv[5] : 128000;

if ($seconds < 0) {
    fwrite(STDERR, "seconds must not be negative\n");
    exit(1);
}

$encoder = new Encoder(new Config($rate, $channels, $bitrate));
$frames = (int) ceil($seconds * $rate / Encoder::FRAME_SAMPLES);
$data = $encoder->encodeSilence($frames);
file_put_contents($path, $data);
printf("wrote %d bytes (%d frames) to %s\n", strlen($data), $encoder->frameCount(), $path);
