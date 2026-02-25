<?php
namespace app\middleware;

use think\facade\Session;
use think\facade\Cookie;
use think\Response;

class Auth
{
    public function handle($request, \Closure $next)
    {
        // 对于登录请求，直接放行
        if ($request->pathinfo() === 'user/login' || $request->pathinfo() === 'login') {
            return $next($request);
        }

        // 如果用户在cookie中但不在session中，先重建session
        if (!Session::has('user_id') && Cookie::has('user_id')) {
            Session::set('user_id', Cookie::get('user_id'));
            if (Cookie::has('username')) {
                Session::set('username', Cookie::get('username'));
            }
            if (Cookie::has('nickname')) {
                Session::set('nickname', Cookie::get('nickname'));
            }
            if (Cookie::has('avatar')) {
                Session::set('avatar', Cookie::get('avatar'));
            }
            if (Cookie::has('mobile')) {
                Session::set('mobile', Cookie::get('mobile'));
            }
        }

        // 检查session中是否有用户信息
        $userId = Session::get('user_id') ?: Cookie::get('user_id');
        
        if (empty($userId)) {
            // 如果是API请求，返回JSON响应
            $pathinfo = $request->pathinfo();
            $acceptHeader = $request->header('accept');

            // 判断是否是API请求：
            // 1. 检查 Accept 头是否包含 application/json
            // 2. 检查路径是否以 api/、user/、settings/、wallet/ 开头
            // 3. 检查是否是传统的 AJAX 请求
            $isApiRequest = $request->isAjax()
                || (is_string($acceptHeader) && strpos($acceptHeader, 'application/json') !== false)
                || strpos($pathinfo, 'api/') === 0
                || strpos($pathinfo, 'user/') === 0
                || strpos($pathinfo, 'settings/') === 0
                || strpos($pathinfo, 'wallet/') === 0;

            if ($isApiRequest) {
                // 清除之前的所有输出
                if (ob_get_level()) {
                    ob_end_clean();
                }
                header('Content-Type: application/json; charset=utf-8');
                header('Cache-Control: no-cache, must-revalidate');
                echo json_encode([
                    'code' => 401,
                    'msg'  => '请先登录',
                    'data' => null
                ]);
                exit;
            }

            // 普通页面请求，跳转到登录页
            return redirect('/login');
        }

        return $next($request);
    }
}
