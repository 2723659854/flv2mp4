<?php
/**
 * @purpose 音频并行转码 worker（静态文件专用，与直播转码无关）。
 * 用法：
 *   php audio-worker.php decode <aac|mp3|wav|opus> <inputFile> [--gain-q8=auto|N]
 *   php audio-worker.php encode <aac|mp3|wav|opus> <outputFile> --rate=N --channels=N [--bitrate=N] [--pre-skip=N]
 * 协议：
 *   decode：S16LE 交错 PCM 写 stdout；stderr 输出 JSON 行
 *     {"ev":"meta","sampleRate":R,"channels":C,...} → {"ev":"progress",...} → {"ev":"done",...}
 *   encode：从 stdin 读 S16LE 交错 PCM；stderr 输出 {"ev":"progress",...} → {"ev":"done",...}
 *   失败时输出 {"ev":"error","message":...} 并 exit(1)
 * @author yanglong
 * @time 2026年10月26日
 */
ini_set('memory_limit', '2048M');
gc_disable(); // 同 motion-worker.php：无循环引用临时数据，省去GC根缓冲簿记

use Xiaosongshu\Flv2mp4\Aac\AacLcDecoder;
use Xiaosongshu\Flv2mp4\Aac\AacLcEncoder;
use Xiaosongshu\Flv2mp4\Manage\AAC2MP3;
use Xiaosongshu\Flv2mp4\Mp3\Config;
use Xiaosongshu\Flv2mp4\Mp3\Encoder;
use Xiaosongshu\Flv2mp4\Mp3\Mp3L3Decoder;
use Xiaosongshu\Flv2mp4\Opus\Encode\CeltFrameEncoder;
use Xiaosongshu\Flv2mp4\Opus\Encode\OggOpusWriter;
use Xiaosongshu\Flv2mp4\Opus\OggOpusReader;
use Xiaosongshu\Flv2mp4\Opus\OpusDecoder;
use Xiaosongshu\Flv2mp4\Opus\Pcm16;

$autoload = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--autoload=')) {
        $autoload = substr($a, 11);
    }
}
$autoload ??= dirname(__DIR__) . '/vendor/autoload.php';
require_once $autoload;

/** 向 stderr 输出一行 JSON 事件 */
function sendEvent(array $event): void
{
    fwrite(STDERR, json_encode($event) . "\n");
}

/** 循环写满一个流（管道可能部分写入） */
function writeAll($handle, string $data): void
{
    for ($offset = 0, $length = strlen($data); $offset < $length;) {
        $written = fwrite($handle, substr($data, $offset, 262144));
        if ($written === false || $written === 0) {
            throw new RuntimeException('写入流失败');
        }
        $offset += $written;
    }
}

/** 解析 --key=value 形式的命令行选项 */
function cliOptions(array $argv): array
{
    $options = [];
    foreach ($argv as $a) {
        if (str_starts_with($a, '--') && str_contains($a, '=')) {
            [$key, $value] = explode('=', substr($a, 2), 2);
            $options[$key] = $value;
        }
    }
    return $options;
}

try {
    $mode = $argv[1] ?? '';
    $format = strtolower($argv[2] ?? '');
    $file = $argv[3] ?? '';
    if (!in_array($mode, ['decode', 'encode'], true) || !in_array($format, ['aac', 'mp3', 'wav', 'opus'], true) || $file === '') {
        fwrite(STDERR, "用法: audio-worker.php decode|encode <aac|mp3|wav|opus> <file> [options]\n");
        exit(1);
    }
    $options = cliOptions($argv);

    if ($mode === 'decode') {
        match ($format) {
            'wav' => decodeWav($file),
            'aac' => decodeAac($file),
            'opus' => decodeOpus($file, $options['gain-q8'] ?? ''),
            'mp3' => decodeMp3($file),
        };
    } else {
        match ($format) {
            'wav' => encodeWav($file, (int)($options['rate'] ?? 0), (int)($options['channels'] ?? 0)),
            'mp3' => encodeMp3($file, (int)($options['rate'] ?? 0), (int)($options['channels'] ?? 0), (int)($options['bitrate'] ?? 128000)),
            'aac' => encodeAac($file, (int)($options['rate'] ?? 0), (int)($options['channels'] ?? 0), (int)($options['bitrate'] ?? 128000)),
            'opus' => encodeOpus($file, (int)($options['rate'] ?? 0), (int)($options['channels'] ?? 0), (int)($options['pre-skip'] ?? 312)),
        };
    }
    exit(0);
} catch (\Throwable $e) {
    sendEvent(['ev' => 'error', 'message' => $e->getMessage()]);
    exit(1);
}

