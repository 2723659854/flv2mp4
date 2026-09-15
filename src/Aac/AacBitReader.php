<?php

namespace Xiaosongshu\Flv2mp4\Aac;

/**
 * @purpose aac比特读取
 * @author yanglong
 * @time 2026年9月3日16:21:03
 */
final class AacBitReader
{
    private int $pos = 0;
    private int $lenBits;

    public function __construct(private string $data)
    {
        $this->lenBits = strlen($data) << 3;
    }

    public function position(): int { return $this->pos; }

    public function read(int $n): int
    {
        if ($n < 0 || $this->pos + $n > $this->lenBits) throw new \RuntimeException('AAC bitstream truncated');
        if ($n === 0) return 0;
        $v = $this->peek($n);
        $this->pos += $n;
        return $v;
    }

    /** 预读 n 位但不移动读指针；超出数据末尾的位按 0 补齐（Huffman 快路径用）。 */
    public function peek(int $n): int
    {
        $p = $this->pos;
        if (($p + $n <= $this->lenBits) && (($p >> 3) + 4 <= strlen($this->data))) {
            $bp = $p >> 3;
            $d = $this->data;
            // 4 字节窗口（n<=27 时覆盖 skip+n），一次取出目标位段
            $v = (ord($d[$bp]) << 24) | (ord($d[$bp + 1]) << 16) | (ord($d[$bp + 2]) << 8) | ord($d[$bp + 3]);
            return ($v >> (32 - ($p & 7) - $n)) & ((1 << $n) - 1);
        }
        $v = 0;
        for ($i = 0; $i < $n; ++$i) {
            $v <<= 1;
            if ($p < $this->lenBits) $v |= (ord($this->data[$p >> 3]) >> (7 - ($p & 7))) & 1;
            ++$p;
        }
        return $v;
    }

    public function skip(int $n): void
    {
        if ($n < 0 || $this->pos + $n > $this->lenBits) throw new \RuntimeException('AAC bitstream truncated');
        $this->pos += $n;
    }

    public function align(): void { $this->pos = ($this->pos + 7) & ~7; }
}