<?php
/**
 * Hublog 社交域「关注 / 拉黑」数据仓储
 *
 * 承载 FollowController 下沉的全部 SQL、参数绑定与事务边界。
 * 所有值一律走 ? 绑定（含 LIMIT/OFFSET、IN、已转型 int）；字段清单、顺序、
 * 别名、JOIN、子查询、ORDER BY 与迁移前 HEAD 原文逐字一致。
 *
 * 【相似但不同的查询刻意不合并】
 *  - countFollowersOfTarget()（SELECT COUNT(*) FROM follows WHERE following_id = ?）
 *    ≠ countFollowerList()（JOIN users + u.status = 1）；两者都叫"粉丝数"但 SQL 不同；
 *  - countFollowingOfUser()（无 JOIN）≠ countFollowingList()（JOIN users + u.status = 1）；
 *  - findActiveUserById() / findActiveUserByUsername() 字段相同但 WHERE 不同，各建各的；
 *  - suggestUsers() 的标量子查询使用别名 f（follows 表没有 user_id 列），
 *    避免与外层 users u 的列名产生歧义 —— 项目铁律。
 *
 * 控制器保留鉴权、请求校验、缓存与响应包装；表缺失降级（blocked_users 未迁移）
 * 和幂等/自关注等入口守卫一并下沉到仓储方法本体，避免内部调用绕过。
 */

declare(strict_types=1);

namespace Hublog\Core;

class FollowRepository
{
    // ==================== 用户探测 ====================

    /** follow() / blockUser() —— 按主键取可关注用户（status = 1 留在 SQL） */
    public static function findActiveUserById(int $userId): ?array
    {
        return Database::fetchOne(
            "SELECT id FROM users WHERE id = ? AND status = 1 LIMIT 1",
            [$userId]
        );
    }

    /** following() / followers() / followStats() —— 按用户名取可用用户 */
    public static function findActiveUserByUsername(string $username): ?array
    {
        return Database::fetchOne(
            "SELECT id FROM users WHERE username = ? AND status = 1 LIMIT 1",
            [$username]
        );
    }

    // ==================== 关注关系 ====================

    /** follow() / checkFollowing() —— 单边关注关系（follows 表用 follower_id/following_id） */
    public static function findFollowEdge(int $followerId, int $followingId): ?array
    {
        return Database::fetchOne(
            "SELECT id FROM follows WHERE follower_id = ? AND following_id = ? LIMIT 1",
            [$followerId, $followingId]
        );
    }

    /** follow() 成功后的粉丝数（无 JOIN，与粉丝列表计数不同） */
    public static function countFollowersOfTarget(int $targetId): int
    {
        return (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM follows WHERE following_id = ?",
            [$targetId]
        );
    }

    /** followStats() 的关注数（无 JOIN） */
    public static function countFollowingOfUser(int $userId): int
    {
        return (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM follows WHERE follower_id = ?",
            [$userId]
        );
    }

