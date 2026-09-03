<?php

namespace Xiaosongshu\Flv2mp4\Aac;

final class AacBitReader
{
    private int $pos = 0; public function __construct(private string $data) {}
    public function position(): int { return $this->pos; }
    public function read(int $n): int { $v = 0; for ($i = 0; $i < $n; ++$i) { if ($this->pos >= strlen($this->data) * 8) throw new \RuntimeException('AAC bitstream truncated'); $v = ($v << 1) | ((ord($this->data[intdiv($this->pos, 8)]) >> (7 - ($this->pos++ % 8))) & 1); } return $v; }
    public function skip(int $n): void { if ($n < 0 || $this->pos + $n > strlen($this->data) * 8) throw new \RuntimeException('AAC bitstream truncated'); $this->pos += $n; }
    public function align(): void { $this->pos = ($this->pos + 7) & ~7; }
}