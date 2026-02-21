<?php

namespace app\middleware;

use Closure;
use think\Request;
use think\Response;

class Cors
{
    protected $allowedOrigins = [
        'http://localhost',
        'http://localhost:8080',
        'http://127.0.0.1',
    ];

    protected $allowedMethods = ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS', 'PATCH'];
    
    protected $allowedHeaders = ['Content-Type', 'Authorization', 'X-Requested-With'];

    public function handle(Request $request, Closure $next)
    {
        $origin = $request->header('origin');
        
        if ($this->isOriginAllowed($origin)) {
            header('Access-Control-Allow-Origin: ' . $origin);
        } else {
            header('Access-Control-Allow-Origin: ' . $this->allowedOrigins[0]);
        }
        
        header('Access-Control-Allow-Methods: ' . implode(', ', $this->allowedMethods));
        header('Access-Control-Allow-Headers: ' . implode(', ', $this->allowedHeaders));
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Max-Age: 86400');
        
        if ($request->method() === 'OPTIONS') {
            return Response::create('', 200);
        }
        
        return $next($request);
    }

    protected function isOriginAllowed($origin)
    {
        if (empty($origin)) {
            return true;
        }
        
        foreach ($this->allowedOrigins as $allowedOrigin) {
            if (strpos($origin, $allowedOrigin) === 0) {
                return true;
            }
        }
        
        return false;
    }
}
