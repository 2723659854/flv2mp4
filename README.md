## FLV to MP4 Converter

### Introduction

A pure PHP tool for converting FLV media files to MP4 format, facilitating storage and further processing.

### Installation

```bash
composer require xiaosongshu/flv2mp4
```

### Example

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '512M');

// Path to the FLV file to convert
$file = __DIR__ . "/test.flv";

// Directory where the converted MP4 file will be saved
$outputDir = __DIR__ . "/output";

try {
    // Perform conversion, returns the MP4 file path on success
    $res = \Xiaosongshu\Flv2mp4\Client::run($file, $outputDir);
    echo $res;
} catch (\Exception $e) {
    echo $e->getMessage();
}
```

### Background

This project was originally developed to provide MP4 storage support for another live streaming project: [xiaosongshu/rtmp_server](https://github.com/2723659854/rtmp-server).

### Disclaimer

- Some code or materials in this project may originate from the internet. If any copyright infringement occurs, please contact the author for removal.
- This project is completely open source and intended solely for technical sharing and learning.
- The author is not responsible for any legal risks or commercial disputes arising from the use of this project by third parties.
- Users assume all consequences resulting from the use of this project, including but not limited to copyright and compliance issues.

### Contact

- Email: 2723659854@qq.com