<?php

use Xiaosongshu\Flv2mp4\Opus\OpusDecoderWorkerServer;
use Xiaosongshu\Flv2mp4\Opus\OpusEncoderWorkerServer;
use Xiaosongshu\Flv2mp4\Opus\OpusWorkerServer;

try {
    $owned = in_array('--owned', $argv, true);
    $port = 8330;
    $role = 'legacy';
    $autoload = null;
    foreach ($argv as $argument) {
        if (str_starts_with($argument, '--autoload=')) {
            $autoload = substr($argument, 11);
        } elseif (str_starts_with($argument, '--port=')) {
            $port = filter_var(substr($argument, 7), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
            if ($port === false) throw new InvalidArgumentException('Invalid Opus worker port');
        } elseif (str_starts_with($argument, '--role=')) {
            $role = substr($argument, 7);
        }
    }
    if ($autoload === null) {
        $candidates = [];
        if (isset($_composer_autoload_path) && is_string($_composer_autoload_path)) $candidates[] = $_composer_autoload_path;
        $candidates[] = dirname(__DIR__) . '/vendor/autoload.php';
        $candidates[] = dirname(__DIR__, 3) . '/autoload.php';
        foreach ($candidates as $candidate) if (is_file($candidate)) { $autoload = $candidate; break; }
    }
    if ($autoload === null || !is_file($autoload)) throw new RuntimeException('Composer autoload file not found; pass --autoload=/path/to/vendor/autoload.php');
    require_once $autoload;
    $idle = $owned ? 1.0 : null;
    if ($role === 'decoder') (new OpusDecoderWorkerServer())->run("tcp://127.0.0.1:{$port}", $idle);
    elseif ($role === 'encoder') (new OpusEncoderWorkerServer())->run("tcp://127.0.0.1:{$port}", $idle);
    elseif ($role === 'legacy') (new OpusWorkerServer())->run("tcp://127.0.0.1:{$port}", $idle);
    else throw new InvalidArgumentException('Invalid Opus worker role');
} catch (Throwable $e) {
    fwrite(STDERR, "Opus worker failed: {$e->getMessage()}\n");
    exit(1);
}