// ---------------------------------------------------------------- decode

/** WAV → PCM：解析 RIFF 头先发 meta，再流式输出 data 块 */
function decodeWav(string $inputFile): void
{
    $input = fopen($inputFile, 'rb');
    if ($input === false) {
        throw new RuntimeException("无法读取 WAV 文件: {$inputFile}");
    }
    try {
        $format = readWavFormat($input);
        sendEvent([
            'ev' => 'meta',
            'sampleRate' => $format['sampleRate'],
            'channels' => $format['channels'],
            'bitsPerSample' => $format['bitsPerSample'],
            'audioFormat' => $format['audioFormat'],
            'blockAlign' => $format['blockAlign'],
        ]);
        $bytes = 0;
        $remaining = $format['dataSize'];
        $nextProgress = 0;
        while ($remaining > 0) {
            $data = fread($input, min(1024 * 1024, $remaining));
            if ($data === false || $data === '') {
                throw new RuntimeException('读取 WAV PCM 数据失败');
            }
            writeAll(STDOUT, $data);
            $length = strlen($data);
            $bytes += $length;
            $remaining -= $length;
            emitSecondsProgress($bytes / $format['blockAlign'] / $format['sampleRate'], $nextProgress);
        }
        sendEvent(['ev' => 'done', 'seconds' => $bytes / $format['blockAlign'] / $format['sampleRate'], 'bytes' => $bytes]);
    } finally {
        fclose($input);
    }
}

/** AAC（含 MP4/FLV 容器）→ PCM：先提取临时 ADTS 再流式解码 */
function decodeAac(string $inputFile): void
{
    $tmpAac = tempnam(sys_get_temp_dir(), 'flv2mp4_aw_') . '.aac';
    try {
        $meta = (new AAC2MP3())->process($inputFile, $tmpAac, 'aac');
        sendEvent([
            'ev' => 'meta',
            'sampleRate' => $meta['sampleRate'],
            'channels' => $meta['channels'],
            'frames' => $meta['frames'],
        ]);
        $input = fopen($tmpAac, 'rb');
        if ($input === false) {
            throw new RuntimeException('无法读取临时 ADTS 文件');
        }
        try {
            $decoder = new AacLcDecoder();
            $frameBytes = $meta['channels'] * 2;
            $pcmBytes = 0;
            $nextProgress = 0;
            while (!feof($input)) {
                $data = fread($input, 1024 * 1024);
                if ($data === false) {
                    throw new RuntimeException('读取 AAC 文件失败');
                }
                if ($data === '') {
                    continue;
                }
                $pcm = $decoder->push($data);
                if ($pcm !== '') {
                    writeAll(STDOUT, $pcm);
                    $pcmBytes += strlen($pcm);
                    emitSecondsProgress($pcmBytes / $frameBytes / $meta['sampleRate'], $nextProgress);
                }
            }
            $pcm = $decoder->flush();
            writeAll(STDOUT, $pcm);
            $pcmBytes += strlen($pcm);
            sendEvent([
                'ev' => 'done',
                'seconds' => $pcmBytes / $frameBytes / $meta['sampleRate'],
                'bytes' => $pcmBytes,
                'frames' => $meta['frames'],
            ]);
        } finally {
            fclose($input);
        }
    } finally {
        if (is_file($tmpAac)) {
            @unlink($tmpAac);
        }
    }
}

