<?php

/**
 * FLV直播拉流转码压缩HLS客户端（独立部署入口）
 *
 * 用法:
 *   php bin/flv2hls-compact.php <直播地址> [选项]
 *
 * 选项:
 *   --out=<目录>            HLS输出目录（默认 项目根/hls/<流名>/）
 *   --name=<流名>           流名称（默认取URL路径末段）
 *   --width=<像素>          目标宽，0=保持（默认0）；缩放时与--height同时给出避免拉伸
 *   --height=<像素>         目标高，0=保持（默认0）
 *   --bitrate=<bps>         目标视频码率（默认800000）
 *   --fps=<帧率>            目标帧率（串行管道仅传编码器，不抽帧，默认0=保持）
 *   --qp=<0-51>             量化参数（默认10）
 *   --audio-bitrate=<bps>   音频码率（默认64000）
 *   --motion-workers=<n>    运动估计子进程数（默认6）
 *   --segment=<秒>          HLS切片时长（默认3）
 *   --queue-mb=<MB>         转码落后容忍MB，超限拉流端跳IDR追直播（默认8，绝不反压上游）
 *   --retries=<n>           断线重连次数（默认5）
 *   --retry-delay=<秒>      重连间隔（默认3）
 *   --idle-timeout=<秒>     无数据断流判定（默认30）
 *   --duration=<秒>         限定运行时长（默认0=不限）
 *   --insecure              跳过TLS证书校验（https/wss自签场景）
 *
 * 示例:
 *   php bin/flv2hls-compact.php http://127.0.0.1:8501/live/room1.flv --width=360 --height=360 --bitrate=600000
 */

ini_set('memory_limit', '2048M');

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Composer autoload 不存在: {$autoload}\n");
    exit(1);
}
require $autoload;

$args = $argv;
array_shift($args);

$url = null;
$options = [];
foreach ($args as $arg) {
    if (str_starts_with($arg, '--')) {
        $eq = strpos($arg, '=');
        if ($eq === false) {
            $key = substr($arg, 2);
            $options[$key] = true;
        } else {
            $options[substr($arg, 2, $eq - 2)] = substr($arg, $eq + 1);
        }
    } elseif ($url === null) {
        $url = $arg;
    }
}

if ($url === null) {
    fwrite(STDERR, "用法: php flv2hls-compact.php <直播地址> [选项]\n");
    exit(1);
}

$config = [];
if (isset($options['out'])) $config['outputDir'] = rtrim($options['out'], '/\\') . '/';
if (isset($options['name'])) $config['streamName'] = (string)$options['name'];
if (isset($options['width'])) $config['width'] = (int)$options['width'];
if (isset($options['height'])) $config['height'] = (int)$options['height'];
if (isset($options['bitrate'])) $config['bitrate'] = (int)$options['bitrate'];
if (isset($options['fps'])) $config['fps'] = (int)$options['fps'];
if (isset($options['qp'])) $config['qp'] = (int)$options['qp'];
if (isset($options['audio-bitrate'])) $config['audioBitrate'] = (int)$options['audio-bitrate'];
if (isset($options['motion-workers'])) $config['motionWorkers'] = (int)$options['motion-workers'];
if (isset($options['segment'])) $config['segmentDuration'] = (int)$options['segment'];
if (isset($options['queue-mb'])) $config['queueMaxBytes'] = (int)$options['queue-mb'] * 1048576;
if (isset($options['retries'])) $config['maxRetries'] = (int)$options['retries'];
if (isset($options['retry-delay'])) $config['retryDelay'] = (int)$options['retry-delay'];
if (isset($options['idle-timeout'])) $config['idleTimeout'] = (int)$options['idle-timeout'];
if (isset($options['duration'])) $config['duration'] = (int)$options['duration'];
if (!empty($options['insecure'])) $config['tlsVerify'] = false;

try {
    (new \Xiaosongshu\Flv2mp4\Manage\Flv2HlsCompact($url, $config))->run();
} catch (\Throwable $e) {
    fwrite(STDERR, '客户端异常退出: ' . $e->getMessage() . "\n");
    exit(1);
}
