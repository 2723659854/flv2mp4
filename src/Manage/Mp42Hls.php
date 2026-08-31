<?php

namespace Xiaosongshu\Flv2mp4\Manage;

use Xiaosongshu\Flv2mp4\Flv\FlvTag;

/**
 * @purpose MP4 转 HLS
 * @author yanglong
 * @time 2026年8月31日11:32:56
 */
class Mp42Hls
{
    private string $inputFile;
    private string $outputDir;
    private $converter;
    private $inputHandle;
    private int $inputSize;
    private $boxTree;
    private $videoTrack;
    private $audioTrack;
    private string $sps;
    private string $pps;
    private ?string $audioSpecificConfig;
    private int $audioSampleRate;
    private int $audioChannels;
    private int $videoWidth;
    private int $videoHeight;

    public function __construct(string $inputFile, string $outputDir)
    {
        $this->inputFile = $inputFile;
        $this->outputDir = $outputDir;
        $this->converter = new Mp4ToFlv($inputFile, $outputDir);
        $this->inputHandle = fopen($inputFile, 'rb');
        if ($this->inputHandle === false) {
            throw new \RuntimeException("无法读取MP4文件: {$inputFile}");
        }
        $stat = fstat($this->inputHandle);
        $this->inputSize = (int)($stat['size'] ?? 0);
        if ($this->inputSize < 8) {
            fclose($this->inputHandle);
            throw new \RuntimeException('MP4文件为空或不完整');
        }
    }

    public function run(): array
    {
        $reflection = new \ReflectionClass($this->converter);
        $this->setProperty($reflection, 'inputHandle', $this->inputHandle);
        $this->setProperty($reflection, 'inputSize', $this->inputSize);
        $this->invoke($reflection, 'parseMp4Boxes');
        $this->invoke($reflection, 'parseTracks');

        $this->boxTree = $this->getProperty($reflection, 'boxTree');
        $this->videoTrack = $this->getProperty($reflection, 'videoTrack');
        $this->audioTrack = $this->getProperty($reflection, 'audioTrack');
        $this->sps = $this->getProperty($reflection, 'sps');
        $this->pps = $this->getProperty($reflection, 'pps');
        $this->audioSpecificConfig = $this->getProperty($reflection, 'audioSpecificConfig');
        $this->audioSampleRate = (int)$this->getProperty($reflection, 'audioSampleRate');
        $this->audioChannels = (int)$this->getProperty($reflection, 'audioChannels');
        $this->videoWidth = (int)$this->getProperty($reflection, 'videoWidth');
        $this->videoHeight = (int)$this->getProperty($reflection, 'videoHeight');

        $streamId = md5(basename($this->getInputFile()));
        $streamDir = rtrim($this->getOutputDir(), '/\\') . DIRECTORY_SEPARATOR . $streamId . DIRECTORY_SEPARATOR;
        $this->clearOutput($streamDir);
        $hls = new Flv2Hls($streamId, [
            'segmentDuration' => 4,
            'outputDir' => rtrim($this->getOutputDir(), '/\\') . DIRECTORY_SEPARATOR . $streamId . DIRECTORY_SEPARATOR,
        ]);

        try {
            $this->emitHeaders($hls);
            $this->emitSamples($reflection, $hls);
            $hls->close();
            return ['index' => $hls->getIndex(), 'outputDir' => $hls->getStreamDir()];
        } finally {
            fclose($this->inputHandle);
            $this->inputHandle = null;
        }
    }

    private function emitHeaders(Flv2Hls $hls): void
    {
        if ($this->sps !== '') {
            $record = "\x01" . ($this->sps[1] ?? "\x42") . ($this->sps[2] ?? "\x00") . ($this->sps[3] ?? "\x1F") . "\xFF" .
                "\xE1" . pack('n', strlen($this->sps)) . $this->sps . "\x01" . pack('n', strlen($this->pps)) . $this->pps;
            $this->emit($hls, 9, "\x17\x00\x00\x00\x00" . $record, 0);
        }
        if ($this->audioSpecificConfig !== null && $this->audioSpecificConfig !== '') {
            $rate = match ($this->audioSampleRate) {
                5512 => 0, 11025 => 1, 22050 => 2, 44100, 48000 => 3, default => 3,
            };
            $audioHeader = (10 << 4) | ($rate << 2) | (1 << 1) | ($this->audioChannels === 2 ? 1 : 0);
            $this->emit($hls, 8, chr($audioHeader) . "\x00" . $this->audioSpecificConfig, 0);
        }
    }

