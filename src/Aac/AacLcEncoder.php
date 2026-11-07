<?php

namespace Xiaosongshu\Flv2mp4\Aac;

use InvalidArgumentException;

/**
 * @purpose 纯 PHP 实现的 AAC-LC 编码器，适用于 48 / 44.1 / 32 kHz 单声道或交错立体声 PCM
 * @author yanglong
 * @time 2026年8月12日17:08:03
 */
final class AacLcEncoder
{
    public const FRAME_SAMPLES = 1024;
    /** ADTS / ASC 采样率索引（与 ISO 14496-3 一致） */
    private const RATES = [96000, 88200, 64000, 48000, 44100, 32000, 24000, 22050, 16000, 12000, 11025, 8000, 7350];
    /**
     * 码率控制：全局 offset 二分次数，以及每帧的逐带局部调整上限。
     */
    private const MAX_GLOBAL_SEARCHES = 6;
    private const START_PEAK = 128.0;
    private const MAX_LOCAL_ADJUSTMENTS = 96;

    private int $bitrate;
    private int $channels;
    private int $sampleRate;
    /** 实际编码的比例因子带数（写入 ics_info 的 max_sfb） */
    private int $maxSfb;
    /** 当前采样率的长窗 swb 偏移表（FFmpeg ff_swb_offset_1024） */
    private array $swb;
    private ?int $previousGlobalOffset = null;
    private array $pending = [];
    private int $pendingOffset = 0;
    private array $overlap = [[], []];
    /** 贪心调账期间 (声道, 带, sf) → quantizeBand 结果缓存：同一帧内同参数重复求值结果恒定 */
    private array $adjustQuantCache = [];
    private int $frameCount = 0;
    /** 按采样率缓存的 band 索引表 */
    private static array $bandIndexCache = [];
    private static ?array $pow43Table = null;
    /** sf(0..255) → [quantizer, quantizer34]：两者都是 sf 的纯函数，避免每次量化都调 2 次 pow */
    private static ?array $quantTable = null;

    /**
     * 整数 4/3 次幂重建值表：pow(v, 4/3)，v 覆盖 0..64，超界回落 pow 原式。
     * 与 pow($value / $quantizer, 4/3) 存在 ~1ulp 浮点差异，仅影响失真评分排序，音质等价。
     */
    private static function pow43Table(): array
    {
        if (self::$pow43Table !== null) {
            return self::$pow43Table;
        }
        $table = [0 => 0.0];
        for ($v = 1; $v <= 64; ++$v) {
            $table[$v] = pow((float) $v, 4.0 / 3.0);
        }
        return self::$pow43Table = $table;
    }

    /**
     * 量化步长表：quantizer = 2^((104-sf)*3/16)，quantizer34 = 2^((104-sf)/4)。
     * 与逐次 pow 的表达式完全一致，取值位等价。
     */
    private static function quantTables(): array
    {
        if (self::$quantTable !== null) {
            return self::$quantTable;
        }
        $quantizer = [];
        $quantizer34 = [];
        for ($sf = 0; $sf <= 255; ++$sf) {
            $quantizer[$sf] = pow(2.0, (104 - $sf) * 3.0 / 16.0);
            $quantizer34[$sf] = pow(2.0, (104 - $sf) / 4.0);
        }
        return self::$quantTable = [$quantizer, $quantizer34];
    }

    /**
     * 预计算每个频谱样本所属的 scalefactor band（-1 表示超出 SWB[maxSfb]，不参与能量统计），
     * 与逐样本 while 推进逻辑位等价，仅省去每样本的边界判断。
     */
    private function bandIndexTable(): array
    {
        if (isset(self::$bandIndexCache[$this->sampleRate])) {
            return self::$bandIndexCache[$this->sampleRate];
        }
        $table = [];
        $band = 0;
        for ($i = 0; $i < self::FRAME_SAMPLES; ++$i) {
            while ($band + 1 < $this->maxSfb && $i >= $this->swb[$band + 1]) {
                ++$band;
            }
            $table[$i] = $i < $this->swb[$this->maxSfb] ? $band : -1;
        }
        return self::$bandIndexCache[$this->sampleRate] = $table;
    }

