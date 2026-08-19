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
            foreach($read as $s){$id=(int)$s;if(!isset($connections[$id]))continue;$d=@fread($s,65536);if($d===false||($d===''&&feof($s))){fclose($s);unset($connections[$id]);continue;}$connections[$id]['input'].=$d;foreach(MotionWorkerProtocol::takeFrames($connections[$id]['input'],1) as $body){try{$v=unserialize($body,['allowed_classes'=>false]);[$request,$width,$height,$aw,$ah,$ref,$blocks]=$v;$result=[];$helper=new MotionWorkerHelper($width,$height,$aw,$ah);foreach($blocks as $index=>$block)$result[$index]=$helper->estimate($block,$ref);$connections[$id]['output'].=MotionWorkerProtocol::frame(serialize([$request,$result]));}catch(Throwable $x){$connections[$id]['output'].=MotionWorkerProtocol::frame(serialize([0,['error'=>$x->getMessage()]]));}}}
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
    use MotionTrait;
    public int $width; public int $height; public int $mbAlignedWidth; public int $mbAlignedHeight; public $refInts=null;
    public function __construct(int $w,int $h,int $aw,int $ah){$this->width=$w;$this->height=$h;$this->mbAlignedWidth=$aw;$this->mbAlignedHeight=$ah;}
    public function estimate(array $job,string $ref): array {return $this->motionEstimate16x16($job['block'],$ref,$job['x'],$job['y'],$job['range']);}
}
