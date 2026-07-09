# FLV ↔ MP4 / HLS Conversion Tool

<p align="center">
  <a href="./README.cn.md"><strong>🇨🇳 中文</strong></a> •
  <a href="./README.md"><strong>🇬🇧 English</strong></a>
</p>

A lightweight media processing toolkit implemented in pure PHP 8.1+, with **zero external dependencies (no FFmpeg required)**.  
Supports bidirectional conversion between FLV and FMP4 / MP4 / HLS, live stream forwarding, pushing, pulling, and relaying, making it easy to integrate into automated pipelines.

## 🎯 Core Features

| Feature                     | Direction                          | Description                                                                 |
|-----------------------------|------------------------------------|-----------------------------------------------------------------------------|
| Transcoding / Muxing        | FLV → MP4 / FMP4                   | Generate standard MP4 or separate fMP4 segments (suitable for MSE)          |
| Segment Distribution        | FLV → HLS                          | Generate M3U8 + TS segments, compatible with hls.js, VLC, etc.              |
| Reverse Restoration         | HLS → FLV                          | Merge HLS segments back into a single FLV file                              |
| Format Conversion           | MP4 → FLV                          | Transcode an MP4 file into FLV container format                             |
| Live Gateway                | FLV Gateway                        | High-performance multi-level forwarding, supporting high concurrency         |
| File Server                 | HTTP File Gateway                  | Lightweight static file server with directory listing support               |
| Push Client                 | FLV / MP4 → RTMP                   | Push static files as pseudo-live streams to an RTMP server (HTTP-FLV / WS-FLV also supported) |
| Pull Client                 | HTTP-FLV / WS-FLV / RTMP → FLV     | Pull a live stream and save it as a local FLV file                          |
| Relay Client                | Multi-protocol input → Multi-protocol output | Pull a live stream and forward it to multiple target addresses simultaneously |

## Prerequisites

| Dependency      | Description                                                      |
|-----------------|------------------------------------------------------------------|
| PHP             | ≥ 8.1 (**CLI command-line mode only**, not for web environments) |
| `sockets` extension | **Required**, provides low-level socket communication capabilities |

> 💡 All features are built with native PHP – no need to install FFmpeg or any third-party binaries.

## 🚀 Installation

Install via Composer:

```bash
composer require xiaosongshu/flv2mp4
```

## 📚 Quick Start

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

// Increase memory limit for large files
ini_set('memory_limit', '512M');

$file = __DIR__ . '/test.flv';

// 1. FLV → mixed audio/video fMP4 segments
$result = \Xiaosongshu\Flv2mp4\Client::runFlv2Fmp4Mixed($file, __DIR__ . '/output_merge');

// 2. FLV → separate audio/video fMP4 segments
$result = \Xiaosongshu\Flv2mp4\Client::runFlv2Fmp4Separate($file, __DIR__ . '/output_separate');

// 3. FLV → HLS
$result = \Xiaosongshu\Flv2mp4\Client::runFlv2Hls($file, __DIR__ . '/hls');

// 4. HLS → FLV
$result = \Xiaosongshu\Flv2mp4\Client::runHls2Flv(__DIR__ . "/hls/abc/index.m3u8", __DIR__ . '/output.flv');

// 5. MP4 → FLV
$result = \Xiaosongshu\Flv2mp4\Client::runMp42Flv(__DIR__ . '/test.mp4', __DIR__ . '/output.flv');

// 6. FLV → MP4
$result = \Xiaosongshu\Flv2mp4\Client::runFlv2Mp4($mp4File, __DIR__ . '/output.mp4');
```

## 🌐 Advanced Features

### FLV Live Gateway

Supports multi-level cascading deployment, enabling high-concurrency live stream forwarding through proxying.

```php
<?php

require_once __DIR__ . '/vendor/autoload.php';

$gateway = new \Xiaosongshu\Flv2mp4\manage\FlvGateway(8080, 'http://127.0.0.1:8501');
$gateway->debug = true;
$gateway->start();
```

Command-line examples:

```bash
# Level 1 gateway (direct to origin)
php flvGateway.php 8080 http://127.0.0.1:8501

# Level 2 gateway (proxy for level 1)
php flvGateway.php 8081 http://127.0.0.1:8080

