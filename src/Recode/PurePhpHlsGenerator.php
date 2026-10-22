<?php

namespace Xiaosongshu\Flv2mp4\Recode;

use Xiaosongshu\Flv2mp4\Codec\H264Decoder;
use Xiaosongshu\Flv2mp4\Codec\H264Encoder;
use Xiaosongshu\Flv2mp4\Codec\NalUtil;
use Xiaosongshu\Flv2mp4\Codec\Scaler\VideoScaler;
use Xiaosongshu\Flv2mp4\Flv\FlvParse;

/**
 * @purpose flv转hls重编码（单profile单码率）
 * @author yanglong
 * @time 2026年7月23日14:05:53
 */
class PurePhpHlsGenerator
{

    /** 切片分割时间 3秒 */
    private int $segmentDuration = 3;
    private int $videoPid = 0x100;
    private int $audioPid = 0x101;
    private int $pmtPid = 0x1000;

    /** 单路转码配置（与 FlvRecoder/Mp4Recoder 同形的一维数组） */
    private array $profile;
    private string $outputDir;
    /** 当前分片写状态：sequence=分片序号，handle=TS 文件句柄，startTime/endTime=分片时间范围 */
    private array $segmentWriter = ['sequence' => 0, 'handle' => null, 'startTime' => 0, 'endTime' => 0];
    /** index.m3u8 追加写句柄，开流后保持打开直到写入 ENDLIST */
    private $playlistHandle = null;
    /** 前置到分片首帧的 SPS/PPS（AnnexB） */
    private string $spsPps = '';
    /** 各 PID 连续计数器 */
    private array $continuityCounters = [];

    private ?int $baseTimestamp = null;
    private int $segmentStartTime = 0;
    private int $currentSegmentLastTime = 0;

    private string $audioSpecificConfig = '';
    private int $audioObjectType = 2;
    private int $samplingFrequencyIndex = 4;
    private int $channelConfiguration = 2;
    private bool $sbrPresent = false;
    private ?int $extensionSamplingIndex = null;

    private int $audioFrameCount = 0;
    private ?int $audioBasePts = null;

    /** 解码器 */
    private H264Decoder $decoder;

    /** 编码器 */
    private H264Encoder $encoder;

    /** 视频尺寸缩放器 */
    private VideoScaler $scaler;

    private int $srcWidth = 0;
    private int $srcHeight = 0;
    private bool $srcInitialized = false;

    /** 水印（解码后的 YUV 平面数据），null 表示无水印 */
    private ?array $watermark = null;

    /** 最大处理帧数 调试用 */
    private ?int $maxFrames = null;
    /** 已写入 TS 的最大视频 dts（90kHz tick），用于全局单调钳制（跨分片持续有效） */
    private ?int $lastVideoDtsTick = null;
    /** B 模式（IBBP）全局编码序帧计数器，跨 GOP 不重置——保证 DTS 全局单调递增 */
    private int $videoEncodeCounter = 0;
    /** TS 音视频 PTS 统一偏移（90kHz tick）：IBBP 下 B 帧解码序天然晚于显示序（pts<dts），
     *  TS 要求每帧 pts>=dts（ISO 13818-1：解码不得晚于呈现；ffmpeg 对 pts<dts 的包报
     *  Invalid timestamps）。标准做法与 x264/ffmpeg mpegts muxer 一致：PTS 整体后移
     *  重排延迟（2 帧时长），音视频同移保持 A/V 同步；非 B 模式为 0 */
    private int $tsPtsOffsetTicks = 0;
    /** pipeline 元数据缓存：worker 传来的 encoded/ctsMs/dtsMs 在 processPipelineEvent 里暂存，handleVideoFrame 里消费 */
    private ?array $pipelineFrameMeta = null;
    private bool $segmentFirstFrame = true;
    private string $srcSpsData = '';
    private string $srcPpsData = '';
    private bool $multi;
    private int $decodeWorkers;
    private string $pipelineEncodedPayload = '';
    /** pipeline 元数据中的编码帧变体（offset/length/width/height），null 表示直通帧 */
    private ?array $pipelineEncodedVariant = null;

    // 帧级双缓冲：一个已 startFrame 的视频帧延后到下一帧到达后再 finish
    private ?array $pendingVideoJob = null;

    /** @var array<int,object> 在途视频帧之后到达的音频 tag，待该帧写出后按原顺序回放 */
    private array $queuedAudioTags = [];

    const VIDEO_FRAME_TYPE_KEY_FRAME = 1;
    const AVC_PACKET_TYPE_SEQUENCE_HEADER = 0;
    const AVC_PACKET_TYPE_NALU = 1;

    /**
     * 转码器初始化
     *
     * @param array $config 单路一维配置（与 FlvRecoder/Mp4Recoder 同形）：
     *    ['width'=>..,'height'=>..,'bitrate'=>..,'fps'=>..,'qp'=>..,'decodeWorkers'=>6,'motionWorkers'=>6,...]
     *    分片与 index.m3u8 直接输出到 outputDir 根部；
     *    decodeWorkers 从配置读取，避免与 recode 入口配置不一致。
     * @param string $outputDir 输出目录
     * @param bool $multi 是否开启多进程
     * @param int $decodeWorkers 多进程解码worker数（配置中的 decodeWorkers 优先）
     */
    public function __construct(array $config, string $outputDir = '', bool $multi = false, int $decodeWorkers = 6)
    {
        $this->profile = $config;
        $decodeWorkers = (int)($config['decodeWorkers'] ?? $decodeWorkers);
        $this->outputDir = rtrim($outputDir, '/');
        $this->multi = $multi;
        $this->decodeWorkers = $decodeWorkers;

        $this->decoder = new H264Decoder();
        $this->scaler = new VideoScaler();

        $this->encoder = new H264Encoder();
        $this->encoder->motionWorkers = max(1, (int)($config['motionWorkers'] ?? 8));
        $this->encoder->setProfile($config['profile'] ?? 'baseline');
        if (!empty($config['fastMotion'])) $this->encoder->setFastMotion(true);
        if (isset($config['intra8x8QpOffset'])) $this->encoder->intra8x8QpOffset = max(-51, min(51, (int)$config['intra8x8QpOffset']));
        if (!is_dir($this->outputDir)) mkdir($this->outputDir, 0777, true);

        if (!empty($config['watermark']) && !empty($config['watermarkFile'])) {
            $this->watermark = $this->loadWatermarkFile($config['watermarkFile']);
        }

        // B 帧模式：TS 层 PTS 统一后移 2 帧时长（重排延迟），保证每帧 pts>=dts。
        // 音视频同移同一常量，A/V 相对同步不变；偏移量用 fps 计算（常数即可，
        // 只需大于最大 pts<dts 缺口 1 帧，无需与 worker 有效帧时长严格相等）
        if (!empty($config['bframes'])) {
            $offsetFps = max(1, (int)($config['fps'] ?? 25));
            $this->tsPtsOffsetTicks = (int)round(2 * (1000.0 / $offsetFps) * 90);
        }

        /** 初始化空m3u8 */
        $this->ensureInitialPlaylist();
    }

