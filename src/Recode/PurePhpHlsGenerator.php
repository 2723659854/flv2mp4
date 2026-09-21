<?php

namespace Xiaosongshu\Flv2mp4\Recode;

use Xiaosongshu\Flv2mp4\Codec\H264Decoder;
use Xiaosongshu\Flv2mp4\Codec\H264Encoder;
use Xiaosongshu\Flv2mp4\Codec\NalUtil;
use Xiaosongshu\Flv2mp4\Codec\Scaler\VideoScaler;
use Xiaosongshu\Flv2mp4\Flv\FlvParse;

/**
 * @purpose flv转hls多码率
 * @author yanglong
 * @time 2026年7月23日14:05:53
 * @note 本转码器目前仅支持baseline profile
 */
class PurePhpHlsGenerator
{

    /** 切片分割时间 3秒 */
    private int $segmentDuration = 3;
    private int $videoPid = 0x100;
    private int $audioPid = 0x101;
    private int $pmtPid = 0x1000;

    /** 视频质量等级 baseline ,main ,high profile */
    private array $profiles;
    private string $outputDir;
    private array $segmentWriters = [];
    private array $segmentDurations = [];
    private array $spsPpsData = [];
    private array $continuityCounters = [];

    private ?int $baseTimestamp = null;
    private array $segmentStartTimes = [];
    private array $currentSegmentLastTimes = [];

    private string $audioSpecificConfig = '';
    private int $audioObjectType = 2;
    private int $samplingFrequencyIndex = 4;
    private int $channelConfiguration = 2;
    private bool $sbrPresent = false;
    private ?int $extensionSamplingIndex = null;

    private array $audioFrameCounts = [];
    private array $audioBasePts = [];

    /** 解码器 */
    private H264Decoder $decoder;

    /** 编码器（每个profile独立一个，避免参考帧互相污染） */
    private array $encoders = [];

    /** 视频尺寸缩放器 */
    private VideoScaler $scaler;
    // 多分辨率解码帧缓存，避免多profile覆盖
    private array $decodedFrameCache = [];
    private string $frameCacheKey = '';

    private int $srcWidth = 0;
    private int $srcHeight = 0;
    private bool $srcInitialized = false;

    private array $profileWatermark = [];

    /** 最大处理帧数 调试用 */
    private ?int $maxFrames = null;
    private array $videoFrameCounts = [];
    private array $lastDts = [];
    private array $segmentFirstFrame = [];
    private string $srcSpsData = '';
    private string $srcPpsData = '';
    private bool $multi;
    private int $decodeWorkers;
    /** 单 profile 模式（与 recode 同形的一维配置）：分片/索引直接输出到 outputDir 根部，不生成 master.m3u8 */
    private bool $singleProfile = false;
    /** 片 worker 模式：只写 segment_N.ts.tmp，不碰 m3u8（由片池协调进程顺序发布） */
    private bool $writePlaylists = true;
    /** 片任务模式：片边界由协调进程规划，任务内禁止按 3s 阈值自动切换分片 */
    private bool $segmentTaskMode = false;
    /** 片任务首帧强制 IDR（Task7/FR-3）：非源关键帧边界靠检查点续解，首输出帧必须强制成 IDR */
    private bool $segmentForceKey = false;
    private ?array $pipelineVariants = null;
    private string $pipelineYuvPayload = '';

    // 帧级双缓冲：一个已 startFrame（所有 profile）的视频帧延后到下一帧到达后再 finish
    private ?array $pendingVideoJob = null;
    /** @var array<int,object> 在途视频帧之后到达的音频 tag，待该帧写出后按原顺序回放 */
    private array $queuedAudioTags = [];

    const VIDEO_FRAME_TYPE_KEY_FRAME = 1;
    const AVC_PACKET_TYPE_SEQUENCE_HEADER = 0;
    const AVC_PACKET_TYPE_NALU = 1;