# Playback URL: http://127.0.0.1:8081/{app}/{stream}.flv
```

### Static File Gateway

A high-performance HTTP file server for quickly sharing directory contents, with a toggle for directory listing.

```php
<?php

require_once __DIR__ . '/vendor/autoload.php';

$server = new \Xiaosongshu\Flv2mp4\manage\FileGateway(
    host: '0.0.0.0',
    port: 8100,
    documentRoot: __DIR__,
    enableDirListing: false   // It is recommended to disable directory listing in production
);
$server->debug = true;
$server->start();
```

```bash
php fileGateway.php
```

### Push Client

#### Command-line Arguments

| Argument         | Description                                        | Default Value                       |
|------------------|----------------------------------------------------|-------------------------------------|
| `file`           | Path to the source FLV / MP4 file                   | **Required**                        |
| `push_url`       | Target push URL (HTTP / WS / RTMP)                  | `http://127.0.0.1:8501/live/stream` |
| `speed`          | Push speed multiplier (0.1 – 10.0)                  | `1.0`                               |
| `--no-reconnect` | Disable automatic reconnection                     | Reconnection enabled by default     |

#### HTTP / WebSocket Push

```php
<?php

require_once __DIR__ . '/vendor/autoload.php';

// FLV push
$pusher = new \Xiaosongshu\Flv2mp4\manage\FLVPusherAll(
    flvFile: 'test.flv',
    pushUrl: 'http://127.0.0.1:8501/live/stream',
    speed: 1.0,
    autoReconnect: true
);
$pusher->start();

// MP4 push
$mp4Pusher = new \Xiaosongshu\Flv2mp4\Manage\Mp4PusherAll(
    mp4File: 'test.mp4',
    pushUrl: 'http://127.0.0.1:8501/live/stream',
    speed: 2.0
);
$mp4Pusher->start();
```

Command-line examples:

```bash
# HTTP-FLV push
php flv_pusher.php test.flv http://127.0.0.1:8501/live/stream

# WebSocket push
php flv_pusher.php test.flv ws://127.0.0.1:8501/live/stream

# Push at 2x speed
php flv_pusher.php test.flv http://127.0.0.1:8501/live/stream 2.0

# Disable automatic reconnection
php flv_pusher.php test.flv http://127.0.0.1:8501/live/stream 1.0 --no-reconnect

# Push MP4 file
php mp4_pusher.php test.mp4 http://127.0.0.1:8501/live/stream 2.0
```

#### RTMP Push

```php
<?php

require_once __DIR__ . '/vendor/autoload.php';

use Xiaosongshu\Flv2mp4\SabreAMF\RtmpPushFlvClient;
use Xiaosongshu\Flv2mp4\SabreAMF\RtmpPushMp4Client;

ini_set('memory_limit', '2048M');

$filePath    = $argv[1];
$rtmpUrl     = $argv[2] ?? 'rtmp://127.0.0.1:1935/live/stream';
$speed       = (float) ($argv[3] ?? 1.0);
$autoReconnect = !in_array('--no-reconnect', $argv);

$extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

$pusher = match ($extension) {
    'mp4'    => new RtmpPushMp4Client($filePath, $rtmpUrl, $speed, $autoReconnect),
    default  => new RtmpPushFlvClient($filePath, $rtmpUrl, $speed, $autoReconnect),
};

$pusher->start();
```

Command-line examples:

```bash
# FLV RTMP push
php rtmp_pusher.php test.flv rtmp://127.0.0.1:1935/live/stream

# MP4 RTMP push (2x speed)
php rtmp_pusher.php test.mp4 rtmp://127.0.0.1:1935/live/stream 2.0

# Disable automatic reconnection
php rtmp_pusher.php test.flv rtmp://127.0.0.1:1935/live/stream 1.0 --no-reconnect
```

