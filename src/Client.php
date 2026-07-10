<?php

namespace Xiaosongshu\Flv2mp4;

use Xiaosongshu\Flv2mp4\Manage\Flv2Fmp4;
use Xiaosongshu\Flv2mp4\Manage\Flv2Hls;
use Xiaosongshu\Flv2mp4\Manage\Hls2Flv;
use Xiaosongshu\Flv2mp4\Manage\Mp4ToFlv;
use Xiaosongshu\Flv2mp4\Manage\FlvToMp4;
use Xiaosongshu\Flv2mp4\Manage\Fmp42Flv;

/**
 * @purpose flv文件转码mp4客户端
 * @author xiaosongshu
 * @time 2026年5月29日14:14:00
 */
class Client
{

    /**
     * 静态flv转fMP4入口函数，音视频混合切片，并生成合并的mp4文件
     * @param string $inputFile 需要转换的flv文件
     * @param string $outputDir 存储mp4的目录
     * @param int $targetSegmentDuration 目标切片时长（毫秒，默认4000）
     * @param int $maxSegmentDuration 最大切片时长/安全阀（毫秒，默认8000）
     * @return string|void
     */
    public static function runFlv2Fmp4Mixed(string $inputFile, string $outputDir, int $targetSegmentDuration = 4000, int $maxSegmentDuration = 8000)
    {
        if (!file_exists($inputFile)) {
            throw new \RuntimeException("flv not exist!");
        }
        if (!self::isFlvFile($inputFile)) {
            throw new \RuntimeException("only support flv file!");
        }
        if (!is_dir($outputDir)) mkdir($outputDir, 0777, true);
        array_map('unlink', glob("$outputDir/*"));
        $flvBinary = file_get_contents($inputFile);

        $flv2fmp4 = new Flv2Fmp4();
        $initSegment = null;
        $segments = [];
        $segmentIndex = 0;

        /** 切片缓冲区 */
        $buffer = [];
        $index = 1;

        $flv2fmp4->onInitSegment = function ($data) use (&$initSegment, $outputDir, $flv2fmp4) {
            echo "\n[回调] 初始化段生成\n";
            echo "大小: " . strlen($data) . " bytes\n";
            $initSegment = $data;
            file_put_contents("$outputDir/init.mp4", $data);
            echo "已写入: $outputDir/init.mp4\n";

            // 生成 meta.json
            $meta = [];
            foreach ($flv2fmp4->metas as $trackMeta) {
                if (isset($trackMeta['codec'])) {
                    if ($trackMeta['type'] == 'video') {
                        $meta['videoCodec'] = $trackMeta['codec'];
                    } else if ($trackMeta['type'] == 'audio') {
                        $meta['audioCodec'] = $trackMeta['codec'];
                    }
                }
            }
            if (!empty($meta)) {
                file_put_contents("$outputDir/meta.json", json_encode($meta));
                echo "已写入: $outputDir/meta.json\n";
                echo "编解码器信息: " . json_encode($meta) . "\n";
            }
        };

        $segmentIndex = 0;
        $buffer = [];
        $index = 1;
        $segmentStartDts = -1;
        $segmentFirstVideoDts = -1;
        $segmentLastVideoDts = -1;
        $readyToCut = false;
        $allSegments = [];

        $flv2fmp4->onMediaSegment = function ($data, $value) use (&$segments, &$segmentIndex, $outputDir, &$buffer, &$index, $targetSegmentDuration, $maxSegmentDuration, &$segmentStartDts, &$segmentFirstVideoDts, &$segmentLastVideoDts, &$readyToCut, &$allSegments) {
            $info = $value['info'] ?? null;
            $track = $value['track'] ?? '';
            $isKeyframe = isset($value['isKeyframe']) ? $value['isKeyframe'] : false;

            $buffer[] = $data;
            $segments[] = $data;

            $shouldFlush = false;

            if ($info) {
                $currentEndDts = $info->originalEndDts ?? $info->endDts ?? 0;

                if ($segmentStartDts < 0) {
                    $segmentStartDts = $info->originalBeginDts ?? $info->beginDts ?? 0;
                }

                if ($track == 'video') {
                    if ($segmentFirstVideoDts < 0) {
                        $segmentFirstVideoDts = $info->originalBeginDts ?? $info->beginDts ?? 0;
                    }
                    $segmentLastVideoDts = $info->originalEndDts ?? $info->endDts ?? 0;

                    if ($readyToCut && $isKeyframe) {
                        $shouldFlush = true;
                        $readyToCut = false;
                    } else {
                        $duration = $currentEndDts - $segmentStartDts;
                        if ($duration >= $maxSegmentDuration) {
                            $shouldFlush = true;
                            $readyToCut = false;
                        } elseif ($duration >= $targetSegmentDuration) {
                            $readyToCut = true;
                        }
                    }
                } else {
                    $duration = $currentEndDts - $segmentStartDts;
                    if ($duration >= $maxSegmentDuration) {
                        $readyToCut = true;
                    } elseif ($duration >= $targetSegmentDuration) {
                        $readyToCut = true;
                    }
                }
            }

            if ($shouldFlush && !empty($buffer)) {
                $segmentData = implode("", $buffer);
                $segmentDuration = 0;
                if ($segmentFirstVideoDts >= 0 && $segmentLastVideoDts >= 0) {
                    $segmentDuration = $segmentLastVideoDts - $segmentFirstVideoDts;
                }

                file_put_contents("$outputDir/segment_$index.m4s", $segmentData);
                echo "已写入: $outputDir/segment_$index.m4s (大小: " . strlen($segmentData) . " bytes, 时长: " . ($segmentDuration / 1000) . "s)\n";

                $allSegments[] = ['path' => "$outputDir/segment_$index.m4s", 'duration' => $segmentDuration];
                $buffer = [];
                $index++;

                $segmentStartDts = -1;
                $segmentFirstVideoDts = -1;
                $segmentLastVideoDts = -1;
            }

            $segmentIndex++;
            echo "\n[回调] 媒体段#$segmentIndex\n";
            echo "大小: " . strlen($data) . " bytes\n";
        };

        $flv2fmp4->onMediaInfo = function ($mediaInfo, $tracks) {
            echo "\n[回调] 媒体信息:\n";
            echo "  宽度: " . ($mediaInfo->width ?? 'N/A') . "\n";
            echo "  高度: " . ($mediaInfo->height ?? 'N/A') . "\n";
            echo "  帧率: " . ($mediaInfo->fps ?? 'N/A') . "\n";
            echo "  时长: " . ($mediaInfo->duration ?? 0) . "\n";
            echo "  音频: " . ($tracks['hasAudio'] ? '是' : '否') . "\n";
            echo "  视频: " . ($tracks['hasVideo'] ? '是' : '否') . "\n";
        };

        try {
            $flv2fmp4->setflv($flvBinary, 0);
        } catch (\Exception $e) {
            throw new \RuntimeException("error:" . $e->getMessage());
        }

        // 写入剩余的缓冲区内容
        $finalSegmentCount = $index;
        if (!empty($buffer)) {
            $segmentData = implode("", $buffer);
            $segmentDuration = 0;
            if ($segmentFirstVideoDts >= 0 && $segmentLastVideoDts >= 0) {
                $segmentDuration = $segmentLastVideoDts - $segmentFirstVideoDts;
            }
            file_put_contents("$outputDir/segment_$index.m4s", $segmentData);
            echo "\n已写入剩余切片: $outputDir/segment_$index.m4s (大小: " . strlen($segmentData) . " bytes, 时长: " . ($segmentDuration / 1000) . "s)\n";
            $allSegments[] = ['path' => "$outputDir/segment_$index.m4s", 'duration' => $segmentDuration];
            $finalSegmentCount = $index;
        }

        if ($initSegment && !empty($segments)) {
            // 计算实际的 duration 并更新初始化片段
            $actualDuration = self::calculateDurationFromSegments($segments, $initSegment);
            if ($actualDuration > 0) {
                $initSegment = self::updateInitSegmentDuration($initSegment, $actualDuration);
            }

            $fullBinary = $initSegment . implode('', $segments);
            $mp4Name = date("Y_m_d_H_i_s") . "_" . uniqid() . '.mp4';
            file_put_contents("$outputDir/$mp4Name", $fullBinary);

            // 生成 m3u8 索引文件（使用实际切片时长）
            $m3u8Path = self::generateMixedM3u8WithDurations($outputDir, $allSegments, $actualDuration);
            echo "\n已写入 m3u8 索引文件: $m3u8Path\n";

            return "$outputDir/$mp4Name";
        } else {
            return "";
        }
    }