/** Ogg Opus → PCM：元数据早发，逐包解码（trim 与 Opus2Wav/Opus2Mp3 语义一致） */
function decodeOpus(string $inputFile, string $gainQ8): void
{
    $reader = OggOpusReader::fromFile($inputFile);
    $channels = $reader->channels();
    $rate = $reader->sampleRate();
    $packets = $reader->audioPackets();
    sendEvent([
        'ev' => 'meta',
        'sampleRate' => $rate,
        'channels' => $channels,
        'packets' => count($packets),
        'mappingFamily' => $reader->head()['mappingFamily'] ?? null,
    ]);
    // 仅 Opus2Mp3 路径应用 outputGainQ8（与原 Opus2Mp3 行为一致；Opus2Wav 不应用）
    $gain = 1.0;
    if ($gainQ8 === 'auto') {
        $q8 = (int)($reader->head()['outputGainQ8'] ?? 0);
        if ($q8 !== 0) {
            $gain = pow(10.0, $q8 / (20.0 * 256.0));
        }
    } elseif ($gainQ8 !== '' && (int)$gainQ8 !== 0) {
        $gain = pow(10.0, (int)$gainQ8 / (20.0 * 256.0));
    }
    $decoder = new OpusDecoder($channels, $rate);
    $samplesTotal = 0;
    $nextProgress = 0;
    foreach ($packets as $packet) {
        $samples = $decoder->decodeFloat($packet['data']);
        $trimStart = $packet['trimStartSamples'];
        $trimEnd = $packet['trimEndSamples'];
        if ($trimStart !== 0 || $trimEnd !== 0) {
            $samples = array_slice($samples, $trimStart * $channels, ($decoder->lastSampleCount() - $trimStart - $trimEnd) * $channels);
        }
        if ($gain !== 1.0) {
            foreach ($samples as &$sample) {
                $sample *= $gain;
            }
            unset($sample);
        }
        writeAll(STDOUT, Pcm16::floatsToS16le($samples, $channels));
        $samplesTotal += $packet['durationSamples'] - $trimStart - $trimEnd;
        emitSecondsProgress($samplesTotal / $rate, $nextProgress);
    }
    sendEvent(['ev' => 'done', 'seconds' => $samplesTotal / $rate, 'samples' => $samplesTotal, 'packets' => count($packets)]);
}

/** MP3 → PCM：Mp3L3Decoder 仅支持整文件解码（mp3 解码本身很快），meta 在解码后发出 */
function decodeMp3(string $inputFile): void
{
    $data = file_get_contents($inputFile);
    if ($data === false) {
        throw new RuntimeException('无法读取 MP3 文件');
    }
    $decoded = (new Mp3L3Decoder())->decode($data);
    unset($data);
    $rate = $decoded['sampleRate'];
    $channels = $decoded['channels'];
    sendEvent(['ev' => 'meta', 'sampleRate' => $rate, 'channels' => $channels]);
    $pcm = interleaveS16($decoded['planes'], $channels);
    $length = strlen($pcm);
    writeAll(STDOUT, $pcm);
    unset($pcm);
    sendEvent(['ev' => 'done', 'seconds' => $length / ($channels * 2) / $rate, 'bytes' => $length]);
}

// ---------------------------------------------------------------- encode

