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
        return "\x67\x42\xc0\x1e\xdc\x0a\x02\xff\x96\x10\x00\x00\x00\x30\x01\x00\x00\x00\x30\x3c\x0f\x16\x2f\x8";
    }

    public function generatePPS(): string
    {
        return "\x68\xce\x0f\x2c\x80";
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
