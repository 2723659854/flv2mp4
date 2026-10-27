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

$source = $argv[1] ?? __DIR__ . '/base.mp4';
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
    if (is_file($productFile)) {
        @unlink($productFile); // 清掉上一轮残留，FAIL 时表格不会误读旧产物大小
    }
    try {
        $task();
        $convTime = microtime(true) - $start;
        if (!is_file($productFile) || filesize($productFile) === 0) {
            throw new RuntimeException('输出文件缺失或为空');
        }
        $verifyStart = microtime(true);
        $productDuration = wavDurationOf($productFile);
        $verifyTime = microtime(true) - $verifyStart;
        $drift = abs($productDuration - $srcDuration);
        if ($drift > $tolerance) {
            throw new RuntimeException(sprintf('时长偏差 %.3fs 超出容差 %.1fs', $drift, $tolerance));
        }
        $results[] = [$label, $convTime, $verifyTime, filesize($productFile), $srcDuration, $productDuration, 'PASS', ''];
        echo "  [PASS] {$label} (转换 " . sprintf('%.2fs', $convTime)
            . ' + 校验 ' . sprintf('%.2fs', $verifyTime) . ')' . PHP_EOL;
        return true;
    } catch (Throwable $e) {
        $failures++;
        $results[] = [$label, microtime(true) - $start, 0.0, is_file($productFile) ? filesize($productFile) : 0, $srcDuration, null, 'FAIL', $e->getMessage()];
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
 * 流式线性插值重采样：输入 44 字节头 PCM16 WAV（runAac2Wav 产物）→ 48kHz WAV。
 * 分块读取、跨块 carry 单帧，内存峰值仅单块（~数 MB），支持任意时长素材。
 */
function resampleWav48k(string $inFile, string $outFile, int $srcRate, int $channels): void
{
    $in = fopen($inFile, 'rb');
    if ($in === false) {
        throw new RuntimeException("无法读取 WAV: {$inFile}");
    }
    fseek($in, 44); // 跳过标准 44 字节头
    $out = fopen($outFile, 'wb');
    if ($out === false) {
        fclose($in);
        throw new RuntimeException("无法创建 WAV: {$outFile}");
    }
    try {
        $frameBytes = $channels * 2;
        $outRate = 48000;
        $step = $srcRate / $outRate;
        // 占位头，写完 data 后回卷补真实长度
        fwrite($out, 'RIFF' . pack('V', 36) . 'WAVE'
            . 'fmt ' . pack('V', 16) . pack('v', 1) . pack('v', $channels)
            . pack('V', $outRate) . pack('V', $outRate * $frameBytes)
            . pack('v', $frameBytes) . pack('v', 16)
            . 'data' . pack('V', 0));
        $pos = 0.0;      // 输入帧浮点位置（全局连续）
        $base = 0;       // 已消费的输入帧数
        $prevFrame = null; // 上一块最后一帧（跨块插值）
        $written = 0;
        while (!feof($in)) {
            $data = fread($in, 1024 * 1024);
            if ($data === false || $data === '') {
                break;
            }
            $usable = intdiv(strlen($data), $frameBytes) * $frameBytes;
            if ($usable === 0) {
                break;
            }
            $samples = unpack('v*', substr($data, 0, $usable));
            $framesIn = intdiv($usable, $frameBytes);
            $end = $base + $framesIn;
            $chunk = '';
            while ($pos + 1.0 < $end) {
                $idx = (int) floor($pos);
                $frac = $pos - $idx;
                $i0 = $idx - $base; // 块内索引，-1 表示需要上一块尾帧
                for ($ch = 0; $ch < $channels; ++$ch) {
                    $a = $i0 < 0 ? $prevFrame[$ch] : $samples[$i0 * $channels + $ch + 1];
                    $b = $samples[($i0 + 1) * $channels + $ch + 1];
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
                $pos += $step;
            }
            $last = ($framesIn - 1) * $channels;
            $prevFrame = [];
            for ($ch = 0; $ch < $channels; ++$ch) {
                $prevFrame[$ch] = $samples[$last + $ch + 1];
            }
            $base = $end;
            fwrite($out, $chunk);
            $written += strlen($chunk);
        }
        // 回卷补真实头长度
        fseek($out, 0);
        fwrite($out, 'RIFF' . pack('V', 36 + $written) . 'WAVE'
            . 'fmt ' . pack('V', 16) . pack('v', 1) . pack('v', $channels)
            . pack('V', $outRate) . pack('V', $outRate * $frameBytes)
            . pack('v', $frameBytes) . pack('v', 16)
            . 'data' . pack('V', $written));
    } finally {
        fclose($in);
        fclose($out);
    }
}

$run = static fn(string $m, array $a): mixed => Client::{$m}(...$a);
$totalStart = microtime(true);

// 每轮产物写入独立 run 子目录：rename 目标永远是全新路径，
// 规避 Windows 上残留句柄（文件监视/杀毒）占名导致的 rename 失败，且各轮产物可留存对比
$runDir = $outDir . '/run_' . date('Ymd_His');
if (!is_dir($runDir) && !mkdir($runDir, 0777, true) && !is_dir($runDir)) {
    fwrite(STDERR, "无法创建运行目录: {$runDir}" . PHP_EOL);
    exit(1);
}

echo "=== 音频转码自检（素材: " . basename($source) . "）===" . PHP_EOL;

echo PHP_EOL . '[1/4] 从 MP4 提取音频 -> WAV' . PHP_EOL;
$tmpWav = $runDir . '/tmp44k.wav';
record('MP4 -> WAV (runAac2Wav)', static fn() => $run('runAac2Wav', [$source, $tmpWav]), $tmpWav, 0, PHP_FLOAT_MAX);
// 素材准备阶段无法预知源时长，仅要求成功产出

echo PHP_EOL . '[2/4] 重采样 44.1kHz -> 48kHz 作为公共源素材' . PHP_EOL;
$srcWav = $runDir . '/s.wav';
$wav = readWavMeta($tmpWav);
if ($wav === null) {
    fwrite(STDERR, '素材准备失败：无法解析提取的 WAV' . PHP_EOL);
    exit(1);
}
resampleWav48k($tmpWav, $srcWav, $wav['sampleRate'], $wav['channels']);
$srcDuration = readWavDuration($srcWav);
printf("  源素材: %d Hz, %d 声道, 时长 %.3f 秒%s", 48000, $wav['channels'], $srcDuration, PHP_EOL);

echo PHP_EOL . '[3/4] 由 WAV 派生 aac/mp3/opus 源素材' . PHP_EOL;
$srcAac = $runDir . '/s.aac';
$srcMp3 = $runDir . '/s.mp3';
$srcOpus = $runDir . '/s.opus';
record('WAV -> AAC (runWav2Aac)', static fn() => $run('runWav2Aac', [$srcWav, $srcAac, 128000]), $srcAac, $srcDuration, $tolerance);
record('WAV -> MP3 (runWav2Mp3)', static fn() => $run('runWav2Mp3', [$srcWav, $srcMp3, 128000]), $srcMp3, $srcDuration, $tolerance);
record('WAV -> OPUS (runWav2Opus)', static fn() => $run('runWav2Opus', [$srcWav, $srcOpus]), $srcOpus, $srcDuration, $tolerance);

echo PHP_EOL . '[4/4] 12 条转换（4 源 x 3 目标，产物均回环解码校验时长）' . PHP_EOL;
$matrix = [
    ['AAC -> MP3', 'runAac2Mp3', [$srcAac, $runDir . '/aac2mp3.mp3', 'mp3'], $runDir . '/aac2mp3.mp3'],
    ['AAC -> WAV', 'runAac2Wav', [$srcAac, $runDir . '/aac2wav.wav'], $runDir . '/aac2wav.wav'],
    ['AAC -> OPUS', 'runAac2Opus', [$srcAac, $runDir . '/aac2opus.opus'], $runDir . '/aac2opus.opus'],
    ['MP3 -> WAV', 'runMp32Wav', [$srcMp3, $runDir . '/mp32wav.wav'], $runDir . '/mp32wav.wav'],
    ['MP3 -> AAC', 'runMp32Aac', [$srcMp3, $runDir . '/mp32aac.aac', 128000], $runDir . '/mp32aac.aac'],
    ['MP3 -> OPUS', 'runMp32Opus', [$srcMp3, $runDir . '/mp32opus.opus'], $runDir . '/mp32opus.opus'],
    ['OPUS -> WAV', 'runOpus2Wav', [$srcOpus, $runDir . '/opus2wav.wav'], $runDir . '/opus2wav.wav'],
    ['OPUS -> AAC', 'runOpus2Aac', [$srcOpus, $runDir . '/opus2aac.aac', 128000], $runDir . '/opus2aac.aac'],
    ['OPUS -> MP3', 'runOpus2Mp3', [$srcOpus, $runDir . '/opus2mp3.mp3', 128000], $runDir . '/opus2mp3.mp3'],
];
foreach ($matrix as [$label, $method, $args, $product]) {
    record($label, static fn() => $run($method, $args), $product, $srcDuration, $tolerance);
}

@unlink($tmpWav);

echo PHP_EOL . '=== 汇总 ===' . PHP_EOL;
printf("%-16s %8s %8s %10s %9s %9s %6s%s", '转换', '转换s', '校验s', '输出B', '源时长s', '产物s', '结果', PHP_EOL);
foreach ($results as [$label, $elapsed, $verify, $bytes, $sd, $pd, $verdict, $error]) {
    printf("%-16s %8.2f %8.2f %10d %9.3f %9s %6s%s",
        $label, $elapsed, $verify, $bytes, $sd, $pd === null ? '-' : sprintf('%.3f', $pd), $verdict, PHP_EOL);
    if ($error !== '') {
        printf("  └ %s%s", $error, PHP_EOL);
    }
}
printf("%s共 %d 条，通过 %d 条，失败 %d 条，总耗时 %.1f 秒%s",
    PHP_EOL, count($results), count($results) - $failures, $failures, microtime(true) - $totalStart, PHP_EOL);
echo "产物目录: {$runDir}" . PHP_EOL;
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
