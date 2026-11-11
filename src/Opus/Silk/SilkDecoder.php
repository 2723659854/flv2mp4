<?php

namespace Xiaosongshu\Flv2mp4\Opus\Silk;

use Xiaosongshu\Flv2mp4\Opus\RangeDecoder;

/**
 * @purpose SILK 解码器
 * @author yanglong
 */


final class SilkDecoder
{
    private const HISTORY = 322;
    private const LTP_ORDER = 5;
    private const MAX_LAG = 290;
    private const INT32_MIN = -2147483648;
    private const INT32_MAX = 2147483647;

    private int $outputChannels;
    private int $midonly = 0;
    private int $subframes = 0;
    private int $sflength = 0;
    private int $flength = 0;
    private int $nlsfInterpFactor = 4;
    private int $bandwidth = 0;
    private bool $wb = false;
    /** @var SilkFrame[] */
    private array $frame = [];
    private array $prevStereoWeights = [0.0, 0.0];
    private array $stereoWeights = [0.0, 0.0];
    private int $prevCodedChannels = 0;

    public function __construct(int $outputChannels = 2)
    {
        if ($outputChannels !== 1 && $outputChannels !== 2) {
            throw new \InvalidArgumentException('SILK output channels must be 1 or 2');
        }
        $this->outputChannels = $outputChannels;
        $this->frame = [new SilkFrame(), new SilkFrame()];
    }

    public function reset(): void
    {
        $this->frame = [new SilkFrame(), new SilkFrame()];
        $this->prevStereoWeights = [0.0, 0.0];
        $this->stereoWeights = [0.0, 0.0];
        $this->prevCodedChannels = 0;
        $this->midonly = 0;
        if ($this->flength > 0) {
            $this->frame[1]->flush();
        }
    }

    /** 内部采样率（NB 8k / MB 12k / WB 16k） */
    public function sampleRate(): int
    {
        return [8000, 12000, 16000][$this->bandwidth] ?? 8000;
    }

    /**
     * 解码一个 SILK superframe（一个 Opus 帧，10/20/40/60ms）。
     *
     * @param RangeDecoder $rc 已初始化并完成覆盖层头解析的区间解码器
     * @param int $bandwidth 0=NB 1=MB 2=WB
     * @param int $codedChannels 1 或 2
     * @param int $durationMs 10/20/40/60
     * @return array<int, array<int, float>> 每声道内部采样率样本
     */
    public function decodeSuperframe(RangeDecoder $rc, int $bandwidth, int $codedChannels, int $durationMs): array
    {
        if ($bandwidth > 2 || $codedChannels > 2 || $durationMs > 60) {
            throw new \InvalidArgumentException('Invalid SILK parameters');
        }
        $nbFrames = 1 + ($durationMs > 20 ? 1 : 0) + ($durationMs > 40 ? 1 : 0);
        $this->subframes = intdiv(intdiv($durationMs, $nbFrames), 5);
        $this->sflength = 20 * ($bandwidth + 2);
        $this->flength = $this->sflength * $this->subframes;
        $this->bandwidth = $bandwidth;
        $this->wb = $bandwidth === 2;

        if ($codedChannels > $this->prevCodedChannels) {
            $this->frame[1]->flush();
        }
        $this->prevCodedChannels = $codedChannels;

        $active = [[], []];
        $redundancy = [0, 0];
        for ($i = 0; $i < $codedChannels; $i++) {
            for ($j = 0; $j < $nbFrames; $j++) {
                $active[$i][$j] = $rc->decodeBitLogp(1);
            }
            $redundancy[$i] = $rc->decodeBitLogp(1);
        }
        for ($i = 0; $i < $codedChannels; $i++) {
            if ($redundancy[$i] && $durationMs > 20) {
                $redundancy[$i] = $rc->decodeCdfFfmpeg($durationMs === 40
                    ? SilkTables::model_lbrr_flags_40
                    : SilkTables::model_lbrr_flags_60);
            }
        }

        for ($i = 0; $i < $nbFrames; $i++) {
            for ($j = 0; $j < $codedChannels; $j++) {
                if ($redundancy[$j] & (1 << $i)) {
                    $active1 = ($j === 0 && !($redundancy[1] & (1 << $i))) ? 0 : 1;
                    $this->decodeFrame($rc, $i, $j, $codedChannels, 1, $active1, 1);
                }
            }
            $this->midonly = 0;
        }

        $out = [];
        for ($c = 0; $c < $this->outputChannels; $c++) {
            $out[$c] = array_fill(0, $nbFrames * $this->flength, 0.0);
        }

        for ($i = 0; $i < $nbFrames; $i++) {
            for ($j = 0; $j < $codedChannels && !$this->midonly; $j++) {
                $active1 = $codedChannels > 1 ? $active[1][$i] : 0;
                $this->decodeFrame($rc, $i, $j, $codedChannels, $active[$j][$i], $active1, 0);
            }

            if ($this->midonly && $this->frame[1]->coded) {
                $this->frame[1]->flush();
            }

            if ($codedChannels === 1 || $this->outputChannels === 1) {
                $base = self::HISTORY - $this->flength - 2;
                for ($j = 0; $j < $this->outputChannels; $j++) {
                    for ($k = 0; $k < $this->flength; $k++) {
                        $out[$j][$i * $this->flength + $k] = $this->frame[0]->output[$base + $k];
                    }
                }
            } else {
                $this->unmixMs($out[0], $out[1], $i * $this->flength);
            }
            $this->midonly = 0;
        }

        return $out;
    }

