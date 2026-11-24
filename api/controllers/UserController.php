<?php
/**
 * Hublog 用户控制器
 */

namespace Hublog\Controllers;

use Hublog\Core\BaseController;
use Hublog\Core\Request;
use Hublog\Core\Response;
use Hublog\Core\Database;
use Hublog\Core\Auth;
use Hublog\Core\Security;
use Hublog\Core\Cache;
use Hublog\Core\Upload;
use Hublog\Core\PostRepository;
use Hublog\Core\UserRepository;
use Hublog\Core\BadgeService;
use Hublog\Core\PostFormatter;
use Hublog\Core\Helper;
use Hublog\Core\PushService;
use Hublog\Core\PhoneCodeService;
use Hublog\Core\AdminTargetGuard;

class UserController extends BaseController
{
    public function list(Request $request): Response
    {
        Auth::admin($request);
        $keyword = $request->getString('keyword');

        ['page' => $page, 'limit' => $limit, 'offset' => $offset] = $request->pagination();
        $rows = UserRepository::fetchAdminUserPage($keyword, $offset, $limit);
        $total = UserRepository::countAdminUsers($keyword);
        // V71：后台用户列表要显示「禁言中」并给解禁入口，所以带上 muted_until
        $users = array_map(function ($user) {
            $user['email'] = Security::maskEmail($user['email']);
            return $user;
        }, $rows);

        return Response::success(Helper::paginatedResponse($users, $page, $limit, $total));
    }

    /**
     * 昵称是否可用（编辑资料时实时查重，避免用户填完提交才被 409 打回）
     * GET /api/user/check-nickname?nickname=xxx
     * 返回 { available: bool, reason: '' }；自己的当前昵称视为可用
     */
    public function checkNickname(Request $request): Response
    {
        // P2-12：查重接口是低成本枚举入口，按 IP 限流（每小时 60 次）
        if (!Security::rateLimit($request->getIp(), 'nickname_check_ip', null, 60, 3600)) {
            return Response::error('操作过于频繁，请稍后再试', 429);
        }
        $nickname = trim($request->getString('nickname'));
        if ($nickname === '') {
            return Response::error('昵称不能为空', 400);
        }

        $currentUser = Auth::user($request);
        $selfId = (int)($currentUser['id'] ?? 0);

        // 与 updateProfile 保持同一套规则：先过禁用词，再查重
        $forbiddenRaw = (string)(UserRepository::fetchSetting('forbidden_nicknames') ?? '');
        if ($forbiddenRaw !== '') {
            $nicknameLower = mb_strtolower($nickname, 'UTF-8');
            $forbidden = array_filter(array_map(
                function ($v) { return mb_strtolower(trim($v), 'UTF-8'); },
                explode(',', $forbiddenRaw)
            ));
            foreach ($forbidden as $word) {
                if ($word !== '' && strpos($nicknameLower, $word) !== false) {
                    return Response::success(['available' => false, 'reason' => '昵称包含禁用词汇']);
                }
            }
        }

        $exists = UserRepository::nicknameExistsElsewhere($nickname, $selfId);

        return Response::success([
            'available' => !$exists,
            'reason'    => $exists ? '该昵称已被其他用户使用' : ''
        ]);
    }

    /**
     * 搜索用户（用于@提及）
     */
    public function search(Request $request): Response
    {
        $keyword = trim($request->getString('keyword'));
        if (empty($keyword) || mb_strlen($keyword) < 1) {
            return Response::success([]);
        }

        $limit = min(20, max(5, (int)$request->getInt('limit', 10)));
        $currentUser = Auth::user($request);
        $currentUserId = $currentUser['id'] ?? 0;

        // V58：allow_search=0 的用户不出现在 @提及/搜索候选里
        // V83：补 realname_verified（前端昵称旁徽章要用）+ badges（徽章系统统一下发）
        $users = UserRepository::searchUsers((int)$currentUserId, $keyword, $limit);
        BadgeService::decorateUsersWithInlineBadges($users);

        return Response::success($users);
    }

