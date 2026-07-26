<?php

namespace Xiaosongshu\Flv2mp4\Codec\Encode;

trait InterPredTrait
{
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

        // 提取当前宏块像素
        $lumaPixels = array_fill(0, 16, array_fill(0, 16, 128));
        $reconStride = $this->mbAlignedWidth;
        for ($y = 0; $y < 16; $y++) {
            $py = $mbY * 16 + $y;
            for ($x = 0; $x < 16; $x++) {
                $px = $mbX * 16 + $x;
                $idx = $py * $reconStride + $px;
                $lumaPixels[$y][$x] = ord($yPlane[$idx]);
            }
        }

        // 运动估计（返回1/4像素单位的MV）
        list($mvX, $mvY, $sad) = $this->motionEstimate16x16($lumaPixels, $refYPlane, $mbX, $mbY);

        // 亮度MC预测（1/4像素精度）
        $refX = $mbX * 64 + $mvX;
        $refY = $mbY * 64 + $mvY;
        $predBlock = $this->mcLumaBlock($refYPlane, $refX, $refY, $this->mbAlignedWidth, $this->mbAlignedHeight);

        // 计算残差
        $residual = array_fill(0, 16, array_fill(0, 16, 0));
        for ($y = 0; $y < 16; $y++) {
            for ($x = 0; $x < 16; $x++) {
                $residual[$y][$x] = $lumaPixels[$y][$x] - $predBlock[$y][$x];
            }
        }

        // DCT和量化
        $nzCache = array_fill(0, 24, 0);
        $cbpLuma = 0;
        $quantResidual = [];

        for ($by = 0; $by < 4; $by++) {
            for ($bx = 0; $bx < 4; $bx++) {
                $blkIdx = $by * 4 + $bx;
                $blk4x4 = array_fill(0, 4, array_fill(0, 4, 0));

                for ($y = 0; $y < 4; $y++) {
                    for ($x = 0; $x < 4; $x++) {
                        $blk4x4[$y][$x] = $residual[$by * 4 + $y][$bx * 4 + $x];
                    }
                }

                $dctBlock = $this->dct($blk4x4);
                $quantBlock = $this->quantize($dctBlock, 0, true);

                $nz = 0;
                $quantResidual[$blkIdx] = array_fill(0, 16, 0);
                for ($y = 0; $y < 4; $y++) {
                    for ($x = 0; $x < 4; $x++) {
                        $quantResidual[$blkIdx][$y * 4 + $x] = $quantBlock[$y][$x];
                        if ($quantBlock[$y][$x] != 0) $nz++;
                    }
                }

                $nzCache[$blkIdx] = min(15, $nz);
                // cbpLuma: 4位，每个位对应一个8x8块
                $subY = intdiv($blkIdx, 4);
                $subX = $blkIdx % 4;
                $block8x8Idx = intdiv($subY, 2) * 2 + intdiv($subX, 2);
                if ($nz > 0) $cbpLuma |= (1 << $block8x8Idx);
            }
        }

        // 对于未编码的8x8块，nzCache必须置0（与解码器一致）
        for ($blkIdx = 0; $blkIdx < 16; $blkIdx++) {
            $subY = intdiv($blkIdx, 4);
            $subX = $blkIdx % 4;
            $block8x8Idx = intdiv($subY, 2) * 2 + intdiv($subX, 2);
            if (!($cbpLuma & (1 << $block8x8Idx))) {
                $nzCache[$blkIdx] = 0;
            }
        }

        // === 计算P_Skip的MVP（与解码器predictMvPSkip一致） ===
        // P_Skip的MV = skipMVP，解码器用此MV做MC
        list($skipMvpX, $skipMvpY) = $this->getMvpPSkip($mbX, $mbY);

        // 色度参考帧尺寸（使用mbAligned尺寸，与I帧重建存储格式一致）
        $chromaW = intdiv($this->mbAlignedWidth, 2);
        $chromaH = intdiv($this->mbAlignedHeight, 2);
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

            // P_Skip亮度重建: MC预测在MV=skipMVP位置（=实际MV，predBlock已正确）
            // 使用mbAlignedWidth作为步长（与I帧重建存储格式一致）
            for ($y = 0; $y < 16; $y++) {
                for ($x = 0; $x < 16; $x++) {
                    $py = $mbY * 16 + $y;
                    $px = $mbX * 16 + $x;
                    $this->reconYPlane[$py * $reconStride + $px] = chr(max(0, min(255, $predBlock[$y][$x])));
                }
            }