    public function __construct(int $bitrate = 128000, int $channels = 2, int $sampleRate = 48000)
    {
        if ($channels !== 1 && $channels !== 2) {
            throw new InvalidArgumentException('AAC channel count must be 1 or 2');
        }
        if (!in_array($sampleRate, [8000, 11025, 12000, 16000, 22050, 24000, 32000, 44100, 48000], true)) {
            throw new InvalidArgumentException("AAC encoder supports 8000/11025/12000/16000/22050/24000/32000/44100/48000 Hz, got {$sampleRate}");
        }
        if (!in_array($sampleRate, self::RATES, true)) {
            throw new InvalidArgumentException("AAC sample rate index missing for {$sampleRate}");
        }
        $minimumBitrate = $channels === 1 ? 48000 : 96000;
        if ($bitrate < $minimumBitrate || $bitrate > 192000) {
            throw new InvalidArgumentException("AAC bitrate must be between {$minimumBitrate} and 192000 bit/s");
        }
        $this->bitrate = $bitrate;
        $this->channels = $channels;
        $this->sampleRate = $sampleRate;
        $this->swb = AacTables::swbOffsetLong($sampleRate);
        $this->maxSfb = AacTables::longBandCount($sampleRate);
        $silence = array_fill(0, self::FRAME_SAMPLES, 0.0);
        $this->overlap = array_fill(0, $channels, $silence);
    }

    public function encodeFloat(array $interleavedPcm): string
    {
        foreach ($interleavedPcm as $sample) {
            $this->pending[] = max(-1.0, min(1.0, (float) $sample));
        }
        return $this->drain(false);
    }

    public function encodeS16le(string $interleavedPcm): string
    {
        $sampleFrameBytes = $this->channels * 2;
        if (strlen($interleavedPcm) % $sampleFrameBytes !== 0) {
            throw new InvalidArgumentException('s16le PCM must contain complete sample frames');
        }
        // 直接换算进待编码缓冲：s16 / 32768 必落在 [-1, 1]，与原 encodeFloat 的钳位结果一致，
        // 省掉一次中间 float 数组分配与一次遍历（直播路径每包 960 样本）。
        foreach (unpack('v*', $interleavedPcm) as $sample) {
            $this->pending[] = ($sample >= 0x8000 ? $sample - 0x10000 : $sample) / 32768.0;
        }
        return $this->drain(false);
    }

    public function flush(): string
    {
        if (count($this->pending) === $this->pendingOffset) {
            $this->pending = [];
            $this->pendingOffset = 0;
            return '';
        }
        $needed = self::FRAME_SAMPLES * $this->channels - (count($this->pending) - $this->pendingOffset);
        while ($needed-- > 0) {
            $this->pending[] = 0.0;
        }
        return $this->drain(true);
    }

    public function getAudioSpecificConfig(): string
    {
        $frequencyIndex = array_search($this->sampleRate, self::RATES, true);
        $config = (2 << 11) | ($frequencyIndex << 7) | ($this->channels << 3);
        return pack('n', $config);
    }

    public function channels(): int
    {
        return $this->channels;
    }

    public function frameCount(): int
    {
        return $this->frameCount;
    }

    private function drain(bool $flush): string
    {
        $output = '';
        $frameSize = self::FRAME_SAMPLES * $this->channels;
        $pendingCount = count($this->pending);
        while ($pendingCount - $this->pendingOffset >= $frameSize) {
            if ($this->channels === 1) {
                // 单声道无需去交织：直接切片，省掉 1024 次 PHP 层元素搬运
                $channels = [array_slice($this->pending, $this->pendingOffset, self::FRAME_SAMPLES)];
                $this->pendingOffset += self::FRAME_SAMPLES;
            } else {
                $channels = array_fill(0, $this->channels, []);
                $offset = $this->pendingOffset;
                for ($i = 0; $i < self::FRAME_SAMPLES; ++$i) {
                    for ($channel = 0; $channel < $this->channels; ++$channel) {
                        $channels[$channel][$i] = $this->pending[$offset++];
                    }
                }
                $this->pendingOffset = $offset;
            }
            $output .= $this->encodeFrame($channels);
        }
        if ($flush || $this->pendingOffset >= $frameSize * 8) {
            $this->pending = $flush
                ? []
                : array_slice($this->pending, $this->pendingOffset, $pendingCount - $this->pendingOffset);
            $this->pendingOffset = 0;
        }
        return $output;
    }

