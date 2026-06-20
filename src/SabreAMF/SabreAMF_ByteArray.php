<?php

namespace Xiaosongshu\Flv2mp4\SabreAMF;
/**
 * @purpose amf 二进制数据 SabreAMF_ByteArray
 * @author yanglong
 */
class SabreAMF_ByteArray
{

    /**
     * data
     *
     * @var string
     */
    private $data;

    /**
     * __construct
     *
     * @param string $data
     * @return void
     */
    function __construct($data = '')
    {
        ;

        $this->data = $data;

    }

    /**
     * getData
     *
     * @return string
     */
    function getData()
    {

        return $this->data;

    }

    /**
     * setData
     *
     * @param string $data
     * @return void
     */
    function setData($data)
    {

        $this->data = $data;

    }

}