    /**
     * 预热运动估计子进程（输出 worker 启动时调用，
     * 让 PHP 冷启动与解码首 GOP 并行发生）
     */
    public function warmupMotionWorkers(): void
    {
        $this->encoder->warmupMotionWorkers();
    }

    /**
     * 设置最大处理帧数
     * @param int $maxFrames
     * @return void
     */
    public function setMaxFrames(int $maxFrames): void
    {
        $this->maxFrames = $maxFrames;
    }

    /**
     * 设置切片间隔
     * 追加写模式下 header 无法回改，故在开流前（尚无任何分片）一次性重写 TARGETDURATION
     * @param int $segmentDuration
     * @return void
     */
    public function setSegmentDuration(int $segmentDuration = 3)
    {
        $this->segmentDuration = $segmentDuration;
        if (($this->segmentWriter['sequence'] ?? 0) === 0) $this->resetPlaylists();
    }

    private function loadWatermarkFile(string $watermarkFile): array
    {
        if (!file_exists($watermarkFile)) {
            throw new \RuntimeException("水印文件不存在: {$watermarkFile}");
        }

        $wmData = file_get_contents($watermarkFile);
        if ($wmData === false || $wmData === '') {
            throw new \RuntimeException("无法读取水印文件: {$watermarkFile}");
        }

        $basename = basename($watermarkFile, '.yuv');
        if (preg_match('/_(\d+)x(\d+)$/', $basename, $matches)) {
            $wmWidth = (int)$matches[1];
            $wmHeight = (int)$matches[2];
        } else {
            $wmWidth = 80;
            $wmHeight = 16;
        }

        $wmYSize = $wmWidth * $wmHeight;
        $wmUvSize = $wmYSize >> 2;
        $expectedSize = $wmYSize + $wmUvSize * 2;

        if (strlen($wmData) < $expectedSize) {
            throw new \RuntimeException("水印文件尺寸不匹配: 期望 {$expectedSize} 字节, 实际 " . strlen($wmData) . " 字节");
        }

        return [
            'width' => $wmWidth,
            'height' => $wmHeight,
            'y' => substr($wmData, 0, $wmYSize),
            'u' => substr($wmData, $wmYSize, $wmUvSize),
            'v' => substr($wmData, $wmYSize + $wmUvSize, $wmUvSize),
        ];
    }

    private function applyWatermarkToFrame(string $yuvData, int $frameW, int $frameH, array $wm): string
    {
        if ($wm['width'] > $frameW || $wm['height'] > $frameH) {
            return $yuvData;
        }

        $ySize = $frameW * $frameH;
        $uvW = $frameW >> 1;
        $uvH = $frameH >> 1;
        $uvSize = $uvW * $uvH;

        $dstX = 0;
        $dstY = 0;

        for ($row = 0; $row < $wm['height']; $row++) {
            $srcOffset = $row * $wm['width'];
            $dstOffset = ($dstY + $row) * $frameW + $dstX;
            for ($col = 0; $col < $wm['width']; $col++) {
                $yuvData[$dstOffset + $col] = $wm['y'][$srcOffset + $col];
            }
        }

        $wmUvW = $wm['width'] >> 1;
        $wmUvH = $wm['height'] >> 1;
        $dstUvX = $dstX >> 1;
        $dstUvY = $dstY >> 1;

        $uOffset = $ySize;
        $vOffset = $ySize + $uvSize;

        for ($row = 0; $row < $wmUvH; $row++) {
            $srcOffset = $row * $wmUvW;
            $dstOffset = ($dstUvY + $row) * $uvW + $dstUvX;
            for ($col = 0; $col < $wmUvW; $col++) {
                $yuvData[$uOffset + $dstOffset + $col] = $wm['u'][$srcOffset + $col];
                $yuvData[$vOffset + $dstOffset + $col] = $wm['v'][$srcOffset + $col];
            }
        }

        return $yuvData;
    }

    /**
     * 处理flv转码
     * @param string $flvFile
     * @return void
     * @throws \Exception
     */
    public function processFlv(string $flvFile): void
    {
        if (!file_exists($flvFile)) throw new \Exception("FLV file not found: {$flvFile}");
        if ($this->multi) {
            // 严格按配置值创建——motionWorkers 配置多少就是每解码 worker 带多少运动进程。
            // fastMotion 只在 H264Encoder.setFastMotion 里缩小运动搜索范围到 8px，不干预进程数。
            (new HlsPipelineClient($this->profile, $this->outputDir, $this->maxFrames, $this->decodeWorkers))->process($flvFile);
            return;
        }

        $flvData = file_get_contents($flvFile);
        FlvParse::setFlv($flvData);

        $frameCount = 0;
        $videoCount = 0;
        foreach (FlvParse::getTags() as $tag) {
            if (property_exists($tag, 'tagType')) {
                if ($tag->tagType === 9) {
                    $this->handleVideoFrame($tag);
                    $videoCount++;
                }
                elseif ($tag->tagType === 8) $this->handleAudioFrame($tag);
            }
            $frameCount++;
            if ($frameCount % 10 === 0) echo "Processed {$frameCount} frames ({$videoCount} video)\n";
            if ($this->maxFrames !== null && $videoCount >= $this->maxFrames) {
                echo "Reached max frames limit ({$this->maxFrames}), stopping...\n";
                break;
            }
        }

        $this->finishStream();
        echo "Done! Processed {$frameCount} frames\n";
    }

    /**
     * 单帧喂入入口（直播流式）：逐 tag 调用，等价于 processFlv 串行模式的循环体。
     * $tag 需为含 tagType(8=音频/9=视频)、body 的对象，时间戳通过 getTime()/getTimestamp()
     * 或可解析的 timestamp/Time 属性获得（与 Flv2Hls::processFrame 兼容的 tag 形态）。
     * 注意：串行流式路径不做抽帧（fps 仅传给编码器），丢弃 P 帧会断参考链，故全部帧均编码。
     * 流结束时必须调用 finishStream() 冲刷末帧并关闭分片。
     */
    public function processTag($tag): void
    {
        if (!is_object($tag) || !property_exists($tag, 'tagType')) return;
        if ($tag->tagType === 9) $this->handleVideoFrame($tag);
        elseif ($tag->tagType === 8) $this->handleAudioFrame($tag);
    }

