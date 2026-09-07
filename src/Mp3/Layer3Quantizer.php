<?php

namespace Xiaosongshu\Flv2mp4\Mp3;

/**
 * @purpose MPEG-1 Layer III 量化器（global_gain 搜索 + scalefactor 噪声整形 + 区域划分 + Huffman 比特计数）
 * @author yanglong
 * @time 2026年9月6日15:40:00
 */
final class Layer3Quantizer
{
    /**  QuantizePVT.IXMAX_VAL */
    private const IXMAX = 8206;
    /** Takehiro::subdv_table（按 scalefactor band 数索引 region0/region1 计数） */
    private const SUBDV = [
        [0, 0], [0, 0], [0, 0], [0, 0], [0, 0], [0, 1], [1, 1], [1, 1], [1, 2], [2, 2],
        [2, 3], [2, 3], [3, 4], [3, 4], [3, 4], [4, 5], [4, 5], [4, 6], [5, 6], [5, 6],
        [5, 7], [6, 7], [6, 7],
    ];
    /** huf_tbl_noESC：按区域最大值直接给候选表（无 ESC） */
    private const NO_ESC = [1, 2, 5, 7, 7, 10, 10, 13, 13, 13, 13, 13, 13, 13, 13];
    /** PRECALC_SIZE = IXMAX_VAL + 2 */
    private const PRECALC_SIZE = 8208;
    /** 长块可传输 scalefac 的带数（cod_info.sfbmax，sfb21 无 scalefac） */
    private const SFBMAX = 21;
    /** 带内目标相对 SNR（TOL=0.1 即 20dB），噪声整形的核心参数 */
    private const BAND_TOL = 0.1;
    /** 允许噪声下限（相对最强带能量的比值），避免静音带无意义放大 */
    private const NOISE_FLOOR = 1e-8;
    /** 噪声整形最大迭代次数（outer_loop 的精简上限） */
    private const MAX_ITER = 1;
    /** Takehiro::slen1_n / slen2_n：各 compress 档位的 scalefac 容量 */
    private const SLEN1_N = [1, 1, 1, 1, 8, 2, 2, 2, 4, 4, 4, 8, 8, 8, 16, 16];
    private const SLEN2_N = [1, 2, 4, 8, 1, 2, 4, 8, 2, 4, 8, 2, 4, 8, 4, 8];
    /** Takehiro::slen1_tab / slen2_tab：各 compress 档位的 scalefac 位宽 */
    public const SLEN1_TAB = [0, 0, 0, 0, 3, 1, 1, 3, 2, 2, 2, 3, 3, 3, 4, 4];
    public const SLEN2_TAB = [0, 1, 2, 3, 0, 1, 2, 3, 1, 2, 3, 1, 2, 3, 2, 3];
    /** Takehiro::scale_long = 11*slen1 + 10*slen2（长块 part2 比特数） */
    private const SCALE_LONG = [0, 10, 20, 30, 33, 21, 31, 41, 32, 42, 52, 43, 53, 63, 64, 74];
    /** 0..15 的位计数（count1 四元组中非零系数个数 = 符号位数） */
    private const POPCNT = [0, 1, 1, 2, 1, 2, 2, 3, 1, 2, 2, 3, 2, 3, 3, 4];

    private static ?array $adj43 = null;
    private static ?array $pow43 = null;

    /** @var array<int, array<int, array{0: int, 1: int}>> bv_scf 缓存，按采样率分组 */
    private array $bvScfCache = [];
    /** @var array<int, array<int, float>> 采样率 => 长块子带边界 */
    private array $bandsCache = [];
    /** 当前 bands 对应的采样率（bv_scf 缓存键） */
    private int $bandsKey = 0;

    public function __construct(private readonly HuffmanEncoder $huffman = new HuffmanEncoder())
    {
    }

