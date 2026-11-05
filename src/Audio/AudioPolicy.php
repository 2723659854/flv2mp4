<?php

namespace Xiaosongshu\Flv2mp4\Audio;

use InvalidArgumentException;

/**
 * @purpose 音频目标策略（唯一实现处）：由「源格式 + 目标格式」决定直通还是转码。
 * @author yanglong
 * @time 2026年11月5日
 *
 * 配置语义（flv / mp4 / hls 三条重编码路径共用）：
 *  - 未配置 audio           → 完全不动音频，原样直通；
 *  - 'audio' => 'aac'       → 源是 AAC 直通，否则转 AAC；
 *  - 'audio' => 'mp3'       → 源是 MP3 直通，否则转 MP3；
 *  - 其他取值               → 抛 InvalidArgumentException，不做静默回退。
 */
final class AudioPolicy
{
    public const CODEC_AAC = 'aac';
    public const CODEC_MP3 = 'mp3';
    public const CODEC_UNKNOWN = 'unknown';

    public const MODE_PASSTHROUGH = 'passthrough';
    public const MODE_TRANSCODE = 'transcode';

    /** MP3 编码器延迟（MPEG-1 Layer III 分析滤波器组，样本数） */
    public const MP3_ENCODER_DELAY = 576;
    /** 本项目 AAC-LC 编码器延迟（MDCT overlap，样本数） */
    public const AAC_ENCODER_DELAY = 1024;
    /** MP3 解码器合成滤波器组固有延迟（ffmpeg/标准实现一致） */
    public const MP3_DECODER_DELAY = 529;

    /**
     * 解析配置中的 audio 目标；未配置返回 null。
     * 只接受 aac / mp3，其他值直接报错（不静默回退到直通）。
     */
    public static function targetFromConfig(array $config): ?string
    {
        if (!array_key_exists('audio', $config) || $config['audio'] === null) {
            return null;
        }
        $target = strtolower(trim((string)$config['audio']));
        if ($target !== self::CODEC_AAC && $target !== self::CODEC_MP3) {
            throw new InvalidArgumentException(
                "配置项 audio 只支持 'aac' 或 'mp3'，收到: '" . $config['audio'] . "'"
            );
        }
        return $target;
    }

    /** FLV SoundFormat → 源格式（10=AAC，2=MP3） */
    public static function sourceFromFlvSoundFormat(int $soundFormat): string
    {
        if ($soundFormat === 10) return self::CODEC_AAC;
        if ($soundFormat === 2) return self::CODEC_MP3;
        return self::CODEC_UNKNOWN . ':' . $soundFormat;
    }

    /** MP4 esds ObjectTypeIndication → 源格式 */
    public static function sourceFromMp4ObjectTypeIndication(int $oti): string
    {
        // 0x40 = MPEG-4 Audio (AAC)；0x66/0x67/0x68 = MPEG-2 AAC 系列
        if ($oti === 0x40 || $oti === 0x66 || $oti === 0x67 || $oti === 0x68) {
            return self::CODEC_AAC;
        }
        // 0x69 = MPEG-2 audio；0x6B = MPEG-1 audio（MP3）
        if ($oti === 0x69 || $oti === 0x6B) {
            return self::CODEC_MP3;
        }
        return self::CODEC_UNKNOWN . ':' . $oti;
    }

    /**
     * 决策：源格式 + 目标格式 → 直通/转码 + 输出格式。
     * @return array{source:string,target:?string,output:string,mode:string}
     */
    public static function decide(string $sourceCodec, ?string $target): array
    {
        if (str_starts_with($sourceCodec, self::CODEC_UNKNOWN)) {
            if ($target !== null) {
                throw new InvalidArgumentException(
                    "无法识别源音频格式（{$sourceCodec}），不能转码为 {$target}；请确认源为 AAC 或 MP3"
                );
            }
            // 未配置目标时保持旧行为：未知源音频不写入输出
            return [
                'source' => $sourceCodec,
                'target' => null,
                'output' => $sourceCodec,
                'mode' => self::MODE_PASSTHROUGH,
            ];
        }

        $output = $target ?? $sourceCodec;
        $mode = ($target !== null && $target !== $sourceCodec)
            ? self::MODE_TRANSCODE
            : self::MODE_PASSTHROUGH;

        return [
            'source' => $sourceCodec,
            'target' => $target,
            'output' => $output,
            'mode' => $mode,
        ];
    }

