# FLV ↔ MP4 / HLS 转换工具

<p align="center">
  <a href="./README.cn.md"><strong>🇨🇳 中文</strong></a> •
  <a href="./README.md"><strong>🇬🇧 English</strong></a>
</p>

纯 PHP 8.1+ 实现的轻量级媒体处理工具包，**零外部依赖（无需 FFmpeg）**。  
支持 FLV、FMP4、MP4、HLS 互转，直播流网关、推流、拉流、转播，以及 **H.264 解码 + 缩放 + 重新编码**（Baseline Profile）。

---

## 🎯 核心功能

| 功能 | 方向 | 说明 |
|------|------|------|
| 封装转换 | FLV ↔ MP4 / FMP4 | 生成标准 MP4 或分离的 fMP4 切片（兼容 MSE） |
| HLS 切片 | FLV → HLS | 生成 M3U8 + TS 切片，兼容 hls.js、VLC 等 |
| HLS 还原 | HLS → FLV | 将 HLS 切片合并还原为单 FLV 文件 |
| MP4 ↔ FLV | MP4 → FLV / FMP4 → FLV | 多容器格式互转 |
| 直播网关 | FLV 网关 | 高性能多级转发，支持高并发连接 |
| 静态文件服务 | HTTP 文件网关 | 轻量级文件服务器，支持目录浏览 |
| 推流客户端 | FLV / MP4 → RTMP/HTTP-FLV/WS-FLV | 将静态文件以伪直播方式推流 |
| 拉流客户端 | RTMP/HTTP-FLV/WS-FLV → FLV | 从直播流拉取并保存为本地 FLV |
| 转播客户端 | 多协议输入 → 多协议输出 | 一路拉流，多路转发 |
| **H.264 重编码** | 解码 → 缩放 → 编码 | 支持 Baseline Profile，为多码率 HLS 提供核心支持 |

---

## 环境依赖

| 依赖项 | 说明 |
|--------|------|
| PHP | ≥ 8.1（**仅 CLI 命令行模式**） |
| `sockets` 扩展 | **必需**，提供底层 Socket 通信 |
| `bcmath` 扩展 | 可选，高精度运算优化 |

💡 **无需 FFmpeg，无需任何第三方二进制程序，全部纯 PHP 实现。**

---

## 🚀 安装

```bash
composer require xiaosongshu/flv2mp4
```

---

## 📚 快速开始

```php
<?php

declare(strict_types=1);
require_once __DIR__ . '/vendor/autoload.php';

ini_set('memory_limit', '512M');

$file = __DIR__ . '/test.flv';

// 1. FLV → 混合 fMP4 切片
\Xiaosongshu\Flv2mp4\Client::runFlv2Fmp4Mixed($file, __DIR__ . '/output_merge');

// 2. FLV → 分离 fMP4 切片（音视频独立）
\Xiaosongshu\Flv2mp4\Client::runFlv2Fmp4Separate($file, __DIR__ . '/output_separate');

// 3. FLV → HLS
\Xiaosongshu\Flv2mp4\Client::runFlv2Hls($file, __DIR__ . '/hls');

// 4. HLS → FLV
\Xiaosongshu\Flv2mp4\Client::runHls2Flv(__DIR__ . '/hls/index.m3u8', __DIR__ . '/output.flv');

// 5. MP4 → FLV
\Xiaosongshu\Flv2mp4\Client::runMp42Flv(__DIR__ . '/test.mp4', __DIR__ . '/output.flv');

// 6. FLV → MP4
\Xiaosongshu\Flv2mp4\Client::runFlv2Mp4($file, __DIR__ . '/output.mp4');

// 7. fMP4 → FLV（混合/分离均支持）
\Xiaosongshu\Flv2mp4\Client::runFmp42Flv(__DIR__ . '/output_merge/index.m3u8', __DIR__ . '/output.flv');
```

---

## 🌐 高级功能

### FLV 直播网关

支持多级代理部署，实现高并发直播流转发。

```php
$gateway = new \Xiaosongshu\Flv2mp4\manage\FlvGateway(8080, 'http://127.0.0.1:8501');
$gateway->debug = true;
$gateway->start();
```

```bash
# 一级网关
php flvGateway.php 8080 http://127.0.0.1:8501
# 二级网关
php flvGateway.php 8081 http://127.0.0.1:8080
# 播放地址：http://127.0.0.1:8081/{app}/{stream}.flv
```

### 静态文件网关

轻量级 HTTP 文件服务器，支持目录浏览开关。

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

### 推流客户端

支持 HTTP-FLV、WS-FLV、RTMP 三种协议，倍速推流、断线重连。

