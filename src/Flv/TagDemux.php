<?php

namespace Xiaosongshu\Flv2mp4\Flv;

/**
 * @purpose flv标签解码器
 * @author yanglong
 */
class TagDemux
{
    public $TAG;
    public $_config = [];
    public $_onError = null;
    public $_onMediaInfo = null;
    public $_onTrackMetadata = null;
    public $_onDataAvailable = null;

    public $_dataOffset = 0;
    public $_firstParse = true;
    public $_dispatch = false;

    public $_hasAudio = false;
    public $_hasVideo = false;

    public $_audioInitialMetadataDispatched = false;
    public $_videoInitialMetadataDispatched = false;

    public $_mediaInfo;
    public $_metadata = null;
    public $_audioMetadata = null;
    public $_videoMetadata = null;

    public $_naluLengthSize = 4;
    public $_timestampBase = 0;
    public $_timescale = 1000;
    public $_duration = 0;
    public $_durationOverrided = false;
    public $_referenceFrameRate = [
        'fixed' => true,
        'fps' => 23.976,
        'fps_num' => 23976,
        'fps_den' => 1000
    ];

    public $_videoTrack = [
        'type' => 'video',
        'id' => 1,
        'sequenceNumber' => 0,
        'addcoefficient' => 2,
        'samples' => [],
        'length' => 0
    ];
    public $_audioTrack = [
        'type' => 'audio',
        'id' => 2,
        'sequenceNumber' => 1,
        'addcoefficient' => 2,
        'samples' => [],
        'length' => 0
    ];

    public $_littleEndian;

    public function __construct()
    {
        $this->TAG = 'tagDemux';
        $this->_mediaInfo = new MediaInfo();
        $this->_mediaInfo->hasAudio = $this->_hasAudio;
        $this->_mediaInfo->hasVideo = $this->_hasVideo;
        $this->_littleEndian = $this->_isLittleEndian();
    }

    public function _isLittleEndian()
    {
        $test = pack('S', 256);
        $unpacked = unpack('S', $test);
        return $unpacked[1] === 256;
    }

    public function setHasAudio($s)
    {
        $this->_mediaInfo->hasAudio = $this->_hasAudio = $s;
    }

    public function setHasVideo($s)
    {
        $this->_mediaInfo->hasVideo = $this->_hasVideo = $s;
    }

    public function onMediaInfo(callable $callback)
    {
        $this->_onMediaInfo = $callback;
    }

    public function onError(callable $callback)
    {
        $this->_onError = $callback;
    }

    public function onTrackMetadata(callable $callback)
    {
        $this->_onTrackMetadata = $callback;
    }

    public function onDataAvailable(callable $callback)
    {
        $this->_onDataAvailable = $callback;
    }

    public function parseMetadata($arr)
    {
        $data = FlvDemux::parseMetadata($arr);
        $this->_parseScriptData($data);
        //error_log(print_r($this->_mediaInfo, true) . ' isComplete? ' . ($this->_mediaInfo->isComplete() ? 'yes' : 'no'));
    }

