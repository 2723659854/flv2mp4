<?php

namespace Xiaosongshu\Flv2mp4\Mp3;

/**
 * @purpose 纯 PHP MPEG-1 Layer III 解码器
 * side info / bit reservoir / scalefactor / Huffman / 反量化 / joint stereo /
 * short reorder / antialias / IMDCT36+12 / overlap-add / DCT32 / 多相合成滤波器组。
 * @author yanglong
 * @time 2026年9月15日15:17:08
 */
final class Mp3L3Decoder
{
    private const BITRATES = [0,32,40,48,56,64,80,96,112,128,160,192,224,256,320];
    private const SAMPLERATES = [44100, 48000, 32000];

    /** ff_slen_table */
    private const SLEN1 = [0,0,0,0,3,1,1,1,2,2,2,3,3,3,4,4];
    private const SLEN2 = [0,1,2,3,0,1,2,3,1,2,3,1,2,3,2,3];

    /** ff_mpa_pretab */
    private const PRETAB = [0,0,0,0,0,0,0,0,0,0,0,1,1,1,1,2,2,3,3,3,2,0];

    /** ff_band_size_long (样本数) */
    private const BAND_LONG = [
        [4,4,4,4,4,4,6,6,8,8,10,12,16,20,24,28,34,42,50,54,76,158],
        [4,4,4,4,4,4,6,6,6,8,10,12,16,18,22,28,34,40,46,54,54,192],
        [4,4,4,4,4,4,6,6,8,10,12,16,20,24,30,38,46,56,68,84,102,26],
        [6,6,6,6,6,6,8,10,12,14,16,20,24,28,32,38,46,52,60,68,58,54],
        [6,6,6,6,6,6,8,10,12,14,16,18,22,26,32,38,46,54,62,70,76,36],
        [6,6,6,6,6,6,8,10,12,14,16,20,24,28,32,38,46,52,60,68,58,54],
        [6,6,6,6,6,6,8,10,12,14,16,20,24,28,32,38,46,52,60,68,58,54],
        [6,6,6,6,6,6,8,10,12,14,16,20,24,28,32,38,46,52,60,68,58,54],
        [12,12,12,12,12,12,16,20,24,28,32,40,48,56,64,76,90,2,2,2,2,2],
    ];

    /** ff_band_size_short (每个短窗样本数) */
    private const BAND_SHORT = [
        [4,4,4,4,6,8,10,12,14,18,22,30,56],
        [4,4,4,4,6,6,10,12,14,16,20,26,66],
        [4,4,4,4,6,8,12,16,20,26,34,42,12],
        [4,4,4,6,6,8,10,14,18,26,32,42,18],
        [4,4,4,6,8,10,12,14,18,24,32,44,12],
        [4,4,4,6,8,10,12,14,18,24,30,40,18],
        [4,4,4,6,8,10,12,14,18,24,30,40,18],
        [4,4,4,6,8,10,12,14,18,24,30,40,18],
        [8,8,8,12,16,20,24,28,36,2,2,2,26],
    ];

    /** ff_mpa_huff_data[k][1] linbits */
    private const LINBITS = [
        0 => 0, 1=>0, 2=>0, 3=>0, 4=>0, 5=>0, 6=>0, 7=>0, 8=>0,
        9=>0, 10=>0, 11=>0, 12=>0, 13=>0, 14=>0, 15=>0,
        16=>1, 17=>2, 18=>3, 19=>4, 20=>6, 21=>8, 22=>10, 23=>13,
        24=>4, 25=>5, 26=>6, 27=>7, 28=>8, 29=>9, 30=>11, 31=>13,
    ];

    /** float csa_table[j][0], [j][1] (mpegaudiodec_float.c) */
    private const CSA = [
        [0.8574929237365722656, -0.5144957900047302246],
        [0.8817420005798339844, -0.4717319905757904053],
        [0.9496286511421203613, -0.3133774697780609131],
        [0.9833145737648010254, -0.1819131970405578613],
        [0.9955177903175354004, -0.09457419067621231079],
        [0.9991605877876281738, -0.04096558317542076111],
        [0.9998992085456848145, -0.01419856864959001541],
        [0.9999931454658508301, -0.003699974622577428818],
    ];

    /** float intensity stereo is_table[2][16]，仅 0..6 有效 */
    private const IS_TAB0 = [0.0, 0.2113248705863952637, 0.3660253882408142090, 0.5,
        0.6339746117591857910, 0.7886751294136047363, 1.0];
    private const IS_TAB1 = [1.0, 0.7886751294136047363, 0.6339746117591857910, 0.5,
        0.3660253882408142090, 0.2113248705863952637, 0.0];

    private const IDXTAB = [3,3,2,2,1,1,1,1,0,0,0,0,0,0,0,0];

    /** ff_mpa_enwindow[257] */
    private const ENWINDOW = [
        0,-1,-1,-1,-1,-1,-1,-2,-2,-2,-2,-3,-3,-4,-4,-5,
        -5,-6,-7,-7,-8,-9,-10,-11,-13,-14,-16,-17,-19,-21,-24,-26,
        -29,-31,-35,-38,-41,-45,-49,-53,-58,-63,-68,-73,-79,-85,-91,-97,
        -104,-111,-117,-125,-132,-139,-147,-154,-161,-169,-176,-183,-190,-196,-202,-208,
        213,218,222,225,227,228,228,227,224,221,215,208,200,189,177,163,
        146,127,106,83,57,29,-2,-36,-72,-111,-153,-197,-244,-294,-347,-401,
        -459,-519,-581,-645,-711,-779,-848,-919,-991,-1064,-1137,-1210,-1283,-1356,-1428,-1498,
        -1567,-1634,-1698,-1759,-1817,-1870,-1919,-1962,-2001,-2032,-2057,-2075,-2085,-2087,-2080,-2063,
        2037,2000,1952,1893,1822,1739,1644,1535,1414,1280,1131,970,794,605,402,185,
        -45,-288,-545,-814,-1095,-1388,-1692,-2006,-2330,-2663,-3004,-3351,-3705,-4063,-4425,-4788,
        -5153,-5517,-5879,-6237,-6589,-6935,-7271,-7597,-7910,-8209,-8491,-8755,-8998,-9219,-9416,-9585,
        -9727,-9838,-9916,-9959,-9966,-9935,-9863,-9750,-9592,-9389,-9139,-8840,-8492,-8092,-7640,-7134,
        6574,5959,5288,4561,3776,2935,2037,1082,70,-998,-2122,-3300,-4533,-5818,-7154,-8540,
        -9975,-11455,-12980,-14548,-16155,-17799,-19478,-21189,-22929,-24694,-26482,-28289,-30112,-31947,-33791,-35640,
        -37489,-39336,-41176,-43006,-44821,-46617,-48390,-50137,-51853,-53534,-55178,-56778,-58333,-59838,-61289,-62684,
        -64019,-65290,-66494,-67629,-68692,-69679,-70590,-71420,-72169,-72835,-73415,-73908,-74313,-74630,-74856,-74992,
        75038,
    ];

