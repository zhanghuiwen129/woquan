<?php
namespace app;

use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;
use think\exception\Handle;
use think\exception\HttpException;
use think\exception\HttpResponseException;
use think\exception\ValidateException;
use think\Response;
use Throwable;

/**
 * 应用异常处理类
 */
class ExceptionHandle extends Handle
{
    /**
     * 不需要记录信息（日志）的异常类列表
     * @var array
     */
    protected $ignoreReport = [
        HttpException::class,
        HttpResponseException::class,
        ModelNotFoundException::class,
        DataNotFoundException::class,
        ValidateException::class,
    ];

    /**
     * 记录异常信息（包括日志或者其它方式记录）
     *
     * @access public
     * @param  Throwable $exception
     * @return void
     */
    public function report(Throwable $exception): void
    {
        // 使用内置的方式记录异常日志
        parent::report($exception);
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @access public
     * @param \think\Request   $request
     * @param Throwable $e
     * @return Response
     */
    public function render($request, Throwable $e): Response
    {
        // 如果是API请求，统一返回JSON格式
        $acceptHeader = $request->header('accept');
        if ($request->isAjax() || (is_string($acceptHeader) && strpos($acceptHeader, 'application/json') !== false)) {
            return $this->renderApiError($e);
        }

        // 如果是页面请求，显示友好的错误页面
        return $this->renderPageError($request, $e);
    }

    /**
     * 渲染API错误响应
     */
    protected function renderApiError(Throwable $e): Response
    {
        $statusCode = 500;
        $message = '系统错误';

        // 获取错误状态码和消息
        if ($e instanceof \think\exception\HttpException) {
            $statusCode = $e->getStatusCode();
            $message = $e->getMessage() ?: $this->getErrorMessage($statusCode);
        } else {
            $message = $e->getMessage() ?: '系统错误';
        }

        return json([
            'code' => $statusCode,
            'msg'  => $message,
            'data' => null
        ]);
    }

    /**
     * 渲染页面错误响应
     */
    protected function renderPageError($request, Throwable $e): Response
    {
        $statusCode = 500;
        $title = '系统错误';
        $message = '系统出现了一些问题，请稍后再试';
        $icon = '⚠️';

        // 根据异常类型设置错误信息
        if ($e instanceof \think\exception\HttpException) {
            $statusCode = $e->getStatusCode();
            
            switch ($statusCode) {
                case 404:
                    $title = '页面不存在';
                    $message = '抱歉，您访问的页面不存在或已被移除';
                    $icon = '🔍';
                    break;
                case 403:
                    $title = '访问被拒绝';
                    $message = '抱歉，您没有权限访问此页面';
                    $icon = '🔒';
                    break;
                case 500:
                    $title = '服务器错误';
                    $message = '服务器出现了一些问题，请稍后再试';
                    $icon = '🔥';
                    break;
                case 502:
                    $title = '网关错误';
                    $message = '服务器网关出现了一些问题';
                    $icon = '🌐';
                    break;
                case 503:
                    $title = '服务不可用';
                    $message = '服务暂时不可用，请稍后再试';
                    $icon = '🚧';
                    break;
                default:
                    $title = '错误 ' . $statusCode;
                    $message = $e->getMessage() ?: '页面请求出错';
                    $icon = '❌';
            }
        } elseif ($e instanceof \think\exception\ValidateException) {
            $statusCode = 422;
            $title = '数据验证失败';
            $message = $e->getMessage();
            $icon = '⚠️';
        }

        // 开发环境显示详细错误信息
        if (config('app.app_debug')) {
            $message .= "\n\n错误详情：\n" . $e->getMessage() . "\n\n" . $e->getFile() . ':' . $e->getLine();
        }

        // 尝试加载对应的错误模板
        $templatePath = app()->getBasePath() . 'view/error/' . $statusCode . '.html';
        if (file_exists($templatePath)) {
            $html = file_get_contents($templatePath);
            return response($html, $statusCode)->contentType('text/html; charset=utf-8');
        }

        // 生成通用错误页面
        $html = $this->generateErrorPage($statusCode, $title, $message, $icon);
        return response($html, $statusCode)->contentType('text/html; charset=utf-8');
    }

    /**
     * 生成错误页面HTML
     */
    protected function generateErrorPage($statusCode, $title, $message, $icon): string
    {
        $gradientColors = $this->getGradientColors($statusCode);
        
        return <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$statusCode} - {$title}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: "PingFang SC", "Microsoft YaHei", Arial, sans-serif;
            background: linear-gradient(135deg, #0a0e27 0%, #1a1f3a 50%, #0a0e27 100%);
            color: #fff;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            overflow: hidden;
        }
        
        /* 动态背景 */
        body::before {
            content: '';
            position: fixed;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle at 20% 80%, rgba(0, 242, 254, 0.1) 0%, transparent 50%),
                        radial-gradient(circle at 80% 20%, rgba(79, 172, 254, 0.1) 0%, transparent 50%);
            animation: bgMove 20s ease-in-out infinite;
        }
        
        @keyframes bgMove {
            0%, 100% { transform: translate(0, 0); }
            50% { transform: translate(-30px, -30px); }
        }
        
