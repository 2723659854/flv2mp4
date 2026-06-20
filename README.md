# FLV ↔ MP4 / HLS Conversion Tool

<p align="center">
  <a href="./README.cn.md"><strong>🇨🇳 中文</strong></a> •
  <a href="./README.md"><strong>🇬🇧 English</strong></a>
</p>

A lightweight media processing toolkit implemented in pure PHP 8.1+, with **zero external dependencies** (no FFmpeg required), supporting bidirectional conversion between FLV and MP4/HLS as well as live stream relay.

## 🎯 Core Features

| Feature | Direction | Description |
|------|------|------|
| Transcoding & Muxing | FLV → MP4 | Generate standard MP4 or separate fMP4 segments |
| Segment Distribution | FLV → HLS | Generate M3U8 + TS segments, compatible with players like hls.js/VLC |
| Reverse Restoration | HLS → FLV | Merge HLS segments back into FLV |
| Format Conversion | MP4 → FLV | Transcode MP4 files to FLV |
| Live Gateway | FLV Gateway | High-performance multi-level relay with high concurrency support |
| File Service | File Gateway | Lightweight HTTP file server |
| Push Client | FLV / MP4 / RTMP | Pseudo-live push of static files to RTMP servers |

## Environment Requirements

| Requirement | Description |
|--------|------|
| PHP | >= 8.1 (CLI mode only) |
| `sockets` extension | **Required**, provides underlying Socket communication capabilities |

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

// 1. FLV → MP4
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

### FLV Live Gateway

Supports multi-level cascading deployment for high-concurrency live stream relay.

```php
<?php

require_once __DIR__ . '/vendor/autoload.php';

$gateway = new \Xiaosongshu\Flv2mp4\manage\FlvGateway(8080, 'http://127.0.0.1:8501');
$gateway->debug = true;
$gateway->start();
```

```bash
# Level-1 gateway (direct to origin)
php gateway.php 8080 http://127.0.0.1:8501

# Level-2 gateway (proxy to level-1)
php gateway.php 8081 http://127.0.0.1:8080

# Playback URL: http://127.0.0.1:8081/{app}/{stream}.flv
```

### Static File Gateway

High-performance HTTP file server with directory listing support.

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

```bash
php file_server.php
```

### Push Client

> ⚠️ **Important**: Use [OBS](https://obsproject.com/) or [FFmpeg](https://ffmpeg.org/) for production streaming. The PHP push client provided by this toolkit is for learning and testing purposes only.

#### Command-Line Arguments

| Argument | Description | Default |
|------|------|--------|
| `file` | Path to FLV / MP4 source file | **Required** |
| `push_url` | Push destination address (HTTP / WS / RTMP) | `http://127.0.0.1:8501/live/stream` |
| `speed` | Push speed multiplier (0.1 – 10.0) | `1.0` |
| `--no-reconnect` | Disable automatic reconnection | Reconnection enabled by default |

#### HTTP / WebSocket Push

```php
<?php

require_once __DIR__ . '/vendor/autoload.php';

$pusher = new \Xiaosongshu\Flv2mp4\manage\FLVPusherAll(
    flvFile: 'test.flv',
    pushUrl: 'http://127.0.0.1:8501/live/stream',
    speed: 1.0,
    autoReconnect: true
);
$pusher->start();
```

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

```php
// MP4 push
$pusher = new \Xiaosongshu\Flv2mp4\Manage\Mp4PusherAll(
    mp4File: 'test.mp4',
    pushUrl: 'http://127.0.0.1:8501/live/stream',
    speed: 2.0
);
$pusher->start();
```

```bash
php mp4_pusher.php test.mp4 http://127.0.0.1:8501/live/stream 2.0
```

#### RTMP Push

```php
<?php

require_once __DIR__ . '/vendor/autoload.php';

use Xiaosongshu\Flv2mp4\SabreAMF\RtmpPushFlvClient;
use Xiaosongshu\Flv2mp4\SabreAMF\RtmpPushMp4Client;

ini_set('memory_limit', '2048M');

$filePath = $argv[1];
$rtmpUrl  = $argv[2] ?? 'rtmp://127.0.0.1:1935/live/stream';
$speed    = (float) ($argv[3] ?? 1.0);
$autoReconnect = !in_array('--no-reconnect', $argv);

$extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

$pusher = match ($extension) {
    'mp4' => new RtmpPushMp4Client($filePath, $rtmpUrl, $speed, $autoReconnect),
    default => new RtmpPushFlvClient($filePath, $rtmpUrl, $speed, $autoReconnect),
};

$pusher->start();
```

```bash
# FLV RTMP push
php rtmp_pusher.php test.flv rtmp://127.0.0.1:1935/live/stream

# MP4 RTMP push
php rtmp_pusher.php test.mp4 rtmp://127.0.0.1:1935/live/stream 2.0

# Disable auto-reconnect
php rtmp_pusher.php test.flv rtmp://127.0.0.1:1935/live/stream 1.0 --no-reconnect
```

### Recommended Streaming Tools (Production)

```bash
# FFmpeg push (lossless)
ffmpeg -re -i test.flv -c copy -f flv rtmp://server/live/stream

# FFmpeg push (re-encode to H.264 + AAC)
ffmpeg -re -i test.flv -c:v libx264 -c:a aac -f flv rtmp://server/live/stream

# OBS Studio push
# GUI-based, supports RTMP / FLV push, easy configuration, feature-rich
```

## 🧪 Testing & Playback

| Output Format | Recommended Player | Reference File |
|----------|-----------|----------|
| Standard MP4 | HTML5 `<video>` | `index.html` |
| fMP4 Segments | MSE Player | `play_merge.html` |
| HLS (TS) | hls.js / Safari | `play.html` |
| Merged FLV | flv.js | `flv.html` |

## 🎯 Use Cases

- **Live Recording** — Real-time transcoding of RTMP live streams into MP4 / HLS
- **Video Playback** — On-demand playback of recorded streams anytime
- **Stream Relay** — Multi-level gateway for load balancing and edge acceleration
- **Offline Batch Processing** — Batch conversion of FLV file formats
- **Pseudo-Live Push** — Stream VOD files as live streams (for testing)

## 🔧 Technical Background

This project is a companion toolkit for [xiaosongshu/rtmp_server](https://github.com/2723659854/rtmp-server), providing live stream recording and playback capabilities.

- Implemented in pure PHP 8.1+, **no FFmpeg dependency**
- Strict type declarations (`declare(strict_types=1)`)
- Recommended to use with [PHPStan](https://phpstan.org/) Level 8 for static analysis

## ⚠️ Disclaimer

- This toolkit is for technical exchange and learning purposes only
- Users assume all legal risks, commercial disputes, or copyright issues
- Please comply with local laws and regulations and use responsibly

## 📧 Contact

- 📬 Email: 2723659854@qq.com
- 🐙 GitHub: [2723659854](https://github.com/2723659854)