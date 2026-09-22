<?php
// Windows下worker与父进程共享控制台：父级直播客户端收到Ctrl+C做优雅收尾时，
// 广播的CTRL事件不能把worker先杀掉（否则父级finishStream报"Motion worker closed"）。
// 注册空处理器即拦截CTRL_C/CTRL_BREAK，worker生命周期由父进程通过TCP管理。
if (function_exists('sapi_windows_set_ctrl_handler')) sapi_windows_set_ctrl_handler(function (int $e): void {});
use Xiaosongshu\Flv2mp4\Codec\Encode\MotionWorkerServer;
$port=8340;$autoload=null;$owned=in_array('--owned',$argv,true);foreach($argv as $a){if(str_starts_with($a,'--port='))$port=(int)substr($a,7);if(str_starts_with($a,'--autoload='))$autoload=substr($a,11);} ini_set('memory_limit','512M'); $autoload??=dirname(__DIR__).'/vendor/autoload.php';require_once $autoload;(new MotionWorkerServer())->run("tcp://127.0.0.1:{$port}",$owned?1.0:null);
