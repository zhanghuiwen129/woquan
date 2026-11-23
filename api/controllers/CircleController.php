<?php
declare(strict_types=1);

/**
 * Hublog 圈子控制器
 *
 * 圈子是「带成员关系的主题社区」：圈主创建圈子，用户加入后成为成员，
 * 成员可发动态到圈子（posts.circle_id），任何人可浏览圈子内动态。
 *
 * 路由分布：
 *  - 公开（游客可看）：list / detail / members / posts
 *  - 需要登录：my / create / update / delete / join / leave
 *
 * 数据访问已下沉到 CircleRepository；本类只保留业务编排、权限校验、
 * 缓存与响应包装。迁移约定见 docs/内联SQL收敛规范.md。
 */

namespace Hublog\Controllers;

use Hublog\Core\BaseController;
use Hublog\Core\Request;
use Hublog\Core\Response;
use Hublog\Core\Auth;
use Hublog\Core\Cache;
use Hublog\Core\Helper;
use Hublog\Core\CircleRepository;
use Hublog\Core\CircleSubscriptionService;
use Hublog\Core\PostRepository;
use Hublog\Core\PostFormatter;
use Hublog\Core\Moderation;
use Hublog\Core\Exception\HublogException;
use Hublog\Core\BadgeService;
use Hublog\Core\MediaHelper;

class CircleController extends BaseController
{
    /**
     * 圈子列表（公开，分页 + 关键词搜索）
     * GET /api/circles?keyword=&page=&limit=
     */
    public function list(Request $request): Response
    {
        $currentUser = Auth::user($request);
        $userId = $currentUser['id'] ?? 0;

        ['page' => $page, 'limit' => $limit, 'offset' => $offset] = $request->pagination(20, 40);
        $keyword = $request->getString('keyword');

        $cacheKey = "circles_list_{$page}_{$limit}_" . md5($keyword);
        $result = Cache::remember($cacheKey, function () use ($keyword, $page, $limit, $offset) {
            // V50：公开列表只展示 visibility='public' 的圈子；
            // protected/private 圈子不进入发现页列表，只能通过直链/邀请进入
            // V71：再加 removed_at IS NULL —— 被后台下架的圈子不能出现在发现页
            // 查询条件与排序由 CircleRepository 保持原样，缓存边界不变
            $rows = CircleRepository::fetchPublicList($keyword, $offset, $limit);
            $total = CircleRepository::countPublic($keyword);

            // cover 是站点根相对路径，App 端 <image> 加载不了，统一补成绝对 URL
            // （处理后的数据进 60s 缓存，域名不变所以无副作用）
            MediaHelper::processCircles($rows);

            return Helper::paginatedResponse($rows, $page, $limit, $total);
        }, 60);

        // 登录用户补 is_member / is_owner 标记（不写缓存，因人而异）
        if ($userId && !empty($result['list'])) {
            $circleIds = array_column($result['list'], 'id');
            $joinedSet = $this->fetchJoinedSet((int)$userId, $circleIds);
            foreach ($result['list'] as &$c) {
                $c['is_member'] = isset($joinedSet[$c['id']]);
                $c['is_owner'] = ((int)$c['owner_id'] === $userId);
            }
            unset($c);
        }

        return Response::success($result, '获取成功');
    }

    /**
     * 圈子详情（公开）
     * GET /api/circles/detail?id=
     */
    public function show(Request $request): Response
    {
        $currentUser = Auth::user($request);
        $userId = $currentUser['id'] ?? 0;

        $id = $request->getInt('id');
        $slug = $request->getString('slug');
        if ($id <= 0 && $slug === '') {
            return Response::error('圈子ID或slug必填', 400);
        }

        // V71：下架的圈子详情也不再对普通用户开放（圈主/管理员在 show() 后续分支里可继续管理）
        $circle = $id > 0
            ? CircleRepository::findVisibleById($id)
            : CircleRepository::findVisibleBySlug($slug);

        if (!$circle) {
            return Response::error('圈子不存在', 404);
        }

        // 圈主信息
        $owner = CircleRepository::findOwner((int)$circle['owner_id']);

        $isMember = false;
        $myRole = null;
        if ($userId) {
            // V65：圈内黑名单成员一律不当成「圈内人」，前端据此隐藏发帖/进群入口
            $row = CircleRepository::getMemberRow((int)$circle['id'], (int)$userId);
            if ($row) {
                if ((int)$row['status'] === 2) {
                    $myRole = 'banned';
                } else {
                    $isMember = true;
                    $myRole = $row['role'];
                }
            }
        }

        // V50 可见性控制：
        //   private  → 非成员直接返回 404（连存在都不暴露）
        //   protected → 非成员可见基本信息（封面/简介/成员数），但 my_role=null（前端据此隐藏 posts/members）
        //   public   → 任何人可见完整信息
        $visibility = $circle['visibility'] ?? 'public';
        if (!$isMember && $visibility === 'private') {
            return Response::error('圈子不存在', 404);
        }

        $circle['owner'] = $owner;
        $circle['is_member'] = $isMember;
        $circle['is_owner'] = ((int)$circle['owner_id'] === $userId);
        $circle['my_role'] = $myRole;

        // V67：付费门槛信息 + 我当前能不能发言。
        //      前端据此显示「订阅后才能发言」而不是让人发一次发现被拒。
        $payType = (int)($circle['pay_type'] ?? 0);
        $circle['pay_type'] = $payType;
        $circle['pay_amount'] = number_format((float)($circle['pay_amount'] ?? 0), 2, '.', '');
        $circle['pay_vip_level'] = (int)($circle['pay_vip_level'] ?? 0);
        if ($payType > 0 && $userId > 0) {
            [$canPost, $payReason] = PaymentController::circlePostAllowed((int)$circle['id'], $circle, $userId);
            $circle['can_post'] = $canPost;
            $circle['pay_reason'] = $payReason;
        } else {
            $circle['can_post'] = true;
            $circle['pay_reason'] = '';
        }

        // V96：订阅态回显（纯追加字段，上面的 can_post / pay_reason 语义保持原样）
        $subscriptionState = CircleSubscriptionService::subscriptionState(
            (int)$userId,
            (int)$circle['id'],
            $circle
        );
        $circle['pay_days'] = CircleSubscriptionService::effectivePayDays((int)$circle['id']);
        $circle['expire_at'] = CircleSubscriptionService::subscriptionExpireAt((int)$userId, (int)$circle['id']);
        $circle['subscription_status'] = $subscriptionState;

        // protected/private 圈子，非成员不返回成员/动态详情（前端按 is_member 决定 UI）
        // 后端 members/posts 接口本身也会拦截，这里只是详情层面限制 owner 信息泄露
        if (!$isMember && $visibility === 'protected') {
            $circle['owner'] = null;
        }

        // cover / owner.avatar 补成绝对 URL（App 端无法加载相对路径）
        MediaHelper::processCircle($circle);

        return Response::success($circle, '获取成功');
    }

