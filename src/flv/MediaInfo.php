<?php

namespace Xiaosongshu\Flv2mp4\Flv;

/**
 * @purpose flv媒体信息包
 * @author yanglong
 */
class MediaInfo
{
    public $mimeType = null;
    public $duration = null;
    public $hasAudio = null;
    public $hasVideo = null;
    public $audioCodec = null;
    public $videoCodec = null;
    public $audioDataRate = null;
    public $videoDataRate = null;
    public $audioSampleRate = null;
    public $audioChannelCount = null;
    public $width = null;
    public $height = null;
    public $fps = null;
    public $profile = null;
    public $level = null;
    public $chromaFormat = null;
    public $sarNum = null;
    public $sarDen = null;
    public $metadata = null;
    public $segments = null;
    public $segmentCount = null;
    public $hasKeyframesIndex = null;
    public $keyframesIndex = null;

    public function isComplete()
    {
        $audioInfoComplete = ($this->hasAudio === false) ||
            ($this->hasAudio === true &&
                $this->audioCodec !== null &&
                $this->audioSampleRate !== null &&
                $this->audioChannelCount !== null);
        $videoInfoComplete = ($this->hasVideo === false) ||
            ($this->hasVideo === true &&
                $this->videoCodec !== null &&
                $this->width !== null &&
                $this->height !== null &&
                $this->fps !== null &&
                $this->profile !== null &&
                $this->level !== null &&
                $this->chromaFormat !== null &&
                $this->sarNum !== null &&
                $this->sarDen !== null);
        return $this->mimeType !== null &&
            $this->duration !== null &&
            $this->metadata !== null &&
            $this->hasKeyframesIndex !== null &&
            $audioInfoComplete &&
            $videoInfoComplete;
    }

    public function isSeekable()
    {
        return $this->hasKeyframesIndex === true;
    }
}