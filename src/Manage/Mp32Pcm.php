<?php
namespace Xiaosongshu\Flv2mp4\Manage;

use Xiaosongshu\Flv2mp4\Mp3\BitReader;
use Xiaosongshu\Flv2mp4\Mp3\HuffmanTables;
use Xiaosongshu\Flv2mp4\Mp3\Layer3Quantizer;
use Xiaosongshu\Flv2mp4\Mp3\Layer3ScalefactorBands;
use RuntimeException;

/**
 * @purpose mp3提取pcm
 * @author yanglong
 * @time 2026年9月8日15:08:46
 */
final class Mp32Pcm
{
    private string $inputFile;
    private string $outputFile;
    private int $sampleRate = 0;
    private int $channels = 0;

    /**
     * @param string $inputFile mp3文件
     * @param string $outputFile pcm文件
     */
    public function __construct(string $inputFile, string $outputFile)
    {
        if (!is_file($inputFile)) throw new RuntimeException("MP3文件不存在: {$inputFile}");
        $this->inputFile = $inputFile; $this->outputFile = $outputFile;
    }

    public function run(): array
    {
        $data = file_get_contents($this->inputFile);
        if ($data === false) throw new RuntimeException('无法读取 MP3 文件');
        $pcm = $this->decode($data);
        $dir = dirname($this->outputFile);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) throw new RuntimeException("无法创建输出目录: {$dir}");
        if (file_put_contents($this->outputFile, $pcm) === false) throw new RuntimeException('无法写入 PCM 文件');
        return ['output' => $this->outputFile, 'sampleRate' => $this->sampleRate, 'channels' => $this->channels, 'bytes' => strlen($pcm)];
    }

    private function decode(string $data): string
    {
        $offset = 0; $out = ''; $tables = HuffmanTables::TABLES; $bands = [];
        while ($offset + 4 <= strlen($data)) {
            $h = unpack('Nv', substr($data, $offset, 4)); $v = $h['v'];
            if (($v >> 21 & 0x7ff) !== 0x7ff) { ++$offset; continue; }
            if (($v >> 19 & 3) !== 3 || ($v >> 17 & 3) !== 1) throw new RuntimeException('仅支持 MPEG-1 Layer III');
            if (($v >> 16 & 1) !== 1) throw new RuntimeException('仅支持无 CRC MP3');
            $bi = ($v >> 12) & 15; $si = ($v >> 10) & 3; $padding = ($v >> 9) & 1;
            if ($bi < 1 || $bi > 14 || $si > 2) throw new RuntimeException('MP3 帧头无效');
            $rates = [44100, 48000, 32000]; $bitrates = [0,32000,40000,48000,56000,64000,80000,96000,112000,128000,160000,192000,224000,256000,320000];
            $this->sampleRate = $rates[$si]; $this->channels = (($v >> 6) & 3) === 3 ? 1 : 2;
            $frameLength = intdiv(144 * $bitrates[$bi], $this->sampleRate) + $padding;
            if ($offset + $frameLength > strlen($data)) break;
            $sideLen = $this->channels === 1 ? 17 : 32;
            $side = new BitReader(substr($data, $offset + 4, $sideLen * 1));
            $mainBegin = $side->read(9); if ($mainBegin !== 0) throw new RuntimeException('仅支持 main_data_begin=0');
            $side->skip($this->channels === 1 ? 5 : 3);
            $scfsi=[]; for($ch=0;$ch<$this->channels;$ch++){ $scfsi[$ch]=[]; for($i=0;$i<4;$i++)$scfsi[$ch][$i]=$side->read(1); }
            $gis=[];
            for($gr=0;$gr<2;$gr++) for($ch=0;$ch<$this->channels;$ch++){
                $gi=['len'=>$side->read(12),'big'=>$side->read(9)*2,'gain'=>$side->read(8),'comp'=>$side->read(4),'switch'=>$side->read(1)];
                if($gi['switch']) throw new RuntimeException('仅支持长块');
                $gi['tab']=[$side->read(5),$side->read(5),$side->read(5)]; $gi['r0']=$side->read(4); $gi['r1']=$side->read(3); $gi['pre']=$side->read(1); $gi['scale']=$side->read(1); $gi['count1']=$side->read(1); $gis[$gr][$ch]=$gi;
            }
            $mainBits = ($frameLength - 4 - $sideLen) * 8; $main = new BitReader(substr($data,$offset+4+$sideLen), intdiv($mainBits,1));
            if (!$bands) $bands=Layer3ScalefactorBands::long($this->sampleRate);
            $spec=array_fill(0,$this->channels,array_fill(0,1152,0.0));
            for($gr=0;$gr<2;$gr++) for($ch=0;$ch<$this->channels;$ch++) $this->decodeGranule($main,$gis[$gr][$ch],$bands,$spec[$ch],$gr,$tables);
            $out .= $this->render($spec);
            $offset += $frameLength;
        }
        return $out;
    }

    private function decodeGranule(BitReader $r,array $g,array $bands,array &$pcm,int $gr,array $tables): void
    {
        $start=$r->position(); $s1=Layer3Quantizer::SLEN1_TAB[$g['comp']]; $s2=Layer3Quantizer::SLEN2_TAB[$g['comp']]; $sf=[];
        for($i=0;$i<21;$i++) $sf[$i]=$r->read($i<11?$s1:$s2);
        $x=array_fill(0,576,0.0); $pos=0; $region0=min($g['big'], $bands[min(22,$g['r0']+1)]); $region1=min($g['big'],$bands[min(22,$g['r0']+$g['r1']+2)]);
        while($pos<$g['big']) { $tab=$pos<$region0?$g['tab'][0]:($pos<$region1?$g['tab'][1]:$g['tab'][2]); [$x[$pos],$x[$pos+1]]=$this->pair($r,$tab,$tables); $pos+=2; }
        $tab=32+$g['count1']; while($pos+3<576 && $r->position()-$start<$g['len']) { [$a,$b,$c,$d]=$this->quad($r,$tab,$tables); $x[$pos]=$a;$x[$pos+1]=$b;$x[$pos+2]=$c;$x[$pos+3]=$d;$pos+=4; }
        while($r->position()-$start<$g['len'])$r->read(1);
        $gain=pow(2.0,($g['gain']-210)/4.0);
        for($i=0;$i<576;$i++){ $band=0; while($band<21 && $i>=$bands[$band+1])++$band; $x[$i]=($x[$i]<0?-1:1)*pow(abs($x[$i]),4/3)*$gain*pow(2.0,-$sf[$band]*(1+$g['scale'])/2); }
        for($n=0;$n<576;$n++) $pcm[$gr*576+$n]=$x[$n];
    }

    private function pair(BitReader $r,int $tab,array $tables): array
    { if($tab===0)return [0,0]; $h=$tables[$tab]; $code=0; $idx=-1; $max=$tab>15?16:$h['width']; for($n=1;$n<=32;$n++){ $code=($code<<1)|$r->read(1); foreach($h['lengths'] as $i=>$len)if($len===$n&&$h['codes'][$i]===$code){$idx=$i;break 2;} } if($idx<0)throw new RuntimeException('Huffman code invalid'); $x=intdiv($idx,$max);$y=$idx%$max; if($h['linbits']){if($x===15)$x+= $r->read($h['linbits']);if($x&&$r->read(1))$x=-$x;if($y===15)$y+= $r->read($h['linbits']);if($y&&$r->read(1))$y=-$y;} else {if($x&&$r->read(1))$x=-$x;if($y&&$r->read(1))$y=-$y;} return[$x,$y]; }
    private function quad(BitReader $r,int $tab,array $tables): array
    { $h=$tables[$tab];$code=0;$idx=-1;for($n=1;$n<=16;$n++){ $code=($code<<1)|$r->read(1);foreach($h['lengths'] as $i=>$len)if($len===$n&&$h['codes'][$i]===$code){$idx=$i;break 2;}}if($idx<0)throw new RuntimeException('count1 Huffman code invalid');$a=[];for($i=3;$i>=0;$i--){$v=($idx>>$i)&1;$a[]=($v&&$r->read(1))?-1:$v;}return$a; }

    private function render(array $spec): string
    {
        $pcm = [];
        for ($ch = 0; $ch < $this->channels; ++$ch) {
            $pcm[$ch] = array_fill(0, 1152, 0.0);
            for ($gr = 0; $gr < 2; ++$gr) {
                for ($n = 0; $n < 576; ++$n) {
                    $sum = 0.0;
                    for ($k = 0; $k < 576; ++$k) {
                        $sum += $spec[$ch][$gr * 576 + $k]
                            * cos(M_PI / 576 * ($n + 0.5 + 288) * ($k + 0.5));
                    }
                    $pcm[$ch][$gr * 576 + $n] = $sum / 288.0;
                }
            }
        }
        $out = '';
        for ($n = 0; $n < 1152; ++$n) {
            for ($ch = 0; $ch < $this->channels; ++$ch) {
                $v = (int) max(-32768, min(32767, round($pcm[$ch][$n] * 32767.0 * 32.0)));
                $out .= pack('v', $v < 0 ? $v + 65536 : $v);
            }
        }
        return $out;
    }
}
