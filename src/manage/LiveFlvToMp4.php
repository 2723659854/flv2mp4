<?php

namespace Xiaosongshu\Flv2mp4\Manage;


use Xiaosongshu\Flv2mp4\Flv\FlvParse;
use Xiaosongshu\Flv2mp4\Flv\TagDemux;
use Xiaosongshu\Flv2mp4\Mp4\MP4;
use Xiaosongshu\Flv2mp4\Mp4\MP4Remuxer;

/**
 * @purpose  直播 FLV 流实时转码为 MP4
 * @author yanglong
 * @note 该类可以拦截直播 FLV 数据流并实时转码为 MP4 分片
 */
class LiveFlvToMp4
{
    public $_config;
    public $onInitSegment = null;
    public $onMediaSegment = null;
    public $onMediaInfo = null;
    public $seekCallBack = null;
    public $error = null;


    // 分开的音视频切片回调
    public $onAudioInitSegment = null;
    public $onVideoInitSegment = null;
    public $onAudioSegment = null;
    public $onVideoSegment = null;

    public $loadmetadata = false;
    public $ftyp_moov = null;
    public $metaSuccRun = false;
    public $metas = [];
    public $parseChunk = null;
    public $hasVideo = false;
    public $hasAudio = false;

    public $_pendingResolveSeekPoint = -1;
    public $_tempBaseTime = 0;

    public $setflvBase;

    public $m4mof;
    protected $tagDemux;

    /**
     * 直播模式标志
     */
    protected $isLive = true;

    /**
     * 是否已初始化
     */
    protected $initialized = false;

    /**
     * 缓存的音频数据（用于延迟 remux）
     */
    protected $_cachedAudioTrack = [];

    /**
     * 缓存的视频数据（用于延迟 remux）
     */
    protected $_cachedVideoTrack = [];

    /**
     * 累计接收的数据量
     */
    protected $totalReceivedBytes = 0;

    /**
     * 当前处理的直播流路径
     */
    protected $streamPath = '';

    /**
     * FLV 数据缓冲区
     */
    protected $flvBuffer = '';

    /**
     * 缓冲区最小大小（字节）
     */
    protected $minBufferSize = 1024;

    /**
     * 分片文件信息
     */
    protected $segmentIndex = 0;
    protected $currentSegmentFile = null;
    protected $currentSegmentSize = 0;
    protected $maxSegmentSize = 10 * 1024 * 1024; // 默认每个分片最大 10MB

    /**
     * 媒体数据临时缓冲区（用于累积数据，减少分片数量）
     */
    protected $mediaBuffer = '';
    protected $minMediaBufferSize = 5 * 1024 * 1024; // 最小累积 5MB 后再写入

    /**
     * FLV 数据缓冲区（用于累积数据，减少处理次数）
     */
    protected $_flvDataBuffer = '';
    protected $flvBufferSize = 2 * 1024 * 1024; // 累积 2MB FLV 数据后再处理

    /**
     * 记录第一个和最后一个包的时间戳（毫秒）
     */
    protected $_firstPacketTimestamp = -1;
    protected $_lastPacketTimestamp = -1;

    /**
     * 单独的音视频初始化数据
     */
    public $audioInitSegment = null;
    public $videoInitSegment = null;

    /**
     * 是否生成分开的音视频切片
     */
    protected $separateTracks = false;

    /**
     * 音视频分片索引（用于分开切片）
     */
    protected $audioSegmentIndex = 0;
    protected $videoSegmentIndex = 0;

    /**
     * 混合切片缓冲区（用于累积多个包，减少分片数量）
     */
    protected $mixedBuffer = [];
    protected $mixedBufferIndex = 0;
    protected $mixedBufferSize = 30; // 默认每个混合切片包含 30 个包

    /**
     * 音频切片缓冲区（用于累积多个包，减少分片数量）
     */
    protected $audioBuffer = [];
    protected $audioBufferIndex = 0;
    protected $audioBufferSize = 30; // 默认每个音频切片包含 30 个包

    /**
     * 视频切片缓冲区（用于累积多个包，减少分片数量）
     */
    protected $videoBuffer = [];
    protected $videoBufferIndex = 0;
    protected $videoBufferSize = 30; // 默认每个视频切片包含 30 个包

