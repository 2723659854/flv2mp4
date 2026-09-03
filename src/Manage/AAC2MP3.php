<?php

namespace Xiaosongshu\Flv2mp4\Manage;

use Xiaosongshu\Flv2mp4\Aac\AacLcDecoder;
use Xiaosongshu\Flv2mp4\Mp3\Config;
use Xiaosongshu\Flv2mp4\Mp3\Encoder;
use InvalidArgumentException;
use RuntimeException;

/**
 * @purpose aac音频提取工具
 * @author yanglong
 * @time 2026年9月3日17:31:46
 * @note 此文件仅供测试用，目前生成的音频音质不高，存在噪音
 */
final class AAC2MP3
{
    private const RATES = [96000, 88200, 64000, 48000, 44100, 32000, 24000, 22050, 16000, 12000, 11025, 8000, 7350];

    public function process(string $inputFile, string $outputFile, string $format = 'mp3'): array
    {
        if (!in_array(strtolower($format), ['aac', 'mp3'], true)) {
            throw new InvalidArgumentException('输出格式仅允许 aac 或 mp3');
        }
        if (!is_file($inputFile)) throw new RuntimeException("输入文件不存在: {$inputFile}");
        $input = fopen($inputFile, 'rb');
        if (!$input) throw new RuntimeException('无法读取输入文件');
        try {
            $signature = fread($input, 12);
            if ($signature === false) throw new RuntimeException('无法读取输入文件');
            fclose($input);
            if (substr($signature, 0, 3) === 'FLV') {
                $source = $this->fromFlv($inputFile);
            } elseif (substr($signature, 4, 4) === 'ftyp') {
                $source = $this->fromMp4($inputFile);
            } else {
                throw new RuntimeException('仅支持 FLV 或 MP4 输入文件');
            }
        } finally {
            if (is_resource($input)) fclose($input);
        }
        if (!is_array($source) || count($source) !== 2 || !is_string($source[0]) || !is_iterable($source[1])) {
            throw new RuntimeException('无法解析 AAC 音频源');
        }
        [$asc, $frames] = $source;
        [$objectType, $rate, $channels] = $this->asc($asc);
        if ($objectType !== 2) throw new RuntimeException('输入音频不是 AAC-LC');
        if (strtolower($format) === 'mp3' && !in_array($rate, Config::SAMPLE_RATES, true)) {
            throw new RuntimeException("MP3 编码器不支持 {$rate} Hz AAC 音频");
        }
        $dir = dirname($outputFile);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) throw new RuntimeException("无法创建输出目录: {$dir}");
        $part = $outputFile . '.part.' . bin2hex(random_bytes(6));
        $out = fopen($part, 'wb');
        if (!$out) throw new RuntimeException("无法创建输出文件: {$part}");
        $decoder = strtolower($format) === 'mp3' ? new AacLcDecoder() : null;
        $encoder = $decoder ? new Encoder(new Config($rate, $channels)) : null;
        $bytes = 0; $count = 0;
        try {
            foreach ($frames as $frame) {
                ++$count;
                if ($decoder) {
                    $encoded = $encoder->encodeS16le($decoder->push($this->adts($frame, $rate, $channels)));
                } else {
                    $encoded = $this->adts($frame, $rate, $channels);
                }
                $this->writeHandle($out, $encoded);
                $bytes += strlen($encoded);
            }
            if ($decoder) {
                $encoded = $encoder->encodeS16le($decoder->flush()) . $encoder->flush();
                $this->writeHandle($out, $encoded);
                $bytes += strlen($encoded);
            }
            if ($count === 0 || $bytes === 0) throw new RuntimeException('未找到 AAC 音频帧或未生成输出');
            fclose($out); $out = null;
            if (!rename($part, $outputFile)) throw new RuntimeException("无法生成输出文件: {$outputFile}");
            return ['output' => $outputFile, 'format' => strtolower($format), 'sampleRate' => $rate, 'channels' => $channels, 'bytes' => $bytes, 'frames' => $count];
        } finally {
            if (is_resource($out)) fclose($out);
            if (is_file($part)) @unlink($part);
        }
    }

    private function fromFlv(string $inputFile): array
    {
        $scan = fopen($inputFile, 'rb');
        if (!$scan || fread($scan, 9) === false) throw new RuntimeException('无法读取 FLV 文件');
        $header = fread($scan, 4);
        if ($header === false || strlen($header) !== 4) throw new RuntimeException('FLV 头部不完整');
        $asc = null;
        while (($tag = $this->readFlvTag($scan)) !== null) {
            if ($tag['type'] !== 8 || strlen($tag['body']) < 2) continue;
            $body = $tag['body'];
            if ((ord($body[0]) >> 4) !== 10) throw new RuntimeException('FLV 音频不是 AAC');
            if (ord($body[1]) === 0) $asc = substr($body, 2);
        }
        fclose($scan);
        if ($asc === null) throw new RuntimeException('FLV 缺少 AAC sequence header');
        $frames = function () use ($inputFile): \Generator {
            $input = fopen($inputFile, 'rb');
            if (!$input) throw new RuntimeException('无法读取 FLV 文件');
            try {
                fread($input, 13);
                while (($tag = $this->readFlvTag($input)) !== null) {
                    if ($tag['type'] !== 8 || strlen($tag['body']) < 2) continue;
                    $body = $tag['body'];
                    if ((ord($body[0]) >> 4) !== 10) throw new RuntimeException('FLV 音频不是 AAC');
                    if (ord($body[1]) === 1) yield substr($body, 2);
                }
            } finally { fclose($input); }
        };
        return [$asc, $frames()];
    }

    private function readFlvTag($input): ?array
    {
        $head = fread($input, 11);
        if ($head === '' || $head === false) return null;
        if (strlen($head) !== 11) throw new RuntimeException('FLV 标签头不完整');
        $size = (ord($head[1]) << 16) | (ord($head[2]) << 8) | ord($head[3]);
        $body = $size ? fread($input, $size) : '';
        $previous = fread($input, 4);
        if (strlen($body) !== $size || strlen($previous) !== 4) throw new RuntimeException('FLV 标签数据不完整');
        return ['type' => ord($head[0]), 'body' => $body];
    }

    private function fromMp4(string $inputFile): array
    {
        $input = fopen($inputFile, 'rb');
        if (!$input) throw new RuntimeException('无法读取 MP4 文件');
        try {
            $moov = $this->readMoov($input);
        } finally {
            fclose($input);
        }
        $boxes = $this->boxes($moov, 8, strlen($moov));
        $track = $this->findAudioTrack($boxes);
        if ($track === null) throw new RuntimeException('MP4 中未找到 AAC 音频轨道');
        $stbl = $this->child($this->child($this->child($track, 'mdia'), 'minf'), 'stbl');
        $stsd = $this->child($stbl, 'stsd');
        $entry = $this->findEntry($stsd, 'mp4a');
        if ($entry === null) throw new RuntimeException('MP4 音频采样格式不是 mp4a');
        $esds = $this->findBox($entry['children'], 'esds');
        if ($esds === null || !preg_match('/\x05(.{1,4})/s', $esds['data'], $m, PREG_OFFSET_CAPTURE)) throw new RuntimeException('MP4 缺少 esds/AAC 配置');
        $p = $m[0][1] + 1;
        $len = $this->descriptorLength($esds['data'], $p, $skip);
        $asc = substr($esds['data'], $p + $skip, $len);
        $this->asc($asc);
        $sizes = $this->sampleSizes($this->child($stbl, 'stsz'));
        $chunks = $this->chunkOffsets($stbl);
        $map = $this->sampleChunks($this->child($stbl, 'stsc'), count($chunks));
        $frames = function () use ($inputFile, $chunks, $map, $sizes): \Generator {
            $input = fopen($inputFile, 'rb');
            if (!$input) throw new RuntimeException('无法读取 MP4 文件');
            try {
                $sample = 0;
                foreach ($chunks as $chunkNo => $offset) {
                    $count = $map[$chunkNo + 1] ?? 0;
                    for ($j = 0; $j < $count && $sample < count($sizes); ++$j) {
                        $size = $sizes[$sample++];
                        if (fseek($input, $offset, SEEK_SET) !== 0) throw new RuntimeException('无法定位 MP4 音频数据');
                        $frame = $size ? fread($input, $size) : '';
                        if ($frame === false || strlen($frame) !== $size) throw new RuntimeException('MP4 音频数据不完整');
                        yield $frame;
                        $offset += $size;
                    }
                }
            } finally { fclose($input); }
        };
        return [$asc, $frames()];
    }

    private function readMoov($input): string
    {
        while (($header = fread($input, 8)) !== '' && $header !== false) {
            if (strlen($header) !== 8) throw new RuntimeException('MP4 box 头部不完整');
            $size = unpack('N', substr($header, 0, 4))[1];
            $type = substr($header, 4, 4);
            $headerSize = 8;
            $extended = null;
            if ($size === 1) {
                $extended = fread($input, 8);
                if ($extended === false || strlen($extended) !== 8) throw new RuntimeException('MP4 box 大小不完整');
                $v = unpack('N2', $extended);
                $size = $v[1] * 4294967296 + $v[2];
                $headerSize = 16;
            }
            if ($size === 0) throw new RuntimeException('MP4 box 大小无效');
            if ($size < $headerSize) throw new RuntimeException('MP4 box 大小无效');
            if ($type === 'moov') {
                $payload = fread($input, $size - $headerSize);
                if ($payload === false || strlen($payload) !== $size - $headerSize) throw new RuntimeException('MP4 moov 数据不完整');
                return $header . ($extended ?? '') . $payload;
            }
            if (fseek($input, $size - $headerSize, SEEK_CUR) !== 0) throw new RuntimeException('无法跳过 MP4 box');
        }
        throw new RuntimeException('MP4 缺少 moov');
    }

    private function boxes(string $data, int $start, int $end): array
    {
        $out = [];
        while ($start + 8 <= $end) {
            $size = unpack('N', substr($data, $start, 4))[1];
            $type = substr($data, $start + 4, 4);
            $head = 8;
            if ($size === 1) {
                if ($start + 16 > $end) break;
                $v = unpack('N2', substr($data, $start + 8, 8));
                $size = $v[1] * 4294967296 + $v[2];
                $head = 16;
            }
            if ($size === 0) $size = $end - $start;
            if ($size < $head || $start + $size > $end) break;
            $payload = substr($data, $start + $head, $size - $head);
            $children = in_array($type, ['moov', 'trak', 'mdia', 'minf', 'stbl', 'edts', 'dinf'], true) ? $this->boxes($data, $start + $head, $start + $size) : [];
            if ($type === 'stsd') $children = $this->boxes($data, $start + $head + 8, $start + $size);
            if ($type === 'mp4a') $children = $this->boxes($data, $start + $head + 28, $start + $size);
            $out[] = ['type' => $type, 'data' => $payload, 'children' => $children];
            $start += $size;
        }
        return $out;
    }

    private function findAudioTrack(array $boxes): ?array
    {
        foreach ($boxes as $b) {
            if ($b['type'] === 'trak') {
                $h = $this->child($b, 'mdia');
                $hd = $this->child($h, 'hdlr');
                if ($hd && substr($hd['data'], 8, 4) === 'soun') return $b;
            }
            if ($b['children']) {
                $r = $this->findAudioTrack($b['children']);
                if ($r) return $r;
            }
        }
        return null;
    }

    private function child(?array $b, string $type): ?array
    {
        return $b ? $this->findBox($b['children'], $type) : null;
    }

    private function findBox(array $boxes, string $type): ?array
    {
        foreach ($boxes as $b) if ($b['type'] === $type) return $b;
        return null;
    }

    private function findEntry(?array $stsd, string $type): ?array
    {
        if (!$stsd) return null;
        foreach ($stsd['children'] as $b) if ($b['type'] === $type) return $b;
        return null;
    }

    private function sampleSizes(?array $b): array
    {
        if (!$b) throw new RuntimeException('MP4 缺少 stsz');
        $size = unpack('N', substr($b['data'], 4, 4))[1];
        $count = unpack('N', substr($b['data'], 8, 4))[1];
        if ($size) return array_fill(0, $count, $size);
        $r = [];
        for ($i = 0; $i < $count; $i++) $r[] = unpack('N', substr($b['data'], 12 + $i * 4, 4))[1];
        return $r;
    }

    private function chunkOffsets(array $stbl): array
    {
        $b = $this->findBox($stbl['children'], 'stco') ?: $this->findBox($stbl['children'], 'co64');
        if (!$b) throw new RuntimeException('MP4 缺少 stco/co64');
        $count = unpack('N', substr($b['data'], 4, 4))[1];
        $r = [];
        for ($i = 0; $i < $count; $i++) $r[] = $b['type'] === 'stco' ? unpack('N', substr($b['data'], 8 + $i * 4, 4))[1] : unpack('N2', substr($b['data'], 8 + $i * 8, 8))[1] * 4294967296 + unpack('N2', substr($b['data'], 8 + $i * 8, 8))[2];
        return $r;
    }

    private function sampleChunks(?array $b, int $chunkCount): array
    {
        if (!$b) {
            throw new RuntimeException('MP4 缺少 stsc');
        }
        $data = $b['data'];
        $entryCount = unpack('N', substr($data, 4, 4))[1];
        $entries = [];
        for ($i = 0; $i < $entryCount; ++$i) {
            $entries[] = array_values(unpack('N3', substr($data, 8 + $i * 12, 12)));
        }
        $result = [];
        for ($i = 0; $i < $entryCount; ++$i) {
            $firstChunk = $entries[$i][0];
            $lastChunk = $i + 1 < $entryCount
                ? $entries[$i + 1][0] - 1
                : $chunkCount;
            for ($chunk = $firstChunk; $chunk <= $lastChunk; ++$chunk) {
                $result[$chunk] = $entries[$i][1];
            }
        }
        return $result;
    }

    private function asc(string $asc): array
    {
        if (strlen($asc) < 2) throw new RuntimeException('AAC 配置不完整');
        $a = unpack('C2', substr($asc, 0, 2));
        $object = ($a[1] >> 3) & 31;
        $index = (($a[1] & 7) << 1) | ($a[2] >> 7);
        if ($object === 31) throw new RuntimeException('不支持扩展 AAC object type');
        if (!isset(self::RATES[$index])) throw new RuntimeException('AAC 采样率不支持');
        $channels = ($a[2] >> 3) & 15;
        if ($channels < 1 || $channels > 2) throw new RuntimeException('仅支持单声道或双声道 AAC');
        return [$object, self::RATES[$index], $channels];
    }

    private function adts(string $frame, int $rate, int $channels): string
    {
        $index = array_search($rate, self::RATES, true);
        if ($index === false || strlen($frame) > 8190) {
            throw new RuntimeException('AAC 帧参数无效');
        }
        $length = strlen($frame) + 7;
        $channelConfig = $channels === 2 ? 2 : 1;
        $header = pack(
            'C7',
            0xff,
            0xf1,
            0x40 | ($index << 2) | ($channelConfig >> 2),
            (($channelConfig & 3) << 6) | (($length >> 11) & 3),
            ($length >> 3) & 0xff,
            (($length & 7) << 5) | 0x1f,
            0xfc
        );
        return $header . $frame;
    }

    private function descriptorLength(string $data, int $pos, ?int &$skip): int
    {
        $len = 0;
        $skip = 0;
        do {
            if ($pos + $skip >= strlen($data)) throw new RuntimeException('esds 描述符不完整');
            $v = ord($data[$pos + $skip++]);
            $len = ($len << 7) | ($v & 127);
        } while (($v & 128) !== 0 && $skip < 5);
        return $len;
    }

    private function writeHandle($handle, string $data): void
    {
        for ($offset = 0, $length = strlen($data); $offset < $length;) {
            $written = fwrite($handle, $data, $length - $offset);
            if ($written === false || $written === 0) throw new RuntimeException('写入输出文件失败');
            $offset += $written;
            if ($offset < $length) $data = substr($data, $written);
        }
    }
}
