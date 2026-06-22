<?php

namespace Xiaosongshu\Flv2mp4\Codec;

class H264Encoder
{
    private int $width = 640;
    private int $height = 360;
    private int $fps = 30;
    private int $bitrate = 500000;
    private int $qp = 28;

    private int $frameNum = 0;
    private int $idrPicId = 0;

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
            $this->idrPicId = 0;
        }

        $sliceData = $this->encodeSlice($yuvData, $isKeyframe);
        $nalUnits[] = $sliceData;

        $this->frameNum++;
        $this->idrPicId++;

        return $nalUnits;
    }

    public function generateSPS(): string
    {
        $profileIdc = 66;
        $levelIdc = 30;

        $picWidthInMbs = (int)ceil($this->width / 16);
        $picHeightInMbs = (int)ceil($this->height / 16);

        $bits = '';
        $bits .= $this->u($profileIdc, 8);
        $bits .= '1';
        $bits .= '0';
        $bits .= '0';
        $bits .= '0';
        $bits .= '0';
        $bits .= '0';
        $bits .= '00';
        $bits .= $this->u($levelIdc, 8);
        $bits .= $this->ue(0);
        $bits .= $this->ue(0);
        $bits .= $this->ue(2);
        $bits .= $this->ue(0);
        $bits .= '0';
        $bits .= $this->ue($picWidthInMbs - 1);
        $bits .= $this->ue($picHeightInMbs - 1);
        $bits .= '1';
        $bits .= '1';
        $bits .= '0';
        $bits .= '0';

        $bits .= '1';
        while (strlen($bits) % 8 != 0) $bits .= '0';

        return $this->rbspToNal($this->bitsToBytes($bits), 7);
    }

    public function generatePPS(): string
    {
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
        $bits .= $this->se($this->qp - 26);
        $bits .= $this->se(0);
        $bits .= $this->se(0);
        $bits .= '0';
        $bits .= '0';
        $bits .= '0';

        $bits .= '1';
        while (strlen($bits) % 8 != 0) $bits .= '0';

        return $this->rbspToNal($this->bitsToBytes($bits), 8);
    }

    private function encodeSlice(string $yuvData, bool $isKeyframe): string
    {
        $bits = '';
        $bits .= $this->ue(0);
        $bits .= $this->ue(7);
        $bits .= $this->ue(0);
        $bits .= $this->u($this->frameNum, 8);

        $bits .= $this->ue($this->idrPicId);
        $bits .= '0';
        $bits .= '0';

        $bits .= $this->se(0);
        $bits .= $this->ue(1);
        $bits .= $this->se(0);
        $bits .= $this->se(0);

        $mbWidth = (int)ceil($this->width / 16);
        $mbHeight = (int)ceil($this->height / 16);

        $ySize = $this->width * $this->height;
        $uvSize = (int)($ySize / 4);
        $yPlane = substr($yuvData, 0, $ySize);
        $uPlane = substr($yuvData, $ySize, $uvSize);
        $vPlane = substr($yuvData, $ySize + $uvSize, $uvSize);

        for ($mbY = 0; $mbY < $mbHeight; $mbY++) {
            for ($mbX = 0; $mbX < $mbWidth; $mbX++) {
                $bits .= $this->encodeMacroblock($mbX, $mbY, $yPlane, $uPlane, $vPlane);
            }
        }

        $bits .= '1';
        while (strlen($bits) % 8 != 0) $bits .= '0';

        return $this->rbspToNal($this->bitsToBytes($bits), 5);
    }

    private function encodeMacroblock(int $mbX, int $mbY, string $yPlane, string $uPlane, string $vPlane): string
    {
        $bits = '';

        $bits .= $this->ue(1);
        $bits .= $this->ue(2);
        $bits .= $this->u(0x3F, 6);
        $bits .= $this->se(0);

        // --- Luma DC ---
        $lumaDC = array_fill(0, 4, array_fill(0, 4, 0));
        $lumaAC = [];

        for ($by = 0; $by < 4; $by++) {
            for ($bx = 0; $bx < 4; $bx++) {
                $block = $this->getBlock($mbX, $mbY, $bx, $by, $yPlane, false);
                $pred = $this->predictI16x16($mbX, $mbY, $yPlane);
                $residual = $this->subtractBlock($block, $pred, $bx, $by);
                $dct = $this->dct($residual);
                $lumaDC[$by][$bx] = $dct[0][0];
                $dct[0][0] = 0;
                $lumaAC[$by][$bx] = $dct;
            }
        }

        $dcHadamard = $this->hadamard($lumaDC);
        $dcQuantized = $this->quantizeDC($dcHadamard, 0);
        $dcZigzag = $this->zigzag($dcQuantized);
        $bits .= $this->encodeCavlc($dcZigzag);

        // --- Luma AC ---
        for ($by = 0; $by < 4; $by++) {
            for ($bx = 0; $bx < 4; $bx++) {
                $acQuantized = $this->quantize($lumaAC[$by][$bx], 0);
                $acZigzag = $this->zigzag($acQuantized);
                $bits .= $this->encodeCavlc($acZigzag);
            }
        }

        // --- Chroma ---
        foreach ([$uPlane, $vPlane] as $chromaPlane) {
            $chromaDC = array_fill(0, 2, array_fill(0, 2, 0));
            $chromaAC = [];

            for ($by = 0; $by < 2; $by++) {
                for ($bx = 0; $bx < 2; $bx++) {
                    $block = $this->getBlock($mbX, $mbY, $bx, $by, $chromaPlane, true);
                    $dct = $this->dct($block);
                    $chromaDC[$by][$bx] = $dct[0][0];
                    $dct[0][0] = 0;
                    $chromaAC[$by][$bx] = $dct;
                }
            }

            // 2x2 Hadamard and embed into 4x4
            $dcH = array_fill(0, 4, array_fill(0, 4, 0));
            $dcH[0][0] = $chromaDC[0][0] + $chromaDC[0][1] + $chromaDC[1][0] + $chromaDC[1][1];
            $dcH[0][1] = $chromaDC[0][0] - $chromaDC[0][1] + $chromaDC[1][0] - $chromaDC[1][1];
            $dcH[1][0] = $chromaDC[0][0] + $chromaDC[0][1] - $chromaDC[1][0] - $chromaDC[1][1];
            $dcH[1][1] = $chromaDC[0][0] - $chromaDC[0][1] - $chromaDC[1][0] + $chromaDC[1][1];

            $dcQuantized = $this->quantizeDC($dcH, 1);
            $dcZigzag = $this->zigzag($dcQuantized);
            $bits .= $this->encodeCavlc($dcZigzag);

            for ($by = 0; $by < 2; $by++) {
                for ($bx = 0; $bx < 2; $bx++) {
                    $acQuantized = $this->quantize($chromaAC[$by][$bx], 1);
                    $acZigzag = $this->zigzag($acQuantized);
                    $bits .= $this->encodeCavlc($acZigzag);
                }
            }
        }

        return $bits;
    }

    private function getBlock(int $mbX, int $mbY, int $bx, int $by, string $plane, bool $chroma): array
    {
        $step = $chroma ? 8 : 16;
        $pw = $chroma ? (int)($this->width / 2) : $this->width;
        $pixels = array_fill(0, 4, array_fill(0, 4, 0));
        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                $px = $mbX * $step + $bx * 4 + $x;
                $py = $mbY * $step + $by * 4 + $y;
                $idx = $py * $pw + $px;
                if ($idx >= 0 && $idx < strlen($plane)) {
                    $pixels[$y][$x] = ord($plane[$idx]) - 128;
                }
            }
        }
        return $pixels;
    }

    private function predictI16x16(int $mbX, int $mbY, string $plane): array
    {
        $pred = array_fill(0, 16, array_fill(0, 16, 0));

        $sum = 0;
        $cnt = 0;

        if ($mbY > 0) {
            $refY = ($mbY - 1) * 16 + 15;
            for ($x = 0; $x < 16; $x++) {
                $refX = $mbX * 16 + $x;
                if ($refX < $this->width) {
                    $idx = $refY * $this->width + $refX;
                    if ($idx < strlen($plane)) {
                        $sum += ord($plane[$idx]) - 128;
                        $cnt++;
                    }
                }
            }
        }

        if ($mbX > 0) {
            $refX = ($mbX - 1) * 16 + 15;
            for ($y = 0; $y < 16; $y++) {
                $refY = $mbY * 16 + $y;
                $idx = $refY * $this->width + $refX;
                if ($idx < strlen($plane)) {
                    $sum += ord($plane[$idx]) - 128;
                    $cnt++;
                }
            }
        }

        $avg = $cnt > 0 ? (int)round($sum / $cnt) : 0;

        for ($y = 0; $y < 16; $y++) {
            for ($x = 0; $x < 16; $x++) {
                $pred[$y][$x] = $avg;
            }
        }

        return $pred;
    }

    private function subtractBlock(array $original, array $pred, int $bx, int $by): array
    {
        $residual = array_fill(0, 4, array_fill(0, 4, 0));
        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                $residual[$y][$x] = $original[$y][$x] - $pred[$by * 4 + $y][$bx * 4 + $x];
            }
        }
        return $residual;
    }

    private function dct(array $block): array
    {
        $t = array_fill(0, 4, array_fill(0, 4, 0));
        for ($i = 0; $i < 4; $i++) {
            $a = $block[$i][0] + $block[$i][3];
            $b = $block[$i][1] + $block[$i][2];
            $c = $block[$i][1] - $block[$i][2];
            $d = $block[$i][0] - $block[$i][3];
            $t[$i][0] = $a + $b;
            $t[$i][1] = (int)round(($d + $c) * 0.707);
            $t[$i][2] = $a - $b;
            $t[$i][3] = (int)round(($d - $c) * 0.707);
        }

        $r = array_fill(0, 4, array_fill(0, 4, 0));
        for ($i = 0; $i < 4; $i++) {
            $a = $t[0][$i] + $t[3][$i];
            $b = $t[1][$i] + $t[2][$i];
            $c = $t[1][$i] - $t[2][$i];
            $d = $t[0][$i] - $t[3][$i];
            $r[0][$i] = (int)round(($a + $b) * 0.5);
            $r[1][$i] = (int)round(($d + $c) * 0.354);
            $r[2][$i] = (int)round(($a - $b) * 0.5);
            $r[3][$i] = (int)round(($d - $c) * 0.354);
        }

        return $r;
    }

    private function hadamard(array $block): array
    {
        $t = array_fill(0, 4, array_fill(0, 4, 0));
        for ($i = 0; $i < 4; $i++) {
            $a = $block[$i][0] + $block[$i][3];
            $b = $block[$i][1] + $block[$i][2];
            $c = $block[$i][1] - $block[$i][2];
            $d = $block[$i][0] - $block[$i][3];
            $t[$i][0] = $a + $b;
            $t[$i][1] = $c + $d;
            $t[$i][2] = $a - $b;
            $t[$i][3] = $c - $d;
        }

        $r = array_fill(0, 4, array_fill(0, 4, 0));
        for ($i = 0; $i < 4; $i++) {
            $a = $t[0][$i] + $t[3][$i];
            $b = $t[1][$i] + $t[2][$i];
            $c = $t[1][$i] - $t[2][$i];
            $d = $t[0][$i] - $t[3][$i];
            $r[0][$i] = (int)round(($a + $b) * 0.5);
            $r[1][$i] = (int)round(($c + $d) * 0.5);
            $r[2][$i] = (int)round(($a - $b) * 0.5);
            $r[3][$i] = (int)round(($c - $d) * 0.5);
        }

        return $r;
    }

    private function quantize(array $block, int $chroma): array
    {
        $r = array_fill(0, 4, array_fill(0, 4, 0));
        $mf = 1 << (int)($this->qp / 6);
        $rem = $this->qp % 6;

        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                $qm = $this->quantMatrix[$chroma][$y * 4 + $x];
                $q = $qm * $mf;
                if ($rem) $q = (int)round($q * pow(1.122, $rem));
                if ($q == 0) $q = 1;
                $r[$y][$x] = (int)round($block[$y][$x] / $q);
            }
        }
        return $r;
    }

    private function quantizeDC(array $block, int $chroma): array
    {
        $r = array_fill(0, 4, array_fill(0, 4, 0));
        $mf = 1 << (int)($this->qp / 6);
        $rem = $this->qp % 6;

        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                $qm = $this->quantMatrix[$chroma][$y * 4 + $x];
                $q = $qm * $mf;
                if ($rem) $q = (int)round($q * pow(1.122, $rem));
                if ($x == 0 && $y == 0) $q = (int)round($q * 0.25);
                if ($q == 0) $q = 1;
                $r[$y][$x] = (int)round($block[$y][$x] / $q);
            }
        }
        return $r;
    }

    private function zigzag(array $block): array
    {
        $order = [
            [0,0],[0,1],[1,0],[2,0],[1,1],[0,2],[0,3],[1,2],
            [2,1],[3,0],[3,1],[2,2],[1,3],[2,3],[3,2],[3,3]
        ];
        $r = [];
        foreach ($order as $p) {
            $r[] = $block[$p[0]][$p[1]];
        }
        return $r;
    }

    private function encodeCavlc(array $coeffs): string
    {
        $last = -1;
        $tc = 0;
        for ($i = 0; $i < 16; $i++) {
            if ($coeffs[$i] != 0) {
                $tc++;
                $last = $i;
            }
        }

        if ($tc == 0) {
            return '1';
        }

        $t1 = 0;
        for ($i = $last; $i >= max(0, $last - 2); $i--) {
            if (abs($coeffs[$i]) == 1) {
                $t1++;
            } else {
                break;
            }
        }

        $bits = $this->ue($tc * 4 + $t1);

        for ($i = $last; $i > $last - $t1; $i--) {
            $bits .= ($coeffs[$i] > 0) ? '0' : '1';
        }

        $levels = [];
        for ($i = $last - $t1; $i >= 0; $i--) {
            if ($coeffs[$i] != 0) {
                $levels[] = abs($coeffs[$i]);
            }
        }

        foreach ($levels as $idx => $level) {
            if ($idx == 0 && $t1 < 3) {
                $level = max(1, $level - 2);
            }
            $bits .= $this->levelBits($level);
            $origIdx = $last - $t1 - $idx;
            if ($origIdx >= 0 && $origIdx < 16) {
                $bits .= ($coeffs[$origIdx] > 0) ? '0' : '1';
            }
        }

        $tz = $last + 1 - $tc;
        if ($tz > 0 && $last < 15) {
            $bits .= $this->ue($tz);
        }

        $zl = $tz;
        for ($i = $last; $i > 0 && $zl > 0; $i--) {
            $rb = 0;
            $pos = $i - 1;
            while ($pos >= 0 && $coeffs[$pos] == 0 && $rb < $zl) {
                $rb++;
                $pos--;
            }
            if ($rb > 0) {
                $bits .= $this->ue($rb);
                $zl -= $rb;
                $i = $pos + 1;
            }
        }

        return $bits;
    }

    private function levelBits(int $level): string
    {
        if ($level == 0) return '1';
        if ($level < 14) {
            $prefix = (int)floor(log($level + 1, 2));
            $suffix = $level - (1 << $prefix) + 1;
            return str_repeat('1', $prefix) . '0' .
                str_pad(decbin($suffix), $prefix, '0', STR_PAD_LEFT);
        }
        return str_repeat('1', 14) . '0' .
            str_pad(decbin($level - 14), 4, '0', STR_PAD_LEFT);
    }

    private function ue(int $v): string
    {
        if ($v == 0) return '1';
        $bin = decbin($v + 1);
        return str_repeat('0', strlen($bin) - 1) . $bin;
    }

    private function se(int $v): string
    {
        return $this->ue($v <= 0 ? -$v * 2 : $v * 2 - 1);
    }

    private function u(int $v, int $n): string
    {
        return str_pad(decbin($v), $n, '0', STR_PAD_LEFT);
    }

    private function bitsToBytes(string $bits): string
    {
        $bytes = '';
        for ($i = 0; $i < strlen($bits); $i += 8) {
            $bytes .= chr(bindec(str_pad(substr($bits, $i, 8), 8, '0')));
        }
        return $bytes;
    }

    private function rbspToNal(string $rbsp, int $type): string
    {
        $ref = ($type == 5) ? 3 : (in_array($type, [1, 2]) ? 2 : 3);
        $header = chr(($ref << 5) | $type);
        $escaped = '';
        $z = 0;
        for ($i = 0; $i < strlen($rbsp); $i++) {
            $b = ord($rbsp[$i]);
            if ($z >= 2 && $b <= 3) {
                $escaped .= chr(0x03);
                $z = 0;
            }
            $escaped .= chr($b);
            $z = ($b == 0) ? $z + 1 : 0;
        }
        return $header . $escaped;
    }
}