    /**
     * follow() —— 关注 / 取消关注切换事务。
     *
     * 原控制器入口已校验 targetId 非 0、非自己、目标存在；此处再次拒绝自关注，
     * 保证 Repository 被内部复用时守卫不会被绕过。返回 true 表示新建关注。
     */
    public static function toggleFollow(
        int $followerId,
        int $followingId,
        string $now,
        ?array $existingEdge = null
    ): bool {
        if ($followerId <= 0 || $followingId <= 0 || $followerId === $followingId) {
            return false;
        }
        // 控制器已在事务前取过同一条边，传入可避免多余查询；内部直接调用时自行读取
        $exists = $existingEdge ?? self::findFollowEdge($followerId, $followingId);
        $following = false;

        Database::beginTransaction();
        try {
            if ($exists) {
                Database::delete('follows', 'id = ?', [$exists['id']]);
            } else {
                Database::insert('follows', [
                    'follower_id' => $followerId,
                    'following_id' => $followingId,
                    'created_at' => $now,
                ]);
                $following = true;
            }
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
        return $following;
    }

    /**
     * createFollowNotification() —— 关注通知写入。
     *
     * 原入口守卫「senderId === receiverId 直接返回」已下沉到仓储本体。
     * 调用方仍以 try/catch 包住本方法（非核心副作用，失败不阻断主流程）。
     */
    public static function insertFollowNotification(int $senderId, int $receiverId, string $now): void
    {
        if ($senderId === $receiverId) {
            return;
        }
        Database::insert('notifications', [
            'user_id' => $receiverId,
            'sender_id' => $senderId,
            'type' => 'follow',
            'post_id' => null,
            'comment_id' => null,
            'content' => null,
            'is_read' => 0,
            'created_at' => $now,
        ]);
    }

    /** following() —— 关注列表（JOIN users + u.status = 1，10 列顺序逐字保留） */
    public static function fetchFollowingPage(int $followerId, int $offset, int $limit): array
    {
        return Database::fetchAll(
            "SELECT u.id, u.username, u.nickname, u.avatar, u.bio, u.realname_verified, u.created_at, f.created_at AS followed_at, u.vip_level, u.avatar_frame
             FROM follows f
             INNER JOIN users u ON f.following_id = u.id
             WHERE f.follower_id = ? AND u.status = 1
             ORDER BY f.created_at DESC LIMIT ?, ?",
            [$followerId, $offset, $limit]
        );
    }

    /** following() —— 关注列表总数，与列表共用 f.following_id = u.id + u.status = 1 */
    public static function countFollowingList(int $followerId): int
    {
        return (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM follows f INNER JOIN users u ON f.following_id = u.id WHERE f.follower_id = ? AND u.status = 1",
            [$followerId]
        );
    }

    /** followers() —— 粉丝列表（JOIN 方向相反，独立的 10 列清单） */
    public static function fetchFollowersPage(int $followingId, int $offset, int $limit): array
    {
        return Database::fetchAll(
            "SELECT u.id, u.username, u.nickname, u.avatar, u.bio, u.realname_verified, u.created_at, f.created_at AS followed_at, u.vip_level, u.avatar_frame
             FROM follows f
             INNER JOIN users u ON f.follower_id = u.id
             WHERE f.following_id = ? AND u.status = 1
             ORDER BY f.created_at DESC LIMIT ?, ?",
            [$followingId, $offset, $limit]
        );
    }

    /** followers() —— 粉丝列表总数（与 follow() 的裸 COUNT 不是同一条 SQL） */
    public static function countFollowerList(int $followingId): int
    {
        return (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM follows f INNER JOIN users u ON f.follower_id = u.id WHERE f.following_id = ? AND u.status = 1",
            [$followingId]
        );
    }

    /** batchCheckFollowing() —— IN 占位符数量由白名单长度生成，值全部绑定 */
    public static function fetchFollowedIdsIn(int $followerId, array $followingIds): array
    {
        $ids = array_values(array_map('intval', $followingIds));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        return Database::fetchAll(
            "SELECT following_id FROM follows WHERE follower_id = ? AND following_id IN ($placeholders)",
            array_merge([$followerId], $ids)
        );
    }

    /** suggestUsers() —— 已关注名单（无 LIMIT） */
    public static function fetchFollowedIds(int $followerId): array
    {
        return Database::fetchAll(
            "SELECT following_id FROM follows WHERE follower_id = ?",
            [$followerId]
        );
    }

    /**
     * suggestUsers() —— 推荐关注列表。
     *
     * 子查询别名 f 是必须的：follows 表没有 user_id 列，裸写 user_id 会歧义/报错。
     * NOT IN 元素与 LIMIT 全部绑定，where 由私有助手拼装（由白名单 int 生成占位符）。
     *
     * @param array<int,int> $excludeIds
     */
    public static function suggestUsers(array $excludeIds, int $limit): array
    {
        $where = 'u.status = 1';
        $params = [];
        if (!empty($excludeIds)) {
            $ids = array_values(array_map('intval', $excludeIds));
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $where .= " AND u.id NOT IN ($placeholders)";
            $params = $ids;
        }
        return Database::fetchAll(
            "SELECT u.id, u.username, u.nickname, u.avatar, u.bio,
                    (SELECT COUNT(*) FROM follows f WHERE f.following_id = u.id) AS follower_count
             FROM users u
             WHERE {$where}
             ORDER BY follower_count DESC, u.id DESC
             LIMIT ?",
            array_merge($params, [$limit])
        );
    }

    // ==================== 拉黑 ====================

    /** blockUser() / listBlocked() —— blocked_users 未迁移时返回 false（原 catch 降级下沉） */
    public static function blockedUsersTableExists(): bool
    {
        try {
            $value = Database::fetchColumn("SHOW TABLES LIKE 'blocked_users'");
            return !empty($value);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** blockUser() —— 幂等探测 */
    public static function findBlockRow(int $userId, int $blockedUserId): ?array
    {
        return Database::fetchOne(
            "SELECT id FROM blocked_users WHERE user_id = ? AND blocked_user_id = ? LIMIT 1",
            [$userId, $blockedUserId]
        );
    }

    /** isBlocked() —— 两条 SELECT 1 的底层实现（保留 fetchColumn 的 1/false 语义） */
    public static function existsBlockRow(int $userId, int $blockedUserId): mixed
    {
        return Database::fetchColumn(
            "SELECT 1 FROM blocked_users WHERE user_id = ? AND blocked_user_id = ? LIMIT 1",
            [$userId, $blockedUserId]
        );
    }

    /**
     * blockUser() —— 拉黑 + 双向解除关注事务。
     *
     * 入口守卫（自拉黑、表未迁移）下沉到仓储本体；控制器仍负责给前端不同错误文案，
     * 本方法在这些情况下安全返回 false 而不写库。
     */
    public static function blockUser(
        int $userId,
        int $targetId,
        string $now,
        ?bool $tableExists = null
    ): bool {
        if ($userId <= 0 || $targetId <= 0 || $userId === $targetId) {
            return false;
        }
        // 控制器已探测过表存在性时复用其结果，避免多余 SHOW TABLES；内部调用自行探测
        $exists = $tableExists ?? self::blockedUsersTableExists();
        if (!$exists) {
            return false;
        }

        Database::beginTransaction();
        try {
            if (!self::findBlockRow($userId, $targetId)) {
                Database::insert('blocked_users', [
                    'user_id' => $userId,
                    'blocked_user_id' => $targetId,
                    'created_at' => $now,
                ]);
            }
            Database::delete('follows', 'follower_id = ? AND following_id = ?', [$userId, $targetId]);
            Database::delete('follows', 'follower_id = ? AND following_id = ?', [$targetId, $userId]);
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
        return true;
    }

    /** unblockUser() —— 无表时忽略（原控制器 catch 下沉） */
    public static function unblockUser(int $userId, int $targetId): void
    {
        try {
            Database::delete('blocked_users', 'user_id = ? AND blocked_user_id = ?', [$userId, $targetId]);
        } catch (\Throwable $e) {
            // 无表时忽略
        }
    }

    /** listBlocked() —— 黑名单列表（6 列顺序保留） */
    public static function fetchBlockedPage(int $userId, int $offset, int $limit): array
    {
        try {
            return Database::fetchAll(
                "SELECT u.id, u.username, u.nickname, u.avatar, u.bio, b.created_at AS blocked_at
             FROM blocked_users b
             INNER JOIN users u ON b.blocked_user_id = u.id
             WHERE b.user_id = ?
             ORDER BY b.created_at DESC LIMIT ?, ?",
                [$userId, $offset, $limit]
            );
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** listBlocked() —— 黑名单总数 */
    public static function countBlocked(int $userId): int
    {
        try {
            return (int)Database::fetchColumn(
                "SELECT COUNT(*) FROM blocked_users b INNER JOIN users u ON b.blocked_user_id = u.id WHERE b.user_id = ?",
                [$userId]
            );
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * isBlocked() —— 双向拉黑检测；两条查询的异常降级（无表时均 false）下沉。
     *
     * @return array{0:mixed,1:mixed} [blocked, blocked_by]
     */
    public static function checkBlockedState(int $userId, int $targetId): array
    {
        try {
            $blocked = self::existsBlockRow($userId, $targetId);
            $blockedBy = self::existsBlockRow($targetId, $userId);
        } catch (\Throwable $e) {
            $blocked = false;
            $blockedBy = false;
        }
        return [$blocked, $blockedBy];
    }
}