    private function decodeFrame(RangeDecoder $rc, int $frameNum, int $channel, int $codedChannels, int $active, int $active1, int $redundant): void
    {
        $frame = $this->frame[$channel];
        $voiced = 0;
        $qoffsetHigh = 0;

        if ($codedChannels === 2 && $channel === 0) {
            $n = $rc->decodeCdfFfmpeg(SilkTables::model_stereo_s1);
            $wi0 = $rc->decodeCdfFfmpeg(SilkTables::model_stereo_s2) + 3 * intdiv($n, 5);
            $ws0 = $rc->decodeCdfFfmpeg(SilkTables::model_stereo_s3);
            $wi1 = $rc->decodeCdfFfmpeg(SilkTables::model_stereo_s2) + 3 * ($n % 5);
            $ws1 = $rc->decodeCdfFfmpeg(SilkTables::model_stereo_s3);

            $w = [0, 0];
            foreach ([0 => [$wi0, $ws0], 1 => [$wi1, $ws1]] as $idx => $pair) {
                $wi = $pair[0];
                $ws = $pair[1];
                $w[$idx] = SilkTables::stereo_weights[$wi]
                    + (((SilkTables::stereo_weights[$wi + 1] - SilkTables::stereo_weights[$wi]) * 6554) >> 16)
                    * ($ws * 2 + 1);
            }
            $this->stereoWeights[0] = ($w[0] - $w[1]) / 8192.0;
            $this->stereoWeights[1] = $w[1] / 8192.0;

            $this->midonly = $active1 ? 0 : $rc->decodeCdfFfmpeg(SilkTables::model_mid_only);
        }

        if (!$active) {
            $qoffsetHigh = $rc->decodeCdfFfmpeg(SilkTables::model_frame_type_inactive);
            $voiced = 0;
        } else {
            $type = $rc->decodeCdfFfmpeg(SilkTables::model_frame_type_active);
            $qoffsetHigh = $type & 1;
            $voiced = $type >> 1;
        }

        $sf = [];
        for ($i = 0; $i < $this->subframes; $i++) {
            if ($i === 0 && ($frameNum === 0 || !$frame->coded)) {
                $x = $rc->decodeCdfFfmpeg(SilkTables::model_gain_highbits[$active + $voiced]);
                $logGain = ($x << 3) | $rc->decodeCdfFfmpeg(SilkTables::model_gain_lowbits);
                if ($frame->coded) {
                    $logGain = max($logGain, $frame->logGain - 16);
                }
            } else {
                $deltaGain = $rc->decodeCdfFfmpeg(SilkTables::model_gain_delta);
                $logGain = max(0, min(63, max(($deltaGain << 1) - 16, $frame->logGain + $deltaGain - 4)));
            }
            $frame->logGain = $logGain;

            $lg = (($logGain * 0x1D1C71) >> 16) + 2090;
            $ipart = $lg >> 7;
            $fpart = $lg & 127;
            $lingain = (1 << $ipart)
                + ((((-174 * $fpart * (128 - $fpart)) >> 16) + $fpart) * ((1 << $ipart) >> 7));
            $sf[$i] = [
                'gain' => $lingain / 65536.0,
                'pitchlag' => 0,
                'ltptaps' => [0.0, 0.0, 0.0, 0.0, 0.0],
            ];
        }

        [$lpcLeadin, $lpcBody, $order, $hasLeadin] = $this->decodeLpc($rc, $frame, $voiced);

        if ($voiced) {
            $lagAbsolute = ($frameNum === 0 || !$frame->prevVoiced);
            $primaryLag = 0;
            if (!$lagAbsolute) {
                $delta = $rc->decodeCdfFfmpeg(SilkTables::model_pitch_delta);
                if ($delta) {
                    $primaryLag = $frame->primaryLag + $delta - 9;
                } else {
                    $lagAbsolute = 1;
                }
            }
            if ($lagAbsolute) {
                $highbits = $rc->decodeCdfFfmpeg(SilkTables::model_pitch_highbits);
                $lowModel = [
                    SilkTables::model_lcg_seed,
                    SilkTables::model_pitch_lowbits_mb,
                    SilkTables::model_gain_lowbits,
                ][$this->bandwidth];
                $lowbits = $rc->decodeCdfFfmpeg($lowModel);
                $primaryLag = SilkTables::pitch_min_lag[$this->bandwidth]
                    + $highbits * SilkTables::pitch_scale[$this->bandwidth] + $lowbits;
            }
            $frame->primaryLag = $primaryLag;

            if ($this->subframes === 2) {
                $offsets = ($this->bandwidth === 0)
                    ? SilkTables::pitch_offset_nb10ms[$rc->decodeCdfFfmpeg(SilkTables::model_pitch_contour_nb10ms)]
                    : SilkTables::pitch_offset_mbwb10ms[$rc->decodeCdfFfmpeg(SilkTables::model_pitch_contour_mbwb10ms)];
            } else {
                $offsets = ($this->bandwidth === 0)
                    ? SilkTables::pitch_offset_nb20ms[$rc->decodeCdfFfmpeg(SilkTables::model_pitch_contour_nb20ms)]
                    : SilkTables::pitch_offset_mbwb20ms[$rc->decodeCdfFfmpeg(SilkTables::model_pitch_contour_mbwb20ms)];
            }

            $minLag = SilkTables::pitch_min_lag[$this->bandwidth];
            $maxLag = SilkTables::pitch_max_lag[$this->bandwidth];
            for ($i = 0; $i < $this->subframes; $i++) {
                $sf[$i]['pitchlag'] = max($minLag, min($maxLag, $primaryLag + $offsets[$i]));
            }

            $ltpFilter = $rc->decodeCdfFfmpeg(SilkTables::model_ltp_filter);
            $sel = [
                SilkTables::model_ltp_filter0_sel,
                SilkTables::model_ltp_filter1_sel,
                SilkTables::model_ltp_filter2_sel,
            ][$ltpFilter];
            $tapsTable = [
                SilkTables::ltp_filter0_taps,
                SilkTables::ltp_filter1_taps,
                SilkTables::ltp_filter2_taps,
            ][$ltpFilter];
            for ($i = 0; $i < $this->subframes; $i++) {
                $index = $rc->decodeCdfFfmpeg($sel);
                for ($j = 0; $j < 5; $j++) {
                    $sf[$i]['ltptaps'][$j] = $tapsTable[$index][$j] / 128.0;
                }
            }
        }

        if ($voiced && $frameNum === 0) {
            $ltpscale = SilkTables::ltp_scale_factor[$rc->decodeCdfFfmpeg(SilkTables::model_ltp_scale_index)] / 16384.0;
        } else {
            $ltpscale = 15565.0 / 16384.0;
        }

        $excitation = array_fill(0, $this->flength, 0.0);
        $this->decodeExcitation($rc, $excitation, $qoffsetHigh, $active, $voiced);

        if ($this->outputChannels === $channel || $redundant) {
            return;
        }

        $residual = array_fill(0, self::MAX_LAG + self::HISTORY, 0.0);
        for ($i = 0; $i < $this->flength; $i++) {
            $residual[self::MAX_LAG + $i] = $excitation[$i];
        }

        // 把逐样本循环里频繁访问的对象属性/数组提到局部变量：PHP 对 $frame->prop[idx]
        // 的每次读写都要走属性+哈希查找，合成内层循环每帧调用 order*2 次，是 SILK 最大热点。
        $output = $frame->output;
        $history = $frame->lpcHistory;
        $sflength = $this->sflength;
        $subframes = $this->subframes;
        for ($i = 0; $i < $subframes; $i++) {
            $lpcCoeff = ($i < 2 && $hasLeadin) ? $lpcLeadin : $lpcBody;
            $dstBase = self::HISTORY + $i * $sflength;
            $resBase = self::MAX_LAG + $i * $sflength;
            $gain = $sf[$i]['gain'];

            if ($voiced) {
                if ($i < 2 || $this->nlsfInterpFactor === 4) {
                    $outEnd = -$i * $sflength;
                    $scale = $ltpscale;
                } else {
                    $outEnd = -($i - 2) * $sflength;
                    $scale = 1.0;
                }

                for ($j = -$sf[$i]['pitchlag'] - 2; $j < $outEnd; $j++) {
                    $sum = $output[$dstBase + $j];
                    for ($k = 0; $k < $order; $k++) {
                        $sum -= $lpcCoeff[$k] * $output[$dstBase + $j - $k - 1];
                    }
                    $residual[$resBase + $j] = self::clipf($sum, -1.0, 1.0) * $scale / $gain;
                }

                if ($outEnd) {
                    $rescale = $sf[$i - 1]['gain'] / $gain;
                    for ($j = $outEnd; $j < 0; $j++) {
                        $residual[$resBase + $j] *= $rescale;
                    }
                }

                $pitchlag = $sf[$i]['pitchlag'];
                $ltptaps = $sf[$i]['ltptaps'];
                for ($j = 0; $j < $sflength; $j++) {
                    $sum = $residual[$resBase + $j];
                    for ($k = 0; $k < self::LTP_ORDER; $k++) {
                        $sum += $ltptaps[$k]
                            * $residual[$resBase + $j - $pitchlag + 2 - $k];
                    }
                    $residual[$resBase + $j] = $sum;
                }
            }

            for ($j = 0; $j < $sflength; $j++) {
                $sum = self::f32($residual[$resBase + $j] * $gain);
                $base = $dstBase + $j;
                for ($k = 1; $k <= $order; $k++) {
                    $sum = self::f32($sum + self::f32($lpcCoeff[$k - 1] * $history[$base - $k]));
                }
                // $sum 已是 float32 值，再套 f32 是恒等；clip 边界 ±1.0 也可精确表示。
                $history[$base] = $sum;
                $output[$base] = $sum > 1.0 ? 1.0 : ($sum < -1.0 ? -1.0 : $sum);
            }
        }
        $frame->output = $output;
        $frame->lpcHistory = $history;

        $frame->prevVoiced = $voiced !== 0;

        $frame->output = array_pad(array_slice($output, $this->flength, self::HISTORY), 644, 0.0);
        $frame->lpcHistory = array_pad(array_slice($history, $this->flength, self::HISTORY), 644, 0.0);
        $frame->coded = true;
    }

