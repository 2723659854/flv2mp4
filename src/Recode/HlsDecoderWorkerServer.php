<?php

namespace Xiaosongshu\Flv2mp4\Recode;

use RuntimeException;
use Throwable;
use Xiaosongshu\Flv2mp4\Codec\H264Decoder;
use Xiaosongshu\Flv2mp4\Codec\NalUtil;
use Xiaosongshu\Flv2mp4\Codec\Scaler\VideoScaler;

final class HlsDecoderWorkerServer
{
    private H264Decoder $decoder;
    private VideoScaler $scaler;
    private string $sps = '';
    private string $pps = '';
    private int $width = 0;
    private int $height = 0;

    public function __construct(private array $profiles)
    {
        $this->decoder = new H264Decoder();
        $this->scaler = new VideoScaler();
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
        $downstreamInput = '';
        $ended = false;
        try {
            while (true) {
                $read = [$downstream];
                if (!$ended && strlen($output) < HlsPipelineProtocol::HIGH_WATERMARK) $read[] = $upstream;
                $write = $output === '' ? [] : [$downstream];
                $except = null;
                @stream_select($read, $write, $except, 0, 200000);
                if (in_array($upstream, $read, true)) {
                    $chunk = @fread($upstream, 65536);
                    if ($chunk === false || ($chunk === '' && feof($upstream))) throw new RuntimeException('主进程媒体连接意外关闭');
                    $input .= $chunk;
                    if (strlen($input) > HlsPipelineProtocol::MAX_BUFFER_LENGTH) throw new RuntimeException('解码进程输入缓冲超限');
                }
                foreach (HlsPipelineProtocol::take($input, 1) as $event) {
                    if ($event['type'] === HlsPipelineProtocol::END) {
                        $output .= HlsPipelineProtocol::frame(HlsPipelineProtocol::END, $event['sequence']);
                        $ended = true;
                    } else {
                        $output .= $this->transform($event);
                    }
                    if (strlen($output) > HlsPipelineProtocol::MAX_BUFFER_LENGTH) throw new RuntimeException('解码进程下游缓冲超限');
                }
                if (in_array($downstream, $write, true)) {
                    $written = @fwrite($downstream, substr($output, 0, 65536));
                    if ($written === false || ($written === 0 && feof($downstream))) throw new RuntimeException('编码进程媒体连接意外关闭');
                    if ($written > 0) $output = substr($output, $written);
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
            // #region debug-point C:decoder-worker-error
            $this->debug('C', 'decoder-worker-error', ['message' => $e->getMessage(), 'class' => get_class($e), 'memory' => memory_get_usage(true)]);
            // #endregion
            @fclose($upstream);
            @fclose($downstream);
            throw $e;
        } finally {
            if (is_resource($upstream)) @fclose($upstream);
            if (is_resource($downstream)) @fclose($downstream);
        }
    }

    // #region debug-point C:decoder-worker-log
    private function debug(string $hypothesis, string $message, array $data): void { $env = @parse_ini_file(dirname(__DIR__, 2).'/.dbg/pipeline-worker-disconnect.env'); $url = $env['DEBUG_SERVER_URL'] ?? ''; $session = $env['DEBUG_SESSION_ID'] ?? ''; if ($url === '' || $session === '') return; $payload = json_encode(['sessionId' => $session, 'runId' => 'pre-fix', 'hypothesisId' => $hypothesis, 'location' => __FILE__, 'msg' => '[DEBUG] '.$message, 'data' => $data, 'ts' => (int)(microtime(true) * 1000)]); $context = stream_context_create(['http' => ['method' => 'POST', 'header' => 'Content-Type: application/json', 'content' => $payload, 'timeout' => 0.1]]); @file_get_contents($url, false, $context); }
    // #endregion

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
        $frame = $this->decoder->decode($nals);
        if (!$frame || empty($frame['data'])) return HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $event['sequence'], $meta, $body);
        $payload = '';
        $variants = [];
        $scaledCache = [];
        foreach ($this->profiles as $name => $profile) {
            $w = ($profile['width'] ?? 0) > 0 ? (int)$profile['width'] : $this->width;
            $h = ($profile['height'] ?? 0) > 0 ? (int)$profile['height'] : $this->height;
            $key = "{$w}x{$h}";
            if (!isset($scaledCache[$key])) {
                $scaledCache[$key] = ($w === $this->width && $h === $this->height)
                    ? $frame['data'] : $this->scaler->scaleYUV420P($frame['data'], $this->width, $this->height, $w, $h);
            }
            $yuv = $scaledCache[$key];
            if (!empty($profile['watermark']) && !empty($profile['watermark_file'])) $yuv = $this->applyWatermark($yuv, $w, $h, $profile['watermark_file']);
            $variants[$name] = ['offset' => strlen($payload), 'length' => strlen($yuv), 'width' => $w, 'height' => $h];
            $payload .= $yuv;
        }
        $meta['decoded'] = true;
        $meta['variants'] = $variants;
        $payload = pack('N', strlen($body)) . $body . $payload;
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
        do { $socket = @stream_socket_client($address, $errno, $error, 0.2); if ($socket !== false) return $socket; usleep(50000); } while (microtime(true) < $deadline);
        throw new RuntimeException("无法连接编码进程: {$error} ({$errno})");
    }

    private function writeAll($socket, string $data): void
    {
        stream_set_blocking($socket, true); $offset = 0;
        while ($offset < strlen($data)) { $n = fwrite($socket, substr($data, $offset)); if ($n === false || $n === 0) throw new RuntimeException('无法发送完成响应'); $offset += $n; }
    }
}
