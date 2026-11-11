<?php

namespace Xiaosongshu\Flv2mp4\Audio;

use InvalidArgumentException;
use Xiaosongshu\Flv2mp4\Opus\OpusPacketParser;

/**
 * @purpose 音频目标策略（唯一实现处）：由「源格式 + 目标格式」决定直通还是转码。
 * @author yanglong
 * @time 2026年11月5日
 *
 * 配置语义（flv / mp4 / hls 三条重编码路径共用）：
 *  - 未配置 audio           → 目标=源：AAC/MP3 直通；Opus/PCM 转 AAC；其它格式无解码器 → 静音 AAC 轨；
 *  - 'audio' => 'aac'       → 源是 AAC 直通，否则转 AAC；无解码器 → 静音 AAC 轨；
 *  - 'audio' => 'mp3'       → 源是 MP3 直通，否则转 MP3；无解码器 → 确定性报错；
 *  - 其他取值               → 抛 InvalidArgumentException，不做静默回退。
 */
final class AudioPolicy
{
    public const CODEC_AAC = 'aac';
    public const CODEC_MP3 = 'mp3';
    /** Opus：纯 PHP CELT 解码器（SILK/Hybrid 未实现，由调用方在首包探测后落静音） */
    public const CODEC_OPUS = 'opus';
    /** PCM：未压缩样本，无需解码器，直接按位深/字节序解释后编码为 AAC-LC */
    public const CODEC_PCM = 'pcm';
    public const CODEC_UNKNOWN = 'unknown';

    /** AAC-LC 编码器覆盖的采样率（PCM/Opus 转 AAC 时可直接使用，无需重采样） */
    public const AAC_ENCODER_RATES = [8000, 11025, 12000, 16000, 22050, 24000, 32000, 44100, 48000];

    public const MODE_PASSTHROUGH = 'passthrough';
    public const MODE_TRANSCODE = 'transcode';
    /** 源无解码器：输出一条内容全静音的 AAC-LC 轨（如实声明，不是幽灵流） */
    public const MODE_SILENT = 'silent';

    /** MP3 编码器延迟（MPEG-1 Layer III 分析滤波器组，样本数） */
    public const MP3_ENCODER_DELAY = 576;
    /** 本项目 AAC-LC 编码器延迟（MDCT overlap，样本数） */
    public const AAC_ENCODER_DELAY = 1024;
    /** MP3 解码器合成滤波器组固有延迟（参考实现/标准实现一致） */
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

    /**
     * FLV 音频头 → 源格式。
     * 传统头：10=AAC，2=MP3，0/3=Linear PCM（平台/小端）。
     * 增强头（SoundFormat=9）必须结合 FourCC：Opus / mp4a / .mp3。
     */
    public static function sourceFromFlvSoundFormat(int $soundFormat, ?string $fourcc = null): string
    {
        if ($soundFormat === 10) return self::CODEC_AAC;
        if ($soundFormat === 2) return self::CODEC_MP3;
        if ($soundFormat === 0 || $soundFormat === 3) return self::CODEC_PCM;
        if ($soundFormat === 9) {
            if ($fourcc === 'Opus') return self::CODEC_OPUS;
            if ($fourcc === 'mp4a') return self::CODEC_AAC;
            if ($fourcc === '.mp3') return self::CODEC_MP3;
            return self::CODEC_UNKNOWN . ':9' . ($fourcc === null ? '' : '/' . trim($fourcc));
        }
        return self::CODEC_UNKNOWN . ':' . $soundFormat;
    }

