<?php

namespace Xiaosongshu\Flv2mp4\Codec;

class H264Encoder
{
    private int $width = 640;
    private int $height = 360;
    private int $fps = 30;
    private int $bitrate = 600000;
    private int $qp = 26;
    private int $gopSize = 60;

    private int $frameNum = 0;
    private int $idrPicId = 0;
    private string $spsData = '';
    private string $ppsData = '';

    private int $picWidthInMbs = 0;
    private int $picHeightInMbs = 0;

    private array $quantMatrix = [];

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

    public function encodeFrame(string $yuvData, bool $isKeyframe = false): array
    {
        $nalUnits = [];

        if ($isKeyframe || $this->frameNum == 0) {
            $nalUnits[] = $this->generateSPS();
            $nalUnits[] = $this->generatePPS();
            $this->idrPicId = ($this->idrPicId + 1) % 65536;
        }

        $sliceData = $this->encodeSlice($yuvData, $isKeyframe);
        $nalUnits[] = $sliceData;

        $this->frameNum++;

        return $nalUnits;
    }

    public function generateSPS(): string
    {
        $mbWidth = (int)ceil($this->width / 16);
        $mbHeight = (int)ceil($this->height / 16);
        $this->picWidthInMbs = $mbWidth;
        $this->picHeightInMbs = $mbHeight;

        $bits = '';

        $bits .= $this->uToBinary(66, 8);
        $bits .= '00000000';
        $bits .= $this->uToBinary(30, 8);

        $bits .= $this->ueToBinary(0);
        $bits .= $this->ueToBinary(1);
        $bits .= $this->ueToBinary(0);
        $bits .= $this->ueToBinary(0);
        $bits .= '0';
        $bits .= '0';

        $bits .= $this->ueToBinary(0);
        $bits .= $this->ueToBinary(0);

        $bits .= $this->ueToBinary(4);

        $bits .= $this->ueToBinary(1);
        $bits .= '0';
        $bits .= $this->ueToBinary($mbWidth - 1);
        $bits .= $this->ueToBinary($mbHeight - 1);
        $bits .= '1';
        $bits .= '0';
        $bits .= '0';
        $bits .= '0';

        $bits .= '1';
        while (strlen($bits) % 8 != 0) $bits .= '0';

        $spsData = $this->bitsToBytes($bits);
        $sps = "\x67" . $spsData;
        $this->spsData = $sps;
        return $sps;
    }

    public function generatePPS(): string
    {
        $bits = '';

        $bits .= $this->ueToBinary(0);
        $bits .= $this->ueToBinary(0);
        $bits .= '0';
        $bits .= '0';
        $bits .= $this->ueToBinary(0);

        $bits .= $this->ueToBinary(0);
        $bits .= $this->ueToBinary(0);

        $bits .= '0';
        $bits .= '00';

        $bits .= $this->seToBinary(0);
        $bits .= $this->seToBinary(0);
        $bits .= $this->seToBinary(0);

        $bits .= '0';
        $bits .= '0';
        $bits .= '0';

        $bits .= '1';
        while (strlen($bits) % 8 != 0) $bits .= '0';

        $ppsData = $this->bitsToBytes($bits);
        $pps = "\x68" . $ppsData;
        $this->ppsData = $pps;
        return $pps;
    }

    private function encodeSlice(string $yuvData, bool $isKeyframe): string
    {
        $nalType = $isKeyframe ? 5 : 1;
        $sliceHeader = chr(($nalType << 1) | 1);

        $bits = '';

        $bits .= $this->ueToBinary(0);

        $sliceType = $isKeyframe ? 2 : 0;
        $bits .= $this->ueToBinary($sliceType);

        $bits .= $this->ueToBinary(0);

        $frameNumBits = 4;
        $bits .= $this->uToBinary($this->frameNum % (1 << $frameNumBits), $frameNumBits);

        if ($isKeyframe) {
            $bits .= $this->ueToBinary($this->idrPicId);
            $bits .= '0';
            $bits .= '0';
        } else {
            $pocLsb = $this->frameNum % 256;
            $bits .= $this->uToBinary($pocLsb, 8);
        }

        $bits .= $this->seToBinary(0);

        $mbCount = $this->picWidthInMbs * $this->picHeightInMbs;

        $yPlane = substr($yuvData, 0, $this->width * $this->height);
        $uvSize = $this->width * $this->height / 4;
        $uPlane = substr($yuvData, $this->width * $this->height, $uvSize);
        $vPlane = substr($yuvData, $this->width * $this->height + $uvSize, $uvSize);

        for ($i = 0; $i < $mbCount; $i++) {
            $mbX = $i % $this->picWidthInMbs;
            $mbY = (int)($i / $this->picWidthInMbs);

            if ($isKeyframe) {
                $this->encodePcmMacroblock($bits, $mbX, $mbY, $yPlane, $uPlane, $vPlane);
            } else {
                $this->encodeMacroblock($bits, $mbX, $mbY, $yPlane, $uPlane, $vPlane);
            }
        }

        $sliceData = $this->bitsToBytes($bits);

        return $sliceHeader . $sliceData;
    }

