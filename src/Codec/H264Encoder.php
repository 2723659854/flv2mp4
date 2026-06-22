<?php

namespace Xiaosongshu\Flv2mp4\Codec;

class H264Encoder
{
    private int $width = 640;
    private int $height = 360;
    private int $fps = 30;
    private int $bitrate = 500000;
    
    private int $frameNum = 0;

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
        return "\x27\x42\x40\x1e\x96\x54\x05\x01\x78\x00";
    }

    public function generatePPS(): string
    {
        return "\x28\xcb\x40";
    }

    private function encodeSlice(string $yuvData, bool $isKeyframe): string
    {
        $nalType = $isKeyframe ? 5 : 1;
        $refIdc = $isKeyframe ? 3 : 0;
        $nalHeader = chr((($refIdc & 0x03) << 5) | ($nalType & 0x1F));

        $bits = '';

        $bits .= $this->ue(0);

        $bits .= $this->ue(2);

        $bits .= $this->ue(0);

        $bits .= $this->u($this->frameNum % 32, 5);

        $bits .= $this->ue(0);
        $bits .= '0';
        $bits .= '0';

        $bits .= $this->se(0);

        $bits .= '0';

        $mbWidth = (int)ceil($this->width / 16);
        $mbHeight = (int)ceil($this->height / 16);
        $mbCount = $mbWidth * $mbHeight;

        $yPlaneSize = $this->width * $this->height;
        $uvSize = (int)($this->width * $this->height / 4);
        $yPlane = substr($yuvData, 0, $yPlaneSize);
        $uPlane = substr($yuvData, $yPlaneSize, $uvSize);
        $vPlane = substr($yuvData, $yPlaneSize + $uvSize, $uvSize);

        for ($i = 0; $i < $mbCount; $i++) {
            $mbX = $i % $mbWidth;
            $mbY = (int)($i / $mbWidth);

            $bits .= $this->ue(25);

            while (strlen($bits) % 8 != 0) {
                $bits .= '0';
            }

            for ($y = 0; $y < 16; $y++) {
                for ($x = 0; $x < 16; $x++) {
                    $pixelY = $mbY * 16 + $y;
                    $pixelX = $mbX * 16 + $x;
                    $idx = $pixelY * $this->width + $pixelX;
                    $val = ($idx < strlen($yPlane)) ? ord($yPlane[$idx]) : 128;
                    $bits .= $this->u($val, 8);
                }
            }

            $uvWidth = (int)($this->width / 2);

            for ($y = 0; $y < 8; $y++) {
                for ($x = 0; $x < 8; $x++) {
                    $pixelY = $mbY * 8 + $y;
                    $pixelX = $mbX * 8 + $x;
                    $idx = $pixelY * $uvWidth + $pixelX;
                    $val = ($idx < strlen($uPlane)) ? ord($uPlane[$idx]) : 128;
                    $bits .= $this->u($val, 8);
                }
            }

            for ($y = 0; $y < 8; $y++) {
                for ($x = 0; $x < 8; $x++) {
                    $pixelY = $mbY * 8 + $y;
                    $pixelX = $mbX * 8 + $x;
                    $idx = $pixelY * $uvWidth + $pixelX;
                    $val = ($idx < strlen($vPlane)) ? ord($vPlane[$idx]) : 128;
                    $bits .= $this->u($val, 8);
                }
            }
        }

        $sliceData = $this->bitsToBytes($bits);
        return $nalHeader . $sliceData;
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