    public function _parseScriptData($obj)
    {
        $scriptData = $obj;
        if (isset($scriptData['onMetaData'])) {
            if ($this->_metadata) {
                //error_log($this->TAG . ' Found another onMetaData tag!');
            }
            $this->_metadata = $scriptData;
            $onMetaData = $this->_metadata['onMetaData'];
            if (isset($onMetaData['hasAudio']) && is_bool($onMetaData['hasAudio'])) {
                $this->_hasAudio = $onMetaData['hasAudio'];
                $this->_mediaInfo->hasAudio = $this->_hasAudio;
            }
            if (isset($onMetaData['hasVideo']) && is_bool($onMetaData['hasVideo'])) {
                $this->_hasVideo = $onMetaData['hasVideo'];
                $this->_mediaInfo->hasVideo = $this->_hasVideo;
            }
            if (isset($onMetaData['audiodatarate']) && is_numeric($onMetaData['audiodatarate'])) {
                $this->_mediaInfo->audioDataRate = $onMetaData['audiodatarate'];
            }
            if (isset($onMetaData['videodatarate']) && is_numeric($onMetaData['videodatarate'])) {
                $this->_mediaInfo->videoDataRate = $onMetaData['videodatarate'];
            }
            if (isset($onMetaData['width']) && is_numeric($onMetaData['width'])) {
                $this->_mediaInfo->width = $onMetaData['width'];
            }
            if (isset($onMetaData['height']) && is_numeric($onMetaData['height'])) {
                $this->_mediaInfo->height = $onMetaData['height'];
            }
            if (isset($onMetaData['duration']) && is_numeric($onMetaData['duration'])) {
                if (!$this->_durationOverrided) {
                    $duration = (int)($onMetaData['duration'] * $this->_timescale);
                    if ($duration < 0) $duration = 0; // 防止负数
                    $this->_duration = $duration;
                    $this->_mediaInfo->duration = $duration;
                }
            } else {
                $this->_mediaInfo->duration = 0;
            }
            if (isset($onMetaData['framerate']) && is_numeric($onMetaData['framerate'])) {
                $fps_num = (int)($onMetaData['framerate'] * 1000);
                if ($fps_num > 0) {
                    $fps = $fps_num / 1000;
                    $this->_referenceFrameRate = ['fixed' => true, 'fps' => $fps, 'fps_num' => $fps_num, 'fps_den' => 1000];
                    $this->_mediaInfo->fps = $fps;
                }
            }
            if (isset($onMetaData['keyframes']) && is_array($onMetaData['keyframes'])) {
                $this->_mediaInfo->hasKeyframesIndex = true;
                $keyframes = $onMetaData['keyframes'];
                $keyframes['times'] = $onMetaData['times'] ?? [];
                $keyframes['filepositions'] = $onMetaData['filepositions'] ?? [];
                $this->_mediaInfo->keyframesIndex = $this->_parseKeyframesIndex($keyframes);
                $onMetaData['keyframes'] = null;
            } else {
                $this->_mediaInfo->hasKeyframesIndex = false;
            }
            $this->_dispatch = false;
            $this->_mediaInfo->metadata = $onMetaData;
            //error_log($this->TAG . ' Parsed onMetaData');
            return $this->_mediaInfo;
        }
        return null;
    }

    public function _parseKeyframesIndex($keyframes)
    {
        $times = [];
        $filepositions = [];
        for ($i = 1; $i < count($keyframes['times']); $i++) {
            $time = $this->_timestampBase + (int)($keyframes['times'][$i] * 1000);
            $times[] = $time;
            $filepositions[] = $keyframes['filepositions'][$i];
        }
        return ['times' => $times, 'filepositions' => $filepositions];
    }

    public function moofTag($tags)
    {
        foreach ($tags as $tag) {
            $this->_dispatch = true;
            $this->parseChunks($tag);
        }
        if ($this->_isInitialMetadataDispatched()) {
            if ($this->_dispatch && (count($this->_audioTrack['samples']) || count($this->_videoTrack['samples']))) {
                if ($this->_onDataAvailable) {
                    call_user_func($this->_onDataAvailable, $this->_audioTrack, $this->_videoTrack);
                    // 清空样本数组，避免重复处理
                    $this->_audioTrack['samples'] = [];
                    $this->_videoTrack['samples'] = [];
                }
            }
        }
    }

    public function parseChunks($flvtag)
    {
        switch ($flvtag->tagType) {
            case 8:
                $this->_parseAudioData($flvtag->body, 0, strlen($flvtag->body), $flvtag->getTime());
                break;
            case 9:
                $this->_parseVideoData($flvtag->body, 0, strlen($flvtag->body), $flvtag->getTime(), 0);
                break;
            case 18:
                $this->parseMetadata($flvtag->body);
                break;
        }
    }

    public function _parseVideoData($arrayBuffer, $dataOffset, $dataSize, $tagTimestamp, $tagPosition)
    {
        if ($tagTimestamp == $this->_timestampBase && $this->_timestampBase != 0) {
            //error_log($tagTimestamp . ' ' . $this->_timestampBase . ' 夭寿啦这个视频不是从0开始');
        }
        if ($dataSize <= 1) {
            //error_log($this->TAG . ' Flv: Invalid video packet, missing VideoData payload!');
            return;
        }
        $spec = ord($arrayBuffer[$dataOffset]);
        $frameType = ($spec & 240) >> 4;
        $codecId = $spec & 15;
        if ($codecId != 7) {
            if ($this->_onError) call_user_func($this->_onError, "Flv: Unsupported codec in video frame: {$codecId}");
            return;
        }
        $this->_parseAVCVideoPacket($arrayBuffer, $dataOffset + 1, $dataSize - 1, $tagTimestamp, $tagPosition, $frameType);
    }

