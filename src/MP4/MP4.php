<?php
namespace Xiaosongshu\Flv2mp4\MP4;

/**
 * @purpose mp4格式
 * @author yanglong
 */
class MP4
{
    public static $types = [];
    public static $constants = [];

    public static function init()
    {
        $typeNames = [
            'avc1', 'avcC', 'btrt', 'dinf', 'dref', 'esds', 'ftyp', 'hdlr',
            'mdat', 'mdhd', 'mdia', 'mfhd', 'minf', 'moof', 'moov', 'mp4a',
            'mvex', 'mvhd', 'sdtp', 'stbl', 'stco', 'stsc', 'stsd', 'stsz',
            'stts', 'tfdt', 'tfhd', 'traf', 'trak', 'trun', 'trex', 'tkhd',
            'vmhd', 'smhd'
        ];
        foreach ($typeNames as $name) {
            self::$types[$name] = $name;
        }
        self::$constants['FTYP'] = pack('C*',
            0x69, 0x73, 0x6F, 0x6D, 0x00, 0x00, 0x00, 0x01,
            0x69, 0x73, 0x6F, 0x6D, 0x61, 0x76, 0x63, 0x31
        );
        self::$constants['STSD_PREFIX'] = pack('C*', 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x01);
        self::$constants['STTS'] = pack('C*', 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00);
        self::$constants['STSC'] = self::$constants['STCO'] = self::$constants['STTS'];
        self::$constants['STSZ'] = pack('C*', 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00);
        self::$constants['HDLR_VIDEO'] = pack('C*',
            0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00,
            0x76, 0x69, 0x64, 0x65, 0x00, 0x00, 0x00, 0x00,
            0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00,
            0x56, 0x69, 0x64, 0x65, 0x6F, 0x48, 0x61, 0x6E,
            0x64, 0x6C, 0x65, 0x72, 0x00
        );
        self::$constants['HDLR_AUDIO'] = pack('C*',
            0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00,
            0x73, 0x6F, 0x75, 0x6E, 0x00, 0x00, 0x00, 0x00,
            0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00,
            0x53, 0x6F, 0x75, 0x6E, 0x64, 0x48, 0x61, 0x6E,
            0x64, 0x6C, 0x65, 0x72, 0x00
        );
        self::$constants['DREF'] = pack('C*',
            0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x01,
            0x00, 0x00, 0x00, 0x0C, 0x75, 0x72, 0x6C, 0x20,
            0x00, 0x00, 0x00, 0x01
        );
        self::$constants['SMHD'] = pack('C*', 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00);
        self::$constants['VMHD'] = pack('C*', 0x00, 0x00, 0x00, 0x01, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00);
    }

    public static function box($type, ...$datas)
    {
        if (is_array($type)) {
            $typeStr = '';
            foreach ($type as $c) $typeStr .= chr($c);
        } else {
            $typeStr = substr($type, 0, 4);
        }
        $size = 8;
        foreach ($datas as $data) $size += strlen($data);
        $result = pack('N', $size) . $typeStr;
        foreach ($datas as $data) $result .= $data;
        return $result;
    }

    public static function generateInitSegment($meta)
    {
        if (!is_array($meta) || !isset($meta[0])) $meta = [$meta];
        $ftyp = self::box(self::$types['ftyp'], self::$constants['FTYP']);
        $moov = self::moov($meta);
        return $ftyp . $moov;
    }

    /**
     * 生成单独的音频初始化片段
     * @param array $audioMeta 音频轨道元数据
     * @return string 音频初始化片段数据
     */
    public static function generateAudioInitSegment($audioMeta)
    {
        $ftyp = self::box(self::$types['ftyp'], self::$constants['FTYP']);
        $moov = self::moovForSingleTrack($audioMeta);
        return $ftyp . $moov;
    }

    /**
     * 生成单独的视频初始化片段
     * @param array $videoMeta 视频轨道元数据
     * @return string 视频初始化片段数据
     */
    public static function generateVideoInitSegment($videoMeta)
    {
        $ftyp = self::box(self::$types['ftyp'], self::$constants['FTYP']);
        $moov = self::moovForSingleTrack($videoMeta);
        return $ftyp . $moov;
    }

    /**
     * 为单个轨道生成 moov box
     * @param array $meta 轨道元数据
     * @return string moov box 数据
     */
    public static function moovForSingleTrack($meta)
    {
        $mvhd = self::mvhd($meta['timescale'], $meta['duration']);
        $trak = self::trak($meta);
        $mvex = self::box(self::$types['mvex'], self::trex($meta));
        return self::box(self::$types['moov'], $mvhd, $trak, $mvex);
    }

