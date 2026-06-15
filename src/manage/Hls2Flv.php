<?php

namespace Xiaosongshu\Flv2mp4\manage;

/**
 * @purpose HLS转FLV工具（修复版）
 * @author yanglong
 * @time 2026年6月3日
 */
class Hls2Flv
{
    private $outputFile;
    private $flvHandle;
    private $sps = '';
    private $pps = '';
    private $audioSpecificConfig = '';
    private $hasWrittenHeader = false;
    private $hasWrittenVideoHeader = false;
    private $hasWrittenAudioHeader = false;
    private $pesBuffers = [];
    private $videoFrames = [];  // 缓存视频帧，按DTS排序
    private $audioFrames = [];  // 缓存音频帧，按PTS排序
    private $videoBuffer = '';  // 视频PES数据缓冲区
    private $videoBufferDts = null;  // 缓冲区数据的DTS
    private $videoBufferPts = null;  // 缓冲区数据的PTS
    private $lastVideoTimestamp = 0;
    private $lastAudioTimestamp = 0;
    private $videoBaseTimestamp = null;
    private $audioBaseTimestamp = null;
    private $firstTimestamp = null;

    /**
     * 构造函数
     * @param string $outputFile 输出的FLV文件路径
     */
    public function __construct(string $outputFile)
    {
        $this->outputFile = $outputFile;
        $dir = dirname($outputFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
    }

    /**
     * 运行转换
     * @param string $m3u8File M3U8文件路径
     * @return bool
     */
    public function run(string $m3u8File): bool
    {
        if (!file_exists($m3u8File)) {
            throw new \RuntimeException("M3U8文件不存在: {$m3u8File}");
        }

        $tsFiles = $this->parseM3U8($m3u8File);
        if (empty($tsFiles)) {
            throw new \RuntimeException("未找到TS文件");
        }

        $this->flvHandle = fopen($this->outputFile, 'wb');
        if (!$this->flvHandle) {
            throw new \RuntimeException("无法创建输出文件: {$this->outputFile}");
        }

        try {
            $m3u8Dir = dirname($m3u8File);
            foreach ($tsFiles as $tsFile) {
                $tsPath = $m3u8Dir . DIRECTORY_SEPARATOR . $tsFile;
                if (file_exists($tsPath)) {
                    $this->processTSFile($tsPath);
                }
            }

            // 确保写入FLV头
            if (!$this->hasWrittenHeader) {
                $this->writeFLVHeader();
            }

            // 写入缓存的帧数据
            $this->flushFrames();

            return true;
        } finally {
            fclose($this->flvHandle);
        }
    }

    /**
     * 解析M3U8文件
     * @param string $m3u8File
     * @return array
     */
    private function parseM3U8(string $m3u8File): array
    {
        $content = file_get_contents($m3u8File);
        $lines = explode("\n", $content);
        $tsFiles = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || strpos($line, '#') === 0) {
                continue;
            }
            if (pathinfo($line, PATHINFO_EXTENSION) === 'ts') {
                $tsFiles[] = $line;
            }
        }

