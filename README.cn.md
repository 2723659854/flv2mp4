# FLV 转 MP4 / HLS 工具

<p align="center">
  <a href="./README.cn.md"><strong>🇨🇳 中文</strong></a> •
  <a href="./README.md"><strong>🇬🇧 English</strong></a>
</p>

## 简介

这是一个纯 PHP 实现的工具，用于将 FLV 媒体文件转换为 MP4 或 HLS 格式，便于存储、分发和在线播放。

## 安装

```bash
composer require xiaosongshu/flv2mp4
```

## 使用示例

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '512M');

// 需要转换的flv媒体文件
$file = __DIR__."/test.flv";

// 示例1: 使用原有方法合并转换为单个MP4
echo "=== 示例1: 合并转换为单个MP4 ===\n";
$outputDir1 = __DIR__."/output_merge";
try{
    $res = \Xiaosongshu\Flv2mp4\Client::runFlv2mp4($file, $outputDir1);
    echo "\n转换完成: " . $res . "\n\n";
}catch (\Exception $e){
    echo "错误: " . $e->getMessage() . "\n\n";
}

// 示例2: 生成分开的音视频切片（用于浏览器播放）
echo "=== 示例2: 生成分开的音视频切片 ===\n";
$outputDir2 = __DIR__."/output_separate";
try{
    $res = \Xiaosongshu\Flv2mp4\Client::runFlv2mp4Separate($file, $outputDir2);
    echo "\n转换完成！生成的文件:\n";
    echo "  音频初始化: " . ($res['audioInit'] ?? '无') . "\n";
    echo "  视频初始化: " . ($res['videoInit'] ?? '无') . "\n";
    echo "  音频切片数量: " . count($res['audioSegments']) . "\n";
    echo "  视频切片数量: " . count($res['videoSegments']) . "\n";
    echo "  元数据文件: " . ($res['meta'] ?? '无') . "\n";
}catch (\Exception $e){
    echo "错误: " . $e->getMessage() . "\n";
}

// mp4转码生成测试的flv文件 ffmpeg -i test.mp4 -c:v libx264 -c:a aac -f flv test.flv
// 检查切片是否错误的命令 ffmpeg -v trace -i hls\a\b\segment_1.ts -f null -


echo "\n === 示例3: 转换flv为hls === \n";
$outputDir1 = __DIR__ . "/hls";
try {
    $res = \Xiaosongshu\Flv2mp4\Client::runFlv2Hls($file, $outputDir1);
    echo "\n hls转换完成 index = {$res['index']} dir = {$res['outputDir']}\n\n";

    echo "\n === 示例4: 转换hls回flv === \n";
    $outputFlv = __DIR__ . "/output_from_hls.flv";
    try {
        $res2 = \Xiaosongshu\Flv2mp4\Client::runHls2Flv($res['index'], $outputFlv);
        echo "\n hls转flv完成: {$res2}\n\n";
    } catch (\Exception $e) {
        echo "错误: " . $e->getMessage() . "\n\n";
    }
} catch (\Exception $e) {
    echo "错误: " . $e->getMessage() . "\n\n";
}
```

- 生成的普通 MP4 文件可直接通过 HTML5 `<video>` 标签播放，参考示例 `index.html`。
- 生成的 fMP4 切片适用于直播播放，参考示例 `play_merge.html`。
- 生成的 HLS 切片同时支持直播与点播，播放示例见 `play.html`。

## 背景说明

本工具最初是为 [xiaosongshu/rtmp_server](https://github.com/2723659854/rtmp-server) 直播项目提供 MP4/HLS 存储支持而开发的。

## 免责声明

- 项目中可能包含部分来源于网络的代码或资料，若涉及侵权，请联系作者删除。
- 本项目完全开源，仅用于技术分享与学习交流。
- 用户因使用本项目产生的任何法律风险、商业纠纷或版权问题，均由用户自行承担，与作者无关。
- 请遵守当地法律法规，合理使用本工具。

## 联系作者

- 邮箱：2723659854@qq.com