```bash
# HTTP-FLV 推流
php flv_pusher.php test.flv http://127.0.0.1:8501/live/stream

# 2 倍速 + 禁用重连
php flv_pusher.php test.flv http://127.0.0.1:8501/live/stream 2.0 --no-reconnect

# RTMP 推流
php rtmp_pusher.php test.mp4 rtmp://127.0.0.1:1935/live/stream

# MP4 推流（2 倍速）
php mp4_pusher.php test.mp4 http://127.0.0.1:8501/live/stream 2.0
```

### 拉流客户端

从直播流拉取并保存为本地 FLV，适合录制或调试。

```bash
php puller.php http://127.0.0.1:8501/live/stream.flv output.flv 0 --no-reconnect
```

### 直播转发（转播）

一路拉流，同时转发至多个目标地址（支持协议混用）。

```bash
php forward.php http://127.0.0.1:8501/a/b.flv \
  "rtmp://127.0.0.1:1935/c/d,ws://127.0.0.1:8501/c/e,http://127.0.0.1:8501/c/f"
```

---

## 🧪 测试与播放

| 输出格式 | 推荐播放器 | 参考文件 |
|----------|------------|----------|
| MP4 | HTML5 `<video>` | `index.html` |
| fMP4 | MSE 播放器 | `play_merge.html`、`mse.html` |
| HLS (TS) | hls.js / Safari | `play.html` |
| FLV | flv.js | `flv.html` |

---

## 🎯 应用场景

- **直播录制**：RTMP/FLV 直播流实时转存为 fMP4 / HLS
- **视频回放**：录制流随时点播回看
- **流转发**：多级网关实现负载均衡与边缘加速
- **离线批处理**：批量转换 FLV / MP4 格式
- **伪直播推流**：点播文件伪装为直播流推送
- **跨平台转播**：一次拉流，同时转发到多个平台
- **多码率 HLS**：纯 PHP 实现的 H.264 重编码，生成自适应码率 HLS

---


### 🔥 H.264 解码 + 缩放 + 重编码

支持 Baseline Profile 的 H.264 解码、缩放、重新编码，为以下场景提供核心能力：

| 应用场景 | 说明 |
|----------|------|
| **多码率 HLS** | 将单路 FLV 转码为多分辨率 HLS 切片（自适应码率） |
| **FLV 重编码** | 修改分辨率、码率后重新输出为 FLV |
| **MP4 重编码** | 修改分辨率、码率后重新输出为 MP4 |
| **格式转换** | FLV ↔ MP4 转换时重新编码（而非仅封装） |
| **水印叠加** | 解码 YUV → 叠加 PNG/文字水印 → 重新编码输出 |
| **画质增强** | 解码后应用滤镜（锐化、降噪等）→ 重新编码 |
| **分辨率适配** | 将高分辨率视频降采样为多档分辨率输出 |
| **码率控制** | 将高码率视频重新编码为指定目标码率 |

**技术定位**：这是一个完整的 **H.264 像素处理管道**（解码 → 处理 → 编码），不依赖 FFmpeg，纯 PHP 实现。

---
以下为多码率hls示例：
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
echo "所有处理完成！\n";
```
其他功能请使用编码器自定义实现。h264详细使用方法见<a href="./src/Codec/README.md">READEME</a>`。

---

### 已支持的重编码特性

- [x] **I 帧解码与编码**（完全精确，INF dB）
- [x] **P 帧解码与编码**（Baseline Profile）
- [x] **帧内预测**：4x4（9种模式）+ 16x16（4种模式）
- [x] **帧间预测**：P 帧运动估计（菱形搜索优化）
- [x] **1/4 像素精度**：6-tap 滤波器插值
- [x] **CAVLC 熵编码**（Baseline Profile）
- [x] **分辨率缩放**（解码后 YUV 缩放 → 重新编码）
- [x] **码率控制**（通过 QP 参数调节）
- [ ] **B 帧支持**（计划中，需处理双向预测）
- [ ] **CABAC 熵编码**（计划中，Main Profile 支持）

---

## 🔧 技术说明

- 纯 PHP 8.1+ 实现，无 FFmpeg 依赖
- 配套 [xiaosongshu/rtmp_server](https://github.com/2723659854/rtmp-server) 使用
- 建议使用 [PHPStan](https://phpstan.org/) Level 8 进行静态分析

---

## 开源协议 & 免责声明

本项目基于 [Apache License 2.0](http://www.apache.org/licenses/LICENSE-2.0) 开源，可自由使用、修改、分发（含商业用途）。  
代码按“现状”（AS IS）提供，不提供任何明示或暗示的担保。作者不对因使用本软件而产生的任何损害承担责任。

---

## 📧 联系方式

- 📬 邮箱：2723659854@qq.com
- 🐙 GitHub：[2723659854](https://github.com/2723659854)