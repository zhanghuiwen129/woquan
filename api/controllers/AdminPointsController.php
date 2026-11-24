<?php
declare(strict_types=1);
/**
 * Hublog 后台「积分与精选」子域控制器
 *
 * 从上帝类 AdminController 拆分而来（P2-4 上帝类重构，v2.16.23）。
 * 纯 relocate：方法体逐字迁移，extends AdminController 复用父类能力，行为零变化。
 * 路由 handler 已在 routes.php 中改指向本类。
 */

namespace Hublog\Controllers;

use Hublog\Core\BaseController;
use Hublog\Core\Request;
use Hublog\Core\Response;
use Hublog\Core\PointRepository;
use Hublog\Core\Database;
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

class AdminPointsController extends AdminController
{
    public function pointsLogs(Request $request): Response
    {
        $this->requireAdmin($request);

        $page = max(1, $request->getInt('page', 1));
        $limit = min(100, max(1, $request->getInt('limit', 20)));
        $offset = ($page - 1) * $limit;
        $type = trim((string)$request->getString('type'));
        $keyword = trim((string)$request->getString('keyword'));

        $rows = PointRepository::fetchAdminLogs($type, $keyword, $offset, $limit);
        $total = PointRepository::countAdminLogs($type, $keyword);

        return Response::success([
            'list' => $rows,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => max(1, (int)ceil($total / $limit)),
            ],
        ], '获取成功');
    }

    /**
     * 后台手动加减积分（补偿漏发 / 处罚刷分）
     * POST /api/admin/points-adjust  { user_id, points, remark }
     */
    public function pointsAdjust(Request $request): Response
    {
        $this->requireAdmin($request);

        $userId = $request->getInt('user_id');
        $delta = (int)$request->getString('points');
        $remark = trim((string)$request->getString('remark'));

        if ($userId <= 0) {
            return Response::error('用户 ID 必填', 400);
        }
        if ($delta === 0) {
            return Response::error('积分数量不合法', 400);
        }
        if ($remark === '') {
            $remark = $delta > 0 ? '后台发放积分' : '后台扣除积分';
        }

        $user = PointRepository::findAdminAdjustUser($userId);
        if (!$user) {
            return Response::error('用户不存在', 404);
        }

        // V102：addPoints 只做条件原子更新 + 写流水，本身不带事务；
        // 后台调整必须在事务内调用，否则流水 INSERT 失败时积分已落库、无法回滚，
        // 造成 users.points 与 points_logs 漂移（对账脚本会报出来）。
        Database::beginTransaction();
        try {
            // 复用积分中心的双写工具（users.points + points_logs 一起写）
            $after = PointController::addPoints($userId, $delta, 'adjust', 'admin', 0, $remark);
            Database::commit();
            return Response::success(['points' => $after], '已调整');
        } catch (\Throwable $e) {
            Database::rollBack();
            error_log('[Hublog pointsAdjust] ' . $e->getMessage()); // @allow-error-log
            return Response::error('调整失败（积分不足？）', 500);
        }
    }

    /**
     * 积分任务配置列表
     * GET /api/admin/point-tasks
     */
    public function pointTasks(Request $request): Response
    {
        $this->requireAdmin($request);

        $rows = PointRepository::fetchAllAdminTasks();
        return Response::success($rows, '获取成功');
    }

    /**
     * 新增 / 修改积分任务
     * POST /api/admin/point-task-save { id, title, desc, points, task_type, target, period, sort, status }
     */
    public function pointTaskSave(Request $request): Response
    {
        $this->requireAdmin($request);

        $id = $request->getInt('id');
        $title = trim((string)$request->getString('title'));
        $desc = trim((string)$request->getString('desc'));
        $points = max(0, $request->getInt('points'));
        $type = trim((string)$request->getString('task_type'));
        $target = max(1, $request->getInt('target'));
        $period = trim((string)$request->getString('period')) === 'once' ? 'once' : 'daily';
        $sort = max(0, $request->getInt('sort'));
        $status = (int)$request->getString('status') === 1 ? 1 : 0;

        if ($title === '') {
            return Response::error('任务标题必填', 400);
        }
        if ($points <= 0 || $points > 100000) {
            return Response::error('奖励积分需在 1 ~ 100000 之间', 400);
        }
        if (!in_array($type, ['profile', 'post', 'comment', 'like', 'circle_join', 'follow', 'signin_days'], true)) {
            return Response::error('任务类型不合法', 400);
        }
        if ($id > 0) {
            $exists = PointRepository::findTaskIdRow($id);
            if (!$exists) {
                return Response::error('任务不存在', 404);
            }
            PointRepository::updateAdminTask(
                $id,
                $title,
                $desc,
                $points,
                $type,
                $target,
                $period,
                $sort,
                $status
            );
        } else {
            PointRepository::insertAdminTask(
                $title,
                $desc,
                $points,
                $type,
                $target,
                $period,
                $status,
                $sort
            );
        }

        return Response::success(null, '已保存');
    }

    /**
     * 删除积分任务
     * POST /api/admin/point-task-delete { id }
     */
    public function pointTaskDelete(Request $request): Response
    {
        $this->requireAdmin($request);

        $id = $request->getInt('id');
        if ($id <= 0) {
            return Response::error('参数错误', 400);
        }
        PointRepository::deleteAdminTask($id);

        return Response::success(null, '已删除');
    }

    /**
     * V65：公开运营位 / 全站精华详情（App 首页「精选」楼层取数用，不需要登录）
     * GET /api/site/featured?slot=home&limit=6
     */
    public function featuredPosts(Request $request): Response
    {
        $slot = $request->getString('slot') ?: 'home';
        if (!in_array($slot, ['home', 'circle'], true)) {
            $slot = 'home';
        }
        $limit = min(max($request->getInt('limit', 6), 1), 20);

        $rows = PointRepository::fetchFeaturedPosts($slot, $limit);

        return Response::success($rows);
    }

}
