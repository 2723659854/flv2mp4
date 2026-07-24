<?php

namespace Xiaosongshu\Flv2mp4\Codec\Encoder;

/**
 * @purpose 帧内编码 I桢
 * @author yanglong
 */
trait IntraEncodingTrait
{
    public function encodeMacroblock(
            $mbX, $mbY, $yPlane, $uPlane, $vPlane,
            $leftAvailable, &$leftNz, $topAvailable,
            &$topNzLuma, &$topNzCb, &$topNzCr,
            &$leftIntra4x4Mode = null, &$topIntra4x4Mode = null
        )
        {
            if ($this->mbType === self::MB_TYPE_I16x16) {
                return $this->encodeMacroblockI16x16($mbX, $mbY, $yPlane, $uPlane, $vPlane, $leftAvailable, $leftNz, $topAvailable, $topNzLuma, $topNzCb, $topNzCr);
            } else {
                return $this->encodeMacroblockI4x4($mbX, $mbY, $yPlane, $uPlane, $vPlane, $leftAvailable, $leftNz, $topAvailable, $topNzLuma, $topNzCb, $topNzCr, $leftIntra4x4Mode, $topIntra4x4Mode);
            }
        }

    public function encodeMacroblockI16x16(
            $mbX, $mbY, $yPlane, $uPlane, $vPlane,
            $leftAvailable, &$leftNz, $topAvailable,
            &$topNzLuma, &$topNzCb, &$topNzCr
        )
        {
            $bits = '';
            $chromaWidth = (int)($this->width / 2);
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
    
            $lumaPredMode = 2;
    
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
                        $cbpLuma |= 1 << (int)($blkIdx / 4);
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
    
            $u8x8 = array_fill(0, 8, array_fill(0, 8, 128));
            $v8x8 = array_fill(0, 8, array_fill(0, 8, 128));
            $chromaQpIndex = max(0, min(51, $this->qp + $this->chromaQpIndexOffset));
            $chromaQp = self::CHROMA_QP_TABLE[$chromaQpIndex];
            $hasChromaAc = false;
            $quantCb4x4 = array_fill(0, 4, array_fill(0, 16, 0));
            $quantCr4x4 = array_fill(0, 4, array_fill(0, 16, 0));
            $dcCb2x2 = [0, 0, 0, 0];
            $dcCr2x2 = [0, 0, 0, 0];
    
            $chromaHeight = (int)($this->height / 2);
            for ($y = 0; $y < 8; $y++) {
                $py = $mbY * 8 + $y;
                if ($py >= $chromaHeight) break;
                for ($x = 0; $x < 8; $x++) {
                    $px = $mbX * 8 + $x;
                    if ($px >= $chromaWidth) break;
                    $idx = $py * $chromaWidth + $px;
                    $u8x8[$y][$x] = ord($uPlane[$idx]);
                    $v8x8[$y][$x] = ord($vPlane[$idx]);
                }
            }
    
            $chromaLeftU = array_fill(0, 8, 128);
            $chromaTopU = array_fill(0, 8, 128);
            $chromaLeftV = array_fill(0, 8, 128);
            $chromaTopV = array_fill(0, 8, 128);
            $chromaLeftSumU = 0;
            $chromaTopSumU = 0;
            $chromaLeftSumV = 0;
            $chromaTopSumV = 0;
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
                    $chromaLeftSumU += $chromaLeftU[$y];
                    $chromaLeftSumV += $chromaLeftV[$y];
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
                    $chromaTopSumU += $chromaTopU[$x];
                    $chromaTopSumV += $chromaTopV[$x];
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
    
            $mbTypeValue = 1 + $i16Mode + ($cbpChroma << 2) + ($cbpLuma == 0 ? 0 : 12);
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
    
            for ($by = 0; $by < 4; $by++) $leftNz[$by] = $nzCache[$by * 4 + 3];
            for ($bx = 0; $bx < 4; $bx++) {
                $topBlkX = $mbX * 4 + $bx;
                if ($topBlkX < count($topNzLuma)) $topNzLuma[$topBlkX] = $nzCache[$bx + 12];
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
            }
    
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
                        // AC存在: 反量化AC, 将DC放入[0], 一起做IDCT
                        $acDequant = $this->dequantize4x4($quant4x4Luma[$rasterIdx], 0, $this->qp);
                        $acDequant[0] = $dcResidual;
                        $acBlock = array_fill(0, 4, array_fill(0, 4, 0));
                        for ($y = 0; $y < 4; $y++) for ($x = 0; $x < 4; $x++) $acBlock[$y][$x] = $acDequant[$y * 4 + $x];
                        $idctResult = $this->idct4x4($acBlock);
    
                        for ($y = 0; $y < 4; $y++) {
                            for ($x = 0; $x < 4; $x++) {
                                $py = $mbY * 16 + $by * 4 + $y;
                                $px = $mbX * 16 + $bx * 4 + $x;
                                $val = $predPixels[$by * 4 + $y][$bx * 4 + $x] + $idctResult[$y][$x];
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
            $chromaH = intdiv($this->mbAlignedHeight, 2);
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
                        // AC存在: DC放入[0], 一起IDCT
                        $cbAcDequant = $this->dequantize4x4($quantCb4x4[$blk], 1, $chromaQp);
                        $crAcDequant = $this->dequantize4x4($quantCr4x4[$blk], 2, $chromaQp);
                        $cbAcDequant[0] = $cbDcResidual;
                        $crAcDequant[0] = $crDcResidual;
                        $cbBlock = array_fill(0, 4, array_fill(0, 4, 0));
                        $crBlock = array_fill(0, 4, array_fill(0, 4, 0));
                        for ($y = 0; $y < 4; $y++) for ($x = 0; $x < 4; $x++) {
                            $cbBlock[$y][$x] = $cbAcDequant[$y * 4 + $x];
                            $crBlock[$y][$x] = $crAcDequant[$y * 4 + $x];
                        }
                        $cbIdct = $this->idct4x4($cbBlock);
                        $crIdct = $this->idct4x4($crBlock);
    
                        for ($y = 0; $y < 4; $y++) {
                            for ($x = 0; $x < 4; $x++) {
                                $py = $mbY * 8 + $by * 4 + $y;
                                $px = $mbX * 8 + $bx * 4 + $x;
                                $vu = $chromaPredU[$by * 4 + $y][$bx * 4 + $x] + $cbIdct[$y][$x];
                                $vv = $chromaPredV[$by * 4 + $y][$bx * 4 + $x] + $crIdct[$y][$x];
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
            $chromaWidth = (int)($this->width / 2);
            $chromaHeight = (int)($this->height / 2);
    
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
    
            $lumaAcScanOrder = [0, 1, 4, 5, 2, 3, 6, 7, 8, 9, 12, 13, 10, 11, 14, 15];
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
                        $cbpLuma |= 1 << (int)($blkIdx / 4);
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
    
            for ($y = 0; $y < 8; $y++) {
                $py = $mbY * 8 + $y;
                if ($py >= $chromaHeight) break;
                for ($x = 0; $x < 8; $x++) {
                    $px = $mbX * 8 + $x;
                    if ($px >= $chromaWidth) break;
                    $idx = $py * $chromaWidth + $px;
                    $u8x8[$y][$x] = ord($uPlane[$idx]);
                    $v8x8[$y][$x] = ord($vPlane[$idx]);
                }
            }
    
            $chromaLeftU = array_fill(0, 8, 128);
            $chromaTopU = array_fill(0, 8, 128);
            $chromaLeftV = array_fill(0, 8, 128);
            $chromaTopV = array_fill(0, 8, 128);
            $chromaLeftSumU = 0;
            $chromaTopSumU = 0;
            $chromaLeftSumV = 0;
            $chromaTopSumV = 0;
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
                    $chromaLeftSumU += $chromaLeftU[$y];
                    $chromaLeftSumV += $chromaLeftV[$y];
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
                    $chromaTopSumU += $chromaTopU[$x];
                    $chromaTopSumV += $chromaTopV[$x];
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
    
                // 计算MPM (Most Probable Mode) - 参考openh264 PredIntra4x4Mode
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
            if ($cbpLuma > 0) {
                foreach ($lumaAcScanOrder as $rasterIdx) {
                    $by = (int)($rasterIdx / 4);
                    $bx = $rasterIdx % 4;
                    $acNc = $this->computeNC($rasterIdx, $mbX, $bx, $by, $leftAvailable, $leftNz, $topAvailable, $topNzLuma, $nzCacheNew);
                    $ac = $this->scan4x4DcAc($quant4x4Luma[$rasterIdx]);
                    $bits .= $this->writeBlockResidualCavlc($ac, 15, false, $acNc);
                    $nzCacheNew[$rasterIdx] = $nzCache[$rasterIdx];
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
            }
    
            return $bits;
        }
}
