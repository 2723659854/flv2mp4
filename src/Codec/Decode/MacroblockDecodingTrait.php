<?php

namespace Xiaosongshu\Flv2mp4\Codec\Decode;

/**
 * @purpose 宏块解码器
 * @author yanglong
 * @time 2026年7月23日14:57:14
 */
trait MacroblockDecodingTrait
{
    /**
     * 解码单个宏块
     * @return int mb_qp_delta
     */
    public function decodeMacroblock(int $mbX, int $mbY, int $sliceQp, int $sliceType): int
    {
        $mbType = $this->reader->readUe();
        $mbWidth = $this->picWidthInMbs;
        $mbIdx = $mbY * $mbWidth + $mbX;
        
        $this->mbTypeForDeblock[$mbIdx] = $mbType;
        $this->mbNnzForDeblock[$mbIdx] = array_fill(0, 24, 0);
        $this->mbMvForDeblock[$mbIdx] = array_fill(0, 16, [0, 0]);
        $this->mbRefForDeblock[$mbIdx] = array_fill(0, 16, 0);

        $mbQpDelta = 0;

        // I帧 (slice_type = 2 或 4)
        if ($sliceType === 2 || $sliceType === 4) {
            $mbQpDelta = $this->decodeIntraMacroblock($mbX, $mbY, $mbType, $sliceQp);
        }
        // P帧 (slice_type = 0 或 5)
        elseif ($sliceType === 0 || $sliceType === 5) {
            $mbQpDelta = $this->decodePInterMacroblock($mbX, $mbY, $mbType, $sliceQp);
        }
        // 其他类型（B帧等）暂时填充灰色
        else {
            $this->fillMacroblockGray($mbX, $mbY);
        }
        return $mbQpDelta;
    }

    /**
     * I帧宏块解码
     */
    private function decodeIntraMacroblock(int $mbX, int $mbY, int $mbType, int $sliceQp): int
    {
        // I_PCM 无损宏块
        if ($mbType === 25) {
            $this->reader->alignToByte();
            for ($y = 0; $y < 16; $y++) {
                for ($x = 0; $x < 16; $x++) {
                    $pixel = $this->reader->readByte();
                    $this->writeLumaPixel($mbX, $mbY, $x, $y, $pixel);
                }
            }
            for ($y = 0; $y < 8; $y++) {
                for ($x = 0; $x < 8; $x++) {
                    $pixel = $this->reader->readByte();
                    $this->writeChromaPixel($mbX, $mbY, $x, $y, $pixel, 0);
                }
            }
            for ($y = 0; $y < 8; $y++) {
                for ($x = 0; $x < 8; $x++) {
                    $pixel = $this->reader->readByte();
                    $this->writeChromaPixel($mbX, $mbY, $x, $y, $pixel, 1);
                }
            }
            // I_PCM宏块传递DC_PRED(2)给相邻宏块
            $this->intra4x4LeftModes = array_fill(0, 4, 2);
            $baseLuma = $mbX * 4;
            $this->intra4x4TopModes[$baseLuma + 0] = 2;
            $this->intra4x4TopModes[$baseLuma + 1] = 2;
            $this->intra4x4TopModes[$baseLuma + 2] = 2;
            $this->intra4x4TopModes[$baseLuma + 3] = 2;
            return 0;
        }

        // Intra4x4 / Intra16x16
        if ($mbType === 0) {
            return $this->decodeIntra4x4($mbX, $mbY, $sliceQp);
        } elseif ($mbType >= 1 && $mbType <= 24) {
            return $this->decodeIntra16x16($mbX, $mbY, $mbType, $sliceQp);
        } else {
            $this->fillMacroblockGray($mbX, $mbY);
            return 0;
        }
    }

