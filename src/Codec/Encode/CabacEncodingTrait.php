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
    // CabacTablesTrait：CABAC 状态表/初值表、scan8/zigzag/N2R 等几何常量
    use CabacDecodingTrait;
    // 复用解码侧邻居簿记与语法辅助（cabFillCaches/cabNbBlock/cabNeighborCbp 等）

    // ============================================================
    // 引擎状态（init_cabac_encoder：low=0, range=0x1FE, outstanding=0）
    // ============================================================
    // H.264 9.3.4.3 算术编码引擎：low/range 为当前区间，outstanding 为挂起位数

    /** 位累加输出缓冲（字节打包，MSB 先行） */
    private string $encBuf = '';
    // 区间下界 low（renorm 时左移，溢出位经 outstanding 机制写出）
    private int $encLow = 0;
    // 区间宽度 range（初值 0x1FE，9.3.4.3.2）
    private int $encRange = 0x1FE;
    // 挂起位计数 outstanding：low 落入 [0x100,0x200) 时累计
    private int $encOutstanding = 0;
    // 当前字节的位累加器（MSB 先行，满 8 位落盘）
    private int $encAcc = 0;
    // 累加器已填位数（0..7）
    private int $encAccBits = 0;
    /**  pb.bit_left++：首个写出位被丢弃 */
    private bool $encFirstBit = true;

    // ============================================================
    // 邻居簿记所需的画布级数组（解码 trait 引用，编码侧同样维护）
    // ============================================================
    // 按 raster 宏块序保存整帧簿记，供邻居上下文推导（6.4.3、6.4.9）
    // picMbType：I16/PCM/DCT8 标志影响 mb_type 与 transform_size_8x8_flag 的邻居 ctx
    // picMvd0/1：索引 = MB raster 号*16 + 4x4 块号，mvd 的 amvd 邻居由此读取

    // MB 行数：cabMbY>0 时上邻才可用
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

    // cabac_init_idc（0/1/2）：P/B 片选 PB0/PB1/PB2 初值表（7.4.3）
    public int $cabacInitIdc = 0;
    // 1024 个上下文状态，值 = 2*stateIdx + MPS（最低位即 MPS）
    public array $cabacStates = [];
    // 解码端 RBSP 缓冲（编码路径不使用，保留字段与解码结构对齐）
    public string $cabacData = '';
    // 解码端字节读指针
    public int $cabacPtr = 0;
    // 解码端字节上界
    public int $cabacEnd = 0;
    // 解码端区间下界（编码使用 encLow）
    public int $cabacLow = 0;
    // 解码端区间宽度（编码使用 encRange）
    public int $cabacRange = 0;

    // ============================================================
    // 帧级 / 片级
    // ============================================================

    /** 帧开始：重置 CABAC 邻居簿记画布（每帧第一片前调用一次） */
    private function encFrameBegin(): void
    {
        // 画布：每 MB 一项的类型/CBP/skip/direct/色度模式，每 MB 16 项 MVD
        $mbCount = $this->picWidthInMbs * $this->picHeightInMbs;
        $this->picMbType = array_fill(0, $mbCount, 0);
        $this->picCbp = array_fill(0, $mbCount, 0);
        $this->picIsSkip = array_fill(0, $mbCount, 0);
        $this->picIsDirectMb = array_fill(0, $mbCount, 0);
        $this->picChromaPred = array_fill(0, $mbCount, 0);
        // MVD 画布：每 MB 16 个 4x4 块，[x,y] 分量
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
        // 每片必须复位，避免上一片的进位状态残留
        // 引擎复位（init_cabac_encoder）
        $this->encBuf = '';
        $this->encLow = 0;
        $this->encRange = 0x1FE;
        $this->encOutstanding = 0;
        $this->encAcc = 0;
        $this->encAccBits = 0;
        $this->encFirstBit = true;

        // 上下文初始化（cabac_init_idc + slice_qp）
        // I 片用 cabacCtxInitI；P/B 片按 cabacInitIdc 选表
        $this->cabacInitStates($sliceQp, $sliceTypeNos);

        // 存储重置（镜像 cabacSliceBegin，去掉解码专用 cabacStart）
        $mbCount = $this->picWidthInMbs * $this->picHeightInMbs;
        // 48 项/MB：亮度 16 + Cb 16 + Cr 16（nz 布局）
        $this->cabNzStore = array_fill(0, $mbCount * 48, 0);
        // i4 模式默认 2（DC），供不可用邻居的 mostProbableMode
        $this->cabI4Store = array_fill(0, $mbCount * 16, 2);
        // scan8 cache 120 单元，nz 初值 0
        $this->cabNzCache = array_fill(0, 120, 0);
        // i4 cache 120 单元，-1 表示不可用
        $this->cabI4Cache = array_fill(0, 120, -1);
        // QPY 截断到 0..51（7.4.3）
        $this->cabQp = max(0, min(51, $sliceQp));
        // 上一 MB 的 mb_qp_delta 置 0
        $this->cabLastQpDiff = 0;
    }

    /** 片结束：取出 CABAC 位流字节（含零填充对齐） */
    private function encGetBytes(): string
    {
        // 尾部位不足 8 位时左移补零落盘（MSB 先行）
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
        // 首个位只翻转开关不写：等价参考实现的 bit_left++ 首位丢弃
        if ($this->encFirstBit) {
            $this->encFirstBit = false;
            return;
        }
        $this->encAcc = (($this->encAcc << 1) | $b) & 0xFF;
        // 满 8 位后整字节写出
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
        // 9.3.4.3.3：先写 b，再补写 outstanding 个 (1-b)
        while ($this->encOutstanding > 0) {
            $this->encOutstanding--;
            // 挂起位逐个写成 b 的反码
            $this->encRawBit(1 - $b);
        }
    }

    /** renorm_cabac_encoder：阈值 256/512 */
    private function encRenorm(): void
    {
        // range<0x100 时反复重归一化（9.3.4.3.2 renorm_cabac_encoder）
        while ($this->encRange < 0x100) {
            // 按 low 区间分支：<0x100 / [0x100,0x200) / ≥0x200
            if ($this->encLow < 0x100) {
                $this->encPutBit(0);
            // low<0x100：直接输出 0
            } elseif ($this->encLow < 0x200) {
                $this->encOutstanding++;
                // 中间区：不输出，挂起 +1 并减 0x100
                $this->encLow -= 0x100;
            } else {
                $this->encPutBit(1);
                // 高区：输出 1 并减 0x200
                $this->encLow -= 0x200;
            }
            $this->encRange += $this->encRange;
            // range 与 low 同时左移一位
            $this->encLow += $this->encLow;
        }
    }

    /** put_cabac：常规 bin（状态 s = 2*stateIdx + mps，与解码器同表同索引） */
    private function encDecision(int $ctxIdx, int $binVal): void
    {
        $s = $this->cabacStates[$ctxIdx];
        // LPS 区间表：range 高位 (range&0xC0) 选段、状态 s 选表项
        $rangeLps = self::$cabacLpsRange[2 * ($this->encRange & 0xC0) + $s];
        // bin 是否等于 MPS（cabacStates 最低位）
        if ($binVal === ($s & 1)) {
            // MPS：区间取大段
            $this->encRange -= $rangeLps;
            // MPS：range 减去 LPS 段，状态按 transIdxMPS 转移
            $this->cabacStates[$ctxIdx] = self::$cabacMlps[128 + $s];
        } else {
            // LPS：区间取小段
            $this->encLow += $this->encRange - $rangeLps;
            // LPS：low 越过 MPS 段，range 取 LPS 段，状态按 transIdxLPS 转移
            $this->encRange = $rangeLps;
            $this->cabacStates[$ctxIdx] = self::$cabacMlps[127 - $s];
        }
        // 每次状态更新后重归一化
        $this->encRenorm();
    }

    /** put_cabac_bypass：阈值 512/1024 */
    private function encBypass(int $bit): void
    {
        // bypass：low 左移一位，bin=1 时再加 range
        $this->encLow += $this->encLow;
        if ($bit) {
            $this->encLow += $this->encRange;
        }
        // low 三分支：<0x200 输出 0
        if ($this->encLow < 0x200) {
            $this->encPutBit(0);
        } elseif ($this->encLow < 0x400) {
            // [0x200,0x400)：挂起并减 0x200
            $this->encOutstanding++;
            $this->encLow -= 0x200;
        } else {
            $this->encPutBit(1);
            // ≥0x400：输出 1 并减 0x400
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
        // range 先减 2（9.3.4.3.4）
        if (!$bit) {
            // bit=0：普通 terminate bin，仅 renorm
            $this->encRenorm();
            return;
        }
        $this->encLow += $this->encRange;
        // bit=1：low 跨过终止区间，range 固定为 2
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
        // 左/上邻居非 skip 各贡献 1（不可用邻居不计）
        $w = $this->picWidthInMbs;
        $ctx = 0;
        // 左邻存在且非 skip
        if ($this->cabMbX > 0 && empty($this->picIsSkip[$mbIdx - 1])) {
            $ctx++;
        }
        // 上邻存在且非 skip
        if ($this->cabMbY > 0 && empty($this->picIsSkip[$mbIdx - $w])) {
            $ctx++;
        }
        if ($isB) {
            // B 片上下文整体偏移 +13
            $ctx += 13;
        }
        $this->encDecision(11 + $ctx, $skip ? 1 : 0);
    }

    /** P mb_type：0=P_L0_16x16 1=16x8 2=8x16 3=8x8；-1=intra（bin14=1） */
    private function encWritePMbType(int $mbType): void
    {
        if ($mbType < 0) {
            // bin@14：1=intra mb_type，直接结束
            $this->encDecision(14, 1);
            return;
        }
        $this->encDecision(14, 0);
        // bin@14=0：inter 前缀树
        if ($mbType === 0 || $mbType === 3) {
            // @15=0：0=P_L0_16x16 / 3=P_8x8
            $this->encDecision(15, 0);
            // @16：1=P_8x8，0=P_L0_16x16
            $this->encDecision(16, $mbType === 3 ? 1 : 0);
        } else {
            // @15=1：1=16x8 / 2=8x16
            $this->encDecision(15, 1);
            // @17：1=16x8，0=8x16
            $this->encDecision(17, $mbType === 1 ? 1 : 0);
        }
    }

    /** B mb_type direct 上下文（左/上非 direct 计数） */
    private function cabBDirectCtx(): int
    {
        $mbIdx = $this->cabMbIdx;
        // 左/上非 direct 各 +1，返回 0..2
        $w = $this->picWidthInMbs;
        $ctx = 0;
        if ($this->cabMbX > 0 && empty($this->picIsDirectMb[$mbIdx - 1])) {
            $ctx++;
        }
        // 上邻存在且非 direct
        if ($this->cabMbY > 0 && empty($this->picIsDirectMb[$mbIdx - $w])) {
            $ctx++;
        }
        return $ctx;
    }

    /** B_Direct_16x16：bin(27+ctx)=0 */
    private function encWriteBMbTypeDirect(): void
    {
        // bin=0 即 B_Direct_16x16（ctxIdx=27+ctx）
        $this->encDecision(27 + $this->cabBDirectCtx(), 0);
    }

    /** B 16x16 inter：mbType 1=L0 2=L1 3=Bi */
    private function encWriteBMbTypeInter(int $mbType): void
    {
        $this->encDecision(27 + $this->cabBDirectCtx(), 1);
        // bin@27+ctx=1：非 direct
        if ($mbType === 1 || $mbType === 2) {
            // L0/L1：bin@30=0，再用 @32 区分
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
        // @27=1：非 direct
        $this->encDecision(30, 1);
        // @30=1：非 16x16 inter
        $this->encDecision(31, 1);
        // @31=1：进入 intra 分支
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
        // ctxIdxInc：左/上为 I16 或 PCM 各 +1
        if ($l >= 0 && ($this->picMbType[$l] & $flg)) {
            $ctx++;
        }
        if ($t >= 0 && ($this->picMbType[$t] & $flg)) {
            $ctx++;
        }
        $this->encDecision(3 + $ctx, $isI16 ? 1 : 0);
        // bin@3+ctx：1=I16，0=I4（I8x8 另用 transform_size 标志区分）
        if (!$isI16) {
            return;
        }
        $this->encTerminate(0); // 非 PCM
        // state = ctxBase+2 = 5
        // bin@6：亮度 AC 是否非零（luma15）
        // bin@7：色度 CBP 是否非零
        $this->encDecision(6, $luma15 ? 1 : 0);
        $this->encDecision(7, $chromaCbp > 0 ? 1 : 0);
        if ($chromaCbp > 0) {
            // bin@8：色度 CBP 值 -1（0/1）
            $this->encDecision(8, $chromaCbp - 1);
        }
        // bin@9、@10：Intra16x16PredMode 高位 p1、低位 p0
        $this->encDecision(9, ($mode >> 1) & 1); // p1
        $this->encDecision(10, $mode & 1);       // p0
    }

    /**
     * B 片内嵌 intra（decode_cabac_intra_mb_type(32,0)
     */
    private function encWriteIntraMbTypeBIntra(bool $isI16, int $luma15, int $chromaCbp, int $mode): void
    {
        $this->encDecision(32, $isI16 ? 1 : 0);
        // bin@32：1=I16，0=I4
        if (!$isI16) {
            return;
        }
        $this->encTerminate(0);
        // bin@33：亮度 AC 是否非零
        // bin@34：色度 CBP 是否非零
        $this->encDecision(33, $luma15 ? 1 : 0);
        $this->encDecision(34, $chromaCbp > 0 ? 1 : 0);
        if ($chromaCbp > 0) {
            // bin@34：色度 CBP 值 -1
            $this->encDecision(34, $chromaCbp - 1);
        }
        // bin@35：模式 p1、p0（同一 ctx）
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
        // mostProbableMode：由左/上 4x4 邻居模式推导（8.3.1.1）
        $cell = self::$SCAN8[$n];
        // scan8 定位 cache 单元；随后写回，供后续块推导
        $this->cabI4Cache[$cell] = $mode;
        if ($dct8) {
            // I8x8：同模式填入 2x2 四单元（+1/+8/+9）
            $this->cabI4Cache[$cell + 1] = $mode;
            $this->cabI4Cache[$cell + 8] = $mode;
            $this->cabI4Cache[$cell + 9] = $mode;
        }
        if ($mode === $pred) {
            // 与预测一致：flag=1（@68）
            $this->encDecision(68, 1);
            return;
        }
        $this->encDecision(68, 0);
        // 不一致：flag=0（@68）
        $m = $mode > $pred ? $mode - 1 : $mode;
        // 去掉预测值的码号（mode>pred 时减 1）
        $this->encDecision(69, $m & 1);
        // 3 位定长、LSB 先行（9.3.2.3）
        $this->encDecision(69, ($m >> 1) & 1);
        // 第 2 位
        $this->encDecision(69, ($m >> 2) & 1);
    }

    /** 色度预测模式：0/1/2/3 前缀树，并写回 picChromaPred 供后续邻居 ctx */
    private function encWriteChromaPred(int $mode): void
    {
        $l = $this->cabLeftMbIdx();
        $t = $this->cabTopMbIdx();
        $ctx = 0;
        // ctx：左/上色度模式非 DC(0) 各 +1（表 9-34）
        if ($l >= 0 && $this->picChromaPred[$l] !== 0) {
            // 左邻存在且非 DC
            $ctx++;
        }
        if ($t >= 0 && $this->picChromaPred[$t] !== 0) {
            // 上邻存在且非 DC
            $ctx++;
        }
        $this->picChromaPred[$this->cabMbIdx] = $mode;
        // 写回，供后续 MB 的邻居 ctx
        if ($mode === 0) {
            // 0=DC：@64+ctx=0
            $this->encDecision(64 + $ctx, 0);
            return;
        }
        $this->encDecision(64 + $ctx, 1);
        // 非 DC：首 bin=1
        if ($mode === 1) {
            // 1=Horizontal：@67=0
            $this->encDecision(67, 0);
            return;
        }
        $this->encDecision(67, 1);
        // 2=Vertical：@67 位串 1,0
        if ($mode === 2) {
            $this->encDecision(67, 0);
            return;
        }
        // 3=Plane：@67 位串 1,1
        $this->encDecision(67, 1);
    }

    /** cbp 亮度 4 位（逐位推进，镜像解码顺序） */
    private function encWriteCbpLuma(int $cbp): void
    {
        [$cbpA, $cbpB] = $this->cabNeighborCbp();
        // 邻居 cbp：低 4 位亮度、高 2 位色度（6.4.9.2）
        $c = 0;
        // c 保存本 MB 已写出的亮度位（供后续位的左邻上下文）
        // 块 0 ctx = 左 cbp bit1 + 上 cbp bit2
        $ctx = (($cbpA & 0x02) === 0 ? 1 : 0) + 2 * (($cbpB & 0x04) === 0 ? 1 : 0);
        $b = $cbp & 1;
        // 写 bit0
        $this->encDecision(73 + $ctx, $b);
        $c |= $b;
        // 块 1 ctx = 已写 bit0 + 上 cbp bit3
        $ctx = (($c & 0x01) === 0 ? 1 : 0) + 2 * (($cbpB & 0x08) === 0 ? 1 : 0);
        $b = ($cbp >> 1) & 1;
        // 写 bit1
        $this->encDecision(73 + $ctx, $b);
        $c |= $b << 1;
        // 块 2 ctx = 左 cbp bit3 + 已写 bit0
        $ctx = (($cbpA & 0x08) === 0 ? 1 : 0) + 2 * (($c & 0x01) === 0 ? 1 : 0);
        $b = ($cbp >> 2) & 1;
        // 写 bit2
        $this->encDecision(73 + $ctx, $b);
        $c |= $b << 2;
        // 块 3 ctx = 已写 bit2 + 已写 bit1
        $ctx = (($c & 0x04) === 0 ? 1 : 0) + 2 * (($c & 0x02) === 0 ? 1 : 0);
        // 写 bit3
        $this->encDecision(73 + $ctx, ($cbp >> 3) & 1);
    }

    /** cbp 色度（0/1/2，双段 ctx） */
    private function encWriteCbpChroma(int $cbpChroma): void
    {
        [$lCbp, $tCbp] = $this->cabNeighborCbp();
        // 取邻居 cbp 的色度位（bit4,5）
        $cbpA = ($lCbp >> 4) & 0x03;
        $cbpB = ($tCbp >> 4) & 0x03;
        $ctx = ($cbpA > 0 ? 1 : 0) + ($cbpB > 0 ? 2 : 0);
        // 首段 ctx：左/上色度 cbp 非零各 +1
        if ($cbpChroma === 0) {
            // 0：单 bin=0
            $this->encDecision(77 + $ctx, 0);
            return;
        }
        $this->encDecision(77 + $ctx, 1);
        $ctx = 4 + ($cbpA === 2 ? 1 : 0) + ($cbpB === 2 ? 2 : 0);
        // 次段 ctx：4 + (左==2) + 2*(上==2)
        $this->encDecision(77 + $ctx, $cbpChroma - 1);
    }

    /** mb_qp_delta：一元码（首 bin ctx 60+2，其余 60+3），更新 cabQp/cabLastQpDiff */
    private function encWriteQpDelta(int $delta): void
    {
        // 首 bin ctx：上一 delta 为 0 → @60，否则 @61
        $this->encDecision(60 + ($this->cabLastQpDiff !== 0 ? 1 : 0), $delta === 0 ? 0 : 1);
        if ($delta === 0) {
            // delta=0：写 0 后清零 lastQpDiff
            $this->cabLastQpDiff = 0;
            return;
        }
        // 映射：d>0 → val=2d-1（奇），d<0 → val=2|d|（偶）
        $val = $delta > 0 ? 2 * $delta - 1 : -2 * $delta;
        // val 映射成一元码（val 个 1 + 终止 0）
        // 一元续位：首个 @62，其余 @63；终止 0 与解码器同 ctx（val=1 时终止 @62）
        if ($val === 1) {
            // val=1：@62 直接写终止 0
            $this->encDecision(62, 0);
        } else {
            for ($i = 0; $i < $val - 1; $i++) {
                $this->encDecision($i === 0 ? 62 : 63, 1);
            }
            // 末尾 @63 写终止 0
            $this->encDecision(63, 0);
        }
        $this->cabLastQpDiff = $val;
        // 供下一 MB 的 ctxIdxInc
        $qp = $this->cabQp + $delta;
        // QPY 更新并按 52 回绕（7.4.5）
        if ($qp < 0) {
            // 下溢 +52
            $qp += 52;
        } elseif ($qp > 51) {
            // 上溢 -52
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
        // 按 list 选 MVD 存储
        [$lMb, $lBlk, $tMb, $tBlk] = $this->cabNbBlock($n);
        // 左/上 4x4 块所在 MB 与块号
        $a = $store[$lMb * 16 + $lBlk] ?? [0, 0];
        $b = $store[$tMb * 16 + $tBlk] ?? [0, 0];
        $amvd = min(70, abs($a[$comp] ?? 0)) + min(70, abs($b[$comp] ?? 0));

        // amvd：左/上同分量绝对值之和，各截断 70（9.3.3.2.1.7）
        $ctxBase = $comp === 0 ? 40 : 47;
        // 分量基址：x=40..46，y=47..53
        $ctx = $ctxBase + 2 - ($amvd < 3 ? 1 : 0) - ($amvd < 33 ? 1 : 0);
        // ctxIdxInc 按 amvd 区间：<3 / <33 / 其余
        $m = abs($mvd);
        if ($m === 0) {
            // abs=0：bin=0 结束
            $this->encDecision($ctx, 0);
            return;
        }
        $this->encDecision($ctx, 1);
        // 非零：bin=1 进入一元/escape
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
            // escape：mv 从 9 起，每级加 2^k 个 1
            $mv = 9;
            $k = 3;
            // 级数 k 从 3 起（UEG3，9.3.2.3）
            while ($m - $mv >= (1 << $k)) {
                $this->encBypass(1);
                $mv += 1 << $k;
                $k++;
            }
            // 前缀终止 0
            $this->encBypass(0);
            $rem = $m - $mv;
            // 余数 rem 的 k 位，MSB 先行
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
        // cat 决定 sig/last/level 三组 ctx 基址与状态机表
        $lastBase = self::$LAST_BASE[$cat];
        $levBase = self::$LEV_BASE[$cat];

        // 最后显著扫描位
        // lastSig=-1 表示无系数；逆序扫描定位最后显著位
        $lastSig = -1;
        // 从 maxCoeff-1 向前找首个非零扫描位
        for ($k = $maxCoeff - 1; $k >= 0; $k--) {
            if (isset($levels[$k]) && $levels[$k] !== 0) {
                $lastSig = $k;
                break;
            }
        }
        // 显著位早于末位：逐位写 sig/last；否则尾部隐含（无 bin）
        for ($k = 0; $k < $lastSig; $k++) {
            // 逐位写 sig；非零时再写 last=0（未到末位）
            $nz = isset($levels[$k]) && $levels[$k] !== 0;
            $this->encDecision($sigBase + $k, $nz ? 1 : 0);
            if ($nz) {
                $this->encDecision($lastBase + $k, 0);
            }
        }
        // 末位非最大扫描位时显式写 sig=1、last=1（k=maxCoeff-1 隐含）
        if ($lastSig < $maxCoeff - 1) {
            $this->encDecision($sigBase + $lastSig, 1);
            $this->encDecision($lastBase + $lastSig, 1);
        }

        // level 逆序（从最后显著位到首个）
        $ks = array_keys($levels);
        // 键为扫描位（升序），逆序遍历还原编码顺序
        $nodeCtx = 0;
        for ($i = count($ks) - 1; $i >= 0; $i--) {
            $k = $ks[$i];
            $lv = abs($levels[$k]);
            $ctx = $levBase + self::$LV1_CTX[$nodeCtx];
            // L1 ctx 由 nodeCtx 状态机映射
            if ($lv === 1) {
                // abs=1：L1 bin=0，转 TRANS_L1
                $this->encDecision($ctx, 0);
                $nodeCtx = self::$TRANS_L1[$nodeCtx];
            } else {
                $this->encDecision($ctx, 1);
                // abs>1：L1 bin=1，转 GT1 状态机
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
                    // 后缀 rem 的 e 位，MSB 先行
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
        // cat5 的 sig/last/level 基址
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
            // 四 cell 置 0 后返回（保持 nz cache 同步）
            $cell = self::$SCAN8[4 * $p8];
            $this->cabNzCache[$cell] = 0;
            $this->cabNzCache[$cell + 1] = 0;
            $this->cabNzCache[$cell + 8] = 0;
            $this->cabNzCache[$cell + 9] = 0;
            return;
        }
        for ($k = 0; $k < $lastSig; $k++) {
            // 非末位：sig（SIG8_FRAME 映射）+ 非零时 last=0
            $nz = isset($levels[$k]) && $levels[$k] !== 0;
            $this->encDecision($sigBase + self::$SIG8_FRAME[$k], $nz ? 1 : 0);
            if ($nz) {
                $this->encDecision($lastBase + self::$LAST8_FRAME[$k], 0);
            }
        }
        // 末位<63：显式写 sig=1、last=1
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
        // cache 单元（scan8 位置）
        $cell = self::$SCAN8[$n];
        // zz15：跳过 DC，扫描位 0..14 对应 zigzag[1..15]
        if ($zz15) {
            $scan = [];
            for ($k = 0; $k < 15; $k++) {
                $scan[$k] = self::ZIGZAG_SCAN_4X4[$k + 1];
            }
            // 15 个扫描位
            $maxCoeff = 15;
        } else {
            $scan = self::ZIGZAG_SCAN_4X4;
            $maxCoeff = 16;
        }
        $levels = [];
        // levels：扫描位 k => 非零系数
        for ($k = 0; $k < $maxCoeff; $k++) {
            $v = $block[$scan[$k]];
            // 0 系数不写入（由 sig bin 表示）
            if ($v !== 0) {
                $levels[$k] = $v;
            }
        }
        if (!count($levels)) {
            // 全零：CBF=0，清 nz cache
            $this->encDecision($this->cabCbfAcCtx($cat, $n), 0);
            $this->cabNzCache[$cell] = 0;
            return;
        }
        $this->encDecision($this->cabCbfAcCtx($cat, $n), 1);
        // CBF=1：写 sig/last/level
        $this->encWriteResidualCore($cat, $levels, $maxCoeff);
        // 非零计数写回 cache
        $this->cabNzCache[$cell] = count($levels);
    }

    /** I16 亮度 DC（16 项 raster 系数，内部按 zigzag 扫描编码——scan=zigzag_scan，镜像解码器）。CBF=1 时镜像解码器：不写 nzCache、置 picCbp bit8 */
    private function encWriteLumaDcI16(array $dcScan16): void
    {
        // 亮度 DC 的 CBF ctx：左/上 cbp 的 bit8
        [$lCbp, $tCbp] = $this->cabNeighborCbp();
        $nza = ($lCbp & 0x100) ? 1 : 0;
        $nzb = ($tCbp & 0x100) ? 1 : 0;
        $levels = [];
        for ($k = 0; $k < 16; $k++) {
            // 按 zigzag 取 16 个亮度 DC 系数
            $v = $dcScan16[self::ZIGZAG_SCAN_4X4[$k]];
            if ($v !== 0) {
                $levels[$k] = $v;
            }
        }
        if (!count($levels)) {
            // 全零：CBF=0，仅清 cache
            $this->encDecision(self::$CBF_BASE[0] + $nza + 2 * $nzb, 0);
            $this->cabNzCache[self::$SCAN8[0]] = 0;
            return;
        }
        $this->encDecision(self::$CBF_BASE[0] + $nza + 2 * $nzb, 1);
        // CBF=1：cat0 残差
        $this->encWriteResidualCore(0, $levels, 16);
        // 置 picCbp bit8，供后续 MB 的 DC CBF ctx
        $this->picCbp[$this->cabMbIdx] |= 0x100;
    }

    /** 色度 DC（4 系数，扫描序 0..3；CBF=1 置 picCbp 对应位） */
    private function encWriteChromaDc(int $c, array $dc4): void
    {
        // bit6=Cb DC、bit7=Cr DC
        $bit = 6 + $c;
        [$lCbp, $tCbp] = $this->cabNeighborCbp();
        $nza = ($lCbp & (1 << $bit)) ? 1 : 0;
        // 左/上对应色度 DC 非零标志
        $nzb = ($tCbp & (1 << $bit)) ? 1 : 0;
        $levels = [];
        // 4 系数按扫描序 0..3
        for ($k = 0; $k < 4; $k++) {
            if ($dc4[$k] !== 0) {
                $levels[$k] = $dc4[$k];
            }
        }
        if (!count($levels)) {
            // 全零：CBF=0，清 cache
            $this->encDecision(self::$CBF_BASE[3] + $nza + 2 * $nzb, 0);
            $this->cabNzCache[self::$SCAN8[16 + $c]] = 0;
            return;
        }
        $this->encDecision(self::$CBF_BASE[3] + $nza + 2 * $nzb, 1);
        // CBF=1：cat3 残差
        $this->encWriteResidualCore(3, $levels, 4);
        // 置 picCbp 的 DC 位
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
            // 8x8DCT：亮度按 p8 走 8x8 残差
            $zz8 = self::$ZZ8_SCAN;
            // scanJ：ZZ8 扫描位转 8x8 存储序
            $scanJ = [];
            for ($k = 0; $k < 64; $k++) {
                // TRANSPOSE8：>>3 与 &7 互换（行列转置）
                $scanJ[$k] = ($zz8[$k] >> 3) | (($zz8[$k] & 7) << 3);
            }
            // 四个 8x8 子块
            for ($p8 = 0; $p8 < 4; $p8++) {
                // cbp 亮度位门控，无逐块 CBF bin
                if (($cbpLuma & (1 << $p8)) !== 0) {
                    // 4x4 子块 raster 重装回 8x8 存储布局（与 prepare 端打包互逆）
                    $base = ($p8 >> 1) * 8 + ($p8 & 1) * 2;
                    // 8x8 重装缓冲（64 系数，0 初始化）
                    $stored = array_fill(0, 64, 0);
                    // 按 8x8 raster 重装 64 系数
                    for ($sub = 0; $sub < 4; $sub++) {
                        // sub：0..3 子块（subY*2+subX）
                        $subY = $sub >> 1;
                        $subX = $sub & 1;
                        // 4x4 子块 raster 序：blkIdx = base + subY*4 + subX
                        $blk = $lumaBlocks[$base + $subY * 4 + $subX];
                        for ($yy = 0; $yy < 4; $yy++) {
                            for ($xx = 0; $xx < 4; $xx++) {
                                // 子块内逐像素搬到 8x8 位置
                                $stored[($subY * 4 + $yy) * 8 + $subX * 4 + $xx] = $blk[$yy * 4 + $xx];
                            }
                        }
                    }
                    // 扫描位 k → 存储位 scanJ[k]（TRANSPOSE8(ZZ8)，镜像 cabResidualLuma8x8）
                    $levels = [];
                    for ($k = 0; $k < 64; $k++) {
                        // 按扫描位 k 取存储系数
                        $v = $stored[$scanJ[$k]];
                        if ($v !== 0) {
                            $levels[$k] = $v;
                        }
                    }
                    // 写 cat5 8x8 残差（无逐块 CBF）
                    $this->encWriteResidual8x8($p8, $levels);
                } else {
                    // cbp 位为 0：四 cell nz 置 0
                    $cell = self::$SCAN8[4 * $p8];
                    $this->cabNzCache[$cell] = 0;
                    $this->cabNzCache[$cell + 1] = 0;
                    $this->cabNzCache[$cell + 8] = 0;
                    $this->cabNzCache[$cell + 9] = 0;
                }
            }
        } else {
            // 非 8x8DCT：16 个 4x4 亮度块
            for ($n = 0; $n < 16; $n++) {
                // cbp 亮度位按 n>>2 取 8x8 组
                if ($cbpLuma & (1 << ($n >> 2))) {
                    // lumaBlocks 按 raster 块号构造，scan8 块号 n 须经 N2R 映射回 raster
                    $this->encWriteAcBlock(2, $n, $lumaBlocks[self::$N2R[$n]], false);
                } else {
                    // 未编码：清 nz cache
                    $this->cabNzCache[self::$SCAN8[$n]] = 0;
                }
            }
        }
        if ($cbpChroma > 0) {
            // 色度 DC：Cb、Cr 各一
            $this->encWriteChromaDc(0, $chromaDc[0]);
            $this->encWriteChromaDc(1, $chromaDc[1]);
        }
        if ($cbpChroma >= 2) {
            // 色度 AC（cbpChroma≥2）
            for ($b = 0; $b < 4; $b++) {
                // Cb 块号 16..19
                $this->encWriteAcBlock(4, 16 + $b, $chromaAc[0][$b], true);
            }
            for ($b = 0; $b < 4; $b++) {
                // Cr 块号 32..35
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
        // 置 I4 标志；8x8DCT 时附带 DCT8 标志
        $this->picMbType[$this->cabMbIdx] |= self::$CAB_FLG_I4 | ($dct8 ? self::$CAB_FLG_DCT8 : 0);
        // i4 模式按 n 序写回持久存储
        $this->cabStoreWriteBack(true, $modesN);
    }

    /** I16 宏块收尾 */
    private function encFinishI16(): void
    {
        // 置 I16 标志
        $this->picMbType[$this->cabMbIdx] |= self::$CAB_FLG_I16;
        // i4 模式写回默认值
        $this->cabStoreWriteBack(false, []);
    }

    /** inter 宏块收尾（picMbType 保持 0；$dct8 时 OR CAB_FLG_DCT8 供邻居 transform_size ctx） */
    private function encFinishInter(bool $dct8 = false): void
    {
        // 8x8DCT 时置 DCT8 标志，其余标志保持 0
        if ($dct8) {
            $this->picMbType[$this->cabMbIdx] |= self::$CAB_FLG_DCT8;
        }
        // inter 无 i4 模式，写回默认值
        $this->cabStoreWriteBack(false, []);
    }
}
