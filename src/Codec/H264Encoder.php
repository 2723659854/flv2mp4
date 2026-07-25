<?php

namespace Xiaosongshu\Flv2mp4\Codec;

use Xiaosongshu\Flv2mp4\Codec\Encode\TablesTrait;
use Xiaosongshu\Flv2mp4\Codec\Encode\BitstreamTrait;
use Xiaosongshu\Flv2mp4\Codec\Encode\TransformTrait;
use Xiaosongshu\Flv2mp4\Codec\Encode\CavlcTrait;
use Xiaosongshu\Flv2mp4\Codec\Encode\IntraPredTrait;
use Xiaosongshu\Flv2mp4\Codec\Encode\MotionTrait;
use Xiaosongshu\Flv2mp4\Codec\Encode\InterPredTrait;
use Xiaosongshu\Flv2mp4\Codec\Encode\SpsPpsTrait;
use Xiaosongshu\Flv2mp4\Codec\Encode\SliceEncodeTrait;

/**
 * @purpose yuv重建h264
 * @author yanglong
 * @time 2026年7月23日14:48:28
 */
class H264Encoder
{
    use TablesTrait, BitstreamTrait, TransformTrait, CavlcTrait, IntraPredTrait, MotionTrait, InterPredTrait, SpsPpsTrait, SliceEncodeTrait;

    public $width = 640;
    public $height = 360;
    public $fps = 30;
    public $bitrate = 500000;
    public $qp = 22;
    public $chromaQpIndexOffset = 0;
    public $mbType = self::MB_TYPE_I16x16;

    // 宏块对齐尺寸（与解码器一致，用于重建帧和参考帧）
    // 解码器使用mbAlignedWidth/Height存储参考帧，编码器必须匹配
    public int $mbAlignedWidth = 0;
    public int $mbAlignedHeight = 0;

    public $frameNum = 0;
    public $idrPicId = 0;
    public $poc = 0;

    public $log2MaxFrameNumMinus4 = 0;
    public $log2MaxPicOrderCntLsbMinus4 = 0;

    public $quantMatrix = [];

    // 反量化表（用于本地解码重建参考帧）
    public $dequant4Table = [];

    // P帧参考帧管理
    public $refYPlane = null;      // 参考帧Y平面（重建后的）
    public $refUPlane = null;      // 参考帧U平面
    public $refVPlane = null;      // 参考帧V平面
    public $refInts = null;        // 参考帧Y平面整数数组缓存（优化运动估计速度）
    public $enableInter = true;   // 是否启用P帧
    public $numRefFrames = 1;      // 参考帧数量
    public $debugStopMbX = -1;     // 调试：编码到此宏块列后停止
    public $debugStopMbY = -1;     // 调试：编码到此宏块行后停止

    // 本地解码重建帧（用于正确更新参考帧，避免编解码器失配）
    public $reconYPlane = '';
    public $reconUPlane = '';
    public $reconVPlane = '';

    // P帧运动向量缓存（与解码器一致的4x4子块粒度存储）
    // mvLeftCol[0..3]: 左邻居宏块右列4个4x4子块的MV，每个元素=[mvX, mvY, refIdx]或null
    // mvTopRow[mbX*4+0..3]: 上邻居宏块底行4个4x4子块的MV，每个元素=[mvX, mvY, refIdx]或null
    public $mvLeftCol = [];
    public $mvTopRow = [];
    public $picWidthInMbs = 0;
    public $lastMbWasSkip = false; // 上一个宏块是否为P_Skip

    private static $ueCache = [];

    public function __construct(int $width = 0, int $height = 0, int $fps = 25, int $bitrate = 1000000)
    {
        $this->width = $width;
        $this->height = $height;
        $this->fps = $fps;
        $this->bitrate = $bitrate;
        $this->refInts = null;
        $this->initQuantMatrix();
    }

    public function setResolution(int $width, int $height): void
    {
        $this->width = $width;
        $this->height = $height;
    }

    public function setQp(int $qp): void
    {
        if ($qp < 0) $qp = 0;
        if ($qp > 51) $qp = 51;
        $this->qp = $qp;
    }

    public function setMbType(int $type): void
    {
        $this->mbType = $type;
    }

    public function setFps(int $fps): void
    {
        $this->fps = $fps;
    }

    public function setBitrate(int $bitrate): void
    {
        $this->bitrate = $bitrate;
        $logBitrate = log(max(100000, $bitrate));
        $logRef = log(100000);
        $logMax = log(10000000);
        $qpRange = 38 - 18;
        $ratio = ($logBitrate - $logRef) / ($logMax - $logRef);
        $this->qp = (int)round(38 - $ratio * $qpRange);
        $this->qp = max(18, min(38, $this->qp));
    }

    public function encodeFrame(string $yuvData, bool $isKeyframe = false): array
    {
        $nalUnits = [];
        if ($isKeyframe) {
            // I帧：重置参考帧和计数器
            $this->refYPlane = null;
            $this->refUPlane = null;
            $this->refVPlane = null;
            $this->frameNum = 0;
            $this->idrPicId++;
            $this->poc = 0;

            $nalUnits[] = $this->generateSPS();
            $nalUnits[] = $this->generatePPS();
        }
        $sliceData = $this->encodeSlice($yuvData, $isKeyframe);
        $nalUnits[] = $sliceData;
        return $nalUnits;
    }

}
