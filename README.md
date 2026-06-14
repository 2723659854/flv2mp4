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

A lightweight media processing toolkit written in pure PHP 8.1+, **zero external dependencies** (no FFmpeg required), supporting bidirectional conversion between FLV and MP4/HLS, as well as live stream forwarding.

## 🎯 Features

| Feature | Direction | Description |
|---------|-----------|-------------|
| Transmux | FLV → MP4 | Generate standard MP4 or fMP4 segments |
| Segment & Distribute | FLV → HLS | Generate M3U8 + TS segments |
| Reverse Restore | HLS → FLV | Merge HLS segments back to FLV |
| Format Conversion | MP4 → FLV | Convert MP4 files back to FLV |
| Live Gateway | FLV Gateway | High-performance multi-level forwarding with concurrency support |
| File Service | File Gateway | Lightweight HTTP file server |
| Push Client | FLV/MP4 Pusher | Static file pseudo-live push to RTMP server |

## 📦 Requirements

- **PHP >= 8.1**
- Recommended: `ext-pcntl` (for signal handling, optional)
- No FFmpeg or other external dependencies

## 🚀 Installation

```bash
composer require xiaosongshu/flv2mp4
```

## 📚 Quick Start

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

## 🌐 Advanced Features

### 1. FLV Live Gateway

Supports multi-level cascading deployment for high-concurrency live stream forwarding.

**Create gateway script** (e.g., `gateway.php`):

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

$gateway = new \Xiaosongshu\Flv2mp4\manage\FlvGateway(8080, 'http://127.0.0.1:8501');
$gateway->debug = true;
$gateway->start();
```

**Start the service**:

```bash
php gateway.php
```

**Multi-level deployment example**:

```bash
# Level 1 gateway (direct connection to origin)
php gateway.php 8080 http://127.0.0.1:8501

# Level 2 gateway (proxies level 1)
php gateway.php 8081 http://127.0.0.1:8080

# Play URL: http://127.0.0.1:8081/{app}/{stream}.flv
```

### 2. Static File Gateway

High-performance HTTP file server with directory listing support.

**Create file server script** (e.g., `file_server.php`):

```php
<?php
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

**Start the service**:

```bash
php file_server.php
```

### 3. FLV Push Client

> ⚠️ **Important Note**: It is recommended to use **OBS** or **FFmpeg** for streaming, as they are industry-standard tools that are stable and reliable. The PHP push client in this toolkit is for learning purposes only and is not recommended for production use.

Accurate timestamp-based pseudo-live streaming, with auto-reconnect and speed control.

**Create push script** (e.g., `flv_pusher.php`):

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    die("This script can only be run from command line.\n");
}

if ($argc < 2) {
    echo "Usage: php flv_pusher.php <flv_file> [push_url] [speed] [--no-reconnect]\n";
    echo "Examples:\n";
    echo "  php flv_pusher.php test.flv\n";
    echo "  php flv_pusher.php test.flv http://127.0.0.1:8501/live/stream\n";
    echo "  php flv_pusher.php test.flv ws://127.0.0.1:8501/live/stream\n";
    echo "  php flv_pusher.php test.flv http://127.0.0.1:8501/live/stream 2.0\n";
    echo "  php flv_pusher.php test.flv http://127.0.0.1:8501/live/stream 1.0 --no-reconnect\n";
    exit(1);
}

$flvFile = $argv[1];
$pushUrl = $argv[2] ?? 'http://127.0.0.1:8501/live/stream';
$speed = isset($argv[3]) ? (float)$argv[3] : 1.0;
$autoReconnect = !in_array('--no-reconnect', $argv);

$pusher = new \Xiaosongshu\Flv2mp4\manage\FLVPusherAll($flvFile, $pushUrl, $speed, $autoReconnect);
$pusher->start();
```

**Command line usage**:

| Parameter | Description | Default |
|-----------|-------------|---------|
| `flv_file` | FLV source file path | **Required** |
| `push_url` | Push target URL (supports http / ws) | `http://127.0.0.1:8501/live/stream` |
| `speed` | Push speed multiplier (0.1–10.0) | `1.0` |
| `--no-reconnect` | Disable auto-reconnect | Off (auto-reconnect enabled by default) |

