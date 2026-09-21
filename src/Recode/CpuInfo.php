<?php

namespace Xiaosongshu\Flv2mp4\Recode;

/**
 * @purpose 跨平台 CPU 逻辑核数检测
 * @note 全项目唯一允许读取系统环境变量（NUMBER_OF_PROCESSORS）的位置，
 *       检测结果仅用于 worker 进程数的默认值自适应。
 */
final class CpuInfo
{
    private static ?int $cores = null;

    /** 获取本机逻辑 CPU 核数；所有检测手段均失败时回退为 2 */
    public static function cores(): int
    {
        return self::$cores ??= self::detect();
    }

    /**
     * worker 默认数量：按核数自适应
     *
     * @param int $max 上限
     * @param int $reserve 为系统/主进程预留的核数（段/片 worker 池使用，下限 1）
     */
    public static function defaultWorkers(int $max = 8, int $reserve = 0): int
    {
        return max(1, min($max, self::cores() - $reserve));
    }

    private static function detect(): int
    {
        // Windows：系统环境变量
        $win = getenv('NUMBER_OF_PROCESSORS');
        if (is_string($win) && (int)$win > 0) return (int)$win;

        // Linux：/proc/cpuinfo（纯文件读取，无需外部进程）
        if (is_readable('/proc/cpuinfo')) {
            $raw = @file_get_contents('/proc/cpuinfo');
            if ($raw !== false) {
                $count = preg_match_all('/^processor\s*:/m', $raw);
                if ($count > 0) return (int)$count;
            }
        }

        // Linux 兜底：nproc
        if (PHP_OS_FAMILY !== 'Windows' && function_exists('shell_exec')) {
            $nproc = @shell_exec('nproc 2>/dev/null');
            if (is_string($nproc) && (int)trim($nproc) > 0) return (int)trim($nproc);

            // macOS：sysctl
            $sysctl = @shell_exec('sysctl -n hw.logicalcpu 2>/dev/null');
            if (is_string($sysctl) && (int)trim($sysctl) > 0) return (int)trim($sysctl);
        }

        return 2;
    }
}