    /**
     * 转码器初始化
     *
     * 支持两种配置形态：
     * 1. 一维单路配置（与 FlvRecoder/Mp4Recoder 同形，推荐）：
     *    ['width'=>..,'height'=>..,'bitrate'=>..,'fps'=>..,'qp'=>..,'decode_workers'=>6,'motionWorkers'=>6,...]
     *    分片与 index.m3u8 直接输出到 outputDir 根部，不生成 master.m3u8；
     *    decode_workers 从配置读取，避免与 recode 入口配置不一致。
     * 2. 多码率 profile map（向后兼容）：['360p' => [...], '240p' => [...]]
     *    每路输出到 outputDir/{name}/ 子目录并生成 master.m3u8；
     *    单元素 map 且键名为空字符串时视为单路模式（worker 进程据此还原布局）。
     *
     * @param array $configOrProfiles 单路一维配置或多码率 profile map
     * @param string $outputDir 输出目录
     * @param bool $multi 快速重编码总开关：true=多进程快速路径，false=原始串行路径（默认）
     * @param int $decodeWorkers 多进程解码worker数（仅多码率形态使用；单路形态取配置中的 decode_workers）
     */
    public function __construct(array $configOrProfiles, string $outputDir = '', bool $multi = false, int $decodeWorkers = 6, bool $writePlaylists = true)
    {
        $this->writePlaylists = $writePlaylists;
        // 一维配置：所有值均为标量；profile map：值均为数组
        if ($configOrProfiles !== [] && count(array_filter($configOrProfiles, 'is_array')) === 0) {
            $this->singleProfile = true;
            // 幂等归一化：worker 子进程以 false 再次构造时，已填键不会被覆盖
            $configOrProfiles = TranscodeOptions::normalize($configOrProfiles, $multi);
            $decodeWorkers = (int)$configOrProfiles['decode_workers'];
            $this->profiles = ['' => $configOrProfiles];
        } else {
            foreach ($configOrProfiles as $name => $profile) {
                $configOrProfiles[$name] = TranscodeOptions::normalize($profile, $multi);
            }
            $this->profiles = $configOrProfiles;
            $this->singleProfile = count($this->profiles) === 1 && array_key_first($this->profiles) === '';
        }
        $this->outputDir = rtrim($outputDir, '/');
        $this->multi = $multi;
        $this->decodeWorkers = $decodeWorkers;

        $this->decoder = new H264Decoder();
        $this->scaler = new VideoScaler();

        foreach ($this->profiles as $name => $profile) {
            $this->encoders[$name] = new H264Encoder();
            $this->encoders[$name]->motionWorkers = max(1, (int)$profile['motionWorkers']);
            TranscodeOptions::applyEncoder($this->encoders[$name], $profile);
            $dir = $this->profileDir($name) . '/';
            if (!is_dir($dir)) mkdir($dir, 0777, true);

            $this->segmentWriters[$name] = ['sequence' => 0, 'handle' => null, 'startTime' => 0, 'endTime' => 0];
            $this->segmentDurations[$name] = [];
            $this->spsPpsData[$name] = '';
            $this->continuityCounters[$name] = [];
            $this->segmentStartTimes[$name] = 0;
            $this->currentSegmentLastTimes[$name] = 0;
            $this->audioFrameCounts[$name] = 0;
            $this->audioBasePts[$name] = null;
            $this->videoFrameCounts[$name] = 0;
            $this->lastDts[$name] = -1;
            $this->segmentFirstFrame[$name] = true;

            if (!empty($profile['watermark']) && !empty($profile['watermark_file'])) {
                $this->profileWatermark[$name] = $this->loadWatermarkFile($profile['watermark_file']);
            } else {
                $this->profileWatermark[$name] = null;
            }
        }

        /** 初始化空m3u8（片 worker 模式跳过：m3u8 由协调进程顺序发布） */
        if ($this->writePlaylists) $this->ensureInitialPlaylist();
    }

    /** 单路模式文件直接落在输出目录根部；多码率模式落在 {outputDir}/{profile}/ 子目录 */
    private function profileDir(string $profile): string
    {
        return $this->singleProfile ? $this->outputDir : "{$this->outputDir}/{$profile}";
    }

