<?php

namespace Xiaosongshu\Flv2mp4\Mp3;

use InvalidArgumentException;

/**
 * @purpose MPEG-1 Layer III 分析滤波器组（polyphase + MDCT）。
 * @author yanglong
 * @time 2026年9月3日16:36:34
 */
final class Mp3AnalysisFilterbank
{
    public const INPUT_SAMPLES = 1152;
    public const GRANULE_SAMPLES = 576;
    public const SUBBANDS = 32;

    /** gfc.mf_size 起始为 ENCDELAY(576)-MDCTDELAY(48)。 */
    private const MF_WRITE = 528;
    /** 滚动缓冲区大小：528 历史上限 + 1152 当前帧 + 余量（读取最大索引 1630）。 */
    private const MF_SIZE = 1728;

    /** @var array<int, array<int, float>> 滚动输入缓冲 [channel][MF_SIZE] */
    private array $mfbuf;
    /** @var array<int, array<int, array<int, array<int, float>>>> 子带样本 [channel][slot][row][band] */
    private array $sbSample;

    public function __construct(private readonly int $channels = 2)
    {
        if ($channels !== 1 && $channels !== 2) {
            throw new InvalidArgumentException('Channel count must be 1 or 2');
        }
        $this->reset();
    }

    /**
     * Analyze exactly one MPEG-1 audio frame. Each channel contains 1152 PCM
     * samples in chronological order (raw int16 scale).
     * Returns [channel][granule][576] spectral lines.
     *
     * @return array<int, array<int, array<int, float>>>
     */
    public function analyze(array $pcm): array
    {
        if (count($pcm) !== $this->channels) {
            throw new InvalidArgumentException('One PCM array is required per channel');
        }
        $result = [];
        foreach ($pcm as $channel => $samples) {
            if (!is_int($channel) || $channel < 0 || $channel >= $this->channels) {
                throw new InvalidArgumentException('PCM channel index out of range');
            }
            if (count($samples) !== self::INPUT_SAMPLES) {
                throw new InvalidArgumentException('Each channel must contain exactly 1152 PCM samples');
            }
            $buf = &$this->mfbuf[$channel];
            for ($i = 0; $i < self::INPUT_SAMPLES; ++$i) {
                $value = $samples[$i];
                if (!is_int($value) && !is_float($value)) {
                    throw new InvalidArgumentException('PCM samples must be numeric');
                }
                $buf[self::MF_WRITE + $i] = (float) $value;
            }
            unset($buf);
            $result[$channel] = $this->mdctSub48($channel);
        }
        // 帧后滚动（ mfbuf[i] = mfbuf[i + framesize]）：
        // 保留当前帧最后 528 个样本 [1152, 1680) 作为下一帧的多相历史。
        foreach ($this->mfbuf as &$buf) {
            for ($i = 0; $i < self::MF_WRITE; ++$i) {
                $buf[$i] = $buf[$i + self::INPUT_SAMPLES];
            }
        }
        unset($buf);
        return $result;
    }

    public function reset(): void
    {
        $this->mfbuf = [];
        $this->sbSample = [];
        for ($channel = 0; $channel < $this->channels; ++$channel) {
            $this->mfbuf[$channel] = array_fill(0, self::MF_SIZE, 0.0);
            $this->sbSample[$channel] = [];
            for ($slot = 0; $slot < 2; ++$slot) {
                $this->sbSample[$channel][$slot] = [];
                for ($row = 0; $row < 18; ++$row) {
                    $this->sbSample[$channel][$slot][$row] = array_fill(0, self::SUBBANDS, 0.0);
                }
            }
        }
    }

