<?php

namespace Xiaosongshu\Flv2mp4\Audio;

use InvalidArgumentException;
use RuntimeException;
use Xiaosongshu\Flv2mp4\Aac\AacLcDecoder;
use Xiaosongshu\Flv2mp4\Aac\AacLcEncoder;
use Xiaosongshu\Flv2mp4\Mp3\Config as Mp3Config;
use Xiaosongshu\Flv2mp4\Mp3\Encoder as Mp3Encoder;
use Xiaosongshu\Flv2mp4\Mp3\Mp3L3Decoder;
use Xiaosongshu\Flv2mp4\Opus\OpusDecoder;

/**
 * @purpose 音频流式转码桥：输入源帧（AAC 访问单元 / MP3 帧字节 / Opus 包 / 裸 PCM），输出目标格式的完整帧。
 * 三条重编码路径共用，不做每帧重新构造，状态跨帧保留。
 * @author yanglong
 * @time 2026年11月5日
 *
 * 帧长说明：AAC 解码输出 1024 样本/帧，MP3 编码需要 1152 样本/帧，
 * 由目标编码器内部的 PcmBuffer 做样本级拼装（非重采样，采样率保持不变）。
 * Opus 解码器固定输出 48 kHz；PCM 按位深/字节序直接转 s16le，不做重采样。
 */
final class AudioTranscoder
{
    private const AAC_RATES = [96000, 88200, 64000, 48000, 44100, 32000, 24000, 22050, 16000, 12000, 11025, 8000, 7350];
    private const MP3_BITRATES = [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320];
    private const MP3_RATES = [44100, 48000, 32000];

    private AacLcDecoder|Mp3L3Decoder|null $decoder = null;
    private Mp3Encoder|AacLcEncoder|null $encoder = null;
    private ?OpusDecoder $opusDecoder = null;

    /** PCM 源参数（PCM 无需解码器，按位深/字节序直接解释） */
    private int $pcmBits = 16;
    private bool $pcmLittleEndian = true;
    private bool $pcmUnsigned = false;
    /** Opus dOps/OpusHead pre-skip（48 kHz 样本数，仅用于报告与时间戳补偿） */
    private int $sourcePreSkip = 0;

    /** 已送入解码器的源样本数 */
    private int $inputSamples = 0;

    /**
     * 编码器延迟补偿（样本域）：首次编码前预填的静音样本数。
     * MP3 目标 = 每帧 1152 - 往返延迟 1105 = 47；AAC 目标 = 1024 - 2048 → 0。
     */
    private int $prerollSamples = 0;
    /** 需要丢弃的编码器 priming 帧数（首帧对齐到源样本 0） */
    private int $dropFrames = 1;
    private bool $prerollApplied = false;

    /**
     * @param array{bits?:int,littleEndian?:bool,unsigned?:bool,preSkip?:int,gain?:int} $options
     */
    public function __construct(
        private readonly string $source,
        private readonly string $target,
        private readonly int $rate,
        private readonly int $channels,
        int $mp3Bitrate = 128000,
        array $options = []
    ) {
        AudioPolicy::assertTranscodable($source, $target, $rate, $channels);
        if ($source === AudioPolicy::CODEC_AAC) {
            $this->decoder = new AacLcDecoder();
        } elseif ($source === AudioPolicy::CODEC_MP3) {
            $this->decoder = new Mp3L3Decoder();
        } elseif ($source === AudioPolicy::CODEC_OPUS) {
            $this->opusDecoder = new OpusDecoder($channels, 48000);
            $this->sourcePreSkip = max(0, (int)($options['preSkip'] ?? 0));
            $this->opusDecoder->setPreSkip($this->sourcePreSkip);
            if (!empty($options['gain'])) {
                $this->opusDecoder->setOutputGain((int)$options['gain']);
            }
        } elseif ($source === AudioPolicy::CODEC_PCM) {
            $this->pcmBits = (int)($options['bits'] ?? 16);
            if (!in_array($this->pcmBits, [8, 16, 24, 32], true)) {
                throw new InvalidArgumentException('PCM 仅支持 8/16/24/32 bit，收到 ' . $this->pcmBits);
            }
            $this->pcmLittleEndian = (bool)($options['littleEndian'] ?? true);
            $this->pcmUnsigned = (bool)($options['unsigned'] ?? ($this->pcmBits === 8));
        }
        if ($target === AudioPolicy::CODEC_MP3) {
            if (!in_array($mp3Bitrate, array_map(fn ($v) => $v * 1000, self::MP3_BITRATES), true)) {
                $mp3Bitrate = 128000;
            }
            $this->encoder = new Mp3Encoder(new Mp3Config($rate, $channels, $mp3Bitrate));
        } else {
            $min = $channels === 1 ? 48000 : 96000;
            $bitrate = max($min, min(192000, $mp3Bitrate));
            $this->encoder = new AacLcEncoder($bitrate, $channels, $rate);
        }
        // 延迟补偿 = 丢 1 帧（samplesPerFrame）后，预填 samplesPerFrame - 往返总延迟 个静音样本；
        // MP3：1152 - (576+529) = 47；AAC：1024 - (1024+1024) < 0 → 0。
        $this->prerollSamples = AudioPolicy::samplesPerFrame($target) - AudioPolicy::codecDelay($target);
        if ($this->prerollSamples < 0) $this->prerollSamples = 0;
    }

