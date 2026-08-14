<?php

namespace Xiaosongshu\Flv2mp4\Recode;

use RuntimeException;
use Throwable;
use Xiaosongshu\Flv2mp4\Codec\H264Decoder;
use Xiaosongshu\Flv2mp4\Codec\NalUtil;
use Xiaosongshu\Flv2mp4\Codec\Scaler\VideoScaler;

final class FlvDecoderWorkerServer
{
    private H264Decoder $decoder;
    private VideoScaler $scaler;
    private string $sps = '';
    private string $pps = '';
    private int $width = 0;
    private int $height = 0;
    private int $baseTimestamp = -1;
    private int $selected = 0;

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
        $input = ''; $output = ''; $response = ''; $ended = false;
        try {
            while (true) {
                $read = [$downstream]; if (!$ended && strlen($output) < HlsPipelineProtocol::HIGH_WATERMARK) $read[] = $upstream;
                $write = $output === '' ? [] : [$downstream]; $except = null; @stream_select($read, $write, $except, 0, 200000);
                if (in_array($upstream, $read, true)) {
                    $chunk = @fread($upstream, 65536);
                    if ($chunk === false || ($chunk === '' && feof($upstream))) throw new RuntimeException('主进程媒体连接意外关闭');
                    $input .= $chunk; if (strlen($input) > HlsPipelineProtocol::MAX_BUFFER_LENGTH) throw new RuntimeException('解码进程输入缓冲超限');
                }
                foreach (HlsPipelineProtocol::take($input, 1) as $event) {
                    if ($event['type'] === HlsPipelineProtocol::END) { $output .= HlsPipelineProtocol::frame(HlsPipelineProtocol::END, $event['sequence']); $ended = true; }
                    else $output .= $this->transform($event);
                    if (strlen($output) > HlsPipelineProtocol::MAX_BUFFER_LENGTH) throw new RuntimeException('解码进程下游缓冲超限');
                }
                if (in_array($downstream, $write, true)) {
                    $n = @fwrite($downstream, substr($output, 0, 65536));
                    if ($n === false || ($n === 0 && feof($downstream))) throw new RuntimeException('输出进程媒体连接意外关闭');
                    if ($n > 0) $output = substr($output, $n);
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
        $frame = $this->decoder->decode($nals);
        if (!$frame || empty($frame['data'])) return HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $event['sequence'], $meta, $body);
        $isKey = (ord($body[0]) >> 4) === 1; $timestamp = (int)($meta['timestamp'] ?? 0);
        if ($this->baseTimestamp < 0) { if (!$isKey) { $meta['drop'] = true; return HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $event['sequence'], $meta, $body); } $this->baseTimestamp = $timestamp; }
        $sourceFps = isset($this->config['source_fps']) ? (float)$this->config['source_fps'] : 0.0;
        $targetFps = (int)($this->config['fps'] ?? 0);
        $dropFrames = $targetFps > 0 && $sourceFps > 0 && $targetFps < $sourceFps - 0.01;
        $relative = $timestamp - $this->baseTimestamp;
        if ($dropFrames && $this->selected > 0 && $relative * $targetFps < $this->selected * 1000) { $meta['drop'] = true; return HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $event['sequence'], $meta, $body); }
        $this->selected++;
        $w = ($this->config['width'] ?? 0) > 0 ? (int)$this->config['width'] : $this->width;
        $h = ($this->config['height'] ?? 0) > 0 ? (int)$this->config['height'] : $this->height;
        $yuv = ($w === $this->width && $h === $this->height) ? $frame['data'] : $this->scaler->scaleYUV420P($frame['data'], $this->width, $this->height, $w, $h);
        if (!empty($this->config['watermark']) && !empty($this->config['watermark_file'])) $yuv = $this->applyWatermark($yuv, $w, $h, $this->config['watermark_file']);
        $meta['decoded'] = true;
        return HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $event['sequence'], $meta, pack('N', strlen($body)) . $body . $yuv);
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
        $deadline = microtime(true) + 15; do { $socket = @stream_socket_client($address, $errno, $error, 0.2); if ($socket !== false) return $socket; usleep(50000); } while (microtime(true) < $deadline);
        throw new RuntimeException("无法连接输出进程: {$error} ({$errno})");
    }

    private function writeAll($socket, string $data): void
    {
        stream_set_blocking($socket, true); $offset = 0; while ($offset < strlen($data)) { $n = fwrite($socket, substr($data, $offset)); if ($n === false || $n === 0) throw new RuntimeException('无法发送完成响应'); $offset += $n; }
    }
}
