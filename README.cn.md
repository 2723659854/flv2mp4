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

// 待转换的 FLV 文件路径
$file = __DIR__ . "/test.flv";

// 示例 1：合并转码为单个 MP4 文件（适合录播回放）
echo "=== 示例 1：合并为单个 MP4 ===\n";
$outputDir1 = __DIR__ . "/output_merge";
try {
    $res = \Xiaosongshu\Flv2mp4\Client::run($file, $outputDir1);
    echo "\n转换完成: " . $res . "\n\n";
} catch (\Exception $e) {
    echo "错误: " . $e->getMessage() . "\n\n";
}

// 示例 2：生成独立的音视频切片（适用于直播场景）
echo "=== 示例 2：生成音视频切片 ===\n";
$outputDir2 = __DIR__ . "/output_separate";
try {
    $res = \Xiaosongshu\Flv2mp4\Client::runSeparate($file, $outputDir2);
    echo "\n转换完成！生成的文件:\n";
    echo "  音频初始化段: " . ($res['audioInit'] ?? '无') . "\n";
    echo "  视频初始化段: " . ($res['videoInit'] ?? '无') . "\n";
    echo "  音频切片数量: " . count($res['audioSegments']) . "\n";
    echo "  视频切片数量: " . count($res['videoSegments']) . "\n";
    echo "  元数据文件: " . ($res['meta'] ?? '无') . "\n";
} catch (\Exception $e) {
    echo "错误: " . $e->getMessage() . "\n";
}

// 示例 3：转换为 HLS 切片（适合直播和录播）
echo "\n=== 示例 3：转换为 HLS ===\n";
$outputDir3 = __DIR__ . "/hls";
try {
    $res = \Xiaosongshu\Flv2mp4\Client::flv2Hls($file, $outputDir3);
    echo "\nHLS 转换完成，索引文件: {$res['index']}，输出目录: {$res['outputDir']}\n\n";
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