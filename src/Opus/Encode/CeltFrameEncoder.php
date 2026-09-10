<?php

namespace Xiaosongshu\Flv2mp4\Opus\Encode;

use InvalidArgumentException;
use Xiaosongshu\Flv2mp4\Opus\Celt\CeltBitAllocation;
use Xiaosongshu\Flv2mp4\Opus\Encode\CeltPvqEncoder;

/**
 * CELT 帧编码器入口（当前仅建立受限格式的安全边界）。
 */
final class CeltFrameEncoder
{
    public const SAMPLE_RATE = 48000;
    public const FRAME_SAMPLES = 960;
    public const MAX_FRAME_BYTES = 1275;

    private const MEAN_ENERGY = [
        6.4375, 6.25, 5.75, 5.3125, 5.0625, 4.8125, 4.5, 4.375, 4.875, 4.6875, 4.5625,
        4.4375, 4.875, 4.625, 4.3125, 4.5, 4.375, 4.625, 4.75, 4.4375, 3.75,
    ];

    private int $channels;
    private CeltAnalysisWindow $analysisWindow;
    private ?array $debugEnergies = null;
    private int $lastCodedBands = 0;

    private const ENERGY_MODEL_LM3_INTRA = [
        [22,178],[63,114],[74,82],[84,83],[92,82],[103,62],[96,72],[96,67],[101,73],[107,72],[113,55],
        [118,52],[125,52],[118,52],[117,55],[135,49],[137,39],[157,32],[145,29],[97,33],[77,40],
    ];

    private const INTRA_BETA = 1 - 4915 / 32768;

    public function __construct(int $channels = 2)
    {
        if ($channels !== 1 && $channels !== 2) {
            throw new InvalidArgumentException('CELT encoder supports only mono or stereo');
        }
        $this->channels = $channels;
        $this->analysisWindow = new CeltAnalysisWindow();
    }

    public function channels(): int
    {
        return $this->channels;
    }

    public function sampleRate(): int
    {
        return self::SAMPLE_RATE;
    }