    /**
     * 预热各 profile 的运动估计子进程（输出 worker 启动时调用，
     * 让 PHP 冷启动与解码首 GOP 并行发生）
     */
    public function warmupMotionWorkers(): void
    {
        foreach ($this->encoders as $encoder) {
            $encoder->warmupMotionWorkers();
        }
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
     * @param int $segmentDuration
     * @return void
     */
    public function setSegmentDuration(int $segmentDuration = 3)
    {
        $this->segmentDuration = $segmentDuration;
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
            $firstProfile = reset($this->profiles);
            if (!empty($firstProfile['segment_pool'])) {
                (new HlsSegmentPipelineClient($this->profiles, $this->outputDir, $this->maxFrames))->process($flvFile);
                return;
            }
            $wavefront = (bool)($firstProfile['decode_wavefront'] ?? false);
            (new HlsPipelineClient($this->profiles, $this->outputDir, $this->maxFrames, $this->decodeWorkers, $wavefront))->process($flvFile);
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

        // 冲刷双缓冲中最后一帧及排队音频，保证分片完整后再关闭
        $this->flushPendingVideo();
        $this->closeAllSegments();
        if (count($this->profiles) > 1) $this->generateMasterPlaylist();
        echo "Done! Processed {$frameCount} frames\n";
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
        };
        if (!empty($metadata['decoded'])) {
            $bodyLength = unpack('N', substr($payload, 0, 4))[1];
            $tag->body = substr($payload, 4, $bodyLength);
            $this->pipelineVariants = $metadata['variants'];
            $this->pipelineYuvPayload = substr($payload, 4 + $bodyLength);
        }
        // 抽帧丢弃帧：decoder 已解码维持参考链，输出端不编码、不写 TS，时间轴由保留帧推进
        if ($tag->tagType === 9 && !empty($metadata['drop'])) {
            $this->pipelineVariants = null;
            $this->pipelineYuvPayload = '';
            return;
        }
        if ($tag->tagType === 9) $this->handleVideoFrame($tag);
        elseif ($tag->tagType === 8) $this->handleAudioFrame($tag);
        $this->pipelineVariants = null;
        $this->pipelineYuvPayload = '';
    }

    public function finishPipelineOutput(bool $generateMasterPlaylist = true): void
    {
        // 冲刷双缓冲中最后一帧及排队音频，保证分片完整后再关闭
        $this->flushPendingVideo();
        $this->closeAllSegments();
        if ($generateMasterPlaylist && $this->writePlaylists) $this->generateMasterPlaylist();
    }

    /**
     * 片 worker 入口：执行单个自包含片任务（片间无参考共享，片首强制 IDR+SPS/PPS）。
     *
     * 任务结构：
     *  - seq：全局片序号（从 1 起）；file：源 FLV 绝对路径
     *  - asc/avcc：AAC AudioSpecificConfig / AVCDecoderConfigurationRecord 二进制
     *  - events：按源顺序排列的紧凑事件（worker 按偏移随机读源文件，零扫描歧义；
     *    isKey/cts 由 worker 从 tag body 自解析，无需随事件下发）
     *    时间轴为全局连续轴（基点=首 IDR 源时间戳，跨片不归零，与串行路径切片一致）：
     *    音频 [0, tagOffset, tagLen, relMs]（全局相对源毫秒）
     *    视频 [1, tagOffset, tagLen, outMs, drop]（全局均匀网格输出毫秒；drop=1 时该值不用）
     *
     * 产物：每 profile 写 segment_{seq}.ts.tmp（不更新 m3u8，由协调进程原子改名后顺序发布）。
     *
     * @param callable(int,string):void|null $onCheckpoint 末帧解码完成、编码冲刷前回调（参数：seq, base64 cp）；
     *        片 worker 据此把检查点提前回传协调端（Task8/B1：解码(n+1)∥编码(n) 流水）
     * @return array{seq:int,endOutMs:int,cp:string} 片末视频帧相对输出时间轴毫秒（协调进程算时长用）
     */
    public function runSegmentTask(array $task, ?callable $onCheckpoint = null): array
    {
        $this->resetRuntimeState();
        $this->segmentTaskMode = true;
        $this->baseTimestamp = 0;
        if (($task['asc'] ?? '') !== '') {
            // 与 handleAudioFrame 收到 AAC sequence header 同构：属性+解析同时就位，
            // 否则后续音频帧会被 audioSpecificConfig==='' 守卫整体丢弃
            $this->audioSpecificConfig = $task['asc'];
            $this->parseAudioSpecificConfig($task['asc']);
        }
        if (($task['avcc'] ?? '') !== '') $this->parseAVCDecoderConfigurationRecord($task['avcc']);

        // 非片 1：导入上一片末帧的 H264 解码检查点（DPB/frameNum），
        // 使本片能从非源 IDR 边界帧直接续解（Task7/FR-3，检查点机制复用 Task4 波前成果）
        $cpB64 = (string)($task['cp'] ?? '');
        if ($cpB64 !== '') {
            $cp = @unserialize(base64_decode($cpB64));
            if (!is_array($cp)) throw new \RuntimeException('片任务解码检查点无效');
            $this->decoder->importCheckpoint($cp);
        }

        $seq = (int)$task['seq'];
        foreach ($this->profiles as $name => $_) {
            $this->startSegment($name, $seq, '.tmp');
            $this->segmentStartTimes[$name] = 0;
        }

        // 本片首个保留帧强制编码为 IDR（不看源 frameType）；SPS/PPS 前置由 segmentFirstFrame 完成
        $this->segmentForceKey = true;

        $handle = @fopen($task['file'], 'rb');
        if ($handle === false) throw new \RuntimeException("片 worker 无法打开源文件: {$task['file']}");
        try {
            foreach ($task['events'] as $ev) {
                $isVideo = (int)$ev[0] === 1;
                fseek($handle, (int)$ev[1]);
                $raw = $this->readTagRaw($handle, (int)$ev[2]);
                $dataSize = unpack('N', "\x00" . substr($raw, 1, 3))[1];
                $body = substr($raw, 11, $dataSize);
                if ($isVideo) {
                    // [1, off, len, outRelMs, drop]
                    $tag = $this->makeSegmentTag(9, $body, (int)$ev[3], 0);
                    if (!empty($ev[4])) {
                        // 抽掉的帧仍须本地解码维持参考链，但不缩放/编码/写 TS
                        $videoData = $this->videoFrameDataRead($body);
                        if ($videoData) {
                            $avc = $this->avcPacketRead($videoData['data']);
                            if ($avc && $avc['avcPacketType'] === self::AVC_PACKET_TYPE_NALU) $this->decodeNaluToYuv($avc['data']);
                        }
                    } else {
                        $this->handleVideoFrame($tag);
                    }
                } else {
                    // [0, off, len, relMs]
                    $this->handleAudioFrame($this->makeSegmentTag(8, $body, (int)$ev[3], (int)$ev[3]));
                }
            }
        } finally {
            fclose($handle);
        }

        // 导出本片末帧【解码后】的检查点（此时所有源帧均已解码，末保留帧尚在双缓冲中待编码；
        // 检查点只含解码器状态，与编码冲刷无关），供协调进程链接下一片非 IDR 起点
        $cpOut = '';
        if ($this->srcInitialized) {
            $cpOut = base64_encode(serialize($this->decoder->exportCheckpoint(
                $this->srcSpsData !== '' ? $this->srcSpsData : null,
                $this->srcPpsData !== '' ? $this->srcPpsData : null
            )));
        }
        // Task8/B1：解码已全部完成、编码冲刷尚未开始——立即把 cp 交回上层（worker 提前回帧），
        // 下一片可在本片冲刷编码期间并行解码
        if ($onCheckpoint !== null) $onCheckpoint($seq, $cpOut);

        // 冲刷末帧双缓冲与排队音频，保证片完整
        $this->flushPendingVideo();
        $endOutMs = 0;
        foreach ($this->profiles as $name => $_) {
            $endOutMs = max($endOutMs, $this->currentSegmentLastTimes[$name]);
            $this->closeSegment($name, 0, false);
        }
        return ['seq' => $seq, 'endOutMs' => (int)$endOutMs, 'cp' => $cpOut];
    }

