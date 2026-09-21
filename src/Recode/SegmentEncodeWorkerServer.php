<?php

namespace Xiaosongshu\Flv2mp4\Recode;

use RuntimeException;
use Throwable;
use Xiaosongshu\Flv2mp4\Codec\H264Encoder;

/**
 * @purpose FLV/MP4 段池并行-段编码 worker 服务（Task 6）
 *
 * 一个 worker 持一条与协调进程的长连接，顺序处理多个自包含段：
 *   CONTROL cmd=segBegin {seq, fps}
 *   EVENT   每帧 meta={fseq,key,w,h} payload=I420 YUV
 *   CONTROL cmd=segEnd   {seq}
 *   -> CONTROL cmd=segDone {seq, frames:[{s,k,n:[base64 NAL...]}]}
 * 收 END -> 回 FINISHED 后退出。
 *
 * 段间状态隔离：每段首帧强制 IDR，H264Encoder 在 IDR 时自动重置
 * frameNum/POC/参考帧并重新生成 SPS/PPS，因此同一个编码器实例可跨段复用，
 * 运动估计子进程组也常驻复用（与输出进程旧路径同构）。
 */
final class SegmentEncodeWorkerServer
{
    public function __construct(private array $config)
    {
    }

    public function run(string $listenAddress): void
    {
        $server = @stream_socket_server($listenAddress, $errno, $error);
        if ($server === false) throw new RuntimeException("段编码 worker 监听失败: {$error} ({$errno})");

        $encoder = new H264Encoder();
        // 段池内每个编码 worker 自带小型 motion 子进程组，默认 2（可用 segment_motion_workers 覆盖）
        $motion = (int)($this->config['segment_motion_workers'] ?? 2);
        $encoder->motionWorkers = max(1, $motion < 1 ? 2 : $motion);
        TranscodeOptions::applyEncoder($encoder, $this->config);
        // 冷启动运动估计子进程放在 accept 之前，与主进程建连并行
        $encoder->warmupMotionWorkers();

        $socket = @stream_socket_accept($server, 30, $peerName);
        if ($socket === false) throw new RuntimeException('段编码 worker 等待协调进程连接超时');
        fclose($server);
        stream_set_blocking($socket, true);

        $input = '';
        // 当前段状态
        $segSeq = -1;
        $segFrames = [];          // 已完成（finishFrame）的帧结果，按段内顺序
        $pendingFseq = null;      // 已 startFrame 尚未 finishFrame 的帧
        $pendingKey = false;
        $segFps = 0;

        $configure = function (int $width, int $height) use ($encoder, &$segFps): void {
            $encoder->setResolution($width, $height);
            $bitrate = (int)($this->config['bitrate'] ?? 0);
            if ($bitrate > 0) {
                $encoder->setBitrate($bitrate);
            } else {
                $encoder->setQp((int)($this->config['qp'] ?? 23));
            }
            if ($segFps > 0) $encoder->setFps(max(1, (int)round($segFps)));
        };

        try {
            while (true) {
                $chunk = fread($socket, 65536);
                if ($chunk === false || ($chunk === '' && feof($socket))) {
                    throw new RuntimeException('段编码 worker 协调连接意外关闭');
                }
                if ($chunk === '') continue;
                $input .= $chunk;
                if (strlen($input) > HlsPipelineProtocol::MAX_BUFFER_LENGTH) {
                    throw new RuntimeException('段编码 worker 输入缓冲超限');
                }
                foreach (HlsPipelineProtocol::take($input, PHP_INT_MAX) as $event) {
                    $type = (int)$event['type'];
                    $meta = is_array($event['metadata'] ?? null) ? $event['metadata'] : [];

                    if ($type === HlsPipelineProtocol::END) {
                        if ($segSeq >= 0 || $pendingFseq !== null) {
                            throw new RuntimeException('收到 END 时段编码 worker 仍有未闭合的段');
                        }
                        $this->writeAll($socket, HlsPipelineProtocol::frame(HlsPipelineProtocol::FINISHED, (int)$event['sequence']));
                        return;
                    }

                    if ($type === HlsPipelineProtocol::CONTROL) {
                        $cmd = (string)($meta['cmd'] ?? '');
                        if ($cmd === 'segBegin') {
                            if ($segSeq >= 0) throw new RuntimeException("段编码 worker 段 {$segSeq} 未闭合即开始新段");
                            $segSeq = (int)($meta['seq'] ?? -1);
                            if ($segSeq < 0) throw new RuntimeException('段编码 worker 收到非法段序号');
                            $segFps = (int)round((float)($meta['fps'] ?? 0));
                            $segFrames = [];
                            $pendingFseq = null;
                            $pendingKey = false;
                            continue;
                        }
                        if ($cmd === 'segEnd') {
                            $endSeq = (int)($meta['seq'] ?? -1);
                            if ($endSeq !== $segSeq) {
                                throw new RuntimeException("段编码 worker segEnd 序号不匹配: {$endSeq} != {$segSeq}");
                            }
                            // 双缓冲：收尾最后一帧
                            if ($pendingFseq !== null) {
                                $segFrames[] = ['s' => $pendingFseq, 'k' => $pendingKey, 'n' => $this->encodeNals($encoder->finishFrame())];
                                $pendingFseq = null;
                            }
                            $reply = HlsPipelineProtocol::frame(HlsPipelineProtocol::CONTROL, $endSeq, [
                                'cmd' => 'segDone',
                                'seq' => $endSeq,
                                'frames' => $segFrames,
                            ]);
                            $this->writeAll($socket, $reply);
                            $segSeq = -1;
                            $segFrames = [];
                            continue;
                        }
                        throw new RuntimeException("段编码 worker 收到未知控制命令: {$cmd}");
                    }

                    if ($type !== HlsPipelineProtocol::EVENT) {
                        throw new RuntimeException('段编码 worker 收到非帧事件');
                    }
                    if ($segSeq < 0) throw new RuntimeException('段编码 worker 在 segBegin 之前收到帧');

                    $fseq = (int)($meta['fseq'] ?? -1);
                    $isKey = !empty($meta['key']);
                    $width = (int)($meta['w'] ?? 0);
                    $height = (int)($meta['h'] ?? 0);
                    if ($fseq < 0 || $width < 2 || $height < 2) {
                        throw new RuntimeException("段编码 worker 收到非法帧: fseq={$fseq} {$width}x{$height}");
                    }
                    $yuv = $event['payload'];
                    if ($yuv === '') throw new RuntimeException("段编码 worker 帧 {$fseq} 缺少 YUV 负载");

                    $configure($width, $height);
                    // 关键顺序与 FlvRecoder/Mp4Recoder 一致：新帧先 startFrame（motion worker 在途），
                    // 再 finishFrame 上一帧，串行 CAVLC 与新帧运动估计重叠
                    if ($pendingFseq !== null) {
                        $segFrames[] = ['s' => $pendingFseq, 'k' => $pendingKey, 'n' => $this->encodeNals($encoder->finishFrame())];
                    }
                    $encoder->startFrame($yuv, $isKey);
                    $pendingFseq = $fseq;
                    $pendingKey = $isKey;
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

    /**
     * @param string[] $nals finishFrame 返回的 annex-B NAL 列表（含 SPS/PPS/VCL）
     * @return string[] base64 编码后的 NAL（经 JSON 回传）
     */
    private function encodeNals(array $nals): array
    {
        $out = [];
        foreach ($nals as $nal) {
            $out[] = base64_encode($nal);
        }
        return $out;
    }

    private function writeAll($socket, string $buffer): void
    {
        while ($buffer !== '') {
            $n = @fwrite($socket, $buffer);
            if ($n === false || ($n === 0 && feof($socket))) throw new RuntimeException('段编码 worker 响应写失败');
            if ($n > 0) $buffer = substr($buffer, $n);
        }
    }
}
