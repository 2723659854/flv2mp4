<?php

namespace Xiaosongshu\Flv2mp4\Codec\Decode;

/**
 * @purpose bit读取器
 * @author yanglong
 * @time 2026年7月23日14:37:33
 */
class BitReader
{
    private string $data;
    private int $bitLength;
    private int $byteLen;
    private int $pos;

    public function __construct(string $data)
    {
        $this->data = $data;
        $this->bitLength = strlen($data) * 8;
        $this->byteLen = strlen($data);
        $this->pos = 0;
    }

    public function skip(int $n): void
    {
        $this->pos = max(0, min($this->bitLength, $this->pos + $n));
    }

    public function readU(int $n): int
    {
        if ($n === 0) {
            return 0;
        }

        $pos = $this->pos;
        // 热路径：码流内全部语法元素宽度 ≤16bit，最多跨 3 个字节，直接拼窗口取值，
        // 避免通用 while/min 循环开销（基线 90 帧约 140 万次调用）
        if ($n <= 16 && $pos + $n <= $this->bitLength) {
            $bytePos = $pos >> 3;
            $bitOff = $pos & 7;
            $span = $bitOff + $n;
            $this->pos = $pos + $n;
            if ($span <= 8) {
                return (ord($this->data[$bytePos]) >> (8 - $span)) & ((1 << $n) - 1);
            }
            if ($span <= 16) {
                $w = (ord($this->data[$bytePos]) << 8) | ord($this->data[$bytePos + 1]);
                return ($w >> (16 - $span)) & ((1 << $n) - 1);
            }
            $w = (ord($this->data[$bytePos]) << 16) | (ord($this->data[$bytePos + 1]) << 8) | ord($this->data[$bytePos + 2]);
            return ($w >> (24 - $span)) & ((1 << $n) - 1);
        }

        $available = min($n, $this->bitLength - $pos);
        $remaining = $available;
        $value = 0;

        while ($remaining > 0) {
            $bitOffset = $pos & 7;
            $take = min($remaining, 8 - $bitOffset);
            $byte = ord($this->data[$pos >> 3]);
            $shift = 8 - $bitOffset - $take;
            $value = ($value << $take) | (($byte >> $shift) & ((1 << $take) - 1));
            $pos += $take;
            $remaining -= $take;
        }

        $this->pos = $pos;
        if ($available < $n) {
            $this->pos = $this->bitLength;
            $value <<= $n - $available;
        }
        return $value;
    }

    /**
     * CAVLC level_prefix 快速读取：返回连续 0 的个数（终止位 1 也一并消费）。
     * 热路径约 50 万次/帧，旧实现逐位 readU(1) 方法调用开销大，
     * 这里直接按字节窗口 + 256 项前导零查表，最多扫描 4 个字节。
     */
    public function readLevelPrefix(int $max = 32): int
    {
        static $clz8 = null;
        if ($clz8 === null) {
            $clz8 = array_fill(0, 256, 0);
            for ($v = 1; $v < 256; $v++) {
                $x = $v;
                $n = 0;
                while (($x & 0x80) === 0) {
                    $n++;
                    $x <<= 1;
                }
                $clz8[$v] = $n;
            }
        }

        $pos = $this->pos;
        if ($pos >= $this->bitLength) {
            return $max;
        }

        $bytePos = $pos >> 3;
        $bitOff = $pos & 7;

        // 当前字节的剩余有效位顶对齐后查表
        $b = (ord($this->data[$bytePos]) << $bitOff) & 0xFF;
        if ($b !== 0) {
            $clz = $clz8[$b];
            $this->pos = $pos + $clz + 1;
            return $clz;
        }

        $prefix = 8 - $bitOff;
        $bytePos++;

        while ($prefix + 8 <= $max && $bytePos < $this->byteLen) {
            $b = ord($this->data[$bytePos]);
            if ($b !== 0) {
                $clz = $clz8[$b];
                if ($prefix + $clz >= $max) {
                    $this->pos = $pos + $max;
                    return $max;
                }
                $this->pos = $pos + $prefix + $clz + 1;
                return $prefix + $clz;
            }
            $prefix += 8;
            $bytePos++;
        }

        // max 边界处不足一个整字节的剩余位
        if ($prefix < $max && $bytePos < $this->byteLen) {
            $rem = $max - $prefix;
            $b = ord($this->data[$bytePos]) >> (8 - $rem);
            if ($b !== 0) {
                $clz = $clz8[$b << (8 - $rem)];
                $this->pos = $pos + $prefix + $clz + 1;
                return $prefix + $clz;
            }
        }

        $this->pos = min($pos + $max, $this->bitLength);
        return $max;
    }

