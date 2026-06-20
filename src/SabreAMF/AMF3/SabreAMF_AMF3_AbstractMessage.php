<?php

namespace Xiaosongshu\Flv2mp4\SabreAMF\AMF3;
/**
 * @purpose SabreAMF_AMF3_AbstractMessage
 * @author yanglong
 */
abstract class SabreAMF_AMF3_AbstractMessage
{

    /**
     * The body of the message
     *
     * @var mixed
     */
    public $body;

    /**
     * Unique client ID
     *
     * @var string
     */
    public $clientId;

    /**
     * destination
     *
     * @var string
     */
    public $destination;

    /**
     * Message headers
     *
     * @var array
     */
    public $headers;

    /**
     * Unique message ID
     *
     * @var string
     */
    public $messageId;

    /**
     * timeToLive
     *
     * @var int
     */
    public $timeToLive;

    /**
     * timestamp
     *
     * @var int
     */
    public $timestamp;

    public function generateRandomId()
    {

        $SabreAMFID = '44445501';

        $id = md5(microtime());

        return $SabreAMFID . '-' . substr($id, 0, 4) . '-' . substr($id, 4, 4) . '-' . substr($id, 8, 12);

    }

}


