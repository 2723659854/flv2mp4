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
- **MP4 → FLV**（将 MP4 转码回单个 FLV 文件）

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

$file = __DIR__."/test.flv";


echo "=== 示例1: flv静态文件切片fMP4并合并为mp4文件 ===\n";
$outputDir1 = __DIR__."/output_merge";
try{
    $res = \Xiaosongshu\Flv2mp4\Client::runFlv2mp4($file, $outputDir1);
    echo "\n转换完成: " . $res . "\n\n";
}catch (\Exception $e){
    echo "错误: " . $e->getMessage() . "\n\n";
}


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


echo "\n === 示例5: 转换mp4为flv === \n";
$mp4File = __DIR__ . "/test.mp4";
$flvFromMp4 = __DIR__ . "/output_from_mp4.flv";
try {
    if (file_exists($mp4File)) {
        $res3 = \Xiaosongshu\Flv2mp4\Client::runMp42Flv($mp4File, $flvFromMp4);
        echo "\n mp4转flv完成: {$res3}\n\n";
    } else {
        echo "跳过: 测试文件不存在 {$mp4File}\n\n";
    }
} catch (\Exception $e) {
    echo "错误: " . $e->getMessage() . "\n\n";
}
```

## 🧪 测试与播放

- 生成的普通 MP4 可直接用 HTML5 `<video>` 标签播放，参考 `index.html`
- 生成的 fMP4 切片适用于流式播放，参考 `play_merge.html`
- 生成的 HLS 切片同时支持点播与直播，参考 `play.html`
- 使用 HLS 切片合并为flv支持点播，参考 `flv.html`
- 使用 MP3 转码为flv支持点播，参考 `flv.html`


## 🔧 背景

最初为 [xiaosongshu/rtmp_server](https://github.com/2723659854/rtmp-server) 项目开发，用于给直播流提供 MP4/HLS 录制与回放能力。

## ⚠️ 免责声明

- 项目中可能引用部分来自网络的代码或资料，如有侵权请联系作者删除
- 本项目仅供技术交流与学习，因使用产生的任何法律风险、商业纠纷或版权问题由使用者自行承担
- 请遵守当地法律法规，合理使用

## 📧 联系作者

邮箱：2723659854@qq.com