    /** 片任务 tag：视频取计划输出时间轴，音频取片内源相对时间轴 */
    private function makeSegmentTag(int $tagType, string $body, int $videoOutMs, int $audioRelMs): object
    {
        return new class($tagType, $body, $videoOutMs, $audioRelMs) {
            public int $tagType;
            public string $body;
            public function __construct(int $tagType, string $body, private int $videoOutMs, private int $audioRelMs)
            {
                $this->tagType = $tagType;
                $this->body = $body;
            }
            public function getTime(): int
            {
                return $this->tagType === 9 ? $this->videoOutMs : $this->audioRelMs;
            }
        };
    }

    /** 按 FLV 布局读取完整 tag（11B header + body；4B PreviousTagSize 由调用方 len 控制可不读满） */
    private function readTagRaw($handle, int $tagLen): string
    {
        $raw = '';
        while (strlen($raw) < $tagLen) {
            $chunk = fread($handle, $tagLen - strlen($raw));
            if ($chunk === false || $chunk === '') throw new \RuntimeException('片 worker 读取源 FLV tag 失败');
            $raw .= $chunk;
        }
        return $raw;
    }

    /** 片任务间重置全部运行态（decoder/encoders/scaler 跨片复用，IDR 自然刷新参考链） */
    private function resetRuntimeState(): void
    {
        foreach ($this->profiles as $name => $_) {
            $this->segmentWriters[$name] = ['sequence' => 0, 'handle' => null, 'startTime' => 0, 'endTime' => 0];
            $this->segmentDurations[$name] = [];
            $this->spsPpsData[$name] = '';
            $this->continuityCounters[$name] = [];
            $this->segmentStartTimes[$name] = 0;
            $this->currentSegmentLastTimes[$name] = 0;
            $this->audioFrameCounts[$name] = 0;
            $this->audioBasePts[$name] = null;
            $this->videoFrameCounts[$name] = 0;
            $this->lastDts[$name] = -1;
            $this->segmentFirstFrame[$name] = true;
        }
        $this->baseTimestamp = null;
        $this->srcWidth = 0;
        $this->srcHeight = 0;
        $this->srcInitialized = false;
        $this->srcSpsData = '';
        $this->srcPpsData = '';
        $this->audioSpecificConfig = '';
        $this->audioObjectType = 2;
        $this->samplingFrequencyIndex = 4;
        $this->channelConfiguration = 2;
        $this->sbrPresent = false;
        $this->extensionSamplingIndex = null;
        $this->decodedFrameCache = [];
        $this->frameCacheKey = '';
        $this->segmentForceKey = false;
        $this->pendingVideoJob = null;
        $this->queuedAudioTags = [];
        $this->pipelineVariants = null;
        $this->pipelineYuvPayload = '';
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

        $isKeyFrame = ($videoData['frameType'] === self::VIDEO_FRAME_TYPE_KEY_FRAME);
        if ($this->segmentTaskMode && $this->segmentForceKey) {
            // Task7/FR-3：非源 IDR 片边界——解码检查点已续上参考链，本片首输出帧强制编码 IDR
            $isKeyFrame = true;
            $this->segmentForceKey = false;
        }
        $timestamp = method_exists($tag, 'getTime') ? $tag->getTime() : 0;

        // 首关键帧初始化时间基准
        if ($this->baseTimestamp === null) {
            if (!$isKeyFrame) return;
            $this->baseTimestamp = $timestamp;
            foreach ($this->profiles as $name => $profile) {
                $this->segmentStartTimes[$name] = 0;
                $this->startSegment($name);
            }
        }

        $job = $this->prepareVideoJob($avc['data'], $isKeyFrame, $timestamp - $this->baseTimestamp, $avc['compositionTime'] ?? 0);
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
        foreach ($job['segmentSwitches'] as $name => $switch) {
            if (!$switch) continue;
            $this->closeSegment($name, $job['relativeTime']);
            $this->audioFrameCounts[$name] = 0;
            $this->audioBasePts[$name] = (int)($job['relativeTime'] * 90);
            $this->lastDts[$name] = -1;
            $this->segmentStartTimes[$name] = $job['relativeTime'];
            $this->segmentFirstFrame[$name] = true;
            $this->startSegment($name);
        }
        if (in_array(true, $job['segmentSwitches'], true)) {
            // 切换分片清空帧缓存（原实现语义：边界处释放）
            $this->decodedFrameCache = [];
        }

        $this->pendingVideoJob = $job;
    }

