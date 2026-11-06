<?php

namespace Xiaosongshu\Flv2mp4\Opus;

use InvalidArgumentException;
use LogicException;
use Xiaosongshu\Flv2mp4\Opus\Celt\CeltFrameDecoder;
use Xiaosongshu\Flv2mp4\Opus\Celt\CeltWindow;
use Xiaosongshu\Flv2mp4\Opus\Silk\SilkDecoder;
use Xiaosongshu\Flv2mp4\Opus\Silk\SilkResampler;

/**
 * @purpose Opus 解码器
 * @author yanglong
 * @time 2026年8月12日17:22:23
 */
final class OpusDecoder
{
    private int $channels;
    private int $sampleRate;
    private ?array $lastPacket = null;
    private int $lastSampleCount = 0;
    private CeltFrameDecoder $celtDecoder;
    private int $skipRemaining = 0;
    private float $outputGain = 1.0;
    private ?SilkDecoder $silkDecoder = null;
    /** @var array<int, SilkResampler> */
    private array $silkResamplers = [];
    private int $silkRate = 0;
    /** @var float[] hybrid 下 CELT 层相对 SILK 输出的尾部延迟（输出声道交错） */
    private array $celtDelay = [];
    /** hybrid 累计的 SILK 重采样欠输出样本数（FFmpeg dec.c s->delayed_samples） */
    private int $hybridDelayedSamples = 0;

    /**
     * CELT 带宽 → 编码带上限（end band）。取值逐项对应 FFmpeg libavcodec/opus/tab.c
     * 的 ff_celt_band_end[] = {13, 17, 17, 19, 21}（NB/MB/WB/SWB/FB）。
     */
    private const BAND_END = ['NB' => 13, 'MB' => 17, 'WB' => 17, 'SWB' => 19, 'FB' => 21];

    public function __construct(int $channels = 2, int $sampleRate = 48000)
    {
        if (($channels !== 1 && $channels !== 2) || !in_array($sampleRate, [8000, 12000, 16000, 24000, 48000], true)) {
            throw new InvalidArgumentException('Unsupported Opus output format');
        }
        $this->channels = $channels;
        $this->sampleRate = $sampleRate;
        $this->celtDecoder = new CeltFrameDecoder();
    }

    public function channels(): int
    {
        return $this->channels;
    }

    public function sampleRate(): int
    {
        return $this->sampleRate;
    }

    public function lastPacket(): ?array
    {
        return $this->lastPacket;
    }

    public function lastSampleCount(): int
    {
        return $this->lastSampleCount;
    }

    public function debugCeltFrame(): ?array
    {
        return $this->celtDecoder->debugFrame();
    }

    public function reset(): void
    {
        $this->lastPacket = null;
        $this->lastSampleCount = 0;
        $this->skipRemaining = 0;
        $this->celtDecoder->reset();
        $this->silkDecoder?->reset();
        foreach ($this->silkResamplers as $resampler) {
            $resampler->reset();
        }
        $this->silkRate = 0;
        $this->celtDelay = [];
        $this->hybridDelayedSamples = 0;
    }

    /** 设置 dOps/OpusHead 的 pre-skip（48 kHz 样本数），从解码输出开头丢弃 */
    public function setPreSkip(int $samples): void
    {
        $this->skipRemaining = max(0, $samples);
    }

    /** 设置 dOps/OpusHead 的 output gain（Q7.7，1/256 dB） */
    public function setOutputGain(int $q7_7): void
    {
        $this->outputGain = (float)(10 ** ($q7_7 / (256.0 * 20.0)));
    }

