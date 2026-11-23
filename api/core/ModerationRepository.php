<?php
/**
 * Hublog 审核域数据仓储
 *
 * 承载 AdminModerationController（敏感词库 / 命中日志 / 用户禁言）与
 * PostModerationTrait（神评标记 / 定时发布 / 我的定时列表）下沉的全部 SQL。
 *
 * 【口径隔离 —— 相似 SQL 刻意不合并】
 *  - 敏感词「改词判重」(word = ? AND id != ?) 与「新增判重 / 导入判重」(word = ?)
 *    是两条不同的 SQL，各自独立方法；不能合并成带可选排除 ID 的通用查询。
 *  - 命中日志列表是 content_filter_logs l LEFT JOIN users u 的 `l.* + u.*` 字段集，
 *    计数只 FROM content_filter_logs l，二者共用同一个 where 助手保证翻页不错位。
 *  - 定时发布 COUNT 与本尊 UPDATE 都带 `status = 0 ... AND audit_state = 0`，
 *    这是业务守卫（待审动态不得被定时器放出），守卫必须随 SQL 一起留在仓储。
 *  - 「我的定时动态」列表与定时发布的 WHERE 不同（前者按 user_id、不判 audit_state），
 *    两条 posts 查询互不复用。
 *  - 凡返回 posts 行的 SELECT 一律在字段清单末尾追加 post_type（铁律）；
 *    COUNT(*) 聚合保持 COUNT(*)，不追加该列。
 *
 * 控制器保留权限、参数校验、缓存失效、错误文案与响应包装；本类不做权限判断。
 */

declare(strict_types=1);

namespace Hublog\Core;

class ModerationRepository
{
    // ==================== 敏感词库 ====================

    /**
     * sensitiveWords() 列表 / 计数共用的 WHERE 助手。
     * keyword 用 `!== ''` 真值判断，与 HEAD 原文一致；LIKE 的前后 % 走绑定参数。
     *
     * @return array{0:string,1:array<int,mixed>}
     */
    private static function sensitiveWordsWhere(string $keyword): array
    {
        $where = 'WHERE 1=1';
        $params = [];
        if ($keyword !== '') {
            $where .= ' AND word LIKE ?';
            $params[] = '%' . $keyword . '%';
        }
        return [$where, $params];
    }

    /** sensitiveWords() —— 敏感词列表：字段/排序/分页与 HEAD 逐字一致 */
    public static function fetchSensitiveWords(string $keyword, int $offset, int $limit): array
    {
        [$where, $params] = self::sensitiveWordsWhere($keyword);
        return Database::fetchAll(
            "SELECT * FROM sensitive_words {$where}
             ORDER BY hit_count DESC, id DESC
             LIMIT ?, ?",
            array_merge($params, [$offset, $limit])
        );
    }

    /** sensitiveWords() —— 与列表共用同一 WHERE 的分页计数 */
    public static function countSensitiveWords(string $keyword): int
    {
        [$where, $params] = self::sensitiveWordsWhere($keyword);
        return (int)Database::fetchColumn("SELECT COUNT(*) FROM sensitive_words {$where}", $params);
    }

    /** saveSensitiveWord() —— 新增 / 导入前的全库判重（不带排除 ID） */
    public static function findSensitiveWordId(string $word): mixed
    {
        return Database::fetchColumn("SELECT id FROM sensitive_words WHERE word = ? LIMIT 1", [$word]);
    }

    /** saveSensitiveWord() —— 改词判重（排除自身，与新增判重刻意分离） */
    public static function findOtherSensitiveWordId(string $word, int $excludeId): mixed
    {
        return Database::fetchColumn(
            "SELECT id FROM sensitive_words WHERE word = ? AND id != ? LIMIT 1",
            [$word, $excludeId]
        );
    }

    /** 敏感词写入字段白名单：列名只来自控制器硬编码数组，仓储再加一道校验 */
    private static function assertSensitiveWordFields(array $payload): void
    {
        $allowed = ['word', 'level', 'replacement', 'scene', 'status', 'created_at', 'updated_at'];
        foreach (array_keys($payload) as $column) {
            $column = (string)$column;
            if (!in_array($column, $allowed, true) || preg_match('/^[a-z_]+$/', $column) !== 1) {
                throw new \InvalidArgumentException('非法敏感词字段名');
            }
        }
    }