    /**
     * 对一个 MPEG-1 Layer III 长块颗粒进行量化。
     * $spectrum 为滤波器组输出的 576 条谱线；
     * $bitBudget 为该颗粒可用的 main-data 比特预算（part2 + part3）。
     * 返回量化方案：系数（带符号）、global_gain、scalefac、区域划分与各区域 Huffman 表。
     */
    public function quantize(array $spectrum, int $bitBudget = PHP_INT_MAX, int $sampleRate = 44100): array
    {
        if (count($spectrum) !== 576) {
            throw new \InvalidArgumentException('MPEG-1 Layer III long blocks require 576 spectral lines');
        }
        $bands = $this->bands($sampleRate);
        $cutoff = $bands[self::SFBMAX];
        $xrpow = array_fill(0, 576, 0.0);
        $xrpowMax = 0.0;
        foreach ($spectrum as $line => $value) {
            $power = pow(abs((float) $value), 0.75);
            $xrpow[$line] = $line < $cutoff ? $power : 0.0;
            if ($power > $xrpowMax) $xrpowMax = $power;
        }
        if ($xrpowMax === 0.0) {
            return $this->silence();
        }
        $scalefac = array_fill(0, 22, 0);
        $scalefacScale = 0;
        $compress = 0;
        $part2 = 0;
        $maxggain = 255;

        // 阶段一：全带宽、无放大，二分搜索满足预算的最细 global_gain
        $current = $this->searchGain($xrpow, $this->gainFloor($xrpow, $cutoff), $maxggain, $cutoff, $bitBudget, $bands);
        if ($current === null) {
            // 阶段二：逐级低通（从高频子带开始整带置零）后再搜索（极端比特饥饿的兜底）
            for ($band = count($bands) - 2; $band >= 1; --$band) {
                $cut = (int) $bands[$band];
                $found = $this->searchGain($xrpow, $this->gainFloor($xrpow, $cut), $maxggain, $cut, $bitBudget, $bands);
                if ($found !== null) {
                    return $this->buildResult($found[0], $found[1], $found[2], $spectrum, $scalefac, 0, 0, 0);
                }
            }
            return $this->silence();
        }

        // 阶段三：噪声整形
        $l3xmin = $this->allowedNoise($spectrum, $bands, $cutoff);
        $best = null;
        for ($iter = 0; $iter < self::MAX_ITER; ++$iter) {
            [$gain, $ix, $plan] = $current;
            $distort = $this->calcDistort($spectrum, $ix, $scalefac, $scalefacScale, $gain, $bands, $l3xmin);
            $over = 0;
            $excess = 0.0;
            for ($sfb = 0; $sfb < self::SFBMAX; ++$sfb) {
                if ($distort[$sfb] > 1.0) {
                    ++$over;
                    $excess += log(max($distort[$sfb], 1e-20));
                }
            }
            $candidate = [
                'gain' => $gain, 'ix' => $ix, 'plan' => $plan,
                'scalefac' => $scalefac, 'scalefacScale' => $scalefacScale,
                'compress' => $compress, 'part2' => $part2,
                'over' => $over, 'excess' => $excess,
            ];
            if ($best === null || $over < $best['over'] || ($over === $best['over'] && $excess < $best['excess'])) {
                $best = $candidate;
            }
            if ($over === 0) {
                break;
            }
            if (!$this->ampScalefacBands($distort, $scalefac, $xrpow, $bands, $scalefacScale)) {
                break;
            }
            if ($this->allAmplified($scalefac)) {
                break;
            }
            $sc = $this->scaleBitcount($scalefac);
            if ($sc === false) {
                if ($scalefacScale !== 0) {
                    break;
                }
                $scalefac = $this->incScalefacScale($scalefac, $xrpow, $bands);
                $scalefacScale = 1;
                $maxggain = 254;
                $sc = $this->scaleBitcount($scalefac);
                if ($sc === false) {
                    break;
                }
            }
            [$compress, $part2] = $sc;
            $huffBudget = $bitBudget - $part2;
            if ($huffBudget <= 0) {
                break;
            }
            $next = $this->searchGain($xrpow, $this->gainFloor($xrpow, $cutoff), $maxggain, $cutoff, $huffBudget, $bands);
            if ($next === null) {
                break;
            }
            $current = $next;
        }

        return $this->buildResult(
            $best['gain'], $best['ix'], $best['plan'], $spectrum,
            $best['scalefac'], $best['scalefacScale'], $best['compress'], $best['part2']
        );
    }

