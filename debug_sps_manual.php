<?php
require_once 'vendor/autoload.php';

use Xiaosongshu\Flv2mp4\Manage\Mp4ToFlv;

$inputFile = 'test.mp4';

if (!file_exists($inputFile)) {
    echo "错误：测试文件 {$inputFile} 不存在！\n";
    exit(1);
}

$mp4Data = file_get_contents($inputFile);

echo "=== 手动解析 SPS ===\n";

$mp4ToFlv = new Mp4ToFlv($inputFile, '/tmp/test.flv');
$reflection = new ReflectionClass($mp4ToFlv);

$mp4DataProp = $reflection->getProperty('mp4Data');
$mp4DataProp->setAccessible(true);
$mp4DataProp->setValue($mp4ToFlv, $mp4Data);

$parseBoxesMethod = $reflection->getMethod('parseMp4Boxes');
$parseBoxesMethod->setAccessible(true);
$parseBoxesMethod->invoke($mp4ToFlv);

$parseTracksMethod = $reflection->getMethod('parseTracks');
$parseTracksMethod->setAccessible(true);
$parseTracksMethod->invoke($mp4ToFlv);

$spsProp = $reflection->getProperty('sps');
$spsProp->setAccessible(true);
$sps = $spsProp->getValue($mp4ToFlv);

echo "SPS 十六进制：" . bin2hex($sps) . "\n";
echo "SPS 长度：" . strlen($sps) . " 字节\n\n";

// 手动解析 SPS
echo "=== 逐字节分析 ===\n";
$bytes = unpack('C*', $sps);
foreach ($bytes as $i => $byte) {
    echo sprintf("字节 %2d: 0x%02X (二进制：%08b)\n", $i-1, $byte, $byte);
    if ($i > 15) {
        echo "... (省略后续字节)\n";
        break;
    }
}

echo "\n=== SPS 结构解析 ===\n";
$pos = 0;

// NALU 头
$naluHeader = ord($sps[$pos]);
$forbiddenZeroBit = ($naluHeader >> 7) & 0x01;
$nalRefIdc = ($naluHeader >> 5) & 0x03;
$nalUnitType = $naluHeader & 0x1F;
echo "NALU 头：0x" . dechex($naluHeader) . "\n";
echo "  forbidden_zero_bit: {$forbiddenZeroBit}\n";
echo "  nal_ref_idc: {$nalRefIdc}\n";
echo "  nal_unit_type: {$nalUnitType} (" . ($nalUnitType == 7 ? 'SPS' : '其他') . ")\n";
$pos++;

// profile_idc
$profileIdc = ord($sps[$pos]);
echo "profile_idc: {$profileIdc} (0x" . dechex($profileIdc) . ")\n";
$pos++;

// constraint_set_flags
$constraintSetFlags = ord($sps[$pos]);
echo "constraint_set_flags: 0x" . dechex($constraintSetFlags) . "\n";
$pos++;

// level_idc
$levelIdc = ord($sps[$pos]);
echo "level_idc: {$levelIdc} (0x" . dechex($levelIdc) . ")\n";
$pos++;

// 辅助函数：读取 UEG
function readUEG($data, &$pos) {
    $leadingZeroBits = 0;
    while ($pos < strlen($data)) {
        $byte = ord($data[$pos]);
        if ($byte >= 128) break;
        $leadingZeroBits++;
        $pos++;
    }
    
    $pos++;
    $result = 1;
    for ($i = 0; $i < $leadingZeroBits; $i++) {
        $bitPos = 7 - $i;
        $bit = (ord($data[$pos]) >> $bitPos) & 0x01;
        $result = ($result << 1) | $bit;
        if ($i < $leadingZeroBits - 1) {
            $bitPos--;
            if ($bitPos < 0) {
                $pos++;
                $bitPos = 7;
            }
        }
    }
    $result -= 1;
    return $result;
}

function skipUEG($data, $pos) {
    while ($pos < strlen($data)) {
        $byte = ord($data[$pos]);
        if ($byte >= 128) {
            $pos++;
            break;
        }
        $pos++;
    }
    return $pos;
}

// seq_parameter_set_id
echo "\n读取 seq_parameter_set_id (UEG):\n";
$spsId = readUEG($sps, $pos);
echo "  seq_parameter_set_id: {$spsId}\n";

// log2_max_frame_num_minus4
echo "\n读取 log2_max_frame_num_minus4 (UEG):\n";
$log2MaxFrameNumMinus4 = readUEG($sps, $pos);
echo "  log2_max_frame_num_minus4: {$log2MaxFrameNumMinus4}\n";

// pic_order_cnt_type
echo "\n读取 pic_order_cnt_type (UEG):\n";
$picOrderCntType = readUEG($sps, $pos);
echo "  pic_order_cnt_type: {$picOrderCntType}\n";

if ($picOrderCntType == 0) {
    echo "  pic_order_cnt_type == 0, 读取 log2_max_pic_order_cnt_lsb_minus4 (UEG):\n";
    $log2MaxPicOrderCntLsbMinus4 = readUEG($sps, $pos);
    echo "    log2_max_pic_order_cnt_lsb_minus4: {$log2MaxPicOrderCntLsbMinus4}\n";
} elseif ($picOrderCntType == 1) {
    echo "  pic_order_cnt_type == 1, 跳过多个字段...\n";
}

// num_ref_frames
echo "\n读取 num_ref_frames (UEG):\n";
$numRefFrames = readUEG($sps, $pos);
echo "  num_ref_frames: {$numRefFrames}\n";

// gaps_in_frame_num_value_allowed_flag
$gapsFlag = (ord($sps[$pos]) >> 7) & 0x01;
echo "gaps_in_frame_num_value_allowed_flag: {$gapsFlag}\n";
$pos++;

// pic_width_in_mbs_minus1
echo "\n读取 pic_width_in_mbs_minus1 (UEG):\n";
$picWidthInMbsMinus1 = readUEG($sps, $pos);
echo "  pic_width_in_mbs_minus1: {$picWidthInMbsMinus1}\n";
echo "  计算宽度：" . (($picWidthInMbsMinus1 + 1) * 16) . "\n";

// pic_height_in_map_units_minus1
echo "\n读取 pic_height_in_map_units_minus1 (UEG):\n";
$picHeightInMapUnitsMinus1 = readUEG($sps, $pos);
echo "  pic_height_in_map_units_minus1: {$picHeightInMapUnitsMinus1}\n";
echo "  计算高度：" . (($picHeightInMapUnitsMinus1 + 1) * 16) . "\n";

echo "\n=== 最终结果 ===\n";
echo "视频宽度：" . (($picWidthInMbsMinus1 + 1) * 16) . "\n";
echo "视频高度：" . (($picHeightInMapUnitsMinus1 + 1) * 16) . "\n";
?>