<?php

namespace Xiaosongshu\Flv2mp4\Codec\Decode;

/**
 * @purpose H.264 CABAC 算术解码器（逐字移植 FFmpeg cabac_functions.h / h264_cabac.c，CABAC_BITS=16）
 *
 * 引擎状态：low（32 位无符号存储，比较时转回有符号）、range、字节流指针。
 * 上下文：1024 个单字节 state（0..127，偶数状态编码概率 state*2 + MPS 位），
 * 与 FFmpeg uint8_t cabac_state 完全一致；state 更新查 mlps[128+s']。
 */
trait CabacDecodingTrait
{
    use CabacTablesTrait;

    // ===== 引擎状态 =====
    private int $cabacLow = 0;       // 32 位无符号
    private int $cabacRange = 0;     // 256..510 renorm 后
    private string $cabacBytes = '';
    private int $cabacPos = 0;
    private int $cabacBytesLen = 0;

    /** @var int[] 1024 个 CABAC state */
    private array $cabacCtx = [];

    // ===== 语法元素邻居缓存（每帧/每行重置） =====
    /** 左邻 MB 的 cbp_table（位 0-5 语法 cbp；6/7 色度 DC CBF；8 luma DC CBF） */
    public int $cabacLeftCbp = 0;
    /** @var int[] 上邻 MB 行 cbp_table */
    public array $cabacTopCbpRow = [];
    public int $cabacLeftChromaMode = 0;
    /** @var int[] 上邻 MB 色度预测模式行 */
    public array $cabacChromaModeTopRow = [];
    public bool $cabacLeftIntra16 = false;
    /** @var bool[] 上邻 MB 是否 I16x16/PCM */
    public array $cabacIntra16TopRow = [];
    /** 左邻 MB 是否 I4x4 + 8x8DCT（neighbor_transform_size） */
    public bool $cabacLeftIs8x8 = false;
    /** @var bool[] 上邻 MB 是否 I4x4 + 8x8DCT */
    public array $cabacIs8x8TopRow = [];

    /** 上一个 mb_qp_delta（0 表示上一 MB 无 delta） */
    private int $cabacLastQscaleDiff = 0;

    /** 帧类 cat → sig/last/level/CBF 基址（MB_FIELD=0，4:2:0） */
    private const CABAC_SIG_BASE  = [0 => 105, 1 => 120, 2 => 134, 3 => 149, 4 => 152];
    private const CABAC_LAST_BASE = [0 => 166, 1 => 181, 2 => 195, 3 => 210, 4 => 213];
    private const CABAC_LVL_BASE  = [0 => 227, 1 => 237, 2 => 247, 3 => 257, 4 => 266];
    private const CABAC_CBF_BASE  = [0 => 85, 1 => 89, 2 => 93, 3 => 97, 4 => 101];

    private const LEVEL1_CTX    = [1, 2, 3, 4, 0, 0, 0, 0];
    private const GT1_CTX       = [5, 5, 5, 5, 6, 7, 8, 9];
    private const TRANS_LEVEL1  = [1, 2, 3, 3, 4, 5, 6, 7];
    private const TRANS_GT1     = [4, 4, 4, 4, 5, 6, 7, 7];

    // ============================================================
    // 引擎（ff_init_cabac_decoder / get_cabac / bypass / terminate）
    // ============================================================

    /**
     * 初始化 CABAC：byte 对齐后开始（slice header 已读完）。
     * FFmpeg even/odd 两个初始化分支已验证输出完全等价，固定使用 even 变体。
     */
    public function cabacInitDecoder(string $rbsp, int $cabacInitIdc, int $sliceType, int $qp, int $headerBitPos = 0): void
    {
        $this->cabacInitContexts($cabacInitIdc, $sliceType, $qp);

        $p = intdiv($headerBitPos, 8);
        $this->cabacBytes = $rbsp;
        $this->cabacBytesLen = strlen($rbsp);
        $b0 = $this->cabacByteAt($p);
        $b1 = $this->cabacByteAt($p + 1);

        $this->cabacLow = (($b0 << 18) | ($b1 << 10) | (1 << 9)) & 0xFFFFFFFF;
        $this->cabacPos = $p + 2;
        $this->cabacRange = 0x1FE;
        $this->cabacLastQscaleDiff = 0;
        error_log(sprintf('CABACINIT rbspLen=%d headerBitPos=%d startByte=%d low=0x%08X', $this->cabacBytesLen, $headerBitPos, $p, $this->cabacLow));
    }