    /**
     * mdct_sub48：单通道一帧（两个颗粒）的多相滤波 + MDCT。
     *
     * @return array<int, array<int, float>> [granule][576]
     */
    private function mdctSub48(int $channel): array
    {
        $xr = [
            array_fill(0, self::GRANULE_SAMPLES, 0.0),
            array_fill(0, self::GRANULE_SAMPLES, 0.0),
        ];
        $enwindow = AnalysisTables::ENWINDOW;
        $win = AnalysisTables::WIN_0;
        $tan = AnalysisTables::WIN_2;
        $order = AnalysisTables::ORDER;
        $sqrt2 = AnalysisTables::SQRT2;

        // wkPos 在通道内跨颗粒连续推进（gr0 起点 286，gr1 起点 862）。
        $wkPos = 286;
        for ($gr = 0; $gr < 2; ++$gr) {
            $samp = &$this->sbSample[$channel][1 - $gr];
            $buf = &$this->mfbuf[$channel];
            $sampPos = 0;
            for ($k = 0; $k < 18 / 2; ++$k) {
                $this->windowSubband($buf, $wkPos, $samp[$sampPos], $enwindow, $sqrt2);
                $this->windowSubband($buf, $wkPos + 32, $samp[$sampPos + 1], $enwindow, $sqrt2);
                $sampPos += 2;
                $wkPos += 64;
                /*
                 * Compensate for inversion in the analysis filter
                 */
                for ($band = 1; $band < self::SUBBANDS; $band += 2) {
                    $samp[$sampPos - 1][$band] *= -1;
                }
            }

            /*
             * Perform imdct of 18 previous subband samples + 18 current
             */
            $band0 = &$this->sbSample[$channel][$gr];
            $band1 = &$this->sbSample[$channel][1 - $gr];
            for ($band = 0; $band < self::SUBBANDS; ++$band) {
                $mdctEncPos = $band * 18;
                $ob = $order[$band];
                $work = array_fill(0, 18, 0.0);
                for ($k = -9; $k < 0; ++$k) {
                    $a = $win[$k + 27] * $band1[$k + 9][$ob]
                        + $win[$k + 36] * $band1[8 - $k][$ob];
                    $b = $win[$k + 9] * $band0[$k + 9][$ob]
                        - $win[$k + 18] * $band0[8 - $k][$ob];
                    $t = $tan[3 + $k + 9];
                    $work[$k + 9] = $a - $b * $t;
                    $work[$k + 18] = $a * $t + $b;
                }
                $this->mdctLong($xr[$gr], $mdctEncPos, $work, $tan);

                /*
                 * Perform aliasing reduction butterfly
                 */
                if ($band != 0) {
                    for ($k = 7; $k >= 0; --$k) {
                        $bu = $xr[$gr][$mdctEncPos + $k] * $tan[20 + $k]
                            + $xr[$gr][$mdctEncPos - 1 - $k] * $tan[28 + $k];
                        $bd = $xr[$gr][$mdctEncPos + $k] * $tan[28 + $k]
                            - $xr[$gr][$mdctEncPos - 1 - $k] * $tan[20 + $k];
                        $xr[$gr][$mdctEncPos - 1 - $k] = $bu;
                        $xr[$gr][$mdctEncPos + $k] = $bd;
                    }
                }
            }
            unset($samp, $buf, $band0, $band1);
        }
        return $xr;
    }

