# FLV ↔ MP4 / HLS 转换工具

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

## 📖 简介

纯 PHP 8.1+ 实现的轻量级媒体处理工具包，**零外部依赖**（无需 FFmpeg），支持 FLV 与 MP4/HLS 之间的双向转换及直播流转发。

## 🎯 功能一览

| 功能 | 方向 | 说明 |
|------|------|------|
| 转码封装 | FLV → MP4 | 生成标准 MP4 或 fMP4 切片 |
| 切片分发 | FLV → HLS | 生成 M3U8 + TS 切片 |
| 逆向还原 | HLS → FLV | 将 HLS 切片合并还原 |
| 格式互转 | MP4 → FLV | MP4 文件转码回 FLV |
| 直播网关 | FLV Gateway | 高性能多级转发，支持高并发 |
| 文件服务 | File Gateway | 轻量级 HTTP 文件服务器 |
| 推流客户端 | FLV/MP4 Pusher | 静态文件伪直播推流至 RTMP 服务器 |

## 📦 环境要求

- PHP >= 8.1
- 推荐 `ext-pcntl`（用于信号处理，可选）
- 无 FFmpeg 或其他外部依赖

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

// 1. FLV → 合并 MP4
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

### 1. FLV 直播网关

支持多层级级联部署，实现高并发直播流转发。

**创建网关脚本**（例如 `gateway.php`）：

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

$gateway = new \Xiaosongshu\Flv2mp4\manage\FlvGateway(8080, 'http://127.0.0.1:8501');
$gateway->debug = true;
$gateway->start();
```

**启动服务**：

```bash
php gateway.php
```

**多级部署示例**：

```bash
# 一级网关（直连源站）
php gateway.php 8080 http://127.0.0.1:8501

# 二级网关（代理一级）
php gateway.php 8081 http://127.0.0.1:8080

# 播放地址：http://127.0.0.1:8081/{app}/{stream}.flv
```

### 2. 静态文件网关

高性能 HTTP 文件服务器，支持目录浏览。

**创建文件服务器脚本**（例如 `file_server.php`）：

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

**启动服务**：

```bash
php file_server.php
```

### 3. FLV 推流客户端

> ⚠️ **重要提示**：建议使用 **OBS** 或 **FFmpeg** 进行推流，这两个是行业标准的推流工具，稳定可靠。本工具的 PHP 推流客户端仅供学习参考，不建议在生产环境使用。

**创建推流脚本**（例如 `flv_pusher.php`）：

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    die("This script can only be run from command line.\n");
}

if ($argc < 2) {
    echo "Usage: php flv_pusher.php <flv_file> [push_url] [speed] [--no-reconnect]\n";
    echo "Examples:\n";
    echo "  php flv_pusher.php test.flv\n";
    echo "  php flv_pusher.php test.flv http://127.0.0.1:8501/live/stream\n";
    echo "  php flv_pusher.php test.flv ws://127.0.0.1:8501/live/stream\n";
    echo "  php flv_pusher.php test.flv http://127.0.0.1:8501/live/stream 2.0\n";
    echo "  php flv_pusher.php test.flv http://127.0.0.1:8501/live/stream 1.0 --no-reconnect\n";
    exit(1);
}

$flvFile = $argv[1];
$pushUrl = $argv[2] ?? 'http://127.0.0.1:8501/live/stream';
$speed = isset($argv[3]) ? (float)$argv[3] : 1.0;
$autoReconnect = !in_array('--no-reconnect', $argv);

$pusher = new \Xiaosongshu\Flv2mp4\manage\FLVPusherAll($flvFile, $pushUrl, $speed, $autoReconnect);
$pusher->start();
```

**命令行用法**：

| 参数 | 说明 | 默认值 |
|------|------|--------|
| `flv_file` | FLV 源文件路径 | **必填** |
| `push_url` | 推流目标地址（支持 http / ws） | `http://127.0.0.1:8501/live/stream` |
| `speed` | 推流倍速（0.1–10.0） | `1.0` |
| `--no-reconnect` | 禁用断线重连 | 关闭（默认启用重连） |

```bash
# HTTP 推流
php flv_pusher.php test.flv http://127.0.0.1:8501/live/stream

# WebSocket 推流
php flv_pusher.php test.flv ws://127.0.0.1:8501/live/stream

# 2倍速推流
php flv_pusher.php test.flv http://127.0.0.1:8501/live/stream 2.0

# 禁用自动重连
php flv_pusher.php test.flv http://127.0.0.1:8501/live/stream 1.0 --no-reconnect
```

### 4. MP4 推流客户端

> ⚠️ **重要提示**：建议使用 **OBS** 或 **FFmpeg** 进行推流，本工具的 PHP 推流客户端仅供学习参考。

**创建推流脚本**（例如 `mp4_pusher.php`）：

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    die("This script can only be run from command line.\n");
}

if ($argc < 2) {
    echo "Usage: php mp4_pusher.php <mp4_file> [push_url] [speed] [--no-reconnect]\n";
    echo "Examples:\n";
    echo "  php mp4_pusher.php test.mp4\n";
    echo "  php mp4_pusher.php test.mp4 http://127.0.0.1:8501/live/stream\n";
    echo "  php mp4_pusher.php test.mp4 ws://127.0.0.1:8501/live/stream\n";
    echo "  php mp4_pusher.php test.mp4 http://127.0.0.1:8501/live/stream 2.0\n";
    exit(1);
}

$mp4File = $argv[1];
$pushUrl = $argv[2] ?? 'http://127.0.0.1:8501/live/stream';
$speed = isset($argv[3]) ? (float)$argv[3] : 1.0;
$autoReconnect = !in_array('--no-reconnect', $argv);

$pusher = new \Xiaosongshu\Flv2mp4\Manage\Mp4PusherAll($mp4File, $pushUrl, $speed, $autoReconnect);
$pusher->start();
```

```bash
# HTTP 推流
php mp4_pusher.php test.mp4 http://127.0.0.1:8501/live/stream

# WebSocket 推流
php mp4_pusher.php test.mp4 ws://127.0.0.1:8501/live/stream

# 2倍速推流
php mp4_pusher.php test.mp4 http://127.0.0.1:8501/live/stream 2.0
```

### 5. 标准推流工具推荐

生产环境请使用以下标准工具推流：

```bash
# FFmpeg 推流示例
ffmpeg -re -i test.flv -c copy -f flv rtmp://server/live/stream

# 重新编码为标准格式（H.264 + AAC）
ffmpeg -re -i test.flv -c:v libx264 -c:a aac -f flv rtmp://server/live/stream

# OBS Studio
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

- **直播录制**：RTMP 直播流实时转存为 MP4/HLS
- **视频回放**：录制的流随时点播回看
- **流分发**：多级网关实现负载均衡与边缘加速
- **离线批处理**：批量转换 FLV 文件格式
- **伪直播推流**：将点播文件伪装为直播流推送（测试用）

## 🔧 技术背景

本项目为 [xiaosongshu/rtmp_server](https://github.com/2723659854/rtmp-server) 的配套工具，提供直播流的录制与回放能力。

- 纯 PHP 8.1+ 实现，**无 FFmpeg 依赖**
- 严格类型声明 (`declare(strict_types=1)`)
- 建议搭配 PHPStan Level 8 进行静态分析

## ⚠️ 免责声明

- 仅供技术交流与学习使用
- 法律风险、商业纠纷或版权问题由使用者自行承担
- 请遵守当地法律法规，合理使用

## 📧 联系方式

- 📬 邮箱：2723659854@qq.com
- 🐙 GitHub：[2723659854](https://github.com/2723659854)