<?php

namespace Xiaosongshu\Flv2mp4\Codec;

class H264TinyEncoder {
    private $width;
    private $height;
    private $idrPicId = 0;

    public function __construct(int $width, int $height) {
        $this->width = $width;
        $this->height = $height;
    }

    private function u(int $v, int $n): string {
        $r = '';
        for ($i = $n - 1; $i >= 0; $i--) {
            $r .= (($v >> $i) & 1) ? '1' : '0';
        }
        return $r;
    }

    private function ue(int $v): string {
        if ($v == 0) return '1';
        $bin = decbin($v + 1);
        return str_repeat('0', strlen($bin) - 1) . $bin;
    }

    private function se(int $v): string {
        if ($v <= 0) return $this->ue(-$v * 2);
        return $this->ue($v * 2 - 1);
    }

    private function bitsToBytes(string $bits): string {
        $bytes = '';
        for ($i = 0; $i < strlen($bits); $i += 8) {
            $chunk = substr($bits, $i, 8);
            if (strlen($chunk) < 8) $chunk = str_pad($chunk, 8, '0');
            $bytes .= chr(bindec($chunk));
        }
        return $bytes;
    }

    private function rbspToNal(string $rbsp, int $type): string {
        $ref = ($type === 7 || $type === 8 || $type === 5) ? 3 : 2;
        $header = chr(($ref << 5) | $type);
        $output = '';
        $zc = 0;
        for ($i = 0; $i < strlen($rbsp); $i++) {
            $b = ord($rbsp[$i]);
            if ($zc >= 2 && $b <= 3) {
                $output .= chr(0x03);
                $zc = 0;
            }
            $output .= chr($b);
            $zc = ($b == 0) ? $zc + 1 : 0;
        }
        return "\x00\x00\x00\x01" . $header . $output;
    }

    public function generateSPS(): string {
        $picWidthInMbs = (int)ceil($this->width / 16);
        $picHeightInMapUnits = (int)ceil($this->height / 16);

        $bits = '';
        $bits .= $this->u(66, 8);
        $bits .= '11000000';
        $bits .= $this->u(10, 8);
        $bits .= $this->ue(0);
        $bits .= $this->ue(3);
        $bits .= $this->ue(0);
        $bits .= $this->ue(0);
        $bits .= $this->ue(1);
        $bits .= '0';
        $bits .= $this->ue($picWidthInMbs - 1);
        $bits .= $this->ue($picHeightInMapUnits - 1);
        $bits .= '1';
        $bits .= '0';
        $bits .= '0';
        $bits .= '1';
        $bits .= '0000000001';
        $bits .= '1';
        $bits .= $this->ue(0);
        $bits .= $this->ue(0);
        $bits .= $this->ue(16);
        $bits .= $this->ue(16);
        $bits .= $this->ue(0);
        $bits .= $this->ue(1);
        $bits .= '1';
        while (strlen($bits) % 8 != 0) $bits .= '0';

        return $this->rbspToNal($this->bitsToBytes($bits), 7);
    }

    public function generatePPS(): string {
        $bits = '';
        $bits .= $this->ue(0);
        $bits .= $this->ue(0);
        $bits .= '0';
        $bits .= '0';
        $bits .= $this->ue(0);
        $bits .= $this->ue(0);
        $bits .= $this->ue(0);
        $bits .= '0';
        $bits .= '00';
        $bits .= $this->se(22 - 26);
        $bits .= $this->se(0);
        $bits .= $this->se(0);
        $bits .= '0';
        $bits .= '0';
        $bits .= '0';
        $bits .= '1';
        while (strlen($bits) % 8 != 0) $bits .= '0';

        return $this->rbspToNal($this->bitsToBytes($bits), 8);
    }

    public function encodeFrame(string $yuvData, bool $isKeyframe): array {
        $nalUnits = [];

        if ($isKeyframe) {
            $nalUnits[] = $this->generateSPS();
            $nalUnits[] = $this->generatePPS();
        }

        $bits = '';

        $bits .= $this->ue(0);
        $bits .= $this->ue(2);
        $bits .= $this->ue(0);

        $bits .= $this->u(0, 7);
        $bits .= $this->ue($this->idrPicId);

        $bits .= $this->u($this->idrPicId & 15, 4);

        $bits .= '0';
        $bits .= '0';

        $bits .= $this->se(0);

        $this->idrPicId++;

        $mbW = (int)ceil($this->width / 16);
        $mbH = (int)ceil($this->height / 16);

        $ySize = $this->width * $this->height;
        $uvSize = (int)($ySize / 4);
        $y = substr($yuvData, 0, $ySize);
        $u = substr($yuvData, $ySize, $uvSize);
        $v = substr($yuvData, $ySize + $uvSize);

        for ($my = 0; $my < $mbH; $my++) {
            for ($mx = 0; $mx < $mbW; $mx++) {
                $bits .= $this->ue(25);

                while (strlen($bits) % 8 != 0) $bits .= '0';

                for ($yy = 0; $yy < 16; $yy++) {
                    for ($xx = 0; $xx < 16; $xx++) {
                        $px = $mx * 16 + $xx;
                        $py = $my * 16 + $yy;
                        if ($px < $this->width && $py < $this->height) {
                            $idx = $py * $this->width + $px;
                            $bits .= $this->u(ord($y[$idx]), 8);
                        } else {
                            $bits .= $this->u(128, 8);
                        }
                    }
                }

                $cw = (int)($this->width / 2);
                $ch = (int)($this->height / 2);
                for ($yy = 0; $yy < 8; $yy++) {
                    for ($xx = 0; $xx < 8; $xx++) {
                        $px = $mx * 8 + $xx;
                        $py = $my * 8 + $yy;
                        if ($px < $cw && $py < $ch) {
                            $idx = $py * $cw + $px;
                            $bits .= $this->u(ord($u[$idx]), 8);
                        } else {
                            $bits .= $this->u(128, 8);
                        }
                    }
                }

                for ($yy = 0; $yy < 8; $yy++) {
                    for ($xx = 0; $xx < 8; $xx++) {
                        $px = $mx * 8 + $xx;
                        $py = $my * 8 + $yy;
                        if ($px < $cw && $py < $ch) {
                            $idx = $py * $cw + $px;
                            $bits .= $this->u(ord($v[$idx]), 8);
                        } else {
                            $bits .= $this->u(128, 8);
                        }
                    }
                }
            }
        }

        $bits .= '1';
        while (strlen($bits) % 8 != 0) $bits .= '0';

        $rbsp = $this->bitsToBytes($bits);
        $nal = $this->rbspToNal($rbsp, 5);
        $nalUnits[] = $nal;

        return $nalUnits;
    }
}

?>
