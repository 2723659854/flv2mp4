<?php

namespace Xiaosongshu\Flv2mp4\Flv;

class FlvParse
{
    public static $tempUint8;
    public static $arrTag = [];
    public static $index = 0;
    public static $stop = false;
    public static $offset = 0;
    public static $frist = true;
    public static $_hasAudio = false;
    public static $_hasVideo = false;

    public static function setFlv($uint8)
    {
        self::$stop = false;
        self::$arrTag = [];
        self::$index = 0;
        self::$tempUint8 = $uint8;

        $len = strlen(self::$tempUint8);
        if ($len > 13 && ord(self::$tempUint8[0]) == 70 && ord(self::$tempUint8[1]) == 76 && ord(self::$tempUint8[2]) == 86) {
            self::probe(self::$tempUint8);
            self::read(9);
            self::read(4);
            self::parse();
            self::$frist = false;
            return self::$offset;
        } elseif (!self::$frist) {
            // 在直播模式下，每次解析前也需要清空标签数组，避免重复处理
            self::$arrTag = [];
            return self::parse();
        } else {
            return self::$offset;
        }
    }

    public static function probe($buffer)
    {
        if (ord($buffer[0]) !== 0x46 || ord($buffer[1]) !== 0x4C || ord($buffer[2]) !== 0x56 || ord($buffer[3]) !== 0x01) {
            return ['match' => false];
        }
        $flags = ord($buffer[4]);
        $hasAudio = (($flags & 4) >> 2) !== 0;
        $hasVideo = ($flags & 1) !== 0;
        if (!$hasAudio && !$hasVideo) {
            return ['match' => false];
        }
        self::$_hasAudio = $hasAudio;
        self::$_hasVideo = $hasVideo;
        return [
            'match' => true,
            'hasAudioTrack' => $hasAudio,
            'hasVideoTrack' => $hasVideo
        ];
    }

    public static function parse()
    {
        $len = strlen(self::$tempUint8);
        while (self::$index < $len && !self::$stop) {
            self::$offset = self::$index;
            $t = new FlvTag();
            if ($len - self::$index >= 11) {
                $t->tagType = ord(self::read(1)[0]);
                $t->dataSize = self::read3BytesAsInt();
                $t->Timestamp = self::read(4);
                $t->StreamID = self::read3BytesAsInt();
            } else {
                self::$stop = true;
                continue;
            }
            $bodySize = $t->dataSize;
            if ($len - self::$index >= ($bodySize + 4)) {
                $t->body = self::read($bodySize);
                if ($t->tagType == 9 && self::$_hasVideo) {
                    self::$arrTag[] = $t;
                }
                if ($t->tagType == 8 && self::$_hasAudio) {
                    self::$arrTag[] = $t;
                }
                if ($t->tagType == 18) {
                    if (count(self::$arrTag) == 0) {
                        self::$arrTag[] = $t;
                    }
                }
                $t->size = self::read4BytesAsInt();
            } else {
                self::$stop = true;
                continue;
            }
            self::$offset = self::$index;
        }
        return self::$offset;
    }

    public static function read($length)
    {
        $data = substr(self::$tempUint8, self::$index, $length);
        self::$index += $length;
        return $data;
    }

    public static function getBodySum($bytes)
    {
        if (strlen($bytes) < 3) return 0;
        return (ord($bytes[0]) << 16) | (ord($bytes[1]) << 8) | ord($bytes[2]);
    }

    public static function read3BytesAsInt()
    {
        $bytes = self::read(3);
        return self::getBodySum($bytes);
    }

    public static function read4BytesAsInt()
    {
        $bytes = self::read(4);
        return unpack('N', $bytes)[1];
    }

    public static function getTags()
    {
        return self::$arrTag;
    }

    public static function reset()
    {
        self::$tempUint8 = '';
        self::$arrTag = [];
        self::$index = 0;
        self::$stop = false;
        self::$offset = 0;
        self::$frist = true;
        self::$_hasAudio = false;
        self::$_hasVideo = false;
    }
}