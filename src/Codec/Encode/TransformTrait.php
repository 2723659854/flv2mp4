<?php

namespace Xiaosongshu\Flv2mp4\Codec\Encode;

/**
 * @purpose 变换与量化
 * @author yanglong
 * 职责概览（H.264 8.5 变换与量化）：
 * - 扫描：raster 顺序 → zigzag 顺序（4x4 的 16 点与 8x8 的 64 点）。
 * - 正向：4x4 整数变换 dctFlat、亮度 DC 4x4 Hadamard、色度 DC 2x2 Hadamard、High profile 8x8。
 * - 量化：level = sign(c)·((ff + |c|)·MF[j]) >> 16，MF=QUANT_MF，ff 为量化偏移（H.264 8.5.8）。
 * - 逆向：反量化 v = (level·qmul + 2^5) >> 6（H.264 8.5.9）、4x4/8x8 逆变换、
 *   亮度 DC 逆 Hadamard（H.264 8.5.10）、色度 DC 逆 Hadamard（H.264 8.5.11）。
 * - 正向与逆向必须与解码端 bit-exact，移位次序与舍入方式不可互换。
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

    /**
     * 4x4 全系数扫描（DC + AC）：把自然序（raster）的 16 个系数重排为 zigzag 扫描序。
     * @param array $raster 16 个系数，下标 = 自然序 y*4 + x
     * @return array 16 个系数，下标 = 扫描序号 k
     * 对应 H.264 8.5 中的扫描过程；I4x4 全部系数与 I16x16 的 AC 均按此顺序编码。
     */
    public function scan4x4DcAc(array $raster): array
    {
        // 第 k 个扫描位取自然序 ZIGZAG_SCAN_4X4[k]（k→raster 下标），低频在前、高频在后
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
     * @param array $raster 16 个 raster 系数（位置 0 为 DC，本函数跳过）
     * @return array 15 个 AC 系数，下标 0 对应扫描序号 1
     */
    public function scan4x4Ac(array $raster): array
    {
        $out = array_fill(0, 15, 0);
        // 扫描表整体偏移 1：跳过 raster 位置 0 的 DC，只输出 15 个 AC
        for ($i = 0; $i < 15; $i++) {
            $out[$i] = $raster[self::ZIGZAG_SCAN_4X4[$i + 1]];
        }
        return $out;
    }

    /**
     * 4x4 正向整数变换的二维包装：二维残差 → 二维系数。
     * @param array $block 4x4 raster 残差 $block[y][x]
     * @return array 4x4 raster 系数 $result[y][x]
     */
    public function dct(array $block): array
    {
        $pDct = array_fill(0, 16, 0);
        // 按 raster 顺序展平：一维下标 = y*4 + x，与后续扫描/量化的下标约定一致
        for ($y = 0; $y < 4; $y++) for ($x = 0; $x < 4; $x++) $pDct[$y * 4 + $x] = $block[$y][$x];

        $this->dctFlat($pDct);
        // 一维蝶形原地完成后，按同一 raster 顺序回写为二维返回

        $result = array_fill(0, 4, array_fill(0, 4, 0));
        for ($y = 0; $y < 4; $y++) for ($x = 0; $x < 4; $x++) $result[$y][$x] = $pDct[$y * 4 + $x];
        return $result;
    }

    /**
     * 4x4 正向整数变换（16 元素行优先一维数组，**原地**变换）
     * @param array $pDct 16 元素行优先系数，引用传递，返回后内容即为变换结果
     * @return void 无返回值
     * 蝶形（butterfly）结构：先行后列各做一次 4 点一维变换，全程只用加/减与 ×2，
     * 不做乘法；这是 H.264 8.5.12 残差变换的整数近似形式。
     */
    public function dctFlat(array &$pDct): void
    {
        // 第一遍：逐行 4 点一维变换（行内 4 个下标连续）
        for ($i = 0; $i < 16; $i += 4) {
            $kiI1 = 1 + $i;
            $kiI2 = 2 + $i;
            $kiI3 = 3 + $i;

            // 和差分解：s03/s12 为对称分量，d03/d12 为反对称分量
            $s03 = $pDct[$i] + $pDct[$kiI3];
            $s12 = $pDct[$kiI1] + $pDct[$kiI2];
            $d03 = $pDct[$i] - $pDct[$kiI3];
            $d12 = $pDct[$kiI1] - $pDct[$kiI2];

            // 重组输出：[0]/[2] 放低频，[1]/[3] 放高频（乘 2 为蝶形增益）
            $pDct[$i] = $s03 + $s12;
            $pDct[$kiI2] = $s03 - $s12;
            $pDct[$kiI1] = 2 * $d03 + $d12;
            $pDct[$kiI3] = $d03 - 2 * $d12;
        }
        // 第二遍：逐列 4 点一维变换（列内元素下标相差 4）

        for ($i = 0; $i < 4; $i++) {
            $kiI4 = 4 + $i;
            $kiI8 = 8 + $i;
            $kiI12 = 12 + $i;

            // 与行方向同构的和差分解，只是元素跨度由 1 改为 4
            $s03 = $pDct[$i] + $pDct[$kiI12];
            $s12 = $pDct[$kiI4] + $pDct[$kiI8];
            $d03 = $pDct[$i] - $pDct[$kiI12];
            $d12 = $pDct[$kiI4] - $pDct[$kiI8];

            // 同样按低频/高频重组，列方向完成后得到全部 16 个变换系数
            $pDct[$i] = $s03 + $s12;
            $pDct[$kiI8] = $s03 - $s12;
            $pDct[$kiI4] = 2 * $d03 + $d12;
            $pDct[$kiI12] = $d03 - 2 * $d12;
        }
    }

    /**
     * 4x4 Inter AC 量化（一维行优先），**原地**修改并返回非零系数个数
     * 使用 per-frame 预缓存的 mf/ff，省掉每次从 self:: 查表。
     * @param array $block 16 元素行优先 Inter AC 系数（引用，原地替换为 level）
     * @return int 非零 level 个数，供 CBP / coded_block_flag 判定
     * level = sign(c)·((ff[j] + |c|)·MF[j]) >> 16，j = i & 7（MF/ff 表按位置 & 7 组织）。
     */
    /** @var ?int 上一次量化用的 QP（用于判断是否需要刷新 mf/ff 缓存） */
    private ?int $quantCacheInterQp = null;
    /** @var ?array 预缓存的 [QUANT_MF[$qp], QUANT_INTER_FF[$qp]] */
    private ?array $quantCacheInter = null;
    public function quantizeFlatInter(array &$block): int
    {
        // mf/ff 只随 QP 变化：QP 变化时刷新缓存，避免每次量化都查 static 表
        if ($this->quantCacheInterQp !== $this->qp) {
            $this->quantCacheInterQp = $this->qp;
            $this->quantCacheInter = [self::QUANT_MF[$this->qp], self::QUANT_INTER_FF[$this->qp]];
        }
        [$mf, $ff] = $this->quantCacheInter;
        $nz = 0;
        // 逐个量化：0 直接跳过，保持稀疏并省掉乘法
        for ($i = 0; $i < 16; $i++) {
            $val = $block[$i];
            if ($val === 0) continue;
            // 缩放位置折叠到 8 项（QUANT_MF / QUANT_INTER_FF 均为每行 8 项）
            $j = $i & 7;
            $absVal = $val < 0 ? -$val : $val;
            // 加 ff 作为舍入偏移后再 >>16；先取绝对值实现对称量化
            $absQuant = (($ff[$j] + $absVal) * $mf[$j]) >> 16;
            $block[$i] = $val >= 0 ? $absQuant : -$absQuant;
            if ($block[$i] !== 0) $nz++;
        }
        return $nz;
    }

    /**
     * 4x4 IDCT 整数逆变换（一维行优先输入输出）——原地
     * 顺序与解码器 idct4x4Flat / 参考实现 ff_h264_idct_add 一致：
     * 先行变换（含 >>1）再列变换（含 >>1 与 >>6）。本地重建必须与解码端 bit-exact，
     * 顺序互换会因 >>1 的 floor 舍入产生 ±1 漂移。
     * @param array $coeffs 16 元素行优先反量化系数（引用，原地变为残差像素）
     * @return void 无返回值
     * 对应 H.264 8.5 中的逆变换环节；Intra16x16 需先经 8.5.10/8.5.11 处理 DC。
     */
    public function idctFlat(array &$coeffs): void
    {
        $coeffs[0] += 32;
        // 直流偏置 32：为两遍右移（共 6 位）提供就近舍入

        // 第一遍：行变换
        for ($i = 0; $i < 4; $i++) {
            $row = $i << 2;
            $z0 = $coeffs[$row + 0] + $coeffs[$row + 2];
            $z1 = $coeffs[$row + 0] - $coeffs[$row + 2];
            $z2 = ($coeffs[$row + 1] >> 1) - $coeffs[$row + 3];
            $z3 = $coeffs[$row + 1] + ($coeffs[$row + 3] >> 1);
            // z2/z3 里的 >>1 是旋转因子的定点近似（1/2 代替 √2/2），对负数按 floor 取整

            $coeffs[$row + 0] = $z0 + $z3;
            $coeffs[$row + 1] = $z1 + $z2;
            $coeffs[$row + 2] = $z1 - $z2;
            $coeffs[$row + 3] = $z0 - $z3;
        }

        // 第二遍：列变换并 >>6
        for ($i = 0; $i < 4; $i++) {
            $z0 = $coeffs[$i + 0] + $coeffs[$i + 8];
            $z1 = $coeffs[$i + 0] - $coeffs[$i + 8];
            $z2 = ($coeffs[$i + 4] >> 1) - $coeffs[$i + 12];
            $z3 = $coeffs[$i + 4] + ($coeffs[$i + 12] >> 1);

            $coeffs[$i + 0] = ($z0 + $z3) >> 6;
            $coeffs[$i + 4] = ($z1 + $z2) >> 6;
            $coeffs[$i + 8] = ($z1 - $z2) >> 6;
            $coeffs[$i + 12] = ($z0 - $z3) >> 6;
        }
    }

    public function hadamardTransformDC(array $b): array
    /**
     * 亮度 DC 4x4 正向 Hadamard 变换（Intra16x16，H.264 8.5.10 的正向过程）。
     * 把 16 个 4x4 块的 DC 组成 4x4 矩阵做二维哈达玛变换，能量集中到左上角。
     * @param array $b 4x4 raster DC 矩阵 $b[y][x]，每项是一个 4x4 块的 DC
     * @return array 4x4 raster 系数，第二遍含 (x+1)>>1 的 1/2 缩放
     * 与解码端 lumaDcDequantIdct 的逆哈达玛配对，输入输出均为二维 raster 布局。
     */
    {
        $d = array_fill(0, 16, 0);
        // 展平为一维 raster 顺序（y*4+x），便于按行/列做蝶形
        for ($y = 0; $y < 4; $y++) for ($x = 0; $x < 4; $x++) $d[$y * 4 + $x] = $b[$y][$x];

        // 第一遍：逐行 4 点一维 Hadamard（只用加/减，无缩放）
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
        // 第二遍：逐列 4 点一维 Hadamard，并加 1 后 >>1 完成 1/2 缩放与舍入

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
     * @param array $c 4 个 DC（raster：左上、右上、左下、右下）
     * @return array 4 个 Hadamard 系数，顺序与输入一致
     * 对应 H.264 8.5.11 色度 DC 变换的编码端（解码端为 chromaDcDequantIdct）。
     */
    public function forwardChromaHadamard2x2(array $c): array
    {
        $a = $c[0];
        $b = $c[1];
        $cc = $c[2];
        $d = $c[3];
        // 2x2 Hadamard 蝶形：先横向两对和差（a±b、cc±d），再纵向合成
        $e = $a - $b;
        $a = $a + $b;
        $b = $cc - $d;
        $cc = $cc + $d;
        // 输出顺序：左上、右上、左下、右下，均为未缩放的整数系数
        return [$a + $cc, $e + $b, $a - $cc, $e - $b];
    }

    /**
     * 4x4 AC系数量化
     * 公式: level = sign(coeff) * abs(((FF[j] + |coeff|) * MF[j]) >> 16)
     * Intra FF索引 = QP + 6, Inter FF索引 = QP
     * @param array $block 4x4 raster 系数 $block[y][x]
     * @param int $isChroma 色度标记（当前实现不参与运算，QP 由调用方决定）
     * @param bool $isInter true=P 帧/帧间（FF 取 QP），false=I 帧/帧内（FF 取 QP+6）
     * @return array 4x4 raster level
     * MF/ff 与解码端 dequant4Table 互逆，对应 H.264 8.5.8 正量化。
     */
    public function quantize(array $block, int $isChroma, bool $isInter = false): array
    {
        $qp = $this->qp;
        $mf = self::QUANT_MF[$qp];
        // 帧内用 QP+6 的 FF：量化偏移更大，等效死区更宽
        $ffIdx = $isInter ? $qp : $qp + 6;
        $ff = self::QUANT_INTER_FF[$ffIdx];
        $out = array_fill(0, 4, array_fill(0, 4, 0));
        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                $i = $y * 4 + $x;
                // 位置折叠到 8 项（MF/ff 表按 position & 7 组织）
                $j = $i & 7;
                $val = $block[$y][$x];
                $absVal = abs($val);
                // 先加 ff 做舍入偏移再 >>16，abs 形式保证正负对称
                $absQuant = (($ff[$j] + $absVal) * $mf[$j]) >> 16;
                $out[$y][$x] = $val >= 0 ? $absQuant : -$absQuant;
            }
        }
        return $out;
    }

    /**
     * 4x4 chroma AC系数量化
     * inter=true（P帧）FF索引=QP；默认 intra（I帧）FF索引=QP+6
     * @param array $block 4x4 raster 系数 $block[y][x]
     * @param int $qp 色度 QP（由 CHROMA_QP_TABLE 映射得到）
     * @param bool $inter true=P 帧（FF 取 QP），false=I 帧（FF 取 QP+6）
     * @return array 4x4 raster level
     */
    public function quantizeChroma(array $block, int $qp, bool $inter = false): array
    {
        $mf = self::QUANT_MF[$qp];
        $ff = self::QUANT_INTER_FF[$inter ? $qp : $qp + 6];
        $out = array_fill(0, 4, array_fill(0, 4, 0));
        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                $i = $y * 4 + $x;
                // 位置折叠到 8 项，与 QUANT_MF / QUANT_INTER_FF 的行内索引一致
                $j = $i & 7;
                $val = $block[$y][$x];
                $absVal = abs($val);
                // 对称量化：取绝对值后量化，最后补回符号
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
     * @param array $b 4x4 raster 亮度 DC 系数（Hadamard 之后）
     * @param int $qp 亮度 QP（0..51）
     * @return array 4x4 raster level
     * H.264 8.5.10 的亮度 DC 使用单独缩放，与解码端 dequant4Table[0][qp][0] 配对。
     */
    public function quantizeDCMatrix(array $b, int $qp): array
    {
        // DC 专用缩放：iFF 左移 1 位、iMF 右移 1 位（步长加倍，量化变粗）
        $mf0 = self::QUANT_MF[$qp][0];
        $ff0 = self::QUANT_INTER_FF[$qp + 6][0];
        $iFF = $ff0 << 1;
        $iMF = $mf0 >> 1;
        // 只取表中位置 0 的 MF/ff —— DC 恒在扫描首位
        $out = array_fill(0, 4, array_fill(0, 4, 0));
        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                $val = $b[$y][$x];
                $absVal = abs($val);
                // 对称量化：先取绝对值，再乘 DC 专用乘数并 >>16 舍入
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
     * @param array $coeffs 4 个色度 DC 系数（raster 顺序，来自 forwardChromaHadamard2x2）
     * @param int $chromaQp 色度 QP（CHROMA_QP_TABLE 映射后）
     * @param bool $inter true=P 帧（FF 取 chromaQp），false=I 帧（FF 取 chromaQp+6）
     * @return array 4 个 level，顺序与输入一致
     */
    public function quantizeChromaDC(array $coeffs, int $chromaQp, bool $inter = false): array
    {
        $mf0 = self::QUANT_MF[$chromaQp][0];
        $ff0 = self::QUANT_INTER_FF[$inter ? $chromaQp : $chromaQp + 6][0];
        $iFF = $ff0 << 1;
        $iMF = $mf0 >> 1;
        $output = [];
        // 与亮度 DC 同样的做法：iFF 左移 1、iMF 右移 1，实现 DC 步长加倍
        foreach ($coeffs as $val) {
            $absVal = abs($val);
            // 对称量化并 >>16 舍入，与解码端 chromaDcDequantIdct 的 qmul 互逆
            $absQuant = (($iFF + $absVal) * $iMF) >> 16;
            $output[] = $val >= 0 ? $absQuant : -$absQuant;
        }
        return $output;
    }

    /**
     * 4x4反量化（用于本地解码重建参考帧）——原地
     * 公式: coeff[i] = (coeff[i] * table[qp][pos] + 32) >> 6
     * @param array $coeff 16 元素行优先 level（引用，原地变为反量化系数）
     * @param int $type 反量化表序号：0=luma、1=Cb、2=Cr（帧间为 3/4/5）
     * @param int $qp 量化参数，内部钳位到 0..51
     * @return void 无返回值
     * H.264 8.5.9 反量化：v = (level·qmul + 2^5) >> 6，qmul 取自 dequant4Table。
     */
    public function dequantize4x4(array &$coeff, int $type, int $qp): void
    {
        // 表只覆盖 0..51，越界时按边界取值
        $qp = max(0, min(51, $qp));
        $listIdx = $type;
        $table = $this->dequant4Table[$listIdx][$qp];
        // 取该 list/QP 下的 16 项缩放表（按 raster 位置索引）
        for ($i = 0; $i < 16; $i++) {
            $val = $coeff[$i];
            if ($val === 0) continue;
            // level 为 0 时保持稀疏，跳过乘法与舍入
            $coeff[$i] = ($val * $table[$i] + 32) >> 6;
        }
    }

    /**
     * 4x4 IDCT整数逆变换（与解码器一致）
     * @param array $in 4x4 raster 反量化系数 $in[y][x]
     * @return array 4x4 raster 残差像素 $out[y][x]
     * 蝶形与 idctFlat 相同，区别是二维进出、列变换结果先写入临时数组 $d。
     */
    public function idct4x4(array $in): array
    {
        $coeffs = array_fill(0, 16, 0);
        // 二维系数展平为行优先 16 元素
        for ($y = 0; $y < 4; $y++) for ($x = 0; $x < 4; $x++) $coeffs[$y * 4 + $x] = $in[$y][$x];

        $coeffs[0] = $coeffs[0] + 32;
        // 直流偏置 32：为两遍右移提供就近舍入

        // 第一遍：行变换（>>1 近似旋转因子，负数按 floor）
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

        // 第二遍：列变换，输出时 >>6 完成定点缩放
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
        // 至此得到 raster 顺序的 16 个残差像素

        $out = array_fill(0, 4, array_fill(0, 4, 0));
        for ($y = 0; $y < 4; $y++) for ($x = 0; $x < 4; $x++) $out[$y][$x] = $d[$y * 4 + $x];
        return $out;
    }

    /**
     * 亮度DC 4x4逆哈达玛+反量化
     * 输入: raster顺序的16个DC系数
     * 输出: raster顺序的16个反量化DC值
     * @param array $dc4x4 raster 顺序 16 个亮度 DC level
     * @param int $qmul 该 QP 的 DC 反量化乘数（dequant4Table[0][qp][0]）
     * @return array raster 顺序 16 个反量化后的 DC 值
     * 对应 H.264 8.5.10：逆 Hadamard 后乘 qmul，再 (x + 128) >> 8。
     */
    public function lumaDcDequantIdct(array $dc4x4, int $qmul): array
    {
        $temp = array_fill(0, 16, 0);
        // 第一遍：逐行 4 点逆 Hadamard（只用加/减，不含缩放）

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
        // 第二遍：逐列逆 Hadamard 并写出（缩放与舍入见下方说明）

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
     * @param array $dc2x2 4 个色度 DC level（raster 顺序）
     * @param int $qmul 该 chromaQp 的 DC 反量化乘数（dequant4Table[1|2][chromaQp][0]）
     * @return array 4 个反量化后的 DC 值（raster 顺序）
     * 对应 H.264 8.5.11：2x2 逆 Hadamard 后乘 qmul 并 >>7。
     */
    public function chromaDcDequantIdct(array $dc2x2, int $qmul): array
    {
        $a = $dc2x2[0];
        $b = $dc2x2[1];
        $c = $dc2x2[2];
        $d = $dc2x2[3];
        // 2x2 逆 Hadamard：先横向两对和差（a±b、c±d），再纵向合成

        $e = $a - $b;
        $a = $a + $b;
        $b = $c - $d;
        $c = $c + $d;

        // 输出乘 qmul 后 >>7 完成色度 DC 缩放，与解码端逐位一致
        $out = array_fill(0, 4, 0);
        $out[0] = (($a + $c) * $qmul) >> 7;
        $out[1] = (($e + $b) * $qmul) >> 7;
        $out[2] = (($a - $c) * $qmul) >> 7;
        $out[3] = (($e - $b) * $qmul) >> 7;
        return $out;
    }

    // ===== High profile 8x8 变换（编码端，与解码端 idct8AddToPlane/buildQmul8 精确配对） =====
    // 8x8 路径用于 High profile 的 transform_size_8x8_flag=1（H.264 8.5.12 的 8x8 变体）。
    // 系数一律按「转置」布局 stored[y*8+x] 存取，与解码端 idct8AddToPlane 的读入顺序一致。

    /** dequant8_coeff_init（与解码端 Decode\TransformTrait 逐值一致） */
    private static array $DEQUANT8_COEFF_INIT = [
        [20, 18, 32, 19, 25, 24],
        [22, 19, 35, 21, 28, 26],
        [26, 23, 42, 24, 33, 31],
        [28, 25, 45, 26, 35, 33],
        [32, 28, 51, 30, 40, 38],
        [36, 32, 58, 34, 46, 43],
    ];
    // 6 行对应 QP % 6（量化步长每 6 个 QP 翻倍），6 列是 8x8 的归一化缩放档位（flat 矩阵）。
    /** dequant8_coeff_init_scan（与解码端一致） */
    private static array $DEQUANT8_COEFF_SCAN = [0, 3, 4, 3, 3, 1, 5, 1, 4, 5, 2, 5, 3, 1, 5, 1];
    // 16 个存储位置折叠到上表的 6 个缩放档位：先按位置算索引，再查此映射表。

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
     * @param int $qp 量化参数，内部钳位到 0..51
     * @param bool $intraLuma 帧内/帧间标记（当前实现未参与计算，帧内死区由量化 ff 控制）
     */
    public function buildQuant8Tables(int $qp, bool $intraLuma): array
    {
        $qp = max(0, min(51, $qp));
        // QP 分解：idx=QP%6 选归一化行，shift=QP/6 为左移位数
        $shift = intdiv($qp, 6);
        $idx = $qp % 6;
        $init = self::$DEQUANT8_COEFF_INIT[$idx];
        $scan = self::$DEQUANT8_COEFF_SCAN;
        // 位置 → 6 个归一化档位的映射表
        $qmul = array_fill(0, 64, 0);
        $mf = array_fill(0, 64, 0);
        for ($j = 0; $j < 64; $j++) {
            // j 为存储位置（转置位置），缩放表索引 x = transpose(j)
            $x = ($j >> 3) | (($j & 7) << 3);
            // 由 x 的行列低位合成 0..15 的档位索引，再查 DEQUANT8_COEFF_SCAN
            $scanIdx = (($x >> 1) & 12) | ($x & 3);
            // flat 缩放：归一化因子 ×16 再按 QP 周期左移，得到反量化乘数 qmul
            $q = ($init[$scan[$scanIdx]] * 16) << $shift;
            $qmul[$j] = $q;
            // mf = ceil(2^22 / qmul)：向上取整保证量化/反量化往返单调（lv=1 不丢失）
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
     * @param array $res 64 元素行主序残差 $res[y*8+x]
     * @return array 64 元素存储（转置）布局系数 stored[y*8+x]
     */
    public function forward8x8(array $res): array
    {
        $M = self::$DCT8_INV_M;
        // 先取出逆蝶形矩阵引用，内层循环直接按行取乘数（每项已定标 2^20）
        // 第一遍：垂直方向 tmp = M·res（全精度，不提前移位）
        $tmp = array_fill(0, 64, 0);
        for ($i = 0; $i < 8; $i++) {
            $m0 = $M[$i][0]; $m1 = $M[$i][1]; $m2 = $M[$i][2]; $m3 = $M[$i][3];
            $m4 = $M[$i][4]; $m5 = $M[$i][5]; $m6 = $M[$i][6]; $m7 = $M[$i][7];
            $base = $i * 8;
            // n 遍历列：acc = Σ_k M[i][k]·res[k*8+n]，保持全精度不提前右移
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
                // 按 row y、col x 写转置布局：stored[y*8+x] 即自然序 (x,y) 的系数
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
     * @param array $c 64 元素存储布局系数（引用，原地变为 level）
     * @param array $mf8 buildQuant8Tables 返回的 64 项乘数
     * @param int $ff 量化偏移：帧内 2、帧间 1
     * @return int 非零 level 个数，供 CBP8x8 / coded_block_flag 判定
     */
    public function quantize8(array &$c, array $mf8, int $ff): int
    {
        $nz = 0;
        // 逐位置量化；0 跳过以保持稀疏
        for ($j = 0; $j < 64; $j++) {
            $v = $c[$j];
            if ($v === 0) continue;
            $abs = $v < 0 ? -$v : $v;
            // 加 ff 作舍入偏移再 >>16，abs 形式保证正负对称
            $q = (($ff + $abs) * $mf8[$j]) >> 16;
            $c[$j] = $v < 0 ? -$q : $q;
            if ($q !== 0) $nz++;
        }
        return $nz;
    }

    /**
     * 8x8 反量化（原地）：d = (lv·qmul + 32) >> 6，与解码端逐比特一致。
     * @param array $lv 64 元素存储布局 level（引用，原地变为反量化系数）
     * @param array $qmul8 buildQuant8Tables 返回的 64 项乘数
     * @return void 无返回值
     */
    public function dequantize8(array &$lv, array $qmul8): void
    {
        for ($j = 0; $j < 64; $j++) {
            $v = $lv[$j];
            if ($v === 0) continue;
            // level 为 0 时保持稀疏，跳过乘法
            $lv[$j] = ($v * $qmul8[$j] + 32) >> 6;
        }
    }

    /**
     * 8x8 整数逆变换（64 元素存储/转置布局系数 → 64 元素行主序像素）。
     * 与解码端 idct8AddToPlane 蝶形逐操作一致（含 +32 直流偏置），用于本地重建。
     * @param array $block 64 元素存储/转置布局的反量化系数
     * @return array 64 元素行主序残差像素 $out[y*8+x]
     * 蝶形为 H.264 8.5.12 的 8x8 逆变换定点形式：只用加减与 1/2、1/4 移位。
     */
    public function inverse8x8(array $block): array
    {
        $block[0] += 32;
        // 直流偏置 32：配合两遍共 6 位的右移实现就近舍入

        // 第一遍：列变换（存储布局的列）
        for ($i = 0; $i < 8; $i++) {
            $a0 = $block[$i] + $block[$i + 32];
            $a2 = $block[$i] - $block[$i + 32];
            $a4 = ($block[$i + 16] >> 1) - $block[$i + 48];
            $a6 = ($block[$i + 48] >> 1) + $block[$i + 16];
            // 偶数项 a0/a2/a4/a6：对称/反对称组合，(x>>1) 近似 cos 项

            $b0 = $a0 + $a6;
            $b2 = $a2 + $a4;
            $b4 = $a2 - $a4;
            $b6 = $a0 - $a6;

            $s1 = $block[$i + 8]; $s3 = $block[$i + 24]; $s5 = $block[$i + 40]; $s7 = $block[$i + 56];
            $a1 = -$s3 + $s5 - $s7 - ($s7 >> 1);
            $a3 =  $s1 + $s7 - $s3 - ($s3 >> 1);
            $a5 = -$s1 + $s7 + $s5 + ($s5 >> 1);
            $a7 =  $s3 + $s5 + $s1 + ($s1 >> 1);
            // 奇数项 a1/a3/a5/a7：用 1/2 移位的旋转因子近似

            $b1 = ($a7 >> 2) + $a1;
            $b3 = $a3 + ($a5 >> 2);
            $b5 = ($a3 >> 2) - $a5;
            $b7 = $a7 - ($a1 >> 2);
            // b1/b3/b5/b7 用 >>2 修正各支路增益

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
        // 输出下标 out[k*8+i]：k 为行、i 为列，等价于把列变换结果按行主序摊平
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