    public function decodeFloat(string $packet): array
    {
        $this->lastPacket = OpusPacketParser::parse($packet);
        $this->lastSampleCount = 0;
        $mode = $this->lastPacket['mode'];
        if ($mode !== 'CELT' && $mode !== 'SILK' && $mode !== 'HYBRID') {
            throw new LogicException($mode . ' audio decoding is not implemented; refusing to fabricate PCM');
        }
        if ($this->sampleRate !== 48000) {
            throw new LogicException(sprintf('Unsupported Opus output rate: %d (only 48000)', $this->sampleRate));
        }
        $frameSamples = $this->lastPacket['frameDurationSamples'];
        $allowedSizes = match ($mode) {
            'SILK' => [480, 960, 1920, 2880],
            'HYBRID' => [480, 960],
            default => [120, 240, 480, 960],
        };
        if (!in_array($frameSamples, $allowedSizes, true)) {
            throw new LogicException('Unsupported ' . $mode . ' frame size: ' . $frameSamples);
        }
        $endBand = self::BAND_END[$this->lastPacket['bandwidth']] ?? 21;
        $codedChannels = $this->lastPacket['stereo'] ? 2 : 1;
        $frames = $this->lastPacket['frames'];
        if (count($frames) !== $this->lastPacket['frameCount']) {
            throw new LogicException('Opus packet frame count mismatch');
        }

        $pcm = [];
        foreach ($frames as $frame) {
            if ($mode === 'CELT' && $this->silkResamplers !== []) {
                // FFmpeg dec.c 408-447：packet 模式切到纯 CELT 时先 flush SILK 重采样器，
                // 把 delayed_samples 个重采样尾部与 celt_delay FIFO 剩余样本叠加，
                // 输出在首个 CELT 帧之前（即 hybrid→CELT 交界处多出 15 个样本）。
                $pcm = array_merge($pcm, $this->flushFloat());
                $this->silkResamplers = [];
                $this->silkRate = 0;
                // FFmpeg dec.c 435-443：CELT 包触发重采样器冲洗后 delayed_samples 归零
                $this->hybridDelayedSamples = 0;
            }
            if ($mode === 'SILK') {
                $pcm = array_merge($pcm, $this->decodeSilkFrame($frame, $frameSamples, $codedChannels));
                continue;
            }
            if ($mode === 'HYBRID') {
                // FFmpeg dec.c：SILK 低频层（≤WB）+ CELT 高频层（start_band=17）逐样本相加，
                // 且两层共用同一个区间解码器状态：先 SILK 读，CELT 从当前位置继续读。
                $rc = new RangeDecoder($frame);
                $silk = $this->decodeSilkFrame($frame, $frameSamples, $codedChannels, $rc);
                // FFmpeg dec.c 274：SILK 重采样输出不足 frame_duration 的部分按帧累计，
                // 冗余帧淡入位置依赖它（本流累计 15 个样本）。
                $this->hybridDelayedSamples += $frameSamples - intdiv(count($silk), $this->channels);
                // FFmpeg dec.c 278–304：hybrid 在 CELT 前还要读 1 位冗余标志（logp=12），
                // 冗余帧从帧尾剥离，随后 CELT 从剩余的字节重新初始化原始位读取器。
                $size = strlen($frame);
                $redundancy = ($rc->tell() + 37 <= $size * 8) ? $rc->decodeBitLogp(12) : 0;
                $redundancyPos = 0;
                $redundancySize = 0;
                if ($redundancy) {
                    $redundancyPos = $rc->decodeBitLogp(1);
                    $redundancySize = $rc->decodeUint(256) + 2;
                    $size -= $redundancySize;
                }
                $redundancyPcm = null;
                // FFmpeg dec.c 298-303：冗余帧在主体之前时，先解冗余再 ff_celt_flush
                if ($redundancy && $redundancyPos) {
                    $redundancyPcm = $this->decodeRedundancyFrame(
                        substr($frame, $size, $redundancySize),
                        $codedChannels,
                        $endBand
                    );
                    $this->celtDecoder->flush();
                }
                $rc->resetRaw($size);
                $celt = $this->normalizeChannels(
                    $this->celtDecoder->decode($frame, $frameSamples, $codedChannels, $endBand, 17, $rc),
                    $frameSamples,
                    $codedChannels
                );
                $hybrid = $this->combineHybrid($silk, $celt);
                // FFmpeg dec.c 368-391：冗余帧在主体之后时，先 ff_celt_flush，再解冗余帧；
                // 冗余帧（240 样本、start_band=0）会留下全带能量状态给下一帧，
                // 这正是 hybrid→纯 CELT 交界处 f17 所依赖的历史。
                if ($redundancy && $redundancyPos) {
                    $hybrid = $this->fadeInRedundancyHead($hybrid, $redundancyPcm, $frameSamples);
                } elseif ($redundancy) {
                    $this->celtDecoder->flush();
                    $redundancyPcm = $this->decodeRedundancyFrame(
                        substr($frame, $size, $redundancySize),
                        $codedChannels,
                        $endBand
                    );
                    $hybrid = $this->fadeInRedundancyTail($hybrid, $redundancyPcm, $frameSamples);
                }
                $pcm = array_merge($pcm, $hybrid);
                continue;
            }
            $pcm = array_merge($pcm, $this->normalizeChannels(
                $this->celtDecoder->decode($frame, $frameSamples, $codedChannels, $endBand),
                $frameSamples,
                $codedChannels
            ));
        }

        if ($this->outputGain !== 1.0) {
            foreach ($pcm as $i => $sample) {
                $pcm[$i] = $sample * $this->outputGain;
            }
        }
        if ($this->skipRemaining > 0) {
            $drop = min($this->skipRemaining, intdiv(count($pcm), $this->channels));
            if ($drop > 0) {
                $pcm = array_slice($pcm, $drop * $this->channels);
                $this->skipRemaining -= $drop;
            }
        }
        $this->lastSampleCount = intdiv(count($pcm), $this->channels);
        return $pcm;
    }

