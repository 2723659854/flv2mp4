<?php

if (version_compare(PHP_VERSION, '8.1.0', '<')) {
    fwrite(STDERR, "错误：此脚本需要 PHP 8.1 或更高版本，当前版本为 " . PHP_VERSION . PHP_EOL);
    exit(1);
}

require_once __DIR__ . '/vendor/autoload.php';

// 命令行示例：php843 .\\aac2pcm.php .\\demo.mp4
// Linux/Docker 示例：php aac2pcm.php ./demo.mp4
// 第一步使用 FFmpeg 仅提取测试用 AAC，后续 AAC→PCM→AAC 全部由 PHP 完成。

if (isset($argv[1]) && in_array($argv[1], ['-h', '--help'], true)) {
    echo "用法：php843 .\\aac2pcm.php [输入MP4]" . PHP_EOL;
    echo "示例：php843 .\\aac2pcm.php .\\demo.mp4" . PHP_EOL;
    echo "输出：aac1.aac、aac1.pcm、aac2.aac" . PHP_EOL;
    exit(0);
}

$inputMp4 = $argv[1] ?? __DIR__ . '/test_demo.mp4';
$outputDir = __DIR__;
$aac1File = $outputDir . '/aac1.aac';
$pcmFile = $outputDir . '/aac1.pcm';
$aac2File = $outputDir . '/aac2.aac';

if (!is_file($inputMp4)) {
    fwrite(STDERR, "输入 MP4 不存在: {$inputMp4}" . PHP_EOL);
    exit(1);
}

try {
    $start = time();
    echo "步骤1：使用 FFmpeg 从 MP4 提取 AAC-LC..." . PHP_EOL;
    extractAac($inputMp4, $aac1File);
    echo "AAC1: {$aac1File} (" . filesize($aac1File) . " bytes)" . PHP_EOL;

    echo "步骤2：AAC1 解码为 PCM..." . PHP_EOL;
    $decoded = \Xiaosongshu\Flv2mp4\Client::runAac2Pcm(
        $aac1File,
        $pcmFile
    );
    echo "PCM: {$decoded['output']} (" . $decoded['bytes'] . " bytes)" . PHP_EOL;
    echo "参数: {$decoded['sampleRate']} Hz, {$decoded['channels']} 声道" . PHP_EOL;
    logPcmDiagnostics($pcmFile, $decoded['channels']);

    echo "步骤3：PCM 重新编码为 AAC-LC..." . PHP_EOL;
    encodePcmToAac($pcmFile, $aac2File, $decoded['sampleRate'], $decoded['channels']);
    echo "AAC2: {$aac2File} (" . filesize($aac2File) . " bytes)" . PHP_EOL;
    logAacDiagnostics($aac2File);
    echo "测试完成：aac1.aac、aac1.pcm、aac2.aac 均已保留。" . PHP_EOL;
    $end = time();
    $cost = $end - $start;
    echo "耗时{$cost}s\r\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "转换失败: {$e->getMessage()}" . PHP_EOL);
    exit(1);
}

function extractAac(string $input, string $output): void
{
    $command = 'ffmpeg -y -i ' . escapeshellarg($input)
        . ' -vn -c:a aac -profile:a aac_low -aac_tns 0 -ar 48000 -ac 2 -f adts '
        . escapeshellarg($output) . ' 2>&1';
    exec($command, $lines, $code);
    if ($code !== 0 || !is_file($output) || filesize($output) === 0) {
        throw new \RuntimeException("FFmpeg 提取 AAC 失败:\n" . implode(PHP_EOL, $lines));
    }
}

