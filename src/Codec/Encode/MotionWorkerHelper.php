<?php

namespace Xiaosongshu\Flv2mp4\Codec\Encode;

/**
 * @purpose 运动模块进程管理辅助函数
 * @author yanglong
 * @time 2026年9月18日11:00:03
 */
final class MotionWorkerHelper
{
    private const INTERP_TAP0 = 1;
    private const INTERP_TAP1 = -5;
    private const INTERP_TAP2 = 20;
    private const INTERP_TAP3 = 20;
    private const INTERP_TAP4 = -5;
    private const INTERP_TAP5 = 1;
    use MotionTrait, TransformTrait, InterPredTrait;
    private const DEQUANT4_COEFF_INIT = [[10,13,16],[11,14,18],[13,16,20],[14,18,23],[16,20,25],[18,23,29]];
    private const QUANT_MF = \Xiaosongshu\Flv2mp4\Codec\H264Encoder::QUANT_MF;
    private const QUANT_INTER_FF = \Xiaosongshu\Flv2mp4\Codec\H264Encoder::QUANT_INTER_FF;
    private const ZIGZAG_SCAN_4X4 = \Xiaosongshu\Flv2mp4\Codec\H264Encoder::ZIGZAG_SCAN_4X4;
    public int $width;
    public int $height;
    public int $mbAlignedWidth;
    public int $mbAlignedHeight;
    public int $qp;
    public array $dequant4Table = [];
    public $refInts = null;
    /** early-skip 开关：静止宏块提前跳过精搜（量化死区内残差归零，不影响正确性） */
    public bool $earlySkip = true;
    private static ?array $sharedDequantTable = null;

    public function __construct(int $width, int $height, int $aw, int $ah, int $qp, private string $refY, private string $refU, private string $refV)
    {
        $this->width = $width;
        $this->height = $height;
        $this->mbAlignedWidth = $aw;
        $this->mbAlignedHeight = $ah;
        $this->qp = $qp;
        if (self::$sharedDequantTable === null) {
            $positionClass = [0,1,0,1,1,2,1,2,0,1,0,1,1,2,1,2];
            $table = array_fill(0, 6, array_fill(0, 52, array_fill(0, 16, 0)));
            for ($i = 0; $i < 6; $i++) for ($q = 0; $q < 52; $q++) {
                $shift = intdiv($q, 6) + 2;
                $index = $q % 6;
                for ($x = 0; $x < 16; $x++) $table[$i][$q][$x] = (self::DEQUANT4_COEFF_INIT[$index][$positionClass[$x]] * 16) << $shift;
            }
            self::$sharedDequantTable = $table;
        }
        $this->dequant4Table = self::$sharedDequantTable;
    }

    public function prepare(array $job): array
    {
        return $this->preparePMacroblock($job[0], $job[1], $job[2], $this->refY, $this->refU, $this->refV, $job[3]);
    }
}