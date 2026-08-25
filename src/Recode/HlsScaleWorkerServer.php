<?php

namespace Xiaosongshu\Flv2mp4\Recode;

use RuntimeException;
use Throwable;
use Xiaosongshu\Flv2mp4\Codec\Scaler\VideoScaler;

/**
 * @purpose flv转hls分布式架构-缩放
 * @author yanglong
 */
final class HlsScaleWorkerServer
{
    private VideoScaler $scaler;

    public function __construct(private array $profiles, private string $outputDir)
    {
        $this->scaler = new VideoScaler();
    }

    public function run(string $listenAddress, array $outputAddresses): void
    {
        $server = @stream_socket_server($listenAddress, $errno, $error);
        if ($server === false) throw new RuntimeException("缩放进程监听失败: {$error} ({$errno})");
        $downstreams = [];
        foreach ($this->profiles as $name => $_) {
            if (!isset($outputAddresses[$name])) throw new RuntimeException("缺少 profile {$name} 的输出地址");
            $downstreams[$name] = $this->connect($outputAddresses[$name], $name);
            stream_set_blocking($downstreams[$name], false);
        }
        $upstream = @stream_socket_accept($server, 15);
        fclose($server);
        if ($upstream === false) throw new RuntimeException('缩放进程等待解码进程连接超时');
        stream_set_blocking($upstream, false);

        $input = '';
        $outputs = array_fill_keys(array_keys($this->profiles), '');
        $responses = array_fill_keys(array_keys($this->profiles), '');
        $finished = [];
        $ended = false;
        $endSequence = 0;
        try {
            while (true) {
                $read = [];
                if (!$ended && $this->outputsBelowHighWatermark($outputs)) $read[] = $upstream;
                foreach ($downstreams as $name => $socket) if (!isset($finished[$name])) $read[] = $socket;
                $write = [];
                foreach ($downstreams as $name => $socket) if ($outputs[$name] !== '') $write[] = $socket;
                $except = null;
                @stream_select($read, $write, $except, 0, 1);

                if (in_array($upstream, $read, true)) {
                    $chunk = @fread($upstream, 65536);
                    if ($chunk === false || ($chunk === '' && feof($upstream))) throw new RuntimeException('解码进程媒体连接意外关闭');
                    $input .= $chunk;
                    if (strlen($input) > HlsPipelineProtocol::MAX_BUFFER_LENGTH) throw new RuntimeException('缩放进程输入缓冲超限');
                }
                foreach (HlsPipelineProtocol::take($input, 1) as $event) {
                    if ($event['type'] === HlsPipelineProtocol::END) {
                        $ended = true;
                        $endSequence = $event['sequence'];
                        foreach ($outputs as $name => $_) $outputs[$name] .= HlsPipelineProtocol::frame(HlsPipelineProtocol::END, $endSequence);
                    } elseif ($event['type'] === HlsPipelineProtocol::EVENT) {
                        foreach ($this->fanout($event) as $name => $frame) $outputs[$name] .= $frame;
                    } else throw new RuntimeException('缩放进程收到未知事件');
                    foreach ($outputs as $buffer) if (strlen($buffer) > HlsPipelineProtocol::MAX_BUFFER_LENGTH) throw new RuntimeException('缩放进程下游缓冲超限');
                }

                foreach ($downstreams as $name => $socket) {
                    if (in_array($socket, $write, true)) {
                        $n = @fwrite($socket, substr($outputs[$name], 0, 65536));
                        if ($n === false || ($n === 0 && feof($socket))) throw new RuntimeException("profile {$name} 输出连接意外关闭");
                        if ($n > 0) $outputs[$name] = substr($outputs[$name], $n);
                    }
                    if (in_array($socket, $read, true)) {
                        $chunk = @fread($socket, 65536);
                        if ($chunk === false || ($chunk === '' && feof($socket))) throw new RuntimeException("profile {$name} 响应连接意外关闭");
                        $responses[$name] .= $chunk;
                        foreach (HlsPipelineProtocol::take($responses[$name], 4) as $response) {
                            if ($response['type'] === HlsPipelineProtocol::ERROR) throw new RuntimeException($response['metadata']['message'] ?? "profile {$name} 输出失败");
                            if ($response['type'] === HlsPipelineProtocol::FINISHED) $finished[$name] = true;
                        }
                    }
                }
                if ($ended && count($finished) === count($downstreams)) {
                    (new PurePhpHlsGenerator($this->profiles, $this->outputDir, false))->finishPipelineOutput();
                    $this->writeAll($upstream, HlsPipelineProtocol::frame(HlsPipelineProtocol::FINISHED, $endSequence));
                    return;
                }
            }
        } catch (Throwable $e) {
            if (is_resource($upstream)) {
                try { $this->writeAll($upstream, HlsPipelineProtocol::frame(HlsPipelineProtocol::ERROR, $endSequence, ['message' => $e->getMessage()])); } catch (Throwable) {}
            }
            throw $e;
        } finally {
            if (is_resource($upstream)) @fclose($upstream);
            foreach ($downstreams as $socket) if (is_resource($socket)) @fclose($socket);
        }
    }

