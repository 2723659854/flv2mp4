# FLV ↔ MP4 / HLS Conversion Tool

<p align="center">
  <a href="./README.cn.md"><strong>🇨🇳 Chinese</strong></a> •
  <a href="./README.md"><strong>🇬🇧 English</strong></a>
</p>

## 📖 Introduction

A pure PHP media conversion tool supporting:

- **FLV → MP4** (regular MP4 or fMP4 segments)
- **FLV → HLS** (generate m3u8 + TS segments)
- **HLS → FLV** (merge HLS segments back into a single FLV file)
- **MP4 → FLV** (transcode MP4 back to a single FLV file)
- **FLV → GATEWAY** (gateway forwarding for FLV live streams, supports high concurrency)

Suitable for storage, distribution, and online playback scenarios, especially when used with RTMP live streaming servers.

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


echo "=== Example 1: FLV static file to fMP4 segments and merge to MP4 ===\n";
$outputDir1 = __DIR__."/output_merge";
try{
    $res = \Xiaosongshu\Flv2mp4\Client::runFlv2mp4($file, $outputDir1);
    echo "\nConversion completed: " . $res . "\n\n";
}catch (\Exception $e){
    echo "Error: " . $e->getMessage() . "\n\n";
}


echo "=== Example 2: Generate separate audio/video segments ===\n";
$outputDir2 = __DIR__."/output_separate";
try{
    $res = \Xiaosongshu\Flv2mp4\Client::runFlv2mp4Separate($file, $outputDir2);
    echo "\nConversion completed! Generated files:\n";
    echo "  Audio init: " . ($res['audioInit'] ?? 'None') . "\n";
    echo "  Video init: " . ($res['videoInit'] ?? 'None') . "\n";
    echo "  Audio segment count: " . count($res['audioSegments']) . "\n";
    echo "  Video segment count: " . count($res['videoSegments']) . "\n";
    echo "  Metadata file: " . ($res['meta'] ?? 'None') . "\n";
}catch (\Exception $e){
    echo "Error: " . $e->getMessage() . "\n";
}

echo "\n === Example 3: Convert FLV to HLS === \n";
$outputDir1 = __DIR__ . "/hls";
try {
    $res = \Xiaosongshu\Flv2mp4\Client::runFlv2Hls($file, $outputDir1);
    echo "\n HLS conversion completed index = {$res['index']} dir = {$res['outputDir']}\n\n";

    echo "\n === Example 4: Convert HLS back to FLV === \n";
    $outputFlv = __DIR__ . "/output_from_hls.flv";
    try {
        $res2 = \Xiaosongshu\Flv2mp4\Client::runHls2Flv($res['index'], $outputFlv);
        echo "\n HLS to FLV conversion completed: {$res2}\n\n";
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
        echo "\n MP4 to FLV conversion completed: {$res3}\n\n";
    } else {
        echo "Skipped: Test file does not exist {$mp4File}\n\n";
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n\n";
}
```

### FLV Live Gateway Example Code

```php
require_once __DIR__ . '/vendor/autoload.php';
// ====== Start ======
error_reporting(E_ALL);
set_time_limit(0);

/** Port for downstream service */
$port = isset($argv[1]) ? (int)$argv[1] : 8080;
/** Upstream FLV playback URL */
$upstream = isset($argv[2]) ? $argv[2] : 'http://127.0.0.1:8501';

/**
 * # Level 1 Gateway
 * php gateway.php 8080 http://127.0.0.1:8501 Playback URL: http://127.0.0.1:8080/{app_name}/{stream_name}.flv
 *
 * # Level 2 Gateway
 * php gateway.php 8081 http://127.0.0.1:8080 Playback URL: http://127.0.0.1:8081/{app_name}/{stream_name}.flv
 *
 * # Level 3 Gateway
 * php gateway.php 8082 http://127.0.0.1:8081 Playback URL: http://127.0.0.1:8082/{app_name}/{stream_name}.flv
 */
$gateway = new \Xiaosongshu\Flv2mp4\manage\FlvGateway($port, $upstream);
/** Enable debug mode, prints logs when enabled */
$gateway->debug = true;
/** Start gateway */
$gateway->start();
```

### Static File Gateway

```php
require_once __DIR__ . '/vendor/autoload.php';
// ========== Start static file server ==========

$host = $argv[1] ?? '0.0.0.0';
$port = isset($argv[2]) ? (int)$argv[2] : 8100;
$documentRoot = $argv[3] ?? __DIR__;
$enableDirListing = isset($argv[4]) && $argv[4] === '--dir';

echo "========================================\n";
echo "  High Performance Static File Gateway\n";
echo "========================================\n";
echo "Usage: php fileGateway.php [host] [port] [document_root] [--dir]\n\n";

try {
    $server = new \Xiaosongshu\Flv2mp4\manage\StaticFileServer($host, $port, $documentRoot, $enableDirListing);
    $server->debug = true;
    $server->start();
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
```

## 🧪 Testing and Playback

- Generated regular MP4 files can be played directly with HTML5 `<video>` tag, refer to `index.html`
- Generated fMP4 segments are suitable for streaming playback, refer to `play_merge.html`
- Generated HLS segments support both VOD and live streaming, refer to `play.html`
- Merging HLS segments back to FLV supports VOD playback, refer to `flv.html`
- Transcoding MP3 to FLV supports VOD playback, refer to `flv.html`
- FLV Gateway supports live stream traffic forwarding with high concurrency, multi-level multi-node deployment

## 🔧 Background

Originally developed for the [xiaosongshu/rtmp_server](https://github.com/2723659854/rtmp-server) project, providing MP4/HLS recording and playback capabilities for live streams.

## ⚠️ Disclaimer

- Some code or materials in this project may reference content from the internet. If there are copyright concerns, please contact the author for removal.
- This project is for technical exchange and learning purposes only. Any legal risks, commercial disputes, or copyright issues arising from its use are the sole responsibility of the user.
- Please comply with local laws and regulations and use responsibly.

## 📧 Contact Author

Email: 2723659854@qq.com