    public static function moov($meta)
    {
        $mvhd = self::mvhd($meta[0]['timescale'], $meta[0]['duration']);
        $vtrak = self::trak($meta[0]);
        $atrak = null;
        if (count($meta) > 1) $atrak = self::trak($meta[1]);
        $mvex = self::mvex($meta);
        if (count($meta) > 1) return self::box(self::$types['moov'], $mvhd, $vtrak, $atrak, $mvex);
        else return self::box(self::$types['moov'], $mvhd, $vtrak, $mvex);
    }

    public static function mvhd($timescale, $duration)
    {
        $data = pack('N*',
            0x00000000,
            0x00000000,
            0x00000000,
            $timescale,
            $duration,
            0x00010000,
            0x01000000,
            0x00000000,
            0x00000000,
            0x00010000,
            0x00000000,
            0x00000000,
            0x00000000,
            0x00010000,
            0x00000000,
            0x00000000,
            0x00000000,
            0x00000000,
            0x40000000,
            0x00000000,
            0x00000000,
            0x00000000,
            0x00000000,
            0x00000000,
            0x00000000,
            0x00000000,
            0x00000000,
            0x00000000,
            0x00000000,
            0x00000000,
            0xFFFFFFFF
        );
        return self::box(self::$types['mvhd'], $data);
    }

    public static function trak($meta)
    {
        return self::box(self::$types['trak'], self::tkhd($meta), self::mdia($meta));
    }

    public static function tkhd($meta)
    {
        $trackId = $meta['id'];
        $duration = $meta['duration'];
        $width = isset($meta['presentWidth']) ? $meta['presentWidth'] : 0;
        $height = isset($meta['presentHeight']) ? $meta['presentHeight'] : 0;

        // tkhd width and height are 32-bit fixed-point (16.16 format)
        $fixedWidth = $width << 16;
        $fixedHeight = $height << 16;

        $data = pack('C*',
            0x00,0x00,0x00,0x07,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,
            ($trackId>>24)&0xFF, ($trackId>>16)&0xFF, ($trackId>>8)&0xFF, $trackId&0xFF,
            0x00,0x00,0x00,0x00,
            ($duration>>24)&0xFF, ($duration>>16)&0xFF, ($duration>>8)&0xFF, $duration&0xFF,
            0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,
            0x00,0x00,0x00,0x00,0x00,0x01,0x00,0x00,0x00,0x00,0x00,0x00,
            0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x01,0x00,0x00,
            0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,
            0x40,0x00,0x00,0x00,
            ($fixedWidth>>24)&0xFF, ($fixedWidth>>16)&0xFF, ($fixedWidth>>8)&0xFF, $fixedWidth&0xFF,
            ($fixedHeight>>24)&0xFF, ($fixedHeight>>16)&0xFF, ($fixedHeight>>8)&0xFF, $fixedHeight&0xFF
        );
        return self::box(self::$types['tkhd'], $data);
    }

    public static function mdia($meta)
    {
        return self::box(self::$types['mdia'], self::mdhd($meta), self::hdlr($meta), self::minf($meta));
    }

    public static function mdhd($meta)
    {
        $timescale = $meta['timescale'];
        $duration = $meta['duration'];
        $data = pack('C*',
            0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,
            ($timescale>>24)&0xFF, ($timescale>>16)&0xFF, ($timescale>>8)&0xFF, $timescale&0xFF,
            ($duration>>24)&0xFF, ($duration>>16)&0xFF, ($duration>>8)&0xFF, $duration&0xFF,
            0x55,0xC4,0x00,0x00
        );
        return self::box(self::$types['mdhd'], $data);
    }

    public static function hdlr($meta)
    {
        if ($meta['type'] === 'audio') $data = self::$constants['HDLR_AUDIO'];
        else $data = self::$constants['HDLR_VIDEO'];
        return self::box(self::$types['hdlr'], $data);
    }

    public static function minf($meta)
    {
        if ($meta['type'] === 'audio') $xmhd = self::box(self::$types['smhd'], self::$constants['SMHD']);
        else $xmhd = self::box(self::$types['vmhd'], self::$constants['VMHD']);
        return self::box(self::$types['minf'], $xmhd, self::dinf(), self::stbl($meta));
    }