    /**
     * 喂入一帧源音频。
     * @param string $payload AAC：原始访问单元（不带 ADTS）；MP3：一个或多个完整 MP3 帧；
     *                        Opus：一个 Opus 包（可含多帧）；PCM：裸 PCM 字节块
     * @return array<int,array{data:string,samples:int}> 输出帧（目标容器可直接写入）
     */
    public function push(string $payload): array
    {
        if ($payload === '') return [];
        $frames = [];

        if ($this->source === AudioPolicy::CODEC_AAC) {
            $pcm = $this->decoder->push($this->wrapAdts($payload, $this->rate, $this->channels));
            $samples = intdiv(strlen($pcm), 2 * $this->channels);
            $this->inputSamples += $samples;
            $this->encodeInto($pcm, $frames);
            return $frames;
        }

        if ($this->source === AudioPolicy::CODEC_OPUS) {
            // CELT 多帧包由 OpusDecoder 输出 48 kHz s16le（pre-skip 已在解码器内丢弃）
            $pcm = $this->opusDecoder->decodeS16le($payload);
            $this->inputSamples += intdiv(strlen($pcm), 2 * $this->channels);
            $this->encodeInto($pcm, $frames);
            return $frames;
        }

        if ($this->source === AudioPolicy::CODEC_PCM) {
            $pcm = $this->pcmToS16le($payload);
            $this->inputSamples += intdiv(strlen($pcm), 2 * $this->channels);
            $this->encodeInto($pcm, $frames);
            return $frames;
        }

        // MP3：一个 tag 可能含多个帧，逐帧解码后立即编码，保持内存 O(单帧)
        $this->decoder->decodeStreaming($payload, function (array $planes, int $channels) use (&$frames): void {
            $pcm = $this->interleaveS16($planes, $channels);
            $this->inputSamples += intdiv(strlen($pcm), 2 * $channels);
            $this->encodeInto($pcm, $frames);
        }, false);

        return $frames;
    }

    /** Opus 源 pre-skip（48 kHz 样本数），用于输出时间戳补偿 */
    public function sourcePreSkipSamples(): int { return $this->sourcePreSkip; }

    /** 冲刷解码器/编码器缓冲区，返回尾部完整帧 */
    public function flush(): array
    {
        $frames = [];
        if ($this->source === AudioPolicy::CODEC_AAC && $this->decoder instanceof AacLcDecoder) {
            $pcm = $this->decoder->flush();
            if ($pcm !== '') {
                $this->inputSamples += intdiv(strlen($pcm), 2 * $this->channels);
                $this->encodeInto($pcm, $frames);
            }
        }
        $tail = $this->encoder->flush();
        $this->appendEncoded($tail, $frames);
        return $frames;
    }

    public function outputCodec(): string { return $this->target; }
    public function outputRate(): int { return $this->rate; }
    public function outputChannels(): int { return $this->channels; }
    public function inputSamples(): int { return $this->inputSamples; }
    public function encoderDelay(): int { return AudioPolicy::encoderDelay($this->target); }
    public function samplesPerFrame(): int { return AudioPolicy::samplesPerFrame($this->target); }

    /** 输出为 AAC 时的 AudioSpecificConfig（供 FLV 序列头/MP4 esds 使用） */
    public function outputAudioSpecificConfig(): string
    {
        if ($this->encoder instanceof AacLcEncoder) {
            return $this->encoder->getAudioSpecificConfig();
        }
        return '';
    }

    /**
     * 裸 PCM → s16le（交错）。
     * 8bit：无符号时减 128 偏置（有符号时按 >=128 取负）；16/24/32bit：有符号，按字节序读取。
     * 位深大于 16 时右移截断到 16bit（不做 dither）。不足一帧的尾部字节丢弃。
     */
    private function pcmToS16le(string $payload): string
    {
        $bytesPerSample = intdiv($this->pcmBits, 8);
        $frameBytes = $bytesPerSample * $this->channels;
        if ($frameBytes <= 0) return '';
        $usable = intdiv(strlen($payload), $frameBytes) * $frameBytes;
        if ($usable <= 0) return '';
        $payload = $usable === strlen($payload) ? $payload : substr($payload, 0, $usable);

        if ($this->pcmBits === 16) {
            if ($this->pcmLittleEndian) return $payload;
            $out = '';
            $len = strlen($payload);
            for ($i = 0; $i < $len; $i += 2) {
                $out .= $payload[$i + 1] . $payload[$i];
            }
            return $out;
        }

        if ($this->pcmBits === 8) {
            $src = unpack('C*', $payload);
            $mapped = [];
            foreach ($src as $v) {
                $signed = $this->pcmUnsigned ? ($v - 128) : ($v >= 128 ? $v - 256 : $v);
                $mapped[] = ($signed * 256) & 0xFFFF;
            }
            return pack('v*', ...$mapped);
        }

        $out = '';
        $len = strlen($payload);
        for ($i = 0; $i + $bytesPerSample <= $len; $i += $bytesPerSample) {
            // 取高 16 位：24bit = 去掉最低字节，32bit = 去掉最低两字节
            if ($this->pcmLittleEndian) {
                $out .= $bytesPerSample === 4
                    ? substr($payload, $i + 2, 2)
                    : substr($payload, $i + 1, 2);
            } else {
                // 大端高 16 位是 [MSB, LSB]，写入 s16le 需要交换字节序
                $out .= $payload[$i + 1] . $payload[$i];
            }
        }
        return $out;
    }

