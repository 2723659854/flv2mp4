<?php

namespace Xiaosongshu\Flv2mp4\Mp3;

use InvalidArgumentException;
use LogicException;

/**
 * @purpose mp3编码器
 * @author yanglong
 * @time 2026年9月3日16:25:09
 * @note 量化器带 scalefactor 噪声整形，逐带控制量化失真
 */
final class Encoder
{
    public const FRAME_SAMPLES = 1152;
    private PcmBuffer $buffer;
    private Mp3AnalysisFilterbank $filterbank;
    private Layer3Quantizer $quantizer;
    private HuffmanEncoder $huffman;
    private int $paddingAccumulator = 0;
    private int $frameCount = 0;

    public function __construct(private readonly Config $config = new Config())
    {
        $this->buffer = new PcmBuffer($config->channels);
        $this->filterbank = new Mp3AnalysisFilterbank($config->channels);
        $this->huffman = new HuffmanEncoder();
        $this->quantizer = new Layer3Quantizer($this->huffman);
    }

    public function encodeS16le(string $pcm): string
    {
        $this->buffer->appendS16le($pcm);
        return $this->drain(false);
    }

    public function flush(): string
    {
        if ($this->buffer->frames() === 0) {
            return '';
        }
        $missing = self::FRAME_SAMPLES - $this->buffer->frames();
        $this->buffer->appendS16le(str_repeat("\0", $missing * $this->config->channels * 2));
        return $this->drain(true);
    }

    public function encodeSilence(int $frames): string
    {
        if ($frames < 0) {
            throw new InvalidArgumentException('Frame count must not be negative');
        }
        $output = '';
        for ($i = 0; $i < $frames; ++$i) {
            $output .= $this->encodeFrame();
        }
        return $output;
    }

    public function frameCount(): int { return $this->frameCount; }
    public function config(): Config { return $this->config; }

    private function drain(bool $flush): string
    {
        $output = '';
        while ($this->buffer->frames() >= self::FRAME_SAMPLES) {
            $output .= $this->encodeFrame($this->buffer->take(self::FRAME_SAMPLES));
        }
        return $output;
    }

    private function encodeFrame(string $pcm = ''): string
    {
        $slotFraction = (144 * $this->config->bitrate) % $this->config->sampleRate;
        $this->paddingAccumulator += $slotFraction;
        $padding = false;
        if ($this->paddingAccumulator >= $this->config->sampleRate) {
            $padding = true;
            $this->paddingAccumulator -= $this->config->sampleRate;
        }
        $length = FrameHeader::frameLength($this->config, $padding);
        [$mainData, $sideInfo] = $this->mainData($pcm, ($length - 4) * 8);
        $sideInfoBytes = FrameWriter::sideInfo($this->config, $sideInfo);
        ++$this->frameCount;
        $payload = $sideInfoBytes . $mainData;
        return FrameHeader::encode($this->config, $padding) . $payload
            . str_repeat("\0", max(0, $length - 4 - strlen($payload)));
    }

