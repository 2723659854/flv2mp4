# FLV ↔ MP4 / HLS 转换工具

<p align="center">
  <a href="./README.cn.md"><strong>🇨🇳 中文</strong></a> •
  <a href="./README.md"><strong>🇬🇧 English</strong></a>
</p>

纯 PHP 8.1+ 实现的轻量级媒体处理工具包，**零外部依赖（无需 FFmpeg）**。  
支持 FLV 与 FMP4 / MP4 / HLS 的双向转换、直播流转发、推流、拉流及转播，便于集成到自动化流水线。

## 🎯 核心功能

| 功能         | 方向                             | 说明                                              |
|--------------|--------------------------------|-------------------------------------------------|
| 转码封装     | FLV → MP4 / FMP4               | 生成标准 MP4 或分离的 fMP4 切片（适用于 MSE）                  |
| 切片分发     | FLV → HLS                      | 生成 M3U8 + TS 切片，兼容 hls.js、VLC 等播放器              |
| 逆向还原     | HLS → FLV                      | 将 HLS 切片合并还原为单个 FLV 文件                          |
| 格式互转     | MP4 → FLV                      | 将 MP4 文件转码为 FLV 封装格式                            |
| 格式互转     | FMP4 → FLV                     | 将 FMP4切片合并为 FLV 格式                            |
| 直播网关     | FLV 网关                         | 高性能多级转发，支持高并发连接                                 |
| 文件服务     | HTTP 文件网关                      | 轻量级静态文件服务器，支持目录浏览                               |
| 推流客户端   | FLV / MP4 → RTMP               | 将静态文件以伪直播方式推流至 RTMP 服务器（HTTP-FLV / WS-FLV 同样支持） |
| 拉流客户端   | HTTP-FLV / WS-FLV / RTMP → FLV | 从直播流拉取并保存为本地 FLV 文件                             |
| 转播客户端   | 多协议输入 → 多协议输出                  | 拉取直播流后同时转发至多个目标地址                               |

## 环境依赖

| 依赖项       | 说明                                            |
|--------------|-------------------------------------------------|
| PHP          | ≥ 8.1（**仅 CLI 命令行模式**运行，不支持 Web 环境） |
| `sockets` 扩展 | **必需**，提供底层 Socket 通信能力                |

> 💡 所有功能均基于原生 PHP 实现，无需安装 FFmpeg 或任何第三方二进制程序。

## 🚀 安装

通过 Composer 安装：

```bash
composer require xiaosongshu/flv2mp4
```

## 📚 快速开始

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

// 建议提高内存限制以应对大文件处理
ini_set('memory_limit', '512M');

$file = __DIR__ . '/test.flv';

// 1. FLV → 音视频混合 fMP4 切片
$result = \Xiaosongshu\Flv2mp4\Client::runFlv2Fmp4Mixed($file, __DIR__ . '/output_merge');

// 2. FLV → 分离的音视频 fMP4 切片
$result = \Xiaosongshu\Flv2mp4\Client::runFlv2Fmp4Separate($file, __DIR__ . '/output_separate');

// 3. FLV → HLS
$result = \Xiaosongshu\Flv2mp4\Client::runFlv2Hls($file, __DIR__ . '/hls');

// 4. HLS → FLV
$result = \Xiaosongshu\Flv2mp4\Client::runHls2Flv(__DIR__ . "/hls/abc/index.m3u8", __DIR__ . '/output.flv');

// 5. MP4 → FLV
$result = \Xiaosongshu\Flv2mp4\Client::runMp42Flv(__DIR__ . '/test.mp4', __DIR__ . '/output.flv');

// 6. FLV → MP4
$result = \Xiaosongshu\Flv2mp4\Client::runFlv2Mp4($mp4File, __DIR__ . '/output.mp4');

// 7. separate audio/video fMP4 segments → FLV
$result = \Xiaosongshu\Flv2mp4\Client::runFmp42Flv(__DIR__."/output_separate/index.m3u8", __DIR__ . "/004.flv");