    /**
     * 每帧预处理：解码/缩放/水印 + 各 profile startFrame，并计算分片切换决策（不执行切换）。
     */
    private function prepareVideoJob(string $avcData, bool $isKeyFrame, int $relativeTime, int $cts): ?array
    {
        if ($cts & 0x800000) $cts -= 0x1000000;

        $segmentSwitches = [];
        foreach ($this->profiles as $name => $profile) {
            // 片任务模式：一个任务恰好一个分片，边界由协调进程按 gop_interval_ms 规划
            $segmentSwitches[$name] = !$this->segmentTaskMode
                && $isKeyFrame
                && ($relativeTime - $this->segmentStartTimes[$name]) >= ($this->segmentDuration * 1000);
        }

        $cacheKey = md5($avcData);
        if (!isset($this->frameCacheKey) || $this->frameCacheKey !== $cacheKey) {
            $this->decodedFrameCache = [];
            $this->frameCacheKey = $cacheKey;
        }

        $dts = (int)($relativeTime * 90);
        $pts = (int)(($relativeTime + $cts) * 90);
        if ($pts < $dts) $pts = $dts;

        $profileJobs = [];
        foreach ($this->profiles as $name => $profile) {
            $writer = &$this->segmentWriters[$name];
            if (!is_resource($writer['handle'])) {
                $profileJobs[$name] = ['transcode' => false, 'skip' => true];
                continue;
            }

            $targetW = $profile['width'] > 0 ? $profile['width'] : $this->srcWidth;
            $targetH = $profile['height'] > 0 ? $profile['height'] : $this->srcHeight;
            $needTranscode = $this->srcInitialized && (
                $this->pipelineVariants !== null ||
                ($profile['width'] > 0 && $this->srcWidth !== $profile['width']) ||
                ($profile['height'] > 0 && $this->srcHeight !== $profile['height']) ||
                $this->profileWatermark[$name] !== null
            );

            if (!$needTranscode) {
                $profileJobs[$name] = ['transcode' => false, 'skip' => false];
                continue;
            }

            $resolutionKey = "{$targetW}_{$targetH}";
            if ($this->pipelineVariants !== null && isset($this->pipelineVariants[$name])) {
                $variant = $this->pipelineVariants[$name];
                $this->decodedFrameCache[$resolutionKey] = substr($this->pipelineYuvPayload, $variant['offset'], $variant['length']);
            } elseif (!isset($this->decodedFrameCache[$resolutionKey])) {
                $rawYuv = $this->decodeNaluToYuv($avcData);
                if ($rawYuv !== null) {
                    $scaledYuv = ($targetW !== $this->srcWidth || $targetH !== $this->srcHeight)
                        ? $this->scaler->scaleYUV420P($rawYuv, $this->srcWidth, $this->srcHeight, $targetW, $targetH)
                        : $rawYuv;
                    $this->decodedFrameCache[$resolutionKey] = $scaledYuv;
                }
            }

            if (!isset($this->decodedFrameCache[$resolutionKey])) {
                // 解码失败：退化为直通（与原实现 isset 分支一致）
                $profileJobs[$name] = ['transcode' => false, 'skip' => false];
                continue;
            }

            $scaledYuv = $this->decodedFrameCache[$resolutionKey];
            if ($this->pipelineVariants === null && $this->profileWatermark[$name] !== null) {
                $scaledYuv = $this->applyWatermarkToFrame($scaledYuv, $targetW, $targetH, $this->profileWatermark[$name]);
            }

            $encoder = $this->encoders[$name];
            $encoder->setResolution($targetW, $targetH);
            $encoder->setBitrate($profile['bitrate']);
            $encoder->setFps($profile['fps']);
            $encoder->setQp($profile['qp'] ?? 26);
            // 异步开始编码：worker 运动估计与上一帧的主进程 CAVLC 重叠
            $encoder->startFrame($scaledYuv, $isKeyFrame);

            $profileJobs[$name] = ['transcode' => true, 'skip' => false];
        }
        unset($writer);

        return [
            'relativeTime' => $relativeTime,
            'isKeyFrame' => $isKeyFrame,
            'avcData' => $avcData,
            'dts' => $dts,
            'pts' => $pts,
            'segmentSwitches' => $segmentSwitches,
            'profiles' => $profileJobs,
        ];
    }

