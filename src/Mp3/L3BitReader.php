<?php

namespace Xiaosongshu\Flv2mp4\Mp3;

/**
 * @purpose MP3 main data MSB-first 位读取器（可随机 seek，用于 bit reservoir 拼接数据）。
 * @author yanglong
 * @time 2026年9月15日15:18:34
 */
final class L3BitReader
{
    public int $pos = 0;
    private int $len;

    public function __construct(private readonly string $data)
    {
        $this->len = strlen($data) * 8;
    }

    public function seek(int $bit): void
    {
        $this->pos = $bit;
    }

    public function skip(int $bits): void
    {
        $this->pos += $bits;
    }

    public function read(int $bits): int
    {
        $value = 0;
        $p = $this->pos;
        $d = $this->data;
        for ($i = 0; $i < $bits; $i++) {
            $value = ($value << 1) | ((ord($d[($p + $i) >> 3]) >> (7 - (($p + $i) & 7))) & 1);
        }
        $this->pos = $p + $bits;
        return $value;
    }
}
