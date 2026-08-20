<?php

namespace Xiaosongshu\Flv2mp4\Recode;

final class ProcessTree
{
    public static function terminate($process): void
    {
        if (!is_resource($process)) return;
        $status = @proc_get_status($process);
        if ($status !== false && ($status['running'] ?? false)) {
            $pid = (int)($status['pid'] ?? 0);
            if (PHP_OS_FAMILY === 'Windows' && $pid > 0) {
                @exec('taskkill /PID ' . $pid . ' /T /F 2>NUL');
            } else {
                @proc_terminate($process);
            }
        }
        @proc_close($process);
    }
}
