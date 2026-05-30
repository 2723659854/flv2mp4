<?php

namespace Xiaosongshu\Flv2mp4\Flv;

class FlvDemux
{
    public static function readUInt8($data, $offset)
    {
        return ord($data[$offset]);
    }

    public static function readUInt16BE($data, $offset)
    {
        return unpack('n', substr($data, $offset, 2))[1];
    }

    public static function readUInt32BE($data, $offset)
    {
        return unpack('N', substr($data, $offset, 4))[1];
    }

    public static function readInt16BE($data, $offset)
    {
        $val = self::readUInt16BE($data, $offset);
        if ($val >= 0x8000) $val -= 0x10000;
        return $val;
    }

    public static function readFloat64BE($data, $offset)
    {
        return unpack('E', substr($data, $offset, 8))[1];
    }

    public static function decodeUTF8($bytes)
    {
        return $bytes;
    }

    public static function parseString($buffer, $dataOffset)
    {
        $length = self::readUInt16BE($buffer, $dataOffset);
        if ($length > 0) {
            $str = self::decodeUTF8(substr($buffer, $dataOffset + 2, $length));
        } else {
            $str = '';
        }
        return ['data' => $str, 'size' => 2 + $length];
    }

    public static function parseLongString($buffer, $dataOffset)
    {
        $length = self::readUInt32BE($buffer, $dataOffset);
        if ($length > 0) {
            $str = self::decodeUTF8(substr($buffer, $dataOffset + 4, $length));
        } else {
            $str = '';
        }
        return ['data' => $str, 'size' => 4 + $length];
    }

    public static function parseDate($buffer, $dataOffset)
    {
        $timestamp = self::readFloat64BE($buffer, $dataOffset);
        $localTimeOffset = self::readInt16BE($buffer, $dataOffset + 8);
        $timestamp += $localTimeOffset * 60 * 1000;
        return ['data' => (int)$timestamp, 'size' => 10];
    }

    public static function parseObject($buffer, $dataOffset, $dataSize = null)
    {
        $name = self::parseString($buffer, $dataOffset);
        $value = self::parseScript($buffer, $dataOffset + $name['size']);
        return ['data' => ['name' => $name['data'], 'value' => $value['data']], 'size' => $value['size'], 'objectEnd' => false];
    }

    public static function parseVariable($buffer, $dataOffset, $dataSize = null)
    {
        return self::parseObject($buffer, $dataOffset, $dataSize);
    }

    public static function parseMetadata($arr)
    {
        $name = self::parseScript($arr, 0);
        $value = self::parseScript($arr, $name['size'], strlen($arr) - $name['size']);
        return [$name['data'] => $value['data']];
    }

    public static function parseScript($arr, $offset, $dataSize = null)
    {
        if ($dataSize === null) $dataSize = strlen($arr) - $offset;
        $dataOffset = $offset;
        $objectEnd = false;
        $type = self::readUInt8($arr, $dataOffset);
        $dataOffset += 1;
        switch ($type) {
            case 0:
                $value = self::readFloat64BE($arr, $dataOffset);
                $dataOffset += 8;
                break;
            case 1:
                $b = self::readUInt8($arr, $dataOffset);
                $value = (bool)$b;
                $dataOffset += 1;
                break;
            case 2:
                $amfstr = self::parseString($arr, $dataOffset);
                $value = $amfstr['data'];
                $dataOffset += $amfstr['size'];
                break;
            case 3:
                $value = [];
                $terminal = 0;
                if ($dataSize >= 4) {
                    $tailCheck = self::readUInt32BE($arr, $offset + $dataSize - 4) & 0x00FFFFFF;
                    if ($tailCheck === 9) $terminal = 3;
                }
                $limit = $offset + $dataSize - 4 - $terminal;
                while ($dataOffset < $limit) {
                    $amfobj = self::parseObject($arr, $dataOffset, $dataSize - ($dataOffset - $offset) - $terminal);
                    if ($amfobj['objectEnd']) break;
                    $value[$amfobj['data']['name']] = $amfobj['data']['value'];
                    $dataOffset = $amfobj['size'];
                }
                if ($dataOffset <= $offset + $dataSize - 3) {
                    $marker = self::readUInt32BE($arr, $dataOffset - 1) & 0x00FFFFFF;
                    if ($marker === 9) $dataOffset += 3;
                }
                break;
            case 8:
                $value = [];
                $dataOffset += 4;
                $terminal = 0;
                if ($dataSize >= 4) {
                    $tailCheck = self::readUInt32BE($arr, $offset + $dataSize - 4) & 0x00FFFFFF;
                    if ($tailCheck === 9) $terminal = 3;
                }
                $limit = $offset + $dataSize - 8 - $terminal;
                while ($dataOffset < $limit) {
                    $amfvar = self::parseVariable($arr, $dataOffset);
                    if ($amfvar['objectEnd']) break;
                    $value[$amfvar['data']['name']] = $amfvar['data']['value'];
                    $dataOffset = $amfvar['size'];
                }
                if ($dataOffset <= $offset + $dataSize - 3) {
                    $marker = self::readUInt32BE($arr, $dataOffset - 1) & 0x00FFFFFF;
                    if ($marker === 9) $dataOffset += 3;
                }
                break;
            case 9:
                $value = null;
                $dataOffset = $offset + 1;
                $objectEnd = true;
                break;
            case 10:
                $strictArrayLength = self::readUInt32BE($arr, $dataOffset);
                $dataOffset += 4;
                $value = [];
                for ($i = 0; $i < $strictArrayLength; $i++) {
                    $val = self::parseScript($arr, $dataOffset);
                    $value[] = $val['data'];
                    $dataOffset = $val['size'];
                }
                break;
            case 11:
                $date = self::parseDate($arr, $dataOffset);
                $value = $date['data'];
                $dataOffset += $date['size'];
                break;
            case 12:
                $amfLongStr = self::parseLongString($arr, $dataOffset);
                $value = $amfLongStr['data'];
                $dataOffset += $amfLongStr['size'];
                break;
            default:
                $dataOffset = $offset + $dataSize;
                break;
        }
        return ['data' => $value, 'size' => $dataOffset];
    }
}