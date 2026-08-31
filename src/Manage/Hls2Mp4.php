<?php

namespace Xiaosongshu\Flv2mp4\Manage;

use Xiaosongshu\Flv2mp4\Flv\SPSParser;

/**
 * @purpose 将 H.264/AAC MPEG-TS HLS 重新封装为 MP4。
 * @author yanglong
 * @time 2026年8月31日12:31:34
 */
class Hls2Mp4
{
    private const TS_PACKET_SIZE = 188;
    private const VIDEO_TIMESCALE = 90000;

    private string $outputFile;
    /** @var resource|null */
    private $mdatHandle = null;
    private string $mdatPath = '';
    private int $mdatSize = 0;

    private array $pesBuffers = [];
    private array $continuityCounters = [];
    private array $videoSamples = [];
    private array $audioSamples = [];
    private string $sps = '';
    private string $pps = '';
    private string $audioSpecificConfig = '';
    private int $videoWidth = 0;
    private int $videoHeight = 0;
    private int $audioSampleRate = 44100;
    private int $audioChannels = 2;
    private ?int $timestampReference = null;
    private string $audioCarry = '';
    private ?int $audioCarryPts = null;

    public function __construct(string $outputFile)
    {
        $this->outputFile = $outputFile;
        $directory = dirname($outputFile);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new \RuntimeException("无法创建输出目录: {$directory}");
        }
    }

    public function run(string $m3u8File): bool
    {
        if (!is_file($m3u8File)) {
            throw new \RuntimeException("M3U8文件不存在: {$m3u8File}");
        }

        $segments = $this->parseM3u8($m3u8File);
        if ($segments === []) {
            throw new \RuntimeException('未找到TS文件');
        }

        $this->resetState();
        $this->mdatPath = tempnam(dirname($this->outputFile), '.hls2mp4-mdat-');
        if ($this->mdatPath === false) {
            throw new \RuntimeException('无法创建临时媒体文件');
        }
        $this->mdatHandle = @fopen($this->mdatPath, 'w+b');
        if ($this->mdatHandle === false) {
            @unlink($this->mdatPath);
            $this->mdatPath = '';
            throw new \RuntimeException('无法打开临时媒体文件');
        }

        try {
            $baseDirectory = dirname($m3u8File);
            foreach ($segments as $segment) {
                $this->processTsFile($this->resolveSegmentPath($baseDirectory, $segment));
            }
            $this->flushPesBuffers();

            if ($this->videoSamples === [] && $this->audioSamples === []) {
                throw new \RuntimeException('未找到有效的 H.264/AAC sample');
            }
            if ($this->videoSamples !== [] && ($this->sps === '' || $this->pps === '')) {
                throw new \RuntimeException('视频轨道缺少 SPS/PPS');
            }
            if ($this->audioSamples !== [] && $this->audioSpecificConfig === '') {
                throw new \RuntimeException('音频轨道缺少 AudioSpecificConfig');
            }
            if (!fflush($this->mdatHandle)) {
                throw new \RuntimeException('刷新临时媒体文件失败');
            }

            $this->buildMp4();
            return true;
        } finally {
            if (is_resource($this->mdatHandle)) {
                fclose($this->mdatHandle);
            }
            $this->mdatHandle = null;
            if ($this->mdatPath !== '') {
                @unlink($this->mdatPath);
                $this->mdatPath = '';
            }
        }
    }

    private function resetState(): void
    {
        $this->mdatSize = 0;
        $this->pesBuffers = [];
        $this->continuityCounters = [];
        $this->videoSamples = [];
        $this->audioSamples = [];
        $this->sps = '';
        $this->pps = '';
        $this->audioSpecificConfig = '';
        $this->videoWidth = 0;
        $this->videoHeight = 0;
        $this->audioSampleRate = 44100;
        $this->audioChannels = 2;
        $this->timestampReference = null;
        $this->audioCarry = '';
        $this->audioCarryPts = null;
    }

    private function parseM3u8(string $m3u8File): array
    {
        $content = file_get_contents($m3u8File);
        if ($content === false) {
            throw new \RuntimeException("无法读取M3U8文件: {$m3u8File}");
        }

        $segments = [];
        foreach (preg_split('/\r?\n/', $content) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $path = parse_url($line, PHP_URL_PATH);
            if (is_string($path) && strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'ts') {
                $segments[] = $line;
            }
        }
        return $segments;
    }

    private function resolveSegmentPath(string $baseDirectory, string $segment): string
    {
        $path = parse_url($segment, PHP_URL_PATH);
        $path = is_string($path) ? rawurldecode($path) : rawurldecode($segment);
        if (stripos($path, 'file://') === 0) {
            $path = substr($path, 7);
        }
        if (preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/]{2})/', $path)) {
            return $path;
        }
        return $baseDirectory . DIRECTORY_SEPARATOR
            . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($path, '/\\'));
    }

    private function processTsFile(string $path): void
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("TS文件不存在或无法读取: {$path}");
        }

        try {
            while (($packet = $this->readPacket($handle)) !== null) {
                $this->processTsPacket($packet);
            }
        } finally {
            fclose($handle);
        }
    }

    private function readPacket($handle): ?string
    {
        $packet = '';
        while (strlen($packet) < self::TS_PACKET_SIZE && !feof($handle)) {
            $chunk = fread($handle, self::TS_PACKET_SIZE - strlen($packet));
            if ($chunk === false) {
                throw new \RuntimeException('读取TS packet失败');
            }
            if ($chunk === '') {
                break;
            }
            $packet .= $chunk;
        }
        if ($packet === '') {
            return null;
        }
        if (strlen($packet) !== self::TS_PACKET_SIZE) {
            throw new \RuntimeException('TS文件末尾包含不完整的packet');
        }
        if ($packet[0] !== "\x47") {
            throw new \RuntimeException('TS packet同步字节错误');
        }
        return $packet;
    }

    private function processTsPacket(string $packet): void
    {
        $second = ord($packet[1]);
        if (($second & 0x80) !== 0) {
            return;
        }
        $pid = (($second & 0x1f) << 8) | ord($packet[2]);
        $adaptationControl = (ord($packet[3]) >> 4) & 0x03;
        if ($adaptationControl === 0 || $adaptationControl === 2) {
            return;
        }

        $offset = 4;
        if ($adaptationControl === 3) {
            $offset += ord($packet[4]) + 1;
        }
        if ($offset >= self::TS_PACKET_SIZE) {
            return;
        }

        $counter = ord($packet[3]) & 0x0f;
        if (isset($this->continuityCounters[$pid])) {
            $previous = $this->continuityCounters[$pid];
            if ($counter === $previous) {
                return;
            }
            if ($counter !== (($previous + 1) & 0x0f)) {
                unset($this->pesBuffers[$pid]);
            }
        }
        $this->continuityCounters[$pid] = $counter;

        $payload = substr($packet, $offset);
        if (($second & 0x40) !== 0) {
            if (isset($this->pesBuffers[$pid])) {
                $this->processPes($this->pesBuffers[$pid]);
                unset($this->pesBuffers[$pid]);
            }
            if (strncmp($payload, "\x00\x00\x01", 3) === 0) {
                $this->pesBuffers[$pid] = $payload;
            }
        } elseif (isset($this->pesBuffers[$pid])) {
            $this->pesBuffers[$pid] .= $payload;
        }
    }

    private function flushPesBuffers(): void
    {
        foreach ($this->pesBuffers as $pesData) {
            $this->processPes($pesData);
        }
        $this->pesBuffers = [];
    }

    private function processPes(string $data): void
    {
        if (strlen($data) < 9 || strncmp($data, "\x00\x00\x01", 3) !== 0) {
            return;
        }
        $pesLength = unpack('n', substr($data, 4, 2))[1];
        if ($pesLength > 0) {
            $data = substr($data, 0, min(strlen($data), $pesLength + 6));
        }

        $streamId = ord($data[3]);
        $flags = ord($data[7]) >> 6;
        $headerDataLength = ord($data[8]);
        $payloadOffset = 9 + $headerDataLength;
        if (strlen($data) < $payloadOffset) {
            return;
        }

        $pts = (($flags & 0x02) !== 0 && $headerDataLength >= 5)
            ? $this->decodeTimestamp(substr($data, 9, 5)) : null;
        $dts = (($flags & 0x01) !== 0 && $headerDataLength >= 10)
            ? $this->decodeTimestamp(substr($data, 14, 5)) : $pts;
        if ($dts !== null) {
            $dts = $this->unwrapTimestamp($dts);
            if ($pts !== null) {
                $pts = $this->unwrapNear($pts, $dts);
            }
        } elseif ($pts !== null) {
            $pts = $this->unwrapTimestamp($pts);
        }

        $payload = substr($data, $payloadOffset);
        if ($streamId >= 0xe0 && $streamId <= 0xef) {
            $this->processVideoPes($payload, $dts, $pts);
        } elseif ($streamId >= 0xc0 && $streamId <= 0xdf) {
            $this->processAudioPes($payload, $pts);
        }
    }

    private function decodeTimestamp(string $data): int
    {
        return (((ord($data[0]) >> 1) & 0x07) << 30)
            | (ord($data[1]) << 22)
            | (((ord($data[2]) >> 1) & 0x7f) << 15)
            | (ord($data[3]) << 7)
            | ((ord($data[4]) >> 1) & 0x7f);
    }

    private function unwrapTimestamp(int $timestamp): int
    {
        if ($this->timestampReference === null) {
            return $this->timestampReference = $timestamp;
        }
        $timestamp = $this->unwrapNear($timestamp, $this->timestampReference);
        $this->timestampReference = max($this->timestampReference, $timestamp);
        return $timestamp;
    }

    private function unwrapNear(int $timestamp, int $reference): int
    {
        $wrap = 1 << 33;
        return $timestamp + (int)round(($reference - $timestamp) / $wrap) * $wrap;
    }

    private function processVideoPes(string $payload, ?int $dts, ?int $pts): void
    {
        if ($payload === '' || $dts === null) {
            return;
        }

        $sampleData = '';
        $hasSlice = false;
        $keyframe = false;
        foreach ($this->extractAnnexBNalus($payload) as $nalu) {
            if ($nalu === '') {
                continue;
            }
            $type = ord($nalu[0]) & 0x1f;
            if ($type === 7) {
                $this->sps = $nalu;
                $this->parseVideoDimensions($nalu);
                continue;
            }
            if ($type === 8) {
                $this->pps = $nalu;
                continue;
            }
            if ($type >= 1 && $type <= 5) {
                $hasSlice = true;
                $keyframe = $keyframe || $type === 5;
            }
            if ($type !== 9) {
                $sampleData .= pack('N', strlen($nalu)) . $nalu;
            }
        }

        if ($hasSlice && $sampleData !== '') {
            $this->videoSamples[] = $this->writeSample($sampleData, [
                'dts' => $dts,
                'pts' => $pts ?? $dts,
                'keyframe' => $keyframe,
            ]);
        }
    }

    private function extractAnnexBNalus(string $data): array
    {
        if (!preg_match_all('/(?:\x00\x00\x00\x01|\x00\x00\x01)/', $data, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }
        $nalus = [];
        $count = count($matches[0]);
        for ($i = 0; $i < $count; $i++) {
            $start = $matches[0][$i][1] + strlen($matches[0][$i][0]);
            $end = $i + 1 < $count ? $matches[0][$i + 1][1] : strlen($data);
            if ($end > $start) {
                $nalus[] = substr($data, $start, $end - $start);
            }
        }
        return $nalus;
    }

    private function parseVideoDimensions(string $sps): void
    {
        try {
            $info = SPSParser::parseSPS($sps);
            $this->videoWidth = (int)($info['present_size']['width'] ?? $info['codec_size']['width'] ?? 0);
            $this->videoHeight = (int)($info['present_size']['height'] ?? $info['codec_size']['height'] ?? 0);
        } catch (\Throwable $e) {
            $this->videoWidth = max(1, $this->videoWidth);
            $this->videoHeight = max(1, $this->videoHeight);
        }
    }

    private function processAudioPes(string $payload, ?int $pts): void
    {
        if ($pts === null) {
            return;
        }
        if ($this->audioCarry !== '') {
            $payload = $this->audioCarry . $payload;
            $pts = $this->audioCarryPts ?? $pts;
            $this->audioCarry = '';
            $this->audioCarryPts = null;
        }

        $offset = 0;
        $frameIndex = 0;
        $length = strlen($payload);
        while ($offset + 7 <= $length) {
            if (ord($payload[$offset]) !== 0xff || (ord($payload[$offset + 1]) & 0xf6) !== 0xf0) {
                $offset++;
                continue;
            }
            $headerLength = (ord($payload[$offset + 1]) & 1) !== 0 ? 7 : 9;
            $frameLength = ((ord($payload[$offset + 3]) & 3) << 11)
                | (ord($payload[$offset + 4]) << 3)
                | ((ord($payload[$offset + 5]) >> 5) & 7);
            if ($frameLength < $headerLength) {
                $offset++;
                continue;
            }
            if ($offset + $frameLength > $length) {
                $this->audioCarry = substr($payload, $offset);
                $this->audioCarryPts = $pts + (int)round($frameIndex * 1024 * 90000 / $this->audioSampleRate);
                break;
            }

            $frequencyIndex = (ord($payload[$offset + 2]) >> 2) & 0x0f;
            $rates = [96000, 88200, 64000, 48000, 44100, 32000, 24000, 22050, 16000, 12000, 11025, 8000, 7350];
            $sampleRate = $rates[$frequencyIndex] ?? 44100;
            $channels = ((ord($payload[$offset + 2]) & 1) << 2)
                | ((ord($payload[$offset + 3]) >> 6) & 3);
            $profile = ((ord($payload[$offset + 2]) >> 6) & 3) + 1;
            if ($this->audioSpecificConfig === '') {
                $this->audioSampleRate = $sampleRate;
                $this->audioChannels = max(1, $channels);
                $this->audioSpecificConfig = pack('n',
                    ($profile << 11) | ($frequencyIndex << 7) | ($this->audioChannels << 3)
                );
            }

            $samplePts = $pts + (int)round($frameIndex * 1024 * 90000 / $sampleRate);
            $sample = substr($payload, $offset + $headerLength, $frameLength - $headerLength);
            $this->audioSamples[] = $this->writeSample($sample, ['dts' => $samplePts]);
            $frameIndex++;
            $offset += $frameLength;
        }
    }

    private function writeSample(string $data, array $sample): array
    {
        $sample['offset'] = $this->mdatSize;
        $sample['size'] = strlen($data);
        $written = 0;
        while ($written < $sample['size']) {
            $count = fwrite($this->mdatHandle, substr($data, $written));
            if ($count === false || $count === 0) {
                throw new \RuntimeException('写入临时媒体文件失败');
            }
            $written += $count;
        }
        $this->mdatSize += $sample['size'];
        return $sample;
    }

    private function buildMp4(): void
    {
        $ftyp = $this->box('ftyp', 'isom' . pack('N', 1) . 'isomavc1mp42');
        $mdatHeader = $this->buildMdatHeader();
        $moov = '';
        for ($i = 0; $i < 3; $i++) {
            $mediaBase = strlen($ftyp) + strlen($moov) + strlen($mdatHeader);
            $next = $this->buildMoov($mediaBase);
            if (strlen($next) === strlen($moov)) {
                $moov = $next;
                break;
            }
            $moov = $next;
        }

        $temporaryOutput = tempnam(dirname($this->outputFile), '.hls2mp4-output-');
        if ($temporaryOutput === false) {
            throw new \RuntimeException('无法创建MP4临时输出文件');
        }
        $output = @fopen($temporaryOutput, 'wb');
        if ($output === false) {
            @unlink($temporaryOutput);
            throw new \RuntimeException('无法打开MP4临时输出文件');
        }

        try {
            $this->writeAll($output, $ftyp . $moov . $mdatHeader);
            rewind($this->mdatHandle);
            if (stream_copy_to_stream($this->mdatHandle, $output) !== $this->mdatSize) {
                throw new \RuntimeException('复制媒体数据失败');
            }
            if (!fflush($output)) {
                throw new \RuntimeException('刷新MP4文件失败');
            }
            fclose($output);
            $output = null;
            if (is_file($this->outputFile) && !@unlink($this->outputFile)) {
                throw new \RuntimeException("无法替换输出文件: {$this->outputFile}");
            }
            if (!@rename($temporaryOutput, $this->outputFile)) {
                throw new \RuntimeException("无法完成输出文件: {$this->outputFile}");
            }
        } finally {
            if (is_resource($output)) {
                fclose($output);
            }
            if (is_file($temporaryOutput)) {
                @unlink($temporaryOutput);
            }
        }
    }

    private function buildMdatHeader(): string
    {
        if ($this->mdatSize + 8 <= 0xffffffff) {
            return pack('N', $this->mdatSize + 8) . 'mdat';
        }
        return pack('N', 1) . 'mdat' . $this->packUint64($this->mdatSize + 16);
    }

    private function buildMoov(int $mediaBase): string
    {
        $videoDuration = $this->trackDuration($this->videoSamples, 3000);
        $audioDuration90k = $this->trackDuration(
            $this->audioSamples,
            (int)round(1024 * self::VIDEO_TIMESCALE / $this->audioSampleRate)
        );
        $movieDuration = max($videoDuration, $audioDuration90k);
        $tracks = [];
        if ($this->videoSamples !== []) {
            $tracks[] = $this->buildTrack(1, 'vide', self::VIDEO_TIMESCALE, $videoDuration, $movieDuration, $mediaBase, $this->videoSamples);
        }
        if ($this->audioSamples !== []) {
            $audioDuration = (int)round($audioDuration90k * $this->audioSampleRate / self::VIDEO_TIMESCALE);
            $tracks[] = $this->buildTrack(2, 'soun', $this->audioSampleRate, $audioDuration, $movieDuration, $mediaBase, $this->audioSamples);
        }
        return $this->box('moov', $this->buildMvhd($movieDuration), ...$tracks);
    }

    private function trackDuration(array $samples, int $fallback): int
    {
        $count = count($samples);
        if ($count === 0) {
            return 0;
        }
        $lastDelta = $count > 1
            ? max(1, $samples[$count - 1]['dts'] - $samples[$count - 2]['dts'])
            : $fallback;
        return max(0, $samples[$count - 1]['dts'] - $samples[0]['dts']) + $lastDelta;
    }

    private function buildTrack(
        int $id,
        string $handler,
        int $timescale,
        int $trackDuration,
        int $movieDuration,
        int $mediaBase,
        array $samples
    ): string {
        $video = $handler === 'vide';
        $stblParts = [
            $video ? $this->buildVideoStsd() : $this->buildAudioStsd(),
            $this->buildStts($samples, $timescale, $video ? 3000 : 1024),
        ];
        if ($video) {
            $stblParts[] = $this->buildCtts($samples);
        }
        $stblParts[] = $this->box('stsc', pack('N5', 0, 1, 1, 1, 1));
        $stblParts[] = $this->buildStsz($samples);
        $stblParts[] = $this->buildChunkOffsets($samples, $mediaBase);
        if ($video) {
            $stblParts[] = $this->buildStss($samples);
        }

        $mediaHeader = $video
            ? $this->box('vmhd', pack('N3', 1, 0, 0))
            : $this->box('smhd', pack('N2', 0, 0));
        $dref = $this->box('dref', pack('N2', 0, 1), $this->box('url ', pack('N', 1)));
        $minf = $this->box('minf', $mediaHeader, $this->box('dinf', $dref), $this->box('stbl', ...$stblParts));
        $name = $video ? 'VideoHandler' : 'SoundHandler';
        $mdia = $this->box(
            'mdia',
            $this->buildMdhd($timescale, $trackDuration),
            $this->box('hdlr', pack('N2', 0, 0) . $handler . pack('N3', 0, 0, 0) . $name . "\0"),
            $minf
        );
        return $this->box(
            'trak',
            $this->buildTkhd($id, $movieDuration, $video ? $this->videoWidth : 0, $video ? $this->videoHeight : 0, !$video),
            $mdia
        );
    }

    private function buildMvhd(int $duration): string
    {
        $matrix = pack('N9', 0x10000, 0, 0, 0, 0x10000, 0, 0, 0, 0x40000000);
        $data = pack('N6', 0, 0, 0, self::VIDEO_TIMESCALE, $duration, 0x10000)
            . pack('n2', 0x100, 0) . pack('N2', 0, 0) . $matrix
            . pack('N6', 0, 0, 0, 0, 0, 0) . pack('N', 3);
        return $this->box('mvhd', $data);
    }

    private function buildTkhd(int $id, int $duration, int $width, int $height, bool $audio): string
    {
        $matrix = pack('N9', 0x10000, 0, 0, 0, 0x10000, 0, 0, 0, 0x40000000);
        $data = pack('N6', 7, 0, 0, $id, 0, $duration)
            . pack('N2', 0, 0) . pack('n4', 0, 0, $audio ? 0x100 : 0, 0)
            . $matrix . pack('N2', $width << 16, $height << 16);
        return $this->box('tkhd', $data);
    }

    private function buildMdhd(int $timescale, int $duration): string
    {
        return $this->box('mdhd', pack('N6', 0, 0, 0, $timescale, $duration, 0x55c40000));
    }

    private function buildVideoStsd(): string
    {
        $avcc = "\x01" . substr($this->sps, 1, 3) . "\xff\xe1"
            . pack('n', strlen($this->sps)) . $this->sps
            . "\x01" . pack('n', strlen($this->pps)) . $this->pps;
        $visual = str_repeat("\0", 6) . pack('n', 1) . str_repeat("\0", 16)
            . pack('n2', max(1, $this->videoWidth), max(1, $this->videoHeight))
            . pack('N2', 0x00480000, 0x00480000) . pack('N', 0) . pack('n', 1)
            . str_repeat("\0", 32) . pack('n2', 0x18, 0xffff);
        return $this->box('stsd', pack('N2', 0, 1), $this->box('avc1', $visual, $this->box('avcC', $avcc)));
    }

    private function buildAudioStsd(): string
    {
        $audio = str_repeat("\0", 6) . pack('n', 1) . pack('N2', 0, 0)
            . pack('n2', $this->audioChannels, 16) . pack('n2', 0, 0)
            . pack('N', $this->audioSampleRate << 16);
        return $this->box('stsd', pack('N2', 0, 1), $this->box('mp4a', $audio, $this->box('esds', $this->buildEsds())));
    }

    private function buildEsds(): string
    {
        $decoderConfig = "\x04" . $this->descriptorLength(13 + 2 + strlen($this->audioSpecificConfig))
            . "\x40\x15\x00\x00\x00" . pack('N2', 0, 0)
            . "\x05" . $this->descriptorLength(strlen($this->audioSpecificConfig)) . $this->audioSpecificConfig;
        $es = "\x03" . $this->descriptorLength(3 + strlen($decoderConfig) + 3)
            . "\x00\x01\x00" . $decoderConfig . "\x06\x01\x02";
        return pack('N', 0) . $es;
    }

    private function descriptorLength(int $length): string
    {
        $bytes = [($length & 0x7f)];
        while (($length >>= 7) > 0) {
            array_unshift($bytes, ($length & 0x7f) | 0x80);
        }
        return implode('', array_map('chr', $bytes));
    }

    private function buildStts(array $samples, int $timescale, int $fallback): string
    {
        $deltas = [];
        $count = count($samples);
        for ($i = 0; $i < $count - 1; $i++) {
            $deltas[] = max(1, (int)round(($samples[$i + 1]['dts'] - $samples[$i]['dts']) * $timescale / 90000));
        }
        $deltas[] = $count > 1 ? $deltas[$count - 2] : $fallback;
        $entries = $this->compressValues($deltas);
        $data = pack('N2', 0, count($entries));
        foreach ($entries as [$entryCount, $value]) {
            $data .= pack('N2', $entryCount, $value);
        }
        return $this->box('stts', $data);
    }

    private function buildCtts(array $samples): string
    {
        $offsets = array_map(static fn(array $sample): int => $sample['pts'] - $sample['dts'], $samples);
        $version = min($offsets) < 0 ? 0x01000000 : 0;
        $entries = $this->compressValues($offsets);
        $data = pack('N2', $version, count($entries));
        foreach ($entries as [$entryCount, $value]) {
            $data .= pack('N2', $entryCount, $value & 0xffffffff);
        }
        return $this->box('ctts', $data);
    }

    private function compressValues(array $values): array
    {
        $entries = [];
        foreach ($values as $value) {
            $last = count($entries) - 1;
            if ($last >= 0 && $entries[$last][1] === $value) {
                $entries[$last][0]++;
            } else {
                $entries[] = [1, $value];
            }
        }
        return $entries;
    }

    private function buildStsz(array $samples): string
    {
        $data = pack('N3', 0, 0, count($samples));
        foreach ($samples as $sample) {
            $data .= pack('N', $sample['size']);
        }
        return $this->box('stsz', $data);
    }

    private function buildChunkOffsets(array $samples, int $mediaBase): string
    {
        $use64 = $this->mdatSize + $mediaBase > 0xffffffff;
        $data = pack('N2', 0, count($samples));
        foreach ($samples as $sample) {
            $offset = $mediaBase + $sample['offset'];
            $data .= $use64 ? $this->packUint64($offset) : pack('N', $offset);
        }
        return $this->box($use64 ? 'co64' : 'stco', $data);
    }

    private function buildStss(array $samples): string
    {
        $keyframes = [];
        foreach ($samples as $index => $sample) {
            if ($sample['keyframe']) {
                $keyframes[] = $index + 1;
            }
        }
        $data = pack('N2', 0, count($keyframes));
        foreach ($keyframes as $sampleNumber) {
            $data .= pack('N', $sampleNumber);
        }
        return $this->box('stss', $data);
    }

    private function packUint64(int $value): string
    {
        return pack('N2', intdiv($value, 0x100000000), $value % 0x100000000);
    }

    private function writeAll($stream, string $data): void
    {
        $offset = 0;
        $length = strlen($data);
        while ($offset < $length) {
            $written = fwrite($stream, substr($data, $offset));
            if ($written === false || $written === 0) {
                throw new \RuntimeException('写入MP4文件失败');
            }
            $offset += $written;
        }
    }

    private function box(string $type, string ...$parts): string
    {
        $size = 8;
        foreach ($parts as $part) {
            $size += strlen($part);
        }
        return pack('N', $size) . $type . implode('', $parts);
    }
}
