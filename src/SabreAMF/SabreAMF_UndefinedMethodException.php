<?php
namespace Xiaosongshu\Flv2mp4\SabreAMF;
/**
 * @purpose SabreAMF_UndefinedMethodException
 * @author yanglong
 * AMF 是一种用于在 Flash 应用程序之间传输数据的二进制格式。SabreAMF 就是在 RTMP 协议中实现了对 AMF 格式的编码和解码，
 * 使得在 Flash 应用程序中可以方便地传输各种类型的数据，比如命令、消息、状态等等。
 * SabreAMF_UndefinedMethodException
 * 处理远程调用rpc故障
 * 这是反映ColdFusion RPC故障的UndefinedMethodException和默认值的收据
 */
class SabreAMF_UndefinedMethodException extends \Exception implements SabreAMF_DetailException
{

    /**
     *    Constructor
     */
    public function __construct($class, $method)
    {
        // Specific message to MethodException
        $this->message = "Undefined method '$method' in class $class";
        $this->code = "Server.Processing";

        // Call parent class constructor
        parent::__construct($this->message);

    }

    public function getDetail()
    {

        return "Check to ensure that the method is defined, and that it is spelled correctly.";

    }


}

?>
