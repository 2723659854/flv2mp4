# FLV ↔ MP4 / HLS Conversion Tool

<p align="center">
  <a href="./README.cn.md"><strong>🇨🇳 中文</strong></a> •
  <a href="./README.md"><strong>🇬🇧 English</strong></a>
</p>

## 📖 Introduction

A pure PHP media conversion tool that supports:

- **FLV → MP4** (regular MP4 or fMP4 segments)
- **FLV → HLS** (generate m3u8 + TS segments)
- **HLS → FLV** (merge HLS segments back into a single FLV file)
- **MP4 → FLV** (transmux MP4 back to a single FLV file)

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

$file = __DIR__."/test.flv";


echo "=== Example 1: Segment FLV into fMP4 and merge to a single MP4 ===\n";
$outputDir1 = __DIR__."/output_merge";
try{
    $res = \Xiaosongshu\Flv2mp4\Client::runFlv2mp4($file, $outputDir1);
    echo "\nDone: " . $res . "\n\n";
}catch (\Exception $e){
    echo "Error: " . $e->getMessage() . "\n\n";
}


echo "=== Example 2: Generate separate audio/video fMP4 segments ===\n";
$outputDir2 = __DIR__."/output_separate";
try{
    $res = \Xiaosongshu\Flv2mp4\Client::runFlv2mp4Separate($file, $outputDir2);
    echo "\nDone! Generated files:\n";
    echo "  Audio init: " . ($res['audioInit'] ?? 'none') . "\n";
    echo "  Video init: " . ($res['videoInit'] ?? 'none') . "\n";
    echo "  Audio segments: " . count($res['audioSegments']) . "\n";
    echo "  Video segments: " . count($res['videoSegments']) . "\n";
    echo "  Metadata file: " . ($res['meta'] ?? 'none') . "\n";
}catch (\Exception $e){
    echo "Error: " . $e->getMessage() . "\n";
}

echo "\n === Example 3: Convert FLV to HLS === \n";
$outputDir1 = __DIR__ . "/hls";
try {
    $res = \Xiaosongshu\Flv2mp4\Client::runFlv2Hls($file, $outputDir1);
    echo "\n HLS conversion done: index = {$res['index']} dir = {$res['outputDir']}\n\n";

    echo "\n === Example 4: Merge HLS back to FLV === \n";
    $outputFlv = __DIR__ . "/output_from_hls.flv";
    try {
        $res2 = \Xiaosongshu\Flv2mp4\Client::runHls2Flv($res['index'], $outputFlv);
        echo "\n HLS → FLV done: {$res2}\n\n";
    } catch (\Exception $e) {
        echo "Error: " . $e->getMessage() . "\n\n";
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n\n";
}


echo "\n === Example 5: Convert MP4 to FLV === \n";
$mp4File = __DIR__ . "/test.mp4";
$flvFromMp4 = __DIR__ . "/output_from_mp4.flv";
try {
    if (file_exists($mp4File)) {
        $res3 = \Xiaosongshu\Flv2mp4\Client::runMp42Flv($mp4File, $flvFromMp4);
        echo "\n MP4 → FLV done: {$res3}\n\n";
    } else {
        echo "Skipped: test file not found {$mp4File}\n\n";
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n\n";
}
```

## 🧪 Testing & Playback

- Generated MP4 files can be played directly with the HTML5 `<video>` tag, see `index.html`
- fMP4 segments are suitable for streaming playback, see `play_merge.html`
- HLS segments support both on-demand and live playback, see `play.html`
- Merge HLS segments back to FLV for on-demand playback, see `flv.html`
- MP4 to FLV conversion also supports on-demand, see `flv.html`

> 💡 Tip: Use `ffmpeg -i test.mp4 -c:v libx264 -c:a aac -f flv test.flv` to generate a test FLV file.

## 🔧 Background

Originally developed for [xiaosongshu/rtmp_server](https://github.com/2723659854/rtmp-server) to provide MP4/HLS recording and playback capabilities for live streams.

## ⚠️ Disclaimer

- This project may contain code or materials from the internet. If any infringement occurs, please contact the author for removal.
- This project is for technical communication and learning only. Any legal risks, commercial disputes, or copyright issues arising from its use are the sole responsibility of the user.
- Please comply with local laws and regulations when using this tool.

## 📧 Contact

Email: 2723659854@qq.com