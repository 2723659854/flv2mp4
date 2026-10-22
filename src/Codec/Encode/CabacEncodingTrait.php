<?php

namespace Xiaosongshu\Flv2mp4\Codec\Encode;

use Xiaosongshu\Flv2mp4\Codec\Decode\CabacDecodingTrait;
use Xiaosongshu\Flv2mp4\Codec\Decode\CabacTablesTrait;

/**
 * @purpose CABAC 熵编码（main/high profile）。
 *   - renorm 阈值 256/512、bypass 阈值 512/1024
 *   - 首位丢弃对应 init_cabac_encoder 的 pb.bit_left++ 技巧
 *   - terminate 成功后写 low>>9 + ((low>>7)&3)|1 并零填充对齐
 *
 * 语法写入器的 ctx 序列逐条镜像本解码器 CabacDecodingTrait。
 * 直接 use 解码 trait 复用全部 CABAC 表与邻居簿记（cabFillCaches/cabNeighborCbp/
 * cabCbfAcCtx/cabPredModeN/cabStoreMvd/cabNbBlock/cabStoreWriteBack 等），
 * 保证编码/解码两侧邻居推导逻辑唯一来源。
 * @author yanglong
 * @time 2026年10月21日16:36:24
 */
trait CabacEncodingTrait
{
    use CabacTablesTrait;
    use CabacDecodingTrait;

    // ============================================================
    // 引擎状态（init_cabac_encoder：low=0, range=0x1FE, outstanding=0）
    // ============================================================

    /** 位累加输出缓冲（字节打包，MSB 先行） */
    private string $encBuf = '';
    private int $encLow = 0;
    private int $encRange = 0x1FE;
    private int $encOutstanding = 0;
    private int $encAcc = 0;
    private int $encAccBits = 0;
    /**  pb.bit_left++：首个写出位被丢弃 */
    private bool $encFirstBit = true;

    // ============================================================
    // 邻居簿记所需的画布级数组（解码 trait 引用，编码侧同样维护）
    // ============================================================

    public $picHeightInMbs = 0;
    /** mbCount 标志位（CAB_FLG_I4/I16/PCM；inter 保持 0） */
    public array $picMbType = [];
    /** mbCount（bit0..3 亮度 8x8、bit4,5 色度、bit6,7 色度 DC、bit8 亮度 DC） */
    public array $picCbp = [];
    /** mbCount 0/1（mb_skip_flag 上下文） */
    public array $picIsSkip = [];
    /** mbCount 0/1（B mb_type direct 上下文） */
    public array $picIsDirectMb = [];
    /** mbCount 色度预测模式 */
    public array $picChromaPred = [];
    /** mbCount*16 [x,y] MVD 存储（mvd 上下文） */
    public array $picMvd0 = [];
    public array $picMvd1 = [];

    public int $cabacInitIdc = 0;
    public array $cabacStates = [];
    public string $cabacData = '';
    public int $cabacPtr = 0;
    public int $cabacEnd = 0;
    public int $cabacLow = 0;
    public int $cabacRange = 0;

    // ============================================================
    // 帧级 / 片级
    // ============================================================

    /** 帧开始：重置 CABAC 邻居簿记画布（每帧第一片前调用一次） */
    private function encFrameBegin(): void
    {
        $mbCount = $this->picWidthInMbs * $this->picHeightInMbs;
        $this->picMbType = array_fill(0, $mbCount, 0);
        $this->picCbp = array_fill(0, $mbCount, 0);
        $this->picIsSkip = array_fill(0, $mbCount, 0);
        $this->picIsDirectMb = array_fill(0, $mbCount, 0);
        $this->picChromaPred = array_fill(0, $mbCount, 0);
        $this->picMvd0 = array_fill(0, $mbCount * 16, [0, 0]);
        $this->picMvd1 = array_fill(0, $mbCount * 16, [0, 0]);
    }

    /**
     * 片开始：引擎复位 + 上下文初始化 + scan8 持久存储重置。
     * 调用前须已设 cabacInitIdc（0/1/2），slice header 已字节对齐。
     *
     * @param int $sliceTypeNos 0=P 1=B 2=I
     */
    private function encSliceBegin(int $sliceQp, int $sliceTypeNos): void
    {
        // 引擎复位（init_cabac_encoder）
        $this->encBuf = '';
        $this->encLow = 0;
        $this->encRange = 0x1FE;
        $this->encOutstanding = 0;
        $this->encAcc = 0;
        $this->encAccBits = 0;
        $this->encFirstBit = true;

        // 上下文初始化（cabac_init_idc + slice_qp）
        $this->cabacInitStates($sliceQp, $sliceTypeNos);

        // 存储重置（镜像 cabacSliceBegin，去掉解码专用 cabacStart）
        $mbCount = $this->picWidthInMbs * $this->picHeightInMbs;
        $this->cabNzStore = array_fill(0, $mbCount * 48, 0);
        $this->cabI4Store = array_fill(0, $mbCount * 16, 2);
        $this->cabNzCache = array_fill(0, 120, 0);
        $this->cabI4Cache = array_fill(0, 120, -1);
        $this->cabQp = max(0, min(51, $sliceQp));
        $this->cabLastQpDiff = 0;
    }

