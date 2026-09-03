<?php

namespace Xiaosongshu\Flv2mp4\Mp3;

/**
 * @purpose 音频帧写入工具
 * @author yanglong
 * @time 2026年9月3日16:26:55
 */
final class FrameWriter
{
    public static function sideInfo(Config $config, SideInfo $sideInfo): string
    {
        $channels = $config->channels;
        $writer = new BitWriter();
        $writer->write($sideInfo->main_data_begin, 9);
        $privateBits = 0;
        $writer->write($privateBits, $channels === 1 ? 5 : 3);
        for ($ch = 0; $ch < $channels; ++$ch) {
            $writer->write((int) ($sideInfo->scfsi[$ch] ?? [0, 0, 0, 0])[0], 1);
            $writer->write((int) ($sideInfo->scfsi[$ch] ?? [0, 0, 0, 0])[1], 1);
            $writer->write((int) ($sideInfo->scfsi[$ch] ?? [0, 0, 0, 0])[2], 1);
            $writer->write((int) ($sideInfo->scfsi[$ch] ?? [0, 0, 0, 0])[3], 1);
        }
        for ($gr = 0; $gr < 2; ++$gr) {
            for ($ch = 0; $ch < $channels; ++$ch) {
                $gi = $sideInfo->granules[$gr][$ch];
                if (!$gi instanceof GranuleInfo) $gi = new GranuleInfo();
                $writer->write($gi->part2_3_length, 12);
                $writer->write($gi->big_values, 9);
                $writer->write($gi->global_gain, 8);
                $writer->write($gi->scalefac_compress, 4);
                $writer->write($gi->window_switching_flag ? 1 : 0, 1);
                if ($gi->window_switching_flag) {
                    $writer->write(0, 2);
                    $writer->write(0, 1);
                    $writer->write($gi->table_select[0] ?? 0, 5);
                    $writer->write($gi->table_select[1] ?? 0, 5);
                    $writer->write(0, 3);
                    $writer->write(0, 3);
                    $writer->write(0, 3);
                } else {
                    $writer->write($gi->table_select[0] ?? 0, 5);
                    $writer->write($gi->table_select[1] ?? 0, 5);
                    $writer->write($gi->table_select[2] ?? 0, 5);
                    $writer->write($gi->region0_count, 4);
                    $writer->write($gi->region1_count, 3);
                }
                $writer->write($gi->preflag, 1);
                $writer->write($gi->scalefac_scale, 1);
                $writer->write($gi->count1table_select, 1);
            }
        }
        return $writer->finish();
    }
}
