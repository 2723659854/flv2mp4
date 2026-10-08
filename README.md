# Pure PHP Audio/Video Processing Engine: FLV/MP4/HLS Interconversion · H.264 Decoding and Re-encoding · Full-format Interconversion of AAC/MP3/Opus/WAV

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
Supports FLV, FMP4, MP4, HLS mutual conversion, live streaming gateway, pushing, pulling, rebroadcasting, as well as **H.264 decoding + scaling + re-encoding** (Baseline Profile) and **Full-format Interconversion of AAC/MP3/Opus/WAV**.

---

## 📋 Table of Contents

- [Introduction](#introduction)
- [Core Features](#-core-features)
- [Requirements](#environment-requirements)
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
    - [FLV/MP4/HLS Compression Transcoding](#flvmp4hls-compression-transcoding)
    - [Live Stream Compression Transcoding](#live-stream-compression-transcoding)
    - [Supported Re-encoding Features](#supported-re-encoding-features)
    - [Watermark Generation Tool](#watermark-generation-tool)
    - [Performance Test Report](#performance-test-report)
- [Encoding/Decoding for AAC-MP3-OPUS-WAV](#encodingdecoding-for-aac-mp3-opus-wav)
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
| **opus/aac/mp3/wav format conversion** | Source audio → PCM → target format audio | Supports mutual conversion among Opus/AAC/MP3/WAV audio formats |
---

## Environment Requirements

| Dependency     | Description                                                                                          |
|----------------|------------------------------------------------------------------------------------------------------|
| PHP            | ≥ 8.1 (**CLI mode only**)                                                                            |
| `sockets` ext  | **Optional**, provides low-level Socket communication. Only required for live streaming and H.264 re-encoding scenarios. |
| `gd` ext       | **Optional**, used to generate watermarks from PNG/JPG images. If not installed, it automatically falls back to the built-in bitmap font mode. |

- 💡 **Only supported in PHP CLI mode. Not supported under Nginx/FPM or web server environments.**
- 💡 **No FFmpeg, no third-party binaries required — fully implemented in pure PHP.**
- 💡 **Container remuxing (FLV/MP4/HLS conversion) only changes the container format and is very fast.**
- ⚠️ **H.264 re-encoding requires `proc_open` to be enabled.** (This module uses multi-process distributed computing and spawns child processes to process frames in parallel.)
- ⚠️ **Opus real-time transcoding (live streaming only) requires `proc_open` to be enabled.** (In real-time scenarios such as WebRTC to RTMP, it launches a standalone background Worker process for audio transcoding. Static file Opus transcoding does not require this function.)
- ⚠️ **H.264 re-encoding is a CPU-intensive task. Performance depends on server configuration. It is not recommended for live real-time transcoding. Enabling JIT acceleration is highly recommended.**

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
### 🔥 H.264 Decoding + Scaling + Re-encoding

Supports Baseline Profile H.264 decoding, scaling, and re-encoding, providing core capabilities for the following scenarios:
**Technical positioning**: This is a complete **H.264 pixel processing pipeline** (decode → process → encode), implemented in pure PHP without FFmpeg.

---
#### FLV/MP4/HLS Compression Transcoding
Example code:
```php
require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '2048M');
$config = [
    'width' => 360,
    'height' => 360,
    'bitrate' => 0,
    'fps' => 15,
    'audioBitrate' => 48000,
    'qp' => 30,
    'watermark'=>false,
    'watermark_file'=> __DIR__."/watermark_80x16.yuv",
    'fastMotion'     => true,
    'motionWorkers'  => 2,
    'motion_budget'  => 8,
    'decode_workers' => 6,
    'segmentDuration'=> 3,
];
# flv -> hls
(new \Xiaosongshu\Flv2mp4\Recode\PurePhpHlsGenerator($config,__DIR__ . '/hls/output',true,))->processFlv(__DIR__ . '/test.flv');
# flv -> flv
(new \Xiaosongshu\Flv2mp4\Recode\FlvRecoder($config, true))->processFlv(__DIR__ . '/test.flv', __DIR__.'/output.flv');
# mp4 -> mp4
(new \Xiaosongshu\Flv2mp4\Recode\Mp4Recoder($config, true))->processMp4($mp4File, __DIR__ . '/output1.mp4');
```
- When adding a watermark, the file name format is: `watermark_{width}x{height}.yuv`.
- The re-encoding module provides a **YUV pixel-level operation interface**, which you can use to implement custom features such as subtitles, picture-in-picture, video stitching, etc.

---

### Live Stream Compression Transcoding
This project supports compressing live FLV streams and transcoding them to HLS, adapting to weak network scenarios on mobile devices. Example code:
```php
<?php
require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '2048M');
$pullUrl = 'rtmp://127.0.0.1:1935/a/b';
$config = [
    'width'        => 480,
    'height'       => 270,
    'bitrate'      => 0,
    'fps'          => 15,
    'qp'           => 30,
    'audioBitrate' => 64000,
    'motionWorkers'   => 1,
    'decodeWorkers'   => 6,
    'segmentDuration' => 3,
    'fastMotion' => true,
    'outputDir'  => __DIR__ . '/hls/live_rtmp/',
    'maxRetries'    => 5,
    'retryDelay'    => 3,
    'connectTimeout'=> 10,
    'idleTimeout'   => 30,
    'queueMaxBytes' => 8388608,
    'duration'   => 120,
    'tlsVerify'  => false,
];
(new \Xiaosongshu\Flv2mp4\Manage\Flv2HlsCompact($pullUrl, $config))->run();

```

- The current project supports live stream compression transcoding with no measured latency, meeting live streaming requirements. Actual performance depends heavily on server configuration; adjust the parameters above according to your server resources.
- `motionWorkers` and `decodeWorkers`: the higher the input resolution, the more you should increase `decodeWorkers`; the higher the output resolution, the more you should increase `motionWorkers`. The sum of both should not exceed the number of logical processors on the server (motion estimation processes are child processes of decoding processes).
- Tested with OBS push: input 960×540, 30 fps, one keyframe per second, Baseline Profile, H.264 + AAC; output 480×270, 15 fps, 3-second HLS segments. Ran continuously for 30 minutes with no dropped frames and no backlog.
- ⚠️ Live stream compression transcoding only supports CLI mode. Do not call it directly in an FPM request, otherwise it will block the web service worker processes.

----

### Supported Re-encoding Features

- [x] **I-frame decoding and encoding** (fully exact, INF dB)
- [x] **P-frame decoding and encoding** (Baseline Profile)
- [x] **Intra prediction**: 4x4 (9 modes) + 16x16 (4 modes)
- [x] **Inter prediction**: P-frame motion estimation (diamond search optimized)
- [x] **1/4-pixel precision**: 6-tap filter interpolation
- [x] **CAVLC entropy coding** (Baseline Profile)
- [x] **Resolution scaling** (YUV scaling after decoding → re-encoding)
- [x] **Bitrate control** (via QP parameter)
- [ ] **B-frame support** (planned, requires extension to Main Profile with bidirectional prediction)
- [ ] **CABAC entropy coding** (planned, Main Profile support)
---

### Watermark Generation Tool
This project provides PHP functions to generate YUV watermarks. GD extension is preferred; if unavailable, it automatically falls back to a bitmap font.
- `generateFromText()` generates a text watermark YUV. GD extension preferred; falls back to bitmap font if unavailable. **The built-in bitmap font only supports ASCII characters (English letters, digits, English punctuation).**
- `generateFromImage()` generates a watermark YUV from an image. Requires GD extension. Supports png/jpg.

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';
use Xiaosongshu\Flv2mp4\Codec\WatermarkUtil;
WatermarkUtil::generateFromText('xiaosongshu',__DIR__ . '/test_wm_text.yuv',80,16,['fontSize' => 5,'fontColor' => [255, 255, 255],'bgColor' => [0, 0, 0],]);
WatermarkUtil::generateFromImage(__DIR__."/watermark_80x16.png",__DIR__ . '/test_wm_copy_80x16.yuv',80,16);
```
---

## Performance Test Report

### Test Environment

| Item | Windows Environment | Linux Environment (Docker) |
| :--- | :--- | :--- |
| **Operating System** | Windows | Linux (Docker) |
| **CPU** | 16 cores (physical) | 14 cores (physical) |
| **Memory** | 15.8 GB (available) | 4 GB (available) |
| **Worker Processes** | 8 ME sub-processes | 8 ME sub-processes |
| **PHP Version** | 8.4.3 (CLI) | 8.1.24 (CLI) |
| **Test Clip** | `test.flv`, 3.02 s, 720×742, 30 fps | Same as left |
| **Output Specs** | `output.flv`, 360×360, 10 fps | Same as left |
| **Encoding Settings** | H.264 Constrained Baseline, AAC 128 kbps | Same as left |


### Cross-Platform Performance Comparison

| Output Format | Windows Time | Linux (Docker) Time | Performance Gain |
| :--- | :--- | :--- | :--- |
| **FLV Re-encoding** | 16 s | **9 s** | **↓ 43.8%** |
| **MP4 Re-encoding** | 16 s | **9 s** | **↓ 43.8%** |
| **HLS (mpegts + m3u8)** | 17 s | **10 s** | **↓ 41.2%** |

### Long Video Test (7 min 5 s video)

| Platform | Re-encoding Time | Time Ratio |
| :--- | :--- | :--- |
| **Windows** | **240 s** | ~0.56× |
| **Linux (Docker)** | **146 s** | ~0.34× |


### Optimization History

| Optimization Stage | FLV | MP4 | HLS | Notes |
|:---|:---|:---|:---|:---|
| **Initial Version** | ~91 s | ~60 s (old) | **135 s** | Serial, no optimization |
| **Algorithm-Level Optimization** | 60 s | — | 97 s | DCT butterfly unrolling, string slicing, reduced array_fill, quantization + Zigzag merged |
| **Multi-Process Motion Estimation (4 processes)** | 51 s | — | 73 s | First introduction of distributed parallelism |
| **HLS Muxing I/O Optimization** | — | — | 69 s | Batch writes, fewer file operations |
| **Encoding Core Optimization** | 44 s | 44 s | 67 s | All-zero block skip, I/P frame QP strategy |
| **Decoding Cache Optimization** | 41 s | 42 s | 64 s | Reuse of repeated calculations |
| **OPcache + JIT Enabled** | 39 s | 39 s | 60 s | Runtime environment acceleration |
| **Multi-Process Model Optimization (select, etc.)** | 33 s | — | — | Event-driven, process communication optimization |
| **Further Fine-Tuning** | 32 s | — | — | Specific method not specified |
| **Extreme Optimization (Windows)** | 28 s | 29 s | 37 s | Windows + PHP 8.4.3 + JIT |
| **Linux Docker Deployment** | 23 s | 24 s | 31 s | Linux + PHP 8.1.24, OPcache disabled |
| **GOP Distributed Multi-Process Decoding (Windows)** | 22 s | 22 s | 22 s | Windows platform |
| **GOP Distributed Multi-Process Decoding (Linux)** | 17 s | 17 s | 17 s | Linux platform |
| **Removed Repeated SHA256 for Reference Frames + Static Block ME Early Exit (Windows)** | 19 s | 19 s | 20 s | Windows platform |
| **Removed Repeated SHA256 for Reference Frames + Static Block ME Early Exit (Linux)** | 16 s | 16 s | 17 s | Linux platform |
| **Zero-Region Skip + Deep Decoding/Filtering Optimization (Windows)** | 18 s | 18 s | 19 s | 6-tap sliding recursion, Bs all-zero fast skip, skip memory write when filter result unchanged, CBP=0 whole-block skip, DPB lazy loading, `chr()` table lookup, on-demand unpack + cache |
| **Zero-Region Skip + Deep Decoding/Filtering Optimization (Linux)** | 14 s | 14 s | 15 s | Same as above |
| **Dropped-Frame Deblock Skip + BitReader Fast Path + Integer Plane Arrays (Windows)** | **16 s** | **16 s** | **17 s** | This optimization round |
| **Dropped-Frame Deblock Skip + BitReader Fast Path + Integer Plane Arrays (Linux)** | **9 s** | **9 s** | **10 s** | Current best results |
| **GOP Streaming Distribution + Reduced Search and Skip 1/4 Pixel** | - | - | - | Improved live streaming and long video compression efficiency |

**Notes:**
- Test clip: `test.flv`, 3.02 s, 720×742, 30 fps; Output specs: 360×360, 10 fps.
- Encoding settings: H.264 Constrained Baseline, AAC 128 kbps.
- Best stable values taken from multiple test runs.
- “—” indicates the format was not separately tested at this stage.
- GOP distributed multi-process decoding is the latest optimization, achieving significant improvements on both Windows and Linux.
- **Platform difference**: Linux 9 s vs Windows 16 s; the gap mainly comes from process scheduling efficiency and system call overhead.

---

## Encoding/Decoding for AAC-MP3-OPUS-WAV

```php
# aac-lc → mp3
\Xiaosongshu\Flv2mp4\Client::runAac2Mp3( __DIR__ . '/input.aac',__DIR__ . '/aac2mp3.mp3');
# aac-lc → wav
\Xiaosongshu\Flv2mp4\Client::runAac2Wav(__DIR__ . '/input.aac',__DIR__ . '/aac2wav.wav');
# wav → aac-lc
\Xiaosongshu\Flv2mp4\Client::runWav2Aac(__DIR__ . '/input.wav',__DIR__ . '/wav2aac.aac');
# opus → wav
\Xiaosongshu\Flv2mp4\Client::runOpus2Wav(__DIR__ . '/input.opus',__DIR__ . '/opus2wav.wav');
# mp3 → wav
\Xiaosongshu\Flv2mp4\Client::runMp32Wav(__DIR__ . '/input.mp3',__DIR__ . '/mp32wav.wav');
# wav → mp3
\Xiaosongshu\Flv2mp4\Client::runWav2Mp3(__DIR__ . '/input.wav',__DIR__ . '/wav2mp3.mp3');
# opus → mp3
\Xiaosongshu\Flv2mp4\Client::runOpus2Mp3(__DIR__ . '/input.opus',__DIR__ . '/opus2mp3.mp3');
# opus → aac-lc
\Xiaosongshu\Flv2mp4\Client::runOpus2Aac(__DIR__ . '/input.opus',__DIR__ . '/opus2aac.aac');
# mp3 → aac-lc
\Xiaosongshu\Flv2mp4\Client::runMp32Aac(__DIR__ . '/input.mp3',__DIR__ . '/mp32aac.aac');
# mp3 → opus
\Xiaosongshu\Flv2mp4\Client::runMp32Opus(__DIR__ . '/input.mp3', __DIR__ . '/mp32opus.opus');
# wav → opus
\Xiaosongshu\Flv2mp4\Client::runWav2Opus(__DIR__ . '/input.wav', __DIR__ . '/wav2opus.opus');
# aac-lc → opus 
\Xiaosongshu\Flv2mp4\Client::runAac2Opus(__DIR__ . '/input.aac', __DIR__ . '/aac2opus.opus');
```


## 🔧 Technical Notes

- 100% pure PHP 8.1+, no FFmpeg dependency.
- Originally built to serve [xiaosongshu/rtmp_server](https://github.com/2723659854/rtmp-server).
- Recommended static analysis: [PHPStan](https://phpstan.org/) Level 8.
- H.264 re‑encoding uses distributed multi‑process architecture; disable distributed mode if running on a single‑core machine.

## Open Source License & Disclaimer

- **Open Source License**: This project is released under the [Apache License 2.0](http://www.apache.org/licenses/LICENSE-2.0), which permits free use, modification, and distribution (including for commercial purposes). The code is provided "AS IS", without any express or implied warranties. The author shall not be held liable for any damages arising from the use of this software.
- **Patent Risk Notice**: This project contains pure PHP implementations of patent-protected audio/video codecs, including H.264, AAC-LC, and MP3. The above open source license grants only a copyright license and **does not include any patent license**.
- **Usage Restrictions & Transfer of Liability**: The above codec implementations are intended solely for **learning, research, testing, and personal non-commercial use**. If you use them for any **commercial product distribution or commercial operation**, you must obtain the appropriate patent licenses from the relevant patent holders (e.g., Via Licensing, MPEG LA, Fraunhofer IIS) at your own expense and assume all patent infringement risks. The author of this project shall not be liable for any patent infringement liabilities arising therefrom.
- **Final Interpretation**: By using this project, you are deemed to have read, understood, and agreed to all terms of this disclaimer.

---

## 📧 Contact

- Email: 2723659854@qq.com
- GitHub: [2723659854](https://github.com/2723659854)