<?php

namespace Xiaosongshu\Flv2mp4\SabreAMF\AMF3;
/**
 * @purpose SabreAMF_AMF3_CommandMessage
 * @author yanglong
 * @comment This class is used for service commands, like pinging the MediaServer
 */
class SabreAMF_AMF3_CommandMessage extends SabreAMF_AMF3_AbstractMessage
{

    const SUBSCRIBE_OPERATION = 0;
    const UNSUSBSCRIBE_OPERATION = 1;
    const POLL_OPERATION = 2;
    const CLIENT_SYNC_OPERATION = 4;
    const CLIENT_PING_OPERATION = 5;
    const CLUSTER_REQUEST_OPERATION = 7;
    const LOGIN_OPERATION = 8;
    const LOGOUT_OPERATION = 9;
    const SESSION_INVALIDATE_OPERATION = 10;
    const MULTI_SUBSCRIBE_OPERATION = 11;
    const DISCONNECT_OPERATION = 12;

    /**
     * operation
     *
     * @var int
     */
    public $operation;

    /**
     * messageRefType
     *
     * @var int
     */
    public $messageRefType;

    /**
     * correlationId
     *
     * @var string
     */
    public $correlationId;

}


