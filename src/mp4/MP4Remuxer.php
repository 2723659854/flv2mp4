<?php
namespace Xiaosongshu\Flv2mp4\MP4;

/**
 * @purpose mp4编码器
 * @author yanglong
 * @time 2026年5月29日14:30:50
 */
class MP4Remuxer
{
    public $TAG;
    public $_config;
    public $_isLive;
    public $_dtsBase = -1;
    public $_dtsBaseInited = false;
    public $_audioDtsBase = INF;
    public $_videoDtsBase = INF;
    public $_audioNextDts = null;
    public $_videoNextDts = null;
    public $_audioMeta = null;
    public $_videoMeta = null;
    public $_audioSegmentInfoList;
    public $_videoSegmentInfoList;
    public $_onInitSegment = null;
    public $_onMediaSegment = null;
    public $_forceFirstIDR = false;
    public $_fillSilentAfterSeek = false;

    public function __construct($config)
    {
        $this->TAG = 'MP4Remuxer';
        $this->_config = $config;
        $this->_isLive = isset($config['isLive']) && $config['isLive'] === true;
        $this->_audioSegmentInfoList = new MediaSegmentInfoList('audio');
        $this->_videoSegmentInfoList = new MediaSegmentInfoList('video');
        $this->_forceFirstIDR = false;
        $this->_fillSilentAfterSeek = false;
    }

    public function destroy()
    {
        $this->_dtsBase = -1;
        $this->_dtsBaseInited = false;
        $this->_audioMeta = null;
        $this->_videoMeta = null;
        $this->_audioSegmentInfoList->clear();
        $this->_audioSegmentInfoList = null;
        $this->_videoSegmentInfoList->clear();
        $this->_videoSegmentInfoList = null;
        $this->_onInitSegment = null;
        $this->_onMediaSegment = null;
    }

    public function bindDataSource($producer)
    {
        $producer->onDataAvailable = [$this, 'remux'];
        $producer->onTrackMetadata = [$this, '_onTrackMetadataReceived'];
        return $this;
    }

    public function getOnInitSegment()
    {
        return $this->_onInitSegment;
    }

    public function setOnInitSegment($callback)
    {
        $this->_onInitSegment = $callback;
    }

    public function getOnMediaSegment()
    {
        return $this->_onMediaSegment;
    }

    public function setOnMediaSegment($callback)
    {
        $this->_onMediaSegment = $callback;
    }

    public function insertDiscontinuity()
    {
        $this->_audioNextDts = null;
        $this->_videoNextDts = null;
    }

    public function seek($originalDts)
    {
        $this->_videoSegmentInfoList->clear();
        $this->_audioSegmentInfoList->clear();
    }

    public function remux($audioTrack, $videoTrack)
    {
        if (!$this->_onMediaSegment) {
            throw new \Exception('MP4Remuxer: onMediaSegment callback must be specified!');
        }
        if (!$this->_dtsBaseInited) {
            $this->_calculateDtsBase($audioTrack, $videoTrack);
        }
        $this->_remuxVideo($videoTrack);
        $this->_remuxAudio($audioTrack);
    }

    public function _onTrackMetadataReceived($type, $metadata)
    {
        if ($type === 'audio') {
            $this->_audioMeta = $metadata;
            $metabox = MP4::generateInitSegment($metadata);
            error_log("msg+audio: " . print_r($metadata, true));
        } elseif ($type === 'video') {
            $this->_videoMeta = $metadata;
            $metabox = MP4::generateInitSegment($metadata);
            error_log("msg+video: " . print_r($metadata, true));
        } else {
            return;
        }
        if (!$this->_onInitSegment) {
            throw new \Exception('MP4Remuxer: onInitSegment callback must be specified!');
        }
        call_user_func($this->_onInitSegment, $type, [
            'type' => $type,
            'data' => $metabox,
            'codec' => $metadata['codec'],
            'container' => $type . '/mp4'
        ]);
    }