    public function _parseAVCVideoPacket($arrayBuffer, $dataOffset, $dataSize, $tagTimestamp, $tagPosition, $frameType)
    {
        if ($dataSize < 4) {
            //error_log($this->TAG . ' Flv: Invalid AVC packet, missing AVCPacketType or/and CompositionTime');
            return;
        }
        $packetType = ord($arrayBuffer[$dataOffset]);
        $cts = 0;
        for ($i = 0; $i < 3; $i++) {
            $cts = ($cts << 8) | ord($arrayBuffer[$dataOffset + 1 + $i]);
        }
        // CTS是有符号的24位整数，需要处理负数情况
        if ($cts & 0x800000) {
            $cts -= 0x1000000;
        }
        if ($packetType == 0) {
            $this->_parseAVCDecoderConfigurationRecord($arrayBuffer, $dataOffset + 4, $dataSize - 4);
        } elseif ($packetType == 1) {
            $this->_parseAVCVideoData($arrayBuffer, $dataOffset + 4, $dataSize - 4, $tagTimestamp, $tagPosition, $frameType, $cts);
        } elseif ($packetType == 2) {
            // empty
        } else {
            if ($this->_onError) call_user_func($this->_onError, "Flv: Invalid video packet type {$packetType}");
        }
    }

    public function _parseAVCDecoderConfigurationRecord($arrayBuffer, $dataOffset, $dataSize)
    {
        if ($dataSize < 7) {
            //error_log($this->TAG . ' Flv: Invalid AVCDecoderConfigurationRecord, lack of data!');
            return;
        }
        $meta = $this->_videoMetadata;
        $track = $this->_videoTrack;
        if (!$meta) {
            $meta = $this->_videoMetadata = [
                'type' => 'video',
                'id' => $track['id'],
                'timescale' => $this->_timescale,
                'duration' => $this->_duration
            ];
        } else {
            if (isset($meta['avcc'])) {
                //error_log($this->TAG . ' Found another AVCDecoderConfigurationRecord!');
            }
        }
        $version = ord($arrayBuffer[$dataOffset]);
        $avcProfile = ord($arrayBuffer[$dataOffset + 1]);
        $profileCompatibility = ord($arrayBuffer[$dataOffset + 2]);
        $avcLevel = ord($arrayBuffer[$dataOffset + 3]);
        if ($version != 1 || $avcProfile == 0) {
            if ($this->_onError) call_user_func($this->_onError, 'Flv: Invalid AVCDecoderConfigurationRecord');
            return;
        }
        $this->_naluLengthSize = (ord($arrayBuffer[$dataOffset + 4]) & 3) + 1;
        if ($this->_naluLengthSize != 3 && $this->_naluLengthSize != 4) {
            if ($this->_onError) call_user_func($this->_onError, 'Flv: Strange NaluLengthSizeMinusOne: ' . ($this->_naluLengthSize - 1));
            return;
        }
        $spsCount = ord($arrayBuffer[$dataOffset + 5]) & 31;
        if ($spsCount == 0 || $spsCount > 1) {
            if ($this->_onError) call_user_func($this->_onError, "Flv: Invalid H264 SPS count: {$spsCount}");
            return;
        }
        $offset = 6;
        for ($i = 0; $i < $spsCount; $i++) {
            $len = unpack('n', substr($arrayBuffer, $dataOffset + $offset, 2))[1];
            $offset += 2;
            if ($len == 0) continue;
            $sps = substr($arrayBuffer, $dataOffset + $offset, $len);
            $offset += $len;
            $config = SPSParser::parseSPS($sps);
            $meta['codecWidth'] = $config['codec_size']['width'];
            $meta['codecHeight'] = $config['codec_size']['height'];
            $meta['presentWidth'] = $config['present_size']['width'];
            $meta['presentHeight'] = $config['present_size']['height'];
            $meta['profile'] = $config['profile_string'];
            $meta['level'] = $config['level_string'];
            $meta['bitDepth'] = $config['bit_depth'];
            $meta['chromaFormat'] = $config['chroma_format'];
            $meta['sarRatio'] = $config['sar_ratio'];
            $meta['frameRate'] = $config['frame_rate'];
            if ($config['frame_rate']['fixed'] === false || $config['frame_rate']['fps_num'] == 0 || $config['frame_rate']['fps_den'] == 0) {
                $meta['frameRate'] = $this->_referenceFrameRate;
            }
            $fps_den = $meta['frameRate']['fps_den'];
            $fps_num = $meta['frameRate']['fps_num'];
            $meta['refSampleDuration'] = (int)($meta['timescale'] * ($fps_den / $fps_num));
            $codecArray = [ord($sps[1]), ord($sps[2]), ord($sps[3])];
            $codecString = 'avc1.';
            foreach ($codecArray as $h) $codecString .= str_pad(dechex($h), 2, '0', STR_PAD_LEFT);
            $meta['codec'] = $codecString;
            $mi = $this->_mediaInfo;
            $mi->width = $meta['codecWidth'];
            $mi->height = $meta['codecHeight'];
            $mi->fps = $meta['frameRate']['fps'];
            $mi->profile = $meta['profile'];
            $mi->level = $meta['level'];
            $mi->chromaFormat = $config['chroma_format_string'] ?? '';
            $mi->sarNum = $meta['sarRatio']['width'];
            $mi->sarDen = $meta['sarRatio']['height'];
            $mi->videoCodec = $codecString;
            if ($mi->hasAudio) {
                if ($mi->audioCodec != null) $mi->mimeType = 'video/x-flv; codecs="' . $mi->videoCodec . ',' . $mi->audioCodec . '"';
            } else {
                $mi->mimeType = 'video/x-flv; codecs="' . $mi->videoCodec . '"';
            }
            if ($mi->isComplete() && $this->_onMediaInfo) call_user_func($this->_onMediaInfo, $mi);
        }
        $ppsCount = ord($arrayBuffer[$dataOffset + $offset]);
        if ($ppsCount == 0 || $ppsCount > 1) {
            if ($this->_onError) call_user_func($this->_onError, "Flv: Invalid H264 PPS count: {$ppsCount}");
            return;
        }
        $offset++;
        $ppsData = '';
        for ($i = 0; $i < $ppsCount; $i++) {
            $len = unpack('n', substr($arrayBuffer, $dataOffset + $offset, 2))[1];
            $offset += 2;
            if ($len == 0) continue;
            $ppsData = substr($arrayBuffer, $dataOffset + $offset, $len);
            $offset += $len;
        }
        $meta['avcc'] = substr($arrayBuffer, $dataOffset, $dataSize);
        // 保存SPS和PPS数据，用于在关键帧前面添加
        $meta['sps'] = $sps ?? '';
        $meta['pps'] = $ppsData;
        //error_log($this->TAG . ' Parsed AVCDecoderConfigurationRecord');
        if ($this->_isInitialMetadataDispatched()) {
            if ($this->_dispatch && (count($this->_audioTrack['samples']) || count($this->_videoTrack['samples']))) {
                if ($this->_onDataAvailable) call_user_func($this->_onDataAvailable, $this->_audioTrack, $this->_videoTrack);
            }
        } else {
            $this->_videoInitialMetadataDispatched = true;
        }
        $this->_dispatch = false;
        if ($this->_onTrackMetadata) call_user_func($this->_onTrackMetadata, 'video', $meta);
        $this->_videoMetadata = $meta;
    }