    /**
     * 构造函数
     * @param array $config 配置参数
     *                       - isLive: 是否为直播模式（默认 true）
     *                       - streamPath: 直播流路径
     *                       - maxSegmentSize: 单个分片最大字节数（默认 10MB）
     *                       - segmentDir: 分片文件存储目录
     *                       - separateTracks: 是否生成分开的音视频切片（默认 false）
     *                       - mixedBufferSize: 混合切片缓冲区大小（默认 30）
     *                       - audioBufferSize: 音频切片缓冲区大小（默认 30）
     *                       - videoBufferSize: 视频切片缓冲区大小（默认 30）
     */
    public function __construct($config = [])
    {
        $this->_config = ['_isLive' => true];
        $this->_config = array_merge($this->_config, $config);
        $this->isLive = isset($config['isLive']) ? $config['isLive'] : true;
        $this->streamPath = isset($config['streamPath']) ? $config['streamPath'] : '';
        $this->maxSegmentSize = isset($config['maxSegmentSize']) ? $config['maxSegmentSize'] : $this->maxSegmentSize;
        $this->separateTracks = isset($config['separateTracks']) ? $config['separateTracks'] : false;
        $this->mixedBufferSize = isset($config['mixedBufferSize']) ? $config['mixedBufferSize'] : $this->mixedBufferSize;
        $this->audioBufferSize = isset($config['audioBufferSize']) ? $config['audioBufferSize'] : $this->audioBufferSize;
        $this->videoBufferSize = isset($config['videoBufferSize']) ? $config['videoBufferSize'] : $this->videoBufferSize;

        $this->loadmetadata = false;
        $this->ftyp_moov = null;
        $this->metaSuccRun = false;
        $this->metas = [];
        $this->parseChunk = null;
        $this->hasVideo = false;
        $this->hasAudio = false;
        $this->_pendingResolveSeekPoint = -1;
        $this->_tempBaseTime = 0;
        $segmentDir = $this->_config['segmentDir'];
        if (!is_dir($segmentDir)) {
            mkdir($segmentDir, 0755, true);
        }
        $this->setflvBase = [$this, 'setflvBasefrist'];

        $this->tagDemux = new TagDemux();
        $this->tagDemux->_onTrackMetadata = [$this, 'Metadata'];
        $this->tagDemux->_onMediaInfo = [$this, 'metaSucc'];
        $this->tagDemux->_onDataAvailable = [$this, 'onDataAvailable'];

        $this->m4mof = new MP4Remuxer($this->_config);
        $this->m4mof->setOnMediaSegment([$this, 'onMdiaSegment']);

        // 重置 FlvParse 的静态变量，确保每个转码器独立工作
        \Xiaosongshu\Flv2mp4\Flv\FlvParse::reset();

        $this->initialized = true;
    }

    /**
     * 设置直播流路径
     * @param string $path
     */
    public function setStreamPath($path)
    {
        $this->streamPath = $path;
    }

    /**
     * 获取直播流路径
     * @return string
     */
    public function getStreamPath()
    {
        return $this->streamPath;
    }

    /**
     * seek 操作（直播流中通常不需要）
     * @param int|null $baseTime
     */
    public function seek($baseTime = null)
    {
        $this->setflvBase = [$this, 'setflvBasefrist'];
        if ($baseTime === null || $baseTime == 0) {
            $baseTime = 0;
            $this->_pendingResolveSeekPoint = -1;
        }
        if ($this->_tempBaseTime != $baseTime) {
            $this->_tempBaseTime = $baseTime;
            $this->tagDemux->timestampBase($baseTime);
            $this->m4mof->seek($baseTime);
            $this->m4mof->insertDiscontinuity();
            $this->_pendingResolveSeekPoint = $baseTime;
        }
    }

    /**
     * 设置 FLV 基础时间（首次调用）
     * @param string $arraybuff FLV 数据
     * @param int $baseTime 基础时间戳
     * @return int 已处理的字节偏移量
     */
    public function setflvBasefrist($arraybuff, $baseTime)
    {
        $offset = FlvParse::setFlv($arraybuff);
        if (isset(FlvParse::$arrTag[0]) && FlvParse::$arrTag[0]->tagType != 18) {
            if ($this->error) {
                call_user_func($this->error, new \Exception('without metadata tag'));
            }
        }
        if (count(FlvParse::$arrTag) > 0) {
            $this->hasAudio = FlvParse::$_hasAudio;
            $this->hasVideo = FlvParse::$_hasVideo;
            $this->tagDemux->setHasAudio($this->hasAudio);
            $this->tagDemux->setHasVideo($this->hasVideo);
            if ($this->_tempBaseTime != 0 && $this->_tempBaseTime == FlvParse::$arrTag[0]->getTime()) {
                $this->tagDemux->timestampBase(0);
            }
            $this->tagDemux->moofTag(FlvParse::$arrTag);
            $this->setflvBase = [$this, 'setflvBaseUsually'];
        }
        return $offset;
    }

    /**
     * 设置 FLV 数据（通常调用）
     * @param string $arraybuff FLV 数据
     * @param int $baseTime 基础时间戳
     * @return int 已处理的字节偏移量
     */
    public function setflvBaseUsually($arraybuff, $baseTime)
    {
        $offset = FlvParse::setFlv($arraybuff);
        if (count(FlvParse::$arrTag) > 0) {
            $this->tagDemux->moofTag(FlvParse::$arrTag);
        }
        return $offset;
    }

    /**
     * 处理媒体分片输出
     * @param string $track 轨道类型（audio/video）
     * @param array $value 分片数据
     */
    public function onMdiaSegment($track, $value)
    {
        // 混合切片输出（始终生成）
        if ($this->onMediaSegment) {
            call_user_func($this->onMediaSegment, $value['data']);
        }

        // 如果是文件输出模式，使用缓冲区累积混合切片
        if (isset($this->_config['segmentDir']) && !empty($this->_config['segmentDir'])) {
            $this->mixedBuffer[] = $value['data'];
            if (count($this->mixedBuffer) >= $this->mixedBufferSize) {
                $this->flushMixedBuffer();
            }
        }

        // 分开输出音视频切片（如果配置了）
        if ($this->separateTracks) {
            if ($track == 'audio') {
                $this->audioSegmentIndex++;
                $this->audioBuffer[] = $value['data'];
                if (count($this->audioBuffer) >= $this->audioBufferSize) {
                    $this->flushAudioBuffer();
                }
                if ($this->onAudioSegment) {
                    call_user_func($this->onAudioSegment, $value['data'], $value);
                }
            } elseif ($track == 'video') {
                $this->videoSegmentIndex++;
                $this->videoBuffer[] = $value['data'];
                if (count($this->videoBuffer) >= $this->videoBufferSize) {
                    $this->flushVideoBuffer();
                }
                if ($this->onVideoSegment) {
                    call_user_func($this->onVideoSegment, $value['data'], $value);
                }
            }
        }

        if ($this->_pendingResolveSeekPoint != -1 && $track == 'video') {
            $seekpoint = $this->_pendingResolveSeekPoint;
            $this->_pendingResolveSeekPoint = -1;
            if ($this->seekCallBack) {
                call_user_func($this->seekCallBack, $seekpoint);
            }
        }
    }

