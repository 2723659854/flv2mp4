<?php

namespace Xiaosongshu\Flv2mp4\SabreAMF\AMF3;
/**
 * @purpose SabreAMF_AMF3_ErrorMessage
 * @comment This message is based on Abstract Message,This is the receipt for Error Messages
 * @author yanglong
 */
class SabreAMF_AMF3_ErrorMessage extends SabreAMF_AMF3_AcknowledgeMessage
{

    /**
     * Extended data that the remote destination has chosen to associate with
     * this error to facilitate custom error processing on the client.
     *
     * @var object
     */
    public $extendedData = null;


    /**
     * The fault code for the error.
     *
     * @var string
     */
    public $faultCode = '';


    /**
     * Detailed description of what caused the error.
     *
     * @var string
     */
    public $faultDetail = '';


    /**
     * A simple description of the error.
     *
     * @var string
     */
    public $faultString = '';


    /**
     * Should a root cause exist for the error, this property contains those details.
     *
     * @var object
     */
    public $rootCause = null;

}