    private function encodeFrame(array $channels): string
    {
        $spectra = [];
        for ($ch = 0; $ch < $this->channels; ++$ch) {
            $input = array_merge($this->overlap[$ch], $channels[$ch]);
            $this->overlap[$ch] = $channels[$ch];
            $mdct = $this->mdct($input);
            $pow34 = [];
            $energy = [];
            $maxPow34 = [];
            $limits = [];
            $active = [];
            // 按带推进：省掉每样本的带索引查表，同时把 maxPow34 收敛出的 sf 下界
            // （同一帧内只与 bandMax 有关）一次算好，供 6 次码率探测复用。
            for ($band = 0; $band < $this->maxSfb; ++$band) {
                $bandStart = $this->swb[$band];
                $bandEnd = $this->swb[$band + 1];
                $sum = 0.0;
                $peak = 0.0;
                for ($i = $bandStart; $i < $bandEnd; ++$i) {
                    $value = $mdct[$i];
                    $magnitude = pow(abs($value), 0.75);
                    $pow34[$i] = $value < 0.0 ? -$magnitude : $magnitude;
                    $sum += $value * $value;
                    if ($magnitude > $peak) {
                        $peak = $magnitude;
                    }
                }
                $energy[$band] = $sum;
                $maxPow34[$band] = $peak;
                if ($peak > 0.0) {
                    $active[] = $band;
                    $limits[$band] = (int) floor(104.0 - (16.0 / 3.0) * log(15.5946 / $peak, 2)) + 1;
                } else {
                    $limits[$band] = 255;
                }
            }
            $spectra[$ch] = [
                'mdct' => $mdct,
                'pow34' => $pow34,
                'energy' => $energy,
                'maxPow34' => $maxPow34,
                'limit' => $limits,
                'active' => $active,
                'totalEnergy' => array_sum($energy),
                'peakPow34' => max($maxPow34),
            ];
        }

        $targetBytes = max(1, intdiv((int) floor($this->bitrate * 1024 / $this->sampleRate) - 56, 8));
        $attempts = 0;
        $plans = null;
        if ($this->previousGlobalOffset !== null) {
            // 码率估计阶段无需失真数据（estimateRawBytes 只消费缓存 bits）
            $candidate = [];
            foreach ($spectra as $spectrum) {
                $candidate[] = $this->analyzeChannel($spectrum, $this->previousGlobalOffset, false);
            }
            ++$attempts;
            $candidateBytes = $this->estimateRawBytes($candidate, $targetBytes);
            if ($candidateBytes <= $targetBytes && $candidateBytes >= (int) floor($targetBytes * 0.85)) {
                $plans = $candidate;
            }
        }
        if ($plans === null) {
            $low = 0;
            $high = 63;
            $bestPlans = null;
            $bestOffset = 64;
            while ($low <= $high && $attempts < self::MAX_GLOBAL_SEARCHES) {
                $offset = ($low + $high) >> 1;
                $candidate = [];
                foreach ($spectra as $spectrum) {
                    $candidate[] = $this->analyzeChannel($spectrum, $offset, false);
                }
                ++$attempts;
                if ($this->estimateRawBytes($candidate, $targetBytes) <= $targetBytes) {
                    $bestPlans = $candidate;
                    $bestOffset = $offset;
                    $high = $offset - 1;
                } else {
                    $low = $offset + 1;
                }
            }
            if ($bestPlans === null) {
                $bestPlans = [];
                foreach ($spectra as $spectrum) {
                    $bestPlans[] = $this->analyzeChannel($spectrum, 64);
                }
                ++$attempts;
            } else {
                // analyzeChannel 是确定性函数：按 bestOffset 用完整模式重建，失真数据与旧逻辑一致
                $withDistortion = [];
                foreach ($spectra as $spectrum) {
                    $withDistortion[] = $this->analyzeChannel($spectrum, $bestOffset);
                }
                $bestPlans = $withDistortion;
            }
            $this->previousGlobalOffset = $bestOffset;
            $plans = $this->adjustToBudget($spectra, $bestPlans, $targetBytes, $attempts);
        }
        $raw = $this->rawDataBlock($plans);

        ++$this->frameCount;
        return $this->adtsHeader(strlen($raw) + 7) . $raw;
    }

    private function analyzeChannel(array $spectrum, int $offset, bool $withDistortion = true): array
    {
        $maxAll = $spectrum['peakPow34'];
        $base = 0;
        if ($maxAll > 0.0) {
            $base = max(0, min(255, (int) ceil(104.0 + (16.0 / 3.0) * log($maxAll / (self::START_PEAK - 0.4054), 2))));
        }
        $scaleFactors = [];
        for ($band = 0; $band < $this->maxSfb; ++$band) {
            $scaleFactors[$band] = $spectrum['maxPow34'][$band] > 0.0
                ? max(0, min(255, $base + $offset))
                : 255;
        }
        $scaleFactors = $this->constrainScaleFactors($scaleFactors, $spectrum);
        $bands = [];
        for ($band = 0; $band < $this->maxSfb; ++$band) {
            $bands[$band] = $this->quantizeBand($spectrum, $band, $scaleFactors[$band], $withDistortion);
        }
        return $this->finishPlan($bands);
    }

