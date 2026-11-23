<?php
declare(strict_types=1);
/**
 * Hublog 后台「活动管理」子域控制器
 *
 * 从上帝类 AdminController 拆分而来（V158，P2-4 上帝类重构）。
 * 纯 relocate：方法体逐字迁移，extends AdminController 复用父类能力，行为零变化。
 * 路由 handler 已在 routes.php 中改指向本类。
 */

namespace Hublog\Controllers;

use Hublog\Core\BaseController;
use Hublog\Core\Request;
use Hublog\Core\Response;
use Hublog\Core\ActivityRepository;
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

class AdminActivitiesController extends AdminController
{
    // ============================================================
    // V79：活动管理（后台「活动管理」页）
    // 背景：activities 表此前没有任何后台入口，App 里的「活动与打卡」空态
    //       写着「圈主可以在后台创建活动与打卡任务」，但后台其实没有这个功能，
    //       只能手写 SQL 往 activities 插入。本段补上完整的增删改查。
    // ============================================================

    /**
     * GET /api/admin/activities —— 活动列表
     * 参数：keyword、type(signup)、status(open/closed/ended)、page、limit
     */
    public function activities(Request $request): Response
    {
        $user = $this->activityManager($request);
        $isAdmin = ($user['role'] === 'admin');
        $owned = $isAdmin ? [] : $this->ownedCircleIds($user['id']);

        ['page' => $page, 'limit' => $limit, 'offset' => $offset] = $request->pagination(20, 100);
        $keyword = trim($request->getString('keyword'));
        $type = strtolower(trim($request->getString('type')));
        $status = strtolower(trim($request->getString('status')));

        // 圈主：仅看自己圈子的活动；无圈子直接返回空
        if (!$isAdmin) {
            if (empty($owned)) {
                return Response::success(
                    Helper::paginatedResponse([], $page, $limit, 0),
                    '获取成功'
                );
            }
        }

        $scopeIds = $isAdmin ? [] : $owned;
        $rows = ActivityRepository::fetchAdminActivities($keyword, $type, $status, $scopeIds, $offset, $limit);
        $total = ActivityRepository::countAdminActivities($keyword, $type, $status, $scopeIds);

        return Response::success(Helper::paginatedResponse($rows, $page, $limit, $total), '获取成功');
    }

    /**
     * POST /api/admin/activities —— 创建活动
     * PUT /api/admin/activities（带 id）—— 更新活动
     * 参数：title、description、cover、type(signup)、mode(free|count)、
     *      capacity、checkin_goal、start_at、end_at、status(open|closed|ended)、circle_id
     */
    public function saveActivity(Request $request): Response
    {
        $user = $this->activityManager($request);
        $isAdmin = ($user['role'] === 'admin');
        $owned = $isAdmin ? [] : $this->ownedCircleIds($user['id']);

        $id = $request->getInt('id');
        $title = trim($request->getString('title'));
        $description = trim($request->getString('description'));
        $cover = trim($request->getString('cover'));
        $type = strtolower(trim($request->getString('type')));
        $mode = strtolower(trim($request->getString('mode')));
        $capacity = $request->getInt('capacity', 0);
        $checkinGoal = $request->getInt('checkin_goal', 0);
        $status = strtolower(trim($request->getString('status')));
        $circleId = $request->getInt('circle_id', 0);

        if ($title === '' || mb_strlen($title) > 100) {
            return Response::error('活动标题必填（1-100 字）', 400);
        }
        if (mb_strlen($description) > 2000) {
            return Response::error('活动说明最多 2000 字', 400);
        }
        if ($type !== 'signup') {
            return Response::error('活动类型仅支持 signup（报名）', 400);
        }
        if (!in_array($mode, ['free', 'count'], true)) {
            $mode = 'free';
        }
        if (!in_array($status, ['open', 'closed', 'ended'], true)) {
            $status = 'open';
        }
        if ($mode === 'count' && $capacity <= 0) {
            return Response::error('限人数活动必须填写人数上限（正整数）', 400);
        }
        if ($capacity < 0) {
            $capacity = 0;
        }
        if ($checkinGoal < 0) {
            $checkinGoal = 0;
        }
        if ($checkinGoal > 3650) {
            return Response::error('打卡目标天数过大（最多 3650 天）', 400);
        }

        // 时间：空值允许；非法格式拒绝；开始时间不得晚于截止时间
        $startAt = $this->normalizeDateTime((string)$request->getString('start_at'));
        $endAt = $this->normalizeDateTime((string)$request->getString('end_at'));
        if ($startAt === false || $endAt === false) {
            return Response::error('时间格式不正确，应为 YYYY-MM-DD HH:MM:SS', 400);
        }
        if ($startAt && $endAt && strtotime($startAt) > strtotime($endAt)) {
            return Response::error('开始时间不能晚于截止时间', 400);
        }

        // 关联圈子（0 / 空 = 全站活动）
        if ($circleId > 0) {
            $exists = ActivityRepository::countCircleById($circleId);
            if (!$exists) {
                return Response::error('关联的圈子不存在', 400);
            }
        } else {
            $circleId = 0;
        }
        // 圈主只能操作自己圈子的活动
        if (!$isAdmin) {
            if ($circleId <= 0) {
                return Response::error('圈主只能为自己管理的圈子创建活动', 403);
            }
            if (!in_array($circleId, $owned, true)) {
                return Response::error('你不是该圈子的圈主，无法操作', 403);
            }
        }

        $data = [
            'circle_id'    => $circleId > 0 ? $circleId : null,
            'title'        => Security::purify($title),
            'description'  => Security::purify($description),
            'cover'        => $cover !== '' ? Security::purify($cover) : null,
            'type'         => $type,
            'mode'         => $mode,
            'capacity'     => $mode === 'count' ? $capacity : 0,
            'checkin_goal' => 0,
            'start_at'     => $startAt ?: null,
            'end_at'       => $endAt ?: null,
            'status'       => $status,
            'updated_at'   => Helper::now(),
        ];

        if ($id) {
            // P1-9：更新时必须校验「活动当前所属圈子」也归该圈主所有。
            // 原实现只查活动存在与否，圈主可以传别人的活动 id + 自己的 circle_id，
            // 把他人活动改写进自己圈子（劫持标题/状态/报名数据）。
            $currentCircleId = (int)ActivityRepository::fetchActivityCircleId($id);
            if ($currentCircleId <= 0 && !ActivityRepository::existsActivityById($id)) {
                return Response::error('活动不存在', 404);
            }
            if (!$isAdmin && !in_array($currentCircleId, $owned, true)) {
                return Response::error('你不是该活动所属圈子的圈主，无法操作', 403);
            }
            ActivityRepository::updateActivityFields($id, $data);
        } else {
            $data['creator_id'] = (int)($user['id'] ?? 1);
            $data['created_at'] = Helper::now();
            ActivityRepository::insertActivity($data);
        }

        return Response::success(null, $id ? '更新成功' : '创建成功');
    }

