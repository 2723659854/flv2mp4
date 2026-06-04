# FLV ↔ MP4 / HLS 转换工具

<p align="center">
  <a href="./README.cn.md"><strong>🇨🇳 中文</strong></a> •
  <a href="./README.md"><strong>🇬🇧 English</strong></a>
</p>

## 📖 简介

一个纯 PHP 实现的媒体转换工具，支持：

- **FLV → MP4**（普通 MP4 或 fMP4 切片）
- **FLV → HLS**（生成 m3u8 + TS 切片）
- **HLS → FLV**（将 HLS 切片合并回单个 FLV 文件）

适用于存储、分发和在线播放场景，尤其适合与 RTMP 直播服务器配合使用。

## 📦 安装

```bash
composer require xiaosongshu/flv2mp4
```

## 🚀 快速开始

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '512M');

$file = __DIR__ . '/test.flv'; // 待转换的 FLV 文件

// 示例 1：转换为单个 MP4 文件（适合点播）
$outputDir1 = __DIR__ . '/output_merge';
try {
    $res = \Xiaosongshu\Flv2mp4\Client::runFlv2mp4($file, $outputDir1);
    echo "转换完成: {$res}\n";
} catch (\Exception $e) {
    echo "错误: " . $e->getMessage() . "\n";
}

// 示例 2：转换为分立的音视频 fMP4 切片（适合浏览器 MSE 播放）
$outputDir2 = __DIR__ . '/output_separate';
try {
    $res = \Xiaosongshu\Flv2mp4\Client::runFlv2mp4Separate($file, $outputDir2);
    echo "音频初始化: {$res['audioInit']}\n";
    echo "视频初始化: {$res['videoInit']}\n";
    echo "音频切片数: " . count($res['audioSegments']) . "\n";
    echo "视频切片数: " . count($res['videoSegments']) . "\n";
} catch (\Exception $e) {
    echo "错误: " . $e->getMessage() . "\n";
}

// 示例 3：FLV → HLS
$outputDir3 = __DIR__ . '/hls';
try {
    $res = \Xiaosongshu\Flv2mp4\Client::runFlv2Hls($file, $outputDir3);
    echo "HLS 索引: {$res['index']}\n";
    echo "输出目录: {$res['outputDir']}\n";
} catch (\Exception $e) {
    echo "错误: " . $e->getMessage() . "\n";
}

// 示例 4：HLS → FLV（将上面生成的 HLS 合并回 FLV）
$outputFlv = __DIR__ . '/output_from_hls.flv';
try {
    $index = __DIR__ . '/hls/a/b/index.m3u8'; // 替换为实际 m3u8 路径
    $res2 = \Xiaosongshu\Flv2mp4\Client::runHls2Flv($index, $outputFlv);
    echo "合并完成: {$res2}\n";
} catch (\Exception $e) {
    echo "错误: " . $e->getMessage() . "\n";
}
```

## 🧪 测试与播放

- 生成的普通 MP4 可直接用 HTML5 `<video>` 标签播放，参考 `index.html`
- 生成的 fMP4 切片适用于流式播放，参考 `play_merge.html`
- 生成的 HLS 切片同时支持点播与直播，参考 `play.html`
- 使用 HLS 切片合并为flv支持点播，参考 `flv.html`

> 💡 提示：可用 `ffmpeg -i test.mp4 -c:v libx264 -c:a aac -f flv test.flv` 生成测试用 FLV 文件

## 🔧 背景

最初为 [xiaosongshu/rtmp_server](https://github.com/2723659854/rtmp-server) 项目开发，用于给直播流提供 MP4/HLS 录制与回放能力。

## ⚠️ 免责声明

- 项目中可能引用部分来自网络的代码或资料，如有侵权请联系作者删除
- 本项目仅供技术交流与学习，因使用产生的任何法律风险、商业纠纷或版权问题由使用者自行承担
- 请遵守当地法律法规，合理使用

## 📧 联系作者

邮箱：2723659854@qq.com
