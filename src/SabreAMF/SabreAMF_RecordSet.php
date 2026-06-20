<?php

namespace Xiaosongshu\Flv2mp4\SabreAMF;


/**
 * @purpose amf 记录设置 SabreAMF_RecordSet
 * @author yanglong
 */
abstract class SabreAMF_RecordSet implements SabreAMF_ITypedObject, \Countable
{


    /**
     * getData
     *
     * @return array
     */
    abstract public function getData();

    /**
     * getColumnNames
     *
     * @return array
     */
    abstract public function getColumnNames();

    /**
     * getAMFClassName
     *
     * @return string
     */
    final public function getAMFClassName()
    {

        return 'RecordSet';

    }

    /**
     * getAMFData
     *
     * @return object
     */
    public function getAMFData()
    {

        return (object)array(
            'serverInfo' => (object)array(
                'totalCount' => $this->count(),
                'initialData' => $this->getData(),
                'cursor' => 1,
                'serviceName' => false,
                'columnNames' => $this->getColumnNames(),
                'version' => 1,
                'id' => false,
            )
        );


    }

}




