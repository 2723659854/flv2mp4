<?php
namespace Xiaosongshu\Flv2mp4\SabreAMF;
    /**
     * @purpose AMF序列化工具
     * @author yanglong
     * SabreAMF_Serializer
     * Abstract Serializer
     *
     * This is the abstract serializer class. This is used by the AMF0 and AMF3 serializers as a base class
     */
    abstract class SabreAMF_Serializer {

        /**
         * 输出流
         * stream
         *
         * @var SabreAMF_OutputStream
         */
        protected $stream;

        /**
         * 初始化
         * __construct
         *
         * @param SabreAMF_OutputStream $stream
         * @return void
         */
        public function __construct(SabreAMF_OutputStream $stream) {

            $this->stream = $stream;

        }

        /**
         * writeAMFData
         *
         * @param mixed $data
         * @param int $forcetype
         * @return mixed
         */
        public abstract function writeAMFData($data,$forcetype=null);

        /**
         * getStream
         *
         * @return SabreAMF_OutputStream
         */
        public function getStream() {

            return $this->stream;

        }

        /**
         * getRemoteClassName
         *
         * @param string $localClass
         * @return mixed
         */
        protected function getRemoteClassName($localClass) {

            return SabreAMF_ClassMapper::getRemoteClass($localClass);

        }

    }


