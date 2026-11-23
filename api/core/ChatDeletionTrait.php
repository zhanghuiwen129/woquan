<?php
/**
 * ChatService · ChatDeletionTrait
 * V99 结构拆分：消息可见性、软删除写入口、回收站与解散群编排。
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

trait ChatDeletionTrait
{

    /* ================================================================
     * 【V52】消息"永不物理删除"：软删除 / 回收站 / 取证
     * ================================================================ */

    /**
     * 消息可见性 SQL 片段（对某个用户而言）。
     * 规则：
     *  - 未删除的消息：所有人可见
     *  - scope='self'（发送者自己删）：仅删除者不可见，对方仍能看到
     *  - scope='admin'/'purge'：所有人不可见，但管理员后台可查
     * 老库缺列时退化成 1=1，保证不报错。
     */
    public static function visibilitySql(int $userId, string $alias = 'm'): string
    {
        if (!self::columnExists('messages', 'deleted_at')) {
            return '1=1';
        }
        $uid = (int)$userId;
        return "({$alias}.deleted_at IS NULL OR ({$alias}.delete_scope = 'self' AND {$alias}.deleted_by <> {$uid}))";
    }

    /**
     * 写一条删除/恢复审计（append-only，永远不删）
     */
    private static function logDeletion(
        array $msg,
        int $operatorId,
        string $operatorRole,
        string $scope,
        string $ip = '',
        string $ua = ''
    ): void {
        if (!self::tableExists('message_deletions')) {
            return;
        }
        try {
            Database::insert('message_deletions', [
                'message_id'         => (int)$msg['id'],
                'conversation_id'    => (int)($msg['conversation_id'] ?? 0),
                'sender_id'          => (int)($msg['sender_id'] ?? 0),
                'operator_id'        => $operatorId,
                'operator_role'      => $operatorRole,
                'scope'              => $scope,
                'content_snapshot'   => (string)($msg['content'] ?? ''),
                'message_created_at' => $msg['created_at'] ?? null,
                'ip'                 => mb_substr($ip, 0, 45),
                'user_agent'         => mb_substr($ua, 0, 255),
                'created_at'         => Helper::now()
            ]);
        } catch (\Throwable $e) {
            Logger::error('[Hublog Chat logDeletion] ' . $e->getMessage());
        }
    }

    /**
     * 用户彻底删除会话：把「自己发的消息」标记为对自己不可见（行保留，可恢复、可审计）。
     * 对方的消息完全不受影响。
     * @return array{deleted_messages:int}
     */
    public static function softDeleteOwnMessages(int $conversationId, int $userId, string $ip = '', string $ua = ''): array
    {
        self::requireMessageSoftDeleteReady();

        $own = Database::fetchAll(
            "SELECT id FROM messages WHERE conversation_id = ? AND sender_id = ? AND deleted_at IS NULL",
            [$conversationId, $userId]
        );
        $result = self::markMessagesDeleted(
            $conversationId,
            $userId,
            self::MSG_SCOPE_SELF,
            $ip,
            $ua,
            array_column($own, 'id')
        );

        return ['deleted_messages' => $result['deleted_messages']];
    }

    /**
     * 用户恢复：把自己删除过的消息恢复可见（scope=self 且 deleted_by=自己）
     */
    public static function restoreOwnMessages(int $conversationId, int $userId, string $ip = '', string $ua = ''): array
    {
        $rows = Database::fetchAll(
            "SELECT id, conversation_id, sender_id, content, created_at
             FROM messages
             WHERE conversation_id = ? AND deleted_at IS NOT NULL
               AND delete_scope = 'self' AND deleted_by = ?",
            [$conversationId, $userId]
        );

        foreach ($rows as $row) {
            Database::update(
                'messages',
                ['deleted_at' => null, 'deleted_by' => null, 'delete_scope' => null],
                'id = ?',
                [(int)$row['id']]
            );
            self::logDeletion($row, $userId, 'user', 'restore', $ip, $ua);
        }

        return ['restored_messages' => count($rows)];
    }

    /**
     * 用户回收站：列出自己删过的会话（含已删消息条数与删除时间），可一键恢复。
     */
    public static function recycleBin(int $userId, int $page = 1, int $limit = 20): array
    {
        $offset = ($page - 1) * $limit;
        if (!self::columnExists('conversation_members', 'deleted_at')) {
            return ['list' => [], 'pagination' => ['page' => $page, 'limit' => $limit, 'total' => 0, 'pages' => 0]];
        }

        $total = (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM conversation_members cm
             INNER JOIN conversations c ON c.id = cm.conversation_id
             WHERE cm.user_id = ? AND cm.deleted_at IS NOT NULL",
            [$userId]
        );

        $rows = Database::fetchAll(
            // V146：补选 circle_id —— 圈子群聊的头像要按圈子封面回填
            "SELECT c.id, c.type, c.title, c.avatar, c.circle_id, cm.deleted_at,
                    (SELECT COUNT(*) FROM messages m
                      WHERE m.conversation_id = c.id AND m.deleted_at IS NOT NULL
                        AND m.delete_scope = 'self' AND m.deleted_by = ?) AS deleted_msg_count
             FROM conversation_members cm
             INNER JOIN conversations c ON c.id = cm.conversation_id
             WHERE cm.user_id = ? AND cm.deleted_at IS NOT NULL
             ORDER BY cm.deleted_at DESC
             LIMIT ? OFFSET ?",
            [$userId, $userId, $limit, $offset]
        );

        // V146：回收站里的圈子群聊同样要显示圈子封面
        $circleIds = [];
        foreach ($rows as $conv) {
            if (($conv['type'] ?? '') === 'group' && !empty($conv['circle_id'])) {
                $circleIds[] = (int)$conv['circle_id'];
            }
        }
        $circleCovers = self::circleCovers($circleIds);

        $list = [];
        foreach ($rows as $conv) {
            $item = [
                'id' => (int)$conv['id'],
                'type' => $conv['type'],
                'title' => $conv['title'],
                'avatar' => $conv['avatar'],
                'deleted_at' => $conv['deleted_at'],
                'deleted_msg_count' => (int)$conv['deleted_msg_count'],
                'peer' => null
            ];
            if ($conv['type'] === 'private') {
                $peer = Database::fetchOne(
                    "SELECT u.id, u.username, u.nickname, u.avatar
                     FROM conversation_members cm
                     INNER JOIN users u ON cm.user_id = u.id
                     WHERE cm.conversation_id = ? AND cm.user_id != ? LIMIT 1",
                    [$conv['id'], $userId]
                );
                if ($peer) {
                    $item['peer'] = [
                        'id' => (int)$peer['id'],
                        'username' => $peer['username'],
                        'nickname' => $peer['nickname'] ?: $peer['username'],
                        'avatar' => $peer['avatar'] ?: ''
                    ];
                    if (empty($item['title'])) {
                        $item['title'] = $peer['nickname'] ?: $peer['username'];
                    }
                    if (empty($item['avatar'])) {
                        $item['avatar'] = $peer['avatar'];
                    }
                }
            }
            // V146：圈子群聊头像取圈子当前封面（建群时 avatar 为空串）
            if ($conv['type'] === 'group' && !empty($conv['circle_id'])) {
                $cover = $circleCovers[(int)$conv['circle_id']] ?? '';
                if ($cover !== '') {
                    $item['avatar'] = $cover;
                }
            }
            $list[] = $item;
        }

        return [
            'list' => $list,
            'pagination' => ['page' => $page, 'limit' => $limit, 'total' => $total, 'pages' => (int)ceil($total / max($limit, 1))]
        ];
    }

    /**
     * V99：全站唯一的消息删除写入口 —— 只打软删标记，任何分支都不发物理删。
     *
     * 同时承担三件事：标 deleted_at/deleted_by/delete_scope、逐条写删除审计、
     * 重算会话 last_message_id。$ids 为空表示会话下全部未删消息。
     *
     * @return array{deleted_messages:int,soft_deleted:bool,file_deleted:bool}
     */
    public static function markMessagesDeleted(
        int $conversationId,
        int $operatorId,
        string $scope,
        string $ip = '',
        string $ua = '',
        ?array $ids = null
    ): array {
        self::requireMessageSoftDeleteReady();

        if (!in_array($scope, [self::MSG_SCOPE_SELF, self::MSG_SCOPE_ADMIN, self::MSG_SCOPE_PURGE], true)) {
            throw new Exception\HublogException('非法删除范围', 400);
        }

        $where = 'conversation_id = ? AND deleted_at IS NULL';
        $params = [$conversationId];
        if ($ids !== null) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
            if ($ids === []) {
                return ['deleted_messages' => 0, 'soft_deleted' => true, 'file_deleted' => false];
            }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $where .= " AND id IN ({$placeholders})";
            $params = array_merge($params, $ids);
        }

        $rows = Database::fetchAll(
            "SELECT id, conversation_id, sender_id, type, content, created_at, extra
             FROM messages WHERE {$where}",
            $params
        );
        if ($rows === []) {
            return ['deleted_messages' => 0, 'soft_deleted' => true, 'file_deleted' => false];
        }

        $now = Helper::now();
        Database::query(
            "UPDATE messages SET deleted_at = ?, deleted_by = ?, delete_scope = ? WHERE {$where}",
            array_merge([$now, $operatorId, $scope], $params)
        );

        $role = $scope === self::MSG_SCOPE_SELF ? 'user' : 'admin';
        foreach ($rows as $row) {
            self::logDeletion($row, $operatorId, $role, $scope, $ip, $ua);
        }

        // 会话摘要只算可见消息；没有必要因为删除而去动别的表
        $lastId = Database::fetchColumn(
            "SELECT id FROM messages WHERE conversation_id = ? AND deleted_at IS NULL ORDER BY id DESC LIMIT 1",
            [$conversationId]
        );
        Database::update('conversations', [
            'last_message_id' => $lastId ?: null,
            'updated_at' => $now
        ], 'id = ?', [$conversationId]);

        return ['deleted_messages' => count($rows), 'soft_deleted' => true, 'file_deleted' => false];
    }

    /**
     * V99：群主解散群聊的编排入口。
     *
     * 先把群内消息软删并留审计（V52「永不物理删除」），再走仓储事务清成员与会话行。
     * 消息 UPDATE 失败时仓储事务根本不会被调用，回滚测试仍然成立。
     */
    public static function dissolveGroupAsOwner(int $conversationId, int $userId, string $ip = '', string $ua = ''): void
    {
        self::requireMessageSoftDeleteReady();
        self::markMessagesDeleted($conversationId, $userId, self::MSG_SCOPE_ADMIN, $ip, $ua);
        self::adminLog(
            $userId,
            'group_dissolve',
            'conversation',
            (string)$conversationId,
            '群主解散群聊；消息已软删并保留取证',
            $ip
        );
        ChatRepository::dissolveMembersAndConversation($conversationId);
    }
}
