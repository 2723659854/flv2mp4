<?php

namespace Xiaosongshu\Flv2mp4\Recode;

use RuntimeException;
use Xiaosongshu\Flv2mp4\Codec\H264Decoder;
use Xiaosongshu\Flv2mp4\Codec\NalUtil;
use Xiaosongshu\Flv2mp4\Codec\Scaler\VideoScaler;

/**
 * @purpose mp4重编码分布式架构-解码
 * @author yanglong
 */
final class Mp4DecoderWorkerServer
{
    private H264Decoder $decoder;
    private VideoScaler $scaler;
    /** @var string 待发回主进程的上行控制帧（波前检查点），每轮事件循环清空 */
    private string $upFrame = '';

    public function __construct(private array $config)
    {
        $this->decoder = new H264Decoder();
        $this->scaler = new VideoScaler();
    }

    public function run(string $listenAddress, string $outputAddress): void
    {
        $server = @stream_socket_server($listenAddress, $errno, $error);
        if ($server === false) throw new RuntimeException("解码进程监听失败: {$error} ({$errno})");
        $downstream = $this->connect($outputAddress);
        $upstream = @stream_socket_accept($server, 15); fclose($server);
        if ($upstream === false) throw new RuntimeException('解码进程等待主进程连接超时');
        stream_set_blocking($upstream, false); stream_set_blocking($downstream, false);
        $input = ''; $output = ''; $response = ''; $upOutput = ''; $ended = false;
        try {
            while (true) {
                $read = [$downstream]; if (!$ended && strlen($input) < HlsPipelineProtocol::HIGH_WATERMARK && strlen($output) < HlsPipelineProtocol::HIGH_WATERMARK) $read[] = $upstream;
                $write = $output === '' ? [] : [$downstream]; if ($upOutput !== '') $write[] = $upstream;
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
                // 单次 select 唤醒（Windows 下粒度约 10~15ms）批量解码全部已缓冲事件，
                // 下游输出积压到高水位时停止，让反压继续向上游传播，避免长文件下缓冲超限
                while (strlen($output) < HlsPipelineProtocol::HIGH_WATERMARK) {
                    $events = HlsPipelineProtocol::take($input, 1);
                    if ($events === []) break;
                    $event = $events[0];
                    if ($event['type'] === HlsPipelineProtocol::CONTROL) {
                        $cmd = $event['metadata']['cmd'] ?? '';
                        if ($cmd === 'checkpoint') {
                            // 波前后段区间起点：注入前段 DPB 后再解后续帧
                            $cp = unserialize($event['payload']);
                            if (!is_array($cp)) throw new RuntimeException('收到无效的解码检查点');
                            $this->decoder->importCheckpoint($cp);
                        }
                    } elseif ($event['type'] === HlsPipelineProtocol::END) { $output .= HlsPipelineProtocol::frame(HlsPipelineProtocol::END, $event['sequence']); $ended = true; }
                    else {
                        $this->upFrame = '';
                        $output .= $this->transform($event);
                        if ($this->upFrame !== '') { $upOutput .= $this->upFrame; $this->upFrame = ''; }
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
                    if ($event['type'] === HlsPipelineProtocol::FINISHED) { $this->writeAll($upstream, HlsPipelineProtocol::frame(HlsPipelineProtocol::FINISHED, $event['sequence'])); return; }
                }
            }
        } catch (\Throwable $e) {
            @stream_set_blocking($upstream, true);
            @fwrite($upstream, HlsPipelineProtocol::frame(HlsPipelineProtocol::ERROR, 0, ['message' => $e->getMessage()]));
            throw $e;
        } finally { if (is_resource($upstream)) @fclose($upstream); if (is_resource($downstream)) @fclose($downstream); }
    }

    /**
     * Task 6 段池模式：解码 worker 只与协调进程通信（无下游输出进程）。
     * transform 结果（含 decoded YUV）全部上行回协调进程；END -> 直接回 FINISHED。
     */
    public function runUpstream(string $listenAddress): void
    {
        $server = @stream_socket_server($listenAddress, $errno, $error);
        if ($server === false) throw new RuntimeException("解码进程监听失败: {$error} ({$errno})");
        $upstream = @stream_socket_accept($server, 15); fclose($server);
        if ($upstream === false) throw new RuntimeException('解码进程等待协调进程连接超时');
        stream_set_blocking($upstream, false);
        $input = ''; $output = '';
        try {
            while (true) {
                $read = []; $write = []; $except = null;
                if (strlen($input) < HlsPipelineProtocol::HIGH_WATERMARK && strlen($output) < HlsPipelineProtocol::HIGH_WATERMARK) $read[] = $upstream;
                if ($output !== '') $write[] = $upstream;
                if ($read === [] && $write === []) {
                    @stream_select($r, $w, $except, 0, 20000);
                } else {
                    @stream_select($read, $write, $except, 0, 20000);
                }
                if (in_array($upstream, $read, true)) {
                    while (true) {
                        $chunk = @fread($upstream, 65536);
                        if ($chunk === false || ($chunk === '' && feof($upstream))) throw new RuntimeException('协调进程媒体连接意外关闭');
                        if ($chunk === '') break;
                        $input .= $chunk;
                        if (strlen($input) > HlsPipelineProtocol::MAX_BUFFER_LENGTH) throw new RuntimeException('解码进程输入缓冲超限');
                        if (strlen($chunk) < 65536) break;
                    }
                }
                while (strlen($output) < HlsPipelineProtocol::HIGH_WATERMARK) {
                    $events = HlsPipelineProtocol::take($input, 1);
                    if ($events === []) break;
                    $event = $events[0];
                    if ($event['type'] === HlsPipelineProtocol::CONTROL) {
                        $cmd = $event['metadata']['cmd'] ?? '';
                        if ($cmd === 'checkpoint') {
                            $cp = unserialize($event['payload']);
                            if (!is_array($cp)) throw new RuntimeException('收到无效的解码检查点');
                            $this->decoder->importCheckpoint($cp);
                        } else {
                            throw new RuntimeException("解码进程收到未知控制命令: {$cmd}");
                        }
                        continue;
                    }
                    if ($event['type'] === HlsPipelineProtocol::END) {
                        stream_set_blocking($upstream, true);
                        $output .= HlsPipelineProtocol::frame(HlsPipelineProtocol::FINISHED, $event['sequence']);
                        while ($output !== '') {
                            $n = @fwrite($upstream, substr($output, 0, 262144));
                            if ($n === false || $n === 0) break;
                            $output = substr($output, $n);
                        }
                        return;
                    }
                    $this->upFrame = '';
                    $output .= $this->transform($event);
                    if ($this->upFrame !== '') { $output .= $this->upFrame; $this->upFrame = ''; }
                    if (strlen($output) > HlsPipelineProtocol::MAX_BUFFER_LENGTH) throw new RuntimeException('解码进程上行缓冲超限');
                }
                if (in_array($upstream, $write, true) && $output !== '') {
                    $n = @fwrite($upstream, substr($output, 0, 262144));
                    if ($n === false || ($n === 0 && feof($upstream))) throw new RuntimeException('协调进程媒体连接写失败');
                    if ($n > 0) $output = substr($output, $n);
                }
            }
        } catch (\Throwable $e) {
            if (is_resource($upstream)) {
                try { $this->writeAll($upstream, HlsPipelineProtocol::frame(HlsPipelineProtocol::ERROR, 0, ['message' => $e->getMessage()])); } catch (\Throwable) {}
            }
            throw $e;
        } finally { if (is_resource($upstream)) @fclose($upstream); }
    }

    private function transform(array $event): string
    {
        if ($event['type'] !== HlsPipelineProtocol::EVENT) throw new RuntimeException('解码进程收到未知事件');
        $meta = $event['metadata']; $payload = $event['payload'];
        // 波前边界标记：本帧解码后回传检查点，不应透传到下游
        $cpAfter = isset($meta['cpAfter']) ? (int)$meta['cpAfter'] : null;
        unset($meta['cpAfter']);
        if (($meta['sampleType'] ?? '') !== 'video') return HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $event['sequence'], $meta, $payload);
        $pipeline = $this->config['pipeline'];
        $needTranscode = ((int)($this->config['width'] ?? 0) > 0 && (int)$pipeline['srcWidth'] !== (int)$pipeline['outputWidth'])
            || ((int)($this->config['height'] ?? 0) > 0 && (int)$pipeline['srcHeight'] !== (int)$pipeline['outputHeight'])
            || (int)($this->config['bitrate'] ?? 0) > 0 || !empty($pipeline['dropFrames']) || !empty($this->config['watermark']);
        if (!$needTranscode) return HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $event['sequence'], $meta, $payload);
        $nals = $this->extractNals($payload);
        $sps = base64_decode($pipeline['srcSps'], true) ?: '';
        $pps = base64_decode($pipeline['srcPps'], true) ?: '';
        if ($sps !== '') array_unshift($nals, ['type' => 7, 'data' => $sps]);
        if ($pps !== '') array_unshift($nals, ['type' => 8, 'data' => $pps]);
        $dropFrame = !empty($meta['drop']);
        // 被丢弃的帧仍需完整解码以维持本GOP参考链，但它的YUV不会进入后续流水线，跳过裁剪输出
        $frame = $this->decoder->decode($nals, false, !$dropFrame);
        // 波前：边界帧（含被抽帧丢弃帧——已完整解码入 DPB）导出检查点回传主进程
        if ($cpAfter !== null && $frame) {
            $cp = $this->decoder->exportCheckpoint($sps !== '' ? $sps : null, $pps !== '' ? $pps : null);
            $this->upFrame = HlsPipelineProtocol::frame(
                HlsPipelineProtocol::CONTROL, 0, ['cmd' => 'checkpoint', 'range' => $cpAfter], serialize($cp)
            );
        }
        // 抽帧决策由主进程统一下发
        if ($dropFrame) return HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $event['sequence'], $meta, $payload);
        if (!$frame || empty($frame['data'])) { unset($meta['drop']); return HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $event['sequence'], $meta, $payload); }
        $srcW = (int)$pipeline['srcWidth']; $srcH = (int)$pipeline['srcHeight'];
        $outW = (int)$pipeline['outputWidth']; $outH = (int)$pipeline['outputHeight'];
        $yuv = ($srcW === $outW && $srcH === $outH) ? $frame['data'] : $this->scaler->scaleYUV420P($frame['data'], $srcW, $srcH, $outW, $outH);
        if (!empty($this->config['watermark']) && !empty($this->config['watermark_file'])) $yuv = $this->applyWatermark($yuv, $outW, $outH, $this->config['watermark_file']);
        $meta['decoded'] = true;
        $meta['variants'] = ['default' => ['offset' => 0, 'length' => strlen($yuv), 'width' => $outW, 'height' => $outH]];
        return HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $event['sequence'], $meta, pack('N', strlen($payload)) . $payload . $yuv);
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
        $deadline = microtime(true) + 15; do { $socket = @stream_socket_client($address, $errno, $error, 0.2); if ($socket !== false) return $socket; usleep(50000); } while (microtime(true) < $deadline);
        throw new RuntimeException("无法连接输出进程: {$error} ({$errno})");
    }

    private function writeAll($socket, string $data): void
    {
        stream_set_blocking($socket, true); $offset = 0; while ($offset < strlen($data)) { $n = fwrite($socket, substr($data, $offset)); if ($n === false || $n === 0) throw new RuntimeException('无法发送完成响应'); $offset += $n; }
    }
}