    /** finishFrame 取出各 profile 在途编码结果并写 TS（其 CAVLC 与下一帧 worker 计算重叠） */
    private function emitPendingVideoFrame(array $job): void
    {
        foreach ($this->profiles as $name => $profile) {
            $pjob = $job['profiles'][$name] ?? null;
            if ($pjob === null || !empty($pjob['skip'])) continue;

            $outputData = $job['avcData'];
            $outputSpsPps = $this->spsPpsData[$name];
            $isTranscoded = false;

            if (!empty($pjob['transcode'])) {
                $encodedNals = $this->encoders[$name]->finishFrame();
                $outputData = '';
                $outputSpsPps = '';
                foreach ($encodedNals as $nal) {
                    $nalHeaderPos = $this->findNalHeaderPos($nal);
                    if ($nalHeaderPos < 0) continue;
                    $nalType = ord($nal[$nalHeaderPos]) & 0x1F;
                    if ($nalType === 7 || $nalType === 8) {
                        $outputSpsPps .= $nal;
                    } else {
                        $outputData .= $nal;
                    }
                }
                if ($outputSpsPps !== '') {
                    $this->spsPpsData[$name] = $outputSpsPps;
                }
                $isTranscoded = true;
            }

            $annexb = $isTranscoded ? $outputData : $this->avccToAnnexB($outputData);
            // 只在每个分片的第一帧前置SPS/PPS，避免重复导致FFmpeg解码错误
            if ($this->segmentFirstFrame[$name]) {
                $prefixNal = $outputSpsPps ?: $this->spsPpsData[$name];
                if ($prefixNal !== '') {
                    $annexb = $prefixNal . $annexb;
                }
                $this->segmentFirstFrame[$name] = false;
            }

            $pes = $this->createPES(0xE0, $annexb, $job['pts'], ($job['pts'] !== $job['dts']) ? $job['dts'] : null);
            // 视频首包携带PCR同步播放器
            $this->writeTSPackets($name, $this->videoPid, $pes, true, $job['dts']);

            $this->currentSegmentLastTimes[$name] = $job['relativeTime'];
            $this->segmentWriters[$name]['endTime'] = $job['relativeTime'];
        }
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
        
        $frame = $this->decoder->decode($nalUnits);
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

        foreach ($this->profiles as $name => $profile) {
            $writer = &$this->segmentWriters[$name];
            if (!is_resource($writer['handle'])) continue;

            if ($this->audioBasePts[$name] === null) {
                $this->audioBasePts[$name] = (int)($relativeTime * 90);
            }
            $pts = $this->audioBasePts[$name] + ($this->audioFrameCounts[$name] * $frameDuration);
            $this->audioFrameCounts[$name]++;

            $pes = $this->createPES(0xC0, $payload, $pts, null);
            $this->writeTSPackets($name, $this->audioPid, $pes, false, 0);
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

        foreach ($this->profiles as $name => $_) {
            $this->spsPpsData[$name] = $spsPpsBuf;
        }
    }

    /**
     * NAL防竞争字节转义
     */
    private function escapeNAL(string $nalData): string
    {
        if (strlen($nalData) <= 1) return $nalData;
        $escaped = '';
        $zeroCnt = 0;
        foreach (str_split($nalData) as $byte) {
            $b = ord($byte);
            if ($zeroCnt >= 2 && $b <= 0x03) {
                $escaped .= "\x03";
                $zeroCnt = 0;
            }
            $escaped .= $byte;
            $zeroCnt = $b === 0 ? $zeroCnt + 1 : 0;
        }
        return $escaped;
    }

    /**
     * AVCC(4字节长度前缀) 转 AnnexB(0001起始码)
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
            $nalRaw = substr($data, $offset, $nalSize);
            $offset += $nalSize;
            $result .= "\x00\x00\x00\x01" . $this->escapeNAL($nalRaw);
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
    private function writePAT(string $profile): void
    {
        $section = "\x00\xB0\x0D\x00\x01\xC1\x00\x00\x00\x01" . pack('n', 0xE000 | $this->pmtPid);
        $section .= pack('N', $this->crc32mpeg($section));
        $payload = "\x00" . $section;
        $this->writeTSPacketsRaw($profile, 0x0000, $payload);
    }

    /**
     * 写入PMT表
     */
    private function writePMT(string $profile): void
    {
        $body = "\x00\x01\xC1\x00\x00"
            . pack('n', 0xE000 | $this->videoPid) . "\xF0\x00"
            . "\x1B" . pack('n', 0xE000 | $this->videoPid) . "\xF0\x00"
            . "\x0F" . pack('n', 0xE000 | $this->audioPid) . "\xF0\x00";
        $sectLen = strlen($body) + 4;
        $section = "\x02" . chr(0xB0 | (($sectLen >> 8) & 0x0F)) . chr($sectLen & 0xFF) . $body;
        $section .= pack('N', $this->crc32mpeg($section));
        $payload = "\x00" . $section;
        $this->writeTSPacketsRaw($profile, $this->pmtPid, $payload);
    }

    /**
     * 无自适应字段TS包写入（PAT/PMT专用）
     */
    private function writeTSPacketsRaw(string $profile, int $pid, string $payload): void
    {
        $cc = &$this->continuityCounters[$profile][$pid];
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
            fwrite($this->segmentWriters[$profile]['handle'], $ts);
            $offset += strlen($chunk);
            $first = false;
        }
    }

