<?php

namespace Xiaosongshu\Flv2mp4\Codec\Encode;

/**
 * @purpose 帧间预测
 * @author yanglong
 */
trait InterPredTrait
{
    // 帧间预测 trait 总览（编码侧构件）：P/B 16x16 宏块准备（ME + MC + 变换量化 + 本地重建）、
    // 写码（CAVLC 7.3.5/9.2 与 CABAC 9.3 两套语法序）、邻块簿记（6.4.9 可用性、
    // 8.4.1.3 中值 MV 预测、9.2.1 nC 上下文）、运动补偿（8.4.2.2.1 亮度、8.4.2.2.2 色度）。
    /**
     * P 帧 16x16 宏块准备（P_L0_16x16 与 P_Skip 共用）。
     * 流程：early-skip 判定 → 16x16 运动估计 → 1/4 像素亮度 MC → 逐 4x4 残差
     * DCT/量化 →（可选）8x8DCT 双路择优 → 本地重建 → 色度残差与重建。
     * @param int $mbX 宏块列号；$mbY 宏块行号（均以 16x16 宏块为单位）
     * @param string $luma 当前宏块亮度 256 字节；$curU/$curV 当前色度各 64 字节
     * @param string $refYPlane 参考亮度平面；$refUPlane/$refVPlane 参考色度平面
     * @param int $motionRange 整像素搜索半径
     * @param array|null $forcedMv B 帧强制 MV=[mvX,mvY,sad]，非空时跳过 ME 与 early-skip
     * @return array [mvX,mvY,sad,cbpLuma,nzCache,quantResidual,reconY,reconU,reconV,cbpChroma,chromaDc,chromaAc,13=>dct8]
     */
    public function preparePMacroblock(
        int $mbX,
        int $mbY,
        string $luma,
        string $curU,
        string $curV,
        string $refYPlane,
        string $refUPlane,
        string $refVPlane,
        int $motionRange = 32,
        ?array $forcedMv = null
    ): array {
        // 进入本函数时可假定：$luma 为 16x16（256 字节）、$curU/$curV 为 8x8（各 64 字节），
        // 参考平面按宏块对齐尺寸（mbAlignedWidth/Height）存储。
        if ($forcedMv !== null && $this->bCurFlat !== null) {
            // B 帧 forcedMv 路径：prepareBMacroblock 已对同一 $luma 做过 unpack，直接复用（内容逐位相同）
            $curFlat = $this->bCurFlat;
        } else {
            $curFlat = array_values(unpack('C*', $luma));
        }
        // array_values 把 unpack('C*') 的 1 基下标重排为 0 基列表；B 帧时复用已 unpack 的 bCurFlat

        // === Tier1 early-skip（数学保证，零画质风险）===
        // 单次遍历计算 16 个 4x4 块在整数 (0,0) 的 SAD；全部 ≤ QP 死区阈值时，
        // 残差经 DCT+Inter 量化必定全为 0（见 MotionTrait::zeroResidualBlockSad）。
        // 此时直接产出 MV=(0,0)、cbp=0、与 (0,0) 运动补偿完全一致的重建帧，
        // 跳过整/半/四像素运动搜索与全部 DCT/量化。主进程仍按 skipMVP 决定 P_Skip，
        // 故与"完整搜索恰好得到同结果"位级等价。
        // 依据：H.264 8.4.2 运动补偿与 8.5 变换量化；整数 MV=(0,0) 时无插值误差。
        if ($forcedMv === null && $this->earlySkip) {
            $zeroT = $this->zeroResidualBlockSad($this->qp);
            // 零残差阈值：块内 SAD 不超过该 QP 死区时，DCT 系数必落在量化舍入范围内
            $stride = $this->mbAlignedWidth;
            if ($this->refInts === null) $this->refInts = unpack('C*', $refYPlane);
            $refInts = $this->refInts;
            $ox = $mbX * 16;
            $oy = $mbY * 16;
            // $stride：参考亮度平面行跨度；($ox,$oy)：宏块在参考帧中的整像素左上角
            $blockSads = array_fill(0, 16, 0);
            $alive = 16;
            $totalSad = 0;
            for ($y = 0; $y < 16; $y++) {
                $rowBase = ($oy + $y) * $stride + $ox;
                $biBase = ($y >> 2) * 4;
                // $biBase：当前像素行所属 4x4 子块的行基索引（每行跨 4 个子块）
                for ($x = 0; $x < 16; $x++) {
                    $diff = $curFlat[$y * 16 + $x] - $refInts[$rowBase + $x + 1];
                    // curFlat 为 0 基、refInts 为 1 基，故 +1 对齐；整数 MV 无插值（8.4.2.2.1）
                    if ($diff < 0) $diff = -$diff;
                    $totalSad += $diff;
                    $bi = $biBase + ($x >> 2);
                    // 子块光栅索引 bi = 子块行基 + 列号（x>>2）
                    if ($blockSads[$bi] <= $zeroT) {
                        $blockSads[$bi] += $diff;
                        if ($blockSads[$bi] > $zeroT) --$alive;
                    }
                }
                if ($alive === 0) break;
            }
            // 全部子块都超出死区时提前结束遍历（结果必定是非零残差）
            if ($alive === 16) {
                // $alive==16 表示 16 个 4x4 子块全部落在死区内 → 残差必为全零
                // 亮度确定：MV=(0,0)、cbpLuma=0，reconY 与 (0,0) MC 完全一致
                $reconY = '';
                for ($y = 0; $y < 16; $y++) {
                    $reconY .= substr($refYPlane, ($oy + $y) * $stride + $ox, 16);
                }
                // 亮度静止 ≠ 色度静止（渐变灯光/曝光），MV=0 下仍要正常编码色度残差
                $chr = $this->processChroma($mbX, $mbY, 0, 0, $refUPlane, $refVPlane, $curU, $curV);
                $nzCache = array_fill(0, 16, 0);
                for ($i = 0; $i < 8; $i++) $nzCache[16 + $i] = $chr['nzSeg'][$i];
                // nzCache 布局：[0..15]=亮度 4x4 的 nz，[16..23]=色度 8 块
                return [
                    0, 0, $totalSad, 0,
                    $nzCache,
                    [],
                    $reconY, $chr['reconU'], $chr['reconV'],
                    $chr['cbpChroma'], $chr['chromaDc'], $chr['chromaAc'],
                ];
            }
        }
        // 以下为通用路径。元组布局：[0]mvX [1]mvY [2]sad [3]cbpLuma [4]nzCache
        // [5]quantResidual [6]reconY [7]reconU [8]reconV [9]cbpChroma [10]chromaDc
        // [11]chromaAc；[12] 留给 B 帧 list，[13] 为 dct8 标志

        if ($forcedMv !== null) {
            // B 帧：MV 已由 prepareBMacroblock 双参考择优，跳过运动搜索直接使用
            $mvX = $forcedMv[0];
            $mvY = $forcedMv[1];
            $sad = $forcedMv[2] ?? 0;
        } else {
            // 整像素搜索（±motionRange）并做半/四像素细化，返回 1/4 像素单位的 MV 与 SAD
            [$mvX, $mvY, $sad] = $this->motionEstimate16x16($curFlat, $refYPlane, $mbX, $mbY, $motionRange);
        }
        $refX = $mbX * 64 + $mvX;
        $refY = $mbY * 64 + $mvY;
        // 参考坐标 = 宏块原点（16 像素 → 16<<2 个 1/4 像素）+ MV，全程 1/4 像素单位
        $predBlock = $this->mcLumaBlock($refYPlane, $refX, $refY, $this->mbAlignedWidth, $this->mbAlignedHeight);
        // 1/4 像素亮度 MC（6-tap 插值，8.4.2.2.1）
        $nzCache = array_fill(0, 24, 0);
        $cbpLuma = 0;
        $quantResidual = [];
        // nzCache 初始全 0；quantResidual 按 4x4 光栅序存 16 个量化块

        // 逐 4x4 变换块：亮度宏块固定划分为 16 个 4x4 块（8.5.1）
        for ($by = 0; $by < 4; $by++) {
            for ($bx = 0; $bx < 4; $bx++) {
                $blkIdx = $by * 4 + $bx;
                // blkIdx：4x4 光栅序索引 0..15（与 cbp 的 8x8 分组编号不同）
                $res = [];
                for ($y = 0; $y < 4; $y++) {
                    $row = $by * 4 + $y;
                    for ($x = 0; $x < 4; $x++) {
                        $res[] = $curFlat[$row * 16 + $bx * 4 + $x] - $predBlock[$row * 16 + $bx * 4 + $x];
                    }
                }
                $this->dctFlat($res);
                // 正向 4x4 整数变换（8.5.2）
                $nz = $this->quantizeFlatInter($res);
                // 帧间量化，FF=1（量化步长由 QP 决定，8.5.3）
                $quantResidual[$blkIdx] = $res;
                $nzCache[$blkIdx] = min(15, $nz);
                // nz 为非零系数个数，clamp 到 15 作为 CAVLC nC 的依据（9.2.1）
                // 8x8 组索引 = (by/2)*2 + (bx/2)：该组任一 4x4 非零即置 cbpLuma 对应位
                if ($nz > 0) $cbpLuma |= 1 << (intdiv($by, 2) * 2 + intdiv($bx, 2));
            }
        }
        // cbp=0 的 8x8 组内 4 个 4x4 子块 nz 归零：解码端不会读取这些系数
        for ($blkIdx = 0; $blkIdx < 16; $blkIdx++) {
            $block8x8Idx = intdiv(intdiv($blkIdx, 4), 2) * 2 + intdiv($blkIdx % 4, 2);
            if (!($cbpLuma & (1 << $block8x8Idx))) $nzCache[$blkIdx] = 0;
        }

        // === T5 inter 8x8DCT 候选：high 档下与 4x4 并行量化，按 Σ|level| 取优（平手保持 4x4）===
        // 8x8 基保留低频更省 bits，但高频细节损失更大；x264 同款廉价系数代价比较。
        // 胜出时 quantResidual 仍按 16 个 4x4 子块结构打包（协议 payload 不变），
        // dct8 标志经 result[13] 传给写码端（[12] 是 B 帧的 list 槽位，不可占用）。
        $use8 = false;
        $stored8 = null;
        $nz8Arr = null;
        $qmul8 = null;
        // T5 分支说明：先独立算出 8x8 系数与代价，再与 4x4 总代价比较
        if (!empty($this->inter8x8Enabled)) {
            [$qmul8, $mf8] = $this->buildQuant8Tables($this->qp, false);
            // 8x8 量化乘数与反量化表（8.5.10/8.5.11 正逆 8x8 变换配套参数）
            $zz8 = self::$ZZ8_SCAN;
            $stored8 = [];
            $nz8Arr = [];
            $cost8 = 0;
            $cost4 = 0;
            for ($p8 = 0; $p8 < 4; $p8++) {
                // 四个 8x8 块：拼 64 点残差 → 正向 8x8 变换 → 帧间量化
                $oy0 = ($p8 >> 1) * 8;
                $ox0 = ($p8 & 1) * 8;
                $res = array_fill(0, 64, 0);
                for ($yy = 0; $yy < 8; $yy++) {
                    $row = $oy0 + $yy;
                    for ($xx = 0; $xx < 8; $xx++) {
                        // 残差 = 当前样本 - 预测样本（8.4.2.2 运动补偿之后）
                        $col = $ox0 + $xx;
                        $res[$yy * 8 + $xx] = $curFlat[$row * 16 + $col] - $predBlock[$row * 16 + $col];
                    }
                }
                $c = $this->forward8x8($res);
                // 正向 8x8 整数变换（8.5.10）
                $nz = $this->quantize8($c, $mf8, 1); // 帧间 ff=1
                // 8x8 量化，帧间 flat=1（8.5.11）
                $stored8[$p8] = $c;
                $nz8Arr[$p8] = $nz;
                if ($nz > 0) {
                    for ($j = 0; $j < 64; $j++) {
                        $v = $c[$j];
                        $cost8 += ($v < 0 ? -$v : $v);
                    }
                }
            }
            // 4x4 总代价：16 个已量化子块的 Σ|level|
            for ($b = 0; $b < 16; $b++) {
                foreach ($quantResidual[$b] as $v) {
                    $cost4 += ($v < 0 ? -$v : $v);
                }
            }
            $use8 = $cost8 < $cost4;
        }

        // 代价小者胜出；相等时保留 4x4（保守选择）
        if ($use8) {
            // 8x8DCT 胜出：按 8x8 组重建 cbp 与 nzCache，并把 64 系数拆回 4 个 4x4 载荷
            // cbp 按 8x8 块重建；nzCache 四子块同值（fill_rectangle 语义，与 encWriteResidual8x8 写回一致）
            $cbpLuma = 0;
            // cbpLuma 按 8x8 组重新置位（bit0..3 = 左上/右上/左下/右下）
            for ($p8 = 0; $p8 < 4; $p8++) {
                $base = ($p8 >> 1) * 8 + ($p8 & 1) * 2;
                $nz = $nz8Arr[$p8];
                if ($nz > 0) {
                    $cbpLuma |= 1 << $p8;
                    $nzCache[$base] = min(15, $nz);
                    $nzCache[$base + 1] = min(15, $nz);
                    $nzCache[$base + 4] = min(15, $nz);
                    $nzCache[$base + 5] = min(15, $nz);
                } else {
                    $nzCache[$base] = 0;
                    $nzCache[$base + 1] = 0;
                    $nzCache[$base + 4] = 0;
                    $nzCache[$base + 5] = 0;
                }
            }
            // 64 系数按 4x4 子块 raster 位置重装（写码端反向重装回存储布局）
            $quantResidual = [];
            // 系数重排：8x8 的 64 个系数拆成 4 个 4x4 子块，按 raster 顺序存放
            for ($p8 = 0; $p8 < 4; $p8++) {
                // $base 为该 8x8 组左上角 4x4 子块的光栅索引
                $base = ($p8 >> 1) * 8 + ($p8 & 1) * 2;
                $c = $stored8[$p8];
                for ($subY = 0; $subY < 2; $subY++) {
                    for ($subX = 0; $subX < 2; $subX++) {
                        $blk = array_fill(0, 16, 0);
                        for ($yy = 0; $yy < 4; $yy++) {
                            for ($xx = 0; $xx < 4; $xx++) {
                                $blk[$yy * 4 + $xx] = $c[($subY * 4 + $yy) * 8 + $subX * 4 + $xx];
                            }
                        }
                        $quantResidual[$base + $subY * 4 + $subX] = $blk;
                    }
                }
            }
            // 重建：dequantize8 + inverse8x8（与解码端 idct8AddToPlane 逐比特一致）
            $reconY = str_repeat("\0", 256);
            // 本地重建：反量化 8x8 → 逆变换 → 加预测 → 钳位 0..255（8.5.11）
            for ($p8 = 0; $p8 < 4; $p8++) {
                // 每个 8x8 块独立重建（nz=0 时残差全 0）
                $by = ($p8 >> 1) * 8;
                $bx = ($p8 & 1) * 8;
                if ($nz8Arr[$p8] > 0) {
                    $c = $stored8[$p8];
                    $this->dequantize8($c, $qmul8);
                    $resid = $this->inverse8x8($c);
                } else {
                    $resid = array_fill(0, 64, 0);
                }
                for ($yy = 0; $yy < 8; $yy++) {
                    $row = $by + $yy;
                    for ($xx = 0; $xx < 8; $xx++) {
                        $col = $bx + $xx;
                        $value = $predBlock[$row * 16 + $col] + $resid[$yy * 8 + $xx];
                        $reconY[$row * 16 + $col] = chr(max(0, min(255, $value)));
                    }
                }
            }
        } else {
        // 4x4 路径：逐 4x4 反量化/逆变换后叠加预测
        $reconY = str_repeat("\0", 256);
        // 亮度重建缓冲（256 字节，行跨度 16）
        for ($by = 0; $by < 4; $by++) {
            for ($bx = 0; $bx < 4; $bx++) {
                $blkIdx = $by * 4 + $bx;
                $block8x8Idx = intdiv($by, 2) * 2 + intdiv($bx, 2);
                if ($cbpLuma & (1 << $block8x8Idx)) {
                    // 仅 cbp 置位的 8x8 组做反量化/逆变换，其余直接使用预测样本
                    $blk = $quantResidual[$blkIdx];
                    $this->dequantize4x4($blk, 0, $this->qp);
                    // 反量化（list 0，FF=1）后逆 4x4 变换（8.5.3/8.5.4）
                    $this->idctFlat($blk);
                    for ($y = 0; $y < 4; $y++) {
                        for ($x = 0; $x < 4; $x++) {
                            $value = $predBlock[($by * 4 + $y) * 16 + $bx * 4 + $x] + $blk[$y * 4 + $x];
                            $reconY[($by * 4 + $y) * 16 + $bx * 4 + $x] = chr(max(0, min(255, $value)));
                        }
                    }
                } else {
                    for ($y = 0; $y < 4; $y++) {
                        for ($x = 0; $x < 4; $x++) {
                            $reconY[($by * 4 + $y) * 16 + $bx * 4 + $x] = chr(max(0, min(255, $predBlock[($by * 4 + $y) * 16 + $bx * 4 + $x])));
                        }
                    }
                }
            }
        }
        } // end 4x4/8x8 recon

        // 色度：1/8 MC + DCT + inter 量化 + 本地解码重建（始终保证色度质量）
        $chr = $this->processChroma($mbX, $mbY, $mvX, $mvY, $refUPlane, $refVPlane, $curU, $curV);
        // 色度：MV 与亮度共用（1/4 像素单位按 1/8 像素解释），双线性插值（8.4.2.2.2）
        for ($i = 0; $i < 8; $i++) $nzCache[16 + $i] = $chr['nzSeg'][$i];
        return [
            $mvX, $mvY, $sad, $cbpLuma, $nzCache, $quantResidual,
            $reconY, $chr['reconU'], $chr['reconV'],
            $chr['cbpChroma'], $chr['chromaDc'], $chr['chromaAc'],
            13 => $use8 ? 1 : 0,
        ];
    }