    public function profile(Request $request): Response
    {
        $username = $request->getString('username');
        if (empty($username)) {
            return Response::error('用户名不能为空', 400);
        }

        $cacheKey = "user_profile_{$username}";
        $profile = Cache::remember($cacheKey, function () use ($username) {
            // V54：已注销账号（deleted_at 非空）主页仍可访问，TA 发布的动态/评论保留；
            //      被封禁账号（status=0 且 deleted_at 为空）依旧 404
            $user = UserRepository::findProfileByUsername($username);
            if (!$user) return null;

            $deleted = !empty($user['deleted_at']);

            $postCount = UserRepository::countUserPosts((int)$user['id']);
            // 用户自己的行为数：我给别人点了多少赞、我写了多少评论
            $myLikeCount = UserRepository::countLikesByUser((int)$user['id']);
            $myCommentCount = UserRepository::countCommentsByUser((int)$user['id']);
            // 获评数：别人对 TA 的动态点赞 + 别人对 TA 的动态评论 + 别人对 TA 的评论回复
            $likesReceived = UserRepository::countLikesReceived((int)$user['id']);
            $commentsReceived = UserRepository::countCommentsReceived((int)$user['id']);
            // V69：关注 / 粉丝数兜底。
            // 主页上这两个数字主要走 GET /api/follow/stats，但那次请求是「额外一次请求」，
            // 一旦失败（网络抖动、前端改动回归），界面就会塌成 0（明明有关注却显示 0）。
            // 这里在 profile 里一并给出，前端即便 stats 接口挂了也有正确值可显示。
            // long/short 两种命名都给，与 followStats() 保持一致（前端两种写法都认）。
            $followingCount = UserRepository::countFollowing((int)$user['id']);
            $followersCount = UserRepository::countFollowers((int)$user['id']);

            return [
                'user' => [
                    'id'                => (int)$user['id'],
                    'username'          => $user['username'],
                    // 已注销账号一律显示「已注销用户」，不再暴露任何原个人信息
                    'nickname'          => $deleted ? '已注销用户' : $user['nickname'],
                    'avatar'            => $user['avatar'],
                    'cover'             => $deleted ? '' : $user['cover'],
                    'bio'               => $deleted ? '' : $user['bio'],
                    // V99：个人标签（逗号分隔文本）随公开主页返回，供个人主页展示
                    'user_tags'         => $deleted ? '' : ($user['user_tags'] ?? ''),
                    'created_at'        => $deleted ? null : $user['created_at'],
                    'realname_verified' => $deleted ? 0 : (int)$user['realname_verified'],
                    'vip_level'         => $deleted ? 0 : (int)($user['vip_level'] ?? 0),
                    // 「我的」页会拿这个 user 覆盖登录态，缺了它会员卡就会显示「长期有效」
                    'vip_expire_at'     => $deleted ? null : ($user['vip_expire_at'] ?? null),
                    'avatar_frame'      => $deleted ? 'none' : ($user['avatar_frame'] ?? 'none'),
                    'deleted'           => $deleted,
                    'deleted_at'        => $user['deleted_at']
                ],
                'stats' => [
                    'posts' => (int)$postCount,
                    'likes' => (int)$myLikeCount,           // 我给别人点过多少赞
                    'comments' => (int)$myCommentCount,     // 我写过多少评论
                    'likes_received' => (int)$likesReceived, // 别人给我点过多少赞（获评）
                    'comments_received' => (int)$commentsReceived, // 别人给我留过多少评论（获评）
                    // V69：关注 / 粉丝兜底（follow/stats 失败时界面不至于显示 0）
                    'following_count' => $followingCount,
                    'followers_count' => $followersCount,
                    'following'       => $followingCount,
                    'followers'       => $followersCount
                ]
            ];
        }, 300);

        if (!$profile) {
            return Response::error('用户不存在', 404);
        }

        // ====== V58：主页可见范围 ======
        // 缓存里不能带"是否可见"的判断（否则 A 看过之后 B 也读同一份缓存），
        // 所以隐私开关单独实时查一次，可见性判断放在缓存之后。
        $currentUser = Auth::user($request);
        $currentUserId = (int)($currentUser['id'] ?? 0);
        $targetId = (int)$profile['user']['id'];
        $isSelf = $currentUserId === $targetId;

        $priv = UserRepository::findPrivacyState($targetId);
        // 在线状态只对本人/平台侧暴露；hide_online 的用户一律视为离线
        $online = !empty($priv['last_active_at']) && (time() - strtotime($priv['last_active_at'])) < 300;
        $online = $online && empty($priv['hide_online']);
        $profile['user']['online'] = $isSelf ? true : $online;
        $profile['user']['last_active_at'] = $isSelf || !$online ? null : $priv['last_active_at'];

        // V82：徽章列表实时查询（故意不放进上面的 300s 缓存：徽章解锁/冻结后主页要尽快反映）。
        // 已注销账号不展示任何徽章（与原个人信息一并匿名化）。
        if (!empty($profile['user']['deleted'])) {
            $profile['user']['badges'] = [];
        } else {
            BadgeService::decorateUser($profile['user']);
        }

        // R5：复用统一可见性门禁（口径与原逻辑一致：本人/admin/public 放行，followers 需关注，private 拒绝）
        $isAdmin = ($currentUser['role'] ?? '') === 'admin';
        if (!UserRepository::isProfileVisibleTo($targetId, $currentUserId, $isAdmin)) {
            return Response::error('用户不存在', 404);
        }

        return Response::success($profile);
    }

    /**
     * 用户发表的评论列表
     */
    public function comments(Request $request): Response
    {
        $username = $request->getString('username');
        if (empty($username)) {
            return Response::error('用户名不能为空', 400);
        }

        $user = UserRepository::findActiveUserIdByUsername($username);
        if (!$user) {
            return Response::error('用户不存在', 404);
        }

        // 当前登录用户（必须先算出来：fetchByIds() 的第二个参数要求 int）
        $currentUser = Auth::user($request);
        $currentUserId = (int)($currentUser['id'] ?? 0);
        $isAdmin = ($currentUser['role'] ?? '') === 'admin';
        // R5：评论列表与主页同一可见性门禁；不可见返回与 profile() 一致的 404
        if (!UserRepository::isProfileVisibleTo((int)$user['id'], $currentUserId, $isAdmin)) {
            return Response::error('用户不存在', 404);
        }

        $page = max(1, $request->getInt('page', 1));
        $limit = min(50, max(1, $request->getInt('limit', 20)));
        $offset = ($page - 1) * $limit;

        $total = UserRepository::countUserCommentsAll((int)$user['id']);

        // 1. 获取评论列表（含 post_id）
        $rows = UserRepository::fetchUserComments((int)$user['id'], $limit, $offset);

        $postIds = array_unique(array_column($rows, 'post_id'));
        $list = [];

        if (!empty($postIds)) {
            // 2. 批量获取动态详情
            $posts = PostRepository::fetchByIds($postIds, $currentUserId);
            $postsMap = [];
            foreach ($posts as $p) {
                $postsMap[$p['id']] = $p;
            }
            // 3. 批量获取点赞/评论元数据
            [$likesMap, $commentsMap, $blockedSet] = PostRepository::fetchBatchMeta($postIds);

            // 4. 组装：每条评论 = 评论信息 + 完整动态数据
            foreach ($rows as $row) {
                $pid = $row['post_id'];
                if (isset($postsMap[$pid])) {
                    $post = PostFormatter::formatPostBatch($postsMap[$pid], $currentUserId, $likesMap[$pid] ?? [], $commentsMap[$pid] ?? [], $blockedSet);
                    $list[] = [
                        'comment' => [
                            'id' => $row['id'],
                            'content' => $row['content'],
                            'created_at' => $row['created_at'],
                        ],
                        'post' => $post,
                    ];
                }
            }
        }

        return Response::success([
            'list' => $list,
            'pagination' => ['page' => $page, 'limit' => $limit, 'total' => $total]
        ]);
    }

