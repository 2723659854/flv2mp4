<?php

namespace Xiaosongshu\Flv2mp4\Opus\Silk;

final class SilkFrame
{
    public bool $coded = false;
    public int $logGain = 0;
    public array $nlsf;
    public array $lpc;
    public array $output;
    public array $lpcHistory;
    public int $primaryLag = 0;
    public bool $prevVoiced = false;

    public function __construct()
    {
        $this->nlsf = array_fill(0, 16, 0);
        $this->lpc = array_fill(0, 16, 0.0);
        $this->output = array_fill(0, 644, 0.0);
        $this->lpcHistory = array_fill(0, 644, 0.0);
    }

    public function flush(): void
    {
        if (!$this->coded) {
            return;
        }
        $this->output = array_fill(0, 644, 0.0);
        $this->lpcHistory = array_fill(0, 644, 0.0);
        $this->lpc = array_fill(0, 16, 0.0);
        $this->nlsf = array_fill(0, 16, 0);
        $this->logGain = 0;
        $this->primaryLag = 0;
        $this->prevVoiced = false;
        $this->coded = false;
    }
}