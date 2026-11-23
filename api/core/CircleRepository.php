<?php
declare(strict_types=1);

/**
 * Hublog 圈子数据仓储
 *
 * 只承载 CircleController 下沉的 SQL 与事务：返回原始行结构，不做权限判定、
 * 响应包装、媒体 URL 处理——这些仍留在控制器。迁移约定见 docs/内联SQL收敛规范.md。
 *
 * 约定（与原控制器逐条对齐，行为不变）：
 *  - 所有值一律通过 ? 占位符绑定，禁止字符串拼接值；
 *  - 分页统一 (offset, limit) 顺序，字段顺序/表别名与原 SQL 一致；
 *  - 涉及 posts 的行查询带 p.post_type；COUNT 聚合保持 COUNT(*)；
 *  - 软删/下架条件（status / removed_at / visibility）留在 SQL 里，不放到 PHP 过滤。
 */

namespace Hublog\Core;

class CircleRepository
{
    // ===== 列表 / 详情（对应 list / show） =====

    /** 公开列表 WHERE：status=1 + public + 未下架 + 可选关键词 */
    private static function publicListWhere(string $keyword): array
    {
        $where = "WHERE status = 1 AND visibility = 'public' AND removed_at IS NULL";
        $params = [];
        if ($keyword !== '') {
            $where .= ' AND (name LIKE ? OR description LIKE ?)';
            $kw = '%' . $keyword . '%';
            $params[] = $kw;
            $params[] = $kw;
        }
        return [$where, $params];
    }

    public static function fetchPublicList(string $keyword, int $offset, int $limit): array
    {
        [$where, $params] = self::publicListWhere($keyword);
        return Database::fetchAll(
            "SELECT id, name, slug, description, cover, owner_id, member_count, post_count,
                    join_mode, post_permission, visibility, created_at
             FROM circles {$where}
             ORDER BY member_count DESC, id DESC
             LIMIT ?, ?",
            array_merge($params, [$offset, $limit])
        );
    }

    public static function countPublic(string $keyword): int
    {
        [$where, $params] = self::publicListWhere($keyword);
        return (int)Database::fetchColumn("SELECT COUNT(*) FROM circles {$where}", $params);
    }

    /** 详情：仅未下架（removed_at IS NULL）且 status=1，按 id / slug 两种入口 */
    public static function findVisibleById(int $id): ?array
    {
        return Database::fetchOne(
            "SELECT * FROM circles WHERE id = ? AND status = 1 AND removed_at IS NULL",
            [$id]
        ) ?: null;
    }

    public static function findVisibleBySlug(string $slug): ?array
    {
        return Database::fetchOne(
            "SELECT * FROM circles WHERE slug = ? AND status = 1 AND removed_at IS NULL",
            [$slug]
        ) ?: null;
    }

    /** 详情用圈主公开字段（不含敏感列） */
    public static function findOwner(int $ownerId): ?array
    {
        return Database::fetchOne(
            "SELECT id, username, nickname, avatar, role FROM users WHERE id = ?",
            [$ownerId]
        ) ?: null;
    }

    /** 详情用成员行：role + status（status=2 表示圈内黑名单） */
    public static function getMemberRow(int $circleId, int $userId): ?array
    {
        return Database::fetchOne(
            "SELECT role, status FROM circle_members WHERE circle_id = ? AND user_id = ? LIMIT 1",
            [$circleId, $userId]
        ) ?: null;
    }

    /** 任意状态的圈子行：create/update/delete 的回显与校验用，保持原样无 LIMIT */
    public static function findAnyById(int $id): ?array
    {
        return Database::fetchOne("SELECT * FROM circles WHERE id = ?", [$id]) ?: null;
    }

    // ===== 我加入的圈子（对应 my） =====

    public static function fetchJoinedCircles(int $userId): array
    {
        return Database::fetchAll(
            "SELECT c.id, c.name, c.slug, c.description, c.cover, c.member_count, c.post_count,
                    c.join_mode, c.post_permission, c.visibility,
                    cm.role AS my_role, cm.joined_at
             FROM circle_members cm
             INNER JOIN circles c ON cm.circle_id = c.id
             WHERE cm.user_id = ? AND c.status = 1
             ORDER BY cm.joined_at DESC",
            [$userId]
        );
    }

    // ===== 成员列表（对应 members） =====