    private function cabacByteAt(int $i): int
    {
        return $i < $this->cabacBytesLen ? ord($this->cabacBytes[$i]) : 0;
    }

    private static function cabacS32(int $v): int
    {
        return $v >= 0x80000000 ? $v - 0x100000000 : $v;
    }

    /** bypass 翻倍后 !(low&0xFFFF) 时补充两字节 */
    private function cabacRefill(): void
    {
        $b0 = $this->cabacByteAt($this->cabacPos);
        $b1 = $this->cabacByteAt($this->cabacPos + 1);
        $this->cabacPos += 2;
        $this->cabacLow = ($this->cabacLow + ($b0 << 9) + ($b1 << 1) - 0xFFFF) & 0xFFFFFFFF;
    }

    /** renorm 后 !(low&0xFFFF) 时按 norm_shift 表补充两字节 */
    private function cabacRefill2(): void
    {
        $low = $this->cabacLow;
        $x = ($low ^ (($low - 1) & 0xFFFFFFFF)) & 0xFFFFFFFF;
        $shift = 7 - self::CABAC_NORM_SHIFT[($x >> 15) & 0x1FF];
        $b0 = $this->cabacByteAt($this->cabacPos);
        $b1 = $this->cabacByteAt($this->cabacPos + 1);
        $this->cabacPos += 2;
        $add = (($b0 << 9) + ($b1 << 1) - 0xFFFF) << $shift;
        $this->cabacLow = ($low + $add) & 0xFFFFFFFF;
    }

    /**
     * 上下文自适应 bin（get_cabac_inline）。
     * RangeLPS = lps[2*(range&0xC0)+s]；state 新值 = mlps[128+s']。
     */
    public function cabacGet(int $ctxIdx): int
    {
        $s = $this->cabacCtx[$ctxIdx];
        $range = $this->cabacRange;

        $rangeLps = self::CABAC_LPS_RAW[2 * ($range & 0xC0) + $s];
        $range -= $rangeLps;

        // 有符号比较：(range<<17) - (int32)low；<0 则走 LPS 分支（lpsMask=-1）
        $lpsMask = (($range << 17) - self::cabacS32($this->cabacLow)) >> 31;

        $this->cabacLow = ($this->cabacLow - (($range << 17) & $lpsMask)) & 0xFFFFFFFF;
        $range += ($rangeLps - $range) & $lpsMask;

        // LPS: s' = ~s = -s-1（C int），mlps 索引 128+s'=127-s；MPS: 128+s
        if ($lpsMask === 0) {
            $this->cabacCtx[$ctxIdx] = self::CABAC_MLPS[128 + $s];
            $bit = $s & 1;
        } else {
            $this->cabacCtx[$ctxIdx] = self::CABAC_MLPS[127 - $s];
            $bit = 1 - ($s & 1);
        }

        // renorm
        $shift = self::CABAC_NORM_SHIFT[$range];
        $this->cabacRange = $range << $shift;
        $this->cabacLow = ($this->cabacLow << $shift) & 0xFFFFFFFF;
        if (($this->cabacLow & 0xFFFF) === 0) {
            $this->cabacRefill2();
        }
        return $bit;
    }

    /** bypass bin：只翻倍 low（range 不变），按需 refill */
    public function cabacGetBypass(): int
    {
        $this->cabacLow = ($this->cabacLow << 1) & 0xFFFFFFFF;
        if (($this->cabacLow & 0xFFFF) === 0) {
            $this->cabacRefill();
        }
        $t = $this->cabacRange << 17;
        if (self::cabacS32($this->cabacLow) < $t) {
            return 0;
        }
        $this->cabacLow = ($this->cabacLow - $t) & 0xFFFFFFFF;
        return 1;
    }