    /**
     * P帧色度残差处理（色度正常档）：
     * 色度 MV 与亮度共用（P_16x16 单 MV），1/8 像素 MC 得预测 → 4 个 4x4 子块
     * DCT → inter 色度量化；DC 收集后做 2x2 Hadamard → inter DC 量化；并做本地解码重建。
     * 结构与 I 帧色度一致，区别：色度量化走 inter（FF 索引=QP），反量化 list 为 Cb=5、Cr=4。
     *
     * @return array{cbpChroma:int,nzSeg:array<int>,chromaDc:array<int>,chromaAc:array<int[]>,reconU:string,reconV:string}
     */
    // 参数：$mbX/$mbY 宏块坐标；$mvX/$mvY 亮度 MV（1/4 像素单位，色度解释为 1/8 像素）；
    // $refUPlane/$refVPlane 参考色度平面，$curU/$curV 当前 8x8 色度块。
    // 语法序：Cb DC → Cr DC → Cb AC(0..3) → Cr AC(0..3)（7.3.5.3）。
    private function processChroma(
        int $mbX,
        int $mbY,
        int $mvX,
        int $mvY,
        string $refUPlane,
        string $refVPlane,
        string $curU,
        string $curV
    ): array {
        $chromaW = intdiv($this->mbAlignedWidth, 2);
        // 4:2:0：色度平面跨度/高度均为亮度对齐尺寸的一半
        $chromaH = intdiv($this->mbAlignedHeight, 2);
        $chromaRefX = $mbX * 64 + $mvX;
        $chromaRefY = $mbY * 64 + $mvY;
        // 色度参考坐标同样按「宏块原点 + MV」计算，单位仍是 1/8 像素
        $cbPred = $this->mcChromaBlock($refUPlane, $chromaRefX, $chromaRefY, $chromaW, $chromaH);
        $crPred = $this->mcChromaBlock($refVPlane, $chromaRefX, $chromaRefY, $chromaW, $chromaH);
        // 1/8 像素双线性插值取 8x8 色度预测（8.4.2.2.2）

        $chromaQpIndex = max(0, min(51, $this->qp + $this->chromaQpIndexOffset));
        $chromaQp = self::CHROMA_QP_TABLE[$chromaQpIndex];
        // 色度 QP = CHROMA_QP_TABLE[clip(QP + chroma_qp_index_offset, 0, 51)]（Table 8-15）

        $curCb = array_values(unpack('C*', $curU));
        $curCr = array_values(unpack('C*', $curV));

        // 当前色度转 0 基字节数组，便于与预测块逐元素求差
        $quantCb = array_fill(0, 4, array_fill(0, 16, 0));
        $quantCr = array_fill(0, 4, array_fill(0, 16, 0));
        $dcCb = [0, 0, 0, 0];
        $dcCr = [0, 0, 0, 0];
        $nzSeg = array_fill(0, 8, 0); // 0..3=Cb块, 4..7=Cr块 → nzCache[16..23]
        // nzSeg：0..3=Cb 的 4 个 4x4 块，4..7=Cr；最终映射到 nzCache[16..23]
        for ($by = 0; $by < 2; $by++) {
            for ($bx = 0; $bx < 2; $bx++) {
                $blk = $by * 2 + $bx;
                // blk：2x2 排列的 4x4 色度块光栅序号 0..3
                $blkU = array_fill(0, 4, array_fill(0, 4, 0));
                $blkV = array_fill(0, 4, array_fill(0, 4, 0));
                // 色度残差 = 当前 - 预测
                for ($y = 0; $y < 4; $y++) for ($x = 0; $x < 4; $x++) {
                    $py = $by * 4 + $y;
                    $px = $bx * 4 + $x;
                    $flat = $py * 8 + $px;
                    // 8x8 色度块内的线性下标（行跨度 8）
                    $blkU[$y][$x] = $curCb[$flat] - $cbPred[$flat];
                    $blkV[$y][$x] = $curCr[$flat] - $crPred[$flat];
                }
                $dctU = $this->dct($blkU);
                $dctV = $this->dct($blkV);
                // 正向 4x4 整数变换（8.5.2）
                $dcCb[$blk] = $dctU[0][0];
                $dcCr[$blk] = $dctV[0][0];
                // DC 系数留待 2x2 Hadamard 统一处理（8.5.6.1）
                $qU = $this->quantizeChroma($dctU, $chromaQp, true);
                $qV = $this->quantizeChroma($dctV, $chromaQp, true);
                // 帧间色度量化，FF 索引取 QP（8.5.3）
                $nzU = 0;
                $nzV = 0;
                // nz 仅统计 AC（下标 0 是 DC，走 Hadamard 通道），上限 15
                for ($yy = 0; $yy < 4; $yy++) for ($xx = 0; $xx < 4; $xx++) {
                    $f = $yy * 4 + $xx;
                    $quantCb[$blk][$f] = $qU[$yy][$xx];
                    $quantCr[$blk][$f] = $qV[$yy][$xx];
                    if ($f !== 0) {
                        if ($qU[$yy][$xx] !== 0) $nzU++;
                        if ($qV[$yy][$xx] !== 0) $nzV++;
                    }
                }
                $nzSeg[$blk] = min(15, $nzU);
                $nzSeg[4 + $blk] = min(15, $nzV);
            }
        }

        $hadCb = $this->forwardChromaHadamard2x2($dcCb);
        $hadCr = $this->forwardChromaHadamard2x2($dcCr);
        $qCbDc = $this->quantizeChromaDC($hadCb, $chromaQp, true);
        $qCrDc = $this->quantizeChromaDC($hadCr, $chromaQp, true);
        // 2x2 Hadamard 后的 DC 量化（8.5.6.2），Cb/Cr 各 4 个 DC

        $hasChromaDc = false;
        for ($i = 0; $i < 4; $i++) {
            if ($qCbDc[$i] !== 0 || $qCrDc[$i] !== 0) { $hasChromaDc = true; break; }
        }
        // 任一 DC 非零 → cbpChroma 最低位置 1（色度 DC 需写码）
        $hasChromaAc = false;
        for ($i = 0; $i < 8; $i++) if ($nzSeg[$i] > 0) { $hasChromaAc = true; break; }
        // 标准无色度"仅AC"状态：只要有AC，cbpChroma必须为2（DC块即使全0也要在码流中发出）；
        // 否则DC非零为1。原"DC优先"判定在 AC非零但DC全量化为0时错判0，丢失AC且nz残留污染后续nC。
        $cbpChroma = $hasChromaAc ? 2 : ($hasChromaDc ? 1 : 0);

        // === 色度本地解码重建（反量化 list：Cb=5、Cr=4）===
        $cbQmul = $this->dequant4Table[5][$chromaQp][0];
        $crQmul = $this->dequant4Table[4][$chromaQp][0];
        $cbDcResult = $this->chromaDcDequantIdct($qCbDc, $cbQmul);
        $crDcResult = $this->chromaDcDequantIdct($qCrDc, $crQmul);
        // 反量化 + 逆 2x2 Hadamard：得到每个 4x4 块待叠加的 DC 残差（8.5.6.3）

        $reconU = str_repeat("\0", 64);
        $reconV = str_repeat("\0", 64);
        for ($by = 0; $by < 2; $by++) {
            // 逐 4x4 块重建并写回 8x8 色度平面
            for ($bx = 0; $bx < 2; $bx++) {
                $blk = $by * 2 + $bx;
                $cbDcResidual = $cbDcResult[$blk];
                $crDcResidual = $crDcResult[$blk];
                if ($cbpChroma >= 2) {
                    // 有 AC：DC 放回系数 0，与 AC 一起做逆变换（8.5.4）
                    // AC 存在：DC 放入[0] 一起 IDCT（直接调 idctFlat，省掉 2D flatten/expand）
                    $cbAcDequant = $quantCb[$blk];
                    $crAcDequant = $quantCr[$blk];
                    $this->dequantize4x4($cbAcDequant, 5, $chromaQp);
                    $this->dequantize4x4($crAcDequant, 4, $chromaQp);
                    $cbAcDequant[0] = $cbDcResidual;
                    $crAcDequant[0] = $crDcResidual;
                    $this->idctFlat($cbAcDequant);
                    $this->idctFlat($crAcDequant);
                    for ($y = 0; $y < 4; $y++) for ($x = 0; $x < 4; $x++) {
                        $py = $by * 4 + $y;
                        $px = $bx * 4 + $x;
                        $f = $py * 8 + $px;
                        $vu = $cbPred[$f] + $cbAcDequant[$y * 4 + $x];
                        $vv = $crPred[$f] + $crAcDequant[$y * 4 + $x];
                        $reconU[$f] = chr(max(0, min(255, $vu)));
                        $reconV[$f] = chr(max(0, min(255, $vv)));
                    }
                } elseif ($cbpChroma === 1) {
                    // 仅 DC：整块残差为常数 (DC+32)>>6（逆 Hadamard 后的舍入）
                    // DC-only: (DC + 32) >> 6
                    $cbDcAdd = ($cbDcResidual + 32) >> 6;
                    $crDcAdd = ($crDcResidual + 32) >> 6;
                    for ($y = 0; $y < 4; $y++) for ($x = 0; $x < 4; $x++) {
                        $py = $by * 4 + $y;
                        $px = $bx * 4 + $x;
                        $f = $py * 8 + $px;
                        $vu = $cbPred[$f] + $cbDcAdd;
                        $vv = $crPred[$f] + $crDcAdd;
                        $reconU[$f] = chr(max(0, min(255, $vu)));
                        $reconV[$f] = chr(max(0, min(255, $vv)));
                    }
                } else {
                    // cbpChroma=0：直接用预测值
                    for ($y = 0; $y < 4; $y++) for ($x = 0; $x < 4; $x++) {
                        $py = $by * 4 + $y;
                        $px = $bx * 4 + $x;
                        $f = $py * 8 + $px;
                        $reconU[$f] = chr(max(0, min(255, $cbPred[$f])));
                        $reconV[$f] = chr(max(0, min(255, $crPred[$f])));
                    }
                }
            }
        }

        // 协议载荷：色度 DC（Cb4+Cr4）、色度 AC（Cb4块+Cr4块）
        $chromaDc = array_merge($qCbDc, $qCrDc);
        $chromaAc = [];
        for ($i = 0; $i < 4; $i++) {
            $chromaAc[$i] = $quantCb[$i];
            $chromaAc[4 + $i] = $quantCr[$i];
        }

        return [
            'cbpChroma' => $cbpChroma,
            'nzSeg' => array_values($nzSeg),
            'chromaDc' => $chromaDc,
            'chromaAc' => $chromaAc,
            'reconU' => $reconU,
            'reconV' => $reconV,
        ];
    }

