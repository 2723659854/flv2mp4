<?php

namespace Xiaosongshu\Flv2mp4\Mp3;

/**
 * @purpose 字节写入工具
 * @author yanglong
 * @time 2026年9月3日16:24:14
 */
final class BitWriter
{
    private string $data = '';
    private int $byte = 0;
    private int $used = 0;
    private int $bits = 0;

    public function write(int $value, int $count): void
    {
        if ($count < 0 || $count > 31) {
            throw new \InvalidArgumentException('Bit count must be between 0 and 31');
        }
        if ($count === 0) {
            return;
        }
        if ($value < 0 || $value >= (1 << $count)) {
            throw new \InvalidArgumentException('Bit value does not fit the requested width');
        }
        for ($i = $count - 1; $i >= 0; --$i) {
            $this->byte = ($this->byte << 1) | (($value >> $i) & 1);
            ++$this->used;
            ++$this->bits;
            if ($this->used === 8) {
                $this->data .= chr($this->byte);
                $this->byte = 0;
                $this->used = 0;
            }
        }
    }

    public function bitCount(): int { return $this->bits; }

    public function writePacked(string $data, int $count): void
    {
        for ($i = 0; $i < $count; ++$i) {
            $byte = ord($data[intdiv($i, 8)]);
            $this->write(($byte >> (7 - ($i & 7))) & 1, 1);
        }
    }

    public function finish(): string
    {
        if ($this->used !== 0) {
            $this->data .= chr($this->byte << (8 - $this->used));
            $this->byte = 0;
            $this->used = 0;
        }
        return $this->data;
    }
}
