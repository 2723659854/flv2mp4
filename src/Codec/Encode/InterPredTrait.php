<?php

namespace Xiaosongshu\Flv2mp4\Codec\Encode;

/**
 * @purpose 帧间预测
 * @author yanglong
 */
trait InterPredTrait
{
    public function preparePMacroblock(
        int $mbX,
        int $mbY,
        string $luma,
        string $refYPlane,
        string $refUPlane,
        string $refVPlane,
        int $motionRange = 32
    ): array {
        $curFlat = array_values(unpack('C*', $luma));

        // === Tier1 early-skip（数学保证，零画质风险）===
        // 单次遍历计算 16 个 4x4 块在整数 (0,0) 的 SAD；全部 ≤ QP 死区阈值时，
        // 残差经 DCT+Inter 量化必定全为 0（见 MotionTrait::zeroResidualBlockSad）。
        // 此时直接产出 MV=(0,0)、cbp=0、与 (0,0) 运动补偿完全一致的重建帧，
        // 跳过整/半/四像素运动搜索与全部 DCT/量化。主进程仍按 skipMVP 决定 P_Skip，
        // 故与"完整搜索恰好得到同结果"位级等价。
        if ($this->earlySkip) {
            $zeroT = $this->zeroResidualBlockSad($this->qp);
            $stride = $this->mbAlignedWidth;
            $ox = $mbX * 16;
            $oy = $mbY * 16;
            $blockSads = array_fill(0, 16, 0);
            $alive = 16;
            $totalSad = 0;
            for ($y = 0; $y < 16; $y++) {
                $rowBase = ($oy + $y) * $stride + $ox;
                $biBase = ($y >> 2) * 4;
                for ($x = 0; $x < 16; $x++) {
                    $diff = $curFlat[$y * 16 + $x] - ord($refYPlane[$rowBase + $x]);
                    if ($diff < 0) $diff = -$diff;
                    $totalSad += $diff;
                    $bi = $biBase + ($x >> 2);
                    if ($blockSads[$bi] <= $zeroT) {
                        $blockSads[$bi] += $diff;
                        if ($blockSads[$bi] > $zeroT) --$alive;
                    }
                }
                if ($alive === 0) break;
            }
            if ($alive === 16) {
                $reconY = '';
                for ($y = 0; $y < 16; $y++) {
                    $reconY .= substr($refYPlane, ($oy + $y) * $stride + $ox, 16);
                }
                $chromaW = intdiv($stride, 2);
                $cx = $mbX * 8;
                $cy = $mbY * 8;
                $reconU = '';
                $reconV = '';
                for ($y = 0; $y < 8; $y++) {
                    $reconU .= substr($refUPlane, ($cy + $y) * $chromaW + $cx, 8);
                    $reconV .= substr($refVPlane, ($cy + $y) * $chromaW + $cx, 8);
                }
                return [
                    0, 0, $totalSad, 0,
                    array_fill(0, 24, 0),
                    array_fill(0, 16, array_fill(0, 16, 0)),
                    $reconY, $reconU, $reconV,
                ];
            }
        }

        [$mvX, $mvY, $sad] = $this->motionEstimate16x16($curFlat, $refYPlane, $mbX, $mbY, $motionRange);
        $refX = $mbX * 64 + $mvX;
        $refY = $mbY * 64 + $mvY;
        $predBlock = $this->mcLumaBlock($refYPlane, $refX, $refY, $this->mbAlignedWidth, $this->mbAlignedHeight);
        $nzCache = array_fill(0, 24, 0);
        $cbpLuma = 0;
        $quantResidual = [];

        for ($by = 0; $by < 4; $by++) {
            for ($bx = 0; $bx < 4; $bx++) {
                $blkIdx = $by * 4 + $bx;
                $res = [];
                for ($y = 0; $y < 4; $y++) {
                    $row = $by * 4 + $y;
                    for ($x = 0; $x < 4; $x++) {
                        $res[] = $curFlat[$row * 16 + $bx * 4 + $x] - $predBlock[$row * 16 + $bx * 4 + $x];
                    }
                }
                [$quantBlock, $nz] = $this->quantizeFlatInter($this->dctFlat($res));
                $quantResidual[$blkIdx] = $quantBlock;
                $nzCache[$blkIdx] = min(15, $nz);
                if ($nz > 0) $cbpLuma |= 1 << (intdiv($by, 2) * 2 + intdiv($bx, 2));
            }
        }
        for ($blkIdx = 0; $blkIdx < 16; $blkIdx++) {
            $block8x8Idx = intdiv(intdiv($blkIdx, 4), 2) * 2 + intdiv($blkIdx % 4, 2);
            if (!($cbpLuma & (1 << $block8x8Idx))) $nzCache[$blkIdx] = 0;
        }

        $reconY = str_repeat("\0", 256);
        for ($by = 0; $by < 4; $by++) {
            for ($bx = 0; $bx < 4; $bx++) {
                $blkIdx = $by * 4 + $bx;
                $block8x8Idx = intdiv($by, 2) * 2 + intdiv($bx, 2);
                $idctResult = null;
                if ($cbpLuma & (1 << $block8x8Idx)) {
                    $idctResult = $this->idctFlat($this->dequantize4x4($quantResidual[$blkIdx], 0, $this->qp));
                }
                for ($y = 0; $y < 4; $y++) {
                    for ($x = 0; $x < 4; $x++) {
                        $value = $predBlock[($by * 4 + $y) * 16 + $bx * 4 + $x] + ($idctResult[$y * 4 + $x] ?? 0);
                        $reconY[($by * 4 + $y) * 16 + $bx * 4 + $x] = chr(max(0, min(255, $value)));
                    }
                }
            }
        }

        $chromaW = intdiv($this->mbAlignedWidth, 2);
        $chromaH = intdiv($this->mbAlignedHeight, 2);
        $chromaRefX = $mbX * 64 + $mvX;
        $chromaRefY = $mbY * 64 + $mvY;
        $cbPred = $this->mcChromaBlock($refUPlane, $chromaRefX, $chromaRefY, $chromaW, $chromaH);
        $crPred = $this->mcChromaBlock($refVPlane, $chromaRefX, $chromaRefY, $chromaW, $chromaH);
        $reconU = str_repeat("\0", 64);
        $reconV = str_repeat("\0", 64);
        for ($y = 0; $y < 8; $y++) for ($x = 0; $x < 8; $x++) {
            $reconU[$y * 8 + $x] = chr($cbPred[$y * 8 + $x]);
            $reconV[$y * 8 + $x] = chr($crPred[$y * 8 + $x]);
        }

        return [$mvX, $mvY, $sad, $cbpLuma, $nzCache, $quantResidual, $reconY, $reconU, $reconV];
    }