    /** 片结束：取出 CABAC 位流字节（含零填充对齐） */
    private function encGetBytes(): string
    {
        if ($this->encAccBits > 0) {
            $this->encBuf .= chr(($this->encAcc << (8 - $this->encAccBits)) & 0xFF);
            $this->encAcc = 0;
            $this->encAccBits = 0;
        }
        return $this->encBuf;
    }

    // ============================================================
    // 算术编码引擎
    // ============================================================

    /** 原始写一位（首位丢弃 = pb.bit_left++ 语义） */
    private function encRawBit(int $b): void
    {
        if ($this->encFirstBit) {
            $this->encFirstBit = false;
            return;
        }
        $this->encAcc = (($this->encAcc << 1) | $b) & 0xFF;
        if (++$this->encAccBits === 8) {
            $this->encBuf .= chr($this->encAcc);
            $this->encAcc = 0;
            $this->encAccBits = 0;
        }
    }

    /** put_cabac_bit：写 b 并按 (1-b) 清空挂起位 */
    private function encPutBit(int $b): void
    {
        $this->encRawBit($b);
        while ($this->encOutstanding > 0) {
            $this->encOutstanding--;
            $this->encRawBit(1 - $b);
        }
    }

    /** renorm_cabac_encoder：阈值 256/512 */
    private function encRenorm(): void
    {
        while ($this->encRange < 0x100) {
            if ($this->encLow < 0x100) {
                $this->encPutBit(0);
            } elseif ($this->encLow < 0x200) {
                $this->encOutstanding++;
                $this->encLow -= 0x100;
            } else {
                $this->encPutBit(1);
                $this->encLow -= 0x200;
            }
            $this->encRange += $this->encRange;
            $this->encLow += $this->encLow;
        }
    }

    /** put_cabac：常规 bin（状态 s = 2*stateIdx + mps，与解码器同表同索引） */
    private function encDecision(int $ctxIdx, int $binVal): void
    {
        $s = $this->cabacStates[$ctxIdx];
        $rangeLps = self::$cabacLpsRange[2 * ($this->encRange & 0xC0) + $s];
        if ($binVal === ($s & 1)) {
            // MPS：区间取大段
            $this->encRange -= $rangeLps;
            $this->cabacStates[$ctxIdx] = self::$cabacMlps[128 + $s];
        } else {
            // LPS：区间取小段
            $this->encLow += $this->encRange - $rangeLps;
            $this->encRange = $rangeLps;
            $this->cabacStates[$ctxIdx] = self::$cabacMlps[127 - $s];
        }
        $this->encRenorm();
    }

    /** put_cabac_bypass：阈值 512/1024 */
    private function encBypass(int $bit): void
    {
        $this->encLow += $this->encLow;
        if ($bit) {
            $this->encLow += $this->encRange;
        }
        if ($this->encLow < 0x200) {
            $this->encPutBit(0);
        } elseif ($this->encLow < 0x400) {
            $this->encOutstanding++;
            $this->encLow -= 0x200;
        } else {
            $this->encPutBit(1);
            $this->encLow -= 0x400;
        }
    }

    /**
     * put_cabac_terminate。
     * bit=0：普通 terminate bin（renorm）；bit=1：片尾终止并 flush。
     */
    private function encTerminate(int $bit): void
    {
        $this->encRange -= 2;
        if (!$bit) {
            $this->encRenorm();
            return;
        }
        $this->encLow += $this->encRange;
        $this->encRange = 2;
        $this->encRenorm();
        // low ≤ 0x1FF：写其最高位 + 2 位尾缀（末位恒 1 供解码器识别）
        $this->encPutBit(($this->encLow >> 9) & 1);
        $v = (($this->encLow >> 7) & 3) | 1;
        $this->encRawBit(($v >> 1) & 1);
        $this->encRawBit($v & 1);
        // flush：零填充对齐（encGetBytes 幂等，重复调用安全）
        if ($this->encAccBits > 0) {
            $this->encBuf .= chr(($this->encAcc << (8 - $this->encAccBits)) & 0xFF);
            $this->encAcc = 0;
            $this->encAccBits = 0;
        }
    }

    // ============================================================
    // 宏块层语法写入器（ctx 序列镜像 CabacDecodingTrait）
    // ============================================================

