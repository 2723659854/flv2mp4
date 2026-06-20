<?php
namespace Xiaosongshu\Flv2mp4\SabreAMF;

/**
 * @purpose SabreAMF_ClassNotFoundException
 * @comment 这个是对象没有找到，用于处理rpc调用的 This is the receipt for ClassException and default values reflective of ColdFusion RPC faults
 * @author yanglong
 */
class SabreAMF_ClassNotFoundException extends \Exception implements SabreAMF_DetailException
{

    /**
     * 初始化
     * @param $classname
     */
    public function __construct($classname)
    {
        // Specific message to ClassException
        $this->message = "Could not locate class " . $classname;
        $this->code = "Server.Processing";

        // Call parent class constructor
        parent::__construct($this->message);
    }

    public function getDetail()
    {

        return "Please check that the given servicename is correct and that the class exists.";

    }

}

?>
