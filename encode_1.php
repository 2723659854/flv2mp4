<?php

require_once 'src/Codec/H264Encoder.php';

use Xiaosongshu\Flv2mp4\Codec\H264Encoder;
/** 使用真实的数据测试编码器是否正常 */
// 1. 用 ffmpeg 提取第一帧 YUV
shell_exec("ffmpeg -y -i test.mp4 -frames:v 1 -pix_fmt yuv420p -f rawvideo test_frame.yuv 2>&1");

// 2. 获取视频信息
$info = shell_exec("ffprobe -v error -select_streams v:0 -show_entries stream=width,height -of csv=p=0 test.mp4 2>&1");
list($w, $h) = explode(',', trim($info));
echo "视频分辨率: {$w}x{$h}\n";

// 3. 读取并编码
$yuvData = file_get_contents('test_frame.yuv');
$encoder = new H264Encoder($w, $h, 30);
$encoder->setQp(30);

$nalUnits = $encoder->encodeFrame($yuvData, true);
$h264Data = implode('', $nalUnits);
file_put_contents('real_test.h264', $h264Data);

echo "编码完成: " . strlen($h264Data) . " 字节\n";

// 4. FFmpeg 验证
$output = shell_exec("ffmpeg -v error -i real_test.h264 -f null - 2>&1");
if (empty($output)) {
    echo "✅ 真实视频帧编码验证通过！\n";
} else {
    echo "❌ 失败：\n$output\n";
}

// 5. 生成可播放的 MP4
shell_exec("ffmpeg -y -f h264 -i real_test.h264 -c:v copy real_test.mp4 2>&1");
echo "生成可播放文件: real_test.mp4\n";