    /** mb_skip_flag：bin 1=skip；ctx=(左非skip?1:0)+(上非skip?1:0)，B 片 +13 */
    private function encWriteMbSkip(bool $isB, bool $skip): void
    {
        $mbIdx = $this->cabMbIdx;
        $w = $this->picWidthInMbs;
        $ctx = 0;
        if ($this->cabMbX > 0 && empty($this->picIsSkip[$mbIdx - 1])) {
            $ctx++;
        }
        if ($this->cabMbY > 0 && empty($this->picIsSkip[$mbIdx - $w])) {
            $ctx++;
        }
        if ($isB) {
            $ctx += 13;
        }
        $this->encDecision(11 + $ctx, $skip ? 1 : 0);
    }

    /** P mb_type：0=P_L0_16x16 1=16x8 2=8x16 3=8x8；-1=intra（bin14=1） */
    private function encWritePMbType(int $mbType): void
    {
        if ($mbType < 0) {
            $this->encDecision(14, 1);
            return;
        }
        $this->encDecision(14, 0);
        if ($mbType === 0 || $mbType === 3) {
            $this->encDecision(15, 0);
            $this->encDecision(16, $mbType === 3 ? 1 : 0);
        } else {
            $this->encDecision(15, 1);
            $this->encDecision(17, $mbType === 1 ? 1 : 0);
        }
    }

    /** B mb_type direct 上下文（左/上非 direct 计数） */
    private function cabBDirectCtx(): int
    {
        $mbIdx = $this->cabMbIdx;
        $w = $this->picWidthInMbs;
        $ctx = 0;
        if ($this->cabMbX > 0 && empty($this->picIsDirectMb[$mbIdx - 1])) {
            $ctx++;
        }
        if ($this->cabMbY > 0 && empty($this->picIsDirectMb[$mbIdx - $w])) {
            $ctx++;
        }
        return $ctx;
    }

    /** B_Direct_16x16：bin(27+ctx)=0 */
    private function encWriteBMbTypeDirect(): void
    {
        $this->encDecision(27 + $this->cabBDirectCtx(), 0);
    }

    /** B 16x16 inter：mbType 1=L0 2=L1 3=Bi */
    private function encWriteBMbTypeInter(int $mbType): void
    {
        $this->encDecision(27 + $this->cabBDirectCtx(), 1);
        if ($mbType === 1 || $mbType === 2) {
            $this->encDecision(30, 0);
            $this->encDecision(32, $mbType - 1);
        } else {
            // Bi_16x16：bits=0000 → mbType=3
            $this->encDecision(30, 1);
            $this->encDecision(31, 0);
            $this->encDecision(32, 0);
            $this->encDecision(32, 0);
            $this->encDecision(32, 0);
        }
    }

    /** B 片内嵌 intra 前缀（bits=13 → decode_cabac_intra_mb_type(32,0)） */
    private function encWriteBIntraPrefix(): void
    {
        $this->encDecision(27 + $this->cabBDirectCtx(), 1);
        $this->encDecision(30, 1);
        $this->encDecision(31, 1);
        $this->encDecision(32, 1);
        $this->encDecision(32, 0);
        $this->encDecision(32, 1);
    }

    /**
     * I 片 mb_type（decode_cabac_intra_mb_type(3,1)）。
     * bin@3+邻居ctx；I16 时 terminate(0) 后 bins@6(luma15)/7(cbpChroma标志)/8(值)/9(p1)/10(p0)。
     */
    private function encWriteIntraMbTypeI(bool $isI16, int $luma15, int $chromaCbp, int $mode): void
    {
        $l = $this->cabLeftMbIdx();
        $t = $this->cabTopMbIdx();
        $flg = self::$CAB_FLG_I16 | self::$CAB_FLG_PCM;
        $ctx = 0;
        if ($l >= 0 && ($this->picMbType[$l] & $flg)) {
            $ctx++;
        }
        if ($t >= 0 && ($this->picMbType[$t] & $flg)) {
            $ctx++;
        }
        $this->encDecision(3 + $ctx, $isI16 ? 1 : 0);
        if (!$isI16) {
            return;
        }
        $this->encTerminate(0); // 非 PCM
        // state = ctxBase+2 = 5
        $this->encDecision(6, $luma15 ? 1 : 0);
        $this->encDecision(7, $chromaCbp > 0 ? 1 : 0);
        if ($chromaCbp > 0) {
            $this->encDecision(8, $chromaCbp - 1);
        }
        $this->encDecision(9, ($mode >> 1) & 1); // p1
        $this->encDecision(10, $mode & 1);       // p0
    }

    /**
     * B 片内嵌 intra（decode_cabac_intra_mb_type(32,0)
     */
    private function encWriteIntraMbTypeBIntra(bool $isI16, int $luma15, int $chromaCbp, int $mode): void
    {
        $this->encDecision(32, $isI16 ? 1 : 0);
        if (!$isI16) {
            return;
        }
        $this->encTerminate(0);
        $this->encDecision(33, $luma15 ? 1 : 0);
        $this->encDecision(34, $chromaCbp > 0 ? 1 : 0);
        if ($chromaCbp > 0) {
            $this->encDecision(34, $chromaCbp - 1);
        }
        $this->encDecision(35, ($mode >> 1) & 1);
        $this->encDecision(35, $mode & 1);
    }

