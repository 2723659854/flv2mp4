<?php

try {
    $options = getopt('', ['mode:', 'autoload:', 'port:', 'output-port:', 'config:', 'output:', 'workers:']);
    $mode = $options['mode'] ?? '';
    if (empty($options['autoload']) || empty($options['port']) || empty($options['config'])) {
        throw new RuntimeException('FLV recode worker 参数不完整');
    }
    if (!is_file($options['autoload'])) {
        throw new RuntimeException("Composer autoload 文件不存在: {$options['autoload']}");
    }
    require $options['autoload'];
    $config = json_decode(base64_decode($options['config'], true), true, 32, JSON_THROW_ON_ERROR);
    if ($mode === 'decoder') {
        if (ini_set('memory_limit', '512M') === false) throw new RuntimeException('无法设置解码 worker 内存上限');
        $server = new \Xiaosongshu\Flv2mp4\Recode\FlvDecoderWorkerServer($config);
        if (empty($options['output-port'])) {
            // Task 6 段池模式：无 output-port 表示结果直接上行回协调进程
            $server->runUpstream('tcp://127.0.0.1:' . (int)$options['port']);
        } else {
            $server->run(
                'tcp://127.0.0.1:' . (int)$options['port'],
                'tcp://127.0.0.1:' . (int)$options['output-port']
            );
        }
    } elseif ($mode === 'output') {
        ini_set('memory_limit', '1024M');
        if (!isset($options['output'])) throw new RuntimeException('输出 worker 缺少 output');
        (new \Xiaosongshu\Flv2mp4\Recode\FlvOutputWorkerServer($config, $options['output']))->run(
            'tcp://127.0.0.1:' . (int)$options['port'],
            (int)($options['workers'] ?? 1)
        );
    } else throw new RuntimeException('未知 FLV recode worker 模式');
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
