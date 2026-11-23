<?php
/**
 * Hublog 关注系统控制器
 */

namespace Hublog\Controllers;

use Hublog\Core\BaseController;
use Hublog\Core\Request;
use Hublog\Core\Response;
use Hublog\Core\FollowRepository;
use Hublog\Core\Auth;
use Hublog\Core\Cache;
use Hublog\Core\Helper;
use Hublog\Core\BadgeService;
// P1-3：关注通知实时推送
use Hublog\Core\SsePush;

class FollowController extends BaseController
{
    /**
     * 关注/取消关注（切换）
     */
    public function follow(Request $request): Response
    {
        $user = Auth::check($request);
        $targetId = $request->getInt('user_id');
        if (!$targetId) {
            return Response::error('参数错误', 400);
        }

        if ($targetId === (int)$user['id']) {
            return Response::error('不能关注自己', 400);
        }

        $target = FollowRepository::findActiveUserById($targetId);
        if (!$target) {
            return Response::error('用户不存在', 404);
        }

        $exists = FollowRepository::findFollowEdge((int)$user['id'], $targetId);
        $following = FollowRepository::toggleFollow((int)$user['id'], $targetId, Helper::now(), $exists);

        // 关注成功后给被关注者发送通知（非核心副作用，失败不阻断主流程）
        if ($following) {
            $this->createFollowNotification((int)$user['id'], $targetId);
        }

        $followersCount = FollowRepository::countFollowersOfTarget($targetId);

        $this->clearFollowCache((int)$user['id'], $targetId);

        // V83：关注数变化 → 重算「社交达人」等统计徽章（关注较低频，不节流）
        BadgeService::evaluateOnAction((int)$user['id'], 0);

        return Response::success([
            'following' => $following,
            'followers_count' => $followersCount
        ]);
    }

    /**
     * 获取指定用户关注的人（分页），支持 username 参数查看他人
     */
    public function following(Request $request): Response
    {
        $currentUser = Auth::user($request);
        $username = $request->getString('username');

        if ($username) {
            $target = FollowRepository::findActiveUserByUsername($username);
            if (!$target) {
                ['page' => $page, 'limit' => $limit] = $request->pagination(20, 50);
                return Response::success(Helper::paginatedResponse([], $page, $limit, 0));
            }
            $targetId = (int)$target['id'];
        } else {
            if (!$currentUser) {
                ['page' => $page, 'limit' => $limit] = $request->pagination(20, 50);
                return Response::success(Helper::paginatedResponse([], $page, $limit, 0));
            }
            $targetId = (int)$currentUser['id'];
        }

        ['page' => $page, 'limit' => $limit, 'offset' => $offset] = $request->pagination(20, 50);
        $items = FollowRepository::fetchFollowingPage($targetId, $offset, $limit);
        // V83：批量补内联徽章，让关注列表与动态卡片露出一致的徽章
        BadgeService::decorateUsersWithInlineBadges($items);
        $total = FollowRepository::countFollowingList($targetId);

        return Response::success(Helper::paginatedResponse($items, $page, $limit, $total));
    }

    /**
     * 获取粉丝列表（分页），支持指定用户名
     */
    public function followers(Request $request): Response
    {
        $currentUser = Auth::user($request);
        $username = $request->getString('username');

        if ($username) {
            $target = FollowRepository::findActiveUserByUsername($username);
            if (!$target) {
                ['page' => $page, 'limit' => $limit] = $request->pagination(20, 50);
                return Response::success(Helper::paginatedResponse([], $page, $limit, 0));
            }
            $targetId = (int)$target['id'];
        } else {
            if (!$currentUser) {
                ['page' => $page, 'limit' => $limit] = $request->pagination(20, 50);
                return Response::success(Helper::paginatedResponse([], $page, $limit, 0));
            }
            $targetId = (int)$currentUser['id'];
        }

        ['page' => $page, 'limit' => $limit, 'offset' => $offset] = $request->pagination(20, 50);
        $items = FollowRepository::fetchFollowersPage($targetId, $offset, $limit);
        // V83：批量补内联徽章（粉丝列表与关注列表一致）
        BadgeService::decorateUsersWithInlineBadges($items);
        $total = FollowRepository::countFollowerList($targetId);

        return Response::success(Helper::paginatedResponse($items, $page, $limit, $total));
    }