    private function constrainScaleFactors(array $scaleFactors, array $spectrum): array
    {
        $active = $spectrum['active'];
        $count = count($active);
        // 全局统一步长下所有活动带的 sf 相同（且未截断到 255），约束恒成立：
        // sf[i] >= sf[j] - 60*|i-j| 化为 x >= x，直接返回可省掉 O(n^2) 比较。
        if ($count > 0) {
            $first = $scaleFactors[$active[0]];
            $uniform = true;
            for ($i = 1; $i < $count; ++$i) {
                if ($scaleFactors[$active[$i]] !== $first) {
                    $uniform = false;
                    break;
                }
            }
            if ($uniform) {
                return $scaleFactors;
            }
        }
        for ($i = 0; $i < $count; ++$i) {
            for ($j = 0; $j < $count; ++$j) {
                $scaleFactors[$active[$i]] = max(
                    $scaleFactors[$active[$i]],
                    $scaleFactors[$active[$j]] - 60 * abs($i - $j)
                );
            }
            $scaleFactors[$active[$i]] = min(255, $scaleFactors[$active[$i]]);
        }
        return $scaleFactors;
    }

    /**
     * 对单个 scalefactor band 量化。$withDistortion=false 时跳过失真/重建值计算
     * （仅用于码率估计阶段，量化值 q、qMax 与完整模式位等价，scaleFactor 推进逻辑不受影响）。
     */
    private function quantizeBand(array $spectrum, int $band, int $scaleFactor, bool $withDistortion = true): array
    {
        $start = $this->swb[$band];
        $end = $this->swb[$band + 1];
        $pow34 = $spectrum['pow34'];
        $mdct = $spectrum['mdct'];
        $pow43 = self::$pow43Table ?? self::pow43Table();
        $bandMax = $spectrum['maxPow34'][$band];
        // 全零带直接返回码本 0：后续写码流时码本 0 的带会被整体跳过，q 内容不参与比特输出。
        if ($bandMax <= 0.0) {
            return [
                'sf' => $scaleFactor,
                'codebook' => 0,
                'q' => [],
                'qMax' => 0,
                'spectralBits' => 0,
                'distortion' => 0.0,
            ];
        }
        // qMax(sf) = floor(max|pow34| * 2^(3*(104-sf)/16) + 0.4054) 对 sf 单调不增。
        // 先解析求出首个满足 qMax <= 15 的 sf，避免从全局 base 起逐级 +1 重复整带量化
        // （音质修复后低频响带需要 ~17 次整带重量化，是 2.6x 变慢的主因之一）。
        if ($bandMax > 0.0 && $scaleFactor < 255) {
            $limit = $spectrum['limit'][$band];
            if ($limit > $scaleFactor) {
                $scaleFactor = min(255, $limit);
            }
        }
        [$quantizers, $quantizers34] = self::quantTables();
        do {
            $quantizer = $quantizers[$scaleFactor];
            $quantizer34 = $quantizers34[$scaleFactor];
            $q = [];
            $qMax = 0;
            $distortion = 0.0;
            // 码率估计阶段（withDistortion=false）与重建阶段拆成两个循环，
            // 省掉每系数一次分支判断（估计调用占约 80%）。
            if ($withDistortion) {
                for ($i = $start; $i < $end; ++$i) {
                    $value34 = $pow34[$i];
                    $value = (int) floor(abs($value34) * $quantizer + 0.4054);
                    if ($value > $qMax) {
                        $qMax = $value;
                    }
                    $q[] = $value34 < 0.0 ? -$value : $value;
                    $reconstructed = $value <= 64 ? $pow43[$value] / $quantizer34 : pow($value / $quantizer, 4.0 / 3.0);
                    if ($mdct[$i] < 0.0) {
                        $reconstructed = -$reconstructed;
                    }
                    $error = $mdct[$i] - $reconstructed;
                    $distortion += $error * $error;
                }
            } else {
                for ($i = $start; $i < $end; ++$i) {
                    $value34 = $pow34[$i];
                    $value = (int) floor(abs($value34) * $quantizer + 0.4054);
                    if ($value > $qMax) {
                        $qMax = $value;
                    }
                    $q[] = $value34 < 0.0 ? -$value : $value;
                }
            }
            if ($qMax <= 15 || $scaleFactor === 255) {
                break;
            }
            ++$scaleFactor;
        } while (true);

        $codebook = 0;
        $spectralBits = 0;
        if ($qMax > 0) {
            if ($qMax <= 1) {
                $candidates = [5, 6, 7, 8];
            } elseif ($qMax <= 4) {
                $candidates = [5, 6];
            } elseif ($qMax <= 7) {
                $candidates = [7, 8];
            } elseif ($qMax <= 12) {
                $candidates = [9, 10];
            } else {
                $candidates = [11];
            }
            $bestBits = PHP_INT_MAX;
            foreach ($candidates as $candidate) {
                $bits = $this->spectralBits($q, $candidate);
                if ($bits < $bestBits) {
                    $bestBits = $bits;
                    $codebook = $candidate;
                }
            }
            $spectralBits = $bestBits;
        }
        return [
            'sf' => $scaleFactor,
            'codebook' => $codebook,
            'q' => $q,
            'qMax' => $qMax,
            'spectralBits' => $spectralBits,
            'distortion' => $distortion,
        ];
    }

