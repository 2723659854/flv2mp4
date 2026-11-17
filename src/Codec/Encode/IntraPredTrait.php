<?php

namespace Xiaosongshu\Flv2mp4\Codec\Encode;
// =============================================================================
// 帧内预测与宏块编码 trait（H.264/AVC）
// 覆盖以下内容：
//   1) I_16x16 宏块：整宏块使用 4 种预测模式（H.264 8.3.3.2），亮度 DC 系数额外做 4x4 Hadamard；
//   2) I_4x4 宏块：16 个 4x4 块各自从 9 种模式中选优（8.3.3.1），DC 不做 Hadamard；
//   3) I_8x8 宏块（high profile）：transform_size_8x8_flag=1，整 8x8 正向变换；
//   4) 宏块层语法写入（7.4.5）：mb_type / 预测模式 / CBP / mb_qp_delta / 残差块，
//      分为 CABAC（9.3）与 CAVLC（9.2）两条互斥路径，由 useCabac 开关选择；
//   5) 本地重建：编码端必须复现解码端像素（预测+反量化+逆变换），供后续宏块做
//      帧内/帧间预测参考；一旦重建与解码端不一致，后续 MV 参考和残差都会漂移。
// 所有平面（源图与重建图）均按宏块对齐步长 mbAlignedWidth 存储，色度步长为其一半。
// =============================================================================

/**
 * @purpose 帧内预测
 * @author yanglong
 */
trait IntraPredTrait
{
    /**
     * 宏块编码入口：按 profile / 配置分派到 I_8x8、I_16x16、I_4x4 三条路径。
     *
     * @param int    $mbX,$mbY                宏块坐标（单位：宏块）
     * @param string $yPlane,$uPlane,$vPlane  原始图像平面字节串（步长 mbAlignedWidth）
     * @param bool   $leftAvailable           左邻宏块是否可用（6.4.9 邻块可用性推导）
     * @param array  &$leftNz                 CAVLC 左邻 nA 缓存（引用传递，写出后回写给右邻）
     * @param bool   $topAvailable            上邻宏块是否可用
     * @param array  &$topNzLuma              亮度上邻 nB 缓存（按 4x4 块列索引）
     * @param array  &$topNzCb,&$topNzCr      色度上邻 nB 缓存（按 4:2:0 4x4 块列索引）
     * @param array  &$leftIntra4x4Mode       左邻 4x4 预测模式（MPM 推导用，可为 null）
     * @param array  &$topIntra4x4Mode        上邻 4x4 预测模式（MPM 推导用，可为 null）
     * @return string                         CAVLC 码流片段；CABAC 路径返回空串
     */
    public function encodeMacroblock(
        $mbX, $mbY, $yPlane, $uPlane, $vPlane,
        $leftAvailable, &$leftNz, $topAvailable,
        &$topNzLuma, &$topNzCb, &$topNzCr,
        &$leftIntra4x4Mode = null, &$topIntra4x4Mode = null
    )
    {
        // high profile 且未进入 I16 试编时优先 I_8x8：8x8 变换在高分辨率下率失真更优
        if (($this->profile === 'high') && $this->intra8x8Enabled && !$this->forceIntra16Slice) {
            // high profile：I 片用 I8x8（forceIntra16Slice 置位时走 I16——逐 GOP 决策的 I16 试编遍）
            return $this->encodeMacroblockI8x8($mbX, $mbY, $yPlane, $uPlane, $vPlane, $leftAvailable, $leftNz, $topAvailable, $topNzLuma, $topNzCb, $topNzCr);
        }
        // 其余情况按 mbType 决定：I16 为整宏块单一模式，否则 I4x4 逐块选模式
        if ($this->mbType === self::MB_TYPE_I16x16) {
            return $this->encodeMacroblockI16x16($mbX, $mbY, $yPlane, $uPlane, $vPlane, $leftAvailable, $leftNz, $topAvailable, $topNzLuma, $topNzCb, $topNzCr);
        } else {
            return  $this->encodeMacroblockI4x4($mbX, $mbY, $yPlane, $uPlane, $vPlane, $leftAvailable, $leftNz, $topAvailable, $topNzLuma, $topNzCb, $topNzCr, $leftIntra4x4Mode, $topIntra4x4Mode);
        }
    }

