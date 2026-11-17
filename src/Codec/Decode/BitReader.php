<?php

// 命名空间：H.264 解码器所在目录
namespace Xiaosongshu\Flv2mp4\Codec\Decode;

/**
 * @purpose bit读取器
 * @author yanglong
 * @time 2026年7月23日14:37:33
 *
 * 按位读取 H.264 码流的工具类（H.264 语法元素以 bit 为单位，见规范 7.2 语法表）。
 * 主要用途：
 *  1) 读取 u(n)/u(v) 定长无符号整数（如 forbidden_zero_bit、nal_unit_type 等）；
 *  2) 读取 ue(v)/se(v)/te(v) 指数哥伦布变长码（见规范 9.1.1、9.1.2）；
 *  3) 提供 CAVLC 的 level_prefix 快速扫描（见规范 9.2.2.1）；
 *  4) 提供按字节对齐（如 rbsp_trailing_bits 前对齐，见规范 7.3.2.11）。
 * 内部以「bit 位置 pos」为唯一游标，所有读取都从 pos 开始并按需推进。
 */
class BitReader
{
    // 待读取的原始字节串（通常是去掉防竞争字节后的 RBSP，见规范 7.3.1、7.4.1）
    private string $data;
    // 码流总位数：字节数 × 8
    private int $bitLength;
    // 码流总字节数（用于 level_prefix 扫描时做边界判断）
    private int $byteLen;
    // 当前读指针（单位：bit，从 0 开始，指向下一个尚未读取的 bit）
    private int $pos;

    /**
     * 构造函数：保存字节串并初始化各个位置/长度字段。
     * 注意：入参被视为已经去掉 emulation_prevention_three_byte 的 RBSP，
     * 因此这里的 bit 位置可直接对应语法元素位置（见规范 7.3.1 与 7.4.1）。
     *
     * @param string $data 原始字节串（RBSP 内容）
     */
    public function __construct(string $data)
    {
        // 保存原始数据，后续所有读取都从该字符串中按位取值
        $this->data = $data;
        // 总位数 = 字节数 × 8，作为越界判断的硬上界
        $this->bitLength = strlen($data) * 8;
        // 记录字节长度，level_prefix 的整字节扫描需要用到
        $this->byteLen = strlen($data);
        // 读指针初始化为 0，即从第一个字节的最高位（MSB）开始读
        $this->pos = 0;
    }

    /**
     * 跳过 n 个 bit（用于跳过不关心的语法元素，例如填充位）。
     * 位置会被夹紧到 [0, bitLength]，避免越界。
     *
     * @param int $n 要跳过的位数
     */
    public function skip(int $n): void
    {
        // 先加 n，再限制在 [0, bitLength] 区间内
        $this->pos = max(0, min($this->bitLength, $this->pos + $n));
    }

