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
        $bits .= '1'; // constraint_set0
        $bits .= '0';
        $bits .= '0';
        $bits .= '0';
        $bits .= '0';
        $bits .= '0';
        $bits .= '00'; // reserved
        $bits .= $this->u($levelIdc, 8);
        $bits .= $this->ue(0); // sps_id
        $bits .= $this->ue(4); // log2_max_frame_num
        $bits .= $this->ue(2); // pic_order_cnt_type
        $bits .= $this->ue(0); // max_num_ref_frames
        $bits .= '0'; // gaps_in_frame_num
        $bits .= $this->ue($picWidthInMbs - 1);
        $bits .= $this->ue($picHeightInMbs - 1);
        $bits .= '1'; // frame_mbs_only
        $bits .= '1'; // direct_8x8_inference
        $bits .= '0'; // cropping
        $bits .= '0'; // vui

        $bits .= '1';
        while (strlen($bits) % 8 != 0) $bits .= '0';

        return $this->rbspToNal($this->bitsToBytes($bits), 7);
    }

    public function generatePPS(): string
    {
        $bits = '';
        $bits .= $this->ue(0); // pps_id
        $bits .= $this->ue(0); // sps_id
        $bits .= '0'; // cavlc
        $bits .= '0'; // pic_order_present
        $bits .= $this->ue(0); // num_slice_groups
        $bits .= $this->ue(0); // num_ref_idx_l0
        $bits .= $this->ue(0); // num_ref_idx_l1
        $bits .= '0'; // weighted_pred
        $bits .= '00'; // weighted_bipred
        $bits .= $this->se($this->qp - 26); // pic_init_qp
        $bits .= $this->se(0); // pic_init_qs
        $bits .= $this->se(0); // chroma_qp_offset
        $bits .= '0'; // deblocking
        $bits .= '0'; // constrained_intra
        $bits .= '0'; // redundant_pic_cnt

        $bits .= '1';
        while (strlen($bits) % 8 != 0) $bits .= '0';

        return $this->rbspToNal($this->bitsToBytes($bits), 8);
    }

    private function encodeSlice(string $yuvData, bool $isKeyframe): string
    {
        $bits = '';
        $bits .= $this->ue(0); // first_mb
        $bits .= $this->ue($isKeyframe ? 7 : 2); // slice_type
        $bits .= $this->ue(0); // pps_id
        $bits .= $this->u($this->frameNum, 8);

        if ($isKeyframe) {
            $bits .= $this->ue($this->idrPicId);
            $bits .= '0'; // no_output
            $bits .= '0'; // long_term
        }

        $bits .= $this->se(0); // slice_qp_delta

        if ($isKeyframe) {
            $bits .= $this->ue(1); // disable_deblocking
            $bits .= $this->se(0);
            $bits .= $this->se(0);
        }

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

        return $this->rbspToNal($this->bitsToBytes($bits), $isKeyframe ? 5 : 1);
    }

    private function encodeMacroblock(int $mbX, int $mbY, string $yPlane, string $uPlane, string $vPlane): string
    {
        $bits = '';

        // mb_type: I_16x16 = 1
        $bits .= $this->ue(1);

        // intra16x16_pred_mode: DC = 2
        $bits .= $this->ue(2);

        // coded_block_pattern: luma AC + chroma DC + chroma AC
        // 简化：全部编码
        $bits .= $this->u(0, 2); // luma AC cbp (0=all present)
        $bits .= $this->u(0, 2); // chroma cbp

        // mb_qp_delta
        $bits .= $this->se(0);

        // ===== Luma DC =====
        $dcLuma = [];
        for ($by = 0; $by < 4; $by++) {
            for ($bx = 0; $bx < 4; $bx++) {
                $block = $this->getBlock($mbX, $mbY, $bx, $by, $yPlane, false);
                $residual = $this->subtract($block, $this->predict($mbX, $mbY, $bx, $by, $yPlane, false));
                $dct = $this->dct($residual);
                $dcLuma[$by][$bx] = $dct[0][0];
            }
        }

        $dcHadamard = $this->hadamard($dcLuma);
        $dcQ = $this->quantize($dcHadamard, 0, true);
        $bits .= $this->encodeCavlc($this->zigzag($dcQ), 0);

        // ===== Luma AC =====
        for ($by = 0; $by < 4; $by++) {
            for ($bx = 0; $bx < 4; $bx++) {
                $block = $this->getBlock($mbX, $mbY, $bx, $by, $yPlane, false);
                $residual = $this->subtract($block, $this->predict($mbX, $mbY, $bx, $by, $yPlane, false));
                $dct = $this->dct($residual);
                $dct[0][0] = 0; // already in DC
                $acQ = $this->quantize($dct, 0, false);
                $bits .= $this->encodeCavlc($this->zigzag($acQ), 1);
            }
        }

        // ===== Chroma DC (U and V) =====
        foreach ([$uPlane, $vPlane] as $chromaPlane) {
            $dcChroma = [];
            for ($by = 0; $by < 2; $by++) {
                for ($bx = 0; $bx < 2; $bx++) {
                    $block = $this->getBlock($mbX, $mbY, $bx, $by, $chromaPlane, true);
                    $residual = $this->subtract($block, $this->predict($mbX, $mbY, $bx, $by, $chromaPlane, true));
                    $dct = $this->dct($residual);
                    $dcChroma[$by][$bx] = $dct[0][0];
                }
            }

            // 2x2 Hadamard
            $dcQ[0][0] = (int)round(($dcChroma[0][0] + $dcChroma[0][1] + $dcChroma[1][0] + $dcChroma[1][1]) / 2);
            $dcQ[0][1] = (int)round(($dcChroma[0][0] - $dcChroma[0][1] + $dcChroma[1][0] - $dcChroma[1][1]) / 2);
            $dcQ[1][0] = (int)round(($dcChroma[0][0] + $dcChroma[0][1] - $dcChroma[1][0] - $dcChroma[1][1]) / 2);
            $dcQ[1][1] = (int)round(($dcChroma[0][0] - $dcChroma[0][1] - $dcChroma[1][0] + $dcChroma[1][1]) / 2);

            // quantize as 4x4 DC
            $dcQQuant = $this->quantize($dcQ, 1, true);
            $bits .= $this->encodeCavlc($this->zigzag($dcQQuant), 0);

            // Chroma AC
            for ($by = 0; $by < 2; $by++) {
                for ($bx = 0; $bx < 2; $bx++) {
                    $block = $this->getBlock($mbX, $mbY, $bx, $by, $chromaPlane, true);
                    $residual = $this->subtract($block, $this->predict($mbX, $mbY, $bx, $by, $chromaPlane, true));
                    $dct = $this->dct($residual);
                    $dct[0][0] = 0;
                    $acQ = $this->quantize($dct, 1, false);
                    $bits .= $this->encodeCavlc($this->zigzag($acQ), 1);
                }
            }
        }

        return $bits;
    }

    private function getBlock(int $mbX, int $mbY, int $bx, int $by, string $plane, bool $chroma): array
    {
        $step = $chroma ? 8 : 16;
        $pw = $chroma ? (int)($this->width / 2) : $this->width;
        $pixels = [];
        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                $px = $mbX * $step + $bx * 4 + $x;
                $py = $mbY * $step + $by * 4 + $y;
                $idx = max(0, min(strlen($plane) - 1, $py * $pw + $px));
                $pixels[$y][$x] = ord($plane[$idx]) - 128;
            }
        }
        return $pixels;
    }

    private function predict(int $mbX, int $mbY, int $bx, int $by, string $plane, bool $chroma): array
    {
        $step = $chroma ? 8 : 16;
        $pw = $chroma ? (int)($this->width / 2) : $this->width;
        $pred = array_fill(0, 4, array_fill(0, 4, 0));

        $sum = 0;
        $cnt = 0;

        // Top
        if ($by > 0 || $mbY > 0) {
            $refY = $mbY * $step + ($by > 0 ? ($by - 1) * 4 + 3 : -1);
            if ($by == 0) $refY = ($mbY - 1) * $step + 3 * 4 + 3;
            for ($x = 0; $x < 4; $x++) {
                $refX = $mbX * $step + $bx * 4 + $x;
                if ($refY >= 0 && $refX < $pw) {
                    $sum += ord($plane[$refY * $pw + $refX] ?? chr(128)) - 128;
                    $cnt++;
                }
            }
        }

        // Left
        if ($bx > 0 || $mbX > 0) {
            $refX = $mbX * $step + ($bx > 0 ? ($bx - 1) * 4 + 3 : -1);
            if ($bx == 0) $refX = ($mbX - 1) * $step + 3 * 4 + 3;
            for ($y = 0; $y < 4; $y++) {
                $refY = $mbY * $step + $by * 4 + $y;
                if ($refX >= 0 && $refY * $pw + $refX < strlen($plane)) {
                    $sum += ord($plane[$refY * $pw + $refX] ?? chr(128)) - 128;
                    $cnt++;
                }
            }
        }

        if ($cnt > 0) {
            $avg = (int)round($sum / $cnt);
            for ($y = 0; $y < 4; $y++)
                for ($x = 0; $x < 4; $x++)
                    $pred[$y][$x] = $avg;
        }

        return $pred;
    }

    private function subtract(array $a, array $b): array
    {
        $r = [];
        for ($y = 0; $y < 4; $y++)
            for ($x = 0; $x < 4; $x++)
                $r[$y][$x] = $a[$y][$x] - $b[$y][$x];
        return $r;
    }

    private function dct(array $block): array
    {
        $t = [];
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

        $r = [];
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
        $t = [];
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

        $r = [];
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

    private function quantize(array $block, int $chroma, bool $isDC): array
    {
        $r = [];
        $mf = 1 << (int)($this->qp / 6);
        $rem = $this->qp % 6;

        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                $qm = $this->quantMatrix[$chroma][$y * 4 + $x];
                $q = $qm * $mf;
                if ($rem) $q = (int)round($q * pow(1.122, $rem));
                if ($isDC && $x == 0 && $y == 0) $q = (int)round($q * 0.25);
                if ($q == 0) $q = 1;
                $r[$y][$x] = (int)round($block[$y][$x] / $q);
            }
        }
        return $r;
    }

    private function zigzag(array $block): array
    {
        $order = [[0,0],[0,1],[1,0],[2,0],[1,1],[0,2],[0,3],[1,2],
            [2,1],[3,0],[3,1],[2,2],[1,3],[2,3],[3,2],[3,3]];
        $r = [];
        foreach ($order as $p) $r[] = $block[$p[0]][$p[1]];
        return $r;
    }

    private function encodeCavlc(array $coeffs, int $nC): string
    {
        $last = -1;
        $tc = 0;
        for ($i = 0; $i < 16; $i++) {
            if ($coeffs[$i] != 0) { $tc++; $last = $i; }
        }

        if ($tc == 0) return '1';

        // Trailing ones
        $t1 = 0;
        for ($i = $last; $i >= max(0, $last - 2); $i--) {
            if (abs($coeffs[$i]) == 1) $t1++;
            else break;
        }

        // coeff_token (simplified UE for TC>4)
        $coeffToken = $tc < 5 ? $this->coeffTokenBits($tc, $t1) : $this->ue(39 + $tc);
        if (is_string($coeffToken)) {
            $bits = $coeffToken;
        } else {
            $bits = $this->ue($coeffToken);
        }

        // Signs
        for ($i = $last; $i > $last - $t1; $i--) {
            $bits .= $coeffs[$i] > 0 ? '0' : '1';
        }

        // Levels
        $levels = [];
        for ($i = $last - $t1; $i >= 0; $i--) {
            if ($coeffs[$i] != 0) $levels[] = abs($coeffs[$i]);
        }

        foreach ($levels as $idx => $level) {
            if ($idx == 0 && $t1 < 3) $level = max(1, $level - 2);
            $bits .= $this->levelBits($level);
            $bits .= $coeffs[$last - $t1 - $idx] > 0 ? '0' : '1';
        }

        // Total zeros
        $tz = $last + 1 - $tc;
        if ($tz > 0) {
            $bits .= $this->totalZerosBits($tc, $tz);
        }

        // Run before
        $zl = $tz;
        for ($i = $last; $i > 0 && $zl > 0; $i--) {
            $rb = 0;
            while ($i - 1 - $rb >= 0 && $coeffs[$i - 1 - $rb] == 0 && $rb < $zl) $rb++;
            if ($rb > 0) {
                $bits .= $this->runBeforeBits($rb, $zl);
                $zl -= $rb;
                $i -= $rb;
            }
        }

        return $bits;
    }

    private function coeffTokenBits(int $tc, int $t1): string
    {
        $table = [
            1 => [0 => '01', 1 => '000101'],
            2 => [0 => '00000111', 1 => '00000100', 2 => '000011'],
            3 => [0 => '00000110', 1 => '000101', 2 => '00000101', 3 => '000001111'],
            4 => [0 => '000011', 1 => '0000101', 2 => '00001011', 3 => '00001101'],
        ];
        return $table[$tc][$t1] ?? '1';
    }

    private function levelBits(int $level): string
    {
        if ($level < 14) {
            $prefix = (int)floor(log($level + 1, 2));
            return str_repeat('1', $prefix) . '0' . str_pad(decbin($level - (1 << $prefix) + 1), $prefix, '0', STR_PAD_LEFT);
        }
        return str_repeat('1', 14) . '0' . str_pad(decbin($level - 14), 4, '0', STR_PAD_LEFT);
    }

    private function totalZerosBits(int $tc, int $tz): string
    {
        $table = [
            1 => [1=>'011',2=>'010',3=>'0011',4=>'0010',5=>'00011',6=>'00010',7=>'000011',8=>'000010',9=>'0000011',10=>'0000010',11=>'00000011',12=>'00000010',13=>'000000011',14=>'000000010',15=>'000000001'],
            2 => [1=>'111',2=>'110',3=>'101',4=>'100',5=>'011',6=>'00101',7=>'00100',8=>'000111',9=>'000110',10=>'000101',11=>'000100',12=>'000011',13=>'0000011',14=>'0000010',15=>'00000011'],
            3 => [1=>'0101',2=>'111',3=>'110',4=>'0100',5=>'0011',6=>'101',7=>'100',8=>'011',9=>'00101',10=>'00100',11=>'00011',12=>'00010',13=>'000011',14=>'000010',15=>'0000011'],
        ];

        if (isset($table[$tc][$tz])) return $table[$tc][$tz];
        return $this->ue($tz);
    }

    private function runBeforeBits(int $rb, int $zl): string
    {
        if ($zl == 0) return '';
        if ($rb == 0) return '1';
        if ($rb == 1) return '01';
        if ($rb == 2) return '001';
        if ($rb <= 6) return str_repeat('0', $rb) . '1';
        return str_repeat('0', 7) . '1';
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
        $ref = in_array($type, [1, 2, 5]) ? ($type == 5 ? 3 : 2) : 3;
        $header = chr(($ref << 5) | $type);
        $escaped = '';
        $z = 0;
        for ($i = 0; $i < strlen($rbsp); $i++) {
            $b = ord($rbsp[$i]);
            if ($z >= 2 && $b <= 3) { $escaped .= chr(0x03); $z = 0; }
            $escaped .= chr($b);
            $z = ($b == 0) ? $z + 1 : 0;
        }
        return $header . $escaped;
    }
}