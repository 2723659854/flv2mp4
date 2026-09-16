<?php

namespace Xiaosongshu\Flv2mp4\Recode;

use RuntimeException;
use Throwable;

/**
 * @purpose mp4重编码分布式架构-输出（支持多个解码worker并发接入，按sequence重排）
 * @author yanglong
 */
final class Mp4OutputWorkerServer
{
    public function __construct(private array $config, private string $outputFile)
    {
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
        $recoder = new Mp4Recoder($this->config, false);
        $recoder->initializePipelineOutput($this->config['pipeline'], $this->outputFile);
        $inputs = array_fill(0, $workers, '');
        $outputs = array_fill(0, $workers, '');
        $pending = [];
        $expected = 0;
        $finished = false;
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
                    if (strlen($inputs[$id]) > HlsPipelineProtocol::MAX_BUFFER_LENGTH) throw new RuntimeException('输出进程输入缓冲超限');
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
                        $recoder->finishPipelineOutput($this->outputFile);
                        $frame = HlsPipelineProtocol::frame(HlsPipelineProtocol::FINISHED, $event['sequence']);
                        for ($i = 0; $i < $workers; $i++) $outputs[$i] .= $frame;
                        $finished = true;
                    } elseif ($event['type'] === HlsPipelineProtocol::EVENT) {
                        $recoder->processPipelineSample($event['metadata'], $event['payload']);
                    } else throw new RuntimeException('输出进程收到未知事件');
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
            $frame = HlsPipelineProtocol::frame(HlsPipelineProtocol::ERROR, $expected, ['message' => $e->getMessage()]);
            foreach ($sockets as $socket) {
                try { @stream_set_blocking($socket, true); @fwrite($socket, $frame); } catch (Throwable) {}
            }
            throw $e;
        } finally {
            $recoder->cleanupPipelineOutput();
            foreach ($sockets as $socket) @fclose($socket);
        }
    }
}