    /**
     * 读取 n 位无符号定长整数，等价于语法描述符 u(n)（见规范 7.2 表 7-1）。
     * 若可读位数不足 n，则低位补 0（与部分参考实现的容错行为一致），
     * 并把读指针推到码流末尾。
     *
     * @param int $n 要读取的位数（0~32）
     * @return int 读到的无符号整数值
     */
    public function readU(int $n): int
    {
        // 读 0 位没有意义，直接返回 0（也避免后续移位出现负数）
        if ($n === 0) {
            return 0;
        }

        // 取当前位位置到局部变量，减少属性访问开销
        $pos = $this->pos;
        // 热路径：码流内全部语法元素宽度 ≤16bit，最多跨 3 个字节，直接拼窗口取值，
        // 避免通用 while/min 循环开销（基线 90 帧约 140 万次调用）
        // 快速路径条件：位数不大（≤16）且剩余码流足够
        if ($n <= 16 && $pos + $n <= $this->bitLength) {
            // 起始字节下标：bit 位置除以 8
            $bytePos = $pos >> 3;
            // 起始字节内的 bit 偏移（0 表示字节最高位）
            $bitOff = $pos & 7;
            // 从起始偏移到读取末尾跨过的位数
            $span = $bitOff + $n;
            // 先推进读指针（快速路径只算一次）
            $this->pos = $pos + $n;
            // 情况 1：只落在当前字节内
            if ($span <= 8) {
                // 右移去掉字节尾部无用位，再与 n 位掩码相与取出目标位
                return (ord($this->data[$bytePos]) >> (8 - $span)) & ((1 << $n) - 1);
            }
            // 情况 2：跨两个字节（拼成 16bit 窗口后右移对齐）
            if ($span <= 16) {
                // 大端拼接：前一字节在高 8 位，后一字节在低 8 位
                $w = (ord($this->data[$bytePos]) << 8) | ord($this->data[$bytePos + 1]);
                // 右移到目标位的最低端，再取低 n 位
                return ($w >> (16 - $span)) & ((1 << $n) - 1);
            }
            // 情况 3：跨三个字节（拼成 24bit 窗口后右移对齐）
            $w = (ord($this->data[$bytePos]) << 16) | (ord($this->data[$bytePos + 1]) << 8) | ord($this->data[$bytePos + 2]);
            // 右移到目标位的最低端，再取低 n 位
            return ($w >> (24 - $span)) & ((1 << $n) - 1);
        }

        // 通用慢路径：可读位数不足 n 时只读剩余位（available 为实际可读位数）
        $available = min($n, $this->bitLength - $pos);
        // 剩余待读取位数
        $remaining = $available;
        // 累积结果
        $value = 0;

        // 按「当前字节剩余位」分段读取，直到凑够 available 位
        while ($remaining > 0) {
            // 当前位在字节内的偏移
            $bitOffset = $pos & 7;
            // 本次最多能取多少位：不能跨过字节边界
            $take = min($remaining, 8 - $bitOffset);
            // 当前字节值
            $byte = ord($this->data[$pos >> 3]);
            // 把目标位对齐到可提取的位置
            $shift = 8 - $bitOffset - $take;
            // 结果左移 take 位后，把本次取到的 take 位拼到最低端
            $value = ($value << $take) | (($byte >> $shift) & ((1 << $take) - 1));
            // 推进局部指针与剩余位计数
            $pos += $take;
            $remaining -= $take;
        }

        // 回写读指针
        $this->pos = $pos;
        // 码流不足 n 位：指针置到末尾，并在低位补 0（左移到应有的高位）
        if ($available < $n) {
            $this->pos = $this->bitLength;
            $value <<= $n - $available;
        }
        // 返回读到的值
        return $value;
    }

    /**
     * CAVLC level_prefix 快速读取：返回连续 0 的个数（终止位 1 也一并消费）。
     * 热路径约 50 万次/帧，旧实现逐位 readU(1) 方法调用开销大，
     * 这里直接按字节窗口 + 256 项前导零查表，最多扫描 4 个字节。
     *
     * 对应规范 9.2.2.1 中 level_prefix 的读取：
     * level_prefix 是「若干个 0 后跟一个 1」的前缀，返回其前导零个数。
     * 若前导零达到 max 仍未遇到 1，则按 max 处理（容错，避免死循环）。
     *
     * @param int $max 允许的最大前导零个数（默认 32）
     * @return int 前导零个数（即 level_prefix 的值）
     */
    public function readLevelPrefix(int $max = 32): int
    {
        // 静态查表：clz8[v] = 字节 v 的前导零个数（v 取 1~255）
        static $clz8 = null;
        // 首次调用时构建查表
        if ($clz8 === null) {
            // 先全部填 0，v=0 的项不使用
            $clz8 = array_fill(0, 256, 0);
            // 枚举 1~255，计算每个值最高位 1 之前有多少个 0
            for ($v = 1; $v < 256; $v++) {
                $x = $v;
                $n = 0;
                // 只要最高位不是 1，就左移一位并计数
                while (($x & 0x80) === 0) {
                    $n++;
                    $x <<= 1;
                }
                // 记录该字节的前导零个数
                $clz8[$v] = $n;
            }
        }

        // 取当前位位置
        $pos = $this->pos;
        // 已到末尾：直接返回 max，表示未找到终止位
        if ($pos >= $this->bitLength) {
            return $max;
        }

        // 当前字节下标与字节内 bit 偏移
        $bytePos = $pos >> 3;
        $bitOff = $pos & 7;

        // 当前字节的剩余有效位顶对齐后查表
        // 左移 bitOff 把有效位对齐到字节最高位，并截断为 8bit
        $b = (ord($this->data[$bytePos]) << $bitOff) & 0xFF;
        // 若本字节剩余位中存在 1，则可直接查表得到前导零个数
        if ($b !== 0) {
            // 查表得到前导零个数
            $clz = $clz8[$b];
            // 跳过 clz 个 0 以及随后那个终止位 1
            $this->pos = $pos + $clz + 1;
            // 返回 level_prefix
            return $clz;
        }

        // 本字节剩余位全为 0：先把这部分长度计入 prefix，再继续看后续整字节
        $prefix = 8 - $bitOff;
        // 移到下一个字节
        $bytePos++;

        // 逐字节扫描：保证 prefix + 8 不超过 max，且不越界
        while ($prefix + 8 <= $max && $bytePos < $this->byteLen) {
            // 取整字节
            $b = ord($this->data[$bytePos]);
            // 找到非零字节：其前导零个数即为本字节贡献
            if ($b !== 0) {
                // 查表得到本字节前导零个数
                $clz = $clz8[$b];
                // 若累计前导零达到/超过 max，则按 max 截断消费
                if ($prefix + $clz >= $max) {
                    $this->pos = $pos + $max;
                    return $max;
                }
                // 正常情况：跳过累计前缀与终止位
                $this->pos = $pos + $prefix + $clz + 1;
                // 返回累计前导零个数
                return $prefix + $clz;
            }
            // 整字节为 0：累计 8 个前导零并继续
            $prefix += 8;
            $bytePos++;
        }

        // max 边界处不足一个整字节的剩余位
        if ($prefix < $max && $bytePos < $this->byteLen) {
            // 还需要检查的位数
            $rem = $max - $prefix;
            // 取该字节高 rem 位（右移去掉低位）
            $b = ord($this->data[$bytePos]) >> (8 - $rem);
            // 若这 rem 位中有 1，则终止位落在其中
            if ($b !== 0) {
                // 查表前需把值重新左对齐到 8bit
                $clz = $clz8[$b << (8 - $rem)];
                // 跳过累计前缀与终止位
                $this->pos = $pos + $prefix + $clz + 1;
                // 返回累计前导零个数
                return $prefix + $clz;
            }
        }

        // 到达 max 仍未遇到 1：消费 max 位并按 max 返回
        $this->pos = min($pos + $max, $this->bitLength);
        return $max;
    }

