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
    private array $previousWindowShape = [0, 0];
    private array $previousSequence = [0, 0];
    private int $frameIndex = 0;
    private int $randomState = 0x12345678;
    private array $bandTypes = [[], []];
    private array $bandScales = [[], []];
    private array $channelTns = [[], []];
    private int $readingChannel = 0;
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
            $this->previousWindowShape = array_fill(0, $channels, 0);
            $this->previousSequence = array_fill(0, $channels, 0);
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
                $this->readingChannel = 0;
                $a = $this->readChannel($r, $gain, $ics, $rateIndex);
                $gain = $r->read(8);
                $this->readingChannel = 1;
                $b = $this->readChannel($r, $gain, $ics, $rateIndex);
                if ($msMask !== 0) {
                    $this->applyMsStereo($a, $b, $ics, $msMask, $msFlags);
                }
                $this->applyIntensityStereo($a, $b, $ics, $msMask);
                $this->applyTns($a, $this->channelTns[0], $ics[2], $ics[3], $ics[2] === 2 ? [0, 4, 8, 12, 16, 20, 28, 36, 44, 56, 68, 80, 96, 112, 128] : AacTables::SWB_48K);
                $this->applyTns($b, $this->channelTns[1], $ics[2], $ics[3], $ics[2] === 2 ? [0, 4, 8, 12, 16, 20, 28, 36, 44, 56, 68, 80, 96, 112, 128] : AacTables::SWB_48K);
            } else {
                $gain = $r->read(8); $ics = $this->readIcsInfo($r);
                $this->readingChannel = 0;
                $a = $this->readChannel($r, $gain, $ics, $rateIndex); $b = null;
                $offsets = $ics[2] === 2 ? [0, 4, 8, 12, 16, 20, 28, 36, 44, 56, 68, 80, 96, 112, 128] : AacTables::SWB_48K;
                $this->applyTns($a, $this->channelTns[0], $ics[2], $ics[3], $offsets);
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
            $last = $gain; $noise = $gain; $intensity = 0; $noiseSeen = false;
            for ($band = 0; $band < $max; ++$band) {
                $codebook = $codebooks[$group][$band] ?? 0;
                if ($codebook === 0) continue;
                if ($codebook === 13) {
                    $noise = $noiseSeen ? $noise + $this->readScaleFactor($r) : ($gain - 90 + $r->read(9) - 256);
                    $noiseSeen = true; $scaleFactors[$group][$band] = $noise; continue;
                }
                if ($codebook === 14 || $codebook === 15) {
                    $intensity += $this->readScaleFactor($r);
                    if ($intensity < -155 || $intensity > 100) throw new RuntimeException('Invalid AAC intensity scale factor');
                    $scaleFactors[$group][$band] = $intensity; continue;
                }
                if ($codebook > 11) throw new RuntimeException("Unsupported AAC section codebook {$codebook}");
                $last += $this->readScaleFactor($r);
                if ($last < 0 || $last > 255) throw new RuntimeException('Invalid AAC scale factor');
                $scaleFactors[$group][$band] = $last;
            }
        }
        if ($r->read(1)) throw new RuntimeException('AAC pulse tool is invalid for short windows');
        $tns = $this->readTns($r, $sequence, $groups);
        $gainControlPresent = $r->read(1);
        if ($gainControlPresent) {
            throw new RuntimeException('AAC gain control is not supported');
        }
        $spectrum = array_fill(0, 1024, 0.0); $window = 0;
        foreach ($groups as $group => $windowCount) {
            for ($band = 0; $band < $max; ++$band) {
                $codebook = $codebooks[$group][$band] ?? 0;
                if ($codebook === 0) continue;
                if ($codebook === 13) {
                    $scale = pow(2.0, ($scaleFactors[$group][$band] - 100) / 4.0) / 32768.0;
                    for ($w = 0; $w < $windowCount; ++$w) {
                        $this->fillPnsBand($spectrum, ($window + $w) * 128, $offsets[$band], $offsets[$band + 1], $scale);
                    }
                    continue;
                }
                if ($codebook >= 12) continue;
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
        $this->applyTns($spectrum, $tns, $sequence, $groups, $offsets);
        return $spectrum;
    }

    private function readTns(AacBitReader $r, int $sequence, array $groups): array
    {
        if (!$r->read(1)) return [];
        $result = [];
        $windows = $sequence === 2 ? array_sum($groups) : 1;
        $lengthBits = $sequence === 2 ? 4 : 6;
        $orderBits = $sequence === 2 ? 3 : 5;
        for ($w = 0; $w < $windows; ++$w) {
            $filters = $r->read($sequence === 2 ? 1 : 2);
            $resolution = $filters ? $r->read(1) : 0;
            for ($f = 0; $f < $filters; ++$f) {
                $length = $r->read($lengthBits); $order = $r->read($orderBits);
                $direction = 0; $compress = 0; $coefs = [];
                if ($order > 0) {
                    $direction = $r->read(1); $compress = $r->read(1);
                    $bits = 3 + $resolution - $compress;
                    $map = $resolution ? ($compress ? [0.0, -0.20791169, -0.40673664, -0.58778525, 0.67369562, 0.52643216, 0.36124167, 0.18374951] : [0.0, -0.20791169, -0.40673664, -0.58778525, -0.74314483, -0.86602540, -0.95105654, -0.99452190, 0.99573418, 0.96182564, 0.89516329, 0.79801723, 0.67369562, 0.52643216, 0.36124167, 0.18374951]) : ($compress ? [0.0, -0.43388374, 0.64278758, 0.34202015] : [0.0, -0.43388374, -0.78183150, -0.97492790, 0.98480775, 0.86602540, 0.64278758, 0.34202015]);
                    for ($i = 0; $i < $order; ++$i) $coefs[] = $map[$r->read($bits)];
                }
                $result[$w][] = [$length, $order, $direction, $coefs];
            }
        }
        return $result;
    }

    private function applyTns(array &$spectrum, array $tns, int $sequence, array $groups, array $offsets): void
    {
        foreach ($tns as $w => $filters) {
            $top = $sequence === 2 ? 14 : count($offsets) - 1;
            foreach ($filters as [$length, $order, $direction, $reflection]) {
                if ($order === 0) { $top -= $length; continue; }
                $bottom = max(0, $top - $length); $start = $offsets[$bottom] ?? 0; $end = $offsets[$top] ?? 1024;
                $lpc = [];
                for ($i = 0; $i < $order; ++$i) {
                    $value = $reflection[$i];
                    for ($j = 0; $j < $i; ++$j) $value -= $lpc[$j] * $reflection[$i - $j - 1];
                    $lpc[$i] = $value;
                }
                $step = $direction ? -1 : 1; $pos = $direction ? $end - 1 : $start;
                for ($n = 0; $n < $end - $start; ++$n, $pos += $step) {
                    $value = $spectrum[($w * 128) + $pos] ?? 0.0;
                    for ($i = 1; $i <= min($n, $order); ++$i) $value -= $lpc[$i - 1] * ($spectrum[($w * 128) + $pos - $i * $step] ?? 0.0);
                    $spectrum[($w * 128) + $pos] = $value;
                }
                $top = $bottom;
            }
        }
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
                            $mid[$index] = $m + $s;
                            $side[$index] = $m - $s;
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
        $sf = array_fill(0, $max, $gain); $last = $gain; $noise = $gain; $intensity = 0; $noiseSeen = false;
        for ($i = 0; $i < $max; ++$i) {
            $codebook = $cb[$i] ?? 0;
            if ($codebook === 0) continue;
            if ($codebook === 13) {
                if (!$noiseSeen) {
                    $noise = $gain - 90 + $r->read(9) - 256;
                    $noiseSeen = true;
                } else {
                    $noise += $this->readScaleFactor($r);
                }
                $sf[$i] = $noise;
                continue;
            }
            if ($codebook === 14 || $codebook === 15) {
                $intensity += $this->readScaleFactor($r);
                if ($intensity < -155 || $intensity > 100) throw new RuntimeException('Invalid AAC intensity scale factor');
                $sf[$i] = $intensity;
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
        $tns = $this->readTns($r, $sequence, [1]);
        $gainControlPresent = $r->read(1);
        if ($gainControlPresent) {
            throw new RuntimeException('AAC gain control is not supported');
        }
        $spectrum = array_fill(0, 1024, 0.0); $offsets = AacTables::SWB_48K;
        for ($i = 0; $i < $max; ++$i) {
            $start = $offsets[$i]; $end = $offsets[$i + 1];
            $codebook = $cb[$i] ?? 0;
            if ($codebook === 0) continue;
            if ($codebook === 13) {
                $scale = pow(2.0, ($sf[$i] - 100) / 4.0) / 32768.0;
                $this->fillPnsBand($spectrum, 0, $start, $end, $scale);
                continue;
            }
            if ($codebook >= 12) continue;
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
        $this->bandTypes[$this->readingChannel] = $cb;
        $this->bandScales[$this->readingChannel] = $sf;
        $this->channelTns[$this->readingChannel] = $tns;
        return $spectrum;
    }

    private function applyIntensityStereo(array &$left, array &$right, array $ics, int $msMask): void
    {
        [$max, , $sequence, $groups] = $ics;
        $offsets = $sequence === 2 ? [0, 4, 8, 12, 16, 20, 28, 36, 44, 56, 68, 80, 96, 112, 128] : AacTables::SWB_48K;
        $window = 0;
        foreach ($groups as $group => $count) {
            for ($band = 0; $band < $max; ++$band) {
                $type = $this->bandTypes[1][$group][$band] ?? 0;
                if ($type !== 14 && $type !== 15) continue;
                $position = $this->bandScales[1][$group][$band] ?? 0;
                $scale = pow(2.0, -$position / 4.0);
                if ($type === 14) $scale = -$scale;
                for ($w = 0; $w < $count; ++$w) {
                    $base = ($window + $w) * ($sequence === 2 ? 128 : 1024);
                    for ($i = $offsets[$band]; $i < $offsets[$band + 1]; ++$i) $right[$base + $i] = $left[$base + $i] * $scale;
                }
            }
            $window += $count;
        }
    }

    private function skipTns(AacBitReader $r, int $sequence, array $groups): void
    {
        $windows = $sequence === 2 ? array_sum($groups) : 1;
        $lengthBits = $sequence === 2 ? 4 : 6;
        $orderBits = $sequence === 2 ? 3 : 5;

        for ($window = 0; $window < $windows; ++$window) {
            $filters = $r->read($sequence === 2 ? 1 : 2);
            if ($filters === 0) {
                continue;
            }
            $coefResolution = $r->read(1);
            for ($filter = 0; $filter < $filters; ++$filter) {
                $length = $r->read($lengthBits);
                $order = $r->read($orderBits);
                if ($order === 0) {
                    continue;
                }
                $r->read(1);
                $compress = $r->read(1);
                $coefBits = 3 + $coefResolution - $compress;
                if ($coefBits < 2 || $coefBits > 4) {
                    throw new RuntimeException('Invalid AAC TNS coefficient size');
                }
                $r->skip($order * $coefBits);
            }
        }
    }

    private function fillPnsBand(array &$spectrum, int $base, int $start, int $end, float $scale): void
    {
        $energy = 0.0;
        $count = $end - $start;
        for ($i = 0; $i < $count; ++$i) {
            $this->randomState = (int) (($this->randomState * 1664525 + 1013904223) & 0x7fffffff);
            $value = ($this->randomState / 1073741824.0) - 1.0;
            $spectrum[$base + $start + $i] = $value;
            $energy += $value * $value;
        }
        $normal = $energy > 0.0 ? $scale / sqrt($energy) : 0.0;
        for ($i = 0; $i < $count; ++$i) $spectrum[$base + $start + $i] *= $normal;
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
        $previousShape = $this->previousWindowShape[$channel];
        $previousSequence = $this->previousSequence[$channel];
        $buf = array_fill(0, 1024, 0.0);

        if ($sequence === 2) {
            for ($w = 0; $w < 8; ++$w) {
                $block = $this->imdctBlock(array_slice($spectrum, $w * 128, 128), 128);
                for ($i = 0; $i < 128; ++$i) {
                    $buf[$w * 128 + $i] = $block[$i];
                }
            }
        } else {
            $block = $this->imdctBlock(array_slice($spectrum, 0, 1024), 1024);
            for ($i = 0; $i < 1024; ++$i) $buf[$i] = $block[$i];
        }

        $out = array_fill(0, 1024, 0.0);
        if (($previousSequence === 0 || $previousSequence === 3) && ($sequence === 0 || $sequence === 1)) {
            $this->overlapLong($out, $this->overlap[$channel], $buf, $previousShape, $windowShape);
        } else {
            for ($i = 0; $i < 448; ++$i) $out[$i] = $this->overlap[$channel][$i];
            if ($sequence === 2) {
                $this->overlapShort($out, 448, $this->overlap[$channel], 448, $buf, 0, $previousShape);
                for ($w = 1; $w < 4; ++$w) {
                    $this->overlapShort($out, 448 + $w * 128, $buf, ($w - 1) * 128 + 64, $buf, $w * 128, $windowShape);
                }
                $temp = array_fill(0, 128, 0.0);
                $this->overlapShort($temp, 0, $buf, 3 * 128 + 64, $buf, 4 * 128, $windowShape);
                for ($i = 0; $i < 64; ++$i) $out[960 + $i] = $temp[$i];
            } else {
                $this->overlapShort($out, 448, $this->overlap[$channel], 448, $buf, 0, $previousShape);
                for ($i = 0; $i < 448; ++$i) $out[576 + $i] = $buf[64 + $i];
            }
        }

        if ($sequence === 2) {
            for ($i = 0; $i < 64; ++$i) $this->overlap[$channel][$i] = $temp[64 + $i];
            for ($w = 0; $w < 3; ++$w) {
                $this->overlapShort($this->overlap[$channel], 64 + $w * 128, $buf, (4 + $w) * 128 + 64, $buf, (5 + $w) * 128, $windowShape);
            }
            for ($i = 0; $i < 64; ++$i) $this->overlap[$channel][448 + $i] = $buf[7 * 128 + 64 + $i];
        } elseif ($sequence === 1) {
            for ($i = 0; $i < 448; ++$i) $this->overlap[$channel][$i] = $buf[512 + $i];
            for ($i = 0; $i < 64; ++$i) $this->overlap[$channel][448 + $i] = $buf[960 + $i];
        } else {
            for ($i = 0; $i < 512; ++$i) $this->overlap[$channel][$i] = $buf[512 + $i];
            for ($i = 512; $i < 1024; ++$i) $this->overlap[$channel][$i] = 0.0;
        }

        $this->previousWindowShape[$channel] = $windowShape;
        $this->previousSequence[$channel] = $sequence;
        return $out;
    }

    private function overlapLong(array &$out, array $saved, array $buf, int $previousShape, int $shape): void
    {
        $this->vectorFmulWindow($out, 0, $saved, 0, $buf, 0, $this->windowArray(1024, $previousShape), 512);
    }

    private function overlapShort(array &$out, int $offset, array $src0, int $src0Offset, array $src1, int $src1Offset, int $shape): void
    {
        $this->vectorFmulWindow($out, $offset, $src0, $src0Offset, $src1, $src1Offset, $this->windowArray(128, $shape), 64);
    }

    private function vectorFmulWindow(array &$dst, int $dstPointer, array $src0, int $src0Pointer, array $src1, int $src1Pointer, array $window, int $length): void
    {
        $dstBase = $dstPointer + $length;
        $src0Base = $src0Pointer + $length;
        for ($i = -$length, $j = $length - 1; $i < 0; ++$i, --$j) {
            $s0 = $src0[$src0Base + $i];
            $s1 = $src1[$src1Pointer + $j];
            $wi = $window[$length + $i];
            $wj = $window[$length + $j];
            $dst[$dstBase + $i] = $s0 * $wj - $s1 * $wi;
            $dst[$dstBase + $j] = $s0 * $wi + $s1 * $wj;
        }
    }

    /* private function unusedImdctExperiment(array $spectrum, int $channel, int $windowShape, int $sequence): array
    {
        $buf = array_fill(0, 1024, 0.0);
        $short = $this->windowArray(128, $windowShape);
        if ($sequence === 2) {
            for ($w = 0; $w < 8; ++$w) {
                $block = $this->imdctBlock(array_slice($spectrum, $w * 128, 128), 128);
                for ($i = 0; $i < 128; ++$i) $buf[$w * 128 + $i] = $block[$i];
            }
        } else {
            $block = $this->imdctBlock(array_slice($spectrum, 0, 1024), 1024);
            for ($i = 0; $i < 1024; ++$i) $buf[$i] = $block[$i];
        }

        $previousShape = $this->previousWindowShape[$channel];
        $previousSequence = $this->previousSequence[$channel];
        $out = array_fill(0, 1024, 0.0);
        if (($previousSequence === 0 || $previousSequence === 3) && ($sequence === 0 || $sequence === 1)) {
            $this->vectorFmulWindow($out, 0, $this->overlap[$channel], 0, $buf, 0, $this->windowArray(1024, $previousShape), 512);
        } else {
            for ($i = 0; $i < 448; ++$i) $out[$i] = $this->overlap[$channel][$i];
            if ($sequence === 2) {
                $this->vectorFmulWindow($out, 448, $this->overlap[$channel], 448, $buf, 0, $this->windowArray(128, $previousShape), 64);
                for ($part = 1; $part < 4; ++$part) {
                    $this->vectorFmulWindow($out, 448 + $part * 128, $buf, ($part - 1) * 128 + 64, $buf, $part * 128, $short, 64);
                }
                $temp = array_fill(0, 128, 0.0);
                $this->vectorFmulWindow($temp, 0, $buf, 3 * 128 + 64, $buf, 4 * 128, $short, 64);
                for ($i = 0; $i < 64; ++$i) $out[960 + $i] = $temp[$i];
            } else {
                $this->vectorFmulWindow($out, 448, $this->overlap[$channel], 448, $buf, 0, $this->windowArray(128, $previousShape), 64);
                for ($i = 0; $i < 448; ++$i) $out[576 + $i] = $buf[64 + $i];
            }
        }
        if ($sequence === 2) {
            for ($i = 0; $i < 64; ++$i) $this->overlap[$channel][$i] = $temp[64 + $i];
            for ($part = 0; $part < 3; ++$part) $this->vectorFmulWindow($this->overlap[$channel], 64 + $part * 128, $buf, (4 + $part) * 128 + 64, $buf, (5 + $part) * 128, $short, 64);
            for ($i = 0; $i < 64; ++$i) $this->overlap[$channel][448 + $i] = $buf[7 * 128 + 64 + $i];
        } elseif ($sequence === 1) {
            for ($i = 0; $i < 448; ++$i) $this->overlap[$channel][$i] = $buf[512 + $i];
            for ($i = 0; $i < 64; ++$i) $this->overlap[$channel][448 + $i] = $buf[960 + $i];
        } else {
            for ($i = 0; $i < 512; ++$i) $this->overlap[$channel][$i] = $buf[512 + $i];
            for ($i = 512; $i < 1024; ++$i) $this->overlap[$channel][$i] = 0.0;
        }
        $this->previousSequence[$channel] = $sequence;
        $this->previousWindowShape[$channel] = $windowShape;
        return $out;
    }

    private function vectorFmulWindow(array &$dst, int $dstOffset, array $src0, int $src0Offset, array $src1, int $src1Offset, array $win, int $len): void
    {
        for ($i = 0, $j = $len - 1; $i < $len; ++$i, --$j) {
            $s0 = $src0[$src0Offset + $i]; $s1 = $src1[$src1Offset + $j];
            $dst[$dstOffset + $i] = $s0 * $win[$len + $j] - $s1 * $win[$i];
            $dst[$dstOffset + $j] = $s0 * $win[$i] + $s1 * $win[$len + $j];
        }
    }

    private function windowArray(int $length, int $shape): array
    {
        $window = [];
        for ($i = 0; $i < $length; ++$i) {
            $window[$i] = $this->windowCoefficient($i, $length, $shape);
        }
        return $window;
    }
    */

    private function windowArray(int $length, int $shape): array
    {
        $window = [];
        for ($i = 0; $i < $length; ++$i) {
            $window[$i] = $this->windowCoefficient($i, $length, $shape);
        }
        return $window;
    }

    /** Inverse MDCT output layout consumed by FFmpeg-style window overlap. */
    private function imdctBlock(array $spectrum, int $n): array
    {
        $out = array_fill(0, $n, 0.0);
        $active = [];
        for ($k = 0; $k < $n; ++$k) {
            if ($spectrum[$k] != 0.0) $active[] = $k;
        }
        if ($active === []) return $out;

        $half = intdiv($n, 2);
        $scale = 1.0 / $n;
        $phase = M_PI / (4.0 * $n);
        for ($i = 0; $i < $half; ++$i) {
            $down = 0.0;
            $up = 0.0;
            $downFactor = 2 * $n - 2 * $i - 1;
            $upFactor = 3 * $n + 2 * $i + 1;
            foreach ($active as $j) {
                $term = 2 * $j + 1;
                $down += $spectrum[$j] * cos($term * $downFactor * $phase);
                $up += $spectrum[$j] * cos($term * $upFactor * $phase);
            }
            $out[$i] = $down * $scale;
            $out[$i + $half] = -$up * $scale;
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
        return $n < 128 ? $this->windowHalf($n, 128, $shape) : $this->windowHalf(255 - $n, 128, $shape);
    }

    private function windowHalf(int $n, int $halfLength, int $shape): float
    {
        if ($shape === 0) return sin(M_PI / (2.0 * $halfLength) * ($n + 0.5));
        $key = 'kbd:' . $halfLength;
        if (!isset(self::$windowCache[$key])) {
            $alpha = $halfLength === 128 ? 6.0 : 4.0;
            $temp = [];
            $sum = 0.0;
            $scale = 0.0;
            for ($i = 0; $i <= $halfLength; ++$i) {
                $value = $this->besselI0(4.0 * ($alpha * M_PI / (2.0 * $halfLength)) ** 2 * $i * (2 * $halfLength - $i));
                $temp[$i] = $value;
                $scale += $value * (1 + ($i > 0 && $i < $halfLength ? 1 : 0));
            }
            $scale = 1.0 / ($scale + 1.0);
            for ($i = 0; $i <= $halfLength; ++$i) {
                $sum += $temp[$i];
                self::$windowCache[$key][$i] = sqrt($sum * $scale);
            }
        }
        return self::$windowCache[$key][$n];
    }

    private function windowCoefficient(int $n, int $length, int $shape): float
    {
        $key = $length . ':' . $shape;
        if (!isset(self::$windowCache[$key])) {
            if ($shape === 0) {
                self::$windowCache[$key] = [];
                for ($i = 0; $i < $length; ++$i) {
                    self::$windowCache[$key][$i] = sin(M_PI / $length * ($i + 0.5));
                }
            } else {
                $alpha = $length <= 128 ? 6.0 : 4.0;
                $half = intdiv($length, 2);
                $alpha2 = 4.0 * ($alpha * M_PI / $length) ** 2;
                $temp = []; $sum = 0.0; $scale = 0.0;
                for ($i = 0; $i <= $half; ++$i) {
                    $temp[$i] = $this->besselI0(sqrt($i * ($length - $i) * $alpha2));
                    $scale += $temp[$i] * (1 + (($i > 0 && $i < $half) ? 1 : 0));
                }
                $scale = 1.0 / ($scale + 1.0);
                for ($i = 0; $i <= $half; ++$i) {
                    $sum += $temp[$i];
                    self::$windowCache[$key][$i] = sqrt($sum * $scale);
                }
                for ($i = $half + 1; $i < $length; ++$i) {
                    $sum += $temp[$length - $i];
                    self::$windowCache[$key][$i] = sqrt($sum * $scale);
                }
            }
        }
        return self::$windowCache[$key][$n];
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


