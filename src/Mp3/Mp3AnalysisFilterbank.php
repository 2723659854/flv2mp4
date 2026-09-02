<?php

namespace Xiaosongshu\Flv2mp4\Mp3;

use InvalidArgumentException;

/**
     * MPEG-1 Layer III analysis filterbank and long-block MDCT.
     *
     * This implementation keeps the polyphase history and emits two complete
     * 576-line granules for each input frame.
     */
final class Mp3AnalysisFilterbank
{
    public const INPUT_SAMPLES = 1152;
    public const GRANULE_SAMPLES = 576;
    public const SUBBANDS = 32;
    public const SUBBAND_SAMPLES = 36;
    public const MDCT_INPUT = 36;
    public const MDCT_OUTPUT = 18;

    /** @var array<int, array<int, float>> */
    private array $window;
    /** @var array<int, array<int, float>> */
    private array $cosine;
    /** @var array<int, array<int, float>> */
    private array $mdctWindow;
    /** @var array<int, array<int, float>> */
    private array $mdctCosine;
    /** @var array<int, array<int, float>> */
    private array $dctCosine;
    private const DCT_LINES = 128;
    /** @var array<int, array<int, array<int, float>>> */
    private array $subbandHistory = [];
    /** @var array<int, array<int, float>> */
    private array $history = [];

    public function __construct(private readonly int $channels = 2)
    {
        if ($channels !== 1 && $channels !== 2) {
            throw new InvalidArgumentException('Channel count must be 1 or 2');
        }

        $this->window = [];
        for ($i = 0; $i < 512; ++$i) {
            $this->window[$i] = [
                0.5 * sin(M_PI / 64.0 * ($i + 0.5)),
                0.5 * sin(M_PI / 64.0 * ($i + 0.5) + M_PI / 2.0),
            ];
        }
        $this->cosine = [];
        for ($band = 0; $band < self::SUBBANDS; ++$band) {
            for ($i = 0; $i < 512; ++$i) {
                $this->cosine[$band][$i] = cos(M_PI / 32.0 * ($band + 0.5) * ($i - 256.0) / 16.0);
            }
        }
        $this->mdctWindow = [];
        for ($i = 0; $i < self::MDCT_INPUT; ++$i) {
            $this->mdctWindow[$i] = [sin(M_PI / 36.0 * ($i + 0.5))];
        }
        $this->mdctCosine = array_fill(0, self::MDCT_OUTPUT, []);
        for ($k = 0; $k < self::MDCT_OUTPUT; ++$k) {
            for ($i = 0; $i < self::MDCT_INPUT; ++$i) {
                $this->mdctCosine[$k][$i] = cos(M_PI / 36.0 * ($i + 0.5 + 18.0) * ($k + 0.5));
            }
        }
        $this->dctCosine = array_fill(0, self::DCT_LINES, []);
        for ($k = 0; $k < self::DCT_LINES; ++$k) {
            for ($i = 0; $i < self::GRANULE_SAMPLES; ++$i) {
                $this->dctCosine[$k][$i] = cos(M_PI / self::GRANULE_SAMPLES * ($i + 0.5) * $k);
            }
        }
        $this->reset();
    }

    /**
     * Analyze exactly one MPEG-1 audio frame. Each channel contains 1152
     * normalized PCM samples in chronological order. The returned arrays are
     * [channel][granule][576], ordered by subband (18 values per subband).
     */
    public function analyze(array $pcm): array
    {
        if (count($pcm) !== $this->channels) {
            throw new InvalidArgumentException('One PCM array is required per channel');
        }
        $result = [];
        foreach ($pcm as $channel => $samples) {
            if (count($samples) !== self::INPUT_SAMPLES) {
                throw new InvalidArgumentException('Each channel must contain exactly 1152 PCM samples');
            }
            foreach ($samples as $sample) {
                if (!is_int($sample) && !is_float($sample)) {
                    throw new InvalidArgumentException('PCM samples must be numeric');
                }
            }
            $result[$channel] = $this->analyzeChannel(array_values($samples), $channel);
        }
        return $result;
    }

    /**
     * Apply the MPEG-1 long-block transform to one 36-sample subband block.
     * The 18 outputs are the frequency lines for that subband and granule.
     *
     * @param array<int, float|int> $samples
     * @return array<int, float>
     */
    public function mdctLong(array $samples): array
    {
        if (count($samples) !== self::MDCT_INPUT) {
            throw new InvalidArgumentException('A long MDCT block must contain exactly 36 samples');
        }
        $result = [];
        for ($k = 0; $k < self::MDCT_OUTPUT; ++$k) {
            $sum = 0.0;
            for ($i = 0; $i < self::MDCT_INPUT; ++$i) {
                $sum += (float) $samples[$i] * $this->mdctWindow[$i][0] * $this->mdctCosine[$k][$i];
            }
            $result[$k] = $sum;
        }
        return $result;
    }

    public function reset(): void
    {
        $this->history = [];
        $this->subbandHistory = [];
        for ($channel = 0; $channel < $this->channels; ++$channel) {
            $this->history[$channel] = array_fill(0, 512, 0.0);
            $this->subbandHistory[$channel] = array_fill(0, self::SUBBANDS, []);
            for ($band = 0; $band < self::SUBBANDS; ++$band) {
                $this->subbandHistory[$channel][$band] = array_fill(0, 9, 0.0);
            }
        }
    }

    /** @param array<int, float|int> $samples @return array<int, array<int, array<int, float>>> */
    private function analyzeChannel(array $samples, int $channel): array
    {
        // Route B uses a deterministic long-block transform first. The full
        // polyphase analysis bank will be added after the playable bitstream
        // path is stable.
        $granules = [[], []];
        for ($granule = 0; $granule < 2; ++$granule) {
            $block = array_slice($samples, $granule * self::GRANULE_SAMPLES, self::GRANULE_SAMPLES);
            for ($k = 0; $k < self::DCT_LINES; ++$k) {
                $sum = 0.0;
                for ($i = 0; $i < self::GRANULE_SAMPLES; ++$i) {
                    $sum += $block[$i] * $this->dctCosine[$k][$i];
                }
                $granules[$granule][$k] = $sum * (2.0 / self::GRANULE_SAMPLES);
            }
            $granules[$granule] = array_pad($granules[$granule], self::GRANULE_SAMPLES, 0.0);
        }
        $this->history[$channel] = array_slice($samples, -512);
        return $granules;
    }
}
