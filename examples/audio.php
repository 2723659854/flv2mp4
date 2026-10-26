<?php
/**
 * audio.php — 12 条静态文件音频转码自检脚本
 *
 * 以 test1.mp4 为初始音频素材提供者，流程：
 *   1. runAac2Wav 从 MP4 提取音频得到 44.1kHz WAV
 *   2. 线性插值重采样为 48kHz WAV 作为公共源素材（AacLcEncoder 仅支持 48kHz）
 *   3. 由 WAV 派生 s.aac / s.mp3 / s.opus 三种源素材
 *   4. 4 源 x 3 目标共 12 条转换全部执行
 *   5. 每个产物回环解码回 WAV，校验时长与源素材偏差
 *
 * 用法：php843 audio.php [素材MP4=test1.mp4] [输出目录=audio_test]
 * 全部通过返回 0，存在失败返回 1。
 */

if (version_compare(PHP_VERSION, '8.1.0', '<')) {
    fwrite(STDERR, '错误：此脚本需要 PHP 8.1 或更高版本，当前版本为 ' . PHP_VERSION . PHP_EOL);
    exit(1);
}
require_once __DIR__ . '/vendor/autoload.php';

use Xiaosongshu\Flv2mp4\Client;

$source = $argv[1] ?? __DIR__ . '/test1.mp4';
$outDir = $argv[2] ?? __DIR__ . '/audio_test';
$tolerance = 1.0; // 产物时长与源素材的最大允许偏差（秒）

if (in_array($source, ['-h', '--help'], true) || !is_file($source)) {
    echo '用法：php843 audio.php [素材MP4=test1.mp4] [输出目录=audio_test]' . PHP_EOL;
    exit(is_file($source) ? 0 : 1);
}

if (!is_dir($outDir) && !mkdir($outDir, 0777, true) && !is_dir($outDir)) {
    fwrite(STDERR, "无法创建输出目录: {$outDir}" . PHP_EOL);
    exit(1);
}

$results = [];
$failures = 0;

/**
 * 记录一条转换结果
 */
function record(string $label, callable $task, string $productFile, float $srcDuration, float $tolerance): bool
{
    global $results, $failures;
    $start = microtime(true);
    try {
        $task();
        if (!is_file($productFile) || filesize($productFile) === 0) {
            throw new RuntimeException('输出文件缺失或为空');
        }
        $productDuration = wavDurationOf($productFile);
        $drift = abs($productDuration - $srcDuration);
        if ($drift > $tolerance) {
            throw new RuntimeException(sprintf('时长偏差 %.3fs 超出容差 %.1fs', $drift, $tolerance));
        }
        $results[] = [$label, microtime(true) - $start, filesize($productFile), $srcDuration, $productDuration, 'PASS', ''];
        echo "  [PASS] {$label} (" . sprintf('%.2fs', microtime(true) - $start) . ')' . PHP_EOL;
        return true;
    } catch (Throwable $e) {
        $failures++;
        $results[] = [$label, microtime(true) - $start, is_file($productFile) ? filesize($productFile) : 0, $srcDuration, null, 'FAIL', $e->getMessage()];
        echo "  [FAIL] {$label}: {$e->getMessage()}" . PHP_EOL;
        return false;
    }
}

/**
 * 得到一个音频文件的时长：WAV 直接读头，其余先回环解码为 WAV 再读头
 */
function wavDurationOf(string $file): float
{
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if ($ext === 'wav') {
        return readWavDuration($file);
    }
    $wav = $file . '.check.wav';
    try {
        if ($ext === 'aac') {
            Client::runAac2Wav($file, $wav);
        } elseif ($ext === 'mp3') {
            Client::runMp32Wav($file, $wav);
        } elseif ($ext === 'opus') {
            Client::runOpus2Wav($file, $wav);
        } else {
            throw new RuntimeException("未知产物扩展名: {$ext}");
        }
        if (!is_file($wav)) {
            throw new RuntimeException('回环解码未产生 WAV');
        }
        return readWavDuration($wav);
    } finally {
        if (is_file($wav)) {
            @unlink($wav);
        }
    }
}

/**
 * 读取 WAV 头并计算时长（秒）
 */