        .error-container {
            position: relative;
            z-index: 1;
            text-align: center;
            max-width: 600px;
            animation: fadeIn 0.6s ease-out;
        }
        
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .error-icon {
            font-size: 100px;
            margin-bottom: 30px;
            animation: float 3s ease-in-out infinite;
            filter: drop-shadow(0 0 20px rgba(0, 242, 254, 0.5));
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-20px); }
        }
        
        .error-code {
            font-size: 120px;
            font-weight: 900;
            background: linear-gradient(135deg, {$gradientColors['start']} 0%, {$gradientColors['end']} 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 20px;
            line-height: 1;
            text-shadow: 0 0 50px rgba(0, 242, 254, 0.3);
            animation: pulse 2s ease-in-out infinite;
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.02); }
        }
        
        .error-title {
            font-size: 32px;
            font-weight: bold;
            margin-bottom: 15px;
            color: #fff;
            text-shadow: 0 0 20px rgba(0, 242, 254, 0.5);
        }
        
        .error-message {
            font-size: 16px;
            margin-bottom: 40px;
            line-height: 1.8;
            color: rgba(255, 255, 255, 0.7);
            white-space: pre-line;
            max-width: 480px;
            margin-left: auto;
            margin-right: auto;
        }
        
        .action-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
            flex-wrap: wrap;
        }
        
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 16px 40px;
            color: #fff;
            text-decoration: none;
            border-radius: 30px;
            font-weight: bold;
            font-size: 16px;
            transition: all 0.3s;
            cursor: pointer;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, {$gradientColors['start']} 0%, {$gradientColors['end']} 100%);
            box-shadow: 0 0 30px rgba(0, 242, 254, 0.4);
        }
        
        .btn-primary:hover {
            transform: scale(1.05);
            box-shadow: 0 0 50px rgba(0, 242, 254, 0.6);
        }
        
        .btn-secondary {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: scale(1.05);
        }
        
        /* 装饰元素 */
        .decor {
            position: absolute;
            border-radius: 50%;
            opacity: 0.3;
        }
        
        .decor-1 {
            width: 300px;
            height: 300px;
            background: radial-gradient(circle, {$gradientColors['start']} 0%, transparent 70%);
            top: -150px;
            right: -150px;
            animation: rotate 20s linear infinite;
        }
        
        .decor-2 {
            width: 200px;
            height: 200px;
            background: radial-gradient(circle, {$gradientColors['end']} 0%, transparent 70%);
            bottom: -100px;
            left: -100px;
            animation: rotate 15s linear infinite reverse;
        }
        
        @keyframes rotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        /* 响应式设计 */
        @media (max-width: 768px) {
            .error-code {
                font-size: 80px;
            }
            
            .error-title {
                font-size: 24px;
            }
            
            .error-message {
                font-size: 14px;
            }
            
            .btn {
                padding: 14px 30px;
                font-size: 14px;
            }
            
            .action-buttons {
                flex-direction: column;
                align-items: center;
            }
        }
    </style>
</head>
<body>
    <div class="decor decor-1"></div>
    <div class="decor decor-2"></div>
    
    <div class="error-container">
        <div class="error-icon">{$icon}</div>
        <div class="error-code">{$statusCode}</div>
        <div class="error-title">{$title}</div>
        <div class="error-message">{$message}</div>
        <div class="action-buttons">
            <a href="/" class="btn btn-primary">
                <span>🏠</span>
                返回首页
            </a>
            <a href="javascript:history.back()" class="btn btn-secondary">
                <span>←</span>
                返回上一页
            </a>
        </div>
    </div>
</body>
</html>
HTML;
    }

    /**
     * 根据状态码获取渐变颜色
     */
    protected function getGradientColors($statusCode): array
    {
        $colorMap = [
            400 => ['#ff6b6b', '#ee5a5a'],
            401 => ['#ffa502', '#ff6348'],
            403 => ['#ff4757', '#ff3838'],
            404 => ['#00f2fe', '#4facfe'],
            500 => ['#ff6b6b', '#ff8e8e'],
            502 => ['#ffa502', '#ff6348'],
            503 => ['#ffa502', '#ff6348'],
        ];

        $colors = $colorMap[$statusCode] ?? ['#00f2fe', '#4facfe'];
        return ['start' => $colors[0], 'end' => $colors[1]];
    }

    /**
     * 根据状态码获取错误消息
     */
    protected function getErrorMessage($statusCode): string
    {
        $messageMap = [
            400 => '请求错误',
            401 => '未授权',
            403 => '禁止访问',
            404 => '页面不存在',
            500 => '服务器错误',
            502 => '网关错误',
            503 => '服务不可用',
        ];

        return $messageMap[$statusCode] ?? '未知错误';
    }
    
    /**
     * 判断是否为生产环境
     */
    protected function isProduction(): bool
    {
        return !config('app.app_debug');
    }
    
    /**
     * 获取错误页面HTML
     */
    protected function getErrorPage(Throwable $e): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>系统错误</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #0a0e27;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
        }
        .error-container {
            text-align: center;
            padding: 20px;
            max-width: 600px;
        }
        .error-icon {
            font-size: 80px;
            margin-bottom: 20px;
        }
        .error-title {
            font-size: 28px;
            font-weight: bold;
            margin-bottom: 15px;
            color: #ff4757;
        }
        .error-message {
            font-size: 16px;
            margin-bottom: 30px;
            line-height: 1.6;
            color: rgba(255,255,255,0.8);
        }
        .back-link {
            display: inline-block;
            padding: 12px 30px;
            background: linear-gradient(135deg, #ff4757 0%, #ff6b81 100%);
            color: #fff;
            text-decoration: none;
            border-radius: 25px;
            font-weight: bold;
            transition: transform 0.3s;
        }
        .back-link:hover {
            transform: scale(1.05);
        }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-icon">⚠️</div>
        <div class="error-title">系统错误</div>
        <div class="error-message">抱歉，系统出现了一些问题。请稍后再试，或者返回首页。</div>
        <a href="/" class="back-link">返回首页</a>
    </div>
</body>
</html>
HTML;
    }
}
