# FLV ↔ MP4 / HLS 转换工具 + H264 重编码 + OPUS2AAC
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
支持 FLV、FMP4、MP4、HLS 互转，直播流网关、推流、拉流、转播，以及 **H.264 解码 + 缩放 + 重新编码**（Baseline Profile）+ OPUS→AAC。

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
| **OPUS→AAC**  | opus→pcm→aac                   | 支持 webrtc的音频opus转码为AAC-LC           |

---

## 环境依赖

| 依赖项          | 说明                     |
|--------------|------------------------|
| PHP          | ≥ 8.1（**仅 CLI 命令行模式**） |
| `sockets` 扩展 | **必需**，提供底层 Socket 通信  |
| `gd` 扩展      | **可选**，用于从 PNG/JPG 图片生成水印。如果未安装，将自动降级为内置点阵字体模式。       |

- 💡 **仅支持 PHP CLI 命令行运行，不支持 Nginx/FPM 网页模式调用。**
- 💡 **无需 FFmpeg，无需任何第三方二进制程序，全部纯 PHP 实现。**
- 💡 **容器封装转换（FLV/MP4/HLS 互转，仅修改封装格式，速度快）。**
- ⚠️**重编码h264 /opus 转码功能要求`proc_open`函数不被禁用。**
- ⚠️**H.264 重编码模块（纯 PHP 解码编码，CPU 开销大，适合短时长离线视频，不适合直播实时转码），同时建议开启JIT加速。**

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

| 项目 | 详情 |
| :--- | :--- |
| **CPU** | 16 核（物理核心） |
| **内存** | 15.8 GB（可用） |
| **子进程配置** | 8 个 ME 子进程（经实测为最优值，增加或减少均导致耗时上升） |
| **PHP 版本** | 8.4.3 (CLI，开启 JIT) |
| **OPcache** | `opcache.enable_cli=on`，`opcache.jit=on`，`opcache.jit_buffer_size=100M` |
| **测试素材** | `test.flv`，3.02 秒，720×742，30fps |
| **输出规格** | `output.flv`，360×360，10fps |
| **编码配置** | H.264 Constrained Baseline，AAC 128kbps |


### 测试结果

| 输出格式                   | 最佳耗时 | 典型耗时范围 |
|:-----------------------| :--- | :--- |
| **FLV 重编码**            | **28 秒** | 28~29 秒 |
| **MP4 重编码**            | **29 秒** | 29~30 秒 |
| **HLS（mpegts + m3u8）** | **37 秒** | 37~38 秒 |

*多轮测试中 FLV 28 秒出现 3 次，29 秒出现 3 次，取最佳值 28 秒作为性能基准。*


### 性能耗时分析

#### 1. 处理规模

**解码端（原视频）**
- 分辨率：720 × 742 → 宏块对齐后为 720 × 752（47 × 47 个 16×16 宏块）
- 每帧宏块数：47 × 47 = **2,209 个宏块**
- 总帧数：90 帧
- **总解码宏块数：2,209 × 90 = 198,810 个宏块**

**编码端（输出视频）**
- 分辨率：360 × 360 → 宏块对齐后为 368 × 368（23 × 23 个 16×16 宏块）
- 每帧宏块数：23 × 23 = **529 个宏块**
- 总帧数：30 帧
- **总编码宏块数：529 × 30 = 15,870 个宏块**

**总计宏块处理量：** 198,810 + 15,870 ≈ **21.5 万个宏块**

#### 2. 运动估计（编码端核心热点）

每个宏块即使在快速搜索模式下，仍需检查约 150 个候选运动矢量。每个候选位置执行一次 SAD 计算：16×16 = **256 次像素差值运算**。

- 编码端运动估计运算量：15,870 × 150 × 256 ≈ **6.1 亿次整数运算**
- 加上解码运动补偿、DCT、量化、熵编码等：总运算量超过 **10 亿次**
- **折合每秒处理约 3.5 亿次原始像素操作**


### 架构概览

当前系统采用 **流水线 + 分布式计算** 架构：