// 8 . mixed audio/video fMP4 segments  → FLV
$result = \Xiaosongshu\Flv2mp4\Client::runFmp42Flv(__DIR__."/output_merge/index.m3u8", __DIR__ . "/004.flv");
```

## 🌐 高级功能

### FLV 直播网关

支持多层级联部署，通过代理方式实现高并发直播流转发。

```php
<?php

require_once __DIR__ . '/vendor/autoload.php';

$gateway = new \Xiaosongshu\Flv2mp4\manage\FlvGateway(8080, 'http://127.0.0.1:8501');
$gateway->debug = true;
$gateway->start();
```

命令行启动示例：

```bash
# 一级网关（直连源站）
php flvGateway.php 8080 http://127.0.0.1:8501

# 二级网关（代理一级）
php flvGateway.php 8081 http://127.0.0.1:8080

# 播放地址：http://127.0.0.1:8081/{app}/{stream}.flv
```

### 静态文件网关

高性能 HTTP 文件服务器，可快速共享目录内容，并支持目录浏览开关。

```php
<?php

require_once __DIR__ . '/vendor/autoload.php';

$server = new \Xiaosongshu\Flv2mp4\manage\FileGateway(
    host: '0.0.0.0',
    port: 8100,
    documentRoot: __DIR__,
    enableDirListing: false   // 生产环境建议关闭目录列表
);
$server->debug = true;
$server->start();
```

```bash
php fileGateway.php
```

### 推流客户端

#### 命令行参数

| 参数             | 说明                                  | 默认值                              |
|------------------|---------------------------------------|-------------------------------------|
| `file`           | FLV / MP4 源文件路径                   | **必填**                            |
| `push_url`       | 推流目标地址（HTTP / WS / RTMP）       | `http://127.0.0.1:8501/live/stream` |
| `speed`          | 推流倍速（0.1 – 10.0）                 | `1.0`                               |
| `--no-reconnect` | 禁用断线重连                          | 默认启用重连                         |

#### HTTP / WebSocket 推流

```php
<?php

require_once __DIR__ . '/vendor/autoload.php';

// FLV 推流
$pusher = new \Xiaosongshu\Flv2mp4\manage\FLVPusherAll(
    flvFile: 'test.flv',
    pushUrl: 'http://127.0.0.1:8501/live/stream',
    speed: 1.0,
    autoReconnect: true
);
$pusher->start();

// MP4 推流
$mp4Pusher = new \Xiaosongshu\Flv2mp4\Manage\Mp4PusherAll(
    mp4File: 'test.mp4',
    pushUrl: 'http://127.0.0.1:8501/live/stream',
    speed: 2.0
);
$mp4Pusher->start();
```

命令行示例：

```bash
# HTTP-FLV 推流
php flv_pusher.php test.flv http://127.0.0.1:8501/live/stream

# WebSocket 推流
php flv_pusher.php test.flv ws://127.0.0.1:8501/live/stream

# 2 倍速推流
php flv_pusher.php test.flv http://127.0.0.1:8501/live/stream 2.0

# 禁用自动重连
php flv_pusher.php test.flv http://127.0.0.1:8501/live/stream 1.0 --no-reconnect

# MP4 文件推流
php mp4_pusher.php test.mp4 http://127.0.0.1:8501/live/stream 2.0
```

#### RTMP 推流

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

命令行示例：

```bash
# FLV RTMP 推流
php rtmp_pusher.php test.flv rtmp://127.0.0.1:1935/live/stream

# MP4 RTMP 推流（2 倍速）
php rtmp_pusher.php test.mp4 rtmp://127.0.0.1:1935/live/stream 2.0

# 禁用自动重连
php rtmp_pusher.php test.flv rtmp://127.0.0.1:1935/live/stream 1.0 --no-reconnect
```