    public function _calculateDtsBase($audioTrack, $videoTrack)
    {
        if ($this->_dtsBaseInited) return;
        if (!empty($audioTrack['samples']) && count($audioTrack['samples'])) {
            $this->_audioDtsBase = $audioTrack['samples'][0]['dts'];
        }
        if (!empty($videoTrack['samples']) && count($videoTrack['samples'])) {
            $this->_videoDtsBase = $videoTrack['samples'][0]['dts'];
        }
        $this->_dtsBase = min($this->_audioDtsBase, $this->_videoDtsBase);
        $this->_dtsBaseInited = true;
    }

    public function _remuxAudio(&$audioTrack)
    {
        if ($this->_audioMeta === null) return;
        $samples = &$audioTrack['samples'];
        if (empty($samples)) return;
        $dtsCorrection = null;
        $firstDts = -1;
        $lastDts = -1;
        $remuxSilentFrame = false;
        $silentFrameDuration = -1;
        $refSampleDuration = $this->_audioMeta['refSampleDuration'];
        $mdatChunks = [];
        $mp4Samples = [];
        while (count($samples)) {
            $aacSample = array_shift($samples);
            $unit = $aacSample['unit'];
            $originalDts = $aacSample['dts'] - $this->_dtsBase;
            if ($dtsCorrection === null) {
                if ($this->_audioNextDts === null) {
                    if ($this->_audioSegmentInfoList->isEmpty()) {
                        $dtsCorrection = 0;
                        if ($this->_fillSilentAfterSeek && !$this->_videoSegmentInfoList->isEmpty()) {
                            $remuxSilentFrame = true;
                        }
                    } else {
                        $lastSample = $this->_audioSegmentInfoList->getLastSampleBefore($originalDts);
                        if ($lastSample != null) {
                            $distance = $originalDts - ($lastSample->originalDts + $lastSample->duration);
                            if ($distance <= 3) $distance = 0;
                            $expectedDts = $lastSample->dts + $lastSample->duration + $distance;
                            $dtsCorrection = $originalDts - $expectedDts;
                        } else {
                            $dtsCorrection = 0;
                        }
                    }
                } else {
                    $dtsCorrection = $originalDts - $this->_audioNextDts;
                }
            }
            $dts = $originalDts - $dtsCorrection;
            if ($remuxSilentFrame) {
                $videoSegment = $this->_videoSegmentInfoList->getLastSegmentBefore($originalDts);
                if ($videoSegment != null && $videoSegment->beginDts < $dts) {
                    $silentFrameDuration = $dts - $videoSegment->beginDts;
                    $dts = $videoSegment->beginDts;
                } else {
                    $remuxSilentFrame = false;
                }
            }
            if ($firstDts === -1) $firstDts = $dts;
            if ($remuxSilentFrame) {
                $remuxSilentFrame = false;
                array_unshift($samples, $aacSample);
                $frame = $this->_generateSilentAudio($dts, $silentFrameDuration);
                if ($frame !== null) {
                    $mp4Samples[] = $frame['mp4Sample'];
                    $mdatChunks[] = $frame['unit'];
                }
                continue;
            }
            $sampleDuration = 0;
            if (count($samples) >= 1) {
                $nextDts = $samples[0]['dts'] - $this->_dtsBase - $dtsCorrection;
                $sampleDuration = $nextDts - $dts;
            } else {
                if (count($mp4Samples) >= 1) {
                    $sampleDuration = $mp4Samples[count($mp4Samples)-1]['duration'];
                } else {
                    $sampleDuration = $refSampleDuration;
                }
            }
            $mp4Sample = [
                'dts' => $dts,
                'pts' => $dts,
                'cts' => 0,
                'size' => strlen($unit),
                'duration' => $sampleDuration,
                'originalDts' => $originalDts,
                'flags' => ['isLeading'=>0, 'dependsOn'=>1, 'isDependedOn'=>0, 'hasRedundancy'=>0, 'isNonSync'=>1]
            ];
            $mp4Samples[] = $mp4Sample;
            $mdatChunks[] = $unit;
        }
        $latest = $mp4Samples[count($mp4Samples)-1];
        $lastDts = $latest['dts'] + $latest['duration'];
        $this->_audioNextDts = $lastDts;
        $mdatData = implode('', $mdatChunks);
        $mdatSize = 8 + strlen($mdatData);
        $mdat = pack('N', $mdatSize) . 'mdat' . $mdatData;
        $info = new MediaSegmentInfo();
        $info->beginDts = $firstDts;
        $info->endDts = $lastDts;
        $info->beginPts = $firstDts;
        $info->endPts = $lastDts;
        $info->originalBeginDts = $mp4Samples[0]['originalDts'];
        $info->originalEndDts = $latest['originalDts'] + $latest['duration'];
        $info->firstSample = new SampleInfo($mp4Samples[0]['dts'], $mp4Samples[0]['pts'], $mp4Samples[0]['duration'], $mp4Samples[0]['originalDts'], false);
        $info->lastSample = new SampleInfo($latest['dts'], $latest['pts'], $latest['duration'], $latest['originalDts'], false);
        if (!$this->_isLive) $this->_audioSegmentInfoList->append($info);
        $audioTrack['samples'] = $mp4Samples;
        $audioTrack['sequenceNumber'] += $audioTrack['addcoefficient'];
        $moof = MP4::moof($audioTrack, $firstDts);
        $audioTrack['samples'] = [];
        $audioTrack['length'] = 0;
        $merged = $moof . $mdat;
        call_user_func($this->_onMediaSegment, 'audio', [
            'type' => 'audio',
            'data' => $merged,
            'sampleCount' => count($mp4Samples),
            'info' => $info
        ]);
    }

