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
        if ($this->finished) {
            throw new RuntimeException('Cannot encode after finishing');
        }
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

    public function ec_encode(int $low, int $high, int $total): void
    {
        $this->encode($low, $high, $total);
    }

    public function ec_enc_bits(int $value, int $bits): void
    {
        $this->encodeBits($value, $bits);
    }

    public function ec_enc_icdf(int $symbol, array $inverseCdf, int $precision): void
    {
        $this->encodeCdf($inverseCdf, $symbol, $precision);
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
        $previous = $total;
        foreach ($inverseCdf as $index => $cdf) {
            if (!is_int($cdf) || $cdf < 0 || $cdf > $previous) {
                throw new InvalidArgumentException('Invalid inverse CDF entry');
            }
            $previous = $cdf;
        }
        if (end($inverseCdf) !== 0) {
            throw new InvalidArgumentException('Invalid inverse CDF');
        }
        // Direct port of ec_enc_icdf()/ec_enc_icdf16() in opus-main/celt/entenc.c.
        // For symbol 0 the coder narrows the range without moving value; for
        // s>0 it shifts value by the previous (higher) cumulative boundary.
        $unit = $this->range >> $precision;
        if ($symbol > 0) {
            $this->value = self::u32($this->value + $this->range - $unit * $inverseCdf[$symbol - 1]);
            $this->range = $unit * ($inverseCdf[$symbol - 1] - $inverseCdf[$symbol]);
        } else {
            $this->range = self::u32($this->range - $unit * $inverseCdf[0]);
        }
        $this->normalize();
    }

    /**
     * Normative Laplace encode, direct port of ec_laplace_encode() /
     * ec_laplace_get_freq1() in opus-main/celt/laplace.c (LAPLACE_MINP=1,
     * LAPLACE_NMIN=16). Returns the (possibly clamped) coded magnitude, which
     * the caller must use exactly as C rewrites *value.
     */
    public function encodeLaplace(int $value, int $zeroFrequency, int $decay): int
    {
        if ($zeroFrequency < 1 || $zeroFrequency >= 32768 || $decay < 0 || $decay > 11456) {
            throw new InvalidArgumentException('Invalid Laplace parameters');
        }
        $fl = 0;
        $fs = $zeroFrequency;
        if ($value !== 0) {
            $s = $value < 0 ? -1 : 0;
            $val = abs($value);
            $fl = $zeroFrequency;
            // ec_laplace_get_freq1(): (32768 - 2*16*MINP - fs0)*(16384-decay)>>15
            $fs = ((32768 - 32 - $zeroFrequency) * (16384 - $decay)) >> 15;
            $i = 1;
            while ($fs > 0 && $i < $val) {
                $fs *= 2;
                $fl += $fs + 2 * 1; // +2*LAPLACE_MINP
                $fs = ($fs * $decay) >> 15;
                $i++;
            }
            if ($fs === 0) {
                // LAPLACE_LOG_MINP=0, LAPLACE_MINP=1:
                // ndi_max = (32768-fl+MINP-1)>>0 = 32768-fl; then (..-s)>>1.
                $ndiMax = (32768 - $fl - $s) >> 1;
                $di = min($val - $i, $ndiMax - 1);
                $fl += 2 * $di + 1 + $s;
                $fs = min(1, 32768 - $fl);
                // *value = (i+di+s)^s: positive -> i+di, negative -> -(i+di).
                $value = $s === -1 ? -($i + $di) : ($i + $di);
            } else {
                $fs += 1; // LAPLACE_MINP
                // C: fl += fs & ~s on 32-bit ints (~(-1)==0). Positive (s=0)
                // adds fs; negative (s=-1) adds nothing.
                $fl += $s === 0 ? $fs : 0;
            }
        }
        // C uses ec_encode_bin(fl, fl+fs, 15) == encode with total 1<<15.
        $this->encode($fl, min($fl + $fs, 32768), 32768);
        return $value;
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

    public function ec_tell(): int
    {
        return $this->tell();
    }

    public function ec_tell_frac(): int
    {
        return $this->tellFrac();
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

    public function ec_enc_done(): string
    {
        return $this->finish();
    }

    public function getFinalRange(): int
    {
        return self::u32($this->value + $this->range);
    }

    public function snapshot(): array
    {
        return get_object_vars($this);
    }

    public function restore(array $snapshot): void
    {
        foreach ($snapshot as $name => $value) {
            if (property_exists($this, $name)) {
                $this->$name = $value;
            }
        }
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
