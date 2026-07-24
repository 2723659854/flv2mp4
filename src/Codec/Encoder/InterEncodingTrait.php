<?php

namespace Xiaosongshu\Flv2mp4\Codec\Encoder;

/**
 * @purpose 帧间预测
 * @author yanglong
 */
trait InterEncodingTrait
{
    private function getRefPixel(string $refPlane, int $x, int $y): int
        {
            $stride = $this->mbAlignedWidth;
            $x = max(0, min($stride - 1, $x));
            $y = max(0, min($this->mbAlignedHeight - 1, $y));
            return ord($refPlane[$y * $stride + $x]);
        }

    private function interpHorizontal(string $refPlane, int $x, int $y): int
        {
            $E = $this->getRefPixel($refPlane, $x - 2, $y);
            $F = $this->getRefPixel($refPlane, $x - 1, $y);
            $G = $this->getRefPixel($refPlane, $x,     $y);
            $H = $this->getRefPixel($refPlane, $x + 1, $y);
            $I = $this->getRefPixel($refPlane, $x + 2, $y);
            $J = $this->getRefPixel($refPlane, $x + 3, $y);
            $val = self::INTERP_TAP0 * $E + self::INTERP_TAP1 * $F + self::INTERP_TAP2 * $G
                 + self::INTERP_TAP3 * $H + self::INTERP_TAP4 * $I + self::INTERP_TAP5 * $J;
            return max(0, min(255, (($val + 16) >> 5)));
        }

    private function interpVertical(string $refPlane, int $x, int $y): int
        {
            $A = $this->getRefPixel($refPlane, $x, $y - 2);
            $B = $this->getRefPixel($refPlane, $x, $y - 1);
            $C = $this->getRefPixel($refPlane, $x, $y);
            $D = $this->getRefPixel($refPlane, $x, $y + 1);
            $E = $this->getRefPixel($refPlane, $x, $y + 2);
            $F = $this->getRefPixel($refPlane, $x, $y + 3);
            $val = self::INTERP_TAP0 * $A + self::INTERP_TAP1 * $B + self::INTERP_TAP2 * $C
                 + self::INTERP_TAP3 * $D + self::INTERP_TAP4 * $E + self::INTERP_TAP5 * $F;
            return max(0, min(255, (($val + 16) >> 5)));
        }

    private function interpDiagonal(string $refPlane, int $x, int $y): int
        {
            // 先做水平插值，得到中间值aa, bb, ..., ff（不clip）
            $vals = [];
            for ($dy = -2; $dy <= 3; $dy++) {
                $E = $this->getRefPixel($refPlane, $x - 2, $y + $dy);
                $F = $this->getRefPixel($refPlane, $x - 1, $y + $dy);
                $G = $this->getRefPixel($refPlane, $x,     $y + $dy);
                $H = $this->getRefPixel($refPlane, $x + 1, $y + $dy);
                $I = $this->getRefPixel($refPlane, $x + 2, $y + $dy);
                $J = $this->getRefPixel($refPlane, $x + 3, $y + $dy);
                $vals[] = self::INTERP_TAP0 * $E + self::INTERP_TAP1 * $F + self::INTERP_TAP2 * $G
                        + self::INTERP_TAP3 * $H + self::INTERP_TAP4 * $I + self::INTERP_TAP5 * $J;
            }
            // 对中间值做垂直插值
            $val = self::INTERP_TAP0 * $vals[0] + self::INTERP_TAP1 * $vals[1] + self::INTERP_TAP2 * $vals[2]
                 + self::INTERP_TAP3 * $vals[3] + self::INTERP_TAP4 * $vals[4] + self::INTERP_TAP5 * $vals[5];
            return max(0, min(255, (($val + 512) >> 10)));
        }

    private function getReferenceBlock(string $refPlane, int $qpX, int $qpY): array
        {
            $block = array_fill(0, 16, array_fill(0, 16, 0));
            $intX = $qpX >> 1;
            $intY = $qpY >> 1;
            $halfX = $qpX & 1;
            $halfY = $qpY & 1;
    
            for ($y = 0; $y < 16; $y++) {
                for ($x = 0; $x < 16; $x++) {
                    $px = $intX + $x;
                    $py = $intY + $y;
                    if ($halfX == 0 && $halfY == 0) {
                        // 整数像素
                        $block[$y][$x] = $this->getRefPixel($refPlane, $px, $py);
                    } elseif ($halfX == 1 && $halfY == 0) {
                        // 水平半像素 b
                        $block[$y][$x] = $this->interpHorizontal($refPlane, $px, $py);
                    } elseif ($halfX == 0 && $halfY == 1) {
                        // 垂直半像素 h
                        $block[$y][$x] = $this->interpVertical($refPlane, $px, $py);
                    } else {
                        // 对角半像素 j
                        $block[$y][$x] = $this->interpDiagonal($refPlane, $px, $py);
                    }
                }
            }
            return $block;
        }