    public static function fetchMembers(int $circleId, int $offset, int $limit): array
    {
        return Database::fetchAll(
            "SELECT cm.user_id, cm.role, cm.joined_at, cm.status,
                    u.username, u.nickname, u.avatar, u.role AS user_role
             FROM circle_members cm
             INNER JOIN users u ON cm.user_id = u.id
             WHERE cm.circle_id = ? AND cm.status = 1
             ORDER BY FIELD(cm.role, 'owner', 'admin', 'member'), cm.joined_at ASC
             LIMIT ?, ?",
            [$circleId, $offset, $limit]
        );
    }

    public static function countMembers(int $circleId): int
    {
        return (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM circle_members WHERE circle_id = ? AND status = 1",
            [$circleId]
        );
    }

    // ===== 圈子动态（对应 posts） =====

    /** 圈内动态 ID 列表。可见性统一走权威策略；行查询带 p.post_type */
    public static function fetchCirclePostIds(int $circleId, int $userId, int $offset, int $limit): array
    {
        [$visWhere, $visParams] = VisibilityPolicy::where($userId);
        return Database::fetchAll(
            "SELECT p.id, p.post_type FROM posts p WHERE p.circle_id = ? AND p.status = 1 AND {$visWhere}
 ORDER BY p.is_pinned DESC, p.pinned_at DESC, p.created_at DESC LIMIT ?, ?",
            array_merge([$circleId], $visParams, [$offset, $limit])
        );
    }

    public static function countCirclePosts(int $circleId, int $userId): int
    {
        [$visWhere, $visParams] = VisibilityPolicy::where($userId);
        return (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM posts p WHERE p.circle_id = ? AND p.status = 1 AND {$visWhere}",
            array_merge([$circleId], $visParams)
        );
    }

    public static function findCircleBasic(int $id): ?array
    {
        return Database::fetchOne(
            "SELECT id, name, slug, visibility FROM circles WHERE id = ? AND status = 1",
            [$id]
        ) ?: null;
    }

    // ===== 创建 / 配额（对应 create） =====

    public static function findVipLevel(int $userId): ?array
    {
        return Database::fetchOne("SELECT vip_level FROM users WHERE id = ?", [$userId]) ?: null;
    }

    public static function countOwnedActive(int $userId): int
    {
        return (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM circles WHERE owner_id = ? AND status = 1",
            [$userId]
        );
    }

