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
            if ($ax > 15) {
                $extraX = $ax - 15;
                $ax = 15;
            }
            if ($ay > 15) {
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
        $codeLength = $h['lengths'][$index] - $signCount;
        return [
            'code' => $h['codes'][$index] >> $signCount,
            'length' => $codeLength,
            'linbits' => [],
            'signBits' => $signBits,
            'bits' => $h['lengths'][$index],
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
        $writer->write($encoded['code'], $encoded['length']);
        foreach ($encoded['linbits'] as $extra) {
            if ($extra !== null) {
                $writer->write($extra, $linbits);
            }
        }
        foreach ([$x, $y] as $value) {
            if ($value !== 0) {
                $writer->write($value < 0 ? 1 : 0, 1);
            }
        }
    }
}
