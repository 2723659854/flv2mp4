<?php

namespace Xiaosongshu\Flv2mp4\Recode;

use Xiaosongshu\Flv2mp4\Audio\AudioPolicy;
use Xiaosongshu\Flv2mp4\Audio\AudioTranscoder;
use Xiaosongshu\Flv2mp4\Audio\SilentAacSource;
use Xiaosongshu\Flv2mp4\Codec\H264Decoder;
use Xiaosongshu\Flv2mp4\Codec\H264Encoder;
use Xiaosongshu\Flv2mp4\Codec\NalUtil;
use Xiaosongshu\Flv2mp4\Codec\Scaler\VideoScaler;

/**
 * @purpose mp4重编码（改变分辨率/码率）
 * @author yanglong
 * @time 2026年7月28日
 * @note 本转码器目前仅支持baseline profile
 */
class Mp4Recoder
{
    private int $targetWidth = 0;
    private int $targetHeight = 0;
    private int $targetBitrate = 0;
    private int $targetFps = 0;
    private int $targetQp = 26;

    private bool $watermarkEnabled = false;
    private string $watermarkFile = '';
    private string $wmY = '';
    private string $wmU = '';
    private string $wmV = '';
    private int $wmWidth = 0;
    private int $wmHeight = 0;

    private ?H264Decoder $decoder = null;
    private ?H264Encoder $encoder = null;
    private ?VideoScaler $scaler = null;

    private string $mp4Data = '';
    private array $boxTree = [];

    private ?array $videoTrack = null;
    private ?array $audioTrack = null;

    private int $srcWidth = 0;
    private int $srcHeight = 0;
    private bool $srcInitialized = false;

    private string $srcSpsData = '';
    private string $srcPpsData = '';

    private string $audioSpecificConfig = '';
    private bool $audioConfigParsed = false;
    private int $audioSampleRate = 44100;
    private int $audioChannels = 2;
    private int $audioObjectType = 2;

    /** 音频目标（null=未配置→原样直通；aac/mp3=目标格式） */
    private ?string $audioTarget = null;
    /** 源音频格式（aac/mp3/unknown:N），由 esds tag0x04 的 ObjectTypeIndication 读出 */
    private ?string $audioSourceCodec = null;
    private int $audioSourceOti = -1;
    private string $audioOutputCodec = AudioPolicy::CODEC_UNKNOWN;
    private string $audioMode = AudioPolicy::MODE_PASSTHROUGH;
    private ?AudioTranscoder $audioTranscoder = null;
    private int $mp3Bitrate = 128000;
    /** 转码输出样本游标（用于生成音频样本时间戳） */
    private int $audioOutputSamples = 0;
    private int $audioAnchorMs = 0;
    private bool $audioAnchorSet = false;
    private ?int $audioLastPtsRaw = null;
    /** 源音频无解码器时的静音 AAC 轨（如实写入一条真实音轨，时长随视频） */
    private ?SilentAacSource $silentAudioSource = null;
    /** Opus dOps pre-skip（48 kHz 样本）/ output gain（Q7.7） */
    private int $audioPreSkip = 0;
    private int $audioGain = 0;
    /** PCM 位深/字节序（PCM 无需解码器，直接解释字节） */
    private array $audioSourceOptions = [];
    /** Opus 首包模式判定是否已完成（非 CELT 时确定性落静音） */
    private bool $opusProbed = false;
    /** 在途音频队列字节数（硬上限 8MB） */
    private int $queuedAudioBytes = 0;

    private array $videoSamples = [];
    private array $audioSamples = [];

    private int $videoTimescale = 90000;
    private int $audioTimescale = 44100;
    private int $duration = 0;

    private string $encSpsAnnexB = '';
    private string $encPpsAnnexB = '';
    private string $encAvccHeader = '';

    private int $videoFrameCount = 0;
    private ?int $maxFrames = null;

    // 帧级双缓冲：一个已 startFrame 的转码帧延后到下一帧到达后再 finish
    private ?array $pendingVideoJob = null;
    /** @var array<int,array{data:string,timestamp:int}> */
    private array $queuedAudioSamples = [];

    // B 帧（IBBP）编码调度状态：显示序待编码帧队列 + 编码序计数
    /** 是否启用 B 帧编码（config.bframes；请求 baseline 时自动升为 main） */
    private bool $bframes = false;
    /** 实际下发给编码器的 profile（请求 B 帧时 baseline 自动升为 main） */
    private string $profileName = 'baseline';
    /** @var array<int,array{yuv:string,isKey:bool,idx:int}> 显示序待编码帧（最多滞留 3 帧） */
    private array $bQueue = [];
    /** 最近一个已编码锚点的显示序序号；三元组（锚点+2B）对齐基准 */
    private int $bLastAnchorIdx = -1;
    /** 最近一个已编码 IDR 的显示序序号；POC 基准 */
    private int $bPocBase = 0;
    /** 编码序 DTS 计数（每落盘一帧 +1） */
    private int $bDtsCounter = 0;
    /** 输出显示序计数（每解出一帧参与输出的显示帧 +1） */
    private int $bDisplayCounter = 0;

    private int $outputVideoWidth = 0;
    private int $outputVideoHeight = 0;
    private ?float $sourceFps = null;
    private ?float $effectiveTargetFps = null;
    private bool $dropFrames = false;
    private ?int $firstVideoDtsMs = null;
    /** 最近一次解码喂入的源 sample DTS（EOF 冲洗帧按源帧率顺延此时间轴） */
    private ?int $lastSourceDtsMs = null;
    private bool $multi;
    private array $config;
    private ?string $pipelineYuv = null;
    private $pipelineMediaHandle = null;
    private ?string $pipelineTempFile = null;
    private int $pipelineMediaSize = 0;

    public function __construct(array $config = [], bool $multi = false)
    {
        $this->multi = $multi;
        $this->config = $config;
        $this->targetWidth = $config['width'] ?? 0;
        $this->targetHeight = $config['height'] ?? 0;
        $this->targetBitrate = $config['bitrate'] ?? 0;
        $this->targetFps = $config['fps'] ?? 0;
        $this->targetQp = $config['qp'] ?? 26;
        // 音频策略在校验期完成取值检查：非法 audio 直接报错
        $this->audioTarget = AudioPolicy::targetFromConfig($config);
        $this->mp3Bitrate = (int)($config['audioBitrate'] ?? 128000);
        $this->validateConfig();

        if (!empty($config['watermark']) && !empty($config['watermarkFile'])) {
            $this->watermarkEnabled = true;
            $this->watermarkFile = $config['watermarkFile'];
            $this->loadWatermark();
        }

        $this->decoder = new H264Decoder();
        $this->encoder = new H264Encoder();
        $this->encoder->motionWorkers = max(1, (int)($config['motionWorkers'] ?? 8));
        $profile = strtolower(trim($config['profile'] ?? 'baseline'));
        // 取值校验前置：非 baseline/main/high 一律回退 baseline（与 setProfile 一致）
        if (!in_array($profile, ['baseline', 'main', 'high'], true)) $profile = 'baseline';
        $this->bframes = !empty($config['bframes']);
        // B 片不属于 baseline 语法：请求 B 帧时至少按 main profile 输出
        if ($this->bframes && $profile === 'baseline') $profile = 'main';
        $this->profileName = $profile;
        $this->encoder->setProfile($profile);
        if ($this->bframes) $this->encoder->enableBFrames(true);
        if (!empty($config['fastMotion'])) $this->encoder->setFastMotion(true);
        if (isset($config['intra8x8QpOffset'])) $this->encoder->intra8x8QpOffset = max(-51, min(51, (int)$config['intra8x8QpOffset']));
        $this->scaler = new VideoScaler();
    }

    private function validateConfig(): void
    {
        foreach (['width' => $this->targetWidth, 'height' => $this->targetHeight, 'fps' => $this->targetFps, 'bitrate' => $this->targetBitrate] as $name => $value) {
            if ($value < 0) {
                throw new \RuntimeException("配置 {$name} 不得为负数");
            }
        }
        if ($this->targetWidth !== 0 && ($this->targetWidth & 1) !== 0) {
            throw new \RuntimeException('配置 width 非0时必须为偶数');
        }
        if ($this->targetHeight !== 0 && ($this->targetHeight & 1) !== 0) {
            throw new \RuntimeException('配置 height 非0时必须为偶数');
        }
        // 当前容器无法可靠获得源视频轨码率；bitrate>0由调用方保证不高于源视频码率。
    }

    private function loadWatermark(): void
    {
        if (!file_exists($this->watermarkFile)) {
            throw new \RuntimeException("水印文件不存在: {$this->watermarkFile}");
        }

        $wmData = file_get_contents($this->watermarkFile);
        if ($wmData === false || $wmData === '') {
            throw new \RuntimeException("无法读取水印文件: {$this->watermarkFile}");
        }

        $basename = basename($this->watermarkFile, '.yuv');
        if (preg_match('/_(\d+)x(\d+)$/', $basename, $matches)) {
            $this->wmWidth = (int)$matches[1];
            $this->wmHeight = (int)$matches[2];
        } else {
            $this->wmWidth = 80;
            $this->wmHeight = 16;
        }

        $wmYSize = $this->wmWidth * $this->wmHeight;
        $wmUvSize = $wmYSize >> 2;
        $expectedSize = $wmYSize + $wmUvSize * 2;

        if (strlen($wmData) < $expectedSize) {
            throw new \RuntimeException("水印文件尺寸不匹配: 期望 {$expectedSize} 字节, 实际 " . strlen($wmData) . " 字节");
        }

        $this->wmY = substr($wmData, 0, $wmYSize);
        $this->wmU = substr($wmData, $wmYSize, $wmUvSize);
        $this->wmV = substr($wmData, $wmYSize + $wmUvSize, $wmUvSize);
    }

    private function applyWatermark(string $yuvData, int $frameW, int $frameH): string
    {
        if ($this->wmWidth > $frameW || $this->wmHeight > $frameH) {
            throw new \RuntimeException("水印尺寸 {$this->wmWidth}x{$this->wmHeight} 超过最终输出尺寸 {$frameW}x{$frameH}");
        }

        $ySize = $frameW * $frameH;
        $uvW = $frameW >> 1;
        $uvH = $frameH >> 1;
        $uvSize = $uvW * $uvH;

        $dstX = 0;
        $dstY = 0;

        for ($row = 0; $row < $this->wmHeight; $row++) {
            $srcOffset = $row * $this->wmWidth;
            $dstOffset = ($dstY + $row) * $frameW + $dstX;
            for ($col = 0; $col < $this->wmWidth; $col++) {
                $yuvData[$dstOffset + $col] = $this->wmY[$srcOffset + $col];
            }
        }

        $wmUvW = $this->wmWidth >> 1;
        $wmUvH = $this->wmHeight >> 1;
        $dstUvX = $dstX >> 1;
        $dstUvY = $dstY >> 1;

        $uOffset = $ySize;
        $vOffset = $ySize + $uvSize;

        for ($row = 0; $row < $wmUvH; $row++) {
            $srcOffset = $row * $wmUvW;
            $dstOffset = ($dstUvY + $row) * $uvW + $dstUvX;
            for ($col = 0; $col < $wmUvW; $col++) {
                $yuvData[$uOffset + $dstOffset + $col] = $this->wmU[$srcOffset + $col];
                $yuvData[$vOffset + $dstOffset + $col] = $this->wmV[$srcOffset + $col];
            }
        }

        return $yuvData;
    }

    public function setMaxFrames(int $maxFrames): void
    {
        $this->maxFrames = $maxFrames;
    }

    private function resetProcessState(): void
    {
        $this->decoder = new H264Decoder();
        $this->encoder = new H264Encoder();
        $this->encoder->motionWorkers = max(1, (int)($this->config['motionWorkers'] ?? 8));
        $this->encoder->setProfile($this->profileName);
        if ($this->bframes) $this->encoder->enableBFrames(true);
        if (!empty($this->config['fastMotion'])) $this->encoder->setFastMotion(true);
        if (isset($this->config['intra8x8QpOffset'])) $this->encoder->intra8x8QpOffset = max(-51, min(51, (int)$this->config['intra8x8QpOffset']));
        // B 帧状态重置
        $this->bQueue = [];
        $this->bLastAnchorIdx = -1;
        $this->bPocBase = 0;
        $this->bDtsCounter = 0;
        $this->bDisplayCounter = 0;
        $this->mp4Data = '';
        $this->boxTree = [];
        $this->videoTrack = null;
        $this->audioTrack = null;
        $this->srcWidth = 0;
        $this->srcHeight = 0;
        $this->srcInitialized = false;
        $this->srcSpsData = '';
        $this->srcPpsData = '';
        $this->videoSamples = [];
        $this->audioSamples = [];
        $this->videoTimescale = 90000;
        $this->audioTimescale = 44100;
        $this->audioSourceCodec = null;
        $this->audioSourceOti = -1;
        $this->audioOutputCodec = AudioPolicy::CODEC_UNKNOWN;
        $this->audioMode = AudioPolicy::MODE_PASSTHROUGH;
        $this->audioTranscoder = null;
        $this->audioOutputSamples = 0;
        $this->audioAnchorMs = 0;
        $this->audioAnchorSet = false;
        $this->audioLastPtsRaw = null;
        $this->silentAudioSource = null;
        $this->audioPreSkip = 0;
        $this->audioGain = 0;
        $this->audioSourceOptions = [];
        $this->opusProbed = false;
        $this->queuedAudioBytes = 0;
        $this->duration = 0;
        $this->encSpsAnnexB = '';
        $this->encPpsAnnexB = '';
        $this->encAvccHeader = '';
        $this->videoFrameCount = 0;
        $this->pendingVideoJob = null;
        $this->queuedAudioSamples = [];
        $this->outputVideoWidth = 0;
        $this->outputVideoHeight = 0;
        $this->sourceFps = null;
        $this->effectiveTargetFps = null;
        $this->dropFrames = false;
        $this->firstVideoDtsMs = null;
        $this->lastSourceDtsMs = null;
        $this->pipelineYuv = null;
        $this->pipelineMediaSize = 0;
        $this->audioSpecificConfig = '';
        $this->audioConfigParsed = false;
        $this->audioSampleRate = 44100;
        $this->audioChannels = 2;
        $this->audioObjectType = 2;
    }