    /**
     * intra4x4 预测模式（most_probable_mode 路径 + LSB 先行 3 bin）。
     * $dct8=true（I8x8）：镜像解码端 fill_rectangle——模式同时填 2x2 cache 单元，
     * 后续 8x8 分组的 mostProbableMode 邻居推导依赖该填充。
     */
    private function encWriteI4Mode(int $n, int $mode, bool $dct8 = false): void
    {
        $pred = $this->cabPredModeN($n);
        $cell = self::$SCAN8[$n];
        $this->cabI4Cache[$cell] = $mode;
        if ($dct8) {
            $this->cabI4Cache[$cell + 1] = $mode;
            $this->cabI4Cache[$cell + 8] = $mode;
            $this->cabI4Cache[$cell + 9] = $mode;
        }
        if ($mode === $pred) {
            $this->encDecision(68, 1);
            return;
        }
        $this->encDecision(68, 0);
        $m = $mode > $pred ? $mode - 1 : $mode;
        $this->encDecision(69, $m & 1);
        $this->encDecision(69, ($m >> 1) & 1);
        $this->encDecision(69, ($m >> 2) & 1);
    }

    /** 色度预测模式：0/1/2/3 前缀树，并写回 picChromaPred 供后续邻居 ctx */
    private function encWriteChromaPred(int $mode): void
    {
        $l = $this->cabLeftMbIdx();
        $t = $this->cabTopMbIdx();
        $ctx = 0;
        if ($l >= 0 && $this->picChromaPred[$l] !== 0) {
            $ctx++;
        }
        if ($t >= 0 && $this->picChromaPred[$t] !== 0) {
            $ctx++;
        }
        $this->picChromaPred[$this->cabMbIdx] = $mode;
        if ($mode === 0) {
            $this->encDecision(64 + $ctx, 0);
            return;
        }
        $this->encDecision(64 + $ctx, 1);
        if ($mode === 1) {
            $this->encDecision(67, 0);
            return;
        }
        $this->encDecision(67, 1);
        if ($mode === 2) {
            $this->encDecision(67, 0);
            return;
        }
        $this->encDecision(67, 1);
    }

    /** cbp 亮度 4 位（逐位推进，镜像解码顺序） */
    private function encWriteCbpLuma(int $cbp): void
    {
        [$cbpA, $cbpB] = $this->cabNeighborCbp();
        $c = 0;
        $ctx = (($cbpA & 0x02) === 0 ? 1 : 0) + 2 * (($cbpB & 0x04) === 0 ? 1 : 0);
        $b = $cbp & 1;
        $this->encDecision(73 + $ctx, $b);
        $c |= $b;
        $ctx = (($c & 0x01) === 0 ? 1 : 0) + 2 * (($cbpB & 0x08) === 0 ? 1 : 0);
        $b = ($cbp >> 1) & 1;
        $this->encDecision(73 + $ctx, $b);
        $c |= $b << 1;
        $ctx = (($cbpA & 0x08) === 0 ? 1 : 0) + 2 * (($c & 0x01) === 0 ? 1 : 0);
        $b = ($cbp >> 2) & 1;
        $this->encDecision(73 + $ctx, $b);
        $c |= $b << 2;
        $ctx = (($c & 0x04) === 0 ? 1 : 0) + 2 * (($c & 0x02) === 0 ? 1 : 0);
        $this->encDecision(73 + $ctx, ($cbp >> 3) & 1);
    }

    /** cbp 色度（0/1/2，双段 ctx） */
    private function encWriteCbpChroma(int $cbpChroma): void
    {
        [$lCbp, $tCbp] = $this->cabNeighborCbp();
        $cbpA = ($lCbp >> 4) & 0x03;
        $cbpB = ($tCbp >> 4) & 0x03;
        $ctx = ($cbpA > 0 ? 1 : 0) + ($cbpB > 0 ? 2 : 0);
        if ($cbpChroma === 0) {
            $this->encDecision(77 + $ctx, 0);
            return;
        }
        $this->encDecision(77 + $ctx, 1);
        $ctx = 4 + ($cbpA === 2 ? 1 : 0) + ($cbpB === 2 ? 2 : 0);
        $this->encDecision(77 + $ctx, $cbpChroma - 1);
    }

