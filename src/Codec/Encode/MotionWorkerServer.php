<?php
namespace Xiaosongshu\Flv2mp4\Codec\Encode;

use RuntimeException;
use Throwable;
final class MotionWorkerServer
{
    public function run(string $address, ?float $idle = null): void
    {
        $server=@stream_socket_server($address,$errno,$error); if($server===false) throw new RuntimeException("Unable to listen on {$address}: {$error} ({$errno})"); stream_set_blocking($server,false); $connections=[]; $accepted=false; $since=microtime(true);
        while(true){ if($accepted&&$idle!==null&&!$connections&&microtime(true)-$since>=$idle){fclose($server);return;} $read=[$server]; foreach($connections as $c)$read[]=$c['socket']; $w=null;$e=null; @stream_select($read,$w,$e,0,1000); if(in_array($server,$read,true)){while(($s=@stream_socket_accept($server,0))!==false){stream_set_blocking($s,false);$connections[(int)$s]=['socket'=>$s,'input'=>'','output'=>''];$accepted=true;}$read=array_filter($read,fn($s)=>$s!==$server);}
            foreach($read as $s){$id=(int)$s;if(!isset($connections[$id]))continue;$d=@fread($s,65536);if($d===false||($d===''&&feof($s))){fclose($s);unset($connections[$id]);continue;}$connections[$id]['input'].=$d;foreach(MotionWorkerProtocol::takeFrames($connections[$id]['input'],1) as $body){$request=0;try{$v=unserialize($body,['allowed_classes'=>false]);[$request,$width,$height,$aw,$ah,$qp,$refY,$refU,$refV,$blocks]=$v;$result=[];$helper=new MotionWorkerHelper($width,$height,$aw,$ah,$qp);foreach($blocks as $index=>$block)$result[$index]=$helper->prepare($block,$refY,$refU,$refV);$connections[$id]['output'].=MotionWorkerProtocol::frame(serialize([$request,true,$result]));}catch(Throwable $x){$connections[$id]['output'].=MotionWorkerProtocol::frame(serialize([$request,false,$x->getMessage()]));}}}
            foreach(array_keys($connections) as $id){if($connections[$id]['output']==='')continue;$n=@fwrite($connections[$id]['socket'],$connections[$id]['output']);if($n===false){fclose($connections[$id]['socket']);unset($connections[$id]);}elseif($n>0)$connections[$id]['output']=substr($connections[$id]['output'],$n);}
        }
    }
}
final class MotionWorkerHelper
{
    private const INTERP_TAP0 = 1;
    private const INTERP_TAP1 = -5;
    private const INTERP_TAP2 = 20;
    private const INTERP_TAP3 = 20;
    private const INTERP_TAP4 = -5;
    private const INTERP_TAP5 = 1;
    use MotionTrait, TransformTrait, InterPredTrait;
    private const DEQUANT4_COEFF_INIT = [[10,13,16],[11,14,18],[13,16,20],[14,18,23],[16,20,25],[18,23,29]];
    private const QUANT_MF = \Xiaosongshu\Flv2mp4\Codec\H264Encoder::QUANT_MF;
    private const QUANT_INTER_FF = \Xiaosongshu\Flv2mp4\Codec\H264Encoder::QUANT_INTER_FF;
    private const ZIGZAG_SCAN_4X4 = \Xiaosongshu\Flv2mp4\Codec\H264Encoder::ZIGZAG_SCAN_4X4;
    public int $width; public int $height; public int $mbAlignedWidth; public int $mbAlignedHeight; public int $qp; public array $dequant4Table=[]; public $refInts=null;
    public function __construct(int $w,int $h,int $aw,int $ah,int $qp){$this->width=$w;$this->height=$h;$this->mbAlignedWidth=$aw;$this->mbAlignedHeight=$ah;$this->qp=$qp;$posClass=[0,1,0,1,1,2,1,2,0,1,0,1,1,2,1,2];$this->dequant4Table=array_fill(0,6,array_fill(0,52,array_fill(0,16,0)));for($i=0;$i<6;$i++)for($q=0;$q<52;$q++){ $shift=intdiv($q,6)+2;$idx=$q%6;for($x=0;$x<16;$x++)$this->dequant4Table[$i][$q][$x]=(self::DEQUANT4_COEFF_INIT[$idx][$posClass[$x]]*16)<<$shift;}}
    public function prepare(array $job,string $refY,string $refU,string $refV): array {return $this->preparePMacroblock($job[0],$job[1],$job[2],$refY,$refU,$refV,$job[3]);}
}
