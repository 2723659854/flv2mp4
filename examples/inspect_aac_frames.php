<?php
require_once __DIR__ . '/vendor/autoload.php';
$path = $argv[1] ?? __DIR__ . '/aac1.aac';
$data = file_get_contents($path);
$decoder = new \Xiaosongshu\Flv2mp4\Aac\AacLcDecoder();
$frameNo = 0;
while (strlen($data) >= 7 && $frameNo < 10) {
    $h = unpack('C7', substr($data, 0, 7));
    $length = (($h[4] & 3) << 11) | ($h[5] << 3) | (($h[6] >> 5) & 7);
    if ($length < 7 || strlen($data) < $length) break;
    $frame = substr($data, 0, $length);
    $data = substr($data, $length);
    ++$frameNo;
    $started = microtime(true);
    try {
        $pcm = $decoder->decodeFrame($frame);
        $nonZero = 0;
        for ($i = 0; $i < strlen($pcm); $i++) if (ord($pcm[$i]) !== 0) { ++$nonZero; break; }
        printf("frame=%d length=%d pcm=%d nonzero=%d elapsed=%.3fs rate=%d channels=%d\n", $frameNo, $length, strlen($pcm), $nonZero, microtime(true) - $started, $decoder->sampleRate(), $decoder->channels());
    } catch (Throwable $e) {
        printf("frame=%d length=%d ERROR=%s elapsed=%.3fs\n", $frameNo, $length, $e->getMessage(), microtime(true) - $started);
        break;
    }
}
printf("frames=%d\n", $frameNo);