本项目同时支持 HTTP-FLV、WS-FLV 和 RTMP 三种推流协议，更多用法可参考 [xiaosongshu/rtmp_server](https://github.com/2723659854/rtmp-server)。

---

### PHP 拉流测试工具

从直播流拉取数据并保存为本地 FLV 文件，适合长期录制或功能验证。

```php
require_once __DIR__ . '/vendor/autoload.php';

use Xiaosongshu\Flv2mp4\Manage\PullerManager;

ini_set('memory_limit', '2048M');

$pullUrl        = $argv[1];
$outputFlv      = __DIR__ . '/record/' . $argv[2];
$duration       = $argv[3] ?? 0;   // 0 表示不限时长
$autoReconnect  = !in_array('--no-reconnect', $argv);

$puller = new PullerManager($pullUrl, $outputFlv, $duration, $autoReconnect);
$puller->start();
```

使用示例：

```bash
# HTTP-FLV 拉流
php puller.php http://127.0.0.1:8501/live/stream.flv output.flv 0 --no-reconnect

# WebSocket 拉流
php puller.php ws://127.0.0.1:8501/live/stream.flv output.flv 0 --no-reconnect

# RTMP 拉流
php puller.php rtmp://127.0.0.1:1935/live/stream output.flv 0 --no-reconnect
```

支持的拉流协议：RTMP、HTTP-FLV、WS-FLV。更多信息见 [xiaosongshu/rtmp_server](https://github.com/2723659854/rtmp-server)。

---

### PHP 直播转发（转播）工具

将一路直播流同时转发至多个目标地址，支持协议混用，方便跨平台分发。

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

使用示例：

```bash
php forward.php http://127.0.0.1:8501/a/b.flv \
  "rtmp://127.0.0.1:1935/c/d,ws://127.0.0.1:8501/c/e,http://127.0.0.1:8501/c/f"
```

支持 RTMP、HTTP-FLV、WS-FLV 三种协议的输入与输出，详细配置参见 [xiaosongshu/rtmp_server](https://github.com/2723659854/rtmp-server)。

## 🧪 测试与播放

| 输出格式       | 推荐播放器          | 参考文件                         |
|----------------|---------------------|------------------------------|
| 普通 MP4       | HTML5 `<video>`     | `index.html`                 |
| fMP4 切片      | MSE 播放器          | `play_merge.html`,`mse.html` |
| HLS (TS)       | hls.js / Safari     | `play.html`                  |
| 合并 FLV       | flv.js              | `flv.html`                   |

## 🎯 应用场景

- **直播录制** — RTMP/FLV 直播流实时转存为 FMP4 或 HLS
- **视频回放** — 录制流随时点播回看
- **流转发** — 多级网关实现负载均衡与边缘加速
- **离线批处理** — 批量转换 FLV 文件格式
- **伪直播推流** — 将点播文件伪装为直播流推送
- **跨平台转播** — 一次拉流，同时转发到多个平台
- **自动化集成** — 纯 PHP 实现，无需外部依赖，可无缝嵌入现有 PHP 工作流

## 🔧 技术说明

本项目是 [xiaosongshu/rtmp_server](https://github.com/2723659854/rtmp-server) 的配套工具，专注于直播流的录制、回放与格式转换。所有功能示例代码均可在 `rtmp_server` 项目中找到。

- 纯 PHP 8.1+ 实现，无 FFmpeg 依赖
- 建议使用 [PHPStan](https://phpstan.org/)（Level 8）进行静态分析以保证代码质量

## 开源协议

本项目基于 [Apache License 2.0](http://www.apache.org/licenses/LICENSE-2.0) 开源。  
您可以自由地使用、修改、分发本项目的代码，包括商业用途。具体条款请参阅 [LICENSE](LICENSE) 文件。

### 免责声明

本项目的代码按“现状”（AS IS）提供，不提供任何明示或暗示的担保，包括但不限于适销性、特定用途适用性和非侵权性的担保。在任何情况下，作者均不对因使用本软件而产生的任何直接、间接、偶然、特殊、惩罚性或后果性损害承担责任，即使已被告知可能发生此类损害。详细免责条款请参阅 [LICENSE](LICENSE) 文件。

## 📧 联系方式

- 📬 邮箱：2723659854@qq.com
- 🐙 GitHub：[2723659854](https://github.com/2723659854)