    /**
     * 刷新混合切片缓冲区
     */
    protected function flushMixedBuffer()
    {
        if (empty($this->mixedBuffer)) {
            return;
        }
        $this->mixedBufferIndex++;
        $segmentData = implode('', $this->mixedBuffer);
        $segmentDir = $this->_config['segmentDir'];
        $filename = rtrim($segmentDir, '/') . "/segment_{$this->mixedBufferIndex}.m4s";
        file_put_contents($filename, $segmentData);
        $this->mixedBuffer = [];
    }

    /**
     * 刷新音频切片缓冲区
     */
    protected function flushAudioBuffer()
    {
        if (empty($this->audioBuffer)) {
            return;
        }
        $this->audioBufferIndex++;
        $segmentData = implode('', $this->audioBuffer);
        $segmentDir = $this->_config['segmentDir'];
        $filename = rtrim($segmentDir, '/') . "/audio_{$this->audioBufferIndex}.m4s";
        file_put_contents($filename, $segmentData);
        $this->audioBuffer = [];
    }

    /**
     * 刷新视频切片缓冲区
     */
    protected function flushVideoBuffer()
    {
        if (empty($this->videoBuffer)) {
            return;
        }
        $this->videoBufferIndex++;
        $segmentData = implode('', $this->videoBuffer);
        $segmentDir = $this->_config['segmentDir'];
        $filename = rtrim($segmentDir, '/') . "/video_{$this->videoBufferIndex}.m4s";
        file_put_contents($filename, $segmentData);
        $this->videoBuffer = [];
    }

    /**
     * 将混合分片数据写入文件
     * @param string $data 分片数据
     */
    protected function writeSegmentToFile($data)
    {
        $segmentDir = $this->_config['segmentDir'];

        if (!is_dir($segmentDir)) {
            mkdir($segmentDir, 0755, true);
        }

        $this->segmentIndex++;
        $filename = rtrim($segmentDir, '/') . "/segment_{$this->segmentIndex}.m4s";
        file_put_contents($filename, $data);
    }

    /**
     * 将音频分片数据写入文件
     * @param string $data 分片数据
     */
    protected function writeAudioSegmentToFile($data)
    {
        $segmentDir = $this->_config['segmentDir'];

        // 如果目录不存在，创建目录
        if (!is_dir($segmentDir)) {
            mkdir($segmentDir, 0755, true);
        }

        $filename = rtrim($segmentDir, '/') . "/audio_{$this->audioSegmentIndex}.m4s";
        file_put_contents($filename, $data);
    }

    /**
     * 将视频分片数据写入文件
     * @param string $data 分片数据
     */
    protected function writeVideoSegmentToFile($data)
    {
        $segmentDir = $this->_config['segmentDir'];

        // 如果目录不存在，创建目录
        if (!is_dir($segmentDir)) {
            mkdir($segmentDir, 0755, true);
        }

        $filename = rtrim($segmentDir, '/') . "/video_{$this->videoSegmentIndex}.m4s";
        file_put_contents($filename, $data);
    }

    /**
     * 刷新缓冲区（在关闭时调用）
     */
    protected function flushMediaBuffer()
    {
        $this->flushMixedBuffer();
        $this->flushAudioBuffer();
        $this->flushVideoBuffer();
    }

    /**
     * 处理元数据
     * @param string $type 轨道类型
     * @param array $meta 元数据
     */
    public function Metadata($type, $meta)
    {
        switch ($type) {
            case 'video':
                $this->metas[] = $meta;
                $this->m4mof->_videoMeta = $meta;
                if ($this->hasVideo && !$this->hasAudio) {
                    $this->metaSucc();
                    return;
                }
                break;
            case 'audio':
                $this->metas[] = $meta;
                $this->m4mof->_audioMeta = $meta;
                if (!$this->hasVideo && $this->hasAudio) {
                    $this->metaSucc();
                    return;
                }
                break;
        }
        if ($this->hasVideo && $this->hasAudio && count($this->metas) > 1) {
            $this->metaSucc();
        }
    }