    /**
     * Encode one interleaved 20 ms PCM float frame into the CELT elementary payload.
     *
     * @param float[] $pcm Samples in [-1.0, 1.0], interleaved when stereo.
     */
    public function encodeFrame(array $pcm): string
    {
        $expected = self::FRAME_SAMPLES * $this->channels;
        if (count($pcm) !== $expected) {
            throw new InvalidArgumentException(sprintf(
                'CELT frame must contain %d interleaved float samples, got %d',
                $expected,
                count($pcm)
            ));
        }
        foreach ($pcm as $sample) {
            if (!is_float($sample) && !is_int($sample)) {
                throw new InvalidArgumentException('CELT PCM samples must be finite numbers');
            }
            if (!is_finite((float) $sample) || $sample < -1.0 || $sample > 1.0) {
                throw new InvalidArgumentException('CELT PCM samples must be finite and within [-1, 1]');
            }
        }

        if ($this->isSilent($pcm)) {
            $silent = new RangeEncoder();
            $silent->encodeBitLogp(1, 15);
            return $silent->finish();
        }

        $analysis = $this->analysisWindow->frame($pcm);
        $spectrum = CeltMdctEncoder::forward($analysis);
        // Band energies in log2(amplitude) units relative to MEAN_ENERGY,
        // matching amp2Log2() + eMeans subtraction in opus-main/celt/quant_bands.c.
        $floatEnergy = [];
        $rawBandE = [];  // Linear energy for normalisation (compute_band_energies)
        for ($band = 0; $band < 21; $band++) {
            $start = CeltBitAllocation::BAND_EDGES[$band] << 3;
            $length = CeltBitAllocation::BAND_WIDTHS[$band] << 3;
            $sum = 1.0e-27;
            for ($i = 0; $i < $length; $i++) $sum += $spectrum[$start + $i] ** 2;
            $rawBandE[$band] = sqrt($sum);
            $floatEnergy[$band] = log($rawBandE[$band], 2) - self::MEAN_ENERGY[$band];
        }
        // 128 kb/s at 48 kHz with a 20 ms frame is 320 bytes per CELT frame.
        $targetBytes = 320;
        $budget = $targetBytes * 8;
        $encoder = new RangeEncoder();
        // Encode silence/postfilter/transient/intra flags, then coarse energy
        // with budget guards (quant_coarse_energy_impl), then TF/spread/dynalloc/trim.
        $coarseError = $this->encodeHeader($encoder, $floatEnergy, $budget);
        // The allocation runs on the real entropy coder: it performs the
        // normative bisections and emits the coded-bands stop/skip bit(s).
        $allocation = CeltBitAllocation::encode($encoder, 3, 1, $budget, $this->lastCodedBands);
        $this->lastCodedBands = min($this->lastCodedBands + 1, max($this->lastCodedBands - 1, $allocation['coded']));
        // Fine energy raw bits are written BEFORE the PVQ band stream
        // (quant_fine_energy, quant_bands.c). Encode the real residual q2.
        for ($band = 0; $band < 21; $band++) {
            $bits = $allocation['fine'][$band];
            if ($bits <= 0) continue;
            if ($encoder->tell() + $bits > $budget) continue;
            $levels = 1 << $bits;
            $q2 = (int) floor(($coarseError[$band] + 0.5) * $levels);
            $q2 = max(0, min($levels - 1, $q2));
            $encoder->encodeBits($q2, $bits);
            $offset = ($q2 + 0.5) * (1.0 / (1 << ($bits + 1))) - 0.5;
            $coarseError[$band] -= $offset;
        }
        $allocation['_totalBits'] = $budget;
        
        // Normalise bands: divide spectrum by raw (unquantized) energy (bands.c:178-180).
        // C reference uses bandE from compute_band_energies(), NOT quantized energy.
        // Decoder will denormalise using quantized energy to restore amplitude.
        $normalizedSpectrum = [];
        for ($band = 0; $band < 21; $band++) {
            $start = CeltBitAllocation::BAND_EDGES[$band] << 3;
            $length = CeltBitAllocation::BAND_WIDTHS[$band] << 3;
            $g = 1.0 / (1e-27 + $rawBandE[$band]);
            for ($i = 0; $i < $length; $i++) {
                $normalizedSpectrum[] = $spectrum[$start + $i] * $g;
            }
        }
        
        $bandResult = CeltBandsEncoder::encode($encoder, $normalizedSpectrum, $allocation, 3, false);
        // LM=3 never has N=1 bands, so the n=1 sign queue is always empty;
        // every band receives pulses, hence no anti-collapse bits either.
        foreach ($bandResult['n1Signs'] as $sign) $encoder->encodeBits($sign, 1);
        // Spend the remaining raw bits on one-bit energy refinements, gated by
        // the same bits_left budget as quant_energy_finalise() in quant_bands.c.
        // Use tellFrac() (1/8-bit precision) and reserve 1 byte of headroom for
        // the range coder's final carry/remainder propagation (ec_enc_done).
        $bitsLeftFrac = ($budget << 3) - $encoder->tellFrac() - 8;
        for ($pass = 0; $pass < 2; $pass++) {
            for ($band = 0; $band < 21 && $bitsLeftFrac >= 8; $band++) {
                if ($allocation['fine'][$band] >= 8 || $allocation['priority'][$band] !== $pass) continue;
                $q2 = $coarseError[$band] < 0.0 ? 0 : 1;
                $encoder->encodeBits($q2, 1);
                $coarseError[$band] -= ($q2 - 0.5) * (1.0 / (1 << ($allocation['fine'][$band] + 1)));
                $bitsLeftFrac -= 8;
            }
        }
        return $encoder->finish($targetBytes);
    }

    public function debugEnergies(): ?array
    {
        return $this->debugEnergies;
    }

    /**
     * Encode silence/postfilter/transient/intra, coarse energy (with budget
     * guards from quant_coarse_energy_impl), TF/spread/dynalloc/trim.
     *
     * @return float[] Coarse energy quantization errors per band.
     */
    private function encodeHeader(RangeEncoder $encoder, array $floatEnergy, int $budget): array
    {
        // The first bit distinguishes the CELT silence packet.
        $encoder->encodeBitLogp(0, 15);
        // Then mirror CeltFrameDecoder::decodePostfilter(), transient, intra.
        $encoder->encodeBitLogp(0, 1);
        $encoder->encodeBitLogp(0, 3);
        $encoder->encodeBitLogp(1, 3);

        // Coarse energy encoding with budget guards, faithful port of
        // quant_coarse_energy_impl() in opus-main/celt/quant_bands.c.
        // For intra mode: coef=0, beta=beta_intra(~0.85).
        // Prediction update: prev += q - beta*q = q*(1-beta).
        $coarseError = [];
        $deltas = [];
        $prediction = 0.0;
        $end = 21;
        for ($band = 0; $band < $end; $band++) {
            $f = $floatEnergy[$band] - $prediction;
            $delta = (int) floor(0.5 + $f);
            // Budget guards: clamp qi when running low on bits.
            $tell = $encoder->tell();
            $bitsLeft = $budget - $tell - 3 * ($end - $band);
            if ($band !== 0 && $bitsLeft < 30) {
                if ($bitsLeft < 24) $delta = min(1, $delta);
                if ($bitsLeft < 16) $delta = max(-1, $delta);
            }
            // Fallback encoding chain based on remaining budget.
            if ($budget - $tell >= 15) {
                [$probability, $decay] = self::ENERGY_MODEL_LM3_INTRA[$band];
                $encoder->encodeLaplace($delta, $probability << 7, $decay << 6);
            } elseif ($budget - $tell >= 2) {
                $delta = max(-1, min(1, $delta));
                $symbol = (2 * $delta) ^ -((int) ($delta < 0));
                $encoder->encodeCdf([2, 1, 0], $symbol, 2);
            } elseif ($budget - $tell >= 1) {
                $delta = min(0, $delta);
                $encoder->encodeBitLogp(-$delta, 1);
            } else {
                $delta = -1;
            }
            $coarseError[$band] = $f - $delta;
            $deltas[$band] = $delta;
            $prediction += self::INTRA_BETA * $delta;
        }
        $this->debugEnergies = $deltas;

        // TF flags (all zero for non-transient LM=3).
        for ($band = 0; $band < 21; $band++) {
            $encoder->encodeBitLogp(0, $band === 0 ? 4 : 5);
        }
        // Spread (value 2 = normal).
        $encoder->encodeCdf([25, 23, 2, 0], 2, 5);
        // Dynalloc (no boosts: one 0-bit per band).
        for ($band = 0; $band < 21; $band++) $encoder->encodeBitLogp(0, 6);
        // Trim (value 5 = center).
        $encoder->encodeCdf([126, 124, 119, 109, 87, 41, 19, 9, 4, 2, 0], 5, 7);
        // The coded-bands skip/stop bit(s) are emitted normatively by
        // CeltBitAllocation::encode() right after this header (see rate.c).
        return $coarseError;
    }