    /**
     * 编码P帧宏块（P_16x16模式）
     * 包含运动估计、MVP预测、MVD编码、残差编码
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

        $prepared = $this->motionWorkerResults[$mbY * $this->picWidthInMbs + $mbX] ?? null;
        if ($prepared === null) {
            throw new \RuntimeException("缺少 P 宏块 Worker 结果 ({$mbX},{$mbY})");
        }
        [$mvX, $mvY, $sad, $cbpLuma, $nzCache, $quantResidual, $workerReconY, $workerReconU, $workerReconV] = $prepared;
        //$reconStride = $this->mbAlignedWidth;

        // === 计算P_Skip的MVP（与解码器predictMvPSkip一致） ===
        // P_Skip的MV = skipMVP，解码器用此MV做MC
        list($skipMvpX, $skipMvpY) = $this->getMvpPSkip($mbX, $mbY);

        // 色度参考帧尺寸（使用mbAligned尺寸，与I帧重建存储格式一致）
        $chromaW = intdiv($this->mbAlignedWidth, 2);
        //$chromaH = intdiv($this->mbAlignedHeight, 2);
        $reconStride = $this->mbAlignedWidth;

        // P_Skip条件：cbpLuma=0 且 MV等于skipMVP（MVD=0）
        // 这样解码器用MV=skipMVP做MC，与编码器本地解码一致
        if ($cbpLuma == 0 && $mvX == $skipMvpX && $mvY == $skipMvpY) {
            $this->lastMbWasSkip = true;

            // 更新邻居nz缓存
            for ($by = 0; $by < 4; $by++) {
                $leftNz[$by] = 0;
            }
            for ($bx = 0; $bx < 4; $bx++) {
                $topBlkX = $mbX * 4 + $bx;
                if ($topBlkX < count($topNzLuma)) {
                    $topNzLuma[$topBlkX] = 0;
                }
            }

            // 保存MV供后续宏块预测（MV=skipMVP, refIdx=0）
            $this->saveMv16x16($mbX, $skipMvpX, $skipMvpY, 0);

            // 帧流水线：整帧 recon 已在派发下一帧前由 worker 结果预拼，无需逐 MB 拷贝
            if (!$this->reconPreassembled) {
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

        // P_L0_16x16模式: mb_type = 0 in P slice (1 partition, 1 MV)
        $bits .= $this->ue(0); // mb_type = 0 for P_L0_16x16

        // 运动向量预测(MVP) - 使用与解码器一致的predictMvP16x16
        $refIdx = 0;
        list($mvpX, $mvpY) = $this->getMvpP16x16($mbX, $mbY, $refIdx);

        // MVD = MV - MVP (1/4像素单位)
        $mvdX = $mvX - $mvpX;
        $mvdY = $mvY - $mvpY;
        $bits .= $this->se($mvdX);
        $bits .= $this->se($mvdY);

        // CBP编码（P帧使用Inter映射表）
        // Inter模式CBP映射表 (codeNum -> cbp)，必须与解码器GOLOMB_TO_INTER_CBP完全一致
        $interCbpMap = [
            0, 16, 1, 2, 4, 8, 32, 3, 5, 10, 12, 15, 47, 7, 11, 13,
            14, 6, 9, 31, 35, 37, 42, 44, 33, 34, 36, 40, 39, 43, 45, 46,
            17, 18, 20, 24, 19, 21, 26, 28, 23, 27, 29, 30, 22, 25, 38, 41,
        ];
        // 查找cbp对应的codeNum
        $cbpFull = $cbpLuma;
        $cbpCode = array_search($cbpFull, $interCbpMap);
        if ($cbpCode === false) $cbpCode = 0;
        $bits .= $this->ue($cbpCode);

        // mb_qp_delta
        if ($cbpLuma > 0) {
            $bits .= $this->se(0);
        }

        // 编码残差（按8x8块分组，仅编码cbpLuma对应位为1的块）
        if ($cbpLuma > 0) {
            // 每个8x8块包含4个4x4子块（按scan4顺序排列）
            $blockGroups = [
                [0, 1, 4, 5],    // 8x8 block 0 (top-left)
                [2, 3, 6, 7],    // 8x8 block 1 (top-right)
                [8, 9, 12, 13],  // 8x8 block 2 (bottom-left)
                [10, 11, 14, 15],// 8x8 block 3 (bottom-right)
            ];
            for ($blk8 = 0; $blk8 < 4; $blk8++) {
                if (!($cbpLuma & (1 << $blk8))) {
                    continue;
                }
                foreach ($blockGroups[$blk8] as $rasterIdx) {
                    $by = (int)($rasterIdx / 4);
                    $bx = $rasterIdx % 4;

                    $ac = $this->scan4x4DcAc($quantResidual[$rasterIdx]);
                    $acNc = $this->computeNC($rasterIdx, $mbX, $bx, $by, $leftAvailable, $leftNz, $topAvailable, $topNzLuma, $nzCache);
                    $bits .= $this->writeBlockResidualCavlc($ac, 15, false, $acNc);
                }
            }
        }

        // 更新邻居nz缓存
        for ($by = 0; $by < 4; $by++) {
            $leftNz[$by] = $nzCache[$by * 4 + 3];
        }
        for ($bx = 0; $bx < 4; $bx++) {
            $topBlkX = $mbX * 4 + $bx;
            if ($topBlkX < count($topNzLuma)) {
                $topNzLuma[$topBlkX] = $nzCache[$bx + 12];
            }
        }

        // 保存当前MV供后续宏块预测（与解码器saveMvForPrediction一致）
        $this->saveMv16x16($mbX, $mvX, $mvY, $refIdx);

        if (!$this->reconPreassembled) {
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
     */
    private function medianInt(int $a, int $b, int $c): int
    {
        $min = min($a, $b, $c);
        $max = max($a, $b, $c);
        return $a + $b + $c - $min - $max;
    }