    /**
     * 用户点赞过的动态列表
     */
    public function likes(Request $request): Response
    {
        $username = $request->getString('username');
        if (empty($username)) {
            return Response::error('用户名不能为空', 400);
        }

        $user = UserRepository::findActiveUserIdByUsername($username);
        if (!$user) {
            return Response::error('用户不存在', 404);
        }

        $currentUser = Auth::user($request);
        $currentUserId = (int)($currentUser['id'] ?? 0);
        $isAdmin = ($currentUser['role'] ?? '') === 'admin';
        // R5：点赞列表与主页同一可见性门禁
        if (!UserRepository::isProfileVisibleTo((int)$user['id'], $currentUserId, $isAdmin)) {
            return Response::error('用户不存在', 404);
        }

        $page = max(1, $request->getInt('page', 1));
        $limit = min(50, max(1, $request->getInt('limit', 20)));
        $offset = ($page - 1) * $limit;

        $total = UserRepository::countLikesByUser((int)$user['id']);

        // 1. 获取用户点赞的 post_id 列表（按点赞时间倒序）
        $rows = UserRepository::fetchLikedPostIds((int)$user['id'], $limit, $offset);

        $postIds = array_column($rows, 'post_id');
        $list = [];

        if (!empty($postIds)) {
            // 2. 复用 PostRepository 获取动态详情（保持顺序）
            $posts = PostRepository::fetchByIds($postIds, $currentUserId);
            // fetchByIds 按 created_at 排序，需要按 postIds 原始顺序重排
            $postsMap = [];
            foreach ($posts as $p) {
                $postsMap[$p['id']] = $p;
            }
            // 3. 批量获取点赞/评论元数据
            [$likesMap, $commentsMap, $blockedSet] = PostRepository::fetchBatchMeta($postIds);
            // 4. 格式化输出
            foreach ($postIds as $pid) {
                if (isset($postsMap[$pid])) {
                    $list[] = PostFormatter::formatPostBatch($postsMap[$pid], $currentUserId, $likesMap[$pid] ?? [], $commentsMap[$pid] ?? [], $blockedSet);
                }
            }
        }

        return Response::success([
            'list' => $list,
            'pagination' => ['page' => $page, 'limit' => $limit, 'total' => $total]
        ]);
    }

    /**
     * GDPR 数据可携带权：用户导出自己的全部数据
     *
     * 当前仅支持 format=json（返回完整结构化数据包，推荐用于迁移到其他平台）；
     * format=csv 返回 400，不生成 zip 压缩包（csv/zip 打包尚未实现）。
     *
     * 数据范围：账号信息（含资料与隐私字段，不含密码/令牌）、动态、评论、点赞、收藏、关注/粉丝
     * 不包含：他人发给该用户的私信（属于对方数据）
     */
    /**
     * V70：设置头像框（会员中心挑选）
     * 仅会员可使用付费头像框；普通用户只能设回 none。
     */
    public function setFrame(Request $request): Response
    {
        $user = Auth::user($request);
        if (!$user) {
            return Response::error('请先登录', 401);
        }
        $frame = trim((string)$request->getString('frame'));
        $allowed = ['none', 'gold', 'diamond', 'crown', 'rainbow'];
        if (!in_array($frame, $allowed, true)) {
            return Response::error('不支持的头像框', 400);
        }
        $vipLevel = (int)($user['vip_level'] ?? 0);
        if ($vipLevel <= 0 && $frame !== 'none') {
            return Response::error('仅会员可使用头像框', 403);
        }
        try {
            UserRepository::updateAvatarFrame((int)$user['id'], $frame);
            Auth::clearUserCache((int)$user['id']);
            // V72：三处缓存都要清，否则选完框界面还在用旧数据
            // 1) 个人资料接口 user_profile_*（我的页 / 个人主页 onShow 都读它）—— 换成「无」后
            //    我的页仍显示旧框，就是漏了这一条
            Cache::delete("user_profile_{$user['username']}");
            // 2) 时间线 timeline_v4_*（1 小时）：主页动态列表
            $this->clearCache('timeline_v4_');
            // 3) 热门榜 popular_*：发现页
            $this->clearCache('popular_');
            return Response::success(['avatar_frame' => $frame], '已更新');
        } catch (\Throwable $e) {
            error_log('[Hublog UserController setFrame] ' . $e->getMessage()); // @allow-error-log
            return Response::error('设置失败，请重试', 500);
        }
    }

    public function exportMyData(Request $request): Response
    {
        $user = Auth::user($request);
        $userId = (int)$user['id'];
        $format = $request->getString('format', 'json');
        $now = date('Y-m-d H:i:s');

        // 1. 账号信息（脱敏：不含密码、token_version）
        $profile = UserRepository::findExportProfileById($userId);
        if (!$profile) {
            return Response::error('用户不存在', 404);
        }

        // 2. 动态
        $posts = UserRepository::fetchUserPostsForExport($userId);

        // 3. 评论
        $comments = UserRepository::fetchUserCommentsForExport($userId);

        // 4. 点赞
        $likes = UserRepository::fetchLikesForExport($userId);

        // 5. 收藏
        $favorites = UserRepository::fetchFavoritesForExport($userId);

        // 6. 关注的人
        $following = UserRepository::fetchFollowingForExport($userId);

        // 7. 粉丝
        $followers = UserRepository::fetchFollowersForExport($userId);

        // 组装数据包
        $dataPackage = [
            'meta' => [
                'exported_at' => $now,
                'platform' => APP_NAME,
                'version' => defined('APP_VERSION') ? APP_VERSION : 'v2.0',
                'format' => 'hublog-personal-data-v1',
            ],
            'profile' => $profile,
            'posts' => $posts,
            'comments' => $comments,
            'likes' => $likes,
            'favorites' => $favorites,
            'following' => $following,
            'followers' => $followers,
        ];

        if ($format === 'csv') {
            // CSV 格式：返回 JSON 提示用 JSON 格式（避免在内存中打包 zip 的复杂性）
            // 真正的 CSV 下载建议前端用 JSON 转 CSV，或使用管理员导出功能
            return Response::error('暂不支持 CSV，请使用 format=json', 400);
        }

        // 默认 JSON 格式：直接返回结构化数据，前端可触发下载
        return Response::success($dataPackage);
    }

    public function updateStatus(Request $request): Response
    {
        $admin = Auth::admin($request);
        $id = $request->getInt('id');
        $status = $request->getInt('status');

        if (!$id || !in_array($status, [0, 1], true)) {
            return Response::error('参数错误', 400);
        }
        // status=0 等价于封禁管理员，必须拦；status=1 是解封，属恢复动作可对管理员放行
        if ($error = AdminTargetGuard::check($admin, $id, AdminTargetGuard::ACTION_STATUS, $status === 1)) {
            return $error;
        }

        // V102：同步维护 account_state（封禁=1 / 解封=0），与 status 二义性拆分对齐
        UserRepository::updateUserStatus($id, $status, \Hublog\Core\Helper::now(), $status === 0 ? 1 : 0);
        \Hublog\Core\Auth::clearUserCache($id);
        $this->clearCache('user_profile_');

        // V82：封禁（status=0）冻结名下徽章；解封（status=1）恢复实时校验
        \Hublog\Core\BadgeService::onEvent($id, 'user_status_changed');

        return Response::success(null, '操作成功');
    }

