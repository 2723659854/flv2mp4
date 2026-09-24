<?php

namespace Xiaosongshu\Flv2mp4\Recode;

use RuntimeException;
use Throwable;
use Xiaosongshu\Flv2mp4\Codec\H264Decoder;
use Xiaosongshu\Flv2mp4\Codec\NalUtil;
use Xiaosongshu\Flv2mp4\Codec\Scaler\VideoScaler;

/**
 * @purpose flv转hls分布式架构-解码服务端
 * @author yanglong
 */
final class HlsDecoderWorkerServer
{
    private H264Decoder $decoder;
    private ?VideoScaler $scaler;
    private string $sps = '';
    private string $pps = '';
    private int $width = 0;
    private int $height = 0;

    public function __construct(private array $profiles)
    {
        $this->decoder = new H264Decoder();
        // 单 profile：缩放/水印在解码进程完成（多解码进程并行，避免输出进程串行缩放成为瓶颈）；
        // 多 profile：输出原始分辨率 YUV，由输出进程按各 profile 分别缩放
        $this->scaler = count($profiles) === 1 ? new VideoScaler() : null;
    }

    public function run(string $listenAddress, string $outputAddress, string $controlAddress = ''): void
    {
        $server = @stream_socket_server($listenAddress, $errno, $error);
        if ($server === false) throw new RuntimeException("解码进程监听失败: {$error} ({$errno})");
        // 独立控制连接：finish 屏障走此通道，不被48MB媒体在途缓冲挡在后面
        $ctrlServer = null;
        if ($controlAddress !== '') {
            $ctrlServer = @stream_socket_server($controlAddress, $errno, $error);
            if ($ctrlServer === false) throw new RuntimeException("解码进程控制端口监听失败: {$error} ({$errno})");
            stream_set_blocking($ctrlServer, false);
        }
        $downstream = $this->connect($outputAddress);
        $upstream = @stream_socket_accept($server, 15);
        fclose($server);
        if ($upstream === false) throw new RuntimeException('解码进程等待主进程连接超时');
        stream_set_blocking($upstream, false);
        stream_set_blocking($downstream, false);
        $input = '';
        $output = '';
        $upOutput = '';
        $downstreamInput = '';
        $ctrlConn = null;
        $ctrlInput = '';
        $ended = false;
        $finishing = false;
        $triggerFinish = static function () use (&$input, &$finishing): void {
            if ($finishing) return;
            // 快速收尾：丢弃所有尚未解码的在途帧并停止解码（直播尾部无观看价值）。
            // 不向下游媒体流插入任何字节（会切断已部分发出的大帧）；输出进程由主进程经
            // 独立控制连接直接通知收尾
            $input = '';
            $finishing = true;
        };
        try {
            while (true) {
                $read = [$downstream];
                if (!$ended && !$finishing && strlen($input) < HlsPipelineProtocol::HIGH_WATERMARK) $read[] = $upstream;
                if ($ctrlServer !== null) $read[] = $ctrlServer;
                if ($ctrlConn !== null) $read[] = $ctrlConn;
                $write = $output === '' ? [] : [$downstream];
                if ($upOutput !== '') $write[] = $upstream;
                $except = null;
                @stream_select($read, $write, $except, 0, 2000);
                if ($ctrlServer !== null && in_array($ctrlServer, $read, true)) {
                    $conn = @stream_socket_accept($ctrlServer, 0);
                    if ($conn !== false) {
                        stream_set_blocking($conn, false);
                        $ctrlConn = $conn;
                        fclose($ctrlServer);
                        $ctrlServer = null;
                    }
                }
                if ($ctrlConn !== null && in_array($ctrlConn, $read, true)) {
                    $chunk = @fread($ctrlConn, 65536);
                    if ($chunk === false || ($chunk === '' && feof($ctrlConn))) {
                        $ctrlConn = null;
                    } else {
                        $ctrlInput .= $chunk;
                        foreach (HlsPipelineProtocol::take($ctrlInput, PHP_INT_MAX) as $ctrlEvent) {
                            if ($ctrlEvent['type'] === HlsPipelineProtocol::CONTROL && ($ctrlEvent['metadata']['cmd'] ?? '') === 'finish') {
                                $triggerFinish();
                            }
                        }
                    }
                }
                if (in_array($upstream, $read, true)) {
                    while (true) {
                        $chunk = @fread($upstream, 65536);
                        if ($chunk === false || ($chunk === '' && feof($upstream))) throw new RuntimeException('主进程媒体连接意外关闭');
                        if ($chunk === '') break;
                        $input .= $chunk;
                        if (strlen($input) > HlsPipelineProtocol::MAX_BUFFER_LENGTH) throw new RuntimeException('解码进程输入缓冲超限');
                        if (strlen($chunk) < 65536) break;
                    }
                }
                // 兼容兜底：finish 若从媒体通道到达（无控制连接的旧调用方），长度前缀快扫定位，
                // 不解析/不搬运媒体负载
                if (!$finishing) {
                    $off = 0;
                    $scanTotal = strlen($input);
                    $foundAt = -1;
                    while ($off + 4 <= $scanTotal) {
                        $frameLen = (int)unpack('N', substr($input, $off, 4))[1];
                        if ($frameLen < 9 || $frameLen > HlsPipelineProtocol::MAX_FRAME_LENGTH) break;
                        if ($off + 4 + $frameLen > $scanTotal) break;
                        if (ord($input[$off + 4]) === HlsPipelineProtocol::CONTROL) {
                            $metaLen = (int)unpack('N', substr($input, $off + 9, 4))[1];
                            if ($metaLen <= $frameLen - 9) {
                                $meta = json_decode(substr($input, $off + 13, $metaLen), true);
                                if (is_array($meta) && ($meta['cmd'] ?? '') === 'finish') {
                                    $foundAt = $off;
                                    break;
                                }
                            }
                        }
                        $off += 4 + $frameLen;
                    }
                    if ($foundAt >= 0) $triggerFinish();
                }
                // 单次 select 唤醒（Windows 下粒度约 10~15ms）批量解码全部已缓冲事件，
                // 下游输出积压到高水位时停止，让反压继续向下游传播，避免长文件下缓冲超限。
                // 收到 finish 后停止解码在途帧（快速收尾：尾部帧由输出进程直接丢弃）。
                while (!$finishing && strlen($output) < HlsPipelineProtocol::HIGH_WATERMARK) {
                    $events = HlsPipelineProtocol::take($input, 1);
                    if ($events === []) break;
                    $event = $events[0];
                    if ($event['type'] === HlsPipelineProtocol::CONTROL) {
                        $cmd = $event['metadata']['cmd'] ?? '';
                        if ($cmd === 'config') $this->parseConfiguration(substr($event['payload'], 5));
                        elseif ($cmd === 'gopEnd') {
                            // 处理到此处时，该 GOP 之前的所有帧均已解码并转发
                            $upOutput .= HlsPipelineProtocol::frame(HlsPipelineProtocol::PROGRESS, 0, ['gop' => (int)($event['metadata']['gop'] ?? -1)]);
                        }
                    } elseif ($event['type'] === HlsPipelineProtocol::END) {
                        $output .= HlsPipelineProtocol::frame(HlsPipelineProtocol::END, $event['sequence']);
                        $ended = true;
                    } else {
                        $output .= $this->transform($event);
                    }
                    if (strlen($output) > HlsPipelineProtocol::MAX_BUFFER_LENGTH) throw new RuntimeException('解码进程下游缓冲超限');
                }
                if (in_array($downstream, $write, true)) {
                    while ($output !== '') {
                        $written = @fwrite($downstream, substr($output, 0, 262144));
                        if ($written === false || ($written === 0 && feof($downstream))) throw new RuntimeException('编码进程媒体连接意外关闭');
                        if ($written === 0) break;
                        $output = substr($output, $written);
                        if ($written < 262144) break;
                    }
                }
                if (in_array($upstream, $write, true) && $upOutput !== '') {
                    while ($upOutput !== '') {
                        $written = @fwrite($upstream, substr($upOutput, 0, 262144));
                        if ($written === false || ($written === 0 && feof($upstream))) break;
                        if ($written === 0) break;
                        $upOutput = substr($upOutput, $written);
                        if ($written < 262144) break;
                    }
                }
                if (in_array($downstream, $read, true)) {
                    $chunk = @fread($downstream, 65536);
                    if ($chunk === false || ($chunk === '' && feof($downstream))) throw new RuntimeException('编码进程响应连接意外关闭');
                    $downstreamInput .= $chunk;
                }
                foreach (HlsPipelineProtocol::take($downstreamInput, 4) as $response) {
                    if ($response['type'] === HlsPipelineProtocol::ERROR) throw new RuntimeException($response['metadata']['message'] ?? '编码进程失败');
                    if ($response['type'] === HlsPipelineProtocol::FINISHED) {
                        $this->writeAll($upstream, HlsPipelineProtocol::frame(HlsPipelineProtocol::FINISHED, $response['sequence']));
                        return;
                    }
                }
            }
        } catch (Throwable $e) {
            if (is_resource($upstream)) {
                try { $this->writeAll($upstream, HlsPipelineProtocol::frame(HlsPipelineProtocol::ERROR, 0, ['message' => $e->getMessage()])); } catch (Throwable) {}
            }
            throw $e;
        } finally {
            if (is_resource($upstream)) @fclose($upstream);
            if (is_resource($downstream)) @fclose($downstream);
            if (is_resource($ctrlConn)) @fclose($ctrlConn);
            if (is_resource($ctrlServer)) @fclose($ctrlServer);
        }
    }