    private function decodeSilkFrame(
        string $frame,
        int $frameSamples,
        int $codedChannels,
        ?RangeDecoder $rc = null
    ): array {
        // hybrid（config 12–15）的 SILK 层最高为宽带，FFmpeg 用 min(bandwidth, WB)
        $bandwidth = min(2, intdiv($this->lastPacket['config'], 4));
        $rate = [8000, 12000, 16000][$bandwidth];
        $durationMs = intdiv($frameSamples, 48);
        if ($this->silkDecoder === null) {
            $this->silkDecoder = new SilkDecoder($this->channels);
        }
        if ($this->silkRate !== $rate) {
            $this->silkResamplers = [];
            $this->silkRate = $rate;
        }

        $channels = $this->silkDecoder->decodeSuperframe(
            $rc ?? new RangeDecoder($frame),
            $bandwidth,
            $codedChannels,
            $durationMs
        );

        $resampled = [];
        foreach ($channels as $index => $samples) {
            if (!isset($this->silkResamplers[$index])) {
                $this->silkResamplers[$index] = new SilkResampler($rate);
            }
            $resampled[$index] = $this->silkResamplers[$index]->process($samples);
        }

        $pcm = [];
        if ($this->channels === 1) {
            foreach ($resampled[0] as $sample) {
                $pcm[] = $sample;
            }
            return $pcm;
        }

        $right = $resampled[1] ?? $resampled[0];
        $count = min(count($resampled[0]), count($right));
        for ($i = 0; $i < $count; $i++) {
            $pcm[] = $resampled[0][$i];
            $pcm[] = $right[$i];
        }
        return $pcm;
    }

    /** 把 CELT 解码结果（按编码声道数）转成输出声道布局的交错 PCM */
    private function normalizeChannels(array $decoded, int $frameSamples, int $codedChannels): array
    {
        if ($codedChannels === 1 && $this->channels === 2) {
            $pcm = [];
            for ($i = 0; $i < $frameSamples; $i++) {
                $pcm[] = $decoded[$i];
                $pcm[] = $decoded[$i];
            }
            return $pcm;
        }
        if ($codedChannels === 2 && $this->channels === 1) {
            $pcm = [];
            for ($i = 0; $i < $frameSamples; $i++) {
                $pcm[] = ($decoded[$i * 2] + $decoded[$i * 2 + 1]) * 0.5;
            }
            return $pcm;
        }
        return $decoded;
    }

    /**
     * hybrid 合成：CELT 层输出比 SILK 重采样输出多出一个固定延迟（48k 下 72 样本），
     * 按 FFmpeg dec.c 的 celt_delay FIFO 方式把多出来的尾部顺延到下一帧。
     */
    private function combineHybrid(array $silk, array $celt): array
    {
        $ch = $this->channels;
        $silkN = intdiv(count($silk), $ch);
        $out = $silk;
        $pos = 0;
        if ($this->celtDelay !== []) {
            $delayN = intdiv(count($this->celtDelay), $ch);
            $take = min($delayN, $silkN);
            for ($i = 0; $i < $take; $i++) {
                for ($c = 0; $c < $ch; $c++) {
                    $out[$i * $ch + $c] += $this->celtDelay[$i * $ch + $c];
                }
            }
            $pos = $take;
        }
        $celtN = intdiv(count($celt), $ch);
        $add = min($silkN - $pos, $celtN);
        for ($i = 0; $i < $add; $i++) {
            for ($c = 0; $c < $ch; $c++) {
                $out[($pos + $i) * $ch + $c] += $celt[$i * $ch + $c];
            }
        }
        $this->celtDelay = array_slice($celt, $add * $ch);
        return $out;
    }

