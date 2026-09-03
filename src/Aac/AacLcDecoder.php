<?php

namespace Xiaosongshu\Flv2mp4\Aac;

use InvalidArgumentException;
use RuntimeException;

/**
 * @purpose aac-lc 解码器
 * @author yanglong
 * @time 2026年9月3日16:21:41
 */
final class AacLcDecoder
{
    private const RATES = [96000, 88200, 64000, 48000, 44100, 32000, 24000, 22050, 16000, 12000, 11025, 8000, 7350];
    private string $buffer = '';
    private int $sampleRate = 0;
    private int $channels = 0;
    private array $overlap = [[], []];
    private int $frameIndex = 0;
    private static array $imdctFactors = [];
    private static array $windowCache = [];

    public function push(string $data): string
    {
        $this->buffer .= $data;
        $out = '';
        while (strlen($this->buffer) >= 7) {
            if (ord($this->buffer[0]) !== 0xff || (ord($this->buffer[1]) & 0xf6) !== 0xf0) {
                $this->buffer = substr($this->buffer, 1);
                continue;
            }
            $h = unpack('C7', substr($this->buffer, 0, 7));
            $protection = $h[2] & 1;
            $length = (($h[4] & 3) << 11) | ($h[5] << 3) | (($h[6] >> 5) & 7);
            $header = $protection ? 7 : 9;
            if ($length < $header || strlen($this->buffer) < $length) break;
            $frame = substr($this->buffer, 0, $length);
            $this->buffer = substr($this->buffer, $length);
            ++$this->frameIndex;
            try {
                $out .= $this->decodeFrame($frame);
            } catch (\Throwable $e) {
                throw new RuntimeException("AAC frame {$this->frameIndex} failed: {$e->getMessage()}", 0, $e);
            }
        }
        return $out;
    }

    public function decodeFrame(string $frame): string
    {
        if (strlen($frame) < 7) throw new InvalidArgumentException('AAC ADTS frame is truncated');
        $h = unpack('C7', substr($frame, 0, 7));
        if ($h[1] !== 0xff || ($h[2] & 0xf6) !== 0xf0) throw new InvalidArgumentException('Invalid AAC ADTS sync word');
        if ((($h[3] >> 6) & 3) !== 1) throw new InvalidArgumentException('Only AAC-LC ADTS is supported');
        $rateIndex = ($h[3] >> 2) & 15;
        if (!isset(self::RATES[$rateIndex])) throw new InvalidArgumentException('Unsupported AAC sample rate');
        $channels = (($h[3] & 1) << 2) | (($h[4] >> 6) & 3);
        if ($channels < 1 || $channels > 2) throw new InvalidArgumentException('Only mono and stereo AAC are supported');
        $length = (($h[4] & 3) << 11) | ($h[5] << 3) | (($h[6] >> 5) & 7);
        $header = ($h[2] & 1) ? 7 : 9;
        if ($length > strlen($frame) || $length < $header) throw new InvalidArgumentException('AAC ADTS frame length is invalid');
        if ($this->channels !== $channels || $this->sampleRate !== self::RATES[$rateIndex]) {
            $this->channels = $channels; $this->sampleRate = self::RATES[$rateIndex];
            $this->overlap = array_fill(0, $channels, array_fill(0, 1024, 0.0));
        }
        $reader = new AacBitReader(substr($frame, $header, $length - $header));
        $pcm = $this->readRawData($reader, $channels, $rateIndex);
        $result = '';
        for ($i = 0; $i < 1024; ++$i) for ($ch = 0; $ch < $channels; ++$ch) {
            $v = max(-32768, min(32767, (int) round($pcm[$ch][$i] * 32767.0)));
            $result .= pack('v', $v < 0 ? $v + 65536 : $v);
        }
        return $result;
    }

    public function flush(): string { $this->buffer = ''; return ''; }
    public function sampleRate(): int { return $this->sampleRate; }
    public function channels(): int { return $this->channels; }

