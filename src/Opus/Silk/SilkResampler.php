<?php

namespace Xiaosongshu\Flv2mp4\Opus\Silk;

/**
 * @purpose SILK 内部采样率（8/12/16 kHz）→ 48 kHz 的零相位 sinc 重采样器
 * @author yanglong
 *
 * 采用固定群延迟的对称加窗 sinc 插值：输出第 n 个样本对应输入时间 n/ratio - delay。
 * delay 与 FFmpeg libavcodec/opus/silk.h 的 silk_resample_delay[3] = {4, 8, 11} 一致，
 * 从而与 FFmpeg（swresample）解码结果逐样本对齐。
 * 由于线性相位滤波器需要前瞻 half-delay 个输入样本，最后一次调用需 flush() 补零收尾。
 */
final class SilkResampler
{
    private const HALF_TAPS = 16;

    private int $ratio;
    private int $delay;
    private int $inputCount = 0;
    private array $kernel = [];
    private array $buffer = [];
    private int $base = 0;
    private int $nextOutput = 0;

    public function __construct(int $inputRate)
    {
        $ratio = 48000 / $inputRate;
        if ($ratio !== 6 && $ratio !== 4 && $ratio !== 3) {
            throw new \InvalidArgumentException('Unsupported SILK internal rate: ' . $inputRate);
        }
        $this->ratio = (int)$ratio;
        $this->delay = [8000 => 4, 12000 => 8, 16000 => 11][$inputRate] ?? 4;
        $this->buildKernel();
    }

    public function reset(): void
    {
        $this->buffer = [];
        $this->base = 0;
        $this->nextOutput = 0;
        $this->inputCount = 0;
    }

    /** 输出全部尚未产出的样本（补零前瞻），用于流结束时补齐数量 */
    public function flush(): array
    {
        $target = $this->ratio * $this->inputCount;
        if ($this->nextOutput >= $target) {
            return [];
        }
        for ($i = 0; $i <= self::HALF_TAPS; $i++) {
            $this->buffer[] = 0.0;
        }
        $out = [];
        $this->drain($out);
        return $out;
    }

    public function process(array $input): array
    {
        foreach ($input as $value) {
            $this->buffer[] = $value;
        }
        $this->inputCount += count($input);

        $out = [];
        $this->drain($out);
        return $out;
    }

    private function drain(array &$out): void
    {
        $half = self::HALF_TAPS;
        $end = $this->base + count($this->buffer) - 1;
        while (true) {
            $n = $this->nextOutput;
            if ($n >= $this->ratio * $this->inputCount) {
                break;
            }
            $q = intdiv($n, $this->ratio);
            $c = $q - $this->delay;
            if ($c + $half > $end) {
                break;
            }

            $phase = $n % $this->ratio;
            $kernel = $this->kernel[$phase];
            $start = $c - $half - $this->base;
            $acc = 0.0;
            $limit = $half * 2 + 1;
            for ($t = 0; $t < $limit; $t++) {
                $idx = $start + $t;
                if ($idx >= 0 && $idx < count($this->buffer)) {
                    $acc += $this->buffer[$idx] * $kernel[$t];
                }
            }
            $out[] = $acc;
            $this->nextOutput++;
        }

        // 丢弃不再需要的输入历史
        $needed = intdiv($this->nextOutput, $this->ratio) - $this->delay - $half - 2;
        if ($needed > $this->base) {
            $drop = $needed - $this->base;
            if ($drop >= count($this->buffer)) {
                $this->buffer = [];
                $this->base = $needed;
            } else {
                array_splice($this->buffer, 0, $drop);
                $this->base = $needed;
            }
        }
    }

    private function buildKernel(): void
    {
        $half = self::HALF_TAPS;
        for ($phase = 0; $phase < $this->ratio; $phase++) {
            $f = (float)$phase / $this->ratio;
            $taps = [];
            $sum = 0.0;
            for ($j = -$half; $j <= $half; $j++) {
                $z = $f - $j;
                $window = abs($z) <= $half
                    ? 0.42 + 0.5 * cos(M_PI * $z / $half) + 0.08 * cos(2 * M_PI * $z / $half)
                    : 0.0;
                $sinc = $z == 0.0 ? 1.0 : sin(M_PI * $z) / (M_PI * $z);
                $value = $sinc * $window;
                $taps[] = $value;
                $sum += $value;
            }
            if ($sum != 0.0) {
                foreach ($taps as $i => $value) {
                    $taps[$i] = $value / $sum;
                }
            }
            $this->kernel[$phase] = $taps;
        }
    }
}
