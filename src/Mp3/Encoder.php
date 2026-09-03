<?php

namespace Xiaosongshu\Flv2mp4\Mp3;

use InvalidArgumentException;
use LogicException;

/**
 * @purpose mp3编码器
 * @author yanglong
 * @time 2026年9月3日16:25:09
 * @note 当前版本编码器存在问题，将pcm编码为mp3后，播放有噪音，目前尚无法处理这个问题
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

    private function quantizeSafe(array $spectrum, int $budget, int $sampleRate): array
    {
        $energy = 0.0;
        foreach ($spectrum as $value) $energy += abs((float) $value);
        $coefficients = array_fill(0, 576, 0);
        $count1End = 0;
        if ($energy > 0.000001 && $budget >= 6) {
            $ranked = [];
            foreach ($spectrum as $index => $value) {
                $magnitude = abs((float) $value);
                if ($magnitude > 1.0e-8) $ranked[$index] = $magnitude;
            }
            arsort($ranked, SORT_NUMERIC);
            $selected = array_slice(array_keys($ranked), 0, 12);
            foreach ($selected as $index) {
                $coefficients[$index] = (float) $spectrum[$index] < 0 ? -1 : 1;
                $count1End = max($count1End, $index + 1);
            }
            $count1End = min(576, (int) (ceil($count1End / 4) * 4));
        }
        $count1 = intdiv($count1End, 4);
        $bits = $count1 > 0 ? $this->huffman->countBits($coefficients, 32, $count1End) : 0;
        return [
            'coefficients' => $coefficients,
            'scalefactors' => array_fill(0, 22, 0),
            'global_gain' => 210,
            'big_values' => 0,
            'count1' => $count1,
            'count1table_select' => 0,
            'preflag' => 0,
            'scalefac_scale' => 0,
            'scalefac_compress' => 0,
            'part2_bits' => 0,
            'huffman_bits' => $bits,
            'candidate_tables' => [0, 0, 0],
        ];
    }

    private function mainData(string $pcm, int $framePayloadBits): array
    {
        $channels = $this->config->channels;
        $samples = array_fill(0, $channels, []);
        if ($pcm === '') {
            for ($ch = 0; $ch < $channels; ++$ch) $samples[$ch] = array_fill(0, self::FRAME_SAMPLES, 0.0);
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
                    $samples[$ch][] = $value / 32768.0;
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
                $q = $this->quantizeSafe($spectra[$ch][$gr], $budget, $this->config->sampleRate);
                $bits = $q['part2_bits'] + $q['huffman_bits'];
                $table = $q['candidate_tables'][0];
                $granules[$gr][$ch] = new GranuleInfo(
                    $bits, $q['big_values'], $q['global_gain'], $q['scalefac_compress'],
                    false, [$table, $table, $table], 0, 0, $q['preflag'], $q['scalefac_scale'], $q['count1table_select']
                );
                $encoded[$gr][$ch] = $q;
            }
        }
        $sideInfo = new SideInfo(0, array_fill(0, $channels, [0, 0, 0, 0]), $granules);
        $sideBytes = FrameWriter::sideInfo($this->config, $sideInfo);
        $writer = new BitWriter();
        for ($gr = 0; $gr < 2; ++$gr) {
            for ($ch = 0; $ch < $channels; ++$ch) {
                $q = $encoded[$gr][$ch];
                $local = new BitWriter();
                $bigCount = $q['big_values'] * 2;
                $table = $granules[$gr][$ch]->table_select[0];
                if ($bigCount > 0) $this->huffman->writePairs($local, $q['coefficients'], $bigCount, $table);
                $count1Count = $q['count1'] * 4;
                if ($count1Count > 0) {
                    $this->huffman->writePairs(
                        $local,
                        $q['coefficients'],
                        $count1Count,
                        32 + $q['count1table_select'],
                        $q['big_values'] * 2
                    );
                }
                if ($local->bitCount() !== $granules[$gr][$ch]->part2_3_length) {
                    throw new LogicException(sprintf('Layer III granule bit count mismatch: actual=%d expected=%d big=%d count1=%d', $local->bitCount(), $granules[$gr][$ch]->part2_3_length, $bigCount, $count1Count));
                }
                $writer->writePacked($local->finish(), $granules[$gr][$ch]->part2_3_length);
            }
        }
        if ($writer->bitCount() > $mainCapacity) throw new LogicException('Layer III main data exceeds the CBR frame capacity');
        for ($i = $writer->bitCount(); $i < $mainCapacity; ++$i) $writer->write(0, 1);
        return [$writer->finish(), $sideInfo];
    }
}
