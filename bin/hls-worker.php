<?php

// #region debug-point C:worker-shutdown
register_shutdown_function(function (): void { $error = error_get_last(); if ($error === null) return; $env = @parse_ini_file(dirname(__DIR__).'/.dbg/pipeline-worker-disconnect.env'); $url = $env['DEBUG_SERVER_URL'] ?? ''; $session = $env['DEBUG_SESSION_ID'] ?? ''; if ($url === '' || $session === '') return; $payload = json_encode(['sessionId' => $session, 'runId' => 'pre-fix', 'hypothesisId' => 'C', 'location' => __FILE__, 'msg' => '[DEBUG] worker-shutdown-error', 'data' => ['error' => $error, 'memory' => memory_get_usage(true)], 'ts' => (int)(microtime(true) * 1000)]); $context = stream_context_create(['http' => ['method' => 'POST', 'header' => 'Content-Type: application/json', 'content' => $payload, 'timeout' => 0.2]]); @file_get_contents($url, false, $context); });
// #endregion

try {
    $options = getopt('', ['mode:', 'autoload:', 'port:', 'output-port:', 'profiles:', 'output:']);
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
