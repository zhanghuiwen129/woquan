<?php
declare(strict_types=1);
/**
 * Hublog 后台「圈子管理」子域控制器
 *
 * 从上帝类 AdminController 拆分而来（P2-4 上帝类重构，v2.16.23）。
 * 纯 relocate：方法体逐字迁移，extends AdminController 复用父类能力，行为零变化。
 * 路由 handler 已在 routes.php 中改指向本类。
 */

namespace Hublog\Controllers;

use Hublog\Core\BaseController;
use Hublog\Core\Request;
use Hublog\Core\Response;
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
use Hublog\Core\CircleRepository;

class AdminCircleController extends AdminController
{
    public function circles(Request $request): Response
    {
        $this->requireAdmin($request);

        ['page' => $page, 'limit' => $limit, 'offset' => $offset] = $request->pagination(20, 50);
        $keyword = $request->getString('keyword');

        $rows = CircleRepository::fetchAdminCircleList($keyword, $offset, $limit);
        $total = CircleRepository::countAdminCircles($keyword);

        return Response::success(Helper::paginatedResponse($rows, $page, $limit, $total), '获取成功');
    }

    /**
     * 保存圈子（新增/编辑）
     * POST/PUT /admin/circles {id?, name, description, cover, owner_id, status,
     *                          join_mode?, post_permission?, visibility?,
     *                          pay_type?, pay_vip_level?, pay_amount?, pay_days?}
     */
    public function saveCircle(Request $request): Response
    {
        $this->requireAdmin($request);

        $id = $request->getInt('id');
        $name = trim($request->getString('name'));
        $description = trim($request->getString('description'));
        $cover = trim($request->getString('cover'));
        $ownerId = $request->getInt('owner_id');
        $status = $request->getInt('status', 1);

        // V50：权限字段（白名单校验，非法值回退默认）
        $joinMode = strtolower(trim($request->getString('join_mode')));
        if (!in_array($joinMode, ['free', 'approval', 'invite'], true)) {
            $joinMode = 'free';
        }
        $postPermission = strtolower(trim($request->getString('post_permission')));
        if (!in_array($postPermission, ['all', 'admin', 'owner'], true)) {
            $postPermission = 'all';
        }
        $visibility = strtolower(trim($request->getString('visibility')));
        if (!in_array($visibility, ['public', 'protected', 'private'], true)) {
            $visibility = 'public';
        }

        if ($name === '' || mb_strlen($name) > 50) {
            return Response::error('圈子名称必填（1-50字）', 400);
        }
        if (mb_strlen($description) > 500) {
            return Response::error('简介最多 500 字', 400);
        }
        if ($ownerId <= 0) {
            return Response::error('圈主用户ID必填', 400);
        }

        // 圈主必须是有效用户
        $ownerExists = CircleRepository::countUsersById($ownerId);
        if (!$ownerExists) {
            return Response::error('圈主用户不存在', 400);
        }

        // V67 修复：后台「付费方式 / 订阅价格」此前被控制器丢弃，保存成功是假象。
        // 合法值口径与 CircleController::update() 圈内定价路径一致：
        //   pay_type 0=免费 / 1=会员等级门槛 / 2=付费订阅；等级 1-3；金额非负、两位小数。
        // 后台是运营服务端定价入口，对非法输入直接 400 而非静默钳制，避免假成功。
        // 未提交 pay_type 时不传这些字段，Repository 保持库中原值（兼容部分提交的客户端）。
        $payType = null;
        $payVipLevel = null;
        $payAmount = null;
        $payDays = null;
        if ($request->has('pay_type')) {
            $payType = $request->getInt('pay_type');
            if (!in_array($payType, [0, 1, 2], true)) {
                return Response::error('付费方式仅支持 0/1/2', 400);
            }
            // 免费与订阅分别把另一种门槛的残留值清零，避免切换方式后留有脏配置
            $payVipLevel = 0;
            $payAmount = 0.0;
            if ($payType === 1) {
                $payVipLevel = max(1, min(3, $request->getInt('pay_vip_level') ?: 1));
            } elseif ($payType === 2) {
                $rawAmount = trim($request->getString('pay_amount'));
                if ($rawAmount === '' || !is_numeric($rawAmount)) {
                    return Response::error('订阅价格必须为非负数字', 400);
                }
                $amount = (float)$rawAmount;
                if ($amount < 0 || $amount > 1000000.0) {
                    return Response::error('订阅价格超出允许范围（0-1000000）', 400);
                }
                $payAmount = round($amount, 2);
            }
        }

        // V96：单圈订阅天数 0=跟随全局，1-3650=本圈独立周期。非法值直接 400，不静默钳制。
        if ($request->has('pay_days')) {
            $rawDays = trim($request->getString('pay_days'));
            if ($rawDays === '' || !ctype_digit($rawDays)) {
                return Response::error('订阅天数必须为 0-3650 的整数', 400);
            }
            $days = (int)$rawDays;
            if ($days < 0 || $days > 3650) {
                return Response::error('订阅天数超出允许范围（0-3650）', 400);
            }
            $payDays = $days;
        }

        $now = Helper::now();
        // slug 用 crc32 短哈希 + 随机后缀兜底（编辑既有圈子时该值不参与写入）
        $slug = crc32($name) . substr(md5((string)mt_rand()), 0, 6);

        $savedId = CircleRepository::saveAdminCircle(
            $id,
            $name,
            $description,
            $cover,
            $ownerId,
            $status,
            $joinMode,
            $postPermission,
            $visibility,
            $slug,
            $now,
            $payType,
            $payVipLevel,
            $payAmount,
            $payDays
        );
        if ($savedId === 0) {
            return Response::error('圈子不存在', 404);
        }
        $id = $savedId;

        // 清缓存
        $this->invalidateCircleListCache();

        return Response::success(['id' => $id], '保存成功');
    }

