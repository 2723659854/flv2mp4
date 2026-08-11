<?php

namespace Xiaosongshu\Flv2mp4\Aac;

final class BitWriter
{
    private string $data = '';
    private int $byte = 0;
    private int $used = 0;
    private int $bits = 0;

    public function write(int $value, int $count): void
    {
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

    public function bitCount(): int
    {
        return $this->bits;
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
