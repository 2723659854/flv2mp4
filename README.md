# FLV ↔ MP4 / HLS Conversion Tool

<p align="center">
  <a href="./README.cn.md"><strong>🇨🇳 中文</strong></a> •
  <a href="./README.md"><strong>🇬🇧 English</strong></a>
</p>

## 📖 Introduction

A pure PHP media conversion tool that supports:

- **FLV → MP4** (regular MP4 or fMP4 segments)
- **FLV → HLS** (m3u8 + TS segments)
- **HLS → FLV** (merge HLS segments back into a single FLV file)

Ideal for storage, distribution, and online playback, especially when paired with an RTMP live streaming server.

## 📦 Installation

```bash
composer require xiaosongshu/flv2mp4
```

## 🚀 Quick Start

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '512M');

$file = __DIR__ . '/test.flv'; // the FLV file to convert

// Example 1: Convert to a single MP4 (video-on-demand)
$outputDir1 = __DIR__ . '/output_merge';
try {
    $res = \Xiaosongshu\Flv2mp4\Client::runFlv2mp4($file, $outputDir1);
    echo "Conversion done: {$res}\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// Example 2: Generate separate audio/video fMP4 segments (for browser MSE playback)
$outputDir2 = __DIR__ . '/output_separate';
try {
    $res = \Xiaosongshu\Flv2mp4\Client::runFlv2mp4Separate($file, $outputDir2);
    echo "Audio init: {$res['audioInit']}\n";
    echo "Video init: {$res['videoInit']}\n";
    echo "Audio segments: " . count($res['audioSegments']) . "\n";
    echo "Video segments: " . count($res['videoSegments']) . "\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// Example 3: FLV → HLS
$outputDir3 = __DIR__ . '/hls';
try {
    $res = \Xiaosongshu\Flv2mp4\Client::runFlv2Hls($file, $outputDir3);
    echo "HLS index: {$res['index']}\n";
    echo "Output dir: {$res['outputDir']}\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// Example 4: HLS → FLV (merge the HLS generated above back into FLV)
$outputFlv = __DIR__ . '/output_from_hls.flv';
try {
    $index = __DIR__ . '/hls/a/b/index.m3u8'; // replace with actual m3u8 path
    $res2 = \Xiaosongshu\Flv2mp4\Client::runHls2Flv($index, $outputFlv);
    echo "Merge completed: {$res2}\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
```

## 🧪 Testing & Playback

- The generated regular MP4 can be played directly with the HTML5 `<video>` tag, see `index.html`
- The fMP4 segments are suitable for streaming playback, see `play_merge.html`
- The HLS segments support both live and on-demand playback, see `play.html`
- Merging HLS segments back to FLV enables on-demand playback, see `flv.html`

> 💡 Tip: Use `ffmpeg -i test.mp4 -c:v libx264 -c:a aac -f flv test.flv` to generate a test FLV file.

## 🔧 Background

Originally developed for [xiaosongshu/rtmp_server](https://github.com/2723659854/rtmp-server) to provide MP4/HLS recording and playback capabilities for live streams.

## ⚠️ Disclaimer

- Some code or materials may originate from the internet. If any infringement is found, please contact the author for removal.
- This project is for technical communication and learning only. Any legal risks, commercial disputes, or copyright issues arising from its use are the sole responsibility of the user.
- Please comply with local laws and regulations when using this tool.

## 📧 Contact

Email: 2723659854@qq.com