    /**
     * 元数据处理成功回调
     * @param mixed $mi 媒体信息
     */
    public function metaSucc($mi = null)
    {
        if ($this->onMediaInfo) {
            call_user_func($this->onMediaInfo, $mi ?: $this->tagDemux->_mediaInfo, ['hasAudio' => $this->hasAudio, 'hasVideo' => $this->hasVideo]);
        }
        if (count($this->metas) == 0) {
            $this->metaSuccRun = true;
            return;
        }
        if ($mi !== null) {
            return;
        }
        $this->ftyp_moov = MP4::generateInitSegment($this->metas);

        // 自动保存初始化分片到 segmentDir (init.mp4)
        if (isset($this->_config['segmentDir']) && !empty($this->_config['segmentDir']) && $this->ftyp_moov) {
            $segmentDir = $this->_config['segmentDir'];
            if (!is_dir($segmentDir)) {
                mkdir($segmentDir, 0755, true);
            }
            $initFile = rtrim($segmentDir, '/') . "/init.mp4";
            file_put_contents($initFile, $this->ftyp_moov);
        }

        if ($this->onInitSegment && $this->loadmetadata == false) {
            call_user_func($this->onInitSegment, $this->ftyp_moov);
            $this->loadmetadata = true;
        }

        // 如果需要分开的音视频切片，生成单独的音视频初始化片段
        if ($this->separateTracks) {
            foreach ($this->metas as $meta) {
                if ($meta['type'] == 'audio') {
                    $this->audioInitSegment = MP4::generateAudioInitSegment($meta);
                    // 保存音频初始化片段
                    if (isset($this->_config['segmentDir']) && !empty($this->_config['segmentDir'])) {
                        $segmentDir = $this->_config['segmentDir'];
                        $audioInitFile = rtrim($segmentDir, '/') . "/audio_init.mp4";
                        file_put_contents($audioInitFile, $this->audioInitSegment);
                    }
                    if ($this->onAudioInitSegment) {
                        call_user_func($this->onAudioInitSegment, $this->audioInitSegment, $meta);
                    }
                } elseif ($meta['type'] == 'video') {
                    $this->videoInitSegment = MP4::generateVideoInitSegment($meta);
                    // 保存视频初始化片段
                    if (isset($this->_config['segmentDir']) && !empty($this->_config['segmentDir'])) {
                        $segmentDir = $this->_config['segmentDir'];
                        $videoInitFile = rtrim($segmentDir, '/') . "/video_init.mp4";
                        file_put_contents($videoInitFile, $this->videoInitSegment);
                    }
                    if ($this->onVideoInitSegment) {
                        call_user_func($this->onVideoInitSegment, $this->videoInitSegment, $meta);
                    }
                }
            }
            // 分离切片模式下，在生成初始化片段后立即生成 meta.json（支持直播）
            $this->writeMetaJson($this->_config['segmentDir'] ?? '', 0);
        } else {
            // 混合切片模式下，在生成初始化片段后立即生成 meta.json（支持直播）
            if (isset($this->_config['segmentDir']) && !empty($this->_config['segmentDir'])) {
                $this->writeMetaJson($this->_config['segmentDir'], 0);
            }
        }
    }


    /**
     * 数据可用回调
     * @param array $audiotrack 音频轨道数据
     * @param array $videotrack 视频轨道数据
     */
    public function onDataAvailable($audiotrack, $videotrack)
    {
        // 缓存音频数据（如果有）
        if (!empty($audiotrack['samples']) && count($audiotrack['samples']) > 0) {
            if (!isset($this->_cachedAudioTrack['samples'])) {
                $this->_cachedAudioTrack['samples'] = [];
            }
            $this->_cachedAudioTrack['samples'] = array_merge(
                $this->_cachedAudioTrack['samples'],
                $audiotrack['samples']
            );
            // 复制其他必要字段
            foreach (['id', 'sequenceNumber', 'addcoefficient'] as $key) {
                if (isset($audiotrack[$key])) {
                    $this->_cachedAudioTrack[$key] = $audiotrack[$key];
                }
            }
        }

        // 缓存视频数据（如果有）
        if (!empty($videotrack['samples']) && count($videotrack['samples']) > 0) {
            if (!isset($this->_cachedVideoTrack['samples'])) {
                $this->_cachedVideoTrack['samples'] = [];
            }
            $this->_cachedVideoTrack['samples'] = array_merge(
                $this->_cachedVideoTrack['samples'],
                $videotrack['samples']
            );
            // 复制其他必要字段
            foreach (['id', 'sequenceNumber', 'addcoefficient'] as $key) {
                if (isset($videotrack[$key])) {
                    $this->_cachedVideoTrack[$key] = $videotrack[$key];
                }
            }
        }

        // 检查是否有缓存的数据
        $hasCachedAudio = !empty($this->_cachedAudioTrack['samples']) && count($this->_cachedAudioTrack['samples']) > 0;
        $hasCachedVideo = !empty($this->_cachedVideoTrack['samples']) && count($this->_cachedVideoTrack['samples']) > 0;

        // 场景1：同时有音频和视频数据 - 调用 remux
        if ($hasCachedAudio && $hasCachedVideo) {
            $this->m4mof->remux($this->_cachedAudioTrack, $this->_cachedVideoTrack);
            $this->_cachedAudioTrack = [];
            $this->_cachedVideoTrack = [];
        }
        // 场景2：只有音频数据（纯音频流）- 调用 remux
        elseif ($hasCachedAudio && !$this->hasVideo) {
            $this->m4mof->remux($this->_cachedAudioTrack, []);
            $this->_cachedAudioTrack = [];
        }
        // 场景3：只有视频数据（纯视频流）- 调用 remux
        elseif ($hasCachedVideo && !$this->hasAudio) {
            $this->m4mof->remux([], $this->_cachedVideoTrack);
            $this->_cachedVideoTrack = [];
        }
        // 场景4：有缓存但还在等待另一种数据类型 - 继续等待
        // （这种情况是临时的，等待音频和视频都到达）
    }