    /** bypass 符号：传入负值 -abs，返回 ±abs（bin0 → +abs，bin1 → -abs） */
    public function cabacGetBypassSign(int $negVal): int
    {
        $this->cabacLow = ($this->cabacLow << 1) & 0xFFFFFFFF;
        if (($this->cabacLow & 0xFFFF) === 0) {
            $this->cabacRefill();
        }
        $t = $this->cabacRange << 17;
        $this->cabacLow = ($this->cabacLow - $t) & 0xFFFFFFFF;
        $mask = self::cabacS32($this->cabacLow) >> 31; // 0 或 -1
        if ($mask !== -1) {
            $t = 0;
        }
        $this->cabacLow = ($this->cabacLow + $t) & 0xFFFFFFFF;
        return ($negVal ^ $mask) - $mask;
    }

    /**
     * I_PCM：按 low 低位回退字节指针，取出 PCM 裸数据后用 ff_init_cabac_decoder
     * 在同一缓冲上重新初始化引擎（上下文 state 保留）。
     */
    public function cabacReadPcmPayload(int $length): string
    {
        $ptr = $this->cabacPos;
        if (($this->cabacLow & 0x001) !== 0) {
            $ptr--;
        }
        if (($this->cabacLow & 0x1FF) !== 0) {
            $ptr--;
        }
        if ($ptr < 0) {
            $ptr = 0;
        }
        error_log(sprintf('PCM pos=%d low=0x%08X ptr=%d rbspLen=%d', $this->cabacPos, $this->cabacLow, $ptr, $this->cabacBytesLen));
        $payload = substr($this->cabacBytes, $ptr, $length);
        $newPos = $ptr + $length;

        $b0 = $this->cabacByteAt($newPos);
        $b1 = $this->cabacByteAt($newPos + 1);
        $this->cabacLow = (($b0 << 18) | ($b1 << 10) | (1 << 9)) & 0xFFFFFFFF;
        $this->cabacPos = $newPos + 2;
        $this->cabacRange = 0x1FE;
        return $payload;
    }

    /** 终止 bin（end_of_slice 检测） */
    public function cabacGetTerminate(): int
    {
        $range = $this->cabacRange - 2;
        if (self::cabacS32($this->cabacLow) < ($range << 17)) {
            $shift = ($range - 0x100) < 0 ? 1 : 0; // (uint32)(range-256)>>31
            $this->cabacRange = $range + (2 << $shift);
            $this->cabacLow = ($this->cabacLow << $shift) & 0xFFFFFFFF;
            if (($this->cabacLow & 0xFFFF) === 0) {
                $this->cabacRefill();
            }
            return 0;
        }
        return 1;
    }

    /**
     * 上下文初始化。I 帧（含 IDR）使用 cabac_context_init_I；
     * pre = clamp(abs(2*((m*qp>>4)+n)-127), <=124 取偶数)。
     */
    private function cabacInitContexts(int $cabacInitIdc, int $sliceType, int $qp): void
    {
        // TODO(P/B 帧)：按 sliceType + cabacInitIdc 选择 SI/SP/P/B 初始化表
        $init = self::CABAC_CTX_INIT_I;
        $ctx = [];
        for ($i = 0; $i < 1024; $i++) {
            $m = $init[$i][0];
            $n = $init[$i][1];
            $pre = 2 * ((($m * $qp) >> 4) + $n) - 127;
            $pre = abs($pre);
            if ($pre > 124) {
                $pre = 124 + ($pre & 1);
            }
            $ctx[$i] = $pre;
        }
        $this->cabacCtx = $ctx;
    }

    // ============================================================
    // 宏块层语法
    // ============================================================

