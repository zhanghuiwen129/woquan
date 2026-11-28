<?php
/**
 * ChatService · ChatSendTrait
 * V99 结构拆分：发送、已读与未读计数。
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

trait ChatSendTrait
{

    /**
     * 发送消息
     */
    public static function sendMessage(
        int $conversationId,
        int $senderId,
        string $content,
        string $type = 'text',
        ?array $extra = null,
        string $clientMsgId = ''
    ): array
    {
        if (!self::validateMembership($conversationId, $senderId)) {
            throw new Exception\HublogException('无权在此会话发送消息', 403);
        }

        $content = trim($content);
        if ($type === 'text' && $content === '') {
            throw new Exception\HublogException('消息内容不能为空', 400);
        }
        if (mb_strlen($content) > 5000) {
            throw new Exception\HublogException('消息内容过长', 400);
        }

        // P1-13：私聊场景再次校验双向拉黑（会话成员可能是很久以前加入的，中途被拉黑应当阻止发送）
        // 通过 conversation_members 找出「除发送方以外的其他成员」进行双向拉黑校验
        $otherMembers = Database::fetchAll(
            "SELECT user_id FROM conversation_members WHERE conversation_id = ? AND user_id != ?",
            [$conversationId, $senderId]
        );
        foreach ($otherMembers as $m) {
            $blockedErr = self::checkBlockedBothWays($senderId, (int)$m['user_id']);
            if ($blockedErr !== null) {
                throw new Exception\HublogException($blockedErr, 403);
            }
        }

        // P1-13：按「会话 + 分钟窗口」频率限制，防止刷屏与存储放大攻击
        $oneMinAgo = gmdate('Y-m-d H:i:s', time() - 60);
        $msgCount = (int)Database::fetchColumn(
            "SELECT COUNT(*) FROM messages WHERE conversation_id = ? AND sender_id = ? AND created_at >= ?",
            [$conversationId, $senderId, $oneMinAgo]
        );
        if ($msgCount >= self::MAX_MESSAGES_PER_CONV_PER_MIN) {
            throw new Exception\HublogException(
                '发送过于频繁，单会话每分钟最多 ' . self::MAX_MESSAGES_PER_CONV_PER_MIN . ' 条消息。请稍后再试。',
                429
            );
        }

        // V98：幂等键查重放在所有校验（含频控）之后、insert 之前。
        // 命中说明同一发送者的同一条客户端消息已落库，直接返回既有消息，不重复插入 / 通知。
        if ($clientMsgId !== '') {
            $existingId = Database::fetchColumn(
                "SELECT id FROM messages WHERE sender_id = ? AND client_msg_id = ? LIMIT 1",
                [$senderId, $clientMsgId]
            );
            if ($existingId) {
                return self::formatMessageById((int)$existingId, $senderId);
            }
        }

        $now = Helper::now();
        $extraJson = $extra !== null ? json_encode($extra, JSON_UNESCAPED_UNICODE) : null;

        // V98：核心写链（消息 → 指纹 → 会话摘要 → 发送者已读）纳入同一事务。
        // 通知 / SSE / 缓存失效不是 DB 事务资源，放在 commit 之后（见下方）。
        Database::beginTransaction();
        try {
            $messageData = [
                'conversation_id' => $conversationId,
                'sender_id' => $senderId,
                'content' => $content,
                'type' => $type,
                'extra' => $extraJson,
                'created_at' => $now
            ];
            // 仅非空时写入幂等键；唯一索引 uk_msg_client(sender_id, client_msg_id) 兜并发重试
            if ($clientMsgId !== '') {
                $messageData['client_msg_id'] = $clientMsgId;
            }
            $messageId = (int)Database::insert('messages', $messageData);

            // V52：写内容指纹，导出取证时可校验消息是否被篡改（列不存在时静默跳过）
            if (self::columnExists('messages', 'content_hash')) {
                try {
                    Database::update('messages', ['content_hash' => self::contentHash($content, $messageId)], 'id = ?', [$messageId]);
                } catch (\Throwable $e) {
                    Logger::error('[Hublog Chat content_hash] ' . $e->getMessage());
                }
            }

            // 更新会话的最后消息信息
            Database::update('conversations', [
                'last_message_id' => $messageId,
                'last_message_at' => $now,
                'updated_at' => $now
            ], 'id = ?', [$conversationId]);

            // 发送者自动标记已读
            self::markAsRead($conversationId, $senderId, $messageId);

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            // 并发重试撞上 uk_msg_client：回查已由另一请求提交的那条消息，按幂等口径返回
            if ($clientMsgId !== '' && $e instanceof \PDOException
                && (int)($e->errorInfo[1] ?? 0) === 1062) {
                $existingId = Database::fetchColumn(
                    "SELECT id FROM messages WHERE sender_id = ? AND client_msg_id = ? LIMIT 1",
                    [$senderId, $clientMsgId]
                );
                if ($existingId) {
                    return self::formatMessageById((int)$existingId, $senderId);
                }
            }
            throw $e;
        }

        // 给会话中其他成员创建通知（让接收方在消息中心也能看到"有人给你发了私信"）
        // P0-C：try/catch 必须逐成员隔离。旧实现用一个大 try 包住整个 foreach，
        // 群聊里第一个成员的 insert 抛异常（如老库缺 conversation_id）就跳到 catch，
        // 后续所有成员的 notification + SSE 全部丢失。改为每个成员独立兜底，
        // 单个成员失败只记日志并 continue，不影响其余成员。
        $members = Database::fetchAll(
            "SELECT user_id FROM conversation_members WHERE conversation_id = ? AND user_id != ?",
            [$conversationId, $senderId]
        );
        // 通知内容摘要：先生成原文，再统一净化一次（写入端净化，输出点仍保留 escape）
        $rawPreview = $type === 'image' ? '[图片]' : ($type === 'video' ? '[视频]' : ($type === 'file' ? '[文件]' : mb_substr($content, 0, 100)));
        $preview = Security::purify($rawPreview);
        foreach ($members as $member) {
            $memberId = (int)$member['user_id'];
            // P1-2：无论免打扰与否，新消息都计入对方未读 → 先失效未读缓存再建通知。
            // 缓存失效放在成员 try 之外，确保即便该成员通知写入失败，未读角标仍正确。
            self::invalidateUnreadCache($memberId);
            Cache::delete("notif_unread_{$memberId}");
            try {
                // 查看对方是否已读（避免给已读的人发多余通知）
                $memberRow = Database::fetchOne(
                    "SELECT last_read_message_id, muted FROM conversation_members WHERE conversation_id = ? AND user_id = ?",
                    [$conversationId, $memberId]
                );
                $isRead = $memberRow && (int)$memberRow['last_read_message_id'] >= $messageId;
                // 免打扰成员不创建通知（避免打扰）
                $muted = $memberRow && (int)$memberRow['muted'] === 1;
                if ($muted) continue;

                Database::insert('notifications', [
                    'user_id' => $memberId,
                    'sender_id' => $senderId,
                    'type' => 'chat',
                    'post_id' => null,
                    'comment_id' => null,
                    'conversation_id' => $conversationId,
                    'content' => $preview,
                    'is_read' => $isRead ? 1 : 0,
                    'created_at' => $now
                ]);
                // P1-3：实时推送给会话成员
                SsePush::push($memberId, 'chat_message', [
                    'conversation_id' => $conversationId,
                    'message_id' => $messageId,
                    'sender_id' => $senderId,
                    'preview' => $preview,
                ]);
            } catch (\Throwable $e) {
                // 单个成员失败不影响其他人（群聊关键健壮性）
                Logger::error('[Hublog Chat notification] member ' . $memberId . ': ' . $e->getMessage());
            }
        }

        // 返回格式化消息（正常路径与幂等命中路径共用同一出口）
        return self::formatMessageById($messageId, $senderId);
    }

    /**
     * 标记会话消息已读（到指定 messageId 为止）
     */
    public static function markAsRead(int $conversationId, int $userId, int $messageId): void
    {
        Database::update(
            'conversation_members',
            ['last_read_message_id' => $messageId],
            'conversation_id = ? AND user_id = ? AND last_read_message_id < ?',
            [$conversationId, $userId, $messageId]
        );

        // P1-2：已读位置变化立即失效未读缓存（否则轮询角标最多粘 10s）
        self::invalidateUnreadCache($userId);
        Cache::delete("notif_unread_{$userId}");

        // 同步将该会话相关的私信通知标记为已读
        try {
            Database::update(
                'notifications',
                ['is_read' => 1],
                'user_id = ? AND type = ? AND conversation_id = ? AND is_read = 0',
                [$userId, 'chat', $conversationId]
            );
        } catch (\Throwable $e) {
            // 忽略（兼容老库无 conversation_id 列）
        }
    }

    /**
     * 获取用户总未读消息数
     *
     * P1-2：轮询接口（/chat/unread-count 在限流白名单里，2400 次/分钟）原来每次
     * 实时 COUNT(join messages)，是未读角标场景的主要 DB 压力源。改为 10s 短 TTL
     * 缓存 + 事件精确失效（来新消息/标记已读立即删 key），正确性不变、DB 压力降一个量级。
     */
    public static function getTotalUnread(int $userId): int
    {
        try {
            return (int)Cache::remember(
                "chat_unread_{$userId}",
                static function () use ($userId): int {
                    // V48 软删除口径对齐：会话列表过滤了 deleted_at，未读数也必须过滤，
                    // 否则「左滑删除带未读的会话」后角标永远挂着幽灵未读（列表为空但 badge > 0）
                    $hasDeleted = self::columnExists('conversation_members', 'deleted_at');
                    $deletedWhere = $hasDeleted ? ' AND cm.deleted_at IS NULL' : '';
                    // 与会话列表口径一致：消息级可见性也要计入未读（后台下架/清理后不再计数）
                    $visSql = self::visibilitySql($userId, 'm');
                    return (int)Database::fetchColumn(
                        "SELECT COUNT(*)
                         FROM conversation_members cm
                         INNER JOIN messages m ON m.conversation_id = cm.conversation_id
                         WHERE cm.user_id = ? AND m.id > cm.last_read_message_id AND m.sender_id != ?{$deletedWhere}
                           AND {$visSql}",
                        [$userId, $userId]
                    );
                },
                self::UNREAD_CACHE_TTL
            );
        } catch (\PDOException $e) {
            // S6：SQLSTATE / 驱动错误码不再回显给客户端，完整信息只进服务端日志。
            Logger::error('[Hublog Chat unread] ' . $e->getMessage()
                . ' [SQLSTATE=' . ($e->errorInfo[0] ?? 'unknown')
                . ' code=' . ($e->errorInfo[1] ?? 'unknown') . ']');
            $detail = (defined('APP_DEBUG') && APP_DEBUG) ? (': ' . $e->getMessage()) : '';
            throw new Exception\HublogException('未读数查询失败，请稍后重试' . $detail, 500);
        }
    }

    /**
     * P1-2：失效指定用户的未读数缓存（来新消息 / 标记已读 / 会话删除时调用）
     */
    public static function invalidateUnreadCache(int $userId): void
    {
        if ($userId > 0) {
            Cache::delete("chat_unread_{$userId}");
        }
    }

    /**
     * 验证用户是否为会话成员
     */
    public static function validateMembership(int $conversationId, int $userId): bool
    {
        $count = Database::fetchColumn(
            "SELECT COUNT(*) FROM conversation_members WHERE conversation_id = ? AND user_id = ?",
            [$conversationId, $userId]
        );
        return (int)$count > 0;
    }
}