    /**
     * 设置 FLV 数据（对外接口）
     * @param string $arraybuff FLV 数据
     * @param int $baseTime 基础时间戳
     * @return int 已处理的字节偏移量
     */
    public function setflv($arraybuff, $baseTime)
    {
        return call_user_func($this->setflvBase, $arraybuff, $baseTime);
    }

    /**
     * 设置 FLV 数据并返回标签数组（用于调试）
     * @param string $arraybuff FLV 数据
     * @return array FLV 标签数组
     */
    public function setflvloc($arraybuff)
    {
        $offset = FlvParse::setFlv($arraybuff);
        if (count(FlvParse::$arrTag) > 0) {
            return FlvParse::$arrTag;
        }
        return [];
    }

    /**
     * 处理接收到的 FLV 数据
     * 这是主要的数据处理入口
     * @param string $flvData FLV 格式的原始数据
     * @param int $timestamp 当前包的时间戳（毫秒），如果为 0 则从 FLV 包中解析
     * @return int 处理的字节数
     */
    public function processFlvData($flvData, $timestamp = 0)
    {
        if (!$this->initialized) {
            return 0;
        }

        $this->totalReceivedBytes += strlen($flvData);

        // 如果没有传时间戳，则从 FLV 包中解析时间戳
        if ($timestamp <= 0 && strlen($flvData) >= 11) {
            // FLV tag 格式：类型 (1) + 大小 (3) + 时间戳 (3 字节，小端序) + 时间戳扩展 (1) + streamID(3)
            // 时间戳是 24 位小端序，扩展字节是高 8 位
            $tsBytes = substr($flvData, 4, 3);
            $timestamp = ord($tsBytes[0]) | (ord($tsBytes[1]) << 8) | (ord($tsBytes[2]) << 16);
            if (strlen($flvData) >= 15) {
                $timestampExt = ord(substr($flvData, 7));
                $timestamp |= ($timestampExt << 24);
            }
        }

        // 记录第一个和最后一个包的时间戳
        if ($timestamp > 0) {
            if ($this->_firstPacketTimestamp < 0) {
                $this->_firstPacketTimestamp = $timestamp;
            }
            $this->_lastPacketTimestamp = $timestamp;
        }

        // 将新数据添加到缓冲区
        $this->flvBuffer .= $flvData;

        // 如果还没有收到完整的 FLV 头部，等待更多数据
        if (!$this->loadmetadata && strlen($this->flvBuffer) < 13) {
            return 0;
        }

        // 如果已经加载了元数据，累积更多数据再处理
        if ($this->loadmetadata && strlen($this->flvBuffer) < $this->flvBufferSize) {
            return 0;
        }

        // 处理缓冲区中的数据
        $processed = $this->processBuffer($timestamp);

        return $processed;
    }

    /**
     * 处理缓冲区中的 FLV 数据
     * @param int $timestamp 当前包的时间戳
     * @return int 处理的字节数
     */
    protected function processBuffer($timestamp)
    {
        $totalProcessed = 0;

        // 如果数据量足够，尝试处理
        while (strlen($this->flvBuffer) >= 11) { // 最小标签大小
            $processed = $this->setflv($this->flvBuffer, $timestamp);

            if ($processed > 0) {
                // 移除已处理的数据
                $this->flvBuffer = substr($this->flvBuffer, $processed);
                $totalProcessed += $processed;
            } else {
                // 无法处理更多数据，退出循环
                break;
            }
        }

        return $totalProcessed;
    }

    /**
     * 清理资源
     */
    public function cleanup()
    {
        if ($this->currentSegmentFile !== null) {
            fclose($this->currentSegmentFile);
            $this->currentSegmentFile = null;
        }
        $this->segmentIndex = 0;
        $this->currentSegmentSize = 0;
        $this->totalReceivedBytes = 0;
        $this->flvBuffer = '';
        $this->audioSegmentIndex = 0;
        $this->videoSegmentIndex = 0;
        $this->mixedBuffer = [];
        $this->mixedBufferIndex = 0;
        $this->audioBuffer = [];
        $this->audioBufferIndex = 0;
        $this->videoBuffer = [];
        $this->videoBufferIndex = 0;
    }

