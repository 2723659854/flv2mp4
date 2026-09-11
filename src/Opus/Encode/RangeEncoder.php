<?php

namespace Xiaosongshu\Flv2mp4\Opus\Encode;

use InvalidArgumentException;
use RuntimeException;

final class RangeEncoder
{
    private const CODE_BOT = 0x00800000;
    private const CODE_TOP = 0x80000000;
    private const CODE_SHIFT = 31 - 8;
    private const UINT_BITS = 8;
    private const U32 = 0xFFFFFFFF;

    private int $range = self::CODE_TOP;
    private int $value = 0;
    private int $remainder = -1;
    private int $extension = 0;
    private int $totalBits = 33;
    private string $output = '';
    private array $rawQueue = [];
    private bool $finished = false;

    public function encode(int $low, int $high, int $total): void
    {
        if ($total < 2 || $total > 0xFFFF || $low < 0 || $low >= $high || $high > $total) {
            throw new InvalidArgumentException('Invalid range interval');
        }
        $unit = intdiv($this->range, $total);
        if ($low > 0) {
            $this->value = self::u32($this->value + $this->range - $unit * ($total - $low));
            $this->range = $unit * ($high - $low);
        } else {
            $this->range = self::u32($this->range - $unit * ($total - $high));
        }
        $this->normalize();
    }

    public function encodeBits(int $value, int $bits): void
    {
        if ($this->finished) {
            throw new RuntimeException('Cannot encode after finishing');
        }
        if ($bits < 1 || $bits > 25 || $value < 0 || $value >= (1 << $bits)) {
            throw new InvalidArgumentException('Raw bits must be 1..25 and fit the requested width');
        }
        $this->rawQueue[] = [$value, $bits];
        $this->totalBits += $bits;
    }

    public function encodeCdf(array $inverseCdf, int $symbol, int $precision = 8): void
    {
        if ($precision < 1 || $precision > 15 || $inverseCdf === [] || $symbol < 0 || $symbol >= count($inverseCdf)) {
            throw new InvalidArgumentException('Invalid inverse CDF');
        }
        $total = 1 << $precision;
        $high = $total;
        foreach ($inverseCdf as $index => $cdf) {
            if (!is_int($cdf) || $cdf < 0 || $cdf >= $total || ($index > 0 && $cdf > $inverseCdf[$index - 1])) {
                throw new InvalidArgumentException('Invalid inverse CDF entry');
            }
            if ($index === $symbol) {
                if ($cdf >= $high) {
                    throw new InvalidArgumentException('Invalid inverse CDF interval');
                }
                $this->encode($cdf, $high, $total);
                return;
            }
            $high = $cdf;
        }
        if ($inverseCdf[count($inverseCdf) - 1] !== 0) {
            throw new InvalidArgumentException('Invalid inverse CDF');
        }
        throw new InvalidArgumentException('Invalid inverse CDF symbol');
    }

    public function encodeLaplace(int $value, int $zeroFrequency, int $decay): void
    {
        if ($zeroFrequency < 1 || $zeroFrequency >= 32768 || $decay < 0 || $decay > 11456) {
            throw new InvalidArgumentException('Invalid Laplace parameters');
        }
        if ($value === 0) {
            $this->encode(0, $zeroFrequency, 32768);
            return;
        }
        $negative = $value < 0;
        $magnitude = abs($value);
        $low = $zeroFrequency;
        $frequency = (((32768 - 32 - $zeroFrequency) * (16384 - $decay)) >> 15) + 1;
        $current = 1;
        while ($current < $magnitude && $frequency > 1) {
            $low += 2 * $frequency;
            $frequency = ((($frequency * 2 - 2) * $decay) >> 15) + 1;
            $current++;
        }
        if ($current < $magnitude) {
            $low += 2 * ($magnitude - $current);
            $frequency = 1;
        }
        if ($negative) {
            $this->encode($low, $low + $frequency, 32768);
        } else {
            $this->encode($low + $frequency, min($low + 2 * $frequency, 32768), 32768);
        }
    }

    public function encodeStep(int $value, int $k0): void
    {
        if ($k0 < 0 || $value < 0 || $value > 2 * $k0) {
            throw new InvalidArgumentException('Invalid step value');
        }
        $total = ($k0 + 1) * 3 + $k0;
        if ($value <= $k0) {
            $low = 3 * $value;
            $high = $low + 3;
        } else {
            $low = ($value - 1 - $k0) + 3 * ($k0 + 1);
            $high = ($value - $k0) + 3 * ($k0 + 1);
        }
        $this->encode($low, $high, $total);
    }

    public function encodeTriangular(int $value, int $qn): void
    {
        if ($qn < 0 || $value < 0 || $value > $qn) {
            throw new InvalidArgumentException('Invalid triangular value');
        }
        $total = (intdiv($qn, 2) + 1) ** 2;
        if ($value <= intdiv($qn, 2)) {
            $low = intdiv($value * ($value + 1), 2);
            $frequency = $value + 1;
        } else {
            $low = $total - intdiv(($qn + 1 - $value) * ($qn + 2 - $value), 2);
            $frequency = $qn + 1 - $value;
        }
        $this->encode($low, $low + $frequency, $total);
    }