function readWavDuration(string $file): float
{
    $in = fopen($file, 'rb');
    if ($in === false) {
        throw new RuntimeException("无法读取 WAV: {$file}");
    }
    try {
        $riff = fread($in, 12);
        if (strlen($riff) !== 12 || substr($riff, 0, 4) !== 'RIFF' || substr($riff, 8, 4) !== 'WAVE') {
            throw new RuntimeException("无效 WAV 文件: {$file}");
        }
        $fmt = null;
        $dataSize = null;
        while (!feof($in)) {
            $chunk = fread($in, 8);
            if ($chunk === false || strlen($chunk) < 8) {
                break;
            }
            $id = substr($chunk, 0, 4);
            $size = unpack('V', substr($chunk, 4, 4))[1];
            if ($id === 'fmt ') {
                $body = fread($in, $size);
                $fmt = unpack('vaudioFormat/vchannels/VsampleRate/VbyteRate/vblockAlign/vbitsPerSample', substr($body, 0, 16));
            } elseif ($id === 'data') {
                $dataSize = $size;
                break;
            } else {
                fseek($in, $size + ($size % 2), SEEK_CUR);
            }
        }
        if ($fmt === null || $dataSize === null) {
            throw new RuntimeException("WAV 缺少 fmt/data 块: {$file}");
        }
        return $dataSize / $fmt['blockAlign'] / $fmt['sampleRate'];
    } finally {
        fclose($in);
    }
}

/**
 * S16LE 交错 PCM 线性插值重采样（块化，内存平稳）
 */
function resamplePcm(string $pcm, int $srcRate, int $channels, int $outRate): string
{
    $frameBytes = $channels * 2;
    $step = $srcRate / $outRate;
    $framesIn = intdiv(strlen($pcm), $frameBytes);
    $out = '';
    $pos = 0.0; // 输入帧位置
    while ($pos + 1.0 < $framesIn) {
        $idx = (int) floor($pos);
        $frac = $pos - $idx;
        $chunk = '';
        for ($ch = 0; $ch < $channels; $ch++) {
            $o = $idx * $frameBytes + $ch * 2;
            $a = unpack('v', substr($pcm, $o, 2))[1];
            $b = unpack('v', substr($pcm, $o + $frameBytes, 2))[1];
            $a = $a >= 32768 ? $a - 65536 : $a;
            $b = $b >= 32768 ? $b - 65536 : $b;
            $v = (int) round($a * (1.0 - $frac) + $b * $frac);
            if ($v > 32767) {
                $v = 32767;
            } elseif ($v < -32768) {
                $v = -32768;
            }
            $chunk .= pack('v', $v < 0 ? $v + 65536 : $v);
        }
        $out .= $chunk;
        $pos += $step;
    }
    return $out;
}

/**
 * 写标准 44 字节头 WAV
 */
function writeWav(string $file, string $pcm, int $rate, int $channels): void
{
    $dataSize = strlen($pcm);
    $header = 'RIFF' . pack('V', 36 + $dataSize) . 'WAVE'
        . 'fmt ' . pack('V', 16) . pack('v', 1) . pack('v', $channels)
        . pack('V', $rate) . pack('V', $rate * $channels * 2)
        . pack('v', $channels * 2) . pack('v', 16)
        . 'data' . pack('V', $dataSize);
    file_put_contents($file, $header . $pcm);
}

$run = static fn(string $m, array $a): mixed => Client::{$m}(...$a);
$totalStart = microtime(true);

echo "=== 音频转码自检（素材: " . basename($source) . "）===" . PHP_EOL;

echo PHP_EOL . '[1/4] 从 MP4 提取音频 -> WAV' . PHP_EOL;
$tmpWav = $outDir . '/tmp44k.wav';
record('MP4 -> WAV (runAac2Wav)', static fn() => $run('runAac2Wav', [$source, $tmpWav]), $tmpWav, 0, PHP_FLOAT_MAX);
// 素材准备阶段无法预知源时长，仅要求成功产出

echo PHP_EOL . '[2/4] 重采样 44.1kHz -> 48kHz 作为公共源素材' . PHP_EOL;
$srcWav = $outDir . '/s.wav';
$wav = readWavMeta($tmpWav);
if ($wav === null) {
    fwrite(STDERR, '素材准备失败：无法解析提取的 WAV' . PHP_EOL);
    exit(1);
}
$pcm = file_get_contents($tmpWav);
$header = 44; // 提取产物为标准 44 字节头
$srcPcm = substr($pcm, $header);
$resampled = resamplePcm($srcPcm, $wav['sampleRate'], $wav['channels'], 48000);
writeWav($srcWav, $resampled, 48000, $wav['channels']);
$srcDuration = readWavDuration($srcWav);
printf("  源素材: %d Hz, %d 声道, 时长 %.3f 秒%s", 48000, $wav['channels'], $srcDuration, PHP_EOL);

