<?php

namespace Xiaosongshu\Flv2mp4\manage;


/**
 * @purpose MP4推流客户端
 * @author yanglong
 * @time 2026年6月12日13:51:41
 */
class Mp4Pusher {

    protected $mp4ConverToFlv = null;

    protected $outputFlvFile = null;

    protected $flvPusher = null;

    public function __construct($filePath, $pushUrl, $speed = 1.0, $autoReconnect = true) {

        $dirName = dirname($filePath);
        $this->outputFlvFile = $dirName . "/" . uniqid().time() . ".flv";
        try{
            $this->mp4ConverToFlv = new Mp4ToFlv($filePath,$this->outputFlvFile);
            if ($this->mp4ConverToFlv->run()){
                $this->flvPusher = new FlvPusher($this->outputFlvFile,$pushUrl, $speed,$autoReconnect);
            }
        }catch (\Exception $e){
            throw new \RuntimeException($e->getMessage());
        }
    }

    public function start()
    {
        if ($this->flvPusher) {
            $this->flvPusher->start();
            if (file_exists($this->outputFlvFile)) {
                @unlink($this->outputFlvFile);
            }
        }
    }


}