    /**
     * 在 [lo, hi] 内二分查找比特数不超过预算的最小 global_gain。
     * bits 随 gain 增大而（近似单调）减少；返回 [gain, ix, plan] 或 null。
     */
    private function searchGain(array $xrpow, int $lo, int $hi, int $cutoff, int $bitBudget, array $bands): ?array
    {
        $best = null;
        while ($lo <= $hi) {
            $mid = intdiv($lo + $hi, 2);
            $ix = $this->quantizeGain($xrpow, $mid, $cutoff);
            $plan = $this->analyzeIx($ix, $bands);
            if ($plan['bits'] <= $bitBudget) {
                $best = [$mid, $ix, $plan];
                $hi = $mid - 1;
            } else {
                $lo = $mid + 1;
            }
        }
        return $best;
    }

    /** 保证放大后频谱在 gain=lo 处仍不超过 IXMAX */
    private function gainFloor(array $xrpow, int $cutoff): int
    {
        $max = 0.0;
        for ($line = 0; $line < $cutoff; ++$line) {
            if ($xrpow[$line] > $max) $max = $xrpow[$line];
        }
        if ($max === 0.0) {
            return 210;
        }
        $gain = (int) ceil(210.0 + log($max / self::IXMAX, 2.0) * 16.0 / 3.0);
        return max(0, min(255, $gain));
    }

    /**
     * 比例因子带的允许噪声（l3_xmin）。无心理声学模型时采用带内相对 SNR：
     * l3xmin[sfb] = 带能量 * BAND_TOL^2（即目标带内 SNR），下限取最强带的 NOISE_FLOOR 倍。
     */
    private function allowedNoise(array $spectrum, array $bands, int $cutoff): array
    {
        $tol2 = self::BAND_TOL * self::BAND_TOL;
        $energies = [];
        $maxEnergy = 0.0;
        for ($sfb = 0; $sfb < self::SFBMAX; ++$sfb) {
            $energy = 0.0;
            for ($j = $bands[$sfb]; $j < $bands[$sfb + 1]; ++$j) {
                $v = (float) $spectrum[$j];
                $energy += $v * $v;
            }
            $energies[$sfb] = $energy;
            if ($energy > $maxEnergy) $maxEnergy = $energy;
        }
        $floor = $maxEnergy * self::NOISE_FLOOR;
        $l3xmin = [];
        for ($sfb = 0; $sfb < self::SFBMAX; ++$sfb) {
            $l3xmin[$sfb] = max($energies[$sfb] * $tol2, $floor);
        }
        return $l3xmin;
    }

    /**
     * QuantizePVT::calc_noise：按带计算反量化误差。
     * 每带有效步长 s = global_gain - (scalefac << (scalefac_scale + 1))，
     * 反量化 xq = |ix|^(4/3) * 2^((s-210)/4)，distort = noise / l3xmin。
     */
    private function calcDistort(array $spectrum, array $ix, array $scalefac, int $scalefacScale, int $gain, array $bands, array $l3xmin): array
    {
        $pow43 = self::pow43();
        $distort = [];
        for ($sfb = 0; $sfb < self::SFBMAX; ++$sfb) {
            $s = $gain - ($scalefac[$sfb] << ($scalefacScale + 1));
            $step = pow(2.0, ($s - 210) * 0.25);
            $noise = 0.0;
            for ($j = $bands[$sfb]; $j < $bands[$sfb + 1]; ++$j) {
                $d = abs((float) $spectrum[$j]) - $pow43[$ix[$j]] * $step;
                $noise += $d * $d;
            }
            $distort[$sfb] = $noise / $l3xmin[$sfb];
        }
        return $distort;
    }

