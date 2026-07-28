<?php

namespace Xiaosongshu\Flv2mp4\Recode;

use Xiaosongshu\Flv2mp4\Codec\H264Decoder;
use Xiaosongshu\Flv2mp4\Codec\H264Encoder;
use Xiaosongshu\Flv2mp4\Codec\NalUtil;
use Xiaosongshu\Flv2mp4\Codec\Scaler\VideoScaler;

/**
 * @purpose mp4重编码（改变分辨率/码率）
 * @author yanglong
 * @time 2026年7月28日
 * @note 本转码器目前仅支持baseline profile
 */
class Mp4Recoder
{
    private int $targetWidth = 0;
    private int $targetHeight = 0;
    private int $targetBitrate = 0;
    private int $targetFps = 30;
    private int $targetQp = 26;

    private bool $watermarkEnabled = false;
    private string $watermarkFile = '';
    private string $wmY = '';
    private string $wmU = '';
    private string $wmV = '';
    private int $wmWidth = 0;
    private int $wmHeight = 0;

    private ?H264Decoder $decoder = null;
    private ?H264Encoder $encoder = null;
    private ?VideoScaler $scaler = null;

    private string $mp4Data = '';
    private array $boxTree = [];

    private ?array $videoTrack = null;
    private ?array $audioTrack = null;

    private int $srcWidth = 0;
    private int $srcHeight = 0;
    private bool $srcInitialized = false;

    private string $srcSpsData = '';
    private string $srcPpsData = '';

    private string $audioSpecificConfig = '';
    private bool $audioConfigParsed = false;
    private int $audioSampleRate = 44100;
    private int $audioChannels = 2;
    private int $audioObjectType = 2;

    private array $videoSamples = [];
    private array $audioSamples = [];

    private int $videoTimescale = 90000;
    private int $audioTimescale = 44100;
    private int $duration = 0;

    private string $encSpsAnnexB = '';
    private string $encPpsAnnexB = '';
    private string $encAvccHeader = '';

    private int $videoFrameCount = 0;
    private ?int $maxFrames = null;

    private int $outputVideoWidth = 0;
    private int $outputVideoHeight = 0;

    public function __construct(array $config = [])
    {
        $this->targetWidth = $config['width'] ?? 0;
        $this->targetHeight = $config['height'] ?? 0;
        $this->targetBitrate = $config['bitrate'] ?? 0;
        $this->targetFps = $config['fps'] ?? 30;
        $this->targetQp = $config['qp'] ?? 26;

        if (!empty($config['watermark']) && !empty($config['watermark_file'])) {
            $this->watermarkEnabled = true;
            $this->watermarkFile = $config['watermark_file'];
            $this->loadWatermark();
        }

        $this->decoder = new H264Decoder();
        $this->encoder = new H264Encoder();
        $this->scaler = new VideoScaler();
    }

    private function loadWatermark(): void
    {
        if (!file_exists($this->watermarkFile)) {
            throw new \RuntimeException("水印文件不存在: {$this->watermarkFile}");
        }

        $wmData = file_get_contents($this->watermarkFile);
        if ($wmData === false || $wmData === '') {
            throw new \RuntimeException("无法读取水印文件: {$this->watermarkFile}");
        }

        $basename = basename($this->watermarkFile, '.yuv');
        if (preg_match('/_(\d+)x(\d+)$/', $basename, $matches)) {
            $this->wmWidth = (int)$matches[1];
            $this->wmHeight = (int)$matches[2];
        } else {
            $this->wmWidth = 80;
            $this->wmHeight = 16;
        }

        $wmYSize = $this->wmWidth * $this->wmHeight;
        $wmUvSize = $wmYSize >> 2;
        $expectedSize = $wmYSize + $wmUvSize * 2;

        if (strlen($wmData) < $expectedSize) {
            throw new \RuntimeException("水印文件尺寸不匹配: 期望 {$expectedSize} 字节, 实际 " . strlen($wmData) . " 字节");
        }

        $this->wmY = substr($wmData, 0, $wmYSize);
        $this->wmU = substr($wmData, $wmYSize, $wmUvSize);
        $this->wmV = substr($wmData, $wmYSize + $wmUvSize, $wmUvSize);
    }

    private function applyWatermark(string $yuvData, int $frameW, int $frameH): string
    {
        if ($this->wmWidth > $frameW || $this->wmHeight > $frameH) {
            return $yuvData;
        }

        $ySize = $frameW * $frameH;
        $uvW = $frameW >> 1;
        $uvH = $frameH >> 1;
        $uvSize = $uvW * $uvH;

        $dstX = 0;
        $dstY = 0;

        for ($row = 0; $row < $this->wmHeight; $row++) {
            $srcOffset = $row * $this->wmWidth;
            $dstOffset = ($dstY + $row) * $frameW + $dstX;
            for ($col = 0; $col < $this->wmWidth; $col++) {
                $yuvData[$dstOffset + $col] = $this->wmY[$srcOffset + $col];
            }
        }

        $wmUvW = $this->wmWidth >> 1;
        $wmUvH = $this->wmHeight >> 1;
        $dstUvX = $dstX >> 1;
        $dstUvY = $dstY >> 1;

        $uOffset = $ySize;
        $vOffset = $ySize + $uvSize;

        for ($row = 0; $row < $wmUvH; $row++) {
            $srcOffset = $row * $wmUvW;
            $dstOffset = ($dstUvY + $row) * $uvW + $dstUvX;
            for ($col = 0; $col < $wmUvW; $col++) {
                $yuvData[$uOffset + $dstOffset + $col] = $this->wmU[$srcOffset + $col];
                $yuvData[$vOffset + $dstOffset + $col] = $this->wmV[$srcOffset + $col];
            }
        }

        return $yuvData;
    }

    public function setMaxFrames(int $maxFrames): void
    {
        $this->maxFrames = $maxFrames;
    }

