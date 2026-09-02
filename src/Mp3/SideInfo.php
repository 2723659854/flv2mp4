<?php

namespace Xiaosongshu\Flv2mp4\Mp3;

final class SideInfo
{
    public function __construct(
        public readonly int $main_data_begin = 0,
        public readonly array $scfsi = [[0, 0, 0, 0], [0, 0, 0, 0]],
        public readonly array $granules = [[null, null], [null, null]]
    ) {}

    public static function silence(int $channels): self
    {
        $zero = new GranuleInfo();
        $granules = [];
        for ($gr = 0; $gr < 2; ++$gr) {
            $granules[$gr] = [];
            for ($ch = 0; $ch < $channels; ++$ch) $granules[$gr][$ch] = $zero;
        }
        return new self(0, array_fill(0, $channels, [0, 0, 0, 0]), $granules);
    }
}
