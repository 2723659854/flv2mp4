# FLV ↔ MP4 / HLS Conversion Toolkit

<p align="center">
  <a href="./README.cn.md"><strong>🇨🇳 中文</strong></a> •
  <a href="./README.md"><strong>🇬🇧 English</strong></a>
</p>

<p align="center">
  <img src="https://img.shields.io/badge/PHP-%3E%3D8.1-777BB4?logo=php&logoColor=white" alt="PHP Version">
  <img src="https://img.shields.io/badge/License-MIT-green.svg" alt="License">
  <img src="https://img.shields.io/badge/Dependencies-Zero-brightgreen.svg" alt="Dependencies">
  <img src="https://img.shields.io/badge/PHPStan-Level%208-blue" alt="PHPStan">
</p>

## 📖 Introduction

A lightweight media processing toolkit written in pure PHP 8.1+, with **zero external dependencies**, supporting bidirectional conversion between FLV and MP4/HLS.

| Feature | Direction | Description |
|---------|-----------|-------------|
| 🎬 Transcoding | FLV → MP4 | Generate standard MP4 or fMP4 segments |
| 📺 Segmentation | FLV → HLS | Generate M3U8 playlist + TS segments |
| 🔄 Reverse | HLS → FLV | Merge HLS segments back to FLV |
| 🔁 Interchange | MP4 → FLV | Transcode MP4 back to FLV |
| 🌐 Live Gateway | FLV Gateway | High-performance multi-tier relay for high concurrency |
| 📁 File Server | File Gateway | Lightweight HTTP file server |
| 📤 Stream Pusher | FLV Pusher | Push static FLV files as simulated live streams to RTMP server |

> Ideal for video recording, playback, and live stream relay scenarios. Works best with RTMP streaming servers.

## 📦 Requirements

- **PHP >= 8.1**
- `ext-pcntl` recommended (for signal handling, optional)
- No FFmpeg or other external dependencies required

## 🚀 Installation

```bash
composer require xiaosongshu/flv2mp4
```

## 📚 Quick Start

### Basic Conversion

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '512M');

$file = __DIR__ . '/test.flv';

// 1. FLV → Merged MP4
$result = \Xiaosongshu\Flv2mp4\Client::runFlv2mp4($file, __DIR__ . '/output_merge');

// 2. FLV → Separate audio/video fMP4 segments
$result = \Xiaosongshu\Flv2mp4\Client::runFlv2mp4Separate($file, __DIR__ . '/output_separate');

// 3. FLV → HLS
$result = \Xiaosongshu\Flv2mp4\Client::runFlv2Hls($file, __DIR__ . '/hls');

// 4. HLS → FLV
$result = \Xiaosongshu\Flv2mp4\Client::runHls2Flv($m3u8Path, __DIR__ . '/output.flv');

// 5. MP4 → FLV
$result = \Xiaosongshu\Flv2mp4\Client::runMp42Flv($mp4File, __DIR__ . '/output.flv');
```

### 🌐 FLV Live Stream Gateway

Supports multi-tier cascade deployment for high-concurrency live stream relay:

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
error_reporting(E_ALL);
set_time_limit(0);

// Listen port + upstream URL
$gateway = new \Xiaosongshu\Flv2mp4\manage\FlvGateway(8080, 'http://127.0.0.1:8501');
$gateway->debug = true;
$gateway->start();
```

**Multi-tier deployment example:**

```bash
# Tier 1 (direct to origin)
php gateway.php 8080 http://127.0.0.1:8501

# Tier 2 (proxy tier 1)
php gateway.php 8081 http://127.0.0.1:8080

# Tier 3 (proxy tier 2)
php gateway.php 8082 http://127.0.0.1:8081

# Playback URL: http://127.0.0.1:8082/{app}/{stream}.flv
```

### 📁 Static File Gateway

Built-in high-performance HTTP file server with optional directory listing:

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

