<?php

namespace Xiaosongshu\Flv2mp4\Opus;

use InvalidArgumentException;
use RuntimeException;

/**
 * @purpose 区间解码器
 * @author yanglong
 * @time 2026年8月12日17:27:57
 */
class RangeDecoder
{
    private const CODE_BOT = 0x00800000;
    private const CODE_TOP = 0x80000000;
    private const CODE_EXTRA = 7;
    private const UINT_BITS = 8;
    private const U32 = 0xFFFFFFFF;
    private const TELL_CORRECTION = [35733, 38967, 42495, 46340, 50535, 55109, 60097, 65535];

    private string $data;
    private int $storage;
    private int $offset = 0;
    private int $endOffset = 0;
    private int $endWindow = 0;
    private int $endBits = 0;
    private int $rawLimit;
    private int $totalBits = 33 - 24;
    private int $range = 128;
    private int $value;
    private int $remainder;
    private int $extension = 0;
    private bool $error = false;

    public function __construct(string $data)
    {
        $this->data = $data;
        $this->storage = strlen($data);
        $this->rawLimit = $this->storage;
        $this->remainder = $this->readByte();
        $this->value = $this->range - 1 - ($this->remainder >> 1);
        $this->normalize();
    }

    public function decode(int $total): int
    {
        if ($total < 2 || $total > 0xFFFF) {
            throw new InvalidArgumentException('Range total must be between 2 and 65535');
        }
        $this->extension = intdiv($this->range, $total);
        $symbol = intdiv($this->value, $this->extension);
        return $total - min($symbol + 1, $total);
    }

    public function decodeLog(int $bits): int
    {
        if ($bits < 1 || $bits > 15) {
            throw new InvalidArgumentException('Binary range precision must be 1..15');
        }
        $this->extension = $this->range >> $bits;
        $symbol = intdiv($this->value, $this->extension);
        $total = 1 << $bits;
        return $total - min($symbol + 1, $total);
    }

    public function update(int $low, int $high, int $total): void
    {
        if ($low < 0 || $low >= $high || $high > $total || $this->extension === 0) {
            throw new InvalidArgumentException('Invalid range interval');
        }
        $scaled = $this->extension * ($total - $high);
        $this->value = self::u32($this->value - $scaled);
        $this->range = $low > 0 ? $this->extension * ($high - $low) : self::u32($this->range - $scaled);
        $this->normalize();
    }

    public function decodeBitLogp(int $logp): int
    {
        if ($logp < 1 || $logp > 31) {
            throw new InvalidArgumentException('logp must be 1..31');
        }
        $split = $this->range >> $logp;
        $one = $this->value < $split;
        if (!$one) {
            $this->value = self::u32($this->value - $split);
        }
        $this->range = $one ? $split : self::u32($this->range - $split);
        $this->normalize();
        return $one ? 1 : 0;
    }

    public function decodeCdf(array $inverseCdf, int $precision = 8): int
    {
        if ($precision < 1 || $precision > 15 || $inverseCdf === [] || end($inverseCdf) !== 0) {
            throw new InvalidArgumentException('Invalid inverse CDF');
        }
        $unit = $this->range >> $precision;
        $current = $this->range;
        $symbol = 0;
        foreach ($inverseCdf as $symbol => $cdf) {
            if (!is_int($cdf) || $cdf < 0 || $cdf >= (1 << $precision)) {
                throw new InvalidArgumentException('Invalid inverse CDF entry');
            }
            $previous = $current;
            $current = $unit * $cdf;
            if ($this->value >= $current) {
                $this->value = self::u32($this->value - $current);
                $this->range = self::u32($previous - $current);
                $this->normalize();
                return $symbol;
            }
        }
        throw new RuntimeException('Inverse CDF did not terminate');
    }

    /**
     * FFmpeg 风格 CDF 解码（libavcodec/opus/rc.c ff_opus_rc_dec_cdf）。
     * CDF 数组格式为 [total, cdf1, cdf2, ..., total]，与 libopus 的反向 CDF 不同。
     * $offset 允许直接指向大表内某个子 CDF 的起始下标（SILK 的 pulse_location 表）。
     */
    public function decodeCdfFfmpeg(array $cdf, int $offset = 0): int
    {
        $total = $cdf[$offset];
        $scale = intdiv($this->range, $total);
        $symbol = intdiv($this->value, $scale) + 1;
        $symbol = $total - min($symbol, $total);
        $count = count($cdf);
        $k = 0;
        while ($offset + 1 + $k < $count && $cdf[$offset + 1 + $k] <= $symbol) {
            $k++;
        }
        $high = $cdf[$offset + 1 + $k];
        $low = $k > 0 ? $cdf[$offset + $k] : 0;
        $this->extension = $scale;
        $this->update($low, $high, $total);
        return $k;
    }

    public function decodeUint(int $total): int
    {
        if ($total < 2 || $total > self::U32) {
            throw new InvalidArgumentException('Uniform total must be 2..2^32-1');
        }
        $maximum = $total - 1;
        $bits = self::ilog($maximum);
        if ($bits > self::UINT_BITS) {
            $bits -= self::UINT_BITS;
            $highTotal = ($maximum >> $bits) + 1;
            $high = $this->decode($highTotal);
            $this->update($high, $high + 1, $highTotal);
            $value = ($high << $bits) | $this->rawBits($bits);
            return min($value, $maximum);
        }
        $value = $this->decode($total);
        $this->update($value, $value + 1, $total);
        return $value;
    }