    private function spectralBits(array $q, int $codebook): int
    {
        $bits = 0;
        $count = count($q);
        $table = AacTables::$tables[$codebook][1];
        if ($codebook <= 6) {
            for ($i = 0; $i < $count; $i += 2) {
                $bits += $table[($q[$i] + 4) * 9 + ($q[$i + 1] + 4)];
            }
            return $bits;
        }
        $base = $codebook <= 8 ? 8 : ($codebook <= 10 ? 13 : 17);
        for ($i = 0; $i < $count; $i += 2) {
            $a = $q[$i];
            $b = $q[$i + 1];
            $bits += $table[abs($a) * $base + abs($b)];
            $bits += ($a !== 0 ? 1 : 0) + ($b !== 0 ? 1 : 0);
        }
        return $bits;
    }

    private function finishPlan(array $bands): array
    {
        $globalGain = 0;
        foreach ($bands as $band) {
            if ($band['codebook'] !== 0) {
                $globalGain = $band['sf'];
                break;
            }
        }
        return ['globalGain' => $globalGain, 'bands' => $bands];
    }

    private function adjustToBudget(array $spectra, array $plans, int $targetBytes, int &$attempts): array
    {
        $this->adjustQuantCache = [];
        for ($iteration = 0; $iteration < self::MAX_LOCAL_ADJUSTMENTS; ++$iteration) {
            $currentChannelBits = [];
            $state = [];
            foreach ($plans as $channel => $plan) {
                $bands = $plan['bands'];
                $codebooks = [];
                $scaleFactors = [];
                $spectral = [];
                for ($band = 0; $band < $this->maxSfb; ++$band) {
                    $codebooks[$band] = $bands[$band]['codebook'];
                    $scaleFactors[$band] = $bands[$band]['sf'];
                    $spectral[$band] = $bands[$band]['spectralBits'];
                }
                // 候选只改一个带的码本，游程重排最多波及该带所在段与左右相邻段，
                // 预先记下每带的游程起止即可 O(1) 求出游程位差。
                $runStart = [];
                $runEnd = [];
                $runBits = 0;
                $band = 0;
                while ($band < $this->maxSfb) {
                    $value = $codebooks[$band];
                    $run = 1;
                    while ($band + $run < $this->maxSfb && $codebooks[$band + $run] === $value) {
                        ++$run;
                    }
                    $runBits += 4 + 5 * (intdiv($run, 31) + 1);
                    $end = $band + $run - 1;
                    for ($index = $band; $index <= $end; ++$index) {
                        $runStart[$index] = $band;
                        $runEnd[$index] = $end;
                    }
                    $band += $run;
                }
                // 前后最近非零带的下标：候选合法性判断与 sf 链差分共用
                $previousIndex = [];
                $nextIndex = [];
                $last = null;
                for ($band = 0; $band < $this->maxSfb; ++$band) {
                    $previousIndex[$band] = $last;
                    if ($codebooks[$band] !== 0) {
                        $last = $band;
                    }
                }
                $last = null;
                for ($band = $this->maxSfb - 1; $band >= 0; --$band) {
                    $nextIndex[$band] = $last;
                    if ($codebooks[$band] !== 0) {
                        $last = $band;
                    }
                }
                $globalGain = 0;
                for ($band = 0; $band < $this->maxSfb; ++$band) {
                    if ($codebooks[$band] !== 0) {
                        $globalGain = $scaleFactors[$band];
                        break;
                    }
                }
                $scaleFactorBits = 0;
                $previousSf = $globalGain;
                for ($band = 0; $band < $this->maxSfb; ++$band) {
                    if ($codebooks[$band] === 0) {
                        continue;
                    }
                    $scaleFactorBits += AacTables::SCALEFACTOR_BITS[$scaleFactors[$band] - $previousSf + 60];
                    $previousSf = $scaleFactors[$band];
                }
                $state[$channel] = [
                    'codebooks' => $codebooks,
                    'sf' => $scaleFactors,
                    'runStart' => $runStart,
                    'runEnd' => $runEnd,
                    'previous' => $previousIndex,
                    'next' => $nextIndex,
                ];
                // 与 channelPayloadBits 的分解式等价：3（段类型/窗序列等固定位）
                // + 码本游程位 + sf 差分位 + 各带频谱位
                $currentChannelBits[$channel] = 3 + $runBits + $scaleFactorBits + array_sum($spectral);
            }
            $currentBytes = $this->totalBytes($currentChannelBits);
            $overBudget = $currentBytes > $targetBytes;
            $best = null;
            foreach ($plans as $channel => $plan) {
                $bands = $plan['bands'];
                $channelState = $state[$channel];
                $codebooks = $channelState['codebooks'];
                $scaleFactors = $channelState['sf'];
                $runStart = $channelState['runStart'];
                $runEnd = $channelState['runEnd'];
                $previousIndex = $channelState['previous'];
                $nextIndex = $channelState['next'];
                for ($band = 0; $band < $this->maxSfb; ++$band) {
                    $old = $bands[$band];
                    if ($old['codebook'] === 0 || ($overBudget && $old['sf'] >= 255) || (!$overBudget && $old['sf'] <= 0)) {
                        continue;
                    }
                    $newSf = $old['sf'] + ($overBudget ? 1 : -1);
                    $prevBand = $previousIndex[$band];
                    $nextBand = $nextIndex[$band];
                    $previousSf = $prevBand === null ? null : $scaleFactors[$prevBand];
                    $nextSf = $nextBand === null ? null : $scaleFactors[$nextBand];
                    if (($previousSf !== null && abs($newSf - $previousSf) > 60)
                        || ($nextSf !== null && abs($nextSf - $newSf) > 60)) {
                        continue;
                    }
                    // 每轮都会对全部带重新评估候选 sf，但绝大多数带的 (sf) 未变，
                    // 命中缓存可把整带重量化从 O(迭代×带) 降到 O(带 + 实际变更数)。
                    // 缓存用整型三维数组，避免字符串键拼接在热循环里产生哈希开销。
                    if (!isset($this->adjustQuantCache[$channel][$band][$newSf])) {
                        $this->adjustQuantCache[$channel][$band][$newSf] =
                            $this->quantizeBand($spectra[$channel], $band, $newSf);
                    }
                    $newBand = $this->adjustQuantCache[$channel][$band][$newSf];
                    if ($newBand['qMax'] > 15 || (!$overBudget && $newBand['sf'] !== $newSf)) {
                        continue;
                    }
                    $newCodebook = $newBand['codebook'];
                    $actualSf = $newBand['sf'];
                    // 增量差分：频谱位直接替换；码本/游程与 sf 链只改动局部
                    $delta = $newBand['spectralBits'] - $old['spectralBits'];
                    if ($newCodebook !== $old['codebook']) {
                        // 游程重排：旧游程被本带切开；仅当本带正好在游程首/尾时才会与相邻同码本游程合并
                        $start = $runStart[$band];
                        $end = $runEnd[$band];
                        $added = 0;
                        $removed = 4 + 5 * (intdiv($end - $start + 1, 31) + 1);
                        $mergedLength = 1;
                        if ($band > $start) {
                            $added += 4 + 5 * (intdiv($band - $start, 31) + 1);
                        }
                        if ($band < $end) {
                            $added += 4 + 5 * (intdiv($end - $band, 31) + 1);
                        }
                        if ($band === $start && $start > 0 && $codebooks[$start - 1] === $newCodebook) {
                            $leftLength = $start - $runStart[$start - 1];
                            $mergedLength += $leftLength;
                            $removed += 4 + 5 * (intdiv($leftLength, 31) + 1);
                        }
                        if ($band === $end && $end + 1 < $this->maxSfb && $codebooks[$end + 1] === $newCodebook) {
                            $rightLength = $runEnd[$end + 1] - $end;
                            $mergedLength += $rightLength;
                            $removed += 4 + 5 * (intdiv($rightLength, 31) + 1);
                        }
                        $delta += $added + 4 + 5 * (intdiv($mergedLength, 31) + 1) - $removed;
                    }
                    if ($newCodebook !== 0) {
                        // sf 链节点替换（首节点时 globalGain 同步变化，差分位恒为 0）
                        if ($previousSf !== null) {
                            $delta += AacTables::SCALEFACTOR_BITS[$actualSf - $previousSf + 60]
                                - AacTables::SCALEFACTOR_BITS[$old['sf'] - $previousSf + 60];
                        }
                        if ($nextSf !== null) {
                            $delta += AacTables::SCALEFACTOR_BITS[$nextSf - $actualSf + 60]
                                - AacTables::SCALEFACTOR_BITS[$nextSf - $old['sf'] + 60];
                        }
                    } else {
                        // 该带退出 sf 链：前驱改接到后继（或成为首个非零带）
                        if ($previousSf === null) {
                            if ($nextSf !== null) {
                                $delta -= AacTables::SCALEFACTOR_BITS[$nextSf - $old['sf'] + 60];
                            }
                        } else {
                            $delta -= AacTables::SCALEFACTOR_BITS[$old['sf'] - $previousSf + 60];
                            if ($nextSf !== null) {
                                $delta += AacTables::SCALEFACTOR_BITS[$nextSf - $previousSf + 60]
                                    - AacTables::SCALEFACTOR_BITS[$nextSf - $old['sf'] + 60];
                            }
                        }
                    }
                    $candidateChannelBits = $currentChannelBits;
                    $candidateChannelBits[$channel] += $delta;
                    $candidateBytes = $this->totalBytes($candidateChannelBits);
                    if ($overBudget) {
                        $saved = $currentBytes - $candidateBytes;
                        if ($saved <= 0) {
                            continue;
                        }
                        $score = max(0.0, $newBand['distortion'] - $old['distortion']) / $saved;
                        if ($best === null || $score < $best['score']) {
                            $best = ['score' => $score, 'channel' => $channel, 'band' => $band, 'data' => $newBand];
                        }
                    } else {
                        $added = $candidateBytes - $currentBytes;
                        if ($added <= 0 || $candidateBytes > $targetBytes) {
                            continue;
                        }
                        $improvement = max(0.0, $old['distortion'] - $newBand['distortion']);
                        $score = $improvement / $added;
                        if ($score > 0.0 && ($best === null || $score > $best['score'])) {
                            $best = ['score' => $score, 'channel' => $channel, 'band' => $band, 'data' => $newBand];
                        }
                    }
                }
            }
            if ($best === null) {
                break;
            }
            $plans[$best['channel']]['bands'][$best['band']] = $best['data'];
            $plans[$best['channel']] = $this->finishPlan($plans[$best['channel']]['bands']);
            ++$attempts;
        }
        return $plans;
    }

