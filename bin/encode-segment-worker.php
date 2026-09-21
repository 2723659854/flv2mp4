<?php

/**
 * @purpose FLV/MP4 段池并行-段编码 worker（Task 6）
 *
 * 参数：--autoload=<composer autoload> --port=<监听端口> --config=<base64 归一化转码配置>
 * 段编码 worker 只与协调进程（PipelineClient）通信：
 * segBegin/EVENT(帧YUV)/segEnd -> segDone(NAL)，END -> FINISHED。
 */

try {
    $options = getopt('', ['autoload:', 'port:', 'config:']);
    if (empty($options['autoload']) || empty($options['port']) || empty($options['config'])) {
        throw new RuntimeException('段编码 worker 参数不完整');
    }
    if (!is_file($options['autoload'])) {
        throw new RuntimeException("Composer autoload 文件不存在: {$options['autoload']}");
    }
    require $options['autoload'];
    $config = json_decode(base64_decode($options['config'], true), true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($config)) throw new RuntimeException('段编码 worker 配置无效');
    if (ini_set('memory_limit', '1024M') === false) throw new RuntimeException('无法设置段编码 worker 内存上限');
    // 段 worker 可能长时间空闲等待新段（段数 < worker 数/长尾），关闭阻塞 socket 60s 读超时
    ini_set('default_socket_timeout', '-1');
    (new \Xiaosongshu\Flv2mp4\Recode\SegmentEncodeWorkerServer($config))->run(
        'tcp://127.0.0.1:' . (int)$options['port']
    );
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
