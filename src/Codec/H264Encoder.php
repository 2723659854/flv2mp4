<?php

namespace Xiaosongshu\Flv2mp4\Codec;

class H264Encoder
{
    public $width = 640;
    public $height = 360;
    public $fps = 30;
    public $bitrate = 500000;
    public $qp = 28;

    public $frameNum = 0;

    private $quantMatrix = [];

    public function __construct()
    {
        $this->initQuantMatrix();
    }

    private function initQuantMatrix(): void
    {
        $this->quantMatrix[0] = [
            16, 11, 10, 16, 24, 40, 51, 61,
            12, 12, 14, 19, 26, 58, 60, 55,
            14, 13, 16, 24, 40, 57, 69, 56,
            14, 17, 22, 29, 51, 87, 80, 62,
            18, 22, 37, 56, 68, 109, 103, 77,
            24, 35, 55, 64, 81, 104, 113, 92,
            49, 64, 78, 87, 103, 121, 120, 101,
            72, 92, 95, 98, 112, 100, 103, 99
        ];

        $this->quantMatrix[1] = [
            17, 18, 24, 47, 99, 99, 99, 99,
            18, 21, 26, 66, 99, 99, 99, 99,
            24, 26, 56, 99, 99, 99, 99, 99,
            47, 66, 99, 99, 99, 99, 99, 99,
            99, 99, 99, 99, 99, 99, 99, 99,
            99, 99, 99, 99, 99, 99, 99, 99,
            99, 99, 99, 99, 99, 99, 99, 99,
            99, 99, 99, 99, 99, 99, 99, 99
        ];
    }

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
        $this->qp = max(10, min(51, 40 - (int)log($bitrate / 500000, 2)));
    }

    public function encodeFrame(string $yuvData, bool $isKeyframe = false): array
    {
        $nalUnits = [];

        if ($isKeyframe) {
            $nalUnits[] = $this->generateSPS();
            $nalUnits[] = $this->generatePPS();
            $this->frameNum = 0;
        }

        $sliceData = $this->encodeSliceIntra($yuvData);
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
        $picInitQpMinus26 = $this->qp - 26;
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
        
        if ($deblockingFilterControlPresentFlag) {
            $bits .= $this->u(1, 1);
            $bits .= $this->se(0);
            $bits .= $this->se(0);
        }
        
        $bits .= $this->u($constrainedIntraPredFlag, 1);
        $bits .= $this->u($redundantPicCntPresentFlag, 1);
        
        while (strlen($bits) % 8 != 0) {
            $bits .= '0';
        }
        
        $pps = "\x68" . $this->bitsToBytes($bits);
        return $pps;
    }

    private function encodeSliceIntra(string $yuvData): string
    {
        $bits = '';
        
        $sliceType = 2;
        $bits .= $this->ue($sliceType);
        
        $bits .= $this->ue(0);
        
        $bits .= $this->u($this->frameNum, 8);
        
        $bits .= $this->ue(0);
        $bits .= '0';
        $bits .= '0';
        
        $bits .= $this->se($this->qp - 26);
        
        $bits .= $this->ue(0);
        
        $bits .= $this->se(0);
        $bits .= $this->se(0);
        
        $mbWidth  = (int)ceil($this->width / 16);
        $mbHeight = (int)ceil($this->height / 16);
        
        $ySize = $this->width * $this->height;
        $uvSize = $ySize / 4;
        $yPlane = substr($yuvData, 0, $ySize);
        $uPlane = substr($yuvData, $ySize, $uvSize);
        $vPlane = substr($yuvData, $ySize + $uvSize, $uvSize);
        
        for ($mbY = 0; $mbY < $mbHeight; $mbY++) {
            for ($mbX = 0; $mbX < $mbWidth; $mbX++) {
                $bits .= $this->ue(2);

                for ($subMb = 0; $subMb < 16; $subMb++) {
                    $bits .= $this->ue(0);
                }

                $mbData = $this->encodeIntraMB($mbX, $mbY, $yPlane, $uPlane, $vPlane);
                $bits .= $mbData;

                $bits .= '00';
            }
        }
        
        $bits .= '1';
        while (strlen($bits) % 8 != 0) {
            $bits .= '0';
        }
        
        return $this->bitsToBytes($bits);
    }

    private function encodeIntraMB(int $mbX, int $mbY, string $yPlane, string $uPlane, string $vPlane): string
    {
        $bits = '';
        
        for ($blockY = 0; $blockY < 4; $blockY++) {
            for ($blockX = 0; $blockX < 4; $blockX++) {
                $blockBits = $this->encodeIntraBlock($mbX, $mbY, $blockX, $blockY, $yPlane, 0);
                $bits .= $blockBits;
            }
        }
        
        $blockBits = $this->encodeIntraBlock($mbX, $mbY, 0, 0, $uPlane, 1);
        $bits .= $blockBits;
        
        $blockBits = $this->encodeIntraBlock($mbX, $mbY, 0, 0, $vPlane, 1);
        $bits .= $blockBits;
        
        return $bits;
    }

    private function encodeIntraBlock(int $mbX, int $mbY, int $blockX, int $blockY, string $plane, int $chroma): string
    {
        $bits = '';
        
        $step = $chroma ? 8 : 16;
        $width = $chroma ? $this->width / 2 : $this->width;
        
        $pixels = [];
        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                $pixelY = $mbY * $step + $blockY * 4 + $y;
                $pixelX = $mbX * $step + $blockX * 4 + $x;
                $idx = $pixelY * $width + $pixelX;
                $pixels[$y][$x] = ord($plane[$idx] ?? chr(128)) - 128;
            }
        }
        
        $predicted = $this->intraPredict($mbX, $mbY, $blockX, $blockY, $plane, $chroma);
        
        $residual = [];
        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                $residual[$y][$x] = $pixels[$y][$x] - $predicted[$y][$x];
            }
        }
        
        $dct = $this->dct4x4($residual);
        
        $quantized = $this->quantize4x4($dct, $chroma);
        
        $zigzag = $this->zigzag4x4($quantized);
        
        $bits .= $this->encodeCoefficients($zigzag);
        
        return $bits;
    }

    private function intraPredict(int $mbX, int $mbY, int $blockX, int $blockY, string $plane, int $chroma): array
    {
        $step = $chroma ? 8 : 16;
        $width = $chroma ? $this->width / 2 : $this->width;
        
        $predicted = array_fill(0, 4, array_fill(0, 4, 0));
        
        $leftAvailable = ($mbX > 0) || ($blockX > 0);
        $topAvailable = ($mbY > 0) || ($blockY > 0);
        
        $leftPixels = [];
        $topPixels = [];
        
        if ($leftAvailable) {
            $refX = $mbX * $step + ($blockX - 1) * 4 + 3;
            for ($y = 0; $y < 4; $y++) {
                $refY = $mbY * $step + $blockY * 4 + $y;
                $idx = $refY * $width + $refX;
                $leftPixels[$y] = ord($plane[$idx] ?? chr(128)) - 128;
            }
        }
        
        if ($topAvailable) {
            $refY = $mbY * $step + ($blockY - 1) * 4 + 3;
            for ($x = 0; $x < 4; $x++) {
                $refX = $mbX * $step + $blockX * 4 + $x;
                $idx = $refY * $width + $refX;
                $topPixels[$x] = ord($plane[$idx] ?? chr(128)) - 128;
            }
        }
        
        if ($leftAvailable && $topAvailable) {
            $avg = (array_sum($leftPixels) + array_sum($topPixels)) / 8;
            for ($y = 0; $y < 4; $y++) {
                for ($x = 0; $x < 4; $x++) {
                    $predicted[$y][$x] = (int)round($avg);
                }
            }
        } elseif ($leftAvailable) {
            $avg = array_sum($leftPixels) / 4;
            for ($y = 0; $y < 4; $y++) {
                for ($x = 0; $x < 4; $x++) {
                    $predicted[$y][$x] = (int)round($avg);
                }
            }
        } elseif ($topAvailable) {
            $avg = array_sum($topPixels) / 4;
            for ($y = 0; $y < 4; $y++) {
                for ($x = 0; $x < 4; $x++) {
                    $predicted[$y][$x] = (int)round($avg);
                }
            }
        }
        
        return $predicted;
    }

    private function dct4x4(array $block): array
    {
        $result = [];
        $temp = [];
        
        for ($y = 0; $y < 4; $y++) {
            $s0 = $block[$y][0] + $block[$y][2];
            $s1 = $block[$y][1] + $block[$y][3];
            $s2 = $block[$y][1] - $block[$y][3];
            $s3 = $block[$y][0] - $block[$y][2];
            
            $temp[$y][0] = (int)(($s0 + $s1) * 0.5);
            $temp[$y][1] = (int)((($s3 + $s2) * 0.7071) * 0.5);
            $temp[$y][2] = (int)(($s0 - $s1) * 0.5);
            $temp[$y][3] = (int)((($s3 - $s2) * 0.7071) * 0.5);
        }
        
        for ($x = 0; $x < 4; $x++) {
            $s0 = $temp[0][$x] + $temp[2][$x];
            $s1 = $temp[1][$x] + $temp[3][$x];
            $s2 = $temp[1][$x] - $temp[3][$x];
            $s3 = $temp[0][$x] - $temp[2][$x];
            
            $result[0][$x] = (int)(($s0 + $s1) * 0.5);
            $result[1][$x] = (int)((($s3 + $s2) * 0.7071) * 0.5);
            $result[2][$x] = (int)(($s0 - $s1) * 0.5);
            $result[3][$x] = (int)((($s3 - $s2) * 0.7071) * 0.5);
        }
        
        return $result;
    }

    private function quantize4x4(array $block, int $chroma): array
    {
        $result = [];
        $scale = 1 << (int)floor($this->qp / 6);
        $qpRem = $this->qp % 6;
        
        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                $qIdx = $y * 4 + $x;
                $qStep = $this->quantMatrix[$chroma][$qIdx] * $scale;
                
                if ($qpRem > 0) {
                    $qStep = (int)round($qStep * pow(1.122, $qpRem));
                }
                
                $result[$y][$x] = (int)round($block[$y][$x] / $qStep);
            }
        }
        
        return $result;
    }

    private function zigzag4x4(array $block): array
    {
        $zigzagOrder = [
            [0, 0], [0, 1], [1, 0], [2, 0], [1, 1], [0, 2], [0, 3], [1, 2],
            [2, 1], [3, 0], [3, 1], [2, 2], [1, 3], [2, 3], [3, 2], [3, 3]
        ];
        
        $result = [];
        foreach ($zigzagOrder as $pos) {
            $result[] = $block[$pos[0]][$pos[1]];
        }
        
        return $result;
    }

    private function encodeCoefficients(array $coeffs): string
    {
        $bits = '';
        
        $nonZeroCount = 0;
        foreach ($coeffs as $coeff) {
            if ($coeff != 0) {
                $nonZeroCount++;
            }
        }
        
        if ($nonZeroCount == 0) {
            $bits .= '1';
            return $bits;
        }
        
        $trailingOnes = 0;
        $lastNonZeroIdx = 15;
        for ($i = 15; $i >= 0; $i--) {
            if ($coeffs[$i] != 0) {
                $lastNonZeroIdx = $i;
                break;
            }
        }
        
        for ($i = $lastNonZeroIdx; $i >= max(0, $lastNonZeroIdx - 2); $i--) {
            if (abs($coeffs[$i]) == 1) {
                $trailingOnes++;
            } else {
                break;
            }
        }
        
        $remaining = $nonZeroCount - $trailingOnes;
        
        if ($remaining <= 10) {
            $coeffToken = ($remaining - 1) * 4 + $trailingOnes;
        } else {
            $coeffToken = 39 + $remaining;
        }
        
        $bits .= $this->ue($coeffToken);
        
        for ($i = $lastNonZeroIdx; $i >= max(0, $lastNonZeroIdx - $trailingOnes + 1); $i--) {
            if ($coeffs[$i] == -1) {
                $bits .= '1';
            } else {
                $bits .= '0';
            }
        }
        
        for ($i = $lastNonZeroIdx - $trailingOnes; $i >= 0; $i--) {
            if ($coeffs[$i] != 0) {
                $level = abs($coeffs[$i]);
                $levelPrefix = 0;
                
                while ($level > (1 << ($levelPrefix + 1)) + 1) {
                    $levelPrefix++;
                }
                
                $bits .= str_repeat('1', $levelPrefix) . '0';
                
                $suffixBits = $levelPrefix;
                if ($suffixBits > 0) {
                    $levelSuffix = $level - (1 << $levelPrefix);
                    $bits .= str_pad(decbin($levelSuffix), $suffixBits, '0', STR_PAD_LEFT);
                }
                
                if ($coeffs[$i] < 0) {
                    $bits .= '1';
                } else {
                    $bits .= '0';
                }
            }
        }
        
        $totalZeros = $lastNonZeroIdx + 1 - $nonZeroCount;
        
        if ($lastNonZeroIdx < 15) {
            $bits .= $this->ue($totalZeros);
            
            $remaining = $nonZeroCount;
            $zeroRun = 0;
            
            for ($i = 0; $i <= $totalZeros; $i++) {
                if ($remaining > 0) {
                    if ($i < $totalZeros) {
                        $runBefore = 0;
                        while ($i + $runBefore < $totalZeros && 
                               $coeffs[$totalZeros - $i - $runBefore - 1] == 0) {
                            $runBefore++;
                        }
                        $bits .= $this->ue($runBefore);
                        $i += $runBefore;
                    }
                    $remaining--;
                }
            }
        }
        
        return $bits;
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