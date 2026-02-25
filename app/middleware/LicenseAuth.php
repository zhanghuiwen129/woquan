<?php

namespace app\middleware;

use app\service\LicenseService;
use think\Request;
use think\Response;

class LicenseAuth
{
    protected $config = [
        'except' => [
            'admin/login/index',
            'admin/login/login',
            'admin/login/logout',
            'admin/login/captcha',
        ]
    ];

    public function handle(Request $request, \Closure $next)
    {
        $path = $request->pathinfo();

        foreach ($this->config['except'] as $except) {
            if (strpos($path, $except) !== false) {
                return $next($request);
            }
        }

        $licenseCode = env('license.key', '');

        if (empty($licenseCode)) {
            if ($request->isAjax()) {
                return Response::create(['code' => 401, 'msg' => '未授权，请在配置文件中设置授权码'], 'json', 401);
            }
            return Response::create('<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>未授权</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
        }
        .container {
            background: white;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            max-width: 500px;
            text-align: center;
        }
        h1 {
            color: #333;
            margin-bottom: 20px;
            font-size: 24px;
        }
        p {
            color: #666;
            margin-bottom: 30px;
            line-height: 1.6;
        }
        .code {
            background: #f5f5f5;
            padding: 15px;
            border-radius: 6px;
            font-family: monospace;
            font-size: 14px;
            color: #333;
            margin-bottom: 20px;
            word-break: break-all;
        }
        .btn {
            display: inline-block;
            padding: 12px 30px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.3s;
        }
        .btn:hover {
            opacity: 0.9;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>未授权</h1>
        <p>系统未授权，请联系客服获取授权码</p>
        <p>请在 .env 文件中配置以下参数：</p>
        <div class="code">LICENSE_KEY=your-license-key-here</div>
        <a href="/" class="btn">返回首页</a>
    </div>
</body>
</html>', 'html', 401);
        }

        $result = LicenseService::verify($licenseCode);

        if (!$result['valid']) {
            if ($request->isAjax()) {
                return Response::create(['code' => 403, 'msg' => $result['msg']], 'json', 403);
            }
            return Response::create('<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>授权无效</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
        }
        .container {
            background: white;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            max-width: 500px;
            text-align: center;
        }
        h1 {
            color: #333;
            margin-bottom: 20px;
            font-size: 24px;
        }
        p {
            color: #666;
            margin-bottom: 15px;
            line-height: 1.6;
        }
        .error {
            color: #f5576c;
            font-weight: 600;
            font-size: 18px;
            margin-bottom: 20px;
        }
        .info {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin: 20px 0;
            text-align: left;
            border-radius: 4px;
        }
        .btn {
            display: inline-block;
            padding: 12px 30px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.3s;
            margin-top: 20px;
        }
        .btn:hover {
            opacity: 0.9;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>授权无效</h1>
        <div class="error">' . htmlspecialchars($result['msg']) . '</div>
        <p>请检查您的授权码是否正确，或联系客服获取新的授权。</p>
        <div class="info">
            <strong>授权信息：</strong><br>
            授权码：' . htmlspecialchars(substr($licenseCode, 0, 16) . '****') . '<br>
            当前域名：' . htmlspecialchars($request->host()) . '<br>
            当前IP：' . htmlspecialchars($request->ip()) . '
        </div>
        <a href="/" class="btn">返回首页</a>
    </div>
</body>
</html>', 'html', 403);
        }

        $request->license = $result['data'];

        return $next($request);
    }
}
