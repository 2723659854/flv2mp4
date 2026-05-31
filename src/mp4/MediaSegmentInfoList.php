<?php

namespace Xiaosongshu\Flv2mp4\MP4;

/**
 * @purpose 媒体包切片列表
 * @author yanglong
 */
class MediaSegmentInfoList
{
    private $_type;
    private $_list = [];
    private $_lastAppendLocation = -1;

    public function __construct($type)
    {
        $this->_type = $type;
    }

    public function getType()
    {
        return $this->_type;
    }

    public function getLength()
    {
        return count($this->_list);
    }

    public function isEmpty()
    {
        return count($this->_list) === 0;
    }

    public function clear()
    {
        $this->_list = [];
        $this->_lastAppendLocation = -1;
    }

    private function _searchNearestSegmentBefore($originalBeginDts)
    {
        $list = $this->_list;
        if (count($list) === 0) return -2;
        $last = count($list) - 1;
        if ($originalBeginDts < $list[0]->originalBeginDts) return -1;
        $lbound = 0;
        $ubound = $last;
        $idx = 0;
        while ($lbound <= $ubound) {
            $mid = $lbound + (int)(($ubound - $lbound) / 2);
            if ($mid === $last || ($originalBeginDts > $list[$mid]->lastSample->originalDts && $originalBeginDts < $list[$mid+1]->originalBeginDts)) {
                $idx = $mid;
                break;
            } elseif ($list[$mid]->originalBeginDts < $originalBeginDts) {
                $lbound = $mid + 1;
            } else {
                $ubound = $mid - 1;
            }
        }
        return $idx;
    }

    private function _searchNearestSegmentAfter($originalBeginDts)
    {
        return $this->_searchNearestSegmentBefore($originalBeginDts) + 1;
    }

    public function append($mediaSegmentInfo)
    {
        $list = &$this->_list;
        $msi = $mediaSegmentInfo;
        $lastAppendIdx = $this->_lastAppendLocation;
        $insertIdx = 0;
        if ($lastAppendIdx !== -1 && $lastAppendIdx < count($list) &&
            $msi->originalBeginDts >= $list[$lastAppendIdx]->lastSample->originalDts &&
            ($lastAppendIdx === count($list)-1 || ($lastAppendIdx < count($list)-1 && $msi->originalBeginDts < $list[$lastAppendIdx+1]->originalBeginDts))) {
            $insertIdx = $lastAppendIdx + 1;
        } else {
            if (count($list) > 0) {
                $insertIdx = $this->_searchNearestSegmentBefore($msi->originalBeginDts) + 1;
            }
        }
        $this->_lastAppendLocation = $insertIdx;
        array_splice($list, $insertIdx, 0, [$msi]);
    }

    public function getLastSegmentBefore($originalBeginDts)
    {
        $idx = $this->_searchNearestSegmentBefore($originalBeginDts);
        if ($idx >= 0) return $this->_list[$idx];
        return null;
    }

    public function getLastSampleBefore($originalBeginDts)
    {
        $segment = $this->getLastSegmentBefore($originalBeginDts);
        return $segment ? $segment->lastSample : null;
    }

    public function getLastSyncPointBefore($originalBeginDts)
    {
        $segmentIdx = $this->_searchNearestSegmentBefore($originalBeginDts);
        if ($segmentIdx < 0) return null;
        $syncPoints = $this->_list[$segmentIdx]->syncPoints;
        while (count($syncPoints) === 0 && $segmentIdx > 0) {
            $segmentIdx--;
            $syncPoints = $this->_list[$segmentIdx]->syncPoints;
        }
        if (count($syncPoints) > 0) return $syncPoints[count($syncPoints)-1];
        return null;
    }
}