    /**
     * 编码P帧宏块（P_16x16模式）
     * 包含运动估计、MVP预测、MVD编码、残差编码
     */
    /**
     * 编码 P 帧 16x16 宏块（P_L0_16x16 / P_Skip）。
     * @param int $mbX/$mbY 宏块坐标；输入帧平面按 mbAligned 尺寸存储
     * @param array &$leftNz/&$topNzLuma/&$topNzCb/&$topNzCr 邻块 nz 缓存（9.2.1）
     * @return string 该宏块的写码比特串（CABAC 模式下为空，已写入码流缓冲）
     * 语法序（7.3.5）：mb_skip_flag/mb_skip_run → mb_type（Table 7-13）→ ref_idx →
     * mvd_l0 → coded_block_pattern（Table 9-4）→ mb_qp_delta → 残差块（9.2/9.3）。
     */
    public function encodePMacroblock(
        int $mbX,
        int $mbY,
        string $yPlane,
        string $uPlane,
        string $vPlane,
        bool $leftAvailable,
        array &$leftNz,
        bool $topAvailable,
        array &$topNzLuma,
        array &$topNzCb,
        array &$topNzCr,
        array &$leftIntra4x4Mode,
        array &$topIntra4x4Mode,
        string $refYPlane
    ): string {
        $bits = '';

        // 取 worker 预先算好的宏块结果（并行流水线，避免写码线程重复做 ME）
        $prepared = $this->motionWorkerResults[$mbY * $this->picWidthInMbs + $mbX] ?? null;
        if ($prepared === null) {
            throw new \RuntimeException("缺少 P 宏块 Worker 结果 ({$mbX},{$mbY})");
        }
        [$mvX, $mvY, $sad, $cbpLuma, $nzCache, $quantResidual, $workerReconY, $workerReconU, $workerReconV] = $prepared;
        // $prepared 元组：0..11 同 preparePMacroblock，[12] 为 B 帧 list，[13] 为 dct8
        $cbpChroma = $prepared[9] ?? 0;
        $chromaDc = $prepared[10] ?? [];   // 8 个量化 Hadamard DC：Cb4 + Cr4
        $chromaAc = $prepared[11] ?? [];  // 8 块量化系数：Cb0-3 + Cr0-3
        $dct8 = !empty($prepared[13]);    // T5 inter 8x8DCT（prepare 双路决策结果）
        // 8x8DCT 标志：与写码端 transform_size_8x8_flag 保持一致

        //$reconStride = $this->mbAlignedWidth;

        // === 计算P_Skip的MVP（与解码器predictMvPSkip一致） ===
        // P_Skip的MV = skipMVP，解码器用此MV做MC
        list($skipMvpX, $skipMvpY) = $this->getMvpPSkip($mbX, $mbY);
        // P_Skip 的 MV 固定为 skipMVP：解码端按 8.4.1.1 递推，MVD 隐含为 0

        // 色度参考帧尺寸（使用mbAligned尺寸，与I帧重建存储格式一致）
        $chromaW = intdiv($this->mbAlignedWidth, 2);
        //$chromaH = intdiv($this->mbAlignedHeight, 2);
        $reconStride = $this->mbAlignedWidth;
        // 重建帧行跨度：与参考帧一致的宏块对齐宽度

        // P_Skip条件：CBP（亮度+色度）全为0 且 MV等于skipMVP（MVD=0）
        // 这样解码器用MV=skipMVP做MC，与编码器本地解码一致
        if ($cbpLuma == 0 && $cbpChroma == 0 && $mvX == $skipMvpX && $mvY == $skipMvpY) {
            $this->lastMbWasSkip = true;
            // 7.4.5.1：P_Skip 语义即 cbp 全 0 且 MVD 为 0

            // CABAC：mb_skip_flag=1（语法序镜像解码器 decodeCabacPMb skip 路径）
            if (!empty($this->useCabac)) {
                $this->cabMbBegin($mbX, $mbY);
                // CABAC 宏块起始：加载/初始化邻块上下文（9.3.3.1.1）
                $mbIdx = $this->cabMbIdx;
                $this->picChromaPred[$mbIdx] = 0;
                $this->picIsSkip[$mbIdx] = 0;
                $this->encWriteMbSkip(false, true);
                // 写 mb_skip_flag=1（上下文由左/上 skip 状态决定）
                $this->picIsSkip[$mbIdx] = 1;
                $this->picCbp[$mbIdx] = 0;   // 跳过宏块：cbp_table 置 0
                $this->cabLastQpDiff = 0;    // last_qscale_diff 置 0
            }
            // 跳过宏块无残差：左/上 nz 全部归零，供后续宏块 nC 推导（9.2.1）

            // 更新邻居nz缓存（亮度 + 色度全部清零）
            for ($by = 0; $by < 8; $by++) {
                $leftNz[$by] = 0;
            }
            for ($bx = 0; $bx < 4; $bx++) {
                $topBlkX = $mbX * 4 + $bx;
                if ($topBlkX < count($topNzLuma)) {
                    $topNzLuma[$topBlkX] = 0;
                }
            }
            $topCbx0 = $mbX * 2;
            $topCbx1 = $mbX * 2 + 1;
            if ($topCbx1 < count($topNzCb)) {
                $topNzCb[$topCbx0] = 0;
                $topNzCb[$topCbx1] = 0;
                $topNzCr[$topCbx0] = 0;
                $topNzCr[$topCbx1] = 0;
            }

            // 保存MV供后续宏块预测（MV=skipMVP, refIdx=0）
            $this->saveMv16x16($mbX, $skipMvpX, $skipMvpY, 0);
            // 保存 MV 供后续宏块 MV 预测（8.4.1.3 的邻居缓存）

            // 帧流水线：整帧 recon 已在派发下一帧前由 worker 结果预拼，无需逐 MB 拷贝
            if (!$this->reconPreassembled) {
                // 非预拼装模式：逐行把 worker 重建结果写回帧缓冲，offset=(y0+y)*stride+x0
                for ($y = 0; $y < 16; $y++) {
                    $offset = ($mbY * 16 + $y) * $reconStride + $mbX * 16;
                    $this->reconYPlane = substr_replace($this->reconYPlane, substr($workerReconY, $y * 16, 16), $offset, 16);
                }
                for ($y = 0; $y < 8; $y++) {
                    $offset = ($mbY * 8 + $y) * $chromaW + $mbX * 8;
                    $this->reconUPlane = substr_replace($this->reconUPlane, substr($workerReconU, $y * 8, 8), $offset, 8);
                    $this->reconVPlane = substr_replace($this->reconVPlane, substr($workerReconV, $y * 8, 8), $offset, 8);
                }
            }

            return '';
        }

        // 非Skip宏块
        $this->lastMbWasSkip = false;

        $refIdx = 0;
        // ---- 非 skip 宏块：以下分 CABAC / CAVLC 两条写码路径，语法内容等价 ----
        if (!empty($this->useCabac)) {
            // ---- CABAC 写码分支：语法序镜像解码器 decodeCabacPMb 非 skip 路径（P_L0_16x16）----
            $this->cabMbBegin($mbX, $mbY);
            $mbIdx = $this->cabMbIdx;
            $this->picChromaPred[$mbIdx] = 0;
            $this->picIsSkip[$mbIdx] = 0;
            $this->encWriteMbSkip(false, false);
            $this->encWritePMbType(0); // P_L0_16x16
            // inter MB：不可用邻居 nz 哨兵转 0（!IS_INTRA -> 0）
            $this->cabFixUnavailNz(false);
            // ref_idx：单参考帧（numRefIdxL0Active<=1）解码器不读任何 bin；
            // 渐进 ref 覆盖仍镜像解码器填 0，保持簿记一致
            $this->cabOvRefBox(0, 0, 0, 4, 4, $refIdx);
            // 运动向量预测(MVP) - 使用与解码器一致的predictMvP16x16
            list($mvpX, $mvpY) = $this->getMvpP16x16($mbX, $mbY, $refIdx);
            // MVP = 邻居 MV 中值预测（8.4.1.3）；邻居可用性见 6.4.9
            // MVD = MV - MVP (1/4像素单位)
            $mvdX = $mvX - $mvpX;
            $mvdY = $mvY - $mvpY;
            // MVD = MV - MVP，单位 1/4 像素（7.4.5.3）
            $this->encWriteMvd(0, 0, 0, $mvdX);
            $this->encWriteMvd(0, 1, 0, $mvdY);
            // mvd 写回邻居簿记（后续宏块 mvd 上下文依赖）
            $this->cabStoreMvd(0, $mbIdx, 0, 0, 4, 4, $mvdX, $mvdY);
            $this->encWriteCbpLuma($cbpLuma);
            $this->encWriteCbpChroma($cbpChroma);
            // cbp 位图：低 4 位为亮度 8x8 组，bit4/5 为色度 DC/AC（Table 9-4 映射）
            // cbp_table 在残差写入前赋值（色度 DC 的 CBF 标志位在其上 OR）
            $cbp = $cbpLuma | ($cbpChroma << 4);
            $this->picCbp[$mbIdx] = $cbp;
            $this->cabCbp = $cbp;
            // transform_size_8x8_flag：PPS transform_8x8_mode_flag=1（high）且 cbpLuma 低 4 位
            // 非零时解码器在 qp delta 前读该 bin（CabacDecodingTrait:2094）
            if ($this->transform8x8ModeFlag && ($cbpLuma & 15) !== 0) {
                $this->encDecision(399 + $this->cabNeighborTransformSize(), $dct8 ? 1 : 0);
            }
            // cbp 非零才写 mb_qp_delta（7.3.5）；否则差值保持 0
            if ($cbp !== 0) {
                $this->encWriteQpDelta(0);
            } else {
                $this->cabLastQpDiff = 0;
            }
            $this->encWriteInterResidual(
                $cbpLuma,
                $cbpChroma,
                $quantResidual,
                [array_slice($chromaDc, 0, 4), array_slice($chromaDc, 4, 4)],
                [array_slice($chromaAc, 0, 4), array_slice($chromaAc, 4, 4)],
                0,
                $dct8
            );
            // 邻居簿记置位必须与 flag bin 写 1 的条件一致（cbpLuma&15==0 时解码器不读该 bin，
            // 不置 transform_size 邻居标志；否则后续宏块 ctx 399+n 失步 → bytestream 错误）
            $this->encFinishInter($dct8 && ($cbpLuma & 15) !== 0);
        } else {
        $bits .= $this->ue(0); // mb_type = 0 for P_L0_16x16模式: mb_type = 0 in P slice (1 partition, 1 MV)

        // 运动向量预测(MVP) - 使用与解码器一致的predictMvP16x16
        list($mvpX, $mvpY) = $this->getMvpP16x16($mbX, $mbY, $refIdx);
        // 与解码端一致的 16x16 MVP（8.4.1.3）

        // MVD = MV - MVP (1/4像素单位)
        $mvdX = $mvX - $mvpX;
        $mvdY = $mvY - $mvpY;
        $bits .= $this->se($mvdX);
        $bits .= $this->se($mvdY);
        // mvd_l0 两个分量，se(v) 编码（9.1.1）

        // CBP编码（P帧使用Inter映射表）——预建反向查找表，省掉每宏块 array_search O(n)
        // Inter模式CBP映射表 (codeNum -> cbp)，必须与解码器GOLOMB_TO_INTER_CBP完全一致
        static $cbpFullToCode = null;
        if ($cbpFullToCode === null) {
            $interCbpMap = [
                0, 16, 1, 2, 4, 8, 32, 3, 5, 10, 12, 15, 47, 7, 11, 13,
                14, 6, 9, 31, 35, 37, 42, 44, 33, 34, 36, 40, 39, 43, 45, 46,
                17, 18, 20, 24, 19, 21, 26, 28, 23, 27, 29, 30, 22, 25, 38, 41,
            ];
            $cbpFullToCode = array_flip($interCbpMap);
        }
        // 查找完整CBP（亮度低4位 | 色度高2位）对应的codeNum
        $cbpFull = ($cbpLuma & 0x0F) | (($cbpChroma & 0x03) << 4);
        $cbpCode = $cbpFullToCode[$cbpFull] ?? 0;
        $bits .= $this->ue($cbpCode);
        // coded_block_pattern 的 ue(v) 编码，映射表见 Table 9-4

        // mb_qp_delta：CBP（含色度）非零时解码器必读取，delta=0 表示沿用 slice QP
        if ($cbpFull > 0) {
            $bits .= $this->se(0);
        }
        // mb_qp_delta=0：沿用当前 slice QP（7.4.5）

        // 编码残差（按8x8块分组，仅编码cbpLuma对应位为1的块）
        if ($cbpLuma > 0) {
            // 亮度残差：按 cbpLuma 置位组，逐 4x4 子块写 CAVLC 系数（9.2）
            // 每个8x8块包含4个4x4子块（按scan4顺序排列）
            $blockGroups = [
                [0, 1, 4, 5],    // 8x8 block 0 (top-left)
                [2, 3, 6, 7],    // 8x8 block 1 (top-right)
                [8, 9, 12, 13],  // 8x8 block 2 (bottom-left)
                [10, 11, 14, 15],// 8x8 block 3 (bottom-right)
            ];
            // 8x8 组 → 4 个 4x4 子块光栅索引的映射（H.264 的 raster 顺序）
            for ($blk8 = 0; $blk8 < 4; $blk8++) {
                if (!($cbpLuma & (1 << $blk8))) {
                    continue;
                }
                foreach ($blockGroups[$blk8] as $rasterIdx) {
                    $by = (int)($rasterIdx / 4);
                    $bx = $rasterIdx % 4;

                    $ac = $this->scan4x4DcAc($quantResidual[$rasterIdx]);
                    // nC 由左/上相邻块 nz 推出（9.2.1），决定 coeff_token 的 VLC 表
                    $acNc = $this->computeNC($rasterIdx, $mbX, $bx, $by, $leftAvailable, $leftNz, $topAvailable, $topNzLuma, $nzCache);
                    $bits .= $this->writeBlockResidualCavlc($ac, 15, false, $acNc);
                }
            }
        }

        // 编码色度残差：Cb DC → Cr DC → Cb AC(块序16-19) → Cr AC(块序20-23)
        // 与解码器 decodeResidualAndAdd 的读取顺序、I帧色度写码结构完全一致
        if ($cbpChroma > 0) {
            $bits .= $this->writeBlockResidualCavlc(array_slice($chromaDc, 0, 4), 3, true, -1);
            // 色度 DC 用专门的色度 DC 语法（4:2:0 为 2x2，totalCoeff ≤ 4，9.2.1）
            $bits .= $this->writeBlockResidualCavlc(array_slice($chromaDc, 4, 4), 3, true, -1);
            if ($cbpChroma >= 2) {
                for ($i = 0; $i < 4; $i++) {
                    // Cb AC 块：块序 16..19，nC 取左/上 Cb 邻居
                    $blockIdx = 16 + $i;
                    $cby = intdiv($i, 2);
                    $cbx = $i % 2;
                    $acNc = $this->computeNC($blockIdx, $mbX, $cbx, $cby, $leftAvailable, $leftNz, $topAvailable, $topNzCb, $nzCache);
                    $bits .= $this->writeBlockResidualCavlc($this->scan4x4Ac($chromaAc[$i]), 14, false, $acNc);
                }
                for ($i = 0; $i < 4; $i++) {
                    // Cr AC 块：块序 20..23，nC 取左/上 Cr 邻居
                    $blockIdx = 20 + $i;
                    $cby = intdiv($i, 2);
                    $cbx = $i % 2;
                    $acNc = $this->computeNC($blockIdx, $mbX, $cbx, $cby, $leftAvailable, $leftNz, $topAvailable, $topNzCr, $nzCache);
                    $bits .= $this->writeBlockResidualCavlc($this->scan4x4Ac($chromaAc[4 + $i]), 14, false, $acNc);
                }
            }
        }

        } // end CAVLC
        // CAVLC 路径结束

        // 更新亮度邻居nz缓存
        for ($by = 0; $by < 4; $by++) {
            // 取每个 4x4 行最右侧子块的 nz（该行右侧邻居的 nC 会用到）
            $leftNz[$by] = $nzCache[$by * 4 + 3];
        }
        for ($bx = 0; $bx < 4; $bx++) {
            $topBlkX = $mbX * 4 + $bx;
            if ($topBlkX < count($topNzLuma)) {
                $topNzLuma[$topBlkX] = $nzCache[$bx + 12];
            }
        }

        // 更新色度邻居nz缓存（照搬I帧映射；无色度残差时 nzCache[16-23] 本就为0）
        // 色度左邻：每行取右侧块（cbBlk = by*2+1）
        for ($by = 0; $by < 2; $by++) {
            // Cb 与 Cr 各自维护左邻 2 项
            $cbBlk = $by * 2 + 1;
            $leftNz[4 + $by] = $nzCache[16 + $cbBlk];
            $leftNz[6 + $by] = $nzCache[20 + $cbBlk];
        }
        $topCbx0 = $mbX * 2;
        $topCbx1 = $mbX * 2 + 1;
        if ($topCbx1 < count($topNzCb)) {
            $topNzCb[$topCbx0] = $nzCache[18];
            $topNzCb[$topCbx1] = $nzCache[19];
            $topNzCr[$topCbx0] = $nzCache[22];
            $topNzCr[$topCbx1] = $nzCache[23];
        }

        // 保存当前MV供后续宏块预测（与解码器saveMvForPrediction一致）
        $this->saveMv16x16($mbX, $mvX, $mvY, $refIdx);
        // 保存当前 MV 供后续宏块预测（refIdx=0）

        if (!$this->reconPreassembled) {
            // 写回重建帧（帧级预拼装时由上层统一处理）
            for ($y = 0; $y < 16; $y++) {
                $offset = ($mbY * 16 + $y) * $reconStride + $mbX * 16;
                $this->reconYPlane = substr_replace($this->reconYPlane, substr($workerReconY, $y * 16, 16), $offset, 16);
            }
            for ($y = 0; $y < 8; $y++) {
                $offset = ($mbY * 8 + $y) * $chromaW + $mbX * 8;
                $this->reconUPlane = substr_replace($this->reconUPlane, substr($workerReconU, $y * 8, 8), $offset, 8);
                $this->reconVPlane = substr_replace($this->reconVPlane, substr($workerReconV, $y * 8, 8), $offset, 8);
            }
        }

        return $bits;
    }

