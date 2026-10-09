<?php

namespace Xiaosongshu\Flv2mp4\Recode;

use RuntimeException;
use Throwable;
use Xiaosongshu\Flv2mp4\Codec\H264Decoder;
use Xiaosongshu\Flv2mp4\Codec\H264Encoder;
use Xiaosongshu\Flv2mp4\Codec\NalUtil;
use Xiaosongshu\Flv2mp4\Codec\Scaler\VideoScaler;

/**
 * @purpose flv重编码分布式架构-GOP worker（解码+缩放+编码，每个worker携带1组运动估计子进程）
 * @author yanglong
 */
final class FlvDecoderWorkerServer
{
    private H264Decoder $decoder;
    private VideoScaler $scaler;
    private H264Encoder $encoder;
    private string $sps = '';
    private string $pps = '';
    private int $width = 0;
    private int $height = 0;
    /**
     * 帧级双缓冲中已 startFrame、等待下一帧到来后 finish 的在途编码帧。
     * 运动子进程计算 N+1 与主进程 CAVLC 编码 N 重叠（与中央编码器同一条流水线），
     * 缺少这层重叠时每帧都要"派运动→等结果→CAVLC"串行，GOP 越少损失越明显。
     */
    private ?array $pendingFrame = null;
    /**
     * 在途视频帧（pendingFrame）之后到达、但必须等它先发出的直通帧（音频/丢帧/解码失败回退）。
     * 输出进程的反压闸门依赖"每路连接序号严格递增"，不能让后续帧在同路连接上反超在途帧。
     */
    private string $deferredOutput = '';
    /**
     * fastMotion 快速路径：与直播流水线同款——
     * 启动/GOP边界向主进程回报 READY，主进程只向 READY worker 投递完整 GOP；
     * 输入按 1MB + 已闭合GOP数门控（频闪小帧压缩后字节小但解码昂贵）。
     * 关闭时保持旧路径行为（48MB 字节门控、无 READY 回报）。
     */
    private bool $fast = false;

    public function __construct(private array $config)
    {
        $this->decoder = new H264Decoder();
        $this->scaler = new VideoScaler();
        // 编码在 GOP worker 内完成：每个 worker 独立编码器 + 独立运动估计子进程（GOP边界只重置状态不重建）
        $this->encoder = new H264Encoder();
        $this->encoder->motionWorkers = max(1, (int)($config['motionWorkers'] ?? 8));
        if (!empty($config['fastMotion'])) {
            $this->encoder->setFastMotion(true);
            $this->fast = true;
        }
    }