    /** I-slice mb_type（ctx_base=3, intra_slice=1），返回 0/1..24/25 */
    public function cabacReadIntraMbType(int $mbX, int $mbY): int
    {
        $nbrCtx = 0;
        if ($mbX > 0 && $this->cabacLeftIntra16) {
            $nbrCtx++;
        }
        if ($mbY > 0 && !empty($this->cabacIntra16TopRow[$mbX])) {
            $nbrCtx++;
        }

        static $dbg = 0;
        $firstBin = $this->cabacGet(3 + $nbrCtx);
        if ($dbg < 5) {
            error_log(sprintf('MBTYPE#%d (%d,%d) nbr=%d bin=%d range=%d low=0x%08X pos=%d', $dbg, $mbX, $mbY, $nbrCtx, $firstBin, $this->cabacRange, $this->cabacLow, $this->cabacPos));
        }
        if ($firstBin === 0) {
            if ($dbg < 5) $dbg++;
            return 0; // I4x4
        }
        // state += 2：后续 ctx 5..10
        $term = $this->cabacGetTerminate();
        if ($dbg < 5) {
            error_log(sprintf('MBTYPE#%d term=%d range=%d low=0x%08X pos=%d', $dbg, $term, $this->cabacRange, $this->cabacLow, $this->cabacPos));
        }
        if ($term === 1) {
            $dbg++;
            return 25; // I_PCM
        }

        $mbType = 1;
        $mbType += 12 * $this->cabacGet(6);
        if ($this->cabacGet(7)) {
            $mbType += 4 + 4 * $this->cabacGet(8);
        }
        $mbType += 2 * $this->cabacGet(9);
        $mbType += 1 * $this->cabacGet(10);
        if ($dbg < 5) {
            error_log(sprintf('MBTYPE#%d -> %d range=%d low=0x%08X pos=%d', $dbg, $mbType, $this->cabacRange, $this->cabacLow, $this->cabacPos));
        }
        $dbg++;
        return $mbType;
    }

    /** Intra4x4 预测模式：prev_intra4x4_pred_mode_flag ctx68；剩余 3 bin ctx69 */
    public function cabacReadIntra4x4PredMode(int $predMode): int
    {
        if ($this->cabacGet(68) === 1) {
            return $predMode;
        }
        $mode = $this->cabacGet(69);
        $mode += 2 * $this->cabacGet(69);
        $mode += 4 * $this->cabacGet(69);
        return $mode + ($mode >= $predMode ? 1 : 0);
    }

    /** transform_size_8x8_flag：ctx 399 + (左邻8x8?1:0) + (上邻8x8?1:0)，仅 I4x4 且 PPS 允许时 */
    public function cabacReadTransform8x8Flag(int $mbX, int $mbY): int
    {
        $n = ($mbX > 0 && $this->cabacLeftIs8x8 ? 1 : 0)
           + ($mbY > 0 && !empty($this->cabacIs8x8TopRow[$mbX]) ? 1 : 0);
        return $this->cabacGet(399 + $n);
    }

    /** 色度预测模式：首 bin ctx 64+(left!=0)+2*(top!=0)，其余 ctx67 */
    public function cabacReadChromaPredMode(int $mbX, int $mbY): int
    {
        $ctx = 0;
        if ($mbX > 0 && $this->cabacLeftChromaMode !== 0) {
            $ctx++;
        }
        if ($mbY > 0 && (int)($this->cabacChromaModeTopRow[$mbX] ?? 0) !== 0) {
            $ctx += 2;
        }

        if ($this->cabacGet(64 + $ctx) === 0) {
            return 0;
        }
        if ($this->cabacGet(67) === 0) {
            return 1;
        }
        if ($this->cabacGet(67) === 0) {
            return 2;
        }
        return 3;
    }

