<?php

namespace Xiaosongshu\Flv2mp4\Codec\Encode;

/**
 * @purpose 帧内预测
 * @author yanglong
 */
trait IntraPredTrait
{
    public function encodeMacroblock(
        $mbX, $mbY, $yPlane, $uPlane, $vPlane,
        $leftAvailable, &$leftNz, $topAvailable,
        &$topNzLuma, &$topNzCb, &$topNzCr,
        &$leftIntra4x4Mode = null, &$topIntra4x4Mode = null
    )
    {
        if (($this->profile === 'high') && $this->intra8x8Enabled) {
            // high profile：I 片恒用 I8x8（无宏块级选型，用户决策）
            return $this->encodeMacroblockI8x8($mbX, $mbY, $yPlane, $uPlane, $vPlane, $leftAvailable, $leftNz, $topAvailable, $topNzLuma, $topNzCb, $topNzCr);
        }
        if ($this->mbType === self::MB_TYPE_I16x16) {
            return $this->encodeMacroblockI16x16($mbX, $mbY, $yPlane, $uPlane, $vPlane, $leftAvailable, $leftNz, $topAvailable, $topNzLuma, $topNzCb, $topNzCr);
        } else {
            return  $this->encodeMacroblockI4x4($mbX, $mbY, $yPlane, $uPlane, $vPlane, $leftAvailable, $leftNz, $topAvailable, $topNzLuma, $topNzCb, $topNzCr, $leftIntra4x4Mode, $topIntra4x4Mode);
        }
    }

