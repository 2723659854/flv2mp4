<?php

namespace Xiaosongshu\Flv2mp4\SabreAMF;
/**
 * @purpose SabreAMF_Externalized接口
 * @author yanglong
 * Implement this interface to construct your own Externalized objects..
 * Don't forget to map the object using the classmapper
 */
interface SabreAMF_Externalized
{

    /**
     * This method is called when the object is serialized
     *
     * @return mixed
     */
    function writeExternal();

    /**
     * This method is called when the object is unserialized
     *
     * @param mixed $data
     * @return void
     */
    function readExternal($data);

}