    /** luma coded_block_pattern（8x8 组位，ctx 73..76） */
    public function cabacReadCbpLuma(int $mbX): int
    {
        $cbpA = $this->cabacLeftCbp;
        $cbpB = (int)($this->cabacTopCbpRow[$mbX] ?? 0);

        $cbp = 0;
        $ctx = (($cbpA & 0x02) === 0 ? 1 : 0) + 2 * (($cbpB & 0x04) === 0 ? 1 : 0);
        $cbp += $this->cabacGet(73 + $ctx);
        $ctx = (($cbp & 0x01) === 0 ? 1 : 0) + 2 * (($cbpB & 0x08) === 0 ? 1 : 0);
        $cbp += $this->cabacGet(73 + $ctx) << 1;
        $ctx = (($cbpA & 0x08) === 0 ? 1 : 0) + 2 * (($cbp & 0x01) === 0 ? 1 : 0);
        $cbp += $this->cabacGet(73 + $ctx) << 2;
        $ctx = (($cbp & 0x04) === 0 ? 1 : 0) + 2 * (($cbp & 0x02) === 0 ? 1 : 0);
        $cbp += $this->cabacGet(73 + $ctx) << 3;
        return $cbp;
    }

    /** chroma coded_block_pattern：0/1/2（ctx 77..81） */
    public function cabacReadCbpChroma(int $mbX): int
    {
        $cbpA = ($this->cabacLeftCbp >> 4) & 0x03;
        $cbpB = (((int)($this->cabacTopCbpRow[$mbX] ?? 0)) >> 4) & 0x03;

        $ctx = 0;
        if ($cbpA > 0) {
            $ctx++;
        }
        if ($cbpB > 0) {
            $ctx += 2;
        }
        if ($this->cabacGet(77 + $ctx) === 0) {
            return 0;
        }

        $ctx = 4;
        if ($cbpA === 2) {
            $ctx++;
        }
        if ($cbpB === 2) {
            $ctx += 2;
        }
        return 1 + $this->cabacGet(77 + $ctx);
    }

    /**
     * mb_qp_delta（ctx 60/62/63），返回带符号 delta 并更新 last_qscale_diff。
     * 无 delta 时 last_qscale_diff=0。
     */
    public function cabacReadMbQpDelta(): int
    {
        if ($this->cabacGet(60 + ($this->cabacLastQscaleDiff !== 0 ? 1 : 0)) === 0) {
            $this->cabacLastQscaleDiff = 0;
            return 0;
        }
        $val = 1;
        $ctx = 2;
        while ($this->cabacGet(60 + $ctx)) {
            $ctx = 3;
            $val++;
            if ($val > 2 * 51) {
                break;
            }
        }
        $delta = ($val & 1) ? (($val + 1) >> 1) : -((($val + 1) >> 1));
        $this->cabacLastQscaleDiff = $delta;
        return $delta;
    }

    public function cabacResetLastQscaleDiff(): void
    {
        $this->cabacLastQscaleDiff = 0;
    }

    // ============================================================
    // 残差（coded_block_pattern + sig/last/level）
    // ============================================================

    /**
     * 非 DC 块的左右/上邻非零计数（镜像 computeNc 的取数，仅取 >0 语义）。
     * @return array{0:?int,1:?int} [left,top]，不可用时为 null（按 0 处理）
     */
    private function cabacNondcNeighbors(int $rasterIdx, int $mbX, int $mbY, array $nzCache, array $leftNz, array $topNz, bool $leftAvailable, bool $topAvailable): array
    {
        if ($rasterIdx < 16) {
            $blkX = $rasterIdx % 4;
            $blkY = intdiv($rasterIdx, 4);
            $left = ($blkX > 0) ? $nzCache[$rasterIdx - 1] : ($leftAvailable ? $leftNz[$blkY] : null);
            if ($blkY > 0) {
                $top = $nzCache[$rasterIdx - 4];
            } elseif ($topAvailable) {
                $top = $topNz[$mbX * 4 + $blkX];
            } else {
                $top = null;
            }
        } elseif ($rasterIdx < 20) {
            $cbIdx = $rasterIdx - 16;
            $blkX = $cbIdx % 2;
            $blkY = intdiv($cbIdx, 2);
            $left = ($blkX > 0) ? $nzCache[$rasterIdx - 1] : ($leftAvailable ? $leftNz[4 + $blkY] : null);
            if ($blkY > 0) {
                $top = $nzCache[$rasterIdx - 2];
            } elseif ($topAvailable) {
                $top = $topNz[$this->picWidthInMbs * 4 + $mbX * 2 + $blkX];
            } else {
                $top = null;
            }
        } else {
            $crIdx = $rasterIdx - 20;
            $blkX = $crIdx % 2;
            $blkY = intdiv($crIdx, 2);
            $left = ($blkX > 0) ? $nzCache[$rasterIdx - 1] : ($leftAvailable ? $leftNz[6 + $blkY] : null);
            if ($blkY > 0) {
                $top = $nzCache[$rasterIdx - 2];
            } elseif ($topAvailable) {
                $top = $topNz[$this->picWidthInMbs * 4 + $this->picWidthInMbs * 2 + $mbX * 2 + $blkX];
            } else {
                $top = null;
            }
        }
        return [$left, $top];
    }

