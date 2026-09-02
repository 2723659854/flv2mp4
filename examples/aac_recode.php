<?php

if (version_compare(PHP_VERSION, '8.1.0', '<')) {
    fwrite(STDERR, '此脚本需要 PHP 8.1 或更高版本' . PHP_EOL);
    exit(1);
}

require_once __DIR__ . '/vendor/autoload.php';

$inputMp4 = $argv[1] ?? __DIR__ . '/test_demo.mp4';
$sourceAac = $argv[2] ?? __DIR__ . '/aac_recode_source.aac';
$pcmFile = $argv[3] ?? __DIR__ . '/aac_recode.pcm';
$outputAac = $argv[4] ?? __DIR__ . '/aac_recode_output.aac';

if (!is_file($inputMp4)) {
    fwrite(STDERR, "输入文件不存在: {$inputMp4}" . PHP_EOL);
    exit(1);
}

try {
    echo "步骤1：使用 FFmpeg 提取 AAC-LC ADTS..." . PHP_EOL;
    extractAacLc($inputMp4, $sourceAac);
    echo "AAC: {$sourceAac} (" . filesize($sourceAac) . " bytes)" . PHP_EOL;

    echo "步骤2：使用纯 PHP 将 AAC-LC 解码为 S16LE PCM..." . PHP_EOL;
    $decoded = \Xiaosongshu\Flv2mp4\Client::runAac2Pcm($sourceAac, $pcmFile);
    echo "PCM: {$pcmFile} ({$decoded['bytes']} bytes)" . PHP_EOL;
    echo "参数: {$decoded['sampleRate']} Hz, {$decoded['channels']} 声道" . PHP_EOL;

    if ($decoded['sampleRate'] !== 48000) {
        throw new RuntimeException('当前纯 PHP AAC 编码器仅支持 48000 Hz PCM');
    }

    echo "步骤3：使用纯 PHP 将 PCM 编码并封装为 AAC-LC ADTS..." . PHP_EOL;
    encodePcmToAac($pcmFile, $outputAac, $decoded['channels']);
    echo "AAC: {$outputAac} (" . filesize($outputAac) . " bytes)" . PHP_EOL;
    echo "转换完成" . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, "转换失败: {$e->getMessage()}" . PHP_EOL);
    exit(1);
}

function extractAacLc(string $input, string $output): void
{
    $command = 'ffmpeg -y -i ' . escapeshellarg($input)
        . ' -map 0:a:0 -vn -c:a aac -profile:a aac_low'
        . ' -aac_tns 0 -ar 48000 -ac 2 -f adts '
        . escapeshellarg($output) . ' 2>&1';

    exec($command, $lines, $code);

    if ($code !== 0 || !is_file($output) || filesize($output) === 0) {
        throw new RuntimeException("FFmpeg 提取 AAC-LC 失败:\n" . implode(PHP_EOL, $lines));
    }
}

function encodePcmToAac(string $pcmFile, string $output, int $channels): void
{
    $input = fopen($pcmFile, 'rb');
    $target = fopen($output, 'wb');

    if ($input === false || $target === false) {
        if (is_resource($input)) {
            fclose($input);
        }
        if (is_resource($target)) {
            fclose($target);
        }
        throw new RuntimeException('无法打开 PCM 输入或 AAC 输出文件');
    }

    $encoder = new \Xiaosongshu\Flv2mp4\Aac\AacLcEncoder(128000, $channels);

    try {
        while (!feof($input)) {
            $pcm = fread($input, 1024 * 1024);
            if ($pcm === false) {
                throw new RuntimeException('读取 PCM 文件失败');
            }
            if ($pcm !== '') {
                writeAll($target, $encoder->encodeS16le($pcm));
            }
        }
        writeAll($target, $encoder->flush());
    } finally {
        fclose($input);
        fclose($target);
    }

    if (!is_file($output) || filesize($output) === 0) {
        throw new RuntimeException('纯 PHP AAC 编码器没有生成数据');
    }
}

function writeAll($handle, string $data): void
{
    $length = strlen($data);
    for ($offset = 0; $offset < $length;) {
        $written = fwrite($handle, substr($data, $offset));
        if ($written === false || $written === 0) {
            throw new RuntimeException('写入输出文件失败');
        }
        $offset += $written;
    }
}
