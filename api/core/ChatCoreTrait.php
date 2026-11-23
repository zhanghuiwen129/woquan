<?php
/**
 * ChatService · ChatCoreTrait
 * V99 结构拆分：会话与消息查询。
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

trait ChatCoreTrait
{

    /**
     * P1-13：双向拉黑校验
     * - sender 拉黑 receiver → 发送方禁止发
     * - receiver 拉黑 sender → 不允许接收
     * @return null 未拉黑 | string 错误信息（"您已拉黑对方" / "对方已拉黑您"）
     */
    public static function checkBlockedBothWays(int $senderId, int $receiverId): ?string
    {
        if ($senderId === $receiverId) return null;
        // 1. 发送方是否拉黑了对方
        $blockedThem = Database::fetchColumn(
            "SELECT 1 FROM blocked_users WHERE user_id = ? AND blocked_user_id = ? LIMIT 1",
            [$senderId, $receiverId]
        );
        if ($blockedThem) {
            return '您已拉黑该用户，无法发送消息或创建会话';
        }
        // 2. 对方是否拉黑了发送方
        $blockedByThem = Database::fetchColumn(
            "SELECT 1 FROM blocked_users WHERE user_id = ? AND blocked_user_id = ? LIMIT 1",
            [$receiverId, $senderId]
        );
        if ($blockedByThem) {
            return '对方已设置拒收您的私信';
        }
        return null;
    }

    /**
     * 获取或创建私聊会话
     * 两个用户之间只存在一个私聊会话
     */
    public static function getOrCreatePrivateConversation(int $userId, int $otherUserId): array
    {
        if ($userId === $otherUserId) {
            throw new Exception\HublogException('不能与自己私聊', 400);
        }

        // 验证对方用户存在且未被封禁
        $otherUser = Database::fetchOne(
            "SELECT id, username, nickname, avatar FROM users WHERE id = ? AND status = 1 LIMIT 1",
            [$otherUserId]
        );
        if (!$otherUser) {
            throw new Exception\HublogException('用户不存在或已封禁', 404);
        }

        // P1-13：双向拉黑校验（无论新建/复用会话，只要任一方向拉黑都不允许进入会话）
        $blockedErr = self::checkBlockedBothWays($userId, $otherUserId);
        if ($blockedErr !== null) {
            throw new Exception\HublogException($blockedErr, 403);
        }

        // 查找已有的私聊会话（两个成员都在同一个 type=private 会话中）
        $convId = Database::fetchColumn(
            "SELECT m1.conversation_id
             FROM conversation_members m1
             INNER JOIN conversation_members m2 ON m1.conversation_id = m2.conversation_id
             INNER JOIN conversations c ON c.id = m1.conversation_id
             WHERE m1.user_id = ? AND m2.user_id = ? AND c.type = 'private'
             LIMIT 1",
            [$userId, $otherUserId]
        );

        if ($convId) {
            return ['id' => (int)$convId, 'created' => false];
        }

        // P1-13：新建会话频控（每用户每小时最多建 MAX_NEW_CONVERSATIONS_PER_HOUR 个新私聊会话）
        // 注：creator_id 是会话创建者，对新建数量计数
        $oneHourAgo = gmdate('Y-m-d H:i:s', time() - 3600);
        $convCount = (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM conversations WHERE creator_id = ? AND type = 'private' AND created_at >= ?",
            [$userId, $oneHourAgo]
        );
        if ($convCount >= self::MAX_NEW_CONVERSATIONS_PER_HOUR) {
            throw new Exception\HublogException(
                '短时间新建私聊过多，已触发风控。请稍后再试或联系对方开启已有会话。',
                429
            );
        }

        // P1-1：dm_key 唯一键兜底，根治并发双私聊会话。
        // 单机并发用文件锁串行化；跨机并发（或锁降级）靠 uk_dm_key 唯一索引的
        // 1062 冲突回查解决——两个用户同时发起私聊时只有一个会话能建成。
        $dmKey = min($userId, $otherUserId) . '_' . max($userId, $otherUserId);
        $now = Helper::now();

        $conv = Cache::withLock('dm_' . $dmKey, static function () use ($userId, $dmKey, $now): array {
            // 锁内重查：等待锁期间可能已被其他进程建好
            $existing = Database::fetchColumn(
                "SELECT id FROM conversations WHERE dm_key = ? AND type = 'private' LIMIT 1",
                [$dmKey]
            );
            if ($existing) {
                return ['id' => (int)$existing, 'existed' => true];
            }
            try {
                $id = Database::insert('conversations', [
                    'type' => 'private',
                    'title' => '',
                    'avatar' => '',
                    'creator_id' => $userId,
                    'dm_key' => $dmKey,
                    'created_at' => $now,
                    'updated_at' => $now
                ]);
                return ['id' => $id, 'existed' => false];
            } catch (\PDOException $e) {
                // 1062 = 唯一键冲突：跨机并发下另一个进程抢先建好，回查返回它
                if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                    $existing = Database::fetchColumn(
                        "SELECT id FROM conversations WHERE dm_key = ? AND type = 'private' LIMIT 1",
                        [$dmKey]
                    );
                    if ($existing) {
                        return ['id' => (int)$existing, 'existed' => true];
                    }
                }
                throw $e;
            }
        });

        $convId = (int)($conv['id'] ?? 0);
        if (!$convId) {
            throw new Exception\HublogException('会话创建失败，请重试', 500);
        }

        // 仅本次新建时才补成员；回查到已存在会话时成员早已就齐
        // （uk_conv_user 唯一键在，重复插入会 1062）
        if (empty($conv['existed'])) {
            Database::insert('conversation_members', [
                'conversation_id' => $convId,
                'user_id' => $userId,
                'role' => 'member',
                'last_read_message_id' => 0,
                'muted' => 0,
                'joined_at' => $now,
                'created_at' => $now
            ]);
            Database::insert('conversation_members', [
                'conversation_id' => $convId,
                'user_id' => $otherUserId,
                'role' => 'member',
                'last_read_message_id' => 0,
                'muted' => 0,
                'joined_at' => $now,
                'created_at' => $now
            ]);
        }

        return ['id' => $convId, 'created' => empty($conv['existed'])];
    }

    /**
     * 获取用户会话列表（含最后一条消息和未读数）
     */
    public static function getConversations(int $userId, int $page = 1, int $limit = 20): array
    {
        $offset = ($page - 1) * $limit;

        // 会话列表 + 对方用户信息（私聊）
        // pinned 字段在 V32 迁移中新增；老库可能没有该列，动态检测
        $hasPinned = self::columnExists('conversation_members', 'pinned');
        $pinnedSelect = $hasPinned ? 'cm.pinned,' : '0 AS pinned,';
        $pinnedOrder = $hasPinned ? 'cm.pinned DESC, ' : '';
        // V48：会话软删除。列不存在时（迁移未跑）退化为不过滤，保证不报错
        $hasDeleted = self::columnExists('conversation_members', 'deleted_at');
        $deletedWhere = $hasDeleted ? ' AND cm.deleted_at IS NULL' : '';
        // V52：消息级软删除可见性 —— 自己删掉的消息只对自己隐藏，对方不受影响
        $visSql = self::visibilitySql($userId, 'm');
        // 【修复 500】包 try/catch：把 PDOException 翻译成 HublogException(500)；
        // SQLSTATE / 驱动错误码只写服务端日志，不回显给客户端。
        try {
            $conversations = Database::fetchAll(
                "SELECT c.*,
                        cm.last_read_message_id,
                        cm.muted,
                        {$pinnedSelect}
                        (SELECT COUNT(*) FROM messages m
                         WHERE m.conversation_id = c.id
                           AND m.id > cm.last_read_message_id
                           AND m.sender_id != ?
                           AND {$visSql}) AS unread_count
                 FROM conversation_members cm
                 INNER JOIN conversations c ON c.id = cm.conversation_id
                 WHERE cm.user_id = ?{$deletedWhere}
                 ORDER BY {$pinnedOrder}c.last_message_at DESC, c.id DESC
                 LIMIT ? OFFSET ?",
                [$userId, $userId, $limit, $offset]
            );
        } catch (\PDOException $e) {
            // S6：SQLSTATE / 驱动错误码不再回显给客户端，完整信息只进服务端日志。
            Logger::error('[Hublog Chat conversations] ' . $e->getMessage()
                . ' [SQLSTATE=' . ($e->errorInfo[0] ?? 'unknown')
                . ' code=' . ($e->errorInfo[1] ?? 'unknown') . ']');
            $detail = (defined('APP_DEBUG') && APP_DEBUG) ? (': ' . $e->getMessage()) : '';
            throw new Exception\HublogException('会话列表查询失败，请稍后重试' . $detail, 500);
        }

        // V146：先把本页涉及的圈子群聊的封面一次取回来（避免每个群各查一次库）
        $circleIds = [];
        foreach ($conversations as $conv) {
            if (($conv['type'] ?? '') === 'group' && !empty($conv['circle_id'])) {
                $circleIds[] = (int)$conv['circle_id'];
            }
        }
        $circleCovers = self::circleCovers($circleIds);

        $result = [];
        foreach ($conversations as $conv) {
            $item = [
                'id' => (int)$conv['id'],
                'type' => $conv['type'],
                'title' => $conv['title'],
                'avatar' => $conv['avatar'],
                'unread_count' => (int)$conv['unread_count'],
                'muted' => (int)$conv['muted'] === 1,
                'pinned' => isset($conv['pinned']) ? ((int)$conv['pinned'] === 1) : false,
                'last_read_message_id' => (int)$conv['last_read_message_id'],
                'last_message_at' => $conv['last_message_at'],
                'last_message' => null,
                'peer' => null
            ];

            // 获取最后一条消息（V52：跳过自己已删除的，取当前可见的最后一条）
            $lastMsg = Database::fetchOne(
                "SELECT m.id, m.sender_id, m.content, m.type, m.created_at
                 FROM messages m
                 WHERE m.conversation_id = ? AND {$visSql}
                 ORDER BY m.id DESC LIMIT 1",
                [$conv['id']]
            );
            if ($lastMsg) {
                $item['last_message'] = [
                    'id' => (int)$lastMsg['id'],
                    'sender_id' => (int)$lastMsg['sender_id'],
                    'content' => self::previewContent($lastMsg['content'], $lastMsg['type']),
                    'type' => $lastMsg['type'],
                    'created_at' => $lastMsg['created_at']
                ];
            }

            // 私聊会话：获取对方用户信息
            if ($conv['type'] === 'private') {
                $peer = Database::fetchOne(
                    "SELECT u.id, u.username, u.nickname, u.avatar, u.vip_level, u.avatar_frame
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
                        // V72：带上会员等级与头像框，App 端会话列表才能显示徽章 / 头像框
                        'avatar' => $peer['avatar'] ?: '',
                        'vip_level' => (int)($peer['vip_level'] ?? 0),
                        'avatar_frame' => $peer['avatar_frame'] ?? 'none'
                    ];
                    // 私聊会话标题和头像用对方信息
                    if (empty($item['title'])) {
                        $item['title'] = $peer['nickname'] ?: $peer['username'];
                    }
                    // V72：会话表的 avatar 列是建会话时写死的快照（很多是默认头像图），
                    //      对方换了头像也还是旧的 —— 私聊一律以 users 表的当前头像为准
                    $item['avatar'] = $peer['avatar'] ?: $item['avatar'];
                }
            }

            // V146：圈子群聊 —— 建群时 conversations.avatar 写的是空串，
            // 列表里就一直显示灰色默认头像，圈子换封面也不会跟着变。
            // 这里一律取圈子当前封面，与私聊「用对方当前头像」同一套思路：不认建会话时的快照。
            if ($conv['type'] === 'group' && !empty($conv['circle_id'])) {
                $cover = $circleCovers[(int)$conv['circle_id']] ?? '';
                if ($cover !== '') {
                    $item['avatar'] = $cover;
                }
            }

            $result[] = $item;
        }

        $total = (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM conversation_members cm WHERE cm.user_id = ?{$deletedWhere}",
            [$userId]
        );

        return [
            'list' => $result,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => (int)ceil($total / $limit)
            ]
        ];
    }

    /**
     * 获取会话消息历史（分页，倒序加载更早的消息）
     */
    /**
     * 获取会话消息（分页）
     *
     * P1-4：新增可选 $beforeId 游标参数（向后兼容，不传走原 OFFSET 逻辑）：
     * 传了就用 `m.id < beforeId` 定位，走 migrate89 补的 (conversation_id, id)
     * 复合索引，深翻页不再 OFFSET 深扫描；不传则保持原 page/limit 行为。
     */
    public static function getMessages(int $conversationId, int $userId, int $page = 1, int $limit = 30, int $beforeId = 0): array
    {
        if (!self::validateMembership($conversationId, $userId)) {
            throw new Exception\HublogException('无权访问此会话', 403);
        }

        $visSql = self::visibilitySql($userId, 'm');

        if ($beforeId > 0) {
            // 游标分页：从 beforeId 往前取（不含 beforeId 本身）
            $messages = Database::fetchAll(
                "SELECT m.*, u.username, u.nickname, u.avatar, u.vip_level, u.avatar_frame
                 FROM messages m
                 LEFT JOIN users u ON m.sender_id = u.id
                 WHERE m.conversation_id = ? AND m.id < ? AND {$visSql}
                 ORDER BY m.id DESC
                 LIMIT ?",
                [$conversationId, $beforeId, $limit]
            );
        } else {
            $offset = ($page - 1) * $limit;
            $messages = Database::fetchAll(
                "SELECT m.*, u.username, u.nickname, u.avatar, u.vip_level, u.avatar_frame
                 FROM messages m
                 LEFT JOIN users u ON m.sender_id = u.id
                 WHERE m.conversation_id = ? AND {$visSql}
                 ORDER BY m.id DESC
                 LIMIT ? OFFSET ?",
                [$conversationId, $limit, $offset]
            );
        }

        $list = array_map(fn($msg) => self::formatMessage($msg, $userId), array_reverse($messages));

        $total = (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM messages m WHERE m.conversation_id = ? AND {$visSql}",
            [$conversationId]
        );

        // 游标模式下附带 next_before_id，前端翻页直接用它，无需再算页码
        $nextBeforeId = 0;
        if ($messages) {
            $oldest = $messages[count($messages) - 1];
            $nextBeforeId = (int)$oldest['id'];
        }

        return [
            'list' => $list,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => (int)ceil($total / $limit),
                'next_before_id' => $nextBeforeId,
                'has_more' => $nextBeforeId > 0 && count($messages) >= $limit,
            ]
        ];
    }

    /**
     * 轮询获取新消息（指定 messageId 之后的消息）
     */
    public static function getNewMessages(int $conversationId, int $userId, int $lastMessageId = 0): array
    {
        if (!self::validateMembership($conversationId, $userId)) {
            throw new Exception\HublogException('无权访问此会话', 403);
        }

        $visSql = self::visibilitySql($userId, 'm');
        $messages = Database::fetchAll(
            "SELECT m.*, u.username, u.nickname, u.avatar, u.vip_level, u.avatar_frame
             FROM messages m
             LEFT JOIN users u ON m.sender_id = u.id
             WHERE m.conversation_id = ? AND m.id > ? AND {$visSql}
             ORDER BY m.id ASC
             LIMIT 100",
            [$conversationId, $lastMessageId]
        );

        return array_map(fn($msg) => self::formatMessage($msg, $userId), $messages);
    }
}
