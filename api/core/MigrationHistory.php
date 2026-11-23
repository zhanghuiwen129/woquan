<?php
/**
 * Hublog 历史数据库迁移方法集合 (V1 - V25)
 *
 * 将迭代过程中的历史迁移方法抽离到 trait，减轻主迁移文件体积。
 * 主迁移类 Hublog\Core\Migration 通过 `use MigrationHistory` 引入这些方法，
 * 旧版本升级时仍按 V1 -> V25 顺序执行；新安装走 buildFullSchema() 全量建表。
 */

declare(strict_types=1);

namespace Hublog\Core;

trait MigrationHistory
{
    /**
     * V1：初始化全部数据表
     */
    private static function migrate1(): void
    {
        $pdo = Database::getInstance();
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

        $statements = [
            "CREATE TABLE IF NOT EXISTS `schema_versions` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `version` int(11) unsigned NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_version` (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='迁移版本记录'",

            "CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(20) NOT NULL COMMENT '用户名',
  `password` varchar(255) NOT NULL COMMENT '密码哈希',
  `email` varchar(100) NOT NULL COMMENT '邮箱',
  `nickname` varchar(50) NOT NULL DEFAULT '' COMMENT '昵称',
  `avatar` varchar(255) NOT NULL DEFAULT 'assets/images/default-avatar.svg' COMMENT '头像URL',
  `bio` text COMMENT '个人简介',
  `role` enum('user','admin') NOT NULL DEFAULT 'user' COMMENT '角色',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态',
  `token_version` int(11) unsigned NOT NULL DEFAULT '1' COMMENT 'Token版本号，用于强制失效',
  `last_login` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`),
  UNIQUE KEY `uk_email` (`email`),
  KEY `idx_status` (`status`),
  KEY `idx_role` (`role`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户表'",

            "CREATE TABLE IF NOT EXISTS `categories` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `parent_id` int(11) unsigned DEFAULT NULL COMMENT '父分类ID，NULL为一级分类',
  `name` varchar(50) NOT NULL COMMENT '分类名称',
  `sort_order` int(11) NOT NULL DEFAULT '0' COMMENT '排序值，越小越靠前',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态：0禁用 1启用',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_parent_id` (`parent_id`),
  KEY `idx_status_sort` (`status`, `sort_order`),
  CONSTRAINT `fk_category_parent_id` FOREIGN KEY (`parent_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='分类表'",

            "CREATE TABLE IF NOT EXISTS `posts` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) unsigned NOT NULL COMMENT '作者ID',
  `category_id` int(11) unsigned DEFAULT NULL COMMENT '分类ID',
  `title` varchar(255) DEFAULT NULL COMMENT '动态标题（可选）',
  `content` text NOT NULL COMMENT '内容',
  `images` json DEFAULT NULL COMMENT '图片数组JSON',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_category_id` (`category_id`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `fk_posts_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_posts_category_id` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='动态表'",

            "CREATE TABLE IF NOT EXISTS `likes` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `post_id` int(11) unsigned NOT NULL,
  `user_id` int(11) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_post_user` (`post_id`, `user_id`),
  KEY `idx_post_id` (`post_id`),
  KEY `idx_user_id` (`user_id`),
  CONSTRAINT `fk_likes_post_id` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_likes_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='点赞表'",

            "CREATE TABLE IF NOT EXISTS `comments` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `post_id` int(11) unsigned NOT NULL,
  `user_id` int(11) unsigned NOT NULL,
  `parent_id` int(11) unsigned DEFAULT NULL,
  `content` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_post_id` (`post_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_parent_id` (`parent_id`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `fk_comments_post_id` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_comments_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_comments_parent_id` FOREIGN KEY (`parent_id`) REFERENCES `comments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='评论表'",

            "CREATE TABLE IF NOT EXISTS `settings` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(50) NOT NULL COMMENT '配置键',
  `value` text COMMENT '配置值',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_key` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统设置表'",
        ];

        foreach ($statements as $sql) {
            try {
                $pdo->exec($sql);
            } catch (\Throwable $e) {
                error_log('[Hublog Migration V1] SQL 执行失败: ' . $e->getMessage() . ' | SQL: ' . substr($sql, 0, 200));
            }
        }

        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

        // 插入默认配置（幂等）
        $defaults = [
            'site_name' => '我圈',
            'site_desc' => '轻量级多用户社区博客系统',
            'site_icp' => '',
            'site_keywords' => '我圈,博客,社区,朋友圈',
            'site_description' => '轻量级多用户社区博客系统，分享生活点滴。',
            'site_stats_code' => '',
            'allow_register' => '1',
            'allow_post' => '1',
            'allow_like' => '1',
            'allow_comment' => '1',
            'upload_max_images' => '9',
            'upload_max_size' => '10',
            'upload_compress' => '1',
            'upload_max_width' => '1920',
            'post_max_length' => '2000',
            'post_collapse_length' => '300'
        ];
        foreach ($defaults as $k => $v) {
            $exists = Database::fetchColumn("SELECT id FROM settings WHERE `key` = ? LIMIT 1", [$k]);
            if (!$exists) {
                Database::insert('settings', [
                    'key' => $k,
                    'value' => $v,
                    'created_at' => self::now(),
                    'updated_at' => self::now()
                ]);
            }
        }
    }

    /**
     * V2：为旧版 users 表补充 token_version 字段
     */
    private static function migrate2(): void
    {
        try {
            $hasColumn = Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'token_version'"
            );
            if (!$hasColumn) {
                Database::getInstance()->exec(
                    "ALTER TABLE `users` ADD COLUMN `token_version` int(11) unsigned NOT NULL DEFAULT '1' COMMENT 'Token版本号'"
                );
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V2] ' . $e->getMessage());
        }
    }

    /**
     * V3：修正默认头像路径，修复旧数据裂图
     * 统一使用相对于模板目录的 assets/images/default-avatar.svg
     */
    private static function migrate3(): void
    {
        try {
            Database::getInstance()->exec(
                "UPDATE `users` SET `avatar` = 'assets/images/default-avatar.svg' " .
                "WHERE `avatar` = 'templates/moments/assets/images/default-avatar.svg' " .
                "   OR `avatar` LIKE '%/uploads/avatars/default.svg' " .
                "   OR `avatar` = '' " .
                "   OR `avatar` IS NULL"
            );
            // 清空缓存，避免旧头像路径继续生效
            Cache::flush();
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V3] ' . $e->getMessage());
        }
    }

    /**
     * V4：补充用户权限开关配置
     * 控制普通用户是否允许发表动态、点赞、评论
     */
    private static function migrate4(): void
    {
        $defaults = [
            'allow_post' => '1',
            'allow_like' => '1',
            'allow_comment' => '1'
        ];
        try {
            foreach ($defaults as $k => $v) {
                $exists = Database::fetchColumn("SELECT id FROM settings WHERE `key` = ? LIMIT 1", [$k]);
                if (!$exists) {
                    Database::insert('settings', [
                        'key' => $k,
                        'value' => $v,
                        'created_at' => self::now(),
                        'updated_at' => self::now()
                    ]);
                }
            }
            Cache::delete('site_settings');
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V4] ' . $e->getMessage());
        }
    }

    /**
     * V5：新增图片上传配置
     * 单次最大数量、单张大小、是否压缩、压缩最大宽度
     */
    private static function migrate5(): void
    {
        $defaults = [
            'upload_max_images' => '9',
            'upload_max_size' => '10',
            'upload_compress' => '1',
            'upload_max_width' => '1920'
        ];
        try {
            foreach ($defaults as $k => $v) {
                $exists = Database::fetchColumn("SELECT id FROM settings WHERE `key` = ? LIMIT 1", [$k]);
                if (!$exists) {
                    Database::insert('settings', [
                        'key' => $k,
                        'value' => $v,
                        'created_at' => self::now(),
                        'updated_at' => self::now()
                    ]);
                }
            }
            Cache::delete('site_settings');
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V5] ' . $e->getMessage());
        }
    }

    /**
     * V6：新增消息通知表
     */
    private static function migrate6(): void
    {
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS `notifications` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) unsigned NOT NULL COMMENT '接收者用户ID',
  `sender_id` int(11) unsigned NOT NULL COMMENT '触发者用户ID',
  `type` enum('like','comment','reply') NOT NULL DEFAULT 'comment' COMMENT '通知类型',
  `post_id` int(11) unsigned DEFAULT NULL COMMENT '关联动态ID',
  `comment_id` int(11) unsigned DEFAULT NULL COMMENT '关联评论ID',
  `content` varchar(255) DEFAULT NULL COMMENT '通知内容摘要（评论内容等）',
  `is_read` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否已读：0未读 1已读',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_user_read` (`user_id`, `is_read`, `created_at`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `fk_notifications_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_notifications_sender_id` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_notifications_post_id` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_notifications_comment_id` FOREIGN KEY (`comment_id`) REFERENCES `comments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='消息通知表';
SQL;
        try {
            Database::getInstance()->exec($sql);
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V6] ' . $e->getMessage());
        }
    }

    /**
     * V7：新增附件上传支持
     * - 动态表增加 attachments 字段
     * - 新增 uploads 表统一管理图片与普通附件
     * - 新增附件与限流相关设置项
     */
    private static function migrate7(): void
    {
        try {
            $hasColumn = Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'attachments'"
            );
            if (!$hasColumn) {
                Database::getInstance()->exec(
                    "ALTER TABLE `posts` ADD COLUMN `attachments` json DEFAULT NULL COMMENT '附件数组JSON' AFTER `images`"
                );
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V7 posts] ' . $e->getMessage());
        }

        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS `uploads` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) unsigned NOT NULL COMMENT '上传用户ID',
  `post_id` int(11) unsigned DEFAULT NULL COMMENT '关联动态ID',
  `type` enum('image','attachment','video') NOT NULL DEFAULT 'image' COMMENT '类型',
  `filename` varchar(255) NOT NULL COMMENT '原始文件名',
  `url` varchar(255) NOT NULL COMMENT '访问URL',
  `size` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '文件大小（字节）',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态：0下架 1正常',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_post_id` (`post_id`),
  KEY `idx_type` (`type`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `fk_uploads_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_uploads_post_id` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='上传文件表';
SQL;
        try {
            Database::getInstance()->exec($sql);
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V7 uploads] ' . $e->getMessage());
        }

        $defaults = [
            'upload_max_attachments' => '5',
            'upload_max_attachment_size' => '50',
            'upload_attachment_exts' => 'zip,rar,7z,pdf,doc,docx,xls,xlsx,ppt,pptx,txt',
            'rate_limit_requests' => '300',
            'rate_limit_window' => '60'
        ];
        try {
            foreach ($defaults as $k => $v) {
                $exists = Database::fetchColumn("SELECT id FROM settings WHERE `key` = ? LIMIT 1", [$k]);
                if (!$exists) {
                    Database::insert('settings', [
                        'key' => $k,
                        'value' => $v,
                        'created_at' => self::now(),
                        'updated_at' => self::now()
                    ]);
                }
            }
            Cache::delete('site_settings');
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V7 settings] ' . $e->getMessage());
        }
    }

    /**
     * V8：附件去重、游客下载开关、头像上传大小限制
     */
    private static function migrate8(): void
    {
        try {
            $hasColumn = Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'uploads' AND COLUMN_NAME = 'file_hash'"
            );
            if (!$hasColumn) {
                Database::getInstance()->exec(
                    "ALTER TABLE `uploads` ADD COLUMN `file_hash` varchar(64) DEFAULT NULL COMMENT '文件哈希，用于秒传去重' AFTER `size`, ADD KEY `idx_file_hash` (`file_hash`)"
                );
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V8 uploads] ' . $e->getMessage());
        }

        $defaults = [
            'allow_guest_download_attachment' => '1',
            'upload_max_avatar_size' => '5'
        ];
        try {
            foreach ($defaults as $k => $v) {
                $exists = Database::fetchColumn("SELECT id FROM settings WHERE `key` = ? LIMIT 1", [$k]);
                if (!$exists) {
                    Database::insert('settings', [
                        'key' => $k,
                        'value' => $v,
                        'created_at' => self::now(),
                        'updated_at' => self::now()
                    ]);
                }
            }
            Cache::delete('site_settings');
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V8 settings] ' . $e->getMessage());
        }
    }

    /**
     * V9：站点描述与 SEO/统计/动态长度相关配置
     */
    private static function migrate9(): void
    {
        // 将旧版默认站点简介更新为新文案
        try {
            Database::getInstance()->exec(
                "UPDATE `settings` SET `value` = '轻量级多用户社区博客系统' WHERE `key` = 'site_desc' AND `value` IN ('轻量多用户朋友圈博客系统','')"
            );
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V9 site_desc] ' . $e->getMessage());
        }

        $defaults = [
            'site_keywords' => '我圈,博客,社区,朋友圈',
            'site_description' => '轻量级多用户社区博客系统，分享生活点滴。',
            'site_stats_code' => '',
            'post_max_length' => '2000',
            'post_collapse_length' => '300'
        ];
        try {
            foreach ($defaults as $k => $v) {
                $exists = Database::fetchColumn("SELECT id FROM settings WHERE `key` = ? LIMIT 1", [$k]);
                if (!$exists) {
                    Database::insert('settings', [
                        'key' => $k,
                        'value' => $v,
                        'created_at' => self::now(),
                        'updated_at' => self::now()
                    ]);
                }
            }
            Cache::delete('site_settings');
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V9 settings] ' . $e->getMessage());
        }
    }

    /**
     * V10：修正默认站点简介并补齐 V9 可能未生效的设置项，清理已废弃的 logo_url
     */
    private static function migrate10(): void
    {
        // 将旧版默认站点简介更新为新文案（兼容 V9 未更新的旧数据）
        try {
            Database::getInstance()->exec(
                "UPDATE `settings` SET `value` = '轻量级多用户社区博客系统' WHERE `key` = 'site_desc' AND `value` IN ('轻量多用户朋友圈博客系统','')"
            );
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V10 site_desc] ' . $e->getMessage());
        }

        $defaults = [
            'site_keywords' => '我圈,博客,社区,朋友圈',
            'site_description' => '轻量级多用户社区博客系统，分享生活点滴。',
            'site_stats_code' => '',
            'post_max_length' => '2000',
            'post_collapse_length' => '300'
        ];
        try {
            foreach ($defaults as $k => $v) {
                $exists = Database::fetchColumn("SELECT id FROM settings WHERE `key` = ? LIMIT 1", [$k]);
                if (!$exists) {
                    Database::insert('settings', [
                        'key' => $k,
                        'value' => $v,
                        'created_at' => self::now(),
                        'updated_at' => self::now()
                    ]);
                }
            }
            // 清理后台已移除的 logo_url 设置项
            Database::delete('settings', "`key` = 'logo_url'");
            Cache::delete('site_settings');
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V10 settings] ' . $e->getMessage());
        }
    }

    /**
     * V11：置顶功能字段 + 亿级数据性能索引
     */
    private static function migrate11(): void
    {
        $db = Database::getInstance();

        // posts 表增加置顶字段
        try {
            $hasPinned = Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'is_pinned'"
            );
            if (!$hasPinned) {
                $db->exec("ALTER TABLE `posts` ADD COLUMN `is_pinned` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否置顶: 0否 1是' AFTER `status`");
            }
            $hasPinnedAt = Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'pinned_at'"
            );
            if (!$hasPinnedAt) {
                $db->exec("ALTER TABLE `posts` ADD COLUMN `pinned_at` datetime DEFAULT NULL COMMENT '置顶时间' AFTER `is_pinned`");
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V11 posts columns] ' . $e->getMessage());
        }

        // 覆盖索引：让时间线/用户主页排序走索引，避免 filesort
        $indexes = [
            ['posts', 'idx_posts_status_pinned_created', 'ALTER TABLE `posts` ADD INDEX `idx_posts_status_pinned_created` (`status`, `is_pinned`, `pinned_at`, `created_at`)'],
            ['posts', 'idx_posts_user_status_pinned_created', 'ALTER TABLE `posts` ADD INDEX `idx_posts_user_status_pinned_created` (`user_id`, `status`, `is_pinned`, `pinned_at`, `created_at`)'],
            ['comments', 'idx_comments_post_created', 'ALTER TABLE `comments` ADD INDEX `idx_comments_post_created` (`post_id`, `created_at`)'],
            ['likes', 'idx_likes_post_created', 'ALTER TABLE `likes` ADD INDEX `idx_likes_post_created` (`post_id`, `created_at`)'],
        ];
        foreach ($indexes as [$table, $name, $sql]) {
            try {
                $exists = Database::fetchColumn(
                    "SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?",
                    [$table, $name]
                );
                if (!$exists) {
                    $db->exec($sql);
                }
            } catch (\Throwable $e) {
                error_log('[Hublog Migration V11 index ' . $name . '] ' . $e->getMessage());
            }
        }
    }

    /**
     * V12：修复 uploads 表 file_hash 字段缺失，并为评论图片预留 image 字段
     */
    private static function migrate12(): void
    {
        $db = Database::getInstance();

        try {
            $hasFileHash = Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'uploads' AND COLUMN_NAME = 'file_hash'"
            );
            if (!$hasFileHash) {
                $db->exec(
                    "ALTER TABLE `uploads` ADD COLUMN `file_hash` varchar(64) DEFAULT NULL COMMENT '文件哈希，用于秒传去重' AFTER `size`, ADD KEY `idx_file_hash` (`file_hash`)"
                );
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V12 uploads file_hash] ' . $e->getMessage());
        }

        try {
            $hasCommentImage = Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'comments' AND COLUMN_NAME = 'image'"
            );
            if (!$hasCommentImage) {
                $db->exec(
                    "ALTER TABLE `comments` ADD COLUMN `image` varchar(255) DEFAULT NULL COMMENT '评论图片URL' AFTER `content`"
                );
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V12 comments image] ' . $e->getMessage());
        }
    }

    /**
     * V13：新增友情链接表
     */
    private static function migrate13(): void
    {
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS `friend_links` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL COMMENT '网站名称',
  `url` varchar(255) NOT NULL COMMENT '网址',
  `description` varchar(255) DEFAULT NULL COMMENT '描述',
  `sort_order` int(11) NOT NULL DEFAULT '0' COMMENT '排序值，越小越靠前',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态：0禁用 1启用',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status_sort` (`status`, `sort_order`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='友情链接表';
SQL;
        try {
            Database::getInstance()->exec($sql);
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V13 friend_links] ' . $e->getMessage());
        }
    }

    /**
     * V14：动态增加可选标题字段
     */
    private static function migrate14(): void
    {
        try {
            $columnExists = Database::fetchColumn(
                "SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'title' LIMIT 1"
            );
            if (!$columnExists) {
                Database::getInstance()->exec("ALTER TABLE `posts` ADD COLUMN `title` varchar(255) DEFAULT NULL COMMENT '动态标题（可选）' AFTER `user_id`");
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V14 posts title] ' . $e->getMessage());
        }
    }

    /**
     * V15：增加有标题动态在列表中的预览字数配置
     */
    private static function migrate15(): void
    {
        try {
            $exists = Database::fetchColumn("SELECT id FROM settings WHERE `key` = ? LIMIT 1", ['post_title_preview_length']);
            if (!$exists) {
                Database::insert('settings', [
                    'key' => 'post_title_preview_length',
                    'value' => '150',
                    'created_at' => self::now(),
                    'updated_at' => self::now()
                ]);
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V15 post_title_preview_length] ' . $e->getMessage());
        }
    }

    /**
     * V16：新增分类表与动态分类字段
     */
    private static function migrate16(): void
    {
        try {
            $db = Database::getInstance();
            $tableExists = Database::fetchColumn(
                "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'categories' LIMIT 1"
            );
            if (!$tableExists) {
                $db->exec(<<<SQL
CREATE TABLE IF NOT EXISTS `categories` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `parent_id` int(11) unsigned DEFAULT NULL COMMENT '父分类ID，NULL为一级分类',
  `name` varchar(50) NOT NULL COMMENT '分类名称',
  `sort_order` int(11) NOT NULL DEFAULT '0' COMMENT '排序值，越小越靠前',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态：0禁用 1启用',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_parent_id` (`parent_id`),
  KEY `idx_status_sort` (`status`, `sort_order`),
  CONSTRAINT `fk_category_parent_id` FOREIGN KEY (`parent_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='分类表';
SQL
                );
            }
            $columnExists = Database::fetchColumn(
                "SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'category_id' LIMIT 1"
            );
            if (!$columnExists) {
                $db->exec("ALTER TABLE `posts` ADD COLUMN `category_id` int(11) unsigned DEFAULT NULL COMMENT '分类ID' AFTER `user_id`, ADD KEY `idx_category_id` (`category_id`)");
                $db->exec("ALTER TABLE `posts` ADD CONSTRAINT `fk_posts_category_id` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL");
            }
            $configExists = Database::fetchColumn("SELECT id FROM settings WHERE `key` = ? LIMIT 1", ['category_required']);
            if (!$configExists) {
                Database::insert('settings', [
                    'key' => 'category_required',
                    'value' => '0',
                    'created_at' => self::now(),
                    'updated_at' => self::now()
                ]);
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V16 categories] ' . $e->getMessage());
        }
    }

    /**
     * V17：新增标题必填与伪静态开关
     */
    private static function migrate17(): void
    {
        $defaults = [
            'title_required' => '0',
            'url_rewrite' => '0'
        ];
        try {
            foreach ($defaults as $k => $v) {
                $exists = Database::fetchColumn("SELECT id FROM settings WHERE `key` = ? LIMIT 1", [$k]);
                if (!$exists) {
                    Database::insert('settings', [
                        'key' => $k,
                        'value' => $v,
                        'created_at' => self::now(),
                        'updated_at' => self::now()
                    ]);
                }
            }
            Cache::delete('site_settings');
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V17 settings] ' . $e->getMessage());
        }
    }

    /**
     * V19：为 posts 表添加 FULLTEXT 索引，支撑内容搜索
     *
     * V93 修复：原来这里写死 `WITH PARSER ngram` —— 该语法是 MySQL 5.7+ 专有，
     * MariaDB 不支持，整条 ALTER 报语法错。老库升级时这个索引从来没建成，
     * 而 SearchController 只看「关键词 ≥ 2 字符」就用 MATCH...AGAINST，
     * 索引不存在时 MySQL 直接报 1191（找不到匹配的 FULLTEXT 索引）→ 搜索接口 500。
     *
     * 现在改为：ngram 可用就用 ngram，不可用就建普通 FULLTEXT，
     * 两者都失败则不建索引（搜索退回 LIKE，由 V93 迁移再兜一次）。
     */
    private static function migrate19(): void
    {
        try {
            $exists = Database::fetchColumn(
                "SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND INDEX_NAME = 'idx_posts_fulltext' LIMIT 1"
            );
            if (!$exists) {
                if (self::supportsNgramParser()) {
                    Database::getInstance()->exec(
                        "ALTER TABLE `posts` ADD FULLTEXT INDEX `idx_posts_fulltext` (`title`, `content`) WITH PARSER ngram"
                    );
                } else {
                    Database::getInstance()->exec(
                        "ALTER TABLE `posts` ADD FULLTEXT INDEX `idx_posts_fulltext` (`title`, `content`)"
                    );
                }
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V19 posts fulltext] ' . $e->getMessage());
        }
    }

    /**
     * V20：新增动态收藏表
     */
    private static function migrate20(): void
    {
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS `favorites` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) unsigned NOT NULL COMMENT '用户ID',
  `post_id` int(11) unsigned NOT NULL COMMENT '动态ID',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_post` (`user_id`, `post_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_post_id` (`post_id`),
  CONSTRAINT `fk_favorites_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_favorites_post` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='动态收藏表';
SQL;
        try {
            Database::getInstance()->exec($sql);
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V20 favorites] ' . $e->getMessage());
        }
    }

    /**
     * V18：新增关注关系表
     */
    private static function migrate18(): void
    {
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS `follows` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `follower_id` int(11) unsigned NOT NULL COMMENT '关注者ID',
  `following_id` int(11) unsigned NOT NULL COMMENT '被关注者ID',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_follower_following` (`follower_id`, `following_id`),
  KEY `idx_follower_id` (`follower_id`),
  KEY `idx_following_id` (`following_id`),
  CONSTRAINT `fk_follows_follower` FOREIGN KEY (`follower_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_follows_following` FOREIGN KEY (`following_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='关注关系表';
SQL;
        try {
            Database::getInstance()->exec($sql);
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V18 follows] ' . $e->getMessage());
        }

        // 扩展通知类型枚举，支持关注通知
        try {
            Database::getInstance()->exec(
                "ALTER TABLE `notifications` MODIFY COLUMN `type` enum('like','comment','reply','follow') NOT NULL DEFAULT 'comment' COMMENT '通知类型'"
            );
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V18 notifications type] ' . $e->getMessage());
        }
    }

    /**
     * V21：用户举报表
     */
    private static function migrate21(): void
    {
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS `reports` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `reporter_id` int(11) unsigned NOT NULL COMMENT '举报人ID',
  `target_type` enum('post','comment','user') NOT NULL COMMENT '举报对象类型',
  `target_id` int(11) unsigned NOT NULL COMMENT '举报对象ID',
  `reason` varchar(20) NOT NULL COMMENT '举报原因：spam/porn/abuse/other',
  `description` varchar(500) DEFAULT NULL COMMENT '补充说明',
  `status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '状态：0待处理 1已处理 2已驳回',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_reporter_id` (`reporter_id`),
  KEY `idx_target` (`target_type`, `target_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_reports_reporter` FOREIGN KEY (`reporter_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户举报表';
SQL;
        try {
            Database::getInstance()->exec($sql);
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V21 reports] ' . $e->getMessage());
        }
    }

    /**
     * V22：智能 AI 自动评论功能
     * - comments 表新增 is_ai 字段标记 AI 评论
     * - 创建虚拟 AI 用户（ai_assistant）
     * - 写入 AI 评论相关默认设置项
     */
    private static function migrate22(): void
    {
        // 1. comments 表新增 is_ai 字段
        try {
            $hasColumn = Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'comments' AND COLUMN_NAME = 'is_ai'"
            );
            if (!$hasColumn) {
                Database::getInstance()->exec(
                    "ALTER TABLE `comments` ADD COLUMN `is_ai` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否为 AI 自动评论：0否 1是' AFTER `content`, ADD KEY `idx_is_ai` (`is_ai`)"
                );
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V22 comments is_ai] ' . $e->getMessage());
        }

        // 2. 创建虚拟 AI 用户（约定：管理员 id=1，AI 评论家 id=2）
        try {
            $exists = Database::fetchColumn(
                "SELECT id FROM users WHERE username = 'ai_assistant' LIMIT 1"
            );
            if (!$exists) {
                $id2Taken = Database::fetchColumn(
                    "SELECT id FROM users WHERE id = 2 LIMIT 1"
                );
                if (!$id2Taken) {
                    // id=2 未被占用，显式指定 id=2（全新安装路径）
                    Database::getInstance()->exec(
                        "INSERT INTO `users` (`id`, `username`, `password`, `email`, `nickname`, `avatar`, `bio`, `role`, `status`, `token_version`, `created_at`, `updated_at`) VALUES (2, 'ai_assistant', "
                        . Database::getInstance()->quote(Auth::hashPassword(bin2hex(random_bytes(16))))
                        . ", 'ai_assistant@hublog.local', 'AI评论家', 'assets/images/default-avatar.svg', "
                        . Database::getInstance()->quote('我是 Hublog 内置的 AI 评论家，会自动对动态发表评论～')
                        . ", 'user', 1, 1, '" . self::now() . "', '" . self::now() . "')"
                    );
                } else {
                    // id=2 已被占用（升级场景），回退到自增
                    Database::insert('users', [
                        'username' => 'ai_assistant',
                        'password' => Auth::hashPassword(bin2hex(random_bytes(16))),
                        'email' => 'ai_assistant@hublog.local',
                        'nickname' => 'AI评论家',
                        'avatar' => 'assets/images/default-avatar.svg',
                        'bio' => '我是 Hublog 内置的 AI 评论家，会自动对动态发表评论～',
                        'role' => 'user',
                        'status' => 1,
                        'token_version' => 1,
                        'created_at' => self::now(),
                        'updated_at' => self::now()
                    ]);
                }
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V22 ai_assistant user] ' . $e->getMessage());
        }

        // 3. 写入 AI 评论默认配置（默认本地 Ollama，无需 API Key）
        $defaults = [
            'ai_comment_enabled' => '0',
            'ai_comment_provider' => 'ollama',
            'ai_comment_api_url' => 'http://localhost:11434/v1/chat/completions',
            'ai_comment_api_key' => '',
            'ai_comment_model' => 'llama3.2',
            'ai_comment_prompt' => "你是一位热心的网友，正在浏览朋友圈。请根据下面这条动态，用 30-80 字发表一句真诚、有趣或带点幽默的评论。不要使用 emoji 以外的特殊字符，不要解释你在做什么，直接输出评论内容即可。",
            'ai_comment_max_length' => '200',
            'ai_comment_timeout' => '30'
        ];
        try {
            foreach ($defaults as $k => $v) {
                $exists = Database::fetchColumn("SELECT id FROM settings WHERE `key` = ? LIMIT 1", [$k]);
                if (!$exists) {
                    Database::insert('settings', [
                        'key' => $k,
                        'value' => $v,
                        'created_at' => self::now(),
                        'updated_at' => self::now()
                    ]);
                }
            }
            Cache::delete('site_settings');
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V22 settings] ' . $e->getMessage());
        }
    }

    /**
     * V23：智能内容审核 + 智能摘要/标签
     * - posts 表新增 summary 字段
     * - 创建 post_tags 表
     * - 写入内容审核与智能摘要默认配置
     */
    private static function migrate23(): void
    {
        // 1. posts 表新增 summary 字段
        try {
            $hasColumn = Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'summary'"
            );
            if (!$hasColumn) {
                Database::getInstance()->exec(
                    "ALTER TABLE `posts` ADD COLUMN `summary` varchar(300) DEFAULT NULL COMMENT 'AI 生成的内容摘要' AFTER `content`"
                );
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V23 posts summary] ' . $e->getMessage());
        }

        // 2. 创建 post_tags 表
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS `post_tags` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `post_id` int(11) unsigned NOT NULL COMMENT '动态ID',
  `tag` varchar(30) NOT NULL COMMENT '标签名称',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_post_id` (`post_id`),
  KEY `idx_tag` (`tag`),
  CONSTRAINT `fk_post_tags_post` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='动态标签表';
SQL;
        try {
            Database::getInstance()->exec($sql);
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V23 post_tags] ' . $e->getMessage());
        }

        // 3. 写入内容审核默认配置（默认本地 Ollama，无需 API Key）
        $auditDefaults = [
            'ai_audit_enabled' => '0',
            'ai_audit_provider' => 'ollama',
            'ai_audit_api_url' => 'http://localhost:11434/v1/chat/completions',
            'ai_audit_api_key' => '',
            'ai_audit_model' => 'llama3.2',
            'ai_audit_custom_words' => ''
        ];
        try {
            foreach ($auditDefaults as $k => $v) {
                $exists = Database::fetchColumn("SELECT id FROM settings WHERE `key` = ? LIMIT 1", [$k]);
                if (!$exists) {
                    Database::insert('settings', [
                        'key' => $k,
                        'value' => $v,
                        'created_at' => self::now(),
                        'updated_at' => self::now()
                    ]);
                }
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V23 audit settings] ' . $e->getMessage());
        }

        // 4. 写入智能摘要默认配置
        $summaryDefaults = [
            'smart_summary_enabled' => '0',
            'smart_summary_provider' => 'deepseek',
            'smart_summary_api_url' => 'https://api.deepseek.com/v1/chat/completions',
            'smart_summary_api_key' => '',
            'smart_summary_model' => 'deepseek-chat'
        ];
        try {
            foreach ($summaryDefaults as $k => $v) {
                $exists = Database::fetchColumn("SELECT id FROM settings WHERE `key` = ? LIMIT 1", [$k]);
                if (!$exists) {
                    Database::insert('settings', [
                        'key' => $k,
                        'value' => $v,
                        'created_at' => self::now(),
                        'updated_at' => self::now()
                    ]);
                }
            }
            Cache::delete('site_settings');
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V23 summary settings] ' . $e->getMessage());
        }
    }

    /**
     * V24：智能搜索 + 智能推荐
     * - posts 表新增 embedding 字段（向量存储）
     * - 创建 user_actions 用户行为日志表
     * - 写入智能搜索与智能推荐默认配置
     */
    private static function migrate24(): void
    {
        // 1. posts 表新增 embedding 字段
        try {
            $hasColumn = Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'embedding'"
            );
            if (!$hasColumn) {
                Database::getInstance()->exec(
                    "ALTER TABLE `posts` ADD COLUMN `embedding` json DEFAULT NULL COMMENT '向量嵌入，用于语义搜索' AFTER `summary`"
                );
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V24 posts embedding] ' . $e->getMessage());
        }

        // 2. 创建 user_actions 用户行为日志表
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS `user_actions` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) unsigned NOT NULL COMMENT '用户ID',
  `post_id` int(11) unsigned NOT NULL COMMENT '动态ID',
  `action` enum('like','comment','view') NOT NULL COMMENT '行为类型：点赞/评论/查看',
  `weight` decimal(5,2) NOT NULL DEFAULT '1.00' COMMENT '行为权重',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_post_id` (`post_id`),
  KEY `idx_action` (`action`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_user_action_created` (`user_id`, `action`, `created_at`),
  CONSTRAINT `fk_user_actions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_user_actions_post` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户行为日志表';
SQL;
        try {
            Database::getInstance()->exec($sql);
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V24 user_actions] ' . $e->getMessage());
        }

        // 3. 写入智能搜索默认配置
        $searchDefaults = [
            'smart_search_enabled' => '0',
            'smart_search_provider' => 'deepseek',
            'smart_search_api_url' => 'https://api.deepseek.com/v1/embeddings',
            'smart_search_api_key' => '',
            'smart_search_model' => 'text-embedding-v1'
        ];
        try {
            foreach ($searchDefaults as $k => $v) {
                $exists = Database::fetchColumn("SELECT id FROM settings WHERE `key` = ? LIMIT 1", [$k]);
                if (!$exists) {
                    Database::insert('settings', [
                        'key' => $k,
                        'value' => $v,
                        'created_at' => self::now(),
                        'updated_at' => self::now()
                    ]);
                }
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V24 search settings] ' . $e->getMessage());
        }

        // 4. 写入智能推荐默认配置
        $recommendDefaults = [
            'smart_recommend_enabled' => '0',
            'smart_recommend_max_items' => '20'
        ];
        try {
            foreach ($recommendDefaults as $k => $v) {
                $exists = Database::fetchColumn("SELECT id FROM settings WHERE `key` = ? LIMIT 1", [$k]);
                if (!$exists) {
                    Database::insert('settings', [
                        'key' => $k,
                        'value' => $v,
                        'created_at' => self::now(),
                        'updated_at' => self::now()
                    ]);
                }
            }
            Cache::delete('site_settings');
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V24 recommend settings] ' . $e->getMessage());
        }
    }

    /**
     * V25：将 enum 类型字段改为 varchar，支持未来扩展（如通知类型新增 ai、系统等）
     */
    private static function migrate25(): void
    {
        try {
            Database::getInstance()->exec(
                "ALTER TABLE `notifications` MODIFY COLUMN `type` varchar(20) NOT NULL DEFAULT 'comment' COMMENT '通知类型'"
            );
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V25 notifications type] ' . $e->getMessage());
        }

        try {
            Database::getInstance()->exec(
                "ALTER TABLE `user_actions` MODIFY COLUMN `action` varchar(20) NOT NULL COMMENT '行为类型'"
            );
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V25 user_actions action] ' . $e->getMessage());
        }
    }

}