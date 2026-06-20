<?php
namespace Xiaosongshu\Flv2mp4\SabreAMF;

/**
 * @purpose In valid AMF exception
 * @author yanglong
 */
class SabreAMF_InvalidAMFException extends \Exception implements SabreAMF_DetailException
{

    /**
     *    Constructor
     */
    public function __construct()
    {
        // Specific message to ClassException
        $this->message = "No valid AMF request received";
        $this->code = "Server.Processing";

        // Call parent class constructor
        parent::__construct($this->message);
    }

    public function getDetail()
    {

        return "Please check that you are calling this page with Flash and AMF.";

    }

}

?>
