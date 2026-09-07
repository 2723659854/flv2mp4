# FLV ↔ MP4 / HLS Converter + H.264 Re-encoding + OPUS2AAC + AAC2MP3

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

---

## Introduction

A lightweight pure PHP 8.1+ media processing toolkit with **zero external dependencies (no FFmpeg required)**.  
Supports FLV, FMP4, MP4, HLS mutual conversion, live streaming gateway, pushing, pulling, rebroadcasting, as well as **H.264 decoding + scaling + re-encoding** (Baseline Profile) and **OPUS → AAC** transcoding + **AAC → MP3** transcoding.

---

## 📋 Table of Contents

- [Introduction](#introduction)
- [Core Features](#-core-features)
- [Requirements](#requirements)
- [Installation](#-installation)
- [Quick Start](#-quick-start)
- [Advanced Features](#-advanced-features)
    - [Opus to AAC](#opus-2-aac)
    - [FLV Live Gateway](#flv-live-gateway)
    - [Static File Gateway](#static-file-gateway)
    - [Pushing Client](#pushing-client)
    - [Pulling Client](#pulling-client)
    - [Rebroadcasting (Forwarding)](#rebroadcasting-forwarding)
- [Testing & Playback](#-testing--playback)
- [Use Cases](#-use-cases)
- [H.264 Re-encoding](#-h264-decoding--scaling--re-encoding)
    - [FLV → HLS Multi-bitrate Example](#flv2hls)
    - [FLV → FLV Re-encoding Example](#flv2flv)
    - [MP4 → MP4 Re-encoding Example](#mp42mp4)
    - [Watermark Generator](#watermark-generator)
    - [Performance Test Report](#performance-test-report)
- [Encoding/Decoding for AAC-MP3-OPUS](#encodingdecoding-for-aac-mp3-opus)
- [Technical Notes](#-technical-notes)
- [License & Disclaimer](#open-source-license--disclaimer)
- [Contact](#-contact)

---

## 🎯 Core Features

| Feature               | Direction                                    | Description                                                        |
|:----------------------|:---------------------------------------------|:-------------------------------------------------------------------|
| Container conversion  | FLV ↔ MP4 / FMP4                             | Generate standard MP4 or fragmented fMP4 (MSE compatible)          |
| HLS slicing           | FLV → HLS                                    | Generate M3U8 + TS segments, compatible with hls.js, VLC, etc.     |
| HLS restoration       | HLS → FLV                                    | Merge HLS segments back into a single FLV file                     |
| MP4 ↔ FLV             | MP4 → FLV / FMP4 → FLV                       | Multi-container interconversion                                    |
| Live gateway          | FLV gateway                                  | High-performance multi-level forwarding, supports high concurrency |
| Static file server    | HTTP file gateway                            | Lightweight file server with directory browsing support            |
| Pushing client        | FLV / MP4 → RTMP/HTTP-FLV/WS-FLV             | Push static files as a pseudo-live stream                          |
| Pulling client        | RTMP/HTTP-FLV/WS-FLV → FLV                   | Pull live stream and save as local FLV                             |
| Rebroadcasting        | Multi-protocol input → Multi-protocol output | One pull, multiple forwards                                        |
| **H.264 re-encoding** | Decode → Scale → Encode                      | Baseline Profile, provides core support for multi-bitrate HLS      |
| **OPUS→AAC**          | opus→pcm→aac                                 | Convert WebRTC Opus audio to AAC-LC                                |
| **AAC→MP3**           | aac→pcm→mp3                                  | Convert AAC-LC audio to MP3                                        |

---

## Requirements

| Dependency | Description |
| :--- | :--- |
| PHP | ≥ 8.1 (**CLI mode only**) |
| `sockets` extension | **Required**, provides low-level socket communication |
| `gd` extension | **Optional**, used for generating watermarks from PNG/JPG images. Falls back to built‑in bitmap font if not available. |

- 💡 **CLI only** – does not work under Nginx/FPM web mode.
- 💡 **No FFmpeg, no third-party binaries** – 100% pure PHP.
- 💡 **Container‑level conversion** (FLV/MP4/HLS interop) is fast as it only changes the container.
- ⚠️ **H.264 re-encoding and Opus transcoding require `proc_open` to be enabled.**
- ⚠️ **H.264 re-encoding module is CPU‑intensive** – suitable for short offline videos, not for live real‑time transcoding. Enabling JIT is strongly recommended.

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

// 1. FLV → fragmented fMP4 (merged)
\Xiaosongshu\Flv2mp4\Client::runFlv2Fmp4Mixed($file, __DIR__ . '/output_merge');

// 2. FLV → fragmented fMP4 (separate audio/video tracks)
\Xiaosongshu\Flv2mp4\Client::runFlv2Fmp4Separate($file, __DIR__ . '/output_separate');

// 3. FLV → HLS
\Xiaosongshu\Flv2mp4\Client::runFlv2Hls($file, __DIR__ . '/hls');

// 4. HLS → FLV
\Xiaosongshu\Flv2mp4\Client::runHls2Flv(__DIR__ . '/hls/index.m3u8', __DIR__ . '/output.flv');

// 5. MP4 → FLV
\Xiaosongshu\Flv2mp4\Client::runMp42Flv(__DIR__ . '/test.mp4', __DIR__ . '/output.flv');

// 6. FLV → MP4
\Xiaosongshu\Flv2mp4\Client::runFlv2Mp4($file, __DIR__ . '/output.mp4');

// 7. fMP4 → FLV (supports both merged and separate formats)
\Xiaosongshu\Flv2mp4\Client::runFmp42Flv(__DIR__ . '/output_merge/index.m3u8', __DIR__ . '/output.flv');

// 8. MP4 → HLS
\Xiaosongshu\Flv2mp4\Client::runMp42Hls(__DIR__ . "/demo.mp4", __DIR__ . "/mp4_hls");

// 9. HLS → MP4
\Xiaosongshu\Flv2mp4\Client::runHls2Mp4( __DIR__ .'/mp4_hls/demo/index.m3u8', __DIR__.'/hls_2_mp4.mp4');

// 10. MP4 → fMP4
\Xiaosongshu\Flv2mp4\Client::runMp42Fmp4(__DIR__.'/demo.mp4', __DIR__.'/mp4_2_fmp4');

// 11. fMP4 → MP4
\Xiaosongshu\Flv2mp4\Client::runFmp42Mp4(__DIR__.'/mp4_2_fmp4/index.m3u8',__DIR__.'/1234567.mp4');
```

---

## 🌐 Advanced Features

### Opus 2 AAC

`WebRtcFlvRelay` receives WebRTC RTP data, wraps H.264 video into FLV, transcodes Opus audio to AAC‑LC via a pure PHP Worker, and pushes it to a WebSocket‑FLV service for recording or forwarding to RTMP.

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

// Call these in your WebRTC server's RTP callback:
// $relay->pushRtp($plainRtp, 'video');
// $relay->pushRtp($plainRtp, 'audio');

// Close relay when done; shut down automatically started Workers on process exit.
$relay->finish();
OpusWorkerClient::shutdownOwnedWorkers();
```

A complete example is available at `examples/webrtc.php`. Common configuration:

```php
// Each project instance must use a different Worker port.
$opusWorkerPort = 8330;

// Supports RTMP, HTTP‑FLV, and WebSocket‑FLV push URLs.
// The example uses WebSocket‑FLV and replaces placeholder with streamId.
$wsFlvPushUrl = 'ws://127.0.0.1:8501/live/{streamId}';
```

Run the example:

```bash
php webrtc.php
```

**Notes:**

- The relay automatically starts `bin/opus-worker.php` if no Worker is listening on the port – no manual startup needed.
- Worker listens only on `127.0.0.1`, default port `8330`.
- Auto‑start passes the host project's real `vendor/autoload.php` via `--autoload`, working with both local development and Composer‑installed setups.
- Default output: 48kHz, mono, 64kbps AAC‑LC.
- One Worker process can manage multiple independent connections, but real‑time transcoding is CPU‑heavy; plan for one live stream per instance.
- Different project instances on the same machine must use different `$opusWorkerPort`.
- On `Ctrl+C` or process exit, call `OpusWorkerClient::shutdownOwnedWorkers()` – the example already handles this.
- PHP must allow `proc_open` for automatic Worker creation.
- The Worker queue has bounded back‑pressure; do not simply enlarge the queue to solve performance issues, as it may increase latency and cause A/V desync.
- WebRTC service requires the `xiaosongshu/webrtc` package.

### FLV Live Gateway

Supports multi‑level proxy deployment for high‑concurrency live stream forwarding. Create `flvGateway.php`:

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';
$gateway = new \Xiaosongshu\Flv2mp4\Manage\FlvGateway(8080, 'http://127.0.0.1:8501');
$gateway->debug = true;
$gateway->start();
```

Run:

```bash
php flvGateway.php
```

### Static File Gateway

Lightweight HTTP file server with directory browsing toggle. Create `fileGateway.php`:

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';
$server = new \Xiaosongshu\Flv2mp4\Manage\FileGateway( '0.0.0.0',8100,__DIR__,false);
$server->debug = true;
$server->start();
```

Run:

```bash
php fileGateway.php
```

### Pushing Client

Supports HTTP‑FLV, WS‑FLV, RTMP, with speed control and auto‑reconnect. Create `pusher.php`:

```php
require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '2048M');
$pusher = new \Xiaosongshu\Flv2mp4\Manage\PusherManage(__DIR__."/test.flv", "http://127.0.0.1:8501/live/stream", 1.0, false);
$pusher->start();
```

Run:

```bash
php pusher.php
```

### Pulling Client

Pulls a live stream and saves it as a local FLV file. Create `puller.php`:

```php
require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '2048M');
$puller = new \Xiaosongshu\Flv2mp4\Manage\PullerManage("ws://127.0.0.1:8501/live/stream.flv", __DIR__."/pull_record.flv", 0, false);
$puller->start();
```

Run:

```bash
php puller.php
```

### Rebroadcasting (Forwarding)

Pulls one stream and forwards it to multiple destinations (mixed protocols supported). Create `forward.php`:

```php
require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '2048M');
$forwarder = new \Xiaosongshu\Flv2mp4\Flv\FlvForwardClient("http://127.0.0.1:8501/a/b.flv", ["rtmp://127.0.0.1:1935/c/d","ws://127.0.0.1:8501/c/e"], 0, true);
$forwarder->start();
```

Run:

```bash
php forward.php
```

---

## 🧪 Testing & Playback

| Output format | Recommended player | Sample file |
| :--- | :--- | :--- |
| MP4 | HTML5 `<video>` | `index.html` |
| fMP4 | MSE player | `play_merge.html`, `mse.html` |
| HLS (TS) | hls.js / Safari | `play.html` |
| FLV | flv.js | `flv.html` |
| FLV (push test) | Web push test | `push.html` |

---

## 🎯 Use Cases

- **Live recording**: Save RTMP/FLV streams as fMP4 / HLS in real time.
- **Video playback**: On‑demand playback of recorded streams.
- **Stream forwarding**: Multi‑level gateways for load balancing and edge acceleration.
- **Offline batch processing**: Bulk FLV / MP4 conversion.
- **Pseudo‑live streaming**: Push on‑demand files as live streams.
- **Cross‑platform rebroadcasting**: One pull, multiple pushes to different platforms.
- **Multi‑bitrate HLS**: Pure PHP H.264 re‑encoding to generate adaptive‑bitrate HLS.

---

## 🔥 H.264 Decoding + Scaling + Re-encoding

Supports Baseline Profile H.264 decoding, scaling, and re‑encoding, enabling the following capabilities:

| Use case | Description |
| :--- | :--- |
| **Multi‑bitrate HLS** | Convert a single FLV into multiple resolution HLS streams (adaptive bitrate) |
| **FLV re‑encoding** | Change resolution/bitrate and output as FLV |
| **MP4 re‑encoding** | Change resolution/bitrate and output as MP4 |
| **Format conversion** | Re‑encode during FLV ↔ MP4 conversion (not just remuxing) |
| **Watermark overlay** | Decode YUV → overlay PNG/text watermark → re‑encode output |
| **Image enhancement** | Apply filters (sharpen, denoise) after decoding → re‑encode |
| **Resolution adaptation** | Downsample high‑resolution video to multiple output resolutions |
| **Bitrate control** | Transcode high‑bitrate videos to target bitrate |

This is a complete **H.264 pixel processing pipeline** (decode → process → encode), implemented entirely in PHP without FFmpeg.

---

### FLV2HLS

Example for multi‑bitrate HLS generation:

```php
<?php

require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '2048M');
$profiles = [
    '240p' => [
        'width' => 426,
        'height' => 240,
        'bitrate' => 300000, // 300 Kbps video
        'fps' => 24,
        'audioBitrate' => 48000, // 48 Kbps
        'qp' => 30,
        'watermark'=>true,
        'watermark_file'=> __DIR__."/src/Static/watermark_80x16.yuv",
    ]
];
// Enable multi-process acceleration for high-quality re-encoding; not needed for low bitrate.
$generator = new \Xiaosongshu\Flv2mp4\Recode\PurePhpHlsGenerator($profiles, __DIR__ . '/hls/output', true);
$generator->processFlv(__DIR__ . '/input.flv');
echo "Master playlist: hls/output/master.m3u8\n";
echo "All done!\n";
```

### FLV2FLV

Re‑encode a FLV file with new bitrate/resolution:

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '2048M');

$config = [
    'width' => 320,
    'height' => 180,
    'bitrate' => 150000,
    'fps' => 15,
    'qp' => 30,
    'watermark'=>true,
    'watermark_file'=> __DIR__."/src/Static/watermark_80x16.yuv",
];
// Enable multi-process acceleration for high-quality re-encoding; not needed for low bitrate.
$recoder = new \Xiaosongshu\Flv2mp4\Recode\FlvRecoder($config, true);
$recoder->setMaxFrames(50);
$recoder->processFlv(__DIR__ . '/input.flv', __DIR__.'/output.flv');
echo "FLV re‑encoding done.\n";
```

### MP42MP4

Re‑encode a MP4 file:

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '2048M');

$config = [
    'width' => 320,
    'height' => 180,
    'bitrate' => 150000,
    'fps' => 15,
    'qp' => 30,
    'watermark'=>true,
    'watermark_file'=> __DIR__."/src/Static/watermark_80x16.yuv",
];
// Enable multi-process acceleration for high-quality re-encoding; not needed for low bitrate.
$recoder = new \Xiaosongshu\Flv2mp4\Recode\Mp4Recoder($config, true);
$recoder->setMaxFrames(50);
$recoder->processMp4(__DIR__ . '/input.mp4', __DIR__ . '/output.mp4');
echo "MP4 re‑encoding done.\n";
```

**Notes for watermarking:**

- Watermark files must be in YUV format, and the filename **must** include its dimensions (e.g., `watermark_{width}x{height}.yuv`).
- The tool parses width and height from the filename automatically (e.g., `watermark_80x16.yuv` → width 80, height 16).
- The re‑encoding module exposes a **YUV pixel‑level interface**, which can be used to implement custom features like subtitles, picture‑in‑picture, video stitching, etc.
- For detailed H.264 usage, see <a href="./src/Codec/README.md">src/Codec/README.md</a>.

---

### Supported Re‑encoding Features

- [x] **I‑frame decode & encode** (100% exact, INF dB)
- [x] **P‑frame decode & encode** (Baseline Profile)
- [x] **Intra prediction**: 4×4 (9 modes) + 16×16 (4 modes)
- [x] **Inter prediction**: P‑frame motion estimation (diamond search optimized)
- [x] **1/4‑pixel precision** (6‑tap filter interpolation)
- [x] **CAVLC entropy coding** (Baseline Profile)
- [x] **Resolution scaling** (YUV scaling after decode → re‑encode)
- [x] **Bitrate control** (via QP parameter)
- [ ] **B‑frame support** (planned, requires Main Profile with bidirectional prediction)
- [ ] **CABAC entropy coding** (planned, Main Profile)

> ⚠️ **Performance note**: The H.264 re‑encoding module is pure PHP and is intended for **short‑duration videos (≤ 10 seconds)** for offline processing or functional verification. For long videos or high‑resolution transcoding, professional tools like FFmpeg are recommended.

---

### Watermark Generator

Provides PHP functions to generate YUV watermark files. Uses GD extension if available, otherwise falls back to built‑in bitmap font.

- `generateFromText()` – generates text watermark YUV. Uses GD if available; otherwise falls back to bitmap font (**ASCII characters only**).
- `generateFromImage()` – generates watermark YUV from PNG/JPG images (requires GD extension).

#### Generate text watermark

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Xiaosongshu\Flv2mp4\Codec\WatermarkUtil;

echo "=== Testing WatermarkUtil ===\n\n";

echo "1. Generate text watermark (xiaosongshu, 80x16)...\n";
$outputFile1 = __DIR__ . '/test_wm_text.yuv';
$start = microtime(true);
$result = WatermarkUtil::generateFromText(
    'xiaosongshu',
    $outputFile1,
    80,
    16,
    [
        'fontSize' => 5, // 1–5, built‑in font; ASCII only
        'fontColor' => [255, 255, 255],
        'bgColor' => [0, 0, 0],
    ]
);
$cost = round(microtime(true) - $start, 3);
if ($result && file_exists($outputFile1)) {
    $size = filesize($outputFile1);
    $expectedSize = 80 * 16 + (80 * 16 >> 1);
    echo "   Success! Size: {$size} bytes (expected: {$expectedSize}) - time: {$cost}s\n";
    if ($size === $expectedSize) {
        echo "   ✅ File size correct\n";
    } else {
        echo "   ❌ File size mismatch\n";
    }
} else {
    echo "   ❌ Generation failed\n";
}
```

#### Generate from image

Requires GD extension.

```php
<?php

require_once __DIR__ . '/vendor/autoload.php';

use Xiaosongshu\Flv2mp4\Codec\WatermarkUtil;

echo "=== Testing WatermarkUtil ===\n\n";

echo "1. Generate watermark from image (xiaosongshu, 80x16)...\n";
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
    echo "   Success! Size: {$size} bytes (expected: {$expectedSize}) - time: {$cost}s\n";
    if ($size === $expectedSize) {
        echo "   ✅ File size correct\n";
    } else {
        echo "   ❌ File size mismatch\n";
    }
} else {
    echo "   ❌ Generation failed\n";
}
```

---

## Performance Test Report

### Test Environment

| Item | Windows Environment | Linux Environment (Docker) |
| :--- | :--- | :--- |
| **Operating System** | Windows | Linux (Docker) |
| **CPU** | 16 cores (physical) | 14 cores (physical) |
| **Memory** | 15.8 GB (available) | 4 GB (available) |
| **Worker processes** | 8 ME sub‑processes | 8 ME sub‑processes |
| **PHP version** | 8.4.3 (CLI, JIT enabled) | 8.1.24 (CLI, OPcache disabled) |
| **OPcache** | `opcache.enable_cli=on`, `opcache.jit=on`, `opcache.jit_buffer_size=100M` | Not enabled |
| **Test clip** | `test.flv`, 3.02 s, 720×742, 30 fps | Same as left |
| **Output specs** | `output.flv`, 360×360, 10 fps | Same as left |
| **Encoding settings** | H.264 Constrained Baseline, AAC 128 kbps | Same as left |


### Cross‑Platform Performance Comparison

| Output Format | Windows Time | **Linux (Docker) Time** | Performance Gain |
| :--- | :--- | :--- | :--- |
| **FLV Re‑encoding** | 28 s | **23 s** | **↓ 17.9%** |
| **MP4 Re‑encoding** | 29 s | **24 s** | **↓ 17.2%** |
| **HLS (mpegts + m3u8)** | 37 s | **31 s** | **↓ 16.2%** |

*Linux environment (without OPcache) is still about 5–6 seconds faster than Windows (with JIT enabled).*

---

## Encoding/Decoding for AAC-MP3-OPUS

This project supports decoding of AAC-LC and Opus audio, and encoding of AAC-LC and MP3.

- The Opus-to-AAC-LC conversion has been used in production environments. See the `Opus 2 AAC` example above, which has been applied to the WebRTC live-to-RTMP part of the `rtmp_server` project.
- For AAC-LC to MP3 conversion, see the source code for detailed usage. Example method:
```php
$converter = new \Xiaosongshu\Flv2mp4\Manage\AAC2MP3();
$result = $converter->process( __DIR__ . '/test_demo.mp4',__DIR__ . '/aac2mp3_test.mp3');
```
This method decodes AAC-LC audio, produces PCM, and then wraps it into MP3 audio.


## 🔧 Technical Notes

- 100% pure PHP 8.1+, no FFmpeg dependency.
- Originally built to serve [xiaosongshu/rtmp_server](https://github.com/2723659854/rtmp-server).
- Recommended static analysis: [PHPStan](https://phpstan.org/) Level 8.
- H.264 re‑encoding uses distributed multi‑process architecture; disable distributed mode if running on a single‑core machine.

## Open Source License & Disclaimer

This project is licensed under the [Apache License 2.0](http://www.apache.org/licenses/LICENSE-2.0). You are free to use, modify, and distribute it (including commercial use).  
The code is provided "AS IS", without warranty of any kind, express or implied. The author is not liable for any damages arising from its use.

---

## 📧 Contact

- Email: 2723659854@qq.com
- GitHub: [2723659854](https://github.com/2723659854)