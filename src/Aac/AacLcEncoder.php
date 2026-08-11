<?php

namespace Xiaosongshu\Flv2mp4\Aac;

use InvalidArgumentException;

/**
 * Pure PHP AAC-LC encoder for 48 kHz interleaved stereo PCM.
 * Bitstream syntax and tables follow ISO/IEC 14496-3; the transform, rate loop,
 * psychoacoustic thresholds and quantizer are an independent implementation.
 */
final class AacLcEncoder
{
    public const FRAME_SAMPLES = 1024;
    private const CHANNELS = 2;
    private const SAMPLE_RATE = 48000;
    private const MAX_SFB = 49;

    private int $bitrate;
    private array $pending = [];
    private array $overlap = [[], []];
    private int $frameCount = 0;

    public function __construct(int $bitrate = 128000)
    {
        if ($bitrate < 96000 || $bitrate > 192000) {
            throw new InvalidArgumentException('AAC bitrate must be between 96000 and 192000 bit/s');
        }
        $this->bitrate = $bitrate;
        $this->overlap = [array_fill(0, 1024, 0.0), array_fill(0, 1024, 0.0)];
    }

    public function encodeFloat(array $interleavedPcm): string
    {
        foreach ($interleavedPcm as $sample) {
            $this->pending[] = max(-1.0, min(1.0, (float) $sample));
        }
        return $this->drain(false);
    }

    public function encodeS16le(string $interleavedPcm): string
    {
        if ((strlen($interleavedPcm) & 3) !== 0) {
            throw new InvalidArgumentException('Stereo s16le PCM must contain complete sample pairs');
        }
        $samples = unpack('v*', $interleavedPcm);
        $float = [];
        foreach ($samples as $sample) {
            if ($sample >= 0x8000) {
                $sample -= 0x10000;
            }
            $float[] = $sample / 32768.0;
        }
        return $this->encodeFloat($float);
    }

    public function flush(): string
    {
        if ($this->pending === []) {
            return '';
        }
        $needed = self::FRAME_SAMPLES * self::CHANNELS - count($this->pending);
        if ($needed > 0) {
            array_push($this->pending, ...array_fill(0, $needed, 0.0));
        }
        return $this->drain(true);
    }

    public function getAudioSpecificConfig(): string
    {
        return pack('C2', 0x11, 0x90);
    }

    public function frameCount(): int
    {
        return $this->frameCount;
    }

    private function drain(bool $flush): string
    {
        $output = '';
        $frameSize = self::FRAME_SAMPLES * self::CHANNELS;
        while (count($this->pending) >= $frameSize) {
            $frame = array_splice($this->pending, 0, $frameSize);
            $channels = [[], []];
            for ($i = 0; $i < self::FRAME_SAMPLES; ++$i) {
                $channels[0][$i] = $frame[$i * 2];
                $channels[1][$i] = $frame[$i * 2 + 1];
            }
            $output .= $this->encodeFrame($channels);
        }
        if ($flush) {
            $this->pending = [];
        }
        return $output;
    }

    private function encodeFrame(array $channels): string
    {
        $spectra = [];
        foreach ([0, 1] as $ch) {
            $input = array_merge($this->overlap[$ch], $channels[$ch]);
            $this->overlap[$ch] = $channels[$ch];
            $spectra[$ch] = $this->mdct($input);
        }
        $targetBits = (int) floor($this->bitrate * 1024 / self::SAMPLE_RATE);
        $best = null;
        for ($gain = 180; $gain <= 255; ++$gain) {
            $encoded = $this->rawDataBlock($spectra, $gain);
            $bits = strlen($encoded) * 8;
            if ($bits <= $targetBits - 56 || $gain === 255) {
                $best = $encoded;
                break;
            }
        }

        ++$this->frameCount;
        return $this->adtsHeader(strlen($best) + 7) . $best;
    }

