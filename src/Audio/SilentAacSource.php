<?php

namespace Xiaosongshu\Flv2mp4\Audio;

use Xiaosongshu\Flv2mp4\Aac\AacLcEncoder;

/**
 * @purpose 静音 AAC-LC 音轨帧源：源音频无法解码时，输出一条内容全静音、但与视频同长的 AAC 轨。
 * @author yanglong
 * @time 2026年11月5日
 *
 * 静音帧编码一次即可复用（全零输入的 MDCT 结果逐帧相同），内存与 CPU 开销可忽略。
 * 采样率优先沿用源声明；不在 32/44.1/48 kHz 档位时回落到 44.1 kHz。声道 1/2，其余回落立体声。
 */
final class SilentAacSource
{
    public const DEFAULT_RATE = 44100;
    public const DEFAULT_CHANNELS = 2;
    public const FRAME_SAMPLES = 1024;

    private int $rate;
    private int $channels;
    private string $frame;
    private string $asc;

    /** 静音轨采样率：仅支持编码器覆盖的 32/44.1/48 kHz，其余回落 44.1 kHz */
    public static function resolveRate(int $declared): int
    {
        return in_array($declared, [32000, 44100, 48000], true) ? $declared : self::DEFAULT_RATE;
    }

    public static function resolveChannels(int $declared): int
    {
        return $declared === 1 ? 1 : 2;
    }

    public function __construct(int $declaredRate, int $declaredChannels)
    {
        $this->rate = self::resolveRate($declaredRate);
        $this->channels = self::resolveChannels($declaredChannels);
        $encoder = new AacLcEncoder(
            $this->channels === 1 ? 48000 : 96000,
            $this->channels,
            $this->rate
        );
        $adts = $encoder->encodeS16le(str_repeat("\0", self::FRAME_SAMPLES * $this->channels * 2));
        $this->frame = strlen($adts) > 7 ? substr($adts, 7) : '';
        $this->asc = $encoder->getAudioSpecificConfig();
    }

    public function rate(): int { return $this->rate; }
    public function channels(): int { return $this->channels; }
    public function audioSpecificConfig(): string { return $this->asc; }
    /** 裸 AAC 访问单元（不带 ADTS 头） */
    public function frame(): string { return $this->frame; }
    public function frameDurationMs(): float { return self::FRAME_SAMPLES * 1000.0 / $this->rate; }

    /** 覆盖 durationMs 所需的帧数（向上取整） */
    public function framesFor(int $durationMs): int
    {
        if ($durationMs <= 0) return 0;
        return (int)ceil($durationMs / $this->frameDurationMs());
    }
}
