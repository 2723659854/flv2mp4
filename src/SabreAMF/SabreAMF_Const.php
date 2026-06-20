<?php

namespace Xiaosongshu\Flv2mp4\SabreAMF;
/**
 * @purpose afm的全局常量 SabreAMF_Const
 * @author yanglong
 */
final class SabreAMF_Const
{

    /**
     * amf 客户端播放器版本
     * AC_Flash
     *
     * Specifies FlashPlayer 6.0 - 8.0 client
     */
    const AC_Flash = 0;

    /**
     * amf 客户端播放器版本
     * AC_FlashCom
     *
     * Specifies FlashCom / Flash Media Server client
     */
    const AC_FlashCom = 1;

    /**
     * amf 客户端播放器版本
     * AC_Flex
     *
     * Specifies a FlashPlayer 9.0 client
     */
    const AC_Flash9 = 3;

    /**
     * 普通的返回数据
     * R_RESULT
     *
     * Normal result to a methodcall
     */
    const R_RESULT = 1;

    /**
     * 错误的返回数据
     * R_STATUS
     *
     * Faulty result
     */
    const R_STATUS = 2;

    /**
     * 调试数据
     * R_DEBUG
     *
     * Result to a debug-header
     */
    const R_DEBUG = 3;

    /**
     * amf0编码
     * AMF0 Encoding
     */
    const AMF0 = 0;

    /**
     * amf3编码
     * AMF3 Encoding
     */
    const AMF3 = 3;

    /**
     * amf3 + flex 消息编码
     * AMF3 Encoding + flex messaging wrappers
     */
    const FLEXMSG = 16;

    /**
     * amf http 消息类型
     * AMF HTTP Mimetype
     */
    const MIMETYPE = 'application/x-amf';

}



