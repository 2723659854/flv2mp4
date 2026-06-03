# FLV to MP4 / HLS Converter

<p align="center">
  <a href="./README.cn.md"><strong>🇨🇳 中文</strong></a> •
  <a href="./README.md"><strong>🇬🇧 English</strong></a>
</p>

## Introduction

A pure PHP tool for converting FLV media files into MP4 or HLS format, suitable for storage, distribution, and online playback.

## Installation

```bash
composer require xiaosongshu/flv2mp4
```

## Usage Examples

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '512M');

// Path to the FLV file to be converted
$file = __DIR__ . "/test.flv";

// Example 1: Merge into a single MP4 file (ideal for video-on-demand playback)
echo "=== Example 1: Merge into a single MP4 ===\n";
$outputDir1 = __DIR__ . "/output_merge";
try {
    $res = \Xiaosongshu\Flv2mp4\Client::run($file, $outputDir1);
    echo "\nConversion completed: " . $res . "\n\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n\n";
}

// Example 2: Generate separate audio and video segments (for live streaming)
echo "=== Example 2: Generate separate audio/video segments ===\n";
$outputDir2 = __DIR__ . "/output_separate";
try {
    $res = \Xiaosongshu\Flv2mp4\Client::runSeparate($file, $outputDir2);
    echo "\nConversion completed! Generated files:\n";
    echo "  Audio init segment: " . ($res['audioInit'] ?? 'None') . "\n";
    echo "  Video init segment: " . ($res['videoInit'] ?? 'None') . "\n";
    echo "  Audio segments count: " . count($res['audioSegments']) . "\n";
    echo "  Video segments count: " . count($res['videoSegments']) . "\n";
    echo "  Metadata file: " . ($res['meta'] ?? 'None') . "\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

// Example 3: Convert to HLS segments (supports both live and VOD)
echo "\n=== Example 3: Convert to HLS ===\n";
$outputDir3 = __DIR__ . "/hls";
try {
    $res = \Xiaosongshu\Flv2mp4\Client::flv2Hls($file, $outputDir3);
    echo "\nHLS conversion completed, index file: {$res['index']}, output directory: {$res['outputDir']}\n\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
```

- The generated standard MP4 file can be played directly using the HTML5 `<video>` tag; see `index.html` for a playback example.
- The generated fMP4 segments are suitable for live streaming; refer to `play_merge.html` for a playback example.
- The generated HLS segments support both live and video-on-demand playback; an example is provided in `play.html`.

## Background

This tool was originally developed to provide MP4/HLS storage support for the [xiaosongshu/rtmp_server](https://github.com/2723659854/rtmp-server) live streaming project.

## Disclaimer

- Some code or materials in this project may originate from the internet. If any infringement occurs, please contact the author for removal.
- This project is fully open-source and intended solely for technical sharing and learning.
- The author assumes no responsibility for any legal risks, commercial disputes, or copyright issues arising from the user's actions.
- Users should comply with local laws and regulations and use this tool responsibly.

## Contact

- Email: 2723659854@qq.com