    public function decodeLaplace(int $zeroFrequency, int $decay): int
    {
        if ($zeroFrequency < 1 || $zeroFrequency >= 32768 || $decay < 0 || $decay > 11456) {
            throw new InvalidArgumentException('Invalid Laplace parameters');
        }
        $frequencyValue = $this->decodeLog(15);
        $low = 0;
        $value = 0;
        $frequency = $zeroFrequency;
        if ($frequencyValue >= $frequency) {
            $value = 1;
            $low = $frequency;
            $frequency = (((32768 - 32 - $frequency) * (16384 - $decay)) >> 15) + 1;
            while ($frequency > 1 && $frequencyValue >= $low + 2 * $frequency) {
                $frequency *= 2;
                $low += $frequency;
                $frequency = ((($frequency - 2) * $decay) >> 15) + 1;
                $value++;
            }
            if ($frequency <= 1) {
                $delta = ($frequencyValue - $low) >> 1;
                $value += $delta;
                $low += 2 * $delta;
            }
            if ($frequencyValue < $low + $frequency) {
                $value = -$value;
            } else {
                $low += $frequency;
            }
        }
        $this->update($low, min($low + $frequency, 32768), 32768);
        return $value;
    }

    public function rawBits(int $bits): int
    {
        if ($bits < 0 || $bits > 25) {
            throw new InvalidArgumentException('Raw bit count must be 0..25');
        }
        if ($bits === 0) {
            return 0;
        }
        while ($this->endBits < $bits) {
            $this->endWindow |= $this->readByteFromEnd() << $this->endBits;
            $this->endBits += 8;
        }
        $mask = (1 << $bits) - 1;
        $value = $this->endWindow & $mask;
        $this->endWindow >>= $bits;
        $this->endBits -= $bits;
        $this->totalBits += $bits;
        return $value;
    }

    public function tell(): int
    {
        return $this->totalBits - self::ilog($this->range);
    }

    public function tellFrac(): int
    {
        $log = self::ilog($this->range);
        $range = $this->range >> ($log - 16);
        $index = ($range >> 12) - 8;
        $index += $range > self::TELL_CORRECTION[$index] ? 1 : 0;
        return ($this->totalBits << 3) - (($log << 3) + $index);
    }

    public function hasError(): bool
    {
        return $this->error;
    }

    public function range(): int
    {
        return $this->range;
    }

    public function decodeStep(int $k0): int
    {
        $total = ($k0 + 1) * 3 + $k0;
        $symbol = $this->decode($total);
        $value = $symbol < ($k0 + 1) * 3 ? intdiv($symbol, 3) : $symbol - ($k0 + 1) * 2;
        $low = $value <= $k0 ? 3 * $value : ($value - 1 - $k0) + 3 * ($k0 + 1);
        $high = $value <= $k0 ? 3 * ($value + 1) : ($value - $k0) + 3 * ($k0 + 1);
        $this->update($low, $high, $total);
        return $value;
    }

    public function decodeTriangular(int $qn): int
    {
        $total = (intdiv($qn, 2) + 1) ** 2;
        $center = $this->decode($total);
        if ($center < ($total >> 1)) {
            $value = intdiv(self::isqrt(8 * $center + 1) - 1, 2);
            $low = intdiv($value * ($value + 1), 2);
            $frequency = $value + 1;
        } else {
            $value = intdiv(2 * ($qn + 1) - self::isqrt(8 * ($total - $center - 1) + 1), 2);
            $low = $total - intdiv(($qn + 1 - $value) * ($qn + 2 - $value), 2);
            $frequency = $qn + 1 - $value;
        }
        $this->update($low, $low + $frequency, $total);
        return $value;
    }

    private static function isqrt(int $value): int
    {
        $root = (int) sqrt($value);
        while (($root + 1) * ($root + 1) <= $value) $root++;
        while ($root * $root > $value) $root--;
        return $root;
    }

    private function normalize(): void
    {
        while ($this->range <= self::CODE_BOT) {
            $this->totalBits += 8;
            $this->range = self::u32($this->range << 8);
            $symbol = $this->remainder;
            $this->remainder = $this->readByte();
            $symbol = (($symbol << 8) | $this->remainder) >> 1;
            $this->value = self::u32(($this->value << 8) + (255 & ~$symbol)) & 0x7FFFFFFF;
        }
    }

    private function readByte(): int
    {
        return $this->offset < $this->storage ? ord($this->data[$this->offset++]) : 0;
    }

    private function readByteFromEnd(): int
    {
        return $this->endOffset < $this->rawLimit ? ord($this->data[$this->rawLimit - ++$this->endOffset]) : 0;
    }

    /**
     * FFmpeg ff_opus_rc_dec_raw_init：把原始位读取器重新指向帧尾（可选地缩短可用字节数），
     * 区间解码状态与 totalBits 保持不变。Hybrid 中 SILK 与 CELT 各自从帧尾独立读取原始位。
     */
    public function resetRaw(int $bytes = -1): void
    {
        if ($bytes >= 0) {
            $this->rawLimit = min($bytes, $this->storage);
        }
        $this->endOffset = 0;
        $this->endWindow = 0;
        $this->endBits = 0;
    }

    private static function ilog(int $value): int
    {
        $bits = 0;
        while ($value > 0) {
            $bits++;
            $value >>= 1;
        }
        return $bits;
    }

    private static function u32(int $value): int
    {
        return $value & self::U32;
    }
}
