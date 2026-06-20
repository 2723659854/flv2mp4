<?php
namespace Xiaosongshu\Flv2mp4\SabreAMF;
    /**
     * @purpose SabreAMF_ITypedObject
     * @author yanglong
     * @comment This interface can be used to encode your data with a specified classname. The result will be that the flash/flex client will transform the data to an object of the specified classname
     *
     */
    interface SabreAMF_ITypedObject {

        /**
         * getAMFClassName 
         *
         * This method should return the classname as it should show up for the client
         * 
         * @return string 
         */
        public function getAMFClassName();

        /**
         * getAMFData 
         *
         * This method should return the actual contents of the object that should be encoded
         * 
         * @return mixed 
         */
        public function getAMFData();

    }


