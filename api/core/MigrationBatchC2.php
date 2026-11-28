<?php
/**
 * Hublog 增量数据库迁移方法集合 (V74 - V81)
 *
 * 由 api/core/Migration.php 拆分而来，主迁移类 Hublog\Core\Migration
 * 通过 `use MigrationBatchC2` 引入这些方法，旧版本升级时仍按版本号顺序执行；
 * 全新安装走 buildFullSchema() 全量建表后循环补跑增量迁移。
 *
 * 拆分零行为差异：trait 方法在编译期展平进主类，run() 的动态分发
 * self::{"migrate{$v}"}() 与新装补跑循环均正常命中，无需改动调用方。
 */

declare(strict_types=1);

namespace Hublog\Core;

trait MigrationBatchC2
{


    /**
     * V82：徽章系统 —— 建 badges / user_badges / badge_logs 三表 + 38 枚徽章种子数据。
     *
     * 设计要点：
     * - rule_type 四类：count=定时扫描统计 / realtime=实时校验 / timed=限时 / manual=人工授予；
     * - user_badges.state 只落 unlock(1)/freeze(2)/revoke(3)，not_reach 不落库（查询时按阈值现算）；
     * - threshold 为 JSON，后台可改阈值不改代码；
     * - badge_key 是稳定标识（改名单据不变），uk_badge_key 保证种子数据幂等；
     * - 三张新表已登记进 criticalTablesExist() 的 $requiredTables。
     */
    private static function migrate74(): void
    {
        $db = Database::getInstance();
        self::log('开始 V82：徽章系统建表与种子数据');

        $tables = [
            'badges' => "CREATE TABLE IF NOT EXISTS `badges` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `badge_key` varchar(50) NOT NULL COMMENT '徽章唯一标识（如 writer_lv1，稳定不变）',
  `name` varchar(50) NOT NULL COMMENT '徽章名称',
  `desc` varchar(255) DEFAULT NULL COMMENT '徽章介绍文案',
  `category` varchar(20) NOT NULL DEFAULT 'creation' COMMENT '分类：auth/vip/activity/creation/comment/social/circle/event',
  `icon` varchar(50) NOT NULL DEFAULT 'award' COMMENT '图标名（App 端 Icon.vue 内注册）',
  `color` varchar(20) NOT NULL DEFAULT '#3b8cfa' COMMENT '主色 HEX',
  `rule_type` varchar(16) NOT NULL DEFAULT 'count' COMMENT 'count=统计类 realtime=实时 timed=限时 manual=人工授予',
  `threshold` json DEFAULT NULL COMMENT '解锁阈值JSON，如 {\"min_posts\":20}',
  `sort_weight` int NOT NULL DEFAULT 0 COMMENT '展示权重，越大越优先',
  `hideable` tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否允许用户隐藏（身份/VIP/管理类=0）',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1启用 0禁用（禁用后停止自动发放，已获取的保留）',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_badge_key` (`badge_key`),
  KEY `idx_status_sort` (`status`, `sort_weight`),
  KEY `idx_category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='徽章配置表'",

            'user_badges' => "CREATE TABLE IF NOT EXISTS `user_badges` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `badge_id` int unsigned NOT NULL,
  `state` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1=unlock 2=freeze 3=revoke（not_reach 不落库）',
  `unlock_time` datetime DEFAULT NULL COMMENT '解锁时间',
  `expire_time` datetime DEFAULT NULL COMMENT '过期时间（限时徽章，如新人启航）',
  `grant_type` varchar(10) NOT NULL DEFAULT 'auto' COMMENT 'auto=自动解锁 manual=后台人工授予',
  `operator_id` int unsigned DEFAULT NULL COMMENT '人工操作人ID',
  `remark` varchar(255) DEFAULT NULL COMMENT '操作备注',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_badge` (`user_id`, `badge_id`),
  KEY `idx_user_state` (`user_id`, `state`),
  KEY `idx_badge_state` (`badge_id`, `state`),
  CONSTRAINT `fk_user_badges_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_user_badges_badge` FOREIGN KEY (`badge_id`) REFERENCES `badges` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户徽章关联表'",

            'badge_logs' => "CREATE TABLE IF NOT EXISTS `badge_logs` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `operator_id` int unsigned DEFAULT NULL COMMENT '操作人ID（系统自动为NULL）',
  `operator_name` varchar(50) DEFAULT NULL COMMENT '操作人名快照',
  `user_id` int unsigned NOT NULL COMMENT '目标用户ID',
  `badge_id` int unsigned DEFAULT NULL COMMENT '目标徽章ID',
  `action` varchar(20) NOT NULL COMMENT 'grant/freeze/revoke/restore/expire/config_create/config_update/config_toggle',
  `from_state` tinyint(1) DEFAULT NULL COMMENT '变更前状态',
  `to_state` tinyint(1) DEFAULT NULL COMMENT '变更后状态',
  `remark` varchar(255) DEFAULT NULL COMMENT '操作备注',
  `ip` varchar(45) DEFAULT NULL COMMENT '操作IP',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_badge` (`badge_id`),
  KEY `idx_action` (`action`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='徽章操作日志（只读审计，不提供删除）'",
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
                error_log("[Hublog Migration V82 {$table}] " . $e->getMessage());
                if (APP_DEBUG) {
                    throw $e;
                }
            }
        }

        // ---------- 种子数据：38 枚徽章 ----------
        // 仅在 badges 表为空时插入（INSERT IGNORE + badge_key 唯一键双保险，重复执行幂等；
        // 后台改过阈值/名称的存量库不会被覆盖）。
        try {
            $count = (int)Database::fetchColumn("SELECT COUNT(*) FROM badges");
            if ($count === 0) {
                // [badge_key, name, desc, category, icon, color, rule_type, threshold, sort_weight, hideable]
                $badges = [
                    // —— 账号认证（蓝色）——
                    ['phone_verified', '手机已认证', '账号已完成手机号绑定，具备可联系渠道', 'auth', 'phone', '#3b8cfa', 'realtime', '{"require":"phone"}', 30, 0],
                    ['email_verified', '邮箱已认证', '账号已完成邮箱验证，支持密码找回与平台通知', 'auth', 'mail', '#5aa9e6', 'realtime', '{"require":"email"}', 25, 1],
                    ['realname_verified', '实名认证', '账号已通过实名核验，代表真实身份', 'auth', 'realname', '#2f6fd6', 'realtime', '{"require":"realname"}', 60, 0],
                    ['profile_complete', '资料完整达人', '完善个人基础资料，方便其他圈友认识你', 'auth', 'user-check', '#7ec8f2', 'realtime', '{"require":"profile"}', 10, 1],
                    // —— 会员付费（金/紫）——
                    ['vip_monthly', 'VIP会员', '付费基础会员，解锁平台基础会员特权', 'vip', 'vip', '#8e5cf7', 'realtime', '{"min_level":1,"max_level":2}', 80, 0],
                    ['vip_yearly', '年度VIP会员', '一次性购买整年会员，专属年度标识', 'vip', 'crown', '#6c3fd1', 'realtime', '{"min_level":3}', 90, 0],
                    // —— 活跃资历（绿色）——
                    ['newcomer', '新人启航', '平台新用户标识，欢迎加入圈子社区', 'activity', 'flag', '#34a853', 'timed', '{"days":7}', 5, 1],
                    ['signin_7', '连续打卡7天', '连续7天每日登录签到，保持社区活跃', 'activity', 'calendar', '#43b75d', 'count', '{"streak":7}', 20, 1],
                    ['signin_30', '连续打卡30天', '坚持30天每日签到，社区常驻活跃用户', 'activity', 'calendar', '#2e9e4f', 'count', '{"streak":30}', 30, 1],
                    ['signin_100', '连续打卡100天', '百天不间断签到，社区重度常驻用户', 'activity', 'calendar', '#1e7d3c', 'count', '{"streak":100}', 40, 1],
                    ['user_1y', '一年老用户', '注册满1周年，陪伴社区成长的老圈友', 'activity', 'clock', '#2e7d32', 'count', '{"days":365}', 35, 1],
                    ['user_3y', '三年老用户', '注册满3周年，社区资深元老用户', 'activity', 'clock', '#1b5e20', 'count', '{"days":1095}', 45, 1],
                    // —— 内容创作（橙琥珀）——
                    ['writer_lv1', '多产写手 Lv1', '乐于发帖分享，初级内容创作者（有效帖≥20篇）', 'creation', 'pen', '#f5a623', 'count', '{"min_posts":20}', 20, 1],
                    ['writer_lv2', '多产写手 Lv2', '高频产出帖子，社区内容贡献者（有效帖≥100篇）', 'creation', 'pen', '#f28c28', 'count', '{"min_posts":100}', 25, 1],
                    ['writer_lv3', '多产写手 Lv3', '高产创作者，持续输出内容（有效帖≥500篇）', 'creation', 'pen', '#e8720c', 'count', '{"min_posts":500}', 30, 1],
                    ['image_master', '图文达人', '擅长图文发帖，图片搭配文字分享内容（图文帖≥30篇）', 'creation', 'image', '#ff9f43', 'count', '{"min_image_posts":30}', 20, 1],
                    ['video_creator', '视频创作者', '以视频形式分享内容（视频帖≥10篇）', 'creation', 'video', '#ff8c42', 'count', '{"min_video_posts":10}', 20, 1],
                    ['featured_contributor', '精华贡献者', '帖子被设为精华帖，内容获得圈子认可（≥3篇）', 'creation', 'pin', '#e8a020', 'count', '{"min_featured":3}', 30, 1],
                    ['viral_author', '爆款作者', '产出过高浏览量热门帖子（单帖浏览≥5000）', 'creation', 'fire', '#ff7a1a', 'count', '{"min_views":5000}', 30, 1],
                    ['quality_creator', '优质创作者', '社区高阶创作者，内容稳定高质量（精华≥5且有效帖≥100）', 'creation', 'star', '#ffb724', 'count', '{"min_featured":5,"min_posts":100}', 50, 1],
                    // —— 评论成就（黄色）——
                    ['first_comment', '初言者', '开始在评论区发言，开启圈子讨论交流（有效评论≥10条）', 'comment', 'message', '#f7d154', 'count', '{"min_comments":10}', 10, 1],
                    ['comment_regular', '评论常客', '经常参与帖子讨论，活跃在评论区（有效评论≥100条）', 'comment', 'comment', '#f5c211', 'count', '{"min_comments":100}', 20, 1],
                    ['comment_master', '评论达人', '重度评论参与者，热衷于圈友讨论（有效评论≥1000条）', 'comment', 'comment', '#e0a800', 'count', '{"min_comments":1000}', 30, 1],
                    ['reply_expert', '回复能手', '喜欢楼中楼回复他人，参与多层对话（回复≥50条）', 'comment', 'reply', '#f2c94c', 'count', '{"min_replies":50}', 20, 1],
                    ['answer_helper', '热心答主', '常在评论区回答提问，帮助圈友解决疑问（被采纳≥5次）', 'comment', 'check-circle', '#ffd166', 'count', '{"min_accepts":5}', 30, 1],
                    ['heartfelt_comment', '走心评论', '多次发布长篇深度评论，拒绝简短灌水（长评论≥20条）', 'comment', 'heart', '#ffcf6b', 'count', '{"min_long_comments":20,"min_length":80}', 20, 1],
                    ['sofa_hunter', '沙发达人', '手速快，多次抢到帖子首评（沙发）（≥10次）', 'comment', 'sofa', '#ffe066', 'count', '{"min_sofa":10}', 25, 1],
                    ['hot_comment', '热评常客', '评论多次进入帖子热评列表，产出热门评论（高赞评论≥15条）', 'comment', 'fire', '#f4b400', 'count', '{"min_hot_comments":15,"min_comment_likes":10}', 30, 1],
                    // —— 社交互动（浅橙）——
                    ['like_giver', '暖心点赞手', '乐于给他人帖子、评论点赞，为圈友内容加油（≥500次）', 'social', 'heart', '#ff8c42', 'count', '{"min_likes_given":500}', 15, 1],
                    ['favorite_lover', '收藏爱好者', '喜欢收藏优质帖子，方便后续回看（≥50篇）', 'social', 'bookmark', '#ff9f5a', 'count', '{"min_favorites":50}', 15, 1],
                    ['social_butterfly', '社交达人', '在平台结识大量圈友，社交关系丰富（关注≥200人）', 'social', 'users', '#ffa94d', 'count', '{"min_following":200}', 20, 1],
                    // —— 圈子管理身份（红色）——
                    ['circle_owner', '圈主', '圈子创建人，拥有圈子最高管理权限', 'circle', 'home', '#e64340', 'realtime', '{"require":"circle_owner"}', 100, 0],
                    ['circle_admin', '圈子管理员', '圈主任命的管理员，负责管理圈子内容与成员', 'circle', 'wrench', '#c0392b', 'realtime', '{"require":"circle_admin"}', 90, 0],
                    ['platform_admin', '平台官方管理员', '平台超级管理员，拥有全站最高管理权限', 'circle', 'shield', '#8e1f1a', 'realtime', '{"require":"platform_admin"}', 110, 0],
                    // —— 活动限定（多彩）——
                    ['activity_participant', '活动参与者', '参与平台官方主题活动（报名≥1个）', 'event', 'gift', '#ff6b9d', 'count', '{"min_activities":1}', 25, 1],
                    ['topic_contributor', '话题贡献者', '积极参与官方话题，在话题下产出有效内容（≥5篇）', 'event', 'hash', '#a29bfe', 'count', '{"min_topic_posts":5}', 20, 1],
                    ['activity_winner', '活动优胜者', '在平台官方活动取得优秀名次，活动奖励徽章', 'event', 'trophy', '#fd79a8', 'manual', null, 60, 1],
                    ['annual_star', '年度优秀用户', '平台年度评选，综合贡献、内容质量、文明互动的荣誉用户', 'event', 'award', '#b2bec3', 'manual', null, 70, 1],
                ];
                $sql = "INSERT IGNORE INTO `badges`
                        (`badge_key`,`name`,`desc`,`category`,`icon`,`color`,`rule_type`,`threshold`,`sort_weight`,`hideable`,`status`)
                        VALUES (?,?,?,?,?,?,?,?,?,?,1)";
                $stmt = $db->prepare($sql);
                foreach ($badges as $b) {
                    $stmt->execute($b);
                }
                self::log('V82 徽章种子数据插入完成：' . count($badges) . ' 枚');
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V82 badge seed] ' . $e->getMessage());
            if (APP_DEBUG) {
                throw $e;
            }
        }

        // 收尾自检：三张表必须都在
        try {
            $missing = [];
            foreach (array_keys($tables) as $table) {
                $exists = Database::fetchOne(
                    "SELECT TABLE_NAME FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1",
                    [$table]
                );
                if (!$exists) {
                    $missing[] = $table;
                }
            }
            if ($missing) {
                error_log('[Hublog Migration V82 verify] 徽章表缺失: ' . implode(',', $missing));
            } else {
                $seeded = (int)Database::fetchColumn("SELECT COUNT(*) FROM badges");
                self::log("V82 校验通过：徽章三表就绪，徽章配置 {$seeded} 条");
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V82 verify] ' . $e->getMessage());
        }
    }


