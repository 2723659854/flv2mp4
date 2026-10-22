<?php

namespace Xiaosongshu\Flv2mp4\Codec\Encode;

/**
 * @purpose 变换与量化
 * @author yanglong
 */
trait TransformTrait
{
    /**
     * 4x4系数扫描
     * 将raster顺序的16个系数转换为zigzag扫描顺序
     * 用于I16x16 DC系数和I4x4全部系数
     */
    /**
     * 8x8 zigzag 扫描表（解码端 CabacDecodingTrait::$ZZ8 镜像）：k(扫描位)→自然序 j。
     * 放在 TransformTrait 是因为 InterPredTrait（计算端，MotionWorkerHelper 无 CABAC trait）
     * 与 CabacEncodingTrait（写码端）都要用，两者组合进宿主类后 private static 互通。
     */
    private static array $ZZ8_SCAN = [
        0, 1, 8, 16, 9, 2, 3, 10, 17, 24, 32, 25, 18, 11, 4, 5,
        12, 19, 26, 33, 40, 48, 41, 34, 27, 20, 13, 6, 7, 14, 21, 28,
        35, 42, 49, 56, 57, 50, 43, 36, 29, 22, 15, 23, 30, 37, 44, 51,
        58, 59, 52, 45, 38, 31, 39, 46, 53, 60, 61, 54, 47, 55, 62, 63,
    ];

    public function scan4x4DcAc(array $raster): array
    {
        $out = array_fill(0, 16, 0);
        for ($i = 0; $i < 16; $i++) {
            $out[$i] = $raster[self::ZIGZAG_SCAN_4X4[$i]];
        }
        return $out;
    }

    /**
     * 4x4 AC系数扫描
     * 跳过DC(位置0),将raster顺序的AC系数(位置1-15)转换为zigzag扫描顺序
     * 输出15个AC系数,用于I16x16 AC和chroma AC
     */
    public function scan4x4Ac(array $raster): array
    {
        $out = array_fill(0, 15, 0);
        for ($i = 0; $i < 15; $i++) {
            $out[$i] = $raster[self::ZIGZAG_SCAN_4X4[$i + 1]];
        }
        return $out;
    }

    public function dct(array $block): array
    {
        $pDct = array_fill(0, 16, 0);
        for ($y = 0; $y < 4; $y++) for ($x = 0; $x < 4; $x++) $pDct[$y * 4 + $x] = $block[$y][$x];

        $this->dctFlat($pDct);

        $result = array_fill(0, 4, array_fill(0, 4, 0));
        for ($y = 0; $y < 4; $y++) for ($x = 0; $x < 4; $x++) $result[$y][$x] = $pDct[$y * 4 + $x];
        return $result;
    }

    /**
     * 4x4 正向整数变换（16 元素行优先一维数组，**原地**变换）
     */
    public function dctFlat(array &$pDct): void
    {
        for ($i = 0; $i < 16; $i += 4) {
            $kiI1 = 1 + $i;
            $kiI2 = 2 + $i;
            $kiI3 = 3 + $i;

            $s03 = $pDct[$i] + $pDct[$kiI3];
            $s12 = $pDct[$kiI1] + $pDct[$kiI2];
            $d03 = $pDct[$i] - $pDct[$kiI3];
            $d12 = $pDct[$kiI1] - $pDct[$kiI2];

            $pDct[$i] = $s03 + $s12;
            $pDct[$kiI2] = $s03 - $s12;
            $pDct[$kiI1] = 2 * $d03 + $d12;
            $pDct[$kiI3] = $d03 - 2 * $d12;
        }

        for ($i = 0; $i < 4; $i++) {
            $kiI4 = 4 + $i;
            $kiI8 = 8 + $i;
            $kiI12 = 12 + $i;

            $s03 = $pDct[$i] + $pDct[$kiI12];
            $s12 = $pDct[$kiI4] + $pDct[$kiI8];
            $d03 = $pDct[$i] - $pDct[$kiI12];
            $d12 = $pDct[$kiI4] - $pDct[$kiI8];

            $pDct[$i] = $s03 + $s12;
            $pDct[$kiI8] = $s03 - $s12;
            $pDct[$kiI4] = 2 * $d03 + $d12;
            $pDct[$kiI12] = $d03 - 2 * $d12;
        }
    }