    /** mb_qp_delta：一元码（首 bin ctx 60+2，其余 60+3），更新 cabQp/cabLastQpDiff */
    private function encWriteQpDelta(int $delta): void
    {
        $this->encDecision(60 + ($this->cabLastQpDiff !== 0 ? 1 : 0), $delta === 0 ? 0 : 1);
        if ($delta === 0) {
            $this->cabLastQpDiff = 0;
            return;
        }
        // 映射：d>0 → val=2d-1（奇），d<0 → val=2|d|（偶）
        $val = $delta > 0 ? 2 * $delta - 1 : -2 * $delta;
        // 一元续位：首个 @62，其余 @63；终止 0 与解码器同 ctx（val=1 时终止 @62）
        if ($val === 1) {
            $this->encDecision(62, 0);
        } else {
            for ($i = 0; $i < $val - 1; $i++) {
                $this->encDecision($i === 0 ? 62 : 63, 1);
            }
            $this->encDecision(63, 0);
        }
        $this->cabLastQpDiff = $val;
        $qp = $this->cabQp + $delta;
        if ($qp < 0) {
            $qp += 52;
        } elseif ($qp > 51) {
            $qp -= 52;
        }
        $this->cabQp = $qp;
    }

    /**
     * mvd（decode_cabac_mvd 镜像）。
     * @param int $n 分区首块号（P 16x16 为 0）
     */
    private function encWriteMvd(int $list, int $comp, int $n, int $mvd): void
    {
        $store = $list === 0 ? $this->picMvd0 : $this->picMvd1;
        [$lMb, $lBlk, $tMb, $tBlk] = $this->cabNbBlock($n);
        $a = $store[$lMb * 16 + $lBlk] ?? [0, 0];
        $b = $store[$tMb * 16 + $tBlk] ?? [0, 0];
        $amvd = min(70, abs($a[$comp] ?? 0)) + min(70, abs($b[$comp] ?? 0));

        $ctxBase = $comp === 0 ? 40 : 47;
        $ctx = $ctxBase + 2 - ($amvd < 3 ? 1 : 0) - ($amvd < 33 ? 1 : 0);
        $m = abs($mvd);
        if ($m === 0) {
            $this->encDecision($ctx, 0);
            return;
        }
        $this->encDecision($ctx, 1);
        $ctxBase += 3; // 43 / 50
        if ($m <= 8) {
            // 一元段：第 i 个 1 的 ctx 为 43/44/45/46,46...
            for ($i = 1; $i < $m; $i++) {
                $this->encDecision($ctxBase + ($i < 4 ? $i - 1 : 3), 1);
            }
            $this->encDecision($ctxBase + ($m < 4 ? $m - 1 : 3), 0);
        } else {
            // 8 个 1 + escape
            for ($i = 1; $i < 9; $i++) {
                $this->encDecision($ctxBase + ($i < 4 ? $i - 1 : 3), 1);
            }
            $mv = 9;
            $k = 3;
            while ($m - $mv >= (1 << $k)) {
                $this->encBypass(1);
                $mv += 1 << $k;
                $k++;
            }
            $this->encBypass(0);
            $rem = $m - $mv;
            for ($j = $k - 1; $j >= 0; $j--) {
                $this->encBypass(($rem >> $j) & 1);
            }
        }
        // 符号：约定 bypass bit 1=负 0=正（解码器 cabBypassSign(-val)：bit=1 → ret=val<0）
        $this->encBypass($mvd < 0 ? 1 : 0);
    }

    // ============================================================
    // 残差写入器（decode_cabac_residual_internal 镜像）
    // ============================================================

    /**
     * 残差核心：sig/last 循环 + 逆序 level（L1/GT1 状态机 + escape）。
     * @param array $levels 扫描位 k（0..maxCoeff-1）=> 带符号非零系数；0 系数不出现
     */
    private function encWriteResidualCore(int $cat, array $levels, int $maxCoeff): void
    {
        $sigBase = self::$SIG_BASE[$cat];
        $lastBase = self::$LAST_BASE[$cat];
        $levBase = self::$LEV_BASE[$cat];

        // 最后显著扫描位
        $lastSig = -1;
        for ($k = $maxCoeff - 1; $k >= 0; $k--) {
            if (isset($levels[$k]) && $levels[$k] !== 0) {
                $lastSig = $k;
                break;
            }
        }
        // 显著位早于末位：逐位写 sig/last；否则尾部隐含（无 bin）
        for ($k = 0; $k < $lastSig; $k++) {
            $nz = isset($levels[$k]) && $levels[$k] !== 0;
            $this->encDecision($sigBase + $k, $nz ? 1 : 0);
            if ($nz) {
                $this->encDecision($lastBase + $k, 0);
            }
        }
        if ($lastSig < $maxCoeff - 1) {
            $this->encDecision($sigBase + $lastSig, 1);
            $this->encDecision($lastBase + $lastSig, 1);
        }

        // level 逆序（从最后显著位到首个）
        $ks = array_keys($levels);
        $nodeCtx = 0;
        for ($i = count($ks) - 1; $i >= 0; $i--) {
            $k = $ks[$i];
            $lv = abs($levels[$k]);
            $ctx = $levBase + self::$LV1_CTX[$nodeCtx];
            if ($lv === 1) {
                $this->encDecision($ctx, 0);
                $nodeCtx = self::$TRANS_L1[$nodeCtx];
            } else {
                $this->encDecision($ctx, 1);
                $ctxG = $levBase + self::$GT1_CTX[$nodeCtx];
                $nodeCtx = self::$TRANS_GT1[$nodeCtx];
                if ($lv < 15) {
                    // 一元：lv-2 个 1 + 终止 0（coeffAbs 2..14）
                    for ($t = 0; $t < $lv - 2; $t++) {
                        $this->encDecision($ctxG, 1);
                    }
                    $this->encDecision($ctxG, 0);
                } else {
                    // 13 个 1 推到 coeffAbs=15 后走 escape
                    for ($t = 0; $t < 13; $t++) {
                        $this->encDecision($ctxG, 1);
                    }
                    // escape：v = lv-14 = 2^e + rem（e 位 MSB 先行）
                    $v = $lv - 14;
                    $e = 0;
                    while ((1 << ($e + 1)) <= $v) {
                        $e++;
                    }
                    $rem = $v - (1 << $e);
                    for ($t = 0; $t < $e; $t++) {
                        $this->encBypass(1);
                    }
                    $this->encBypass(0);
                    for ($t = $e - 1; $t >= 0; $t--) {
                        $this->encBypass(($rem >> $t) & 1);
                    }
                }
            }
            // 符号：约定 bypass bit 1=负 0=正
            $this->encBypass($levels[$k] < 0 ? 1 : 0);
        }
    }

