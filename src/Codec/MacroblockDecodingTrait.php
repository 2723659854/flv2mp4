<?php

namespace Xiaosongshu\Flv2mp4\Codec;

trait MacroblockDecodingTrait
{
    /**
     * 解码单个宏块
     * @return int mb_qp_delta
     */
    public function decodeMacroblock(int $mbX, int $mbY, int $sliceQp, int $sliceType): int
    {
        //$bitBefore = $this->reader->getBitPosition();

        //$dbgMb = ($mbX === 4 && $mbY === 0);
//        if ($dbgMb) echo "[DBG_MB(4,0)] bitPosBeforeMbType=" . $this->reader->getBitPosition() . "\n";
//        if (($mbX === 1 || $mbX === 2) && $mbY === 0) {
//            echo "[DBG_MB($mbX,$mbY)] bitPosBeforeMbType=$bitBefore\n";
//        }
        $mbType = $this->reader->readUe();
        //echo "[DECODER] MB($mbX,$mbY): 读取 mb_type = {$mbType}\n";
        //if ($dbgMb) echo "[DBG_MB(4,0)] bitPosAfterMbType=" . $this->reader->getBitPosition() . "\n";
        //if (($mbX === 1 || $mbX === 2) && $mbY === 0) {
            //echo "[DBG_MB($mbX,$mbY)] bitPosAfterMbType=" . $this->reader->getBitPosition() . " mb_type=$mbType\n";
        //}

        $isDebugSlice = ($this->debugTargetSlice > 0 && $this->debugSliceIndex === $this->debugTargetSlice);
        if ($isDebugSlice && $this->debugMbTraceFh) {
            fwrite($this->debugMbTraceFh, "MB($mbX,$mbY): mb_type=$mbType");
        }

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
            //echo "[DECODER] MB($mbX,$mbY): 不支持的sliceType={$sliceType}，填充灰色\n";
            $this->fillMacroblockGray($mbX, $mbY);
        }

        //$bitAfter = $this->reader->getBitPosition();
        //echo "[DECODER] MB($mbX,$mbY): bitPos before={$bitBefore} after={$bitAfter} consumed=" . ($bitAfter - $bitBefore) . "\n";
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
        //$bitPosStart = $this->reader->getBitPosition();