    /**
     * 4x4 Inter AC 量化（一维行优先），**原地**修改并返回非零系数个数
     * 使用 per-frame 预缓存的 mf/ff，省掉每次从 self:: 查表。
     */
    /** @var ?int 上一次量化用的 QP（用于判断是否需要刷新 mf/ff 缓存） */
    private ?int $quantCacheInterQp = null;
    /** @var ?array 预缓存的 [QUANT_MF[$qp], QUANT_INTER_FF[$qp]] */
    private ?array $quantCacheInter = null;
    public function quantizeFlatInter(array &$block): int
    {
        if ($this->quantCacheInterQp !== $this->qp) {
            $this->quantCacheInterQp = $this->qp;
            $this->quantCacheInter = [self::QUANT_MF[$this->qp], self::QUANT_INTER_FF[$this->qp]];
        }
        [$mf, $ff] = $this->quantCacheInter;
        $nz = 0;
        for ($i = 0; $i < 16; $i++) {
            $val = $block[$i];
            if ($val === 0) continue;
            $j = $i & 7;
            $absVal = $val < 0 ? -$val : $val;
            $absQuant = (($ff[$j] + $absVal) * $mf[$j]) >> 16;
            $block[$i] = $val >= 0 ? $absQuant : -$absQuant;
            if ($block[$i] !== 0) $nz++;
        }
        return $nz;
    }

    /**
     * 4x4 IDCT 整数逆变换（一维行优先输入输出）——原地
     * 蝶形顺序与解码器 idct4x4Flat 一致：
     * 先列变换（stride 4）后行变换并 >>6。本地重建必须与解码端 bit-exact，
     * 行列顺序互换会因 >>1 舍入产生 ±1 漂移。
     */
    public function idctFlat(array &$coeffs): void
    {
        $coeffs[0] += 32;

        // 第一遍：列变换
        for ($i = 0; $i < 4; $i++) {
            $z0 = $coeffs[$i + 0] + $coeffs[$i + 8];
            $z1 = $coeffs[$i + 0] - $coeffs[$i + 8];
            $z2 = ($coeffs[$i + 4] >> 1) - $coeffs[$i + 12];
            $z3 = $coeffs[$i + 4] + ($coeffs[$i + 12] >> 1);

            $coeffs[$i + 0] = $z0 + $z3;
            $coeffs[$i + 4] = $z1 + $z2;
            $coeffs[$i + 8] = $z1 - $z2;
            $coeffs[$i + 12] = $z0 - $z3;
        }

        // 第二遍：行变换并 >>6
        for ($i = 0; $i < 4; $i++) {
            $row = $i << 2;
            $z0 = $coeffs[$row + 0] + $coeffs[$row + 2];
            $z1 = $coeffs[$row + 0] - $coeffs[$row + 2];
            $z2 = ($coeffs[$row + 1] >> 1) - $coeffs[$row + 3];
            $z3 = $coeffs[$row + 1] + ($coeffs[$row + 3] >> 1);

            $coeffs[$row + 0] = ($z0 + $z3) >> 6;
            $coeffs[$row + 1] = ($z1 + $z2) >> 6;
            $coeffs[$row + 2] = ($z1 - $z2) >> 6;
            $coeffs[$row + 3] = ($z0 - $z3) >> 6;
        }
    }

    public function hadamardTransformDC(array $b): array
    {
        $d = array_fill(0, 16, 0);
        for ($y = 0; $y < 4; $y++) for ($x = 0; $x < 4; $x++) $d[$y * 4 + $x] = $b[$y][$x];

        $tmp = array_fill(0, 16, 0);
        for ($i = 0; $i < 4; $i++) {
            $s01 = $d[$i * 4 + 0] + $d[$i * 4 + 1];
            $d01 = $d[$i * 4 + 0] - $d[$i * 4 + 1];
            $s23 = $d[$i * 4 + 2] + $d[$i * 4 + 3];
            $d23 = $d[$i * 4 + 2] - $d[$i * 4 + 3];
            $tmp[$i * 4 + 0] = $s01 + $s23;
            $tmp[$i * 4 + 1] = $s01 - $s23;
            $tmp[$i * 4 + 2] = $d01 - $d23;
            $tmp[$i * 4 + 3] = $d01 + $d23;
        }

        $res = array_fill(0, 4, array_fill(0, 4, 0));
        for ($j = 0; $j < 4; $j++) {
            $s01 = $tmp[0 * 4 + $j] + $tmp[1 * 4 + $j];
            $d01 = $tmp[0 * 4 + $j] - $tmp[1 * 4 + $j];
            $s23 = $tmp[2 * 4 + $j] + $tmp[3 * 4 + $j];
            $d23 = $tmp[2 * 4 + $j] - $tmp[3 * 4 + $j];
            $res[0][$j] = (int)(($s01 + $s23 + 1) >> 1);
            $res[1][$j] = (int)(($s01 - $s23 + 1) >> 1);
            $res[2][$j] = (int)(($d01 - $d23 + 1) >> 1);
            $res[3][$j] = (int)(($d01 + $d23 + 1) >> 1);
        }
        return $res;
    }