    /**
     * 我加入的圈子（登录）
     * GET /api/circles/my
     */
    public function my(Request $request): Response
    {
        $currentUser = $this->requireUser($request);

        $rows = CircleRepository::fetchJoinedCircles((int)$currentUser['id']);

        MediaHelper::processCircles($rows);

        return Response::success(['list' => $rows], '获取成功');
    }

    /**
     * 圈子成员列表（公开圈子可见，protected/private 仅成员可见）
     * GET /api/circles/members?id=&page=&limit=
     */
    public function members(Request $request): Response
    {
        $currentUser = Auth::user($request);
        $userId = $currentUser['id'] ?? 0;

        $id = $request->getInt('id');
        if ($id <= 0) {
            return Response::error('圈子ID必填', 400);
        }

        // V50：protected/private 圈子，非成员不能看成员列表
        $gate = $this->ensureViewable($id, $userId);
        if ($gate !== null) {
            return $gate;
        }

        ['page' => $page, 'limit' => $limit, 'offset' => $offset] = $request->pagination(50, 100);

        // V65：黑名单成员在圈内「隐身」—— 不进成员列表，圈管页单独一个黑名单面板看
        $rows = CircleRepository::fetchMembers($id, $offset, $limit);
        $total = CircleRepository::countMembers($id);

        // 成员头同样是相对路径，App 端 <image> 加载不了
        MediaHelper::processUsers($rows);

        return Response::success(Helper::paginatedResponse($rows, $page, $limit, $total), '获取成功');
    }

    /**
     * 圈子内动态列表（公开圈子可见，protected/private 仅成员可见）
     * GET /api/circles/posts?id=&page=&limit=
     * 复用 PostRepository::fetchByIds + PostFormatter，确保和首页信息流格式一致
     */
    public function posts(Request $request): Response
    {
        $currentUser = Auth::user($request);
        $userId = $currentUser['id'] ?? 0;

        $id = $request->getInt('id');
        if ($id <= 0) {
            return Response::error('圈子ID必填', 400);
        }

        $circle = CircleRepository::findCircleBasic($id);
        if (!$circle) {
            return Response::error('圈子不存在', 404);
        }

        // V50：protected/private 圈子动态仅成员可见
        $gate = $this->ensureViewable($id, $userId);
        if ($gate !== null) {
            return $gate;
        }

        ['page' => $page, 'limit' => $limit, 'offset' => $offset] = $request->pagination(10, 20);

        // V56：圈内动态同样受可见范围约束（私密动态只有作者本人能看见）
        // 可见性参数已由 CircleRepository 用 ? 绑定，不再拼进 SQL
        $ids = CircleRepository::fetchCirclePostIds($id, $userId, $offset, $limit);

        if (empty($ids)) {
            return Response::success(Helper::paginatedResponse([], $page, $limit, 0), '获取成功');
        }

        $postIds = array_column($ids, 'id');
        $posts = PostRepository::fetchByIds($postIds, $userId);
        [$likesMap, $commentsMap, $blockedSet] = PostRepository::fetchBatchMeta($postIds, $userId);

        $list = [];
        foreach ($posts as $post) {
            $list[] = PostFormatter::formatPostBatch($post, $userId, $likesMap[$post['id']] ?? [], $commentsMap[$post['id']] ?? [], $blockedSet);
        }

        $total = CircleRepository::countCirclePosts($id, $userId);

        return Response::success(Helper::paginatedResponse($list, $page, $limit, $total), '获取成功');
    }

    /**
     * 创建圈子（登录）
     * POST /api/circles {name, description, cover}
     */
    public function create(Request $request): Response
    {
        $currentUser = $this->requireUser($request);

        $name = trim($request->getString('name'));
        $description = trim($request->getString('description'));
        $cover = trim($request->getString('cover'));

        // V50：权限相关字段（带白名单校验，非法值回退默认）
        $joinMode = $this->normalizeJoinMode($request->getString('join_mode'));
        $postPermission = $this->normalizePostPermission($request->getString('post_permission'));
        $visibility = $this->normalizeVisibility($request->getString('visibility'));

        if ($name === '' || mb_strlen($name) > 7) {
            return Response::error('圈子名称必填（最多 7 字）', 400);
        }
        if (mb_strlen($description) > 30) {
            return Response::error('简介最多 30 字', 400);
        }

        // ====== V71：全站禁言 + 圈名/简介敏感词过滤 ======
        try {
            Moderation::assertNotMuted($currentUser);
        } catch (HublogException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 403);
        }
        $fName = Moderation::filter($name, 'circle', (int)$currentUser['id'], 0);
        if (!$fName['ok']) {
            return Response::error($fName['msg'], 403);
        }
        $name = $fName['text'];
        $fDesc = Moderation::filter($description, 'circle', (int)$currentUser['id'], 0);
        if (!$fDesc['ok']) {
            return Response::error($fDesc['msg'], 403);
        }
        $description = $fDesc['text'];

        // V67：可创建圈子上限（普通 1 个 / 会员 5 个），管理员不扣
        if ($currentUser['role'] !== 'admin') {
            PaymentController::syncVip((int)$currentUser['id']);
            $vipRow = CircleRepository::findVipLevel((int)$currentUser['id']);
            $quota = PaymentController::privileges((int)($vipRow['vip_level'] ?? 0))['circle_create'];
            $used = CircleRepository::countOwnedActive((int)$currentUser['id']);
            if ($used >= $quota) {
                return Response::error("已创建 {$used} 个圈子，达到上限（{$quota} 个），开通会员可提升", 402);
            }
        }

