<?php

namespace Xiaosongshu\Flv2mp4\Codec;

class H264Encoder
{
    public $width = 640;
    public $height = 360;
    public $fps = 30;
    public $bitrate = 500000;

    public $frameNum = 0;

    public function __construct() {}

    public function setResolution(int $width, int $height): void
    {
        $this->width = $width;
        $this->height = $height;
    }

    public function setFps(int $fps): void
    {
        $this->fps = $fps;
    }

    public function setBitrate(int $bitrate): void
    {
        $this->bitrate = $bitrate;
    }

    public function encodeFrame(string $yuvData, bool $isKeyframe = false): array
    {
        $nalUnits = [];

        if ($isKeyframe) {
            $nalUnits[] = $this->generateSPS();
            $nalUnits[] = $this->generatePPS();
            $this->frameNum = 0;
        }

        $sliceData = $this->encodeSlice($yuvData, $isKeyframe);
        $nalUnits[] = $sliceData;

        $this->frameNum++;

        return $nalUnits;
    }

    public function generateSPS(): string
    {
        $profileIdc = 66;
        $constraintSet0 = 0;
        $constraintSet1 = 0;
        $constraintSet2 = 0;
        $levelIdc = 30;
        $seqParameterSetId = 0;
        $log2MaxFrameNumMinus4 = 4;
        $picOrderCntType = 0;
        $log2MaxPicOrderCntLsbMinus4 = 4;
        $numRefFrames = 1;
        $gapsInFrameNumValueAllowedFlag = 0;
        
        $picWidthInMbs = (int)ceil($this->width / 16);
        $picWidthInMbsMinus1 = $picWidthInMbs - 1;
        $picHeightInMapUnits = (int)ceil($this->height / 16);
        $picHeightInMapUnitsMinus1 = $picHeightInMapUnits - 1;
        $frameMbsOnlyFlag = 1;
        $direct8x8InferenceFlag = 1;
        $frameCroppingFlag = 0;
        $vuiParametersPresentFlag = 0;

        $bits = '';
        $bits .= $this->u($profileIdc, 8);
        $bits .= $this->u($constraintSet0, 1);
        $bits .= $this->u($constraintSet1, 1);
        $bits .= $this->u($constraintSet2, 1);
        $bits .= $this->u(0, 5);
        $bits .= $this->u($levelIdc, 8);
        $bits .= $this->ue($seqParameterSetId);
        $bits .= $this->ue($log2MaxFrameNumMinus4);
        $bits .= $this->ue($picOrderCntType);
        
        if ($picOrderCntType == 0) {
            $bits .= $this->ue($log2MaxPicOrderCntLsbMinus4);
        }
        
        $bits .= $this->ue($numRefFrames);
        $bits .= $this->u($gapsInFrameNumValueAllowedFlag, 1);
        $bits .= $this->ue($picWidthInMbsMinus1);
        $bits .= $this->ue($picHeightInMapUnitsMinus1);
        $bits .= $this->u($frameMbsOnlyFlag, 1);
        
        if (!$frameMbsOnlyFlag) {
            $bits .= $this->u(0, 1);
        }
        
        $bits .= $this->u($direct8x8InferenceFlag, 1);
        $bits .= $this->u($frameCroppingFlag, 1);
        
        if ($frameCroppingFlag) {
            $bits .= $this->ue(0);
            $bits .= $this->ue(0);
            $bits .= $this->ue(0);
            $bits .= $this->ue(0);
        }
        
        $bits .= $this->u($vuiParametersPresentFlag, 1);
        
        while (strlen($bits) % 8 != 0) {
            $bits .= '0';
        }
        
        $sps = "\x67" . $this->bitsToBytes($bits);
        return $sps;
    }