    private function computeSAD(array $block1, array $block2): int
        {
            $sad = 0;
            for ($y = 0; $y < 16; $y++) {
                for ($x = 0; $x < 16; $x++) {
                    $sad += abs($block1[$y][$x] - $block2[$y][$x]);
                }
            }
            return $sad;
        }

    public function motionEstimate16x16(array $currentBlock, string $refPlane, int $mbX, int $mbY, int $searchRange = 16): array
        {
            if (!isset($this->refInts) || $this->refInts === null) {
                $this->refInts = unpack('C*', $refPlane);
            }
    
            $curFlat = [];
            foreach ($currentBlock as $row) {
                foreach ($row as $val) {
                    $curFlat[] = $val;
                }
            }
    
            $origX = $mbX * 16;
            $origY = $mbY * 16;
            $blockW = min(16, $this->width - $origX);
            $blockH = min(16, $this->height - $origY);
            $refStride = $this->mbAlignedWidth;
    
            $minDx = max(-$searchRange, -$origX);
            $maxDx = min($searchRange, $this->mbAlignedWidth - $origX - $blockW);
            $minDy = max(-$searchRange, -$origY);
            $maxDy = min($searchRange, $this->mbAlignedHeight - $origY - $blockH);
    
            $ldspPattern = [
                [-2, 0], [2, 0], [0, -2], [0, 2],
                [-1, -1], [1, -1], [-1, 1], [1, 1],
            ];
            $sdspPattern = [
                [-1, 0], [1, 0], [0, -1], [0, 1],
            ];
    
            $bestDX = 0;
            $bestDY = 0;
            $bestSAD = $this->computeSADFast($curFlat, $origX, $origY, 0, 0, $blockW, $blockH, $refStride);
    
            for ($iter = 0; $iter < 10; $iter++) {
                $foundBetter = false;
                foreach ($ldspPattern as [$px, $py]) {
                    $dx = $bestDX + $px;
                    $dy = $bestDY + $py;
                    if ($dx < $minDx || $dx > $maxDx || $dy < $minDy || $dy > $maxDy) {
                        continue;
                    }
                    $sad = $this->computeSADFast($curFlat, $origX, $origY, $dx, $dy, $blockW, $blockH, $refStride);
                    if ($sad < $bestSAD) {
                        $bestSAD = $sad;
                        $bestDX = $dx;
                        $bestDY = $dy;
                        $foundBetter = true;
                    }
                }
                if (!$foundBetter) break;
            }
    
            for ($iter = 0; $iter < 3; $iter++) {
                $foundBetter = false;
                foreach ($sdspPattern as [$px, $py]) {
                    $dx = $bestDX + $px;
                    $dy = $bestDY + $py;
                    if ($dx < $minDx || $dx > $maxDx || $dy < $minDy || $dy > $maxDy) {
                        continue;
                    }
                    $sad = $this->computeSADFast($curFlat, $origX, $origY, $dx, $dy, $blockW, $blockH, $refStride);
                    if ($sad < $bestSAD) {
                        $bestSAD = $sad;
                        $bestDX = $dx;
                        $bestDY = $dy;
                        $foundBetter = true;
                    }
                }
                if (!$foundBetter) break;
            }
    
            return [$bestDX * 4, $bestDY * 4, $bestSAD];
        }

    private function computeSADFast(array $curFlat, int $origX, int $origY, int $dx, int $dy, int $blockW, int $blockH, int $refStride): int
        {
            $rx = $origX + $dx;
            $ry = $origY + $dy;
            $refStart = $ry * $refStride + $rx + 1;
            $sad = 0;
            $pos = 0;
            $refInts = $this->refInts;
    
            for ($y = 0; $y < $blockH; $y++) {
                $rowOffset = $refStart + $y * $refStride;
                for ($x = 0; $x < $blockW; $x++) {
                    $diff = $curFlat[$pos] - $refInts[$rowOffset + $x];
                    if ($diff < 0) $diff = -$diff;
                    $sad += $diff;
                    $pos++;
                }
            }
            return $sad;
        }

