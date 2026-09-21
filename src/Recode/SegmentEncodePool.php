<?php

namespace Xiaosongshu\Flv2mp4\Recode;

use RuntimeException;

/**
 * @purpose FLV/MP4 段池并行-段编码池协调端（Task 6）
 *
 * 只负责协议与 IO，不持有子进程（进程由 PipelineClient 统一 start/wait/terminate）：
 * 客户端把已连接的段 worker socket 数组传入。
 *
 * 生命周期/调度模型（与 HlsSegmentPipelineClient 同构）：
 *  - openSegment() 选一个空闲 worker 独占一个段（segBegin），返回段序号；
 *  - submitFrame() 把段内帧（YUV）顺序追加给该 worker；
 *  - closeSegment() 发 segEnd；worker 编码完回 segDone 后该 worker 重新空闲；
 *  - segDone 允许乱序到达，drainDone() 只按段序号连续发布，保证输出顺序；
 *  - finishStart() 广播 END，service() 收齐 FINISHED 即 allFinished()。
 */
final class SegmentEncodePool
{
    /** @var resource[] */
    private array $sockets;
    private int $workerCount;
    /** @var bool[] */
    private array $alive;
    /** @var string[] 各 worker 待写缓冲 */
    private array $outbound;
    /** @var string[] 各 worker 已读未解析缓冲 */
    private array $inbound;
    /** @var int[] workerId => 当前占用段序号（-1=空闲） */
    private array $workerSeg;
    /** @var int[] 段序号 => workerId */
    private array $segWorker;
    /** @var array<int,array> 已完成段 seq => frames（乱序缓存） */
    private array $done = [];
    private int $nextSegSeq = 0;
    private int $nextPublish = 0;
    private int $finishedCount = 0;
    private bool $endSent = false;
    /** @var int[] segDone 实际到达顺序（TR-6.4 乱序证据） */
    private array $doneOrder = [];
    private int $segFrameCount = 0;

    /**
     * @param resource[] $sockets 已连接且已设为非阻塞的段 worker socket
     */
    public function __construct(array $sockets, private int $encoderFps)
    {
        $this->sockets = array_values($sockets);
        $this->workerCount = count($this->sockets);
        if ($this->workerCount < 1) throw new RuntimeException('段编码池至少需要 1 个 worker');
        $this->alive = array_fill(0, $this->workerCount, true);
        $this->outbound = array_fill(0, $this->workerCount, '');
        $this->inbound = array_fill(0, $this->workerCount, '');
        $this->workerSeg = array_fill(0, $this->workerCount, -1);
        $this->segWorker = [];
    }

    /** 加入 stream_select 的读集合（END 发出并收齐 FINISHED 后自动剔除） */
    public function readSockets(): array
    {
        $read = [];
        foreach ($this->alive as $id => $isAlive) if ($isAlive) $read[] = $this->sockets[$id];
        return $read;
    }

    /** 加入 stream_select 的写集合 */
    public function writeSockets(): array
    {
        $write = [];
        foreach ($this->outbound as $id => $buffer) {
            if ($buffer !== '' && $this->alive[$id]) $write[] = $this->sockets[$id];
        }
        return $write;
    }

    /**
     * 处理一次 select 结果中的段池 socket。
     * @param resource[] $read
     * @param resource[] $write
     */
    public function service(array $read, array $write): void
    {
        foreach ($write as $socket) {
            $id = (int)array_search($socket, $this->sockets, true);
            if (!isset($this->outbound[$id]) || $this->outbound[$id] === '') continue;
            $n = @fwrite($socket, substr($this->outbound[$id], 0, 262144));
            if ($n === false || ($n === 0 && feof($socket))) {
                if (!$this->endSent) throw new RuntimeException('段编码 worker 连接意外关闭');
                $this->alive[$id] = false;
                continue;
            }
            if ($n > 0) $this->outbound[$id] = substr($this->outbound[$id], $n);
        }
        foreach ($read as $socket) {
            $id = (int)array_search($socket, $this->sockets, true);
            if (empty($this->alive[$id])) continue;
            $chunk = @fread($socket, 65536);
            if ($chunk === false || ($chunk === '' && feof($socket))) {
                if (!$this->endSent) throw new RuntimeException('段编码 worker 连接意外关闭');
                $this->alive[$id] = false;
                continue;
            }
            if ($chunk !== '') $this->inbound[$id] .= $chunk;
        }
        foreach ($this->inbound as $id => $buffer) {
            foreach (HlsPipelineProtocol::take($this->inbound[$id], PHP_INT_MAX) as $event) {
                $type = (int)$event['type'];
                if ($type === HlsPipelineProtocol::ERROR) {
                    throw new RuntimeException($event['metadata']['message'] ?? '段编码 worker 失败');
                }
                if ($type === HlsPipelineProtocol::FINISHED) {
                    $this->finishedCount++;
                    $this->alive[$id] = false;
                    continue;
                }
                if ($type !== HlsPipelineProtocol::CONTROL || ($event['metadata']['cmd'] ?? '') !== 'segDone') {
                    throw new RuntimeException('协调进程收到非法段编码响应');
                }
                $seq = (int)$event['metadata']['seq'];
                if (!isset($this->segWorker[$seq])) throw new RuntimeException("收到未知段结果: {$seq}");
                if ($this->workerSeg[$id] !== $seq) {
                    throw new RuntimeException("段结果 worker 归属不匹配: seq={$seq} worker={$id}");
                }
                $frames = [];
                foreach (($event['metadata']['frames'] ?? []) as $frame) {
                    $nals = [];
                    foreach (($frame['n'] ?? []) as $b64) {
                        // 跨进程 JSON 传输：NAL 全程保持 base64，由最终 muxer 消费侧解码
                        if (base64_decode((string)$b64, true) === false) {
                            throw new RuntimeException("段 {$seq} NAL base64 非法");
                        }
                        $nals[] = (string)$b64;
                    }
                    if ($nals === []) throw new RuntimeException("段 {$seq} 存在无 NAL 的帧");
                    $frames[] = ['s' => (int)$frame['s'], 'k' => !empty($frame['k']), 'n' => $nals];
                }
                $this->done[$seq] = $frames;
                $this->doneOrder[] = $seq;
                $this->workerSeg[$id] = -1;
                unset($this->segWorker[$seq]);
            }
            if (strlen($this->inbound[$id]) > HlsPipelineProtocol::MAX_BUFFER_LENGTH) {
                throw new RuntimeException('协调进程段响应缓冲超限');
            }
        }
    }

