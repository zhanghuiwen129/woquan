<?php
/**
 * ChatService · ChatAdminTrait
 * V99 结构拆分：后台聊天管理（查询 / 审计 / 下架 / 清理）。
 * 方法体从 ChatService.php 逐字搬迁，零行为变更。
 */

namespace Hublog\Core;

use Hublog\Core\Database;
use Hublog\Core\Cache;
use Hublog\Core\Helper;
use Hublog\Core\Logger;
use Hublog\Core\Security;
use Hublog\Core\SsePush;
use Hublog\Core\PathResolver;
use Hublog\Core\ChatRepository;
use Hublog\Core\Exception\HublogException;

trait ChatAdminTrait
{

    /* ================================================================
     * 【管理后台】私信管理接口
     * ================================================================ */

    /**
     * 管理后台：获取全局会话列表（支持搜索、分页）
     */
    public static function adminGetConversations(int $page = 1, int $limit = 20, string $keyword = '', bool $includeDeleted = false): array
    {
        $offset = ($page - 1) * $limit;
        $hasPinned = self::columnExists('conversation_members', 'pinned');
        $pinnedSelect = $hasPinned ? 'cm.pinned,' : '0 AS pinned,';

        $where = 'WHERE 1=1';
        $params = [];
        // V52：会话下架也是软删除，默认只看正常会话；勾上"含已下架"才能看到已删除的会话（取证用）
        if (!$includeDeleted && self::columnExists('conversations', 'deleted_at')) {
            $where .= ' AND c.deleted_at IS NULL';
        }
        if ($keyword !== '') {
            $where .= " AND (c.id IN (SELECT conversation_id FROM messages WHERE content LIKE ?)
                            OR c.id IN (SELECT conversation_id FROM conversation_members WHERE user_id IN (SELECT id FROM users WHERE username LIKE ? OR nickname LIKE ?)))";
            $kw = "%{$keyword}%";
            $params = [$kw, $kw, $kw];
        }

        $total = (int)Database::fetchColumn("SELECT COUNT(*) FROM conversations c {$where}", $params);

        $list = Database::fetchAll(
            "SELECT c.id, c.type, c.title, c.last_message_id, c.last_message_at, c.created_at, c.updated_at,
                    (SELECT COUNT(*) FROM messages WHERE conversation_id = c.id AND deleted_at IS NULL) AS msg_count,
                    (SELECT COUNT(*) FROM messages WHERE conversation_id = c.id AND deleted_at IS NOT NULL) AS deleted_msg_count
             FROM conversations c
             {$where}
             ORDER BY c.last_message_at DESC, c.id DESC
             LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        );

        foreach ($list as &$conv) {
            $members = Database::fetchAll(
                "SELECT u.id, u.username, u.nickname, u.avatar
                 FROM conversation_members cm INNER JOIN users u ON cm.user_id = u.id
                 WHERE cm.conversation_id = ?",
                [$conv['id']]
            );
            $conv['members'] = $members;
            $conv['msg_count'] = (int)$conv['msg_count'];
            $conv['deleted_msg_count'] = (int)($conv['deleted_msg_count'] ?? 0);
        }

