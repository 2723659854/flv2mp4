<?php

namespace Xiaosongshu\Flv2mp4\SabreAMF;
/**
 * @purpose amf输出流 将数据转码成二进制
 * @author yanglong
 * SabreAMF_OutputStream
 * This class provides methods to encode bytes, longs, strings, int's etc. to a binary format
 */
class SabreAMF_OutputStream
{

    /**
     * rawData
     *
     * @var string
     */
    private $rawData = '';

    /**
     * writeBuffer
     *
     * @param string $str
     * @return void
     */
    public function writeBuffer($str)
    {
        $this->rawData .= $str;
    }

    /**
     * writeByte
     *
     * @param int $byte
     * @return void
     */
    public function writeByte($byte)
    {

        $this->rawData .= pack('c', $byte);

    }

    /**
     * writeInt
     *
     * @param int $int
     * @return void
     */
    public function writeInt($int)
    {

        $this->rawData .= pack('n', $int);

    }

    /**
     * writeDouble
     *
     * @param float $double
     * @return void
     */
    public function writeDouble($double)
    {

        $bin = pack("d", $double);
        $testEndian = unpack("C*", pack("S*", 256));
        $bigEndian = !$testEndian[1] == 1;
        if ($bigEndian) $bin = strrev($bin);
        $this->rawData .= $bin;

    }

    /**
     * writeLong
     *
     * @param int $long
     * @return void
     */
    public function writeLong($long)
    {

        $this->rawData .= pack("N", $long);


    }

    /**
     * getRawData
     *
     * @return string
     */
    public function getRawData()
    {

        return $this->rawData;

    }


}