    public function freeWorkerCount(): int
    {
        $count = 0;
        foreach ($this->workerSeg as $seg) if ($seg === -1) $count++;
        return $count;
    }

    /** 当前段在 worker 侧待写字节，客户端据此反压（停止从解码重排队列取帧） */
    public function segmentOutboundBytes(int $segSeq): int
    {
        if (!isset($this->segWorker[$segSeq])) return 0;
        return strlen($this->outbound[$this->segWorker[$segSeq]]);
    }

    /**
     * 在空闲 worker 上开启新段。
     * @return int 段序号
     */
    public function openSegment(): int
    {
        foreach ($this->workerSeg as $id => $seg) {
            if ($seg !== -1) continue;
            $seq = $this->nextSegSeq++;
            $this->workerSeg[$id] = $seq;
            $this->segWorker[$seq] = $id;
            $this->segFrameCount = 0;
            $this->outbound[$id] .= HlsPipelineProtocol::frame(HlsPipelineProtocol::CONTROL, $seq, [
                'cmd' => 'segBegin', 'seq' => $seq, 'fps' => $this->encoderFps,
            ]);
            return $seq;
        }
        throw new RuntimeException('没有空闲段编码 worker');
    }

    public function submitFrame(int $segSeq, int $frameSeq, bool $key, int $width, int $height, string $yuv): void
    {
        if (!isset($this->segWorker[$segSeq])) throw new RuntimeException("向未开启的段提交帧: {$segSeq}");
        $id = $this->segWorker[$segSeq];
        $this->outbound[$id] .= HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $frameSeq, [
            'fseq' => $frameSeq, 'key' => $key, 'w' => $width, 'h' => $height,
        ], $yuv);
        $this->segFrameCount++;
    }

    public function closeSegment(int $segSeq): void
    {
        if (!isset($this->segWorker[$segSeq])) throw new RuntimeException("闭合未开启的段: {$segSeq}");
        if ($this->segFrameCount === 0) throw new RuntimeException("段 {$segSeq} 不含任何帧");
        $id = $this->segWorker[$segSeq];
        $this->outbound[$id] .= HlsPipelineProtocol::frame(HlsPipelineProtocol::CONTROL, $segSeq, [
            'cmd' => 'segEnd', 'seq' => $segSeq,
        ]);
    }

    /**
     * 按段序号连续取出已完成结果（乱序完成的段在内部缓存）。
     * @return array<int,array> 段序号 => frames[['s'=>全局帧序号,'k'=>bool,'n'=>NAL字符串[]]]
     */
    public function drainDone(): array
    {
        $published = [];
        while (isset($this->done[$this->nextPublish])) {
            $published[$this->nextPublish] = $this->done[$this->nextPublish];
            unset($this->done[$this->nextPublish]);
            $this->nextPublish++;
        }
        return $published;
    }

    /** 已开启且尚未发布的段数（含 worker 在编 + 乱序缓存） */
    public function pendingSegmentCount(): int
    {
        return $this->nextSegSeq - $this->nextPublish;
    }

    public function finishStart(): void
    {
        if ($this->endSent) return;
        $this->endSent = true;
        for ($id = 0; $id < $this->workerCount; $id++) {
            $this->outbound[$id] .= HlsPipelineProtocol::frame(HlsPipelineProtocol::END, 0);
        }
    }

    public function allFinished(): bool
    {
        return $this->endSent && $this->finishedCount >= $this->workerCount;
    }

    /** @return int[] segDone 实际到达顺序（TR-6.4 乱序完成证据） */
    public function doneOrder(): array
    {
        return $this->doneOrder;
    }

    public function doneOrderCount(): int
    {
        return count($this->doneOrder);
    }

    public function closeSockets(): void
    {
        foreach ($this->sockets as $socket) if (is_resource($socket)) @fclose($socket);
    }
}
