<?php

namespace Xiaosongshu\Flv2mp4\SabreAMF\AMF3;
/**
 * @purpose SabreAMF_AMF3_RemotingMessage
 * @comment 远程调用服务上的消息 Invokes a message on a service
 * @author yanglong
 */
class SabreAMF_AMF3_RemotingMessage extends SabreAMF_AMF3_AbstractMessage
{

    /**
     * operation
     *
     * @var string
     */
    public $operation;

    /**
     * source
     *
     * @var string
     */
    public $source;

    /**
     * Creates the object and generates some values
     *
     * @return void
     */
    public function __construct()
    {

        $this->messageId = $this->generateRandomId();
        $this->clientId = $this->generateRandomId();
        $this->destination = null;
        $this->body = null;
        $this->timeToLive = 0;
        $this->timestamp = time() . '00';
        $this->headers = new \STDClass();

    }
}


