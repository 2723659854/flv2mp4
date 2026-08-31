<?php

namespace Xiaosongshu\Flv2mp4\Manage;

/**
 * @purpose 将 fMP4 初始化段和媒体分片直接重建为标准 MP4
 * @author yanglong
 * @time 2026年8月31日14:04:36
 */
class Fmp42Mp4
{
    private string $inputFile;
    private string $outputFile;
    private string $mdatPath = '';
    private $mdatFile = null;
    private int $mdatSize = 0;
    private array $videoSamples = [];
    private array $audioSamples = [];

    public function __construct(string $inputFile, string $outputFile)
    {
        if (!is_file($inputFile)) throw new \RuntimeException("fMP4 输入不存在: {$inputFile}");
        $this->inputFile = $inputFile;
        $this->outputFile = $outputFile;
        $dir = dirname($outputFile);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException("无法创建输出目录: {$dir}");
        }
    }

    public function run(): string
    {
        [$initFiles, $segments] = $this->resolveInput($this->inputFile);
        if (!$initFiles) throw new \RuntimeException('未找到 fMP4 init.mp4');
        if (!$segments) throw new \RuntimeException('未找到 fMP4 m4s 分片');

        $parser = new Fmp42Flv();
        foreach ($initFiles as $initFile) {
            $data = file_get_contents($initFile);
            if ($data === false) throw new \RuntimeException("无法读取初始化段: {$initFile}");
            $parser->setInitSegment($data);
        }
        if (!$parser->hasVideo && !$parser->hasAudio) throw new \RuntimeException('初始化段中未找到 H264/AAC 轨道');

        $this->mdatPath = tempnam(dirname($this->outputFile), '.fmp42mp4-mdat-');
        if ($this->mdatPath === false) throw new \RuntimeException('无法创建临时媒体文件');
        $this->mdatFile = fopen($this->mdatPath, 'w+b');
        if ($this->mdatFile === false) {
            @unlink($this->mdatPath);
            throw new \RuntimeException('无法打开临时媒体文件');
        }

        try {
            foreach ($segments as $segment) {
                $data = file_get_contents($segment);
                if ($data === false) throw new \RuntimeException("无法读取媒体分片: {$segment}");
                foreach ($parser->collectMediaSegmentSamples($data) as $sample) {
                    if (!isset($sample['data']) || strlen($sample['data']) !== $sample['size']) {
                        throw new \RuntimeException("媒体分片 sample 不完整: {$segment}");
                    }
                    $index = $this->writeSample($sample['data']);
                    $timescale = $sample['type'] === 'video' ? $parser->videoTimescale : $parser->audioTimescale;
                    $index['timestamp'] = (int)round($sample['dts'] * 1000 / $timescale);
                    if ($sample['type'] === 'video') {
                        $index['cts'] = (int)round($sample['cts'] * 1000 / $timescale);
                        $index['keyframe'] = (bool)$sample['isKeyframe'];
                        $index['index'] = count($this->videoSamples);
                        $this->videoSamples[] = $index;
                    } else {
                        $index['index'] = count($this->audioSamples);
                        $this->audioSamples[] = $index;
                    }
                }
                unset($data);
            }
            if (!$this->videoSamples && !$this->audioSamples) throw new \RuntimeException('媒体分片中未找到 sample');
            fflush($this->mdatFile);
            $this->buildMp4($parser, $initFiles[0]);
            return $this->outputFile;
        } finally {
            if (is_resource($this->mdatFile)) fclose($this->mdatFile);
            $this->mdatFile = null;
            if ($this->mdatPath !== '') @unlink($this->mdatPath);
        }
    }

    private function resolveInput(string $input): array
    {
        $extension = strtolower(pathinfo($input, PATHINFO_EXTENSION));
        if ($extension === 'm3u8') return $this->parsePlaylist($input);
        $dir = dirname($input);
        if ($extension === 'm4s') {
            $init = $dir . DIRECTORY_SEPARATOR . 'init.mp4';
            return [is_file($init) ? [$init] : [], [$input]];
        }
        if ($extension === 'mp4') {
            $segments = glob($dir . DIRECTORY_SEPARATOR . '*.m4s') ?: [];
            natsort($segments);
            return [[$input], array_values($segments)];
        }
        throw new \RuntimeException('仅支持 m3u8、init.mp4 或 m4s 输入');
    }

    private function parsePlaylist(string $playlist): array
    {
        $content = file_get_contents($playlist);
        if ($content === false) throw new \RuntimeException("无法读取 m3u8: {$playlist}");
        $dir = dirname($playlist);
        $initFiles = [];
        $segments = [];
        $children = [];
        foreach (preg_split('/\r?\n/', $content) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            if (str_starts_with($line, '#EXT-X-MAP:') && preg_match('/URI="([^"]+)"/', $line, $match)) {
                $initFiles[] = $this->localPath($dir, $match[1]);
            } elseif ($line[0] !== '#') {
                $path = $this->localPath($dir, $line);
                $ext = strtolower(pathinfo(parse_url($line, PHP_URL_PATH) ?: $line, PATHINFO_EXTENSION));
                if ($ext === 'm4s') $segments[] = $path;
                elseif ($ext === 'm3u8') $children[] = $path;
            }
        }
        foreach ($children as $child) {
            [$childInit, $childSegments] = $this->parsePlaylist($child);
            $initFiles = array_merge($initFiles, $childInit);
            $segments = array_merge($segments, $childSegments);
        }
        $initFiles = array_values(array_unique($initFiles));
        $segments = array_values(array_unique($segments));
        foreach (array_merge($initFiles, $segments) as $file) {
            if (!is_file($file)) throw new \RuntimeException("m3u8 引用文件不存在: {$file}");
        }
        return [$initFiles, $segments];
    }

    private function localPath(string $dir, string $uri): string
    {
        $path = parse_url($uri, PHP_URL_PATH);
        if ($path === null || preg_match('#^[a-z]+://#i', $uri)) {
            throw new \RuntimeException("不支持远程 m3u8 URI: {$uri}");
        }
        $path = rawurldecode($path);
        if (preg_match('/^[A-Za-z]:[\\\\\/]/', $path) || str_starts_with($path, '/') || str_starts_with($path, '\\')) return $path;
        return $dir . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }

    private function writeSample(string $data): array
    {
        $size = strlen($data);
        $written = 0;
        while ($written < $size) {
            $count = fwrite($this->mdatFile, substr($data, $written));
            if ($count === false || $count === 0) throw new \RuntimeException('写入临时媒体文件失败');
            $written += $count;
        }
        $sample = ['offset' => $this->mdatSize, 'size' => $size];
        $this->mdatSize += $size;
        return $sample;
    }

    private function buildMp4(Fmp42Flv $source, string $initFile): void
    {
        $builder = new FlvToMp4($initFile, $this->outputFile);
        $reflection = new \ReflectionClass($builder);
        $values = [
            'mdatFile' => $this->mdatFile,
            'mdatPath' => '',
            'mdatSize' => $this->mdatSize,
            'hasVideo' => (bool)$this->videoSamples,
            'hasAudio' => (bool)$this->audioSamples,
            'videoWidth' => $source->videoWidth,
            'videoHeight' => $source->videoHeight,
            'sps' => $source->sps,
            'pps' => $source->pps,
            'avccHeader' => $this->avcc($source->sps, $source->pps),
            'audioSampleRate' => $source->audioSampleRate,
            'audioChannels' => $source->audioChannels,
            'audioObjectType' => $source->audioObjectType,
            'audioSpecificConfig' => $source->audioSpecificConfig,
            'videoSamples' => $this->videoSamples,
            'audioSamples' => $this->audioSamples,
            'videoTimescale' => 90000,
            'audioTimescale' => $source->audioSampleRate,
        ];
        foreach ($values as $name => $value) {
            $property = $reflection->getProperty($name);
            $property->setAccessible(true);
            $property->setValue($builder, $value);
        }
        $method = $reflection->getMethod('buildMp4');
        $method->setAccessible(true);
        $method->invoke($builder);
    }

    private function avcc(string $sps, string $pps): string
    {
        if ($sps === '' || $pps === '') return '';
        return "\x01" . ($sps[1] ?? "\x42") . ($sps[2] ?? "\x00") . ($sps[3] ?? "\x1f") .
            "\xff\xe1" . pack('n', strlen($sps)) . $sps . "\x01" . pack('n', strlen($pps)) . $pps;
    }
}
