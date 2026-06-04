<?php
require_once __DIR__ . '/vendor/autoload.php';
ini_set('memory_limit', '512M');

echo "=== MP4转FLV转换测试 ===\n\n";

// 测试文件路径
$mp4File = __DIR__ . "/test.mp4";
$flvFile = __DIR__ . "/output_mp4_to_flv.flv";

// 检查测试文件是否存在
if (!file_exists($mp4File)) {
    echo "错误: 测试文件不存在: {$mp4File}\n";
    echo "请将一个MP4文件重命名为test.mp4放在项目根目录下\n";
    exit(1);
}

echo "输入文件: {$mp4File}\n";
echo "输出文件: {$flvFile}\n\n";

try {
    // 调用mp4ToFlv方法进行转换
    echo "开始转换...\n";
    
    // 创建转换器实例
    $converter = new \Xiaosongshu\Flv2mp4\manage\Mp4ToFlv($mp4File, $flvFile);
    
    // 添加调试信息
    $reflection = new \ReflectionClass($converter);
    $spsProperty = $reflection->getProperty('sps');
    $spsProperty->setAccessible(true);
    $ppsProperty = $reflection->getProperty('pps');
    $ppsProperty->setAccessible(true);
    $audioConfigProperty = $reflection->getProperty('audioSpecificConfig');
    $audioConfigProperty->setAccessible(true);
    $videoTrackProperty = $reflection->getProperty('videoTrack');
    $videoTrackProperty->setAccessible(true);
    $audioTrackProperty = $reflection->getProperty('audioTrack');
    $audioTrackProperty->setAccessible(true);
    $boxTreeProperty = $reflection->getProperty('boxTree');
    $boxTreeProperty->setAccessible(true);
    
    // 手动执行各个步骤并添加调试
    echo "解析 MP4 boxes...\n";
    $converter->run();
    
    echo "\n调试信息:\n";
    echo "视频轨道: " . ($videoTrackProperty->getValue($converter) ? '存在' : '不存在') . "\n";
    echo "音频轨道: " . ($audioTrackProperty->getValue($converter) ? '存在' : '不存在') . "\n";
    echo "SPS 长度: " . strlen($spsProperty->getValue($converter)) . " bytes\n";
    echo "PPS 长度: " . strlen($ppsProperty->getValue($converter)) . " bytes\n";
    echo "音频配置长度: " . strlen($audioConfigProperty->getValue($converter)) . " bytes\n";
    
    $boxTree = $boxTreeProperty->getValue($converter);
    echo "Box树深度: " . count($boxTree) . "\n";
    
    $result = $flvFile;
    
    echo "转换完成!\n";
    echo "输出文件: {$result}\n";
    
    // 检查输出文件
    if (file_exists($result)) {
        $fileSize = filesize($result);
        echo "文件大小: " . round($fileSize / 1024 / 1024, 2) . " MB\n";
        
        // 验证FLV文件头
        $handle = fopen($result, 'rb');
        $header = fread($handle, 13);
        fclose($handle);
        
        if (substr($header, 0, 3) === 'FLV') {
            echo "文件格式验证: FLV格式正确\n";
        } else {
            echo "警告: 文件头格式不正确\n";
        }
    } else {
        echo "错误: 输出文件未生成\n";
    }
    
} catch (\Exception $e) {
    echo "转换失败: " . $e->getMessage() . "\n";
    echo "堆栈跟踪:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

echo "\n=== 测试完成 ===\n";