    /**
     * 删除圈子（强制删，级联：清 circle_members + posts.circle_id 置 NULL）
     * DELETE /admin/circles?id=
     */
    public function deleteCircle(Request $request): Response
    {
        $this->requireAdmin($request);

        $id = $request->getInt('id');
        if ($id <= 0) {
            return Response::error('参数错误', 400);
        }

        $circle = CircleRepository::findCircleIdForAdmin($id);
        if (!$circle) {
            return Response::error('圈子不存在', 404);
        }

        // 圈内动态 circle_id 置 NULL（保留动态）→ 成员关系清空 → 删圈子，事务整体下沉
        CircleRepository::deleteAdminCircle($id);

        $this->invalidateCircleListCache();
        return Response::success(null, '删除成功');
    }

    /**
     * 圈子成员列表（后台）
     * GET /admin/circles/members?id=&page=&limit=
     */
    public function circleMembers(Request $request): Response
    {
        $this->requireAdmin($request);

        $id = $request->getInt('id');
        if ($id <= 0) {
            return Response::error('圈子ID必填', 400);
        }

        ['page' => $page, 'limit' => $limit, 'offset' => $offset] = $request->pagination(50, 100);

        $rows = CircleRepository::fetchAdminMembers($id, $offset, $limit);
        $total = CircleRepository::countAdminMembers($id);

        return Response::success(Helper::paginatedResponse($rows, $page, $limit, $total), '获取成功');
    }

    /**
     * 移除圈子成员（不能移除圈主）
     * DELETE /admin/circles/members?circle_id=&user_id=
     */
    public function removeCircleMember(Request $request): Response
    {
        $this->requireAdmin($request);

        $circleId = $request->getInt('circle_id');
        $userId = $request->getInt('user_id');
        if ($circleId <= 0 || $userId <= 0) {
            return Response::error('参数错误', 400);
        }

        $role = CircleRepository::getMemberRole($circleId, $userId);
        if ($role === null) {
            return Response::error('成员不存在', 404);
        }
        if ($role === 'owner') {
            return Response::error('不能移除圈主，请先在编辑页转让圈主', 400);
        }

        // 删除成员 + 同步 member_count（沿用既有非事务语义）
        CircleRepository::removeAdminMember($circleId, $userId, Helper::now());

        $this->invalidateCircleListCache();
        return Response::success(null, '移除成功');
    }

