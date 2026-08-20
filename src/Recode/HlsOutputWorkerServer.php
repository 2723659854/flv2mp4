<?php

namespace Xiaosongshu\Flv2mp4\Recode;

use RuntimeException;
use Throwable;

final class HlsOutputWorkerServer
{
    public function __construct(private array $profiles, private string $outputDir)
    {
    }

    public function run(string $listenAddress): void
    {
        $server = @stream_socket_server($listenAddress, $errno, $error);
        if ($server === false) throw new RuntimeException("编码进程监听失败: {$error} ({$errno})");
        $socket = @stream_socket_accept($server, 15);
        fclose($server);
        if ($socket === false) throw new RuntimeException('编码进程等待解码进程连接超时');
        stream_set_blocking($socket, false);
        $generator = new PurePhpHlsGenerator($this->profiles, $this->outputDir, false);
        $input = '';
        $output = '';
        $expected = 0;
        $finished = false;
        $lastProgressAt = microtime(true);
        $firstProfile = reset($this->profiles) ?: [];
        $gopWorkers = max(1, (int)($firstProfile['gopWorkers'] ?? 1));
        $pool = $gopWorkers > 1 ? new GopPool($gopWorkers, (int)($firstProfile['motionWorkersPerGop'] ?? 1), (float)($firstProfile['gopSeconds'] ?? 2.0)) : null;
        $replay = static fn(array $queued) => $generator->processPipelineEvent($queued['metadata'], $queued['payload']);
        try {
            while (true) {
                $read = $finished ? [] : [$socket];
                $write = $output === '' ? [] : [$socket];
                if ($read === [] && $write === []) return;
                $except = null;
                @stream_select($read, $write, $except, 0, 200000);
                if ($read !== []) {
                    $chunk = @fread($socket, 65536);
                    if ($chunk === false || ($chunk === '' && feof($socket))) throw new RuntimeException('解码进程媒体连接意外关闭');
                    $input .= $chunk;
                    if (strlen($input) > HlsPipelineProtocol::MAX_BUFFER_LENGTH) throw new RuntimeException('编码进程输入缓冲超限');
                }
                foreach (HlsPipelineProtocol::take($input, 1) as $event) {
                    if ($event['sequence'] !== $expected) throw new RuntimeException("媒体事件 sequence 不连续，期望 {$expected}，实际 {$event['sequence']}");
                    $expected++;
                    if ($event['type'] === HlsPipelineProtocol::END) {
                        if ($pool !== null) $pool->finish($this->profiles, $replay);
                        $generator->finishPipelineOutput(count($this->profiles) > 1);
                        $output .= HlsPipelineProtocol::frame(HlsPipelineProtocol::FINISHED, $event['sequence']);
                        $finished = true;
                    } elseif ($event['type'] === HlsPipelineProtocol::EVENT) {
                        if ($pool !== null) $pool->push($event, $this->profiles, $replay);
                        else $generator->processPipelineEvent($event['metadata'], $event['payload']);
                    } else throw new RuntimeException('编码进程收到未知事件');
                }
                if ($write !== []) {
                    $n = @fwrite($socket, substr($output, 0, 65536));
                    if ($n === false || ($n === 0 && feof($socket))) throw new RuntimeException('无法发送编码完成响应');
                    if ($n > 0) $output = substr($output, $n);
                }
            }
        } catch (Throwable $e) {
            $errorFrame = HlsPipelineProtocol::frame(HlsPipelineProtocol::ERROR, $expected, ['message' => $e->getMessage()]);
            @stream_set_blocking($socket, true); @fwrite($socket, $errorFrame);
            throw $e;
        } finally {
            @fclose($socket);
        }
    }
}
