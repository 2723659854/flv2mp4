<?php

namespace Xiaosongshu\Flv2mp4\Pipeline;

use Xiaosongshu\Flv2mp4\Codec\H264Decoder;
use Xiaosongshu\Flv2mp4\Codec\H264Encoder;
use Xiaosongshu\Flv2mp4\Codec\VideoScaler;

class TranscodePipeline
{
    private H264Decoder $decoder;
    private H264Encoder $encoder;
    private VideoScaler $scaler;

    private array $frameCache = [];
    private bool $initialized = false;

    public function __construct()
    {
        $this->decoder = new H264Decoder();
        $this->encoder = new H264Encoder();
        $this->scaler = new VideoScaler();
    }

    public function processFrame(array $flvFrame, array $profiles): array
    {
        $results = [];

        if ($flvFrame['type'] !== 'video') {
            return $results;
        }

        $nalUnits = $this->extractNalUnitsFromAVCC($flvFrame['data']);
        if (empty($nalUnits)) {
            return $results;
        }

        $decoded = $this->decoder->decode($nalUnits);
        if (!$decoded || !isset($decoded['data'])) {
            return $results;
        }

        $width = $decoded['width'];
        $height = $decoded['height'];
        $isKeyframe = $decoded['is_keyframe'] ?? false;
        $yuvData = $decoded['data'];

        if (!$this->initialized && $width > 0 && $height > 0) {
            $this->initialized = true;
        }

        foreach ($profiles as $profileName => $profile) {
            $dstWidth = $profile['width'];
            $dstHeight = $profile['height'];
            $bitrate = $profile['bitrate'];
            $fps = $profile['fps'];

            $scaledYuv = $this->scaler->scaleYUV420P(
                $yuvData,
                $width,
                $height,
                $dstWidth,
                $dstHeight
            );

            $this->encoder->setResolution($dstWidth, $dstHeight);
            $this->encoder->setBitrate($bitrate);
            $this->encoder->setFps($fps);

            $encoded = $this->encoder->encodeFrame($scaledYuv, $isKeyframe);

            $results[$profileName][] = implode('', $encoded);
        }

        return $results;
    }

    private function extractNalUnitsFromAVCC(string $data): array
    {
        $nalUnits = [];
        $offset = 0;
        $len = strlen($data);

        while ($offset + 4 <= $len) {
            $nalSize = unpack('N', substr($data, $offset, 4))[1];
            $offset += 4;

            if ($offset + $nalSize > $len) {
                break;
            }

            $nalData = substr($data, $offset, $nalSize);
            $offset += $nalSize;

            if (strlen($nalData) > 0) {
                $nalType = (ord($nalData[0]) >> 1) & 0x3F;
                $nalUnits[] = [
                    'type' => $nalType,
                    'data' => $nalData,
                    'offset' => $offset - $nalSize,
                    'length' => $nalSize,
                ];
            }
        }

        return $nalUnits;
    }
}