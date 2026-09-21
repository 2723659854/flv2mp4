<?php

try {
    $options = getopt('', ['mode:', 'autoload:', 'port:', 'output-port:', 'output-ports:', 'profiles:', 'output:', 'workers:']);
    $mode = $options['mode'] ?? '';
    if (empty($options['autoload']) || empty($options['port']) || empty($options['profiles'])) {
        throw new RuntimeException('HLS worker 参数不完整');
    }
    if (!is_file($options['autoload'])) {
        throw new RuntimeException("Composer autoload 文件不存在: {$options['autoload']}");
    }
    require $options['autoload'];
    $profiles = json_decode(base64_decode($options['profiles'], true), true, 32, JSON_THROW_ON_ERROR);
    $workers = (int)($options['workers'] ?? 1);
    if ($mode === 'decoder') {
        if (ini_set('memory_limit', '512M') === false) throw new RuntimeException('无法设置解码 worker 内存上限');
        if (empty($options['output-port'])) throw new RuntimeException('解码 worker 缺少 output-port');
        (new \Xiaosongshu\Flv2mp4\Recode\HlsDecoderWorkerServer($profiles))->run(
            'tcp://127.0.0.1:' . (int)$options['port'],
            'tcp://127.0.0.1:' . (int)$options['output-port']
        );
    } elseif ($mode === 'scale') {
        if (ini_set('memory_limit', '512M') === false) throw new RuntimeException('无法设置缩放 worker 内存上限');
        if (!isset($options['output'], $options['output-ports'])) throw new RuntimeException('缩放 worker 参数不完整');
        $outputPorts = json_decode(base64_decode($options['output-ports'], true), true, 32, JSON_THROW_ON_ERROR);
        $outputAddresses = [];
        foreach ($outputPorts as $name => $port) $outputAddresses[$name] = 'tcp://127.0.0.1:' . (int)$port;
        (new \Xiaosongshu\Flv2mp4\Recode\HlsScaleWorkerServer($profiles, $options['output']))->run(
            'tcp://127.0.0.1:' . (int)$options['port'],
            $outputAddresses,
            $workers
        );
    } elseif ($mode === 'output') {
        if (ini_set('memory_limit', '512M') === false) throw new RuntimeException('无法设置输出 worker 内存上限');
        if (!isset($options['output'])) throw new RuntimeException('输出 worker 缺少 output');
        (new \Xiaosongshu\Flv2mp4\Recode\HlsOutputWorkerServer($profiles, $options['output']))->run(
            'tcp://127.0.0.1:' . (int)$options['port'],
            $workers
        );
    } elseif ($mode === 'segment') {
        if (ini_set('memory_limit', '512M') === false) throw new RuntimeException('无法设置片 worker 内存上限');
        // 片任务链式派发时空闲可能超过默认 60s（Task7 检查点链接），禁掉 socket 空闲超时
        ini_set('default_socket_timeout', '-1');
        if (!isset($options['output'])) throw new RuntimeException('片 worker 缺少 output');
        (new \Xiaosongshu\Flv2mp4\Recode\HlsSegmentWorkerServer($profiles, $options['output']))->run(
            'tcp://127.0.0.1:' . (int)$options['port']
        );
    } else throw new RuntimeException('未知 HLS worker 模式');
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