    /**
     * 结束流式喂入：冲刷双缓冲末帧、关闭分片并写入 ENDLIST
     */
    public function finishStream(): void
    {
        // 冲刷双缓冲中最后一帧及排队音频，保证分片完整后再关闭
        $this->flushPendingVideo();
        $this->closeAllSegments();
    }

    public function processPipelineEvent(array $metadata, string $payload): void
    {
        $tag = new class($metadata, $payload) {
            public int $tagType;
            public string $body;
            public function __construct(private array $metadata, string $payload)
            {
                $this->tagType = (int)$metadata['tagType'];
                $this->body = $payload;
            }
            public function getTime(): int {
                // 抽帧保留帧使用主进程重映射后的均匀网格时间戳，音频/未抽帧使用源时间戳
                return (int)($this->metadata['outTimestamp'] ?? $this->metadata['timestamp'] ?? 0);
            }
            public function hasKeyFrameMark(): bool {
                return array_key_exists('keyFrame', $this->metadata);
            }
            public function isMarkedKey(): bool {
                return !empty($this->metadata['keyFrame']);
            }
        };
        if (!empty($metadata['decoded']) || !empty($metadata['encoded'])) {
            $bodyLength = unpack('N', substr($payload, 0, 4))[1];
            $tag->body = substr($payload, 4, $bodyLength);
            $this->pipelineEncodedPayload = substr($payload, 4 + $bodyLength);
            $this->pipelineEncodedVariant = $metadata['encodedVariant'] ?? null;
            // 暂存 worker 传来的 B 帧关键元数据（encoded/ctsTicks/dtsMs），handleVideoFrame 里消费
            $this->pipelineFrameMeta = [
                'encoded' => !empty($metadata['encoded']),
                'ctsTicks' => isset($metadata['ctsTicks']) ? (int)$metadata['ctsTicks'] : null,
                'dtsMs' => isset($metadata['dtsMs']) ? (int)$metadata['dtsMs'] : null,
                'frameDurMs' => isset($metadata['frameDurMs']) && (float)$metadata['frameDurMs'] > 0 ? (float)$metadata['frameDurMs'] : null,
            ];
            $metadata['compositionTime'] = (int)($metadata['compositionTime'] ?? 0);
        }
        // 抽帧丢弃帧：decoder 已解码维持参考链，输出端不编码、不写 TS，时间轴由保留帧推进
        if ($tag->tagType === 9 && !empty($metadata['drop'])) {
            $this->pipelineEncodedPayload = '';
            $this->pipelineEncodedVariant = null;
            return;
        }
        if ($tag->tagType === 9) $this->handleVideoFrame($tag);
        elseif ($tag->tagType === 8) $this->handleAudioFrame($tag);
        $this->pipelineEncodedPayload = '';
        $this->pipelineEncodedVariant = null;
        $this->pipelineFrameMeta = null;
    }

    public function finishPipelineOutput(): void
    {
        // 冲刷双缓冲中最后一帧及排队音频，保证分片完整后再关闭
        $this->flushPendingVideo();
        $this->closeAllSegments();
    }

    /**
     * 处理视频帧
     * @param $tag
     * @return void
     */
    private function handleVideoFrame($tag): void
    {
        $body = $tag->body ?? null;
        if ($body === null) return;

        $videoData = $this->videoFrameDataRead($body);
        if (!$videoData) return;

        $avc = $this->avcPacketRead($videoData['data']);
        if (!$avc) return;

        if ($avc['avcPacketType'] === self::AVC_PACKET_TYPE_SEQUENCE_HEADER) {
            $this->parseAVCDecoderConfigurationRecord($avc['data']);
            return;
        }

        if ($avc['avcPacketType'] !== self::AVC_PACKET_TYPE_NALU) return;

        // 关键帧判定：分布式管线下解码内容因 POC 重排滞后于 tag，body 首字节不可信，
        // 解码 worker 已在 meta.keyFrame 写入真实编码内容的 isIdr（与 FlvRecoder 消费约定一致）；
        // 非分布式路径无该标记，回退 body frameType
        $isKeyFrame = (method_exists($tag, 'hasKeyFrameMark') && $tag->hasKeyFrameMark())
            ? $tag->isMarkedKey()
            : ($videoData['frameType'] === self::VIDEO_FRAME_TYPE_KEY_FRAME);
        $timestamp = method_exists($tag, 'getTime') ? $tag->getTime() : 0;

        // 首关键帧初始化时间基准
        if ($this->baseTimestamp === null) {
            if (!$isKeyFrame) return;
            $this->baseTimestamp = $timestamp;
            $this->segmentStartTime = 0;
            $this->startSegment();
        }

        // 重编码流：worker 带 ctsTicks 时（B 帧 IBBP）直接用它（90kHz tick，无毫秒取整误差）；
        // 否则强制 cts=0 按均匀网格推进
        // 避免源流 cts 与重排后内容不配对导致 pts 倒挂（非单调 dts → muxer 报错）
        $pm = $this->pipelineFrameMeta;
        if (is_array($pm) && !empty($pm['encoded']) && $pm['ctsTicks'] !== null) {
            $ctsTicks = (int)$pm['ctsTicks'];
        } elseif ($this->pipelineEncodedVariant !== null) {
            $ctsTicks = 0;
        } else {
            $ctsMs = (int)($avc['compositionTime'] ?? 0);
            // FLV compositionTime 是 24-bit 有符号补码，转成 PHP int
            if ($ctsMs & 0x800000) $ctsMs -= 0x1000000;
            $ctsTicks = $ctsMs * 90;
        }
        // B 模式编码序帧：worker 给出了真正的编码序 DTS（均匀网格），不走源流 timestamp
        $workerDtsMs = (is_array($pm) && !empty($pm['encoded']) && $pm['dtsMs'] !== null) ? (int)$pm['dtsMs'] : null;
        $workerFrameDurMs = (is_array($pm) && !empty($pm['encoded']) && $pm['frameDurMs'] !== null) ? (float)$pm['frameDurMs'] : null;
        $job = $this->prepareVideoJob($avc['data'], $isKeyFrame, $timestamp - $this->baseTimestamp, $ctsTicks, $workerDtsMs, $workerFrameDurMs);
        if ($job === null) return;

        // 关键顺序：新帧各 profile 已 startFrame（worker 在途）→ 此时 finish 上一帧，
        // 主进程串行 CAVLC 与 worker 对新帧的运动估计重叠执行
        if ($this->pendingVideoJob !== null) {
            $pendingJob = $this->pendingVideoJob;
            // 必须先清空在途标记：replayQueuedAudio 依赖它判断音频可直接写出，
            // 否则排队音频会被 handleAudioFrame 重新入队后清空，导致帧间音频全部丢失
            $this->pendingVideoJob = null;
            $this->emitPendingVideoFrame($pendingJob);
        }
        $this->replayQueuedAudio();

        // 上一帧与期间音频都已写入旧分片，此刻再为新帧切换分片
        if ($job['segmentSwitch']) {
            $this->closeSegment($job['relativeTime']);
            $this->audioFrameCount = 0;
            $this->audioBasePts = (int)($job['relativeTime'] * 90);
            $this->segmentStartTime = $job['relativeTime'];
            $this->segmentFirstFrame = true;
            $this->startSegment();
        }

        $this->pendingVideoJob = $job;
    }

