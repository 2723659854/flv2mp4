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

    private const MODEL_LM3 = [
        [[42,121],[96,66],[108,43],[111,40],[117,44],[123,32],[120,36],[119,33],[127,33],[134,34],[139,21],[147,23],[152,20],[158,25],[154,26],[166,21],[173,16],[184,13],[184,10],[150,13],[139,15]],
        [[22,178],[63,114],[74,82],[84,83],[92,82],[103,62],[96,72],[96,67],[101,73],[107,72],[113,55],[118,52],[125,52],[118,52],[117,55],[135,49],[137,39],[157,32],[145,29],[97,33],[77,40]],
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

    public function decodeCoarse(RangeDecoder $decoder, int $lm, int $channels, bool $intra, int $totalBits): array
    {
        $alpha = $intra ? 0.0 : self::ALPHA[$lm];
        $beta = $intra ? self::INTRA_BETA : self::BETA[$lm];
        $prediction = [0.0, 0.0];
        $energy = $this->previous;
        for ($band = 0; $band < 21; $band++) {
            for ($channel = 0; $channel < $channels; $channel++) {
                $left = $totalBits - $decoder->tell();
                if ($left >= 15) {
                    [$probability, $decay] = self::MODEL_LM3[$intra ? 1 : 0][$band];
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

    public static function decodeFine(RangeDecoder $decoder, array $energy, array $fineBits, int $channels): array
    {
        for ($band = 0; $band < 21; $band++) {
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
        int $totalBits
    ): array {
        $bitsLeft = $totalBits - $decoder->tell();
        for ($pass = 0; $pass < 2; $pass++) {
            for ($band = 0; $band < 21 && $bitsLeft >= $channels; $band++) {
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