    /**
     * 单个 8x8 亮度残差（cat5，cabResidualLuma8x8 镜像）：
     * 无 CBF bin（cbp 位门控，由调用方保证非零）；sig/last 按扫描位 k 查
     * SIG8_FRAME/LAST8_FRAME（非 raster 位）；k=63 为隐含显著位（无 bin）；
     * level 复用 LV1/GT1/TRANS 状态机；结束后 nzCache 2x2 四 cell 同值写回。
     *
     * @param int   $p8     8x8 块号 0..3
     * @param array $levels 扫描位 k => 带符号量化 level（0 系数不出现）
     */
    private function encWriteResidual8x8(int $p8, array $levels): void
    {
        $sigBase = self::$SIG_BASE[5];
        $lastBase = self::$LAST_BASE[5];
        $levBase = self::$LEV_BASE[5];

        // 最后显著扫描位（k=63 隐含，无需 sig/last bin）
        $lastSig = -1;
        for ($k = 63; $k >= 0; $k--) {
            if (isset($levels[$k]) && $levels[$k] !== 0) {
                $lastSig = $k;
                break;
            }
        }
        // 调用方 cbp 位=1 保证至少一个非零系数；防御性返回保持引擎同步（无 bin）
        if ($lastSig < 0) {
            $cell = self::$SCAN8[4 * $p8];
            $this->cabNzCache[$cell] = 0;
            $this->cabNzCache[$cell + 1] = 0;
            $this->cabNzCache[$cell + 8] = 0;
            $this->cabNzCache[$cell + 9] = 0;
            return;
        }
        for ($k = 0; $k < $lastSig; $k++) {
            $nz = isset($levels[$k]) && $levels[$k] !== 0;
            $this->encDecision($sigBase + self::$SIG8_FRAME[$k], $nz ? 1 : 0);
            if ($nz) {
                $this->encDecision($lastBase + self::$LAST8_FRAME[$k], 0);
            }
        }
        if ($lastSig < 63) {
            $this->encDecision($sigBase + self::$SIG8_FRAME[$lastSig], 1);
            $this->encDecision($lastBase + self::$LAST8_FRAME[$lastSig], 1);
        }

        // level 逆序（从最后显著位到首个），状态机与 encWriteResidualCore 一致
        $ks = array_keys($levels);
        $nodeCtx = 0;
        for ($i = count($ks) - 1; $i >= 0; $i--) {
            $k = $ks[$i];
            $lv = abs($levels[$k]);
            $ctx = $levBase + self::$LV1_CTX[$nodeCtx];
            if ($lv === 1) {
                $this->encDecision($ctx, 0);
                $nodeCtx = self::$TRANS_L1[$nodeCtx];
            } else {
                $this->encDecision($ctx, 1);
                $ctxG = $levBase + self::$GT1_CTX[$nodeCtx];
                $nodeCtx = self::$TRANS_GT1[$nodeCtx];
                if ($lv < 15) {
                    for ($t = 0; $t < $lv - 2; $t++) {
                        $this->encDecision($ctxG, 1);
                    }
                    $this->encDecision($ctxG, 0);
                } else {
                    // 13 个 1 推到 coeffAbs=15 后走 escape
                    for ($t = 0; $t < 13; $t++) {
                        $this->encDecision($ctxG, 1);
                    }
                    $v = $lv - 14;
                    $e = 0;
                    while ((1 << ($e + 1)) <= $v) {
                        $e++;
                    }
                    $rem = $v - (1 << $e);
                    for ($t = 0; $t < $e; $t++) {
                        $this->encBypass(1);
                    }
                    $this->encBypass(0);
                    for ($t = $e - 1; $t >= 0; $t--) {
                        $this->encBypass(($rem >> $t) & 1);
                    }
                }
            }
            $this->encBypass($levels[$k] < 0 ? 1 : 0);
        }

        // nz：四个 4x4 块同值（fill_rectangle(&nz_cache[scan8[4*i8x8]],2,2,8)）
        $nz = count($ks);
        $cell = self::$SCAN8[4 * $p8];
        $this->cabNzCache[$cell] = $nz;
        $this->cabNzCache[$cell + 1] = $nz;
        $this->cabNzCache[$cell + 8] = $nz;
        $this->cabNzCache[$cell + 9] = $nz;
    }

