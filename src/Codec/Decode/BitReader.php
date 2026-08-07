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
    private int $pos;

    public function __construct(string $data)
    {
        $this->data = $data;
        $this->bitLength = strlen($data) * 8;
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