    /** saveSensitiveWord() / importSensitiveWords() —— 新增敏感词 */
    public static function insertSensitiveWord(array $payload): int
    {
        self::assertSensitiveWordFields($payload);
        return Database::insert('sensitive_words', $payload);
    }

    /** saveSensitiveWord() —— 编辑敏感词 */
    public static function updateSensitiveWord(int $id, array $payload): void
    {
        if ($id <= 0) {
            return;
        }
        self::assertSensitiveWordFields($payload);
        Database::update('sensitive_words', $payload, 'id = ?', [$id]);
    }

    /** deleteSensitiveWord() —— 按主键删除 */
    public static function deleteSensitiveWord(int $id): void
    {
        if ($id <= 0) {
            return;
        }
        Database::delete('sensitive_words', 'id = ?', [$id]);
    }

    // ==================== 敏感词命中日志 ====================

    /** filterLogs() 列表 / 计数共用的 WHERE 助手（别名 l 逐字保留） */
    private static function filterLogsWhere(string $scene): array
    {
        $where = 'WHERE 1=1';
        $params = [];
        if ($scene !== '') {
            $where .= ' AND l.scene = ?';
            $params[] = $scene;
        }
        return [$where, $params];
    }

    /** filterLogs() —— 日志列表：l.* + u.username/nickname/avatar，JOIN/ORDER/LIMIT 逐字保留 */
    public static function fetchFilterLogs(string $scene, int $offset, int $limit): array
    {
        [$where, $params] = self::filterLogsWhere($scene);
        return Database::fetchAll(
            "SELECT l.*, u.username, u.nickname, u.avatar
             FROM content_filter_logs l
             LEFT JOIN users u ON l.user_id = u.id
             {$where}
             ORDER BY l.id DESC
             LIMIT ?, ?",
            array_merge($params, [$offset, $limit])
        );
    }

    /** filterLogs() —— 与列表共用同一 WHERE 的分页计数（不带 LEFT JOIN，与 HEAD 一致） */
    public static function countFilterLogs(string $scene): int
    {
        [$where, $params] = self::filterLogsWhere($scene);
        return (int)Database::fetchColumn("SELECT COUNT(*) FROM content_filter_logs l {$where}", $params);
    }

    // ==================== 用户禁言 ====================

    /** muteUser() —— 写入禁言截止时间；控制器负责随后清 auth_user_ 缓存 */
    public static function updateUserMutedUntil(int $userId, string $until, string $now): void
    {
        if ($userId <= 0) {
            return;
        }
        Database::update('users', ['muted_until' => $until, 'updated_at' => $now], 'id = ?', [$userId]);
    }

    /** unmuteUser() —— 解除禁言（muted_until 写回 null） */
    public static function clearUserMutedUntil(int $userId, string $now): void
    {
        if ($userId <= 0) {
            return;
        }
        Database::update('users', ['muted_until' => null, 'updated_at' => $now], 'id = ?', [$userId]);
    }

    // ==================== 神评标记 ====================

    /** canMarkGodComment() —— 圈子成员角色 + 圈主（LEFT JOIN circles，status=1 留 SQL） */
    public static function findCircleMemberRole(int $circleId, int $userId): ?array
    {
        if ($circleId <= 0 || $userId <= 0) {
            return null;
        }
        return Database::fetchOne(
            "SELECT cm.role, c.owner_id
             FROM circle_members cm
             LEFT JOIN circles c ON c.id = cm.circle_id
             WHERE cm.circle_id = ? AND cm.user_id = ? AND cm.status = 1",
            [$circleId, $userId]
        );
    }

    /**
     * markGodComment() / unmarkGodComment() —— 取待标记评论及其帖子归属。
     * 两处调用 SQL 逐字相同，故复用同一方法（这是「相同才合并」的正例）。
     * 末尾 p.post_type 为遵守 posts 行查询铁律追加，不改变原有列的顺序与取值。
     */
    public static function findCommentForMark(int $commentId): ?array
    {
        if ($commentId <= 0) {
            return null;
        }
        return Database::fetchOne(
            "SELECT c.id, c.post_id, c.user_id, c.is_ai, c.status, p.user_id AS post_user_id, p.circle_id, p.post_type
             FROM comments c
             INNER JOIN posts p ON p.id = c.post_id
             WHERE c.id = ? LIMIT 1",
            [$commentId]
        );
    }