    /**
     * 单个 4x4 非 DC 残差块（含 CBF 与 nzCache 写回）。
     * @param array $block 16 项 raster 系数（zz15 时仅 raster[1..15] 参与编码）
     */
    private function encWriteAcBlock(int $cat, int $n, array $block, bool $zz15): void
    {
        $cell = self::$SCAN8[$n];
        if ($zz15) {
            $scan = [];
            for ($k = 0; $k < 15; $k++) {
                $scan[$k] = self::ZIGZAG_SCAN_4X4[$k + 1];
            }
            $maxCoeff = 15;
        } else {
            $scan = self::ZIGZAG_SCAN_4X4;
            $maxCoeff = 16;
        }
        $levels = [];
        for ($k = 0; $k < $maxCoeff; $k++) {
            $v = $block[$scan[$k]];
            if ($v !== 0) {
                $levels[$k] = $v;
            }
        }
        if (!count($levels)) {
            $this->encDecision($this->cabCbfAcCtx($cat, $n), 0);
            $this->cabNzCache[$cell] = 0;
            return;
        }
        $this->encDecision($this->cabCbfAcCtx($cat, $n), 1);
        $this->encWriteResidualCore($cat, $levels, $maxCoeff);
        $this->cabNzCache[$cell] = count($levels);
    }

    /** I16 亮度 DC（16 项 raster 系数，内部按 zigzag 扫描编码——scan=zigzag_scan，镜像解码器）。CBF=1 时镜像解码器：不写 nzCache、置 picCbp bit8 */
    private function encWriteLumaDcI16(array $dcScan16): void
    {
        [$lCbp, $tCbp] = $this->cabNeighborCbp();
        $nza = ($lCbp & 0x100) ? 1 : 0;
        $nzb = ($tCbp & 0x100) ? 1 : 0;
        $levels = [];
        for ($k = 0; $k < 16; $k++) {
            $v = $dcScan16[self::ZIGZAG_SCAN_4X4[$k]];
            if ($v !== 0) {
                $levels[$k] = $v;
            }
        }
        if (!count($levels)) {
            $this->encDecision(self::$CBF_BASE[0] + $nza + 2 * $nzb, 0);
            $this->cabNzCache[self::$SCAN8[0]] = 0;
            return;
        }
        $this->encDecision(self::$CBF_BASE[0] + $nza + 2 * $nzb, 1);
        $this->encWriteResidualCore(0, $levels, 16);
        $this->picCbp[$this->cabMbIdx] |= 0x100;
    }

    /** 色度 DC（4 系数，扫描序 0..3；CBF=1 置 picCbp 对应位） */
    private function encWriteChromaDc(int $c, array $dc4): void
    {
        $bit = 6 + $c;
        [$lCbp, $tCbp] = $this->cabNeighborCbp();
        $nza = ($lCbp & (1 << $bit)) ? 1 : 0;
        $nzb = ($tCbp & (1 << $bit)) ? 1 : 0;
        $levels = [];
        for ($k = 0; $k < 4; $k++) {
            if ($dc4[$k] !== 0) {
                $levels[$k] = $dc4[$k];
            }
        }
        if (!count($levels)) {
            $this->encDecision(self::$CBF_BASE[3] + $nza + 2 * $nzb, 0);
            $this->cabNzCache[self::$SCAN8[16 + $c]] = 0;
            return;
        }
        $this->encDecision(self::$CBF_BASE[3] + $nza + 2 * $nzb, 1);
        $this->encWriteResidualCore(3, $levels, 4);
        $this->picCbp[$this->cabMbIdx] |= (1 << $bit);
    }

