<?php

namespace Xiaosongshu\Flv2mp4\Mp3;

use InvalidArgumentException;

final class PcmBuffer
{
    private string $data = '';

    public function __construct(private readonly int $channels) {}

    public function appendS16le(string $pcm): void
    {
        if (strlen($pcm) % ($this->channels * 2) !== 0) {
            throw new InvalidArgumentException('s16le PCM must contain complete interleaved sample frames');
        }
        $this->data .= $pcm;
    }

    public function frames(): int { return intdiv(strlen($this->data), $this->channels * 2); }

    public function take(int $frames): string
    {
        $bytes = $frames * $this->channels * 2;
        $result = substr($this->data, 0, $bytes);
        $this->data = substr($this->data, $bytes);
        return $result;
    }

    public function clear(): void { $this->data = ''; }
}
