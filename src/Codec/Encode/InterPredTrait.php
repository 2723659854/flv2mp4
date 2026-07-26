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
        for ($y = 0; $y < 16; $y++) {
            $py = $mbY * 16 + $y;
            if ($py >= $this->height) break;
            for ($x = 0; $x < 16; $x++) {
                $px = $mbX * 16 + $x;
                if ($px >= $this->width) break;
                $idx = $py * $this->width + $px;
                $lumaPixels[$y][$x] = ord($yPlane[$idx]);
            }
        }

        // 运动估计（返回1/4像素单位的MV）
        list($mvX, $mvY, $sad) = $this->motionEstimate16x16($lumaPixels, $refYPlane, $mbX, $mbY);

        // 亮度MC预测（1/4像素精度，边缘钳位到mbAligned尺寸，与解码器mcLuma一致）
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
            // chromaMV = floor(lumaMV / 2)，右移一位实现向下取整（符合H.264标准）
            $chromaRefX = $mbX * 64 + ($skipMvpX >> 1);
            $chromaRefY = $mbY * 64 + ($skipMvpY >> 1);
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
        // chromaMV = floor(lumaMV / 2)，右移一位实现向下取整（符合H.264标准）
        $chromaRefX = $mbX * 64 + ($mvX >> 1);
        $chromaRefY = $mbY * 64 + ($mvY >> 1);
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
     * 特殊快速路径：A或B不可用返回(0,0)；A或B为零向量返回(0,0)
     */
    private function predictMvPSkip(?array $mvLeft, ?array $mvTop, ?array $mvTopRight): array
    {
        $aAvail = ($mvLeft !== null);
        $bAvail = ($mvTop !== null);

        if (!$aAvail || !$bAvail) {
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

        if ($mbX > 0 && isset($this->mvLeftCol[0])) {
            $mvLeft = $this->mvLeftCol[0];
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

        if ($mbX > 0 && isset($this->mvLeftCol[0])) {
            $mvLeft = $this->mvLeftCol[0];
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
     * 亮度运动补偿：整数像素位置取值，越界钳位到边缘
     */
    private function mcLumaBlock(string $refPlane, int $refX, int $refY, int $w, int $h): array
    {
        $pred = array_fill(0, 16, array_fill(0, 16, 0));

        $fracX = $refX & 3;
        $fracY = $refY & 3;
        $intX = $refX >> 2;
        $intY = $refY >> 2;

        if ($fracX === 0 && $fracY === 0) {
            for ($y = 0; $y < 16; $y++) {
                for ($x = 0; $x < 16; $x++) {
                    $pred[$y][$x] = $this->getClampedPixel($refPlane, $intX + $x, $intY + $y, $w, $h);
                }
            }
            return $pred;
        }

        $H = null;
        if ($fracX !== 0) {
            $H = array_fill(0, 16, array_fill(0, 16, 0));
            for ($y = 0; $y < 16; $y++) {
                $ry = max(0, min($h - 1, $intY + $y));
                for ($x = 0; $x < 16; $x++) {
                    $px0 = $this->getClampedPixel($refPlane, $intX + $x - 2, $ry, $w, $h);
                    $px1 = $this->getClampedPixel($refPlane, $intX + $x - 1, $ry, $w, $h);
                    $px2 = $this->getClampedPixel($refPlane, $intX + $x, $ry, $w, $h);
                    $px3 = $this->getClampedPixel($refPlane, $intX + $x + 1, $ry, $w, $h);
                    $px4 = $this->getClampedPixel($refPlane, $intX + $x + 2, $ry, $w, $h);
                    $px5 = $this->getClampedPixel($refPlane, $intX + $x + 3, $ry, $w, $h);
                    $hVal = ($px0 - 5 * $px1 + 20 * $px2 + 20 * $px3 - 5 * $px4 + $px5 + 16) >> 5;
                    $H[$y][$x] = max(0, min(255, $hVal));
                }
            }
        }

        if ($fracY !== 0) {
            for ($y = 0; $y < 16; $y++) {
                for ($x = 0; $x < 16; $x++) {
                    $p0 = $H !== null ? $H[max(0, $y - 2)][$x] : $this->getClampedPixel($refPlane, $intX + $x, max(0, min($h - 1, $intY + $y - 2)), $w, $h);
                    $p1 = $H !== null ? $H[max(0, $y - 1)][$x] : $this->getClampedPixel($refPlane, $intX + $x, max(0, min($h - 1, $intY + $y - 1)), $w, $h);
                    $p2 = $H !== null ? $H[$y][$x] : $this->getClampedPixel($refPlane, $intX + $x, max(0, min($h - 1, $intY + $y)), $w, $h);
                    $p3 = $H !== null ? $H[min(15, $y + 1)][$x] : $this->getClampedPixel($refPlane, $intX + $x, max(0, min($h - 1, $intY + $y + 1)), $w, $h);
                    $p4 = $H !== null ? $H[min(15, $y + 2)][$x] : $this->getClampedPixel($refPlane, $intX + $x, max(0, min($h - 1, $intY + $y + 2)), $w, $h);
                    $p5 = $H !== null ? $H[min(15, $y + 3)][$x] : $this->getClampedPixel($refPlane, $intX + $x, max(0, min($h - 1, $intY + $y + 3)), $w, $h);
                    $vVal = ($p0 - 5 * $p1 + 20 * $p2 + 20 * $p3 - 5 * $p4 + $p5 + 16) >> 5;
                    $pred[$y][$x] = max(0, min(255, $vVal));
                }
            }
        } else {
            for ($y = 0; $y < 16; $y++) {
                for ($x = 0; $x < 16; $x++) {
                    $pred[$y][$x] = $H[$y][$x];
                }
            }
        }

        return $pred;
    }
}
