<?php
/**
 * Hublog 登录认证中间件
 */

namespace Hublog\Middleware;

use Hublog\Core\Request;
use Hublog\Core\Auth;
use Hublog\Core\Exception\HublogException;

class AuthMiddleware
{
    public function handle(Request $request): void
    {
        Auth::check($request);
    }
}