    /**
     *  Quantize::amp_scalefac_bands（noise_shaping_amp=1 语义）：
     * 放大所有与最大失真相差 50%（dB）以内的带：scalefac++ 且带内 xrpow *= ifqstep34。
     */
    private function ampScalefacBands(array $distort, array &$scalefac, array &$xrpow, array $bands, int $scalefacScale): bool
    {
        $trigger = 0.0;
        for ($sfb = 0; $sfb < self::SFBMAX; ++$sfb) {
            if ($distort[$sfb] > $trigger) $trigger = $distort[$sfb];
        }
        if ($trigger <= 0.0) {
            return false;
        }
        $trigger = $trigger > 1.0 ? sqrt($trigger) : $trigger * 0.95;
        $ifqstep34 = $scalefacScale === 0 ? 1.29683955465100964055 : 1.68179283050742922612;
        $amplified = false;
        for ($sfb = 0; $sfb < self::SFBMAX; ++$sfb) {
            if ($distort[$sfb] < $trigger) {
                continue;
            }
            ++$scalefac[$sfb];
            $amplified = true;
            for ($j = $bands[$sfb]; $j < $bands[$sfb + 1]; ++$j) {
                $xrpow[$j] *= $ifqstep34;
            }
        }
        return $amplified;
    }

    /** Quantize::loop_break：所有带都已放大过（无剩余选择空间）时返回 true。 */
    private function allAmplified(array $scalefac): bool
    {
        for ($sfb = 0; $sfb < self::SFBMAX; ++$sfb) {
            if ($scalefac[$sfb] === 0) {
                return false;
            }
        }
        return true;
    }

    /**
     * Takehiro::scale_bitcount：遍历全部 16 档 scalefac_compress，
     * 选 part2 比特数最小且能覆盖 max(slen1 区域)/max(slen2 区域) 的档位。
     * 返回 [scalefac_compress, part2_bits] 或 false（溢出）。
     */
    private function scaleBitcount(array $scalefac): array|false
    {
        $max1 = 0;
        for ($sfb = 0; $sfb < 11; ++$sfb) {
            if ($scalefac[$sfb] > $max1) $max1 = $scalefac[$sfb];
        }
        $max2 = 0;
        for ($sfb = 11; $sfb < self::SFBMAX; ++$sfb) {
            if ($scalefac[$sfb] > $max2) $max2 = $scalefac[$sfb];
        }
        $best = false;
        for ($k = 0; $k < 16; ++$k) {
            if ($max1 < self::SLEN1_N[$k] && $max2 < self::SLEN2_N[$k]
                && ($best === false || self::SCALE_LONG[$k] < self::SCALE_LONG[$best[0]])) {
                $best = [$k, self::SCALE_LONG[$k]];
            }
        }
        return $best;
    }

    /**
     *  Quantize::inc_scalefac_scale：切换 scalefac_scale=1，
     * 奇数 scalefac 先 +1 并放大 xrpow，再整体折半，等效步长翻倍。
     */
    private function incScalefacScale(array $scalefac, array &$xrpow, array $bands): array
    {
        $ifqstep34 = 1.29683955465100964055;
        $result = array_fill(0, 22, 0);
        for ($sfb = 0; $sfb < self::SFBMAX; ++$sfb) {
            $s = $scalefac[$sfb];
            if (($s & 1) !== 0) {
                ++$s;
                for ($j = $bands[$sfb]; $j < $bands[$sfb + 1]; ++$j) {
                    $xrpow[$j] *= $ifqstep34;
                }
            }
            $result[$sfb] = $s >> 1;
        }
        return $result;
    }