    /**
     * 每帧预处理：解码/缩放/水印 + startFrame，并计算分片切换决策（不执行切换）。
     */
    private function prepareVideoJob(string $avcData, bool $isKeyFrame, int $relativeTime, int $ctsTicks, ?int $workerDtsMs = null, ?float $workerFrameDurMs = null): ?array
    {
        // 注意：CTS 补码处理（FLV 24-bit compositionTime）已移到 handleVideoFrame，
        // 此处接收的 ctsTicks 已是 90kHz tick（源流毫秒×90 或 worker 直接提供），不再二次转换

        $segmentSwitch = $isKeyFrame
            && ($relativeTime - $this->segmentStartTime) >= ($this->segmentDuration * 1000);

        // B 模式（IBBP）：worker 在 GOP 边界会重置 bEncodeCounter，
        // 所以不能直接用 workerDtsMs（会从 0 重新开始导致跨 GOP DTS 非单调）。
        // 改用本进程的全局编码序计数器 videoEncodeCounter（跨 GOP 不重置）×帧时长推导 DTS。
        // B 模式编码序帧：worker 给出了真正的编码序 DTS（均匀网格），不走源流 timestamp。
        // CTS 用 worker 的 ctsTicks（只在 GOP 内做相对差，绝对值不受 GOP 边界 reset 影响）。
        // PTS = 全局编码序×帧时长 + ctsTicks = 绝对显示序×帧时长，跨 GOP 连续递增。
        if ($workerDtsMs !== null) {
            // 帧时长优先用 worker 实际值（抽帧后有效帧率 = target/source 取小），回退配置 fps
            $frameDurMs = ($workerFrameDurMs !== null && $workerFrameDurMs > 0)
                ? $workerFrameDurMs
                : 1000.0 / max(1, (int)(($this->profile['fps'] ?? 25)));
            $dtsMs = $this->videoEncodeCounter * $frameDurMs;
            $dts = (int)($dtsMs * 90);
            $this->videoEncodeCounter++;
        } else {
            $dts = (int)($relativeTime * 90);
            // 非 B 模式仍保留钳制（源流 timestamp 可能有碰撞）
            if ($this->lastVideoDtsTick !== null && $dts <= $this->lastVideoDtsTick) {
                $dts = $this->lastVideoDtsTick + 1;
            }
            $this->lastVideoDtsTick = $dts;
        }
        // B 帧合法 PTS<DTS（TS 容器原生支持），不能钳制
        // 非 B 模式 cts 来自源流（非负 tick），所以 pts >= dts 自动成立
        $pts = $dts + $ctsTicks;

        $skip = !is_resource($this->segmentWriter['handle']);
        $transcode = false;
        if (!$skip && $this->pipelineEncodedVariant === null) {
            $targetW = $this->profile['width'] > 0 ? $this->profile['width'] : $this->srcWidth;
            $targetH = $this->profile['height'] > 0 ? $this->profile['height'] : $this->srcHeight;
            $needTranscode = $this->srcInitialized && (
                ($this->profile['width'] > 0 && $this->srcWidth !== $this->profile['width']) ||
                ($this->profile['height'] > 0 && $this->srcHeight !== $this->profile['height']) ||
                $this->watermark !== null
            );

            if ($needTranscode) {
                $rawYuv = $this->decodeNaluToYuv($avcData);
                if ($rawYuv !== null) {
                    $scaledYuv = ($targetW !== $this->srcWidth || $targetH !== $this->srcHeight)
                        ? $this->scaler->scaleYUV420P($rawYuv, $this->srcWidth, $this->srcHeight, $targetW, $targetH)
                        : $rawYuv;
                    if ($this->watermark !== null) {
                        $scaledYuv = $this->applyWatermarkToFrame($scaledYuv, $targetW, $targetH, $this->watermark);
                    }
                    $this->encoder->setResolution($targetW, $targetH);
                    $this->encoder->setBitrate($this->profile['bitrate']);
                    $this->encoder->setFps($this->profile['fps']);
                    $this->encoder->setQp($this->profile['qp'] ?? 26);
                    // 异步开始编码：worker 运动估计与上一帧的主进程 CAVLC 重叠
                    $this->encoder->startFrame($scaledYuv, $isKeyFrame);
                    $transcode = true;
                }
                // 解码失败：退化为直通（transcode 保持 false）
            }
        }

        return [
            'relativeTime' => $relativeTime,
            'isKeyFrame' => $isKeyFrame,
            'avcData' => $avcData,
            'dts' => $dts,
            'pts' => $pts,
            'segmentSwitch' => $segmentSwitch,
            'transcode' => $transcode,
            'skip' => $skip,
            'encodedPayload' => $this->pipelineEncodedPayload,
            'encodedVariant' => $this->pipelineEncodedVariant,
        ];
    }