    private function transform(array $event): string
    {
        if ($event['type'] !== HlsPipelineProtocol::EVENT) throw new RuntimeException('解码进程收到未知事件');
        $meta = $event['metadata'];
        if (($meta['tagType'] ?? 0) !== 9) return HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $event['sequence'], $meta, $event['payload']);
        $body = $event['payload'];
        if (strlen($body) < 5 || (ord($body[0]) & 0x0f) !== 7) return HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $event['sequence'], $meta, $body);
        $packetType = ord($body[1]);
        if ($packetType === 0) {
            $this->parseConfiguration(substr($body, 5));
            $meta['sourceWidth'] = $this->width;
            $meta['sourceHeight'] = $this->height;
            return HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $event['sequence'], $meta, $body);
        }
        if ($packetType !== 1 || $this->width === 0) return HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $event['sequence'], $meta, $body);
        $nals = $this->extractNals(substr($body, 5));
        if ($this->sps !== '') array_unshift($nals, ['type' => 7, 'data' => $this->sps]);
        if ($this->pps !== '') array_unshift($nals, ['type' => 8, 'data' => $this->pps]);
        $dropFrame = !empty($meta['drop']);
        // 抽帧丢弃的帧仅维持P链参考：仍完整解码并执行去块滤波，保证后续保留帧的参考质量
        $frame = $this->decoder->decode($nals, false, true, false);
        // 抽帧丢弃：解码已完成（维持 GOP 内后续帧的参考链），但不缩放/不附 YUV，
        // meta.drop 原样透传，输出端直接跳过编码
        if ($dropFrame) {
            return HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $event['sequence'], $meta, $body);
        }
        if (!$frame || empty($frame['data'])) return HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $event['sequence'], $meta, $body);
        $meta['decoded'] = true;
        $meta['sourceWidth'] = $this->width;
        $meta['sourceHeight'] = $this->height;
        $yuv = $frame['data'];
        if ($this->scaler !== null) {
            $name = array_key_first($this->profiles);
            $profile = $this->profiles[$name];
            $width = ($profile['width'] ?? 0) > 0 ? (int)$profile['width'] : $this->width;
            $height = ($profile['height'] ?? 0) > 0 ? (int)$profile['height'] : $this->height;
            if ($width !== $this->width || $height !== $this->height) {
                $yuv = $this->scaler->scaleYUV420P($yuv, $this->width, $this->height, $width, $height);
            }
            if (!empty($profile['watermark']) && !empty($profile['watermark_file'])) $yuv = $this->applyWatermark($yuv, $width, $height, $profile['watermark_file']);
            $meta['variants'] = [$name => ['offset' => 0, 'length' => strlen($yuv), 'width' => $width, 'height' => $height]];
        }
        $payload = pack('N', strlen($body)) . $body . $yuv;
        return HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $event['sequence'], $meta, $payload);
    }

    private function parseConfiguration(string $data): void
    {
        if (strlen($data) < 7) return;
        $offset = 5;
        $count = ord($data[$offset++]) & 0x1f;
        for ($i = 0; $i < $count; $i++) {
            $length = unpack('n', substr($data, $offset, 2))[1]; $offset += 2;
            $raw = substr($data, $offset, $length); $offset += $length;
            $this->sps = substr(NalUtil::removeEmulationPrevention($raw), 1);
            $this->decoder->decode([['type' => 7, 'data' => $this->sps]], true);
            $this->width = $this->decoder->getWidth(); $this->height = $this->decoder->getHeight();
        }
        if ($offset >= strlen($data)) return;
        $count = ord($data[$offset++]);
        for ($i = 0; $i < $count; $i++) {
            $length = unpack('n', substr($data, $offset, 2))[1]; $offset += 2;
            $raw = substr($data, $offset, $length); $offset += $length;
            $this->pps = substr(NalUtil::removeEmulationPrevention($raw), 1);
        }
    }

    private function extractNals(string $data): array
    {
        $result = []; $offset = 0; $total = strlen($data);
        while ($offset + 4 <= $total) {
            $length = unpack('N', substr($data, $offset, 4))[1]; $offset += 4;
            if ($offset + $length > $total) break;
            $clean = NalUtil::removeEmulationPrevention(substr($data, $offset, $length)); $offset += $length;
            $result[] = ['type' => ord($clean[0]) & 0x1f, 'data' => substr($clean, 1), 'raw' => $clean];
        }
        return $result;
    }

    private function applyWatermark(string $yuv, int $w, int $h, string $file): string
    {
        $data = file_get_contents($file);
        if ($data === false) throw new RuntimeException("无法读取水印文件: {$file}");
        $base = basename($file, '.yuv');
        if (!preg_match('/_(\d+)x(\d+)$/', $base, $m)) { $ww = 80; $wh = 16; } else { $ww = (int)$m[1]; $wh = (int)$m[2]; }
        if ($ww > $w || $wh > $h || strlen($data) < $ww * $wh * 3 / 2) return $yuv;
        $ySize = $w * $h; $uvSize = ($w >> 1) * ($h >> 1); $wySize = $ww * $wh; $wuvSize = $wySize >> 2;
        for ($row = 0; $row < $wh; $row++) for ($col = 0; $col < $ww; $col++) $yuv[$row * $w + $col] = $data[$row * $ww + $col];
        for ($row = 0; $row < ($wh >> 1); $row++) for ($col = 0; $col < ($ww >> 1); $col++) {
            $dst = $row * ($w >> 1) + $col; $src = $row * ($ww >> 1) + $col;
            $yuv[$ySize + $dst] = $data[$wySize + $src]; $yuv[$ySize + $uvSize + $dst] = $data[$wySize + $wuvSize + $src];
        }
        return $yuv;
    }

    private function connect(string $address)
    {
        $deadline = microtime(true) + 15;
        do { $socket = @stream_socket_client($address, $errno, $error, 0.2); if ($socket !== false) return $socket; usleep(1); } while (microtime(true) < $deadline);
        throw new RuntimeException("无法连接编码进程: {$error} ({$errno})");
    }

    private function writeAll($socket, string $data): void
    {
        stream_set_blocking($socket, true); $offset = 0;
        while ($offset < strlen($data)) { $n = fwrite($socket, substr($data, $offset)); if ($n === false || $n === 0) throw new RuntimeException('无法发送完成响应'); $offset += $n; }
    }
}
