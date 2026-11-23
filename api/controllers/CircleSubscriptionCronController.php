<?php
/**
 * Hublog 付费圈子订阅生命周期 cron 入口
 *
 * POST /api/cron/circle-subscribes
 * 鉴权复用 CronAuth::verify()（body secret 或请求头 X-Cron-Token），
 * 与 /api/cron/jobs、/api/cron/badges 同一套 token。
 *
 * 本入口只做 best-effort 的到期清退与提醒；内容门禁在读路径上独立判定 expire_at，
 * 即使这个 cron 完全没跑，过期用户也读不到圈子正文。
 */

declare(strict_types=1);

namespace Hublog\Controllers;

use Hublog\Core\BaseController;
use Hublog\Core\CircleSubscriptionService;
use Hublog\Core\CronAuth;
use Hublog\Core\Request;
use Hublog\Core\Response;

class CircleSubscriptionCronController extends BaseController
{
    /**
     * 扫描过期订阅并清退，同时发送到期前 / 到期当天提醒。
     *
     * 参数：
     *   secret       —— 鉴权用（也可用请求头 X-Cron-Token）
     *   limit        —— 本轮最多处理多少行，默认 200，上限 1000
     *   lookahead    —— 到期前 N 天提醒，默认读 settings.circle_expire_remind_days
     *
     * @return Response {expired, removed, reminded, failed}
     */
    public function sweep(Request $request): Response
    {
        $secret = (string)$request->getString('secret');
        if ($secret === '') {
            $secret = (string)($request->getHeader('X-Cron-Token') ?? '');
        }
        if (!CronAuth::verify($secret)) {
            return Response::error('cron token 无效或未配置', 403);
        }

        $limit = max(1, min(1000, $request->getInt('limit', 200)));
        $lookaheadRaw = $request->get('lookahead');
        $lookahead = ($lookaheadRaw === null || $lookaheadRaw === '') ? null : (int)$lookaheadRaw;

        try {
            // 先提醒再清退：清退会把 status 置 0，反过来会让到期提醒漏发
            $reminders = CircleSubscriptionService::sendReminders($lookahead, $limit);
            $swept = CircleSubscriptionService::sweepExpired($limit);
            $result = [
                'expired' => $swept['expired'],
                'removed' => $swept['removed'],
                'reminded' => $reminders['reminded'],
                'failed' => $swept['failed'] + $reminders['failed'],
            ];
            error_log('[Hublog cronCircleSubscribes] ' . json_encode($result, JSON_UNESCAPED_UNICODE)); // @allow-error-log
            return Response::success($result);
        } catch (\Throwable $e) {
            error_log('[Hublog cronCircleSubscribes] 执行失败: ' . $e->getMessage()); // @allow-error-log
            return Response::error('任务执行失败', 500);
        }
    }
}