    /**
     * 标准PES TS打包，支持PCR自适应字段
     */
    private function writeTSPackets(string $profile, int $pid, string $payload, bool $writePCR = false, int $pcr = 0): void
    {
        $cc = &$this->continuityCounters[$profile][$pid];
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
            fwrite($this->segmentWriters[$profile]['handle'], $tsPacket);
            $offset += $payloadSpace;
            $first = false;
        }
    }

    /**
     * 新建TS分片
     * @param int|null $sequence 片 worker 模式指定全局片序号；null 时自增
     * @param string $tmpSuffix 片 worker 模式写 ".tmp"，由协调进程完成后原子改名
     */
    private function startSegment(string $profile, ?int $sequence = null, string $tmpSuffix = ''): void
    {
        $writer = &$this->segmentWriters[$profile];
        $writer['sequence'] = $sequence ?? ($writer['sequence'] + 1);
        $this->continuityCounters[$profile] = [];
        $filePath = $this->profileDir($profile) . "/segment_{$writer['sequence']}.ts{$tmpSuffix}";
        $writer['handle'] = fopen($filePath, 'wb');
        // 分片头部写入PAT/PMT，兼容播放器
        $this->writePAT($profile);
        $this->writePMT($profile);

        $this->audioFrameCounts[$profile] = 0;
    }

    /**
     * 关闭分片并更新m3u8（片 worker 模式只关文件，m3u8 由协调进程发布）
     */
    private function closeSegment(string $profile, int $endTime = 0, bool $updatePlaylist = true): void
    {
        $writer = &$this->segmentWriters[$profile];
        if (!is_resource($writer['handle'])) return;
        fflush($writer['handle']);
        fclose($writer['handle']);
        $writer['handle'] = null;

        $endTs = $endTime ?: $this->currentSegmentLastTimes[$profile];
        $durSec = max(0.001, round(($endTs - $this->segmentStartTimes[$profile]) / 1000.0, 3));
        $this->segmentDurations[$profile][$writer['sequence']] = $durSec;
        if ($this->writePlaylists && $updatePlaylist) $this->updatePlaylist($profile);
    }

    /**
     * 关闭所有分片
     */
    private function closeAllSegments(): void
    {
        foreach ($this->profiles as $name => $_) {
            $writer = $this->segmentWriters[$name];
            if (is_resource($writer['handle'])) {
                $this->closeSegment($name, $writer['endTime']);
            }
            if ($this->writePlaylists) $this->addEndList($name);
        }
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
     * 初始化空m3u8
     */
    private function ensureInitialPlaylist(): void
    {
        foreach ($this->profiles as $name => $_) {
            $m3u8Path = $this->profileDir($name) . '/index.m3u8';
            if (!file_exists($m3u8Path)) {
                $lines = [
                    '#EXTM3U',
                    '#EXT-X-VERSION:3',
                    '#EXT-X-TARGETDURATION:' . $this->segmentDuration,
                    '#EXT-X-MEDIA-SEQUENCE:1',
                    '#EXT-X-INDEPENDENT-SEGMENTS',
                ];
                file_put_contents($m3u8Path, implode("\n", $lines) . "\n");
            }
        }
    }

    /**
     * 更新分片m3u8，原子写入tmp防止损坏
     */
    private function updatePlaylist(string $profile): void
    {
        $lines = ['#EXTM3U', '#EXT-X-VERSION:3'];
        $maxDur = $this->segmentDuration;
        foreach ($this->segmentDurations[$profile] as $d) {
            $maxDur = max($maxDur, ceil($d));
        }
        $lines[] = "#EXT-X-TARGETDURATION:{$maxDur}";
        $lines[] = '#EXT-X-MEDIA-SEQUENCE:1';
        $lines[] = '#EXT-X-INDEPENDENT-SEGMENTS';

        $seqMax = $this->segmentWriters[$profile]['sequence'];
        for ($i = 1; $i <= $seqMax; $i++) {
            $d = $this->segmentDurations[$profile][$i] ?? $this->segmentDuration;
            $lines[] = "#EXTINF:" . number_format($d, 3, '.', '') . ",";
            $lines[] = "segment_{$i}.ts";
        }
        $content = implode("\n", $lines) . "\n";
        $path = $this->profileDir($profile) . '/index.m3u8';
        $tmp = $path . '.tmp';
        file_put_contents($tmp, $content);
        rename($tmp, $path);
    }

    /**
     * 末尾追加ENDLIST标记
     */
    private function addEndList(string $profile): void
    {
        $path = $this->profileDir($profile) . '/index.m3u8';
        if (!file_exists($path)) return;
        $buf = rtrim(file_get_contents($path)) . "\n";
        if (strpos($buf, '#EXT-X-ENDLIST') === false) {
            file_put_contents($path, $buf . "#EXT-X-ENDLIST\n");
        }
    }

    /**
     * 生成主m3u8多清晰度列表
     */
    private function generateMasterPlaylist(): void
    {
        $lines = ['#EXTM3U', '#EXT-X-VERSION:6'];
        // 码率从高到低排序
        $sorted = $this->profiles;
        uasort($sorted, fn($a, $b) => $b['bitrate'] <=> $a['bitrate']);
        foreach ($sorted as $name => $cfg) {
            $audioBr = $cfg['audioBitrate'] ?? 128000;
            $bandwidth = $cfg['bitrate'] + $audioBr;
            $res = "{$cfg['width']}x{$cfg['height']}";
            $lines[] = sprintf(
                '#EXT-X-STREAM-INF:BANDWIDTH=%d,RESOLUTION=%s,CODECS="avc1.64001F,mp4a.40.2"',
                $bandwidth,
                $res
            );
            $lines[] = "{$name}/index.m3u8";
        }
        file_put_contents("{$this->outputDir}/master.m3u8", implode("\n", $lines) . "\n");
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