<?php

namespace Xiaosongshu\Flv2mp4\Codec\Decode;

/**
 * CABAC 算术解码（main/high profile）。
 *
 * 严格对照 FFmpeg：
 *  - libavcodec/cabac.c            ff_init_cabac_decoder
 *  - libavcodec/cabac_functions.h get_cabac_inline / refill / refill2 / bypass / terminate
 *  - libavcodec/h264_cabac.c      ctx 初始化、宏块层语法、残差
 *
 * 32 位无符号运算统一用 & 0xFFFFFFFF 仿真；PHP >> 为算术下移，掩码后数值恒 < 2^31，
 * 因此 >>31 即逻辑移位。
 */
trait CabacDecodingTrait
{
    use CabacTablesTrait;

    // MB 类型标志（与 FFmpeg mpegutils.h / h264dec.h 一致）
    private static int $CAB_FLG_I4    = 0x00000001;  // MB_TYPE_INTRA4x4
    private static int $CAB_FLG_I16   = 0x00000002;  // MB_TYPE_INTRA16x16
    private static int $CAB_FLG_PCM   = 0x00000004;  // MB_TYPE_INTRA_PCM
    private static int $CAB_FLG_DCT8  = 0x01000000;  // MB_TYPE_8x8DCT

    // 当前宏块解码期状态
    private int $cabMbX = 0;
    private int $cabMbY = 0;
    private int $cabMbIdx = 0;
    /** slice 序号（cabacStart 每次 +1；调试仪表用，区分跨 slice 的 cabMbIdx 重置） */
    private int $cabSliceIdx = -1;
    private int $cabQp = 0;
    private int $cabLastQpDiff = 0;
    /** @var int[] 本 MB 24 个 4x4 块非零计数（raster：0-15 亮度，16-19 Cb，20-23 Cr），MB 结束时由 scan8 cache 汇总，仅供 deblock/legacy */
    private array $cabNz = [];
    private int $cabCbp = 0;
    private int $cabBinSeq = 0;
    private int $cabBinsTotal = 0;
    private string $cabRawTag = '';
    /** 当前 MB 是否 intra（mb_type 读出后由 cabFixUnavailNz 设置；不可用邻居的 nz/cbp 默认值依赖它） */
    private bool $cabMbIsIntra = true;
    /** 本 MB 渐进 ref 覆盖（FFmpeg ref_cache 语义）：[list][4x4 块号] = ref；未设块按 -1 处理 */
    private array $cabMbRefOv = [[], []];
    /** 本 MB 渐进 direct 标记（FFmpeg direct_cache 语义）：4x4 块号 => 1，B 片 ref ctx 排除用 */
    private array $cabMbDirOv = [];

    // ---- FFmpeg scan8 cache 几何（h264_parse.h scan8 / h264_mvpred.h fill/write_back）----
    /** @var int[] 工作 cache，8 宽共 120 单元，下标 = scan8 位置 */
    private array $cabNzCache = [];
    /** @var int[] 同几何的 intra4x4 预测模式 cache（-1=不可用，2=DC） */
    private array $cabI4Cache = [];
    /** @var int[] 每 MB 持久 nz 存储（48 布局，见 write_back_non_zero_count） */
    private array $cabNzStore = [];
    /** @var int[] 每 MB 持久 i4 模式（16，n 序），仅 I4 MB 有效 */
    private array $cabI4Store = [];

    // I 片不可用邻居默认值（FFmpeg fill_decode_caches）：nz=64，cbp=0x7CF
    private static int $CAB_NZ_UNAVAIL = 64;
    private static int $CAB_CBP_INTRA  = 0x7CF;

    private static array $SIG8_FRAME = [
        0, 1, 2, 3, 4, 5, 5, 4, 4, 3, 3, 4, 4, 4, 5, 5,
        4, 4, 4, 4, 3, 3, 6, 7, 7, 7, 8, 9, 10, 9, 8, 7,
        7, 6, 11, 12, 13, 11, 6, 7, 8, 9, 14, 10, 9, 8, 6, 11,
        12, 13, 11, 6, 9, 14, 10, 9, 11, 12, 13, 11, 14, 10, 12,
    ];
    private static array $LAST8_FRAME = [
        0, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1,
        2, 2, 2, 2, 2, 2, 2, 2, 2, 2, 2, 2, 2, 2, 2, 2,
        3, 3, 3, 3, 3, 3, 3, 3, 4, 4, 4, 4, 4, 4, 4, 4,
        5, 5, 5, 5, 6, 6, 6, 6, 7, 7, 7, 7, 8, 8, 8,
    ];
    // node ctx：level=1 映射 / level>1 映射 / 两种迁移
    private static array $LV1_CTX  = [1, 2, 3, 4, 0, 0, 0, 0];
    private static array $GT1_CTX  = [5, 5, 5, 5, 6, 7, 8, 9];
    private static array $TRANS_L1 = [1, 2, 3, 3, 4, 5, 6, 7];
    private static array $TRANS_GT1 = [4, 4, 4, 4, 5, 6, 7, 7];
    // 帧模式 cat -> 基址
    private static array $SIG_BASE  = [0 => 105, 1 => 120, 2 => 134, 3 => 149, 4 => 152, 5 => 402, 9 => 660];
    private static array $LAST_BASE = [0 => 166, 1 => 181, 2 => 195, 3 => 210, 4 => 213, 5 => 417, 9 => 690];
    private static array $LEV_BASE  = [0 => 227, 1 => 237, 2 => 247, 3 => 257, 4 => 266, 5 => 426, 9 => 708];
    private static array $CBF_BASE  = [0 => 85, 1 => 89, 2 => 93, 3 => 97, 4 => 101, 9 => 1016];
    // 8x8 ff_zigzag_direct
    private static array $ZZ8 = [
        0, 1, 8, 16, 9, 2, 3, 10, 17, 24, 32, 25, 18, 11, 4, 5,
        12, 19, 26, 33, 40, 48, 41, 34, 27, 20, 13, 6, 7, 14, 21, 28,
        35, 42, 49, 56, 57, 50, 43, 36, 29, 22, 15, 23, 30, 37, 44, 51,
        58, 59, 52, 45, 38, 31, 39, 46, 53, 60, 61, 54, 47, 55, 62, 63,
    ];

    // scan8[n]：n 是 8x8 分组序块号（非 raster）。420 用到前 36 项（h264_parse.h L40-L49）
    private static array $SCAN8 = [
        12, 13, 20, 21,  14, 15, 22, 23,  28, 29, 36, 37,  30, 31, 38, 39, // 0..15 luma
        52, 53, 60, 61,  54, 55, 62, 63,                                    // 16..23 Cb
        68, 69, 76, 77,  70, 71, 78, 79,                                    // 24..31
        92, 93, 100, 101,                                                   // 32..35 Cr
    ];
    /** 亮度 n(8x8分组序) -> raster 4x4 块号 r=by*4+bx */
    private static array $N2R = [0, 1, 4, 5, 2, 3, 6, 7, 8, 9, 12, 13, 10, 11, 14, 15];
    /** raster -> n */
    private static array $R2N = [0, 1, 4, 5, 2, 3, 6, 7, 8, 9, 12, 13, 10, 11, 14, 15];

    // ---- 持久 nz 存储（48 布局）与当前 MB / 邻居 cache 单元的对应（write_back/fill_decode_caches）----
    /** 当前 MB 矩形单元（luma 16 + Cb 8 + Cr 8），MB 开始清零 */
    private static array $CELL_RESET = [
        12, 13, 14, 15, 20, 21, 22, 23, 28, 29, 30, 31, 36, 37, 38, 39,
        52, 53, 54, 55, 60, 61, 62, 63,
        92, 93, 94, 95, 100, 101, 102, 103,
    ];
    /** nz 存储 s0..15 对应的 luma cache 单元 */
    private static array $CELL_LUMA_ST = [12, 13, 14, 15, 20, 21, 22, 23, 28, 29, 30, 31, 36, 37, 38, 39];
    private static array $CELL_CB_ST   = [52, 53, 54, 55, 60, 61, 62, 63];
    private static array $CELL_CR_ST   = [92, 93, 94, 95, 100, 101, 102, 103];

    // ============================================================
    // 引擎
    // ============================================================

    /**
     * 在 slice header 结束（已字节对齐）后启动 CABAC。
     * 始终采用 FFmpeg 的“奇对齐”规范初始化：消费 b0/b1/b2 三字节。
     */
    private function cabacStart(string $rbsp, int $byteOffset): void
    {
        $this->cabSliceIdx++;
        $this->cabacData = $rbsp;
        $this->cabacEnd = strlen($rbsp);
        $this->cabacInitAt($byteOffset);
    }

    private function cabacInitAt(int $byteOffset): void
    {
        $b0 = $this->cabacAbsByte($byteOffset);
        $b1 = $this->cabacAbsByte($byteOffset + 1);
        $b2 = $this->cabacAbsByte($byteOffset + 2);
        // FFmpeg ff_init_cabac_decoder：读完 b0/b1 后按指针奇偶选分支。
        // gb.buffer 为偶对齐，故由 slice-data 字节偏移奇偶决定。
        $even = ($byteOffset & 1) === 0;
        if ($even) {
            $this->cabacPtr = $byteOffset + 2;
            $this->cabacLow = (($b0 << 18) | ($b1 << 10) | (1 << 9)) & 0xFFFFFFFF;
        } else {
            $this->cabacPtr = $byteOffset + 3;
            $this->cabacLow = (($b0 << 18) | ($b1 << 10) | ($b2 << 2) | 2) & 0xFFFFFFFF;
        }
        $this->cabacRange = 0x1FE;
    }

    private function cabacAbsByte(int $abs): int
    {
        if ($abs < 0 || $abs >= $this->cabacEnd) {
            return 0; // AV_INPUT_BUFFER_PADDING 零
        }
        return ord($this->cabacData[$abs]);
    }

    private function cabacRefill(): void
    {
        $p = $this->cabacPtr;
        $this->cabacLow =
            (($this->cabacLow + ($this->cabacAbsByte($p) << 9) + ($this->cabacAbsByte($p + 1) << 1)) - 0xFFFF)
            & 0xFFFFFFFF;
        $this->cabacPtr = $p + 2;
    }

    private function cabacRefill2(): void
    {
        $low = $this->cabacLow;
        $tmp = ($low ^ ($low - 1)) & 0xFFFFFFFF;
        $v = $tmp >> 15;
        if ($v < 512) {
            $i = 7 - self::$cabacNormShift[$v];
        } else {
            // 理论不可达（FFmpeg 512 项表）；与 HAVE_FAST_CLZ 分支 i=ctz(low)-16 等价
            $u = $low;
            $z = 0;
            while (($u & 1) === 0 && $z < 32) {
                $u >>= 1;
                $z++;
            }
            $i = $z - 16;
        }
        $p = $this->cabacPtr;
        $x = -0xFFFF + ($this->cabacAbsByte($p) << 9) + ($this->cabacAbsByte($p + 1) << 1);
        $this->cabacLow = ($low + ($x << $i)) & 0xFFFFFFFF;
        $this->cabacPtr = $p + 2;
    }

    private function cabGet(int $ctxIdx): int
    {
        $this->cabBinsTotal++;
        $s = $this->cabacStates[$ctxIdx];
        $range = $this->cabacRange;
        $low = $this->cabacLow;

        $rangeLps = self::$cabacLpsRange[2 * ($range & 0xC0) + $s];
        $range -= $rangeLps;
        // FFmpeg int32 回绕语义：lps_mask = ((range<<17) - low) >> 31，等价于无符号 (range<<17) < low 时取 -1
        $d = (($range << 17) - $low) & 0xFFFFFFFF;
        $mask = $d >= 0x80000000 ? -1 : 0;

        $low -= ($range << 17) & $mask;
        $range += ($rangeLps - $range) & $mask;

        // FFmpeg: s ^= lps_mask → LPS 时 s 变为 -(s+1)，mlps 索引 128+s 即 127-s（表前 128 项镜像段）
        $s ^= $mask;
        $this->cabacStates[$ctxIdx] = self::$cabacMlps[128 + $s];
        $bit = $s & 1;

        $shift = self::$cabacNormShift[$range];
        $range <<= $shift;
        $low = ($low << $shift) & 0xFFFFFFFF;

        if (($low & 0xFFFF) === 0) {
            $this->cabacLow = $low;
            $this->cabacRange = $range;
            $this->cabacRefill2();
        } else {
            $this->cabacLow = $low;
            $this->cabacRange = $range;
        }
        return $bit;
    }

    private function cabBypass(): int
    {
        $this->cabBinsTotal++;
        $low = ($this->cabacLow << 1) & 0xFFFFFFFF;
        $this->cabacLow = $low;
        if (($low & 0xFFFF) === 0) {
            $this->cabacRefill();
            $low = $this->cabacLow;
        }
        $r = $this->cabacRange << 17;
        // FFmpeg：if((int32)low < (int32)range) return 0; 必须按有符号比较
        $d = ($low - $r) & 0xFFFFFFFF;
        if ($d >= 0x80000000) {
            // 有符号 low < r：bit=0，low 保持
            return 0;
        }
        $this->cabacLow = $d;
        return 1;
    }

    private function cabBypassSign(int $val): int
    {
        $this->cabBinsTotal++;
        $low = ($this->cabacLow << 1) & 0xFFFFFFFF;
        $this->cabacLow = $low;
        if (($low & 0xFFFF) === 0) {
            $this->cabacRefill();
            $low = $this->cabacLow;
        }
        $r = $this->cabacRange << 17;
        // FFmpeg：low-=range; mask=(int32)low>>31; 必须按有符号 32 位
        $d = ($low - $r) & 0xFFFFFFFF;
        $mask = $d >= 0x80000000 ? -1 : 0;
        $this->cabacLow = ($d + ($r & $mask)) & 0xFFFFFFFF;
        return ($val ^ $mask) - $mask;
    }

    /** @return int 0=未结束，非零=已到片尾（调用方一般只判真假） */
    private function cabTerminate(): int
    {
        $this->cabBinsTotal++;
        $range = $this->cabacRange - 2;
        // FFmpeg：if((int32)low < (int32)(range<<17)) —— 有符号比较
        $d = ($this->cabacLow - ($range << 17)) & 0xFFFFFFFF;
        if ($d >= 0x80000000) {
            // renorm once
            $shift = ($range < 0x100) ? 1 : 0;
            $range <<= $shift;
            $this->cabacLow = ($this->cabacLow << $shift) & 0xFFFFFFFF;
            $this->cabacRange = $range;
            if (($this->cabacLow & 0xFFFF) === 0) {
                $this->cabacRefill();
            }
            return 0;
        }
        return 1;
    }

    // ============================================================
    // 上下文初始化（h264_cabac.c ff_h264_init_cabac_states）
    // ============================================================

    /**
     * @param int $sliceTypeNos 0=P 1=B 2=I
     */
    private function cabacInitStates(int $qp, int $sliceTypeNos): void
    {
        $qp = max(0, min(51, $qp));
        $states = [];
        if ($sliceTypeNos === 2) {
            $init = self::$cabacCtxInitI;
        } else {
            $init = $this->cabacInitIdc === 0
                ? self::$cabacCtxInitPB0
                : ($this->cabacInitIdc === 1 ? self::$cabacCtxInitPB1 : self::$cabacCtxInitPB2);
        }
        for ($i = 0; $i < 1024; $i++) {
            $m = $init[$i][0];
            $n = $init[$i][1];
            // 严格对照 FFmpeg：pre ^= pre>>31（负数 -> -pre-1；正数保持，不 clamp）
            $pre = 2 * ((($m * $qp) >> 4) + $n) - 127;
            if ($pre < 0) {
                $pre = -$pre - 1;
            }
            if ($pre > 124) {
                $pre = 124 + ($pre & 1);
            }
            $states[$i] = $pre; // state 含 MPS（最低位）
        }
        $this->cabacStates = $states;
    }