    private function decodeExcitation(RangeDecoder $rc, array &$excitationf, int $qoffsetHigh, int $active, int $voiced): void
    {
        $shellblocks = SilkTables::shell_blocks[$this->bandwidth][$this->subframes >> 2];
        $seed = $rc->decodeCdfFfmpeg(SilkTables::model_lcg_seed);
        $ratelevel = $rc->decodeCdfFfmpeg(SilkTables::model_exc_rate[$voiced]);

        $pulsecount = array_fill(0, 20, 0);
        $lsbcount = array_fill(0, 20, 0);

        for ($i = 0; $i < $shellblocks; $i++) {
            $pulsecount[$i] = $rc->decodeCdfFfmpeg(SilkTables::model_pulse_count[$ratelevel]);
            if ($pulsecount[$i] === 17) {
                while ($pulsecount[$i] === 17 && ++$lsbcount[$i] !== 10) {
                    $pulsecount[$i] = $rc->decodeCdfFfmpeg(SilkTables::model_pulse_count[9]);
                }
                if ($lsbcount[$i] === 10) {
                    $pulsecount[$i] = $rc->decodeCdfFfmpeg(SilkTables::model_pulse_count[10]);
                }
            }
        }

        $excitation = array_fill(0, $shellblocks * 16, 0);
        // 原版用 countChildren() + 二维 $branch 数组做 4 层二分，每个非零 shellblock
        // 要分配 15 个 2 元素数组、走 15 次方法调用；改成标量展开后调用次数与取值顺序
        // 完全一致（只影响整数分拆，不涉及浮点），每帧省下数百次小数组分配。
        for ($i = 0; $i < $shellblocks; $i++) {
            $total = $pulsecount[$i];
            if ($total === 0) {
                continue;
            }
            $location = $i * 16;
            $offset = (($total - 1 + 5) * ($total - 1)) >> 1;
            $n0 = $rc->decodeCdfFfmpeg(SilkTables::model_pulse_location[0], $offset);
            $n1 = $total - $n0;
            for ($b = 0; $b < 2; $b++) {
                $t1 = $b === 0 ? $n0 : $n1;
                if ($t1 !== 0) {
                    $offset = (($t1 - 1 + 5) * ($t1 - 1)) >> 1;
                    $m0 = $rc->decodeCdfFfmpeg(SilkTables::model_pulse_location[1], $offset);
                    $m1 = $t1 - $m0;
                } else {
                    $m0 = $m1 = 0;
                }
                for ($c = 0; $c < 2; $c++) {
                    $t2 = $c === 0 ? $m0 : $m1;
                    if ($t2 !== 0) {
                        $offset = (($t2 - 1 + 5) * ($t2 - 1)) >> 1;
                        $q0 = $rc->decodeCdfFfmpeg(SilkTables::model_pulse_location[2], $offset);
                        $q1 = $t2 - $q0;
                    } else {
                        $q0 = $q1 = 0;
                    }
                    for ($d = 0; $d < 2; $d++) {
                        $t3 = $d === 0 ? $q0 : $q1;
                        if ($t3 !== 0) {
                            $offset = (($t3 - 1 + 5) * ($t3 - 1)) >> 1;
                            $p0 = $rc->decodeCdfFfmpeg(SilkTables::model_pulse_location[3], $offset);
                            $p1 = $t3 - $p0;
                        } else {
                            $p0 = $p1 = 0;
                        }
                        $excitation[$location++] = $p0;
                        $excitation[$location++] = $p1;
                    }
                }
            }
        }

        for ($i = 0; $i < ($shellblocks << 4); $i++) {
            for ($bit = 0; $bit < $lsbcount[$i >> 4]; $bit++) {
                $excitation[$i] = ($excitation[$i] << 1)
                    | $rc->decodeCdfFfmpeg(SilkTables::model_excitation_lsb);
            }
        }

        $signModel = SilkTables::model_excitation_sign[$active + $voiced][$qoffsetHigh];
        for ($i = 0; $i < ($shellblocks << 4); $i++) {
            $value = $excitation[$i];
            if ($value !== 0) {
                $sign = $rc->decodeCdfFfmpeg($signModel[min($pulsecount[$i >> 4], 6)]);
                if ($sign === 0) {
                    $excitation[$i] = -$value;
                }
            }
        }

        $quantOffset = SilkTables::quant_offset[$voiced][$qoffsetHigh];
        for ($i = 0; $i < ($shellblocks << 4); $i++) {
            $value = $excitation[$i];
            $exc = ($value * 256) | $quantOffset;
            if ($value < 0) {
                $exc += 20;
            } elseif ($value > 0) {
                $exc -= 20;
            }

            $seed = (196314165 * $seed + 907633515) & 0xFFFFFFFF;
            if ($seed & 0x80000000) {
                $exc *= -1;
            }
            $seed = ($seed + $value) & 0xFFFFFFFF;

            $excitationf[$i] = $exc / 8388608.0;
        }
    }