    /** finishFrame 取出在途编码结果并写 TS（其 CAVLC 与下一帧 worker 计算重叠） */
    private function emitPendingVideoFrame(array $job): void
    {
        if (!empty($job['skip'])) return;

        $outputData = $job['avcData'];
        $outputSpsPps = $this->spsPps;
        $isTranscoded = false;

        if ($job['encodedVariant'] !== null) {
            $variant = $job['encodedVariant'];
            $outputData = substr($job['encodedPayload'], $variant['offset'], $variant['length']);
            $outputSpsPps = '';
            $isTranscoded = true;
        } elseif (!empty($job['transcode'])) {
            $encodedNals = $this->encoder->finishFrame();
            $outputData = '';
            $outputSpsPps = '';
            foreach ($encodedNals as $nal) {
                $nalHeaderPos = $this->findNalHeaderPos($nal);
                // encoder 返回裸 NAL（无 start code）时按位置 0 处理，不能跳过丢帧
                if ($nalHeaderPos < 0) $nalHeaderPos = 0;
                $nalType = ord($nal[$nalHeaderPos]) & 0x1F;
                if ($nalType === 7 || $nalType === 8) {
                    $outputSpsPps .= "\x00\x00\x00\x01" . substr($nal, $nalHeaderPos);
                } else {
                    $outputData .= "\x00\x00\x00\x01" . substr($nal, $nalHeaderPos);
                }
            }
            if ($outputSpsPps !== '') {
                $this->spsPps = $outputSpsPps;
            }
            $isTranscoded = true;
        }

        $annexb = $isTranscoded ? $outputData : $this->avccToAnnexB($outputData);
        // 只在每个分片的第一帧前置SPS/PPS，避免重复导致FFmpeg解码错误
        // 注意：转码路径（encoded/transcode）的 IDR 帧已自带新编码参数的 SPS/PPS，
        // 不能再前置源流的 SPS/PPS——旧 SPS 声明了错误尺寸且无 B 帧重排声明，
        // 播放端会按"无 B 帧"判定 pts<dts 为非法时间戳
        if ($this->segmentFirstFrame) {
            $this->segmentFirstFrame = false;
            if ($isTranscoded) {
                // 本进程转码：SPS/PPS 已从 encoder 输出中抽离（outputData 仅含 slice），需前置回去
                if ($outputSpsPps !== '') $annexb = $outputSpsPps . $annexb;
            } else {
                // 直通路径：无 SPS 时前置源流 SPS/PPS（avcc 已转 AnnexB）
                $prefixNal = $outputSpsPps ?: $this->spsPps;
                if ($prefixNal !== '') {
                    $annexb = $prefixNal . $annexb;
                }
            }
        }

        // PTS 统一后移重排延迟（B 模式非 0），DTS 保持编码序网格不变
        $pes = $this->createPES(0xE0, $annexb, $job['pts'] + $this->tsPtsOffsetTicks, ($job['pts'] !== $job['dts']) ? $job['dts'] : null);
        // 视频首包携带PCR同步播放器
        $this->writeTSPackets($this->videoPid, $pes, true, $job['dts']);

        $this->currentSegmentLastTime = $job['relativeTime'];
        $this->segmentWriter['endTime'] = $job['relativeTime'];
    }

    private function replayQueuedAudio(): void
    {
        foreach ($this->queuedAudioTags as $queuedTag) {
            $this->handleAudioFrame($queuedTag);
        }
        $this->queuedAudioTags = [];
    }

    /** 冲刷最后一帧的延迟编码及排队音频（closeAllSegments 前必须调用） */
    public function flushPendingVideo(): void
    {
        if ($this->pendingVideoJob !== null) {
            $job = $this->pendingVideoJob;
            $this->pendingVideoJob = null;
            $this->emitPendingVideoFrame($job);
        }
        $this->replayQueuedAudio();
    }

    /**
     * 将Nalu解码为yuv
     * @param string $avcData
     * @return string|null
     */
    private function decodeNaluToYuv(string $avcData): ?string
    {
        /** 拆分nalu单元 */
        $nalUnits = $this->extractNalUnitsFromAVCC($avcData);
        
        // 将 SPS/PPS 与视频帧一起解码（解码器每次会重置状态）
        if ($this->srcSpsData !== '') {
            array_unshift($nalUnits, ['type' => 7, 'data' => $this->srcSpsData]);
        }
        if ($this->srcPpsData !== '') {
            array_unshift($nalUnits, ['type' => 8, 'data' => $this->srcPpsData]);
        }
        
        // 源解码始终执行去块滤波（编码侧本身不做去块；跳过去块会让缩放输入出现块边界混叠）
        $frame = $this->decoder->decode($nalUnits, false, true, false);
        if ($frame && !empty($frame['data'])) {
            return $frame['data'];
        }
        return null;
    }

    // 新增工具：AnnexB 裸流转NAL数组
    private function annexBToNalList(string $annexBuf): array
    {
        $splitRes = NalUtil::splitNalUnits($annexBuf);
        $out = [];
        foreach ($splitRes as $item) {
            $out[] = [
                'type' => $item['type'],
                'data' => $item['raw']
            ];
        }
        return $out;
    }

    /**
     * 处理音频帧
     * @param $tag
     * @return void
     */
    private function handleAudioFrame($tag): void
    {
        // 有在途视频帧时音频排队，随该帧 finish 后按原 tag 顺序回放，维持 TS 音视频交错
        if ($this->pendingVideoJob !== null) {
            $this->queuedAudioTags[] = $tag;
            return;
        }

        $raw = $tag->body ?? null;
        if ($raw === null || strlen($raw) < 2) return;

        $soundFormat = (ord($raw[0]) >> 4) & 0x0F;
        if ($soundFormat !== 10) return;

        $aacPacketType = ord($raw[1]);
        if ($aacPacketType === 0) {
            $asc = substr($raw, 2);
            if (strlen($asc) >= 2) {
                $this->audioSpecificConfig = $asc;
                $this->parseAudioSpecificConfig($asc);
            }
            return;
        }
        if ($aacPacketType !== 1) return;
        if ($this->baseTimestamp === null || $this->audioSpecificConfig === '') return;

        $aacRaw = substr($raw, 2);
        if ($aacRaw === '') return;

        // 剥离ADTS外层
        if (strlen($aacRaw) >= 2) {
            $b1 = ord($aacRaw[0]);
            $b2 = ord($aacRaw[1]);
            if ($b1 === 0xFF && ($b2 & 0xF0) === 0xF0) {
                $crcPresent = (ord($aacRaw[1]) & 0x01) === 0;
                $adtsLen = $crcPresent ? 9 : 7;
                if (strlen($aacRaw) > $adtsLen) {
                    $aacRaw = substr($aacRaw, $adtsLen);
                }
            }
        }

        $adts = $this->createADTSHeader(strlen($aacRaw));
        $payload = $adts . $aacRaw;
        $timestamp = method_exists($tag, 'getTime') ? $tag->getTime() : 0;
        $relativeTime = $timestamp - $this->baseTimestamp;

        $sampleRates = [96000, 88200, 64000, 48000, 44100, 32000, 24000, 22050, 16000, 12000, 11025, 8000, 7350];
        $sampleRate = $sampleRates[$this->samplingFrequencyIndex] ?? 44100;
        $frameDuration = (int)((1024 * 90000) / $sampleRate);

        if (is_resource($this->segmentWriter['handle'])) {
            if ($this->audioBasePts === null) {
                $this->audioBasePts = (int)($relativeTime * 90);
            }
            $pts = $this->audioBasePts + ($this->audioFrameCount * $frameDuration);
            $this->audioFrameCount++;

            // 音频与视频同步后移同一常量，保持 A/V 同步
            $pes = $this->createPES(0xC0, $payload, $pts + $this->tsPtsOffsetTicks, null);
            $this->writeTSPackets($this->audioPid, $pes, false, 0);
        }
    }