/** PCM → WAV：先写 size=0 头，流式写 data，EOF 后回卷补写真头再 rename（镜像 Pcm2Wav） */
function encodeWav(string $outputFile, int $rate, int $channels): void
{
    if ($rate < 1 || $rate > 384000) {
        throw new InvalidArgumentException('PCM 采样率无效');
    }
    if ($channels < 1 || $channels > 32) {
        throw new InvalidArgumentException('PCM 声道数无效');
    }
    $dir = dirname($outputFile);
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new RuntimeException("无法创建输出目录: {$dir}");
    }
    $part = $outputFile . '.part.' . bin2hex(random_bytes(6));
    $blockAlign = $channels * 2;
    $output = fopen($part, 'wb');
    if ($output === false) {
        throw new RuntimeException("无法创建 WAV 文件: {$part}");
    }
    try {
        writeAll($output, wavHeader(0, $rate, $blockAlign));
        $dataBytes = 0;
        $pending = '';
        while (!feof(STDIN)) {
            $data = fread(STDIN, 1024 * 1024);
            if ($data === false) {
                throw new RuntimeException('读取 PCM 数据失败');
            }
            if ($data === '') {
                continue;
            }
            $pending .= $data;
            $usable = intdiv(strlen($pending), $blockAlign) * $blockAlign;
            if ($usable === 0) {
                continue;
            }
            writeAll($output, substr($pending, 0, $usable));
            $dataBytes += $usable;
            $pending = substr($pending, $usable);
        }
        if ($pending !== '') {
            throw new RuntimeException('PCM 数据长度不是完整采样帧的整数倍');
        }
        if (fseek($output, 0, SEEK_SET) !== 0) {
            throw new RuntimeException('无法回写 WAV 文件头');
        }
        writeAll($output, wavHeader($dataBytes, $rate, $blockAlign));
        fclose($output);
        if (!rename($part, $outputFile)) {
            throw new RuntimeException("无法生成 WAV 文件: {$outputFile}");
        }
        sendEvent([
            'ev' => 'done',
            'sampleRate' => $rate,
            'channels' => $channels,
            'bitsPerSample' => 16,
            'bytes' => $dataBytes,
            'duration' => $dataBytes / $blockAlign / $rate,
        ]);
    } finally {
        if (is_resource($output)) {
            fclose($output);
        }
        if (is_file($part)) {
            @unlink($part);
        }
    }
}

/** PCM → MP3：按采样帧对齐缓冲后 encodeS16le，EOF 后 flush（镜像 Wav2Mp3） */
function encodeMp3(string $outputFile, int $rate, int $channels, int $bitrate): void
{
    $encoder = new Encoder(new Config($rate, $channels, $bitrate));
    $dir = dirname($outputFile);
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new RuntimeException("无法创建输出目录: {$dir}");
    }
    $part = $outputFile . '.part.' . bin2hex(random_bytes(6));
    $output = fopen($part, 'wb');
    if ($output === false) {
        throw new RuntimeException("无法创建 MP3 文件: {$part}");
    }
    try {
        $frameBytes = $channels * 2;
        $pending = '';
        $bytes = 0;
        while (!feof(STDIN)) {
            $data = fread(STDIN, 1024 * 1024);
            if ($data === false) {
                throw new RuntimeException('读取 PCM 数据失败');
            }
            if ($data === '') {
                continue;
            }
            $pending .= $data;
            $usable = intdiv(strlen($pending), $frameBytes) * $frameBytes;
            if ($usable === 0) {
                continue;
            }
            writeAll($output, $encoder->encodeS16le(substr($pending, 0, $usable)));
            $pending = substr($pending, $usable);
        }
        if ($pending !== '') {
            throw new RuntimeException('PCM 数据不是完整采样帧的整数倍');
        }
        writeAll($output, $encoder->flush());
        $bytes = filesize_stats($part);
        if ($bytes === 0) {
            throw new RuntimeException('未生成输出');
        }
        fclose($output);
        if (!rename($part, $outputFile)) {
            throw new RuntimeException("无法生成 MP3 文件: {$outputFile}");
        }
        sendEvent([
            'ev' => 'done',
            'sampleRate' => $rate,
            'channels' => $channels,
            'bitrate' => $bitrate,
            'bytes' => $bytes,
            'frames' => $encoder->frameCount(),
        ]);
    } finally {
        if (is_resource($output)) {
            fclose($output);
        }
        if (is_file($part)) {
            @unlink($part);
        }
    }
}

