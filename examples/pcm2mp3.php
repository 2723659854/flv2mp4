<?php

if (version_compare(PHP_VERSION, '8.1.0', '<')) {
    fwrite(STDERR, '错误：此脚本需要 PHP 8.1 或更高版本，当前版本为 ' . PHP_VERSION . PHP_EOL);
    exit(1);
}

require_once __DIR__ . '/vendor/autoload.php';

$inputMp4 = $argv[1] ?? __DIR__ . '/test_demo.mp4';
$pcmFile = $argv[2] ?? __DIR__ . '/pcm2mp3.pcm';
$mp3File = $argv[3] ?? __DIR__ . '/pcm2mp3.mp3';
$sampleRate = 48000;
$channels = 2;
$bitrate = 128000;

if (in_array($inputMp4, ['-h', '--help'], true)) {
    echo '用法：php843 .\\pcm2mp3.php [输入MP4] [临时PCM] [输出MP3]' . PHP_EOL;
    echo '示例：php843 .\\pcm2mp3.php .\\test_demo.mp4 .\\pcm2mp3.pcm .\\pcm2mp3.mp3' . PHP_EOL;
    exit(0);
}

if (!is_file($inputMp4)) {
    fwrite(STDERR, "输入 MP4 不存在: {$inputMp4}" . PHP_EOL);
    exit(1);
}

try {
    $start = microtime(true);

    echo "步骤1：使用 FFmpeg 仅提取 S16LE PCM..." . PHP_EOL;
    extractPcm($inputMp4, $pcmFile, $sampleRate, $channels);
    echo "PCM: {$pcmFile} (" . filesize($pcmFile) . " bytes)" . PHP_EOL;
    echo "参数: {$sampleRate} Hz, {$channels} 声道, S16LE" . PHP_EOL;

    echo "步骤2：使用纯 PHP 将 PCM 编码为 MP3..." . PHP_EOL;
    encodePcmToMp3($pcmFile, $mp3File, $sampleRate, $channels, $bitrate);
    echo "MP3: {$mp3File} (" . filesize($mp3File) . " bytes)" . PHP_EOL;

    echo sprintf("测试完成，耗时 %.3f 秒。%s", microtime(true) - $start, PHP_EOL);
} catch (Throwable $e) {
    fwrite(STDERR, '转换失败: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

function extractPcm(string $input, string $output, int $sampleRate, int $channels): void
{
    $command = 'ffmpeg -y -i ' . escapeshellarg($input)
        . ' -vn -ar ' . $sampleRate . ' -ac ' . $channels
        . ' -f s16le ' . escapeshellarg($output) . ' 2>&1';

    exec($command, $lines, $code);
    if ($code !== 0 || !is_file($output) || filesize($output) === 0) {
        throw new RuntimeException("FFmpeg 提取 PCM 失败:\n" . implode(PHP_EOL, $lines));
    }
}

function encodePcmToMp3(string $pcmFile, string $mp3File, int $sampleRate, int $channels, int $bitrate): void
{
    $encoder = new \Xiaosongshu\Flv2mp4\Mp3\Encoder(
        new \Xiaosongshu\Flv2mp4\Mp3\Config($sampleRate, $channels, $bitrate)
    );
    $input = fopen($pcmFile, 'rb');
    $output = fopen($mp3File, 'wb');

    if ($input === false || $output === false) {
        if (is_resource($input)) fclose($input);
        if (is_resource($output)) fclose($output);
        throw new RuntimeException('无法打开 PCM 输入文件或 MP3 输出文件');
    }

    try {
        while (!feof($input)) {
            $data = fread($input, 1024 * 1024);
            if ($data === false) throw new RuntimeException('读取 PCM 文件失败');
            if ($data !== '') writeAll($output, $encoder->encodeS16le($data));
        }
        writeAll($output, $encoder->flush());
    } finally {
        fclose($input);
        fclose($output);
    }

    if (filesize($mp3File) === 0) throw new RuntimeException('MP3 编码没有生成任何数据');
}

function writeAll($handle, string $data): void
{
    for ($offset = 0, $length = strlen($data); $offset < $length;) {
        $written = fwrite($handle, substr($data, $offset));
        if ($written === false || $written === 0) throw new RuntimeException('写入 MP3 文件失败');
        $offset += $written;
    }
}
