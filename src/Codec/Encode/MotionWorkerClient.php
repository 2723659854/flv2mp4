<?php
namespace Xiaosongshu\Flv2mp4\Codec\Encode;

use RuntimeException;
final class MotionWorkerClient
{
    private array $sockets=[]; private array $inputs=[]; private array $outputs=[]; private array $processes=[]; private int $id=1;
    public function __construct(private int $port=8340, private int $workers=0){$this->workers=$workers>0?$workers:max(1,min(4,(int)(getenv('NUMBER_OF_PROCESSORS')?:2)));}
    public function batch(int $width,int $height,int $aw,int $ah,int $qp,string $refY,string $refU,string $refV,array $blocks): array {
        $this->connectAll();$chunks=array_fill(0,$this->workers,[]);foreach($blocks as $k=>$v)$chunks[$k%$this->workers][$k]=$v;$ids=[];
        foreach($chunks as $i=>$chunk){if(!$chunk)continue;$id=$this->id++;$ids[$i]=$id;$this->outputs[$i].=MotionWorkerProtocol::batch($id,$width,$height,$aw,$ah,$qp,$refY,$refU,$refV,$chunk);}
        $result=[];$deadline=microtime(true)+30;while(count($result)<count($blocks)&&microtime(true)<$deadline){foreach($ids as $i=>$id){$this->pump($i);foreach(MotionWorkerProtocol::takeFrames($this->inputs[$i],16) as $body){[$rid,$ok,$part]=unserialize($body,['allowed_classes'=>false]);if($rid!==$id)throw new RuntimeException("Unexpected motion worker response {$rid}");if(!$ok)throw new RuntimeException('Motion worker failed: '.$part);foreach($part as $k=>$v)$result[$k]=$v;unset($ids[$i]);}}if(count($result)<count($blocks))usleep(1000);}if(count($result)!==count($blocks))throw new RuntimeException('Timed out motion worker batch');ksort($result);return $result;
    }
    private function connectAll(): void {for($i=0;$i<$this->workers;$i++){if(isset($this->sockets[$i])&&is_resource($this->sockets[$i]))continue;$port=$this->port+$i;$socket=@stream_socket_client("tcp://127.0.0.1:{$port}",$e,$m,.1);if($socket===false){$entry=dirname(__DIR__,3).'/bin/motion-worker.php';$autoload=dirname((new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName(),2).'/autoload.php';$d=[fopen('php://stdin','r'),fopen('php://stdout','a'),fopen('php://stderr','a')];$p=@proc_open([PHP_BINARY,$entry,'--owned',"--port={$port}","--autoload={$autoload}"],$d,$pipes,null,null,['bypass_shell'=>true]);if(!is_resource($p))throw new RuntimeException('Unable to start motion worker');$this->processes[]=$p;$end=microtime(true)+2;do{usleep(20000);$socket=@stream_socket_client("tcp://127.0.0.1:{$port}",$e,$m,.1);}while($socket===false&&microtime(true)<$end);}if($socket===false)throw new RuntimeException("Unable to connect motion worker {$port}");stream_set_blocking($socket,false);$this->sockets[$i]=$socket;$this->inputs[$i]='';$this->outputs[$i]='';}}
    private function pump(int $i): void {$s=$this->sockets[$i];if($this->outputs[$i]!==''){$n=@fwrite($s,$this->outputs[$i]);if($n===false)throw new RuntimeException('Failed writing motion worker');if($n)$this->outputs[$i]=substr($this->outputs[$i],$n);}while(($d=@fread($s,65536))!==false&&$d!=='')$this->inputs[$i].=$d;if(feof($s))throw new RuntimeException('Motion worker closed');}
    public function __destruct(){foreach($this->sockets as $s)if(is_resource($s))@fclose($s);foreach($this->processes as $p){if(is_resource($p)){if((proc_get_status($p)['running']??false))@proc_terminate($p);@proc_close($p);}}}
}
