<?php

namespace Xiaosongshu\Flv2mp4\Manage;

use Closure;
use RuntimeException;

/**
 * @purpose 静态文件音频并行转码管线：解码子进程 → 父进程管道中继 → 编码子进程。
 * 总耗时由"解码+编码之和"缩短为两者中的较大值。
 * 仅用于静态文件转码，与直播转码（OpusWorkerClient/OpusToAacTranscoder 等）无关。
 * 管道为阻塞模式，依靠"编码器只写文件/内存、解码器只读文件"的天然流控避免死锁。
 * @author yanglong
 * @time 2026年10月26日
 */
final class AudioParallelPipeline
{
    private const RELAY_CHUNK = 262144;

    /**
     * @param string $decodeFormat 解码格式 aac|mp3|wav|opus
     * @param string $inputFile 输入文件
     * @param string $encodeFormat 编码格式 aac|mp3|wav|opus
     * @param string $outputFile 输出文件
     * @param array|Closure $encodeOptions 编码选项数组，或 fn(array $meta): array（拿到解码元数据后计算，如按声道折算码率）
     * @param Closure|null $validateMeta fn(array $meta): void 解码元数据回调，校验失败抛异常可 fail-fast 终止整条管线
     * @param Closure|null $onEvent fn(string $stage, array $event) 进度事件回调，$stage 为 decode|encode，$event 含 ev/type 字段
     * @param array $decodeOptions 解码选项，如 ['gainQ8' => 'auto']（仅 opus→mp3 路径应用 outputGainQ8）
     * @return array{decode: array, encode: array} decode=解码元数据+统计，encode=编码统计
     */
    public static function run(
        string $decodeFormat,
        string $inputFile,
        string $encodeFormat,
        string $outputFile,
        array|Closure $encodeOptions = [],
        ?Closure $validateMeta = null,
        ?Closure $onEvent = null,
        array $decodeOptions = []
    ): array {
        if (!is_file($inputFile)) {
            throw new RuntimeException("输入文件不存在: {$inputFile}");
        }
        $script = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'audio-worker.php';
        if (!is_file($script)) {
            throw new RuntimeException("找不到 audio-worker 脚本: {$script}");
        }
        $nullDevice = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';

        $dec = null;
        $enc = null;
        $dPipes = [];
        $ePipes = [];
        try {
            $decCmd = [PHP_BINARY, $script, 'decode', $decodeFormat, $inputFile];
            foreach ($decodeOptions as $key => $value) {
                $decCmd[] = '--' . $key . '=' . $value;
            }
            $dec = @proc_open($decCmd, [
                0 => ['file', $inputFile, 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $dPipes, dirname(__DIR__, 2), null, ['bypass_shell' => true]);
            if (!is_resource($dec)) {
                throw new RuntimeException('无法启动解码子进程');
            }

            // 阻塞读取解码器 stderr 直到 meta（wav/opus/aac 均在重活前发出）
            [$decMeta, $decError] = self::readEvents($dPipes[2], $onEvent, 'decode', true);
            if ($decMeta === null) {
                throw new RuntimeException($decError ?? '解码子进程未报告音频元数据');
            }
            if ($validateMeta !== null) {
                $validateMeta($decMeta);
            }

            $options = $encodeOptions instanceof Closure ? $encodeOptions($decMeta) : $encodeOptions;
            $encCmd = [PHP_BINARY, $script, 'encode', $encodeFormat, $outputFile,
                '--rate=' . (int)$decMeta['sampleRate'], '--channels=' . (int)$decMeta['channels']];
            if (isset($options['bitrate'])) {
                $encCmd[] = '--bitrate=' . (int)$options['bitrate'];
            }
            if (isset($options['preSkip'])) {
                $encCmd[] = '--pre-skip=' . (int)$options['preSkip'];
            }
            $enc = @proc_open($encCmd, [
                0 => ['pipe', 'r'],
                1 => ['file', $nullDevice, 'w'],
                2 => ['pipe', 'w'],
            ], $ePipes, dirname(__DIR__, 2), null, ['bypass_shell' => true]);
            if (!is_resource($enc)) {
                throw new RuntimeException('无法启动编码子进程');
            }

            // 中继：解码 stdout → 编码 stdin。阻塞管道自然流控：
            // 编码慢 → 编码 stdin 满 → 中继写阻塞 → 解码 stdout 积压 → 解码阻塞，无环形等待。
            try {
                while (true) {
                    $chunk = fread($dPipes[1], self::RELAY_CHUNK);
                    if ($chunk === false || $chunk === '') {
                        break; // 解码器 stdout EOF
                    }
                    self::writeAll($ePipes[0], $chunk);
                }
            } catch (RuntimeException $relayError) {
                // 写入失败通常是编码器已崩溃：取回其 stderr 中的真实错误原因
                $status = proc_get_status($enc);
                if (!$status['running']) {
                    [, $encError] = self::readEvents($ePipes[2], null, 'encode', false);
                    throw new RuntimeException($encError ?? $relayError->getMessage(), 0, $relayError);
                }
                throw $relayError;
            }
            // 解码器提前崩溃时中止整条管线，避免产出截断文件
            $status = proc_get_status($dec);
            if (!$status['running'] && $status['exitcode'] !== 0) {
                [, $decError] = self::readEvents($dPipes[2], null, 'decode', false);
                throw new RuntimeException($decError ?? '解码子进程异常退出（exit ' . $status['exitcode'] . '）');
            }

            fclose($ePipes[0]); // 编码器收到 EOF 后 flush 并收尾
            $ePipes[0] = null;
            fclose($dPipes[1]);
            $dPipes[1] = null;

            [$decDone] = self::readEvents($dPipes[2], $onEvent, 'decode', false);
            fclose($dPipes[2]);
            $dPipes[2] = null;
            $decExit = proc_close($dec);
            $dec = null;
            if ($decExit !== 0) {
                throw new RuntimeException($decError ?? "解码子进程退出码 {$decExit}");
            }

            [$encDone, $encError] = self::readEvents($ePipes[2], $onEvent, 'encode', false);
            fclose($ePipes[2]);
            $ePipes[2] = null;
            $encExit = proc_close($enc);
            $enc = null;
            if ($encExit !== 0 || $encDone === null) {
                throw new RuntimeException($encError ?? "编码子进程退出码 {$encExit}");
            }

            return [
                'decode' => $decMeta + ($decDone ?? []),
                'encode' => $encDone,
            ];
        } catch (\Throwable $e) {
            // 失败时清理可能残留的 .part 临时文件（随机后缀，仿各 Manage 类 finally 清理语义）
            foreach (glob($outputFile . '.part.*') ?: [] as $partFile) {
                @unlink($partFile);
            }
            throw $e;
        } finally {
            foreach (['enc' => &$enc, 'dec' => &$dec] as $key => $process) {
                if (is_resource($process)) {
                    proc_terminate($process);
                    proc_close($process);
                    ${$key} = null;
                }
            }
            foreach ([$dPipes, $ePipes] as $pipes) {
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        @fclose($pipe);
                    }
                }
            }
        }
    }

    /**
     * 逐行读取 worker stderr JSON 事件。
     * @return array{0: ?array, 1: ?string} [meta 或 done 事件, error 消息]
     */
    private static function readEvents($stream, ?Closure $onEvent, string $stage, bool $stopAtMeta): array
    {
        $metaOrDone = null;
        $error = null;
        while (($line = fgets($stream)) !== false) {
            $event = json_decode(trim($line), true);
            if (!is_array($event)) {
                continue;
            }
            if ($onEvent !== null) {
                $onEvent($stage, $event);
            }
            switch ($event['ev'] ?? '') {
                case 'meta':
                case 'done':
                    if ($metaOrDone === null) {
                        $metaOrDone = $event;
                    }
                    if ($stopAtMeta && $event['ev'] === 'meta') {
                        return [$metaOrDone, $error];
                    }
                    break;
                case 'error':
                    $error = (string)($event['message'] ?? 'worker 失败');
                    break;
            }
        }
        return [$metaOrDone, $error];
    }

    private static function writeAll($handle, string $data): void
    {
        for ($offset = 0, $length = strlen($data); $offset < $length;) {
            $written = @fwrite($handle, substr($data, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('编码器已退出或 stdin 写入失败');
            }
            $offset += $written;
        }
    }
}