    private function emitSamples(\ReflectionClass $reflection, Flv2Hls $hls): void
    {
        $video = $this->samples($reflection, $this->videoTrack, 'vide');
        $audio = $this->samples($reflection, $this->audioTrack, 'soun');
        $vi = 0; $ai = 0; $vn = count($video); $an = count($audio);
        while ($vi < $vn || $ai < $an) {
            $isVideo = $vi < $vn && ($ai >= $an || $video[$vi]['dtsMs'] <= $audio[$ai]['dtsMs']);
            $sample = $isVideo ? $video[$vi++] : $audio[$ai++];
            $data = $this->readAt($sample['offset'], $sample['size']);
            if ($isVideo) {
                $frameType = $sample['keyframe'] ? "\x17" : "\x27";
                $cts = $sample['ctsMs'];
                $cts &= 0xFFFFFF;
                $body = $frameType . "\x01" . chr(($cts >> 16) & 255) . chr(($cts >> 8) & 255) . chr($cts & 255) . $data;
                $this->emit($hls, 9, $body, $sample['dtsMs']);
            } else {
                $rate = match ($this->audioSampleRate) { 5512 => 0, 11025 => 1, 22050 => 2, 44100, 48000 => 3, default => 3 };
                $header = (10 << 4) | ($rate << 2) | (1 << 1) | ($this->audioChannels === 2 ? 1 : 0);
                $this->emit($hls, 8, chr($header) . "\x01" . $data, $sample['dtsMs']);
            }
        }
    }

    private function samples(\ReflectionClass $reflection, ?array $track, string $type): array
    {
        if ($track === null) return [];
        $trak = $this->findBox($this->boxTree, 'trak', $track['id']);
        if ($trak === null) return [];
        $stbl = $this->findNested($trak, 'stbl');
        return $stbl === null ? [] : $this->invoke($reflection, 'extractSamplesFromStbl', [$stbl, $type]);
    }

    private function findBox(array $boxes, string $type, int $trackId): ?array
    {
        foreach ($boxes as $box) {
            if ($box['type'] === $type && $type === 'trak') {
                $tkhd = $this->findNested($box, 'tkhd');
                if ($tkhd && unpack('N', substr($tkhd['data'], 12, 4))[1] === $trackId) return $box;
            }
            if (!empty($box['children'])) { $found = $this->findBox($box['children'], $type, $trackId); if ($found) return $found; }
        }
        return null;
    }

    private function findNested(array $box, string $type): ?array
    {
        foreach ($box['children'] ?? [] as $child) { if ($child['type'] === $type) return $child; $found = $this->findNested($child, $type); if ($found) return $found; }
        return null;
    }

    private function readAt(int $offset, int $length): string
    {
        fseek($this->inputHandle, $offset); $data = '';
        while (strlen($data) < $length) { $part = fread($this->inputHandle, $length - strlen($data)); if ($part === false || $part === '') throw new \RuntimeException('MP4数据读取不完整'); $data .= $part; }
        return $data;
    }

    private function emit(Flv2Hls $hls, int $type, string $body, int $timestamp): void
    {
        $timestamp = max(0, $timestamp);
        $tag = new FlvTag();
        $tag->tagType = $type;
        $tag->body = $body;
        $tag->Timestamp = chr(($timestamp >> 16) & 0xFF)
            . chr(($timestamp >> 8) & 0xFF)
            . chr($timestamp & 0xFF)
            . chr(($timestamp >> 24) & 0xFF);
        $hls->processFrame($tag);
    }

    private function clearOutput(string $streamDir): void
    {
        if (!is_dir($streamDir)) return;
        foreach (glob($streamDir . '*') as $file) {
            if (is_file($file)) @unlink($file);
        }
    }

    private function getInputFile(): string { return $this->inputFile; }
    private function getOutputDir(): string { return $this->outputDir; }
    private function getProperty(\ReflectionClass $r, string $name): mixed { $p = $r->getProperty($name); $p->setAccessible(true); return $p->getValue($this->converter); }
    private function setProperty(\ReflectionClass $r, string $name, mixed $value): void { $p = $r->getProperty($name); $p->setAccessible(true); $p->setValue($this->converter, $value); }
    private function invoke(\ReflectionClass $r, string $name, array $args = []): mixed { $m = $r->getMethod($name); $m->setAccessible(true); return $m->invokeArgs($this->converter, $args); }
}