```bash
# HTTP push
php flv_pusher.php test.flv http://127.0.0.1:8501/live/stream

# WebSocket push
php flv_pusher.php test.flv ws://127.0.0.1:8501/live/stream

# 2x speed push
php flv_pusher.php test.flv http://127.0.0.1:8501/live/stream 2.0

# Disable auto-reconnect
php flv_pusher.php test.flv http://127.0.0.1:8501/live/stream 1.0 --no-reconnect
```

### 4. MP4 Push Client

> ⚠️ **Important Note**: It is recommended to use **OBS** or **FFmpeg** for streaming. The PHP push client in this toolkit is for learning purposes only.

**Create push script** (e.g., `mp4_pusher.php`):

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    die("This script can only be run from command line.\n");
}

if ($argc < 2) {
    echo "Usage: php mp4_pusher.php <mp4_file> [push_url] [speed] [--no-reconnect]\n";
    echo "Examples:\n";
    echo "  php mp4_pusher.php test.mp4\n";
    echo "  php mp4_pusher.php test.mp4 http://127.0.0.1:8501/live/stream\n";
    echo "  php mp4_pusher.php test.mp4 ws://127.0.0.1:8501/live/stream\n";
    echo "  php mp4_pusher.php test.mp4 http://127.0.0.1:8501/live/stream 2.0\n";
    exit(1);
}

$mp4File = $argv[1];
$pushUrl = $argv[2] ?? 'http://127.0.0.1:8501/live/stream';
$speed = isset($argv[3]) ? (float)$argv[3] : 1.0;
$autoReconnect = !in_array('--no-reconnect', $argv);

$pusher = new \Xiaosongshu\Flv2mp4\Manage\Mp4PusherAll($mp4File, $pushUrl, $speed, $autoReconnect);
$pusher->start();
```

```bash
# HTTP push
php mp4_pusher.php test.mp4 http://127.0.0.1:8501/live/stream

# WebSocket push
php mp4_pusher.php test.mp4 ws://127.0.0.1:8501/live/stream

# 2x speed push
php mp4_pusher.php test.mp4 http://127.0.0.1:8501/live/stream 2.0
```

### 5. Recommended Standard Streaming Tools

For production environments, please use the following standard tools:

```bash
# FFmpeg push example
ffmpeg -re -i test.flv -c copy -f flv rtmp://server/live/stream

# Re-encode to standard format (H.264 + AAC)
ffmpeg -re -i test.flv -c:v libx264 -c:a aac -f flv rtmp://server/live/stream

# OBS Studio
# GUI tool supporting RTMP/FLV push, easy configuration, powerful features
```

## 🧪 Testing & Playback

| Output Format | Recommended Player | Reference File |
|---------------|-------------------|----------------|
| Standard MP4 | HTML5 `<video>` tag | `index.html` |
| fMP4 Segments | MSE Player | `play_merge.html` |
| HLS (TS) | hls.js / Safari | `play.html` |
| Merged FLV | flv.js | `flv.html` |

## 🎯 Use Cases

- **Live Recording**: Real-time RTMP live stream recording to MP4/HLS
- **Video Playback**: On-demand playback of recorded streams
- **Stream Distribution**: Multi-level gateway for load balancing and edge acceleration
- **Offline Batch Processing**: Batch conversion of FLV files
- **Pseudo-live Streaming**: Simulate live streaming from VOD files (testing only)

## 🔧 Technical Background

This project was originally developed as a companion tool for [xiaosongshu/rtmp_server](https://github.com/2723659854/rtmp-server), providing live stream recording and playback capabilities.

- Pure PHP 8.1+ implementation, **no FFmpeg dependency**
- Strict type declarations (`declare(strict_types=1)`)
- Recommended to use with PHPStan Level 8 for static analysis

## ⚠️ Disclaimer

- For technical exchange and learning purposes only
- Users assume all legal risks, commercial disputes, or copyright issues arising from use
- Please comply with local laws and regulations and use responsibly

## 📧 Contact

- 📬 Email: 2723659854@qq.com
- 🐙 GitHub: [2723659854](https://github.com/2723659854)