    /**
     * 解码 hybrid 冗余帧：240 样本、start_band=0 的纯 CELT 帧（FFmpeg dec.c 218-232）。
     * 该调用会写入完整 21 带能量，因此它解码后 CELT 的历史可直接供下一帧使用。
     */
    private function decodeRedundancyFrame(string $payload, int $codedChannels, int $endBand): array
    {
        if ($payload === '') {
            return [];
        }
        return $this->normalizeChannels(
            $this->celtDecoder->decode($payload, 240, $codedChannels, $endBand, 0),
            240,
            $codedChannels
        );
    }

    /**
     * FFmpeg dec.c 375-382（redundancy_pos=0）：本帧尾部 120-delayed 个样本用 window2
     * 把冗余帧 [120..] 淡入。
     */
    private function fadeInRedundancyTail(array $hybrid, array $redundancy, int $frameSamples): array
    {
        if ($redundancy === []) {
            return $hybrid;
        }
        $ch = $this->channels;
        $delay = max(0, min(120, $this->hybridDelayedSamples));
        $len = 120 - $delay;
        $start = $frameSamples - 120 + $delay;
        $window = CeltWindow::window2();
        $total = intdiv(count($hybrid), $ch);
        for ($i = 0; $i < $len; $i++) {
            $sample = $start + $i;
            if ($sample < 0 || $sample >= $total) {
                continue;
            }
            $w = $window[$i];
            for ($c = 0; $c < $ch; $c++) {
                $idx = $sample * $ch + $c;
                $red = $redundancy[(120 + $i) * $ch + $c] ?? 0.0;
                $hybrid[$idx] = $red * $w + $hybrid[$idx] * (1.0 - $w);
            }
        }
        return $hybrid;
    }

    /**
     * FFmpeg dec.c 384-390（redundancy_pos=1）：本帧开头 120 个样本替换为冗余帧，
     * 随后 120 个样本用 window2 从冗余帧淡出。
     */
    private function fadeInRedundancyHead(array $hybrid, array $redundancy, int $frameSamples): array
    {
        if ($redundancy === []) {
            return $hybrid;
        }
        $ch = $this->channels;
        $delay = max(0, min(120, $this->hybridDelayedSamples));
        $window = CeltWindow::window2();
        $total = intdiv(count($hybrid), $ch);
        for ($i = 0; $i < 120; $i++) {
            $sample = $delay + $i;
            if ($sample < 0 || $sample >= $total) {
                continue;
            }
            for ($c = 0; $c < $ch; $c++) {
                $hybrid[$sample * $ch + $c] = $redundancy[$i * $ch + $c] ?? 0.0;
            }
        }
        for ($i = 0; $i < 120; $i++) {
            $sample = $delay + 120 + $i;
            if ($sample < 0 || $sample >= $total) {
                continue;
            }
            $w = $window[$i];
            for ($c = 0; $c < $ch; $c++) {
                $idx = $sample * $ch + $c;
                $red = $redundancy[(120 + $i) * $ch + $c] ?? 0.0;
                $hybrid[$idx] = $hybrid[$idx] * $w + $red * (1.0 - $w);
            }
        }
        return $hybrid;
    }

    public function decodeS16le(string $packet): string
    {
        return Pcm16::floatsToS16le($this->decodeFloat($packet), $this->channels);
    }

    /**
     * 冲洗 SILK 重采样器的尾部延迟（线性相位滤波器需要前瞻，结束时补零）。
     * 返回交错格式的剩余 PCM 样本。
     */
    public function flushFloat(): array
    {
        $pcm = [];
        if ($this->silkResamplers !== []) {
            $extra = [];
            foreach ($this->silkResamplers as $index => $resampler) {
                $extra[$index] = $resampler->flush();
            }
            if ($this->channels === 1) {
                $pcm = $extra[0] ?? [];
            } else {
                $left = $extra[0] ?? [];
                $right = $extra[1] ?? $left;
                $count = min(count($left), count($right));
                for ($i = 0; $i < $count; $i++) {
                    $pcm[] = $left[$i];
                    $pcm[] = $right[$i];
                }
            }
        }
        // hybrid：把 CELT 层 FIFO 中剩余的尾样本叠加到冲洗输出的开头
        if ($this->celtDelay !== []) {
            $ch = $this->channels;
            $n = min(intdiv(count($this->celtDelay), $ch), intdiv(count($pcm), $ch));
            for ($i = 0; $i < $n; $i++) {
                for ($c = 0; $c < $ch; $c++) {
                    $pcm[$i * $ch + $c] += $this->celtDelay[$i * $ch + $c];
                }
            }
            $this->celtDelay = [];
        }
        return $pcm;
    }
}