    /**
     * PUT /admin/users/update —— 编辑用户（昵称/角色/状态/重置密码）
     * 后台用户管理的编辑弹窗入口；字段均可选，只更新提交的字段。
     */
    public function adminUpdate(Request $request): Response
    {
        Auth::admin($request);
        $id = $request->getInt('id');
        if (!$id) {
            return Response::error('参数错误', 400);
        }

        $user = UserRepository::findIdRoleStatusById($id);
        if (!$user) {
            return Response::error('用户不存在', 404);
        }

        $currentUser = Auth::user($request);
        $isSelf = $id === (int)($currentUser['id'] ?? 0);
        $data = [];

        // 昵称：提交且非空才更新，查重排除自己
        if ($request->has('nickname')) {
            $nickname = trim($request->getString('nickname'));
            if ($nickname === '') {
                return Response::error('昵称不能为空', 400);
            }
            $exists = UserRepository::nicknameExistsElsewhere($nickname, $id);
            if ($exists) {
                return Response::error('昵称已被其他用户使用', 409);
            }
            $data['nickname'] = $nickname;
        }

        // 角色：仅 user / admin；不允许修改自己的角色（防误降权锁死），超级管理员 id=1 角色不可改
        if ($request->has('role')) {
            $role = $request->getString('role');
            if (!in_array($role, ['user', 'admin'], true)) {
                return Response::error('角色参数错误', 400);
            }
            if ($isSelf && $role !== 'admin') {
                return Response::error('不能修改自己的角色', 403);
            }
            if ($id === 1 && $role !== 'admin') {
                return Response::error('超级管理员角色不可修改', 403);
            }
            // V103：角色变更审计（before 为旧角色）
            if ((string)($user['role'] ?? '') !== $role) {
                \Hublog\Core\MemberChangeAudit::record(
                    $id, \Hublog\Core\MemberChangeAudit::TYPE_ROLE_CHANGE,
                    (string)($user['role'] ?? ''), $role,
                    (int)($currentUser['id'] ?? 0), (string)($currentUser['username'] ?? ''),
                    '后台修改角色', '', 'admin', (string)$id
                );
            }
            $data['role'] = $role;
        }

        // 状态：不允许禁用自己
        if ($request->has('status')) {
            $status = $request->getInt('status');
            if (!in_array($status, [0, 1], true)) {
                return Response::error('状态参数错误', 400);
            }
            if ($isSelf && $status !== 1) {
                return Response::error('不能禁用当前登录账号', 403);
            }
            $data['status'] = $status;
            // V102：与 updateStatus 同口径同步 account_state（封禁=1 / 解封=0）
            $data['account_state'] = $status === 0 ? 1 : 0;
        }

        // 密码：留空不修改，至少 6 位。取出哈希与其余字段分开走——改密必须同事务递增版本 + 撤销全部 refresh_token
        $passwordHash = null;
        if ($request->has('password')) {
            $password = (string)$request->getString('password');
            if ($password !== '') {
                if (mb_strlen($password) < 6) {
                    return Response::error('新密码至少 6 位', 400);
                }
                $passwordHash = Auth::hashPassword($password);
            }
        }

        if (!$data && $passwordHash === null) {
            return Response::error('没有需要更新的字段', 400);
        }

        $statusChanged = isset($data['status']) && (int)$data['status'] !== (int)$user['status'];
        $now = Helper::now();
        if ($passwordHash !== null) {
            // 附带字段（昵称/角色/状态等）与改密在同一事务内提交；updated_at 由仓储 SQL 统一写入
            UserRepository::changePasswordAndInvalidateSessions($id, $passwordHash, $now, $data);
        } else {
            $data['updated_at'] = $now;
            UserRepository::updateUserFields($id, $data);
        }
        Auth::clearUserCache($id);
        $this->clearCache('user_profile_');

        if ($statusChanged) {
            // 与 updateStatus 相同：封禁冻结名下徽章 / 解封恢复
            BadgeService::onEvent($id, 'user_status_changed');
        }

        return Response::success(null, '保存成功');
    }

    public function delete(Request $request): Response
    {
        $admin = Auth::admin($request);
        $id = $request->getInt('id');
        if (!$id) {
            return Response::error('参数错误', 400);
        }

        if ($error = AdminTargetGuard::check($admin, $id, AdminTargetGuard::ACTION_DELETE)) {
            return $error;
        }

        $user = UserRepository::findIdUsernameAvatarById($id);
        if (!$user) {
            return Response::error('用户不存在', 404);
        }

        // 预先收集该用户的动态图片，数据库删除后将无法通过外键反查
        $posts = UserRepository::fetchUserPostImages($id);
        $imageUrls = [];
        foreach ($posts as $post) {
            if (!empty($post['images'])) {
                $images = json_decode($post['images'], true);
                if (is_array($images)) {
                    $imageUrls = array_merge($imageUrls, $images);
                }
            }
        }

        UserRepository::deleteUser($id);

        // 数据库已提交，再清理文件与缓存（文件清理失败不影响数据库一致性）
        foreach ($imageUrls as $url) {
            Upload::deleteUpload($url);
        }
        Upload::deleteUpload($user['avatar']);

        Cache::delete("user_profile_{$user['username']}");
        foreach ($posts as $post) {
            Cache::delete("post_{$post['id']}");
            Cache::delete("post_likes_{$post['id']}");
            Cache::delete("post_comments_{$post['id']}");
            Cache::delete("post_comments_raw_{$post['id']}");
        }
        // 【P0-3 缓存前缀修复】旧代码清 timeline_v2_ 是已废弃前缀，删了等于没删：
        // 删号后关注者时间线仍会命中缓存、继续展示该用户旧动态（1 小时 TTL）。
        // 现役键是 timeline_v4_*（PostFeedTrait）与 timeline_total_*（计数），
        // 且都可能再次升版——用命名空间升版一次性失效全部代际，与
        // AuthController::clearUserProfileCaches() 的清法保持一致。
        Cache::bumpNsVersion('timeline');
        Cache::delete('admin_dashboard');

        return Response::success(null, '删除成功');
    }