    private function isScaleFactorLegal(array $bands, int $changedBand, int $newSf): bool
    {
        $previous = null;
        $next = null;
        for ($band = $changedBand - 1; $band >= 0; --$band) {
            if ($bands[$band]['codebook'] !== 0) {
                $previous = $bands[$band]['sf'];
                break;
            }
        }
        for ($band = $changedBand + 1; $band < $this->maxSfb; ++$band) {
            if ($bands[$band]['codebook'] !== 0) {
                $next = $bands[$band]['sf'];
                break;
            }
        }
        return ($previous === null || abs($newSf - $previous) <= 60)
            && ($next === null || abs($next - $newSf) <= 60);
    }

    /**
     * $maxBytes > 0 时做早停：只要已确定超过该字节数就立刻返回 $maxBytes + 1，
     * 供二分搜索/快速路径只做"是否超预算"判定，避免为超预算的探测点算完全部带。
     */
    private function estimateRawBytes(array $plans, int $maxBytes = 0): int
    {
        $limitBits = $maxBytes > 0 ? $maxBytes * 8 : PHP_INT_MAX;
        $channelBits = [];
        $accumulated = 0;
        foreach ($plans as $channel => $plan) {
            $bits = $this->channelPayloadBits($plan, $limitBits - $accumulated);
            if ($bits === PHP_INT_MAX) {
                return $maxBytes + 1;
            }
            $channelBits[$channel] = $bits;
            $accumulated += $bits + 8;
        }
        return $this->totalBytes($channelBits);
    }