        return $tsFiles;
    }

    /**
     * 处理TS文件
     * @param string $tsFile
     * @return void
     */
    private function processTSFile(string $tsFile): void
    {
        $tsData = file_get_contents($tsFile);
        $offset = 0;
        $len = strlen($tsData);

        while ($offset + 188 <= $len) {
            $packet = substr($tsData, $offset, 188);
            $this->processTSPacket($packet);
            $offset += 188;
        }
    }

    /**
     * 处理单个TS包
     * @param string $packet
     * @return void
     */
    private function processTSPacket(string $packet): void
    {
        if (strlen($packet) < 4) return;

        $syncByte = ord($packet[0]);
        if ($syncByte !== 0x47) return;

        $pid = ((ord($packet[1]) & 0x1F) << 8) | ord($packet[2]);
        $afc = (ord($packet[3]) >> 4) & 0x03;

        $payloadOffset = 4;

        if ($afc === 2 || $afc === 3) {
            $adaptationLength = ord($packet[$payloadOffset]);
            $payloadOffset += 1 + $adaptationLength;
        }

        if ($afc === 0 || $afc === 2) {
            return;
        }

        if ($payloadOffset >= 188) return;

        $payload = substr($packet, $payloadOffset);
        $pusi = (ord($packet[1]) >> 6) & 0x01;

        $this->processPESPayload($pid, $payload, $pusi === 1);
    }

    /**
     * 处理PES负载
     * @param int $pid
     * @param string $payload
     * @param bool $isStart
     * @return void
     */
    private function processPESPayload(int $pid, string $payload, bool $isStart): void
    {
        if (!isset($this->pesBuffers[$pid])) {
            $this->pesBuffers[$pid] = '';
        }

        if ($isStart) {
            if (!empty($this->pesBuffers[$pid])) {
                $this->processCompletePES($this->pesBuffers[$pid]);
            }
            $this->pesBuffers[$pid] = $payload;
        } else {
            $this->pesBuffers[$pid] .= $payload;
        }
    }

    /**
     * 处理完整的PES包
     * @param string $pesData
     * @return void
     */
    private function processCompletePES(string $pesData): void
    {
        if (strlen($pesData) < 6) return;

        $packetStartCode = substr($pesData, 0, 3);
        if ($packetStartCode !== "\x00\x00\x01") return;

        $streamId = ord($pesData[3]);
        $headerLength = 6;
        $pts = null;
        $dts = null;

        if (strlen($pesData) >= 9) {
            $ptsDtsFlags = ord($pesData[7]) >> 6;
            $headerDataLength = ord($pesData[8]);
            $headerLength += 3 + $headerDataLength;

            if ($headerDataLength > 0 && strlen($pesData) >= $headerLength) {
                $timestampData = substr($pesData, 9, $headerDataLength);
                $offset = 0;

                if ($ptsDtsFlags & 0x02) {
                    $pts = $this->decodeTimestamp(substr($timestampData, $offset, 5));
                    $offset += 5;
                }

                if ($ptsDtsFlags & 0x01) {
                    $dts = $this->decodeTimestamp(substr($timestampData, $offset, 5));
                }
            }
        }

        $payload = substr($pesData, $headerLength);

        if ($streamId >= 0xE0 && $streamId <= 0xEF) {
            $this->processVideoPES($payload, $pts, $dts);
        } elseif ($streamId >= 0xC0 && $streamId <= 0xDF) {
            $this->processAudioPES($payload, $pts);
        }
    }

    /**
     * 解码时间戳
     * @param string $data
     * @return int
     */
    private function decodeTimestamp(string $data): int
    {
        if (strlen($data) < 5) return 0;

        $b0 = ord($data[0]);
        $b1 = ord($data[1]);
        $b2 = ord($data[2]);
        $b3 = ord($data[3]);
        $b4 = ord($data[4]);

        $ts = (($b0 >> 1) & 0x07) << 30;
        $ts |= $b1 << 22;
        $ts |= (($b2 >> 1) & 0x7F) << 15;
        $ts |= $b3 << 7;
        $ts |= (($b4 >> 1) & 0x7F);

        return $ts;
    }

    /**
     * 处理视频PES
     * @param string $payload
     * @param int|null $pts
     * @param int|null $dts
     * @return void
     */
    private function processVideoPES(string $payload, ?int $pts, ?int $dts): void
    {
        if (empty($payload)) return;

        // 更新缓冲区的时间戳（使用第一个非空时间戳）
        if ($this->videoBufferDts === null && $dts !== null) {
            $this->videoBufferDts = $dts;
        }
        if ($this->videoBufferPts === null && $pts !== null) {
            $this->videoBufferPts = $pts;
        }

        // 将当前PES数据追加到缓冲区
        $this->videoBuffer .= $payload;

        // 尝试从缓冲区中提取完整的访问单元
        $this->extractCompleteAccessUnits();
    }

    /**
     * 从缓冲区中提取完整的访问单元
     * 访问单元必须至少包含一个slice NALU（类型1-5）
     */
    private function extractCompleteAccessUnits(): void
    {
        if (empty($this->videoBuffer)) return;

        // 提取NALU及其在原始数据中的位置信息
        $naluInfos = $this->extractNalusWithPositions($this->videoBuffer);
        if (empty($naluInfos)) return;

        // 先处理SPS/PPS（这些是序列参数，不应该放在访问单元中）
        foreach ($naluInfos as $info) {
            $nalu = $info['nalu'];
            if (strlen($nalu) < 1) continue;
            $naluType = ord($nalu[0]) & 0x1F;

            if ($naluType === 7 && empty($this->sps)) {
                $this->sps = $nalu;
            } elseif ($naluType === 8 && empty($this->pps)) {
                $this->pps = $nalu;
            }
        }

        // 检查并写入头信息
        if (!$this->hasWrittenHeader && !empty($this->sps) && !empty($this->pps)) {
            $this->writeFLVHeader();
        }

        if ($this->hasWrittenHeader && !$this->hasWrittenVideoHeader && !empty($this->sps) && !empty($this->pps)) {
            $this->writeAVCSequenceHeader();
        }

        // 寻找完整的访问单元
        // 访问单元结构：[AUD] [SEI] [slice(s)]
        // 遇到AUD(9)或IDR slice(5)表示新的访问单元开始
        // 一个访问单元包含从当前开始标记到下一个开始标记之前的所有NALU
        $accessUnits = [];
        $currentAU = [];
        $currentAUHasSlice = false;
        $auStartOffset = 0;
        $firstAU = true;

        foreach ($naluInfos as $idx => $info) {
            $nalu = $info['nalu'];
            $naluOffset = $info['offset'];
            if (strlen($nalu) < 1) continue;
            $naluType = ord($nalu[0]) & 0x1F;

            // 跳过SPS/PPS，它们不应该出现在访问单元中
            if ($naluType === 7 || $naluType === 8) {
                // 更新起始偏移
                $auStartOffset = $naluOffset + $info['startCodeLen'] + strlen($nalu);
                continue;
            }

            // AUD(9)或IDR slice(5)表示新的访问单元开始
            // 如果当前已有未完成的访问单元，先保存它
            if (($naluType === 9 || $naluType === 5) && !$firstAU && !empty($currentAU)) {
                if ($currentAUHasSlice) {
                    $accessUnits[] = $currentAU;
                }
                $currentAU = [];
                $currentAUHasSlice = false;
                // 记录新AU的起始位置
                $auStartOffset = $naluOffset;
            }
            $firstAU = false;

            // 添加当前NALU到访问单元
            $currentAU[] = $nalu;

            // slice类型: 1-5 (P/B/I/SI/SP slice)
            if ($naluType >= 1 && $naluType <= 5) {
                $currentAUHasSlice = true;
            }
        }

        // 添加最后一个访问单元（如果包含slice）
        if ($currentAUHasSlice && !empty($currentAU)) {
            $accessUnits[] = $currentAU;
        }

        // 输出所有完整的访问单元
        foreach ($accessUnits as $au) {
            $this->outputAccessUnit($au);
        }

        // 更新缓冲区：保留未输出的数据（如果有）
        if (!empty($accessUnits)) {
            // 找到最后一个输出的AU结束位置
            $lastAuEndOffset = 0;
            foreach ($naluInfos as $info) {
                $lastAuEndOffset = $info['offset'] + $info['startCodeLen'] + strlen($info['nalu']);
            }
            // 如果还有未处理的数据，保留到缓冲区
            if ($lastAuEndOffset < strlen($this->videoBuffer)) {
                $this->videoBuffer = substr($this->videoBuffer, $lastAuEndOffset);
            } else {
                $this->videoBuffer = '';
                $this->videoBufferDts = null;
                $this->videoBufferPts = null;
            }
        }
        // 否则保留缓冲区中的数据（纯辅助数据）与下一个PES包合并
    }

    /**
     * 从AnnexB格式提取NALU及其位置信息
     * @param string $data
     * @return array
     */
    private function extractNalusWithPositions(string $data): array
    {
        $naluInfos = [];
        $offset = 0;
        $len = strlen($data);

        while ($offset < $len) {
            // 查找起始码
            $pos4 = strpos($data, "\x00\x00\x00\x01", $offset);
            $pos3 = strpos($data, "\x00\x00\x01", $offset);
            
            // 选择最早出现的起始码
            if ($pos4 !== false && $pos3 !== false) {
                $pos = min($pos4, $pos3);
                $startCodeLen = ($pos === $pos4) ? 4 : 3;
            } elseif ($pos4 !== false) {
                $pos = $pos4;
                $startCodeLen = 4;
            } elseif ($pos3 !== false) {
                $pos = $pos3;
                $startCodeLen = 3;
            } else {
                // 没有找到起始码，检查是否还有剩余数据
                if ($offset < $len) {
                    $remaining = substr($data, $offset);
                    if (strlen($remaining) > 0) {
                        // 没有起始码的数据，可能是截断的NALU，保留到下次处理
                        break;
                    }
                }
                break;
            }

            // 查找下一个起始码位置
            $nextPos4 = strpos($data, "\x00\x00\x00\x01", $pos + $startCodeLen);
            $nextPos3 = strpos($data, "\x00\x00\x01", $pos + $startCodeLen);
            
            if ($nextPos4 !== false && $nextPos3 !== false) {
                $nextPos = min($nextPos4, $nextPos3);
            } elseif ($nextPos4 !== false) {
                $nextPos = $nextPos4;
            } elseif ($nextPos3 !== false) {
                $nextPos = $nextPos3;
            } else {
                $nextPos = $len;
            }

            $naluStart = $pos + $startCodeLen;
            $naluEnd = $nextPos;

            if ($naluStart < $naluEnd) {
                $naluInfos[] = [
                    'nalu' => substr($data, $naluStart, $naluEnd - $naluStart),
                    'offset' => $pos,
                    'startCodeLen' => $startCodeLen
                ];
            }

            $offset = $nextPos;
        }

        return $naluInfos;
    }

    /**
     * 输出一个完整的访问单元
     * @param array $nalus
     */
    private function outputAccessUnit(array $nalus): void
    {
        if (empty($nalus) || !$this->hasWrittenHeader) return;

        $isKeyFrame = false;
        foreach ($nalus as $nalu) {
            if (strlen($nalu) < 1) continue;
            $naluType = ord($nalu[0]) & 0x1F;
            if ($naluType === 5) {
                $isKeyFrame = true;
                break;
            }
        }

        $dts = $this->videoBufferDts;
        $pts = $this->videoBufferPts;
        if ($dts === null) {
            $dts = $pts;
        }
        if ($pts === null) {
            $pts = $dts;
        }

        // 缓存帧数据，按DTS排序
        $this->videoFrames[] = [
            'nalus' => $nalus,
            'isKeyFrame' => $isKeyFrame,
            'dts' => $dts,
            'pts' => $pts
        ];
    }

    /**
     * 从AnnexB格式提取NALU
     * @param string $data
     * @return array
     */
    private function extractNalusFromAnnexB(string $data): array
    {
        $nalus = [];
        $offset = 0;
        $len = strlen($data);

        while ($offset < $len) {
            $pos = strpos($data, "\x00\x00\x00\x01", $offset);
            if ($pos === false) {
                $pos = strpos($data, "\x00\x00\x01", $offset);
            }

            if ($pos === false) {
                if ($offset < $len) {
                    $remaining = substr($data, $offset);
                    if (strlen($remaining) > 0) {
                        $nalus[] = $remaining;
                    }
                }
                break;
            }

            $startCodeLen = (substr($data, $pos, 4) === "\x00\x00\x00\x01") ? 4 : 3;
            $nextPos = strpos($data, "\x00\x00\x00\x01", $pos + $startCodeLen);
            if ($nextPos === false) {
                $nextPos = strpos($data, "\x00\x00\x01", $pos + $startCodeLen);
            }

            $naluStart = $pos + $startCodeLen;
            $naluEnd = ($nextPos !== false) ? $nextPos : $len;

            if ($naluStart < $naluEnd) {
                $nalus[] = substr($data, $naluStart, $naluEnd - $naluStart);
            }

            $offset = $naluEnd;
        }

        return $nalus;
    }

    /**
     * 处理音频PES
     * @param string $payload
     * @param int|null $pts
     * @return void
     */
    private function processAudioPES(string $payload, ?int $pts): void
    {
        if (empty($payload)) return;

        $offset = 0;
        $len = strlen($payload);

        while ($offset + 7 <= $len) {
            $syncWord = substr($payload, $offset, 2);
            if ($syncWord !== "\xFF\xF1" && $syncWord !== "\xFF\xF9") {
                $offset++;
                continue;
            }

            $adtsHeader = substr($payload, $offset, 7);
            $frameLength = ((ord($adtsHeader[3]) & 0x03) << 11) | (ord($adtsHeader[4]) << 3) | ((ord($adtsHeader[5]) >> 5) & 0x07);

            if ($frameLength < 7) {
                $offset++;
                continue;
            }

            $aacData = substr($payload, $offset + 7, $frameLength - 7);

            if (empty($this->audioSpecificConfig)) {
                $this->extractAudioSpecificConfig($adtsHeader);
            }

            // 检查并写入头信息
            if (!$this->hasWrittenHeader && !empty($this->sps) && !empty($this->pps)) {
                $this->writeFLVHeader();
            }

            if ($this->hasWrittenHeader && !$this->hasWrittenAudioHeader && !empty($this->audioSpecificConfig)) {
                $this->writeAACSequenceHeader();
            }

            if (!empty($aacData) && $this->hasWrittenHeader && $pts !== null) {
                // 缓存音频帧
                $this->audioFrames[] = [
                    'data' => $aacData,
                    'pts' => $pts
                ];
            }

            $offset += $frameLength;
        }
    }

    /**
     * 从ADTS头提取AudioSpecificConfig
     * @param string $adtsHeader
     * @return void
     */
    private function extractAudioSpecificConfig(string $adtsHeader): void
    {
        $profile = ((ord($adtsHeader[2]) >> 6) & 0x03) + 1;
        $freqIndex = ((ord($adtsHeader[2]) >> 2) & 0x0F);
        $channelConfig = ((ord($adtsHeader[2]) & 0x01) << 2) | ((ord($adtsHeader[3]) >> 6) & 0x03);

        $asc = ($profile << 11) | ($freqIndex << 7) | ($channelConfig << 3);
        $this->audioSpecificConfig = pack('n', $asc);
    }

    /**
     * 刷新缓存的帧数据
     */
    private function flushFrames(): void
    {
        // 处理缓冲区中剩余的数据
        if (!empty($this->videoBuffer)) {
            $nalus = $this->extractNalusFromAnnexB($this->videoBuffer);
            if (!empty($nalus)) {
                // 检查是否包含slice
                $hasSlice = false;
                foreach ($nalus as $nalu) {
                    if (strlen($nalu) >= 1) {
                        $naluType = ord($nalu[0]) & 0x1F;
                        if ($naluType >= 1 && $naluType <= 5) {
                            $hasSlice = true;
                            break;
                        }
                    }
                }
                if ($hasSlice) {
                    $this->outputAccessUnit($nalus);
                }
                // 如果不包含slice，直接丢弃这些纯辅助数据
            }
            $this->videoBuffer = '';
            $this->videoBufferDts = null;
            $this->videoBufferPts = null;
        }

        // 按DTS排序视频帧
        usort($this->videoFrames, function($a, $b) {
            return $a['dts'] - $b['dts'];
        });

        // 按PTS排序音频帧
        usort($this->audioFrames, function($a, $b) {
            return $a['pts'] - $b['pts'];
        });

        // 设置基准时间戳
        if (!empty($this->videoFrames) || !empty($this->audioFrames)) {
            $minTimestamp = PHP_INT_MAX;

            foreach ($this->videoFrames as $frame) {
                if ($frame['dts'] < $minTimestamp) {
                    $minTimestamp = $frame['dts'];
                }
            }

            foreach ($this->audioFrames as $frame) {
                if ($frame['pts'] < $minTimestamp) {
                    $minTimestamp = $frame['pts'];
                }
            }

            $this->firstTimestamp = $minTimestamp;
        }

        // 写入视频帧
        $videoIndex = 0;
        $audioIndex = 0;

        while ($videoIndex < count($this->videoFrames) || $audioIndex < count($this->audioFrames)) {
            $writeVideo = false;
            $writeAudio = false;

            if ($videoIndex < count($this->videoFrames) && $audioIndex < count($this->audioFrames)) {
                $videoDts = $this->videoFrames[$videoIndex]['dts'];
                $audioPts = $this->audioFrames[$audioIndex]['pts'];

                // 比较时间戳，选择较小的写入
                if ($videoDts <= $audioPts) {
                    $writeVideo = true;
                } else {
                    $writeAudio = true;
                }
            } elseif ($videoIndex < count($this->videoFrames)) {
                $writeVideo = true;
            } elseif ($audioIndex < count($this->audioFrames)) {
                $writeAudio = true;
            }

            if ($writeVideo) {
                $frame = $this->videoFrames[$videoIndex];
                $dts = $frame['dts'];
                $pts = $frame['pts'] ?? $dts;

                $timestamp = (int)(($dts - $this->firstTimestamp) / 90);
                $cts = (int)(($pts - $dts) / 90);

                // 确保时间戳不递减
                $timestamp = max($timestamp, $this->lastVideoTimestamp);
                $this->lastVideoTimestamp = $timestamp;

                $this->writeVideoFrame($frame['nalus'], $frame['isKeyFrame'], $timestamp, $cts);
                $videoIndex++;
            }

            if ($writeAudio) {
                $frame = $this->audioFrames[$audioIndex];
                $pts = $frame['pts'];

                $timestamp = (int)(($pts - $this->firstTimestamp) / 90);

                // 确保时间戳不递减
                $timestamp = max($timestamp, $this->lastAudioTimestamp);
                $this->lastAudioTimestamp = $timestamp;

                $this->writeAudioFrame($frame['data'], $timestamp);
                $audioIndex++;
            }
        }
    }

    /**
     * 写入FLV头部
     * @return void
     */
    private function writeFLVHeader(): void
    {
        if ($this->hasWrittenHeader) return;

        $flags = 0;
        if (!empty($this->sps) && !empty($this->pps)) $flags |= 0x01;
        if (!empty($this->audioSpecificConfig)) $flags |= 0x04;

        $header = "FLV\x01" . chr($flags) . "\x00\x00\x00\x09";
        fwrite($this->flvHandle, $header);
        fwrite($this->flvHandle, "\x00\x00\x00\x00");

        $this->hasWrittenHeader = true;
    }

    /**
     * 写入AVC序列头
     * @return void
     */
    private function writeAVCSequenceHeader(): void
    {
        if (empty($this->sps) || empty($this->pps) || $this->hasWrittenVideoHeader) return;

        $avcConfig = "\x01";
        $avcConfig .= substr($this->sps, 1, 3);
        $avcConfig .= "\xFF\xE1";
        $avcConfig .= pack('n', strlen($this->sps));
        $avcConfig .= $this->sps;
        $avcConfig .= "\x01";
        $avcConfig .= pack('n', strlen($this->pps));
        $avcConfig .= $this->pps;

        $videoData = "\x17\x00\x00\x00\x00" . $avcConfig;

        $this->writeFLVTag(9, $videoData, 0);
        $this->hasWrittenVideoHeader = true;
    }

    /**
     * 写入AAC序列头
     * @return void
     */
    private function writeAACSequenceHeader(): void
    {
        if (empty($this->audioSpecificConfig) || $this->hasWrittenAudioHeader) return;

        $audioData = "\xAF\x00" . $this->audioSpecificConfig;
        $this->writeFLVTag(8, $audioData, 0);
        $this->hasWrittenAudioHeader = true;
    }

    /**
     * 写入视频帧
     * @param array $nalus
     * @param bool $isKeyFrame
     * @param int $timestamp
     * @param int $cts
     * @return void
     */
    private function writeVideoFrame(array $nalus, bool $isKeyFrame, int $timestamp, int $cts): void
    {
        $codecId = 7;
        $frameType = $isKeyFrame ? 1 : 2;
        $frameType = ($frameType << 4) | $codecId;

        $avccData = $this->annexbToAvcc($nalus);

        $cts = max(0, $cts);
        $ctsBytes = chr(($cts >> 16) & 0xFF) . chr(($cts >> 8) & 0xFF) . chr($cts & 0xFF);
        $videoData = chr($frameType) . "\x01" . $ctsBytes . $avccData;

        $this->writeFLVTag(9, $videoData, $timestamp);
    }

    /**
     * 写入音频帧
     * @param string $aacData
     * @param int $timestamp
     * @return void
     */
    private function writeAudioFrame(string $aacData, int $timestamp): void
    {
        $soundFormat = 10;
        $soundRate = 3;
        $soundSize = 1;
        $soundType = 1;

        $audioHeader = (($soundFormat << 4) | ($soundRate << 2) | ($soundSize << 1) | $soundType);
        $audioData = chr($audioHeader) . "\x01" . $aacData;

        $this->writeFLVTag(8, $audioData, $timestamp);
    }

    /**
     * AnnexB转AVCC
     * @param array $nalus
     * @return string
     */
    private function annexbToAvcc(array $nalus): string
    {
        $result = '';
        foreach ($nalus as $nalu) {
            if (strlen($nalu) > 0) {
                $result .= pack('N', strlen($nalu)) . $nalu;
            }
        }
        return $result;
    }

    /**
     * 写入FLV Tag
     * @param int $tagType
     * @param string $data
     * @param int $timestamp
     * @return void
     */
    private function writeFLVTag(int $tagType, string $data, int $timestamp): void
    {
        $dataSize = strlen($data);

        // 确保时间戳在有效范围内（0-16777215，即24位）
        $timestamp = $timestamp & 0xFFFFFF;
        $timestampExtended = ($timestamp >> 24) & 0xFF;
        $timestampLower = $timestamp & 0xFFFFFF;

        $tagHeader = chr($tagType);
        $tagHeader .= chr(($dataSize >> 16) & 0xFF);
        $tagHeader .= chr(($dataSize >> 8) & 0xFF);
        $tagHeader .= chr($dataSize & 0xFF);
        $tagHeader .= chr(($timestampLower >> 16) & 0xFF);
        $tagHeader .= chr(($timestampLower >> 8) & 0xFF);
        $tagHeader .= chr($timestampLower & 0xFF);
        $tagHeader .= chr($timestampExtended);
        $tagHeader .= "\x00\x00\x00";

        fwrite($this->flvHandle, $tagHeader);
        fwrite($this->flvHandle, $data);
        fwrite($this->flvHandle, pack('N', 11 + $dataSize));
    }
}