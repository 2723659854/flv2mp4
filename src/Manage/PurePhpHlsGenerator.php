<?php

namespace Xiaosongshu\Flv2mp4\Manage;

use Xiaosongshu\Flv2mp4\Flv\FlvParse;
use Xiaosongshu\Flv2mp4\Codec\H264Decoder;
use Xiaosongshu\Flv2mp4\Codec\H264Encoder;
use Xiaosongshu\Flv2mp4\Codec\VideoScaler;
use Xiaosongshu\Flv2mp4\Codec\NalUtil;

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

    /** 编码器 */
    private H264Encoder $encoder;

    /** 视频尺寸缩放器 */
    private VideoScaler $scaler;
    // 多分辨率解码帧缓存，避免多profile覆盖
    private array $decodedFrameCache = [];
    private string $frameCacheKey = '';

    private int $srcWidth = 0;
    private int $srcHeight = 0;
    private bool $srcInitialized = false;

    /** 最大处理帧数 调试用 */
    private ?int $maxFrames = null;
    private array $videoFrameCounts = [];
    private array $lastDts = [];
    private array $segmentFirstFrame = [];
    private string $srcSpsData = '';
    private string $srcPpsData = '';

    const VIDEO_FRAME_TYPE_KEY_FRAME = 1;
    const AVC_PACKET_TYPE_SEQUENCE_HEADER = 0;
    const AVC_PACKET_TYPE_NALU = 1;

    /**
     * 转码器初始化
     * @param array $profiles
     * @param string $outputDir
     */
    public function __construct(array $profiles, string $outputDir)
    {
        $this->profiles = $profiles;
        $this->outputDir = rtrim($outputDir, '/');

        $this->decoder = new H264Decoder();
        $this->encoder = new H264Encoder();
        $this->scaler = new VideoScaler();

        foreach ($this->profiles as $name => $profile) {
            $dir = "{$this->outputDir}/{$name}/";
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
        }

        /** 初始化空m3u8 */
        $this->ensureInitialPlaylist();
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
     * 处理flv转码
     * @param string $flvFile
     * @return void
     * @throws \Exception
     */
    public function processFlv(string $flvFile): void
    {
        if (!file_exists($flvFile)) throw new \Exception("FLV file not found: {$flvFile}");

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
            if ($frameCount % 100 === 0) echo "Processed {$frameCount} frames ({$videoCount} video)\n";
            if ($this->maxFrames !== null && $videoCount >= $this->maxFrames) {
                echo "Reached max frames limit ({$this->maxFrames}), stopping...\n";
                break;
            }
        }

        $this->closeAllSegments();
        $this->generateMasterPlaylist();
        echo "Done! Processed {$frameCount} frames\n";
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

        $relativeTime = $timestamp - $this->baseTimestamp;

        // 切片切分逻辑
        foreach ($this->profiles as $name => $profile) {
            /** 只在关键帧并且满足切片时间的时候才开始新的切片 */
            if ($isKeyFrame && ($relativeTime - $this->segmentStartTimes[$name]) >= ($this->segmentDuration * 1000)) {
                /** 关闭当前的切片 */
                $this->closeSegment($name, $relativeTime);
                $this->audioFrameCounts[$name] = 0;
                $this->audioBasePts[$name] = (int)($relativeTime * 90);
                $this->lastDts[$name] = -1;
                $this->segmentStartTimes[$name] = $relativeTime;
                $this->segmentFirstFrame[$name] = true;
               /** 开启新切片 */
                $this->startSegment($name);
                // 切换分片清空帧缓存
                $this->decodedFrameCache = [];
            }
            $this->currentSegmentLastTimes[$name] = $relativeTime;
            $this->segmentWriters[$name]['endTime'] = $relativeTime;
        }

        $cts = $avc['compositionTime'] ?? 0;
        if ($cts & 0x800000) $cts -= 0x1000000;
        $avcData = $avc['data'];

        $cacheKey = md5($avcData);
        if (!isset($this->frameCacheKey) || $this->frameCacheKey !== $cacheKey) {
            $this->decodedFrameCache = [];
            $this->frameCacheKey = $cacheKey;
        }

        foreach ($this->profiles as $name => $profile) {
            $writer = &$this->segmentWriters[$name];
            if (!is_resource($writer['handle'])) continue;

            $dts = (int)($relativeTime * 90);
            $pts = (int)(($relativeTime + $cts) * 90);
            if ($pts < $dts) $pts = $dts;

            $outputData = $avcData;
            $outputSpsPps = $this->spsPpsData[$name];
            $isTranscoded = false;

            // 转码逻辑：源尺寸≠目标尺寸才缩放编码
            $targetW = $profile['width'];
            $targetH = $profile['height'];
            $needTranscode = $this->srcInitialized && ($this->srcWidth !== $targetW || $this->srcHeight !== $targetH);
            if ($needTranscode) {
                $cacheKey = "{$profile['width']}_{$profile['height']}";
                if (!isset($this->decodedFrameCache[$cacheKey])) {
                    /** 解码h264为yuv */
                    $rawYuv = $this->decodeNaluToYuv($avcData);
                    if ($rawYuv !== null) {
                        /** 缩放尺寸 */
                        $scaledYuv = $this->scaler->scaleYUV420P($rawYuv, $this->srcWidth, $this->srcHeight, $profile['width'], $profile['height']);
                        $this->decodedFrameCache[$cacheKey] = $scaledYuv;
                    }
                }

                if (isset($this->decodedFrameCache[$cacheKey])) {
                    /** 取出被缩放的yuv */
                    $scaledYuv = $this->decodedFrameCache[$cacheKey];
                    $this->encoder->setResolution($profile['width'], $profile['height']);
                    $this->encoder->setBitrate($profile['bitrate']);
                    $this->encoder->setFps($profile['fps']);
                    $this->encoder->setQp($profile['qp'] ?? 30);
                    /** 将被缩放后的yuv重新编码为h264 */
                    $encodedNals = $this->encoder->encodeFrame($scaledYuv, $isKeyFrame);

                    $outputData = '';
                    $outputSpsPps = '';
                    foreach ($encodedNals as $nal) {
                        /** 查找nal中是否存在header */
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
            }

            // 拼接AnnexB
            if ($isTranscoded) {
                $annexb = $outputData;
            } else {
                /** 没有缩放的数据需要重新编码 */
                $annexb = $this->avccToAnnexB($outputData);
            }
            // 只在每个分片的第一帧前置SPS/PPS，避免重复导致FFmpeg解码错误
            if ($this->segmentFirstFrame[$name]) {
                $prefixNal = $outputSpsPps ?: $this->spsPpsData[$name];
                if ($prefixNal !== '') {
                    $annexb = $prefixNal . $annexb;
                }
                $this->segmentFirstFrame[$name] = false;
            }

            $pes = $this->createPES(0xE0, $annexb, $pts, ($pts !== $dts) ? $dts : null);
            // 视频首包携带PCR同步播放器
            $this->writeTSPackets($name, $this->videoPid, $pes, true, $dts);
        }
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
//            $spsPpsBuf .= "\x00\x00\x00\x01" . $spsData;
//
//            if (!$this->srcInitialized) {
//                $this->decoder->decode([['type' => 7, 'data' => $spsData]]);
//                $this->srcWidth = $this->decoder->getWidth();
//                $this->srcHeight = $this->decoder->getHeight();
//                $this->srcInitialized = true;
//            }

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
     */
    private function startSegment(string $profile): void
    {
        $writer = &$this->segmentWriters[$profile];
        $writer['sequence']++;
        $this->continuityCounters[$profile] = [];
        $filePath = "{$this->outputDir}/{$profile}/segment_{$writer['sequence']}.ts";
        $writer['handle'] = fopen($filePath, 'wb');
        // 分片头部写入PAT/PMT，兼容播放器
        $this->writePAT($profile);
        $this->writePMT($profile);
        
        $this->audioFrameCounts[$profile] = 0;
    }

    /**
     * 关闭分片并更新m3u8
     */
    private function closeSegment(string $profile, int $endTime = 0): void
    {
        $writer = &$this->segmentWriters[$profile];
        if (!is_resource($writer['handle'])) return;
        fflush($writer['handle']);
        fclose($writer['handle']);
        $writer['handle'] = null;

        $endTs = $endTime ?: $this->currentSegmentLastTimes[$profile];
        $durSec = max(0.001, round(($endTs - $this->segmentStartTimes[$profile]) / 1000.0, 3));
        $this->segmentDurations[$profile][$writer['sequence']] = $durSec;
        $this->updatePlaylist($profile);
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
            $this->addEndList($name);
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
            $m3u8Path = "{$this->outputDir}/{$name}/index.m3u8";
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
        $path = "{$this->outputDir}/{$profile}/index.m3u8";
        $tmp = $path . '.tmp';
        file_put_contents($tmp, $content);
        rename($tmp, $path);
    }

    /**
     * 末尾追加ENDLIST标记
     */
    private function addEndList(string $profile): void
    {
        $path = "{$this->outputDir}/{$profile}/index.m3u8";
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