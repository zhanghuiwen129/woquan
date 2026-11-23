<?php
/**
 * Hublog 增量数据库迁移方法集合 (V26 - V45)
 *
 * 由 api/core/Migration.php 拆分而来，主迁移类 Hublog\Core\Migration
 * 通过 `use MigrationBatchA` 引入这些方法，旧版本升级时仍按版本号顺序执行；
 * 全新安装走 buildFullSchema() 全量建表后循环补跑增量迁移。
 *
 * 拆分零行为差异：trait 方法在编译期展平进主类，run() 的动态分发
 * self::{"migrate{$v}"}() 与新装补跑循环均正常命中，无需改动调用方。
 */

declare(strict_types=1);

namespace Hublog\Core;

trait MigrationBatchA
{

    // ==================== 增量迁移（老库升级，按版本号顺序执行） ====================
    // V26 起每个版本一个方法；V1~V25 在 MigrationHistory.php。
    // 新增迁移：复制任一 migrateNN() 的写法（information_schema 判存在 + 单句 try/catch），
    //         改完记得 $currentVersion +1，并按文件头的「三件事」 checklist 自查。

    /**
     * V26：新增 @提及、定位、匿名发布功能
     * - posts 表增加 location、location_lat、location_lng、is_anonymous 字段
     * - 新增 mentions 表存储 @提及关系
     * - 新增相关系统设置
     */
    private static function migrate26(): void
    {
        try {
            $hasColumn = Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'location'"
            );
            if (!$hasColumn) {
                Database::getInstance()->exec("ALTER TABLE `posts` ADD COLUMN `location` varchar(255) DEFAULT NULL COMMENT '定位地点名称' AFTER `images`");
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V26 posts location] ' . $e->getMessage());
        }