    /**
     * @return array{0: array<int, float>, 1: array<int, float>, 2: int, 3: bool}
     */
    private function decodeLpc(RangeDecoder $rc, SilkFrame $frame, int $voiced): array
    {
        $order = $this->wb ? 16 : 10;
        $lsfI1 = $rc->decodeCdfFfmpeg(SilkTables::model_lsf_s1[$this->wb ? 1 : 0][$voiced]);

        $lsfI2 = array_fill(0, 16, 0);
        for ($i = 0; $i < $order; $i++) {
            $index = $this->wb
                ? SilkTables::lsf_s2_model_sel_wb[$lsfI1][$i]
                : SilkTables::lsf_s2_model_sel_nbmb[$lsfI1][$i];
            $value = $rc->decodeCdfFfmpeg(SilkTables::model_lsf_s2[$index]) - 4;
            if ($value === -4) {
                $value -= $rc->decodeCdfFfmpeg(SilkTables::model_lsf_s2_ext);
            } elseif ($value === 4) {
                $value += $rc->decodeCdfFfmpeg(SilkTables::model_lsf_s2_ext);
            }
            $lsfI2[$i] = $value;
        }

        $lsfRes = array_fill(0, 16, 0);
        for ($i = $order - 1; $i >= 0; $i--) {
            $qstep = $this->wb ? 9830 : 11796;
            $value = $lsfI2[$i] * 1024;
            if ($lsfI2[$i] < 0) {
                $value += 102;
            } elseif ($lsfI2[$i] > 0) {
                $value -= 102;
            }
            $value = ($value * $qstep) >> 16;

            if ($i + 1 < $order) {
                $weight = $this->wb
                    ? SilkTables::lsf_pred_weights_wb[SilkTables::lsf_weight_sel_wb[$lsfI1][$i]][$i]
                    : SilkTables::lsf_pred_weights_nbmb[SilkTables::lsf_weight_sel_nbmb[$lsfI1][$i]][$i];
                $value += ($lsfRes[$i + 1] * $weight) >> 8;
            }
            $lsfRes[$i] = $value;
        }

        $nlsf = array_fill(0, 16, 0);
        for ($i = 0; $i < $order; $i++) {
            $codebook = $this->wb
                ? SilkTables::lsf_codebook_wb[$lsfI1]
                : SilkTables::lsf_codebook_nbmb[$lsfI1];
            $weight = $this->wb
                ? SilkTables::model_lsf_weight_wb[$lsfI1][$i]
                : SilkTables::model_lsf_weight_nbmb[$lsfI1][$i];
            $value = $codebook[$i] * 128 + intdiv($lsfRes[$i] * 16384, $weight);
            $nlsf[$i] = $value < 0 ? 0 : ($value > 32767 ? 32767 : $value);
        }

        $this->stabilizeLsf($nlsf, $order, $this->wb
            ? SilkTables::lsf_min_spacing_wb
            : SilkTables::lsf_min_spacing_nbmb);

        $hasLeadin = false;
        $lpcLeadin = array_fill(0, 16, 0.0);
        if ($this->subframes === 4) {
            $offset = $rc->decodeCdfFfmpeg(SilkTables::model_lsf_interpolation_offset);
            if ($offset !== 4 && $frame->coded) {
                $hasLeadin = true;
                if ($offset !== 0) {
                    $nlsfLeadin = array_fill(0, 16, 0);
                    for ($i = 0; $i < $order; $i++) {
                        $nlsfLeadin[$i] = $frame->nlsf[$i]
                            + (($nlsf[$i] - $frame->nlsf[$i]) * $offset >> 2);
                    }
                    $lpcLeadin = $this->lsf2lpc($nlsfLeadin, $order);
                } else {
                    $lpcLeadin = $frame->lpc;
                }
            } else {
                $offset = 4;
            }
            $this->nlsfInterpFactor = $offset;
            $lpcBody = $this->lsf2lpc($nlsf, $order);
        } else {
            $this->nlsfInterpFactor = 4;
            $lpcBody = $this->lsf2lpc($nlsf, $order);
        }

        for ($i = 0; $i < $order; $i++) {
            $frame->nlsf[$i] = $nlsf[$i];
            $frame->lpc[$i] = $lpcBody[$i];
        }

        return [$lpcLeadin, $lpcBody, $order, $hasLeadin];
    }