    /** @var array<int, mixed> Huffman 解码 trie 缓存 */
    private static array $trieCache = [];

    /** @var float[][] expval_table_float[512][16] */
    private array $expVal = [];
    /** @var float[] exp_table_float[512] */
    private array $expOne = [];

    /** @var float[][][] ff_mdct_win_float[8][40] */
    private array $mdctWin = [];
    /** @var float[] ff_mpa_synth_window_float[512] */
    private array $synthWin = [];

    // ---- 跨帧状态 ----
    private string $reservoir = '';
    /** @var float[][][][]: mdct overlap，[ch][sb][18] */
    private array $mdctOv = [];
    /** @var float[][]: 合成环形缓冲 [ch][1024] */
    private array $synthBuf = [];
    /** @var int[] */
    private array $synthOff = [0, 0];

    private int $sampleRate = 0;
    private int $channels = 0;

    public function __construct()
    {
        $this->initExpTable();
        $this->initMdctWindows();
        $this->initSynthWindow();
    }

    private function initExpTable(): void
    {
        $lut = [1.0, 1.18920711500272106672, M_SQRT2, 1.68179283050742908606];
        $base = 2.11758236813575084767080625169910490512847900390625e-22; // 2^-72
        $pow43 = [];
        for ($i = 0; $i < 16; $i++) $pow43[$i] = $i * pow($i, 1.0 / 3.0);
        for ($e = 0; $e < 512; $e++) {
            if ($e && ($e & 3) === 0) $base *= 2.0;
            $v = $base * $lut[$e & 3] / 1.759;
            $row = [];
            for ($value = 0; $value < 16; $value++) $row[$value] = $pow43[$value] * $v;
            $this->expVal[$e] = $row;
            $this->expOne[$e] = $row[1];
        }
    }

    private function initMdctWindows(): void
    {
        $win = array_fill(0, 8, array_fill(0, 40, 0.0));
        for ($i = 0; $i < 36; $i++) {
            for ($j = 0; $j < 4; $j++) {
                if ($j === 2 && $i % 3 !== 1) continue;
                $d = sin(M_PI * ($i + 0.5) / 36.0);
                if ($j === 1) {
                    if ($i >= 30) $d = 0.0;
                    else if ($i >= 24) $d = sin(M_PI * ($i - 18 + 0.5) / 12.0);
                    else if ($i >= 18) $d = 1.0;
                } else if ($j === 3) {
                    if ($i < 6) $d = 0.0;
                    else if ($i < 12) $d = sin(M_PI * ($i - 6 + 0.5) / 12.0);
                    else if ($i < 18) $d = 1.0;
                }
                $d *= 0.5 * 1.759 / cos(M_PI * (2 * $i + 19) / 72.0);
                $d /= 32.0;
                if ($j === 2) {
                    $win[2][intdiv($i, 3)] = $d;
                } else {
                    $idx = $i < 18 ? $i : $i + 2;
                    $win[$j][$idx] = $d;
                }
            }
        }
        // 频率反转：奇数位置取负
        for ($j = 0; $j < 4; $j++) {
            for ($i = 0; $i < 40; $i += 2) {
                $win[$j + 4][$i] = $win[$j][$i];
                $win[$j + 4][$i + 1] = -$win[$j][$i + 1];
            }
        }
        $this->mdctWin = $win;
    }

    private function initSynthWindow(): void
    {
        $w = array_fill(0, 512, 0.0);
        $scale = 1.0 / (1 << 39); // 1LL << (16 + FRAC_BITS(23))
        for ($i = 0; $i < 257; $i++) {
            $v = self::ENWINDOW[$i] * $scale;
            $w[$i] = $v;
            if (($i & 63) !== 0) $v = -$v;
            if ($i !== 0) $w[512 - $i] = $v;
        }
        $this->synthWin = $w;
    }

    private static function trie(int $table): array
    {
        if (isset(self::$trieCache[$table])) return self::$trieCache[$table];
        $h = HuffmanTables::TABLES[$table];
        $root = [];
        foreach ($h['codes'] as $idx => $code) {
            $len = $h['lengths'][$idx];
            if ($len === 0) continue;
            $node = &$root;
            for ($b = $len - 1; $b >= 1; $b--) {
                $bit = ($code >> $b) & 1;
                if (!isset($node[$bit])) $node[$bit] = [];
                $node = &$node[$bit];
            }
            $node[$code & 1] = ['leaf' => $idx];
            unset($node);
        }
        return self::$trieCache[$table] = $root;
    }

    /**
     * 解码整个 MP3 文件数据，返回 planar float 采样。
     * @return array{sampleRate:int,channels:int,planes:float[][]}
     */
    public function decode(string $data): array
    {
        $this->reservoir = '';
        $this->sampleRate = 0;
        $this->channels = 0;
        $this->synthOff = [0, 0];

        $planes = [[]];
        $total = strlen($data);
        $off = $this->skipId3v2($data);

        while ($off + 4 <= $total) {
            // 帧同步
            $v = $this->peekU32($data, $off);
            if (($v >> 21 & 0x7ff) !== 0x7ff) { $off++; continue; }
            // ID3v1 尾部标签直接结束
            if (($v & 0xffffff00) === (ord('T') << 24 | ord('A') << 16 | ord('G') << 8)) break;

            $id = ($v >> 19) & 3;
            $layer = ($v >> 17) & 3;
            if ($id !== 3 || $layer !== 1) { $off++; continue; }
            $bi = ($v >> 12) & 15;
            $si = ($v >> 10) & 3;
            $padding = ($v >> 9) & 1;
            $errorProtection = (($v >> 16) & 1) === 0;
            $mode = ($v >> 6) & 3;
            $modeExt = ($v >> 4) & 3;
            if ($bi === 0 || $si === 3) { $off++; continue; }

            $bitrate = self::BITRATES[$bi] * 1000;
            $sr = self::SAMPLERATES[$si];
            $frameLen = intdiv(144 * $bitrate, $sr) + $padding;
            if ($off + $frameLen > $total) break;
            if ($frameLen < 4) { $off++; continue; }

            $channels = $mode === 3 ? 1 : 2;
            if ($this->sampleRate === 0) {
                $this->sampleRate = $sr;
                $this->channels = $channels;
                $this->mdctOv = array_fill(0, $channels, array_fill(0, 32, array_fill(0, 18, 0.0)));
                $this->synthBuf = array_fill(0, $channels, array_fill(0, 1024, 0.0));
                $planes = array_fill(0, $channels, []);
            } else if ($channels !== $this->channels) {
                // 声道数变化，重置状态
                $this->channels = $channels;
                $this->mdctOv = array_fill(0, $channels, array_fill(0, 32, array_fill(0, 18, 0.0)));
                $this->synthBuf = array_fill(0, $channels, array_fill(0, 1024, 0.0));
                $this->synthOff = array_fill(0, $channels, 0);
                $planes = array_fill(0, $channels, []);
            }

            $frame = substr($data, $off, $frameLen);
            $this->decodeFrame($frame, $si, $channels, $mode, $modeExt, $errorProtection, $planes);
            $off += $frameLen;
        }

        return ['sampleRate' => $this->sampleRate, 'channels' => $this->channels, 'planes' => $planes];
    }