    private function allocationForBudget(array $energies, int $budget): array
    {
        $probe = new RangeEncoder();
        $this->encodeHeader($probe, $energies);
        $data = $probe->finish();
        $probeBytes = intdiv($budget + 7, 8);
        // Keep the complete range stream.  Truncating it changes the probe
        // state, and "\\0" would add two literal bytes instead of NULs.
        if (strlen($data) < $probeBytes) $data = str_pad($data, $probeBytes, "\0");
        $decoder = new RangeDecoder($data);
        $decoder->decodeBitLogp(15);
        $decoder->decodeBitLogp(1);
        $decoder->decodeBitLogp(3);
        $decoder->decodeBitLogp(3);
        $energy = new CeltEnergy();
        $energy->decodeCoarse($decoder, 3, 1, true, strlen($data) * 8);
        return CeltBitAllocation::decode($decoder, 3, false, 1, strlen($data) * 8);
    }

    private function quantizeValues(array $values, int $pulses): array
    {
        $vector = array_fill(0, count($values), 0);
        if ($pulses <= 0) return $vector;
        $norm = sqrt(max(1.0e-20, array_sum(array_map(static fn(float $v): float => $v * $v, $values))));
        $target = array_map(static fn(float $v): float => $v / $norm, $values);
        for ($pulse = 0; $pulse < $pulses; $pulse++) {
            $best = 0;
            $bestScore = -INF;
            foreach ($target as $i => $value) {
                $score = abs($value) - abs($vector[$i]) / max(1, $pulses);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = $i;
                }
            }
            $vector[$best] += $target[$best] < 0.0 ? -1 : 1;
        }
        return $vector;
    }

    private function quantizeBand(array $spectrum, int $band, int $dimensions, int $pulses): array
    {
        $offset = CeltBitAllocation::BAND_EDGES[$band] << 3;
        $values = array_slice($spectrum, $offset, $dimensions);
        $norm = sqrt(max(1.0e-20, array_sum(array_map(static fn(float $v): float => $v * $v, $values))));
        $vector = array_fill(0, $dimensions, 0);
        for ($pulse = 0; $pulse < $pulses; $pulse++) {
            $best = 0; $score = -1.0;
            foreach ($values as $i => $value) {
                $candidate = abs($value) / $norm - abs($vector[$i]) / max(1, $pulses);
                if ($candidate > $score) { $score = $candidate; $best = $i; }
            }
            $vector[$best] += ($values[$best] < 0.0 ? -1 : 1);
        }
        return $vector;
    }

    private function isSilent(array $pcm): bool
    {
        foreach ($pcm as $sample) {
            if ((float) $sample !== 0.0) {
                return false;
            }
        }
        return true;
    }

    /**
     * Wrap an elementary CELT payload in a single-frame Opus packet header.
     */
    public function makeOpusPacket(string $celtFrame): string
    {
        $length = strlen($celtFrame);
        if ($length < 1 || $length > self::MAX_FRAME_BYTES) {
            throw new InvalidArgumentException('CELT payload must contain 1..1275 bytes');
        }

        $config = 31;
        $toc = ($config << 3) | ($this->channels === 2 ? 4 : 0);
        return chr($toc) . $celtFrame;
    }

    /**
     * Convenience method for callers that need a complete one-frame Opus packet.
     *
     * @param float[] $pcm
     */
    public function encodePacket(array $pcm): string
    {
        return $this->makeOpusPacket($this->encodeFrame($pcm));
    }
}
