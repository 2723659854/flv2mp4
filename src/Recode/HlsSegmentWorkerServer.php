<?php

namespace Xiaosongshu\Flv2mp4\Recode;

use RuntimeException;
use Throwable;

/**
 * @purpose flv转hls片级并行架构-分片 worker 服务
 *
 * 一个 worker 持有一条主进程长连接，顺序接收多个自包含片任务：
 * 收 CONTROL cmd=segmentTask -> PurePhpHlsGenerator::runSegmentTask
 * 产 segment_{seq}.ts.tmp -> 回 CONTROL cmd=segmentDone；
 * 收 END -> 回 FINISHED 后退出。片 worker 不写任何 m3u8。
 */
final class HlsSegmentWorkerServer
{
    public function __construct(private array $profiles, private string $outputDir)
    {
    }

    public function run(string $listenAddress): void
    {
        $server = @stream_socket_server($listenAddress, $errno, $error);
        if ($server === false) throw new RuntimeException("片 worker 监听失败: {$error} ({$errno})");

        // 片池规格：每个片 worker 内运动估计子进程默认降为 2（N 个片 worker 并行，避免进程超订），
        // 可用 config segment_motion_workers 覆盖（按核数与片 worker 数联合调参）
        $profiles = $this->profiles;
        foreach ($profiles as $name => $profile) {
            if (!is_array($profile)) continue;
            $motion = (int)($profile['segment_motion_workers'] ?? 2);
            $profile['motionWorkers'] = max(1, $motion < 1 ? 2 : $motion);
            $profiles[$name] = $profile;
        }
        // writePlaylists=false：只产 .tmp 分片，播放列表由协调进程顺序发布
        $generator = new PurePhpHlsGenerator($profiles, $this->outputDir, false, 6, false);
        // accept 前预热 motion 孙进程（PHP 冷启动与建链并行）。空闲 motion 进程的 CPU 开销
        // 已由 MotionWorkerServer 的自适应退避 select（1µs→2ms）压到可忽略（Task8/B1），无需延迟拉起；
        // 保留启动期拉起也避免了高负载下惰性建链的竞态窗口
        $generator->warmupMotionWorkers();

        $socket = @stream_socket_accept($server, 30);
        if ($socket === false) throw new RuntimeException('片 worker 等待协调进程连接超时');
        fclose($server);
        stream_set_blocking($socket, true);
        // 检查点链式派发下（Task7/FR-3），已完成本片的 worker 可能空闲等待 >60s 才接到下一片：
        // 必须禁用 PHP 默认 socket 读超时，否则 Windows 上 fread 在超时时返回 false 被误判为掉线
        stream_set_timeout($socket, -1);

        $input = '';
        try {
            while (true) {
                $chunk = fread($socket, 65536);
                if ($chunk === false || ($chunk === '' && feof($socket))) {
                    throw new RuntimeException('片 worker 协调连接意外关闭');
                }
                if ($chunk === '') continue;
                $input .= $chunk;
                if (strlen($input) > HlsPipelineProtocol::MAX_BUFFER_LENGTH) {
                    throw new RuntimeException('片 worker 输入缓冲超限');
                }
                foreach (HlsPipelineProtocol::take($input, PHP_INT_MAX) as $event) {
                    $type = (int)$event['type'];
                    if ($type === HlsPipelineProtocol::END) {
                        $this->writeAll($socket, HlsPipelineProtocol::frame(HlsPipelineProtocol::FINISHED, (int)$event['sequence']));
                        return;
                    }
                    if ($type !== HlsPipelineProtocol::CONTROL) {
                        throw new RuntimeException('片 worker 收到非控制帧');
                    }
                    $cmd = $event['metadata']['cmd'] ?? '';
                    if ($cmd !== 'segmentTask') throw new RuntimeException("片 worker 收到未知控制命令: {$cmd}");
                    $meta = $event['metadata'];
                    $asc = base64_decode((string)($meta['asc'] ?? ''), true);
                    $avcc = base64_decode((string)($meta['avcc'] ?? ''), true);
                    $task = [
                        'seq' => (int)($meta['seq'] ?? 0),
                        'file' => (string)($meta['file'] ?? ''),
                        'asc' => $asc === false ? '' : $asc,
                        'avcc' => $avcc === false ? '' : $avcc,
                        // 上一片末帧 H264 解码检查点（base64(serialize)，片 1 为空串）
                        'cp' => (string)($meta['cp'] ?? ''),
                        'events' => is_array($meta['events'] ?? null) ? $meta['events'] : [],
                    ];
                    $result = $generator->runSegmentTask($task, function (int $cSeq, string $cpFrame) use ($socket) {
                        // Task8/B1：末帧解码完成立即回传 cp（本片编码冲刷尚未开始），
                        // 协调端据此提前派发下一片，形成 解码(n+1)∥编码(n) 流水
                        $this->writeAll($socket, HlsPipelineProtocol::frame(
                            HlsPipelineProtocol::CONTROL, $cSeq,
                            ['cmd' => 'segmentCp', 'seq' => $cSeq, 'cp' => $cpFrame]
                        ));
                    });
                    // segmentDone 不再携带 cp：权威检查点已由早期 segmentCp 帧回传，
                    // 避免每片重复一次数 MB 级 JSON 编码/传输（Task8/B1）
                    $reply = HlsPipelineProtocol::frame(HlsPipelineProtocol::CONTROL, (int)$event['sequence'], [
                        'cmd' => 'segmentDone',
                        'seq' => $result['seq'],
                        'endOutMs' => $result['endOutMs'],
                    ]);
                    $this->writeAll($socket, $reply);
                }
            }
        } catch (Throwable $e) {
            $errorFrame = HlsPipelineProtocol::frame(HlsPipelineProtocol::ERROR, 0, ['message' => $e->getMessage()]);
            try { $this->writeAll($socket, $errorFrame); } catch (Throwable) {}
            throw $e;
        } finally {
            @fclose($socket);
        }
    }

    private function writeAll($socket, string $buffer): void
    {
        while ($buffer !== '') {
            $n = @fwrite($socket, $buffer);
            if ($n === false || ($n === 0 && feof($socket))) throw new RuntimeException('片 worker 响应写失败');
            if ($n > 0) $buffer = substr($buffer, $n);
        }
    }
}