    private function skipId3v2(string $data): int
    {
        if (strlen($data) >= 10 && substr($data, 0, 3) === 'ID3') {
            $b = unpack('C4', substr($data, 6, 4));
            $size = (($b[1] & 0x7f) << 21) | (($b[2] & 0x7f) << 14) | (($b[3] & 0x7f) << 7) | ($b[4] & 0x7f);
            return 10 + $size;
        }
        return 0;
    }

    private function peekU32(string $data, int $off): int
    {
        return (ord($data[$off]) << 24) | (ord($data[$off + 1]) << 16)
            | (ord($data[$off + 2]) << 8) | ord($data[$off + 3]);
    }

    /**
     * @param float[][] $planes
     */
    private function decodeFrame(string $frame, int $sri, int $channels, int $mode,
                                 int $modeExt, bool $crc, array &$planes): void
    {
        $sideLen = $channels === 1 ? 17 : 32;
        $headerLen = 4 + ($crc ? 2 : 0) + $sideLen;
        $br = new L3BitReader(substr($frame, $crc ? 6 : 4, $sideLen));

        $mainDataBegin = $br->read(9);
        $br->skip($channels === 1 ? 5 : 3);
        $scfsi = [];
        for ($ch = 0; $ch < $channels; $ch++) {
            $scfsi[$ch] = $br->read(4);
        }

        // GranuleDef 集合：$gran[gr][ch]
        $gran = [];
        for ($gr = 0; $gr < 2; $gr++) {
            for ($ch = 0; $ch < $channels; $ch++) {
                $g = [];
                $g['part23'] = $br->read(12);
                $bigValues = $br->read(9);
                if ($bigValues > 288) $bigValues = 288;
                $g['big'] = $bigValues;
                $g['gain'] = $br->read(8);
                if (($modeExt & 3) === 2) $g['gain'] -= 2; // 仅 MS stereo
                $g['comp'] = $br->read(4);
                $blocksplit = $br->read(1);
                $g['tabsel'] = [0, 0, 0];
                $g['subgain'] = [0, 0, 0];
                if ($blocksplit) {
                    $g['bt'] = $br->read(2);
                    $g['sp'] = $br->read(1);
                    $g['tabsel'][0] = $br->read(5);
                    $g['tabsel'][1] = $br->read(5);
                    $g['tabsel'][2] = 0;
                    for ($i = 0; $i < 3; $i++) $g['subgain'][$i] = $br->read(3);
                    $this->initShortRegion($g, $sri);
                } else {
                    $g['bt'] = 0;
                    $g['sp'] = 0;
                    for ($i = 0; $i < 3; $i++) $g['tabsel'][$i] = $br->read(5);
                    $ra1 = $br->read(4);
                    $ra2 = $br->read(3);
                    $this->initLongRegion($g, $sri, $ra1, $ra2);
                }
                $this->regionOffset2Size($g);
                $this->computeBandIndexes($g, $sri);
                $g['preflag'] = $br->read(1);
                $g['sfscale'] = $br->read(1);
                $g['count1'] = $br->read(1);
                $g['sf'] = array_fill(0, 40, 0);
                $g['hybrid'] = array_fill(0, 576, 0.0);
                $gran[$gr][$ch] = $g;
            }
        }

        // 拼接 bit reservoir 与当前帧 main data
        $payload = substr($frame, $headerLen);
        $combined = $this->reservoir === '' ? $payload : $this->reservoir . $payload;
        $main = new L3BitReader($combined);
        // gr0/ch0 数据起点（bit），可能为负（文件开头 reservoir 不足）
        $bitPos = (strlen($this->reservoir) - $mainDataBegin) * 8;

        for ($gr = 0; $gr < 2; $gr++) {
            for ($ch = 0; $ch < $channels; $ch++) {
                $g = &$gran[$gr][$ch];
                if ($bitPos < 0) {
                    // reservoir 不足：该 granule 输出零谱，但仍要走 IMDCT 刷新 overlap
                    $g['hybrid'] = array_fill(0, 576, 0.0);
                    $bitPos += $g['part23'];
                    unset($g);
                    continue;
                }
                $main->seek($bitPos);
                $this->readScaleFactors($main, $g, $gr === 1 ? $scfsi[$ch] : 0, $gran[0][$ch]['sf']);
                $exponents = $this->exponents($g, $sri);
                $this->huffmanDecode($main, $g, $exponents, $bitPos + $g['part23']);
                $bitPos += $g['part23'];
                unset($g);
            }
            if ($mode === 1) {
                $this->computeStereo($gran[$gr][0], $gran[$gr][1], $sri, $modeExt);
            }
            for ($ch = 0; $ch < $channels; $ch++) {
                $g = &$gran[$gr][$ch];
                $this->reorderBlock($g, $sri);
                $this->computeAntialias($g);
                $this->computeImdct($g, $ch, $planes[$ch]);
                unset($g);
            }
        }

        // 保存未消费字节作为下一帧 reservoir（字节对齐）
        $endByte = intdiv($bitPos + 7, 8);
        if ($endByte < 0) $endByte = 0;
        if ($endByte < strlen($combined)) {
            $this->reservoir = substr($combined, $endByte, 1048);
        } else {
            $this->reservoir = '';
        }
    }