    /**
     * 读取无符号指数哥伦布码 ue(v)（见规范 9.1.1）：
     * 先数前导零 leadingZeros，再读 leadingZeros 位后缀，
     * 码字值 = 2^leadingZeros - 1 + suffix。
     *
     * @return int 解码出的无符号值
     */
    public function readUe(): int
    {
        // 记录起始位置，便于计算前导零个数
        $start = $this->pos;
        // 扫描指针从起始位置开始
        $pos = $start;
        // 逐位寻找第一个 1（终止位）
        while ($pos < $this->bitLength) {
            // 取当前字节
            $byte = ord($this->data[$pos >> 3]);
            // 取出当前 bit（字节内从高到低编号）
            if ((($byte >> (7 - ($pos & 7))) & 1) !== 0) {
                // 找到 1，结束扫描
                break;
            }
            // 该位是 0，继续后移
            $pos++;
        }

        // 扫到末尾仍未找到 1：视为非法/截断码，指针置末尾并返回 0
        if ($pos >= $this->bitLength) {
            $this->pos = $this->bitLength;
            return 0;
        }

        // 前导零个数即为码字的信息量
        $leadingZeros = $pos - $start;
        // 检查「前导零 + 终止位 1 + 后缀」是否越界
        if ($start + $leadingZeros + 1 + $leadingZeros > $this->bitLength) {
            $this->pos = $this->bitLength;
            return 0;
        }

        // 跳过终止位 1
        $this->pos = $pos + 1;
        // 读取后缀（leadingZeros 位）
        $value = $this->readU($leadingZeros);
        // 按 ue(v) 公式还原：2^leadingZeros - 1 + suffix
        return (1 << $leadingZeros) + $value - 1;
    }

    /**
     * 读取有符号指数哥伦布码 se(v)（见规范 9.1.1）：
     * 先按 ue(v) 读出 k，再按 k 的奇偶映射到有符号数：
     * 偶数 k → -k/2，奇数 k → (k+1)/2。
     *
     * @return int 解码出的有符号值
     */
    public function readSe(): int
    {
        // 先读无符号码字
        $ue = $this->readUe();
        // 偶数映射为非正数（0, -1, -2, ...）
        if ($ue % 2 === 0) {
            return -(int)($ue / 2);
        } else {
            // 奇数映射为正数（1, 2, 3, ...）
            return (int)(($ue + 1) / 2);
        }
    }

    /**
     * 读取截断指数哥伦布码 te(v)（见规范 9.1.2）：
     * range=1 时恒为 0；range=2 时读 1 位并取反（即 0→1、1→0）；
     * 其他情况退化为 ue(v)。
     *
     * @param int $range 语法元素的取值范围上界（xRange）
     * @return int 解码出的值
     */
    public function readTe(int $range): int
    {
        // range=1：取值只能是 0，无需读位
        if ($range === 1) {
            return 0;
        }
        // range=2：codeNum = 1 - read_bits(1)，即读 1 位后取反
        if ($range === 2) {
            $bit = $this->readU(1);
            return $bit ^ 1;
        }
        // 其他情况按 ue(v) 解析
        return $this->readUe();
    }