    /**
     * flv转fMP4生成分开的音视频切片（用于浏览器播放）
     * @param string $inputFile 需要转换的flv文件
     * @param string $outputDir 存储切片的目录
     * @param int $targetSegmentDuration 目标切片时长（毫秒，默认4000）
     * @param int $maxSegmentDuration 最大切片时长/安全阀（毫秒，默认8000）
     * @return array 返回生成的文件信息
     */
    public static function runFlv2Fmp4Separate(string $inputFile, string $outputDir, int $targetSegmentDuration = 4000, int $maxSegmentDuration = 8000)
    {
        if (!file_exists($inputFile)) {
            throw new \RuntimeException("flv not exist!");
        }
        if (!self::isFlvFile($inputFile)) {
            throw new \RuntimeException("only support flv file!");
        }
        if (!is_dir($outputDir)) mkdir($outputDir, 0777, true);

        // 清空输出目录
        foreach (glob("$outputDir/*") as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $flvBinary = file_get_contents($inputFile);
        $flv2fmp4 = new Flv2Fmp4();

        $audioSegmentIndex = 0;
        $videoSegmentIndex = 0;
        $outputFiles = [
            'audioInit' => null,
            'videoInit' => null,
            'audioSegments' => [],
            'videoSegments' => [],
            'audioSegmentDurations' => [],
            'videoSegmentDurations' => [],
            'meta' => null
        ];

        // 音频缓冲区和视频缓冲区
        $audioBuffer = [];
        $videoBuffer = [];

        // 时间跟踪变量
        $audioSegmentStartDts = -1;
        $audioSegmentFirstDts = -1;
        $audioSegmentLastDts = -1;
        $videoSegmentStartDts = -1;
        $videoSegmentFirstDts = -1;
        $videoSegmentLastDts = -1;
        $videoReadyToCut = false;

        // 音频初始化片段回调
        $flv2fmp4->onAudioInitSegment = function ($data, $meta) use ($outputDir, &$outputFiles) {
            echo "\n[回调] 音频初始化段生成\n";
            echo "大小: " . strlen($data) . " bytes\n";
            $outputFiles['audioInit'] = "$outputDir/audio_init.mp4";
            file_put_contents($outputFiles['audioInit'], $data);
            echo "已写入: " . $outputFiles['audioInit'] . "\n";
        };

        // 视频初始化片段回调
        $flv2fmp4->onVideoInitSegment = function ($data, $meta) use ($outputDir, &$outputFiles) {
            echo "\n[回调] 视频初始化段生成\n";
            echo "大小: " . strlen($data) . " bytes\n";
            $outputFiles['videoInit'] = "$outputDir/video_init.mp4";
            file_put_contents($outputFiles['videoInit'], $data);
            echo "已写入: " . $outputFiles['videoInit'] . "\n";
        };

        // 音频切片回调
        $flv2fmp4->onAudioSegment = function ($data, $value) use ($outputDir, &$audioSegmentIndex, &$outputFiles, &$audioBuffer, $targetSegmentDuration, $maxSegmentDuration, &$audioSegmentStartDts, &$audioSegmentFirstDts, &$audioSegmentLastDts) {
            $audioSegmentIndex++;
            $info = $value['info'] ?? null;

            // 将数据添加到缓冲区
            $audioBuffer[] = $data;

            $shouldFlush = false;
            if ($info) {
                $currentEndDts = $info->originalEndDts ?? $info->endDts ?? 0;

                if ($audioSegmentStartDts < 0) {
                    $audioSegmentStartDts = $info->originalBeginDts ?? $info->beginDts ?? 0;
                }

                if ($audioSegmentFirstDts < 0) {
                    $audioSegmentFirstDts = $info->originalBeginDts ?? $info->beginDts ?? 0;
                }
                $audioSegmentLastDts = $info->originalEndDts ?? $info->endDts ?? 0;

                $duration = $currentEndDts - $audioSegmentStartDts;
                if ($duration >= $targetSegmentDuration) {
                    $shouldFlush = true;
                }
            }

            // 当达到目标时长时，写入切片文件
            if ($shouldFlush && !empty($audioBuffer)) {
                $segmentData = implode("", $audioBuffer);
                $segmentDuration = 0;
                if ($audioSegmentFirstDts >= 0 && $audioSegmentLastDts >= 0) {
                    $segmentDuration = $audioSegmentLastDts - $audioSegmentFirstDts;
                }

                $audioBufferIndex = count($outputFiles['audioSegments']) + 1;
                $filename = "$outputDir/audio_$audioBufferIndex.m4s";
                file_put_contents($filename, $segmentData);
                $outputFiles['audioSegments'][] = $filename;
                $outputFiles['audioSegmentDurations'][] = $segmentDuration;
                echo "\n已写入: $filename (大小: " . strlen($segmentData) . " bytes, 时长: " . ($segmentDuration / 1000) . "s)\n";

                $audioBuffer = [];
                $audioSegmentStartDts = -1;
                $audioSegmentFirstDts = -1;
                $audioSegmentLastDts = -1;
            }

            echo "\n[回调] 音频段#$audioSegmentIndex\n";
            echo "大小: " . strlen($data) . " bytes\n";
        };

        // 视频切片回调
        $flv2fmp4->onVideoSegment = function ($data, $value) use ($outputDir, &$videoSegmentIndex, &$outputFiles, &$videoBuffer, $targetSegmentDuration, $maxSegmentDuration, &$videoSegmentStartDts, &$videoSegmentFirstDts, &$videoSegmentLastDts, &$videoReadyToCut) {
            $videoSegmentIndex++;
            $info = $value['info'] ?? null;
            $isKeyframe = isset($value['isKeyframe']) ? $value['isKeyframe'] : false;

            // 将数据添加到缓冲区
            $videoBuffer[] = $data;

            $shouldFlush = false;
            if ($info) {
                $currentEndDts = $info->originalEndDts ?? $info->endDts ?? 0;

                if ($videoSegmentStartDts < 0) {
                    $videoSegmentStartDts = $info->originalBeginDts ?? $info->beginDts ?? 0;
                }

                if ($videoSegmentFirstDts < 0) {
                    $videoSegmentFirstDts = $info->originalBeginDts ?? $info->beginDts ?? 0;
                }
                $videoSegmentLastDts = $info->originalEndDts ?? $info->endDts ?? 0;

                if ($videoReadyToCut && $isKeyframe) {
                    $shouldFlush = true;
                    $videoReadyToCut = false;
                } else {
                    $duration = $currentEndDts - $videoSegmentStartDts;
                    if ($duration >= $maxSegmentDuration) {
                        $shouldFlush = true;
                        $videoReadyToCut = false;
                    } elseif ($duration >= $targetSegmentDuration) {
                        $videoReadyToCut = true;
                    }
                }
            }

            // 当达到目标时长且遇到关键帧时，写入切片文件
            if ($shouldFlush && !empty($videoBuffer)) {
                $segmentData = implode("", $videoBuffer);
                $segmentDuration = 0;
                if ($videoSegmentFirstDts >= 0 && $videoSegmentLastDts >= 0) {
                    $segmentDuration = $videoSegmentLastDts - $videoSegmentFirstDts;
                }

                $videoBufferIndex = count($outputFiles['videoSegments']) + 1;
                $filename = "$outputDir/video_$videoBufferIndex.m4s";
                file_put_contents($filename, $segmentData);
                $outputFiles['videoSegments'][] = $filename;
                $outputFiles['videoSegmentDurations'][] = $segmentDuration;
                echo "\n已写入: $filename (大小: " . strlen($segmentData) . " bytes, 时长: " . ($segmentDuration / 1000) . "s)\n";

                $videoBuffer = [];
                $videoSegmentStartDts = -1;
                $videoSegmentFirstDts = -1;
                $videoSegmentLastDts = -1;
            }

            echo "\n[回调] 视频段#$videoSegmentIndex\n";
            echo "大小: " . strlen($data) . " bytes\n";
        };

        // 媒体信息回调
        $flv2fmp4->onMediaInfo = function ($mediaInfo, $tracks) use ($outputDir, &$outputFiles, $flv2fmp4) {
            echo "\n[回调] 媒体信息:\n";
            echo "  宽度: " . ($mediaInfo->width ?? 'N/A') . "\n";
            echo "  高度: " . ($mediaInfo->height ?? 'N/A') . "\n";
            echo "  帧率: " . ($mediaInfo->fps ?? 'N/A') . "\n";
            echo "  时长: " . ($mediaInfo->duration ?? 0) . "\n";
            echo "  音频: " . ($tracks['hasAudio'] ? '是' : '否') . "\n";
            echo "  视频: " . ($tracks['hasVideo'] ? '是' : '否') . "\n";

            // 生成 meta.json
            $meta = [];
            foreach ($flv2fmp4->metas as $trackMeta) {
                if (isset($trackMeta['codec'])) {
                    if ($trackMeta['type'] == 'video') {
                        $meta['videoCodec'] = $trackMeta['codec'];
                    } else if ($trackMeta['type'] == 'audio') {
                        $meta['audioCodec'] = $trackMeta['codec'];
                    }
                }
            }
            $meta['hasAudio'] = $tracks['hasAudio'];
            $meta['hasVideo'] = $tracks['hasVideo'];
            $meta['width'] = $mediaInfo->width ?? 0;
            $meta['height'] = $mediaInfo->height ?? 0;
            $meta['duration'] = $mediaInfo->duration ?? 0;
            $meta['fps'] = $mediaInfo->fps ?? 0;

            $outputFiles['meta'] = "$outputDir/meta.json";
            file_put_contents($outputFiles['meta'], json_encode($meta));
            echo "已写入: " . $outputFiles['meta'] . "\n";
            echo "编解码器信息: " . json_encode($meta) . "\n";
        };

        try {
            $flv2fmp4->setflv($flvBinary, 0);
        } catch (\Exception $e) {
            throw new \RuntimeException("error:" . $e->getMessage());
        }

        // 写入剩余的音频缓冲区内容
        if (!empty($audioBuffer)) {
            $segmentData = implode("", $audioBuffer);
            $segmentDuration = 0;
            if ($audioSegmentFirstDts >= 0 && $audioSegmentLastDts >= 0) {
                $segmentDuration = $audioSegmentLastDts - $audioSegmentFirstDts;
            }

            $audioBufferIndex = count($outputFiles['audioSegments']) + 1;
            $filename = "$outputDir/audio_$audioBufferIndex.m4s";
            file_put_contents($filename, $segmentData);
            $outputFiles['audioSegments'][] = $filename;
            $outputFiles['audioSegmentDurations'][] = $segmentDuration;
            echo "\n已写入剩余音频切片: $filename (大小: " . strlen($segmentData) . " bytes, 时长: " . ($segmentDuration / 1000) . "s)\n";
        }

        // 写入剩余的视频缓冲区内容
        if (!empty($videoBuffer)) {
            $segmentData = implode("", $videoBuffer);
            $segmentDuration = 0;
            if ($videoSegmentFirstDts >= 0 && $videoSegmentLastDts >= 0) {
                $segmentDuration = $videoSegmentLastDts - $videoSegmentFirstDts;
            }

            $videoBufferIndex = count($outputFiles['videoSegments']) + 1;
            $filename = "$outputDir/video_$videoBufferIndex.m4s";
            file_put_contents($filename, $segmentData);
            $outputFiles['videoSegments'][] = $filename;
            $outputFiles['videoSegmentDurations'][] = $segmentDuration;
            echo "\n已写入剩余视频切片: $filename (大小: " . strlen($segmentData) . " bytes, 时长: " . ($segmentDuration / 1000) . "s)\n";
        }

        // 获取媒体元数据中的总时长
        $totalDuration = 0;
        $hasAudio = !empty($outputFiles['audioInit']);
        $hasVideo = !empty($outputFiles['videoInit']);

        // 从meta.json中读取时长信息
        if (file_exists($outputFiles['meta'])) {
            $metaContent = file_get_contents($outputFiles['meta']);
            $meta = json_decode($metaContent, true);
            if (isset($meta['duration'])) {
                $totalDuration = (int)$meta['duration'];
            }
        }

        // 生成音视频子m3u8索引文件（使用实际切片时长）
        $audioSegmentCount = count($outputFiles['audioSegments']);
        $videoSegmentCount = count($outputFiles['videoSegments']);

        if ($hasAudio) {
            $audioM3u8 = self::generateAudioM3u8WithDurations($outputDir, $outputFiles['audioSegmentDurations'], $totalDuration);
            $outputFiles['audioM3u8'] = $audioM3u8;
            echo "\n已写入音频 m3u8 索引文件: $audioM3u8\n";
        }

        if ($hasVideo) {
            $videoM3u8 = self::generateVideoM3u8WithDurations($outputDir, $outputFiles['videoSegmentDurations'], $totalDuration);
            $outputFiles['videoM3u8'] = $videoM3u8;
            echo "\n已写入视频 m3u8 索引文件: $videoM3u8\n";
        }

        // 生成主m3u8索引文件（引用音视频子索引）
        $masterM3u8 = self::generateMasterM3u8($outputDir, $hasAudio, $hasVideo);
        $outputFiles['masterM3u8'] = $masterM3u8;
        echo "\n已写入主 m3u8 索引文件: $masterM3u8\n";

        return $outputFiles;
    }

    /**
     * 判断文件是否是flv文件
     * @param string $filename 静态flv文件
     * @return bool
     */
    protected static function isFlvFile(string $filename)
    {
        return strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'flv';
    }

    /**
     * 生成分离模式的主m3u8索引文件（引用音视频子索引）
     * @param string $outputDir 输出目录
     * @param bool $hasAudio 是否有音频
     * @param bool $hasVideo 是否有视频
     * @return string m3u8文件路径
     */
    protected static function generateMasterM3u8(string $outputDir, bool $hasAudio, bool $hasVideo): string
    {
        $lines = [];
        $lines[] = "#EXTM3U";
        $lines[] = "#EXT-X-VERSION:7";

        $audioId = 1;
        $videoId = 1;

        if ($hasAudio) {
            $lines[] = "#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID=\"audio\",NAME=\"Audio\",DEFAULT=YES,AUTOSELECT=YES,URI=\"audio.m3u8\"";
            $audioId = "audio";
        }

        if ($hasVideo) {
            $lines[] = "#EXT-X-STREAM-INF:BANDWIDTH=2000000,AUDIO=\"$audioId\"";
            $lines[] = "video.m3u8";
        } elseif ($hasAudio) {
            $lines[] = "#EXT-X-STREAM-INF:BANDWIDTH=128000,AUDIO=\"$audioId\"";
            $lines[] = "audio.m3u8";
        }

        $m3u8Content = implode("\n", $lines) . "\n";
        $m3u8Path = "$outputDir/index.m3u8";
        file_put_contents($m3u8Path, $m3u8Content);

        return $m3u8Path;
    }

    /**
     * 生成混合模式的fMP4 m3u8索引文件（使用实际切片时长）
     * @param string $outputDir 输出目录
     * @param array $segments 切片数组（包含path和duration字段）
     * @param int $totalDuration 总时长（毫秒）
     * @return string m3u8文件路径
     */
    protected static function generateMixedM3u8WithDurations(string $outputDir, array $segments, int $totalDuration = 0): string
    {
        $lines = [];
        $lines[] = "#EXTM3U";
        $lines[] = "#EXT-X-VERSION:7";
        $lines[] = "#EXT-X-MEDIA-SEQUENCE:1";
        $lines[] = "#EXT-X-INDEPENDENT-SEGMENTS";
        $lines[] = "#EXT-X-MAP:URI=\"init.mp4\"";

        $maxDuration = 0;
        foreach ($segments as $i => $segment) {
            $duration = max(0.001, round($segment['duration'] / 1000, 3));
            $maxDuration = max($maxDuration, $duration);
            $lines[] = "#EXTINF:" . $duration . ",";
            $lines[] = "segment_" . ($i + 1) . ".m4s";
        }

        $lines[] = "#EXT-X-TARGETDURATION:" . (int)ceil($maxDuration);
        $lines[] = "#EXT-X-ENDLIST";

        $m3u8Content = implode("\n", $lines) . "\n";
        $m3u8Path = "$outputDir/index.m3u8";
        file_put_contents($m3u8Path, $m3u8Content);

        return $m3u8Path;
    }

    /**
     * 生成分离模式的音频m3u8索引文件（使用实际切片时长）
     * @param string $outputDir 输出目录
     * @param array $durations 每个切片的时长数组（毫秒）
     * @param int $totalDuration 总时长（毫秒）
     * @return string m3u8文件路径
     */
    protected static function generateAudioM3u8WithDurations(string $outputDir, array $durations, int $totalDuration = 0): string
    {
        $lines = [];
        $lines[] = "#EXTM3U";
        $lines[] = "#EXT-X-VERSION:7";
        $lines[] = "#EXT-X-MEDIA-SEQUENCE:1";
        $lines[] = "#EXT-X-INDEPENDENT-SEGMENTS";
        $lines[] = "#EXT-X-MAP:URI=\"audio_init.mp4\"";

        $maxDuration = 0;
        foreach ($durations as $i => $duration) {
            $durationSec = max(0.001, round($duration / 1000, 3));
            $maxDuration = max($maxDuration, $durationSec);
            $lines[] = "#EXTINF:" . $durationSec . ",";
            $lines[] = "audio_" . ($i + 1) . ".m4s";
        }

        $lines[] = "#EXT-X-TARGETDURATION:" . (int)ceil($maxDuration);
        $lines[] = "#EXT-X-ENDLIST";

        $m3u8Content = implode("\n", $lines) . "\n";
        $m3u8Path = "$outputDir/audio.m3u8";
        file_put_contents($m3u8Path, $m3u8Content);

        return $m3u8Path;
    }

    /**
     * 生成分离模式的视频m3u8索引文件（使用实际切片时长）
     * @param string $outputDir 输出目录
     * @param array $durations 每个切片的时长数组（毫秒）
     * @param int $totalDuration 总时长（毫秒）
     * @return string m3u8文件路径
     */
    protected static function generateVideoM3u8WithDurations(string $outputDir, array $durations, int $totalDuration = 0): string
    {
        $lines = [];
        $lines[] = "#EXTM3U";
        $lines[] = "#EXT-X-VERSION:7";
        $lines[] = "#EXT-X-MEDIA-SEQUENCE:1";
        $lines[] = "#EXT-X-INDEPENDENT-SEGMENTS";
        $lines[] = "#EXT-X-MAP:URI=\"video_init.mp4\"";

        $maxDuration = 0;
        foreach ($durations as $i => $duration) {
            $durationSec = max(0.001, round($duration / 1000, 3));
            $maxDuration = max($maxDuration, $durationSec);
            $lines[] = "#EXTINF:" . $durationSec . ",";
            $lines[] = "video_" . ($i + 1) . ".m4s";
        }

        $lines[] = "#EXT-X-TARGETDURATION:" . (int)ceil($maxDuration);
        $lines[] = "#EXT-X-ENDLIST";

        $m3u8Content = implode("\n", $lines) . "\n";
        $m3u8Path = "$outputDir/video.m3u8";
        file_put_contents($m3u8Path, $m3u8Content);

        return $m3u8Path;
    }

    /**
     * 静态flv文件转hls协议，切片
     * @param string $inputFile 静态flv文件
     * @param string $outputDir 生成的切片目录
     * @return array 返回转码切片的目录及相关信息
     */
    public static function runFlv2Hls(string $inputFile, string $outputDir)
    {
        // ini_set('memory_limit', '512M');
        if (!file_exists($inputFile)) {
            throw new \RuntimeException("flv not exist!");
        }
        if(!self::isFlvFile($inputFile)){
            throw new \RuntimeException("only support flv file!");
        }
        $streamId = md5(basename($inputFile));
        $hls = new Flv2Hls($streamId,['segmentDuration'=>4,'outputDir'=>$outputDir."/".$streamId."/"]);
        $back = ['index'=>$hls->getIndex(),'outputDir'=>$hls->getStreamDir()];
        $hls->run($inputFile);
        $hls->close();
        return $back;
    }

    /**
     * 静态hls转flv
     * @param string $m3u8File M3U8文件路径
     * @param string $outputFile 输出的FLV文件路径
     * @return string 返回输出的FLV文件路径
     */
    public static function runHls2Flv(string $m3u8File, string $outputFile)
    {
        if (!file_exists($m3u8File)) {
            throw new \RuntimeException("m3u8 file not exist!");
        }
        $hls2Flv = new Hls2Flv($outputFile);
        $hls2Flv->run($m3u8File);
        return $outputFile;
    }

    /**
     * 将mp4转码为flv文件
     * @param string $mp4File 原始mp4静态文件
     * @param string $flvFile 输出的flv静态文件
     * @return string|void 返回转码成功的flv文件
     */
    public static function runMp42Flv(string $mp4File, string $flvFile)
    {
        try{
            $converter = new Mp4ToFlv($mp4File, $flvFile);
            if ($converter->run()){
                return $flvFile;
            }
        }catch (\Exception $e){
            throw new \RuntimeException("error:" . $e->getMessage());
        }
    }

    /**
     * 将flv转码为mp4文件
     * @param string $flvFile 原始flv静态文件
     * @param string $mp4File 输出的mp4静态文件
     * @return string|void 返回转码成功的mp4文件
     */
    public static function runFlv2Mp4(string $flvFile, string $mp4File)
    {
        try{
            $converter = new FlvToMp4($flvFile, $mp4File);
            if ($converter->run()){
                return $mp4File;
            }
        }catch (\Exception $e){
            throw new \RuntimeException("error:" . $e->getMessage());
        }
    }

    /**
     * 将fMP4切片转码为FLV文件
     * @param string $m3u8File fMP4的m3u8索引文件路径（支持混合模式和分离模式）
     * @param string $outputFile 输出的FLV文件路径
     * @return string|void 返回转码成功的FLV文件路径
     */
    public static function runFmp42Flv(string $m3u8File, string $outputFile)
    {
        if (!file_exists($m3u8File)) {
            throw new \RuntimeException("m3u8 file not exist!");
        }

        $m3u8Dir = dirname($m3u8File);
        $parsed = self::parseFmp4M3U8($m3u8File);

        $outputDir = dirname($outputFile);
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0777, true);
        }

