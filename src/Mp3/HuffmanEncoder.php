<?php

namespace Xiaosongshu\Flv2mp4\Mp3;

/**
 * @purpose 霍夫曼编码器
 * @author yanglong
 * @time 2026年9月3日16:29:11
 */
final class HuffmanEncoder
{
    private const TABLES = HuffmanTables::TABLES;

    public function encodePair(int $x, int $y, int $table = 5): array
    {
        if (!isset(self::TABLES[$table])) {
            throw new \InvalidArgumentException('Unsupported MPEG-1 Layer III Huffman table');
        }
        if ($table === 0) {
            if ($x !== 0 || $y !== 0) throw new \InvalidArgumentException('Table 0 only encodes zero pairs');
            return ['code' => 0, 'length' => 0, 'linbits' => [], 'signBits' => [], 'bits' => 0];
        }
        $h = self::TABLES[$table];
        $max = $h['width'] - 1;
        $ax = abs($x); $ay = abs($y);
        if ($table >= 32) {
            if ($ax > 1 || $ay > 1) throw new \InvalidArgumentException('Count1 tables only encode values 0 or 1');
        } elseif (
            $ax > ($h['linbits'] > 0 ? 15 + ((1 << $h['linbits']) - 1) : $max)
            || $ay > ($h['linbits'] > 0 ? 15 + ((1 << $h['linbits']) - 1) : $max)
        ) {
            throw new \InvalidArgumentException('Value exceeds selected Huffman table');
        }
        $extraX = null;
        $extraY = null;
        if ($h['linbits']) {
            // ISO 11172-3：表中索引 15 表示 ESC（值 >= 15），必须追加 linbits 位（值为 15 时写 0）
            if ($ax >= 15) {
                $extraX = $ax - 15;
                $ax = 15;
            }
            if ($ay >= 15) {
                $extraY = $ay - 15;
                $ay = 15;
            }
        }
        $encoded = HuffmanTables::pair($table, $ax, $ay);
        $signBits = [];
        if ($x !== 0) {
            $signBits[] = $x < 0 ? 1 : 0;
        }
        if ($y !== 0) {
            $signBits[] = $y < 0 ? 1 : 0;
        }
        return [
            'code' => $encoded['code'],
            'length' => $encoded['length'],
            'linbits' => [$extraX, $extraY],
            'signBits' => $signBits,
            'bits' => $encoded['length']
                + ($extraX !== null ? $h['linbits'] : 0)
                + ($extraY !== null ? $h['linbits'] : 0)
                + count($signBits),
        ];
    }

    public function encodeQuad(array $values, int $table = 32): array
    {
        if (($table !== 32 && $table !== 33) || count($values) !== 4) {
            throw new \InvalidArgumentException('Count1 encoding requires four values and table 32 or 33');
        }
        foreach ($values as $value) {
            if (!is_int($value) || abs($value) > 1) {
                throw new \InvalidArgumentException('Count1 values must be signed integers in the range -1..1');
            }
        }
        $index = 0;
        foreach ($values as $value) $index = ($index << 1) | (abs((int) $value) ? 1 : 0);
        $h = self::TABLES[$table];
        $signBits = [];
        foreach ($values as $value) {
            if ($value) {
                $signBits[] = $value < 0 ? 1 : 0;
            }
        }
        $signCount = count($signBits);
        return [
            // 表中 codes/lengths 已去除符号位，符号位由调用方在码字之后写入
            'code' => $h['codes'][$index],
            'length' => $h['lengths'][$index],
            'linbits' => [],
            'signBits' => $signBits,
            'bits' => $h['lengths'][$index] + $signCount,
        ];
    }

    public function countPairBits(int $x, int $y, int $table = 5): int
    {
        return $this->encodePair($x, $y, $table)['bits'];
    }

    public function writePairs(BitWriter $writer, array $values, int $limit = 576, int $table = 5, int $offset = 0): int
    {
        $written = 0;
        if ($table >= 32) {
            for ($i = $offset; $i + 3 < count($values) && $written + 4 <= $limit; $i += 4) {
                $this->writeQuad($writer, array_slice($values, $i, 4), $table);
                $written += 4;
            }
            return $written;
        }
        for ($i = $offset; $i + 1 < count($values) && $written + 2 <= $limit; $i += 2) { $this->writePair($writer, (int) $values[$i], (int) $values[$i + 1], $table); $written += 2; }
        return $written;
    }

    public function countBits(array $values, int $table = 5, int $limit = 576, int $offset = 0): int
    {
        $bits = 0;
        if ($table >= 32) {
            for ($i = $offset; $i + 3 < count($values) && $i < $offset + $limit; $i += 4) $bits += $this->encodeQuad(array_slice($values, $i, 4), $table)['bits'];
            return $bits;
        }
        for ($i = $offset; $i + 1 < count($values) && $i < $offset + $limit; $i += 2) $bits += $this->countPairBits((int) $values[$i], (int) $values[$i + 1], $table);
        return $bits;
    }

    /**
     * 写入一个大值区域（值区间 [start, end)，end-start 为偶数，系数带符号）。
     * table 为 0 时区域全零，不写任何比特。
     */
    public function writeRegion(BitWriter $writer, array $coefficients, int $start, int $end, int $table): void
    {
        if ($table === 0) {
            return;
        }
        for ($i = $start; $i < $end; $i += 2) {
            $this->writePair($writer, (int) $coefficients[$i], (int) $coefficients[$i + 1], $table);
        }
    }

    /** 写入 count1 区域：从 $start 起共 $quads 个四元组（值必须为 -1/0/1）。 */
    public function writeCount1(BitWriter $writer, array $coefficients, int $start, int $quads, int $table): void
    {
        for ($k = 0; $k < $quads; ++$k) {
            $this->writeQuad($writer, array_slice($coefficients, $start + $k * 4, 4), $table);
        }
    }

    private function writeQuad(BitWriter $writer, array $values, int $table): void
    {
        $encoded = $this->encodeQuad($values, $table);
        $writer->write($encoded['code'], $encoded['length']);
        foreach ($encoded['signBits'] as $signBit) {
            $writer->write($signBit, 1);
        }
    }

    private function writePair(BitWriter $writer, int $x, int $y, int $table): void
    {
        $encoded = $this->encodePair($x, $y, $table);
        $linbits = self::TABLES[$table]['linbits'];
        [$extraX, $extraY] = $encoded['linbits'];
        $writer->write($encoded['code'], $encoded['length']);
        // ISO 11172-3 位流顺序（与 lamejs Huffmancode 的 ext 打包一致）：
        // 码字 → linbits_x → sign_x → linbits_y → sign_y（缺失项跳过）
        if ($extraX !== null) {
            $writer->write($extraX, $linbits);
        }
        if ($x !== 0) {
            $writer->write($x < 0 ? 1 : 0, 1);
        }
        if ($extraY !== null) {
            $writer->write($extraY, $linbits);
        }
        if ($y !== 0) {
            $writer->write($y < 0 ? 1 : 0, 1);
        }
    }
}
