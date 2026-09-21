<?php

namespace Xiaosongshu\Flv2mp4\Codec\Encode;

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
    private static ?array $sharedDequantTable = null;

    /**
     * @param array      $motionOptions 进程级编码选项（early_skip/subpel_sad_mul），
     *                                  由 motion-worker 启动参数注入，不读环境变量
     * @param array|null $seedMap 本批次（当前帧）的前一帧 MV 种子地图
     *                            （w/h/mvs 光栅列表，1/4 像素），null=无地图
     * @param int        $tier1BlockSad v7：本帧 Tier1 经验绝对 SAD 限额（每 4x4 块，0=不放宽，≤4080）
     */
    public function __construct(int $width, int $height, int $aw, int $ah, int $qp, private string $refY, private string $refU, private string $refV, array $motionOptions = [], private ?array $seedMap = null, int $tier1BlockSad = 0)
    {
        $this->width = $width;
        $this->height = $height;
        $this->mbAlignedWidth = $aw;
        $this->mbAlignedHeight = $ah;
        $this->qp = $qp;
        $this->earlySkip = (bool)($motionOptions['early_skip'] ?? true);
        $mul = (float)($motionOptions['subpel_sad_mul'] ?? 4.0);
        $this->subpelSadMul = $mul > 0 ? $mul : 4.0;
        // v7：按帧下发的 Tier1 经验绝对 SAD 限额（由主进程依据上一帧自然 skip 占比决定，worker 不自行判定）
        $this->tier1BlockSad = max(0, min(MotionWorkerProtocol::MAX_BLOCK_SAD, $tier1BlockSad));
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
        $seed = null;
        if ($this->seedMap !== null && $job[0] < $this->seedMap['w'] && $job[1] < $this->seedMap['h']) {
            // 前后帧同分辨率（编码器序列内不变），同网格位置直接取种；网格不匹配则该 MB 弃种
            $seed = $this->seedMap['mvs'][$job[1] * $this->seedMap['w'] + $job[0]] ?? null;
        }
        return $this->preparePMacroblock($job[0], $job[1], $job[2], $this->refY, $this->refU, $this->refV, $job[3], $seed);
    }
}