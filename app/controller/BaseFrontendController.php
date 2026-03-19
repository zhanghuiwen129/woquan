<?php
declare (strict_types = 1);

namespace app\controller;

use app\BaseController;
use think\facade\Session;
use think\facade\Cookie;
use think\facade\Request;
use think\facade\View;
use think\facade\Response;
use think\facade\Url;

class BaseFrontendController extends BaseController
{
    protected function initialize()
    {
        parent::initialize();

        $this->checkUserLogin();

        $this->setGlobalTemplateVars();
    }
    protected function checkUserLogin()
    {
        // 优先使用 Session，如果 Session 为空则尝试从 Cookie 恢复（与 Auth 中间件逻辑一致）
        $userId = Session::get('user_id') ?: Cookie::get('user_id');
        $isLoggedIn = !empty($userId);

        // 如果 Session 为空但 Cookie 中有用户信息，补充 Session 数据
        if (!$isLoggedIn && !empty($userId)) {
            Session::set('user_id', $userId);
            Session::set('username', Cookie::get('username', ''));
            Session::set('nickname', Cookie::get('nickname', ''));
            Session::set('avatar', Cookie::get('avatar', ''));
            $isLoggedIn = true;
        }

        View::assign([
            'isLoggedIn' => $isLoggedIn,
            'isLogin' => $isLoggedIn
        ]);

        if ($isLoggedIn) {
            View::assign([
                'userInfo' => [
                    'id' => Session::get('user_id'),
                    'username' => Session::get('username'),
                    'avatar' => Session::get('avatar', '/static/images/default-avatar.png')
                ],
                'currentUser' => [
                    'avatar' => Session::get('avatar', '/static/images/default-avatar.png'),
                    'nickname' => Session::get('nickname', Session::get('username')),
                    'username' => Session::get('username')
                ]
            ]);
        }
    }

    protected function setGlobalTemplateVars()
    {
        $pathinfo = Request::pathinfo();
        $currentUrl = '/' . $pathinfo;

        View::assign([
            'current_url' => $currentUrl,
            'current_path' => $pathinfo
        ]);

        $config = [];
        try {
            $siteConfig = \app\model\SystemConfig::getAllConfigs();
            foreach ($siteConfig as $item) {
                if (is_object($item)) {
                    $config[$item->config_key] = $item->config_value;
                } elseif (is_array($item)) {
                    $config[$item['config_key']] = $item['config_value'];
                }
            }
        } catch (\Exception $e) {
            error_log('获取网站配置失败: ' . $e->getMessage());
            $config = [];
        }

        $siteName = $config['site_name'] ?? '我圈社交平台';
        $siteSubtitle = $config['site_subtitle'] ?? '连接你我，分享精彩';
        $logo = $config['site_logo'] ?? '';
        $icon = $config['site_favicon'] ?? '';

        View::assign([
            'siteInfo' => [
                'name' => $siteName,
                'subtitle' => $siteSubtitle,
                'logo' => $logo,
                'icon' => $icon,
                'sign' => $config['site_sign'] ?? '',
                'copyright' => $config['site_copyright'] ?? '© 2025 我圈社交平台 版权所有',
                'beian' => $config['site_icp'] ?? ''
            ],
            'config' => $config,
            'name' => $siteName,
            'subtitle' => $siteSubtitle,
            'logo' => $logo,
            'icon' => $icon
        ]);
    }

    protected function redirect($url, $code = 302, $with = [])
    {
        return Response::create($url, 'redirect', $code)->with($with);
    }

    /**
     * 统一JSON响应方法
     * @param int $code 状态码
     * @param string $msg 消息
     * @param mixed $data 数据
     * @return \think\Response
     */
    protected function jsonResponse($code = 200, $msg = 'success', $data = null)
    {
        $responseData = [
            'code' => $code,
            'msg' => $msg
        ];

        if ($data !== null) {
            $responseData['data'] = $data;
        }

        return json($responseData)->header([
            'Content-Type' => 'application/json; charset=utf-8',
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type'
        ]);
    }

    /**
     * 成功响应
     * @param mixed $data 数据
     * @param string $msg 消息
     * @return \think\Response
     */
    protected function success($data = null, $msg = 'success')
    {
        return $this->jsonResponse(200, $msg, $data);
    }

    /**
     * 错误响应
     * @param string $msg 错误消息
     * @param int $code 错误码
     * @return \think\Response
     */
    protected function error($msg = 'error', $code = 500)
    {
        return $this->jsonResponse($code, $msg);
    }

    /**
     * 未登录响应
     * @param string $msg 消息
     * @return \think\Response
     */
    protected function unauthorized($msg = '未登录')
    {
        return $this->jsonResponse(401, $msg);
    }

    /**
     * 参数错误响应
     * @param string $msg 消息
     * @return \think\Response
     */
    protected function badRequest($msg = '参数错误')
    {
        return $this->jsonResponse(400, $msg);
    }

    /**
     * 未找到响应
     * @param string $msg 消息
     * @return \think\Response
     */
    protected function notFound($msg = '资源不存在')
    {
        return $this->jsonResponse(404, $msg);
    }
}