    private function regionOffset2Size(array &$g): void
    {
        $j = 0;
        $g['rsize'][2] = 288;
        for ($i = 0; $i < 3; $i++) {
            $k = min($g['rsize'][$i], $g['big']);
            $g['rsize'][$i] = $k - $j;
            $j = $k;
        }
    }

    private function initShortRegion(array &$g, int $sri): void
    {
        if ($g['bt'] === 2) {
            $g['rsize'][0] = $sri !== 8 ? 18 : 36;
        } else {
            $g['rsize'][0] = $sri <= 2 ? 18 : ($sri !== 8 ? 27 : 54);
        }
        $g['rsize'][1] = 288;
        $g['rsize'][2] = 288;
    }

    private function initLongRegion(array &$g, int $sri, int $ra1, int $ra2): void
    {
        $bandIndex = $this->bandIndexLong($sri);
        $g['rsize'][0] = $bandIndex[$ra1 + 1];
        $l = min($ra1 + $ra2 + 2, 22);
        $g['rsize'][1] = $bandIndex[$l];
        $g['rsize'][2] = 288;
    }

    private function bandIndexLong(int $sri): array
    {
        static $cache = [];
        if (isset($cache[$sri])) return $cache[$sri];
        $k = 0;
        $idx = [];
        for ($j = 0; $j < 22; $j++) {
            $idx[$j] = $k;
            $k += self::BAND_LONG[$sri][$j] >> 1;
        }
        $idx[22] = $k;
        return $cache[$sri] = $idx;
    }

    private function computeBandIndexes(array &$g, int $sri): void
    {
        if ($g['bt'] === 2) {
            if ($g['sp']) {
                $g['longEnd'] = $sri <= 2 ? 8 : 6;
                $g['shortStart'] = 3;
            } else {
                $g['longEnd'] = 0;
                $g['shortStart'] = 0;
            }
        } else {
            $g['shortStart'] = 13;
            $g['longEnd'] = 22;
        }
    }

    private function readScaleFactors(L3BitReader $main, array &$g, int $scfsi, array $gr0sf): void
    {
        $slen1 = self::SLEN1[$g['comp']];
        $slen2 = self::SLEN2[$g['comp']];
        $j = 0;
        if ($g['bt'] === 2) {
            $n = $g['sp'] ? 17 : 18;
            if ($slen1) {
                for ($i = 0; $i < $n; $i++) $g['sf'][$j++] = $main->read($slen1);
            } else {
                $j += $n;
            }
            if ($slen2) {
                for ($i = 0; $i < 18; $i++) $g['sf'][$j++] = $main->read($slen2);
                $j += 3;
            } else {
                $j += 21;
            }
        } else {
            for ($k = 0; $k < 4; $k++) {
                $n = $k === 0 ? 6 : 5;
                if (($scfsi & (0x8 >> $k)) === 0) {
                    $slen = $k < 2 ? $slen1 : $slen2;
                    if ($slen) {
                        for ($i = 0; $i < $n; $i++) $g['sf'][$j++] = $main->read($slen);
                    } else {
                        $j += $n;
                    }
                } else {
                    for ($i = 0; $i < $n; $i++) {
                        $g['sf'][$j] = $gr0sf[$j];
                        $j++;
                    }
                }
            }
            $g['sf'][$j++] = 0;
        }
    }

    /**
     * @return int[] 每采样 exponent（576）
     */
    private function exponents(array $g, int $sri): array
    {
        $gain = $g['gain'] - 210;
        $shift = $g['sfscale'] + 1;
        $pretab = $g['preflag'] ? self::PRETAB : array_fill(0, 22, 0);
        $exp = [];
        for ($i = 0; $i < $g['longEnd']; $i++) {
            $v0 = $gain - (($g['sf'][$i] + $pretab[$i]) << $shift) + 400;
            for ($j = 0; $j < self::BAND_LONG[$sri][$i]; $j++) $exp[] = $v0;
        }
        if ($g['shortStart'] < 13) {
            $gains = [
                $gain - ($g['subgain'][0] << 3),
                $gain - ($g['subgain'][1] << 3),
                $gain - ($g['subgain'][2] << 3),
            ];
            $k = $g['longEnd'];
            for ($i = $g['shortStart']; $i < 13; $i++) {
                $len = self::BAND_SHORT[$sri][$i];
                for ($l = 0; $l < 3; $l++) {
                    $v0 = $gains[$l] - ($g['sf'][$k++] << $shift) + 400;
                    for ($j = 0; $j < $len; $j++) $exp[] = $v0;
                }
            }
        }
        // 防御：补齐 576
        while (count($exp) < 576) $exp[] = 0;
        return $exp;
    }

    private function huffmanDecode(L3BitReader $main, array &$g, array $exponents, int $endBit): void
    {
        $hybrid = &$g['hybrid'];
        $sIndex = 0;
        for ($region = 0; $region < 3; $region++) {
            $pairs = $g['rsize'][$region];
            if ($pairs <= 0) continue;
            $tab = $g['tabsel'][$region];
            if ($tab === 0 || $tab === 4 || $tab === 14) {
                $sIndex += 2 * $pairs;
                continue;
            }
            $linbits = self::LINBITS[$tab];
            $trie = self::trie($tab);
            for ($n = 0; $n < $pairs; $n++) {
                if ($main->pos >= $endBit) break;
                $idx = $this->walkTrie($main, $trie, $endBit);
                if ($idx === null) break;
                $iw = $tab > 15 ? 16 : HuffmanTables::TABLES[$tab]['width'];
                $ax = intdiv($idx, $iw);
                $ay = $idx % $iw;
                if ($ax !== 0 && $ay !== 0) {
                    $hybrid[$sIndex] = $this->readQuant($main, $ax, $linbits, $exponents[$sIndex]);
                    $hybrid[$sIndex + 1] = $this->readQuant($main, $ay, $linbits, $exponents[$sIndex + 1]);
                } else if ($ax !== 0) {
                    $hybrid[$sIndex] = $this->readQuant($main, $ax, $linbits, $exponents[$sIndex]);
                } else if ($ay !== 0) {
                    $hybrid[$sIndex + 1] = $this->readQuant($main, $ay, $linbits, $exponents[$sIndex + 1]);
                }
                $sIndex += 2;
            }
        }

        // count1 高频区
        $tab = 32 + $g['count1'];
        $trie = self::trie($tab);
        while ($sIndex <= 572 && $main->pos < $endBit) {
            $code = $this->walkTrie($main, $trie, $endBit);
            if ($code === null) break;
            while ($code) {
                $pos = $sIndex + self::IDXTAB[$code];
                $code ^= 8 >> self::IDXTAB[$code];
                $v = $this->expOne[max(0, min(511, $exponents[$pos]))];
                if ($main->pos < $endBit && $main->read(1)) $v = -$v;
                $hybrid[$pos] = $v;
            }
            $sIndex += 4;
        }
        $main->seek($endBit);
    }