    /**
     * markGodComment() —— INSERT IGNORE 写神评标记，返回是否真的新增。
     * 四个值全部绑定（原 SQL 的 'god' 字面量也改为绑定，值不进 SQL 文本）。
     * SQLite 不识别 MySQL 的 INSERT IGNORE，仅在行为测试下换成等价的 OR IGNORE；
     * 生产（MySQL）路径保持原文法。
     */
    public static function insertGodCommentMark(int $commentId, int $operatorId, string $now): bool
    {
        if ($commentId <= 0) {
            return false;
        }
        $isSqlite = Database::getInstance()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $verb = $isSqlite ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
        $stmt = Database::getInstance()->prepare(
            "{$verb} INTO comment_marks (comment_id, mark_type, operator_id, created_at)
             VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([$commentId, 'god', $operatorId, $now]);
        return $stmt->rowCount() > 0;
    }

    /** unmarkGodComment() —— 删除神评标记，返回是否真的删到行 */
    public static function deleteGodCommentMark(int $commentId): bool
    {
        if ($commentId <= 0) {
            return false;
        }
        return Database::delete(
            'comment_marks',
            'comment_id = ? AND mark_type = ?',
            [$commentId, 'god']
        ) > 0;
    }

    /** 通知写入字段白名单（列名来自控制器硬编码数组） */
    private static function assertNotificationFields(array $data): void
    {
        $allowed = [
            'user_id', 'type', 'title', 'content', 'post_id',
            'comment_id', 'sender_id', 'is_read', 'created_at',
        ];
        foreach (array_keys($data) as $column) {
            $column = (string)$column;
            if (!in_array($column, $allowed, true) || preg_match('/^[a-z_]+$/', $column) !== 1) {
                throw new \InvalidArgumentException('非法通知字段名');
            }
        }
    }

    /** markGodComment() —— 通知评论作者；控制器保留 try/catch 吞异常的原有容错语义 */
    public static function insertGodCommentNotification(array $data): void
    {
        self::assertNotificationFields($data);
        Database::insert('notifications', $data);
    }

    // ==================== 定时发布 ====================

    /**
     * publishScheduledPosts() —— 到期待发布动态计数。
     * `audit_state = 0` 是防绕过审核的业务守卫，必须与下一方法的 UPDATE 同步保留。
     */
    public static function countDueScheduledPosts(string $now): int
    {
        return (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM posts
             WHERE status = 0 AND scheduled_at IS NOT NULL AND scheduled_at <= ? AND audit_state = 0",
            [$now]
        );
    }

    /**
     * publishScheduledPosts() —— 把到期动态转为正式发布。
     * 原 getInstance()->exec() 把 $now 拼进 SQL，此处改为 ? 绑定；
     * 其余条件（含 audit_state = 0 守卫）逐字保留。
     */
    public static function publishDueScheduledPosts(string $now): int
    {
        return Database::query(
            "UPDATE posts SET status = 1, scheduled_at = NULL
             WHERE status = 0 AND scheduled_at IS NOT NULL AND scheduled_at <= ? AND audit_state = 0",
            [$now]
        )->rowCount();
    }

    // ==================== 我的定时动态 ====================

    /** scheduledList() —— 本人定时动态计数（不过滤 audit_state，与发布守卫口径不同） */
    public static function countMyScheduledPosts(int $userId): int
    {
        return (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM posts WHERE user_id = ? AND status = 0 AND scheduled_at IS NOT NULL",
            [$userId]
        );
    }

    /**
     * scheduledList() —— 本人定时动态列表。
     * 原为 `LIMIT {$limit} OFFSET {$offset}` 拼接，改为 `LIMIT ? OFFSET ?`，
     * 参数顺序保持 limit, offset（不可与 `LIMIT offset, limit` 混淆）。
     * 字段清单末尾追加 post_type 遵守 posts 行查询铁律。
     */
    public static function fetchMyScheduledPosts(int $userId, int $offset, int $limit): array
    {
        return Database::fetchAll(
            "SELECT id, title, content, images, videos, location, circle_id,
                    is_anonymous, scheduled_at, created_at, post_type
             FROM posts
             WHERE user_id = ? AND status = 0 AND scheduled_at IS NOT NULL
             ORDER BY scheduled_at ASC
             LIMIT ? OFFSET ?",
            [$userId, $limit, $offset]
        );
    }
}
