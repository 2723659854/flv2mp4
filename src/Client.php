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
        if (!is_dir($outputDir) && !mkdir($outputDir, 0777, true) && !is_dir($outputDir)) {
            throw new \RuntimeException("cannot create output directory: {$outputDir}");
        }

        $flv2fmp4 = new Flv2Fmp4();
        $initSegment = null;
        $segmentIndex = 0;
        $pendingFiles = [];
        $temporaryFiles = [];
        $fullHandle = null;
        $fullTemp = null;
        $mp4Path = null;

        $bufferHandle = null;
        $bufferTemp = null;
        $bufferBytes = 0;
        $index = 1;

        $flv2fmp4->onInitSegment = function ($data) use (&$initSegment, $outputDir, $flv2fmp4, &$pendingFiles, &$temporaryFiles, &$fullHandle, &$fullTemp) {
            echo "\n[回调] 初始化段生成\n";
            echo "大小: " . strlen($data) . " bytes\n";
            $initSegment = $data;
            $initPath = "$outputDir/init.mp4";
            $initTemp = self::temporaryPath($initPath);
            self::writeFile($initTemp, $data);
            $pendingFiles[$initPath] = $initTemp;
            $temporaryFiles[] = $initTemp;

            $fullTemp = self::temporaryPath("$outputDir/output.mp4");
            $fullHandle = self::openOutput($fullTemp);
            $temporaryFiles[] = $fullTemp;
            self::writeAll($fullHandle, $data);
            echo "已准备: $outputDir/init.mp4\n";

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
                $metaPath = "$outputDir/meta.json";
                $metaTemp = self::temporaryPath($metaPath);
                self::writeFile($metaTemp, json_encode($meta));
                $pendingFiles[$metaPath] = $metaTemp;
                $temporaryFiles[] = $metaTemp;
                echo "已准备: $metaPath\n";
                echo "编解码器信息: " . json_encode($meta) . "\n";
            }
        };

        $segmentIndex = 0;
        $segmentStartDts = -1;
        $segmentEndDts = -1;
        $readyToCut = false;
        $allSegments = [];
        $actualDuration = 0;

        $flushSegment = function () use (&$bufferHandle, &$bufferTemp, &$bufferBytes, &$index, &$segmentStartDts, &$segmentEndDts, &$allSegments, &$pendingFiles, &$temporaryFiles, $outputDir) {
            if ($bufferHandle === null || $bufferBytes === 0) return;
            if (!fflush($bufferHandle)) throw new \RuntimeException('cannot flush media fragment');
            fclose($bufferHandle);
            $bufferHandle = null;
            $path = "$outputDir/segment_$index.m4s";
            $duration = max(0, $segmentEndDts - $segmentStartDts);
            $pendingFiles[$path] = $bufferTemp;
            $temporaryFiles[] = $bufferTemp;
            $allSegments[] = ['path' => $path, 'duration' => $duration];
            echo "已准备: $path (大小: {$bufferBytes} bytes, 时长: " . ($duration / 1000) . "s)\n";
            $bufferTemp = null;
            $bufferBytes = 0;
            $segmentStartDts = -1;
            $segmentEndDts = -1;
            $index++;
        };

        $flv2fmp4->onMediaSegment = function ($data, $value) use (&$segmentIndex, $outputDir, &$bufferHandle, &$bufferTemp, &$bufferBytes, &$fullHandle, &$index, $targetSegmentDuration, $maxSegmentDuration, &$segmentStartDts, &$segmentEndDts, &$readyToCut, &$actualDuration, $flushSegment) {
            $info = $value['info'] ?? null;
            $track = $value['track'] ?? '';
            $isKeyframe = (bool)($value['isKeyframe'] ?? false);
            $beginDts = $info ? ($info->originalBeginDts ?? $info->beginDts ?? 0) : 0;
            $endDts = $info ? ($info->originalEndDts ?? $info->endDts ?? $beginDts) : $beginDts;

            if ($bufferBytes > 0 && $track === 'video' && $isKeyframe && ($readyToCut || $endDts - $segmentStartDts >= $maxSegmentDuration)) {
                $flushSegment();
                $readyToCut = false;
            }
            if ($bufferHandle === null) {
                $bufferTemp = self::temporaryPath("$outputDir/segment_$index.m4s");
                $bufferHandle = self::openOutput($bufferTemp);
            }
            self::writeAll($bufferHandle, $data);
            if ($fullHandle === null) throw new \RuntimeException('missing fMP4 init segment');
            self::writeAll($fullHandle, $data);
            $bufferBytes += strlen($data);
            if ($segmentStartDts < 0) $segmentStartDts = $beginDts;
            $segmentEndDts = max($segmentEndDts, $endDts);
            $actualDuration = max($actualDuration, $endDts);
            if ($segmentEndDts - $segmentStartDts >= $targetSegmentDuration) $readyToCut = true;

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
            self::streamFlvTags($inputFile, $flv2fmp4);
            $flushSegment();
            if ($initSegment === null || $fullHandle === null || $segmentIndex === 0) throw new \RuntimeException('no fMP4 media was generated');

            $initSegment = self::updateInitSegmentDuration($initSegment, $actualDuration);
            self::writeFile($pendingFiles["$outputDir/init.mp4"], $initSegment);
            if (fseek($fullHandle, 0) !== 0) throw new \RuntimeException('cannot update fMP4 init segment');
            self::writeAll($fullHandle, $initSegment);
            if (!fflush($fullHandle)) throw new \RuntimeException('cannot flush fMP4 output');
            fclose($fullHandle);
            $fullHandle = null;

            $mp4Name = date("Y_m_d_H_i_s") . "_" . uniqid() . '.mp4';
            $mp4Path = "$outputDir/$mp4Name";
            $pendingFiles[$mp4Path] = $fullTemp;
            $m3u8Path = "$outputDir/index.m3u8";
            $m3u8Temp = self::temporaryPath($m3u8Path);
            self::writeFile($m3u8Temp, self::mixedM3u8Content($allSegments));
            $pendingFiles[$m3u8Path] = $m3u8Temp;
            $temporaryFiles[] = $m3u8Temp;

            foreach ($pendingFiles as $path => $temp) self::atomicReplace($temp, $path);
            echo "\n已写入 m3u8 索引文件: $m3u8Path\n";
            return $mp4Path;
        } catch (\Throwable $e) {
            if (is_resource($bufferHandle)) fclose($bufferHandle);
            if (is_resource($fullHandle)) fclose($fullHandle);
            foreach (array_unique(array_filter(array_merge($temporaryFiles, [$bufferTemp, $fullTemp]))) as $temp) {
                if (is_file($temp)) @unlink($temp);
            }
            throw new \RuntimeException("error:" . $e->getMessage(), 0, $e);
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
            self::streamFlvTags($inputFile, $flv2fmp4);
        } catch (\Exception $e) {
            throw new \RuntimeException("error:" . $e->getMessage(), 0, $e);
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

    private static function streamFlvTags(string $inputFile, Flv2Fmp4 $converter): void
    {
        $handle = @fopen($inputFile, 'rb');
        if ($handle === false) throw new \RuntimeException("cannot open FLV: {$inputFile}");
        \Xiaosongshu\Flv2mp4\Flv\FlvParse::reset();
        try {
            $header = self::readExact($handle, 9);
            if (substr($header, 0, 3) !== 'FLV' || ord($header[3]) !== 1) throw new \RuntimeException('invalid FLV header');
            $headerSize = unpack('N', substr($header, 5, 4))[1];
            if ($headerSize < 9) throw new \RuntimeException('invalid FLV header size');
            if ($headerSize > 9) $header .= self::readExact($handle, $headerSize - 9);
            $previousTagSize = self::readExact($handle, 4);
            $first = true;
            while (true) {
                $tagHeader = fread($handle, 11);
                if ($tagHeader === false) throw new \RuntimeException('cannot read FLV tag header');
                if ($tagHeader === '') break;
                if (strlen($tagHeader) !== 11) throw new \RuntimeException('truncated FLV tag header');
                $dataSize = unpack('N', "\0" . substr($tagHeader, 1, 3))[1];
                $tag = $tagHeader . self::readExact($handle, $dataSize) . self::readExact($handle, 4);
                $converter->setflv($first ? $header . $previousTagSize . $tag : $tag, 0);
                $first = false;
            }
        } finally {
            fclose($handle);
            \Xiaosongshu\Flv2mp4\Flv\FlvParse::reset();
        }
    }

    private static function readExact($handle, int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = fread($handle, $length - strlen($data));
            if ($chunk === false || $chunk === '') throw new \RuntimeException('truncated FLV file');
            $data .= $chunk;
        }
        return $data;
    }

    private static function temporaryPath(string $path): string
    {
        return $path . '.part.' . bin2hex(random_bytes(6));
    }

    private static function openOutput(string $path)
    {
        $handle = @fopen($path, 'w+b');
        if ($handle === false) throw new \RuntimeException("cannot create temporary output: {$path}");
        return $handle;
    }

    private static function writeAll($handle, string $data): void
    {
        for ($offset = 0, $length = strlen($data); $offset < $length;) {
            $written = fwrite($handle, substr($data, $offset));
            if ($written === false || $written === 0) throw new \RuntimeException('cannot write output');
            $offset += $written;
        }
    }

    private static function writeFile(string $path, string $data): void
    {
        $handle = self::openOutput($path);
        try {
            self::writeAll($handle, $data);
            if (!fflush($handle)) throw new \RuntimeException("cannot flush output: {$path}");
        } finally {
            fclose($handle);
        }
    }

    private static function atomicReplace(string $temporary, string $path): void
    {
        if (!@rename($temporary, $path)) throw new \RuntimeException("cannot replace output atomically: {$path}");
    }

    private static function mixedM3u8Content(array $segments): string
    {
        $lines = ['#EXTM3U', '#EXT-X-VERSION:7', '#EXT-X-MEDIA-SEQUENCE:1', '#EXT-X-INDEPENDENT-SEGMENTS', '#EXT-X-MAP:URI="init.mp4"'];
        $maxDuration = 0.001;
        foreach ($segments as $i => $segment) {
            $duration = max(0.001, round($segment['duration'] / 1000, 3));
            $maxDuration = max($maxDuration, $duration);
            $lines[] = "#EXTINF:{$duration},";
            $lines[] = 'segment_' . ($i + 1) . '.m4s';
        }
        $lines[] = '#EXT-X-TARGETDURATION:' . (int)ceil($maxDuration);
        $lines[] = '#EXT-X-ENDLIST';
        return implode("\n", $lines) . "\n";
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
    public static function runFmp42Flv(string $m3u8File, string $outputFile){
        return Fmp42Flv::runFmp42Flv($m3u8File, $outputFile);
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