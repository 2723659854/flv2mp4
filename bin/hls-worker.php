<?php
ini_set('memory_limit', '2048M');
// 转码worker全程只处理无循环引用的临时数据（平面数组/字符串/帧上下文），
// 循环引用GC的根缓冲簿记在百万级小数组分配下有实测开销（解码约7%），根缓冲长期不触发；
// 数据树随帧即时refcount释放，关闭GC不产生泄漏。
gc_disable();
if (function_exists('pcntl_signal')) pcntl_signal(SIGINT, SIG_IGN);
try {
    $options = getopt('', ['mode:', 'autoload:', 'port:', 'control-port:', 'output-port:', 'config:', 'output:', 'workers:', 'preencoded']);
    $mode = $options['mode'] ?? '';
    if (empty($options['autoload']) || empty($options['port']) || empty($options['config'])) {
        throw new RuntimeException('HLS worker 参数不完整');
    }
    if (!is_file($options['autoload'])) {
        throw new RuntimeException("Composer autoload 文件不存在: {$options['autoload']}");
    }
    require $options['autoload'];
    $config = json_decode(base64_decode($options['config'], true), true, 32, JSON_THROW_ON_ERROR);
    $workers = (int)($options['workers'] ?? 1);
    if ($mode === 'decoder') {
        if (ini_set('memory_limit', '2048M') === false) throw new RuntimeException('无法设置解码 worker 内存上限');
        if (empty($options['output-port'])) throw new RuntimeException('解码 worker 缺少 output-port');
        (new \Xiaosongshu\Flv2mp4\Recode\HlsDecoderWorkerServer($config))->run(
            'tcp://127.0.0.1:' . (int)$options['port'],
            'tcp://127.0.0.1:' . (int)$options['output-port'],
            isset($options['control-port']) ? 'tcp://127.0.0.1:' . (int)$options['control-port'] : ''
        );
    } elseif ($mode === 'output') {
        if (ini_set('memory_limit', '512M') === false) throw new RuntimeException('无法设置输出 worker 内存上限');
        if (!isset($options['output'])) throw new RuntimeException('输出 worker 缺少 output');
        (new \Xiaosongshu\Flv2mp4\Recode\HlsOutputWorkerServer($config, $options['output']))->run(
            'tcp://127.0.0.1:' . (int)$options['port'],
            $workers,
            isset($options['control-port']) ? 'tcp://127.0.0.1:' . (int)$options['control-port'] : '',
            array_key_exists('preencoded', $options)
        );
    } else throw new RuntimeException('未知 HLS worker 模式');
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
