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
支持 FLV、FMP4、MP4、HLS 互转，直播流网关、推流、拉流、转播，以及 **H.264 解码 + 缩放 + 重新编码**（Baseline Profile）+ AAC/MP3/Opus/WAV 全格式互转。

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
    - [FLV → HLS 多码率示例](#flv-hls)
    - [FLV → FLV 重编码示例](#flv-flv)
    - [MP4 → MP4 重编码示例](#mp4-mp4)
    - [水印工具](#水印生成工具)
    - [性能测试报告](#性能测试报告)
- [AAC/MP3/OPUS/WAV的编码解码](#AAC-MP3-OPUS-WAV的编码解码)
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
| **H.264 重编码** | 解码 → 缩放 → 编码                     | 支持 Baseline Profile，为多码率 HLS 提供核心支持 |
| **OPUS→AAC**  | opus→pcm→aac                     | 支持 webrtc的音频opus转码为AAC-LC           |
| **AAC→MP3**   | aac→pcm→mp3                      | 支持 AAC-LC 的音频转码为MP3                 |
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
- ⚠️ **H.264 重编码模块属于 CPU 密集型计算，性能取决于服务器配置，不建议用于直播实时转码场景，同时建议开启 JIT 加速。**
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

支持 Baseline Profile 的 H.264 解码、缩放、重新编码，为以下场景提供核心能力：

通过 Composer 安装后无需手动运行 Opus/HLS/FLV/MP4 Worker；程序会使用当前 PHP CLI 和宿主 `vendor/autoload.php` 自动启动。多进程模式要求启用 `proc_open`，建议在 CLI 环境运行。

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
####  flv->hls
以下为flv转码多码率hls示例：
```php
<?php

require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '2048M');
$profiles = [
    // 不同码率分别配置
    '240p' => [
        'width' => 426,      // 或 424，保持 16:9 比例即可
        'height' => 240,
        'bitrate' => 300000, // 300 Kbps（视频码率）
        'fps' => 24,
        'audioBitrate' => 48000, // 48 Kbps
        'qp' => 30,          // 保持 30 以确保稳定性
        'watermark'=>true,     // 是否添加水印
        'watermark_file'=> __DIR__."/src/Static/watermark_80x16.yuv",// 水印文件
    ]
];
// 如果重编码质量要求高，那么开启多进程加速，低码率则不需要加速
$generator = new \Xiaosongshu\Flv2mp4\Recode\PurePhpHlsGenerator($profiles, __DIR__ . '/hls/output',true);
$generator->processFlv(__DIR__ . '/input.flv');
echo "索引地址: hls/output/master.m3u8\n";
echo "所有处理完成！\n";
```
#### flv->flv
将flv使用新的码率编码
```php
<?php
require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '2048M');

$config = [
    'width' => 320,        // 目标宽度，0 = 保持原分辨率
    'height' => 180,       // 目标高度，0 = 保持原分辨率
    'bitrate' => 150000,   // 目标码率（bps），0 = 使用 QP 模式
    'fps' => 15,           // 目标帧率
    'qp' => 30,            // QP 质量参数（码率为 0 时生效）
    'watermark'=>true,     // 是否添加水印
    'watermark_file'=> __DIR__."/src/Static/watermark_80x16.yuv",// 水印文件
];
// 如果重编码质量要求高，那么开启多进程加速，低码率则不需要加速
$recoder = new \Xiaosongshu\Flv2mp4\Recode\FlvRecoder($config,true);
$recoder->setMaxFrames(50);  // 可选：限制处理帧数
$recoder->processFlv(__DIR__ . '/input.flv', __DIR__.'/output.flv');
echo "flv重编码完成\r\n";
```
#### mp4->mp4
将mp4文件使用新的码率编码
```php
<?php
require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '2048M');

$config = [
    'width' => 320,        // 目标宽度，0 = 保持原分辨率
    'height' => 180,       // 目标高度，0 = 保持原分辨率
    'bitrate' => 150000,   // 目标码率（bps），0 = 使用 QP 模式
    'fps' => 15,           // 目标帧率
    'qp' => 30,            // QP 质量参数（码率为 0 时生效）
    'watermark'=>true,     // 是否添加水印
    'watermark_file'=> __DIR__."/src/Static/watermark_80x16.yuv",// 水印文件
];
// 如果重编码质量要求高，那么开启多进程加速，低码率则不需要加速
$recoder = new \Xiaosongshu\Flv2mp4\Recode\Mp4Recoder($config,true);
$recoder->setMaxFrames(50); // 可选：限制处理帧数
$recoder->processMp4(__DIR__ . '/input.mp4', __DIR__ . '/output.mp4');
echo "mp4重编码完成\r\n";
```
- 添加水印的时候，需要使用yuv格式文件，并且文件名称如上面的示例所示，必须包含水印文件的宽高（watermark_{width}x{height}.yuv）。工具会自动从文件名解析宽高（如 `watermark_80x16.yuv` → 宽 80，高 16），请确保文件名格式准确。
- 重编码模块提供了 **YUV 像素级操作接口**，你可以基于此实现自定义功能，如添加字幕、画中画、视频拼接等。
- h264详细使用方法见<a href="./src/Codec/README.md">README</a>。

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
- [ ] **B 帧支持**（计划中，需扩展至 Main Profile 并实现双向预测）
- [ ] **CABAC 熵编码**（计划中，Main Profile 支持）

> ⚠️ **性能说明**：当前 H.264 重编码模块由纯 PHP 实现，适用于**短时长视频（建议 ≤ 10 秒）**的离线处理或功能验证。对于长视频或高分辨率转码，建议使用 FFmpeg 等专业工具。
---

### 水印生成工具
本项目提供php生成水印yuv功能，GD 扩展优先，无 GD 时自动降级为点阵字体。
- generateFromText()	生成文字水印 YUV，	GD 扩展优先，无 GD 时自动降级为点阵字体,**内置点阵字体仅支持 ASCII 字符（英文字母、数字、英文标点）**
- generateFromImage()	从图片生成水印 YUV，需要 GD 扩展，支持png/jpg

#### 使用文字生成水印文件

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use Xiaosongshu\Flv2mp4\Codec\WatermarkUtil;

echo "=== 测试 WatermarkUtil ===\n\n";

// 测试1：生成文字水印
echo "1. 生成文字水印 (xiaosongshu, 80x16)...\n";
$outputFile1 = __DIR__ . '/test_wm_text.yuv';
$start = microtime(true);
$result = WatermarkUtil::generateFromText(
    'xiaosongshu',
    $outputFile1,
    80,
    16,

    [
        'fontSize' => 5, // 内置字体大小 1-5 `fontSize` 取值范围 1-5（数字越大字体越大），内置点阵字体仅支持 ASCII 字符。
        'fontColor' => [255, 255, 255],
        'bgColor' => [0, 0, 0],
    ]
);
$cost = round(microtime(true) - $start, 3);
if ($result && file_exists($outputFile1)) {
    $size = filesize($outputFile1);
    $expectedSize = 80 * 16 + (80 * 16 >> 1);
    echo "   成功! 文件大小: {$size} 字节 (期望: {$expectedSize}) - 耗时: {$cost}s\n";
    if ($size === $expectedSize) {
        echo "   ✅ 文件尺寸正确\n";
    } else {
        echo "   ❌ 文件尺寸不匹配\n";
    }
} else {
    echo "   ❌ 生成失败\n";
}
```
#### 使用图片生成水印文件

- 需要php安装gd扩展

```php
<?php

require_once __DIR__ . '/vendor/autoload.php';

use Xiaosongshu\Flv2mp4\Codec\WatermarkUtil;

echo "=== 测试 WatermarkUtil ===\n\n";

// 测试1：从图片生成水印
echo "1. 从图片生成水印 (xiaosongshu, 80x16)...\n";
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
    echo "   成功! 文件大小: {$size} 字节 (期望: {$expectedSize}) - 耗时: {$cost}s\n";
    if ($size === $expectedSize) {
        echo "   ✅ 文件尺寸正确\n";
    } else {
        echo "   ❌ 文件尺寸不匹配\n";
    }
} else {
    echo "   ❌ 生成失败\n";
}
```
---

## 性能测试报告

### 实验环境

| 项目 | Windows 环境 | Linux 环境 (Docker) |
| :--- | :--- | :--- |
| **操作系统** | Windows | Linux (Docker) |
| **CPU** | 16 核（物理核心） | 14 核（物理核心） |
| **内存** | 15.8 GB（可用） | 4 GB（可用） |
| **子进程配置** | 8 个 ME 子进程 | 8 个 ME 子进程 |
| **PHP 版本** | 8.4.3 (CLI) | 8.1.24 (CLI) |
| **测试素材** | `test.flv`，3.02 秒，720×742，30fps | 同左 |
| **输出规格** | `output.flv`，360×360，10fps | 同左 |
| **编码配置** | H.264 Constrained Baseline，AAC 128kbps | 同左 |


---

### 跨平台测试结果对比

| 输出格式 | Windows 耗时 | Linux (Docker) 耗时 | 提升幅度        |
| :--- |:-----------|:------------------|:------------|
| **FLV 重编码** | 18 秒       | **14 秒**          | **↓ 22.2%** |
| **MP4 重编码** | 18 秒       | **14 秒**          | **↓ 22.2%** |
| **HLS（mpegts + m3u8）** | 19 秒       | **15 秒**          | **↓ 21.1%** |

---

### 历史优化记录

| 优化阶段                           | FLV 重编码 | MP4 重编码   | HLS 全流程   | 备注                                        |
|:-------------------------------|:--------|:----------|:----------|:------------------------------------------|
| **初始版本**                       | ~91 秒   | ~60 秒（旧版） | **135 秒** | 串行，无优化                                    |
| **算法级优化**                      | 60 秒    | —         | 97 秒      | DCT 蝶形展开、字符串切片、减少 array_fill、量化+Zigzag 合并 |
| **多进程运动估计（4 进程）**              | 51 秒    | —         | 73 秒      | 首次引入分布式并行                                 |
| **HLS 封装 I/O 优化**              | —       | —         | 69 秒      | 批量写入、减少文件操作                               |
| **编码核心优化**                     | 44 秒    | 44 秒      | 67 秒      | 全零块跳过、I/P 帧 QP 策略                         |
| **解码缓存优化**                     | 41 秒    | 42 秒      | 64 秒      | 复用重复计算                                    |
| **开启 OPcache + JIT**           | 39 秒    | 39 秒      | 60 秒      | 运行时环境加速                                   |
| **多进程模型优化（select 等）**          | 33 秒    | —         | —         | 事件驱动、进程通信优化                               |
| **继续微调**                       | 32 秒    | —         | —         | 未明确具体手段                                   |
| **极限优化（Windows）**              | 28 秒    | 29 秒      | 37 秒      | Windows + PHP 8.4.3 + JIT                 |
| **Linux Docker 部署**            | 23 秒    | 24 秒      | 31 秒      | Linux + PHP 8.1.24，未开启 OPcache            |
| **GOP 分布式多进程解码**               | 22 秒    | 22 秒      | 22 秒      | 未开启 OPcache；Windows平台                     |
| **GOP 分布式多进程解码**               | 17 秒    | 17 秒      | 17 秒      | 未开启 OPcache；Linux平台                       |
| **去除参考帧重复 SHA256 + 静止块 ME 早退** | **19 秒** | **19 秒** | **20 秒** | Windows平台                               | 
| **去除参考帧重复 SHA256 + 静止块 ME 早退** | **16 秒** | **16 秒** | **17 秒** | Linux平台                               | 
| **零区域跳过 + 解码/滤波深度优化（Windows）** | **18 秒** | **18 秒** | **19 秒** | 6 抽头滑动递推、Bs 全零跳过、滤波结果未变跳过写、CBP=0 整块跳过、DPB 懒加载、`chr()` 查表化、按需 unpack + 缓存 |
| **零区域跳过 + 解码/滤波深度优化（Linux）** | **14 秒** | **14 秒** | **15 秒** | 同上，当前最新成绩 |
**说明：**
- 测试素材：`test.flv`，3.02 秒，720×742，30fps；输出规格：360×360，10fps。
- 编码配置：H.264 Constrained Baseline，AAC 128kbps。
- 多轮测试中取最佳稳定值。
- “—”表示该阶段未单独测试该格式。
- GOP 分布式多进程解码为最新优化，Windows 和 Linux 下均取得显著提升。
- 基于 GOP 的多进程并行处理，将长视频的串行编码计算拆分为多个独立任务并行执行，从而摊薄长视频逐帧串行处理带来的累计耗时。测试另外一个时长425秒的视频，重编码耗时501秒。


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