    private function parseAVCDecoderConfigurationRecord(string $data): void
    {
        $offset = 5;
        $numSps = ord($data[$offset]) & 0x1F;
        $offset++;
        $spsPpsBuf = '';

        for ($i = 0; $i < $numSps; $i++) {
            $len = unpack('n', substr($data, $offset, 2))[1];
            $offset += 2;
            $spsData = substr($data, $offset, $len);
            $offset += $len;
            // 去除防竞争字节再送入解码器
            $spsClean = NalUtil::removeEmulationPrevention($spsData);
            $spsPpsBuf .= "\x00\x00\x00\x01" . $spsData;

            if (!$this->srcInitialized) {
                $rbspSps = substr($spsClean, 1);
                $this->srcSpsData = $rbspSps;
                $this->decoder->decode([['type' => 7, 'data' => $rbspSps]], true);
                $this->srcWidth = $this->decoder->getWidth();
                $this->srcHeight = $this->decoder->getHeight();
                $this->srcInitialized = true;
            }
        }

        $numPps = ord($data[$offset]);
        $offset++;
        for ($i = 0; $i < $numPps; $i++) {
            $len = unpack('n', substr($data, $offset, 2))[1];
            $offset += 2;
            $ppsData = substr($data, $offset, $len);
            $offset += $len;

            $ppsClean = NalUtil::removeEmulationPrevention($ppsData);
            $spsPpsBuf .= "\x00\x00\x00\x01" . $ppsData;
            
            $rbspPps = substr($ppsClean, 1);
            $this->srcPpsData = $rbspPps;
            
            if (!$this->srcInitialized) {
                $this->decoder->decode([['type' => 8, 'data' => $rbspPps]]);
            }

        }

        $this->spsPps = $spsPpsBuf;
    }

    /**
     * AVCC(4字节长度前缀) 转 AnnexB(0001起始码)
     * 注意：AVCC 中的 NAL 单元本身已包含防竞争字节（EP3），此处只替换长度前缀为起始码，
     * 绝不能再次转义，否则 00 00 03 xx 会被写成 00 00 03 03 xx，导致 H264 码流非法。
     */
    private function avccToAnnexB(string $data): string
    {
        $offset = 0;
        $result = '';
        $totalLen = strlen($data);
        while ($offset + 4 <= $totalLen) {
            $nalSize = unpack('N', substr($data, $offset, 4))[1];
            $offset += 4;
            if ($offset + $nalSize > $totalLen) break;
            $result .= "\x00\x00\x00\x01" . substr($data, $offset, $nalSize);
            $offset += $nalSize;
        }
        return $result;
    }


    /**
     * 从avc中拆分nalu，裸数据 = 起始码 + NALU
     * @param string $data
     * @return array
     */
    private function extractNalUnitsFromAVCC(string $data): array
    {
        $list = [];
        $offset = 0;
        $totalLen = strlen($data);
        while ($offset + 4 <= $totalLen) {
            $nalSize = unpack('N', substr($data, $offset, 4))[1];
            $offset += 4;
            if ($offset + $nalSize > $totalLen) break;
            $nalRaw = substr($data, $offset, $nalSize);
            $offset += $nalSize;
            // 解码前移除防竞争字节
            $nalClean = NalUtil::removeEmulationPrevention($nalRaw);
            $type = ord($nalClean[0]) & 0x1F;
            $rbspData = substr($nalClean, 1);
            $list[] = ['type' => $type, 'data' => $rbspData, 'raw' => $nalClean];
        }
        return $list;
    }

    /**
     * 解析AAC ASC配置
     */
    private function parseAudioSpecificConfig(string $asc): void
    {
        if (strlen($asc) < 2) return;
        $bits = '';
        foreach (str_split($asc) as $b) {
            $val = ord($b);
            for ($i = 7; $i >= 0; $i--) {
                $bits .= (($val >> $i) & 1) ? '1' : '0';
            }
        }
        $pos = 0;
        $maxBit = strlen($bits);

        $audioObjType = (int)bindec(substr($bits, $pos, 5));
        $pos += 5;
        $this->sbrPresent = false;
        $this->extensionSamplingIndex = null;

        if ($audioObjType === 5 || $audioObjType === 29) {
            $this->sbrPresent = true;
            if ($pos + 4 > $maxBit) return;
            $this->extensionSamplingIndex = (int)bindec(substr($bits, $pos, 4));
            $pos += 4;
            if ($this->extensionSamplingIndex === 0x0F) $pos += 24;
            if ($pos + 5 > $maxBit) return;
            $audioObjType = (int)bindec(substr($bits, $pos, 5));
            $pos += 5;
        }
        $this->audioObjectType = $audioObjType;

        if ($pos + 4 > $maxBit) return;
        $this->samplingFrequencyIndex = (int)bindec(substr($bits, $pos, 4));
        $pos += 4;
        if ($this->samplingFrequencyIndex === 0x0F) $pos += 24;

        if ($pos + 4 > $maxBit) return;
        $this->channelConfiguration = (int)bindec(substr($bits, $pos, 4));
    }

    /**
     * 生成ADTS AAC头
     */
    private function createADTSHeader(int $aacRawLen): string
    {
        $profile = $this->audioObjectType - 1;
        if ($profile < 0) $profile = 1;
        $freqIdx = $this->samplingFrequencyIndex;
        if ($freqIdx < 0 || $freqIdx > 11) $freqIdx = 4;
        $chCfg = $this->channelConfiguration;
        if ($chCfg < 0 || $chCfg > 7) $chCfg = 2;
        $frameTotalLen = $aacRawLen + 7;

        return pack('CCCCCCC',
            0xFF, 0xF1,
            (($profile & 0x03) << 6) | (($freqIdx & 0x0F) << 2) | (($chCfg >> 2) & 0x01),
            (($chCfg & 0x03) << 6) | (($frameTotalLen >> 11) & 0x03),
            ($frameTotalLen >> 3) & 0xFF,
            (($frameTotalLen & 0x07) << 5) | 0x1F,
            0xFC
        );
    }

