# FLV ↔ MP4 / HLS Conversion Toolkit

<p align="center">
  <a href="./README.cn.md"><strong>🇨🇳 中文</strong></a> •
  <a href="./README.md"><strong>🇬🇧 English</strong></a>
</p>

A lightweight media processing toolkit implemented in pure PHP 8.1+, **zero external dependencies (no FFmpeg required)**.  
Supports FLV, FMP4, MP4, HLS interconversion, live streaming gateway, pushing, pulling, relaying, and **H.264 decoding + scaling + re-encoding** (Baseline Profile).

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
| `bcmath` extension | Optional, for high-precision arithmetic optimization |

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

Supports multi-level proxy deployment for high-concurrency live stream forwarding.

```php
$gateway = new \Xiaosongshu\Flv2mp4\manage\FlvGateway(8080, 'http://127.0.0.1:8501');
$gateway->debug = true;
$gateway->start();
```

```bash
# Level 1 gateway
php flvGateway.php 8080 http://127.0.0.1:8501
# Level 2 gateway
php flvGateway.php 8081 http://127.0.0.1:8080
# Playback URL: http://127.0.0.1:8081/{app}/{stream}.flv
```

### Static File Gateway

Lightweight HTTP file server with directory listing toggle.

```php
$server = new \Xiaosongshu\Flv2mp4\manage\FileGateway(
    host: '0.0.0.0',
    port: 8100,
    documentRoot: __DIR__,
    enableDirListing: false
);
$server->debug = true;
$server->start();
```

### Push Client

Supports HTTP-FLV, WS-FLV, RTMP protocols with speed control and auto-reconnect.

```bash
# HTTP-FLV push
php flv_pusher.php test.flv http://127.0.0.1:8501/live/stream

# 2x speed + disable reconnect
php flv_pusher.php test.flv http://127.0.0.1:8501/live/stream 2.0 --no-reconnect

# RTMP push
php rtmp_pusher.php test.mp4 rtmp://127.0.0.1:1935/live/stream

# MP4 push (2x speed)
php mp4_pusher.php test.mp4 http://127.0.0.1:8501/live/stream 2.0
```

### Pull Client

Pull live streams and save as local FLV files, suitable for recording or debugging.

```bash
php puller.php http://127.0.0.1:8501/live/stream.flv output.flv 0 --no-reconnect
```

### Live Relay

Pull one stream and forward to multiple targets simultaneously (supports mixed protocols).

```bash
php forward.php http://127.0.0.1:8501/a/b.flv \
  "rtmp://127.0.0.1:1935/c/d,ws://127.0.0.1:8501/c/e,http://127.0.0.1:8501/c/f"
```

---

## 🧪 Testing & Playback

| Output Format | Recommended Player | Reference File |
|---------------|-------------------|----------------|
| MP4 | HTML5 `<video>` | `index.html` |
| fMP4 | MSE Player | `play_merge.html`, `mse.html` |
| HLS (TS) | hls.js / Safari | `play.html` |
| FLV | flv.js | `flv.html` |

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

Below is a multi-bitrate HLS example:

```php
<?php

require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '2048M');

$profiles = [
    '1080p' => ['width' => 1920, 'height' => 1080, 'bitrate' => 5000000],
    '720p'  => ['width' => 1280, 'height' => 720,  'bitrate' => 2500000],
    '480p'  => ['width' => 854,  'height' => 480,  'bitrate' => 1200000],
    '360p'  => ['width' => 640,  'height' => 360,  'bitrate' => 600000],
];

$generator = new PurePhpHlsGenerator($profiles, __DIR__ . '/hls/output');
$generator->processFlv(__DIR__ . '/test.flv');

echo "All processing complete!\n";
```

For other features, please implement custom logic using the encoder.

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
- [ ] **B-frame Support** (planned, requires bidirectional prediction handling)
- [ ] **CABAC Entropy Coding** (planned, for Main Profile support)

---

## 🔧 Technical Notes

- Pure PHP 8.1+ implementation, no FFmpeg dependency
- Companion to [xiaosongshu/rtmp_server](https://github.com/2723659854/rtmp-server)
- Recommended static analysis with [PHPStan](https://phpstan.org/) Level 8

---

## License & Disclaimer

This project is open-sourced under the [Apache License 2.0](http://www.apache.org/licenses/LICENSE-2.0). You are free to use, modify, and distribute the code, including for commercial purposes.  
The code is provided "AS IS", without warranty of any kind, express or implied. The author shall not be liable for any damages arising from the use of this software.

---

## 📧 Contact

- 📬 Email: 2723659854@qq.com
- 🐙 GitHub: [2723659854](https://github.com/2723659854)