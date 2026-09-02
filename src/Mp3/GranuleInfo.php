<?php

namespace Xiaosongshu\Flv2mp4\Mp3;

final class GranuleInfo
{
    public function __construct(
        public readonly int $part2_3_length = 0,
        public readonly int $big_values = 0,
        public readonly int $global_gain = 210,
        public readonly int $scalefac_compress = 0,
        public readonly bool $window_switching_flag = false,
        public readonly array $table_select = [0, 0, 0],
        public readonly int $region0_count = 0,
        public readonly int $region1_count = 0,
        public readonly int $preflag = 0,
        public readonly int $scalefac_scale = 0,
        public readonly int $count1table_select = 0
    ) {
        if ($part2_3_length < 0 || $part2_3_length > 4095 || $big_values < 0 || $big_values > 288) {
            throw new \InvalidArgumentException('Invalid Layer III granule bit lengths');
        }
    }
}