    private function stabilizeLsf(array &$nlsf, int $order, array $minDelta): void
    {
        for ($pass = 0; $pass < 20; $pass++) {
            $k = 0;
            $minDiff = 0;
            for ($i = 0; $i < $order + 1; $i++) {
                $low = $i !== 0 ? $nlsf[$i - 1] : 0;
                $high = $i !== $order ? $nlsf[$i] : 32768;
                $diff = ($high - $low) - $minDelta[$i];
                if ($diff < $minDiff) {
                    $minDiff = $diff;
                    $k = $i;
                }
            }
            if ($minDiff === 0) {
                return;
            }

            if ($k === 0) {
                $nlsf[0] = $minDelta[0];
            } elseif ($k === $order) {
                $nlsf[$order - 1] = 32768 - $minDelta[$order];
            } else {
                $minCenter = 0;
                $maxCenter = 32768;
                for ($i = 0; $i < $k; $i++) {
                    $minCenter += $minDelta[$i];
                }
                $minCenter += $minDelta[$k] >> 1;
                for ($i = $order; $i > $k; $i--) {
                    $maxCenter -= $minDelta[$i];
                }
                $maxCenter -= $minDelta[$k] >> 1;

                $centerVal = $nlsf[$k - 1] + $nlsf[$k];
                $centerVal = ($centerVal >> 1) + ($centerVal & 1);
                $centerVal = min($maxCenter, max($minCenter, $centerVal));

                $nlsf[$k - 1] = $centerVal - ($minDelta[$k] >> 1);
                $nlsf[$k] = $nlsf[$k - 1] + $minDelta[$k];
            }
        }

        for ($i = 1; $i < $order; $i++) {
            $value = $nlsf[$i];
            for ($j = $i - 1; $j >= 0 && $nlsf[$j] > $value; $j--) {
                $nlsf[$j + 1] = $nlsf[$j];
            }
            $nlsf[$j + 1] = $value;
        }

        if ($nlsf[0] < $minDelta[0]) {
            $nlsf[0] = $minDelta[0];
        }
        for ($i = 1; $i < $order; $i++) {
            $nlsf[$i] = max($nlsf[$i], min($nlsf[$i - 1] + $minDelta[$i], 32767));
        }

        if ($nlsf[$order - 1] > 32768 - $minDelta[$order]) {
            $nlsf[$order - 1] = 32768 - $minDelta[$order];
        }
        for ($i = $order - 2; $i >= 0; $i--) {
            if ($nlsf[$i] > $nlsf[$i + 1] - $minDelta[$i + 1]) {
                $nlsf[$i] = $nlsf[$i + 1] - $minDelta[$i + 1];
            }
        }
    }