    /**
     * window_subband：一次消耗 32 个 PCM 样本，产出 32 个子带值。
     *
     * @param array<int, float> $x1 滚动输入缓冲
     * @param array<int, float> $a 输出子带值（32 个）
     */
    private function windowSubband(array $x1, int $x1Pos, array &$a, array $enwindow, float $sqrt2): void
    {
        $wp = 10;
        $x2 = $x1Pos + 238 - 14 - 286;

        for ($i = -15; $i < 0; ++$i) {
            $w = $enwindow[$wp - 10];
            $s = $x1[$x2 - 224] * $w;
            $t = $x1[$x1Pos + 224] * $w;
            $w = $enwindow[$wp - 9];
            $s += $x1[$x2 - 160] * $w;
            $t += $x1[$x1Pos + 160] * $w;
            $w = $enwindow[$wp - 8];
            $s += $x1[$x2 - 96] * $w;
            $t += $x1[$x1Pos + 96] * $w;
            $w = $enwindow[$wp - 7];
            $s += $x1[$x2 - 32] * $w;
            $t += $x1[$x1Pos + 32] * $w;
            $w = $enwindow[$wp - 6];
            $s += $x1[$x2 + 32] * $w;
            $t += $x1[$x1Pos - 32] * $w;
            $w = $enwindow[$wp - 5];
            $s += $x1[$x2 + 96] * $w;
            $t += $x1[$x1Pos - 96] * $w;
            $w = $enwindow[$wp - 4];
            $s += $x1[$x2 + 160] * $w;
            $t += $x1[$x1Pos - 160] * $w;
            $w = $enwindow[$wp - 3];
            $s += $x1[$x2 + 224] * $w;
            $t += $x1[$x1Pos - 224] * $w;

            $w = $enwindow[$wp - 2];
            $s += $x1[$x1Pos - 256] * $w;
            $t -= $x1[$x2 + 256] * $w;
            $w = $enwindow[$wp - 1];
            $s += $x1[$x1Pos - 192] * $w;
            $t -= $x1[$x2 + 192] * $w;
            $w = $enwindow[$wp + 0];
            $s += $x1[$x1Pos - 128] * $w;
            $t -= $x1[$x2 + 128] * $w;
            $w = $enwindow[$wp + 1];
            $s += $x1[$x1Pos - 64] * $w;
            $t -= $x1[$x2 + 64] * $w;
            $w = $enwindow[$wp + 2];
            $s += $x1[$x1Pos + 0] * $w;
            $t -= $x1[$x2 + 0] * $w;
            $w = $enwindow[$wp + 3];
            $s += $x1[$x1Pos + 64] * $w;
            $t -= $x1[$x2 - 64] * $w;
            $w = $enwindow[$wp + 4];
            $s += $x1[$x1Pos + 128] * $w;
            $t -= $x1[$x2 - 128] * $w;
            $w = $enwindow[$wp + 5];
            $s += $x1[$x1Pos + 192] * $w;
            $t -= $x1[$x2 - 192] * $w;

            $s *= $enwindow[$wp + 6];
            $w = $t - $s;
            $a[30 + $i * 2] = $t + $s;
            $a[31 + $i * 2] = $enwindow[$wp + 7] * $w;
            $wp += 18;
            --$x1Pos;
            ++$x2;
        }
        {
            $t = $x1[$x1Pos - 16] * $enwindow[$wp - 10];
            $s = $x1[$x1Pos - 32] * $enwindow[$wp - 2];
            $t += ($x1[$x1Pos - 48] - $x1[$x1Pos + 16]) * $enwindow[$wp - 9];
            $s += $x1[$x1Pos - 96] * $enwindow[$wp - 1];
            $t += ($x1[$x1Pos - 80] + $x1[$x1Pos + 48]) * $enwindow[$wp - 8];
            $s += $x1[$x1Pos - 160] * $enwindow[$wp + 0];
            $t += ($x1[$x1Pos - 112] - $x1[$x1Pos + 80]) * $enwindow[$wp - 7];
            $s += $x1[$x1Pos - 224] * $enwindow[$wp + 1];
            $t += ($x1[$x1Pos - 144] + $x1[$x1Pos + 112]) * $enwindow[$wp - 6];
            $s -= $x1[$x1Pos + 32] * $enwindow[$wp + 2];
            $t += ($x1[$x1Pos - 176] - $x1[$x1Pos + 144]) * $enwindow[$wp - 5];
            $s -= $x1[$x1Pos + 96] * $enwindow[$wp + 3];
            $t += ($x1[$x1Pos - 208] + $x1[$x1Pos + 176]) * $enwindow[$wp - 4];
            $s -= $x1[$x1Pos + 160] * $enwindow[$wp + 4];
            $t += ($x1[$x1Pos - 240] - $x1[$x1Pos + 208]) * $enwindow[$wp - 3];
            $s -= $x1[$x1Pos + 224];

            $u = $s - $t;
            $v = $s + $t;

            $t = $a[14];
            $s = $a[15] - $t;

            $a[31] = $v + $t; /* A0 */
            $a[30] = $u + $s; /* A1 */
            $a[15] = $u - $s; /* A2 */
            $a[14] = $v - $t; /* A3 */
        }
        {
            $xr = $a[28] - $a[0];
            $a[0] += $a[28];
            $a[28] = $xr * $enwindow[$wp - 2 * 18 + 7];
            $xr = $a[29] - $a[1];
            $a[1] += $a[29];
            $a[29] = $xr * $enwindow[$wp - 2 * 18 + 7];

            $xr = $a[26] - $a[2];
            $a[2] += $a[26];
            $a[26] = $xr * $enwindow[$wp - 4 * 18 + 7];
            $xr = $a[27] - $a[3];
            $a[3] += $a[27];
            $a[27] = $xr * $enwindow[$wp - 4 * 18 + 7];

            $xr = $a[24] - $a[4];
            $a[4] += $a[24];
            $a[24] = $xr * $enwindow[$wp - 6 * 18 + 7];
            $xr = $a[25] - $a[5];
            $a[5] += $a[25];
            $a[25] = $xr * $enwindow[$wp - 6 * 18 + 7];

            $xr = $a[22] - $a[6];
            $a[6] += $a[22];
            $a[22] = $xr * $sqrt2;
            $xr = $a[23] - $a[7];
            $a[7] += $a[23];
            $a[23] = $xr * $sqrt2 - $a[7];
            $a[7] -= $a[6];
            $a[22] -= $a[7];
            $a[23] -= $a[22];

            $xr = $a[6];
            $a[6] = $a[31] - $xr;
            $a[31] = $a[31] + $xr;
            $xr = $a[7];
            $a[7] = $a[30] - $xr;
            $a[30] = $a[30] + $xr;
            $xr = $a[22];
            $a[22] = $a[15] - $xr;
            $a[15] = $a[15] + $xr;
            $xr = $a[23];
            $a[23] = $a[14] - $xr;
            $a[14] = $a[14] + $xr;

            $xr = $a[20] - $a[8];
            $a[8] += $a[20];
            $a[20] = $xr * $enwindow[$wp - 10 * 18 + 7];
            $xr = $a[21] - $a[9];
            $a[9] += $a[21];
            $a[21] = $xr * $enwindow[$wp - 10 * 18 + 7];

            $xr = $a[18] - $a[10];
            $a[10] += $a[18];
            $a[18] = $xr * $enwindow[$wp - 12 * 18 + 7];
            $xr = $a[19] - $a[11];
            $a[11] += $a[19];
            $a[19] = $xr * $enwindow[$wp - 12 * 18 + 7];

            $xr = $a[16] - $a[12];
            $a[12] += $a[16];
            $a[16] = $xr * $enwindow[$wp - 14 * 18 + 7];
            $xr = $a[17] - $a[13];
            $a[13] += $a[17];
            $a[17] = $xr * $enwindow[$wp - 14 * 18 + 7];

            $xr = -$a[20] + $a[24];
            $a[20] += $a[24];
            $a[24] = $xr * $enwindow[$wp - 12 * 18 + 7];
            $xr = -$a[21] + $a[25];
            $a[21] += $a[25];
            $a[25] = $xr * $enwindow[$wp - 12 * 18 + 7];

            $xr = $a[4] - $a[8];
            $a[4] += $a[8];
            $a[8] = $xr * $enwindow[$wp - 12 * 18 + 7];
            $xr = $a[5] - $a[9];
            $a[5] += $a[9];
            $a[9] = $xr * $enwindow[$wp - 12 * 18 + 7];

            $xr = $a[0] - $a[12];
            $a[0] += $a[12];
            $a[12] = $xr * $enwindow[$wp - 4 * 18 + 7];
            $xr = $a[1] - $a[13];
            $a[1] += $a[13];
            $a[13] = $xr * $enwindow[$wp - 4 * 18 + 7];
            $xr = $a[16] - $a[28];
            $a[16] += $a[28];
            $a[28] = $xr * $enwindow[$wp - 4 * 18 + 7];
            $xr = -$a[17] + $a[29];
            $a[17] += $a[29];
            $a[29] = $xr * $enwindow[$wp - 4 * 18 + 7];

            $xr = $sqrt2 * ($a[2] - $a[10]);
            $a[2] += $a[10];
            $a[10] = $xr;
            $xr = $sqrt2 * ($a[3] - $a[11]);
            $a[3] += $a[11];
            $a[11] = $xr;
            $xr = $sqrt2 * (-$a[18] + $a[26]);
            $a[18] += $a[26];
            $a[26] = $xr - $a[18];
            $xr = $sqrt2 * (-$a[19] + $a[27]);
            $a[19] += $a[27];
            $a[27] = $xr - $a[19];

            $xr = $a[2];
            $a[19] -= $a[3];
            $a[3] -= $xr;
            $a[2] = $a[31] - $xr;
            $a[31] += $xr;
            $xr = $a[3];
            $a[11] -= $a[19];
            $a[18] -= $xr;
            $a[3] = $a[30] - $xr;
            $a[30] += $xr;
            $xr = $a[18];
            $a[27] -= $a[11];
            $a[19] -= $xr;
            $a[18] = $a[15] - $xr;
            $a[15] += $xr;

            $xr = $a[19];
            $a[10] -= $xr;
            $a[19] = $a[14] - $xr;
            $a[14] += $xr;
            $xr = $a[10];
            $a[11] -= $xr;
            $a[10] = $a[23] - $xr;
            $a[23] += $xr;
            $xr = $a[11];
            $a[26] -= $xr;
            $a[11] = $a[22] - $xr;
            $a[22] += $xr;
            $xr = $a[26];
            $a[27] -= $xr;
            $a[26] = $a[7] - $xr;
            $a[7] += $xr;

            $xr = $a[27];
            $a[27] = $a[6] - $xr;
            $a[6] += $xr;

            $xr = $sqrt2 * ($a[0] - $a[4]);
            $a[0] += $a[4];
            $a[4] = $xr;
            $xr = $sqrt2 * ($a[1] - $a[5]);
            $a[1] += $a[5];
            $a[5] = $xr;
            $xr = $sqrt2 * ($a[16] - $a[20]);
            $a[16] += $a[20];
            $a[20] = $xr;
            $xr = $sqrt2 * ($a[17] - $a[21]);
            $a[17] += $a[21];
            $a[21] = $xr;

            $xr = -$sqrt2 * ($a[8] - $a[12]);
            $a[8] += $a[12];
            $a[12] = $xr - $a[8];
            $xr = -$sqrt2 * ($a[9] - $a[13]);
            $a[9] += $a[13];
            $a[13] = $xr - $a[9];
            $xr = -$sqrt2 * ($a[25] - $a[29]);
            $a[25] += $a[29];
            $a[29] = $xr - $a[25];
            $xr = -$sqrt2 * ($a[24] + $a[28]);
            $a[24] -= $a[28];
            $a[28] = $xr - $a[24];

            $xr = $a[24] - $a[16];
            $a[24] = $xr;
            $xr = $a[20] - $xr;
            $a[20] = $xr;
            $xr = $a[28] - $xr;
            $a[28] = $xr;

            $xr = $a[25] - $a[17];
            $a[25] = $xr;
            $xr = $a[21] - $xr;
            $a[21] = $xr;
            $xr = $a[29] - $xr;
            $a[29] = $xr;

            $xr = $a[17] - $a[1];
            $a[17] = $xr;
            $xr = $a[9] - $xr;
            $a[9] = $xr;
            $xr = $a[25] - $xr;
            $a[25] = $xr;
            $xr = $a[5] - $xr;
            $a[5] = $xr;
            $xr = $a[21] - $xr;
            $a[21] = $xr;
            $xr = $a[13] - $xr;
            $a[13] = $xr;
            $xr = $a[29] - $xr;
            $a[29] = $xr;

            $xr = $a[1] - $a[0];
            $a[1] = $xr;
            $xr = $a[16] - $xr;
            $a[16] = $xr;
            $xr = $a[17] - $xr;
            $a[17] = $xr;
            $xr = $a[8] - $xr;
            $a[8] = $xr;
            $xr = $a[9] - $xr;
            $a[9] = $xr;
            $xr = $a[24] - $xr;
            $a[24] = $xr;
            $xr = $a[25] - $xr;
            $a[25] = $xr;
            $xr = $a[4] - $xr;
            $a[4] = $xr;
            $xr = $a[5] - $xr;
            $a[5] = $xr;
            $xr = $a[20] - $xr;
            $a[20] = $xr;
            $xr = $a[21] - $xr;
            $a[21] = $xr;
            $xr = $a[12] - $xr;
            $a[12] = $xr;
            $xr = $a[13] - $xr;
            $a[13] = $xr;
            $xr = $a[28] - $xr;
            $a[28] = $xr;
            $xr = $a[29] - $xr;
            $a[29] = $xr;

            $xr = $a[0];
            $a[0] += $a[31];
            $a[31] -= $xr;
            $xr = $a[1];
            $a[1] += $a[30];
            $a[30] -= $xr;
            $xr = $a[16];
            $a[16] += $a[15];
            $a[15] -= $xr;
            $xr = $a[17];
            $a[17] += $a[14];
            $a[14] -= $xr;
            $xr = $a[8];
            $a[8] += $a[23];
            $a[23] -= $xr;
            $xr = $a[9];
            $a[9] += $a[22];
            $a[22] -= $xr;
            $xr = $a[24];
            $a[24] += $a[7];
            $a[7] -= $xr;
            $xr = $a[25];
            $a[25] += $a[6];
            $a[6] -= $xr;
            $xr = $a[4];
            $a[4] += $a[27];
            $a[27] -= $xr;
            $xr = $a[5];
            $a[5] += $a[26];
            $a[26] -= $xr;
            $xr = $a[20];
            $a[20] += $a[11];
            $a[11] -= $xr;
            $xr = $a[21];
            $a[21] += $a[10];
            $a[10] -= $xr;
            $xr = $a[12];
            $a[12] += $a[19];
            $a[19] -= $xr;
            $xr = $a[13];
            $a[13] += $a[18];
            $a[18] -= $xr;
            $xr = $a[28];
            $a[28] += $a[3];
            $a[3] -= $xr;
            $xr = $a[29];
            $a[29] += $a[2];
            $a[2] -= $xr;
        }
    }