    /**
     * QuantizePVT::quantize_xrpow + quantize_lines_xrpow（floor + adj43 变体）。
     * istep = IPOW20(gain) = 2^(-(gain-210)*3/16)；$cutoff 以上的谱线强制为 0。
     * 返回 576 个量化幅度（非负，符号另行附加）。
     */
    private function quantizeGain(array $xrpow, int $gain, int $cutoff): array
    {
        $istep = pow(2.0, (210 - $gain) * 0.1875);
        $adj = self::adj43();
        $ix = array_fill(0, 576, 0);
        for ($line = 0; $line < $cutoff; ++$line) {
            $x = $xrpow[$line] * $istep;
            if ($x <= 0.0) {
                continue;
            }
            $rx = (int) $x;
            if ($rx > self::IXMAX) {
                $rx = self::IXMAX;
            }
            $ix[$line] = (int) floor($x + $adj[$rx]);
        }
        return $ix;
    }

    /**
     * Takehiro::noquant_count_bits：推导 big_values / count1 边界、
     * count1 表选择与 region0/1 划分，并统计 part3 比特数。
     */
    private function analyzeIx(array $ix, array $bands): array
    {
        $lastNonzero = -1;
        for ($i = 575; $i >= 0; --$i) {
            if ($ix[$i] !== 0) {
                $lastNonzero = $i;
                break;
            }
        }
        $i = min(576, (($lastNonzero + 2) >> 1) << 1);
        for (; $i > 1; $i -= 2) {
            if (($ix[$i - 1] | $ix[$i - 2]) !== 0) {
                break;
            }
        }
        $count1End = $i;
        $t32 = HuffmanTables::TABLES[32]['lengths'];
        $t33 = HuffmanTables::TABLES[33]['lengths'];
        $a1 = 0;
        $a2 = 0;
        for (; $i > 3; $i -= 4) {
            if (($ix[$i - 1] | $ix[$i - 2] | $ix[$i - 3] | $ix[$i - 4]) > 1) {
                break;
            }
            $p = (($ix[$i - 4] * 2 + $ix[$i - 3]) * 2 + $ix[$i - 2]) * 2 + $ix[$i - 1];
            // 本包表数据的 lengths 已剥离符号位，计数时补上四元组非零系数的符号位
            $signs = self::POPCNT[$p];
            $a1 += $t32[$p] + $signs;
            $a2 += $t33[$p] + $signs;
        }
        $count1Bits = $a1;
        $count1Select = 0;
        if ($a1 > $a2) {
            $count1Bits = $a2;
            $count1Select = 1;
        }
        $bigValues = $i;

        $bits = $count1Bits;
        $tables = [0, 0, 0];
        $r0 = 0;
        $r1 = 0;
        $r0End = 0;
        $r1End = 0;
        if ($bigValues > 0) {
            [$r0, $r1] = $this->bvScfPair($bigValues, $bands);
            $a1 = $bands[$r0 + 1];
            $a2 = $bands[$r0 + $r1 + 2];
            if ($a2 < $bigValues) {
                [$table, $regionBits] = $this->chooseTable($ix, $a2, $bigValues);
                $tables[2] = $table;
                $bits += $regionBits;
            }
            $a1 = min($a1, $bigValues);
            $a2 = min($a2, $bigValues);
            if (0 < $a1) {
                [$table, $regionBits] = $this->chooseTable($ix, 0, $a1);
                $tables[0] = $table;
                $bits += $regionBits;
            }
            if ($a1 < $a2) {
                [$table, $regionBits] = $this->chooseTable($ix, $a1, $a2);
                $tables[1] = $table;
                $bits += $regionBits;
            }
            $r0End = $a1;
            $r1End = $a2;
        }
        return [
            'big_values' => $bigValues,
            'count1_quads' => intdiv($count1End - $bigValues, 4),
            'count1table_select' => $count1Select,
            'region0_count' => $r0,
            'region1_count' => $r1,
            'region0_end' => $r0End,
            'region1_end' => $r1End,
            'tables' => $tables,
            'bits' => $bits,
        ];
    }