    private function cabacReadCbfNondc(int $cat, int $rasterIdx, int $mbX, int $mbY, array $nzCache, array $leftNz, array $topNz, bool $leftAvailable, bool $topAvailable): int
    {
        [$nza, $nzb] = $this->cabacNondcNeighbors($rasterIdx, $mbX, $mbY, $nzCache, $leftNz, $topNz, $leftAvailable, $topAvailable);
        $ctx = self::CABAC_CBF_BASE[$cat]
            + (($nza !== null && $nza > 0) ? 1 : 0)
            + (($nzb !== null && $nzb > 0) ? 2 : 0);
        return $this->cabacGet($ctx);
    }

    private function cabacReadCbfLumaDc(int $mbX): int
    {
        $nza = $this->cabacLeftCbp & 0x100;
        $nzb = ((int)($this->cabacTopCbpRow[$mbX] ?? 0)) & 0x100;
        $ctx = 85 + ($nza > 0 ? 1 : 0) + ($nzb > 0 ? 2 : 0);
        return $this->cabacGet($ctx);
    }

    /** @param int $comp 0=Cb(bit6), 1=Cr(bit7) */
    private function cabacReadCbfChromaDc(int $comp, int $mbX): int
    {
        $bit = 6 + $comp;
        $nza = ($this->cabacLeftCbp >> $bit) & 1;
        $nzb = (((int)($this->cabacTopCbpRow[$mbX] ?? 0)) >> $bit) & 1;
        $ctx = 97 + ($nza > 0 ? 1 : 0) + ($nzb > 0 ? 2 : 0);
        return $this->cabacGet($ctx);
    }

    /**
     * 残差主体：sig/last 前向扫描 + node_ctx level 后向解码。
     * @return array{0:array<int,int>,1:int} [scan 序 levels, coeffCount]
     */
    private function cabacReadResidualLevels(int $cat, int $maxCoeff): array
    {
        $sigBase = self::CABAC_SIG_BASE[$cat];
        $lastBase = self::CABAC_LAST_BASE[$cat];
        $lvlBase = self::CABAC_LVL_BASE[$cat];

        // 前向 significant/last
        $sigIdx = [];
        $lastHit = false;
        for ($i = 0; $i < $maxCoeff - 1; $i++) {
            if ($this->cabacGet($sigBase + $i)) {
                $sigIdx[] = $i;
                if ($this->cabacGet($lastBase + $i)) {
                    $lastHit = true;
                    break;
                }
            }
        }
        if (!$lastHit) {
            $sigIdx[] = $maxCoeff - 1;
        }

        $levels = array_fill(0, $maxCoeff, 0);
        $nodeCtx = 0;
        for ($k = count($sigIdx) - 1; $k >= 0; $k--) {
            $scanPos = $sigIdx[$k];
            if ($this->cabacGet($lvlBase + self::LEVEL1_CTX[$nodeCtx]) === 0) {
                $levels[$scanPos] = $this->cabacGetBypassSign(-1);
                $nodeCtx = self::TRANS_LEVEL1[$nodeCtx];
            } else {
                $abs = 2;
                while ($abs < 15 && $this->cabacGet($lvlBase + self::GT1_CTX[$nodeCtx])) {
                    $abs++;
                }
                $nodeCtx = self::TRANS_GT1[$nodeCtx];
                if ($abs >= 15) {
                    $j = 0;
                    while ($this->cabacGetBypass() && $j < 23) {
                        $j++;
                    }
                    $abs = 1;
                    while ($j-- > 0) {
                        $abs += $abs + $this->cabacGetBypass();
                    }
                    $abs += 14;
                }
                $levels[$scanPos] = $this->cabacGetBypassSign(-$abs);
            }
        }
        return [$levels, count($sigIdx)];
    }