    /**
     * inter/I4 残差全套（cabDecodeInterResidual 镜像，非 8x8 路径）：
     * 亮度 16 块（cat2）→ 色度 DC（cat3，cbpChroma>0）→ 色度 AC 平面优先（cat4，cbpChroma≥2）。
     * $dct8=true（T5 inter 8x8DCT）：亮度逐 p8 重装 64 系数写 encWriteResidual8x8
     *（无逐块 CBF，cbp 位门控），色度仍 4x4。
     *
     * @param array $lumaBlocks 16 个 raster 16 系数块（dct8 时为 8x8 系数按 4x4 子块 raster 重装）
     * @param array $chromaDc    [cb 4 系数, cr 4 系数]（扫描序）
     * @param array $chromaAc    [cb 4 块 raster, cr 4 块 raster]
     */
    private function encWriteInterResidual(
        int $cbpLuma,
        int $cbpChroma,
        array $lumaBlocks,
        array $chromaDc,
        array $chromaAc,
        int $chromaQp,
        bool $dct8 = false
    ): void {
        if ($dct8) {
            $zz8 = self::$ZZ8_SCAN;
            $scanJ = [];
            for ($k = 0; $k < 64; $k++) {
                $scanJ[$k] = ($zz8[$k] >> 3) | (($zz8[$k] & 7) << 3);
            }
            for ($p8 = 0; $p8 < 4; $p8++) {
                if (($cbpLuma & (1 << $p8)) !== 0) {
                    // 4x4 子块 raster 重装回 8x8 存储布局（与 prepare 端打包互逆）
                    $base = ($p8 >> 1) * 8 + ($p8 & 1) * 2;
                    $stored = array_fill(0, 64, 0);
                    for ($sub = 0; $sub < 4; $sub++) {
                        $subY = $sub >> 1;
                        $subX = $sub & 1;
                        // 4x4 子块 raster 序：blkIdx = base + subY*4 + subX
                        $blk = $lumaBlocks[$base + $subY * 4 + $subX];
                        for ($yy = 0; $yy < 4; $yy++) {
                            for ($xx = 0; $xx < 4; $xx++) {
                                $stored[($subY * 4 + $yy) * 8 + $subX * 4 + $xx] = $blk[$yy * 4 + $xx];
                            }
                        }
                    }
                    // 扫描位 k → 存储位 scanJ[k]（TRANSPOSE8(ZZ8)，镜像 cabResidualLuma8x8）
                    $levels = [];
                    for ($k = 0; $k < 64; $k++) {
                        $v = $stored[$scanJ[$k]];
                        if ($v !== 0) {
                            $levels[$k] = $v;
                        }
                    }
                    $this->encWriteResidual8x8($p8, $levels);
                } else {
                    $cell = self::$SCAN8[4 * $p8];
                    $this->cabNzCache[$cell] = 0;
                    $this->cabNzCache[$cell + 1] = 0;
                    $this->cabNzCache[$cell + 8] = 0;
                    $this->cabNzCache[$cell + 9] = 0;
                }
            }
        } else {
            for ($n = 0; $n < 16; $n++) {
                if ($cbpLuma & (1 << ($n >> 2))) {
                    // lumaBlocks 按 raster 块号构造，scan8 块号 n 须经 N2R 映射回 raster
                    $this->encWriteAcBlock(2, $n, $lumaBlocks[self::$N2R[$n]], false);
                } else {
                    $this->cabNzCache[self::$SCAN8[$n]] = 0;
                }
            }
        }
        if ($cbpChroma > 0) {
            $this->encWriteChromaDc(0, $chromaDc[0]);
            $this->encWriteChromaDc(1, $chromaDc[1]);
        }
        if ($cbpChroma >= 2) {
            for ($b = 0; $b < 4; $b++) {
                $this->encWriteAcBlock(4, 16 + $b, $chromaAc[0][$b], true);
            }
            for ($b = 0; $b < 4; $b++) {
                $this->encWriteAcBlock(4, 32 + $b, $chromaAc[1][$b], true);
            }
        }
    }

    // ============================================================
    // 宏块收尾（写回 scan8 持久存储 + picMbType 标志）
    // ============================================================

    /**
     * I4 宏块收尾：modesN 为 n 序（0..15）的 intra4x4 预测模式。
     * $dct8=true（I8x8）：picMbType 额外 OR CAB_FLG_DCT8，
     * 后续宏块 transform_size_8x8_flag 的邻居 ctx（399+n）依赖该标志。
     */
    private function encFinishI4(array $modesN, bool $dct8 = false): void
    {
        $this->picMbType[$this->cabMbIdx] |= self::$CAB_FLG_I4 | ($dct8 ? self::$CAB_FLG_DCT8 : 0);
        $this->cabStoreWriteBack(true, $modesN);
    }

    /** I16 宏块收尾 */
    private function encFinishI16(): void
    {
        $this->picMbType[$this->cabMbIdx] |= self::$CAB_FLG_I16;
        $this->cabStoreWriteBack(false, []);
    }

    /** inter 宏块收尾（picMbType 保持 0；$dct8 时 OR CAB_FLG_DCT8 供邻居 transform_size ctx） */
    private function encFinishInter(bool $dct8 = false): void
    {
        if ($dct8) {
            $this->picMbType[$this->cabMbIdx] |= self::$CAB_FLG_DCT8;
        }
        $this->cabStoreWriteBack(false, []);
    }
}