    public function processMp4(string $inputFile, string $outputFile): void
    {
        if (!file_exists($inputFile)) {
            throw new \RuntimeException("MP4文件不存在: {$inputFile}");
        }

        $outputDir = dirname($outputFile);
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0777, true);
        }

        $this->mp4Data = file_get_contents($inputFile);
        if (empty($this->mp4Data)) {
            throw new \RuntimeException("无法读取MP4文件");
        }

        $this->parseMp4Boxes();
        $this->parseTracks();
        $this->extractAndTranscodeMediaData();

        if (!$this->videoTrack && !$this->audioTrack) {
            throw new \RuntimeException("未找到有效的视频或音频轨道");
        }

        $this->buildMp4($outputFile);

        echo "Done! Output: {$outputFile}\n";
        echo "Output size: " . filesize($outputFile) . " bytes\n";
    }

    /* ========== MP4 Box 解析 ========== */

    private function parseMp4Boxes(): void
    {
        $this->boxTree = $this->parseBox($this->mp4Data, 0, strlen($this->mp4Data));
    }

    private function parseBox(string $data, int $offset, int $end): array
    {
        $boxes = [];
        while ($offset + 8 <= $end) {
            $size = unpack('N', substr($data, $offset, 4))[1];
            $type = substr($data, $offset + 4, 4);
            if ($size == 1) {
                if ($offset + 16 <= $end) {
                    $size = unpack('J', substr($data, $offset + 8, 8))[1];
                    $headerSize = 16;
                } else break;
            } elseif ($size == 0) {
                $size = $end - $offset;
                $headerSize = 8;
            } else {
                $headerSize = 8;
            }
            $boxEnd = $offset + $size;
            if ($boxEnd > $end) break;
            $boxData = substr($data, $offset + $headerSize, $size - $headerSize);
            $box = ['type' => $type, 'size' => $size, 'offset' => $offset, 'data' => $boxData, 'children' => []];
            if ($size > $headerSize) {
                $box['children'] = $this->parseBox($data, $offset + $headerSize, $boxEnd);
            }
            $boxes[] = $box;
            $offset = $boxEnd;
        }
        return $boxes;
    }

    private function findBox(array $boxes, string $type): ?array
    {
        foreach ($boxes as $box) {
            if ($box['type'] === $type) return $box;
            if (!empty($box['children'])) {
                $found = $this->findBox($box['children'], $type);
                if ($found !== null) return $found;
            }
        }
        return null;
    }

    private function findAllBoxes(array $boxes, string $type): array
    {
        $result = [];
        foreach ($boxes as $box) {
            if ($box['type'] === $type) $result[] = $box;
            if (!empty($box['children'])) {
                $result = array_merge($result, $this->findAllBoxes($box['children'], $type));
            }
        }
        return $result;
    }

    /* ========== 轨道解析 ========== */

    private function parseTracks(): void
    {
        $moov = $this->findBox($this->boxTree, 'moov');
        if (!$moov) throw new \RuntimeException("未找到moov盒子");

        $mvhd = $this->findBox([$moov], 'mvhd');
        if ($mvhd) {
            $mvhdData = $mvhd['data'];
            $version = ord($mvhdData[0]);
            if ($version == 0) {
                $timescale = unpack('N', substr($mvhdData, 12, 4))[1];
                $duration = unpack('N', substr($mvhdData, 16, 4))[1];
            } else {
                $timescale = unpack('N', substr($mvhdData, 20, 4))[1];
                $duration = unpack('J', substr($mvhdData, 24, 8))[1];
            }
            $this->duration = $duration;
            $this->videoTimescale = $timescale;
        }

        $traks = $this->findAllBoxes([$moov], 'trak');
        foreach ($traks as $trak) {
            $this->parseTrack($trak);
        }
    }

    private function parseTrack(array $trak): void
    {
        $tkhd = $this->findBox([$trak], 'tkhd');
        if (!$tkhd) return;
        $trackId = unpack('N', substr($tkhd['data'], 12, 4))[1];
        $mdia = $this->findBox([$trak], 'mdia');
        if (!$mdia) return;
        $hdlr = $this->findBox([$mdia], 'hdlr');
        if (!$hdlr) return;
        $handlerType = substr($hdlr['data'], 8, 4);

        $minf = $this->findBox([$mdia], 'minf');
        if (!$minf) return;
        $stbl = $this->findBox([$minf], 'stbl');
        if (!$stbl) return;
        $stsd = $this->findBox([$stbl], 'stsd');
        if (!$stsd) return;
        $mdhd = $this->findBox([$mdia], 'mdhd');
        $timescale = 90000;
        if ($mdhd) {
            $mdhdData = $mdhd['data'];
            $version = ord($mdhdData[0]);
            if ($version == 0) {
                $timescale = unpack('N', substr($mdhdData, 12, 4))[1];
            } else {
                $timescale = unpack('N', substr($mdhdData, 20, 4))[1];
            }
        }
        $stsdData = $stsd['data'];
        $pos = 8;
        while ($pos + 8 <= strlen($stsdData)) {
            $entrySize = unpack('N', substr($stsdData, $pos, 4))[1];
            $entryType = substr($stsdData, $pos + 4, 4);
            if ($handlerType === 'vide' && $entryType === 'avc1') {
                $this->videoTrack = ['id' => $trackId, 'type' => 'video', 'codec' => 'avc1', 'timescale' => $timescale, 'trak' => $trak];
                $this->parseAvcCFromBox(substr($stsdData, $pos, $entrySize));
                break;
            } elseif ($handlerType === 'soun' && $entryType === 'mp4a') {
                $this->audioTrack = ['id' => $trackId, 'type' => 'audio', 'codec' => 'mp4a', 'timescale' => $timescale, 'trak' => $trak];
                $this->parseEsdsFromBox(substr($stsdData, $pos, $entrySize));
                break;
            }
            $pos += $entrySize;
        }
    }

    private function parseAvcCFromBox(string $data): void
    {
        $pos = strpos($data, 'avcC');
        if ($pos === false || $pos < 4) return;
        $boxSize = unpack('N', substr($data, $pos - 4, 4))[1];
        $avcCData = substr($data, $pos + 4, $boxSize - 8);
        $this->parseAvcC($avcCData);
    }

    private function parseAvcC(string $data): void
    {
        if (strlen($data) < 8) return;
        $numSps = ord($data[5]) & 0x1F;
        $offset = 6;
        for ($i = 0; $i < $numSps; $i++) {
            if ($offset + 2 > strlen($data)) break;
            $spsLength = unpack('n', substr($data, $offset, 2))[1];
            $offset += 2;
            if ($offset + $spsLength > strlen($data)) break;
            $spsData = substr($data, $offset, $spsLength);
            $offset += $spsLength;
            if (!$this->srcInitialized) {
                $spsClean = NalUtil::removeEmulationPrevention($spsData);
                $rbspSps = substr($spsClean, 1);
                $this->srcSpsData = $rbspSps;
                $this->decoder->decode([['type' => 7, 'data' => $rbspSps]], true);
                $this->srcWidth = $this->decoder->getWidth();
                $this->srcHeight = $this->decoder->getHeight();
                $this->srcInitialized = true;
            }
            break;
        }
        $numPps = ord($data[$offset]); $offset++;
        for ($i = 0; $i < $numPps; $i++) {
            if ($offset + 2 > strlen($data)) break;
            $ppsLength = unpack('n', substr($data, $offset, 2))[1];
            $offset += 2;
            if ($offset + $ppsLength > strlen($data)) break;
            $ppsData = substr($data, $offset, $ppsLength);
            $ppsClean = NalUtil::removeEmulationPrevention($ppsData);
            $rbspPps = substr($ppsClean, 1);
            $this->srcPpsData = $rbspPps;
            if (!$this->srcInitialized) {
                $this->decoder->decode([['type' => 8, 'data' => $rbspPps]]);
            }
            break;
        }
    }

    private function parseEsdsFromBox(string $data): void
    {
        $pos = strpos($data, 'esds');
        if ($pos === false || $pos < 4) return;
        $boxSize = unpack('N', substr($data, $pos - 4, 4))[1];
        $esdsData = substr($data, $pos + 4, $boxSize - 8);
        $this->parseEsds($esdsData);
    }

    private function parseEsds(string $data, bool $hasFullBoxHeader = true): void
    {
        $len = strlen($data);
        if ($len < 2) return;
        $pos = $hasFullBoxHeader ? 4 : 0;
        while ($pos + 2 <= $len) {
            $tag = ord($data[$pos]);
            $pos++;
            if ($pos >= $len) break;
            $length = 0;
            while ($pos < $len) {
                $byte = ord($data[$pos]);
                $pos++;
                $length = ($length << 7) | ($byte & 0x7F);
                if (($byte & 0x80) == 0) break;
            }
            if ($pos + $length > $len) break;
            if ($tag == 0x05) {
                $this->audioSpecificConfig = substr($data, $pos, $length);
                $this->parseAudioSpecificConfig($this->audioSpecificConfig);
                $this->audioConfigParsed = true;
                return;
            }
            if ($tag == 0x03) {
                $skipBytes = 3;
                if ($length > $skipBytes) {
                    $this->parseEsds(substr($data, $pos + $skipBytes, $length - $skipBytes), false);
                }
            } elseif ($tag == 0x04) {
                $skipBytes = 13;
                if ($length > $skipBytes) {
                    $this->parseEsds(substr($data, $pos + $skipBytes, $length - $skipBytes), false);
                }
            }
            $pos += $length;
        }
    }

    private function parseAudioSpecificConfig(string $config): void
    {
        $len = strlen($config);
        if ($len < 2) return;
        $bytes = unpack('n', substr($config, 0, 2))[1];
        $this->audioObjectType = ($bytes >> 11) & 0x1F;
        $freqIndex = ($bytes >> 7) & 0x0F;
        $channelConfig = ($bytes >> 3) & 0x0F;

        $rates = [96000, 88200, 64000, 48000, 44100, 32000, 24000, 22050, 16000, 12000, 11025, 8000, 7350];
        $baseRate = $rates[$freqIndex] ?? 44100;

        switch ($this->audioObjectType) {
            case 2:
                $this->audioSampleRate = $baseRate;
                $this->audioChannels = $channelConfig;
                break;
            case 5:
            case 29:
                $this->audioSampleRate = $baseRate * 2;
                $this->audioChannels = ($this->audioObjectType == 29) ? 2 : $channelConfig;
                break;
            default:
                $this->audioSampleRate = $baseRate;
                $this->audioChannels = $channelConfig;
                break;
        }
        $this->audioTimescale = $this->audioSampleRate;
    }

    /* ========== 媒体数据提取与转码 ========== */

    private function extractAndTranscodeMediaData(): void
    {
        $mdat = $this->findBox($this->boxTree, 'mdat');
        if (!$mdat) throw new \RuntimeException("未找到mdat盒子");
        $moov = $this->findBox($this->boxTree, 'moov');
        if (!$moov) return;

        $allSamples = [];

        if ($this->videoTrack) {
            $videoSamples = $this->extractVideoSamples();
            foreach ($videoSamples as &$s) {
                $s['type'] = 'video';
            }
            unset($s);
            $allSamples = array_merge($allSamples, $videoSamples);
        }

        if ($this->audioTrack) {
            $audioSamples = $this->extractAudioSamples();
            foreach ($audioSamples as &$s) {
                $s['type'] = 'audio';
            }
            unset($s);
            $allSamples = array_merge($allSamples, $audioSamples);
        }

        usort($allSamples, function($a, $b) {
            return $a['dtsMs'] - $b['dtsMs'];
        });

        $videoCount = 0;
        foreach ($allSamples as $sample) {
            if ($sample['type'] === 'video') {
                $this->transcodeVideoSample($sample);
                $videoCount++;
                if ($this->maxFrames !== null && $videoCount >= $this->maxFrames) {
                    echo "Reached max frames limit ({$this->maxFrames})\n";
                    break;
                }
                if ($videoCount % 10 === 0) {
                    echo "Processed {$videoCount} video frames\n";
                }
            } else {
                if ($this->maxFrames !== null && $videoCount >= $this->maxFrames) continue;
                $this->audioSamples[] = [
                    'data' => $sample['data'],
                    'timestamp' => $sample['dtsMs'],
                ];
            }
        }
    }

    private function extractVideoSamples(): array
    {
        $trak = $this->videoTrack['trak'];
        $timescale = $this->videoTrack['timescale'];
        return $this->extractSamplesFromTrak($trak, $timescale, 'video');
    }

    private function extractAudioSamples(): array
    {
        $trak = $this->audioTrack['trak'];
        $timescale = $this->audioTrack['timescale'];
        return $this->extractSamplesFromTrak($trak, $timescale, 'audio');
    }

    private function extractSamplesFromTrak(array $trak, int $timescale, string $type): array
    {
        $mdia = $this->findBox([$trak], 'mdia');
        if (!$mdia) return [];
        $minf = $this->findBox([$mdia], 'minf');
        if (!$minf) return [];
        $stbl = $this->findBox([$minf], 'stbl');
        if (!$stbl) return [];

        $stsz = $this->findBox([$stbl], 'stsz');
        $stco = $this->findBox([$stbl], 'stco');
        $stsc = $this->findBox([$stbl], 'stsc');
        $stts = $this->findBox([$stbl], 'stts');
        $ctts = $this->findBox([$stbl], 'ctts');
        $stss = $this->findBox([$stbl], 'stss');

        if (!$stsz || !$stco || !$stsc || !$stts) return [];

        $stszData = $stsz['data'];
        $sampleSize = unpack('N', substr($stszData, 4, 4))[1];
        $sampleCount = unpack('N', substr($stszData, 8, 4))[1];
        $sampleSizes = [];
        if ($sampleSize == 0) {
            for ($i = 0; $i < $sampleCount; $i++) {
                $sampleSizes[] = unpack('N', substr($stszData, 12 + $i * 4, 4))[1];
            }
        } else {
            $sampleSizes = array_fill(0, $sampleCount, $sampleSize);
        }

        $stcoData = $stco['data'];
        $chunkCount = unpack('N', substr($stcoData, 4, 4))[1];
        $chunkOffsets = [];
        for ($i = 0; $i < $chunkCount; $i++) {
            $chunkOffsets[] = unpack('N', substr($stcoData, 8 + $i * 4, 4))[1];
        }

        $stscData = $stsc['data'];
        $stscEntries = unpack('N', substr($stscData, 4, 4))[1];
        $chunkMap = [];
        for ($i = 0; $i < $stscEntries; $i++) {
            $firstChunk = unpack('N', substr($stscData, 8 + $i * 12, 4))[1];
            $samplesPerChunk = unpack('N', substr($stscData, 12 + $i * 12, 4))[1];
            $chunkMap[$firstChunk] = $samplesPerChunk;
        }

        $chunkSamples = [];
        for ($chunk = 0; $chunk < $chunkCount; $chunk++) {
            $chunkNum = $chunk + 1;
            $samples = 1;
            foreach ($chunkMap as $firstChunk => $spc) {
                if ($chunkNum >= $firstChunk) $samples = $spc;
            }
            $chunkSamples[] = $samples;
        }

        $sampleOffsets = [];
        $sampleIndex = 0;
        foreach ($chunkOffsets as $chunkIdx => $chunkOffset) {
            $count = $chunkSamples[$chunkIdx];
            $runningOffset = $chunkOffset;
            for ($j = 0; $j < $count && $sampleIndex < count($sampleSizes); $j++) {
                $sampleOffsets[$sampleIndex] = $runningOffset;
                $runningOffset += $sampleSizes[$sampleIndex];
                $sampleIndex++;
            }
        }

        $sttsData = $stts['data'];
        $sttsEntries = unpack('N', substr($sttsData, 4, 4))[1];
        $timeDeltas = [];
        $pos = 8;
        for ($i = 0; $i < $sttsEntries; $i++) {
            $count = unpack('N', substr($sttsData, $pos, 4))[1];
            $delta = unpack('N', substr($sttsData, $pos + 4, 4))[1];
            $pos += 8;
            for ($j = 0; $j < $count; $j++) {
                $timeDeltas[] = $delta;
            }
        }

        $ctOffsets = [];
        if ($ctts && $type === 'video') {
            $cttsData = $ctts['data'];
            $cttsEntries = unpack('N', substr($cttsData, 4, 4))[1];
            $pos = 8;
            for ($i = 0; $i < $cttsEntries; $i++) {
                $count = unpack('N', substr($cttsData, $pos, 4))[1];
                $offset = unpack('N', substr($cttsData, $pos + 4, 4))[1];
                $pos += 8;
                for ($j = 0; $j < $count; $j++) {
                    $ctOffsets[] = $offset;
                }
            }
        }

        $keyframeSet = [];
        if ($stss && $type === 'video') {
            $stssData = $stss['data'];
            $entries = unpack('N', substr($stssData, 4, 4))[1];
            for ($i = 0; $i < $entries; $i++) {
                $keyframeSet[unpack('N', substr($stssData, 8 + $i * 4, 4))[1] - 1] = true;
            }
        }

        $samples = [];
        $dtsTicks = 0;
        for ($i = 0; $i < count($sampleSizes) && $i < count($sampleOffsets); $i++) {
            $offset = $sampleOffsets[$i];
            if ($offset < 0 || $offset + $sampleSizes[$i] > strlen($this->mp4Data)) continue;
            $rawData = substr($this->mp4Data, $offset, $sampleSizes[$i]);

            $ctsTicks = $ctOffsets[$i] ?? 0;
            $dtsMs = (int)round($dtsTicks * 1000 / $timescale);
            $ctsMs = (int)round($ctsTicks * 1000 / $timescale);
            $isKeyframe = isset($keyframeSet[$i]);

            $samples[] = [
                'data' => $rawData,
                'dtsMs' => $dtsMs,
                'ctsMs' => $ctsMs,
                'keyframe' => $isKeyframe,
                'index' => $i,
            ];

            $dtsTicks += $timeDeltas[$i] ?? 0;
        }
        return $samples;
    }

    private function transcodeVideoSample(array $sample): void
    {
        $avcData = $sample['data'];
        $isKeyFrame = $sample['keyframe'];
        $dtsMs = $sample['dtsMs'];
        $ctsMs = $sample['ctsMs'];

        $targetW = $this->targetWidth > 0 ? $this->targetWidth : $this->srcWidth;
        $targetH = $this->targetHeight > 0 ? $this->targetHeight : $this->srcHeight;

        $needTranscode = $this->srcInitialized && (
            $this->targetWidth > 0 && $this->srcWidth !== $this->targetWidth ||
            $this->targetHeight > 0 && $this->srcHeight !== $this->targetHeight ||
            $this->targetBitrate > 0
        );

        if ($needTranscode) {
            $yuvData = $this->decodeNaluToYuv($avcData);
            if ($yuvData === null) return;

            if ($targetW !== $this->srcWidth || $targetH !== $this->srcHeight) {
                $yuvData = $this->scaler->scaleYUV420P(
                    $yuvData,
                    $this->srcWidth, $this->srcHeight,
                    $targetW, $targetH
                );
            }

            if ($this->watermarkEnabled) {
                $yuvData = $this->applyWatermark($yuvData, $targetW, $targetH);
            }

            $this->encoder->setResolution($targetW, $targetH);
            if ($this->targetBitrate > 0) {
                $this->encoder->setBitrate($this->targetBitrate);
            } else {
                $this->encoder->setQp($this->targetQp);
            }
            $this->encoder->setFps($this->targetFps);

            $encodedNals = $this->encoder->encodeFrame($yuvData, $isKeyFrame);
            $this->videoFrameCount++;

            if (empty($this->videoSamples)) {
                $this->extractSpsPpsFromNals($encodedNals);
                $this->buildEncAvccHeader();
                $this->outputVideoWidth = $targetW;
                $this->outputVideoHeight = $targetH;
            }

            $videoAvcc = $this->extractVideoAvccFromNals($encodedNals);
            if ($videoAvcc === '') return;

            $frameIndex = count($this->videoSamples);
            $transcodeTimestamp = (int)($frameIndex * 1000 / $this->targetFps);

            $this->videoSamples[] = [
                'data' => $videoAvcc,
                'timestamp' => $transcodeTimestamp,
                'cts' => 0,
                'keyframe' => $isKeyFrame,
            ];
        } else {
            if (empty($this->videoSamples)) {
                $this->outputVideoWidth = $this->srcWidth;
                $this->outputVideoHeight = $this->srcHeight;
                $this->buildSrcAvccHeader();
            }
            $this->videoSamples[] = [
                'data' => $avcData,
                'timestamp' => $dtsMs,
                'cts' => $ctsMs,
                'keyframe' => $isKeyFrame,
            ];
        }
    }

    private function decodeNaluToYuv(string $avcData): ?string
    {
        $nalUnits = $this->extractNalUnitsFromAVCC($avcData);

        if ($this->srcSpsData !== '') {
            array_unshift($nalUnits, ['type' => 7, 'data' => $this->srcSpsData]);
        }
        if ($this->srcPpsData !== '') {
            array_unshift($nalUnits, ['type' => 8, 'data' => $this->srcPpsData]);
        }

        $frame = $this->decoder->decode($nalUnits);
        if ($frame && !empty($frame['data'])) {
            return $frame['data'];
        }
        return null;
    }

    private function extractNalUnitsFromAVCC(string $data): array
    {
        $list = [];
        $offset = 0;
        $totalLen = strlen($data);
        while ($offset + 4 <= $totalLen) {
            $nalSize = unpack('N', substr($data, $offset, 4))[1];
            $offset += 4;
            if ($offset + $nalSize > $totalLen) break;
            $nalRaw = substr($data, $offset, $nalSize);
            $offset += $nalSize;

            $nalClean = NalUtil::removeEmulationPrevention($nalRaw);
            $type = ord($nalClean[0]) & 0x1F;
            $rbspData = substr($nalClean, 1);
            $list[] = ['type' => $type, 'data' => $rbspData, 'raw' => $nalClean];
        }
        return $list;
    }

    private function extractSpsPpsFromNals(array $nals): void
    {
        foreach ($nals as $nal) {
            $nalHeaderPos = $this->findNalHeaderPos($nal);
            if ($nalHeaderPos < 0) continue;

            $nalRaw = substr($nal, $nalHeaderPos);
            $nalType = ord($nalRaw[0]) & 0x1F;

            if ($nalType === 7) {
                $this->encSpsAnnexB = $nal;
            } elseif ($nalType === 8) {
                $this->encPpsAnnexB = $nal;
            }
        }
    }

    private function extractVideoAvccFromNals(array $nals): string
    {
        $videoNalsAnnexB = '';
        foreach ($nals as $nal) {
            $nalHeaderPos = $this->findNalHeaderPos($nal);
            if ($nalHeaderPos < 0) continue;
            $nalRaw = substr($nal, $nalHeaderPos);
            $nalType = ord($nalRaw[0]) & 0x1F;
            if ($nalType === 7 || $nalType === 8) continue;
            $videoNalsAnnexB .= $nal;
        }
        if ($videoNalsAnnexB === '') return '';
        return $this->annexBToAvcc($videoNalsAnnexB);
    }

    private function annexBToAvcc(string $annexBuf): string
    {
        $nals = NalUtil::splitNalUnits($annexBuf);
        $result = '';
        foreach ($nals as $nal) {
            $nalRaw = $nal['raw'];
            $nalLen = strlen($nalRaw);
            $result .= pack('N', $nalLen) . $nalRaw;
        }
        return $result;
    }

    private function findNalHeaderPos(string $nal): int
    {
        $len = strlen($nal);
        if ($len < 4) return -1;
        if (substr($nal, 0, 4) === "\x00\x00\x00\x01") return 4;
        if ($len >= 3 && substr($nal, 0, 3) === "\x00\x00\x01") return 3;
        return -1;
    }

    private function buildEncAvccHeader(): void
    {
        $spsAnnexB = $this->encSpsAnnexB;
        $ppsAnnexB = $this->encPpsAnnexB;

        $spsPos = $this->findNalHeaderPos($spsAnnexB);
        $ppsPos = $this->findNalHeaderPos($ppsAnnexB);

        if ($spsPos < 0 || $ppsPos < 0) {
            $this->buildSrcAvccHeader();
            return;
        }

        $spsRaw = substr($spsAnnexB, $spsPos);
        $ppsRaw = substr($ppsAnnexB, $ppsPos);

        $spsLen = strlen($spsRaw);
        $ppsLen = strlen($ppsRaw);

        $config = '';
        $config .= chr(1);
        $config .= $spsLen > 1 ? $spsRaw[1] : chr(0);
        $config .= $spsLen > 2 ? $spsRaw[2] : chr(0);
        $config .= $spsLen > 3 ? $spsRaw[3] : chr(0);
        $config .= chr(0xFF);
        $config .= chr(0xE0 | 1);
        $config .= pack('n', $spsLen);
        $config .= $spsRaw;
        $config .= chr(1);
        $config .= pack('n', $ppsLen);
        $config .= $ppsRaw;

        $this->encAvccHeader = $config;
    }

    private function buildSrcAvccHeader(): void
    {
        $spsClean = NalUtil::removeEmulationPrevention("\x67" . $this->srcSpsData);
        $ppsClean = NalUtil::removeEmulationPrevention("\x68" . $this->srcPpsData);

        $spsLen = strlen($spsClean);
        $ppsLen = strlen($ppsClean);

        $config = '';
        $config .= chr(1);
        $config .= $spsLen > 1 ? $spsClean[1] : chr(0);
        $config .= $spsLen > 2 ? $spsClean[2] : chr(0);
        $config .= $spsLen > 3 ? $spsClean[3] : chr(0);
        $config .= chr(0xFF);
        $config .= chr(0xE0 | 1);
        $config .= pack('n', $spsLen);
        $config .= $spsClean;
        $config .= chr(1);
        $config .= pack('n', $ppsLen);
        $config .= $ppsClean;

        $this->encAvccHeader = $config;
    }

    /* ========== MP4 构建 ========== */

    private function buildMp4(string $outputFile): void
    {
        $this->sortAndNormalizeSamples();

        $ftyp = $this->buildFtyp();
        $mdat = $this->buildMdat();

        $ftypSize = strlen($ftyp);

        $videoTotalSize = 0;
        foreach ($this->videoSamples as $sample) {
            $videoTotalSize += strlen($sample['data']);
        }

        $stcoVideoBase = $ftypSize + 10000;
        $stcoAudioBase = $stcoVideoBase + $videoTotalSize;

        $moov = $this->buildMoov($stcoVideoBase, $stcoAudioBase);

        $moovSize = strlen($moov);
        $mdatHeaderSize = 8;
        $actualStcoVideoBase = $ftypSize + $moovSize + $mdatHeaderSize;
        $actualStcoAudioBase = $actualStcoVideoBase + $videoTotalSize;

        if ($stcoVideoBase != $actualStcoVideoBase) {
            $moov = $this->buildMoov($actualStcoVideoBase, $actualStcoAudioBase);
        }

        file_put_contents($outputFile, $ftyp . $moov . $mdat);
    }

    private function sortAndNormalizeSamples(): void
    {
        usort($this->videoSamples, function($a, $b) {
            if ($a['timestamp'] != $b['timestamp']) {
                return $a['timestamp'] - $b['timestamp'];
            }
            return 0;
        });

        $prevTimestamp = -1;
        foreach ($this->videoSamples as &$sample) {
            if ($sample['timestamp'] <= $prevTimestamp) {
                $sample['timestamp'] = $prevTimestamp + 1;
            }
            $prevTimestamp = $sample['timestamp'];
        }
        unset($sample);

        if (!empty($this->videoSamples)) {
            $baseTs = $this->videoSamples[0]['timestamp'];
            foreach ($this->videoSamples as &$sample) {
                $sample['timestamp'] -= $baseTs;
            }
            unset($sample);
        }

        usort($this->audioSamples, function($a, $b) {
            if ($a['timestamp'] != $b['timestamp']) {
                return $a['timestamp'] - $b['timestamp'];
            }
            return 0;
        });
    }

    private function calculateDuration(): int
    {
        $maxVideoDts = 0;
        $maxAudioDts = 0;

        foreach ($this->videoSamples as $sample) {
            if ($sample['timestamp'] > $maxVideoDts) $maxVideoDts = $sample['timestamp'];
        }

        foreach ($this->audioSamples as $sample) {
            if ($sample['timestamp'] > $maxAudioDts) $maxAudioDts = $sample['timestamp'];
        }

        $maxDtsMs = max($maxVideoDts, $maxAudioDts);
        return (int)($maxDtsMs * $this->videoTimescale / 1000);
    }

    private function buildFtyp(): string
    {
        $data = pack('C*',
            0x69, 0x73, 0x6F, 0x6D, 0x00, 0x00, 0x00, 0x01,
            0x69, 0x73, 0x6F, 0x6D, 0x61, 0x76, 0x63, 0x31,
            0x6D, 0x70, 0x34, 0x32, 0x00, 0x00, 0x00, 0x01,
            0x6D, 0x70, 0x34, 0x31
        );
        return $this->box('ftyp', $data);
    }

    private function buildMoov(int $stcoVideoBase = 0, int $stcoAudioBase = 0): string
    {
        $duration = $this->calculateDuration();
        $mvhd = $this->buildMvhd($this->videoTimescale, $duration);
        $tracks = [];

        if (!empty($this->videoSamples)) {
            $tracks[] = $this->buildVideoTrak($stcoVideoBase);
        }
        if (!empty($this->audioSamples)) {
            $tracks[] = $this->buildAudioTrak($stcoAudioBase);
        }

        return $this->box('moov', $mvhd, ...$tracks);
    }

    private function buildMvhd(int $timescale, int $duration): string
    {
        $data = pack('C*',
            0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,
            ($timescale>>24)&0xFF, ($timescale>>16)&0xFF, ($timescale>>8)&0xFF, $timescale&0xFF,
            ($duration>>24)&0xFF, ($duration>>16)&0xFF, ($duration>>8)&0xFF, $duration&0xFF,
            0x00,0x01,0x00,0x00,0x01,0x00,0x00,0x00,0x00,0x00,0x00,0x00,
            0x00,0x00,0x00,0x00,0x00,0x01,0x00,0x00,0x00,0x00,0x00,0x00,
            0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x01,0x00,0x00,
            0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,
            0x40,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,
            0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,
            0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0xFF,0xFF,0xFF,0xFF
        );
        return $this->box('mvhd', $data);
    }

    private function buildVideoTrak(int $stcoBase = 0): string
    {
        $trackId = 1;
        $numSamples = count($this->videoSamples);
        $duration = ($numSamples > 0) ? $this->calculateDuration() : 0;

        $tkhd = $this->buildTkhd($trackId, $duration, $this->outputVideoWidth, $this->outputVideoHeight);
        $mdhd = $this->buildMdhd($this->videoTimescale, $duration);
        $hdlr = $this->buildHdlr('vide');
        $minf = $this->buildMinf('video', $stcoBase);

        $mdia = $this->box('mdia', $mdhd, $hdlr, $minf);
        return $this->box('trak', $tkhd, $mdia);
    }

    private function buildAudioTrak(int $stcoBase = 0): string
    {
        $trackId = 2;
        $numSamples = count($this->audioSamples);
        $duration = ($numSamples > 0) ? (int)($this->calculateDuration() * $this->audioTimescale / $this->videoTimescale) : 0;

        $tkhd = $this->buildTkhd($trackId, $duration, 0, 0);
        $mdhd = $this->buildMdhd($this->audioTimescale, $duration);
        $hdlr = $this->buildHdlr('soun');
        $minf = $this->buildMinf('audio', $stcoBase);

        $mdia = $this->box('mdia', $mdhd, $hdlr, $minf);
        return $this->box('trak', $tkhd, $mdia);
    }

    private function buildTkhd(int $trackId, int $duration, int $width, int $height): string
    {
        $fixedWidth = $width << 16;
        $fixedHeight = $height << 16;

        $data = pack('C*',
            0x00,0x00,0x00,0x07,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,
            ($trackId>>24)&0xFF, ($trackId>>16)&0xFF, ($trackId>>8)&0xFF, $trackId&0xFF,
            0x00,0x00,0x00,0x00,
            ($duration>>24)&0xFF, ($duration>>16)&0xFF, ($duration>>8)&0xFF, $duration&0xFF,
            0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,
            0x00,0x00,0x00,0x00,0x00,0x01,0x00,0x00,0x00,0x00,0x00,0x00,
            0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x01,0x00,0x00,
            0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,
            0x40,0x00,0x00,0x00,
            ($fixedWidth>>24)&0xFF, ($fixedWidth>>16)&0xFF, ($fixedWidth>>8)&0xFF, $fixedWidth&0xFF,
            ($fixedHeight>>24)&0xFF, ($fixedHeight>>16)&0xFF, ($fixedHeight>>8)&0xFF, $fixedHeight&0xFF
        );
        return $this->box('tkhd', $data);
    }

    private function buildMdhd(int $timescale, int $duration): string
    {
        $data = pack('C*',
            0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,
            ($timescale>>24)&0xFF, ($timescale>>16)&0xFF, ($timescale>>8)&0xFF, $timescale&0xFF,
            ($duration>>24)&0xFF, ($duration>>16)&0xFF, ($duration>>8)&0xFF, $duration&0xFF,
            0x55,0xC4,0x00,0x00
        );
        return $this->box('mdhd', $data);
    }

    private function buildHdlr(string $type): string
    {
        if ($type === 'vide') {
            $data = pack('C*',
                0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,
                0x76,0x69,0x64,0x65,0x00,0x00,0x00,0x00,
                0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,
                0x56,0x69,0x64,0x65,0x6F,0x48,0x61,0x6E,
                0x64,0x6C,0x65,0x72,0x00
            );
        } else {
            $data = pack('C*',
                0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,
                0x73,0x6F,0x75,0x6E,0x00,0x00,0x00,0x00,
                0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,
                0x53,0x6F,0x75,0x6E,0x64,0x48,0x61,0x6E,
                0x64,0x6C,0x65,0x72,0x00
            );
        }
        return $this->box('hdlr', $data);
    }

    private function buildMinf(string $type, int $stcoBase = 0): string
    {
        if ($type === 'video') {
            $vmhd = pack('C*', 0x00,0x00,0x00,0x01,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00);
            $xmhd = $this->box('vmhd', $vmhd);
        } else {
            $smhd = pack('C*', 0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00);
            $xmhd = $this->box('smhd', $smhd);
        }

        $drefData = pack('N*', 0, 1) . pack('N', 12) . "url " . pack('N', 1);
        $dref = $this->box('dref', $drefData);
        $dinf = $this->box('dinf', $dref);

        $stbl = $this->buildStbl($type, $stcoBase);
        return $this->box('minf', $xmhd, $dinf, $stbl);
    }

    private function buildStbl(string $type, int $stcoBase = 0): string
    {
        if ($type === 'video') {
            $samples = $this->videoSamples;
            $stsd = $this->buildVideoStsd();
            $stts = $this->buildVideoStts();
            $ctts = $this->buildCtts();
            $stsc = $this->buildStsc($samples);
            $stsz = $this->buildStsz($samples);
            $stco = $this->buildVideoStco($stcoBase);
            return $this->box('stbl', $stsd, $stts, $ctts, $stsc, $stsz, $stco);
        } else {
            $samples = $this->audioSamples;
            $stsd = $this->buildAudioStsd();
            $stts = $this->buildAudioStts();
            $stsc = $this->buildStsc($samples);
            $stsz = $this->buildStsz($samples);
            $stco = $this->buildAudioStco($stcoBase);
            return $this->box('stbl', $stsd, $stts, $stsc, $stsz, $stco);
        }
    }

    private function buildVideoStsd(): string
    {
        $avcc = $this->encAvccHeader;
        $avcCBox = $this->box('avcC', $avcc);

        $width = $this->outputVideoWidth ?: 1;
        $height = $this->outputVideoHeight ?: 1;

        $avc1Data = str_repeat("\x00", 6) .
            pack('n', 1) .
            pack('n', 0) . pack('n', 0) . pack('N', 0) . pack('N', 0) . pack('N', 0) .
            pack('n', $width) . pack('n', $height) .
            pack('N', 0x00480000) . pack('N', 0x00480000) . pack('N', 0) .
            pack('n', 1) .
            "\x00" . str_repeat("\x00", 31) .
            pack('n', 0x0018) . pack('n', 0xFFFF);

        $avc1Box = $this->box('avc1', $avc1Data, $avcCBox);
        $stsdPrefix = pack('C*', 0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x01);
        return $this->box('stsd', $stsdPrefix, $avc1Box);
    }

    private function buildAudioStsd(): string
    {
        $channelCount = $this->audioChannels;
        $sampleRate = $this->audioSampleRate;

        $mp4aData = pack('C*',
            0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x01,
            0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,
            0x00,$channelCount,0x00,0x10,0x00,0x00,0x00,0x00,
            ($sampleRate>>8)&0xFF, $sampleRate&0xFF, 0x00,0x00
        );

        $esds = $this->buildEsds();
        $esdsBox = $this->box('esds', $esds);
        $mp4aBox = $this->box('mp4a', $mp4aData, $esdsBox);

        $stsdPrefix = pack('C*', 0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x01);
        return $this->box('stsd', $stsdPrefix, $mp4aBox);
    }

    private function buildEsds(): string
    {
        $config = $this->audioSpecificConfig;
        $configSize = strlen($config);

        $tag5 = "\x05" . $this->writeDescriptorLength($configSize) . $config;

        $tag4Content = "\x40" . chr($this->audioObjectType) . "\x00\x00\x00" .
            "\x00\x00\x00\x00\x00\x00\x00\x00" . $tag5;
        $tag4 = "\x04" . $this->writeDescriptorLength(strlen($tag4Content)) . $tag4Content;

        $tag3Content = "\x00\x01\x00" . $tag4;
        $tag3 = "\x03" . $this->writeDescriptorLength(strlen($tag3Content)) . $tag3Content;

        return pack('N', 0) . $tag3;
    }

    private function writeDescriptorLength(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }
        $result = '';
        while ($length > 0) {
            $byte = $length & 0x7F;
            $length >>= 7;
            if ($result !== '') {
                $byte |= 0x80;
            }
            $result = chr($byte) . $result;
        }
        return $result;
    }

    private function calculateVideoFrameRate(): float
    {
        $samples = $this->videoSamples;
        $count = count($samples);

        if ($count < 2) {
            return $this->targetFps > 0 && $this->targetFps <= 120 ? $this->targetFps : 30;
        }

        $totalInterval = 0;
        $intervals = 0;
        for ($i = 1; $i < $count; $i++) {
            $interval = $samples[$i]['timestamp'] - $samples[$i - 1]['timestamp'];
            if ($interval > 0) {
                $totalInterval += $interval;
                $intervals++;
            }
        }

        if ($intervals == 0) {
            return $this->targetFps > 0 && $this->targetFps <= 120 ? $this->targetFps : 30;
        }

        $avgIntervalMs = $totalInterval / $intervals;
        $fps = 1000 / $avgIntervalMs;

        if ($fps <= 0 || $fps > 120) {
            return $this->targetFps > 0 && $this->targetFps <= 120 ? $this->targetFps : 30;
        }

        return $fps;
    }

    private function buildVideoStts(): string
    {
        $samples = $this->videoSamples;
        $count = count($samples);

        if ($count === 0) {
            return $this->box('stts', pack('N*', 0, 0));
        }

        $data = pack('N', 0);

        $baseTimestamp = PHP_INT_MAX;
        foreach ($samples as $sample) {
            if ($sample['timestamp'] < $baseTimestamp) {
                $baseTimestamp = $sample['timestamp'];
            }
        }

        $entries = [];
        $currentDts = 0;

        foreach ($samples as $sample) {
            $targetDts = (int)(($sample['timestamp'] - $baseTimestamp) * $this->videoTimescale / 1000);
            $delta = $targetDts - $currentDts;
            if ($delta <= 0) $delta = 1;
            $entries[] = ['count' => 1, 'delta' => $delta];
            $currentDts += $delta;
        }

        $data .= pack('N', count($entries));
        foreach ($entries as $entry) {
            $data .= pack('N', $entry['count']);
            $data .= pack('N', $entry['delta']);
        }

        return $this->box('stts', $data);
    }

    private function buildAudioStts(): string
    {
        $samples = $this->audioSamples;
        $count = count($samples);

        if ($count === 0) {
            return $this->box('stts', pack('N*', 0, 0));
        }

        $data = pack('N', 0);

        $baseTimestamp = PHP_INT_MAX;
        foreach ($samples as $sample) {
            if ($sample['timestamp'] < $baseTimestamp) {
                $baseTimestamp = $sample['timestamp'];
            }
        }

        $entries = [];
        $currentDts = 0;

        foreach ($samples as $sample) {
            $targetDts = (int)(($sample['timestamp'] - $baseTimestamp) * $this->audioTimescale / 1000);
            $delta = $targetDts - $currentDts;
            if ($delta <= 0) $delta = 1;
            $entries[] = ['count' => 1, 'delta' => $delta];
            $currentDts += $delta;
        }

        $data .= pack('N', count($entries));
        foreach ($entries as $entry) {
            $data .= pack('N', $entry['count']);
            $data .= pack('N', $entry['delta']);
        }

        return $this->box('stts', $data);
    }

    private function buildCtts(): string
    {
        $samples = $this->videoSamples;
        $count = count($samples);

        if ($count === 0) {
            return $this->box('ctts', pack('N*', 0, 0));
        }

        $data = pack('N', 0);
        $data .= pack('N', $count);

        foreach ($samples as $sample) {
            $cts = (int)(($sample['cts'] ?? 0) * $this->videoTimescale / 1000);
            $data .= pack('N', 1);
            $data .= pack('N', $cts);
        }

        return $this->box('ctts', $data);
    }

    private function buildStsc(array $samples): string
    {
        $count = count($samples);

        if ($count === 0) {
            return $this->box('stsc', pack('N*', 0, 0));
        }

        $data = pack('N', 0);
        $data .= pack('N', $count);

        for ($i = 0; $i < $count; $i++) {
            $data .= pack('N', $i + 1);
            $data .= pack('N', 1);
            $data .= pack('N', 1);
        }

        return $this->box('stsc', $data);
    }

    private function buildStsz(array $samples): string
    {
        $count = count($samples);

        $data = pack('N', 0);
        $data .= pack('N', 0);
        $data .= pack('N', $count);

        foreach ($samples as $sample) {
            $data .= pack('N', strlen($sample['data']));
        }

        return $this->box('stsz', $data);
    }

    private function buildVideoStco(int $baseOffset = 0): string
    {
        $samples = $this->videoSamples;
        $count = count($samples);

        $data = pack('N', 0);
        $data .= pack('N', $count);

        $offset = $baseOffset;
        foreach ($samples as $sample) {
            $data .= pack('N', $offset);
            $offset += strlen($sample['data']);
        }

        return $this->box('stco', $data);
    }

    private function buildAudioStco(int $baseOffset = 0): string
    {
        $samples = $this->audioSamples;
        $count = count($samples);

        $data = pack('N', 0);
        $data .= pack('N', $count);

        $offset = $baseOffset;
        foreach ($samples as $sample) {
            $data .= pack('N', $offset);
            $offset += strlen($sample['data']);
        }

        return $this->box('stco', $data);
    }

    private function buildMdat(): string
    {
        $mdatData = '';

        foreach ($this->videoSamples as $sample) {
            $mdatData .= $sample['data'];
        }
        foreach ($this->audioSamples as $sample) {
            $mdatData .= $sample['data'];
        }

        return $this->box('mdat', $mdatData);
    }

    private function box(string $type, ...$datas): string
    {
        $size = 8;
        foreach ($datas as $data) $size += strlen($data);
        $result = pack('N', $size) . $type;
        foreach ($datas as $data) $result .= $data;
        return $result;
    }
}
