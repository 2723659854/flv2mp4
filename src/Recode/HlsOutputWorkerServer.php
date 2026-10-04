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

    public function run(string $listenAddress, int $workers = 1, string $controlAddress = '', bool $preEncoded = false): void
    {
        $workers = max(1, $workers);
        $server = @stream_socket_server($listenAddress, $errno, $error);
        if ($server === false) throw new RuntimeException("编码进程监听失败: {$error} ({$errno})");
        // 独立控制连接：主进程的finish屏障走此通道直达，与媒体FIFO完全隔离
        // （媒体流里可能正卡着一个已发出一半的大帧，任何插入都会破坏长度前缀分帧）
        $ctrlServer = null;
        $ctrlConn = null;
        if ($controlAddress !== '') {
            $ctrlServer = @stream_socket_server($controlAddress, $errno, $error);
            if ($ctrlServer === false) throw new RuntimeException("编码进程控制端口监听失败: {$error} ({$errno})");
            stream_set_blocking($ctrlServer, false);
        }
        $generator = new PurePhpHlsGenerator($this->profiles, $this->outputDir, false);
        // 直播切片时长经 profile 透传（默认 3 秒）
        $firstProfile = $this->profiles[array_key_first($this->profiles)] ?? [];
        $segmentDuration = (int)($firstProfile['segmentDuration'] ?? 3);
        if ($segmentDuration > 0) $generator->setSegmentDuration($segmentDuration);
        // 先接受全部 decoder 连接，避免运动估计子进程冷启动阻塞流水线建链。
        // warmup 放到连接完成后执行，decoder 的媒体连接可在内核缓冲中等待。
        $sockets = [];
        for ($i = 0; $i < $workers; $i++) {
            $socket = @stream_socket_accept($server, 30);
            if ($socket === false) throw new RuntimeException("编码进程等待解码进程连接超时 ({$i}/{$workers})");
            stream_set_blocking($socket, false);
            $sockets[] = $socket;
        }
        fclose($server);
        // 控制连接也要在预热前接入，否则主进程仍会卡在 outCtrl 建链阶段。
        if ($ctrlServer !== null) {
            $ctrlConn = @stream_socket_accept($ctrlServer, 15);
            if ($ctrlConn === false) throw new RuntimeException('编码进程等待控制连接超时');
            stream_set_blocking($ctrlConn, false);
            fclose($ctrlServer);
            $ctrlServer = null;
        }
        // 媒体和控制连接全部建立后再预热运动估计进程。
        // 主进程此时可以立即完成流水线就绪，不再等待运动 worker 冷启动。
        // 单 profile 分布式路径下视频帧已在解码 worker 编码完成，输出 worker 的编码器
        // 永远不会被调用，跳过预热，避免白启动 2 个 PHP 子进程（多 profile 输出 worker 仍需要）。
        if (!$preEncoded) $generator->warmupMotionWorkers();
        $inputs = array_fill(0, $workers, '');
        $outputs = array_fill(0, $workers, '');
        $pending = [];
        $pendingBytes = 0;
        $expected = 0;
        $finished = false;
        $endCount = 0;
        $lastSeq = array_fill(0, $workers, -1);
        $ctrlInput = '';
        $ctrlFinish = false;
        $pool = null;
        $replay = static fn(array $queued) => $generator->processPipelineEvent($queued['metadata'], $queued['payload']);
        try {
            while (true) {
                // 反压：乱序重排队列达软上限后，连续序号已在队列中则本轮不读（整轮消费会迅速释放积压）；
                // 缺连续序号 N 时，N 只可能来自"最后见到的序号仍小于 N"的落后连接（每路序号严格递增），
                // 故只读这些连接，绝不把超前连接的未来帧吸进重排队列，避免长 GOP 下内存成倍膨胀；
                // 未达上限时读尽全部就绪连接，按唤醒周期批量推进（Windows select 唤醒粒度约 10~15ms）；
                // 一旦收到主进程finish屏障，停止反压门控并立即收尾丢弃尾部，
                // 控制连接独立于媒体流，不存在被48MB积压挡住的问题
                $gated = !$finished && !$ctrlFinish && $pendingBytes >= HlsPipelineProtocol::PENDING_SOFT_LIMIT;
                if ($finished) $read = [];
                elseif (!$gated) $read = $sockets;
                elseif (isset($pending[$expected])) $read = [];
                else {
                    $read = [];
                    foreach ($sockets as $id => $socket) if ($lastSeq[$id] < $expected) $read[] = $socket;
                }
                if ($ctrlServer !== null) $read[] = $ctrlServer;
                if ($ctrlConn !== null) $read[] = $ctrlConn;
                $write = [];
                foreach ($outputs as $id => $buffer) if ($buffer !== '') $write[] = $sockets[$id];
                if ($read === [] && $write === []) {
                    if ($finished) return;
                    usleep(1);
                    continue;
                }
                $except = null;
                if (@stream_select($read, $write, $except, 0, 2000) === false) continue;
                if ($ctrlServer !== null && in_array($ctrlServer, $read, true)) {
                    $conn = @stream_socket_accept($ctrlServer, 0);
                    if ($conn !== false) {
                        stream_set_blocking($conn, false);
                        $ctrlConn = $conn;
                    }
                }
                if ($ctrlConn !== null && in_array($ctrlConn, $read, true)) {
                    $chunk = @fread($ctrlConn, 65536);
                    if ($chunk === '' && feof($ctrlConn)) { @fclose($ctrlConn); $ctrlConn = null; }
                    else $ctrlInput .= $chunk;
                    foreach (HlsPipelineProtocol::take($ctrlInput, PHP_INT_MAX) as $ctrlEvent) {
                        if ($ctrlEvent['type'] === HlsPipelineProtocol::CONTROL && ($ctrlEvent['metadata']['cmd'] ?? '') === 'finish') {
                            $ctrlFinish = true;
                        }
                    }
                }
                foreach ($sockets as $id => $socket) {
                    if (!in_array($socket, $read, true)) continue;
                    $chunk = @fread($socket, 65536);
                    if ($chunk === false || ($chunk === '' && feof($socket))) throw new RuntimeException('解码进程媒体连接意外关闭');
                    $inputs[$id] .= $chunk;
                    if (strlen($inputs[$id]) > HlsPipelineProtocol::MAX_BUFFER_LENGTH) throw new RuntimeException('编码进程输入缓冲超限');
                }
                foreach ($inputs as $id => $buffer) {
                    foreach (HlsPipelineProtocol::take($inputs[$id], PHP_INT_MAX) as $event) {
                        // 媒体通道只接受EVENT/END；CONTROL/PROGRESS等控制帧不入重排队列
                        if ($event['type'] === HlsPipelineProtocol::END) {
                            $endCount++;
                            continue;
                        }
                        if ($event['type'] !== HlsPipelineProtocol::EVENT) continue;
                        $seq = $event['sequence'];
                        if ($seq < $expected) continue;
                        if (isset($pending[$seq])) throw new RuntimeException("媒体事件 sequence 重复: {$seq}");
                        $eventBytes = strlen($event['payload']) + 256;
                        $pending[$seq] = [$event, $eventBytes];
                        $pendingBytes += $eventBytes;
                        $lastSeq[$id] = $seq;
                    }
                }
                // 只有所有 decoder 都报告 END，且重排队列已经连续排空后，才关闭分片。
                // 控制连接 finish 仅作为异常收尾兜底，不清空已收到的媒体事件。
                if (!$finished && $endCount >= $workers && $pending === []) {
                    $generator->finishPipelineOutput(count($this->profiles) > 1);
                    $frame = HlsPipelineProtocol::frame(HlsPipelineProtocol::FINISHED, $expected);
                    for ($i = 0; $i < $workers; $i++) $outputs[$i] .= $frame;
                    $finished = true;
                }
                while (isset($pending[$expected])) {
                    [$event, $eventBytes] = $pending[$expected];
                    unset($pending[$expected]);
                    $pendingBytes -= $eventBytes;
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
            if ($ctrlConn !== null) @fclose($ctrlConn);
            if ($ctrlServer !== null) @fclose($ctrlServer);
        }
    }
}
