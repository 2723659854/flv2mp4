# H.264 编解码器 (纯 PHP 实现)

纯 PHP 实现的 H.264 Baseline Profile 编解码器，支持视频解码、缩放和重新编码，无需依赖 FFmpeg 扩展。

## 目录结构

```
src/Codec/
├── H264Decoder.php      # H.264 解码器主类
├── H264Encoder.php      # H.264 编码器主类
├── NalUtil.php          # NALU 工具类（拆分、防竞争字节处理）
├── README.md            # 本文档
├── Examples/            # 示例代码
│   └── demo.php         # 完整的解码+缩放+编码 Demo
├── Decode/              # 解码器各模块 (Trait)
│   ├── SpsPpsParsingTrait.php    # SPS/PPS 解析
│   ├── SliceDecodingTrait.php    # Slice 层解码
│   ├── MacroblockDecodingTrait.php  # 宏块解码
│   ├── IntraPredictionTrait.php   # 帧内预测
│   ├── MotionVectorPredictionTrait.php  # 运动向量预测
│   ├── MotionCompensationTrait.php    # 运动补偿
│   ├── ResidualDecodingTrait.php  # 残差解码 (CAVLC)
│   ├── TransformTrait.php         # 反变换/反量化 (IDCT)
│   ├── DeblockingFilterTrait.php  # 去块滤波
│   └── BitReader.php              # 比特读取器
├── Encode/              # 编码器各模块 (Trait)
│   ├── TablesTrait.php           # 常量/VLC表
│   ├── BitstreamTrait.php        # 比特流写入
│   ├── TransformTrait.php        # 变换/量化 (DCT)
│   ├── CavlcTrait.php            # CAVLC 编码
│   ├── IntraPredTrait.php        # 帧内预测
│   ├── MotionTrait.php           # 运动估计
│   ├── InterPredTrait.php        # 帧间预测
│   ├── SpsPpsTrait.php           # SPS/PPS 生成
│   └── SliceEncodeTrait.php      # Slice 编码
└── Scaler/
    └── VideoScaler.php    # YUV420P 视频缩放器
```

## 核心类说明

### 1. H264Decoder - H.264 解码器

**功能**：将 H.264 AnnexB 格式的 NALU 数据解码为 YUV420P 原始像素数据。

**支持的 Profile**：Baseline / Extended 

**使用示例**：

```php
use Xiaosongshu\Flv2mp4\Codec\H264Decoder;
use Xiaosongshu\Flv2mp4\Codec\NalUtil;

// 1. 读取 H.264 裸流 (AnnexB 格式)
$h264Data = file_get_contents('input.h264');

// 2. 拆分为 NALU 单元
$nalUnits = NalUtil::splitNalUnits($h264Data);
echo "NAL单元数: " . count($nalUnits) . "\n";

// 3. 解码
$decoder = new H264Decoder();
$result = $decoder->decode($nalUnits);

if ($result) {
    $width = $result['width'];
    $height = $result['height'];
    $yuvData = $result['data'];   // YUV420P 格式，所有帧拼接
    $pixFmt = $result['pix_fmt'];  // 'yuv420p'
    
    // 计算帧数
    $frameSize = $width * $height + (int)(($width * $height) / 2);
    $frameCount = (int)(strlen($yuvData) / $frameSize);
    
    echo "解码完成: {$width}x{$height}, {$frameCount} 帧\n";
    file_put_contents('output.yuv', $yuvData);
}
```

**输入格式**：
- `$nalUnits` 数组格式：
  ```php
  [
      [
          'type' => 7,          // NALU 类型 (7=SPS, 8=PPS, 5=IDR, 1=P帧)
          'data' => '...',      // RBSP 数据（去除防竞争字节后）
          'raw'  => '...',      // 原始 NALU 数据（含防竞争字节）
      ],
      // ...
  ]
  ```

**输出格式**：
- 成功返回数组：`['width' => int, 'height' => int, 'data' => string, 'pix_fmt' => 'yuv420p']`
- 失败返回 `null`
- `data` 是 YUV420P 格式的原始像素数据，所有帧顺序拼接