    public function encodeMacroblockI16x16(
        $mbX, $mbY, $yPlane, $uPlane, $vPlane,
        $leftAvailable, &$leftNz, $topAvailable,
        &$topNzLuma, &$topNzCb, &$topNzCr
    )
    {
        $bits = '';
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

        $leftPixels = array_fill(0, 16, 128);
        $topPixels = array_fill(0, 16, 128);
        $leftSum = 0;
        $topSum = 0;
        $cntL = 0;
        $cntT = 0;
        $reconStride = $this->mbAlignedWidth;
        if ($leftAvailable) {
            $refX = $mbX * 16 - 1;
            for ($y = 0; $y < 16; $y++) {
                $py = $mbY * 16 + $y;
                $idx = $py * $reconStride + $refX;
                $leftPixels[$y] = ord($this->reconYPlane[$idx]);
                $leftSum += $leftPixels[$y];
                $cntL++;
            }
        }
        if ($topAvailable) {
            $refY = $mbY * 16 - 1;
            for ($x = 0; $x < 16; $x++) {
                $px = $mbX * 16 + $x;
                $idx = $refY * $reconStride + $px;
                $topPixels[$x] = ord($this->reconYPlane[$idx]);
                $topSum += $topPixels[$x];
                $cntT++;
            }
        }

        if (!$topAvailable && !$leftAvailable) {
            $lumaPredMode = 2;
        } elseif (!$topAvailable) {
            $lumaPredMode = 1;
        } elseif (!$leftAvailable) {
            $lumaPredMode = 0;
        } else {
            $lumaPredMode = 2;
        }

        $predPixels = array_fill(0, 16, array_fill(0, 16, 128));
        for ($y = 0; $y < 16; $y++) {
            for ($x = 0; $x < 16; $x++) {
                switch ($lumaPredMode) {
                    case 0:
                        $predPixels[$y][$x] = $topPixels[$x];
                        break;
                    case 1:
                        $predPixels[$y][$x] = $leftPixels[$y];
                        break;
                    case 2:
                        if ($cntL && $cntT) $predPixels[$y][$x] = ($topSum + $leftSum + 16) >> 5;
                        elseif ($cntL) $predPixels[$y][$x] = ($leftSum + 8) >> 4;
                        elseif ($cntT) $predPixels[$y][$x] = ($topSum + 8) >> 4;
                        else $predPixels[$y][$x] = 128;
                        break;
                    case 3:
                        $a = $topPixels[$x];
                        $b = $leftPixels[$y];
                        $c = ($x > 0) ? $topPixels[$x - 1] : 128;
                        $d = ($y > 0) ? $leftPixels[$y - 1] : 128;
                        $predPixels[$y][$x] = (int)(($a + $b - $c - $d + $x * ($d - $c) + $y * ($c - $a) + 16) >> 5);
                        break;
                }
            }
        }

        $dc4x4Raw = array_fill(0, 4, array_fill(0, 4, 0));
        $quant4x4Luma = array_fill(0, 16, array_fill(0, 16, 0));
        $nzCache = array_fill(0, 24, 0);
        $cbpLuma = 0;
        for ($by = 0; $by < 4; $by++) {
            for ($bx = 0; $bx < 4; $bx++) {
                $blkIdx = $by * 4 + $bx;
                $blk4x4 = array_fill(0, 4, array_fill(0, 4, 0));
                for ($y = 0; $y < 4; $y++) for ($x = 0; $x < 4; $x++) {
                    $py = $by * 4 + $y;
                    $px = $bx * 4 + $x;
                    $pv = $lumaPixels[$py][$px];
                    $pred = $predPixels[$py][$px];
                    $blk4x4[$y][$x] = $pv - $pred;
                }
                $dctBlock = $this->dct($blk4x4);
                $dc4x4Raw[$by][$bx] = $dctBlock[0][0];
                $quantBlock = $this->quantize($dctBlock, 0);
                for ($yy = 0; $yy < 4; $yy++) for ($xx = 0; $xx < 4; $xx++) {
                    $quant4x4Luma[$blkIdx][$yy * 4 + $xx] = $quantBlock[$yy][$xx];
                }
                $nz = 0;
                for ($i = 1; $i < 16; $i++) {
                    if ($quant4x4Luma[$blkIdx][$i] !== 0) $nz++;
                }
                $nzCache[$blkIdx] = min(15, $nz);
                if ($nz > 0) {
                    $subY = intdiv($blkIdx, 4);
                    $subX = $blkIdx % 4;
                    $block8x8Idx = intdiv($subY, 2) * 2 + intdiv($subX, 2);
                    $cbpLuma |= 1 << $block8x8Idx;
                }
            }
        }

        $dcHadamard = $this->hadamardTransformDC($dc4x4Raw);
        $dcQuant = $this->quantizeDCMatrix($dcHadamard, $this->qp);
        $dcFlat = [];
        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                $dcFlat[] = $dcQuant[$y][$x];
            }
        }
        $dcZigzag = [];
        for ($i = 0; $i < 16; $i++) {
            $dcZigzag[$i] = $dcFlat[self::ZIGZAG_SCAN_4X4[$i]];
        }

        $hasLumaDc = false;
        for ($i = 0; $i < 16; $i++) {
            if ($dcZigzag[$i] !== 0) {
                $hasLumaDc = true;
                break;
            }
        }
        if ($hasLumaDc && $cbpLuma === 0) {
            $cbpLuma = 15;
        }
        if ($cbpLuma > 0 && $cbpLuma < 15) {
            $cbpLuma = 15;
        }

        $u8x8 = array_fill(0, 8, array_fill(0, 8, 128));
        $v8x8 = array_fill(0, 8, array_fill(0, 8, 128));
        $chromaQpIndex = max(0, min(51, $this->qp + $this->chromaQpIndexOffset));
        $chromaQp = self::CHROMA_QP_TABLE[$chromaQpIndex];
        $hasChromaAc = false;
        $quantCb4x4 = array_fill(0, 4, array_fill(0, 16, 0));
        $quantCr4x4 = array_fill(0, 4, array_fill(0, 16, 0));
        $dcCb2x2 = [0, 0, 0, 0];
        $dcCr2x2 = [0, 0, 0, 0];

        $chromaStride = (int)($this->mbAlignedWidth / 2);
        for ($y = 0; $y < 8; $y++) {
            $py = $mbY * 8 + $y;
            for ($x = 0; $x < 8; $x++) {
                $px = $mbX * 8 + $x;
                $idx = $py * $chromaStride + $px;
                $u8x8[$y][$x] = ord($uPlane[$idx]);
                $v8x8[$y][$x] = ord($vPlane[$idx]);
            }
        }

        $chromaLeftU = array_fill(0, 8, 128);
        $chromaTopU = array_fill(0, 8, 128);
        $chromaLeftV = array_fill(0, 8, 128);
        $chromaTopV = array_fill(0, 8, 128);

        $chromaCntL = 0;
        $chromaCntT = 0;
        $reconChromaStride = intdiv($this->mbAlignedWidth, 2);
        if ($leftAvailable) {
            $refX = $mbX * 8 - 1;
            for ($y = 0; $y < 8; $y++) {
                $py = $mbY * 8 + $y;
                $idx = $py * $reconChromaStride + $refX;
                $chromaLeftU[$y] = ord($this->reconUPlane[$idx]);
                $chromaLeftV[$y] = ord($this->reconVPlane[$idx]);
                $chromaCntL++;
            }
        }
        if ($topAvailable) {
            $refY = $mbY * 8 - 1;
            for ($x = 0; $x < 8; $x++) {
                $px = $mbX * 8 + $x;
                $idx = $refY * $reconChromaStride + $px;
                $chromaTopU[$x] = ord($this->reconUPlane[$idx]);
                $chromaTopV[$x] = ord($this->reconVPlane[$idx]);
                $chromaCntT++;
            }
        }

        $chromaPredMode = 0;

        $chromaPredU = array_fill(0, 8, array_fill(0, 8, 128));
        $chromaPredV = array_fill(0, 8, array_fill(0, 8, 128));

        $hasTop = $chromaCntT > 0;
        $hasLeft = $chromaCntL > 0;

        switch ($chromaPredMode) {
            case 0:
                if ($hasTop && $hasLeft) {
                    $dc0U = 0; $dc1U = 0; $dc2U = 0;
                    $dc0V = 0; $dc1V = 0; $dc2V = 0;
                    for ($i = 0; $i < 4; $i++) {
                        $dc0U += $chromaTopU[$i] + $chromaLeftU[$i];
                        $dc1U += $chromaTopU[4 + $i];
                        $dc2U += $chromaLeftU[4 + $i];
                        $dc0V += $chromaTopV[$i] + $chromaLeftV[$i];
                        $dc1V += $chromaTopV[4 + $i];
                        $dc2V += $chromaLeftV[4 + $i];
                    }
                    $dc0ValU = ($dc0U + 4) >> 3;
                    $dc1ValU = ($dc1U + 2) >> 2;
                    $dc2ValU = ($dc2U + 2) >> 2;
                    $dc3ValU = ($dc1U + $dc2U + 4) >> 3;
                    $dc0ValV = ($dc0V + 4) >> 3;
                    $dc1ValV = ($dc1V + 2) >> 2;
                    $dc2ValV = ($dc2V + 2) >> 2;
                    $dc3ValV = ($dc1V + $dc2V + 4) >> 3;
                } elseif (!$hasTop && $hasLeft) {
                    $dc0U = 0; $dc2U = 0;
                    $dc0V = 0; $dc2V = 0;
                    for ($i = 0; $i < 4; $i++) {
                        $dc0U += $chromaLeftU[$i];
                        $dc2U += $chromaLeftU[4 + $i];
                        $dc0V += $chromaLeftV[$i];
                        $dc2V += $chromaLeftV[4 + $i];
                    }
                    $dc0ValU = ($dc0U + 2) >> 2;
                    $dc1ValU = $dc0ValU;
                    $dc2ValU = ($dc2U + 2) >> 2;
                    $dc3ValU = $dc2ValU;
                    $dc0ValV = ($dc0V + 2) >> 2;
                    $dc1ValV = $dc0ValV;
                    $dc2ValV = ($dc2V + 2) >> 2;
                    $dc3ValV = $dc2ValV;
                } elseif ($hasTop && !$hasLeft) {
                    $dc0U = 0; $dc1U = 0;
                    $dc0V = 0; $dc1V = 0;
                    for ($i = 0; $i < 4; $i++) {
                        $dc0U += $chromaTopU[$i];
                        $dc1U += $chromaTopU[4 + $i];
                        $dc0V += $chromaTopV[$i];
                        $dc1V += $chromaTopV[4 + $i];
                    }
                    $dc0ValU = ($dc0U + 2) >> 2;
                    $dc1ValU = ($dc1U + 2) >> 2;
                    $dc2ValU = $dc0ValU;
                    $dc3ValU = $dc1ValU;
                    $dc0ValV = ($dc0V + 2) >> 2;
                    $dc1ValV = ($dc1V + 2) >> 2;
                    $dc2ValV = $dc0ValV;
                    $dc3ValV = $dc1ValV;
                } else {
                    $dc0ValU = $dc1ValU = $dc2ValU = $dc3ValU = 128;
                    $dc0ValV = $dc1ValV = $dc2ValV = $dc3ValV = 128;
                }
                for ($y = 0; $y < 4; $y++) {
                    for ($x = 0; $x < 4; $x++) {
                        $chromaPredU[$y][$x] = $dc0ValU;
                        $chromaPredV[$y][$x] = $dc0ValV;
                    }
                    for ($x = 4; $x < 8; $x++) {
                        $chromaPredU[$y][$x] = $dc1ValU;
                        $chromaPredV[$y][$x] = $dc1ValV;
                    }
                }
                for ($y = 4; $y < 8; $y++) {
                    for ($x = 0; $x < 4; $x++) {
                        $chromaPredU[$y][$x] = $dc2ValU;
                        $chromaPredV[$y][$x] = $dc2ValV;
                    }
                    for ($x = 4; $x < 8; $x++) {
                        $chromaPredU[$y][$x] = $dc3ValU;
                        $chromaPredV[$y][$x] = $dc3ValV;
                    }
                }
                break;
            case 1:
                for ($y = 0; $y < 8; $y++) {
                    for ($x = 0; $x < 8; $x++) {
                        $chromaPredU[$y][$x] = $chromaLeftU[$y];
                        $chromaPredV[$y][$x] = $chromaLeftV[$y];
                    }
                }
                break;
            case 2:
                for ($y = 0; $y < 8; $y++) {
                    for ($x = 0; $x < 8; $x++) {
                        $chromaPredU[$y][$x] = $chromaTopU[$x];
                        $chromaPredV[$y][$x] = $chromaTopV[$x];
                    }
                }
                break;
            default:
                for ($y = 0; $y < 8; $y++) {
                    for ($x = 0; $x < 8; $x++) {
                        $chromaPredU[$y][$x] = 128;
                        $chromaPredV[$y][$x] = 128;
                    }
                }
                break;
        }

        for ($by = 0; $by < 2; $by++) {
            for ($bx = 0; $bx < 2; $bx++) {
                $blkIdx = $by * 2 + $bx;
                $blkU = array_fill(0, 4, array_fill(0, 4, 0));
                $blkV = array_fill(0, 4, array_fill(0, 4, 0));
                for ($y = 0; $y < 4; $y++) for ($x = 0; $x < 4; $x++) {
                    $py = $by * 4 + $y;
                    $px = $bx * 4 + $x;
                    $blkU[$y][$x] = $u8x8[$py][$px] - $chromaPredU[$py][$px];
                    $blkV[$y][$x] = $v8x8[$py][$px] - $chromaPredV[$py][$px];
                }
                $dctU = $this->dct($blkU);
                $dctV = $this->dct($blkV);
                $dcCb2x2[$blkIdx] = $dctU[0][0];
                $dcCr2x2[$blkIdx] = $dctV[0][0];
                $qU = $this->quantizeChroma($dctU, $chromaQp);
                $qV = $this->quantizeChroma($dctV, $chromaQp);
                for ($yy = 0; $yy < 4; $yy++) for ($xx = 0; $xx < 4; $xx++) {
                    $quantCb4x4[$blkIdx][$yy * 4 + $xx] = $qU[$yy][$xx];
                    $quantCr4x4[$blkIdx][$yy * 4 + $xx] = $qV[$yy][$xx];
                }
                $nzU = 0;
                $nzV = 0;
                for ($i = 1; $i < 16; $i++) {
                    if ($quantCb4x4[$blkIdx][$i] !== 0) $nzU++;
                    if ($quantCr4x4[$blkIdx][$i] !== 0) $nzV++;
                }
                $nzCache[16 + $blkIdx] = min(15, $nzU);
                $nzCache[20 + $blkIdx] = min(15, $nzV);
                if ($nzU > 0 || $nzV > 0) $hasChromaAc = true;
            }
        }

        $hadCb = $this->forwardChromaHadamard2x2($dcCb2x2);
        $hadCr = $this->forwardChromaHadamard2x2($dcCr2x2);
        $qCbDc = $this->quantizeChromaDC($hadCb, $chromaQp);
        $qCrDc = $this->quantizeChromaDC($hadCr, $chromaQp);

        $hasChromaDc = false;
        for ($i = 0; $i < 4; $i++) {
            if ($qCbDc[$i] !== 0 || $qCrDc[$i] !== 0) {
                $hasChromaDc = true;
                break;
            }
        }

        $cbpChroma = 0;
        if ($hasChromaDc) {
            $cbpChroma = $hasChromaAc ? 2 : 1;
        }

        $mapModeI16x16 = [0, 1, 2, 3, 2, 2, 2];
        $mapModeChroma = [0, 1, 2, 3, 0, 0, 0];
        $i16Mode = $mapModeI16x16[$lumaPredMode];
        $chromaMode = $mapModeChroma[$chromaPredMode];

        if (!empty($this->useCabac)) {
            // ---- CABAC 写码分支：语法序镜像解码器 cabDecodeI16x16 ----
            // I16 的 cbp 内嵌于 mb_type bins（解码器由 bins 推出 luma15/chromaCbp，无独立 CBP 语法元素）
            $this->cabMbBegin($mbX, $mbY);
            $this->encWriteIntraMbTypeI(true, $cbpLuma !== 0 ? 1 : 0, $cbpChroma, $i16Mode);
            $this->cabFixUnavailNz(true);
            $this->encWriteChromaPred($chromaMode);
            $cbp = $cbpLuma | ($cbpChroma << 4);
            $this->cabCbp = $cbp;
            $this->picCbp[$this->cabMbIdx] = $cbp;
            $this->encWriteQpDelta(0);
            $this->encWriteLumaDcI16($dcFlat);
            if ($cbpLuma !== 0) {
                // 亮度 AC：n 序（scan8），cat1（15 系数 zz15），镜像解码器无条件读全部 16 块
                // quant4x4Luma 按 raster 块号构造，scan8 块号 n 须经 N2R 映射回 raster
                for ($n = 0; $n < 16; $n++) {
                    $this->encWriteAcBlock(1, $n, $quant4x4Luma[self::$N2R[$n]], true);
                }
            }
            if ($cbpChroma > 0) {
                $this->encWriteChromaDc(0, $qCbDc);
                $this->encWriteChromaDc(1, $qCrDc);
            }
            if ($cbpChroma >= 2) {
                // 色度 AC：Cb×4 → Cr×4（zz15，15 系数）
                for ($b = 0; $b < 4; $b++) {
                    $this->encWriteAcBlock(4, 16 + $b, $quantCb4x4[$b], true);
                }
                for ($b = 0; $b < 4; $b++) {
                    $this->encWriteAcBlock(4, 32 + $b, $quantCr4x4[$b], true);
                }
            }
            $this->encFinishI16();
        } else {
        $cbpIdx = ($cbpLuma == 0 ? 0 : 3) + $cbpChroma;
        $mbTypeValue = 1 + $i16Mode + ($cbpIdx << 2);
        $bits .= $this->ue($mbTypeValue);
        $bits .= $this->ue($chromaMode);
        $bits .= $this->se(0);
        $dcNc = $this->computeNC(-1, $mbX, 0, 0, $leftAvailable, $leftNz, $topAvailable, $topNzLuma, $nzCache);
        $bits .= $this->writeBlockResidualCavlc($dcZigzag, 15, false, $dcNc);
        $lumaAcScanOrder = [0, 1, 4, 5, 2, 3, 6, 7, 8, 9, 12, 13, 10, 11, 14, 15];
        $nzCacheNew = array_fill(0, 24, 0);
        if ($cbpLuma > 0) {
            foreach ($lumaAcScanOrder as $rasterIdx) {
                $by = (int)($rasterIdx / 4);
                $bx = $rasterIdx % 4;
                $acNc = $this->computeNC($rasterIdx, $mbX, $bx, $by, $leftAvailable, $leftNz, $topAvailable, $topNzLuma, $nzCacheNew);
                $ac = $this->scan4x4Ac($quant4x4Luma[$rasterIdx]);
                $bits .= $this->writeBlockResidualCavlc($ac, 14, false, $acNc);
                $nzCacheNew[$rasterIdx] = $nzCache[$rasterIdx];
            }
        }
        for ($by = 0; $by < 4; $by++) {
            $leftNz[$by] = ($cbpLuma > 0) ? $nzCache[$by * 4 + 3] : 0;
        }
        for ($bx = 0; $bx < 4; $bx++) {
            $topBlkX = $mbX * 4 + $bx;
            if ($topBlkX < count($topNzLuma)) {
                $topNzLuma[$topBlkX] = ($cbpLuma > 0) ? $nzCache[$bx + 12] : 0;
            }
        }

        if ($cbpChroma > 0) {
            $bits .= $this->writeBlockResidualCavlc($qCbDc, 3, true, -1);
            $bits .= $this->writeBlockResidualCavlc($qCrDc, 3, true, -1);
            if ($cbpChroma === 2) {
                $cbScanOrder = [16, 17, 18, 19];
                foreach ($cbScanOrder as $blockIdx) {
                    $blk = $blockIdx - 16;
                    $by = (int)($blk / 2);
                    $bx = $blk % 2;
                    $acNc = $this->computeNC($blockIdx, $mbX, $bx, $by, $leftAvailable, $leftNz, $topAvailable, $topNzCb, $nzCacheNew);
                    $acCb = $this->scan4x4Ac($quantCb4x4[$blk]);
                    $bits .= $this->writeBlockResidualCavlc($acCb, 14, false, $acNc);
                    $nzCacheNew[$blockIdx] = $nzCache[$blockIdx];
                }
                $crScanOrder = [20, 21, 22, 23];
                foreach ($crScanOrder as $blockIdx) {
                    $blk = $blockIdx - 20;
                    $by = (int)($blk / 2);
                    $bx = $blk % 2;
                    $acNc = $this->computeNC($blockIdx, $mbX, $bx, $by, $leftAvailable, $leftNz, $topAvailable, $topNzCr, $nzCacheNew);
                    $acCr = $this->scan4x4Ac($quantCr4x4[$blk]);
                    $bits .= $this->writeBlockResidualCavlc($acCr, 14, false, $acNc);
                    $nzCacheNew[$blockIdx] = $nzCache[$blockIdx];
                }
            }

            for ($by = 0; $by < 2; $by++) {
                $cbBlk = $by * 2 + 1;
                $leftNz[4 + $by] = $nzCache[16 + $cbBlk];
                $leftNz[6 + $by] = $nzCache[20 + $cbBlk];
            }
            $topCbx0 = $mbX * 2 + 0;
            $topCbx1 = $mbX * 2 + 1;
            if ($topCbx1 < count($topNzCb)) {
                $topNzCb[$topCbx0] = $nzCache[18];
                $topNzCb[$topCbx1] = $nzCache[19];
                $topNzCr[$topCbx0] = $nzCache[22];
                $topNzCr[$topCbx1] = $nzCache[23];
            }
        } else {
            for ($by = 0; $by < 2; $by++) {
                $leftNz[4 + $by] = 0;
                $leftNz[6 + $by] = 0;
            }
            $topCbx0 = $mbX * 2 + 0;
            $topCbx1 = $mbX * 2 + 1;
            if ($topCbx1 < count($topNzCb)) {
                $topNzCb[$topCbx0] = 0;
                $topNzCb[$topCbx1] = 0;
                $topNzCr[$topCbx0] = 0;
                $topNzCr[$topCbx1] = 0;
            }
        }
        } // end CAVLC

        // === 本地解码重建（用于正确更新参考帧）===
        // 1. 反量化DC Hadamard系数
        $dcFlatRecon = [];
        for ($y = 0; $y < 4; $y++) for ($x = 0; $x < 4; $x++) $dcFlatRecon[] = $dcQuant[$y][$x];
        $lumaQmul = $this->dequant4Table[0][$this->qp][0];
        $dcResultRecon = $this->lumaDcDequantIdct($dcFlatRecon, $lumaQmul);

        // 2. 逐4x4块重建像素
        for ($by = 0; $by < 4; $by++) {
            for ($bx = 0; $bx < 4; $bx++) {
                $rasterIdx = $by * 4 + $bx;
                $dcResidual = $dcResultRecon[$rasterIdx];

                if ($cbpLuma > 0) {
                    // AC存在: 反量化AC, 将DC放入[0], 做原地IDCT（省 2D flatten/expand）
                    $ac = $quant4x4Luma[$rasterIdx];
                    $this->dequantize4x4($ac, 0, $this->qp);
                    $ac[0] = $dcResidual;
                    $this->idctFlat($ac);

                    for ($y = 0; $y < 4; $y++) {
                        for ($x = 0; $x < 4; $x++) {
                            $py = $mbY * 16 + $by * 4 + $y;
                            $px = $mbX * 16 + $bx * 4 + $x;
                            $val = $predPixels[$by * 4 + $y][$bx * 4 + $x] + $ac[$y * 4 + $x];
                            $val = max(0, min(255, $val));
                            $this->reconYPlane[$py * $reconStride + $px] = chr($val);
                        }
                    }
                } else {
                    // 仅DC: (DC + 32) >> 6
                    $dcAdd = ($dcResidual + 32) >> 6;
                    for ($y = 0; $y < 4; $y++) {
                        for ($x = 0; $x < 4; $x++) {
                            $py = $mbY * 16 + $by * 4 + $y;
                            $px = $mbX * 16 + $bx * 4 + $x;
                            $val = $predPixels[$by * 4 + $y][$bx * 4 + $x] + $dcAdd;
                            $val = max(0, min(255, $val));
                            $this->reconYPlane[$py * $reconStride + $px] = chr($val);
                        }
                    }
                }
            }
        }

        // === 色度本地解码重建（用于正确更新色度参考帧）===
        $chromaW = intdiv($this->mbAlignedWidth, 2);
        $cbQmul = $this->dequant4Table[1][$chromaQp][0];
        $crQmul = $this->dequant4Table[2][$chromaQp][0];
        $cbDcResult = $this->chromaDcDequantIdct($qCbDc, $cbQmul);
        $crDcResult = $this->chromaDcDequantIdct($qCrDc, $crQmul);

        for ($by = 0; $by < 2; $by++) {
            for ($bx = 0; $bx < 2; $bx++) {
                $blk = $by * 2 + $bx;
                $cbDcResidual = $cbDcResult[$blk];
                $crDcResidual = $crDcResult[$blk];

                if ($cbpChroma >= 2) {
                    // AC存在: DC放入[0], 原地IDCT
                    $cbAc = $quantCb4x4[$blk];
                    $crAc = $quantCr4x4[$blk];
                    $this->dequantize4x4($cbAc, 1, $chromaQp);
                    $this->dequantize4x4($crAc, 2, $chromaQp);
                    $cbAc[0] = $cbDcResidual;
                    $crAc[0] = $crDcResidual;
                    $this->idctFlat($cbAc);
                    $this->idctFlat($crAc);

                    for ($y = 0; $y < 4; $y++) {
                        for ($x = 0; $x < 4; $x++) {
                            $py = $mbY * 8 + $by * 4 + $y;
                            $px = $mbX * 8 + $bx * 4 + $x;
                            $vu = $chromaPredU[$by * 4 + $y][$bx * 4 + $x] + $cbAc[$y * 4 + $x];
                            $vv = $chromaPredV[$by * 4 + $y][$bx * 4 + $x] + $crAc[$y * 4 + $x];
                            $idx = $py * $chromaW + $px;
                            $this->reconUPlane[$idx] = chr(max(0, min(255, $vu)));
                            $this->reconVPlane[$idx] = chr(max(0, min(255, $vv)));
                        }
                    }
                } elseif ($cbpChroma == 1) {
                    // DC-only: (DC + 32) >> 6
                    $cbDcAdd = ($cbDcResidual + 32) >> 6;
                    $crDcAdd = ($crDcResidual + 32) >> 6;
                    for ($y = 0; $y < 4; $y++) {
                        for ($x = 0; $x < 4; $x++) {
                            $py = $mbY * 8 + $by * 4 + $y;
                            $px = $mbX * 8 + $bx * 4 + $x;
                            $vu = $chromaPredU[$by * 4 + $y][$bx * 4 + $x] + $cbDcAdd;
                            $vv = $chromaPredV[$by * 4 + $y][$bx * 4 + $x] + $crDcAdd;
                            $idx = $py * $chromaW + $px;
                            $this->reconUPlane[$idx] = chr(max(0, min(255, $vu)));
                            $this->reconVPlane[$idx] = chr(max(0, min(255, $vv)));
                        }
                    }
                } else {
                    // cbpChroma == 0: 直接使用预测值
                    for ($y = 0; $y < 4; $y++) {
                        for ($x = 0; $x < 4; $x++) {
                            $py = $mbY * 8 + $by * 4 + $y;
                            $px = $mbX * 8 + $bx * 4 + $x;
                            $idx = $py * $chromaW + $px;
                            $this->reconUPlane[$idx] = chr(max(0, min(255, $chromaPredU[$by * 4 + $y][$bx * 4 + $x])));
                            $this->reconVPlane[$idx] = chr(max(0, min(255, $chromaPredV[$by * 4 + $y][$bx * 4 + $x])));
                        }
                    }
                }
            }
        }
        return $bits;
    }

    public function encodeMacroblockI4x4(
        $mbX, $mbY, $yPlane, $uPlane, $vPlane,
        $leftAvailable, &$leftNz, $topAvailable,
        &$topNzLuma, &$topNzCb, &$topNzCr,
        &$leftIntra4x4Mode = null, &$topIntra4x4Mode = null
    )
    {
        $bits = '';
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

        $leftPixels4x4 = array_fill(0, 8, array_fill(0, 4, 128));
        $topPixels4x4 = array_fill(0, 4, array_fill(0, 8, 128));
        if ($leftAvailable) {
            $refX = $mbX * 16 - 1;
            for ($by = 0; $by < 4; $by++) {
                for ($y = 0; $y < 4; $y++) {
                    $py = $mbY * 16 + $by * 4 + $y;
                    if ($py >= $this->height) break 2;
                    $idx = $py * $this->width + $refX;
                    if ($idx >= 0 && $idx < strlen($this->reconYPlane)) {
                        $leftPixels4x4[$by][$y] = ord($this->reconYPlane[$idx]);
                    }
                }
            }
        }
        if ($topAvailable) {
            $refY = $mbY * 16 - 1;
            for ($bx = 0; $bx < 4; $bx++) {
                for ($x = 0; $x < 4; $x++) {
                    $px = $mbX * 16 + $bx * 4 + $x;
                    if ($px >= $this->width) break 2;
                    $idx = $refY * $this->width + $px;
                    if ($idx >= 0 && $idx < strlen($this->reconYPlane)) {
                        $topPixels4x4[$bx][$x] = ord($this->reconYPlane[$idx]);
                    }
                }
            }
            for ($bx = 0; $bx < 4; $bx++) {
                for ($x = 4; $x < 8; $x++) {
                    $px = $mbX * 16 + $bx * 4 + $x;
                    if ($px >= $this->width) {
                        $topPixels4x4[$bx][$x] = $topPixels4x4[$bx][3];
                    } else {
                        $idx = $refY * $this->width + $px;
                        if ($idx >= 0 && $idx < strlen($this->reconYPlane)) {
                            $topPixels4x4[$bx][$x] = ord($this->reconYPlane[$idx]);
                        }
                    }
                }
            }
        }

        //$lumaAcScanOrder = [0, 1, 4, 5, 2, 3, 6, 7, 8, 9, 12, 13, 10, 11, 14, 15];
        $intra4x4PredModes = array_fill(0, 16, 2);
        for ($by = 0; $by < 4; $by++) {
            for ($bx = 0; $bx < 4; $bx++) {
                $blkIdx = $by * 4 + $bx;
                $leftAvail = ($bx > 0) || $leftAvailable;
                $topAvail = $topAvailable;
                $topRightAvail = false;
                if ($topAvail && $bx < 3) {
                    $topRightAvail = true;
                }

                $top = array_fill(0, 8, 128);
                $left = array_fill(0, 4, 128);
                $topLeft = 128;

                if ($leftAvail) {
                    if ($bx > 0) {
                        for ($y = 0; $y < 4; $y++) {
                            $left[$y] = $lumaPixels[$by * 4 + $y][$bx * 4 - 1];
                        }
                    } else {
                        for ($y = 0; $y < 4; $y++) {
                            $left[$y] = $leftPixels4x4[$by][$y];
                        }
                    }
                }

                if ($topAvail) {
                    for ($x = 0; $x < 4; $x++) {
                        $top[$x] = $topPixels4x4[$bx][$x];
                    }
                    if ($topRightAvail) {
                        for ($x = 4; $x < 8; $x++) {
                            $top[$x] = $topPixels4x4[$bx][$x];
                        }
                    } else {
                        for ($x = 4; $x < 8; $x++) {
                            $top[$x] = $top[3];
                        }
                    }
                }

                if ($topAvail && $leftAvail) {
                    if ($bx > 0 && $by > 0) {
                        $topLeft = $lumaPixels[$by * 4 - 1][$bx * 4 - 1];
                    } elseif ($bx > 0) {
                        $topLeft = $top[0];
                    } elseif ($by > 0) {
                        $topLeft = $left[0];
                    }
                } elseif ($topAvail) {
                    $topLeft = $top[0];
                } elseif ($leftAvail) {
                    $topLeft = $left[0];
                }

                $bestMode = 2;
                $bestCost = PHP_INT_MAX;
                for ($mode = 0; $mode <= 8; $mode++) {
                    // fastIntraPrune: 只评估 DC(2)/Vertical(0)/Horizontal(1)/DiagonalDownLeft(3) 四种常用模式
                    // 原来的完整 9 模式评估代码（case 0-8 switch）全部保留，剪枝仅在此处 continue 跳过
                    if (!empty($this->fastIntraPrune) && !in_array($mode, [0, 1, 2, 3], true)) {
                        continue;
                    }
                    if (!$topAvail && ($mode === 0 || $mode === 3 || $mode === 4 || $mode === 5 || $mode === 7 || $mode === 8)) {
                        continue;
                    }
                    if (!$leftAvail && ($mode === 1 || $mode === 4 || $mode === 6)) {
                        continue;
                    }
                    $pred = array_fill(0, 4, array_fill(0, 4, 128));
                    switch ($mode) {
                        case 0:
                            for ($y = 0; $y < 4; $y++) {
                                for ($x = 0; $x < 4; $x++) {
                                    $pred[$y][$x] = $top[$x];
                                }
                            }
                            break;
                        case 1:
                            for ($y = 0; $y < 4; $y++) {
                                for ($x = 0; $x < 4; $x++) {
                                    $pred[$y][$x] = $left[$y];
                                }
                            }
                            break;
                        case 2:
                            if ($topAvail && $leftAvail) {
                                $sum = $top[0] + $top[1] + $top[2] + $top[3] +
                                    $left[0] + $left[1] + $left[2] + $left[3];
                                $avg = ($sum + 4) >> 3;
                            } elseif ($topAvail) {
                                $sum = $top[0] + $top[1] + $top[2] + $top[3];
                                $avg = ($sum + 2) >> 2;
                            } elseif ($leftAvail) {
                                $sum = $left[0] + $left[1] + $left[2] + $left[3];
                                $avg = ($sum + 2) >> 2;
                            } else {
                                $avg = 128;
                            }
                            for ($y = 0; $y < 4; $y++) {
                                for ($x = 0; $x < 4; $x++) {
                                    $pred[$y][$x] = $avg;
                                }
                            }
                            break;
                        case 3:
                            $t0 = $top[0]; $t1 = $top[1]; $t2 = $top[2]; $t3 = $top[3];
                            $t4 = $top[4]; $t5 = $top[5]; $t6 = $top[6]; $t7 = $top[7];
                            $pred[0][0] = (int)(($t0 + 2 * $t1 + $t2 + 2) >> 2);
                            $pred[0][1] = (int)(($t1 + 2 * $t2 + $t3 + 2) >> 2);
                            $pred[1][0] = $pred[0][1];
                            $pred[0][2] = (int)(($t2 + 2 * $t3 + $t4 + 2) >> 2);
                            $pred[1][1] = $pred[0][2];
                            $pred[2][0] = $pred[0][2];
                            $pred[0][3] = (int)(($t3 + 2 * $t4 + $t5 + 2) >> 2);
                            $pred[1][2] = $pred[0][3];
                            $pred[2][1] = $pred[0][3];
                            $pred[3][0] = $pred[0][3];
                            $pred[1][3] = (int)(($t4 + 2 * $t5 + $t6 + 2) >> 2);
                            $pred[2][2] = $pred[1][3];
                            $pred[3][1] = $pred[1][3];
                            $pred[2][3] = (int)(($t5 + 2 * $t6 + $t7 + 2) >> 2);
                            $pred[3][2] = $pred[2][3];
                            $pred[3][3] = (int)(($t6 + 3 * $t7 + 2) >> 2);
                            break;
                        case 4:
                            $lt = $topLeft;
                            $t0 = $top[0]; $t1 = $top[1]; $t2 = $top[2]; $t3 = $top[3];
                            $l0 = $left[0]; $l1 = $left[1]; $l2 = $left[2]; $l3 = $left[3];
                            $avg3 = function($a, $b, $c) { return (int)(($a + 2 * $b + $c + 2) >> 2); };
                            $v03 = $avg3($l3, $l2, $l1);
                            $v02 = $avg3($l2, $l1, $l0);
                            $v01 = $avg3($l1, $l0, $lt);
                            $v00 = $avg3($l0, $lt, $t0);
                            $v10 = $avg3($lt, $t0, $t1);
                            $v20 = $avg3($t0, $t1, $t2);
                            $v30 = $avg3($t1, $t2, $t3);
                            $pred[3][0] = $v03; $pred[2][0] = $v02; $pred[3][1] = $v02;
                            $pred[1][0] = $v01; $pred[2][1] = $v01; $pred[3][2] = $v01;
                            $pred[0][0] = $v00; $pred[1][1] = $v00; $pred[2][2] = $v00; $pred[3][3] = $v00;
                            $pred[0][1] = $v10; $pred[1][2] = $v10; $pred[2][3] = $v10;
                            $pred[0][2] = $v20; $pred[1][3] = $v20;
                            $pred[0][3] = $v30;
                            break;
                        case 5:
                            $lt = $topLeft;
                            $t0 = $top[0]; $t1 = $top[1]; $t2 = $top[2]; $t3 = $top[3];
                            $l0 = $left[0]; $l1 = $left[1]; $l2 = $left[2];
                            $avg2 = function($a, $b) { return (int)(($a + $b + 1) >> 1); };
                            $avg3 = function($a, $b, $c) { return (int)(($a + 2 * $b + $c + 2) >> 2); };
                            $pred[0][0] = $avg2($lt, $t0); $pred[2][1] = $pred[0][0];
                            $pred[0][1] = $avg2($t0, $t1); $pred[2][2] = $pred[0][1];
                            $pred[0][2] = $avg2($t1, $t2); $pred[2][3] = $pred[0][2];
                            $pred[0][3] = $avg2($t2, $t3);
                            $pred[1][0] = $avg3($l0, $lt, $t0); $pred[3][1] = $pred[1][0];
                            $pred[1][1] = $avg3($lt, $t0, $t1); $pred[3][2] = $pred[1][1];
                            $pred[1][2] = $avg3($t0, $t1, $t2); $pred[3][3] = $pred[1][2];
                            $pred[1][3] = $avg3($t1, $t2, $t3);
                            $pred[2][0] = $avg3($lt, $l0, $l1);
                            $pred[3][0] = $avg3($l0, $l1, $l2);
                            break;
                        case 6:
                            $lt = $topLeft;
                            $t0 = $top[0]; $t1 = $top[1]; $t2 = $top[2];
                            $l0 = $left[0]; $l1 = $left[1]; $l2 = $left[2]; $l3 = $left[3];
                            $avg2 = function($a, $b) { return (int)(($a + $b + 1) >> 1); };
                            $avg3 = function($a, $b, $c) { return (int)(($a + 2 * $b + $c + 2) >> 2); };
                            $pred[0][0] = $avg2($lt, $l0); $pred[1][2] = $pred[0][0];
                            $pred[0][1] = $avg3($l0, $lt, $t0); $pred[1][3] = $pred[0][1];
                            $pred[0][2] = $avg3($lt, $t0, $t1);
                            $pred[0][3] = $avg3($t0, $t1, $t2);
                            $pred[1][0] = $avg2($l0, $l1); $pred[2][2] = $pred[1][0];
                            $pred[1][1] = $avg3($lt, $l0, $l1); $pred[2][3] = $pred[1][1];
                            $pred[2][0] = $avg2($l1, $l2); $pred[3][2] = $pred[2][0];
                            $pred[2][1] = $avg3($l0, $l1, $l2); $pred[3][3] = $pred[2][1];
                            $pred[3][0] = $avg2($l2, $l3);
                            $pred[3][1] = $avg3($l1, $l2, $l3);
                            break;
                        case 7:
                            $t0 = $top[0]; $t1 = $top[1]; $t2 = $top[2]; $t3 = $top[3];
                            $t4 = $top[4]; $t5 = $top[5]; $t6 = $top[6];
                            $avg2 = function($a, $b) { return (int)(($a + $b + 1) >> 1); };
                            $avg3 = function($a, $b, $c) { return (int)(($a + 2 * $b + $c + 2) >> 2); };
                            $pred[0][0] = $avg2($t0, $t1);
                            $pred[0][1] = $avg2($t1, $t2); $pred[2][0] = $pred[0][1];
                            $pred[0][2] = $avg2($t2, $t3); $pred[2][1] = $pred[0][2];
                            $pred[0][3] = $avg2($t3, $t4); $pred[2][2] = $pred[0][3];
                            $pred[2][3] = $avg2($t4, $t5);
                            $pred[1][0] = $avg3($t0, $t1, $t2);
                            $pred[1][1] = $avg3($t1, $t2, $t3); $pred[3][0] = $pred[1][1];
                            $pred[1][2] = $avg3($t2, $t3, $t4); $pred[3][1] = $pred[1][2];
                            $pred[1][3] = $avg3($t3, $t4, $t5); $pred[3][2] = $pred[1][3];
                            $pred[3][3] = $avg3($t4, $t5, $t6);
                            break;
                        case 8:
                            $l0 = $left[0]; $l1 = $left[1]; $l2 = $left[2]; $l3 = $left[3];
                            $avg2 = function($a, $b) { return (int)(($a + $b + 1) >> 1); };
                            $avg3 = function($a, $b, $c) { return (int)(($a + 2 * $b + $c + 2) >> 2); };
                            $pred[0][0] = $avg2($l0, $l1);
                            $pred[0][1] = $avg3($l0, $l1, $l2);
                            $pred[0][2] = $avg2($l1, $l2); $pred[1][0] = $pred[0][2];
                            $pred[0][3] = $avg3($l1, $l2, $l3); $pred[1][1] = $pred[0][3];
                            $pred[1][2] = $avg2($l2, $l3); $pred[2][0] = $pred[1][2];
                            $pred[1][3] = $avg3($l2, $l3, $l3); $pred[2][1] = $pred[1][3];
                            $pred[2][3] = $l3; $pred[3][1] = $l3; $pred[3][0] = $l3;
                            $pred[2][2] = $l3; $pred[3][2] = $l3; $pred[3][3] = $l3;
                            break;
                    }

                    $cost = 0;
                    for ($y = 0; $y < 4; $y++) {
                        for ($x = 0; $x < 4; $x++) {
                            $diff = $lumaPixels[$by * 4 + $y][$bx * 4 + $x] - $pred[$y][$x];
                            $cost += $diff * $diff;
                        }
                    }
                    if ($cost < $bestCost) {
                        $bestCost = $cost;
                        $bestMode = $mode;
                    }
                }
                $intra4x4PredModes[$blkIdx] = $bestMode;
            }
        }
        $quant4x4Luma = array_fill(0, 16, array_fill(0, 16, 0));
        $nzCache = array_fill(0, 24, 0);
        $cbpLuma = 0;
        for ($by = 0; $by < 4; $by++) {
            for ($bx = 0; $bx < 4; $bx++) {
                $blkIdx = $by * 4 + $bx;
                $blk4x4 = array_fill(0, 4, array_fill(0, 4, 0));
                for ($y = 0; $y < 4; $y++) {
                    for ($x = 0; $x < 4; $x++) {
                        $py = $by * 4 + $y;
                        $px = $bx * 4 + $x;
                        $blk4x4[$y][$x] = $lumaPixels[$py][$px];
                    }
                }

                $leftAvail = ($bx > 0) || $leftAvailable;
                $topAvail = ($by > 0) || $topAvailable;
                $topRightAvail = false;
                if ($topAvail && $bx < 3) {
                    $topRightAvail = true;
                }

                $top = array_fill(0, 8, 128);
                $left = array_fill(0, 4, 128);
                $topLeft = 128;

                if ($leftAvail) {
                    if ($bx > 0) {
                        for ($y = 0; $y < 4; $y++) {
                            $left[$y] = $lumaPixels[$by * 4 + $y][$bx * 4 - 1];
                        }
                    } else {
                        for ($y = 0; $y < 4; $y++) {
                            $left[$y] = $leftPixels4x4[$by][$y];
                        }
                    }
                }

                if ($topAvail) {
                    if ($by > 0) {
                        for ($x = 0; $x < 4; $x++) {
                            $top[$x] = $lumaPixels[$by * 4 - 1][$bx * 4 + $x];
                        }
                        for ($x = 4; $x < 8; $x++) {
                            $px = $bx * 4 + $x;
                            if ($px < 16) {
                                $top[$x] = $lumaPixels[$by * 4 - 1][$px];
                            } else {
                                $top[$x] = $top[3];
                            }
                        }
                    } else {
                        for ($x = 0; $x < 4; $x++) {
                            $top[$x] = $topPixels4x4[$bx][$x];
                        }
                        if ($topRightAvail) {
                            for ($x = 4; $x < 8; $x++) {
                                $top[$x] = $topPixels4x4[$bx][$x];
                            }
                        } else {
                            for ($x = 4; $x < 8; $x++) {
                                $top[$x] = $top[3];
                            }
                        }
                    }
                }

                if ($topAvail && $leftAvail) {
                    if ($bx > 0 && $by > 0) {
                        $topLeft = $lumaPixels[$by * 4 - 1][$bx * 4 - 1];
                    } elseif ($bx > 0) {
                        $topLeft = $top[0];
                    } elseif ($by > 0) {
                        $topLeft = $left[0];
                    }
                } elseif ($topAvail) {
                    $topLeft = $top[0];
                } elseif ($leftAvail) {
                    $topLeft = $left[0];
                }

                $mode = $intra4x4PredModes[$blkIdx];
                if (!$topAvail && ($mode === 0 || $mode === 3 || $mode === 4 || $mode === 5 || $mode === 7)) {
                    $mode = 2;
                }
                if (!$leftAvail && ($mode === 1 || $mode === 4 || $mode === 6 || $mode === 8)) {
                    $mode = 2;
                }
                $intra4x4PredModes[$blkIdx] = $mode;
                $predPixels = array_fill(0, 4, array_fill(0, 4, 128));
                switch ($mode) {
                    case 0:
                        for ($y = 0; $y < 4; $y++) {
                            for ($x = 0; $x < 4; $x++) {
                                $predPixels[$y][$x] = $top[$x];
                            }
                        }
                        break;
                    case 1:
                        for ($y = 0; $y < 4; $y++) {
                            for ($x = 0; $x < 4; $x++) {
                                $predPixels[$y][$x] = $left[$y];
                            }
                        }
                        break;
                    case 2:
                        if ($topAvail && $leftAvail) {
                            $sum = $top[0] + $top[1] + $top[2] + $top[3] +
                                $left[0] + $left[1] + $left[2] + $left[3];
                            $avg = ($sum + 4) >> 3;
                        } elseif ($topAvail) {
                            $sum = $top[0] + $top[1] + $top[2] + $top[3];
                            $avg = ($sum + 2) >> 2;
                        } elseif ($leftAvail) {
                            $sum = $left[0] + $left[1] + $left[2] + $left[3];
                            $avg = ($sum + 2) >> 2;
                        } else {
                            $avg = 128;
                        }
                        for ($y = 0; $y < 4; $y++) {
                            for ($x = 0; $x < 4; $x++) {
                                $predPixels[$y][$x] = $avg;
                            }
                        }
                        break;
                    case 3:
                        $t0 = $top[0]; $t1 = $top[1]; $t2 = $top[2]; $t3 = $top[3];
                        $t4 = $top[4]; $t5 = $top[5]; $t6 = $top[6]; $t7 = $top[7];
                        $predPixels[0][0] = (int)(($t0 + 2 * $t1 + $t2 + 2) >> 2);
                        $predPixels[0][1] = (int)(($t1 + 2 * $t2 + $t3 + 2) >> 2);
                        $predPixels[1][0] = $predPixels[0][1];
                        $predPixels[0][2] = (int)(($t2 + 2 * $t3 + $t4 + 2) >> 2);
                        $predPixels[1][1] = $predPixels[0][2];
                        $predPixels[2][0] = $predPixels[0][2];
                        $predPixels[0][3] = (int)(($t3 + 2 * $t4 + $t5 + 2) >> 2);
                        $predPixels[1][2] = $predPixels[0][3];
                        $predPixels[2][1] = $predPixels[0][3];
                        $predPixels[3][0] = $predPixels[0][3];
                        $predPixels[1][3] = (int)(($t4 + 2 * $t5 + $t6 + 2) >> 2);
                        $predPixels[2][2] = $predPixels[1][3];
                        $predPixels[3][1] = $predPixels[1][3];
                        $predPixels[2][3] = (int)(($t5 + 2 * $t6 + $t7 + 2) >> 2);
                        $predPixels[3][2] = $predPixels[2][3];
                        $predPixels[3][3] = (int)(($t6 + 3 * $t7 + 2) >> 2);
                        break;
                    case 4:
                        $lt = $topLeft;
                        $t0 = $top[0]; $t1 = $top[1]; $t2 = $top[2]; $t3 = $top[3];
                        $l0 = $left[0]; $l1 = $left[1]; $l2 = $left[2]; $l3 = $left[3];
                        $avg3 = function($a, $b, $c) { return (int)(($a + 2 * $b + $c + 2) >> 2); };
                        $v03 = $avg3($l3, $l2, $l1);
                        $v02 = $avg3($l2, $l1, $l0);
                        $v01 = $avg3($l1, $l0, $lt);
                        $v00 = $avg3($l0, $lt, $t0);
                        $v10 = $avg3($lt, $t0, $t1);
                        $v20 = $avg3($t0, $t1, $t2);
                        $v30 = $avg3($t1, $t2, $t3);
                        $predPixels[3][0] = $v03; $predPixels[2][0] = $v02; $predPixels[3][1] = $v02;
                        $predPixels[1][0] = $v01; $predPixels[2][1] = $v01; $predPixels[3][2] = $v01;
                        $predPixels[0][0] = $v00; $predPixels[1][1] = $v00; $predPixels[2][2] = $v00; $predPixels[3][3] = $v00;
                        $predPixels[0][1] = $v10; $predPixels[1][2] = $v10; $predPixels[2][3] = $v10;
                        $predPixels[0][2] = $v20; $predPixels[1][3] = $v20;
                        $predPixels[0][3] = $v30;
                        break;
                    case 5:
                        $lt = $topLeft;
                        $t0 = $top[0]; $t1 = $top[1]; $t2 = $top[2]; $t3 = $top[3];
                        $l0 = $left[0]; $l1 = $left[1]; $l2 = $left[2];
                        $avg2 = function($a, $b) { return (int)(($a + $b + 1) >> 1); };
                        $avg3 = function($a, $b, $c) { return (int)(($a + 2 * $b + $c + 2) >> 2); };
                        $predPixels[0][0] = $avg2($lt, $t0); $predPixels[2][1] = $predPixels[0][0];
                        $predPixels[0][1] = $avg2($t0, $t1); $predPixels[2][2] = $predPixels[0][1];
                        $predPixels[0][2] = $avg2($t1, $t2); $predPixels[2][3] = $predPixels[0][2];
                        $predPixels[0][3] = $avg2($t2, $t3);
                        $predPixels[1][0] = $avg3($l0, $lt, $t0); $predPixels[3][1] = $predPixels[1][0];
                        $predPixels[1][1] = $avg3($lt, $t0, $t1); $predPixels[3][2] = $predPixels[1][1];
                        $predPixels[1][2] = $avg3($t0, $t1, $t2); $predPixels[3][3] = $predPixels[1][2];
                        $predPixels[1][3] = $avg3($t1, $t2, $t3);
                        $predPixels[2][0] = $avg3($lt, $l0, $l1);
                        $predPixels[3][0] = $avg3($l0, $l1, $l2);
                        break;
                    case 6:
                        $lt = $topLeft;
                        $t0 = $top[0]; $t1 = $top[1]; $t2 = $top[2];
                        $l0 = $left[0]; $l1 = $left[1]; $l2 = $left[2]; $l3 = $left[3];
                        $avg2 = function($a, $b) { return (int)(($a + $b + 1) >> 1); };
                        $avg3 = function($a, $b, $c) { return (int)(($a + 2 * $b + $c + 2) >> 2); };
                        $predPixels[0][0] = $avg2($lt, $l0); $predPixels[1][2] = $predPixels[0][0];
                        $predPixels[0][1] = $avg3($l0, $lt, $t0); $predPixels[1][3] = $predPixels[0][1];
                        $predPixels[0][2] = $avg3($lt, $t0, $t1);
                        $predPixels[0][3] = $avg3($t0, $t1, $t2);
                        $predPixels[1][0] = $avg2($l0, $l1); $predPixels[2][2] = $predPixels[1][0];
                        $predPixels[1][1] = $avg3($lt, $l0, $l1); $predPixels[2][3] = $predPixels[1][1];
                        $predPixels[2][0] = $avg2($l1, $l2); $predPixels[3][2] = $predPixels[2][0];
                        $predPixels[2][1] = $avg3($l0, $l1, $l2); $predPixels[3][3] = $predPixels[2][1];
                        $predPixels[3][0] = $avg2($l2, $l3);
                        $predPixels[3][1] = $avg3($l1, $l2, $l3);
                        break;
                    case 7:
                        $t0 = $top[0]; $t1 = $top[1]; $t2 = $top[2]; $t3 = $top[3];
                        $t4 = $top[4]; $t5 = $top[5]; $t6 = $top[6];
                        $avg2 = function($a, $b) { return (int)(($a + $b + 1) >> 1); };
                        $avg3 = function($a, $b, $c) { return (int)(($a + 2 * $b + $c + 2) >> 2); };
                        $predPixels[0][0] = $avg2($t0, $t1);
                        $predPixels[0][1] = $avg2($t1, $t2); $predPixels[2][0] = $predPixels[0][1];
                        $predPixels[0][2] = $avg2($t2, $t3); $predPixels[2][1] = $predPixels[0][2];
                        $predPixels[0][3] = $avg2($t3, $t4); $predPixels[2][2] = $predPixels[0][3];
                        $predPixels[2][3] = $avg2($t4, $t5);
                        $predPixels[1][0] = $avg3($t0, $t1, $t2);
                        $predPixels[1][1] = $avg3($t1, $t2, $t3); $predPixels[3][0] = $predPixels[1][1];
                        $predPixels[1][2] = $avg3($t2, $t3, $t4); $predPixels[3][1] = $predPixels[1][2];
                        $predPixels[1][3] = $avg3($t3, $t4, $t5); $predPixels[3][2] = $predPixels[1][3];
                        $predPixels[3][3] = $avg3($t4, $t5, $t6);
                        break;
                    case 8:
                        $l0 = $left[0]; $l1 = $left[1]; $l2 = $left[2]; $l3 = $left[3];
                        $avg2 = function($a, $b) { return (int)(($a + $b + 1) >> 1); };
                        $avg3 = function($a, $b, $c) { return (int)(($a + 2 * $b + $c + 2) >> 2); };
                        $predPixels[0][0] = $avg2($l0, $l1);
                        $predPixels[0][1] = $avg3($l0, $l1, $l2);
                        $predPixels[0][2] = $avg2($l1, $l2); $predPixels[1][0] = $predPixels[0][2];
                        $predPixels[0][3] = $avg3($l1, $l2, $l3); $predPixels[1][1] = $predPixels[0][3];
                        $predPixels[1][2] = $avg2($l2, $l3); $predPixels[2][0] = $predPixels[1][2];
                        $predPixels[1][3] = $avg3($l2, $l3, $l3); $predPixels[2][1] = $predPixels[1][3];
                        $predPixels[2][3] = $l3; $predPixels[3][1] = $l3; $predPixels[3][0] = $l3;
                        $predPixels[2][2] = $l3; $predPixels[3][2] = $l3; $predPixels[3][3] = $l3;
                        break;
                }

                for ($y = 0; $y < 4; $y++) {
                    for ($x = 0; $x < 4; $x++) {
                        $blk4x4[$y][$x] -= $predPixels[$y][$x];
                    }
                }

                $dctBlock = $this->dct($blk4x4);
                $quantBlock = $this->quantize($dctBlock, 0);
                for ($yy = 0; $yy < 4; $yy++) {
                    for ($xx = 0; $xx < 4; $xx++) {
                        $quant4x4Luma[$blkIdx][$yy * 4 + $xx] = $quantBlock[$yy][$xx];
                    }
                }
                $nz = 0;
                for ($i = 1; $i < 16; $i++) {
                    if ($quant4x4Luma[$blkIdx][$i] !== 0) $nz++;
                }
                $nzCache[$blkIdx] = min(15, $nz);
                if ($nz > 0) {
                    $subY = intdiv($blkIdx, 4);
                    $subX = $blkIdx % 4;
                    $block8x8Idx = intdiv($subY, 2) * 2 + intdiv($subX, 2);
                    $cbpLuma |= 1 << $block8x8Idx;
                }
            }
        }

        $u8x8 = array_fill(0, 8, array_fill(0, 8, 128));
        $v8x8 = array_fill(0, 8, array_fill(0, 8, 128));
        $chromaQpIndex = max(0, min(51, $this->qp + $this->chromaQpIndexOffset));
        $chromaQp = self::CHROMA_QP_TABLE[$chromaQpIndex];
        $hasChromaAc = false;
        $quantCb4x4 = array_fill(0, 4, array_fill(0, 16, 0));
        $quantCr4x4 = array_fill(0, 4, array_fill(0, 16, 0));
        $dcCb2x2 = [0, 0, 0, 0];
        $dcCr2x2 = [0, 0, 0, 0];

        $chromaStride = (int)($this->mbAlignedWidth / 2);
        for ($y = 0; $y < 8; $y++) {
            $py = $mbY * 8 + $y;
            for ($x = 0; $x < 8; $x++) {
                $px = $mbX * 8 + $x;
                $idx = $py * $chromaStride + $px;
                $u8x8[$y][$x] = ord($uPlane[$idx]);
                $v8x8[$y][$x] = ord($vPlane[$idx]);
            }
        }

        $chromaLeftU = array_fill(0, 8, 128);
        $chromaTopU = array_fill(0, 8, 128);
        $chromaLeftV = array_fill(0, 8, 128);
        $chromaTopV = array_fill(0, 8, 128);
        $chromaCntL = 0;
        $chromaCntT = 0;
        $reconChromaStride = intdiv($this->mbAlignedWidth, 2);
        if ($leftAvailable) {
            $refX = $mbX * 8 - 1;
            for ($y = 0; $y < 8; $y++) {
                $py = $mbY * 8 + $y;
                $idx = $py * $reconChromaStride + $refX;
                $chromaLeftU[$y] = ord($this->reconUPlane[$idx]);
                $chromaLeftV[$y] = ord($this->reconVPlane[$idx]);
                $chromaCntL++;
            }
        }
        if ($topAvailable) {
            $refY = $mbY * 8 - 1;
            for ($x = 0; $x < 8; $x++) {
                $px = $mbX * 8 + $x;
                $idx = $refY * $reconChromaStride + $px;
                $chromaTopU[$x] = ord($this->reconUPlane[$idx]);
                $chromaTopV[$x] = ord($this->reconVPlane[$idx]);
                $chromaCntT++;
            }
        }

        $chromaPredMode = 0;
        $chromaPredU = array_fill(0, 8, array_fill(0, 8, 128));
        $chromaPredV = array_fill(0, 8, array_fill(0, 8, 128));

        $hasTop = $chromaCntT > 0;
        $hasLeft = $chromaCntL > 0;

        switch ($chromaPredMode) {
            case 0:
                if ($hasTop && $hasLeft) {
                    $dc0U = 0; $dc1U = 0; $dc2U = 0;
                    $dc0V = 0; $dc1V = 0; $dc2V = 0;
                    for ($i = 0; $i < 4; $i++) {
                        $dc0U += $chromaTopU[$i] + $chromaLeftU[$i];
                        $dc1U += $chromaTopU[4 + $i];
                        $dc2U += $chromaLeftU[4 + $i];
                        $dc0V += $chromaTopV[$i] + $chromaLeftV[$i];
                        $dc1V += $chromaTopV[4 + $i];
                        $dc2V += $chromaLeftV[4 + $i];
                    }
                    $dc0ValU = ($dc0U + 4) >> 3;
                    $dc1ValU = ($dc1U + 2) >> 2;
                    $dc2ValU = ($dc2U + 2) >> 2;
                    $dc3ValU = ($dc1U + $dc2U + 4) >> 3;
                    $dc0ValV = ($dc0V + 4) >> 3;
                    $dc1ValV = ($dc1V + 2) >> 2;
                    $dc2ValV = ($dc2V + 2) >> 2;
                    $dc3ValV = ($dc1V + $dc2V + 4) >> 3;
                } elseif (!$hasTop && $hasLeft) {
                    $dc0U = 0; $dc2U = 0;
                    $dc0V = 0; $dc2V = 0;
                    for ($i = 0; $i < 4; $i++) {
                        $dc0U += $chromaLeftU[$i];
                        $dc2U += $chromaLeftU[4 + $i];
                        $dc0V += $chromaLeftV[$i];
                        $dc2V += $chromaLeftV[4 + $i];
                    }
                    $dc0ValU = ($dc0U + 2) >> 2;
                    $dc1ValU = $dc0ValU;
                    $dc2ValU = ($dc2U + 2) >> 2;
                    $dc3ValU = $dc2ValU;
                    $dc0ValV = ($dc0V + 2) >> 2;
                    $dc1ValV = $dc0ValV;
                    $dc2ValV = ($dc2V + 2) >> 2;
                    $dc3ValV = $dc2ValV;
                } elseif ($hasTop && !$hasLeft) {
                    $dc0U = 0; $dc1U = 0;
                    $dc0V = 0; $dc1V = 0;
                    for ($i = 0; $i < 4; $i++) {
                        $dc0U += $chromaTopU[$i];
                        $dc1U += $chromaTopU[4 + $i];
                        $dc0V += $chromaTopV[$i];
                        $dc1V += $chromaTopV[4 + $i];
                    }
                    $dc0ValU = ($dc0U + 2) >> 2;
                    $dc1ValU = ($dc1U + 2) >> 2;
                    $dc2ValU = $dc0ValU;
                    $dc3ValU = $dc1ValU;
                    $dc0ValV = ($dc0V + 2) >> 2;
                    $dc1ValV = ($dc1V + 2) >> 2;
                    $dc2ValV = $dc0ValV;
                    $dc3ValV = $dc1ValV;
                } else {
                    $dc0ValU = $dc1ValU = $dc2ValU = $dc3ValU = 128;
                    $dc0ValV = $dc1ValV = $dc2ValV = $dc3ValV = 128;
                }
                for ($y = 0; $y < 4; $y++) {
                    for ($x = 0; $x < 4; $x++) {
                        $chromaPredU[$y][$x] = $dc0ValU;
                        $chromaPredV[$y][$x] = $dc0ValV;
                    }
                    for ($x = 4; $x < 8; $x++) {
                        $chromaPredU[$y][$x] = $dc1ValU;
                        $chromaPredV[$y][$x] = $dc1ValV;
                    }
                }
                for ($y = 4; $y < 8; $y++) {
                    for ($x = 0; $x < 4; $x++) {
                        $chromaPredU[$y][$x] = $dc2ValU;
                        $chromaPredV[$y][$x] = $dc2ValV;
                    }
                    for ($x = 4; $x < 8; $x++) {
                        $chromaPredU[$y][$x] = $dc3ValU;
                        $chromaPredV[$y][$x] = $dc3ValV;
                    }
                }
                break;
            case 1:
                for ($y = 0; $y < 8; $y++) {
                    for ($x = 0; $x < 8; $x++) {
                        $chromaPredU[$y][$x] = $chromaLeftU[$y];
                        $chromaPredV[$y][$x] = $chromaLeftV[$y];
                    }
                }
                break;
            case 2:
                for ($y = 0; $y < 8; $y++) {
                    for ($x = 0; $x < 8; $x++) {
                        $chromaPredU[$y][$x] = $chromaTopU[$x];
                        $chromaPredV[$y][$x] = $chromaTopV[$x];
                    }
                }
                break;
            default:
                for ($y = 0; $y < 8; $y++) {
                    for ($x = 0; $x < 8; $x++) {
                        $chromaPredU[$y][$x] = 128;
                        $chromaPredV[$y][$x] = 128;
                    }
                }
                break;
        }

        for ($by = 0; $by < 2; $by++) {
            for ($bx = 0; $bx < 2; $bx++) {
                $blkIdx = $by * 2 + $bx;
                $blkU = array_fill(0, 4, array_fill(0, 4, 0));
                $blkV = array_fill(0, 4, array_fill(0, 4, 0));
                for ($y = 0; $y < 4; $y++) {
                    for ($x = 0; $x < 4; $x++) {
                        $py = $by * 4 + $y;
                        $px = $bx * 4 + $x;
                        $blkU[$y][$x] = $u8x8[$py][$px] - $chromaPredU[$py][$px];
                        $blkV[$y][$x] = $v8x8[$py][$px] - $chromaPredV[$py][$px];
                    }
                }
                $dctU = $this->dct($blkU);
                $dctV = $this->dct($blkV);
                $dcCb2x2[$blkIdx] = $dctU[0][0];
                $dcCr2x2[$blkIdx] = $dctV[0][0];
                $qU = $this->quantizeChroma($dctU, $chromaQp);
                $qV = $this->quantizeChroma($dctV, $chromaQp);
                for ($yy = 0; $yy < 4; $yy++) {
                    for ($xx = 0; $xx < 4; $xx++) {
                        $quantCb4x4[$blkIdx][$yy * 4 + $xx] = $qU[$yy][$xx];
                        $quantCr4x4[$blkIdx][$yy * 4 + $xx] = $qV[$yy][$xx];
                    }
                }
                $nzU = 0;
                $nzV = 0;
                for ($i = 1; $i < 16; $i++) {
                    if ($quantCb4x4[$blkIdx][$i] !== 0) $nzU++;
                    if ($quantCr4x4[$blkIdx][$i] !== 0) $nzV++;
                }
                $nzCache[16 + $blkIdx] = min(15, $nzU);
                $nzCache[20 + $blkIdx] = min(15, $nzV);
                if ($nzU > 0 || $nzV > 0) $hasChromaAc = true;
            }
        }

        $hadCb = $this->forwardChromaHadamard2x2($dcCb2x2);
        $hadCr = $this->forwardChromaHadamard2x2($dcCr2x2);
        $qCbDc = $this->quantizeChromaDC($hadCb, $chromaQp);
        $qCrDc = $this->quantizeChromaDC($hadCr, $chromaQp);

        $hasChromaDc = false;
        for ($i = 0; $i < 4; $i++) {
            if ($qCbDc[$i] !== 0 || $qCrDc[$i] !== 0) {
                $hasChromaDc = true;
                break;
            }
        }

        $cbpChroma = 0;
        if ($hasChromaDc) {
            $cbpChroma = $hasChromaAc ? 2 : 1;
        }

        // I_4x4编码顺序：mb_type -> intra4x4_pred_mode -> intra_chroma_pred_mode -> CBP -> mb_qp_delta -> residual

        if (!empty($this->useCabac)) {
            // ---- CABAC 写码分支：语法序镜像解码器 cabDecodeI4x4（模式/残差均 n 序）----
            $this->cabMbBegin($mbX, $mbY);
            $this->encWriteIntraMbTypeI(false, 0, 0, 0);
            $this->cabFixUnavailNz(true);
            for ($n = 0; $n < 16; $n++) {
                $this->encWriteI4Mode($n, $intra4x4PredModes[self::$N2R[$n]]);
            }
            $this->encWriteChromaPred($chromaPredMode);
            $cbp = ($cbpChroma << 4) | $cbpLuma;
            // I4 的 CBP 是独立语法元素（I16 才内嵌于 mb_type），漏写会使解码端读飞错位
            $this->encWriteCbpLuma($cbpLuma);
            $this->encWriteCbpChroma($cbpChroma);
            $this->cabCbp = $cbp;
            $this->picCbp[$this->cabMbIdx] = $cbp;
            if ($cbp !== 0) {
                $this->encWriteQpDelta(0);
            } else {
                $this->cabLastQpDiff = 0;
            }
            // 亮度残差：n 序，cbp 位 = n>>2，cat2（16 系数含 DC）；跳过块清 nzCache（镜像解码器）
            for ($n = 0; $n < 16; $n++) {
                if (($cbpLuma & (1 << ($n >> 2))) !== 0) {
                    $this->encWriteAcBlock(2, $n, $quant4x4Luma[self::$N2R[$n]], false);
                } else {
                    $this->cabNzCache[self::$SCAN8[$n]] = 0;
                }
            }
            if ($cbpChroma > 0) {
                $this->encWriteChromaDc(0, $qCbDc);
                $this->encWriteChromaDc(1, $qCrDc);
            }
            if ($cbpChroma >= 2) {
                // 色度 AC：Cb×4 → Cr×4（zz15，15 系数）
                for ($b = 0; $b < 4; $b++) {
                    $this->encWriteAcBlock(4, 16 + $b, $quantCb4x4[$b], true);
                }
                for ($b = 0; $b < 4; $b++) {
                    $this->encWriteAcBlock(4, 32 + $b, $quantCr4x4[$b], true);
                }
            } else {
                // cbp<2 时清色度 AC nz，防陈旧值经 48-store 污染后续 MB 的 CBF 邻居（镜像 cabWriteChroma）
                foreach (self::$CELL_CB_ST as $cell) {
                    $this->cabNzCache[$cell] = 0;
                }
                foreach (self::$CELL_CR_ST as $cell) {
                    $this->cabNzCache[$cell] = 0;
                }
            }
            $modesN = [];
            for ($n = 0; $n < 16; $n++) {
                $modesN[$n] = $intra4x4PredModes[self::$N2R[$n]];
            }
            $this->encFinishI4($modesN);
        } else {
        // 1. mb_type = 0 for I_NxN
        $bits .= $this->ue(0);

        // 2. 编码intra4x4_pred_mode (16个块)
        $lumaAcScanOrder = [0, 1, 4, 5, 2, 3, 6, 7, 8, 9, 12, 13, 10, 11, 14, 15];
        $modeCache = array_fill(0, 16, -1);
        for ($scanIdx = 0; $scanIdx < 16; $scanIdx++) {
            $rasterIdx = $lumaAcScanOrder[$scanIdx];
            $bx = $rasterIdx % 4;
            $by = (int)($rasterIdx / 4);

            // 获取leftMode
            if ($bx > 0) {
                $leftMode = $modeCache[$rasterIdx - 1];
            } elseif ($leftAvailable && $leftIntra4x4Mode !== null) {
                $leftMode = $leftIntra4x4Mode[$by];
            } else {
                $leftMode = -1;
            }

            // 获取topMode
            if ($by > 0) {
                $topMode = $modeCache[$rasterIdx - 4];
            } elseif ($topAvailable && $topIntra4x4Mode !== null) {
                $absBlkX = $mbX * 4 + $bx;
                $topMode = ($absBlkX < count($topIntra4x4Mode)) ? $topIntra4x4Mode[$absBlkX] : -1;
            } else {
                $topMode = -1;
            }

            // 计算 MPM (Most Probable Mode)
            $predicted = ($leftMode < 0 || $topMode < 0) ? 2 : min($leftMode, $topMode);
            $mode = $intra4x4PredModes[$rasterIdx];

            // 编码模式
            if ($mode === $predicted) {
                $bits .= '1';  // prev_intra4x4_pred_mode_flag = 1
            } else {
                $bits .= '0';  // prev_intra4x4_pred_mode_flag = 0
                $remMode = ($mode > $predicted) ? $mode - 1 : $mode;
                $bits .= $this->u($remMode, 3);
            }

            $modeCache[$rasterIdx] = $mode;
        }

        // 更新邻居模式缓存
        if ($leftIntra4x4Mode !== null) {
            for ($by = 0; $by < 4; $by++) {
                $leftIntra4x4Mode[$by] = $modeCache[3 + $by * 4];
            }
        }
        if ($topIntra4x4Mode !== null) {
            for ($bx = 0; $bx < 4; $bx++) {
                $absBlkX = $mbX * 4 + $bx;
                if ($absBlkX < count($topIntra4x4Mode)) {
                    $topIntra4x4Mode[$absBlkX] = $modeCache[12 + $bx];
                }
            }
        }

        // 3. 编码intra_chroma_pred_mode
        $bits .= $this->ue($chromaPredMode);

        // 4. 编码CBP (coded_block_pattern)
        // I_4x4的CBP映射表 (codeNum -> cbp)，与解码器golombToIntraCbp完全一致
        $intra4x4CbpMap = [
            47, 31, 15, 0, 23, 27, 29, 30, 7, 11, 13, 14, 39, 43, 45, 46,
            16, 3, 5, 10, 12, 19, 21, 26, 28, 35, 37, 42, 44, 1, 2, 4, 8,
            17, 18, 20, 24, 6, 9, 22, 25, 32, 33, 34, 36, 40, 38, 41,
        ];
        $cbpValue = ($cbpChroma << 4) | $cbpLuma;
        $cbpCode = array_search($cbpValue, $intra4x4CbpMap);
        if ($cbpCode === false) $cbpCode = 0;
        $bits .= $this->ue($cbpCode);

        // 5. 编码mb_qp_delta (如果CBP > 0)
        if ($cbpValue > 0) {
            $bits .= $this->se(0);
        }

        for ($by = 0; $by < 4; $by++) {
            $leftNz[$by] = $nzCache[$by * 4 + 3];
        }
        for ($bx = 0; $bx < 4; $bx++) {
            $topBlkX = $mbX * 4 + $bx;
            if ($topBlkX < count($topNzLuma)) {
                $topNzLuma[$topBlkX] = $nzCache[$bx + 12];
            }
        }

        $nzCacheNew = array_fill(0, 24, 0);
        for ($i8x8 = 0; $i8x8 < 4; $i8x8++) {
            if ($cbpLuma & (1 << $i8x8)) {
                for ($i4x4 = 0; $i4x4 < 4; $i4x4++) {
                    $scanIdx = $i8x8 * 4 + $i4x4;
                    $rasterIdx = $lumaAcScanOrder[$scanIdx];
                    $by = (int)($rasterIdx / 4);
                    $bx = $rasterIdx % 4;
                    $acNc = $this->computeNC($rasterIdx, $mbX, $bx, $by, $leftAvailable, $leftNz, $topAvailable, $topNzLuma, $nzCacheNew);
                    $ac = $this->scan4x4DcAc($quant4x4Luma[$rasterIdx]);
                    $bits .= $this->writeBlockResidualCavlc($ac, 15, false, $acNc);
                    $nzCacheNew[$rasterIdx] = $nzCache[$rasterIdx];
                }
            } else {
                for ($i4x4 = 0; $i4x4 < 4; $i4x4++) {
                    $scanIdx = $i8x8 * 4 + $i4x4;
                    $rasterIdx = $lumaAcScanOrder[$scanIdx];
                    $nzCacheNew[$rasterIdx] = 0;
                }
            }
        }

        if ($cbpChroma > 0) {
            $bits .= $this->writeBlockResidualCavlc($qCbDc, 3, true, -1);
            $bits .= $this->writeBlockResidualCavlc($qCrDc, 3, true, -1);

            if ($cbpChroma === 2) {
                $cbScanOrder = [16, 17, 18, 19];
                foreach ($cbScanOrder as $blockIdx) {
                    $blk = $blockIdx - 16;
                    $by = (int)($blk / 2);
                    $bx = $blk % 2;
                    $acNc = $this->computeNC($blockIdx, $mbX, $bx, $by, $leftAvailable, $leftNz, $topAvailable, $topNzCb, $nzCacheNew);
                    $acCb = $this->scan4x4Ac($quantCb4x4[$blk]);
                    $bits .= $this->writeBlockResidualCavlc($acCb, 14, false, $acNc);
                    $nzCacheNew[$blockIdx] = $nzCache[$blockIdx];
                }
                $crScanOrder = [20, 21, 22, 23];
                foreach ($crScanOrder as $blockIdx) {
                    $blk = $blockIdx - 20;
                    $by = (int)($blk / 2);
                    $bx = $blk % 2;
                    $acNc = $this->computeNC($blockIdx, $mbX, $bx, $by, $leftAvailable, $leftNz, $topAvailable, $topNzCr, $nzCacheNew);
                    $acCr = $this->scan4x4Ac($quantCr4x4[$blk]);
                    $bits .= $this->writeBlockResidualCavlc($acCr, 14, false, $acNc);
                    $nzCacheNew[$blockIdx] = $nzCache[$blockIdx];
                }
            }

            for ($by = 0; $by < 2; $by++) {
                $cbBlk = $by * 2 + 1;
                $leftNz[4 + $by] = $nzCache[16 + $cbBlk];
                $leftNz[6 + $by] = $nzCache[20 + $cbBlk];
            }
            $topCbx0 = $mbX * 2 + 0;
            $topCbx1 = $mbX * 2 + 1;
            if ($topCbx1 < count($topNzCb)) {
                $topNzCb[$topCbx0] = $nzCache[18];
                $topNzCb[$topCbx1] = $nzCache[19];
                $topNzCr[$topCbx0] = $nzCache[22];
                $topNzCr[$topCbx1] = $nzCache[23];
            }
        } else {
            for ($by = 0; $by < 2; $by++) {
                $leftNz[4 + $by] = 0;
                $leftNz[6 + $by] = 0;
            }
            $topCbx0 = $mbX * 2 + 0;
            $topCbx1 = $mbX * 2 + 1;
            if ($topCbx1 < count($topNzCb)) {
                $topNzCb[$topCbx0] = 0;
                $topNzCb[$topCbx1] = 0;
                $topNzCr[$topCbx0] = 0;
                $topNzCr[$topCbx1] = 0;
            }
        }
        } // end CAVLC

        return $bits;
    }

    /**
     * Intra_8x8 帧内预测（编码端，镜像解码端 intra8x8Prediction）：
     * 邻居样值取自本机重建平面 reconYPlane（mbAlignedWidth 步长），
     * 先经 [1 2 1]/4 滤波（l0..l7/t0..t15/lt），再按模式生成 8x8 预测。
     * blkX/blkY ∈ {0,1}：8x8 块在宏块内的位置。
     */
    private function encIntra8x8Prediction(int $mbX, int $mbY, int $blkX, int $blkY, int $mode): array
    {
        if ($mode < 0 || $mode > 8) {
            $mode = 2;
        }
        $x0 = $mbX * 16 + $blkX * 8;
        $y0 = $mbY * 16 + $blkY * 8;
        $w = $this->width;
        $h = $this->height;
        $stride = $this->mbAlignedWidth;

        $topAvail = ($blkY > 0) || ($mbY > 0);
        $leftAvail = ($blkX > 0) || ($mbX > 0);
        // topRight：blkX=0 时与 top 同行（上邻底行右半或本 MB 块1底行，均已重建）；
        // blkX=1 且 blkY=0 时为右上邻 MB 底行（帧内需存在）；blkX=1 且 blkY=1 时为右邻（未编码）
        if ($blkX === 0) {
            $topRightAvail = $topAvail;
        } else {
            $topRightAvail = ($blkY === 0) && $mbY > 0 && ($mbX + 1 < $this->picWidthInMbs);
        }
        $topLeftAvail = $topAvail && $leftAvail;

        // 原始邻居样值（不可用时 128）
        $st = array_fill(0, 16, 128); // p[x,-1], x=0..15
        $sl = array_fill(0, 8, 128);  // p[-1,y], y=0..7
        $slt = 128;                   // p[-1,-1]
        if ($topAvail) {
            $ry = $y0 - 1;
            $lim = $topRightAvail ? 16 : 8;
            for ($x = 0; $x < $lim; $x++) {
                $px = $x0 + $x;
                if ($px < $w && $ry < $h) {
                    $st[$x] = ord($this->reconYPlane[$ry * $stride + $px]);
                }
            }
            if (!$topRightAvail) {
                for ($x = 8; $x < 16; $x++) {
                    $st[$x] = $st[7];
                }
            }
        }
        if ($leftAvail) {
            $rx = $x0 - 1;
            for ($y = 0; $y < 8; $y++) {
                $py = $y0 + $y;
                if ($py < $h && $rx < $w) {
                    $sl[$y] = ord($this->reconYPlane[$py * $stride + $rx]);
                }
            }
        }
        if ($topLeftAvail) {
            $slt = ord($this->reconYPlane[($y0 - 1) * $stride + ($x0 - 1)]);
        }

        // [1 2 1]/4 滤波（PREDICT_8x8_LOAD_*）
        $l = [];
        $l[0] = (($topLeftAvail ? $slt : $sl[0]) + 2 * $sl[0] + $sl[1] + 2) >> 2;
        for ($y = 1; $y <= 6; $y++) {
            $l[$y] = ($sl[$y - 1] + 2 * $sl[$y] + $sl[$y + 1] + 2) >> 2;
        }
        $l[7] = ($sl[6] + 3 * $sl[7] + 2) >> 2;

        $t = [];
        $t[0] = (($topLeftAvail ? $slt : $st[0]) + 2 * $st[0] + $st[1] + 2) >> 2;
        for ($x = 1; $x <= 6; $x++) {
            $t[$x] = ($st[$x - 1] + 2 * $st[$x] + $st[$x + 1] + 2) >> 2;
        }
        $t[7] = (($topRightAvail ? $st[8] : $st[7]) + 2 * $st[7] + $st[6] + 2) >> 2;
        if ($topRightAvail) {
            for ($x = 8; $x <= 14; $x++) {
                $t[$x] = ($st[$x - 1] + 2 * $st[$x] + $st[$x + 1] + 2) >> 2;
            }
            $t[15] = ($st[14] + 3 * $st[15] + 2) >> 2;
        } else {
            for ($x = 8; $x <= 15; $x++) {
                $t[$x] = $st[7];
            }
        }
        $lt = ($sl[0] + 2 * $slt + $st[0] + 2) >> 2;

        $avg2 = fn($a, $b) => ($a + $b + 1) >> 1;
        $avg3 = fn($a, $b, $c) => ($a + 2 * $b + $c + 2) >> 2;

        $p = array_fill(0, 8, array_fill(0, 8, 128));
        switch ($mode) {
            case 0: // Vertical
                for ($y = 0; $y < 8; $y++) {
                    for ($x = 0; $x < 8; $x++) {
                        $p[$y][$x] = $t[$x];
                    }
                }
                break;
            case 1: // Horizontal
                for ($y = 0; $y < 8; $y++) {
                    for ($x = 0; $x < 8; $x++) {
                        $p[$y][$x] = $l[$y];
                    }
                }
                break;
            case 2: // DC（四变体）
                if ($topAvail && $leftAvail) {
                    $s = 8;
                    for ($i = 0; $i < 8; $i++) {
                        $s += $l[$i] + $t[$i];
                    }
                    $dc = $s >> 4;
                } elseif ($leftAvail) {
                    $dc = ($l[0] + $l[1] + $l[2] + $l[3] + $l[4] + $l[5] + $l[6] + $l[7] + 4) >> 3;
                } elseif ($topAvail) {
                    $dc = ($t[0] + $t[1] + $t[2] + $t[3] + $t[4] + $t[5] + $t[6] + $t[7] + 4) >> 3;
                } else {
                    $dc = 128;
                }
                for ($y = 0; $y < 8; $y++) {
                    for ($x = 0; $x < 8; $x++) {
                        $p[$y][$x] = $dc;
                    }
                }
                break;
            case 3: // Diagonal Down-Left
                for ($y = 0; $y < 8; $y++) {
                    for ($x = 0; $x < 8; $x++) {
                        $k = $x + $y;
                        if ($k < 14) {
                            $p[$y][$x] = $avg3($t[$k], $t[$k + 1], $t[$k + 2]);
                        } else {
                            $p[$y][$x] = $avg3($t[14], $t[15], $t[15]);
                        }
                    }
                }
                break;
            case 4: // Diagonal Down-Right（等值线 d=x-y）
                for ($y = 0; $y < 8; $y++) {
                    for ($x = 0; $x < 8; $x++) {
                        $d = $x - $y;
                        if ($d > 0) {
                            $p[$y][$x] = $d === 1
                                ? $avg3($lt, $t[0], $t[1])
                                : $avg3($t[$d - 2], $t[$d - 1], $t[$d]);
                        } elseif ($d < 0) {
                            $k = -$d;
                            $p[$y][$x] = $k === 1
                                ? $avg3($l[1], $l[0], $lt)
                                : $avg3($l[$k], $l[$k - 1], $l[$k - 2]);
                        } else {
                            $p[$y][$x] = $avg3($l[0], $lt, $t[0]);
                        }
                    }
                }
                break;
            case 5: // Vertical-Right（等值线 zVR=2x-y）
                for ($y = 0; $y < 8; $y++) {
                    for ($x = 0; $x < 8; $x++) {
                        $zVR = 2 * $x - $y;
                        if ($zVR >= 0) {
                            if (($zVR & 1) === 0) {
                                $k = $zVR >> 1;
                                $p[$y][$x] = $k === 0 ? $avg2($lt, $t[0]) : $avg2($t[$k - 1], $t[$k]);
                            } else {
                                $k = $zVR >> 1;
                                $p[$y][$x] = $k === 0
                                    ? $avg3($lt, $t[0], $t[1])
                                    : $avg3($t[$k - 1], $t[$k], $t[$k + 1]);
                            }
                        } elseif ($zVR === -1) {
                            $p[$y][$x] = $avg3($l[0], $lt, $t[0]);
                        } else {
                            $j = -$zVR - 2;
                            $p[$y][$x] = $j === 0
                                ? $avg3($l[1], $l[0], $lt)
                                : $avg3($l[$j + 1], $l[$j], $l[$j - 1]);
                        }
                    }
                }
                break;
            case 6: // Horizontal-Down（等值线 zHD=2y-x）
                for ($y = 0; $y < 8; $y++) {
                    for ($x = 0; $x < 8; $x++) {
                        $zHD = 2 * $y - $x;
                        if ($zHD >= 0) {
                            if (($zHD & 1) === 0) {
                                $k = $zHD >> 1;
                                $p[$y][$x] = $k === 0 ? $avg2($lt, $l[0]) : $avg2($l[$k - 1], $l[$k]);
                            } else {
                                $k = $zHD >> 1;
                                $p[$y][$x] = $k === 0
                                    ? $avg3($lt, $l[0], $l[1])
                                    : $avg3($l[$k - 1], $l[$k], $l[$k + 1]);
                            }
                        } elseif ($zHD === -1) {
                            $p[$y][$x] = $avg3($l[0], $lt, $t[0]);
                        } else {
                            $j = -$zHD - 2;
                            $p[$y][$x] = $j === 0
                                ? $avg3($lt, $t[0], $t[1])
                                : $avg3($t[$j + 1], $t[$j], $t[$j - 1]);
                        }
                    }
                }
                break;
            case 7: // Vertical-Left（等值线 zVL=2x+y）
                for ($y = 0; $y < 8; $y++) {
                    for ($x = 0; $x < 8; $x++) {
                        $zVL = 2 * $x + $y;
                        $k = $zVL >> 1;
                        $p[$y][$x] = ($zVL & 1) === 0
                            ? $avg2($t[$k], $t[$k + 1])
                            : $avg3($t[$k], $t[$k + 1], $t[$k + 2]);
                    }
                }
                break;
            case 8: // Horizontal-Up（等值线 zHU=x+2y）
                for ($y = 0; $y < 8; $y++) {
                    for ($x = 0; $x < 8; $x++) {
                        $zHU = $x + 2 * $y;
                        if ($zHU < 13) {
                            $k = $zHU >> 1;
                            $p[$y][$x] = ($zHU & 1) === 0
                                ? $avg2($l[$k], $l[$k + 1])
                                : $avg3($l[$k], $l[$k + 1], $l[$k + 2]);
                        } elseif ($zHU === 13) {
                            $p[$y][$x] = ($l[6] + 3 * $l[7] + 2) >> 2;
                        } else {
                            $p[$y][$x] = $l[7];
                        }
                    }
                }
                break;
        }
        return $p;
    }

    /**
     * Intra_8x8 宏块编码（high profile I 片恒用，无宏块级选型）。
     * 语法序镜像解码端 cabDecodeI4x4 的 dct8 分支：
     * mb_type → transform_size_8x8_flag(=1, ctx 399+n) → 4 模式（2x2 cache 填充）→
     * chroma pred → CBP → qp delta → 逐 p8 亮度残差（cat5）→ 色度 DC/AC。
     * 重建镜像解码端：逐 8x8 串行"预测→残差→量化→反量化→逆变换→写重建"，
     * 后续 8x8 块的预测依赖已重建邻居（与解码器逐比特一致）。
     */
    public function encodeMacroblockI8x8(
        $mbX, $mbY, $yPlane, $uPlane, $vPlane,
        $leftAvailable, &$leftNz, $topAvailable,
        &$topNzLuma, &$topNzCb, &$topNzCr
    ): string {
        $reconStride = $this->mbAlignedWidth;

        // ---- 原始 16x16 亮度像素 ----
        $lumaPixels = array_fill(0, 16, array_fill(0, 16, 128));
        for ($y = 0; $y < 16; $y++) {
            $py = $mbY * 16 + $y;
            for ($x = 0; $x < 16; $x++) {
                $lumaPixels[$y][$x] = ord($yPlane[$py * $reconStride + ($mbX * 16 + $x)]);
            }
        }

        // ---- 8x8 变换/量化表（帧内 ff=2）----
        [$qmul8, $mf8] = $this->buildQuant8Tables($this->qp, true);
        $zz8 = self::$ZZ8;
        $scanJ = [];
        for ($k = 0; $k < 64; $k++) {
            $scanJ[$k] = ($zz8[$k] >> 3) | (($zz8[$k] & 7) << 3);
        }

        // ---- Phase A：逐 8x8 串行（模式决策 + 量化 + 重建，p8=TL,TR,BL,BR）----
        $modes = [];
        $levels = [];
        $cbpLuma = 0;
        for ($p8 = 0; $p8 < 4; $p8++) {
            $bx = $p8 & 1;
            $by = $p8 >> 1;
            $topAvail = ($by > 0) || ($mbY > 0);
            $leftAvail = ($bx > 0) || ($mbX > 0);

            // 模式决策：SSE 最小（9 模式全评——8x8 块作为 I 帧参考，剪到 4 模式
            // 会使重建明显劣化并放大后续 P/B 帧残差，实测得不偿失）
            // 合法性对照 ffmpeg ff_h264_check_intra4x4_pred_mode 的 top[]/left[] 表
            // （需要 top：{0,3,4,5,6,7}；需要 left：{1,4,5,6,8}），非法组合解码器直接报错
            $bestMode = 2;
            $bestCost = PHP_INT_MAX;
            for ($m = 0; $m <= 8; $m++) {
                if (!$topAvail && ($m === 0 || $m === 3 || $m === 4 || $m === 5 || $m === 6 || $m === 7)) {
                    continue;
                }
                if (!$leftAvail && ($m === 1 || $m === 4 || $m === 5 || $m === 6 || $m === 8)) {
                    continue;
                }
                $pred = $this->encIntra8x8Prediction($mbX, $mbY, $bx, $by, $m);
                $cost = 0;
                for ($yy = 0; $yy < 8; $yy++) {
                    $oy = $by * 8 + $yy;
                    for ($xx = 0; $xx < 8; $xx++) {
                        $d = $lumaPixels[$oy][$bx * 8 + $xx] - $pred[$yy][$xx];
                        $cost += $d * $d;
                    }
                }
                if ($cost < $bestCost) {
                    $bestCost = $cost;
                    $bestMode = $m;
                }
            }
            $modes[$p8] = $bestMode;

            // 残差 → 正向变换 → 量化（存储布局）
            $pred = $this->encIntra8x8Prediction($mbX, $mbY, $bx, $by, $bestMode);
            $res = array_fill(0, 64, 0);
            for ($yy = 0; $yy < 8; $yy++) {
                $oy = $by * 8 + $yy;
                for ($xx = 0; $xx < 8; $xx++) {
                    $res[$yy * 8 + $xx] = $lumaPixels[$oy][$bx * 8 + $xx] - $pred[$yy][$xx];
                }
            }
            $c = $this->forward8x8($res);
            $nz = $this->quantize8($c, $mf8, 2);

            // 扫描序 levels（k -> 存储位 j），供 cat5 语法写入
            $lv = [];
            for ($k = 0; $k < 64; $k++) {
                $v = $c[$scanJ[$k]];
                if ($v !== 0) {
                    $lv[$k] = $v;
                }
            }
            $levels[$p8] = $lv;
            if ($nz > 0) {
                $cbpLuma |= 1 << $p8;
                // 反量化 + 逆变换 → 残差像素（行主序）
                $this->dequantize8($c, $qmul8);
                $resid = $this->inverse8x8($c);
            } else {
                $resid = array_fill(0, 64, 0);
            }

            // 重建写入 reconYPlane（镜像解码端：先写 pred 再叠 idct8 残差，逐像素 clamp）
            for ($yy = 0; $yy < 8; $yy++) {
                $py = $mbY * 16 + $by * 8 + $yy;
                if ($py >= $this->height) {
                    break;
                }
                $rowOff = $py * $reconStride;
                $oy = $by * 8 + $yy;
                for ($xx = 0; $xx < 8; $xx++) {
                    $px = $mbX * 16 + $bx * 8 + $xx;
                    if ($px >= $this->width) {
                        break;
                    }
                    // resid 是 8x8 块级 64 元素数组，用块内行号 yy 索引（oy 是宏块级行号）
                    $v = $pred[$yy][$xx] + $resid[$yy * 8 + $xx];
                    $this->reconYPlane[$rowOff + $px] = chr(max(0, min(255, $v)));
                }
            }
        }

        // ---- 色度（DC 预测 + 4x4 变换量化，与 I4x4 路径一致）----
        $u8x8 = array_fill(0, 8, array_fill(0, 8, 128));
        $v8x8 = array_fill(0, 8, array_fill(0, 8, 128));
        $chromaQpIndex = max(0, min(51, $this->qp + $this->chromaQpIndexOffset));
        $chromaQp = self::CHROMA_QP_TABLE[$chromaQpIndex];
        $quantCb4x4 = array_fill(0, 4, array_fill(0, 16, 0));
        $quantCr4x4 = array_fill(0, 4, array_fill(0, 16, 0));
        $dcCb2x2 = [0, 0, 0, 0];
        $dcCr2x2 = [0, 0, 0, 0];

        $chromaStride = (int)($reconStride / 2);
        for ($y = 0; $y < 8; $y++) {
            $py = $mbY * 8 + $y;
            for ($x = 0; $x < 8; $x++) {
                $px = $mbX * 8 + $x;
                $idx = $py * $chromaStride + $px;
                $u8x8[$y][$x] = ord($uPlane[$idx]);
                $v8x8[$y][$x] = ord($vPlane[$idx]);
            }
        }

        $chromaLeftU = array_fill(0, 8, 128);
        $chromaTopU = array_fill(0, 8, 128);
        $chromaLeftV = array_fill(0, 8, 128);
        $chromaTopV = array_fill(0, 8, 128);
        $reconChromaStride = $chromaStride;
        if ($leftAvailable) {
            $refX = $mbX * 8 - 1;
            for ($y = 0; $y < 8; $y++) {
                $py = $mbY * 8 + $y;
                $idx = $py * $reconChromaStride + $refX;
                $chromaLeftU[$y] = ord($this->reconUPlane[$idx]);
                $chromaLeftV[$y] = ord($this->reconVPlane[$idx]);
            }
        }
        if ($topAvailable) {
            $refY = $mbY * 8 - 1;
            for ($x = 0; $x < 8; $x++) {
                $px = $mbX * 8 + $x;
                $idx = $refY * $reconChromaStride + $px;
                $chromaTopU[$x] = ord($this->reconUPlane[$idx]);
                $chromaTopV[$x] = ord($this->reconVPlane[$idx]);
            }
        }

        $chromaPredMode = 0;
        $chromaPredU = array_fill(0, 8, array_fill(0, 8, 128));
        $chromaPredV = array_fill(0, 8, array_fill(0, 8, 128));
        $hasTop = $topAvailable;
        $hasLeft = $leftAvailable;

        // DC 预测（四分区）
        if ($hasTop && $hasLeft) {
            $dc0U = 0; $dc1U = 0; $dc2U = 0;
            $dc0V = 0; $dc1V = 0; $dc2V = 0;
            for ($i = 0; $i < 4; $i++) {
                $dc0U += $chromaTopU[$i] + $chromaLeftU[$i];
                $dc1U += $chromaTopU[4 + $i];
                $dc2U += $chromaLeftU[4 + $i];
                $dc0V += $chromaTopV[$i] + $chromaLeftV[$i];
                $dc1V += $chromaTopV[4 + $i];
                $dc2V += $chromaLeftV[4 + $i];
            }
            $dc0ValU = ($dc0U + 4) >> 3;
            $dc1ValU = ($dc1U + 2) >> 2;
            $dc2ValU = ($dc2U + 2) >> 2;
            $dc3ValU = ($dc1U + $dc2U + 4) >> 3;
            $dc0ValV = ($dc0V + 4) >> 3;
            $dc1ValV = ($dc1V + 2) >> 2;
            $dc2ValV = ($dc2V + 2) >> 2;
            $dc3ValV = ($dc1V + $dc2V + 4) >> 3;
        } elseif (!$hasTop && $hasLeft) {
            $dc0U = 0; $dc2U = 0;
            $dc0V = 0; $dc2V = 0;
            for ($i = 0; $i < 4; $i++) {
                $dc0U += $chromaLeftU[$i];
                $dc2U += $chromaLeftU[4 + $i];
                $dc0V += $chromaLeftV[$i];
                $dc2V += $chromaLeftV[4 + $i];
            }
            $dc0ValU = ($dc0U + 2) >> 2;
            $dc1ValU = $dc0ValU;
            $dc2ValU = ($dc2U + 2) >> 2;
            $dc3ValU = $dc2ValU;
            $dc0ValV = ($dc0V + 2) >> 2;
            $dc1ValV = $dc0ValV;
            $dc2ValV = ($dc2V + 2) >> 2;
            $dc3ValV = $dc2ValV;
        } elseif ($hasTop && !$hasLeft) {
            $dc0U = 0; $dc1U = 0;
            $dc0V = 0; $dc1V = 0;
            for ($i = 0; $i < 4; $i++) {
                $dc0U += $chromaTopU[$i];
                $dc1U += $chromaTopU[4 + $i];
                $dc0V += $chromaTopV[$i];
                $dc1V += $chromaTopV[4 + $i];
            }
            $dc0ValU = ($dc0U + 2) >> 2;
            $dc1ValU = ($dc1U + 2) >> 2;
            $dc2ValU = $dc0ValU;
            $dc3ValU = $dc1ValU;
            $dc0ValV = ($dc0V + 2) >> 2;
            $dc1ValV = ($dc1V + 2) >> 2;
            $dc2ValV = $dc0ValV;
            $dc3ValV = $dc1ValV;
        } else {
            $dc0ValU = $dc1ValU = $dc2ValU = $dc3ValU = 128;
            $dc0ValV = $dc1ValV = $dc2ValV = $dc3ValV = 128;
        }
        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                $chromaPredU[$y][$x] = $dc0ValU;
                $chromaPredV[$y][$x] = $dc0ValV;
            }
            for ($x = 4; $x < 8; $x++) {
                $chromaPredU[$y][$x] = $dc1ValU;
                $chromaPredV[$y][$x] = $dc1ValV;
            }
        }
        for ($y = 4; $y < 8; $y++) {
            for ($x = 0; $x < 4; $x++) {
                $chromaPredU[$y][$x] = $dc2ValU;
                $chromaPredV[$y][$x] = $dc2ValV;
            }
            for ($x = 4; $x < 8; $x++) {
                $chromaPredU[$y][$x] = $dc3ValU;
                $chromaPredV[$y][$x] = $dc3ValV;
            }
        }

        // 色度 4x4 残差量化（帧内 FF）
        for ($by = 0; $by < 2; $by++) {
            for ($bx = 0; $bx < 2; $bx++) {
                $blkIdx = $by * 2 + $bx;
                $blkU = array_fill(0, 4, array_fill(0, 4, 0));
                $blkV = array_fill(0, 4, array_fill(0, 4, 0));
                for ($y = 0; $y < 4; $y++) {
                    for ($x = 0; $x < 4; $x++) {
                        $py = $by * 4 + $y;
                        $px = $bx * 4 + $x;
                        $blkU[$y][$x] = $u8x8[$py][$px] - $chromaPredU[$py][$px];
                        $blkV[$y][$x] = $v8x8[$py][$px] - $chromaPredV[$py][$px];
                    }
                }
                $dctU = $this->dct($blkU);
                $dctV = $this->dct($blkV);
                $dcCb2x2[$blkIdx] = $dctU[0][0];
                $dcCr2x2[$blkIdx] = $dctV[0][0];
                $qU = $this->quantizeChroma($dctU, $chromaQp);
                $qV = $this->quantizeChroma($dctV, $chromaQp);
                for ($yy = 0; $yy < 4; $yy++) {
                    for ($xx = 0; $xx < 4; $xx++) {
                        $quantCb4x4[$blkIdx][$yy * 4 + $xx] = $qU[$yy][$xx];
                        $quantCr4x4[$blkIdx][$yy * 4 + $xx] = $qV[$yy][$xx];
                    }
                }
            }
        }
        $hadCb = $this->forwardChromaHadamard2x2($dcCb2x2);
        $hadCr = $this->forwardChromaHadamard2x2($dcCr2x2);
        $qCbDc = $this->quantizeChromaDC($hadCb, $chromaQp);
        $qCrDc = $this->quantizeChromaDC($hadCr, $chromaQp);

        $hasChromaDc = false;
        for ($i = 0; $i < 4; $i++) {
            if ($qCbDc[$i] !== 0 || $qCrDc[$i] !== 0) {
                $hasChromaDc = true;
                break;
            }
        }
        $hasChromaAc = false;
        for ($b = 0; $b < 4 && !$hasChromaAc; $b++) {
            for ($i = 1; $i < 16; $i++) {
                if ($quantCb4x4[$b][$i] !== 0 || $quantCr4x4[$b][$i] !== 0) {
                    $hasChromaAc = true;
                    break;
                }
            }
        }
        $cbpChroma = 0;
        if ($hasChromaDc) {
            $cbpChroma = $hasChromaAc ? 2 : 1;
        }

        // ---- 色度本地解码重建（更新色度参考帧，与 I16x16 路径一致）----
        $chromaW = $chromaStride;
        $cbQmul = $this->dequant4Table[1][$chromaQp][0];
        $crQmul = $this->dequant4Table[2][$chromaQp][0];
        $cbDcResult = $this->chromaDcDequantIdct($qCbDc, $cbQmul);
        $crDcResult = $this->chromaDcDequantIdct($qCrDc, $crQmul);
        for ($by = 0; $by < 2; $by++) {
            for ($bx = 0; $bx < 2; $bx++) {
                $blk = $by * 2 + $bx;
                $cbDcResidual = $cbDcResult[$blk];
                $crDcResidual = $crDcResult[$blk];
                if ($cbpChroma >= 2) {
                    $cbAc = $quantCb4x4[$blk];
                    $crAc = $quantCr4x4[$blk];
                    $this->dequantize4x4($cbAc, 1, $chromaQp);
                    $this->dequantize4x4($crAc, 2, $chromaQp);
                    $cbAc[0] = $cbDcResidual;
                    $crAc[0] = $crDcResidual;
                    $this->idctFlat($cbAc);
                    $this->idctFlat($crAc);
                    for ($y = 0; $y < 4; $y++) {
                        for ($x = 0; $x < 4; $x++) {
                            $py = $mbY * 8 + $by * 4 + $y;
                            $px = $mbX * 8 + $bx * 4 + $x;
                            $vu = $chromaPredU[$by * 4 + $y][$bx * 4 + $x] + $cbAc[$y * 4 + $x];
                            $vv = $chromaPredV[$by * 4 + $y][$bx * 4 + $x] + $crAc[$y * 4 + $x];
                            $idx = $py * $chromaW + $px;
                            $this->reconUPlane[$idx] = chr(max(0, min(255, $vu)));
                            $this->reconVPlane[$idx] = chr(max(0, min(255, $vv)));
                        }
                    }
                } elseif ($cbpChroma == 1) {
                    $cbDcAdd = ($cbDcResidual + 32) >> 6;
                    $crDcAdd = ($crDcResidual + 32) >> 6;
                    for ($y = 0; $y < 4; $y++) {
                        for ($x = 0; $x < 4; $x++) {
                            $py = $mbY * 8 + $by * 4 + $y;
                            $px = $mbX * 8 + $bx * 4 + $x;
                            $vu = $chromaPredU[$by * 4 + $y][$bx * 4 + $x] + $cbDcAdd;
                            $vv = $chromaPredV[$by * 4 + $y][$bx * 4 + $x] + $crDcAdd;
                            $idx = $py * $chromaW + $px;
                            $this->reconUPlane[$idx] = chr(max(0, min(255, $vu)));
                            $this->reconVPlane[$idx] = chr(max(0, min(255, $vv)));
                        }
                    }
                } else {
                    for ($y = 0; $y < 4; $y++) {
                        for ($x = 0; $x < 4; $x++) {
                            $py = $mbY * 8 + $by * 4 + $y;
                            $px = $mbX * 8 + $bx * 4 + $x;
                            $idx = $py * $chromaW + $px;
                            $this->reconUPlane[$idx] = chr(max(0, min(255, $chromaPredU[$by * 4 + $y][$bx * 4 + $x])));
                            $this->reconVPlane[$idx] = chr(max(0, min(255, $chromaPredV[$by * 4 + $y][$bx * 4 + $x])));
                        }
                    }
                }
            }
        }

        // ---- Phase B：CABAC 语法写入（镜像解码端 dct8 分支语法序）----
        $this->cabMbBegin($mbX, $mbY);
        $this->encWriteIntraMbTypeI(false, 0, 0, 0);
        $this->cabFixUnavailNz(true);
        // transform_size_8x8_flag = 1（I 片在所有 pred 模式之前读）
        $this->encDecision(399 + $this->cabNeighborTransformSize(), 1);
        // 4 个 8x8 分组模式（n=0,4,8,12，2x2 cache 填充）
        for ($p8 = 0; $p8 < 4; $p8++) {
            $this->encWriteI4Mode(4 * $p8, $modes[$p8], true);
        }
        $this->encWriteChromaPred($chromaPredMode);
        $cbp = ($cbpChroma << 4) | $cbpLuma;
        $this->encWriteCbpLuma($cbpLuma);
        $this->encWriteCbpChroma($cbpChroma);
        $this->cabCbp = $cbp;
        $this->picCbp[$this->cabMbIdx] = $cbp;
        if ($cbp !== 0) {
            $this->encWriteQpDelta(0);
        } else {
            $this->cabLastQpDiff = 0;
        }
        // 亮度残差：逐 p8（cat5，无逐块 CBF）；跳过块清 nzCache 2x2（镜像解码器）
        for ($p8 = 0; $p8 < 4; $p8++) {
            if (($cbpLuma & (1 << $p8)) !== 0) {
                $this->encWriteResidual8x8($p8, $levels[$p8]);
            } else {
                $cell = self::$SCAN8[4 * $p8];
                $this->cabNzCache[$cell] = 0;
                $this->cabNzCache[$cell + 1] = 0;
                $this->cabNzCache[$cell + 8] = 0;
                $this->cabNzCache[$cell + 9] = 0;
            }
        }
        // 色度 DC/AC（与 I4x4 路径一致）
        if ($cbpChroma > 0) {
            $this->encWriteChromaDc(0, $qCbDc);
            $this->encWriteChromaDc(1, $qCrDc);
        }
        if ($cbpChroma >= 2) {
            for ($b = 0; $b < 4; $b++) {
                $this->encWriteAcBlock(4, 16 + $b, $quantCb4x4[$b], true);
            }
            for ($b = 0; $b < 4; $b++) {
                $this->encWriteAcBlock(4, 32 + $b, $quantCr4x4[$b], true);
            }
        } else {
            foreach (self::$CELL_CB_ST as $cell) {
                $this->cabNzCache[$cell] = 0;
            }
            foreach (self::$CELL_CR_ST as $cell) {
                $this->cabNzCache[$cell] = 0;
            }
        }
        // 收尾：modesN 按 n 序展开（2x2 同值），置 CAB_FLG_I4 | CAB_FLG_DCT8
        $modesN = [];
        for ($p8 = 0; $p8 < 4; $p8++) {
            for ($i = 0; $i < 4; $i++) {
                $modesN[4 * $p8 + $i] = $modes[$p8];
            }
        }
        $this->encFinishI4($modesN, true);

        return '';
    }

}
