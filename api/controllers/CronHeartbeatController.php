<?php
/**
 * Copyright 2026 woquan. All rights reserved.
 * 本文件为本项目新增代码，商业使用须取得书面授权。
 *
 * POST /api/cron/license-heartbeat —— 授权统计上报的 cron 兜底入口（P1-04）。
 *
 * 鉴权复用 CronAuth::verify()（body secret 或 X-Cron-Token），
 * 与 /api/cron/jobs、/api/cron/badges 共用同一套 cron_secret。
 * 本端点只触发一次 best-effort 上报；未提供 token 时 fail-closed。
 */

declare(strict_types=1);

namespace Hublog\Controllers;

use Hublog\Core\BaseController;
use Hublog\Core\CronAuth;
use Hublog\Core\HeartbeatReporter;
use Hublog\Core\Request;
use Hublog\Core\Response;

class CronHeartbeatController extends BaseController
{
    /**
     * 主动触发一次心跳上报。
     *
     * @return Response {reported: bool}；鉴权失败返回 403
     */
    public function cron(Request $request): Response
    {
        $secret = (string)$request->getString('secret');
        if ($secret === '') {
            $secret = (string)($request->getHeader('X-Cron-Token') ?? '');
        }
        if (!CronAuth::verify($secret)) {
            return Response::error('cron token 无效或未配置', 403);
        }

        try {
            $reported = HeartbeatReporter::reportNow();
            return Response::success(['reported' => $reported]);
        } catch (\Throwable $e) {
            // 上报器对外承诺不抛异常，这里仍兜底，保证 cron 端点自身永不 500
            return Response::success(['reported' => false]);
        }
    }
}