        // V56：创建时即可带公告与标签（选填）
        $initAnnouncement = $request->has('announcement') ? trim($request->getString('announcement')) : '';
        $initTags = $request->has('tags')
            ? implode(',', array_slice(array_filter(array_map('trim', explode(',', (string)$request->getString('tags')))), 0, 8))
            : '';
        if (mb_strlen($initAnnouncement) > 1000) {
            return Response::error('公告最多 1000 字', 400);
        }
        if (mb_strlen($initTags) > 255) {
            return Response::error('标签过长', 400);
        }
        $initReview = $request->has('post_review') ? ($request->getBool('post_review') ? 1 : 0) : 0;

        // slug 用 crc32 短哈希 + 时间戳保证唯一（避免拼音依赖）
        $slug = $this->generateUniqueSlug($name);

        // 与迁移前一致：空描述/公告/标签写 NULL；建圈事务（含圈主成员行）整体下沉
        try {
            $circleId = CircleRepository::createCircle(
                $name,
                $slug,
                $description ?: null,
                $cover,
                (int)$currentUser['id'],
                $joinMode,
                $postPermission,
                $visibility,
                $initAnnouncement !== '' ? $initAnnouncement : null,
                $initTags !== '' ? $initTags : null,
                $initReview
            );
        } catch (\Throwable $e) {
            return Response::error('创建失败：' . $e->getMessage(), 500);
        }

        $this->clearCircleListCache();

        // V58 / P1-3：建圈即建群，圈主第一时间进群
        $this->addGroupMember($circleId, (int)$currentUser['id'], 'owner');

        $circle = CircleRepository::findAnyById($circleId);
        $circle['is_member'] = true;
        $circle['is_owner'] = true;
        $circle['my_role'] = 'owner';

        MediaHelper::processCircle($circle);

