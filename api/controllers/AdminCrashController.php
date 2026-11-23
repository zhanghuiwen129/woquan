<?php
declare(strict_types=1);
/**
 * Hublog 后台「崩溃日志」子域控制器
 *
 * 从上帝类 AdminController 拆分而来（P2-4 上帝类重构，v2.16.23）。
 * 纯 relocate：方法体逐字迁移，extends AdminController 复用父类能力，行为零变化。
 * 路由 handler 已在 routes.php 中改指向本类。
 */

namespace Hublog\Controllers;

use Hublog\Core\BaseController;
use Hublog\Core\Request;
use Hublog\Core\Response;
use Hublog\Core\AppRepository;
use Hublog\Core\Auth;
use Hublog\Core\Cache;
use Hublog\Core\Security;
use Hublog\Core\Helper;
use Hublog\Core\PathResolver;
use Hublog\Core\Upload;
use Hublog\Core\Upgrade;
use Hublog\Core\Watermark;
use Hublog\Core\Moderation;
use Hublog\Core\Exception\HublogException;
use Hublog\Core\BadgeService;
use Hublog\Core\MediaHelper;

class AdminCrashController extends AdminController
{
    public function crashLogs(Request $request): Response
    {
        $this->requireAdmin($request);

        $page = max(1, $request->getInt('page', 1));
        $limit = min(200, max(10, $request->getInt('limit', 50)));
        $event = strtolower(trim((string)$request->getString('event')));
        $platform = strtolower(trim((string)$request->getString('platform')));
        $keyword = trim((string)$request->getString('keyword'));
        $date = trim((string)$request->getString('date'));

        // 过滤白名单守卫与 WHERE 构造已下沉仓储，count/list/top 共用同一套 WHERE
        $total = AppRepository::countCrashLogs($event, $platform, $date, $keyword);
        $offset = ($page - 1) * $limit;
        $rows = AppRepository::fetchCrashLogs($event, $platform, $date, $keyword, $limit, $offset);

        // Top N 聚合：同一 event + 同一 message（堆栈/摘要）视为同一类故障，定位高频问题
        $top = AppRepository::fetchCrashTop($event, $platform, $date, $keyword);

        // 今日新增（不受筛选影响，用于阈值告警总览）
        $today = AppRepository::countTodayCrashLogs();
        $threshold = (int)(AppRepository::fetchCrashAlertThreshold() ?: 0);

        return Response::success([
            'list'    => array_map(function ($r) {
                $r['user_id'] = (int)$r['user_id'];
                return $r;
            }, $rows ?: []),
            'top'     => $top ?: [],
            'total'   => $total,
            'today'   => $today,
            'threshold'=> $threshold,
            'page'    => $page,
            'limit'   => $limit,
        ]);
    }

    /**
     * PUT /api/admin/crash-alert-threshold —— 设置「每日新增异常数」告警阈值
     *
     * 阈值 > 0 时，后台「客户端异常」页面会在今日新增超过该值时给出醒目提示。
     * （真正的邮件/推送投递可在此基础上接入现有 mail 体系，本接口只负责阈值配置。）
     */
    public function crashAlertThreshold(Request $request): Response
    {
        $this->requireAdmin($request);
        $threshold = max(0, $request->getInt('threshold', 0));
        // 「先插后更」完整序列（含唯一键冲突降级）已下沉仓储；仓储内失败照原样上抛
        AppRepository::saveCrashAlertThreshold((string)$threshold);
        Cache::delete('site_settings');
        return Response::success(['threshold' => $threshold], '告警阈值已保存');
    }
}
