<?php

namespace Xiaosongshu\Flv2mp4\Opus;

use InvalidArgumentException;
use LogicException;
use RuntimeException;

/**
 * @purpose Ogg Opus 解复用读取器
 * 按页增量读取：内存占用与文件大小无关，只保留当前页、正在重组的单个包和上一个已完成包。
 * 保留旧的整串构造与 audioPackets() 接口；新增 packets() 流式逐包迭代与 fromStream() 流入口。
 * @author yanglong
 * @time 2026年8月12日17:21:40
 */
final class OggOpusReader
{
    /** @var string|null 字符串模式：完整 Ogg 数据（按偏移读取，不复制） */
    private ?string $data = null;
    private int $offset = 0;

    /** @var resource|null 流模式：输入流 */
    private $stream = null;
    private bool $ownsStream = false;

    private array $head = [];
    private array $tags = [];

    /** 音频包缓存，仅 audioPackets() 首次调用时填充 */
    private ?array $audioPackets = null;
    /** 已完成头部解析、尚未消费完的包迭代器 */
    private ?\Generator $pending = null;
    private bool $packetsStarted = false;

    /**
     * @param string|resource $source 完整 Ogg 数据，或位于 Ogg 流起点的可读流（文件句柄/管道）
     */
    public function __construct(mixed $source)
    {
        if (is_string($source)) {
            $this->data = $source;
        } elseif (is_resource($source)) {
            $this->stream = $source;
        } else {
            throw new InvalidArgumentException('Ogg Opus reader expects a string or stream resource');
        }
        $this->readHeaders();
    }

