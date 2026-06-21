<?php

namespace Xiaosongshu\Flv2mp4\Manage;


/**
 * @purpose MP4推流客户端
 * @author yanglong
 * @time 2026年6月12日13:51:41
 * @comment 此方法是将mp4文件转码为flv，然后推流，存在硬盘溢出风险，存在相同名称文件冲突风险。但推流绝对正确
 */
class Mp4PusherOther {

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
