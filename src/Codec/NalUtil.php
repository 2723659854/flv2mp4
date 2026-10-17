<?php

namespace Xiaosongshu\Flv2mp4\Codec;

/**
 * @purpose nalu数据处理工具
 * @author yanglong
 * @time 2026年7月23日15:17:16
 */
class NalUtil
{
    /**
     * 移除H264 NAL防竞争字节 0x000003 -> 0x0000
     */
    public static function removeEmulationPrevention(string $data): string
    {
        $out = '';
        $zeroCnt = 0;
        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            $b = ord($data[$i]);
            // 连续两个0后遇到03，跳过03
            if ($zeroCnt === 2 && $b === 0x03) {
                $zeroCnt = 0;
                continue;
            }
            $out .= chr($b);
            $zeroCnt = $b === 0 ? $zeroCnt + 1 : 0;
        }
        return $out;
    }

    /**
     * 重新加入H264 NAL防竞争字节：连续两个0后若下一字节 <= 0x03，插入0x03
     * （removeEmulationPrevention 的逆操作；avcC/FLV 配置记录中的 SPS/PPS 必须是转义后的 EBSP 形态）
     */
    public static function addEmulationPrevention(string $data): string
    {
        $out = '';
        $zeroCnt = 0;
        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            $b = ord($data[$i]);
            if ($zeroCnt >= 2 && $b <= 0x03) {
                $out .= "\x03";
                $zeroCnt = 0;
            }
            $out .= chr($b);
            $zeroCnt = $b === 0 ? $zeroCnt + 1 : 0;
        }
        return $out;
    }

    /**
     * 拆分AnnexB(H264裸流 0001/000001起始码) 为NAL数组
     * @param string $stream AnnexB码流
     * @return array [{type:int, data:rbsp, raw:nal完整字节}]
     */
    public static function splitNalUnits(string $stream): array
    {
        $nalList = [];
        $pos = 0;
        $len = strlen($stream);
        $startCode3 = "\x00\x00\x01";
        $startCode4 = "\x00\x00\x00\x01";

        while ($pos < $len) {
            $foundStart = false;
            // 优先匹配4字节起始码
            if ($pos + 4 <= $len && substr($stream, $pos, 4) === $startCode4) {
                $pos += 4;
                $foundStart = true;
            } elseif ($pos + 3 <= $len && substr($stream, $pos, 3) === $startCode3) {
                $pos += 3;
                $foundStart = true;
            }

            if (!$foundStart) {
                $pos++;
                continue;
            }

            $nalBegin = $pos;
            // 寻找下一个起始码作为结束边界
            while ($pos < $len) {
                if (($pos + 4 <= $len && substr($stream, $pos, 4) === $startCode4)
                    || ($pos + 3 <= $len && substr($stream, $pos, 3) === $startCode3)) {
                    break;
                }
                $pos++;
            }

            $nalRaw = substr($stream, $nalBegin, $pos - $nalBegin);
            if ($nalRaw === '') continue;

            $nalType = ord($nalRaw[0]) & 0x1F;
            // RBSP = NAL去掉头部 + 去除防竞争字节
            $rbsp = self::removeEmulationPrevention(substr($nalRaw, 1));

            $nalList[] = [
                'type' => $nalType,
                'data' => $rbsp,
                'raw'  => $nalRaw
            ];
        }
        return $nalList;
    }
}