    /**
     * 转码可行性校验：缺解码器/编码器能力时给出明确错误，不产出坏文件。
     * @param string $source aac|mp3
     * @param string $target aac|mp3
     */
    public static function assertTranscodable(string $source, string $target, int $rate, int $channels): void
    {
        if ($rate <= 0 || $channels < 1 || $channels > 2) {
            throw new InvalidArgumentException(
                "音频转码仅支持 1/2 声道，当前 rate={$rate} channels={$channels}"
            );
        }
        if ($source === self::CODEC_AAC && $target === self::CODEC_MP3) {
            // MP3（MPEG-1 Layer III）只支持 32/44.1/48 kHz
            if (!in_array($rate, [48000, 44100, 32000], true)) {
                throw new InvalidArgumentException(
                    "AAC({$rate}Hz)→MP3 不支持：MP3 仅支持 32000/44100/48000 Hz，本项目无重采样器"
                );
            }
            return;
        }
        if ($source === self::CODEC_MP3 && $target === self::CODEC_AAC) {
            // 本项目 AAC-LC 编码器固定 48 kHz，无重采样器
            if ($rate !== 48000) {
                throw new InvalidArgumentException(
                    "MP3({$rate}Hz)→AAC 不支持：AAC 编码器固定 48kHz，本项目无重采样器（仅支持 48kHz 源）"
                );
            }
            return;
        }
        if ($source !== $target) {
            throw new InvalidArgumentException("不支持的音频转码组合: {$source} → {$target}");
        }
    }

    /** 输出格式对应的编码器延迟样本数 */
    public static function encoderDelay(string $codec): int
    {
        if ($codec === self::CODEC_MP3) return self::MP3_ENCODER_DELAY;
        if ($codec === self::CODEC_AAC) return self::AAC_ENCODER_DELAY;
        return 0;
    }

    /** 解码侧固有延迟样本数（MP3 合成滤波器组 529；AAC-LC 一帧） */
    public static function decoderDelay(string $codec): int
    {
        if ($codec === self::CODEC_MP3) return self::MP3_DECODER_DELAY;
        if ($codec === self::CODEC_AAC) return self::AAC_ENCODER_DELAY;
        return 0;
    }

    /** 编码+解码总往返延迟（用于样本域对齐补偿） */
    public static function codecDelay(string $codec): int
    {
        return self::encoderDelay($codec) + self::decoderDelay($codec);
    }

    /** 输出格式每帧样本数 */
    public static function samplesPerFrame(string $codec): int
    {
        return $codec === self::CODEC_MP3 ? 1152 : 1024;
    }

    /**
     * 解析 MP3 帧头，返回采样率与声道数（供 FLV/TS 源格式参数读取，避免各驱动各写一份）。
     * @return array{rate:int,channels:int}
     */
    public static function mp3FrameHeader(string $frame): array
    {
        $result = ['rate' => 0, 'channels' => 0];
        if (strlen($frame) < 4) return $result;
        $b1 = ord($frame[0]);
        $b2 = ord($frame[1]);
        $b3 = ord($frame[2]);
        if ($b1 !== 0xFF || ($b2 & 0xE0) !== 0xE0) return $result;
        $version = ($b2 >> 3) & 0x03; // 3=MPEG1, 2=MPEG2, 0=MPEG2.5
        $layer = ($b2 >> 1) & 0x03;   // 1=Layer III
        if ($layer !== 1) return $result;
        $rateIndex = ($b3 >> 2) & 0x03;
        if ($rateIndex === 3) return $result;
        $rates = $version === 3 ? [44100, 48000, 32000] : ($version === 2 ? [22050, 24000, 16000] : [11025, 12000, 8000]);
        $result['rate'] = $rates[$rateIndex];
        $channelMode = (ord($frame[3]) >> 6) & 0x03;
        $result['channels'] = $channelMode === 3 ? 1 : 2;
        return $result;
    }
}