    /** 片开始 */
    private function cabacSliceBegin(string $rbsp, int $headerEndByte, int $sliceQp, int $sliceTypeNos): void
    {
        $this->cabacStart($rbsp, $headerEndByte);
        $this->cabacInitStates($sliceQp, $sliceTypeNos);
        $this->cabQp = max(0, min(51, $sliceQp));
        $this->cabLastQpDiff = 0;
        $this->cabBinSeq = 0;
        $this->cabBinsTotal = 0;

        // 每片重置 scan8 持久存储（write_back_non_zero_count / write_back_intra_pred_mode）
        $mbCount = $this->picWidthInMbs * $this->picHeightInMbs;
        $this->cabNzStore = array_fill(0, $mbCount * 48, 0);
        $this->cabI4Store = array_fill(0, $mbCount * 16, 2);
        $this->cabNzCache = array_fill(0, 120, 0);
        $this->cabI4Cache = array_fill(0, 120, -1);
    }

    // ============================================================
    // 邻居辅助（全帧 pic* 数组）
    // ============================================================

    private function cabLeftMbIdx(): int
    {
        return $this->cabMbX > 0 ? $this->cabMbIdx - 1 : -1;
    }

    private function cabTopMbIdx(): int
    {
        return $this->cabMbY > 0 ? $this->cabMbIdx - $this->picWidthInMbs : -1;
    }

    private function cabMbBegin(int $mbX, int $mbY): void
    {
        $this->cabMbX = $mbX;
        $this->cabMbY = $mbY;
        $this->cabMbIdx = $mbY * $this->picWidthInMbs + $mbX;
        // 本 MB 渐进 ref/direct 覆盖（FFmpeg ref_cache/direct_cache 在 MB 解码内渐进填写）
        $this->cabMbRefOv = [[], []];
        $this->cabMbDirOv = [];
        $this->cabNz = array_fill(0, 24, 0);
        $this->cabCbp = 0;
        $this->cabFillCaches();
    }

    /** 本 MB 覆盖：写入一块区域的渐进 ref（FFmpeg ref 循环 fill_rectangle） */
    private function cabOvRefBox(int $list, int $bx, int $by, int $bw, int $bh, int $ref): void
    {
        for ($y = $by; $y < $by + $bh; $y++) {
            $row = $y * 4;
            for ($x = $bx; $x < $bx + $bw; $x++) {
                $this->cabMbRefOv[$list][$row + $x] = $ref;
            }
        }
    }

    /** 4x4 块号 -> 8x8 象限号（0=TL,1=TR,2=BL,3=BR） */
    private static function blkQuad(int $b): int
    {
        return (($b & 3) >> 1) | (($b >> 2) & 2);
    }

    /**
     * 按 FFmpeg fill_decode_caches（h264_mvpred.h）从持久存储填充本 MB 的 nz / i4 mode
     * 工作 cache（当前矩形 + 上/左邻居边）。I 片：不可用邻居 nz=64、mode=-1、cbp=0x7CF。
     */
    private function cabFillCaches(): void
    {
        $nz = &$this->cabNzCache;
        $i4 = &$this->cabI4Cache;

        // 当前矩形复位
        foreach (self::$CELL_RESET as $cell) {
            $nz[$cell] = 0;
        }
        for ($n = 0; $n < 16; $n++) {
            $i4[self::$SCAN8[$n]] = -1;
        }

        $l = $this->cabLeftMbIdx();
        $t = $this->cabTopMbIdx();
        $nzDef = self::$CAB_NZ_UNAVAIL;

        // ---- nz 上边 ----
        if ($t >= 0) {
            $s = $t * 48;
            $nz[4] = $this->cabNzStore[$s + 12]; $nz[5] = $this->cabNzStore[$s + 13];
            $nz[6] = $this->cabNzStore[$s + 14]; $nz[7] = $this->cabNzStore[$s + 15];
            $nz[44] = $this->cabNzStore[$s + 20]; $nz[45] = $this->cabNzStore[$s + 21];
            $nz[46] = $this->cabNzStore[$s + 22]; $nz[47] = $this->cabNzStore[$s + 23];
            $nz[84] = $this->cabNzStore[$s + 36]; $nz[85] = $this->cabNzStore[$s + 37];
            $nz[86] = $this->cabNzStore[$s + 38]; $nz[87] = $this->cabNzStore[$s + 39];
        } else {
            // 不可用 top：填哨兵 -1，mb_type 读出后经 cabFixUnavailNz 转为
            // FFmpeg fill_decode_caches 默认值（h264_mvpred.h L685/L716：
            // CABAC && !IS_INTRA(mb_type) ? 0 : 64）
            foreach ([4, 5, 6, 7, 44, 45, 46, 47, 84, 85, 86, 87] as $c) {
                $nz[$c] = -1;
            }
        }
        // ---- nz 左边 ----
        if ($l >= 0) {
            $s = $l * 48;
            $nz[11] = $this->cabNzStore[$s + 3];  $nz[19] = $this->cabNzStore[$s + 7];
            $nz[27] = $this->cabNzStore[$s + 11]; $nz[35] = $this->cabNzStore[$s + 15];
            $nz[51] = $this->cabNzStore[$s + 17]; $nz[59] = $this->cabNzStore[$s + 21];
            $nz[91] = $this->cabNzStore[$s + 33]; $nz[99] = $this->cabNzStore[$s + 37];
        } else {
            // 不可用 left：同上哨兵 -1
            foreach ([11, 19, 27, 35, 51, 59, 91, 99] as $c) {
                $nz[$c] = -1;
            }
        }

        // ---- i4 mode 上边（单元 4..7 ← 上 MB 底边 n10,n11,n14,n15）----
        if ($t >= 0 && ($this->picMbType[$t] & self::$CAB_FLG_I4)) {
            $b = $t * 16;
            $i4[4] = $this->cabI4Store[$b + 10]; $i4[5] = $this->cabI4Store[$b + 11];
            $i4[6] = $this->cabI4Store[$b + 14]; $i4[7] = $this->cabI4Store[$b + 15];
        } else {
            $m = $t >= 0 ? 2 : -1;
            $i4[4] = $i4[5] = $i4[6] = $i4[7] = $m;
        }
        // ---- i4 mode 左边（11,19,27,35 ← 左 MB 右缘 n5,n7,n13,n15）----
        if ($l >= 0 && ($this->picMbType[$l] & self::$CAB_FLG_I4)) {
            $b = $l * 16;
            $i4[11] = $this->cabI4Store[$b + 5];  $i4[19] = $this->cabI4Store[$b + 7];
            $i4[27] = $this->cabI4Store[$b + 13]; $i4[35] = $this->cabI4Store[$b + 15];
        } else {
            $m = $l >= 0 ? 2 : -1;
            $i4[11] = $i4[19] = $i4[27] = $i4[35] = $m;
        }
    }

    /**
     * mb_type 读出后调用：把不可用邻居的 nz 哨兵(-1)按当前 MB 类型转为 FFmpeg 默认值。
     * FFmpeg fill_decode_caches（h264_mvpred.h L685/L716）：
     *   CABAC && !IS_INTRA(mb_type) ? 0 : 64
     * cabMbBegin 在 mb_type 读取前执行（fill 时尚不知 intra/inter），故哨兵延迟到此转换。
     * skip/PCM 无 CBF 读取，未调用亦无影响（哨兵在 ctx 计算中表现为 0）。
     */
    private function cabFixUnavailNz(bool $isIntra): void
    {
        $this->cabMbIsIntra = $isIntra;
        $def = $isIntra ? 64 : 0;
        $nz = &$this->cabNzCache;
        foreach ([4, 5, 6, 7, 44, 45, 46, 47, 84, 85, 86, 87, 11, 19, 27, 35, 51, 59, 91, 99] as $c) {
            if ($nz[$c] === -1) {
                $nz[$c] = $def;
            }
        }
    }

    /**
     * 左/上 MB 的 cbp 上下文（ffmpeg fill_decode_caches，h264_mvpred.h:720-733）。
     * 帧 MBAFF（非场）时 left_block={0,1,2,3,...}、leftL==leftBot：
     *   top_cbp  = top 存在 ? cbp_table[top]（原始，不掩码） : (intra?0x7CF:0x00F)
     *   left_cbp = left 存在 ? (c&0x7F0) | (c&0x02) | (c&0x08) : (intra?0x7CF:0x00F)
     *   即保留原 bit1 与 bit3（(c>>(0&~1))&2 与 ((c>>(2&~1))&2)<<2），丢弃 bit0/bit2。
     * 不可用默认值按当前 MB 类型（h264_mvpred.h L725/L732：IS_INTRA ? 0x7CF : 0x00F）：
     * cbp_luma/chroma 的 ctx 两者等价，但 luma-DC(bit8)/chroma-DC(bit6,7) 的 CBF ctx 不等价。
     *
     * @return array{0:int,1:int} [leftCbp,topCbp]
     */
    private function cabNeighborCbp(): array
    {
        $l = $this->cabLeftMbIdx();
        $t = $this->cabTopMbIdx();
        $unavail = $this->cabMbIsIntra ? self::$CAB_CBP_INTRA : 0x00F;
        if ($t >= 0) {
            $topCbp = $this->picCbp[$t];
        } else {
            $topCbp = $unavail;
        }
        if ($l >= 0) {
            $c = $this->picCbp[$l];
            $leftCbp = ($c & 0x7F0) | ($c & 0x02) | ($c & 0x08);
        } else {
            $leftCbp = $unavail;
        }
        return [$leftCbp, $topCbp];
    }

    /**
     * 非 DC 块 CBF 上下文（get_cabac_cbf_ctx），n 为 scan8 块号：
     * 亮度 AC cat2 n=0..15；I16-AC cat1 n=0..15；色度-AC cat4 n=16+16c+i。
     */
    private function cabCbfAcCtx(int $cat, int $n): int
    {
        $cell = self::$SCAN8[$n];
        $nza = $this->cabNzCache[$cell - 1];
        $nzb = $this->cabNzCache[$cell - 8];
        return self::$CBF_BASE[$cat] + ($nza > 0 ? 1 : 0) + 2 * ($nzb > 0 ? 1 : 0);
    }

    /** intra4x4 预测模式（pred_intra_mode：min(左,上)，不可用(<0)->DC=2），n 为 scan8 块号 */
    private function cabPredModeN(int $n): int
    {
        $cell = self::$SCAN8[$n];
        $a = $this->cabI4Cache[$cell - 1];
        $b = $this->cabI4Cache[$cell - 8];
        $m = min($a, $b);
        return $m < 0 ? 2 : $m;
    }

    /** MB 结束：把当前矩形写回持久 nz(48)/i4(n序) 存储（write_back_non_zero_count / write_back_intra_pred_mode） */
    private function cabStoreWriteBack(bool $isI4, array $modesN): void
    {
        $mbIdx = $this->cabMbIdx;
        $nz = &$this->cabNzCache;
        $s = $mbIdx * 48;
        foreach (self::$CELL_LUMA_ST as $k => $cell) {
            $this->cabNzStore[$s + $k] = $nz[$cell];
        }
        foreach (self::$CELL_CB_ST as $k => $cell) {
            $this->cabNzStore[$s + 16 + $k] = $nz[$cell];
        }
        foreach (self::$CELL_CR_ST as $k => $cell) {
            $this->cabNzStore[$s + 32 + $k] = $nz[$cell];
        }
        if ($isI4) {
            $b = $mbIdx * 16;
            for ($n = 0; $n < 16; $n++) {
                $this->cabI4Store[$b + $n] = $modesN[$n];
            }
        }
    }

    // ============================================================
    // 宏块层语法原语
    // ============================================================

    /**
     * intra mb_type（decode_cabac_intra_mb_type）。
     * 返回 ['k'=>'i4'|'i16'|'pcm', 'mode'=>int, 'luma15'=>bool, 'chromaCbp'=>int]
     */
    private function cabReadIntraMbType(int $ctxBase, bool $intraSlice): array
    {
        if ($intraSlice) {
            $ctx = 0;
            $l = $this->cabLeftMbIdx();
            $t = $this->cabTopMbIdx();
            if ($l >= 0 && ($this->picMbType[$l] & (self::$CAB_FLG_I16 | self::$CAB_FLG_PCM))) {
                $ctx++;
            }
            if ($t >= 0 && ($this->picMbType[$t] & (self::$CAB_FLG_I16 | self::$CAB_FLG_PCM))) {
                $ctx++;
            }
            if ($this->cabGet($ctxBase + $ctx) === 0) {
                return ['k' => 'i4'];
            }
            $state = $ctxBase + 2;
        } else {
            if ($this->cabGet($ctxBase) === 0) {
                return ['k' => 'i4'];
            }
            // FFmpeg decode_cabac_intra_mb_type：state+=2 仅在 intra_slice 分支内，
            // 非 intra 片保持 state=ctx_base（后续读 state[1..3+2*is] 即 ctx_base+1..3）
            $state = $ctxBase;
        }

        if ($this->cabTerminate()) {
            return ['k' => 'pcm'];
        }

        $is = $intraSlice ? 1 : 0;
        $luma15 = (bool)$this->cabGet($state + 1);
        $chromaCbp = 0;
        if ($this->cabGet($state + 2)) {
            $chromaCbp = 1 + $this->cabGet($state + 2 + $is);
        }
        $p1 = $this->cabGet($state + 3 + $is);
        $p0 = $this->cabGet($state + 3 + 2 * $is);
        $mode = 2 * $p1 + $p0;

        return ['k' => 'i16', 'mode' => $mode, 'luma15' => $luma15, 'chromaCbp' => $chromaCbp];
    }

    private function cabReadIntra4Mode(int $pred): int
    {
        if ($this->cabGet(68)) {
            return $pred;
        }
        $mode = $this->cabGet(69) + 2 * $this->cabGet(69) + 4 * $this->cabGet(69);
        return $mode + ($mode >= $pred ? 1 : 0);
    }

    private function cabReadChromaPredMode(): int
    {
        $l = $this->cabLeftMbIdx();
        $t = $this->cabTopMbIdx();
        $ctx = 0;
        if ($l >= 0 && $this->picChromaPred[$l] !== 0) {
            $ctx++;
        }
        // FFmpeg decode_cabac_mb_chroma_pre_mode：左/上邻居各 ctx++（非 +2）
        if ($t >= 0 && $this->picChromaPred[$t] !== 0) {
            $ctx++;
        }
        if ($this->cabGet(64 + $ctx) === 0) {
            return 0;
        }
        if ($this->cabGet(67) === 0) {
            return 1;
        }
        if ($this->cabGet(67) === 0) {
            return 2;
        }
        return 3;
    }

