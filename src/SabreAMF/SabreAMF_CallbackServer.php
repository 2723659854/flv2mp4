<?php

namespace Xiaosongshu\Flv2mp4\SabreAMF;

use Xiaosongshu\Flv2mp4\SabreAMF\AMF3\SabreAMF_AMF3_AbstractMessage;
use Xiaosongshu\Flv2mp4\SabreAMF\AMF3\SabreAMF_AMF3_AcknowledgeMessage;
use Xiaosongshu\Flv2mp4\SabreAMF\AMF3\SabreAMF_AMF3_CommandMessage;
use Xiaosongshu\Flv2mp4\SabreAMF\AMF3\SabreAMF_AMF3_ErrorMessage;
use Xiaosongshu\Flv2mp4\SabreAMF\AMF3\SabreAMF_AMF3_RemotingMessage;

/**
 * @purpose amf服务
 * @author yanglong
 * AMF Server
 * amf服务端的网关，为client提供服务
 * This is the AMF0/AMF3 Server class. Use this class to construct a gateway for clients to connect to
 * The difference between this MediaServer class and the regular MediaServer, is that this MediaServer is aware of the
 * AMF3 Messaging system, and there is no need to manually construct the AcknowledgeMessage classes.
 * Also, the response to the ping message will be done for you.
 */
class SabreAMF_CallbackServer extends SabreAMF_Server
{

    /**
     * 定义回调函数别名  用来处理方法调用
     * Assign this callback to handle method-calls
     *
     * @var callback
     */
    public $onInvokeService;

    /**
     * 定义鉴权请求别名
     * Assign this callback to handle authentication requests
     *
     * @var callback
     */
    public $onAuthenticate;

    /**
     * 处理消息命令
     * handleCommandMessage
     *
     * @param SabreAMF_AMF3_CommandMessage $request
     * @return SabreAMF_AMF3_AbstractMessage
     */
    private function handleCommandMessage(SabreAMF_AMF3_CommandMessage $request)
    {

        switch ($request->operation) {

            /** 返回心跳 */
            case SabreAMF_AMF3_CommandMessage::CLIENT_PING_OPERATION :
                $response = new SabreAMF_AMF3_AcknowledgeMessage($request);
                break;
            /** 登录 */
            case SabreAMF_AMF3_CommandMessage::LOGIN_OPERATION :
                $authData = base64_decode($request->body);
                if ($authData) {
                    $authData = explode(':', $authData, 2);
                    if (count($authData) == 2) {
                        /** 鉴权 */
                        $this->authenticate($authData[0], $authData[1]);
                    }
                }
                $response = new SabreAMF_AMF3_AcknowledgeMessage($request);
                $response->body = true;
                break;
            /** 退出登录 */
            case SabreAMF_AMF3_CommandMessage::DISCONNECT_OPERATION :
                $response = new SabreAMF_AMF3_AcknowledgeMessage($request);
                break;
            default :
                throw new \Exception('Unsupported CommandMessage operation: ' . $request->operation);

        }
        return $response;

    }

    /**
     * 鉴权
     * authenticate
     *
     * @param string $username
     * @param string $password
     * @return void
     */
    protected function authenticate($username, $password)
    {

        if (is_callable($this->onAuthenticate)) {
            call_user_func($this->onAuthenticate, $username, $password);
        }

    }

    /**
     * 初始化函数
     * invokeService
     *
     * @param string $service
     * @param string $method
     * @param array $data
     * @return mixed
     */
    protected function invokeService($service, $method, $data)
    {

        if (is_callable($this->onInvokeService)) {
            return call_user_func_array($this->onInvokeService, array($service, $method, $data));
        } else {
            throw new \Exception('onInvokeService is not defined or not callable');
        }

    }


    /**
     * 默认执行方法
     * exec
     *
     * @return void
     */
    public function exec()
    {

        // First we'll be looping through the headers to see if there's anything we reconize
        /** 首先检查是否需要鉴权 */
        foreach ($this->getRequestHeaders() as $header) {

            switch ($header['name']) {

                // We found a credentials headers, calling the authenticate method
                case 'Credentials' :
                    $this->authenticate($header['data']['userid'], $header['data']['password']);
                    break;

            }

        }
        /** 处理每一个请求 */
        foreach ($this->getRequests() as $request) {

            // Default AMFVersion
            $AMFVersion = 0;

            $response = null;

            try {

                if (is_array($request['data']) && isset($request['data'][0]) && $request['data'][0] instanceof SabreAMF_AMF3_AbstractMessage) {
                    $request['data'] = $request['data'][0];
                }

                /** 是amf3格式的数据 */
                // See if we are dealing with the AMF3 messaging system
                if (is_object($request['data']) && $request['data'] instanceof SabreAMF_AMF3_AbstractMessage) {

                    $AMFVersion = 3;
                    /** 是发送的命令，处理这些命令 */
                    // See if we are dealing with a CommandMessage
                    if ($request['data'] instanceof SabreAMF_AMF3_CommandMessage) {

                        // Handle the command message
                        $response = $this->handleCommandMessage($request['data']);
                    }
                    /** 远程调用服务 */
                    // Is this maybe a RemotingMessage ?
                    if ($request['data'] instanceof SabreAMF_AMF3_RemotingMessage) {

                        // Yes
                        $response = new SabreAMF_AMF3_AcknowledgeMessage($request['data']);
                        $response->body = $this->invokeService($request['data']->source, $request['data']->operation, $request['data']->body);

                    }

                } else {
                    /** 处理amf0格式数据 */
                    // We are dealing with AMF0
                    $service = substr($request['target'], 0, strrpos($request['target'], '.'));
                    $method = substr(strrchr($request['target'], '.'), 1);
                    /** 远程调用服务 */
                    $response = $this->invokeService($service, $method, $request['data']);

                }
                /** 返回状态为正常 */
                $status = SabreAMF_Const::R_RESULT;

            } catch (\Exception $e) {

                // We got an exception somewhere, ignore anything that has happened and send back
                // exception information
                /** 有异常，忽略异常 */
                if ($e instanceof SabreAMF_DetailException) {
                    $detail = $e->getDetail();
                } else {
                    $detail = '';
                }

                switch ($AMFVersion) {
                    case SabreAMF_Const::AMF0 :
                        $response = array(
                            'description' => $e->getMessage(),
                            'detail' => $detail,
                            'line' => $e->getLine(),
                            'code' => $e->getCode() ? $e->getCode() : get_class($e),
                        );
                        break;
                    case SabreAMF_Const::AMF3 :
                        $response = new SabreAMF_AMF3_ErrorMessage($request['data']);
                        $response->faultString = $e->getMessage();
                        $response->faultCode = $e->getCode();
                        $response->faultDetail = $detail;
                        break;

                }
                /** 返回状态为错误 */
                $status = SabreAMF_Const::R_STATUS;
            }
            /** 返回异常 */
            $this->setResponse($request['response'], $status, $response);

        }
        /** 返回处理结果 */
        $this->sendResponse();

    }

}