    public function run(string $listenAddress, string $outputAddress): void
    {
        $server = @stream_socket_server($listenAddress, $errno, $error);
        if ($server === false) throw new RuntimeException("解码进程监听失败: {$error} ({$errno})");
        $downstream = $this->connect($outputAddress);
        $upstream = @stream_socket_accept($server, 15); fclose($server);
        if ($upstream === false) throw new RuntimeException('解码进程等待主进程连接超时');
        stream_set_blocking($upstream, false); stream_set_blocking($downstream, false);
        // 立即拉起运动估计子进程，让冷启动与主进程派发其它 worker/读取文件并行（与中央编码器同策略）
        $this->encoder->warmupMotionWorkers();
        $input = ''; $output = ''; $response = ''; $upOutput = ''; $ended = false;
        // 快速路径：输入中"已闭合但尚未处理完"的GOP数（口径同 HlsDecoderWorkerServer）
        $queuedGops = 0;
        if ($this->fast) {
            // 启动即报告空闲，主进程只向 READY worker 投递完整 GOP
            $upOutput .= HlsPipelineProtocol::frame(HlsPipelineProtocol::READY, 0, ['worker' => true]);
        }
        try {
            while (true) {
                $read = [$downstream];
                if (!$ended) {
                    if ($this->fast) {
                        // 1MB + GOP 双门控：把每 worker 在途算力限制为当前GOP+1个完整GOP
                        if (strlen($input) < HlsPipelineProtocol::INPUT_HIGH_WATERMARK
                            && $queuedGops < HlsPipelineProtocol::INPUT_MAX_QUEUED_GOPS) $read[] = $upstream;
                    } elseif (strlen($input) < HlsPipelineProtocol::HIGH_WATERMARK && strlen($output) < HlsPipelineProtocol::HIGH_WATERMARK) {
                        $read[] = $upstream;
                    }
                }
                $write = $output === '' ? [] : [$downstream];
                if ($upOutput !== '') $write[] = $upstream;
                $except = null; @stream_select($read, $write, $except, 0, 2000);
                if (in_array($upstream, $read, true)) {
                    while (true) {
                        $chunk = @fread($upstream, 65536);
                        if ($chunk === false || ($chunk === '' && feof($upstream))) throw new RuntimeException('主进程媒体连接意外关闭');
                        if ($chunk === '') break;
                        $input .= $chunk; if (strlen($input) > HlsPipelineProtocol::MAX_BUFFER_LENGTH) throw new RuntimeException('解码进程输入缓冲超限');
                        if (strlen($chunk) < 65536) break;
                    }
                }
                if ($this->fast) {
                    // 长度前缀快扫统计缓冲内未处理 gopEnd（不解析/不搬运媒体负载）
                    $off = 0; $scanTotal = strlen($input); $bufferedGopEnds = 0;
                    while ($off + 4 <= $scanTotal) {
                        $frameLen = (int)unpack('N', substr($input, $off, 4))[1];
                        if ($frameLen < 9 || $frameLen > HlsPipelineProtocol::MAX_FRAME_LENGTH) break;
                        if ($off + 4 + $frameLen > $scanTotal) break;
                        if (ord($input[$off + 4]) === HlsPipelineProtocol::CONTROL) {
                            $metaLen = (int)unpack('N', substr($input, $off + 9, 4))[1];
                            if ($metaLen <= $frameLen - 9) {
                                $meta = json_decode(substr($input, $off + 13, $metaLen), true);
                                if (is_array($meta) && ($meta['cmd'] ?? '') === 'gopEnd') $bufferedGopEnds++;
                            }
                        }
                        $off += 4 + $frameLen;
                    }
                    $queuedGops = $bufferedGopEnds;
                }
                // 单次 select 唤醒（Windows 下粒度约 10~15ms）批量解码全部已缓冲事件，
                // 下游输出积压到高水位时停止，让反压继续向上游传播，避免长文件下缓冲超限
                while (strlen($output) < HlsPipelineProtocol::HIGH_WATERMARK) {
                    $events = HlsPipelineProtocol::take($input, 1);
                    if ($events === []) break;
                    $event = $events[0];
                    if ($event['type'] === HlsPipelineProtocol::CONTROL) {
                        $cmd = $event['metadata']['cmd'] ?? '';
                        if ($cmd === 'config') $this->parseConfiguration(substr($event['payload'], 5));
                        elseif ($cmd === 'gopEnd') {
                            // 先冲刷在途帧再重置：保证 GOP 最后一帧正常输出且不跨 GOP 残留流水线状态
                            $gop = (int)($event['metadata']['gop'] ?? -1);
                            $output .= $this->flushEncodedFrame();
                            $this->resetGopState();
                            if ($this->fast) {
                                // 快速路径：回报该 GOP 完成并重新声明空闲，主进程据此派发下一个完整 GOP
                                $upOutput .= HlsPipelineProtocol::frame(HlsPipelineProtocol::PROGRESS, 0, ['gop' => $gop]);
                                $upOutput .= HlsPipelineProtocol::frame(HlsPipelineProtocol::READY, 0, ['gop' => $gop]);
                            }
                        }
                    } elseif ($event['type'] === HlsPipelineProtocol::END) {
                        // 末帧在途：收尾后再转发 END，输出进程必然先收齐全部媒体再看到 END
                        $output .= $this->flushEncodedFrame();
                        $output .= HlsPipelineProtocol::frame(HlsPipelineProtocol::END, $event['sequence']);
                        $ended = true;
                    } else {
                        $output .= $this->orderFrame($this->transform($event));
                    }
                    if (strlen($output) > HlsPipelineProtocol::MAX_BUFFER_LENGTH) throw new RuntimeException('解码进程下游缓冲超限');
                }
                if (in_array($downstream, $write, true)) {
                    while ($output !== '') {
                        $n = @fwrite($downstream, substr($output, 0, 262144));
                        if ($n === false || ($n === 0 && feof($downstream))) throw new RuntimeException('输出进程媒体连接意外关闭');
                        if ($n === 0) break;
                        $output = substr($output, $n);
                        if ($n < 262144) break;
                    }
                }
                // 快速路径：READY/PROGRESS 经媒体上行连接回报主进程（FINISHED 亦在此通道，顺序天然一致）
                if (in_array($upstream, $write, true) && $upOutput !== '') {
                    while ($upOutput !== '') {
                        $n = @fwrite($upstream, substr($upOutput, 0, 262144));
                        if ($n === false || ($n === 0 && feof($upstream))) break;
                        if ($n === 0) break;
                        $upOutput = substr($upOutput, $n);
                        if ($n < 262144) break;
                    }
                }
                if (in_array($downstream, $read, true)) {
                    $chunk = @fread($downstream, 65536);
                    if ($chunk === false || ($chunk === '' && feof($downstream))) throw new RuntimeException('输出进程响应连接意外关闭');
                    $response .= $chunk;
                }
                foreach (HlsPipelineProtocol::take($response, 4) as $event) {
                    if ($event['type'] === HlsPipelineProtocol::ERROR) throw new RuntimeException($event['metadata']['message'] ?? '输出进程失败');
                    if ($event['type'] === HlsPipelineProtocol::FINISHED) {
                        // 必须排在尚未发完的 READY/PROGRESS 之后，避免同一连接上帧序颠倒
                        if ($this->fast && $upOutput !== '') {
                            $this->writeAll($upstream, $upOutput);
                            $upOutput = '';
                        }
                        $this->writeAll($upstream, HlsPipelineProtocol::frame(HlsPipelineProtocol::FINISHED, $event['sequence']));
                        return;
                    }
                }
            }
        } catch (Throwable $e) {
            if (is_resource($upstream)) {
                try { $this->writeAll($upstream, HlsPipelineProtocol::frame(HlsPipelineProtocol::ERROR, 0, ['message' => $e->getMessage()])); } catch (Throwable) {}
            }
            throw $e;
        } finally { if (is_resource($upstream)) @fclose($upstream); if (is_resource($downstream)) @fclose($downstream); }
    }

