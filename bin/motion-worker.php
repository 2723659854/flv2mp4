<?php
use Xiaosongshu\Flv2mp4\Codec\Encode\MotionWorkerServer;
$port=8340;$autoload=null;$owned=in_array('--owned',$argv,true);
// 编码选项由主进程启动参数显式注入（禁止子进程读环境变量作为功能开关）
$earlySkip=true;$subpelMul=4.0;
foreach($argv as $a){
    if(str_starts_with($a,'--port='))$port=(int)substr($a,7);
    elseif(str_starts_with($a,'--autoload='))$autoload=substr($a,11);
    elseif(str_starts_with($a,'--early-skip='))$earlySkip=substr($a,13)==='1';
    elseif(str_starts_with($a,'--subpel-mul='))$subpelMul=(float)substr($a,13);
}
ini_set('memory_limit','512M'); $autoload??=dirname(__DIR__).'/vendor/autoload.php';require_once $autoload;
(new MotionWorkerServer(['early_skip'=>$earlySkip,'subpel_sad_mul'=>$subpelMul>0?$subpelMul:4.0]))
    ->run("tcp://127.0.0.1:{$port}",$owned?1.0:null);
