<?php

namespace app\middleware;

use think\Request;
use think\Response;

class Csrf
{
    protected $tokenName = '_token';
    protected $headerName = 'X-CSRF-TOKEN';
    protected $excludeMethods = ['GET', 'HEAD', 'OPTIONS'];
    
    public function handle(Request $request, \Closure $next)
    {
        if (in_array($request->method(), $this->excludeMethods)) {
            return $next($request);
        }
        
        if ($this->shouldExclude($request)) {
            return $next($request);
        }
        
        $token = $this->getTokenFromRequest($request);
        
        if (!$this->validateToken($token)) {
            if ($request->isAjax() || $request->header('accept') === 'application/json') {
                return json([
                    'code' => 403,
                    'msg' => 'CSRF token验证失败，请刷新页面重试'
                ], 403);
            }
            
            return redirect('/')->with('error', 'CSRF token验证失败，请刷新页面重试');
        }
        
        return $next($request);
    }
    
    protected function shouldExclude(Request $request)
    {
        $excludeRoutes = [
            'api/upload',
            'api/user/login',
            'api/user/register',
            'api/user/captcha',
            'api/user/sendSms',
            'api/websocket',
        ];
        
        $path = $request->pathinfo();
        
        foreach ($excludeRoutes as $route) {
            if (strpos($path, $route) === 0) {
                return true;
            }
        }
        
        return false;
    }
    
    protected function getTokenFromRequest(Request $request)
    {
        $token = $request->post($this->tokenName);
        
        if (empty($token)) {
            $token = $request->header($this->headerName);
        }
        
        if (empty($token)) {
            $token = $request->param($this->tokenName);
        }
        
        return $token;
    }
    
    protected function validateToken($token)
    {
        if (empty($token)) {
            return false;
        }
        
        $sessionToken = session($this->tokenName);
        
        if (empty($sessionToken)) {
            return false;
        }
        
        return hash_equals($sessionToken, $token);
    }
    
    public static function generateToken()
    {
        $token = bin2hex(random_bytes(32));
        session('_token', $token);
        return $token;
    }
    
    public static function getTokenField()
    {
        $token = self::generateToken();
        return '<input type="hidden" name="_token" value="' . $token . '">';
    }
    
    public static function getMetaTag()
    {
        $token = self::generateToken();
        return '<meta name="csrf-token" content="' . $token . '">';
    }
}