    public function encodeUint(int $value, int $total): void
    {
        if ($total < 1 || $total > self::U32 || $value < 0 || $value >= $total) {
            throw new InvalidArgumentException('Uniform value must be in 0..total-1');
        }
        $maximum = $total - 1;
        $bits = self::ilog($maximum);
        if ($bits > self::UINT_BITS) {
            $bits -= self::UINT_BITS;
            $highTotal = ($maximum >> $bits) + 1;
            $high = $value >> $bits;
            $this->encode($high, $high + 1, $highTotal);
            $this->encodeBits($value & ((1 << $bits) - 1), $bits);
            return;
        }
        $this->encode($value, $value + 1, $total);
    }

    public function encodeBitLogp(int $value, int $logp): void
    {
        if (($value !== 0 && $value !== 1) || $logp < 1 || $logp > 31) {
            throw new InvalidArgumentException('Bit value must be 0 or 1 and logp must be 1..31');
        }
        $oldRange = $this->range;
        $split = $oldRange >> $logp;
        $this->range = $value ? $split : self::u32($oldRange - $split);
        if ($value) {
            $this->value = self::u32($this->value + $oldRange - $split);
        }
        $this->normalize();
    }

    public function tell(): int
    {
        return $this->totalBits - self::ilog($this->range);
    }

    public function tellFrac(): int
    {
        $correction = [35733, 38967, 42495, 46340, 50535, 55109, 60097, 65535];
        $log = self::ilog($this->range);
        $range = $this->range >> ($log - 16);
        $index = ($range >> 12) - 8;
        $index += $range > $correction[$index] ? 1 : 0;
        return ($this->totalBits << 3) - (($log << 3) + $index);
    }

    public function finish(?int $targetBytes = null): string
    {
        if ($this->finished) {
            return $this->output;
        }
        if ($targetBytes !== null && ($targetBytes < 1 || $targetBytes > 1275)) {
            throw new InvalidArgumentException('Target frame size must be 1..1275 bytes');
        }
        $bits = 32 - self::ilog($this->range);
        $mask = self::u32(0x7FFFFFFF >> $bits);
        $end = self::u32($this->value + $mask) & self::u32(~$mask);
        if (($end | $mask) >= self::u32($this->value + $this->range)) {
            $bits++;
            $mask >>= 1;
            $end = self::u32($this->value + $mask) & self::u32(~$mask);
        }
        while ($bits > 0) {
            $this->carryOut($end >> self::CODE_SHIFT);
            $end = self::u32($end << 8) & 0x7FFFFFFF;
            $bits -= 8;
        }
        if ($this->remainder >= 0 || $this->extension > 0) {
            $this->carryOut(0);
        }
        $rangeOutput = $this->output;
        
        // Build raw stream from end: pack raw bits into bytes from LSB to MSB,
        // then reverse to form tail bytes (matching ec_write_byte_at_end).
        $rawBytes = [];
        $rawWindow = 0;
        $rawBits = 0;
        foreach ($this->rawQueue as [$value, $width]) {
            $rawWindow |= $value << $rawBits;
            $rawBits += $width;
            while ($rawBits >= 8) {
                $rawBytes[] = $rawWindow & 0xFF;
                $rawWindow >>= 8;
                $rawBits -= 8;
            }
        }
        $leftoverBits = $rawBits;
        $leftoverWindow = $rawWindow & ((1 << $rawBits) - 1);
        
        if ($targetBytes !== null) {
            $rangeLen = strlen($rangeOutput);
            $rawLen = count($rawBytes);

            if ($rangeLen + $rawLen > $targetBytes) {
                 throw new RuntimeException(sprintf(
                     'Encoded frame exceeds target size: range=%d raw=%d leftover=%d total=%d target=%d',
                     $rangeLen, $rawLen, $leftoverBits, $rangeLen + $rawLen, $targetBytes
                 ));
             }
            
            $rawOutput = implode('', array_map('chr', array_reverse($rawBytes)));
            $rangeBytes = $targetBytes - strlen($rawOutput);
            if ($rangeBytes < $rangeLen || $rangeBytes < 1) {
                throw new RuntimeException('Encoded frame exceeds target size');
            }
            $rangeOutput = str_pad($rangeOutput, $rangeBytes, "\0");
            if ($leftoverBits > 0) {
                $last = strlen($rangeOutput) - 1;
                $rangeOutput[$last] = chr(ord($rangeOutput[$last]) | $leftoverWindow);
            }
            $this->output = $rangeOutput . $rawOutput;
        } else {
            if ($leftoverBits > 0 && $rangeOutput !== '') {
                $last = strlen($rangeOutput) - 1;
                $rangeOutput[$last] = chr(ord($rangeOutput[$last]) | $leftoverWindow);
            }
            $this->output = $rangeOutput . implode('', array_map('chr', array_reverse($rawBytes)));
        }
        
        $this->finished = true;
        return $this->output;
    }

    public function getData(): string
    {
        return $this->finish();
    }

    private function normalize(): void
    {
        while ($this->range <= self::CODE_BOT) {
            $this->carryOut($this->value >> self::CODE_SHIFT);
            $this->value = self::u32($this->value << 8) & 0x7FFFFFFF;
            $this->range = self::u32($this->range << 8);
            $this->totalBits += 8;
        }
    }

    private function carryOut(int $value): void
    {
        $value &= 0x1FF;
        if ($value !== 0xFF) {
            $carry = $value >> 8;
            if ($this->remainder >= 0) {
                $this->output .= chr(($this->remainder + $carry) & 0xFF);
            }
            while ($this->extension > 0) {
                $this->output .= chr((0xFF + $carry) & 0xFF);
                $this->extension--;
            }
            $this->remainder = $value & 0xFF;
        } else {
            $this->extension++;
        }
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