    private function readRawData(AacBitReader $r, int $channels, int $rateIndex): array
    {
        $audioType = $channels === 1 ? 0 : 1;
        $audio = null;
        while (true) {
            $element = $r->read(3);
            if ($element === 7) break;
            $elementId = $r->read(4);
            if ($element === 4) {
                $align = $r->read(1);
                $count = $r->read(8);
                if ($count === 255) {
                    $count += $r->read(8);
                }
                if ($align) {
                    $r->align();
                }
                $r->skip($count * 8);
                continue;
            }
            if ($element === 6) {
                $count = $elementId;
                if ($count === 15) {
                    $count += $r->read(8) - 1;
                }
                $r->skip($count * 8);
                continue;
            }
            if ($element !== $audioType || $audio !== null) {
                throw new RuntimeException('Unsupported or duplicate AAC audio element');
            }

            $ics = null;
            if ($channels === 2) {
                $common = $r->read(1);
                if (!$common) throw new RuntimeException('Only common-window stereo AAC is supported');
                $ics = $this->readIcsInfo($r);
                $msMask = $r->read(2);
                $msFlags = [];
                if ($msMask === 1) {
                    for ($group = 0; $group < count($ics[3]); ++$group) {
                        for ($band = 0; $band < $ics[0]; ++$band) {
                            $msFlags[$group][$band] = $r->read(1);
                        }
                    }
                }
                $gain = $r->read(8);
                $a = $this->readChannel($r, $gain, $ics, $rateIndex);
                $gain = $r->read(8);
                $b = $this->readChannel($r, $gain, $ics, $rateIndex);
                if ($msMask !== 0) {
                    $this->applyMsStereo($a, $b, $ics, $msMask, $msFlags);
                }
            } else {
                $gain = $r->read(8); $ics = $this->readIcsInfo($r);
                $a = $this->readChannel($r, $gain, $ics, $rateIndex); $b = null;
            }
            $audio = $channels === 1 ? [$this->imdct($a, 0, $ics[1], $ics[2])] : [$this->imdct($a, 0, $ics[1], $ics[2]), $this->imdct($b, 1, $ics[1], $ics[2])];
        }
        if ($audio === null) throw new RuntimeException('AAC raw_data_block has no audio element');
        return $audio;
    }

    private function readIcsInfo(AacBitReader $r): array
    {
        $r->read(1); $sequence = $r->read(2); $shape = $r->read(1);
        if ($sequence === 2) {
            $max = $r->read(4);
            $groups = [1];
            for ($i = 0; $i < 7; ++$i) {
                if ($r->read(1)) ++$groups[count($groups) - 1];
                else $groups[] = 1;
            }
            return [$max, $shape, $sequence, $groups];
        }
        $max = $r->read(6); $r->read(1);
        return [$max, $shape, $sequence, [1]];
    }

