<?php
/**
 * ChatService · ChatSchemaTrait
 * V99 结构拆分：表 / 列探针与 V99 软删除能力闸门（老库缺列时 fail-closed）。
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

trait ChatSchemaTrait
{

    /**
     * 检查表是否存在指定列（兼容老库缺字段场景）
     */
    /**
     * 判断表中是否存在某列（迁移可能未跑到，查询前先探测再决定要不要引用该列）
     * 对外可见：Controller 侧也需要按列存在与否决定行为
     */
    public static function columnExists(string $table, string $column): bool
    {
        try {
            $row = Database::fetchOne(
                "SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1",
                [$table, $column]
            );
            return !empty($row);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 消息内容指纹：sha256(内容 + 消息ID + 服务端盐)，导出时用于校验是否被篡改
     */
    public static function contentHash(string $content, int $messageId = 0): string
    {
        $salt = defined('JWT_SECRET') ? (string)JWT_SECRET : 'hublog-message-salt';
        return hash('sha256', $salt . '|' . $messageId . '|' . $content);
    }

    /**
     * 判断表是否存在
     */
    public static function tableExists(string $table): bool
    {
        try {
            $row = Database::fetchOne(
                "SELECT 1 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1",
                [$table]
            );
            return !empty($row);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** V99：messages 三列软删除字段是否齐备（缺一即视为未完成升级） */
    public static function messageSoftDeleteReady(): bool
    {
        foreach (self::REQUIRED_MESSAGE_SOFT_COLUMNS as $column) {
            if (!self::columnExists('messages', $column)) {
                return false;
            }
        }
        return self::tableExists('messages');
    }

    /** V99：conversations 会话级软删列是否齐备 */
    public static function conversationSoftDeleteReady(): bool
    {
        foreach (self::REQUIRED_CONVERSATION_SOFT_COLUMNS as $column) {
            if (!self::columnExists('conversations', $column)) {
                return false;
            }
        }
        return true;
    }

    /** V99：conversation_members 成员级软删列是否齐备 */
    public static function memberSoftDeleteReady(): bool
    {
        foreach (self::REQUIRED_MEMBER_SOFT_COLUMNS as $column) {
            if (!self::columnExists('conversation_members', $column)) {
                return false;
            }
        }
        return true;
    }

    /** V99：所有删除入口的统一能力闸门——不满足就零写入 + 503，绝不物理删 */
    public static function requireMessageSoftDeleteReady(): void
    {
        if (!self::messageSoftDeleteReady()
            || !self::conversationSoftDeleteReady()
            || !self::memberSoftDeleteReady()) {
            throw new Exception\HublogException('服务端未完成软删除升级，操作已拒绝', 503);
        }
    }
}
