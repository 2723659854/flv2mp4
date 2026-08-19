<?php

try {
    $options = getopt('', ['mode:', 'autoload:', 'port:', 'output-port:', 'output-ports:', 'profiles:', 'output:']);
    $mode = $options['mode'] ?? '';
    if (empty($options['autoload']) || empty($options['port']) || empty($options['profiles'])) {
        throw new RuntimeException('HLS worker 参数不完整');
    }
    if (!is_file($options['autoload'])) {
        throw new RuntimeException("Composer autoload 文件不存在: {$options['autoload']}");
    }
    require $options['autoload'];
    $profiles = json_decode(base64_decode($options['profiles'], true), true, 32, JSON_THROW_ON_ERROR);
    if ($mode === 'decoder') {
        if (ini_set('memory_limit', '512M') === false) throw new RuntimeException('无法设置解码 worker 内存上限');
        if (empty($options['output-port'])) throw new RuntimeException('解码 worker 缺少 output-port');
        (new \Xiaosongshu\Flv2mp4\Recode\HlsDecoderWorkerServer($profiles))->run(
            'tcp://127.0.0.1:' . (int)$options['port'],
            'tcp://127.0.0.1:' . (int)$options['output-port']
        );
    } elseif ($mode === 'scale') {
        if (!isset($options['output'], $options['output-ports'])) throw new RuntimeException('缩放 worker 参数不完整');
        $outputPorts = json_decode(base64_decode($options['output-ports'], true), true, 32, JSON_THROW_ON_ERROR);
        $outputAddresses = [];
        foreach ($outputPorts as $name => $port) $outputAddresses[$name] = 'tcp://127.0.0.1:' . (int)$port;
        (new \Xiaosongshu\Flv2mp4\Recode\HlsScaleWorkerServer($profiles, $options['output']))->run(
            'tcp://127.0.0.1:' . (int)$options['port'],
            $outputAddresses
        );
    } elseif ($mode === 'output') {
        if (!isset($options['output'])) throw new RuntimeException('输出 worker 缺少 output');
        (new \Xiaosongshu\Flv2mp4\Recode\HlsOutputWorkerServer($profiles, $options['output']))->run(
            'tcp://127.0.0.1:' . (int)$options['port']
        );
    } else throw new RuntimeException('未知 HLS worker 模式');
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