    private function readShortChannel(AacBitReader $r, int $gain, int $max, int $sequence, array $groups): array
    {
        $offsets = [0, 4, 8, 12, 16, 20, 28, 36, 44, 56, 68, 80, 96, 112, 128];
        if ($max < 0 || $max > 14) throw new RuntimeException('Invalid AAC max_sfb');
        $codebooks = [];
        foreach ($groups as $group => $_) {
            $band = 0;
            while ($band < $max) {
                $code = $r->read(4); $run = 0;
                do { $n = $r->read(3); $run += $n; } while ($n === 7);
                if ($band + $run > $max) throw new RuntimeException('Invalid AAC short section length');
                for ($i = 0; $i < $run; ++$i) $codebooks[$group][$band + $i] = $code;
                $band += $run;
            }
        }
        $scaleFactors = [];
        foreach ($groups as $group => $_) {
            $last = $gain; $noise = $gain; $noiseSeen = false;
            for ($band = 0; $band < $max; ++$band) {
                $codebook = $codebooks[$group][$band] ?? 0;
                if ($codebook === 0) continue;
                if ($codebook === 13) {
                    $noise = $noiseSeen ? $noise + $this->readScaleFactor($r) : $r->read(9);
                    $noiseSeen = true; $scaleFactors[$group][$band] = $noise; continue;
                }
                if ($codebook === 14 || $codebook === 15) {
                    $last += $this->readScaleFactor($r);
                    if ($last < 0 || $last > 255) throw new RuntimeException('Invalid AAC intensity scale factor');
                    $scaleFactors[$group][$band] = $last; continue;
                }
                if ($codebook > 11) throw new RuntimeException("Unsupported AAC section codebook {$codebook}");
                $last += $this->readScaleFactor($r);
                if ($last < 0 || $last > 255) throw new RuntimeException('Invalid AAC scale factor');
                $scaleFactors[$group][$band] = $last;
            }
        }
        if ($r->read(1)) throw new RuntimeException('AAC pulse tool is invalid for short windows');
        $tnsPresent = $r->read(1);
        $gainControlPresent = $r->read(1);
        if ($tnsPresent || $gainControlPresent) throw new RuntimeException('Unsupported AAC TNS/gain control');
        $spectrum = array_fill(0, 1024, 0.0); $window = 0;
        foreach ($groups as $group => $windowCount) {
            for ($band = 0; $band < $max; ++$band) {
                $codebook = $codebooks[$group][$band] ?? 0;
                if ($codebook === 0 || $codebook >= 12) continue;
                $scale = pow(2.0, ($scaleFactors[$group][$band] - 100) / 4.0) / 32768.0;
                $step = $codebook <= 4 ? 4 : 2;
                for ($w = 0; $w < $windowCount; ++$w) {
                    for ($p = $offsets[$band]; $p < $offsets[$band + 1]; $p += $step) {
                        foreach ($this->readSpectral($r, $codebook) as $j => $value) {
                            if ($p + $j < $offsets[$band + 1]) $spectrum[($window + $w) * 128 + $p + $j] = ($value < 0 ? -1 : 1) * pow(abs($value), 4.0 / 3.0) * $scale;
                        }
                    }
                }
            }
            $window += $windowCount;
        }
        return $spectrum;
    }

    private function applyMsStereo(array &$mid, array &$side, array $ics, int $msMask, array $msFlags): void
    {
        [$max, , $sequence, $groups] = $ics;
        $offsets = $sequence === 2
            ? [0, 4, 8, 12, 16, 20, 28, 36, 44, 56, 68, 80, 96, 112, 128]
            : AacTables::SWB_48K;
        $window = 0;
        foreach ($groups as $group => $windowCount) {
            for ($band = 0; $band < $max; ++$band) {
                $enabled = $msMask === 2 || (($msFlags[$group][$band] ?? 0) !== 0);
                if ($enabled) {
                    $start = $offsets[$band];
                    $end = $offsets[$band + 1];
                    for ($w = 0; $w < $windowCount; ++$w) {
                        for ($i = $start; $i < $end; ++$i) {
                            $index = $sequence === 2 ? ($window + $w) * 128 + $i : $i;
                            $m = $mid[$index];
                            $s = $side[$index];
                            $mid[$index] = ($m + $s) * 0.7071067811865476;
                            $side[$index] = ($m - $s) * 0.7071067811865476;
                        }
                    }
                }
            }
            $window += $windowCount;
        }
    }

