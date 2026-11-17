<?php

namespace Xiaosongshu\Flv2mp4\Codec\Encode;

/**
 * @purpose bite写入器
 * @author yanglong
 *
 * 比特流构造工具：把整数编码为 H.264 语法元素所需的「位串」，
 * 再把位串拼装成字节串、最后封装成 NAL 单元。
 * 相关规范条款：
 * - 9.1    u(n)：无符号定长整数编码
 * - 9.1.1  ue(v) / se(v)：指数哥伦布编码
 * - 7.3.1  NAL 单元语法（forbidden_zero_bit / nal_ref_idc / nal_unit_type）
 * - 7.4.1  字节流格式与防竞争字节 emulation_prevention_three_byte
 */
trait BitstreamTrait
{
    /**
     * ue(v)：无符号指数哥伦布编码（规范 9.1.1）。
     * 编码结构为 [prefixZeros][1][infoBits]，codeNum = v 时：
     *   codeNum = 2^leadingZeros - 1 + infoBits
     * 因此直接编码 v+1 的二进制串，再在其前面补 (位长-1) 个 0 即可。
     *
     * @param int $v 待编码的无符号值（例如 first_mb_in_slice、mb_type 的增量部分等）
     * @return string 由 '0'/'1' 字符组成的位串
     */
    public function ue(int $v): string
    {
        // 缓存命中：小值在编码过程中会被反复使用（如 mvd、run_before），命中直接返回位串
        if (isset(self::$ueCache[$v])) {
            return self::$ueCache[$v];
        }
        // v+1 的二进制表示即为 [1][infoBits]，其长度决定前缀零的个数
        $bin = decbin($v + 1);
        // 前缀零个数 = 二进制长度 - 1（对应规范中 leadingZeros 的部分）
        $zeros = strlen($bin) - 1;
        // 拼接得到完整的 ue(v) 码字
        $result = str_repeat('0', $zeros) . $bin;
        // 只为较小的值建立缓存，避免缓存无限膨胀
        if ($v < 100) {
            self::$ueCache[$v] = $result;
        }
        return $result;
    }

    /**
     * se(v)：有符号指数哥伦布编码（规范 9.1.1）。
     * 映射关系：v>0 -> codeNum = 2v-1；v<=0 -> codeNum = -2v，
     * 再对 codeNum 调用 ue()，从而把正负号交织到自然数轴上。
     *
     * @param int $v 待编码的有符号值（如 mvd_l0、delta_qp 等）
     * @return string 位串
     */
    public function se(int $v): string
    {
        // 规范 9.1.1 的映射：0 -> 0，-1 -> 1，1 -> 2，-2 -> 3，2 -> 4 ...
        if ($v <= 0) {
            return $this->ue(-$v * 2);
        } else {
            return $this->ue($v * 2 - 1);
        }
    }

    /**
     * u(n)：n 位无符号定长整数编码（规范 9.1）。
     * 若数值位长不足 n 则在左侧补 0 至 n 位。
     *
     * @param int $v 待编码的无符号值
     * @param int $n 位宽（如 u(16) 的 pic_width_in_mbs_minus1 相关语法）
     * @return string 恰好 n 个字符的位串
     */
    public function u(int $v, int $n): string
    {
        // 左补 0 到固定位宽，注意调用方需保证 v 不超过 n 位能表示的范围
        return str_pad(decbin($v), $n, '0', STR_PAD_LEFT);
    }

    /**
     * 把 '0'/'1' 位串按每 8 位打包成字节串。
     * 若末尾不足 8 位，则在右侧补 0（等价于 rbsp_trailing_bits 中的 alignment_zero_bit，
     * 规范 7.3.2.11 的 rbsp_slice_trailing_bits / 7.4.1 的字节对齐要求）。
     *
     * @param string $bits 由 '0'/'1' 组成的位串
     * @return string 二进制字节串
     */
    public function bitsToBytes(string $bits): string
    {
        $bytes = '';
        $len = strlen($bits);
        // 每次取 8 位处理，i 为位偏移
        for ($i = 0; $i < $len; $i += 8) {
            $chunk = substr($bits, $i, 8);
            // 末段不足 8 位时在右侧补 0，完成字节对齐
            if (strlen($chunk) < 8) {
                $chunk = str_pad($chunk, 8, '0', STR_PAD_RIGHT);
            }
            // bindec 得到 0~255，再转成单字节字符
            $bytes .= chr(bindec($chunk));
        }
        return $bytes;
    }

    /**
     * 把 RBSP 字节串封装成 Annex B 格式的 NAL 单元（起始码 + NAL 头 + 防竞争后的 RBSP）。
     *
     * 规范 7.3.1：NAL 头 1 字节 = forbidden_zero_bit(1) + nal_ref_idc(2) + nal_unit_type(5)；
     * 规范 7.4.1：RBSP 中若出现 0x000000~0x000003 的字节序列，需插入 0x03 防竞争字节，
     * 以免与起始码 0x000001 混淆。
     *
     * @param string $rbsp     已生成的 RBSP 内容（不含 NAL 头）
     * @param int    $type     nal_unit_type，如 5=IDR slice、7=SPS、8=PPS、1=非 IDR slice
     * @param int|null $nalRefIdc 显式指定 nal_ref_idc（B 帧非参考帧需传 0）；为 null 时按 NAL 类型默认推导
     * @return string 以 4 字节起始码 0x00000001 开头的 NAL 单元
     */
    public function rbspToNal(string $rbsp, int $type, ?int $nalRefIdc = null): string
    {
        // 未显式指定时按 NAL 类型推导：IDR/SPS/PPS 视为参考（3），其余默认 2
        $ref = $nalRefIdc ?? match (true) {
            $type === 5 => 3,
            $type === 7 || $type === 8 => 3,
            default => 2
        };
        // NAL 头：forbidden_zero_bit 占最高位（恒 0），中间 2 位 nal_ref_idc，低 5 位 nal_unit_type
        $header = chr(($ref << 5) | $type);

        $output = '';
        // 记录当前已经连续出现了多少个 0x00 字节，用于判断是否需要插入 0x03
        $zeroCount = 0;
        for ($i = 0; $i < strlen($rbsp); $i++) {
            $byte = ord($rbsp[$i]);
            // 规范 7.4.1：已出现两个 0x00 且下一个字节 <= 0x03 时，插入 emulation_prevention_three_byte
            if ($zeroCount >= 2 && $byte <= 3) {
                $output .= chr(0x03);
                $zeroCount = 0;
            }
            $output .= chr($byte);
            // 维护连续零计数：是 0x00 则累加，否则清零
            if ($byte == 0) {
                $zeroCount++;
            } else {
                $zeroCount = 0;
            }
        }
        // 拼接 4 字节起始码（规范 7.4.1 中 byte_stream_nal_unit 的 leading_zero_8bits + start code prefix）
        return "\x00\x00\x00\x01" . $header . $output;
    }
}