    /**
     * 从分片文件计算视频时长
     * @param array $segmentFiles 分片文件列表
     * @return int 视频时长（毫秒）
     */
    protected function calculateDurationFromSegments($segmentFiles)
    {
        // 优先使用记录的时间戳差值计算时长
        if ($this->_firstPacketTimestamp >= 0 && $this->_lastPacketTimestamp >= 0) {
            $duration = $this->_lastPacketTimestamp - $this->_firstPacketTimestamp;
            //error_log("calculateDurationFromSegments: duration from timestamps = $duration ms (first={$this->_firstPacketTimestamp}, last={$this->_lastPacketTimestamp})");
            return $duration;
        }

        // 如果没有记录时间戳，则从分片文件计算（备用方案）
        $videoTimescale = 0;
        $audioTimescale = 0;

        // 获取 segmentDir
        $segmentDir = $this->_config['segmentDir'] ?? '';

        // 查找 init.mp4 文件
        $initFile = rtrim($segmentDir, '/') . '/init.mp4';

        if ($initFile === null || !file_exists($initFile)) {
            return 0;
        }

        $initData = file_get_contents($initFile);
        if (!$initData) {
            return 0;
        }

        // 从 init.mp4 的 moov 中获取 timescale
        $moovStart = strpos($initData, 'moov');
        if ($moovStart !== false) {
            $trakStart = strpos($initData, 'trak', $moovStart);
            while ($trakStart !== false) {
                $mdiaStart = strpos($initData, 'mdia', $trakStart);
                if ($mdiaStart !== false) {
                    $mdhdStart = strpos($initData, 'mdhd', $mdiaStart);
                    if ($mdhdStart !== false && $mdhdStart + 32 <= strlen($initData)) {
                        $mdhdVersion = ord(substr($initData, $mdhdStart + 8, 1));
                        $timescale = 0;
                        if ($mdhdVersion == 0) {
                            $timescale = unpack('N', substr($initData, $mdhdStart + 20, 4))[1];
                        } else {
                            $timescale = unpack('N', substr($initData, $mdhdStart + 28, 4))[1];
                        }

                        // 通过 hdlr 确定轨道类型
                        $hdlrStart = strpos($initData, 'hdlr', $mdiaStart);
                        if ($hdlrStart !== false) {
                            $handlerType = substr($initData, $hdlrStart + 16, 4);
                            if ($handlerType == 'vide' && $videoTimescale == 0) {
                                $videoTimescale = $timescale;
                            } elseif ($handlerType == 'soun' && $audioTimescale == 0) {
                                $audioTimescale = $timescale;
                            }
                        }
                    }
                }
                $trakStart = strpos($initData, 'trak', $trakStart + 4);
            }
        }

        $timescale = $videoTimescale > 0 ? $videoTimescale : ($audioTimescale > 0 ? $audioTimescale : 1000);

        // 第二步：从分片文件获取总时长
        $maxEndTime = 0;

        // 调试信息
        //error_log("calculateDurationFromSegments: segmentFiles count = " . count($segmentFiles));
        foreach ($segmentFiles as $sf) {
            //error_log("  segment file: $sf, size: " . filesize($sf) . " bytes");
        }

        foreach ($segmentFiles as $segmentFile) {
            $data = file_get_contents($segmentFile);
            if (!$data || strlen($data) < 16) {
                continue;
            }

            // 查找所有的 moof
            $moofStart = strpos($data, 'moof');
            while ($moofStart !== false) {
                // 获取 moof box 的大小
                $moofSize = unpack('N', substr($data, $moofStart, 4))[1];

                // 在这个 moof 范围内查找 tfdt
                $moofEnd = $moofStart + $moofSize;
                $tfdtStart = strpos($data, 'tfdt', $moofStart + 8);

                if ($tfdtStart !== false && $tfdtStart < $moofEnd && $tfdtStart + 20 <= strlen($data)) {
                    $tfdtVersion = ord(substr($data, $tfdtStart + 8, 1));
                    $baseDecodeTime = 0;
                    if ($tfdtVersion == 0) {
                        $baseDecodeTime = unpack('N', substr($data, $tfdtStart + 12, 4))[1];
                    } else {
                        if ($tfdtStart + 28 <= strlen($data)) {
                            $baseDecodeTime = unpack('J', substr($data, $tfdtStart + 12, 8))[1];
                        }
                    }

                    // 查找 trun 获取样本时长
                    $trunStart = strpos($data, 'trun', $moofStart + 8);
                    $totalSampleDuration = 0;

                    if ($trunStart !== false && $trunStart < $moofEnd && $trunStart + 20 <= strlen($data)) {
                        $trunVersion = ord(substr($data, $trunStart + 8, 1));
                        $trunFlags = unpack('N', substr($data, $trunStart + 8, 4))[1];
                        $trunFlags = $trunFlags & 0xFFFFFF;

                        $sampleCount = unpack('N', substr($data, $trunStart + 12, 4))[1];

                        if (($trunFlags & 0x000100) && $sampleCount > 0) {
                            $headerSize = 16;
                            $durationSize = ($trunVersion == 0) ? 4 : 8;
                            $offset = $trunStart + $headerSize;

                            for ($i = 0; $i < $sampleCount && $offset + $durationSize <= strlen($data); $i++) {
                                $dur = 0;
                                if ($durationSize == 4) {
                                    $dur = unpack('N', substr($data, $offset, 4))[1];
                                } else {
                                    if ($offset + 8 <= strlen($data)) {
                                        $dur = unpack('J', substr($data, $offset, 8))[1];
                                    }
                                }
                                $totalSampleDuration += $dur;
                                $offset += $durationSize;
                            }
                        }
                    }

                    // 计算这个分片的结束时间
                    $segmentEndTime = $baseDecodeTime + $totalSampleDuration;
                    if ($segmentEndTime > $maxEndTime) {
                        $maxEndTime = $segmentEndTime;
                    }
                }

                // 查找下一个 moof
                $moofStart = strpos($data, 'moof', $moofStart + 4);
            }
        }

        // 转换为毫秒
        $durationMs = 0;
        if ($timescale > 0 && $maxEndTime > 0) {
            $durationMs = (int)($maxEndTime * 1000 / $timescale);
        }

        return $durationMs;
    }