    // ====================== 运动向量预测 (与解码器MotionVectorPredictionTrait一致) ======================

    /**
     * 三整数取中值
     * @param int $a/$b/$c 三个整数
     * @return int 三者的中值
     */
    private function medianInt(int $a, int $b, int $c): int
    {
        $min = min($a, $b, $c);
        $max = max($a, $b, $c);
        // 三数和减最小、最大即中值，避免排序分支
        return $a + $b + $c - $min - $max;
    }

    /**
     * P帧16x16宏块运动向量预测 (8.4.1.3节)
     * 与解码器predictMvP16x16完全一致
     * @param array|null $mvLeft [mvX, mvY, refIdx]或null
     * @param array|null $mvTop  [mvX, mvY, refIdx]或null
     * @param array|null $mvTopRight [mvX, mvY, refIdx]或null
     * @param int $currRefIdx 当前参考帧索引
     * @return array [predMvX, predMvY]
     */
    private function predictMvP16x16(?array $mvLeft, ?array $mvTop, ?array $mvTopRight, int $currRefIdx): array
    {
        $aAvail = ($mvLeft !== null);
        $bAvail = ($mvTop !== null);
        $cAvail = ($mvTopRight !== null);
        // 邻居 A/B/C 对应左、上、右上（6.4.9 的块可用性推导）

        $mvA = $aAvail ? [$mvLeft[0], $mvLeft[1]] : [0, 0];
        $mvB = $bAvail ? [$mvTop[0], $mvTop[1]] : [0, 0];
        $mvC = $cAvail ? [$mvTopRight[0], $mvTopRight[1]] : [0, 0];
        // 不可用邻居按 MV=(0,0) 参与中值计算（8.4.1.3）

        $refA = $aAvail ? $mvLeft[2] : -1;
        $refB = $bAvail ? $mvTop[2] : -1;
        $refC = $cAvail ? $mvTopRight[2] : -1;

        $matchCount = 0;
        if ($refA === $currRefIdx) $matchCount++;
        if ($refB === $currRefIdx) $matchCount++;
        if ($refC === $currRefIdx) $matchCount++;
        // 统计 MV 指向同一参考帧的邻居个数，决定采用哪条预测规则

        if ($matchCount > 1) {
            // 规则：≥2 个邻居 refIdx 相同 → 各分量取中值
            return [
                $this->medianInt($mvA[0], $mvB[0], $mvC[0]),
                $this->medianInt($mvA[1], $mvB[1], $mvC[1]),
            ];
        } elseif ($matchCount === 1) {
            // 恰好 1 个相同 → 直接取该邻居的 MV
            if ($refA === $currRefIdx) return $mvA;
            if ($refB === $currRefIdx) return $mvB;
            return $mvC;
        } else {
            // 0 个相同 → 除边界特例外仍按中值预测
            if (!$bAvail && !$cAvail && $aAvail) {
                // 边界特例：仅 A 可用（B、C 均在图像外）时取 A（8.4.1.3）
                return $mvA;
            }
            return [
                $this->medianInt($mvA[0], $mvB[0], $mvC[0]),
                $this->medianInt($mvA[1], $mvB[1], $mvC[1]),
            ];
        }
    }

