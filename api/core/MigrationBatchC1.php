<?php
/**
 * Hublog 增量数据库迁移方法集合 (V66 - V73)
 *
 * 由 api/core/Migration.php 拆分而来，主迁移类 Hublog\Core\Migration
 * 通过 `use MigrationBatchC1` 引入这些方法，旧版本升级时仍按版本号顺序执行；
 * 全新安装走 buildFullSchema() 全量建表后循环补跑增量迁移。
 *
 * 拆分零行为差异：trait 方法在编译期展平进主类，run() 的动态分发
 * self::{"migrate{$v}"}() 与新装补跑循环均正常命中，无需改动调用方。
 */

declare(strict_types=1);

namespace Hublog\Core;

trait MigrationBatchC1
{

    // ==================== V66：推荐算法轻量版 ====================

    /**
     * V66 —— 推荐算法轻量版底座。
     *
     * 目标：不做向量建模，只用「关注关系 + 我的历史互动标签 + 负反馈」三路信号，
     *      在时间线里加一档 sort=recommend。冷启动（无互动）自动退化为时间倒序。
     *
     * 建的东西：
     *   ① feed_feedback     —— 负反馈落库（v1 之前只是前端内存隐藏，刷新又回来）
     *   ② 推荐排序 SQL      —— 见 PostController::timeline() 的 sort=recommend 分支
     */
    private static function migrate66(): void
    {
        $db = Database::getInstance();
        self::log('开始 V66：推荐算法轻量版（负反馈落库 + 兴趣画像）');

        // ---------- ① feed_feedback：负反馈表 ----------
        try {
            $exists = Database::fetchOne(
                "SELECT TABLE_NAME FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'feed_feedback' LIMIT 1"
            );
            if (!$exists) {
                $db->exec("CREATE TABLE IF NOT EXISTS `feed_feedback` (
                    `id` int unsigned NOT NULL AUTO_INCREMENT,
                    `user_id` int unsigned NOT NULL COMMENT '操作人（0=游客，留作埋点但按负反馈逻辑只对登录用户生效）',
                    `action` varchar(16) NOT NULL DEFAULT 'dislike' COMMENT 'dislike=不感兴趣 tag=屏蔽标签 author=屏蔽作者',
                    `target_type` varchar(16) NOT NULL DEFAULT 'post' COMMENT 'post|tag|author',
                    `target_value` varchar(64) NOT NULL DEFAULT '' COMMENT 'post 放动态ID，author 放用户ID，tag 放标签名',
                    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uk_user_action_target` (`user_id`, `action`, `target_type`, `target_value`),
                    KEY `idx_user_created` (`user_id`, `created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='信息流负反馈表'");
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V66 feed_feedback] ' . $e->getMessage());
            if (APP_DEBUG) {
                throw $e;
            }
        }

        // ---------- ② posts 推荐候选池索引 ----------
        // 排序分支要按 created_at 扫近 7 天公开动态，没有这个索引会全表。
        try {
            $exists = Database::fetchOne(
                "SELECT INDEX_NAME FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'posts'
                   AND INDEX_NAME = 'idx_posts_recommend' LIMIT 1"
            );
            if (!$exists) {
                $db->exec("ALTER TABLE `posts` ADD KEY `idx_posts_recommend` (`status`, `visibility`, `created_at`)");
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V66 posts.idx_posts_recommend] ' . $e->getMessage());
            if (APP_DEBUG) {
                throw $e;
            }
        }

        // ---------- ③ 收尾自检 ----------
        $tables = ['feed_feedback'];
        foreach ($tables as $table) {
            try {
                $exists = Database::fetchOne(
                    "SELECT TABLE_NAME FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}' LIMIT 1"
                );
                if (!$exists) {
                    error_log("[Hublog Migration V66 verify] {$table} 未建出，请检查上方 V66 日志");
                }
            } catch (\Throwable $e) {
                error_log("[Hublog Migration V66 verify {$table}] " . $e->getMessage());
            }
        }
        self::log('V66 完成');
    }


    // ==================== V67：会员 / 钱包 / 付费圈子 ====================

    /**
     * V67 —— 会员体系底座。
     *
     * 商业模型（先定死这几条，后面所有校验都照它写）：
     *   等级      0 普通 / 1 月度 / 2 季度 / 3 年度，到期自动降级回 0
     *   钱包      独立流水 wallet_logs，users.balance 是冗余余额（双写，避免每次查聚合）
     *   付费圈子  circles.pay_type：0 免费 / 1 会员等级门槛 / 2 付费订阅
     *   充值      不开放前端自助支付（没有支付网关，开了就是提现漏洞），
     *             统一走后台手动入账；App 只做消费侧（买会员 / 订阅圈子）。
     */
    private static function migrate67(): void
    {
        $db = Database::getInstance();
        self::log('开始 V67：会员 / 钱包 / 付费圈子');

        // ---------- ① users 加会员三列 ----------
        $userCols = [
            'vip_level' => "ALTER TABLE `users` ADD COLUMN `vip_level` tinyint(1) NOT NULL DEFAULT 0 COMMENT '会员等级：0普通 1月度 2季度 3年度' AFTER `role`",
            'vip_expire_at' => "ALTER TABLE `users` ADD COLUMN `vip_expire_at` datetime DEFAULT NULL COMMENT '会员到期时间' AFTER `vip_level`",
            'balance' => "ALTER TABLE `users` ADD COLUMN `balance` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '钱包余额（冗余列，与 wallet_logs 双写）' AFTER `vip_expire_at`",
        ];
        foreach ($userCols as $col => $ddl) {
            try {
                $exists = Database::fetchOne(
                    "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = '{$col}' LIMIT 1"
                );
                if (!$exists) {
                    $db->exec($ddl);
                }
            } catch (\Throwable $e) {
                error_log("[Hublog Migration V67 users.{$col}] " . $e->getMessage());
                if (APP_DEBUG) {
                    throw $e;
                }
            }
        }

        // ---------- ② wallet_logs：钱包流水 ----------
        try {
            $exists = Database::fetchOne(
                "SELECT TABLE_NAME FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wallet_logs' LIMIT 1"
            );
            if (!$exists) {
                $db->exec("CREATE TABLE IF NOT EXISTS `wallet_logs` (
                    `id` int unsigned NOT NULL AUTO_INCREMENT,
                    `user_id` int unsigned NOT NULL COMMENT '所属用户',
                    `amount` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '变动金额，正=入账 负=支出',
                    `type` varchar(16) NOT NULL DEFAULT 'consume' COMMENT 'recharge=充值 consume=消费 refund=退款 adjust=后台调整 income=收入',
                    `biz_type` varchar(16) NOT NULL DEFAULT 'system' COMMENT 'vip|circle|reward|system|admin',
                    `biz_id` int unsigned NOT NULL DEFAULT 0 COMMENT '关联业务ID（会员订单/圈子ID…）',
                    `balance_after` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '变动后的余额',
                    `remark` varchar(255) DEFAULT NULL COMMENT '备注',
                    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_user_created` (`user_id`, `created_at`),
                    KEY `idx_user_type` (`user_id`, `type`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='钱包流水表'");
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V67 wallet_logs] ' . $e->getMessage());
            if (APP_DEBUG) {
                throw $e;
            }
        }

        // ---------- ③ vip_subscriptions：会员订购单 ----------
        try {
            $exists = Database::fetchOne(
                "SELECT TABLE_NAME FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vip_subscriptions' LIMIT 1"
            );
            if (!$exists) {
                $db->exec("CREATE TABLE IF NOT EXISTS `vip_subscriptions` (
                    `id` int unsigned NOT NULL AUTO_INCREMENT,
                    `user_id` int unsigned NOT NULL COMMENT '购买人',
                    `level` tinyint(1) NOT NULL DEFAULT 1 COMMENT '会员等级 1/2/3',
                    `days` int unsigned NOT NULL DEFAULT 30 COMMENT '购买天数',
                    `amount` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '实付金额',
                    `order_no` varchar(40) NOT NULL DEFAULT '' COMMENT '订单号',
                    `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1=已支付 0=待支付',
                    `started_at` datetime DEFAULT NULL COMMENT '生效时间（空=购买时立即生效）',
                    `expire_at` datetime DEFAULT NULL COMMENT '到期时间',
                    `remark` varchar(255) DEFAULT NULL,
                    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uk_order_no` (`order_no`),
                    KEY `idx_user_created` (`user_id`, `created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='会员订购单'");
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V67 vip_subscriptions] ' . $e->getMessage());
            if (APP_DEBUG) {
                throw $e;
            }
        }

        // ---------- ④ circle_subscribes：付费圈子订阅 ----------
        try {
            $exists = Database::fetchOne(
                "SELECT TABLE_NAME FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'circle_subscribes' LIMIT 1"
            );
            if (!$exists) {
                $db->exec("CREATE TABLE IF NOT EXISTS `circle_subscribes` (
                    `id` int unsigned NOT NULL AUTO_INCREMENT,
                    `circle_id` int unsigned NOT NULL COMMENT '圈子ID',
                    `user_id` int unsigned NOT NULL COMMENT '订阅人',
                    `order_no` varchar(40) NOT NULL DEFAULT '',
                    `amount` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '订阅金额',
                    `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1有效 0过期',
                    `expire_at` datetime DEFAULT NULL COMMENT '订阅到期（空=长期有效）',
                    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uk_circle_user` (`circle_id`, `user_id`),
                    KEY `idx_user_id` (`user_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='付费圈子订阅记录'");
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V67 circle_subscribes] ' . $e->getMessage());
            if (APP_DEBUG) {
                throw $e;
            }
        }

        // ---------- ⑤ circles 加付费门槛三列 ----------
        $circleCols = [
            'pay_type' => "ALTER TABLE `circles` ADD COLUMN `pay_type` tinyint(1) NOT NULL DEFAULT 0 COMMENT '付费方式：0免费 1会员等级门槛 2付费订阅' AFTER `status`",
            'pay_vip_level' => "ALTER TABLE `circles` ADD COLUMN `pay_vip_level` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'pay_type=1 时要求的会员等级' AFTER `pay_type`",
            'pay_amount' => "ALTER TABLE `circles` ADD COLUMN `pay_amount` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'pay_type=2 时订阅价格' AFTER `pay_vip_level`",
        ];
        foreach ($circleCols as $col => $ddl) {
            try {
                $exists = Database::fetchOne(
                    "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'circles' AND COLUMN_NAME = '{$col}' LIMIT 1"
                );
                if (!$exists) {
                    $db->exec($ddl);
                }
            } catch (\Throwable $e) {
                error_log("[Hublog Migration V67 circles.{$col}] " . $e->getMessage());
                if (APP_DEBUG) {
                    throw $e;
                }
            }
        }

        // ---------- ⑥ 收尾自检 ----------
        foreach (['wallet_logs', 'vip_subscriptions', 'circle_subscribes'] as $table) {
            try {
                $exists = Database::fetchOne(
                    "SELECT TABLE_NAME FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}' LIMIT 1"
                );
                if (!$exists) {
                    error_log("[Hublog Migration V67 verify] {$table} 未建出，请检查上方 V67 日志");
                }
            } catch (\Throwable $e) {
                error_log("[Hublog Migration V67 verify {$table}] " . $e->getMessage());
            }
        }
        foreach (['vip_level', 'balance'] as $col) {
            try {
                $exists = Database::fetchOne(
                    "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = '{$col}' LIMIT 1"
                );
                if (!$exists) {
                    error_log("[Hublog Migration V67 verify] users.{$col} 未建出，请检查上方 V67 日志");
                }
            } catch (\Throwable $e) {
                error_log("[Hublog Migration V67 verify users.{$col}] " . $e->getMessage());
            }
        }

        self::log('V67 完成');
    }


    /**
     * V68：积分中心
     *
     *   积分       users.points 冗余余额（与 points_logs 双写，避免每次聚合）
     *   签到       user_signins：每月第 n 天奖励 2n 分（2/4/6…62），当天重复签忽略，
     *              漏签当天奖励作废 —— 连击（streak）从 1 重新开始，不再累计加成。
     *   任务       point_tasks + user_task_logs：后台可配（标题/积分/周期/次数上限），
     *              达标后在 App 手动点「领取」，避免埋点漏发。
     *   消费       buyVip / circleSubscribe 支持 use_points 抵扣，
     *              汇率走 settings.point_rate（默认 10 积分 = ¥1，后台可改）。
     */
    private static function migrate68(): void
    {
        $db = Database::getInstance();
        self::log('开始 V68：积分中心');

        // ---------- ① users 加积分余额列 ----------
        $userCols = [
            'points' => "ALTER TABLE `users` ADD COLUMN `points` int unsigned NOT NULL DEFAULT 0 COMMENT '积分余额（冗余列，与 points_logs 双写）' AFTER `balance`",
        ];
        foreach ($userCols as $col => $ddl) {
            try {
                $exists = Database::fetchOne(
                    "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = '{$col}' LIMIT 1"
                );
                if (!$exists) {
                    $db->exec($ddl);
                }
            } catch (\Throwable $e) {
                error_log("[Hublog Migration V68 users.{$col}] " . $e->getMessage());
                if (APP_DEBUG) {
                    throw $e;
                }
            }
        }

        // ---------- ② points_logs：积分流水 ----------
        try {
            $exists = Database::fetchOne(
                "SELECT TABLE_NAME FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'points_logs' LIMIT 1"
            );
            if (!$exists) {
                $db->exec("CREATE TABLE IF NOT EXISTS `points_logs` (
                    `id` int unsigned NOT NULL AUTO_INCREMENT,
                    `user_id` int unsigned NOT NULL COMMENT '所属用户',
                    `points` int NOT NULL DEFAULT 0 COMMENT '积分变动，正=获得 负=消耗',
                    `type` varchar(16) NOT NULL DEFAULT 'earn' COMMENT 'earn=获得 spend=消耗 signin=签到 task=任务 consume=兑换 adjust=后台调整',
                    `biz_type` varchar(16) NOT NULL DEFAULT 'system' COMMENT 'signin|task|vip|circle|system|admin',
                    `biz_id` int unsigned NOT NULL DEFAULT 0,
                    `balance_after` int unsigned NOT NULL DEFAULT 0 COMMENT '变动后的积分余额',
                    `remark` varchar(255) DEFAULT NULL,
                    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_user_created` (`user_id`, `created_at`),
                    KEY `idx_user_type` (`user_id`, `type`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='积分流水表'");
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V68 points_logs] ' . $e->getMessage());
            if (APP_DEBUG) {
                throw $e;
            }
        }

        // ---------- ③ user_signins：每日签到 ----------
        try {
            $exists = Database::fetchOne(
                "SELECT TABLE_NAME FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_signins' LIMIT 1"
            );
            if (!$exists) {
                $db->exec("CREATE TABLE IF NOT EXISTS `user_signins` (
                    `id` int unsigned NOT NULL AUTO_INCREMENT,
                    `user_id` int unsigned NOT NULL COMMENT '签到用户',
                    `sign_month` char(7) NOT NULL DEFAULT '' COMMENT '所属月份 YYYY-MM',
                    `sign_date` date NOT NULL COMMENT '签到日期',
                    `points` int unsigned NOT NULL DEFAULT 0 COMMENT '当天获得积分（= 日号 × 2）',
                    `streak` int unsigned NOT NULL DEFAULT 1 COMMENT '签到当天是多少连击（漏签会被重置）',
                    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uk_user_date` (`user_id`, `sign_date`),
                    KEY `idx_user_month` (`user_id`, `sign_month`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户签到记录'");
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V68 user_signins] ' . $e->getMessage());
            if (APP_DEBUG) {
                throw $e;
            }
        }

        // ---------- ④ point_tasks：积分任务配置（后台可增删改） ----------
        try {
            $exists = Database::fetchOne(
                "SELECT TABLE_NAME FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'point_tasks' LIMIT 1"
            );
            if (!$exists) {
                $db->exec("CREATE TABLE IF NOT EXISTS `point_tasks` (
                    `id` int unsigned NOT NULL AUTO_INCREMENT,
                    `title` varchar(64) NOT NULL DEFAULT '' COMMENT '任务标题',
                    `desc` varchar(255) DEFAULT NULL COMMENT '任务说明',
                    `points` int unsigned NOT NULL DEFAULT 0 COMMENT '完成奖励积分',
                    `task_type` varchar(24) NOT NULL DEFAULT 'post' COMMENT 'profile|post|comment|like|circle_join|follow|signin_days',
                    `target` int unsigned NOT NULL DEFAULT 1 COMMENT '完成条件：达标次数（如 3 条动态）',
                    `period` varchar(8) NOT NULL DEFAULT 'daily' COMMENT 'daily=每日重置 once=仅一次',
                    `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1开启 0关闭',
                    `sort` int unsigned NOT NULL DEFAULT 0,
                    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_status_sort` (`status`, `sort`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='积分任务配置'");

                // 内置任务：覆盖新用户冷启动能拿到的全部路径
                $db->exec(
                    "INSERT INTO `point_tasks` (`title`,`desc`,`points`,`task_type`,`target`,`period`,`status`,`sort`) VALUES
                     ('完善个人资料','填写昵称与头像，让别人记住你',200,'profile',1,'once',1,10),
                     ('发布第一条动态','把今天的心情发出来',150,'post',1,'once',1,20),
                     ('发布 3 条动态','每天发满 3 条动态，攒够下周零花钱',200,'post',3,'daily',1,30),
                     ('发表 2 条评论','参与讨论也能赚积分',160,'comment',2,'daily',1,40),
                     ('点赞 5 条动态','给看得下去的内容点个赞',100,'like',5,'daily',1,50),
                     ('加入 1 个圈子','圈子是这里的主要内容来源',180,'circle_join',1,'once',1,60),
                     ('认识 1 个新朋友','关注一个有兴趣的人',120,'follow',1,'daily',1,70),
                     ('本月签到 7 天','连续签到满 7 天的额外奖励',500,'signin_days',7,'once',1,80)"
                );
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V68 point_tasks] ' . $e->getMessage());
            if (APP_DEBUG) {
                throw $e;
            }
        }

        // ---------- ⑤ user_task_logs：任务完成进度 ----------
        try {
            $exists = Database::fetchOne(
                "SELECT TABLE_NAME FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_task_logs' LIMIT 1"
            );
            if (!$exists) {
                $db->exec("CREATE TABLE IF NOT EXISTS `user_task_logs` (
                    `id` int unsigned NOT NULL AUTO_INCREMENT,
                    `user_id` int unsigned NOT NULL,
                    `task_id` int unsigned NOT NULL,
                    `times` int unsigned NOT NULL DEFAULT 0 COMMENT '周期内已完成次数',
                    `claimed` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1=已领取奖励',
                    `last_date` date DEFAULT NULL COMMENT '最后完成日期（daily 用它算重置）',
                    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uk_user_task` (`user_id`, `task_id`),
                    KEY `idx_user_id` (`user_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户积分任务进度'");
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V68 user_task_logs] ' . $e->getMessage());
            if (APP_DEBUG) {
                throw $e;
            }
        }

        // ---------- ⑥ 收尾自检 ----------
        foreach (['points_logs', 'user_signins', 'point_tasks', 'user_task_logs'] as $table) {
            try {
                $exists = Database::fetchOne(
                    "SELECT TABLE_NAME FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}' LIMIT 1"
                );
                if (!$exists) {
                    error_log("[Hublog Migration V68 verify] {$table} 未建出，请检查上方 V68 日志");
                }
            } catch (\Throwable $e) {
                error_log("[Hublog Migration V68 verify {$table}] " . $e->getMessage());
            }
        }
        try {
            $exists = Database::fetchOne(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'points' LIMIT 1"
            );
            if (!$exists) {
                error_log('[Hublog Migration V68 verify] users.points 未建出，请检查上方 V68 日志');
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V68 verify users.points] ' . $e->getMessage());
        }

        self::log('V68 完成');
    }


    /**
     * V69：在线充值（微信 / 支付宝官方支付）
     *
     * PaymentController 文件头原本写着「充值不开放前端自助支付 —— 没有支付网关，
     * 开了就是提现漏洞」。V69 就是把这个网关补上：充值单落库 → 官方渠道下单 →
     * 异步回调（微信走主动查单、支付宝走验签）确认后入账。
     * 本站钱包只进不出（没有任何提现出口），所以不存在「提现漏洞」那一说。
     *
     * 支付参数不落迁移，走 settings 表（后台可改），见 PaymentController::paySettings()。
     */
    private static function migrate69(): void
    {
        $db = Database::getInstance();
        self::log('开始 V69：在线充值');

        try {
            $exists = Database::fetchOne(
                "SELECT TABLE_NAME FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'recharge_orders' LIMIT 1"
            );
            if (!$exists) {
                $db->exec("CREATE TABLE `recharge_orders` (
                    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                    `order_no` varchar(32) NOT NULL COMMENT '本站充值单号（RC 前缀）',
                    `user_id` int unsigned NOT NULL DEFAULT 0,
                    `amount` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT '充值金额（元）',
                    `method` varchar(16) NOT NULL DEFAULT '' COMMENT 'wxpay / alipay',
                    `status` varchar(16) NOT NULL DEFAULT 'pending' COMMENT 'pending 待支付 / paid 已到账 / closed 已关闭',
                    `trade_no` varchar(64) NOT NULL DEFAULT '' COMMENT '渠道交易号',
                    `paid_at` datetime DEFAULT NULL COMMENT '到账时间',
                    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uk_order_no` (`order_no`),
                    KEY `idx_user_id` (`user_id`),
                    KEY `idx_status` (`status`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='在线充值订单'");
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V69 recharge_orders] ' . $e->getMessage());
            if (APP_DEBUG) {
                throw $e;
            }
        }

        // ---------- 收尾自检 ----------
        foreach (['recharge_orders'] as $table) {
            try {
                $exists = Database::fetchOne(
                    "SELECT TABLE_NAME FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}' LIMIT 1"
                );
                if (!$exists) {
                    error_log("[Hublog Migration V69 verify] {$table} 未建出，请检查上方 V69 日志");
                }
            } catch (\Throwable $e) {
                error_log("[Hublog Migration V69 verify {$table}] " . $e->getMessage());
            }
        }

        self::log('V69 完成');
    }


    /**
     * V70：头像框（avatar_frame）
     * 会员可在会员中心挑选系统预设的头像框，存入 users.avatar_frame（默认 none）。
     * 前端在头像处按值渲染对应边框；后端只负责存值与白名单校验。
     */
    private static function migrate70(): void
    {
        self::log('开始 V70：头像框字段');

        try {
            $has = (int)Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'avatar_frame'"
            );
            if (!$has) {
                Database::getInstance()->exec(
                    "ALTER TABLE `users` ADD COLUMN `avatar_frame` varchar(32) NOT NULL DEFAULT 'none'
                     COMMENT '头像框：none / gold / diamond / crown / rainbow' AFTER `avatar`"
                );
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V70 avatar_frame] ' . $e->getMessage());
            if (APP_DEBUG) {
                throw $e;
            }
        }

        // ---------- 收尾自检 ----------
        try {
            $has = (int)Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'avatar_frame'"
            );
            if (!$has) {
                error_log('[Hublog Migration V70 verify] users.avatar_frame 未建出，请检查上方 V70 日志');
            } else {
                self::log('V70 完成：users.avatar_frame 已就绪');
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V70 verify] ' . $e->getMessage());
        }
    }


    /**
     * V71：内容治理（P0）——全局敏感词过滤 + 违规处理链路
     *
     * 一次迁移建齐 4 件事，避免反复上传 Migration.php：
     *  1) sensitive_words    ：敏感词库（分级：replace 替换 / block 拦截 / review 转审核）
     *  2) content_filter_logs：命中记录（后台可查谁在哪个场景命中了什么）
     *  3) users.muted_until  ：全站禁言到期时间（NULL=未禁言；>now=禁言中）
     *  4) circles.limited / circles.removed_at：圈子限流 / 下架
     *
     * 注意：词库与命中日志两张新表必须登记进 criticalTablesExist() 的 $requiredTables，
     * 否则新装站点永远缺表（历史教训）。
     */
    private static function migrate71(): void
    {
        $db = Database::getInstance();
        self::log('开始 V71：内容治理（敏感词 + 违规处理）');

        // ---------- 1) 敏感词库 ----------
        try {
            $exists = Database::fetchOne(
                "SELECT TABLE_NAME FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sensitive_words' LIMIT 1"
            );
            if (!$exists) {
                $db->exec("CREATE TABLE `sensitive_words` (
                    `id` int unsigned NOT NULL AUTO_INCREMENT,
                    `word` varchar(100) NOT NULL COMMENT '敏感词',
                    `level` enum('replace','block','review') NOT NULL DEFAULT 'replace'
                        COMMENT '处理级别：replace=替换为* / block=直接拦截 / review=放行但转人工审核',
                    `replacement` varchar(50) NOT NULL DEFAULT '***' COMMENT 'replace 级别时的替换文本',
                    `scene` varchar(100) NOT NULL DEFAULT ''
                        COMMENT '生效场景，逗号分隔：post/comment/chat/profile/circle；空=全场景',
                    `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1启用 0停用',
                    `hit_count` int unsigned NOT NULL DEFAULT 0 COMMENT '累计命中次数',
                    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uk_word` (`word`),
                    KEY `idx_status` (`status`),
                    KEY `idx_level` (`level`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='敏感词库'");
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V71 sensitive_words] ' . $e->getMessage());
            if (APP_DEBUG) {
                throw $e;
            }
        }

        // ---------- 2) 命中记录 ----------
        try {
            $exists = Database::fetchOne(
                "SELECT TABLE_NAME FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'content_filter_logs' LIMIT 1"
            );
            if (!$exists) {
                $db->exec("CREATE TABLE `content_filter_logs` (
                    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
                    `user_id` int unsigned NOT NULL DEFAULT 0 COMMENT '命中用户ID',
                    `scene` varchar(20) NOT NULL DEFAULT '' COMMENT '场景：post/comment/chat/profile/circle',
                    `target_id` int unsigned NOT NULL DEFAULT 0 COMMENT '关联对象ID（动态/评论/圈子等）',
                    `action` varchar(16) NOT NULL DEFAULT '' COMMENT '实际处理：replace/block/review',
                    `words` varchar(500) NOT NULL DEFAULT '' COMMENT '命中的词，逗号分隔',
                    `excerpt` varchar(500) NOT NULL DEFAULT '' COMMENT '原文片段（截断，便于人工复核）',
                    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_user_id` (`user_id`),
                    KEY `idx_scene` (`scene`),
                    KEY `idx_created_at` (`created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='敏感词命中记录'");
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V71 content_filter_logs] ' . $e->getMessage());
            if (APP_DEBUG) {
                throw $e;
            }
        }

        // ---------- 3) users.muted_until（全站禁言） ----------
        try {
            $has = (int)Database::fetchColumn(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'muted_until'"
            );
            if (!$has) {
                $db->exec(
                    "ALTER TABLE `users` ADD COLUMN `muted_until` datetime DEFAULT NULL
                     COMMENT '全站禁言到期时间，NULL=未禁言' AFTER `status`"
                );
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V71 users.muted_until] ' . $e->getMessage());
            if (APP_DEBUG) {
                throw $e;
            }
        }

        // ---------- 4) circles 限流 / 下架 ----------
        $circleColumns = [
            'limited'    => "ADD COLUMN `limited` tinyint(1) NOT NULL DEFAULT 0
                             COMMENT '是否限流：1=限制发帖/评论，仅浏览' AFTER `status`",
            'removed_at' => "ADD COLUMN `removed_at` datetime DEFAULT NULL
                             COMMENT '下架时间，NULL=正常' AFTER `limited`",
        ];
        foreach ($circleColumns as $col => $alterSql) {
            try {
                $has = (int)Database::fetchColumn(
                    "SELECT COUNT(*) FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'circles' AND COLUMN_NAME = ?",
                    [$col]
                );
                if (!$has) {
                    $db->exec("ALTER TABLE `circles` {$alterSql}");
                }
            } catch (\Throwable $e) {
                error_log("[Hublog Migration V71 circles.{$col}] " . $e->getMessage());
                if (APP_DEBUG) {
                    throw $e;
                }
            }
        }

        // ---------- 收尾自检（缺一样就 error_log，别让站点带残库静默运行）----------
        foreach (['sensitive_words', 'content_filter_logs'] as $table) {
            try {
                $exists = Database::fetchOne(
                    "SELECT TABLE_NAME FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}' LIMIT 1"
                );
                if (!$exists) {
                    error_log("[Hublog Migration V71 verify] 表 {$table} 未建出，请检查上方 V71 日志");
                }
            } catch (\Throwable $e) {
                error_log("[Hublog Migration V71 verify {$table}] " . $e->getMessage());
            }
        }
        foreach ([['users', 'muted_until'], ['circles', 'limited'], ['circles', 'removed_at']] as [$t, $c]) {
            try {
                $has = (int)Database::fetchColumn(
                    "SELECT COUNT(*) FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
                    [$t, $c]
                );
                if (!$has) {
                    error_log("[Hublog Migration V71 verify] {$t}.{$c} 未建出，请检查上方 V71 日志");
                }
            } catch (\Throwable $e) {
                error_log("[Hublog Migration V71 verify {$t}.{$c}] " . $e->getMessage());
            }
        }

        self::log('V71 完成');
    }


    /**
     * V80：活动打卡（checkins）功能整体下线。
     * 活动仅保留「报名（signup）」一种类型；打卡由独立的 checkin_logs / checkin_posts（打卡帖）体系承载。
     * 这里删除残留的 checkins 表与打卡类活动，并同步移除 criticalTablesExist() 与 migrate59 建表清单中的 checkins。
     */
    private static function migrate72(): void
    {
        $db = Database::getInstance();
        self::log('开始 V80：删除活动打卡表 checkins');

        // 先清掉「打卡活动」类活动（报名类活动不受影响；报名记录靠外键 CASCADE 一并清理）
        try {
            $db->exec("DELETE FROM activities WHERE type = 'checkin'");
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V80 delete checkin activities] ' . $e->getMessage());
            if (APP_DEBUG) {
                throw $e;
            }
        }

        // 删除 checkins 表
        try {
            $db->exec("DROP TABLE IF EXISTS `checkins`");
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V80 drop checkins] ' . $e->getMessage());
            if (APP_DEBUG) {
                throw $e;
            }
        }

        // 收尾自检：checkins 必须已不存在
        try {
            $exists = Database::fetchOne(
                "SELECT TABLE_NAME FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'checkins' LIMIT 1"
            );
            if ($exists) {
                error_log('[Hublog Migration V80 verify] checkins 表仍然存在，请检查上方 V80 日志');
            } else {
                self::log('V80 校验通过：checkins 表已删除');
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V80 verify] ' . $e->getMessage());
        }
    }


    /**
     * V81：校准 posts.comment_count，把「漏计」的评论数补回来。
     *
     * 背景：AI 自动评论（plugins/AiComment）是直接 insert comments 的，不走
     * PostController::comment()，因此从来没有 +1 posts.comment_count；
     * 而列表页/详情页的评论数都读这个冗余列（PostFormatter::formatPostBatch 优先读冗余列），
     * 于是出现「详情里明明有 3 条评论（含 AI 评论），评论数却显示 2」。
     *
     * 插件侧已改为插入后 +1（新数据不再漏计）；本迁移只负责把存量数据校准一次。
     * 幂等：按 comments 实际条数重算，只更新不一致的行，重复执行无副作用。
     */
    private static function migrate73(): void
    {
        self::log('开始 V81：校准 posts.comment_count（补回 AI 评论等漏计的条数）');

        $affected = 0;
        try {
            $stmt = Database::query(
                "UPDATE posts p
                 LEFT JOIN (SELECT post_id, COUNT(*) AS cnt FROM comments GROUP BY post_id) c
                        ON c.post_id = p.id
                 SET p.comment_count = COALESCE(c.cnt, 0)
                 WHERE p.comment_count <> COALESCE(c.cnt, 0)"
            );
            $affected = $stmt->rowCount();
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V81 fix comment_count] ' . $e->getMessage());
            if (APP_DEBUG) {
                throw $e;
            }
        }

        // 收尾自检：复查是否还有不一致的行
        try {
            $left = (int)Database::fetchColumn(
                "SELECT COUNT(*) FROM posts p
                 WHERE p.comment_count <> (SELECT COUNT(*) FROM comments c WHERE c.post_id = p.id)"
            );
            if ($left > 0) {
                error_log("[Hublog Migration V81 verify] 仍有 {$left} 条动态 comment_count 与实际评论数不一致");
            } else {
                self::log("V81 校验通过：comment_count 已全部与实际评论数对齐（本次修正 {$affected} 条）");
            }
        } catch (\Throwable $e) {
            error_log('[Hublog Migration V81 verify] ' . $e->getMessage());
        }
    }
}