```
FLV 文件
   │
   ▼
┌──────────────────┐
│  主进程           │
│  (FLV Tag 拆包)   │
└────────┬─────────┘
         │
         ▼
┌──────────────────┐      ┌──────────────────┐
│  解码+缩放进程    │ ──▶  │  编码主进程       │
│  (H.264 → YUV)   │      │  (YUV → H.264)   │
└──────────────────┘      └────────┬─────────┘
                                    │
            ┌───────────┬───────────┼───────────┬───────────┐
            ▼           ▼           ▼           ▼           ▼
       ┌───────────┐  ┌───────────┐  ┌───────────┐  ┌───────────┐  ┌───────────┐
       │ ME 子进程 │  │ ME 子进程 │  │ ME 子进程 │  │ ME 子进程 │  │ ME 子进程 │
       │ (运动估计)│  │ (运动估计)│  │ (运动估计)│  │ (运动估计)│  │ (运动估计)│
       └───────────┘  └───────────┘  └───────────┘  └───────────┘  └───────────┘
```

*注：编码进程下挂载 **8 个运动估计（ME）子进程**。经测试，16 核环境下 8 个子进程为最优配置，增加子进程因上下文切换开销导致性能下降，减少则无法充分利用 CPU 资源。*


### 性能定位与使用建议

| 场景 | 是否适用 | 说明 |
| :--- | :--- | :--- |
| **直播实时转码** | ❌ 不建议 | 3 秒视频耗时 28 秒，延迟倍率约 9 倍，无法满足直播低延迟要求 |
| **点播离线转码** | ✅ 推荐 | 可作为后台异步任务（如 Redis 队列 + Worker），适合用户上传后后台处理 |
| **受限环境（无 FFmpeg）** | ✅ 推荐 | 纯 PHP 实现，零外部依赖，可在特殊容器或嵌入式环境中部署 |
| **突发高并发场景** | ⚠️ 需控制 | 建议限制并发任务数，避免 CPU 资源争抢 |


### 生产部署建议

#### 1. 必须启用 JIT

实测 PHP 8.4.3（开启 JIT）比 PHP 8.2.9 快约 1 秒，环境差异显著。

```ini
; php.ini
opcache.enable_cli=1
opcache.jit=on
opcache.jit_buffer_size=100M
```

#### 2. 推荐 PHP 版本

- **PHP 8.4.x**（实测最佳）
- PHP 8.2.x 也可运行，但性能略低（约 29 秒）

#### 3. 子进程调优

```bash
# 16 核环境下，8 个子进程为最优配置
php recode.php --workers=8
```

#### 4. CPU 资源隔离（可选）

```bash
# 仅使用 CPU 0-7 执行转码任务（对应 8 个子进程）
taskset -c 0-7 php recode.php
```

#### 5. 任务队列化

推荐将转码任务投递至 Redis 或 Beanstalkd 队列，由 Worker 进程消费，避免阻塞主请求链路。


### 结论

纯 PHP 实现的 H.264 重编码系统，在 16 核测试环境下（3 秒 FLV，720→360 缩放，30→10fps）稳定达成：

- **FLV 重编码：28 秒**
- **MP4 重编码：29 秒**
- **HLS 全流程：37 秒**

总运算量超过 **10 亿次整数操作**，每秒处理约 **3.5 亿次像素级运算**。该系统适用于离线异步转码场景，不适用于直播实时转码。


### 数据来源

本报告所有数据均基于 `xiaosongshu/flv2mp4` 项目的实测结果。



## 🔧 技术说明

- 纯 PHP 8.1+ 实现，无 FFmpeg 依赖
- 当前项目最初主要是为 [xiaosongshu/rtmp_server](https://github.com/2723659854/rtmp-server) 提供服务
- 建议使用 [PHPStan](https://phpstan.org/) Level 8 进行静态分析
- H.264 重编码采用分布式多进程架构，充分利用多CPU资源，若服务器是单核则建议关闭分布式。

## 开源协议 & 免责声明

本项目基于 [Apache License 2.0](http://www.apache.org/licenses/LICENSE-2.0) 开源，可自由使用、修改、分发（含商业用途）。  
代码按“现状”（AS IS）提供，不提供任何明示或暗示的担保。作者不对因使用本软件而产生的任何损害承担责任。

---

## 📧 联系方式

- 📬 邮箱：2723659854@qq.com
- 🐙 GitHub：[2723659854](https://github.com/2723659854)