    // ===================== V58：P1-4 隐私权限 + P2 设置 / 草稿 / 通知偏好 / 申诉 / 手机号 =====================

    /** 可写的隐私与个性化字段（白名单，杜绝 SQL 注入型写库） */
    private const PRIVACY_FIELDS = [
        'privacy_home', 'allow_search', 'allow_mention', 'allow_stranger_msg', 'hide_online', 'font_scale', 'msg_sound',
    ];

    private const NOTIFY_FIELDS = [
        'all_enabled', 'post_like', 'post_comment', 'follow', 'message', 'circle_post', 'system',
    ];

    /**
     * V58 隐私：设置页一次性拉全量（隐私开关 + 通知偏好 + 资料扩展字段）
     * GET /api/user/privacy
     */
    public function privacy(Request $request): Response
    {
        $user = Auth::check($request);

        $row = UserRepository::findPrivacySettings((int)$user['id']) ?: [];

        $prefs = UserRepository::findNotificationPrefs((int)$user['id']);
        if (!$prefs) {
            UserRepository::insertNotificationPrefs([
                'user_id' => $user['id'], 'all_enabled' => 1, 'post_like' => 1, 'post_comment' => 1,
                'follow' => 1, 'message' => 1, 'circle_post' => 1, 'system' => 1,
            ]);
            $prefs = UserRepository::findNotificationPrefs((int)$user['id']) ?: [];
        }
        unset($prefs['id'], $prefs['user_id'], $prefs['created_at'], $prefs['updated_at']);

        // 手机号脱敏：138****8888
        $maskedPhone = !empty($row['phone'])
            ? preg_replace('/(\d{3})\d{4}(\d{4})/', '$1****$2', (string)$row['phone'])
            : '';

        return Response::success([
            'privacy' => [
                'privacy_home'       => $row['privacy_home'] ?? 'public',
                'allow_search'       => (int)($row['allow_search'] ?? 1),
                'allow_mention'      => (int)($row['allow_mention'] ?? 1),
                'allow_stranger_msg' => (int)($row['allow_stranger_msg'] ?? 1),
                'hide_online'        => (int)($row['hide_online'] ?? 0),
                'font_scale'         => $row['font_scale'] ?? 'normal',
                'msg_sound'          => (int)($row['msg_sound'] ?? 1),
            ],
            'profile_ext' => [
                'gender'    => (int)($row['gender'] ?? 0),
                'birthday'  => $row['birthday'] ?? '',
                'region'    => $row['region'] ?? '',
                'user_tags' => $row['user_tags'] ?? '',
            ],
            'phone' => [
                'phone'      => $maskedPhone,
                'verified'   => !empty($row['phone_verified_at']),
                'full_phone' => (string)($row['phone'] ?? ''),
            ],
            'notify' => $prefs,
        ]);
    }

    /**
     * PUT /api/user/privacy — 更新隐私与个性化设置
     * privacy_home: public=所有人 followers=仅粉丝 private=仅自己
     */
    public function updatePrivacy(Request $request): Response
    {
        $user = Auth::check($request);
        $updateData = [];
        $all = $request->all();

        foreach (self::PRIVACY_FIELDS as $field) {
            if (!array_key_exists($field, $all) || $all[$field] === null || $all[$field] === '') {
                continue;
            }
            if ($field === 'privacy_home') {
                $val = (string)$all[$field];
                if (!in_array($val, ['public', 'followers', 'private'], true)) {
                    continue;
                }
                $updateData[$field] = $val;
            } elseif ($field === 'font_scale') {
                $val = (string)$all[$field];
                if (!in_array($val, ['small', 'normal', 'large', 'xlarge'], true)) {
                    continue;
                }
                $updateData[$field] = $val;
            } else {
                $updateData[$field] = ((int)$all[$field]) ? 1 : 0;
            }
        }

        if (!$updateData) {
            return Response::success(null, '没有需要更新的内容');
        }
        $updateData['updated_at'] = Helper::now();
        UserRepository::updateUserFields((int)$user['id'], $updateData);

        // 缓存的个人主页要失效，否则主页可见范围改了但仍读旧缓存
        $username = UserRepository::findUsernameById((int)$user['id']);
        if ($username) {
            Cache::delete("user_profile_{$username}");
        }

        return Response::success($updateData, '设置已保存');
    }

    /**
     * P2-3 云端草稿箱：列表
     * GET /api/user/drafts?type=post
     */
    public function drafts(Request $request): Response
    {
        $user = Auth::check($request);
        $type = $request->getString('type', 'post');
        if (!in_array($type, ['post', 'comment'], true)) {
            $type = 'post';
        }

        // V134：extra 列探测与 SQL 拼接已下沉到 UserRepository，老库自动退化为不带该字段
        ['page' => $page, 'limit' => $limit, 'offset' => $offset] = $request->pagination();
        $rows = UserRepository::fetchDraftPage((int)$user['id'], $type, $offset, $limit);
        $total = UserRepository::countDrafts((int)$user['id'], $type);
        $list = array_map(function ($draft) {
            $draft['images'] = $draft['images'] ? explode(',', rtrim((string)$draft['images'], ',')) : [];
            if (array_key_exists('extra', $draft)) {
                $decoded = json_decode((string)$draft['extra'], true);
                $draft['extra'] = is_array($decoded) ? $decoded : null;
            }
            return $draft;
        }, $rows);

        return Response::success(Helper::paginatedResponse($list, $page, $limit, $total));
    }

    /**
     * V134：drafts.extra 列是否存在（老库可能建于加列之前）。
     * information_schema 探测 + 进程内缓存，避免每次请求都查。
     */
    private static function draftsTableHasExtra(): bool
    {
        return UserRepository::draftsTableHasExtraColumn();
    }

