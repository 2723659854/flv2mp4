<?php

namespace Xiaosongshu\Flv2mp4\Aac;

/**
 * @purpose bit写入工具
 * @author yanglong
 * @time 2026年8月12日17:09:23
 */
final class BitWriter
{
    private string $data = '';
    private int $byte = 0;
    private int $used = 0;
    private int $bits = 0;

    /**
     * 整段写入：一次移位拼进缓冲，再按整字节落盘。
     * 与原逐 bit 循环产生的字节流完全一致（MSB 先写），但把每 bit 3 次运算降到每字节一次。
     */
    public function write(int $value, int $count): void
    {
        if ($count === 0) {
            return;
        }
        $this->bits += $count;
        $this->byte = ($this->byte << $count) | ($value & ((1 << $count) - 1));
        $this->used += $count;
        while ($this->used >= 8) {
            $this->used -= 8;
            $this->data .= chr(($this->byte >> $this->used) & 0xff);
        }
        $this->byte = $this->used > 0 ? $this->byte & ((1 << $this->used) - 1) : 0;
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