### 2. H264Encoder - H.264 编码器

**功能**：将 YUV420P 原始像素数据编码为 H.264 Baseline Profile NALU。

**支持的 Profile**：Baseline Profile（I帧 + P帧）

**使用示例**：

```php
use Xiaosongshu\Flv2mp4\Codec\H264Encoder;

// 1. 创建编码器实例
$encoder = new H264Encoder();

// 2. 设置编码参数
$encoder->setResolution(640, 360);   // 分辨率
$encoder->setFps(24);                 // 帧率
$encoder->setBitrate(600000);         // 目标码率 (bps)
$encoder->setQp(30);                  // 量化参数 (0-51，越小质量越高)

// 3. 逐帧编码
$yuvFile = fopen('input.yuv', 'rb');
$frameSize = 640 * 360 + (int)((640 * 360) / 2);
$outputNals = [];
$frameIndex = 0;

while (!feof($yuvFile)) {
    $yuvData = fread($yuvFile, $frameSize);
    if (strlen($yuvData) < $frameSize) break;
    
    $isKeyFrame = ($frameIndex % 30 === 0);  // 每30帧一个关键帧
    $nalUnits = $encoder->encodeFrame($yuvData, $isKeyFrame);
    
    foreach ($nalUnits as $nal) {
        // AnnexB 格式: 00 00 00 01 + NALU数据
        $outputNals[] = "\x00\x00\x00\x01" . $nal;
    }
    $frameIndex++;
}
fclose($yuvFile);

file_put_contents('output.h264', implode('', $outputNals));
echo "编码完成: {$frameIndex} 帧\n";
```

**方法说明**：

| 方法 | 参数 | 说明 |
|------|------|------|
| `setResolution(int $width, int $height)` | 宽、高 | 设置输出分辨率 |
| `setFps(int $fps)` | 帧率 | 设置帧率 |
| `setBitrate(int $bitrate)` | 码率(bps) | 设置目标码率 |
| `setQp(int $qp)` | QP值(0-51) | 设置量化参数 |
| `encodeFrame(string $yuvData, bool $isKeyFrame)` | YUV数据、是否关键帧 | 编码单帧，返回 NALU 数组 |

### 3. VideoScaler - 视频缩放器

**功能**：对 YUV420P 格式的视频帧进行尺寸缩放。

**算法**：双线性插值 (bilinear) / 双立方插值 (bicubic)

**使用示例**：

```php
use Xiaosongshu\Flv2mp4\Codec\Scaler\VideoScaler;

$scaler = new VideoScaler();

$srcW = 1280;
$srcH = 720;
$dstW = 640;
$dstH = 360;

$srcYuv = file_get_contents('input_720p.yuv');
$dstYuv = $scaler->scaleYUV420P($srcYuv, $srcW, $srcH, $dstW, $dstH);

file_put_contents('output_360p.yuv', $dstYuv);
echo "缩放完成: {$srcW}x{$srcH} -> {$dstW}x{$dstH}\n";
```

### 4. NalUtil - NALU 工具类

**功能**：NALU 拆分、防竞争字节处理等工具函数。

**使用示例**：

```php
use Xiaosongshu\Flv2mp4\Codec\NalUtil;

// 拆分 AnnexB 格式的 H.264 裸流为 NALU 数组
$h264Data = file_get_contents('input.h264');
$nalUnits = NalUtil::splitNalUnits($h264Data);

foreach ($nalUnits as $nal) {
    echo "NALU 类型: {$nal['type']}, 大小: " . strlen($nal['raw']) . " bytes\n";
}

// 去除防竞争字节 (Emulation Prevention Bytes)
$rbsp = NalUtil::removeEmulationPrevention($nal['raw']);
```

## 完整流程：FLV → HLS 多码率转码

参考项目根目录的 [encode.php](/examples/encode.php) 和 `PurePhpHlsGenerator` 类。