    /**
     * 更新初始化片段中的时长信息
     * @param string $initData 初始化片段数据
     * @param int $duration 时长（毫秒）
     * @return string 更新后的初始化片段
     */
    protected function updateInitSegmentDuration($initData, $duration)
    {
        $mvhdStart = strpos($initData, 'mvhd');
        if ($mvhdStart === false) {
            return $initData;
        }

        if ($mvhdStart + 8 > strlen($initData)) {
            return $initData;
        }

        $mvhdSize = unpack('N', substr($initData, $mvhdStart, 4))[1];
        $mvhdVersion = ord(substr($initData, $mvhdStart + 8, 1));

        if ($mvhdVersion == 0) {
            if ($mvhdSize < 28 || $mvhdStart + 28 > strlen($initData)) {
                return $initData;
            }
            $mvhdTimescale = unpack('N', substr($initData, $mvhdStart + 20, 4))[1];
            $durationOffset = 24;
            $durationLength = 4;
        } else {
            if ($mvhdSize < 36 || $mvhdStart + 36 > strlen($initData)) {
                return $initData;
            }
            $mvhdTimescale = unpack('N', substr($initData, $mvhdStart + 28, 4))[1];
            $durationOffset = 32;
            $durationLength = 8;
        }

        $mvhdDurationInTimescale = (int)($duration * $mvhdTimescale / 1000);

        if ($durationLength == 4) {
            $durationBytes = pack('N', $mvhdDurationInTimescale);
        } else {
            $durationBytes = pack('J', $mvhdDurationInTimescale);
        }
        $initData = substr_replace($initData, $durationBytes, $mvhdStart + $durationOffset, $durationLength);

        $trakStart = strpos($initData, 'trak');
        while ($trakStart !== false && $trakStart + 8 <= strlen($initData)) {
            $tkhdStart = strpos($initData, 'tkhd', $trakStart);
            if ($tkhdStart !== false && $tkhdStart + 8 <= strlen($initData)) {
                $tkhdSize = unpack('N', substr($initData, $tkhdStart, 4))[1];
                $tkhdVersion = ord(substr($initData, $tkhdStart + 8, 1));

                if ($tkhdVersion == 0) {
                    if ($tkhdSize < 32 || $tkhdStart + 32 > strlen($initData)) {
                        $trakStart = strpos($initData, 'trak', $trakStart + 4);
                        continue;
                    }
                    $durationOffset = 28;
                    $durationLength = 4;
                } else {
                    if ($tkhdSize < 44 || $tkhdStart + 44 > strlen($initData)) {
                        $trakStart = strpos($initData, 'trak', $trakStart + 4);
                        continue;
                    }
                    $durationOffset = 36;
                    $durationLength = 8;
                }

                if ($durationLength == 4) {
                    $durationBytes = pack('N', $mvhdDurationInTimescale);
                } else {
                    $durationBytes = pack('J', $mvhdDurationInTimescale);
                }
                $initData = substr_replace($initData, $durationBytes, $tkhdStart + $durationOffset, $durationLength);
            }
            $trakStart = strpos($initData, 'trak', $trakStart + 4);
        }

        return $initData;
    }

    /**
     * 生成 meta.json 文件
     * @param string $segmentDir 分片目录
     * @param int $duration 视频时长（毫秒）
     */
    protected function generateMetaJson($segmentDir, $duration)
    {
        $this->writeMetaJson($segmentDir, $duration);
    }

    /**
     * 生成分离切片的 meta.json 文件（公共方法）
     * @param string $segmentDir 分片目录
     */
    public function writeMetaJson($segmentDir = null, $duration = 0)
    {
        if ($segmentDir === null) {
            $segmentDir = $this->_config['segmentDir'] ?? '';
        }

        if (empty($segmentDir)) {
            return;
        }

        $meta = [];

        foreach ($this->metas as $trackMeta) {
            if (isset($trackMeta['codec'])) {
                if ($trackMeta['type'] == 'video') {
                    $meta['videoCodec'] = $trackMeta['codec'];
                    $meta['width'] = isset($trackMeta['presentWidth']) ? $trackMeta['presentWidth'] : (isset($trackMeta['codecWidth']) ? $trackMeta['codecWidth'] : 0);
                    $meta['height'] = isset($trackMeta['presentHeight']) ? $trackMeta['presentHeight'] : (isset($trackMeta['codecHeight']) ? $trackMeta['codecHeight'] : 0);
                } else if ($trackMeta['type'] == 'audio') {
                    $meta['audioCodec'] = $trackMeta['codec'];
                    $meta['sampleRate'] = isset($trackMeta['audioSampleRate']) ? $trackMeta['audioSampleRate'] : 0;
                    $meta['channels'] = isset($trackMeta['channelCount']) ? $trackMeta['channelCount'] : 0;
                }
            }
        }

        $meta['hasAudio'] = $this->hasAudio;
        $meta['hasVideo'] = $this->hasVideo;
        $meta['duration'] = $duration;

        if ($this->separateTracks) {
            $meta['audioSegmentCount'] = $this->audioBufferIndex;
            $meta['videoSegmentCount'] = $this->videoBufferIndex;
        } else {
            $meta['segmentCount'] = $this->mixedBufferIndex;
        }

        $metaFile = rtrim($segmentDir, '/') . '/meta.json';
        file_put_contents($metaFile, json_encode($meta, JSON_PRETTY_PRINT));
    }

