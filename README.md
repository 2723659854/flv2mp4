# FLV ↔ MP4 / HLS Conversion Toolkit + H264 Re-encoding Tool

<p align="center">
<img src="https://img.shields.io/badge/PHP-8.1%2B-blue" />
<img src="https://img.shields.io/badge/License-Apache%202.0-green" />
<img src="https://img.shields.io/badge/Code-PHPStan%20Level8-purple" />
<img src="https://img.shields.io/badge/No-FFmpeg-red" />
</p>

<p align="center">
  <a href="./README.cn.md"><strong>🇨🇳 中文</strong></a> •
  <a href="./README.md"><strong>🇬🇧 English</strong></a>
</p>

## Project Overview

A lightweight media processing toolkit implemented in pure PHP 8.1+, **zero external dependencies (no FFmpeg required)**.  
Supports FLV, FMP4, MP4, HLS interconversion, live streaming gateway, pushing, pulling, relaying, and **H.264 decoding + scaling + re-encoding** (Baseline Profile).

---

## 📋 Table of Contents

- [Project Overview](#project-overview)
- [Core Features](#-core-features)
- [Requirements](#requirements)
- [Installation](#-installation)
- [Quick Start](#-quick-start)
- [Advanced Features](#-advanced-features)
    - [FLV Live Gateway](#flv-live-gateway)
    - [Static File Gateway](#static-file-gateway)
    - [Push Client](#push-client)
    - [Pull Client](#pull-client)
    - [Live Relay](#live-relay)
- [Testing & Playback](#-testing--playback)
- [Use Cases](#-use-cases)
- [H.264 Re-encoding Deep Dive](#-h264-decoding--scaling--re-encoding)
    - [FLV → HLS Multi-bitrate Example](#flv-hls)
    - [FLV → FLV Re-encoding Example](#flv-flv)
    - [MP4 → MP4 Re-encoding Example](#mp4-mp4)
    - [Watermark Generation Tool](#watermark-generation-tool)
- [Technical Notes](#-technical-notes)
- [License & Disclaimer](#license--disclaimer)
- [Contact](#-contact)

---

## 🎯 Core Features

| Feature | Direction | Description |
|---------|-----------|-------------|
| Container Conversion | FLV ↔ MP4 / FMP4 | Generate standard MP4 or separate fMP4 segments (MSE-compatible) |
| HLS Packaging | FLV → HLS | Generate M3U8 + TS segments, compatible with hls.js, VLC, etc. |
| HLS Reversal | HLS → FLV | Merge HLS segments back into a single FLV file |
| MP4 ↔ FLV | MP4 → FLV / FMP4 → FLV | Multi-container format interconversion |
| Live Gateway | FLV Gateway | High-performance multi-level forwarding with high concurrency support |
| Static File Service | HTTP File Gateway | Lightweight file server with directory listing support |
| Push Client | FLV / MP4 → RTMP/HTTP-FLV/WS-FLV | Push static files as pseudo-live streams |
| Pull Client | RTMP/HTTP-FLV/WS-FLV → FLV | Pull live streams and save as local FLV files |
| Relay Client | Multi-protocol input → Multi-protocol output | One pull, multiple push targets |
| **H.264 Re-encoding** | Decode → Scale → Encode | Baseline Profile support, core engine for multi-bitrate HLS |

---

## Requirements

| Dependency | Description |
|------------|-------------|
| PHP | ≥ 8.1 (**CLI mode only**) |
| `sockets` extension | **Required**, provides underlying Socket communication |
| `gd` extension | **Optional**, used for generating watermarks from PNG/JPG images. If not installed, it will automatically fallback to built-in bitmap font mode. |

💡 **No FFmpeg, no third-party binaries — all pure PHP.**

---

## 🚀 Installation

```bash
composer require xiaosongshu/flv2mp4
```

---

## 📚 Quick Start

```php
<?php

declare(strict_types=1);
require_once __DIR__ . '/vendor/autoload.php';

ini_set('memory_limit', '512M');

$file = __DIR__ . '/test.flv';

// 1. FLV → mixed fMP4 segments
\Xiaosongshu\Flv2mp4\Client::runFlv2Fmp4Mixed($file, __DIR__ . '/output_merge');

// 2. FLV → separate fMP4 segments (audio/video independent)
\Xiaosongshu\Flv2mp4\Client::runFlv2Fmp4Separate($file, __DIR__ . '/output_separate');

// 3. FLV → HLS
\Xiaosongshu\Flv2mp4\Client::runFlv2Hls($file, __DIR__ . '/hls');

// 4. HLS → FLV
\Xiaosongshu\Flv2mp4\Client::runHls2Flv(__DIR__ . '/hls/index.m3u8', __DIR__ . '/output.flv');

// 5. MP4 → FLV
\Xiaosongshu\Flv2mp4\Client::runMp42Flv(__DIR__ . '/test.mp4', __DIR__ . '/output.flv');

// 6. FLV → MP4
\Xiaosongshu\Flv2mp4\Client::runFlv2Mp4($file, __DIR__ . '/output.mp4');

// 7. fMP4 → FLV (supports both mixed and separate)
\Xiaosongshu\Flv2mp4\Client::runFmp42Flv(__DIR__ . '/output_merge/index.m3u8', __DIR__ . '/output.flv');
```

---

## 🌐 Advanced Features

### FLV Live Gateway

Supports multi-level proxy deployment for high-concurrency live stream forwarding. Create a new file `flvGateway.php` with the following content:

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';
$gateway = new \Xiaosongshu\Flv2mp4\manage\FlvGateway(8080, 'http://127.0.0.1:8501');
$gateway->debug = true;
$gateway->start();
```

Start the FLV gateway:
```bash
php flvGateway.php
```

### Static File Gateway

Lightweight HTTP file server with directory listing toggle. Create a new file `fileGateway.php` with the following content:

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';
$server = new \Xiaosongshu\Flv2mp4\manage\FileGateway( '0.0.0.0',8100,__DIR__,false);
$server->debug = true;
$server->start();
```

Start the file gateway:
```bash
php fileGateway.php
```

### Push Client

Supports HTTP-FLV, WS-FLV, RTMP protocols with speed control and auto-reconnect. Create a new file `pusher.php` with the following content:

```php
require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '2048M');
$pusher = new \Xiaosongshu\Flv2mp4\Manage\PusherManage(__DIR__."/test.flv", "http://127.0.0.1:8501/live/stream", 1.0, false);
$pusher->start();
```

Start pushing:
```bash
php pusher.php
```

### Pull Client

Pull live streams and save as local FLV files, suitable for recording or debugging. Create a new file `puller.php` with the following content:

```php
require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '2048M');
$puller = new \Xiaosongshu\Flv2mp4\Manage\PullerManage("ws://127.0.0.1:8501/live/stream.flv", __DIR__."/pull_record.flv", 0, false);
$puller->start();
```

Start the pull client:
```bash
php puller.php
```

### Live Relay

Pull one stream and forward to multiple targets simultaneously (supports mixed protocols). Create a new file `forward.php` with the following content:

```php
require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '2048M');
$forwarder = new \Xiaosongshu\Flv2mp4\Flv\FlvForwardClient("http://127.0.0.1:8501/a/b.flv", ["rtmp://127.0.0.1:1935/c/d","ws://127.0.0.1:8501/c/e"], 0, true);
$forwarder->start();
```

Start the relay client:
```bash
php forward.php
```

---

## 🧪 Testing & Playback

| Output Format | Recommended Player | Reference File |
|---------------|--------------------|----------------|
| MP4 | HTML5 `<video>` | `index.html` |
| fMP4 | MSE Player | `play_merge.html`、`mse.html` |
| HLS (TS) | hls.js / Safari | `play.html` |
| FLV | flv.js | `flv.html` |
| FLV | Web push test | `push.html` |

---

## 🎯 Use Cases

- **Live Recording**: RTMP/FLV live streams saved as fMP4 / HLS in real-time
- **Video Playback**: On-demand playback of recorded streams
- **Stream Relaying**: Multi-level gateways for load balancing and edge acceleration
- **Offline Batch Processing**: Batch conversion of FLV / MP4 formats
- **Pseudo-live Streaming**: Push VOD files as live streams
- **Cross-platform Relaying**: One pull, multiple push destinations
- **Multi-bitrate HLS**: Pure PHP H.264 re-encoding for adaptive bitrate HLS

---

## 🔥 H.264 Decoding + Scaling + Re-encoding

Supports Baseline Profile H.264 decoding, scaling, and re-encoding, providing core capabilities for the following scenarios:

| Use Case | Description |
|----------|-------------|
| **Multi-bitrate HLS** | Transcode a single FLV into multi-resolution HLS segments (adaptive bitrate) |
| **FLV Re-encoding** | Modify resolution/bitrate and re-encode to FLV |
| **MP4 Re-encoding** | Modify resolution/bitrate and re-encode to MP4 |
| **Format Conversion** | Re-encode during FLV ↔ MP4 conversion (not just remuxing) |
| **Watermark Overlay** | Decode YUV → overlay PNG/text watermark → re-encode output |
| **Quality Enhancement** | Apply filters (sharpening, denoising, etc.) after decoding → re-encode |
| **Resolution Adaptation** | Downsample high-resolution video to multiple output resolutions |
| **Bitrate Control** | Re-encode high-bitrate video to a specified target bitrate |

**Technical Positioning**: This is a complete **H.264 pixel processing pipeline** (Decode → Process → Encode), implemented in pure PHP with no FFmpeg dependency.

---

#### FLV → HLS

Below is a FLV to multi-bitrate HLS example:

```php
<?php

require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '2048M');
$profiles = [
    // Configure different bitrates separately
    '240p' => [
        'width' => 426,      // or 424, keep 16:9 ratio
        'height' => 240,
        'bitrate' => 300000, // 300 Kbps (video bitrate)
        'fps' => 24,
        'audioBitrate' => 48000, // 48 Kbps
        'qp' => 30,          // Keep 30 for stability
        'watermark'=>true,     // Whether to add watermark
        'watermark_file'=> __DIR__."/src/Static/watermark_80x16.yuv",// Watermark file
    ]
];
$generator = new \Xiaosongshu\Flv2mp4\Recode\PurePhpHlsGenerator($profiles, __DIR__ . '/hls/output');
$generator->processFlv(__DIR__ . '/input.flv');
echo "Index URL: hls/output/master.m3u8\n";
echo "All processing complete!\n";
```

#### FLV → FLV

Re-encode FLV with new bitrate settings:

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '2048M');

$config = [
    'width' => 320,        // Target width, 0 = keep original resolution
    'height' => 180,       // Target height, 0 = keep original resolution
    'bitrate' => 150000,   // Target bitrate (bps), 0 = use QP mode
    'fps' => 15,           // Target framerate
    'qp' => 30,            // QP quality parameter (takes effect when bitrate is 0)
    'watermark'=>true,     // Whether to add watermark
    'watermark_file'=> __DIR__."/src/Static/watermark_80x16.yuv",// Watermark file
];

$recoder = new \Xiaosongshu\Flv2mp4\Recode\FlvRecoder($config);
$recoder->setMaxFrames(50);  // Optional: limit the number of frames to process
$recoder->processFlv(__DIR__ . '/input.flv', __DIR__.'/output.flv');
echo "FLV re-encoding complete\n";
```

#### MP4 → MP4

Re-encode MP4 file with new bitrate settings:

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '2048M');

$config = [
    'width' => 320,        // Target width, 0 = keep original resolution
    'height' => 180,       // Target height, 0 = keep original resolution
    'bitrate' => 150000,   // Target bitrate (bps), 0 = use QP mode
    'fps' => 15,           // Target framerate
    'qp' => 30,            // QP quality parameter (takes effect when bitrate is 0)
    'watermark'=>true,     // Whether to add watermark
    'watermark_file'=> __DIR__."/src/Static/watermark_80x16.yuv",// Watermark file
];
$recoder = new \Xiaosongshu\Flv2mp4\Recode\Mp4Recoder($config);
$recoder->setMaxFrames(50); // Optional: limit the number of frames to process
$recoder->processMp4(__DIR__ . '/input.mp4', __DIR__ . '/output.mp4');
echo "MP4 re-encoding complete\n";
```

- When adding a watermark, a YUV format file is required. The filename must include the watermark dimensions (e.g., `watermark_{width}x{height}.yuv`). The tool will automatically parse the width and height from the filename (e.g., `watermark_80x16.yuv` → width 80, height 16). Please ensure the filename format is correct.
- The re-encoding module provides a **YUV pixel-level operation interface**. You can build custom features on top of it, such as adding subtitles, picture-in-picture, video splicing, etc.
- For detailed H.264 usage instructions, please refer to the <a href="./src/Codec/README.md">README</a>.

---

### Supported Re-encoding Features

- [x] **I-frame Decoding & Encoding** (fully precise, INF dB)
- [x] **P-frame Decoding & Encoding** (Baseline Profile)
- [x] **Intra Prediction**: 4x4 (9 modes) + 16x16 (4 modes)
- [x] **Inter Prediction**: P-frame motion estimation (Diamond Search optimized)
- [x] **1/4-pixel Precision**: 6-tap filter interpolation
- [x] **CAVLC Entropy Coding** (Baseline Profile)
- [x] **Resolution Scaling** (YUV scaling after decoding → re-encoding)
- [x] **Bitrate Control** (via QP parameter adjustment)
- [ ] **B-frame Support** (planned, requires extension to Main Profile and bidirectional prediction implementation)
- [ ] **CABAC Entropy Coding** (planned, for Main Profile support)

> ⚠️ **Performance Notice**: The current H.264 re-encoding module is implemented in pure PHP and is suitable for **short-duration videos (recommended ≤ 10 seconds)** offline processing or functional verification. For long videos or high-resolution transcoding, it is recommended to use FFmpeg or other professional tools.

---

### Watermark Generation Tool

This project provides PHP functions for generating watermark YUV files. GD extension is preferred, with automatic fallback to bitmap font when GD is unavailable.

- `generateFromText()`: Generate text watermark YUV. GD extension preferred, falls back to bitmap font when GD is unavailable. **Built-in bitmap font only supports ASCII characters (English letters, numbers, and English punctuation).**
- `generateFromImage()`: Generate watermark YUV from an image. Requires GD extension, supports PNG/JPG.

#### Generate Watermark File from Text

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Xiaosongshu\Flv2mp4\Codec\WatermarkUtil;

echo "=== Testing WatermarkUtil ===\n\n";

// Test 1: Generate text watermark
echo "1. Generating text watermark (xiaosongshu, 80x16)...\n";
$outputFile1 = __DIR__ . '/test_wm_text.yuv';
$start = microtime(true);
$result = WatermarkUtil::generateFromText(
    'xiaosongshu',
    $outputFile1,
    80,
    16,

    [
        'fontSize' => 5, // Built-in font size 1-5. `fontSize` ranges from 1-5 (larger number = larger font). Built-in bitmap font only supports ASCII characters.
        'fontColor' => [255, 255, 255],
        'bgColor' => [0, 0, 0],
    ]
);
$cost = round(microtime(true) - $start, 3);
if ($result && file_exists($outputFile1)) {
    $size = filesize($outputFile1);
    $expectedSize = 80 * 16 + (80 * 16 >> 1);
    echo "   Success! File size: {$size} bytes (expected: {$expectedSize}) - Time: {$cost}s\n";
    if ($size === $expectedSize) {
        echo "   ✅ File size correct\n";
    } else {
        echo "   ❌ File size mismatch\n";
    }
} else {
    echo "   ❌ Generation failed\n";
}
```

#### Generate Watermark File from Image

- Requires PHP GD extension installed.

```php
<?php

require_once __DIR__ . '/vendor/autoload.php';

use Xiaosongshu\Flv2mp4\Codec\WatermarkUtil;

echo "=== Testing WatermarkUtil ===\n\n";

// Test 1: Generate watermark from image
echo "1. Generating watermark from image (xiaosongshu, 80x16)...\n";
$outputFile1 = __DIR__ . '/test_wm_copy_80x16.yuv';
$start = microtime(true);
$result = WatermarkUtil::generateFromImage(
    __DIR__."/watermark_80x16.png",
    $outputFile1,
    80,
    16,
);
$cost = round(microtime(true) - $start, 3);
if ($result && file_exists($outputFile1)) {
    $size = filesize($outputFile1);
    $expectedSize = 80 * 16 + (80 * 16 >> 1);
    echo "   Success! File size: {$size} bytes (expected: {$expectedSize}) - Time: {$cost}s\n";
    if ($size === $expectedSize) {
        echo "   ✅ File size correct\n";
    } else {
        echo "   ❌ File size mismatch\n";
    }
} else {
    echo "   ❌ Generation failed\n";
}
```

## 🔧 Technical Notes

- Pure PHP 8.1+ implementation, no FFmpeg dependency
- This project was originally created primarily to serve the [xiaosongshu/rtmp_server](https://github.com/2723659854/rtmp-server) project
- Recommended static analysis with [PHPStan](https://phpstan.org/) Level 8

## License & Disclaimer

This project is open-sourced under the [Apache License 2.0](http://www.apache.org/licenses/LICENSE-2.0). You are free to use, modify, and distribute the code, including for commercial purposes.  
The code is provided "AS IS", without warranty of any kind, express or implied. The author shall not be liable for any damages arising from the use of this software.

---

## 📧 Contact

- 📬 Email: 2723659854@qq.com
- 🐙 GitHub: [2723659854](https://github.com/2723659854)