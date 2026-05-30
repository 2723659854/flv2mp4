<?php

namespace Xiaosongshu\Flv2mp4\Mp4;

class MediaSegmentInfo
{
    public $beginDts;
    public $endDts;
    public $beginPts;
    public $endPts;
    public $originalBeginDts;
    public $originalEndDts;
    public $syncPoints;
    public $firstSample;
    public $lastSample;

    public function __construct()
    {
        $this->beginDts = 0;
        $this->endDts = 0;
        $this->beginPts = 0;
        $this->endPts = 0;
        $this->originalBeginDts = 0;
        $this->originalEndDts = 0;
        $this->syncPoints = [];
        $this->firstSample = null;
        $this->lastSample = null;
    }

    public function appendSyncPoint($sampleInfo)
    {
        $sampleInfo->isSyncPoint = true;
        $this->syncPoints[] = $sampleInfo;
    }
}