    private function mainData(string $pcm, int $framePayloadBits): array
    {
        $channels = $this->config->channels;
        $samples = array_fill(0, $channels, []);
        if ($pcm === '') {
            for ($ch = 0; $ch < $channels; ++$ch) $samples[$ch] = array_fill(0, self::FRAME_SAMPLES, 0);
        } else {
            $frameBytes = self::FRAME_SAMPLES * $channels * 2;
            if (strlen($pcm) !== $frameBytes) {
                throw new LogicException('One MPEG-1 Layer III frame requires exactly 1152 PCM samples');
            }
            for ($i = 0; $i < self::FRAME_SAMPLES; ++$i) {
                for ($ch = 0; $ch < $channels; ++$ch) {
                    $offset = ($i * $channels + $ch) * 2;
                    $value = unpack('v', substr($pcm, $offset, 2))[1];
                    if ($value >= 32768) $value -= 65536;
                    // 原始 int16 量级直接送入滤波器组
                    $samples[$ch][] = $value;
                }
            }
        }

        $spectra = $this->filterbank->analyze($samples);
        $emptySide = FrameWriter::sideInfo($this->config, SideInfo::silence($channels));
        $mainCapacity = $framePayloadBits - strlen($emptySide) * 8;
        if ($mainCapacity <= 0) {
            throw new LogicException('MPEG-1 Layer III frame has no main-data capacity');
        }
        $budget = max(0, intdiv($mainCapacity, 2 * $channels));
        $granules = array_fill(0, 2, array_fill(0, $channels, null));
        $encoded = array_fill(0, 2, array_fill(0, $channels, []));
        for ($gr = 0; $gr < 2; ++$gr) {
            for ($ch = 0; $ch < $channels; ++$ch) {
                $q = $this->quantizer->quantize($spectra[$ch][$gr], $budget, $this->config->sampleRate);
                $granules[$gr][$ch] = new GranuleInfo(
                    $q['part2_3_length'], $q['big_values'], $q['global_gain'], $q['scalefac_compress'],
                    false, $q['table_select'], $q['region0_count'], $q['region1_count'],
                    $q['preflag'], $q['scalefac_scale'], $q['count1table_select']
                );
                $encoded[$gr][$ch] = $q;
            }
        }
        $sideInfo = new SideInfo(0, array_fill(0, $channels, [0, 0, 0, 0]), $granules);
        $sideBytes = FrameWriter::sideInfo($this->config, $sideInfo);
        if (strlen($sideBytes) !== strlen($emptySide)) {
            throw new LogicException('Layer III side info size changed after granule assignment');
        }
        $writer = new BitWriter();
        for ($gr = 0; $gr < 2; ++$gr) {
            for ($ch = 0; $ch < $channels; ++$ch) {
                $q = $encoded[$gr][$ch];
                // 比例因子（part2）：sfb 0-10 用 slen1，sfb 11-20 用 slen2
                $slen1 = Layer3Quantizer::SLEN1_TAB[$q['scalefac_compress']];
                $slen2 = Layer3Quantizer::SLEN2_TAB[$q['scalefac_compress']];
                for ($sfb = 0; $sfb < 11; ++$sfb) {
                    $writer->write($q['scalefactors'][$sfb], $slen1);
                }
                for ($sfb = 11; $sfb < 21; ++$sfb) {
                    $writer->write($q['scalefactors'][$sfb], $slen2);
                }
                $local = new BitWriter();
                $bigEnd = $q['big_values'] * 2;
                $this->huffman->writeRegion($local, $q['coefficients'], 0, $q['region0_end'], $q['table_select'][0]);
                $this->huffman->writeRegion($local, $q['coefficients'], $q['region0_end'], $q['region1_end'], $q['table_select'][1]);
                $this->huffman->writeRegion($local, $q['coefficients'], $q['region1_end'], $bigEnd, $q['table_select'][2]);
                if ($q['count1_quads'] > 0) {
                    $this->huffman->writeCount1($local, $q['coefficients'], $bigEnd, $q['count1_quads'], 32 + $q['count1table_select']);
                }
                if ($local->bitCount() !== $granules[$gr][$ch]->part2_3_length) {
                    throw new LogicException(sprintf('Layer III granule bit count mismatch: actual=%d expected=%d', $local->bitCount(), $granules[$gr][$ch]->part2_3_length));
                }
                $writer->writePacked($local->finish(), $granules[$gr][$ch]->part2_3_length);
            }
        }
        if ($writer->bitCount() > $mainCapacity) throw new LogicException('Layer III main data exceeds the CBR frame capacity');
        for ($i = $writer->bitCount(); $i < $mainCapacity; ++$i) $writer->write(0, 1);
        return [$writer->finish(), $sideInfo];
    }
}