    private function readChannel(AacBitReader $r, int $gain, array $ics, int $rateIndex): array
    {
        [$max, $shape, $sequence] = $ics;
        if ($sequence === 2) return $this->readShortChannel($r, $gain, $max, $sequence, $ics[3]);
        $bands = []; $cb = [];
        $band = 0;
        if ($max < 0 || $max > 51) throw new RuntimeException('Invalid AAC max_sfb');
        $numSwb = [41, 41, 47, 49, 49, 51, 47, 47, 43, 43, 43, 40, 40][$rateIndex] ?? 0;
        if ($max > $numSwb) throw new RuntimeException('Invalid AAC max_sfb');
        $sectionBits = 5;
        while ($band < $max) {
            $code = $r->read(4); $run = 0;
            do {
                $n = $r->read($sectionBits);
                $run += $n;
            } while ($n === ((1 << $sectionBits) - 1));
            if ($band + $run > $max) throw new RuntimeException("Invalid AAC section length (run={$run}, band={$band}, max={$max}, code={$code})");
            for ($i = 0; $i < $run; ++$i) $cb[$band + $i] = $code;
            $band += $run;
        }
        $sf = array_fill(0, $max, $gain); $last = $gain; $noise = $gain; $noiseSeen = false;
        for ($i = 0; $i < $max; ++$i) {
            $codebook = $cb[$i] ?? 0;
            if ($codebook === 0) continue;
            if ($codebook === 13) {
                if (!$noiseSeen) {
                    $noise = $r->read(9);
                    $noiseSeen = true;
                } else {
                    $noise += $this->readScaleFactor($r);
                }
                $sf[$i] = $noise;
                continue;
            }
            if ($codebook === 14 || $codebook === 15) {
                $last += $this->readScaleFactor($r);
                if ($last < 0 || $last > 255) throw new RuntimeException('Invalid AAC intensity scale factor');
                $sf[$i] = $last;
                continue;
            }
            if ($codebook > 11) throw new RuntimeException("Unsupported AAC section codebook {$codebook}");
            $last += $this->readScaleFactor($r);
            if ($last < 0 || $last > 255) throw new RuntimeException('Invalid AAC scale factor');
            $sf[$i] = $last;
        }
        $pulsePresent = $r->read(1);
        if ($pulsePresent) {
            $pulseCount = $r->read(2) + 1;
            $r->read(6);
            for ($i = 0; $i < $pulseCount; ++$i) {
                $r->read(5);
                $r->read(4);
            }
        }
        $tnsPresent = $r->read(1);
        $gainControlPresent = $r->read(1);
        if ($tnsPresent || $gainControlPresent) throw new RuntimeException('Unsupported AAC TNS/gain control');
        $spectrum = array_fill(0, 1024, 0.0); $offsets = AacTables::SWB_48K;
        for ($i = 0; $i < $max; ++$i) {
            $start = $offsets[$i]; $end = $offsets[$i + 1];
            $codebook = $cb[$i] ?? 0;
            if ($codebook === 0 || $codebook >= 12) continue;
            if ($codebook < 1 || $codebook > 11) throw new RuntimeException("Unsupported AAC spectral codebook {$codebook}");
            $scale = pow(2.0, ($sf[$i] - 100) / 4.0) / 32768.0;
            $step = ($codebook <= 4) ? 4 : 2;
            for ($p = $start; $p < $end; $p += $step) {
                $values = $this->readSpectral($r, $codebook);
                foreach ($values as $j => $value) {
                    if ($p + $j < $end) {
                        $spectrum[$p + $j] = ($value < 0 ? -1 : 1) * pow(abs($value), 4.0 / 3.0) * $scale;
                    }
                }
            }
        }
        return $spectrum;
    }

    private function readScaleFactor(AacBitReader $r): int
    {
        $code = 0;
        for ($n = 1; $n <= 19; ++$n) { $code = ($code << 1) | $r->read(1); foreach (AacTables::SCALEFACTOR_BITS as $i => $bits) if ($bits === $n && $code === AacTables::SCALEFACTOR_CODES[$i]) return $i - 60; }
        throw new RuntimeException('Invalid AAC scale factor code');
    }

