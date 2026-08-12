<?php

use Xiaosongshu\Flv2mp4\Opus\OpusWorkerServer;

require_once dirname(__DIR__) . '/vendor/autoload.php';

try {
    $owned = in_array('--owned', $argv, true);
    $port = 8330;
    foreach ($argv as $argument) {
        if (str_starts_with($argument, '--port=')) {
            $port = filter_var(substr($argument, 7), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
            if ($port === false) {
                throw new InvalidArgumentException('Invalid Opus worker port');
            }
        }
    }
    (new OpusWorkerServer())->run("tcp://127.0.0.1:{$port}", $owned ? 1.0 : null);
} catch (Throwable $e) {
    fwrite(STDERR, "Opus worker failed: {$e->getMessage()}\n");
    exit(1);
}
