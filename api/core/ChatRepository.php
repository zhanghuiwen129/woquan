<?php
/**
 * Hublog 即时通讯域数据仓储
 *
 * 承载 ChatController 下沉的全部 SQL、参数绑定与事务边界（会话成员 / 会话 /
 * 消息 / 用户探测 / information_schema 列探测）。字段清单、顺序、别名、
 * JOIN、ORDER BY 与迁移前 HEAD 原文逐字一致。
 *
 * 【相似但不同的查询刻意不合并】
 *  - findMemberId()（SELECT id ... conversation_id + user_id）
 *    ≠ findMemberAllByConversationAndUser()（SELECT *）
 *    ≠ findMemberAllById()（SELECT * ... WHERE id = ?）；
 *  - findGroupRole() JOIN conversations 校验 type='group'，不能与任何成员表查询复用；
 *  - fetchGroupMembers() 的 FIELD(cm.role,...) 属业务排序，原样保留；
 *  - 群成员踢出与解散群是两条独立事务，各自完整包含 begin/commit/rollBack。
 *
 * 入口守卫中属于 SQL/数据探针的部分（pinned 列探测、无表降级）已下沉到仓储本体；
 * 权限与业务错误响应仍由控制器负责。
 */

declare(strict_types=1);

namespace Hublog\Core;

class ChatRepository
{
    /** updateMemberSettings() 允许写入的列（控制器组装，仓储再挡一层） */
    private const MEMBER_SETTING_COLUMNS = ['muted', 'pinned'];

    // ==================== 会话成员 ====================

    /** deleteConversation() / restoreConversation() —— 成员身份探测（SELECT id） */
    public static function findMemberId(int $conversationId, int $userId): ?array
    {
        return Database::fetchOne(
            "SELECT id FROM conversation_members WHERE conversation_id = ? AND user_id = ? LIMIT 1",
            [$conversationId, $userId]
        );
    }

    /** updateMemberSettings() —— 成员完整行（SELECT *，与 SELECT id 版本不同） */
    public static function findMemberAllByConversationAndUser(int $conversationId, int $userId): ?array
    {
        return Database::fetchOne(
            "SELECT * FROM conversation_members WHERE conversation_id = ? AND user_id = ? LIMIT 1",
            [$conversationId, $userId]
        );
    }

    /** updateMemberSettings() —— 按成员主键回读（SELECT *） */
    public static function findMemberAllById(int $memberId): ?array
    {
        return Database::fetchOne(
            "SELECT * FROM conversation_members WHERE id = ? LIMIT 1",
            [$memberId]
        );
    }

    /** deleteConversation() 两条分支共用的软删标记 */
    public static function markMemberDeleted(int $conversationId, int $userId, string $now): void
    {
        Database::update(
            'conversation_members',
            ['deleted_at' => $now],
            'conversation_id = ? AND user_id = ?',
            [$conversationId, $userId]
        );
    }

    /** 成员行物理删工具：仅保留给历史调用/测试；V99 起 deleteConversation() 的 hard 分支已改为缺列返回 501，不再调用它 */
    public static function deleteMember(int $conversationId, int $userId): void
    {
        Database::delete(
            'conversation_members',
            'conversation_id = ? AND user_id = ?',
            [$conversationId, $userId]
        );
    }

    /** restoreConversation() —— 回收站恢复成员行 */
    public static function clearMemberDeleted(int $conversationId, int $userId): void
    {
        Database::update(
            'conversation_members',
            ['deleted_at' => null],
            'conversation_id = ? AND user_id = ?',
            [$conversationId, $userId]
        );
    }

