<?php

namespace Xiaosongshu\Flv2mp4\Recode;

use RuntimeException;
use Throwable;

/**
 * @purpose flv转hls分布式架构-输出服务（支持多个解码worker并发接入，按sequence重排）
 * @author yanglong
 */
final class HlsOutputWorkerServer
{
    public function __construct(private array $profiles, private string $outputDir)
    {
    }

    public function run(string $listenAddress, int $workers = 1): void
    {
        $workers = max(1, $workers);
        $server = @stream_socket_server($listenAddress, $errno, $error);
        if ($server === false) throw new RuntimeException("编码进程监听失败: {$error} ({$errno})");
        $generator = new PurePhpHlsGenerator($this->profiles, $this->outputDir, false);
        // 冷启动运动估计子进程放在 accept 之前：监听 backlog 暂存 decoder 连接，
        // PHP 冷启动与 decoder 启动/首 GOP 解码完全并行
        $generator->warmupMotionWorkers();
        $sockets = [];
        for ($i = 0; $i < $workers; $i++) {
            $socket = @stream_socket_accept($server, 30);
            if ($socket === false) throw new RuntimeException("编码进程等待解码进程连接超时 ({$i}/{$workers})");
            stream_set_blocking($socket, false);
            $sockets[] = $socket;
        }
        fclose($server);
        $inputs = array_fill(0, $workers, '');
        $outputs = array_fill(0, $workers, '');
        $pending = [];
        $expected = 0;
        $finished = false;
        $pool = null;
        $replay = static fn(array $queued) => $generator->processPipelineEvent($queued['metadata'], $queued['payload']);
        try {
            while (true) {
                $read = $finished ? [] : $sockets;
                $write = [];
                foreach ($outputs as $id => $buffer) if ($buffer !== '') $write[] = $sockets[$id];
                if ($read === [] && $write === []) return;
                $except = null;
                if (@stream_select($read, $write, $except, 0, 1) === false) continue;
                foreach ($sockets as $id => $socket) {
                    if (!in_array($socket, $read, true)) continue;
                    $chunk = @fread($socket, 65536);
                    if ($chunk === false || ($chunk === '' && feof($socket))) throw new RuntimeException('解码进程媒体连接意外关闭');
                    $inputs[$id] .= $chunk;
                    if (strlen($inputs[$id]) > HlsPipelineProtocol::MAX_BUFFER_LENGTH) throw new RuntimeException('编码进程输入缓冲超限');
                }
                foreach ($inputs as $id => $buffer) {
                    foreach (HlsPipelineProtocol::take($inputs[$id], PHP_INT_MAX) as $event) {
                        $seq = $event['sequence'];
                        if ($seq < $expected) continue;
                        if (isset($pending[$seq])) throw new RuntimeException("媒体事件 sequence 重复: {$seq}");
                        $pending[$seq] = $event;
                    }
                }
                while (isset($pending[$expected])) {
                    $event = $pending[$expected];
                    unset($pending[$expected]);
                    if ($event['type'] === HlsPipelineProtocol::END) {
                        if ($pool !== null) $pool->finish($this->profiles, $replay);
                        $generator->finishPipelineOutput(count($this->profiles) > 1);
                        $frame = HlsPipelineProtocol::frame(HlsPipelineProtocol::FINISHED, $event['sequence']);
                        for ($i = 0; $i < $workers; $i++) $outputs[$i] .= $frame;
                        $finished = true;
                    } elseif ($event['type'] === HlsPipelineProtocol::EVENT) {
                        if ($pool !== null) $pool->push($event, $this->profiles, $replay);
                        else $generator->processPipelineEvent($event['metadata'], $event['payload']);
                    } else throw new RuntimeException('编码进程收到未知事件');
                    $expected++;
                }
                foreach ($outputs as $id => $buffer) {
                    if ($buffer === '' || !in_array($sockets[$id], $write, true)) continue;
                    $n = @fwrite($sockets[$id], substr($buffer, 0, 65536));
                    if ($n === false || ($n === 0 && feof($sockets[$id]))) throw new RuntimeException('无法发送编码完成响应');
                    if ($n > 0) $outputs[$id] = substr($buffer, $n);
                }
            }
        } catch (Throwable $e) {
            $errorFrame = HlsPipelineProtocol::frame(HlsPipelineProtocol::ERROR, $expected, ['message' => $e->getMessage()]);
            foreach ($sockets as $socket) {
                try { @stream_set_blocking($socket, true); @fwrite($socket, $errorFrame); } catch (Throwable) {}
            }
            throw $e;
        } finally {
            foreach ($sockets as $socket) @fclose($socket);
        }
    }
}