    /**
     * I_16x16 宏块编码（7.4.5.1，Table 7-11：mb_type = 1 + Intra16x16PredMode + 4*cbpIdx）。
     * 流程：取 16x16 参考样值 -> 生成整宏块预测 -> 16 个 4x4 残差 DCT/量化
     *      -> 亮度 DC 4x4 Hadamard + 量化 -> 色度 DC 预测/变换/量化
     *      -> 写语法（CABAC 或 CAVLC）-> 本地反量化/逆变换重建。
     */
    public function encodeMacroblockI16x16(
        $mbX, $mbY, $yPlane, $uPlane, $vPlane,
        $leftAvailable, &$leftNz, $topAvailable,
        &$topNzLuma, &$topNzCb, &$topNzCr
    )
    {
        $bits = '';
        // CAVLC 码流累加缓冲；CABAC 路径不会写入它，最终返回空串
        $lumaPixels = array_fill(0, 16, array_fill(0, 16, 128));
        // 16x16 原始亮度样值缓存（行主序 [y][x]），初值 128 仅作占位
        $reconStride = $this->mbAlignedWidth;
        // reconStride：源平面/重建平面的行字节数（= 宏块对齐宽度）
        for ($y = 0; $y < 16; $y++) {
            $py = $mbY * 16 + $y;
            for ($x = 0; $x < 16; $x++) {
                $px = $mbX * 16 + $x;
                $idx = $py * $reconStride + $px;
                $lumaPixels[$y][$x] = ord($yPlane[$idx]);
            }
        }
        // ---- 载入本宏块 16x16 亮度原像素：平面偏移 = py*stride + px ----

        $leftPixels = array_fill(0, 16, 128);
        $topPixels = array_fill(0, 16, 128);
        // 左/上参考样值数组，默认 128（不可用样值按 8.3.3.2 的“不可用样值”规则处理）
        $leftSum = 0;
        // leftSum/topSum 用于 DC 预测；cntL/cntT 记录实际可用样值个数
        $topSum = 0;
        $cntL = 0;
        $cntT = 0;
        $reconStride = $this->mbAlignedWidth;
        // ---- 读左邻 16 个重建样值 p[-1, y]（仅当左邻宏块可用）----
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
        // ---- 读上邻 16 个重建样值 p[x, -1]（仅当上邻宏块可用）----
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

        // 预测模式选择（8.3.3.2）：
        //   0 = Intra_16x16_Vertical（需要上邻）
        //   1 = Intra_16x16_Horizontal（需要左邻）
        //   2 = Intra_16x16_DC（默认）
        //   3 = Intra_16x16_Plane（下方 switch 支持，本处不作为默认值）
        // 上、左都不可用只能 DC；缺上邻用 Horizontal；缺左邻用 Vertical。
        if (!$topAvailable && !$leftAvailable) {
            $lumaPredMode = 2;
        } elseif (!$topAvailable) {
            $lumaPredMode = 1;
        } elseif (!$leftAvailable) {
            $lumaPredMode = 0;
        } else {
            $lumaPredMode = 2;
        }

        // ---- 生成整宏块 16x16 预测 ----
        $predPixels = array_fill(0, 16, array_fill(0, 16, 128));
        // 各模式公式见 8.3.3.2；DC 的除数按可用样值个数选择
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
                        // DC：上+左共 32 个样值 -> (sum+16)>>5；仅单边 16 个 -> (sum+8)>>4
                        break;
                    case 3:
                        $a = $topPixels[$x];
                        $b = $leftPixels[$y];
                        $c = ($x > 0) ? $topPixels[$x - 1] : 128;
                        $d = ($y > 0) ? $leftPixels[$y - 1] : 128;
                        $predPixels[$y][$x] = (int)(($a + $b - $c - $d + $x * ($d - $c) + $y * ($c - $a) + 16) >> 5);
                        break;
                        // Plane：a=top[x]、b=left[y]、c=top[x-1]、d=left[y-1]
                        // 预测值 = clip((a + b - c - d + x*(d-c) + y*(c-a) + 16) >> 5)
                }
            }
        }

        // ---- 亮度残差：16 个 4x4 块逐个 DCT + 量化（对应 8.5 的正向变换与量化）----
        $dc4x4Raw = array_fill(0, 4, array_fill(0, 4, 0));
        $quant4x4Luma = array_fill(0, 16, array_fill(0, 16, 0));
        // quant4x4Luma 按 raster 块号（by*4+bx）存放 16 个量化系数，块内行主序
        $nzCache = array_fill(0, 24, 0);
        // nzCache：0..15 亮度块非零系数个数，16..19 Cb，20..23 Cr（截断到 15）
        $cbpLuma = 0;
        // cbpLuma 4bit：第 i 位表示第 i 个 8x8 子块是否含非零残差（Table 7-8）
        for ($by = 0; $by < 4; $by++) {
            for ($bx = 0; $bx < 4; $bx++) {
                $blkIdx = $by * 4 + $bx;
                // blkIdx 为 raster 序块号；码流语法用的是 n 序，需经 N2R/扫描表换算
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
                // 非零计数只统计 AC（下标 1..15），DC 单独走 Hadamard 通道
                if ($nz > 0) {
                    $subY = intdiv($blkIdx, 4);
                    $subX = $blkIdx % 4;
                    $block8x8Idx = intdiv($subY, 2) * 2 + intdiv($subX, 2);
                    $cbpLuma |= 1 << $block8x8Idx;
                }
            }
        }

        // ---- I16 亮度 DC：16 个 DC 系数先做 4x4 Hadamard，再统一量化 ----
        // 这是 I16 与 I4x4 的关键差异（8.5.10 亮度 DC 的 4x4 整数变换）
        $dcHadamard = $this->hadamardTransformDC($dc4x4Raw);
        $dcQuant = $this->quantizeDCMatrix($dcHadamard, $this->qp);
        $dcFlat = [];
        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                $dcFlat[] = $dcQuant[$y][$x];
            }
        }
        // 展平成 16 元素后按 4x4 zigzag 重排，供语法层按扫描序写入
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
        // 亮度 DC 全零但 AC 有非零时，cbpLuma 仍需置 15（DC 与 AC 共用 CBP 位）
        if ($hasLumaDc && $cbpLuma === 0) {
            $cbpLuma = 15;
        }
        if ($cbpLuma > 0 && $cbpLuma < 15) {
            $cbpLuma = 15;
        }
        // I16 的 cbpLuma 语义只区分 0 与非 0（Table 7-11），中间值统一提升为 15

        $u8x8 = array_fill(0, 8, array_fill(0, 8, 128));
        $v8x8 = array_fill(0, 8, array_fill(0, 8, 128));
        $chromaQpIndex = max(0, min(51, $this->qp + $this->chromaQpIndexOffset));
        $chromaQp = self::CHROMA_QP_TABLE[$chromaQpIndex];
        $hasChromaAc = false;
        $quantCb4x4 = array_fill(0, 4, array_fill(0, 16, 0));
        $quantCr4x4 = array_fill(0, 4, array_fill(0, 16, 0));
        $dcCb2x2 = [0, 0, 0, 0];
        $dcCr2x2 = [0, 0, 0, 0];

        // ---- 色度（4:2:0）：8x8 划分为 4 个 4x4，DC 组成 2x2 后再做 Hadamard ----
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
        // 载入 8x8 Cb/Cr 原像素（色度平面步长 = 亮度步长 / 2）

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
        // 色度预测模式固定 DC（8.3.3.3）：8x8 再分 4 个 4x4 分区，各取不同 DC 值

        $chromaPredMode = 0;

        $chromaPredU = array_fill(0, 8, array_fill(0, 8, 128));
        $chromaPredV = array_fill(0, 8, array_fill(0, 8, 128));

        // hasTop/hasLeft 表示色度参考样值可用性，决定 DC 分区公式
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
        // ---- 色度 4x4 残差：DCT + 量化（色度 QP 经 CHROMA_QP_TABLE 映射，Table 8-15）----

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
        // ---- 色度 DC：2x2 Hadamard + 量化（8.5.11 色度 DC 变换）----

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
        // 色度 DC 或 AC 任一非零，都需要写色度残差语法

        $cbpChroma = 0;
        if ($hasChromaDc) {
            $cbpChroma = $hasChromaAc ? 2 : 1;
        }
        // cbpChroma 语义（Table 7-9）：0=无系数，1=仅 DC，2=DC+AC 都有

        $mapModeI16x16 = [0, 1, 2, 3, 2, 2, 2];
        $mapModeChroma = [0, 1, 2, 3, 0, 0, 0];
        $i16Mode = $mapModeI16x16[$lumaPredMode];
        $chromaMode = $mapModeChroma[$chromaPredMode];

        // 内部模式编号 -> 语法取值的映射：
        //   i16Mode    ：Table 7-11 中 mb_type 携带的 Intra16x16PredMode
        //   chromaMode ：7.4.5.3 intra_chroma_pred_mode（0=DC,1=Horizontal,2=Vertical,3=Plane）
        if (!empty($this->useCabac)) {
        // ================== 路径 A：CABAC（9.3） ==================
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
            // I16 语法写完，收尾刷新 CABAC 上下文（对应解码端 cabDecodeI16x16 的收尾）
        } else {
        // ================== 路径 B：CAVLC（9.2） ==================
        // 语法序（7.4.5）：mb_type -> intra_chroma_pred_mode -> mb_qp_delta
        //              -> 亮度 DC 块 -> 亮度 AC 块 -> 色度 DC -> 色度 AC
        // 块扫描顺序必须与解码端一致，才能保证 nc（9.2.1）推导一致
        $cbpIdx = ($cbpLuma == 0 ? 0 : 3) + $cbpChroma;
        $mbTypeValue = 1 + $i16Mode + ($cbpIdx << 2);
        // mb_type = 1 + Intra16x16PredMode + 4*cbpIdx（Table 7-11）
        // 其中 cbpIdx = (亮度 CBP != 0 ? 3 : 0) + 色度 CBP
        $bits .= $this->ue($mbTypeValue);
        $bits .= $this->ue($chromaMode);
        $bits .= $this->se(0);
        $dcNc = $this->computeNC(-1, $mbX, 0, 0, $leftAvailable, $leftNz, $topAvailable, $topNzLuma, $nzCache);
        // 亮度 DC 块：16 个系数（TotalCoeff 最大 16），nc 由左/上 DC 块的 nA/nB 推出
        $bits .= $this->writeBlockResidualCavlc($dcZigzag, 15, false, $dcNc);
        // AC 块扫描顺序：按 8x8 子块光栅序展开的 4x4 块 raster 索引
        $lumaAcScanOrder = [0, 1, 4, 5, 2, 3, 6, 7, 8, 9, 12, 13, 10, 11, 14, 15];
        $nzCacheNew = array_fill(0, 24, 0);
        // nzCacheNew 逐步填充：写当前块时右/下邻居尚未产生，用 0 占位后按需覆盖
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
        // 写完后把本宏块最右列（bx=3）nz 写入左邻缓存、最底行（by=3）写入上邻缓存
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
        // 色度 DC：Cb、Cr 各 4 系数（TotalCoeff 最大 4），nc 固定 -1（不走 nA/nB）
            $bits .= $this->writeBlockResidualCavlc($qCbDc, 3, true, -1);
            $bits .= $this->writeBlockResidualCavlc($qCrDc, 3, true, -1);
            if ($cbpChroma === 2) {
                $cbScanOrder = [16, 17, 18, 19];
                // 色度 AC：Cb 的四个 4x4 -> Cr 的四个 4x4（14 系数，不含 DC）
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
            // 色度 CBP 为 0 时同样清零左/上 nz 缓存，避免陈旧值污染下一宏块的 nc
        } // end CAVLC

        // ================== 本地重建（必须与解码端逐比特一致） ==================
        // === 本地解码重建（用于正确更新参考帧）===
        // 1. 反量化DC Hadamard系数
        $dcFlatRecon = [];
        for ($y = 0; $y < 4; $y++) for ($x = 0; $x < 4; $x++) $dcFlatRecon[] = $dcQuant[$y][$x];
        $lumaQmul = $this->dequant4Table[0][$this->qp][0];
        // DC 反量化：亮度 DC 使用 dequant4Table[0][qp][0] 作为缩放因子（8.5.10）
        $dcResultRecon = $this->lumaDcDequantIdct($dcFlatRecon, $lumaQmul);

        // 2. 逐4x4块重建像素
        // 逐 4x4 块：AC 反量化 -> DC 填回 [0] -> 4x4 逆变换 -> 加预测 -> clip 写回重建平面
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
                    // 仅 DC 情形：DC 反量化值 (DC+32)>>6 即该 4x4 块所有像素的残差
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
        // 色度重建：DC 2x2 反 Hadamard 后，逐 4x4 块叠加 AC 残差
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
                    // CBP=2 表示 AC 存在：DC 填入 [0] 后做原地 4x4 逆变换
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
                    // CBP=1 表示仅 DC：(DC+32)>>6 为块内统一残差
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
                    // CBP=0：残差为 0，重建像素即预测像素
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

    /**
     * I_4x4 宏块编码（mb_type = 0，Table 7-11）。
     * 每个 4x4 块在 9 种模式（8.3.3.1 Table 8-2）中按 SSE 选优；
     * 残差走 4x4 DCT/量化，DC 不做 Hadamard；
     * 语法：mb_type -> intra4x4_pred_mode（16 次 MPM 编码）-> 色度模式
     *      -> coded_block_pattern -> mb_qp_delta -> 残差块（9.2/9.3）。
     */
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
        // 载入本宏块 16x16 原始亮度（行主序 [y][x]）
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
        // 左邻参考：leftPixels4x4[by][y] 为宏块左侧 4 个 4x4 块列共 4x4 样值
        // 上邻参考：topPixels4x4[bx][x] 为上邻 4 个 4x4 块列，每列 8 个样值（含右上扩展）
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

        // ---- 第一遍：逐 4x4 块按 SSE 从 9 种模式中选最优（8.3.3.1）----
        //$lumaAcScanOrder = [0, 1, 4, 5, 2, 3, 6, 7, 8, 9, 12, 13, 10, 11, 14, 15];
        $intra4x4PredModes = array_fill(0, 16, 2);
        // 默认 DC；真正取值由下方 9 模式循环决定
        for ($by = 0; $by < 4; $by++) {
            for ($bx = 0; $bx < 4; $bx++) {
                $blkIdx = $by * 4 + $bx;
                $leftAvail = ($bx > 0) || $leftAvailable;
                // 可用性（6.4.9 / 8.3.3.1）：同一宏块内左侧块总是可用；
                $topAvail = $topAvailable;
                // 本块的上邻：4x4 块所在行之前的行为已重建块；第一行才依赖宏块级上邻
                $topRightAvail = false;
                // topRight 仅在本行不是最后一块、且上邻宏块可用时存在
                if ($topAvail && $bx < 3) {
                    $topRightAvail = true;
                }

                $top = array_fill(0, 8, 128);
                $left = array_fill(0, 4, 128);
                $topLeft = 128;

                // 参考样值缓冲：top 8 个（p[x,-1]，x=0..7），left 4 个（p[-1,y]），topLeft 1 个
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
                // topRight 不可用时复制 top[3] 填充（H.264 边界样值扩展规则）

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

                // bestMode/bestCost 保存当前块的最佳模式与最小 SSE
                $bestMode = 2;
                $bestCost = PHP_INT_MAX;
                // bestCost 初始化为无穷大，保证第一个合法模式一定被选中
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
                // 各模式严格对应 8.3.3.1 表 8-2 的 0..8 号预测模式：
                //   0=Vertical 1=Horizontal 2=DC 3=DiagonalDownLeft 4=DiagonalDownRight
                //   5=VerticalRight 6=HorizontalDown 7=VerticalLeft 8=HorizontalUp
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
                    // 模式代价：SSE = Σ(原始 - 预测)^2（率失真近似，不叠加码率项）
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
        // ---- 第二遍：用选定模式重新生成预测，求残差并做 4x4 DCT/量化 ----
        $quant4x4Luma = array_fill(0, 16, array_fill(0, 16, 0));
        $nzCache = array_fill(0, 24, 0);
        $cbpLuma = 0;
        // cbpLuma：每个 8x8 子块 1 位，4x4 块有非零 AC 即置位（Table 7-8）
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

                // 第二遍重新装配参考样值：宏块内部块直接取原始像素（编码端可确定解码端重建值）
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
                // 若最终模式在当前可用性下非法，则回退 DC（解码端遇到非法模式会报错）
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
                // 残差 = 原始 - 预测
                    for ($x = 0; $x < 4; $x++) {
                        $blk4x4[$y][$x] -= $predPixels[$y][$x];
                    }
                }

                $dctBlock = $this->dct($blk4x4);
                // 4x4 正向变换（8.5 变换部分），isChroma=0 表示亮度
                $quantBlock = $this->quantize($dctBlock, 0);
                // 量化：亮度 4x4 帧内使用 QP%6 对应量化表
                for ($yy = 0; $yy < 4; $yy++) {
                // 量化系数按 raster 布局存入 quant4x4Luma
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

        // ---- 色度：与 I16 路径相同的 DC 预测 + 4x4 变换量化 + DC Hadamard ----
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
        // 色度预测模式固定 DC（8.3.3.3）
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
        // I_4x4 语法序：mb_type -> intra4x4_pred_mode -> 色度模式 -> CBP -> mb_qp_delta -> 残差

        if (!empty($this->useCabac)) {
            // ------------------ 路径 A：CABAC（9.3） ------------------
            // ---- CABAC 写码分支：语法序镜像解码器 cabDecodeI4x4（模式/残差均 n 序）----
            $this->cabMbBegin($mbX, $mbY);
            $this->encWriteIntraMbTypeI(false, 0, 0, 0);
            $this->cabFixUnavailNz(true);
            for ($n = 0; $n < 16; $n++) {
            // 16 个块按 n 序写 prev_intra4x4_pred_mode_flag / rem_intra4x4_pred_mode（9.3.3.2.1.4）
                $this->encWriteI4Mode($n, $intra4x4PredModes[self::$N2R[$n]]);
            }
            $this->encWriteChromaPred($chromaPredMode);
            $cbp = ($cbpChroma << 4) | $cbpLuma;
            // I4 的 CBP 是独立语法元素（I16 才内嵌于 mb_type），漏写会使解码端读飞错位
            // CABAC 下 I_NxN 的 CBP 是独立语法元素（I16 才内嵌于 mb_type），按亮度->色度顺序写
            $this->encWriteCbpLuma($cbpLuma);
            $this->encWriteCbpChroma($cbpChroma);
            $this->cabCbp = $cbp;
            $this->picCbp[$this->cabMbIdx] = $cbp;
            if ($cbp !== 0) {
            // CBP 非 0 才有 mb_qp_delta；CBP 为 0 时复位 QP 差上下文
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
        // ------------------ 路径 B：CAVLC（9.2） ------------------
        // 1. mb_type = 0 for I_NxN
        $bits .= $this->ue(0);

        // 2. 编码intra4x4_pred_mode (16个块)
        $lumaAcScanOrder = [0, 1, 4, 5, 2, 3, 6, 7, 8, 9, 12, 13, 10, 11, 14, 15];
        // 模式编码顺序：按 8x8 子块光栅序展开的 4x4 扫描序（与解码端 modeCache 更新顺序一致）
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

            // MPM：左/上模式都可用时取较小者，否则取 DC(2)（8.3.3.1 的模式预测）
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
                // rem = mode > predicted ? mode-1 : mode，用 3 bit 定长写入（Table 8-2 映射）
            }

            $modeCache[$rasterIdx] = $mode;
        }

            // 更新模式缓存，供本宏块内右下相邻块计算 MPM
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
        // CBP 编码：I_NxN 用 Table 9-4 的 48 项映射表把 cbp 映射成 codeNum 再 ue 编码
        $bits .= $this->ue($cbpCode);

        // 5. 编码mb_qp_delta (如果CBP > 0)
        if ($cbpValue > 0) {
        // mb_qp_delta 仅在 CBP 非 0 时出现（7.4.5.3）
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
        // 残差遍历：亮度每块 15 系数（含 DC），色度每块 14 系数（DC 单独成块）
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
            // cbp 位为 0 的 8x8 子块：其 4 个 4x4 块的 nz 必须清零
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
        // CAVLC 路径结束

        return $bits;
    }

    /**
     * Intra_8x8 帧内预测（编码端，镜像解码端 intra8x8Prediction）：
     * 邻居样值取自本机重建平面 reconYPlane（mbAlignedWidth 步长），
     * 先经 [1 2 1]/4 滤波（l0..l7/t0..t15/lt），再按模式生成 8x8 预测。
     * blkX/blkY ∈ {0,1}：8x8 块在宏块内的位置。
     */
    // 模式编号与 8.3.3.1 一致；参考样值先经 [1 2 1]/4 滤波（8.3.3 的 Intra_8x8 规则）
    private function encIntra8x8Prediction(int $mbX, int $mbY, int $blkX, int $blkY, int $mode): array
    {
        if ($mode < 0 || $mode > 8) {
            $mode = 2;
        }
        // 非法模式回退 DC，与解码端保持一致
        $x0 = $mbX * 16 + $blkX * 8;
        $y0 = $mbY * 16 + $blkY * 8;
        $w = $this->width;
        $h = $this->height;
        $stride = $this->mbAlignedWidth;
        // $stride 固定使用重建平面步长：8x8 预测样值必须取自重建图，不能用原始图

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
        // topLeft 需要上、左同时可用才存在

        // 原始邻居样值（不可用时 128）
        $st = array_fill(0, 16, 128); // p[x,-1], x=0..15
        $sl = array_fill(0, 8, 128);  // p[-1,y], y=0..7
        $slt = 128;                   // p[-1,-1]
        // 原始参考样值：st=p[x,-1]（16 个），sl=p[-1,y]（8 个），slt=p[-1,-1]
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
        // topRight 不可用时 p[8..15,-1] 全部用 p[7,-1] 复制
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
        // 左上角样值：仅当 top 与 left 都可用时读取

        // [1 2 1]/4 滤波（PREDICT_8x8_LOAD_*）
        // ---- 参考样值滤波：p'[x] = (p[x-1] + 2*p[x] + p[x+1] + 2) >> 2 ----
        // 端点（x=0）用 p[0] 与 p[-1] 组合；x=7 用 3*p[7] 收尾，避免越界
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

        // lt 为滤波后的左上角样值，供模式 4/5/6 使用
        $avg2 = fn($a, $b) => ($a + $b + 1) >> 1;
        $avg3 = fn($a, $b, $c) => ($a + 2 * $b + $c + 2) >> 2;

        // avg2/avg3 即 8.3.3.1 预测公式中的 (a+b+1)>>1 与 (a+2b+c+2)>>2
        $p = array_fill(0, 8, array_fill(0, 8, 128));
        // 9 种 8x8 预测：模式语义同 4x4，但作用于 8x8 且参考样值已滤波
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
        // 返回 8x8 预测块（行主序）
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
    // Phase A 做“预测->残差->变换->量化->反量化->逆变换->重建”，逐 8x8 串行，
    // 是为了让后面的 8x8 块能用到刚重建的邻居像素（解码端同样是串行处理）。
    public function encodeMacroblockI8x8(
        $mbX, $mbY, $yPlane, $uPlane, $vPlane,
        $leftAvailable, &$leftNz, $topAvailable,
        &$topNzLuma, &$topNzCb, &$topNzCr
    ): string {
        $reconStride = $this->mbAlignedWidth;
        // 重建平面步长

        // ---- 原始 16x16 亮度像素 ----
        $lumaPixels = array_fill(0, 16, array_fill(0, 16, 128));
        for ($y = 0; $y < 16; $y++) {
            $py = $mbY * 16 + $y;
            for ($x = 0; $x < 16; $x++) {
                $lumaPixels[$y][$x] = ord($yPlane[$py * $reconStride + ($mbX * 16 + $x)]);
            }
        }
        // 只读取 16x16 原始亮度；预测参考一律走重建平面

        // ---- 8x8 变换/量化表（帧内 ff=2）----
        [$qmul8, $mf8] = $this->buildQuant8Tables($this->qp, true);
        $zz8 = self::$ZZ8;
        $scanJ = [];
        for ($k = 0; $k < 64; $k++) {
            $scanJ[$k] = ($zz8[$k] >> 3) | (($zz8[$k] & 7) << 3);
        }
        // ZZ8 与存储布局坐标互换：j = (zz>>3) | ((zz&7)<<3)

        // ---- Phase A：逐 8x8 串行（模式决策 + 量化 + 重建，p8=TL,TR,BL,BR）----
        $modes = [];
        $levels = [];
        $cbpLuma = 0;
        // cbpLuma：第 p8 位表示该 8x8 块是否含非零量化系数（Table 7-8）
        for ($p8 = 0; $p8 < 4; $p8++) {
            $bx = $p8 & 1;
            $by = $p8 >> 1;
        // p8 顺序：TL(0) -> TR(1) -> BL(2) -> BR(3)
            $topAvail = ($by > 0) || ($mbY > 0);
            $leftAvail = ($bx > 0) || ($mbX > 0);

            // 模式决策：SSE 最小（9 模式全评——8x8 块作为 I 帧参考，剪到 4 模式
            // 会使重建明显劣化并放大后续 P/B 帧残差，实测得不偿失）
            // 合法性对照参考实现 ff_h264_check_intra4x4_pred_mode 的 top[]/left[] 表
            // （需要 top：{0,3,4,5,6,7}；需要 left：{1,4,5,6,8}），非法组合解码器直接报错
            $bestMode = 2;
            $bestCost = PHP_INT_MAX;
            for ($m = 0; $m <= 8; $m++) {
        // 9 模式全评估，仅剔除当前可用性下非法的模式
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
        // 记录该 8x8 块的最佳模式
            $modes[$p8] = $bestMode;

            // 残差 → 正向变换 → 量化（存储布局）
        // 用最佳模式生成预测，并求 8x8 残差（行主序 64 元素）
            $pred = $this->encIntra8x8Prediction($mbX, $mbY, $bx, $by, $bestMode);
            $res = array_fill(0, 64, 0);
            for ($yy = 0; $yy < 8; $yy++) {
                $oy = $by * 8 + $yy;
                for ($xx = 0; $xx < 8; $xx++) {
                    $res[$yy * 8 + $xx] = $lumaPixels[$oy][$bx * 8 + $xx] - $pred[$yy][$xx];
                }
            }
            $c = $this->forward8x8($res);
        // 8x8 正向变换；quantize8 的 ff=2 表示帧内
            $nz = $this->quantize8($c, $mf8, 2);

            // 扫描序 levels（k -> 存储位 j），供 cat5 语法写入
        // 转成扫描序稀疏 levels（k -> 存储下标 j），供 cat5 语法逐系数写入
            $lv = [];
            for ($k = 0; $k < 64; $k++) {
                $v = $c[$scanJ[$k]];
                if ($v !== 0) {
                    $lv[$k] = $v;
                }
            }
            $levels[$p8] = $lv;
            if ($nz > 0) {
        // 有非零系数才需要反量化/逆变换；否则残差视为全 0
                $cbpLuma |= 1 << $p8;
                // 反量化 + 逆变换 → 残差像素（行主序）
                $this->dequantize8($c, $qmul8);
                $resid = $this->inverse8x8($c);
            } else {
                $resid = array_fill(0, 64, 0);
            }

            // 重建写入 reconYPlane（镜像解码端：先写 pred 再叠 idct8 残差，逐像素 clamp）
        // 重建 = 预测 + 逆变换残差，逐像素 clip 到 [0,255] 后写重建平面
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

        // ---- 色度部分：与 I4x4 路径完全一致的 DC 预测 + 4x4 变换量化 ----
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
        // 色度预测模式固定 DC
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
        // 色度 CBP 语义：0=无系数，1=仅 DC，2=DC+AC
        if ($hasChromaDc) {
            $cbpChroma = $hasChromaAc ? 2 : 1;
        }

        // ---- 色度本地解码重建（更新色度参考帧，与 I16x16 路径一致）----
        // 色度重建：保证后续宏块的帧内色度预测有正确参考
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
        // ---- Phase B：CABAC 语法写入（I_8x8 仅 high profile 使用，故只有 CABAC 路径）----
        $this->cabMbBegin($mbX, $mbY);
        $this->encWriteIntraMbTypeI(false, 0, 0, 0);
        $this->cabFixUnavailNz(true);
        // transform_size_8x8_flag = 1（I 片在所有 pred 模式之前读）
        $this->encDecision(399 + $this->cabNeighborTransformSize(), 1);
        // transform_size_8x8_flag=1，上下文 idx = 399 + 邻居条件（9.3.3.1.1.9）
        // 4 个 8x8 分组模式（n=0,4,8,12，2x2 cache 填充）
        for ($p8 = 0; $p8 < 4; $p8++) {
            $this->encWriteI4Mode(4 * $p8, $modes[$p8], true);
        }
        // 每个 8x8 块写一次模式（ctx 由 2x2 模式缓存填充得到）
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
        // 亮度残差：cat5，一次写满 64 系数（含 DC），无逐块 CBF
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
        // 色度 DC/AC 语法（与 I4x4 路径一致）
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
        // 收尾：模式按 n 序展开（2x2 同值），并置 CAB_FLG_I4 | CAB_FLG_DCT8
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
