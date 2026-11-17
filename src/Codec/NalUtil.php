<?php

namespace Xiaosongshu\Flv2mp4\Codec;

/**
 * @purpose nalu数据处理工具
 * @author yanglong
 * @time 2026年7月23日15:17:16
 *
 * NAL 单元层面的通用工具：
 * - 防竞争字节（emulation_prevention_three_byte）的添加/移除（规范 7.4.1）；
 * - Annex B 字节流按起始码拆分成 NAL 列表（规范 B.1 byte_stream_nal_unit）；
 * - 从 NAL 头解析 nal_unit_type（规范 7.3.1）。
 */
class NalUtil
{
    /**
     * 移除NAL防竞争字节 0x000003 -> 0x0000
     *
     * 输入为 EBSP（含防竞争字节），输出为 RBSP；对应规范 7.3.1.1 中
     * 「当 consecutive zeros 达到 2 且下一字节为 0x03 时丢弃该 0x03」的逆过程。
     *
     * @param string $data 含防竞争字节的字节串
     * @return string 去除 0x03 后的 RBSP 字节串
     */
    public static function removeEmulationPrevention(string $data): string
    {
        $out = '';
        // 记录当前已连续出现的 0x00 个数
        $zeroCnt = 0;
        $len = strlen($data);
        // 逐字节扫描
        for ($i = 0; $i < $len; $i++) {
            $b = ord($data[$i]);
            // 连续两个0后遇到03，跳过03
            // 注意：跳过 0x03 后连续零计数清零，因为该 0x03 本身就是防竞争字节
            if ($zeroCnt === 2 && $b === 0x03) {
                $zeroCnt = 0;
                continue;
            }
            // 普通字节直接输出
            $out .= chr($b);
            // 维护连续零计数：0x00 累加，其它字节归零
            $zeroCnt = $b === 0 ? $zeroCnt + 1 : 0;
        }
        return $out;
    }

    /**
     * 重新加入NAL防竞争字节：连续两个0后若下一字节 <= 0x03，插入0x03
     * （removeEmulationPrevention 的逆操作；avcC/FLV 配置记录中的 SPS/PPS 必须是转义后的 EBSP 形态）
     *
     * 规范 7.4.1：为避免 RBSP 中意外出现 0x000000/0x000001/0x000002/0x000003，
     * 在第三个字节前插入 emulation_prevention_three_byte（0x03）。
     *
     * @param string $data RBSP 字节串
     * @return string 含防竞争字节的 EBSP 字节串
     */
    public static function addEmulationPrevention(string $data): string
    {
        $out = '';
        // 记录当前已连续出现的 0x00 个数
        $zeroCnt = 0;
        $len = strlen($data);
        // 逐字节扫描
        for ($i = 0; $i < $len; $i++) {
            $b = ord($data[$i]);
            // 已连续两个 0x00，且当前字节 <= 0x03 时需要插入 0x03，防止与起始码混淆
            if ($zeroCnt >= 2 && $b <= 0x03) {
                $out .= "\x03";
                // 插入后连续零计数归零（插入的 0x03 打断了零序列）
                $zeroCnt = 0;
            }
            // 输出原始字节
            $out .= chr($b);
            // 维护连续零计数
            $zeroCnt = $b === 0 ? $zeroCnt + 1 : 0;
        }
        return $out;
    }

    /**
     * 拆分AnnexB(裸流 0001/000001起始码) 为NAL数组
     * @param string $stream AnnexB码流
     * @return array [{type:int, data:rbsp, raw:nal完整字节}]
     *
     * 规范 B.1：字节流由若干 byte_stream_nal_unit 组成，每个单元以
     * 4 字节 0x00000001 或 3 字节 0x000001 起始码开头（前面可能还有 leading_zero_8bits）。
     * 本方法以「下一个起始码」作为当前 NAL 的结束边界。
     */
    public static function splitNalUnits(string $stream): array
    {
        // 结果列表：每项包含 nal_unit_type、RBSP 数据、原始 NAL 字节
        $nalList = [];
        // 当前扫描位置
        $pos = 0;
        $len = strlen($stream);
        // 3 字节起始码
        $startCode3 = "\x00\x00\x01";
        // 4 字节起始码
        $startCode4 = "\x00\x00\x00\x01";

        // 循环直到扫描完整个字节流
        while ($pos < $len) {
            // 本轮是否在 $pos 处找到起始码
            $foundStart = false;
            // 优先匹配4字节起始码
            if ($pos + 4 <= $len && substr($stream, $pos, 4) === $startCode4) {
                // 跳过 4 字节起始码，NAL 内容紧随其后
                $pos += 4;
                $foundStart = true;
            } elseif ($pos + 3 <= $len && substr($stream, $pos, 3) === $startCode3) {
                // 退而匹配 3 字节起始码
                $pos += 3;
                $foundStart = true;
            }

            // 当前位置不是起始码：向后挪一个字节继续找（兼容起始码前的 leading_zero_8bits 与填充字节）
            if (!$foundStart) {
                $pos++;
                continue;
            }

            // 记录 NAL 数据（含 NAL 头）的起始偏移
            $nalBegin = $pos;
            // 寻找下一个起始码作为结束边界
            while ($pos < $len) {
                // 命中任一长度的起始码即认为当前 NAL 结束
                if (($pos + 4 <= $len && substr($stream, $pos, 4) === $startCode4)
                    || ($pos + 3 <= $len && substr($stream, $pos, 3) === $startCode3)) {
                    break;
                }
                $pos++;
            }

            // 截取原始 NAL 字节（包含 1 字节 NAL 头，不含起始码）
            $nalRaw = substr($stream, $nalBegin, $pos - $nalBegin);
            // 空 NAL（两个起始码紧邻）直接跳过
            if ($nalRaw === '') continue;

            // NAL 头低 5 位为 nal_unit_type（规范 7.3.1：forbidden_zero_bit(1) + nal_ref_idc(2) + nal_unit_type(5)）
            $nalType = ord($nalRaw[0]) & 0x1F;
            // RBSP = NAL去掉头部 + 去除防竞争字节
            $rbsp = self::removeEmulationPrevention(substr($nalRaw, 1));

            // 收集该 NAL 的解析结果
            $nalList[] = [
                'type' => $nalType,
                'data' => $rbsp,
                'raw'  => $nalRaw
            ];
        }
        return $nalList;
    }
}