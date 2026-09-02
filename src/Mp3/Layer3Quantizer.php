<?php

namespace Xiaosongshu\Flv2mp4\Mp3;

final class Layer3Quantizer
{
    public function __construct(private readonly HuffmanEncoder $huffman = new HuffmanEncoder())
    {
    }

    /**
     * Quantizes one MPEG-1 Layer III 44.1 kHz long-block granule.
     * The returned values are candidates only; no encoder side information is written.
     */
    public function quantize(array $spectrum, int $bitBudget = PHP_INT_MAX, int $sampleRate = 44100): array
    {
        if (count($spectrum) !== 576) {
            throw new \InvalidArgumentException('MPEG-1 Layer III long blocks require 576 spectral lines');
        }
        $bands = $this->longBands($sampleRate);
        $max = 0.0;
        foreach ($spectrum as $value) {
            if (!is_int($value) && !is_float($value)) throw new \InvalidArgumentException('Spectrum values must be numeric');
            $max = max($max, abs((float) $value));
        }
        if ($max === 0.0) {
            return $this->makeCandidate(array_fill(0, 576, 0), 210, 576, $bands);
        }
        $best = null;
        for ($gain = 0; $gain <= 255; ++$gain) {
            $step = pow(2.0, (210 - $gain) / 4.0);
            $values = [];
            foreach (array_values($spectrum) as $value) {
                $magnitude = pow(abs((float) $value) * $step, 0.75);
                $q = min(15, (int) floor($magnitude + 0.4054));
                $values[] = $value < 0 ? -$q : $q;
            }
            foreach (array_reverse($bands) as $limit) {
                $candidate = $this->makeCandidate($values, $gain, $limit, $bands);
                if ($candidate['huffman_bits'] > $bitBudget) {
                    continue;
                }
                if ($best === null || $limit > $best['scanned_limit']) {
                    $candidate['scanned_limit'] = $limit;
                    $best = $candidate;
                }
                if ($limit === 576) {
                    unset($candidate['scanned_limit']);
                    return $candidate;
                }
            }
        }
        if ($best !== null) {
            unset($best['scanned_limit']);
            return $best;
        }
        throw new \LogicException('MPEG-1 Layer III quantization cannot produce a reliable Huffman-coded granule with the available codebooks');
    }

    private function makeCandidate(array $values, int $gain, int $limit, array $bands): array
    {
        $coefficients = array_fill(0, 576, 0);
        for ($i = 0; $i < $limit; ++$i) $coefficients[$i] = $values[$i];
        $lastBig = -1;
        for ($i = 575; $i >= 0; --$i) {
            if (abs($coefficients[$i]) > 1) {
                $lastBig = $i;
                break;
            }
        }
        $bigCount = $lastBig < 0 ? 0 : min(576, $lastBig + 1 + (($lastBig + 1) % 2));
        $lastNonzero = -1;
        for ($i = $bigCount; $i < 576; ++$i) {
            if (abs($coefficients[$i]) > 1) {
                $coefficients[$i] = $coefficients[$i] < 0 ? -1 : 1;
            }
            if ($coefficients[$i] !== 0) {
                $lastNonzero = $i;
            }
        }
        $count1End = $bigCount;
        if ($lastNonzero >= $bigCount) {
            $count1End = min(576, $bigCount + (int) ceil(($lastNonzero - $bigCount + 1) / 4) * 4);
        }
        $count1BitsByTable = [32 => 0, 33 => 0];
        for ($i = $bigCount; $i + 3 < $count1End; $i += 4) {
            $quad = [
                $coefficients[$i],
                $coefficients[$i + 1],
                $coefficients[$i + 2],
                $coefficients[$i + 3],
            ];
            foreach ([32, 33] as $table) {
                $count1BitsByTable[$table] += $this->huffman->encodeQuad($quad, $table)['bits'];
            }
        }
        $count1Table = $count1BitsByTable[32] <= $count1BitsByTable[33] ? 32 : 33;
        $bigBits = [];
        foreach ($this->bigTables() as $table) {
            try {
                $bigBits[$table] = $bigCount > 0 ? $this->huffman->countBits(array_slice($coefficients, 0, $bigCount), $table, $bigCount) : 0;
            } catch (\InvalidArgumentException) {
                $bigBits[$table] = PHP_INT_MAX;
            }
        }
        $bigTable = $bigCount ? array_keys($bigBits, min($bigBits), true)[0] : 0;
        $huffmanBits = ($bigBits[$bigTable] ?? 0) + $count1BitsByTable[$count1Table];
        return ['coefficients' => $coefficients, 'scalefactors' => array_fill(0, 22, 0), 'global_gain' => $gain, 'big_values' => intdiv($bigCount, 2), 'count1' => intdiv($count1End - $bigCount, 4), 'count1table_select' => $count1Table === 33 ? 1 : 0, 'preflag' => 0, 'scalefac_scale' => 0, 'scalefac_compress' => 0, 'part2_bits' => 0, 'big_values_bits' => $bigBits[$bigTable] ?? 0, 'count1_bits' => $count1BitsByTable[$count1Table], 'huffman_bits' => $huffmanBits, 'candidate_tables' => [$bigTable, $bigTable, $bigTable], 'candidates' => [], 'scalefactor_bands' => $bands];
    }

    private function longBands(int $sampleRate): array
    {
        if (!in_array($sampleRate, [32000, 44100, 48000], true)) throw new \InvalidArgumentException('MPEG-1 Layer III sample rate must be 32000, 44100 or 48000 Hz');
        return Layer3ScalefactorBands::long($sampleRate);
    }

    private function bigTables(): array
    {
        return [1, 2, 3, 5, 6, 7, 8, 9, 10, 11, 12, 13, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 30, 31];
    }
}