    private function readQuant(L3BitReader $main, int $mag, int $linbits, int $exponent): float
    {
        $e = max(0, min(511, $exponent));
        if ($mag === 15 && $linbits > 0) {
            $x = 15 + $main->read($linbits);
            $v = $x * pow($x, 1.0 / 3.0) * $this->expOne[$e];
        } else {
            $v = $this->expVal[$e][$mag];
        }
        if ($main->read(1)) $v = -$v;
        return $v;
    }

    private function walkTrie(L3BitReader $main, array $node, int $endBit): ?int
    {
        while (!isset($node['leaf'])) {
            if ($main->pos >= $endBit) return null;
            $bit = $main->read(1);
            if (!isset($node[$bit])) return null;
            $node = $node[$bit];
        }
        return $node['leaf'];
    }

    /**
     * joint stereo（MS / intensity），1:1 移植 compute_stereo()。
     * 输入文件为普通立体声时不会调用。
     */
    private function computeStereo(array &$g0, array &$g1, int $sri, int $modeExt): void
    {
        $t0 = &$g0['hybrid'];
        $t1 = &$g1['hybrid'];

        if ($modeExt & 1) {
            // intensity stereo（MPEG-1: is_table，sf_max=7）
            $nzShort = [0, 0, 0];
            $k = (13 - $g1['shortStart']) * 3 + $g1['longEnd'] - 3;
            $p = 576;
            for ($i = 12; $i >= $g1['shortStart']; $i--) {
                if ($i !== 11) $k -= 3;
                $len = self::BAND_SHORT[$sri][$i];
                for ($l = 2; $l >= 0; $l--) {
                    $p -= $len;
                    $doMs = $nzShort[$l] !== 0;
                    if (!$doMs) {
                        for ($j = 0; $j < $len; $j++) {
                            if ($t1[$p + $j] != 0.0) { $nzShort[$l] = 1; $doMs = true; break; }
                        }
                    }
                    if (!$doMs) {
                        $sf = $g1['sf'][$k + $l];
                        if ($sf >= 7) {
                            $doMs = true;
                        } else {
                            $v1 = self::IS_TAB0[$sf];
                            $v2 = self::IS_TAB1[$sf];
                            for ($j = 0; $j < $len; $j++) {
                                $a = $t0[$p + $j];
                                $t0[$p + $j] = $a * $v1;
                                $t1[$p + $j] = $a * $v2;
                            }
                        }
                    }
                    if ($doMs && ($modeExt & 2)) {
                        for ($j = 0; $j < $len; $j++) {
                            $a = $t0[$p + $j];
                            $b = $t1[$p + $j];
                            $t0[$p + $j] = ($a + $b) * 0.7071067811865475;
                            $t1[$p + $j] = ($a - $b) * 0.7071067811865475;
                        }
                    }
                }
            }

            $nz = $nzShort[0] | $nzShort[1] | $nzShort[2];
            for ($i = $g1['longEnd'] - 1; $i >= 0; $i--) {
                $len = self::BAND_LONG[$sri][$i];
                $p -= $len;
                $doMs = $nz !== 0;
                if (!$doMs) {
                    for ($j = 0; $j < $len; $j++) {
                        if ($t1[$p + $j] != 0.0) { $nz = 1; $doMs = true; break; }
                    }
                }
                if (!$doMs) {
                    $sf = $g1['sf'][$i === 21 ? 20 : $i];
                    if ($sf >= 7) {
                        $doMs = true;
                    } else {
                        $v1 = self::IS_TAB0[$sf];
                        $v2 = self::IS_TAB1[$sf];
                        for ($j = 0; $j < $len; $j++) {
                            $a = $t0[$p + $j];
                            $t0[$p + $j] = $a * $v1;
                            $t1[$p + $j] = $a * $v2;
                        }
                    }
                }
                if ($doMs && ($modeExt & 2)) {
                    for ($j = 0; $j < $len; $j++) {
                        $a = $t0[$p + $j];
                        $b = $t1[$p + $j];
                        $t0[$p + $j] = ($a + $b) * 0.7071067811865475;
                        $t1[$p + $j] = ($a - $b) * 0.7071067811865475;
                    }
                }
            }
        } else if ($modeExt & 2) {
            // MS stereo ONLY（1/sqrt(2) 已包含在 global gain 的 -2 中）
            for ($i = 0; $i < 576; $i++) {
                $a = $t0[$i];
                $b = $t1[$i];
                $t0[$i] = $a + $b;
                $t1[$i] = $a - $b;
            }
        }
    }

    /** reorder_block()：短块 [w0s..][w1s..][w2s..] -> [s0w0,s0w1,s0w2,...] */
    private function reorderBlock(array &$g, int $sri): void
    {
        if ($g['bt'] !== 2) return;
        $h = &$g['hybrid'];
        $start = $g['sp'] ? ($sri !== 8 ? 36 : 72) : 0;
        $ptr = $start;
        for ($i = $g['shortStart']; $i < 13; $i++) {
            $len = self::BAND_SHORT[$sri][$i];
            $tmp = [];
            for ($j = 0; $j < $len; $j++) {
                $tmp[] = $h[$ptr + 0 * $len];
                $tmp[] = $h[$ptr + 1 * $len];
                $tmp[] = $h[$ptr + 2 * $len];
                $ptr++;
            }
            for ($j = 0; $j < 3 * $len; $j++) $h[$start + $j] = $tmp[$j];
            $ptr += 2 * $len;
            $start += 3 * $len;
        }
    }

    /** compute_antialias() */
    private function computeAntialias(array &$g): void
    {
        if ($g['bt'] === 2) {
            if (!$g['sp']) return;
            $n = 1;
        } else {
            $n = 31;
        }
        $h = &$g['hybrid'];
        $ptr = 18;
        for ($i = $n; $i > 0; $i--) {
            for ($j = 0; $j < 8; $j++) {
                $a = $h[$ptr - 1 - $j];
                $b = $h[$ptr + $j];
                $h[$ptr - 1 - $j] = $a * self::CSA[$j][0] - $b * self::CSA[$j][1];
                $h[$ptr + $j]     = $a * self::CSA[$j][1] + $b * self::CSA[$j][0];
            }
            $ptr += 18;
        }
    }