    public function generatePPS(): string
    {
        $picParameterSetId = 0;
        $seqParameterSetId = 0;
        $entropyCodingModeFlag = 0;
        $picOrderPresentFlag = 0;
        $numRefIdxL0DefaultActiveMinus1 = 0;
        $numRefIdxL1DefaultActiveMinus1 = 0;
        $weightedPredFlag = 0;
        $weightedBipredIdc = 0;
        $picInitQpMinus26 = -6;
        $picInitQsMinus26 = 0;
        $chromaQpIndexOffset = 0;
        $deblockingFilterControlPresentFlag = 0;
        $constrainedIntraPredFlag = 0;
        $redundantPicCntPresentFlag = 0;

        $bits = '';
        $bits .= $this->ue($picParameterSetId);
        $bits .= $this->ue($seqParameterSetId);
        $bits .= $this->u($entropyCodingModeFlag, 1);
        $bits .= $this->u($picOrderPresentFlag, 1);
        $bits .= $this->ue($numRefIdxL0DefaultActiveMinus1);
        $bits .= $this->ue($numRefIdxL1DefaultActiveMinus1);
        $bits .= $this->u($weightedPredFlag, 1);
        $bits .= $this->u($weightedBipredIdc, 2);
        $bits .= $this->se($picInitQpMinus26);
        $bits .= $this->se($picInitQsMinus26);
        $bits .= $this->se($chromaQpIndexOffset);
        $bits .= $this->u($deblockingFilterControlPresentFlag, 1);
        $bits .= $this->u($constrainedIntraPredFlag, 1);
        $bits .= $this->u($redundantPicCntPresentFlag, 1);
        
        while (strlen($bits) % 8 != 0) {
            $bits .= '0';
        }
        
        $pps = "\x68" . $this->bitsToBytes($bits);
        return $pps;
    }

    private function encodeSlice(string $yuvData, bool $isKeyframe): string
    {
        $bits = '';
        
        $bits .= '01100101';
        
        $bits .= $this->ue(0);
        
        $bits .= $this->ue(7);
        
        $bits .= $this->ue(0);
        
        $bits .= $this->u($this->frameNum, 4);
        
        if ($isKeyframe) {
            $bits .= $this->ue(0);
            $bits .= '0';
            $bits .= '0';
        }
        
        $bits .= $this->se(-18);
        
        $bits .= $this->ue(0);
        
        $bits .= $this->se(0);
        $bits .= $this->se(0);
        
        $mbWidth  = (int)ceil($this->width / 16);
        $mbHeight = (int)ceil($this->height / 16);
        
        for ($mbY = 0; $mbY < $mbHeight; $mbY++) {
            for ($mbX = 0; $mbX < $mbWidth; $mbX++) {
                if ($mbY == 0) {
                    $bits .= $this->ue(3);
                } else {
                    $bits .= $this->ue(1);
                }

                for ($subMb = 0; $subMb < 16; $subMb++) {
                    $subX = $subMb % 4;
                    if ($subX == 3) {
                        $bits .= $this->ue(3);
                    } else {
                        $bits .= $this->ue(0);
                    }
                }

                $bits .= $this->ue(0);

                $bits .= '00';
            }
        }
        
        $bits .= '1';
        while (strlen($bits) % 8 != 0) {
            $bits .= '0';
        }
        
        return $this->bitsToBytes($bits);
    }

    private function ue(int $value): string
    {
        if ($value == 0) return '1';
        $codeNum = $value + 1;
        $leadingZeroBits = 0;
        $temp = $codeNum;
        while (($temp >>= 1) > 0) {
            $leadingZeroBits++;
        }
        return str_repeat('0', $leadingZeroBits) . '1' . substr(decbin($codeNum), 1);
    }

    private function se(int $value): string
    {
        if ($value <= 0) {
            $codeNum = -$value * 2;
        } else {
            $codeNum = $value * 2 - 1;
        }
        return $this->ue($codeNum);
    }

    private function u(int $value, int $n): string
    {
        return str_pad(decbin($value), $n, '0', STR_PAD_LEFT);
    }

    private function bitsToBytes(string $bits): string
    {
        $bytes = '';
        $len = strlen($bits);
        for ($i = 0; $i < $len; $i += 8) {
            $byte = substr($bits, $i, 8);
            if (strlen($byte) < 8) {
                $byte = str_pad($byte, 8, '0');
            }
            $bytes .= chr(bindec($byte));
        }
        return $bytes;
    }
}
