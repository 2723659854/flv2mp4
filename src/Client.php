<?php

namespace Xiaosongshu\Flv2mp4;

use Xiaosongshu\Flv2mp4\Manage\Flv2Fmp4;
use Xiaosongshu\Flv2mp4\manage\Flv2Hls;
use Xiaosongshu\Flv2mp4\manage\Hls2Flv;

/**
 * @purpose flv文件转码mp4客户端
 * @author xiaosongshu
 * @time 2026年5月29日14:14:00
 */
class Client
{

    /**
     * 静态flv转MP4入口函数，音视频混合切片，并生成合并的mp4文件
     * @param string $inputFile 需要转换的flv文件
     * @param string $outputDir 存储mp4的目录
     * @param int $segmentPackets 每个切片包含的包数量（默认30）
     * @return string|null
     */
    public static function runFlv2mp4(string $inputFile, string $outputDir, int $segmentPackets = 30)
    {
        return self::run($inputFile, $outputDir, $segmentPackets);
    }

    /**
     * 静态flv转MP4入口函数，音视频混合切片，并生成合并的mp4文件
     * @param string $inputFile 需要转换的flv文件
     * @param string $outputDir 存储mp4的目录
     * @param int $segmentPackets 每个切片包含的包数量（默认30）
     * @return string|void
     */
    public static function run(string $inputFile, string $outputDir, int $segmentPackets = 30)
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

        $flv2fmp4->onMediaSegment = function ($data) use (&$segments, &$segmentIndex, $outputDir, &$buffer, &$index, $segmentPackets) {
            /** 将多个包合并成一个切片，防止生成过多的切片 */
            $buffer[] = $data;
            $segments[] = $data;

            if (count($buffer) >= $segmentPackets) {
                $segmentData = implode("", $buffer);
                file_put_contents("$outputDir/segment_$index.m4s", $segmentData);
                echo "已写入: $outputDir/segment_$index.m4s (大小: " . strlen($segmentData) . " bytes)\n";
                $buffer = [];
                $index++;
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
        if (!empty($buffer)) {
            $segmentData = implode("", $buffer);
            file_put_contents("$outputDir/segment_$index.m4s", $segmentData);
            echo "\n已写入剩余切片: $outputDir/segment_$index.m4s (大小: " . strlen($segmentData) . " bytes)\n";
        }

        if ($initSegment && !empty($segments)) {
            $fullBinary = $initSegment . implode('', $segments);
            $mp4Name = date("Y_m_d_H_i_s") . "_" . uniqid() . '.mp4';
            file_put_contents("$outputDir/$mp4Name", $fullBinary);
            return "$outputDir/$mp4Name";
        } else {
            return "";
        }
    }

    /**
     * 静态flv转MP4入口函数，音视频分开切片，适用于高级自定义播放场景
     * @param string $inputFile 需要转换的flv文件
     * @param string $outputDir 存储切片的目录
     * @param int $segmentPackets 每个切片包含的包数量（默认30）
     * @return array 返回生成的文件信息
     */
    public static function runFlv2mp4Separate(string $inputFile, string $outputDir, int $segmentPackets = 30)
    {
        return self::runSeparate($inputFile, $outputDir, $segmentPackets);
    }

    /**
     * flv转MP4生成分开的音视频切片（用于浏览器播放）
     * @param string $inputFile 需要转换的flv文件
     * @param string $outputDir 存储切片的目录
     * @param int $segmentPackets 每个切片包含的包数量（默认30）
     * @return array 返回生成的文件信息
     */
    public static function runSeparate(string $inputFile, string $outputDir, int $segmentPackets = 30)
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
            'meta' => null
        ];

        // 音频缓冲区和视频缓冲区
        $audioBuffer = [];
        $videoBuffer = [];

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
        $flv2fmp4->onAudioSegment = function ($data, $value) use ($outputDir, &$audioSegmentIndex, &$outputFiles, &$audioBuffer, $segmentPackets) {
            $audioSegmentIndex++;

            // 将数据添加到缓冲区
            $audioBuffer[] = $data;

            // 当缓冲区达到指定数量时，写入切片文件
            if (count($audioBuffer) >= $segmentPackets) {
                $segmentData = implode("", $audioBuffer);
                $audioBufferIndex = count($outputFiles['audioSegments']) + 1;
                $filename = "$outputDir/audio_$audioBufferIndex.m4s";
                file_put_contents($filename, $segmentData);
                $outputFiles['audioSegments'][] = $filename;
                echo "\n已写入: $filename (大小: " . strlen($segmentData) . " bytes, 包含 " . $segmentPackets . " 个包)\n";
                $audioBuffer = [];
            }

            echo "\n[回调] 音频段#$audioSegmentIndex\n";
            echo "大小: " . strlen($data) . " bytes\n";
        };

        // 视频切片回调
        $flv2fmp4->onVideoSegment = function ($data, $value) use ($outputDir, &$videoSegmentIndex, &$outputFiles, &$videoBuffer, $segmentPackets) {
            $videoSegmentIndex++;

            // 将数据添加到缓冲区
            $videoBuffer[] = $data;

            // 当缓冲区达到指定数量时，写入切片文件
            if (count($videoBuffer) >= $segmentPackets) {
                $segmentData = implode("", $videoBuffer);
                $videoBufferIndex = count($outputFiles['videoSegments']) + 1;
                $filename = "$outputDir/video_$videoBufferIndex.m4s";
                file_put_contents($filename, $segmentData);
                $outputFiles['videoSegments'][] = $filename;
                echo "\n已写入: $filename (大小: " . strlen($segmentData) . " bytes, 包含 " . $segmentPackets . " 个包)\n";
                $videoBuffer = [];
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
            $audioBufferIndex = count($outputFiles['audioSegments']) + 1;
            $filename = "$outputDir/audio_$audioBufferIndex.m4s";
            file_put_contents($filename, $segmentData);
            $outputFiles['audioSegments'][] = $filename;
            echo "\n已写入剩余音频切片: $filename (大小: " . strlen($segmentData) . " bytes, 包含 " . count($audioBuffer) . " 个包)\n";
        }

        // 写入剩余的视频缓冲区内容
        if (!empty($videoBuffer)) {
            $segmentData = implode("", $videoBuffer);
            $videoBufferIndex = count($outputFiles['videoSegments']) + 1;
            $filename = "$outputDir/video_$videoBufferIndex.m4s";
            file_put_contents($filename, $segmentData);
            $outputFiles['videoSegments'][] = $filename;
            echo "\n已写入剩余视频切片: $filename (大小: " . strlen($segmentData) . " bytes, 包含 " . count($videoBuffer) . " 个包)\n";
        }

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
        $streamId = "a/b";
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
}