    private function cabReadCbpLuma(): int
    {
        [$cbpA, $cbpB] = $this->cabNeighborCbp();

        $cbp = 0;
        $ctx = (($cbpA & 0x02) === 0 ? 1 : 0) + 2 * (($cbpB & 0x04) === 0 ? 1 : 0);
        $cbp += $this->cabGet(73 + $ctx);
        $ctx = (($cbp & 0x01) === 0 ? 1 : 0) + 2 * (($cbpB & 0x08) === 0 ? 1 : 0);
        $cbp += $this->cabGet(73 + $ctx) << 1;
        $ctx = (($cbpA & 0x08) === 0 ? 1 : 0) + 2 * (($cbp & 0x01) === 0 ? 1 : 0);
        $cbp += $this->cabGet(73 + $ctx) << 2;
        $ctx = (($cbp & 0x04) === 0 ? 1 : 0) + 2 * (($cbp & 0x02) === 0 ? 1 : 0);
        $cbp += $this->cabGet(73 + $ctx) << 3;
        return $cbp;
    }

    private function cabReadCbpChroma(): int
    {
        [$lCbp, $tCbp] = $this->cabNeighborCbp();
        $cbpA = ($lCbp >> 4) & 0x03;
        $cbpB = ($tCbp >> 4) & 0x03;

        $ctx = ($cbpA > 0 ? 1 : 0) + ($cbpB > 0 ? 2 : 0);
        if ($this->cabGet(77 + $ctx) === 0) {
            return 0;
        }
        $ctx = 4 + ($cbpA === 2 ? 1 : 0) + ($cbpB === 2 ? 2 : 0);
        return 1 + $this->cabGet(77 + $ctx);
    }

    /** 读取 mb_qp_delta，更新 cabQp / cabLastQpDiff（h264_cabac.c decode_cabac_mb_dqp） */
    private function cabReadQpDelta(): void
    {
        if ($this->cabGet(60 + ($this->cabLastQpDiff !== 0 ? 1 : 0)) === 0) {
            $this->cabLastQpDiff = 0;
            return;
        }
        $val = 1;
        $ctx = 2;
        while ($this->cabGet(60 + $ctx)) {
            $ctx = 3;
            $val++;
            if ($val > 102) {
                break;
            }
        }
        if (($val & 1) !== 0) {
            $val = ($val + 1) >> 1;
        } else {
            $val = -(($val + 1) >> 1);
        }
        $this->cabLastQpDiff = $val;
        $qp = $this->cabQp + $val;
        if ($qp < 0) {
            $qp += 52;
        } elseif ($qp > 51) {
            $qp -= 52;
        }
        $this->cabQp = $qp;
    }

    private function cabChromaQp(): int
    {
        $idx = max(0, min(51, $this->cabQp + $this->chromaQpIndexOffset));
        return self::CHROMA_QP_TABLE[$idx];
    }

    // ============================================================
    // 残差（decode_cabac_residual_internal）
    // ============================================================

    /**
     * 残差核心。
     *
     * @param int      $cat      cat（决定 sig/last/level 基址）
     * @param array    $scanJ    长度 maxCoeff，扫描位 k -> 输出存储位 j
     * @param int      $maxCoeff 16/15/4/64
     * @param array|null $qmul   非 DC：按存储位 j 的反量化乘数；DC 传 null（输出原始 level）
     * @param bool     $is8x8
     * @return array{0:array,1:int} [j=>level, coeffCount]
     */
    private function cabResidualCore(
        int $cat,
        array $scanJ,
        int $maxCoeff,
        ?array $qmul,
        bool $is8x8
    ): array {
        $sigBase = self::$SIG_BASE[$cat];
        $lastBase = self::$LAST_BASE[$cat];
        $levBase = self::$LEV_BASE[$cat];

        $index = [];
        $coeffCount = 0;
        if ($is8x8) {
            $terminated = false;
            for ($last = 0; $last < 63; $last++) {
                if ($this->cabGet($sigBase + self::$SIG8_FRAME[$last])) {
                    $index[$coeffCount++] = $last;
                    if ($this->cabGet($lastBase + self::$LAST8_FRAME[$last])) {
                        $last = 64;
                        $terminated = true;
                        break;
                    }
                }
            }
            // FFmpeg: 循环正常退出（last==63）时最后一位必显著；break（last=64）不补
            if (!$terminated) {
                $index[$coeffCount++] = 63;
            }
        } else {
            $terminated = false;
            for ($last = 0; $last < $maxCoeff - 1; $last++) {
                if ($this->cabGet($sigBase + $last)) {
                    $index[$coeffCount++] = $last;
                    if ($this->cabGet($lastBase + $last)) {
                        $last = $maxCoeff;
                        $terminated = true;
                        break;
                    }
                }
            }
            // FFmpeg 宏语义：break（last 置 max）不补；正常退出（last==max-1）补最后一位
            if (!$terminated) {
                $index[$coeffCount++] = $maxCoeff - 1;
            }
        }

        $out = [];
        $nodeCtx = 0;
        while ($coeffCount > 0) {
            $k = $index[--$coeffCount];
            $j = $scanJ[$k];

            $ctx = $levBase + self::$LV1_CTX[$nodeCtx];
            if ($this->cabGet($ctx) === 0) {
                $nodeCtx = self::$TRANS_L1[$nodeCtx];
                $sv = $this->cabBypassSign(-1);
                if ($qmul === null) {
                    $out[$j] = $sv;
                } else {
                    $out[$j] = (($sv * $qmul[$j]) + 32) >> 6;
                }
            } else {
                $ctx = $levBase + self::$GT1_CTX[$nodeCtx];
                $nodeCtx = self::$TRANS_GT1[$nodeCtx];
                $coeffAbs = 2;
                while ($coeffAbs < 15 && $this->cabGet($ctx)) {
                    $coeffAbs++;
                }
                if ($coeffAbs >= 15) {
                    $e = 0;
                    while ($this->cabBypass() && $e < 23) {
                        $e++;
                    }
                    $coeffAbs = 1;
                    while ($e-- > 0) {
                        $coeffAbs += $coeffAbs + $this->cabBypass();
                    }
                    $coeffAbs += 14;
                }
                $sv = $this->cabBypassSign(-$coeffAbs);
                if ($qmul === null) {
                    $out[$j] = $sv;
                } else {
                    $out[$j] = (($sv * $qmul[$j]) + 32) >> 6;
                }
            }
        }
        return [$out, count($out)];
    }

    /**
     * 4x4 非 DC 残差块（亮度 AC cat2 / I16-AC cat1 / 色度-AC cat4）。
     * $n 为 scan8 块号（亮度 0..15；色度 16+16c+i），CBF 邻居与 nz 写回均走 nzCache[scan8[n]]。
     * 输出 16 位 raster 系数。
     * @return array{0:int[],1:int} [16 raster 系数, nz]
     */
    private function cabDecodeAcBlock(int $cat, int $n, int $list, int $qp, bool $zz15): array
    {
        $cell = self::$SCAN8[$n];
        if ($this->cabGet($this->cabCbfAcCtx($cat, $n)) === 0) {
            $this->cabNzCache[$cell] = 0;
            return [array_fill(0, 16, 0), 0];
        }
        $qmul = $this->dequant4Table[$list][$qp];
        if ($zz15) {
            $scanJ = [];
            for ($k = 0; $k < 15; $k++) {
                $scanJ[$k] = self::ZIGZAG_SCAN_4X4[$k + 1];
            }
            $maxCoeff = 15;
        } else {
            $scanJ = self::ZIGZAG_SCAN_4X4;
            $maxCoeff = 16;
        }
        $this->cabRawTag = "cat{$cat}n{$n}";
        [$map, $nz] = $this->cabResidualCore($cat, $scanJ, $maxCoeff, $qmul, false);
        $block = array_fill(0, 16, 0);
        foreach ($map as $j => $v) {
            $block[$j] = $v;
        }
        $this->cabNzCache[$cell] = $nz;
        return [$block, $nz];
    }

    /**
     * I16x16 亮度 DC（16 个原始 level，scan 序）。含 CBF。
     * @return int[] 16 个原始 level，键为扫描位 k（0..15）
     */
    private function cabResidualLumaDc(): array
    {
        $mbIdx = $this->cabMbIdx;
        // DC CBF ctx：cat0 idx0，nza/nzb 取左/上 cbp bit8（不可用 I 邻居 0x7CF 含 bit8）
        [$lCbp, $tCbp] = $this->cabNeighborCbp();
        $nza = ($lCbp & 0x100) ? 1 : 0;
        $nzb = ($tCbp & 0x100) ? 1 : 0;
        if ($this->cabGet(self::$CBF_BASE[0] + $nza + 2 * $nzb) === 0) {
            $this->cabNzCache[self::$SCAN8[0]] = 0;
            return array_fill(0, 16, 0);
        }
        [$map] = $this->cabResidualCore(0, self::ZIGZAG_SCAN_4X4, 16, null, false);
        // map 的 key 已是 raster 位（cabResidualCore 内部 $j = scanJ[$k]），此处仅展开为定长数组
        $out = array_fill(0, 16, 0);
        foreach ($map as $k => $v) {
            $out[$k] = $v;
        }
        $this->picCbp[$mbIdx] |= 0x100;
        return $out;
    }

    /** 色度 DC（4 个原始 level）。$c 0=Cb 1=Cr。含 CBF。 */
    private function cabResidualChromaDc(int $c): array
    {
        $mbIdx = $this->cabMbIdx;
        $bit = 6 + $c;
        [$lCbp, $tCbp] = $this->cabNeighborCbp();
        $nza = ($lCbp & (1 << $bit)) ? 1 : 0;
        $nzb = ($tCbp & (1 << $bit)) ? 1 : 0;
        if ($this->cabGet(self::$CBF_BASE[3] + $nza + 2 * $nzb) === 0) {
            // decode_cabac_residual_dc：CBF=0 时置 nzCache[scan8[n]]=0（n=16+c）
            $this->cabNzCache[self::$SCAN8[16 + $c]] = 0;
            return [0, 0, 0, 0];
        }
        [$map] = $this->cabResidualCore(3, [0, 1, 2, 3], 4, null, false);
        $out = [0, 0, 0, 0];
        foreach ($map as $k => $v) {
            $out[$k] = $v;
        }
        $this->picCbp[$mbIdx] |= (1 << $bit);
        return $out;
    }

    /**
     * 8x8 亮度残差（cat5，420 无逐块 CBF，由 cbp 位决定）。
     * 输出 64 系数列主序（与 buildQmul8/idct8AddToPlane 一致）。
     * @return int[]|null 64 系数；无残差返回 null
     */
    private function cabResidualLuma8x8(int $p8, bool $intra): ?array
    {
        // scanJ[k] = TRANSPOSE8(ff_zigzag_direct[k])（FFmpeg 存储布局）
        $scanJ = [];
        for ($k = 0; $k < 64; $k++) {
            $z = self::$ZZ8[$k];
            $scanJ[$k] = ($z >> 3) | (($z & 7) << 3);
        }
        $qmul = $this->buildQmul8($this->cabQp, $intra);
        [$map] = $this->cabResidualCore(5, $scanJ, 64, $qmul, true);
        $block = array_fill(0, 64, 0);
        foreach ($map as $j => $v) {
            $block[$j] = $v;
        }
        // nz：四个 4x4 块都记 coeffCount（fill_rectangle(&nz_cache[scan8[4*i8x8]],2,2,8)）
        $nz = count($map);
        $baseCell = self::$SCAN8[4 * $p8];
        $this->cabNzCache[$baseCell] = $nz;
        $this->cabNzCache[$baseCell + 1] = $nz;
        $this->cabNzCache[$baseCell + 8] = $nz;
        $this->cabNzCache[$baseCell + 9] = $nz;
        // legacy 24-raster（deblock）
        $origin = [0 => 0, 1 => 2, 2 => 8, 3 => 10][$p8];
        foreach ([[0, 0], [1, 0], [0, 1], [1, 1]] as [$dx, $dy]) {
            $this->cabNz[$origin + $dx + 4 * $dy] = $nz;
        }
        return $block;
    }

    // ============================================================
    // I 片宏块编排
    // ============================================================

    /**
     * 解码一个 I 片宏块（CABAC）。
     * 调用前必须已 cabacSliceBegin 且 cabMbBegin。
     */
    private function decodeCabacIntraMb(int $mbX, int $mbY, ?array $preType = null): void
    {
        $this->cabMbBegin($mbX, $mbY);
        $mbIdx = $this->cabMbIdx;
        // 同位帧 intra 标记（B 帧 spatial direct 的 colZero 判定依赖）
        $this->picMbType[$mbY * $this->picWidthInMbs + $mbX] |= 1;

        $t = $preType ?? $this->cabReadIntraMbType(3, true);
        // intra 恒定：不可用邻居 nz 哨兵转 64（FFmpeg IS_INTRA -> 0x40）
        $this->cabFixUnavailNz(true);

        if ($t['k'] === 'pcm') {
            $this->cabDecodePcm();
            return;
        }

        if ($t['k'] === 'i4') {
            $this->cabDecodeI4x4($mbX, $mbY);
        } else {
            $this->cabDecodeI16x16($mbX, $mbY, $t['mode'], $t['luma15'], $t['chromaCbp']);
        }
    }

    private function cabNeighborTransformSize(): int
    {
        $n = 0;
        $l = $this->cabLeftMbIdx();
        $t = $this->cabTopMbIdx();
        if ($l >= 0 && ($this->picMbType[$l] & self::$CAB_FLG_DCT8)) {
            $n++;
        }
        if ($t >= 0 && ($this->picMbType[$t] & self::$CAB_FLG_DCT8)) {
            $n++;
        }
        return $n;
    }