    private function lsp2poly(array $lsp, int $offset, int $halfOrder): array
    {
        $pol = array_fill(0, 9, 0);
        $pol[0] = 65536;
        $pol[1] = -$lsp[$offset];

        for ($i = 1; $i < $halfOrder; $i++) {
            $pol[$i + 1] = $pol[$i - 1] * 2 - self::roundMull($lsp[$offset + 2 * $i], $pol[$i], 16);
            for ($j = $i; $j > 1; $j--) {
                $pol[$j] += $pol[$j - 2] - self::roundMull($lsp[$offset + 2 * $i], $pol[$j - 1], 16);
            }
            $pol[1] -= $lsp[$offset + 2 * $i];
        }
        return $pol;
    }

    private function lsf2lpc(array $nlsf, int $order): array
    {
        $lsp = array_fill(0, 16, 0);
        for ($k = 0; $k < $order; $k++) {
            $index = $nlsf[$k] >> 8;
            $offset = $nlsf[$k] & 255;
            $k2 = $order === 10 ? SilkTables::lsf_ordering_nbmb[$k] : SilkTables::lsf_ordering_wb[$k];

            $value = SilkTables::cosine[$index] * 256;
            $value += (SilkTables::cosine[$index + 1] - SilkTables::cosine[$index]) * $offset;
            $lsp[$k2] = ($value + 4) >> 3;
        }

        $half = $order >> 1;
        $p = $this->lsp2poly($lsp, 0, $half);
        $q = $this->lsp2poly($lsp, 1, $half);

        $lpc32 = array_fill(0, 16, 0);
        for ($k = 0; $k < $half; $k++) {
            $pTemp = $p[$k + 1] + $p[$k];
            $qTemp = $q[$k + 1] - $q[$k];
            $lpc32[$k] = -$qTemp - $pTemp;
            $lpc32[$order - $k - 1] = $qTemp - $pTemp;
        }

        $lpc = array_fill(0, 16, 0);
        $iter = 0;
        for (; $iter < 10; $iter++) {
            $maxabs = 0;
            $k = 0;
            for ($j = 0; $j < $order; $j++) {
                $x = abs($lpc32[$k]);
                if ($x > $maxabs) {
                    $maxabs = $x;
                    $k = $j;
                }
            }

            $maxabs = ($maxabs + 16) >> 5;
            if ($maxabs <= 32767) {
                break;
            }

            $maxabs = min($maxabs, 163838);
            $chirpBase = $chirp = 65470 - intdiv(($maxabs - 32767) << 14, intdiv($maxabs * ($k + 1), 4));
            for ($k = 0; $k < $order; $k++) {
                $lpc32[$k] = self::roundMull($lpc32[$k], $chirp, 16);
                $chirp = ($chirpBase * $chirp + 32768) >> 16;
            }
        }

        if ($iter === 10) {
            for ($k = 0; $k < $order; $k++) {
                $x = ($lpc32[$k] + 16) >> 5;
                $lpc[$k] = max(-32768, min(32767, $x));
                $lpc32[$k] = $lpc[$k] << 5;
            }
        } else {
            for ($k = 0; $k < $order; $k++) {
                $lpc[$k] = ($lpc32[$k] + 16) >> 5;
            }
        }

        for ($i = 1; $i <= 16 && !$this->isLpcStable($lpc, $order); $i++) {
            $chirpBase = $chirp = 65536 - (1 << $i);
            for ($k = 0; $k < $order; $k++) {
                $lpc32[$k] = self::roundMull($lpc32[$k], $chirp, 16);
                $lpc[$k] = ($lpc32[$k] + 16) >> 5;
                $chirp = ($chirpBase * $chirp + 32768) >> 16;
            }
        }

        $out = array_fill(0, 16, 0.0);
        for ($i = 0; $i < $order; $i++) {
            $out[$i] = $lpc[$i] / 4096.0;
        }
        return $out;
    }