    /**
     * P_Skip运动向量预测 (8.4.1.1节)
     * 特殊快速路径（与 pred_pskip_motion 一致，与解码器完全一致）：
     * - 如果A（左邻居）完全不存在（帧边界外，null）→ 返回(0,0)
     * - 如果B（上邻居）完全不存在（帧边界外，null）→ 返回(0,0)
     * - 如果A是Inter宏块且ref=0、MV=(0,0) → 返回(0,0)
     * - 如果B是Inter宏块且ref=0、MV=(0,0) → 返回(0,0)
     * - 否则使用与P_16x16相同的中值预测逻辑
     */
    private function predictMvPSkip(?array $mvLeft, ?array $mvTop, ?array $mvTopRight): array
    {
        if ($mvLeft === null || $mvTop === null) {
            // 左或上邻居完全不可用（图像边界）→ 直接 (0,0)
            return [0, 0];
        }

        $aZero = ($mvLeft[2] === 0 && $mvLeft[0] === 0 && $mvLeft[1] === 0);
        // 邻居为 ref=0 且 MV=(0,0) 的 inter 宏块 → 快速判定 (0,0)
        $bZero = ($mvTop[2] === 0 && $mvTop[0] === 0 && $mvTop[1] === 0);
        if ($aZero || $bZero) {
            return [0, 0];
        }

        return $this->predictMvP16x16($mvLeft, $mvTop, $mvTopRight, 0);
    }

    /**
     * 获取16x16宏块MVP：读取左/上/右上邻居MV
     * 与解码器getP16x16MvPrediction一致
     */
    private function getMvpP16x16(int $mbX, int $mbY, int $refIdx): array
    {
        $mbWidth = $this->picWidthInMbs;
        // 邻居槽位按 4x4 粒度缓存，每宏块 4 个

        $mvLeft = null;
        $mvTop = null;
        $mvC = null;

        if ($mbX > 0) {
            // 左邻：优先取与当前宏块竖直相邻的子块行（下标 1），否则退回 0
            if (isset($this->mvLeftCol[1])) {
                $mvLeft = $this->mvLeftCol[1];
            } elseif (isset($this->mvLeftCol[0])) {
                $mvLeft = $this->mvLeftCol[0];
            }
        }
        if ($mbY > 0) {
            // 上邻：取上一宏块行第 mbX*4 个子块
            $mvTop = $this->mvTopRow[$mbX * 4] ?? null;
        }
        // C邻居：优先top-right，不可用时回退到top-left (D)
        if ($mbY > 0) {
            if ($mbX + 1 < $mbWidth) {
                $mvC = $this->mvTopRow[($mbX + 1) * 4] ?? null;
            }
            if ($mvC === null && $mbX > 0) {
                $mvC = $this->mvTopLeft;
            }
        }

        return $this->predictMvP16x16($mvLeft, $mvTop, $mvC, $refIdx);
    }

