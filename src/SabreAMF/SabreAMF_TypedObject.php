<?php

namespace Xiaosongshu\Flv2mp4\SabreAMF;


/**
 * @purpose SabreAMF_TypedObject
 * @author yanglong
 * @comment php和rtmp的flash交互类
 */
class SabreAMF_TypedObject implements SabreAMF_ITypedObject
{

    private $amfClassName;
    private $amfData;

    /** 初始化 类名 数据 */
    public function __construct($classname, $data)
    {

        $this->setAMFClassName($classname);
        $this->setAMFData($data);

    }

    /**
     * 获取amf类名
     * getAMFClassName
     *
     * @return string
     */
    public function getAMFClassName()
    {

        return $this->amfClassName;

    }

    /**
     * 获取amf数据
     * getAMFData
     *
     * @return mixed
     */
    public function getAMFData()
    {

        return $this->amfData;

    }

    /**
     * 设置amf类名
     * setAMFClassName
     *
     * @param string $classname
     * @return void
     */
    public function setAMFClassName($classname)
    {

        $this->amfClassName = $classname;

    }

    /**
     * 设置amf数据
     * setAMFData
     *
     * @param mixed $data
     * @return void
     */
    public function setAMFData($data)
    {

        $this->amfData = $data;

    }

}