    /** 建圈事务：INSERT circles + 圈主成员行，返回新圈子 ID */
    public static function createCircle(
        string $name,
        string $slug,
        ?string $description,
        string $cover,
        int $ownerId,
        string $joinMode,
        string $postPermission,
        string $visibility,
        ?string $announcement,
        ?string $tags,
        int $postReview
    ): int {
        Database::beginTransaction();
        try {
            $now = date('Y-m-d H:i:s');
            Database::getInstance()->prepare(
                "INSERT INTO circles (name, slug, description, cover, owner_id, member_count, post_count,
                                      join_mode, post_permission, visibility, announcement, announcement_at, tags, post_review,
                                      status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, 1, 0, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)"
            )->execute([
                $name, $slug, $description, $cover, $ownerId,
                $joinMode, $postPermission, $visibility,
                $announcement, $announcement !== null ? $now : null,
                $tags, $postReview, $now, $now,
            ]);

            $circleId = (int)Database::getInstance()->lastInsertId();
            Database::getInstance()->prepare(
                "INSERT INTO circle_members (circle_id, user_id, role, joined_at) VALUES (?, ?, 'owner', ?)"
            )->execute([$circleId, $ownerId, $now]);

            Database::commit();
            return $circleId;
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    /** 动态列更新：字段名来自控制器白名单，这里再挡一层防注入 */
    public static function updateCircleFields(int $id, array $fields, array $params): void
    {
        foreach ($fields as $field) {
            if (!is_string($field) || !preg_match('/^[a-z_]+ = \?$/', $field)) {
                throw new \InvalidArgumentException('非法更新字段');
            }
        }
        $params[] = $id;
        Database::getInstance()->prepare(
            "UPDATE circles SET " . implode(', ', $fields) . " WHERE id = ?"
        )->execute($params);
    }

    /** 删圈事务：查群 → 群会话 → 成员 → posts.circle_id 置 NULL → 圈子，全部绑定参数 */
    public static function deleteCircleCascade(int $circleId): void
    {
        Database::beginTransaction();
        try {
            $group = self::findGroupIdByCircle($circleId);
            $groupId = $group ? (int)$group['id'] : 0;
            if ($groupId > 0) {
                Database::getInstance()->prepare(
                    "DELETE FROM conversation_members WHERE conversation_id = ?"
                )->execute([$groupId]);
                Database::getInstance()->prepare(
                    "DELETE FROM conversations WHERE id = ?"
                )->execute([$groupId]);
            }
            Database::getInstance()->prepare(
                "DELETE FROM circle_members WHERE circle_id = ?"
            )->execute([$circleId]);
            Database::getInstance()->prepare(
                "UPDATE posts SET circle_id = NULL WHERE circle_id = ?"
            )->execute([$circleId]);
            Database::getInstance()->prepare(
                "DELETE FROM circles WHERE id = ?"
            )->execute([$circleId]);
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    // ===== 加入 / 申请（对应 join / myJoinRequests / pendingRequests / approve / reject） =====

    public static function findForJoin(int $id): ?array
    {
        return Database::fetchOne(
            "SELECT id, owner_id, member_count, status, join_mode, pay_type, pay_vip_level FROM circles WHERE id = ?",
            [$id]
        ) ?: null;
    }

    public static function getMemberStatus(int $circleId, int $userId): ?array
    {
        return Database::fetchOne(
            "SELECT status FROM circle_members WHERE circle_id = ? AND user_id = ? LIMIT 1",
            [$circleId, $userId]
        ) ?: null;
    }

    public static function memberExists(int $circleId, int $userId): bool
    {
        return (bool)Database::fetchOne(
            "SELECT id FROM circle_members WHERE circle_id = ? AND user_id = ?",
            [$circleId, $userId]
        );
    }

    public static function findPendingJoinRequest(int $circleId, int $userId): ?array
    {
        return Database::fetchOne(
            "SELECT id, status FROM circle_join_requests
             WHERE circle_id = ? AND user_id = ? AND status = 'pending'
             ORDER BY id DESC LIMIT 1",
            [$circleId, $userId]
        ) ?: null;
    }

    public static function findPendingRequestId(int $circleId, int $userId): ?array
    {
        return Database::fetchOne(
            "SELECT id FROM circle_join_requests
             WHERE circle_id = ? AND user_id = ? AND status = 'pending'
             ORDER BY id DESC LIMIT 1",
            [$circleId, $userId]
        ) ?: null;
    }

    public static function insertJoinRequest(int $circleId, int $userId, string $message): void
    {
        Database::getInstance()->prepare(
            "INSERT INTO circle_join_requests (circle_id, user_id, status, message, created_at)
             VALUES (?, ?, 'pending', ?, NOW())"
        )->execute([$circleId, $userId, $message]);
    }

    /** free 模式直接入圈：两条语句保持原有非事务语义 */
    public static function joinFree(int $circleId, int $userId): void
    {
        Database::getInstance()->prepare(
            "INSERT INTO circle_members (circle_id, user_id, role, joined_at) VALUES (?, ?, 'member', NOW())"
        )->execute([$circleId, $userId]);
        Database::getInstance()->prepare(
            "UPDATE circles SET member_count = member_count + 1 WHERE id = ?"
        )->execute([$circleId]);
    }

    public static function fetchMyJoinRequests(int $userId): array
    {
        return Database::fetchAll(
            "SELECT r.id, r.circle_id, r.status, r.message, r.created_at, r.reviewed_at,
                    c.name AS circle_name, c.slug AS circle_slug, c.cover AS circle_cover
             FROM circle_join_requests r
             INNER JOIN circles c ON r.circle_id = c.id
             WHERE r.user_id = ?
             ORDER BY r.id DESC
             LIMIT 100",
            [$userId]
        );
    }

    public static function fetchPendingRequests(int $circleId, int $offset, int $limit): array
    {
        return Database::fetchAll(
            "SELECT r.id, r.user_id, r.status, r.message, r.created_at, r.reviewed_at,
                    u.username, u.nickname, u.avatar
             FROM circle_join_requests r
             INNER JOIN users u ON r.user_id = u.id
             WHERE r.circle_id = ? AND r.status = 'pending'
             ORDER BY r.id DESC
             LIMIT ?, ?",
            [$circleId, $offset, $limit]
        );
    }

    public static function countPendingRequests(int $circleId): int
    {
        return (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM circle_join_requests WHERE circle_id = ? AND status = 'pending'",
            [$circleId]
        );
    }

    public static function markRequestApproved(int $reviewerId, int $requestId): void
    {
        Database::getInstance()->prepare(
            "UPDATE circle_join_requests SET status='approved', reviewer_id=?, reviewed_at=NOW() WHERE id=?"
        )->execute([$reviewerId, $requestId]);
    }

    public static function markRequestRejected(int $reviewerId, int $requestId): void
    {
        Database::getInstance()->prepare(
            "UPDATE circle_join_requests SET status='rejected', reviewer_id=?, reviewed_at=NOW() WHERE id=?"
        )->execute([$reviewerId, $requestId]);
    }

    /** 审批通过事务：入成员表 + member_count+1 + 申请置 approved */
    public static function approveAndAddMember(
        int $circleId,
        int $applyUserId,
        int $reviewerId,
        int $requestId
    ): void {
        Database::beginTransaction();
        try {
            Database::getInstance()->prepare(
                "INSERT INTO circle_members (circle_id, user_id, role, joined_at) VALUES (?, ?, 'member', NOW())"
            )->execute([$circleId, $applyUserId]);
            Database::getInstance()->prepare(
                "UPDATE circles SET member_count = member_count + 1 WHERE id = ?"
            )->execute([$circleId]);
            Database::getInstance()->prepare(
                "UPDATE circle_join_requests SET status='approved', reviewer_id=?, reviewed_at=NOW() WHERE id=?"
            )->execute([$reviewerId, $requestId]);
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    // ===== 公告 / 成员治理 / 内容治理 =====

    public static function updateAnnouncement(int $id, ?string $content, string $now): void
    {
        Database::query(
            "UPDATE circles SET announcement = ?, announcement_at = ? WHERE id = ?",
            [$content, $now, $id]
        );
    }

    public static function findCircleOwner(int $id): ?array
    {
        return Database::fetchOne("SELECT id, owner_id FROM circles WHERE id = ? LIMIT 1", [$id]) ?: null;
    }

    public static function findTargetMemberRole(int $circleId, int $userId): ?array
    {
        return Database::fetchOne(
            "SELECT role FROM circle_members WHERE circle_id = ? AND user_id = ? LIMIT 1",
            [$circleId, $userId]
        ) ?: null;
    }

    public static function findTargetMemberId(int $circleId, int $userId): ?array
    {
        return Database::fetchOne(
            "SELECT id FROM circle_members WHERE circle_id = ? AND user_id = ? LIMIT 1",
            [$circleId, $userId]
        ) ?: null;
    }

    public static function removeMember(int $circleId, int $targetId): void
    {
        Database::beginTransaction();
        try {
            Database::query(
                "DELETE FROM circle_members WHERE circle_id = ? AND user_id = ?",
                [$circleId, $targetId]
            );
            Database::query(
                "UPDATE circles SET member_count = GREATEST(member_count - 1, 0) WHERE id = ?",
                [$circleId]
            );
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    public static function setMemberRole(int $circleId, int $targetId, string $role): void
    {
        Database::query(
            "UPDATE circle_members SET role = ? WHERE circle_id = ? AND user_id = ?",
            [$role, $circleId, $targetId]
        );
    }

    public static function setMutedUntil(?string $until, int $circleId, int $targetId): void
    {
        Database::query(
            "UPDATE circle_members SET muted_until = ? WHERE circle_id = ? AND user_id = ?",
            [$until, $circleId, $targetId]
        );
    }

    public static function setMemberBanned(int $status, int $circleId, int $targetId): void
    {
        Database::query(
            "UPDATE circle_members SET status = ? WHERE circle_id = ? AND user_id = ?",
            [$status, $circleId, $targetId]
        );
    }

    public static function fetchBlacklist(int $circleId): array
    {
        return Database::fetchAll(
            "SELECT cm.user_id, cm.role, cm.joined_at,
                    u.username, u.nickname, u.avatar
             FROM circle_members cm
             INNER JOIN users u ON cm.user_id = u.id
             WHERE cm.circle_id = ? AND cm.status = 2
             ORDER BY cm.user_id ASC",
            [$circleId]
        );
    }

    /** 圈内动态存在性判定。posts 行查询带 p.post_type；只多返回一个未使用列，不改变行为 */
    public static function findPostInCircle(int $postId, int $circleId): ?array
    {
        return Database::fetchOne(
            "SELECT p.id, p.user_id, p.post_type
             FROM posts p
             WHERE p.id = ? AND (p.circle_id = ? OR p.user_id = ?) LIMIT 1",
            [$postId, $circleId, $circleId]
        ) ?: null;
    }

    public static function updatePost(int $postId, array $data): void
    {
        Database::update('posts', $data, 'id = ?', [$postId]);
    }

    public static function leaveCircle(int $circleId, int $userId): void
    {
        Database::getInstance()->prepare(
            "DELETE FROM circle_members WHERE circle_id = ? AND user_id = ?"
        )->execute([$circleId, $userId]);
        Database::getInstance()->prepare(
            "UPDATE circles SET member_count = GREATEST(member_count - 1, 0) WHERE id = ?"
        )->execute([$circleId]);
    }

    // ===== 圈子群聊联动 =====

    public static function findCircleWithStatus(int $id): ?array
    {
        return Database::fetchOne(
            "SELECT id, name, owner_id, member_count, status, join_mode, pay_type, pay_vip_level
             FROM circles WHERE id = ?",
            [$id]
        ) ?: null;
    }

    public static function findCircleName(int $circleId): ?array
    {
        return Database::fetchOne("SELECT name FROM circles WHERE id = ?", [$circleId]) ?: null;
    }

    public static function findCircleCover(int $circleId)
    {
        return Database::fetchColumn("SELECT cover FROM circles WHERE id = ? LIMIT 1", [$circleId]);
    }

    public static function findGroupIdByCircle(int $circleId): ?array
    {
        return Database::fetchOne(
            "SELECT id FROM conversations WHERE circle_id = ? AND type = 'group' LIMIT 1",
            [$circleId]
        ) ?: null;
    }

    /** 建群（幂等）：唯一键 1062 冲突时回查已有群，逻辑从控制器整体下沉 */
    public static function ensureCircleGroup(int $circleId, string $name): int
    {
        $row = self::findGroupIdByCircle($circleId);
        if ($row) {
            return (int)$row['id'];
        }
        $now = date('Y-m-d H:i:s');
        $cover = self::findCircleCover($circleId);
        try {
            Database::getInstance()->prepare(
                "INSERT INTO conversations (type, title, avatar, creator_id, member_count, circle_id,
                                            created_at, updated_at, last_message_at)
                 VALUES ('group', ?, ?, 0, 0, ?, ?, ?, ?)"
            )->execute([$name, (string)($cover ?: ''), $circleId, $now, $now, $now]);
            return (int)Database::getInstance()->lastInsertId();
        } catch (\PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                $existing = Database::fetchColumn(
                    "SELECT id FROM conversations WHERE circle_id = ? AND type = 'group' ORDER BY id ASC LIMIT 1",
                    [$circleId]
                );
                if ($existing) {
                    return (int)$existing;
                }
            }
            throw $e;
        }
    }

    public static function findConversationMemberId(int $groupId, int $userId): ?array
    {
        return Database::fetchOne(
            "SELECT id FROM conversation_members WHERE conversation_id = ? AND user_id = ? LIMIT 1",
            [$groupId, $userId]
        ) ?: null;
    }

    public static function updateConversationMemberRole(int $groupId, int $userId, string $role): void
    {
        Database::getInstance()->prepare(
            "UPDATE conversation_members SET role = ? WHERE conversation_id = ? AND user_id = ?"
        )->execute([$role, $groupId, $userId]);
    }

    /** INSERT IGNORE，返回真实插入行数（0 表示并发下已被别人插入） */
    public static function insertGroupMemberIgnore(int $groupId, int $userId, string $role): int
    {
        $ins = Database::getInstance()->prepare(
            "INSERT IGNORE INTO conversation_members (conversation_id, user_id, role, joined_at, created_at) VALUES (?, ?, ?, NOW(), NOW())"
        );
        $ins->execute([$groupId, $userId, $role]);
        return $ins->rowCount();
    }

    public static function incrementConversationMemberCount(int $groupId): void
    {
        Database::getInstance()->prepare(
            "UPDATE conversations SET member_count = member_count + 1 WHERE id = ?"
        )->execute([$groupId]);
    }

    public static function deleteConversationMember(int $groupId, int $userId): void
    {
        Database::getInstance()->prepare(
            "DELETE FROM conversation_members WHERE conversation_id = ? AND user_id = ?"
        )->execute([$groupId, $userId]);
    }

    public static function countConversationMembers(int $groupId): int
    {
        return (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM conversation_members WHERE conversation_id = ?",
            [$groupId]
        );
    }

    public static function deleteConversation(int $groupId): void
    {
        Database::getInstance()->prepare("DELETE FROM conversations WHERE id = ?")->execute([$groupId]);
    }

    public static function setConversationMemberCount(int $groupId, int $count): void
    {
        Database::getInstance()->prepare(
            "UPDATE conversations SET member_count = ? WHERE id = ?"
        )->execute([$count, $groupId]);
    }

    // ===== 可见性 / 辅助 =====

    public static function findForGate(int $circleId): ?array
    {
        return Database::fetchOne(
            "SELECT id, owner_id, visibility, pay_type FROM circles WHERE id = ? AND status = 1",
            [$circleId]
        ) ?: null;
    }

    /** 成员角色（无 LIMIT，与原私有方法一致）；非登录用户直接返回 null */
    public static function getMemberRole(int $circleId, int $userId): ?string
    {
        if ($userId <= 0) {
            return null;
        }
        $row = Database::fetchOne(
            "SELECT role FROM circle_members WHERE circle_id = ? AND user_id = ?",
            [$circleId, $userId]
        );
        return $row ? $row['role'] : null;
    }

    /** 批量取用户已加入的圈子 ID 集合 */
    public static function fetchJoinedSet(int $userId, array $circleIds): array
    {
        if (empty($circleIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($circleIds), '?'));
        $rows = Database::fetchAll(
            "SELECT circle_id FROM circle_members WHERE user_id = ? AND circle_id IN ({$placeholders})",
            array_merge([$userId], $circleIds)
        );
        $set = [];
        foreach ($rows as $r) {
            $set[(int)$r['circle_id']] = true;
        }
        return $set;
    }

    public static function slugExists(string $slug): bool
    {
        return (bool)Database::fetchOne("SELECT id FROM circles WHERE slug = ?", [$slug]);
    }

    // =====================================================================
    // 内联 SQL 收敛批次二 D 组：AdminCircleController 后台圈子管理。
    // 仅新增方法；上方示范批次的方法保持零改动。后台列表口径（无 status 过滤）
    // 与公开列表不同，故另起方法，避免与 fetchPublicList 混用。
    // =====================================================================

    /** circles() —— 后台列表与计数共用 WHERE（关键词 LIKE 值绑定） */
    private static function adminCircleWhere(string $keyword): array
    {
        $where = 'WHERE 1=1';
        $params = [];
        if ($keyword !== '') {
            $where .= ' AND (name LIKE ? OR description LIKE ?)';
            $kw = '%' . $keyword . '%';
            $params[] = $kw;
            $params[] = $kw;
        }
        return [$where, $params];
    }

    /** circles() —— 后台圈子分页 */
    public static function fetchAdminCircleList(string $keyword, int $offset, int $limit): array
    {
        [$where, $params] = self::adminCircleWhere($keyword);
        return Database::fetchAll(
            "SELECT c.*, u.username AS owner_username, u.nickname AS owner_nickname
             FROM circles c
             LEFT JOIN users u ON c.owner_id = u.id
             {$where}
             ORDER BY c.id DESC
             LIMIT ?, ?",
            array_merge($params, [$offset, $limit])
        );
    }

    /** circles() —— 后台圈子总数（与列表同一套 WHERE） */
    public static function countAdminCircles(string $keyword): int
    {
        [$where, $params] = self::adminCircleWhere($keyword);
        return (int)Database::fetchColumn("SELECT COUNT(*) FROM circles c {$where}", $params);
    }

    /** saveCircle() —— 圈主有效性校验 */
    public static function countUsersById(int $userId): int
    {
        return (int)Database::fetchColumn("SELECT COUNT(*) FROM users WHERE id = ? LIMIT 1", [$userId]);
    }

    /** deleteCircle() —— 删前回显 */
    public static function findCircleIdForAdmin(int $id): ?array
    {
        return Database::fetchOne("SELECT id FROM circles WHERE id = ?", [$id]);
    }

    /**
     * saveCircle() —— 新增/编辑圈子完整事务（含圈主变更的成员角色同步）。
     * 返回 0 表示按 ID 编辑时圈子不存在，由控制器转原 404 文案；其余异常抛给控制器。
     */
    public static function saveAdminCircle(
        int $id,
        string $name,
        ?string $description,
        string $cover,
        int $ownerId,
        int $status,
        string $joinMode,
        string $postPermission,
        string $visibility,
        string $slug,
        string $now,
        ?int $payType = null,
        ?int $payVipLevel = null,
        ?float $payAmount = null,
        ?int $payDays = null
    ): int {
        // V67 修复：付费三字段随后台保存一并落库。
        // null = 本次未提交该字段，保持库中原值；旧调用方（只传 11 参）行为完全不变。
        $payFields = [];
        if ($payType !== null) {
            $payFields['pay_type'] = $payType;
        }
        if ($payVipLevel !== null) {
            $payFields['pay_vip_level'] = $payVipLevel;
        }
        if ($payAmount !== null) {
            $payFields['pay_amount'] = $payAmount;
        }
        if ($payDays !== null) {
            $payFields['pay_days'] = $payDays;
        }

        Database::beginTransaction();
        try {
            if ($id > 0) {
                $existing = Database::fetchOne("SELECT id, owner_id FROM circles WHERE id = ?", [$id]);
                if (!$existing) {
                    Database::commit();
                    return 0;
                }

                Database::update('circles', array_merge([
                    'name' => $name,
                    'description' => $description ?: null,
                    'cover' => $cover ?: '',
                    'owner_id' => $ownerId,
                    'status' => $status,
                    'join_mode' => $joinMode,
                    'post_permission' => $postPermission,
                    'visibility' => $visibility,
                    'updated_at' => $now,
                ], $payFields), 'id = ?', [$id]);

                $oldOwnerId = (int)$existing['owner_id'];
                if ($oldOwnerId !== $ownerId) {
                    if ($oldOwnerId > 0) {
                        Database::query(
                            "UPDATE circle_members SET role = 'member' WHERE circle_id = ? AND user_id = ?",
                            [$id, $oldOwnerId]
                        );
                    }
                    $memberRow = Database::fetchOne(
                        "SELECT id FROM circle_members WHERE circle_id = ? AND user_id = ?",
                        [$id, $ownerId]
                    );
                    if ($memberRow) {
                        Database::update('circle_members', [
                            'role' => 'owner',
                        ], 'id = ?', [(int)$memberRow['id']]);
                    } else {
                        Database::insert('circle_members', [
                            'circle_id' => $id,
                            'user_id' => $ownerId,
                            'role' => 'owner',
                            'joined_at' => $now,
                        ]);
                    }
                }
            } else {
                Database::insert('circles', array_merge([
                    'name' => $name,
                    'slug' => $slug,
                    'description' => $description ?: null,
                    'cover' => $cover ?: '',
                    'owner_id' => $ownerId,
                    'member_count' => 1,
                    'post_count' => 0,
                    'join_mode' => $joinMode,
                    'post_permission' => $postPermission,
                    'visibility' => $visibility,
                    'status' => $status,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $payFields));

                $id = (int)Database::getInstance()->lastInsertId();

                Database::insert('circle_members', [
                    'circle_id' => $id,
                    'user_id' => $ownerId,
                    'role' => 'owner',
                    'joined_at' => $now,
                ]);
            }

            Database::commit();
            return $id;
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    /** deleteCircle() —— 圈子删除事务（posts.circle_id 置 NULL → 成员 → 圈子） */
    public static function deleteAdminCircle(int $circleId): void
    {
        Database::beginTransaction();
        try {
            Database::query("UPDATE posts SET circle_id = NULL WHERE circle_id = ?", [$circleId]);
            Database::delete('circle_members', 'circle_id = ?', [$circleId]);
            Database::delete('circles', 'id = ?', [$circleId]);
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    /** circleMembers() —— 后台成员列表（不过滤 status，与公开成员列表口径不同） */
    public static function fetchAdminMembers(int $circleId, int $offset, int $limit): array
    {
        return Database::fetchAll(
            "SELECT cm.user_id, cm.role, cm.joined_at,
                    u.username, u.nickname, u.avatar, u.role AS user_role
             FROM circle_members cm
             INNER JOIN users u ON cm.user_id = u.id
             WHERE cm.circle_id = ?
             ORDER BY FIELD(cm.role, 'owner', 'admin', 'member'), cm.joined_at ASC
             LIMIT ?, ?",
            [$circleId, $offset, $limit]
        );
    }

    /** circleMembers() —— 后台成员总数（与列表共用 circle_id 条件，不含 status） */
    public static function countAdminMembers(int $circleId): int
    {
        return (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM circle_members WHERE circle_id = ?",
            [$circleId]
        );
    }

    /** removeCircleMember() —— 删除成员 + 同步 member_count（原实现无事务，保持） */
    public static function removeAdminMember(int $circleId, int $userId, string $now): void
    {
        Database::delete('circle_members', 'circle_id = ? AND user_id = ?', [$circleId, $userId]);
        Database::query(
            "UPDATE circles SET member_count = (SELECT COUNT(*) FROM circle_members WHERE circle_id = ?), updated_at = ? WHERE id = ?",
            [$circleId, $now, $circleId]
        );
    }

    /** transferCircleOwner() —— 转让圈主事务 */
    public static function transferAdminCircleOwner(
        int $circleId,
        int $newOwnerId,
        int $newOwnerMemberId,
        int $oldOwnerId,
        string $now
    ): void {
        Database::beginTransaction();
        try {
            Database::update('circle_members', [
                'role' => 'owner',
            ], 'id = ?', [$newOwnerMemberId]);

            if ($oldOwnerId > 0 && $oldOwnerId !== $newOwnerId) {
                Database::query(
                    "UPDATE circle_members SET role = 'admin' WHERE circle_id = ? AND user_id = ? AND role = 'owner'",
                    [$circleId, $oldOwnerId]
                );
            }

            Database::update('circles', [
                'owner_id' => $newOwnerId,
                'updated_at' => $now,
            ], 'id = ?', [$circleId]);

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    /** approveCircleRequest() —— 后台审批通过事务：入成员 + member_count+1 + 申请置 approved */
    public static function approveAdminJoinRequest(
        int $circleId,
        int $userId,
        int $reviewerId,
        int $requestId,
        string $now
    ): void {
        Database::beginTransaction();
        try {
            Database::insert('circle_members', [
                'circle_id' => $circleId,
                'user_id' => $userId,
                'role' => 'member',
                'joined_at' => $now,
            ]);
            Database::query(
                "UPDATE circles SET member_count = member_count + 1, updated_at = ? WHERE id = ?",
                [$now, $circleId]
            );
            Database::query(
                "UPDATE circle_join_requests SET status='approved', reviewer_id=?, reviewed_at=NOW() WHERE id=?",
                [$reviewerId, $requestId]
            );
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    /** circleModerate() —— 限制 / 恢复发言 */
    public static function setCircleLimited(int $circleId, int $limited, string $now): void
    {
        Database::update('circles', ['limited' => $limited, 'updated_at' => $now], 'id = ?', [$circleId]);
    }

    /** circleModerate() —— 下架 / 恢复圈子 */
    public static function setCircleRemoved(int $circleId, ?string $removedAt, string $now): void
    {
        Database::update('circles', ['removed_at' => $removedAt, 'updated_at' => $now], 'id = ?', [$circleId]);
    }

    /**
     * circlePendingRequests() —— 后台待审列表专用。
     * 与前台 fetchPendingRequests 的字段清单不同：后台 SQL 含 r.reviewer_id
     * （位于 reviewed_at 之后、u.username 之前），响应契约必须逐字保留。
     */
    public static function fetchAdminPendingRequests(int $circleId, int $offset, int $limit): array
    {
        return Database::fetchAll(
            "SELECT r.id, r.user_id, r.status, r.message, r.created_at, r.reviewed_at, r.reviewer_id,
                    u.username, u.nickname, u.avatar
             FROM circle_join_requests r
             INNER JOIN users u ON r.user_id = u.id
             WHERE r.circle_id = ? AND r.status = 'pending'
             ORDER BY r.id DESC
             LIMIT ?, ?",
            [$circleId, $offset, $limit]
        );
    }
}
