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
     * 构造函数
     * @param array $config 配置参数
     *                       - isLive: 是否为直播模式（默认 true）
     *                       - streamPath: 直播流路径
     *                       - maxSegmentSize: 单个分片最大字节数（默认 10MB）
     *                       - segmentDir: 分片文件存储目录
     */
    public function __construct($config = [])
    {
        $this->_config = ['_isLive' => true];
        $this->_config = array_merge($this->_config, $config);
        $this->isLive = isset($config['isLive']) ? $config['isLive'] : true;
        $this->streamPath = isset($config['streamPath']) ? $config['streamPath'] : '';
        $this->maxSegmentSize = isset($config['maxSegmentSize']) ? $config['maxSegmentSize'] : $this->maxSegmentSize;

        $this->loadmetadata = false;
        $this->ftyp_moov = null;
        $this->metaSuccRun = false;
        $this->metas = [];
        $this->parseChunk = null;
        $this->hasVideo = false;
        $this->hasAudio = false;
        $this->_pendingResolveSeekPoint = -1;
        $this->_tempBaseTime = 0;

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
        if ($this->onMediaSegment) {
            call_user_func($this->onMediaSegment, $value['data']);
        }

        // 如果是文件输出模式，写入到文件
        if (isset($this->_config['segmentDir']) && !empty($this->_config['segmentDir'])) {
            $this->writeSegmentToFile($value['data']);
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
     * 将分片数据写入文件
     * @param string $data 分片数据
     */
    protected function writeSegmentToFile($data)
    {
        $segmentDir = $this->_config['segmentDir'];

        // 如果目录不存在，创建目录
        if (!is_dir($segmentDir)) {
            mkdir($segmentDir, 0755, true);
        }

        // 将数据添加到临时缓冲区
        $this->mediaBuffer .= $data;

        // 调试日志
        static $callCount = 0;
        $callCount++;
        //error_log("LiveFlvToMp4: writeSegmentToFile call #{$callCount}, dataSize=" . strlen($data) . ", mediaBufferSize=" . strlen($this->mediaBuffer) . ", currentSegmentFile=" . ($this->currentSegmentFile ? 'open' : 'null'));

        // 只有当缓冲区达到最小大小才写入
        if (strlen($this->mediaBuffer) >= $this->minMediaBufferSize) {

            // 如果文件未打开，创建新文件
            if ($this->currentSegmentFile === null) {
                // 创建新文件名
                $this->segmentIndex++;
                $timestamp = date('YmdHis');
                $filename = rtrim($segmentDir, '/') . '/' .
                    basename($this->streamPath) . '_' .
                    $timestamp . '_' .
                    $this->segmentIndex . '.m4s';

                //error_log("LiveFlvToMp4: Creating new segment file: {$filename} (index={$this->segmentIndex})");

                $this->currentSegmentFile = fopen($filename, 'wb');
                $this->currentSegmentSize = 0;
            }

            // 写入缓冲区中的数据
            fwrite($this->currentSegmentFile, $this->mediaBuffer);
            $this->currentSegmentSize += strlen($this->mediaBuffer);
            $this->mediaBuffer = ''; // 清空缓冲区

            // 如果当前分片大小超过限制，关闭并准备新分片
            if ($this->currentSegmentSize >= $this->maxSegmentSize) {
                fclose($this->currentSegmentFile);
                $this->currentSegmentFile = null;
                //error_log("LiveFlvToMp4: Segment file closed, size={$this->currentSegmentSize}");
            }
        }
    }

    /**
     * 刷新缓冲区（在关闭时调用）
     */
    protected function flushMediaBuffer()
    {
        if (strlen($this->mediaBuffer) > 0) {
            if ($this->currentSegmentFile === null) {
                // 如果文件未打开，创建新文件
                $segmentDir = $this->_config['segmentDir'];
                $this->segmentIndex++;
                $timestamp = date('YmdHis');
                $filename = rtrim($segmentDir, '/') . '/' .
                    basename($this->streamPath) . '_' .
                    $timestamp . '_' .
                    $this->segmentIndex . '.m4s';

                //error_log("LiveFlvToMp4: Creating new segment file in flushMediaBuffer: {$filename} (index={$this->segmentIndex})");

                $this->currentSegmentFile = fopen($filename, 'wb');
                $this->currentSegmentSize = 0;
            }

            fwrite($this->currentSegmentFile, $this->mediaBuffer);
            $this->currentSegmentSize += strlen($this->mediaBuffer);
            $this->mediaBuffer = '';
            //error_log("LiveFlvToMp4: Flushed media buffer, size=" . strlen($this->mediaBuffer));
        }
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

        // 自动保存初始化分片到 segmentDir
        if (isset($this->_config['segmentDir']) && !empty($this->_config['segmentDir']) && $this->ftyp_moov) {
            $segmentDir = $this->_config['segmentDir'];
            if (!is_dir($segmentDir)) {
                mkdir($segmentDir, 0755, true);
            }
            $baseName = basename($this->streamPath);
            $initFile = rtrim($segmentDir, '/') . "/{$baseName}_init.mp4";
            file_put_contents($initFile, $this->ftyp_moov);
            error_log("LiveFlvToMp4: Saved init segment to {$initFile}");
        }

        if ($this->onInitSegment && $this->loadmetadata == false) {
            call_user_func($this->onInitSegment, $this->ftyp_moov);
            $this->loadmetadata = true;
        }
    }

    /**
     * 数据可用回调
     * @param array $audiotrack 音频轨道数据
     * @param array $videotrack 视频轨道数据
     */
    public function onDataAvailable($audiotrack, $videotrack)
    {
        // 直接调用 remux，数据累积在 processFlvData 层面处理
        $this->m4mof->remux($audiotrack, $videotrack);
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
     * @param int $baseTime 基础时间戳（可选，默认 0）
     * @return int 处理的字节数
     */
    public function processFlvData($flvData, $baseTime = 0)
    {
        if (!$this->initialized) {
            //error_log("LiveFlvToMp4: Not initialized");
            return 0;
        }

        $this->totalReceivedBytes += strlen($flvData);

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
        $processed = $this->processBuffer($baseTime);

        return $processed;
    }

    /**
     * 处理缓冲区中的 FLV 数据
     * @param int $baseTime 基础时间戳
     * @return int 处理的字节数
     */
    protected function processBuffer($baseTime)
    {
        $totalProcessed = 0;

        // 如果数据量足够，尝试处理
        while (strlen($this->flvBuffer) >= 11) { // 最小标签大小
            $processed = $this->setflv($this->flvBuffer, $baseTime);

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
    }

    /**
     * 从分片文件计算视频时长
     * @param array $segmentFiles 分片文件列表
     * @return int 时长（毫秒）
     */
    protected function calculateDurationFromSegments($segmentFiles)
    {
        $totalDuration = 0;
        $videoTimescale = 0;
        $audioTimescale = 0;

        //error_log("LiveFlvToMp4: calculateDurationFromSegments called with " . count($segmentFiles) . " files");

        // 获取 segmentDir
        $segmentDir = $this->_config['segmentDir'] ?? '';
        $baseName = basename($this->streamPath);

        // 查找 init.mp4 文件
        $initFile = rtrim($segmentDir, '/') . '/' . $baseName . '_init.mp4';

        if ($initFile === null || !file_exists($initFile)) {
            //error_log("LiveFlvToMp4: init file not found: {$initFile}");
            return 0;
        }

        $initData = file_get_contents($initFile);
        if (!$initData) {
            //error_log("LiveFlvToMp4: failed to read init file");
            return 0;
        }

        //error_log("LiveFlvToMp4: init file size = " . strlen($initData) . " bytes");

        // 检查 init 文件的 box 结构
        $firstBoxSize = unpack('N', substr($initData, 0, 4))[1];
        $firstBoxType = substr($initData, 4, 4);
        //error_log("LiveFlvToMp4: init first box: size={$firstBoxSize}, type={$firstBoxType}");

        // 检查 offset 28-60 的数据（ftyp 之后应该是 moov）
        $hex28 = bin2hex(substr($initData, 28, 32));
        //error_log("LiveFlvToMp4: init data at offset 28-59: {$hex28}");

        // 查找所有可能的 box
        $moovPos = strpos($initData, 'moov');
        $moofPos = strpos($initData, 'moof');
        $mvhdPos = strpos($initData, 'mvhd');
        //error_log("LiveFlvToMp4: box positions: moov=" . ($moovPos !== false ? $moovPos : 'not found') . ", moof=" . ($moofPos !== false ? $moofPos : 'not found') . ", mvhd=" . ($mvhdPos !== false ? $mvhdPos : 'not found'));

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
                                //error_log("LiveFlvToMp4: found video timescale = {$videoTimescale}");
                            } elseif ($handlerType == 'soun' && $audioTimescale == 0) {
                                $audioTimescale = $timescale;
                                //error_log("LiveFlvToMp4: found audio timescale = {$audioTimescale}");
                            }
                        }
                    }
                }
                $trakStart = strpos($initData, 'trak', $trakStart + 4);
            }
        }

        $timescale = $videoTimescale > 0 ? $videoTimescale : ($audioTimescale > 0 ? $audioTimescale : 1000);
        //error_log("LiveFlvToMp4: using timescale = {$timescale} (video={$videoTimescale}, audio={$audioTimescale})");

        // 第二步：从分片文件获取总时长
        $maxEndTime = 0;

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

        //error_log("LiveFlvToMp4: Calculated duration = {$durationMs} ms (maxEndTime={$maxEndTime}, timescale={$timescale})");

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
        //error_log("LiveFlvToMp4: updateInitSegmentDuration called, duration = {$duration} ms, initData length = " . strlen($initData));

        $mvhdStart = strpos($initData, 'mvhd');
        if ($mvhdStart === false) {
            //error_log("LiveFlvToMp4: mvhd box not found");
            return $initData;
        }

        //error_log("LiveFlvToMp4: mvhd found at offset {$mvhdStart}");

        if ($mvhdStart + 8 > strlen($initData)) {
            //error_log("LiveFlvToMp4: mvhd box position out of bounds");
            return $initData;
        }

        $mvhdSize = unpack('N', substr($initData, $mvhdStart, 4))[1];
        //error_log("LiveFlvToMp4: mvhd size from box = {$mvhdSize}, actual data available = " . (strlen($initData) - $mvhdStart));

        $mvhdVersion = ord(substr($initData, $mvhdStart + 8, 1));

        if ($mvhdVersion == 0) {
            if ($mvhdSize < 28 || $mvhdStart + 28 > strlen($initData)) {
                //error_log("LiveFlvToMp4: mvhd box too small for version 0, size={$mvhdSize}");
                return $initData;
            }
            $timescale = unpack('N', substr($initData, $mvhdStart + 20, 4))[1];
            $durationOffset = 24;
            $durationLength = 4;
        } else {
            if ($mvhdSize < 36 || $mvhdStart + 36 > strlen($initData)) {
                //error_log("LiveFlvToMp4: mvhd box too small for version 1, size={$mvhdSize}");
                return $initData;
            }
            $timescale = unpack('N', substr($initData, $mvhdStart + 28, 4))[1];
            $durationOffset = 32;
            $durationLength = 8;
        }

        $durationInTimescale = (int)($duration * $timescale / 1000);

        if ($durationLength == 4) {
            $durationBytes = pack('N', $durationInTimescale);
        } else {
            $durationBytes = pack('J', $durationInTimescale);
        }
        $initData = substr_replace($initData, $durationBytes, $mvhdStart + $durationOffset, $durationLength);

        //error_log("LiveFlvToMp4: Updated mvhd duration = {$durationInTimescale} ({$duration} ms), timescale = {$timescale}, version = {$mvhdVersion}, size = {$mvhdSize}");

        $trakStart = strpos($initData, 'trak');
        while ($trakStart !== false && $trakStart + 8 <= strlen($initData)) {
            $tkhdStart = strpos($initData, 'tkhd', $trakStart);
            if ($tkhdStart !== false && $tkhdStart + 8 <= strlen($initData)) {
                $tkhdSize = unpack('N', substr($initData, $tkhdStart, 4))[1];
                $tkhdVersion = ord(substr($initData, $tkhdStart + 8, 1));

                if ($tkhdVersion == 0) {
                    if ($tkhdSize < 32 || $tkhdStart + 32 > strlen($initData)) {
                        //error_log("LiveFlvToMp4: tkhd box too small for version 0, size={$tkhdSize}");
                        $trakStart = strpos($initData, 'trak', $trakStart + 4);
                        continue;
                    }
                    $durationOffset = 28;
                    $durationLength = 4;
                } else {
                    if ($tkhdSize < 44 || $tkhdStart + 44 > strlen($initData)) {
                        //error_log("LiveFlvToMp4: tkhd box too small for version 1, size={$tkhdSize}");
                        $trakStart = strpos($initData, 'trak', $trakStart + 4);
                        continue;
                    }
                    $durationOffset = 36;
                    $durationLength = 8;
                }

                if ($durationLength == 4) {
                    $durationBytes = pack('N', $durationInTimescale);
                } else {
                    $durationBytes = pack('J', $durationInTimescale);
                }
                $initData = substr_replace($initData, $durationBytes, $tkhdStart + $durationOffset, $durationLength);
            }
            $trakStart = strpos($initData, 'trak', $trakStart + 4);
        }

        return $initData;
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
            //error_log("LiveFlvToMp4: segmentDir not configured");
            return false;
        }

        $segmentDir = $this->_config['segmentDir'];
        $baseName = basename($this->streamPath);

        // 如果目录不存在，返回失败
        if (!is_dir($segmentDir)) {
            //error_log("LiveFlvToMp4: segmentDir does not exist: {$segmentDir}");
            return false;
        }

        // 构建输出文件路径
        if ($outputFile === null) {
            $outputFile = rtrim($segmentDir, '/') . "/{$baseName}_full.mp4";
        }

        // 查找所有分片文件
        $initFile = rtrim($segmentDir, '/') . "/{$baseName}_init.mp4";
        $segmentPattern = rtrim($segmentDir, '/') . "/{$baseName}_*.m4s";

        // 检查初始化文件是否存在
        if (!file_exists($initFile)) {
            //error_log("LiveFlvToMp4: init file not found: {$initFile}");
            return false;
        }

        // 获取所有分片文件并排序
        $segmentFiles = glob($segmentPattern);
        if (empty($segmentFiles)) {
            //error_log("LiveFlvToMp4: no segment files found: {$segmentPattern}");
            return false;
        }

        // 按创建时间排序
        usort($segmentFiles, function($a, $b) {
            return filemtime($a) - filemtime($b);
        });

        try {
            // 创建输出文件
            $outputHandle = fopen($outputFile, 'wb');
            if (!$outputHandle) {
                //error_log("LiveFlvToMp4: failed to create output file: {$outputFile}");
                return false;
            }

            // 读取初始化文件
            $initData = file_get_contents($initFile);

            // 计算实际时长并更新初始化片段
            $duration = $this->calculateDurationFromSegments($segmentFiles);
            //error_log("LiveFlvToMp4: Final duration calculated = {$duration} ms (expected ~10000 ms for 10s recording)");
            if ($duration > 0) {
                $initData = $this->updateInitSegmentDuration($initData, $duration);
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

            // 删除初始化文件
            if ($deleteSegments && file_exists($initFile)) {
                unlink($initFile);
            }

            //error_log("LiveFlvToMp4: Successfully merged MP4 file: {$outputFile} ({$totalSize} bytes)");

            // 触发回调
            if ($this->onMediaInfo) {
                call_user_func($this->onMediaInfo, null, ['mergedFile' => $outputFile, 'size' => $totalSize]);
            }

            return $outputFile;
        } catch (\Exception $e) {
            //error_log("LiveFlvToMp4: Failed to merge MP4 file: " . $e->getMessage());
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
            'segmentIndex' => $this->segmentIndex,
            'currentSegmentSize' => $this->currentSegmentSize,
            'hasAudio' => $this->hasAudio,
            'hasVideo' => $this->hasVideo,
            'metadataLoaded' => $this->loadmetadata
        ];
    }

    /**
     * 析构函数
     */
    public function __destruct()
    {
        $this->cleanup();
        //error_log("LiveFlvToMp4: Stream {$this->streamPath} destroyed");
    }
}
