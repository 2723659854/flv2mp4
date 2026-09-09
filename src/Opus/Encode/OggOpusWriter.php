<?php

namespace Xiaosongshu\Flv2mp4\Opus\Encode;

use InvalidArgumentException;
use RuntimeException;

final class OggOpusWriter
{
    private int $channels;
    private int $inputSampleRate;
    private int $preSkip;
    private int $outputGainQ8;
    private int $serial;
    private int $sequence = 0;
    private int $granulePosition = 0;
    private string $data = '';
    private bool $finished = false;
    private bool $audioWritten = false;

    public function __construct(
        int $channels = 2,
        int $inputSampleRate = 48000,
        int $preSkip = 312,
        int $outputGainQ8 = 0,
        ?int $serial = null,
        string $vendor = 'Xiaosongshu Flv2mp4'
    ) {
        if ($channels < 1 || $channels > 2 || $inputSampleRate < 0 || $inputSampleRate > 0xFFFFFFFF || $preSkip < 0 || $preSkip > 0xFFFF || $outputGainQ8 < -32768 || $outputGainQ8 > 32767) {
            throw new InvalidArgumentException('Invalid Opus stream parameters');
        }
        if ($serial === null) {
            $serial = random_int(0, 0x7FFFFFFF);
        }
        $this->channels = $channels;
        $this->inputSampleRate = $inputSampleRate;
        $this->preSkip = $preSkip;
        $this->outputGainQ8 = $outputGainQ8;
        $this->serial = $serial & 0xFFFFFFFF;
        $this->data .= $this->page($this->opusHead(), 2, 0);
        $this->data .= $this->page($this->opusTags($vendor), 0, 0);
    }

    public function writePacket(string $packet, ?int $granulePosition = null): void
    {
        if ($this->finished) {
            throw new RuntimeException('Ogg Opus writer is already finished');
        }
        if ($granulePosition !== null) {
            if ($granulePosition < 0) {
                throw new InvalidArgumentException('Granule position must be non-negative');
            }
            $this->granulePosition = $granulePosition;
        }
        $this->data .= $this->page($packet, 0, $this->granulePosition);
        $this->audioWritten = true;
    }

    public function finish(?int $granulePosition = null): string
    {
        if ($this->finished) {
            return $this->data;
        }
        if ($granulePosition !== null) {
            if ($granulePosition < 0) {
                throw new InvalidArgumentException('Granule position must be non-negative');
            }
            $this->granulePosition = $granulePosition;
        }
        if ($this->audioWritten === true) {
            $this->markLastPageEos();
        } else {
            $this->data .= $this->page('', 4, 0);
        }
        $this->finished = true;
        return $this->data;
    }

    public function getData(): string
    {
        return $this->finish();
    }

    public function writeToFile(string $path): void
    {
        $written = @file_put_contents($path, $this->finish());
        if ($written === false) {
            throw new RuntimeException("Unable to write Ogg Opus file: {$path}");
        }
    }

    public function opusHead(): string
    {
        $head = 'OpusHead' . "\x01" . chr($this->channels);
        $head .= pack('v', $this->preSkip);
        $head .= pack('V', $this->inputSampleRate);
        $head .= pack('v', $this->outputGainQ8 & 0xFFFF);
        return $head . "\x00";
    }

    public function opusTags(string $vendor, array $comments = []): string
    {
        $packet = 'OpusTags' . pack('V', strlen($vendor)) . $vendor . pack('V', count($comments));
        foreach ($comments as $comment) {
            if (!is_string($comment)) {
                throw new InvalidArgumentException('Opus comment must be a string');
            }
            $packet .= pack('V', strlen($comment)) . $comment;
        }
        return $packet;
    }

    private function page(string $packet, int $flags, int $granule): string
    {
        $lacing = [];
        $length = strlen($packet);
        for ($offset = 0; $offset < $length; $offset += 255) {
            $lacing[] = min(255, $length - $offset);
        }
        if ($length === 0 || $length % 255 === 0) {
            $lacing[] = 0;
        }
        $pages = '';
        $bodyOffset = 0;
        while ($lacing !== []) {
            $pageLacing = array_splice($lacing, 0, 255);
            $bodyLength = array_sum($pageLacing);
            $continued = $bodyOffset > 0 ? 1 : 0;
            $last = $lacing === [];
            $pageFlags = $flags | $continued;
            if (!$last) {
                $pageGranule = -1;
            } else {
                $pageGranule = $granule;
            }
            $body = substr($packet, $bodyOffset, $bodyLength);
            $header = "OggS\x00" . chr($pageFlags) . $this->packGranule($pageGranule);
            $header .= pack('V', $this->serial) . pack('V', $this->sequence++);
            $header .= "\x00\x00\x00\x00" . chr(count($pageLacing)) . implode('', array_map('chr', $pageLacing));
            $page = $header . $body;
            $crc = $this->crc($page);
            $page = substr_replace($page, pack('V', $crc), 22, 4);
            $pages .= $page;
            $bodyOffset += $bodyLength;
        }
        return $pages;
    }

    private function markLastPageEos(): void
    {
        $offset = strlen($this->data);
        while ($offset > 0) {
            $offset = strrpos(substr($this->data, 0, $offset), 'OggS');
            if ($offset === false) {
                throw new RuntimeException('Ogg page not found');
            }
            if ($offset + 27 <= strlen($this->data)) {
                break;
            }
        }
        $pageLength = 27 + ord($this->data[$offset + 26]);
        for ($i = 0; $i < ord($this->data[$offset + 26]); $i++) {
            $pageLength += ord($this->data[$offset + 27 + $i]);
        }
        $page = substr($this->data, $offset, $pageLength);
        $page[5] = chr(ord($page[5]) | 4);
        $page = substr_replace($page, $this->packGranule($this->granulePosition), 6, 8);
        $page = substr_replace($page, "\x00\x00\x00\x00", 22, 4);
        $page = substr_replace($page, pack('V', $this->crc($page)), 22, 4);
        $this->data = substr($this->data, 0, $offset) . $page . substr($this->data, $offset + $pageLength);
    }

    private function packGranule(int $value): string
    {
        if ($value < 0) {
            return str_repeat("\xFF", 8);
        }
        return pack('V2', $value & 0xFFFFFFFF, ($value >> 32) & 0xFFFFFFFF);
    }

    private function crc(string $page): int
    {
        $crc = 0;
        for ($i = 0, $length = strlen($page); $i < $length; $i++) {
            $crc ^= ord($page[$i]) << 24;
            for ($bit = 0; $bit < 8; $bit++) {
                $crc = (($crc & 0x80000000) !== 0 ? ($crc << 1) ^ 0x04C11DB7 : $crc << 1) & 0xFFFFFFFF;
            }
        }
        return $crc;
    }
}