    public function motionEstimate16x16TwoLevel(array $currentBlock, string $refPlane, int $mbX, int $mbY, int $searchRange = 16): array
        {
            $refStride = $this->mbAlignedWidth;
            $refW = $this->mbAlignedWidth;
            $refH = $this->mbAlignedHeight;
    
            $bestMV = [0, 0];
            $bestSAD = PHP_INT_MAX;
    
            $origX = $mbX * 16;
            $origY = $mbY * 16;
    
            $blockW = min(16, $this->width - $origX);
            $blockH = min(16, $this->height - $origY);
    
            // 先检查(0,0)位置
            $sad00 = 0;
            for ($y = 0; $y < $blockH; $y++) {
                for ($x = 0; $x < $blockW; $x++) {
                    $refIdx = ($origY + $y) * $refStride + ($origX + $x);
                    $sad00 += abs($currentBlock[$y][$x] - ord($refPlane[$refIdx]));
                }
            }
            $bestSAD = $sad00;
            $bestDX = 0;
            $bestDY = 0;
    
            // 大步长粗搜索（步长=4）
            $coarseStep = 4;
            for ($dy = -$searchRange; $dy <= $searchRange; $dy += $coarseStep) {
                for ($dx = -$searchRange; $dx <= $searchRange; $dx += $coarseStep) {
                    if ($dx == 0 && $dy == 0) continue;
                    $rx = $origX + $dx;
                    $ry = $origY + $dy;
    
                    if ($rx < 0 || $rx + $blockW > $refW || $ry < 0 || $ry + $blockH > $refH) {
                        continue;
                    }
    
                    $sad = 0;
                    for ($y = 0; $y < $blockH; $y++) {
                        for ($x = 0; $x < $blockW; $x++) {
                            $refIdx = ($ry + $y) * $refStride + ($rx + $x);
                            $sad += abs($currentBlock[$y][$x] - ord($refPlane[$refIdx]));
                        }
                    }
    
                    if ($sad < $bestSAD) {
                        $bestSAD = $sad;
                        $bestDX = $dx;
                        $bestDY = $dy;
                    }
                }
            }
    
            // 小步长精搜索（在粗搜索最佳点周围±3，步长=1）
            $refineRange = 3;
            for ($dy = $bestDY - $refineRange; $dy <= $bestDY + $refineRange; $dy++) {
                for ($dx = $bestDX - $refineRange; $dx <= $bestDX + $refineRange; $dx++) {
                    if ($dx == 0 && $dy == 0 && $bestDX == 0 && $bestDY == 0) continue;
                    if (abs($dx) > $searchRange || abs($dy) > $searchRange) continue;
                    $rx = $origX + $dx;
                    $ry = $origY + $dy;
    
                    if ($rx < 0 || $rx + $blockW > $refW || $ry < 0 || $ry + $blockH > $refH) {
                        continue;
                    }
    
                    $sad = 0;
                    for ($y = 0; $y < $blockH; $y++) {
                        for ($x = 0; $x < $blockW; $x++) {
                            $refIdx = ($ry + $y) * $refStride + ($rx + $x);
                            $sad += abs($currentBlock[$y][$x] - ord($refPlane[$refIdx]));
                        }
                    }
    
                    if ($sad < $bestSAD) {
                        $bestSAD = $sad;
                        $bestDX = $dx;
                        $bestDY = $dy;
                    }
                }
            }
    
            $bestMV = [$bestDX * 4, $bestDY * 4];
            return [$bestMV[0], $bestMV[1], $bestSAD];
        }

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

    private function medianInt(int $a, int $b, int $c): int
        {
            $min = min($a, $b, $c);
            $max = max($a, $b, $c);
            return $a + $b + $c - $min - $max;
        }

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

    private function saveMv16x16(int $mbX, int $mvX, int $mvY, int $refIdx): void
        {
            $mv = [$mvX, $mvY, $refIdx];
            $this->mvLeftCol = [$mv, $mv, $mv, $mv];
            $this->mvTopRow[$mbX * 4 + 0] = $mv;
            $this->mvTopRow[$mbX * 4 + 1] = $mv;
            $this->mvTopRow[$mbX * 4 + 2] = $mv;
            $this->mvTopRow[$mbX * 4 + 3] = $mv;
        }

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

    private function getClampedPixel(string $plane, int $x, int $y, int $w, int $h): int
        {
            $x = max(0, min($w - 1, $x));
            $y = max(0, min($h - 1, $y));
            return ord($plane[$y * $w + $x]);
        }

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