$server = new \Xiaosongshu\Flv2mp4\manage\FileGateway(
    host: '0.0.0.0',
    port: 8100,
    documentRoot: __DIR__,
    enableDirListing: false
);
$server->debug = true;
$server->start();
```

### 📤 FLV Stream Pusher

Push static FLV files to RTMP server as simulated live streams with original timestamps. Supports automatic reconnection, multi-speed pushing, and real-time progress reporting.

**Features:**

- ✅ Precise timestamp-based pushing (simulated live)
- ✅ Automatic reconnection on disconnect
- ✅ Memory optimized (streaming read, no full file loading)
- ✅ Real-time progress reporting
- ✅ Adjustable playback speed (0.5x / 1x / 2x)
- ✅ Detailed logging output
- ✅ Signal handling (graceful shutdown)

**Full example:**

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '2048M');

// ============ CLI Entry Point ============

if (PHP_SAPI !== 'cli') {
    die("This script can only be run from command line.\n");
}

// Parse command line arguments
if ($argc < 2) {
    echo "Usage: php " . basename($argv[0]) . " <flv_file> [push_url] [speed] [--no-reconnect]\n";
    echo "\n";
    echo "Examples:\n";
    echo "  php flv_pusher.php test.flv\n";
    echo "  php flv_pusher.php test.flv http://127.0.0.1:8501/live/stream\n";
    echo "  php flv_pusher.php test.flv http://127.0.0.1:8501/live/stream 2.0\n";
    echo "  php flv_pusher.php test.flv http://127.0.0.1:8501/live/stream 1.0 --no-reconnect\n";
    echo "\n";
    echo "Options:\n";
    echo "  speed           Push speed multiplier (0.1-10.0, default: 1.0)\n";
    echo "  --no-reconnect  Disable automatic reconnection\n";
    exit(1);
}

$flvFile = $argv[1];
$pushUrl = $argv[2] ?? 'http://127.0.0.1:8501/live/stream';
$speed = isset($argv[3]) ? (float)$argv[3] : 1.0;
$autoReconnect = !in_array('--no-reconnect', $argv);

// Create pusher instance
$pusher = new \Xiaosongshu\Flv2mp4\manage\FLVPusher($flvFile, $pushUrl, $speed, $autoReconnect);

// Start pushing
$pusher->start();
```

**Command line usage:**

```bash
# Basic push (1x speed)
php flv_pusher.php test.flv http://127.0.0.1:8501/live/stream

# 2x speed push
php flv_pusher.php test.flv http://127.0.0.1:8501/live/stream 2.0

# 0.5x slow push
php flv_pusher.php test.flv http://127.0.0.1:8501/live/stream 0.5

# Disable auto-reconnect
php flv_pusher.php test.flv http://127.0.0.1:8501/live/stream 1.0 --no-reconnect
```

| Parameter | Description | Default |
|-----------|-------------|---------|
| `flv_file` | Path to FLV source file | *Required* |
| `push_url` | Push target URL | `http://127.0.0.1:8501/live/stream` |
| `speed` | Push speed multiplier (0.1 - 10.0) | `1.0` |
| `--no-reconnect` | Disable automatic reconnection | Off (reconnect enabled) |

## 🧪 Testing & Playback

| Output Format | Recommended Player | Reference File |
|---------------|-------------------|----------------|
| Standard MP4 | HTML5 `<video>` tag | `index.html` |
| fMP4 Segments | MSE-based player | `play_merge.html` |
| HLS (TS) | hls.js / Native Safari | `play.html` |
| Merged FLV | flv.js | `flv.html` |

## 🎯 Use Cases

- **Live Recording**: Real-time RTMP stream recording to MP4/HLS
- **Video Playback**: On-demand playback of recorded streams
- **Stream Distribution**: Load balancing and edge acceleration via multi-tier gateway
- **Batch Processing**: Offline batch conversion of FLV files
- **Simulated Live Push**: Push VOD files as simulated live streams

## 🔧 Technical Background

This project was originally developed for [xiaosongshu/rtmp_server](https://github.com/2723659854/rtmp-server) to provide recording and playback capabilities for live streams.

- Pure PHP 8.1+ implementation, **no FFmpeg or external dependencies required**
- Supports strict type declarations (`declare(strict_types=1)`)
- PHPStan Level 8 compatible for static analysis

## ⚠️ Disclaimer

- This project is intended for technical exchange and learning purposes only
- Any legal risks, commercial disputes, or copyright issues arising from its use shall be borne by the user
- Please comply with local laws and regulations and use responsibly

## 📧 Contact

- 📬 Email: 2723659854@qq.com
- 🐙 GitHub: [2723659854](https://github.com/2723659854)