<?php

namespace Xiaosongshu\Flv2mp4\Recode;

use RuntimeException;
use Throwable;

final class FlvOutputWorkerServer
{
    public function __construct(private array $config, private string $outputFile)
    {
    }

    public function run(string $listenAddress): void
    {
        $server = @stream_socket_server($listenAddress, $errno, $error);
        if ($server === false) throw new RuntimeException("输出进程监听失败: {$error} ({$errno})");
        $socket = @stream_socket_accept($server, 15); fclose($server);
        if ($socket === false) throw new RuntimeException('输出进程等待解码进程连接超时');
        stream_set_blocking($socket, false);
        $recoder = new FlvRecoder($this->config, false);
        $input = ''; $output = ''; $expected = 0; $finished = false;
        $gopWorkers = max(1, (int)($this->config['gopWorkers'] ?? 1));
        $profiles = ['default' => $this->config];
        $pool = $gopWorkers > 1 ? new GopPool($gopWorkers, (int)($this->config['motionWorkersPerGop'] ?? 1), (float)($this->config['gopSeconds'] ?? 2.0)) : null;
        $replay = static fn(array $queued) => $recoder->processPipelineEvent($queued['metadata'], $queued['payload']);
        try {
            while (true) {
                $read = $finished ? [] : [$socket]; $write = $output === '' ? [] : [$socket];
                if ($read === [] && $write === []) return;
                $except = null; @stream_select($read, $write, $except, 0, 200000);
                if ($read !== []) {
                    $chunk = @fread($socket, 65536);
                    if ($chunk === false || ($chunk === '' && feof($socket))) throw new RuntimeException('解码进程媒体连接意外关闭');
                    $input .= $chunk; if (strlen($input) > HlsPipelineProtocol::MAX_BUFFER_LENGTH) throw new RuntimeException('输出进程输入缓冲超限');
                }
                foreach (HlsPipelineProtocol::take($input, 1) as $event) {
                    if ($event['sequence'] !== $expected) throw new RuntimeException("媒体事件 sequence 不连续，期望 {$expected}，实际 {$event['sequence']}");
                    $expected++;
                    if ($event['type'] === HlsPipelineProtocol::END) {
                        if ($pool !== null) $pool->finish($profiles, $replay);
                        $recoder->finishPipelineOutput($this->outputFile); $output .= HlsPipelineProtocol::frame(HlsPipelineProtocol::FINISHED, $event['sequence']); $finished = true;
                    } elseif ($event['type'] === HlsPipelineProtocol::EVENT) {
                        if ($pool !== null) $pool->push($event, $profiles, $replay);
                        else $recoder->processPipelineEvent($event['metadata'], $event['payload']);
                    }
                    else throw new RuntimeException('输出进程收到未知事件');
                }
                if ($write !== []) { $n = @fwrite($socket, substr($output, 0, 65536)); if ($n === false || ($n === 0 && feof($socket))) throw new RuntimeException('无法发送输出完成响应'); if ($n > 0) $output = substr($output, $n); }
            }
        } catch (Throwable $e) {
            @stream_set_blocking($socket, true); @fwrite($socket, HlsPipelineProtocol::frame(HlsPipelineProtocol::ERROR, $expected, ['message' => $e->getMessage()]));
            throw $e;
        } finally { @fclose($socket); }
    }
}