            // P_Skip色度重建: 使用与解码器一致的1/8像素双线性插值MC
            // 亮度MV（1/4像素单位）直接作为色度MV（1/8像素单位），无需移位
            $chromaRefX = $mbX * 64 + $skipMvpX;
            $chromaRefY = $mbY * 64 + $skipMvpY;
            $cbPred = $this->mcChromaBlock($this->refUPlane, $chromaRefX, $chromaRefY, $chromaW, $chromaH);
            $crPred = $this->mcChromaBlock($this->refVPlane, $chromaRefX, $chromaRefY, $chromaW, $chromaH);
            for ($y = 0; $y < 8; $y++) {
                for ($x = 0; $x < 8; $x++) {
                    $py = $mbY * 8 + $y;
                    $px = $mbX * 8 + $x;
                    $idx = $py * $chromaW + $px;
                    $this->reconUPlane[$idx] = chr($cbPred[$y][$x]);
                    $this->reconVPlane[$idx] = chr($crPred[$y][$x]);
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

        // === P帧亮度本地解码重建 ===
        // 使用mbAlignedWidth作为步长（与I帧重建存储格式一致）
        for ($by = 0; $by < 4; $by++) {
            for ($bx = 0; $bx < 4; $bx++) {
                $blkIdx = $by * 4 + $bx;
                $block8x8Idx = intdiv($by, 2) * 2 + intdiv($bx, 2);

                if ($cbpLuma & (1 << $block8x8Idx)) {
                    // 有残差: 反量化 + IDCT + 加到MC预测
                    $acDequant = $this->dequantize4x4($quantResidual[$blkIdx], 0, $this->qp);
                    $acBlock = array_fill(0, 4, array_fill(0, 4, 0));
                    for ($y = 0; $y < 4; $y++) for ($x = 0; $x < 4; $x++) $acBlock[$y][$x] = $acDequant[$y * 4 + $x];
                    $idctResult = $this->idct4x4($acBlock);

                    for ($y = 0; $y < 4; $y++) {
                        for ($x = 0; $x < 4; $x++) {
                            $py = $mbY * 16 + $by * 4 + $y;
                            $px = $mbX * 16 + $bx * 4 + $x;
                            $val = $predBlock[$by * 4 + $y][$bx * 4 + $x] + $idctResult[$y][$x];
                            $val = max(0, min(255, $val));
                            $this->reconYPlane[$py * $reconStride + $px] = chr($val);
                        }
                    }
                } else {
                    // 无残差: 直接使用MC预测
                    for ($y = 0; $y < 4; $y++) {
                        for ($x = 0; $x < 4; $x++) {
                            $py = $mbY * 16 + $by * 4 + $y;
                            $px = $mbX * 16 + $bx * 4 + $x;
                            $val = max(0, min(255, $predBlock[$by * 4 + $y][$bx * 4 + $x]));
                            $this->reconYPlane[$py * $reconStride + $px] = chr($val);
                        }
                    }
                }
            }
        }

        // === P帧色度本地解码重建 ===
        // P帧cbpChroma=0, 解码器直接做色度MC(无残差)
        // 亮度MV（1/4像素单位）直接作为色度MV（1/8像素单位），无需移位
        $chromaRefX = $mbX * 64 + $mvX;
        $chromaRefY = $mbY * 64 + $mvY;
        $cbPred = $this->mcChromaBlock($this->refUPlane, $chromaRefX, $chromaRefY, $chromaW, $chromaH);
        $crPred = $this->mcChromaBlock($this->refVPlane, $chromaRefX, $chromaRefY, $chromaW, $chromaH);
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $py = $mbY * 8 + $y;
                $px = $mbX * 8 + $x;
                $idx = $py * $chromaW + $px;
                $this->reconUPlane[$idx] = chr($cbPred[$y][$x]);
                $this->reconVPlane[$idx] = chr($crPred[$y][$x]);
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
                $mvC = $this->mvTopRow[($mbX - 1) * 4 + 3] ?? null;
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
                $mvC = $this->mvTopRow[($mbX - 1) * 4 + 3] ?? null;
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
        $pred = array_fill(0, 8, array_fill(0, 8, 128));
        $fracX = $chromaRefX & 7;
        $fracY = $chromaRefY & 7;
        $intX = $chromaRefX >> 3;
        $intY = $chromaRefY >> 3;

        for ($j = 0; $j < 8; $j++) {
            for ($i = 0; $i < 8; $i++) {
                $a00 = $this->getClampedPixel($refPlane, $intX + $i, $intY + $j, $chromaW, $chromaH);
                $a10 = $this->getClampedPixel($refPlane, $intX + $i + 1, $intY + $j, $chromaW, $chromaH);
                $a01 = $this->getClampedPixel($refPlane, $intX + $i, $intY + $j + 1, $chromaW, $chromaH);
                $a11 = $this->getClampedPixel($refPlane, $intX + $i + 1, $intY + $j + 1, $chromaW, $chromaH);

                $val = ((8 - $fracX) * (8 - $fracY) * $a00 +
                        $fracX * (8 - $fracY) * $a10 +
                        (8 - $fracX) * $fracY * $a01 +
                        $fracX * $fracY * $a11 + 32) >> 6;
                $pred[$j][$i] = max(0, min(255, $val));
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
        $pred = array_fill(0, 16, array_fill(0, 16, 0));

        $fracX = $refX & 3;
        $fracY = $refY & 3;
        $intX = $refX >> 2;
        $intY = $refY >> 2;
        $blockW = 16;
        $blockH = 16;

        if ($fracX === 0 && $fracY === 0) {
            for ($j = 0; $j < $blockH; $j++) {
                for ($i = 0; $i < $blockW; $i++) {
                    $pred[$j][$i] = $this->getClampedPixel($refPlane, $intX + $i, $intY + $j, $w, $h);
                }
            }
            return $pred;
        }

        $hRows = 0;
        $hStart = 0;
        if ($fracX !== 0) {
            if ($fracY === 0) {
                $hRows = $blockH;
                $hStart = 0;
            } else {
                $hRows = $blockH + 5;
                $hStart = -2;
            }
        }

        $vCols = 0;
        if ($fracY !== 0) {
            if ($fracX === 0) {
                $vCols = $blockW;
            } else {
                $vCols = $blockW + 1;
            }
        }

        $H = null;
        $Hfull = null;
        if ($fracX !== 0) {
            if ($fracY === 0) {
                $H = array_fill(0, $hRows, array_fill(0, $blockW, 0));
            } else {
                $Hfull = array_fill(0, $hRows, array_fill(0, $blockW, 0));
                $H = array_fill(0, $hRows, array_fill(0, $blockW, 0));
            }
            for ($j = $hStart; $j < $hStart + $hRows; $j++) {
                $ry = $this->clampInt($intY + $j, 0, $h - 1);
                for ($i = 0; $i < $blockW; $i++) {
                    $px0 = $this->getClampedPixel($refPlane, $intX + $i - 2, $ry, $w, $h);
                    $px1 = $this->getClampedPixel($refPlane, $intX + $i - 1, $ry, $w, $h);
                    $px2 = $this->getClampedPixel($refPlane, $intX + $i, $ry, $w, $h);
                    $px3 = $this->getClampedPixel($refPlane, $intX + $i + 1, $ry, $w, $h);
                    $px4 = $this->getClampedPixel($refPlane, $intX + $i + 2, $ry, $w, $h);
                    $px5 = $this->getClampedPixel($refPlane, $intX + $i + 3, $ry, $w, $h);
                    $fullVal = $px0 - 5 * $px1 + 20 * $px2 + 20 * $px3 - 5 * $px4 + $px5;
                    if ($fracY === 0) {
                        $hVal = ($fullVal + 16) >> 5;
                        $H[$j - $hStart][$i] = $this->clip255Int($hVal);
                    } else {
                        $Hfull[$j - $hStart][$i] = $fullVal;
                        $hVal = ($fullVal + 16) >> 5;
                        $H[$j - $hStart][$i] = $this->clip255Int($hVal);
                    }
                }
            }
        }

        $V = null;
        if ($fracY !== 0) {
            $V = array_fill(0, $blockH, array_fill(0, $vCols, 0));
            for ($j = 0; $j < $blockH; $j++) {
                for ($i = 0; $i < $vCols; $i++) {
                    $rx = $this->clampInt($intX + $i, 0, $w - 1);
                    $px0 = $this->getClampedPixel($refPlane, $rx, $intY + $j - 2, $w, $h);
                    $px1 = $this->getClampedPixel($refPlane, $rx, $intY + $j - 1, $w, $h);
                    $px2 = $this->getClampedPixel($refPlane, $rx, $intY + $j, $w, $h);
                    $px3 = $this->getClampedPixel($refPlane, $rx, $intY + $j + 1, $w, $h);
                    $px4 = $this->getClampedPixel($refPlane, $rx, $intY + $j + 2, $w, $h);
                    $px5 = $this->getClampedPixel($refPlane, $rx, $intY + $j + 3, $w, $h);
                    $hVal = ($px0 - 5 * $px1 + 20 * $px2 + 20 * $px3 - 5 * $px4 + $px5 + 16) >> 5;
                    $V[$j][$i] = $this->clip255Int($hVal);
                }
            }
        }

        $C = null;
        if ($fracX !== 0 && $fracY !== 0) {
            $C = array_fill(0, $blockH, array_fill(0, $blockW, 0));
            for ($j = 0; $j < $blockH; $j++) {
                for ($i = 0; $i < $blockW; $i++) {
                    $px0 = $Hfull[$j][$i];
                    $px1 = $Hfull[$j + 1][$i];
                    $px2 = $Hfull[$j + 2][$i];
                    $px3 = $Hfull[$j + 3][$i];
                    $px4 = $Hfull[$j + 4][$i];
                    $px5 = $Hfull[$j + 5][$i];
                    $fullVal = $px0 - 5 * $px1 + 20 * $px2 + 20 * $px3 - 5 * $px4 + $px5;
                    $hVal = ($fullVal + 512) >> 10;
                    $C[$j][$i] = $this->clip255Int($hVal);
                }
            }
        }

        $avg = function($a, $b) {
            return ($a + $b + 1) >> 1;
        };

        if ($fracY === 0) {
            for ($j = 0; $j < $blockH; $j++) {
                $ry = $this->clampInt($intY + $j, 0, $h - 1);
                for ($i = 0; $i < $blockW; $i++) {
                    if ($fracX === 1) {
                        $I = $this->getClampedPixel($refPlane, $intX + $i, $ry, $w, $h);
                        $pred[$j][$i] = $avg($I, $H[$j][$i]);
                    } elseif ($fracX === 2) {
                        $pred[$j][$i] = $H[$j][$i];
                    } else {
                        $I1 = $this->getClampedPixel($refPlane, $intX + $i + 1, $ry, $w, $h);
                        $pred[$j][$i] = $avg($H[$j][$i], $I1);
                    }
                }
            }
        } elseif ($fracX === 0) {
            for ($j = 0; $j < $blockH; $j++) {
                for ($i = 0; $i < $blockW; $i++) {
                    $rx = $this->clampInt($intX + $i, 0, $w - 1);
                    if ($fracY === 1) {
                        $I = $this->getClampedPixel($refPlane, $rx, $intY + $j, $w, $h);
                        $pred[$j][$i] = $avg($I, $V[$j][$i]);
                    } elseif ($fracY === 2) {
                        $pred[$j][$i] = $V[$j][$i];
                    } else {
                        $I_1 = $this->getClampedPixel($refPlane, $rx, $intY + $j + 1, $w, $h);
                        $pred[$j][$i] = $avg($V[$j][$i], $I_1);
                    }
                }
            }
        } elseif ($fracX === 2) {
            for ($j = 0; $j < $blockH; $j++) {
                for ($i = 0; $i < $blockW; $i++) {
                    if ($fracY === 1) {
                        $pred[$j][$i] = $avg($H[$j + 2][$i], $C[$j][$i]);
                    } elseif ($fracY === 2) {
                        $pred[$j][$i] = $C[$j][$i];
                    } else {
                        $pred[$j][$i] = $avg($C[$j][$i], $H[$j + 3][$i]);
                    }
                }
            }
        } elseif ($fracY === 2) {
            for ($j = 0; $j < $blockH; $j++) {
                for ($i = 0; $i < $blockW; $i++) {
                    if ($fracX === 1) {
                        $pred[$j][$i] = $avg($V[$j][$i], $C[$j][$i]);
                    } else {
                        $pred[$j][$i] = $avg($C[$j][$i], $V[$j][$i + 1]);
                    }
                }
            }
        } else {
            $hIdx = ($fracY === 1) ? 2 : 3;
            $vIdx = ($fracX === 3) ? 1 : 0;
            for ($j = 0; $j < $blockH; $j++) {
                for ($i = 0; $i < $blockW; $i++) {
                    $pred[$j][$i] = $avg($H[$j + $hIdx][$i], $V[$j][$i + $vIdx]);
                }
            }
        }

        for ($j = 0; $j < $blockH; $j++) {
            for ($i = 0; $i < $blockW; $i++) {
                $pred[$j][$i] = $this->clip255Int($pred[$j][$i]);
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