    /**
     * 合成完整的 MP4 文件
     * 在关闭播放器时调用此方法，将所有分片合成为完整的 MP4 文件
     * @param string|null $outputFile 输出文件路径（可选，默认为 streamPath_full.mp4）
     * @param bool $deleteSegments 是否删除分片文件（默认 true）
     * @return string|false 返回合成后的文件路径，失败返回 false
     */
    public function finalize($outputFile = null, $deleteSegments = true)
    {
        // 处理剩余的 FLV 数据缓冲区
        if (strlen($this->flvBuffer) > 0) {

            $this->processBuffer(0);
        }

        // 刷新缓冲区，确保所有数据都写入文件
        $this->flushMediaBuffer();

        // 确保当前分片文件已关闭
        if ($this->currentSegmentFile !== null) {
            fclose($this->currentSegmentFile);
            $this->currentSegmentFile = null;
        }

        // 检查是否有分片目录配置
        if (!isset($this->_config['segmentDir']) || empty($this->_config['segmentDir'])) {
            return false;
        }

        $segmentDir = $this->_config['segmentDir'];

        // 如果目录不存在，返回失败
        if (!is_dir($segmentDir)) {
            return false;
        }

        // 构建输出文件路径
        if ($outputFile === null) {
            $baseName = basename($this->streamPath);
            $outputFile = rtrim($segmentDir, '/') . "/{$baseName}_full.mp4";
        }

        // 查找所有分片文件
        $initFile = rtrim($segmentDir, '/') . "/init.mp4";
        $segmentPattern = rtrim($segmentDir, '/') . "/segment_*.m4s";

        // 检查初始化文件是否存在
        if (!file_exists($initFile)) {
            return false;
        }

        // 获取所有分片文件并排序
        $segmentFiles = glob($segmentPattern);
        if (empty($segmentFiles)) {
            return false;
        }

        // 按分片索引排序
        usort($segmentFiles, function($a, $b) {
            $pattern = '/segment_(\d+)\.m4s/';
            preg_match($pattern, $a, $matchesA);
            preg_match($pattern, $b, $matchesB);
            $indexA = isset($matchesA[1]) ? (int)$matchesA[1] : 0;
            $indexB = isset($matchesB[1]) ? (int)$matchesB[1] : 0;
            return $indexA - $indexB;
        });

        try {
            // 创建输出文件
            $outputHandle = fopen($outputFile, 'wb');
            if (!$outputHandle) {
                return false;
            }

            // 读取初始化文件（用于获取原始元数据）
            $initData = file_get_contents($initFile);

            // 计算实际时长
            $duration = $this->calculateDurationFromSegments($segmentFiles);

            // 调试信息
            //error_log("finalize: duration = $duration ms, metas count = " . count($this->metas));
            foreach ($this->metas as $i => $meta) {
                //error_log("  meta[$i]: timescale = " . ($meta['timescale'] ?? 'N/A') . ", duration = " . ($meta['duration'] ?? 'N/A'));
            }

            // 如果计算出了有效时长，更新 metas 中的 duration 并重新生成 init.mp4
            if ($duration > 0 && count($this->metas) > 0) {
                // 更新所有轨道的 duration
                foreach ($this->metas as &$meta) {
                    if (isset($meta['timescale'])) {
                        $meta['duration'] = (int)($duration * $meta['timescale'] / 1000);
                    }
                }
                // 重新生成包含正确时长的 init.mp4
                $initData = MP4::generateInitSegment($this->metas);
            }

            fwrite($outputHandle, $initData);
            $totalSize = strlen($initData);

            // 写入所有分片文件
            foreach ($segmentFiles as $segmentFile) {
                $segmentData = file_get_contents($segmentFile);
                fwrite($outputHandle, $segmentData);
                $totalSize += strlen($segmentData);

                // 如果需要删除分片文件
                if ($deleteSegments) {
                    unlink($segmentFile);
                }
            }

            // 关闭输出文件
            fclose($outputHandle);

            // 生成 meta.json
            $this->generateMetaJson($segmentDir, $duration);

            // 删除初始化文件
            if ($deleteSegments && file_exists($initFile)) {
                unlink($initFile);
            }

            // 触发回调
            if ($this->onMediaInfo) {
                call_user_func($this->onMediaInfo, null, ['mergedFile' => $outputFile, 'size' => $totalSize]);
            }

            return $outputFile;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 获取统计信息
     * @return array 统计信息
     */
    public function getStats()
    {
        return [
            'streamPath' => $this->streamPath,
            'totalReceivedBytes' => $this->totalReceivedBytes,
            'segmentIndex' => $this->mixedBufferIndex,
            'currentSegmentSize' => $this->currentSegmentSize,
            'hasAudio' => $this->hasAudio,
            'hasVideo' => $this->hasVideo,
            'metadataLoaded' => $this->loadmetadata,
            'separateTracks' => $this->separateTracks,
            'audioSegmentIndex' => $this->audioBufferIndex,
            'videoSegmentIndex' => $this->videoBufferIndex,
            'mixedBufferSize' => $this->mixedBufferSize,
            'audioBufferSize' => $this->audioBufferSize,
            'videoBufferSize' => $this->videoBufferSize
        ];
    }

    /**
     * 析构函数
     */
    public function __destruct()
    {
        $this->cleanup();
    }
}