    /**
     * mdct_long：18 点输入 → 18 线输出（写入 out 的 outPos 起）。
     *
     * @param array<int, float> $out 输出频谱（当前颗粒 576 线）
     * @param array<int, float> $in 工作数组（18 个）
     */
    private function mdctLong(array &$out, int $outPos, array $in, array $cx): void
    {
        {
            $tc1 = $in[17] - $in[9];
            $tc3 = $in[15] - $in[11];
            $tc4 = $in[14] - $in[12];
            $ts5 = $in[0] + $in[8];
            $ts6 = $in[1] + $in[7];
            $ts7 = $in[2] + $in[6];
            $ts8 = $in[3] + $in[5];

            $out[$outPos + 17] = ($ts5 + $ts7 - $ts8) - ($ts6 - $in[4]);
            $st = ($ts5 + $ts7 - $ts8) * $cx[12 + 7] + ($ts6 - $in[4]);
            $ct = ($tc1 - $tc3 - $tc4) * $cx[12 + 6];
            $out[$outPos + 5] = $ct + $st;
            $out[$outPos + 6] = $ct - $st;

            $tc2 = ($in[16] - $in[10]) * $cx[12 + 6];
            $ts6 = $ts6 * $cx[12 + 7] + $in[4];
            $ct = $tc1 * $cx[12 + 0] + $tc2 + $tc3 * $cx[12 + 1] + $tc4 * $cx[12 + 2];
            $st = -$ts5 * $cx[12 + 4] + $ts6 - $ts7 * $cx[12 + 5] + $ts8 * $cx[12 + 3];
            $out[$outPos + 1] = $ct + $st;
            $out[$outPos + 2] = $ct - $st;

            $ct = $tc1 * $cx[12 + 1] - $tc2 - $tc3 * $cx[12 + 2] + $tc4 * $cx[12 + 0];
            $st = -$ts5 * $cx[12 + 5] + $ts6 - $ts7 * $cx[12 + 3] + $ts8 * $cx[12 + 4];
            $out[$outPos + 9] = $ct + $st;
            $out[$outPos + 10] = $ct - $st;

            $ct = $tc1 * $cx[12 + 2] - $tc2 + $tc3 * $cx[12 + 0] - $tc4 * $cx[12 + 1];
            $st = $ts5 * $cx[12 + 3] - $ts6 + $ts7 * $cx[12 + 4] - $ts8 * $cx[12 + 5];
            $out[$outPos + 13] = $ct + $st;
            $out[$outPos + 14] = $ct - $st;
        }
        {
            $ts1 = $in[8] - $in[0];
            $ts3 = $in[6] - $in[2];
            $ts4 = $in[5] - $in[3];
            $tc5 = $in[17] + $in[9];
            $tc6 = $in[16] + $in[10];
            $tc7 = $in[15] + $in[11];
            $tc8 = $in[14] + $in[12];

            $out[$outPos + 0] = ($tc5 + $tc7 + $tc8) + ($tc6 + $in[13]);
            $ct = ($tc5 + $tc7 + $tc8) * $cx[12 + 7] - ($tc6 + $in[13]);
            $st = ($ts1 - $ts3 + $ts4) * $cx[12 + 6];
            $out[$outPos + 11] = $ct + $st;
            $out[$outPos + 12] = $ct - $st;

            $ts2 = ($in[7] - $in[1]) * $cx[12 + 6];
            $tc6 = $in[13] - $tc6 * $cx[12 + 7];
            $ct = $tc5 * $cx[12 + 3] - $tc6 + $tc7 * $cx[12 + 4] + $tc8 * $cx[12 + 5];
            $st = $ts1 * $cx[12 + 2] + $ts2 + $ts3 * $cx[12 + 0] + $ts4 * $cx[12 + 1];
            $out[$outPos + 3] = $ct + $st;
            $out[$outPos + 4] = $ct - $st;

            $ct = -$tc5 * $cx[12 + 5] + $tc6 - $tc7 * $cx[12 + 3] - $tc8 * $cx[12 + 4];
            $st = $ts1 * $cx[12 + 1] + $ts2 - $ts3 * $cx[12 + 2] - $ts4 * $cx[12 + 0];
            $out[$outPos + 7] = $ct + $st;
            $out[$outPos + 8] = $ct - $st;

            $ct = -$tc5 * $cx[12 + 4] + $tc6 - $tc7 * $cx[12 + 5] - $tc8 * $cx[12 + 3];
            $st = $ts1 * $cx[12 + 0] - $ts2 + $ts3 * $cx[12 + 1] - $ts4 * $cx[12 + 2];
            $out[$outPos + 15] = $ct + $st;
            $out[$outPos + 16] = $ct - $st;
        }
    }
}