    /**
     * V134：从请求里取帖型表单，组装成 drafts.extra 用的数组。
     * 普通帖（没传帖型）返回 null —— 不碰 extra 列，保持老行为。
     * 投票的选项/问题/时长已有专列，这里只补多选标记。
     */
    private function draftExtraFromRequest(Request $request): ?array
    {
        $postType = strtolower(trim((string)$request->getString('post_type')));
        if (!in_array($postType, ['question', 'team', 'checkin', 'poll'], true)) {
            return null;
        }
        $extra = ['post_type' => $postType];
        if ($postType === 'question') {
            $extra['question'] = mb_substr(trim((string)$request->getString('question')), 0, 255);
        } elseif ($postType === 'team') {
            $extra['team_headline'] = mb_substr(trim((string)$request->getString('team_headline')), 0, 255);
            $extra['team_need'] = (int)$request->getInt('team_need', 0);
            $extra['team_deadline'] = mb_substr(trim((string)$request->getString('team_deadline')), 0, 20);
            $extra['team_city'] = mb_substr(trim((string)$request->getString('team_city')), 0, 50);
            $extra['team_contact'] = mb_substr(trim((string)$request->getString('team_contact')), 0, 100);
        } elseif ($postType === 'checkin') {
            $extra['checkin_goal'] = mb_substr(trim((string)$request->getString('checkin_goal')), 0, 255);
            $extra['checkin_days'] = (int)$request->getInt('checkin_days', 0);
        } else {
            $extra['poll_multi'] = $request->getBool('poll_multi') ? 1 : 0;
        }
        return $extra;
    }

    /**
     * P2-3 云端草稿箱：新建 / 更新（传 id 则更新，否则按「同类型同目标」只保留一条最新草稿）
     * POST /api/user/draft
     */
    public function saveDraft(Request $request): Response
    {
        $user = Auth::check($request);
        $type = $request->getString('type', 'post');
        if (!in_array($type, ['post', 'comment'], true)) {
            return Response::error('草稿类型不正确', 400);
        }

        $draftId = (int)$request->getInt('id', 0);
        $postId = (int)$request->getInt('post_id', 0);
        $circleId = (int)$request->getInt('circle_id', 0);

        $common = [
            'type'        => $type,
            'post_id'     => $postId > 0 ? $postId : null,
            'circle_id'   => $circleId > 0 ? $circleId : null,
            'title'       => $request->getString('title') ?: null,
            'content'     => $request->getString('content') ?? '',
            'visibility'  => $request->getString('visibility') ?: 'public',
            'link_url'    => $request->getString('link_url') ?: null,
            'link_title'  => $request->getString('link_title') ?: null,
            'link_desc'   => $request->getString('link_desc') ?: null,
            'link_site'   => $request->getString('link_site') ?: null,
            'poll_question' => $request->getString('poll_question') ?: null,
            'poll_options'  => $request->getString('poll_options') ?: null,
            'poll_duration' => (int)$request->getInt('poll_duration', 0),
            'scheduled_at'  => $request->getString('scheduled_at') ?: null,
        ];

        // V134：帖型表单（问答/组队/打卡/投票多选）随 extra JSON 一起存——
        // 纯文字帖型帖正文为空，不存帖型的话云端草稿恢复后内容全丢。
        // 列不存在的老库直接跳过（普通帖与老草稿行为不变）。
        if (self::draftsTableHasExtra()) {
            $extra = $this->draftExtraFromRequest($request);
            $common['extra'] = $extra !== null
                ? json_encode($extra, JSON_UNESCAPED_UNICODE)
                : null;
        }

        // 图片URL 允许相对路径与本站地址，统一用逗号拼接（最多 9 张）
        $images = $request->getString('images');
        if ($images) {
            $list = array_values(array_unique(array_filter(array_map('trim', explode(',', (string)$images)))));
            if (count($list) > 9) {
                return Response::error('最多保存 9 张图片', 400);
            }
            foreach ($list as $u) {
                if (str_starts_with($u, 'http') || str_starts_with($u, '/') || str_starts_with($u, 'blob') || str_starts_with($u, 'data:')) {
                    continue; // 允许本地临时路径（发布时才落库）与已上传 URL
                }
                return Response::error('图片地址不合法', 422);
            }
            $common['images'] = implode(',', $list);
        } else {
            $common['images'] = null;
        }

        $savedId = UserRepository::saveDraft((int)$user['id'], $draftId, $type, $postId, $common);
        if ($savedId === null) {
            return Response::error('草稿不存在', 404);
        }
        $draftId = $savedId;

        $draft = UserRepository::findDraftById($draftId);
        $draft['images'] = $draft['images'] ? explode(',', rtrim($draft['images'], ',')) : [];
        return Response::success($draft, '草稿已保存');
    }

    /**
     * P2-3 删除草稿
     * DELETE /api/user/draft?id=1
     */
    public function deleteDraft(Request $request): Response
    {
        $user = Auth::check($request);
        $id = (int)$request->getInt('id');
        if (!$id) {
            return Response::error('参数错误', 400);
        }
        $affected = UserRepository::clearDraftContent($id, (int)$user['id']);
        if (!$affected) {
            return Response::error('草稿不存在', 404);
        }
        UserRepository::deleteDraft($id, (int)$user['id']);
        return Response::success(null, '删除成功');
    }

    /**
     * P2-4 服务端通知偏好
     * GET /api/user/notify-prefs
     */
    public function notifyPrefs(Request $request): Response
    {
        $user = Auth::check($request);
        $prefs = UserRepository::findNotificationPrefs((int)$user['id']);
        if (!$prefs) {
            UserRepository::insertNotificationPrefs(['user_id' => $user['id']] + array_fill_keys(self::NOTIFY_FIELDS, 1));
            $prefs = UserRepository::findNotificationPrefs((int)$user['id']);
        }
        $out = [];
        foreach (self::NOTIFY_FIELDS as $f) {
            $out[$f] = (int)($prefs[$f] ?? 1);
        }
        return Response::success($out);
    }

    /**
     * PUT /api/user/notify-prefs
     */
    public function updateNotifyPrefs(Request $request): Response
    {
        $user = Auth::check($request);
        $all = $request->all();
        $updateData = [];
        foreach (self::NOTIFY_FIELDS as $f) {
            if (array_key_exists($f, $all) && $all[$f] !== '') {
                $updateData[$f] = ((int)$all[$f]) ? 1 : 0;
            }
        }
        if (!$updateData) {
            return Response::success(null, '没有需要更新的内容');
        }
        $exists = UserRepository::notificationPrefsExist((int)$user['id']);
        if (!$exists) {
            UserRepository::insertNotificationPrefs(array_merge(['user_id' => $user['id']], $updateData));
        } else {
            UserRepository::updateNotificationPrefs((int)$user['id'], $updateData);
        }
        return Response::success($updateData, '通知偏好已保存');
    }

