<?php

declare(strict_types=1);

namespace Hublog\Core;

/**
 * 动态可见性的唯一权威策略（T0）。
 *
 * 所有对外暴露动态的入口（信息流/搜索/话题/详情/SSR/热门榜/转发/写接口/收藏）
 * 必须复用本类，禁止各自实现可见性判断，避免遗漏导致私密内容泄露。
 *
 * 四态语义：
 *   public  —— 所有人可见（含游客）
 *   circle  —— 有效圈子成员可见；付费圈还要求订阅有效
 *   friend  —— 单向关注：当前用户关注了作者（f.follower_id=当前用户）
 *   private —— 仅作者本人可见
 *
 * 默认拒绝：缺失/非法 visibility 一律不可见（不兼容旧数据的 ?? 'public'）。
 */
final class VisibilityPolicy
{
    /** 合法可见范围枚举（顺序即前端选项顺序，勿改） */
    public const TYPES = ['public', 'circle', 'friend', 'private'];

    /**
     * 生成列表查询用的 WHERE 片段与绑定参数。
     *
     * 游客只认 public；登录用户放行自己、public、有效圈子成员、单向关注的好友。
     *
     * @param int $userId 当前用户 ID，<=0 视为游客
     * @return array{0:string,1:array<int,mixed>} [SQL 片段, 参数数组]
     */
    public static function where(int $userId): array
    {
        if ($userId <= 0) {
            return ["(p.visibility = 'public')", []];
        }

        $now = date('Y-m-d H:i:s');
        return [
            "(p.user_id = ? OR p.visibility = 'public'
 OR (p.visibility = 'circle' AND p.circle_id IN (
   SELECT cm.circle_id FROM circle_members cm
   JOIN circles c ON c.id = cm.circle_id AND c.status = 1
   LEFT JOIN circle_subscribes cs ON cs.circle_id = cm.circle_id AND cs.user_id = cm.user_id
   WHERE cm.user_id = ? AND cm.status = 1
     AND ( c.pay_type <> 2 OR c.owner_id = ?
           OR (cs.status = 1 AND (cs.expire_at IS NULL OR cs.expire_at > ?)) )
 ))
 OR (p.visibility = 'friend' AND p.user_id IN (
   SELECT f.following_id FROM follows f WHERE f.follower_id = ?
 )))",
            [$userId, $userId, $userId, $now, $userId]
        ];
    }

    /**
     * 判断单条动态对当前用户是否可见（默认拒绝）。
     *
     * @param array<string,mixed> $post   含 visibility/user_id/circle_id 的 posts 行
     * @param int                 $userId 当前用户 ID，<=0 视为游客
     */
    public static function canView(array $post, int $userId): bool
    {
        $visibility = (string)($post['visibility'] ?? '');
        if (!in_array($visibility, self::TYPES, true)) {
            return false;
        }

        $ownerId = (int)($post['user_id'] ?? 0);
        // 作者本人始终可读自己的内容（含待审帖）
        if ($userId > 0 && $ownerId > 0 && $userId === $ownerId) {
            return true;
        }
        if ($visibility === 'public') {
            return true;
        }
        if ($visibility === 'private') {
            return false;
        }
        if ($visibility === 'circle') {
            return self::isCircleVisible((int)($post['circle_id'] ?? 0), $userId);
        }
        return self::isFollower($userId, $ownerId);
    }

    /**
     * 付费感知的圈子可见性（有效成员 + 圈子启用 + 订阅有效）。
     */
    public static function isCircleVisible(int $circleId, int $userId): bool
    {
        if ($circleId <= 0 || $userId <= 0) {
            return false;
        }
        $now = date('Y-m-d H:i:s');
        $value = Database::fetchColumn(
            "SELECT 1 FROM circle_members cm
             JOIN circles c ON c.id = cm.circle_id AND c.status = 1
             LEFT JOIN circle_subscribes cs ON cs.circle_id = cm.circle_id AND cs.user_id = cm.user_id
             WHERE cm.circle_id = ? AND cm.user_id = ? AND cm.status = 1
               AND ( c.pay_type <> 2 OR c.owner_id = ?
                     OR (cs.status = 1 AND (cs.expire_at IS NULL OR cs.expire_at > ?)) )
             LIMIT 1",
            [$circleId, $userId, $userId, $now]
        );
        // fetchColumn 无行返回 false（不是 null），必须两个都排除
        return $value !== false && $value !== null;
    }

    /**
     * 单向好友判定：当前用户是否关注了作者。
     */
    public static function isFollower(int $followerId, int $followingId): bool
    {
        if ($followerId <= 0 || $followingId <= 0) {
            return false;
        }
        $value = Database::fetchColumn(
            "SELECT 1 FROM follows WHERE follower_id = ? AND following_id = ? LIMIT 1",
            [$followerId, $followingId]
        );
        return $value !== false && $value !== null;
    }
}