    /**
     * 清理圈子列表相关缓存（按前缀删除 circles_list_ 键）
     */
    private function invalidateCircleListCache(): void
    {
        try {
            Cache::deleteByPrefix('circles_list_');
        } catch (\Throwable $e) {
            // 缓存清理失败不影响主流程
        }
    }

    // ===== V50 圈子权限扩展 =====

    /**
     * 设置成员角色（任命/取消管理员，圈主不可改）
     * POST /admin/circles/members/role {circle_id, user_id, role}
     * role 取值：admin / member
     */
    public function setCircleMemberRole(Request $request): Response
    {
        $this->requireAdmin($request);

        $circleId = $request->getInt('circle_id');
        $userId = $request->getInt('user_id');
        $role = strtolower(trim($request->getString('role')));

        if ($circleId <= 0 || $userId <= 0) {
            return Response::error('参数错误', 400);
        }
        if (!in_array($role, ['admin', 'member'], true)) {
            return Response::error('role 仅支持 admin 或 member', 400);
        }

        $currentRole = CircleRepository::getMemberRole($circleId, $userId);
        if ($currentRole === null) {
            return Response::error('成员不存在', 404);
        }
        if ($currentRole === 'owner') {
            return Response::error('圈主角色不可通过此接口修改', 400);
        }
        if ($currentRole === $role) {
            return Response::success(null, '角色未变更');
        }

        CircleRepository::setMemberRole($circleId, $userId, $role);

        // V82：圈子角色变更 → 徽章实时校验（圈主/圈子管理员）
        BadgeService::onEvent($userId, 'circle_role_changed');

        return Response::success(['role' => $role], '已设为' . ($role === 'admin' ? '管理员' : '普通成员'));
    }

    /**
     * 转让圈主（把目标成员升为 owner，原圈主降为 admin）
     * POST /admin/circles/transfer {circle_id, new_owner_id}
     */
    public function transferCircleOwner(Request $request): Response
    {
        $this->requireAdmin($request);

        $circleId = $request->getInt('circle_id');
        $newOwnerId = $request->getInt('new_owner_id');

        if ($circleId <= 0 || $newOwnerId <= 0) {
            return Response::error('参数错误', 400);
        }

        $circle = CircleRepository::findCircleOwner($circleId);
        if (!$circle) {
            return Response::error('圈子不存在', 404);
        }
        $oldOwnerId = (int)$circle['owner_id'];
        if ($oldOwnerId === $newOwnerId) {
            return Response::error('新圈主与当前圈主相同', 400);
        }

        // 新圈主必须是该圈子成员（非成员不可直接转让）
        $newOwnerRow = CircleRepository::findTargetMemberId($circleId, $newOwnerId);
        if (!$newOwnerRow) {
            return Response::error('目标用户不是圈子成员，请先将其加入', 400);
        }

        $now = Helper::now();
        CircleRepository::transferAdminCircleOwner(
            $circleId,
            $newOwnerId,
            (int)$newOwnerRow['id'],
            $oldOwnerId,
            $now
        );

        $this->invalidateCircleListCache();

        // V82：圈主转让 → 新旧圈主的圈子身份徽章实时校验
        BadgeService::onEvent($newOwnerId, 'circle_role_changed');
        if ($oldOwnerId > 0) {
            BadgeService::onEvent($oldOwnerId, 'circle_role_changed');
        }

        return Response::success(null, '圈主已转让');
    }

    /**
     * 后台查看圈子待审申请列表
     * GET /admin/circles/requests?id=&page=&limit=
     */
    public function circlePendingRequests(Request $request): Response
    {
        $this->requireAdmin($request);

        $id = $request->getInt('id');
        if ($id <= 0) {
            return Response::error('圈子ID必填', 400);
        }

        ['page' => $page, 'limit' => $limit, 'offset' => $offset] = $request->pagination(20, 50);

        // 后台待审列表字段清单与前台不同（含 reviewer_id），计数规则共用
        $rows = CircleRepository::fetchAdminPendingRequests($id, $offset, $limit);
        $total = CircleRepository::countPendingRequests($id);

        return Response::success(Helper::paginatedResponse($rows, $page, $limit, $total), '获取成功');
    }

