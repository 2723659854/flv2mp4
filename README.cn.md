# 纯 PHP 音视频处理引擎：FLV/MP4/HLS 互转 · H.264 解码与重编码 · AAC/MP3/Opus/WAV 全格式互转
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

## 项目简介
纯 PHP 8.1+ 实现的轻量级媒体处理工具包，**零外部依赖（无需 FFmpeg）**。  
支持 FLV、FMP4、MP4、HLS 互转，直播流网关、推流、拉流、转播，以及 **H.264 解码 + 缩放 + 重新编码**（输入支持 Baseline/Main/High Profile）+ AAC/MP3/Opus/WAV 全格式互转。

---
## 📋 目录

- [项目简介](#项目简介)
- [核心功能](#-核心功能)
- [环境依赖](#环境依赖)
- [安装](#-安装)
- [快速开始](#-快速开始)
- [高级功能](#-高级功能)
    - [Opus转AAC](#opus-2-aac)
    - [FLV 直播网关](#flv-直播网关)
    - [静态文件网关](#静态文件网关)
    - [推流客户端](#推流客户端)
    - [拉流客户端](#拉流客户端)
    - [直播转发（转播）](#直播转发)
- [测试与播放](#-测试推流播放)
- [应用场景](#-应用场景)
- [H.264 重编码详解](#-h264-解码--缩放--重编码)
    - [FLV/MP4/HLS 压缩转码](#flvmp4hls压缩转码)
    - [直播压缩转码](#直播压缩转码)
    - [已支持的重编码特性](#已支持的重编码特性)
    - [水印生成工具](#水印生成工具)
    - [性能测试报告](#性能测试报告)
- [AAC-MP3-OPUS-WAV的编码解码](#AAC-MP3-OPUS-WAV的编码解码)
- [技术说明](#-技术说明)
- [开源协议 & 免责声明](#开源协议--免责声明)
- [联系方式](#-联系方式)

---


---

## 🎯 核心功能

| 功能            | 方向                               | 说明                                  |
|---------------|----------------------------------|-------------------------------------|
| 封装转换          | FLV ↔ MP4 / FMP4                 | 生成标准 MP4 或分离的 fMP4 切片（兼容 MSE）       |
| HLS 切片        | FLV → HLS                        | 生成 M3U8 + TS 切片，兼容 hls.js、VLC 等     |
| HLS 还原        | HLS → FLV                        | 将 HLS 切片合并还原为单 FLV 文件               |
| MP4 ↔ FLV     | MP4 → FLV / FMP4 → FLV           | 多容器格式互转                             |
| 直播网关          | FLV 网关                           | 高性能多级转发，支持高并发连接                     |
| 静态文件服务        | HTTP 文件网关                        | 轻量级文件服务器，支持目录浏览                     |
| 推流客户端         | FLV / MP4 → RTMP/HTTP-FLV/WS-FLV | 将静态文件以伪直播方式推流                       |
| 拉流客户端         | RTMP/HTTP-FLV/WS-FLV → FLV       | 从直播流拉取并保存为本地 FLV                    |
| 转播客户端         | 多协议输入 → 多协议输出                    | 一路拉流，多路转发                           |
| **H.264 重编码** | 解码 → 缩放 → 编码                     | 输入兼容 Baseline/Main/High Profile，输出 Baseline，为多码率 HLS 提供核心支持 |
| **OPUS→AAC**  | opus→pcm→aac                     | 支持 webrtc的音频opus转码为AAC-LC           |
| **opus/aac/mp3/wav格式互转** | 原始格式音频→pcm→目标格式音频                | 支持 opus/aac/mp3/wav格式音频相互转码         |
---
## 环境依赖

| 依赖项          | 说明                                              |
|--------------|-------------------------------------------------|
| PHP          | ≥ 8.1（**仅 CLI 命令行模式**）                          |
| `sockets` 扩展 | **可选**，提供底层 Socket 通信，仅直播和h264重编码相关场景需要         |
| `gd` 扩展      | **可选**，用于从 PNG/JPG 图片生成水印。如果未安装，将自动降级为内置点阵字体模式。 |

- 💡 **仅支持 PHP CLI 命令行运行，不支持 Nginx/FPM 网页模式调用。**
- 💡 **无需 FFmpeg，无需任何第三方二进制程序，全部纯 PHP 实现。**
- 💡 **容器封装转换（FLV/MP4/HLS 互转，仅修改封装格式，速度快）。**
- ⚠️ **H.264 重编码功能要求 `proc_open` 不被禁用。**（该模块采用多进程分布式计算，需要创建子进程来并行处理帧数据。）
- ⚠️ **Opus 实时转码（仅直播场景）要求 `proc_open` 不被禁用。**（在 WebRTC 转 RTMP 等实时场景下，会启动独立的后台 Worker 进程进行音频转码；静态文件的 Opus 转码不需要此函数。）
- ⚠️ **H.264 重编码模块属于 CPU 密集型计算，性能取决于服务器配置与分辨率/帧率，是否可用于直播实时场景请结合实际硬件压测评估，同时建议开启 JIT 加速。**
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

## 🌐 高级功能

### Opus 2 AAC

`WebRtcFlvRelay` 可接收 WebRTC RTP 数据，将 H.264 视频封装为 FLV，并通过纯 PHP Worker 把 Opus 音频实时转码为 AAC-LC，再推送到 WebSocket-FLV 服务，由该服务继续录制或转发到 RTMP 等目标。

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

// 在 WebRTC 服务端的 RTP 回调中调用：
// $relay->pushRtp($plainRtp, 'video');
// $relay->pushRtp($plainRtp, 'audio');

// 推流结束时关闭 relay，并在主进程退出时关闭自动启动的 Worker。
$relay->finish();
OpusWorkerClient::shutdownOwnedWorkers();
```

项目根目录的 `examples\webrtc.php` 提供了完整的 WebRTC 转 FLV 示例。常用配置如下：

```php
// 每个项目实例应使用不同的 Worker 端口。
$opusWorkerPort = 8330;

// 支持 RTMP、HTTP-FLV 和 WebSocket-FLV 服务提供的推流地址；
// 示例默认使用 WebSocket-FLV，并以 streamId 替换占位符。
$wsFlvPushUrl = 'ws://127.0.0.1:8501/live/{streamId}';
```

运行示例：

```bash
php webrtc.php
```

说明：

- relay 连接时会自动启动 `bin/opus-worker.php`（若目标端口尚无 Worker），无需手动启动 Worker；
- Worker 仅监听 `127.0.0.1`，默认端口为 `8330`；
- 自动启动时会把宿主项目真实的 `vendor/autoload.php` 通过 `--autoload` 传给 Worker，兼容通过 `composer require xiaosongshu/flv2mp4` 安装及自定义 `vendor-dir`；
- 默认输出为 `48kHz`、单声道、`64kbps` AAC-LC；
- 同一个 Worker 进程可以管理多路独立连接，但纯 PHP 实时转码会消耗较多 CPU，建议单实例先按一路实时节目规划；
- 同一台机器启动多个项目实例时，必须为每个实例配置不同的 `$opusWorkerPort`；
- 主进程收到 `Ctrl+C` 或退出时，应调用 `OpusWorkerClient::shutdownOwnedWorkers()`，`start.php` 已包含相应的退出处理；
- PHP 必须允许使用 `proc_open`，否则无法自动创建 Worker 子进程；
- Worker 队列包含有界背压保护。不要仅通过扩大队列解决性能不足，否则可能增加音频延迟并造成音视频不同步。
- webrtc服务需要用到工具包`xiaosongshu/webrtc`。

### FLV 直播网关

支持多级代理部署，实现高并发直播流转发。新建文件`flvGateway.php`，内容如下:

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';
$gateway = new \Xiaosongshu\Flv2mp4\Manage\FlvGateway(8080, 'http://127.0.0.1:8501');
$gateway->debug = true;
$gateway->start();
```
启动flv网关
```bash
php flvGateway.php
```

### 静态文件网关

轻量级 HTTP 文件服务器，支持目录浏览开关。新建文件`fileGateway.php`，内容如下:

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';
$server = new \Xiaosongshu\Flv2mp4\Manage\FileGateway( '0.0.0.0',8100,__DIR__,false);
$server->debug = true;
$server->start();
```
启动file网关
```bash
php fileGateway.php
```
### 推流客户端

支持 HTTP-FLV、WS-FLV、RTMP 三种协议，倍速推流、断线重连。新建文件`pusher.php`，内容如下:
```php
require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '2048M');
$pusher = new \Xiaosongshu\Flv2mp4\Manage\PusherManage(__DIR__."/test.flv", "http://127.0.0.1:8501/live/stream", 1.0, false);
$pusher->start();
```
启动推流
```bash
php pusher.php
```

### 拉流客户端

从直播流拉取并保存为本地 FLV，适合录制或调试。新建文件`puller.php`，内容如下：
```php
require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '2048M');
$puller = new \Xiaosongshu\Flv2mp4\Manage\PullerManage("ws://127.0.0.1:8501/live/stream.flv", __DIR__."/pull_record.flv", 0, false);
$puller->start();
```
启动拉流客户端
```bash
php puller.php
```

### 直播转发

一路拉流，同时转发至多个目标地址（支持协议混用）。新建文件`forward.php`，内容如下：
```php
require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '2048M');
$forwarder = new \Xiaosongshu\Flv2mp4\Flv\FlvForwardClient("http://127.0.0.1:8501/a/b.flv", ["rtmp://127.0.0.1:1935/c/d","ws://127.0.0.1:8501/c/e"], 0, true);
$forwarder->start();
```
启动转播客户端
```bash
php forward.php
```

---

## 🧪 测试&推流&播放

| 输出格式 | 推荐播放器           | 参考文件                         |
|----------|-----------------|------------------------------|
| MP4 | HTML5 `<video>` | `index.html`                 |
| fMP4 | MSE 播放器         | `play_merge.html`、`mse.html` |
| HLS (TS) | hls.js / Safari | `play.html`                  |
| FLV | flv.js          | `flv.html`                   |
| FLV | web推流测试         | `push.html`                    |

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

支持 H.264 解码、缩放、重新编码，为以下场景提供核心能力：
- 这是一个完整的 **H.264 像素处理管道**（解码 → 处理 → 编码），不依赖 FFmpeg，纯 PHP 实现。
- 当前支持baseline/main/high profile 输入，输出统一为baseline profile。含 B 帧的 Main/High 输入会被解码后转为 P 帧编码，输出流无 B 帧（dts 与 pts 一致、时间戳单调）。

---
####  flv/mp4/hls压缩转码
以下是示例代码：
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
    'watermark'      => false,
    'watermarkFile'  => __DIR__."/watermark_80x16.yuv",
    'fastMotion'     => true,
    'motionWorkers'  => 1,
    'decodeWorkers'  => 12,
    'segmentDuration'=> 3,
];
# flv -> hls
(new \Xiaosongshu\Flv2mp4\Recode\PurePhpHlsGenerator($config,__DIR__ . '/hls/output',true,))->processFlv(__DIR__ . '/test.flv');
# flv -> flv
(new \Xiaosongshu\Flv2mp4\Recode\FlvRecoder($config, true))->processFlv(__DIR__ . '/test.flv', __DIR__.'/output.flv');
# mp4 -> mp4
(new \Xiaosongshu\Flv2mp4\Recode\Mp4Recoder($config, true))->processMp4($mp4File, __DIR__ . '/output1.mp4');
```
- 添加水印的时候，文件名格式：（watermark_{width}x{height}.yuv）。
- 重编码模块提供了 **YUV 像素级操作接口**，你可以基于此实现自定义功能，如添加字幕、画中画、视频拼接等。

---

### 直播压缩转码
本项目支持将直播flv流压缩并转码hls，以适配移动端弱网络场景。示例代码如下：
```php
<?php
require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '2048M');
$pullUrl = 'rtmp://127.0.0.1:1935/a/b';
$config = [
    'width'        => 480,
    'height'       => 270,
    'bitrate'      => 0,
    'fps'          => 10,
    'qp'           => 30,
    'audioBitrate' => 64000,
    'motionWorkers'   => 1,
    'decodeWorkers'   => 12,
    'segmentDuration' => 3,
    'fastMotion' => true,
    'outputDir'  => __DIR__ . '/hls/live_rtmp/',
    'watermark'      => false,
    'watermarkFile'  => __DIR__."/watermark_80x16.yuv",
    'maxRetries'    => 5,
    'retryDelay'    => 3,
    'connectTimeout'=> 10,
    'idleTimeout'   => 30,
    'queueMaxBytes' => 83886080,
    'duration'   => 120,
    'tlsVerify'  => false,
];
(new \Xiaosongshu\Flv2mp4\Manage\Flv2HlsCompact($pullUrl, $config))->run();

```

- 直播压缩转码的实时性取决于服务器配置与输入/输出分辨率、帧率，实测在低分辨率低帧率场景可满足直播需求（参考下方测试条件），请结合自身硬件压测评估。具体性能与服务器性能密切相关，你可以根据自己的服务器配置调整以上参数。
- `motionWorkers`和`decodeWorkers`输入分辨率越高，越应增加 decodeWorkers；输出分辨率越高，越应增加 motionWorkers。两者之和不要超过服务器逻辑处理器数（运动估计进程是解码进程的子进程）。
- 测试使用 OBS 推流：输入 960×540、30fps、每 1 秒一个关键帧、Baseline Profile、H.264 + AAC；输出 480×270、15fps、HLS 切片 3 秒。连续运行 30 分钟无跳帧、无积压。
- ⚠️ 直播压缩转码仅支持 CLI 模式运行，请勿在 FPM 请求中直接调用，否则会阻塞 Web 服务的 worker 进程。

----

### 已支持的重编码特性

- [x] **I 帧解码与编码**（完全精确，INF dB）
- [x] **P 帧解码与编码**（Baseline Profile）
- [x] **帧内预测**：4x4（9种模式）+ 16x16（4种模式）
- [x] **帧间预测**：P 帧运动估计（菱形搜索优化）
- [x] **1/4 像素精度**：6-tap 滤波器插值
- [x] **CAVLC 熵编码**（Baseline Profile）
- [x] **分辨率缩放**（解码后 YUV 缩放 → 重新编码）
- [x] **码率控制**（通过 QP 参数调节）
- [x] **B 帧解码**（Main/High Profile，CABAC，含 Direct 模式；重编码时 B 帧转为 P 帧输出）
- [x] **CABAC 熵解码**（Main/High Profile 支持）
- [ ] **B 帧编码**（计划中，需双向预测）
---

### 水印生成工具
本项目提供php生成水印yuv功能，GD 扩展优先，无 GD 时自动降级为点阵字体。
- generateFromText()	生成文字水印 YUV，	GD 扩展优先，无 GD 时自动降级为点阵字体,**内置点阵字体仅支持 ASCII 字符（英文字母、数字、英文标点）**
- generateFromImage()	从图片生成水印 YUV，需要 GD 扩展，支持png/jpg

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';
use Xiaosongshu\Flv2mp4\Codec\WatermarkUtil;
WatermarkUtil::generateFromText('xiaosongshu',__DIR__ . '/test_wm_text.yuv',80,16,['fontSize' => 5,'fontColor' => [255, 255, 255],'bgColor' => [0, 0, 0],]);
WatermarkUtil::generateFromImage(__DIR__."/watermark_80x16.png",__DIR__ . '/test_wm_copy_80x16.yuv',80,16);
```
---

## 性能测试报告

### 一、测试环境

| 项目 | Windows 环境 | Linux (Docker) 环境  |
| :--- | :--- |:-------------------|
| **CPU** | Intel Core i5-14400（10 核 16 线程，6P+4E） | Intel Core i5-14400（10 核 16 线程，6P+4E） |
| **内存** | 31.7 GB | 16 GB              |
| **PHP 版本** | **8.1.3 (CLI)** | **8.1.24 (CLI)**   |
| **运行模式** | 纯 PHP CLI，GOP 分布式多进程 | 纯 PHP CLI，GOP 分布式多进程                |

> 注：Linux 环境为 Docker 容器，运行于同一台 i5-14400 物理机上。

---

### 二、测试文件信息

| 文件名 | 时长 | 分辨率 | 帧率 | 视频编码 | 音频编码 | 视频码率 | 音频码率 | 总码率 |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| `base3.flv` | 3.02 秒 | 960×540 | 30 fps | H.264 Constrained Baseline | AAC LC 48 kHz stereo | 1024 kb/s | 163 kb/s | 1152 kb/s |
| `base.flv` | 48.49 秒 | 960×540 | 30 fps | H.264 Constrained Baseline | AAC LC 48 kHz stereo | 1024 kb/s | 163 kb/s | 1176 kb/s |
| `fast.flv` | 410.94 秒 | 960×540 | 30 fps | H.264 Constrained Baseline | AAC LC 48 kHz stereo | 1024 kb/s | 163 kb/s | 1116 kb/s |

---

### 三、转码配置

| 参数 | 值                                |
| :--- |:---------------------------------|
| **输出分辨率** | 480×270                          |
| **输出帧率** | 15 fps                           |
| **视频码率** | 0（使用 QP 模式）                      |
| **QP** | 30                               |
| **音频码率** | 48 kb/s                          |
| **编码格式** | H.264 Constrained Baseline + AAC |
| **分布式模式** | GOP 分布式多进程                       |
| **decodeWorkers** | 12 个解码进程                         |
| **motionWorkers** | 1（每个解码 worker 内嵌 1 个运动估计子进程）     |
| **fastMotion** | true                             |
| **segmentDuration** | 3 秒（仅 HLS）                       |

---

### 四、测试结果

#### 4.1 Windows 平台（PHP 8.1.3）

| 文件 | 时长 | FLV | MP4 | HLS |
| :--- | :--- | :--- | :--- | :--- |
| `base3.flv` | 3.02 s | 8 s（约 2.65×） | 8 s（约 2.65×） | 8 s（约 2.65×） |
| `base.flv` | 48.49 s | 40 s（约 0.83×） | 40 s（约 0.83×） | 40 s（约 0.83×） |
| `fast.flv` | 410.94 s | 249 s（约 0.61×） | 256 s（约 0.62×） | 253 s（约 0.62×） |

> 耗时倍率 = 重编码耗时 ÷ 视频时长。

#### 4.2 Linux (Docker) 平台（PHP 8.1.24）

| 文件 | 时长 | FLV            | MP4            | HLS |
| :--- | :--- |:---------------|:---------------| :--- |
| `base3.flv` | 3.02 s | 3 s（约 0.99×）   | 3 s（约 0.99×）   | 3 s（约 0.99×） |
| `base.flv` | 48.49 s | 26 s（约 0.54×）  | 26 s（约 0.54×）  | 26 s（约 0.54×） |
| `fast.flv` | 410.94 s | 186 s（约 0.45×） | 189 s（约 0.46×） | 191 s（约 0.46×） |

> 耗时倍率 = 重编码耗时 ÷ 视频时长。

- 测试结果受视频复杂度，计算机硬件性能，计算机负载影响，请依实际测试结果为准。
- 当前测试报告使用baseline profile视频资源测试，而包含B帧的main/high profile视频资源稍慢。

---

## AAC-MP3-OPUS-WAV的编码解码

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

## 🔧 技术说明

- 纯 PHP 8.1+ 实现，无 FFmpeg 依赖
- 当前项目最初主要是为 [xiaosongshu/rtmp_server](https://github.com/2723659854/rtmp-server) 提供服务
- 建议使用 [PHPStan](https://phpstan.org/) Level 8 进行静态分析
- H.264 重编码采用分布式多进程架构，充分利用多CPU资源，若服务器是单核则建议关闭分布式。

## 开源协议 & 免责声明

- **开源协议**：本项目基于 [Apache License 2.0](http://www.apache.org/licenses/LICENSE-2.0) 开源，允许自由使用、修改、分发（含商业用途）。代码按“现状”（AS IS）提供，不提供任何明示或暗示的担保，作者不对因使用本软件而产生的任何损害承担责任。
- **专利风险提示**：本项目包含 H.264、AAC-LC、MP3 等受专利保护的音视频编解码器纯 PHP 实现。上述开源协议仅授予版权许可，**不包含任何专利授权**。
- **使用限制与责任转移**：上述编解码器实现仅供**学习、研究、测试和个人非商业使用**。若使用者将其用于任何**商业产品分发或商业运营**，请自行向相关专利授权方（如 Via Licensing、MPEG LA、Fraunhofer IIS 等）获取合法的专利授权，并自行承担全部专利侵权风险。本项目作者不承担由此产生的任何专利侵权责任。
- **最终解释权**：若您使用本项目，即视为您已阅读、理解并同意本免责声明的全部条款。

## 📧 联系方式

- 📬 邮箱：2723659854@qq.com
- 🐙 GitHub：[2723659854](https://github.com/2723659854)