    /** 由各通道载荷位数换算整帧字节数（与原始位流封装开销一致） */
    private function totalBytes(array $channelBits): int
    {
        if ($this->channels === 1) {
            $bits = 3 + 4 + 8 + 11 + $channelBits[0] + 3;
        } else {
            $bits = 3 + 4 + 1 + 11 + 2;
            foreach ($channelBits as $value) {
                $bits += 8 + $value;
            }
            $bits += 3;
        }
        return intdiv($bits + 7, 8);
    }

    /**
     * 通道载荷位数。$limitBits 用于码率搜索的"超预算即中止"：
     * 一旦累计超过该上限，返回 PHP_INT_MAX 表示"必定超预算"，调用方据此判定即可，
     * 不需要精确值（预算是硬上界，判定结果与完整计算一致）。
     */
    private function channelPayloadBits(array $plan, int $limitBits = PHP_INT_MAX): int
    {
        $bands = $plan['bands'];
        $codebooks = [];
        for ($i = 0; $i < $this->maxSfb; ++$i) {
            $codebooks[$i] = $bands[$i]['codebook'];
        }
        $bits = 3;
        $band = 0;
        while ($band < $this->maxSfb) {
            $codebook = $codebooks[$band];
            $run = 1;
            while ($band + $run < $this->maxSfb && $codebooks[$band + $run] === $codebook) {
                ++$run;
            }
            $bits += 4 + 5 * (intdiv($run, 31) + 1);
            $band += $run;
        }
        $previous = $plan['globalGain'];
        for ($i = 0; $i < $this->maxSfb; ++$i) {
            if ($codebooks[$i] === 0) {
                continue;
            }
            $difference = $bands[$i]['sf'] - $previous;
            $bits += AacTables::SCALEFACTOR_BITS[$difference + 60];
            $previous = $bands[$i]['sf'];
            $bits += $bands[$i]['spectralBits'];
            if ($bits > $limitBits) {
                return PHP_INT_MAX;
            }
        }
        return $bits;
    }