    /** 0.5 / cos(pi*(2*i+1)/36)，imdct36 用 */
    private const ICOS36 = [
        0.5019099187716737, 0.5176380902050415, 0.5516889594812459,
        0.6103872943807280, 0.7071067811865475, 0.8717233978105490,
        1.1831007915762493, 1.9318516525781366, 5.7368566228349276,
    ];
    private const ICOS36H = [
        0.25095495938583685, 0.25881904510252074, 0.27584447974062294,
        0.30519364719036402, 0.35355339059327373, 0.43586169890527450,
        0.29577519789406232, 0.48296291314453414,
    ];

    /**
     * compute_imdct()：长块 IMDCT36 + overlap；短块三窗 IMDCT12 旋转；
     * 零带冲刷历史；最后 18 个时间槽过 32 子带合成滤波器组。
     * 历史缓冲简化为 [32 子带][18 槽]（等价 ffmpeg 的 mdct_buf 分组打包布局）。
     */
    private function computeImdct(array &$g, int $ch, array &$outPlane): void
    {
        $h = &$g['hybrid'];

        // 找最后一个非零 6 样本组 → sblimit（ffmpeg: ptr 从 576 回退，最低探到 36）
        $ptr = 576;
        while ($ptr >= 36) {
            $ptr -= 6;
            $nz = false;
            for ($z = 0; $z < 6; $z++) {
                if ($h[$ptr + $z] != 0.0) { $nz = true; break; }
            }
            if ($nz) break;
        }
        $sblimit = intdiv($ptr, 18) + 1;

        $mdctLongEnd = $g['bt'] === 2 ? ($g['sp'] ? 2 : 0) : $sblimit;

        $sbOut = array_fill(0, 18, array_fill(0, 32, 0.0));

        // 长块子带
        for ($sb = 0; $sb < $mdctLongEnd; $sb++) {
            $winIdx = ($g['sp'] && $sb < 2) ? 0 : $g['bt'];
            $win = $this->mdctWin[$winIdx + (($sb & 1) ? 4 : 0)];
            $this->imdct36($sbOut, $sb, $this->mdctOv[$ch][$sb], $h, 18 * $sb, $win);
        }

        // 短块子带
        for ($sb = $mdctLongEnd; $sb < $sblimit; $sb++) {
            $win = $this->mdctWin[2 + (($sb & 1) ? 4 : 0)];
            $b = &$this->mdctOv[$ch][$sb];
            $base = 18 * $sb;
            for ($i = 0; $i < 6; $i++) $sbOut[$i][$sb] = $b[$i];

            $o = array_fill(0, 12, 0.0);
            $this->imdct12($o, $h, $base + 0);
            for ($i = 0; $i < 6; $i++) {
                $sbOut[6 + $i][$sb] = $o[$i] * $win[$i] + $b[6 + $i];
                $b[12 + $i] = $o[6 + $i] * $win[6 + $i];
            }
            $this->imdct12($o, $h, $base + 1);
            for ($i = 0; $i < 6; $i++) {
                $sbOut[12 + $i][$sb] = $o[$i] * $win[$i] + $b[12 + $i];
                $b[$i] = $o[6 + $i] * $win[6 + $i];
            }
            $this->imdct12($o, $h, $base + 2);
            for ($i = 0; $i < 6; $i++) {
                $b[$i]      = $o[$i] * $win[$i] + $b[$i];
                $b[6 + $i]  = $o[6 + $i] * $win[6 + $i];
                $b[12 + $i] = 0.0;
            }
            unset($b);
        }

        // 零带：仅输出 overlap 历史并清零
        for ($sb = $sblimit; $sb < 32; $sb++) {
            $b = &$this->mdctOv[$ch][$sb];
            for ($i = 0; $i < 18; $i++) {
                $sbOut[$i][$sb] = $b[$i];
                $b[$i] = 0.0;
            }
            unset($b);
        }

        // 32 子带合成滤波器组：18 时间槽 × 32 采样
        for ($t = 0; $t < 18; $t++) {
            $this->synthFilter($ch, $sbOut[$t], $outPlane);
        }
    }

    /**
     * imdct36()（mpegaudiodsp_template.c），float 版：
     * MULH3(x,y,s)=s*y*x，常数已折算最终倍率；in 会被原地预处理修改。
     */
    private function imdct36(array &$out, int $sb, array &$buf, array &$in,
                             int $b0, array $win): void
    {
        for ($i = 17; $i >= 1; $i--) $in[$b0 + $i] += $in[$b0 + $i - 1];
        for ($i = 17; $i >= 3; $i -= 2) $in[$b0 + $i] += $in[$b0 + $i - 2];

        $tmp = array_fill(0, 18, 0.0);
        for ($j = 0; $j < 2; $j++) {
            $i0 = $b0 + $j;
            $t2 = $in[$i0 + 8] + $in[$i0 + 16] - $in[$i0 + 4];

            $t3 = $in[$i0] + 0.5 * $in[$i0 + 12];
            $t1 = $in[$i0] - $in[$i0 + 12];
            $tmp[$j + 6]  = $t1 - 0.5 * $t2;
            $tmp[$j + 16] = $t1 + $t2;

            $t0 = ($in[$i0 + 4] + $in[$i0 + 8]) * 0.9396926207859084;
            $t1 = ($in[$i0 + 8] - $in[$i0 + 16]) * -0.17364817766693033;
            $t2 = ($in[$i0 + 4] + $in[$i0 + 16]) * -0.7660444431189780;

            $tmp[$j + 10] = $t3 - $t0 - $t2;
            $tmp[$j + 2]  = $t3 + $t0 + $t1;
            $tmp[$j + 14] = $t3 + $t2 - $t1;

            $tmp[$j + 4] = ($in[$i0 + 10] + $in[$i0 + 14] - $in[$i0 + 2]) * -0.8660254037844386;
            $t2 = ($in[$i0 + 2] + $in[$i0 + 10]) * 0.9848077530122080;
            $t3 = ($in[$i0 + 10] - $in[$i0 + 14]) * -0.3420201433256687;
            $t0 = $in[$i0 + 6] * 0.8660254037844386;
            $t1 = ($in[$i0 + 2] + $in[$i0 + 14]) * -0.6427876096865394;

            $tmp[$j]      = $t2 + $t3 + $t0;
            $tmp[$j + 12] = $t2 + $t1 - $t0;
            $tmp[$j + 8]  = $t3 - $t1 - $t0;
        }

        $i = 0;
        for ($j = 0; $j < 4; $j++) {
            $s0 = $tmp[$i + 2] + $tmp[$i];
            $s2 = $tmp[$i + 2] - $tmp[$i];
            $s1 = ($tmp[$i + 3] + $tmp[$i + 1]) * (2.0 * self::ICOS36H[$j]);
            $s3 = ($tmp[$i + 3] - $tmp[$i + 1]) * self::ICOS36[8 - $j];

            $t0 = $s0 + $s1;
            $t1 = $s0 - $s1;
            $out[9 + $j][$sb] = $t1 * $win[9 + $j] + $buf[9 + $j];
            $out[8 - $j][$sb] = $t1 * $win[8 - $j] + $buf[8 - $j];
            $buf[9 + $j] = $t0 * $win[20 + 9 + $j];
            $buf[8 - $j] = $t0 * $win[20 + 8 - $j];

            $t0 = $s2 + $s3;
            $t1 = $s2 - $s3;
            $out[17 - $j][$sb] = $t1 * $win[17 - $j] + $buf[17 - $j];
            $out[$j][$sb]      = $t1 * $win[$j] + $buf[$j];
            $buf[17 - $j] = $t0 * $win[20 + 17 - $j];
            $buf[$j]      = $t0 * $win[20 + $j];
            $i += 4;
        }

        $s0 = $tmp[16];
        $s1 = $tmp[17] * (2.0 * self::ICOS36H[4]);
        $t0 = $s0 + $s1;
        $t1 = $s0 - $s1;
        $out[13][$sb] = $t1 * $win[13] + $buf[13];
        $out[4][$sb]  = $t1 * $win[4] + $buf[4];
        $buf[13] = $t0 * $win[33];
        $buf[4]  = $t0 * $win[24];
    }

