<?php

namespace Xiaosongshu\Flv2mp4\Opus\Celt;

use Xiaosongshu\Flv2mp4\Opus\RangeDecoder;

/**
 * @purpose CELT 频带能量包络提取与增益控制单元
 * @author yanglong
 * @time 2026年8月12日17:17:04
 */
final class CeltEnergy
{
    private const ALPHA = [29440 / 32768, 26112 / 32768, 21248 / 32768, 16384 / 32768];
    private const BETA = [1 - 30147 / 32768, 1 - 22282 / 32768, 1 - 12124 / 32768, 1 - 6554 / 32768];
    private const INTRA_BETA = 1 - 4915 / 32768;

    /**
     * 粗能量 Laplace 概率模型，逐项取自 FFmpeg libavcodec/opus/tab.c 的
     * ff_celt_coarse_energy_dist[4][2][42]（维度 = LM × (inter/intra) × 21 band × 2 参数）。
     * 每帧长使用不同模型，短帧若误用 LM=3 的表会导致熵解码从首带起发散。
     */
    private const MODEL = [
        [ // LM=0（120 样本）
            [[72,127],[65,129],[66,128],[65,128],[64,128],[62,128],[64,128],[64,128],[92,78],[92,79],[92,78],[90,79],[116,41],[115,40],[114,40],[132,26],[132,26],[145,17],[161,12],[176,10],[177,11]],
            [[24,179],[48,138],[54,135],[54,132],[53,134],[56,133],[55,132],[55,132],[61,114],[70,96],[74,88],[75,88],[87,74],[89,66],[91,67],[100,59],[108,50],[120,40],[122,37],[97,43],[78,50]],
        ],
        [ // LM=1（240 样本）
            [[83,78],[84,81],[88,75],[86,74],[87,71],[90,73],[93,74],[93,74],[109,40],[114,36],[117,34],[117,34],[143,17],[145,18],[146,19],[162,12],[165,10],[178,7],[189,6],[190,8],[177,9]],
            [[23,178],[54,115],[63,102],[66,98],[69,99],[74,89],[71,91],[73,91],[78,89],[86,80],[92,66],[93,64],[102,59],[103,60],[104,60],[117,52],[123,44],[138,35],[133,31],[97,38],[77,45]],
        ],
        [ // LM=2（480 样本）
            [[61,90],[93,60],[105,42],[107,41],[110,45],[116,38],[113,38],[112,38],[124,26],[132,27],[136,19],[140,20],[155,14],[159,16],[158,18],[170,13],[177,10],[187,8],[192,6],[175,9],[159,10]],
            [[21,178],[59,110],[71,86],[75,85],[84,83],[91,66],[88,73],[87,72],[92,75],[98,72],[105,58],[107,54],[115,52],[114,55],[112,56],[129,51],[132,40],[150,33],[140,29],[98,35],[77,42]],
        ],
        [ // LM=3（960 样本）
            [[42,121],[96,66],[108,43],[111,40],[117,44],[123,32],[120,36],[119,33],[127,33],[134,34],[139,21],[147,23],[152,20],[158,25],[154,26],[166,21],[173,16],[184,13],[184,10],[150,13],[139,15]],
            [[22,178],[63,114],[74,82],[84,83],[92,82],[103,62],[96,72],[96,67],[101,73],[107,72],[113,55],[118,52],[125,52],[118,52],[117,55],[135,49],[137,39],[157,32],[145,29],[97,33],[77,40]],
        ],
    ];

    private array $previous = [[], []];
    private array $previous2 = [[], []];

    public function __construct()
    {
        $this->reset();
    }

    public function reset(): void
    {
        $silence = array_fill(0, 21, -28.0);
        $this->previous = [$silence, $silence];
        $this->previous2 = [$silence, $silence];
    }

    public function decodeCoarse(RangeDecoder $decoder, int $lm, int $channels, bool $intra, int $totalBits, int $endBand = 21): array
    {
        $alpha = $intra ? 0.0 : self::ALPHA[$lm];
        $beta = $intra ? self::INTRA_BETA : self::BETA[$lm];
        $prediction = [0.0, 0.0];
        $energy = $this->previous;
        for ($band = 0; $band < 21; $band++) {
            if ($band >= $endBand) {
                for ($channel = 0; $channel < $channels; $channel++) $energy[$channel][$band] = 0.0;
                continue;
            }
            for ($channel = 0; $channel < $channels; $channel++) {
                $left = $totalBits - $decoder->tell();
                if ($left >= 15) {
                    [$probability, $decay] = self::MODEL[$lm][$intra ? 1 : 0][$band];
                    $delta = $decoder->decodeLaplace($probability << 7, $decay << 6);
                } elseif ($left >= 2) {
                    $symbol = $decoder->decodeCdf([2, 1, 0], 2);
                    $delta = [0, -1, 1][$symbol];
                } elseif ($left >= 1) {
                    $delta = -$decoder->decodeBitLogp(1);
                } else {
                    $delta = -1;
                }
                $value = $alpha * max(-9.0, $this->previous[$channel][$band]) + $prediction[$channel] + $delta;
                $energy[$channel][$band] = $value;
                $prediction[$channel] += $beta * $delta;
            }
        }
        if ($channels === 1) {
            $energy[1] = $energy[0];
        }
        return $energy;
    }

    public function previous(): array
    {
        return [$this->previous, $this->previous2];
    }

    public function finishFrame(array $energy, bool $transient, int $channels): void
    {
        if ($channels === 1) $energy[1] = $energy[0];
        for ($channel = 0; $channel < 2; $channel++) {
            if ($transient) {
                for ($band = 0; $band < 21; $band++) {
                    $this->previous[$channel][$band] = min($this->previous[$channel][$band], $energy[$channel][$band]);
                }
            } else {
                $this->previous2[$channel] = $this->previous[$channel];
                $this->previous[$channel] = $energy[$channel];
            }
        }
    }

    public static function decodeFine(RangeDecoder $decoder, array $energy, array $fineBits, int $channels, int $endBand = 21): array
    {
        for ($band = 0; $band < $endBand; $band++) {
            $bits = $fineBits[$band];
            if ($bits === 0) {
                continue;
            }
            for ($channel = 0; $channel < $channels; $channel++) {
                $quantized = $decoder->rawBits($bits);
                $energy[$channel][$band] += ($quantized + 0.5) / (1 << $bits) - 0.5;
            }
        }
        return $energy;
    }

    public static function decodeFinal(
        RangeDecoder $decoder,
        array $energy,
        array $fineBits,
        array $priority,
        int $channels,
        int $totalBits,
        int $endBand = 21
    ): array {
        $bitsLeft = $totalBits - $decoder->tell();
        for ($pass = 0; $pass < 2; $pass++) {
            for ($band = 0; $band < $endBand && $bitsLeft >= $channels; $band++) {
                if ($priority[$band] !== $pass || $fineBits[$band] >= 8) {
                    continue;
                }
                $scale = 1.0 / (1 << ($fineBits[$band] + 1));
                for ($channel = 0; $channel < $channels; $channel++) {
                    $energy[$channel][$band] += ($decoder->rawBits(1) - 0.5) * $scale;
                    $bitsLeft--;
                }
            }
        }
        return $energy;
    }
}
