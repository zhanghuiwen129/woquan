<?php

namespace app\controller\admin;

use app\BaseController;
use think\facade\Response;
use think\facade\Url;

class AdminController extends BaseController
{
    // 应用AdminAuth中间件到所有后台控制器
    protected $middleware = [
        'AdminAuth' => ['except' => ['login/index', 'login/login', 'login/logout', 'login/captcha']]
    ];
    
    /**
     * 错误提示
     * @param string $msg 错误消息
     * @param string $url 跳转地址
     * @param array $data 数据
     * @param int $wait 跳转等待时间
     * @param array $header 响应头
     * @return \think\response
     */
    protected function error($msg = '', $url = '', $data = [], $wait = 3, $header = [])
    {
        if (is_null($url)) {
            $url = $this->request->server('HTTP_REFERER') ?: 'javascript:history.back(-1);';
        } elseif ('' !== $url) {
            $url = (strpos($url, '://') || 0 === strpos($url, '/')) ? $url : Url::build($url);
        }
        
        $result = [
            'code' => 0,
            'msg'  => $msg,
            'data' => $data,
            'url'  => $url,
            'wait' => $wait,
        ];
        
        return Response::create($result, 'json', 200, $header);
    }
}