    /**
     * P2-5 提交申诉（被封禁/被下架内容可申诉）
     * POST /api/user/appeal
     */
    public function submitAppeal(Request $request): Response
    {
        $user = Auth::check($request);
        $category = $request->getString('category');
        if (!in_array($category, ['falseblock', 'forbidden', 'misreview', 'other'], true)) {
            return Response::error('申诉类型不正确', 400);
        }
        $content = trim((string)$request->getString('content'));
        if (mb_strlen($content) < 10) {
            return Response::error('申诉说明至少 10 个字，请补充说明情况', 400);
        }
        if (mb_strlen($content) > 1000) {
            return Response::error('申诉说明最多 1000 字', 400);
        }

        $targetType = $request->getString('target_type') ?: null;
        if ($targetType && !in_array($targetType, ['post', 'comment', 'user', 'circle', null], true)) {
            return Response::error('申诉对象类型不正确', 400);
        }
        $targetId = (int)$request->getInt('target_id', 0);

        // 一天最多提 3 条，防止刷屏
        $today = UserRepository::countTodayAppeals((int)$user['id']);
        if ((int)$today >= 3) {
            return Response::error('每天最多提交 3 条申诉', 429);
        }

        $id = UserRepository::insertAppeal([
            'user_id'     => $user['id'],
            'target_type' => $targetType,
            'target_id'   => $targetId > 0 ? $targetId : null,
            'category'    => $category,
            'content'     => $content,
            'status'      => 'pending',
        ]);

        return Response::success(['id' => $id], '申诉已提交，平台会在 1-3 个工作日内处理');
    }

    /**
     * GET /api/user/appeals — 我的申诉记录
     */
    public function myAppeals(Request $request): Response
    {
        $user = Auth::check($request);
        ['page' => $page, 'limit' => $limit, 'offset' => $offset] = $request->pagination();
        $list = UserRepository::fetchMyAppeals((int)$user['id'], $offset, $limit);
        $total = UserRepository::countMyAppeals((int)$user['id']);

        return Response::success(Helper::paginatedResponse($list, $page, $limit, $total));
    }

    /**
     * 后台申诉管理：列表（按状态/类型/用户筛选 + 分页）
     * GET /api/admin/appeals?status=&category=&keyword=&page=&limit=
     */
    public function adminAppeals(Request $request): Response
    {
        Auth::admin($request);
        $status = $request->getString('status');
        $category = $request->getString('category');
        $keyword = trim((string)$request->getString('keyword'));

        // 枚举白名单守卫留在控制器，仓储只接收归一化后的筛选值
        if (!in_array($status, ['pending', 'reviewing', 'resolved', 'rejected'], true)) {
            $status = '';
        }
        if (!in_array($category, ['falseblock', 'forbidden', 'misreview', 'other'], true)) {
            $category = '';
        }

        ['page' => $page, 'limit' => $limit, 'offset' => $offset] = $request->pagination();
        $rows = UserRepository::fetchAdminAppeals($status, $category, $keyword, $offset, $limit);
        $total = UserRepository::countAdminAppeals($status, $category, $keyword);
        $list = array_map(function ($row) {
            // 申诉对象摘要（帖子标题/评论内容/圈子名/用户昵称），逐行查，后台分页 ≤50 行可接受
            $row['target'] = $this->appealTargetSummary($row['target_type'], (int)$row['target_id']);
            $row['images'] = $row['images'] ? json_decode((string)$row['images'], true) ?: [] : [];
            return $row;
        }, $rows);

        return Response::success(Helper::paginatedResponse($list, $page, $limit, $total));
    }

    /** 申诉对象摘要：给后台列表跳转/展示用 */
    private function appealTargetSummary(?string $type, int $id): ?array
    {
        if (!$type || $id <= 0) {
            return null;
        }
        try {
            switch ($type) {
                case 'post':
                    $r = UserRepository::findAppealPostTarget($id);
                    return $r ? ['type' => 'post', 'id' => $id, 'label' => $r['title'] ?: mb_substr((string)$r['content'], 0, 30), 'status' => $r['status']] : null;
                case 'comment':
                    $r = UserRepository::findAppealCommentTarget($id);
                    return $r ? ['type' => 'comment', 'id' => $id, 'post_id' => (int)$r['post_id'], 'label' => mb_substr((string)$r['content'], 0, 30), 'status' => $r['status']] : null;
                case 'circle':
                    $r = UserRepository::findAppealCircleTarget($id);
                    return $r ? ['type' => 'circle', 'id' => $id, 'label' => $r['name'], 'status' => $r['status']] : null;
                case 'user':
                    $r = UserRepository::findAppealUserTarget($id);
                    return $r ? ['type' => 'user', 'id' => $id, 'label' => $r['nickname'] ?: $r['username'], 'status' => $r['status']] : null;
            }
        } catch (\Throwable $e) {
            // 表不存在等异常不阻断列表渲染
        }
        return null;
    }

    /**
     * 后台处理申诉：改状态 + 备注 + 记录处理人/时间，解决或驳回时给用户发系统通知
     * POST /api/admin/appeal-handle  { id, status: reviewing|resolved|rejected, admin_note }
     */
    public function adminHandleAppeal(Request $request): Response
    {
        $admin = Auth::admin($request);
        $id = (int)$request->getInt('id', 0);
        $status = $request->getString('status');
        if ($id <= 0 || !in_array($status, ['reviewing', 'resolved', 'rejected'], true)) {
            return Response::error('参数不正确', 400);
        }
        $adminNote = trim((string)$request->getString('admin_note'));
        if ($status !== 'reviewing' && $adminNote === '') {
            return Response::error('解决或驳回时必须填写处理备注', 400);
        }
        if (mb_strlen($adminNote) > 500) {
            return Response::error('处理备注最多 500 字', 400);
        }

        $appeal = UserRepository::findAppealById($id);
        if (!$appeal) {
            return Response::error('申诉不存在', 404);
        }

        $statusLabel = ['reviewing' => '处理中', 'resolved' => '已解决', 'rejected' => '已驳回'][$status];
        UserRepository::updateAppeal($id, [
            'status'     => $status,
            'admin_id'   => (int)$admin['id'],
            'admin_note' => $adminNote,
            'handled_at' => Helper::now(),
        ]);

        // 结案（解决/驳回）时通知申诉人；「开始处理」不打扰
        if (in_array($status, ['resolved', 'rejected'], true)) {
            $content = $adminNote !== ''
                ? "你提交的申诉已处理：{$statusLabel}。备注：{$adminNote}"
                : "你提交的申诉已处理：{$statusLabel}";
            try {
                UserRepository::insertNotification([
                    'user_id'    => (int)$appeal['user_id'],
                    'sender_id'  => 1, // 系统通知：install.php 固定管理员 id=1（与徽章通知兜底一致）
                    'type'       => 'system',
                    'content'    => $content,
                    'is_read'    => 0,
                    'created_at' => Helper::now(),
                ]);
                PushService::pushToUser((int)$appeal['user_id'], '申诉处理结果', $content, ['type' => 'system']);
            } catch (\Throwable $e) {
                // 通知失败不阻断处理结果
            }
        }

        return Response::success(['id' => $id, 'status' => $status]);
    }

