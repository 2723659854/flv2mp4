<?php

namespace Xiaosongshu\Flv2mp4\Codec\Decode;

/**
 * @purpose spspps解析器
 * @author yanglong
 * @time 2026年7月23日15:25:17
 */
trait SpsPpsParsingTrait
{
    /**
     * 解析SPS获取画面宽高、色度格式等
     */
    public function parseSPS(string $rbsp): void
    {
        $this->reader = new BitReader($rbsp);

        $this->profileIdc = $this->reader->readU(8);
        $constraintFlags = $this->reader->readU(8);
        $levelIdc = $this->reader->readU(8);

        $this->reader->readUe(); // seq_parameter_set_id

        $isHighProfile = in_array($this->profileIdc, [100, 110, 122, 244, 44, 83, 86, 118, 128, 138, 139, 134, 135, 144]);

        if ($isHighProfile) {
            $this->chromaFormatIdc = $this->reader->readUe();
            if ($this->chromaFormatIdc > 3) return;
            if ($this->chromaFormatIdc === 3) $this->reader->skip(1);
            $this->reader->readUe(); // bit_depth_luma_minus8
            $this->reader->readUe(); // bit_depth_chroma_minus8
            $this->reader->skip(1); // qpprime_y_zero_transform_bypass_flag

            // seq_scaling_matrix_present_flag (必须先读取这个 flag)
            $seqScalingMatrixPresentFlag = $this->reader->readU(1);
            if ($seqScalingMatrixPresentFlag) {
                $numScalingLists = ($this->chromaFormatIdc !== 3) ? 4 : 6;
                for ($i = 0; $i < $numScalingLists; $i++) {
                    $useScalingList = $this->reader->readU(1);
                    if ($useScalingList) {
                        $sizeOfScalingList = ($i < 4) ? 16 : 64;
                        $lastScale = 8;
                        $nextScale = 8;
                        for ($j = 0; $j < $sizeOfScalingList; $j++) {
                            if ($nextScale !== 0) {
                                $deltaScale = $this->reader->readSe();
                                $nextScale = ($lastScale + $deltaScale + 256) % 256;
                            }
                            $lastScale = $nextScale === 0 ? $lastScale : $nextScale;
                        }
                    }
                }
            }
        } else {
            $this->chromaFormatIdc = 1;
        }

        $this->log2MaxFrameNumMinus4 = $this->reader->readUe();
        $this->picOrderCntType = $this->reader->readUe();

        if ($this->picOrderCntType === 0) {
            $this->log2MaxPicOrderCntLsb = $this->reader->readUe() + 4;
        } elseif ($this->picOrderCntType === 1) {
            $this->reader->skip(1);
            $this->reader->readSe();
            $this->reader->readSe();
            $numRefFramesInPicOrderCntCycle = $this->reader->readUe();
            for ($j = 0; $j < $numRefFramesInPicOrderCntCycle; $j++) $this->reader->readSe();
        }

        $this->maxNumRefFrames = $this->reader->readUe() + 1; // max_num_ref_frames
        $this->reader->skip(1);
        $picWidthInMbsMinus1 = $this->reader->readUe();
        $picHeightInMapUnitsMinus1 = $this->reader->readUe();
        $this->frameMbsOnlyFlag = (bool)$this->reader->readU(1);

        if (!$this->frameMbsOnlyFlag) $this->reader->skip(1);
        $this->reader->skip(1);

        $frameCroppingFlag = $this->reader->readU(1);
        $cropLeft = 0; $cropRight = 0; $cropTop = 0; $cropBottom = 0;
        if ($frameCroppingFlag) {
            $cropLeft = $this->reader->readUe();
            $cropRight = $this->reader->readUe();
            $cropTop = $this->reader->readUe();
            $cropBottom = $this->reader->readUe();
        }

        $this->picWidthInMbs = $picWidthInMbsMinus1 + 1;
        $this->picHeightInMbs = $picHeightInMapUnitsMinus1 + 1;
        if (!$this->frameMbsOnlyFlag) $this->picHeightInMbs *= 2;

        $vsub = (int)($this->chromaFormatIdc === 1);
        $hsub = (int)($this->chromaFormatIdc === 1 || $this->chromaFormatIdc === 2);
        $stepX = 1 << $hsub;
        $stepY = (2 - (int)$this->frameMbsOnlyFlag) << $vsub;

        $width = $this->picWidthInMbs * 16;
        $height = $this->picHeightInMbs * 16;

        $cropLeftScaled = $cropLeft * $stepX;
        $cropRightScaled = $cropRight * $stepX;
        $cropTopScaled = $cropTop * $stepY;
        $cropBottomScaled = $cropBottom * $stepY;

        if (($cropLeftScaled + $cropRightScaled) < $width && ($cropTopScaled + $cropBottomScaled) < $height) {
            $this->width = $width - $cropLeftScaled - $cropRightScaled;
            $this->height = $height - $cropTopScaled - $cropBottomScaled;
        } else {
            $this->width = $width;
            $this->height = $height;
        }
    }

    /**
     * 解析PPS
     */
    public function parsePPS(string $rbsp): void
    {
        $this->reader = new BitReader($rbsp);
        $this->reader->readUe();
        $spsId = $this->reader->readUe();
        $this->entropyCodingModeFlag = (bool)$this->reader->readU(1);
        $this->bottomFieldPicOrderInFramePresent = (bool)$this->reader->readU(1);
        $this->reader->readUe();
        $this->numRefIdxL0DefaultActive = $this->reader->readUe() + 1;
        $this->numRefIdxL1DefaultActive = $this->reader->readUe() + 1;
        $this->weightedPredFlag = (bool)$this->reader->readU(1);
        $this->weightedBipredIdc = $this->reader->readU(2);

        $this->picInitQp = $this->reader->readSe() + 26;
        $this->reader->readSe();
        $this->chromaQpIndexOffset = $this->reader->readSe();

        // 修复色度QP偏移范围 -12 ~ 12
        if ($this->chromaQpIndexOffset < -12 || $this->chromaQpIndexOffset > 12) {
            $this->chromaQpIndexOffset = 5;
        }

        $this->deblockingFilterParametersPresent = (bool)$this->reader->readU(1);
        $this->reader->skip(1); // constrained_intra_pred_flag
        $this->redundantPicCntPresent = (bool)$this->reader->readU(1);
    }
}