    /**
     * 2x2 chroma DC Hadamard变换
     * 不做缩放,输出原始Hadamard系数
     */
    public function forwardChromaHadamard2x2(array $c): array
    {
        $a = $c[0];
        $b = $c[1];
        $cc = $c[2];
        $d = $c[3];
        $e = $a - $b;
        $a = $a + $b;
        $b = $cc - $d;
        $cc = $cc + $d;
        return [$a + $cc, $e + $b, $a - $cc, $e - $b];
    }

    /**
     * 4x4 AC系数量化
     * 公式: level = sign(coeff) * abs(((FF[j] + |coeff|) * MF[j]) >> 16)
     * Intra FF索引 = QP + 6, Inter FF索引 = QP
     */
    public function quantize(array $block, int $isChroma, bool $isInter = false): array
    {
        $qp = $this->qp;
        $mf = self::QUANT_MF[$qp];
        $ffIdx = $isInter ? $qp : $qp + 6;
        $ff = self::QUANT_INTER_FF[$ffIdx];
        $out = array_fill(0, 4, array_fill(0, 4, 0));
        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                $i = $y * 4 + $x;
                $j = $i & 7;
                $val = $block[$y][$x];
                $absVal = abs($val);
                $absQuant = (($ff[$j] + $absVal) * $mf[$j]) >> 16;
                $out[$y][$x] = $val >= 0 ? $absQuant : -$absQuant;
            }
        }
        return $out;
    }

    /**
     * 4x4 chroma AC系数量化
     * inter=true（P帧）FF索引=QP；默认 intra（I帧）FF索引=QP+6
     */
    public function quantizeChroma(array $block, int $qp, bool $inter = false): array
    {
        $mf = self::QUANT_MF[$qp];
        $ff = self::QUANT_INTER_FF[$inter ? $qp : $qp + 6];
        $out = array_fill(0, 4, array_fill(0, 4, 0));
        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                $i = $y * 4 + $x;
                $j = $i & 7;
                $val = $block[$y][$x];
                $absVal = abs($val);
                $absQuant = (($ff[$j] + $absVal) * $mf[$j]) >> 16;
                $out[$y][$x] = $val >= 0 ? $absQuant : -$absQuant;
            }
        }
        return $out;
    }

    /**
     * 4x4 luma DC量化
     * DC使用: iFF = pFF[0] << 1, iMF = pMF[0] >> 1
     * 公式: level = sign(coeff) * abs(((iFF + |coeff|) * iMF) >> 16)
     */
    public function quantizeDCMatrix(array $b, int $qp): array
    {
        $mf0 = self::QUANT_MF[$qp][0];
        $ff0 = self::QUANT_INTER_FF[$qp + 6][0];
        $iFF = $ff0 << 1;
        $iMF = $mf0 >> 1;
        $out = array_fill(0, 4, array_fill(0, 4, 0));
        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                $val = $b[$y][$x];
                $absVal = abs($val);
                $absQuant = (($iFF + $absVal) * $iMF) >> 16;
                $out[$y][$x] = $val >= 0 ? $absQuant : -$absQuant;
            }
        }
        return $out;
    }

    /**
     * 2x2 chroma DC量化
     * DC使用: iFF = pFF[0] << 1, iMF = pMF[0] >> 1
     * inter=true（P帧）FF索引=chromaQp；默认 intra 用 chromaQp+6
     */
    public function quantizeChromaDC(array $coeffs, int $chromaQp, bool $inter = false): array
    {
        $mf0 = self::QUANT_MF[$chromaQp][0];
        $ff0 = self::QUANT_INTER_FF[$inter ? $chromaQp : $chromaQp + 6][0];
        $iFF = $ff0 << 1;
        $iMF = $mf0 >> 1;
        $output = [];
        foreach ($coeffs as $val) {
            $absVal = abs($val);
            $absQuant = (($iFF + $absVal) * $iMF) >> 16;
            $output[] = $val >= 0 ? $absQuant : -$absQuant;
        }
        return $output;
    }

    /**
     * 4x4反量化（用于本地解码重建参考帧）——原地
     * 公式: coeff[i] = (coeff[i] * table[qp][pos] + 32) >> 6
     */
    public function dequantize4x4(array &$coeff, int $type, int $qp): void
    {
        $qp = max(0, min(51, $qp));
        $listIdx = $type;
        $table = $this->dequant4Table[$listIdx][$qp];
        for ($i = 0; $i < 16; $i++) {
            $val = $coeff[$i];
            if ($val === 0) continue;
            $coeff[$i] = ($val * $table[$i] + 32) >> 6;
        }
    }

    /**
     * 4x4 IDCT整数逆变换（与解码器一致）
     */
    public function idct4x4(array $in): array
    {
        $coeffs = array_fill(0, 16, 0);
        for ($y = 0; $y < 4; $y++) for ($x = 0; $x < 4; $x++) $coeffs[$y * 4 + $x] = $in[$y][$x];

        $coeffs[0] = $coeffs[0] + 32;

        for ($i = 0; $i < 4; $i++) {
            $row = 4 * $i;
            $z0 = $coeffs[$row] + $coeffs[$row + 2];
            $z1 = $coeffs[$row] - $coeffs[$row + 2];
            $z2 = ($coeffs[$row + 1] >> 1) - $coeffs[$row + 3];
            $z3 = $coeffs[$row + 1] + ($coeffs[$row + 3] >> 1);

            $coeffs[$row] = $z0 + $z3;
            $coeffs[$row + 1] = $z1 + $z2;
            $coeffs[$row + 2] = $z1 - $z2;
            $coeffs[$row + 3] = $z0 - $z3;
        }

        $d = array_fill(0, 16, 0);
        for ($i = 0; $i < 4; $i++) {
            $z0 = $coeffs[$i] + $coeffs[$i + 8];
            $z1 = $coeffs[$i] - $coeffs[$i + 8];
            $z2 = ($coeffs[$i + 4] >> 1) - $coeffs[$i + 12];
            $z3 = $coeffs[$i + 4] + ($coeffs[$i + 12] >> 1);

            $d[$i] = ($z0 + $z3) >> 6;
            $d[$i + 4] = ($z1 + $z2) >> 6;
            $d[$i + 8] = ($z1 - $z2) >> 6;
            $d[$i + 12] = ($z0 - $z3) >> 6;
        }

        $out = array_fill(0, 4, array_fill(0, 4, 0));
        for ($y = 0; $y < 4; $y++) for ($x = 0; $x < 4; $x++) $out[$y][$x] = $d[$y * 4 + $x];
        return $out;
    }

    /**
     * 亮度DC 4x4逆哈达玛+反量化
     * 输入: raster顺序的16个DC系数
     * 输出: raster顺序的16个反量化DC值
     */
    public function lumaDcDequantIdct(array $dc4x4, int $qmul): array
    {
        $temp = array_fill(0, 16, 0);

        for ($i = 0; $i < 4; $i++) {
            $base = 4 * $i;
            $z0 = $dc4x4[$base] + $dc4x4[$base + 1];
            $z1 = $dc4x4[$base] - $dc4x4[$base + 1];
            $z2 = $dc4x4[$base + 2] - $dc4x4[$base + 3];
            $z3 = $dc4x4[$base + 2] + $dc4x4[$base + 3];

            $temp[$base] = $z0 + $z3;
            $temp[$base + 1] = $z0 - $z3;
            $temp[$base + 2] = $z1 - $z2;
            $temp[$base + 3] = $z1 + $z2;
        }

        $out = array_fill(0, 16, 0);
        for ($j = 0; $j < 4; $j++) {
            $z0 = $temp[$j] + $temp[8 + $j];
            $z1 = $temp[$j] - $temp[8 + $j];
            $z2 = $temp[4 + $j] - $temp[12 + $j];
            $z3 = $temp[4 + $j] + $temp[12 + $j];

            // (x*qmul + 128) >> 8，PHP >> 对负数为算术右移(floor)，与 C 一致。
            // 不可用向0截断(-((abs)>>8))，否则本地重建与解码端漂移 ±1。
            $out[0 * 4 + $j] = (($z0 + $z3) * $qmul + 128) >> 8;
            $out[1 * 4 + $j] = (($z1 + $z2) * $qmul + 128) >> 8;
            $out[2 * 4 + $j] = (($z1 - $z2) * $qmul + 128) >> 8;
            $out[3 * 4 + $j] = (($z0 - $z3) * $qmul + 128) >> 8;
        }
        return $out;
    }

    /**
     * 色度DC 2x2逆哈达玛+反量化
     * output[i] = (hadamard_result * qmul) >> 7
     */
    public function chromaDcDequantIdct(array $dc2x2, int $qmul): array
    {
        $a = $dc2x2[0];
        $b = $dc2x2[1];
        $c = $dc2x2[2];
        $d = $dc2x2[3];

        $e = $a - $b;
        $a = $a + $b;
        $b = $c - $d;
        $c = $c + $d;

        $out = array_fill(0, 4, 0);
        $out[0] = (($a + $c) * $qmul) >> 7;
        $out[1] = (($e + $b) * $qmul) >> 7;
        $out[2] = (($a - $c) * $qmul) >> 7;
        $out[3] = (($e - $b) * $qmul) >> 7;
        return $out;
    }

    // ===== High profile 8x8 变换（编码端，与解码端 idct8AddToPlane/buildQmul8 精确配对） =====

    /** dequant8_coeff_init（与解码端 Decode\TransformTrait 逐值一致） */
    private static array $DEQUANT8_COEFF_INIT = [
        [20, 18, 32, 19, 25, 24],
        [22, 19, 35, 21, 28, 26],
        [26, 23, 42, 24, 33, 31],
        [28, 25, 45, 26, 35, 33],
        [32, 28, 51, 30, 40, 38],
        [36, 32, 58, 34, 46, 43],
    ];
    /** dequant8_coeff_init_scan（与解码端一致） */
    private static array $DEQUANT8_COEFF_SCAN = [0, 3, 4, 3, 3, 1, 5, 1, 4, 5, 2, 5, 3, 1, 5, 1];

    /**
     * 1D 逆蝶形矩阵的定点近似 M = round(2^20 * A^-1)。
     * A 为解码端蝶形（idct8 的 1D 变换）的精确有理矩阵；编码端 forward8x8
     * 用 M 做两遍矩阵乘即得与解码端系数域一致的正向变换（均匀增益、无串扰）。
     * 由自测脚本从解码蝶形单位向量探测求逆生成。
     */
    private static array $DCT8_INV_M = [
        [131072, 131072, 131072, 131072, 131072, 131072, 131072, 131072],
        [174158, 145132, 87079, 43539, -43539, -87079, -145132, -174158],
        [209715, 104858, -104858, -209715, -209715, -104858, 104858, 209715],
        [145132, -43539, -174158, -87079, 87079, 174158, 43539, -145132],
        [131072, -131072, -131072, 131072, 131072, -131072, -131072, 131072],
        [87079, -174158, 43539, 145132, -145132, -43539, 174158, -87079],
        [104858, -209715, 209715, -104858, -104858, 209715, -209715, 104858],
        [43539, -87079, 145132, -174158, 174158, -145132, 87079, -43539],
    ];

    /**
     * 构造 8x8 量化/反量化乘数表（64 项，存储/转置位置索引，flat scaling=16）。
     * qmul 与解码端 buildQmul8 完全一致：dequant 公式 (lv*qmul+32)>>6。
     * mf = ceil(2^22/qmul)：保证 |c|≥1 量化的 lv 再反量化后幅值 ≥ 阈值，仍量回 ≥1
     * （round 版在 qp51 有 mf*qmul < 2^22，lv=1 会量化回 0，破坏往返单调性）。
     * @return array{0: array, 1: array} [qmul8, mf8]
     */
    public function buildQuant8Tables(int $qp, bool $intraLuma): array
    {
        $qp = max(0, min(51, $qp));
        $shift = intdiv($qp, 6);
        $idx = $qp % 6;
        $init = self::$DEQUANT8_COEFF_INIT[$idx];
        $scan = self::$DEQUANT8_COEFF_SCAN;
        $qmul = array_fill(0, 64, 0);
        $mf = array_fill(0, 64, 0);
        for ($j = 0; $j < 64; $j++) {
            // j 为存储位置（转置位置），缩放表索引 x = transpose(j)
            $x = ($j >> 3) | (($j & 7) << 3);
            $scanIdx = (($x >> 1) & 12) | ($x & 3);
            $q = ($init[$scan[$scanIdx]] * 16) << $shift;
            $qmul[$j] = $q;
            $mf[$j] = intdiv(4194304 + $q - 1, $q);
        }
        return [$qmul, $mf];
    }

    /**
     * 8x8 正向变换（64 元素行主序残差 → 64 元素存储/转置布局系数）。
     * 输出系数域与解码端 idct8 输入域一致：c = 64·A^-1·r·A^-T（A 为解码蝶形矩阵）。
     * 两遍矩阵乘（垂直、水平），M ≈ 2^20·A^-1，第二遍直接写转置存储布局。
     * 中间值保持全精度，仅在最后做一次 (x + 2^33) >> 34 就近舍入：
     * 两遍移位会让第一遍的地板截断误差被第二遍乘 M 放大（实测 row0 系统偏差 ≈ -32）。
     * 中间累加幅值上限 ≈ 2^50（res ±255 时 ≈ 7e14），int64 安全。
     */
    public function forward8x8(array $res): array
    {
        $M = self::$DCT8_INV_M;
        // 第一遍：垂直方向 tmp = M·res（全精度，不提前移位）
        $tmp = array_fill(0, 64, 0);
        for ($i = 0; $i < 8; $i++) {
            $m0 = $M[$i][0]; $m1 = $M[$i][1]; $m2 = $M[$i][2]; $m3 = $M[$i][3];
            $m4 = $M[$i][4]; $m5 = $M[$i][5]; $m6 = $M[$i][6]; $m7 = $M[$i][7];
            $base = $i * 8;
            for ($n = 0; $n < 8; $n++) {
                $acc = $m0 * $res[$n]
                     + $m1 * $res[8 + $n]
                     + $m2 * $res[16 + $n]
                     + $m3 * $res[24 + $n]
                     + $m4 * $res[32 + $n]
                     + $m5 * $res[40 + $n]
                     + $m6 * $res[48 + $n]
                     + $m7 * $res[56 + $n];
                $tmp[$base + $n] = $acc;
            }
        }
        // 第二遍：水平方向 c_nat[x][y] = (Σ_n M[y][n]·tmp[x][n] + 2^33) >> 34（2^40·A^-1·R·A^-T → 2^6），
        // 直接写转置存储布局 stored[y*8+x]
        $stored = array_fill(0, 64, 0);
        for ($x = 0; $x < 8; $x++) {
            $base = $x * 8;
            $t0 = $tmp[$base]; $t1 = $tmp[$base + 1]; $t2 = $tmp[$base + 2]; $t3 = $tmp[$base + 3];
            $t4 = $tmp[$base + 4]; $t5 = $tmp[$base + 5]; $t6 = $tmp[$base + 6]; $t7 = $tmp[$base + 7];
            for ($y = 0; $y < 8; $y++) {
                $my = $M[$y];
                $stored[$y * 8 + $x] = ($my[0] * $t0 + $my[1] * $t1 + $my[2] * $t2 + $my[3] * $t3
                                     + $my[4] * $t4 + $my[5] * $t5 + $my[6] * $t6 + $my[7] * $t7
                                     + 8589934592) >> 34;
            }
        }
        return $stored;
    }

    /**
     * 8x8 死区量化（原地修改，返回非零系数个数）。
     * level = sign(c)·((ff + |c|)·mf[j]) >> 16，与 dequantize8 的 (lv·qmul+32)>>6 互逆
     * （mf·qmul ≈ 2^22）。ff: 帧内 2 / 帧间 1（帧内死区略大，对应 4x4 的 qp+6 索引约定）。
     */
    public function quantize8(array &$c, array $mf8, int $ff): int
    {
        $nz = 0;
        for ($j = 0; $j < 64; $j++) {
            $v = $c[$j];
            if ($v === 0) continue;
            $abs = $v < 0 ? -$v : $v;
            $q = (($ff + $abs) * $mf8[$j]) >> 16;
            $c[$j] = $v < 0 ? -$q : $q;
            if ($q !== 0) $nz++;
        }
        return $nz;
    }

    /**
     * 8x8 反量化（原地）：d = (lv·qmul + 32) >> 6，与解码端逐比特一致。
     */
    public function dequantize8(array &$lv, array $qmul8): void
    {
        for ($j = 0; $j < 64; $j++) {
            $v = $lv[$j];
            if ($v === 0) continue;
            $lv[$j] = ($v * $qmul8[$j] + 32) >> 6;
        }
    }

    /**
     * 8x8 整数逆变换（64 元素存储/转置布局系数 → 64 元素行主序像素）。
     * 与解码端 idct8AddToPlane 蝶形逐操作一致（含 +32 直流偏置），用于本地重建。
     */
    public function inverse8x8(array $block): array
    {
        $block[0] += 32;

        // 第一遍：列变换（存储布局的列）
        for ($i = 0; $i < 8; $i++) {
            $a0 = $block[$i] + $block[$i + 32];
            $a2 = $block[$i] - $block[$i + 32];
            $a4 = ($block[$i + 16] >> 1) - $block[$i + 48];
            $a6 = ($block[$i + 48] >> 1) + $block[$i + 16];

            $b0 = $a0 + $a6;
            $b2 = $a2 + $a4;
            $b4 = $a2 - $a4;
            $b6 = $a0 - $a6;

            $s1 = $block[$i + 8]; $s3 = $block[$i + 24]; $s5 = $block[$i + 40]; $s7 = $block[$i + 56];
            $a1 = -$s3 + $s5 - $s7 - ($s7 >> 1);
            $a3 =  $s1 + $s7 - $s3 - ($s3 >> 1);
            $a5 = -$s1 + $s7 + $s5 + ($s5 >> 1);
            $a7 =  $s3 + $s5 + $s1 + ($s1 >> 1);

            $b1 = ($a7 >> 2) + $a1;
            $b3 = $a3 + ($a5 >> 2);
            $b5 = ($a3 >> 2) - $a5;
            $b7 = $a7 - ($a1 >> 2);

            $block[$i]      = $b0 + $b7;
            $block[$i + 56] = $b0 - $b7;
            $block[$i + 8]  = $b2 + $b5;
            $block[$i + 48] = $b2 - $b5;
            $block[$i + 16] = $b4 + $b3;
            $block[$i + 40] = $b4 - $b3;
            $block[$i + 24] = $b6 + $b1;
            $block[$i + 32] = $b6 - $b1;
        }

        // 第二遍：行变换并 >>6，按行主序写出（与解码端竖写列 i、行 k 的映射一致）
        $out = array_fill(0, 64, 0);
        for ($i = 0; $i < 8; $i++) {
            $base = $i * 8;
            $s0 = $block[$base]; $s1 = $block[$base + 1]; $s2 = $block[$base + 2]; $s3 = $block[$base + 3];
            $s4 = $block[$base + 4]; $s5 = $block[$base + 5]; $s6 = $block[$base + 6]; $s7 = $block[$base + 7];

            $a0 = $s0 + $s4;
            $a2 = $s0 - $s4;
            $a4 = ($s2 >> 1) - $s6;
            $a6 = ($s6 >> 1) + $s2;

            $b0 = $a0 + $a6;
            $b2 = $a2 + $a4;
            $b4 = $a2 - $a4;
            $b6 = $a0 - $a6;

            $a1 = -$s3 + $s5 - $s7 - ($s7 >> 1);
            $a3 =  $s1 + $s7 - $s3 - ($s3 >> 1);
            $a5 = -$s1 + $s7 + $s5 + ($s5 >> 1);
            $a7 =  $s3 + $s5 + $s1 + ($s1 >> 1);

            $b1 = ($a7 >> 2) + $a1;
            $b3 = $a3 + ($a5 >> 2);
            $b5 = ($a3 >> 2) - $a5;
            $b7 = $a7 - ($a1 >> 2);

            $out[$i]        = ($b0 + $b7) >> 6;
            $out[8 + $i]    = ($b2 + $b5) >> 6;
            $out[16 + $i]   = ($b4 + $b3) >> 6;
            $out[24 + $i]   = ($b6 + $b1) >> 6;
            $out[32 + $i]   = ($b6 - $b1) >> 6;
            $out[40 + $i]   = ($b4 - $b3) >> 6;
            $out[48 + $i]   = ($b2 - $b5) >> 6;
            $out[56 + $i]   = ($b0 - $b7) >> 6;
        }
        return $out;
    }
}
