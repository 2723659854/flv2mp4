<?php

namespace Xiaosongshu\Flv2mp4\SabreAMF;


/**
 * @purpose amf 数据解码
 * @author yanglong
 * SabreAMF_Deserializer
 *
 * This is the abstract Deserializer. The AMF0 and AMF3 classes descent from this class
 */
abstract class SabreAMF_Deserializer
{

    /**
     * stream
     *
     * @var SabreAMF_InputStream
     */
    protected $stream;

    /**
     * __construct
     *
     * @param SabreAMF_InputStream $stream
     * @return void
     */
    public function __construct(SabreAMF_InputStream $stream)
    {

        $this->stream = $stream;

    }

    /**
     * readAMFData
     *
     * Starts reading an AMF block from the stream
     *
     * @param mixed $settype
     * @return mixed
     */
    public abstract function readAMFData($settype = null);


    /**
     * getLocalClassName
     *
     * @param string $remoteClass
     * @return mixed
     */
    protected function getLocalClassName($remoteClass)
    {

        return SabreAMF_ClassMapper::getLocalClass($remoteClass);

    }

}