    public static function dinf()
    {
        return self::box(self::$types['dinf'], self::box(self::$types['dref'], self::$constants['DREF']));
    }

    public static function stbl($meta)
    {
        return self::box(self::$types['stbl'],
            self::stsd($meta),
            self::box(self::$types['stts'], self::$constants['STTS']),
            self::box(self::$types['stsc'], self::$constants['STSC']),
            self::box(self::$types['stsz'], self::$constants['STSZ']),
            self::box(self::$types['stco'], self::$constants['STCO'])
        );
    }

    public static function stsd($meta)
    {
        if ($meta['type'] === 'audio') {
            return self::box(self::$types['stsd'], self::$constants['STSD_PREFIX'], self::mp4a($meta));
        } else {
            return self::box(self::$types['stsd'], self::$constants['STSD_PREFIX'], self::avc1($meta));
        }
    }

    public static function mp4a($meta)
    {
        $channelCount = $meta['channelCount'];
        $sampleRate = $meta['audioSampleRate'];
        $data = pack('C*',
            0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x01,
            0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,
            0x00,$channelCount,0x00,0x10,0x00,0x00,0x00,0x00,
            ($sampleRate>>8)&0xFF, $sampleRate&0xFF, 0x00,0x00
        );
        return self::box(self::$types['mp4a'], $data, self::esds($meta));
    }

    public static function esds($meta)
    {
        $config = $meta['config'];
        $configSize = strlen($config);
        $data = pack('C*',
            0x00,0x00,0x00,0x00,
            0x03, 0x17+$configSize, 0x00,0x01, 0x00,
            0x04, 0x0F+$configSize, 0x40, 0x15, 0x00,0x00,0x00,
            0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,
            0x05
        );
        $data .= pack('C', $configSize) . $config . pack('C*', 0x06,0x01,0x02);
        return self::box(self::$types['esds'], $data);
    }

    public static function avc1($meta)
    {
        $avcc = $meta['avcc'];

        $width  = isset($meta['presentWidth'])
            ? $meta['presentWidth']
            : $meta['codecWidth'];

        $height = isset($meta['presentHeight'])
            ? $meta['presentHeight']
            : $meta['codecHeight'];

        $avcCBox = self::box(self::$types['avcC'], $avcc);

        $reserved = str_repeat("\x00", 6);
        $dataReferenceIndex = pack('n', 1);

        // VisualSampleEntry 固定 16 字节
        $preDefined =
            pack('n', 0) .
            pack('n', 0) .
            pack('N', 0) .
            pack('N', 0) .
            pack('N', 0);

        $widthBytes  = pack('n', $width);
        $heightBytes = pack('n', $height);

        $horizResolution = pack('N', 0x00480000);
        $vertResolution  = pack('N', 0x00480000);

        $reserved2 = pack('N', 0);

        $frameCount = pack('n', 1);

        $compressorName =
            "\x00" .
            str_repeat("\x00", 31);

        $depth = pack('n', 0x0018);

        $preDefined2 = pack('n', 0xFFFF);

        $data =
            $reserved .
            $dataReferenceIndex .
            $preDefined .
            $widthBytes .
            $heightBytes .
            $horizResolution .
            $vertResolution .
            $reserved2 .
            $frameCount .
            $compressorName .
            $depth .
            $preDefined2;

        return self::box(
            self::$types['avc1'],
            $data,
            $avcCBox
        );
    }

    public static function avc12($meta)
    {
        $avcc = $meta['avcc'];
        $width = isset($meta['presentWidth']) ? $meta['presentWidth'] : $meta['codecWidth'];
        $height = isset($meta['presentHeight']) ? $meta['presentHeight'] : $meta['codecHeight'];

        $avcCBox = self::box(self::$types['avcC'], $avcc);

        $reserved = str_repeat("\x00", 6);
        $dataReferenceIndex = "\x00\x01";
        $preDefined = str_repeat("\x00", 22);
        $widthBytes = pack('n', $width);
        $heightBytes = pack('n', $height);
        $horizResolution = "\x00\x48\x00\x00";
        $vertResolution = "\x00\x48\x00\x00";
        $reserved2 = "\x00\x00\x00\x00";
        $frameCount = "\x00\x01";
        $compressorName = str_repeat("\x00", 32);
        $depth = "\x00\x18";
        $preDefined2 = "\xFF\xFF";

        $data = $reserved . $dataReferenceIndex . $preDefined .
                 $widthBytes . $heightBytes .
                 $horizResolution . $vertResolution . $reserved2 .
                 $frameCount . $compressorName . $depth . $preDefined2;

        return self::box(self::$types['avc1'], $data, $avcCBox);
    }

