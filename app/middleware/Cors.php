<?php

namespace app\middleware;

use think\Request;
use think\Response;

class Cors
{
    protected $allowedOrigins = [];
    protected $allowedMethods = ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS', 'PATCH'];
    protected $allowedHeaders = ['Content-Type', 'Authorization', 'X-Requested-With', 'X-CSRF-TOKEN', 'Accept'];
    protected $exposedHeaders = [];
    protected $maxAge = 86400;
    protected $allowCredentials = true;
    
    public function handle(Request $request, \Closure $next)
    {
        $origin = $request->header('Origin');
        
        if ($this->isOriginAllowed($origin)) {
            header('Access-Control-Allow-Origin: ' . $origin);
        } else {
            $allowedOrigin = $this->getAllowedOrigin();
            if ($allowedOrigin) {
                header('Access-Control-Allow-Origin: ' . $allowedOrigin);
            }
        }
        
        header('Access-Control-Allow-Methods: ' . implode(', ', $this->allowedMethods));
        header('Access-Control-Allow-Headers: ' . implode(', ', $this->allowedHeaders));
        
        if (!empty($this->exposedHeaders)) {
            header('Access-Control-Expose-Headers: ' . implode(', ', $this->exposedHeaders));
        }
        
        if ($this->allowCredentials) {
            header('Access-Control-Allow-Credentials: true');
        }
        
        header('Access-Control-Max-Age: ' . $this->maxAge);
        
        if ($request->method() === 'OPTIONS') {
            return response('', 200);
        }
        
        $response = $next($request);
        
        if ($response instanceof Response) {
            if ($this->isOriginAllowed($origin)) {
                $response->header('Access-Control-Allow-Origin', $origin);
            }
        }
        
        return $response;
    }
    
    protected function isOriginAllowed($origin)
    {
        if (empty($origin)) {
            return false;
        }
        
        if (empty($this->allowedOrigins)) {
            $allowedOrigins = env('CORS_ALLOWED_ORIGINS', '');
            if (empty($allowedOrigins)) {
                return false;
            }
            
            $this->allowedOrigins = array_map('trim', explode(',', $allowedOrigins));
        }
        
        if (in_array('*', $this->allowedOrigins)) {
            return true;
        }
        
        return in_array($origin, $this->allowedOrigins);
    }
    
    protected function getAllowedOrigin()
    {
        if (empty($this->allowedOrigins)) {
            $allowedOrigins = env('CORS_ALLOWED_ORIGINS', '');
            if (empty($allowedOrigins)) {
                return null;
            }
            
            $this->allowedOrigins = array_map('trim', explode(',', $allowedOrigins));
        }
        
        if (in_array('*', $this->allowedOrigins)) {
            return '*';
        }
        
        return !empty($this->allowedOrigins) ? $this->allowedOrigins[0] : null;
    }
    
    public function setAllowedOrigins(array $origins)
    {
        $this->allowedOrigins = $origins;
        return $this;
    }
    
    public function setAllowedMethods(array $methods)
    {
        $this->allowedMethods = $methods;
        return $this;
    }
    
    public function setAllowedHeaders(array $headers)
    {
        $this->allowedHeaders = $headers;
        return $this;
    }
    
    public function setExposedHeaders(array $headers)
    {
        $this->exposedHeaders = $headers;
        return $this;
    }
    
    public function setMaxAge($seconds)
    {
        $this->maxAge = $seconds;
        return $this;
    }
    
    public function setAllowCredentials($allow)
    {
        $this->allowCredentials = $allow;
        return $this;
    }
}
