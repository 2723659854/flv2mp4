<?php
use Xiaosongshu\Flv2mp4\Codec\Encode\MotionWorkerServer;
$port=8340;$autoload=null;$owned=in_array('--owned',$argv,true);foreach($argv as $a){if(str_starts_with($a,'--port='))$port=(int)substr($a,7);if(str_starts_with($a,'--autoload='))$autoload=substr($a,11);} $autoload??=dirname(__DIR__).'/vendor/autoload.php';require_once $autoload;(new MotionWorkerServer())->run("tcp://127.0.0.1:{$port}",$owned?1.0:null);