    private function rawDataBlock(array $spectra, int $globalGain): string
    {
        $writer = new BitWriter();
        $writer->write(1, 3); // channel_pair_element
        $writer->write(0, 4);
        $writer->write(1, 1); // common_window
        $this->writeIcsInfo($writer);
        $writer->write(0, 2); // ms_mask_present
        $this->writeChannel($writer, $spectra[0], $globalGain);
        $this->writeChannel($writer, $spectra[1], $globalGain);
        $writer->write(7, 3); // end
        return $writer->finish();
    }

    private function writeIcsInfo(BitWriter $writer): void
    {
        $writer->write(0, 1);
        $writer->write(0, 2); // ONLY_LONG_SEQUENCE
        $writer->write(0, 1); // sine window
        $writer->write(self::MAX_SFB, 6);
        $writer->write(0, 1); // predictor absent
    }

    private function writeChannel(BitWriter $writer, array $spectrum, int $globalGain): void
    {
        [$quantized, $active] = $this->quantize($spectrum, $globalGain);
        $writer->write($globalGain, 8);

        $band = 0;
        while ($band < self::MAX_SFB) {
            $codebook = $active[$band] ? 7 : 0;
            $run = 1;
            while ($band + $run < self::MAX_SFB && $active[$band + $run] === $active[$band] && $run < 30) {
                ++$run;
            }
            $writer->write($codebook, 4);
            $writer->write($run, 5);
            $band += $run;
        }

        foreach ($active as $enabled) {
            if ($enabled) {
                $writer->write(AacTables::SCALEFACTOR_CODES[60], AacTables::SCALEFACTOR_BITS[60]);
            }
        }
        $writer->write(0, 1); // pulse_data_present
        $writer->write(0, 1); // tns_data_present
        $writer->write(0, 1); // gain_control_data_present

        for ($band = 0; $band < self::MAX_SFB; ++$band) {
            if (!$active[$band]) {
                continue;
            }
            $start = AacTables::SWB_48K[$band];
            $end = AacTables::SWB_48K[$band + 1];
            for ($i = $start; $i < $end; $i += 2) {
                $a = $quantized[$i];
                $b = $quantized[$i + 1];
                $index = abs($a) * 8 + abs($b);
                $writer->write(AacTables::CODES7[$index], AacTables::BITS7[$index]);
                if ($a !== 0) {
                    $writer->write($a < 0 ? 1 : 0, 1);
                }
                if ($b !== 0) {
                    $writer->write($b < 0 ? 1 : 0, 1);
                }
            }
        }
    }

    private function quantize(array $spectrum, int $globalGain): array
    {
        $quantizer = pow(2.0, (104 - $globalGain) * 3.0 / 16.0);
        $quantized = array_fill(0, 1024, 0);
        $active = array_fill(0, self::MAX_SFB, false);
        for ($band = 0; $band < self::MAX_SFB; ++$band) {
            $start = AacTables::SWB_48K[$band];
            $end = AacTables::SWB_48K[$band + 1];
            for ($i = $start; $i < $end; ++$i) {
                $magnitude = abs($spectrum[$i]);
                $q = (int) floor(pow($magnitude, 0.75) * $quantizer + 0.4054);
                $q = min(7, $q);
                if ($q !== 0) {
                    $quantized[$i] = $spectrum[$i] < 0.0 ? -$q : $q;
                    $active[$band] = true;
                }
            }
        }
        return [$quantized, $active];
    }

    private function mdct(array $input): array
    {
        return FastMdct::transform($input);
    }

    private function adtsHeader(int $frameLength): string
    {
        $profile = 1;
        $frequencyIndex = 3;
        $channelConfig = 2;
        return pack('C7',
            0xff,
            0xf1,
            ($profile << 6) | ($frequencyIndex << 2) | ($channelConfig >> 2),
            (($channelConfig & 3) << 6) | (($frameLength >> 11) & 3),
            ($frameLength >> 3) & 0xff,
            (($frameLength & 7) << 5) | 0x1f,
            0xfc
        );
    }
}