    /**
     * P帧16x16宏块运动向量预测 (H.264 8.4.1.3节)
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

        $mvA = $aAvail ? [$mvLeft[0], $mvLeft[1]] : [0, 0];
        $mvB = $bAvail ? [$mvTop[0], $mvTop[1]] : [0, 0];
        $mvC = $cAvail ? [$mvTopRight[0], $mvTopRight[1]] : [0, 0];

        $refA = $aAvail ? $mvLeft[2] : -1;
        $refB = $bAvail ? $mvTop[2] : -1;
        $refC = $cAvail ? $mvTopRight[2] : -1;

        $matchCount = 0;
        if ($refA === $currRefIdx) $matchCount++;
        if ($refB === $currRefIdx) $matchCount++;
        if ($refC === $currRefIdx) $matchCount++;

        if ($matchCount > 1) {
            return [
                $this->medianInt($mvA[0], $mvB[0], $mvC[0]),
                $this->medianInt($mvA[1], $mvB[1], $mvC[1]),
            ];
        } elseif ($matchCount === 1) {
            if ($refA === $currRefIdx) return $mvA;
            if ($refB === $currRefIdx) return $mvB;
            return $mvC;
        } else {
            if (!$bAvail && !$cAvail && $aAvail) {
                return $mvA;
            }
            return [
                $this->medianInt($mvA[0], $mvB[0], $mvC[0]),
                $this->medianInt($mvA[1], $mvB[1], $mvC[1]),
            ];
        }
    }

    /**
     * P_Skip运动向量预测 (H.264 8.4.1.1节)
     * 特殊快速路径（与FFmpeg pred_pskip_motion一致，与解码器完全一致）：
     * - 如果A（左邻居）完全不存在（帧边界外，null）→ 返回(0,0)
     * - 如果B（上邻居）完全不存在（帧边界外，null）→ 返回(0,0)
     * - 如果A是Inter宏块且ref=0、MV=(0,0) → 返回(0,0)
     * - 如果B是Inter宏块且ref=0、MV=(0,0) → 返回(0,0)
     * - 否则使用与P_16x16相同的中值预测逻辑
     */
    private function predictMvPSkip(?array $mvLeft, ?array $mvTop, ?array $mvTopRight): array
    {
        if ($mvLeft === null || $mvTop === null) {
            return [0, 0];
        }

        $aZero = ($mvLeft[2] === 0 && $mvLeft[0] === 0 && $mvLeft[1] === 0);
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

        $mvLeft = null;
        $mvTop = null;
        $mvC = null;

        if ($mbX > 0) {
            if (isset($this->mvLeftCol[1])) {
                $mvLeft = $this->mvLeftCol[1];
            } elseif (isset($this->mvLeftCol[0])) {
                $mvLeft = $this->mvLeftCol[0];
            }
        }
        if ($mbY > 0) {
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

        $mvLeft = null;
        $mvTop = null;
        $mvC = null;

        if ($mbX > 0) {
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
        $this->mvLeftCol = [$mv, $mv, $mv, $mv];
        $this->mvTopRow[$mbX * 4 + 0] = $mv;
        $this->mvTopRow[$mbX * 4 + 1] = $mv;
        $this->mvTopRow[$mbX * 4 + 2] = $mv;
        $this->mvTopRow[$mbX * 4 + 3] = $mv;
    }

    /**
     * 色度运动补偿（与解码器mcChroma一致的1/8像素双线性插值）
     * chromaMV数值与luma MV相同(1/4像素单位)，解释为1/8像素单位
     */
    private function mcChromaBlock(string $refPlane, int $chromaRefX, int $chromaRefY, int $chromaW, int $chromaH): array
    {
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

        $w00 = (8 - $fracX) * (8 - $fracY);
        $w10 = $fracX * (8 - $fracY);
        $w01 = (8 - $fracX) * $fracY;
        $w11 = $fracX * $fracY;
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
        return ord($plane[$y * $w + $x]);
    }

    /**
     * 亮度运动补偿 - 1/4 像素精度（与FFmpeg实现一致）
     * 对角线方向：中间H数组不移位不裁剪，最终一次性移位10位
     */
    private function mcLumaBlock(string $refPlane, int $refX, int $refY, int $w, int $h): array
    {
        $pred = array_fill(0, 256, 0);

        $fracX = $refX & 3;
        $fracY = $refY & 3;
        $intX = $refX >> 2;
        $intY = $refY >> 2;

        // 参考帧整数数组（unpack 为 1 基下标），与运动估计阶段共用
        $ref = $this->refInts ?? unpack('C*', $refPlane);

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

        if ($fracX === 0 && $fracY === 0) {
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
                    if ($fracY === 0) {
                        $hVal = ($fullVal + 16) >> 5;
                        $H[$jr][$i] = $hVal < 0 ? 0 : ($hVal > 255 ? 255 : $hVal);
                    } else {
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
                    $fullVal = $px0 - 5 * $px1 + 20 * $px2 + 20 * $px3 - 5 * $px4 + $px5;
                    $hVal = ($fullVal + 512) >> 10;
                    $C[$j][$i] = $hVal < 0 ? 0 : ($hVal > 255 ? 255 : $hVal);
                }
            }
        }

        if ($fracY === 0) {
            for ($j = 0; $j < 16; $j++) {
                $row = $cy[$j + 2] * $w;
                for ($i = 0; $i < 16; $i++) {
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
            for ($j = 0; $j < 16; $j++) {
                $row = $cy[$j + 2] * $w;
                $row1 = $cy[$j + 3] * $w;
                for ($i = 0; $i < 16; $i++) {
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
            for ($j = 0; $j < 16; $j++) {
                for ($i = 0; $i < 16; $i++) {
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
            for ($j = 0; $j < 16; $j++) {
                for ($i = 0; $i < 16; $i++) {
                    if ($fracX === 1) {
                        $pred[$j * 16 + $i] = ($V[$j][$i] + $C[$j][$i] + 1) >> 1;
                    } else {
                        $pred[$j * 16 + $i] = ($C[$j][$i] + $V[$j][$i + 1] + 1) >> 1;
                    }
                }
            }
        } else {
            $hIdx = ($fracY === 1) ? 2 : 3;
            $vIdx = ($fracX === 3) ? 1 : 0;
            for ($j = 0; $j < 16; $j++) {
                for ($i = 0; $i < 16; $i++) {
                    $pred[$j * 16 + $i] = ($H[$j + $hIdx][$i] + $V[$j][$i + $vIdx] + 1) >> 1;
                }
            }
        }

        return $pred;
    }

    private function clampInt(int $val, int $min, int $max): int
    {
        return max($min, min($max, $val));
    }

    private function clip255Int(int $val): int
    {
        return max(0, min(255, $val));
    }
}