    /**
     * Takehiro::choose_table：按区域最大值选择候选表并取比特数最小者。
     * 返回 [table, bits]。
     */
    private function chooseTable(array $ix, int $start, int $end): array
    {
        $max = 0;
        for ($i = $start; $i < $end; ++$i) {
            if ($ix[$i] > $max) {
                $max = $ix[$i];
            }
        }
        if ($max === 0) {
            return [0, 0];
        }
        $candidates = match (true) {
            $max === 1 => [1, 2],
            $max === 2 => [2, 3],
            $max === 3 => [5, 6],
            // 表 14 在 ISO B.7 中未定义（本包数据为空表），max<=15 时以 13/15 替代 LAME 的 {13,14,15}
            $max <= 15 => [self::NO_ESC[$max - 1], 15],
            default => $this->escCandidates($max),
        };
        $best = null;
        foreach ($candidates as $table) {
            $bits = $this->countRegion($ix, $start, $end, $table);
            if ($best === null || $bits < $best[1]) {
                $best = [$table, $bits];
            }
        }
        return $best;
    }

    /** 最大值超过 15 时可用的 ESC 表（容量 15 + 2^linbits - 1 须覆盖 max）。 */
    private function escCandidates(int $max): array
    {
        $candidates = [];
        for ($table = 16; $table <= 31; ++$table) {
            $linbits = HuffmanTables::TABLES[$table]['linbits'];
            if ($linbits > 0 && $max <= 15 + (1 << $linbits) - 1) {
                $candidates[] = $table;
            }
        }
        if ($candidates === []) {
            throw new \LogicException('Spectrum value exceeds the MPEG-1 Layer III Huffman range');
        }
        return $candidates;
    }

    /**
     * 统计用指定表编码幅度对 [start, end) 所需比特数（含 linbits 与符号位）。
     * 与 HuffmanEncoder::writePair 的输出严格一致。
     */
    private function countRegion(array $ix, int $start, int $end, int $table): int
    {
        $h = HuffmanTables::TABLES[$table];
        $width = $table > 15 ? 16 : $h['width'];
        $linbits = $h['linbits'];
        $lengths = $h['lengths'];
        $esc = $linbits > 0;
        $bits = 0;
        for ($i = $start; $i < $end; $i += 2) {
            $ax = $ix[$i];
            $ay = $ix[$i + 1];
            $cx = $esc && $ax >= 15 ? 15 : $ax;
            $cy = $esc && $ay >= 15 ? 15 : $ay;
            $total = $lengths[$cx * $width + $cy] + ($ax !== 0 ? 1 : 0) + ($ay !== 0 ? 1 : 0);
            if ($esc) {
                $total += ($ax >= 15 ? 1 : 0) * $linbits + ($ay >= 15 ? 1 : 0) * $linbits;
            }
            $bits += $total;
        }
        return $bits;
    }

    /**
     * Takehiro::huffman_init 的 bv_scf 计算：按 big_values（值计数，偶数）
     * 返回 [region0_count, region1_count]。
     */
    private function bvScfPair(int $bigValues, array $bands): array
    {
        $cached = $this->bvScfCache[$this->bandsKey] ?? [];
        if (isset($cached[$bigValues])) {
            return $cached[$bigValues];
        }
        $scfbAnz = 0;
        do {
            ++$scfbAnz;
        } while ($bands[$scfbAnz] < $bigValues);
        $r0 = self::SUBDV[$scfbAnz][0];
        while ($bands[$r0 + 1] > $bigValues) {
            --$r0;
        }
        if ($r0 < 0) {
            $r0 = self::SUBDV[$scfbAnz][0];
        }
        $r1 = self::SUBDV[$scfbAnz][1];
        while ($bands[$r1 + $r0 + 2] > $bigValues) {
            --$r1;
        }
        if ($r1 < 0) {
            $r1 = self::SUBDV[$scfbAnz][1];
        }
        $this->bvScfCache[$this->bandsKey][$bigValues] = [$r0, $r1];
        return [$r0, $r1];
    }

