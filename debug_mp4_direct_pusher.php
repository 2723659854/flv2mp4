<?php
require_once 'vendor/autoload.php';

use Xiaosongshu\Flv2mp4\Manage\Mp4DirectPusher;

$inputFile = 'test.mp4';
$pushUrl = 'http://127.0.0.1:8501/live/stream';

if (!file_exists($inputFile)) {
    echo "错误：测试文件 {$inputFile} 不存在！\n";
    exit(1);
}

echo "========================================\n";
echo "MP4 Direct Pusher 测试\n";
echo "========================================\n";
echo "输入文件：{$inputFile}\n";
echo "推流地址：{$pushUrl}\n";
echo "========================================\n\n";

try {
    $pusher = new Mp4DirectPusher($inputFile, $pushUrl, 1.0, true);
    $result = $pusher->run();
    
    if ($result) {
        echo "\n推流成功！\n";
    } else {
        echo "\n推流失败！\n";
    }
} catch (\Exception $e) {
    echo "\n异常：" . $e->getMessage() . "\n";
    echo "堆栈跟踪:\n" . $e->getTraceAsString() . "\n";
}
?>