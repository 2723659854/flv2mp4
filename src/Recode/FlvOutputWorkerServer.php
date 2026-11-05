<?php

namespace Xiaosongshu\Flv2mp4\Recode;

use RuntimeException;
use Throwable;

/**
 * @purpose flv重编码分布式架构-子进程输出服务端（支持多个解码worker并发接入，按sequence重排）
 * @author yanglong
 */
final class FlvOutputWorkerServer
{
    public function __construct(private array $config, private string $outputFile)
    {
    }

    /** 当前进程累计 CPU 秒数（用户态+内核态） */
    private static function cpuSeconds(): float
    {
        $usage = getrusage();
        if (!is_array($usage)) return 0.0;
        $user = ($usage['ru_utime.tv_sec'] ?? 0) + ($usage['ru_utime.tv_usec'] ?? 0) / 1e6;
        $sys = ($usage['ru_stime.tv_sec'] ?? 0) + ($usage['ru_stime.tv_usec'] ?? 0) / 1e6;
        return $user + $sys;
    }

    public function run(string $listenAddress, int $workers = 1): void
    {
        $workers = max(1, $workers);
        $server = @stream_socket_server($listenAddress, $errno, $error);
        if ($server === false) throw new RuntimeException("输出进程监听失败: {$error} ({$errno})");
        $sockets = [];
        for ($i = 0; $i < $workers; $i++) {
            $socket = @stream_socket_accept($server, 30);
            if ($socket === false) throw new RuntimeException("输出进程等待解码进程连接超时 ({$i}/{$workers})");
            stream_set_blocking($socket, false);
            $sockets[] = $socket;
        }
        fclose($server);
        $recoder = new FlvRecoder($this->config, false);
        $inputs = array_fill(0, $workers, '');
        $outputs = array_fill(0, $workers, '');
        $pending = [];
        $pendingBytes = 0;
        $expected = 0;
        $endCount = 0;
        $finished = false;
        $lastSeq = array_fill(0, $workers, -1);
        // 反压门控停滞检测：worker 帧级双缓冲使"产出落后于收到"，门控条件 lastSeq<expected
        // 可能恰好排除 expected 帧的持有者（该帧还在它的处理队列/发送缓冲里），导致 expected
        // 永不推进、pending 只进不出。停滞若干轮（progress 无任何变化）后强制放水读全部连接。
        $lastProgress = '';
        $stallRounds = 0;
        $probeLog = getenv('OUT_BUSY_LOG') ?: '';
        $busyNs = 0;
        // CPU 时间探针：进程被其它 worker 抢占时 hrtime 会把等待计入 busy，
        // 因此同时记录 getrusage 的 CPU 秒数，区分"真实计算占用"与"墙钟占用"。
        $cpuStart = $probeLog !== '' ? self::cpuSeconds() : 0.0;
        $tStart = hrtime(true);
        try {
            $recoder->beginPipelineOutput($this->outputFile);
            while (true) {
                $progress = $expected . ':' . count($pending);
                $stallRounds = ($progress === $lastProgress) ? $stallRounds + 1 : 0;
                $lastProgress = $progress;
                // 反压：乱序重排队列达软上限后，连续序号已在队列中则本轮不读（整轮消费会迅速释放积压）；
                // 缺连续序号 N 时，N 只可能来自"最后见到的序号仍小于 N"的落后连接（每路序号严格递增），
                // 故只读这些连接，绝不把超前连接的未来帧吸进重排队列，避免长 GOP 下内存成倍膨胀；
                // 未达上限时读尽全部就绪连接，按唤醒周期批量推进（Windows select 唤醒粒度约 10~15ms）
                $gated = !$finished && $pendingBytes >= HlsPipelineProtocol::PENDING_SOFT_LIMIT;
                if ($gated && $stallRounds > 500) $gated = false;
                if ($finished) $read = [];
                elseif (!$gated) $read = $sockets;
                elseif (isset($pending[$expected])) $read = [];
                else {
                    $read = [];
                    foreach ($sockets as $id => $socket) if ($lastSeq[$id] < $expected) $read[] = $socket;
                }
                $write = [];
                foreach ($outputs as $id => $buffer) if ($buffer !== '') $write[] = $sockets[$id];
                if ($read === [] && $write === []) {
                    if ($finished) {
                        if ($probeLog !== '') {
                            $wall = (hrtime(true) - $tStart) / 1e9;
                            $cpu = max(0.0, self::cpuSeconds() - $cpuStart);
                            file_put_contents($probeLog, json_encode([
                                'wall_s' => $wall,
                                'busy_s' => $busyNs / 1e9,
                                'busy_pct' => ($busyNs / 1e9) / max(1e-9, $wall) * 100,
                                'cpu_s' => $cpu,
                                'cpu_pct' => $cpu / max(1e-9, $wall) * 100,
                            ]));
                        }
                        return;
                    }
                    usleep(1);
                    continue;
                }
                $except = null;
                if (@stream_select($read, $write, $except, 0, 1) === false) continue;
                foreach ($sockets as $id => $socket) {
                    if (!in_array($socket, $read, true)) continue;
                    $chunk = @fread($socket, 65536);
                    if ($chunk === false || ($chunk === '' && feof($socket))) throw new RuntimeException('解码进程媒体连接意外关闭');
                    $inputs[$id] .= $chunk;
                    if (strlen($inputs[$id]) > HlsPipelineProtocol::MAX_BUFFER_LENGTH) throw new RuntimeException('输出进程输入缓冲超限');
                }
                foreach ($inputs as $id => $buffer) {
                    foreach (HlsPipelineProtocol::take($inputs[$id], PHP_INT_MAX) as $event) {
                        // END 与 EVENT 统一按序号入重排队列：END 占用派发序号（dispatcher 的 $sequence++），
                        // 若只计数不入队，expected 可能恰好停在 END 的序号上，而被更大序号的媒体事件
                        // 包围（多路 TCP 交错到达），expected 永不推进 → 全链死锁。
                        // END 消费时跳过（不入封装），仅作为序号推进的占位。
                        if ($event['type'] === HlsPipelineProtocol::END) $endCount++;
                        if ($event['type'] !== HlsPipelineProtocol::EVENT && $event['type'] !== HlsPipelineProtocol::END) continue;
                        $seq = $event['sequence'];
                        if ($seq < $expected) continue;
                        if (isset($pending[$seq])) throw new RuntimeException("媒体事件 sequence 重复: {$seq}");
                        $eventBytes = strlen($event['payload']) + 256;
                        $pending[$seq] = [$event, $eventBytes];
                        $pendingBytes += $eventBytes;
                        $lastSeq[$id] = $seq;
                    }
                }
                if (!$finished && $endCount >= $workers && $pending === []) {
                    $recoder->finishPipelineOutput($this->outputFile);
                    $frame = HlsPipelineProtocol::frame(HlsPipelineProtocol::FINISHED, $expected);
                    for ($i = 0; $i < $workers; $i++) $outputs[$i] .= $frame;
                    $finished = true;
                }
                while (isset($pending[$expected])) {
                    [$event, $eventBytes] = $pending[$expected];
                    unset($pending[$expected]);
                    $pendingBytes -= $eventBytes;
                    if ($event['type'] === HlsPipelineProtocol::EVENT) {
                        // 忙闲探针仅在显式设置 OUT_BUSY_LOG 时采样，默认路径零额外开销
                        $tb = $probeLog !== '' ? hrtime(true) : 0;
                        $recoder->processPipelineEvent($event['metadata'], $event['payload']);
                        if ($probeLog !== '') $busyNs += hrtime(true) - $tb;
                    } elseif ($event['type'] !== HlsPipelineProtocol::END) {
                        throw new RuntimeException('输出进程收到未知事件');
                    }
                    $expected++;
                }
                foreach ($outputs as $id => $buffer) {
                    if ($buffer === '' || !in_array($sockets[$id], $write, true)) continue;
                    $n = @fwrite($sockets[$id], substr($buffer, 0, 65536));
                    if ($n === false || ($n === 0 && feof($sockets[$id]))) throw new RuntimeException('无法发送输出完成响应');
                    if ($n > 0) $outputs[$id] = substr($buffer, $n);
                }
            }
        } catch (Throwable $e) {
            $recoder->abortPipelineOutput();
            $frame = HlsPipelineProtocol::frame(HlsPipelineProtocol::ERROR, $expected, ['message' => $e->getMessage()]);
            foreach ($sockets as $socket) {
                try { @stream_set_blocking($socket, true); @fwrite($socket, $frame); } catch (Throwable) {}
            }
            throw $e;
        } finally {
            foreach ($sockets as $socket) @fclose($socket);
        }
    }
}