    private function buildResult(int $gain, array $ix, array $plan, array $spectrum, array $scalefac, int $scalefacScale, int $compress, int $part2): array
    {
        $coefficients = array_fill(0, 576, 0);
        for ($line = 0; $line < 576; ++$line) {
            $magnitude = $ix[$line];
            if ($magnitude !== 0) {
                $coefficients[$line] = (float) $spectrum[$line] < 0 ? -$magnitude : $magnitude;
            }
        }
        return [
            'coefficients' => $coefficients,
            'global_gain' => $gain,
            'big_values' => intdiv($plan['big_values'], 2),
            'count1_quads' => $plan['count1_quads'],
            'count1table_select' => $plan['count1table_select'],
            'region0_count' => $plan['region0_count'],
            'region1_count' => $plan['region1_count'],
            'region0_end' => $plan['region0_end'],
            'region1_end' => $plan['region1_end'],
            'table_select' => $plan['tables'],
            'part2_3_length' => $plan['bits'],
            'scalefactors' => $scalefac,
            'scalefac_compress' => $compress,
            'preflag' => 0,
            'scalefac_scale' => $scalefacScale,
            'part2_bits' => $part2,
        ];
    }

    private function silence(): array
    {
        return [
            'coefficients' => array_fill(0, 576, 0),
            'global_gain' => 210,
            'big_values' => 0,
            'count1_quads' => 0,
            'count1table_select' => 0,
            'region0_count' => 0,
            'region1_count' => 0,
            'region0_end' => 0,
            'region1_end' => 0,
            'table_select' => [0, 0, 0],
            'part2_3_length' => 0,
            'scalefactors' => array_fill(0, 22, 0),
            'scalefac_compress' => 0,
            'preflag' => 0,
            'scalefac_scale' => 0,
            'part2_bits' => 0,
        ];
    }

    private function bands(int $sampleRate): array
    {
        if (isset($this->bandsCache[$sampleRate])) {
            return $this->bandsCache[$sampleRate];
        }
        if (!in_array($sampleRate, [32000, 44100, 48000], true)) {
            throw new \InvalidArgumentException('MPEG-1 Layer III sample rate must be 32000, 44100 or 48000 Hz');
        }
        $this->bandsKey = $sampleRate;
        return $this->bandsCache[$sampleRate] = Layer3ScalefactorBands::long($sampleRate);
    }

    /** QuantizePVT::iteration_init 的 adj43 表（量化取整修正）。 */
    private static function adj43(): array
    {
        if (self::$adj43 !== null) {
            return self::$adj43;
        }
        $size = self::PRECALC_SIZE;
        $pow43 = [0.0];
        for ($i = 1; $i < $size; ++$i) {
            $pow43[$i] = pow((float) $i, 4.0 / 3.0);
        }
        $adj = [];
        for ($i = 0; $i < $size - 1; ++$i) {
            $adj[$i] = ((float) ($i + 1)) - pow(0.5 * ($pow43[$i] + $pow43[$i + 1]), 0.75);
        }
        $adj[$size - 1] = 0.5;
        return self::$adj43 = $adj;
    }

    /** ix^(4/3) 反量化表（下标 0..IXMAX）。 */
    private static function pow43(): array
    {
        if (self::$pow43 !== null) {
            return self::$pow43;
        }
        $pow43 = [0.0];
        for ($i = 1; $i <= self::IXMAX; ++$i) {
            $pow43[$i] = pow((float) $i, 4.0 / 3.0);
        }
        return self::$pow43 = $pow43;
    }
}
