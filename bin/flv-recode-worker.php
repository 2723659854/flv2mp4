<?php

$options = getopt('', ['mode:', 'autoload:', 'port:', 'output-port:', 'config:', 'output:']);
$mode = $options['mode'] ?? '';
if (empty($options['autoload']) || empty($options['port']) || empty($options['config'])) {
    fwrite(STDERR, "FLV recode worker 参数不完整\n");
    exit(2);
}
require $options['autoload'];

try {
    $config = json_decode(base64_decode($options['config'], true), true, 32, JSON_THROW_ON_ERROR);
    if ($mode === 'decoder') {
        if (ini_set('memory_limit', '512M') === false) throw new RuntimeException('无法设置解码 worker 内存上限');
        if (empty($options['output-port'])) throw new RuntimeException('解码 worker 缺少 output-port');
        (new \Xiaosongshu\Flv2mp4\Recode\FlvDecoderWorkerServer($config))->run(
            'tcp://127.0.0.1:' . (int)$options['port'],
            'tcp://127.0.0.1:' . (int)$options['output-port']
        );
    } elseif ($mode === 'output') {
        if (!isset($options['output'])) throw new RuntimeException('输出 worker 缺少 output');
        (new \Xiaosongshu\Flv2mp4\Recode\FlvOutputWorkerServer($config, $options['output']))->run(
            'tcp://127.0.0.1:' . (int)$options['port']
        );
    } else throw new RuntimeException('未知 FLV recode worker 模式');
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