    private function readSpectral(AacBitReader $r, int $book): array
    {
        [$codes, $bits] = AacTables::spectral($book);
        $code = 0;
        foreach (range(1, 16) as $n) {
            $code = ($code << 1) | $r->read(1);
            foreach ($codes as $index => $value) {
                if ($bits[$index] === $n && $value === $code) {
                    $width = $book <= 4 ? 2 : ($book <= 6 ? 4 : ($book <= 8 ? 3 : ($book <= 10 ? 4 : 5)));
                    $base = $book <= 4 ? 3 : ($book <= 6 ? 9 : ($book <= 8 ? 8 : ($book <= 10 ? 13 : 17)));
                    $components = $book <= 4 ? 4 : 2;
                    $values = [];
                    $indexValues = [];
                    for ($j = 0; $j < $components; ++$j) {
                        $digit = $index % $base;
                        $index = intdiv($index, $base);
                        $indexValues[$components - 1 - $j] = $digit;
                    }
                    for ($j = 0; $j < $components; ++$j) {
                        $v = $indexValues[$j];
                        if ($book === 1 || $book === 2) $v -= 1;
                        if ($book === 5 || $book === 6) $v -= 4;
                        $values[] = $v;
                    }
                    if ($book === 3 || $book === 4 || $book >= 7) {
                        foreach ($values as $j => $v) {
                            if ($v !== 0 && $r->read(1)) $values[$j] = -$v;
                        }
                    }
                    if ($book === 11) {
                        foreach ($values as $j => $v) {
                            if (abs($v) !== 16) continue;
                            $negative = $v < 0;
                            $n = 4; while ($r->read(1)) ++$n;
                            $values[$j] = (1 << $n) + $r->read($n);
                            if ($negative) $values[$j] = -$values[$j];
                        }
                    }
                    return $values;
                }
            }
        }
        throw new RuntimeException('Invalid AAC spectral code');
    }

    private function imdct(array $spectrum, int $channel, int $windowShape, int $sequence): array
    {
        $windowed = array_fill(0, 2048, 0.0);
        if ($sequence === 2) {
            for ($w = 0; $w < 8; ++$w) {
                $time = $this->imdctBlock(array_slice($spectrum, $w * 128, 128), 128);
                $offset = 448 + $w * 128;
                for ($n = 0; $n < 256; ++$n) {
                    $windowed[$offset + $n] += $time[$n] * $this->shortWindow($n, $windowShape);
                }
            }
        } else {
            $time = $this->imdctBlock(array_slice($spectrum, 0, 1024), 1024);
            for ($n = 0; $n < 2048; ++$n) {
                $windowed[$n] = $time[$n] * $this->longWindow($n, $windowShape, $sequence);
            }
        }
        $out = array_fill(0, 1024, 0.0);
        for ($i = 0; $i < 1024; ++$i) $out[$i] = $this->overlap[$channel][$i] + $windowed[$i];
        $this->overlap[$channel] = array_slice($windowed, 1024);
        return $out;
    }

    /** Direct AAC IMDCT; the reference form avoids phase/index errors in a fast convolution. */
    private function imdctBlock(array $spectrum, int $n): array
    {
        $key = (string) $n;
        if (!isset(self::$imdctFactors[$key])) {
            $factors = [];
            for ($m = 0; $m < $n * 2; ++$m) {
                $row = [];
                $time = $m + 0.5 + $n / 2.0;
                for ($k = 0; $k < $n; ++$k) {
                    $row[$k] = cos(M_PI / $n * $time * ($k + 0.5));
                }
                $factors[$m] = $row;
            }
            self::$imdctFactors[$key] = $factors;
        }
        $out = array_fill(0, $n * 2, 0.0);
        $active = [];
        for ($k = 0; $k < $n; ++$k) {
            if ($spectrum[$k] != 0.0) {
                $active[] = $k;
            }
        }
        if ($active === []) {
            return $out;
        }
        $scale = 2.0 / $n;
        $factors = self::$imdctFactors[$key];
        for ($m = 0; $m < $n * 2; ++$m) {
            $sum = 0.0;
            $row = $factors[$m];
            foreach ($active as $k) {
                $sum += $spectrum[$k] * $row[$k];
            }
            $out[$m] = $sum * $scale;
        }
        return $out;
    }

