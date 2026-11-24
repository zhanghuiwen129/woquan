<?php
/**
 * Hublog VIP 到期批量降级 cron 入口
 *
 * POST /api/cron/vip-expire
 * 鉴权复用 CronAuth::verify()（body secret 或 X-Cron-Token），与其余 cron 同一套 token。
 *
 * 本入口是 best-effort 批量降级：即使完全不跑，读时兜底 syncVip() 仍保证
 * 过期用户拿不到权益；本任务的作用是让落库值及时准确并清掉陈旧缓存
 * （头像框设置读 Auth 缓存，最多可陈旧 300 秒）。
 */

declare(strict_types=1);

namespace Hublog\Controllers;

use Hublog\Core\BaseController;
use Hublog\Core\CronAuth;
use Hublog\Core\Database;
use Hublog\Core\VipExpiryService;
use Hublog\Core\Request;
use Hublog\Core\Response;

class VipExpiryCronController extends BaseController
{
    /**
     * 扫描并降级过期会员。
     *
     * 参数：secret（或 X-Cron-Token）、limit（默认 200，上限 1000）
     *
     * @return Response {scanned, downgraded, failed}
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

        try {
            $result = VipExpiryService::sweepExpired(Database::getInstance(), $limit);
            error_log('[Hublog cronVipExpire] ' . json_encode($result, JSON_UNESCAPED_UNICODE)); // @allow-error-log
            return Response::success($result);
        } catch (\Throwable $e) {
            error_log('[Hublog cronVipExpire] 执行失败: ' . $e->getMessage()); // @allow-error-log
            return Response::error('任务执行失败', 500);
        }
    }
}