    private function encodePcmMacroblock(string &$bits, int $mbX, int $mbY, string $yPlane, string $uPlane, string $vPlane): void
    {
        $bits .= $this->ueToBinary(25);

        while (strlen($bits) % 8 != 0) {
            $bits .= '0';
        }

        for ($y = 0; $y < 16; $y++) {
            for ($x = 0; $x < 16; $x++) {
                $idx = ($mbY * 16 + $y) * $this->width + ($mbX * 16 + $x);
                $val = ($idx < strlen($yPlane)) ? ord($yPlane[$idx]) : 128;
                $bits .= $this->uToBinary($val, 8);
            }
        }

        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $idx = ($mbY * 8 + $y) * ($this->width / 2) + ($mbX * 8 + $x);
                $val = ($idx < strlen($uPlane)) ? ord($uPlane[$idx]) : 128;
                $bits .= $this->uToBinary($val, 8);
            }
        }

        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $idx = ($mbY * 8 + $y) * ($this->width / 2) + ($mbX * 8 + $x);
                $val = ($idx < strlen($vPlane)) ? ord($vPlane[$idx]) : 128;
                $bits .= $this->uToBinary($val, 8);
            }
        }
    }

    private function encodeMacroblock(string &$bits, int $mbX, int $mbY, string $yPlane, string $uPlane, string $vPlane): void
    {
        $bits .= $this->ueToBinary(0);

        for ($i = 0; $i < 18; $i++) {
            $bits .= '00';
        }
    }

    private function encodeLumaBlock(string &$bits, int $mbX, int $mbY, int $blockX, int $blockY, string $yPlane): void
    {
        $pixels = [];
        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                $pixelY = $mbY * 16 + $blockY * 4 + $y;
                $pixelX = $mbX * 16 + $blockX * 4 + $x;
                $idx = $pixelY * $this->width + $pixelX;
                if ($idx >= 0 && $idx < strlen($yPlane)) {
                    $pixels[$y][$x] = ord($yPlane[$idx]) - 128;
                } else {
                    $pixels[$y][$x] = 0;
                }
            }
        }

        $pred = $this->intraDCPredict($pixels, $mbX, $mbY, $blockX, $blockY, $yPlane);

        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                $pixels[$y][$x] -= $pred;
            }
        }

        $transformed = $this->dct4x4($pixels);

        $quantized = $this->quantize4x4($transformed, 0);

        $zigzag = $this->zigzag4x4($quantized);

        $this->cavlcEncode($bits, $zigzag);
    }

    private function encodeChromaBlock(string &$bits, int $mbX, int $mbY, string $uvPlane): void
    {
        $pixels = [];
        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                $pixelY = $mbY * 8 + $y;
                $pixelX = $mbX * 8 + $x;
                $idx = $pixelY * ($this->width / 2) + $pixelX;
                if ($idx >= 0 && $idx < strlen($uvPlane)) {
                    $pixels[$y][$x] = ord($uvPlane[$idx]) - 128;
                } else {
                    $pixels[$y][$x] = 0;
                }
            }
        }

        $pred = $this->intraDCPredictChroma($pixels, $mbX, $mbY, $uvPlane);

        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                $pixels[$y][$x] -= $pred;
            }
        }

        $transformed = $this->dct4x4($pixels);

        $quantized = $this->quantize4x4($transformed, 1);

        $zigzag = $this->zigzag4x4($quantized);

        $this->cavlcEncode($bits, $zigzag);
    }

    private function intraDCPredict(array $block, int $mbX, int $mbY, int $blockX, int $blockY, string $yPlane): int
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

    private function intraDCPredictChroma(array $block, int $mbX, int $mbY, string $uvPlane): int
    {
        return 0;
    }

    private function dct4x4(array $block): array
    {
        $result = [];
        $temp = [];

        for ($y = 0; $y < 4; $y++) {
            $s0 = $block[$y][0] + $block[$y][3];
            $s1 = $block[$y][1] + $block[$y][2];
            $s2 = $block[$y][1] - $block[$y][2];
            $s3 = $block[$y][0] - $block[$y][3];

            $temp[$y][0] = $s0 + $s1;
            $temp[$y][1] = (int)(($s3 + $s2) * 0.7071);
            $temp[$y][2] = $s0 - $s1;
            $temp[$y][3] = (int)(($s3 - $s2) * 0.7071);
        }

        for ($x = 0; $x < 4; $x++) {
            $s0 = $temp[0][$x] + $temp[3][$x];
            $s1 = $temp[1][$x] + $temp[2][$x];
            $s2 = $temp[1][$x] - $temp[2][$x];
            $s3 = $temp[0][$x] - $temp[3][$x];

            $result[0][$x] = $s0 + $s1;
            $result[1][$x] = (int)(($s3 + $s2) * 0.7071);
            $result[2][$x] = $s0 - $s1;
            $result[3][$x] = (int)(($s3 - $s2) * 0.7071);
        }

        return $result;
    }

    private function quantize4x4(array $block, int $chroma): array
    {
        $result = [];
        $qp = $this->qp;
        $scale = 1 << (int)floor($qp / 6);
        $qpRem = $qp % 6;

        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                $qIdx = $y * 4 + $x;
                $qStep = $this->quantMatrix[$chroma][$qIdx] * $scale;

                if ($qpRem > 0) {
                    $qStep = (int)round($qStep * pow(1.122, $qpRem));
                }

                if ($qStep == 0) $qStep = 1;

                $val = $block[$y][$x];
                if ($val >= 0) {
                    $result[$y][$x] = (int)(($val + $qStep / 2) / $qStep);
                } else {
                    $result[$y][$x] = -(int)((abs($val) + $qStep / 2) / $qStep);
                }
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

    private function cavlcEncode(string &$bits, array $coefficients): void
    {
        $nonZeroCount = 0;
        $trailingOnes = 0;
        $lastNonZero = -1;

        for ($i = 15; $i >= 0; $i--) {
            if ($coefficients[$i] != 0) {
                if ($lastNonZero < 0) $lastNonZero = $i;
                $nonZeroCount++;
                $absLevel = abs($coefficients[$i]);
                if ($absLevel == 1 && $trailingOnes < 3) $trailingOnes++;
            }
        }

        if ($nonZeroCount == 0) {
            $bits .= '00';
            return;
        }

        $trailingOnes = min(3, $trailingOnes);

        if ($nonZeroCount <= 10) {
            $coeffToken = ($nonZeroCount - 1) * 4 + $trailingOnes;
        } else {
            $coeffToken = 36 + ($nonZeroCount - 11);
        }

        $bits .= $this->ueToBinary($coeffToken);

        for ($i = $lastNonZero; $i >= 0; $i--) {
            if (abs($coefficients[$i]) == 1 && $trailingOnes > 0) {
                $bits .= ($coefficients[$i] > 0) ? '0' : '1';
                $trailingOnes--;
            }
        }

        $levels = [];
        for ($i = $lastNonZero; $i >= 0; $i--) {
            if ($coefficients[$i] != 0 && abs($coefficients[$i]) > 1) {
                $levels[] = $coefficients[$i];
            }
        }

        foreach ($levels as $level) {
            $absLevel = abs($level);
            if ($absLevel <= 3) {
                $bits .= $this->uToBinary($absLevel - 2, 1);
            } else {
                $bits .= '1';
                $bits .= $this->ueToBinary($absLevel - 3);
            }
            $bits .= ($level > 0) ? '1' : '0';
        }

        $totalZeros = $lastNonZero;
        $bits .= $this->ueToBinary($totalZeros);

        $zeroRun = 0;
        $nonZeroSeen = 0;

        for ($i = 0; $i <= $lastNonZero; $i++) {
            if ($coefficients[$i] == 0) {
                $zeroRun++;
            } else {
                if ($nonZeroSeen < $nonZeroCount - 1) {
                    $bits .= $this->ueToBinary($zeroRun);
                }
                $zeroRun = 0;
                $nonZeroSeen++;
            }
        }
    }

    public function setResolution(int $width, int $height): void
    {
        $this->width = $width;
        $this->height = $height;
    }

    public function setBitrate(int $bitrate): void
    {
        $this->bitrate = $bitrate;
        $this->qp = max(10, 51 - (int)($bitrate / 100000));
    }

    public function setFps(int $fps): void
    {
        $this->fps = $fps;
        $this->gopSize = $fps * 2;
    }

    private function ueToBinary(int $value): string
    {
        $codeNum = $value + 1;
        $leadingZeroBits = (int)floor(log($codeNum, 2));
        $code = str_repeat('0', $leadingZeroBits) . '1' .
            substr(decbin($codeNum), 1);
        return $code;
    }

    private function seToBinary(int $value): string
    {
        if ($value <= 0) {
            $codeNum = -$value * 2;
        } else {
            $codeNum = $value * 2 - 1;
        }
        return $this->ueToBinary($codeNum);
    }

    private function uToBinary(int $value, int $n): string
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