    public function _generateSilentAudio($dts, $frameDuration)
    {
        error_log($this->TAG . " GenerateSilentAudio: dts = {$dts}, duration = {$frameDuration}");
        $unit = AAC::getSilentFrame($this->_audioMeta['channelCount']);
        if ($unit === null) {
            error_log($this->TAG . " Cannot generate silent aac frame for channelCount = {$this->_audioMeta['channelCount']}");
            return null;
        }
        $mp4Sample = [
            'dts' => $dts,
            'pts' => $dts,
            'cts' => 0,
            'size' => strlen($unit),
            'duration' => $frameDuration,
            'originalDts' => $dts,
            'flags' => ['isLeading'=>0, 'dependsOn'=>1, 'isDependedOn'=>0, 'hasRedundancy'=>0]
        ];
        return ['unit' => $unit, 'mp4Sample' => $mp4Sample];
    }

    public function _remuxVideo(&$videoTrack)
    {
        $samples = &$videoTrack['samples'];
        if (empty($samples)) return;
        $dtsCorrection = null;
        $firstDts = -1;
        $lastDts = -1;
        $firstPts = -1;
        $lastPts = -1;
        $mdatChunks = [];
        $mp4Samples = [];
        $info = new MediaSegmentInfo();
        while (count($samples)) {
            $avcSample = array_shift($samples);
            $keyframe = $avcSample['isKeyframe'];
            $originalDts = $avcSample['dts'] - $this->_dtsBase;
            if ($dtsCorrection === null) {
                if ($this->_videoNextDts === null) {
                    if ($this->_videoSegmentInfoList->isEmpty()) {
                        $dtsCorrection = 0;
                    } else {
                        $lastSample = $this->_videoSegmentInfoList->getLastSampleBefore($originalDts);
                        if ($lastSample != null) {
                            $distance = $originalDts - ($lastSample->originalDts + $lastSample->duration);
                            if ($distance <= 3) $distance = 0;
                            $expectedDts = $lastSample->dts + $lastSample->duration + $distance;
                            $dtsCorrection = $originalDts - $expectedDts;
                        } else {
                            $dtsCorrection = 0;
                        }
                    }
                } else {
                    $dtsCorrection = $originalDts - $this->_videoNextDts;
                }
            }
            $dts = $originalDts - $dtsCorrection;
            $cts = $avcSample['cts'];
            $pts = $dts + $cts;
            if ($firstDts === -1) {
                $firstDts = $dts;
                $firstPts = $pts;
            }
            $sampleSize = 0;
            // 如果是关键帧，在前面添加SPS和PPS
            if ($keyframe && !empty($this->_videoMeta['sps']) && !empty($this->_videoMeta['pps'])) {
                $mdatChunks[] = "\x00\x00\x00\x01" . $this->_videoMeta['sps'];
                $mdatChunks[] = "\x00\x00\x00\x01" . $this->_videoMeta['pps'];
                $sampleSize += 4 + strlen($this->_videoMeta['sps']) + 4 + strlen($this->_videoMeta['pps']);
            }
            foreach ($avcSample['units'] as $unit) {
                $data = $unit['data'];
                // 在MP4中，每个NALU前面需要添加起始码
                $mdatChunks[] = "\x00\x00\x00\x01" . $data;
                $sampleSize += 4 + strlen($data);
            }
            $sampleDuration = 0;
            if (count($samples) >= 1) {
                $nextDts = $samples[0]['dts'] - $this->_dtsBase - $dtsCorrection;
                $sampleDuration = $nextDts - $dts;
            } else {
                if (count($mp4Samples) >= 1) {
                    $sampleDuration = $mp4Samples[count($mp4Samples)-1]['duration'];
                } else {
                    $sampleDuration = $this->_videoMeta['refSampleDuration'];
                }
            }
            if ($keyframe) {
                $syncPoint = new SampleInfo($dts, $pts, $sampleDuration, $avcSample['dts'], true);
                if (isset($avcSample['fileposition'])) $syncPoint->fileposition = $avcSample['fileposition'];
                $info->appendSyncPoint($syncPoint);
            }
            $mp4Sample = [
                'dts' => $dts,
                'pts' => $pts,
                'cts' => $cts,
                'size' => $sampleSize,
                'isKeyframe' => $keyframe,
                'duration' => $sampleDuration,
                'originalDts' => $originalDts,
                'flags' => [
                    'isLeading' => 0,
                    'dependsOn' => $keyframe ? 2 : 1,
                    'isDependedOn' => $keyframe ? 1 : 0,
                    'hasRedundancy' => 0,
                    'isNonSync' => $keyframe ? 0 : 1
                ]
            ];
            $mp4Samples[] = $mp4Sample;
        }
        $latest = $mp4Samples[count($mp4Samples)-1];
        $lastDts = $latest['dts'] + $latest['duration'];
        $lastPts = $latest['pts'] + $latest['duration'];
        $this->_videoNextDts = $lastDts;
        $mdatData = implode('', $mdatChunks);
        $mdatSize = 8 + strlen($mdatData);
        $mdat = pack('N', $mdatSize) . 'mdat' . $mdatData;
        $info->beginDts = $firstDts;
        $info->endDts = $lastDts;
        $info->beginPts = $firstPts;
        $info->endPts = $lastPts;
        $info->originalBeginDts = $mp4Samples[0]['originalDts'];
        $info->originalEndDts = $latest['originalDts'] + $latest['duration'];
        $info->firstSample = new SampleInfo($mp4Samples[0]['dts'], $mp4Samples[0]['pts'], $mp4Samples[0]['duration'], $mp4Samples[0]['originalDts'], $mp4Samples[0]['isKeyframe']);
        $info->lastSample = new SampleInfo($latest['dts'], $latest['pts'], $latest['duration'], $latest['originalDts'], $latest['isKeyframe']);
        if (!$this->_isLive) $this->_videoSegmentInfoList->append($info);
        $videoTrack['samples'] = $mp4Samples;
        $videoTrack['sequenceNumber'] += $videoTrack['addcoefficient'];
        if ($this->_forceFirstIDR) {
            $flags = &$mp4Samples[0]['flags'];
            $flags['dependsOn'] = 2;
            $flags['isNonSync'] = 0;
        }
        $moof = MP4::moof($videoTrack, $firstDts);
        $videoTrack['samples'] = [];
        $videoTrack['length'] = 0;
        $merged = $moof . $mdat;
        call_user_func($this->_onMediaSegment, 'video', [
            'type' => 'video',
            'data' => $merged,
            'sampleCount' => count($mp4Samples),
            'info' => $info
        ]);
    }
}