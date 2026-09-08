<?php
namespace Xiaosongshu\Flv2mp4\Mp3;

/**
 * @purpose mp3比特读取器
 * @author yanglong
 * @time 2026年9月8日15:08:07
 */
final class BitReader
{
    private int $position = 0;
    private int $length;
    public function __construct(private readonly string $data, int $length = -1)
    {
        $this->length = $length < 0 ? strlen($data) * 8 : $length;
    }
    public function position(): int { return $this->position; }
    public function remaining(): int { return $this->length - $this->position; }
    public function skip(int $bits): void { $this->position = min($this->length, $this->position + $bits); }
    public function read(int $bits): int
    {
        if ($bits < 0 || $bits > 31 || $this->position + $bits > $this->length) throw new \RuntimeException('MP3 bitstream truncated');
        $value = 0;
        for ($i = 0; $i < $bits; ++$i) {
            $p = $this->position++;
            $value = ($value << 1) | ((ord($this->data[intdiv($p, 8)]) >> (7 - ($p & 7))) & 1);
        }
        return $value;
    }
}
