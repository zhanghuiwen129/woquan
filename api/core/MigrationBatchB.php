<?php
/**
 * Hublog 增量数据库迁移方法集合 (V46 - V65)
 *
 * 由 api/core/Migration.php 拆分而来，主迁移类 Hublog\Core\Migration
 * 通过 `use MigrationBatchB` 引入这些方法，旧版本升级时仍按版本号顺序执行；
 * 全新安装走 buildFullSchema() 全量建表后循环补跑增量迁移。
 *
 * 拆分零行为差异：trait 方法在编译期展平进主类，run() 的动态分发
 * self::{"migrate{$v}"}() 与新装补跑循环均正常命中，无需改动调用方。
 */

declare(strict_types=1);

namespace Hublog\Core;

trait MigrationBatchB
{

    /**
     * V46：新增 push_tokens 表（设备推送令牌，为离线推送做准备）
     */
    private static function migrate46(): void
    {
        try {
            Database::getInstance()->exec(
                "CREATE TABLE IF NOT EXISTS `push_tokens` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) unsigned NOT NULL,
  `provider` varchar(16) NOT NULL DEFAULT 'unipush' COMMENT 'unipush/jpush/getui/apns/fcm',
  `token` varchar(512) NOT NULL COMMENT '推送厂商下发的设备令牌',
  `device_id` varchar(64) NOT NULL DEFAULT '' COMMENT '客户端设备唯一串',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_provider_token` (`provider`, `token`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='设备推送令牌表'"
            );
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V46 push_tokens] ' . $e->getMessage());
            if (APP_DEBUG) {
                throw $e;
            }
        }
    }


    /**
     * V47：IM 增强
     *
     *  1) messages.type 增加 'voice' 枚举值 —— 语音消息
     *  2) messages 增加 is_read / recalled_at
     *     - is_read：单条已读回执，聊天页要显示「已读/未读」
     *     - recalled_at：撤回标记，撤回后前端渲染「消息已撤回」而不是真删
     *
     * 说明：ALTER 不支持 IF NOT EXISTS，所以每步都先探测再执行，
     * 重复运行 / 手工改过表结构都不会炸。
     */
    private static function migrate47(): void
    {
        $db = Database::getInstance();

        // 1) type 枚举追加 voice
        try {
            $row = Database::fetchOne(
                "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'messages' AND COLUMN_NAME = 'type'"
            );
            $colType = is_array($row) ? (string)($row['COLUMN_TYPE'] ?? '') : '';
            if ($colType !== '' && stripos($colType, 'voice') === false) {
                $newType = preg_replace(
                    "/\)$/",
                    ",'voice')",
                    rtrim($colType)
                );
                // enum('a','b') -> enum('a','b','voice')
                if ($newType && $newType !== $colType) {
                    $db->exec("ALTER TABLE `messages` MODIFY COLUMN `type` {$newType} NOT NULL DEFAULT 'text' COMMENT '消息类型'");
                }
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V47 messages.type voice] ' . $e->getMessage());
            if (APP_DEBUG) {
                throw $e;
            }
        }

        // 2) is_read
        try {
            $row = Database::fetchOne(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'messages' AND COLUMN_NAME = 'is_read'"
            );
            if (!$row) {
                $db->exec(
                    "ALTER TABLE `messages`
                     ADD COLUMN `is_read` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否已读（单条已读回执）' AFTER `extra`"
                );
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V47 messages.is_read] ' . $e->getMessage());
            if (APP_DEBUG) {
                throw $e;
            }
        }

        // 3) recalled_at
        try {
            $row = Database::fetchOne(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'messages' AND COLUMN_NAME = 'recalled_at'"
            );
            if (!$row) {
                $db->exec(
                    "ALTER TABLE `messages`
                     ADD COLUMN `recalled_at` datetime DEFAULT NULL COMMENT '撤回时间（NULL=未撤回）' AFTER `is_read`"
                );
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V47 messages.recalled_at] ' . $e->getMessage());
            if (APP_DEBUG) {
                throw $e;
            }
        }

        // 4) comments.is_pinned —— 博主/管理员把某条评论置顶
        try {
            $row = Database::fetchOne(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'comments' AND COLUMN_NAME = 'is_pinned'"
            );
            if (!$row) {
                $db->exec(
                    "ALTER TABLE `comments`
                     ADD COLUMN `is_pinned` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否被博主置顶' AFTER `image`"
                );
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V47 comments.is_pinned] ' . $e->getMessage());
            if (APP_DEBUG) {
                throw $e;
            }
        }
    }


    /**
     * V48: 会话软删除 —— 用户左滑删除会话时只对自己隐藏，不影响对方
     */
    private static function migrate48(): void
    {
        $db = Database::getInstance();

        try {
            $row = Database::fetchOne(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'conversation_members' AND COLUMN_NAME = 'deleted_at'"
            );
            if (!$row) {
                $db->exec(
                    "ALTER TABLE `conversation_members`
                     ADD COLUMN `deleted_at` datetime DEFAULT NULL COMMENT '成员删除会话的时间（NULL=未删除）' AFTER `joined_at`"
                );
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V48 conversation_members.deleted_at] ' . $e->getMessage());
            if (APP_DEBUG) {
                throw $e;
            }
        }
    }


    /**
     * V49: 圈子功能 —— 新增 circles / circle_members 两张表，posts 加 circle_id 列。
     * 圈子动态可见性：posts.circle_id IS NULL = 全站动态（默认），非 NULL = 仅该圈子内可见。
     */
    private static function migrate49(): void
    {
        $db = Database::getInstance();

        // 1) circles 表
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS `circles` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(50) NOT NULL COMMENT '圈子名称',
  `slug` VARCHAR(60) NOT NULL COMMENT 'URL标识',
  `description` TEXT DEFAULT NULL COMMENT '圈子简介',
  `cover` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '圈子封面图URL',
  `owner_id` INT UNSIGNED NOT NULL COMMENT '圈主用户ID',
  `member_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '成员数（冗余计数）',
  `post_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '动态数（冗余计数）',
  `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=正常 0=隐藏',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_slug` (`slug`),
  KEY `idx_owner_id` (`owner_id`),
  KEY `idx_status` (`status`),
  KEY `idx_member_count` (`member_count`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='圈子表'");
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V49 circles] ' . $e->getMessage());
            if (APP_DEBUG) {
                throw $e;
            }
        }

        // 2) circle_members 表
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS `circle_members` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `circle_id` INT UNSIGNED NOT NULL COMMENT '圈子ID',
  `user_id` INT UNSIGNED NOT NULL COMMENT '成员用户ID',
  `role` ENUM('owner','admin','member') NOT NULL DEFAULT 'member' COMMENT '成员角色',
  `joined_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '加入时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_circle_user` (`circle_id`, `user_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_circle_role` (`circle_id`, `role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='圈子成员表'");
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V49 circle_members] ' . $e->getMessage());
            if (APP_DEBUG) {
                throw $e;
            }
        }

        // 3) posts 表加 circle_id 字段（旧库没有则需要 ALTER）
        try {
            $row = Database::fetchOne(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'circle_id'"
            );
            if (!$row) {
                $db->exec(
                    "ALTER TABLE `posts`
                     ADD COLUMN `circle_id` int(11) unsigned DEFAULT NULL COMMENT '所属圈子ID（NULL=全站动态，非NULL=仅圈子内可见）' AFTER `pinned_at`"
                );
                $db->exec("ALTER TABLE `posts` ADD KEY `idx_circle_id` (`circle_id`)");
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V49 posts.circle_id] ' . $e->getMessage());
            if (APP_DEBUG) {
                throw $e;
            }
        }
    }


    /**
     * V50: 圈子权限扩展 ——
     *   1) circles 表加 3 个字段：join_mode（加入方式）/ post_permission（发帖权限）/ visibility（可见性）
     *   2) 新增 circle_join_requests 表：approval 模式下用户申请入圈，圈主/管理员审批
     *
     * 字段语义：
     *   - join_mode: free=自由加入 / approval=需审核 / invite=仅邀请
     *   - post_permission: all=所有成员可发 / admin=仅管理员可发 / owner=仅圈主可发
     *   - visibility: public=公开列表可见 / protected=列表可见但内容仅成员 / private=不公开列表
     */
    private static function migrate50(): void
    {
        $db = Database::getInstance();

        // 1) circles 表加 3 个权限字段（幂等 ALTER）
        $newColumns = [
            'join_mode'       => "ADD COLUMN `join_mode` VARCHAR(15) NOT NULL DEFAULT 'free' COMMENT '加入方式：free/approval/invite' AFTER `post_count`",
            'post_permission' => "ADD COLUMN `post_permission` VARCHAR(15) NOT NULL DEFAULT 'all' COMMENT '发帖权限：all/admin/owner' AFTER `join_mode`",
            'visibility'      => "ADD COLUMN `visibility` VARCHAR(15) NOT NULL DEFAULT 'public' COMMENT '可见性：public/protected/private' AFTER `post_permission`",
        ];
        foreach ($newColumns as $col => $alterSql) {
            try {
                $exists = Database::fetchOne(
                    "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'circles' AND COLUMN_NAME = ?",
                    [$col]
                );
                if (!$exists) {
                    $db->exec("ALTER TABLE `circles` {$alterSql}");
                }
            } catch (\Throwable $e) {
                error_log("[Hublog Migration V50 circles.{$col}] " . $e->getMessage());
                if (APP_DEBUG) {
                    throw $e;
                }
            }
        }

        // 加可见性索引（如已存在则跳过）
        try {
            $exists = Database::fetchOne(
                "SELECT INDEX_NAME FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'circles' AND INDEX_NAME = 'idx_visibility'"
            );
            if (!$exists) {
                $db->exec("ALTER TABLE `circles` ADD KEY `idx_visibility` (`visibility`)");
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V50 circles.idx_visibility] ' . $e->getMessage());
        }

        // 2) circle_join_requests 表
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS `circle_join_requests` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `circle_id` INT UNSIGNED NOT NULL COMMENT '圈子ID',
  `user_id` INT UNSIGNED NOT NULL COMMENT '申请用户ID',
  `status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending' COMMENT '审核状态',
  `message` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '申请留言',
  `reviewer_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '审核人ID',
  `reviewed_at` DATETIME NULL DEFAULT NULL COMMENT '审核时间',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '申请时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_circle_pending` (`circle_id`, `user_id`, `status`),
  KEY `idx_circle_status` (`circle_id`, `status`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='圈子加入申请表'");
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V50 circle_join_requests] ' . $e->getMessage());
            if (APP_DEBUG) {
                throw $e;
            }
        }
    }


    /**
     * V51: 实名认证 ——
     *   1) users 表加 realname_verified / realname_verified_at（冗余状态，列表展示用）
     *   2) 新增 realname_records 表：只存脱敏姓名、脱敏身份证号与身份证哈希，不存明文
     *
     * 合规说明：身份证号属于敏感个人信息，按最小必要原则只落库脱敏值与哈希，
     * 哈希用于「一个身份证只能认证一个账号」的查重，不用于还原原文。
     */
    private static function migrate51(): void
    {
        $db = Database::getInstance();

        // 1) users 表加实名状态字段（幂等 ALTER）
        $newColumns = [
            'realname_verified' => "ADD COLUMN `realname_verified` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否已通过实名认证' AFTER `bio`",
            'realname_verified_at' => "ADD COLUMN `realname_verified_at` DATETIME DEFAULT NULL COMMENT '实名认证通过时间' AFTER `realname_verified`",
        ];
        foreach ($newColumns as $col => $alterSql) {
            try {
                $exists = Database::fetchOne(
                    "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = ?",
                    [$col]
                );
                if (!$exists) {
                    $db->exec("ALTER TABLE `users` {$alterSql}");
                }
            } catch (\Throwable $e) {
                error_log("[Hublog Migration V51 users.{$col}] " . $e->getMessage());
                if (APP_DEBUG) {
                    throw $e;
                }
            }
        }

        // 2) realname_records 表
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS `realname_records` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL COMMENT '用户ID',
  `real_name_masked` VARCHAR(20) NOT NULL DEFAULT '' COMMENT '脱敏姓名（如 张*）',
  `id_card_masked` VARCHAR(30) NOT NULL DEFAULT '' COMMENT '脱敏身份证号（如 3****************7）',
  `id_card_hash` CHAR(64) NOT NULL COMMENT '身份证号 sha256 哈希（加盐），用于查重防刷',
  `provider` VARCHAR(20) NOT NULL DEFAULT 'aliyun_market' COMMENT '核验服务商标识',
  `status` ENUM('pending','verified','rejected','revoked') NOT NULL DEFAULT 'pending' COMMENT 'pending待核验/verified已通过/rejected未通过/revoked已撤销',
  `fail_reason` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '未通过或驳回原因',
  `provider_code` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '服务商返回码',
  `reviewer_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '人工审核人ID（0=系统自动核验）',
  `reviewed_at` DATETIME NULL DEFAULT NULL COMMENT '人工审核时间',
  `verified_at` DATETIME NULL DEFAULT NULL COMMENT '通过时间',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_id_card_hash` (`id_card_hash`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='实名认证记录表（脱敏存储）'");
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V51 realname_records] ' . $e->getMessage());
            if (APP_DEBUG) {
                throw $e;
            }
        }
    }


    /**
     * V52: 聊天记录"永不物理删除"改造。
     * 背景：用户长按"彻底删除"原先执行 DELETE FROM messages，行被物理抹掉，
     *      导致用户自己无法找回、管理员无法审计、需要取证时无据可查。
     * 方案：
     *  1) messages 增加 deleted_at / deleted_by / delete_scope / content_hash，
     *     删除一律改为打标记，行永久保留；
     *  2) message_deletions 记录每一次删除动作的原文快照（append-only）；
     *  3) admin_audit_logs 记录管理员的查询/导出/删除行为。
     */
    private static function migrate52(): void
    {
        $db = Database::getInstance();

        // 0) conversations 增加下架标记（管理员"删除会话"改为下架，行保留）
        try {
            $exists = Database::fetchOne(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'conversations' AND COLUMN_NAME = 'deleted_at'"
            );
            if (!$exists) {
                $db->exec("ALTER TABLE `conversations` ADD COLUMN `deleted_at` DATETIME DEFAULT NULL COMMENT '管理员下架时间（NULL=正常；会话行不物理删除）' AFTER `last_message_at`");
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V52 conversations.deleted_at] ' . $e->getMessage());
            if (APP_DEBUG) {
                throw $e;
            }
        }

        // 1) messages 新增软删除相关列（幂等）
        $newColumns = [
            'deleted_at'   => "ADD COLUMN `deleted_at` DATETIME DEFAULT NULL COMMENT '软删除时间（NULL=正常；消息永不物理删除，仅供审计与取证）' AFTER `created_at`",
            'deleted_by'   => "ADD COLUMN `deleted_by` INT UNSIGNED DEFAULT NULL COMMENT '删除操作人ID（用户ID或管理员ID）' AFTER `deleted_at`",
            'delete_scope' => "ADD COLUMN `delete_scope` ENUM('self','admin','purge') DEFAULT NULL COMMENT 'self=发送者自己删除(仅自己不可见) admin=管理员下架(所有人不可见) purge=清理任务' AFTER `deleted_by`",
            'content_hash' => "ADD COLUMN `content_hash` CHAR(64) DEFAULT NULL COMMENT '内容指纹 sha256(加盐)，导出时校验是否被篡改' AFTER `delete_scope`",
        ];
        foreach ($newColumns as $col => $alterSql) {
            try {
                $exists = Database::fetchOne(
                    "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'messages' AND COLUMN_NAME = ?",
                    [$col]
                );
                if (!$exists) {
                    $db->exec("ALTER TABLE `messages` {$alterSql}");
                }
            } catch (\Throwable $e) {
                error_log("[Hublog Migration V52 messages.{$col}] " . $e->getMessage());
                if (APP_DEBUG) {
                    throw $e;
                }
            }
        }

        try {
            $db->exec("CREATE INDEX `idx_deleted_at` ON `messages` (`deleted_at`)");
        } catch (\Throwable $e) {
            // 索引已存在时会报错，忽略
        }

        // 2) 删除审计表
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS `message_deletions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `message_id` BIGINT UNSIGNED NOT NULL COMMENT '被删消息ID',
  `conversation_id` BIGINT UNSIGNED NOT NULL COMMENT '所属会话ID',
  `sender_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '原消息发送者',
  `operator_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '操作人ID',
  `operator_role` ENUM('user','admin','system') NOT NULL DEFAULT 'user' COMMENT '操作人角色',
  `scope` ENUM('self','admin','purge','restore') NOT NULL DEFAULT 'self' COMMENT 'self自身删除/admin管理员下架/purge清理/restore恢复',
  `content_snapshot` MEDIUMTEXT DEFAULT NULL COMMENT '删除前的内容快照（取证用，永久保留）',
  `message_created_at` DATETIME DEFAULT NULL COMMENT '原消息发送时间',
  `ip` VARCHAR(45) NOT NULL DEFAULT '' COMMENT '操作IP',
  `user_agent` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '操作UA',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_message_id` (`message_id`),
  KEY `idx_conversation_id` (`conversation_id`),
  KEY `idx_operator_id` (`operator_id`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='消息删除审计表'");
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V52 message_deletions] ' . $e->getMessage());
            if (APP_DEBUG) {
                throw $e;
            }
        }

        // 3) 后台操作审计表
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS `admin_audit_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '管理员ID',
  `action` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '动作标识，如 chat.export / chat.view',
  `target_type` VARCHAR(30) NOT NULL DEFAULT '' COMMENT '对象类型 message/conversation',
  `target_id` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '对象ID',
  `detail` TEXT DEFAULT NULL COMMENT '补充信息（筛选条件、条数等）',
  `ip` VARCHAR(45) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_admin_id` (`admin_id`),
  KEY `idx_action` (`action`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='后台操作审计日志表'");
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V52 admin_audit_logs] ' . $e->getMessage());
            if (APP_DEBUG) {
                throw $e;
            }
        }
    }


    /**
     * V53: users.nickname 唯一索引（数据库级防重兜底）
     *
     * 前端（check-nickname 实时查重）+ 后端（updateProfile 返回 409）都已做昵称防重，
     * 但并发写入、历史脏数据、OAuth 默认昵称（如多个「微信用户」）仍可能漏进来。
     * 这里在数据库层面兜底，步骤：
     *  1) 索引已存在 → 直接跳过（幂等，重复部署 / 重复执行都安全）；
     *  2) 空昵称（NULL / '' / 纯空格）→ 回填为 username（username 本身唯一，
     *     且前端展示本就是 nickname ?: username，用户完全无感）；
     *  3) 历史重复昵称 → 保留最早注册（id 最小）的一个原样不动，其余追加「#用户ID」消歧；
     *  4) ADD UNIQUE KEY；万一仍失败（极罕见残留冲突）只写 error_log，不阻断整站。
     */
    private static function migrate53(): void
    {
        $db = Database::getInstance();

        // 1) 幂等检查：索引已存在则跳过
        try {
            $exists = Database::fetchOne(
                "SELECT INDEX_NAME FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'uk_users_nickname' LIMIT 1"
            );
            if ($exists) {
                return;
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V53 索引检查失败，本次跳过] ' . $e->getMessage());
            return;
        }

        // 2) 空昵称回填为 username（唯一索引下多个空串会 1062 冲突）
        try {
            $db->exec("UPDATE `users` SET `nickname` = `username`
                       WHERE `nickname` IS NULL OR TRIM(`nickname`) = ''");
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V53 空昵称回填] ' . $e->getMessage());
        }

        // 3) 历史重复昵称消歧：保留 id 最小的，其余追加 #uid
        try {
            $dupRows = Database::fetchAll(
                "SELECT `nickname` FROM `users`
                 WHERE `nickname` IS NOT NULL AND `nickname` <> ''
                 GROUP BY `nickname` HAVING COUNT(*) > 1"
            ) ?: [];
            $fixed = 0;
            foreach ($dupRows as $row) {
                $nick = (string)$row['nickname'];
                $ids  = array_column(
                    Database::fetchAll("SELECT `id` FROM `users` WHERE `nickname` = ? ORDER BY `id` ASC", [$nick]) ?: [],
                    'id'
                );
                if (count($ids) < 2) {
                    continue;
                }
                array_shift($ids); // 最早注册的那个保持原昵称
                $base = mb_substr($nick, 0, 38, 'UTF-8'); // 38 + '#' + 最多 10 位 ID ≤ 49 < varchar(50)
                foreach ($ids as $uid) {
                    $uid     = (int)$uid;
                    $newNick = $base . '#' . $uid;
                    if (Database::fetchColumn("SELECT id FROM users WHERE nickname = ? LIMIT 1", [$newNick])) {
                        $newNick = $base . '_' . substr(md5(uniqid('', true)), 0, 6);
                    }
                    Database::update('users', ['nickname' => $newNick], 'id = ?', [$uid]);
                    $fixed++;
                }
            }
            if ($fixed > 0) {
                error_log('[Hublog Migration V53] 已为 ' . $fixed . ' 个重复昵称追加唯一后缀');
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V53 重复昵称消歧] ' . $e->getMessage());
        }

        // 4) 建唯一索引（失败只记录，不阻断整站）
        try {
            $db->exec("ALTER TABLE `users` ADD UNIQUE KEY `uk_users_nickname` (`nickname`)");
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V53 users.uk_users_nickname] ' . $e->getMessage());
            if (APP_DEBUG) {
                throw $e;
            }
        }
    }


    /**
     * V54: 账号注销后「内容保留 + 身份匿名化」所需的字段
     *
     * 之前注销账号是物理 DELETE 掉 posts/comments，代价是：
     *   - 别人在该动态下的评论、收藏、圈子内容被连坐；
     *   - 评论串断了，对话上下文变成孤儿。
     * 改为保留内容、只做身份匿名化后，必须能区分两种 status=0 的账号：
     *   - 被管理员封禁（deleted_at IS NULL）：动态不对外展示，主页 404；
     *   - 用户主动注销（deleted_at IS NOT NULL）：动态继续展示，主页可访问但显示「已注销」。
     * 所以这里给 users 加 deleted_at 作为判据。
     */
    private static function migrate54(): void
    {
        $db = Database::getInstance();

        try {
            $exists = Database::fetchOne(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'deleted_at'"
            );
            if (!$exists) {
                $db->exec("ALTER TABLE `users` ADD COLUMN `deleted_at` DATETIME DEFAULT NULL COMMENT '账号注销时间（NULL=正常；非NULL=已注销，内容保留并匿名化）' AFTER `status`");
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V54 users.deleted_at] ' . $e->getMessage());
            if (APP_DEBUG) {
                throw $e;
            }
        }

        try {
            $db->exec("CREATE INDEX `idx_deleted_at` ON `users` (`deleted_at`)");
        } catch (\Throwable $e) {
            // 索引已存在时会报错，忽略
        }
    }


    /**
     * V55：音视频通话记录表（P1-5，腾讯云 TRTC 一对一语音/视频通话）
     *
     * 说明：本表既是通话记录，也兼作「呼叫状态机」——
     *   status=calling  呼叫中（被叫未响应）
     *   status=accepted 已接通（ended_at 为空表示通话进行中）
     *   status=rejected / missed / canceled /ended 均为终态
     * 房间号 room_id 由服务端生成，主叫与被叫用同一个房间号进房。
     */
    private static function migrate55(): void
    {
        $db = Database::getInstance();

        try {
            $db->exec("CREATE TABLE IF NOT EXISTS `call_records` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `room_id` varchar(64) NOT NULL COMMENT 'TRTC 房间号（服务端生成）',
                `caller_id` int unsigned NOT NULL COMMENT '主叫 user_id',
                `callee_id` int unsigned NOT NULL COMMENT '被叫 user_id',
                `type` varchar(10) NOT NULL DEFAULT 'audio' COMMENT 'audio=语音 / video=视频',
                `status` varchar(16) NOT NULL DEFAULT 'calling' COMMENT 'calling/accepted/rejected/missed/canceled/ended',
                `duration` int unsigned NOT NULL DEFAULT 0 COMMENT '通话时长（秒，接通后计算）',
                `started_at` datetime DEFAULT NULL COMMENT '接通时间',
                `ended_at` datetime DEFAULT NULL COMMENT '结束时间',
                `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_room_id` (`room_id`),
                KEY `idx_caller` (`caller_id`, `created_at`),
                KEY `idx_callee` (`callee_id`, `created_at`),
                KEY `idx_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='音视频通话记录'");
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V55 call_records] ' . $e->getMessage());
            if (APP_DEBUG) {
                throw $e;
            }
        }
    }


    /**
     * V56：内容可见范围 / 转发来源 / 精华权重 / 圈子公告标签 / 圈内禁言 / 发帖审核
     * 对应补齐清单 P0-1（可见范围）、P0-2（公告+标签）、P0-4（转发）、
     *        P0-6（圈主管理：禁言/精华/下沉/审核）、P1-2（精华与下沉）
     */
    private static function migrate56(): void
    {
        $db = Database::getInstance();

        // 1) posts 表
        $postColumns = [
            'visibility' => "ADD COLUMN `visibility` VARCHAR(16) NOT NULL DEFAULT 'public' COMMENT '可见范围：public/circle/friend/private' AFTER `status`",
            'origin_id' => "ADD COLUMN `origin_id` INT UNSIGNED DEFAULT NULL COMMENT '转发来源动态ID' AFTER `visibility`",
            'is_featured' => "ADD COLUMN `is_featured` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否精华' AFTER `origin_id`",
            'weight' => "ADD COLUMN `weight` INT NOT NULL DEFAULT 0 COMMENT '排序权重，负数下沉' AFTER `is_featured`",
        ];
        foreach ($postColumns as $col => $alterSql) {
            try {
                $exists = Database::fetchOne(
                    "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND COLUMN_NAME = ?",
                    [$col]
                );
                if (!$exists) {
                    $db->exec("ALTER TABLE `posts` {$alterSql}");
                }
            } catch (\Throwable $e) {
                error_log("[Hublog Migration V56 posts.{$col}] " . $e->getMessage());
                if (APP_DEBUG) {
                    throw $e;
                }
            }
        }

        // posts.visibility 索引（历史存量数据都是 public，不影响既有行为）
        try {
            $idx = Database::fetchOne(
                "SELECT INDEX_NAME FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND INDEX_NAME = 'idx_visibility' LIMIT 1"
            );
            if (!$idx) {
                $db->exec("CREATE INDEX `idx_visibility` ON `posts` (`visibility`)");
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V56 posts.idx_visibility] ' . $e->getMessage());
            if (APP_DEBUG) {
                throw $e;
            }
        }

        // 2) circles 表
        $circleColumns = [
            'announcement' => "ADD COLUMN `announcement` TEXT DEFAULT NULL COMMENT '圈子公告' AFTER `description`",
            'announcement_at' => "ADD COLUMN `announcement_at` DATETIME DEFAULT NULL COMMENT '公告更新时间' AFTER `announcement`",
            'tags' => "ADD COLUMN `tags` VARCHAR(255) DEFAULT NULL COMMENT '圈子标签，逗号分隔' AFTER `announcement_at`",
            'post_review' => "ADD COLUMN `post_review` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '发帖需圈主审核' AFTER `tags`",
        ];
        foreach ($circleColumns as $col => $alterSql) {
            try {
                $exists = Database::fetchOne(
                    "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'circles' AND COLUMN_NAME = ?",
                    [$col]
                );
                if (!$exists) {
                    $db->exec("ALTER TABLE `circles` {$alterSql}");
                }
            } catch (\Throwable $e) {
                error_log("[Hublog Migration V56 circles.{$col}] " . $e->getMessage());
                if (APP_DEBUG) {
                    throw $e;
                }
            }
        }

        // 3) circle_members 表：圈内禁言到期时间（role 字段已存在，不再新增）
        try {
            $exists = Database::fetchOne(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'circle_members' AND COLUMN_NAME = 'muted_until' LIMIT 1"
            );
            if (!$exists) {
                $db->exec("ALTER TABLE `circle_members` ADD COLUMN `muted_until` DATETIME DEFAULT NULL COMMENT '圈内禁言到期时间' AFTER `role`");
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V56 circle_members.muted_until] ' . $e->getMessage());
            if (APP_DEBUG) {
                throw $e;
            }
        }
    }


    /**
     * V57：图片评论（多图）+ 进阶帖型（投票 / 链接分享）
     *
     * 一次迁移把 P0-3 与 P1-1 的数据模型一起建好，避免反复上传 Migration.php：
     *  - comments.images  ：评论配图（JSON 数组，最多 9 张；老的 image 单字段保留兼容）
     *  - posts.link_*     ：链接分享卡片（url/title/desc/site）
     *  - polls / poll_options / poll_votes：投票帖
     */
    private static function migrate57(): void
    {
        $db = Database::getInstance();

        // 1) comments.images：评论配图（JSON 数组）
        try {
            $exists = Database::fetchOne(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'comments' AND COLUMN_NAME = 'images' LIMIT 1"
            );
            if (!$exists) {
                $db->exec("ALTER TABLE `comments` ADD COLUMN `images` VARCHAR(1024) DEFAULT NULL COMMENT '评论图片，JSON 数组，最多9张' AFTER `image`");
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V57 comments.images] ' . $e->getMessage());
            if (APP_DEBUG) {
                throw $e;
            }
        }

        // 2) posts.link_*：链接分享卡片
        $postLinkColumns = [
            'link_url'   => "ADD COLUMN `link_url` VARCHAR(2048) DEFAULT NULL COMMENT '分享链接地址' AFTER `origin_id`",
            'link_title' => "ADD COLUMN `link_title` VARCHAR(255) DEFAULT NULL COMMENT '链接卡片标题' AFTER `link_url`",
            'link_desc'  => "ADD COLUMN `link_desc` VARCHAR(500) DEFAULT NULL COMMENT '链接卡片摘要' AFTER `link_title`",
            'link_site'  => "ADD COLUMN `link_site` VARCHAR(128) DEFAULT NULL COMMENT '链接来源站点' AFTER `link_desc`",
        ];
        foreach ($postLinkColumns as $col => $alterSql) {
            try {
                $exists = Database::fetchOne(
                    "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND COLUMN_NAME = ? LIMIT 1",
                    [$col]
                );
                if (!$exists) {
                    $db->exec("ALTER TABLE `posts` {$alterSql}");
                }
            } catch (\Throwable $e) {
                error_log("[Hublog Migration V57 posts.{$col}] " . $e->getMessage());
                if (APP_DEBUG) {
                    throw $e;
                }
            }
        }

        // 3) 投票表
        $tables = [
            'polls' => "CREATE TABLE IF NOT EXISTS `polls` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `question` varchar(255) NOT NULL COMMENT '投票问题',
  `multi` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否多选：0单 1多',
  `max_choice` tinyint(1) NOT NULL DEFAULT 1 COMMENT '多选时最多可选几项',
  `expires_at` datetime DEFAULT NULL COMMENT '到期时间，NULL=永久',
  `is_anonymous` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否匿名投票：0否 1是',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_polls_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='投票表'",

            'poll_options' => "CREATE TABLE IF NOT EXISTS `poll_options` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `poll_id` int(11) unsigned NOT NULL,
  `content` varchar(255) NOT NULL COMMENT '选项内容',
  `sort_order` int(11) NOT NULL DEFAULT 0 COMMENT '排序',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_poll_options_poll` (`poll_id`),
  CONSTRAINT `fk_poll_options_poll_id` FOREIGN KEY (`poll_id`) REFERENCES `polls` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='投票选项表'",

            'poll_votes' => "CREATE TABLE IF NOT EXISTS `poll_votes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `poll_id` int(11) unsigned NOT NULL,
  `option_id` int(11) unsigned NOT NULL,
  `user_id` int(11) unsigned NOT NULL,
  `post_id` int(11) unsigned DEFAULT NULL COMMENT '关联动态ID',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_poll_votes_user_option` (`poll_id`, `user_id`, `option_id`),
  KEY `idx_poll_votes_user` (`poll_id`, `user_id`),
  KEY `idx_poll_votes_post` (`post_id`),
  CONSTRAINT `fk_poll_votes_poll_id` FOREIGN KEY (`poll_id`) REFERENCES `polls` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_poll_votes_option_id` FOREIGN KEY (`option_id`) REFERENCES `poll_options` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='投票记录表'",
        ];
        foreach ($tables as $table => $createSql) {
            try {
                $exists = Database::fetchOne(
                    "SELECT TABLE_NAME FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1",
                    [$table]
                );
                if (!$exists) {
                    $db->exec($createSql);
                }
            } catch (\Throwable $e) {
                error_log("[Hublog Migration V57 {$table}] " . $e->getMessage());
                if (APP_DEBUG) {
                    throw $e;
                }
            }
        }
    }


    /**
     * V58：P1-4 隐私权限全套 + P2-2/3/4/5/6/7 + P1-3 圈子群聊关联字段
     * 一次性建齐，避免后续反复上传 Migration 文件
     */
    private static function migrate58(): void
    {
        // P1-9：Database 只有 getInstance()/query/fetch* 等静态方法，没有 connection()。
        // 这里用 getInstance() 取 PDO，避免调用不存在的方法导致整个 V58 静默失效（曾线上踩过）。
        $db = Database::getInstance();

        // ====== 1. 已有表补列（幂等：逐个查 information_schema）======
        $columnAdds = [
            'users' => [
                'phone'              => "varchar(20) DEFAULT NULL COMMENT '手机号'",
                'phone_verified_at'  => "datetime DEFAULT NULL COMMENT '手机号验证通过时间'",
                'gender'             => "tinyint(1) NOT NULL DEFAULT 0 COMMENT '性别 0未知 1男 2女'",
                'birthday'           => "date DEFAULT NULL COMMENT '生日'",
                'region'             => "varchar(50) DEFAULT NULL COMMENT '地区'",
                'user_tags'          => "varchar(255) DEFAULT NULL COMMENT '个人标签（逗号分隔）'",
                'privacy_home'       => "varchar(16) NOT NULL DEFAULT 'public' COMMENT '主页可见范围 public/followers/private'",
                'allow_search'       => "tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否允许被搜索到'",
                'allow_mention'      => "tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否允许被@'",
                'allow_stranger_msg' => "tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否允许陌生人私信'",
                'hide_online'        => "tinyint(1) NOT NULL DEFAULT 0 COMMENT '隐藏在线状态'",
                'last_active_at'     => "datetime DEFAULT NULL COMMENT '最近活跃时间（在线状态）'",
                'font_scale'         => "varchar(8) NOT NULL DEFAULT 'normal' COMMENT '字体大小 small/normal/large/xlarge'",
                'msg_sound'          => "tinyint(1) NOT NULL DEFAULT 1 COMMENT '消息音效'",
            ],
            'conversations' => [
                'circle_id'       => "int(11) unsigned DEFAULT NULL COMMENT '关联圈子ID（圈子群）'",
                'member_count'    => "int(11) NOT NULL DEFAULT 0 COMMENT '群成员数'",
                'announcement'    => "varchar(1000) DEFAULT NULL COMMENT '群公告'",
                'announcement_at' => "datetime DEFAULT NULL COMMENT '群公告更新时间'",
            ],
            'conversation_members' => [
                'muted_until' => "datetime DEFAULT NULL COMMENT '禁言截止时间（NULL=正常）'",
            ],
        ];

        foreach ($columnAdds as $table => $columns) {
            foreach ($columns as $column => $definition) {
                try {
                    $exists = Database::fetchOne(
                        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1",
                        [$table, $column]
                    );
                    if (!$exists) {
                        $db->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
                    }
                } catch (\Throwable $e) {
                    error_log("[Hublog Migration V58 {$table}.{$column}] " . $e->getMessage());
                    if (APP_DEBUG) {
                        throw $e;
                    }
                }
            }
        }

        // ====== 2. 新表 ======
        $tables = [
            // P2-1 手机号验证码
            'phone_codes' => "CREATE TABLE IF NOT EXISTS `phone_codes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `phone` varchar(20) NOT NULL COMMENT '手机号',
  `code` varchar(10) NOT NULL COMMENT '验证码',
  `scene` varchar(16) NOT NULL DEFAULT 'login' COMMENT '场景 login=登录 bind=绑定 reset=重置',
  `expires_at` datetime NOT NULL COMMENT '过期时间',
  `used_at` datetime DEFAULT NULL,
  `sent_ip` varchar(45) DEFAULT NULL,
  `attempts` smallint(6) NOT NULL DEFAULT 0 COMMENT '校验失败次数',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_phone_scene` (`phone`, `scene`, `created_at`),
  KEY `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='手机号验证码表'",

            // P2-3 云端草稿箱
            'drafts' => "CREATE TABLE IF NOT EXISTS `drafts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) unsigned NOT NULL,
  `type` varchar(16) NOT NULL DEFAULT 'post' COMMENT '类型 post=动态 comment=评论',
  `circle_id` int(11) unsigned DEFAULT NULL,
  `post_id` bigint(20) unsigned DEFAULT NULL COMMENT '关联动态ID（评论草稿）',
  `title` varchar(200) DEFAULT NULL,
  `content` text,
  `images` varchar(2000) DEFAULT NULL COMMENT '图片URL列表（逗号分隔）',
  `visibility` varchar(16) NOT NULL DEFAULT 'public' COMMENT '可见范围',
  `link_url` varchar(1000) DEFAULT NULL,
  `link_title` varchar(255) DEFAULT NULL,
  `link_desc` varchar(500) DEFAULT NULL,
  `link_site` varchar(255) DEFAULT NULL,
  `poll_question` varchar(255) DEFAULT NULL,
  `poll_options` varchar(500) DEFAULT NULL COMMENT '投票选项（逗号分隔）',
  `poll_duration` int(11) NOT NULL DEFAULT 0 COMMENT '投票天数 0=不过期',
  `scheduled_at` datetime DEFAULT NULL COMMENT '定时发布',
  `extra` JSON DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_drafts_user` (`user_id`, `updated_at`),
  KEY `idx_drafts_type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='云端草稿箱'",

            // P2-4 服务端通知偏好（服务端推送前查这张表决定是否下发）
            'notification_prefs' => "CREATE TABLE IF NOT EXISTS `notification_prefs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) unsigned NOT NULL,
  `all_enabled` tinyint(1) NOT NULL DEFAULT 1 COMMENT '总开关',
  `post_like` tinyint(1) NOT NULL DEFAULT 1 COMMENT '点赞',
  `post_comment` tinyint(1) NOT NULL DEFAULT 1 COMMENT '评论',
  `follow` tinyint(1) NOT NULL DEFAULT 1 COMMENT '关注',
  `message` tinyint(1) NOT NULL DEFAULT 1 COMMENT '私信/群消息',
  `circle_post` tinyint(1) NOT NULL DEFAULT 1 COMMENT '圈子动态',
  `system` tinyint(1) NOT NULL DEFAULT 1 COMMENT '系统通知',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_notif_prefs_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='服务端通知偏好表'",

            // P2-5 用户申诉
            'appeals' => "CREATE TABLE IF NOT EXISTS `appeals` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) unsigned NOT NULL,
  `target_type` varchar(16) DEFAULT NULL COMMENT '关联对象 post/comment/user/circle/account/NULL',
  `target_id` int(11) unsigned DEFAULT NULL,
  `category` varchar(32) NOT NULL COMMENT '申诉类型 falseblock=误封 forbidden=内容误下架 misreview=审核有误 other=其他',
  `content` text NOT NULL COMMENT '申诉说明',
  `images` varchar(2000) DEFAULT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'pending' COMMENT 'pending/reviewing/resolved/rejected',
  `admin_id` int(11) unsigned DEFAULT NULL,
  `admin_note` text COMMENT '处理备注',
  `handled_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_appeals_user` (`user_id`, `created_at`),
  KEY `idx_appeals_status` (`status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户申诉表'",

            // P2-6 活动（报名 / 打卡）
            'activities' => "CREATE TABLE IF NOT EXISTS `activities` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `circle_id` int(11) unsigned DEFAULT NULL COMMENT '关联圈子（可为空=全站活动）',
  `creator_id` int(11) unsigned NOT NULL,
  `title` varchar(100) NOT NULL COMMENT '活动标题',
  `description` text COMMENT '活动说明',
  `cover` varchar(500) DEFAULT NULL,
  `type` varchar(16) NOT NULL DEFAULT 'signup' COMMENT 'signup=报名 checkin=打卡',
  `mode` varchar(16) NOT NULL DEFAULT 'free' COMMENT 'free=不限人数 count=限人数',
  `capacity` int(11) NOT NULL DEFAULT 0 COMMENT '人数上限（0=不限）',
  `checkin_goal` int(11) NOT NULL DEFAULT 0 COMMENT '打卡目标天数（0=不考核）',
  `start_at` datetime DEFAULT NULL,
  `end_at` datetime DEFAULT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'open' COMMENT 'open=报名中 closed=已截止 ended=已结束',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_activities_circle` (`circle_id`, `status`),
  KEY `idx_activities_status` (`status`, `end_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='活动表'",

            'activity_registrations' => "CREATE TABLE IF NOT EXISTS `activity_registrations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `activity_id` bigint(20) unsigned NOT NULL,
  `user_id` int(11) unsigned NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'joined' COMMENT 'joined=已报名 canceled=已取消',
  `note` varchar(255) DEFAULT NULL COMMENT '报名备注',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_activity_reg_user` (`activity_id`, `user_id`),
  KEY `idx_activity_reg_user` (`user_id`),
  CONSTRAINT `fk_activity_reg_activity` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='活动报名表'",

        ];

        foreach ($tables as $table => $createSql) {
            try {
                $exists = Database::fetchOne(
                    "SELECT TABLE_NAME FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1",
                    [$table]
                );
                if (!$exists) {
                    $db->exec($createSql);
                }
            } catch (\Throwable $e) {
                error_log("[Hublog Migration V58 {$table}] " . $e->getMessage());
                if (APP_DEBUG) {
                    throw $e;
                }
            }
        }

        // ====== 3. 补齐老用户的通知偏好行 ======
        try {
            $db->exec(
                "INSERT IGNORE INTO notification_prefs (user_id) SELECT id FROM users"
            );
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V58 notification_prefs seed] ' . $e->getMessage());
            if (APP_DEBUG) {
                throw $e;
            }
        }

        // ====== 4. 收尾自检：任何一张 V58 表没建出来都要留下日志，杜绝"静默失效" ======
        foreach (['phone_codes', 'drafts', 'notification_prefs', 'appeals', 'activities', 'activity_registrations'] as $table) {
            try {
                $exists = Database::fetchOne(
                    "SELECT TABLE_NAME FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1",
                    [$table]
                );
                if (!$exists) {
                    error_log("[Hublog Migration V58 verify] 缺少表 {$table}，请检查上方 V58 日志");
                }
            } catch (\Throwable $e) {
                error_log("[Hublog Migration V58 verify {$table}] " . $e->getMessage());
            }
        }
    }


    /**
     * V59：P1-1 剩余三种进阶帖型 —— 问答 / 组队 / 打卡
     * 设计：posts.post_type 标记帖型（normal/poll/question/team/checkin），
     * 各帖型的附加数据放独立表，老客户端读不到 post_type 也只当普通帖，向后兼容。
     */
    private static function migrate59(): void
    {
        $db = Database::getInstance();

        // ====== 1. posts 补列（帖型标记）======
        $columnAdds = [
            'posts' => [
                'post_type' => "varchar(16) NOT NULL DEFAULT 'normal' COMMENT '帖型 normal/poll/question/team/checkin'",
            ],
        ];
        foreach ($columnAdds as $table => $columns) {
            foreach ($columns as $column => $definition) {
                try {
                    $exists = Database::fetchOne(
                        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1",
                        [$table, $column]
                    );
                    if (!$exists) {
                        $db->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
                    }
                } catch (\Throwable $e) {
                    error_log("[Hublog Migration V59 {$table}.{$column}] " . $e->getMessage());
                    if (APP_DEBUG) {
                        throw $e;
                    }
                }
            }
        }

        // ====== 2. 新表 ======
        $tables = [
            'questions' => "CREATE TABLE IF NOT EXISTS `questions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `post_id` int(11) unsigned NOT NULL,
  `question` varchar(255) NOT NULL COMMENT '问题描述',
  `status` tinyint(1) NOT NULL DEFAULT 0 COMMENT '0=待解决 1=已解决',
  `answer` text,
  `answered_by` int(11) unsigned DEFAULT NULL,
  `answered_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_questions_post` (`post_id`),
  KEY `idx_questions_post` (`post_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='问答帖'",

            'teams' => "CREATE TABLE IF NOT EXISTS `teams` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `post_id` int(11) unsigned NOT NULL,
  `headline` varchar(255) DEFAULT NULL COMMENT '招募口号',
  `need_count` int(11) NOT NULL DEFAULT 0 COMMENT '需要人数 0=不限',
  `joined_count` int(11) NOT NULL DEFAULT 0 COMMENT '已报名人数',
  `deadline` date DEFAULT NULL COMMENT '报名截止日',
  `city` varchar(50) DEFAULT NULL COMMENT '城市',
  `contact` varchar(100) DEFAULT NULL COMMENT '联系方式',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_teams_post` (`post_id`),
  KEY `idx_teams_post` (`post_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='组队帖'",

            'team_members' => "CREATE TABLE IF NOT EXISTS `team_members` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `team_id` bigint(20) unsigned NOT NULL,
  `post_id` int(11) unsigned NOT NULL,
  `user_id` int(11) unsigned NOT NULL,
  `joined_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_team_members_user` (`team_id`, `user_id`),
  KEY `idx_team_members_post` (`post_id`),
  KEY `idx_team_members_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='组队报名成员'",

            'checkin_posts' => "CREATE TABLE IF NOT EXISTS `checkin_posts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `post_id` int(11) unsigned NOT NULL,
  `goal` varchar(255) DEFAULT NULL COMMENT '打卡目标',
  `target_days` int(11) NOT NULL DEFAULT 0 COMMENT '目标天数 0=不限',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_checkin_posts_post` (`post_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='打卡帖'",

            'checkin_logs' => "CREATE TABLE IF NOT EXISTS `checkin_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `post_id` int(11) unsigned NOT NULL,
  `user_id` int(11) unsigned NOT NULL,
  `content` varchar(255) DEFAULT NULL COMMENT '打卡备注',
  `checkin_date` date NOT NULL COMMENT '打卡日期',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_checkin_logs_day` (`post_id`, `user_id`, `checkin_date`),
  KEY `idx_checkin_logs_user` (`post_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='打卡记录'",
        ];
        foreach ($tables as $table => $createSql) {
            try {
                $exists = Database::fetchOne(
                    "SELECT TABLE_NAME FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1",
                    [$table]
                );
                if (!$exists) {
                    $db->exec($createSql);
                }
            } catch (\Throwable $e) {
                error_log("[Hublog Migration V59 {$table}] " . $e->getMessage());
                if (APP_DEBUG) {
                    throw $e;
                }
            }
        }

        // ====== 3. 收尾自检：五张表任何一张没建出来都要留日志，杜绝静默失效 ======
        foreach (['questions', 'teams', 'team_members', 'checkin_posts', 'checkin_logs'] as $table) {
            try {
                $exists = Database::fetchOne(
                    "SELECT TABLE_NAME FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1",
                    [$table]
                );
                if (!$exists) {
                    error_log("[Hublog Migration V59 verify] 缺少表 {$table}，请检查上方 V59 日志");
                }
            } catch (\Throwable $e) {
                error_log("[Hublog Migration V59 verify {$table}] " . $e->getMessage());
            }
        }

        // ====== 4. 历史数据回填：把已存在的投票帖标记为 poll，便于列表/详情正确渲染 ======
        self::markExistingPollPosts(59);
    }


    /**
     * V61：回填存量动态里的 #话题#
     * 单独一个版本号：回填是纯数据操作（非 DDL），和建表迁移分开，跑坏了重跑即可（幂等）。
     * （V60 已在线上记录为已执行，实际逻辑放在 61。）
     */
    private static function migrate61(): void
    {
        self::backfillPostTagsFromContent();
    }


    /**
     * V62：补跑「历史投票帖标记」（V59 遗留的数据修补，纯数据操作）
     *
     * 为什么要再跑一次：V59 当初写成 `SELECT post_id FROM polls`，而 polls 表本身没有 post_id 列
     * （post 与 poll 的关联在 poll_votes.post_id 上）→ Unknown column 'post_id'。
     * 线上 APP_DEBUG=false，异常被 catch 后只写 error_log，静默失败：
     * 那批在 V59 之前发的投票帖 post_type 一直是 'normal'，列表/详情当普通帖渲染。
     *
     * 老站点已经跑过 59 了，修好 V59 的代码对它无效（不会重跑），所以补一个版本号专门修复一次。
     * 全新安装路径 59 已经修好，这里再跑一遍是 no-op（幂等，只匹配 post_type='normal' 的）。
     */
    private static function migrate62(): void
    {
        self::markExistingPollPosts(62);
    }


    /**
     * V63：给 polls 补上 post_id（投票帖 ↔ 投票主体的直接关联）
     *
     * 背景：polls 表当初只有 id/question/multi…，没有指向 posts 的字段，
     * post 与 poll 的关联只能靠 poll_votes.post_id 反查，而且旧代码是
     * `SELECT poll_id FROM poll_votes WHERE post_id = ? AND user_id = ?` —— 按「当前用户」查。
     * 后果有两个：
     *   ① 谁没投过票，谁就看不到这条动态的投票内容（只有发布者自己看得见）；
     *   ② 多选投票发布时不写首票 → 表里一行 poll_votes 都没有 → 所有人都投不了票。
     *
     * 这里补上 polls.post_id 后，PostController 改为直接 `WHERE post_id = ?` 查投票，
     * 不再依赖「我投过票」这个前提。
     *
     * 幂等：列/索引先判存在；回填只填 post_id IS NULL 的行。
     */
    private static function migrate63(): void
    {
        $db = Database::getInstance();

        // 1) polls.post_id 列
        try {
            $exists = Database::fetchOne(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'polls' AND COLUMN_NAME = 'post_id' LIMIT 1"
            );
            if (!$exists) {
                $db->exec("ALTER TABLE `polls` ADD COLUMN `post_id` int(11) unsigned DEFAULT NULL COMMENT '所属动态ID' AFTER `id`");
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V63 polls.post_id] ' . $e->getMessage());
            if (APP_DEBUG) {
                throw $e;
            }
        }

        // 2) polls.post_id 索引
        try {
            $exists = Database::fetchOne(
                "SELECT INDEX_NAME FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'polls' AND INDEX_NAME = 'idx_polls_post' LIMIT 1"
            );
            if (!$exists) {
                $db->exec("ALTER TABLE `polls` ADD KEY `idx_polls_post` (`post_id`)");
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V63 polls.idx_polls_post] ' . $e->getMessage());
            if (APP_DEBUG) {
                throw $e;
            }
        }

        // 3) 回填：用已有的投票记录把 polls.post_id 补上（一条投票可能有多票，取最小的 post_id）
        try {
            $db->exec(
                "UPDATE polls p
                 INNER JOIN (SELECT poll_id, MIN(post_id) AS pid FROM poll_votes
                             WHERE post_id IS NOT NULL AND post_id > 0 GROUP BY poll_id) v
                         ON v.poll_id = p.id
                 SET p.post_id = v.pid
                 WHERE p.post_id IS NULL"
            );
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V63 backfill polls.post_id] ' . $e->getMessage());
            if (APP_DEBUG) {
                throw $e;
            }
        }

        // 4) 顺带把能关联上的投票帖标记成 post_type='poll'（比 V62 那版更彻底：
        //    多选投票在没人投票前 poll_votes 里没记录，只能靠这里）
        try {
            $db->exec(
                "UPDATE posts SET post_type = 'poll' WHERE post_type = 'normal'
                 AND id IN (SELECT post_id FROM polls WHERE post_id IS NOT NULL AND post_id > 0)"
            );
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V63 seed-poll] ' . $e->getMessage());
            if (APP_DEBUG) {
                throw $e;
            }
        }

        // 5) 收尾自检：列没建出来就留日志，杜绝静默失效
        try {
            $exists = Database::fetchOne(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'polls' AND COLUMN_NAME = 'post_id' LIMIT 1"
            );
            if (!$exists) {
                error_log('[Hublog Migration V63 verify] polls.post_id 未建出，请检查上方 V63 日志');
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V63 verify] ' . $e->getMessage());
        }
    }


    /**
     * V65：功能缺口盘点（docs/Hublog_功能缺口盘点.md）的三块结构性改动
     *
     * ① circle_members.status —— 圈内永久黑名单（原来只有 muted_until 禁言，圈主无永久手段）
     * ② featured_posts        —— 全站精华 / 运营推荐位（原来只有圈子内精华，站点级没有出口）
     * ③ topics.slug 归一      —— 话题大小写拆散热度（#Android开发 vs #android开发）
     *
     * 幂等：全部先判存在再建；③ 的合并只动纯 ASCII 的大小写变体，不会动中文话题。
     */
    private static function migrate65(): void
    {
        $db = Database::getInstance();

        // ---------- ① circle_members.status ----------
        try {
            $exists = Database::fetchOne(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'circle_members' AND COLUMN_NAME = 'status' LIMIT 1"
            );
            if (!$exists) {
                $db->exec("ALTER TABLE `circle_members` ADD COLUMN `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1=正常 2=圈内黑名单' AFTER `role`");
            }
            $db->exec("UPDATE circle_members SET status = 1 WHERE status IS NULL OR status = 0");
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V65 circle_members.status] ' . $e->getMessage());
            if (APP_DEBUG) {
                throw $e;
            }
        }

        // ---------- ② featured_posts（全站精华 / 运营位） ----------
        try {
            $exists = Database::fetchOne(
                "SELECT TABLE_NAME FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'featured_posts' LIMIT 1"
            );
            if (!$exists) {
                $db->exec("CREATE TABLE IF NOT EXISTS `featured_posts` (
                    `id` int unsigned NOT NULL AUTO_INCREMENT,
                    `post_id` int unsigned NOT NULL COMMENT '被推荐的动态ID',
                    `user_id` int unsigned NOT NULL DEFAULT 0 COMMENT '推荐人（运营账号）',
                    `slot` varchar(32) NOT NULL DEFAULT 'home' COMMENT '投放位：home=首页精选  circle=圈子内',
                    `sort_order` int NOT NULL DEFAULT 0 COMMENT '越小越靠前',
                    `remark` varchar(255) DEFAULT NULL COMMENT '推荐理由',
                    `start_at` datetime DEFAULT NULL COMMENT '生效时间（空=立即）',
                    `end_at` datetime DEFAULT NULL COMMENT '失效时间（空=长期）',
                    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uk_post_slot` (`post_id`, `slot`),
                    KEY `idx_slot_sort` (`slot`, `sort_order`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='全站精华 / 运营推荐位'");
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V65 featured_posts] ' . $e->getMessage());
            if (APP_DEBUG) {
                throw $e;
            }
        }

        // ---------- ③ topics 大小写归一 ----------
        // 只合并「纯 ASCII 且去掉大小写后相同」的话题，中文话题一个字节都不动。
        try {
            $rows = Database::fetchAll(
                "SELECT id, name, slug FROM topics
                 WHERE name REGEXP '^[A-Za-z0-9_ -]+$'
                 ORDER BY post_count DESC, id ASC"
            );
            if ($rows) {
                Database::beginTransaction();
                $moved = 0;
                $merged = 0;
                foreach ($rows as $row) {
                    $key = strtolower(trim($row['name']));
                    if ($key === '') {
                        continue;
                    }
                    // 找一个语义上同名的老话题（id 更小优先）
                    $target = Database::fetchOne(
                        "SELECT id, name FROM topics
                         WHERE id <> ? AND LOWER(TRIM(name)) = ?
                         ORDER BY id ASC LIMIT 1",
                        [$row['id'], $key]
                    );
                    if (!$target) {
                        // 没同名兄弟 → 只把自己规范化成小写，避免下次再被合并
                        $newSlug = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', $row['name']));
                        if ($newSlug !== '' && $newSlug !== $row['slug']) {
                            Database::query(
                                "UPDATE topics SET slug = ? WHERE id = ? LIMIT 1",
                                [$newSlug, $row['id']]
                            );
                        }
                        continue;
                    }
                    // 有兄弟 → 把它的动态归属并到老话题名下，再删掉自己的索引行
                    Database::query(
                        "UPDATE post_tags SET tag = ? WHERE tag = ?",
                        [$target['name'], $row['name']]
                    );
                    Database::query("DELETE FROM topics WHERE id = ? LIMIT 1", [$row['id']]);
                    $moved++;
                    $merged++;
                }
                Database::commit();
                error_log("[Hublog Migration V65 topics-normalize] rows={$merged} merged={$moved}");
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V65 topics-normalize] ' . $e->getMessage());
            if (APP_DEBUG) {
                throw $e;
            }
        }

        // ---------- 收尾自检：三处必须都在 ----------
        try {
            $checks = [
                'circle_members.status' => "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'circle_members' AND COLUMN_NAME = 'status' LIMIT 1",
                'featured_posts' => "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'featured_posts' LIMIT 1",
            ];
            foreach ($checks as $label => $sql) {
                if (!Database::fetchOne($sql)) {
                    error_log("[Hublog Migration V65 verify] {$label} 未建出，请检查上方 V65 日志");
                }
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V65 verify] ' . $e->getMessage());
        }
    }


    /**
     * V60：把存量动态正文/标题里的 #话题# 回填到 post_tags，并登记进 topics 表。
     *
     * 背景：发布页 V59 加了「话题」按钮，候选话题取自 topics 表（后台维护）与 post_tags 聚合。
     * 但存量动态都是 P2-5「自动抽取 hashtag」上线之前发的，虽然正文写着 #打工人# 这类话题，
     * post_tags 却一直是空的 → 话题详情页全空、发布页的候选列表也全空。这里一次性补齐。
     *
     * 幂等：post_tags 有 uk_post_tag(post_id, tag)、topics 有 uk_slug(slug)，
     * 两处都用 INSERT IGNORE，重复执行不会产生脏数据。
     */
    private static function backfillPostTagsFromContent(): void
    {
        try {
            $lastId = 0;
            $scanned = 0;
            $tagStmt = Database::getInstance()->prepare(
                "INSERT IGNORE INTO post_tags (post_id, tag, created_at) VALUES (?, ?, NOW())"
            );
            $topicStmt = Database::getInstance()->prepare(
                "INSERT IGNORE INTO topics (name, slug, status, created_at) VALUES (?, ?, 1, NOW())"
            );

            // 按主键游标分批扫，避免一次性把全表读进内存
            while (true) {
                $rows = Database::fetchAll(
                    "SELECT id, content, title FROM posts
                     WHERE id > ? AND (content LIKE '%#%' OR title LIKE '%#%')
                     ORDER BY id ASC LIMIT 200",
                    [$lastId]
                );
                if (empty($rows)) {
                    break;
                }
                foreach ($rows as $row) {
                    $lastId = (int)$row['id'];
                    $scanned++;
                    $text = ($row['content'] ?? '') . ' ' . ($row['title'] ?? '');
                    if (!preg_match_all('/#([A-Za-z0-9_\x{4e00}-\x{9fa5}]{1,29})#?/u', $text, $m)) {
                        continue;
                    }
                    $tags = array_values(array_unique($m[1]));
                    $tags = array_slice(array_values(array_filter(
                        $tags,
                        fn($t) => $t !== '' && mb_strlen($t) <= 30
                    )), 0, 5);
                    foreach ($tags as $tag) {
                        $tagStmt->execute([$lastId, $tag]);
                        $topicStmt->execute([$tag, $tag]);
                    }
                }
            }

            // 统一重算一次计数（只算 status=1 的动态），避免后续自增导致虚高
            Database::query(
                "UPDATE topics t
                 SET t.post_count = (
                     SELECT COUNT(*) FROM post_tags pt
                     INNER JOIN posts p ON p.id = pt.post_id AND p.status = 1
                     WHERE pt.tag = t.name
                 )"
            );

            error_log("[Hublog Migration V61 backfill] scanned={$scanned} posts with hashtag");
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V61 backfill] ' . $e->getMessage());
            if (APP_DEBUG) {
                throw $e;
            }
        }
    }


    /**
     * 把已存在的投票帖标记为 post_type='poll'（幂等，V59 / V62 共用）
     *
     * 关联关系在 poll_votes.post_id 上 —— polls 是独立的投票主体表，本身没有 post_id 列。
     * 旧代码写成 `SELECT post_id FROM polls`，字段不存在 → Unknown column 'post_id'。
     *
     * @param int $version 调用方的迁移版本号，仅用于日志定位
     */
    private static function markExistingPollPosts(int $version): void
    {
        try {
            Database::getInstance()->exec(
                "UPDATE posts SET post_type = 'poll' WHERE post_type = 'normal'
                 AND id IN (SELECT DISTINCT post_id FROM poll_votes WHERE post_id IS NOT NULL AND post_id > 0)"
            );
        } catch (\Throwable $e) {
            error_log("[Hublog Migration V{$version} seed-poll] " . $e->getMessage());
            if (APP_DEBUG) {
                throw $e;
            }
        }
    }
}