    /**
     * 检查当前用户是否关注了目标用户
     */
    public function checkFollowing(Request $request): Response
    {
        $user = Auth::check($request);
        $targetId = $request->getInt('user_id');
        if (!$targetId) {
            return Response::error('参数错误', 400);
        }

        $exists = FollowRepository::findFollowEdge((int)$user['id'], $targetId);

        return Response::success(['following' => !empty($exists)]);
    }

    /**
     * 批量检查当前用户是否关注了多个目标用户
     * POST /follow/batch-check  body: { user_ids: [1, 2, 3, ...] }
     * 返回: { "1": true, "2": false, "3": true, ... }
     */
    public function batchCheckFollowing(Request $request): Response
    {
        $user = Auth::check($request);
        $rawIds = $request->getArray('user_ids');
        if (empty($rawIds)) {
            return Response::success([]);
        }

        // 仅保留合法的整数值，去重，排除自己，限制最多 100 个
        $userIds = [];
        $seen = [];
        foreach ($rawIds as $id) {
            $id = (int)$id;
            if ($id > 0 && $id !== (int)$user['id'] && !isset($seen[$id])) {
                $userIds[] = $id;
                $seen[$id] = true;
            }
            if (count($userIds) >= 100) break;
        }

        if (empty($userIds)) {
            return Response::success([]);
        }

        $rows = FollowRepository::fetchFollowedIdsIn((int)$user['id'], $userIds);

        $followedIds = [];
        foreach ($rows as $r) {
            $followedIds[(int)$r['following_id']] = true;
        }

        $result = [];
        foreach ($userIds as $uid) {
            $result[$uid] = isset($followedIds[$uid]);
        }

        return Response::success($result);
    }

    /**
     * 获取用户的关注数与粉丝数
     */
    public function followStats(Request $request): Response
    {
        $currentUser = Auth::user($request);
        $username = $request->getString('username');

        if ($username) {
            $target = FollowRepository::findActiveUserByUsername($username);
            if (!$target) {
                return Response::error('用户不存在', 404);
            }
            $targetId = (int)$target['id'];
        } else {
            if (!$currentUser) {
                return Response::error('参数错误', 400);
            }
            $targetId = (int)$currentUser['id'];
        }

        $cacheKey = "follow_stats_{$targetId}";
        $stats = Cache::remember($cacheKey, function () use ($targetId) {
            $followingCount = FollowRepository::countFollowingOfUser($targetId);
            $followersCount = FollowRepository::countFollowersOfTarget($targetId);
            // V82：同时给短字段名 following / followers。
            // 只给 _count 时前端读 following 会拿到 undefined → 界面一直显示 0（明明有关注）。
            return [
                'following_count' => $followingCount,
                'followers_count' => $followersCount,
                'following'       => $followingCount,
                'followers'       => $followersCount
            ];
        }, 300);

        return Response::success($stats);
    }

    /**
     * 创建关注通知
     * 通知属于非核心副作用，写入失败时不应阻断关注主流程
     */
    private function createFollowNotification(int $senderId, int $receiverId): void
    {
        if ($senderId === $receiverId) {
            return;
        }
        try {
            FollowRepository::insertFollowNotification($senderId, $receiverId, Helper::now());
            // P1-3：实时推送给被关注者
            try {
                SsePush::push($receiverId, 'notification', [
                    'type' => 'follow',
                    'sender_id' => $senderId,
                ]);
            } catch (\Throwable $_) {}
        } catch (\Throwable $e) {
            error_log('[Hublog Follow Notification] ' . $e->getMessage()); // @allow-error-log
        }
    }