    private function isLpcStable(array $lpc, int $order): bool
    {
        $lpc32 = [[], []];
        $rowIndex = 0;
        $row = array_fill(0, 16, 0);
        $dcResponse = 0;
        for ($k = 0; $k < $order; $k++) {
            $dcResponse += $lpc[$k];
            $row[$k] = $lpc[$k] * 4096;
        }
        if ($dcResponse >= 4096) {
            return false;
        }
        $lpc32[0] = $row;
        $lpc32[1] = array_fill(0, 16, 0);
        $totalInverseGain = 1 << 30;

        for ($k = $order - 1; ; $k--) {
            if (abs($row[$k]) > 16773022) {
                return false;
            }

            $rc = -($row[$k] * 128);
            $gainDiv = (1 << 30) - self::mulh($rc, $rc);
            $totalInverseGain = self::mulh($totalInverseGain, $gainDiv) << 2;
            if ($k === 0) {
                return $totalInverseGain >= 107374;
            }

            $fbits = self::ilog($gainDiv);
            $gain = intdiv((1 << 29) - 1, $gainDiv >> ($fbits + 1 - 16));
            $error = (1 << 29) - self::mull($gainDiv << (15 + 16 - $fbits), $gain, 16);
            $gain = ($gain << 16) + (($error * $gain) >> 13);

            $prevRow = $row;
            $rowIndex ^= 1;
            $row = $lpc32[$rowIndex];

            for ($j = 0; $j < $k; $j++) {
                $x = self::satSub32($prevRow[$j], self::roundMull($prevRow[$k - $j - 1], $rc, 31));
                $tmp = self::roundMull($x, $gain, $fbits);
                if ($tmp < self::INT32_MIN || $tmp > self::INT32_MAX) {
                    return false;
                }
                $row[$j] = $tmp;
            }
            $lpc32[$rowIndex] = $row;
        }
    }

