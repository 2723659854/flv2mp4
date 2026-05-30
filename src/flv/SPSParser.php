<?php

namespace Xiaosongshu\Flv2mp4\Flv;

class SPSParser
{
    public static function _ebsp2rbsp($uint8array)
    {
        $src = $uint8array;
        $src_length = strlen($src);
        $dst = '';
        for ($i = 0; $i < $src_length; $i++) {
            if ($i >= 2 && ord($src[$i]) === 0x03 && ord($src[$i-1]) === 0x00 && ord($src[$i-2]) === 0x00) {
                continue;
            }
            $dst .= $src[$i];
        }
        return $dst;
    }

    public static function parseSPS($uint8array)
    {
        $rbsp = self::_ebsp2rbsp($uint8array);
        $gb = new ExpGolomb($rbsp);
        $gb->readByte();
        $profile_idc = $gb->readByte();
        $gb->readByte();
        $level_idc = $gb->readByte();
        $gb->readUEG();
        $profile_string = self::getProfileString($profile_idc);
        $level_string = self::getLevelString($level_idc);
        $chroma_format_idc = 1;
        $chroma_format = 420;
        $chroma_format_table = [0, 420, 422, 444];
        $bit_depth = 8;
        $extended_profiles = [100, 110, 122, 244, 44, 83, 86, 118, 128, 138, 144];
        if (in_array($profile_idc, $extended_profiles)) {
            $chroma_format_idc = $gb->readUEG();
            if ($chroma_format_idc === 3) $gb->readBits(1);
            if ($chroma_format_idc <= 3) $chroma_format = $chroma_format_table[$chroma_format_idc];
            $bit_depth = $gb->readUEG() + 8;
            $gb->readUEG();
            $gb->readBits(1);
            if ($gb->readBool()) {
                $scaling_list_count = ($chroma_format_idc !== 3) ? 8 : 12;
                for ($i = 0; $i < $scaling_list_count; $i++) {
                    if ($gb->readBool()) {
                        if ($i < 6) self::_skipScalingList($gb, 16);
                        else self::_skipScalingList($gb, 64);
                    }
                }
            }
        }
        $gb->readUEG();
        $pic_order_cnt_type = $gb->readUEG();
        if ($pic_order_cnt_type === 0) {
            $gb->readUEG();
        } elseif ($pic_order_cnt_type === 1) {
            $gb->readBits(1);
            $gb->readSEG();
            $gb->readSEG();
            $num_ref_frames = $gb->readUEG();
            for ($i = 0; $i < $num_ref_frames; $i++) $gb->readSEG();
        }
        $gb->readUEG();
        $gb->readBits(1);
        $pic_width_in_mbs_minus1 = $gb->readUEG();
        $pic_height_in_map_units_minus1 = $gb->readUEG();
        $frame_mbs_only_flag = $gb->readBits(1);
        if ($frame_mbs_only_flag === 0) $gb->readBits(1);
        $gb->readBits(1);
        $frame_crop_left_offset = 0;
        $frame_crop_right_offset = 0;
        $frame_crop_top_offset = 0;
        $frame_crop_bottom_offset = 0;
        if ($gb->readBool()) {
            $frame_crop_left_offset = $gb->readUEG();
            $frame_crop_right_offset = $gb->readUEG();
            $frame_crop_top_offset = $gb->readUEG();
            $frame_crop_bottom_offset = $gb->readUEG();
        }
        $sar_width = 1;
        $sar_height = 1;
        $fps = 0;
        $fps_fixed = true;
        $fps_num = 0;
        $fps_den = 0;
        if ($gb->readBool()) {
            if ($gb->readBool()) {
                $aspect_ratio_idc = $gb->readByte();
                $sar_w_table = [1,12,10,16,40,24,20,32,80,18,15,64,160,4,3,2];
                $sar_h_table = [1,11,11,11,33,11,11,11,33,11,11,33,99,3,2,1];
                if ($aspect_ratio_idc > 0 && $aspect_ratio_idc < 16) {
                    $sar_width = $sar_w_table[$aspect_ratio_idc-1];
                    $sar_height = $sar_h_table[$aspect_ratio_idc-1];
                } elseif ($aspect_ratio_idc === 255) {
                    $sar_width = ($gb->readByte() << 8) | $gb->readByte();
                    $sar_height = ($gb->readByte() << 8) | $gb->readByte();
                }
            }
            if ($gb->readBool()) $gb->readBool();
            if ($gb->readBool()) {
                $gb->readBits(4);
                if ($gb->readBool()) $gb->readBits(24);
            }
            if ($gb->readBool()) {
                $gb->readUEG();
                $gb->readUEG();
            }
            if ($gb->readBool()) {
                $num_units_in_tick = $gb->readBits(32);
                $time_scale = $gb->readBits(32);
                $fps_fixed = $gb->readBool();
                $fps_num = $time_scale;
                $fps_den = $num_units_in_tick * 2;
                $fps = $fps_num / $fps_den;
            }
        }
        $sarScale = 1;
        if ($sar_width != 1 || $sar_height != 1) $sarScale = $sar_width / $sar_height;
        if ($chroma_format_idc === 0) {
            $crop_unit_x = 1;
            $crop_unit_y = 2 - $frame_mbs_only_flag;
        } else {
            $sub_wc = ($chroma_format_idc === 3) ? 1 : 2;
            $sub_hc = ($chroma_format_idc === 1) ? 2 : 1;
            $crop_unit_x = $sub_wc;
            $crop_unit_y = $sub_hc * (2 - $frame_mbs_only_flag);
        }
        $codec_width = ($pic_width_in_mbs_minus1 + 1) * 16;
        $codec_height = (2 - $frame_mbs_only_flag) * (($pic_height_in_map_units_minus1 + 1) * 16);
        $codec_width -= ($frame_crop_left_offset + $frame_crop_right_offset) * $crop_unit_x;
        $codec_height -= ($frame_crop_top_offset + $frame_crop_bottom_offset) * $crop_unit_y;
        $present_width = ceil($codec_width * $sarScale);
        $gb->destroy();
        return [
            'profile_string' => $profile_string,
            'level_string' => $level_string,
            'bit_depth' => $bit_depth,
            'chroma_format' => $chroma_format,
            'chroma_format_string' => self::getChromaFormatString($chroma_format),
            'frame_rate' => ['fixed' => $fps_fixed, 'fps' => $fps, 'fps_den' => $fps_den, 'fps_num' => $fps_num],
            'sar_ratio' => ['width' => $sar_width, 'height' => $sar_height],
            'codec_size' => ['width' => $codec_width, 'height' => $codec_height],
            'present_size' => ['width' => $present_width, 'height' => $codec_height]
        ];
    }

    public static function _skipScalingList($gb, $count)
    {
        $last_scale = 8;
        $next_scale = 8;
        for ($i = 0; $i < $count; $i++) {
            if ($next_scale !== 0) {
                $delta_scale = $gb->readSEG();
                $next_scale = ($last_scale + $delta_scale + 256) % 256;
            }
            $last_scale = ($next_scale === 0) ? $last_scale : $next_scale;
        }
    }

    public static function getProfileString($profile_idc)
    {
        switch ($profile_idc) {
            case 66: return 'Baseline';
            case 77: return 'Main';
            case 88: return 'Extended';
            case 100: return 'High';
            case 110: return 'High10';
            case 122: return 'High422';
            case 244: return 'High444';
            default: return 'Unknown';
        }
    }

    public static function getLevelString($level_idc)
    {
        return number_format($level_idc / 10, 1, '.', '');
    }

    public static function getChromaFormatString($chroma)
    {
        switch ($chroma) {
            case 420: return '4:2:0';
            case 422: return '4:2:2';
            case 444: return '4:4:4';
            default: return 'Unknown';
        }
    }
}