    public static function fromFile(string $path): self
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Unable to read Ogg Opus file: {$path}");
        }
        try {
            $reader = new self($handle);
        } catch (\Throwable $e) {
            fclose($handle);
            throw $e;
        }
        $reader->ownsStream = true;
        return $reader;
    }

    /**
     * 从调用方提供的可读流构造（管道/直播场景，不整文件读入）。
     * 读取器不关闭调用方的流；该流只能被本实例消费一次。
     */
    public static function fromStream(mixed $stream): self
    {
        if (!is_resource($stream)) {
            throw new InvalidArgumentException('Ogg Opus reader expects a readable stream resource');
        }
        return new self($stream);
    }

    public function __destruct()
    {
        if ($this->ownsStream && $this->stream !== null) {
            @fclose($this->stream);
        }
    }

    public function channels(): int
    {
        return $this->head['channels'];
    }

    public function preSkip(): int
    {
        return $this->head['preSkip'];
    }

    public function sampleRate(): int
    {
        return 48000;
    }

    public function head(): array
    {
        return $this->head;
    }

    public function tags(): array
    {
        return $this->tags;
    }

    /**
     * 兼容接口：一次性返回全部音频包（内存与文件大小相关，仅供需要随机访问的调用方）。
     */
    public function audioPackets(): array
    {
        if ($this->audioPackets === null) {
            if ($this->packetsStarted) {
                throw new LogicException('Ogg Opus packets already consumed; create a new reader instance');
            }
            $this->audioPackets = iterator_to_array($this->packets(), false);
        }
        return $this->audioPackets;
    }

    /**
     * 流式逐包产出音频包（不含 OpusHead/OpusTags）。
     * 最后一包的 trimEnd 依赖流末尾的 granule position，因此内部滞后一包再产出。
     * 同一实例只能消费一次；需要重复遍历请用 audioPackets()。
     */
    public function packets(): \Generator
    {
        if ($this->audioPackets !== null) {
            foreach ($this->audioPackets as $packet) {
                yield $packet;
            }
            return;
        }
        if ($this->packetsStarted) {
            throw new LogicException('Ogg Opus packets already consumed');
        }
        $this->packetsStarted = true;
        $decodedEnd = 0;
        $previous = null;
        try {
            // 不能对已启动的生成器用 foreach（rewind 会抛异常），改为手动推进
            while ($this->pending->valid()) {
                $raw = $this->pending->current();
                $this->pending->next();
                $description = OpusPacketParser::parse($raw['data']);
                $samples = $description['frameCount'] * $description['frameDurationSamples'];
                $trimStart = min($samples, max(0, $this->head['preSkip'] - $decodedEnd));
                $decodedEnd += $samples;
                if ($previous !== null) {
                    yield $previous;
                }
                $previous = [
                    'data' => $raw['data'],
                    'granulePosition' => $raw['granule'],
                    'durationSamples' => $samples,
                    'trimStartSamples' => $trimStart,
                    'trimEndSamples' => 0,
                ];
            }
        } finally {
            $this->pending = null;
            $this->closeStream();
        }
        if ($previous !== null) {
            $granule = $previous['granulePosition'];
            if ($granule !== null && $granule !== self::unknownGranule()) {
                $previous['trimEndSamples'] = min($previous['durationSamples'], max(0, $decodedEnd - $granule));
            }
            yield $previous;
        }
    }

    /** 解析 OpusHead 与 OpusTags；只读到第二个包为止，其余页面留给 packets() 增量读取 */
    private function readHeaders(): void
    {
        $this->pending = $this->iteratePackets();
        $headers = [];
        while (count($headers) < 2) {
            if (!$this->pending->valid()) {
                throw new InvalidArgumentException('Ogg stream does not begin with OpusHead and OpusTags');
            }
            $headers[] = $this->pending->current()['data'];
            $this->pending->next();
        }
        if (!str_starts_with($headers[0], 'OpusHead') || !str_starts_with($headers[1], 'OpusTags')) {
            throw new InvalidArgumentException('Ogg stream does not begin with OpusHead and OpusTags');
        }
        $this->head = $this->parseHead($headers[0]);
        $this->tags = $this->parseTags($headers[1]);
    }

    /**
     * 逐页读取并重组包。跨页包由 continuation 标志校验，缓冲量以单个包为界。
     * @return \Generator<array{data: string, granule: ?int}>
     */
    private function iteratePackets(): \Generator
    {
        $serial = null;
        $expectedSequence = 0;
        $partial = '';
        $seenBos = false;
        $seenEos = false;

        while (($page = $this->readPage()) !== null) {
            $flags = $page['flags'];
            if ($serial === null) {
                $serial = $page['serial'];
            }
            if ($page['serial'] !== $serial || $page['sequence'] !== $expectedSequence++) {
                throw new InvalidArgumentException('Ogg serial or page sequence mismatch');
            }
            if (!$seenBos && ($flags & 2) === 0) {
                throw new InvalidArgumentException('First Ogg page is not BOS');
            }
            if ($seenBos && ($flags & 2) !== 0) {
                throw new InvalidArgumentException('Unexpected Ogg BOS page');
            }
            $seenBos = true;
            $continued = ($flags & 1) !== 0;
            if ($continued !== ($partial !== '')) {
                throw new InvalidArgumentException('Invalid Ogg continued-packet flag');
            }
            if ($seenEos) {
                throw new InvalidArgumentException('Data follows Ogg EOS page');
            }
            $seenEos = ($flags & 4) !== 0;

            $lacing = $page['lacing'];
            $body = $page['body'];
            // 该页 granule 归属"本页最后一个完成的包"，与整文件解析语义一致
            $completed = 0;
            foreach ($lacing as $lace) {
                if ($lace < 255) {
                    $completed++;
                }
            }
            $bodyOffset = 0;
            $index = 0;
            foreach ($lacing as $lace) {
                $partial .= substr($body, $bodyOffset, $lace);
                $bodyOffset += $lace;
                if ($lace < 255) {
                    yield [
                        'data' => $partial,
                        'granule' => $index === $completed - 1 ? $page['granule'] : null,
                    ];
                    $partial = '';
                    $index++;
                }
            }
        }
        if ($partial !== '') {
            throw new InvalidArgumentException('Ogg stream ends in a partial packet');
        }
        if (!$seenEos) {
            throw new InvalidArgumentException('Ogg stream has no EOS page');
        }
    }

    /** 读取一个完整页（27 字节页头 + segment table + body），EOF 返回 null */
    private function readPage(): ?array
    {
        $header = $this->readFully(27, 'Invalid Ogg capture pattern or truncated page');
        if ($header === null) {
            return null;
        }
        if (substr($header, 0, 4) !== 'OggS') {
            throw new InvalidArgumentException('Invalid Ogg capture pattern or truncated page');
        }
        $version = ord($header[4]);
        $flags = ord($header[5]);
        $pageSegments = ord($header[26]);
        if ($version !== 0) {
            throw new InvalidArgumentException('Unsupported Ogg version or truncated segment table');
        }
        $segmentTable = $pageSegments > 0
            ? $this->readFully($pageSegments, 'Unsupported Ogg version or truncated segment table')
            : '';
        if ($segmentTable === null) {
            throw new InvalidArgumentException('Unsupported Ogg version or truncated segment table');
        }
        $lacing = array_values(unpack('C*', $segmentTable) ?: []);
        $bodyLength = array_sum($lacing);
        $body = $bodyLength > 0 ? $this->readFully($bodyLength, 'Truncated Ogg page body') : '';
        if ($body === null) {
            throw new InvalidArgumentException('Truncated Ogg page body');
        }

        $page = $header . $segmentTable . $body;
        $storedCrc = unpack('V', substr($page, 22, 4))[1];
        $crcPage = substr_replace($page, "\0\0\0\0", 22, 4);
        if (self::crc($crcPage) !== $storedCrc) {
            throw new InvalidArgumentException('Ogg CRC mismatch');
        }

        return [
            'flags' => $flags,
            'granule' => self::readGranule(substr($page, 6, 8)),
            'serial' => unpack('V', substr($page, 14, 4))[1],
            'sequence' => unpack('V', substr($page, 18, 4))[1],
            'lacing' => $lacing,
            'body' => $body,
        ];
    }

    /**
     * 读满 $length 字节；干净 EOF 返回 null，半截数据抛异常。
     * 字符串模式按偏移切片（不复制整串），流模式循环读满（管道可能短读）。
     */
    private function readFully(int $length, string $error): ?string
    {
        if ($this->data !== null) {
            $available = strlen($this->data) - $this->offset;
            if ($available <= 0) {
                return null;
            }
            if ($available < $length) {
                throw new InvalidArgumentException($error);
            }
            $chunk = substr($this->data, $this->offset, $length);
            $this->offset += $length;
            return $chunk;
        }

        $buffer = '';
        while (strlen($buffer) < $length) {
            $chunk = fread($this->stream, $length - strlen($buffer));
            if ($chunk === false) {
                throw new RuntimeException('Unable to read Ogg Opus stream');
            }
            if ($chunk === '') {
                if ($buffer === '') {
                    return null;
                }
                throw new InvalidArgumentException($error);
            }
            $buffer .= $chunk;
        }
        return $buffer;
    }

    private function closeStream(): void
    {
        if ($this->stream !== null) {
            if ($this->ownsStream) {
                @fclose($this->stream);
            }
            $this->stream = null;
        }
    }

    private function parseHead(string $packet): array
    {
        if (strlen($packet) < 19) {
            throw new InvalidArgumentException('Truncated OpusHead');
        }
        $version = ord($packet[8]);
        $channels = ord($packet[9]);
        $mappingFamily = ord($packet[18]);
        if (($version & 0xF0) !== 0 || $channels < 1) {
            throw new InvalidArgumentException('Unsupported OpusHead version or channel count');
        }
        if ($mappingFamily === 0) {
            if ($channels > 2 || strlen($packet) !== 19) {
                throw new InvalidArgumentException('Invalid mapping family 0 OpusHead');
            }
            $streams = 1;
            $coupled = $channels - 1;
            $mapping = $channels === 1 ? [0] : [0, 1];
        } else {
            if (strlen($packet) < 21 + $channels) {
                throw new InvalidArgumentException('Truncated OpusHead channel mapping');
            }
            $streams = ord($packet[19]);
            $coupled = ord($packet[20]);
            $mapping = array_values(unpack('C*', substr($packet, 21, $channels)) ?: []);
            if ($streams < 1 || $coupled > $streams || $streams + $coupled > 255) {
                throw new InvalidArgumentException('Invalid OpusHead stream mapping');
            }
        }
        $gain = unpack('v', substr($packet, 16, 2))[1];
        if ($gain >= 0x8000) {
            $gain -= 0x10000;
        }
        return [
            'version' => $version,
            'channels' => $channels,
            'preSkip' => unpack('v', substr($packet, 10, 2))[1],
            'inputSampleRate' => unpack('V', substr($packet, 12, 4))[1],
            'outputGainQ8' => $gain,
            'mappingFamily' => $mappingFamily,
            'streamCount' => $streams,
            'coupledCount' => $coupled,
            'channelMapping' => $mapping,
        ];
    }

    private function parseTags(string $packet): array
    {
        $offset = 8;
        $vendor = self::readLengthPrefixed($packet, $offset);
        if ($offset + 4 > strlen($packet)) {
            throw new InvalidArgumentException('Truncated OpusTags comment count');
        }
        $count = unpack('V', substr($packet, $offset, 4))[1];
        $offset += 4;
        $comments = [];
        for ($i = 0; $i < $count; $i++) {
            $comments[] = self::readLengthPrefixed($packet, $offset);
        }
        return ['vendor' => $vendor, 'comments' => $comments];
    }

    private static function readLengthPrefixed(string $data, int &$offset): string
    {
        if ($offset + 4 > strlen($data)) {
            throw new InvalidArgumentException('Truncated OpusTags field');
        }
        $length = unpack('V', substr($data, $offset, 4))[1];
        $offset += 4;
        if ($length > strlen($data) - $offset) {
            throw new InvalidArgumentException('OpusTags field exceeds packet');
        }
        $value = substr($data, $offset, $length);
        $offset += $length;
        return $value;
    }

    private static function crc(string $page): int
    {
        $crc = 0;
        $length = strlen($page);
        for ($i = 0; $i < $length; $i++) {
            $crc ^= ord($page[$i]) << 24;
            for ($bit = 0; $bit < 8; $bit++) {
                $crc = (($crc & 0x80000000) !== 0 ? ($crc << 1) ^ 0x04C11DB7 : $crc << 1) & 0xFFFFFFFF;
            }
        }
        return $crc;
    }

    private static function readGranule(string $bytes): int
    {
        $parts = unpack('Vlow/Vhigh', $bytes);
        if ($parts['high'] === 0xFFFFFFFF && $parts['low'] === 0xFFFFFFFF) {
            return self::unknownGranule();
        }
        if (($parts['high'] & 0x80000000) !== 0 || $parts['high'] > 0x7FFFFFFF) {
            throw new InvalidArgumentException('Ogg granule position exceeds signed PHP integer range');
        }
        return ($parts['high'] << 32) | $parts['low'];
    }

    private static function unknownGranule(): int
    {
        return -1;
    }
}
