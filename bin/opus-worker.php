<?php
ini_set('memory_limit', '2048M');
gc_disable(); // 常驻转码进程无循环引用临时数据，省去GC根缓冲簿记（与 audio-worker.php 一致）
use Xiaosongshu\Flv2mp4\Opus\OpusWorkerServer;

try {
    $owned = in_array('--owned', $argv, true);
    $port = 8330;
    $autoload = null;
    foreach ($argv as $argument) {
        if (str_starts_with($argument, '--autoload=')) {
            $autoload = substr($argument, 11);
        } elseif (str_starts_with($argument, '--port=')) {
            $port = filter_var(substr($argument, 7), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
            if ($port === false) {
                throw new InvalidArgumentException('Invalid Opus worker port');
            }
        }
    }
    $autoload ??= dirname(__DIR__) . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException("Composer autoload file not found: {$autoload}");
    }
    require_once $autoload;
    (new OpusWorkerServer())->run("tcp://127.0.0.1:{$port}", $owned ? 1.0 : null);
} catch (Throwable $e) {
    fwrite(STDERR, "Opus worker failed: {$e->getMessage()}\n");
    exit(1);
}
