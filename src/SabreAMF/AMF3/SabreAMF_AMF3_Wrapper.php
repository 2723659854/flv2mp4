<?php

namespace Xiaosongshu\Flv2mp4\SabreAMF\AMF3;
/**
 * amf3数据打包
 * @purpose SabreAMF_AMF3_Wrapper
 * @author yanglong
 */
class SabreAMF_AMF3_Wrapper
{


    /**
     * data
     *
     * @var mixed
     */
    private $data;


    /**
     * __construct
     *
     * @param mixed $data
     * @return void
     */
    public function __construct($data)
    {

        $this->setData($data);

    }


    /**
     * getData
     *
     * @return mixed
     */
    public function getData()
    {

        return $this->data;

    }

    /**
     * setData
     *
     * @param mixed $data
     * @return void
     */
    public function setData($data)
    {

        $this->data = $data;

    }


}


