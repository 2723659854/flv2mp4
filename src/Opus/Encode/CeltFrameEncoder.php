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

    private CeltEncoderState $state;
    private CeltAnalysisWindow $analysisWindow;
    private ?array $debugEnergies = null;

    private const ENERGY_MODEL_LM3_INTRA = [
        [22,178],[63,114],[74,82],[84,83],[92,82],[103,62],[96,72],[96,67],[101,73],[107,72],[113,55],
        [118,52],[125,52],[118,52],[117,55],[135,49],[137,39],[157,32],[145,29],[97,33],[77,40],
    ];

    private const INTRA_BETA = 4915 / 32768;
    private const INTER_ALPHA = 0.5;
    private const INTER_BETA = 1 - 6554 / 32768;
    private const ENERGY_MODEL_LM3_INTER = [
        [42,121],[96,66],[108,43],[111,40],[117,44],[123,32],[120,36],[119,33],[127,33],[134,34],[139,21],
        [147,23],[152,20],[158,25],[154,26],[166,21],[173,16],[184,13],[184,10],[150,13],[139,15],
    ];

    public function __construct(int $channels = 1)
    {
        if ($channels !== 1) {
            throw new InvalidArgumentException('CELT encoder supports only mono');
        }
        $this->state = new CeltEncoderState($channels);
        $this->analysisWindow = new CeltAnalysisWindow();
    }

    public function channels(): int
    {
        return $this->state->channels;
    }

    public function sampleRate(): int
    {
        return self::SAMPLE_RATE;
    }

    public function reset(): void
    {
        $this->state->reset();
        $this->analysisWindow->reset();
        $this->debugEnergies = null;
    }

    /**
     * Encode one interleaved 20 ms PCM float frame into the CELT elementary payload.
     *
     * @param float[] $pcm Samples in [-1.0, 1.0], interleaved when stereo.
     */
    public function encodeFrame(array $pcm): string
    {
        $expected = self::FRAME_SAMPLES * $this->state->channels;
        if (count($pcm) !== $expected) {
            throw new InvalidArgumentException(sprintf(
                'CELT frame must contain %d interleaved float samples, got %d',
                $expected,
                count($pcm)
            ));
        }
        $this->state->stages = [];
        $this->state->stageDiagnostics = [];
        foreach ($pcm as $sample) {
            if (!is_float($sample) && !is_int($sample)) {
                throw new InvalidArgumentException('CELT PCM samples must be finite numbers');
            }
            if (!is_finite((float) $sample) || $sample < -1.0 || $sample > 1.0) {
                throw new InvalidArgumentException('CELT PCM samples must be finite and within [-1, 1]');
            }
        }

        if ($this->isSilent($pcm)) {
            $this->state->oldEBands = array_fill(0, 21, -28.0);
            $this->state->oldLogE = array_fill(0, 21, -28.0);
            $this->state->oldLogE2 = array_fill(0, 21, -28.0);
            $this->state->energyError = array_fill(0, 21, 0.0);
            $silent = new RangeEncoder();
            $silent->encodeBitLogp(1, 15);
            return $silent->finish();
        }

        $analysisPcm = [];
        for ($channel = 0; $channel < $this->state->channels; $channel++) {
            $memory = $this->state->preemph_memE[$channel];
            for ($i = $channel; $i < count($pcm); $i += $this->state->channels) {
                $sample = (float) $pcm[$i];
                $analysisPcm[$i] = $sample - $memory;
                $memory = 0.8500061035 * $sample;
            }
            $this->state->preemph_memE[$channel] = $memory;
        }
        $analysis = $this->analysisWindow->frame($analysisPcm);
        $this->state->analysisHistory[] = ['preemphasized' => $analysisPcm, 'windowed' => $analysis];
        if (count($this->state->analysisHistory) > 2) array_shift($this->state->analysisHistory);
        $spectrum = CeltMdctEncoder::forward($analysis);
        $this->state->stages = [
            'preemphasis' => $analysisPcm,
            'window_mdct_input' => $analysis,
            'mdct' => $spectrum,
        ];
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
            // quant_coarse_energy_impl receives absolute log2 amplitude; eMeans
            // is applied by the model, not subtracted from the transmitted energy.
            $floatEnergy[$band] = log($rawBandE[$band], 2);
        }
        $this->state->stages['bandE'] = $rawBandE;
        $this->state->stages['bandLogE'] = $floatEnergy;
        // 128 kb/s at 48 kHz with a 20 ms frame is 320 bytes per CELT frame.
        $targetBytes = 320;
        $budget = $targetBytes * 8;
        $encoder = new RangeEncoder();
        // Encode silence/postfilter/transient/intra flags, then coarse energy
        // with budget guards (quant_coarse_energy_impl), then TF/spread/dynalloc/trim.
        // Encode the header against the complete frame bit budget.
        $coarseError = $this->encodeHeader($encoder, $floatEnergy, $budget);
        $reconstructed = $this->state->oldEBands;
        $this->state->stages['coarse_quantized_energy'] = [
            'error' => $coarseError,
            'reconstructed' => $this->state->oldEBands,
        ];
        // The allocation runs on the real entropy coder: it performs the
        // normative bisections and emits the coded-bands stop/skip bit(s).
        $allocation = CeltBitAllocation::encode($encoder, 3, 1, $budget, $this->state->lastCodedBands);
        $this->state->stages['allocation'] = $allocation;
        if ($this->state->lastCodedBands !== 0) {
            $this->state->lastCodedBands = min($this->state->lastCodedBands + 1, max($this->state->lastCodedBands - 1, $allocation['coded']));
        } else {
            $this->state->lastCodedBands = $allocation['coded'];
        }
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
            $offset = ($q2 + 0.5) * (1.0 / (1 << $bits)) - 0.5;
            $coarseError[$band] -= $offset;
            $reconstructed[$band] += $offset;
        }
        $this->state->stages['fine_energy'] = ['error' => $coarseError, 'reconstructed' => $reconstructed];
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
        
        $this->state->stages['normalized_bands'] = $normalizedSpectrum;
        $bandResult = CeltBandsEncoder::encode($encoder, $normalizedSpectrum, $allocation, 3, false);
        $this->state->stages['pvq_lowband'] = $bandResult;
        // LM=3 never has N=1 bands, so the n=1 sign queue is always empty;
        // every band receives pulses, hence no anti-collapse bits either.
        foreach ($bandResult['n1Signs'] as $sign) $encoder->encodeBits($sign, 1);
        // Spend the remaining raw bits on one-bit energy refinements, gated by
        // the same bits_left budget as quant_energy_finalise() in quant_bands.c.
        // quant_energy_finalise() uses the real integer bit budget.
        // quant_energy_finalise() receives the actual number of bits left;
        // do not reserve a fixed bit, since the range coder's tell is the
        // authoritative budget boundary.
        $bitsLeft = max(0, $budget - $encoder->tell());
        for ($pass = 0; $pass < 2; $pass++) {
            for ($band = 0; $band < 21 && $bitsLeft >= 1; $band++) {
                if ($allocation['fine'][$band] >= 8 || $allocation['priority'][$band] !== $pass) continue;
                $q2 = $coarseError[$band] < 0.0 ? 0 : 1;
                if ($encoder->tell() + 1 > $budget) break;
                $encoder->encodeBits($q2, 1);
                $fineBits = $allocation['fine'][$band];
                $offset = ($q2 - 0.5) * (1.0 / (1 << ($fineBits + 1)));
                $coarseError[$band] -= $offset;
                $reconstructed[$band] += $offset;
                $bitsLeft--;
            }
        }
        $this->state->energyError = array_map(static fn(float $error): float => max(-0.5, min(0.5, $error)), $coarseError);
        $this->state->oldEBands = $reconstructed;
        $this->state->oldLogE2 = $this->state->oldLogE;
        $this->state->oldLogE = $this->state->oldEBands;
        $this->state->stages['final_energy'] = [
            'error' => $coarseError,
            'reconstructed' => $reconstructed,
            'energy_error' => $this->state->energyError,
        ];
        $payload = $encoder->finish($targetBytes);
        $this->state->rng = $encoder->tell();
        return $payload;
    }

    /** @return array<string,mixed> */
    public function debugStages(): array
    {
        return $this->state->stages;
    }

    /**
     * Compare deterministic stage vectors with reference dumps supplied by a caller.
     * No claim is made that a reference encoder was executed.
     *
     * @param array<string,array<int,int|float>> $reference
     * @return array<string,array{maxAbs:float,meanAbs:float,mismatches:int}>
     */
    public function compareStages(array $reference, float $tolerance = 1.0e-6): array
    {
        $result = [];
        foreach ($reference as $stage => $expected) {
            $actual = $this->state->stages[$stage] ?? null;
            if (!is_array($actual)) {
                $result[$stage] = ['maxAbs' => INF, 'meanAbs' => INF, 'mismatches' => count($expected)];
                continue;
            }
            $actual = array_values($actual);
            $max = 0.0; $sum = 0.0; $mismatches = 0;
            foreach ($expected as $index => $value) {
                $delta = abs((float) ($actual[$index] ?? 0.0) - (float) $value);
                $max = max($max, $delta); $sum += $delta;
                if ($delta > $tolerance) $mismatches++;
            }
            $result[$stage] = ['maxAbs' => $max, 'meanAbs' => $expected === [] ? 0.0 : $sum / count($expected), 'mismatches' => $mismatches];
        }
        $this->state->stageDiagnostics = $result;
        return $result;
    }

    /** @return array<string,array{maxAbs:float,meanAbs:float,mismatches:int}> */
    public function stageDiagnostics(): array
    {
        return $this->state->stageDiagnostics;
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

        // Coarse energy encoding with budget guards, faithful port of
        // quant_coarse_energy_impl() in opus-main/celt/quant_bands.c.
        // For intra mode: coef=0, beta=beta_intra(~0.85).
        // Prediction update: prev += q - beta*q = q*(1-beta).
        $coarseError = [];
        $deltas = [];
        $prediction = 0.0;
        $reconstructed = [];
        $intra = true;
        $encoder->encodeBitLogp($intra ? 1 : 0, 3);
        $end = 21;
        $coef = $intra ? 0.0 : 0.5;
        $beta = $intra ? self::INTRA_BETA : self::INTER_BETA;
        for ($band = 0; $band < $end; $band++) {
            $old = max(-9.0, $this->state->oldEBands[$band]);
            $f = $floatEnergy[$band] - $coef * $old - $prediction;
            $delta = (int) floor(0.5 + $f);
            $decayBound = max(-28.0, $this->state->oldEBands[$band]) - 16.0;
            if ($delta < 0 && $floatEnergy[$band] < $decayBound) {
                $delta += (int) floor($decayBound - $floatEnergy[$band]);
                if ($delta > 0) $delta = 0;
            }
            // Budget guards: clamp qi when running low on bits.
            $tell = $encoder->tell();
            $bitsLeft = $budget - $tell - 3 * ($end - $band);
            if ($band !== 0 && $bitsLeft < 30) {
                if ($bitsLeft < 24) $delta = min(1, $delta);
                if ($bitsLeft < 16) $delta = max(-1, $delta);
            }
            // Fallback encoding chain based on remaining budget.
            if ($budget - $tell >= 15) {
                [$probability, $decay] = ($intra ? self::ENERGY_MODEL_LM3_INTRA : self::ENERGY_MODEL_LM3_INTER)[$band];
                $encoder->encodeLaplace($delta, ($probability << 7), ($decay << 6));
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
            $reconstructed[$band] = $coef * $old + $prediction + $delta;
            $prediction += $delta - $beta * $delta;
            $this->state->energyError[$band] = max(-0.5, min(0.5, $coarseError[$band]));
        }
        $this->debugEnergies = $deltas;
        $this->state->oldEBands = $reconstructed;

        // tf_encode(): reserve tf_select, encode raw decisions, then apply the LM=3 table.
        $tfChanged = 0;
        $tf = [];
        for ($band = 0; $band < 21; $band++) {
            $value = 0;
            $tf[$band] = $value;
            $encoder->encodeBitLogp($value, $band === 0 ? 4 : 5);
        }
        $tfSelect = 0;
        $this->state->stages['tf'] = ['raw' => $tf, 'select' => $tfSelect, 'changed' => $tfChanged,
            'resolved' => array_fill(0, 21, 0)];
        // spread_icdf is a 5-bit CDF in the reference (the values are inverse CDF entries).
        $encoder->encodeCdf([25, 23, 2, 0], 2, 5);
        // dynalloc_analysis yields zero offsets for the fixed mono profile; rate.c still emits one stop flag per band.
        $dynalloc = [];
        for ($band = 0; $band < 21; $band++) { $encoder->encodeBitLogp(0, 6); $dynalloc[$band] = 0; }
        // alloc_trim_analysis is centered for this fixed CBR profile.
        $encoder->encodeCdf([126, 124, 119, 109, 87, 41, 19, 9, 4, 2, 0], 5, 7);
        $this->state->stages['dynalloc'] = $dynalloc;
        $this->state->stages['trim'] = 5;
        // The coded-bands skip/stop bit(s) are emitted normatively by
        // CeltBitAllocation::encode() right after this header (see rate.c).
        return $coarseError;
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
        $toc = ($config << 3) | ($this->state->channels === 2 ? 4 : 0);
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
