<?php

namespace Xiaosongshu\Flv2mp4\Opus\Encode;

/** 编码端 CELT 120 点分析窗和跨帧输入状态。 */
final class CeltAnalysisWindow
{
    private const OVERLAP = 120;
    private array $previous = [];

    /** @param float[] $current @return float[] */
    public function frame(array $current): array
    {
        $previous = $this->previous;
        if ($previous === []) $previous = array_fill(0, count($current), 0.0);
        $this->previous = array_values($current);
        $input = array_merge($previous, $current);
        $window = $this->coefficients();
        $length = count($input);
        for ($i = 0; $i < self::OVERLAP; $i++) {
            $input[$i] *= $window[self::OVERLAP - 1 - $i];
            $input[$length - self::OVERLAP + $i] *= $window[$i];
        }
        return $input;
    }

    /** @return float[] */
    private function coefficients(): array
    {
        static $window;
        return $window ??= array_map(
            static fn(int $i): float => sin(0.5 * M_PI * sin(0.5 * M_PI * ($i + 0.5) / self::OVERLAP) ** 2),
            range(0, self::OVERLAP - 1)
        );
    }
}
