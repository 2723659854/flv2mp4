<?php

if (version_compare(PHP_VERSION, '8.1.0', '<')) {
    fwrite(STDERR, '错误：此脚本需要 PHP 8.1 或更高版本，当前版本为 ' . PHP_VERSION . PHP_EOL);
    exit(1);
}

require_once __DIR__ . '/vendor/autoload.php';

// 用法：php843 .\aac2mp3.php [输入MP4]
// Linux/Docker：php aac2mp3.php [输入MP4]
// FFmpeg 只负责提取 AAC；AAC→PCM→MP3 全部由 PHP 完成。

if (isset($argv[1]) && in_array($argv[1], ['-h', '--help'], true)) {
    echo '用法：php843 .\\aac2mp3.php [输入MP4]' . PHP_EOL;
    echo '示例：php843 .\\aac2mp3.php .\\test_demo.mp4' . PHP_EOL;
    echo '输出：aac1-for-mp3.aac、aac1-for-mp3.pcm、aac1-for-mp3.mp3' . PHP_EOL;
    exit(0);
}

$inputMp4 = $argv[1] ?? __DIR__ . '/test_demo.mp4';
$aacFile = __DIR__ . '/aac1-for-mp3.aac';
$pcmFile = __DIR__ . '/aac1-for-mp3.pcm';
$mp3File = __DIR__ . '/aac1-for-mp3.mp3';

if (!is_file($inputMp4)) {
    fwrite(STDERR, "输入 MP4 不存在: {$inputMp4}" . PHP_EOL);
    exit(1);
}

try {
    $start = microtime(true);

    echo "步骤1：使用 FFmpeg 提取 AAC-LC ADTS..." . PHP_EOL;
    extractAac($inputMp4, $aacFile);
    echo "AAC1: {$aacFile} (" . filesize($aacFile) . " bytes)" . PHP_EOL;

    echo "步骤2：使用纯 PHP 将 AAC-LC 解码为 PCM..." . PHP_EOL;
    $decoded = \Xiaosongshu\Flv2mp4\Client::runAac2Pcm($aacFile, $pcmFile);
    echo "PCM: {$pcmFile} ({$decoded['bytes']} bytes)" . PHP_EOL;
    echo "参数: {$decoded['sampleRate']} Hz, {$decoded['channels']} 声道" . PHP_EOL;

    echo "步骤3：使用纯 PHP 将 PCM 编码为 MP3..." . PHP_EOL;
    encodePcmToMp3(
        $pcmFile,
        $mp3File,
        $decoded['sampleRate'],
        $decoded['channels'],
        128000
    );
    echo "MP3: {$mp3File} (" . filesize($mp3File) . " bytes)" . PHP_EOL;

    $cost = microtime(true) - $start;
    echo sprintf("测试完成，耗时 %.3f 秒。%s", $cost, PHP_EOL);
} catch (Throwable $e) {
    fwrite(STDERR, '转换失败: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

function extractAac(string $input, string $output): void
{
    $command = 'ffmpeg -y -i ' . escapeshellarg($input)
        . ' -vn -c:a aac -profile:a aac_low -aac_tns 0 -ar 48000 -ac 2 -f adts '
        . escapeshellarg($output) . ' 2>&1';

    exec($command, $lines, $code);
    if ($code !== 0 || !is_file($output) || filesize($output) === 0) {
        throw new RuntimeException(
            "FFmpeg 提取 AAC 失败:\n" . implode(PHP_EOL, $lines)
        );
    }
}

function encodePcmToMp3(
    string $pcmFile,
    string $mp3File,
    int $sampleRate,
    int $channels,
    int $bitrate
): void {
    $config = new \Xiaosongshu\Flv2mp4\Mp3\Config(
        $sampleRate,
        $channels,
        $bitrate
    );
    $encoder = new \Xiaosongshu\Flv2mp4\Mp3\Encoder($config);
    $input = fopen($pcmFile, 'rb');
    $output = fopen($mp3File, 'wb');

    if ($input === false || $output === false) {
        if (is_resource($input)) {
            fclose($input);
        }
        if (is_resource($output)) {
            fclose($output);
        }
        throw new RuntimeException('无法打开 PCM 输入文件或 MP3 输出文件');
    }

    try {
        while (!feof($input)) {
            $data = fread($input, 1024 * 1024);
            if ($data === false) {
                throw new RuntimeException('读取 PCM 文件失败');
            }
            if ($data !== '') {
                writeAll($output, $encoder->encodeS16le($data));
            }
        }
        writeAll($output, $encoder->flush());
    } finally {
        fclose($input);
        fclose($output);
    }

    if (filesize($mp3File) === 0) {
        throw new RuntimeException('MP3 编码没有生成任何数据');
    }
}

function writeAll($handle, string $data): void
{
    for ($offset = 0, $length = strlen($data); $offset < $length;) {
        $written = fwrite($handle, substr($data, $offset));
        if ($written === false || $written === 0) {
            throw new RuntimeException('写入 MP3 文件失败');
        }
        $offset += $written;
    }
}