    /**
     * 解析一个 FLV 音频 tag，统一传统头与增强头（SoundFormat=9）。
     * 返回 codec / 负载偏移 / 负载 / PCM 参数 / dOps / AAC ASC。
     *
     * 增强头布局：flags(1，高 4 位=9，低 4 位=包类型) + FourCC(4) + payload；
     * 包类型 0=SequenceStart（Opus 时 payload 为 dOps，AAC 为 ASC），1=CodedFrames。
     *
     * @return array{soundFormat:int,codec:string,packetType:int,payloadOffset:int,payload:string,
     *               rate:int,channels:int,bits:int,littleEndian:bool,unsigned:bool,dOps:?array,aacAsc:?string}
     */
    public static function parseFlvAudioTag(string $raw): array
    {
        $info = [
            'soundFormat' => 0, 'codec' => self::CODEC_UNKNOWN, 'packetType' => -1,
            'payloadOffset' => 0, 'payload' => '',
            'rate' => 0, 'channels' => 0, 'bits' => 0, 'littleEndian' => false, 'unsigned' => false,
            'dOps' => null, 'aacAsc' => null,
        ];
        if ($raw === '') return $info;
        $sf = (ord($raw[0]) >> 4) & 0x0F;
        $info['soundFormat'] = $sf;

        if ($sf === 9) {
            if (strlen($raw) < 5) return $info;
            $packetType = ord($raw[0]) & 0x0F;
            $offset = 1;
            if ($packetType === 5) {
                // AudioPacketTypeMultitrack：types(1) + FourCC(4) + trackIdx(1) + payload
                if (strlen($raw) < 8) return $info;
                $packetType = ord($raw[1]) & 0x0F;
                $offset = 2;
            }
            $fourcc = substr($raw, $offset, 4);
            $info['packetType'] = $packetType;
            $info['payloadOffset'] = $offset + 4 + ($packetType === 5 ? 1 : 0);
            $info['payload'] = substr($raw, $info['payloadOffset']);
            $info['codec'] = self::sourceFromFlvSoundFormat(9, $fourcc);
            if ($fourcc === 'Opus') {
                // Opus 在 FLV 中始终按 48 kHz 解码；声道/pre-skip 来自 SequenceStart 的 dOps/OpusHead
                $info['rate'] = 48000;
                $info['channels'] = 2;
                if ($packetType === 0) {
                    $dops = self::parseOpusIdentificationHeader($info['payload']);
                    if ($dops !== null) {
                        $info['dOps'] = $dops;
                        $info['channels'] = $dops['channels'];
                    }
                } elseif ($packetType === 4 && strlen($info['payload']) >= 2) {
                    // MultichannelConfig：order(1) + channels(1) + layout
                    $info['channels'] = max(1, ord($info['payload'][1]));
                }
            } elseif ($fourcc === 'mp4a' && $packetType === 0) {
                $info['aacAsc'] = $info['payload'];
            }
            return $info;
        }

        if ($sf === 0 || $sf === 3) {
            // SoundRate 2bit：0=5.5k 1=11.025k 2=22.05k 3=44.1k；SoundSize 1bit；SoundType 1bit
            $info['codec'] = self::CODEC_PCM;
            $info['payloadOffset'] = 1;
            $info['payload'] = substr($raw, 1);
            $info['rate'] = [5512, 11025, 22050, 44100][(ord($raw[0]) >> 2) & 3];
            $info['bits'] = ((ord($raw[0]) >> 1) & 1) === 1 ? 16 : 8;
            $info['channels'] = (ord($raw[0]) & 1) === 1 ? 2 : 1;
            // FLV 规范：0=Linear PCM platform endian（本项目按 x86 小端处理），3=小端
            $info['littleEndian'] = true;
            // 8bit PCM 为无符号（需减去 128 偏置），16bit 为有符号
            $info['unsigned'] = $info['bits'] === 8;
            return $info;
        }

        if ($sf === 10) {
            $info['codec'] = self::CODEC_AAC;
            $packetType = strlen($raw) > 1 ? ord($raw[1]) : -1;
            $info['packetType'] = $packetType;
            $info['payloadOffset'] = 2;
            $info['payload'] = substr($raw, 2);
            if ($packetType === 0) $info['aacAsc'] = $info['payload'];
            return $info;
        }

        if ($sf === 2) {
            $info['codec'] = self::CODEC_MP3;
            $info['payloadOffset'] = 1;
            $info['payload'] = substr($raw, 1);
            return $info;
        }

        $info['payloadOffset'] = 1;
        $info['payload'] = substr($raw, 1);
        $info['codec'] = self::CODEC_UNKNOWN . ':' . $sf;
        return $info;
    }