```
FLV 文件
   ↓
  解析 FLV Tag
   ↓
  提取 AVC NALU
   ↓
┌───────────────────────────┐
│  H264Decoder::decode()    │  ← 解码为 YUV420P
└───────────────────────────┘
   ↓
┌───────────────────────────┐
│ VideoScaler::scaleYUV420P()│ ← 缩放到目标分辨率
└───────────────────────────┘
   ↓
┌───────────────────────────┐
│  H264Encoder::encodeFrame()│ ← 重新编码为 H.264
└───────────────────────────┘
   ↓
  打包为 MPEG-TS 切片
   ↓
  生成 HLS 播放列表 (.m3u8)
```

**代码示例**：

```php
use Xiaosongshu\Flv2mp4\Codec\H264Decoder;
use Xiaosongshu\Flv2mp4\Codec\H264Encoder;
use Xiaosongshu\Flv2mp4\Codec\Scaler\VideoScaler;
use Xiaosongshu\Flv2mp4\Codec\NalUtil;

// 初始化
$decoder = new H264Decoder();
$encoder = new H264Encoder();
$scaler = new VideoScaler();

// 设置编码参数
$encoder->setResolution(426, 240);
$encoder->setFps(24);
$encoder->setQp(30);

// 读取源 H.264 数据
$h264Data = file_get_contents('input.h264');
$nalUnits = NalUtil::splitNalUnits($h264Data);

// 解码
$decoded = $decoder->decode($nalUnits);
if (!$decoded) die("解码失败");

$srcW = $decoded['width'];
$srcH = $decoded['height'];
$dstW = 426;
$dstH = 240;

$srcFrameSize = $srcW * $srcH + (int)(($srcW * $srcH) / 2);
$dstFrameSize = $dstW * $dstH + (int)(($dstW * $dstH) / 2);
$frameCount = (int)(strlen($decoded['data']) / $srcFrameSize);

$outputNals = [];
for ($i = 0; $i < $frameCount; $i++) {
    // 取出一帧
    $srcFrame = substr($decoded['data'], $i * $srcFrameSize, $srcFrameSize);
    
    // 缩放
    $scaledFrame = $scaler->scaleYUV420P($srcFrame, $srcW, $srcH, $dstW, $dstH);
    
    // 编码
    $isKeyFrame = ($i % 30 === 0);
    $nals = $encoder->encodeFrame($scaledFrame, $isKeyFrame);
    
    foreach ($nals as $nal) {
        $outputNals[] = "\x00\x00\x00\x01" . $nal;
    }
}

file_put_contents('output_240p.h264', implode('', $outputNals));
echo "转码完成: {$frameCount} 帧\n";
```

## 快速开始

运行完整的解码 + 缩放 + 编码 Demo：

```bash
# 确保项目根目录有 test.flv 文件
php src/Codec/Examples/demo.php
```

Demo 会自动完成以下步骤：
1. 从 `test.flv` 提取 H.264 裸流 → `input.h264`
2. 解码为 YUV420P 原始像素
3. 缩放到 426x240
4. 重新编码为 H.264 → `output.h264`
5. 验证编码结果（重新解码并计算 PSNR）

生成的文件都在 `src/Codec/Examples/` 目录下。

## 注意事项

1. **Profile 支持**：目前仅完整支持 Baseline Profile。
2. **多参考帧**：解码器支持多参考帧（`num_ref_idx_l0_active_minus1`），但参考帧数量过多时性能会下降。
3. **内存使用**：每帧 YUV 数据约为 `宽 × 高 × 1.5` 字节，处理高分辨率视频时请注意内存限制。
4. **性能**：纯 PHP 实现的编解码性能低于原生 C 实现（如 FFmpeg），适合离线转码或低分辨率实时场景。
5. **⚠️ 高风险修改警告**：H.264 编解码逻辑涉及码流层级的位操作（Bitstream Manipulation），
   任何对熵编码（CAVLC）、变换（DCT/IDCT）、预测（Intra/Inter）模块的修改，
   都可能因单比特错误导致全部码流无法解码。
   **非充分理解 H.264 标准者，请勿修改本目录下的核心编解码文件。**



