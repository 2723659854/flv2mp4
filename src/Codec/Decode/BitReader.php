<?php

namespace Xiaosongshu\Flv2mp4\Codec\Decode;

/**
 * @purpose bit读取器
 * @author yanglong
 * @time 2026年7月23日14:37:33
 */
class BitReader
{
    private string $bits;
    private int $pos;

    public function __construct(string $data)
    {
        $this->bits = '';
        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            $this->bits .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
        }
        $this->pos = 0;
    }

    public function skip(int $n): void
    {
        $total = strlen($this->bits);
        $this->pos = max(0, min($total, $this->pos + $n));
    }

    public function readU(int $n): int
    {
        $total = strlen($this->bits);
        if ($this->pos + $n > $total) {
            $remain = $total - $this->pos;
            $read = substr($this->bits, $this->pos, $remain);
            $this->pos = $total;
            return bindec(str_pad($read, $n, '0', STR_PAD_RIGHT));
        }
        $value = bindec(substr($this->bits, $this->pos, $n));
        $this->pos += $n;
        return $value;
    }

    public function readUe(): int
    {
        $leadingZeros = 0;
        $total = strlen($this->bits);
        while (($this->pos + $leadingZeros) < $total && $this->bits[$this->pos + $leadingZeros] === '0') {
            $leadingZeros++;
        }
        if (($this->pos + $leadingZeros) >= $total) {
            $this->pos = $total;
            return 0;
        }

        $totalBitsNeeded = $leadingZeros + 1 + $leadingZeros;
        if ($this->pos + $totalBitsNeeded > $total) {
            $this->pos = $total;
            return 0;
        }

        // 跳过分隔符 '1'
        $this->pos += $leadingZeros + 1;

        // 读取 leadingZeros 个数据位
        $value = 0;
        for ($i = 0; $i < $leadingZeros; $i++) {
            $bit = ($this->bits[$this->pos + $i] === '1' ? 1 : 0);
            $value = ($value << 1) | $bit;
        }
        $this->pos += $leadingZeros;

        // 计算 codeNum = 2^leadingZeros + value
        $codeNum = (1 << $leadingZeros) + $value;
        return $codeNum - 1;
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
        return max(0, strlen($this->bits) - $this->pos);
    }

    public function alignToByte(): void
    {
        $rem = $this->pos % 8;
        if ($rem !== 0) {
            $this->pos += 8 - $rem;
        }
        $this->pos = min($this->pos, strlen($this->bits));
    }

    public function readByte(): int
    {
        $this->alignToByte();
        return $this->readU(8);
    }

    public function peek(int $n): int
    {
        $total = strlen($this->bits);
        if ($this->pos + $n > $total) {
            $remain = $total - $this->pos;
            $read = substr($this->bits, $this->pos, $remain);
            return bindec(str_pad($read, $n, '0', STR_PAD_RIGHT));
        }
        return bindec(substr($this->bits, $this->pos, $n));
    }
}