/** PCM → AAC-LC ADTS：码率由父进程按声道数折算后传入（镜像 Wav2Aac/Mp32Aac） */
function encodeAac(string $outputFile, int $rate, int $channels, int $bitrate): void
{
    if ($rate !== 48000) {
        throw new InvalidArgumentException('AacLcEncoder 只支持 48000 Hz 输入');
    }
    $encoder = new AacLcEncoder($bitrate, $channels);
    $dir = dirname($outputFile);
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new RuntimeException("无法创建输出目录: {$dir}");
    }
    $part = $outputFile . '.part.' . bin2hex(random_bytes(6));
    $output = fopen($part, 'wb');
    if ($output === false) {
        throw new RuntimeException("无法创建 AAC 文件: {$part}");
    }
    try {
        $frameBytes = $channels * 2;
        $pending = '';
        while (!feof(STDIN)) {
            $data = fread(STDIN, 1024 * 1024);
            if ($data === false) {
                throw new RuntimeException('读取 PCM 数据失败');
            }
            if ($data === '') {
                continue;
            }
            $pending .= $data;
            $usable = intdiv(strlen($pending), $frameBytes) * $frameBytes;
            if ($usable === 0) {
                continue;
            }
            writeAll($output, $encoder->encodeS16le(substr($pending, 0, $usable)));
            $pending = substr($pending, $usable);
        }
        if ($pending !== '') {
            throw new RuntimeException('PCM 数据不是完整采样帧的整数倍');
        }
        writeAll($output, $encoder->flush());
        $bytes = filesize_stats($part);
        if ($bytes === 0) {
            throw new RuntimeException('未生成输出');
        }
        fclose($output);
        if (!rename($part, $outputFile)) {
            throw new RuntimeException("无法生成 AAC 文件: {$outputFile}");
        }
        sendEvent([
            'ev' => 'done',
            'sampleRate' => $rate,
            'channels' => $channels,
            'bitrate' => $bitrate,
            'bytes' => $bytes,
            'frames' => $encoder->frameCount(),
        ]);
    } finally {
        if (is_resource($output)) {
            fclose($output);
        }
        if (is_file($part)) {
            @unlink($part);
        }
    }
}

/**
 * PCM → Ogg Opus：48kHz 单声道 CELT，立体声在编码侧流式下混（镜像 Pcm2Opus + Mp32Opus 下混语义）
 */
