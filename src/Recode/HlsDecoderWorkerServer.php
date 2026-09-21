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
    /** @var string 待发回主进程的上行控制帧（波前检查点等），每轮事件循环清空 */
    private string $upFrame = '';

    public function __construct(private array $profiles)
    {
        $this->decoder = new H264Decoder();
        $this->scaler = count($profiles) === 1 ? new VideoScaler() : null;
    }

    public function run(string $listenAddress, string $outputAddress): void
    {
        $server = @stream_socket_server($listenAddress, $errno, $error);
        if ($server === false) throw new RuntimeException("解码进程监听失败: {$error} ({$errno})");
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
        $ended = false;
        try {
            while (true) {
                $read = [$downstream];
                if (!$ended && strlen($input) < HlsPipelineProtocol::HIGH_WATERMARK) $read[] = $upstream;
                $write = $output === '' ? [] : [$downstream];
                if ($upOutput !== '') $write[] = $upstream;
                $except = null;
                @stream_select($read, $write, $except, 0, 1);
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
                // 单次 select 唤醒（Windows 下粒度约 10~15ms）批量解码全部已缓冲事件，
                // 下游输出积压到高水位时停止，让反压继续向下游传播，避免长文件下缓冲超限
                while (strlen($output) < HlsPipelineProtocol::HIGH_WATERMARK) {
                    $events = HlsPipelineProtocol::take($input, 1);
                    if ($events === []) break;
                    $event = $events[0];
                    if ($event['type'] === HlsPipelineProtocol::CONTROL) {
                        $cmd = $event['metadata']['cmd'] ?? '';
                        if ($cmd === 'config') $this->parseConfiguration(substr($event['payload'], 5));
                        elseif ($cmd === 'checkpoint') {
                            // 波前后段区间起点：注入前段 DPB 后再解后续帧
                            $cp = unserialize($event['payload']);
                            if (!is_array($cp)) throw new RuntimeException('收到无效的解码检查点');
                            $this->decoder->importCheckpoint($cp);
                        }
                        elseif ($cmd === 'gopEnd') {
                            // 处理到此处时，该 GOP 之前的所有帧均已解码并转发
                            $upOutput .= HlsPipelineProtocol::frame(HlsPipelineProtocol::PROGRESS, 0, ['gop' => (int)($event['metadata']['gop'] ?? -1)]);
                        }
                    } elseif ($event['type'] === HlsPipelineProtocol::END) {
                        $output .= HlsPipelineProtocol::frame(HlsPipelineProtocol::END, $event['sequence']);
                        $ended = true;
                    } else {
                        $this->upFrame = '';
                        $output .= $this->transform($event);
                        if ($this->upFrame !== '') { $upOutput .= $this->upFrame; $this->upFrame = ''; }
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
        }
    }

    private function transform(array $event): string
    {
        if ($event['type'] !== HlsPipelineProtocol::EVENT) throw new RuntimeException('解码进程收到未知事件');
        $meta = $event['metadata'];
        // 波前边界标记：本帧解码后回传检查点，不应透传到下游
        $cpAfter = isset($meta['cpAfter']) ? (int)$meta['cpAfter'] : null;
        unset($meta['cpAfter']);
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
        $frame = $this->decoder->decode($nals);
        if (!$frame || empty($frame['data'])) return HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $event['sequence'], $meta, $body);
        // 波前：边界帧（含被抽帧丢弃帧——已完整解码入 DPB）导出检查点回传主进程
        if ($cpAfter !== null) {
            $cp = $this->decoder->exportCheckpoint(
                $this->sps !== '' ? $this->sps : null,
                $this->pps !== '' ? $this->pps : null
            );
            $this->upFrame = HlsPipelineProtocol::frame(
                HlsPipelineProtocol::CONTROL, 0, ['cmd' => 'checkpoint', 'range' => $cpAfter], serialize($cp)
            );
        }
        // 抽帧丢弃：解码已完成（维持 GOP 内后续帧的参考链），但不缩放/不附 YUV，
        // meta.drop 原样透传，输出端直接跳过编码
        if (!empty($meta['drop'])) {
            return HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $event['sequence'], $meta, $body);
        }
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