    private function inverseFft(array &$real, array &$imag): void
    {
        foreach ($imag as $i => $value) $imag[$i] = -$value;
        $this->fft($real, $imag);
        $scale = 1.0 / count($real);
        foreach ($real as $i => $value) { $real[$i] = $value * $scale; $imag[$i] *= -$scale; }
    }

    private function longWindow(int $n, int $shape, int $sequence): float
    {
        if ($sequence === 1) {
            if ($n < 1024) return $this->windowCoefficient($n, 2048, $shape);
            if ($n < 1472) return 1.0;
            if ($n < 1600) return $this->shortWindow(127 - ($n - 1472), $shape);
            return 0.0;
        }
        if ($sequence === 3) {
            if ($n < 448) return 0.0;
            if ($n < 576) return $this->shortWindow($n - 448, $shape);
            if ($n < 1024) return 1.0;
            return $this->windowCoefficient($n, 2048, $shape);
        }
        return $this->windowCoefficient($n, 2048, $shape);
    }

    private function shortWindow(int $n, int $shape): float
    {
        return $this->windowCoefficient($n, 256, $shape);
    }

    private function windowCoefficient(int $n, int $length, int $shape): float
    {
        if ($shape === 0) {
            $key = $length . ':0';
            if (!isset(self::$windowCache[$key])) {
                self::$windowCache[$key] = [];
                for ($i = 0; $i < $length; ++$i) {
                    self::$windowCache[$key][$i] = sin(M_PI / $length * ($i + 0.5));
                }
            }
            return self::$windowCache[$key][$n];
        }
        static $cache = [];
        $key = $length . ':' . $shape;
        if (!isset($cache[$key])) {
            $alpha = $length === 256 ? 6.0 : 4.0;
            $values = [];
            $sum = 0.0;
            for ($i = 0; $i <= intdiv($length, 2); ++$i) {
                $x = 2.0 * $i / $length - 1.0;
                $sum += $this->besselI0(M_PI * $alpha * sqrt(max(0.0, 1.0 - $x * $x)));
                $values[$i] = $sum;
            }
            $total = $values[intdiv($length, 2)];
            $cache[$key] = $values;
            foreach ($values as $i => $value) $cache[$key][$i] = sqrt($value / $total);
        }
        $i = $n <= intdiv($length, 2) ? $n : $length - 1 - $n;
        return $cache[$key][$i];
    }

    private function besselI0(float $x): float
    {
        $sum = 1.0;
        $term = 1.0;
        for ($k = 1; $k < 20; ++$k) {
            $term *= ($x * $x) / (4.0 * $k * $k);
            $sum += $term;
            if ($term < 1.0e-15 * $sum) break;
        }
        return $sum;
    }

    private function fft(array &$real, array &$imag): void
    {
        $n = count($real);
        for ($i = 1, $j = 0; $i < $n; ++$i) {
            $bit = $n >> 1;
            for (; $j & $bit; $bit >>= 1) $j ^= $bit;
            $j ^= $bit;
            if ($i < $j) { [$real[$i], $real[$j]] = [$real[$j], $real[$i]]; [$imag[$i], $imag[$j]] = [$imag[$j], $imag[$i]]; }
        }
        for ($length = 2; $length <= $n; $length <<= 1) {
            $half = $length >> 1;
            $step = -2.0 * M_PI / $length;
            for ($base = 0; $base < $n; $base += $length) {
                for ($j = 0; $j < $half; ++$j) {
                    $angle = $step * $j; $c = cos($angle); $s = sin($angle);
                    $p = $base + $j; $q = $p + $half;
                    $tr = $real[$q] * $c - $imag[$q] * $s; $ti = $real[$q] * $s + $imag[$q] * $c;
                    $real[$q] = $real[$p] - $tr; $imag[$q] = $imag[$p] - $ti;
                    $real[$p] += $tr; $imag[$p] += $ti;
                }
            }
        }
    }
}