This project supports HTTP-FLV, WS-FLV, and RTMP push protocols. For more usage details, refer to [xiaosongshu/rtmp_server](https://github.com/2723659854/rtmp-server).

---

### PHP Pull Client (Test Tool)

Pull data from a live stream and save it as a local FLV file, suitable for long-term recording or functionality verification.

```php
require_once __DIR__ . '/vendor/autoload.php';

use Xiaosongshu\Flv2mp4\Manage\PullerManager;

ini_set('memory_limit', '2048M');

$pullUrl        = $argv[1];
$outputFlv      = __DIR__ . '/record/' . $argv[2];
$duration       = $argv[3] ?? 0;   // 0 means unlimited duration
$autoReconnect  = !in_array('--no-reconnect', $argv);

$puller = new PullerManager($pullUrl, $outputFlv, $duration, $autoReconnect);
$puller->start();
```

Usage examples:

```bash
# HTTP-FLV pull
php puller.php http://127.0.0.1:8501/live/stream.flv output.flv 0 --no-reconnect

# WebSocket pull
php puller.php ws://127.0.0.1:8501/live/stream.flv output.flv 0 --no-reconnect

# RTMP pull
php puller.php rtmp://127.0.0.1:1935/live/stream output.flv 0 --no-reconnect
```

Supported pull protocols: RTMP, HTTP-FLV, WS-FLV. For more information, see [xiaosongshu/rtmp_server](https://github.com/2723659854/rtmp-server).

---

### PHP Live Forwarding (Relay) Tool

Forward a single live stream to multiple target addresses simultaneously, with support for mixed protocols, facilitating cross-platform distribution.

```php
require_once __DIR__ . '/vendor/autoload.php';

use Xiaosongshu\Flv2mp4\Flv\FlvForwardClient;

ini_set('memory_limit', '2048M');

$pullUrl      = $argv[1];
$pushUrls     = array_map('trim', explode(',', $argv[2]));
$duration     = $argv[3] ?? 0;
$autoReconnect = !in_array('--no-reconnect', $argv);

$forwarder = new FlvForwardClient($pullUrl, $pushUrls, $duration, $autoReconnect);
$forwarder->start();
```

Usage example:

```bash
php forward.php http://127.0.0.1:8501/a/b.flv \
  "rtmp://127.0.0.1:1935/c/d,ws://127.0.0.1:8501/c/e,http://127.0.0.1:8501/c/f"
```

Supports RTMP, HTTP-FLV, and WS-FLV for both input and output. See [xiaosongshu/rtmp_server](https://github.com/2723659854/rtmp-server) for detailed configuration.

## 🧪 Testing and Playback

| Output Format   | Recommended Player        | Reference File      |
|-----------------|---------------------------|---------------------|
| Regular MP4     | HTML5 `<video>`           | `index.html`        |
| fMP4 Segments   | MSE player                | `play_merge.html`   |
| HLS (TS)        | hls.js / Safari           | `play.html`         |
| Merged FLV      | flv.js                    | `flv.html`          |

## 🎯 Use Cases

- **Live Recording** — Convert RTMP/FLV live streams into FMP4 or HLS in real time
- **Video Playback** — Watch recorded streams on demand at any time
- **Stream Forwarding** — Multi-level gateways for load balancing and edge acceleration
- **Offline Batch Processing** — Batch convert FLV file formats
- **Pseudo-live Push** — Push on-demand files as if they were live streams
- **Cross-platform Relaying** — Pull once and forward to multiple platforms simultaneously
- **Automation Integration** — Pure PHP implementation with no external dependencies, seamlessly embedding into existing PHP workflows

## 🔧 Technical Notes

This project is a companion tool for [xiaosongshu/rtmp_server](https://github.com/2723659854/rtmp-server), focusing on live stream recording, playback, and format conversion. All feature demonstration code can be found in the `rtmp_server` project.

- Pure PHP 8.1+ implementation, no FFmpeg dependency
- It is recommended to use [PHPStan](https://phpstan.org/) (Level 8) for static analysis to ensure code quality

## License

This project is open-sourced under the [Apache License 2.0](http://www.apache.org/licenses/LICENSE-2.0).  
You are free to use, modify, and distribute the code, including for commercial purposes. Please refer to the [LICENSE](LICENSE) file for the full terms.

### Disclaimer

The code in this project is provided "AS IS", without warranty of any kind, express or implied, including but not limited to the warranties of merchantability, fitness for a particular purpose, and noninfringement. In no event shall the authors be liable for any direct, indirect, incidental, special, punitive, or consequential damages arising from the use of this software, even if advised of the possibility of such damages. See the [LICENSE](LICENSE) file for the complete disclaimer.

## 📧 Contact

- 📬 Email: 2723659854@qq.com
- 🐙 GitHub: [2723659854](https://github.com/2723659854)