    /**
     * 构建PES包
     */
    private function createPES(int $streamId, string $payload, int $pts, ?int $dts): string
    {
        $ptsDtsFlag = ($dts !== null && $dts !== $pts) ? 0xC0 : 0x80;
        $tsBuf = $this->encodeTimestamp(0x02, $pts);
        if ($dts !== null && $dts !== $pts) {
            $tsBuf .= $this->encodeTimestamp(0x01, $dts);
        }
        $headerLen = strlen($tsBuf);
        $pesBodyLen = strlen($payload) + 3 + $headerLen;
        $pesBodyLen = $pesBodyLen > 0xFFFF ? 0 : $pesBodyLen;

        return "\x00\x00\x01"
            . chr($streamId)
            . pack('n', $pesBodyLen)
            . "\x80"
            . chr($ptsDtsFlag)
            . chr($headerLen)
            . $tsBuf
            . $payload;
    }

    /**
     * PTS/DTS 5字节时间戳编码
     */
    private function encodeTimestamp(int $type, int $ts): string
    {
        $ts = $ts & 0x1FFFFFFFF;
        return pack('CCCCC',
            (($type << 4) & 0xF0) | ((($ts >> 30) & 0x07) << 1) | 1,
            ($ts >> 22) & 0xFF,
            ((($ts >> 15) & 0x7F) << 1) | 1,
            ($ts >> 7) & 0xFF,
            (($ts & 0x7F) << 1) | 1
        );
    }

    /**
     * PCR 6字节编码
     */
    private function encodePCR(int $pcr): string
    {
        return pack('CCCCCC',
            ($pcr >> 25) & 0xFF,
            ($pcr >> 17) & 0xFF,
            ($pcr >> 9) & 0xFF,
            ($pcr >> 1) & 0xFF,
            (($pcr & 1) << 7) | 0x7E,
            0x00
        );
    }

    /**
     * 写入PAT表
     */
    private function writePAT(): void
    {
        $section = "\x00\xB0\x0D\x00\x01\xC1\x00\x00\x00\x01" . pack('n', 0xE000 | $this->pmtPid);
        $section .= pack('N', $this->crc32mpeg($section));
        $payload = "\x00" . $section;
        $this->writeTSPacketsRaw(0x0000, $payload);
    }

    /**
     * 写入PMT表
     */
    private function writePMT(): void
    {
        $body = "\x00\x01\xC1\x00\x00"
            . pack('n', 0xE000 | $this->videoPid) . "\xF0\x00"
            . "\x1B" . pack('n', 0xE000 | $this->videoPid) . "\xF0\x00"
            . "\x0F" . pack('n', 0xE000 | $this->audioPid) . "\xF0\x00";
        $sectLen = strlen($body) + 4;
        $section = "\x02" . chr(0xB0 | (($sectLen >> 8) & 0x0F)) . chr($sectLen & 0xFF) . $body;
        $section .= pack('N', $this->crc32mpeg($section));
        $payload = "\x00" . $section;
        $this->writeTSPacketsRaw($this->pmtPid, $payload);
    }

    /**
     * 无自适应字段TS包写入（PAT/PMT专用）
     */
    private function writeTSPacketsRaw(int $pid, string $payload): void
    {
        $cc = &$this->continuityCounters[$pid];
        if (!isset($cc)) $cc = 0;
        $offset = 0;
        $totalPayload = strlen($payload);
        $first = true;
        while ($offset < $totalPayload) {
            $rem = $totalPayload - $offset;
            $ts = "\x47"
                . chr((($first ? 1 : 0) << 6) | (($pid >> 8) & 0x1F))
                . chr($pid & 0xFF)
                . chr(0x10 | ($cc & 0x0F));
            $cc = ($cc + 1) & 0x0F;
            $chunk = substr($payload, $offset, min($rem, 184));
            $ts .= $chunk;
            $ts = str_pad($ts, 188, "\xFF");
            fwrite($this->segmentWriter['handle'], $ts);
            $offset += strlen($chunk);
            $first = false;
        }
    }

    /**
     * 标准PES TS打包，支持PCR自适应字段
     */
    private function writeTSPackets(int $pid, string $payload, bool $writePCR = false, int $pcr = 0): void
    {
        $cc = &$this->continuityCounters[$pid];
        if (!isset($cc)) $cc = 0;
        $offset = 0;
        $totalPayload = strlen($payload);
        $first = true;
        while ($offset < $totalPayload) {
            $rem = $totalPayload - $offset;
            $tsHeader = "\x47"
                . chr((($first ? 1 : 0) << 6) | (($pid >> 8) & 0x1F))
                . chr($pid & 0xFF);
            $adaptField = '';
            $adaptCtrl = 1;

            // 首包写入PCR
            if ($writePCR && $first) {
                $adaptCtrl = 3;
                $adaptField = chr(7) . chr(0x10) . $this->encodePCR($pcr);
            }
            $payloadSpace = 188 - 4 - strlen($adaptField);
            // 填充自适应字段
            if ($rem < $payloadSpace) {
                $adaptCtrl = 3;
                $stuffLen = $payloadSpace - $rem;
                if ($adaptField === '') {
                    if ($stuffLen >= 2) {
                        $adaptField = chr($stuffLen - 1) . chr(0x00) . str_repeat("\xFF", $stuffLen - 2);
                    } else {
                        // stuffing == 1, adaptation field is just the length byte (0)
                        $adaptField = chr(0);
                    }
                } else {
                    // 有 PCR 时，扩展 adaptation field
                    $newAdapLen = min(255, ord($adaptField[0]) + $stuffLen);
                    $adaptField = chr($newAdapLen) . substr($adaptField, 1) . str_repeat("\xFF", $stuffLen);
                }
                $payloadSpace = 188 - 4 - strlen($adaptField);
            }

            $tsHeader .= chr(($adaptCtrl << 4) | ($cc & 0x0F));
            $cc = ($cc + 1) & 0x0F;
            $tsPacket = $tsHeader . $adaptField . substr($payload, $offset, $payloadSpace);
            $tsPacket = str_pad($tsPacket, 188, "\xFF");
            fwrite($this->segmentWriter['handle'], $tsPacket);
            $offset += $payloadSpace;
            $first = false;
        }
    }