    /**
     * DELETE /api/admin/activities?id=xxx —— 删除活动
     * 说明：activity_registrations 有外键 ON DELETE CASCADE，报名记录会随活动一并删除。
     *      打卡（checkins）功能已在 V80 整体下线，此处不再处理。
     */
    public function deleteActivity(Request $request): Response
    {
        $user = $this->activityManager($request);
        $isAdmin = ($user['role'] === 'admin');
        $owned = $isAdmin ? [] : $this->ownedCircleIds($user['id']);

        $id = $request->getInt('id');
        if (!$id) {
            return Response::error('参数错误', 400);
        }
        $act = ActivityRepository::findActivityCircleRow($id);
        if (!$act) {
            return Response::error('活动不存在', 404);
        }
        if (!$isAdmin) {
            $cid = (int)($act['circle_id'] ?? 0);
            if ($cid <= 0 || !in_array($cid, $owned, true)) {
                return Response::error('你不是该圈子的圈主，无法操作', 403);
            }
        }

        // activity_registrations 有外键 ON DELETE CASCADE，报名记录随活动一并删除
        ActivityRepository::deleteActivityById($id);

        return Response::success(null, '删除成功');
    }

    /**
     * GET /api/admin/my-circles — 当前用户可管理的圈子
     * 站点管理员返回全部圈子；圈主只返回自己拥有的圈子（供活动创建时的圈子下拉）。
     */
    public function myCircles(Request $request): Response
    {
        $user = $this->activityManager($request);
        $isAdmin = ($user['role'] === 'admin');
        if ($isAdmin) {
            $list = ActivityRepository::fetchAdminMyCircles();
        } else {
            $ids = $this->ownedCircleIds($user['id']);
            if (empty($ids)) {
                return Response::success(['is_admin' => false, 'circles' => []], '获取成功');
            }
            $list = ActivityRepository::fetchOwnedCircles($ids);
        }
        return Response::success(['is_admin' => $isAdmin, 'circles' => $list], '获取成功');
    }

    /**
     * 活动管理鉴权：站点管理员直接放行；圈主（拥有 ≥1 个圈子）放行，但仅能管理自己圈子的活动。
     * 其余角色（普通用户、非圈主）抛 403。
     */
    private function activityManager(Request $request): array
    {
        $user = Auth::check($request);
        if ($user['role'] === 'admin') {
            return $user;
        }
        $isOwner = ActivityRepository::countOwnedCircles((int)$user['id']) > 0;
        if (!$isOwner) {
            throw new HublogException('权限不足', 403);
        }
        return $user;
    }

    /**
     * 返回当前用户拥有的圈子 ID 列表（圈主判定依据：circles.owner_id）
     */
    private function ownedCircleIds(int $userId): array
    {
        $rows = ActivityRepository::fetchOwnedCircleIds($userId);
        return array_map(function ($r) { return (int)$r['id']; }, $rows);
    }

    /**
     * 规范化后台传入的日期时间字符串。
     * 返回：null（空值）/ 标准 'Y-m-d H:i:s' / false（格式非法）
     *
     * @return string|null|false
     */
    private function normalizeDateTime(string $value)
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        // 兼容 <input type="datetime-local"> 的 'YYYY-MM-DDTHH:MM'
        $value = str_replace('T', ' ', $value);
        $ts = strtotime($value);
        if ($ts === false) {
            return false;
        }
        return date('Y-m-d H:i:s', $ts);
    }

}