    public static function mvex($meta)
    {
        if (count($meta) > 1) {
            return self::box(self::$types['mvex'], self::trex($meta[0]), self::trex($meta[1]));
        } else {
            return self::box(self::$types['mvex'], self::trex($meta[0]));
        }
    }

    public static function trex($meta)
    {
        $trackId = $meta['id'];
        $data = pack('C*',
            0x00,0x00,0x00,0x00,
            ($trackId>>24)&0xFF, ($trackId>>16)&0xFF, ($trackId>>8)&0xFF, $trackId&0xFF,
            0x00,0x00,0x00,0x01,0x00,0x00,0x00,0x00,0x00,0x00,0x00,0x00,
            0x00,0x01,0x00,0x01
        );
        return self::box(self::$types['trex'], $data);
    }

    public static function moof($track, $baseMediaDecodeTime)
    {
        return self::box(self::$types['moof'], self::mfhd($track['sequenceNumber']), self::traf($track, $baseMediaDecodeTime));
    }

    public static function mfhd($sequenceNumber)
    {
        $data = pack('C*',
            0x00,0x00,0x00,0x00,
            ($sequenceNumber>>24)&0xFF, ($sequenceNumber>>16)&0xFF, ($sequenceNumber>>8)&0xFF, $sequenceNumber&0xFF
        );
        return self::box(self::$types['mfhd'], $data);
    }

    public static function traf($track, $baseMediaDecodeTime)
    {
        $trackId = $track['id'];
        $tfhd = self::box(self::$types['tfhd'], pack('C*',
            0x00,0x00,0x00,0x00,
            ($trackId>>24)&0xFF, ($trackId>>16)&0xFF, ($trackId>>8)&0xFF, $trackId&0xFF
        ));
        $tfdt = self::box(self::$types['tfdt'], pack('C*',
            0x00,0x00,0x00,0x00,
            ($baseMediaDecodeTime>>24)&0xFF, ($baseMediaDecodeTime>>16)&0xFF, ($baseMediaDecodeTime>>8)&0xFF, $baseMediaDecodeTime&0xFF
        ));
        $trun = self::trun($track, strlen($tfdt) + 16 + 8 + 16 + 8 + 8);
        return self::box(self::$types['traf'], $tfhd, $tfdt, $trun);
    }

    public static function sdtp($track)
    {
        $samples = $track['samples'] ?? [];
        $sampleCount = count($samples);
        $data = pack('C*', 0x00,0x00,0x00,0x00);
        for ($i=0; $i<$sampleCount; $i++) {
            $flags = $samples[$i]['flags'];
            $byte = (($flags['isLeading']<<6) | ($flags['dependsOn']<<4) | ($flags['isDependedOn']<<2) | $flags['hasRedundancy']);
            $data .= pack('C', $byte);
        }
        return self::box(self::$types['sdtp'], $data);
    }

    public static function trun($track, $offset)
    {
        $samples = $track['samples'] ?? [];
        $sampleCount = count($samples);
        $dataSize = 12 + 16 * $sampleCount;
        $offset += 8 + $dataSize;
        $data = pack('C*',
            0x00,0x00,0x0F,0x01,
            ($sampleCount>>24)&0xFF, ($sampleCount>>16)&0xFF, ($sampleCount>>8)&0xFF, $sampleCount&0xFF,
            ($offset>>24)&0xFF, ($offset>>16)&0xFF, ($offset>>8)&0xFF, $offset&0xFF
        );
        for ($i=0; $i<$sampleCount; $i++) {
            $sample = $samples[$i];
            $duration = $sample['duration'];
            $size = $sample['size'];
            $flags = $sample['flags'];
            $cts = $sample['cts'];
            $data .= pack('N', $duration);
            $data .= pack('N', $size);
            $flagsHigh = (($flags['isLeading'] << 2) | $flags['dependsOn']);
            $flagsLow = (($flags['isDependedOn'] << 6) | ($flags['hasRedundancy'] << 4) | $flags['isNonSync']);
            $data .= pack('C*', $flagsHigh, $flagsLow, 0, 0);
            $data .= pack('N', $cts);
        }
        return self::box(self::$types['trun'], $data);
    }

    public static function mdat($data)
    {
        return self::box(self::$types['mdat'], $data);
    }
}
MP4::init();