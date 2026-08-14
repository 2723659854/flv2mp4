# FLV ↔ MP4 / HLS Converter + H264 Re-encoding + OPUS2AAC
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
A lightweight media processing toolkit implemented in pure PHP 8.1+, **with zero external dependencies (no FFmpeg required)**.  
Supports conversion between FLV, FMP4, MP4, and HLS, live streaming gateway, push, pull, relay, as well as **H.264 decoding + scaling + re-encoding** (Baseline Profile)+ OPUS→AAC.

---
## 📋 Table of Contents

- [Project Overview](#project-overview)
- [Core Features](#-core-features)
- [Environment Dependencies](#environment-dependencies)
- [Installation](#-installation)
- [Quick Start](#-quick-start)
- [Advanced Features](#-advanced-features)
    - [Opus 2 AAC](#opus-to-aac)
    - [FLV Live Gateway](#flv-live-gateway)
    - [Static File Gateway](#static-file-gateway)
    - [Push Client](#push-client)
    - [Pull Client](#pull-client)
    - [Live Relay](#live-relay)
- [Testing & Playback](#-testing--playback)
- [Use Cases](#-use-cases)
- [H.264 Re-encoding Details](#-h264-decoding--scaling--re-encoding)
    - [FLV → HLS Multi-bitrate Example](#flv-2-hls)
    - [FLV → FLV Re-encoding Example](#flv-2-flv)
    - [MP4 → MP4 Re-encoding Example](#mp4-2-mp4)
    - [Watermark Tools](#watermark-generation-tools)
- [Technical Notes](#-technical-notes)
- [License & Disclaimer](#open-source-license--disclaimer)
- [Contact](#-contact)

---


---

## 🎯 Core Features

| Feature | Direction | Description                                                           |
|---------|-----------|-----------------------------------------------------------------------|
| Container Conversion | FLV ↔ MP4 / FMP4 | Generate standard MP4 or separate fMP4 segments (MSE-compatible)      |
| HLS Segmentation | FLV → HLS | Generate M3U8 + TS segments, compatible with hls.js, VLC, etc.        |
| HLS Restoration | HLS → FLV | Merge HLS segments back into a single FLV file                        |
| MP4 ↔ FLV | MP4 → FLV / FMP4 → FLV | Convert between multiple container formats                            |
| Live Gateway | FLV Gateway | High-performance multi-level forwarding with high concurrency support |
| Static File Service | HTTP File Gateway | Lightweight file server with directory browsing support               |
| Push Client | FLV / MP4 → RTMP/HTTP-FLV/WS-FLV | Push static files as pseudo-live streams                              |
| Pull Client | RTMP/HTTP-FLV/WS-FLV → FLV | Pull from live streams and save as local FLV                          |
| Relay Client | Multi-protocol input → Multi-protocol output | Pull once, forward to multiple destinations                           |
| **H.264 Re-encoding** | Decode → Scale → Encode | Supports Baseline Profile, core support for multi-bitrate HLS         |
| **OPUS→AAC** | opus→pcm→aac | Convert WebRTC Opus audio to AAC-LC                                   |

---

## Environment Dependencies

| Dependency     | Description                                          |
|----------------|------------------------------------------------------|
| PHP            | ≥ 8.1 (**CLI mode only**)                            |
| `sockets` extension | **Required**, provides low-level socket communication |
| `gd` extension | **Optional**, used for generating watermarks from PNG/JPG images. If not installed, falls back to built-in bitmap font mode. |

💡 **No FFmpeg required, no third-party binaries, all pure PHP.**

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

// 1. FLV → multiplexed fMP4 segments
\Xiaosongshu\Flv2mp4\Client::runFlv2Fmp4Mixed($file, __DIR__ . '/output_merge');

// 2. FLV → separate fMP4 segments (audio/video separate)
\Xiaosongshu\Flv2mp4\Client::runFlv2Fmp4Separate($file, __DIR__ . '/output_separate');

// 3. FLV → HLS
\Xiaosongshu\Flv2mp4\Client::runFlv2Hls($file, __DIR__ . '/hls');

// 4. HLS → FLV
\Xiaosongshu\Flv2mp4\Client::runHls2Flv(__DIR__ . '/hls/index.m3u8', __DIR__ . '/output.flv');

// 5. MP4 → FLV
\Xiaosongshu\Flv2mp4\Client::runMp42Flv(__DIR__ . '/test.mp4', __DIR__ . '/output.flv');

// 6. FLV → MP4
\Xiaosongshu\Flv2mp4\Client::runFlv2Mp4($file, __DIR__ . '/output.mp4');

// 7. fMP4 → FLV (supports both multiplexed and separate)
\Xiaosongshu\Flv2mp4\Client::runFmp42Flv(__DIR__ . '/output_merge/index.m3u8', __DIR__ . '/output.flv');
```

---

## 🌐 Advanced Features

### Opus to AAC

`WebRtcFlvRelay` can receive WebRTC RTP data, encapsulate H.264 video into FLV, and transcode Opus audio to AAC-LC in real-time via a pure PHP Worker, then push it to a WebSocket-FLV service, which can continue recording or forwarding to targets like RTMP.

```php
<?php

require_once __DIR__ . '/vendor/autoload.php';

use Xiaosongshu\Flv2mp4\Flv\WebRtcFlvRelay;
use Xiaosongshu\Flv2mp4\Opus\OpusWorkerClient;

$clientId = 1;
$streamId = 'stream_001';
$opusWorkerPort = 8330;
$pushUrl = "ws://127.0.0.1:8501/live/{$streamId}";

$relay = new WebRtcFlvRelay(
    $clientId,
    $streamId,
    $pushUrl,
    null,
    null,
    $opusWorkerPort
);
$relay->connect();

// Call in the WebRTC server's RTP callback:
// $relay->pushRtp($plainRtp, 'video');
// $relay->pushRtp($plainRtp, 'audio');

// Close the relay when pushing ends, and shut down the auto-started Worker when the main process exits.
$relay->finish();
OpusWorkerClient::shutdownOwnedWorkers();
```

The `examples\webrtc.php` file in the project root provides a complete WebRTC to FLV example. Common configurations:

```php
// Each project instance should use a different Worker port.
$opusWorkerPort = 8330;

// Supports push URLs provided by RTMP, HTTP-FLV, and WebSocket-FLV services;
// Default example uses WebSocket-FLV, replacing the placeholder with streamId.
$wsFlvPushUrl = 'ws://127.0.0.1:8501/live/{streamId}';
```

Run the example:

```bash
php webrtc.php
```

Notes:

- The relay automatically starts `bin/opus-worker.php` when connecting (if no Worker exists on the target port); no manual Worker startup is required.
- The Worker listens only on `127.0.0.1`, default port `8330`;
- Auto-start passes the actual `vendor/autoload.php` of the host project to the Worker via `--autoload`, compatible with installation via `composer require xiaosongshu/flv2mp4` and custom `vendor-dir`;
- Default output is `48kHz`, mono, `64kbps` AAC-LC;
- A single Worker process can manage multiple independent connections, but pure PHP real-time transcoding consumes significant CPU; it is recommended to plan for one real-time stream per instance;
- When running multiple project instances on the same machine, each must use a different `$opusWorkerPort`;
- When the main process receives `Ctrl+C` or exits, `OpusWorkerClient::shutdownOwnedWorkers()` should be called; `start.php` includes the appropriate exit handling;
- PHP must allow `proc_open`, otherwise Worker child processes cannot be created automatically;
- The Worker queue includes bounded backpressure protection. Do not resolve performance issues solely by enlarging the queue, as this may increase audio latency and cause A/V desynchronization.
- The WebRTC service requires the `xiaosongshu/webrtc` package.

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

A lightweight HTTP file server with directory browsing toggle. Create a new file `fileGateway.php` with the following content:

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

Supports HTTP-FLV, WS-FLV, and RTMP protocols, with speed-controlled pushing and auto-reconnection. Create a new file `pusher.php` with the following content:
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

Pull from a live stream and save as a local FLV file, suitable for recording or debugging. Create a new file `puller.php` with the following content:
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

Pull once and forward to multiple target addresses (supports mixed protocols). Create a new file `forward.php` with the following content:
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

| Output Format | Recommended Player   | Reference File                  |
|---------------|----------------------|---------------------------------|
| MP4           | HTML5 `<video>`      | `index.html`                    |
| fMP4          | MSE Player           | `play_merge.html`, `mse.html`   |
| HLS (TS)      | hls.js / Safari      | `play.html`                     |
| FLV           | flv.js               | `flv.html`                      |
| FLV           | Web Push Test        | `push.html`                     |

---

## 🎯 Use Cases

- **Live Recording**: Real-time save RTMP/FLV live streams as fMP4 / HLS
- **Video Replay**: On-demand playback of recorded streams
- **Stream Forwarding**: Multi-level gateways for load balancing and edge acceleration
- **Offline Batch Processing**: Batch convert FLV / MP4 formats
- **Pseudo-live Streaming**: Push VOD files as live streams
- **Cross-platform Relay**: Pull once, forward to multiple platforms simultaneously
- **Multi-bitrate HLS**: Pure PHP H.264 re-encoding for adaptive bitrate HLS

---


### 🔥 H.264 Decoding + Scaling + Re-encoding

Supports Baseline Profile H.264 decoding, scaling, and re‑encoding, providing core capabilities for the following scenarios:

After installing via Composer, you do not need to manually run any Opus/HLS/FLV/MP4 Worker; the program will automatically start them using the current PHP CLI and the host's vendor/autoload.php. Multi‑process mode requires proc_open to be enabled; CLI environment is recommended.

| Use Case | Description |
|----------|-------------|
| **Multi-bitrate HLS** | Transcode a single FLV into multi-resolution HLS segments (adaptive bitrate) |
| **FLV Re-encoding** | Modify resolution/bitrate and re-encode as FLV |
| **MP4 Re-encoding** | Modify resolution/bitrate and re-encode as MP4 |
| **Format Conversion** | Re-encode during FLV ↔ MP4 conversion (rather than just remuxing) |
| **Watermark Overlay** | Decode YUV → overlay PNG/text watermark → re-encode |
| **Quality Enhancement** | Apply filters (sharpening, denoising, etc.) after decoding → re-encode |
| **Resolution Adaptation** | Downsample high-resolution videos to multiple tiers |
| **Bitrate Control** | Re-encode high-bitrate videos to target bitrates |

**Technical Positioning**: This is a complete **H.264 pixel processing pipeline** (Decode → Process → Encode), with no FFmpeg dependency, implemented in pure PHP.

---
####  FLV 2 HLS
Example of transcoding FLV to multi-bitrate HLS:
```php
<?php

require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '2048M');
$profiles = [
    // Configure different bitrates separately
    '240p' => [
        'width' => 426,      // or 424, just keep 16:9 ratio
        'height' => 240,
        'bitrate' => 300000, // 300 Kbps (video bitrate)
        'fps' => 24,
        'audioBitrate' => 48000, // 48 Kbps
        'qp' => 30,          // Keep 30 for stability
        'watermark'=>true,     // Whether to add watermark
        'watermark_file'=> __DIR__."/src/Static/watermark_80x16.yuv",// Watermark file
    ]
];
// If high quality re‑encoding is required, enable multi‑process acceleration; for low bitrates, it is not needed.
$generator = new \Xiaosongshu\Flv2mp4\Recode\PurePhpHlsGenerator($profiles, __DIR__ . '/hls/output',true);
$generator->processFlv(__DIR__ . '/input.flv');
echo "Index URL: hls/output/master.m3u8\n";
echo "All processing completed!\n";
```
#### FLV 2 FLV
Re-encode FLV with new bitrate:
```php
<?php
require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '2048M');

$config = [
    'width' => 320,        // Target width, 0 = keep original resolution
    'height' => 180,       // Target height, 0 = keep original resolution
    'bitrate' => 150000,   // Target bitrate (bps), 0 = use QP mode
    'fps' => 15,           // Target framerate
    'qp' => 30,            // QP quality parameter (used when bitrate is 0)
    'watermark'=>true,     // Whether to add watermark
    'watermark_file'=> __DIR__."/src/Static/watermark_80x16.yuv",// Watermark file
];
// If high quality re‑encoding is required, enable multi‑process acceleration; for low bitrates, it is not needed.
$recoder = new \Xiaosongshu\Flv2mp4\Recode\FlvRecoder($config,true);
$recoder->setMaxFrames(50);  // Optional: limit frames processed
$recoder->processFlv(__DIR__ . '/input.flv', __DIR__.'/output.flv');
echo "FLV re-encoding completed\r\n";
```
#### MP4 2 MP4
Re-encode MP4 file with new bitrate:
```php
<?php
require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '2048M');

$config = [
    'width' => 320,        // Target width, 0 = keep original resolution
    'height' => 180,       // Target height, 0 = keep original resolution
    'bitrate' => 150000,   // Target bitrate (bps), 0 = use QP mode
    'fps' => 15,           // Target framerate
    'qp' => 30,            // QP quality parameter (used when bitrate is 0)
    'watermark'=>true,     // Whether to add watermark
    'watermark_file'=> __DIR__."/src/Static/watermark_80x16.yuv",// Watermark file
];
// If high quality re‑encoding is required, enable multi‑process acceleration; for low bitrates, it is not needed.
$recoder = new \Xiaosongshu\Flv2mp4\Recode\Mp4Recoder($config,true);
$recoder->setMaxFrames(50); // Optional: limit frames processed
$recoder->processMp4(__DIR__ . '/input.mp4', __DIR__ . '/output.mp4');
echo "MP4 re-encoding completed\r\n";
```
- When adding a watermark, the YUV format file is required, and the file name must include the watermark's width and height as shown in the examples above (watermark_{width}x{height}.yuv). The tool automatically parses the width and height from the filename (e.g., `watermark_80x16.yuv` → width 80, height 16). Please ensure the filename format is accurate.
- The re-encoding module provides a **YUV pixel-level operation interface**, allowing you to implement custom features based on this, such as subtitle addition, picture-in-picture, video concatenation, etc.
- For detailed usage of H.264, see <a href="./src/Codec/README.md">README</a>.

---

### Supported Re-encoding Features

- [x] **I-frame decoding & encoding** (fully accurate, INF dB)
- [x] **P-frame decoding & encoding** (Baseline Profile)
- [x] **Intra Prediction**: 4x4 (9 modes) + 16x16 (4 modes)
- [x] **Inter Prediction**: P-frame motion estimation (diamond search optimized)
- [x] **1/4 Pixel Precision**: 6-tap filter interpolation
- [x] **CAVLC Entropy Coding** (Baseline Profile)
- [x] **Resolution Scaling** (YUV scaling after decoding → re-encode)
- [x] **Bitrate Control** (via QP parameter adjustment)
- [ ] **B-frame support** (planned, requires extending to Main Profile with bidirectional prediction)
- [ ] **CABAC Entropy Coding** (planned, Main Profile support)

> ⚠️ **Performance Note**: The current H.264 re-encoding module is implemented in pure PHP and is suitable for **short-duration videos (recommended ≤ 10 seconds)** for offline processing or functional verification. For long videos or high-resolution transcoding, professional tools like FFmpeg are recommended.

---

### Watermark Generation Tools
This project provides PHP functionality for generating watermark YUV files, with GD extension preferred, falling back to bitmap fonts when GD is unavailable.
- generateFromText()	Generates text watermark YUV, GD extension preferred, falls back to bitmap font when GD is unavailable. **The built-in bitmap font only supports ASCII characters (English letters, numbers, English punctuation).**
- generateFromImage()	Generates watermark YUV from an image, requires GD extension, supports PNG/JPG.

#### Generating Watermark File from Text

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
        'fontSize' => 5, // Built-in font size 1-5 `fontSize` range 1-5 (larger number = larger font), built-in bitmap font only supports ASCII characters.
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
#### Generating Watermark File from Image

- Requires PHP GD extension installed

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

- Implemented in pure PHP 8.1+, no FFmpeg dependency
- This project was initially developed primarily to provide services for [xiaosongshu/rtmp_server](https://github.com/2723659854/rtmp-server)
- [PHPStan](https://phpstan.org/) Level 8 is recommended for static analysis

## Open Source License & Disclaimer

This project is open-sourced under the [Apache License 2.0](http://www.apache.org/licenses/LICENSE-2.0), allowing free use, modification, and distribution (including commercial use).  
The code is provided "AS IS" without any express or implied warranties. The author assumes no liability for any damages arising from the use of this software.

---

## 📧 Contact

- 📬 Email: 2723659854@qq.com
- 🐙 GitHub: [2723659854](https://github.com/2723659854)
