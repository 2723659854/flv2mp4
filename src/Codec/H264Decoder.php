<?php

namespace Xiaosongshu\Flv2mp4\Codec;

class H264Decoder
{
    private int $width = 0;
    private int $height = 0;
    private int $picWidthInMbs = 0;
    private int $picHeightInMbs = 0;

    private array $quantMatrix = [];

    private string $currentYuv = '';
    private string $prevFrameYuv = '';

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

    public function decode(array $nalUnits): ?array
    {
        $this->parseNalUnits($nalUnits);

        if ($this->width == 0 || $this->height == 0) {
            return null;
        }

        $yuvSize = $this->width * $this->height * 3 / 2;
        $yuvData = str_repeat(chr(128), $yuvSize);

        $this->currentYuv = $yuvData;

        foreach ($nalUnits as $nal) {
            $nalType = ord($nal['data'][0]) & 0x1F;

            if ($nalType == 1 || $nalType == 5) {
                $this->decodeSlice($nal['data']);
            }
        }

        return [
            'data' => $this->currentYuv,
            'width' => $this->width,
            'height' => $this->height
        ];
    }

    private function parseNalUnits(array $nalUnits): void
    {
        foreach ($nalUnits as $nal) {
            $nalType = ord($nal['data'][0]) & 0x1F;

            switch ($nalType) {
                case 7:
                    $this->parseSPS(substr($nal['data'], 1));
                    break;
                case 8:
                    $this->parsePPS(substr($nal['data'], 1));
                    break;
            }
        }
    }

    private function parseSPS(string $spsData): void
    {
        $bits = $this->bytesToBits($spsData);
        $pos = 0;

        $profileIdc = $this->bitsToU($bits, $pos, 8);
        $pos += 8;

        $pos += 8;

        $levelIdc = $this->bitsToU($bits, $pos, 8);
        $pos += 8;

        $pos += $this->ueLength($bits, $pos);

        $chromaFormatIdc = $this->ueToVal($bits, $pos);
        $pos += $this->ueLength($bits, $pos);

        if ($chromaFormatIdc == 3) {
            $pos += 1;
        }

        $bitDepthLumaMinus8 = $this->ueToVal($bits, $pos);
        $pos += $this->ueLength($bits, $pos);

        $bitDepthChromaMinus8 = $this->ueToVal($bits, $pos);
        $pos += $this->ueLength($bits, $pos);

        $pos += 1;

        $pos += 1;

        $log2MaxFrameNumMinus4 = $this->ueToVal($bits, $pos);
        $pos += $this->ueLength($bits, $pos);

        $picOrderCntType = $this->ueToVal($bits, $pos);
        $pos += $this->ueLength($bits, $pos);

        if ($picOrderCntType == 0) {
            $log2MaxPicOrderCntLsbMinus4 = $this->ueToVal($bits, $pos);
            $pos += $this->ueLength($bits, $pos);
        } elseif ($picOrderCntType == 1) {
            $pos += 1;
            $pos += $this->seLength($bits, $pos);
            $pos += $this->seLength($bits, $pos);
            $numRefFramesInPicOrderCntCycle = $this->ueToVal($bits, $pos);
            $pos += $this->ueLength($bits, $pos);
            for ($i = 0; $i < $numRefFramesInPicOrderCntCycle; $i++) {
                $pos += $this->seLength($bits, $pos);
            }
        }

        $numRefFrames = $this->ueToVal($bits, $pos);
        $pos += $this->ueLength($bits, $pos);

        $gapsInFrameNumValueAllowedFlag = $this->bitsToU($bits, $pos, 1);
        $pos += 1;

        $picWidthInMbsMinus1 = $this->ueToVal($bits, $pos);
        $pos += $this->ueLength($bits, $pos);

        $picHeightInMapUnitsMinus1 = $this->ueToVal($bits, $pos);
        $pos += $this->ueLength($bits, $pos);

        $frameMbsOnlyFlag = $this->bitsToU($bits, $pos, 1);
        $pos += 1;

        $this->picWidthInMbs = $picWidthInMbsMinus1 + 1;
        $this->picHeightInMbs = $picHeightInMapUnitsMinus1 + 1;

        $this->width = $this->picWidthInMbs * 16;
        $this->height = $this->picHeightInMbs * 16;
    }