    public function readUe(): int
    {
        $start = $this->pos;
        $pos = $start;
        while ($pos < $this->bitLength) {
            $byte = ord($this->data[$pos >> 3]);
            if ((($byte >> (7 - ($pos & 7))) & 1) !== 0) {
                break;
            }
            $pos++;
        }

        if ($pos >= $this->bitLength) {
            $this->pos = $this->bitLength;
            return 0;
        }

        $leadingZeros = $pos - $start;
        if ($start + $leadingZeros + 1 + $leadingZeros > $this->bitLength) {
            $this->pos = $this->bitLength;
            return 0;
        }

        $this->pos = $pos + 1;
        $value = $this->readU($leadingZeros);
        return (1 << $leadingZeros) + $value - 1;
    }

    public function readSe(): int
    {
        $ue = $this->readUe();
        if ($ue % 2 === 0) {
            return -(int)($ue / 2);
        } else {
            return (int)(($ue + 1) / 2);
        }
    }

    public function readTe(int $range): int
    {
        if ($range === 1) {
            return 0;
        }
        if ($range === 2) {
            $bit = $this->readU(1);
            return $bit ^ 1;
        }
        return $this->readUe();
    }

    public function getPos(): int
    {
        return $this->pos;
    }

    public function getBitPosition(): int
    {
        return $this->pos;
    }

    public function getRemainingBits(): int
    {
        return max(0, $this->bitLength - $this->pos);
    }

    public function alignToByte(): void
    {
        $rem = $this->pos % 8;
        if ($rem !== 0) {
            $this->pos += 8 - $rem;
        }
        $this->pos = min($this->pos, $this->bitLength);
    }

    public function readByte(): int
    {
        $this->alignToByte();
        return $this->readU(8);
    }

    public function peek(int $n): int
    {
        if ($n === 0) {
            return 0;
        }

        $pos = $this->pos;
        // 与 readU 相同的 1/2/3 字节窗口快速路径（不推进位置）
        if ($n <= 16 && $pos + $n <= $this->bitLength) {
            $bytePos = $pos >> 3;
            $bitOff = $pos & 7;
            $span = $bitOff + $n;
            if ($span <= 8) {
                return (ord($this->data[$bytePos]) >> (8 - $span)) & ((1 << $n) - 1);
            }
            if ($span <= 16) {
                $w = (ord($this->data[$bytePos]) << 8) | ord($this->data[$bytePos + 1]);
                return ($w >> (16 - $span)) & ((1 << $n) - 1);
            }
            $w = (ord($this->data[$bytePos]) << 16) | (ord($this->data[$bytePos + 1]) << 8) | ord($this->data[$bytePos + 2]);
            return ($w >> (24 - $span)) & ((1 << $n) - 1);
        }

        $available = min($n, $this->bitLength - $pos);
        $remaining = $available;
        $value = 0;

        while ($remaining > 0) {
            $bitOffset = $pos & 7;
            $take = min($remaining, 8 - $bitOffset);
            $byte = ord($this->data[$pos >> 3]);
            $shift = 8 - $bitOffset - $take;
            $value = ($value << $take) | (($byte >> $shift) & ((1 << $take) - 1));
            $pos += $take;
            $remaining -= $take;
        }

        if ($available < $n) {
            $value <<= $n - $available;
        }
        return $value;
    }
}
