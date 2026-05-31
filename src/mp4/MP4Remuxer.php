<?php
namespace Xiaosongshu\Flv2mp4\MP4;

/**
 * @purpose mp4编码器
 * @author yanglong
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
        $this->_dtsBaseInited = false;
    }

    public function seek($originalDts)
    {
        $this->_videoSegmentInfoList->clear();
        $this->_audioSegmentInfoList->clear();
        $this->_dtsBaseInited = false;
    }

    public function remux($audioTrack, $videoTrack)
    {
        if (!$this->_onMediaSegment) {
            throw new \Exception('MP4Remuxer: onMediaSegment callback must be specified!');
        }
        if (!$this->_dtsBaseInited) {
            $this->_calculateDtsBase($audioTrack, $videoTrack);
        }

        $hasVideo = !empty($videoTrack['samples']) && count($videoTrack['samples']) > 0;
        $hasAudio = !empty($audioTrack['samples']) && count($audioTrack['samples']) > 0;

        if (!$hasVideo && !$hasAudio) return;

        $audioSamples = $hasAudio ? $audioTrack['samples'] : [];
        $videoSamples = $hasVideo ? $videoTrack['samples'] : [];

        $audioChunks = [];
        $videoChunks = [];

        // 将音频样本分成小块（每100ms一块）
        if ($hasAudio) {
            $chunk = [];
            $chunkStartDts = null;
            foreach ($audioSamples as $sample) {
                $dts = $sample['dts'] - $this->_audioDtsBase;
                if ($chunkStartDts === null) {
                    $chunkStartDts = $dts;
                }
                $chunk[] = $sample;
                if ($dts - $chunkStartDts >= 100) { // 100ms chunk
                    $audioChunks[] = $chunk;
                    $chunk = [];
                    $chunkStartDts = null;
                }
            }
            if (!empty($chunk)) {
                $audioChunks[] = $chunk;
            }
        }

        // 将视频样本分成小块（每100ms一块）
        if ($hasVideo) {
            $chunk = [];
            $chunkStartDts = null;
            foreach ($videoSamples as $sample) {
                $dts = $sample['dts'] - $this->_videoDtsBase;
                if ($chunkStartDts === null) {
                    $chunkStartDts = $dts;
                }
                $chunk[] = $sample;
                if ($dts - $chunkStartDts >= 100) { // 100ms chunk
                    $videoChunks[] = $chunk;
                    $chunk = [];
                    $chunkStartDts = null;
                }
            }
            if (!empty($chunk)) {
                $videoChunks[] = $chunk;
            }
        }

        // 交错处理音频和视频chunks
        $maxChunks = max(count($audioChunks), count($videoChunks));
        for ($i = 0; $i < $maxChunks; $i++) {
            if ($i < count($videoChunks)) {
                $videoTrack['samples'] = $videoChunks[$i];
                $this->_remuxVideo($videoTrack);
            }
            if ($i < count($audioChunks)) {
                $audioTrack['samples'] = $audioChunks[$i];
                $this->_remuxAudio($audioTrack);
            }
        }
    }

    public function _onTrackMetadataReceived($type, $metadata)
    {
        if ($type === 'audio') {
            $this->_audioMeta = $metadata;
            $metabox = MP4::generateInitSegment($metadata);
        } elseif ($type === 'video') {
            $this->_videoMeta = $metadata;
            $metabox = MP4::generateInitSegment($metadata);
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

        // 分别记录音频和视频的第一个DTS
        if (!empty($audioTrack['samples']) && count($audioTrack['samples'])) {
            $this->_audioDtsBase = $audioTrack['samples'][0]['dts'];
        }
        if (!empty($videoTrack['samples']) && count($videoTrack['samples'])) {
            $this->_videoDtsBase = $videoTrack['samples'][0]['dts'];
        }

        $minAudio = ($this->_audioDtsBase === INF) ? PHP_INT_MAX : $this->_audioDtsBase;
        $minVideo = ($this->_videoDtsBase === INF) ? PHP_INT_MAX : $this->_videoDtsBase;
        $this->_dtsBase = min($minAudio, $minVideo);

        //error_log("MP4Remuxer: Audio DTS Base = {$this->_audioDtsBase}, Video DTS Base = {$this->_videoDtsBase}, Common Base = {$this->_dtsBase}");

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
        $refSampleDuration = $this->_audioMeta['refSampleDuration'];
        $mdatChunks = [];
        $mp4Samples = [];

        while (count($samples)) {
            $aacSample = array_shift($samples);
            $unit = $aacSample['unit'];

            // 使用音频自己的DTS基准
            $originalDts = $aacSample['dts'] - $this->_audioDtsBase;

            if ($dtsCorrection === null) {
                if ($this->_audioNextDts === null) {
                    if ($this->_audioSegmentInfoList->isEmpty()) {
                        $dtsCorrection = 0;
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
                    if ($originalDts <= $this->_audioNextDts) {
                        $dtsCorrection = $originalDts - ($this->_audioNextDts + 1);
                    } else {
                        $dtsCorrection = 0;
                    }
                }
            }

            $dts = $originalDts - $dtsCorrection;

            // 确保DTS单调递增
            if (!empty($mp4Samples)) {
                $prevSample = $mp4Samples[count($mp4Samples)-1];
                if ($dts <= $prevSample['dts']) {
                    $dts = $prevSample['dts'] + 1;
                }
            }

            if ($firstDts === -1) $firstDts = $dts;

            // 计算采样持续时间
            $sampleDuration = 0;
            if (count($samples) >= 1) {
                $nextDts = $samples[0]['dts'] - $this->_audioDtsBase - $dtsCorrection;
                $sampleDuration = $nextDts - $dts;
                if ($sampleDuration <= 0) {
                    $sampleDuration = $refSampleDuration;
                }
            } else {
                if (count($mp4Samples) >= 1) {
                    $sampleDuration = $mp4Samples[count($mp4Samples)-1]['duration'];
                    if ($sampleDuration <= 0) {
                        $sampleDuration = $refSampleDuration;
                    }
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

        if (empty($mp4Samples)) return;

        $latest = $mp4Samples[count($mp4Samples)-1];
        $lastDts = $latest['dts'] + $latest['duration'];

        if ($this->_audioNextDts !== null && $lastDts <= $this->_audioNextDts) {
            $lastDts = $this->_audioNextDts + 1;
        }
        $this->_audioNextDts = $lastDts;

        //error_log("MP4Remuxer Audio: firstDts={$firstDts}, lastDts={$lastDts}, samples=" . count($mp4Samples));

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
        $unit = AAC::getSilentFrame($this->_audioMeta['channelCount']);
        if ($unit === null) {
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

            // 使用视频自己的DTS基准
            $originalDts = $avcSample['dts'] - $this->_videoDtsBase;

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
            if ($keyframe && !empty($this->_videoMeta['sps']) && !empty($this->_videoMeta['pps'])) {
                $mdatChunks[] = "\x00\x00\x00\x01" . $this->_videoMeta['sps'];
                $mdatChunks[] = "\x00\x00\x00\x01" . $this->_videoMeta['pps'];
                $sampleSize += 4 + strlen($this->_videoMeta['sps']) + 4 + strlen($this->_videoMeta['pps']);
            }
            foreach ($avcSample['units'] as $unit) {
                $data = $unit['data'];
                $mdatChunks[] = "\x00\x00\x00\x01" . $data;
                $sampleSize += 4 + strlen($data);
            }

            $sampleDuration = 0;
            if (count($samples) >= 1) {
                $nextDts = $samples[0]['dts'] - $this->_videoDtsBase - $dtsCorrection;
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

        if (empty($mp4Samples)) return;

        $latest = $mp4Samples[count($mp4Samples)-1];
        $lastDts = $latest['dts'] + $latest['duration'];
        $lastPts = $latest['pts'] + $latest['duration'];
        $this->_videoNextDts = $lastDts;

        //error_log("MP4Remuxer Video: firstDts={$firstDts}, lastDts={$lastDts}, samples=" . count($mp4Samples));

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