function encodeOpus(string $outputFile, int $rate, int $channels, int $preSkip): void
{
    if ($rate !== 48000) {
        throw new InvalidArgumentException('当前纯 PHP CELT 编码器只支持 48000 Hz');
    }
    if ($channels !== 1 && $channels !== 2) {
        throw new InvalidArgumentException('当前纯 PHP Opus 编码器只支持单声道或双声道输入');
    }
    if ($preSkip < 0 || $preSkip > 65535) {
        throw new InvalidArgumentException('Opus pre-skip 无效');
    }
    $dir = dirname($outputFile);
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new RuntimeException("无法创建输出目录: {$dir}");
    }
    $part = $outputFile . '.part.' . bin2hex(random_bytes(6));
    $output = fopen($part, 'wb');
    if ($output === false) {
        throw new RuntimeException("无法写入 Opus 文件: {$part}");
    }
    try {
        $frameEncoder = new CeltFrameEncoder(1);
        $writer = new OggOpusWriter(1, 48000, $preSkip);
        $granule = $preSkip;
        $inputSamples = 0;
        $packets = 0;
        $nextProgress = 0;
        $pending = '';        // 单声道 PCM 缓冲（按 960 采样帧切片）
        $stereoRemainder = ''; // 立体声分块后不足 4 字节的尾部
        while (!feof(STDIN)) {
            $data = fread(STDIN, 1024 * 1024);
            if ($data === false) {
                throw new RuntimeException('读取 PCM 数据失败');
            }
            if ($data === '') {
                continue;
            }
            if ($channels === 2) {
                $buffer = $stereoRemainder . $data;
                $usable = strlen($buffer) - (strlen($buffer) % 4);
                $stereoRemainder = substr($buffer, $usable);
                $pending .= downmixStereoChunk(substr($buffer, 0, $usable));
            } else {
                $pending .= $data;
            }
            while (strlen($pending) >= 1920) {
                $frame = substr($pending, 0, 1920);
                $pending = substr($pending, 1920);
                $writer->writePacket($frameEncoder->encodePacket(decodeS16leMono($frame)), $granule += 960);
                $inputSamples += 960;
                ++$packets;
                if ($packets - $nextProgress >= 2000) {
                    sendEvent(['ev' => 'progress', 'packets' => $packets, 'seconds' => $inputSamples / 48000]);
                    $nextProgress = $packets;
                }
            }
        }
        if ($stereoRemainder !== '') {
            throw new RuntimeException('立体声 PCM 数据不完整');
        }
        // 尾帧补零（镜像 Pcm2Opus：inputSamples 只计实际输入采样数）
        if ($pending !== '' || $packets === 0) {
            $samples = decodeS16leMono($pending);
            $length = count($samples);
            $samples = array_pad($samples, 960, 0.0);
            $writer->writePacket($frameEncoder->encodePacket($samples), $granule += 960);
            $inputSamples += $length;
            ++$packets;
        }
        $data = $writer->finish($preSkip + $inputSamples);
        if (file_put_contents($part, $data) !== strlen($data)) {
            throw new RuntimeException("无法写入 Opus 文件: {$part}");
        }
        if (!rename($part, $outputFile)) {
            throw new RuntimeException("无法生成 Opus 文件: {$outputFile}");
        }
        sendEvent([
            'ev' => 'done',
            'sampleRate' => 48000,
            'channels' => 1,
            'preSkip' => $preSkip,
            'bytes' => strlen($data),
            'packets' => $packets,
            'samples' => $inputSamples,
            'duration' => $inputSamples / 48000,
        ]);
    } finally {
        if (is_resource($output)) {
            fclose($output);
        }
        if (is_file($part)) {
            @unlink($part);
        }
    }
}

// ---------------------------------------------------------------- helpers

/** 每 20 秒音频发一次 progress（控制 stderr 总量 < 管道缓冲） */
function emitSecondsProgress(float $seconds, int &$nextProgress): void
{
    if ($seconds >= $nextProgress) {
        sendEvent(['ev' => 'progress', 'seconds' => $seconds]);
        $nextProgress = (int)floor($seconds / 20) * 20 + 20;
    }
}

/** 立体声 S16LE 块 → 单声道 S16LE（与 Mp32Opus::downmixStereoToMono 逐样本语义一致） */
function downmixStereoChunk(string $data): string
{
    $out = '';
    for ($offset = 0, $length = strlen($data); $offset < $length; $offset += 4) {
        $left = unpack('v', substr($data, $offset))[1];
        $right = unpack('v', substr($data, $offset + 2))[1];
        if ($left >= 32768) $left -= 65536;
        if ($right >= 32768) $right -= 65536;
        $mono = (int)max(-32768, min(32767, round(($left + $right) * 0.5)));
        $out .= pack('v', $mono < 0 ? $mono + 65536 : $mono);
    }
    return $out;
}

/** 单声道 S16LE → float[]（镜像 Pcm2Opus::decodeS16le） */
function decodeS16leMono(string $data): array
{
    $samples = [];
    for ($offset = 0, $length = strlen($data); $offset < $length; $offset += 2) {
        $value = unpack('v', substr($data, $offset))[1];
        if ($value >= 32768) {
            $value -= 65536;
        }
        $samples[] = $value / 32768.0;
    }
    return $samples;
}