    /** updateMemberSettings() —— information_schema.COLUMNS 探针（无权限/老库返回 false） */
    public static function pinnedColumnExists(): bool
    {
        try {
            $value = Database::fetchColumn(
                "SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'conversation_members' AND COLUMN_NAME = 'pinned' LIMIT 1"
            );
            return !empty($value);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** updateMemberSettings() —— 动态列白名单 + 正则双校验，字段名不进参数数组 */
    private static function assertSettingColumns(array $updates): void
    {
        foreach (array_keys($updates) as $column) {
            if (!is_string($column)
                || !preg_match('/^[a-z_]+$/', $column)
                || !in_array($column, self::MEMBER_SETTING_COLUMNS, true)) {
                throw new \InvalidArgumentException('非法会话设置字段');
            }
        }
    }

    /** updateMemberSettings() —— 按成员主键更新 muted/pinned */
    public static function updateMemberSettings(int $memberId, array $updates): void
    {
        self::assertSettingColumns($updates);
        Database::update('conversation_members', $updates, 'id = ?', [$memberId]);
    }

    /** groupInfo() —— 当前用户禁言到期时间 */
    public static function findMemberMutedUntil(int $conversationId, int $userId): ?array
    {
        return Database::fetchOne(
            "SELECT muted_until FROM conversation_members WHERE conversation_id = ? AND user_id = ? LIMIT 1",
            [$conversationId, $userId]
        );
    }

    /** groupRole() —— JOIN conversations 强校验 type='group'（私聊成员不得被当群成员） */
    public static function findGroupRole(int $conversationId, int $userId): ?string
    {
        $row = Database::fetchOne(
            "SELECT cm.role FROM conversation_members cm
             INNER JOIN conversations c ON c.id = cm.conversation_id
             WHERE cm.conversation_id = ? AND cm.user_id = ? AND c.type = 'group' LIMIT 1",
            [$conversationId, $userId]
        );
        return $row ? (string)$row['role'] : null;
    }

    /** groupMembers() —— 群成员（FIELD 业务排序 + cm.id ASC 逐字保留） */
    public static function fetchGroupMembers(int $conversationId): array
    {
        return Database::fetchAll(
            "SELECT cm.user_id, cm.role, cm.muted_until, cm.joined_at,
                    u.username, u.nickname, u.avatar
             FROM conversation_members cm
             INNER JOIN users u ON u.id = cm.user_id
             WHERE cm.conversation_id = ?
             ORDER BY FIELD(cm.role, 'owner', 'admin', 'member'), cm.id ASC",
            [$conversationId]
        );
    }

    /** typing() —— 同会话其他成员（SSE 广播用） */
    public static function fetchOtherMemberUserIds(int $conversationId, int $userId): array
    {
        return Database::fetchAll(
            "SELECT user_id FROM conversation_members WHERE conversation_id = ? AND user_id != ?",
            [$conversationId, $userId]
        );
    }

    /** groupMute() —— 群内禁言 / 解禁 */
    public static function muteMember(int $conversationId, int $targetId, ?string $mutedUntil): void
    {
        Database::update(
            'conversation_members',
            ['muted_until' => $mutedUntil],
            'conversation_id = ? AND user_id = ?',
            [$conversationId, $targetId]
        );
    }

    /**
     * groupKick() —— 移出群聊事务：删成员 → 重新计数 → 回写 member_count。
     *
     * 返回剩余成员数（原控制器用 $left 构造响应）。异常时 rollBack + throw。
     */
    public static function kickMember(int $conversationId, int $targetId): int
    {
        Database::beginTransaction();
        try {
            Database::delete(
                'conversation_members',
                'conversation_id = ? AND user_id = ?',
                [$conversationId, $targetId]
            );
            $left = (int)Database::fetchColumn(
                "SELECT COUNT(*) FROM conversation_members WHERE conversation_id = ?",
                [$conversationId]
            );
            Database::update('conversations', ['member_count' => max($left, 0)], 'id = ?', [$conversationId]);
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
        return $left;
    }

    /**
     * groupDissolve() —— 解散群事务：只清成员与会话行，消息行永久保留。
     *
     * V99：消息由 ChatService::dissolveGroupAsOwner() 先做软删并留审计，
     * 这里不再碰 messages —— 后台仍能按 conversation_id 查到已解散群的消息。
     * 原事务的 commit / rollBack 边界逐字保留。
     */
    public static function dissolveMembersAndConversation(int $conversationId): void
    {
        Database::beginTransaction();
        try {
            Database::delete('conversation_members', 'conversation_id = ?', [$conversationId]);
            Database::delete('conversations', 'id = ?', [$conversationId]);
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    // ==================== 会话 ====================

    /**
     * send() —— 发言前的会话/权限聚合查询。
     *
     * 五个 ? 分别绑定 my_muted_until、peer_id、peer_allow_stranger 子查询、
     * is_fans 子查询的 follower_id 与 peer 子查询，最后才是 conversation_id。
     * follows 子查询使用别名 f，避免 follows.user_id 不存在的歧义。
     */
    public static function findConversationForSend(int $userId, int $conversationId): ?array
    {
        return Database::fetchOne(
            "SELECT c.id, c.type, c.creator_id,
                    (SELECT cm.muted_until FROM conversation_members cm
                      WHERE cm.conversation_id = c.id AND cm.user_id = ? LIMIT 1) AS my_muted_until,
                    (SELECT cm.user_id FROM conversation_members cm
                      WHERE cm.conversation_id = c.id AND cm.user_id != ? LIMIT 1) AS peer_id,
                    (SELECT u.allow_stranger_msg FROM users u WHERE u.id = (
                        SELECT cm.user_id FROM conversation_members cm
                         WHERE cm.conversation_id = c.id AND cm.user_id != ? LIMIT 1)) AS peer_allow_stranger,
                    (SELECT COUNT(*) FROM follows f WHERE f.follower_id = ? AND f.following_id = (
                        SELECT cm.user_id FROM conversation_members cm
                         WHERE cm.conversation_id = c.id AND cm.user_id != ? LIMIT 1)) AS is_fans
             FROM conversations c WHERE c.id = ? LIMIT 1",
            [$userId, $userId, $userId, $userId, $userId, $conversationId]
        );
    }

    /** groupInfo() —— 群信息（8 列 + 子查询字段清单逐字保留） */
    public static function findGroupInfo(int $conversationId): ?array
    {
        return Database::fetchOne(
            "SELECT c.id, c.type, c.title, c.circle_id, c.announcement, c.announcement_at, c.member_count, c.avatar
             FROM conversations c WHERE c.id = ? LIMIT 1",
            [$conversationId]
        );
    }

    /** groupInfo() —— 圈子当前封面（群头像回退） */
    public static function findCircleCover(int $circleId): mixed
    {
        return Database::fetchColumn(
            "SELECT cover FROM circles WHERE id = ? LIMIT 1",
            [$circleId]
        );
    }

    /** groupAnnounce() —— 发布 / 清空群公告 */
    public static function updateAnnouncement(int $conversationId, ?string $content, string $now): void
    {
        Database::update(
            'conversations',
            ['announcement' => $content !== '' ? $content : null, 'announcement_at' => $now],
            'id = ?',
            [$conversationId]
        );
    }

    // ==================== 消息 ====================

    /**
     * markRead() —— 单条已读回执更新。
     *
     * 原控制器以 try/catch 吞异常（老库无 messages.is_read），降级下沉到仓储本体；
     * 控制器仍负责 403 权限守卫与响应包装。
     */
    public static function tryMarkIncomingMessagesRead(int $conversationId, int $userId, int $messageId): void
    {
        try {
            Database::update(
                'messages',
                ['is_read' => 1],
                'conversation_id = ? AND sender_id != ? AND id <= ? AND is_read = 0',
                [$conversationId, $userId, $messageId]
            );
        } catch (\Throwable $e) {
            // V47 迁移未执行时 messages.is_read 不存在，忽略即可
        }
    }

    /**
     * markRead 越界守卫：message_id 必须真实存在于该会话。
     *
     * S10 已补「调用者是会话成员」校验，但仍缺「message_id 属于该会话」：
     * 会话成员传一个别的会话的大 id / 超大 id，会把本会话 last_read_message_id
     * 顶到高位，导致本会话全部未读被清零。这里按 (id, conversation_id) 精确探测，
     * 兼顾软删消息（deleted_at 不参与判断，位点语义覆盖全会话）。
     */
    public static function messageBelongsToConversation(int $messageId, int $conversationId): bool
    {
        if ($messageId <= 0 || $conversationId <= 0) {
            return false;
        }
        $row = Database::fetchOne(
            "SELECT id FROM messages WHERE id = ? AND conversation_id = ? LIMIT 1",
            [$messageId, $conversationId]
        );
        return $row !== false && $row !== null;
    }

    /** recallMessage() —— 撤回前的消息探测（5 列） */
    public static function findMessageForRecall(int $messageId): ?array
    {
        return Database::fetchOne(
            "SELECT id, conversation_id, sender_id, created_at, recalled_at FROM messages WHERE id = ? LIMIT 1",
            [$messageId]
        );
    }

    /** recallMessage() —— 软删标记 recalled_at */
    public static function recallMessage(int $messageId, string $now): void
    {
        Database::update(
            'messages',
            ['recalled_at' => $now],
            'id = ?',
            [$messageId]
        );
    }
}