    private function cabDecodeI4x4(int $mbX, int $mbY): void
    {
        $mbIdx = $this->cabMbIdx;
        // 模式数组按 n（8x8 分组序块号）存放，与 i4Cache 写回一致
        $modes = array_fill(0, 16, 2);
        $dct8 = false;

        // transform_8x8 标志在所有 pred 模式之前读
        if ($this->transform8x8ModeFlag && $this->cabGet(399 + $this->cabNeighborTransformSize())) {
            $dct8 = true;
            // 只在每个 8x8 分组左上块 n=0,4,8,12 读模式，2x2 填充（fill_rectangle）
            foreach ([0, 4, 8, 12] as $n0) {
                $m = $this->cabReadIntra4Mode($this->cabPredModeN($n0));
                $cell = self::$SCAN8[$n0];
                $this->cabI4Cache[$cell] = $m;
                $this->cabI4Cache[$cell + 1] = $m;
                $this->cabI4Cache[$cell + 8] = $m;
                $this->cabI4Cache[$cell + 9] = $m;
                $modes[$n0] = $m;
                $modes[$n0 + 1] = $m;
                $modes[$n0 + 2] = $m;
                $modes[$n0 + 3] = $m;
            }
        } else {
            for ($n = 0; $n < 16; $n++) {
                $m = $this->cabReadIntra4Mode($this->cabPredModeN($n));
                $modes[$n] = $m;
                $this->cabI4Cache[self::$SCAN8[$n]] = $m;
            }
        }

        $chromaPredMode = $this->cabReadChromaPredMode();
        $lumaCbp = $this->cabReadCbpLuma();
        $chromaCbp = $this->cabReadCbpChroma();
        $cbp = $lumaCbp | ($chromaCbp << 4);
        $this->cabCbp = $cbp;
        $this->picCbp[$mbIdx] = $cbp;
        $this->picChromaPred[$mbIdx] = $chromaPredMode;

        // I4x4 仅 cbp!=0 时读 mb_qp_delta
        if ($cbp !== 0) {
            $this->cabReadQpDelta();
        } else {
            $this->cabLastQpDiff = 0;
        }

        $qp = $this->cabQp;

        // ---------- 亮度残差（n 序；cbp 位 = n>>2）----------
        if ($dct8) {
            // FFmpeg hl_decode_mb 的 8x8DCT 分支：逐 8x8 块 预测→叠残差→重建完成，
            // 后续块的预测使用已重建邻居样值（不能先写全部预测再统一叠残差）
            for ($p8 = 0; $p8 < 4; $p8++) {
                $bx = $p8 & 1;
                $by = $p8 >> 1;
                $x0 = $mbX * 16 + $bx * 8;
                $y0 = $mbY * 16 + $by * 8;
                $pred = $this->intra8x8Prediction($mbX, $mbY, $bx, $by, $modes[4 * $p8]);
                for ($yy = 0; $yy < 8; $yy++) {
                    $py = $y0 + $yy;
                    if ($py >= $this->height) {
                        break;
                    }
                    $rowOff = $py * $this->width;
                    for ($xx = 0; $xx < 8; $xx++) {
                        $px = $x0 + $xx;
                        if ($px < $this->width) {
                            $this->yPlane[$rowOff + $px] = $pred[$yy][$xx];
                        }
                    }
                }
                if (($lumaCbp & (1 << $p8)) !== 0) {
                    $block = $this->cabResidualLuma8x8($p8, true);
                    $this->idct8AddToPlane($this->yPlane, $this->width, $x0, $y0, $block);
                } else {
                    $cell = self::$SCAN8[4 * $p8];
                    $this->cabNzCache[$cell] = 0;
                    $this->cabNzCache[$cell + 1] = 0;
                    $this->cabNzCache[$cell + 8] = 0;
                    $this->cabNzCache[$cell + 9] = 0;
                }
            }
        } else {
            // 阶段1：按 n 序（8x8 分组序）解码全部残差系数块
            $blocksN = array_fill(0, 16, null);
            for ($n = 0; $n < 16; $n++) {
                if (($lumaCbp & (1 << ($n >> 2))) !== 0) {
                    [$blocksN[$n]] = $this->cabDecodeAcBlock(2, $n, 0, $qp, false);
                } else {
                    $blocksN[$n] = array_fill(0, 16, 0);
                    $this->cabNzCache[self::$SCAN8[$n]] = 0;
                }
            }
            // 阶段2：按 raster 序重建像素（intra4x4 预测依赖已重建的上/右上邻居）
            for ($r = 0; $r < 16; $r++) {
                $n = self::$R2N[$r];
                $this->cabWriteI4PredictionWithResidual($mbX, $mbY, $r, $modes[$n], $blocksN[$n]);
            }
        }

        // ---------- 色度 ----------
        $chromaQp = $this->cabChromaQp();
        $this->cabWriteChroma($mbX, $mbY, $chromaPredMode, $chromaCbp, $chromaQp, true);

        // ---------- 邻居缓存写回 ----------
        $this->cabFinishI4Bookkeeping($mbX, $mbY, $modes, $dct8 ? self::$CAB_FLG_DCT8 : 0);
    }