    private function parsePPS(string $ppsData): void
    {
    }

    private function decodeSlice(string $sliceData): void
    {
        $bits = $this->bytesToBits(substr($sliceData, 1));
        $pos = 0;

        $firstMbInSlice = $this->ueToVal($bits, $pos);
        $pos += $this->ueLength($bits, $pos);

        $sliceType = $this->ueToVal($bits, $pos);
        $pos += $this->ueLength($bits, $pos);

        $pos += $this->ueLength($bits, $pos);

        $frameNumBits = 4;
        $frameNum = $this->bitsToU($bits, $pos, $frameNumBits);
        $pos += $frameNumBits;

        if ($sliceType == 2 || $sliceType == 4 || $sliceType == 6) {
            $idrPicId = $this->ueToVal($bits, $pos);
            $pos += $this->ueLength($bits, $pos);
            $pos += 2;
        } else {
            $pos += 8;
        }

        $pos += $this->seLength($bits, $pos);

        $mbCount = $this->picWidthInMbs * $this->picHeightInMbs;

        for ($mbIdx = $firstMbInSlice; $mbIdx < $mbCount; $mbIdx++) {
            $mbX = $mbIdx % $this->picWidthInMbs;
            $mbY = (int)($mbIdx / $this->picWidthInMbs);

            $mbType = $this->ueToVal($bits, $pos);
            $pos += $this->ueLength($bits, $pos);

            $this->decodeMacroblock($bits, $pos, $mbX, $mbY);
        }
    }

    private function decodeMacroblock(string $bits, int &$pos, int $mbX, int $mbY): void
    {
        $yPlane = substr($this->currentYuv, 0, $this->width * $this->height);
        $uvSize = $this->width * $this->height / 4;
        $uPlane = substr($this->currentYuv, $this->width * $this->height, $uvSize);
        $vPlane = substr($this->currentYuv, $this->width * $this->height + $uvSize, $uvSize);

        for ($blockY = 0; $blockY < 4; $blockY++) {
            for ($blockX = 0; $blockX < 4; $blockX++) {
                $this->decodeLumaBlock($bits, $pos, $mbX, $mbY, $blockX, $blockY, $yPlane);
            }
        }

        $this->decodeChromaBlock($bits, $pos, $mbX, $mbY, $uPlane);
        $this->decodeChromaBlock($bits, $pos, $mbX, $mbY, $vPlane);

        $this->currentYuv = $yPlane . $uPlane . $vPlane;
    }

