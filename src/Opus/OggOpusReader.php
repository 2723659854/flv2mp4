<?php

namespace Xiaosongshu\Flv2mp4\Opus;

use InvalidArgumentException;
use RuntimeException;

/**
 * @purpose Ogg Opus 解复用读取器
 * @author yanglong
 * @time 2026年8月12日17:21:40
 */
final class OggOpusReader
{
    private array $head;
    private array $tags;
    private array $audioPackets;

    public function __construct(string $oggData)
    {
        [$packets, $granules] = $this->parsePages($oggData);
        if (count($packets) < 2 || !str_starts_with($packets[0], 'OpusHead') || !str_starts_with($packets[1], 'OpusTags')) {
            throw new InvalidArgumentException('Ogg stream does not begin with OpusHead and OpusTags');
        }
        $this->head = $this->parseHead($packets[0]);
        $this->tags = $this->parseTags($packets[1]);
        $this->audioPackets = [];
        $decodedEnd = 0;
        for ($i = 2, $count = count($packets); $i < $count; $i++) {
            $description = OpusPacketParser::parse($packets[$i]);
            $samples = $description['frameCount'] * $description['frameDurationSamples'];
            $decodedStart = $decodedEnd;
            $decodedEnd += $samples;
            $granule = $granules[$i];
            $trimEnd = $i === $count - 1 && $granule !== null && $granule !== self::unknownGranule()
                ? max(0, $decodedEnd - $granule)
                : 0;
            $this->audioPackets[] = [
                'data' => $packets[$i],
                'granulePosition' => $granule,
                'durationSamples' => $samples,
                'trimStartSamples' => min($samples, max(0, $this->head['preSkip'] - $decodedStart)),
                'trimEndSamples' => min($samples, $trimEnd),
            ];
        }
    }

    public static function fromFile(string $path): self
    {
        $data = @file_get_contents($path);
        if ($data === false) {
            throw new RuntimeException("Unable to read Ogg Opus file: {$path}");
        }
        return new self($data);
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

    public function audioPackets(): array
    {
        return $this->audioPackets;
    }

    private function parsePages(string $data): array
    {
        $offset = 0;
        $length = strlen($data);
        $serial = null;
        $expectedSequence = 0;
        $partial = '';
        $packets = [];
        $granules = [];
        $seenBos = false;
        $seenEos = false;

        while ($offset < $length) {
            if ($length - $offset < 27 || substr($data, $offset, 4) !== 'OggS') {
                throw new InvalidArgumentException('Invalid Ogg capture pattern or truncated page');
            }
            $version = ord($data[$offset + 4]);
            $flags = ord($data[$offset + 5]);
            $pageSegments = ord($data[$offset + 26]);
            $headerLength = 27 + $pageSegments;
            if ($version !== 0 || $offset + $headerLength > $length) {
                throw new InvalidArgumentException('Unsupported Ogg version or truncated segment table');
            }
            $lacing = array_values(unpack('C*', substr($data, $offset + 27, $pageSegments)) ?: []);
            $bodyLength = array_sum($lacing);
            $pageLength = $headerLength + $bodyLength;
            if ($offset + $pageLength > $length) {
                throw new InvalidArgumentException('Truncated Ogg page body');
            }
            $page = substr($data, $offset, $pageLength);
            $storedCrc = unpack('V', substr($page, 22, 4))[1];
            $crcPage = substr_replace($page, "\0\0\0\0", 22, 4);
            if (self::crc($crcPage) !== $storedCrc) {
                throw new InvalidArgumentException('Ogg CRC mismatch');
            }
            $pageSerial = unpack('V', substr($page, 14, 4))[1];
            $sequence = unpack('V', substr($page, 18, 4))[1];
            if ($serial === null) {
                $serial = $pageSerial;
            }
            if ($pageSerial !== $serial || $sequence !== $expectedSequence++) {
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

            $granule = self::readGranule(substr($page, 6, 8));
            $bodyOffset = $headerLength;
            $completedOnPage = [];
            foreach ($lacing as $lace) {
                $partial .= substr($page, $bodyOffset, $lace);
                $bodyOffset += $lace;
                if ($lace < 255) {
                    $packets[] = $partial;
                    $granules[] = null;
                    $completedOnPage[] = count($packets) - 1;
                    $partial = '';
                }
            }
            if ($completedOnPage !== []) {
                $granules[end($completedOnPage)] = $granule;
            }
            $offset += $pageLength;
        }
        if ($partial !== '') {
            throw new InvalidArgumentException('Ogg stream ends in a partial packet');
        }
        if (!$seenEos) {
            throw new InvalidArgumentException('Ogg stream has no EOS page');
        }
        return [$packets, $granules];
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
