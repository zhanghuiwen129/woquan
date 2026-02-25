<?php

namespace app\middleware;

use Closure;
use think\Request;
use think\Response;

class Csrf
{
    protected $except = [
        'api/user/login',
        'api/user/register',
        'api/upload',
        'api/upload/avatar',
    ];

    public function handle(Request $request, Closure $next)
    {
        $path = $request->pathinfo();
        
        if ($this->shouldExcept($path)) {
            return $next($request);
        }
        
        if ($request->isPost() || $request->isPut() || $request->isDelete()) {
            $token = $request->param('__token__');
            
            if (!$token || !check_csrf_token($token)) {
                return Response::create([
                    'code' => 403,
                    'msg' => 'CSRF token验证失败，请刷新页面重试'
                ], 'json', 403);
            }
        }
        
        return $next($request);
    }

    protected function shouldExcept($path)
    {
        foreach ($this->except as $except) {
            if (strpos($path, $except) !== false) {
                return true;
            }
        }
        
        return false;
    }
}

if (!function_exists('check_csrf_token')) {
    function check_csrf_token($token)
    {
        return $token === session('__token__');
    }
}