    /**
     * P2-1 发送手机号验证码
     * POST /api/user/phone-code  { phone, scene: bind|set_password }
     *
     * 本路由要求登录态（:1104 Auth::check），只允许登录态自服务场景：
     *  - bind：绑定手机号（verifyPhone 按 bind 校验）
     *  - set_password：App 设置密码（AuthController::setPassword 按 set_password 校验）
     * login / reset 是公开端点 /api/auth/phone-code 的场景，禁止经此端点为任意手机号发码。
     *
     * S1 修复：本方法曾在未配短信时把明文 dev_code 无条件回带给任意登录用户，
     * 配合 /api/auth/login-phone（按 scene='login' 取码）可接管任意手机号账号。
     * 现在与 /api/auth/phone-code 共用 PhoneCodeService：生产失败关闭 503，
     * dev_code 仅 APP_DEBUG 回带，并补上 IP 级 sms_ip_hour/day 限流。
     */
    public function sendPhoneCode(Request $request): Response
    {
        // 该路由挂在公开 group 内（无 AuthMiddleware），Auth::check 会强制登录并抛 401。
        $user = Auth::check($request);

        $phone = preg_replace('/\D/', '', trim((string)$request->getString('phone')));
        $scene = (string)$request->getString('scene');
        $scene = $scene !== '' ? $scene : 'bind';
        // 仅放行登录态自服务场景；login / reset 是公开端点的场景，
        // 若允许经此发码，登录用户可为任意手机号签发登录/重置码。
        if (!in_array($scene, ['bind', 'set_password'], true)) {
            return Response::error('场景参数错误', 400);
        }
        if ($scene === 'set_password') {
            // setPassword 端按 set_password 场景取码且校验手机号归属当前账号（AuthController.php:733-739），
            // 发码端同样校验，避免给他人手机号发设置密码码。
            $accountPhone = UserRepository::findPhoneById((int)$user['id']);
            if (empty($accountPhone) || $accountPhone !== $phone) {
                return Response::error('手机号与当前账号不符', 403);
            }
        }
        $result = PhoneCodeService::issue($phone, $scene, $request->getIp());
        if (!$result['ok']) {
            return Response::error($result['message'], $result['status']);
        }
        return Response::success($result['data'], $result['message']);
    }

    /**
     * POST /api/user/phone/verify — 校验验证码并绑定手机号
     */
    public function verifyPhone(Request $request): Response
    {
        $user = Auth::check($request);
        $phone = preg_replace('/\D/', '', trim((string)$request->getString('phone')));
        $code = preg_replace('/\D/', '', trim((string)$request->getString('code')));
        if (!preg_match('/^1[3-9]\d{9}$/', $phone) || strlen($code) !== 6) {
            return Response::error('参数不正确', 400);
        }

        // 收敛：本入口原先把 scene 完全交给客户端、既无 scene 过滤也无防爆破锁定，
        // 导致 login 场景签发的码可被拿来绑定，且可无限猜码。现统一走 verify，
        // scene 服务端写死为 bind（与签发端 PhoneCodeService::SCENES 语义一致）。
        $verified = PhoneCodeService::verify($phone, $code, 'bind');
        if (!$verified['ok']) {
            return Response::error($verified['message'], $verified['status']);
        }

        // 该号码若已绑到别的账号，解绑那个账号（避免一个号码两账号）由仓储事务完成；
        // 验证码原子核销已由 PhoneCodeService::verify 在校验阶段原子完成。
        UserRepository::bindPhone((int)$user['id'], $phone, Helper::now());

        // V82：手机号绑定成功 → 徽章实时校验（手机已认证）
        BadgeService::onEvent((int)$user['id'], 'phone_bound');

        return Response::success(
            ['phone' => $phone, 'phone_verified_at' => Helper::now()],
            '手机号绑定成功'
        );
    }

    /**
     * P2-7 在线心跳：每 60 秒上报一次，用于好友/主页展示在线状态
     * PUT /api/user/alive
     */
    public function heartbeat(Request $request): Response
    {
        $user = Auth::check($request);
        UserRepository::updateLastActiveAt((int)$user['id'], Helper::now());
        return Response::success(['last_active_at' => Helper::now()]);
    }

    /**
     * P2-7 在线状态批量查询（5 分钟视为在线）
     * GET /api/user/online?ids=1,2,3
     */
    public function onlineStatus(Request $request): Response
    {
        $viewer = Auth::check($request);
        $viewerId = (int)($viewer['id'] ?? 0);
        $ids = array_filter(array_map('intval', explode(',', (string)$request->getString('ids'))));
        $ids = array_values(array_slice($ids, 0, 50));
        if (!$ids) {
            return Response::success([]);
        }

        $rows = UserRepository::fetchOnlineStatuses($ids);

        $result = [];
        foreach ($rows as $r) {
            $targetId = (int)$r['id'];
            $hide = !empty($r['hide_online']);
            $online = !$hide
                && (int)$r['status'] === 1
                && !empty($r['last_active_at'])
                && (time() - strtotime((string)$r['last_active_at'])) < 300;
            // 与 profile() 同一口径：本人 / 隐藏在线 / 非在线（含封禁注销 status≠1）
            // 一律不下发精确活跃时间，避免 hide_online 被本接口绕过。
            $result[$targetId] = [
                'online'         => (bool)$online,
                'last_active_at' => ($viewerId === $targetId || $hide || !$online) ? null : $r['last_active_at'],
            ];
        }
        return Response::success($result);
    }
}
