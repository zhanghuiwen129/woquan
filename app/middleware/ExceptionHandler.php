<?php

namespace app\middleware;

use app\exception\BusinessException;
use app\exception\ValidationException;
use think\Request;
use think\Response;

class ExceptionHandler
{
    public function handle(Request $request, \Closure $next)
    {
        try {
            return $next($request);
        } catch (\Exception $e) {
            return $this->handleException($request, $e);
        }
    }

    protected function handleException(Request $request, \Exception $e): Response
    {
        if ($e instanceof BusinessException) {
            return $this->jsonResponse(
                $e->getCode(),
                $e->getMessage(),
                $e->getData()
            );
        }

        if ($e instanceof ValidationException) {
            return $this->jsonResponse(
                $e->getCode(),
                $e->getMessage(),
                ['errors' => $e->getErrors()]
            );
        }

        if ($e instanceof \think\exception\ValidateException) {
            return $this->jsonResponse(
                422,
                $e->getError(),
                null
            );
        }

        if ($e instanceof \think\exception\HttpException) {
            return $this->jsonResponse(
                $e->getStatusCode(),
                $e->getMessage() ?: $this->getHttpMessage($e->getStatusCode()),
                null
            );
        }

        $this->logError($e);

        if (config('app.debug')) {
            return $this->jsonResponse(
                500,
                $e->getMessage(),
                [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString()
                ]
            );
        }

        return $this->jsonResponse(500, '服务器内部错误', null);
    }

    protected function jsonResponse($code, $msg, $data): Response
    {
        $response = [
            'code' => $code,
            'msg' => $msg
        ];

        if ($data !== null) {
            $response['data'] = $data;
        }

        return json($response, $code);
    }

    protected function getHttpMessage($statusCode): string
    {
        $messages = [
            400 => '请求参数错误',
            401 => '未授权，请登录',
            403 => '拒绝访问',
            404 => '资源不存在',
            405 => '请求方法不允许',
            422 => '验证失败',
            429 => '请求过于频繁',
            500 => '服务器内部错误',
            502 => '网关错误',
            503 => '服务不可用',
            504 => '网关超时'
        ];

        return $messages[$statusCode] ?? '未知错误';
    }

    protected function logError(\Exception $e): void
    {
        $logData = [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
            'request' => [
                'url' => request()->url(),
                'method' => request()->method(),
                'ip' => request()->ip(),
                'user_agent' => request()->header('user-agent'),
                'params' => request()->param()
            ]
        ];

        \think\facade\Log::error('Exception', $logData);
    }
}