    /**
     * 获取当前读指针（bit 位置）。
     *
     * @return int 当前 bit 位置
     */
    public function getPos(): int
    {
        return $this->pos;
    }

    /**
     * 获取当前读指针（bit 位置），语义与 getPos 相同，
     * 供习惯 bit 位置命名的调用方使用。
     *
     * @return int 当前 bit 位置
     */
    public function getBitPosition(): int
    {
        return $this->pos;
    }

    /**
     * 获取剩余可读位数（已到末尾时为 0）。
     *
     * @return int 剩余 bit 数
     */
    public function getRemainingBits(): int
    {
        return max(0, $this->bitLength - $this->pos);
    }

    /**
     * 将读指针对齐到下一个字节边界（丢弃当前字节剩余的填充位）。
     * 典型用途：rbsp_trailing_bits 之后的字节对齐处理（见规范 7.3.2.11）。
     */
    public function alignToByte(): void
    {
        // 当前字节内已读的位数
        $rem = $this->pos % 8;
        // 未对齐则补齐到下一个字节边界
        if ($rem !== 0) {
            $this->pos += 8 - $rem;
        }
        // 对齐后仍不能越过码流末尾
        $this->pos = min($this->pos, $this->bitLength);
    }

    /**
     * 读取一个字节（先对齐到字节边界，再读 8 位）。
     *
     * @return int 读到的字节值（0~255）
     */
    public function readByte(): int
    {
        // 读取前先对齐，保证读取的是一个完整字节
        $this->alignToByte();
        // 按 8 位无符号整数读取
        return $this->readU(8);
    }

    /**
     * 预读 n 位而不推进读指针（用于「先看后决定」的解析场景）。
     * 内部走与 readU 相同的 1/2/3 字节窗口快速路径；不足时低位补 0。
     *
     * @param int $n 要预读的位数
     * @return int 预读到的值
     */
    public function peek(int $n): int
    {
        // 预读 0 位直接返回 0
        if ($n === 0) {
            return 0;
        }

        // 当前位位置
        $pos = $this->pos;
        // 与 readU 相同的 1/2/3 字节窗口快速路径（不推进位置）
        if ($n <= 16 && $pos + $n <= $this->bitLength) {
            // 起始字节下标与字节内 bit 偏移
            $bytePos = $pos >> 3;
            $bitOff = $pos & 7;
            // 跨越的总位数
            $span = $bitOff + $n;
            // 只落在当前字节内
            if ($span <= 8) {
                return (ord($this->data[$bytePos]) >> (8 - $span)) & ((1 << $n) - 1);
            }
            // 跨两个字节：拼 16bit 窗口后右移对齐取低 n 位
            if ($span <= 16) {
                $w = (ord($this->data[$bytePos]) << 8) | ord($this->data[$bytePos + 1]);
                return ($w >> (16 - $span)) & ((1 << $n) - 1);
            }
            // 跨三个字节：拼 24bit 窗口后右移对齐取低 n 位
            $w = (ord($this->data[$bytePos]) << 16) | (ord($this->data[$bytePos + 1]) << 8) | ord($this->data[$bytePos + 2]);
            return ($w >> (24 - $span)) & ((1 << $n) - 1);
        }

        // 慢路径：计算实际可读位数（不足 n 时低位补 0）
        $available = min($n, $this->bitLength - $pos);
        // 剩余待读位数
        $remaining = $available;
        // 累积结果（注意：全程使用局部 $pos，不回写属性）
        $value = 0;

        // 按字节边界分段累加
        while ($remaining > 0) {
            // 当前字节内偏移
            $bitOffset = $pos & 7;
            // 本次可取的位数
            $take = min($remaining, 8 - $bitOffset);
            // 当前字节值
            $byte = ord($this->data[$pos >> 3]);
            // 对齐移位量
            $shift = 8 - $bitOffset - $take;
            // 拼接到结果低位
            $value = ($value << $take) | (($byte >> $shift) & ((1 << $take) - 1));
            // 局部指针前进
            $pos += $take;
            // 剩余位数减少
            $remaining -= $take;
        }

        // 不足 n 位时低位补 0，保证返回值位宽一致
        if ($available < $n) {
            $value <<= $n - $available;
        }
        // 返回预读值（读指针保持不变）
        return $value;
    }
}