    /**
     * dOps / OpusHead 识别头（MP4 dOps 与 FLV Opus SequenceStart 内容一致，11 字节起）。
     * @return array{version:int,channels:int,preSkip:int,inputRate:int,gain:int,mappingFamily:int}|null
     */
    public static function parseOpusIdentificationHeader(string $data): ?array
    {
        // 参考实现/libopus 的 FLV SequenceStart 里写的是带 magic 的 OpusHead（多字节字段为小端），
        // 而 MP4 dOps 字段布局相同但为 ISO BMFF 大端，这里按 magic 区分。
        $littleEndian = false;
        if (substr($data, 0, 8) === 'OpusHead') {
            $data = substr($data, 8);
            $littleEndian = true;
        }
        if (strlen($data) < 11) return null;
        $gainRaw = unpack($littleEndian ? 'v' : 'n', substr($data, 8, 2))[1];
        return [
            'version' => ord($data[0]),
            'channels' => ord($data[1]),
            'preSkip' => unpack($littleEndian ? 'v' : 'n', substr($data, 2, 2))[1],
            'inputRate' => unpack($littleEndian ? 'V' : 'N', substr($data, 4, 4))[1],
            'gain' => $gainRaw >= 0x8000 ? $gainRaw - 0x10000 : $gainRaw,
            'mappingFamily' => ord($data[10]),
        ];
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
            if ($target === self::CODEC_MP3) {
                throw new InvalidArgumentException(
                    "无法识别源音频格式（{$sourceCodec}），不能转码为 mp3：本项目的 MP3/Opus 解码器不覆盖该格式"
                );
            }
            // 未配置目标或目标为 AAC：无解码器 → 生成内容全静音的 AAC-LC 轨并如实声明
            return [
                'source' => $sourceCodec,
                'target' => $target,
                'output' => self::CODEC_AAC,
                'mode' => self::MODE_SILENT,
            ];
        }

        // Opus / PCM 不能原样写入 FLV/MP4/HLS 的既有音频路径：未配置目标时一律转 AAC-LC
        if ($sourceCodec === self::CODEC_OPUS || $sourceCodec === self::CODEC_PCM) {
            return [
                'source' => $sourceCodec,
                'target' => $target,
                'output' => $target ?? self::CODEC_AAC,
                'mode' => self::MODE_TRANSCODE,
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
        if ($source === self::CODEC_OPUS) {
            // Opus 解码器固定输出 48 kHz；AAC/MP3 编码器都覆盖 48 kHz，无需重采样
            if ($rate !== 48000) {
                throw new InvalidArgumentException(
                    "Opus 源必须以 48000 Hz 送入转码器，当前为 {$rate} Hz（Opus 解码器固定 48 kHz 输出）"
                );
            }
            return;
        }
        if ($source === self::CODEC_PCM) {
            if ($target === self::CODEC_AAC) {
                if (!in_array($rate, self::AAC_ENCODER_RATES, true)) {
                    throw new InvalidArgumentException(
                        "PCM({$rate}Hz)→AAC 不支持：AAC 编码器覆盖 8000/11025/12000/16000/22050/24000/32000/44100/48000 Hz，本项目无重采样器"
                    );
                }
                return;
            }
            if ($target === self::CODEC_MP3) {
                if (!in_array($rate, [48000, 44100, 32000], true)) {
                    throw new InvalidArgumentException(
                        "PCM({$rate}Hz)→MP3 不支持：MP3 仅支持 32000/44100/48000 Hz，本项目无重采样器"
                    );
                }
                return;
            }
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
            // AAC-LC 编码器支持 32/44.1/48 kHz（与 MP3 的 MPEG-1 档位一致），无重采样器
            if (!in_array($rate, [48000, 44100, 32000], true)) {
                throw new InvalidArgumentException(
                    "MP3({$rate}Hz)→AAC 不支持：AAC 编码器仅支持 32000/44100/48000 Hz，本项目无重采样器"
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
     * 探测 Opus 包模式（CELT / SILK / Hybrid）。
     * 解析失败返回 null；调用方据此决定「转码」还是「确定性落静音」。
     */
    public static function opusPacketMode(string $packet): ?string
    {
        try {
            $info = OpusPacketParser::parse($packet);
            return $info['mode'] ?? null;
        } catch (\Throwable) {
            return null;
        }
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
