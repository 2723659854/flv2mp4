<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use Xiaosongshu\Flv2mp4\Mp3\Config;
use Xiaosongshu\Flv2mp4\Mp3\Encoder;

try {
    if ($argc !== 6) {
        throw new InvalidArgumentException(
            '用法: php bin/mp3-encode-pcm.php input.pcm output.mp3 sampleRate channels bitrate'
        );
    }

    $inputPath = $argv[1];
    $outputPath = $argv[2];
    $sampleRate = filter_var($argv[3], FILTER_VALIDATE_INT);
    $channels = filter_var($argv[4], FILTER_VALIDATE_INT);
    $bitrate = filter_var($argv[5], FILTER_VALIDATE_INT);

    if ($sampleRate === false || $channels === false || $bitrate === false) {
        throw new InvalidArgumentException('sampleRate、channels 和 bitrate 必须是整数');
    }
    if (!is_file($inputPath)) {
        throw new RuntimeException("PCM 文件不存在: {$inputPath}");
    }

    $input = fopen($inputPath, 'rb');
    $output = fopen($outputPath, 'wb');
    if ($input === false || $output === false) {
        if (is_resource($input)) fclose($input);
        if (is_resource($output)) fclose($output);
        throw new RuntimeException('无法打开 PCM 输入文件或 MP3 输出文件');
    }

    try {
        $encoder = new Encoder(new Config($sampleRate, $channels, $bitrate));
        while (!feof($input)) {
            $pcm = fread($input, 1024 * 1024);
            if ($pcm === false) {
                throw new RuntimeException('读取 PCM 文件失败');
            }
            if ($pcm === '') {
                continue;
            }
            $encoded = $encoder->encodeS16le($pcm);
            if ($encoded !== '' && fwrite($output, $encoded) !== strlen($encoded)) {
                throw new RuntimeException('写入 MP3 文件失败');
            }
        }

        $encoded = $encoder->flush();
        if ($encoded !== '' && fwrite($output, $encoded) !== strlen($encoded)) {
            throw new RuntimeException('写入 MP3 文件失败');
        }
    } finally {
        fclose($input);
        fclose($output);
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL . $e->getTraceAsString() . PHP_EOL);
    exit(1);
}