    /**
     * 后台通过加入申请
     * POST /admin/circles/requests/approve {circle_id, user_id}
     */
    public function approveCircleRequest(Request $request): Response
    {
        $admin = $this->requireAdmin($request);
        $adminId = (int)($admin['id'] ?? 0);

        $circleId = $request->getInt('circle_id');
        $userId = $request->getInt('user_id');
        if ($circleId <= 0 || $userId <= 0) {
            return Response::error('参数错误', 400);
        }

        $apply = CircleRepository::findPendingJoinRequest($circleId, $userId);
        if (!$apply) {
            return Response::error('申请不存在或已处理', 404);
        }

        // 已是成员 → 标记申请 approved
        if (CircleRepository::memberExists($circleId, $userId)) {
            CircleRepository::markRequestApproved($adminId, (int)$apply['id']);
            return Response::success(null, '该用户已是成员');
        }

        CircleRepository::approveAdminJoinRequest(
            $circleId,
            $userId,
            $adminId,
            (int)$apply['id'],
            Helper::now()
        );

        $this->invalidateCircleListCache();
        return Response::success(null, '已通过');
    }

    /**
     * 后台拒绝加入申请
     * POST /admin/circles/requests/reject {circle_id, user_id}
     */
    public function rejectCircleRequest(Request $request): Response
    {
        $admin = $this->requireAdmin($request);
        $adminId = (int)($admin['id'] ?? 0);

        $circleId = $request->getInt('circle_id');
        $userId = $request->getInt('user_id');
        if ($circleId <= 0 || $userId <= 0) {
            return Response::error('参数错误', 400);
        }

        $apply = CircleRepository::findPendingRequestId($circleId, $userId);
        if (!$apply) {
            return Response::error('申请不存在或已处理', 404);
        }

        CircleRepository::markRequestRejected($adminId, (int)$apply['id']);

        return Response::success(null, '已拒绝');
    }

    /**
     * POST /admin/circles/moderate —— 圈子限流 / 下架 / 恢复
     * {circle_id, action: limit | unlimit | remove | restore}
     *
     * limited    = 1：可浏览，不可发帖/评论（Moderation::assertCircleWritable 拦）
     * removed_at 非空：整圈下架，列表与详情都不再出现
     */
    public function circleModerate(Request $request): Response
    {
        $this->requireAdmin($request);

        $circleId = $request->getInt('circle_id') ?: $request->getInt('id');
        $action   = strtolower(trim($request->getString('action')));
        if (!$circleId || !in_array($action, ['limit', 'unlimit', 'remove', 'restore'], true)) {
            return Response::error('参数错误', 400);
        }

        $now = Helper::now();
        if ($action === 'limit') {
            CircleRepository::setCircleLimited($circleId, 1, $now);
            $msg = '已限制该圈子发言';
        } elseif ($action === 'unlimit') {
            CircleRepository::setCircleLimited($circleId, 0, $now);
            $msg = '已恢复该圈子发言';
        } elseif ($action === 'remove') {
            CircleRepository::setCircleRemoved($circleId, $now, $now);
            $msg = '已下架该圈子';
        } else {
            CircleRepository::setCircleRemoved($circleId, null, $now);
            $msg = '已恢复该圈子';
        }

        // 圈子列表页在 CircleController 里按 circles_list_ 前缀缓存
        Cache::deleteByPrefix('circles_list_');

        return Response::success(null, $msg);
    }

    /**
     * GET /api/admin/crash-logs —— 客户端异常列表（错误/接口异常收集）
     *
     * 配套表 app_crash_logs（由 AppController::track 在 error / api_error 事件时写入）。
     * 支持筛选：event(error/api_error)、platform(android/ios/h5)、date(YYYY-MM-DD)、keyword(模糊)。
     * 额外返回 Top N 聚合（按 event+message 分组，定位高频故障）与今日新增数（用于阈值告警）。
     */
}
