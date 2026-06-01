<?php

namespace Xiaosongshu\Flv2mp4;

use Xiaosongshu\Flv2mp4\Manage\Flv2Fmp4;

/**
 * @purpose flv文件转码mp4客户端
 * @author xiaosongshu
 * @time 2026年5月29日14:14:00
 */
class Client
{

    /**
     * flv转MP4入口函数
     * @param string $inputFile 需要转换的flv文件
     * @param string $outputDir 存储mp4的目录
     * @return string|void
     */
    public static function run(string $inputFile,string $outputDir)
    {
        // ini_set('memory_limit', '512M');
        if (!file_exists($inputFile)) {
            throw new \RuntimeException("flv not exist!");
        }
        if(!self::isFlvFile($inputFile)){
            throw new \RuntimeException("only support flv file!");
        }
        if (!is_dir($outputDir)) mkdir($outputDir, 0777, true);
        array_map('unlink', glob("$outputDir/*"));
        $flvBinary = file_get_contents($inputFile);

        $flv2fmp4 = new Flv2Fmp4();
        $initSegment = null;
        $segments = [];
        $segmentIndex = 0;

        $flv2fmp4->onInitSegment = function($data) use (&$initSegment, $outputDir, $flv2fmp4) {
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

        $flv2fmp4->onMediaSegment = function($data) use (&$segments, &$segmentIndex, $outputDir) {
            $segmentIndex++;
            echo "\n[回调] 媒体段#$segmentIndex\n";
            echo "大小: " . strlen($data) . " bytes\n";
            $segments[] = $data;
            file_put_contents("$outputDir/segment_$segmentIndex.m4s", $data);
            echo "已写入: $outputDir/segment_$segmentIndex.m4s\n";
        };

        $flv2fmp4->onMediaInfo = function($mediaInfo, $tracks) {
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
           throw new \RuntimeException("error:".$e->getMessage());
        }

        if ($initSegment && !empty($segments)) {
            $fullBinary = $initSegment . implode('', $segments);
            $mp4Name = date("Y_m_d_H_i_s")."_".uniqid().'.mp4';
            file_put_contents("$outputDir/$mp4Name", $fullBinary);
            return "$outputDir/$mp4Name";
        }else{
            return "";
        }
    }

    /**
     * flv转MP4生成分开的音视频切片（用于浏览器播放）
     * @param string $inputFile 需要转换的flv文件
     * @param string $outputDir 存储切片的目录
     * @return array 返回生成的文件信息
     */
    public static function runSeparate(string $inputFile, string $outputDir)
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

        // 音频初始化片段回调
        $flv2fmp4->onAudioInitSegment = function($data, $meta) use ($outputDir, &$outputFiles) {
            echo "\n[回调] 音频初始化段生成\n";
            echo "大小: " . strlen($data) . " bytes\n";
            $outputFiles['audioInit'] = "$outputDir/audio_init.mp4";
            file_put_contents($outputFiles['audioInit'], $data);
            echo "已写入: " . $outputFiles['audioInit'] . "\n";
        };

        // 视频初始化片段回调
        $flv2fmp4->onVideoInitSegment = function($data, $meta) use ($outputDir, &$outputFiles) {
            echo "\n[回调] 视频初始化段生成\n";
            echo "大小: " . strlen($data) . " bytes\n";
            $outputFiles['videoInit'] = "$outputDir/video_init.mp4";
            file_put_contents($outputFiles['videoInit'], $data);
            echo "已写入: " . $outputFiles['videoInit'] . "\n";
        };

        // 音频切片回调
        $flv2fmp4->onAudioSegment = function($data, $value) use ($outputDir, &$audioSegmentIndex, &$outputFiles) {
            $audioSegmentIndex++;
            echo "\n[回调] 音频段#$audioSegmentIndex\n";
            echo "大小: " . strlen($data) . " bytes\n";
            $filename = "$outputDir/audio_$audioSegmentIndex.m4s";
            file_put_contents($filename, $data);
            $outputFiles['audioSegments'][] = $filename;
            echo "已写入: $filename\n";
        };

        // 视频切片回调
        $flv2fmp4->onVideoSegment = function($data, $value) use ($outputDir, &$videoSegmentIndex, &$outputFiles) {
            $videoSegmentIndex++;
            echo "\n[回调] 视频段#$videoSegmentIndex\n";
            echo "大小: " . strlen($data) . " bytes\n";
            $filename = "$outputDir/video_$videoSegmentIndex.m4s";
            file_put_contents($filename, $data);
            $outputFiles['videoSegments'][] = $filename;
            echo "已写入: $filename\n";
        };

        // 媒体信息回调
        $flv2fmp4->onMediaInfo = function($mediaInfo, $tracks) use ($outputDir, &$outputFiles, $flv2fmp4) {
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
            throw new \RuntimeException("error:".$e->getMessage());
        }

        return $outputFiles;
    }

    /**
     * 判断文件是否是flv文件
     * @param string $filename
     * @return bool
     */
    protected static function isFlvFile(string $filename) {
        return strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'flv';
    }
}