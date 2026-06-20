<?php

namespace Xiaosongshu\Flv2mp4\SabreAMF\AMF3;
/**
 * @purpose SabreAMF_AMF3_AcknowledgeMessage
 * @author yanglong
 * @comment This is the receipt for any message that being sent
 */
class SabreAMF_AMF3_AcknowledgeMessage extends SabreAMF_AMF3_AbstractMessage
{

    /**
     * The ID of the message where this is a receipt of
     *
     * @var string
     */
    public $correlationId;

    public function __construct(?SabreAMF_AMF3_AbstractMessage $message = null)
    {

        $this->messageId = $this->generateRandomId();
        $this->clientId = $this->generateRandomId();
        $this->destination = null;
        $this->body = null;
        $this->timeToLive = 0;
        $this->timestamp = time() . '00';
        $this->headers = new \STDClass();

        if ($message) {
            $this->correlationId = $message->messageId;
        }

    }

}