    public function processMp4(string $inputFile, string $outputFile): void
    {
        if (!file_exists($inputFile)) {
            throw new \RuntimeException("MP4文件不存在: {$inputFile}");
        }
        if ($this->multi) {
            (new Mp4PipelineClient($this->config, $this->maxFrames))->process($inputFile, $outputFile);
            return;
        }

        $this->resetProcessState();
        $outputDir = dirname($outputFile);
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0777, true);
        }

        $this->mp4Data = file_get_contents($inputFile);
        if (empty($this->mp4Data)) {
            throw new \RuntimeException("无法读取MP4文件");
        }

        $this->parseMp4Boxes();
        $this->parseTracks();
        $this->extractAndTranscodeMediaData();

        if (!$this->videoTrack && !$this->audioTrack) {
            throw new \RuntimeException("未找到有效的视频或音频轨道");
        }

        $this->buildMp4($outputFile);

        echo "Done! Output: {$outputFile}\n";
        echo "Output size: " . filesize($outputFile) . " bytes\n";
    }

    /* ========== MP4 Box 解析 ========== */

    private function parseMp4Boxes(): void
    {
        $this->boxTree = $this->parseBox($this->mp4Data, 0, strlen($this->mp4Data));
    }

    private function parseBox(string $data, int $offset, int $end): array
    {
        $boxes = [];
        while ($offset + 8 <= $end) {
            $size = unpack('N', substr($data, $offset, 4))[1];
            $type = substr($data, $offset + 4, 4);
            if ($size == 1) {
                if ($offset + 16 <= $end) {
                    $size = unpack('J', substr($data, $offset + 8, 8))[1];
                    $headerSize = 16;
                } else break;
            } elseif ($size == 0) {
                $size = $end - $offset;
                $headerSize = 8;
            } else {
                $headerSize = 8;
            }
            $boxEnd = $offset + $size;
            if ($boxEnd > $end) break;
            $boxData = substr($data, $offset + $headerSize, $size - $headerSize);
            $box = ['type' => $type, 'size' => $size, 'offset' => $offset, 'data' => $boxData, 'children' => []];
            if ($size > $headerSize) {
                $box['children'] = $this->parseBox($data, $offset + $headerSize, $boxEnd);
            }
            $boxes[] = $box;
            $offset = $boxEnd;
        }
        return $boxes;
    }

    private function findBox(array $boxes, string $type): ?array
    {
        foreach ($boxes as $box) {
            if ($box['type'] === $type) return $box;
            if (!empty($box['children'])) {
                $found = $this->findBox($box['children'], $type);
                if ($found !== null) return $found;
            }
        }
        return null;
    }

    /**
     * 在 sample entry 数据区中查找子盒子的原始数据（如 Opus 的 dOps、lpcm 的 enda）。
     * 直接按 FourCC 定位，兼容 sample entry 的 version 0/1 偏移差异。
     */
    private function findEntryChildBox(string $entryData, string $type): ?string
    {
        $pos = 0;
        $needle = $type;
        while (($idx = strpos($entryData, $needle, $pos)) !== false) {
            if ($idx >= 4) {
                $size = unpack('N', substr($entryData, $idx - 4, 4))[1];
                if ($size >= 8 && $idx - 4 + $size <= strlen($entryData)) {
                    return substr($entryData, $idx + 4, $size - 8);
                }
            }
            $pos = $idx + 4;
        }
        return null;
    }

    /**
     * 解析 QuickTime enda 子盒：payload 为 16bit 大端标志（1=小端，0=大端），
     * 兼容部分封装在标志前带 4 字节 version/flags 的变体。
     */
    private function parseEndaFlag(?string $enda): ?bool
    {
        if ($enda === null) return null;
        $flag = null;
        if (strlen($enda) >= 6 && substr($enda, 0, 4) === "\0\0\0\0") {
            $flag = unpack('n', substr($enda, 4, 2))[1];
        } elseif (strlen($enda) >= 2) {
            $flag = unpack('n', substr($enda, 0, 2))[1];
        }
        return ($flag === 0 || $flag === 1) ? $flag === 1 : null;
    }

    private function findAllBoxes(array $boxes, string $type): array
    {
        $result = [];
        foreach ($boxes as $box) {
            if ($box['type'] === $type) $result[] = $box;
            if (!empty($box['children'])) {
                $result = array_merge($result, $this->findAllBoxes($box['children'], $type));
            }
        }
        return $result;
    }

    /* ========== 轨道解析 ========== */

    private function parseTracks(): void
    {
        $moov = $this->findBox($this->boxTree, 'moov');
        if (!$moov) throw new \RuntimeException("未找到moov盒子");

        $mvhd = $this->findBox([$moov], 'mvhd');
        if ($mvhd) {
            $mvhdData = $mvhd['data'];
            $version = ord($mvhdData[0]);
            if ($version == 0) {
                $timescale = unpack('N', substr($mvhdData, 12, 4))[1];
                $duration = unpack('N', substr($mvhdData, 16, 4))[1];
            } else {
                $timescale = unpack('N', substr($mvhdData, 20, 4))[1];
                $duration = unpack('J', substr($mvhdData, 24, 8))[1];
            }
            $this->duration = $duration;
            $this->videoTimescale = $timescale;
        }

        $traks = $this->findAllBoxes([$moov], 'trak');
        foreach ($traks as $trak) {
            $this->parseTrack($trak);
        }
        // 有音频轨但未从 esds 读到 OTI：源格式未知（未配置 audio 时仍走旧直通行为）
        if ($this->audioTrack !== null && $this->audioSourceCodec === null) {
            $this->audioSourceCodec = AudioPolicy::CODEC_UNKNOWN;
        }
    }

    private function parseTrack(array $trak): void
    {
        $tkhd = $this->findBox([$trak], 'tkhd');
        if (!$tkhd) return;
        $trackId = unpack('N', substr($tkhd['data'], 12, 4))[1];
        $mdia = $this->findBox([$trak], 'mdia');
        if (!$mdia) return;
        $hdlr = $this->findBox([$mdia], 'hdlr');
        if (!$hdlr) return;
        $handlerType = substr($hdlr['data'], 8, 4);

        $minf = $this->findBox([$mdia], 'minf');
        if (!$minf) return;
        $stbl = $this->findBox([$minf], 'stbl');
        if (!$stbl) return;
        $stsd = $this->findBox([$stbl], 'stsd');
        if (!$stsd) return;
        $mdhd = $this->findBox([$mdia], 'mdhd');
        $timescale = 90000;
        if ($mdhd) {
            $mdhdData = $mdhd['data'];
            $version = ord($mdhdData[0]);
            if ($version == 0) {
                $timescale = unpack('N', substr($mdhdData, 12, 4))[1];
            } else {
                $timescale = unpack('N', substr($mdhdData, 20, 4))[1];
            }
        }
        $stsdData = $stsd['data'];
        $pos = 8;
        while ($pos + 8 <= strlen($stsdData)) {
            $entrySize = unpack('N', substr($stsdData, $pos, 4))[1];
            $entryType = substr($stsdData, $pos + 4, 4);
            if ($handlerType === 'vide' && $entryType === 'avc1') {
                $this->videoTrack = ['id' => $trackId, 'type' => 'video', 'codec' => 'avc1', 'timescale' => $timescale, 'trak' => $trak];
                $this->parseAvcCFromBox(substr($stsdData, $pos, $entrySize));
                break;
            } elseif ($handlerType === 'soun') {
                // 任何 soun 轨都识别：mp4a 走原有 AAC/MP3 路径；PCM/Opus 等无解码器时
                // 后续按策略输出静音 AAC 轨（不能因为样本条目不识别就把整条音轨丢掉）
                $this->audioTrack = ['id' => $trackId, 'type' => 'audio', 'codec' => $entryType, 'timescale' => $timescale, 'trak' => $trak];
                // AudioSampleEntry 数据区（盒子头之后）：声道数偏移16(u16)，采样率偏移24(u32 16.16)
                $entryData = substr($stsdData, $pos + 8, $entrySize - 8);
                if (strlen($entryData) >= 28) {
                    $channels = unpack('n', substr($entryData, 16, 2))[1];
                    $rate = unpack('N', substr($entryData, 24, 4))[1] >> 16;
                    if ($channels > 0) $this->audioChannels = $channels;
                    if ($rate > 0) {
                        $this->audioSampleRate = $rate;
                        $this->audioTimescale = $rate;
                    }
                }
                if ($entryType === 'mp4a') {
                    $this->parseEsdsFromBox(substr($stsdData, $pos, $entrySize));
                } elseif ($entryType === 'Opus') {
                    // Opus：dOps 给出真实声道 / pre-skip / output gain；解码固定 48 kHz
                    $this->audioSourceCodec = AudioPolicy::CODEC_OPUS;
                    $this->audioSampleRate = 48000;
                    $dops = $this->findEntryChildBox($entryData, 'dOps');
                    if ($dops !== null) {
                        $parsed = AudioPolicy::parseOpusIdentificationHeader($dops);
                        if ($parsed !== null) {
                            if ($parsed['channels'] > 0) $this->audioChannels = max(1, min(2, $parsed['channels']));
                            $this->audioPreSkip = (int)$parsed['preSkip'];
                            $this->audioGain = (int)$parsed['gain'];
                            $this->audioSourceOptions = ['preSkip' => $this->audioPreSkip, 'gain' => $this->audioGain];
                        }
                    }
                } elseif (in_array($entryType, ['ipcm', 'sowt', 'twos', 'lpcm', 'raw ', 'in24', 'in32'], true)) {
                    // PCM：无需解码器，按 sample entry 的位深/字节序直接解释
                    $this->audioSourceCodec = AudioPolicy::CODEC_PCM;
                    // QuickTime in24/in32 的 samplesize 字段恒为 16，真实位深只能由 FourCC 决定
                    $bits = $entryType === 'in24' ? 24 : ($entryType === 'in32' ? 32 : 0);
                    if ($bits === 0 && strlen($entryData) >= 20) {
                        $declared = unpack('n', substr($entryData, 18, 2))[1];
                        if (in_array($declared, [8, 16, 24, 32], true)) $bits = $declared;
                    }
                    if ($bits === 0) $bits = 16;
                    $littleEndian = $entryType === 'sowt';
                    $unsigned = $entryType === 'raw ';
                    $unsupported = null;
                    if ($entryType === 'ipcm') {
                        // ISO/IEC 23003-5 pcmC：version/flags(4) + format_flags(1) + PCM_sample_size(1)
                        // format_flags bit0 = 小端；PCM_sample_size 才是权威位深（8bit 为无符号）
                        $pcmC = $this->findEntryChildBox($entryData, 'pcmC');
                        if ($pcmC !== null && strlen($pcmC) >= 6) {
                            $littleEndian = (ord($pcmC[4]) & 0x01) === 1;
                            $sampleSize = ord($pcmC[5]);
                            if (in_array($sampleSize, [8, 16, 24, 32], true)) $bits = $sampleSize;
                            if ($bits === 8) $unsigned = true;
                        }
                    } elseif ($entryType === 'lpcm') {
                        // QuickTime lpcm：v0 用 samplesize + enda；v2 ASBD 才是权威
                        //（offset 32 采样率 f64 / 40 声道 / 48 位深 / 52 格式标志：0x1 浮点、0x2 大端、0x4 有符号）
                        $flag = $this->parseEndaFlag($this->findEntryChildBox($entryData, 'enda'));
                        if ($flag !== null) $littleEndian = $flag;
                        $version = strlen($entryData) >= 10 ? unpack('n', substr($entryData, 8, 2))[1] : 0;
                        if ($version === 2 && strlen($entryData) >= 60) {
                            $flags = unpack('N', substr($entryData, 52, 4))[1];
                            if (($flags & 0x1) !== 0) {
                                // 浮点 lpcm 无解码器：显式不支持，绝不按整数解释
                                $unsupported = 'lpcm-float';
                            } else {
                                $asbdBits = unpack('N', substr($entryData, 48, 4))[1];
                                if (in_array($asbdBits, [8, 16, 24, 32], true)) $bits = $asbdBits;
                                $littleEndian = ($flags & 0x2) === 0;
                                $unsigned = ($flags & 0x4) === 0;
                                if ($bits === 8) $unsigned = true;
                                $asbdChannels = unpack('N', substr($entryData, 40, 4))[1];
                                if ($asbdChannels > 0 && $asbdChannels <= 8) $this->audioChannels = $asbdChannels;
                                $asbdRate = unpack('E', substr($entryData, 32, 8))[1];
                                if ($asbdRate >= 8000 && $asbdRate <= 192000) {
                                    $this->audioSampleRate = (int)round($asbdRate);
                                    $this->audioTimescale = $this->audioSampleRate;
                                }
                            }
                        }
                    } else {
                        // QuickTime in24/in32：字节序在 enda 子盒（16bit 大端标志，1=小端）
                        $flag = $this->parseEndaFlag($this->findEntryChildBox($entryData, 'enda'));
                        if ($flag !== null) $littleEndian = $flag;
                    }
                    if ($unsupported !== null) {
                        $this->audioSourceCodec = AudioPolicy::CODEC_UNKNOWN . ':' . $unsupported;
                        $this->audioSourceOptions = [];
                    } else {
                        $this->audioSourceOptions = [
                            'bits' => $bits,
                            'littleEndian' => $littleEndian,
                            'unsigned' => $unsigned,
                        ];
                    }
                } elseif (in_array($entryType, ['fpcm', 'fl32', 'fl64'], true)) {
                    // 浮点 PCM 无解码器：显式标记为不支持（后续确定性输出静音 AAC；目标 MP3 时报错），
                    // 绝不按整数 PCM 解释，避免把浮点字节当 s16 输出噪声
                    $this->audioSourceCodec = AudioPolicy::CODEC_UNKNOWN . ':' . trim($entryType);
                }
                break;
            }
            $pos += $entrySize;
        }
    }

    private function parseAvcCFromBox(string $data): void
    {
        $pos = strpos($data, 'avcC');
        if ($pos === false || $pos < 4) return;
        $boxSize = unpack('N', substr($data, $pos - 4, 4))[1];
        $avcCData = substr($data, $pos + 4, $boxSize - 8);
        $this->parseAvcC($avcCData);
    }

    private function parseAvcC(string $data): void
    {
        if (strlen($data) < 8) return;
        $numSps = ord($data[5]) & 0x1F;
        $offset = 6;
        for ($i = 0; $i < $numSps; $i++) {
            if ($offset + 2 > strlen($data)) break;
            $spsLength = unpack('n', substr($data, $offset, 2))[1];
            $offset += 2;
            if ($offset + $spsLength > strlen($data)) break;
            $spsData = substr($data, $offset, $spsLength);
            $offset += $spsLength;
            if (!$this->srcInitialized) {
                $spsClean = NalUtil::removeEmulationPrevention($spsData);
                $rbspSps = substr($spsClean, 1);
                $this->srcSpsData = $rbspSps;
                $this->decoder->decode([['type' => 7, 'data' => $rbspSps]], true);
                $this->srcWidth = $this->decoder->getWidth();
                $this->srcHeight = $this->decoder->getHeight();
                $this->srcInitialized = true;
            }
            break;
        }
        $numPps = ord($data[$offset]); $offset++;
        for ($i = 0; $i < $numPps; $i++) {
            if ($offset + 2 > strlen($data)) break;
            $ppsLength = unpack('n', substr($data, $offset, 2))[1];
            $offset += 2;
            if ($offset + $ppsLength > strlen($data)) break;
            $ppsData = substr($data, $offset, $ppsLength);
            $ppsClean = NalUtil::removeEmulationPrevention($ppsData);
            $rbspPps = substr($ppsClean, 1);
            $this->srcPpsData = $rbspPps;
            if (!$this->srcInitialized) {
                $this->decoder->decode([['type' => 8, 'data' => $rbspPps]]);
            }
            break;
        }
    }

    private function parseEsdsFromBox(string $data): void
    {
        $pos = strpos($data, 'esds');
        if ($pos === false || $pos < 4) return;
        $boxSize = unpack('N', substr($data, $pos - 4, 4))[1];
        $esdsData = substr($data, $pos + 4, $boxSize - 8);
        $this->parseEsds($esdsData);
    }

    private function parseEsds(string $data, bool $hasFullBoxHeader = true): void
    {
        $len = strlen($data);
        if ($len < 2) return;
        $pos = $hasFullBoxHeader ? 4 : 0;
        while ($pos + 2 <= $len) {
            $tag = ord($data[$pos]);
            $pos++;
            if ($pos >= $len) break;
            $length = 0;
            while ($pos < $len) {
                $byte = ord($data[$pos]);
                $pos++;
                $length = ($length << 7) | ($byte & 0x7F);
                if (($byte & 0x80) == 0) break;
            }
            if ($pos + $length > $len) break;
            if ($tag == 0x05) {
                $this->audioSpecificConfig = substr($data, $pos, $length);
                $this->parseAudioSpecificConfig($this->audioSpecificConfig);
                $this->audioConfigParsed = true;
                return;
            }
            if ($tag == 0x03) {
                $skipBytes = 3;
                if ($length > $skipBytes) {
                    $this->parseEsds(substr($data, $pos + $skipBytes, $length - $skipBytes), false);
                }
            } elseif ($tag == 0x04) {
                // 记录 ObjectTypeIndication：0x6B/0x69=MP3，0x40/0x66/0x67/0x68=AAC
                if ($this->audioSourceOti < 0 && $length >= 1) {
                    $this->audioSourceOti = ord($data[$pos]);
                    $this->audioSourceCodec = AudioPolicy::sourceFromMp4ObjectTypeIndication($this->audioSourceOti);
                }
                $skipBytes = 13;
                if ($length > $skipBytes) {
                    $this->parseEsds(substr($data, $pos + $skipBytes, $length - $skipBytes), false);
                }
            }
            $pos += $length;
        }
    }

    private function parseAudioSpecificConfig(string $config): void
    {
        $len = strlen($config);
        if ($len < 2) return;
        $bytes = unpack('n', substr($config, 0, 2))[1];
        $this->audioObjectType = ($bytes >> 11) & 0x1F;
        $freqIndex = ($bytes >> 7) & 0x0F;
        $channelConfig = ($bytes >> 3) & 0x0F;

        $rates = [96000, 88200, 64000, 48000, 44100, 32000, 24000, 22050, 16000, 12000, 11025, 8000, 7350];
        $baseRate = $rates[$freqIndex] ?? 44100;

        switch ($this->audioObjectType) {
            case 2:
                $this->audioSampleRate = $baseRate;
                $this->audioChannels = $channelConfig;
                break;
            case 5:
            case 29:
                $this->audioSampleRate = $baseRate * 2;
                $this->audioChannels = ($this->audioObjectType == 29) ? 2 : $channelConfig;
                break;
            default:
                $this->audioSampleRate = $baseRate;
                $this->audioChannels = $channelConfig;
                break;
        }
        $this->audioTimescale = $this->audioSampleRate;
    }

    public function preparePipelineInput(string $inputFile): array
    {
        $this->resetProcessState();
        $this->mp4Data = file_get_contents($inputFile);
        if ($this->mp4Data === '') throw new \RuntimeException('无法读取MP4文件');
        $this->parseMp4Boxes();
        $this->parseTracks();
        $samples = [];
        if ($this->videoTrack) {
            $video = $this->extractVideoSamples();
            $this->configureOutputVideo($video);
            foreach ($video as &$sample) $sample['type'] = 'video';
            unset($sample);
            $samples = array_merge($samples, $video);
        }
        if ($this->audioTrack && !str_starts_with((string)$this->audioSourceCodec, AudioPolicy::CODEC_UNKNOWN)) {
            $audio = $this->extractAudioSamples();
            // Opus：用首个包判定 CELT；SILK/Hybrid 未实现 → 确定性落静音（目标 MP3 时直接报错）
            if ($this->audioSourceCodec === AudioPolicy::CODEC_OPUS && $audio !== []) {
                $this->opusProbed = true;
                $mode = AudioPolicy::opusPacketMode((string)$audio[0]['data']);
                if ($mode !== 'CELT') {
                    if ($this->audioTarget === AudioPolicy::CODEC_MP3) {
                        throw new \RuntimeException('源音频（Opus/' . ($mode ?? 'invalid') . '）无解码器，无法转为 MP3');
                    }
                    $this->audioSourceCodec = AudioPolicy::CODEC_UNKNOWN . ':opus-' . strtolower((string)$mode);
                    $audio = [];
                }
            }
            foreach ($audio as &$sample) $sample['type'] = 'audio';
            unset($sample);
            $samples = array_merge($samples, $audio);
        }
        usort($samples, fn(array $a, array $b): int => $a['dtsMs'] <=> $b['dtsMs']);
        return [[
            'srcWidth' => $this->srcWidth, 'srcHeight' => $this->srcHeight,
            'srcSps' => base64_encode($this->srcSpsData), 'srcPps' => base64_encode($this->srcPpsData),
            'sourceFps' => $this->sourceFps, 'outputWidth' => $this->outputVideoWidth,
            'outputHeight' => $this->outputVideoHeight, 'dropFrames' => $this->dropFrames,
            'effectiveTargetFps' => $this->effectiveTargetFps,
            'audioConfig' => base64_encode($this->audioSpecificConfig),
            'audioSampleRate' => $this->audioSampleRate, 'audioChannels' => $this->audioChannels,
            'audioObjectType' => $this->audioObjectType, 'audioTimescale' => $this->audioTimescale,
            'audioCodec' => $this->audioSourceCodec ?? AudioPolicy::CODEC_UNKNOWN,
            'audioTarget' => $this->audioTarget, 'mp3Bitrate' => $this->mp3Bitrate,
            'audioOptions' => $this->audioSourceOptions,
            'audioPreSkip' => $this->audioPreSkip,
            'audioGain' => $this->audioGain,
            'hasVideo' => $this->videoTrack !== null, 'hasAudio' => $this->audioTrack !== null,
        ], $samples];
    }

    public function initializePipelineOutput(array $metadata, string $outputFile): void
    {
        $this->resetProcessState();
        $outputDir = dirname($outputFile);
        if (!is_dir($outputDir) && !mkdir($outputDir, 0777, true) && !is_dir($outputDir)) {
            throw new \RuntimeException("无法创建输出目录: {$outputDir}");
        }
        $this->pipelineTempFile = $outputFile . '.media.' . bin2hex(random_bytes(8)) . '.tmp';
        $this->pipelineMediaHandle = @fopen($this->pipelineTempFile, 'x+b');
        if (!is_resource($this->pipelineMediaHandle)) {
            $this->pipelineTempFile = null;
            throw new \RuntimeException('无法创建临时媒体文件');
        }
        $this->writeAll($this->pipelineMediaHandle, pack('N', 0) . 'mdat');
        $this->srcWidth = (int)$metadata['srcWidth'];
        $this->srcHeight = (int)$metadata['srcHeight'];
        $this->srcSpsData = base64_decode($metadata['srcSps'], true) ?: '';
        $this->srcPpsData = base64_decode($metadata['srcPps'], true) ?: '';
        $this->srcInitialized = $this->srcWidth > 0;
        $this->sourceFps = isset($metadata['sourceFps']) ? (float)$metadata['sourceFps'] : null;
        $this->outputVideoWidth = (int)$metadata['outputWidth'];
        $this->outputVideoHeight = (int)$metadata['outputHeight'];
        $this->dropFrames = (bool)$metadata['dropFrames'];
        $this->effectiveTargetFps = isset($metadata['effectiveTargetFps']) ? (float)$metadata['effectiveTargetFps'] : null;
        $this->audioSpecificConfig = base64_decode($metadata['audioConfig'], true) ?: '';
        $this->audioSampleRate = (int)$metadata['audioSampleRate'];
        $this->audioChannels = (int)$metadata['audioChannels'];
        $this->audioObjectType = (int)$metadata['audioObjectType'];
        $this->audioTimescale = (int)$metadata['audioTimescale'];
        $this->audioSourceCodec = $metadata['audioCodec'] ?? null;
        if ($this->audioSourceCodec === null && !empty($metadata['hasAudio'])) {
            $this->audioSourceCodec = AudioPolicy::CODEC_UNKNOWN;
        }
        if (array_key_exists('audioTarget', $metadata)) {
            $this->audioTarget = $metadata['audioTarget'];
        }
        if (isset($metadata['mp3Bitrate'])) {
            $this->mp3Bitrate = (int)$metadata['mp3Bitrate'];
        }
        if (isset($metadata['audioOptions']) && is_array($metadata['audioOptions'])) {
            $this->audioSourceOptions = $metadata['audioOptions'];
        }
        $this->audioPreSkip = (int)($metadata['audioPreSkip'] ?? 0);
        $this->audioGain = (int)($metadata['audioGain'] ?? 0);
        $this->videoTrack = !empty($metadata['hasVideo']) ? ['pipeline' => true] : null;
        $this->audioTrack = !empty($metadata['hasAudio']) ? ['pipeline' => true] : null;
        $this->initAudioPolicy();
    }

    /** 由源格式 + 目标格式做一次策略判定（三驱动共用 AudioPolicy，不各写一份） */
    private function initAudioPolicy(): void
    {
        if ($this->audioTrack === null || $this->audioSourceCodec === null) return;
        $decision = AudioPolicy::decide($this->audioSourceCodec, $this->audioTarget);
        $this->audioOutputCodec = $decision['output'];
        $this->audioMode = $decision['mode'];
        if ($this->audioMode === AudioPolicy::MODE_SILENT) {
            // 源无解码器：准备静音 AAC 源（采样率/声道优先沿用源声明），实际样本在收尾时按视频时长补齐
            $source = new SilentAacSource($this->audioSampleRate, $this->audioChannels);
            $this->silentAudioSource = $source;
            $this->audioSampleRate = $source->rate();
            $this->audioChannels = $source->channels();
            $this->audioTimescale = $source->rate();
            $this->audioSpecificConfig = $source->audioSpecificConfig();
            $this->audioObjectType = 2;
            return;
        }
        if ($this->audioMode !== AudioPolicy::MODE_TRANSCODE) return;
        $this->audioTranscoder = new AudioTranscoder(
            $this->audioSourceCodec,
            $this->audioOutputCodec,
            $this->audioSampleRate,
            $this->audioChannels,
            $this->mp3Bitrate,
            $this->audioSourceOptions
        );
        if ($this->audioOutputCodec === AudioPolicy::CODEC_AAC) {
            $asc = $this->audioTranscoder->outputAudioSpecificConfig();
            if ($asc !== '') {
                $this->audioSpecificConfig = $asc;
                $this->parseAudioSpecificConfig($asc);
            }
        }
        $this->audioTimescale = $this->audioSampleRate;
    }

    public function processPipelineSample(array $metadata, string $payload): void
    {
        if (($metadata['sampleType'] ?? '') === 'audio') {
            $this->intakeAudioPayload($payload, (int)$metadata['dtsMs']);
            return;
        }
        if (!empty($metadata['drop'])) return;
        if (!empty($metadata['encoded']) && isset($metadata['encodedVariant'])) {
            // GOP worker 已完成解码+缩放+编码：payload = [u32 sampleLen][原始AVCC sample][AnnexB编码帧]
            // 预编码样本与逐帧流水线编码互斥：先冲刷在途帧与排队音频保序
            $variant = $metadata['encodedVariant'];
            $annexb = substr($payload, (int)$variant['offset'], (int)$variant['length']);
            $this->flushPendingVideo();
            $this->appendPipelineEncodedSample($metadata, $this->splitAnnexBToNals($annexb));
            return;
        }
        $this->pipelineYuv = null;
        if (!empty($metadata['decoded'])) {
            $length = unpack('N', substr($payload, 0, 4))[1];
            $avcData = substr($payload, 4, $length);
            $this->pipelineYuv = substr($payload, 4 + $length);
        } else {
            $avcData = $payload;
        }
        $this->intakeVideoSample([
            'data' => $avcData, 'dtsMs' => (int)$metadata['dtsMs'],
            'ctsMs' => (int)$metadata['ctsMs'], 'keyframe' => (bool)$metadata['keyframe'],
        ]);
        $this->pipelineYuv = null;
    }

    private function appendPipelineEncodedSample(array $metadata, array $nals): void
    {
        if ($this->videoSamples === []) {
            $this->extractSpsPpsFromNals($nals);
            $this->buildEncAvccHeader();
        }
        $data = $this->extractVideoAvccFromNals($nals);
        if ($data === '') return;
        // 与逐帧转码路径一致：抽帧时按目标帧率生成 CFR 时间轴（帧序号 × 帧时长），不抽帧保留源 DTS；
        // B 模式 cts 由 worker 透传。注意 ctts version 0 的 offset 按规范是无符号数
        // （负 CTS 仅 version 1 合法），本路径 DTS 网格前移保证 cts 恒非负，version 0 直接写入
        $frameIndex = count($this->videoSamples);
        $timestamp = ($this->dropFrames && $this->effectiveTargetFps !== null && $this->effectiveTargetFps > 0)
            ? (int)round($frameIndex * 1000 / $this->effectiveTargetFps)
            : (int)$metadata['dtsMs'];
        $this->storePipelineSample($this->videoSamples, $data, [
            'timestamp' => $timestamp,
            'cts' => (int)($metadata['ctsMs'] ?? 0),
            'keyframe' => $frameIndex === 0 || !empty($metadata['keyframe']),
        ]);
    }

    /** 将 GOP worker 产出的 AnnexB 码流拆为带4字节起始码的 NAL 数组（编码器输出格式） */
    private function splitAnnexBToNals(string $annexb): array
    {
        $nals = [];
        foreach (NalUtil::splitNalUnits($annexb) as $unit) {
            $nals[] = "\x00\x00\x00\x01" . $unit['raw'];
        }
        return $nals;
    }

    public function finishPipelineOutput(string $outputFile): void
    {
        // 先冲洗解码器重排队列中残留的 B 帧，再冲刷最后一帧的延迟编码及排队音频
        $this->flushDecoderFrames();
        $this->flushPendingVideo();
        $this->flushAudioTranscoder();
        $this->synthesizeSilentAudio();
        if (!$this->videoTrack && !$this->audioTrack) throw new \RuntimeException('未找到有效的视频或音频轨道');
        $this->buildPipelineMp4($outputFile);
    }

    public function cleanupPipelineOutput(): void
    {
        if (is_resource($this->pipelineMediaHandle)) fclose($this->pipelineMediaHandle);
        $this->pipelineMediaHandle = null;
        if ($this->pipelineTempFile !== null && is_file($this->pipelineTempFile)) @unlink($this->pipelineTempFile);
        $this->pipelineTempFile = null;
    }

    /* ========== 媒体数据提取与转码 ========== */

    private function extractAndTranscodeMediaData(): void
    {
        $mdat = $this->findBox($this->boxTree, 'mdat');
        if (!$mdat) throw new \RuntimeException("未找到mdat盒子");
        $moov = $this->findBox($this->boxTree, 'moov');
        if (!$moov) return;
        // 单进程路径同样走统一音频策略
        $this->initAudioPolicy();

        $allSamples = [];

        if ($this->videoTrack) {
            $videoSamples = $this->extractVideoSamples();
            $this->configureOutputVideo($videoSamples);
            foreach ($videoSamples as &$s) {
                $s['type'] = 'video';
            }
            unset($s);
            $allSamples = array_merge($allSamples, $videoSamples);
        }

        if ($this->audioTrack && !str_starts_with((string)$this->audioSourceCodec, AudioPolicy::CODEC_UNKNOWN)) {
            $audioSamples = $this->extractAudioSamples();
            foreach ($audioSamples as &$s) {
                $s['type'] = 'audio';
            }
            unset($s);
            $allSamples = array_merge($allSamples, $audioSamples);
        }

        usort($allSamples, function($a, $b) {
            return $a['dtsMs'] - $b['dtsMs'];
        });

        $videoCount = 0;
        foreach ($allSamples as $sample) {
            if ($sample['type'] === 'video') {
                $this->intakeVideoSample($sample);
                $videoCount++;
                if ($this->maxFrames !== null && $videoCount >= $this->maxFrames) {
                    echo "Reached max frames limit ({$this->maxFrames})\n";
                    break;
                }
                if ($videoCount % 50 === 0) {
                    echo "Processed {$videoCount} video frames\n";
                }
            } else {
                if ($this->maxFrames !== null && $videoCount >= $this->maxFrames) continue;
                $this->intakeAudioPayload($sample['data'], (int)$sample['dtsMs']);
            }
        }
        // 先冲洗解码器重排队列中残留的 B 帧，再冲刷双缓冲末帧
        $this->flushDecoderFrames();
        $this->flushPendingVideo();
        $this->flushAudioTranscoder();
        // 源音频无解码器：按视频时长合成一条静音 AAC 轨（真实 trak + 真实样本）
        $this->synthesizeSilentAudio();
    }

    private function extractVideoSamples(): array
    {
        $trak = $this->videoTrack['trak'];
        $timescale = $this->videoTrack['timescale'];
        return $this->extractSamplesFromTrak($trak, $timescale, 'video');
    }

    private function configureOutputVideo(array $samples): void
    {
        $count = count($samples);
        if ($count >= 2) {
            $span = $samples[$count - 1]['dtsMs'] - $samples[0]['dtsMs'];
            if ($span > 0) {
                $this->sourceFps = ($count - 1) * 1000 / $span;
            }
        }
        $this->outputVideoWidth = $this->targetWidth > 0 ? $this->targetWidth : $this->srcWidth;
        $this->outputVideoHeight = $this->targetHeight > 0 ? $this->targetHeight : $this->srcHeight;
        if ($this->targetWidth > $this->srcWidth) {
            throw new \RuntimeException("目标 width {$this->targetWidth} 超过源 width {$this->srcWidth}，不支持升配");
        }
        if ($this->targetHeight > $this->srcHeight) {
            throw new \RuntimeException("目标 height {$this->targetHeight} 超过源 height {$this->srcHeight}，不支持升配");
        }
        if ($this->watermarkEnabled && ($this->wmWidth > $this->outputVideoWidth || $this->wmHeight > $this->outputVideoHeight)) {
            throw new \RuntimeException("水印尺寸 {$this->wmWidth}x{$this->wmHeight} 超过最终输出尺寸 {$this->outputVideoWidth}x{$this->outputVideoHeight}");
        }
        if ($this->targetFps > 0 && $this->sourceFps !== null && $this->targetFps > $this->sourceFps + 0.01) {
            throw new \RuntimeException("目标 fps {$this->targetFps} 高于源 fps " . round($this->sourceFps, 3) . '，不支持升帧');
        }
        // 源帧率未知时保留输入时间戳且不抽帧，避免无法确认的升帧行为。
        $this->dropFrames = $this->targetFps > 0 && $this->sourceFps !== null && $this->targetFps < $this->sourceFps - 0.01;
        $this->effectiveTargetFps = $this->dropFrames ? (float)$this->targetFps : $this->sourceFps;
    }

    private function extractAudioSamples(): array
    {
        $trak = $this->audioTrack['trak'];
        $timescale = $this->audioTrack['timescale'];
        return $this->extractSamplesFromTrak($trak, $timescale, 'audio');
    }

    private function extractSamplesFromTrak(array $trak, int $timescale, string $type): array
    {
        $mdia = $this->findBox([$trak], 'mdia');
        if (!$mdia) return [];
        $minf = $this->findBox([$mdia], 'minf');
        if (!$minf) return [];
        $stbl = $this->findBox([$minf], 'stbl');
        if (!$stbl) return [];

        $stsz = $this->findBox([$stbl], 'stsz');
        $stco = $this->findBox([$stbl], 'stco');
        $stsc = $this->findBox([$stbl], 'stsc');
        $stts = $this->findBox([$stbl], 'stts');
        $ctts = $this->findBox([$stbl], 'ctts');
        $stss = $this->findBox([$stbl], 'stss');

        if (!$stsz || !$stco || !$stsc || !$stts) return [];

        $stszData = $stsz['data'];
        $sampleSize = unpack('N', substr($stszData, 4, 4))[1];
        $sampleCount = unpack('N', substr($stszData, 8, 4))[1];
        $sampleSizes = [];
        if ($sampleSize == 0) {
            for ($i = 0; $i < $sampleCount; $i++) {
                $sampleSizes[] = unpack('N', substr($stszData, 12 + $i * 4, 4))[1];
            }
        } else {
            $sampleSizes = array_fill(0, $sampleCount, $sampleSize);
        }

        $stcoData = $stco['data'];
        $chunkCount = unpack('N', substr($stcoData, 4, 4))[1];
        $chunkOffsets = [];
        for ($i = 0; $i < $chunkCount; $i++) {
            $chunkOffsets[] = unpack('N', substr($stcoData, 8 + $i * 4, 4))[1];
        }

        $stscData = $stsc['data'];
        $stscEntries = unpack('N', substr($stscData, 4, 4))[1];
        $chunkMap = [];
        for ($i = 0; $i < $stscEntries; $i++) {
            $firstChunk = unpack('N', substr($stscData, 8 + $i * 12, 4))[1];
            $samplesPerChunk = unpack('N', substr($stscData, 12 + $i * 12, 4))[1];
            $chunkMap[$firstChunk] = $samplesPerChunk;
        }

        $chunkSamples = [];
        for ($chunk = 0; $chunk < $chunkCount; $chunk++) {
            $chunkNum = $chunk + 1;
            $samples = 1;
            foreach ($chunkMap as $firstChunk => $spc) {
                if ($chunkNum >= $firstChunk) $samples = $spc;
            }
            $chunkSamples[] = $samples;
        }

        $sampleOffsets = [];
        $sampleIndex = 0;
        foreach ($chunkOffsets as $chunkIdx => $chunkOffset) {
            $count = $chunkSamples[$chunkIdx];
            $runningOffset = $chunkOffset;
            for ($j = 0; $j < $count && $sampleIndex < count($sampleSizes); $j++) {
                $sampleOffsets[$sampleIndex] = $runningOffset;
                $runningOffset += $sampleSizes[$sampleIndex];
                $sampleIndex++;
            }
        }

        $sttsData = $stts['data'];
        $sttsEntries = unpack('N', substr($sttsData, 4, 4))[1];
        $timeDeltas = [];
        $pos = 8;
        for ($i = 0; $i < $sttsEntries; $i++) {
            $count = unpack('N', substr($sttsData, $pos, 4))[1];
            $delta = unpack('N', substr($sttsData, $pos + 4, 4))[1];
            $pos += 8;
            for ($j = 0; $j < $count; $j++) {
                $timeDeltas[] = $delta;
            }
        }

        $ctOffsets = [];
        if ($ctts && $type === 'video') {
            $cttsData = $ctts['data'];
            $cttsEntries = unpack('N', substr($cttsData, 4, 4))[1];
            $pos = 8;
            for ($i = 0; $i < $cttsEntries; $i++) {
                $count = unpack('N', substr($cttsData, $pos, 4))[1];
                $offset = unpack('N', substr($cttsData, $pos + 4, 4))[1];
                $pos += 8;
                for ($j = 0; $j < $count; $j++) {
                    $ctOffsets[] = $offset;
                }
            }
        }

        $keyframeSet = [];
        if ($stss && $type === 'video') {
            $stssData = $stss['data'];
            $entries = unpack('N', substr($stssData, 4, 4))[1];
            for ($i = 0; $i < $entries; $i++) {
                $keyframeSet[unpack('N', substr($stssData, 8 + $i * 4, 4))[1] - 1] = true;
            }
        }

        $samples = [];
        $dtsTicks = 0;
        for ($i = 0; $i < count($sampleSizes) && $i < count($sampleOffsets); $i++) {
            $offset = $sampleOffsets[$i];
            if ($offset < 0 || $offset + $sampleSizes[$i] > strlen($this->mp4Data)) continue;
            $rawData = substr($this->mp4Data, $offset, $sampleSizes[$i]);

            $ctsTicks = $ctOffsets[$i] ?? 0;
            $dtsMs = (int)round($dtsTicks * 1000 / $timescale);
            $ctsMs = (int)round($ctsTicks * 1000 / $timescale);
            $isKeyframe = isset($keyframeSet[$i]);

            $samples[] = [
                'data' => $rawData,
                'dtsMs' => $dtsMs,
                'ctsMs' => $ctsMs,
                'keyframe' => $isKeyframe,
                'index' => $i,
            ];

            $dtsTicks += $timeDeltas[$i] ?? 0;
        }
        return $samples;
    }

    private function intakeAudioPayload(string $data, int $dtsMs): void
    {
        if ($this->audioMode === AudioPolicy::MODE_SILENT) {
            // 源无解码器：不写入源负载（否则会把 PCM/Opus 裸数据当成 AAC 样本），静音轨在收尾时合成
            return;
        }
        if ($this->audioMode === AudioPolicy::MODE_TRANSCODE && $this->audioTranscoder !== null) {
            // Opus 首包模式兜底判定（pipeline 已在 prepare 阶段预判，这里覆盖非 pipeline 路径）
            if ($this->audioSourceCodec === AudioPolicy::CODEC_OPUS && !$this->opusProbed) {
                $this->opusProbed = true;
                $mode = AudioPolicy::opusPacketMode($data);
                if ($mode !== 'CELT') {
                    $this->switchToSilentAudioMp4('Opus/' . ($mode ?? 'invalid'));
                    return;
                }
            }
            if (!$this->audioAnchorSet) {
                // Opus pre-skip 在解码器内丢弃；整轨时间戳后移同一偏移，保持首个可听样本对齐
                $this->audioAnchorMs = $dtsMs + ($this->audioSourceCodec === AudioPolicy::CODEC_OPUS
                    ? (int)round($this->audioPreSkip * 1000 / 48000)
                    : 0);
                $this->audioAnchorSet = true;
            }
            foreach ($this->audioTranscoder->push($data) as $frame) {
                $this->enqueueOrStoreAudio($frame['data'], $this->nextTranscodedAudioTimestamp());
            }
            return;
        }
        $this->enqueueOrStoreAudio($data, $dtsMs);
    }

    /** Opus 首包非 CELT（SILK/Hybrid）：丢弃转码器，改确定性静音 AAC 轨 */
    private function switchToSilentAudioMp4(string $reason): void
    {
        if ($this->audioTarget === AudioPolicy::CODEC_MP3) {
            throw new \RuntimeException('源音频（' . $reason . '）无解码器，无法转为 MP3');
        }
        $this->audioTranscoder = null;
        $this->audioOutputCodec = AudioPolicy::CODEC_AAC;
        $this->audioMode = AudioPolicy::MODE_SILENT;
        $source = new SilentAacSource(48000, max(1, min(2, $this->audioChannels)));
        $this->silentAudioSource = $source;
        $this->audioSampleRate = $source->rate();
        $this->audioChannels = $source->channels();
        $this->audioTimescale = $source->rate();
        $this->audioSpecificConfig = $source->audioSpecificConfig();
        $this->audioObjectType = 2;
    }

    /** 音频落盘：有在途视频帧时排队（8MB 硬上限），否则直接写临时媒体文件/样本数组 */
    private function enqueueOrStoreAudio(string $data, int $timestamp): void
    {
        if ($this->pendingVideoJob !== null) {
            $this->queuedAudioBytes += strlen($data);
            if ($this->queuedAudioBytes > 8 * 1024 * 1024) {
                throw new \RuntimeException('音频排队超过 8MB 硬上限');
            }
            $this->queuedAudioSamples[] = ['data' => $data, 'timestamp' => $timestamp];
            return;
        }
        if (is_resource($this->pipelineMediaHandle)) {
            $this->storePipelineSample($this->audioSamples, $data, ['timestamp' => $timestamp]);
        } else {
            $this->audioSamples[] = ['data' => $data, 'timestamp' => $timestamp];
        }
    }

    /** 转码帧时间戳：锚点 + 已输出样本数；毫秒舍入并强制单调 */
    private function nextTranscodedAudioTimestamp(): int
    {
        $rate = max(1, $this->audioSampleRate);
        $timestamp = $this->audioAnchorMs + (int)round($this->audioOutputSamples * 1000 / $rate);
        $this->audioOutputSamples += AudioPolicy::samplesPerFrame($this->audioOutputCodec);
        if ($this->audioLastPtsRaw !== null && $timestamp <= $this->audioLastPtsRaw) {
            $timestamp = $this->audioLastPtsRaw + 1;
        }
        $this->audioLastPtsRaw = $timestamp;
        return $timestamp;
    }

    /** EOF 冲洗转码器尾部帧 */
    private function flushAudioTranscoder(): void
    {
        if ($this->audioMode !== AudioPolicy::MODE_TRANSCODE || $this->audioTranscoder === null) return;
        foreach ($this->audioTranscoder->flush() as $frame) {
            $this->enqueueOrStoreAudio($frame['data'], $this->nextTranscodedAudioTimestamp());
        }
    }

    /**
     * 源音频无解码器：合成一条内容全静音的 AAC-LC 轨。
     * 帧数按视频总时长（含最后一帧显示时长）向上取整，保证音轨不短于视频；
     * 采样率/声道已在 initAudioPolicy 按源声明确定（不可用时回落 44.1 kHz / 2 声道）。
     */
    private function synthesizeSilentAudio(): void
    {
        if ($this->audioMode !== AudioPolicy::MODE_SILENT || $this->silentAudioSource === null) return;
        if (!empty($this->audioSamples)) return;
        $endMs = $this->videoEndMs();
        if ($endMs <= 0) return;
        $source = $this->silentAudioSource;
        $frames = $source->framesFor((int)ceil($endMs));
        for ($i = 0; $i < $frames; $i++) {
            $timestamp = (int)round($i * $source->frameDurationMs());
            $this->enqueueOrStoreAudio($source->frame(), $timestamp);
        }
    }

    /** 视频总时长（毫秒，含最后一帧的显示时长），与 sample 时间戳基准无关 */
    private function videoEndMs(): float
    {
        $count = count($this->videoSamples);
        if ($count === 0) return 0.0;
        $first = (float)$this->videoSamples[0]['timestamp'];
        $last = (float)$this->videoSamples[$count - 1]['timestamp'];
        if ($count >= 2) {
            $frameMs = ($last - $first) / ($count - 1);
        } else {
            $fps = $this->effectiveTargetFps ?? $this->sourceFps;
            $frameMs = ($fps !== null && $fps > 0) ? 1000.0 / $fps : 40.0;
        }
        return max(0.0, $last - $first) + max(1.0, $frameMs);
    }

    private function flushQueuedAudio(): void
    {
        foreach ($this->queuedAudioSamples as $queued) {
            if (is_resource($this->pipelineMediaHandle)) {
                $this->storePipelineSample($this->audioSamples, $queued['data'], ['timestamp' => $queued['timestamp']]);
            } else {
                $this->audioSamples[] = $queued;
            }
        }
        $this->queuedAudioSamples = [];
        $this->queuedAudioBytes = 0;
    }

    private function flushPendingVideo(): void
    {
        // B 模式：先把显示序缓冲残留帧按 P 补齐（单进程用 bQueue，分布式输出 worker 为空、无副作用）
        if ($this->bframes) $this->drainBQueue(true);
        if ($this->pendingVideoJob !== null) {
            $job = $this->pendingVideoJob;
            $this->pendingVideoJob = null;
            $this->emitTranscodeVideoSample($job);
        }
        $this->flushQueuedAudio();
    }

    /**
     * EOF 时冲洗解码器重排队列中残留的 B 帧（最多 bframes 帧）。
     * B 模式走 flushDecoderFramesB：尾帧补入 bQueue 后 drain；
     * P 模式：逐帧送入编码器双缓冲流水线并落盘。
     */
    private function flushDecoderFrames(): void
    {
        if ($this->bframes) {
            $this->flushDecoderFramesB();
            return;
        }
        if ($this->decoder === null) return;
        $frames = $this->decoder->flushOutput();
        if (empty($frames)) return;

        $targetW = $this->outputVideoWidth;
        $targetH = $this->outputVideoHeight;
        $frameDurMs = ($this->sourceFps !== null && $this->sourceFps > 0) ? 1000 / $this->sourceFps : 40;
        $flushIdx = 0;

        foreach ($frames as $f) {
            $yuvData = $f['data'];
            $isIdr = !empty($f['isIdr']);
            $flushIdx++;

            if ($targetW !== $this->srcWidth || $targetH !== $this->srcHeight) {
                $yuvData = $this->scaler->scaleYUV420P($yuvData, $this->srcWidth, $this->srcHeight, $targetW, $targetH);
            }
            if ($this->watermarkEnabled) {
                $yuvData = $this->applyWatermark($yuvData, $targetW, $targetH);
            }

            $this->encoder->setResolution($targetW, $targetH);
            if ($this->targetBitrate > 0) {
                $this->encoder->setBitrate($this->targetBitrate);
            } else {
                $this->encoder->setQp($this->targetQp);
            }

            $this->encoder->startFrame($yuvData, $isIdr);
            $this->videoFrameCount++;

            // 尾部帧的源 DTS 不可得（显示序帧与 sample 的对应关系已断裂），
            // 按源帧率从最后喂入 sample 的时间轴顺延
            $job = [
                'dtsMs' => (int)round(($this->lastSourceDtsMs ?? 0) + $flushIdx * $frameDurMs),
                'isKey' => $isIdr,
            ];

            if ($this->pendingVideoJob !== null) {
                $pendingJob = $this->pendingVideoJob;
                $this->pendingVideoJob = null;
                $this->emitTranscodeVideoSample($pendingJob);
            }
            $this->flushQueuedAudio();
            $this->pendingVideoJob = $job;
        }
    }

    private function intakeVideoSample(array $sample): void
    {
        $avcData = $sample['data'];
        $isKeyFrame = $sample['keyframe'];
        $dtsMs = $sample['dtsMs'];
        $ctsMs = $sample['ctsMs'];

        $needTranscode = $this->srcInitialized && (
            $this->targetWidth > 0 && $this->srcWidth !== $this->targetWidth ||
            $this->targetHeight > 0 && $this->srcHeight !== $this->targetHeight ||
            $this->targetBitrate > 0 ||
            $this->dropFrames ||
            $this->watermarkEnabled ||
            $this->bframes
        );

        if (!$needTranscode) {
            // 直通帧不经过编码器：先冲刷在途转码帧与排队音频
            $this->flushPendingVideo();
            if (empty($this->videoSamples)) {
                $this->outputVideoWidth = $this->srcWidth;
                $this->outputVideoHeight = $this->srcHeight;
                $this->buildSrcAvccHeader();
            }
            $sampleMetadata = [
                'timestamp' => $dtsMs,
                'cts' => $ctsMs,
                'keyframe' => $isKeyFrame,
            ];
            if (is_resource($this->pipelineMediaHandle)) $this->storePipelineSample($this->videoSamples, $avcData, $sampleMetadata);
            else $this->videoSamples[] = ['data' => $avcData] + $sampleMetadata;
            return;
        }

        $job = $this->prepareTranscodeVideoSample($sample);
        if ($job === null) return; // 首帧非关键帧 / 抽帧丢弃

        // 关键顺序：新帧已 startFrame（worker 在途）→ 此时 finish 上一帧，
        // 主进程串行 CAVLC 与 worker 对新帧的运动估计重叠执行
        if ($this->pendingVideoJob !== null) {
            $this->emitTranscodeVideoSample($this->pendingVideoJob);
        }
        $this->flushQueuedAudio();
        $this->pendingVideoJob = $job;
    }

    /**
     * 完成解码/缩放/抽帧判定并 startFrame；保留输出槽位计数。
     * 返回待 finish 的任务，null 表示该帧不输出。
     */
    private function prepareTranscodeVideoSample(array $sample): ?array
    {
        $avcData = $sample['data'];
        $isKeyFrame = $sample['keyframe'];
        $dtsMs = $sample['dtsMs'];

        $targetW = $this->outputVideoWidth;
        $targetH = $this->outputVideoHeight;

        // 多进程模式中所有输入 sample 已由 decoder worker 解码；单进程仍在此维持参考链。
        // 解码器按显示序返回帧（B 帧重排），返回 null 表示帧仍在重排队列中
        if ($this->pipelineYuv !== null) {
            $yuvData = $this->pipelineYuv;
            $decodedIsIdr = $isKeyFrame;
        } else {
            $decoded = $this->decodeNaluToYuv($avcData);
            if ($decoded === null) return null;
            $yuvData = $decoded['data'];
            $decodedIsIdr = $decoded['isIdr'];
        }
        $this->lastSourceDtsMs = $dtsMs;

        // 上一帧虽未 finish 落盘，但输出槽位已被保留
        $outputCount = count($this->videoSamples) + ($this->pendingVideoJob !== null ? 1 : 0);
        if ($this->firstVideoDtsMs === null) {
            if (!$decodedIsIdr) return null;
            $this->firstVideoDtsMs = $dtsMs;
        }
        $relativeTime = $dtsMs - $this->firstVideoDtsMs;
        // 关键帧一律保留（关键帧对齐抽帧），其余按相对首关键帧时间轴选择
        // 注意：B 帧重排后解码返回的是显示序帧，源 sample 的 keyframe 标志属于解码序，
        // 因此 IDR 判定必须用解码帧自身的 isIdr
        $shouldOutput = !$this->dropFrames
            || $outputCount === 0
            || $decodedIsIdr
            || $relativeTime * $this->effectiveTargetFps >= $outputCount * 1000;
        if (!$shouldOutput) {
            return null;
        }

        if ($this->pipelineYuv === null) {
            if ($targetW !== $this->srcWidth || $targetH !== $this->srcHeight) {
                $yuvData = $this->scaler->scaleYUV420P(
                    $yuvData,
                    $this->srcWidth, $this->srcHeight,
                    $targetW, $targetH
                );
            }

            if ($this->watermarkEnabled) {
                $yuvData = $this->applyWatermark($yuvData, $targetW, $targetH);
            }
        }

        $this->encoder->setResolution($targetW, $targetH);
        if ($this->targetBitrate > 0) {
            $this->encoder->setBitrate($this->targetBitrate);
        } else {
            $this->encoder->setQp($this->targetQp);
        }
        $encoderFps = $this->effectiveTargetFps ?? $this->sourceFps;
        if ($encoderFps !== null && $encoderFps > 0) {
            $this->encoder->setFps(max(1, (int)round($encoderFps)));
        }

        // B 模式：显示序帧入 3 帧重排缓冲，凑齐三元组后按编码序（锚点→B→B）落盘
        if ($this->bframes) {
            $this->videoFrameCount++;
            $this->bQueue[] = ['yuv' => $yuvData, 'isKey' => $decodedIsIdr, 'idx' => $this->bDisplayCounter++];
            $this->drainBQueue(false);
            return null; // B 模式绕开双缓冲 pendingVideoJob
        }

        $isOutputKey = $outputCount === 0 || $decodedIsIdr;
        $this->encoder->startFrame($yuvData, $isOutputKey);
        $this->videoFrameCount++;

        return [
            'dtsMs' => $dtsMs,
            'isKey' => $decodedIsIdr,
        ];
    }

    /**
     * finishFrame 取出在途编码结果并落盘（其 CAVLC 与下一帧 worker 计算重叠）。
     */
    private function emitTranscodeVideoSample(array $job): void
    {
        $dtsMs = $job['dtsMs'];
        $isKeyFrame = $job['isKey'];
        $targetW = $this->outputVideoWidth;
        $targetH = $this->outputVideoHeight;

        $encodedNals = $this->encoder->finishFrame();

        if (empty($this->videoSamples)) {
            $this->extractSpsPpsFromNals($encodedNals);
            $this->buildEncAvccHeader();
            $this->outputVideoWidth = $targetW;
            $this->outputVideoHeight = $targetH;
        }

        $videoAvcc = $this->extractVideoAvccFromNals($encodedNals);
        if ($videoAvcc === '') return;

        $frameIndex = count($this->videoSamples);
        $transcodeTimestamp = $this->dropFrames
            ? (int)round($frameIndex * 1000 / $this->effectiveTargetFps)
            : $dtsMs;

        $sampleMetadata = [
            'timestamp' => $transcodeTimestamp,
            'cts' => 0,
            'keyframe' => $frameIndex === 0 || $isKeyFrame,
        ];
        if (is_resource($this->pipelineMediaHandle)) $this->storePipelineSample($this->videoSamples, $videoAvcc, $sampleMetadata);
        else $this->videoSamples[] = ['data' => $videoAvcc] + $sampleMetadata;
    }

    /**
     * 显示序缓冲 → 编码序落盘（与 FLV Recoder / MP4 Worker 同构）。
     * 固定 3 帧延迟的 IBBP：锚点在显示序 0、3、6…，编码序 = 锚点 → B1 → B2。
     * ctts version 0 的 offset 按规范无符号（负 CTS 仅 version 1 合法）；
     * 本路径 DTS 网格前移（B 帧解码槽=显示槽，锚点 cts=+3 帧时长）保证 cts 恒非负，
     * 故无需 FLV 的 ctsShift 偏移，version 0 直接写入。
     */
    private function drainBQueue(bool $flush): void
    {
        while (true) {
            $n = count($this->bQueue);
            if ($n === 0) break;
            $head = $this->bQueue[0];

            // 1) 队首是 IDR：直接作为锚点编码，并把 POC 基准移到本帧
            if ($head['isKey']) {
                $nals = $this->encoder->encodeFrame($head['yuv'], true);
                $this->emitBFrame($nals, $head['idx'], true);
                $this->bLastAnchorIdx = $head['idx'];
                $this->bPocBase = $head['idx'];
                array_shift($this->bQueue);
                continue;
            }

            // 2) 前 3 帧内出现 IDR：先把 IDR 之前的残留帧按 P 编码，下轮再处理 IDR
            $idrPos = null;
            for ($i = 1; $i < min(3, $n); $i++) {
                if ($this->bQueue[$i]['isKey']) { $idrPos = $i; break; }
            }
            if ($idrPos !== null) {
                for ($i = 0; $i < $idrPos; $i++) {
                    $e = array_shift($this->bQueue);
                    $this->emitBAnchorP($e);
                }
                continue;
            }

            // 3) 标准三元组：队首 idx == 上一锚点+1，第 3 帧 idx == 上一锚点+3
            if ($n >= 3 && $this->bLastAnchorIdx >= 0
                && $head['idx'] === $this->bLastAnchorIdx + 1
                && $this->bQueue[1]['idx'] === $this->bLastAnchorIdx + 2
                && $this->bQueue[2]['idx'] === $this->bLastAnchorIdx + 3) {
                $b1 = $this->bQueue[0];
                $b2 = $this->bQueue[1];
                $anchor = $this->bQueue[2];
                // 编码序：锚点（未来参考 L1）→ B(过去锚点=快照 L0，未来=本锚点) → B
                $this->encoder->snapshotBRefL0();
                $this->encoder->poc = 2 * ($anchor['idx'] - $this->bPocBase);
                $anchorNals = $this->encoder->encodeFrame($anchor['yuv'], false);
                $this->emitBFrame($anchorNals, $anchor['idx'], false);
                $b1Nals = $this->encoder->encodeBFrame($b1['yuv'], 2 * ($b1['idx'] - $this->bPocBase));
                $this->emitBFrame($b1Nals, $b1['idx'], false);
                $b2Nals = $this->encoder->encodeBFrame($b2['yuv'], 2 * ($b2['idx'] - $this->bPocBase));
                $this->emitBFrame($b2Nals, $b2['idx'], false);
                array_splice($this->bQueue, 0, 3);
                $this->bLastAnchorIdx = $anchor['idx'];
                continue;
            }

            // 4) 冲刷时把残留帧按显示序 P 编码
            if ($flush) {
                $e = array_shift($this->bQueue);
                if ($this->bLastAnchorIdx < 0) {
                    $nals = $this->encoder->encodeFrame($e['yuv'], true);
                    $this->emitBFrame($nals, $e['idx'], true);
                    $this->bPocBase = $e['idx'];
                } else {
                    $this->emitBAnchorP($e);
                }
                $this->bLastAnchorIdx = $e['idx'];
                continue;
            }
            break;
        }
    }

    /** 把一帧按锚点 P 编码（参考上一锚点重建帧），推进锚点基准 */
    private function emitBAnchorP(array $e): void
    {
        $this->encoder->snapshotBRefL0();
        $this->encoder->poc = 2 * ($e['idx'] - $this->bPocBase);
        $nals = $this->encoder->encodeFrame($e['yuv'], false);
        $this->emitBFrame($nals, $e['idx'], false);
        $this->bLastAnchorIdx = $e['idx'];
    }

    /**
     * B 模式编码序落盘一帧：DTS 按编码序步进，CTS =（显示序 − 编码序）×帧时长。
     * DTS 网格前移使 cts 恒非负（B 帧 cts=0、锚点 cts=+3 帧时长），
     * ctts version 0（无符号 offset）直接写入即合法；若未来 cts 可能出现负值，
     * 必须升级 ctts version 1（有符号 offset）。
     */
    private function emitBFrame(array $nals, int $displayIdx, bool $isKey): void
    {
        $fps = $this->effectiveTargetFps ?? $this->sourceFps;
        $frameDurMs = ($fps !== null && $fps > 0) ? 1000.0 / $fps : 40.0;
        $dts = (int)round($this->bDtsCounter * $frameDurMs);
        $cts = (int)round(($displayIdx - $this->bDtsCounter) * $frameDurMs);

        if (empty($this->videoSamples)) {
            $this->extractSpsPpsFromNals($nals);
            $this->buildEncAvccHeader();
        }

        $videoAvcc = $this->extractVideoAvccFromNals($nals);
        if ($videoAvcc === '') return;

        $frameIndex = count($this->videoSamples);
        $sampleMetadata = [
            'timestamp' => $dts,
            'cts' => $cts,
            'keyframe' => $frameIndex === 0 || $isKey,
        ];
        if (is_resource($this->pipelineMediaHandle)) $this->storePipelineSample($this->videoSamples, $videoAvcc, $sampleMetadata);
        else $this->videoSamples[] = ['data' => $videoAvcc] + $sampleMetadata;

        $this->bDtsCounter++;
    }

    /** EOF 时把解码器重排队列中残留的 B 尾帧补入显示序缓冲，随后 drain */
    private function flushDecoderFramesB(): void
    {
        if ($this->decoder === null) return;
        $frames = $this->decoder->flushOutput();
        if (empty($frames)) return;

        $targetW = $this->outputVideoWidth;
        $targetH = $this->outputVideoHeight;

        foreach ($frames as $f) {
            if (empty($f['data'])) continue;
            $yuvData = $f['data'];

            if ($targetW !== $this->srcWidth || $targetH !== $this->srcHeight) {
                $yuvData = $this->scaler->scaleYUV420P($yuvData, $this->srcWidth, $this->srcHeight, $targetW, $targetH);
            }
            if ($this->watermarkEnabled) {
                $yuvData = $this->applyWatermark($yuvData, $targetW, $targetH);
            }

            $this->encoder->setResolution($targetW, $targetH);
            if ($this->targetBitrate > 0) $this->encoder->setBitrate($this->targetBitrate);
            else $this->encoder->setQp($this->targetQp);
            $encoderFps = $this->effectiveTargetFps ?? $this->sourceFps;
            if ($encoderFps !== null && $encoderFps > 0) $this->encoder->setFps(max(1, (int)round($encoderFps)));

            $this->bQueue[] = ['yuv' => $yuvData, 'isKey' => !empty($f['isIdr']), 'idx' => $this->bDisplayCounter++];
        }
    }

    /** @return array{data:string,isIdr:bool}|null 解码器按显示序返回；null 表示帧仍在重排队列中 */
    private function decodeNaluToYuv(string $avcData): ?array
    {
        $nalUnits = $this->extractNalUnitsFromAVCC($avcData);

        if ($this->srcSpsData !== '') {
            array_unshift($nalUnits, ['type' => 7, 'data' => $this->srcSpsData]);
        }
        if ($this->srcPpsData !== '') {
            array_unshift($nalUnits, ['type' => 8, 'data' => $this->srcPpsData]);
        }

        $frame = $this->decoder->decode($nalUnits, false, true, false);
        if ($frame && !empty($frame['data'])) {
            return ['data' => $frame['data'], 'isIdr' => !empty($frame['isIdr'])];
        }
        return null;
    }

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

            $nalClean = NalUtil::removeEmulationPrevention($nalRaw);
            $type = ord($nalClean[0]) & 0x1F;
            $rbspData = substr($nalClean, 1);
            $list[] = ['type' => $type, 'data' => $rbspData, 'raw' => $nalClean];
        }
        return $list;
    }

    private function extractSpsPpsFromNals(array $nals): void
    {
        foreach ($nals as $nal) {
            $nalHeaderPos = $this->findNalHeaderPos($nal);
            if ($nalHeaderPos < 0) continue;

            $nalRaw = substr($nal, $nalHeaderPos);
            $nalType = ord($nalRaw[0]) & 0x1F;

            if ($nalType === 7) {
                $this->encSpsAnnexB = $nal;
            } elseif ($nalType === 8) {
                $this->encPpsAnnexB = $nal;
            }
        }
    }

    private function extractVideoAvccFromNals(array $nals): string
    {
        $videoNalsAnnexB = '';
        foreach ($nals as $nal) {
            $nalHeaderPos = $this->findNalHeaderPos($nal);
            if ($nalHeaderPos < 0) continue;
            $nalRaw = substr($nal, $nalHeaderPos);
            $nalType = ord($nalRaw[0]) & 0x1F;
            if ($nalType === 7 || $nalType === 8) continue;
            $videoNalsAnnexB .= $nal;
        }
        if ($videoNalsAnnexB === '') return '';
        return $this->annexBToAvcc($videoNalsAnnexB);
    }

    private function annexBToAvcc(string $annexBuf): string
    {
        $nals = NalUtil::splitNalUnits($annexBuf);
        $result = '';
        foreach ($nals as $nal) {
            $nalRaw = $nal['raw'];
            $nalLen = strlen($nalRaw);
            $result .= pack('N', $nalLen) . $nalRaw;
        }
        return $result;
    }

    private function findNalHeaderPos(string $nal): int
    {
        $len = strlen($nal);
        if ($len < 4) return -1;
        if (substr($nal, 0, 4) === "\x00\x00\x00\x01") return 4;
        if ($len >= 3 && substr($nal, 0, 3) === "\x00\x00\x01") return 3;
        return -1;
    }

    private function buildEncAvccHeader(): void
    {
        $spsAnnexB = $this->encSpsAnnexB;
        $ppsAnnexB = $this->encPpsAnnexB;

        $spsPos = $this->findNalHeaderPos($spsAnnexB);
        $ppsPos = $this->findNalHeaderPos($ppsAnnexB);

        if ($spsPos < 0 || $ppsPos < 0) {
            $this->buildSrcAvccHeader();
            return;
        }

        $spsRaw = substr($spsAnnexB, $spsPos);
        $ppsRaw = substr($ppsAnnexB, $ppsPos);

        $spsLen = strlen($spsRaw);
        $ppsLen = strlen($ppsRaw);

        $config = '';
        $config .= chr(1);
        $config .= $spsLen > 1 ? $spsRaw[1] : chr(0);
        $config .= $spsLen > 2 ? $spsRaw[2] : chr(0);
        $config .= $spsLen > 3 ? $spsRaw[3] : chr(0);
        $config .= chr(0xFF);
        $config .= chr(0xE0 | 1);
        $config .= pack('n', $spsLen);
        $config .= $spsRaw;
        $config .= chr(1);
        $config .= pack('n', $ppsLen);
        $config .= $ppsRaw;

        $this->encAvccHeader = $config;
    }

    private function buildSrcAvccHeader(): void
    {
        // srcSpsData/srcPpsData 是 RBSP（已去防竞争字节），avcC 需要 EBSP 形态，补回防竞争字节
        $spsClean = "\x67" . NalUtil::addEmulationPrevention($this->srcSpsData);
        $ppsClean = "\x68" . NalUtil::addEmulationPrevention($this->srcPpsData);

        $spsLen = strlen($spsClean);
        $ppsLen = strlen($ppsClean);

        $config = '';
        $config .= chr(1);
        $config .= $spsLen > 1 ? $spsClean[1] : chr(0);
        $config .= $spsLen > 2 ? $spsClean[2] : chr(0);
        $config .= $spsLen > 3 ? $spsClean[3] : chr(0);
        $config .= chr(0xFF);
        $config .= chr(0xE0 | 1);
        $config .= pack('n', $spsLen);
        $config .= $spsClean;
        $config .= chr(1);
        $config .= pack('n', $ppsLen);
        $config .= $ppsClean;

        $this->encAvccHeader = $config;
    }

    /* ========== MP4 构建 ========== */

    private function storePipelineSample(array &$samples, string $data, array $metadata): void
    {
        if (!is_resource($this->pipelineMediaHandle)) throw new \RuntimeException('临时媒体文件未初始化');
        $size = strlen($data);
        $offset = $this->pipelineMediaSize;
        $this->writeAll($this->pipelineMediaHandle, $data);
        $this->pipelineMediaSize += $size;
        $samples[] = $metadata + ['size' => $size, 'mediaOffset' => $offset];
    }

    private function writeAll($handle, string $data): void
    {
        $offset = 0;
        $length = strlen($data);
        while ($offset < $length) {
            $written = @fwrite($handle, substr($data, $offset));
            if ($written === false || $written === 0) throw new \RuntimeException('写入临时媒体文件失败');
            $offset += $written;
        }
    }

    private function buildPipelineMp4(string $outputFile): void
    {
        if (!is_resource($this->pipelineMediaHandle) || $this->pipelineTempFile === null) {
            throw new \RuntimeException('临时媒体文件未初始化');
        }
        $this->videoTimescale = 1000;
        $this->sortAndNormalizeSamples();
        $ftyp = $this->buildFtyp();
        $moov = $this->buildMoov();
        do {
            $mediaBase = strlen($ftyp) + strlen($moov) + 8;
            $nextMoov = $this->buildMoov($mediaBase, $mediaBase);
            $stable = strlen($nextMoov) === strlen($moov);
            $moov = $nextMoov;
        } while (!$stable);

        if ($this->pipelineMediaSize > 0xFFFFFFFF - 8) throw new \RuntimeException('临时媒体数据超过 32 位 mdat 限制');
        if (@fseek($this->pipelineMediaHandle, 0) !== 0) throw new \RuntimeException('无法定位临时媒体文件');
        $this->writeAll($this->pipelineMediaHandle, pack('N', $this->pipelineMediaSize + 8) . 'mdat');
        if (!@fflush($this->pipelineMediaHandle)) throw new \RuntimeException('无法刷新临时媒体文件');
        fclose($this->pipelineMediaHandle);
        $this->pipelineMediaHandle = null;

        $finalTemp = $outputFile . '.final.' . bin2hex(random_bytes(8)) . '.tmp';
        $source = null;
        $target = null;
        try {
            $source = @fopen($this->pipelineTempFile, 'rb');
            $target = @fopen($finalTemp, 'x+b');
            if (!is_resource($source) || !is_resource($target)) throw new \RuntimeException('无法创建最终临时文件');
            $this->writeAll($target, $ftyp . $moov);
            if (stream_copy_to_stream($source, $target) === false) throw new \RuntimeException('复制临时媒体数据失败');
            if (!fflush($target)) throw new \RuntimeException('无法刷新最终临时文件');
            fclose($source); $source = null;
            fclose($target); $target = null;
            if (!@rename($finalTemp, $outputFile)) throw new \RuntimeException("无法原子替换输出文件: {$outputFile}");
            @unlink($this->pipelineTempFile);
            $this->pipelineTempFile = null;
        } finally {
            if (is_resource($source)) fclose($source);
            if (is_resource($target)) fclose($target);
            if (is_file($finalTemp)) @unlink($finalTemp);
        }
    }

    private function buildMp4(string $outputFile): void
    {
        // 输出 movie timescale 与视频 media timescale 沿用当前结构，统一为毫秒刻度。
        $this->videoTimescale = 1000;
        $this->sortAndNormalizeSamples();

        $ftyp = $this->buildFtyp();
        $mdat = $this->buildMdat();

        $ftypSize = strlen($ftyp);

        $videoTotalSize = 0;
        foreach ($this->videoSamples as $sample) {
            $videoTotalSize += strlen($sample['data']);
        }

        $stcoVideoBase = $ftypSize + 10000;
        $stcoAudioBase = $stcoVideoBase + $videoTotalSize;

        $moov = $this->buildMoov($stcoVideoBase, $stcoAudioBase);

        $moovSize = strlen($moov);
        $mdatHeaderSize = 8;
        $actualStcoVideoBase = $ftypSize + $moovSize + $mdatHeaderSize;
        $actualStcoAudioBase = $actualStcoVideoBase + $videoTotalSize;

        if ($stcoVideoBase != $actualStcoVideoBase) {
            $moov = $this->buildMoov($actualStcoVideoBase, $actualStcoAudioBase);
        }

        file_put_contents($outputFile, $ftyp . $moov . $mdat);
    }

    private function sortAndNormalizeSamples(): void
    {
        usort($this->videoSamples, function($a, $b) {
            if ($a['timestamp'] != $b['timestamp']) {
                return $a['timestamp'] - $b['timestamp'];
            }
            return 0;
        });

        $prevTimestamp = -1;
        foreach ($this->videoSamples as &$sample) {
            if ($sample['timestamp'] <= $prevTimestamp) {
                $sample['timestamp'] = $prevTimestamp + 1;
            }
            $prevTimestamp = $sample['timestamp'];
        }
        unset($sample);

        if (!empty($this->videoSamples)) {
            $baseTs = $this->videoSamples[0]['timestamp'];
            foreach ($this->videoSamples as &$sample) {
                $sample['timestamp'] -= $baseTs;
            }
            unset($sample);
        }

        usort($this->audioSamples, function($a, $b) {
            if ($a['timestamp'] != $b['timestamp']) {
                return $a['timestamp'] - $b['timestamp'];
            }
            return 0;
        });
    }

    private function calculateVideoSampleDurations(): array
    {
        $count = count($this->videoSamples);
        if ($count === 0) return [];

        $durations = [];
        $positiveTotal = 0;
        $positiveCount = 0;
        for ($i = 0; $i < $count - 1; $i++) {
            $currentTicks = (int)round($this->videoSamples[$i]['timestamp'] * $this->videoTimescale / 1000);
            $nextTicks = (int)round($this->videoSamples[$i + 1]['timestamp'] * $this->videoTimescale / 1000);
            $delta = max(1, $nextTicks - $currentTicks);
            $durations[] = $delta;
            $positiveTotal += $delta;
            $positiveCount++;
        }

        if ($positiveCount > 0) {
            $lastDuration = max(1, (int)round($positiveTotal / $positiveCount));
        } else {
            $fps = $this->calculateVideoFrameRate();
            $lastDuration = max(1, (int)round($this->videoTimescale / $fps));
        }
        $durations[] = $lastDuration;
        return $durations;
    }

    private function calculateAudioSampleDuration(): int
    {
        if ($this->audioOutputCodec === AudioPolicy::CODEC_MP3) {
            return AudioPolicy::samplesPerFrame(AudioPolicy::CODEC_MP3);
        }
        return in_array($this->audioObjectType, [5, 29], true) ? 2048 : 1024;
    }

    private function calculateVideoDurationTicks(): int
    {
        return array_sum($this->calculateVideoSampleDurations());
    }

    private function calculateAudioDurationTicks(): int
    {
        return count($this->audioSamples) * $this->calculateAudioSampleDuration();
    }

    private function calculateMovieDuration(): int
    {
        $videoMovieTicks = $this->videoTimescale > 0
            ? (int)round($this->calculateVideoDurationTicks())
            : 0;
        $audioMovieTicks = $this->audioTimescale > 0
            ? (int)round($this->calculateAudioDurationTicks() * $this->videoTimescale / $this->audioTimescale)
            : 0;
        return max($videoMovieTicks, $audioMovieTicks);
    }

    private function buildFtyp(): string
    {
        $data = pack('C*',
            0x69, 0x73, 0x6F, 0x6D, 0x00, 0x00, 0x00, 0x01,
            0x69, 0x73, 0x6F, 0x6D, 0x61, 0x76, 0x63, 0x31,
            0x6D, 0x70, 0x34, 0x32, 0x00, 0x00, 0x00, 0x01,
            0x6D, 0x70, 0x34, 0x31
        );
        return $this->box('ftyp', $data);
    }

    private function buildMoov(int $stcoVideoBase = 0, int $stcoAudioBase = 0): string
    {
        $duration = $this->calculateMovieDuration();
        $mvhd = $this->buildMvhd($this->videoTimescale, $duration);
        $tracks = [];

        if (!empty($this->videoSamples)) {
            $tracks[] = $this->buildVideoTrak($stcoVideoBase);
        }
        if (!empty($this->audioSamples)) {
            $tracks[] = $this->buildAudioTrak($stcoAudioBase);
        }

        return $this->box('moov', $mvhd, ...$tracks);
    }

    private function buildMvhd(int $timescale, int $duration): string
    {
        $data = pack('C*',
            0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,
            ($timescale>>24)&0xFF, ($timescale>>16)&0xFF, ($timescale>>8)&0xFF, $timescale&0xFF,
            ($duration>>24)&0xFF, ($duration>>16)&0xFF, ($duration>>8)&0xFF, $duration&0xFF,
            0x00,0x01,0x00,0x00,0x01,0x00,0x00,0x00,0x00,0x00,0x00,0x00,
            0x00,0x00,0x00,0x00,0x00,0x01,0x00,0x00,0x00,0x00,0x00,0x00,
            0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x01,0x00,0x00,
            0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,
            0x40,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,
            0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,
            0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0xFF,0xFF,0xFF,0xFF
        );
        return $this->box('mvhd', $data);
    }

    private function buildVideoTrak(int $stcoBase = 0): string
    {
        $trackId = 1;
        $mediaDuration = $this->calculateVideoDurationTicks();
        $movieDuration = $this->videoTimescale > 0
            ? (int)round($mediaDuration)
            : 0;

        $tkhd = $this->buildTkhd($trackId, $movieDuration, $this->outputVideoWidth, $this->outputVideoHeight);
        $mdhd = $this->buildMdhd($this->videoTimescale, $mediaDuration);
        $hdlr = $this->buildHdlr('vide');
        $minf = $this->buildMinf('video', $stcoBase);

        $mdia = $this->box('mdia', $mdhd, $hdlr, $minf);
        return $this->box('trak', $tkhd, $mdia);
    }

    private function buildAudioTrak(int $stcoBase = 0): string
    {
        $trackId = 2;
        $mediaDuration = $this->calculateAudioDurationTicks();
        $movieDuration = $this->audioTimescale > 0
            ? (int)round($mediaDuration * $this->videoTimescale / $this->audioTimescale)
            : 0;

        $tkhd = $this->buildTkhd($trackId, $movieDuration, 0, 0);
        $mdhd = $this->buildMdhd($this->audioTimescale, $mediaDuration);
        $hdlr = $this->buildHdlr('soun');
        $minf = $this->buildMinf('audio', $stcoBase);

        $mdia = $this->box('mdia', $mdhd, $hdlr, $minf);
        return $this->box('trak', $tkhd, $mdia);
    }

    private function buildTkhd(int $trackId, int $duration, int $width, int $height): string
    {
        $fixedWidth = $width << 16;
        $fixedHeight = $height << 16;

        $data = pack('C*',
            0x00,0x00,0x00,0x07,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,
            ($trackId>>24)&0xFF, ($trackId>>16)&0xFF, ($trackId>>8)&0xFF, $trackId&0xFF,
            0x00,0x00,0x00,0x00,
            ($duration>>24)&0xFF, ($duration>>16)&0xFF, ($duration>>8)&0xFF, $duration&0xFF,
            0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,
            0x00,0x00,0x00,0x00,0x00,0x01,0x00,0x00,0x00,0x00,0x00,0x00,
            0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x01,0x00,0x00,
            0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,
            0x40,0x00,0x00,0x00,
            ($fixedWidth>>24)&0xFF, ($fixedWidth>>16)&0xFF, ($fixedWidth>>8)&0xFF, $fixedWidth&0xFF,
            ($fixedHeight>>24)&0xFF, ($fixedHeight>>16)&0xFF, ($fixedHeight>>8)&0xFF, $fixedHeight&0xFF
        );
        return $this->box('tkhd', $data);
    }

    private function buildMdhd(int $timescale, int $duration): string
    {
        $data = pack('C*',
            0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,
            ($timescale>>24)&0xFF, ($timescale>>16)&0xFF, ($timescale>>8)&0xFF, $timescale&0xFF,
            ($duration>>24)&0xFF, ($duration>>16)&0xFF, ($duration>>8)&0xFF, $duration&0xFF,
            0x55,0xC4,0x00,0x00
        );
        return $this->box('mdhd', $data);
    }

    private function buildHdlr(string $type): string
    {
        if ($type === 'vide') {
            $data = pack('C*',
                0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,
                0x76,0x69,0x64,0x65,0x00,0x00,0x00,0x00,
                0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,
                0x56,0x69,0x64,0x65,0x6F,0x48,0x61,0x6E,
                0x64,0x6C,0x65,0x72,0x00
            );
        } else {
            $data = pack('C*',
                0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,
                0x73,0x6F,0x75,0x6E,0x00,0x00,0x00,0x00,
                0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,
                0x53,0x6F,0x75,0x6E,0x64,0x48,0x61,0x6E,
                0x64,0x6C,0x65,0x72,0x00
            );
        }
        return $this->box('hdlr', $data);
    }

    private function buildMinf(string $type, int $stcoBase = 0): string
    {
        if ($type === 'video') {
            $vmhd = pack('C*', 0x00,0x00,0x00,0x01,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00);
            $xmhd = $this->box('vmhd', $vmhd);
        } else {
            $smhd = pack('C*', 0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00);
            $xmhd = $this->box('smhd', $smhd);
        }

        $drefData = pack('N*', 0, 1) . pack('N', 12) . "url " . pack('N', 1);
        $dref = $this->box('dref', $drefData);
        $dinf = $this->box('dinf', $dref);

        $stbl = $this->buildStbl($type, $stcoBase);
        return $this->box('minf', $xmhd, $dinf, $stbl);
    }

    private function buildStbl(string $type, int $stcoBase = 0): string
    {
        if ($type === 'video') {
            $samples = $this->videoSamples;
            $stsd = $this->buildVideoStsd();
            $stts = $this->buildVideoStts();
            $ctts = $this->buildCtts();
            $stss = $this->buildStss();
            $stsc = $this->buildStsc($samples);
            $stsz = $this->buildStsz($samples);
            $stco = $this->buildVideoStco($stcoBase);
            return $this->box('stbl', $stsd, $stts, $ctts, $stss, $stsc, $stsz, $stco);
        } else {
            $samples = $this->audioSamples;
            $stsd = $this->buildAudioStsd();
            $stts = $this->buildAudioStts();
            $stsc = $this->buildStsc($samples);
            $stsz = $this->buildStsz($samples);
            $stco = $this->buildAudioStco($stcoBase);
            return $this->box('stbl', $stsd, $stts, $stsc, $stsz, $stco);
        }
    }

    private function buildVideoStsd(): string
    {
        $avcc = $this->encAvccHeader;
        $avcCBox = $this->box('avcC', $avcc);

        $width = $this->outputVideoWidth ?: 1;
        $height = $this->outputVideoHeight ?: 1;

        $avc1Data = str_repeat("\x00", 6) .
            pack('n', 1) .
            pack('n', 0) . pack('n', 0) . pack('N', 0) . pack('N', 0) . pack('N', 0) .
            pack('n', $width) . pack('n', $height) .
            pack('N', 0x00480000) . pack('N', 0x00480000) . pack('N', 0) .
            pack('n', 1) .
            "\x00" . str_repeat("\x00", 31) .
            pack('n', 0x0018) . pack('n', 0xFFFF);

        $avc1Box = $this->box('avc1', $avc1Data, $avcCBox);
        $stsdPrefix = pack('C*', 0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x01);
        return $this->box('stsd', $stsdPrefix, $avc1Box);
    }

    private function buildAudioStsd(): string
    {
        $channelCount = $this->audioChannels;
        $sampleRate = $this->audioSampleRate;

        $mp4aData = pack('C*',
            0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x01,
            0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,
            0x00,$channelCount,0x00,0x10,0x00,0x00,0x00,0x00,
            ($sampleRate>>8)&0xFF, $sampleRate&0xFF, 0x00,0x00
        );

        $esds = $this->buildEsds();
        $esdsBox = $this->box('esds', $esds);
        $mp4aBox = $this->box('mp4a', $mp4aData, $esdsBox);

        $stsdPrefix = pack('C*', 0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x01);
        return $this->box('stsd', $stsdPrefix, $mp4aBox);
    }

    private function buildEsds(): string
    {
        // MP3onMP4（ISO/IEC 14496-14 注册的 MP3 对象类型）：mp4a + esds(OTI 0x6B,
        // 无 tag5 DecoderSpecificInfo) + SLConfig。max/avgBitrate 按实际帧尺寸计算，
        // 直通与转码两条路径都不会把配置里的目标码率误当成流码率声明
        if ($this->audioOutputCodec === AudioPolicy::CODEC_MP3) {
            [$avgBitrate, $maxBitrate] = $this->measureMp3Bitrates();
            $tag4Content = "\x6B\x15\x00\x00\x00" . pack('N', $maxBitrate) . pack('N', $avgBitrate);
            $tag4 = "\x04" . $this->writeDescriptorLength(strlen($tag4Content)) . $tag4Content;
            $tag6 = "\x06\x01\x02";
            $tag3Content = "\x00\x01\x00" . $tag4 . $tag6;
            $tag3 = "\x03" . $this->writeDescriptorLength(strlen($tag3Content)) . $tag3Content;
            return pack('N', 0) . $tag3;
        }

        $config = $this->audioSpecificConfig;
        $configSize = strlen($config);

        $tag5 = "\x05" . $this->writeDescriptorLength($configSize) . $config;

        $tag4Content = "\x40" . chr($this->audioObjectType) . "\x00\x00\x00" .
            "\x00\x00\x00\x00\x00\x00\x00\x00" . $tag5;
        $tag4 = "\x04" . $this->writeDescriptorLength(strlen($tag4Content)) . $tag4Content;

        $tag3Content = "\x00\x01\x00" . $tag4;
        $tag3 = "\x03" . $this->writeDescriptorLength(strlen($tag3Content)) . $tag3Content;

        return pack('N', 0) . $tag3;
    }

    /**
     * 按已收集的 MP3 帧实测码率：1152 样本/帧，max 取最大帧、avg 取总字节/总时长
     * @return array{0:int,1:int} [avgBitrate, maxBitrate]
     */
    private function measureMp3Bitrates(): array
    {
        $count = count($this->audioSamples);
        if ($count === 0) return [$this->mp3Bitrate, $this->mp3Bitrate];
        $frameSeconds = 1152.0 / max(1, $this->audioSampleRate);
        $total = 0;
        $maxFrame = 0;
        foreach ($this->audioSamples as $sample) {
            // 分布式路径只保留 size（数据已落盘），本地路径才有 data
            $size = $sample['size'] ?? strlen($sample['data']);
            $total += $size;
            if ($size > $maxFrame) $maxFrame = $size;
        }
        $avg = (int)round($total * 8 / ($count * $frameSeconds));
        $max = (int)round($maxFrame * 8 / $frameSeconds);
        return [$avg > 0 ? $avg : $this->mp3Bitrate, $max > 0 ? $max : $this->mp3Bitrate];
    }

    private function writeDescriptorLength(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }
        $result = '';
        while ($length > 0) {
            $byte = $length & 0x7F;
            $length >>= 7;
            if ($result !== '') {
                $byte |= 0x80;
            }
            $result = chr($byte) . $result;
        }
        return $result;
    }

    private function calculateVideoFrameRate(): float
    {
        $samples = $this->videoSamples;
        $count = count($samples);

        if ($count < 2) {
            $fallbackFps = $this->effectiveTargetFps ?? $this->sourceFps;
            return $fallbackFps !== null && $fallbackFps > 0 && $fallbackFps <= 120 ? $fallbackFps : 30;
        }

        $totalInterval = 0;
        $intervals = 0;
        for ($i = 1; $i < $count; $i++) {
            $interval = $samples[$i]['timestamp'] - $samples[$i - 1]['timestamp'];
            if ($interval > 0) {
                $totalInterval += $interval;
                $intervals++;
            }
        }

        if ($intervals == 0) {
            $fallbackFps = $this->effectiveTargetFps ?? $this->sourceFps;
            return $fallbackFps !== null && $fallbackFps > 0 && $fallbackFps <= 120 ? $fallbackFps : 30;
        }

        $avgIntervalMs = $totalInterval / $intervals;
        $fps = 1000 / $avgIntervalMs;

        if ($fps <= 0 || $fps > 120) {
            $fallbackFps = $this->effectiveTargetFps ?? $this->sourceFps;
            return $fallbackFps !== null && $fallbackFps > 0 && $fallbackFps <= 120 ? $fallbackFps : 30;
        }

        return $fps;
    }

    private function buildVideoStts(): string
    {
        $samples = $this->videoSamples;
        $count = count($samples);

        if ($count === 0) {
            return $this->box('stts', pack('N*', 0, 0));
        }

        $durations = $this->calculateVideoSampleDurations();
        $data = pack('N', 0);
        $data .= pack('N', count($durations));
        foreach ($durations as $duration) {
            $data .= pack('N', 1);
            $data .= pack('N', max(1, $duration));
        }

        return $this->box('stts', $data);
    }

    private function buildAudioStts(): string
    {
        $samples = $this->audioSamples;
        $count = count($samples);

        if ($count === 0) {
            return $this->box('stts', pack('N*', 0, 0));
        }

        $sampleDuration = $this->calculateAudioSampleDuration();
        $data = pack('N', 0);
        $data .= pack('N', 1);
        $data .= pack('N', $count);
        $data .= pack('N', $sampleDuration);

        return $this->box('stts', $data);
    }

    private function buildCtts(): string
    {
        // version 0：offset 无符号（ISO 14496-12），负 CTS 必须用 version 1（有符号）。
        // 本类 cts 恒非负（DTS 网格前移，见 drainBQueue/emitBFrame 注释），version 0 即合法。
        $samples = $this->videoSamples;
        $count = count($samples);

        if ($count === 0) {
            return $this->box('ctts', pack('N*', 0, 0));
        }

        $data = pack('N', 0);
        $data .= pack('N', $count);

        foreach ($samples as $sample) {
            $cts = (int)(($sample['cts'] ?? 0) * $this->videoTimescale / 1000);
            $data .= pack('N', 1);
            $data .= pack('N', $cts);
        }

        return $this->box('ctts', $data);
    }

    private function buildStss(): string
    {
        $syncSamples = [];
        foreach ($this->videoSamples as $index => $sample) {
            if (!empty($sample['keyframe'])) {
                $syncSamples[] = $index + 1;
            }
        }

        $data = pack('N', 0) . pack('N', count($syncSamples));
        foreach ($syncSamples as $sampleNumber) {
            $data .= pack('N', $sampleNumber);
        }

        return $this->box('stss', $data);
    }

    private function buildStsc(array $samples): string
    {
        $count = count($samples);

        if ($count === 0) {
            return $this->box('stsc', pack('N*', 0, 0));
        }

        $data = pack('N', 0);
        $data .= pack('N', $count);

        for ($i = 0; $i < $count; $i++) {
            $data .= pack('N', $i + 1);
            $data .= pack('N', 1);
            $data .= pack('N', 1);
        }

        return $this->box('stsc', $data);
    }

    private function buildStsz(array $samples): string
    {
        $count = count($samples);

        $data = pack('N', 0);
        $data .= pack('N', 0);
        $data .= pack('N', $count);

        foreach ($samples as $sample) {
            $data .= pack('N', $sample['size'] ?? strlen($sample['data']));
        }

        return $this->box('stsz', $data);
    }

    private function buildVideoStco(int $baseOffset = 0): string
    {
        $samples = $this->videoSamples;
        $count = count($samples);

        $data = pack('N', 0);
        $data .= pack('N', $count);

        $offset = $baseOffset;
        foreach ($samples as $sample) {
            $sampleOffset = isset($sample['mediaOffset']) ? $baseOffset + $sample['mediaOffset'] : $offset;
            $data .= pack('N', $sampleOffset);
            $offset += $sample['size'] ?? strlen($sample['data']);
        }

        return $this->box('stco', $data);
    }

    private function buildAudioStco(int $baseOffset = 0): string
    {
        $samples = $this->audioSamples;
        $count = count($samples);

        $data = pack('N', 0);
        $data .= pack('N', $count);

        $offset = $baseOffset;
        foreach ($samples as $sample) {
            $sampleOffset = isset($sample['mediaOffset']) ? $baseOffset + $sample['mediaOffset'] : $offset;
            $data .= pack('N', $sampleOffset);
            $offset += $sample['size'] ?? strlen($sample['data']);
        }

        return $this->box('stco', $data);
    }

    private function buildMdat(): string
    {
        $mdatData = '';

        foreach ($this->videoSamples as $sample) {
            $mdatData .= $sample['data'];
        }
        foreach ($this->audioSamples as $sample) {
            $mdatData .= $sample['data'];
        }

        return $this->box('mdat', $mdatData);
    }

    private function box(string $type, ...$datas): string
    {
        $size = 8;
        foreach ($datas as $data) $size += strlen($data);
        $result = pack('N', $size) . $type;
        foreach ($datas as $data) $result .= $data;
        return $result;
    }
}