    private static function satSub32(int $a, int $b): int
    {
        $value = $a - $b;
        if ($value < self::INT32_MIN) {
            return self::INT32_MIN;
        }
        if ($value > self::INT32_MAX) {
            return self::INT32_MAX;
        }
        return $value;
    }

    private static function roundMull(int $a, int $b, int $shift): int
    {
        $product = $a * $b;
        return (($product >> ($shift - 1)) + 1) >> 1;
    }

    private static function mull(int $a, int $b, int $shift): int
    {
        return ($a * $b) >> $shift;
    }

    private static function mulh(int $a, int $b): int
    {
        return ($a * $b) >> 32;
    }

    private static function ilog(int $value): int
    {
        $bits = 0;
        while ($value > 0) {
            $bits++;
            $value >>= 1;
        }
        return $bits;
    }

    /**
     * 模拟 C 的 float（32 位）舍入：FFmpeg 的 SILK 合成全程使用 float，
     * LPC 反馈在响度大/削波处对精度敏感，必须与 float32 保持一致才能逐样本对齐。
     */
    private static function f32(float $value): float
    {
        // Veltkamp 拆分（s = 53 - 24 = 29）一步得到 24 位尾数，与 float32 舍入等价，
        // 实测 96.2 ns → 5.3 ns（JIT 下 18×）；仅 float32 次正规数不适用，回退 pack/unpack。
        if ($value == 0.0) {
            return $value;
        }
        $t = 536870913.0 * $value;
        $hi = $t - ($t - $value);
        if ($hi < 1.1754943508222875e-38 && $hi > -1.1754943508222875e-38) {
            return unpack('g', pack('g', $value))[1];
        }
        return $hi;
    }

    private static function clipf(float $value, float $min, float $max): float
    {
        if ($value < $min) {
            return $min;
        }
        if ($value > $max) {
            return $max;
        }
        return $value;
    }

    private function unmixMs(array &$left, array &$right, int $offset): void
    {
        $mid = $this->frame[0]->output;
        $side = $this->frame[1]->output;
        $base = self::HISTORY - $this->flength;
        $w0Prev = $this->prevStereoWeights[0];
        $w1Prev = $this->prevStereoWeights[1];
        $w0 = $this->stereoWeights[0];
        $w1 = $this->stereoWeights[1];
        $n1 = SilkTables::stereo_interp_len[$this->bandwidth];

        $i = 0;
        for (; $i < $n1; $i++) {
            $interp0 = $w0Prev + $i * ($w0 - $w0Prev) / $n1;
            $interp1 = $w1Prev + $i * ($w1 - $w1Prev) / $n1;
            $p0 = 0.25 * ($mid[$base + $i - 2] + 2 * $mid[$base + $i - 1] + $mid[$base + $i]);

            $left[$offset + $i] = self::clipf(
                (1 + $interp1) * $mid[$base + $i - 1] + $side[$base + $i - 1] + $interp0 * $p0,
                -1.0,
                1.0
            );
            $right[$offset + $i] = self::clipf(
                (1 - $interp1) * $mid[$base + $i - 1] - $side[$base + $i - 1] - $interp0 * $p0,
                -1.0,
                1.0
            );
        }

        for (; $i < $this->flength; $i++) {
            $p0 = 0.25 * ($mid[$base + $i - 2] + 2 * $mid[$base + $i - 1] + $mid[$base + $i]);
            $left[$offset + $i] = self::clipf(
                (1 + $w1) * $mid[$base + $i - 1] + $side[$base + $i - 1] + $w0 * $p0,
                -1.0,
                1.0
            );
            $right[$offset + $i] = self::clipf(
                (1 - $w1) * $mid[$base + $i - 1] - $side[$base + $i - 1] - $w0 * $p0,
                -1.0,
                1.0
            );
        }

        $this->prevStereoWeights = $this->stereoWeights;
    }
}