    private function transform(array $event): string
    {
        if ($event['type'] !== HlsPipelineProtocol::EVENT) throw new RuntimeException('解码进程收到未知事件');
        $meta = $event['metadata']; $body = $event['payload'];
        if (($meta['tagType'] ?? 0) !== 9 || strlen($body) < 5 || (ord($body[0]) & 0x0f) !== 7) return HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $event['sequence'], $meta, $body);
        $packetType = ord($body[1]);
        if ($packetType === 0) { $this->parseConfiguration(substr($body, 5)); return HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $event['sequence'], $meta, $body); }
        if ($packetType !== 1 || $this->width === 0) return HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $event['sequence'], $meta, $body);
        $nals = $this->extractNals(substr($body, 5));
        if ($this->sps !== '') array_unshift($nals, ['type' => 7, 'data' => $this->sps]);
        if ($this->pps !== '') array_unshift($nals, ['type' => 8, 'data' => $this->pps]);
        $dropFrame = !empty($meta['drop']);
        $isKeyFrame = (ord($body[0]) >> 4) === 1;
        $sourceFps = isset($meta['sourceFps']) ? (float)$meta['sourceFps'] : (float)($this->config['source_fps'] ?? 0);
        if ($sourceFps <= 0.0) $sourceFps = 0.0;
        $targetFps = (int)($this->config['fps'] ?? 0);
        $dropFrames = $targetFps > 0 && $sourceFps > 0.0 && $targetFps < $sourceFps - 0.01;
        $w = ($this->config['width'] ?? 0) > 0 ? (int)$this->config['width'] : $this->width;
        $h = ($this->config['height'] ?? 0) > 0 ? (int)$this->config['height'] : $this->height;
        $needTranscode = ($w !== $this->width || $h !== $this->height)
            || (int)($this->config['bitrate'] ?? 0) > 0 || $dropFrames
            || (!empty($this->config['watermark']) && !empty($this->config['watermark_file']));
        // 无需转码（纯直通）时原封不动转发，输出侧同样判定为直通
        if (!$needTranscode) return HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $event['sequence'], $meta, $body);
        // 抽帧丢弃的帧仍需解码以维持 GOP 内 P 链参考，否则后续保留帧会参考错误画面出现马赛克
        // （与 HlsDecoderWorkerServer 同策略）：不生成 YUV、跳过去块滤波，省去大部分开销；
        // IDR 在解码器内部仍强制执行去块。原 tag 回传占用序号，输出侧按 meta.drop 丢弃。
        if ($dropFrame) {
            $this->decoder->decode($nals, false, false, true);
            return HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $event['sequence'], $meta, $body);
        }
        $frame = $this->decoder->decode($nals, false, true, false);
        if (!$frame || empty($frame['data'])) { unset($meta['drop']); return HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $event['sequence'], $meta, $body); }
        $yuv = ($w === $this->width && $h === $this->height) ? $frame['data'] : $this->scaler->scaleYUV420P($frame['data'], $this->width, $this->height, $w, $h);
        if (!empty($this->config['watermark']) && !empty($this->config['watermark_file'])) $yuv = $this->applyWatermark($yuv, $w, $h, $this->config['watermark_file']);
        // GOP worker 内直接完成编码（运动估计走本 worker 的子进程），输出侧只做封装
        $this->encoder->setResolution($w, $h);
        if ((int)($this->config['bitrate'] ?? 0) > 0) $this->encoder->setBitrate((int)$this->config['bitrate']);
        else $this->encoder->setQp((int)($this->config['qp'] ?? 26));
        $encoderFps = $dropFrames ? (float)$targetFps : ($sourceFps > 0.0 ? $sourceFps : null);
        if ($encoderFps !== null && $encoderFps > 0) $this->encoder->setFps(max(1, (int)round($encoderFps)));
        return $this->feedEncoder($event, $meta, $body, $yuv, $w, $h, $isKeyFrame);
    }

    /**
     * 维持本路连接序号严格递增：在途帧未 finish 期间，后续直通帧（序号更大）先缓存，
     * 待在途帧产出时按"在途帧 + 缓存帧"顺序一并吐出。
     */
    private function orderFrame(string $frame): string
    {
        if ($frame === '') return '';
        if ($this->pendingFrame === null) return $frame;
        $this->deferredOutput .= $frame;
        return '';
    }

    /**
     * 帧级双缓冲喂帧：首帧只 startFrame（异步派运动估计）不产出；
     * 后续帧先 startFrame(N+1) 再 finishFrame() 取 N 的 NAL，
     * 主进程 CAVLC(N) 与运动子进程计算(N+1) 在时间上重叠。
     * 返回上一帧（已 finish）的协议帧（连同被延后的直通帧）；首帧返回空串。
     */
    private function feedEncoder(array $event, array $meta, string $body, string $yuv, int $w, int $h, bool $isKeyFrame): string
    {
        $this->encoder->startFrame($yuv, $isKeyFrame);
        if ($this->pendingFrame === null) {
            $this->pendingFrame = ['sequence' => $event['sequence'], 'meta' => $meta, 'body' => $body, 'w' => $w, 'h' => $h, 'isKey' => $isKeyFrame];
            return '';
        }
        $frame = $this->buildPendingFrame($this->encoder->finishFrame());
        $this->pendingFrame = ['sequence' => $event['sequence'], 'meta' => $meta, 'body' => $body, 'w' => $w, 'h' => $h, 'isKey' => $isKeyFrame];
        if ($this->deferredOutput !== '') { $frame .= $this->deferredOutput; $this->deferredOutput = ''; }
        return $frame;
    }

    /** 冲刷在途的最后一帧（GOP 边界或 END），并带出其后缓存的直通帧。 */
    private function flushEncodedFrame(): string
    {
        if ($this->pendingFrame === null) return '';
        $frame = $this->buildPendingFrame($this->encoder->finishFrame());
        $this->pendingFrame = null;
        if ($this->deferredOutput !== '') { $frame .= $this->deferredOutput; $this->deferredOutput = ''; }
        return $frame;
    }

    private function buildPendingFrame(array $encodedNals): string
    {
        $p = $this->pendingFrame;
        $annexb = '';
        foreach ($encodedNals as $nal) $annexb .= $nal;
        $meta = $p['meta'];
        $meta['encoded'] = true;
        $meta['keyFrame'] = $p['isKey'];
        $meta['encodedVariant'] = ['offset' => 4 + strlen($p['body']), 'length' => strlen($annexb), 'width' => $p['w'], 'height' => $p['h']];
        return HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $p['sequence'], $meta, pack('N', strlen($p['body'])) . $p['body'] . $annexb);
    }

    private function resetGopState(): void
    {
        // GOP 边界：解码器重建（参考链/帧计数耦合较多），编码器轻量重置并复用已连接的运动估计子进程
        $this->pendingFrame = null;
        $this->deferredOutput = '';
        $this->decoder = new H264Decoder();
        if ($this->sps !== '') {
            $this->decoder->decode([['type' => 7, 'data' => $this->sps]], true);
            $this->width = $this->decoder->getWidth();
            $this->height = $this->decoder->getHeight();
        }
        $this->encoder->resetGopState();
    }

    private function parseConfiguration(string $data): void
    {
        if (strlen($data) < 7) return; $offset = 5; $count = ord($data[$offset++]) & 0x1f;
        for ($i = 0; $i < $count; $i++) { $length = unpack('n', substr($data, $offset, 2))[1]; $offset += 2; $raw = substr($data, $offset, $length); $offset += $length; $this->sps = substr(NalUtil::removeEmulationPrevention($raw), 1); $this->decoder->decode([['type' => 7, 'data' => $this->sps]], true); $this->width = $this->decoder->getWidth(); $this->height = $this->decoder->getHeight(); }
        if ($offset >= strlen($data)) return; $count = ord($data[$offset++]);
        for ($i = 0; $i < $count; $i++) { $length = unpack('n', substr($data, $offset, 2))[1]; $offset += 2; $raw = substr($data, $offset, $length); $offset += $length; $this->pps = substr(NalUtil::removeEmulationPrevention($raw), 1); }
    }

    private function extractNals(string $data): array
    {
        $result = []; $offset = 0; $total = strlen($data);
        while ($offset + 4 <= $total) { $length = unpack('N', substr($data, $offset, 4))[1]; $offset += 4; if ($offset + $length > $total) break; $clean = NalUtil::removeEmulationPrevention(substr($data, $offset, $length)); $offset += $length; $result[] = ['type' => ord($clean[0]) & 0x1f, 'data' => substr($clean, 1), 'raw' => $clean]; }
        return $result;
    }

    private function applyWatermark(string $yuv, int $w, int $h, string $file): string
    {
        $data = file_get_contents($file); if ($data === false) throw new RuntimeException("无法读取水印文件: {$file}");
        if (!preg_match('/_(\d+)x(\d+)$/', basename($file, '.yuv'), $m)) { $ww = 80; $wh = 16; } else { $ww = (int)$m[1]; $wh = (int)$m[2]; }
        if ($ww > $w || $wh > $h || strlen($data) < $ww * $wh * 3 / 2) throw new RuntimeException('水印文件尺寸不匹配');
        $ySize = $w * $h; $uvSize = ($w >> 1) * ($h >> 1); $wySize = $ww * $wh; $wuvSize = $wySize >> 2;
        for ($row = 0; $row < $wh; $row++) for ($col = 0; $col < $ww; $col++) $yuv[$row * $w + $col] = $data[$row * $ww + $col];
        for ($row = 0; $row < ($wh >> 1); $row++) for ($col = 0; $col < ($ww >> 1); $col++) { $dst = $row * ($w >> 1) + $col; $src = $row * ($ww >> 1) + $col; $yuv[$ySize + $dst] = $data[$wySize + $src]; $yuv[$ySize + $uvSize + $dst] = $data[$wySize + $wuvSize + $src]; }
        return $yuv;
    }

    private function connect(string $address)
    {
        $deadline = microtime(true) + 15; do { $socket = @stream_socket_client($address, $errno, $error, 0.2); if ($socket !== false) return $socket; usleep(1); } while (microtime(true) < $deadline);
        throw new RuntimeException("无法连接输出进程: {$error} ({$errno})");
    }

    private function writeAll($socket, string $data): void
    {
        stream_set_blocking($socket, true); $offset = 0; while ($offset < strlen($data)) { $n = fwrite($socket, substr($data, $offset)); if ($n === false || $n === 0) throw new RuntimeException('无法发送完成响应'); $offset += $n; }
    }
}
