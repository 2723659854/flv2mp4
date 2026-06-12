# FLV ↔ MP4 / HLS Converter

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

A lightweight media processing toolkit written in pure PHP 8.1+ with **zero external dependencies**, supporting bidirectional conversion between FLV, MP4, and HLS.

| Feature | Direction | Description |
|---------|-----------|-------------|
| 🎬 Remux | FLV → MP4 | Generate standard MP4 or fMP4 segments |
| 📺 Segment | FLV → HLS | Generate M3U8 + TS segments |
| 🔄 Reconstruct | HLS → FLV | Merge HLS segments back to FLV |
| 🔁 Convert | MP4 → FLV | Transcode MP4 file back to FLV |
| 🌐 Live Gateway | FLV Gateway | High-performance multi-level forwarding, high concurrency support |
| 📁 File Service | File Gateway | Lightweight HTTP file server |
| 📤 Push Client | FLV/MP4 Pusher | Simulate live streaming from static files to RTMP server |

> Ideal for video recording, playback, live forwarding, and works great with RTMP live streaming servers.

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

// 1. FLV → merged MP4
$result = \Xiaosongshu\Flv2mp4\Client::runFlv2mp4($file, __DIR__ . '/output_merge');

// 2. FLV → separate audio/video fMP4 segments
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

Supports multi-level cascading deployment for high-concurrency live streaming.

**Create gateway script** (e.g., `gateway.php`):

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

$gateway = new \Xiaosongshu\Flv2mp4\manage\FlvGateway(8080, 'http://127.0.0.1:8501');
$gateway->debug = true;
$gateway->start();
```

**Multi-level deployment example**:

```bash
# Level 1 gateway (directly connected to origin)
php gateway.php 8080 http://127.0.0.1:8501

# Level 2 gateway (proxies level 1)
php gateway.php 8081 http://127.0.0.1:8080

# Level 3 gateway (proxies level 2)
php gateway.php 8082 http://127.0.0.1:8081

# Playback URL: http://127.0.0.1:8082/{app}/{stream}.flv
```

### 2. Static File Gateway

High-performance HTTP file server with directory listing support.

**Create file server script** (e.g., `file_server.php`):

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

### 3. FLV Push Client

Push static FLV files to an RTMP server with original timestamps (simulated live streaming).

**Features**:

- ✅ Accurate push with original timestamps (simulated live)
- ✅ Automatic reconnection
- ✅ Memory efficient (streaming read)
- ✅ Real-time progress reporting
- ✅ Speed control (0.5x / 1x / 2x)
- ✅ Signal handling (graceful shutdown)

**Create push script** (e.g., `flv_pusher.php`):

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '2048M');

if (PHP_SAPI !== 'cli') {
    die("This script can only be run from command line.\n");
}

if ($argc < 2) {
    echo "Usage: php " . basename($argv[0]) . " <flv_file> [push_url] [speed] [--no-reconnect]\n";
    echo "Examples:\n";
    echo "  php flv_pusher.php test.flv\n";
    echo "  php flv_pusher.php test.flv http://127.0.0.1:8501/live/stream\n";
    echo "  php flv_pusher.php test.flv http://127.0.0.1:8501/live/stream 2.0\n";
    echo "  php flv_pusher.php test.flv http://127.0.0.1:8501/live/stream 1.0 --no-reconnect\n";
    exit(1);
}

$flvFile = $argv[1];
$pushUrl = $argv[2] ?? 'http://127.0.0.1:8501/live/stream';
$speed = isset($argv[3]) ? (float)$argv[3] : 1.0;
$autoReconnect = !in_array('--no-reconnect', $argv);

$pusher = new \Xiaosongshu\Flv2mp4\manage\FLVPusher($flvFile, $pushUrl, $speed, $autoReconnect);
$pusher->start();
```

**Command line usage**:

| Argument | Description | Default |
|----------|-------------|---------|
| `flv_file` | FLV source file path | **Required** |
| `push_url` | Target push URL | `http://127.0.0.1:8501/live/stream` |
| `speed` | Push speed multiplier (0.1–10.0) | `1.0` |
| `--no-reconnect` | Disable auto-reconnect | Off (enabled by default) |

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

### 4. MP4 Push Client

Usage is identical to the FLV push client; just replace the file path.

**Example** (save as `mp4_pusher.php`):

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '2048M');

$mp4File = __DIR__ . "/test.mp4";
$pusher = new \Xiaosongshu\Flv2mp4\Manage\Mp4Pusher($mp4File, 'http://localhost:8501/a/b');
$pusher->start();
```

> Command line arguments are the same as the FLV pusher: `speed` and `--no-reconnect` are supported.

## 🧪 Testing & Playback

| Output Format | Recommended Player | Example File |
|---------------|--------------------|---------------|
| Standard MP4 | HTML5 `<video>` tag | `index.html` |
| fMP4 segments | MSE player | `play_merge.html` |
| HLS (TS) | hls.js / native Safari | `play.html` |
| Merged FLV | flv.js | `flv.html` |

## 🎯 Use Cases

- **Live Recording**: Save RTMP live streams as MP4/HLS in real time
- **Video Playback**: On-demand playback of recorded streams
- **Stream Distribution**: Multi-level gateways for load balancing and edge acceleration
- **Offline Batch Processing**: Batch convert FLV files
- **Simulated Live Streaming**: Push on-demand files as a live stream

## 🔧 Technical Background

This project was originally developed for [xiaosongshu/rtmp_server](https://github.com/2723659854/rtmp-server) to provide live stream recording and playback capabilities.

- Pure PHP 8.1+ implementation, **no FFmpeg or any external dependencies**
- Strict type declarations (`declare(strict_types=1)`)
- Recommended to use with PHPStan Level 8 for static analysis

## ⚠️ Disclaimer

- This project is for technical exchange and learning purposes only.
- The user assumes all legal risks, commercial disputes, or copyright issues arising from its use.
- Please comply with local laws and regulations and use responsibly.

## 📧 Contact

- 📬 Email: 2723659854@qq.com
- 🐙 GitHub: [2723659854](https://github.com/2723659854)