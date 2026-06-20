<?php

namespace Xiaosongshu\Flv2mp4\SabreAMF;

/**
 * @purpose SabreAMF异常类
 * @author yanglong
 * This interface can provide detailed information about an exception
 * Implement this interface to provide faultDetail in flex2 and detail in Flash Remoting
 */
interface SabreAMF_DetailException
{

    /**
     * Returns detailed information about the exception
     *
     * @return void
     */
    function getDetail();

}