    private function rawDataBlock(array $plans): string
    {
        $writer = new BitWriter();
        if ($this->channels === 1) {
            $writer->write(0, 3);
            $writer->write(0, 4);
            $writer->write($plans[0]['globalGain'], 8);
            $this->writeIcsInfo($writer);
            $this->writeChannelPayload($writer, $plans[0]);
        } else {
            $writer->write(1, 3);
            $writer->write(0, 4);
            $writer->write(1, 1);
            $this->writeIcsInfo($writer);
            $writer->write(0, 2);
            foreach ($plans as $plan) {
                $writer->write($plan['globalGain'], 8);
                $this->writeChannelPayload($writer, $plan);
            }
        }
        $writer->write(7, 3);
        return $writer->finish();
    }

    private function writeIcsInfo(BitWriter $writer): void
    {
        $writer->write(0, 1);
        $writer->write(0, 2);
        $writer->write(0, 1);
        $writer->write($this->maxSfb, 6);
        $writer->write(0, 1);
    }

    private function writeChannelPayload(BitWriter $writer, array $plan): void
    {
        $band = 0;
        while ($band < $this->maxSfb) {
            $codebook = $plan['bands'][$band]['codebook'];
            $run = 1;
            while ($band + $run < $this->maxSfb && $plan['bands'][$band + $run]['codebook'] === $codebook) {
                ++$run;
            }
            $writer->write($codebook, 4);
            $remaining = $run;
            while ($remaining >= 31) {
                $writer->write(31, 5);
                $remaining -= 31;
            }
            $writer->write($remaining, 5);
            $band += $run;
        }

        $previous = $plan['globalGain'];
        foreach ($plan['bands'] as $band) {
            if ($band['codebook'] === 0) {
                continue;
            }
            $difference = $band['sf'] - $previous;
            $writer->write(AacTables::SCALEFACTOR_CODES[$difference + 60], AacTables::SCALEFACTOR_BITS[$difference + 60]);
            $previous = $band['sf'];
        }
        $writer->write(0, 1);
        $writer->write(0, 1);
        $writer->write(0, 1);

        foreach ($plan['bands'] as $band) {
            if ($band['codebook'] === 0) {
                continue;
            }
            $q = $band['q'];
            $count = count($q);
            $codebook = $band['codebook'];
            $codes = AacTables::$tables[$codebook][0];
            $bitLengths = AacTables::$tables[$codebook][1];
            $base = $codebook <= 8 ? 8 : ($codebook <= 10 ? 13 : 17);
            for ($i = 0; $i < $count; $i += 2) {
                $a = $q[$i];
                $b = $q[$i + 1];
                if ($codebook <= 6) {
                    $index = ($a + 4) * 9 + ($b + 4);
                    $writer->write($codes[$index], $bitLengths[$index]);
                } else {
                    $index = abs($a) * $base + abs($b);
                    $writer->write($codes[$index], $bitLengths[$index]);
                    if ($a !== 0) {
                        $writer->write($a < 0 ? 1 : 0, 1);
                    }
                    if ($b !== 0) {
                        $writer->write($b < 0 ? 1 : 0, 1);
                    }
                }
            }
        }
    }

    private function mdct(array $input): array
    {
        return FastMdct::transform($input);
    }

    private function adtsHeader(int $frameLength): string
    {
        $profile = 1;
        $frequencyIndex = array_search($this->sampleRate, self::RATES, true);
        $channelConfig = $this->channels;
        return pack('C7',
            0xff,
            0xf1,
            ($profile << 6) | ($frequencyIndex << 2) | ($channelConfig >> 2),
            (($channelConfig & 3) << 6) | (($frameLength >> 11) & 3),
            ($frameLength >> 3) & 0xff,
            (($frameLength & 7) << 5) | 0x1f,
            0xfc
        );
    }
}
