<?php

namespace Xiaosongshu\Flv2mp4\Codec\Encode;

/**
 * @purpose bite写入器
 * @author yanglong
 */
trait BitstreamTrait
{
    public function ue(int $v): string
    {
        if (isset(self::$ueCache[$v])) {
            return self::$ueCache[$v];
        }
        $bin = decbin($v + 1);
        $zeros = strlen($bin) - 1;
        $result = str_repeat('0', $zeros) . $bin;
        if ($v < 100) {
            self::$ueCache[$v] = $result;
        }
        return $result;
    }

    public function se(int $v): string
    {
        if ($v <= 0) {
            return $this->ue(-$v * 2);
        } else {
            return $this->ue($v * 2 - 1);
        }
    }

    public function u(int $v, int $n): string
    {
        return str_pad(decbin($v), $n, '0', STR_PAD_LEFT);
    }

    public function bitsToBytes(string $bits): string
    {
        $bytes = '';
        $len = strlen($bits);
        for ($i = 0; $i < $len; $i += 8) {
            $chunk = substr($bits, $i, 8);
            if (strlen($chunk) < 8) {
                $chunk = str_pad($chunk, 8, '0', STR_PAD_RIGHT);
            }
            $bytes .= chr(bindec($chunk));
        }
        return $bytes;
    }

    public function rbspToNal(string $rbsp, int $type): string
    {
        $ref = match (true) {
            $type === 5 => 3,
            $type === 7 || $type === 8 => 3,
            default => 2
        };
        $header = chr(($ref << 5) | $type);

        $output = '';
        $zeroCount = 0;
        for ($i = 0; $i < strlen($rbsp); $i++) {
            $byte = ord($rbsp[$i]);
            if ($zeroCount >= 2 && $byte <= 3) {
                $output .= chr(0x03);
                $zeroCount = 0;
            }
            $output .= chr($byte);
            if ($byte == 0) {
                $zeroCount++;
            } else {
                $zeroCount = 0;
            }
        }
        return "\x00\x00\x00\x01" . $header . $output;
    }
}
