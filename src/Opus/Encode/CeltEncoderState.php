<?php

namespace Xiaosongshu\Flv2mp4\Opus\Encode;

use InvalidArgumentException;

final class CeltEncoderState
{
    public int $channels;
    public int $lm = 3;
    public int $overlap = 120;
    public int $lastCodedBands = 0;
    public int $rng = 0;
    public float $delayedIntra = 0.0;
    public int $consecTransient = 0;
    public int $oldLogEBands = 21;
    public array $preemph_memE;
    public array $inMem = [];
    public array $oldEBands;
    public array $oldLogE;
    public array $oldLogE2;
    public array $energyError;
    public array $analysisHistory = [];
    /** @var array<string,mixed> */
    public array $stages = [];
    /** @var array<string,array{maxAbs:float,meanAbs:float,mismatches:int}> */
    public array $stageDiagnostics = [];

    public function __construct(int $channels)
    {
        if ($channels !== 1 && $channels !== 2) {
            throw new InvalidArgumentException('CELT encoder supports only mono or stereo');
        }
        $this->channels = $channels;
        $this->preemph_memE = array_fill(0, $channels, 0.0);
        $this->oldEBands = array_fill(0, $channels * 21, -28.0);
        $this->oldLogE = array_fill(0, $channels * 21, -28.0);
        $this->oldLogE2 = array_fill(0, $channels * 21, -28.0);
        $this->energyError = array_fill(0, $channels * 21, 0.0);
    }

    public function reset(): void
    {
        $this->preemph_memE = array_fill(0, $this->channels, 0.0);
        $this->oldEBands = array_fill(0, $this->channels * 21, -28.0);
        $this->oldLogE = array_fill(0, $this->channels * 21, -28.0);
        $this->oldLogE2 = array_fill(0, $this->channels * 21, -28.0);
        $this->energyError = array_fill(0, $this->channels * 21, 0.0);
        $this->lastCodedBands = 0;
        $this->rng = 0;
        $this->delayedIntra = 0.0;
        $this->consecTransient = 0;
        $this->oldLogEBands = 21;
        $this->inMem = [];
        $this->analysisHistory = [];
        $this->stages = [];
        $this->stageDiagnostics = [];
    }
}
