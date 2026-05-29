<?php

namespace Xiaosongshu\Flv2mp4\MP4;

/**
 * @purpose 样本信息类
 * @author yanglong
 * @time 2026年5月29日14:26:52
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