        try {
            $hasColumn = Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'location_lat'"
            );
            if (!$hasColumn) {
                Database::getInstance()->exec("ALTER TABLE `posts` ADD COLUMN `location_lat` decimal(10,7) DEFAULT NULL COMMENT '定位纬度' AFTER `location`");
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V26 posts location_lat] ' . $e->getMessage());
        }

        try {
            $hasColumn = Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'location_lng'"
            );
            if (!$hasColumn) {
                Database::getInstance()->exec("ALTER TABLE `posts` ADD COLUMN `location_lng` decimal(10,7) DEFAULT NULL COMMENT '定位经度' AFTER `location_lat`");
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V26 posts location_lng] ' . $e->getMessage());
        }

        try {
            $hasColumn = Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'is_anonymous'"
            );
            if (!$hasColumn) {
                Database::getInstance()->exec("ALTER TABLE `posts` ADD COLUMN `is_anonymous` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否匿名发布：0否 1是' AFTER `location_lng`");
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V26 posts is_anonymous] ' . $e->getMessage());
        }

        try {
            $hasIndex = Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND INDEX_NAME = 'idx_location'"
            );
            if (!$hasIndex) {
                Database::getInstance()->exec("ALTER TABLE `posts` ADD INDEX `idx_location` (`location`(191))");
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V26 posts idx_location] ' . $e->getMessage());
        }

        try {
            $hasColumn = Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'mentions'"
            );
            if (!$hasColumn) {
                Database::getInstance()->exec("ALTER TABLE `posts` ADD COLUMN `mentions` text DEFAULT NULL COMMENT '@提及用户列表（JSON）' AFTER `is_anonymous`");
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V26 posts mentions] ' . $e->getMessage());
        }

        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS `mentions` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `post_id` int(11) unsigned NOT NULL COMMENT '动态ID',
  `comment_id` int(11) unsigned DEFAULT NULL COMMENT '评论ID（评论中@时）',
  `user_id` int(11) unsigned NOT NULL COMMENT '被@的用户ID',
  `mentioned_by` int(11) unsigned NOT NULL COMMENT '发起@的用户ID',
  `type` enum('post','comment') NOT NULL DEFAULT 'post' COMMENT '来源类型',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_post_id` (`post_id`),
  KEY `idx_comment_id` (`comment_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_user_created` (`user_id`, `created_at`),
  CONSTRAINT `fk_mentions_post_id` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_mentions_comment_id` FOREIGN KEY (`comment_id`) REFERENCES `comments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_mentions_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_mentions_mentioned_by` FOREIGN KEY (`mentioned_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='@提及记录表';
SQL;
        try {
            Database::getInstance()->exec($sql);
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V26 mentions table] ' . $e->getMessage());
        }

        $settings = [
            'allow_mention' => '1',
            'allow_location' => '1',
            'allow_anonymous_post' => '1',
            'mention_max_count' => '10',
        ];
        foreach ($settings as $key => $value) {
            try {
                $exists = Database::fetchColumn(
                    "SELECT COUNT(*) FROM `settings` WHERE `key` = ?",
                    [$key]
                );
                if (!$exists) {
                    Database::insert('settings', [
                        'key' => $key,
                        'value' => $value,
                        'created_at' => self::now(),
                        'updated_at' => self::now()
                    ]);
                }
            } catch (\Throwable $e) {
                error_log('[Hublog Migration V26 settings ' . $key . '] ' . $e->getMessage());
            }
        }
    }


    /**
     * V27：更新 AI 用户昵称为「AI评论家」，更新默认 AI 配置为本地 Ollama
     */
    private static function migrate27(): void
    {
        try {
            Database::update('users', [
                'nickname' => 'AI评论家',
                'bio' => '我是 Hublog 内置的 AI 评论家，会自动对动态发表评论～',
                'updated_at' => self::now()
            ], "username = 'ai_assistant'");
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V27 ai_assistant nickname] ' . $e->getMessage());
        }

        // 更新已存在的 AI 配置为本地 Ollama 默认值（仅当值为旧默认时更新）
        $updates = [
            'ai_comment_provider' => ['old' => 'deepseek', 'new' => 'ollama'],
            'ai_comment_api_url' => ['old' => 'https://api.deepseek.com/v1/chat/completions', 'new' => 'http://localhost:11434/v1/chat/completions'],
            'ai_comment_model' => ['old' => 'deepseek-chat', 'new' => 'llama3.2'],
            'ai_comment_timeout' => ['old' => '15', 'new' => '30'],
        ];
        foreach ($updates as $key => $info) {
            try {
                $current = Database::fetchColumn("SELECT `value` FROM `settings` WHERE `key` = ? LIMIT 1", [$key]);
                if ($current === $info['old']) {
                    Database::update('settings', ['value' => $info['new'], 'updated_at' => self::now()], '`key` = ?', [$key]);
                }
            } catch (\Throwable $e) {
                error_log('[Hublog Migration V27 setting ' . $key . '] ' . $e->getMessage());
            }
        }

        // 清除缓存
        try {
            Cache::delete('site_settings');
            Cache::delete('ai_comment_settings');
        } catch (\Throwable $e) {
            // 忽略
        }
    }


    /**
     * V28：新增用户封面字段（支持图片/视频封面）
     */
    private static function migrate28(): void
    {
        try {
            $exists = Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'cover'"
            );
            if (!$exists) {
                Database::getInstance()->exec(
                    "ALTER TABLE `users` ADD COLUMN `cover` varchar(500) NOT NULL DEFAULT '' COMMENT '用户封面URL（图片或视频）' AFTER `avatar`"
                );
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V28 user cover] ' . $e->getMessage());
        }
    }


    /**
     * V29：即时通讯（IM）系统
     * - 新增 conversations 会话表
     * - 新增 conversation_members 成员表（含已读位置）
     * - 新增 messages 消息表
     */
    private static function migrate29(): void
    {
        // 会话表
        try {
            Database::getInstance()->exec(
                "CREATE TABLE IF NOT EXISTS `conversations` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `type` ENUM('private','group') NOT NULL DEFAULT 'private' COMMENT '会话类型',
                    `title` VARCHAR(100) NOT NULL DEFAULT '' COMMENT '群聊标题（私聊为空）',
                    `avatar` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '群聊头像',
                    `creator_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建者用户ID',
                    `last_message_id` BIGINT UNSIGNED DEFAULT NULL COMMENT '最后一条消息ID',
                    `last_message_at` DATETIME DEFAULT NULL COMMENT '最后消息时间',
                    `created_at` DATETIME NOT NULL,
                    `updated_at` DATETIME NOT NULL,
                    PRIMARY KEY (`id`),
                    KEY `idx_type` (`type`),
                    KEY `idx_last_message_at` (`last_message_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='IM会话表'"
            );
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V29 conversations] ' . $e->getMessage());
        }

        // 会话成员表
        try {
            Database::getInstance()->exec(
                "CREATE TABLE IF NOT EXISTS `conversation_members` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `conversation_id` BIGINT UNSIGNED NOT NULL,
                    `user_id` INT UNSIGNED NOT NULL,
                    `role` ENUM('member','admin','owner') NOT NULL DEFAULT 'member' COMMENT '成员角色',
                    `last_read_message_id` BIGINT UNSIGNED DEFAULT 0 COMMENT '已读位置（该ID及之前的消息已读）',
                    `muted` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否免打扰',
                    `joined_at` DATETIME NOT NULL,
                    `created_at` DATETIME NOT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uk_conv_user` (`conversation_id`, `user_id`),
                    KEY `idx_user_id` (`user_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='IM会话成员表'"
            );
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V29 conversation_members] ' . $e->getMessage());
        }

        // 消息表
        try {
            Database::getInstance()->exec(
                "CREATE TABLE IF NOT EXISTS `messages` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `conversation_id` BIGINT UNSIGNED NOT NULL,
                    `sender_id` INT UNSIGNED NOT NULL,
                    `content` MEDIUMTEXT NOT NULL COMMENT '消息内容',
                    `type` ENUM('text','image','video','file','system') NOT NULL DEFAULT 'text' COMMENT '消息类型',
                    `extra` JSON DEFAULT NULL COMMENT '附加数据（图片URL、文件信息等）',
                    `created_at` DATETIME NOT NULL,
                    PRIMARY KEY (`id`),
                    KEY `idx_conv_id_created` (`conversation_id`, `created_at`),
                    KEY `idx_sender_id` (`sender_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='IM消息表'"
            );
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V29 messages] ' . $e->getMessage());
        }
    }


    /**
     * V30：关闭默认伪静态开关
     *
     * 伪静态是否启用取决于服务器环境（Apache mod_rewrite / Nginx rewrite），
     * 不应由系统默认开启。此处将已有安装中 url_rewrite = '1' 的记录重置为 '0'，
     * 由用户在后台根据自身服务器环境自行决定是否开启。
     */
    private static function migrate30(): void
    {
        try {
            Database::getInstance()->exec(
                "UPDATE `settings` SET `value` = '0', `updated_at` = '" . self::now() . "' WHERE `key` = 'url_rewrite' AND `value` = '1'"
            );
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V30 url_rewrite] ' . $e->getMessage());
        }
    }


    /**
     * V31：放宽 API 限流默认阈值
     *
     * 原有 rate_limit_requests=300 在多标签页 + 轮询场景下容易触发 429，
     * 影响正常使用体验。此处将默认值提高，并对已有安装进行迁移。
     */
    private static function migrate31(): void
    {
        $now = self::now();
        $pdo = Database::getInstance();

        try {
            $pdo->exec(
                "INSERT INTO `settings` (`key`, `value`, `created_at`, `updated_at`) VALUES
                    ('rate_limit_requests', '1500', '$now', '$now'),
                    ('rate_limit_window', '60', '$now', '$now')
                 ON DUPLICATE KEY UPDATE
                    `value` = CASE `key`
                        WHEN 'rate_limit_requests' THEN '1500'
                        WHEN 'rate_limit_window' THEN '60'
                        ELSE `value`
                    END,
                    `updated_at` = '$now'"
            );
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V31 rate_limit] ' . $e->getMessage());
        }
    }


    /**
     * V32：补齐评论点赞表、用户拉黑表、会话置顶字段
     */
    private static function migrate32(): void
    {
        $pdo = Database::getInstance();
        $now = self::now();

        // 1. conversation_members 补 pinned 字段
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM `conversation_members` LIKE 'pinned'")->fetchAll();
            if (!$cols) {
                $pdo->exec(
                    "ALTER TABLE `conversation_members` ADD COLUMN `pinned` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否置顶' AFTER `muted`"
                );
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V32 pinned] ' . $e->getMessage());
        }

        // 2. 建 comment_likes 表
        try {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS `comment_likes` (
                  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                  `comment_id` INT UNSIGNED NOT NULL,
                  `user_id` INT UNSIGNED NOT NULL,
                  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `uk_comment_user` (`comment_id`, `user_id`),
                  KEY `idx_comment_id` (`comment_id`),
                  KEY `idx_user_id` (`user_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='评论点赞表'"
            );
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V32 comment_likes] ' . $e->getMessage());
        }

        // 3. 建 blocked_users 表
        try {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS `blocked_users` (
                  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                  `user_id` INT UNSIGNED NOT NULL COMMENT '发起拉黑的用户ID',
                  `blocked_user_id` INT UNSIGNED NOT NULL COMMENT '被拉黑的用户ID',
                  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `uk_user_blocked` (`user_id`, `blocked_user_id`),
                  KEY `idx_user_id` (`user_id`),
                  KEY `idx_blocked_user_id` (`blocked_user_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户拉黑屏蔽表'"
            );
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V32 blocked_users] ' . $e->getMessage());
        }
    }


    /**
     * V33: uploads 表 type 字段添加 video 枚举值
     * 修复视频上传时因 type 字段不包含 'video' 导致的服务器内部错误
     */
    private static function migrate33(): void
    {
        $pdo = Database::getInstance();
        try {
            // 修改 type 枚举，新增 'video'
            $pdo->exec(
                "ALTER TABLE `uploads` MODIFY COLUMN `type` ENUM('image','attachment','video') NOT NULL DEFAULT 'image' COMMENT '类型'"
            );
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V33 uploads.type] ' . $e->getMessage());
        }
    }


    /**
     * V34: posts 表添加 videos 字段
     * 用于存储视频数组（含 url, cover, duration），修复视频动态不显示的问题
     */
    private static function migrate34(): void
    {
        $pdo = Database::getInstance();
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM `posts` LIKE 'videos'")->fetchAll();
            if (empty($cols)) {
                $pdo->exec(
                    "ALTER TABLE `posts` ADD COLUMN `videos` json DEFAULT NULL COMMENT '视频数组JSON（含url,cover,duration）' AFTER `images`"
                );
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V34 posts.videos] ' . $e->getMessage());
        }
    }


    private static function migrate35(): void
    {
        $pdo = Database::getInstance();
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM `notifications` LIKE 'conversation_id'")->fetchAll();
            if (empty($cols)) {
                $pdo->exec(
                    "ALTER TABLE `notifications` ADD COLUMN `conversation_id` bigint unsigned DEFAULT NULL COMMENT '关联会话ID（私信通知）' AFTER `comment_id`"
                );
                $pdo->exec(
                    "ALTER TABLE `notifications` ADD INDEX `idx_user_type_read` (`user_id`, `type`, `is_read`, `created_at`)"
                );
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V35 notifications.conversation_id] ' . $e->getMessage());
        }
    }


    /**
     * V36：功能全面增强迁移
     * - posts 表: 新增 scheduled_at (定时发布)、view_count (浏览统计)
     * - messages 表: 新增 read_at (已读回执)
     * - users 表: 新增 email_verified、oauth_provider、oauth_id (邮箱验证+第三方登录)
     * - 新建 email_verifications 表 (邮箱验证码)
     * - 新建 topics 表 (话题管理)
     * - 新建 oauth_accounts 表 (第三方登录关联)
     * - 新增大量系统设置项
     */
    private static function migrate36(): void
    {
        $pdo = Database::getInstance();
        $now = self::now();

        // 1. posts 表新增 scheduled_at 字段（定时发布）
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM `posts` LIKE 'scheduled_at'")->fetchAll();
            if (empty($cols)) {
                $pdo->exec("ALTER TABLE `posts` ADD COLUMN `scheduled_at` datetime DEFAULT NULL COMMENT '定时发布时间（NULL=立即发布）' AFTER `is_anonymous`");
                $pdo->exec("ALTER TABLE `posts` ADD INDEX `idx_scheduled` (`scheduled_at`, `status`)");
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V36 posts.scheduled_at] ' . $e->getMessage());
        }

        // 2. posts 表新增 view_count 字段（浏览统计）
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM `posts` LIKE 'view_count'")->fetchAll();
            if (empty($cols)) {
                $pdo->exec("ALTER TABLE `posts` ADD COLUMN `view_count` int unsigned NOT NULL DEFAULT 0 COMMENT '浏览次数' AFTER `scheduled_at`");
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V36 posts.view_count] ' . $e->getMessage());
        }

        // 3. messages 表新增 read_at 字段（已读回执）
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM `messages` LIKE 'read_at'")->fetchAll();
            if (empty($cols)) {
                $pdo->exec("ALTER TABLE `messages` ADD COLUMN `read_at` datetime DEFAULT NULL COMMENT '已读时间' AFTER `created_at`");
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V36 messages.read_at] ' . $e->getMessage());
        }

        // 4. users 表新增 email_verified 字段
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'email_verified'")->fetchAll();
            if (empty($cols)) {
                $pdo->exec("ALTER TABLE `users` ADD COLUMN `email_verified` tinyint(1) NOT NULL DEFAULT 0 COMMENT '邮箱是否已验证' AFTER `email`");
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V36 users.email_verified] ' . $e->getMessage());
        }

        // 5. users 表新增 oauth_provider、oauth_id 字段
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'oauth_provider'")->fetchAll();
            if (empty($cols)) {
                $pdo->exec("ALTER TABLE `users` ADD COLUMN `oauth_provider` varchar(20) DEFAULT NULL COMMENT '第三方登录提供商' AFTER `email_verified`");
                $pdo->exec("ALTER TABLE `users` ADD COLUMN `oauth_id` varchar(100) DEFAULT NULL COMMENT '第三方登录ID' AFTER `oauth_provider`");
                $pdo->exec("ALTER TABLE `users` ADD INDEX `idx_oauth` (`oauth_provider`, `oauth_id`)");
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V36 users.oauth] ' . $e->getMessage());
        }

        // 6. 新建 email_verifications 表
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `email_verifications` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `user_id` int unsigned NOT NULL COMMENT '用户ID',
                `email` varchar(255) NOT NULL COMMENT '邮箱地址',
                `code` varchar(64) NOT NULL COMMENT '验证码或Token',
                `type` enum('register','reset','change') NOT NULL DEFAULT 'register' COMMENT '验证类型',
                `expires_at` datetime NOT NULL COMMENT '过期时间',
                `used` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否已使用',
                `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_user_id` (`user_id`),
                KEY `idx_code` (`code`),
                KEY `idx_type_used` (`type`, `used`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='邮箱验证记录表'");
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V36 email_verifications] ' . $e->getMessage());
        }

        // 7. 新建 topics 表（话题管理）
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `topics` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `name` varchar(100) NOT NULL COMMENT '话题名称',
                `slug` varchar(100) NOT NULL COMMENT '话题URL标识',
                `description` text DEFAULT NULL COMMENT '话题描述',
                `post_count` int unsigned NOT NULL DEFAULT 0 COMMENT '关联动态数（缓存）',
                `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1=正常 0=隐藏',
                `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_slug` (`slug`),
                KEY `idx_status` (`status`),
                KEY `idx_post_count` (`post_count`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='话题管理表'");
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V36 topics] ' . $e->getMessage());
        }

        // 8. 新建 oauth_accounts 表（第三方登录关联）
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `oauth_accounts` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `user_id` int unsigned NOT NULL COMMENT '关联的用户ID',
                `provider` varchar(20) NOT NULL COMMENT '提供商(github/google/wechat)',
                `oauth_id` varchar(100) NOT NULL COMMENT '第三方用户ID',
                `access_token` text DEFAULT NULL COMMENT '访问令牌(加密)',
                `refresh_token` text DEFAULT NULL COMMENT '刷新令牌(加密)',
                `raw_data` json DEFAULT NULL COMMENT '原始用户信息',
                `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_provider_oauth` (`provider`, `oauth_id`),
                KEY `idx_user_id` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='第三方登录关联表'");
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V36 oauth_accounts] ' . $e->getMessage());
        }

        // 9. 新建 post_view_logs 表（浏览统计 - 按日聚合）
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `post_view_logs` (
                `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                `post_id` int unsigned NOT NULL COMMENT '动态ID',
                `viewer_id` int unsigned DEFAULT NULL COMMENT '浏览者ID(游客为NULL)',
                `viewer_ip` varchar(45) DEFAULT NULL COMMENT '浏览者IP',
                `view_date` date NOT NULL COMMENT '浏览日期',
                `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_post_date` (`post_id`, `view_date`),
                KEY `idx_viewer_id` (`viewer_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='动态浏览日志表'");
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V36 post_view_logs] ' . $e->getMessage());
        }

        // 10. 新增系统设置项
        $newSettings = [
            // 密码策略
            'password_min_length' => '8',
            'password_require_upper' => '1',
            'password_require_lower' => '1',
            'password_require_digit' => '1',
            'password_require_special' => '0',
            // 邮件系统
            'mail_enabled' => '0',
            'mail_host' => '',
            'mail_port' => '587',
            'mail_username' => '',
            'mail_password' => '',
            'mail_from_name' => '',
            'mail_from_email' => '',
            'mail_encryption' => 'tls',
            'email_verification_required' => '0',
            // 定时发布
            'allow_scheduled_post' => '1',
            // 自定义主题色
            'custom_theme_color' => '',
            // 国际化
            'site_language' => 'zh-CN',
            'allow_language_switch' => '1',
            // 备份
            'backup_auto_enabled' => '0',
            'backup_auto_frequency' => 'daily',
            'backup_auto_keep' => '7',
            // 举报自动处理
            'report_auto_hide_threshold' => '5',
            'report_auto_notify_admin' => '1',
            // SEO
            'seo_sitemap_enabled' => '1',
            'seo_robots_txt' => "User-agent: *\nAllow: /",
            // 批量操作
            'admin_batch_limit' => '100',
            // 数据导出
            'export_format' => 'json',
            // 插件管理
            'plugin_management_enabled' => '1',
        ];

        foreach ($newSettings as $key => $value) {
            try {
                $exists = Database::fetchColumn(
                    "SELECT COUNT(*) FROM `settings` WHERE `key` = ?",
                    [$key]
                );
                if (!$exists) {
                    Database::insert('settings', [
                        'key' => $key,
                        'value' => $value,
                        'created_at' => $now,
                        'updated_at' => $now
                    ]);
                }
            } catch (\Throwable $e) {
                error_log('[Hublog Migration V36 setting ' . $key . '] ' . $e->getMessage());
            }
        }

        // 清除缓存
        try {
            Cache::delete('site_settings');
        } catch (\Throwable $e) {
            // 忽略
        }
    }


    /**
     * V37：授权系统 & 防盗机制
     * - 新建 licenses 表（授权码管理，后台使用）
     * - 新增大量授权相关系统设置项
     * - 新增 file_hashes 表（核心文件完整性校验 - 防盗用）
     */
    private static function migrate37(): void
    {
        $pdo = Database::getInstance();
        $now = self::now();

        // 1. 新建 licenses 表（后台保存的授权记录）
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `licenses` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `license_key` varchar(255) NOT NULL COMMENT '授权码',
                `license_type` enum('trial','personal','business','enterprise') NOT NULL DEFAULT 'trial' COMMENT '授权类型',
                `domain` varchar(255) DEFAULT NULL COMMENT '绑定域名（NULL=不限）',
                `expires_at` datetime DEFAULT NULL COMMENT '过期时间（NULL=永久）',
                `max_users` int unsigned DEFAULT NULL COMMENT '最大用户数（NULL=不限）',
                `max_posts` int unsigned DEFAULT NULL COMMENT '最大动态数（NULL=不限）',
                `features` json DEFAULT NULL COMMENT '启用的功能列表JSON',
                `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1=有效 0=无效',
                `customer_name` varchar(255) DEFAULT NULL COMMENT '客户名称',
                `customer_email` varchar(255) DEFAULT NULL COMMENT '客户邮箱',
                `note` text DEFAULT NULL COMMENT '备注',
                `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_license_key` (`license_key`),
                KEY `idx_domain` (`domain`),
                KEY `idx_status_expire` (`status`, `expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='授权码记录表'");
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V37 licenses] ' . $e->getMessage());
        }

        // 2. 新建 file_hashes 表（核心文件完整性校验）
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `file_hashes` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `file_path` varchar(500) NOT NULL COMMENT '相对文件路径',
                `file_hash` varchar(64) NOT NULL COMMENT '文件SHA256哈希',
                `version` varchar(20) NOT NULL COMMENT '对应程序版本',
                `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_file_path` (`file_path`(191))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='核心文件哈希表（防盗用）'");
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V37 file_hashes] ' . $e->getMessage());
        }

        // 3. 新增授权系统配置项
        $newSettings = [
            // 授权系统主开关
            'license_enabled' => '1',
            // 当前激活的授权码
            'license_key' => '',
            // 授权验证结果缓存（JSON格式，包含type/expires/domain/features等）
            'license_data' => '',
            // 上次验证时间戳
            'license_last_check' => '0',
            // 验证模式：local=本地离线验证, remote=远程服务器验证
            'license_verify_mode' => 'local',
            // 远程授权验证服务器URL（remote模式）
            'license_remote_url' => '',
            // 远程验证公钥（remote模式，留空使用内置公钥）
            'license_remote_pubkey' => '',
            // 防盗：绑定域名（留空=允许任意域名）
            'license_bind_domain' => '',
            // 防盗：核心文件篡改检测开关
            'license_integrity_check' => '1',
            // 防盗：文件篡改检测宽容模式（仅警告不拦截）
            'license_integrity_lenient' => '0',
            // 未授权：允许最大用户数（0=不允许注册）
            'license_unauth_max_users' => '3',
            // 未授权：允许最大动态数
            'license_unauth_max_posts' => '10',
            // 未授权：允许使用AI功能
            'license_unauth_allow_ai' => '0',
            // 未授权：允许使用插件系统
            'license_unauth_allow_plugins' => '1',
            // 未授权：允许使用即时消息
            'license_unauth_allow_chat' => '0',
            // 未授权：允许使用后台运营统计
            'license_unauth_allow_stats' => '0',
            // 未授权：允许数据导出
            'license_unauth_allow_export' => '0',
            // 未授权：允许自定义主题色
            'license_unauth_allow_theme' => '0',
            // 未授权：允许使用定时发布
            'license_unauth_allow_scheduled' => '0',
            // 未授权：前端显示未授权提示横幅
            'license_unauth_show_banner' => '1',
            // 授权系统内置公钥（用于本地验证授权码签名）
            'license_public_key' => "-----BEGIN PUBLIC KEY-----\nMIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA0o0/Hw9lhGPcLx7gM8tP\nF4iQqPjX8R5d0JnMm8gBk7HqLd+QmJl9U5Lq5JmYjVJ3k5sYzQv3k7C6s1Wq0XtM\nuJwRfYjLqJ8V1sY0uE6Xk0n5ZbFk6sXb0hYnWq1TlHqB0l0mG7uK1Y5D8s9Vp6bR\nYl1c1sGmY2Zk3J5fH4w3d2xG6l7D0Qa4jM3s8o9F3r5V2l7j0n3Q6zC1b9k8L5e7\niQ4vN3c1u0j9lHk7G6T0z9o0V5n2J4tK9vN3sM4w8lU3oP5XqL1bD6N0jR8t2v9M\nK3vQ7G5n9YpT0Z7XsL3a4Qe7T8v9D1cX5u0jM3sY6fQ2u9Tz8n0L5r1K6fN7w3e9\nmQIDAQAB\n-----END PUBLIC KEY-----",
        ];

        foreach ($newSettings as $key => $value) {
            try {
                $exists = Database::fetchColumn(
                    "SELECT COUNT(*) FROM `settings` WHERE `key` = ?",
                    [$key]
                );
                if (!$exists) {
                    Database::insert('settings', [
                        'key' => $key,
                        'value' => $value,
                        'created_at' => $now,
                        'updated_at' => $now
                    ]);
                }
            } catch (\Throwable $e) {
                error_log('[Hublog Migration V37 setting ' . $key . '] ' . $e->getMessage());
            }
        }

        // 4. 初始化核心文件哈希记录（首次安装）
        try {
            $coreFiles = [
                'api/index.php',
                'api/core/Auth.php',
                'api/core/Router.php',
                'api/core/Database.php',
                'config.php',
                'index.php',
            ];
            $version = defined('APP_VERSION') ? APP_VERSION : 'v2.0';
            foreach ($coreFiles as $file) {
                $absPath = __DIR__ . '/../../' . $file;
                if (file_exists($absPath)) {
                    $hash = hash_file('sha256', $absPath);
                    $exists = Database::fetchColumn(
                        "SELECT COUNT(*) FROM `file_hashes` WHERE `file_path` = ?",
                        [$file]
                    );
                    if (!$exists) {
                        Database::insert('file_hashes', [
                            'file_path' => $file,
                            'file_hash' => $hash,
                            'version' => $version,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V37 file_hashes init] ' . $e->getMessage());
        }

        // 清除缓存
        try {
            Cache::delete('site_settings');
            Cache::deleteByPrefix('license_');
        } catch (\Throwable $e) {
            // 忽略
        }
    }


    /**
     * V38: 补全 IM 表外键约束
     *
     * 之前 conversations / conversation_members / messages 三张表仅靠应用层维护引用完整性，
     * 删除会话/用户时可能留下孤儿记录。本迁移补齐外键，并加上 ON DELETE CASCADE
     * 让数据完整性由数据库保证。
     *
     * 兼容策略：
     * - 先用 information_schema 检查外键是否已存在，避免重复创建报错
     * - 清理已存在的孤儿记录后再添加外键，避免 ALTER 失败
     * - 单条外键失败不影响其他外键
     */
    private static function migrate38(): void
    {
        $pdo = Database::getInstance();

        // 需要补的外键定义：[名字, 表, 列, 引用表, 引用列]
        $foreignKeys = [
            ['fk_cm_conversation', 'conversation_members', 'conversation_id', 'conversations', 'id'],
            ['fk_cm_user',         'conversation_members', 'user_id',         'users',           'id'],
            ['fk_msg_conversation','messages',             'conversation_id', 'conversations',   'id'],
            ['fk_msg_sender',      'messages',             'sender_id',       'users',           'id'],
        ];

        foreach ($foreignKeys as [$name, $table, $col, $refTable, $refCol]) {
            try {
                // 1) 检查外键是否已存在
                $exists = Database::fetchColumn(
                    "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
                    [$table, $name]
                );
                if ($exists) {
                    continue;
                }

                // 2) 清理孤儿记录：把引用了不存在父记录的行删掉，避免 ADD CONSTRAINT 失败
                $pdo->exec("DELETE `{$table}` FROM `{$table}`
                    LEFT JOIN `{$refTable}` ON `{$table}`.`{$col}` = `{$refTable}`.`{$refCol}`
                    WHERE `{$refTable}`.`{$refCol}` IS NULL");

                // 3) 添加外键约束
                $pdo->exec("ALTER TABLE `{$table}`
                    ADD CONSTRAINT `{$name}`
                    FOREIGN KEY (`{$col}`) REFERENCES `{$refTable}` (`{$refCol}`)
                    ON DELETE CASCADE");
            } catch (\Throwable $e) {
                error_log("[Hublog Migration V38 {$name}] " . $e->getMessage());
            }
        }
    }


    /**
     * P2-7 配套：post_view_logs 增加 (post_id, viewer_id, view_date) 联合唯一索引
     * - 登录用户（viewer_id 非 NULL）：唯一索引直接生效，配合 INSERT IGNORE 按日去重
     * - 游客（viewer_id 为 NULL）：MySQL 唯一索引对 NULL 不做等值冲突，需配合应用层 SELECT-before-INSERT
     *   （PostController::detail 里已处理）
     * 老库可能已有按 (user, date, post) 重复的历史残留，加索引前先去重。
     */
    private static function migrate39(): void
    {
        $pdo = Database::getInstance();

        try {
            $tblExists = Database::fetchColumn(
                "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'post_view_logs' LIMIT 1"
            );
            if (!$tblExists) {
                return;
            }
            $idxExists = Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'post_view_logs' AND INDEX_NAME = 'uk_pv_post_user_date'"
            );
            if ($idxExists) {
                return;
            }

            // 对 viewer_id 非 NULL 的行按 (post_id, viewer_id, view_date) 去重，保留最小 id
            $pdo->exec(
                "DELETE a FROM post_view_logs a
                 INNER JOIN post_view_logs b
                   ON a.post_id = b.post_id
                  AND a.viewer_id <=> b.viewer_id
                  AND a.view_date = b.view_date
                  AND a.id > b.id
                 WHERE a.viewer_id IS NOT NULL"
            );

            $pdo->exec(
                "ALTER TABLE `post_view_logs`
                 ADD UNIQUE KEY `uk_pv_post_user_date` (`post_id`, `viewer_id`, `view_date`)"
            );
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V39 post_view_logs UK] ' . $e->getMessage());
            if (APP_DEBUG) {
                throw $e;
            }
        }
    }


    /**
     * P2-1 / P2-6：posts 表冗余 like_count / comment_count，时间线直接读列不再 COUNT likes/comments。
     * 写入路径（点赞 toggle / 评论增删 / 删除评论后代数）会同步维护两列。
     */
    private static function migrate40(): void
    {
        $pdo = Database::getInstance();

        try {
            $tblExists = Database::fetchColumn(
                "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' LIMIT 1"
            );
            if (!$tblExists) {
                return;
            }

            // 1) 加 like_count 列
            $cols = $pdo->query("SHOW COLUMNS FROM `posts` LIKE 'like_count'")->fetchAll();
            if (empty($cols)) {
                $pdo->exec(
                    "ALTER TABLE `posts`
                     ADD COLUMN `like_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '冗余：点赞总数' AFTER `view_count`"
                );
            }
            // 2) 加 comment_count 列
            $cols = $pdo->query("SHOW COLUMNS FROM `posts` LIKE 'comment_count'")->fetchAll();
            if (empty($cols)) {
                $pdo->exec(
                    "ALTER TABLE `posts`
                     ADD COLUMN `comment_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '冗余：评论总数(含子评论)' AFTER `like_count`"
                );
            }

            // 3) 初始化历史数据：用 UPDATE JOIN 一次性回填，避免每行子查询 N 次扫描
            //    like_count
            $pdo->exec(
                "UPDATE `posts` p
                 LEFT JOIN (SELECT post_id, COUNT(*) AS c FROM `likes` GROUP BY post_id) l ON l.post_id = p.id
                 SET p.`like_count` = IFNULL(l.c, 0)"
            );
            //    comment_count（含子评论：parent_id 为子评论也按 post_id 聚合）
            $pdo->exec(
                "UPDATE `posts` p
                 LEFT JOIN (SELECT post_id, COUNT(*) AS c FROM `comments` GROUP BY post_id) c ON c.post_id = p.id
                 SET p.`comment_count` = IFNULL(c.c, 0)"
            );
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V40 posts.like_count/comment_count] ' . $e->getMessage());
            if (APP_DEBUG) {
                throw $e;
            }
        }
    }


    /**
     * P2-5 / P2-4：post_tags 索引升级 + 话题提取写库前置条件
     *   1) UNIQUE uk_post_tag(post_id, tag)：同一条动态不会对同话题存多行；INSERT IGNORE 抽取话题时幂等；
     *   2) KEY idx_tag_post(tag, post_id)：话题页按 tag 过滤时可直接走复合索引返回按 post_id DESC 排序的 ID，
     *      无需回表、无需 filesort。话题页改为 ORDER BY p.id DESC（单调递增）等效 created_at 排序。
     */
    private static function migrate41(): void
    {
        $pdo = Database::getInstance();

        try {
            $tblExists = Database::fetchColumn(
                "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'post_tags' LIMIT 1"
            );
            if (!$tblExists) {
                return;
            }

            // 1) UNIQUE uk_post_tag(post_id, tag)：先对 (post_id,tag) 去重（保最小 id）再加唯一索引
            $ukExists = Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'post_tags' AND INDEX_NAME = 'uk_post_tag'"
            );
            if (!$ukExists) {
                $pdo->exec(
                    "DELETE a FROM post_tags a
                     INNER JOIN post_tags b ON a.post_id = b.post_id AND a.tag = b.tag AND a.id > b.id"
                );
                $pdo->exec(
                    "ALTER TABLE `post_tags` ADD UNIQUE KEY `uk_post_tag` (`post_id`, `tag`)"
                );
            }

            // 2) 复合索引 idx_tag_post(tag, post_id)：代替单独 idx_tag(tag) 单列
            $cmpExists = Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'post_tags' AND INDEX_NAME = 'idx_tag_post'"
            );
            if (!$cmpExists) {
                $pdo->exec(
                    "ALTER TABLE `post_tags` ADD KEY `idx_tag_post` (`tag`, `post_id`)"
                );
            }

            // 3) 清理旧的单列 idx_tag(tag)（复合索引前缀 tag 列已经覆盖单列查询，单列索引不再需要）
            $oldTagIdx = Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'post_tags' AND INDEX_NAME = 'idx_tag'"
            );
            if ($oldTagIdx) {
                $pdo->exec("ALTER TABLE `post_tags` DROP INDEX `idx_tag`");
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V41 post_tags indexes] ' . $e->getMessage());
            if (APP_DEBUG) {
                throw $e;
            }
        }
    }


    /**
     * V42：uploads 表加 expires_at —— 僵尸文件自动清理
     * 上传未发布的文件 24h 后自动清理（磁盘 + DB）
     */
    private static function migrate42(): void
    {
        $pdo = Database::getInstance();
        try {
            $colExists = Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'uploads' AND COLUMN_NAME = 'expires_at'"
            );
            if (!$colExists) {
                $pdo->exec("ALTER TABLE `uploads` ADD COLUMN `expires_at` datetime DEFAULT NULL COMMENT '过期时间' AFTER `status`");
            }
            $idxExists = Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'uploads' AND INDEX_NAME = 'idx_expires_at'"
            );
            if (!$idxExists) {
                $pdo->exec("ALTER TABLE `uploads` ADD KEY `idx_expires_at` (`expires_at`)");
            }
            $pdo->exec("UPDATE `uploads` SET `expires_at` = NOW() + INTERVAL 24 HOUR WHERE `post_id` IS NULL AND `expires_at` IS NULL");
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V42 uploads.expires_at] ' . $e->getMessage());
            if (APP_DEBUG) {
                throw $e;
            }
        }
    }


    /**
     * V43：图片水印功能
     *
     * 纯配置迁移（settings 表 KV），无需改表结构。
     * 幂等：只插入缺失的 key，已存在的保持管理员设定值不变。
     */
    private static function migrate43(): void
    {
        try {
            foreach (Watermark::defaults() as $key => $value) {
                $exists = Database::fetchColumn("SELECT id FROM settings WHERE `key` = ? LIMIT 1", [$key]);
                if (!$exists) {
                    Database::insert('settings', [
                        'key'        => $key,
                        'value'      => $value,
                        'created_at' => self::now(),
                        'updated_at' => self::now()
                    ]);
                }
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V43 watermark settings] ' . $e->getMessage());
            if (APP_DEBUG) {
                throw $e;
            }
        }
    }


    /**
     * V44：新增 refresh_tokens 表（App/多端长效刷新令牌）
     *
     * 解决的问题：原 /api/auth/refresh 必须依赖未过期的 access_token，
     * 一旦 JWT_EXPIRE（默认 7 天）到期用户就被强制登出，移动端体验不可接受。
     */
    private static function migrate44(): void
    {
        try {
            Database::getInstance()->exec(
                "CREATE TABLE IF NOT EXISTS `refresh_tokens` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) unsigned NOT NULL,
  `token_hash` char(64) NOT NULL COMMENT 'sha256(明文token)，服务端不存明文',
  `device_id` varchar(64) NOT NULL DEFAULT '' COMMENT '客户端设备唯一串',
  `user_agent` varchar(255) DEFAULT NULL COMMENT '设备信息',
  `ip` varchar(45) NOT NULL DEFAULT '',
  `tv` int(11) unsigned NOT NULL DEFAULT '1' COMMENT '签发时的 token_version 快照',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` timestamp NOT NULL COMMENT '过期时间',
  `last_used_at` timestamp NULL DEFAULT NULL,
  `revoked` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1=已撤销',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_token_hash` (`token_hash`),
  KEY `idx_user` (`user_id`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='长效刷新令牌表'"
            );
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V44 refresh_tokens] ' . $e->getMessage());
            if (APP_DEBUG) {
                throw $e;
            }
        }
    }


    /**
     * V45：新增 app_versions 表（App 版本检查 / 强制更新）
     */
    private static function migrate45(): void
    {
        try {
            Database::getInstance()->exec(
                "CREATE TABLE IF NOT EXISTS `app_versions` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `platform` varchar(16) NOT NULL DEFAULT 'android' COMMENT 'android/ios/h5',
  `version_code` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '整数版本号，用于大小比较',
  `version_name` varchar(32) NOT NULL DEFAULT '' COMMENT '展示用版本号',
  `update_type` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0=建议更新 1=强制更新',
  `changelog` text COMMENT '更新说明',
  `download_url` varchar(512) NOT NULL DEFAULT '' COMMENT 'APK直链或AppStore地址',
  `status` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_platform_status` (`platform`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='App版本管理表'"
            );
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V45 app_versions] ' . $e->getMessage());
            if (APP_DEBUG) {
                throw $e;
            }
        }
    }
}