    /**
     * Intra4x4 完整解码
     * @return int mb_qp_delta
     */
    public function decodeIntra4x4(int $mbX, int $mbY, int $sliceQp): int
    {
        $modes = array_fill(0, 16, 0);
        $modeCache = array_fill(0, 16, -1);
        $scanToRaster = [0, 1, 4, 5, 2, 3, 6, 7, 8, 9, 12, 13, 10, 11, 14, 15];

        for ($scanIdx = 0; $scanIdx < 16; $scanIdx++) {
            $rasterIdx = $scanToRaster[$scanIdx];
            $blkX = $rasterIdx % 4;
            $blkY = (int)($rasterIdx / 4);

            // 获取左边块的预测模式
            $leftMode = -1;
            if ($blkX > 0) {
                $leftMode = $modeCache[$rasterIdx - 1];
            } elseif ($mbX > 0) {
                $leftMode = $this->intra4x4LeftModes[$blkY];
            }

            // 获取上面块的预测模式
            $topMode = -1;
            if ($blkY > 0) {
                $topMode = $modeCache[$rasterIdx - 4];
            } elseif ($mbY > 0) {
                $absBlkX = $mbX * 4 + $blkX;
                $topMode = $this->intra4x4TopModes[$absBlkX];
            }

            // H.264标准8.3.1.1节：如果任一邻居不可用(mode<0)，predicted=DC(2)
            // 否则 predicted=min(leftMode, topMode)
            $minMode = min($leftMode, $topMode);
            $predicted = ($minMode < 0) ? 2 : $minMode;
            $prevFlag = $this->reader->readU(1);
            if ($prevFlag) {
                $mode = $predicted;
            } else {
                $remMode = $this->reader->readU(3);
                $mode = $remMode >= $predicted ? $remMode + 1 : $remMode;
            }
            $modeCache[$rasterIdx] = $mode;
            $modes[$rasterIdx] = $mode;
        }

        // 更新跨宏块预测模式缓存
        // 左边列：右列块的模式 (rasterIdx 3,7,11,15)
        $this->intra4x4LeftModes[0] = $modes[3];
        $this->intra4x4LeftModes[1] = $modes[7];
        $this->intra4x4LeftModes[2] = $modes[11];
        $this->intra4x4LeftModes[3] = $modes[15];
        // 上边行：顶行块的模式 (rasterIdx 12,13,14,15)
        $baseLuma = $mbX * 4;
        $this->intra4x4TopModes[$baseLuma + 0] = $modes[12];
        $this->intra4x4TopModes[$baseLuma + 1] = $modes[13];
        $this->intra4x4TopModes[$baseLuma + 2] = $modes[14];
        $this->intra4x4TopModes[$baseLuma + 3] = $modes[15];

        $chromaPredMode = $this->reader->readUe();
        $cbpCode = $this->reader->readUe();
        $cbp = $this->golombToIntraCbp($cbpCode);
        // mb_qp_delta 仅在 CBP > 0 时读取（H.264 标准 7.4.5）
        $mbQpDelta = 0;
        if ($cbp > 0) {
            $mbQpDelta = $this->reader->readSe();
        }
        $qp = max(0, min(51, $sliceQp + $mbQpDelta));
        $yCoeffs = array_fill(0, 16, array_fill(0, 16, 0));
        $cbCoeffs = array_fill(0, 4, array_fill(0, 16, 0));
        $crCoeffs = array_fill(0, 4, array_fill(0, 16, 0));

        // 亮度4x4残差 - I_4x4每个块有16个系数（DC+AC）
        // 按zigzag扫描顺序解码，每个块计算nC
        $nzCache = array_fill(0, 24, 0);
        $leftNz = array_fill(0, 8, 0);
        $topNz = array_fill(0, $this->picWidthInMbs * 4 + $this->picWidthInMbs * 4, 0);
        $leftAvailable = ($mbX > 0);
        $topAvailable = ($mbY > 0);

        // 从成员变量加载左边和上边的非零系数数
        if ($leftAvailable) {
            for ($y = 0; $y < 4; $y++) $leftNz[$y] = $this->nzLeftColLuma[$y];
            // leftNz布局：4=Cb上行右列(19), 5=Cb下行右列(17), 6=Cr上行右列(23), 7=Cr下行右列(21)
            $leftNz[4] = $this->nzLeftColChroma[0];  // Cb上行
            $leftNz[5] = $this->nzLeftColChroma[1];  // Cb下行
            $leftNz[6] = $this->nzLeftColChroma[2];  // Cr上行
            $leftNz[7] = $this->nzLeftColChroma[3];  // Cr下行
        }
        if ($topAvailable) {
            $baseLuma = $mbX * 4;
            for ($x = 0; $x < 4; $x++) $topNz[$baseLuma + $x] = $this->nzTopRowLuma[$baseLuma + $x];
            // 色度Cb：topNz索引16+mbX*2+x，nzTopRowChroma索引mbX*2+x
            // 块18需要上宏块的块16（左列），块19需要上宏块的块17（右列）
            for ($x = 0; $x < 2; $x++) $topNz[$this->picWidthInMbs * 4 + $mbX * 2 + $x] = $this->nzTopRowChroma[$mbX * 2 + $x];
            // 色度Cr：topNz索引24+mbX*2+x，nzTopRowChroma索引picWidthInMbs*2+mbX*2+x
            for ($x = 0; $x < 2; $x++) $topNz[$this->picWidthInMbs * 4 + $this->picWidthInMbs * 2 + $mbX * 2 + $x] = $this->nzTopRowChroma[$this->picWidthInMbs * 2 + $mbX * 2 + $x];
        }

        // SCAN_TO_RASTER映射：扫描顺序 -> 光栅顺序（与wedeo cavlc.rs完全一致）
        $scanToRaster = [0, 1, 4, 5, 2, 3, 6, 7, 8, 9, 12, 13, 10, 11, 14, 15];
        $lumaCbp = $cbp & 0x0F;

        // 按扫描顺序解码
        // i8x8: 8x8块索引(0-3), i4x4: 8x8块内的4x4子块索引(0-3)
        for ($i8x8 = 0; $i8x8 < 4; $i8x8++) {
            if (($lumaCbp & (1 << $i8x8)) !== 0) {
                for ($i4x4 = 0; $i4x4 < 4; $i4x4++) {
                    $scanIdx = $i8x8 * 4 + $i4x4;
                    $rasterIdx = $scanToRaster[$scanIdx];
                    $nc = $this->computeNc($rasterIdx, $mbX, $mbY, $nzCache, $leftNz, $topNz, $leftAvailable, $topAvailable);
                    $coeffs = $this->decodeResidualBlock(16, $nc);
                    for ($i = 0; $i < 16; $i++) $yCoeffs[$rasterIdx][$i] = $coeffs[$i];
                    $yCoeffs[$rasterIdx] = $this->zigzagToRaster($yCoeffs[$rasterIdx]);
                    $yCoeffs[$rasterIdx] = $this->dequantize4x4($yCoeffs[$rasterIdx], 0, $qp);
                    $nzCount = 0;
                    for ($i = 0; $i < 16; $i++) if ($coeffs[$i] != 0) $nzCount++;
                    $nzCache[$rasterIdx] = $nzCount;
                }
            } else {
                for ($i4x4 = 0; $i4x4 < 4; $i4x4++) {
                    $scanIdx = $i8x8 * 4 + $i4x4;
                    $rasterIdx = $scanToRaster[$scanIdx];
                    $nzCache[$rasterIdx] = 0;
                }
            }
        }

        $chromaQpIndex = max(0, min(51, $qp + $this->chromaQpIndexOffset));
        $chromaQp = self::CHROMA_QP_TABLE[$chromaQpIndex];
        $cbDc = array_fill(0, 4, 0);
        $crDc = array_fill(0, 4, 0);

        // 色度CBP: 高2位 (0=无, 1=仅DC, 2=DC+AC)
        $chromaCbp = ($cbp >> 4) & 0x03;

        // 色度DC残差 - 使用nC=-1，对应coeffTokenMinus1 VLC表
        if ($chromaCbp > 0) {
            $cbDc = $this->decodeResidualBlock(4, -1);
            $crDc = $this->decodeResidualBlock(4, -1);
            // 注意：不初始化nzCache[16-23]为DC计数，totalCoeff[16-23]只存AC计数
        }

        // 色度AC残差 - 条件是chromaCbp >= 2
        // DC残差不进入IDCT，直接加到像素上；AC残差单独处理（coeffs[0]保持为0）
        if ($chromaCbp >= 2) {
            // Cb块空间布局：16 17 (上行), 18 19 (下行)
            // 按递增顺序解码：16,17,18,19
            $cbScanOrder = [16, 17, 18, 19];
            foreach ($cbScanOrder as $blockIdx) {
                $blk = $blockIdx - 16;
                $nc = $this->computeNc($blockIdx, $mbX, $mbY, $nzCache, $leftNz, $topNz, $leftAvailable, $topAvailable);
                $ac = $this->decodeResidualBlock(15, $nc);
                for ($i = 0; $i < 15; $i++) $cbCoeffs[$blk][$i + 1] = $ac[$i];
                $cbCoeffs[$blk] = $this->zigzagToRaster($cbCoeffs[$blk]);
                // 反量化AC系数（coeffs[0]保持为0，DC单独处理）
                $cbCoeffs[$blk] = $this->dequantize4x4($cbCoeffs[$blk], 1, $chromaQp);
                // AC-only count
                $nzCount = 0;
                for ($i = 0; $i < 15; $i++) if ($ac[$i] != 0) $nzCount++;
                $nzCache[$blockIdx] = $nzCount;
            }
            // Cr块空间布局：20 21 (上行), 22 23 (下行)
            // 按递增顺序解码：20,21,22,23
            $crScanOrder = [20, 21, 22, 23];
            foreach ($crScanOrder as $blockIdx) {
                $blk = $blockIdx - 20;
                $nc = $this->computeNc($blockIdx, $mbX, $mbY, $nzCache, $leftNz, $topNz, $leftAvailable, $topAvailable);
                $ac = $this->decodeResidualBlock(15, $nc);
                for ($i = 0; $i < 15; $i++) $crCoeffs[$blk][$i + 1] = $ac[$i];
                $crCoeffs[$blk] = $this->zigzagToRaster($crCoeffs[$blk]);
                // 反量化AC系数（coeffs[0]保持为0，DC单独处理）
                $crCoeffs[$blk] = $this->dequantize4x4($crCoeffs[$blk], 2, $chromaQp);
                // AC-only count
                $nzCount = 0;
                for ($i = 0; $i < 15; $i++) if ($ac[$i] != 0) $nzCount++;
                $nzCache[$blockIdx] = $nzCount;
            }
        }

        // IDCT4x4 逆变换亮度
        $yPixels = array_fill(0, 16, array_fill(0, 16, 0));
        for ($blkY = 0; $blkY < 4; $blkY++) {
            for ($blkX = 0; $blkX < 4; $blkX++) {
                $blk = $blkY * 4 + $blkX;
                $block = array_fill(0, 4, array_fill(0, 4, 0));
                for ($y = 0; $y < 4; $y++) for ($x = 0; $x < 4; $x++) $block[$y][$x] = $yCoeffs[$blk][$y * 4 + $x];
                $idct = $this->idct4x4($block);
                for ($y = 0; $y < 4; $y++) for ($x = 0; $x < 4; $x++) $yPixels[$blkY * 4 + $y][$blkX * 4 + $x] = $idct[$y][$x];
            }
        }

        // 亮度预测+残差写入像素
        for ($blkY = 0; $blkY < 4; $blkY++) {
            for ($blkX = 0; $blkX < 4; $blkX++) {
                $blk = $blkY * 4 + $blkX;
                $predicted = $this->intra4x4Prediction($mbX, $mbY, $blkX, $blkY, $modes[$blk]);

                for ($y = 0; $y < 4; $y++) {
                    for ($x = 0; $x < 4; $x++) {
                        $py = $mbY * 16 + $blkY * 4 + $y;
                        $px = $mbX * 16 + $blkX * 4 + $x;
                        if ($py < $this->height && $px < $this->width) {
                            $val = $predicted[$y][$x] + $yPixels[$blkY * 4 + $y][$blkX * 4 + $x];
                            $val = max(0, min(255, $val));
                            $idx = $py * $this->width + $px;
                            $this->yPlane[$idx] = $val;
                        }
                    }
                }
            }
        }

        // 色度DC逆哈达玛 + AC IDCT
        //   - AC存在 (chromaCbp>=2): DC放入coeffs[0], 一次IDCT (单一+32偏置, >>6)
        //   - DC-only (chromaCbp==1): dc_add = (dc + 32) >> 6, 加到所有像素
        $uPixels = array_fill(0, 8, array_fill(0, 8, 0));
        $vPixels = array_fill(0, 8, array_fill(0, 8, 0));

        // 色度DC: 逆Hadamard + 反量化
        // qmul = dequant4Table[list_idx=1+plane_idx][chromaQp][0]
        // 输入为原始DC系数（不预反量化）
        $cbQmul = $this->dequant4Table[1][$chromaQp][0];
        $crQmul = $this->dequant4Table[2][$chromaQp][0];
        $cbDcResult = $this->chromaDcDequantIdct($cbDc, $cbQmul);
        $crDcResult = $this->chromaDcDequantIdct($crDc, $crQmul);

        for ($blkY = 0; $blkY < 2; $blkY++) {
            for ($blkX = 0; $blkX < 2; $blkX++) {
                $blk = $blkY * 2 + $blkX;
                // DC残差（已完成反量化+逆哈达玛，但还需要+32>>6归一化）
                $dcResidualCb = $cbDcResult[$blk];
                $dcResidualCr = $crDcResult[$blk];

                if ($chromaCbp >= 2) {
                    // AC存在: 将DC放入coeffs[0], 与AC一起做IDCT
                    $blockCb = array_fill(0, 4, array_fill(0, 4, 0));
                    $blockCr = array_fill(0, 4, array_fill(0, 4, 0));
                    for ($y = 0; $y < 4; $y++) for ($x = 0; $x < 4; $x++) {
                        $blockCb[$y][$x] = $cbCoeffs[$blk][$y * 4 + $x];
                        $blockCr[$y][$x] = $crCoeffs[$blk][$y * 4 + $x];
                    }
                    // 将DC残差放入位置[0][0]
                    $blockCb[0][0] = $dcResidualCb;
                    $blockCr[0][0] = $dcResidualCr;
                    $acIdctCb = $this->idct4x4($blockCb);
                    $acIdctCr = $this->idct4x4($blockCr);

                    for ($y = 0; $y < 4; $y++) for ($x = 0; $x < 4; $x++) {
                        $uPixels[$blkY * 4 + $y][$blkX * 4 + $x] = $acIdctCb[$y][$x];
                        $vPixels[$blkY * 4 + $y][$blkX * 4 + $x] = $acIdctCr[$y][$x];
                    }
                } else {
                    // DC-only: 应用+32>>6归一化，加到所有像素
                    $dcAddCb = ($dcResidualCb + 32) >> 6;
                    $dcAddCr = ($dcResidualCr + 32) >> 6;
                    for ($y = 0; $y < 4; $y++) for ($x = 0; $x < 4; $x++) {
                        $uPixels[$blkY * 4 + $y][$blkX * 4 + $x] = $dcAddCb;
                        $vPixels[$blkY * 4 + $y][$blkX * 4 + $x] = $dcAddCr;
                    }
                }
            }
        }

        // 色度预测+残差写入
        $cbPredicted = $this->intraChromaPrediction($mbX, $mbY, $chromaPredMode, 0);
        $crPredicted = $this->intraChromaPrediction($mbX, $mbY, $chromaPredMode, 1);
        $chromaWidth = (int)($this->width / 2);
        $chromaHeight = (int)($this->height / 2);
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $py = $mbY * 8 + $y;
                $px = $mbX * 8 + $x;
                if ($py < $chromaHeight && $px < $chromaWidth) {
                    $valU = $cbPredicted[$y][$x] + $uPixels[$y][$x];
                    $valV = $crPredicted[$y][$x] + $vPixels[$y][$x];
                    $valU = max(0, min(255, $valU));
                    $valV = max(0, min(255, $valV));
                    $idx = $py * $chromaWidth + $px;
                    $this->uPlane[$idx] = $valU;
                    $this->vPlane[$idx] = $valV;
                }
            }
        }

        // 保存非零系数数供相邻宏块使用
        // 亮度raster布局：
        //  0  1  2  3
        //  4  5  6  7
        //  8  9 10 11
        // 12 13 14 15
        // 右列（供左宏块使用）：raster 3,7,11,15
        $this->nzLeftColLuma[0] = $nzCache[3];
        $this->nzLeftColLuma[1] = $nzCache[7];
        $this->nzLeftColLuma[2] = $nzCache[11];
        $this->nzLeftColLuma[3] = $nzCache[15];
        // 底行（供上宏块使用）：raster 12,13,14,15
        $baseLuma = $mbX * 4;
        $this->nzTopRowLuma[$baseLuma + 0] = $nzCache[12];
        $this->nzTopRowLuma[$baseLuma + 1] = $nzCache[13];
        $this->nzTopRowLuma[$baseLuma + 2] = $nzCache[14];
        $this->nzTopRowLuma[$baseLuma + 3] = $nzCache[15];

        // 色度Cb raster布局：
        // 16 17 (上行)
        // 18 19 (下行)
        // 右列：17,19 供左宏块使用
        $this->nzLeftColChroma[0] = $nzCache[17];  // Cb上行右列
        $this->nzLeftColChroma[1] = $nzCache[19];  // Cb下行右列
        // 底行：18,19 供上宏块使用
        $this->nzTopRowChroma[$mbX * 2 + 0] = $nzCache[18];
        $this->nzTopRowChroma[$mbX * 2 + 1] = $nzCache[19];

        // 色度Cr raster布局：
        // 20 21 (上行)
        // 22 23 (下行)
        $this->nzLeftColChroma[2] = $nzCache[21];  // Cr上行右列
        $this->nzLeftColChroma[3] = $nzCache[23];  // Cr下行右列
        $this->nzTopRowChroma[$this->picWidthInMbs * 2 + $mbX * 2 + 0] = $nzCache[22];
        $this->nzTopRowChroma[$this->picWidthInMbs * 2 + $mbX * 2 + 1] = $nzCache[23];
        return $mbQpDelta;
    }

    /**
     * Intra16x16 解码
     * @return int mb_qp_delta
     */
    public function decodeIntra16x16(int $mbX, int $mbY, int $mbType, int $qp): int
    {
        // H.264标准Table 7-11 (I帧) / Table 7-10 (P帧Intra mb_type-5):
        // mbType 1-24: Intra16x16
        // predMode顺序: 0(V),1(H),2(DC),3(Plane) 每4个一循环
        // cbp分组: 每4个一组
        //   组0 (1-4):  Luma=0,  Chroma=0
        //   组1 (5-8):  Luma=0,  Chroma=1
        //   组2 (9-12): Luma=0,  Chroma=2
        //   组3 (13-16):Luma=15, Chroma=0
        //   组4 (17-20):Luma=15, Chroma=1
        //   组5 (21-24):Luma=15, Chroma=2

        $predMode = ($mbType - 1) % 4;
        $hasLumaAc = ($mbType - 1) >= 12;
        $base = $hasLumaAc ? 12 : 0;
        $cbpChroma = intdiv(($mbType - 1 - $base), 4);
        $cbpLuma = $hasLumaAc ? 15 : 0;


        // I_16x16宏块总是编码chroma_pred_mode（参考C语言编码器cavlc_mb_header_i）
        // chroma = CHROMA_FORMAT == CHROMA_420 || CHROMA_FORMAT == CHROMA_422，对于YUV420总是true
        // 编码顺序: mb_type -> chroma_pred_mode -> mb_qp_delta -> 亮度DC系数
        $chromaPredMode = $this->reader->readUe();
        if ($chromaPredMode > 3) {
            $chromaPredMode = 0; // 回退到DC模式
        }

        // mb_qp_delta - I_16x16无论cbp如何都要读取（H.264标准 7.4.5.2）
        $mbQpDelta = $this->reader->readSe();
        $qp = max(0, min(51, $qp + $mbQpDelta));

        // 亮度DC系数
        // 参考x264编码顺序: 亮度DC -> 亮度AC -> 色度DC -> 色度AC
        $nzCache = array_fill(0, 24, 0);
        $leftNz = array_fill(0, 8, 0);
        $topNz = array_fill(0, $this->picWidthInMbs * 4 + $this->picWidthInMbs * 4, 0);
        $leftAvailable = ($mbX > 0);
        $topAvailable = ($mbY > 0);

        // 从成员变量加载左边和上边的非零系数数
        if ($leftAvailable) {
            for ($y = 0; $y < 4; $y++) $leftNz[$y] = $this->nzLeftColLuma[$y];
            // leftNz布局：4=Cb上行右列(19), 5=Cb下行右列(17), 6=Cr上行右列(23), 7=Cr下行右列(21)
            $leftNz[4] = $this->nzLeftColChroma[0];  // Cb上行
            $leftNz[5] = $this->nzLeftColChroma[1];  // Cb下行
            $leftNz[6] = $this->nzLeftColChroma[2];  // Cr上行
            $leftNz[7] = $this->nzLeftColChroma[3];  // Cr下行
        }
        if ($topAvailable) {
            $baseLuma = $mbX * 4;
            for ($x = 0; $x < 4; $x++) $topNz[$baseLuma + $x] = $this->nzTopRowLuma[$baseLuma + $x];
            // 色度Cb：topNz索引16+mbX*2+x，nzTopRowChroma索引mbX*2+x
            // 块18需要上宏块的块16（左列），块19需要上宏块的块17（右列）
            for ($x = 0; $x < 2; $x++) $topNz[$this->picWidthInMbs * 4 + $mbX * 2 + $x] = $this->nzTopRowChroma[$mbX * 2 + $x];
            // 色度Cr：topNz索引24+mbX*2+x，nzTopRowChroma索引picWidthInMbs*2+mbX*2+x
            for ($x = 0; $x < 2; $x++) $topNz[$this->picWidthInMbs * 4 + $this->picWidthInMbs * 2 + $mbX * 2 + $x] = $this->nzTopRowChroma[$this->picWidthInMbs * 2 + $mbX * 2 + $x];
        }

        // Intra16x16总是解码亮度DC
        // 不论cbpLuma是否为0，DC系数总是存在
        $predNz = 0;
        $count = 0;
        if ($leftAvailable) {
            $predNz += $leftNz[0];
            $count++;
        }
        if ($topAvailable) {
            $ax = $mbX * 4;
            if ($ax < count($topNz)) {
                $predNz += $topNz[$ax];
                $count++;
            }
        }
        if ($count === 0) {
            $yDcNc = 0;
        } else {
            $avgNz = intdiv($predNz + intdiv($count, 2), $count);
            $yDcNc = min($avgNz, 16);
        }
        if ($mbX === 0 && $mbY === 0) $this->debugResidual = true;
        if ($mbX === 3 && $mbY === 0) $this->debugResidual = true;
        $yDcZigzag = $this->decodeResidualBlock(16, $yDcNc);
        $this->debugResidual = false;
        // decodeResidualBlock返回zig-zag顺序，需先转为raster顺序
        // raster顺序在宏块DC语境下对应4x4矩阵: row=block_row, col=block_col
        // 这是Hadamard变换所需的输入顺序
        $yDcRaster = array_fill(0, 16, 0);
        for ($i = 0; $i < 16; $i++) {
            $yDcRaster[self::ZIGZAG_SCAN_4X4[$i]] = $yDcZigzag[$i];
        }
        $qpClamped = max(0, min(51, $qp));

        // 亮度DC: 逆Hadamard + 反量化
        // qmul = dequant4Table[list_idx=0][qp][0]
        // 输入为raster顺序的原始DC系数（不预反量化），输出为raster顺序的反量化值
        $lumaQmul = $this->dequant4Table[0][$qpClamped][0];
        $yDcResultBlockOrder = $this->lumaDcDequantIdct($yDcRaster, $lumaQmul);

        if ($mbX === 0 && $mbY === 0) {
            $this->debugLastQp = $qp;
            $this->debugLastDcScan = $yDcRaster;
            $this->debugLastDcRaster = $yDcRaster;
            $this->debugLastQmul = $lumaQmul;
            $this->debugLastDcResult = $yDcResultBlockOrder;
        }

        $yAcCoeffs = array_fill(0, 16, array_fill(0, 16, 0));
        $blockIndexToRaster = [0, 1, 4, 5, 2, 3, 6, 7, 8, 9, 12, 13, 10, 11, 14, 15];

        // 亮度AC残差 - 按blockIndex递增顺序解码，每个块计算nC
        // AC系数单独处理，DC残差不进入IDCT，直接加到像素上
        if ($cbpLuma !== 0) {
            for ($blkIdx = 0; $blkIdx < 16; $blkIdx++) {
                $rasterIdx = $blockIndexToRaster[$blkIdx];
                $nc = $this->computeNc($rasterIdx, $mbX, $mbY, $nzCache, $leftNz, $topNz, $leftAvailable, $topAvailable);
                $ac = $this->decodeResidualBlock(15, $nc);
                // 直接用 ZIGZAG_SCAN_4X4 表映射AC系数到raster位置，跳过位置0（DC）
                for ($scanPos = 0; $scanPos < 15; $scanPos++) {
                    $yAcCoeffs[$rasterIdx][self::ZIGZAG_SCAN_4X4[$scanPos + 1]] = $ac[$scanPos];
                }
                // 反量化AC系数（coeffs[0]保持为0，DC单独处理）
                $yAcCoeffs[$rasterIdx] = $this->dequantize4x4($yAcCoeffs[$rasterIdx], 0, $qp);
                // AC-only count（totalCoeff[blockIndex]只存AC计数，不包括DC）
                $nzCount = 0;
                for ($i = 0; $i < 15; $i++) if ($ac[$i] != 0) $nzCount++;
                $nzCache[$rasterIdx] = $nzCount;
            }
        }

        $chromaQpIndex = max(0, min(51, $qp + $this->chromaQpIndexOffset));
        $chromaQp = self::CHROMA_QP_TABLE[$chromaQpIndex];

        $cbDc = array_fill(0, 4, 0);
        $crDc = array_fill(0, 4, 0);

        if ($cbpChroma != 0) {
            $cbDc = $this->decodeResidualBlock(4, -1);
            $crDc = $this->decodeResidualBlock(4, -1);
        }

        // 色度DC: 逆Hadamard + 反量化
        // qmul = dequant4Table[list_idx=1+plane_idx][chromaQp][0]
        // 输入为原始DC系数（不预反量化），输出为raster顺序的反量化值
        // Cb用list_idx=1, Cr用list_idx=2 (都是intra scaling matrix)
        $cbQmul = $this->dequant4Table[1][$chromaQp][0];
        $crQmul = $this->dequant4Table[2][$chromaQp][0];
        $cbDcResult = $this->chromaDcDequantIdct($cbDc, $cbQmul);
        $crDcResult = $this->chromaDcDequantIdct($crDc, $crQmul);

        $cbAcCoeffs = array_fill(0, 4, array_fill(0, 16, 0));
        $crAcCoeffs = array_fill(0, 4, array_fill(0, 16, 0));

        // 色度AC残差 - 条件是cbpChroma >= 2
        // DC残差不进入IDCT，直接加到像素上；AC残差单独处理（coeffs[0]保持为0）
        if ($cbpChroma >= 2) {
            // Cb块空间布局
            //   16 17  (上行)
            //   18 19  (下行)
            // 按递增顺序解码：16,17,18,19
            $cbScanOrder = [16, 17, 18, 19];
            foreach ($cbScanOrder as $blockIdx) {
                $blk = $blockIdx - 16;
                $nc = $this->computeNc($blockIdx, $mbX, $mbY, $nzCache, $leftNz, $topNz, $leftAvailable, $topAvailable);
                $ac = $this->decodeResidualBlock(15, $nc);
                $nzCnt = 0; for ($i = 0; $i < 15; $i++) if ($ac[$i] != 0) $nzCnt++;
                for ($i = 1; $i < 16; $i++) $cbAcCoeffs[$blk][$i] = $ac[$i - 1];
                $cbAcCoeffs[$blk] = $this->zigzagToRaster($cbAcCoeffs[$blk]);
                // 反量化AC系数（coeffs[0]保持为0，DC单独处理）
                $cbAcCoeffs[$blk] = $this->dequantize4x4($cbAcCoeffs[$blk], 1, $chromaQp);
                $nzCache[$blockIdx] = $nzCnt;
            }
            // Cr块空间布局（与Cb相同）：
            //   20 21  (上行)
            //   22 23  (下行)
            // 按递增顺序解码：20,21,22,23
            $crScanOrder = [20, 21, 22, 23];
            foreach ($crScanOrder as $blockIdx) {
                $blk = $blockIdx - 20;
                $nc = $this->computeNc($blockIdx, $mbX, $mbY, $nzCache, $leftNz, $topNz, $leftAvailable, $topAvailable);
                $ac = $this->decodeResidualBlock(15, $nc);
                $nzCnt = 0; for ($i = 0; $i < 15; $i++) if ($ac[$i] != 0) $nzCnt++;
                for ($i = 1; $i < 16; $i++) $crAcCoeffs[$blk][$i] = $ac[$i - 1];
                $crAcCoeffs[$blk] = $this->zigzagToRaster($crAcCoeffs[$blk]);
                // 反量化AC系数（coeffs[0]保持为0，DC单独处理）
                $crAcCoeffs[$blk] = $this->dequantize4x4($crAcCoeffs[$blk], 2, $chromaQp);
                $nzCache[$blockIdx] = $nzCnt;
            }
        }

        // 亮度预测 + 残差
        // decode_intra16x16:
        //   - AC存在: DC放入coeffs[0], 一次IDCT (单一+32偏置, >>6)
        //   - DC-only: dc_add = (dc + 32) >> 6, 加到所有像素
        $lumaPred = $this->intra16x16Prediction($mbX, $mbY, $predMode);
        for ($blkY = 0; $blkY < 4; $blkY++) {
            for ($blkX = 0; $blkX < 4; $blkX++) {
                $blk = $blkY * 4 + $blkX;

                // DC残差（已完成反量化+逆哈达玛，但还需要+32>>6归一化）
                $dcResidual = $yDcResultBlockOrder[$blk];

                if ($cbpLuma !== 0) {
                    // AC存在: 将DC放入coeffs[0], 与AC一起做IDCT
                    // (匹配Rust: 单一+32偏置, 避免DC和AC分别IDCT导致的双重偏置)
                    $acBlock = array_fill(0, 4, array_fill(0, 4, 0));
                    for ($y = 0; $y < 4; $y++) for ($x = 0; $x < 4; $x++) $acBlock[$y][$x] = $yAcCoeffs[$blk][$y * 4 + $x];
                    // 将DC残差放入位置[0][0] (idct4x4会对位置0加+32偏置)
                    $acBlock[0][0] = $dcResidual;
                    $acIdct = $this->idct4x4($acBlock);

                    for ($y = 0; $y < 4; $y++) {
                        for ($x = 0; $x < 4; $x++) {
                            $py = $mbY * 16 + $blkY * 4 + $y;
                            $px = $mbX * 16 + $blkX * 4 + $x;
                            if ($py < $this->height && $px < $this->width) {
                                $val = $lumaPred[$blkY * 4 + $y][$blkX * 4 + $x] + $acIdct[$y][$x];
                                $val = max(0, min(255, $val));
                                $idx = $py * $this->width + $px;
                                $this->yPlane[$idx] = $val;
                            }
                        }
                    }
                } else {
                    // DC-only: 应用+32>>6归一化，加到所有像素
                    $dcAdd = ($dcResidual + 32) >> 6;
                    for ($y = 0; $y < 4; $y++) {
                        for ($x = 0; $x < 4; $x++) {
                            $py = $mbY * 16 + $blkY * 4 + $y;
                            $px = $mbX * 16 + $blkX * 4 + $x;
                            if ($py < $this->height && $px < $this->width) {
                                $val = $lumaPred[$blkY * 4 + $y][$blkX * 4 + $x] + $dcAdd;
                                $val = max(0, min(255, $val));
                                $idx = $py * $this->width + $px;
                                $this->yPlane[$idx] = $val;
                            }
                        }
                    }
                }
            }
        }

        // 色度预测+残差
        // decode_chroma:
        //   - AC存在 (cbpChroma>=2): DC放入coeffs[0], 一次IDCT (单一+32偏置, >>6)
        //   - DC-only (cbpChroma==1): dc_add = (dc + 32) >> 6, 加到所有像素
        $cbPred = $this->intraChromaPrediction($mbX, $mbY, $chromaPredMode, 0);
        $crPred = $this->intraChromaPrediction($mbX, $mbY, $chromaPredMode, 1);
        $cw = (int)($this->width / 2);
        $ch = (int)($this->height / 2);
        for ($blkY = 0; $blkY < 2; $blkY++) {
            for ($blkX = 0; $blkX < 2; $blkX++) {
                $blk = $blkY * 2 + $blkX;

                // DC残差（已完成反量化+逆哈达玛，但还需要+32>>6归一化）
                $cbDcResidual = $cbDcResult[$blk];
                $crDcResidual = $crDcResult[$blk];

                if ($cbpChroma >= 2) {
                    // AC存在: 将DC放入coeffs[0], 与AC一起做IDCT
                    $cbAcBlock = array_fill(0, 4, array_fill(0, 4, 0));
                    $crAcBlock = array_fill(0, 4, array_fill(0, 4, 0));
                    for ($y = 0; $y < 4; $y++) for ($x = 0; $x < 4; $x++) {
                        $cbAcBlock[$y][$x] = $cbAcCoeffs[$blk][$y * 4 + $x];
                        $crAcBlock[$y][$x] = $crAcCoeffs[$blk][$y * 4 + $x];
                    }
                    // 将DC残差放入位置[0][0]
                    $cbAcBlock[0][0] = $cbDcResidual;
                    $crAcBlock[0][0] = $crDcResidual;
                    $acIdctCb = $this->idct4x4($cbAcBlock);
                    $acIdctCr = $this->idct4x4($crAcBlock);

                    for ($y = 0; $y < 4; $y++) {
                        for ($x = 0; $x < 4; $x++) {
                            $py = $mbY * 8 + $blkY * 4 + $y;
                            $px = $mbX * 8 + $blkX * 4 + $x;
                            if ($py < $ch && $px < $cw) {
                                $vu = $cbPred[$blkY * 4 + $y][$blkX * 4 + $x] + $acIdctCb[$y][$x];
                                $vv = $crPred[$blkY * 4 + $y][$blkX * 4 + $x] + $acIdctCr[$y][$x];
                                $idx = $py * $cw + $px;
                                $this->uPlane[$idx] = max(0, min(255, $vu));
                                $this->vPlane[$idx] = max(0, min(255, $vv));
                            }
                        }
                    }
                } elseif ($cbpChroma == 1) {
                    // DC-only: 应用+32>>6归一化，加到所有像素
                    $cbDcAdd = ($cbDcResidual + 32) >> 6;
                    $crDcAdd = ($crDcResidual + 32) >> 6;
                    for ($y = 0; $y < 4; $y++) {
                        for ($x = 0; $x < 4; $x++) {
                            $py = $mbY * 8 + $blkY * 4 + $y;
                            $px = $mbX * 8 + $blkX * 4 + $x;
                            if ($py < $ch && $px < $cw) {
                                $vu = $cbPred[$blkY * 4 + $y][$blkX * 4 + $x] + $cbDcAdd;
                                $vv = $crPred[$blkY * 4 + $y][$blkX * 4 + $x] + $crDcAdd;
                                $idx = $py * $cw + $px;
                                $this->uPlane[$idx] = max(0, min(255, $vu));
                                $this->vPlane[$idx] = max(0, min(255, $vv));
                            }
                        }
                    }
                } else {
                    // cbpChroma == 0: 没有残差，直接写入预测值
                    for ($y = 0; $y < 4; $y++) {
                        for ($x = 0; $x < 4; $x++) {
                            $py = $mbY * 8 + $blkY * 4 + $y;
                            $px = $mbX * 8 + $blkX * 4 + $x;
                            if ($py < $ch && $px < $cw) {
                                $idx = $py * $cw + $px;
                                $this->uPlane[$idx] = $cbPred[$blkY * 4 + $y][$blkX * 4 + $x];
                                $this->vPlane[$idx] = $crPred[$blkY * 4 + $y][$blkX * 4 + $x];
                            }
                        }
                    }
                }
            }
        }

        // 保存非零系数数供相邻宏块使用
        // 亮度raster布局：
        //  0  1  2  3
        //  4  5  6  7
        //  8  9 10 11
        // 12 13 14 15
        // 右列（供左宏块使用）：raster 3,7,11,15
        $this->nzLeftColLuma[0] = $nzCache[3];
        $this->nzLeftColLuma[1] = $nzCache[7];
        $this->nzLeftColLuma[2] = $nzCache[11];
        $this->nzLeftColLuma[3] = $nzCache[15];
        // 底行（供上宏块使用）：raster 12,13,14,15
        $baseLuma = $mbX * 4;
        $this->nzTopRowLuma[$baseLuma + 0] = $nzCache[12];
        $this->nzTopRowLuma[$baseLuma + 1] = $nzCache[13];
        $this->nzTopRowLuma[$baseLuma + 2] = $nzCache[14];
        $this->nzTopRowLuma[$baseLuma + 3] = $nzCache[15];

        // 色度Cb raster布局：
        // 16 17 (上行)
        // 18 19 (下行)
        // 右列：17,19 供左宏块使用
        $this->nzLeftColChroma[0] = $nzCache[17];  // Cb上行右列
        $this->nzLeftColChroma[1] = $nzCache[19];  // Cb下行右列
        // 底行：18,19 供上宏块使用
        $this->nzTopRowChroma[$mbX * 2 + 0] = $nzCache[18];
        $this->nzTopRowChroma[$mbX * 2 + 1] = $nzCache[19];

        // 色度Cr raster布局：
        // 20 21 (上行)
        // 22 23 (下行)
        $this->nzLeftColChroma[2] = $nzCache[21];  // Cr上行右列
        $this->nzLeftColChroma[3] = $nzCache[23];  // Cr下行右列
        $this->nzTopRowChroma[$this->picWidthInMbs * 2 + $mbX * 2 + 0] = $nzCache[22];
        $this->nzTopRowChroma[$this->picWidthInMbs * 2 + $mbX * 2 + 1] = $nzCache[23];

        // Intra16x16宏块传递DC_PRED(2)给相邻宏块
        // H.264 spec 8.3.1.1: 当邻居不是Intra_4x4时，其预测模式推断为DC_PRED(2)
        // 存储2而不是-1的原因：如果存储-1，当左边是-1但上边是有效模式(如1)时，
        // min(-1, 1) = -1会导致predicted=DC_PRED(2)，但正确行为应该是min(2, 1) = 1
        $this->intra4x4LeftModes = array_fill(0, 4, 2);
        $baseLuma = $mbX * 4;
        $this->intra4x4TopModes[$baseLuma + 0] = 2;
        $this->intra4x4TopModes[$baseLuma + 1] = 2;
        $this->intra4x4TopModes[$baseLuma + 2] = 2;
        $this->intra4x4TopModes[$baseLuma + 3] = 2;

        return $mbQpDelta;
    }

    // ---------------------- 工具函数 ----------------------
    /**
     * CBP 映射表
     */
    public function golombToIntraCbp(int $code): int
    {
        $table = [47,31,15,0,23,27,29,30,7,11,13,14,39,43,45,46,16,3,5,10,12,19,21,26,28,35,
            37,42,44,1,2,4,8,17,18,20,24,6,9,22,25,32,33,34,36,40,38,41];
        return $code < count($table) ? $table[$code] : 0;
    }

    /**
     * 填充宏块为中性灰（P/B帧无运动补偿）
     */
    public function fillMacroblockGray(int $mbX, int $mbY): void
    {
        // 亮度16x16
        $py0 = $mbY * 16;
        $px0 = $mbX * 16;
        for ($y = 0; $y < 16; $y++) {
            $py = $py0 + $y;
            if ($py >= $this->height) break;
            $baseIdx = $py * $this->width;
            for ($x = 0; $x < 16; $x++) {
                $px = $px0 + $x;
                if ($px >= $this->width) break;
                $this->yPlane[$baseIdx + $px] = 128;
            }
        }
        // 色度8x8
        $cw = (int)($this->width / 2);
        $ch = (int)($this->height / 2);
        $cy0 = $mbY * 8;
        $cx0 = $mbX * 8;
        for ($y = 0; $y < 8; $y++) {
            $cy = $cy0 + $y;
            if ($cy >= $ch) break;
            $baseIdx = $cy * $cw;
            for ($x = 0; $x < 8; $x++) {
                $cx = $cx0 + $x;
                if ($cx >= $cw) break;
                $this->uPlane[$baseIdx + $cx] = 128;
                $this->vPlane[$baseIdx + $cx] = 128;
            }
        }
        // 非I帧宏块传递DC_PRED(2)给相邻宏块
        $this->intra4x4LeftModes = array_fill(0, 4, 2);
        $baseLuma = $mbX * 4;
        $this->intra4x4TopModes[$baseLuma + 0] = 2;
        $this->intra4x4TopModes[$baseLuma + 1] = 2;
        $this->intra4x4TopModes[$baseLuma + 2] = 2;
        $this->intra4x4TopModes[$baseLuma + 3] = 2;
    }

    /**
     * 写入亮度像素
     */
    public function writeLumaPixel(int $mbX, int $mbY, int $x, int $y, int $val): void
    {
        $px = $mbX * 16 + $x;
        $py = $mbY * 16 + $y;
        if ($px >= $this->width || $py >= $this->height) return;
        $idx = $py * $this->width + $px;
        $this->yPlane[$idx] = max(0, min(255, $val));
    }

    /**
     * 写入色度像素
     * @param int $uv 0=U 1=V
     */
    public function writeChromaPixel(int $mbX, int $mbY, int $x, int $y, int $val, int $uv): void
    {
        $cw = (int)($this->width / 2);
        $ch = (int)($this->height / 2);
        $px = $mbX * 8 + $x;
        $py = $mbY * 8 + $y;
        if ($px >= $cw || $py >= $ch) return;
        $idx = $py * $cw + $px;
        $val = max(0, min(255, $val));
        if ($uv === 0) {
            $this->uPlane[$idx] = $val;
        } else {
            $this->vPlane[$idx] = $val;
        }
    }

    // ====================== P帧宏块解码 ======================

    /**
     * P帧宏块解码
     * P slice mb_type:
     *   0 = P_Skip
     *   1 = P_L0_16x16
     *   2 = P_L0_L0_16x8
     *   3 = P_L0_L0_8x16
     *   4 = P_8x8
     *   5 = P_8x8ref0
     *   6..31 = Intra_16x16 / I_4x4 / I_PCM (与I帧对应关系: mb_type - 1)
     */
    private function decodePInterMacroblock(int $mbX, int $mbY, int $mbType, int $sliceQp): int
    {
        if ($mbType === 0) {
            return $this->decodePL0_16x16($mbX, $mbY, $sliceQp);
        }

        if ($mbType === 1) {
            return $this->decodePL0_16x8($mbX, $mbY, $sliceQp);
        }

        if ($mbType === 2) {
            return $this->decodePL0_8x16($mbX, $mbY, $sliceQp);
        }

        if ($mbType === 3) {
            return $this->decodeP_8x8($mbX, $mbY, $sliceQp);
        }

        if ($mbType === 4) {
            return $this->decodeP_8x8ref0($mbX, $mbY, $sliceQp);
        }

        if ($mbType >= 5) {
            $intraMbType = $mbType - 5;
            if ($intraMbType === 31) {
                $this->fillMacroblockGray($mbX, $mbY);
                return 0;
            }
            $mbQpDelta = $this->decodeIntraMacroblock($mbX, $mbY, $intraMbType, $sliceQp);
            $intraMv = [0, 0, -1];
            $this->mvLeftCol = [$intraMv, $intraMv, $intraMv, $intraMv];
            $this->mvTopRow[$mbX * 4 + 0] = $intraMv;
            $this->mvTopRow[$mbX * 4 + 1] = $intraMv;
            $this->mvTopRow[$mbX * 4 + 2] = $intraMv;
            $this->mvTopRow[$mbX * 4 + 3] = $intraMv;
            return $mbQpDelta;
        }
        $this->fillMacroblockGray($mbX, $mbY);
        return 0;
    }

    /**
     * P_Skip 宏块解码
     * 运动向量为预测值，无残差
     */
    private function decodePSkip(int $mbX, int $mbY): int
    {
        $refIdx = 0;
        list($predMvX, $predMvY) = $this->getPSkipMvPrediction($mbX, $mbY);

        $mvX = $predMvX;
        $mvY = $predMvY;
        
        $this->performMotionCompensation16x16($mbX, $mbY, $mvX, $mvY, $refIdx);

        $this->saveMvForPrediction($mbX, $mbY, $mvX, $mvY, $refIdx);

        $this->updateNzCountZero($mbX, $mbY);

        $this->updateInterMbIntraModes($mbX);

        $mbWidth = $this->picWidthInMbs;
        $mbIdx = $mbY * $mbWidth + $mbX;
        for ($i = 0; $i < 16; $i++) {
            $this->mbMvForDeblock[$mbIdx][$i] = [$mvX, $mvY];
            $this->mbRefForDeblock[$mbIdx][$i] = $refIdx;
        }

        return 0;
    }

    private function updateNzCountZero(int $mbX, int $mbY): void
    {
        $mbWidth = $this->picWidthInMbs;

        $baseLuma = $mbX * 4;
        $this->nzTopRowLuma[$baseLuma + 0] = 0;
        $this->nzTopRowLuma[$baseLuma + 1] = 0;
        $this->nzTopRowLuma[$baseLuma + 2] = 0;
        $this->nzTopRowLuma[$baseLuma + 3] = 0;

        $this->nzLeftColLuma[0] = 0;
        $this->nzLeftColLuma[1] = 0;
        $this->nzLeftColLuma[2] = 0;
        $this->nzLeftColLuma[3] = 0;

        $this->nzTopRowChroma[$mbX * 2 + 0] = 0;
        $this->nzTopRowChroma[$mbX * 2 + 1] = 0;
        $this->nzTopRowChroma[$mbWidth * 2 + $mbX * 2 + 0] = 0;
        $this->nzTopRowChroma[$mbWidth * 2 + $mbX * 2 + 1] = 0;

        $this->nzLeftColChroma[0] = 0;
        $this->nzLeftColChroma[1] = 0;
        $this->nzLeftColChroma[2] = 0;
        $this->nzLeftColChroma[3] = 0;
    }

    /**
     * Inter宏块解码后更新intra4x4预测模式缓存
     * H.264标准: 非Intra_4x4宏块的邻居预测模式视为DC_PRED(2)
     */
    private function updateInterMbIntraModes(int $mbX): void
    {
        $this->intra4x4LeftModes = [2, 2, 2, 2];
        $baseLuma = $mbX * 4;
        $this->intra4x4TopModes[$baseLuma + 0] = 2;
        $this->intra4x4TopModes[$baseLuma + 1] = 2;
        $this->intra4x4TopModes[$baseLuma + 2] = 2;
        $this->intra4x4TopModes[$baseLuma + 3] = 2;
    }

    /**
     * P_L0_16x16 宏块解码
     */
    private function decodePL0_16x16(int $mbX, int $mbY, int $sliceQp): int
    {
        $refIdx = 0;
        if ($this->numRefIdxL0Active > 1) {
            $refIdx = $this->reader->readUe();
        }

        $mvdL0X = $this->reader->readSe();
        $mvdL0Y = $this->reader->readSe();

        list($predMvX, $predMvY) = $this->getP16x16MvPrediction($mbX, $mbY, $refIdx);
        $mvX = $predMvX + $mvdL0X;
        $mvY = $predMvY + $mvdL0Y;

        $this->performMotionCompensation16x16($mbX, $mbY, $mvX, $mvY, $refIdx);
        $cbpCode = $this->reader->readUe();
        $codedBlockPattern = self::GOLOMB_TO_INTER_CBP[$cbpCode] ?? 0;
        $mbQpDelta = 0;
        if ($codedBlockPattern !== 0) {
            $mbQpDelta = $this->reader->readSe();
            $qp = $sliceQp + $mbQpDelta;
            $qp = max(0, min(51, $qp));
            $this->decodeResidualAndAdd($mbX, $mbY, $codedBlockPattern, $qp, 0);
        }
        if ($codedBlockPattern === 0) {
            $this->updateNzCountZero($mbX, $mbY);
        }
        $this->saveMvForPrediction($mbX, $mbY, $mvX, $mvY, $refIdx);
        $this->updateInterMbIntraModes($mbX);
        $mbWidth = $this->picWidthInMbs;
        $mbIdx = $mbY * $mbWidth + $mbX;
        for ($i = 0; $i < 16; $i++) {
            $this->mbMvForDeblock[$mbIdx][$i] = [$mvX, $mvY];
            $this->mbRefForDeblock[$mbIdx][$i] = $refIdx;
        }
        return $mbQpDelta;
    }

    /**
     * P_L0_L0_16x8 宏块解码
     */
    private function decodePL0_16x8(int $mbX, int $mbY, int $sliceQp): int
    {
        $refIdx0 = 0;
        $refIdx1 = 0;
        if ($this->numRefIdxL0Active > 1) {
            $refIdx0 = $this->reader->readUe();
        }
        $mvd0X = $this->reader->readSe();
        $mvd0Y = $this->reader->readSe();
        if ($this->numRefIdxL0Active > 1) {
            $refIdx1 = $this->reader->readUe();
        }
        $mvd1X = $this->reader->readSe();
        $mvd1Y = $this->reader->readSe();

        list($predMv0X, $predMv0Y) = $this->getP16x8MvPrediction($mbX, $mbY, 0, $refIdx0);
        $mv0X = $predMv0X + $mvd0X;
        $mv0Y = $predMv0Y + $mvd0Y;

        $mv0 = [$mv0X, $mv0Y, $refIdx0];
        list($predMv1X, $predMv1Y) = $this->getP16x8MvPrediction($mbX, $mbY, 1, $refIdx1, $mv0);
        $mv1X = $predMv1X + $mvd1X;
        $mv1Y = $predMv1Y + $mvd1Y;

        $this->performMotionCompensation16x8($mbX, $mbY, 0, $mv0X, $mv0Y, $refIdx0);
        $this->performMotionCompensation16x8($mbX, $mbY, 1, $mv1X, $mv1Y, $refIdx1);

        $cbpCode = $this->reader->readUe();
        $codedBlockPattern = self::GOLOMB_TO_INTER_CBP[$cbpCode] ?? 0;

        $mbQpDelta = 0;
        if ($codedBlockPattern !== 0) {
            $mbQpDelta = $this->reader->readSe();
            $qp = $sliceQp + $mbQpDelta;
            $qp = max(0, min(51, $qp));
            $this->decodeResidualAndAdd($mbX, $mbY, $codedBlockPattern, $qp, 0);
        }

        if ($codedBlockPattern === 0) {
            $this->updateNzCountZero($mbX, $mbY);
        }

        $this->saveMvForPrediction16x8($mbX, $mbY, $mv0X, $mv0Y, $refIdx0, $mv1X, $mv1Y, $refIdx1);

        $this->updateInterMbIntraModes($mbX);

        $mbWidth = $this->picWidthInMbs;
        $mbIdx = $mbY * $mbWidth + $mbX;
        for ($i = 0; $i < 8; $i++) {
            $this->mbMvForDeblock[$mbIdx][$i] = [$mv0X, $mv0Y];
            $this->mbRefForDeblock[$mbIdx][$i] = $refIdx0;
        }
        for ($i = 8; $i < 16; $i++) {
            $this->mbMvForDeblock[$mbIdx][$i] = [$mv1X, $mv1Y];
            $this->mbRefForDeblock[$mbIdx][$i] = $refIdx1;
        }

        return $mbQpDelta;
    }

    /**
     * P_L0_L0_8x16 宏块解码
     */
    private function decodePL0_8x16(int $mbX, int $mbY, int $sliceQp): int
    {
        $refIdx0 = 0;
        $refIdx1 = 0;
        if ($this->numRefIdxL0Active > 1) {
            $refIdx0 = $this->reader->readUe();
        }
        $mvd0X = $this->reader->readSe();
        $mvd0Y = $this->reader->readSe();
        if ($this->numRefIdxL0Active > 1) {
            $refIdx1 = $this->reader->readUe();
        }
        $mvd1X = $this->reader->readSe();
        $mvd1Y = $this->reader->readSe();

        list($predMv0X, $predMv0Y) = $this->getP8x16MvPrediction($mbX, $mbY, 0, $refIdx0);
        $mv0X = $predMv0X + $mvd0X;
        $mv0Y = $predMv0Y + $mvd0Y;

        $mv0 = [$mv0X, $mv0Y, $refIdx0];
        list($predMv1X, $predMv1Y) = $this->getP8x16MvPrediction($mbX, $mbY, 1, $refIdx1, $mv0);
        $mv1X = $predMv1X + $mvd1X;
        $mv1Y = $predMv1Y + $mvd1Y;

        $this->performMotionCompensation8x16($mbX, $mbY, 0, $mv0X, $mv0Y, $refIdx0);
        $this->performMotionCompensation8x16($mbX, $mbY, 1, $mv1X, $mv1Y, $refIdx1);

        $cbpCode = $this->reader->readUe();
        $codedBlockPattern = self::GOLOMB_TO_INTER_CBP[$cbpCode] ?? 0;
        $mbQpDelta = 0;
        if ($codedBlockPattern !== 0) {
            $mbQpDelta = $this->reader->readSe();
            $qp = $sliceQp + $mbQpDelta;
            $qp = max(0, min(51, $qp));
            $this->decodeResidualAndAdd($mbX, $mbY, $codedBlockPattern, $qp, 0);
        }

        if ($codedBlockPattern === 0) {
            $this->updateNzCountZero($mbX, $mbY);
        }

        $this->saveMvForPrediction8x16($mbX, $mbY, $mv0X, $mv0Y, $refIdx0, $mv1X, $mv1Y, $refIdx1);

        $this->updateInterMbIntraModes($mbX);

        $mbWidth = $this->picWidthInMbs;
        $mbIdx = $mbY * $mbWidth + $mbX;
        $leftBlocks = [0, 1, 4, 5, 8, 9, 12, 13];
        $rightBlocks = [2, 3, 6, 7, 10, 11, 14, 15];
        foreach ($leftBlocks as $i) {
            $this->mbMvForDeblock[$mbIdx][$i] = [$mv0X, $mv0Y];
            $this->mbRefForDeblock[$mbIdx][$i] = $refIdx0;
        }
        foreach ($rightBlocks as $i) {
            $this->mbMvForDeblock[$mbIdx][$i] = [$mv1X, $mv1Y];
            $this->mbRefForDeblock[$mbIdx][$i] = $refIdx1;
        }

        return $mbQpDelta;
    }

    /**
     * P_8x8 宏块解码
     * 码流顺序: 先所有4个sub_mb_type, 再所有4个ref_idx, 最后所有sub-partition的mvd
     */
    private function decodeP_8x8(int $mbX, int $mbY, int $sliceQp): int
    {
        $mbMvs = array_fill(0, 4, array_fill(0, 4, null));

        $subMbScan = [[0, 0], [1, 0], [0, 1], [1, 1]];

        $leftColMvs = $this->mvLeftCol;
        $topRowMvs = $this->mvTopRow;

        $subMbTypes = [];
        $refIdxs = [];
        $mvds = [];

        $subPartCount = [1, 2, 2, 4];

        for ($i = 0; $i < 4; $i++) {
            $subMbTypes[$i] = $this->reader->readUe();
        }

        for ($i = 0; $i < 4; $i++) {
            if ($this->numRefIdxL0Active > 1) {
                $refIdxs[$i] = $this->reader->readUe();
            } else {
                $refIdxs[$i] = 0;
            }
        }

        for ($i = 0; $i < 4; $i++) {
            $subType = $subMbTypes[$i];
            $partCount = $subPartCount[$subType] ?? 1;
            $mvds[$i] = [];
            for ($j = 0; $j < $partCount; $j++) {
                $mvdX = $this->reader->readSe();
                $mvdY = $this->reader->readSe();
                $mvds[$i][$j] = [$mvdX, $mvdY];
            }
        }

        for ($mbPartIdx = 0; $mbPartIdx < 4; $mbPartIdx++) {
            $blkX8 = $subMbScan[$mbPartIdx][0];
            $blkY8 = $subMbScan[$mbPartIdx][1];
            $subMbType = $subMbTypes[$mbPartIdx];
            $refIdx = $refIdxs[$mbPartIdx];

            $partX = $blkX8 * 2;
            $partY = $blkY8 * 2;

            if ($subMbType === 0) {
                $mvdX = $mvds[$mbPartIdx][0][0];
                $mvdY = $mvds[$mbPartIdx][0][1];

                list($predMvX, $predMvY) = $this->predictMvP8x8(
                    $mbX, $mbY, $partX, $partY, 2,
                    $mbMvs, $topRowMvs, $leftColMvs,
                    $refIdx
                );
                $mvX = $predMvX + $mvdX;
                $mvY = $predMvY + $mvdY;

                $mv = [$mvX, $mvY, $refIdx];
                $mbMvs[$partY][$partX] = $mv;
                $mbMvs[$partY][$partX + 1] = $mv;
                $mbMvs[$partY + 1][$partX] = $mv;
                $mbMvs[$partY + 1][$partX + 1] = $mv;

                $this->performMotionCompensation8x8($mbX, $mbY, $blkX8, $blkY8, $mvX, $mvY, $refIdx);

            } elseif ($subMbType === 1) {
                for ($sub = 0; $sub < 2; $sub++) {
                    $subY = $partY + $sub;
                    $mvdX = $mvds[$mbPartIdx][$sub][0];
                    $mvdY = $mvds[$mbPartIdx][$sub][1];

                    list($predMvX, $predMvY) = $this->predictMvP8x8(
                        $mbX, $mbY, $partX, $subY, 2,
                        $mbMvs, $topRowMvs, $leftColMvs,
                        $refIdx
                    );
                    $mvX = $predMvX + $mvdX;
                    $mvY = $predMvY + $mvdY;

                    $mv = [$mvX, $mvY, $refIdx];
                    $mbMvs[$subY][$partX] = $mv;
                    $mbMvs[$subY][$partX + 1] = $mv;

                    $this->performMotionCompensation8x4($mbX, $mbY, $blkX8, $blkY8, $sub, $mvX, $mvY, $refIdx);
                }

            } elseif ($subMbType === 2) {
                for ($sub = 0; $sub < 2; $sub++) {
                    $subX = $partX + $sub;
                    $mvdX = $mvds[$mbPartIdx][$sub][0];
                    $mvdY = $mvds[$mbPartIdx][$sub][1];

                    list($predMvX, $predMvY) = $this->predictMvP8x8(
                        $mbX, $mbY, $subX, $partY, 1,
                        $mbMvs, $topRowMvs, $leftColMvs,
                        $refIdx
                    );
                    $mvX = $predMvX + $mvdX;
                    $mvY = $predMvY + $mvdY;

                    $mv = [$mvX, $mvY, $refIdx];
                    $mbMvs[$partY][$subX] = $mv;
                    $mbMvs[$partY + 1][$subX] = $mv;

                    $this->performMotionCompensation4x8($mbX, $mbY, $blkX8, $blkY8, $sub, $mvX, $mvY, $refIdx);
                }

            } elseif ($subMbType === 3) {
                for ($sub = 0; $sub < 4; $sub++) {
                    $subX = $partX + ($sub % 2);
                    $subY = $partY + (int)($sub / 2);
                    $mvdX = $mvds[$mbPartIdx][$sub][0];
                    $mvdY = $mvds[$mbPartIdx][$sub][1];

                    list($predMvX, $predMvY) = $this->predictMvP8x8(
                        $mbX, $mbY, $subX, $subY, 1,
                        $mbMvs, $topRowMvs, $leftColMvs,
                        $refIdx
                    );
                    $mvX = $predMvX + $mvdX;
                    $mvY = $predMvY + $mvdY;

                    $mbMvs[$subY][$subX] = [$mvX, $mvY, $refIdx];

                    $this->performMotionCompensation4x4($mbX, $mbY, $blkX8, $blkY8, $sub, $mvX, $mvY, $refIdx);
                }

            } else {
                break;
            }
        }

        $mbQpDelta = 0;
        $cbpCode = $this->reader->readUe();
        $codedBlockPattern = self::GOLOMB_TO_INTER_CBP[$cbpCode] ?? 0;
        if ($codedBlockPattern !== 0) {
            $mbQpDelta = $this->reader->readSe();
            $qp = $sliceQp + $mbQpDelta;
            $qp = max(0, min(51, $qp));
            $this->decodeResidualAndAdd($mbX, $mbY, $codedBlockPattern, $qp, 0);
        }

        if ($codedBlockPattern === 0) {
            $this->updateNzCountZero($mbX, $mbY);
        }

        $this->saveMvForPrediction8x8($mbX, $mbY, $mbMvs);

        $this->updateInterMbIntraModes($mbX);

        return $mbQpDelta;
    }

    /**
     * P_8x8ref0 宏块解码（ref_idx固定为0）
     * 码流顺序: 先所有4个sub_mb_type, 再所有sub-partition的mvd (ref_idx固定为0)
     * 4x4子块粒度，每个子划分独立预测MV
     */
    private function decodeP_8x8ref0(int $mbX, int $mbY, int $sliceQp): int
    {
        $refIdx = 0;

        $mbMvs = array_fill(0, 4, array_fill(0, 4, null));

        $subMbScan = [[0, 0], [1, 0], [0, 1], [1, 1]];

        $leftColMvs = $this->mvLeftCol;
        $topRowMvs = $this->mvTopRow;

        $subMbTypes = [];
        $mvds = [];

        $subPartCount = [1, 2, 2, 4];

        for ($i = 0; $i < 4; $i++) {
            $subMbTypes[$i] = $this->reader->readUe();
        }

        for ($i = 0; $i < 4; $i++) {
            $subType = $subMbTypes[$i];
            $partCount = $subPartCount[$subType] ?? 1;
            $mvds[$i] = [];
            for ($j = 0; $j < $partCount; $j++) {
                $mvdX = $this->reader->readSe();
                $mvdY = $this->reader->readSe();
                $mvds[$i][$j] = [$mvdX, $mvdY];
            }
        }

        for ($mbPartIdx = 0; $mbPartIdx < 4; $mbPartIdx++) {
            $blkX8 = $subMbScan[$mbPartIdx][0];
            $blkY8 = $subMbScan[$mbPartIdx][1];
            $subMbType = $subMbTypes[$mbPartIdx];

            $partX = $blkX8 * 2;
            $partY = $blkY8 * 2;

            if ($subMbType === 0) {
                $mvdX = $mvds[$mbPartIdx][0][0];
                $mvdY = $mvds[$mbPartIdx][0][1];

                list($predMvX, $predMvY) = $this->predictMvP8x8(
                    $mbX, $mbY, $partX, $partY, 2,
                    $mbMvs, $topRowMvs, $leftColMvs,
                    $refIdx
                );
                $mvX = $predMvX + $mvdX;
                $mvY = $predMvY + $mvdY;

                $mv = [$mvX, $mvY, $refIdx];
                $mbMvs[$partY][$partX] = $mv;
                $mbMvs[$partY][$partX + 1] = $mv;
                $mbMvs[$partY + 1][$partX] = $mv;
                $mbMvs[$partY + 1][$partX + 1] = $mv;

                $this->performMotionCompensation8x8($mbX, $mbY, $blkX8, $blkY8, $mvX, $mvY, $refIdx);

            } elseif ($subMbType === 1) {
                for ($sub = 0; $sub < 2; $sub++) {
                    $subY = $partY + $sub;
                    $mvdX = $mvds[$mbPartIdx][$sub][0];
                    $mvdY = $mvds[$mbPartIdx][$sub][1];

                    list($predMvX, $predMvY) = $this->predictMvP8x8(
                        $mbX, $mbY, $partX, $subY, 2,
                        $mbMvs, $topRowMvs, $leftColMvs,
                        $refIdx
                    );
                    $mvX = $predMvX + $mvdX;
                    $mvY = $predMvY + $mvdY;

                    $mv = [$mvX, $mvY, $refIdx];
                    $mbMvs[$subY][$partX] = $mv;
                    $mbMvs[$subY][$partX + 1] = $mv;

                    $this->performMotionCompensation8x4($mbX, $mbY, $blkX8, $blkY8, $sub, $mvX, $mvY, $refIdx);
                }

            } elseif ($subMbType === 2) {
                for ($sub = 0; $sub < 2; $sub++) {
                    $subX = $partX + $sub;
                    $mvdX = $mvds[$mbPartIdx][$sub][0];
                    $mvdY = $mvds[$mbPartIdx][$sub][1];

                    list($predMvX, $predMvY) = $this->predictMvP8x8(
                        $mbX, $mbY, $subX, $partY, 1,
                        $mbMvs, $topRowMvs, $leftColMvs,
                        $refIdx
                    );
                    $mvX = $predMvX + $mvdX;
                    $mvY = $predMvY + $mvdY;

                    $mv = [$mvX, $mvY, $refIdx];
                    $mbMvs[$partY][$subX] = $mv;
                    $mbMvs[$partY + 1][$subX] = $mv;

                    $this->performMotionCompensation4x8($mbX, $mbY, $blkX8, $blkY8, $sub, $mvX, $mvY, $refIdx);
                }

            } elseif ($subMbType === 3) {
                for ($sub = 0; $sub < 4; $sub++) {
                    $subX = $partX + ($sub % 2);
                    $subY = $partY + (int)($sub / 2);
                    $mvdX = $mvds[$mbPartIdx][$sub][0];
                    $mvdY = $mvds[$mbPartIdx][$sub][1];

                    list($predMvX, $predMvY) = $this->predictMvP8x8(
                        $mbX, $mbY, $subX, $subY, 1,
                        $mbMvs, $topRowMvs, $leftColMvs,
                        $refIdx
                    );
                    $mvX = $predMvX + $mvdX;
                    $mvY = $predMvY + $mvdY;

                    $mbMvs[$subY][$subX] = [$mvX, $mvY, $refIdx];

                    $this->performMotionCompensation4x4($mbX, $mbY, $blkX8, $blkY8, $sub, $mvX, $mvY, $refIdx);
                }

            } else {
                break;
            }
        }

        $cbpCode = $this->reader->readUe();
        $codedBlockPattern = self::GOLOMB_TO_INTER_CBP[$cbpCode] ?? 0;
        $mbQpDelta = 0;
        if ($codedBlockPattern !== 0) {
            $mbQpDelta = $this->reader->readSe();
            $qp = $sliceQp + $mbQpDelta;
            $qp = max(0, min(51, $qp));
            $this->decodeResidualAndAdd($mbX, $mbY, $codedBlockPattern, $qp, 0);
        }

        if ($codedBlockPattern === 0) {
            $this->updateNzCountZero($mbX, $mbY);
        }

        $this->saveMvForPrediction8x8($mbX, $mbY, $mbMvs);

        $this->updateInterMbIntraModes($mbX);

        $mbWidth = $this->picWidthInMbs;
        $mbIdx = $mbY * $mbWidth + $mbX;
        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                $idx = $y * 4 + $x;
                $mv = $mbMvs[$y][$x];
                if ($mv !== null) {
                    $this->mbMvForDeblock[$mbIdx][$idx] = [$mv[0], $mv[1]];
                    $this->mbRefForDeblock[$mbIdx][$idx] = $mv[2];
                }
            }
        }

        return $mbQpDelta;
    }

    /**
     * 执行8x8运动补偿
     */
    private function performMotionCompensation8x8(int $mbX, int $mbY, int $blkX, int $blkY, int $mvX, int $mvY, int $refIdx): void
    {
        if ($this->refFrameY === null) {
            return;
        }

        $lumaRefX = $mbX * 64 + $blkX * 32 + $mvX;
        $lumaRefY = $mbY * 64 + $blkY * 32 + $mvY;

        $lumaPred = $this->mcLuma(
            $this->refFrameY, $this->refStrideY,
            $this->refWidthY, $this->refHeightY,
            $lumaRefX, $lumaRefY, 8, 8
        );

        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $this->writeLumaPixel($mbX, $mbY, $blkX * 8 + $x, $blkY * 8 + $y, $lumaPred[$y][$x]);
            }
        }

        // 色度：8x8亮度对应4x4色度
        $chromaRefX = $mbX * 64 + $blkX * 32 + $mvX;
        $chromaRefY = $mbY * 64 + $blkY * 32 + $mvY;

        $cbPred = $this->mcChroma(
            $this->refFrameU, $this->refStrideUv,
            $this->refWidthUv, $this->refHeightUv,
            $chromaRefX, $chromaRefY, 4, 4
        );
        $crPred = $this->mcChroma(
            $this->refFrameV, $this->refStrideUv,
            $this->refWidthUv, $this->refHeightUv,
            $chromaRefX, $chromaRefY, 4, 4
        );

        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                $this->writeChromaPixel($mbX, $mbY, $blkX * 4 + $x, $blkY * 4 + $y, $cbPred[$y][$x], 0);
                $this->writeChromaPixel($mbX, $mbY, $blkX * 4 + $x, $blkY * 4 + $y, $crPred[$y][$x], 1);
            }
        }
    }

    /**
     * 执行8x4运动补偿
     * @param int $subPartIdx 0=上半, 1=下半
     */
    private function performMotionCompensation8x4(int $mbX, int $mbY, int $blkX, int $blkY, int $subPartIdx, int $mvX, int $mvY, int $refIdx): void
    {
        if ($this->refFrameY === null) return;

        $yOffset = $subPartIdx * 4;
        $lumaRefX = $mbX * 64 + $blkX * 32 + $mvX;
        $lumaRefY = $mbY * 64 + $blkY * 32 + $yOffset * 4 + $mvY;

        $lumaPred = $this->mcLuma(
            $this->refFrameY, $this->refStrideY,
            $this->refWidthY, $this->refHeightY,
            $lumaRefX, $lumaRefY, 8, 4
        );

        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $this->writeLumaPixel($mbX, $mbY, $blkX * 8 + $x, $blkY * 8 + $yOffset + $y, $lumaPred[$y][$x]);
            }
        }

        // 色度
        $chromaRefX = $mbX * 64 + $blkX * 32 + $mvX;
        $chromaRefY = $mbY * 64 + $blkY * 32 + $yOffset * 4 + $mvY;

        $cbPred = $this->mcChroma(
            $this->refFrameU, $this->refStrideUv,
            $this->refWidthUv, $this->refHeightUv,
            $chromaRefX, $chromaRefY, 4, 2
        );
        $crPred = $this->mcChroma(
            $this->refFrameV, $this->refStrideUv,
            $this->refWidthUv, $this->refHeightUv,
            $chromaRefX, $chromaRefY, 4, 2
        );

        for ($y = 0; $y < 2; $y++) {
            for ($x = 0; $x < 4; $x++) {
                $this->writeChromaPixel($mbX, $mbY, $blkX * 4 + $x, $blkY * 4 + (int)($yOffset / 2) + $y, $cbPred[$y][$x], 0);
                $this->writeChromaPixel($mbX, $mbY, $blkX * 4 + $x, $blkY * 4 + (int)($yOffset / 2) + $y, $crPred[$y][$x], 1);
            }
        }
    }

    /**
     * 执行4x8运动补偿
     * @param int $subPartIdx 0=左半, 1=右半
     */
    private function performMotionCompensation4x8(int $mbX, int $mbY, int $blkX, int $blkY, int $subPartIdx, int $mvX, int $mvY, int $refIdx): void
    {
        if ($this->refFrameY === null) return;

        $xOffset = $subPartIdx * 4;
        $lumaRefX = $mbX * 64 + $blkX * 32 + $xOffset * 4 + $mvX;
        $lumaRefY = $mbY * 64 + $blkY * 32 + $mvY;

        $lumaPred = $this->mcLuma(
            $this->refFrameY, $this->refStrideY,
            $this->refWidthY, $this->refHeightY,
            $lumaRefX, $lumaRefY, 4, 8
        );

        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 4; $x++) {
                $this->writeLumaPixel($mbX, $mbY, $blkX * 8 + $xOffset + $x, $blkY * 8 + $y, $lumaPred[$y][$x]);
            }
        }

        // 色度
        $chromaRefX = $mbX * 64 + $blkX * 32 + $xOffset * 4 + $mvX;
        $chromaRefY = $mbY * 64 + $blkY * 32 + $mvY;

        $cbPred = $this->mcChroma(
            $this->refFrameU, $this->refStrideUv,
            $this->refWidthUv, $this->refHeightUv,
            $chromaRefX, $chromaRefY, 2, 4
        );
        $crPred = $this->mcChroma(
            $this->refFrameV, $this->refStrideUv,
            $this->refWidthUv, $this->refHeightUv,
            $chromaRefX, $chromaRefY, 2, 4
        );

        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 2; $x++) {
                $this->writeChromaPixel($mbX, $mbY, $blkX * 4 + (int)($xOffset / 2) + $x, $blkY * 4 + $y, $cbPred[$y][$x], 0);
                $this->writeChromaPixel($mbX, $mbY, $blkX * 4 + (int)($xOffset / 2) + $x, $blkY * 4 + $y, $crPred[$y][$x], 1);
            }
        }
    }

    /**
     * 执行4x4运动补偿
     * @param int $subPartIdx 0=top-left, 1=top-right, 2=bottom-left, 3=bottom-right
     */
    private function performMotionCompensation4x4(int $mbX, int $mbY, int $blkX, int $blkY, int $subPartIdx, int $mvX, int $mvY, int $refIdx): void
    {
        if ($this->refFrameY === null) return;

        $subX = $subPartIdx % 2;
        $subY = intdiv($subPartIdx, 2);
        $lumaRefX = $mbX * 64 + $blkX * 32 + $subX * 16 + $mvX;
        $lumaRefY = $mbY * 64 + $blkY * 32 + $subY * 16 + $mvY;

        $lumaPred = $this->mcLuma(
            $this->refFrameY, $this->refStrideY,
            $this->refWidthY, $this->refHeightY,
            $lumaRefX, $lumaRefY, 4, 4
        );

        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                $this->writeLumaPixel($mbX, $mbY, $blkX * 8 + $subX * 4 + $x, $blkY * 8 + $subY * 4 + $y, $lumaPred[$y][$x]);
            }
        }

        $chromaRefX = $mbX * 64 + $blkX * 32 + $subX * 16 + $mvX;
        $chromaRefY = $mbY * 64 + $blkY * 32 + $subY * 16 + $mvY;

        $cbPred = $this->mcChroma(
            $this->refFrameU, $this->refStrideUv,
            $this->refWidthUv, $this->refHeightUv,
            $chromaRefX, $chromaRefY, 2, 2
        );
        $crPred = $this->mcChroma(
            $this->refFrameV, $this->refStrideUv,
            $this->refWidthUv, $this->refHeightUv,
            $chromaRefX, $chromaRefY, 2, 2
        );

        for ($y = 0; $y < 2; $y++) {
            for ($x = 0; $x < 2; $x++) {
                $this->writeChromaPixel($mbX, $mbY, $blkX * 4 + $subX * 2 + $x, $blkY * 4 + $subY * 2 + $y, $cbPred[$y][$x], 0);
                $this->writeChromaPixel($mbX, $mbY, $blkX * 4 + $subX * 2 + $x, $blkY * 4 + $subY * 2 + $y, $crPred[$y][$x], 1);
            }
        }
    }

    // ====================== 运动向量预测辅助函数 ======================

    /**
     * 获取P帧16x16宏块的运动向量预测值
     * C邻居（top-right）不可用时，回退到top-left（D邻居）
     */
    private function getP16x16MvPrediction(int $mbX, int $mbY, int $refIdx): array
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
     * 获取P帧Skip宏块的运动向量预测值
     * P_Skip有特殊快速路径：A或B不可用时直接返回(0,0)；A或B为ref=0且mv=0时直接返回(0,0)
     */
    private function getPSkipMvPrediction(int $mbX, int $mbY): array
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
     * 获取P帧16x8宏块的运动向量预测值
     * @param int $partIdx 0=上半部分, 1=下半部分
     * @param array|null $mvPart0 上半部分的MV（仅partIdx=1时使用）
     */
    private function getP16x8MvPrediction(int $mbX, int $mbY, int $partIdx, int $refIdx, ?array $mvPart0 = null): array
    {
        $mbWidth = $this->picWidthInMbs;

        $mvLeft = null;
        $mvTop = null;
        $mvTopRight = null;

        if ($mbX > 0) {
            $rowIdx = $partIdx * 2;
            if (isset($this->mvLeftCol[$rowIdx])) {
                $mvLeft = $this->mvLeftCol[$rowIdx];
            }
        }

        if ($partIdx === 0) {
            if ($mbY > 0) {
                $mvTop = $this->mvTopRow[$mbX * 4] ?? null;
            }
        } else {
            $mvTop = $mvPart0;
        }

        if ($partIdx === 0) {
            // part0: C = top-right of current mb (topright 宏块的左下角)
            if ($mbX + 1 < $mbWidth && $mbY > 0) {
                $mvTopRight = $this->mvTopRow[($mbX + 1) * 4] ?? null;
            }
        } else {
            // part1: C 位置 (scan8[8]-8+part_width=24) 未被填充，回退到
            // D = scan8[8]-8-1 = left of (0,1) = mvLeftCol[1] (左宏块 (3,1) 位置)
            if ($mbX > 0 && isset($this->mvLeftCol[1])) {
                $mvTopRight = $this->mvLeftCol[1];
            }
        }

        // 标准 FFmpeg pred_16x8_motion (h264_mvpred.h):
        //   part0: 若 top 可用且 top_ref == ref，直接返回 top_mv
        //   part1: 若 left 可用且 left_ref == ref，直接返回 left_mv
        //   否则调用 pred_motion (median)
        if ($partIdx === 0) {
            if ($mvTop !== null && $mvTop[2] === $refIdx) {
                return [$mvTop[0], $mvTop[1]];
            }
        } else {
            if ($mvLeft !== null && $mvLeft[2] === $refIdx) {
                return [$mvLeft[0], $mvLeft[1]];
            }
        }

        return $this->predictMvP16x16($mvLeft, $mvTop, $mvTopRight, $refIdx);
    }

    /**
     * 获取P帧8x16宏块的运动向量预测值
     * @param int $partIdx 0=左半部分, 1=右半部分
     * @param array|null $mvPart0 左半部分的MV（仅partIdx=1时使用）
     */
    private function getP8x16MvPrediction(int $mbX, int $mbY, int $partIdx, int $refIdx, ?array $mvPart0 = null): array
    {
        $mbWidth = $this->picWidthInMbs;

        $mvLeft = null;
        $mvTop = null;
        $mvTopRight = null;

        if ($partIdx === 0) {
            if ($mbX > 0) {
                if (isset($this->mvLeftCol[1])) {
                    $mvLeft = $this->mvLeftCol[1];
                } elseif (isset($this->mvLeftCol[0])) {
                    $mvLeft = $this->mvLeftCol[0];
                }
            }
        } else {
            $mvLeft = $mvPart0;
        }

        if ($mbY > 0) {
            $colIdx = $mbX * 4 + $partIdx * 2;
            if (isset($this->mvTopRow[$colIdx])) {
                $mvTop = $this->mvTopRow[$colIdx];
            }
        }

        if ($partIdx === 0) {
            if ($mbY > 0) {
                $mvTopRight = $this->mvTopRow[$mbX * 4 + 2] ?? null;
            }
        } else {
            if ($mbX + 1 < $mbWidth && $mbY > 0) {
                $mvTopRight = $this->mvTopRow[($mbX + 1) * 4] ?? null;
            }
        }

        if ($partIdx === 0) {
            if ($mvLeft !== null && $mvLeft[2] === $refIdx) {
                return [$mvLeft[0], $mvLeft[1]];
            }
        } else {
            if ($mvTopRight !== null && $mvTopRight[2] === $refIdx) {
                return [$mvTopRight[0], $mvTopRight[1]];
            }
        }

        return $this->predictMvP16x16($mvLeft, $mvTop, $mvTopRight, $refIdx);
    }

    /**
     * 保存运动向量用于后续预测（16x16宏块）
     * 每个8x8子块的MV都相同
     */
    private function saveMvForPrediction(int $mbX, int $mbY, int $mvX, int $mvY, int $refIdx): void
    {
        $mv = [$mvX, $mvY, $refIdx];
        $this->mvLeftCol = [$mv, $mv, $mv, $mv];
        $this->mvTopRow[$mbX * 4 + 0] = $mv;
        $this->mvTopRow[$mbX * 4 + 1] = $mv;
        $this->mvTopRow[$mbX * 4 + 2] = $mv;
        $this->mvTopRow[$mbX * 4 + 3] = $mv;
    }

    /**
     * 保存运动向量（16x8宏块）
     * 上下分割：上半部分mv0，下半部分mv1
     */
    private function saveMvForPrediction16x8(int $mbX, int $mbY, int $mv0X, int $mv0Y, int $refIdx0, int $mv1X, int $mv1Y, int $refIdx1): void
    {
        $mvTop = [$mv0X, $mv0Y, $refIdx0];
        $mvBottom = [$mv1X, $mv1Y, $refIdx1];
        $this->mvLeftCol = [$mvTop, $mvTop, $mvBottom, $mvBottom];
        $this->mvTopRow[$mbX * 4 + 0] = $mvBottom;
        $this->mvTopRow[$mbX * 4 + 1] = $mvBottom;
        $this->mvTopRow[$mbX * 4 + 2] = $mvBottom;
        $this->mvTopRow[$mbX * 4 + 3] = $mvBottom;
    }

    /**
     * 保存运动向量（8x16宏块）
     * 左右分割：左半部分mv0，右半部分mv1
     */
    private function saveMvForPrediction8x16(int $mbX, int $mbY, int $mv0X, int $mv0Y, int $refIdx0, int $mv1X, int $mv1Y, int $refIdx1): void
    {
        $mvLeft = [$mv0X, $mv0Y, $refIdx0];
        $mvRight = [$mv1X, $mv1Y, $refIdx1];
        $this->mvLeftCol = [$mvRight, $mvRight, $mvRight, $mvRight];
        $this->mvTopRow[$mbX * 4 + 0] = $mvLeft;
        $this->mvTopRow[$mbX * 4 + 1] = $mvLeft;
        $this->mvTopRow[$mbX * 4 + 2] = $mvRight;
        $this->mvTopRow[$mbX * 4 + 3] = $mvRight;
    }

    /**
     * 保存运动向量（8x8宏块的4x4子块）
     * mbMvs[blkY][blkX] = [mvX, mvY, refIdx] (4x4=16个子块)
     */
    private function saveMvForPrediction8x8(int $mbX, int $mbY, array $mbMvs): void
    {
        $this->mvLeftCol[0] = $mbMvs[0][3];
        $this->mvLeftCol[1] = $mbMvs[1][3];
        $this->mvLeftCol[2] = $mbMvs[2][3];
        $this->mvLeftCol[3] = $mbMvs[3][3];
        $this->mvTopRow[$mbX * 4 + 0] = $mbMvs[3][0];
        $this->mvTopRow[$mbX * 4 + 1] = $mbMvs[3][1];
        $this->mvTopRow[$mbX * 4 + 2] = $mbMvs[3][2];
        $this->mvTopRow[$mbX * 4 + 3] = $mbMvs[3][3];
    }

    // ====================== 运动补偿辅助函数 ======================

    /**
     * 执行16x16运动补偿
     */
    private function performMotionCompensation16x16(int $mbX, int $mbY, int $mvX, int $mvY, int $refIdx): void
    {
        if ($this->refFrameY === null) {
            $this->fillMacroblockGray($mbX, $mbY);
            return;
        }

        $lumaRefX = $mbX * 64 + $mvX;
        $lumaRefY = $mbY * 64 + $mvY;

        $lumaPred = $this->mcLuma(
            $this->refFrameY, $this->refStrideY,
            $this->refWidthY, $this->refHeightY,
            $lumaRefX, $lumaRefY, 16, 16
        );

        for ($y = 0; $y < 16; $y++) {
            for ($x = 0; $x < 16; $x++) {
                $this->writeLumaPixel($mbX, $mbY, $x, $y, $lumaPred[$y][$x]);
            }
        }

        $chromaRefX = $mbX * 64 + $mvX;
        $chromaRefY = $mbY * 64 + $mvY;

        $cbPred = $this->mcChroma(
            $this->refFrameU, $this->refStrideUv,
            $this->refWidthUv, $this->refHeightUv,
            $chromaRefX, $chromaRefY, 8, 8
        );
        $crPred = $this->mcChroma(
            $this->refFrameV, $this->refStrideUv,
            $this->refWidthUv, $this->refHeightUv,
            $chromaRefX, $chromaRefY, 8, 8
        );

        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $this->writeChromaPixel($mbX, $mbY, $x, $y, $cbPred[$y][$x], 0);
                $this->writeChromaPixel($mbX, $mbY, $x, $y, $crPred[$y][$x], 1);
            }
        }
    }

    /**
     * 执行16x8运动补偿
     * @param int $partIdx 0=上半部分, 1=下半部分
     */
    private function performMotionCompensation16x8(int $mbX, int $mbY, int $partIdx, int $mvX, int $mvY, int $refIdx): void
    {
        if ($this->refFrameY === null) {
            $this->fillMacroblockGray($mbX, $mbY);
            return;
        }

        $yOffset = $partIdx * 8;

        $lumaRefX = $mbX * 64 + $mvX;
        $lumaRefY = $mbY * 64 + $mvY + $yOffset * 4;

        $lumaPred = $this->mcLuma(
            $this->refFrameY, $this->refStrideY,
            $this->refWidthY, $this->refHeightY,
            $lumaRefX, $lumaRefY, 16, 8
        );

        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 16; $x++) {
                $this->writeLumaPixel($mbX, $mbY, $x, $yOffset + $y, $lumaPred[$y][$x]);
            }
        }

        $chromaRefX = $mbX * 64 + $mvX;
        $chromaRefY = $mbY * 64 + $mvY + $yOffset * 4;

        $cbPred = $this->mcChroma(
            $this->refFrameU, $this->refStrideUv,
            $this->refWidthUv, $this->refHeightUv,
            $chromaRefX, $chromaRefY, 8, 4
        );
        $crPred = $this->mcChroma(
            $this->refFrameV, $this->refStrideUv,
            $this->refWidthUv, $this->refHeightUv,
            $chromaRefX, $chromaRefY, 8, 4
        );

        $chromaYOffset = $partIdx * 4;
        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $this->writeChromaPixel($mbX, $mbY, $x, $chromaYOffset + $y, $cbPred[$y][$x], 0);
                $this->writeChromaPixel($mbX, $mbY, $x, $chromaYOffset + $y, $crPred[$y][$x], 1);
            }
        }
    }

    /**
     * 执行8x16运动补偿
     * @param int $partIdx 0=左半部分, 1=右半部分
     */
    private function performMotionCompensation8x16(int $mbX, int $mbY, int $partIdx, int $mvX, int $mvY, int $refIdx): void
    {
        if ($this->refFrameY === null) {
            $this->fillMacroblockGray($mbX, $mbY);
            return;
        }

        $xOffset = $partIdx * 8;

        $lumaRefX = $mbX * 64 + $mvX + $xOffset * 4;
        $lumaRefY = $mbY * 64 + $mvY;

        $lumaPred = $this->mcLuma(
            $this->refFrameY, $this->refStrideY,
            $this->refWidthY, $this->refHeightY,
            $lumaRefX, $lumaRefY, 8, 16
        );

        for ($y = 0; $y < 16; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $this->writeLumaPixel($mbX, $mbY, $xOffset + $x, $y, $lumaPred[$y][$x]);
            }
        }

        $chromaRefX = $mbX * 64 + $mvX + $xOffset * 4;
        $chromaRefY = $mbY * 64 + $mvY;

        $cbPred = $this->mcChroma(
            $this->refFrameU, $this->refStrideUv,
            $this->refWidthUv, $this->refHeightUv,
            $chromaRefX, $chromaRefY, 4, 8
        );
        $crPred = $this->mcChroma(
            $this->refFrameV, $this->refStrideUv,
            $this->refWidthUv, $this->refHeightUv,
            $chromaRefX, $chromaRefY, 4, 8
        );

        $chromaXOffset = $partIdx * 4;
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 4; $x++) {
                $this->writeChromaPixel($mbX, $mbY, $chromaXOffset + $x, $y, $cbPred[$y][$x], 0);
                $this->writeChromaPixel($mbX, $mbY, $chromaXOffset + $x, $y, $crPred[$y][$x], 1);
            }
        }
    }

    /**
     * 解码残差并加到预测值上（Inter帧方式，与Intra4x4类似）
     */
    private function decodeResidualAndAdd(int $mbX, int $mbY, int $codedBlockPattern, int $qp, int $mbType): void
    {
        $cbp = $codedBlockPattern;
        $lumaCbp = $cbp & 0x0F;
        $chromaCbp = ($cbp >> 4) & 0x03;

        $mbWidth = $this->picWidthInMbs;
        $leftAvailable = ($mbX > 0);
        $topAvailable = ($mbY > 0);

        $nzCache = array_fill(0, 24, 0);
        $leftNz = array_fill(0, 8, 0);
        $topNz = array_fill(0, $mbWidth * 4 + $mbWidth * 4, 0);

        if ($leftAvailable) {
            for ($y = 0; $y < 4; $y++) $leftNz[$y] = $this->nzLeftColLuma[$y];
            $leftNz[4] = $this->nzLeftColChroma[0];
            $leftNz[5] = $this->nzLeftColChroma[1];
            $leftNz[6] = $this->nzLeftColChroma[2];
            $leftNz[7] = $this->nzLeftColChroma[3];
        }
        if ($topAvailable) {
            $baseLuma = $mbX * 4;
            for ($x = 0; $x < 4; $x++) $topNz[$baseLuma + $x] = $this->nzTopRowLuma[$baseLuma + $x];
            for ($x = 0; $x < 2; $x++) $topNz[$mbWidth * 4 + $mbX * 2 + $x] = $this->nzTopRowChroma[$mbX * 2 + $x];
            for ($x = 0; $x < 2; $x++) $topNz[$mbWidth * 4 + $mbWidth * 2 + $mbX * 2 + $x] = $this->nzTopRowChroma[$mbWidth * 2 + $mbX * 2 + $x];
        }

        $yCoeffs = array_fill(0, 16, array_fill(0, 16, 0));
        $scanToRaster = [0, 1, 4, 5, 2, 3, 6, 7, 8, 9, 12, 13, 10, 11, 14, 15];

        // 亮度4x4残差 - Inter帧每个块有16个系数（DC+AC一起）
        // 按zigzag扫描顺序解码，每个块计算nC（与Intra4x4相同）
        for ($i8x8 = 0; $i8x8 < 4; $i8x8++) {
            if (($lumaCbp & (1 << $i8x8)) !== 0) {
                for ($i4x4 = 0; $i4x4 < 4; $i4x4++) {
                    $scanIdx = $i8x8 * 4 + $i4x4;
                    $rasterIdx = $scanToRaster[$scanIdx];
                    $nc = $this->computeNc($rasterIdx, $mbX, $mbY, $nzCache, $leftNz, $topNz, $leftAvailable, $topAvailable);
                    $coeffs = $this->decodeResidualBlock(16, $nc);
                    for ($i = 0; $i < 16; $i++) $yCoeffs[$rasterIdx][$i] = $coeffs[$i];
                    $yCoeffs[$rasterIdx] = $this->zigzagToRaster($yCoeffs[$rasterIdx]);
                    $yCoeffs[$rasterIdx] = $this->dequantize4x4($yCoeffs[$rasterIdx], 3, $qp);
                    $nzCount = 0;
                    for ($i = 0; $i < 16; $i++) if ($coeffs[$i] != 0) $nzCount++;
                    $nzCache[$rasterIdx] = $nzCount;
                }
            } else {
                for ($i4x4 = 0; $i4x4 < 4; $i4x4++) {
                    $scanIdx = $i8x8 * 4 + $i4x4;
                    $rasterIdx = $scanToRaster[$scanIdx];
                    $nzCache[$rasterIdx] = 0;
                }
            }
        }

        // 色度
        $chromaQpIndex = max(0, min(51, $qp + $this->chromaQpIndexOffset));
        $chromaQp = self::CHROMA_QP_TABLE[$chromaQpIndex];

        $cbDc = array_fill(0, 4, 0);
        $crDc = array_fill(0, 4, 0);
        $cbAcCoeffs = array_fill(0, 4, array_fill(0, 16, 0));
        $crAcCoeffs = array_fill(0, 4, array_fill(0, 16, 0));

        $isDebugSlice = ($this->debugTargetSlice > 0 && $this->debugSliceIndex === $this->debugTargetSlice);
        $isDebugMb = $isDebugSlice && $mbY === 0 && ($mbX === 1 || $mbX === 2);

        if ($chromaCbp >= 1) {
            $cbDc = $this->decodeResidualBlock(4, -1);
            $crDc = $this->decodeResidualBlock(4, -1);
        }

        $cbQmul = $this->dequant4Table[5][$chromaQp][0];
        $crQmul = $this->dequant4Table[4][$chromaQp][0];
        $cbDcResult = $this->chromaDcDequantIdct($cbDc, $cbQmul);
        $crDcResult = $this->chromaDcDequantIdct($crDc, $crQmul);

        if ($chromaCbp === 2 || $chromaCbp === 3) {
            $cbScanOrder = [16, 17, 18, 19];
            foreach ($cbScanOrder as $blockIdx) {
                $blk = $blockIdx - 16;
                $nc = $this->computeNc($blockIdx, $mbX, $mbY, $nzCache, $leftNz, $topNz, $leftAvailable, $topAvailable);
                $ac = $this->decodeResidualBlock(15, $nc);
                $nzCnt = 0; for ($i = 0; $i < 15; $i++) if ($ac[$i] != 0) $nzCnt++;
                for ($i = 1; $i < 16; $i++) $cbAcCoeffs[$blk][$i] = $ac[$i - 1];
                $cbAcCoeffs[$blk] = $this->zigzagToRaster($cbAcCoeffs[$blk]);
                $cbAcCoeffs[$blk] = $this->dequantize4x4($cbAcCoeffs[$blk], 5, $chromaQp);
                $nzCache[$blockIdx] = $nzCnt;
            }
            $crScanOrder = [20, 21, 22, 23];
            foreach ($crScanOrder as $blockIdx) {
                $blk = $blockIdx - 20;
                $nc = $this->computeNc($blockIdx, $mbX, $mbY, $nzCache, $leftNz, $topNz, $leftAvailable, $topAvailable);
                $ac = $this->decodeResidualBlock(15, $nc);

                $nzCnt = 0; for ($i = 0; $i < 15; $i++) if ($ac[$i] != 0) $nzCnt++;
                for ($i = 1; $i < 16; $i++) $crAcCoeffs[$blk][$i] = $ac[$i - 1];
                $crAcCoeffs[$blk] = $this->zigzagToRaster($crAcCoeffs[$blk]);
                $crAcCoeffs[$blk] = $this->dequantize4x4($crAcCoeffs[$blk], 4, $chromaQp);
                $nzCache[$blockIdx] = $nzCnt;
            }
        }

        // 亮度残差+IDCT 并加到像素上（Inter帧：每个4x4块完整16系数）
        for ($blkY = 0; $blkY < 4; $blkY++) {
            for ($blkX = 0; $blkX < 4; $blkX++) {
                $blk = $blkY * 4 + $blkX;
                $i8x8 = (int)($blkY / 2) * 2 + (int)($blkX / 2);
                if (($lumaCbp & (1 << $i8x8)) !== 0) {
                    $block = array_fill(0, 4, array_fill(0, 4, 0));
                    for ($y = 0; $y < 4; $y++) for ($x = 0; $x < 4; $x++) $block[$y][$x] = $yCoeffs[$blk][$y * 4 + $x];
                    $idct = $this->idct4x4($block);

                    for ($y = 0; $y < 4; $y++) {
                        for ($x = 0; $x < 4; $x++) {
                            $py = $mbY * 16 + $blkY * 4 + $y;
                            $px = $mbX * 16 + $blkX * 4 + $x;
                            if ($py < $this->height && $px < $this->width) {
                                $idx = $py * $this->width + $px;
                                $val = $this->yPlane[$idx] + $idct[$y][$x];
                                $this->yPlane[$idx] = max(0, min(255, $val));
                            }
                        }
                    }
                }
            }
        }

        // 色度残差+IDCT 并加到像素上
        $cw = (int)($this->width / 2);
        $ch = (int)($this->height / 2);
        for ($blkY = 0; $blkY < 2; $blkY++) {
            for ($blkX = 0; $blkX < 2; $blkX++) {
                $blk = $blkY * 2 + $blkX;
                $dcCb = $cbDcResult[$blk];
                $dcCr = $crDcResult[$blk];

                // Cb 处理
                if ($chromaCbp >= 2) {
                    $acBlockCb = array_fill(0, 4, array_fill(0, 4, 0));
                    for ($y = 0; $y < 4; $y++) for ($x = 0; $x < 4; $x++) {
                        $acBlockCb[$y][$x] = $cbAcCoeffs[$blk][$y * 4 + $x];
                    }
                    $acBlockCb[0][0] = $dcCb;
                    $acIdctCb = $this->idct4x4($acBlockCb);
                    for ($y = 0; $y < 4; $y++) {
                        for ($x = 0; $x < 4; $x++) {
                            $py = $mbY * 8 + $blkY * 4 + $y;
                            $px = $mbX * 8 + $blkX * 4 + $x;
                            if ($py < $ch && $px < $cw) {
                                $idx = $py * $cw + $px;
                                $val = $this->uPlane[$idx] + $acIdctCb[$y][$x];
                                $this->uPlane[$idx] = max(0, min(255, $val));
                            }
                        }
                    }
                } else {
                    $dcAddCb = ($dcCb + 32) >> 6;
                    for ($y = 0; $y < 4; $y++) {
                        for ($x = 0; $x < 4; $x++) {
                            $py = $mbY * 8 + $blkY * 4 + $y;
                            $px = $mbX * 8 + $blkX * 4 + $x;
                            if ($py < $ch && $px < $cw) {
                                $idx = $py * $cw + $px;
                                $val = $this->uPlane[$idx] + $dcAddCb;
                                $this->uPlane[$idx] = max(0, min(255, $val));
                            }
                        }
                    }
                }

                // Cr 处理
                if ($chromaCbp >= 2) {
                    $acBlockCr = array_fill(0, 4, array_fill(0, 4, 0));
                    for ($y = 0; $y < 4; $y++) for ($x = 0; $x < 4; $x++) {
                        $acBlockCr[$y][$x] = $crAcCoeffs[$blk][$y * 4 + $x];
                    }
                    $acBlockCr[0][0] = $dcCr;
                    $acIdctCr = $this->idct4x4($acBlockCr);
                    for ($y = 0; $y < 4; $y++) {
                        for ($x = 0; $x < 4; $x++) {
                            $py = $mbY * 8 + $blkY * 4 + $y;
                            $px = $mbX * 8 + $blkX * 4 + $x;
                            if ($py < $ch && $px < $cw) {
                                $idx = $py * $cw + $px;
                                $val = $this->vPlane[$idx] + $acIdctCr[$y][$x];
                                $this->vPlane[$idx] = max(0, min(255, $val));
                            }
                        }
                    }
                } else {
                    $dcAddCr = ($dcCr + 32) >> 6;
                    for ($y = 0; $y < 4; $y++) {
                        for ($x = 0; $x < 4; $x++) {
                            $py = $mbY * 8 + $blkY * 4 + $y;
                            $px = $mbX * 8 + $blkX * 4 + $x;
                            if ($py < $ch && $px < $cw) {
                                $idx = $py * $cw + $px;
                                $val = $this->vPlane[$idx] + $dcAddCr;
                                $this->vPlane[$idx] = max(0, min(255, $val));
                            }
                        }
                    }
                }
            }
        }

        // 更新非零系数缓存
        $baseLuma = $mbX * 4;
        for ($x = 0; $x < 4; $x++) $this->nzTopRowLuma[$baseLuma + $x] = $nzCache[12 + $x];
        $this->nzLeftColLuma[0] = $nzCache[3];
        $this->nzLeftColLuma[1] = $nzCache[7];
        $this->nzLeftColLuma[2] = $nzCache[11];
        $this->nzLeftColLuma[3] = $nzCache[15];

        $this->nzTopRowChroma[$mbX * 2 + 0] = $nzCache[18];
        $this->nzTopRowChroma[$mbX * 2 + 1] = $nzCache[19];
        $this->nzTopRowChroma[$mbWidth * 2 + $mbX * 2 + 0] = $nzCache[22];
        $this->nzTopRowChroma[$mbWidth * 2 + $mbX * 2 + 1] = $nzCache[23];
        $this->nzLeftColChroma[0] = $nzCache[17];
        $this->nzLeftColChroma[1] = $nzCache[19];
        $this->nzLeftColChroma[2] = $nzCache[21];
        $this->nzLeftColChroma[3] = $nzCache[23];

        $mbIdx = $mbY * $this->picWidthInMbs + $mbX;
        $this->mbNnzForDeblock[$mbIdx] = $nzCache;
    }
}
