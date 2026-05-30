<?php

namespace Xiaosongshu\Flv2mp4\Mp4;

class IDRSampleList
{
    private $_list = [];

    public function clear()
    {
        $this->_list = [];
    }

    public function appendArray($syncPoints)
    {
        if (count($syncPoints) === 0) return;
        $list = &$this->_list;
        if (count($list) > 0 && $syncPoints[0]->originalDts < $list[count($list)-1]->originalDts) {
            $this->clear();
        }
        array_push($list, ...$syncPoints);
    }

    public function getLastSyncPointBeforeDts($dts)
    {
        if (count($this->_list) == 0) return null;
        $list = $this->_list;
        $last = count($list) - 1;
        if ($dts < $list[0]->dts) return $list[0];
        $lbound = 0;
        $ubound = $last;
        $idx = 0;
        while ($lbound <= $ubound) {
            $mid = $lbound + (int)(($ubound - $lbound) / 2);
            if ($mid === $last || ($dts >= $list[$mid]->dts && $dts < $list[$mid+1]->dts)) {
                $idx = $mid;
                break;
            } elseif ($list[$mid]->dts < $dts) {
                $lbound = $mid + 1;
            } else {
                $ubound = $mid - 1;
            }
        }
        return $list[$idx];
    }
}