    /**
     * 新建TS分片
     */
    private function startSegment(): void
    {
        $writer = &$this->segmentWriter;
        $writer['sequence']++;
        $this->continuityCounters = [];
        $filePath = $this->outputDir . "/segment_{$writer['sequence']}.ts";
        $writer['handle'] = fopen($filePath, 'wb');
        // 首个分片开启时接管 index.m3u8 追加句柄，并清空上一次运行的残留条目
        $this->openPlaylistHandle($writer['sequence'] === 1);
        // 分片头部写入PAT/PMT，兼容播放器
        $this->writePAT();
        $this->writePMT();

        $this->audioFrameCount = 0;
    }

    /**
     * 关闭分片并向 index.m3u8 追加条目
     */
    private function closeSegment(int $endTime = 0): void
    {
        $writer = &$this->segmentWriter;
        if (!is_resource($writer['handle'])) return;
        fflush($writer['handle']);
        fclose($writer['handle']);
        $writer['handle'] = null;

        $endTs = $endTime ?: $this->currentSegmentLastTime;
        $durSec = max(0.001, round(($endTs - $this->segmentStartTime) / 1000.0, 3));
        // 单次 fwrite 写入完整条目块（EXTINF + URI），避免读到半行
        if (is_resource($this->playlistHandle)) {
            fwrite($this->playlistHandle, "#EXTINF:" . number_format($durSec, 3, '.', '') . ",\nsegment_{$writer['sequence']}.ts\n");
            fflush($this->playlistHandle);
        }
    }

    /**
     * 关闭分片
     */
    private function closeAllSegments(): void
    {
        if (is_resource($this->segmentWriter['handle'])) {
            $this->closeSegment($this->segmentWriter['endTime']);
        }
        $this->addEndList();
    }

    /**
     * MPEG2 TS CRC32（修复无符号溢出BUG）
     */
    private function crc32mpeg(string $data): int
    {
        $crc = 0xFFFFFFFF;
        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            $crc ^= ord($data[$i]) << 24;
            for ($j = 0; $j < 8; $j++) {
                $crc = ($crc & 0x80000000) ? (($crc << 1) ^ 0x04C11DB7) : ($crc << 1);
                $crc &= 0xFFFFFFFF;
            }
        }
        return $crc;
    }

    /**
     * 初始化空m3u8：仅当文件不存在时写占位 header（正式的 header 在首个分片开启时定稿）
     */
    private function ensureInitialPlaylist(): void
    {
        $m3u8Path = $this->outputDir . '/index.m3u8';
        if (!file_exists($m3u8Path)) {
            file_put_contents($m3u8Path, $this->playlistHeader());
        }
    }

    /**
     * 开流前重写 header（此时尚无任何分片，可安全截断）
     */
    private function resetPlaylists(): void
    {
        if (is_resource($this->playlistHandle)) return;
        file_put_contents($this->outputDir . '/index.m3u8', $this->playlistHeader());
    }

    /**
     * m3u8 header：追加写无法回改，TARGETDURATION 一次性定死并留余量
     * （分片时长取首个满足 elapsed >= segmentDuration 的关键帧，会随源关键帧间隔上浮）
     */
    private function playlistHeader(): string
    {
        $target = max(1, (int)ceil($this->segmentDuration) + 2);
        return "#EXTM3U\n"
            . "#EXT-X-VERSION:3\n"
            . "#EXT-X-TARGETDURATION:{$target}\n"
            . "#EXT-X-MEDIA-SEQUENCE:1\n"
            . "#EXT-X-INDEPENDENT-SEGMENTS\n";
    }

    /**
     * 取得 index.m3u8 追加写句柄；$reset 为真时截断旧内容重写 header
     */
    private function openPlaylistHandle(bool $reset): void
    {
        if (!$reset && is_resource($this->playlistHandle)) return;
        $path = $this->outputDir . '/index.m3u8';
        if (is_resource($this->playlistHandle)) fclose($this->playlistHandle);
        if ($reset || !file_exists($path)) {
            file_put_contents($path, $this->playlistHeader());
        }
        $handle = @fopen($path, 'ab');
        if ($handle === false) throw new \RuntimeException("无法打开索引文件写入: {$path}");
        $this->playlistHandle = $handle;
    }

    /**
     * 末尾追加ENDLIST标记并关闭句柄；重复调用幂等
     */
    private function addEndList(): void
    {
        if (is_resource($this->playlistHandle)) {
            fwrite($this->playlistHandle, "#EXT-X-ENDLIST\n");
            fflush($this->playlistHandle);
            fclose($this->playlistHandle);
            $this->playlistHandle = null;
            return;
        }
        // 无句柄（仅做收尾的实例）：直接对文件追加，已存在 ENDLIST 则跳过
        $path = $this->outputDir . '/index.m3u8';
        if (!file_exists($path)) return;
        $buf = file_get_contents($path);
        if ($buf === false || strpos($buf, '#EXT-X-ENDLIST') !== false) return;
        file_put_contents($path, rtrim($buf, "\n") . "\n#EXT-X-ENDLIST\n");
    }

    /**
     * 检测并跳过 H.264 裸流（Annex-B 格式）开头的起始码（Start Code）
     * @param string $nal
     * @return int
     */
    private function findNalHeaderPos(string $nal): int
    {
        $len = strlen($nal);
        if ($len < 4) return -1;
        if (substr($nal, 0, 4) === "\x00\x00\x00\x01") return 4;
        if ($len >= 3 && substr($nal, 0, 3) === "\x00\x00\x01") return 3;
        return -1;
    }

    // FLV工具解析辅助函数
    private function videoFrameDataRead(string $data): ?array
    {
        if (strlen($data) < 1) return null;
        $b0 = ord($data[0]);
        return [
            'frameType' => $b0 >> 4,
            'codecId' => $b0 & 0x0F,
            'data' => substr($data, 1)
        ];
    }

    private function avcPacketRead(string $packet): ?array
    {
        if (strlen($packet) < 1) return null;
        $avcPacketType = ord($packet[0]);
        
        // AVC Sequence Header: AVCPacketType(1) + 保留(3) + AVCDecoderConfigurationRecord(1+)
        // AVCPacketType=0 后面没有 CompositionTime，跳过 4 字节
        if ($avcPacketType === 0) {
            return [
                'avcPacketType' => $avcPacketType,
                'compositionTime' => 0,
                'data' => substr($packet, 4)
            ];
        }
        
        if (strlen($packet) < 4) return null;
        $cts = (ord($packet[1]) << 16) | (ord($packet[2]) << 8) | ord($packet[3]);
        if ($cts & 0x800000) $cts -= 0x1000000;
        return [
            'avcPacketType' => $avcPacketType,
            'compositionTime' => $cts,
            'data' => substr($packet, 4)
        ];
    }
}