    /**
     * 获取P_Skip MVP：与解码器getPSkipMvPrediction一致
     */
    private function getMvpPSkip(int $mbX, int $mbY): array
    {
        $mbWidth = $this->picWidthInMbs;
        // 与 getMvpP16x16 相同的邻居收集，但预测函数换成 P_Skip 规则（8.4.1.1）

        $mvLeft = null;
        $mvTop = null;
        $mvC = null;

        if ($mbX > 0) {
            // 左邻收集（同 P_16x16 的槽位选择）
            if (isset($this->mvLeftCol[1])) {
                $mvLeft = $this->mvLeftCol[1];
            } elseif (isset($this->mvLeftCol[0])) {
                $mvLeft = $this->mvLeftCol[0];
            }
        }
        if ($mbY > 0) {
            $mvTop = $this->mvTopRow[$mbX * 4] ?? null;
        }
        if ($mbY > 0) {
            if ($mbX + 1 < $mbWidth) {
                $mvC = $this->mvTopRow[($mbX + 1) * 4] ?? null;
            }
            if ($mvC === null && $mbX > 0) {
                $mvC = $this->mvTopLeft;
            }
        }

        return $this->predictMvPSkip($mvLeft, $mvTop, $mvC);
    }

    /**
     * 保存16x16宏块MV供后续预测（与解码器saveMvForPrediction一致）
     * mvLeftCol和mvTopRow的4个子块都设为同一个MV
     */
    private function saveMv16x16(int $mbX, int $mvX, int $mvY, int $refIdx): void
    {
        $mv = [$mvX, $mvY, $refIdx];
        // 16x16 分区把同一 MV 写入左列 4 个与上行 4 个子块槽位（4x4 粒度缓存）
        $this->mvLeftCol = [$mv, $mv, $mv, $mv];
        $this->mvTopRow[$mbX * 4 + 0] = $mv;
        $this->mvTopRow[$mbX * 4 + 1] = $mv;
        $this->mvTopRow[$mbX * 4 + 2] = $mv;
        $this->mvTopRow[$mbX * 4 + 3] = $mv;
    }

    // ====================== B 帧（IBBP）宏块编码 ======================

    /**
     * B 帧宏块准备：分别对 L0（过去锚点）/ L1（未来锚点）做运动估计，按 SAD 择优，
     * 再以选中 MV 复用 P 的 MC+残差+色度路径。返回数组第 12 位附带选中的 list。
     * @param string $refL0Y/$refL0U/$refL0V list0（过去）参考平面；$refL1Y/$refL1U/$refL1V list1（未来）参考平面
     * @param int $motionRange 整像素搜索半径
     * @return array 与 preparePMacroblock 相同，[12] 填选中的 list（0 或 1）
     * 依据：8.4.3.2 双向预测（此处退化为单 list 择优）、8.4.1.3 MV 预测。
     */
    public function prepareBMacroblock(
        int $mbX,
        int $mbY,
        string $luma,
        string $curU,
        string $curV,
        string $refL0Y,
        string $refL0U,
        string $refL0V,
        string $refL1Y,
        string $refL1U,
        string $refL1V,
        int $motionRange = 32
    ): array {
        $curFlat = array_values(unpack('C*', $luma));
        $this->bCurFlat = $curFlat;
        // 缓存当前宏块字节数组，供 preparePMacroblock 复用，避免重复 unpack

        // 两次 ME 各自指向对应参考帧的整数缓存（片级已 unpack，避免每宏块重建整帧数组）
        $this->refInts = $this->bRefIntsL0 ?? unpack('C*', $refL0Y);
        // 两次 ME 前分别把 refInts 指向对应参考帧的整数缓存（片级已 unpack）
        [$mv0X, $mv0Y, $sad0] = $this->motionEstimate16x16($curFlat, $refL0Y, $mbX, $mbY, $motionRange);
        $this->refInts = $this->bRefIntsL1 ?? unpack('C*', $refL1Y);
        // 切到 list1 的整数缓存后再做一次 ME
        [$mv1X, $mv1Y, $sad1] = $this->motionEstimate16x16($curFlat, $refL1Y, $mbX, $mbY, $motionRange);

        if ($sad0 <= $sad1) {
            // 按 SAD 择优：较小者作为该宏块的显式单 list 预测
            $list = 0; $mvX = $mv0X; $mvY = $mv0Y; $sad = $sad0;
            $ry = $refL0Y; $ru = $refL0U; $rv = $refL0V;
            $this->refInts = $this->bRefIntsL0 ?? unpack('C*', $refL0Y);
        } else {
            $list = 1; $mvX = $mv1X; $mvY = $mv1Y; $sad = $sad1;
            $ry = $refL1Y; $ru = $refL1U; $rv = $refL1V;
            $this->refInts = $this->bRefIntsL1 ?? unpack('C*', $refL1Y);
        }

        // 复用 P 路径做 MC+残差+重建；forcedMv 已选定，early-skip 与 ME 均跳过。
        // refInts 已指向所选参考平面，保证 mcLumaBlock 使用正确缓存。
        $prepared = $this->preparePMacroblock($mbX, $mbY, $luma, $curU, $curV, $ry, $ru, $rv, $motionRange, [$mvX, $mvY, $sad]);
        // 复用 P 路径完成 MC/残差/重建；forcedMv 已定，early-skip 与 ME 均跳过
        $prepared[12] = $list;
        // [12] 记录选中的 list，写码端据此选 B_L0_16x16 / B_L1_16x16（Table 7-14）
        return $prepared;
    }

    /** B 帧 16x16 宏块写码（CAVLC：mb_skip_run=0 + mb_type(B_L0/B_L1) + mvd + cbp + 残差；CABAC 双后端） */
    // 语法序：mb_skip_run/mb_skip_flag → mb_type(1=B_L0_16x16, 2=B_L1_16x16) →
    // ref_idx_lX → mvd_lX → cbp → mb_qp_delta → 残差（7.3.5）；CABAC 走 9.3。
    // @param int $mbX/$mbY 宏块坐标；array $prepared 为 prepareBMacroblock 的结果
    // @param array &$leftNz/&$topNzLuma/&$topNzCb/&$topNzCr 邻块 nz 缓存
    // @return string 该宏块的写码比特串（CABAC 模式返回空串）
    public function encodeBMacroblock(
        int $mbX,
        int $mbY,
        array $prepared,
        bool $leftAvailable,
        array &$leftNz,
        bool $topAvailable,
        array &$topNzLuma,
        array &$topNzCb,
        array &$topNzCr
    ): string {
        [$mvX, $mvY, , $cbpLuma, $nzCache, $quantResidual] = $prepared;
        // 元组取出：MV、cbp、nz、量化系数；list/dct8 由 [12]/[13] 提供
        $cbpChroma = $prepared[9] ?? 0;
        $chromaDc = $prepared[10] ?? [];
        $chromaAc = $prepared[11] ?? [];
        $list = $prepared[12] ?? 0;
        $dct8 = !empty($prepared[13]);    // T5 inter 8x8DCT（preparePMacroblock 双路决策结果）
        $refIdx = 0;

        $bits = '';
        if (!empty($this->useCabac)) {
            // ---- CABAC 分支：镜像解码端 B 宏块语法（9.3.3.1）----
            // ---- CABAC 写码分支：语法序镜像解码器 decodeCabacBMb（显式 B_L0/B_L1_16x16）----
            $this->cabMbBegin($mbX, $mbY);
            $mbIdx = $this->cabMbIdx;
            $this->picChromaPred[$mbIdx] = 0;
            $this->picIsSkip[$mbIdx] = 0;
            $this->picIsDirectMb[$mbIdx] = 0;
            // 本编码器不产生 B_Skip/B_Direct，故 skip 标志为 0，mb_type 取显式 16x16
            $this->encWriteMbSkip(true, false); // 本编码器不产生 B_Skip/B_Direct
            $this->encWriteBMbTypeInter($list === 0 ? 1 : 2);
            // 显式 inter 分区：不可用邻居 nz 哨兵转 0
            $this->cabFixUnavailNz(false);
            // ref_idx：双 list 均单参考帧（numRefIdxLxActive<=1）解码器不读 bin；
            // 渐进 ref 覆盖仍镜像解码器填 0，保持簿记一致
            $this->cabOvRefBox($list, 0, 0, 4, 4, $refIdx);
            // MVP：与解码器 getB16x16MvPrediction 一致（双 list 独立邻居缓存）
            list($mvpX, $mvpY) = $this->getMvpB16x16($mbX, $mbY, $list, $refIdx);
            $mvdX = $mvX - $mvpX;
            $mvdY = $mvY - $mvpY;
            $this->encWriteMvd($list, 0, 0, $mvdX);
            $this->encWriteMvd($list, 1, 0, $mvdY);
            // mvd 写回邻居簿记，供后续宏块上下文使用
            $this->cabStoreMvd($list, $mbIdx, 0, 0, 4, 4, $mvdX, $mvdY);
            $this->encWriteCbpLuma($cbpLuma);
            $this->encWriteCbpChroma($cbpChroma);
            // cbp_table 在残差写入前赋值（色度 DC 的 CBF 标志位在其上 OR）
            $cbp = $cbpLuma | ($cbpChroma << 4);
            $this->picCbp[$mbIdx] = $cbp;
            $this->cabCbp = $cbp;
            // transform_size_8x8_flag：PPS transform_8x8_mode_flag=1（high）且 cbpLuma 低 4 位
            // 非零时解码器在 qp delta 前读该 bin（CabacDecodingTrait:2180）
            if ($this->transform8x8ModeFlag && ($cbpLuma & 15) !== 0) {
                $this->encDecision(399 + $this->cabNeighborTransformSize(), $dct8 ? 1 : 0);
            }
            if ($cbp !== 0) {
                $this->encWriteQpDelta(0);
            } else {
                $this->cabLastQpDiff = 0;
            }
            $this->encWriteInterResidual(
                $cbpLuma,
                $cbpChroma,
                $quantResidual,
                [array_slice($chromaDc, 0, 4), array_slice($chromaDc, 4, 4)],
                [array_slice($chromaAc, 0, 4), array_slice($chromaAc, 4, 4)],
                0,
                $dct8
            );
            // 同 P 路径：仅 flag bin 真正写入 1 时才置邻居 transform_size 簿记
            $this->encFinishInter($dct8 && ($cbpLuma & 15) !== 0);
        } else {
        $bits .= $this->ue(0); // mb_skip_run = 0（本编码器不使用 B_Skip/B_Direct）
        $bits .= $this->ue($list === 0 ? 1 : 2); // mb_type: 1=B_L0_16x16, 2=B_L1_16x16

        // MVP：与解码器 getB16x16MvPrediction 一致（双 list 独立邻居缓存）
        list($mvpX, $mvpY) = $this->getMvpB16x16($mbX, $mbY, $list, $refIdx);
        $bits .= $this->se($mvX - $mvpX);
        $bits .= $this->se($mvY - $mvpY);

        // CBP（与 P 一致的 Inter 映射表反查）
        static $cbpFullToCode = null;
        if ($cbpFullToCode === null) {
            $interCbpMap = [
                0, 16, 1, 2, 4, 8, 32, 3, 5, 10, 12, 15, 47, 7, 11, 13,
                14, 6, 9, 31, 35, 37, 42, 44, 33, 34, 36, 40, 39, 43, 45, 46,
                17, 18, 20, 24, 19, 21, 26, 28, 23, 27, 29, 30, 22, 25, 38, 41,
            ];
            $cbpFullToCode = array_flip($interCbpMap);
        }
        $cbpFull = ($cbpLuma & 0x0F) | (($cbpChroma & 0x03) << 4);
        $bits .= $this->ue($cbpFullToCode[$cbpFull] ?? 0);
        if ($cbpFull > 0) $bits .= $this->se(0);

        if ($cbpLuma > 0) {
            // 亮度残差：与 P 路径相同的 CAVLC 写码（9.2）
            $blockGroups = [
                [0, 1, 4, 5],
                [2, 3, 6, 7],
                [8, 9, 12, 13],
                [10, 11, 14, 15],
            ];
            for ($blk8 = 0; $blk8 < 4; $blk8++) {
                if (!($cbpLuma & (1 << $blk8))) continue;
                foreach ($blockGroups[$blk8] as $rasterIdx) {
                    $by = (int)($rasterIdx / 4);
                    $bx = $rasterIdx % 4;
                    $ac = $this->scan4x4DcAc($quantResidual[$rasterIdx]);
                    $acNc = $this->computeNC($rasterIdx, $mbX, $bx, $by, $leftAvailable, $leftNz, $topAvailable, $topNzLuma, $nzCache);
                    $bits .= $this->writeBlockResidualCavlc($ac, 15, false, $acNc);
                }
            }
        }

        if ($cbpChroma > 0) {
            // 色度残差：DC → AC，顺序与解码端读取顺序一致
            $bits .= $this->writeBlockResidualCavlc(array_slice($chromaDc, 0, 4), 3, true, -1);
            $bits .= $this->writeBlockResidualCavlc(array_slice($chromaDc, 4, 4), 3, true, -1);
            if ($cbpChroma >= 2) {
                for ($i = 0; $i < 4; $i++) {
                    $acNc = $this->computeNC(16 + $i, $mbX, $i % 2, intdiv($i, 2), $leftAvailable, $leftNz, $topAvailable, $topNzCb, $nzCache);
                    $bits .= $this->writeBlockResidualCavlc($this->scan4x4Ac($chromaAc[$i]), 14, false, $acNc);
                }
                for ($i = 0; $i < 4; $i++) {
                    $acNc = $this->computeNC(20 + $i, $mbX, $i % 2, intdiv($i, 2), $leftAvailable, $leftNz, $topAvailable, $topNzCr, $nzCache);
                    $bits .= $this->writeBlockResidualCavlc($this->scan4x4Ac($chromaAc[4 + $i]), 14, false, $acNc);
                }
            }
        }

        } // end CAVLC

        // 更新亮度邻居 nz
        // 邻块 nz 簿记（与 P 路径一致）
        for ($by = 0; $by < 4; $by++) {
            $leftNz[$by] = $nzCache[$by * 4 + 3];
        }
        for ($bx = 0; $bx < 4; $bx++) {
            $topBlkX = $mbX * 4 + $bx;
            if ($topBlkX < count($topNzLuma)) $topNzLuma[$topBlkX] = $nzCache[$bx + 12];
        }
        // 更新色度邻居 nz
        for ($by = 0; $by < 2; $by++) {
            $cbBlk = $by * 2 + 1;
            $leftNz[4 + $by] = $nzCache[16 + $cbBlk];
            $leftNz[6 + $by] = $nzCache[20 + $cbBlk];
        }
        $topCbx0 = $mbX * 2;
        $topCbx1 = $mbX * 2 + 1;
        if ($topCbx1 < count($topNzCb)) {
            $topNzCb[$topCbx0] = $nzCache[18];
            $topNzCb[$topCbx1] = $nzCache[19];
            $topNzCr[$topCbx0] = $nzCache[22];
            $topNzCr[$topCbx1] = $nzCache[23];
        }

        // 保存 B 双 list MV 供后续宏块预测（未使用 list 填 [0,0,-1]）
        // 双 list MV 缓存：未使用的 list 填 [0,0,-1] 表示不可用
        $this->saveBListMvs16x16($mbX, $mvX, $mvY, $refIdx, $list);

        return $bits;
    }