        return Response::success($circle, '创建成功');
    }

    /**
     * 更新圈子（仅圈主）
     * PUT /api/circles {id, name, description, cover}
     */
    public function update(Request $request): Response
    {
        $currentUser = $this->requireUser($request);

        $id = $request->getInt('id');
        if ($id <= 0) {
            return Response::error('圈子ID必填', 400);
        }

        $circle = CircleRepository::findAnyById($id);
        if (!$circle) {
            return Response::error('圈子不存在', 404);
        }
        // V93：与审批/公告等管理动作一致，圈主或圈子管理员均可编辑资料（前端圈主管理菜单对两者开放）
        $role = $this->fetchMemberRole($id, (int)$currentUser['id']);
        if ($role !== 'owner' && $role !== 'admin') {
            return Response::error('无权限，仅圈主/管理员可编辑', 403);
        }

        $fields = [];
        $params = [];

        // V71：圈名 / 简介 / 公告统一过敏感词（update 场景）
        // V135：名称最多 7 字、简介最多 30 字（与 create 同口径）；超长直接报错，
        // 不再静默跳过字段——否则用户改了名却没保存上，毫无提示
        $name = trim($request->getString('name'));
        if ($name !== '') {
            if (mb_strlen($name) > 7) {
                return Response::error('圈子名称最多 7 字', 400);
            }
            $fName = Moderation::filter($name, 'circle', (int)$currentUser['id'], $id);
            if (!$fName['ok']) {
                return Response::error($fName['msg'], 403);
            }
            $fields[] = 'name = ?';
            $params[] = $fName['text'];
        }

        $description = trim($request->getString('description'));
        if ($description !== '') {
            if (mb_strlen($description) > 30) {
                return Response::error('简介最多 30 字', 400);
            }
            $fDesc = Moderation::filter($description, 'circle', (int)$currentUser['id'], $id);
            if (!$fDesc['ok']) {
                return Response::error($fDesc['msg'], 403);
            }
            $fields[] = 'description = ?';
            $params[] = $fDesc['text'];
        }

        $cover = trim($request->getString('cover'));
        if ($cover !== '') {
            $fields[] = 'cover = ?';
            $params[] = $cover;
        }

        // V56：圈子公告 / 标签 / 发帖审核开关
        $announcement = trim($request->getString('announcement'));
        if ($request->has('announcement')) {
            if (mb_strlen($announcement) > 100) {
                return Response::error('公告最多 100 字', 400);
            }
            $fAnn = Moderation::filter($announcement, 'circle', (int)$currentUser['id'], $id);
            if (!$fAnn['ok']) {
                return Response::error($fAnn['msg'], 403);
            }
            $announcement = $fAnn['text'];
            $fields[] = 'announcement = ?';
            $params[] = $announcement !== '' ? $announcement : null;
            $fields[] = 'announcement_at = ?';
            $params[] = Helper::now();
        }

        if ($request->has('tags')) {
            $tags = $request->getString('tags');
            // 支持两种输入：「标签A,标签B」或 JSON 数组 ["标签A","标签B"]
            $tags = trim($tags);
            if ($tags !== '' && $tags[0] === '[') {
                $decoded = json_decode($tags, true);
                if (is_array($decoded)) {
                    $parts = [];
                    foreach ($decoded as $t) {
                        $t = trim((string)$t);
                        if ($t !== '') {
                            $parts[] = $t;
                        }
                    }
                    $tags = implode(',', array_slice($parts, 0, 8));
                }
            } else {
                $parts = array_slice(array_filter(array_map('trim', explode(',', $tags))), 0, 8);
                $tags = implode(',', $parts);
            }
            if (mb_strlen($tags) > 255) {
                return Response::error('标签过长', 400);
            }
            $fields[] = 'tags = ?';
            $params[] = $tags !== '' ? $tags : null;
        }

        if ($request->has('post_review')) {
            $fields[] = 'post_review = ?';
            $params[] = $request->getBool('post_review') ? 1 : 0;
        }

        // V50：权限相关字段（带白名单校验）
        $joinMode = $this->normalizeJoinMode($request->getString('join_mode'), true);
        if ($joinMode !== null) {
            $fields[] = 'join_mode = ?';
            $params[] = $joinMode;
        }
        $postPermission = $this->normalizePostPermission($request->getString('post_permission'), true);
        if ($postPermission !== null) {
            $fields[] = 'post_permission = ?';
            $params[] = $postPermission;
        }
        $visibility = $this->normalizeVisibility($request->getString('visibility'), true);
        if ($visibility !== null) {
            $fields[] = 'visibility = ?';
            $params[] = $visibility;
        }

        // V67：付费门槛（后台管理员代圈主设置；圈主自己编辑圈子不带这几个字段就不会被改）
        if ($request->has('pay_type')) {
            $payType = max(0, min(2, $request->getInt('pay_type')));
            $fields[] = 'pay_type = ?';
            $params[] = $payType;
            if ($payType === 1) {
                $vipLevel = max(1, min(3, $request->getInt('pay_vip_level') ?: 1));
                $fields[] = 'pay_vip_level = ?';
                $params[] = $vipLevel;
            }
            if ($payType === 2) {
                $amount = round(max(0, (float)$request->getString('pay_amount')), 2);
                $fields[] = 'pay_amount = ?';
                $params[] = $amount;

                // V96：圈主可携带单圈订阅天数，0=跟随全局，上限 3650
                $rawDays = trim($request->getString('pay_days'));
                if ($rawDays !== '' && ctype_digit($rawDays)) {
                    $fields[] = 'pay_days = ?';
                    $params[] = max(0, min(3650, (int)$rawDays));
                }
            }
        }

        if (empty($fields)) {
            return Response::error('没有要更新的字段', 400);
        }

        CircleRepository::updateCircleFields($id, $fields, $params);

        $this->clearCircleListCache();

        $circle = CircleRepository::findAnyById($id);

        // 封面刚更新过，直接把绝对 URL 回给前端，省得页面再拉一次详情
        MediaHelper::processCircle($circle);

        return Response::success($circle, '更新成功');
    }

    /**
     * 删除圈子（仅圈主）
     * DELETE /api/circles?id=
     * 级联：circle_members 全删，posts.circle_id 置 NULL（动态不丢，回到全站流）
     */
    public function delete(Request $request): Response
    {
        $currentUser = $this->requireUser($request);

        $id = $request->getInt('id');
        if ($id <= 0) {
            return Response::error('圈子ID必填', 400);
        }

        $circle = CircleRepository::findAnyById($id);
        if (!$circle) {
            return Response::error('圈子不存在', 404);
        }
        if ((int)$circle['owner_id'] !== (int)$currentUser['id']) {
            return Response::error('无权限，仅圈主可删除', 403);
        }

        // V58 / P1-3：解散圈子时先解散其群会话（含查群、群成员删除整体下沉到仓储事务）
        try {
            CircleRepository::deleteCircleCascade($id);
        } catch (\Throwable $e) {
            return Response::error('删除失败：' . $e->getMessage(), 500);
        }

        $this->clearCircleListCache();

        return Response::success(null, '已删除');
    }

    /**
     * 加入圈子（登录）—— V50 按 join_mode 分流
     * POST /api/circles/join?id=&message=
     *   - free：直接加入（member_count + 1）
     *   - approval：创建 pending 申请记录，等待圈主/管理员审批
     *   - invite：拒绝（仅邀请，需圈主主动邀请）
     */
    public function join(Request $request): Response
    {
        $currentUser = $this->requireUser($request);

        $id = $request->getInt('id');
        if ($id <= 0) {
            return Response::error('圈子ID必填', 400);
        }

        $circle = CircleRepository::findForJoin($id);
        if (!$circle || (int)$circle['status'] !== 1) {
            return Response::error('圈子不存在', 404);
        }

        // V67：付费/门槛圈子先过付费校验，前端会引导去订阅页；
        //      这里不直接放行，否则「付费圈子」的门槛就等于没设。
        [$canJoin, $payReason] = PaymentController::circlePostAllowed($id, $circle, (int)$currentUser['id']);
        if (!$canJoin && (int)$circle['pay_type'] > 0) {
            return Response::error($payReason, 402);
        }

        // V65：先查圈内黑名单，被拉黑的人连申请入口都不给
        $banned = CircleRepository::getMemberStatus($id, (int)$currentUser['id']);
        if ($banned && (int)$banned['status'] === 2) {
            return Response::error('你已被该圈子列入黑名单，无法再加入', 403);
        }

        // 已是成员 → 幂等返回
        $exist = CircleRepository::memberExists($id, (int)$currentUser['id']);
        if ($exist) {
            return Response::success(['already_member' => true], '已加入');
        }

        $joinMode = $circle['join_mode'] ?? 'free';

        // invite 模式：仅邀请加入，用户主动申请直接拒绝
        if ($joinMode === 'invite') {
            return Response::error('该圈子为仅邀请加入，请联系圈主', 403);
        }

        // approval 模式：创建申请记录
        if ($joinMode === 'approval') {
            // 已有 pending 申请 → 幂等返回
            $pending = CircleRepository::findPendingJoinRequest($id, (int)$currentUser['id']);
            if ($pending) {
                return Response::success(['request_status' => 'pending'], '已提交申请，等待审核');
            }

            $message = mb_substr(trim($request->getString('message')), 0, 500);
            try {
                CircleRepository::insertJoinRequest($id, (int)$currentUser['id'], $message);
            } catch (\Throwable $e) {
                // 唯一键撞库 = 同一用户对同圈子已申请过（含历史 approved/rejected），
                // 此时把旧 pending 之外的状态置为 pending 复用最新一条
                return Response::error('申请失败：' . $e->getMessage(), 500);
            }

            return Response::success(['request_status' => 'pending'], '申请已提交，等待审核');
        }

        // free 模式：直接加入
        CircleRepository::joinFree($id, (int)$currentUser['id']);

        $this->clearCircleListCache();

        // V58 / P1-3：入圈即入群
        $this->addGroupMember((int)$id, (int)$currentUser['id'], 'member');

        return Response::success(['member_count' => (int)$circle['member_count'] + 1], '已加入');
    }

    /**
     * 我提交的加入申请列表（登录）
     * GET /api/circles/my-requests
     */
    public function myJoinRequests(Request $request): Response
    {
        $currentUser = $this->requireUser($request);

        $rows = CircleRepository::fetchMyJoinRequests((int)$currentUser['id']);

        // 列表带圈子封面（circle_cover 别名），同样补绝对 URL
        MediaHelper::processCircles($rows);

        return Response::success(['list' => $rows], '获取成功');
    }

    /**
     * 圈子待审申请列表（仅圈主/管理员）
     * GET /api/circles/pending-requests?id=&page=&limit=
     */
    public function pendingRequests(Request $request): Response
    {
        $currentUser = $this->requireUser($request);

        $id = $request->getInt('id');
        if ($id <= 0) {
            return Response::error('圈子ID必填', 400);
        }

        $role = $this->fetchMemberRole($id, (int)$currentUser['id']);
        if ($role === null) {
            return Response::error('非圈子成员', 403);
        }
        if ($role !== 'owner' && $role !== 'admin') {
            return Response::error('无权限，仅圈主/管理员可查看申请', 403);
        }

        ['page' => $page, 'limit' => $limit, 'offset' => $offset] = $request->pagination(20, 50);

        $rows = CircleRepository::fetchPendingRequests($id, $offset, $limit);
        $total = CircleRepository::countPendingRequests($id);

        // 申请人头像补绝对 URL
        MediaHelper::processUsers($rows);

        return Response::success(Helper::paginatedResponse($rows, $page, $limit, $total), '获取成功');
    }

    /**
     * 审批通过加入申请（圈主/管理员）
     * POST /api/circles/approve?id=&user_id=
     * 通过后：写 circle_members role=member + member_count+1 + 申请记录 status=approved
     */
    public function approveRequest(Request $request): Response
    {
        $currentUser = $this->requireUser($request);

        $id = $request->getInt('id');
        $applyUserId = $request->getInt('user_id');
        if ($id <= 0 || $applyUserId <= 0) {
            return Response::error('圈子ID和申请用户ID必填', 400);
        }

        $role = $this->fetchMemberRole($id, (int)$currentUser['id']);
        if ($role !== 'owner' && $role !== 'admin') {
            return Response::error('无权限，仅圈主/管理员可审批', 403);
        }

        // 取最近一条 pending 申请
        $apply = CircleRepository::findPendingJoinRequest($id, $applyUserId);
        if (!$apply) {
            return Response::error('申请不存在或已处理', 404);
        }

        // 已是成员（幂等保护）
        $exist = CircleRepository::memberExists($id, $applyUserId);
        if ($exist) {
            // 把申请标记为 approved
            CircleRepository::markRequestApproved((int)$currentUser['id'], (int)$apply['id']);
            return Response::success(null, '该用户已是成员');
        }

        try {
            CircleRepository::approveAndAddMember(
                $id,
                $applyUserId,
                (int)$currentUser['id'],
                (int)$apply['id']
            );
        } catch (\Throwable $e) {
            return Response::error('审批失败：' . $e->getMessage(), 500);
        }

        $this->clearCircleListCache();

        return Response::success(null, '已通过');
    }

    /**
     * 拒绝加入申请（圈主/管理员）
     * POST /api/circles/reject?id=&user_id=
     */
    public function rejectRequest(Request $request): Response
    {
        $currentUser = $this->requireUser($request);

        $id = $request->getInt('id');
        $applyUserId = $request->getInt('user_id');
        if ($id <= 0 || $applyUserId <= 0) {
            return Response::error('圈子ID和申请用户ID必填', 400);
        }

        $role = $this->fetchMemberRole($id, (int)$currentUser['id']);
        if ($role !== 'owner' && $role !== 'admin') {
            return Response::error('无权限，仅圈主/管理员可审批', 403);
        }

        $apply = CircleRepository::findPendingRequestId($id, $applyUserId);
        if (!$apply) {
            return Response::error('申请不存在或已处理', 404);
        }

        CircleRepository::markRequestRejected((int)$currentUser['id'], (int)$apply['id']);

        return Response::success(null, '已拒绝');
    }

    /**
     * V56：设置圈子公告（圈主/管理员，发布后立即对全体成员可见）
     * POST /api/circles/announce {id, content}
     */
    public function setAnnouncement(Request $request): Response
    {
        $currentUser = $this->requireUser($request);

        $id = $request->getInt('id');
        if ($id <= 0) {
            return Response::error('圈子ID必填', 400);
        }
        $role = $this->fetchMemberRole($id, (int)$currentUser['id']);
        if ($role !== 'owner' && $role !== 'admin') {
            return Response::error('无权限，仅圈主/管理员可发公告', 403);
        }

        $content = trim($request->getString('content'));
        if (mb_strlen($content) > 1000) {
            return Response::error('公告最多 1000 字', 400);
        }
        // V71：公告过敏感词
        try {
            Moderation::assertNotMuted($currentUser);
        } catch (HublogException $e) {
            return Response::error($e->getMessage(), $e->getCode() ?: 403);
        }
        $fAnn = Moderation::filter($content, 'circle', (int)$currentUser['id'], $id);
        if (!$fAnn['ok']) {
            return Response::error($fAnn['msg'], 403);
        }
        $content = $fAnn['text'];

        CircleRepository::updateAnnouncement($id, $content !== '' ? $content : null, Helper::now());
        $this->clearCircleListCache();

        return Response::success(
            ['announcement' => $content !== '' ? $content : null, 'announcement_at' => Helper::now()],
            $content !== '' ? '公告已发布' : '公告已清空'
        );
    }

    /**
     * V56：移除圈内成员（圈主/管理员）
     * POST /api/circles/manage-member {id, user_id, action: remove}
     */
    public function manageMember(Request $request): Response
    {
        $currentUser = $this->requireUser($request);

        $id = $request->getInt('id');
        $targetId = $request->getInt('user_id');
        $action = $request->getString('action');
        if ($id <= 0 || $targetId <= 0) {
            return Response::error('参数错误', 400);
        }

        $circle = CircleRepository::findCircleOwner($id);
        if (!$circle) {
            return Response::error('圈子不存在', 404);
        }
        $role = $this->fetchMemberRole($id, (int)$currentUser['id']);
        $isOwner = (int)$circle['owner_id'] === (int)$currentUser['id'];
        $isPlatformAdmin = ($currentUser['role'] ?? '') === 'admin';
        if (!$isOwner && !$isPlatformAdmin && $role !== 'admin') {
            return Response::error('无权限', 403);
        }

        $target = CircleRepository::findTargetMemberRole($id, $targetId);
        if (!$target) {
            return Response::error('该用户不是本圈成员', 404);
        }

        if ($action === 'remove') {
            // 不能移除圈主自己
            if ((int)$circle['owner_id'] === $targetId) {
                return Response::error('不能移除圈主', 400);
            }
            try {
                CircleRepository::removeMember($id, $targetId);
            } catch (\Throwable $e) {
                return Response::error('操作失败：' . $e->getMessage(), 500);
            }
            $this->clearCircleListCache();
            return Response::success(null, '已移出圈子');
        }

        if ($action === 'set-admin' || $action === 'set-member') {
            $newRole = $action === 'set-admin' ? 'admin' : 'member';
            CircleRepository::setMemberRole($id, $targetId, $newRole);
            // V82：圈内角色变更 → 徽章实时校验（圈主/圈子管理员）
            BadgeService::onEvent($targetId, 'circle_role_changed');
            return Response::success(null, $newRole === 'admin' ? '已设为圈管' : '已降级为普通成员');
        }

        return Response::error('不支持的操作', 400);
    }

    /**
     * V56：圈内禁言 / 解禁（圈主/管理员）
     * POST /api/circles/mute-member {id, user_id, minutes}
     * minutes=0 表示解除禁言
     */
    public function muteMember(Request $request): Response
    {
        $currentUser = $this->requireUser($request);

        $id = $request->getInt('id');
        $targetId = $request->getInt('user_id');
        $minutes = max(0, $request->getInt('minutes'));
        if ($id <= 0 || $targetId <= 0) {
            return Response::error('参数错误', 400);
        }

        $circle = CircleRepository::findCircleOwner($id);
        if (!$circle) {
            return Response::error('圈子不存在', 404);
        }
        $role = $this->fetchMemberRole($id, (int)$currentUser['id']);
        $isOwner = (int)$circle['owner_id'] === (int)$currentUser['id'];
        $isPlatformAdmin = ($currentUser['role'] ?? '') === 'admin';
        if (!$isOwner && !$isPlatformAdmin && $role !== 'admin') {
            return Response::error('无权限', 403);
        }
        if ((int)$circle['owner_id'] === $targetId) {
            return Response::error('不能禁言圈主', 400);
        }
        $target = CircleRepository::findTargetMemberId($id, $targetId);
        if (!$target) {
            return Response::error('该用户不是本圈成员', 404);
        }

        $until = $minutes > 0
            ? date('Y-m-d H:i:s', time() + $minutes * 60)
            : null;

        CircleRepository::setMutedUntil($until, $id, $targetId);

        return Response::success(
            ['user_id' => $targetId, 'muted_until' => $until],
            $minutes > 0 ? "已禁言 {$minutes} 分钟" : '已解除禁言'
        );
    }

    /**
     * V65：把人拉进圈子永久黑名单（禁言只是临时挡一下，圈主治理缺永久手段）
     * POST /api/circles/ban-member {id, user_id}
     *
     * 拉黑后：不出现在成员列表、不能发言/发帖/转发到本圈、点「加入」会被拒、
     * 本圈发的 circle 可见动态对他不可见。解黑名单走 unban-member（保留加入时间记录）。
     */
    public function banMember(Request $request): Response
    {
        return $this->setMemberBanned($request, 2);
    }

    /** V65：解除圈内黑名单 POST /api/circles/unban-member {id, user_id} */
    public function unbanMember(Request $request): Response
    {
        return $this->setMemberBanned($request, 1);
    }

    /** V65：圈内黑名单名单 GET /api/circles/blacklist?id=（仅圈主/平台管理员可见） */
    public function blacklist(Request $request): Response
    {
        $currentUser = $this->requireUser($request);

        $id = $request->getInt('id');
        if ($id <= 0) {
            return Response::error('参数错误', 400);
        }

        $circle = CircleRepository::findCircleOwner($id);
        if (!$circle) {
            return Response::error('圈子不存在', 404);
        }
        $role = $this->fetchMemberRole($id, (int)$currentUser['id']);
        $isOwner = (int)$circle['owner_id'] === (int)$currentUser['id'];
        $isPlatformAdmin = ($currentUser['role'] ?? '') === 'admin';
        if (!$isOwner && !$isPlatformAdmin && $role !== 'admin') {
            return Response::error('无权限', 403);
        }

        $rows = CircleRepository::fetchBlacklist($id);

        // 黑名单头像同样补绝对 URL
        MediaHelper::processUsers($rows);

        return Response::success($rows);
    }

    /**
     * 拉黑 / 解黑的公共实现
     *
     * 权限判定与 muteMember 完全一致（圈主本人、平台管理员、圈内 admin）；
     * 另外禁掉「把圈主自己和圈内 admin 拉黑」—— 后者会导致圈内没人能管。
     */
    private function setMemberBanned(Request $request, int $status): Response
    {
        $currentUser = $this->requireUser($request);

        $id = $request->getInt('id');
        $targetId = $request->getInt('user_id');
        if ($id <= 0 || $targetId <= 0) {
            return Response::error('参数错误', 400);
        }

        $circle = CircleRepository::findCircleOwner($id);
        if (!$circle) {
            return Response::error('圈子不存在', 404);
        }
        $role = $this->fetchMemberRole($id, (int)$currentUser['id']);
        $isOwner = (int)$circle['owner_id'] === (int)$currentUser['id'];
        $isPlatformAdmin = ($currentUser['role'] ?? '') === 'admin';
        if (!$isOwner && !$isPlatformAdmin && $role !== 'admin') {
            return Response::error('无权限', 403);
        }

        $target = CircleRepository::findTargetMemberRole($id, $targetId);
        if (!$target) {
            return Response::error('该用户不是本圈成员', 404);
        }
        if ((int)$circle['owner_id'] === $targetId) {
            return Response::error('不能拉黑圈主', 400);
        }
        if ($target['role'] === 'admin' && $status === 2 && !$isOwner && !$isPlatformAdmin) {
            return Response::error('不能拉黑圈内管理员', 400);
        }

        CircleRepository::setMemberBanned($status, $id, $targetId);

        return Response::success(
            ['user_id' => $targetId, 'status' => $status],
            $status === 2 ? '已拉入黑名单' : '已解除黑名单'
        );
    }

    /**
     * V56：圈内内容管理 —— 置顶 / 取消置顶 / 精华 / 取消精华 / 下沉 / 取消下沉
     * POST /api/circles/manage-post {id, post_id, action}
     */
    public function managePost(Request $request): Response
    {
        $currentUser = $this->requireUser($request);

        $id = $request->getInt('id');
        $postId = $request->getInt('post_id');
        $action = $request->getString('action');
        if ($id <= 0 || $postId <= 0) {
            return Response::error('参数错误', 400);
        }

        $circle = CircleRepository::findCircleOwner($id);
        if (!$circle) {
            return Response::error('圈子不存在', 404);
        }
        $role = $this->fetchMemberRole($id, (int)$currentUser['id']);
        $isOwner = (int)$circle['owner_id'] === (int)$currentUser['id'];
        $isPlatformAdmin = ($currentUser['role'] ?? '') === 'admin';
        if (!$isOwner && !$isPlatformAdmin && $role !== 'admin') {
            return Response::error('无权限', 403);
        }

        $post = CircleRepository::findPostInCircle($postId, $id);
        if (!$post) {
            return Response::error('动态不存在', 404);
        }

        $map = [
            'pin'        => ['is_pinned' => 1, 'pinned_at' => Helper::now()],
            'unpin'      => ['is_pinned' => 0, 'pinned_at' => null],
            'feature'    => ['is_featured' => 1],
            'unfeature'  => ['is_featured' => 0],
            // 下沉：给一个明显负权重，列表按 weight DESC 排序时自然沉底
            'sink'       => ['weight' => -100],
            'unsink'     => ['weight' => 0],
        ];
        if (!isset($map[$action])) {
            return Response::error('不支持的操作', 400);
        }

        CircleRepository::updatePost($postId, $map[$action]);
        Cache::delete("post_{$postId}");
        $this->clearCircleListCache();
        // P0-11：timeline 命名空间升版。原 clearCache('timeline_v3_') 是早已失效的
        // 前缀（现役键为 timeline_v4_*）；timeline_total_* 同属该命名空间
        Cache::bumpNsVersion('timeline');

        return Response::success(null, '已更新');
    }

    /**
     * 退出圈子（登录）
     * POST /api/circles/leave?id=
     * 圈主不能退出，需先转让或删除圈子
     */
    public function leave(Request $request): Response
    {
        $currentUser = $this->requireUser($request);

        $id = $request->getInt('id');
        if ($id <= 0) {
            return Response::error('圈子ID必填', 400);
        }

        $role = CircleRepository::getMemberRole($id, (int)$currentUser['id']);
        if ($role === null) {
            return Response::success(['already_left' => true], '已退出');
        }
        if ($role === 'owner') {
            return Response::error('圈主不能退出，请先转让或删除圈子', 400);
        }

        CircleRepository::leaveCircle($id, (int)$currentUser['id']);

        // V58 / P1-3：退圈即退群（群里已没人则连带解散会话）
        $this->removeGroupMember((int)$id, (int)$currentUser['id']);

        $this->clearCircleListCache();

        return Response::success(null, '已退出');
    }

    // ===== V58 / P1-3：圈子群聊联动 =====

    /**
     * 进入圈子群聊（POST /api/circles/group {id}）
     * 老圈子没有群会话时这里补建；已入群则幂等。返回 conversation_id 供前端跳聊天页。
     */
    public function joinGroup(Request $request): Response
    {
        $currentUser = $this->requireUser($request);
        $id = $request->getInt('id');
        if ($id <= 0) {
            return Response::error('圈子ID必填', 400);
        }
        $circle = CircleRepository::findCircleWithStatus($id);
        if (!$circle || (int)$circle['status'] !== 1) {
            return Response::error('圈子不存在', 404);
        }

        $uid = (int)$currentUser['id'];
        // P0：付费圈与会员门槛必须在建群/入群之前判定，否则非订阅用户可直接绕过进群
        [$canJoin, $payReason] = PaymentController::circlePostAllowed($id, $circle, $uid);
        if (!$canJoin && (int)($circle['pay_type'] ?? 0) > 0) {
            return Response::error($payReason, 402);
        }

        // V65：圈内黑名单不能进群
        $member = CircleRepository::getMemberStatus($id, $uid);
        if ($member && (int)$member['status'] === 2) {
            return Response::error('你已被该圈子列入黑名单，无法进群', 403);
        }

        // P0：群聊是圈内成员权益，非有效成员不得凭任意 circle_id 直接进群
        if (!$member || (int)$member['status'] !== 1) {
            $mode = $circle['join_mode'] ?? 'free';
            if ($mode === 'invite') {
                return Response::error('该圈子仅允许受邀成员入群', 403);
            }
            if ($mode === 'approval') {
                return Response::error('请先申请加入圈子并等待审核', 403);
            }
            return Response::error('请先加入圈子', 403);
        }

        $groupId = CircleRepository::ensureCircleGroup($id, (string)($circle['name'] ?? ''));
        // V89：把已解析的 groupId 传进去，防止 addGroupMember 内部再次 ensure 建出第二个群
        $this->addGroupMember($id, $uid, 'member', $groupId);

        return Response::success([
            'conversation_id' => $groupId,
            'circle_id'       => (int)$id,
            'title'           => $circle['name'],
            'member_count'    => CircleRepository::countConversationMembers($groupId)
        ], '已进入群聊');
    }

    /**
     * 入群：已存在则忽略（或升级角色），并同步 member_count。
     * $groupId 由调用方预先解析好时传入，避免 joinGroup 里二次建群竞态。
     */
    private function addGroupMember(int $circleId, int $userId, string $role, int $groupId = 0): void
    {
        if ($circleId <= 0 || $userId <= 0) {
            return;
        }
        if ($groupId <= 0) {
            $circle = CircleRepository::findCircleName($circleId);
            $groupId = CircleRepository::ensureCircleGroup($circleId, $circle ? $circle['name'] : '圈子群聊');
        }

        $exists = CircleRepository::findConversationMemberId($groupId, $userId);
        if ($exists) {
            // 幂等入群：已存在成员行时不做任何改动，避免 member 角色反向降级群主/管理员
            return;
        }

        // P1-1：INSERT IGNORE + rowCount 判定，防并发入群撞 uk_conv_user 唯一键 500；
        // 只有真正插入新成员行时才累加 member_count（原子自增）
        if (CircleRepository::insertGroupMemberIgnore($groupId, $userId, $role) > 0) {
            CircleRepository::incrementConversationMemberCount($groupId);
        }
    }

    /**
     * 退群：群里没人了就顺带解散会话，避免留下永久空群
     */
    private function removeGroupMember(int $circleId, int $userId): void
    {
        if ($circleId <= 0 || $userId <= 0) {
            return;
        }
        $group = CircleRepository::findGroupIdByCircle($circleId);
        if (!$group) {
            return;
        }
        $groupId = (int)$group['id'];

        CircleRepository::deleteConversationMember($groupId, $userId);

        $left = CircleRepository::countConversationMembers($groupId);
        if ($left <= 0) {
            CircleRepository::deleteConversation($groupId);
        } else {
            CircleRepository::setConversationMemberCount($groupId, $left);
        }
    }

    // ===== 内部辅助 =====

    /**
     * V50：规范化 join_mode
     * @param string $value 入参
     * @param bool $forUpdate 是否为 update 场景（true=空串返回 null 不更新；false=空串返回默认 free）
     */
    private function normalizeJoinMode(string $value, bool $forUpdate = false): ?string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return $forUpdate ? null : 'free';
        }
        if (in_array($value, ['free', 'approval', 'invite'], true)) {
            return $value;
        }
        return $forUpdate ? null : 'free';
    }

    /**
     * V50：规范化 post_permission
     */
    private function normalizePostPermission(string $value, bool $forUpdate = false): ?string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return $forUpdate ? null : 'all';
        }
        if (in_array($value, ['all', 'admin', 'owner'], true)) {
            return $value;
        }
        return $forUpdate ? null : 'all';
    }

    /**
     * V50：规范化 visibility
     */
    private function normalizeVisibility(string $value, bool $forUpdate = false): ?string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return $forUpdate ? null : 'public';
        }
        if (in_array($value, ['public', 'protected', 'private'], true)) {
            return $value;
        }
        return $forUpdate ? null : 'public';
    }

    /**
     * V50：可见性门禁
     * public → 任何人可访问
     * protected → 非成员返回 403
     * private → 非成员返回 404（不暴露存在）
     * @return Response|null 非 null 表示被拦截，应直接 return
     */
    private function ensureViewable(int $circleId, int $userId): ?Response
    {
        $circle = CircleRepository::findForGate($circleId);
        if (!$circle) {
            return Response::error('圈子不存在', 404);
        }

        // V96：付费门禁必须前置于 visibility 判断，否则 visibility=public 的付费圈
        // 会被直接放行，订阅过期用户仍能读帖子列表 / 成员列表。
        if ((int)($circle['pay_type'] ?? 0) === 2
            && !CircleSubscriptionService::canAccessCircleContent($userId, $circleId, $circle)) {
            return ($circle['visibility'] ?? 'public') === 'private'
                ? Response::error('圈子不存在', 404)
                : Response::error('订阅已到期', 403);
        }

        $visibility = $circle['visibility'] ?? 'public';
        if ($visibility === 'public') {
            return null;
        }
        // protected/private 圈子需要校验成员身份
        if ($userId <= 0) {
            return $visibility === 'private'
                ? Response::error('圈子不存在', 404)
                : Response::error('加入圈子后可见', 403);
        }
        $row = CircleRepository::getMemberRole($circleId, $userId);
        if ($row === null) {
            return $visibility === 'private'
                ? Response::error('圈子不存在', 404)
                : Response::error('加入圈子后可见', 403);
        }
        return null;
    }

    /**
     * 取当前用户在指定圈子的 role（非成员返回 null）
     */
    private function fetchMemberRole(int $circleId, int $userId): ?string
    {
        return CircleRepository::getMemberRole($circleId, $userId);
    }

    /**
     * 批量取当前用户已加入的圈子ID集合（用于列表补 is_member）
     */
    private function fetchJoinedSet(int $userId, array $circleIds): array
    {
        return CircleRepository::fetchJoinedSet($userId, $circleIds);
    }

    /**
     * 生成唯一 slug：name 转 ASCII 失败时用 crc32+时间戳短哈希兜底
     */
    private function generateUniqueSlug(string $name): string
    {
        $base = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)preg_replace('/\s+/', '-', $name));
        if ($base === '') {
            // 中文名等非 ASCII → 用 crc32 + 时间戳后 6 位
            $base = 'c' . dechex(crc32($name . microtime(true)));
        }
        // 截断 + 加 6 位随机后缀防撞
        $base = substr($base, 0, 30);
        $suffix = substr(bin2hex(random_bytes(3)), 0, 6);
        $slug = $base . '-' . $suffix;

        // 极端情况撞 slug 再加后缀（最多重试 5 次）
        $tries = 0;
        while (CircleRepository::slugExists($slug)) {
            $suffix = substr(bin2hex(random_bytes(3)), 0, 6);
            $slug = $base . '-' . $suffix;
            if (++$tries > 5) break;
        }
        return $slug;
    }

    /**
     * 圈子列表有变更（创建/删除/加入/退出）时清掉相关缓存
     */
    private function clearCircleListCache(): void
    {
        // Cache 类暂未提供按前缀清除，这里清几个常用 key 即可；
        // 时间戳 key 用前缀扫描方式（如 Cache 提供 flushByPrefix）
        $keys = [
            'circles_list_1_20_' . md5(''),
            'circles_list_1_40_' . md5(''),
        ];
        foreach ($keys as $k) {
            Cache::delete($k);
        }
    }
}
