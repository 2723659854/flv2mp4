<?php

$options = getopt('', ['mode:', 'autoload:', 'port:', 'output-port:', 'profiles:', 'output:']);
$mode = $options['mode'] ?? '';
if (empty($options['autoload']) || empty($options['port']) || empty($options['profiles'])) {
    fwrite(STDERR, "HLS worker 参数不完整\n");
    exit(2);
}
require $options['autoload'];

// #region debug-point H5:fatal-shutdown
register_shutdown_function(static function () use ($mode): void {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        @file_get_contents('http://127.0.0.1:7777/event', false, stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => json_encode(['sessionId' => 'hls-decoder-disconnect', 'runId' => 'pre-fix-2', 'hypothesisId' => 'H5', 'location' => 'bin/hls-worker.php:shutdown', 'msg' => '[DEBUG] HLS worker fatal shutdown', 'data' => ['mode' => $mode, 'type' => $error['type'], 'message' => $error['message'], 'file' => $error['file'], 'line' => $error['line'], 'memoryUsage' => memory_get_usage(true), 'memoryPeak' => memory_get_peak_usage(true), 'memoryLimit' => ini_get('memory_limit')], 'ts' => (int)(microtime(true) * 1000)]), 'timeout' => 0.5, 'ignore_errors' => true]]));
    }
});
// #endregion

try {
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