    /**
     * V83：徽章展示位治理 + 原创作者徽章 + 神评体系
     *
     * 1) badges 表加 display 列（1=内联名字旁，0=仅个人主页横排），并把身份/认证/角色类徽章
     *    标记为内联，使「实名徽章在哪、新徽章就在哪」统一成立；
     * 2) posts 表加 is_original（原创标记），支撑「原创作者」徽章；
     * 3) 新建 comment_marks 表（神评等评论标记），支撑神评标签 + 两枚联动徽章。
     */
    private static function migrate75(): void
    {
        // ---- 1. badges.display 列（幂等加列）----
        try {
            $hasDisplay = (int)Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'badges' AND COLUMN_NAME = 'display'"
            );
            if (!$hasDisplay) {
                Database::query(
                    "ALTER TABLE `badges` ADD COLUMN `display` tinyint NOT NULL DEFAULT 0
                     COMMENT '1=内联名字旁展示，0=仅个人主页横排' AFTER `hideable`"
                );
                self::log('V83：badges 表增加 display 列');
            }
            // 身份/认证/角色类徽章内联展示（与实名徽章同一位置）
            Database::update(
                'badges',
                ['display' => 1],
                "badge_key IN ('phone_verified','email_verified','realname_verified','profile_complete',"
                . "'vip_monthly','vip_yearly','circle_owner','circle_admin','platform_admin')"
            );
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V83 badges.display] ' . $e->getMessage());
        }

        // ---- 2. posts.is_original 列（幂等加列）----
        try {
            $hasOriginal = (int)Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'is_original'"
            );
            if (!$hasOriginal) {
                Database::query(
                    "ALTER TABLE `posts` ADD COLUMN `is_original` tinyint NOT NULL DEFAULT 0
                     COMMENT '是否原创内容（发布时勾选）' AFTER `is_featured`"
                );
                self::log('V83：posts 表增加 is_original 列');
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V83 posts.is_original] ' . $e->getMessage());
        }

        // ---- 3. comment_marks 表 ----
        try {
            Database::query(
                "CREATE TABLE IF NOT EXISTS `comment_marks` (
                    `id` int unsigned NOT NULL AUTO_INCREMENT,
                    `comment_id` int unsigned NOT NULL,
                    `mark_type` varchar(20) NOT NULL DEFAULT 'god' COMMENT '标记类型：god=神评',
                    `operator_id` int unsigned NOT NULL DEFAULT 0 COMMENT '标记人',
                    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uk_comment_mark` (`comment_id`, `mark_type`),
                    KEY `idx_operator` (`operator_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='评论标记（神评等）'"
            );
            self::log('V83：comment_marks 表就绪');
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V83 comment_marks] ' . $e->getMessage());
        }

        // ---- 4. 新增徽章种子（原创作者 / 神评两枚）----
        try {
            // [badge_key,name,desc,category,icon,color,rule_type,threshold,sort_weight,hideable,display]
            $newBadges = [
                ['original_author', '原创作者', '坚持原创内容创作，标注原创分享独到见解（原创帖≥10篇）', 'creation', 'pen', '#f5a623', 'count', '{"min_original":10}', 22, 1, 1],
                ['god_rising', '新晋神评选手', '评论频获圈友公认的神评，崭露头角（被标神评≥3次）', 'comment', 'award', '#ffce54', 'count', '{"min_god_comments":3}', 28, 1, 0],
                ['god_master', '封神评论官', '高产神评制造机，评论区公认的封神存在（被标神评≥20次）', 'comment', 'trophy', '#ffb300', 'count', '{"min_god_comments":20}', 40, 1, 0],
            ];
            $sql = "INSERT IGNORE INTO `badges`
                    (`badge_key`,`name`,`desc`,`category`,`icon`,`color`,`rule_type`,`threshold`,`sort_weight`,`hideable`,`display`,`status`)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,1)";
            $stmt = Database::getInstance()->prepare($sql);
            foreach ($newBadges as $b) {
                $stmt->execute($b);
            }
            self::log('V83：新增徽章种子 ' . count($newBadges) . ' 枚');
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V83 badge seed] ' . $e->getMessage());
        }
    }


    /**
     * V84：清理徽章系统误写入的「从未解锁过却处于冻结态」的记录。
     *
     * 背景：BadgeService 的冻结逻辑原先对「达标/不达标」都直接 setState(FREEZE)，
     * 而 setState 在无记录时会 INSERT。于是每个用户都会为每个未达标的 count 徽章
     * 凭空生成一条 freeze 记录并写一条日志——实测 7 个用户就产生 263 条，
     * 既撑大 user_badges 表，也让后台「用户徽章」列表被大量冻结项刷屏。
     *
     * 冻结的语义是「收回已发出的徽章」，从未发出过的自然不该有记录。
     * 判定依据：badge_logs 中该 user+badge 从未出现 to_state=1（解锁）。
     *
     * badge_logs 是只读审计表（设计上不提供删除），故只清理 user_badges，日志保留。
     * 本迁移幂等：重复执行时已无符合条件的记录，删除 0 行。
     */
    private static function migrate76(): void
    {
        try {
            $hasUserBadges = Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_badges'"
            );
            $hasLogs = Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'badge_logs'"
            );
            if (!$hasUserBadges || !$hasLogs) {
                return;
            }

            $junk = (int)Database::fetchColumn(
                "SELECT COUNT(*) FROM user_badges ub
                 WHERE ub.state = 2
                   AND NOT EXISTS (
                        SELECT 1 FROM badge_logs bl
                        WHERE bl.user_id = ub.user_id
                          AND bl.badge_id = ub.badge_id
                          AND bl.to_state = 1
                   )"
            );

            if ($junk > 0) {
                Database::query(
                    "DELETE ub FROM user_badges ub
                     WHERE ub.state = 2
                       AND NOT EXISTS (
                            SELECT 1 FROM badge_logs bl
                            WHERE bl.user_id = ub.user_id
                              AND bl.badge_id = ub.badge_id
                              AND bl.to_state = 1
                       )"
                );
            }

            self::log('V84：清理「从未解锁却被冻结」的无效徽章记录 ' . $junk . ' 条');
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V84] ' . $e->getMessage());
        }
    }


    /**
     * V85：补齐 PRD 徽章清单里缺失的 10 枚。
     *
     * PRD《通用圈子社交APP - 徽章系统》共 51 项，此前只实现了 41 枚，缺：
     *   账号安全卫士、体验VIP、至尊皇冠会员、平台捐助者、常驻用户、
     *   优质评论官、真知灼见、理性观察员、暖心安慰者、全站审核员
     *
     * 分两类处理（重要，别混淆）：
     *  - 有数据基础的 3 枚做自动解锁：
     *      vip_trial         → vip_level = 4（体验会员）   ← 见下方修正
     *      vip_crown         → vip_level = 5（至尊皇冠会员）← 见下方修正
     *      platform_reviewer → users.role = 'reviewer'（审核员角色，本迁移新增枚举值）
     *
     * ⚠️ 修正（V86）：当初误以为会员会扩到 4、5 档，实际会员始终只有 1/2/3
     *    三档（VIP_DAYS 只认 1/2/3，后台 grant-vip 也硬校验 1/2/3），4/5 无人能到达，
     *    故 V86（migrate78）把 vip_trial / vip_crown 改成了 manual 人工授予。
     *    这里的种子仍按 realtime 插入，靠 migrate78 翻正 —— 保证老库升级路径一致。
     *  - 其余 7 枚依赖的功能源码里尚不存在（2FA 二次验证、捐赠支付、在线时长统计、
     *    评论精选/收藏/感谢/处罚记录），硬做成自动解锁只会变成永远解不开的死徽章，
     *    故设为 manual 人工授予：后台确认事实后手动发放，将来建了功能再改成自动。
     *
     * 同时按 PRD「4. 徽章展示优先级」校准：至尊皇冠须排在年度VIP之前，
     * 故把 vip_yearly 权重从 90 下调到 85（圈子管理员 90 > 至尊皇冠 88 > 年度VIP 85）。
     *
     * 幂等：INSERT IGNORE + 按 badge_key 判定，重复执行无副作用。
     */
    private static function migrate77(): void
    {
        // ① 审核员角色：users.role 增加 reviewer
        try {
            $col = Database::fetchOne(
                "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role'"
            );
            $type = $col ? (string)$col['COLUMN_TYPE'] : '';
            if ($type !== '' && stripos($type, 'reviewer') === false) {
                Database::query(
                    "ALTER TABLE `users` MODIFY COLUMN `role` enum('user','admin','reviewer')
                     NOT NULL DEFAULT 'user' COMMENT '角色：user普通 admin超管 reviewer审核员'"
                );
                self::log('V85：users.role 新增 reviewer（审核员）');
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V85 role] ' . $e->getMessage());
        }

        // ② PRD 展示优先级校准：年度VIP 让位给至尊皇冠
        try {
            Database::query(
                "UPDATE `badges` SET sort_weight = 85 WHERE badge_key = 'vip_yearly' AND sort_weight = 90"
            );
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V85 weight] ' . $e->getMessage());
        }

        // ③ 新增 10 枚徽章
        try {
            // [badge_key, name, desc, category, icon, color, rule_type, threshold, sort_weight, hideable, display]
            $newBadges = [
                // —— 有数据基础，自动解锁 ——
                ['vip_trial', '体验VIP', '领取并激活平台体验会员，限时享受部分会员权益', 'vip', 'vip', '#b18cff', 'realtime', '{}', 78, 0, 1],
                ['vip_crown', '至尊皇冠会员', '平台最高等级付费会员，拥有全部会员特权', 'vip', 'crown', '#f5c518', 'realtime', '{}', 88, 0, 1],
                ['platform_reviewer', '全站审核员', '平台内容审核人员，负责全站内容审核', 'role', 'eye', '#c0392b', 'realtime', '{}', 105, 0, 1],

                // —— 依赖功能尚未建设，人工授予（后台手动发放） ——
                ['security_guard', '账号安全卫士', '开启登录二次保护，账号安全等级提升', 'auth', 'shield', '#2f7de1', 'manual', '{}', 40, 1, 1],
                ['donor', '平台捐助者', '向平台一次性捐赠，支持社区发展的稀有永久纪念徽章', 'vip', 'heart', '#b87333', 'manual', '{}', 55, 1, 1],
                ['resident', '常驻用户', '累计有效在线时长≥100小时，长期驻留圈子', 'activity', 'clock', '#6b8e23', 'manual', '{}', 42, 1, 0],
                ['quality_commenter', '优质评论官', '多条评论被楼主或圈主标记精选，内容获得认可', 'comment', 'check-circle', '#f0b400', 'manual', '{}', 38, 1, 0],
                ['insight', '真知灼见', '评论内容有价值，累计被其他用户收藏≥20次', 'comment', 'star', '#ffce54', 'manual', '{}', 36, 1, 0],
                ['rational_observer', '理性观察员', '理性参与讨论，长期无争吵举报，文明发言', 'comment', 'search', '#ffe066', 'manual', '{}', 26, 1, 0],
                ['warm_comforter', '暖心安慰者', '评论温暖人心，多次收到其他用户标记感谢', 'comment', 'heart', '#ffd98a', 'manual', '{}', 24, 1, 0],
            ];
            $sql = "INSERT IGNORE INTO `badges`
                    (`badge_key`,`name`,`desc`,`category`,`icon`,`color`,`rule_type`,`threshold`,`sort_weight`,`hideable`,`display`,`status`)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,1)";
            $stmt = Database::getInstance()->prepare($sql);
            foreach ($newBadges as $b) {
                $stmt->execute($b);
            }
            self::log('V85：补齐徽章 ' . count($newBadges) . ' 枚（3 枚自动解锁 / 7 枚人工授予）');
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V85 badge seed] ' . $e->getMessage());
        }
    }


    /**
     * V86：VIP 档位收回为 3 档，vip_trial / vip_crown 改为人工授予。
     *
     * V85 为补齐 PRD 徽章清单加了两枚会员徽章，并假设 vip_level 会扩到 4、5。
     * 但会员等级实际只有 1/2/3 三档（由 /api/pay/vip-buy 购买获得，
     * PaymentController::VIP_DAYS 只认 1/2/3；后台 grant-vip 也硬校验 in_array(1,2,3)），
     * 4/5 没有任何路径可以设置 —— 这两枚徽章的 realtime 判定永远为假，
     * 等于「死徽章」。会员体系维持原样（3 档、购买获得），徽章改 manual：
     * 将来真要发体验/至尊身份时，后台手动授予即可。
     *
     * 幂等：按 badge_key + rule_type 判定，重复执行无副作用。
     */
    private static function migrate78(): void
    {
        try {
            $stmt = Database::getInstance()->prepare(
                "UPDATE `badges` SET `rule_type` = 'manual' WHERE `badge_key` IN (?, ?) AND `rule_type` = 'realtime'"
            );
            $stmt->execute(['vip_trial', 'vip_crown']);

            // 说明同步改准：后台看到「人工授予」才知道该手动发，不会干等自动解锁
            $desc = Database::getInstance()->prepare(
                "UPDATE `badges` SET `desc` = ? WHERE `badge_key` = ?"
            );
            $desc->execute(['平台体验会员身份（体验档未开放购买，由后台人工授予）', 'vip_trial']);
            $desc->execute(['平台最高等级会员身份（至尊档未开放购买，由后台人工授予）', 'vip_crown']);

            self::log('V86：VIP 档位收回三档，vip_trial / vip_crown 改为人工授予');
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V86] ' . $e->getMessage());
        }
    }


    /**
     * V87：放开 users.email 为可空，使「无邮箱」诚实表示为 NULL。
     * 此前手机号静默开号用户被迫写入占位邮箱 phone<number>@invalid.local（NOT NULL + uk_email 唯一约束的妥协），
     * 导致资料页展示假邮箱、且该假邮箱可被当作登录标识，账号/手机号/邮箱三者混淆。
     * 放开后：手机号开号 email=NULL；uk_email 唯一索引允许多个 NULL（InnoDB 标准行为），无需改索引。
     */
    private static function migrate79(): void
    {
        $pdo = Database::getInstance();
        // 1) email 改为可空（先判当前可空性，保持幂等）
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'email'")->fetchAll(\PDO::FETCH_ASSOC);
            $row = $cols[0] ?? [];
            $nullable = strtoupper((string)($row['Null'] ?? 'NO'));
            if ($nullable !== 'YES') {
                $pdo->exec("ALTER TABLE `users` MODIFY COLUMN `email` varchar(100) NULL DEFAULT NULL COMMENT '邮箱（无邮箱则为 NULL，与 username/phone 分离）'");
            }
            self::log('V87：users.email 已放开为可空');
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V87 users.email nullable] ' . $e->getMessage());
        }
        // 2) 清洗历史占位邮箱 phone<number>@invalid.local → NULL
        try {
            $pdo->exec("UPDATE `users` SET `email` = NULL WHERE `email` LIKE 'phone%@invalid.local'");
            self::log('V87：已清洗历史手机号占位邮箱');
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V87 clean phone-email] ' . $e->getMessage());
        }
    }


    /**
     * V88（migrate80）：posts 新增自主声明列 declaration。
     * 发布页把原「原创」工具按钮升级为「自主声明」设置项（枚举下拉）：
     * ai=含AI生成内容 / marketing=含营销内容 / fiction=含虚构内容 /
     * opinion=个人观点，仅供参考 / repost=转载内容，空串=未声明。
     * is_original 字段与「原创作者」徽章统计逻辑保留不动（历史数据不受影响）。
     */
    private static function migrate80(): void
    {
        $pdo = Database::getInstance();
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM `posts` LIKE 'declaration'")->fetchAll(\PDO::FETCH_ASSOC);
            if (empty($cols)) {
                $pdo->exec("ALTER TABLE `posts` ADD COLUMN `declaration` varchar(20) NOT NULL DEFAULT '' COMMENT '自主声明（ai/marketing/fiction/opinion/repost，空=未声明）'");
                self::log('V88：posts 已新增 declaration 自主声明列');
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V88 posts.declaration] ' . $e->getMessage());
        }
    }


    /**
     * V89（migrate81）：修复圈子群聊关联断裂（进群聊 403「无权访问此会话」根因）。
     * 背景：CircleController::ensureCircleGroup() 建群时 INSERT 漏写 circle_id，且
     * joinGroup() 里 ensureCircleGroup 被调了两次（直接一次 + addGroupMember 内部一次），
     * 导致每次进群聊都新建两个 circle_id=NULL 的野群：用户被加进第二个群，
     * 前端拿到的却是第一个群的 id → 聊天页 validateMembership 403 → 「加载失败」。
     * 代码已修（INSERT 补 circle_id、addGroupMember 支持传入 groupId），这里做数据修复：
     *   1) 给每个没有关联群的圈子补建一个带 circle_id 的正规群；
     *   2) 把标题与圈子同名的野群（type='group' AND circle_id IS NULL）的
     *      成员、消息合并进正规群（uk_conv_user 唯一键 + INSERT IGNORE 去重）；
     *   3) 删除被合并的野群，重算所有圈子群的 member_count。
     */
    private static function migrate81(): void
    {
        $pdo = Database::getInstance();

        // 0) 幂等闸：极老库可能没有 circle_id 列，先补（补列本身也幂等）
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM `conversations` LIKE 'circle_id'")->fetchAll(\PDO::FETCH_ASSOC);
            if (empty($cols)) {
                $pdo->exec("ALTER TABLE `conversations` ADD COLUMN `circle_id` int(11) unsigned DEFAULT NULL COMMENT '关联圈子ID（圈子群聊）' AFTER `creator_id`");
                self::log('V89：conversations 已补 circle_id 列');
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V89 add circle_id] ' . $e->getMessage());
        }

        try {
            // 1) 每个没有关联群的圈子补建正规群（带 circle_id）
            $circles = $pdo->query(
                "SELECT c.id, c.name FROM `circles` c
                 WHERE NOT EXISTS (SELECT 1 FROM `conversations` v WHERE v.circle_id = c.id AND v.type = 'group')"
            )->fetchAll(\PDO::FETCH_ASSOC);
            $created = 0;
            $ins = $pdo->prepare(
                "INSERT INTO `conversations` (type, title, avatar, creator_id, member_count, circle_id, created_at, updated_at, last_message_at)
                 VALUES ('group', ?, '', 0, 0, ?, NOW(), NOW(), NOW())"
            );
            foreach ($circles as $c) {
                $ins->execute([$c['name'], (int)$c['id']]);
                $created++;
            }

            // 2) 合并野群：type='group' AND circle_id IS NULL 且标题与某圈子同名
            //    （ensureCircleGroup 建野群时 title 用的就是圈子名，这是唯一可识别的关联信号；
            //      重名圈子取 id 最小者，避免串群）
            $movedMembers = 0;
            $movedMsgs = 0;
            $deletedConvs = 0;
            $orphans = $pdo->query(
                "SELECT v.id, v.title FROM `conversations` v
                 WHERE v.type = 'group' AND v.circle_id IS NULL
                   AND EXISTS (SELECT 1 FROM `circles` c WHERE c.name = v.title)"
            )->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($orphans as $o) {
                $oid = (int)$o['id'];
                $circleStmt = $pdo->prepare("SELECT id FROM `circles` WHERE name = ? ORDER BY id ASC LIMIT 1");
                $circleStmt->execute([$o['title']]);
                $cid = (int)($circleStmt->fetchColumn() ?: 0);
                if ($cid <= 0) {
                    continue;
                }
                $canonStmt = $pdo->prepare("SELECT id FROM `conversations` WHERE circle_id = ? AND type = 'group' ORDER BY id ASC LIMIT 1");
                $canonStmt->execute([$cid]);
                $canonId = (int)($canonStmt->fetchColumn() ?: 0);
                if ($canonId <= 0 || $canonId === $oid) {
                    continue;
                }
                // 2a) 消息并入正规群（防丢历史；本批野群实际无消息，防御性执行）
                $mv = $pdo->prepare("UPDATE `messages` SET conversation_id = ? WHERE conversation_id = ?");
                $mv->execute([$canonId, $oid]);
                $movedMsgs += $mv->rowCount();
                // 2b) 成员并入正规群（uk_conv_user + INSERT IGNORE 去重）
                $mm = $pdo->prepare(
                    "INSERT IGNORE INTO `conversation_members` (conversation_id, user_id, role, joined_at, created_at)
                     SELECT ?, cm.user_id, cm.role, cm.joined_at, cm.created_at
                     FROM `conversation_members` cm WHERE cm.conversation_id = ?"
                );
                $mm->execute([$canonId, $oid]);
                $movedMembers += $mm->rowCount();
                // 2c) 删野群的成员行与会话行
                $pdo->prepare("DELETE FROM `conversation_members` WHERE conversation_id = ?")->execute([$oid]);
                $pdo->prepare("DELETE FROM `conversations` WHERE id = ?")->execute([$oid]);
                $deletedConvs++;
            }

            // 3) 重算所有圈子群的 member_count（与 conversation_members 实际行数对齐）
            $pdo->exec(
                "UPDATE `conversations` v
                 SET v.member_count = (SELECT COUNT(*) FROM `conversation_members` cm WHERE cm.conversation_id = v.id)
                 WHERE v.type = 'group' AND v.circle_id IS NOT NULL"
            );

            self::log(sprintf(
                'V89：圈子群聊关联修复完成：补建正规群 %d 个，合并野群 %d 个（并入成员 %d 行、消息 %d 行）',
                $created, $deletedConvs, $movedMembers, $movedMsgs
            ));
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V89 circle group fix] ' . $e->getMessage());
        }

        // 收尾自检（照 v58 惯例）：逐项复查并 error_log，不达标必须能在日志里一眼看到
        try {
            $noGroupCircles = (int)$pdo->query(
                "SELECT COUNT(*) FROM `circles` c
                 WHERE NOT EXISTS (SELECT 1 FROM `conversations` v WHERE v.circle_id = c.id AND v.type = 'group')"
            )->fetchColumn();
            $orphanLeft = (int)$pdo->query(
                "SELECT COUNT(*) FROM `conversations` v
                 WHERE v.type = 'group' AND v.circle_id IS NULL
                   AND EXISTS (SELECT 1 FROM `circles` c WHERE c.name = v.title)"
            )->fetchColumn();
            $linkedGroups = (int)$pdo->query(
                "SELECT COUNT(*) FROM `conversations` WHERE type = 'group' AND circle_id IS NOT NULL"
            )->fetchColumn();
            $badCount = (int)$pdo->query(
                "SELECT COUNT(*) FROM `conversations` v
                 WHERE v.type = 'group' AND v.circle_id IS NOT NULL
                   AND v.member_count <> (SELECT COUNT(*) FROM `conversation_members` cm WHERE cm.conversation_id = v.id)"
            )->fetchColumn();
            error_log(sprintf(
                '[Hublog Migration V89 selfcheck] circles_without_group=%d orphan_left=%d linked_groups=%d member_count_mismatch=%d',
                $noGroupCircles, $orphanLeft, $linkedGroups, $badCount
            ));
            if ($noGroupCircles === 0 && $orphanLeft === 0 && $badCount === 0) {
                self::log('V89 自检通过：所有圈子均有唯一关联群、无待合并野群、member_count 全部对齐');
            } else {
                error_log('[Hublog Migration V89 selfcheck] 存在未达标项，请检查上方计数');
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V89 selfcheck] ' . $e->getMessage());
        }
    }
}