    /** B 帧 16x16 宏块 MVP：镜像解码器 getB16x16MvPrediction（双 list 独立缓存） */
    // 参数：$mbX/$mbY 宏块坐标，$list 为 0/1，$refIdx 为当前参考帧索引；返回 [mvpX, mvpY]。
    private function getMvpB16x16(int $mbX, int $mbY, int $list, int $refIdx): array
    {
        $mbWidth = $this->picWidthInMbs;
        // 左邻：仅取 list 对应缓存（bMvLeftCol[list][0]）
        $mvLeft = null;
        $mvTop = null;
        $mvC = null;
        if ($mbX > 0 && isset($this->bMvLeftCol[$list][0])) {
            $mvLeft = $this->bMvLeftCol[$list][0];
        }
        if ($mbY > 0) {
            // 上邻与右上邻同样按 list 分开存放；C 不可用时回退左上 D
            $mvTop = $this->bMvTopRow[$list][$mbX * 4] ?? null;
            if ($mbX + 1 < $mbWidth) {
                $mvC = $this->bMvTopRow[$list][($mbX + 1) * 4] ?? null;
            }
            if ($mvC === null && $mbX > 0) {
                $mvC = $this->bMvTopLeft[$list] ?? null;
            }
        }
        return $this->predictMvP16x16($mvLeft, $mvTop, $mvC, $refIdx);
    }

    /** 保存 B 双 list MV（镜像解码器 saveBListMvs；未使用 list 统一 [0,0,-1]） */
    // 参数：$mbX 宏块列号，$mvX/$mvY/$refIdx 为选中 MV，$list 为生效的 list；无返回值。
    private function saveBListMvs16x16(int $mbX, int $mvX, int $mvY, int $refIdx, int $list): void
    {
        $used = [$mvX, $mvY, $refIdx];
        // 当前宏块只属于一个 list，另一 list 用 [0,0,-1] 标记不可用
        $unused = [0, 0, -1];
        $l0 = $list === 0 ? $used : $unused;
        $l1 = $list === 1 ? $used : $unused;
        if (!isset($this->bMvLeftCol[0])) $this->bMvLeftCol = [[null, null, null, null], [null, null, null, null]];
        if (!isset($this->bMvTopRow[0])) $this->bMvTopRow = [[], []];
        $this->bMvLeftCol[0] = [$l0, $l0, $l0, $l0];
        $this->bMvLeftCol[1] = [$l1, $l1, $l1, $l1];
        for ($i = 0; $i < 4; $i++) {
            // 4 个子块槽位填同一 MV（4x4 粒度缓存）
            $this->bMvTopRow[0][$mbX * 4 + $i] = $l0;
            $this->bMvTopRow[1][$mbX * 4 + $i] = $l1;
        }
    }

    /**
     * 色度运动补偿（与解码器mcChroma一致的1/8像素双线性插值）
     * chromaMV数值与luma MV相同(1/4像素单位)，解释为1/8像素单位
     */
    private function mcChromaBlock(string $refPlane, int $chromaRefX, int $chromaRefY, int $chromaW, int $chromaH): array
    {
        // 8x8 色度预测块；参考坐标以 1/8 像素为单位分解为整数部分 + 分数部分
        $pred = array_fill(0, 64, 0);
        $fracX = $chromaRefX & 7;
        $fracY = $chromaRefY & 7;
        $intX = $chromaRefX >> 3;
        $intY = $chromaRefY >> 3;

        // 坐标预先钳位，避免逐像素 max/min 与方法调用
        $bx = [];
        $bx1 = [];
        for ($i = 0; $i < 8; $i++) {
            $v = $intX + $i;
            $bx[$i] = $v < 0 ? 0 : ($v >= $chromaW ? $chromaW - 1 : $v);
            $v1 = $v + 1;
            $bx1[$i] = $v1 < 0 ? 0 : ($v1 >= $chromaW ? $chromaW - 1 : $v1);
        }
        $by = [];
        $by1 = [];
        for ($j = 0; $j < 8; $j++) {
            $v = $intY + $j;
            $by[$j] = $v < 0 ? 0 : ($v >= $chromaH ? $chromaH - 1 : $v);
            $v1 = $v + 1;
            $by1[$j] = $v1 < 0 ? 0 : ($v1 >= $chromaH ? $chromaH - 1 : $v1);
        }
        // 预钳位：越界坐标夹到参考平面边界（8.4.2.2.2 的边缘扩展）

        $w00 = (8 - $fracX) * (8 - $fracY);
        $w10 = $fracX * (8 - $fracY);
        $w01 = (8 - $fracX) * $fracY;
        $w11 = $fracX * $fracY;
        // 双线性权重，分母 64（8x8 相位网格），四邻点加权后统一舍入
        for ($j = 0; $j < 8; $j++) {
            $row0 = $by[$j] * $chromaW;
            $row1 = $by1[$j] * $chromaW;
            for ($i = 0; $i < 8; $i++) {
                $x0 = $bx[$i];
                $x1 = $bx1[$i];
                $a00 = ord($refPlane[$row0 + $x0]);
                $a10 = ord($refPlane[$row0 + $x1]);
                $a01 = ord($refPlane[$row1 + $x0]);
                $a11 = ord($refPlane[$row1 + $x1]);

                $val = ($w00 * $a00 + $w10 * $a10 + $w01 * $a01 + $w11 * $a11 + 32) >> 6;
                // (Σ w*a + 32) >> 6 为 6 位定点舍入
                $pred[$j * 8 + $i] = $val < 0 ? 0 : ($val > 255 ? 255 : $val);
            }
        }
        return $pred;
    }

    /**
     * 从参考帧获取像素，越界时钳位到边缘（与解码器getRefPixel一致）
     */
    private function getClampedPixel(string $plane, int $x, int $y, int $w, int $h): int
    {
        $x = max(0, min($w - 1, $x));
        $y = max(0, min($h - 1, $y));
        // 越界坐标夹到 [0,w-1]/[0,h-1]，即边缘像素扩展（8.4.2.2.1）
        return ord($plane[$y * $w + $x]);
    }

