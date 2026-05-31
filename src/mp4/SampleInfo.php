<?php

namespace Xiaosongshu\Flv2mp4\MP4;

/**
 * @purpose 采样率包
 * @author yanglong
 */
class SampleInfo
{
    public $dts;
    public $pts;
    public $duration;
    public $originalDts;
    public $isSyncPoint;
    public $fileposition;

    public function __construct($dts, $pts, $duration, $originalDts, $isSync)
    {
        $this->dts = $dts;
        $this->pts = $pts;
        $this->duration = $duration;
        $this->originalDts = $originalDts;
        $this->isSyncPoint = $isSync;
        $this->fileposition = null;
    }
}