        $fmp42flv = new Fmp42Flv();
        $flvData = '';

        $fmp42flv->onFlvData = function ($data) use (&$flvData) {
            $flvData .= $data;
        };

        $fmp42flv->onMediaInfo = function ($info) {
            echo "\n[回调] 媒体信息:\n";
            echo "  宽度: " . ($info['width'] ?? 'N/A') . "\n";
            echo "  高度: " . ($info['height'] ?? 'N/A') . "\n";
            echo "  音频: " . ($info['hasAudio'] ? '是' : '否') . "\n";
            echo "  视频: " . ($info['hasVideo'] ? '是' : '否') . "\n";
        };

        try {
            if (!empty($parsed['initFile'])) {
                $initPath = $m3u8Dir . DIRECTORY_SEPARATOR . $parsed['initFile'];
                if (file_exists($initPath)) {
                    $initData = file_get_contents($initPath);
                    $fmp42flv->setInitSegment($initData);
                }
            }

            if (!empty($parsed['audioInitFile']) && !$fmp42flv->hasAudio) {
                $audioInitPath = $m3u8Dir . DIRECTORY_SEPARATOR . $parsed['audioInitFile'];
                if (file_exists($audioInitPath)) {
                    $audioInitData = file_get_contents($audioInitPath);
                    $fmp42flv->setInitSegment($audioInitData);
                }
            }

            if (!empty($parsed['videoInitFile']) && !$fmp42flv->hasVideo) {
                $videoInitPath = $m3u8Dir . DIRECTORY_SEPARATOR . $parsed['videoInitFile'];
                if (file_exists($videoInitPath)) {
                    $videoInitData = file_get_contents($videoInitPath);
                    $fmp42flv->setInitSegment($videoInitData);
                }
            }

            $fmp42flv->flushInit();

            $allSamples = [];

            if (!empty($parsed['audioSegments'])) {
                foreach ($parsed['audioSegments'] as $seg) {
                    $segmentPath = $m3u8Dir . DIRECTORY_SEPARATOR . $seg;
                    if (!file_exists($segmentPath)) {
                        throw new \RuntimeException("segment file not exist: $segmentPath");
                    }
                    $segmentData = file_get_contents($segmentPath);
                    $samples = $fmp42flv->collectMediaSegmentSamples($segmentData);
                    $allSamples = array_merge($allSamples, $samples);
                }
            }

            if (!empty($parsed['videoSegments'])) {
                foreach ($parsed['videoSegments'] as $seg) {
                    $segmentPath = $m3u8Dir . DIRECTORY_SEPARATOR . $seg;
                    if (!file_exists($segmentPath)) {
                        throw new \RuntimeException("segment file not exist: $segmentPath");
                    }
                    $segmentData = file_get_contents($segmentPath);
                    $samples = $fmp42flv->collectMediaSegmentSamples($segmentData);
                    $allSamples = array_merge($allSamples, $samples);
                }
            }

            if (!empty($parsed['segmentFiles'])) {
                foreach ($parsed['segmentFiles'] as $seg) {
                    $segmentPath = $m3u8Dir . DIRECTORY_SEPARATOR . $seg;
                    if (!file_exists($segmentPath)) {
                        throw new \RuntimeException("segment file not exist: $segmentPath");
                    }
                    $segmentData = file_get_contents($segmentPath);
                    $samples = $fmp42flv->collectMediaSegmentSamples($segmentData);
                    $allSamples = array_merge($allSamples, $samples);
                }
            }

            usort($allSamples, function ($a, $b) {
                return $a['dts'] - $b['dts'];
            });

            foreach ($allSamples as $sample) {
                $fmp42flv->writeSample($sample);
            }

            file_put_contents($outputFile, $flvData);
            return $outputFile;
        } catch (\Exception $e) {
            throw new \RuntimeException("error:" . $e->getMessage());
        }
    }

    /**
     * 解析fMP4的m3u8索引文件，提取init.mp4和切片文件列表（支持混合模式和分离模式）
     * @param string $m3u8File m3u8文件路径
     * @return array ['initFile' => string, 'segmentFiles' => array, 'audioInitFile' => string, 'audioSegments' => array, 'videoInitFile' => string, 'videoSegments' => array]
     */
    protected static function parseFmp4M3U8(string $m3u8File): array
    {
        $content = file_get_contents($m3u8File);
        $lines = explode("\n", $content);
        
        $m3u8Dir = dirname($m3u8File);
        
        $initFile = null;
        $segmentFiles = [];
        $audioInitFile = null;
        $audioSegments = [];
        $videoInitFile = null;
        $videoSegments = [];

        $audioM3u8File = null;
        $videoM3u8File = null;

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            if (strpos($line, '#EXT-X-MAP:') === 0) {
                preg_match('/URI="([^"]+)"/', $line, $matches);
                if (isset($matches[1])) {
                    $initFile = $matches[1];
                }
            } elseif (strpos($line, '#EXT-X-MEDIA:TYPE=AUDIO') === 0) {
                preg_match('/URI="([^"]+)"/', $line, $matches);
                if (isset($matches[1])) {
                    $audioM3u8File = $matches[1];
                }
            } elseif (strpos($line, '#EXT-X-STREAM-INF:') === 0) {
                continue;
            } elseif (strpos($line, '#') !== 0) {
                $ext = pathinfo($line, PATHINFO_EXTENSION);
                if ($ext === 'm4s') {
                    $segmentFiles[] = $line;
                } elseif ($ext === 'm3u8') {
                    $videoM3u8File = $line;
                }
            }
        }

        if ($audioM3u8File) {
            $audioM3u8Path = $m3u8Dir . DIRECTORY_SEPARATOR . $audioM3u8File;
            if (file_exists($audioM3u8Path)) {
                $audioParsed = self::parseFmp4M3U8($audioM3u8Path);
                $audioInitFile = $audioParsed['initFile'];
                $audioSegments = $audioParsed['segmentFiles'];
            }
        }

        if ($videoM3u8File) {
            $videoM3u8Path = $m3u8Dir . DIRECTORY_SEPARATOR . $videoM3u8File;
            if (file_exists($videoM3u8Path)) {
                $videoParsed = self::parseFmp4M3U8($videoM3u8Path);
                $videoInitFile = $videoParsed['initFile'];
                $videoSegments = $videoParsed['segmentFiles'];
            }
        }

        return [
            'initFile' => $initFile,
            'segmentFiles' => $segmentFiles,
            'audioInitFile' => $audioInitFile,
            'audioSegments' => $audioSegments,
            'videoInitFile' => $videoInitFile,
            'videoSegments' => $videoSegments
        ];
    }

    /**
     * 从媒体片段中计算实际时长（毫秒）
     * @param array $segments 媒体片段数组
     * @param string $initSegment 初始化片段
     * @return int 时长（毫秒）
     */
    protected static function calculateDurationFromSegments(array $segments, string $initSegment): int
    {
        $maxEndTime = 0;
        $timescale = 1000; // 默认 timescale

        // 从初始化片段中获取 timescale
        // mvhd box 结构: size(4) + 'mvhd'(4) + content
        $mvhdPos = strpos($initSegment, 'mvhd');
        if ($mvhdPos !== false) {
            $mvhdContentStart = $mvhdPos + 4;
            if ($mvhdContentStart + 16 <= strlen($initSegment)) {
                $mvhdVersion = ord(substr($initSegment, $mvhdContentStart, 1));
                if ($mvhdVersion == 0) {
                    // version 0: timescale at offset 12 (relative to content start)
                    $timescaleOffset = $mvhdContentStart + 12;
                } else {
                    // version 1: timescale at offset 20 (relative to content start)
                    $timescaleOffset = $mvhdContentStart + 20;
                }
                if ($timescaleOffset + 4 <= strlen($initSegment)) {
                    $timescale = unpack('N', substr($initSegment, $timescaleOffset, 4))[1];
                }
            }
        }

        // 从每个媒体片段中提取最大时间
        foreach ($segments as $segment) {
            $segmentEndTime = self::extractSegmentEndTime($segment, $timescale);
            if ($segmentEndTime > $maxEndTime) {
                $maxEndTime = $segmentEndTime;
            }
        }

        // 转换为毫秒
        return (int)($maxEndTime * 1000 / $timescale);
    }

    /**
     * 从单个媒体片段中提取结束时间
     * @param string $segment 媒体片段数据
     * @param int $timescale 时间尺度
     * @return float 结束时间（timescale 单位）
     */
    protected static function extractSegmentEndTime(string $segment, int $timescale): float
    {
        $maxEndTime = 0;

        // 查找 moof box
        $moofPos = strpos($segment, 'moof');
        if ($moofPos === false) {
            return 0;
        }

        // 查找 traf box
        $trafPos = strpos($segment, 'traf', $moofPos);
        if ($trafPos === false) {
            return 0;
        }

        // 查找 tfdt box
        // tfdt box 结构: size(4) + 'tfdt'(4) + content
        // content: version+flags(4) + baseMediaDecodeTime(4 or 8)
        $tfdtPos = strpos($segment, 'tfdt', $trafPos);
        if ($tfdtPos === false) {
            return 0;
        }

        // 解析 tfdt box 获取 baseMediaDecodeTime
        $tfdtContentStart = $tfdtPos + 4;
        if ($tfdtContentStart + 8 <= strlen($segment)) {
            $tfdtVersion = ord(substr($segment, $tfdtContentStart, 1));
            if ($tfdtVersion == 0) {
                // version 0: baseMediaDecodeTime at offset 4 (relative to content start)
                $baseMediaDecodeTime = unpack('N', substr($segment, $tfdtContentStart + 4, 4))[1];
            } else {
                // version 1: baseMediaDecodeTime at offset 4 (relative to content start), 8 bytes
                $baseMediaDecodeTime = unpack('J', substr($segment, $tfdtContentStart + 4, 8))[1];
            }
        } else {
            $baseMediaDecodeTime = 0;
        }

        // 查找 trun box
        // trun box 结构: size(4) + 'trun'(4) + content
        // content: version+flags(4) + sampleCount(4) + [dataOffset(4)] + samples...
        $trunPos = strpos($segment, 'trun', $trafPos);
        if ($trunPos === false) {
            return $baseMediaDecodeTime;
        }

        // 解析 trun box 获取样本数量和总时长
        $trunContentStart = $trunPos + 4;
        if ($trunContentStart + 8 <= strlen($segment)) {
            $trunFlags = unpack('N', substr($segment, $trunContentStart, 4))[1];
            $sampleCount = unpack('N', substr($segment, $trunContentStart + 4, 4))[1];

            // 计算 trun 数据偏移 (relative to content start)
            $dataOffset = 8;
            // 检查是否有 data-offset-present (flag 0x000001)
            if ($trunFlags & 0x000001) {
                $dataOffset += 4;
            }

            // 计算所有样本的时长总和
            $totalDuration = 0;
            // 检查是否有 sample-duration-present (flag 0x000100)
            if ($trunFlags & 0x000100) {
                for ($i = 0; $i < $sampleCount; $i++) {
                    $sampleDataOffset = $trunContentStart + $dataOffset;
                    if ($sampleDataOffset + 4 > strlen($segment)) {
                        break;
                    }
                    $sampleDuration = unpack('N', substr($segment, $sampleDataOffset, 4))[1];
                    $totalDuration += $sampleDuration;
                    // 每个 sample 至少有 duration(4) + size(4) + flags(4) + cts(4) = 16 字节
                    $dataOffset += 16;
                }
            }

            $maxEndTime = $baseMediaDecodeTime + $totalDuration;
        }

        return $maxEndTime;
    }

    /**
     * 更新初始化片段中的 duration 字段
     * @param string $initData 初始化片段数据
     * @param int $duration 时长（毫秒）
     * @return string 更新后的初始化片段
     */
    protected static function updateInitSegmentDuration(string $initData, int $duration): string
    {
        // 更新 mvhd 中的 duration
        // mvhd box 结构: size(4) + 'mvhd'(4) + content
        // content 结构 (version 0):
        //   version+flags(4) + creation_time(4) + modification_time(4) + timescale(4) + duration(4) + ...
        // content 结构 (version 1):
        //   version+flags(4) + creation_time(8) + modification_time(8) + timescale(4) + duration(8) + ...
        $mvhdPos = strpos($initData, 'mvhd');
        if ($mvhdPos === false) {
            return $initData;
        }

        // box 内容开始位置 = 'mvhd' 位置 + 4
        $mvhdContentStart = $mvhdPos + 4;
        if ($mvhdContentStart + 4 > strlen($initData)) {
            return $initData;
        }

        $mvhdVersion = ord(substr($initData, $mvhdContentStart, 1));

        if ($mvhdVersion == 0) {
            // version 0: timescale at offset 12, duration at offset 16 (relative to content start)
            $timescaleOffset = $mvhdContentStart + 12;
            $durationOffset = $mvhdContentStart + 16;
            $durationLength = 4;
        } else {
            // version 1: timescale at offset 20, duration at offset 24 (relative to content start)
            $timescaleOffset = $mvhdContentStart + 20;
            $durationOffset = $mvhdContentStart + 24;
            $durationLength = 8;
        }

        if ($timescaleOffset + 4 > strlen($initData) || $durationOffset + $durationLength > strlen($initData)) {
            return $initData;
        }

        $mvhdTimescale = unpack('N', substr($initData, $timescaleOffset, 4))[1];
        $mvhdDurationInTimescale = (int)($duration * $mvhdTimescale / 1000);

        if ($durationLength == 4) {
            $durationBytes = pack('N', $mvhdDurationInTimescale);
        } else {
            $durationBytes = pack('J', $mvhdDurationInTimescale);
        }
        $initData = substr_replace($initData, $durationBytes, $durationOffset, $durationLength);

        // 更新所有 tkhd 中的 duration
        // tkhd box 结构: size(4) + 'tkhd'(4) + content
        // content 结构 (version 0):
        //   version+flags(4) + creation_time(4) + modification_time(4) + track_id(4) + reserved(4) + duration(4) + ...
        // content 结构 (version 1):
        //   version+flags(4) + creation_time(8) + modification_time(8) + track_id(4) + reserved(4) + duration(8) + ...
        $trakPos = 0;
        while (($trakPos = strpos($initData, 'trak', $trakPos)) !== false) {
            $tkhdPos = strpos($initData, 'tkhd', $trakPos);
            if ($tkhdPos !== false) {
                $tkhdContentStart = $tkhdPos + 4;
                if ($tkhdContentStart + 4 > strlen($initData)) {
                    $trakPos = $trakPos + 4;
                    continue;
                }

                $tkhdVersion = ord(substr($initData, $tkhdContentStart, 1));

                if ($tkhdVersion == 0) {
                    // version 0: duration at offset 20 (relative to content start)
                    $tkhdDurationOffset = $tkhdContentStart + 20;
                    $durationLength = 4;
                } else {
                    // version 1: duration at offset 28 (relative to content start)
                    $tkhdDurationOffset = $tkhdContentStart + 28;
                    $durationLength = 8;
                }

                if ($tkhdDurationOffset + $durationLength <= strlen($initData)) {
                    if ($durationLength == 4) {
                        $durationBytes = pack('N', $mvhdDurationInTimescale);
                    } else {
                        $durationBytes = pack('J', $mvhdDurationInTimescale);
                    }
                    $initData = substr_replace($initData, $durationBytes, $tkhdDurationOffset, $durationLength);
                }
            }
            $trakPos = $trakPos + 4;
        }

        return $initData;
    }
}