    public function timestampBase($i)
    {
        $this->_timestampBase = $i;
    }

    public function _parseAVCVideoData($arrayBuffer, $dataOffset, $dataSize, $tagTimestamp, $tagPosition, $frameType, $cts)
    {
        $units = [];
        $length = 0;
        $offset = 0;
        $lengthSize = $this->_naluLengthSize;
        $dts = $this->_timestampBase + $tagTimestamp;
        $keyframe = ($frameType == 1);
        while ($offset < $dataSize) {
            if ($offset + $lengthSize > $dataSize) {
                //error_log($this->TAG . " Malformed Nalu near timestamp {$dts}, offset = {$offset}, dataSize = {$dataSize}");
                break;
            }
            $naluSize = 0;
            for ($i = 0; $i < $lengthSize; $i++) {
                $naluSize = ($naluSize << 8) | ord($arrayBuffer[$dataOffset + $offset + $i]);
            }
            if ($lengthSize == 3) $naluSize >>= 8;
            if ($naluSize > $dataSize - $lengthSize) {
                //error_log($this->TAG . " Malformed Nalus near timestamp {$dts}, NaluSize > DataSize!");
                return;
            }
            $unitType = (ord($arrayBuffer[$dataOffset + $offset + $lengthSize]) & 0x1F);
            if ($unitType == 5) $keyframe = true;
            // NALU数据不包含长度前缀，只获取实际的NALU内容
            $data = substr($arrayBuffer, $dataOffset + $offset + $lengthSize, $naluSize);
            $units[] = ['type' => $unitType, 'data' => $data];
            $length += strlen($data);
            $offset += $lengthSize + $naluSize;
        }
        if (count($units)) {
            $track = &$this->_videoTrack;
            $avcSample = [
                'units' => $units,
                'length' => $length,
                'isKeyframe' => $keyframe,
                'dts' => $dts,
                'cts' => $cts,
                'pts' => $dts + $cts
            ];
            if ($keyframe) $avcSample['fileposition'] = $tagPosition;
            $track['samples'][] = $avcSample;
            $track['length'] += $length;
        }
    }