        return [
            'list' => $list,
            'pagination' => ['page' => $page, 'limit' => $limit, 'total' => $total, 'pages' => (int)ceil($total / max($limit, 1))]
        ];
    }

    /**
     * 管理后台：获取消息列表（支持按会话、类型、时间范围过滤）
     */
    public static function adminGetMessages(int $page = 1, int $limit = 30, array $filters = []): array
    {
        $offset = ($page - 1) * $limit;
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['conversation_id'])) {
            $where[] = 'm.conversation_id = ?';
            $params[] = (int)$filters['conversation_id'];
        }
        if (!empty($filters['type'])) {
            $where[] = 'm.type = ?';
            $params[] = $filters['type'];
        }
        if (!empty($filters['keyword'])) {
            $where[] = 'm.content LIKE ?';
            $params[] = '%' . $filters['keyword'] . '%';
        }
        if (!empty($filters['sender_id'])) {
            $where[] = 'm.sender_id = ?';
            $params[] = (int)$filters['sender_id'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'm.created_at >= ?';
            $params[] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'm.created_at <= ?';
            $params[] = $filters['date_to'] . ' 23:59:59';
        }

        // V52：默认只看正常消息；勾"含已删除"后连软删除的消息一起列出（取证）
        $hasDeletedCol = self::columnExists('messages', 'deleted_at');
        if ($hasDeletedCol) {
            if (empty($filters['include_deleted'])) {
                $where[] = 'm.deleted_at IS NULL';
            } elseif (!empty($filters['deleted_only'])) {
                $where[] = 'm.deleted_at IS NOT NULL';
            }
        }

        $whereSql = implode(' AND ', $where);

        $total = (int)Database::fetchColumn("SELECT COUNT(*) FROM messages m {$whereSql}", $params);

        $list = Database::fetchAll(
            "SELECT m.*, u.username, u.nickname,
                    d.username AS deleted_by_username, d.nickname AS deleted_by_nickname,
                    (SELECT GROUP_CONCAT(user_id) FROM conversation_members WHERE conversation_id = m.conversation_id) AS member_ids
             FROM messages m
             LEFT JOIN users u ON m.sender_id = u.id
             LEFT JOIN users d ON m.deleted_by = d.id
             {$whereSql}
             ORDER BY m.id DESC
             LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        );

        foreach ($list as &$row) {
            $row['deleted'] = !empty($row['deleted_at']);
            // 内容指纹校验：存过 hash 的顺手验一次，被篡改过能被发现
            if (!empty($row['content_hash'])) {
                $row['hash_ok'] = hash_equals((string)$row['content_hash'], self::contentHash((string)$row['content'], (int)$row['id']));
            }
        }
        unset($row);

        return [
            'list' => $list,
            'pagination' => ['page' => $page, 'limit' => $limit, 'total' => $total, 'pages' => (int)ceil($total / max($limit, 1))]
        ];
    }

    /**
     * 管理后台：删除流水（谁在什么时候删了哪条消息，附原文快照）
     */
    public static function adminGetDeletions(int $page = 1, int $limit = 30, array $filters = []): array
    {
        if (!self::tableExists('message_deletions')) {
            return ['list' => [], 'pagination' => ['page' => $page, 'limit' => $limit, 'total' => 0, 'pages' => 0]];
        }

        $offset = ($page - 1) * $limit;
        $where = ['1=1'];
        $params = [];
        if (!empty($filters['conversation_id'])) {
            $where[] = 'd.conversation_id = ?';
            $params[] = (int)$filters['conversation_id'];
        }
        if (!empty($filters['operator_id'])) {
            $where[] = 'd.operator_id = ?';
            $params[] = (int)$filters['operator_id'];
        }
        if (!empty($filters['scope'])) {
            $where[] = 'd.scope = ?';
            $params[] = $filters['scope'];
        }
        if (!empty($filters['keyword'])) {
            $where[] = 'd.content_snapshot LIKE ?';
            $params[] = '%' . $filters['keyword'] . '%';
        }
        $whereSql = implode(' AND ', $where);

        $total = (int)Database::fetchColumn("SELECT COUNT(*) FROM message_deletions d WHERE {$whereSql}", $params);
        $list = Database::fetchAll(
            "SELECT d.*, u.username AS operator_username, u.nickname AS operator_nickname
             FROM message_deletions d
             LEFT JOIN users u ON d.operator_id = u.id
             WHERE {$whereSql}
             ORDER BY d.id DESC
             LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        );

        return [
            'list' => $list,
            'pagination' => ['page' => $page, 'limit' => $limit, 'total' => $total, 'pages' => (int)ceil($total / max($limit, 1))]
        ];
    }

    /**
     * 管理后台：恢复一条被删消息
     */
    public static function adminRestoreMessage(int $messageId, int $adminId, string $ip = ''): array
    {
        $msg = Database::fetchOne("SELECT * FROM messages WHERE id = ? LIMIT 1", [$messageId]);
        if (!$msg) {
            throw new Exception\HublogException('消息不存在', 404);
        }
        Database::update('messages', [
            'deleted_at' => null,
            'deleted_by' => null,
            'delete_scope' => null
        ], 'id = ?', [$messageId]);

        self::logDeletion($msg, $adminId, 'admin', 'restore', $ip, '');
        return ['message_id' => $messageId];
    }

    /**
     * 管理后台：导出用数据（不分页，含已删除消息）
     */
    public static function adminExportRows(array $filters = [], int $maxRows = 5000): array
    {
        $filters['include_deleted'] = true;
        $res = self::adminGetMessages(1, max(1, min($maxRows, 5000)), $filters);
        return $res['list'];
    }

    /**
     * 后台操作留痕（查询、导出、删除都会被记录，本身也可作为证据链）
     */
    public static function adminLog(int $adminId, string $action, string $targetType = '', string $targetId = '', string $detail = '', string $ip = ''): void
    {
        if (!self::tableExists('admin_audit_logs')) {
            return;
        }
        try {
            Database::insert('admin_audit_logs', [
                'admin_id'    => $adminId,
                'action'      => mb_substr($action, 0, 50),
                'target_type' => mb_substr($targetType, 0, 30),
                'target_id'   => mb_substr((string)$targetId, 0, 64),
                'detail'      => $detail,
                'ip'          => mb_substr($ip, 0, 45),
                'created_at'  => Helper::now()
            ]);
        } catch (\Throwable $e) {
            Logger::error('[Hublog adminLog] ' . $e->getMessage());
        }
    }

    /**
     * 管理后台：下架单条消息。
     * V52：改为软删除 —— 行、内容、附件文件全部保留，只是对所有人不可见，
     *      后台勾"含已删除"仍可查，删除流水里保留原文快照。
     */
    public static function adminDeleteMessage(int $messageId, int $adminId = 0, string $ip = ''): array
    {
        $msg = Database::fetchOne("SELECT * FROM messages WHERE id = ? LIMIT 1", [$messageId]);
        if (!$msg) {
            throw new Exception\HublogException('消息不存在', 404);
        }

        self::requireMessageSoftDeleteReady();
        self::markMessagesDeleted(
            (int)($msg['conversation_id'] ?? 0),
            $adminId,
            self::MSG_SCOPE_ADMIN,
            $ip,
            '',
            [$messageId]
        );

        return ['message_id' => $messageId, 'soft_deleted' => true, 'file_deleted' => false];
    }

    /**
     * 管理后台：批量删除消息
     */
    public static function adminBatchDeleteMessages(array $messageIds, int $adminId = 0, string $ip = ''): array
    {
        if (empty($messageIds)) {
            throw new Exception\HublogException('请选择要删除的消息', 400);
        }
        $ids = array_map('intval', $messageIds);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $messages = Database::fetchAll(
            "SELECT id, conversation_id, sender_id, type, content, created_at, extra FROM messages WHERE id IN ({$placeholders})",
            $ids
        );

        self::requireMessageSoftDeleteReady();

        $byConversation = [];
        foreach ($messages as $m) {
            $byConversation[(int)($m['conversation_id'] ?? 0)][] = (int)$m['id'];
        }
        foreach ($byConversation as $conversationId => $messageIds) {
            self::markMessagesDeleted((int)$conversationId, $adminId, self::MSG_SCOPE_ADMIN, $ip, '', $messageIds);
        }

        return ['deleted_messages' => count($messages), 'soft_deleted' => true, 'deleted_files' => 0];
    }

    /**
     * 管理后台：清理过期私信消息
     * @param int $days 清理多少天前的消息
     * @param array $types 清理的类型，空数组表示全部
     */
    public static function adminCleanupExpired(int $days, array $types = [], int $adminId = 0, string $ip = ''): array
    {
        if ($days < 1) {
            throw new Exception\HublogException('天数必须大于0', 400);
        }

        self::requireMessageSoftDeleteReady();

        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $typeWhere = '';
        $params = [$cutoff];
        if (!empty($types)) {
            $placeholders = implode(',', array_fill(0, count($types), '?'));
            $typeWhere = " AND type IN ({$placeholders})";
            $params = array_merge($params, $types);
        }
        // V52：只处理尚未删除的消息，避免重复清理
        $typeWhere .= ' AND deleted_at IS NULL';

        $messages = Database::fetchAll(
            "SELECT id, conversation_id, sender_id, type, content, created_at, extra
             FROM messages WHERE created_at < ? {$typeWhere} LIMIT 5000",
            $params
        );

        if (empty($messages)) {
            return ['deleted_messages' => 0, 'soft_deleted' => true, 'freed_bytes' => 0, 'freed_mb' => 0];
        }

        $byConversation = [];
        foreach ($messages as $m) {
            $byConversation[(int)($m['conversation_id'] ?? 0)][] = (int)$m['id'];
        }
        foreach ($byConversation as $conversationId => $messageIds) {
            // V52：scope=purge，行与快照都留着，证据不灭失
            self::markMessagesDeleted((int)$conversationId, $adminId, self::MSG_SCOPE_PURGE, $ip, '', $messageIds);
        }

        // V52：软删除模式下消息原文与附件文件全部保留，供后台取证
        return [
            'deleted_messages' => count($messages),
            'soft_deleted' => true,
            'freed_bytes' => 0,
            'freed_mb' => 0
        ];
    }

    /**
     * 管理后台：下架整个会话。
     * V52：会话行、成员行、消息行全部保留，只打删除标记 —— 后台勾"含已下架"随时能翻出来。
     */
    public static function adminDeleteConversation(int $conversationId, int $adminId = 0, string $ip = ''): array
    {
        // 跨表原子闸门：消息 / 会话 / 成员三套软删能力必须同时齐备。
        // 任一不满足 → 零写入 + 503，绝不再出现「消息已软删但会话被物理删」。
        self::requireMessageSoftDeleteReady();
        if (!self::conversationSoftDeleteReady() || !self::memberSoftDeleteReady()) {
            throw new Exception\HublogException('服务端未完成软删除升级，操作已拒绝', 503);
        }

        $messages = Database::fetchAll(
            "SELECT id FROM messages WHERE conversation_id = ?",
            [$conversationId]
        );

        // 消息只打软删标记，会话行也只打 deleted_at：两张表都不做物理删
        self::markMessagesDeleted($conversationId, $adminId, self::MSG_SCOPE_ADMIN, $ip, '');
        $now = Helper::now();
        Database::update('conversations', ['deleted_at' => $now, 'updated_at' => $now], 'id = ?', [$conversationId]);

        return [
            'deleted_messages' => count($messages),
            'soft_deleted' => true,
            'freed_bytes' => 0,
            'freed_mb' => 0
        ];
    }
}