    /**
     * 清理关注相关缓存
     */
    private function clearFollowCache(int $followerId, int $followingId): void
    {
        Cache::delete("follow_stats_{$followerId}");
        Cache::delete("follow_stats_{$followingId}");
    }

    /**
     * POST /follow/block — 拉黑用户（同时自动取消双方关注关系）
     */
    public function blockUser(Request $request): Response
    {
        $user = Auth::check($request);
        $targetId = $request->getInt('user_id');
        if (!$targetId) {
            return Response::error('参数错误', 400);
        }
        if ($targetId === (int)$user['id']) {
            return Response::error('不能拉黑自己', 400);
        }
        $target = FollowRepository::findActiveUserById($targetId);
        if (!$target) {
            return Response::error('用户不存在', 404);
        }

        // 兼容：blocked_users 表未迁移时降级（探针已下沉，内部调用不会绕过）
        if (!FollowRepository::blockedUsersTableExists()) {
            return Response::error('未迁移用户拉黑表', 500);
        }

        FollowRepository::blockUser((int)$user['id'], $targetId, Helper::now(), true);
        $this->clearFollowCache((int)$user['id'], $targetId);

        return Response::success(['blocked' => true], '已加入黑名单');
    }

    /**
     * POST /follow/unblock — 解除拉黑
     */
    public function unblockUser(Request $request): Response
    {
        $user = Auth::check($request);
        $targetId = $request->getInt('user_id');
        if (!$targetId) {
            return Response::error('参数错误', 400);
        }
        FollowRepository::unblockUser((int)$user['id'], $targetId);
        return Response::success(['blocked' => false], '已解除拉黑');
    }

    /**
     * GET /follow/blocks — 当前用户的黑名单列表（分页）
     */
    public function listBlocked(Request $request): Response
    {
        $user = Auth::check($request);
        if (!FollowRepository::blockedUsersTableExists()) {
            ['page' => $page, 'limit' => $limit] = $request->pagination(20, 50);
            return Response::success(Helper::paginatedResponse([], $page, $limit, 0));
        }
        ['page' => $page, 'limit' => $limit, 'offset' => $offset] = $request->pagination(20, 50);
        $items = FollowRepository::fetchBlockedPage((int)$user['id'], $offset, $limit);
        $total = FollowRepository::countBlocked((int)$user['id']);

        return Response::success(Helper::paginatedResponse($items, $page, $limit, $total));
    }

    /**
     * GET /follow/blocked-check — 检查是否已拉黑某用户（登录态）
     */
    public function isBlocked(Request $request): Response
    {
        $user = Auth::check($request);
        $targetId = $request->getInt('user_id');
        if (!$targetId) {
            return Response::success(['blocked' => false, 'blocked_by' => false]);
        }
        [$blocked, $blockedBy] = FollowRepository::checkBlockedState((int)$user['id'], $targetId);
        return Response::success([
            'blocked' => !empty($blocked),
            'blocked_by' => !empty($blockedBy),
        ]);
    }

    /**
     * GET /follow/suggest — 推荐关注（粉丝数 Top 的未关注用户）
     */
    public function suggestUsers(Request $request): Response
    {
        $user = Auth::user($request);
        $limit = min(20, max(3, $request->getInt('limit', 6)));
        $excludeIds = [];
        if ($user) {
            $rows = FollowRepository::fetchFollowedIds((int)$user['id']);
            $excludeIds = array_map(fn($r) => (int)$r['following_id'], $rows);
            $excludeIds[] = (int)$user['id'];
        }
        $list = FollowRepository::suggestUsers($excludeIds, $limit);
        // V83：推荐关注同样补内联徽章
        BadgeService::decorateUsersWithInlineBadges($list);
        return Response::success(['list' => $list]);
    }
}
