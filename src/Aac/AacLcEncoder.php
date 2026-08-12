<?php

namespace Xiaosongshu\Flv2mp4\Aac;

use InvalidArgumentException;

/**
 * Pure PHP AAC-LC encoder for 48 kHz mono or interleaved stereo PCM.
 * Bitstream syntax and tables follow ISO/IEC 14496-3; the transform, rate loop,
 * psychoacoustic thresholds and quantizer are an independent implementation.
 */
final class AacLcEncoder
{
    public const FRAME_SAMPLES = 1024;
    private const SAMPLE_RATE = 48000;
    private const MAX_SFB = 49;

    private int $bitrate;
    private int $channels;
    private array $pending = [];
    private int $pendingOffset = 0;
    private array $overlap = [[], []];
    private int $frameCount = 0;

    public function __construct(int $bitrate = 128000, int $channels = 2)
    {
        if ($channels !== 1 && $channels !== 2) {
            throw new InvalidArgumentException('AAC channel count must be 1 or 2');
        }
        $minimumBitrate = $channels === 1 ? 48000 : 96000;
        if ($bitrate < $minimumBitrate || $bitrate > 192000) {
            throw new InvalidArgumentException("AAC bitrate must be between {$minimumBitrate} and 192000 bit/s");
        }
        $this->bitrate = $bitrate;
        $this->channels = $channels;
        $silence = array_fill(0, self::FRAME_SAMPLES, 0.0);
        $this->overlap = array_fill(0, $channels, $silence);
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
        $sampleFrameBytes = $this->channels * 2;
        if (strlen($interleavedPcm) % $sampleFrameBytes !== 0) {
            throw new InvalidArgumentException('s16le PCM must contain complete sample frames');
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
        if (count($this->pending) === $this->pendingOffset) {
            $this->pending = [];
            $this->pendingOffset = 0;
            return '';
        }
        $needed = self::FRAME_SAMPLES * $this->channels - (count($this->pending) - $this->pendingOffset);
        while ($needed-- > 0) {
            $this->pending[] = 0.0;
        }
        return $this->drain(true);
    }

    public function getAudioSpecificConfig(): string
    {
        $config = (2 << 11) | (3 << 7) | ($this->channels << 3);
        return pack('n', $config);
    }

    public function channels(): int
    {
        return $this->channels;
    }

    public function frameCount(): int
    {
        return $this->frameCount;
    }

    private function drain(bool $flush): string
    {
        $output = '';
        $frameSize = self::FRAME_SAMPLES * $this->channels;
        $pendingCount = count($this->pending);
        while ($pendingCount - $this->pendingOffset >= $frameSize) {
            $channels = array_fill(0, $this->channels, []);
            $offset = $this->pendingOffset;
            for ($i = 0; $i < self::FRAME_SAMPLES; ++$i) {
                for ($channel = 0; $channel < $this->channels; ++$channel) {
                    $channels[$channel][$i] = $this->pending[$offset++];
                }
            }
            $this->pendingOffset = $offset;
            $output .= $this->encodeFrame($channels);
        }
        if ($flush || $this->pendingOffset >= $frameSize * 8) {
            $this->pending = $flush
                ? []
                : array_slice($this->pending, $this->pendingOffset, $pendingCount - $this->pendingOffset);
            $this->pendingOffset = 0;
        }
        return $output;
    }

    private function encodeFrame(array $channels): string
    {
        $spectra = [];
        for ($ch = 0; $ch < $this->channels; ++$ch) {
            $input = array_merge($this->overlap[$ch], $channels[$ch]);
            $this->overlap[$ch] = $channels[$ch];
            $spectrum = $this->mdct($input);
            for ($i = 0; $i < 1024; ++$i) {
                $value = $spectrum[$i];
                $magnitude = pow(abs($value), 0.75);
                $spectrum[$i] = $value < 0.0 ? -$magnitude : $magnitude;
            }
            $spectra[$ch] = $spectrum;
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
        if ($this->channels === 1) {
            $writer->write(0, 3); // single_channel_element
            $writer->write(0, 4);
            $writer->write($globalGain, 8);
            $this->writeIcsInfo($writer);
            $this->writeChannelPayload($writer, $spectra[0], $globalGain);
        } else {
            $writer->write(1, 3); // channel_pair_element
            $writer->write(0, 4);
            $writer->write(1, 1); // common_window
            $this->writeIcsInfo($writer);
            $writer->write(0, 2); // ms_mask_present
            $this->writeChannel($writer, $spectra[0], $globalGain);
            $this->writeChannel($writer, $spectra[1], $globalGain);
        }
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
        $writer->write($globalGain, 8);
        $this->writeChannelPayload($writer, $spectrum, $globalGain);
    }

    private function writeChannelPayload(BitWriter $writer, array $spectrum, int $globalGain): void
    {
        [$quantized, $active] = $this->quantize($spectrum, $globalGain);
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
                $q = min(7, (int) floor($magnitude * $quantizer + 0.4054));
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
        $channelConfig = $this->channels;
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
