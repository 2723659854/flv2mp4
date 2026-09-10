<?php

namespace Xiaosongshu\Flv2mp4\Opus\Encode;

/**
 * 编码端 CELT 跨帧输入状态（48 kHz / 20 ms）。
 *
 * 对照 opus-main/celt/celt_encoder.c：
 *   正变换缓冲长度为 N+overlap = 960+120 = 1080，前 120 个样本是上一帧
 *   尾部保存的 in_mem，后 960 个是当前帧。窗函数不在此外部应用，而是在
 *   clt_mdct_forward 的折叠阶段使用。当前帧最后 120 个样本成为下一帧的
 *   in_mem。
 */
final class CeltAnalysisWindow
{
    private const OVERLAP = 120;
    private const FRAME = 960;

    /** @var float[] 长度 120 的历史样本 */
    private array $previous = [];

    /**
     * @param float[] $current 长度 960 的当前帧
     * @return float[] 长度 1080：120 历史 + 960 当前
     */
    public function frame(array $current): array
    {
        if (count($current) !== self::FRAME) {
            throw new \InvalidArgumentException('CELT analysis frame must contain 960 samples');
        }
        $previous = $this->previous;
        if ($previous === []) {
            $previous = array_fill(0, self::OVERLAP, 0.0);
        }
        // in_mem <- in[N .. N+overlap-1]，即当前帧最后 120 个样本。
        $this->previous = array_slice($current, self::FRAME - self::OVERLAP, self::OVERLAP);
        return array_merge($previous, array_values($current));
    }

    public function reset(): void
    {
        $this->previous = [];
    }
}
