<?php

namespace app\exception;

use think\exception\Handle;
use think\exception\HttpException;
use think\exception\ValidateException;
use think\Response;
use Throwable;

class ExceptionHandler extends Handle
{
    public function render($request, Throwable $e): Response
    {
        if ($request->isAjax() || $request->header('accept') === 'application/json') {
            return $this->renderJson($request, $e);
        }

        return parent::render($request, $e);
    }

    protected function renderJson($request, Throwable $e): Response
    {
        $data = [
            'code' => 500,
            'msg' => '服务器内部错误',
            'data' => null
        ];

        if ($e instanceof BusinessException) {
            $data['code'] = $e->getCode();
            $data['msg'] = $e->getMessage();
            $data['data'] = $e->getData();
        } elseif ($e instanceof ValidationException) {
            $data['code'] = $e->getCode();
            $data['msg'] = $e->getMessage();
            $data['errors'] = $e->getErrors();
        } elseif ($e instanceof ValidateException) {
            $data['code'] = 422;
            $data['msg'] = $e->getError();
        } elseif ($e instanceof HttpException) {
            $data['code'] = $e->getStatusCode();
            $data['msg'] = $e->getMessage() ?: $this->getHttpMessage($e->getStatusCode());
        }

        if (config('app.debug')) {
            $data['trace'] = $e->getTraceAsString();
            $data['file'] = $e->getFile();
            $data['line'] = $e->getLine();
        }

        $this->logError($e);

        return json($data, $data['code']);
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

    protected function logError(Throwable $e): void
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

        if ($e instanceof BusinessException) {
            \think\facade\Log::info('BusinessException', $logData);
        } elseif ($e instanceof ValidationException) {
            \think\facade\Log::info('ValidationException', $logData);
        } else {
            \think\facade\Log::error('Exception', $logData);
        }
    }
}