    public function _parseAudioData($arrayBuffer, $dataOffset, $dataSize, $tagTimestamp)
    {
        if ($tagTimestamp == $this->_timestampBase && $this->_timestampBase != 0) {
            //error_log($tagTimestamp . ' ' . $this->_timestampBase . ' 这个视频不是从0开始');
        }
        if ($dataSize <= 1) {
            //error_log($this->TAG . ' Flv: Invalid audio packet, missing SoundData payload!');
            return;
        }
        $meta = $this->_audioMetadata;
        $track = &$this->_audioTrack;
        if (!$meta || !isset($meta['codec'])) {
            $meta = $this->_audioMetadata = [
                'type' => 'audio',
                'id' => $track['id'],
                'timescale' => $this->_timescale,
                'duration' => $this->_duration
            ];
            $soundSpec = ord($arrayBuffer[$dataOffset]);
            $soundFormat = $soundSpec >> 4;
            if ($soundFormat != 10) {
                if ($this->_onError) call_user_func($this->_onError, 'Flv: Unsupported audio codec idx: ' . $soundFormat);
                return;
            }
            $soundRateIndex = ($soundSpec & 12) >> 2;
            $soundRateTable = [5500, 11025, 22050, 44100, 48000];
            $soundRate = $soundRateTable[$soundRateIndex] ?? 0;
            if ($soundRate == 0) {
                if ($this->_onError) call_user_func($this->_onError, 'Flv: Invalid audio sample rate idx: ' . $soundRateIndex);
                return;
            }
            $soundType = ($soundSpec & 1);
            $meta['audioSampleRate'] = $soundRate;
            $meta['channelCount'] = ($soundType == 0 ? 1 : 2);
            $meta['refSampleDuration'] = (int)(1024 / $meta['audioSampleRate'] * $meta['timescale']);
            $meta['codec'] = 'mp4a.40.5';
        }
        $aacData = $this->_parseAACAudioData($arrayBuffer, $dataOffset + 1, $dataSize - 1);
        if ($aacData == null) return;
        if ($aacData['packetType'] == 0) {
            if (isset($meta['config'])) error_log($this->TAG . ' Found another AudioSpecificConfig!');
            $misc = $aacData['data'];
            $meta['audioSampleRate'] = $misc['samplingRate'];
            $meta['channelCount'] = $misc['channelCount'];
            $meta['codec'] = $misc['codec'];
            $meta['config'] = $misc['config'];
            $samplePerFrame = ($misc['originalAudioObjectType'] == 5 || $misc['originalAudioObjectType'] == 29) ? 2048 : 1024;
            $meta['refSampleDuration'] = (int)($samplePerFrame / $meta['audioSampleRate'] * $meta['timescale']);
            //error_log($this->TAG . ' Parsed AudioSpecificConfig');
            if ($this->_isInitialMetadataDispatched()) {
                if ($this->_dispatch && (count($this->_audioTrack['samples']) || count($this->_videoTrack['samples']))) {
                    if ($this->_onDataAvailable) call_user_func($this->_onDataAvailable, $this->_audioTrack, $this->_videoTrack);
                }
            } else {
                $this->_audioInitialMetadataDispatched = true;
            }
            $this->_dispatch = false;
            if ($this->_onTrackMetadata) call_user_func($this->_onTrackMetadata, 'audio', $meta);
            $mi = $this->_mediaInfo;
            $mi->audioCodec = 'mp4a.40.' . $misc['originalAudioObjectType'];
            $mi->audioSampleRate = $meta['audioSampleRate'];
            $mi->audioChannelCount = $meta['channelCount'];
            if ($mi->hasVideo) {
                if ($mi->videoCodec != null) $mi->mimeType = 'video/x-flv; codecs="' . $mi->videoCodec . ',' . $mi->audioCodec . '"';
            } else {
                $mi->mimeType = 'video/x-flv; codecs="' . $mi->audioCodec . '"';
            }
            if ($mi->isComplete() && $this->_onMediaInfo) call_user_func($this->_onMediaInfo, $mi);
            return;
        } elseif ($aacData['packetType'] == 1) {
            $dts = $this->_timestampBase + $tagTimestamp;
            $aacSample = ['unit' => $aacData['data'], 'dts' => $dts, 'pts' => $dts];
            $track['samples'][] = $aacSample;
            $track['length'] += strlen($aacData['data']);
        } else {
            //error_log($this->TAG . " Flv: Unsupported AAC data type {$aacData['packetType']}");
        }
    }