    /**
     * imdct12()（mpegaudiodec_template.c），短块三个短窗之一，
     * 输入 stride=3，$b0 为窗相位偏移（0/1/2）。
     */
    private function imdct12(array &$out, array &$in, int $b0): void
    {
        $in0 = $in[$b0];
        $in1 = $in[$b0 + 3] + $in[$b0];
        $in2 = $in[$b0 + 6] + $in[$b0 + 3];
        $in3 = $in[$b0 + 9] + $in[$b0 + 6];
        $in4 = $in[$b0 + 12] + $in[$b0 + 9];
        $in5 = $in[$b0 + 15] + $in[$b0 + 12];
        $in5 += $in3;
        $in3 += $in1;

        $in2 *= 0.8660254037844386;       // MULH3(x,C3,2)
        $in3 *= 1.7320508075688772;       // MULH3(x,C3,4)

        $t1 = $in0 - $in4;
        $t2 = ($in1 - $in5) * 0.7071067811865476;
        $out[7] = $out[10] = $t1 + $t2;
        $out[1] = $out[4]  = $t1 - $t2;

        $in0 += 0.5 * $in4;
        $in4 = $in0 + $in2;
        $in5 += 2 * $in1;
        $in1 = ($in5 + $in3) * 0.25881904510252074;
        $out[8] = $out[9] = $in4 + $in1;
        $out[2] = $out[3] = $in4 - $in1;

        $in0 -= $in2;
        $in5 = ($in5 - $in3) * 0.9659258262890683;
        $out[0] = $out[5] = $in0 - $in5;
        $out[6] = $out[11] = $in0 + $in5;
    }

    /** dct32 常数（定义中的 /N 与蝶形 s 位移抵消，此处为最终倍率） */
    private const DCT_C0 = [
        0.5006029982351963, 0.5054709598975437, 0.5154473099226245,
        0.5310425910897842, 0.5531038960344445, 0.5829349682061339,
        0.6225041230356648, 0.6748083414550057, 0.7445362710022984,
        0.8393496454155270, 0.9725682378619607, 1.1694399334328850,
        1.4841646163141663, 2.0577810099534116, 3.4076084184687188,
        10.190008123548057,
    ];
    private const DCT_C1 = [
        0.5024192861881557, 0.5224986149396889, 0.5669440348163577,
        0.6468217833599901, 0.7881546234512502, 1.0606776859903475,
        1.7224470982383339, 5.101148618689164,
    ];
    private const DCT_C2 = [
        0.5097955791041592, 0.6013448869350453, 0.8999762231364157,
        2.562915447741506,
    ];
    private const DCT_C3 = [0.5411961001461970, 1.3065629648763766];
    private const DCT_C4 = 0.7071067811865476;

