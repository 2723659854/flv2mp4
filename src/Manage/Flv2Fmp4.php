<?php

namespace Xiaosongshu\Flv2mp4\Manage;

use Xiaosongshu\Flv2mp4\Flv\FlvParse;
use Xiaosongshu\Flv2mp4\Flv\TagDemux;
use Xiaosongshu\Flv2mp4\Mp4\MP4;
use Xiaosongshu\Flv2mp4\Mp4\MP4Remuxer;

/**
 * @purpose 静态flv转码fmp4工具
 * @author yanglong
 */
class Flv2Fmp4
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

    // 单独的音视频初始化数据
    public $audioInitSegment = null;
    public $videoInitSegment = null;

    public function __construct($config = [])
    {
        $this->_config = ['_isLive' => false];
        $this->_config = array_merge($this->_config, $config);

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
    }

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

    public function setflvBaseUsually($arraybuff, $baseTime)
    {
        $offset = FlvParse::setFlv($arraybuff);
        if (count(FlvParse::$arrTag) > 0) {
            $this->tagDemux->moofTag(FlvParse::$arrTag);
        }
        return $offset;
    }

    // 修改 Flv2Fmp4.php 中的 onMdiaSegment 方法
    public function onMdiaSegment($track, $value)
    {
        // 直接输出，不要缓存
        if ($this->onMediaSegment) {
            call_user_func($this->onMediaSegment, $value['data'], ['track' => $track, 'info' => $value['info'] ?? null, 'isKeyframe' => $value['isKeyframe'] ?? false]);
        }

        // 分开输出音视频切片
        if ($track == 'audio' && $this->onAudioSegment) {
            call_user_func($this->onAudioSegment, $value['data'], $value);
        } elseif ($track == 'video' && $this->onVideoSegment) {
            call_user_func($this->onVideoSegment, $value['data'], $value);
        }

        if ($this->_pendingResolveSeekPoint != -1 && $track == 'video') {
            $seekpoint = $this->_pendingResolveSeekPoint;
            $this->_pendingResolveSeekPoint = -1;
            if ($this->seekCallBack) {
                call_user_func($this->seekCallBack, $seekpoint);
            }
        }
    }

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
        if ($this->onInitSegment && $this->loadmetadata == false) {
            call_user_func($this->onInitSegment, $this->ftyp_moov);
            $this->loadmetadata = true;
        }

        // 生成单独的音视频初始化片段
        foreach ($this->metas as $meta) {
            if ($meta['type'] == 'audio') {
                $this->audioInitSegment = MP4::generateAudioInitSegment($meta);
                if ($this->onAudioInitSegment) {
                    call_user_func($this->onAudioInitSegment, $this->audioInitSegment, $meta);
                }
            } elseif ($meta['type'] == 'video') {
                $this->videoInitSegment = MP4::generateVideoInitSegment($meta);
                if ($this->onVideoInitSegment) {
                    call_user_func($this->onVideoInitSegment, $this->videoInitSegment, $meta);
                }
            }
        }
    }

    public function onDataAvailable($audiotrack, $videotrack)
    {
        $this->m4mof->remux($audiotrack, $videotrack);
    }

    public function setflv($arraybuff, $baseTime)
    {
        return call_user_func($this->setflvBase, $arraybuff, $baseTime);
    }

    public function setflvloc($arraybuff)
    {
        $offset = FlvParse::setFlv($arraybuff);
        if (count(FlvParse::$arrTag) > 0) {
            return FlvParse::$arrTag;
        }
        return [];
    }
}