    private function decodeLumaBlock(string $bits, int &$pos, int $mbX, int $mbY, int $blockX, int $blockY, string &$yPlane): void
    {
        $coeffToken = $this->ueToVal($bits, $pos);
        $pos += $this->ueLength($bits, $pos);

        if ($coeffToken == 0) {
            return;
        }

        $nonZeroCount = 0;
        $trailingOnes = 0;

        if ($coeffToken <= 39) {
            $nonZeroCount = (int)($coeffToken / 4) + 1;
            $trailingOnes = $coeffToken % 4;
        } else {
            $nonZeroCount = $coeffToken - 39 + 10;
            $trailingOnes = 3;
        }

        $coefficients = array_fill(0, 16, 0);
        $coeffIdx = 15;

        for ($i = 0; $i < $trailingOnes; $i++) {
            $sign = $this->bitsToU($bits, $pos, 1);
            $pos += 1;
            $coefficients[$coeffIdx--] = $sign == 0 ? 1 : -1;
        }

        for ($i = 0; $i < $nonZeroCount - $trailingOnes; $i++) {
            $levelPrefix = 0;
            while ($pos < strlen($bits) && $this->bitsToU($bits, $pos, 1) == 1) {
                $levelPrefix++;
                $pos++;
            }
            $pos++;

            $levelSuffix = 0;
            $suffixBits = $levelPrefix;
            if ($suffixBits > 0) {
                $levelSuffix = $this->bitsToU($bits, $pos, $suffixBits);
                $pos += $suffixBits;
            }

            $level = (1 << $levelPrefix) + $levelSuffix + 1;

            $sign = $this->bitsToU($bits, $pos, 1);
            $pos += 1;

            $coefficients[$coeffIdx--] = $sign == 1 ? $level : -$level;
        }

        $totalZeros = $this->ueToVal($bits, $pos);
        $pos += $this->ueLength($bits, $pos);

        $remaining = $nonZeroCount;
        $zeroRun = 0;

        for ($i = 0; $i <= $totalZeros; $i++) {
            if ($remaining > 0) {
                if ($i < $totalZeros) {
                    $runBefore = $this->ueToVal($bits, $pos);
                    $pos += $this->ueLength($bits, $pos);
                    $zeroRun += $runBefore;
                }
                $coeffIdx = $totalZeros - $zeroRun;
                $zeroRun = 0;
                $remaining--;
            }
        }

        $dezigzag = $this->dezigzag4x4($coefficients);

        $dequantized = $this->dequantize4x4($dezigzag, 0);

        $idct = $this->idct4x4($dequantized);

        $pred = $this->intraDCPredict($mbX, $mbY, $blockX, $blockY, $yPlane);

        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                $pixelY = $mbY * 16 + $blockY * 4 + $y;
                $pixelX = $mbX * 16 + $blockX * 4 + $x;
                $idx = $pixelY * $this->width + $pixelX;
                $val = (int)($idct[$y][$x] + $pred + 128);
                $val = max(0, min(255, $val));
                $yPlane[$idx] = chr($val);
            }
        }
    }

    private function decodeChromaBlock(string $bits, int &$pos, int $mbX, int $mbY, string &$uvPlane): void
    {
        $coeffToken = $this->ueToVal($bits, $pos);
        $pos += $this->ueLength($bits, $pos - $this->ueLength($bits, $pos));

        if ($coeffToken == 0) {
            return;
        }

        $nonZeroCount = 0;
        $trailingOnes = 0;

        if ($coeffToken <= 39) {
            $nonZeroCount = (int)($coeffToken / 4) + 1;
            $trailingOnes = $coeffToken % 4;
        } else {
            $nonZeroCount = $coeffToken - 39 + 10;
            $trailingOnes = 3;
        }

        $coefficients = array_fill(0, 16, 0);
        $coeffIdx = 15;

        for ($i = 0; $i < $trailingOnes; $i++) {
            $sign = $this->bitsToU($bits, $pos, 1);
            $pos += 1;
            $coefficients[$coeffIdx--] = $sign == 1 ? 1 : -1;
        }

        for ($i = 0; $i < $nonZeroCount - $trailingOnes; $i++) {
            $levelPrefix = 0;
            while ($pos < strlen($bits) && $this->bitsToU($bits, $pos, 1) == 1) {
                $levelPrefix++;
                $pos++;
            }
            $pos++;

            $levelSuffix = 0;
            if ($levelPrefix > 0) {
                $suffixBits = $levelPrefix - 1;
                if ($suffixBits > 0) {
                    $levelSuffix = $this->bitsToU($bits, $pos, $suffixBits);
                    $pos += $suffixBits;
                }
            }

            $level = ($levelPrefix << ($levelPrefix > 0 ? $levelPrefix - 1 : 0)) + $levelSuffix + 2;

            $sign = $this->bitsToU($bits, $pos, 1);
            $pos += 1;

            $coefficients[$coeffIdx--] = $sign == 1 ? $level : -$level;
        }

        $totalZeros = $this->ueToVal($bits, $pos);
        $pos += $this->ueLength($bits, $pos - $this->ueLength($bits, $pos));

        $remaining = $nonZeroCount;
        for ($i = 0; $i < $totalZeros; $i++) {
            if ($remaining > 0) {
                $runBefore = $this->ueToVal($bits, $pos);
                $pos += $this->ueLength($bits, $pos - $this->ueLength($bits, $pos));
                $coeffIdx -= $runBefore + 1;
                $remaining--;
            }
        }

        $dezigzag = $this->dezigzag4x4($coefficients);

        $dequantized = $this->dequantize4x4($dezigzag, 1);

        $idct = $this->idct4x4($dequantized);

        $pred = 0;

        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                $pixelY = $mbY * 8 + $y;
                $pixelX = $mbX * 8 + $x;
                $idx = $pixelY * ($this->width / 2) + $pixelX;
                $val = (int)($idct[$y][$x] + $pred + 128);
                $val = max(0, min(255, $val));
                $uvPlane[$idx] = chr($val);
            }
        }
    }

    private function intraDCPredict(int $mbX, int $mbY, int $blockX, int $blockY, string $yPlane): int
    {
        $leftSum = 0;
        $leftCount = 0;
        $topSum = 0;
        $topCount = 0;

        if ($blockX > 0) {
            $refX = $mbX * 16 + ($blockX - 1) * 4 + 3;
            for ($y = 0; $y < 4; $y++) {
                $refY = $mbY * 16 + $blockY * 4 + $y;
                $idx = $refY * $this->width + $refX;
                if ($idx < strlen($yPlane)) {
                    $leftSum += ord($yPlane[$idx]) - 128;
                    $leftCount++;
                }
            }
        }

        if ($blockY > 0) {
            $refY = $mbY * 16 + ($blockY - 1) * 4 + 3;
            for ($x = 0; $x < 4; $x++) {
                $refX = $mbX * 16 + $blockX * 4 + $x;
                $idx = $refY * $this->width + $refX;
                if ($idx < strlen($yPlane)) {
                    $topSum += ord($yPlane[$idx]) - 128;
                    $topCount++;
                }
            }
        }

        if ($leftCount > 0 && $topCount > 0) {
            return (int)(($leftSum / $leftCount + $topSum / $topCount) / 2);
        } elseif ($leftCount > 0) {
            return (int)($leftSum / $leftCount);
        } elseif ($topCount > 0) {
            return (int)($topSum / $topCount);
        }

        return 0;
    }

    private function dezigzag4x4(array $coefficients): array
    {
        $zigzagOrder = [
            [0, 0], [0, 1], [1, 0], [2, 0], [1, 1], [0, 2], [0, 3], [1, 2],
            [2, 1], [3, 0], [3, 1], [2, 2], [1, 3], [2, 3], [3, 2], [3, 3]
        ];

        $result = array_fill(0, 4, array_fill(0, 4, 0));
        foreach ($zigzagOrder as $i => $pos) {
            $result[$pos[0]][$pos[1]] = $coefficients[$i];
        }

        return $result;
    }

    private function dequantize4x4(array $block, int $chroma): array
    {
        $result = [];
        $qp = 26;
        $scale = 1 << (int)floor($qp / 6);
        $qpRem = $qp % 6;

        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                $qIdx = $y * 4 + $x;
                $qStep = $this->quantMatrix[$chroma][$qIdx] * $scale;

                if ($qpRem > 0) {
                    $qStep = (int)round($qStep * pow(1.122, $qpRem));
                }

                $result[$y][$x] = $block[$y][$x] * $qStep;
            }
        }

        return $result;
    }

    private function idct4x4(array $block): array
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

    private function bytesToBits(string $bytes): string
    {
        $bits = '';
        for ($i = 0; $i < strlen($bytes); $i++) {
            $byte = ord($bytes[$i]);
            for ($j = 7; $j >= 0; $j--) {
                $bits .= (($byte >> $j) & 0x01) ? '1' : '0';
            }
        }
        return $bits;
    }

    private function bitsToU(string $bits, int $pos, int $n): int
    {
        if ($pos + $n > strlen($bits)) {
            return 0;
        }
        return bindec(substr($bits, $pos, $n));
    }

    private function ueToVal(string $bits, int $pos): int
    {
        $leadingZeroBits = 0;
        while ($pos + $leadingZeroBits < strlen($bits) && $bits[$pos + $leadingZeroBits] == '0') {
            $leadingZeroBits++;
        }

        if ($pos + $leadingZeroBits >= strlen($bits)) {
            return 0;
        }

        $codeNum = bindec('1' . substr($bits, $pos + $leadingZeroBits + 1, $leadingZeroBits));
        return $codeNum - 1;
    }

    private function ueLength(string $bits, int $pos): int
    {
        $leadingZeroBits = 0;
        while ($pos + $leadingZeroBits < strlen($bits) && $bits[$pos + $leadingZeroBits] == '0') {
            $leadingZeroBits++;
        }
        return $leadingZeroBits * 2 + 1;
    }

    private function seLength(string $bits, int $pos): int
    {
        return $this->ueLength($bits, $pos);
    }

    public function getWidth(): int
    {
        return $this->width;
    }

    public function getHeight(): int
    {
        return $this->height;
    }
}