    /** 仅写 I4x4 亮度预测（8x8 DCT 路径用，残差由 idct8 统一叠加） */
    private function cabWriteI4Prediction(int $mbX, int $mbY, int $raster, int $mode): void
    {
        $blkX = $raster & 3;
        $blkY = $raster >> 2;
        $pred = $this->intra4x4Prediction($mbX, $mbY, $blkX, $blkY, $mode);
        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                $py = $mbY * 16 + $blkY * 4 + $y;
                $px = $mbX * 16 + $blkX * 4 + $x;
                if ($py < $this->height && $px < $this->width) {
                    $this->yPlane[$py * $this->width + $px] = max(0, min(255, $pred[$y][$x]));
                }
            }
        }
    }

    /** 写 I4x4 亮度预测 + 4x4 残差（一条 idct4x4Flat，内含 +32 偏置） */
    private function cabWriteI4PredictionWithResidual(
        int $mbX,
        int $mbY,
        int $raster,
        int $mode,
        array $block
    ): void {
        $blkX = $raster & 3;
        $blkY = $raster >> 2;
        $pred = $this->intra4x4Prediction($mbX, $mbY, $blkX, $blkY, $mode);
        $idct = $this->idct4x4Flat($block);
        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                $py = $mbY * 16 + $blkY * 4 + $y;
                $px = $mbX * 16 + $blkX * 4 + $x;
                if ($py < $this->height && $px < $this->width) {
                    $v = $pred[$y][$x] + $idct[$y * 4 + $x];
                    $this->yPlane[$py * $this->width + $px] = max(0, min(255, $v));
                }
            }
        }
    }

    /** 把 nzCache 当前矩形汇总为 24-raster（0..15 亮度，16..19 Cb，20..23 Cr）到 $cabNz */
    private function cabBuildNz24(): void
    {
        $nz = &$this->cabNzCache;
        for ($n = 0; $n < 16; $n++) {
            $this->cabNz[self::$N2R[$n]] = $nz[self::$SCAN8[$n]];
        }
        for ($b = 0; $b < 4; $b++) {
            $this->cabNz[16 + $b] = $nz[self::$SCAN8[16 + $b]]; // 52,53,60,61
            $this->cabNz[20 + $b] = $nz[self::$SCAN8[32 + $b]]; // 92,93,100,101
        }
    }

    private function cabFinishI4Bookkeeping(int $mbX, int $mbY, array $modesN, int $extraFlags): void
    {
        $mbIdx = $this->cabMbIdx;
        $this->picMbType[$mbIdx] = self::$CAB_FLG_I4 | $extraFlags;
        $this->picIs8x8Dct[$mbIdx] = $extraFlags !== 0 ? 1 : 0;
        for ($n = 0; $n < 16; $n++) {
            $this->picIntra4Mode[$mbIdx * 16 + self::$N2R[$n]] = $modesN[$n];
        }
        $this->cabStoreWriteBack(true, $modesN);

        // 跨 MB 预测模式缓存（供 intra4x4Prediction 等既有路径使用），按几何右缘/底边
        $this->intra4x4LeftModes = [$modesN[5], $modesN[7], $modesN[13], $modesN[15]];
        $base = $mbX * 4;
        $this->intra4x4TopModes[$base] = $modesN[10];
        $this->intra4x4TopModes[$base + 1] = $modesN[11];
        $this->intra4x4TopModes[$base + 2] = $modesN[14];
        $this->intra4x4TopModes[$base + 3] = $modesN[15];

        $this->cabBuildNz24();
        $this->cabWriteBackNz($mbX);
        $this->cabWriteDeblock(0);
    }

    /** I16x16 宏块 */
    private function cabDecodeI16x16(
        int $mbX,
        int $mbY,
        int $predMode,
        bool $luma15,
        int $chromaCbp
    ): void {
        $mbIdx = $this->cabMbIdx;
        $lumaCbp = $luma15 ? 15 : 0;
        $cbp = $lumaCbp | ($chromaCbp << 4);
        $this->cabCbp = $cbp;
        $this->picCbp[$mbIdx] = $cbp;

        $chromaPredMode = $this->cabReadChromaPredMode();
        $this->picChromaPred[$mbIdx] = $chromaPredMode;

        // I16 无论 cbp 如何都读 mb_qp_delta
        $this->cabReadQpDelta();
        $qp = $this->cabQp;

        // 亮度 DC：cabResidualCore 内部已按 $j = scanJ[$k] 存到 raster 位（j=ZIGZAG_SCAN_4X4[k]），
        // 因此 cabResidualLumaDc 返回的即是 raster 序，不能再用 ZIGZAG_SCAN_4X4 二次散开
        $yDcRaster = $this->cabResidualLumaDc();
        $lumaQmul = $this->dequant4Table[0][$qp][0];
        $yDcResult = $this->lumaDcDequantIdct($yDcRaster, $lumaQmul);

        // 亮度 AC（n 序，cat1，15 系数 zz15）
        $yAcN = array_fill(0, 16, null);
        if ($lumaCbp !== 0) {
            for ($n = 0; $n < 16; $n++) {
                [$yAcN[$n]] = $this->cabDecodeAcBlock(1, $n, 0, $qp, true);
            }
        }

        // 色度
        $chromaQp = $this->cabChromaQp();
        $cbDc = [0, 0, 0, 0];
        $crDc = [0, 0, 0, 0];
        if ($chromaCbp > 0) {
            $cbDc = $this->cabResidualChromaDc(0);
            $crDc = $this->cabResidualChromaDc(1);
        }
        $cbDcResult = $this->chromaDcDequantIdct($cbDc, $this->dequant4Table[1][$chromaQp][0]);
        $crDcResult = $this->chromaDcDequantIdct($crDc, $this->dequant4Table[2][$chromaQp][0]);

        $cbAc = array_fill(0, 4, array_fill(0, 16, 0));
        $crAc = array_fill(0, 4, array_fill(0, 16, 0));
        if ($chromaCbp >= 2) {
            for ($b = 0; $b < 4; $b++) {
                [$cbAc[$b]] = $this->cabDecodeAcBlock(4, 16 + $b, 1, $chromaQp, true);
            }
            for ($b = 0; $b < 4; $b++) {
                [$crAc[$b]] = $this->cabDecodeAcBlock(4, 32 + $b, 2, $chromaQp, true);
            }
        }

        // ---------- 亮度预测 + 残差 ----------
        $lumaPred = $this->intra16x16Prediction($mbX, $mbY, $predMode);
        for ($by = 0; $by < 4; $by++) {
            for ($bx = 0; $bx < 4; $bx++) {
                $blk = $by * 4 + $bx;
                $dcRes = $yDcResult[$blk];
                if ($lumaCbp !== 0) {
                    $acBlock = $yAcN[self::$R2N[$blk]];
                    $acBlock[0] = $dcRes;
                    $idct = $this->idct4x4Flat($acBlock);
                    for ($y = 0; $y < 4; $y++) {
                        for ($x = 0; $x < 4; $x++) {
                            $py = $mbY * 16 + $by * 4 + $y;
                            $px = $mbX * 16 + $bx * 4 + $x;
                            if ($py < $this->height && $px < $this->width) {
                                $v = $lumaPred[$by * 4 + $y][$bx * 4 + $x] + $idct[$y * 4 + $x];
                                $this->yPlane[$py * $this->width + $px] = max(0, min(255, $v));
                            }
                        }
                    }
                } else {
                    $dcAdd = ($dcRes + 32) >> 6;
                    for ($y = 0; $y < 4; $y++) {
                        for ($x = 0; $x < 4; $x++) {
                            $py = $mbY * 16 + $by * 4 + $y;
                            $px = $mbX * 16 + $bx * 4 + $x;
                            if ($py < $this->height && $px < $this->width) {
                                $v = $lumaPred[$by * 4 + $y][$bx * 4 + $x] + $dcAdd;
                                $this->yPlane[$py * $this->width + $px] = max(0, min(255, $v));
                            }
                        }
                    }
                }
            }
        }

        $this->cabWriteChromaBlocks($mbX, $mbY, $chromaPredMode, $chromaCbp, $cbDcResult, $crDcResult, $cbAc, $crAc);

        // ---------- 邻居缓存 ----------
        $this->picMbType[$mbIdx] = self::$CAB_FLG_I16;
        $this->picIs8x8Dct[$mbIdx] = 0;
        $this->cabStoreWriteBack(false, []);
        // I16 对 I4 邻居而言推断为 DC_PRED(2)
        $this->intra4x4LeftModes = [2, 2, 2, 2];
        $base = $mbX * 4;
        $this->intra4x4TopModes[$base] = 2;
        $this->intra4x4TopModes[$base + 1] = 2;
        $this->intra4x4TopModes[$base + 2] = 2;
        $this->intra4x4TopModes[$base + 3] = 2;
        $this->cabBuildNz24();
        $this->cabWriteBackNz($mbX);
        $chrType = $chromaCbp === 2 ? 8 : ($chromaCbp === 1 ? 4 : 0);
        $this->cabWriteDeblock(1 + ($luma15 ? 12 : 0) + $chrType + $predMode);
    }

    /**
     * 色度预测 + DC/AC 叠加（I16 与 I4 共用）。
     * 若未提供 $cbAc/$crAc（I4 路径），内部按 chromaCbp 自行解码。
     */
    private function cabWriteChroma(
        int $mbX,
        int $mbY,
        int $chromaPredMode,
        int $chromaCbp,
        int $chromaQp,
        bool $intra
    ): void {
        $cbDc = [0, 0, 0, 0];
        $crDc = [0, 0, 0, 0];
        if ($chromaCbp > 0) {
            $cbDc = $this->cabResidualChromaDc(0);
            $crDc = $this->cabResidualChromaDc(1);
        }
        $cbDcResult = $this->chromaDcDequantIdct($cbDc, $this->dequant4Table[$intra ? 1 : 4][$chromaQp][0]);
        $crDcResult = $this->chromaDcDequantIdct($crDc, $this->dequant4Table[$intra ? 2 : 5][$chromaQp][0]);

        $cbAc = array_fill(0, 4, array_fill(0, 16, 0));
        $crAc = array_fill(0, 4, array_fill(0, 16, 0));
        if ($chromaCbp >= 2) {
            $cbList = $intra ? 1 : 4;
            $crList = $intra ? 2 : 5;
            for ($b = 0; $b < 4; $b++) {
                [$cbAc[$b]] = $this->cabDecodeAcBlock(4, 16 + $b, $cbList, $chromaQp, true);
            }
            for ($b = 0; $b < 4; $b++) {
                [$crAc[$b]] = $this->cabDecodeAcBlock(4, 32 + $b, $crList, $chromaQp, true);
            }
        } else {
            // FFmpeg h264_cabac.c: cbp&0x20==0 时 fill_rectangle 清零色度 AC nz，
            // 否则陈旧值会经 48-store 污染后续 MB 的 CBF 邻居
            foreach (self::$CELL_CB_ST as $cell) {
                $this->cabNzCache[$cell] = 0;
            }
            foreach (self::$CELL_CR_ST as $cell) {
                $this->cabNzCache[$cell] = 0;
            }
        }
        $this->cabWriteChromaBlocks($mbX, $mbY, $chromaPredMode, $chromaCbp, $cbDcResult, $crDcResult, $cbAc, $crAc);
    }

    private function cabWriteChromaBlocks(
        int $mbX,
        int $mbY,
        int $chromaPredMode,
        int $chromaCbp,
        array $cbDcResult,
        array $crDcResult,
        array $cbAc,
        array $crAc
    ): void {
        $cbPred = $this->intraChromaPrediction($mbX, $mbY, $chromaPredMode, 0);
        $crPred = $this->intraChromaPrediction($mbX, $mbY, $chromaPredMode, 1);
        $cw = intdiv($this->width, 2);
        $ch = intdiv($this->height, 2);

        for ($by = 0; $by < 2; $by++) {
            for ($bx = 0; $bx < 2; $bx++) {
                $blk = $by * 2 + $bx;
                if ($chromaCbp >= 2) {
                    $cbBlock = $cbAc[$blk];
                    $crBlock = $crAc[$blk];
                    $cbBlock[0] = $cbDcResult[$blk];
                    $crBlock[0] = $crDcResult[$blk];
                    $idctCb = $this->idct4x4Flat($cbBlock);
                    $idctCr = $this->idct4x4Flat($crBlock);
                    for ($y = 0; $y < 4; $y++) {
                        for ($x = 0; $x < 4; $x++) {
                            $py = $mbY * 8 + $by * 4 + $y;
                            $px = $mbX * 8 + $bx * 4 + $x;
                            if ($py < $ch && $px < $cw) {
                                $u = $cbPred[$by * 4 + $y][$bx * 4 + $x] + $idctCb[$y * 4 + $x];
                                $v = $crPred[$by * 4 + $y][$bx * 4 + $x] + $idctCr[$y * 4 + $x];
                                $idx = $py * $cw + $px;
                                $this->uPlane[$idx] = max(0, min(255, $u));
                                $this->vPlane[$idx] = max(0, min(255, $v));
                            }
                        }
                    }
                } elseif ($chromaCbp === 1) {
                    $cbAdd = ($cbDcResult[$blk] + 32) >> 6;
                    $crAdd = ($crDcResult[$blk] + 32) >> 6;
                    for ($y = 0; $y < 4; $y++) {
                        for ($x = 0; $x < 4; $x++) {
                            $py = $mbY * 8 + $by * 4 + $y;
                            $px = $mbX * 8 + $bx * 4 + $x;
                            if ($py < $ch && $px < $cw) {
                                $idx = $py * $cw + $px;
                                $this->uPlane[$idx] = max(0, min(255, $cbPred[$by * 4 + $y][$bx * 4 + $x] + $cbAdd));
                                $this->vPlane[$idx] = max(0, min(255, $crPred[$by * 4 + $y][$bx * 4 + $x] + $crAdd));
                            }
                        }
                    }
                } else {
                    for ($y = 0; $y < 4; $y++) {
                        for ($x = 0; $x < 4; $x++) {
                            $py = $mbY * 8 + $by * 4 + $y;
                            $px = $mbX * 8 + $bx * 4 + $x;
                            if ($py < $ch && $px < $cw) {
                                $idx = $py * $cw + $px;
                                $this->uPlane[$idx] = max(0, min(255, $cbPred[$by * 4 + $y][$bx * 4 + $x]));
                                $this->vPlane[$idx] = max(0, min(255, $crPred[$by * 4 + $y][$bx * 4 + $x]));
                            }
                        }
                    }
                }
            }
        }
    }

    /** 把本 MB nz 写回去块滤波等使用的行列缓存 */
    private function cabWriteBackNz(int $mbX): void
    {
        $nz = $this->cabNz;
        $this->nzLeftColLuma[0] = $nz[3];
        $this->nzLeftColLuma[1] = $nz[7];
        $this->nzLeftColLuma[2] = $nz[11];
        $this->nzLeftColLuma[3] = $nz[15];
        $base = $mbX * 4;
        $this->nzTopRowLuma[$base] = $nz[12];
        $this->nzTopRowLuma[$base + 1] = $nz[13];
        $this->nzTopRowLuma[$base + 2] = $nz[14];
        $this->nzTopRowLuma[$base + 3] = $nz[15];

        $this->nzLeftColChroma[0] = $nz[17];
        $this->nzLeftColChroma[1] = $nz[19];
        $this->nzLeftColChroma[2] = $nz[21];
        $this->nzLeftColChroma[3] = $nz[23];
        $this->nzTopRowChroma[$mbX * 2] = $nz[18];
        $this->nzTopRowChroma[$mbX * 2 + 1] = $nz[19];
        $w2 = $this->picWidthInMbs * 2;
        $this->nzTopRowChroma[$w2 + $mbX * 2] = $nz[22];
        $this->nzTopRowChroma[$w2 + $mbX * 2 + 1] = $nz[23];
    }

    /** 去块滤波簿记（语法 mb_type：I4=0；I16=1..24；PCM=25） */
    private function cabWriteDeblock(int $syntaxType): void
    {
        if (!$this->deblockInfoEnabled) {
            return;
        }
        $mbIdx = $this->cabMbIdx;
        $this->mbTypeForDeblock[$mbIdx] = $syntaxType;
        $this->mbNnzForDeblock[$mbIdx] = $this->cabNz;
        $this->mbQpForDeblock[$mbIdx] = $this->cabQp;
    }

    // ============================================================
    // I_PCM
    // ============================================================

    private function cabDecodePcm(): void
    {
        // 定位 PCM 起始：当前 ptr 按 low 回退 0~2 字节
        $ptr = $this->cabacPtr;
        if (($this->cabacLow & 0x1) !== 0) {
            $ptr--;
        }
        if (($this->cabacLow & 0x1FF) !== 0) {
            $ptr--;
        }

        $mbX = $this->cabMbX;
        $mbY = $this->cabMbY;
        $cw = intdiv($this->width, 2);
        // 256 Y
        $off = $ptr;
        for ($yy = 0; $yy < 16; $yy++) {
            $py = $mbY * 16 + $yy;
            for ($xx = 0; $xx < 16; $xx++) {
                $px = $mbX * 16 + $xx;
                if ($py < $this->height && $px < $this->width) {
                    $this->yPlane[$py * $this->width + $px] = $this->cabacAbsByte($off++);
                } else {
                    $off++;
                }
            }
        }
        // 128 Cb + 128 Cr（420）
        foreach ([0 => $this->uPlane, 1 => $this->vPlane] as $uv => $plane) {
            for ($yy = 0; $yy < 8; $yy++) {
                $py = $mbY * 8 + $yy;
                for ($xx = 0; $xx < 8; $xx++) {
                    $px = $mbX * 8 + $xx;
                    $b = $this->cabacAbsByte($off++);
                    if ($py < intdiv($this->height, 2) && $px < $cw) {
                        if ($uv === 0) {
                            $this->uPlane[$py * $cw + $px] = $b;
                        } else {
                            $this->vPlane[$py * $cw + $px] = $b;
                        }
                    }
                }
            }
        }

        // PCM 之后按 ptr+384 重初始化引擎
        $this->cabacInitAt($ptr + 384);

        $mbIdx = $this->cabMbIdx;
        $this->picMbType[$mbIdx] = self::$CAB_FLG_PCM;
        $this->picIsPcm[$mbIdx] = 1;
        $this->picIs8x8Dct[$mbIdx] = 0;
        $this->picChromaPred[$mbIdx] = 0;
        $this->picCbp[$mbIdx] = 0xf7ef;
        // PCM：当前矩形所有 nz=16 并持久化（ff_h264_write_back_non_zero_count 语义）
        foreach (self::$CELL_RESET as $cell) {
            $this->cabNzCache[$cell] = 16;
        }
        $this->cabStoreWriteBack(false, []);
        for ($r = 0; $r < 24; $r++) {
            $this->cabNz[$r] = 16;
        }
        $this->intra4x4LeftModes = [2, 2, 2, 2];
        $base = $mbX * 4;
        $this->intra4x4TopModes[$base] = 2;
        $this->intra4x4TopModes[$base + 1] = 2;
        $this->intra4x4TopModes[$base + 2] = 2;
        $this->intra4x4TopModes[$base + 3] = 2;
        $this->cabLastQpDiff = 0;
        $this->cabWriteBackNz($mbX);
        $this->cabWriteDeblock(25);
    }

    // ================================================================
    // CABAC P/B 宏块（FFmpeg h264_cabac.c ff_h264_decode_mb_cabac）
    // ================================================================

    /**
     * 当前 MB 某分区（首块 n）的左/上邻居块定位。
     * @return int[] [lMb, lBlk, tMb, tBlk]，Mb 为 -1 表示不可用
     */
    private function cabNbBlock(int $n): array
    {
        $mbIdx = $this->cabMbIdx;
        $w = $this->picWidthInMbs;
        $bx = $n & 3;
        $by = $n >> 2;
        $lMb = -1; $lBlk = 0;
        $tMb = -1; $tBlk = 0;
        if ($bx > 0) {
            $lMb = $mbIdx; $lBlk = $n - 1;
        } elseif ($this->cabMbX > 0) {
            $lMb = $mbIdx - 1; $lBlk = $by * 4 + 3;
        }
        if ($by > 0) {
            $tMb = $mbIdx; $tBlk = $n - 4;
        } elseif ($this->cabMbY > 0) {
            $tMb = $mbIdx - $w; $tBlk = ($n & 3) + 12;
        }
        return [$lMb, $lBlk, $tMb, $tBlk];
    }

    /** mb_skip_flag（decode_cabac_mb_skip） */
    private function cabReadMbSkip(bool $isB): bool
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
        return $this->cabGet(11 + $ctx) !== 0;
    }

    /** P mb_type（ctx 14..17）：>=0 为 inter（0..3），-1 为 intra */
    private function cabReadPMbType(): int
    {
        if ($this->cabGet(14) === 0) {
            if ($this->cabGet(15) === 0) {
                return $this->cabGet(16) !== 0 ? 3 : 0;
            }
            return $this->cabGet(17) !== 0 ? 1 : 2;
        }
        return -1;
    }

    /**
     * B mb_type（ctx 27..32）。
     * k: 'direct' | 'inter'（mbType 0..22）| 'i4'/'i16'/'pcm'（B 片内嵌 Intra）
     */
    private function cabReadBMbType(): array
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
        if ($this->cabGet(27 + $ctx) === 0) {
            return ['k' => 'direct', 'mbType' => 0];
        }
        if ($this->cabGet(30) === 0) {
            return ['k' => 'inter', 'mbType' => 1 + ($this->cabGet(32) !== 0 ? 1 : 0)];
        }
        $bits = 0;
        if ($this->cabGet(31) !== 0) $bits |= 8;
        if ($this->cabGet(32) !== 0) $bits |= 4;
        if ($this->cabGet(32) !== 0) $bits |= 2;
        if ($this->cabGet(32) !== 0) $bits |= 1;
        if ($bits < 8) {
            return ['k' => 'inter', 'mbType' => $bits + 3];
        }
        if ($bits === 13) {
            // B 片内嵌 Intra（decode_cabac_intra_mb_type ctx 32, intra_slice=0）
            return $this->cabReadIntraMbType(32, false);
        }
        if ($bits === 14) {
            return ['k' => 'inter', 'mbType' => 11];
        }
        if ($bits === 15) {
            return ['k' => 'inter', 'mbType' => 22];
        }
        $bits = ($bits << 1) + ($this->cabGet(32) !== 0 ? 1 : 0);
        return ['k' => 'inter', 'mbType' => $bits - 4];
    }

    /** P sub_mb_type（ctx 21..23） */
    private function cabReadPSubMbType(): int
    {
        if ($this->cabGet(21) !== 0) {
            return 0;
        }
        if ($this->cabGet(22) === 0) {
            return 1;
        }
        if ($this->cabGet(23) !== 0) {
            return 2;
        }
        return 3;
    }

    /** B sub_mb_type（ctx 36..39） */
    private function cabReadBSubMbType(): int
    {
        if ($this->cabGet(36) === 0) {
            return 0;
        }
        if ($this->cabGet(37) === 0) {
            return 1 + ($this->cabGet(39) !== 0 ? 1 : 0);
        }
        $type = 3;
        if ($this->cabGet(38) !== 0) {
            if ($this->cabGet(39) !== 0) {
                return 11 + ($this->cabGet(39) !== 0 ? 1 : 0);
            }
            $type += 4;
        }
        $type += 2 * ($this->cabGet(39) !== 0 ? 1 : 0);
        $type += $this->cabGet(39) !== 0 ? 1 : 0;
        return $type;
    }

    /** ref_idx（decode_cabac_mb_ref）：rc<=1 直接返回 0 */
    private function cabReadRefIdx(int $list, int $n, bool $isB): int
    {
        $rc = $list === 0 ? $this->numRefIdxL0Active : $this->numRefIdxL1Active;
        if ($rc <= 1) {
            return 0;
        }
        [$lMb, $lBlk, $tMb, $tBlk] = $this->cabNbBlock($n);
        $store = $list === 0 ? $this->picRef0 : $this->picRef1;
        $ctx = 0;
        // 本 MB 内的邻居：读渐进覆盖（FFmpeg ref_cache 在 MB 解码过程中渐进填写，
        // ref 循环里后解码象限的 ctx 能看到先解码象限的 ref）
        if ($lMb >= 0) {
            if ($lMb === $this->cabMbIdx) {
                $r = $this->cabMbRefOv[$list][$lBlk] ?? -1;
                $isDir = !empty($this->cabMbDirOv[$lBlk]);
            } else {
                $r = $store[$lMb * 16 + $lBlk] ?? -1;
                $isDir = $isB && !empty($this->picDirectQ[$lMb * 4 + self::blkQuad($lBlk)]);
            }
            if ($r > 0 && !$isDir) {
                $ctx++;
            }
        }
        if ($tMb >= 0) {
            if ($tMb === $this->cabMbIdx) {
                $r = $this->cabMbRefOv[$list][$tBlk] ?? -1;
                $isDir = !empty($this->cabMbDirOv[$tBlk]);
            } else {
                $r = $store[$tMb * 16 + $tBlk] ?? -1;
                $isDir = $isB && !empty($this->picDirectQ[$tMb * 4 + self::blkQuad($tBlk)]);
            }
            if ($r > 0 && !$isDir) {
                $ctx += 2;
            }
        }
        $ref = 0;
        while ($this->cabGet(54 + $ctx) !== 0) {
            $ref++;
            $ctx = ($ctx >> 2) + 4;
            if ($ref > 31) {
                break;
            }
        }
        return $ref;
    }

    /** mvd（DECODE_CABAC_MB_MVD），amvd 取自 picMvd0/picMvd1 的左/上邻块 */
    private function cabReadMvd(int $list, int $comp, int $n): int
    {
        [$lMb, $lBlk, $tMb, $tBlk] = $this->cabNbBlock($n);
        $store = $list === 0 ? $this->picMvd0 : $this->picMvd1;
        // FFmpeg mvd_cache 存的是 abs(mvd) 截 70（decode_cabac_mb_mvd 传出 *mvda），
        // amvd = 左/上邻块 abs 值之和
        $amvd = 0;
        if ($lMb >= 0) {
            $m = $store[$lMb * 16 + $lBlk] ?? [0, 0];
            $amvd += min(70, abs($m[$comp]));
        }
        if ($tMb >= 0) {
            $m = $store[$tMb * 16 + $tBlk] ?? [0, 0];
            $amvd += min(70, abs($m[$comp]));
        }
        $ctxBase = $comp === 0 ? 40 : 47;
        // FFmpeg h264_cabac.c L1510：ctx = ctxbase + ((amvd-3)>>31) + ((amvd-33)>>31) + 2
        // 即 amvd<3 -> ctxbase+0，[3,33) -> ctxbase+1，>=33 -> ctxbase+2
        $ctx = $ctxBase + 2 - ($amvd < 3 ? 1 : 0) - ($amvd < 33 ? 1 : 0);
        if ($this->cabGet($ctx) === 0) {
            return 0;
        }
        $mvd = 1;
        $ctxBase += 3;
        while ($mvd < 9 && $this->cabGet($ctxBase) !== 0) {
            if ($mvd < 4) {
                $ctxBase++;
            }
            $mvd++;
        }
        if ($mvd >= 9) {
            $k = 3;
            while ($this->cabBypass() !== 0) {
                $mvd += 1 << $k;
                $k++;
                if ($k > 20) {
                    break;
                }
            }
            while ($k-- > 0) {
                if ($this->cabBypass() !== 0) {
                    $mvd += 1 << $k;
                }
            }
        }
        return $this->cabBypassSign(-$mvd);
    }

    /** 把一个分区的 MVD 写入块级存储（分区内全部 4x4 块同值） */
    private function cabStoreMvd(int $list, int $mbIdx, int $bx, int $by, int $bw, int $bh, int $dx, int $dy): void
    {
        if ($list === 0) {
            $store = &$this->picMvd0;
        } else {
            $store = &$this->picMvd1;
        }
        $base = $mbIdx * 16;
        for ($y = $by; $y < $by + $bh; $y++) {
            $row = $base + $y * 4;
            for ($x = $bx; $x < $bx + $bw; $x++) {
                $store[$row + $x] = [$dx, $dy];
            }
        }
    }

    /** 子分区几何：返回 [块x, 块y, 宽(4x4), 高(4x4)]；兼容 P(0..3) 与 B(0..12) 子类型。
     *  注意 P/B 编号不同：B 的 1/2/3 是 L0/L1/Bi_8x8（整 8x8），P 的 1/2/3 是 8x4/4x8/4x4。
     */
    private function cabSubPartGeometry(int $st, int $i8, int $j, bool $isB = false): array
    {
        $px = ($i8 & 1) * 2;
        $py = ($i8 >> 1) * 2;
        if ($isB && $st >= 1 && $st <= 3) {
            return [$px, $py, 2, 2];
        }
        if ($st === 0) {
            return [$px, $py, 2, 2];
        }
        if ($st === 1 || $st === 4 || $st === 6 || $st === 8) {
            return [$px, $py + $j, 2, 1];
        }
        if ($st === 2 || $st === 5 || $st === 7 || $st === 9) {
            return [$px + $j, $py, 1, 2];
        }
        return [$px + ($j & 1), $py + ($j >> 1), 1, 1];
    }

    /** inter 宏块残差：叠加到运动补偿后的像素上（FFmpeg L2360-2510 inter 残差语义） */
    private function cabDecodeInterResidual(int $mbX, int $mbY, int $cbpLuma, int $cbpChroma, bool $dct8): void
    {
        $qp = $this->cabQp;
        $w = $this->width;
        $h = $this->height;

        // ---------- 亮度 ----------
        if ($dct8) {
            for ($p8 = 0; $p8 < 4; $p8++) {
                if (($cbpLuma & (1 << $p8)) !== 0) {
                    $block = $this->cabResidualLuma8x8($p8, false);
                    if ($block !== null) {
                        $x0 = $mbX * 16 + ($p8 & 1) * 8;
                        $y0 = $mbY * 16 + ($p8 >> 1) * 8;
                        $this->idct8AddToPlane($this->yPlane, $w, $x0, $y0, $block);
                    }
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
                if (($cbpLuma & (1 << ($n >> 2))) !== 0) {
                    [$block] = $this->cabDecodeAcBlock(2, $n, 3, $qp, false);
                    $idct = $this->idct4x4Flat($block);
                    // FFmpeg block_offset[i] 由 scan8[i]-scan8[0] 派生（h264_slice.c L555）：
                    // 残差块号 n 为 8x8 分组序，像素位置须经 N2R 映射到 raster
                    $r = self::$N2R[$n];
                    $px0 = $mbX * 16 + ($r & 3) * 4;
                    $py0 = $mbY * 16 + ($r >> 2) * 4;
                    for ($y = 0; $y < 4; $y++) {
                        $py = $py0 + $y;
                        if ($py >= $h) {
                            break;
                        }
                        $row = $py * $w + $px0;
                        $iy = $y * 4;
                        for ($x = 0; $x < 4; $x++) {
                            if ($px0 + $x >= $w) {
                                break;
                            }
                            $v = $this->yPlane[$row + $x] + $idct[$iy + $x];
                            $this->yPlane[$row + $x] = $v < 0 ? 0 : ($v > 255 ? 255 : $v);
                        }
                    }
                } else {
                    $this->cabNzCache[self::$SCAN8[$n]] = 0;
                }
            }
        }

        // ---------- 色度（叠加残差，不做帧内预测）----------
        $chromaQp = $this->cabChromaQp();
        $cw = intdiv($w, 2);
        $ch = intdiv($h, 2);

        $cbDc = [0, 0, 0, 0];
        $crDc = [0, 0, 0, 0];
        if ($cbpChroma > 0) {
            $cbDc = $this->cabResidualChromaDc(0);
            $crDc = $this->cabResidualChromaDc(1);
        }
        $cbDcResult = $this->chromaDcDequantIdct($cbDc, $this->dequant4Table[4][$chromaQp][0]);
        $crDcResult = $this->chromaDcDequantIdct($crDc, $this->dequant4Table[5][$chromaQp][0]);

        $cbAc = array_fill(0, 4, array_fill(0, 16, 0));
        $crAc = array_fill(0, 4, array_fill(0, 16, 0));
        if ($cbpChroma >= 2) {
            for ($b = 0; $b < 4; $b++) {
                [$cbAc[$b]] = $this->cabDecodeAcBlock(4, 16 + $b, 4, $chromaQp, true);
            }
            for ($b = 0; $b < 4; $b++) {
                [$crAc[$b]] = $this->cabDecodeAcBlock(4, 32 + $b, 5, $chromaQp, true);
            }
        }

        for ($by = 0; $by < 2; $by++) {
            for ($bx = 0; $bx < 2; $bx++) {
                $blk = $by * 2 + $bx;
                if ($cbpChroma >= 2) {
                    $cbBlock = $cbAc[$blk];
                    $crBlock = $crAc[$blk];
                    $cbBlock[0] = $cbDcResult[$blk];
                    $crBlock[0] = $crDcResult[$blk];
                    $idctCb = $this->idct4x4Flat($cbBlock);
                    $idctCr = $this->idct4x4Flat($crBlock);
                    for ($y = 0; $y < 4; $y++) {
                        for ($x = 0; $x < 4; $x++) {
                            $py = $mbY * 8 + $by * 4 + $y;
                            $px = $mbX * 8 + $bx * 4 + $x;
                            if ($py < $ch && $px < $cw) {
                                $idx = $py * $cw + $px;
                                $u = $this->uPlane[$idx] + $idctCb[$y * 4 + $x];
                                $v = $this->vPlane[$idx] + $idctCr[$y * 4 + $x];
                                $this->uPlane[$idx] = $u < 0 ? 0 : ($u > 255 ? 255 : $u);
                                $this->vPlane[$idx] = $v < 0 ? 0 : ($v > 255 ? 255 : $v);
                            }
                        }
                    }
                } elseif ($cbpChroma === 1) {
                    $cbAdd = ($cbDcResult[$blk] + 32) >> 6;
                    $crAdd = ($crDcResult[$blk] + 32) >> 6;
                    for ($y = 0; $y < 4; $y++) {
                        for ($x = 0; $x < 4; $x++) {
                            $py = $mbY * 8 + $by * 4 + $y;
                            $px = $mbX * 8 + $bx * 4 + $x;
                            if ($py < $ch && $px < $cw) {
                                $idx = $py * $cw + $px;
                                $u = $this->uPlane[$idx] + $cbAdd;
                                $v = $this->vPlane[$idx] + $crAdd;
                                $this->uPlane[$idx] = $u < 0 ? 0 : ($u > 255 ? 255 : $u);
                                $this->vPlane[$idx] = $v < 0 ? 0 : ($v > 255 ? 255 : $v);
                            }
                        }
                    }
                }
            }
        }
    }

    /** inter 宏块收尾簿记：cbp/i4/nz/去块 */
    private function cabFinishInterMb(int $mbX, int $mbY, int $cbp, bool $dct8, int $deblockType): void
    {
        $mbIdx = $this->cabMbIdx;
        $this->cabCbp = $cbp;
        // picCbp 已在残差解码前赋值（含残差期 OR 上的 DC 标志位），此处不得覆盖
        $this->picIs8x8Dct[$mbIdx] = $dct8 ? 1 : 0;
        if ($dct8) {
            $this->picMbType[$mbIdx] |= self::$CAB_FLG_DCT8;
        }
        $this->cabStoreWriteBack(false, []);
        $this->intra4x4LeftModes = [2, 2, 2, 2];
        $base = $mbX * 4;
        $this->intra4x4TopModes[$base] = 2;
        $this->intra4x4TopModes[$base + 1] = 2;
        $this->intra4x4TopModes[$base + 2] = 2;
        $this->intra4x4TopModes[$base + 3] = 2;
        $this->cabBuildNz24();
        $this->cabWriteBackNz($mbX);
        $this->cabWriteDeblock($deblockType);
    }

    /** CABAC P 片宏块 */
    private function decodeCabacPMb(int $mbX, int $mbY): void
    {
        $this->cabMbBegin($mbX, $mbY);
        $mbIdx = $this->cabMbIdx;
        $this->picChromaPred[$mbIdx] = 0;
        $this->picIsSkip[$mbIdx] = 0;

        // ---------- skip（decode_cabac_mb_skip）----------
        if ($this->cabReadMbSkip(false)) {
            $this->picIsSkip[$mbIdx] = 1;
            $this->picCbp[$mbIdx] = 0;   // FFmpeg decode_mb_skip: h->cbp_table[mb_xy] = 0
            $this->cabLastQpDiff = 0;    // FFmpeg L1952: sl->last_qscale_diff = 0
            $this->decodePSkip($mbX, $mbY);
            $this->cabWriteDeblock(0);
            return;
        }

        $mbType = $this->cabReadPMbType();
        if ($mbType === -1) {
            // P 片内嵌 Intra（ctx 17, intra_slice=0）
            $this->picIsSkip[$mbIdx] = 0;
            $intraType = $this->cabReadIntraMbType(17, false);
            $this->decodeCabacIntraMb($mbX, $mbY, $intraType);
            // intra 邻居：P 缓存 MV/ref 标记未使用
            $intraMv = [0, 0, -1];
            $this->mvLeftCol = [$intraMv, $intraMv, $intraMv, $intraMv];
            $base = $mbX * 4;
            for ($i = 0; $i < 4; $i++) {
                $this->mvTopRow[$base + $i] = $intraMv;
            }
            return;
        }

        // ---------- inter 语法 ----------
        // inter MB：不可用邻居 nz 哨兵转 0（FFmpeg !IS_INTRA -> 0）
        $this->cabFixUnavailNz(false);
        $dct8Allowed = $this->transform8x8ModeFlag;
        $mcTasks = [];
        $mbMvs = [];

        if ($mbType === 0) {
            // P_L0_16x16
            $refIdx = $this->cabReadRefIdx(0, 0, false);
            $this->cabOvRefBox(0, 0, 0, 4, 4, $refIdx);
            [$px, $py] = $this->getP16x16MvPrediction($mbX, $mbY, $refIdx);
            $mvdX = $this->cabReadMvd(0, 0, 0);
            $mvdY = $this->cabReadMvd(0, 1, 0);
            $mvX = $px + $mvdX;
            $mvY = $py + $mvdY;
            $this->performMotionCompensation16x16($mbX, $mbY, $mvX, $mvY, $refIdx);
            $this->saveMvForPrediction($mbX, $mbY, $mvX, $mvY, $refIdx);
            $this->cabStoreMvd(0, $mbIdx, 0, 0, 4, 4, $mvdX, $mvdY);
            if ($this->deblockInfoEnabled) {
                $this->mbMvForDeblock[$mbIdx] = array_fill(0, 16, [$mvX, $mvY]);
                $this->mbRefForDeblock[$mbIdx] = array_fill(0, 16, $refIdx);
            }
        } elseif ($mbType === 1 || $mbType === 2) {
            // P_L0_16x8 / P_L0_8x16
            // anchor 用 raster 块号（p1 首块：16x8=(0,2)→8，8x16=(2,0)→2；
            // FFmpeg per-quadrant 8/4 经 scan8 落在同位置，cabNbBlock 按空间取邻居）
            $ns = $mbType === 1 ? [0, 8] : [0, 2];
            $ref0 = $this->cabReadRefIdx(0, $ns[0], false);
            // FFmpeg 16x8/8x16：每分区 ref 读后立即 fill ref_cache（part1 的 ref ctx 可见 part0）
            if ($mbType === 1) {
                $this->cabOvRefBox(0, 0, 0, 4, 2, $ref0);
            } else {
                $this->cabOvRefBox(0, 0, 0, 2, 4, $ref0);
            }
            $ref1 = $this->cabReadRefIdx(0, $ns[1], false);
            if ($mbType === 1) {
                $this->cabOvRefBox(0, 0, 2, 4, 2, $ref1);
            } else {
                $this->cabOvRefBox(0, 2, 0, 2, 4, $ref1);
            }
            $mv0X = 0; $mv0Y = 0; $mv1X = 0; $mv1Y = 0;
            $mvd0X = 0; $mvd0Y = 0; $mvd1X = 0; $mvd1Y = 0;
            if ($mbType === 1) {
                [$px, $py] = $this->getP16x8MvPrediction($mbX, $mbY, 0, $ref0);
                $mvd0X = $this->cabReadMvd(0, 0, 0);
                $mvd0Y = $this->cabReadMvd(0, 1, 0);
                $mv0X = $px + $mvd0X; $mv0Y = $py + $mvd0Y;
                // FFmpeg L2282：每分区读后立即写回 mvd_cache（part1 的 amvd 依赖 part0 的值）
                $this->cabStoreMvd(0, $mbIdx, 0, 0, 4, 2, $mvd0X, $mvd0Y);
                // FFmpeg L2278：part1 的 pred 经 mv_cache（part0 的 ref 已回填），mvPart0 须带 ref0
                [$px, $py] = $this->getP16x8MvPrediction($mbX, $mbY, 1, $ref1, [$mv0X, $mv0Y, $ref0]);
                $mvd1X = $this->cabReadMvd(0, 0, 8);
                $mvd1Y = $this->cabReadMvd(0, 1, 8);
                $mv1X = $px + $mvd1X; $mv1Y = $py + $mvd1Y;
                $this->cabStoreMvd(0, $mbIdx, 0, 2, 4, 2, $mvd1X, $mvd1Y);
            } else {
                [$px, $py] = $this->getP8x16MvPrediction($mbX, $mbY, 0, $ref0);
                $mvd0X = $this->cabReadMvd(0, 0, 0);
                $mvd0Y = $this->cabReadMvd(0, 1, 0);
                $mv0X = $px + $mvd0X; $mv0Y = $py + $mvd0Y;
                $this->cabStoreMvd(0, $mbIdx, 0, 0, 2, 4, $mvd0X, $mvd0Y);
                // 同 16x8：mvPart0 带上 ref0（FFmpeg mv_cache[scan8[4]-1] 的 ref = part0）
                [$px, $py] = $this->getP8x16MvPrediction($mbX, $mbY, 1, $ref1, [$mv0X, $mv0Y, $ref0]);
                $mvd1X = $this->cabReadMvd(0, 0, 2);
                $mvd1Y = $this->cabReadMvd(0, 1, 2);
                $mv1X = $px + $mvd1X; $mv1Y = $py + $mvd1Y;
                $this->cabStoreMvd(0, $mbIdx, 2, 0, 2, 4, $mvd1X, $mvd1Y);
            }
            if ($mbType === 1) {
                $this->performMotionCompensation16x8($mbX, $mbY, 0, $mv0X, $mv0Y, $ref0);
                $this->performMotionCompensation16x8($mbX, $mbY, 1, $mv1X, $mv1Y, $ref1);
                $this->saveMvForPrediction16x8($mbX, $mbY, $mv0X, $mv0Y, $ref0, $mv1X, $mv1Y, $ref1);
            } else {
                $this->performMotionCompensation8x16($mbX, $mbY, 0, $mv0X, $mv0Y, $ref0);
                $this->performMotionCompensation8x16($mbX, $mbY, 1, $mv1X, $mv1Y, $ref1);
                $this->saveMvForPrediction8x16($mbX, $mbY, $mv0X, $mv0Y, $ref0, $mv1X, $mv1Y, $ref1);
            }
            if ($this->deblockInfoEnabled) {
                $mvArr = [];
                $refArr = [];
                for ($y = 0; $y < 4; $y++) {
                    for ($x = 0; $x < 4; $x++) {
                        if ($mbType === 1) {
                            $use0 = $y < 2;
                        } else {
                            $use0 = $x < 2;
                        }
                        $mvArr[$y * 4 + $x] = $use0 ? [$mv0X, $mv0Y] : [$mv1X, $mv1Y];
                        $refArr[$y * 4 + $x] = $use0 ? $ref0 : $ref1;
                    }
                }
                $this->mbMvForDeblock[$mbIdx] = $mvArr;
                $this->mbRefForDeblock[$mbIdx] = $refArr;
            }
        } else {
            // P_8x8（CABAC mb_type 3，无 P_8x8ref0）
            $subTypes = [
                $this->cabReadPSubMbType(),
                $this->cabReadPSubMbType(),
                $this->cabReadPSubMbType(),
                $this->cabReadPSubMbType(),
            ];
            $mbMvs = array_fill(0, 4, array_fill(0, 4, [0, 0, 0]));
            $dct8Allowed = $this->transform8x8ModeFlag
                && $subTypes[0] === 0 && $subTypes[1] === 0
                && $subTypes[2] === 0 && $subTypes[3] === 0;

            // refs（list 序）；anchor = 各 8x8 象限首块的 raster 块号 [0,2,8,10]
            $refs = [0, 0, 0, 0];
            $p8RefN = [0, 2, 8, 10];
            for ($i = 0; $i < 4; $i++) {
                $refs[$i] = $this->cabReadRefIdx(0, $p8RefN[$i], false);
                // FFmpeg：每象限 ref 读后立即 fill ref_cache 2x2（后续象限 ctx 可见）
                $this->cabOvRefBox(0, ($i & 1) * 2, ($i >> 1) * 2, 2, 2, $refs[$i]);
            }
            // mvds + 预测（list 序、子块序、分区序）
            for ($i = 0; $i < 4; $i++) {
                $st = $subTypes[$i];
                $partCount = $st === 0 ? 1 : ($st === 3 ? 4 : 2);
                for ($j = 0; $j < $partCount; $j++) {
                    [$bx, $by, $cols, $rows] = $this->cabSubPartGeometry($st, $i, $j);
                    [$mvX, $mvY] = $this->predictMvP8x8(
                        $mbX, $mbY, $bx, $by, $cols, $mbMvs, $this->mvTopRow, $this->mvLeftCol, $refs[$i]
                    );
                    $n = $by * 4 + $bx;
                    $mvdX = $this->cabReadMvd(0, 0, $n);
                    $mvdY = $this->cabReadMvd(0, 1, $n);
                    $mvX += $mvdX;
                    $mvY += $mvdY;
                    for ($y = $by; $y < $by + $rows; $y++) {
                        for ($x = $bx; $x < $bx + $cols; $x++) {
                            $mbMvs[$y][$x] = [$mvX, $mvY, $refs[$i]];
                        }
                    }
                    $mcTasks[] = [$bx * 4, $by * 4, $cols * 4, $rows * 4, $mvX, $mvY, $refs[$i]];
                    $this->cabStoreMvd(0, $mbIdx, $bx, $by, $cols, $rows, $mvdX, $mvdY);
                }
            }
            $this->executeMergedMcTasks($mbX, $mbY, $mcTasks);
            $this->saveMvForPrediction8x8($mbX, $mbY, $mbMvs);
            if ($this->deblockInfoEnabled) {
                $mvArr = [];
                $refArr = [];
                for ($y = 0; $y < 4; $y++) {
                    for ($x = 0; $x < 4; $x++) {
                        $m = $mbMvs[$y][$x];
                        $mvArr[$y * 4 + $x] = [$m[0], $m[1]];
                        $refArr[$y * 4 + $x] = $m[2];
                    }
                }
                $this->mbMvForDeblock[$mbIdx] = $mvArr;
                $this->mbRefForDeblock[$mbIdx] = $refArr;
            }
        }

        // ---------- CBP / dct8 / qp ----------
        $cbpLuma = $this->cabReadCbpLuma();
        $cbpChroma = $this->cabReadCbpChroma();
        $dct8 = false;
        if ($dct8Allowed && ($cbpLuma & 15) !== 0 && $this->cabGet(399 + $this->cabNeighborTransformSize()) !== 0) {
            $dct8 = true;
        }
        $cbp = $cbpLuma | ($cbpChroma << 4);
        // FFmpeg L2345：cbp_table 在残差解码前赋值（色度 DC/亮度 DC 的 CBF 标志位在其上 OR）
        $this->picCbp[$mbIdx] = $cbp;
        if ($cbp !== 0) {
            $this->cabReadQpDelta();
        } else {
            $this->cabLastQpDiff = 0;
        }
        $this->cabDecodeInterResidual($mbX, $mbY, $cbpLuma, $cbpChroma, $dct8);
        $this->cabFinishInterMb($mbX, $mbY, $cbp, $dct8, $mbType);
    }

    /** CABAC B 片宏块 */
    private function decodeCabacBMb(int $mbX, int $mbY): void
    {
        $this->cabMbBegin($mbX, $mbY);
        $mbIdx = $this->cabMbIdx;
        $this->picChromaPred[$mbIdx] = 0;
        $this->picIsSkip[$mbIdx] = 0;
        $this->picIsDirectMb[$mbIdx] = 0;
        $dbg = &$this->dbgBStat;
        $dbg['n']++;

        // ---------- skip（B_Skip = B_Direct_16x16 无残差）----------
        if ($this->cabReadMbSkip(true)) {
            $this->picIsSkip[$mbIdx] = 1;
            $dbg['skip']++;
            $this->picIsDirectMb[$mbIdx] = 1;
            // B_Skip = B_Direct_16x16：direct_cache 全 MB direct（邻居 ref ctx 排除）
            $q0 = $mbIdx * 4;
            $this->picDirectQ[$q0] = $this->picDirectQ[$q0 + 1] = $this->picDirectQ[$q0 + 2] = $this->picDirectQ[$q0 + 3] = 1;
            $this->picCbp[$mbIdx] = 0;   // FFmpeg decode_mb_skip: h->cbp_table[mb_xy] = 0
            $this->cabLastQpDiff = 0;    // FFmpeg L1952: sl->last_qscale_diff = 0
            $this->decodeBSkip($mbX, $mbY);
            $this->cabWriteDeblock(0);
            return;
        }

        $t = $this->cabReadBMbType();

        if ($t['k'] === 'i4' || $t['k'] === 'i16' || $t['k'] === 'pcm') {
            // B 片内嵌 Intra
            $dbg['intra']++;
            $this->decodeCabacIntraMb($mbX, $mbY, $t);
            // intra 邻居：B 列表 MV/ref 缓存标记未使用
            $intraMv = [0, 0, -1];
            $this->bMvLeftCol[0] = [$intraMv, $intraMv, $intraMv, $intraMv];
            $this->bMvLeftCol[1] = [$intraMv, $intraMv, $intraMv, $intraMv];
            $base = $mbX * 4;
            for ($i = 0; $i < 4; $i++) {
                $this->bMvTopRow[0][$base + $i] = $intraMv;
                $this->bMvTopRow[1][$base + $i] = $intraMv;
            }
            return;
        }

        if ($t['k'] === 'direct') {
            // B_Direct_16x16（含残差）；direct 同属 inter（FFmpeg !IS_INTRA -> 0）
            $dbg['direct']++;
            $this->cabFixUnavailNz(false);
            $this->picIsDirectMb[$mbIdx] = 1;
            // B_Direct_16x16：direct_cache 全 MB direct（邻居 ref ctx 排除）
            $q0 = $mbIdx * 4;
            $this->picDirectQ[$q0] = $this->picDirectQ[$q0 + 1] = $this->picDirectQ[$q0 + 2] = $this->picDirectQ[$q0 + 3] = 1;
            $this->decodeBDirect16x16($mbX, $mbY);
            $dct8Allowed = $this->transform8x8ModeFlag && $this->direct8x8InferenceFlag;
            $cbpLuma = $this->cabReadCbpLuma();
            $cbpChroma = $this->cabReadCbpChroma();
            $dct8 = false;
            if ($dct8Allowed && ($cbpLuma & 15) !== 0 && $this->cabGet(399 + $this->cabNeighborTransformSize()) !== 0) {
                $dct8 = true;
            }
            $cbp = $cbpLuma | ($cbpChroma << 4);
            // FFmpeg L2345：cbp_table 在残差解码前赋值（色度 DC/亮度 DC 的 CBF 标志位在其上 OR）
            $this->picCbp[$mbIdx] = $cbp;
        // FFmpeg L2345：cbp_table 在残差解码前赋值（色度 DC/亮度 DC 的 CBF 标志位在其上 OR）
        $this->picCbp[$mbIdx] = $cbp;
            if ($cbp !== 0) {
                $this->cabReadQpDelta();
            } else {
                $this->cabLastQpDiff = 0;
            }
            $this->cabDecodeInterResidual($mbX, $mbY, $cbpLuma, $cbpChroma, $dct8);
            $this->cabFinishInterMb($mbX, $mbY, $cbp, $dct8, 0);
            return;
        }

        $mbType = $t['mbType'];
        $dbg['inter']++;
        // 显式 inter 分区（16x16/16x8/8x16/8x8）：不可用邻居 nz 哨兵转 0
        $this->cabFixUnavailNz(false);
        $dct8Allowed = $this->transform8x8ModeFlag;

        if ($mbType <= 2) {
            // B_L0/L1_16x16
            $list = $mbType === 1 ? 0 : 1;
            $refIdx = $this->cabReadRefIdx($list, 0, true);
            // FFmpeg：ref 读后立即 fill ref_cache 4x4（后续 MB 边界与本 MB mvd ctx 可见）
            $this->cabOvRefBox($list, 0, 0, 4, 4, $refIdx);
            [$px, $py] = $this->getB16x16MvPrediction($mbX, $mbY, $list, $refIdx);
            $mvdX = $this->cabReadMvd($list, 0, 0);
            $mvdY = $this->cabReadMvd($list, 1, 0);
            $mvX = $px + $mvdX;
            $mvY = $py + $mvdY;
            $gridL0 = array_fill(0, 4, array_fill(0, 4, [0, 0, -1]));
            $gridL1 = array_fill(0, 4, array_fill(0, 4, [0, 0, -1]));
            // 注意：PHP 数组为值语义（COW），不能用 $grid 别名写入——必须直接写目标 grid
            if ($list === 0) {
                for ($y = 0; $y < 4; $y++) {
                    for ($x = 0; $x < 4; $x++) {
                        $gridL0[$y][$x] = [$mvX, $mvY, $refIdx];
                    }
                }
            } else {
                for ($y = 0; $y < 4; $y++) {
                    for ($x = 0; $x < 4; $x++) {
                        $gridL1[$y][$x] = [$mvX, $mvY, $refIdx];
                    }
                }
            }
            $this->mcBBlockTo($mbX * 16, $mbY * 16, 16, 16, $mvX, $mvY, $list, $refIdx);
            $this->saveBListMvs($mbX, $gridL0, $gridL1);
            $this->storeBPicBlocks($mbX, $mbY, $gridL0, $gridL1, 0x08);
            $this->storeBDeblockInfo($mbX, $mbY, $gridL0, $gridL1);
            $this->cabStoreMvd($list, $mbIdx, 0, 0, 4, 4, $mvdX, $mvdY);
        } elseif ($mbType === 3) {
            // B_Bi_16x16
            $ref0 = $this->cabReadRefIdx(0, 0, true);
            $ref1 = $this->cabReadRefIdx(1, 0, true);
            // FFmpeg：ref 读后立即 fill ref_cache 4x4
            $this->cabOvRefBox(0, 0, 0, 4, 4, $ref0);
            $this->cabOvRefBox(1, 0, 0, 4, 4, $ref1);
            [$px0, $py0] = $this->getB16x16MvPrediction($mbX, $mbY, 0, $ref0);
            [$px1, $py1] = $this->getB16x16MvPrediction($mbX, $mbY, 1, $ref1);
            $mvd0X = $this->cabReadMvd(0, 0, 0);
            $mvd0Y = $this->cabReadMvd(0, 1, 0);
            $mvd1X = $this->cabReadMvd(1, 0, 0);
            $mvd1Y = $this->cabReadMvd(1, 1, 0);
            $mv0X = $px0 + $mvd0X; $mv0Y = $py0 + $mvd0Y;
            $mv1X = $px1 + $mvd1X; $mv1Y = $py1 + $mvd1Y;
            $gridL0 = array_fill(0, 4, array_fill(0, 4, [$mv0X, $mv0Y, $ref0]));
            $gridL1 = array_fill(0, 4, array_fill(0, 4, [$mv1X, $mv1Y, $ref1]));
            $this->performBiPredBlock($mbX * 16, $mbY * 16, 16, 16, $mv0X, $mv0Y, $ref0, $mv1X, $mv1Y, $ref1);
            $this->saveBListMvs($mbX, $gridL0, $gridL1);
            $this->storeBPicBlocks($mbX, $mbY, $gridL0, $gridL1, 0x08);
            $this->storeBDeblockInfo($mbX, $mbY, $gridL0, $gridL1);
            $this->cabStoreMvd(0, $mbIdx, 0, 0, 4, 4, $mvd0X, $mvd0Y);
            $this->cabStoreMvd(1, $mbIdx, 0, 0, 4, 4, $mvd1X, $mvd1Y);
        } elseif ($mbType <= 21) {
            // 16x8 / 8x16 双分区（mbType 4..21，镜像 decodeBBipart + CABAC 语法）
            $spec = self::$bPartSpecs[$mbType];
            $layout = $spec[0]; // 0=16x8, 1=8x16
            $ns = $layout === 0 ? [0, 8] : [0, 2]; // raster anchor（8x16 p1=(2,0)）

            // ref：list 主序，Bi(2) 分区双向都读
            $refs = [[-1, -1], [-1, -1]];
            for ($list = 0; $list < 2; $list++) {
                for ($p = 0; $p < 2; $p++) {
                    $dir = $spec[1 + $p];
                    if ($dir === $list || $dir === 2) {
                        $refs[$list][$p] = $this->cabReadRefIdx($list, $ns[$p], true);
                        // FFmpeg：每分区 ref 读后立即 fill ref_cache（part1 的 ref ctx 可见 part0）
                        if ($layout === 0) {
                            $this->cabOvRefBox($list, 0, $p * 2, 4, 2, $refs[$list][$p]);
                        } else {
                            $this->cabOvRefBox($list, $p * 2, 0, 2, 4, $refs[$list][$p]);
                        }
                    }
                }
            }
            // mvd：list 主序，逐分区 pred + mvd
            $mvs = [[null, null], [null, null]];
            for ($list = 0; $list < 2; $list++) {
                for ($p = 0; $p < 2; $p++) {
                    if ($refs[$list][$p] < 0) {
                        continue;
                    }
                    if ($layout === 0) {
                        [$px, $py] = $this->getB16x8MvPrediction($mbX, $mbY, $p, $list, $refs[$list][$p], $mvs[$list][0]);
                    } else {
                        [$px, $py] = $this->getB8x16MvPrediction($mbX, $mbY, $p, $list, $refs[$list][$p], $mvs[$list][0]);
                    }
                    $mvdX = $this->cabReadMvd($list, 0, $ns[$p]);
                    $mvdY = $this->cabReadMvd($list, 1, $ns[$p]);
                    // FFmpeg fill_rectangle 回填 mv_cache 时 ref 一并写入，part1 的
                    // B/A 邻居（mv_cache[20]/[13]）能读到 part0 的 ref；须带 ref 分量
                    $mvs[$list][$p] = [$px + $mvdX, $py + $mvdY, $refs[$list][$p]];
                    $bx0 = ($layout === 0 ? 0 : $p * 2) + ($ns[$p] & 3);
                    $by0 = ($layout === 0 ? $p * 2 : 0) + ($ns[$p] >> 2);
                    $bw = $layout === 0 ? 4 : 2;
                    $bh = $layout === 0 ? 2 : 4;
                    $storeBx = $layout === 0 ? 0 : $p * 2;
                    $storeBy = $layout === 0 ? $p * 2 : 0;
                    $this->cabStoreMvd($list, $mbIdx, $storeBx, $storeBy, $bw, $bh, $mvdX, $mvdY);
                }
            }
            // MC + 网格
            $gridL0 = array_fill(0, 4, array_fill(0, 4, [0, 0, -1]));
            $gridL1 = array_fill(0, 4, array_fill(0, 4, [0, 0, -1]));
            for ($p = 0; $p < 2; $p++) {
                $dir = $spec[1 + $p];
                if ($layout === 0) {
                    $bx0 = 0; $by0 = $p * 2; $cols = 4; $rows = 2;
                } else {
                    $bx0 = $p * 2; $by0 = 0; $cols = 2; $rows = 4;
                }
                $mv0 = ($dir === 0 || $dir === 2) ? $mvs[0][$p] : null;
                $mv1 = ($dir === 1 || $dir === 2) ? $mvs[1][$p] : null;
                $dstX = $mbX * 16 + $bx0 * 4;
                $dstY = $mbY * 16 + $by0 * 4;
                $w = $cols * 4;
                $h = $rows * 4;
                if ($mv0 !== null && $mv1 !== null) {
                    $this->performBiPredBlock($dstX, $dstY, $w, $h, $mv0[0], $mv0[1], $refs[0][$p], $mv1[0], $mv1[1], $refs[1][$p]);
                } elseif ($mv0 !== null) {
                    $this->mcBBlockTo($dstX, $dstY, $w, $h, $mv0[0], $mv0[1], 0, $refs[0][$p]);
                } elseif ($mv1 !== null) {
                    $this->mcBBlockTo($dstX, $dstY, $w, $h, $mv1[0], $mv1[1], 1, $refs[1][$p]);
                }
                for ($y = $by0; $y < $by0 + $rows; $y++) {
                    for ($x = $bx0; $x < $bx0 + $cols; $x++) {
                        $gridL0[$y][$x] = $mv0 !== null ? [$mv0[0], $mv0[1], $refs[0][$p]] : [0, 0, -1];
                        $gridL1[$y][$x] = $mv1 !== null ? [$mv1[0], $mv1[1], $refs[1][$p]] : [0, 0, -1];
                    }
                }
            }
            $this->saveBListMvs($mbX, $gridL0, $gridL1);
            $this->storeBPicBlocks($mbX, $mbY, $gridL0, $gridL1, $layout === 0 ? 0x10 : 0x20);
            $this->storeBDeblockInfo($mbX, $mbY, $gridL0, $gridL1);
        } else {
            // B_8x8（mbType 22）
            $dct8Allowed = $dct8Allowed && $this->decodeCabacB8x8($mbX, $mbY, $mbIdx);
            $cbpLuma = $this->cabReadCbpLuma();
            $cbpChroma = $this->cabReadCbpChroma();
            $dct8 = false;
            if ($dct8Allowed && ($cbpLuma & 15) !== 0 && $this->cabGet(399 + $this->cabNeighborTransformSize()) !== 0) {
                $dct8 = true;
            }
            $cbp = $cbpLuma | ($cbpChroma << 4);
            // FFmpeg L2345：cbp_table 在残差解码前赋值（色度 DC/亮度 DC 的 CBF 标志位在其上 OR）
            $this->picCbp[$mbIdx] = $cbp;
        // FFmpeg L2345：cbp_table 在残差解码前赋值（色度 DC/亮度 DC 的 CBF 标志位在其上 OR）
        $this->picCbp[$mbIdx] = $cbp;
            if ($cbp !== 0) {
                $this->cabReadQpDelta();
            } else {
                $this->cabLastQpDiff = 0;
            }
            $this->cabDecodeInterResidual($mbX, $mbY, $cbpLuma, $cbpChroma, $dct8);
            $this->cabFinishInterMb($mbX, $mbY, $cbp, $dct8, $mbType);
            return;
        }

        // ---------- 16x16/16x8/8x16 类型的 CBP/qp/残差/簿记 ----------
        $cbpLuma = $this->cabReadCbpLuma();
        $cbpChroma = $this->cabReadCbpChroma();
        $dct8 = false;
        if ($dct8Allowed && ($cbpLuma & 15) !== 0 && $this->cabGet(399 + $this->cabNeighborTransformSize()) !== 0) {
            $dct8 = true;
        }
        $cbp = $cbpLuma | ($cbpChroma << 4);
        // FFmpeg L2345：cbp_table 在残差解码前赋值（色度 DC/亮度 DC 的 CBF 标志位在其上 OR）
        $this->picCbp[$mbIdx] = $cbp;
        if ($cbp !== 0) {
            $this->cabReadQpDelta();
        } else {
            $this->cabLastQpDiff = 0;
        }
        $this->cabDecodeInterResidual($mbX, $mbY, $cbpLuma, $cbpChroma, $dct8);
        $this->cabFinishInterMb($mbX, $mbY, $cbp, $dct8, $mbType);
    }

    /**
     * B mb_type 是否使用某 list（CABAC 可达类型 4..21）
     * 4,5 L0_L0；6,7 L1_L1；8..21 双 list
     */
    private function bMbUsesList(int $mbType, int $list): bool
    {
        if ($mbType <= 5) {
            return $list === 0;
        }
        if ($mbType <= 7) {
            return $list === 1;
        }
        return true;
    }

    /**
     * B_8x8 宏块（CABAC，镜像 CAVLC decodeB8x8：sub types → direct 预测 → refs → mvd → 统一 MC）。
     * @return bool 8x8DCT 是否允许
     */
    private function decodeCabacB8x8(int $mbX, int $mbY, int $mbIdx): bool
    {
        $subTypes = [
            $this->cabReadBSubMbType(),
            $this->cabReadBSubMbType(),
            $this->cabReadBSubMbType(),
            $this->cabReadBSubMbType(),
        ];
        $hasDirect = $subTypes[0] === 0 || $subTypes[1] === 0
            || $subTypes[2] === 0 || $subTypes[3] === 0;

        // 8x8DCT：仅当所有子分区为 8x8 级（≤3），且 direct 子块需要 direct_8x8_inference
        $dct8Allowed = $this->transform8x8ModeFlag
            && (!$hasDirect || $this->direct8x8InferenceFlag)
            && $subTypes[0] <= 3 && $subTypes[1] <= 3
            && $subTypes[2] <= 3 && $subTypes[3] <= 3;

        $mbMvsL0 = array_fill(0, 4, array_fill(0, 4, null));
        $mbMvsL1 = array_fill(0, 4, array_fill(0, 4, null));
        $gridL0 = array_fill(0, 4, array_fill(0, 4, [0, 0, -1]));
        $gridL1 = array_fill(0, 4, array_fill(0, 4, [0, 0, -1]));

        // FFmpeg L2130：direct_cache 按 8x8 象限 fill（direct 象限标记，B 片 ref ctx 排除 +
        // 写回 direct_table 供后续 MB 边界 ctx 使用）
        for ($i = 0; $i < 4; $i++) {
            $this->picDirectQ[$mbIdx * 4 + $i] = ($subTypes[$i] === 0) ? 1 : 0;
        }

        // direct 子块：空间/时间 direct 预测（无语法），并同步进 mbMvs 供后续预测
        if ($hasDirect) {
            if (!$this->directSpatialMvPred) {
                for ($i = 0; $i < 4; $i++) {
                    if ($subTypes[$i] === 0) {
                        $this->applyBDirect8x8Temporal($mbX, $mbY, $i, $gridL0, $gridL1);
                    }
                }
            } else {
                $neighborPred = $this->getBDirectNeighborPred($mbX, $mbY);
                for ($i = 0; $i < 4; $i++) {
                    if ($subTypes[$i] === 0) {
                        $this->applyBDirect8x8($mbX, $mbY, $i, $neighborPred, $gridL0, $gridL1);
                    }
                }
            }
            for ($i = 0; $i < 4; $i++) {
                if ($subTypes[$i] !== 0) {
                    continue;
                }
                $partX = ($i & 1) * 2;
                $partY = ($i >> 1) * 2;
                // FFmpeg pred_direct_motion：direct 象限 mv_cache 双 list 无条件 2x2 fill
                //（后续非 direct 象限 pred_motion 的 A/B 邻居可读到 direct MV/ref）
                for ($y = $partY; $y < $partY + 2; $y++) {
                    for ($x = $partX; $x < $partX + 2; $x++) {
                        $mbMvsL0[$y][$x] = $gridL0[$y][$x];
                        $mbMvsL1[$y][$x] = $gridL1[$y][$x];
                        $this->cabMbDirOv[$y * 4 + $x] = 1;
                    }
                }
            }
        }

        // ref：list 主序，跳过 direct，未使用该 list 的子块填 -1
        $refs = [[-1, -1, -1, -1], [-1, -1, -1, -1]];
        $b8RefN = [0, 2, 8, 10]; // 各 8x8 象限首块 raster 块号
        for ($list = 0; $list < 2; $list++) {
            for ($i = 0; $i < 4; $i++) {
                $st = $subTypes[$i];
                if ($st === 0 || $st < 0 || $st >= 13) {
                    continue;
                }
                if (($list === 0 && self::$bSubUsesL0[$st]) || ($list === 1 && self::$bSubUsesL1[$st])) {
                    $refs[$list][$i] = $this->cabReadRefIdx($list, $b8RefN[$i], true);
                    // FFmpeg L2156-2157：每象限 ref 读后立即 fill ref_cache 2x2（后续象限 ctx 可见）
                    $this->cabOvRefBox($list, ($i & 1) * 2, ($i >> 1) * 2, 2, 2, $refs[$list][$i]);
                }
            }
        }

        // mvd：list 主序，逐子块逐分区 pred + mvd
        for ($list = 0; $list < 2; $list++) {
            // 注意：必须引用绑定（PHP 数组值语义/COW，值拷贝写入不会传回 $mbMvsL0/L1，
            // 导致跨象限 MV 预测邻居与网格回填全部丢失非 direct MV）
            if ($list === 0) {
                $mbMvs = &$mbMvsL0;
            } else {
                $mbMvs = &$mbMvsL1;
            }
            for ($i = 0; $i < 4; $i++) {
                $st = $subTypes[$i];
                if ($st === 0 || $st < 0 || $st >= 13) {
                    continue;   // direct 象限已由 direct fill 无条件写入
                }
                $partX = ($i & 1) * 2;
                $partY = ($i >> 1) * 2;
                if ($refs[$list][$i] < 0) {
                    // FFmpeg L2214-2217：未用该 list 的非 direct 象限 mv_cache 填 0（ref=-1）
                    //——后续象限 pred_motion 将其视为"可用但不匹配"而非不可用
                    for ($y = $partY; $y < $partY + 2; $y++) {
                        for ($x = $partX; $x < $partX + 2; $x++) {
                            $mbMvs[$y][$x] = [0, 0, -1];
                        }
                    }
                    continue;
                }
                $partCount = self::$bSubPartCount[$st];
                $refIdx = $refs[$list][$i];
                for ($j = 0; $j < $partCount; $j++) {
                    [$bx, $by, $cols, $rows] = $this->cabSubPartGeometry($st, $i, $j, true);
                    [$px, $py] = $this->predictMvP8x8(
                        $mbX, $mbY, $bx, $by, $cols,
                        $mbMvs, $this->bMvTopRow[$list], $this->bMvLeftCol[$list], $refIdx
                    );
                    $n = $by * 4 + $bx;
                    $mvdX = $this->cabReadMvd($list, 0, $n);
                    $mvdY = $this->cabReadMvd($list, 1, $n);
                    $mv = [$px + $mvdX, $py + $mvdY, $refIdx];
                    for ($yy = 0; $yy < $rows; $yy++) {
                        for ($xx = 0; $xx < $cols; $xx++) {
                            $mbMvs[$by + $yy][$bx + $xx] = $mv;
                        }
                    }
                    $this->cabStoreMvd($list, $mbIdx, $bx, $by, $cols, $rows, $mvdX, $mvdY);
                }
            }
        }
        unset($mbMvs);

        // 网格回填（direct 子块已在 grid 中，非 direct 从 mbMvs 回填）
        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                if ($mbMvsL0[$y][$x] !== null) {
                    $gridL0[$y][$x] = $mbMvsL0[$y][$x];
                }
                if ($mbMvsL1[$y][$x] !== null) {
                    $gridL1[$y][$x] = $mbMvsL1[$y][$x];
                }
            }
        }

        // 运动补偿（双 list MV 均已就绪，按分区统一处理）
        for ($i = 0; $i < 4; $i++) {
            $st = $subTypes[$i];
            if ($st < 0 || $st >= 13) {
                continue;
            }
            $partCount = self::$bSubPartCount[$st];
            for ($j = 0; $j < $partCount; $j++) {
                [$bx, $by, $cols, $rows] = $this->cabSubPartGeometry($st, $i, $j, true);
                $l0 = $mbMvsL0[$by][$bx] ?? $gridL0[$by][$bx];
                $l1 = $mbMvsL1[$by][$bx] ?? $gridL1[$by][$bx];
                $dstX = $mbX * 16 + $bx * 4;
                $dstY = $mbY * 16 + $by * 4;
                $w = $cols * 4;
                $h = $rows * 4;
                if ($l0[2] >= 0 && $l1[2] >= 0) {
                    $this->performBiPredBlock($dstX, $dstY, $w, $h, $l0[0], $l0[1], $l0[2], $l1[0], $l1[1], $l1[2]);
                } elseif ($l0[2] >= 0) {
                    $this->mcBBlockTo($dstX, $dstY, $w, $h, $l0[0], $l0[1], 0, $l0[2]);
                } elseif ($l1[2] >= 0) {
                    $this->mcBBlockTo($dstX, $dstY, $w, $h, $l1[0], $l1[1], 1, $l1[2]);
                }
            }
        }

        $this->saveBListMvs($mbX, $gridL0, $gridL1);
        $this->storeBPicBlocks($mbX, $mbY, $gridL0, $gridL1, 0x40);
        $this->storeBDeblockInfo($mbX, $mbY, $gridL0, $gridL1);
        return $dct8Allowed;
    }
}
