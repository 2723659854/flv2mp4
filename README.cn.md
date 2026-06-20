# FLV ↔ MP4 / HLS 转换工具
<p align="center">
  <a href="./README.cn.md"><strong>🇨🇳 中文</strong></a> •
  <a href="./README.md"><strong>🇬🇧 English</strong></a>
</p>

纯 PHP 8.1+ 实现的轻量级媒体处理工具包，**零外部依赖**（无需 FFmpeg），支持 FLV 与 MP4/HLS 双向转换及直播流转发。

## 🎯 核心功能

| 功能 | 方向 | 说明 |
|------|------|------|
| 转码封装 | FLV → MP4 | 生成标准 MP4 或分离的 fMP4 切片 |
| 切片分发 | FLV → HLS | 生成 M3U8 + TS 切片，兼容 hls.js/VLC 等播放器 |
| 逆向还原 | HLS → FLV | HLS 切片合并还原为 FLV |
| 格式互转 | MP4 → FLV | MP4 文件转码为 FLV |
| 直播网关 | FLV Gateway | 高性能多级转发，支持高并发 |
| 文件服务 | File Gateway | 轻量级 HTTP 文件服务器 |
| 推流客户端 | FLV / MP4 / RTMP | 静态文件伪直播推流至 RTMP 服务器 |

## 环境依赖

| 依赖项 | 说明 |
|--------|------|
| PHP | >= 8.1（仅 CLI 命令行模式运行） |
| `sockets` 扩展 | **必需**，提供底层 Socket 通信能力 |

## 🚀 安装

```bash
composer require xiaosongshu/flv2mp4
```

## 📚 快速开始

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

ini_set('memory_limit', '512M');

$file = __DIR__ . '/test.flv';

// 1. FLV → MP4
$result = \Xiaosongshu\Flv2mp4\Client::runFlv2mp4($file, __DIR__ . '/output_merge');

// 2. FLV → 分离的音视频 fMP4 切片
$result = \Xiaosongshu\Flv2mp4\Client::runFlv2mp4Separate($file, __DIR__ . '/output_separate');

// 3. FLV → HLS
$result = \Xiaosongshu\Flv2mp4\Client::runFlv2Hls($file, __DIR__ . '/hls');

// 4. HLS → FLV
$result = \Xiaosongshu\Flv2mp4\Client::runHls2Flv($m3u8Path, __DIR__ . '/output.flv');

// 5. MP4 → FLV
$result = \Xiaosongshu\Flv2mp4\Client::runMp42Flv($mp4File, __DIR__ . '/output.flv');
```

## 🌐 高级功能

### FLV 直播网关

支持多层级联部署，实现高并发直播流转发。

```php
<?php

require_once __DIR__ . '/vendor/autoload.php';

$gateway = new \Xiaosongshu\Flv2mp4\manage\FlvGateway(8080, 'http://127.0.0.1:8501');
$gateway->debug = true;
$gateway->start();
```

```bash
# 一级网关（直连源站）
php gateway.php 8080 http://127.0.0.1:8501

# 二级网关（代理一级）
php gateway.php 8081 http://127.0.0.1:8080

# 播放地址：http://127.0.0.1:8081/{app}/{stream}.flv
```

### 静态文件网关

高性能 HTTP 文件服务器，支持目录浏览。

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

### 推流客户端

> ⚠️ **重要提示**：生产环境请使用 [OBS](https://obsproject.com/) 或 [FFmpeg](https://ffmpeg.org/) 进行推流，本工具的 PHP 推流客户端仅供学习与测试参考。

#### 命令行参数

| 参数 | 说明 | 默认值 |
|------|------|--------|
| `file` | FLV / MP4 源文件路径 | **必填** |
| `push_url` | 推流目标地址（HTTP / WS / RTMP） | `http://127.0.0.1:8501/live/stream` |
| `speed` | 推流倍速（0.1 – 10.0） | `1.0` |
| `--no-reconnect` | 禁用断线重连 | 默认启用重连 |

#### HTTP / WebSocket 推流

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
# HTTP 推流
php flv_pusher.php test.flv http://127.0.0.1:8501/live/stream

# WebSocket 推流
php flv_pusher.php test.flv ws://127.0.0.1:8501/live/stream

# 2 倍速推流
php flv_pusher.php test.flv http://127.0.0.1:8501/live/stream 2.0

# 禁用自动重连
php flv_pusher.php test.flv http://127.0.0.1:8501/live/stream 1.0 --no-reconnect
```

```php
// MP4 推流
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

#### RTMP 推流

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
# FLV RTMP 推流
php rtmp_pusher.php test.flv rtmp://127.0.0.1:1935/live/stream

# MP4 RTMP 推流
php rtmp_pusher.php test.mp4 rtmp://127.0.0.1:1935/live/stream 2.0

# 禁用自动重连
php rtmp_pusher.php test.flv rtmp://127.0.0.1:1935/live/stream 1.0 --no-reconnect
```

### 标准推流工具推荐（生产环境）

```bash
# FFmpeg 推流（无损）
ffmpeg -re -i test.flv -c copy -f flv rtmp://server/live/stream

# FFmpeg 推流（重新编码为 H.264 + AAC）
ffmpeg -re -i test.flv -c:v libx264 -c:a aac -f flv rtmp://server/live/stream

# OBS Studio 推流
# 图形化界面，支持 RTMP / FLV 推流，配置简单，功能强大
```

## 🧪 测试与播放

| 输出格式 | 推荐播放器 | 参考文件 |
|----------|-----------|----------|
| 普通 MP4 | HTML5 `<video>` | `index.html` |
| fMP4 切片 | MSE 播放器 | `play_merge.html` |
| HLS (TS) | hls.js / Safari | `play.html` |
| 合并 FLV | flv.js | `flv.html` |

## 🎯 应用场景

- **直播录制** — RTMP 直播流实时转存为 MP4 / HLS
- **视频回放** — 录制流随时点播回看
- **流转发** — 多级网关实现负载均衡与边缘加速
- **离线批处理** — 批量转换 FLV 文件格式
- **伪直播推流** — 点播文件伪装为直播流推送（测试用）

## 🔧 技术背景

本项目为 [xiaosongshu/rtmp_server](https://github.com/2723659854/rtmp-server) 的配套工具，提供直播流的录制与回放能力。

- 纯 PHP 8.1+ 实现，**无 FFmpeg 依赖**
- 严格类型声明（`declare(strict_types=1)`）
- 推荐搭配 [PHPStan](https://phpstan.org/) Level 8 进行静态分析

## ⚠️ 免责声明

- 本工具仅供技术交流与学习使用
- 法律风险、商业纠纷或版权问题由使用者自行承担
- 请遵守当地法律法规，合理使用

## 📧 联系方式

- 📬 邮箱：2723659854@qq.com
- 🐙 GitHub：[2723659854](https://github.com/2723659854)