echo PHP_EOL . '[3/4] 由 WAV 派生 aac/mp3/opus 源素材' . PHP_EOL;
$srcAac = $outDir . '/s.aac';
$srcMp3 = $outDir . '/s.mp3';
$srcOpus = $outDir . '/s.opus';
record('WAV -> AAC (runWav2Aac)', static fn() => $run('runWav2Aac', [$srcWav, $srcAac, 128000]), $srcAac, $srcDuration, $tolerance);
record('WAV -> MP3 (runWav2Mp3)', static fn() => $run('runWav2Mp3', [$srcWav, $srcMp3, 128000]), $srcMp3, $srcDuration, $tolerance);
record('WAV -> OPUS (runWav2Opus)', static fn() => $run('runWav2Opus', [$srcWav, $srcOpus]), $srcOpus, $srcDuration, $tolerance);

echo PHP_EOL . '[4/4] 12 条转换（4 源 x 3 目标，产物均回环解码校验时长）' . PHP_EOL;
$matrix = [
    ['AAC -> MP3', 'runAac2Mp3', [$srcAac, $outDir . '/aac2mp3.mp3', 'mp3'], $outDir . '/aac2mp3.mp3'],
    ['AAC -> WAV', 'runAac2Wav', [$srcAac, $outDir . '/aac2wav.wav'], $outDir . '/aac2wav.wav'],
    ['AAC -> OPUS', 'runAac2Opus', [$srcAac, $outDir . '/aac2opus.opus'], $outDir . '/aac2opus.opus'],
    ['MP3 -> WAV', 'runMp32Wav', [$srcMp3, $outDir . '/mp32wav.wav'], $outDir . '/mp32wav.wav'],
    ['MP3 -> AAC', 'runMp32Aac', [$srcMp3, $outDir . '/mp32aac.aac', 128000], $outDir . '/mp32aac.aac'],
    ['MP3 -> OPUS', 'runMp32Opus', [$srcMp3, $outDir . '/mp32opus.opus'], $outDir . '/mp32opus.opus'],
    ['OPUS -> WAV', 'runOpus2Wav', [$srcOpus, $outDir . '/opus2wav.wav'], $outDir . '/opus2wav.wav'],
    ['OPUS -> AAC', 'runOpus2Aac', [$srcOpus, $outDir . '/opus2aac.aac', 128000], $outDir . '/opus2aac.aac'],
    ['OPUS -> MP3', 'runOpus2Mp3', [$srcOpus, $outDir . '/opus2mp3.mp3', 128000], $outDir . '/opus2mp3.mp3'],
];
foreach ($matrix as [$label, $method, $args, $product]) {
    record($label, static fn() => $run($method, $args), $product, $srcDuration, $tolerance);
}

@unlink($tmpWav);

echo PHP_EOL . '=== 汇总 ===' . PHP_EOL;
printf("%-16s %8s %10s %9s %9s %6s%s", '转换', '耗时s', '输出B', '源时长s', '产物s', '结果', PHP_EOL);
foreach ($results as [$label, $elapsed, $bytes, $sd, $pd, $verdict, $error]) {
    printf("%-16s %8.2f %10d %9.3f %9s %6s%s",
        $label, $elapsed, $bytes, $sd, $pd === null ? '-' : sprintf('%.3f', $pd), $verdict, PHP_EOL);
    if ($error !== '') {
        printf("  └ %s%s", $error, PHP_EOL);
    }
}
printf("%s共 %d 条，通过 %d 条，失败 %d 条，总耗时 %.1f 秒%s",
    PHP_EOL, count($results), count($results) - $failures, $failures, microtime(true) - $totalStart, PHP_EOL);
echo "产物目录: {$outDir}" . PHP_EOL;
exit($failures === 0 ? 0 : 1);

/**
 * 读取 WAV 头元信息（sampleRate/channels），失败返回 null
 */
function readWavMeta(string $file): ?array
{
    $in = @fopen($file, 'rb');
    if ($in === false) {
        return null;
    }
    try {
        $riff = fread($in, 12);
        if (strlen($riff) !== 12 || substr($riff, 0, 4) !== 'RIFF' || substr($riff, 8, 4) !== 'WAVE') {
            return null;
        }
        while (!feof($in)) {
            $chunk = fread($in, 8);
            if ($chunk === false || strlen($chunk) < 8) {
                break;
            }
            $id = substr($chunk, 0, 4);
            $size = unpack('V', substr($chunk, 4, 4))[1];
            if ($id === 'fmt ') {
                $body = fread($in, $size);
                $fmt = unpack('vaudioFormat/vchannels/VsampleRate/VbyteRate/vblockAlign/vbitsPerSample', substr($body, 0, 16));
                return ['sampleRate' => $fmt['sampleRate'], 'channels' => $fmt['channels']];
            }
            fseek($in, $size + ($size % 2), SEEK_CUR);
        }
        return null;
    } finally {
        fclose($in);
    }
}