    /**
     * I4x4 luma 块（cat2，max16，自带 CBF）。
     * @return array{0:array<int,int>,1:int} [16 个 scan 序 levels, nzCount]
     */
    public function cabacReadLuma4x4Block(int $rasterIdx, int $mbX, int $mbY, array $nzCache, array $leftNz, array $topNz, bool $leftAvailable, bool $topAvailable): array
    {
        if ($this->cabacReadCbfNondc(2, $rasterIdx, $mbX, $mbY, $nzCache, $leftNz, $topNz, $leftAvailable, $topAvailable) === 0) {
            return [array_fill(0, 16, 0), 0];
        }
        return $this->cabacReadResidualLevels(2, 16);
    }

    /**
     * I16x16 luma AC 块（cat1，max15，自带 CBF）。
     * @return array{0:array<int,int>,1:int} [15 个 AC scan 序 levels, nzCount]
     */
    public function cabacReadLuma16AcBlock(int $rasterIdx, int $mbX, int $mbY, array $nzCache, array $leftNz, array $topNz, bool $leftAvailable, bool $topAvailable): array
    {
        if ($this->cabacReadCbfNondc(1, $rasterIdx, $mbX, $mbY, $nzCache, $leftNz, $topNz, $leftAvailable, $topAvailable) === 0) {
            return [array_fill(0, 15, 0), 0];
        }
        return $this->cabacReadResidualLevels(1, 15);
    }

    /**
     * 色度 AC 块（cat4，max15，自带 CBF）。rasterIdx 16..19=Cb, 20..23=Cr。
     * @return array{0:array<int,int>,1:int}
     */
    public function cabacReadChromaAcBlock(int $rasterIdx, int $mbX, int $mbY, array $nzCache, array $leftNz, array $topNz, bool $leftAvailable, bool $topAvailable): array
    {
        if ($this->cabacReadCbfNondc(4, $rasterIdx, $mbX, $mbY, $nzCache, $leftNz, $topNz, $leftAvailable, $topAvailable) === 0) {
            return [array_fill(0, 15, 0), 0];
        }
        return $this->cabacReadResidualLevels(4, 15);
    }

    /**
     * I16x16 luma DC（cat0，max16，自带 CBF）。
     * @return array{0:array<int,int>,1:int} [16 个 scan 序 levels, nzCount]；CBF=0 时 nzCount=0
     */
    public function cabacReadLumaDcBlock(int $mbX): array
    {
        if ($this->cabacReadCbfLumaDc($mbX) === 0) {
            return [array_fill(0, 16, 0), 0];
        }
        return $this->cabacReadResidualLevels(0, 16);
    }

    /**
     * 色度 DC（cat3，max4，自带 CBF）。
     * @param int $comp 0=Cb, 1=Cr
     * @return array{0:array<int,int>,1:int}
     */
    public function cabacReadChromaDcBlock(int $comp, int $mbX): array
    {
        if ($this->cabacReadCbfChromaDc($comp, $mbX) === 0) {
            return [array_fill(0, 4, 0), 0];
        }
        return $this->cabacReadResidualLevels(3, 4);
    }
}