/** planar float → 交错 S16LE（镜像 Mp32Pcm::interleaveS16） */
function interleaveS16(array $planes, int $channels): string
{
    $frames = count($planes[0]);
    $out = '';
    for ($n = 0; $n < $frames; $n++) {
        for ($ch = 0; $ch < $channels; $ch++) {
            $v = (int)round($planes[$ch][$n] * 32767.0);
            if ($v > 32767) $v = 32767;
            else if ($v < -32768) $v = -32768;
            $out .= pack('v', $v < 0 ? $v + 65536 : $v);
        }
    }
    return $out;
}

/** 44 字节 WAV 头（镜像 Pcm2Wav::header） */
function wavHeader(int $dataBytes, int $rate, int $blockAlign): string
{
    return 'RIFF'
        . pack('V', 36 + $dataBytes)
        . 'WAVE'
        . 'fmt '
        . pack('VvvVVvv', 16, 1, intdiv($blockAlign, 2), $rate, $rate * $blockAlign, $blockAlign, 16)
        . 'data'
        . pack('V', $dataBytes);
}

/** 文件当前大小（rename 前统计 part 文件字节数） */
function filesize_stats(string $file): int
{
    clearstatcache(true, $file);
    $size = filesize($file);
    return $size === false ? 0 : $size;
}

/** 解析 WAV 头（镜像 Wav2Pcm::readFormat） */
function readWavFormat($input): array
{
    $readExactly = function ($input, int $length) use (&$readExactly): string {
        $data = '';
        while (strlen($data) < $length && !feof($input)) {
            $part = fread($input, $length - strlen($data));
            if ($part === false || $part === '') {
                break;
            }
            $data .= $part;
        }
        if ($data !== '' && strlen($data) !== $length) {
            throw new InvalidArgumentException('WAV 文件已截断');
        }
        return $data;
    };
    $riff = $readExactly($input, 12);
    if (substr($riff, 0, 4) !== 'RIFF' || substr($riff, 8, 4) !== 'WAVE') {
        throw new InvalidArgumentException('无效的 WAV 文件');
    }
    $fmt = null;
    $dataSize = null;
    while (!feof($input)) {
        $chunk = $readExactly($input, 8);
        if ($chunk === '') {
            break;
        }
        $size = unpack('V', substr($chunk, 4, 4))[1];
        if ($size < 0) {
            throw new InvalidArgumentException('WAV 数据块长度无效');
        }
        if (substr($chunk, 0, 4) === 'fmt ') {
            $body = $readExactly($input, $size);
            if (strlen($body) < 16) {
                throw new InvalidArgumentException('WAV fmt 数据块不完整');
            }
            $fmt = unpack('vaudioFormat/vchannels/VsampleRate/VbyteRate/vblockAlign/vbitsPerSample', substr($body, 0, 16));
        } elseif (substr($chunk, 0, 4) === 'data') {
            $dataSize = $size;
            break;
        } else {
            if ($size > 0 && fseek($input, $size, SEEK_CUR) !== 0) {
                throw new InvalidArgumentException('WAV 数据块已截断');
            }
        }
        if ($size % 2 !== 0) {
            fseek($input, 1, SEEK_CUR);
        }
    }
    if ($fmt === null || $dataSize === null) {
        throw new InvalidArgumentException('WAV 缺少 fmt 或 data 数据块');
    }
    if ($fmt['audioFormat'] !== 1) {
        throw new InvalidArgumentException('只支持未压缩 PCM WAV');
    }
    if ($fmt['channels'] < 1 || $fmt['sampleRate'] < 1 || $fmt['bitsPerSample'] < 1 || $fmt['blockAlign'] < 1) {
        throw new InvalidArgumentException('WAV 音频参数无效');
    }
    if ($dataSize % $fmt['blockAlign'] !== 0) {
        throw new InvalidArgumentException('WAV PCM 数据不是完整采样帧的整数倍');
    }
    return [
        'audioFormat' => $fmt['audioFormat'],
        'channels' => $fmt['channels'],
        'sampleRate' => $fmt['sampleRate'],
        'blockAlign' => $fmt['blockAlign'],
        'bitsPerSample' => $fmt['bitsPerSample'],
        'dataSize' => $dataSize,
    ];
}
