## FLV 转码为 MP4 工具

### 介绍

这是一款纯 PHP 开发的工具，用于将 FLV 媒体文件转换为 MP4 格式，便于存储和后续处理。

### 安装

```bash
composer require xiaosongshu/flv2mp4
```

### 示例

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '512M');

// 需要转换的 FLV 文件路径
$file = __DIR__ . "/test.flv";

// 转换后 MP4 文件保存目录
$outputDir = __DIR__ . "/output";

try {
    // 执行转换，成功返回 MP4 文件路径
    $res = \Xiaosongshu\Flv2mp4\Client::run($file, $outputDir);
    echo $res;
} catch (\Exception $e) {
    echo $e->getMessage();
}
```

### 说明

本项目的开发初衷是为另一个直播项目 [xiaosongshu/rtmp_server](https://github.com/2723659854/rtmp-server) 提供 MP4 存储支持。

### 免责声明

- 项目中的部分代码或资料可能来源于网络，如涉及侵权，请及时联系作者删除。
- 本项目完全开源，仅供技术分享与学习交流。
- 因使用者自身行为导致的任何法律风险或商业纠纷，均与作者无关。
- 使用者应自行承担使用本项目可能带来的后果，包括但不限于版权、合规等问题。

### 联系作者

- 邮箱：2723659854@qq.com