    public function _parseAACAudioData($arrayBuffer, $dataOffset, $dataSize)
    {
        if ($dataSize <= 1) {
            //error_log($this->TAG . ' Flv: Invalid AAC packet, missing AACPacketType or/and Data!');
            return null;
        }
        $packetType = ord($arrayBuffer[$dataOffset]);
        $result = ['packetType' => $packetType];
        if ($packetType == 0) {
            $result['data'] = $this->_parseAACAudioSpecificConfig($arrayBuffer, $dataOffset + 1, $dataSize - 1);
        } else {
            $result['data'] = substr($arrayBuffer, $dataOffset + 1, $dataSize - 1);
        }
        return $result;
    }

    public function _parseAACAudioSpecificConfig($arrayBuffer, $dataOffset, $dataSize)
    {
        $data = substr($arrayBuffer, $dataOffset, $dataSize);
        if (strlen($data) < 2) return null;
        $mpegSamplingRates = [96000,88200,64000,48000,44100,32000,24000,22050,16000,12000,11025,8000,7350];
        $byte0 = ord($data[0]);
        $byte1 = ord($data[1]);
        $audioObjectType = $originalAudioObjectType = $byte0 >> 3;
        $samplingIndex = (($byte0 & 0x07) << 1) | ($byte1 >> 7);
        if ($samplingIndex < 0 || $samplingIndex >= count($mpegSamplingRates)) {
            if ($this->_onError) call_user_func($this->_onError, 'Flv: AAC invalid sampling frequency index!');
            return null;
        }
        $samplingFrequence = $mpegSamplingRates[$samplingIndex];
        $channelConfig = ($byte1 & 0x78) >> 3;
        if ($channelConfig < 0 || $channelConfig >= 8) {
            if ($this->_onError) call_user_func($this->_onError, 'Flv: AAC invalid channel configuration');
            return null;
        }
        $extensionSamplingIndex = null;
        $audioExtensionObjectType = null;
        if ($audioObjectType == 5 || $audioObjectType == 29) {
            $byte2 = isset($data[2]) ? ord($data[2]) : 0;
            $extensionSamplingIndex = (($byte1 & 0x07) << 1) | ($byte2 >> 7);
            $audioExtensionObjectType = ($byte2 & 0x7C) >> 2;
        }
        $outputSampleRate = $samplingFrequence;
        if ($audioObjectType == 5 || $audioObjectType == 29) {
            $extensionSampleRate = $mpegSamplingRates[$extensionSamplingIndex] ?? 0;
            if ($extensionSamplingIndex != $samplingIndex || $extensionSampleRate != $samplingFrequence) {
                $outputSampleRate = $extensionSampleRate;
            } else {
                $outputSampleRate = $samplingFrequence * 2;
            }
        }

        $outputChannelCount = $channelConfig;
        if ($audioObjectType == 29 && $channelConfig == 1) {
            $outputChannelCount = 2;
        }

        return [
            'config' => $data,
            'samplingRate' => $outputSampleRate,
            'channelCount' => $outputChannelCount,
            'codec' => 'mp4a.40.' . $audioObjectType,
            'originalAudioObjectType' => $originalAudioObjectType
        ];
    }

    public function _isInitialMetadataDispatched()
    {
        if ($this->_hasAudio && $this->_hasVideo) {
            return $this->_audioInitialMetadataDispatched && $this->_videoInitialMetadataDispatched;
        }
        if ($this->_hasAudio && !$this->_hasVideo) return $this->_audioInitialMetadataDispatched;
        if (!$this->_hasAudio && $this->_hasVideo) return $this->_videoInitialMetadataDispatched;
        return false;
    }
}