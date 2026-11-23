<?php
/**
 * ChatService · ChatFormatTrait
 * V99 结构拆分：消息格式化与预览文本。
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

trait ChatFormatTrait
{

    /**
     * V146：批量取圈子封面（circle_id => cover）。
     *
     * 圈子群聊的会话头像在 conversations 表里恒为空（建群时没写），
     * 会话列表要显示圈子封面，所以列表页按页一次性把封面取回来，避免 N+1。
     * 圈子表/列缺失（老库）时静默返回空表，由调用方回退到原快照值。
     */
    private static function circleCovers(array $circleIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $circleIds))));
        if (!$ids) {
            return [];
        }
        try {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $rows = Database::fetchAll("SELECT id, cover FROM circles WHERE id IN ({$ph})", $ids);
            $map = [];
            foreach ($rows as $r) {
                $map[(int)$r['id']] = (string)($r['cover'] ?? '');
            }
            return $map;
        } catch (\Throwable $e) {
            Logger::error('[Hublog ChatService circleCovers] ' . $e->getMessage());
            return [];
        }
    }

    /**
     * 按消息 id 查询并以指定查看者视角格式化。
     *
     * sendMessage() 的正常提交路径与 client_msg_id 幂等命中路径共用此方法，
     * 避免两处各自维护同一段查询 + 格式化逻辑而发生口径漂移。
     */
    private static function formatMessageById(int $messageId, int $viewerId): array
    {
        $msg = Database::fetchOne(
            "SELECT m.*, u.username, u.nickname, u.avatar, u.vip_level, u.avatar_frame
             FROM messages m
             LEFT JOIN users u ON m.sender_id = u.id
             WHERE m.id = ? LIMIT 1",
            [$messageId]
        );
        if (!$msg) {
            throw new Exception\HublogException('消息不存在', 404);
        }

        return self::formatMessage($msg, $viewerId);
    }

    /**
     * 格式化消息为 API 响应格式
     */
    private static function formatMessage(array $msg, int $currentUserId): array
    {
        $extra = null;
        if (!empty($msg['extra'])) {
            $extra = json_decode($msg['extra'], true);
        }

        return [
            'id' => (int)$msg['id'],
            'conversation_id' => (int)$msg['conversation_id'],
            'sender_id' => (int)$msg['sender_id'],
            'content' => Security::escape($msg['content'] ?? ''),
            'type' => $msg['type'],
            'extra' => $extra,
            'is_self' => (int)$msg['sender_id'] === $currentUserId,
            'sender' => [
                'id' => (int)$msg['sender_id'],
                'username' => $msg['username'] ?? '',
                'nickname' => $msg['nickname'] ?: ($msg['username'] ?? ''),
                // V72：会员等级与头像框一起下发，聊天头像才能显示徽章 / 头像框
                'avatar' => $msg['avatar'] ?? '',
                'vip_level' => (int)($msg['vip_level'] ?? 0),
                'avatar_frame' => $msg['avatar_frame'] ?? 'none'
            ],
            'created_at' => $msg['created_at']
        ];
    }

    /**
     * 生成消息预览文本（用于会话列表）
     */
    private static function previewContent(string $content, string $type): string
    {
        return match ($type) {
            'image' => '[图片]',
            'video' => '[视频]',
            'file' => '[文件]',
            'system' => '[系统消息]',
            default => mb_strlen($content) > 50 ? mb_substr($content, 0, 50) . '...' : $content
        };
    }
}
