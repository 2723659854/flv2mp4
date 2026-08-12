<?php

use Xiaosongshu\Flv2mp4\Opus\OpusWorkerServer;

require_once dirname(__DIR__) . '/vendor/autoload.php';

try {
    $owned = in_array('--owned', $argv, true);
    (new OpusWorkerServer())->run('tcp://127.0.0.1:8330', $owned ? 1.0 : null);
} catch (Throwable $e) {
    fwrite(STDERR, "Opus worker failed: {$e->getMessage()}\n");
    exit(1);
}