    private function fanout(array $event): array
    {
        $meta = $event['metadata'];
        $payload = $event['payload'];
        if (empty($meta['decoded'])) {
            $frame = HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $event['sequence'], $meta, $payload);
            return array_fill_keys(array_keys($this->profiles), $frame);
        }
        if (strlen($payload) < 4) throw new RuntimeException('解码帧负载不完整');
        $bodyLength = unpack('N', substr($payload, 0, 4))[1];
        if (strlen($payload) < 4 + $bodyLength) throw new RuntimeException('解码帧原始 Tag 不完整');
        $body = substr($payload, 4, $bodyLength);
        $yuv = substr($payload, 4 + $bodyLength);
        $sourceWidth = (int)($meta['sourceWidth'] ?? 0);
        $sourceHeight = (int)($meta['sourceHeight'] ?? 0);
        if ($sourceWidth <= 0 || $sourceHeight <= 0) throw new RuntimeException('解码帧缺少源尺寸');

        $result = [];
        $scaledCache = [];
        foreach ($this->profiles as $name => $profile) {
            $width = ($profile['width'] ?? 0) > 0 ? (int)$profile['width'] : $sourceWidth;
            $height = ($profile['height'] ?? 0) > 0 ? (int)$profile['height'] : $sourceHeight;
            $key = "{$width}x{$height}";
            if (!isset($scaledCache[$key])) {
                $scaledCache[$key] = ($width === $sourceWidth && $height === $sourceHeight)
                    ? $yuv : $this->scaler->scaleYUV420P($yuv, $sourceWidth, $sourceHeight, $width, $height);
            }
            $variant = $scaledCache[$key];
            if (!empty($profile['watermark']) && !empty($profile['watermark_file'])) $variant = $this->applyWatermark($variant, $width, $height, $profile['watermark_file']);
            $profileMeta = $meta;
            $profileMeta['variants'] = [$name => ['offset' => 0, 'length' => strlen($variant), 'width' => $width, 'height' => $height]];
            $result[$name] = HlsPipelineProtocol::frame(HlsPipelineProtocol::EVENT, $event['sequence'], $profileMeta, pack('N', strlen($body)) . $body . $variant);
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

    private function outputsBelowHighWatermark(array $outputs): bool
    {
        foreach ($outputs as $output) if (strlen($output) >= HlsPipelineProtocol::HIGH_WATERMARK) return false;
        return true;
    }

    private function connect(string $address, string $name)
    {
        $deadline = microtime(true) + 15;
        do { $socket = @stream_socket_client($address, $errno, $error, 0.2); if ($socket !== false) return $socket; usleep(50000); } while (microtime(true) < $deadline);
        throw new RuntimeException("无法连接 profile {$name} 输出进程: {$error} ({$errno})");
    }

    private function writeAll($socket, string $data): void
    {
        stream_set_blocking($socket, true); $offset = 0;
        while ($offset < strlen($data)) { $n = @fwrite($socket, substr($data, $offset)); if ($n === false || $n === 0) throw new RuntimeException('无法发送缩放进程响应'); $offset += $n; }
    }
}