    /** dct32_template.c 逐蝶形移植，输入 32 子带样本，输出 32 点 */
    private function dct32(array $tab): array
    {
        $v = [];
        $bf0 = function (int $a, int $b, float $c) use (&$v, $tab): void {
            $t0 = $tab[$a] + $tab[$b];
            $t1 = $tab[$a] - $tab[$b];
            $v[$a] = $t0;
            $v[$b] = $t1 * $c;
        };
        $bf = function (int $a, int $b, float $c) use (&$v): void {
            $t0 = $v[$a] + $v[$b];
            $t1 = $v[$a] - $v[$b];
            $v[$a] = $t0;
            $v[$b] = $t1 * $c;
        };
        $bf1 = function (int $a, int $b, int $c, int $d) use ($bf, &$v): void {
            $bf($a, $b, self::DCT_C4);
            $bf($c, $d, -self::DCT_C4);
            $v[$c] += $v[$d];
        };
        $bf2 = function (int $a, int $b, int $c, int $d) use ($bf, &$v): void {
            $bf($a, $b, self::DCT_C4);
            $bf($c, $d, -self::DCT_C4);
            $v[$c] += $v[$d];
            $v[$a] += $v[$c];
            $v[$c] += $v[$b];
            $v[$b] += $v[$d];
        };
        $c0 = self::DCT_C0;
        $c1 = self::DCT_C1;
        $c2 = self::DCT_C2;
        $c3 = self::DCT_C3;

        $bf0(0, 31, $c0[0]);  $bf0(15, 16, $c0[15]);
        $bf(0, 15, $c1[0]);   $bf(16, 31, -$c1[0]);
        $bf0(7, 24, $c0[7]);  $bf0(8, 23, $c0[8]);
        $bf(7, 8, $c1[7]);    $bf(23, 24, -$c1[7]);
        $bf(0, 7, $c2[0]);    $bf(8, 15, -$c2[0]);
        $bf(16, 23, $c2[0]);  $bf(24, 31, -$c2[0]);
        $bf0(3, 28, $c0[3]);  $bf0(12, 19, $c0[12]);
        $bf(3, 12, $c1[3]);   $bf(19, 28, -$c1[3]);
        $bf0(4, 27, $c0[4]);  $bf0(11, 20, $c0[11]);
        $bf(4, 11, $c1[4]);   $bf(20, 27, -$c1[4]);
        $bf(3, 4, $c2[3]);    $bf(11, 12, -$c2[3]);
        $bf(19, 20, $c2[3]);  $bf(27, 28, -$c2[3]);
        $bf(0, 3, $c3[0]);    $bf(4, 7, -$c3[0]);
        $bf(8, 11, $c3[0]);   $bf(12, 15, -$c3[0]);
        $bf(16, 19, $c3[0]);  $bf(20, 23, -$c3[0]);
        $bf(24, 27, $c3[0]);  $bf(28, 31, -$c3[0]);

        $bf0(1, 30, $c0[1]);  $bf0(14, 17, $c0[14]);
        $bf(1, 14, $c1[1]);   $bf(17, 30, -$c1[1]);
        $bf0(6, 25, $c0[6]);  $bf0(9, 22, $c0[9]);
        $bf(6, 9, $c1[6]);    $bf(22, 25, -$c1[6]);
        $bf(1, 6, $c2[1]);    $bf(9, 14, -$c2[1]);
        $bf(17, 22, $c2[1]);  $bf(25, 30, -$c2[1]);

        $bf0(2, 29, $c0[2]);  $bf0(13, 18, $c0[13]);
        $bf(2, 13, $c1[2]);   $bf(18, 29, -$c1[2]);
        $bf0(5, 26, $c0[5]);  $bf0(10, 21, $c0[10]);
        $bf(5, 10, $c1[5]);   $bf(21, 26, -$c1[5]);
        $bf(2, 5, $c2[2]);    $bf(10, 13, -$c2[2]);
        $bf(18, 21, $c2[2]);  $bf(26, 29, -$c2[2]);
        $bf(1, 2, $c3[1]);    $bf(5, 6, -$c3[1]);
        $bf(9, 10, $c3[1]);   $bf(13, 14, -$c3[1]);
        $bf(17, 18, $c3[1]);  $bf(21, 22, -$c3[1]);
        $bf(25, 26, $c3[1]);  $bf(29, 30, -$c3[1]);

        $bf1(0, 1, 2, 3);
        $bf2(4, 5, 6, 7);
        $bf1(8, 9, 10, 11);
        $bf2(12, 13, 14, 15);
        $bf1(16, 17, 18, 19);
        $bf2(20, 21, 22, 23);
        $bf1(24, 25, 26, 27);
        $bf2(28, 29, 30, 31);

        $v[8]  += $v[12];
        $v[12] += $v[10];
        $v[10] += $v[14];
        $v[14] += $v[9];
        $v[9]  += $v[13];
        $v[13] += $v[11];
        $v[11] += $v[15];

        $out = [];
        $out[0]  = $v[0];  $out[16] = $v[1];
        $out[8]  = $v[2];  $out[24] = $v[3];
        $out[4]  = $v[4];  $out[20] = $v[5];
        $out[12] = $v[6];  $out[28] = $v[7];
        $out[2]  = $v[8];  $out[18] = $v[9];
        $out[10] = $v[10]; $out[26] = $v[11];
        $out[6]  = $v[12]; $out[22] = $v[13];
        $out[14] = $v[14]; $out[30] = $v[15];

        $v[24] += $v[28];
        $v[28] += $v[26];
        $v[26] += $v[30];
        $v[30] += $v[25];
        $v[25] += $v[29];
        $v[29] += $v[27];
        $v[27] += $v[31];

        $out[1]  = $v[16] + $v[24];
        $out[17] = $v[17] + $v[25];
        $out[9]  = $v[18] + $v[26];
        $out[25] = $v[19] + $v[27];
        $out[5]  = $v[20] + $v[28];
        $out[21] = $v[21] + $v[29];
        $out[13] = $v[22] + $v[30];
        $out[29] = $v[23] + $v[31];
        $out[3]  = $v[24] + $v[20];
        $out[19] = $v[25] + $v[21];
        $out[11] = $v[26] + $v[22];
        $out[27] = $v[27] + $v[23];
        $out[7]  = $v[28] + $v[18];
        $out[23] = $v[29] + $v[19];
        $out[15] = $v[30] + $v[17];
        $out[31] = $v[31];
        return $out;
    }

    /**
     * ff_mpa_synth_filter + ff_mpadsp_apply_window（float 版）：
     * DCT32 写入环形缓冲，合成窗输出 32 PCM 采样追加到 $outPlane。
     */
    private function synthFilter(int $ch, array $sbRow, array &$outPlane): void
    {
        $ring = &$this->synthBuf[$ch];
        $off = $this->synthOff[$ch];
        $w = $this->synthWin;

        $dct = $this->dct32($sbRow);
        for ($k = 0; $k < 32; $k++) {
            $ring[$off + $k] = $dct[$k];
            $ring[$off + 512 + $k] = $dct[$k]; // memcpy(synth+512, synth, 32)
        }

        $s = [];
        // sample 0
        $sum = 0.0;
        for ($k = 0; $k < 8; $k++) $sum += $w[$k * 64]        * $ring[$off + 16 + $k * 64];
        for ($k = 0; $k < 8; $k++) $sum -= $w[32 + $k * 64]   * $ring[$off + 48 + $k * 64];
        $s[0] = $sum;

        // sample j 与 sample 32-j 成对
        for ($j = 1; $j < 16; $j++) {
            $sum = 0.0;
            $sum2 = 0.0;
            for ($k = 0; $k < 8; $k++) {
                $x = $ring[$off + 16 + $j + $k * 64];
                $sum  += $w[$j + $k * 64] * $x;
                $sum2 -= $w[32 - $j + $k * 64] * $x;
            }
            for ($k = 0; $k < 8; $k++) {
                $x = $ring[$off + 48 - $j + $k * 64];
                $sum  -= $w[$j + 32 + $k * 64] * $x;
                $sum2 -= $w[64 - $j + $k * 64] * $x;
            }
            $s[$j] = $sum;
            $s[32 - $j] = $sum2;
        }

        // sample 16
        $sum = 0.0;
        for ($k = 0; $k < 8; $k++) $sum -= $w[48 + $k * 64] * $ring[$off + 32 + $k * 64];
        $s[16] = $sum;

        for ($k = 0; $k < 32; $k++) $outPlane[] = $s[$k];

        $this->synthOff[$ch] = ($off - 32) & 511;
    }
}