    /**
     * 亮度运动补偿 - 1/4 像素精度（与参考实现一致）
     * 对角线方向：中间H数组不移位不裁剪，最终一次性移位10位
     * 位置分解：MV 低 2 位为 1/4 像素相位，高位移位得整数像素坐标（8.4.2.2.1）。
     * 6-tap 系数 1,-5,20,20,-5,1；水平/垂直中间值分别舍入，对角相位再平均。
     */
    private function mcLumaBlock(string $refPlane, int $refX, int $refY, int $w, int $h): array
    {
        $pred = array_fill(0, 256, 0);

        $fracX = $refX & 3;
        $fracY = $refY & 3;
        $intX = $refX >> 2;
        $intY = $refY >> 2;
        // fracX/fracY ∈ 0..3 表示 1/4 像素相位；intX/intY 为整数像素坐标

        // 参考帧整数数组（unpack 为 1 基下标），与运动估计阶段共用
        $ref = $this->refInts ?? unpack('C*', $refPlane);
        // refInts 为 1 基下标数组（unpack('C*')），故行内偏移一律 +1

        // 预钳位坐标：键 = 相对位移 + 2
        $cx = [];
        for ($d = -2; $d <= 19; $d++) {
            $v = $intX + $d;
            $cx[$d + 2] = $v < 0 ? 0 : ($v >= $w ? $w - 1 : $v);
        }
        $cy = [];
        for ($d = -2; $d <= 22; $d++) {
            $v = $intY + $d;
            $cy[$d + 2] = $v < 0 ? 0 : ($v >= $h ? $h - 1 : $v);
        }
        // 预钳位窗口：水平 -2..19、垂直 -2..22，覆盖 6-tap 所需 ±2/±3 邻域

        if ($fracX === 0 && $fracY === 0) {
            // 整像素对齐（相位 0,0）：直接拷贝，无插值误差
            for ($j = 0; $j < 16; $j++) {
                $row = $cy[$j + 2] * $w;
                for ($i = 0; $i < 16; $i++) {
                    $pred[$j * 16 + $i] = $ref[$row + $cx[$i + 2] + 1];
                }
            }
            return $pred;
        }

        $hRows = 0;
        $hStart = 0;
        if ($fracX !== 0) {
            if ($fracY === 0) {
                $hRows = 16;
                $hStart = 0;
            } else {
                $hRows = 21;
                $hStart = -2;
            }
        }

        $vCols = $fracY !== 0 ? ($fracX === 0 ? 16 : 17) : 0;
        // 由相位决定中间数组规模：H 行数与 V 列数取决于是否需要水平/垂直滤波

        $H = null;
        $Hfull = null;
        if ($fracX !== 0) {
            if ($fracY === 0) {
                $H = array_fill(0, $hRows, array_fill(0, 16, 0));
            } else {
                $Hfull = array_fill(0, $hRows, array_fill(0, 16, 0));
                $H = array_fill(0, $hRows, array_fill(0, 16, 0));
            }
            for ($j = $hStart; $j < $hStart + $hRows; $j++) {
                $row = $cy[$j + 2] * $w;
                $jr = $j - $hStart;
                for ($i = 0; $i < 16; $i++) {
                    $fullVal = $ref[$row + $cx[$i] + 1]
                        - 5 * $ref[$row + $cx[$i + 1] + 1]
                        + 20 * $ref[$row + $cx[$i + 2] + 1]
                        + 20 * $ref[$row + $cx[$i + 3] + 1]
                        - 5 * $ref[$row + $cx[$i + 4] + 1]
                        + $ref[$row + $cx[$i + 5] + 1];
                    // 水平 6-tap：(1,-5,20,20,-5,1)·p 得未舍入的中间值 fullVal
                    if ($fracY === 0) {
                        $hVal = ($fullVal + 16) >> 5;
                        // 仅水平相位时立即 (x+16)>>5 舍入并钳位（8.4.2.2.1）
                        $H[$jr][$i] = $hVal < 0 ? 0 : ($hVal > 255 ? 255 : $hVal);
                    } else {
                        // 同时保留 Hfull（未舍入）供对角相位复用，H 为已舍入值
                        $Hfull[$jr][$i] = $fullVal;
                        $hVal = ($fullVal + 16) >> 5;
                        $H[$jr][$i] = $hVal < 0 ? 0 : ($hVal > 255 ? 255 : $hVal);
                    }
                }
            }
        }

        $V = null;
        if ($fracY !== 0) {
            $V = array_fill(0, 16, array_fill(0, $vCols, 0));
            for ($j = 0; $j < 16; $j++) {
                $r0 = $cy[$j] * $w;
                $r1 = $cy[$j + 1] * $w;
                $r2 = $cy[$j + 2] * $w;
                $r3 = $cy[$j + 3] * $w;
                $r4 = $cy[$j + 4] * $w;
                $r5 = $cy[$j + 5] * $w;
                for ($i = 0; $i < $vCols; $i++) {
                    $col = $cx[$i + 2];
                    $fullVal = $ref[$r0 + $col + 1]
                        - 5 * $ref[$r1 + $col + 1]
                        + 20 * $ref[$r2 + $col + 1]
                        + 20 * $ref[$r3 + $col + 1]
                        - 5 * $ref[$r4 + $col + 1]
                        + $ref[$r5 + $col + 1];
                    $hVal = ($fullVal + 16) >> 5;
                    // 垂直 6-tap，同样 (x+16)>>5 舍入并钳位
                    $V[$j][$i] = $hVal < 0 ? 0 : ($hVal > 255 ? 255 : $hVal);
                }
            }
        }

        $C = null;
        if ($fracX !== 0 && $fracY !== 0) {
            $C = array_fill(0, 16, array_fill(0, 16, 0));
            for ($j = 0; $j < 16; $j++) {
                for ($i = 0; $i < 16; $i++) {
                    $px0 = $Hfull[$j][$i];
                    $px1 = $Hfull[$j + 1][$i];
                    $px2 = $Hfull[$j + 2][$i];
                    $px3 = $Hfull[$j + 3][$i];
                    $px4 = $Hfull[$j + 4][$i];
                    $px5 = $Hfull[$j + 5][$i];
                    // 对角：对 Hfull 再做垂直 6-tap，一次性 (x+512)>>10 舍入（避免二次舍入误差）
                    $fullVal = $px0 - 5 * $px1 + 20 * $px2 + 20 * $px3 - 5 * $px4 + $px5;
                    $hVal = ($fullVal + 512) >> 10;
                    $C[$j][$i] = $hVal < 0 ? 0 : ($hVal > 255 ? 255 : $hVal);
                }
            }
        }

        // 相位组合：fracY=0 时用 H 与整数像素或相邻整数像素平均
        if ($fracY === 0) {
            for ($j = 0; $j < 16; $j++) {
                $row = $cy[$j + 2] * $w;
                for ($i = 0; $i < 16; $i++) {
                    // fracX=1 → (I+H+1)>>1；2 → H；3 → (H+I(+1)+1)>>1
                    if ($fracX === 1) {
                        $I = $ref[$row + $cx[$i + 2] + 1];
                        $pred[$j * 16 + $i] = ($I + $H[$j][$i] + 1) >> 1;
                    } elseif ($fracX === 2) {
                        $pred[$j * 16 + $i] = $H[$j][$i];
                    } else {
                        $I1 = $ref[$row + $cx[$i + 3] + 1];
                        $pred[$j * 16 + $i] = ($H[$j][$i] + $I1 + 1) >> 1;
                    }
                }
            }
        } elseif ($fracX === 0) {
            // 仅垂直相位：V 与整数像素或相邻行平均
            for ($j = 0; $j < 16; $j++) {
                $row = $cy[$j + 2] * $w;
                $row1 = $cy[$j + 3] * $w;
                for ($i = 0; $i < 16; $i++) {
                    // fracY=1 → (I+V+1)>>1；2 → V；3 → (V+I(+1)+1)>>1
                    $col = $cx[$i + 2];
                    if ($fracY === 1) {
                        $I = $ref[$row + $col + 1];
                        $pred[$j * 16 + $i] = ($I + $V[$j][$i] + 1) >> 1;
                    } elseif ($fracY === 2) {
                        $pred[$j * 16 + $i] = $V[$j][$i];
                    } else {
                        $I1 = $ref[$row1 + $col + 1];
                        $pred[$j * 16 + $i] = ($V[$j][$i] + $I1 + 1) >> 1;
                    }
                }
            }
        } elseif ($fracX === 2) {
            // 水平相位 2（H 已为半像素列），再与垂直相位组合
            for ($j = 0; $j < 16; $j++) {
                for ($i = 0; $i < 16; $i++) {
                    // fracY=1 → (H[j+2]+C+1)>>1；2 → C；3 → (C+H[j+3]+1)>>1
                    if ($fracY === 1) {
                        $pred[$j * 16 + $i] = ($H[$j + 2][$i] + $C[$j][$i] + 1) >> 1;
                    } elseif ($fracY === 2) {
                        $pred[$j * 16 + $i] = $C[$j][$i];
                    } else {
                        $pred[$j * 16 + $i] = ($C[$j][$i] + $H[$j + 3][$i] + 1) >> 1;
                    }
                }
            }
        } elseif ($fracY === 2) {
            // 垂直相位 2（C 为 H 再垂直滤波的结果），与水平相位组合
            for ($j = 0; $j < 16; $j++) {
                for ($i = 0; $i < 16; $i++) {
                    // fracX=1 → (V+C+1)>>1；3 → (C+V[i+1]+1)>>1
                    if ($fracX === 1) {
                        $pred[$j * 16 + $i] = ($V[$j][$i] + $C[$j][$i] + 1) >> 1;
                    } else {
                        $pred[$j * 16 + $i] = ($C[$j][$i] + $V[$j][$i + 1] + 1) >> 1;
                    }
                }
            }
        } else {
            // 其余相位组合：取对应 H 行与 V 列平均
            $hIdx = ($fracY === 1) ? 2 : 3;
            $vIdx = ($fracX === 3) ? 1 : 0;
            // $hIdx/$vIdx 选出参与平均的 H 行与 V 列；(a+b+1)>>1 为 1/4、3/4 相位的舍入均值
            for ($j = 0; $j < 16; $j++) {
                for ($i = 0; $i < 16; $i++) {
                    $pred[$j * 16 + $i] = ($H[$j + $hIdx][$i] + $V[$j][$i + $vIdx] + 1) >> 1;
                }
            }
        }

        return $pred;
    }

    /**
     * 通用区间钳位
     * @param int $val 输入值；$min/$max 为下/上界
     * @return int 落在 [$min,$max] 内的整数
     */
    private function clampInt(int $val, int $min, int $max): int
    {
        return max($min, min($max, $val));
    }

    /**
     * 像素值钳位到 8bit 范围
     * @param int $val 输入值
     * @return int [0,255] 内的整数
     */
    private function clip255Int(int $val): int
    {
        return max(0, min(255, $val));
    }
}
