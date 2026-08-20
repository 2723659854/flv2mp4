<?php

namespace Xiaosongshu\Flv2mp4\Manage;

/**
 * 将本地 HLS（H.264 + AAC）转换为 FLV。
 */
class Hls2Flv
{
    private const TS_PACKET_SIZE = 188;
    private const REORDER_WINDOW = 180000; // 90kHz，2 秒
    private const MAX_REORDER_FRAMES = 128;

    private string $outputFile;
    /** @var resource|null */
    private $flvHandle = null;
    private string $sps = '';
    private string $pps = '';
    private string $audioSpecificConfig = '';
    private bool $hasWrittenHeader = false;
    private bool $hasWrittenVideoHeader = false;
    private bool $hasWrittenAudioHeader = false;
    private array $pesBuffers = [];
    private array $continuityCounters = [];
    private array $reorderFrames = [];
    private int $frameSequence = 0;
    private ?int $timestampAnchor = null;
    private ?int $firstTimestamp = null;
    private int $lastFlvTimestamp = 0;

    public function __construct(string $outputFile)
    {
        $this->outputFile = $outputFile;
        $dir = dirname($outputFile);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException("无法创建输出目录: {$dir}");
        }
    }

    public function run(string $m3u8File): bool
    {
        if (!is_file($m3u8File)) {
            throw new \RuntimeException("M3U8文件不存在: {$m3u8File}");
        }

        $tsFiles = $this->parseM3U8($m3u8File);
        if ($tsFiles === []) {
            throw new \RuntimeException('未找到TS文件');
        }

        $temporaryFile = $this->outputFile . '.part';
        $this->flvHandle = @fopen($temporaryFile, 'wb');
        if ($this->flvHandle === false) {
            throw new \RuntimeException("无法创建临时输出文件: {$temporaryFile}");
        }

        try {
            $m3u8Dir = dirname($m3u8File);
            foreach ($tsFiles as $tsFile) {
                $tsPath = $this->resolveTsPath($m3u8Dir, $tsFile);
                if (!is_file($tsPath)) {
                    throw new \RuntimeException("TS文件不存在: {$tsPath}");
                }
                $this->processTSFile($tsPath);
            }

            $this->flushPesBuffers();
            $this->flushReorderFrames(true);
            if (!$this->hasWrittenHeader) {
                $this->writeFLVHeader();
            }
        } catch (\Throwable $e) {
            fclose($this->flvHandle);
            $this->flvHandle = null;
            @unlink($temporaryFile);
            throw $e;
        }

        if (!fflush($this->flvHandle)) {
            fclose($this->flvHandle);
            $this->flvHandle = null;
            @unlink($temporaryFile);
            throw new \RuntimeException('刷新FLV临时文件失败');
        }
        fclose($this->flvHandle);
        $this->flvHandle = null;

        if (is_file($this->outputFile) && !@unlink($this->outputFile)) {
            @unlink($temporaryFile);
            throw new \RuntimeException("无法替换输出文件: {$this->outputFile}");
        }
        if (!@rename($temporaryFile, $this->outputFile)) {
            @unlink($temporaryFile);
            throw new \RuntimeException("无法完成输出文件: {$this->outputFile}");
        }

        return true;
    }

    private function parseM3U8(string $m3u8File): array
    {
        $content = file_get_contents($m3u8File);
        if ($content === false) {
            throw new \RuntimeException("无法读取M3U8文件: {$m3u8File}");
        }

        $tsFiles = [];
        foreach (preg_split('/\r?\n/', $content) as $line) {
            $line = trim($line);
            if ($line !== '' && $line[0] !== '#') {
                $path = parse_url($line, PHP_URL_PATH);
                if (is_string($path) && strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'ts') {
                    $tsFiles[] = $line;
                }
            }
        }
        return $tsFiles;
    }

    private function resolveTsPath(string $m3u8Dir, string $tsFile): string
    {
        $path = parse_url($tsFile, PHP_URL_PATH);
        $path = $path === false ? $tsFile : urldecode($path);
        if (preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/]{2})/', $path)) {
            return $path;
        }
        return $m3u8Dir . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($path, '/\\'));
    }

    private function processTSFile(string $tsFile): void
    {
        $handle = @fopen($tsFile, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("无法打开TS文件: {$tsFile}");
        }

        try {
            while (!feof($handle)) {
                $packet = $this->readPacket($handle);
                if ($packet === null) {
                    break;
                }
                $this->processTSPacket($packet);
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

    private function processTSPacket(string $packet): void
    {
        $second = ord($packet[1]);
        if (($second & 0x80) !== 0) {
            return;
        }

        $pid = (($second & 0x1F) << 8) | ord($packet[2]);
        $afc = (ord($packet[3]) >> 4) & 0x03;
        if ($afc === 0 || $afc === 2) {
            return;
        }

        $payloadOffset = 4;
        if ($afc === 3) {
            $payloadOffset += 1 + ord($packet[4]);
        }
        if ($payloadOffset >= self::TS_PACKET_SIZE) {
            return;
        }

        $counter = ord($packet[3]) & 0x0F;
        if (isset($this->continuityCounters[$pid])) {
            $expected = ($this->continuityCounters[$pid] + 1) & 0x0F;
            if ($counter === $this->continuityCounters[$pid]) {
                return;
            }
            if ($counter !== $expected) {
                unset($this->pesBuffers[$pid]);
            }
        }
        $this->continuityCounters[$pid] = $counter;

        $payload = substr($packet, $payloadOffset);
        $isStart = ($second & 0x40) !== 0;
        if ($isStart) {
            if (isset($this->pesBuffers[$pid])) {
                $this->processCompletePES($this->pesBuffers[$pid]);
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
            $this->processCompletePES($pesData);
        }
        $this->pesBuffers = [];
    }

    private function processCompletePES(string $pesData): void
    {
        if (strlen($pesData) < 9 || strncmp($pesData, "\x00\x00\x01", 3) !== 0) {
            return;
        }

        $pesLength = unpack('n', substr($pesData, 4, 2))[1];
        if ($pesLength > 0) {
            $pesData = substr($pesData, 0, min(strlen($pesData), 6 + $pesLength));
        }

        $streamId = ord($pesData[3]);
        $flags = ord($pesData[7]) >> 6;
        $headerDataLength = ord($pesData[8]);
        $headerLength = 9 + $headerDataLength;
        if (strlen($pesData) < $headerLength) {
            return;
        }

        $pts = null;
        $dts = null;
        if (($flags & 0x02) !== 0 && $headerDataLength >= 5) {
            $pts = $this->decodeTimestamp(substr($pesData, 9, 5));
        }
        if (($flags & 0x01) !== 0 && $headerDataLength >= 10) {
            $dts = $this->decodeTimestamp(substr($pesData, 14, 5));
        }

        $payload = substr($pesData, $headerLength);
        if ($streamId >= 0xE0 && $streamId <= 0xEF) {
            $this->processVideoPES($payload, $pts, $dts);
        } elseif ($streamId >= 0xC0 && $streamId <= 0xDF) {
            $this->processAudioPES($payload, $pts);
        }
    }

    private function decodeTimestamp(string $data): int
    {
        return (((ord($data[0]) >> 1) & 0x07) << 30)
            | (ord($data[1]) << 22)
            | (((ord($data[2]) >> 1) & 0x7F) << 15)
            | (ord($data[3]) << 7)
            | ((ord($data[4]) >> 1) & 0x7F);
    }

    private function processVideoPES(string $payload, ?int $pts, ?int $dts): void
    {
        if ($payload === '' || ($pts === null && $dts === null)) {
            return;
        }

        $nalus = $this->extractNalusFromAnnexB($payload);
        $frameNalus = [];
        $isKeyFrame = false;
        $hasSlice = false;
        foreach ($nalus as $nalu) {
            if ($nalu === '') {
                continue;
            }
            $type = ord($nalu[0]) & 0x1F;
            if ($type === 7) {
                $this->sps = $nalu;
                continue;
            }
            if ($type === 8) {
                $this->pps = $nalu;
                continue;
            }
            if ($type >= 1 && $type <= 5) {
                $hasSlice = true;
                $isKeyFrame = $isKeyFrame || $type === 5;
            }
            if ($type !== 9) {
                $frameNalus[] = $nalu;
            }
        }

        if ($hasSlice && $frameNalus !== []) {
            $dts ??= $pts;
            $pts ??= $dts;
            $this->queueFrame([
                'type' => 9,
                'time' => $dts,
                'pts' => $pts,
                'nalus' => $frameNalus,
                'isKeyFrame' => $isKeyFrame,
            ]);
        }
    }

    private function extractNalusFromAnnexB(string $data): array
    {
        $nalus = [];
        if (!preg_match_all('/(?:\x00\x00\x00\x01|\x00\x00\x01)/', $data, $matches, PREG_OFFSET_CAPTURE)) {
            return $nalus;
        }
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

    private function processAudioPES(string $payload, ?int $pts): void
    {
        if ($payload === '' || $pts === null) {
            return;
        }

        $offset = 0;
        $frameIndex = 0;
        $length = strlen($payload);
        while ($offset + 7 <= $length) {
            if (ord($payload[$offset]) !== 0xFF || (ord($payload[$offset + 1]) & 0xF6) !== 0xF0) {
                $offset++;
                continue;
            }

            $protectionAbsent = ord($payload[$offset + 1]) & 0x01;
            $headerLength = $protectionAbsent ? 7 : 9;
            $frameLength = ((ord($payload[$offset + 3]) & 0x03) << 11)
                | (ord($payload[$offset + 4]) << 3)
                | ((ord($payload[$offset + 5]) >> 5) & 0x07);
            if ($frameLength < $headerLength || $offset + $frameLength > $length) {
                break;
            }

            $header = substr($payload, $offset, 7);
            if ($this->audioSpecificConfig === '') {
                $this->extractAudioSpecificConfig($header);
            }
            $sampleRate = $this->getAdtsSampleRate($header);
            $framePts = $pts + (int)round($frameIndex * 1024 * 90000 / $sampleRate);
            $this->queueFrame([
                'type' => 8,
                'time' => $framePts,
                'data' => substr($payload, $offset + $headerLength, $frameLength - $headerLength),
            ]);
            $frameIndex++;
            $offset += $frameLength;
        }
    }

    private function extractAudioSpecificConfig(string $adtsHeader): void
    {
        $profile = ((ord($adtsHeader[2]) >> 6) & 0x03) + 1;
        $frequencyIndex = (ord($adtsHeader[2]) >> 2) & 0x0F;
        $channelConfig = ((ord($adtsHeader[2]) & 0x01) << 2) | ((ord($adtsHeader[3]) >> 6) & 0x03);
        $this->audioSpecificConfig = pack('n', ($profile << 11) | ($frequencyIndex << 7) | ($channelConfig << 3));
    }

    private function getAdtsSampleRate(string $adtsHeader): int
    {
        $rates = [96000, 88200, 64000, 48000, 44100, 32000, 24000, 22050, 16000, 12000, 11025, 8000, 7350];
        $index = (ord($adtsHeader[2]) >> 2) & 0x0F;
        return $rates[$index] ?? 44100;
    }

    private function queueFrame(array $frame): void
    {
        if ($this->timestampAnchor === null) {
            $this->timestampAnchor = $frame['time'];
        } else {
            $frame['time'] = $this->unwrapTimestamp($frame['time'], $this->timestampAnchor);
            if (isset($frame['pts'])) {
                $frame['pts'] = $this->unwrapTimestamp($frame['pts'], $frame['time']);
            }
            $this->timestampAnchor = max($this->timestampAnchor, $frame['time']);
        }
        $frame['sequence'] = $this->frameSequence++;
        $this->reorderFrames[] = $frame;
        $this->flushReorderFrames(false);
    }

    private function unwrapTimestamp(int $timestamp, int $reference): int
    {
        $wrap = 1 << 33;
        $candidate = $timestamp + (int)round(($reference - $timestamp) / $wrap) * $wrap;
        return $candidate;
    }

    private function flushReorderFrames(bool $all): void
    {
        if ($this->reorderFrames === []) {
            return;
        }

        usort($this->reorderFrames, static function (array $a, array $b): int {
            return ($a['time'] <=> $b['time']) ?: ($a['sequence'] <=> $b['sequence']);
        });

        if ($all) {
            $flushCount = count($this->reorderFrames);
        } else {
            $newestTime = max(array_column($this->reorderFrames, 'time'));
            $flushCount = 0;
            foreach ($this->reorderFrames as $frame) {
                if ($frame['time'] <= $newestTime - self::REORDER_WINDOW) {
                    $flushCount++;
                } else {
                    break;
                }
            }
            $flushCount = max($flushCount, count($this->reorderFrames) - self::MAX_REORDER_FRAMES);
        }

        for ($i = 0; $i < $flushCount; $i++) {
            $this->writeQueuedFrame(array_shift($this->reorderFrames));
        }
    }

    private function writeQueuedFrame(array $frame): void
    {
        if (!$this->hasWrittenHeader) {
            $this->writeFLVHeader();
        }
        if ($this->sps !== '' && $this->pps !== '' && !$this->hasWrittenVideoHeader) {
            $this->writeAVCSequenceHeader();
        }
        if ($this->audioSpecificConfig !== '' && !$this->hasWrittenAudioHeader) {
            $this->writeAACSequenceHeader();
        }

        $this->firstTimestamp ??= $frame['time'];
        $timestamp = max(0, (int)round(($frame['time'] - $this->firstTimestamp) / 90));
        $timestamp = max($timestamp, $this->lastFlvTimestamp);
        $this->lastFlvTimestamp = $timestamp;

        if ($frame['type'] === 9) {
            $cts = (int)round(($frame['pts'] - $frame['time']) / 90);
            $this->writeVideoFrame($frame['nalus'], $frame['isKeyFrame'], $timestamp, $cts);
        } else {
            $this->writeAudioFrame($frame['data'], $timestamp);
        }
    }

    private function writeFLVHeader(): void
    {
        if ($this->hasWrittenHeader) {
            return;
        }
        $flags = ($this->sps !== '' && $this->pps !== '' ? 0x01 : 0)
            | ($this->audioSpecificConfig !== '' ? 0x04 : 0);
        $this->writeBytes("FLV\x01" . chr($flags) . "\x00\x00\x00\x09\x00\x00\x00\x00");
        $this->hasWrittenHeader = true;
    }

    private function writeAVCSequenceHeader(): void
    {
        $config = "\x01" . substr($this->sps, 1, 3) . "\xFF\xE1"
            . pack('n', strlen($this->sps)) . $this->sps . "\x01"
            . pack('n', strlen($this->pps)) . $this->pps;
        $this->writeFLVTag(9, "\x17\x00\x00\x00\x00" . $config, 0);
        $this->hasWrittenVideoHeader = true;
    }

    private function writeAACSequenceHeader(): void
    {
        $this->writeFLVTag(8, "\xAF\x00" . $this->audioSpecificConfig, 0);
        $this->hasWrittenAudioHeader = true;
    }

    private function writeVideoFrame(array $nalus, bool $isKeyFrame, int $timestamp, int $cts): void
    {
        $avccData = '';
        foreach ($nalus as $nalu) {
            $avccData .= pack('N', strlen($nalu)) . $nalu;
        }
        $cts &= 0xFFFFFF;
        $ctsBytes = chr(($cts >> 16) & 0xFF) . chr(($cts >> 8) & 0xFF) . chr($cts & 0xFF);
        $frameHeader = chr((($isKeyFrame ? 1 : 2) << 4) | 7);
        $this->writeFLVTag(9, $frameHeader . "\x01" . $ctsBytes . $avccData, $timestamp);
    }

    private function writeAudioFrame(string $aacData, int $timestamp): void
    {
        $this->writeFLVTag(8, "\xAF\x01" . $aacData, $timestamp);
    }

    private function writeFLVTag(int $tagType, string $data, int $timestamp): void
    {
        $dataSize = strlen($data);
        $timestamp &= 0xFFFFFFFF;
        $header = chr($tagType)
            . substr(pack('N', $dataSize), 1)
            . substr(pack('N', $timestamp), 1)
            . chr(($timestamp >> 24) & 0xFF)
            . "\x00\x00\x00";
        $this->writeBytes($header . $data . pack('N', 11 + $dataSize));
    }

    private function writeBytes(string $data): void
    {
        $length = strlen($data);
        $offset = 0;
        while ($offset < $length) {
            $written = fwrite($this->flvHandle, substr($data, $offset));
            if ($written === false || $written === 0) {
                throw new \RuntimeException('写入FLV临时文件失败');
            }
            $offset += $written;
        }
    }
}