function encodePcmToAac(string $pcmFile, string $aacFile, int $sampleRate, int $channels): void
{
    if ($sampleRate !== 48000) {
        throw new \RuntimeException('当前 AAC-LC 编码器仅支持 48000 Hz PCM');
    }
    $input = fopen($pcmFile, 'rb');
    $output = fopen($aacFile, 'wb');
    if (!$input || !$output) {
        throw new \RuntimeException('无法打开 PCM 或 AAC 文件');
    }
    try {
        $encoder = new \Xiaosongshu\Flv2mp4\Aac\AacLcEncoder(128000, $channels);
        while (!feof($input)) {
            $data = fread($input, 1024 * 1024);
            if ($data === false) {
                throw new \RuntimeException('读取 PCM 文件失败');
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
}

function logPcmDiagnostics(string $pcmFile, int $channels): void
{
    $data = file_get_contents($pcmFile);
    if ($data === false) {
        return;
    }
    $channelStats = [];
    for ($channel = 0; $channel < $channels; $channel++) {
        $channelStats[$channel] = ['min' => null, 'max' => null, 'nonzero' => 0];
    }
    $overallMin = null;
    $overallMax = null;
    $overallNonzero = 0;
    $sampleFrames = [];
    $frameBytes = $channels * 2;
    $sampleCount = intdiv(strlen($data), $frameBytes);
    for ($frame = 0; $frame < $sampleCount; $frame++) {
        $samples = [];
        for ($channel = 0; $channel < $channels; $channel++) {
            $offset = ($frame * $channels + $channel) * 2;
            $value = unpack('v', substr($data, $offset, 2))[1];
            if ($value >= 0x8000) {
                $value -= 0x10000;
            }
            $samples[] = $value;
            $channelStats[$channel]['min'] = $channelStats[$channel]['min'] === null ? $value : min($channelStats[$channel]['min'], $value);
            $channelStats[$channel]['max'] = $channelStats[$channel]['max'] === null ? $value : max($channelStats[$channel]['max'], $value);
            if ($value !== 0) {
                $channelStats[$channel]['nonzero']++;
                $overallNonzero++;
            }
            $overallMin = $overallMin === null ? $value : min($overallMin, $value);
            $overallMax = $overallMax === null ? $value : max($overallMax, $value);
        }
        if ($frame < 1) {
            $sampleFrames[] = $samples;
        }
    }
    debugAacLog('AAC1_to_PCM', [
        'pcmBytes' => strlen($data),
        'channels' => $channels,
        'sampleFrames' => $sampleCount,
        'overall' => ['min' => $overallMin, 'max' => $overallMax, 'nonzero' => $overallNonzero],
        'perChannel' => $channelStats,
        'first32Hex' => bin2hex(substr($data, 0, 32)),
        'firstFrameSamples' => $sampleFrames[0] ?? [],
    ]);
}

function logAacDiagnostics(string $aacFile): void
{
    $data = file_get_contents($aacFile);
    if ($data === false) {
        return;
    }
    $rates = [96000, 88200, 64000, 48000, 44100, 32000, 24000, 22050, 16000, 12000, 11025, 8000, 7350];
    $frames = [];
    $offset = 0;
    while (count($frames) < 5 && $offset + 7 <= strlen($data)) {
        $h = unpack('C7', substr($data, $offset, 7));
        if ($h[1] !== 0xff || ($h[2] & 0xf6) !== 0xf0) {
            break;
        }
        $profile = (($h[3] >> 6) & 3) + 1;
        $rateIndex = ($h[3] >> 2) & 15;
        $channelConfig = (($h[3] & 1) << 2) | (($h[4] >> 6) & 3);
        $frameLength = (($h[4] & 3) << 11) | ($h[5] << 3) | (($h[6] >> 5) & 7);
        $rawBlocks = ($h[7] & 3) + 1;
        $headerLength = ($h[2] & 1) ? 7 : 9;
        $frames[] = [
            'offset' => $offset,
            'profile' => $profile,
            'rate' => $rates[$rateIndex] ?? null,
            'channels' => $channelConfig,
            'frameLength' => $frameLength,
            'rawBlocks' => $rawBlocks,
        ];
        if ($frameLength < $headerLength || $offset + $frameLength > strlen($data)) {
            break;
        }
        $offset += $frameLength;
    }
    debugAacLog('PCM_to_AAC2', ['aacBytes' => strlen($data), 'frames' => $frames]);
}

function debugAacLog(string $stage, array $data): void
{
    $entry = ['time' => date('c'), 'stage' => $stage, 'data' => $data];
    file_put_contents(__DIR__ . '/debug-aac2-noise.log', json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function writeAll($handle, string $data): void
{
    for ($offset = 0, $length = strlen($data); $offset < $length;) {
        $written = fwrite($handle, substr($data, $offset));
        if ($written === false || $written === 0) {
            throw new \RuntimeException('写入 AAC 文件失败');
        }
        $offset += $written;
    }
}
