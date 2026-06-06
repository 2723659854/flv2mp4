# FLV ↔ MP4 / HLS Converter Tool

<p align="center">
  <a href="./README.cn.md"><strong>🇨🇳 Chinese</strong></a> •
  <a href="./README.md"><strong>🇬🇧 English</strong></a>
</p>

## 📖 Introduction
A media conversion tool written entirely in pure PHP, supporting the following conversion workflows:
- **FLV → MP4**: Standard MP4 or fragmented fMP4 chunks
- **FLV → HLS**: Generate HLS m3u8 index + TS segment files
- **HLS → FLV**: Merge split HLS TS segments back into a single FLV file
- **MP4 → FLV**: Remux existing MP4 files into standalone FLV format
- **FLV → GATEWAY**: FLV live stream relay gateway with high concurrency support

Optimized for media storage, content distribution and online playback scenarios, perfectly paired with the RTMP live streaming server project.

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

echo "=== Example1: Convert static FLV to merged fMP4 & full MP4 file ===\n";
$outputDir1 = __DIR__."/output_merge";
try{
    $res = \Xiaosongshu\Flv2mp4\Client::runFlv2mp4($file, $outputDir1);
    echo "\nConvert finished: " . $res . "\n\n";
}catch (\Exception $e){
    echo "Error: " . $e->getMessage() . "\n\n";
}

echo "=== Example2: Generate separate audio & video fMP4 chunks ===\n";
$outputDir2 = __DIR__."/output_separate";
try{
    $res = \Xiaosongshu\Flv2mp4\Client::runFlv2mp4Separate($file, $outputDir2);
    echo "\nConvert completed! Generated files:\n";
    echo "  Audio init file: " . ($res['audioInit'] ?? 'N/A') . "\n";
    echo "  Video init file: " . ($res['videoInit'] ?? 'N/A') . "\n";
    echo "  Total audio segments: " . count($res['audioSegments']) . "\n";
    echo "  Total video segments: " . count($res['videoSegments']) . "\n";
    echo "  Metadata file: " . ($res['meta'] ?? 'N/A') . "\n";
}catch (\Exception $e){
    echo "Error: " . $e->getMessage() . "\n";
}

echo "\n === Example3: Convert FLV to HLS stream === \n";
$outputDir1 = __DIR__ . "/hls";
try {
    $res = \Xiaosongshu\Flv2mp4\Client::runFlv2Hls($file, $outputDir1);
    echo "\n HLS conversion done | Index Path = {$res['index']} | Output Dir = {$res['outputDir']}\n\n";

    echo "\n === Example4: Convert HLS back to single FLV === \n";
    $outputFlv = __DIR__ . "/output_from_hls.flv";
    try {
        $res2 = \Xiaosongshu\Flv2mp4\Client::runHls2Flv($res['index'], $outputFlv);
        echo "\n HLS to FLV finished: {$res2}\n\n";
    } catch (\Exception $e) {
        echo "Error: " . $e->getMessage() . "\n\n";
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n\n";
}

echo "\n === Example5: Convert MP4 source file to FLV === \n";
$mp4File = __DIR__ . "/test.mp4";
$flvFromMp4 = __DIR__ . "/output_from_mp4.flv";
try {
    if (file_exists($mp4File)) {
        $res3 = \Xiaosongshu\Flv2mp4\Client::runMp42Flv($mp4File, $flvFromMp4);
        echo "\n MP4 to FLV finished: {$res3}\n\n";
    } else {
        echo "Skipped: Source test file not found {$mp4File}\n\n";
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n\n";
}
```

### FLV Live Gateway Sample Code
```php
require_once __DIR__ . '/vendor/autoload.php';
// ====== Service Bootstrap ======
error_reporting(E_ALL);
set_time_limit(0);

/** Gateway listening port for downstream client access */
$port = isset($argv[1]) ? (int)$argv[1] : 8080;
/** Upstream source FLV live address */
$upstream = isset($argv[2]) ? $argv[2] : 'http://127.0.0.1:8501';

/**
 * # Tier 1 Gateway
 * php gateway.php 8080 http://127.0.0.1:8501 | Play URL: http://127.0.0.1:8080/{AppName}/{StreamName}.flv
 *
 * # Tier 2 Gateway
 * php gateway.php 8081 http://127.0.0.1:8080 | Play URL: http://127.0.0.1:8081/{AppName}/{StreamName}.flv
 *
 * # Tier 3 Gateway
 * php gateway.php 8082 http://127.0.0.1:8081 | Play URL: http://127.0.0.1:8082/{AppName}/{StreamName}.flv
 */
$gateway = new \Xiaosongshu\Flv2mp4\manage\FlvGateway($port, $upstream);
/** Toggle debug mode to print runtime logs */
$gateway->debug = true;
/** Start gateway service */
$gateway->start();
```

## 🧪 Testing & Playback Guide
- Standard generated MP4: Direct playback via HTML5 `<video>` tag, reference `index.html`
- Generated fMP4 chunks: Designed for chunked streaming playback, reference `play_merge.html`
- Generated HLS segments: Supports both VOD and live streaming, reference `play.html`
- FLV merged from HLS chunks: Available for on-demand playback, reference `flv.html`
- MP4 converted into FLV: Ready for VOD playback, reference `flv.html`
- FLV Gateway: High-performance live stream relay, supports multi-layer & multi-node distributed deployment

## 🔧 Project Background
Initially developed as a dependent component for [xiaosongshu/rtmp_server](https://github.com/2723659854/rtmp-server), to add permanent MP4/HLS recording and VOD playback capability for RTMP live streams.

## ⚠️ Disclaimer
- Partial open-source code snippets collected from public resources; contact author for immediate removal if any copyright infringement exists.
- This project is built solely for technical learning & research. End users bear full legal, commercial and copyright liabilities arising from any production usage.
- Comply with local laws and regulations during usage.

## 📧 Contact Author
Email: 2723659854@qq.com