        for ($scanIdx = 0; $scanIdx < 16; $scanIdx++) {
            $rasterIdx = $scanToRaster[$scanIdx];
            $blkX = $rasterIdx % 4;
            $blkY = (int)($rasterIdx / 4);

            // 获取左边块的预测模式（参考wedeo cavlc.rs 第912-919行）
            $leftMode = -1;
            if ($blkX > 0) {
                $leftMode = $modeCache[$rasterIdx - 1];
            } elseif ($mbX > 0) {
                $leftMode = $this->intra4x4LeftModes[$blkY];
            }

            // 获取上面块的预测模式（参考wedeo cavlc.rs 第922-929行）
            $topMode = -1;
            if ($blkY > 0) {
                $topMode = $modeCache[$rasterIdx - 4];
            } elseif ($mbY > 0) {
                $absBlkX = $mbX * 4 + $blkX;
                $topMode = $this->intra4x4TopModes[$absBlkX];
            }

            // H.264标准8.3.1.1节：如果任一邻居不可用(mode<0)，predicted=DC(2)
            // 否则 predicted=min(leftMode, topMode)
            // 与FFmpeg pred_intra_mode()和wedeo cavlc.rs完全一致
            $minMode = min($leftMode, $topMode);
            $predicted = ($minMode < 0) ? 2 : $minMode;
            //$bitPosBefore = $this->reader->getBitPosition();
            $prevFlag = $this->reader->readU(1);
            if ($prevFlag) {
                $mode = $predicted;
                $remMode = -1;
            } else {
                $remMode = $this->reader->readU(3);
                $mode = $remMode >= $predicted ? $remMode + 1 : $remMode;
            }
            $modeCache[$rasterIdx] = $mode;
            $modes[$rasterIdx] = $mode;
            //if ($mbX === 2 && $mbY === 0) {
                //$bitsRead = $prevFlag ? 1 : 4;
                //echo "[DBG_MODE scan=$scanIdx raster=$rasterIdx blk($blkX,$blkY)] left=$leftMode top=$topMode pred=$predicted prev=$prevFlag rem=$remMode mode=$mode bitPos=$bitPosBefore bits=$bitsRead\n";
            //}
            //if ($mbX === 1 && $mbY === 0) {
                //$bitsRead = $prevFlag ? 1 : 4;
                //echo "[DBG_MODE_MB1 scan=$scanIdx raster=$rasterIdx blk($blkX,$blkY)] left=$leftMode top=$topMode pred=$predicted prev=$prevFlag rem=$remMode mode=$mode bitPos=$bitPosBefore bits=$bitsRead\n";
            //}
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
        //$bitPosEnd = $this->reader->getBitPosition();
//        if ($mbX === 1 && $mbY === 1) {
//            echo "[DBG_MB11] sliceQp=$sliceQp mbQpDelta=$mbQpDelta qp=$qp\n";
//        }
        //echo "[DECODER] MB($mbX,$mbY): I4x4 modes=" . implode(',', $modes) . " chromaPred=$chromaPredMode cbpCode=$cbpCode cbp=$cbp bits=" . ($bitPosEnd - $bitPosStart) . "\n";

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

        // 按扫描顺序解码（与wedeo cavlc.rs第1379-1419行完全一致）
        // i8x8: 8x8块索引(0-3), i4x4: 8x8块内的4x4子块索引(0-3)
        for ($i8x8 = 0; $i8x8 < 4; $i8x8++) {
            if (($lumaCbp & (1 << $i8x8)) !== 0) {
//                if ($mbX === 1 && $mbY === 0) {
//                    echo "[DBG_CBP MB(1,0)] i8x8=$i8x8 (8x8块), bit=" . (1 << $i8x8) . ", cbp_bit_set=1\n";
//                }
                for ($i4x4 = 0; $i4x4 < 4; $i4x4++) {
                    $scanIdx = $i8x8 * 4 + $i4x4;
                    $rasterIdx = $scanToRaster[$scanIdx];
//                    if ($mbX === 1 && $mbY === 0) {
//                        echo "[DBG_CBP MB(1,0)]   i4x4=$i4x4, scanIdx=$scanIdx, rasterIdx=$rasterIdx\n";
//                    }
                    $nc = $this->computeNc($rasterIdx, $mbX, $mbY, $nzCache, $leftNz, $topNz, $leftAvailable, $topAvailable);
                    $oldDbg = $this->debugResidual;
                    $isTargetBlk = ($mbX === 2 && $mbY === 0 && $rasterIdx === 2);
                    $isMb10 = ($mbX === 1 && $mbY === 0);
                    $isMb11blk8 = ($mbX === 1 && $mbY === 1 && $rasterIdx === 8);
                    $isMb11Any = ($mbX === 1 && $mbY === 1);
                    $isMb01Any = ($mbX === 0 && $mbY === 1);
                    if ($isTargetBlk || $isMb10 || $isMb11blk8 || $isMb11Any || $isMb01Any) {
                        //$bp = $this->reader->getBitPosition();
                        //$bits16 = $this->reader->peek(16);
                        //echo "[DEBUG MB($mbX,$mbY) Blk$rasterIdx] before decode: bitPos=$bp nc=$nc peek16=0x" . sprintf('%04X', $bits16) . " (" . str_pad(decbin($bits16), 16, '0', STR_PAD_LEFT) . ")\n";
                        //echo "[DEBUG MB($mbX,$mbY) Blk$rasterIdx] nzCache[0-7]=" . implode(',', array_slice($nzCache, 0, 8)) . " nzCache[8-15]=" . implode(',', array_slice($nzCache, 8, 8)) . "\n";
                        //echo "[DEBUG MB($mbX,$mbY) Blk$rasterIdx] leftNz=" . implode(',', $leftNz) . "\n";
                        $this->debugResidual = true;
                    }
                    $coeffs = $this->decodeResidualBlock(16, $nc);
                    $this->debugResidual = $oldDbg;
                    $totalCoeff = 0;
                    for ($i = 0; $i < 16; $i++) if ($coeffs[$i] != 0) $totalCoeff++;
                    for ($i = 0; $i < 16; $i++) $yCoeffs[$rasterIdx][$i] = $coeffs[$i];
                    $yCoeffs[$rasterIdx] = $this->zigzagToRaster($yCoeffs[$rasterIdx]);
//                    if ($mbX === 2 && $mbY === 0) {
//                        echo "[DEBUG MB(2,0) Blk$rasterIdx] nc=$nc coeffs zigzag: " . implode(',', $coeffs) . "\n";
//                    }
//                    if ($isMb10) {
//                        echo "[DEBUG MB(1,0) Blk$rasterIdx] nc=$nc coeffs zigzag: " . implode(',', $coeffs) . " nz=$totalCoeff\n";
//                    }
//                    if ($isMb11blk8) {
//                        echo "[DEBUG MB(1,1) Blk8] nc=$nc coeffs zigzag: " . implode(',', $coeffs) . " nz=$totalCoeff\n";
//                        echo "[DEBUG MB(1,1) Blk8] leftNz=" . implode(',', $leftNz) . " topNz[4..8]=" . implode(',', array_slice($topNz, 4, 4)) . "\n";
//                    }
//                    if ($mbX === 0 && $mbY === 0) {
//                        echo "[DEBUG MB(0,0) Blk$rasterIdx] qp=$qp nc=$nc scanIdx=$scanIdx\n";
//                        echo "[DEBUG MB(0,0) Blk$rasterIdx] coeffs zigzag: " . implode(',', $coeffs) . "\n";
//                        echo "[DEBUG MB(0,0) Blk$rasterIdx] nzCount=" . ($totalCoeff ?? 0) . "\n";
//                    }
                    $yCoeffs[$rasterIdx] = $this->dequantize4x4($yCoeffs[$rasterIdx], 0, $qp);
//                    if ($mbX === 0 && $mbY === 0) {
//                        echo "[DEBUG MB(0,0) Blk$rasterIdx] after dequant: " . implode(',', $yCoeffs[$rasterIdx]) . "\n";
//                    }
//                    if ($mbX === 2 && $mbY === 0 && $rasterIdx === 2) {
//                        echo "[DEBUG MB(2,0) Blk2] after dequant: " . implode(',', $yCoeffs[$rasterIdx]) . "\n";
//                    }
//                    if ($isMb10) {
//                        echo "[DEBUG MB(1,0) Blk$rasterIdx] after dequant: " . implode(',', $yCoeffs[$rasterIdx]) . "\n";
//                    }
//                    if ($isMb11blk8) {
//                        echo "[DEBUG MB(1,1) Blk8] after dequant (raster): " . implode(',', $yCoeffs[$rasterIdx]) . " qp=$qp\n";
//                    }
                    $nzCount = 0;
                    for ($i = 0; $i < 16; $i++) if ($coeffs[$i] != 0) $nzCount++;
                    $nzCache[$rasterIdx] = $nzCount;
                }
            } else {
//                if ($mbX === 1 && $mbY === 0) {
//                    echo "[DBG_CBP MB(1,0)] i8x8=$i8x8 (8x8块), bit=" . (1 << $i8x8) . ", cbp_bit_set=0, all 4x4 blocks skipped\n";
//                }
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
            // 注意：不初始化nzCache[16-23]为DC计数，tinyh264的totalCoeff[16-23]只存AC计数
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
                // AC-only count（参考tinyh264：totalCoeff只存AC计数）
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
                // AC-only count（参考tinyh264：totalCoeff只存AC计数）
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
        // 参考Rust mb.rs decode_chroma:
        //   - AC存在 (chromaCbp>=2): DC放入coeffs[0], 一次IDCT (单一+32偏置, >>6)
        //   - DC-only (chromaCbp==1): dc_add = (dc + 32) >> 6, 加到所有像素
        $uPixels = array_fill(0, 8, array_fill(0, 8, 0));
        $vPixels = array_fill(0, 8, array_fill(0, 8, 0));

        // 色度DC: 逆Hadamard + 反量化
        // 参考Rust: qmul = dequant4Table[list_idx=1+plane_idx][chromaQp][0]
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

        // 保存非零系数数供相邻宏块使用（参考wedeo Rust update_after_mb）
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
        //echo "[DECODER] MB($mbX,$mbY): === Intra16x16 解码 ===\n";

        // 参考 C语言编码器 cavlc_mb_header_i 的公式：
        // mb_type = 1 + pred_mode + cbp_chroma * 4 + (cbp_luma == 0 ? 0 : 12)
        // pred_mode: 0=垂直, 1=水平, 2=DC, 3=平面
        // cbp_chroma: 0=none, 1=DC only, 2=DC+AC
        // cbp_luma: 0=no coeff, 15=has AC

        $predMode = ($mbType - 1) % 4;
        $hasLumaAc = ($mbType - 1) >= 12;
        $base = $hasLumaAc ? 12 : 0;
        $cbpChroma = intdiv(($mbType - 1 - $base), 4);
        $cbpLuma = $hasLumaAc ? 15 : 0;

        //echo "[DECODER] MB($mbX,$mbY): mbType={$mbType}, predMode={$predMode}, cbpLuma={$cbpLuma}, cbpChroma={$cbpChroma}\n";

        // I_16x16宏块总是编码chroma_pred_mode（参考C语言编码器cavlc_mb_header_i）
        // chroma = CHROMA_FORMAT == CHROMA_420 || CHROMA_FORMAT == CHROMA_422，对于YUV420总是true
        // 编码顺序: mb_type -> chroma_pred_mode -> mb_qp_delta -> 亮度DC系数
        //$bitBeforeChroma = $this->reader->getBitPosition();
        $chromaPredMode = $this->reader->readUe();
        //$bitAfterChroma = $this->reader->getBitPosition();
        if ($chromaPredMode > 3) {
            //echo "[DECODER] MB($mbX,$mbY): 错误 - chromaPredMode=$chromaPredMode 超出范围 (0-3) bitPos=$bitBeforeChroma consumed=" . ($bitAfterChroma - $bitBeforeChroma) . "\n";
            // 打印接下来的16个比特用于调试
            //$peek16 = $this->reader->peek(16);
            //echo "[DECODER] MB($mbX,$mbY): next16bits=" . sprintf('%016b', $peek16) . " bitPos=" . $this->reader->getBitPosition() . "\n";
            $chromaPredMode = 0; // 回退到DC模式
        }
        //echo "[DECODER] MB($mbX,$mbY): 读取 chromaPredMode = $chromaPredMode\n";

        // mb_qp_delta - I_16x16无论cbp如何都要读取（H.264标准 7.4.5.2）
        $mbQpDelta = $this->reader->readSe();
        $qp = max(0, min(51, $qp + $mbQpDelta));
        //echo "[DECODER] MB($mbX,$mbY): 读取 mbQpDelta = $mbQpDelta, qp = $qp\n";

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

        // Intra16x16总是解码亮度DC（参考tinyh264 h264bsd_macroblock_layer.c 第721-729行）
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
        //$dbgI16 = ($mbX === 3 && $mbY === 0);
        //if ($dbgI16) {
            //echo "[DBG_I16 MB(3,0)] leftNz=" . implode(',', array_slice($leftNz, 0, 4)) . " leftAvail=" . ($leftAvailable ? 1 : 0) . " topAvail=" . ($topAvailable ? 1 : 0) . "\n";
            //echo "[DBG_I16 MB(3,0)] yDcNc=$yDcNc bitPosBefore=" . $this->reader->getBitPosition() . "\n";
        //}
        if ($mbX === 0 && $mbY === 0) $this->debugResidual = true;
        if ($mbX === 3 && $mbY === 0) $this->debugResidual = true;
        //$bitBeforeDc = $this->reader->getBitPosition();
        $yDcZigzag = $this->decodeResidualBlock(16, $yDcNc);
        //$bitAfterDc = $this->reader->getBitPosition();
        $this->debugResidual = false;
        
        //$dcNzCount = 0;
        //for ($i = 0; $i < 16; $i++) {
            //if ($yDcZigzag[$i] != 0) $dcNzCount++;
        //}
        
//        if ($dbgI16) {
//            echo "[DBG_I16 MB(3,0)] yDcZigzag=" . implode(',', $yDcZigzag) . " nzCount=$dcNzCount\n";
//            echo "[DBG_I16 MB(3,0)] bitPosAfter=$bitAfterDc dcConsumed=" . ($bitAfterDc - $bitBeforeDc) . "\n";
//        }
        // decodeResidualBlock返回zig-zag顺序，需先转为raster顺序
        // raster顺序在宏块DC语境下对应4x4矩阵: row=block_row, col=block_col
        // 这是Hadamard变换所需的输入顺序
        $yDcRaster = array_fill(0, 16, 0);
        for ($i = 0; $i < 16; $i++) {
            $yDcRaster[self::ZIGZAG_SCAN_4X4[$i]] = $yDcZigzag[$i];
        }
        $qpClamped = max(0, min(51, $qp));

        // 亮度DC: 逆Hadamard + 反量化
        // 参考Rust mb.rs: qmul = dequant4Table[list_idx=0][qp][0]
        // 输入为raster顺序的原始DC系数（不预反量化），输出为raster顺序的反量化值
        $lumaQmul = $this->dequant4Table[0][$qpClamped][0];
        $yDcResultBlockOrder = $this->lumaDcDequantIdct($yDcRaster, $lumaQmul);

        if ($mbX === 0 && $mbY === 0) {
            //echo "[DEBUG MB(0,0)] DC raster: " . implode(',', $yDcRaster) . "\n";
            //echo "[DEBUG MB(0,0)] qp=$qp lumaQmul=$lumaQmul\n";
            //echo "[DEBUG MB(0,0)] DC Hadamard (raster order): " . implode(',', $yDcResultBlockOrder) . "\n";
            $this->debugLastQp = $qp;
            $this->debugLastDcScan = $yDcRaster;
            $this->debugLastDcRaster = $yDcRaster;
            $this->debugLastQmul = $lumaQmul;
            $this->debugLastDcResult = $yDcResultBlockOrder;
        }
//        if ($mbX === 1 && $mbY === 0) {
//            echo "[DEBUG MB(1,0)] DC raster: " . implode(',', $yDcRaster) . "\n";
//            echo "[DEBUG MB(1,0)] qp=$qp lumaQmul=$lumaQmul\n";
//            echo "[DEBUG MB(1,0)] DC Hadamard (raster order): " . implode(',', $yDcResultBlockOrder) . "\n";
//        }

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
                // AC-only count（参考tinyh264：totalCoeff[blockIndex]只存AC计数，不包括DC）
                $nzCount = 0;
                for ($i = 0; $i < 15; $i++) if ($ac[$i] != 0) $nzCount++;
                $nzCache[$rasterIdx] = $nzCount;
//                if ($mbX === 0 && $mbY === 0 && $blkIdx === 0) {
//                    echo "[DEBUG MB(0,0) Blk0] nc=$nc AC scan: " . implode(',', $ac) . "\n";
//                    echo "[DEBUG MB(0,0) Blk0] AC raster+dequant: " . implode(',', $yAcCoeffs[$rasterIdx]) . "\n";
//                }
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
        // 参考Rust mb.rs decode_chroma: qmul = dequant4Table[list_idx=1+plane_idx][chromaQp][0]
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
            // Cb块空间布局（根据tinyh264 N_A_4x4B/N_B_4x4B表）：
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
        // 参考Rust mb.rs decode_intra16x16:
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
        // 参考Rust mb.rs decode_chroma:
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

        // 保存非零系数数供相邻宏块使用（参考wedeo Rust update_after_mb）
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
        // 参考wedeo mb.rs第1230-1249行和FFmpeg fill_decode_caches(h264_mvpred.h第645-648行)
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
        // 非I帧宏块传递DC_PRED(2)给相邻宏块（参考wedeo mb.rs第1248行）
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
        $mbWidth = $this->picWidthInMbs;

        if (($this->debugSliceIndex === 3 && $mbX === 15 && $mbY === 13) ||
            ($this->debugSliceIndex === 3 && $mbX === 16 && $mbY === 13) ||
            ($this->debugSliceIndex === 2 && $mbX === 8 && $mbY === 10)) {
            echo "[DEBUG_MB] MB($mbX,$mbY): mb_type=$mbType\n";
        }

        // P_L0_16x16 (mb_type 0)
        if ($mbType === 0) {
            //echo "[DECODER] MB($mbX,$mbY): P_L0_16x16\n";
            return $this->decodePL0_16x16($mbX, $mbY, $sliceQp);
        }

        // P_L0_L0_16x8 (mb_type 1)
        if ($mbType === 1) {
            //echo "[DECODER] MB($mbX,$mbY): P_L0_L0_16x8\n";
            return $this->decodePL0_16x8($mbX, $mbY, $sliceQp);
        }

        // P_L0_L0_8x16 (mb_type 2)
        if ($mbType === 2) {
            //echo "[DECODER] MB($mbX,$mbY): P_L0_L0_8x16\n";
            return $this->decodePL0_8x16($mbX, $mbY, $sliceQp);
        }

        // P_8x8 (mb_type 3)
        if ($mbType === 3) {
            //echo "[DECODER] MB($mbX,$mbY): P_8x8\n";
            return $this->decodeP_8x8($mbX, $mbY, $sliceQp);
        }

        // P_8x8ref0 (mb_type 4)
        if ($mbType === 4) {
            //echo "[DECODER] MB($mbX,$mbY): P_8x8ref0\n";
            return $this->decodeP_8x8ref0($mbX, $mbY, $sliceQp);
        }

        // Intra 模式 (mb_type >= 5, 即 mb_type - 5 对应 I_4x4..I_PCM)
        if ($mbType >= 5 && $mbType <= 30) {
            $intraMbType = $mbType - 5;
            //echo "[DECODER] MB($mbX,$mbY): P帧Intra宏块, intra_mb_type=$intraMbType\n";
            $mbQpDelta = $this->decodeIntraMacroblock($mbX, $mbY, $intraMbType, $sliceQp);
            $this->mvLeftCol = [null, null, null, null];
            $this->mvTopRow[$mbX * 4 + 0] = null;
            $this->mvTopRow[$mbX * 4 + 1] = null;
            $this->mvTopRow[$mbX * 4 + 2] = null;
            $this->mvTopRow[$mbX * 4 + 3] = null;
            return $mbQpDelta;
        }

        //echo "[DECODER] MB($mbX,$mbY): 未知P帧mb_type={$mbType}，填充灰色\n";
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

        // Debug: Frame 1 MB(8,10) and Frame 2 MB(8,10)
        if (($this->debugSliceIndex === 2 && $mbX === 8 && $mbY === 10) ||
            ($this->debugSliceIndex === 3 && $mbX === 8 && $mbY === 10)) {
            echo "[DEBUG_MB] MB($mbX,$mbY) P_Skip: pred_mv=($predMvX,$predMvY) mv=($mvX,$mvY) refIdx=$refIdx\n";
        }

        $isDebugSlice = ($this->debugTargetSlice > 0 && $this->debugSliceIndex === $this->debugTargetSlice);
        if ($isDebugSlice && $this->debugMbTraceFh) {
            fwrite($this->debugMbTraceFh, " [P_Skip] mv=($mvX,$mvY) refIdx=$refIdx");
        }

        $this->performMotionCompensation16x16($mbX, $mbY, $mvX, $mvY, $refIdx);

        $this->saveMvForPrediction($mbX, $mbY, $mvX, $mvY, $refIdx);

        $this->updateNzCountZero($mbX, $mbY);

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
     * P_L0_16x16 宏块解码
     */
    private function decodePL0_16x16(int $mbX, int $mbY, int $sliceQp): int
    {
        $isDebugSlice = ($this->debugTargetSlice > 0 && $this->debugSliceIndex === $this->debugTargetSlice);
        $isDebugMb = $isDebugSlice && $mbY === 0 && $mbX <= 5;
        if ($isDebugMb && $this->debugMbTraceFh) {
            fwrite($this->debugMbTraceFh, "\n  [DBG] bitPos at func start: " . $this->reader->getBitPosition());
        }
        $refIdx = 0;
        if ($this->numRefIdxL0Active > 1) {
            $refIdx = $this->reader->readUe();
        }
        if ($isDebugMb && $this->debugMbTraceFh) {
            fwrite($this->debugMbTraceFh, "\n  [DBG] bitPos after ref_idx: " . $this->reader->getBitPosition() . " refIdx=$refIdx");
        }
        $mvdL0X = $this->reader->readSe();
        if ($isDebugMb && $this->debugMbTraceFh) {
            fwrite($this->debugMbTraceFh, "\n  [DBG] bitPos after mvd_x: " . $this->reader->getBitPosition() . " mvdL0X=$mvdL0X");
        }
        $mvdL0Y = $this->reader->readSe();
        if ($isDebugMb && $this->debugMbTraceFh) {
            fwrite($this->debugMbTraceFh, "\n  [DBG] bitPos after mvd_y: " . $this->reader->getBitPosition() . " mvdL0Y=$mvdL0Y");
        }

        list($predMvX, $predMvY) = $this->getP16x16MvPrediction($mbX, $mbY, $refIdx);
        $mvX = $predMvX + $mvdL0X;
        $mvY = $predMvY + $mvdL0Y;

        if (($this->debugSliceIndex === 3 && $mbX === 16 && $mbY === 13) ||
            ($this->debugSliceIndex === 2 && $mbX === 8 && $mbY === 10)) {
            echo "[DEBUG_MB] MB($mbX,$mbY) P_16x16:\n";
            echo "  pred_mv=($predMvX,$predMvY), mvd=($mvdL0X,$mvdL0Y), mv=($mvX,$mvY), ref=$refIdx\n";
        }

        // Debug: Frame 2 first few MBs
        //if ($this->debugSliceIndex === 3 && $mbY === 0 && $mbX <= 5) {
            //echo "[MV_DEBUG] MB($mbX,$mbY) P_16x16: mvd=($mvdL0X,$mvdL0Y) pred_mv=($predMvX,$predMvY) mv=($mvX,$mvY) refIdx=$refIdx\n";
            //echo "[MV_DEBUG]   bitBeforeMvdX=$bitBeforeMvdX bitBeforeMvdY=$bitBeforeMvdY bitAfterMvd=$bitAfterMvd\n";
            //echo "[MV_DEBUG]   mvdX_bits=" . ($bitBeforeMvdY - $bitBeforeMvdX) . " mvdY_bits=" . ($bitAfterMvd - $bitBeforeMvdY) . "\n";
            //echo "[MV_DEBUG]   mvLeftCol[0]=" . ($this->mvLeftCol[0] ? "({$this->mvLeftCol[0][0]},{$this->mvLeftCol[0][1]},{$this->mvLeftCol[0][2]})" : "null") . "\n";
            //$topMv = $this->mvTopRow[$mbX * 4] ?? null;
            //echo "[MV_DEBUG]   mvTopRow[mbX*4]=" . ($topMv ? "({$topMv[0]},{$topMv[1]},{$topMv[2]})" : "null") . "\n";
            //$topRightMv = $this->mvTopRow[($mbX + 1) * 4] ?? null;
            //echo "[MV_DEBUG]   mvTopRow[(mbX+1)*4]=" . ($topRightMv ? "({$topRightMv[0]},{$topRightMv[1]},{$topRightMv[2]})" : "null") . "\n";
        //}

        //echo "[DECODER] MB($mbX,$mbY): refIdx=$refIdx mvd=($mvdL0X,$mvdL0Y) pred_mv=($predMvX,$predMvY) mv=($mvX,$mvY)\n";

        if ($isDebugSlice && $this->debugMbTraceFh) {
            fwrite($this->debugMbTraceFh, " [P_L0_16x16] refIdx=$refIdx mvd=($mvdL0X,$mvdL0Y) pred_mv=($predMvX,$predMvY) mv=($mvX,$mvY)");
        }

        //todo 为什么要强制使用 MV=(-2,0)
        if ($mbX === 2 && $mbY === 0) {
            //echo "[DBG_MB20] bitBeforeMvdX=$bitBeforeMvdX bitBeforeMvdY=$bitBeforeMvdY bitAfterMvd=$bitAfterMvd\n";
            //echo "[DBG_MB20] mvdX_bits=" . ($bitBeforeMvdY - $bitBeforeMvdX) . " mvdY_bits=" . ($bitAfterMvd - $bitBeforeMvdY) . "\n";
            //echo "[DBG_MB20] mvd=($mvdL0X,$mvdL0Y) pred_mv=($predMvX,$predMvY) mv=($mvX,$mvY)\n";
            // 临时测试：强制使用 MV=(-2,0)
            if (property_exists($this, 'forceMvMb20') && $this->forceMvMb20) {
                $mvX = -2;
                $mvY = 0;
                //echo "[DBG_MB20] 强制 MV=(-2,0)\n";
            }
        }
        //if ($mbX === 1 && $mbY === 0) {
            //echo "[DBG_MB10] bitBeforeMvdX=$bitBeforeMvdX bitBeforeMvdY=$bitBeforeMvdY bitAfterMvd=$bitAfterMvd\n";
            //echo "[DBG_MB10] mvdX_bits=" . ($bitBeforeMvdY - $bitBeforeMvdX) . " mvdY_bits=" . ($bitAfterMvd - $bitBeforeMvdY) . "\n";
            //echo "[DBG_MB10] mvd=($mvdL0X,$mvdL0Y) pred_mv=($predMvX,$predMvY) mv=($mvX,$mvY)\n";
        //}

        $this->performMotionCompensation16x16($mbX, $mbY, $mvX, $mvY, $refIdx);

        if ($isDebugMb && $this->debugMbTraceFh) {
            fwrite($this->debugMbTraceFh, "\n  [MC] Motion compensated prediction (Y, first 4 rows):");
            for ($yy = 0; $yy < 4; $yy++) {
                $line = "\n    row $yy: ";
                $py = $mbY * 16 + $yy;
                $baseIdx = $py * $this->width + $mbX * 16;
                for ($xx = 0; $xx < 16; $xx++) {
                    $line .= $this->yPlane[$baseIdx + $xx] . " ";
                }
                fwrite($this->debugMbTraceFh, $line);
            }
        }

        $cbpCode = $this->reader->readUe();
        $codedBlockPattern = self::GOLOMB_TO_INTER_CBP[$cbpCode] ?? 0;
        if (($this->debugSliceIndex === 3 && $mbX === 16 && $mbY === 13) ||
            ($this->debugSliceIndex === 2 && $mbX === 8 && $mbY === 10)) {
            echo "[DEBUG_MB] MB($mbX,$mbY) cbp_code=$cbpCode, cbp=0x" . dechex($codedBlockPattern) . "\n";
        }
        if ($isDebugMb && $this->debugMbTraceFh) {
            fwrite($this->debugMbTraceFh, "\n  [DBG] bitPos after cbp: " . $this->reader->getBitPosition() . " cbpCode=$cbpCode cbp=$codedBlockPattern");
        }
        $mbQpDelta = 0;
        if ($codedBlockPattern !== 0) {
            $mbQpDelta = $this->reader->readSe();
            $qp = $sliceQp + $mbQpDelta;
            $qp = max(0, min(51, $qp));
            if ($isDebugMb && $this->debugMbTraceFh) {
                fwrite($this->debugMbTraceFh, "\n  [DBG] bitPos after mb_qp_delta: " . $this->reader->getBitPosition() . " mbQpDelta=$mbQpDelta qp=$qp");
            }
            $this->decodeResidualAndAdd($mbX, $mbY, $codedBlockPattern, $qp, 0);
            if ($isDebugMb && $this->debugMbTraceFh) {
                fwrite($this->debugMbTraceFh, "\n  [DBG] bitPos after residual: " . $this->reader->getBitPosition());
                fwrite($this->debugMbTraceFh, "\n  [OUT] Decoded pixels after residual (Y, first 4 rows):");
                for ($yy = 0; $yy < 4; $yy++) {
                    $line = "\n    row $yy: ";
                    $py = $mbY * 16 + $yy;
                    $baseIdx = $py * $this->width + $mbX * 16;
                    for ($xx = 0; $xx < 16; $xx++) {
                        $line .= $this->yPlane[$baseIdx + $xx] . " ";
                    }
                    fwrite($this->debugMbTraceFh, $line);
                }
            }
        }

        if ($isDebugSlice && $this->debugMbTraceFh) {
            fwrite($this->debugMbTraceFh, " cbp=$codedBlockPattern");
        }

        if ($codedBlockPattern === 0) {
            $this->updateNzCountZero($mbX, $mbY);
        }

        $this->saveMvForPrediction($mbX, $mbY, $mvX, $mvY, $refIdx);

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

        if ($this->debugSliceIndex === 3 && $mbX === 15 && $mbY === 13) {
            echo "[DEBUG_MB] MB(15,13) P_16x8:\n";
            echo "  part0 (top): pred_mv=($predMv0X,$predMv0Y), mvd=($mvd0X,$mvd0Y), mv=($mv0X,$mv0Y), ref=$refIdx0\n";
            echo "  part1 (bottom): pred_mv=($predMv1X,$predMv1Y), mvd=($mvd1X,$mvd1Y), mv=($mv1X,$mv1Y), ref=$refIdx1\n";
            $lumaRefX1 = $mbX * 64 + $mv1X;
            $lumaRefY1 = $mbY * 64 + $mv1Y + 8 * 4;
            echo "  part1 lumaRefX=$lumaRefX1, lumaRefY=$lumaRefY1, intX=" . ($lumaRefX1 >> 2) . ", fracX=" . ($lumaRefX1 & 3) . "\n";
            // 输出参考帧中的整像素值
            $intX = $lumaRefX1 >> 2;
            $intY = $lumaRefY1 >> 2;
            echo "  参考帧整像素值 (x=$intX-2 到 x=$intX+5, y=$intY 到 y=$intY+2):\n";
            echo "  [DEBUG] before loop, refFrameY is array: " . is_array($this->refFrameY) . ", count=" . count($this->refFrameY) . "\n";
            for ($dy = 0; $dy < 3; $dy++) {
                $refVals = [];
                for ($x = -2; $x <= 5; $x++) {
                    $rx = max(0, min($this->refWidthY - 1, $intX + $x));
                    $ry = max(0, min($this->refHeightY - 1, $intY + $dy));
                    $idx = $ry * $this->refStrideY + $rx;
                    $v = isset($this->refFrameY[$idx]) ? $this->refFrameY[$idx] : -1;
                    $refVals[] = sprintf("%3d", $v);
                }
                echo "    y=" . ($intY + $dy) . ": " . implode(" ", $refVals) . "\n";
            }
            echo "  [DEBUG] after loop\n";
            echo "  预测值（运动补偿后，残差前）下半部分第0-3列:\n";
            for ($y = 8; $y < 11; $y++) {
                $line = "    y=$y: ";
                for ($x = 0; $x < 4; $x++) {
                    $px = $mbX * 16 + $x;
                    $py = $mbY * 16 + $y;
                    $idx = $py * $this->width + $px;
                    $v = $this->yPlane[$idx];
                    $line .= sprintf("%3d ", $v);
                }
                echo $line . "\n";
            }
        }

        $cbpCode = $this->reader->readUe();
        $codedBlockPattern = self::GOLOMB_TO_INTER_CBP[$cbpCode] ?? 0;

        if ($this->debugSliceIndex === 3 && $mbX === 15 && $mbY === 13) {
            echo "[DEBUG_MB] MB(15,13) cbp_code=$cbpCode, cbp=" . sprintf("0x%03x", $codedBlockPattern) . "\n";
        }

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

        // Debug: Frame 2 first few MBs
        //if ($this->debugSliceIndex === 3 && $mbY === 0 && $mbX <= 5) {
            //echo "[MV_DEBUG] MB($mbX,$mbY) P_8x16: part0 mv=($mv0X,$mv0Y) ref=$refIdx0, part1 mv=($mv1X,$mv1Y) ref=$refIdx1\n";
            //echo "[MV_DEBUG]   pred0=($predMv0X,$predMv0Y) mvd0=($mvd0X,$mvd0Y)\n";
            //echo "[MV_DEBUG]   pred1=($predMv1X,$predMv1Y) mvd1=($mvd1X,$mvd1Y)\n";
        //}

        $this->performMotionCompensation8x16($mbX, $mbY, 0, $mv0X, $mv0Y, $refIdx0);
        $this->performMotionCompensation8x16($mbX, $mbY, 1, $mv1X, $mv1Y, $refIdx1);

        if ($this->debugSliceIndex === 3 && $mbX === 14 && $mbY === 13) {
            echo "[DEBUG_MB] MB(14,13) P_8x16:\n";
            echo "  part0 (left): pred_mv=($predMv0X,$predMv0Y), mvd=($mvd0X,$mvd0Y), mv=($mv0X,$mv0Y), ref=$refIdx0\n";
            echo "  part1 (right): pred_mv=($predMv1X,$predMv1Y), mvd=($mvd1X,$mvd1Y), mv=($mv1X,$mv1Y), ref=$refIdx1\n";
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

        $this->saveMvForPrediction8x16($mbX, $mbY, $mv0X, $mv0Y, $refIdx0, $mv1X, $mv1Y, $refIdx1);

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
     * 参考 FFmpeg h264_cavlc.c 和 wedeo cavlc.rs
     */
    private function decodeP_8x8(int $mbX, int $mbY, int $sliceQp): int
    {
        //echo "[DECODER] MB($mbX,$mbY): P_8x8\n";

        $mbWidth = $this->picWidthInMbs;

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
            //$blkX = $subMbScan[$i][0];
            //$blkY = $subMbScan[$i][1];
            //echo "[DECODER] MB($mbX,$mbY) sub($blkX,$blkY): sub_mb_type={$subMbTypes[$i]}\n";
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
                //echo "[DECODER] MB($mbX,$mbY): 未知sub_mb_type=$subMbType\n";
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

        return $mbQpDelta;
    }

    /**
     * P_8x8ref0 宏块解码（ref_idx固定为0）
     * 码流顺序: 先所有4个sub_mb_type, 再所有sub-partition的mvd (ref_idx固定为0)
     * 4x4子块粒度，每个子划分独立预测MV
     * 参考 FFmpeg h264_cavlc.c 和 wedeo cavlc.rs
     */
    private function decodeP_8x8ref0(int $mbX, int $mbY, int $sliceQp): int
    {
        //echo "[DECODER] MB($mbX,$mbY): P_8x8ref0\n";

        $mbWidth = $this->picWidthInMbs;
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
            //$blkX = $subMbScan[$i][0];
            //$blkY = $subMbScan[$i][1];
            //echo "[DECODER] MB($mbX,$mbY) sub($blkX,$blkY): sub_mb_type={$subMbTypes[$i]} (ref0)\n";
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
                //echo "[DECODER] MB($mbX,$mbY): 未知sub_mb_type=$subMbType\n";
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
     * 参考wedeo MvContext::neighbor_c_16x16 和 H.264 8.4.1.2.1节
     */
    private function getP16x16MvPrediction(int $mbX, int $mbY, int $refIdx): array
    {
        $mbWidth = $this->picWidthInMbs;

        $mvLeft = null;
        $mvTop = null;
        $mvC = null;

        if ($mbX > 0) {
            if (isset($this->mvLeftCol[0])) {
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

        if ($this->debugSliceIndex === 3 && $mbX === 16 && $mbY === 13) {
            echo "[MVP_16x16] MB(16,13):\n";
            echo "  mvLeft=" . ($mvLeft ? "({$mvLeft[0]},{$mvLeft[1]},{$mvLeft[2]})" : "null") . "\n";
            echo "  mvTop=" . ($mvTop ? "({$mvTop[0]},{$mvTop[1]},{$mvTop[2]})" : "null") . "\n";
            echo "  mvC=" . ($mvC ? "({$mvC[0]},{$mvC[1]},{$mvC[2]})" : "null") . "\n";
        }

        // 调试：Frame 2 第一行前6个MB
//        if ($this->debugSliceIndex === 3 && $mbY === 0 && $mbX <= 5) {
//            echo "[PRED16x16] MB($mbX,$mbY): mvLeft=" . ($mvLeft ? "({$mvLeft[0]},{$mvLeft[1]},{$mvLeft[2]})" : "null") .
//                 " mvTop=" . ($mvTop ? "({$mvTop[0]},{$mvTop[1]},{$mvTop[2]})" : "null") .
//                 " mvC=" . ($mvC ? "({$mvC[0]},{$mvC[1]},{$mvC[2]})" : "null") .
//                 " refIdx=$refIdx\n";
//            echo "[PRED16x16]   mvLeftCol[0]=" . ($this->mvLeftCol[0] ? "({$this->mvLeftCol[0][0]},{$this->mvLeftCol[0][1]},{$this->mvLeftCol[0][2]})" : "null") . "\n";
//        }

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
            if (isset($this->mvLeftCol[0])) {
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
            if ($mbX + 1 < $mbWidth && $mbY > 0) {
                $mvTopRight = $this->mvTopRow[($mbX + 1) * 4] ?? null;
            }
        } else {
            $mvTopRight = $mvPart0;
        }

        if (($this->debugSliceIndex === 3 && $mbX === 15 && $mbY === 13) ||
            ($this->debugSliceIndex === 3 && $mbX === 14 && $mbY === 13)) {
            echo "[MVP_16x8] MB($mbX,$mbY) part=$partIdx:\n";
            echo "  mvLeft=" . ($mvLeft ? "({$mvLeft[0]},{$mvLeft[1]},{$mvLeft[2]})" : "null") . "\n";
            echo "  mvTop=" . ($mvTop ? "({$mvTop[0]},{$mvTop[1]},{$mvTop[2]})" : "null") . "\n";
            echo "  mvTopRight=" . ($mvTopRight ? "({$mvTopRight[0]},{$mvTopRight[1]},{$mvTopRight[2]})" : "null") . "\n";
        }

        $primaryMv = null;
        $otherMv = null;

        if ($partIdx === 0) {
            if ($mvTop !== null && $mvTop[2] === $refIdx) {
                $primaryMv = $mvTop;
            }
            if ($mvLeft !== null && $mvLeft[2] === $refIdx) {
                $otherMv = $mvLeft;
            }
        } else {
            if ($mvLeft !== null && $mvLeft[2] === $refIdx) {
                $primaryMv = $mvLeft;
            }
            if ($mvTop !== null && $mvTop[2] === $refIdx) {
                $otherMv = $mvTop;
            }
        }

        if ($primaryMv !== null) {
            if ($otherMv !== null) {
                $dx = abs($primaryMv[0] - $otherMv[0]);
                $dy = abs($primaryMv[1] - $otherMv[1]);
                $threshold = 11;
                if ($dx < $threshold && $dy < $threshold) {
                    return [$primaryMv[0], $primaryMv[1]];
                }
            } else {
                return [$primaryMv[0], $primaryMv[1]];
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
                $mvLeft = $this->mvLeftCol[0] ?? null;
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
     * 参考 wedeo cavlc.rs decode_residual_blocks (非Intra16x16路径)
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
                    $isDebugSlice = ($this->debugTargetSlice > 0 && $this->debugSliceIndex === $this->debugTargetSlice);
                    $isDebugMb = $isDebugSlice && $mbY === 0 && ($mbX === 1 || $mbX === 2);
                    $bpBefore = $this->reader->getBitPosition();
                    $coeffs = $this->decodeResidualBlock(16, $nc);
                    $bpAfter = $this->reader->getBitPosition();
                    if ($isDebugMb && $this->debugMbTraceFh) {
                        $nz = 0;
                        for ($i = 0; $i < 16; $i++) if ($coeffs[$i] != 0) $nz++;
                        fwrite($this->debugMbTraceFh, "\n    [RES] luma blk scan=$scanIdx raster=$rasterIdx nc=$nc nz=$nz bits=" . ($bpAfter - $bpBefore) . " (from $bpBefore to $bpAfter)");
                    }
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
            if ($isDebugMb && $this->debugMbTraceFh) {
                fwrite($this->debugMbTraceFh, "\n    [RES] chroma DC start, bitPos=" . $this->reader->getBitPosition() . " chromaCbp=$chromaCbp");
            }
            $bpBefore = $this->reader->getBitPosition();
            $cbDc = $this->decodeResidualBlock(4, -1);
            $bpAfter = $this->reader->getBitPosition();
            if ($isDebugMb && $this->debugMbTraceFh) {
                $nz = 0; for ($i = 0; $i < 4; $i++) if ($cbDc[$i] != 0) $nz++;
                fwrite($this->debugMbTraceFh, "\n    [RES] chroma Cb DC: nc=-1 nz=$nz bits=" . ($bpAfter - $bpBefore) . " (from $bpBefore to $bpAfter) coeffs=[" . implode(",", $cbDc) . "]");
            }
            $bpBefore = $this->reader->getBitPosition();
            $crDc = $this->decodeResidualBlock(4, -1);
            $bpAfter = $this->reader->getBitPosition();
            if ($isDebugMb && $this->debugMbTraceFh) {
                $nz = 0; for ($i = 0; $i < 4; $i++) if ($crDc[$i] != 0) $nz++;
                fwrite($this->debugMbTraceFh, "\n    [RES] chroma Cr DC: nc=-1 nz=$nz bits=" . ($bpAfter - $bpBefore) . " (from $bpBefore to $bpAfter) coeffs=[" . implode(",", $crDc) . "]");
            }
        }

        $cbQmul = $this->dequant4Table[1][$chromaQp][0];
        $crQmul = $this->dequant4Table[2][$chromaQp][0];
        $cbDcResult = $this->chromaDcDequantIdct($cbDc, $cbQmul);
        $crDcResult = $this->chromaDcDequantIdct($crDc, $crQmul);

        if ($chromaCbp === 2 || $chromaCbp === 3) {
            $cbScanOrder = [16, 17, 18, 19];
            foreach ($cbScanOrder as $blockIdx) {
                $blk = $blockIdx - 16;
                $nc = $this->computeNc($blockIdx, $mbX, $mbY, $nzCache, $leftNz, $topNz, $leftAvailable, $topAvailable);
                $bpBefore = $this->reader->getBitPosition();
                $ac = $this->decodeResidualBlock(15, $nc);
                $bpAfter = $this->reader->getBitPosition();
                if ($isDebugMb && $this->debugMbTraceFh) {
                    $nzCnt = 0; for ($i = 0; $i < 15; $i++) if ($ac[$i] != 0) $nzCnt++;
                    fwrite($this->debugMbTraceFh, "\n    [RES] chroma Cb AC blk=$blk blockIdx=$blockIdx nc=$nc nz=$nzCnt bits=" . ($bpAfter - $bpBefore) . " (from $bpBefore to $bpAfter)");
                }
                $nzCnt = 0; for ($i = 0; $i < 15; $i++) if ($ac[$i] != 0) $nzCnt++;
                for ($i = 1; $i < 16; $i++) $cbAcCoeffs[$blk][$i] = $ac[$i - 1];
                $cbAcCoeffs[$blk] = $this->zigzagToRaster($cbAcCoeffs[$blk]);
                $cbAcCoeffs[$blk] = $this->dequantize4x4($cbAcCoeffs[$blk], 1, $chromaQp);
                $nzCache[$blockIdx] = $nzCnt;
            }
            $crScanOrder = [20, 21, 22, 23];
            foreach ($crScanOrder as $blockIdx) {
                $blk = $blockIdx - 20;
                $nc = $this->computeNc($blockIdx, $mbX, $mbY, $nzCache, $leftNz, $topNz, $leftAvailable, $topAvailable);
                $bpBefore = $this->reader->getBitPosition();
                $ac = $this->decodeResidualBlock(15, $nc);
                $bpAfter = $this->reader->getBitPosition();
                if ($isDebugMb && $this->debugMbTraceFh) {
                    $nzCnt = 0; for ($i = 0; $i < 15; $i++) if ($ac[$i] != 0) $nzCnt++;
                    fwrite($this->debugMbTraceFh, "\n    [RES] chroma Cr AC blk=$blk blockIdx=$blockIdx nc=$nc nz=$nzCnt bits=" . ($bpAfter - $bpBefore) . " (from $bpBefore to $bpAfter)");
                }
                $nzCnt = 0; for ($i = 0; $i < 15; $i++) if ($ac[$i] != 0) $nzCnt++;
                for ($i = 1; $i < 16; $i++) $crAcCoeffs[$blk][$i] = $ac[$i - 1];
                $crAcCoeffs[$blk] = $this->zigzagToRaster($crAcCoeffs[$blk]);
                $crAcCoeffs[$blk] = $this->dequantize4x4($crAcCoeffs[$blk], 2, $chromaQp);
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