    private function encodeInto(string $pcm, array &$frames): void
    {
        if ($pcm === '') return;
        if (!$this->prerollApplied) {
            $this->prerollApplied = true;
            if ($this->prerollSamples > 0) {
                $pcm = str_repeat("\0", $this->prerollSamples * $this->channels * 2) . $pcm;
            }
        }
        $this->appendEncoded($this->encoder->encodeS16le($pcm), $frames);
    }

    /** 拆分编码输出，并丢弃编码器 priming 首帧（保证第 1 个输出帧对应源样本 0） */
    private function appendEncoded(string $data, array &$frames): void
    {
        if ($data === '') return;
        $list = [];
        $this->splitEncoded($data, $list);
        if ($this->dropFrames > 0 && $list !== []) {
            $drop = min($this->dropFrames, count($list));
            $list = array_slice($list, $drop);
            $this->dropFrames -= $drop;
        }
        foreach ($list as $frame) {
            $frames[] = $frame;
        }
    }

    /** 把编码器返回的字节串拆成完整帧（MP3 按帧头长度，AAC 按 ADTS 长度） */
    private function splitEncoded(string $data, array &$frames): void
    {
        if ($data === '') return;
        if ($this->target === AudioPolicy::CODEC_MP3) {
            $off = 0;
            $total = strlen($data);
            while ($off + 4 <= $total) {
                $h = unpack('N', substr($data, $off, 4))[1];
                if (($h >> 21 & 0x7FF) !== 0x7FF) {
                    throw new RuntimeException('MP3 编码器输出帧头损坏');
                }
                $bi = ($h >> 12) & 0xF;
                $si = ($h >> 10) & 0x3;
                $padding = ($h >> 9) & 1;
                if ($bi === 0 || $si === 3) throw new RuntimeException('MP3 编码器输出帧头参数非法');
                $bitrate = self::MP3_BITRATES[$bi] * 1000;
                $rate = self::MP3_RATES[$si];
                $len = intdiv(144 * $bitrate, $rate) + $padding;
                if ($off + $len > $total) break;
                $frames[] = ['data' => substr($data, $off, $len), 'samples' => 1152];
                $off += $len;
            }
            return;
        }

        // AAC：编码器输出 ADTS 帧，去掉 7 字节头，容器写裸访问单元
        $off = 0;
        $total = strlen($data);
        while ($off + 7 <= $total) {
            $len = ((ord($data[$off + 3]) & 0x3) << 11)
                | (ord($data[$off + 4]) << 3)
                | (ord($data[$off + 5]) >> 5);
            if ($len < 7 || $off + $len > $total) break;
            $frames[] = ['data' => substr($data, $off + 7, $len - 7), 'samples' => 1024];
            $off += $len;
        }
    }

    /** planar float → interleaved s16le */
    private function interleaveS16(array $planes, int $channels): string
    {
        $count = count($planes[0] ?? []);
        if ($count === 0) return '';
        $out = '';
        if ($channels === 1) {
            for ($i = 0; $i < $count; $i++) {
                $v = (int)round($planes[0][$i] * 32768.0);
                $out .= pack('v', max(0, min(65535, $v & 0xFFFF)));
            }
            return $out;
        }
        $left = $planes[0] ?? [];
        $right = $planes[1] ?? [];
        for ($i = 0; $i < $count; $i++) {
            $l = (int)round($left[$i] * 32768.0);
            $r = (int)round($right[$i] * 32768.0);
            $out .= pack('vv', $l & 0xFFFF, $r & 0xFFFF);
        }
        return $out;
    }

    private function wrapAdts(string $frame, int $rate, int $channels): string
    {
        $index = array_search($rate, self::AAC_RATES, true);
        if ($index === false || strlen($frame) > 8190) {
            throw new InvalidArgumentException("AAC 帧参数非法: rate={$rate}");
        }
        $length = strlen($frame) + 7;
        $channelConfig = $channels === 2 ? 2 : 1;
        return pack(
            'C7',
            0xff,
            0xf1,
            0x40 | ($index << 2) | ($channelConfig >> 2),
            (($channelConfig & 3) << 6) | (($length >> 11) & 3),
            ($length >> 3) & 0xff,
            (($length & 7) << 5) | 0x1f,
            0xfc
        ) . $frame;
    }
}
