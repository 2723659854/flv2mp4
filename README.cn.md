你说得对，FLV 推流客户端部分缺少了完整的命令行入口示例。以下是修正后的完整 README 文档：

---

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

一个纯 PHP 8.1+ 编写的轻量级媒体处理工具包，**零外部依赖**，支持 FLV 与 MP4/HLS 之间的双向转换。

| 功能 | 方向 | 说明 |
|------|------|------|
| 🎬 转码封装 | FLV → MP4 | 生成标准 MP4 或 fMP4 切片 |
| 📺 切片分发 | FLV → HLS | 生成 M3U8 + TS 切片 |
| 🔄 逆向还原 | HLS → FLV | 将 HLS 切片合并还原 |
| 🔁 格式互转 | MP4 → FLV | MP4 文件转码回 FLV |
| 🌐 直播网关 | FLV Gateway | 高性能多级转发，支持高并发 |
| 📁 文件服务 | File Gateway | 轻量级 HTTP 文件服务器 |
| 📤 推流客户端 | FLV Pusher | 静态文件伪直播推流至 RTMP 服务器 |

> 适用于视频录制、回放、直播转发等场景，与 RTMP 直播服务器配合使用效果更佳。

## 📦 环境要求

- **PHP >= 8.1**
- 推荐使用 `ext-pcntl`（用于信号处理，可选）
- 无需 FFmpeg 或其他外部依赖

## 🚀 安装

```bash
composer require xiaosongshu/flv2mp4
```

## 📚 快速开始

### 基础转换

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

### 🌐 FLV 直播网关

支持多层级级联部署，轻松实现高并发直播流转发：

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
error_reporting(E_ALL);
set_time_limit(0);

// 监听端口 + 上游地址
$gateway = new \Xiaosongshu\Flv2mp4\manage\FlvGateway(8080, 'http://127.0.0.1:8501');
$gateway->debug = true;
$gateway->start();
```

**多级部署示例：**

```bash
# 一级网关（直连源站）
php gateway.php 8080 http://127.0.0.1:8501

# 二级网关（代理一级）
php gateway.php 8081 http://127.0.0.1:8080

# 三级网关（代理二级）
php gateway.php 8082 http://127.0.0.1:8081

# 播放地址：http://127.0.0.1:8082/{app}/{stream}.flv
```

### 📁 静态文件网关

内置高性能 HTTP 文件服务器，支持目录浏览：

```php
<?php
declare(strict_types=1);

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

### 📤 FLV 推流客户端

将静态 FLV 文件按原始时间戳以伪直播方式推送至 RTMP 服务器，支持断线重连、多倍速推流和实时进度上报。

**推流器特性：**

- ✅ 按原始时间戳精确推流（伪直播）
- ✅ 自动断线重连
- ✅ 内存优化（流式读取，不加载整个文件）
- ✅ 实时进度上报
- ✅ 支持推流倍速（0.5x / 1x / 2x）
- ✅ 详细的日志输出
- ✅ 信号处理（优雅退出）

**完整示例：**

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '2048M');

// ============ 命令行入口 ============

if (PHP_SAPI !== 'cli') {
    die("This script can only be run from command line.\n");
}

// 解析命令行参数
if ($argc < 2) {
    echo "Usage: php " . basename($argv[0]) . " <flv_file> [push_url] [speed] [--no-reconnect]\n";
    echo "\n";
    echo "Examples:\n";
    echo "  php flv_pusher.php test.flv\n";
    echo "  php flv_pusher.php test.flv http://127.0.0.1:8501/live/stream\n";
    echo "  php flv_pusher.php test.flv http://127.0.0.1:8501/live/stream 2.0\n";
    echo "  php flv_pusher.php test.flv http://127.0.0.1:8501/live/stream 1.0 --no-reconnect\n";
    echo "\n";
    echo "Options:\n";
    echo "  speed           推流速度倍数 (0.1-10.0, default: 1.0)\n";
    echo "  --no-reconnect  禁用自动重连\n";
    exit(1);
}

$flvFile = $argv[1];
$pushUrl = $argv[2] ?? 'http://127.0.0.1:8501/live/stream';
$speed = isset($argv[3]) ? (float)$argv[3] : 1.0;
$autoReconnect = !in_array('--no-reconnect', $argv);

// 创建推流器
$pusher = new \Xiaosongshu\Flv2mp4\manage\FLVPusher($flvFile, $pushUrl, $speed, $autoReconnect);

// 启动推流
$pusher->start();
```

**命令行用法：**

```bash
# 基础推流（1倍速）
php flv_pusher.php test.flv http://127.0.0.1:8501/live/stream

# 2倍速推流
php flv_pusher.php test.flv http://127.0.0.1:8501/live/stream 2.0

# 0.5倍慢速推流
php flv_pusher.php test.flv http://127.0.0.1:8501/live/stream 0.5

# 禁用自动重连
php flv_pusher.php test.flv http://127.0.0.1:8501/live/stream 1.0 --no-reconnect
```

| 参数 | 说明 | 默认值 |
|------|------|--------|
| `flv_file` | FLV 源文件路径 | *必填* |
| `push_url` | 推流目标地址 | `http://127.0.0.1:8501/live/stream` |
| `speed` | 推流倍速 (0.1 - 10.0) | `1.0` |
| `--no-reconnect` | 禁用断线重连 | 关闭（默认启用重连） |

## 🧪 测试与播放

| 输出格式 | 推荐播放器 | 参考文件 |
|----------|-----------|----------|
| 普通 MP4 | HTML5 `<video>` 标签 | `index.html` |
| fMP4 切片 | MSE 播放器 | `play_merge.html` |
| HLS (TS) | hls.js / 原生 Safari | `play.html` |
| 合并 FLV | flv.js | `flv.html` |

## 🎯 应用场景

- **直播录制**：RTMP 直播流实时转存为 MP4/HLS
- **视频回放**：录制的流随时点播回看
- **流分发**：多级网关实现负载均衡与边缘加速
- **离线批处理**：批量转换 FLV 文件格式
- **伪直播推流**：将点播文件伪装为直播流推送

## 🔧 技术背景

本项目最初为 [xiaosongshu/rtmp_server](https://github.com/2723659854/rtmp-server) 开发，为其提供直播流的录制与回放能力。

- 纯 PHP 8.1+ 实现，**无需 FFmpeg 或任何外部依赖**
- 支持严格类型声明 (`declare(strict_types=1)`)
- 推荐搭配 PHPStan Level 8 进行静态分析

## ⚠️ 免责声明

- 本项目仅供技术交流与学习使用
- 使用过程中产生的法律风险、商业纠纷或版权问题由使用者自行承担
- 请遵守当地法律法规，合理使用

## 📧 联系方式

- 📬 邮箱：2723659854@qq.com
- 🐙 GitHub：[2723659854](https://github.com/2723659854)